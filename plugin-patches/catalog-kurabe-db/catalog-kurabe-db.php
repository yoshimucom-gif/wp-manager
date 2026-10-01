<?php
/**
 * Plugin Name: カタログギフトくらべ 比較データ表示
 * Description: カタログギフトの比較データ（各社の公式通販から取得した仕様）を投稿メタ kurabe_data に保存し、ショートコード [kurabe part="..."] で出典・数字・一覧表・通販リンクを表示します。発行会社・ブランドの定義（名前・表記・色）と絞り込みの軸はデータ側の stores / filters 配列で持ち、プラグインには店名をハードコードしません。見出しと本文の見た目はテーマに任せ、このプラグインは部品だけを描きます。
 * Version:     1.2.20
 * Author:      Keys
 * License:     GPLv2 or later
 * Text Domain: catalog-kurabe-db
 */

if (!defined('ABSPATH')) {
    exit;
}

/* 自動更新（GitHub直配信）。機能より先に入れる決まり */
require_once __DIR__ . '/includes/plugin-updater.php';
add_action('init', function () {
    new Catalog_Kurabe_Db_Plugin_Updater(
        __FILE__,
        'https://raw.githubusercontent.com/yoshimucom-gif/wp-manager/main/plugin-host/api/plugin-update/catalog-kurabe-db'
    );
});

class Catalog_Kurabe_Db
{
    const VERSION  = '1.2.20';
    const META     = 'kurabe_data';
    const OPT      = 'catalog_kurabe_db_settings';

    private static $used = false;

    public static function boot()
    {
        add_action('init', array(__CLASS__, 'register_meta'));
        add_shortcode('kurabe', array(__CLASS__, 'shortcode'));
        add_action('wp_enqueue_scripts', array(__CLASS__, 'assets'));
        add_action('admin_menu', array(__CLASS__, 'admin_menu'));
        add_action('admin_init', array(__CLASS__, 'admin_init'));
        add_filter('diver_single_side_items', array(__CLASS__, 'side_items'));
        add_action('diver_main_before', array(__CLASS__, 'archive_table'), 20);
        add_shortcode('kurabe_list', array(__CLASS__, 'list_shortcode'));
        add_shortcode('kurabe_top', array(__CLASS__, 'top_shortcode'));
        add_action('rest_api_init', array(__CLASS__, 'rest_selfupdate'));
        add_action('rest_api_init', array(__CLASS__, 'rest_shindan'));
        add_shortcode('kurabe_shindan', array(__CLASS__, 'shindan_shortcode'));
        add_filter('wp_robots', array(__CLASS__, 'robots_while_private'), 999);
    }

    /* 「検索エンジンがサイトをインデックスしないようにする」がオンの間は、全ページに noindex を付ける。
       re:Diver は記事ごとの設定（既定 noindex=false）でサイト全体の設定を上書きし、
       公開前なのに robots が「nofollow」だけになる（100均くらべで 2026-09-30 実測）。
       本公開でこの設定をオフにすれば、ここは何もしなくなる */
    public static function robots_while_private($robots)
    {
        if ((string) get_option('blog_public') === '0') {
            $robots['noindex']  = true;
            $robots['nofollow'] = true;
        }
        return $robots;
    }

    /* 新版の即時適用の窓口（管理者のアプリケーションパスワードで叩く）: POST /wp-json/ckdb/v1/selfupdate
       更新キャッシュを捨てて配信元を確認し、WP標準の自動更新をその場で走らせる（100均くらべ kdb/v1 と同じ作り） */
    public static function rest_selfupdate()
    {
        register_rest_route('ckdb/v1', '/selfupdate', array(
            'methods'             => 'POST',
            'permission_callback' => function () {
                return current_user_can('update_plugins');
            },
            'callback'            => function () {
                $before = self::VERSION;
                delete_transient('catalog_kurabe_db_updater_' . md5(plugin_basename(__FILE__)));
                delete_site_transient('update_plugins');
                wp_update_plugins();
                if (function_exists('wp_maybe_auto_update')) {
                    wp_maybe_auto_update();
                }
                // 更新の途中でWPはプラグインを無効にし、自分自身を更新したリクエストでは有効に戻らないので戻す
                $base   = plugin_basename(__FILE__);
                $active = (array) get_option('active_plugins', array());
                $was    = in_array($base, $active, true);
                if (!$was) {
                    $active[] = $base;
                    update_option('active_plugins', array_values(array_unique($active)));
                }
                return array('ok' => true, 'version_before' => $before, 'reactivated' => !$was);
            },
        ));
    }

    /* ---------- カタログギフト診断（2026-10-01） ----------
       データ（診断用に詰めたDBの行・用途・予算・比較ページへのリンク）は、診断ページの投稿メタ kurabe_shindan に
       JSON 文字列で入れる（kurabe/build_shindan.py --apply）。ページには部品だけを出し、データは REST で読む */
    const SHINDAN = 'kurabe_shindan';

    public static function rest_shindan()
    {
        register_rest_route('ckdb/v1', '/shindan/(?P<id>\d+)', array(
            'methods'             => 'GET',
            'permission_callback' => '__return_true',
            'callback'            => function ($req) {
                $id = (int) $req['id'];
                if (get_post_status($id) !== 'publish' && !current_user_can('edit_post', $id)) {
                    return new WP_Error('not_found', 'not found', array('status' => 404));
                }
                $d = json_decode((string) get_post_meta($id, self::SHINDAN, true), true);
                if (!is_array($d)) {
                    return new WP_Error('not_found', 'not found', array('status' => 404));
                }
                $res = new WP_REST_Response($d);
                $res->header('Cache-Control', 'public, max-age=3600');
                return $res;
            },
        ));
    }

    public static function shindan_shortcode()
    {
        $id = get_the_ID();
        if (!$id || !get_post_meta($id, self::SHINDAN, true)) {
            return '';
        }
        wp_enqueue_style('catalog-kurabe-db');
        wp_enqueue_script('catalog-kurabe-shindan');
        return '<div class="kurabe-shindan" data-src="' . esc_url(rest_url('ckdb/v1/shindan/' . $id)) . '">'
            . '<p class="ks-wait">診断を読み込んでいます。</p>'
            . '<noscript><p class="ks-wait">診断を使うには、ブラウザのJavaScriptを有効にしてください。</p></noscript></div>';
    }

    /* 比較表の上に出す診断への入口（診断ページが公開されているときだけ） */
    private static function shindan_link()
    {
        static $url = null;
        if ($url === null) {
            $p = get_page_by_path('shindan');
            $url = ($p && $p->post_status === 'publish') ? get_permalink($p) : '';
        }
        if (!$url || (int) get_the_ID() === (int) url_to_postid($url)) {
            return '';
        }
        return '<p class="kurabe-shindan-link"><a href="' . esc_url($url) . '">用途と予算から選ぶなら<b>カタログギフト診断</b>（5問）</a></p>';
    }

    /* 比較ページは「サイズ：幅広」で組む。re:Diverは幅広のとき記事横の縦並びボタン
       （シェア・コピー・保存・目次・印刷）を本文の上に積んで大きな空白を作るので、
       比較データのあるページでは出さない（シェアはタイトル欄に残る） */
    public static function side_items($items)
    {
        return is_singular() && get_post_meta(get_the_ID(), self::META, true) ? array() : $items;
    }

    /* ---------- データ ---------- */

    public static function register_meta()
    {
        foreach (array('post', 'page') as $type) {
            foreach (array('kurabe_item', 'kurabe_group', 'kurabe_parent', 'kurabe_count', 'kurabe_stores', 'kurabe_axes') as $k) {
                register_post_meta($type, $k, array(
                    'type'          => 'string',
                    'single'        => true,
                    'show_in_rest'  => true,
                    'auth_callback' => function () {
                        return current_user_can('edit_posts');
                    },
                ));
            }
            register_post_meta($type, self::META, array(
                'type'          => 'string',
                'single'        => true,
                'show_in_rest'  => true,
                'auth_callback' => function () {
                    return current_user_can('edit_posts');
                },
            ));
        }
    }

