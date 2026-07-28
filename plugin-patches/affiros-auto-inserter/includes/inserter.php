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
        if (!empty($cached_products) && empty($opts['force_refresh_products'])) {
            $products_data = is_array($cached_products) ? $cached_products : json_decode($cached_products, true);
        }
        $count = max(1, min(5, intval($settings['products_count'] ?? 3)));
        if (empty($products_data) || empty($products_data['amazon']) && empty($products_data['rakuten'])) {
            $amazon_api  = new Affiros_AI_Amazon_API($settings);
            $rakuten_api = new Affiros_AI_Rakuten_API($settings);

            $amazon_products = [];
            $rakuten_products = [];
            $errors = [];

            // 多めに取ってから多様性フィルタで絞る (同一ブランド・類似商品の並びを防ぐ)
            if ($amazon_api->is_configured()) {
                $res = $amazon_api->search($keyword, 10);
                if (is_wp_error($res)) $errors[] = 'Amazon: ' . $res->get_error_message();
                else $amazon_products = self::diversify($res, $count);
            }
            // 楽天は Amazon 商品が取れなかった時だけ主軸として使う
            // (Amazon 主軸カードの楽天ボタンは検索一覧リンクなので商品データ不要)
            if (empty($amazon_products) && $rakuten_api->is_configured()) {
                $res = $rakuten_api->search($keyword, 10);
                if (is_wp_error($res)) $errors[] = '楽天: ' . $res->get_error_message();
                else $rakuten_products = self::diversify($res, $count);
            }

            if (empty($amazon_products) && empty($rakuten_products)) {
                return self::fail($post_id, '商品取得失敗: ' . implode(' / ', $errors ?: ['両APIとも0件']));
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
                'card_heading'         => $settings['card_heading']         ?? '',
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

        return self::result(true, "挿入完了 ({$insertions}箇所)", [
            'changed' => true,
            'keyword' => $keyword,
            'insertions' => $insertions,
            'amazon_count' => count($products_data['amazon'] ?? []),
            'rakuten_count' => count($products_data['rakuten'] ?? []),
        ]);
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
    $heading = trim((string)($settings['card_heading'] ?? ''));
    if ($heading === '') $heading = '超売れ筋のおすすめTOP3';

    // 開始divタグ直後〜次のタグまで (見出しテキスト部分) を差し替え。
    // キーワードの <span class="affiros-ai-kw"> は保持される
    return preg_replace_callback(
        '/(<div class="affiros-ai-card-head">)[^<]*/u',
        function ($m) use ($heading) {
            return $m[1] . esc_html($heading) . ' ';
        },
        $content
    );
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

endif;
