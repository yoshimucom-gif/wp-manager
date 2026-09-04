<?php
/**
 * AJAX処理ハンドラ（装飾 + 整形）
 *
 * 装飾: decofmt_deco_* アクション
 * 整形: decofmt_fmt_* アクション
 * nonce は両方共通の 'decofmt_nonce'
 */

if (!defined('ABSPATH')) exit;

// =============================================================================
// AI装飾 AJAX
// =============================================================================

add_action('wp_ajax_decofmt_deco_decorate', 'decofmt_deco_ajax_decorate');
function decofmt_deco_ajax_decorate() {
    check_ajax_referer('decofmt_nonce', 'nonce');
    if (!current_user_can('edit_posts')) {
        wp_send_json_error(['message' => '権限がありません']);
    }
    // v1.0.17: Claude API 呼び出しが300秒 × 最大3回（初回+リトライ2回）なので余裕を持たせる
    @set_time_limit(1000);

    $post_id = intval($_POST['post_id'] ?? 0);
    $dry_run = ($_POST['dry_run'] ?? 'false') === 'true';
    $level = sanitize_text_field($_POST['level'] ?? '');
    $model = sanitize_text_field($_POST['model'] ?? '');
    // v1.0.24: 再試行フラグだけ受け取る（HTMLをPOSTに載せない・WAF対策）
    $is_retry = !empty($_POST['retry']);

    if (!$post_id) {
        wp_send_json_error(['message' => '記事IDが不正です']);
    }

    $allowed_models = array_keys(decofmt_get_models());
    if ($model && !in_array($model, $allowed_models, true)) {
        $model = '';
    }

    $fb_key = 'decofmt_fb_' . $post_id;
    $retry_feedback = $is_retry ? (string) get_transient($fb_key) : '';

    // max_retries=0: サーバー内でリトライせず1リクエスト1API（504対策）
    $options = ['dry_run' => $dry_run, 'max_retries' => 0];
    if ($level) $options['level'] = $level;
    if ($model) $options['model'] = $model;
    if ($retry_feedback !== '') $options['retry_feedback'] = $retry_feedback;

    $result = Decofmt_Decorator::decorate_post($post_id, $options);

    if (is_wp_error($result)) {
        $err_data = $result->get_error_data();
        $fb = is_array($err_data) ? (string)($err_data['retry_feedback'] ?? '') : '';
        if ($fb !== '') {
            set_transient($fb_key, $fb, 600);
        }
        wp_send_json_error([
            'message'   => wp_strip_all_tags($result->get_error_message()),
            'retryable' => ($fb !== ''),
        ]);
    }

    delete_transient($fb_key);

    // v1.0.16: プレビュー（dry_run）時は before（元本文）も返す
    if ($dry_run && !empty($result['decorated'])) {
        $post = get_post($post_id);
        if ($post) $result['before'] = $post->post_content;
    }

    wp_send_json_success($result);
}

add_action('wp_ajax_decofmt_deco_rollback', 'decofmt_deco_ajax_rollback');
function decofmt_deco_ajax_rollback() {
    check_ajax_referer('decofmt_nonce', 'nonce');
    if (!current_user_can('edit_posts')) {
        wp_send_json_error(['message' => '権限がありません']);
    }

    $post_id = intval($_POST['post_id'] ?? 0);
    if (!$post_id) {
        wp_send_json_error(['message' => '記事IDが不正です']);
    }

    $result = Decofmt_Decorator::rollback_post($post_id);

    if (is_wp_error($result)) {
        wp_send_json_error(['message' => $result->get_error_message()]);
    }

    wp_send_json_success($result);
}

add_action('wp_ajax_decofmt_deco_toggle_exclude', 'decofmt_deco_ajax_toggle_exclude');
function decofmt_deco_ajax_toggle_exclude() {
    check_ajax_referer('decofmt_nonce', 'nonce');
    if (!current_user_can('edit_posts')) {
        wp_send_json_error(['message' => '権限がありません']);
    }

    $post_id = intval($_POST['post_id'] ?? 0);
    $excluded = ($_POST['excluded'] ?? 'false') === 'true';

    Decofmt_Post_Meta::set_excluded($post_id, $excluded);
    wp_send_json_success(['excluded' => $excluded]);
}

