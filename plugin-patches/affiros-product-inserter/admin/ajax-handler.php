<?php
/**
 * AJAX処理ハンドラ
 */
if (!defined('ABSPATH')) exit;

/**
 * 1記事に商品挿入実行
 */
add_action('wp_ajax_ai_pi_insert', 'ai_pi_ajax_insert');
function ai_pi_ajax_insert() {
    check_ajax_referer('ai_pi_nonce', 'nonce');
    if (!current_user_can('edit_posts')) wp_send_json_error(['message' => '権限がありません']);

    $post_id = intval($_POST['post_id'] ?? 0);
    $dry_run = ($_POST['dry_run'] ?? 'false') === 'true';
    $mode = sanitize_text_field($_POST['mode'] ?? '');
    $design = sanitize_text_field($_POST['design'] ?? '');

    if (!$post_id) wp_send_json_error(['message' => '記事IDが不正です']);

    $options = ['dry_run' => $dry_run];
    if ($mode) $options['insert_mode'] = $mode;
    if ($design) $options['card_design'] = $design;

    $result = AI_PI_Inserter::insert_into_post($post_id, $options);

    if (is_wp_error($result)) {
        wp_send_json_error(['message' => $result->get_error_message()]);
    }

    // dry_runの場合、結果HTML（の先頭1000文字程度）を返す
    if ($dry_run) {
        $preview = $result['new_content'] ?? '';
        $result['preview'] = mb_substr($preview, 0, 3000);
    }

    wp_send_json_success($result);
}

/**
 * ロールバック
 */
add_action('wp_ajax_ai_pi_rollback', 'ai_pi_ajax_rollback');
function ai_pi_ajax_rollback() {
    check_ajax_referer('ai_pi_nonce', 'nonce');
    if (!current_user_can('edit_posts')) wp_send_json_error(['message' => '権限がありません']);

    $post_id = intval($_POST['post_id'] ?? 0);
    if (!$post_id) wp_send_json_error(['message' => '記事IDが不正です']);

    $result = AI_PI_Inserter::rollback($post_id);
    if (is_wp_error($result)) wp_send_json_error(['message' => $result->get_error_message()]);

    wp_send_json_success($result);
}

/**
 * 除外フラグ切り替え
 */
add_action('wp_ajax_ai_pi_toggle_exclude', 'ai_pi_ajax_toggle_exclude');
function ai_pi_ajax_toggle_exclude() {
    check_ajax_referer('ai_pi_nonce', 'nonce');
    if (!current_user_can('edit_posts')) wp_send_json_error(['message' => '権限がありません']);

    $post_id = intval($_POST['post_id'] ?? 0);
    $excluded = ($_POST['excluded'] ?? 'false') === 'true';

    AI_PI_Post_Meta::set_excluded($post_id, $excluded);
    wp_send_json_success(['excluded' => $excluded]);
}

/**
 * 対象記事カウント
 */
add_action('wp_ajax_ai_pi_count_targets', 'ai_pi_ajax_count_targets');
function ai_pi_ajax_count_targets() {
    check_ajax_referer('ai_pi_nonce', 'nonce');
    if (!current_user_can('manage_options')) wp_send_json_error(['message' => '権限がありません']);

    $args = [
        'categories' => $_POST['categories'] ?? [],
        'tags' => $_POST['tags'] ?? [],
        'insertion_filter' => sanitize_text_field($_POST['filter'] ?? 'uninserted'),
    ];
    $limit = intval($_POST['limit'] ?? 5);

    $all_ids = AI_PI_Post_Meta::query_posts($args);
    $target_ids = array_slice($all_ids, 0, $limit);

    // コスト試算
    $settings = get_option('ai_pi_settings', []);
    $model = $settings['claude_model'] ?? 'claude-sonnet-4-6';
    $cost_per_post = [
        'claude-haiku-4-5-20251001' => 2,
        'claude-sonnet-4-6' => 15,
        'claude-opus-4-7' => 80,
    ][$model] ?? 15;

    $estimated_cost = count($target_ids) * $cost_per_post;
    $estimated_time = count($target_ids) * 45;

    $preview_ids = array_slice($target_ids, 0, 20);
    $preview = [];
    foreach ($preview_ids as $id) {
        $preview[] = [
            'id' => $id,
            'title' => get_the_title($id),
            'edit_url' => get_edit_post_link($id, ''),
        ];
    }

    wp_send_json_success([
        'total' => count($all_ids),
        'target' => count($target_ids),
        'target_ids' => $target_ids,
        'preview' => $preview,
        'estimated_cost' => $estimated_cost,
        'estimated_time' => $estimated_time,
    ]);
}

/**
 * 一括処理：1件処理
 */
add_action('wp_ajax_ai_pi_bulk_process_one', 'ai_pi_ajax_bulk_process_one');
function ai_pi_ajax_bulk_process_one() {
    check_ajax_referer('ai_pi_nonce', 'nonce');
    if (!current_user_can('manage_options')) wp_send_json_error(['message' => '権限がありません']);

    $post_id = intval($_POST['post_id'] ?? 0);
    $mode = sanitize_text_field($_POST['mode'] ?? 'marker');
    $design = sanitize_text_field($_POST['design'] ?? 'vertical');

    if (!$post_id) wp_send_json_error(['message' => '記事IDが不正です']);

    $result = AI_PI_Inserter::insert_into_post($post_id, [
        'insert_mode' => $mode,
        'card_design' => $design,
        'dry_run' => false,
    ]);

    if (is_wp_error($result)) {
        wp_send_json_success([
            'post_id' => $post_id,
            'title' => get_the_title($post_id),
            'result' => 'failure',
            'message' => $result->get_error_message(),
        ]);
    }

    wp_send_json_success([
        'post_id' => $post_id,
        'title' => get_the_title($post_id),
        'result' => 'success',
        'product_count' => count($result['products'] ?? []),
        'edit_url' => get_edit_post_link($post_id, ''),
    ]);
}
