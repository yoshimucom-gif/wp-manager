<?php
/**
 * オート挿入コアロジック
 *
 * process($post_id) が全部の流れを回す:
 *   1. ランキング判定 → 該当なら skip
 *   2. 既存カード削除 (更新挿入用)
 *   3. Claude Haiku で本文からキーワード抽出 (キャッシュあり)
 *   4. Amazon + 楽天から3件検索
 *   5. 「最初のH2直前」「まとめ直後」に比較カード挿入
 *   6. post_content 更新、post_meta に商品データ保存
 *
 * 冪等性:
 *   - 既存の <!-- affiros-ai-card-start --> ... <!-- affiros-ai-card-end -->
 *     ブロックを検出して先に削除してから再挿入する
 *   - 同じ記事を何度呼んでも重複しない
 */

if (!defined('ABSPATH')) exit;

if (!class_exists('Affiros_AI_Inserter')) :

class Affiros_AI_Inserter {

    /**
     * 記事1本を処理
     * @param int $post_id
     * @param array $opts {
     *   force_refresh_keyword: bool - キャッシュ済みKWを無視して再抽出
     *   force_refresh_products: bool - キャッシュ済み商品を無視して再取得
     * }
     * @return array {success, changed, keyword, products_count, reason, message}
     */
    public static function process($post_id, $opts = []) {
        $post = get_post($post_id);
        if (!$post) return self::result(false, 'post not found');

        // ランキング判定
        if (Affiros_AI_Ranking_Detector::is_ranking($post)) {
            self::clear_last_error($post_id);
            return self::result(true, 'ランキング記事のためスキップ', ['skipped' => true, 'reason' => 'ranking']);
        }

        $settings = affiros_ai_get_settings();

        // 除外カテゴリー/タグ判定
        if (affiros_ai_taxonomy_excluded($post_id, $settings)) {
            self::clear_last_error($post_id);
            return self::result(true, '除外カテゴリー/タグのためスキップ', ['skipped' => true, 'reason' => 'taxonomy']);
        }

        // ステータスチェック
        $allowed_statuses = array_filter(array_map('trim', explode(',', $settings['target_statuses'] ?? 'publish,future,draft')));
        if (!in_array($post->post_status, $allowed_statuses, true)) {
            return self::result(true, 'ステータス対象外', ['skipped' => true, 'reason' => 'status:' . $post->post_status]);
        }

        // キーワード取得 (キャッシュ or 新規抽出)
        $keyword = get_post_meta($post_id, AFFIROS_AI_META_KEYWORD, true);
        if (empty($keyword) || !empty($opts['force_refresh_keyword'])) {
            $extractor = new Affiros_AI_Keyword_Extractor($settings);
            if (!$extractor->is_configured()) {
                return self::fail($post_id, 'Claude API キーが未設定');
            }
            $keyword = $extractor->extract($post->post_title, $post->post_content);
            if (is_wp_error($keyword)) {
                return self::fail($post_id, 'キーワード抽出失敗: ' . $keyword->get_error_message());
            }
            update_post_meta($post_id, AFFIROS_AI_META_KEYWORD, $keyword);
        }

        // 商品取得 (キャッシュ or 新規)
        $cached_products = get_post_meta($post_id, AFFIROS_AI_META_PRODUCTS, true);
        $products_data = [];
        $partial_error = '';
        if (!empty($cached_products) && empty($opts['force_refresh_products'])) {
            $products_data = is_array($cached_products) ? $cached_products : json_decode($cached_products, true);
        }
        $count = max(1, min(5, intval($settings['products_count'] ?? 3)));
        $keyword_note = '';
        $source_note = '';
        if (empty($products_data) || empty($products_data['amazon']) && empty($products_data['rakuten'])) {
            $extractor = new Affiros_AI_Keyword_Extractor($settings);

            list($amazon_products, $rakuten_products, $errors, $all_rejected, $source_note) =
                self::fetch_products($settings, $extractor, $keyword, $post->post_title, $count);

            // 検品全滅 (検索結果はあるが全て別カテゴリ商品) なら、
            // キーワードを出し直して1回だけ再挑戦する。v0.10.1
            // 例: 「スーツ用コート」→防虫カバーだらけ→「チェスターコート メンズ」で再検索
            if (empty($amazon_products) && empty($rakuten_products) && $all_rejected) {
                $kw2 = $extractor->extract_alternative($post->post_title, $post->post_content, $keyword);
                if (!is_wp_error($kw2) && $kw2 !== '' && $kw2 !== $keyword) {
                    list($a2, $r2, $errors2, , $note2) =
                        self::fetch_products($settings, $extractor, $kw2, $post->post_title, $count);
                    if (!empty($a2) || !empty($r2)) {
                        $amazon_products = $a2;
                        $rakuten_products = $r2;
                        $errors = $errors2;
                        $source_note = $note2;
                        $keyword_note = "キーワード再抽出: {$keyword} → {$kw2}";
                        $keyword = $kw2;
                        update_post_meta($post_id, AFFIROS_AI_META_KEYWORD, $keyword);
                    } else {
                        $errors = array_merge($errors, array_map(function ($e) {
                            return '再挑戦でも ' . $e;
                        }, $errors2));
                    }
                }
            }

            if (empty($amazon_products) && empty($rakuten_products)) {
                return self::fail($post_id, '商品取得失敗: ' . implode(' / ', $errors ?: ['両APIとも0件']));
            }

            // 片側だけ失敗した場合も理由を握りつぶさず結果メッセージに載せる
            // (Amazonが弾かれて楽天主軸になった原因をユーザーが特定できるように)
            if (!empty($errors)) {
                $partial_error = implode(' / ', $errors);
            } elseif (empty($amazon_products) && (new Affiros_AI_Amazon_API($settings))->is_configured()) {
                $partial_error = 'Amazon: エラーなしで0件 (キーワードにヒットなし)';
            }

            $products_data = [
                'amazon'   => $amazon_products,
                'rakuten'  => $rakuten_products,
                'keyword'  => $keyword,
                'fetched_at' => current_time('mysql'),
            ];
            update_post_meta($post_id, AFFIROS_AI_META_PRODUCTS, $products_data);
        }

        // カードHTML生成
        $card_html = Affiros_AI_Card_Renderer::render(
            $products_data['amazon'] ?? [],
            $products_data['rakuten'] ?? [],
            [
                'keyword'    => $keyword,
                'updated_at' => $products_data['fetched_at'] ?? current_time('mysql'),
                'count'      => $count,
                // 対応商品がない側のボタンを検索一覧に飛ばすためのアフィリエイト情報
                'amazon_partner_tag'   => $settings['amazon_partner_tag']   ?? '',
                'rakuten_affiliate_id' => $settings['rakuten_affiliate_id'] ?? '',
                'card_heading_suffix'  => $settings['card_heading_suffix']  ?? '',
            ]
        );

        if (empty($card_html)) {
            return self::fail($post_id, 'カードHTML生成失敗');
        }

        // 既存カードを削除してから再挿入
        $content = self::strip_existing_cards($post->post_content);

        // 挿入位置を決定 & 挿入
        $before_first_h2 = ($settings['insert_before_first_h2'] ?? 'yes') === 'yes';
        $after_matome    = ($settings['insert_after_matome']    ?? 'yes') === 'yes';

        $new_content = $content;
        $insertions = 0;

        if ($before_first_h2) {
            $result = self::insert_before_first_h2($new_content, $card_html);
            if ($result !== null) {
                $new_content = $result;
                $insertions++;
            }
        }
        if ($after_matome) {
            $result = self::insert_after_matome($new_content, $card_html);
            if ($result !== null) {
                $new_content = $result;
                $insertions++;
            }
        }

        if ($insertions === 0) {
            return self::fail($post_id, '挿入位置が見つかりませんでした (H2 / まとめ が本文にない)');
        }

        // 更新
        $upd = wp_update_post([
            'ID'           => $post_id,
            'post_content' => $new_content,
        ], true);
        if (is_wp_error($upd)) {
            return self::fail($post_id, 'wp_update_post 失敗: ' . $upd->get_error_message());
        }

        update_post_meta($post_id, AFFIROS_AI_META_LAST_INSERT_AT, current_time('mysql'));
        self::clear_last_error($post_id);

        $msg = "挿入完了 ({$insertions}箇所)";
        if ($partial_error !== '') {
            $primary_src = !empty($products_data['amazon']) ? 'Amazon' : '楽天';
            $msg .= " ⚠️ {$partial_error} → {$primary_src}主軸で挿入";
        }
        if ($keyword_note !== '') {
            $msg .= " 🔁 {$keyword_note}";
        }
        if (!empty($source_note)) {
            $msg .= " 📊 {$source_note}";
        }

        return self::result(true, $msg, [
            'changed' => true,
            'keyword' => $keyword,
            'insertions' => $insertions,
            'amazon_count' => count($products_data['amazon'] ?? []),
            'rakuten_count' => count($products_data['rakuten'] ?? []),
        ]);
    }

