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
        'insertion_filter' => sanitize_text_field($_POST['filter'] ?? 'has_marker'),
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

    // 挿入後検証の結果に応じて success / partial を区別する。
    // partial = カードはいくつか入ったが raw マーカーが残った（uninserted コメントに退避済み）
    $status = $result['status'] ?? 'success';
    $bmm_count = intval($result['brand_mismatch_count'] ?? 0);
    wp_send_json_success([
        'post_id'              => $post_id,
        'title'                => get_the_title($post_id),
        'result'               => $status === 'success' ? 'success' : 'partial',
        'status'               => $status,
        'product_count'        => count($result['products'] ?? []),
        'residual_count'       => intval($result['residual_before_neutralize'] ?? 0),
        'brand_mismatch_count' => $bmm_count,
        'edit_url'             => get_edit_post_link($post_id, ''),
        'message'              => $status === 'success'
            ? ($bmm_count > 0 ? sprintf('挿入完了。ただしブランドミスマッチが %d 件あります（H3 商品名と実商品ブランドが不一致）。', $bmm_count) : null)
            : sprintf('マーカー %d 件が挿入できず退避しました。再処理してください。', intval($result['residual_before_neutralize'] ?? 0)),
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
        // Amazon Creators API (v1.9.29〜)
        'amazon_creators_client_id'     => sanitize_text_field($_POST['amazon_creators_client_id']     ?? ''),
        'amazon_creators_client_secret' => sanitize_text_field($_POST['amazon_creators_client_secret'] ?? ''),
        'amazon_marketplace'            => sanitize_text_field($_POST['amazon_marketplace']            ?? 'www.amazon.co.jp'),
        'amazon_partner_tag'            => sanitize_text_field($_POST['amazon_partner_tag']            ?? ''),
        // 旧 PA-API（互換のため保持）
        'amazon_access_key'    => sanitize_text_field($_POST['amazon_access_key'] ?? ''),
        'amazon_secret_key'    => sanitize_text_field($_POST['amazon_secret_key'] ?? ''),
        // 楽天（v1.9.31〜 accessKey 必須）
        'rakuten_app_id'       => sanitize_text_field($_POST['rakuten_app_id']       ?? ''),
        'rakuten_access_key'   => sanitize_text_field($_POST['rakuten_access_key']   ?? ''),
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

    // --- Amazon Creators API (v1.9.29〜) ---
    $amazon = new AI_PI_Amazon_API($config);
    if (!$amazon->is_configured()) {
        $results[] = ['service' => 'amazon', 'label' => 'Amazon Creators API', 'status' => 'skip', 'message' => 'Client ID / Client Secret / アソシエイトタグ のいずれか未入力'];
    } else {
        $r = $amazon->search('ボールペン', 1);
        if (is_wp_error($r)) {
            $results[] = ['service' => 'amazon', 'label' => 'Amazon Creators API', 'status' => 'ng', 'message' => $r->get_error_message()];
        } else {
            $results[] = ['service' => 'amazon', 'label' => 'Amazon Creators API', 'status' => 'ok', 'message' => '接続成功（' . count($r) . '件取得）'];
        }
    }

    // --- 楽天市場API (v1.9.31〜: 新エンドポイント + accessKey) ---
    $rakuten = new AI_PI_Rakuten_API($config);
    if (!$rakuten->is_configured()) {
        $missing = [];
        if (empty($config['rakuten_app_id']))     $missing[] = 'アプリID';
        if (empty($config['rakuten_access_key'])) $missing[] = 'アクセスキー';
        $results[] = ['service' => 'rakuten', 'label' => '楽天市場API', 'status' => 'skip', 'message' => implode(' / ', $missing) . ' 未入力'];
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
