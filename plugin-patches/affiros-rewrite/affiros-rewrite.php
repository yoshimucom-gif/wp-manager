<?php
/**
 * Plugin Name: Affiros リライター
 * Description: WordPress記事をClaude APIでリライトする。WP_Queryで内部処理するためホスティングWAFの影響を受けない（403回避）。
 * Version: 0.3.1
 * Author: Affiros
 * License: GPL v2 or later
 * Text Domain: affiros-rewrite
 */

if (!defined('ABSPATH')) {
    exit;
}

define('AFFIROS_REWRITE_VERSION', '0.3.1');
define('AFFIROS_REWRITE_PATH', plugin_dir_path(__FILE__));
define('AFFIROS_REWRITE_URL', plugin_dir_url(__FILE__));

// オプションキー
define('AFFIROS_REWRITE_OPTION_KEY', 'affiros_rewrite_settings');

// モジュール読み込み
require_once AFFIROS_REWRITE_PATH . 'includes/claude-api.php';
require_once AFFIROS_REWRITE_PATH . 'includes/post-fetcher.php';
require_once AFFIROS_REWRITE_PATH . 'includes/quality-presets.php';
require_once AFFIROS_REWRITE_PATH . 'includes/marker-inserter.php';
require_once AFFIROS_REWRITE_PATH . 'includes/rewrite-engine.php';
require_once AFFIROS_REWRITE_PATH . 'admin/settings-page.php';
require_once AFFIROS_REWRITE_PATH . 'admin/presets-page.php';
require_once AFFIROS_REWRITE_PATH . 'admin/rewrite-page.php';
require_once AFFIROS_REWRITE_PATH . 'admin/ajax-handler.php';

/**
 * デフォルト設定
 */
function affiros_rewrite_default_settings() {
    return [
        'claude_api_key' => '',
        'claude_model' => 'claude-sonnet-4-6',
        'rewrite_mode' => 'seo',          // seo / readability / freshness
        'emphasis_level' => 'standard',   // light / standard / strong
        'tone' => 'natural',              // natural / professional / casual
        'target_chars' => 0,              // 0 = 元記事に合わせる
        'tolerance_percent' => 10,
    ];
}

/**
 * 旧モデルID → 現行モデルID のマイグレーションマップ
 *
 * v0.3.0 以前を入れていた環境は DB に旧モデルIDが保存されたままになる。
 * 旧IDのまま API を叩くとリタイア済みモデルで失敗するため、設定読み込み時に
 * 現行IDへ寄せる。
 */
function affiros_rewrite_migrate_model_id($model) {
    $map = [
        'claude-sonnet-4-5-20250929' => 'claude-sonnet-4-6',
        'claude-sonnet-4-5'          => 'claude-sonnet-4-6',
        'claude-opus-4-1-20250805'   => 'claude-opus-4-7',
        'claude-opus-4-1'            => 'claude-opus-4-7',
        'claude-3-5-haiku-20241022'  => 'claude-haiku-4-5',
        'claude-3-5-haiku'           => 'claude-haiku-4-5',
    ];
    return $map[$model] ?? $model;
}

/**
 * 設定取得
 */
function affiros_rewrite_get_settings() {
    $saved = get_option(AFFIROS_REWRITE_OPTION_KEY, []);
    $settings = array_merge(affiros_rewrite_default_settings(), is_array($saved) ? $saved : []);
    $settings['claude_model'] = affiros_rewrite_migrate_model_id($settings['claude_model'] ?? '');
    return $settings;
}

/**
 * 管理メニュー登録
 */
add_action('admin_menu', function () {
    add_menu_page(
        'Affiros リライト',
        'Affiros リライト',
        'manage_options',
        'affiros-rewrite',
        'affiros_rewrite_render_rewrite_page',
        'dashicons-edit',
        76
    );
    add_submenu_page(
        'affiros-rewrite',
        'リライト実行',
        'リライト実行',
        'manage_options',
        'affiros-rewrite',
        'affiros_rewrite_render_rewrite_page'
    );
    add_submenu_page(
        'affiros-rewrite',
        '品質プリセット',
        '品質プリセット',
        'manage_options',
        'affiros-rewrite-presets',
        'affiros_rewrite_render_presets_page'
    );
    add_submenu_page(
        'affiros-rewrite',
        '設定',
        '設定',
        'manage_options',
        'affiros-rewrite-settings',
        'affiros_rewrite_render_settings_page'
    );
});

/**
 * 管理画面用 CSS/JS
 */
add_action('admin_enqueue_scripts', function ($hook) {
    if (strpos($hook, 'affiros-rewrite') === false) {
        return;
    }
    wp_enqueue_style(
        'affiros-rewrite-admin',
        AFFIROS_REWRITE_URL . 'assets/admin.css',
        [],
        AFFIROS_REWRITE_VERSION
    );
    wp_enqueue_script(
        'affiros-rewrite-admin',
        AFFIROS_REWRITE_URL . 'assets/admin.js',
        ['jquery'],
        AFFIROS_REWRITE_VERSION,
        true
    );
    wp_localize_script('affiros-rewrite-admin', 'AffirosRewrite', [
        'ajaxUrl' => admin_url('admin-ajax.php'),
        'nonce' => wp_create_nonce('affiros_rewrite_nonce'),
    ]);
});

/**
 * 有効化フック
 */
register_activation_hook(__FILE__, function () {
    if (!get_option(AFFIROS_REWRITE_OPTION_KEY)) {
        update_option(AFFIROS_REWRITE_OPTION_KEY, affiros_rewrite_default_settings());
    }
});
