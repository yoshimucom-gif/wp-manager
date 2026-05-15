<?php
/**
 * AJAX エンドポイント
 */

if (!defined('ABSPATH')) exit;

/**
 * 投稿一覧取得
 */
add_action('wp_ajax_affiros_rewrite_fetch_posts', function () {
    check_ajax_referer('affiros_rewrite_nonce', 'nonce');
    if (!current_user_can('manage_options')) {
        wp_send_json_error(['message' => '権限がありません']);
    }

    $args = [
        'page' => intval($_POST['page'] ?? 1),
        'per_page' => intval($_POST['per_page'] ?? 20),
        'search' => sanitize_text_field($_POST['search'] ?? ''),
        'category' => intval($_POST['category'] ?? 0),
        'status' => sanitize_text_field($_POST['status'] ?? 'publish'),
    ];

    $result = Affiros_Rewrite_Post_Fetcher::fetch($args);
    wp_send_json_success($result);
});

/**
 * 単一記事の本文取得（Phase 2 で使用）
 */
add_action('wp_ajax_affiros_rewrite_get_post', function () {
    check_ajax_referer('affiros_rewrite_nonce', 'nonce');
    if (!current_user_can('manage_options')) {
        wp_send_json_error(['message' => '権限がありません']);
    }
    $post_id = intval($_POST['post_id'] ?? 0);
    if (!$post_id) {
        wp_send_json_error(['message' => '記事IDが不正です']);
    }
    $post = Affiros_Rewrite_Post_Fetcher::get_post_content($post_id);
    if (!$post) {
        wp_send_json_error(['message' => '記事が見つかりません']);
    }
    wp_send_json_success($post);
});