add_action('wp_ajax_decofmt_deco_count_targets', 'decofmt_deco_ajax_count_targets');
function decofmt_deco_ajax_count_targets() {
    check_ajax_referer('decofmt_nonce', 'nonce');
    if (!current_user_can('manage_options')) {
        wp_send_json_error(['message' => '権限がありません']);
    }

    $args = [
        'categories' => $_POST['categories'] ?? [],
        'tags' => $_POST['tags'] ?? [],
        'decoration_filter' => sanitize_text_field($_POST['filter'] ?? 'undecorated'),
    ];
    $limit = intval($_POST['limit'] ?? DECOFMT_DEFAULT_SCAN_LIMIT);
    $model = sanitize_text_field($_POST['model'] ?? '');

    $all_ids = Decofmt_Post_Meta::query_posts($args);
    $target_ids = array_slice($all_ids, 0, $limit);

    $allowed_models = array_keys(decofmt_get_models());
    if (!in_array($model, $allowed_models, true)) {
        $settings = get_option('decofmt_deco_settings', []);
        $model = $settings['model'] ?? DECOFMT_DEFAULT_MODEL;
    }
    $cost_per_post = decofmt_get_cost_per_post($model);

    $estimated_cost = count($target_ids) * $cost_per_post;
    // v1.0.22: 1記事あたりの実測目安。従来は30秒だったが、検証エラー時のリトライ
    // （最大3回API）を含めると実際は1〜3分かかるため 120 秒に修正。
    // 画面側で同時実行数で割って表示する。
    $estimated_time = count($target_ids) * 120;

    // v1.0.16: 整形と同じテーブル表示のため、全対象IDのメタ情報を返す（過去装飾状態含む）
    $rows = [];
    foreach ($target_ids as $id) {
        $status = Decofmt_Post_Meta::get_status($id); // ok/warning/error/none
        $past_model = get_post_meta($id, '_decofmt_model', true);
        $past_level = get_post_meta($id, '_decofmt_level', true);
        $past_date  = get_post_meta($id, '_decofmt_decorated_at', true);
        $rows[] = [
            'id'                => (int)$id,
            'title'             => get_the_title($id),
            'view_url'          => get_permalink($id),
            'edit_url'          => get_edit_post_link($id, ''),
            'status'            => $status,
            'past_model'        => $past_model ?: '',
            'past_model_label'  => $past_model ? decofmt_get_model_label($past_model) : '',
            'past_level'        => $past_level ?: '',
            'date'              => $past_date ?: '',
        ];
    }

    wp_send_json_success([
        'total'          => count($all_ids),
        'target'         => count($target_ids),
        'target_ids'     => $target_ids,
        'rows'           => $rows,
        'estimated_cost' => $estimated_cost,
        'estimated_time' => $estimated_time,
        'model'          => $model,
        'cost_per_post'  => $cost_per_post,
        'model_label'    => decofmt_get_model_label($model),
    ]);
}

