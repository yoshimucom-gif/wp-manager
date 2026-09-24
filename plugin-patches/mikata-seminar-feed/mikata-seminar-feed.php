<?php
/**
 * Plugin Name: ミカタセミナー 更新情報
 * Description: ミカタセミナー（mikata-seminar.jp）の掲載セミナーを1日1回取得して保存し、ショートコードで表示します。RSSが無いサイトなので、トップページのHTMLから読み取ります。
 * Version:     1.0.0
 * Author:      ミカタ株式会社
 * License:     GPLv2 or later
 * Text Domain: mikata-seminar-feed
 */

if (!defined('ABSPATH')) {

/* 自動更新（GitHub直配信）。機能より先に入れる決まり */
require_once __DIR__ . '/includes/plugin-updater.php';
add_action( 'init', function () {
	new Mikata_Seminar_Plugin_Updater(
		__FILE__,
		'https://raw.githubusercontent.com/yoshimucom-gif/wp-manager/main/plugin-host/api/plugin-update/mikata-seminar-feed'
	);
} );
    exit;
}

class Mikata_Seminar_Feed
{
    const SOURCE   = 'https://mikata-seminar.jp/';
    const OPT_ITEMS = 'mikata_seminar_items';       // 取得できたセミナー
    const OPT_TIME  = 'mikata_seminar_fetched_at';  // 最後に取得できた時刻
    const OPT_ERR   = 'mikata_seminar_last_error';  // 最後のエラー
    const OPT_TRIED = 'mikata_seminar_tried_at';    // 最後に取得を試みた時刻
    const HOOK      = 'mikata_seminar_fetch_event';
    const MAX       = 12;                            // 保存する上限

    public static function boot()
    {
        add_action(self::HOOK, array(__CLASS__, 'fetch'));
        add_shortcode('mikata_seminar', array(__CLASS__, 'shortcode'));
        add_action('admin_menu', array(__CLASS__, 'admin_menu'));
        add_action('admin_post_mikata_seminar_refresh', array(__CLASS__, 'handle_refresh'));
        add_action('wp_enqueue_scripts', array(__CLASS__, 'styles'));
    }

    /* ---------- 有効化・停止 ---------- */

    public static function activate()
    {
        if (!wp_next_scheduled(self::HOOK)) {
            wp_schedule_event(time() + 60, 'daily', self::HOOK);
        }
        self::fetch();
    }

    public static function deactivate()
    {
        $ts = wp_next_scheduled(self::HOOK);
        if ($ts) {
            wp_unschedule_event($ts, self::HOOK);
        }
    }

    /* ---------- 取得と解析 ---------- */

    /**
     * 取得できた件数を返す。失敗しても、前回取得できた内容は消さない。
     */
    public static function fetch()
    {
        update_option(self::OPT_TRIED, time(), false);

        $res = wp_remote_get(self::SOURCE, array(
            'timeout'    => 20,
            'user-agent' => 'Mozilla/5.0 (compatible; MikataSeminarFeed/1.0; +https://f-mikata.co.jp/)',
            'headers'    => array('Accept-Language' => 'ja,en;q=0.8'),
        ));

        if (is_wp_error($res)) {
            update_option(self::OPT_ERR, '取得できませんでした: ' . $res->get_error_message(), false);
            return 0;
        }
        $code = wp_remote_retrieve_response_code($res);
        if ($code !== 200) {
            update_option(self::OPT_ERR, '取得できませんでした（HTTP ' . intval($code) . '）', false);
            return 0;
        }

        $items = self::parse(wp_remote_retrieve_body($res));

        // 解析できなかったときは、前の内容を残す（画面が空になるのを避ける）
        if (empty($items)) {
            update_option(self::OPT_ERR, 'ページは取れましたが、セミナーを読み取れませんでした。掲載元の作りが変わった可能性があります。', false);
            return 0;
        }

        update_option(self::OPT_ITEMS, $items, false);
        update_option(self::OPT_TIME, time(), false);
        update_option(self::OPT_ERR, '', false);
        return count($items);
    }

