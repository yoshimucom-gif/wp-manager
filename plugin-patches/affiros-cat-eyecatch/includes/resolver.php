<?php
/**
 * 「この記事に使うカテゴリーアイキャッチはどれか」を決める中核ロジック。
 *
 * フロントの仮想適用（fallback.php）と一括書き込み（bulk-tool.php）の
 * 両方がここを呼ぶ。判定ロジックの正本はこのファイル1本に限る。
 *
 * 優先順位:
 *   1. 記事に実アイキャッチがあれば、そもそも呼ばれない（呼び側が判定）
 *   2. 主要カテゴリー（Yoast / Rank Math の primary term）
 *   3. 残りのカテゴリーを term_id 昇順で見て、最初に画像を持つもの
 *   4. inherit_parent が有効なら、画像のないタームは親を遡って継承
 *   5. どれも無ければ全体デフォルト画像
 */

if (!defined('ABSPATH')) exit;

/**
 * 記事IDから使用すべき添付ファイルIDを返す（無ければ 0）。
 */
function affiros_cat_eyecatch_resolve_for_post($post_id) {
    $post_id = (int)$post_id;
    $post = get_post($post_id);
    if (!$post) return 0;

    $s = affiros_cat_eyecatch_settings();
    if (!in_array($post->post_type, affiros_cat_eyecatch_enabled_post_types(), true)) return 0;

    foreach (affiros_cat_eyecatch_enabled_taxonomies() as $tax) {
        $terms = get_the_terms($post_id, $tax);
        if (is_wp_error($terms) || empty($terms)) continue;

        foreach (affiros_cat_eyecatch_order_terms($terms, $post_id, $tax) as $term) {
            $image_id = affiros_cat_eyecatch_term_image_id($term->term_id, !empty($s['inherit_parent']));
            if ($image_id) return $image_id;
        }
    }

    $default = (int)$s['default_image_id'];
    return affiros_cat_eyecatch_is_valid_image($default) ? $default : 0;
}

/**
 * 主要カテゴリーを先頭に、残りは term_id 昇順（毎回同じ結果になるように）。
 */
function affiros_cat_eyecatch_order_terms($terms, $post_id, $taxonomy) {
    usort($terms, function ($a, $b) {
        return $a->term_id - $b->term_id;
    });

    $primary_id = affiros_cat_eyecatch_primary_term_id($post_id, $taxonomy);
    if (!$primary_id) return $terms;

    $primary = null;
    $rest = [];
    foreach ($terms as $t) {
        if ((int)$t->term_id === $primary_id) $primary = $t;
        else $rest[] = $t;
    }
    return $primary ? array_merge([$primary], $rest) : $terms;
}

/**
 * SEOプラグインが持つ「主要カテゴリー」を拾う。無ければ 0。
 */
function affiros_cat_eyecatch_primary_term_id($post_id, $taxonomy) {
    $id = 0;

    $yoast = get_post_meta($post_id, '_yoast_wpseo_primary_' . $taxonomy, true);
    if ($yoast) $id = (int)$yoast;

    if (!$id) {
        $rank_math = get_post_meta($post_id, 'rank_math_primary_' . $taxonomy, true);
        if ($rank_math) $id = (int)$rank_math;
    }

    return (int)apply_filters('affiros_cat_eyecatch_primary_term_id', $id, $post_id, $taxonomy);
}

/**
 * ターム自身（必要なら祖先）に設定された画像IDを返す。
 */
function affiros_cat_eyecatch_term_image_id($term_id, $inherit_parent = true) {
    $term_id = (int)$term_id;
    $guard = 0;

    while ($term_id && $guard < 10) {
        $image_id = (int)get_term_meta($term_id, AFFIROS_CAT_EYECATCH_TERM_META, true);
        if ($image_id && affiros_cat_eyecatch_is_valid_image($image_id)) return $image_id;

        if (!$inherit_parent) return 0;

        $term = get_term($term_id);
        if (!$term || is_wp_error($term) || empty($term->parent)) return 0;
        $term_id = (int)$term->parent;
        $guard++;
    }
    return 0;
}

/**
 * 添付ファイルが実在する画像かを確認する（削除済みIDで壊れた <img> を出さないため）。
 */
function affiros_cat_eyecatch_is_valid_image($attachment_id) {
    $attachment_id = (int)$attachment_id;
    if ($attachment_id <= 0) return false;

    static $cache = [];
    if (isset($cache[$attachment_id])) return $cache[$attachment_id];

    $cache[$attachment_id] = (get_post_type($attachment_id) === 'attachment')
        && wp_attachment_is_image($attachment_id);
    return $cache[$attachment_id];
}