    private static function data($post_id = null)
    {
        $post_id = $post_id ? $post_id : get_the_ID();
        if (!$post_id) {
            return null;
        }
        $raw = get_post_meta($post_id, self::META, true);
        if (!$raw) {
            return null;
        }
        $d = json_decode($raw, true);
        return is_array($d) && !empty($d['rows']) ? $d : null;
    }

    /* ---------- 店定義（kurabe_data の stores 配列から読む） ----------
       "stores": [{"s":"洋服の青山","slug":"aoyama","label":"洋服の青山","color":"#1a3a5c"}, ...]
       s     = rows の s 値と一致するキー
       slug  = CSSクラス・タグslug用
       label = バッジ表記
       color = ブランド色（バッジ・帯グラフ・チップに --kurabe-c で渡す） */

    private static function stores($d)
    {
        $out = array();
        if (!empty($d['stores']) && is_array($d['stores'])) {
            foreach ($d['stores'] as $st) {
                if (empty($st['s'])) {
                    continue;
                }
                $out[$st['s']] = array(
                    'slug'  => isset($st['slug']) && $st['slug'] !== '' ? $st['slug'] : 'other',
                    'label' => isset($st['label']) && $st['label'] !== '' ? $st['label'] : $st['s'],
                    'color' => isset($st['color']) ? (string) $st['color'] : '',
                    'count' => isset($st['count']) ? (int) $st['count'] : 0,   // その店の真の該当数（表が抜粋のときrowsと違う）
                );
            }
        }
        return $out;
    }

    private static function label($stores, $s)
    {
        return isset($stores[$s]['label']) ? $stores[$s]['label'] : $s;
    }

    private static function slug($stores, $s)
    {
        return isset($stores[$s]['slug']) ? $stores[$s]['slug'] : 'other';
    }

    /* バッジ・帯グラフ・チップに渡すインラインCSS変数。stores に無い店は灰色フォールバック */
    private static function color_style($stores, $s, $extra = '')
    {
        $c = isset($stores[$s]['color']) ? $stores[$s]['color'] : '';
        $style = ($c !== '' ? '--kurabe-c:' . $c . ';' : '') . $extra;
        return $style !== '' ? ' style="' . esc_attr($style) . '"' : '';
    }

    /* rows に出てくる店を stores 定義の順に並べる（定義に無い店は末尾） */
    private static function present_stores($d, $stores)
    {
        $present = array();
        foreach ($d['rows'] as $r) {
            if (!empty($r['s'])) {
                $present[$r['s']] = true;
            }
        }
        $ordered = array();
        foreach (array_keys($stores) as $s) {
            if (isset($present[$s])) {
                $ordered[] = $s;
                unset($present[$s]);
            }
        }
        foreach (array_keys($present) as $s) {
            $ordered[] = $s;
        }
        return $ordered;
    }

    /* ---------- アフィリエイトID ---------- */

    private static function ids()
    {
        $own = get_option(self::OPT, array());
        $af  = get_option('affiros_ai_settings', array());
        $amazon  = !empty($own['amazon_tag']) ? $own['amazon_tag'] : (isset($af['amazon_partner_tag']) ? $af['amazon_partner_tag'] : '');
        $rakuten = !empty($own['rakuten_id']) ? $own['rakuten_id'] : (isset($af['rakuten_affiliate_id']) ? $af['rakuten_affiliate_id'] : '');
        if (!$amazon) {
            $amazon = get_option('yyi_rinker_amazon_traccking_id', '');
        }
        if (!$rakuten) {
            $rakuten = get_option('yyi_rinker_rakuten_affiliate_id', '');
        }
        return array(trim((string) $amazon), trim((string) $rakuten));
    }

    private static function amazon_url($q)
    {
        list($tag) = self::ids();
        $u = 'https://www.amazon.co.jp/s?k=' . rawurlencode($q);
        return $tag ? $u . '&tag=' . rawurlencode($tag) : $u;
    }

    private static function rakuten_url($q)
    {
        list(, $id) = self::ids();
        $u = 'https://search.rakuten.co.jp/search/mall/' . rawurlencode($q) . '/';
        return $id ? 'https://hb.afl.rakuten.co.jp/hgc/' . $id . '/?pc=' . rawurlencode($u) : $u;
    }

    /* ---------- 表示 ---------- */

    public static function assets()
    {
        $url = plugin_dir_url(__FILE__) . 'assets/';
        wp_register_style('catalog-kurabe-db', $url . 'kurabe.css', array(), self::VERSION);
        wp_register_script('catalog-kurabe-db', $url . 'kurabe.js', array(), self::VERSION, true);
        wp_register_script('catalog-kurabe-shindan', $url . 'shindan.js', array(), self::VERSION, true);
    }

    public static function shortcode($atts)
    {
        $a = shortcode_atts(array('part' => 'table'), $atts, 'kurabe');
        $d = self::data();
        if (!$d && $a['part'] === 'related') {
            // 表の無い基礎知識ページでも関連欄は出す（kurabe_axes だけで組める・2026-10-01）
            $raw = json_decode((string) get_post_meta(get_the_ID(), self::META, true), true);
            $d = is_array($raw) ? $raw : array('item' => get_the_title());
            wp_enqueue_style('catalog-kurabe-db');
            return self::part_related($d);
        }
        if (!$d) {
            return '';
        }
        wp_enqueue_style('catalog-kurabe-db');
        wp_enqueue_script('catalog-kurabe-db');
        $fn = 'part_' . preg_replace('/[^a-z]/', '', $a['part']);
        if (!method_exists(__CLASS__, $fn)) {
            return '';
        }
        return self::$fn($d);
    }

    /* 絞り込みと縮尺図が使うデータ。本文中の <script> はサイトによって削られるため、
       表・図の要素の data 属性に持たせる */
    private static function data_attr($d)
    {
        return ' data-kurabe="' . esc_attr(wp_json_encode(self::client_data($d), JSON_UNESCAPED_UNICODE)) . '"';
    }

    private static function client_data($d)
    {
        $rows = array();
        foreach ($d['rows'] as $r) {
            $rows[] = array(
                's'  => isset($r['s']) ? $r['s'] : '',
                'p'  => isset($r['p']) ? $r['p'] : null,
                'd'  => isset($r['dims']) ? $r['dims'] : null,
                'rg' => isset($r['range']) ? $r['range'] : null,
            );
        }
        $stores = array();
        foreach (self::stores($d) as $s => $st) {
            $stores[] = array('s' => $s, 'label' => $st['label'], 'color' => $st['color']);
        }
        return array(
            'mode'  => isset($d['mode']) ? $d['mode'] : 'none',
            'item'  => isset($d['item']) ? $d['item'] : '',
            'dimNames' => isset($d['dim_names']) ? $d['dim_names'] : array(),
            'stores' => $stores,
            'rows'  => $rows,
        );
    }

    private static function date_ja($ymd)
    {
        $t = strtotime($ymd);
        return $t ? date('Y年n月j日', $t) : esc_html($ymd);
    }

    private static function part_source($d)
    {
        if (!empty($d['total'])) {                   // 表が抜粋のときは全該当数（build_dataが数えた値）
            $n = (int) $d['total'];
        } else {
            $uniq = array();
            foreach ($d['rows'] as $r) {             // 同じ商品（JAN）は1件として数える
                $uniq[!empty($r['jan']) ? $r['jan'] : $r['u']] = true;
            }
            $n = count($uniq);
        }
        $stores = self::stores($d);
        $names  = array();
        foreach (self::present_stores($d, $stores) as $s) {
            $names[] = self::label($stores, $s);
        }
        $h  = '<dl class="kurabe-source" aria-label="データの出どころ">';
        $h .= '<div><dt>最終確認</dt><dd><time datetime="' . esc_attr($d['checked']) . '">' . esc_html(self::date_ja($d['checked'])) . '</time></dd></div>';
        $unit = isset($d['unit']) ? $d['unit'] : '種';
        $h .= '<div><dt>出典</dt><dd>' . esc_html(implode('・', $names)) . 'の公式通販（' . $n . esc_html($unit) . '）</dd></div>';
        if (!empty($d['source_extra']) && is_array($d['source_extra'])) {
            foreach ($d['source_extra'] as $x) {     // 任意の追加行（{dt,dd} の配列）
                if (isset($x['dt'], $x['dd'])) {
                    $h .= '<div><dt>' . esc_html($x['dt']) . '</dt><dd>' . esc_html($x['dd']) . '</dd></div>';
                }
            }
        }
        $h .= '<div><dt>空欄</dt><dd>公式に記載のない項目は推測で埋めず「記載なし」と表示</dd></div>';
        $h .= '</dl>';
        return $h;
    }

