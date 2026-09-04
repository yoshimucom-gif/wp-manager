<?php
/**
 * Plugin Name: 装飾・整形プラグイン
 * Description: Claude APIによる自動装飾（マーカー・赤字・強調ボックス等）と、機械的な段落整形（分割・見出し昇格）を統合。DBPテーマ用ブロック生成前提。
 * Version: 1.0.28
 * License: GPL v2 or later
 * Text Domain: decoration-formatter
 */

if (!defined('ABSPATH')) exit;

define('DECOFMT_VERSION', '1.0.28');
define('DECOFMT_PATH', plugin_dir_path(__FILE__));
define('DECOFMT_URL', plugin_dir_url(__FILE__));

// 既定モデル。v1.0.19 で一度 Haiku にしたが v1.0.20 で Sonnet に戻した。
// 以前は 8 箇所に 'claude-sonnet-4-6' がベタ書きされていて変更漏れの温床だったため定数に集約した。
define('DECOFMT_DEFAULT_MODEL', 'claude-sonnet-4-6');
// 一括処理のスキャン上限の既定値
define('DECOFMT_DEFAULT_SCAN_LIMIT', 50);

// includes（装飾）
require_once DECOFMT_PATH . 'includes/claude-api.php';
require_once DECOFMT_PATH . 'includes/validator.php';
require_once DECOFMT_PATH . 'includes/decorator.php';
require_once DECOFMT_PATH . 'includes/post-meta.php';

// includes（整形）
require_once DECOFMT_PATH . 'includes/paragraph-formatter.php';

/**
 * 自動更新チェッカー登録
 *
 * v1.0.28: GitHub直配信に移行（affiros系プラグインと同じ方式）。
 *   旧ホストのミカタOWNEDは生きているが、更新の正はwp-managerリポジトリに集約する。
 *   リポジトリは公開なので raw がそのまま配信になり、push＝配信完了。
 *   DECOFMT_UPDATE_HOST を定義しているサイトはそちらを優先する（移行期の逃げ道）。
 */
require_once DECOFMT_PATH . 'includes/plugin-updater.php';
add_action('init', function () {
    $url = 'https://raw.githubusercontent.com/yoshimucom-gif/wp-manager/main/plugin-host/api/plugin-update/decoration-formatter';
    if (defined('DECOFMT_UPDATE_HOST')) {
        $url = rtrim(DECOFMT_UPDATE_HOST, '/') . '/api/plugin-update/decoration-formatter';
    }
    new Decofmt_Plugin_Updater(__FILE__, $url);
});

// admin
require_once DECOFMT_PATH . 'admin/settings.php';
require_once DECOFMT_PATH . 'admin/decoration-meta-box.php';
require_once DECOFMT_PATH . 'admin/formatter-meta-box.php';
require_once DECOFMT_PATH . 'admin/decoration-bulk.php';
require_once DECOFMT_PATH . 'admin/formatter-bulk.php';
require_once DECOFMT_PATH . 'admin/ajax-handler.php';

/**
 * 使用可能なClaudeモデル一覧
 * cost_yen は標準3,000字記事・装飾レベル「標準」での1記事あたり試算（1USD=155円）
 */
