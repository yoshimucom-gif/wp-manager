<?php
/**
 * 設定の読み書き。
 * 保存先は単一オプション（配列）。他ファイルはここ経由でしか設定を触らない。
 */

if (!defined('ABSPATH')) exit;

function affiros_cat_eyecatch_default_settings() {
    return [
        'enabled'          => 1,   // フロントでの自動適用
        'post_types'       => ['post'],
        'taxonomies'       => ['category'],
        'inherit_parent'   => 1,   // 子カテゴリーに画像がなければ親を遡る
        'default_image_id' => 0,   // どのカテゴリーにも画像がない場合の最終フォールバック
    ];
}

function affiros_cat_eyecatch_settings() {
    static $cache = null;
    if ($cache !== null) return $cache;
    $saved = get_option(AFFIROS_CAT_EYECATCH_OPTION, []);
    if (!is_array($saved)) $saved = [];
    $cache = affiros_cat_eyecatch_sanitize_settings(array_merge(affiros_cat_eyecatch_default_settings(), $saved));
    return $cache;
}

function affiros_cat_eyecatch_save_settings($raw) {
    $clean = affiros_cat_eyecatch_sanitize_settings($raw);
    update_option(AFFIROS_CAT_EYECATCH_OPTION, $clean);
    return $clean;
}

function affiros_cat_eyecatch_sanitize_settings($raw) {
    $d = affiros_cat_eyecatch_default_settings();

    $post_types = isset($raw['post_types']) && is_array($raw['post_types'])
        ? array_values(array_filter(array_map('sanitize_key', $raw['post_types'])))
        : $d['post_types'];
    $taxonomies = isset($raw['taxonomies']) && is_array($raw['taxonomies'])
        ? array_values(array_filter(array_map('sanitize_key', $raw['taxonomies'])))
        : $d['taxonomies'];

    // 全部外されると何も起きない設定になり事故に見えるので、既定に戻す
    if (!$post_types) $post_types = $d['post_types'];
    if (!$taxonomies) $taxonomies = $d['taxonomies'];

    return [
        'enabled'          => !empty($raw['enabled']) ? 1 : 0,
        'post_types'       => $post_types,
        'taxonomies'       => $taxonomies,
        'inherit_parent'   => !empty($raw['inherit_parent']) ? 1 : 0,
        'default_image_id' => isset($raw['default_image_id']) ? max(0, (int)$raw['default_image_id']) : 0,
    ];
}

/** 画像フィールドを出すタクソノミー（存在しないものは除外） */
function affiros_cat_eyecatch_enabled_taxonomies() {
    $s = affiros_cat_eyecatch_settings();
    $out = [];
    foreach ($s['taxonomies'] as $tax) {
        if (taxonomy_exists($tax)) $out[] = $tax;
    }
    return $out;
}

/** 自動適用の対象になる投稿タイプ */
function affiros_cat_eyecatch_enabled_post_types() {
    $s = affiros_cat_eyecatch_settings();
    $out = [];
    foreach ($s['post_types'] as $pt) {
        if (post_type_exists($pt)) $out[] = $pt;
    }
    return $out;
}

/** 設定画面のチェックボックスに出す投稿タイプ候補 */
function affiros_cat_eyecatch_selectable_post_types() {
    $out = [];
    foreach (get_post_types(['public' => true], 'objects') as $pt) {
        if ($pt->name === 'attachment') continue;
        if (!post_type_supports($pt->name, 'thumbnail')) continue;
        $out[$pt->name] = $pt->labels->singular_name ?: $pt->name;
    }
    return $out;
}

/** 設定画面のチェックボックスに出すタクソノミー候補 */
function affiros_cat_eyecatch_selectable_taxonomies() {
    $out = [];
    foreach (get_taxonomies(['public' => true], 'objects') as $tax) {
        if ($tax->name === 'post_format') continue;
        $out[$tax->name] = $tax->labels->singular_name ?: $tax->name;
    }
    return $out;
}
