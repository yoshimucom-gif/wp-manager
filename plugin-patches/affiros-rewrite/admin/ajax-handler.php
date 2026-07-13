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
        'exclude_tags' => array_map('intval', (array)($_POST['exclude_tags'] ?? [])),
        'exclude_categories' => array_map('intval', (array)($_POST['exclude_categories'] ?? [])),
        'exclude_keywords' => sanitize_text_field((string)($_POST['exclude_keywords'] ?? '')),
        'marker_filter' => sanitize_text_field((string)($_POST['marker_filter'] ?? '')),
    ];

    $result = Affiros_Rewrite_Post_Fetcher::fetch($args);
    wp_send_json_success($result);
});

/**
 * タグ一覧取得（除外フィルタUI用）
 */
add_action('wp_ajax_affiros_rewrite_fetch_tags', function () {
    check_ajax_referer('affiros_rewrite_nonce', 'nonce');
    if (!current_user_can('manage_options')) {
        wp_send_json_error(['message' => '権限がありません']);
    }
    wp_send_json_success(['tags' => Affiros_Rewrite_Post_Fetcher::get_tags()]);
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
 * v0.4.46: セクション並び替え（H2 順序を SEO 最適に並び替え）
 */
add_action('wp_ajax_affiros_rewrite_reorder_sections', function () {
    check_ajax_referer('affiros_rewrite_nonce', 'nonce');
    if (!current_user_can('manage_options')) {
        wp_send_json_error(['message' => '権限がありません']);
    }
    $post_id = intval($_POST['post_id'] ?? 0);
    if (!$post_id) {
        wp_send_json_error(['message' => '記事IDが不正です']);
    }
    $result = Affiros_Rewrite_Engine::reorder_sections_only($post_id);
    if (is_wp_error($result)) {
        wp_send_json_error([
            'message' => $result->get_error_message(),
            'code'    => $result->get_error_code(),
        ]);
    }
    wp_send_json_success($result);
});

/**
 * v0.4.42: マーカー除去のみ（Pre_Cleanup のみ実行）
 */
add_action('wp_ajax_affiros_rewrite_cleanup_markers', function () {
    check_ajax_referer('affiros_rewrite_nonce', 'nonce');
    if (!current_user_can('manage_options')) {
        wp_send_json_error(['message' => '権限がありません']);
    }
    $post_id = intval($_POST['post_id'] ?? 0);
    if (!$post_id) {
        wp_send_json_error(['message' => '記事IDが不正です']);
    }
    $result = Affiros_Rewrite_Engine::cleanup_markers_only($post_id);
    if (is_wp_error($result)) {
        wp_send_json_error(['message' => $result->get_error_message()]);
    }
    wp_send_json_success($result);
});

/**
 * v0.4.42: マーカー挿入のみ（Pre_Cleanup しない・既存マーカー検出時は保存拒否）
 * ランキング記事は strict 判定（N選なら N/N 必須）。
 */
add_action('wp_ajax_affiros_rewrite_insert_markers_new', function () {
    check_ajax_referer('affiros_rewrite_nonce', 'nonce');
    if (!current_user_can('manage_options')) {
        wp_send_json_error(['message' => '権限がありません']);
    }
    $post_id = intval($_POST['post_id'] ?? 0);
    if (!$post_id) {
        wp_send_json_error(['message' => '記事IDが不正です']);
    }
    $opts = [];
    if (isset($_POST['article_type']) && $_POST['article_type'] !== '') {
        $opts['article_type'] = sanitize_text_field($_POST['article_type']);
    }
    $result = Affiros_Rewrite_Engine::insert_markers_new($post_id, $opts);
    if (is_wp_error($result)) {
        wp_send_json_error([
            'message' => $result->get_error_message(),
            'code'    => $result->get_error_code(),
        ]);
    }
    wp_send_json_success($result);
});

/**
 * マーカーのみ挿入モード（Claude 呼ばずに広告マーカーを再配置）
 * v1.7.78 追加。既に WP に公開済みの記事でマーカー位置がおかしいものを
 * リライトせずに直せる。
 * v0.4.42 で cleanup_markers / insert_markers_new に分割。後方互換で残置。
 */
add_action('wp_ajax_affiros_rewrite_insert_markers_only', function () {
    check_ajax_referer('affiros_rewrite_nonce', 'nonce');
    if (!current_user_can('manage_options')) {
        wp_send_json_error(['message' => '権限がありません']);
    }
    $post_id = intval($_POST['post_id'] ?? 0);
    if (!$post_id) {
        wp_send_json_error(['message' => '記事IDが不正です']);
    }
    $opts = [];
    if (isset($_POST['article_type']) && $_POST['article_type'] !== '') {
        $opts['article_type'] = sanitize_text_field($_POST['article_type']);
    }
    $result = Affiros_Rewrite_Engine::insert_markers_only($post_id, $opts);
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

    // 検証結果（marker_validation）をJSONで受け取って投稿メタへ伝播させる
    $marker_validation = null;
    $mv_raw = $_POST['marker_validation'] ?? '';
    if (is_string($mv_raw) && $mv_raw !== '') {
        $decoded = json_decode(wp_unslash($mv_raw), true);
        if (is_array($decoded)) {
            $marker_validation = $decoded;
        }
    } elseif (is_array($mv_raw)) {
        $marker_validation = $mv_raw;
    }

    $result = Affiros_Rewrite_Post_Fetcher::update_post(
        $post_id,
        $content,
        $title !== '' ? $title : null,
        $marker_validation
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

/**
 * リライト履歴がある投稿一覧（リビジョン復元 UI 用）
 */
add_action('wp_ajax_affiros_rewrite_restore_list', function () {
    check_ajax_referer('affiros_rewrite_nonce', 'nonce');
    if (!current_user_can('manage_options')) {
        wp_send_json_error(['message' => '権限がありません']);
    }
    $args = [
        'page' => intval($_POST['page'] ?? 1),
        'per_page' => intval($_POST['per_page'] ?? 20),
    ];
    $result = Affiros_Rewrite_Revision_Restorer::list_rewritten_posts($args);
    wp_send_json_success($result);
});

/**
 * リビジョン復元プレビュー（差分情報を確認）
 */
add_action('wp_ajax_affiros_rewrite_restore_preview', function () {
    check_ajax_referer('affiros_rewrite_nonce', 'nonce');
    if (!current_user_can('manage_options')) {
        wp_send_json_error(['message' => '権限がありません']);
    }
    $post_id = intval($_POST['post_id'] ?? 0);
    if (!$post_id) {
        wp_send_json_error(['message' => '記事IDが不正です']);
    }
    $result = Affiros_Rewrite_Revision_Restorer::preview($post_id);
    if (is_wp_error($result)) {
        wp_send_json_error(['message' => $result->get_error_message()]);
    }
    wp_send_json_success($result);
});

/**
 * リビジョン復元を1件実行
 * mode: 'one_step'（既定・1回分戻る）/ 'oldest'（すべてのリライトを取り消す）/
 *       'before_date'（指定日時より前で最新のリビジョンに戻す）
 *
 * 例外・致命的エラーも catch して JSON で返す（通信エラー扱いされないように）
 */
add_action('wp_ajax_affiros_rewrite_restore_one', function () {
    check_ajax_referer('affiros_rewrite_nonce', 'nonce');
    if (!current_user_can('manage_options')) {
        wp_send_json_error(['message' => '権限がありません']);
    }
    $post_id = intval($_POST['post_id'] ?? 0);
    if (!$post_id) {
        wp_send_json_error(['message' => '記事IDが不正です']);
    }
    $mode = sanitize_text_field($_POST['mode'] ?? 'one_step');
    if (!in_array($mode, ['one_step', 'oldest', 'before_date'], true)) {
        $mode = 'one_step';
    }
    $target_date = sanitize_text_field($_POST['target_date'] ?? '');
    @set_time_limit(60);

    try {
        $result = Affiros_Rewrite_Revision_Restorer::restore_one($post_id, $mode, $target_date);
        if (is_wp_error($result)) {
            wp_send_json_error([
                'message'  => $result->get_error_message(),
                'code'     => $result->get_error_code(),
                'post_id'  => $post_id,
            ]);
        }
        wp_send_json_success($result);
    } catch (Exception $e) {
        error_log('[affiros-rewrite] restore_one Exception post=' . $post_id . ' msg=' . $e->getMessage());
        wp_send_json_error([
            'message' => 'PHP例外: ' . $e->getMessage(),
            'post_id' => $post_id,
        ]);
    } catch (Error $e) {
        // PHP 7+ の致命的エラー（型違い、null メソッド呼出等）
        error_log('[affiros-rewrite] restore_one FatalError post=' . $post_id . ' msg=' . $e->getMessage());
        wp_send_json_error([
            'message' => 'PHP致命的エラー: ' . $e->getMessage(),
            'post_id' => $post_id,
        ]);
    }
});

/**
 * リライト履歴がある全投稿の ID 一覧を返す（「全件復元」ボタン用）
 */
add_action('wp_ajax_affiros_rewrite_restore_all_ids', function () {
    check_ajax_referer('affiros_rewrite_nonce', 'nonce');
    if (!current_user_can('manage_options')) {
        wp_send_json_error(['message' => '権限がありません']);
    }
    $ids = Affiros_Rewrite_Revision_Restorer::list_all_rewritten_post_ids();
    wp_send_json_success([
        'ids'   => $ids,
        'total' => count($ids),
    ]);
});

/**
 * before_date 用：指定日時より後に更新された投稿一覧を返す
 */
add_action('wp_ajax_affiros_rewrite_restore_before_date_list', function () {
    check_ajax_referer('affiros_rewrite_nonce', 'nonce');
    if (!current_user_can('manage_options')) {
        wp_send_json_error(['message' => '権限がありません']);
    }
    $target_date = sanitize_text_field($_POST['target_date'] ?? '');
    if (empty($target_date)) {
        wp_send_json_error(['message' => '基準日時が指定されていません']);
    }
    $args = [
        'page' => intval($_POST['page'] ?? 1),
        'per_page' => intval($_POST['per_page'] ?? 20),
    ];
    $result = Affiros_Rewrite_Revision_Restorer::list_posts_for_before_date($target_date, $args);
    wp_send_json_success($result);
});

/**
 * before_date 用：対象記事の全 ID 一覧（全件復元用）
 */
add_action('wp_ajax_affiros_rewrite_restore_before_date_all_ids', function () {
    check_ajax_referer('affiros_rewrite_nonce', 'nonce');
    if (!current_user_can('manage_options')) {
        wp_send_json_error(['message' => '権限がありません']);
    }
    $target_date = sanitize_text_field($_POST['target_date'] ?? '');
    if (empty($target_date)) {
        wp_send_json_error(['message' => '基準日時が指定されていません']);
    }
    $ids = Affiros_Rewrite_Revision_Restorer::list_posts_modified_after($target_date);
    wp_send_json_success([
        'ids'   => $ids,
        'total' => count($ids),
    ]);
});

/**
 * テーブル修復: 全記事スキャン（ページング）
 *   POST: offset, limit
 *   返却: scanned (今回チェックした件数), found (壊れた記事の配列)
 */
add_action('wp_ajax_affiros_rewrite_scan_tables', function () {
    check_ajax_referer('affiros_rewrite_nonce', 'nonce');
    if (!current_user_can('manage_options')) {
        wp_send_json_error('権限がありません');
    }
    @set_time_limit(120);

    $offset = max(0, intval($_POST['offset'] ?? 0));
    $limit  = min(200, max(1, intval($_POST['limit'] ?? 100)));

    $posts = get_posts([
        'post_type'        => 'post',
        'post_status'      => ['publish', 'draft', 'future', 'private'],
        'numberposts'      => $limit,
        'offset'           => $offset,
        'orderby'          => 'ID',
        'order'            => 'DESC',
        'suppress_filters' => true,
        'no_found_rows'    => true,
    ]);

    $found = [];
    foreach ($posts as $p) {
        $count = Affiros_Rewrite_Gutenberg::count_malformed_table_blocks((string)$p->post_content);
        if ($count > 0) {
            $found[] = [
                'id'           => (int)$p->ID,
                'title'        => (string)$p->post_title,
                'broken_count' => (int)$count,
            ];
        }
    }

    wp_send_json_success([
        'scanned' => count($posts),
        'found'   => $found,
    ]);
});

/**
 * 重複投稿スキャン: post_title でグルーピング、複数あれば最古を保持・残りを削除候補とする
 */
add_action('wp_ajax_affiros_rewrite_dup_scan', function () {
    check_ajax_referer('affiros_rewrite_nonce', 'nonce');
    if (!current_user_can('manage_options')) {
        wp_send_json_error('権限がありません');
    }
    @set_time_limit(120);

    global $wpdb;
    // 対象: post タイプ、ゴミ箱・自動下書き以外
    $rows = $wpdb->get_results(
        "SELECT ID, post_title, post_date, post_status
         FROM {$wpdb->posts}
         WHERE post_type = 'post'
           AND post_status NOT IN ('trash', 'auto-draft', 'inherit')
           AND TRIM(post_title) <> ''
         ORDER BY ID ASC",
        ARRAY_A
    );

    $groups = [];
    foreach ($rows as $r) {
        $title = trim($r['post_title']);
        if (!isset($groups[$title])) {
            $groups[$title] = [];
        }
        $groups[$title][] = [
            'id'       => intval($r['ID']),
            'date'     => $r['post_date'],
            'status'   => $r['post_status'],
            'edit_url' => admin_url('post.php?action=edit&post=' . intval($r['ID'])),
        ];
    }

    $result = [];
    foreach ($groups as $title => $posts) {
        if (count($posts) < 2) continue;
        // ID 昇順なので先頭が最古
        $keep = array_shift($posts);
        $result[] = [
            'title'      => $title,
            'keep'       => $keep,
            'duplicates' => $posts,
        ];
    }

    wp_send_json_success([
        'scanned' => count($rows),
        'groups'  => $result,
    ]);
});

/**
 * 重複投稿削除: 1件削除（permanent=1 なら永久削除、それ以外はゴミ箱送り）
 */
add_action('wp_ajax_affiros_rewrite_dup_delete', function () {
    check_ajax_referer('affiros_rewrite_nonce', 'nonce');
    if (!current_user_can('manage_options')) {
        wp_send_json_error('権限がありません');
    }
    @set_time_limit(60);

    $post_id = intval($_POST['post_id'] ?? 0);
    $permanent = !empty($_POST['permanent']);
    if (!$post_id) wp_send_json_error('post_id が不正です');
    $post = get_post($post_id);
    if (!$post) wp_send_json_error('記事が見つかりません');
    if ($post->post_type !== 'post') wp_send_json_error('post タイプ以外は削除しません');

    if ($permanent) {
        $result = wp_delete_post($post_id, true);
    } else {
        $result = wp_trash_post($post_id);
    }
    if (!$result) {
        wp_send_json_error('削除に失敗しました');
    }
    wp_send_json_success(['message' => $permanent ? '永久削除しました' : 'ゴミ箱に送りました']);
});


/**
 * テーブル修復: 1記事修復
 *   POST: post_id
 */
add_action('wp_ajax_affiros_rewrite_repair_tables', function () {
    check_ajax_referer('affiros_rewrite_nonce', 'nonce');
    if (!current_user_can('manage_options')) {
        wp_send_json_error('権限がありません');
    }
    @set_time_limit(60);

    $post_id = intval($_POST['post_id'] ?? 0);
    if (!$post_id) {
        wp_send_json_error('post_id が不正です');
    }
    $post = get_post($post_id);
    if (!$post) {
        wp_send_json_error('記事が見つかりません');
    }
    $original = (string)$post->post_content;
    $repaired = Affiros_Rewrite_Gutenberg::repair_table_blocks($original);
    if ($repaired === $original) {
        wp_send_json_success([
            'message' => '修復不要（既に正常）',
            'changed' => false,
        ]);
    }

    // post_modified を更新せずに本文だけ書き換えたい場合は wp_insert_post に
    // edit_date を渡せばよいが、ここではリビジョン作成と更新日時更新を
    // そのまま許容する（=「いつ修復したか」を残す）。
    $result = wp_update_post([
        'ID'           => $post_id,
        'post_content' => $repaired,
    ], true);
    if (is_wp_error($result)) {
        wp_send_json_error($result->get_error_message());
    }
    // 修復後は admin notice のキャッシュをクリアして即時反映
    if (function_exists('affiros_rewrite_clear_broken_tables_cache')) {
        affiros_rewrite_clear_broken_tables_cache();
    }
    wp_send_json_success([
        'message' => '修復完了',
        'changed' => true,
    ]);
});