    private static function part_stats($d)
    {
        if (empty($d['stats'])) {
            return '';
        }
        $main = $d['stats'][0];
        $rest = array_slice($d['stats'], 1);
        $stores = self::stores($d);
        $per = array();                              // 店ごとの掲載数（stores[].count＝真の該当数を優先。表が抜粋でも集計は全件）
        foreach (self::present_stores($d, $stores) as $st) {
            $n = isset($stores[$st]['count']) ? (int) $stores[$st]['count'] : 0;
            if (!$n) {
                foreach ($d['rows'] as $r) {
                    if ($r['s'] === $st) {
                        $n++;
                    }
                }
            }
            if ($n) {
                $per[$st] = $n;
            }
        }
        $h  = '<div class="kurabe-stats"><div class="kurabe-stats-main">';
        $h .= '<div class="kurabe-k">' . esc_html($main['k']) . '</div>';
        $h .= '<div class="kurabe-v kurabe-v-main">' . esc_html($main['v']) . '<small>' . esc_html(isset($main['u']) ? $main['u'] : '') . '</small></div>';
        if ($per) {
            $h .= '<div class="kurabe-bar" aria-hidden="true">';
            foreach ($per as $st => $n) {
                $h .= '<span class="kurabe-bar-seg"' . self::color_style($stores, $st, 'flex:' . (int) $n) . '></span>';
            }
            $h .= '</div><div class="kurabe-bar-legend">';
            foreach ($per as $st => $n) {
                $h .= '<span><b class="kurabe-t"' . self::color_style($stores, $st) . '>' . esc_html(self::label($stores, $st)) . '</b> ' . (int) $n . '</span>';
            }
            $h .= '</div>';
            if (count($per) > 1 && array_sum($per) !== (int) $main['v']) {
                $h .= '<div class="kurabe-bar-note">店ごとの数は、同じ商品を各店で数えています。</div>';
            }
        }
        $h .= '</div><div class="kurabe-stats-rest">';
        foreach ($rest as $x) {
            $h .= '<div class="kurabe-stats-row"><span class="kurabe-k">' . esc_html($x['k']) . '</span><span class="kurabe-v">' . esc_html($x['v']) . '<small>' . esc_html(isset($x['u']) ? $x['u'] : '') . '</small></span></div>';
        }
        $h .= '</div></div>';
        return $h;
    }

    private static function part_table($d)
    {
        $mode  = isset($d['mode']) ? $d['mode'] : 'none';
        $cols  = isset($d['cols']) && is_array($d['cols']) ? $d['cols'] : array();
        $label = isset($d['size_label']) ? $d['size_label'] : 'サイズ（cm）';
        $stores = self::stores($d);
        $storecol = isset($d['store_col']) ? $d['store_col'] : '店';
        $namecol  = isset($d['name_col']) ? $d['name_col'] : '商品名';
        $filters  = isset($d['filters']) && is_array($d['filters']) ? $d['filters'] : array();

        $prices  = array();
        foreach ($d['rows'] as $r) {
            if (isset($r['p']) && $r['p'] !== null) {
                $prices[(int) str_replace(',', '', (string) $r['p'])] = true;   // 「5,390」のような文字列でも数として扱う
            }
        }
        ksort($prices);

        $names = array();
        foreach (self::present_stores($d, $stores) as $s) {
            $names[] = self::label($stores, $s);
        }

        $h  = '<div class="kurabe-table" data-mode="' . esc_attr($mode) . '"' . self::data_attr($d) . '>';
        $h .= self::shindan_link();
        $h .= '<p class="kurabe-stamp">' . esc_html(self::date_ja($d['checked'])) . '時点で、' . esc_html(implode('・', $names)) . 'の公式通販に掲載されている情報です。' . esc_html(isset($d['stamp_note']) ? $d['stamp_note'] : '店頭の品ぞろえとは違う場合があります。') . '</p>';
        if (!empty($d['table_note'])) {
            $h .= '<p class="kurabe-stamp">' . esc_html($d['table_note']) . '</p>';
        }

        $h .= '<div class="kurabe-filters">';
        $h .= '<div class="kurabe-fgroup"><span class="kurabe-flabel">' . esc_html($storecol) . '</span><div class="kurabe-chips" data-filter="s">';
        foreach (self::present_stores($d, $stores) as $s) {
            $h .= '<button type="button" class="kurabe-chip kurabe-chip-store kurabe-chip-' . esc_attr(self::slug($stores, $s)) . '"' . self::color_style($stores, $s) . ' data-v="' . esc_attr($s) . '" aria-pressed="true">' . esc_html(self::label($stores, $s)) . '</button>';
        }
        $h .= '</div></div>';
        foreach ($filters as $fl) {                  // 任意の絞り込み軸（{key,label,values}）。rows の f[key] と照合
            if (empty($fl['key']) || empty($fl['values'])) {
                continue;
            }
            $fk = 'x' . preg_replace('/[^a-z0-9]/', '', $fl['key']);
            $h .= '<div class="kurabe-fgroup"><span class="kurabe-flabel">' . esc_html($fl['label']) . '</span><div class="kurabe-chips" data-filter="' . esc_attr($fk) . '">';
            foreach ($fl['values'] as $v) {
                $h .= '<button type="button" class="kurabe-chip" data-v="' . esc_attr($v) . '" aria-pressed="true">' . esc_html($v) . '</button>';
            }
            $h .= '</div></div>';
        }
        if (count($prices) > 1 && count($prices) <= 8) {
            $h .= '<div class="kurabe-fgroup"><span class="kurabe-flabel">価格</span><div class="kurabe-chips" data-filter="p">';
            foreach (array_keys($prices) as $p) {
                $h .= '<button type="button" class="kurabe-chip" data-v="' . esc_attr($p) . '" aria-pressed="true">' . esc_html(number_format($p)) . '円</button>';
            }
            $h .= '</div></div>';
        }
        $fit = isset($d['fit']) ? $d['fit'] : null;
        if ($fit && !empty($fit['inputs'])) {
            $h .= '<div class="kurabe-fgroup"><span class="kurabe-flabel">' . esc_html($fit['label']) . '</span><div class="kurabe-fit">';
            foreach ($fit['inputs'] as $i => $name) {
                if ($i > 0) {
                    $h .= '<span class="kurabe-dim">×</span>';
                }
                $id = 'kurabe-fit-' . $i;
                $h .= '<label for="' . $id . '" class="kurabe-dim">' . esc_html($name) . '</label><input id="' . $id . '" data-i="' . $i . '" type="number" inputmode="decimal" min="0" step="0.5" placeholder="—">';
            }
            $h .= '<span class="kurabe-dim">' . esc_html($fit['unit']) . '</span></div></div>';
        }
        $h .= '</div>';

        $h .= '<p class="kurabe-count" aria-live="polite"><b>' . count($d['rows']) . '</b> 件を表示中</p>';
        $h .= '<div class="kurabe-tablebox"><table><thead><tr>';
        $h .= '<th scope="col">' . esc_html($storecol) . '</th><th scope="col">' . esc_html($namecol) . '</th>';
        if ($mode !== 'none') {
            $h .= '<th scope="col"><button type="button" data-sort="sz">' . esc_html($label) . '</button></th>';
        }
        $h .= '<th scope="col"><button type="button" data-sort="p">価格（税込）</button></th>';
        foreach ($cols as $key => $cl) {
            $sortable = !empty($cl['sort']);
            $h .= '<th scope="col">' . ($sortable ? '<button type="button" data-sort="' . esc_attr($key) . '">' . esc_html($cl['label']) . '</button>' : esc_html($cl['label'])) . '</th>';
        }
        $namelink = !empty($d['name_link']);         // 商品名そのものを公式ページへのリンクにする（右端の「公式」列は出さない）
        $h .= ($namelink ? '' : '<th scope="col">公式</th>') . '</tr></thead><tbody>';

        foreach ($d['rows'] as $i => $r) {
            $rel = !empty($r['aff']) ? 'nofollow sponsored noopener' : 'noopener';
            $attrs = ' data-i="' . $i . '" data-s="' . esc_attr($r['s']) . '" data-p="' . esc_attr(isset($r['p']) ? $r['p'] : '') . '"';
            $attrs .= ' data-sz="' . esc_attr(isset($r['sz']) ? $r['sz'] : '') . '"';
            foreach ($filters as $fl) {
                if (!empty($fl['key'])) {
                    $fk = preg_replace('/[^a-z0-9]/', '', $fl['key']);
                    $attrs .= ' data-x' . $fk . '="' . esc_attr(isset($r['f'][$fl['key']]) ? $r['f'][$fl['key']] : '') . '"';
                }
            }
            foreach ($cols as $key => $cl) {
                if (!empty($cl['sort'])) {
                    $attrs .= ' data-' . esc_attr($key) . '="' . esc_attr(isset($r[$key . '_n']) ? $r[$key . '_n'] : '') . '"';
                }
            }
            $h .= '<tr' . $attrs . '>';
            $h .= '<td class="kurabe-td-store"><span class="kurabe-store kurabe-' . esc_attr(self::slug($stores, $r['s'])) . '"' . self::color_style($stores, $r['s']) . '>' . esc_html(self::label($stores, $r['s'])) . '</span></td>';
            if ($namelink && !empty($r['u'])) {
                $h .= '<td><a class="kurabe-pname kurabe-pname-link" href="' . esc_url($r['u']) . '" target="_blank" rel="' . $rel . '">' . esc_html($r['n']) . '</a>';
            } else {
                $h .= '<td><span class="kurabe-pname">' . esc_html($r['n']) . '</span>';
            }
            if (!empty($r['note'])) {
                $h .= '<span class="kurabe-sub">' . esc_html($r['note']) . '</span>';
            }
            if (!empty($r['sr'])) {                  // シリーズの公開ページがあれば、表の行からサイト内リンク
                $sp = self::series_page($r['sr']);
                if ($sp && (int) $sp['id'] !== (int) get_the_ID()) {
                    $h .= '<a class="kurabe-sub kurabe-series-link" href="' . esc_url($sp['url']) . '">' . esc_html($sp['label']) . 'の全コース</a>';
                }
            }
            if (!empty($r['same'])) {
                $h .= '<span class="kurabe-sub"><b>' . esc_html(implode('・', $r['same'])) . '</b>でも同じ商品を販売' . (!empty($r['jan']) ? '（JAN ' . esc_html($r['jan']) . '）' : '') . '</span>';
            }
            $h .= '</td>';
            if ($mode !== 'none') {
                $h .= '<td class="kurabe-num" data-label="' . esc_attr($label) . '">' . (!empty($r['size_txt']) ? esc_html($r['size_txt']) : '<span class="kurabe-dim">記載なし</span>') . '</td>';
            }
            if (isset($r['p']) && $r['p'] !== null) {
                $h .= '<td class="kurabe-num" data-label="価格（税込）">';
                if (!empty($r['p_regular'])) {
                    $h .= '<s class="kurabe-was">通常' . esc_html($r['p_regular']) . '円</s> ';
                }
                $h .= '<span class="kurabe-price">' . esc_html(is_numeric($r['p']) ? number_format((float) $r['p']) : $r['p']) . '円</span></td>';
            } else {
                $h .= '<td class="kurabe-num" data-label="価格（税込）"><span class="kurabe-dim">記載なし</span></td>';
            }
            foreach ($cols as $key => $cl) {
                $v = isset($r[$key]) ? $r[$key] : '';
                $from = isset($r['from'][$key]) ? $r['from'][$key] : '';
                $h .= '<td class="' . (!empty($cl['sort']) ? 'kurabe-num' : 'kurabe-text') . '" data-label="' . esc_attr($cl['label']) . '">';
                $h .= $v !== '' && $v !== null ? esc_html($v) . ($from ? '<span class="kurabe-sub">' . esc_html($from) . 'の掲載値</span>' : '') : '<span class="kurabe-dim">記載なし</span>';
                $h .= '</td>';
            }
            if (!$namelink) {
                $h .= '<td data-label="公式"><a href="' . esc_url($r['u']) . '" target="_blank" rel="' . $rel . '">' . esc_html(isset($r['u_label']) ? $r['u_label'] : '商品ページ') . '</a></td>';
            }
            $h .= '</tr>';
        }
        $h .= '</tbody></table></div>';
        $h .= '<p class="kurabe-empty" hidden>条件に合う商品がありません。条件をゆるめてみてください。</p>';
        $h .= '</div>';
        return $h;
    }

