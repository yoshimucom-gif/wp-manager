<?php
/**
 * Plugin Name: Affiros プロダクトインサーター
 * Description: AIが記事内容を解析し、Amazon・楽天市場の最適な商品アフィリエイトカードを自動挿入するプラグイン
 * Version: 1.9.3
 * Author: AI Product Inserter
 * License: GPL v2 or later
 * Text Domain: ai-product-inserter
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * 旧プラグイン (ai-product-inserter) との二重ロード防止ガード
 *
 * v1.7.3 でディレクトリ名を ai-product-inserter → affiros-product-inserter に
 * リネームしたが、旧プラグインが active なままだと AI_PI_VERSION 定数や
 * AI_PI_Inserter 等のクラス名が二重定義されて PHP Fatal で白画面になる。
 *
 * このガードで、旧プラグインが先に読まれていた場合は本プラグインの初期化を
 * スキップし、管理画面に明確なエラー通知だけ出す。
 */
if (defined('AI_PI_VERSION')) {
    add_action('admin_notices', function () {
        echo '<div class="notice notice-error"><p>'
            . '<strong>Affiros プロダクトインサーター:</strong> 旧バージョン「AIプロダクトインサーター」('
            . '<code>ai-product-inserter</code>) が有効化されたままです。'
            . 'プラグイン一覧で <strong>旧版を「停止 → 削除」</strong>してから、'
            . '本プラグイン（<code>affiros-product-inserter</code>）を有効化し直してください。'
            . '</p></div>';
    });
    return;
}

define('AI_PI_VERSION', '1.9.1');
define('AI_PI_PATH', plugin_dir_path(__FILE__));
define('AI_PI_URL', plugin_dir_url(__FILE__));

// モジュール読み込み
require_once AI_PI_PATH . 'includes/claude-api.php';
require_once AI_PI_PATH . 'includes/amazon-api.php';
require_once AI_PI_PATH . 'includes/rakuten-api.php';
require_once AI_PI_PATH . 'includes/product-selector.php';
require_once AI_PI_PATH . 'includes/card-renderer.php';
require_once AI_PI_PATH . 'includes/inserter.php';
require_once AI_PI_PATH . 'includes/post-meta.php';
require_once AI_PI_PATH . 'admin/settings.php';
require_once AI_PI_PATH . 'admin/meta-box.php';
require_once AI_PI_PATH . 'admin/bulk-process.php';
require_once AI_PI_PATH . 'admin/ajax-handler.php';
require_once AI_PI_PATH . 'admin/design-preview.php';

/**
 * 有効化時の処理
 */
register_activation_hook(__FILE__, 'ai_pi_activate');
function ai_pi_activate() {
    if (!get_option('ai_pi_settings')) {
        add_option('ai_pi_settings', [
            // API
            'claude_api_key' => '',
            'claude_model' => 'claude-sonnet-4-6',
            'amazon_access_key' => '',
            'amazon_secret_key' => '',
            'amazon_partner_tag' => '',
            'rakuten_app_id' => '',
            'rakuten_affiliate_id' => '',

            // ★ v1.2.0: 3軸構造 (方式/デザイン/位置)
            'default_insert_mode' => 'marker', // marker / marker_per_heading / auto
            'default_card_design' => 'vertical', // vertical / horizontal / ranking
            'default_position' => 'bottom', // top / before_first_h2 / after_first_h2 / before_last_h2 / after_last_h2 / bottom

            // 商品選定
            'products_per_marker' => 1,
            'ranking_count' => 3,
            'candidates_per_keyword' => 10,

            // サイト優先度
            'preferred_site' => 'both',

            // 安全機構
            'enable_24h_refresh' => 'yes',
        ]);
    } else {
        // v1.1.0からのマイグレーション: auto_top3_position → default_position
        $settings = get_option('ai_pi_settings');
        $changed = false;

        if (empty($settings['default_position']) && !empty($settings['auto_top3_position'])) {
            $settings['default_position'] = $settings['auto_top3_position'];
            $changed = true;
        } elseif (empty($settings['default_position'])) {
            $settings['default_position'] = 'bottom';
            $changed = true;
        }

        // 旧モード「auto_top3」「ranking」を「auto」にマイグレーション
        if (in_array($settings['default_insert_mode'] ?? '', ['auto_top3', 'ranking'])) {
            $settings['default_insert_mode'] = 'auto';
            $changed = true;
        }

        if ($changed) {
            update_option('ai_pi_settings', $settings);
        }
    }

    // 24時間ルール対応cron
    if (!wp_next_scheduled('ai_pi_daily_refresh')) {
        wp_schedule_event(time(), 'daily', 'ai_pi_daily_refresh');
    }
}