    /**
     * キーワードで Amazon/楽天 を検索し、AI検品 → 多様性フィルタまで通す。
     * 検品の必要性: Amazon検索は「スーツ用コート」で防虫カバーを返す
     * (商品名にスーツ/コート/用が全部含まれる字面一致のため)。v0.10.0
     *
     * ソース選択 (v0.11.0): Amazonの合格商品が表示件数に満たない場合、
     * 楽天から「レビューが付いている商品だけ」を取って検品にかけ、
     * 良品が多い方を主軸にする。Amazonはレビューデータを返さないが
     * 楽天は返すため、ニッチ玩具系などAmazonが無名出品だらけのキーワードで
     * 「レビュー実績のある楽天商品」に切り替えられる。
     *
     * @return array [amazon_products, rakuten_products, errors(配列), all_rejected(検品全滅か), source_note]
     */
    private static function fetch_products($settings, $extractor, $keyword, $post_title, $count) {
        $amazon_api  = new Affiros_AI_Amazon_API($settings);
        $rakuten_api = new Affiros_AI_Rakuten_API($settings);
        $amazon_products = [];
        $rakuten_products = [];
        $errors = [];
        $all_rejected = false;
        $source_note = '';

        if ($amazon_api->is_configured()) {
            $res = $amazon_api->search($keyword, 10);
            if (is_wp_error($res)) $errors[] = 'Amazon: ' . $res->get_error_message();
            else {
                $before = count($res);
                $res = $extractor->filter_relevant($keyword, $post_title, $res);
                if (empty($res) && $before > 0) {
                    $errors[] = "Amazon: 検索結果{$before}件は全て不合格 (別カテゴリ商品 or 低品質出品) と判定";
                    $all_rejected = true;
                }
                $amazon_products = self::diversify($res, $count);
            }
        }

        // Amazonが表示件数を満たせない場合は楽天も検討する
        // (楽天はレビュー0件の商品を除外できるので、良品が多ければ主軸を切り替える)
        if (count($amazon_products) < $count && $rakuten_api->is_configured()) {
            $res = $rakuten_api->search($keyword, 10);
            if (is_wp_error($res)) $errors[] = '楽天: ' . $res->get_error_message();
            else {
                $before = count($res);
                // レビュー実績のある商品だけに絞る (楽天だけができる品質フィルタ)
                $res = array_values(array_filter($res, function ($p) {
                    return intval($p['review_count'] ?? 0) > 0;
                }));
                $res = $extractor->filter_relevant($keyword, $post_title, $res);
                if (empty($res) && $before > 0) {
                    $errors[] = "楽天: 検索結果{$before}件は全て不合格 (レビューなし・別カテゴリ・低品質) と判定";
                    if (empty($amazon_products)) $all_rejected = true;
                }
                $rakuten_candidates = self::diversify($res, $count);

                if (count($rakuten_candidates) > count($amazon_products)) {
                    // 楽天主軸に切り替え (renderer は amazon が空のとき楽天主軸で組む)
                    if (!empty($amazon_products)) {
                        $source_note = '楽天主軸を採用 (Amazon良品' . count($amazon_products) . '件 < 楽天レビューあり良品' . count($rakuten_candidates) . '件)';
                    }
                    $amazon_products = [];
                    $rakuten_products = $rakuten_candidates;
                } elseif (empty($amazon_products)) {
                    $rakuten_products = $rakuten_candidates;
                }
            }
        }
        return [$amazon_products, $rakuten_products, $errors, $all_rejected, $source_note];
    }

