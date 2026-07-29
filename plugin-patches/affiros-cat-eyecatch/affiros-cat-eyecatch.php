<?php
/**
 * Plugin Name: Affiros カテゴリーアイキャッチ
 * Description: カテゴリーごとにアイキャッチ画像を設定し、アイキャッチ未設定の記事に自動で適用する。既定は仮想適用（記事のDBを汚さない）。必要なら実アイキャッチとして一括書き込み／一括取り消しもできる。
 * Version: 1.0.0
 * Author: Affiros
 * License: GPL v2 or later
 */

if (!defined('ABSPATH')) exit;

define('AFFIROS_CAT_EYECATCH_VERSION', '1.0.0');
define('AFFIROS_CAT_EYECATCH_FILE', __FILE__);
define('AFFIROS_CAT_EYECATCH_DIR', plugin_dir_path(__FILE__));
define('AFFIROS_CAT_EYECATCH_URL', plugin_dir_url(__FILE__));

/** ターム（カテゴリー）側に画像IDを保存するメタキー */
define('AFFIROS_CAT_EYECATCH_TERM_META', 'affiros_cat_eyecatch_id');
/** 設定の保存先オプション名 */
define('AFFIROS_CAT_EYECATCH_OPTION', 'affiros_cat_eyecatch_settings');
/** 一括適用で書き込んだ記事に立てる目印（一括取り消し用） */
define('AFFIROS_CAT_EYECATCH_APPLIED_META', '_affiros_cat_eyecatch_applied');

// 自動更新通知（ke-ys.co.jp の配信ホストから定期チェック）
require_once __DIR__ . '/includes/plugin-updater.php';
add_action('init', function () {
    $host = defined('AFFIROS_UPDATE_HOST') ? AFFIROS_UPDATE_HOST : 'https://wp-manager.onrender.com';
    new Affiros_Plugin_Updater(__FILE__, rtrim($host, '/') . '/api/plugin-update/cat-eyecatch');
});

require_once __DIR__ . '/includes/settings.php';
require_once __DIR__ . '/includes/resolver.php';
require_once __DIR__ . '/includes/term-fields.php';
require_once __DIR__ . '/includes/fallback.php';
require_once __DIR__ . '/includes/admin-page.php';
require_once __DIR__ . '/includes/bulk-tool.php';

/**
 * 管理画面用アセット。
 * メディアアップローダーを使うのは「タームの追加/編集画面」と「本プラグインの設定画面」だけ。
 */
add_action('admin_enqueue_scripts', function ($hook) {
    $screen = function_exists('get_current_screen') ? get_current_screen() : null;
    $is_term_screen = false;
    if ($screen && in_array($hook, ['edit-tags.php', 'term.php'], true)) {
        $is_term_screen = in_array($screen->taxonomy, affiros_cat_eyecatch_enabled_taxonomies(), true);
    }
    $is_settings_screen = ($hook === 'settings_page_affiros-cat-eyecatch');
    if (!$is_term_screen && !$is_settings_screen) return;

    wp_enqueue_media();
    wp_enqueue_style(
        'affiros-cat-eyecatch-admin',
        AFFIROS_CAT_EYECATCH_URL . 'assets/admin.css',
        [],
        AFFIROS_CAT_EYECATCH_VERSION
    );
    wp_enqueue_script(
        'affiros-cat-eyecatch-admin',
        AFFIROS_CAT_EYECATCH_URL . 'assets/admin.js',
        ['jquery'],
        AFFIROS_CAT_EYECATCH_VERSION,
        true
    );
    wp_localize_script('affiros-cat-eyecatch-admin', 'AffirosCatEyecatch', [
        'ajaxUrl'     => admin_url('admin-ajax.php'),
        'nonce'       => wp_create_nonce('affiros_cat_eyecatch_nonce'),
        'frameTitle'  => 'アイキャッチ画像を選択',
        'frameButton' => 'この画像を使う',
    ]);
});

/**
 * プラグイン一覧に設定へのショートカットを出す
 */
add_filter('plugin_action_links_' . plugin_basename(__FILE__), function ($links) {
    $url = admin_url('options-general.php?page=affiros-cat-eyecatch');
    array_unshift($links, '<a href="' . esc_url($url) . '">設定</a>');
    return $links;
});