    private static function part_scale($d)
    {
        if (empty($d['mode']) || $d['mode'] === 'none') {
            return '';
        }
        if ($d['mode'] === 'range') {
            return self::range_guide($d);
        }
        $item = isset($d['item']) ? $d['item'] : '';
        return '<div class="kurabe-scale"' . self::data_attr($d) . '><div class="kurabe-scale-grid" role="img" aria-label="' . esc_attr($item) . 'のサイズを同じ縮尺で並べた図"></div><div class="kurabe-legend"></div></div>';
    }

    /* 伸縮する品目（突っ張り棒など）の早見表：取り付けたい幅ごとに、各社で何円から何種あるか */
    private static function range_guide($d)
    {
        $rows = array();
        $max = 0;
        foreach ($d['rows'] as $r) {
            if (empty($r['range']) || mb_strpos($r['n'], '縦') !== false) {
                continue;          // 床と天井の間に立てる縦型は横幅の比較に入れない
            }
            $rows[] = $r;
            $max = max($max, $r['range'][1]);
        }
        if (!$rows) {
            return '';
        }
        $steps = array_values(array_filter(array(10, 15, 20, 25, 30, 40, 50, 60, 70, 80, 90, 100, 110, 120, 130, 150, 170, 190, 200, 210, 220, 250, 300),
            function ($w) use ($max) { return $w <= $max; }));
        $storedef = self::stores($d);
        $stores = array();
        foreach (array_keys($storedef) as $s) {
            foreach ($rows as $r) {
                if ($r['s'] === $s) {
                    $stores[] = $s;
                    break;
                }
            }
        }
        $h  = '<div class="kurabe-guide"><table><thead><tr><th scope="col">取り付けたい幅</th>';
        foreach ($stores as $s) {
            $h .= '<th scope="col"><span class="kurabe-store kurabe-' . esc_attr(self::slug($storedef, $s)) . '"' . self::color_style($storedef, $s) . '>' . esc_html(self::label($storedef, $s)) . '</span></th>';
        }
        $h .= '</tr></thead><tbody>';
        foreach ($steps as $w) {
            $h .= '<tr><th scope="row">' . $w . 'cm</th>';
            foreach ($stores as $s) {
                $hit = array_filter($rows, function ($r) use ($s, $w) {
                    return $r['s'] === $s && $r['range'][0] <= $w && $w <= $r['range'][1];
                });
                if (!$hit) {
                    $h .= '<td class="kurabe-dim">なし</td>';
                    continue;
                }
                $ps = array_map(function ($r) { return (int) $r['p']; }, $hit);
                $h .= '<td><b>' . min($ps) . '円</b>から<span class="kurabe-sub">' . count($hit) . '種</span></td>';
            }
            $h .= '</tr>';
        }
        $h .= '</tbody></table></div>';
        return $h;
    }