    /**
     * 検索結果から「ブランドが被らず・タイトルが似すぎない」商品を上から選ぶ。
     * 同じ店の色違い3枚が並ぶのを防ぐ。厳選で $n 件に満たなければ残りから順に補充。
     */
    public static function diversify($products, $n) {
        $picked = [];
        foreach ($products as $p) {
            if (count($picked) >= $n) break;
            $brand  = mb_strtolower(trim($p['brand'] ?? ''));
            $tokens = Affiros_AI_Card_Renderer::tokenize($p['title'] ?? '');
            $dup = false;
            foreach ($picked as $q) {
                if ($brand !== '' && $brand === mb_strtolower(trim($q['brand'] ?? ''))) { $dup = true; break; }
                $overlap = count(array_intersect($tokens, Affiros_AI_Card_Renderer::tokenize($q['title'] ?? '')));
                $min_len = max(1, min(count($tokens), count(Affiros_AI_Card_Renderer::tokenize($q['title'] ?? ''))));
                if ($overlap >= 4 || $overlap / $min_len >= 0.6) { $dup = true; break; }
            }
            if (!$dup) $picked[] = $p;
        }
        // 厳選で足りなければ未選択の商品を上から補充
        if (count($picked) < $n) {
            foreach ($products as $p) {
                if (count($picked) >= $n) break;
                if (!in_array($p, $picked, true)) $picked[] = $p;
            }
        }
        return $picked;
    }

