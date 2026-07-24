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
        if (empty($products_data) || empty($products_data['amazon']) && empty($products_data['rakuten'])) {
            $count = max(3, intval($settings['products_count'] ?? 3));
            $amazon_api  = new Affiros_AI_Amazon_API($settings);
            $rakuten_api = new Affiros_AI_Rakuten_API($settings);

            $amazon_products = [];
            $rakuten_products = [];
            $errors = [];

            if ($amazon_api->is_configured()) {
                $res = $amazon_api->search($keyword, $count);
                if (is_wp_error($res)) $errors[] = 'Amazon: ' . $res->get_error_message();
                else $amazon_products = $res;
            }
            if ($rakuten_api->is_configured()) {
                $res = $rakuten_api->search($keyword, $count);
                if (is_wp_error($res)) $errors[] = '楽天: ' . $res->get_error_message();
                else $rakuten_products = $res;
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
     * まとめの直後に挿入
     * 「まとめ」を含む H2 (or wp:heading level=2) を探し、その次のH2の直前 (or 記事末尾) に挿入
     */
    private static function insert_after_matome($content, $card_html) {
        // 「まとめ」を含む H2 を探す。Gutenberg / 生HTML 両対応
        // wp:heading + h2 with matome text
        if (preg_match_all('/<h2\b[^>]*>([\s\S]*?)<\/h2>/i', $content, $m, PREG_OFFSET_CAPTURE)) {
            $matome_end_pos = null;
            foreach ($m[0] as $i => $match) {
                $text = wp_strip_all_tags($m[1][$i][0]);
                if (mb_strpos($text, 'まとめ') !== false || mb_strpos($text, 'おわりに') !== false || mb_strpos($text, '最後に') !== false) {
                    // まとめのH2 の開始位置
                    $start = $match[1];
                    // 次のH2 (or ドキュメント終端) を探す
                    $next_h2_pos = false;
                    for ($j = $i + 1; $j < count($m[0]); $j++) {
                        $next_h2_pos = $m[0][$j][1];
                        break;
                    }
                    if ($next_h2_pos === false) {
                        // 記事末尾に挿入
                        $matome_end_pos = strlen($content);
                    } else {
                        // 次のH2の直前 (直前の wp:heading コメントがあればその前)
                        $matome_end_pos = self::back_up_to_wp_heading_open($content, $next_h2_pos);
                    }
                    break;
                }
            }
            if ($matome_end_pos !== null) {
                return substr($content, 0, $matome_end_pos) . "\n" . $card_html . "\n" . substr($content, $matome_end_pos);
            }
        }
        return null;
    }

    /**
     * 指定 offset より前を遡って `<!-- wp:heading` の開始 offset を返す。
     * なければ元の offset を返す。
     */
    private static function back_up_to_wp_heading_open($content, $offset) {
        // offset から前方に最大 200 文字くらいの範囲で wp:heading を探す
        $window = substr($content, max(0, $offset - 200), min($offset, 200));
        if (preg_match('/<!--\s*wp:heading[^>]*-->\s*$/i', $window, $m, PREG_OFFSET_CAPTURE)) {
            return max(0, $offset - 200) + $m[0][1];
        }
        return $offset;
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
// 公開時自動挿入 + 週次リフレッシュ
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

// 週次リフレッシュ (価格・在庫更新)
add_action('affiros_ai_weekly_refresh', function () {
    $settings = affiros_ai_get_settings();
    if (($settings['cron_refresh'] ?? 'yes') !== 'yes') return;

    $allowed = array_filter(array_map('trim', explode(',', $settings['target_statuses'] ?? 'publish,future,draft')));
    if (empty($allowed)) $allowed = ['publish'];

    global $wpdb;
    $placeholders = implode(',', array_fill(0, count($allowed), '%s'));
    $rows = $wpdb->get_col($wpdb->prepare(
        "SELECT p.ID FROM {$wpdb->posts} p
         INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID
             AND pm.meta_key = %s
         WHERE p.post_type = 'post' AND p.post_status IN ($placeholders)
         LIMIT 100",
        AFFIROS_AI_META_LAST_INSERT_AT, ...$allowed
    ));
    foreach ($rows as $pid) {
        Affiros_AI_Inserter::process(intval($pid), ['force_refresh_products' => true]);
    }
});

endif;
