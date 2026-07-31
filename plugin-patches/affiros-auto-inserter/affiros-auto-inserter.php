<?php
/**
 * Plugin Name: Affiros オートインサーター
 * Description: マーカー不要。Claude Haiku が本文から検索キーワードを自動抽出し、Amazon + 楽天から関連商品3件を引っ張って「最初のH2直前」「まとめ直後」の2箇所に比較カードを自動挿入する。ランキング記事は自動判定して除外。既存の affiros-product-inserter とは独立して動作。
 * Version: 0.13.1
 * Author: Affiros
 * License: GPL v2 or later
 * Text Domain: affiros-auto-inserter
 */

if (!defined('ABSPATH')) exit;

define('AFFIROS_AI_VERSION',      '0.13.1');
define('AFFIROS_AI_PATH',         plugin_dir_path(__FILE__));
define('AFFIROS_AI_URL',          plugin_dir_url(__FILE__));
define('AFFIROS_AI_OPTION_KEY',   'affiros_ai_settings');

// 記事メタキー
define('AFFIROS_AI_META_EXCLUDED',       '_affiros_ai_excluded');        // 除外フラグ(yes/no)
define('AFFIROS_AI_META_KEYWORD',        '_affiros_ai_keyword');         // 抽出キーワード
define('AFFIROS_AI_META_PRODUCTS',       '_affiros_ai_products');        // 商品データ(3件, JSON)
define('AFFIROS_AI_META_LAST_INSERT_AT', '_affiros_ai_last_insert_at');  // 最終挿入日時
define('AFFIROS_AI_META_LAST_ERROR',     '_affiros_ai_last_error');      // 最終エラー
define('AFFIROS_AI_META_INSERTED_MARKER','_affiros_ai_inserted_marker'); // 挿入済みHTMLマーカー

// モジュール読み込み
require_once AFFIROS_AI_PATH . 'includes/plugin-updater.php';
require_once AFFIROS_AI_PATH . 'includes/amazon-api.php';
require_once AFFIROS_AI_PATH . 'includes/rakuten-api.php';
require_once AFFIROS_AI_PATH . 'includes/keyword-extractor.php';
require_once AFFIROS_AI_PATH . 'includes/card-renderer.php';
require_once AFFIROS_AI_PATH . 'includes/ranking-detector.php';
require_once AFFIROS_AI_PATH . 'includes/inserter.php';
require_once AFFIROS_AI_PATH . 'includes/shortcode.php';
require_once AFFIROS_AI_PATH . 'admin/settings.php';
require_once AFFIROS_AI_PATH . 'admin/bulk-page.php';
require_once AFFIROS_AI_PATH . 'admin/metabox.php';
require_once AFFIROS_AI_PATH . 'admin/design-preview.php';

/**
 * 自動更新チェッカー登録
 */
add_action('init', function () {
    $host = defined('AFFIROS_UPDATE_HOST') ? AFFIROS_UPDATE_HOST : 'https://wp-manager.onrender.com';
    new Affiros_Plugin_Updater(__FILE__, rtrim($host, '/') . '/api/plugin-update/auto-inserter');
});

/**
 * デフォルト設定
 */
function affiros_ai_default_settings() {
    return [
        // Claude Haiku (キーワード抽出用)
        'claude_api_key' => '',
        // Amazon Creators API
        'amazon_client_id'     => '',
        'amazon_client_secret' => '',
        'amazon_partner_tag'   => '',
        'amazon_marketplace'   => 'www.amazon.co.jp',
        // 楽天
        'rakuten_app_id'       => '',
        'rakuten_access_key'   => '',
        'rakuten_affiliate_id' => '',
        // 挿入設定
        'insert_before_first_h2' => 'yes', // 最初のH2の直前
        'insert_after_matome'    => 'yes', // まとめ直後
        'products_count'         => 3,     // 表示商品数
        'target_statuses'        => 'publish,future,draft',
        // 見出し文言
        'card_heading'           => '超売れ筋のおすすめTOP3', // 記事内カード
        'side_heading'           => 'この記事のイチオシ',     // サイドバーカード ([affiros_ai_top])
        // ランキング検出
        'skip_ranking_articles'  => 'yes',
        'ranking_title_patterns' => "選\nランキング\nおすすめ.*位\nベスト\\d+",
        // 挿入しないカテゴリー・タグ
        'exclude_category_ids'   => [],  // カテゴリーID配列
        'exclude_tags'           => '',  // カンマ区切り (タグ名 or スラッグ)
        // 挿入動作
        'auto_on_publish'        => 'yes', // 公開時自動挿入
        // 月次リフレッシュ (v0.12.0): 挿入から30日経過した記事を1日10件ずつ商品更新
        // リビジョン無し・更新日保持なので週次時代 (v0.7.0で廃止) の副作用はない
        'monthly_refresh'        => 'yes',
    ];
}