add_action('wp_ajax_decofmt_deco_bulk_process_one', 'decofmt_deco_ajax_bulk_process_one');
function decofmt_deco_ajax_bulk_process_one() {
    check_ajax_referer('decofmt_nonce', 'nonce');
    if (!current_user_can('manage_options')) {
        wp_send_json_error(['message' => '権限がありません']);
    }
    // v1.0.17: Claude API 呼び出しが300秒 × 最大3回なので余裕を持たせる
    @set_time_limit(1000);

    $post_id = intval($_POST['post_id'] ?? 0);
    $level = sanitize_text_field($_POST['level'] ?? 'standard');
    $model = sanitize_text_field($_POST['model'] ?? '');
    // v1.0.24: 再試行かどうかのフラグだけ受け取る（HTMLをPOSTに載せない・WAF対策）
    $is_retry = !empty($_POST['retry']);

    $allowed_models = array_keys(decofmt_get_models());
    if ($model && !in_array($model, $allowed_models, true)) {
        $model = '';
    }

    if (!$post_id) {
        wp_send_json_error(['message' => '記事IDが不正です']);
    }

    // 前回の検証エラーはサーバー側の transient から取り出す。
    // v1.0.23 ではこれをレスポンス→POST で往復させていたが、内容に
    // <!-- wp:xxx --> 等のHTML断片が含まれるため、レンタルサーバーのWAFに
    // 「XSS/PHPコマンド」と判定されて admin-ajax が HTTP 400 で弾かれていた。
    $fb_key = 'decofmt_fb_' . $post_id;
    $retry_feedback = $is_retry ? (string) get_transient($fb_key) : '';

    // max_retries=0: サーバー内でリトライせず1リクエスト1API（504対策）
    $options = ['level' => $level, 'dry_run' => false, 'max_retries' => 0];
    if ($model) $options['model'] = $model;
    if ($retry_feedback !== '') $options['retry_feedback'] = $retry_feedback;

    $result = Decofmt_Decorator::decorate_post($post_id, $options);

    if (is_wp_error($result)) {
        $err_data = $result->get_error_data();
        $fb = is_array($err_data) ? (string)($err_data['retry_feedback'] ?? '') : '';
        if ($fb !== '') {
            set_transient($fb_key, $fb, 600); // 次のリクエストで使う（10分）
        }
        wp_send_json_success([
            'post_id'   => $post_id,
            'title'     => get_the_title($post_id),
            'result'    => 'failure',
            // メッセージからHTMLタグを除去して返す（これもWAF対策）
            'message'   => wp_strip_all_tags($result->get_error_message()),
            'retryable' => ($fb !== ''),
        ]);
    }

    delete_transient($fb_key);

    wp_send_json_success([
        'post_id' => $post_id,
        'title' => get_the_title($post_id),
        'result' => 'success',
        'status' => $result['validation']['status'],
        'edit_url' => get_edit_post_link($post_id, ''),
    ]);
}

// =============================================================================
// 段落整形 AJAX
// =============================================================================

/**
 * v1.0.26: 一括処理画面から整形モードを切り替える。
 * 設定画面を開かなくても「今どのモードで処理するのか」を見て変えられるようにするため。
 * 保存先は設定画面と同じオプションなので、両画面で状態が食い違うことはない。
 */
add_action('wp_ajax_decofmt_fmt_set_mode', 'decofmt_fmt_ajax_set_mode');
function decofmt_fmt_ajax_set_mode() {
    check_ajax_referer('decofmt_nonce', 'nonce');
    if (!current_user_can('manage_options')) wp_send_json_error('権限がありません');

    $mode = (($_POST['mode'] ?? '') === 'one_sentence') ? 'yes' : 'no';
    $settings = get_option('decofmt_fmt_settings', []);
    if (!is_array($settings)) $settings = [];
    $settings['one_sentence_per_paragraph'] = $mode;
    update_option('decofmt_fmt_settings', $settings);

    wp_send_json_success(['one_sentence' => ($mode === 'yes')]);
}

