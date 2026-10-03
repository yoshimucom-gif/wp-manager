<?php
/**
 * Plugin Name: スーツくらべ 比較データ表示
 * Description: スーツ量販店の比較データ（各社の公式通販から取得した仕様）を投稿メタ kurabe_data に保存し、ショートコード [kurabe part="..."] で出典・数字・一覧表・通販リンクを表示します。店の定義（名前・表記・色）はデータ側の stores 配列で持ち、プラグインには店名をハードコードしません。見出しと本文の見た目はテーマに任せ、このプラグインは部品だけを描きます。
 * Version:     1.4.1
 * Author:      Keys
 * License:     GPLv2 or later
 * Text Domain: suit-kurabe-db
 */

if (!defined('ABSPATH')) {
    exit;
}

/* 自動更新（GitHub直配信）。機能より先に入れる決まり */
require_once __DIR__ . '/includes/plugin-updater.php';
add_action('init', function () {
    new Suit_Kurabe_Db_Plugin_Updater(
        __FILE__,
        'https://raw.githubusercontent.com/yoshimucom-gif/wp-manager/main/plugin-host/api/plugin-update/suit-kurabe-db'
    );
});

class Suit_Kurabe_Db
{
    const VERSION  = '1.4.1';
    const META     = 'kurabe_data';
    const OPT      = 'suit_kurabe_db_settings';

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
        add_shortcode('kurabe_stores', array(__CLASS__, 'stores_shortcode'));
        add_action('suit_kurabe_selfupdate', array(__CLASS__, 'selfupdate'));
        add_action('rest_api_init', array(__CLASS__, 'rest_selfupdate'));
    }

    /* 新版の即時適用の窓口（管理者のアプリケーションパスワードで叩く）:
       POST /wp-json/skdb/v1/selfupdate → 更新キャッシュを捨てて配信元を確認し、
       WP標準の自動更新をその場で走らせる。通常の自動更新は半日周期＋12時間の
       鮮度ガードがあり、リリース直後に反映されないため。
       （当初のrdh/v1でcronオプションに予約を書く案は、rdhがcronを書き込み禁止にしていて不可） */
    public static function rest_selfupdate()
    {
        register_rest_route('skdb/v1', '/selfupdate', array(
            'methods'             => 'POST',
            'permission_callback' => function () {
                return current_user_can('update_plugins');
            },
            'callback'            => function () {
                $before = self::VERSION;
                self::selfupdate();
                return array('ok' => true, 'version_before' => $before);
            },
        ));
    }

    public static function selfupdate()
    {
        delete_transient('suit_kurabe_db_updater_' . md5(plugin_basename(__FILE__)));
        delete_site_transient('update_plugins');
        wp_update_plugins();
        if (function_exists('wp_maybe_auto_update')) {
            wp_maybe_auto_update();
        }
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
            foreach (array('kurabe_item', 'kurabe_group', 'kurabe_parent', 'kurabe_count', 'kurabe_stores') as $k) {
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
    /* 背景色の上に置く文字色（明るい背景は濃い文字、暗い背景は白） */
    private static function ink($hex)
    {
        if (!preg_match('/^#?([0-9a-f]{6})$/i', (string) $hex, $m)) {
            return '#fff';
        }
        $v = array_map('hexdec', str_split($m[1], 2));
        $y = (0.299 * $v[0] + 0.587 * $v[1] + 0.114 * $v[2]) / 255;
        return $y > 0.6 ? '#2b2200' : '#fff';
    }

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
        wp_register_style('suit-kurabe-db', $url . 'kurabe.css', array(), self::VERSION);
        wp_register_script('suit-kurabe-db', $url . 'kurabe.js', array(), self::VERSION, true);
        // TOP（固定ページ＋メインビジュアル）の区画 .kt-* の余白・見出し1の指定もこのCSSにある。
        // TOPには [kurabe] が無いので、フロントページでは無条件に読み込む
        if (is_front_page()) {
            wp_enqueue_style('suit-kurabe-db');
        }
    }

    public static function shortcode($atts)
    {
        $a = shortcode_atts(array('part' => 'table'), $atts, 'kurabe');
        $d = self::data();
        if (!$d) {
            return '';
        }
        wp_enqueue_style('suit-kurabe-db');
        wp_enqueue_script('suit-kurabe-db');
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

    /* 出典の呼び方（量販店＝公式通販／オーダー専門店＝公式サイト）。データ側で指定 */
    private static function word($d)
    {
        return isset($d['source_word']) && $d['source_word'] !== '' ? $d['source_word'] : '公式通販';
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
        $h .= '<div><dt>出典</dt><dd>' . esc_html(implode('・', $names)) . 'の' . esc_html(self::word($d)) . '（' . $n . '種）</dd></div>';
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
        // 掲載数の帯は数字の欄の下に全幅で通す（左の欄の中だと8社の店名が1段に収まらない。2026-10-03）
        $bar = '';
        if ($per) {
            $bar .= '<div class="kurabe-stats-bar"><div class="kurabe-bar" aria-hidden="true">';
            foreach ($per as $st => $n) {
                // 帯の中に店名を入れる（2026-10-03 吉村さん: 紺系が並んで見分けにくい）。区間は店名が収まる幅を下限にし、
                // 文字色は背景の明るさで白／濃色を切り替える
                $c = isset($stores[$st]['color']) ? $stores[$st]['color'] : '';
                $bar .= '<span class="kurabe-bar-seg"' . self::color_style($stores, $st, 'flex:' . (int) $n . ' 1 0;color:' . self::ink($c)) . '>' . esc_html(self::label($stores, $st)) . '</span>';
            }
            $bar .= '</div><div class="kurabe-bar-legend">';
            foreach ($per as $st => $n) {
                $bar .= '<span><b class="kurabe-t"' . self::color_style($stores, $st) . '>' . esc_html(self::label($stores, $st)) . '</b> ' . (int) $n . '</span>';
            }
            $bar .= '</div>';
            if (count($per) > 1 && array_sum($per) !== (int) $main['v']) {
                $bar .= '<div class="kurabe-bar-note">店ごとの数は、同じ商品を各店で数えています。</div>';
            }
            $bar .= '</div>';
        }
        $h .= '</div><div class="kurabe-stats-rest">';
        foreach ($rest as $x) {
            $h .= '<div class="kurabe-stats-row"><span class="kurabe-k">' . esc_html($x['k']) . '</span><span class="kurabe-v">' . esc_html($x['v']) . '<small>' . esc_html(isset($x['u']) ? $x['u'] : '') . '</small></span></div>';
        }
        $h .= '</div>' . $bar;
        $h .= '</div>';
        return $h;
    }

    private static function part_table($d)
    {
        $mode  = isset($d['mode']) ? $d['mode'] : 'none';
        $cols  = isset($d['cols']) && is_array($d['cols']) ? $d['cols'] : array();
        $label = isset($d['size_label']) ? $d['size_label'] : 'サイズ（cm）';
        $plabel = isset($d['price_label']) ? $d['price_label'] : '価格（税込）';
        $stores = self::stores($d);

        $prices  = array();
        foreach ($d['rows'] as $r) {
            if (isset($r['p']) && $r['p'] !== null) {
                $prices[(int) $r['p']] = true;
            }
        }
        ksort($prices);

        $names = array();
        foreach (self::present_stores($d, $stores) as $s) {
            $names[] = self::label($stores, $s);
        }

        $h  = '<div class="kurabe-table" data-mode="' . esc_attr($mode) . '"' . self::data_attr($d) . '>';
        $h .= '<p class="kurabe-stamp">' . esc_html(self::date_ja($d['checked'])) . '時点で、' . esc_html(implode('・', $names)) . 'の' . esc_html(self::word($d)) . 'に掲載されている情報です。' . esc_html(isset($d['stamp_tail']) ? $d['stamp_tail'] : '店頭の品ぞろえとは違う場合があります。') . '</p>';
        if (!empty($d['table_note'])) {
            $h .= '<p class="kurabe-stamp">' . esc_html($d['table_note']) . '</p>';
        }

        $h .= '<div class="kurabe-filters">';
        $h .= '<div class="kurabe-fgroup"><span class="kurabe-flabel">店</span><div class="kurabe-chips" data-filter="s">';
        foreach (self::present_stores($d, $stores) as $s) {
            $h .= '<button type="button" class="kurabe-chip kurabe-chip-store kurabe-chip-' . esc_attr(self::slug($stores, $s)) . '"' . self::color_style($stores, $s) . ' data-v="' . esc_attr($s) . '" aria-pressed="true">' . esc_html(self::label($stores, $s)) . '</button>';
        }
        $h .= '</div></div>';
        if (count($prices) > 1) {
            // 価格は1円刻みではなく価格帯で絞る（吉村さん指示）。商品がある帯だけチップを出す
            $ranges = !empty($d['price_ranges']) && is_array($d['price_ranges']) ? $d['price_ranges'] : array(
                array(0, 3000, '〜3,000円'),
                array(3000, 5000, '3,000〜5,000円'),
                array(5000, 10000, '5,000円〜1万円'),
                array(10000, 20000, '1〜2万円'),
                array(20000, 30000, '2〜3万円'),
                array(30000, 50000, '3〜5万円'),
                array(50000, 0, '5万円〜'),
            );
            $have = array();
            foreach (array_keys($prices) as $p) {
                foreach ($ranges as $i => $rg) {
                    if ($p >= $rg[0] && ($rg[1] === 0 || $p < $rg[1])) {
                        $have[$i] = true;
                        break;
                    }
                }
            }
            if (count($have) > 1) {
                $h .= '<div class="kurabe-fgroup"><span class="kurabe-flabel">価格</span><div class="kurabe-chips" data-filter="p">';
                foreach ($ranges as $i => $rg) {
                    if (empty($have[$i])) {
                        continue;
                    }
                    $v = $rg[0] . '-' . ($rg[1] === 0 ? '' : $rg[1]);
                    $h .= '<button type="button" class="kurabe-chip" data-v="' . esc_attr($v) . '" aria-pressed="true">' . esc_html($rg[2]) . '</button>';
                }
                $h .= '</div></div>';
            }
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
        $h .= '<th scope="col">商品</th>';
        if ($mode !== 'none') {
            $h .= '<th scope="col"><button type="button" data-sort="sz">' . esc_html($label) . '</button></th>';
        }
        $h .= '<th scope="col"><button type="button" data-sort="p">' . esc_html($plabel) . '</button></th>';
        foreach ($cols as $key => $cl) {
            $sortable = !empty($cl['sort']);
            $h .= '<th scope="col">' . ($sortable ? '<button type="button" data-sort="' . esc_attr($key) . '">' . esc_html($cl['label']) . '</button>' : esc_html($cl['label'])) . '</th>';
        }
        $h .= '</tr></thead><tbody>';

        foreach ($d['rows'] as $i => $r) {
            $attrs = ' data-i="' . $i . '" data-s="' . esc_attr($r['s']) . '" data-p="' . esc_attr(isset($r['p']) ? $r['p'] : '') . '"';
            $attrs .= ' data-sz="' . esc_attr(isset($r['sz']) ? $r['sz'] : '') . '"';
            foreach ($cols as $key => $cl) {
                if (!empty($cl['sort'])) {
                    $attrs .= ' data-' . esc_attr($key) . '="' . esc_attr(isset($r[$key . '_n']) ? $r[$key . '_n'] : '') . '"';
                }
            }
            $h .= '<tr' . $attrs . '>';
            // 店バッジ＋商品名を1つの列にまとめる（吉村さん指示・2026-09-28）。
            // 商品名＝公式商品ページへのリンク（右端の「公式」列は廃止済み）
            $h .= '<td class="kurabe-td-item"><span class="kurabe-store kurabe-' . esc_attr(self::slug($stores, $r['s'])) . '"' . self::color_style($stores, $r['s']) . '>' . esc_html(self::label($stores, $r['s'])) . '</span> ';
            list($purl, $prel) = self::aff_link($r['u'], self::slug($stores, $r['s']));
            $h .= '<a class="kurabe-pname" href="' . esc_url($purl) . '" target="_blank" rel="' . esc_attr($prel) . '">' . esc_html($r['n']) . '</a>';
            if (!empty($r['same'])) {
                $h .= '<span class="kurabe-sub"><b>' . esc_html(implode('・', $r['same'])) . '</b>でも同じ商品を販売' . (!empty($r['jan']) ? '（JAN ' . esc_html($r['jan']) . '）' : '') . '</span>';
            }
            $h .= '</td>';
            if ($mode !== 'none') {
                $h .= '<td class="kurabe-num" data-label="' . esc_attr($label) . '">' . (!empty($r['size_txt']) ? esc_html($r['size_txt']) : '<span class="kurabe-dim">記載なし</span>') . '</td>';
            }
            if (isset($r['p']) && $r['p'] !== null) {
                $h .= '<td class="kurabe-num" data-label="' . esc_attr($plabel) . '">';
                if (!empty($r['p_regular'])) {
                    // 値下げ品は2段表示：上段に取り消し線の通常価格、下段に現在価格
                    $h .= '<s class="kurabe-was">通常' . esc_html(number_format((int) $r['p_regular'])) . '円</s>';
                }
                $h .= '<span class="kurabe-price">' . esc_html(number_format((int) $r['p'])) . '円' . (!empty($r['p_from']) ? '〜' : '') . '</span>';
                if (!empty($r['p_note'])) {
                    // 価格の条件（税表記・何着分の価格か等）。公式の表記どおりの短い注記
                    $h .= '<span class="kurabe-sub">' . esc_html($r['p_note']) . '</span>';
                }
                $h .= '</td>';
            } else {
                $h .= '<td class="kurabe-num" data-label="' . esc_attr($plabel) . '"><span class="kurabe-dim">記載なし</span></td>';
            }
            foreach ($cols as $key => $cl) {
                $v = isset($r[$key]) ? $r[$key] : '';
                $from = isset($r['from'][$key]) ? $r['from'][$key] : '';
                $h .= '<td class="' . (!empty($cl['sort']) ? 'kurabe-num' : 'kurabe-text') . '" data-label="' . esc_attr($cl['label']) . '">';
                $h .= $v !== '' && $v !== null ? esc_html($v) . ($from ? '<span class="kurabe-sub">' . esc_html($from) . 'の掲載値</span>' : '') : '<span class="kurabe-dim">記載なし</span>';
                $h .= '</td>';
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

    private static function part_related($d)
    {
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
        $h = '<h2 class="wp-block-heading">' . esc_html($name) . 'とあわせて比べたい品目</h2><div class="kurabe-related">';
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

    /* オーダースーツ専門店の店舗一覧（地域記事用）。データは option suit_kurabe_order_stores（JSON文字列）。
       [kurabe_stores pref="大阪府" city="大阪市" addr="" label="大阪市"]
       pref=都道府県（カンマ区切りで複数）、city=市区町村の先頭一致（カンマ区切り）、addr=住所に含む語（銀座など） */
    public static function stores_shortcode($atts)
    {
        $a = shortcode_atts(array('pref' => '', 'city' => '', 'addr' => '', 'label' => ''), $atts, 'kurabe_stores');
        $raw = get_option('suit_kurabe_order_stores', '');
        $d = is_string($raw) ? json_decode($raw, true) : $raw;
        if (!is_array($d) || empty($d['stores'])) {
            return '';
        }
        wp_enqueue_style('suit-kurabe-db');
        $prefs = array_filter(array_map('trim', explode(',', $a['pref'])));
        $cities = array_filter(array_map('trim', explode(',', $a['city'])));
        $addrs = array_filter(array_map('trim', explode(',', $a['addr'])));
        $brands = isset($d['brands']) && is_array($d['brands']) ? $d['brands'] : array();
        $hit = array();
        foreach ($d['stores'] as $st) {
            $pf = isset($st['pref']) ? $st['pref'] : '';
            $ct = isset($st['city']) ? $st['city'] : '';
            $ad = isset($st['address']) ? $st['address'] : '';
            if ($prefs && !in_array($pf, $prefs, true)) {
                continue;
            }
            if ($cities) {
                $ok = false;
                foreach ($cities as $c) {
                    if ($c !== '' && strpos($ct, $c) === 0) {
                        $ok = true;
                        break;
                    }
                }
                if (!$ok) {
                    continue;
                }
            }
            if ($addrs) {
                $ok = false;
                foreach ($addrs as $w) {
                    if ($w !== '' && (strpos($ad, $w) !== false || strpos(isset($st['name']) ? $st['name'] : '', $w) !== false)) {
                        $ok = true;
                        break;
                    }
                }
                if (!$ok) {
                    continue;
                }
            }
            $hit[] = $st;
        }
        $label = $a['label'] !== '' ? $a['label'] : implode('・', $prefs);
        $date = isset($d['fetched']) ? self::date_ja($d['fetched']) : '';
        $h = '<div class="kurabe-stores">';
        if (!$hit) {
            return $h . '<p class="kurabe-stamp">' . esc_html($date) . '時点の各社公式サイトの店舗一覧に、' . esc_html($label) . 'の店舗は載っていません。</p></div>';
        }
        $nb = count(array_unique(array_map(function ($x) { return $x['brand']; }, $hit)));
        // 広告（アフィリエイト）リンクのある店（brands[*].aff）を先に。その中と残りは brands の並び→市区町村→店名の順
        $order = array_flip(array_keys($brands));
        $rank = function ($st) use ($brands, $order) {
            $k = $st['brand'];
            return array(empty($brands[$k]['aff']) ? 1 : 0, isset($order[$k]) ? $order[$k] : 999);
        };
        usort($hit, function ($x, $y) use ($rank) {
            $c = $rank($x) <=> $rank($y);
            if ($c !== 0) {
                return $c;
            }
            return strcmp((isset($x['city']) ? $x['city'] : '') . "\t" . $x['name'], (isset($y['city']) ? $y['city'] : '') . "\t" . $y['name']);
        });
        $ads = array();
        foreach ($hit as $st) {
            $k = $st['brand'];
            if (!empty($brands[$k]['aff'])) {
                $ads[$k] = isset($brands[$k]['label']) ? $brands[$k]['label'] : $k;
            }
        }
        $h .= '<p class="kurabe-stamp">' . esc_html($date) . '時点の各社公式サイトの店舗一覧から、' . esc_html($label) . 'にある店舗を並べています（' . count($hit) . '店・' . $nb . '社）。営業時間や開店・閉店は変わることがあるので、来店の前に各店の公式ページで確かめてください。'
            . ($ads ? 'このうち' . esc_html(implode('・', $ads)) . 'の「公式サイト」のリンクは広告（アフィリエイト）リンクです。' : '') . '</p>';
        $h .= '<div class="kurabe-tablebox"><table><thead><tr><th scope="col">店舗</th><th scope="col">住所</th><th scope="col">営業時間</th></tr></thead><tbody>';
        foreach ($hit as $st) {
            $b = isset($brands[$st['brand']]) ? $brands[$st['brand']] : array();
            $color = isset($b['color']) ? $b['color'] : '#1b2a4a';
            $bn = isset($st['brand_name']) ? $st['brand_name'] : $st['brand'];
            $h .= '<tr><td class="kurabe-td-item"><span class="kurabe-store" style="--kurabe-c:' . esc_attr($color) . '">' . esc_html(isset($b['label']) ? $b['label'] : $bn) . '</span> ';
            $h .= !empty($st['url']) ? '<a class="kurabe-pname" href="' . esc_url($st['url']) . '" target="_blank" rel="noopener">' . esc_html($st['name']) . '</a>' : esc_html($st['name']);
            if (!empty($st['note'])) {
                $h .= '<span class="kurabe-sub">' . esc_html($st['note']) . '</span>';
            }
            if (!empty($b['aff'])) {
                $h .= '<a class="kurabe-aff" href="' . esc_url($b['aff']) . '" target="_blank" rel="nofollow sponsored noopener">' . esc_html(isset($b['label']) ? $b['label'] : $bn) . ' 公式サイト</a>';
            }
            $h .= '</td><td class="kurabe-text" data-label="住所">' . esc_html(isset($st['address']) ? $st['address'] : '') . '</td>';
            $h .= '<td class="kurabe-text" data-label="営業時間">' . (!empty($st['hours']) ? esc_html($st['hours']) : '<span class="kurabe-dim">記載なし</span>') . '</td></tr>';
        }
        $h .= '</tbody></table></div></div>';
        return $h;
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
        /* 店定義は各ページの kurabe_data の stores をマージして使う。
           各ページのstoresは店マスタ順の部分列なので、いちばん店数の多いページの並びを
           土台にしてから残りを足す（先勝ちだけだと最初のページに無い店が末尾に落ちて
           マスタ順が崩れる。2026-09-29 吉村さん指摘＝ユニクロは最後・青山が先頭側） */
        $base = array();
        $rows = array();
        $maps = array();
        foreach ($posts as $p) {
            $d = self::data($p->ID);
            if ($d) {
                $m = self::stores($d);
                $maps[] = $m;
                if (count($m) > count($base)) {
                    $base = $m;
                }
            }
            $per = json_decode((string) get_post_meta($p->ID, 'kurabe_stores', true), true);
            $rows[] = array(
                'url'   => get_permalink($p),
                'item'  => get_post_meta($p->ID, 'kurabe_item', true),
                'total' => (int) get_post_meta($p->ID, 'kurabe_count', true),
                'per'   => is_array($per) ? $per : array(),
            );
        }
        $storemap = $base;
        foreach ($maps as $m) {
            $storemap += $m;
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
        usort($rows, function ($a, $b) use ($sort_store) {
            if ($sort_store) {
                $x = isset($a['per'][$sort_store]) ? $a['per'][$sort_store] : 0;
                $y = isset($b['per'][$sort_store]) ? $b['per'][$sort_store] : 0;
                if ($x !== $y) {
                    return $y - $x;
                }
            }
            return $b['total'] - $a['total'];
        });
        wp_enqueue_style('suit-kurabe-db');
        $lead = $sort_store
            ? esc_html($cols[$sort_store]['label']) . 'の公式通販に載っている品目を、' . esc_html($cols[$sort_store]['label']) . 'の掲載数の多い順に並べています。'
            : '各社の公式通販に載っている品目を、掲載数の多い順に並べています。';
        $h  = '<div class="kurabe-list"><p class="kurabe-list-lead">' . count($rows) . '品目。' . $lead . '</p>';
        $h .= '<div class="kurabe-tablebox"><table><thead><tr><th scope="col">品目</th>';
        foreach ($cols as $st => $c) {
            $h .= '<th scope="col" class="kurabe-c"><span class="kurabe-store kurabe-' . esc_attr($c['slug']) . '"' . self::color_style($cols, $st) . '>' . esc_html($c['label']) . '</span></th>';
        }
        $h .= '</tr></thead><tbody>';
        foreach ($rows as $r) {
            $h .= '<tr><td><a href="' . esc_url($r['url']) . '">' . esc_html($r['item']) . '</a></td>';
            foreach ($cols as $st => $c) {
                $n = isset($r['per'][$st]) ? (int) $r['per'][$st] : 0;
                $h .= '<td class="kurabe-c kurabe-num">' . ($n ? '<span class="kurabe-t"' . self::color_style($cols, $st) . '>' . $n . '</span>' : '<span class="kurabe-none">—</span>') . '</td>';
            }
            $h .= '</tr>';
        }
        return $h . '</tbody></table></div></div>';
    }

    /* 店ごとのアフィリエイト変換（ASP承認後に有効化する。2026-09-30）
       option skdb_affiliate = { 店slug: リダイレクト型プレフィックス } を rdh/v1 で入れる。
       例: {"psfa": "https://px.a8.net/svt/ejp?a8mat=XXXXX+YYYYY&a8ejpredirect="}
       プレフィックスの後ろに公式商品URLをURLエンコードして付ける。
       未設定の店は素の公式リンクのまま（rel も noopener のみ） */
    private static function aff_link($url, $slug)
    {
        static $conf = null;
        if ($conf === null) {
            $conf = get_option('skdb_affiliate');
            if (!is_array($conf)) {
                $conf = array();
            }
        }
        if (empty($conf[$slug]) || !is_string($conf[$slug]) || strpos($conf[$slug], 'https://') !== 0) {
            return array($url, 'noopener');
        }
        return array($conf[$slug] . rawurlencode($url), 'nofollow sponsored noopener');
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
            $h .= '<a class="kurabe-btn kurabe-btn-amazon" href="' . esc_url(self::amazon_url($q)) . '" target="_blank" rel="nofollow sponsored noopener">Amazonで探す</a>';
            $h .= '<a class="kurabe-btn kurabe-btn-rakuten" href="' . esc_url(self::rakuten_url($q)) . '" target="_blank" rel="nofollow sponsored noopener">楽天市場で探す</a>';
            $h .= '</div></div>';
        }
        $h .= '</div><p class="kurabe-adnote">上のリンクは広告（アフィリエイト）を含みます。価格と在庫はリンク先でご確認ください。</p>';
        return $h;
    }

    /* ---------- 設定画面 ---------- */

    public static function admin_menu()
    {
        add_options_page('スーツくらべ 比較データ', 'スーツくらべ', 'manage_options', 'suit-kurabe-db', array(__CLASS__, 'settings_page'));
    }

    public static function admin_init()
    {
        register_setting('suit_kurabe_db', self::OPT, array(
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
        echo '<div class="wrap"><h1>スーツくらべ 比較データ</h1>';
        echo '<p>空欄のときはオートインサーター（またはRinker）の設定からIDを読みます。いま使われているID：Amazon <code>' . esc_html($amazon ? $amazon : '未設定') . '</code> ／ 楽天 <code>' . esc_html($rakuten ? '設定あり' : '未設定') . '</code></p>';
        echo '<form method="post" action="options.php">';
        settings_fields('suit_kurabe_db');
        echo '<table class="form-table"><tr><th>AmazonトラッキングID</th><td><input class="regular-text" name="' . self::OPT . '[amazon_tag]" value="' . esc_attr(isset($o['amazon_tag']) ? $o['amazon_tag'] : '') . '"></td></tr>';
        echo '<tr><th>楽天アフィリエイトID</th><td><input class="regular-text" name="' . self::OPT . '[rakuten_id]" value="' . esc_attr(isset($o['rakuten_id']) ? $o['rakuten_id'] : '') . '"></td></tr></table>';
        submit_button();
        echo '</form></div>';
    }
}

Suit_Kurabe_Db::boot();
