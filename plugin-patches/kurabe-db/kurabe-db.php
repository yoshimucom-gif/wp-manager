<?php
/**
 * Plugin Name: 100均くらべ 比較データ表示
 * Description: 品目ごとの比較データ（ダイソー・キャンドゥ・ワッツの公式通販から取得した仕様）を投稿メタ kurabe_data に保存し、ショートコード [kurabe part="..."] で出典・数字・一覧表・縮尺図・通販リンクを表示します。見出しと本文の見た目はテーマに任せ、このプラグインは部品だけを描きます。
 * Version:     1.1.5
 * Author:      Keys
 * License:     GPLv2 or later
 * Text Domain: kurabe-db
 */

if (!defined('ABSPATH')) {
    exit;
}

/* 自動更新（GitHub直配信）。機能より先に入れる決まり */
require_once __DIR__ . '/includes/plugin-updater.php';
add_action('init', function () {
    new Kurabe_Db_Plugin_Updater(
        __FILE__,
        'https://raw.githubusercontent.com/yoshimucom-gif/wp-manager/main/plugin-host/api/plugin-update/kurabe-db'
    );
});

class Kurabe_Db
{
    const VERSION  = '1.1.5';
    const META     = 'kurabe_data';
    const OPT      = 'kurabe_db_settings';
    const STORES   = array('ダイソー' => 'daiso', 'キャンドゥ' => 'cando', 'ワッツ' => 'watts');
    /* 店名バッジと絞り込みに出す表記（各社のロゴ表記） */
    const LABELS   = array('ダイソー' => 'DAISO', 'キャンドゥ' => 'Can★Do', 'ワッツ' => 'Watts');

    private static $used = false;

    public static function boot()
    {
        add_action('init', array(__CLASS__, 'register_meta'));
        add_shortcode('kurabe', array(__CLASS__, 'shortcode'));
        add_action('wp_enqueue_scripts', array(__CLASS__, 'assets'));
        add_action('admin_menu', array(__CLASS__, 'admin_menu'));
        add_action('admin_init', array(__CLASS__, 'admin_init'));
        add_filter('diver_single_side_items', array(__CLASS__, 'side_items'));
        add_filter('get_post_metadata', array(__CLASS__, 'layout_title_normal'), 10, 4);
        add_action('diver_single_main_section', array(__CLASS__, 'full_width_header'), 5);
        add_action('diver_main_before', array(__CLASS__, 'archive_table'), 20);
        add_shortcode('kurabe_list', array(__CLASS__, 'list_shortcode'));
    }

    /* 比較ページは「サイズ：幅広」で組む。re:Diverは幅広のとき記事横の縦並びボタン
       （シェア・コピー・保存・目次・印刷）を本文の上に積んで大きな空白を作るので、
       比較データのあるページでは出さない（シェアはタイトル欄に残る） */
    public static function side_items($items)
    {
        return is_singular() && get_post_meta(get_the_ID(), self::META, true) ? array() : $items;
    }

    /* 比較ページは「サイズ：幅広」＋タイトルは通常の見た目（吉村さん指定）。
       re:Diver は幅広のとき通常のタイトル欄を出さない（ビッグだけ出す）ので、
       表示のときだけタイトルの設定を「ビッグ」→「デフォルト」に読み替え、
       テーマと同じタイトル欄をここで出し直す */
    private static $reading_layout = false;

    public static function layout_title_normal($value, $object_id, $meta_key, $single)
    {
        if ($meta_key !== 'diver_single_layout' || is_admin() || self::$reading_layout) {
            return $value;
        }
        self::$reading_layout = true;
        $has  = get_post_meta($object_id, self::META, true);
        $meta = get_post_meta($object_id, 'diver_single_layout', true);
        self::$reading_layout = false;
        if (!$has || !is_array($meta) || !isset($meta['title']) || $meta['title'] !== 'big') {
            return $value;
        }
        $meta['title'] = 'default';
        return array($meta);
    }

    public static function full_width_header()
    {
        if (!is_singular()) {
            return;
        }
        $id = get_the_ID();
        if (!get_post_meta($id, self::META, true)) {
            return;
        }
        $meta = get_post_meta($id, 'diver_single_layout', true);
        if (!is_array($meta) || !isset($meta['size']) || $meta['size'] !== 'full' || (isset($meta['title']) && $meta['title'] === 'hide')) {
            return;
        }
        ob_start();
        do_action('diver_single_main_header', $id);
        $header = ob_get_clean();
        if ($header) {
            echo '<header class="article-header -inner-content alignfull has-gap-column:10 is-position-relative">' . $header . '</header>';
        }
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
        wp_register_style('kurabe-db', $url . 'kurabe.css', array(), self::VERSION);
        wp_register_script('kurabe-db', $url . 'kurabe.js', array(), self::VERSION, true);
    }