    /* 関連する品目のリンク（公開済みのページだけを拾う。公開が増えれば自動で増える）
       投稿メタ: kurabe_item＝品目名 / kurabe_group＝売り場の小分類 /
                 kurabe_parent＝親の品目名 / kurabe_count＝掲載種数 */
    private static function linked_posts($key, $value, $exclude, $limit)
    {
        if ($value === '' || $value === null) {
            return array();
        }
        $status = array('publish');
        if (is_preview() && current_user_can('edit_posts')) {
            $status[] = 'draft';      // 下書きのプレビューでは下書き同士もつないで確認できるようにする
        }
        return get_posts(array(
            'post_type'        => 'post',
            'post_status'      => $status,
            'posts_per_page'   => $limit,
            'post__not_in'     => array((int) $exclude),
            'meta_key'         => $key,
            'meta_value'       => $value,
            'orderby'          => 'title',
            'order'            => 'ASC',
            'suppress_filters' => true,
        ));
    }

    private static function link_list($posts)
    {
        $h = '<ul class="kurabe-links">';
        foreach ($posts as $p) {
            $item  = get_post_meta($p->ID, 'kurabe_item', true);
            $count = get_post_meta($p->ID, 'kurabe_count', true);
            $h .= '<li><a href="' . esc_url(get_permalink($p)) . '">' . esc_html($item ? $item : get_the_title($p)) . '</a>'
                . ($count ? '<span>' . (int) $count . '種</span>' : '') . '</li>';
        }
        return $h . '</ul>';
    }

    /* ---------- 軸（予算・ジャンル・シーン・シリーズ）で張る内部リンク ----------
       投稿メタ kurabe_axes（JSON）: {"kind":"budget|genre|scene|combo|series|issuer|guide",
         "budget":5000, "genre":"グルメ", "scene":"香典返し", "series":"リンベル|プレゼンテージ",
         "issuer":"リンベル", "pmin":3080, "pmax":55000, "label":"プレゼンテージ"}
       リンク先は公開済みページだけ（プレビュー中は下書きも）。文言は相手ページの kurabe_item */

    private static $axes_cache = null;

    private static function all_axes()
    {
        if (self::$axes_cache !== null) {
            return self::$axes_cache;
        }
        $status = array('publish');
        if (function_exists('is_preview') && is_preview() && current_user_can('edit_posts')) {
            $status[] = 'draft';
        }
        $posts = get_posts(array(
            'post_type' => 'post', 'post_status' => $status, 'posts_per_page' => -1,
            'meta_key' => 'kurabe_axes', 'suppress_filters' => true, 'fields' => 'ids',
        ));
        $out = array();
        foreach ($posts as $pid) {
            $a = json_decode((string) get_post_meta($pid, 'kurabe_axes', true), true);
            if (!is_array($a) || empty($a['kind'])) {
                continue;
            }
            $a['id'] = (int) $pid;
            $a['url'] = get_permalink($pid);
            $item = get_post_meta($pid, 'kurabe_item', true);
            $a['name'] = $item ? $item : get_the_title($pid);
            $out[] = $a;
        }
        return self::$axes_cache = $out;
    }

    private static function series_page($key)
    {
        foreach (self::all_axes() as $a) {
            if ($a['kind'] === 'series' && isset($a['series']) && $a['series'] === $key) {
                return array('id' => $a['id'], 'url' => $a['url'], 'label' => !empty($a['label']) ? $a['label'] : $a['name']);
            }
        }
        return null;
    }

    private static function pick($fn, $limit = 8)
    {
        $out = array();
        foreach (self::all_axes() as $a) {
            if ($fn($a)) {
                $out[] = $a;
            }
        }
        return array_slice($out, 0, $limit);
    }

    private static function axis_list($items)
    {
        $h = '<ul class="kurabe-links">';
        foreach ($items as $a) {
            $h .= '<li><a href="' . esc_url($a['url']) . '">' . esc_html($a['name']) . '</a></li>';
        }
        return $h . '</ul>';
    }

    private static function related_by_axes($me, $d)
    {
        $id = get_the_ID();
        $not_me = function ($a) use ($id) { return $a['id'] !== (int) $id; };
        $by_budget = function ($x, $y) { return (isset($x['budget']) ? $x['budget'] : 0) - (isset($y['budget']) ? $y['budget'] : 0); };
        $groups = array();
        $k = $me['kind'];
        $b = isset($me['budget']) ? (int) $me['budget'] : 0;
        $g = isset($me['genre']) ? $me['genre'] : '';
        $s = isset($me['scene']) ? $me['scene'] : '';

        if ($k === 'budget') {
            $bud = self::pick(function ($a) use ($not_me) { return $a['kind'] === 'budget' && $not_me($a); }, 99);
            usort($bud, $by_budget);
            $lower = array_values(array_filter($bud, function ($a) use ($b) { return $a['budget'] < $b; }));
            $upper = array_values(array_filter($bud, function ($a) use ($b) { return $a['budget'] > $b; }));
            $near = array_merge(array_slice($lower, -2), array_slice($upper, 0, 2));
            $groups['前後の予算'] = $near;
            $groups['この予算で用途・ジャンルを絞る'] = self::pick(function ($a) use ($b, $not_me) {
                return $a['kind'] === 'combo' && isset($a['budget']) && (int) $a['budget'] === $b && $not_me($a);
            });
        } elseif ($k === 'genre' || $k === 'scene') {
            $key = $k;
            $val = $k === 'genre' ? $g : $s;
            $sub = self::pick(function ($a) use ($key, $val, $not_me) {
                return $a['kind'] === 'combo' && isset($a[$key]) && $a[$key] === $val && $not_me($a);
            }, 99);
            usort($sub, $by_budget);
            $groups['予算で絞る'] = array_slice($sub, 0, 8);
            $groups[$k === 'genre' ? 'ほかのジャンル' : 'ほかのシーン'] = self::pick(function ($a) use ($key, $not_me) {
                return $a['kind'] === $key && $not_me($a);
            }, 10);
        } elseif ($k === 'combo') {
            $groups['もっと広く比べる'] = self::pick(function ($a) use ($b, $g, $s) {
                return ($a['kind'] === 'budget' && $b && isset($a['budget']) && (int) $a['budget'] === $b)
                    || ($a['kind'] === 'genre' && $g && isset($a['genre']) && $a['genre'] === $g)
                    || ($a['kind'] === 'scene' && $s && isset($a['scene']) && $a['scene'] === $s);
            });
            $sib = self::pick(function ($a) use ($b, $g, $s, $not_me) {
                if ($a['kind'] !== 'combo' || !$not_me($a)) {
                    return false;
                }
                $sameB = $b && isset($a['budget']) && (int) $a['budget'] === $b;
                $sameG = $g && isset($a['genre']) && $a['genre'] === $g;
                $sameS = $s && isset($a['scene']) && $a['scene'] === $s;
                return $sameB || $sameG || $sameS;
            }, 99);
            usort($sib, $by_budget);
            $groups['近い組み合わせ'] = array_slice($sib, 0, 8);
        } elseif ($k === 'series' || $k === 'issuer') {
            $iss = isset($me['issuer']) ? $me['issuer'] : '';
            $groups['同じ発行会社のシリーズ'] = self::pick(function ($a) use ($iss, $not_me) {
                return in_array($a['kind'], array('series', 'issuer'), true) && isset($a['issuer']) && $a['issuer'] === $iss && $not_me($a);
            }, 10);
            $lo = isset($me['pmin']) ? (int) $me['pmin'] : 0;
            $hi = isset($me['pmax']) ? (int) $me['pmax'] : 0;
            if ($lo && $hi) {
                $bud = self::pick(function ($a) use ($lo, $hi) {
                    return $a['kind'] === 'budget' && isset($a['budget']) && $a['budget'] >= $lo * 0.8 && $a['budget'] <= $hi;
                }, 99);
                usort($bud, $by_budget);
                $groups['このシリーズのコースがある予算'] = array_slice($bud, 0, 8);
            }
        } elseif ($k === 'guide') {
            // 値段の調べ方などから、コース名と価格の一覧があるシリーズ・ブランド別のページへつなぐ
            $groups['シリーズ・ブランド別の価格一覧'] = self::pick(function ($a) {
                return in_array($a['kind'], array('series', 'issuer'), true);
            }, 60);
            $groups['ほかの基礎知識'] = self::pick(function ($a) use ($not_me) { return $a['kind'] === 'guide' && $not_me($a); }, 10);
        }
        $groups = array_filter($groups);
        if (!$groups) {
            return '';
        }
        $name = !empty($me['name']) ? $me['name'] : (isset($d['item']) ? $d['item'] : '');
        // シリーズ名「プレゼンテージ カタログギフト」は見出しで「プレゼンテージのカタログギフト」と読ませる
        $name = preg_replace('/\s+カタログギフト$/u', 'のカタログギフト', $name);
        $h = '<h3 class="wp-block-heading">' . esc_html($name) . 'とあわせて見たいページ</h3><div class="kurabe-related">';
        foreach ($groups as $label => $items) {
            $h .= '<p class="kurabe-related-label">' . esc_html($label) . '</p>' . self::axis_list($items);
        }
        return $h . '</div>';
    }

