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
            }
        }

        if (count($meta_query) > 1) $meta_query['relation'] = 'AND';
        if (!empty($meta_query)) $query_args['meta_query'] = $meta_query;

        $ids = get_posts($query_args);

        // 'has_marker'フィルタは追加で本文チェック
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
}