    /**
     * 掲載元のHTMLからセミナーを取り出す。
     * 作りが変わったら、ここの正規表現だけを直せば済むようにしている。
     */
    public static function parse($html)
    {
        if (!is_string($html) || $html === '') {
            return array();
        }

        // カード単位に切り分ける（class の引用符はどちらでも拾う）
        $chunks = preg_split('#<div\s+class=[\'"]card\s+card-sm#i', $html);
        if (!is_array($chunks) || count($chunks) < 2) {
            return array();
        }
        array_shift($chunks);

        $items = array();
        foreach ($chunks as $chunk) {
            // タイトルとリンク
            if (!preg_match('#seminar_title[\'"]?\s*>\s*<a[^>]*href=["\']([^"\']+)["\'][^>]*>(.*?)</a>#is', $chunk, $m)) {
                continue;
            }
            $url   = self::absolute(html_entity_decode($m[1], ENT_QUOTES, 'UTF-8'));
            $title = self::text($m[2]);
            if ($title === '' || $url === '') {
                continue;
            }

            // 開催日時（「開催日時」の後ろに出てくる最初の行）
            $date = '';
            $pos  = mb_strpos($chunk, '開催日時');
            if ($pos !== false) {
                $after = mb_substr($chunk, $pos, 900);
                if (preg_match('#<span[^>]*>\s*([^<>]{4,60}?)\s*</span>#u', $after, $d)) {
                    $date = self::text($d[1]);
                }
            }

            // 区分（売買仲介 など）
            $cat = '';
            if (preg_match('#badge\s+bg-primary[^>]*>\s*([^<]{1,20})\s*<#u', $chunk, $c)) {
                $cat = self::text($c[1]);
            }

            // サムネイル
            $img = '';
            if (preg_match('#card-img-top[^>]*src=["\']([^"\']+)["\']#i', $chunk, $g)) {
                $img = esc_url_raw(html_entity_decode($g[1], ENT_QUOTES, 'UTF-8'));
            }

            // 受付状況（受付中！ など）
            $state = '';
            if (preg_match('#ribbon\s+bg-[a-z]+[^>]*>\s*([^<]{1,12}?)\s*<#u', $chunk, $s)) {
                $state = self::text($s[1]);
            }

            $items[] = array(
                'title' => $title,
                'url'   => $url,
                'date'  => $date,
                'cat'   => $cat,
                'img'   => $img,
                'state' => $state,
            );
            if (count($items) >= self::MAX) {
                break;
            }
        }
        return $items;
    }

    private static function text($s)
    {
        $s = wp_strip_all_tags($s);
        $s = html_entity_decode($s, ENT_QUOTES, 'UTF-8');
        $s = preg_replace('#\s+#u', ' ', $s);
        return trim($s);
    }

    private static function absolute($url)
    {
        $url = trim($url);
        if ($url === '') {
            return '';
        }
        if (strpos($url, 'http') === 0) {
            return esc_url_raw($url);
        }
        return esc_url_raw(rtrim(self::SOURCE, '/') . '/' . ltrim($url, '/'));
    }

    /* ---------- 表示 ---------- */

