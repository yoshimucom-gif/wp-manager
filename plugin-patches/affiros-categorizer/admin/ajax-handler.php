<?php
/**
 * AJAX エンドポイント
 *  - affiros_cat_fetch_posts   … 一括分類画面の投稿一覧取得
 *  - affiros_cat_classify_post … 1記事の分類実行（メタボックス・一括分類で共用）
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * 投稿一覧取得（公開済みの post のみ）
 */
add_action('wp_ajax_affiros_cat_fetch_posts', function () {
    check_ajax_referer('affiros_categorizer_nonce', 'nonce');
    if (!current_user_can('manage_options')) {
        wp_send_json_error(['message' => '権限がありません']);
    }

    $paged  = max(1, intval($_POST['page'] ?? 1));
    $search = sanitize_text_field($_POST['search'] ?? '');
    $filter = sanitize_text_field($_POST['category'] ?? '');

    $args = [
        'post_type'      => 'post',
        'post_status'    => 'publish',
        'posts_per_page' => 30,
        'paged'          => $paged,
        'orderby'        => 'date',
        'order'          => 'DESC',
    ];
    if ($search !== '') {
        $args['s'] = $search;
    }
    if ($filter === 'uncategorized') {
        $args['cat'] = (int) get_option('default_category');
    } elseif ($filter !== '' && intval($filter) > 0) {
        $args['cat'] = intval($filter);
    }

    $query = new WP_Query($args);
    $items = [];
    foreach ($query->posts as $p) {
        $cat_names = wp_get_post_categories($p->ID, ['fields' => 'names']);
        $items[] = [
            'id'         => (int) $p->ID,
            'title'      => $p->post_title !== '' ? $p->post_title : '(無題)',
            'categories' => !empty($cat_names) ? implode('、', $cat_names) : '—',
            'edit_link'  => get_edit_post_link($p->ID, 'raw'),
        ];
    }

    wp_send_json_success([
        'items'       => $items,
        'page'        => $paged,
        'total_pages' => (int) $query->max_num_pages,
        'total'       => (int) $query->found_posts,
    ]);
});

/**
 * 1記事の分類実行
 * メタボックス・一括分類の両方から呼ばれる。手動実行なので必ず上書きする（force）。
 */
add_action('wp_ajax_affiros_cat_classify_post', function () {
    check_ajax_referer('affiros_categorizer_nonce', 'nonce');
    $post_id = intval($_POST['post_id'] ?? 0);
    if (!$post_id) {
        wp_send_json_error(['message' => '記事IDが不正です']);
    }
    if (!current_user_can('edit_post', $post_id)) {
        wp_send_json_error(['message' => 'この記事を編集する権限がありません']);
    }

    // Claude API 呼び出しに時間がかかるケースに備える
    @set_time_limit(120);

    $res = Affiros_Cat_Classifier::classify($post_id, true);
    if (empty($res['success'])) {
        wp_send_json_error(['message' => $res['error'] ?: '分類に失敗しました']);
    }
    wp_send_json_success([
        'category' => $res['category'],
        'reason'   => $res['reason'],
    ]);
});
