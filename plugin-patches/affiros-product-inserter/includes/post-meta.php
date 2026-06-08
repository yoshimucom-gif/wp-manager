<?php
/**
 * 投稿メタ管理ヘルパー
 */

if (!defined('ABSPATH')) exit;

class AI_PI_Post_Meta {

    public static function is_inserted($post_id) {
        return (bool) get_post_meta($post_id, '_ai_pi_inserted', true);
    }

    public static function has_backup($post_id) {
        return !empty(get_post_meta($post_id, '_ai_pi_backup', true));
    }

    public static function is_excluded($post_id) {
        return (bool) get_post_meta($post_id, '_ai_pi_excluded', true);
    }

    public static function set_excluded($post_id, $excluded = true) {
        if ($excluded) {
            update_post_meta($post_id, '_ai_pi_excluded', 1);
        } else {
            delete_post_meta($post_id, '_ai_pi_excluded');
        }
    }

    public static function get_products($post_id) {
        return get_post_meta($post_id, '_ai_pi_products', true) ?: [];
    }

    public static function get_inserted_at($post_id) {
        return get_post_meta($post_id, '_ai_pi_inserted_at', true);
    }

    /**
     * 24時間以上経過した商品データに期限切れフラグを立てる
     */
    public static function mark_expired_products() {
        global $wpdb;
        $threshold = date('Y-m-d H:i:s', strtotime('-24 hours'));

        $results = $wpdb->get_results($wpdb->prepare(
            "SELECT post_id, meta_value FROM {$wpdb->postmeta}
             WHERE meta_key = '_ai_pi_inserted_at'
             AND meta_value < %s",
            $threshold
        ));

        foreach ($results as $row) {
            // 既にexpiredならスキップ
            if (get_post_meta($row->post_id, '_ai_pi_expired', true)) continue;
            update_post_meta($row->post_id, '_ai_pi_expired', 1);
        }

        return count($results);
    }

    /**
     * 絞り込みクエリ
     */
    public static function query_posts($args = []) {
        $defaults = [
            'post_type' => 'post',
            'post_status' => ['publish'],
            'posts_per_page' => -1,
            'fields' => 'ids',
        ];
        $query_args = wp_parse_args($args, $defaults);

        // tax_query
        $tax_query = [];
        if (!empty($args['categories'])) {
            $tax_query[] = [
                'taxonomy' => 'category',
                'field' => 'term_id',
                'terms' => array_map('intval', (array) $args['categories']),
            ];
        }
        if (!empty($args['tags'])) {
            $tax_query[] = [
                'taxonomy' => 'post_tag',
                'field' => 'term_id',
                'terms' => array_map('intval', (array) $args['tags']),
            ];
        }
        if (count($tax_query) > 1) $tax_query['relation'] = 'AND';
        if (!empty($tax_query)) $query_args['tax_query'] = $tax_query;

        // meta_query
        $meta_query = [];
        $meta_query[] = [
            'key' => '_ai_pi_excluded',
            'compare' => 'NOT EXISTS',
        ];

        if (!empty($args['insertion_filter'])) {
            switch ($args['insertion_filter']) {
                case 'has_marker':
                    // 本文にマーカーがあれば「処理が必要」と判定する（マーカー有り = SoT）。
                    // 旧版は _ai_pi_inserted フラグ無しを条件に入れていたが、これだと
                    // 過去に処理した記事をリライト等でマーカーが再導入されたケースを
                    // 検知できない問題があった。よって meta 制約は付けない。
                    break;
                case 'uninserted':
                    $meta_query[] = [
                        'key' => '_ai_pi_inserted',
                        'compare' => 'NOT EXISTS',
                    ];
                    break;
                case 'inserted':
                    $meta_query[] = [
                        'key' => '_ai_pi_inserted',
                        'value' => '1',
                        'compare' => '=',
                    ];
                    break;
                case 'expired':
                    $meta_query[] = [
                        'key' => '_ai_pi_expired',
                        'value' => '1',
                        'compare' => '=',
                    ];
                    break;
                case 'residual':
                    // 直前の挿入で raw マーカーが残った（→ uninserted コメントに退避済み）
                    // 記事を抽出。再処理推奨対象。
                    $meta_query[] = [
                        'key' => '_ai_pi_residual_markers',
                        'value' => '0',
                        'compare' => '>',
                        'type'    => 'NUMERIC',
                    ];
                    break;
            }
        }

        if (count($meta_query) > 1) $meta_query['relation'] = 'AND';
        if (!empty($meta_query)) $query_args['meta_query'] = $meta_query;

        $ids = get_posts($query_args);

        // 'has_marker'フィルタは追加で本文チェック（生 raw マーカー）
        if (!empty($args['insertion_filter']) && $args['insertion_filter'] === 'has_marker') {
            $filtered = [];
            foreach ($ids as $id) {
                $content = get_post_field('post_content', $id);
                if (preg_match('/<!--\s*ai-product(?::[a-z]+(?::\d+)?)?\s*-->/i', $content)) {
                    $filtered[] = $id;
                }
            }
            return $filtered;
        }

        return $ids;
    }

    /**
     * 公開済み (publish) 記事のうち、生 raw マーカーが残ったままになっている
     * 件数を返す。WP 管理画面の admin_notices で警告に使う。
     *
     * 「raw マーカー」とは <!--ai-product--> 系であり、退避済みの
     * <!--ai-product-uninserted:...--> は含めない（編集者向けタグなので
     * 公開記事に残っていても表示上のノイズにならない）。
     *
     * パフォーマンス: posts テーブルの post_content を LIKE スキャンする。
     * publish 限定なので運用サイトでは数千件程度に収まる前提。
     */
    public static function count_published_with_raw_markers() {
        global $wpdb;
        $like = '%<!--' . $wpdb->esc_like(' ai-product') . '%';
        // 'ai-product' の前後にスペースの揺らぎがあるので2パターンチェック
        $like2 = '%<!--' . $wpdb->esc_like('ai-product') . '%';
        $sql = $wpdb->prepare(
            "SELECT COUNT(DISTINCT p.ID) FROM {$wpdb->posts} p
             WHERE p.post_type='post' AND p.post_status='publish'
               AND (p.post_content LIKE %s OR p.post_content LIKE %s)
               AND p.post_content REGEXP '<!--[[:space:]]*ai-product(:[a-z]+(:[a-z0-9]+)?)?[[:space:]]*-->'",
            $like, $like2
        );
        return (int) $wpdb->get_var($sql);
    }
}