    private static function part_related($d)
    {
        $me = json_decode((string) get_post_meta(get_the_ID(), 'kurabe_axes', true), true);
        if (is_array($me) && !empty($me['kind'])) {
            $item = get_post_meta(get_the_ID(), 'kurabe_item', true);
            $me['name'] = $item ? $item : (isset($d['item']) ? $d['item'] : '');
            return self::related_by_axes($me, $d);
        }
        $id     = get_the_ID();
        $item   = get_post_meta($id, 'kurabe_item', true);
        $group  = get_post_meta($id, 'kurabe_group', true);
        $parent = get_post_meta($id, 'kurabe_parent', true);

        $pair = array_merge(
            $parent ? self::linked_posts('kurabe_item', $parent, $id, 1) : array(),
            $item ? self::linked_posts('kurabe_parent', $item, $id, 8) : array()
        );
        $seen = wp_list_pluck($pair, 'ID');
        $same = array_values(array_filter(self::linked_posts('kurabe_group', $group, $id, 16), function ($p) use ($seen) {
            return !in_array($p->ID, $seen, true);
        }));
        if (!$pair && !$same) {
            return '';
        }
        $name = $item ? $item : (isset($d['item']) ? $d['item'] : '');
        $h = '<h3 class="wp-block-heading">' . esc_html($name) . 'とあわせて比べたい品目</h3><div class="kurabe-related">';
        if ($pair) {
            $h .= '<p class="kurabe-related-label">一緒に使う品目</p>' . self::link_list($pair);
        }
        if ($same) {
            $h .= '<p class="kurabe-related-label">同じ売り場の品目</p>' . self::link_list($same);
        }
        return $h . '</div>';
    }

    /* カテゴリー・店名タグの一覧ページ：品目×各社の掲載数の表
       公開済みの比較ページだけを拾う。店名タグのページはその店の掲載数の多い順 */
    public static function archive_table()
    {
        if (!(is_category() || is_tag()) || is_paged()) {
            return;
        }
        $term = get_queried_object();
        if (!$term || empty($term->term_id)) {
            return;
        }
        echo self::list_html(array('taxonomy' => $term->taxonomy, 'term' => $term->term_id));
    }

    /* ---------- TOPページの部品 [kurabe_top part="stats|showcase|groups|brands"] ----------
       100均くらべのTOP（吉村さんが選んだB案）と同じ並び。公開済みの比較ページだけを使い、
       編集できる人がプレビューで見るときだけ下書きも含める */

    private static function top_posts()
    {
        $status = current_user_can('edit_posts') ? array('publish', 'draft', 'pending', 'future') : array('publish');
        return get_posts(array(
            'post_type'        => 'post',
            'post_status'      => $status,
            'posts_per_page'   => -1,
            'meta_key'         => 'kurabe_axes',
            'suppress_filters' => true,
        ));
    }

    public static function top_shortcode($atts)
    {
        $a = shortcode_atts(array('part' => 'groups', 'max' => 8, 'item' => '', 'courses' => '', 'issuers' => '',
                                  'order' => '予算別,シーン別,ジャンル別,ブランド別,シリーズ,基礎知識'), $atts, 'kurabe_top');
        wp_enqueue_style('catalog-kurabe-db');
        if ($a['part'] === 'stats') {
            // 数字はDBから入稿時に計算して渡す（ページをまたいだ重複を除いたコース数）。
            // 100均くらべで「数字は何を載せているかだけ」「語の途中で折り返さないよう短く1行」と指定（2026-09-30）
            return '<p class="kurabe-top-stats"><b>' . esc_html($a['courses']) . '</b>コースを掲載</p>';
        }
        $posts = self::top_posts();
        if (!$posts) {
            return '';
        }
        if ($a['part'] === 'showcase') {
            return self::top_showcase($posts, $a['item']);
        }
        if ($a['part'] === 'brands') {
            return self::top_brands($posts);
        }
        if ($a['part'] === 'sidebox') {
            return self::top_sidebox($a['courses']);
        }
        return self::top_groups($posts, max(1, (int) $a['max']), array_map('trim', explode(',', $a['order'])));
    }

    /* サイドバーの「サイトの強み」の箱（100均くらべの [kurabe_top part=sidebox] と同じ形・2026-10-01 吉村さん「まねして」）
       コース数はTOPの数字と同じく入稿時にDBから計算して courses で渡す。締めは検索窓と診断への入口 */
    private static function top_sidebox($courses)
    {
        $ico = array(
            'box'   => '<path d="M4 8l8-4 8 4v9l-8 4-8-4z"/><path d="M4 8l8 4 8-4M12 12v9"/>',
            'shop'  => '<path d="M3 9h18l-1.5-5h-15z"/><path d="M5 9v11h14V9M10 20v-6h4v6"/>',
            'form'  => '<path d="M4 4h10v16H4z"/><path d="M16 7h4v13h-4M7 8h4M7 12h4"/>',
            'check' => '<path d="M6 3h9l4 4v14H6z"/><path d="M9 13l2 2 4-4"/>',
        );
        $labels = '';
        foreach (array('リンベル' => '#8E2A3B', 'ハーモニック' => '#2E5E8C', 'シャディ' => '#1F7A6B', '大和' => '#6B5B3E') as $n => $c) {
            $labels .= '<i class="kurabe-store" style="--kurabe-c:' . $c . '">' . esc_html($n) . '</i>';
        }
        $rows = array(
            array('box', '<span>公式通販から集めた</span>', ($courses !== '' ? $courses : '—') . 'コース'),
            array('shop', '<span class="kurabe-sidebox-labels">' . $labels . '</span>', '各社を横並び'),
            array('form', '<span>冊子・カード・eギフトは</span>', '形態ごとに比較'),
            array('check', '<span>公式に無い値は</span>', '推測しない'),
        );
        $logo = wp_get_attachment_image_url((int) get_theme_mod('custom_logo'), 'medium');
        $h  = '<div class="kurabe-sidebox">';   // 箱の一言は不要（吉村さん 2026-10-01「いらない」）。ロゴから始める
        $h .= $logo ? '<p class="kurabe-sidebox-logo"><img src="' . esc_url($logo) . '" alt="カタログギフトくらべ" width="220" height="55" loading="lazy"></p>' : '';
        foreach ($rows as $r) {
            $h .= '<div class="kurabe-sidebox-row"><svg viewBox="0 0 24 24" aria-hidden="true">' . $ico[$r[0]] . '</svg><div>' . $r[1] . '<b>' . esc_html($r[2]) . '</b></div></div>';
        }
        $h .= '<p class="kurabe-sidebox-q">カタログ名で調べる</p>'
            . '<form class="kurabe-sidebox-search" role="search" method="get" action="' . esc_url(home_url('/')) . '">'
            . '<input type="search" name="s" placeholder="例：プレゼンテージ" aria-label="サイト内検索">'
            . '<button type="submit">検索</button></form>';
        $sd = get_page_by_path('shindan');
        if ($sd && $sd->post_status === 'publish') {
            $h .= '<p class="kurabe-sidebox-go"><a href="' . esc_url(get_permalink($sd)) . '">用途と予算から選ぶ<b>カタログギフト診断</b></a></p>';
        }
        return $h . '</div>';
    }