    /**
     * 既存カードだけ削除 (post_content を返す)
     */
    public static function strip_existing_cards($content) {
        $pattern = '/<!--\s*affiros-ai-card-start\s*-->[\s\S]*?<!--\s*affiros-ai-card-end\s*-->\s*/u';
        return preg_replace($pattern, '', $content);
    }

    /**
     * 最初のH2の直前に挿入 (Gutenberg wp:heading または生 <h2> の両対応)
     */
    private static function insert_before_first_h2($content, $card_html) {
        // Gutenberg wp:heading コメント → 優先
        $pos = self::find_first_position($content, [
            '/<!--\s*wp:heading\b[^>]*"level"\s*:\s*2/i',
            '/<!--\s*wp:heading\s*-->\s*<h2\b/i',
            '/<h2\b/i',
        ]);
        if ($pos === false) return null;
        return substr($content, 0, $pos) . "\n" . $card_html . "\n" . substr($content, $pos);
    }

    /**
     * まとめ見出しの直下に挿入
     * 「まとめ」を含む H2 の </h2> 直後 (Gutenberg の <!-- /wp:heading --> があれば
     * その後) にカードを入れる。まとめ本文より前に商品が目に入る位置。
     * (v0.7.3 以前は「次のH2直前 or 記事末尾」= まとめ本文の後だった)
     */
    private static function insert_after_matome($content, $card_html) {
        // 「まとめ」を含む H2 を探す。Gutenberg / 生HTML 両対応
        if (preg_match_all('/<h2\b[^>]*>([\s\S]*?)<\/h2>/i', $content, $m, PREG_OFFSET_CAPTURE)) {
            foreach ($m[0] as $i => $match) {
                $text = wp_strip_all_tags($m[1][$i][0]);
                if (mb_strpos($text, 'まとめ') !== false || mb_strpos($text, 'おわりに') !== false || mb_strpos($text, '最後に') !== false) {
                    $pos = $match[1] + strlen($match[0]);
                    // Gutenberg のブロック閉じコメントをまたいだ位置に入れる
                    if (preg_match('/^\s*<!--\s*\/wp:heading\s*-->/i', substr($content, $pos, 60), $mm)) {
                        $pos += strlen($mm[0]);
                    }
                    return substr($content, 0, $pos) . "\n" . $card_html . "\n" . substr($content, $pos);
                }
            }
        }
        return null;
    }

