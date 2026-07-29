<?php
/**
 * 実アイキャッチとしての一括書き込み／一括取り消し（AJAX）。
 *
 * 書き込んだ記事には「どの画像を入れたか」を目印メタとして残す。
 * 取り消しは、現在のアイキャッチが目印と一致する記事だけを対象にするので、
 * あとから手で別画像に差し替えた記事を巻き込まない。
 */

if (!defined('ABSPATH')) exit;

/** 一括処理の対象になる投稿ステータス（ゴミ箱・自動下書きは除外） */
function affiros_cat_eyecatch_target_statuses() {
    return ['publish', 'future', 'draft', 'pending', 'private'];
}

/** 対象投稿タイプの記事IDを全部取る */
function affiros_cat_eyecatch_all_target_ids() {
    $post_types = affiros_cat_eyecatch_enabled_post_types();
    if (!$post_types) return [];

    return get_posts([
        'post_type'              => $post_types,
        'post_status'            => affiros_cat_eyecatch_target_statuses(),
        'posts_per_page'         => -1,
        'fields'                 => 'ids',
        'no_found_rows'          => true,
        'ignore_sticky_posts'    => true,
        'update_post_term_cache' => false,
        'update_post_meta_cache' => false,
        'orderby'                => 'ID',
        'order'                  => 'ASC',
    ]);
}

add_action('wp_ajax_affiros_cat_eyecatch_scan', function () {
    check_ajax_referer('affiros_cat_eyecatch_nonce', 'nonce');
    if (!current_user_can('manage_options')) wp_send_json_error('権限がありません');
    @set_time_limit(180);

    $ids = affiros_cat_eyecatch_all_target_ids();
    if (count($ids) > 20000) {
        wp_send_json_error('対象記事が2万件を超えています。安全のため一括処理は行いません。');
    }

    // メタとタームを一括で温めてから回す（1件ずつクエリを撃たない）
    if ($ids) {
        update_postmeta_cache($ids);
        foreach (affiros_cat_eyecatch_enabled_post_types() as $pt) {
            update_object_term_cache($ids, $pt);
        }
    }

    $missing = [];
    $resolvable = [];
    $applied = 0;

    foreach ($ids as $id) {
        if (get_post_meta($id, AFFIROS_CAT_EYECATCH_APPLIED_META, true)) $applied++;

        $thumb = get_post_meta($id, '_thumbnail_id', true);
        if (!empty($thumb)) continue;

        $missing[] = $id;
        if (affiros_cat_eyecatch_resolve_for_post($id)) $resolvable[] = $id;
    }

    wp_send_json_success([
        'total'         => count($ids),
        'missing'       => count($missing),
        'resolvable'    => count($resolvable),
        'unresolvable'  => count($missing) - count($resolvable),
        'applied'       => $applied,
        'ids'           => array_map('intval', $resolvable),
        'empty_terms'   => affiros_cat_eyecatch_terms_without_image(),
    ]);
});

/** 画像が設定されていない（親から継承もできない）ターム一覧 */
function affiros_cat_eyecatch_terms_without_image() {
    $s = affiros_cat_eyecatch_settings();
    $out = [];

    foreach (affiros_cat_eyecatch_enabled_taxonomies() as $tax) {
        $terms = get_terms(['taxonomy' => $tax, 'hide_empty' => false]);
        if (is_wp_error($terms)) continue;

        foreach ($terms as $term) {
            if (affiros_cat_eyecatch_term_image_id($term->term_id, !empty($s['inherit_parent']))) continue;
            $out[] = [
                'name'  => $term->name,
                'tax'   => $tax,
                'count' => (int)$term->count,
                'link'  => get_edit_term_link($term->term_id, $tax),
            ];
        }
    }
    return $out;
}

add_action('wp_ajax_affiros_cat_eyecatch_apply', function () {
    check_ajax_referer('affiros_cat_eyecatch_nonce', 'nonce');
    if (!current_user_can('manage_options')) wp_send_json_error('権限がありません');
    @set_time_limit(180);

    $ids = isset($_POST['ids']) ? (array)$_POST['ids'] : [];
    $ids = array_slice(array_filter(array_map('intval', $ids)), 0, 200);
    if (!$ids) wp_send_json_error('対象がありません');

    $done = 0;
    $skipped = 0;

    foreach ($ids as $id) {
        // スキャン後に手でアイキャッチが付いた記事を上書きしないよう、直前に再確認する
        $thumb = get_post_meta($id, '_thumbnail_id', true);
        if (!empty($thumb)) { $skipped++; continue; }

        $image_id = affiros_cat_eyecatch_resolve_for_post($id);
        if (!$image_id) { $skipped++; continue; }

        set_post_thumbnail($id, $image_id);
        update_post_meta($id, AFFIROS_CAT_EYECATCH_APPLIED_META, $image_id);
        $done++;
    }

    wp_send_json_success(['done' => $done, 'skipped' => $skipped]);
});

add_action('wp_ajax_affiros_cat_eyecatch_revert_scan', function () {
    check_ajax_referer('affiros_cat_eyecatch_nonce', 'nonce');
    if (!current_user_can('manage_options')) wp_send_json_error('権限がありません');
    @set_time_limit(180);

    $ids = get_posts([
        'post_type'              => 'any',
        'post_status'            => affiros_cat_eyecatch_target_statuses(),
        'posts_per_page'         => -1,
        'fields'                 => 'ids',
        'no_found_rows'          => true,
        'ignore_sticky_posts'    => true,
        'update_post_term_cache' => false,
        'meta_query'             => [[
            'key'     => AFFIROS_CAT_EYECATCH_APPLIED_META,
            'compare' => 'EXISTS',
        ]],
    ]);

    wp_send_json_success(['ids' => array_map('intval', $ids), 'count' => count($ids)]);
});

add_action('wp_ajax_affiros_cat_eyecatch_revert', function () {
    check_ajax_referer('affiros_cat_eyecatch_nonce', 'nonce');
    if (!current_user_can('manage_options')) wp_send_json_error('権限がありません');
    @set_time_limit(180);

    $ids = isset($_POST['ids']) ? (array)$_POST['ids'] : [];
    $ids = array_slice(array_filter(array_map('intval', $ids)), 0, 200);
    if (!$ids) wp_send_json_error('対象がありません');

    $done = 0;
    $kept = 0;

    foreach ($ids as $id) {
        $applied = (int)get_post_meta($id, AFFIROS_CAT_EYECATCH_APPLIED_META, true);
        if (!$applied) continue;

        $current = (int)get_post_meta($id, '_thumbnail_id', true);
        if ($current === $applied) {
            delete_post_thumbnail($id);
            $done++;
        } else {
            // 後から手で差し替えられた記事。アイキャッチはそのまま残す
            $kept++;
        }
        delete_post_meta($id, AFFIROS_CAT_EYECATCH_APPLIED_META);
    }

    wp_send_json_success(['done' => $done, 'kept' => $kept]);
});