function affiros_ai_get_settings() {
    $saved = get_option(AFFIROS_AI_OPTION_KEY, []);
    $merged = array_merge(affiros_ai_default_settings(), is_array($saved) ? $saved : []);
    // v0.2.0 以前のバグで秘密キーにマスク値 (****) が保存された場合は未設定扱いに
    foreach (['claude_api_key', 'amazon_client_id', 'amazon_client_secret',
              'rakuten_app_id', 'rakuten_access_key'] as $k) {
        if (preg_match('/^\*+$/', (string)($merged[$k] ?? ''))) $merged[$k] = '';
    }
    return $merged;
}

/**
 * 記事が除外カテゴリー/タグに属しているか
 * process() (一括・個別・公開時自動挿入すべての入口) から呼ばれる根本ガード
 */
function affiros_ai_taxonomy_excluded($post_id, $settings = null) {
    if (!$settings) $settings = affiros_ai_get_settings();
    $cat_ids = array_filter(array_map('intval', (array)($settings['exclude_category_ids'] ?? [])));
    if ($cat_ids && has_category($cat_ids, $post_id)) return true;
    $tags = array_filter(array_map('trim', explode(',', (string)($settings['exclude_tags'] ?? ''))));
    if ($tags && has_tag($tags, $post_id)) return true;
    return false;
}

/**
 * 管理メニュー
 */
add_action('admin_menu', function () {
    add_menu_page(
        'Affiros オートインサーター',
        'Affiros オート挿入',
        'manage_options',
        'affiros-ai',
        'affiros_ai_render_bulk_page',
        'dashicons-cart',
        77
    );
    add_submenu_page(
        'affiros-ai',
        '一括挿入',
        '✨ 一括挿入',
        'manage_options',
        'affiros-ai',
        'affiros_ai_render_bulk_page'
    );
    add_submenu_page(
        'affiros-ai',
        'デザインプレビュー',
        '🎨 デザインプレビュー',
        'manage_options',
        'affiros-ai-preview',
        'affiros_ai_render_preview_page'
    );
    add_submenu_page(
        'affiros-ai',
        '設定',
        '⚙️ 設定',
        'manage_options',
        'affiros-ai-settings',
        'affiros_ai_render_settings_page'
    );
});

/**
 * 管理画面用 CSS/JS
 */
add_action('admin_enqueue_scripts', function ($hook) {
    // 管理ページ + 投稿編集画面で AffirosAI 変数を利用可能に
    if (strpos($hook, 'affiros-ai') === false && $hook !== 'post.php' && $hook !== 'post-new.php') return;
    wp_localize_script('jquery', 'AffirosAI', [
        'ajaxUrl' => admin_url('admin-ajax.php'),
        'nonce'   => wp_create_nonce('affiros_ai_nonce'),
    ]);
    // デザインプレビューでフロントと同じカードCSSを使う
    if (strpos($hook, 'affiros-ai-preview') !== false) {
        wp_enqueue_style('affiros-ai-card', AFFIROS_AI_URL . 'assets/card.css', [], AFFIROS_AI_VERSION);
    }
});

/**
 * フロントエンド用カードCSS
 */
add_action('wp_enqueue_scripts', function () {
    wp_enqueue_style('affiros-ai-card', AFFIROS_AI_URL . 'assets/card.css', [], AFFIROS_AI_VERSION);
});

/**
 * 有効化フック
 */
register_activation_hook(__FILE__, function () {
    if (!get_option(AFFIROS_AI_OPTION_KEY)) {
        update_option(AFFIROS_AI_OPTION_KEY, affiros_ai_default_settings());
    }
});

// v0.7.0 で週次リフレッシュを廃止。旧バージョンが登録した cron イベントを掃除する
// v0.12.0 で月次リフレッシュ (日次イベントで分散処理) を導入。更新では
// activation hook が走らないため、スケジュール登録も init で行う
add_action('init', function () {
    if (wp_next_scheduled('affiros_ai_weekly_refresh')) {
        wp_clear_scheduled_hook('affiros_ai_weekly_refresh');
    }
    if (!wp_next_scheduled('affiros_ai_daily_refresh')) {
        wp_schedule_event(time() + 600, 'daily', 'affiros_ai_daily_refresh');
    }
});

register_deactivation_hook(__FILE__, function () {
    wp_clear_scheduled_hook('affiros_ai_daily_refresh');
});

/**
 * リフレッシュ履歴 (直近300件を wp_options に保持、autoload しない)
 */
function affiros_ai_refresh_log_add($post_id, $res) {
    $log = get_option('affiros_ai_refresh_log', []);
    if (!is_array($log)) $log = [];
    $log[] = [
        't'    => current_time('mysql'),
        'id'   => intval($post_id),
        'ok'   => !empty($res['success']),
        'skip' => !empty($res['skipped']),
        'msg'  => mb_substr((string)($res['message'] ?? ''), 0, 200),
    ];
    if (count($log) > 300) $log = array_slice($log, -300);
    update_option('affiros_ai_refresh_log', $log, false);
}