    /**
     * $needles 各パターンで最も早い出現位置を返す。見つからなければ false
     */
    private static function find_first_position($content, $patterns) {
        $best = false;
        foreach ($patterns as $pat) {
            if (preg_match($pat, $content, $m, PREG_OFFSET_CAPTURE)) {
                $pos = $m[0][1];
                if ($best === false || $pos < $best) $best = $pos;
            }
        }
        return $best;
    }

    /** 結果生成ヘルパ */
    private static function result($success, $message, $extra = []) {
        return array_merge([
            'success' => (bool)$success,
            'message' => $message,
            'changed' => false,
            'skipped' => false,
        ], $extra);
    }

    /** エラー保存 + 失敗結果 */
    private static function fail($post_id, $message) {
        update_post_meta($post_id, AFFIROS_AI_META_LAST_ERROR, $message);
        return self::result(false, $message);
    }

    private static function clear_last_error($post_id) {
        delete_post_meta($post_id, AFFIROS_AI_META_LAST_ERROR);
    }
}

// =============================================================================
// カード見出しの動的差し替え (v0.8.1)
// =============================================================================
// 見出しはHTMLに焼き込まれているが、表示時に現在の設定値へ差し替える。
// これにより設定変更が再挿入なしで全記事に即反映される。
// (焼き込み側も current 設定で入れているので、フィルタが効かない場面でも
//  挿入時点の見出しは表示される = フォールバック)

add_filter('the_content', function ($content) {
    if (strpos($content, 'affiros-ai-card-head') === false) return $content;

    $settings = affiros_ai_get_settings();
    $suffix = trim((string)($settings['card_heading_suffix'] ?? ''));
    if ($suffix === '') $suffix = 'はどれを選ぶ？';

    // 見出し全体を「{キーワード}」+接尾辞 に書き換える (v0.16.0)。
    // キーワードは旧カードなら <span class="affiros-ai-kw">「KW」で厳選</span> から、
    // 新カードなら見出し先頭の 「KW」 から取り出す。どちらも現在の設定文言で再構成
    // されるため、設定変更が焼き込み済みカードにも即反映される。
    $content = preg_replace_callback(
        '/<div class="affiros-ai-card-head">.*?<\/div>/us',
        function ($m) use ($suffix) {
            $kw = '';
            if (preg_match('/affiros-ai-kw">「(.*?)」/u', $m[0], $km)) {
                $kw = $km[1]; // 旧形式: spanの「KW」で厳選
            } elseif (preg_match('/card-head">「(.*?)」/u', $m[0], $km)) {
                $kw = $km[1]; // 新形式: 見出し先頭の「KW」
            }
            $head = $kw !== '' ? '「' . $kw . '」' . esc_html($suffix) : esc_html($suffix);
            return '<div class="affiros-ai-card-head">' . $head . '</div>';
        },
        $content
    );

    // 価格表示は規約対応 (v0.15.0) で廃止。焼き込み済みの旧カードからも
    // 表示時に除去する (再挿入を待たずに全記事から即座に消える)
    $content = preg_replace('/<div class="affiros-ai-price">[^<]*<\/div>\s*/u', '', $content);
    $content = preg_replace('/<div class="affiros-ai-card-foot"><small>[^<]*<\/small><\/div>\s*/u', '', $content);

    return $content;
}, 20);

