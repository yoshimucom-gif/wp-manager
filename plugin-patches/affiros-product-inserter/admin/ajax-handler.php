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

/**
 * API認証情報の有効性チェック（接続テスト）
 *
 * 設定画面のフォームに入力中の値で実際に各APIを叩き、保存前に
 * 打ち間違い・無効キーを検出する。値は保存しない。
 */
add_action('wp_ajax_ai_pi_test_credentials', 'ai_pi_ajax_test_credentials');
function ai_pi_ajax_test_credentials() {
    check_ajax_referer('ai_pi_nonce', 'nonce');
    if (!current_user_can('manage_options')) {
        wp_send_json_error(['message' => '権限がありません']);
    }

    $config = [
        'claude_api_key'       => sanitize_text_field($_POST['claude_api_key'] ?? ''),
        'claude_model'         => sanitize_text_field($_POST['claude_model'] ?? 'claude-sonnet-4-6'),
        'amazon_access_key'    => sanitize_text_field($_POST['amazon_access_key'] ?? ''),
        'amazon_secret_key'    => sanitize_text_field($_POST['amazon_secret_key'] ?? ''),
        'amazon_partner_tag'   => sanitize_text_field($_POST['amazon_partner_tag'] ?? ''),
        'rakuten_app_id'       => sanitize_text_field($_POST['rakuten_app_id'] ?? ''),
        'rakuten_affiliate_id' => sanitize_text_field($_POST['rakuten_affiliate_id'] ?? ''),
    ];

    $results = [];

    // --- Claude API ---
    if (empty($config['claude_api_key'])) {
        $results[] = ['service' => 'claude', 'label' => 'Claude API', 'status' => 'skip', 'message' => 'APIキー未入力'];
    } else {
        $claude = new AI_PI_Claude_API($config);
        $r = $claude->test_connection();
        if (is_wp_error($r)) {
            $results[] = ['service' => 'claude', 'label' => 'Claude API', 'status' => 'ng', 'message' => $r->get_error_message()];
        } else {
            $results[] = ['service' => 'claude', 'label' => 'Claude API', 'status' => 'ok', 'message' => '接続成功（モデル: ' . $config['claude_model'] . '）'];
        }
    }

    // --- Amazon PA-API ---
    $amazon = new AI_PI_Amazon_API($config);
    if (!$amazon->is_configured()) {
        $results[] = ['service' => 'amazon', 'label' => 'Amazon PA-API', 'status' => 'skip', 'message' => 'Access Key / Secret Key / アソシエイトタグ のいずれか未入力'];
    } else {
        $r = $amazon->search('ボールペン', 1);
        if (is_wp_error($r)) {
            $results[] = ['service' => 'amazon', 'label' => 'Amazon PA-API', 'status' => 'ng', 'message' => $r->get_error_message()];
        } else {
            $results[] = ['service' => 'amazon', 'label' => 'Amazon PA-API', 'status' => 'ok', 'message' => '接続成功（' . count($r) . '件取得）'];
        }
    }

    // --- 楽天市場API ---
    $rakuten = new AI_PI_Rakuten_API($config);
    if (!$rakuten->is_configured()) {
        $results[] = ['service' => 'rakuten', 'label' => '楽天市場API', 'status' => 'skip', 'message' => 'アプリID未入力'];
    } else {
        $r = $rakuten->search('ボールペン', 1);
        if (!is_wp_error($r)) {
            $msg = !empty($config['rakuten_affiliate_id'])
                ? '接続成功（アフィリエイトID込みで検証）'
                : '接続成功（アフィリエイトID未設定）';
            $results[] = ['service' => 'rakuten', 'label' => '楽天市場API', 'status' => 'ok', 'message' => $msg];
        } else {
            // アフィリエイトID込みで失敗した場合、ID無しで再試行して原因を切り分ける
            $msg = $r->get_error_message();
            if (!empty($config['rakuten_affiliate_id'])) {
                $config_no_aff = $config;
                $config_no_aff['rakuten_affiliate_id'] = '';
                $r2 = (new AI_PI_Rakuten_API($config_no_aff))->search('ボールペン', 1);
                if (!is_wp_error($r2)) {
                    $msg = 'アプリIDは有効ですが、アフィリエイトIDが不正の可能性があります（' . $msg . '）';
                }
            }
            $results[] = ['service' => 'rakuten', 'label' => '楽天市場API', 'status' => 'ng', 'message' => $msg];
        }
    }

    wp_send_json_success(['results' => $results]);
}

/* ─────────────────────────────────────────────
 * バックグラウンドジョブ用エンドポイント
 * ───────────────────────────────────────────── */

add_action('wp_ajax_ai_pi_enqueue_bulk', function () {
    check_ajax_referer('ai_pi_nonce', 'nonce');
    if (!current_user_can('manage_options')) wp_send_json_error(['message' => '権限がありません']);

    $ids = array_map('intval', (array)($_POST['post_ids'] ?? []));
    $ids = array_values(array_filter($ids, function ($v) { return $v > 0; }));
    if (empty($ids)) wp_send_json_error(['message' => '対象記事が選択されていません']);

    $options = [
        'insert_mode' => sanitize_text_field($_POST['mode']   ?? 'marker'),
        'card_design' => sanitize_text_field($_POST['design'] ?? 'vertical'),
    ];

    $job_id = AI_PI_Job_Queue::create_job($ids, $options);
    if (!$job_id) wp_send_json_error(['message' => 'ジョブを作成できませんでした']);

    wp_schedule_single_event(time() + 5, AI_PI_Worker::TICK_HOOK);

    wp_send_json_success([
        'job_id'      => $job_id,
        'count'       => count($ids),
        'history_url' => admin_url('admin.php?page=ai-product-inserter-history'),
    ]);
});

add_action('wp_ajax_ai_pi_jobs_list', function () {
    check_ajax_referer('ai_pi_nonce', 'nonce');
    if (!current_user_can('manage_options')) wp_send_json_error(['message' => '権限がありません']);
    $jobs = AI_PI_Job_Queue::list_sorted();
    $light = array_values(array_map(function ($j) {
        unset($j['items']);
        return $j;
    }, $jobs));
    wp_send_json_success(['jobs' => $light]);
});

add_action('wp_ajax_ai_pi_job_status', function () {
    check_ajax_referer('ai_pi_nonce', 'nonce');
    if (!current_user_can('manage_options')) wp_send_json_error(['message' => '権限がありません']);
    $job_id = sanitize_text_field((string)($_POST['job_id'] ?? ''));
    $job = AI_PI_Job_Queue::get($job_id);
    if (!$job) wp_send_json_error(['message' => 'ジョブが見つかりません']);
    wp_send_json_success($job);
});

add_action('wp_ajax_ai_pi_job_cancel', function () {
    check_ajax_referer('ai_pi_nonce', 'nonce');
    if (!current_user_can('manage_options')) wp_send_json_error(['message' => '権限がありません']);
    $job_id = sanitize_text_field((string)($_POST['job_id'] ?? ''));
    $ok = AI_PI_Job_Queue::cancel($job_id);
    wp_send_json_success(['cancelled' => $ok]);
});

add_action('wp_ajax_ai_pi_job_delete', function () {
    check_ajax_referer('ai_pi_nonce', 'nonce');
    if (!current_user_can('manage_options')) wp_send_json_error(['message' => '権限がありません']);
    $job_id = sanitize_text_field((string)($_POST['job_id'] ?? ''));
    $ok = AI_PI_Job_Queue::delete($job_id);
    wp_send_json_success(['deleted' => $ok]);
});