    public static function shortcode($atts)
    {
        $a = shortcode_atts(array('part' => 'table'), $atts, 'kurabe');
        $d = self::data();
        if (!$d) {
            return '';
        }
        wp_enqueue_style('kurabe-db');
        wp_enqueue_script('kurabe-db');
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
        return array(
            'mode'  => isset($d['mode']) ? $d['mode'] : 'none',
            'item'  => isset($d['item']) ? $d['item'] : '',
            'dimNames' => isset($d['dim_names']) ? $d['dim_names'] : array(),
            'rows'  => $rows,
        );
    }

    public static function label($s)
    {
        return isset(self::LABELS[$s]) ? self::LABELS[$s] : $s;
    }

    private static function date_ja($ymd)
    {
        $t = strtotime($ymd);
        return $t ? date('Y年n月j日', $t) : esc_html($ymd);
    }

    private static function part_source($d)
    {
        $uniq = array();
        foreach ($d['rows'] as $r) {                 // 同じ商品（JAN）は1件として数える
            $uniq[!empty($r['jan']) ? $r['jan'] : $r['u']] = true;
        }
        $n = count($uniq);
        $stores = array();
        foreach (array_keys(self::STORES) as $s) {
            foreach ($d['rows'] as $r) {
                if ($r['s'] === $s) {
                    $stores[] = $s;
                    break;
                }
            }
        }
        $h  = '<dl class="kurabe-source" aria-label="データの出どころ">';
        $h .= '<div><dt>最終確認</dt><dd><time datetime="' . esc_attr($d['checked']) . '">' . esc_html(self::date_ja($d['checked'])) . '</time></dd></div>';
        $h .= '<div><dt>出典</dt><dd>' . esc_html(implode('・', $stores)) . 'の公式通販（' . $n . '種）</dd></div>';
        $h .= '<div><dt>照合</dt><dd>JANコード（商品のバーコード番号）で同じ商品を判定</dd></div>';
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
        $per = array();                              // 店ごとの掲載数（同じ商品は各店で数える）
        foreach (array_keys(self::STORES) as $st) {
            $n = 0;
            foreach ($d['rows'] as $r) {
                if ($r['s'] === $st) {
                    $n++;
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
                $h .= '<span class="kurabe-bar-' . self::STORES[$st] . '" style="flex:' . (int) $n . '"></span>';
            }
            $h .= '</div><div class="kurabe-bar-legend">';
            foreach ($per as $st => $n) {
                $h .= '<span><b class="kurabe-t-' . self::STORES[$st] . '">' . esc_html(self::label($st)) . '</b> ' . (int) $n . '</span>';
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

        $stores = array();
        $prices = array();
        foreach ($d['rows'] as $r) {
            $stores[$r['s']] = true;
            if (isset($r['p']) && $r['p'] !== null) {
                $prices[(int) $r['p']] = true;
            }
        }
        ksort($prices);

        $h  = '<div class="kurabe-table" data-mode="' . esc_attr($mode) . '"' . self::data_attr($d) . '>';
        $h .= '<p class="kurabe-stamp">' . esc_html(self::date_ja($d['checked'])) . '時点で、ダイソーネットストア・Can★Doネットショップ・ワッツオンラインに掲載されている情報です。店頭の品ぞろえとは違う場合があります。商品写真は各社の公式ページでご覧ください。</p>';

        $h .= '<div class="kurabe-filters">';
        $h .= '<div class="kurabe-fgroup"><span class="kurabe-flabel">店</span><div class="kurabe-chips" data-filter="s">';
        foreach (array_keys(self::STORES) as $s) {
            if (isset($stores[$s])) {
                $h .= '<button type="button" class="kurabe-chip kurabe-chip-' . self::STORES[$s] . '" data-v="' . esc_attr($s) . '" aria-pressed="true">' . esc_html(self::label($s)) . '</button>';
            }
        }
        $h .= '</div></div>';
        if (count($prices) > 1) {
            $h .= '<div class="kurabe-fgroup"><span class="kurabe-flabel">価格</span><div class="kurabe-chips" data-filter="p">';
            foreach (array_keys($prices) as $p) {
                $h .= '<button type="button" class="kurabe-chip" data-v="' . esc_attr($p) . '" aria-pressed="true">' . esc_html($p) . '円</button>';
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
        $h .= '<th scope="col">店</th><th scope="col">商品名</th>';
        if ($mode !== 'none') {
            $h .= '<th scope="col"><button type="button" data-sort="sz">' . esc_html($label) . '</button></th>';
        }
        $h .= '<th scope="col"><button type="button" data-sort="p">価格（税込）</button></th>';
        foreach ($cols as $key => $cl) {
            $sortable = !empty($cl['sort']);
            $h .= '<th scope="col">' . ($sortable ? '<button type="button" data-sort="' . esc_attr($key) . '">' . esc_html($cl['label']) . '</button>' : esc_html($cl['label'])) . '</th>';
        }
        $h .= '</tr></thead><tbody>';          // 公式ページへは商品名のリンクで飛ぶので「公式」列は置かない（吉村さん指定）

        foreach ($d['rows'] as $i => $r) {
            $attrs = ' data-i="' . $i . '" data-s="' . esc_attr($r['s']) . '" data-p="' . esc_attr(isset($r['p']) ? $r['p'] : '') . '"';
            $attrs .= ' data-sz="' . esc_attr(isset($r['sz']) ? $r['sz'] : '') . '"';
            foreach ($cols as $key => $cl) {
                if (!empty($cl['sort'])) {
                    $attrs .= ' data-' . esc_attr($key) . '="' . esc_attr(isset($r[$key . '_n']) ? $r[$key . '_n'] : '') . '"';
                }
            }
            $cls = isset(self::STORES[$r['s']]) ? self::STORES[$r['s']] : 'other';
            $h .= '<tr' . $attrs . '>';
            $h .= '<td class="kurabe-td-store"><span class="kurabe-store kurabe-' . $cls . '">' . esc_html(self::label($r['s'])) . '</span></td>';
            // 商品名から公式の商品ページへ（別タブ）。吉村さん指定
            $h .= '<td class="kurabe-td-name"><a class="kurabe-pname" href="' . esc_url($r['u']) . '" target="_blank" rel="noopener">' . esc_html($r['n']) . '</a>';
            if (!empty($r['same'])) {
                // JANコードは本文に出さず、マウスを乗せたときだけ出す（吉村さん指定）
                $h .= '<span class="kurabe-sub" title="JANコード ' . esc_attr($r['jan']) . '">' . esc_html(implode('・', array_map(array(__CLASS__, 'label'), $r['same']))) . 'でも同じ商品を販売</span>';
            }
            $h .= '</td>';
            if ($mode !== 'none') {
                $h .= '<td class="kurabe-num" data-label="' . esc_attr($label) . '">' . (!empty($r['size_txt']) ? esc_html($r['size_txt']) : '<span class="kurabe-dim">記載なし</span>') . '</td>';
            }
            $h .= '<td class="kurabe-num" data-label="価格（税込）">' . (isset($r['p']) && $r['p'] !== null ? '<span class="kurabe-price">' . esc_html($r['p']) . '円</span>' : '<span class="kurabe-dim">記載なし</span>') . '</td>';
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
        $stores = array();
        foreach (array_keys(self::STORES) as $s) {
            foreach ($rows as $r) {
                if ($r['s'] === $s) {
                    $stores[] = $s;
                    break;
                }
            }
        }
        $h  = '<div class="kurabe-guide"><table><thead><tr><th scope="col">取り付けたい幅</th>';
        foreach ($stores as $s) {
            $h .= '<th scope="col"><span class="kurabe-store kurabe-' . self::STORES[$s] . '">' . esc_html(self::label($s)) . '</span></th>';
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
       投稿メタ: kurabe_item＝品目名 / kurabe_group＝ダイソーの売り場の小分類 /
                 kurabe_parent＝親の品目名（「ワイヤーネット用フック」なら「ワイヤーネット」）/ kurabe_count＝掲載種数 */
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
            $h .= '<li><a href="' . esc_url(get_permalink($p)) . '">100均' . esc_html($item ? $item : get_the_title($p)) . '</a>'
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
        $h = '<h3 class="wp-block-heading">100均' . esc_html($name) . 'とあわせて比べたい品目</h3><div class="kurabe-related">';   // ページの最後に置く小見出し（吉村さん指定）
        if ($pair) {
            $h .= '<p class="kurabe-related-label">一緒に使う品目</p>' . self::link_list($pair);
        }
        if ($same) {
            $h .= '<p class="kurabe-related-label">同じ売り場の品目</p>' . self::link_list($same);
        }
        return $h . '</div>';
    }

    /* カテゴリー・店名タグの一覧ページ：品目×3社の掲載数の表（吉村さん案A）
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
        $sort_store = '';
        if ($q['taxonomy'] === 'post_tag') {
            $slug = get_term((int) $q['term'])->slug;
            $sort_store = array_search($slug, self::STORES, true) ?: '';
        }
        $rows = array();
        foreach ($posts as $p) {
            $per = json_decode((string) get_post_meta($p->ID, 'kurabe_stores', true), true);
            $rows[] = array(
                'url'   => get_permalink($p),
                'item'  => get_post_meta($p->ID, 'kurabe_item', true),
                'total' => (int) get_post_meta($p->ID, 'kurabe_count', true),
                'per'   => is_array($per) ? $per : array(),
            );
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
        wp_enqueue_style('kurabe-db');
        $lead = $sort_store
            ? esc_html(self::label($sort_store)) . 'の公式通販に載っている品目を、' . esc_html(self::label($sort_store)) . 'の掲載数の多い順に並べています。'
            : 'ダイソー・キャンドゥ・ワッツの公式通販に載っている品目を、掲載数の多い順に並べています。';
        $h  = '<div class="kurabe-list"><p class="kurabe-list-lead">' . count($rows) . '品目。' . $lead . '</p>';
        $h .= '<div class="kurabe-tablebox"><table><thead><tr><th scope="col">品目</th>';
        foreach (self::STORES as $st => $cls) {
            $h .= '<th scope="col" class="kurabe-c"><span class="kurabe-store kurabe-' . $cls . '">' . esc_html(self::label($st)) . '</span></th>';
        }
        $h .= '<th scope="col" class="kurabe-c">合計</th></tr></thead><tbody>';
        foreach ($rows as $r) {
            $h .= '<tr><td><a href="' . esc_url($r['url']) . '">100均' . esc_html($r['item']) . '</a></td>';
            foreach (self::STORES as $st => $cls) {
                $n = isset($r['per'][$st]) ? (int) $r['per'][$st] : 0;
                $h .= '<td class="kurabe-c kurabe-num">' . ($n ? '<span class="kurabe-t-' . $cls . '">' . $n . '</span>' : '<span class="kurabe-none">—</span>') . '</td>';
            }
            $h .= '<td class="kurabe-c kurabe-num">' . $r['total'] . '種</td></tr>';
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
            $h .= '<a href="' . esc_url(self::amazon_url($q)) . '" target="_blank" rel="nofollow sponsored noopener">Amazonで探す</a>';
            $h .= '<a href="' . esc_url(self::rakuten_url($q)) . '" target="_blank" rel="nofollow sponsored noopener">楽天市場で探す</a>';
            $h .= '</div></div>';
        }
        $h .= '</div><p class="kurabe-adnote">上のリンクは広告（アフィリエイト）を含みます。価格と在庫はリンク先でご確認ください。</p>';
        return $h;
    }

    /* ---------- 設定画面 ---------- */

    public static function admin_menu()
    {
        add_options_page('100均くらべ 比較データ', '100均くらべ', 'manage_options', 'kurabe-db', array(__CLASS__, 'settings_page'));
    }

    public static function admin_init()
    {
        register_setting('kurabe_db', self::OPT, array(
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
        echo '<div class="wrap"><h1>100均くらべ 比較データ</h1>';
        echo '<p>空欄のときはオートインサーター（またはRinker）の設定からIDを読みます。いま使われているID：Amazon <code>' . esc_html($amazon ? $amazon : '未設定') . '</code> ／ 楽天 <code>' . esc_html($rakuten ? '設定あり' : '未設定') . '</code></p>';
        echo '<form method="post" action="options.php">';
        settings_fields('kurabe_db');
        echo '<table class="form-table"><tr><th>AmazonトラッキングID</th><td><input class="regular-text" name="' . self::OPT . '[amazon_tag]" value="' . esc_attr(isset($o['amazon_tag']) ? $o['amazon_tag'] : '') . '"></td></tr>';
        echo '<tr><th>楽天アフィリエイトID</th><td><input class="regular-text" name="' . self::OPT . '[rakuten_id]" value="' . esc_attr(isset($o['rakuten_id']) ? $o['rakuten_id'] : '') . '"></td></tr></table>';
        submit_button();
        echo '</form></div>';
    }
}

Kurabe_Db::boot();
