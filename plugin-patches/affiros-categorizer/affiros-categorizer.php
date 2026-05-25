<?php
/**
 * Plugin Name: Affiros カテゴライザー
 * Description: 記事の公開時に Claude API で本文を解析し、そのサイトの既存カテゴリーへ自動で振り分ける。カテゴリー一覧はサイトから動的に読むため、どの WordPress サイトでもそのまま動作する。
 * Version: 0.1.0
 * Author: Affiros
 * License: GPL v2 or later
 * Text Domain: affiros-categorizer
 */

if (!defined('ABSPATH')) {
    exit;
}

define('AFFIROS_CAT_VERSION', '0.1.0');
define('AFFIROS_CAT_PATH', plugin_dir_path(__FILE__));
define('AFFIROS_CAT_URL', plugin_dir_url(__FILE__));

// オプションキー
define('AFFIROS_CAT_OPTION_KEY', 'affiros_categorizer_settings');

// モジュール読み込み
require_once AFFIROS_CAT_PATH . 'includes/claude-api.php';
require_once AFFIROS_CAT_PATH . 'includes/classifier.php';
require_once AFFIROS_CAT_PATH . 'admin/settings-page.php';
require_once AFFIROS_CAT_PATH . 'admin/classify-page.php';
require_once AFFIROS_CAT_PATH . 'admin/meta-box.php';
require_once AFFIROS_CAT_PATH . 'admin/ajax-handler.php';

/**
 * デフォルト設定
 */
function affiros_cat_default_settings() {
    return [
        'claude_api_key'  => '',
        'claude_model'    => 'claude-haiku-4-5-20251001',
        'site_context'    => '',
        'auto_on_publish' => 1,        // 1 = 公開時に自動分類
        'overwrite'       => 'empty',  // empty = 未分類のときだけ / always = 常に上書き
    ];
}

/**
 * 旧モデルID → 現行モデルID のマイグレーションマップ
 * （affiros-rewrite と同じ方針。リタイア済みモデルIDで失敗するのを防ぐ）
 */
function affiros_cat_migrate_model_id($model) {
    $map = [
        'claude-sonnet-4-5-20250929' => 'claude-sonnet-4-6',
        'claude-sonnet-4-5'          => 'claude-sonnet-4-6',
        'claude-opus-4-1-20250805'   => 'claude-opus-4-7',
        'claude-opus-4-1'            => 'claude-opus-4-7',
        'claude-3-5-haiku-20241022'  => 'claude-haiku-4-5-20251001',
        'claude-3-5-haiku'           => 'claude-haiku-4-5-20251001',
        'claude-haiku-4-5'           => 'claude-haiku-4-5-20251001',
    ];
    return isset($map[$model]) ? $map[$model] : $model;
}

/**
 * 設定取得
 */
function affiros_cat_get_settings() {
    $saved = get_option(AFFIROS_CAT_OPTION_KEY, []);
    $settings = array_merge(affiros_cat_default_settings(), is_array($saved) ? $saved : []);
    $settings['claude_model'] = affiros_cat_migrate_model_id($settings['claude_model'] ?? '');

    // wp-config.php に AFFIROS_CATEGORIZER_API_KEY を定義していれば最優先で使う。
    // wp-config.php はプラグインの更新・再インストール・削除で変更されないため、
    // この方式なら API キーが消えることはない（affiros-rewrite と同じ方針）。
    if (defined('AFFIROS_CATEGORIZER_API_KEY') && AFFIROS_CATEGORIZER_API_KEY) {
        $settings['claude_api_key'] = AFFIROS_CATEGORIZER_API_KEY;
    }
    return $settings;
}

/**
 * 管理メニュー登録
 */
add_action('admin_menu', function () {
    add_menu_page(
        'Affiros カテゴライザー',
        'Affiros カテゴライザー',
        'manage_options',
        'affiros-categorizer',
        'affiros_cat_render_classify_page',
        'dashicons-category',
        77
    );
    add_submenu_page(
        'affiros-categorizer',
        '一括分類',
        '一括分類',
        'manage_options',
        'affiros-categorizer',
        'affiros_cat_render_classify_page'
    );
    add_submenu_page(
        'affiros-categorizer',
        '設定',
        '設定',
        'manage_options',
        'affiros-categorizer-settings',
        'affiros_cat_render_settings_page'
    );
});

/**
 * 管理画面用 CSS / JS
 * - プラグインの管理ページ（一括分類・設定）と、投稿編集画面で読み込む
 */
add_action('admin_enqueue_scripts', function ($hook) {
    $is_plugin_page = strpos($hook, 'affiros-categorizer') !== false;
    $is_post_editor = in_array($hook, ['post.php', 'post-new.php'], true);
    if (!$is_plugin_page && !$is_post_editor) {
        return;
    }
    wp_enqueue_style(
        'affiros-cat-admin',
        AFFIROS_CAT_URL . 'assets/admin.css',
        [],
        AFFIROS_CAT_VERSION
    );
    wp_enqueue_script(
        'affiros-cat-admin',
        AFFIROS_CAT_URL . 'assets/admin.js',
        ['jquery'],
        AFFIROS_CAT_VERSION,
        true
    );
    wp_localize_script('affiros-cat-admin', 'AffirosCat', [
        'ajaxUrl' => admin_url('admin-ajax.php'),
        'nonce'   => wp_create_nonce('affiros_categorizer_nonce'),
    ]);
});

/**
 * 自動分類トリガー
 *
 * 投稿が「公開」状態に遷移したときに一度だけ分類する。
 * transition_post_status は新規作成（直接公開）・下書き→公開のどちらでも発火し、
 * かつ wp_insert_post アクションより先に走る順序問題の影響を受けない。
 * 重複実行は分類ログメタ（_affiros_cat_log）の有無で防ぐ。
 *
 * 公開リクエストをブロックしないよう、実処理は WP-Cron の単発イベントに逃がす。
 */
add_action('transition_post_status', function ($new_status, $old_status, $post) {
    if ($new_status !== 'publish' || $post->post_type !== 'post') {
        return;
    }
    // すでに分類済み（手動・自動を問わず）の記事は対象外
    if (get_post_meta($post->ID, '_affiros_cat_log', true)) {
        return;
    }
    $settings = affiros_cat_get_settings();
    if (empty($settings['auto_on_publish'])) {
        return;
    }
    $post_id = (int) $post->ID;
    if (!wp_next_scheduled('affiros_cat_classify_event', [$post_id])) {
        wp_schedule_single_event(time() + 1, 'affiros_cat_classify_event', [$post_id]);
    }
}, 10, 3);

add_action('affiros_cat_classify_event', function ($post_id) {
    Affiros_Cat_Classifier::classify((int) $post_id, false);
}, 10, 1);

/**
 * 有効化フック
 */
register_activation_hook(__FILE__, function () {
    if (!get_option(AFFIROS_CAT_OPTION_KEY)) {
        update_option(AFFIROS_CAT_OPTION_KEY, affiros_cat_default_settings());
    }
});