add_action('wp_ajax_decofmt_fmt_scan', 'decofmt_fmt_ajax_scan');
function decofmt_fmt_ajax_scan() {
    check_ajax_referer('decofmt_nonce', 'nonce');
    if (!current_user_can('manage_options')) wp_send_json_error('権限がありません');
    @set_time_limit(120);

    $settings = decofmt_fmt_get_settings();
    $statuses = array_filter(array_map('trim', explode(',', $settings['target_statuses'] ?? 'publish,future,draft')));
    if (empty($statuses)) $statuses = ['publish', 'future', 'draft'];

    // v1.0.27: 投稿タイプ（投稿／固定ページ／カスタム投稿タイプ）とカテゴリで対象を絞り込む。
    // 従来は post_type='post' 固定・全カテゴリだったため、固定ページを整形できず、
    // カテゴリ単位で回すこともできなかった。
    $types     = decofmt_fmt_get_post_types();
    $post_type = sanitize_key($_POST['post_type'] ?? 'post');
    if (!isset($types[$post_type])) {
        $post_type = isset($types['post']) ? 'post' : (string) array_key_first($types);
    }

    $cat_ids = array_values(array_unique(array_filter(array_map('intval', (array) ($_POST['categories'] ?? [])))));
    // 固定ページのようにカテゴリを持たない投稿タイプではカテゴリ条件を無視する
    // （そのまま tax_query に渡すと必ず 0 件になり「対象なし」に見えてしまう）。
    $cat_applied = is_object_in_taxonomy($post_type, 'category');
    if (!$cat_applied) $cat_ids = [];

    $query_args = [
        'post_type'           => $post_type,
        'post_status'         => $statuses,
        'posts_per_page'      => -1,
        'fields'              => 'ids',
        'orderby'             => 'ID',
        'order'               => 'DESC',
        'no_found_rows'       => true,
        'ignore_sticky_posts' => true,
    ];
    if ($cat_ids) {
        $query_args['tax_query'] = [[
            'taxonomy' => 'category',
            'field'    => 'term_id',
            'terms'    => $cat_ids,
        ]];
    }
    $ids = get_posts($query_args);

    // 本文は ID を絞ってから1回のクエリでまとめて取る（記事数が多いサイトでの N+1 回避）
    $rows = [];
    if ($ids) {
        global $wpdb;
        $in = implode(',', array_map('intval', $ids));
        $rows = $wpdb->get_results(
            "SELECT ID, post_title, post_content, post_type FROM {$wpdb->posts}
             WHERE ID IN ($in) ORDER BY ID DESC"
        );
    }

    $targets = [];
    foreach ($rows as $r) {
        $stats = decofmt_fmt_stats($r->post_content, $settings);
        // 「長段落」「見出し昇格候補」「strong+コロン <li>」のいずれかで対象に
        // 「長段落」「見出し昇格候補」「strong+コロン <li>」「短めだが3句以上」のいずれかで対象に
        if ($stats['over_200'] <= 0
            && ($stats['heading_candidates'] ?? 0) <= 0
            && ($stats['strong_label_candidates'] ?? 0) <= 0
            && ($stats['multi_sentence_short'] ?? 0) <= 0) continue;
        $targets[] = [
            'id'                       => (int)$r->ID,
            'title'                    => $r->post_title,
            'count'                    => $stats['count'],
            'max'                      => $stats['max'],
            'over_200'                 => $stats['over_200'],
            'heading_candidates'       => $stats['heading_candidates'] ?? 0,
            'strong_label_candidates'  => $stats['strong_label_candidates'] ?? 0,
            'multi_sentence_short'     => $stats['multi_sentence_short'] ?? 0,
            // v1.0.27: 一覧で投稿／固定ページを見分けられるようにする
            'post_type'                => $r->post_type,
            'post_type_label'          => decofmt_fmt_get_post_type_label($r->post_type),
            // v1.0.13: タイトルクリック時は編集画面ではなく公開URLに飛ばす（吉村さん要望）。
            // 下書き記事の場合 get_permalink() はプレビューURLを返す。
            'view_url'                 => get_permalink($r->ID),
            // 後方互換のため edit_url も残す（JS 側フォールバック用）
            'edit_url'                 => get_edit_post_link($r->ID, ''),
        ];
    }
    wp_send_json_success([
        'scanned'          => count($rows),
        'posts'            => $targets,
        // v1.0.27: 実際に何で絞ったかを画面に返す（カテゴリが無視された場合も分かるように）
        'post_type'        => $post_type,
        'post_type_label'  => decofmt_fmt_get_post_type_label($post_type),
        'category_applied' => (bool) $cat_ids,
        'category_count'   => count($cat_ids),
        'category_ignored' => (!$cat_applied && !empty($_POST['categories'])),
    ]);
}

