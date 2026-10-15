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