register_deactivation_hook(__FILE__, 'ai_pi_deactivate');
function ai_pi_deactivate() {
    wp_clear_scheduled_hook('ai_pi_daily_refresh');
}

/**
 * 管理画面メニュー
 */
add_action('admin_menu', 'ai_pi_admin_menu');
function ai_pi_admin_menu() {
    add_menu_page(
        'AI商品挿入',
        'AI商品挿入',
        'manage_options',
        'ai-product-inserter',
        'ai_pi_render_settings_page',
        'dashicons-cart',
        59
    );

    add_submenu_page(
        'ai-product-inserter',
        '設定',
        '設定',
        'manage_options',
        'ai-product-inserter',
        'ai_pi_render_settings_page'
    );

    add_submenu_page(
        'ai-product-inserter',
        'デザインプレビュー',
        '🎨 デザインプレビュー',
        'manage_options',
        'ai-product-inserter-preview',
        'ai_pi_render_preview_page'
    );

    add_submenu_page(
        'ai-product-inserter',
        '一括処理',
        '一括処理',
        'manage_options',
        'ai-product-inserter-bulk',
        'ai_pi_render_bulk_page'
    );

    add_submenu_page(
        'ai-product-inserter',
        '処理ログ',
        '処理ログ',
        'manage_options',
        'ai-product-inserter-logs',
        'ai_pi_render_logs_page'
    );
}

/**
 * 管理画面用CSS/JS
 */
add_action('admin_enqueue_scripts', 'ai_pi_admin_scripts');
function ai_pi_admin_scripts($hook) {
    if (strpos($hook, 'ai-product-inserter') === false && $hook !== 'post.php' && $hook !== 'post-new.php') {
        return;
    }

    wp_enqueue_style('ai-pi-admin', AI_PI_URL . 'assets/admin.css', [], AI_PI_VERSION);
    wp_enqueue_script('ai-pi-admin', AI_PI_URL . 'assets/admin.js', ['jquery'], AI_PI_VERSION, true);

    wp_localize_script('ai-pi-admin', 'aiPI', [
        'ajaxUrl' => admin_url('admin-ajax.php'),
        'nonce' => wp_create_nonce('ai_pi_nonce'),
    ]);
}

/**
 * フロント用CSS
 */
add_action('wp_enqueue_scripts', 'ai_pi_frontend_scripts');
function ai_pi_frontend_scripts() {
    wp_enqueue_style('ai-pi-frontend', AI_PI_URL . 'assets/frontend.css', [], AI_PI_VERSION);
}

/**
 * ログページ
 */
function ai_pi_render_logs_page() {
    require_once AI_PI_PATH . 'admin/logs.php';
    ai_pi_logs_render();
}

/**
 * 日次リフレッシュ（24時間ルール対応）
 */
add_action('ai_pi_daily_refresh', 'ai_pi_do_daily_refresh');
function ai_pi_do_daily_refresh() {
    $settings = get_option('ai_pi_settings', []);
    if (($settings['enable_24h_refresh'] ?? 'yes') !== 'yes') return;

    AI_PI_Post_Meta::mark_expired_products();
}
