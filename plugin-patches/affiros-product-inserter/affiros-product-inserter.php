<?php
/**
 * Plugin Name: Affiros プロダクトインサーター
 * Description: AIが記事内容を解析し、Amazon・楽天市場の最適な商品アフィリエイトカードを自動挿入するプラグイン
 * Version: 1.9.28
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

define('AI_PI_VERSION', '1.9.28');
define('AI_PI_PATH', plugin_dir_path(__FILE__));
define('AI_PI_URL', plugin_dir_url(__FILE__));

/**
 * 1.9.11 で商品挿入のデフォルトモデルを Sonnet 4.6 → Haiku 4.5 に変更したが、
 * 既存ユーザーの wp_options には Sonnet が保存されたまま残るため、コードの
 * default 変更だけでは反映されない。
 *
 * このマイグレーションは「保存値が旧 default の Sonnet 4.6」だった人だけを
 * Haiku 4.5 に1回だけ書き換える。明示的に Opus/Haiku を選んでいる人は対象外。
 * 一度走ったら ai_pi_default_model_migrated フラグで二度走らないように記録する。
 *
 * 想定外の自動切替を避けたい場合は wp-config.php に
 *   define('AI_PI_SKIP_HAIKU_MIGRATION', true);
 * を入れるとスキップする。
 */
add_action('plugins_loaded', function () {
    if (defined('AI_PI_SKIP_HAIKU_MIGRATION') && AI_PI_SKIP_HAIKU_MIGRATION) {
        return;
    }
    if (get_option('ai_pi_default_model_migrated')) {
        return;
    }
    $settings = get_option('ai_pi_settings');
    if (is_array($settings) && ($settings['claude_model'] ?? '') === 'claude-sonnet-4-6') {
        $settings['claude_model'] = 'claude-haiku-4-5-20251001';
        update_option('ai_pi_settings', $settings);
    }
    update_option('ai_pi_default_model_migrated', '1.9.11');
}, 5);

/**
 * 確実性ガード: 公開済み記事に raw な <!--ai-product:...--> が残っていれば
 * WP 管理画面のすべてのページ上部にハッキリと警告を出す。
 *
 * 残存マーカーは読者に表示されてしまうコメントノイズで、商品挿入が
 * 失敗したまま気付かれていない致命的状態。1件でもあれば即座にユーザーが
 * 認識できるようにする。
 *
 * パフォーマンス対策: 結果を 5 分キャッシュ。クリック時のリンクで
 * 一括処理画面 (residual フィルタ) に直接遷移できる。
 */
add_action('admin_notices', function () {
    if (!current_user_can('manage_options')) return;

    $cached = get_transient('ai_pi_residual_count_publish');
    if ($cached === false) {
        if (class_exists('AI_PI_Post_Meta')) {
            $count = AI_PI_Post_Meta::count_published_with_raw_markers();
            set_transient('ai_pi_residual_count_publish', $count, 5 * MINUTE_IN_SECONDS);
            $cached = $count;
        } else {
            return;
        }
    }
    if ((int)$cached <= 0) return;

    $url = admin_url('admin.php?page=ai-product-inserter&filter=has_marker');
    printf(
        '<div class="notice notice-error" style="border-left-color:#d63638;"><p>'
        . '<strong>⚠️ AI商品挿入: 公開済み記事に商品挿入マーカーが残っています（%d 件）</strong><br>'
        . '読者にコメントノイズとして見えている可能性があります。'
        . '<a href="%s">→ 一括処理画面で再処理する</a>'
        . '</p></div>',
        (int)$cached,
        esc_url($url)
    );
});

/**
 * 投稿の保存・削除があった場合、上記キャッシュを破棄して再カウントさせる。
 * 一括処理での書き換え直後にも警告状態が即座に更新される。
 */
add_action('save_post',   function () { delete_transient('ai_pi_residual_count_publish'); });
add_action('deleted_post', function () { delete_transient('ai_pi_residual_count_publish'); });

// モジュール読み込み
require_once AI_PI_PATH . 'includes/claude-api.php';
require_once AI_PI_PATH . 'includes/amazon-api.php';
require_once AI_PI_PATH . 'includes/rakuten-api.php';
require_once AI_PI_PATH . 'includes/product-selector.php';
require_once AI_PI_PATH . 'includes/card-renderer.php';
require_once AI_PI_PATH . 'includes/inserter.php';
require_once AI_PI_PATH . 'includes/auto-insert.php';
require_once AI_PI_PATH . 'includes/post-meta.php';
require_once AI_PI_PATH . 'admin/settings.php';
require_once AI_PI_PATH . 'admin/meta-box.php';
require_once AI_PI_PATH . 'admin/bulk-process.php';
require_once AI_PI_PATH . 'admin/ajax-handler.php';
require_once AI_PI_PATH . 'admin/design-preview.php';
require_once AI_PI_PATH . 'admin/adjacent-cards.php';
require_once AI_PI_PATH . 'includes/plugin-updater.php';

/**
 * Affiros9 サーバーをアップデートサーバーとして登録。
 * これ以降の更新は WP 管理画面の「プラグイン」から「今すぐ更新」で行える。
 *
 * 別ホストで運用する場合は wp-config.php に AFFIROS_UPDATE_HOST を定義する。
 */
add_action('init', function () {
    $host = defined('AFFIROS_UPDATE_HOST') ? AFFIROS_UPDATE_HOST : 'https://wp-manager.onrender.com';
    new Affiros_Plugin_Updater(__FILE__, rtrim($host, '/') . '/api/plugin-update/product-inserter');
});

/**
 * 有効化時の処理
 */
register_activation_hook(__FILE__, 'ai_pi_activate');
function ai_pi_activate() {
    if (!get_option('ai_pi_settings')) {
        add_option('ai_pi_settings', [
            // API
            'claude_api_key' => '',
            'claude_model' => 'claude-haiku-4-5-20251001',
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
    // 自動挿入用に予約された single cron event を全部掃除
    if (function_exists('ai_pi_clear_auto_insert_crons')) {
        ai_pi_clear_auto_insert_crons();
    }
}

/**
 * 管理画面メニュー
 */
add_action('admin_menu', 'ai_pi_admin_menu');
function ai_pi_admin_menu() {
    // 親メニュー = 一括処理（最初に開くページ）
    add_menu_page(
        'AI商品挿入',
        'AI商品挿入',
        'manage_options',
        'ai-product-inserter',
        'ai_pi_render_bulk_page',
        'dashicons-cart',
        59
    );

    // サブメニューの並び: 一括処理 → 設定 → デザインプレビュー → 処理ログ
    add_submenu_page(
        'ai-product-inserter',
        '一括処理',
        '一括処理',
        'manage_options',
        'ai-product-inserter',     // 親と同じスラッグ = デフォルト表示
        'ai_pi_render_bulk_page'
    );

    add_submenu_page(
        'ai-product-inserter',
        '設定',
        '設定',
        'manage_options',
        'ai-product-inserter-settings',
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
        '連続カード／マーカー検出',
        '🔍 連続カード／マーカー検出',
        'manage_options',
        'ai-product-inserter-adjacent',
        'ai_pi_render_adjacent_cards_page'
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