    public static function styles()
    {
        $css = '
.mks-list{list-style:none!important;margin:0;padding:0}
.mks-list li{margin:0;padding:14px 0;border-bottom:1px solid #f0f2f4;list-style:none!important}
.mks-list li:first-child{padding-top:0}
.mks-list li:last-child{border-bottom:0}
.mks-meta{display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin-bottom:6px}
.mks-cat{font-size:10.5px;letter-spacing:.06em;background:#eef1f4;color:#5b6673;padding:2px 8px;border-radius:2px}
.mks-state{font-size:10.5px;letter-spacing:.06em;background:#F08430;color:#fff;padding:2px 8px;border-radius:2px}
.mks-date{font-size:11.5px;color:#8b98a6}
.mks-title{display:block;font-size:14px;line-height:1.75;font-weight:700;color:#182b40;
  text-decoration:none;transition:color .3s ease}
.mks-title:hover{color:#F08430}
.mks-empty{font-size:12.5px;color:#8b98a6;margin:0}
';
        wp_register_style('mikata-seminar-feed', false, array(), '1.0.0');
        wp_enqueue_style('mikata-seminar-feed');
        wp_add_inline_style('mikata-seminar-feed', $css);
    }

    public static function shortcode($atts)
    {
        $a = shortcode_atts(array(
            'limit' => 3,
            'date'  => 'yes',   // 開催日時を出すか
            'cat'   => 'yes',   // 区分を出すか
            'state' => 'yes',   // 受付状況を出すか
        ), $atts, 'mikata_seminar');

        $items = get_option(self::OPT_ITEMS, array());
        if (!is_array($items) || empty($items)) {
            return '<p class="mks-empty">セミナー情報を取得できませんでした。</p>';
        }
        $items = array_slice($items, 0, max(1, intval($a['limit'])));

        $out = '<ul class="mks-list">';
        foreach ($items as $it) {
            $meta = '';
            if ($a['state'] === 'yes' && !empty($it['state'])) {
                $meta .= '<span class="mks-state">' . esc_html($it['state']) . '</span>';
            }
            if ($a['cat'] === 'yes' && !empty($it['cat'])) {
                $meta .= '<span class="mks-cat">' . esc_html($it['cat']) . '</span>';
            }
            if ($a['date'] === 'yes' && !empty($it['date'])) {
                $meta .= '<span class="mks-date">' . esc_html($it['date']) . '</span>';
            }
            $out .= '<li>';
            if ($meta !== '') {
                $out .= '<div class="mks-meta">' . $meta . '</div>';
            }
            $out .= '<a class="mks-title" href="' . esc_url($it['url']) . '" target="_blank" rel="noreferrer noopener">'
                  . esc_html($it['title']) . '</a></li>';
        }
        $out .= '</ul>';
        return $out;
    }

    /* ---------- 管理画面 ---------- */

    public static function admin_menu()
    {
        add_options_page('ミカタセミナー 更新情報', 'ミカタセミナー', 'manage_options',
            'mikata-seminar-feed', array(__CLASS__, 'admin_page'));
    }

    public static function handle_refresh()
    {
        if (!current_user_can('manage_options')) {
            wp_die('権限がありません。');
        }
        check_admin_referer('mikata_seminar_refresh');
        $n = self::fetch();
        wp_safe_redirect(add_query_arg(
            array('page' => 'mikata-seminar-feed', 'fetched' => $n),
            admin_url('options-general.php')
        ));
        exit;
    }

    public static function admin_page()
    {
        $items = get_option(self::OPT_ITEMS, array());
        $time  = intval(get_option(self::OPT_TIME, 0));
        $tried = intval(get_option(self::OPT_TRIED, 0));
        $err   = get_option(self::OPT_ERR, '');
        $next  = wp_next_scheduled(self::HOOK);
        $fmt   = 'Y-m-d H:i';
        echo '<div class="wrap"><h1>ミカタセミナー 更新情報</h1>';

        if (isset($_GET['fetched'])) {
            $n = intval($_GET['fetched']);
            echo '<div class="notice notice-' . ($n > 0 ? 'success' : 'warning') . '"><p>'
               . ($n > 0 ? esc_html($n) . '件を取得しました。' : '取得できませんでした。下の「最後のエラー」をご確認ください。')
               . '</p></div>';
        }

        echo '<table class="widefat" style="max-width:820px"><tbody>';
        echo '<tr><th style="width:190px">掲載元</th><td><a href="' . esc_url(self::SOURCE) . '" target="_blank" rel="noreferrer noopener">' . esc_html(self::SOURCE) . '</a></td></tr>';
        echo '<tr><th>保存しているセミナー</th><td>' . count((array) $items) . '件</td></tr>';
        echo '<tr><th>最後に取得できた時刻</th><td>' . ($time ? esc_html(wp_date($fmt, $time)) : '—') . '</td></tr>';
        echo '<tr><th>最後に試した時刻</th><td>' . ($tried ? esc_html(wp_date($fmt, $tried)) : '—') . '</td></tr>';
        echo '<tr><th>次回の自動取得</th><td>' . ($next ? esc_html(wp_date($fmt, $next)) : '未設定') . '（1日1回）</td></tr>';
        echo '<tr><th>最後のエラー</th><td>' . ($err ? '<span style="color:#b32d2e">' . esc_html($err) . '</span>' : 'なし') . '</td></tr>';
        echo '</tbody></table>';

        echo '<p style="margin-top:18px"><a class="button button-primary" href="'
           . esc_url(wp_nonce_url(admin_url('admin-post.php?action=mikata_seminar_refresh'), 'mikata_seminar_refresh'))
           . '">いま取得する</a></p>';

        echo '<h2>固定ページ・投稿での出し方</h2>';
        echo '<p>ショートコードを置いてください。</p>';
        echo '<p><code>[mikata_seminar limit="3"]</code></p>';
        echo '<p>指定できるもの: <code>limit</code>（件数）／<code>date</code>・<code>cat</code>・<code>state</code>（<code>no</code> で非表示）</p>';

        if (!empty($items)) {
            echo '<h2>いま保存している内容</h2><table class="widefat striped" style="max-width:980px"><thead><tr>'
               . '<th style="width:110px">開催日時</th><th style="width:90px">区分</th><th>タイトル</th></tr></thead><tbody>';
            foreach ($items as $it) {
                echo '<tr><td>' . esc_html($it['date']) . '</td><td>' . esc_html($it['cat']) . '</td>'
                   . '<td><a href="' . esc_url($it['url']) . '" target="_blank" rel="noreferrer noopener">'
                   . esc_html($it['title']) . '</a></td></tr>';
            }
            echo '</tbody></table>';
        }

        echo '<h2>うまく取れなくなったら</h2>';
        echo '<p>掲載元はRSSを持っていないため、ページのHTMLから読み取っています。'
           . '先方のページの作りが変わると読み取れなくなります。その場合もこの画面に前回の内容が残り、'
           . '公開ページが空になることはありません。読み取り部分（<code>parse()</code>）を直せば復旧します。</p>';
        echo '</div>';
    }
}

register_activation_hook(__FILE__, array('Mikata_Seminar_Feed', 'activate'));
register_deactivation_hook(__FILE__, array('Mikata_Seminar_Feed', 'deactivate'));
Mikata_Seminar_Feed::boot();
