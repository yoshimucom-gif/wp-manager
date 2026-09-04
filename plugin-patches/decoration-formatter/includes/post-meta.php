<?php
/**
 * 投稿メタデータ管理ヘルパー（装飾）
 */

if (!defined('ABSPATH')) exit;

class Decofmt_Post_Meta {

    public static function is_decorated($post_id) {
        return (bool) get_post_meta($post_id, '_decofmt_decorated', true);
    }

    public static function get_status($post_id) {
        return get_post_meta($post_id, '_decofmt_status', true) ?: 'none';
    }

    public static function has_backup($post_id) {
        return !empty(get_post_meta($post_id, '_decofmt_backup', true));
    }

    public static function is_excluded($post_id) {
        return (bool) get_post_meta($post_id, '_decofmt_excluded', true);
    }

    public static function set_excluded($post_id, $excluded = true) {
        if ($excluded) {
            update_post_meta($post_id, '_decofmt_excluded', 1);
        } else {
            delete_post_meta($post_id, '_decofmt_excluded');
        }
    }

    public static function query_posts($args = []) {
        $defaults = [
            'post_type' => 'post',
            'post_status' => ['publish'],
            'posts_per_page' => -1,
            'fields' => 'ids',
        ];

        $query_args = wp_parse_args($args, $defaults);

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
        if (count($tax_query) > 1) {
            $tax_query['relation'] = 'AND';
        }
        if (!empty($tax_query)) {
            $query_args['tax_query'] = $tax_query;
        }

        $meta_query = [];
        // 除外フラグが立っているものは常に除外
        $meta_query[] = [
            'key' => '_decofmt_excluded',
            'compare' => 'NOT EXISTS',
        ];

        if (!empty($args['decoration_filter'])) {
            switch ($args['decoration_filter']) {
                case 'undecorated':
                    $meta_query[] = [
                        'key' => '_decofmt_decorated',
                        'compare' => 'NOT EXISTS',
                    ];
                    break;
                case 'decorated':
                    $meta_query[] = [
                        'key' => '_decofmt_decorated',
                        'value' => '1',
                        'compare' => '=',
                    ];
                    break;
                case 'warning':
                    $meta_query[] = [
                        'key' => '_decofmt_status',
                        'value' => 'warning',
                        'compare' => '=',
                    ];
                    break;
            }
        }

        if (count($meta_query) > 1) {
            $meta_query['relation'] = 'AND';
        }
        if (!empty($meta_query)) {
            $query_args['meta_query'] = $meta_query;
        }

        return get_posts($query_args);
    }
}