    /* 見本：1つの比較ページで、発行会社ごとの「いちばん多い掲載点数」を各社の色の棒で並べる（ポイント制は除く） */
    private static function top_showcase($posts, $item)
    {
        $post = null;
        foreach ($posts as $p) {
            if (get_post_meta($p->ID, 'kurabe_item', true) === $item) {
                $post = $p;
                break;
            }
        }
        $d = $post ? self::data($post->ID) : null;
        if (!$d) {
            return '';
        }
        $stores = self::stores($d);
        $val = array();
        foreach ($d['rows'] as $r) {
            if (!isset($r['items_n']) || !is_numeric($r['items_n']) || mb_strpos($r['n'], 'ポイント') !== false) {
                continue;
            }
            $val[$r['s']] = max(isset($val[$r['s']]) ? $val[$r['s']] : 0, (int) $r['items_n']);
        }
        if (!$val) {
            return '';
        }
        arsort($val);
        $top = max($val);
        $h = '<div class="kurabe-showcase"><p class="kurabe-showcase-head">例：' . esc_html($item) . '　<span>発行会社ごとのいちばん多い掲載点数</span></p>';
        foreach (array_slice($val, 0, 5, true) as $s => $v) {   // 上位5社（体験ギフトの数点の棒まで並ぶと見本として読みにくい）
            $w = $top ? max(8, round($v / $top * 100)) : 0;
            $h .= '<div class="kurabe-showcase-row"><span class="kurabe-showcase-store kurabe-t"' . self::color_style($stores, $s) . '>' . esc_html(self::label($stores, $s)) . '</span>'
                . '<span class="kurabe-showcase-bar"><i' . self::color_style($stores, $s, 'width:' . $w . '%') . '></i></span>'
                . '<span class="kurabe-showcase-v">' . number_format($v) . '<small>点</small></span></div>';
        }
        $n = isset($d['courses']) ? (int) $d['courses'] : count($d['rows']);
        $h .= '<a class="kurabe-showcase-go" href="' . esc_url(get_permalink($post)) . '">' . esc_html($item) . 'の' . $n . 'コースを1枚の表で見る</a></div>';
        return $h;
    }

    /* TOPのカテゴリの枠の見出し（カテゴリ名だけだと何の予算別か分からないので「カタログギフト」を入れる） */
    private static $GROUP_HEAD = array(
        '予算別' => '予算別のカタログギフト', 'シーン別' => 'シーン別のカタログギフト', 'ジャンル別' => 'ジャンル別のカタログギフト',
        'ブランド別' => 'ブランド別のカタログギフト', 'シリーズ' => 'シリーズ別のカタログギフト', '基礎知識' => 'カタログギフトの基礎知識',
    );

    /* カテゴリ（予算別・シーン別…）ごとにコンテナで囲み、比較ページをコース数の多い順に並べる */
    private static function top_groups($posts, $max, $order)
    {
        $by = array();
        foreach ($posts as $p) {
            $cats = get_the_category($p->ID);
            if (!$cats) {
                continue;
            }
            $c = $cats[0];
            if (!isset($by[$c->name])) {
                $by[$c->name] = array('term' => $c, 'rows' => array());
            }
            $by[$c->name]['rows'][] = array(
                'url'   => get_permalink($p),
                'item'  => get_post_meta($p->ID, 'kurabe_item', true) ?: get_the_title($p),
                'total' => (int) get_post_meta($p->ID, 'kurabe_count', true),
                'axes'  => json_decode((string) get_post_meta($p->ID, 'kurabe_axes', true), true),
            );
        }
        $h = '<div class="kurabe-genres">';
        foreach ($order as $name) {
            if (empty($by[$name])) {
                continue;
            }
            $g = $by[$name];
            // 予算別は金額の順、それ以外はコース数の多い順
            usort($g['rows'], function ($x, $y) use ($name) {
                if ($name === '予算別') {
                    $bx = isset($x['axes']['budget']) ? (int) $x['axes']['budget'] : 0;
                    $by_ = isset($y['axes']['budget']) ? (int) $y['axes']['budget'] : 0;
                    return $bx === $by_ ? $y['total'] - $x['total'] : $bx - $by_;
                }
                return $y['total'] - $x['total'];
            });
            $link = get_term_link($g['term']);
            $n = count($g['rows']);
            $h .= '<section class="kurabe-genre wp-block-dbp-container padding-block:30 padding-inline:30 dbp-container">'
                // 見出し（h3）はカテゴリ名だけ。本数は見出しの外に置く（見出しの文字が「予算別比較表 21本」にならないように・2026-10-01）
                . '<div class="dbp-container__inner"><div class="kurabe-genre-name"><h3><a href="' . esc_url(is_wp_error($link) ? '' : $link) . '">'
                . esc_html(isset(self::$GROUP_HEAD[$name]) ? self::$GROUP_HEAD[$name] : $name) . '</a></h3><span>比較表 ' . $n . '本</span></div><ul>';
            foreach (array_slice($g['rows'], 0, $max) as $r) {
                $h .= '<li><a href="' . esc_url($r['url']) . '">' . esc_html(preg_replace('/\s+カタログギフト$/u', 'のカタログギフト', $r['item'])) . '</a>'
                    . ($r['total'] ? '<span>' . $r['total'] . 'コース</span>' : '') . '</li>';
            }
            $h .= '</ul>';
            if ($n > $max && !is_wp_error($link)) {
                $h .= '<a class="kurabe-genre-more" href="' . esc_url($link) . '">' . esc_html($name) . 'の比較表をすべて見る</a>';
            }
            $h .= '</div></section>';
        }
        return $h . '</div>';
    }

    /* 発行会社・ブランド別のページ（kind=issuer）をカードで並べる。色はそのページの比較データの発行会社の色 */
    private static function top_brands($posts)
    {
        $cards = array();
        foreach ($posts as $p) {
            $ax = json_decode((string) get_post_meta($p->ID, 'kurabe_axes', true), true);
            if (!is_array($ax) || (isset($ax['kind']) ? $ax['kind'] : '') !== 'issuer') {
                continue;
            }
            $d = self::data($p->ID);
            $stores = $d ? self::stores($d) : array();
            $iss = isset($ax['issuer']) ? $ax['issuer'] : '';
            $color = isset($stores[$iss]) ? self::color_style($stores, $iss) : '';
            if (!$color && $stores) {
                $first = array_keys($stores)[0];
                foreach ($stores as $k => $st) {
                    if (!empty($st['count'])) {
                        $first = $k;
                        break;
                    }
                }
                $color = self::color_style($stores, $first);
            }
            $cards[] = array('url' => get_permalink($p), 'name' => preg_replace('/百貨店$/u', '', $iss), 'label' => isset($ax['label']) ? $ax['label'] : $iss,
                             'n' => (int) get_post_meta($p->ID, 'kurabe_count', true), 'style' => $color);
        }
        usort($cards, function ($x, $y) {
            return $y['n'] - $x['n'];
        });
        $h = '<div class="kurabe-top-stores">';
        foreach ($cards as $c) {
            $h .= '<a class="kurabe-top-store"' . $c['style'] . ' href="' . esc_url($c['url']) . '">'
                . '<span class="kurabe-store"' . $c['style'] . '>' . esc_html($c['name']) . '</span>'
                . '<span class="kurabe-top-store-n">' . number_format($c['n']) . '<small>コース</small></span>'
                . '<span class="kurabe-top-store-go">' . esc_html($c['label']) . 'へ</span></a>';
        }
        return $h . '</div>';
    }

    public static function list_shortcode($atts)
    {
        $a = shortcode_atts(array('category' => '', 'tag' => ''), $atts, 'kurabe_list');
        if ($a['tag']) {
            $t = get_term_by('slug', $a['tag'], 'post_tag');
        } else {
            $t = get_term_by('slug', $a['category'], 'category');
        }
        return $t ? self::list_html(array('taxonomy' => $t->taxonomy, 'term' => $t->term_id)) : '';
    }

