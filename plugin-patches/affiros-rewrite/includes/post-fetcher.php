<?php
/**
 * 投稿一覧取得（WP_Query 経由・REST API不使用 → 403回避）
 */

if (!defined('ABSPATH')) exit;

class Affiros_Rewrite_Post_Fetcher {

    /**
     * 投稿一覧を取得
     *
     * @param array $args 取得条件
     *   - per_page: int (default 20)
     *   - page: int (default 1)
     *   - status: string 'publish' (default)
     *   - search: string キーワード検索
     *   - category: int カテゴリーID
     * @return array { items: [...], total: int, total_pages: int }
     */
    public static function fetch($args = []) {
        $per_page = max(1, intval($args['per_page'] ?? 20));
        $page = max(1, intval($args['page'] ?? 1));
        $status = sanitize_text_field($args['status'] ?? 'publish');
        $search = trim((string)($args['search'] ?? ''));
        $category = intval($args['category'] ?? 0);

        // 除外条件
        $exclude_tags = array_map('intval', (array)($args['exclude_tags'] ?? []));
        $exclude_tags = array_values(array_filter($exclude_tags, function ($v) { return $v > 0; }));
        $exclude_cats = array_map('intval', (array)($args['exclude_categories'] ?? []));
        $exclude_cats = array_values(array_filter($exclude_cats, function ($v) { return $v > 0; }));
        $exclude_kw_raw = trim((string)($args['exclude_keywords'] ?? ''));
        // カンマ・改行・スペース区切りでキーワード分解
        $exclude_keywords = [];
        if ($exclude_kw_raw !== '') {
            $parts = preg_split('/[,、\r\n\s]+/u', $exclude_kw_raw);
            foreach ($parts as $p) {
                $p = trim($p);
                if ($p !== '') $exclude_keywords[] = $p;
            }
        }

        $query_args = [
            'post_type' => 'post',
            'post_status' => $status,
            'posts_per_page' => $per_page,
            'paged' => $page,
            'orderby' => 'date',
            'order' => 'DESC',
            'no_found_rows' => false,
        ];
        if ($search !== '') {
            $query_args['s'] = $search;
        }
        if ($category > 0) {
            $query_args['cat'] = $category;
        }
        if (!empty($exclude_cats)) {
            $query_args['category__not_in'] = $exclude_cats;
        }
        if (!empty($exclude_tags)) {
            $query_args['tag__not_in'] = $exclude_tags;
        }

        // 除外キーワード（タイトルに含む記事を除外）。posts_where フィルタで実装。
        // 一発限りで適用後即外す（他クエリへの副作用を防ぐ）。
        $where_filter = null;
        if (!empty($exclude_keywords)) {
            $where_filter = function ($where) use ($exclude_keywords) {
                global $wpdb;
                foreach ($exclude_keywords as $kw) {
                    $like = '%' . $wpdb->esc_like($kw) . '%';
                    $where .= $wpdb->prepare(" AND {$wpdb->posts}.post_title NOT LIKE %s", $like);
                }
                return $where;
            };
            add_filter('posts_where', $where_filter, 10, 1);
        }

        $q = new WP_Query($query_args);

        if ($where_filter !== null) {
            remove_filter('posts_where', $where_filter, 10);
        }
        $items = [];
        if ($q->have_posts()) {
            while ($q->have_posts()) {
                $q->the_post();
                $post_id = get_the_ID();
                $rw_count = (int) get_post_meta($post_id, '_affiros_rewrite_count', true);
                $rw_last  = (string) get_post_meta($post_id, '_affiros_rewrite_last_at', true);
                $items[] = [
                    'id' => $post_id,
                    'title' => get_the_title($post_id),
                    'excerpt' => wp_strip_all_tags(get_the_excerpt($post_id)),
                    'date' => get_the_date('Y-m-d', $post_id),
                    'modified' => get_the_modified_date('Y-m-d', $post_id),
                    'status' => get_post_status($post_id),
                    'category' => self::category_names($post_id),
                    'link' => get_permalink($post_id),
                    'edit_link' => get_edit_post_link($post_id, 'raw'),
                    'word_count' => self::count_chars($post_id),
                    'rewrite_count'   => $rw_count,
                    'rewrite_last_at' => $rw_last !== '' ? mysql2date('Y-m-d H:i', $rw_last) : '',
                ];
            }
            wp_reset_postdata();
        }

        return [
            'items' => $items,
            'total' => intval($q->found_posts),
            'total_pages' => intval($q->max_num_pages),
            'page' => $page,
            'per_page' => $per_page,
        ];
    }

    /**
     * 投稿の本文を取得（リライト用）
     */
    public static function get_post_content($post_id) {
        $post = get_post($post_id);
        if (!$post) {
            return null;
        }
        return [
            'id' => $post->ID,
            'title' => $post->post_title,
            'content' => $post->post_content,
            'status' => $post->post_status,
            'date' => $post->post_date,
            'modified' => $post->post_modified,
            'category' => self::category_names($post->ID),
        ];
    }

    /**
     * 投稿を更新（リライト結果を保存）
     *
     * 保存成功時に以下のメタを更新する（リライト履歴トラッキング用・
     * 本体ロジック・マーカー挿入・品質には一切影響しない）:
     *   _affiros_rewrite_count   : 累計リライト回数
     *   _affiros_rewrite_last_at : 最終リライト日時（mysql 形式）
     */
    public static function update_post($post_id, $new_content, $new_title = null) {
        $update = ['ID' => $post_id, 'post_content' => $new_content];
        if ($new_title) {
            $update['post_title'] = $new_title;
        }
        $result = wp_update_post($update, true);
        if (is_wp_error($result)) {
            return $result;
        }
        // リライト履歴を加算
        $current_count = (int) get_post_meta($post_id, '_affiros_rewrite_count', true);
        update_post_meta($post_id, '_affiros_rewrite_count', $current_count + 1);
        update_post_meta($post_id, '_affiros_rewrite_last_at', current_time('mysql'));
        return ['success' => true, 'post_id' => $post_id];
    }

    private static function category_names($post_id) {
        $cats = get_the_category($post_id);
        if (!$cats) return '';
        return implode(', ', array_map(function ($c) { return $c->name; }, $cats));
    }

    private static function count_chars($post_id) {
        $content = get_post_field('post_content', $post_id);
        $text = wp_strip_all_tags($content);
        return mb_strlen($text);
    }

    /**
     * カテゴリー一覧取得（フィルタ用）
     */
    public static function get_categories() {
        $cats = get_categories(['hide_empty' => false, 'orderby' => 'name']);
        return array_map(function ($c) {
            return ['id' => $c->term_id, 'name' => $c->name, 'count' => $c->count];
        }, $cats);
    }

    /**
     * タグ一覧取得（除外フィルタ用）
     */
    public static function get_tags() {
        $tags = get_tags(['hide_empty' => false, 'orderby' => 'name']);
        return array_map(function ($t) {
            return ['id' => $t->term_id, 'name' => $t->name, 'count' => $t->count];
        }, $tags);
    }
}
