<?php
/**
 * AJAX処理ハンドラ
 */

if (!defined('ABSPATH')) exit;

/**
 * 1記事装飾実行
 */
add_action('wp_ajax_ai_deco_decorate', 'ai_deco_ajax_decorate');
function ai_deco_ajax_decorate() {
    check_ajax_referer('ai_deco_nonce', 'nonce');
    if (!current_user_can('edit_posts')) {
        wp_send_json_error(['message' => '権限がありません']);
    }

    $post_id = intval($_POST['post_id'] ?? 0);
    $dry_run = ($_POST['dry_run'] ?? 'false') === 'true';
    $level = sanitize_text_field($_POST['level'] ?? '');
    $model = sanitize_text_field($_POST['model'] ?? '');

    if (!$post_id) {
        wp_send_json_error(['message' => '記事IDが不正です']);
    }

    // モデル名のホワイトリスト検証
    $allowed_models = array_keys(ai_deco_get_models());
    if ($model && !in_array($model, $allowed_models, true)) {
        $model = '';
    }

    $options = ['dry_run' => $dry_run];
    if ($level) $options['level'] = $level;
    if ($model) $options['model'] = $model;

    $result = AI_Deco_Decorator::decorate_post($post_id, $options);

    if (is_wp_error($result)) {
        wp_send_json_error(['message' => $result->get_error_message()]);
    }

    wp_send_json_success($result);
}

/**
 * ロールバック
 */
add_action('wp_ajax_ai_deco_rollback', 'ai_deco_ajax_rollback');
function ai_deco_ajax_rollback() {
    check_ajax_referer('ai_deco_nonce', 'nonce');
    if (!current_user_can('edit_posts')) {
        wp_send_json_error(['message' => '権限がありません']);
    }

    $post_id = intval($_POST['post_id'] ?? 0);
    if (!$post_id) {
        wp_send_json_error(['message' => '記事IDが不正です']);
    }

    $result = AI_Deco_Decorator::rollback_post($post_id);

    if (is_wp_error($result)) {
        wp_send_json_error(['message' => $result->get_error_message()]);
    }

    wp_send_json_success($result);
}

/**
 * 除外フラグ切り替え
 */
add_action('wp_ajax_ai_deco_toggle_exclude', 'ai_deco_ajax_toggle_exclude');
function ai_deco_ajax_toggle_exclude() {
    check_ajax_referer('ai_deco_nonce', 'nonce');
    if (!current_user_can('edit_posts')) {
        wp_send_json_error(['message' => '権限がありません']);
    }

    $post_id = intval($_POST['post_id'] ?? 0);
    $excluded = ($_POST['excluded'] ?? 'false') === 'true';

    AI_Deco_Post_Meta::set_excluded($post_id, $excluded);
    wp_send_json_success(['excluded' => $excluded]);
}

/**
 * 一括処理用：対象記事カウント
 */
add_action('wp_ajax_ai_deco_count_targets', 'ai_deco_ajax_count_targets');
function ai_deco_ajax_count_targets() {
    check_ajax_referer('ai_deco_nonce', 'nonce');
    if (!current_user_can('manage_options')) {
        wp_send_json_error(['message' => '権限がありません']);
    }

    $args = [
        'categories' => $_POST['categories'] ?? [],
        'tags' => $_POST['tags'] ?? [],
        'decoration_filter' => sanitize_text_field($_POST['filter'] ?? 'undecorated'),
    ];
    $limit = intval($_POST['limit'] ?? 10);
    $model = sanitize_text_field($_POST['model'] ?? '');

    $all_ids = AI_Deco_Post_Meta::query_posts($args);
    $target_ids = array_slice($all_ids, 0, $limit);

    // コスト見積もり：指定モデルがあればそれ、なければデフォルトモデル
    $allowed_models = array_keys(ai_deco_get_models());
    if (!in_array($model, $allowed_models, true)) {
        $settings = get_option('ai_deco_settings', []);
        $model = $settings['model'] ?? 'claude-sonnet-4-6';
    }
    $cost_per_post = ai_deco_get_cost_per_post($model);

    $estimated_cost = count($target_ids) * $cost_per_post;
    $estimated_time = count($target_ids) * 30; // 秒

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
        'model' => $model,
        'cost_per_post' => $cost_per_post,
        'model_label' => ai_deco_get_model_label($model),
    ]);
}

/**
 * 一括処理用：1件処理
 */
add_action('wp_ajax_ai_deco_bulk_process_one', 'ai_deco_ajax_bulk_process_one');
function ai_deco_ajax_bulk_process_one() {
    check_ajax_referer('ai_deco_nonce', 'nonce');
    if (!current_user_can('manage_options')) {
        wp_send_json_error(['message' => '権限がありません']);
    }

    $post_id = intval($_POST['post_id'] ?? 0);
    $level = sanitize_text_field($_POST['level'] ?? 'standard');
    $model = sanitize_text_field($_POST['model'] ?? '');

    $allowed_models = array_keys(ai_deco_get_models());
    if ($model && !in_array($model, $allowed_models, true)) {
        $model = '';
    }

    if (!$post_id) {
        wp_send_json_error(['message' => '記事IDが不正です']);
    }

    $options = ['level' => $level, 'dry_run' => false];
    if ($model) $options['model'] = $model;

    $result = AI_Deco_Decorator::decorate_post($post_id, $options);

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
        'status' => $result['validation']['status'],
        'edit_url' => get_edit_post_link($post_id, ''),
    ]);
}
