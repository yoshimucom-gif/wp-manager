<?php
/**
 * 記事・固定ページごとの「noindex / nofollow」設定が本番に出ない問題を直す
 *
 * ── 原因（2026-10-15 実測）
 * re:Diver の編集画面の SEO 設定は投稿メタ diver_single_seo に
 *   {valid:"1", robots:{noindex:"1", nofollow:""}, ...}
 * の形で保存される。ところが catalog-kurabe.com /contact/ でこれを入れても
 * <meta name="robots"> は max-image-preview:large のままで、noindex が出なかった
 * （2026-10-02 にも同じサイトで同じ症状を確認済み）。
 *
 * ── 直し方
 * WP標準の wp_robots に、保存されている設定どおりの noindex / nofollow を足す。
 * valid（「このページのSEO設定を使う」）が立っていないページには何もしない。
 * 値は "1" "on" "true" と true だけを真とみなす（文字列 "false" を真にしない）。
 *
 * 止めたいときは functions.php 等で:
 *   add_filter('rdh_single_robots_fix', '__return_false');
 */

if (!defined('ABSPATH')) exit;

function rdh_flag_on($v) {
    if ($v === true) return true;
    return in_array(strtolower(trim((string) $v)), ['1', 'on', 'true', 'yes'], true);
}

add_filter('wp_robots', function ($robots) {
    if (!is_singular() || !apply_filters('rdh_single_robots_fix', true)) return $robots;

    $seo = get_post_meta(get_queried_object_id(), 'diver_single_seo', true);
    if (!is_array($seo) || !rdh_flag_on($seo['valid'] ?? '')) return $robots;

    $r = isset($seo['robots']) && is_array($seo['robots']) ? $seo['robots'] : [];
    if (rdh_flag_on($r['noindex'] ?? '')) {
        $robots['noindex'] = true;
        unset($robots['index'], $robots['max-image-preview']);
        if (!rdh_flag_on($r['nofollow'] ?? '')) $robots['follow'] = true;
    }
    if (rdh_flag_on($r['nofollow'] ?? '')) {
        $robots['nofollow'] = true;
        unset($robots['follow']);
    }
    return $robots;
}, 999);

/**
 * noindex にしたページを WP標準のサイトマップ（wp-sitemap.xml）から外す（1.4.1）
 * noindex のURLをサイトマップで送ると、Search Console が「noindex タグによって除外されました」の
 * 送信エラーとして数える。上と同じ判定（valid が立っていて robots.noindex が真）で外す。
 */
function rdh_noindex_post_ids() {
    static $ids = null;
    if ($ids !== null) return $ids;
    $ids = [];
    $cand = get_posts([
        'post_type'      => 'any',
        'post_status'    => 'publish',
        'posts_per_page' => 500,
        'fields'         => 'ids',
        'no_found_rows'  => true,
        // 全記事が diver_single_seo を持つので、noindex が真の形（"1" "on" "true" true）だけを拾う
        'meta_query'     => [
            'relation' => 'OR',
            ['key' => 'diver_single_seo', 'value' => 's:7:"noindex";s:1:"1"', 'compare' => 'LIKE'],
            ['key' => 'diver_single_seo', 'value' => 's:7:"noindex";s:2:"on"', 'compare' => 'LIKE'],
            ['key' => 'diver_single_seo', 'value' => 's:7:"noindex";s:4:"true"', 'compare' => 'LIKE'],
            ['key' => 'diver_single_seo', 'value' => 's:7:"noindex";b:1', 'compare' => 'LIKE'],
        ],
    ]);
    foreach ($cand as $id) {
        $seo = get_post_meta($id, 'diver_single_seo', true);
        if (is_array($seo) && rdh_flag_on($seo['valid'] ?? '') && rdh_flag_on($seo['robots']['noindex'] ?? '')) {
            $ids[] = (int) $id;
        }
    }
    return $ids;
}

add_filter('wp_sitemaps_posts_query_args', function ($args) {
    if (!apply_filters('rdh_single_robots_fix', true)) return $args;
    $ids = rdh_noindex_post_ids();
    if ($ids) {
        $args['post__not_in'] = array_values(array_unique(array_merge((array) ($args['post__not_in'] ?? []), $ids)));
    }
    return $args;
});
