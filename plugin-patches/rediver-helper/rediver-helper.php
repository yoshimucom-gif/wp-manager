<?php
/**
 * Plugin Name: Re:Diver ヘルパー
 * Description: 通常のREST APIでは触れないWordPress/テーマの設定を、スクリプトから読み書きできるようにする構築補助プラグイン。テーマ側の不具合の回避（外部リンクアイコンの豆腐）も含む。カテゴリー画像などのタームメタ、記事幅などの投稿メタ、カスタマイザー（theme_mod / オプション）に対応。キー名を発見する調査用エンドポイント付き。全て管理者権限必須。
 * Version: 1.1.2
 * Author: Keys
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * Update URI: https://github.com/yoshimucom-gif/wp-manager
 * License: GPL v2 or later
 */

if (!defined('ABSPATH')) exit;

define('RDH_VERSION', '1.1.2');

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
                    'タームメタの削除'                 => "DELETE {$base}/termmeta/<term_id>  {key}",
                    '投稿メタのキー調査'               => "GET  {$base}/postmeta-keys?post_type=post&limit=20",
                    '投稿1件の全メタ'                 => "GET  {$base}/postmeta/<post_id>",
                    '投稿メタの更新'                   => "POST {$base}/postmeta/<post_id>  {key,value}",
                    '投稿メタの一括更新'               => "POST {$base}/postmeta/bulk  {key,post_ids,value}",
                    'カスタマイザー(theme_mod)一覧'    => "GET  {$base}/thememods",
                    'カスタマイザーの更新'             => "POST {$base}/thememods  {key,value}",
                    'オプション検索（キー発見）'        => "GET  {$base}/options?search=diver",
                    'オプション取得'                   => "GET  {$base}/option/<name>",
                    'オプション更新'                   => "POST {$base}/option/<name>  {value,merge,force}",
                    '退避の一覧'                       => "GET  {$base}/backups",
                    '退避1件の中身（戻る値の確認）'     => "GET  {$base}/backups?id=<backup_id>",
                    '退避から復元'                     => "POST {$base}/backups  {id}",
                ],
                'params' => [
                    'dry_run'     => '書かずに before と would_be だけ返す。一括更新の前には必ず通す。',
                    'merge'       => 'オプションが配列のとき、指定した葉だけ差し替える（他の項目は残る）。',
                    'force'       => 'merge を使わず丸ごと置き換える。既存キーが消えるのを承知のときだけ。',
                    'allow_empty' => '空文字を「削除」ではなく「空文字として保存」にする。',
                ],
                'fixes' => [
                    '外部リンクアイコンの豆腐' => 'テーマのCSSが指定する記号が、読み込み済みフォントのサブセットに入っていないため□になる。同じセレクタに実文字の矢印を上書きして回避する。無効化するフィルタ名は rdh_extlink_icon_fix。',
                ],
                'notes' => [
                    '書き込み系はすべて before / after / changed を返す。changed=false なら実際には変わっていない。',
                    'update_option はサニタイズを通るため、200でも値が反映されないことがある（after で判定する）。',
                    'サイトが壊れるオプション（siteurl/home/template/active_plugins 等）は拒否する。',
                    '書き込み系はすべて backup_id を返す。POST /backups {id} でその時点に戻せる。',
                    '一括更新は全件の変更前の値を1件の退避にまとめて保存する。上限は ' . RDH_BULK_MAX . ' 件。',
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
