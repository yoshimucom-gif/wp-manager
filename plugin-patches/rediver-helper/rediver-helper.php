<?php
/**
 * Plugin Name: Re:Diver ヘルパー
 * Description: 通常のREST APIでは触れないWordPress/テーマの設定を、スクリプトから読み書きできるようにする構築補助プラグイン。テーマ側の不具合の回避（外部リンクアイコンの豆腐）も含む。カテゴリー画像などのタームメタ、記事幅などの投稿メタ、カスタマイザー（theme_mod / オプション）に対応。キー名を発見する調査用エンドポイント付き。全て管理者権限必須。
 * Version: 1.1.0
 * Author: Keys
 * License: GPL v2 or later
 */

if (!defined('ABSPATH')) exit;

define('RDH_VERSION', '1.1.0');

// 自動更新通知（GitHub 直配信のメタJSONを定期チェック）
require_once __DIR__ . '/includes/plugin-updater.php';
add_action('init', function () {
    // 差し替えたいときは wp-config.php で RDH_UPDATE_URL を定義する
    $url = defined('RDH_UPDATE_URL') ? RDH_UPDATE_URL
        : 'https://raw.githubusercontent.com/yoshimucom-gif/wp-manager/main/plugin-host/api/plugin-update/rediver-helper';
    new RDH_Plugin_Updater(__FILE__, $url);
});

require_once __DIR__ . '/includes/common.php';
require_once __DIR__ . '/includes/rest-termmeta.php';
require_once __DIR__ . '/includes/rest-postmeta.php';
require_once __DIR__ . '/includes/rest-customizer.php';
require_once __DIR__ . '/includes/fix-extlink-icon.php';

/**
 * 使えるエンドポイントの一覧を返す（迷子防止）
 */
add_action('rest_api_init', function () {
    register_rest_route(RDH_NS, '/help', [
        'methods'             => 'GET',
        'permission_callback' => 'rdh_permission',
        'callback'            => function () {
            $base = rest_url(RDH_NS);
            return [
                'version'    => RDH_VERSION,
                'stylesheet' => get_stylesheet(),
                'endpoints'  => [
                    'カテゴリ等のメタ一覧（キー発見）' => "GET  {$base}/termmeta?taxonomy=category",
                    'ターム1件の全メタ'               => "GET  {$base}/termmeta/<term_id>",
                    'タームメタの更新'                 => "POST {$base}/termmeta/<term_id>  {key,value}",
                    '投稿メタのキー調査'               => "GET  {$base}/postmeta-keys?post_type=post&limit=20",
                    '投稿1件の全メタ'                 => "GET  {$base}/postmeta/<post_id>",
                    '投稿メタの更新'                   => "POST {$base}/postmeta/<post_id>  {key,value}",
                    '投稿メタの一括更新'               => "POST {$base}/postmeta/bulk  {key,post_ids,value}",
                    'カスタマイザー(theme_mod)一覧'    => "GET  {$base}/thememods",
                    'カスタマイザーの更新'             => "POST {$base}/thememods  {key,value}",
                    'オプション検索（キー発見）'        => "GET  {$base}/options?search=diver",
                    'オプション取得'                   => "GET  {$base}/option/<name>",
                    'オプション更新'                   => "POST {$base}/option/<name>  {value}",
                ],
                'fixes' => [
                    '外部リンクアイコンの豆腐' => 'style.min.css の content:"\e89e" が読み込み済みフォントのサブセットに無いため□になる。同セレクタに実文字の矢印を上書きして回避。止めるなら add_filter('rdh_extlink_icon_fix', '__return_false')',
                ],
                'notes' => [
                    '書き込み系はすべて before / after / changed を返す。changed=false なら実際には変わっていない。',
                    'update_option はサニタイズを通るため、200でも値が反映されないことがある（after で判定する）。',
                    'サイトが壊れるオプション（siteurl/home/template/active_plugins 等）は拒否する。',
                ],
            ];
        },
    ]);
});

add_filter('plugin_action_links_' . plugin_basename(__FILE__), function ($links) {
    $url = rest_url(RDH_NS . '/help');
    array_unshift($links, '<a href="' . esc_url($url) . '" target="_blank" rel="noopener">API一覧</a>');
    return $links;
});