// =============================================================================
// 公開時自動挿入
// =============================================================================

add_action('transition_post_status', function ($new_status, $old_status, $post) {
    if ($new_status !== 'publish') return;
    if ($old_status === 'publish' && $new_status === 'publish') return; // 上書き保存はスキップ (無限ループ防止)
    if ($post->post_type !== 'post') return;

    $settings = affiros_ai_get_settings();
    if (($settings['auto_on_publish'] ?? 'yes') !== 'yes') return;

    // wp_update_post による再帰保存を防ぐため、schedule で遅延実行 (WP Cron 経由)
    wp_schedule_single_event(time() + 60, 'affiros_ai_delayed_process', [$post->ID]);
}, 10, 3);

add_action('affiros_ai_delayed_process', function ($post_id) {
    Affiros_AI_Inserter::process(intval($post_id));
});

// =============================================================================
// 月次リフレッシュ (v0.12.0)
// =============================================================================
// 毎日1回、最終挿入から30日以上経過した記事を10件だけ処理する分散ローテーション。
// 週次リフレッシュ (v0.7.0で廃止) の副作用への対策込み:
//   - リビジョンを作らない (wp_revisions_to_keep を処理中だけ0に)
//   - post_modified を動かさない (wp_insert_post_data で元値を復元)
//   - 全記事が同日に動かない (1日10件ずつ)
// 商品再取得+検品のみ (KW再抽出なし) で約¥0.1〜0.4/記事。結果は履歴に記録し
// 一括挿入ページ下部で確認できる。取得失敗時は本文に触らないので既存カードは残る。

add_action('affiros_ai_daily_refresh', function () {
    // セール情報の日次取得 (v0.17.0)。月次リフレッシュOFFでも取得は毎日行う
    affiros_ai_sale_fetch();

    $settings = affiros_ai_get_settings();
    if (($settings['monthly_refresh'] ?? 'yes') !== 'yes') return;

    $allowed = array_filter(array_map('trim', explode(',', $settings['target_statuses'] ?? 'publish,future,draft')));
    if (empty($allowed)) $allowed = ['publish'];

    $cutoff = date('Y-m-d H:i:s', strtotime(current_time('mysql')) - 30 * DAY_IN_SECONDS);

    global $wpdb;
    $placeholders = implode(',', array_fill(0, count($allowed), '%s'));
    $rows = $wpdb->get_col($wpdb->prepare(
        "SELECT p.ID FROM {$wpdb->posts} p
         INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = %s
         WHERE p.post_type = 'post' AND p.post_status IN ($placeholders)
           AND pm.meta_value < %s
         ORDER BY pm.meta_value ASC
         LIMIT 10",
        ...array_merge([AFFIROS_AI_META_LAST_INSERT_AT], $allowed, [$cutoff])
    ));
    if (empty($rows)) return;

    $zero_revisions = function () { return 0; };

    foreach ($rows as $pid) {
        $pid = intval($pid);
        $orig = get_post($pid);
        if (!$orig) continue;

        // 更新日 (post_modified) をリフレッシュで動かさない
        $keep_modified = function ($data, $postarr) use ($pid, $orig) {
            if (intval($postarr['ID'] ?? 0) === $pid) {
                $data['post_modified']     = $orig->post_modified;
                $data['post_modified_gmt'] = $orig->post_modified_gmt;
            }
            return $data;
        };

        add_filter('wp_revisions_to_keep', $zero_revisions, 999);
        add_filter('wp_insert_post_data', $keep_modified, 999, 2);
        $res = Affiros_AI_Inserter::process($pid, ['force_refresh_products' => true]);
        remove_filter('wp_insert_post_data', $keep_modified, 999);
        remove_filter('wp_revisions_to_keep', $zero_revisions, 999);

        affiros_ai_refresh_log_add($pid, $res);
    }
});

endif;