    private static function list_html($q)
    {
        $posts = get_posts(array(
            'post_type'        => 'post',
            'post_status'      => 'publish',
            'posts_per_page'   => -1,
            'meta_key'         => 'kurabe_item',
            'tax_query'        => array(array('taxonomy' => $q['taxonomy'], 'field' => 'term_id', 'terms' => (int) $q['term'])),
            'suppress_filters' => true,
        ));
        if (!$posts) {
            return '';
        }
        /* 店定義は各ページの kurabe_data の stores をマージして使う（先勝ち） */
        $storemap = array();
        $rows = array();
        foreach ($posts as $p) {
            $d = self::data($p->ID);
            if ($d) {
                $storemap += self::stores($d);
            }
            $per = json_decode((string) get_post_meta($p->ID, 'kurabe_stores', true), true);
            $rows[] = array(
                'url'   => get_permalink($p),
                'item'  => get_post_meta($p->ID, 'kurabe_item', true),
                'total' => (int) get_post_meta($p->ID, 'kurabe_count', true),
                'per'   => is_array($per) ? $per : array(),
            );
        }
        /* 列＝定義済みの店＋（定義に無いが集計に出てくる店） */
        $cols = $storemap;
        foreach ($rows as $r) {
            foreach (array_keys($r['per']) as $name) {
                if (!isset($cols[$name])) {
                    $cols[$name] = array('slug' => 'other', 'label' => $name, 'color' => '');
                }
            }
        }
        $sort_store = '';
        if ($q['taxonomy'] === 'post_tag') {
            $slug = get_term((int) $q['term'])->slug;
            foreach ($cols as $name => $st) {
                if ($st['slug'] === $slug) {
                    $sort_store = $name;
                    break;
                }
            }
        }
        /* 並び：予算別のページは金額の順、それ以外はコース数の多い順（店タグの一覧はその店の件数の順） */
        foreach ($posts as $k => $p) {
            $ax = json_decode((string) get_post_meta($p->ID, 'kurabe_axes', true), true);
            $rows[$k]['budget'] = is_array($ax) && isset($ax['budget']) ? (int) $ax['budget'] : 0;
        }
        usort($rows, function ($a, $b) use ($sort_store) {
            if ($sort_store) {
                $x = isset($a['per'][$sort_store]) ? $a['per'][$sort_store] : 0;
                $y = isset($b['per'][$sort_store]) ? $b['per'][$sort_store] : 0;
                if ($x !== $y) {
                    return $y - $x;
                }
            }
            if ($a['budget'] && $b['budget'] && $a['budget'] !== $b['budget']) {
                return $a['budget'] - $b['budget'];
            }
            return $b['total'] - $a['total'];
        });
        wp_enqueue_style('catalog-kurabe-db');
        /* 発行会社が15社あり、会社ごとの列にすると比較ページ名が数文字で折り返す（2026-10-01）。
           「比較ページ・コース数・載っている発行会社（コース数の多い順のバッジ）」の3列にする */
        $budgeted = count(array_filter($rows, function ($r) { return $r['budget'] > 0; })) === count($rows);
        $lead = $sort_store
            ? esc_html($cols[$sort_store]['label']) . 'のカタログギフトが載っている比較ページを、件数の多い順に並べています。'
            : ($budgeted ? '各社の公式通販のカタログギフトを比べたページを、予算の順に並べています。'
                         : '各社の公式通販のカタログギフトを比べたページを、載っているコースの多い順に並べています。');
        $h  = '<div class="kurabe-list"><p class="kurabe-list-lead">' . '比較表' . count($rows) . '本。' . $lead . '</p>';
        $h .= '<div class="kurabe-tablebox"><table class="kurabe-list-table"><thead><tr><th scope="col">比較ページ</th>'
            . '<th scope="col" class="kurabe-c">コース数</th><th scope="col">載っている発行会社</th></tr></thead><tbody>';
        foreach ($rows as $r) {
            $per = $r['per'];
            arsort($per);
            $badges = '';
            foreach (array_slice($per, 0, 6, true) as $st => $n) {
                $label = isset($cols[$st]) ? $cols[$st]['label'] : $st;
                $badges .= '<span class="kurabe-store"' . self::color_style($cols, $st) . '>' . esc_html($label) . '</span> ';
            }
            if (count($per) > 6) {
                $badges .= '<span class="kurabe-list-more">ほか' . (count($per) - 6) . '社</span>';
            }
            $name = preg_replace('/\s+カタログギフト$/u', 'のカタログギフト', $r['item']);
            $h .= '<tr><td class="kurabe-list-name"><a href="' . esc_url($r['url']) . '">' . esc_html($name) . '</a></td>'
                . '<td class="kurabe-c kurabe-num">' . ($r['total'] ? $r['total'] . 'コース' : '—') . '</td>'
                . '<td class="kurabe-list-badges">' . $badges . '</td></tr>';
        }
        return $h . '</tbody></table></div></div>';
    }

    private static function part_shop($d)
    {
        if (empty($d['shop'])) {
            return '';
        }
        $item = isset($d['item']) ? $d['item'] : '';
        $h = '<div class="kurabe-shop" role="list">';
        foreach ($d['shop'] as $s) {
            $q = isset($s['q']) ? $s['q'] : $item;
            $h .= '<div class="kurabe-shop-row" role="listitem"><div class="kurabe-shop-cond"><b>' . esc_html($s['b']) . '</b>';
            if (!empty($s['note'])) {
                $h .= '<span>' . esc_html($s['note']) . '</span>';
            }
            $h .= '</div><div class="kurabe-shop-links">';
            if (!empty($s['links']) && is_array($s['links'])) {   // 公式通販など任意のリンク（{label,url}）
                foreach ($s['links'] as $l) {
                    if (!empty($l['url']) && !empty($l['label'])) {
                        $h .= '<a href="' . esc_url($l['url']) . '" target="_blank" rel="nofollow sponsored noopener">' . esc_html($l['label']) . '</a>';
                    }
                }
            } else {
                $h .= '<a href="' . esc_url(self::amazon_url($q)) . '" target="_blank" rel="nofollow sponsored noopener">Amazonで探す</a>';
                $h .= '<a href="' . esc_url(self::rakuten_url($q)) . '" target="_blank" rel="nofollow sponsored noopener">楽天市場で探す</a>';
            }
            $h .= '</div></div>';
        }
        $h .= '</div><p class="kurabe-adnote">上のリンクは広告（アフィリエイト）を含みます。価格と在庫はリンク先でご確認ください。</p>';
        return $h;
    }

    /* ---------- 設定画面 ---------- */

    public static function admin_menu()
    {
        add_options_page('カタログギフトくらべ 比較データ', 'カタログギフトくらべ', 'manage_options', 'catalog-kurabe-db', array(__CLASS__, 'settings_page'));
    }

    public static function admin_init()
    {
        register_setting('catalog_kurabe_db', self::OPT, array(
            'type'              => 'array',
            'sanitize_callback' => function ($v) {
                return array(
                    'amazon_tag' => isset($v['amazon_tag']) ? sanitize_text_field($v['amazon_tag']) : '',
                    'rakuten_id' => isset($v['rakuten_id']) ? sanitize_text_field($v['rakuten_id']) : '',
                );
            },
        ));
    }

    public static function settings_page()
    {
        $o = get_option(self::OPT, array());
        list($amazon, $rakuten) = self::ids();
        echo '<div class="wrap"><h1>カタログギフトくらべ 比較データ</h1>';
        echo '<p>空欄のときはオートインサーター（またはRinker）の設定からIDを読みます。いま使われているID：Amazon <code>' . esc_html($amazon ? $amazon : '未設定') . '</code> ／ 楽天 <code>' . esc_html($rakuten ? '設定あり' : '未設定') . '</code></p>';
        echo '<form method="post" action="options.php">';
        settings_fields('catalog_kurabe_db');
        echo '<table class="form-table"><tr><th>AmazonトラッキングID</th><td><input class="regular-text" name="' . self::OPT . '[amazon_tag]" value="' . esc_attr(isset($o['amazon_tag']) ? $o['amazon_tag'] : '') . '"></td></tr>';
        echo '<tr><th>楽天アフィリエイトID</th><td><input class="regular-text" name="' . self::OPT . '[rakuten_id]" value="' . esc_attr(isset($o['rakuten_id']) ? $o['rakuten_id'] : '') . '"></td></tr></table>';
        submit_button();
        echo '</form></div>';
    }
}

Catalog_Kurabe_Db::boot();
