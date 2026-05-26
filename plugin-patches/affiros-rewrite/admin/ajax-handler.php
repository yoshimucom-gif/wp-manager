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
 * リライト実行（1記事）
 * - opts でモード等を上書き可能（指定なければ設定画面のデフォルト値）
 * - 保存はしない（returnのみ）→ クライアント側で確認後に save 呼び出し
 */
add_action('wp_ajax_affiros_rewrite_run_single', function () {
    check_ajax_referer('affiros_rewrite_nonce', 'nonce');
    if (!current_user_can('manage_options')) {
        wp_send_json_error(['message' => '権限がありません']);
    }
    $post_id = intval($_POST['post_id'] ?? 0);
    if (!$post_id) {
        wp_send_json_error(['message' => '記事IDが不正です']);
    }

    $opts = [];
    foreach (['rewrite_mode', 'emphasis_level', 'tone', 'target_chars', 'tolerance_percent', 'article_type'] as $k) {
        if (isset($_POST[$k]) && $_POST[$k] !== '') {
            $opts[$k] = sanitize_text_field($_POST[$k]);
        }
    }
    // マーカー挿入オプション
    $opts['insert_markers'] = !empty($_POST['insert_markers']) && $_POST['insert_markers'] !== '0';

    // PHP の実行時間を伸ばす（Claude API が長くかかるケースに備える）
    @set_time_limit(180);

    $result = Affiros_Rewrite_Engine::run($post_id, $opts);
    if (is_wp_error($result)) {
        wp_send_json_error(['message' => $result->get_error_message()]);
    }
    wp_send_json_success($result);
});

/**
 * リライト結果をWP投稿へ保存
 * リビジョンは wp_update_post が自動作成するので、いつでもロールバック可能
 */
add_action('wp_ajax_affiros_rewrite_save', function () {
    check_ajax_referer('affiros_rewrite_nonce', 'nonce');
    if (!current_user_can('manage_options')) {
        wp_send_json_error(['message' => '権限がありません']);
    }
    $post_id = intval($_POST['post_id'] ?? 0);
    if (!$post_id) {
        wp_send_json_error(['message' => '記事IDが不正です']);
    }
    // wp_unslash しないと \" が含まれるとそのまま保存されてしまう
    $content = wp_unslash($_POST['content'] ?? '');
    $title = wp_unslash($_POST['title'] ?? '');
    if ($content === '') {
        wp_send_json_error(['message' => '本文が空です']);
    }

    $result = Affiros_Rewrite_Post_Fetcher::update_post(
        $post_id,
        $content,
        $title !== '' ? $title : null
    );
    if (is_wp_error($result)) {
        wp_send_json_error(['message' => $result->get_error_message()]);
    }
    wp_send_json_success([
        'post_id' => $post_id,
        'edit_link' => get_edit_post_link($post_id, 'raw'),
        'view_link' => get_permalink($post_id),
    ]);
});
