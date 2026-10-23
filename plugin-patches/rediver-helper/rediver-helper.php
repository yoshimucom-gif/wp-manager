<?php
/**
 * Plugin Name: Re:Diver ヘルパー
 * Description: 通常のREST APIでは触れないWordPress/テーマの設定を、スクリプトから読み書きできるようにする構築補助プラグイン。テーマ側の不具合の回避（外部リンクアイコンの豆腐）も含む。カテゴリー画像などのタームメタ、記事幅などの投稿メタ、カスタマイザー（theme_mod / オプション）に対応。キー名を発見する調査用エンドポイント付き。全て管理者権限必須。
 * Version: 1.5.0
 * Author: Keys
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * Update URI: https://github.com/yoshimucom-gif/wp-manager
 * License: GPL v2 or later
 */

if (!defined('ABSPATH')) exit;

define('RDH_VERSION', '1.5.0');

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
require_once __DIR__ . '/includes/rest-diver-keys.php';
require_once __DIR__ . '/includes/fix-extlink-icon.php';
require_once __DIR__ . '/includes/avatar.php';
require_once __DIR__ . '/includes/fix-single-robots.php';
require_once __DIR__ . '/includes/cf7-style.php';
require_once __DIR__ . '/includes/ads-txt.php';

/**
 * 新版の即時適用（1.4.0）: POST /wp-json/rdh/v1/selfupdate
 * 更新キャッシュを捨てて配信元を確認し、このプラグインだけをその場で更新する。
 * 管理画面の更新ボタンを押さずに、リリース直後の版を反映するための窓口。
 */
add_action('rest_api_init', function () {
    register_rest_route(RDH_NS, '/selfupdate', [
        'methods'             => 'POST',
        'permission_callback' => function () { return current_user_can('update_plugins'); },
        'callback'            => function () {
            $base = plugin_basename(__FILE__);
            delete_transient('rdh_updater_' . md5($base));
            delete_transient('rdh_updater_fail_' . md5($base));
            delete_site_transient('update_plugins');
            wp_update_plugins();
            $upd = get_site_transient('update_plugins');
            if (empty($upd->response[$base])) {
                return ['ok' => true, 'updated' => false, 'version' => RDH_VERSION, 'note' => '配信版との差がない'];
            }
            require_once ABSPATH . 'wp-admin/includes/file.php';
            require_once ABSPATH . 'wp-admin/includes/misc.php';
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
            require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
            $up  = new Plugin_Upgrader(new Automatic_Upgrader_Skin());
            $res = $up->upgrade($base);
            // 更新の途中でWPはプラグインを無効にし、自分自身を更新したリクエストでは有効に戻らないので戻す
            $active = (array) get_option('active_plugins', []);
            $was    = in_array($base, $active, true);
            if (!$was) {
                $active[] = $base;
                update_option('active_plugins', array_values(array_unique($active)));
            }
            return ['ok' => $res === true, 'updated' => $res === true, 'version_before' => RDH_VERSION,
                    'to' => $upd->response[$base]->new_version ?? '', 'reactivated' => !$was,
                    'error' => is_wp_error($res) ? $res->get_error_message() : null];
        },
    ]);
});

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
                    're:Diverの設定キー索引（現在値つき）' => "GET  {$base}/diver-keys",
                    'カスタマイザー(theme_mod)一覧'    => "GET  {$base}/thememods",
                    'カスタマイザーの更新'             => "POST {$base}/thememods  {key,value}",
                    'オプション検索（キー発見）'        => "GET  {$base}/options?search=diver",
                    'オプション取得'                   => "GET  {$base}/option/<name>",
                    'オプション更新'                   => "POST {$base}/option/<name>  {value,merge,force}",
                    '退避の一覧'                       => "GET  {$base}/backups",
                    '退避1件の中身（戻る値の確認）'     => "GET  {$base}/backups?id=<backup_id>",
                    '退避から復元'                     => "POST {$base}/backups  {id}",
                    '新版の即時適用'                   => "POST {$base}/selfupdate",
                    'CF7フォームの見た目を有効化'      => "POST {$base}/option/rdh_cf7_style  {\"value\":\"1\"}",
                    'ads.txt の配信'                   => "POST {$base}/option/rdh_ads_txt  {\"value\":\"google.com, pub-..., DIRECT, f08c47fec0942fa0\"}",
                    '著者アバターの差し替え'           => "POST {$base}/option/rdh_avatar_map  {\"value\":{\"<user_id>\":<attachment_id>}}",
                ],
                'params' => [
                    'dry_run'     => '書かずに before と would_be だけ返す。一括更新の前には必ず通す。',
                    'merge'       => 'オプションが配列のとき、指定した葉だけ差し替える（他の項目は残る）。',
                    'force'       => 'merge を使わず丸ごと置き換える。既存キーが消えるのを承知のときだけ。',
                    'allow_empty' => '空文字を「削除」ではなく「空文字として保存」にする。',
                ],
                'fixes' => [
                    '外部リンクアイコンの豆腐' => 'テーマのCSSが指定する記号が、読み込み済みフォントのサブセットに入っていないため□になる。同じセレクタに実文字の矢印を上書きして回避する。無効化するフィルタ名は rdh_extlink_icon_fix。',
                    '記事ごとのnoindexが出ない' => '編集画面のSEO設定（投稿メタ diver_single_seo の valid と robots.noindex / nofollow）が <meta name="robots"> に出ないため、wp_robots で同じ値を足す（1.4.0）。無効化するフィルタ名は rdh_single_robots_fix。',
                    'CF7フォームが既定の見た目' => 'option rdh_cf7_style を 1 にすると、Contact Form 7 の入力欄・送信ボタン・結果表示をサイトの配色（--rd--c--accent）で整える（1.4.0・既定はオフ）。本文に <span class="rdh-req">必須</span> / <span class="rdh-opt">任意</span> を置くとラベル横の札になる。',
                    'アバターがGravatarになる' => 'option rdh_avatar_map に「ユーザーID: 添付ファイルID」を入れると、サイト内のアバターをメディアライブラリの画像に差し替える（1.3.0）。Gravatarへの登録は不要。無効化するフィルタ名は rdh_avatar_override。',
                ],
                'notes' => [
                    '配色・見出しの色・メインビジュアル・記事幅などのキー名が分からないときは GET /diver-keys を見る。現在値と書き方が一緒に返る。',
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
