<?php
/**
 * Plugin Name: Affiros デコレーター
 * Description: Claude APIでAI生成記事をDBPテーマのGutenbergブロックで自動装飾するプラグイン
 * Version: 1.2.0
 * Author: AI Decoration
 * License: GPL v2 or later
 * Text Domain: ai-decoration
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * 旧プラグイン (ai-decoration) との二重ロード防止ガード
 *
 * v1.2.0 で配布物のディレクトリ構造を改めて affiros-decoration/ サブディレクトリに
 * 統一した。旧バージョン (zip直下展開 / ディレクトリ名 ai-decoration 等) が
 * active なままだと AI_DECO_VERSION 定数や AI_Deco_* クラスが二重定義されて
 * PHP Fatal で白画面になる。
 */
if (defined('AI_DECO_VERSION')) {
    add_action('admin_notices', function () {
        echo '<div class="notice notice-error"><p>'
            . '<strong>Affiros デコレーター:</strong> 旧バージョン「AIデコレーション」が'
            . '有効化されたままです。プラグイン一覧で <strong>旧版を「停止 → 削除」</strong>してから、'
            . '本プラグイン（<code>affiros-decoration</code>）を有効化し直してください。'
            . '</p></div>';
    });
    return;
}

define('AI_DECO_VERSION', '1.2.0');
define('AI_DECO_PATH', plugin_dir_path(__FILE__));
define('AI_DECO_URL', plugin_dir_url(__FILE__));

require_once AI_DECO_PATH . 'includes/claude-api.php';
require_once AI_DECO_PATH . 'includes/validator.php';
require_once AI_DECO_PATH . 'includes/decorator.php';
require_once AI_DECO_PATH . 'includes/post-meta.php';
require_once AI_DECO_PATH . 'admin/settings.php';
require_once AI_DECO_PATH . 'admin/meta-box.php';
require_once AI_DECO_PATH . 'admin/bulk-process.php';
require_once AI_DECO_PATH . 'admin/ajax-handler.php';

/**
 * 使用可能なモデル一覧（品質ラベル・目安単価込み）
 * cost_yen は標準的な3,000字記事・装飾レベル「標準」での1記事あたり試算（1USD=155円）
 */
function ai_deco_get_models() {
    return [
        'claude-haiku-4-5-20251001' => [
            'label' => '標準品質（Haiku 4.5）',
            'cost_yen' => 6,
            'description' => 'コスト重視。ブロックJSONでミスりやすいのでリトライ前提',
        ],
        'claude-sonnet-4-6' => [
            'label' => '高品質（Sonnet 4.6・推奨）',
            'cost_yen' => 19,
            'description' => 'バランス重視の推奨モデル',
        ],
        'claude-opus-4-7' => [
            'label' => '最高品質（Opus 4.7・最新）',
            'cost_yen' => 32,
            'description' => '最新フラッグシップ',
        ],
        'claude-opus-4-6' => [
            'label' => '最高品質（Opus 4.6）',
            'cost_yen' => 32,
            'description' => '旧フラッグシップ。互換性維持用',
        ],
    ];
}

function ai_deco_get_cost_per_post($model) {
    $models = ai_deco_get_models();
    return $models[$model]['cost_yen'] ?? 19;
}

function ai_deco_get_model_label($model) {
    $models = ai_deco_get_models();
    return $models[$model]['label'] ?? $model;
}

register_activation_hook(__FILE__, 'ai_deco_activate');
function ai_deco_activate() {
    if (!get_option('ai_deco_settings')) {
        add_option('ai_deco_settings', [
            'api_key' => '',
            'model' => 'claude-sonnet-4-6',
            'decoration_level' => 'standard',
            'enable_faq' => 'no',
            'auto_decorate_on_save' => 'no',
        ]);
    }
}

add_action('admin_menu', 'ai_deco_admin_menu');
function ai_deco_admin_menu() {
    add_menu_page('AIデコレーション', 'AIデコレーション', 'manage_options', 'ai-decoration', 'ai_deco_render_settings_page', 'dashicons-art', 58);
    add_submenu_page('ai-decoration', '設定', '設定', 'manage_options', 'ai-decoration', 'ai_deco_render_settings_page');
    add_submenu_page('ai-decoration', '一括処理', '一括処理', 'manage_options', 'ai-deco-bulk', 'ai_deco_render_bulk_page');
    add_submenu_page('ai-decoration', '処理ログ', '処理ログ', 'manage_options', 'ai-deco-logs', 'ai_deco_render_logs_page');
}

add_action('admin_enqueue_scripts', 'ai_deco_admin_scripts');
function ai_deco_admin_scripts($hook) {
    if (strpos($hook, 'ai-decoration') === false
        && strpos($hook, 'ai-deco-') === false
        && $hook !== 'post.php'
        && $hook !== 'post-new.php') {
        return;
    }

    wp_enqueue_style('ai-deco-admin', AI_DECO_URL . 'assets/admin.css', [], AI_DECO_VERSION);
    wp_enqueue_script('ai-deco-admin', AI_DECO_URL . 'assets/admin.js', ['jquery'], AI_DECO_VERSION, true);

    wp_localize_script('ai-deco-admin', 'aiDeco', [
        'ajaxUrl' => admin_url('admin-ajax.php'),
        'nonce' => wp_create_nonce('ai_deco_nonce'),
        'models' => ai_deco_get_models(),
    ]);
}

function ai_deco_render_logs_page() {
    require_once AI_DECO_PATH . 'admin/logs.php';
    ai_deco_logs_render();
}

/**
 * 投稿保存時の自動装飾フック
 */
add_action('save_post_post', 'ai_deco_maybe_auto_decorate', 30, 3);
add_action('save_post_page', 'ai_deco_maybe_auto_decorate', 30, 3);
function ai_deco_maybe_auto_decorate($post_id, $post, $update) {
    $settings = get_option('ai_deco_settings', []);
    if (($settings['auto_decorate_on_save'] ?? 'no') !== 'yes') return;

    if (wp_is_post_revision($post_id)) return;
    if (wp_is_post_autosave($post_id)) return;
    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
    if (defined('DOING_AJAX') && DOING_AJAX) return;
    if (defined('REST_REQUEST') && REST_REQUEST) return;
    if (defined('DOING_CRON') && DOING_CRON) return;

    if ($post->post_status !== 'publish') return;
    if (get_transient('ai_deco_processing_' . $post_id)) return;
    if (AI_Deco_Post_Meta::is_decorated($post_id)) return;
    if (AI_Deco_Post_Meta::is_excluded($post_id)) return;
    if (empty(trim($post->post_content))) return;

    AI_Deco_Decorator::decorate_post($post_id, ['dry_run' => false]);
}
