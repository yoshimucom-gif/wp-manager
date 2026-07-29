<?php
/**
 * フロント側の仮想適用。
 *
 * `_thumbnail_id` の取得に割り込み、記事にアイキャッチが無いときだけ
 * カテゴリーの画像IDを返す。DBには一切書き込まない。
 *
 * この1フックで has_post_thumbnail() / the_post_thumbnail() /
 * get_the_post_thumbnail_url() / 各SEOプラグインのOGP がまとめて追随する。
 *
 * 管理画面・REST は対象外。ブロックエディタは REST 経由で featured_media を
 * 読むため、そこで仮想値を返すと「記事を保存しただけで実アイキャッチが
 * 焼き付く」事故になる。実体として持たせたい場合は設定画面の一括適用を使う。
 */

if (!defined('ABSPATH')) exit;

add_filter('get_post_metadata', 'affiros_cat_eyecatch_filter_thumbnail_id', 10, 4);

function affiros_cat_eyecatch_filter_thumbnail_id($value, $object_id, $meta_key, $single) {
    static $busy = false;

    if ($meta_key !== '_thumbnail_id') return $value;
    if ($busy) return $value;
    if (!affiros_cat_eyecatch_fallback_active()) return $value;

    $busy = true;
    try {
        $existing = get_post_meta($object_id, '_thumbnail_id', true);
        if (!empty($existing)) return $value;  // 実アイキャッチが最優先

        $image_id = affiros_cat_eyecatch_cached_resolve($object_id);
        if (!$image_id) return $value;

        return $single ? (string)$image_id : [(string)$image_id];
    } finally {
        $busy = false;
    }
}

/**
 * 同一リクエスト内で同じ記事を何度も解決しない（アーカイブは1記事あたり複数回引かれる）。
 */
function affiros_cat_eyecatch_cached_resolve($post_id) {
    static $cache = [];
    $post_id = (int)$post_id;
    if (!isset($cache[$post_id])) {
        $cache[$post_id] = affiros_cat_eyecatch_resolve_for_post($post_id);
    }
    return $cache[$post_id];
}

/**
 * 仮想適用してよいリクエストか。
 *
 * admin-ajax.php は is_admin() が true になるため、ここで一緒に除外される。
 * 無限スクロール等でフロントの admin-ajax にも効かせたい場合は
 * `affiros_cat_eyecatch_enable_fallback` フィルタで true を返す。
 */
function affiros_cat_eyecatch_fallback_active() {
    $s = affiros_cat_eyecatch_settings();
    $active = !empty($s['enabled']);

    if ($active) {
        if (is_admin()) $active = false;
        elseif (defined('REST_REQUEST') && REST_REQUEST) $active = false;
        elseif (defined('WP_CLI') && WP_CLI) $active = false;
    }

    return (bool)apply_filters('affiros_cat_eyecatch_enable_fallback', $active);
}