add_action('wp_ajax_decofmt_fmt_preview', 'decofmt_fmt_ajax_preview');
function decofmt_fmt_ajax_preview() {
    check_ajax_referer('decofmt_nonce', 'nonce');
    if (!current_user_can('manage_options')) wp_send_json_error('権限がありません');

    $post_id = intval($_POST['post_id'] ?? 0);
    if (!$post_id) wp_send_json_error('post_id 不正');
    $post = get_post($post_id);
    if (!$post) wp_send_json_error('記事が見つかりません');

    $before = preg_replace('/<!--\s*\/?wp:[^>]*-->\s*/i', '', $post->post_content);
    $after_raw = decofmt_fmt_process_content($post->post_content);
    $after = preg_replace('/<!--\s*\/?wp:[^>]*-->\s*/i', '', $after_raw);

    wp_send_json_success([
        'before_html' => $before,
        'after_html'  => $after,
    ]);
}

add_action('wp_ajax_decofmt_fmt_apply', 'decofmt_fmt_ajax_apply');
function decofmt_fmt_ajax_apply() {
    check_ajax_referer('decofmt_nonce', 'nonce');
    if (!current_user_can('manage_options')) wp_send_json_error('権限がありません');
    @set_time_limit(60);

    $post_id = intval($_POST['post_id'] ?? 0);
    if (!$post_id) wp_send_json_error('post_id 不正');
    $post = get_post($post_id);
    if (!$post) wp_send_json_error('記事が見つかりません');

    // 変換前後の stats を取ることで、実際に何が変換されたか報告する。
    // 従来は「$new !== $original」だけを見ていたので、見出し前後の空段落追加だけで
    // 「成功」扱いになり、strong ラベルは分割されず残り続けても気付けなかった。
    $settings = decofmt_fmt_get_settings();
    $before_stats = decofmt_fmt_stats($post->post_content, $settings);
    $new = decofmt_fmt_process_content($post->post_content, $settings);
    $after_stats = decofmt_fmt_stats($new, $settings);

    $delta = [
        'over_200_resolved'         => max(0, $before_stats['over_200'] - $after_stats['over_200']),
        'heading_promoted'          => max(0, ($before_stats['heading_candidates'] ?? 0) - ($after_stats['heading_candidates'] ?? 0)),
        'strong_label_split'        => max(0, ($before_stats['strong_label_candidates'] ?? 0) - ($after_stats['strong_label_candidates'] ?? 0)),
        'multi_sentence_short_split'=> max(0, ($before_stats['multi_sentence_short'] ?? 0) - ($after_stats['multi_sentence_short'] ?? 0)),
    ];
    $delta_total = array_sum($delta);
    $remaining_after = [
        'over_200'                => $after_stats['over_200'],
        'heading_candidates'      => $after_stats['heading_candidates'] ?? 0,
        'strong_label_candidates' => $after_stats['strong_label_candidates'] ?? 0,
        'multi_sentence_short'    => $after_stats['multi_sentence_short'] ?? 0,
    ];
    $remaining_total = array_sum($remaining_after);

    if ($new === $post->post_content) {
        wp_send_json_success([
            'changed'         => false,
            'message'         => '変更なし',
            'delta'           => $delta,
            'remaining'       => $remaining_after,
            'remaining_total' => $remaining_total,
        ]);
    }

    set_transient('decofmt_fmt_skip_' . $post_id, 1, 30);
    $result = wp_update_post(['ID' => $post_id, 'post_content' => $new], true);
    delete_transient('decofmt_fmt_skip_' . $post_id);
    if (is_wp_error($result)) wp_send_json_error($result->get_error_message());

    wp_send_json_success([
        'changed'         => true,
        'delta'           => $delta,
        'delta_total'     => $delta_total,
        'remaining'       => $remaining_after,
        'remaining_total' => $remaining_total,
    ]);
}