function decofmt_get_models() {
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

function decofmt_get_cost_per_post($model) {
    $models = decofmt_get_models();
    return $models[$model]['cost_yen'] ?? 19;
}

function decofmt_get_model_label($model) {
    $models = decofmt_get_models();
    return $models[$model]['label'] ?? $model;
}

register_activation_hook(__FILE__, 'decofmt_activate');
function decofmt_activate() {
    if (!get_option('decofmt_deco_settings')) {
        add_option('decofmt_deco_settings', [
            'api_key' => '',
            'model' => DECOFMT_DEFAULT_MODEL,
            'decoration_level' => 'standard',
            'enable_faq' => 'no',
            'auto_decorate_on_save' => 'no',
        ]);
    }
    // 整形設定は decofmt_fmt_default_settings() が実行時に既定値を返すので add_option 不要
}

add_action('admin_menu', 'decofmt_admin_menu');
function decofmt_admin_menu() {
    add_menu_page('装飾・整形', '装飾・整形', 'manage_options', 'decoration-formatter', 'decofmt_render_settings_page', 'dashicons-art', 58);
    add_submenu_page('decoration-formatter', '設定', '設定', 'manage_options', 'decoration-formatter', 'decofmt_render_settings_page');
    add_submenu_page('decoration-formatter', 'AI装飾（一括）', '🎨 AI装飾（一括）', 'manage_options', 'decofmt-deco-bulk', 'decofmt_render_deco_bulk_page');
    add_submenu_page('decoration-formatter', '段落整形（一括）', '📝 段落整形（一括）', 'manage_options', 'decofmt-fmt-bulk', 'decofmt_render_fmt_bulk_page');
    add_submenu_page('decoration-formatter', '処理ログ', '処理ログ', 'manage_options', 'decofmt-logs', 'decofmt_render_logs_page');
}

add_action('admin_enqueue_scripts', 'decofmt_admin_scripts');
function decofmt_admin_scripts($hook) {
    if (strpos($hook, 'decoration-formatter') === false
        && strpos($hook, 'decofmt-') === false
        && $hook !== 'post.php'
        && $hook !== 'post-new.php') {
        return;
    }

    wp_enqueue_style('decofmt-admin', DECOFMT_URL . 'assets/admin.css', [], DECOFMT_VERSION);
    wp_enqueue_script('decofmt-admin', DECOFMT_URL . 'assets/admin.js', ['jquery'], DECOFMT_VERSION, true);

    wp_localize_script('decofmt-admin', 'decofmt', [
        'ajaxUrl' => admin_url('admin-ajax.php'),
        'nonce' => wp_create_nonce('decofmt_nonce'),
        'models' => decofmt_get_models(),
    ]);
}

function decofmt_render_logs_page() {
    require_once DECOFMT_PATH . 'admin/logs.php';
    decofmt_logs_render();
}

/**
 * v1.0.20: v1.0.19 の Haiku 移行を取り消す。
 *
 * v1.0.19 で既定モデルを一時的に Haiku に変更し、既存サイトの保存値も
 * Sonnet → Haiku に書き換える移行処理を入れた。v1.0.20 で方針を戻したため、
 * その移行で書き換わったサイトだけを Sonnet に戻す。
 *
 * 条件を「v1.0.19 の移行が走った（フラグあり）」かつ「現在 Haiku」に限定するので、
 * 自分の意思で Haiku を選んだサイトや Opus のサイトには触れない。
 */
add_action('admin_init', 'decofmt_revert_haiku_migration');
function decofmt_revert_haiku_migration() {
    if (get_option('decofmt_reverted_to_sonnet')) return;
    update_option('decofmt_reverted_to_sonnet', 1, false);

    // v1.0.19 の移行が走っていないサイトは、そもそも書き換えていないので何もしない
    if (!get_option('decofmt_migrated_haiku')) return;

    $settings = get_option('decofmt_deco_settings', []);
    if (!is_array($settings) || empty($settings)) return;
    if (($settings['model'] ?? '') !== 'claude-haiku-4-5-20251001') return;

    $settings['model'] = DECOFMT_DEFAULT_MODEL; // Sonnet に戻す
    update_option('decofmt_deco_settings', $settings);
}

/**
 * 投稿保存時の自動装飾フック（設定でON時のみ）
 */
add_action('save_post_post', 'decofmt_maybe_auto_decorate', 30, 3);
add_action('save_post_page', 'decofmt_maybe_auto_decorate', 30, 3);
function decofmt_maybe_auto_decorate($post_id, $post, $update) {
    $settings = get_option('decofmt_deco_settings', []);
    if (($settings['auto_decorate_on_save'] ?? 'no') !== 'yes') return;

    if (wp_is_post_revision($post_id)) return;
    if (wp_is_post_autosave($post_id)) return;
    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
    if (defined('DOING_AJAX') && DOING_AJAX) return;
    if (defined('REST_REQUEST') && REST_REQUEST) return;
    if (defined('DOING_CRON') && DOING_CRON) return;

    if ($post->post_status !== 'publish') return;
    if (get_transient('decofmt_processing_' . $post_id)) return;
    if (Decofmt_Post_Meta::is_decorated($post_id)) return;
    if (Decofmt_Post_Meta::is_excluded($post_id)) return;
    if (empty(trim($post->post_content))) return;

    Decofmt_Decorator::decorate_post($post_id, ['dry_run' => false]);
}
