<?php
/**
 * WordPressなしでプラグインの出力を確かめる。
 *   php test/render.php > test/preview.html
 * 全パターンのCTA3箇所と、追従バナー2種類を1枚に並べる。
 */

define('ABSPATH', __DIR__ . '/');

$GLOBALS['fj_test_terms']  = array();
$GLOBALS['fj_test_meta']   = array();
$GLOBALS['fj_test_option'] = array('cta_enabled' => 1, 'banner_enabled' => 1, 'exclude_ids' => '');

function plugin_dir_path($f) { return dirname($f) . '/'; }
function plugin_dir_url($f)  { return './'; }
function add_action() {}
function add_filter() {}
function add_options_page() {}
function get_option($k, $d = array()) { return $GLOBALS['fj_test_option']; }
function update_option($k, $v) { $GLOBALS['fj_test_option'] = $v; }
function is_wp_error($v) { return false; }
function wp_get_post_terms($id, $tax, $args) { return $GLOBALS['fj_test_terms']; }
function esc_html($s) { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
function esc_attr($s) { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
function esc_url($s)  { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
function wp_kses_post($s) { return $s; }
function is_admin() { return false; }
function is_singular($t = '') { return true; }
function in_the_loop() { return true; }
function is_main_query() { return true; }
function get_the_ID() { return 1; }
function get_queried_object_id() { return 1; }
function wp_enqueue_style() {}
function wp_enqueue_script() {}
function current_user_can() { return true; }
function checked() {}
function submit_button() {}
function wp_nonce_field() {}
function wp_verify_nonce() { return false; }
function sanitize_text_field($s) { return $s; }
function wp_unslash($s) { return $s; }
class WP_Query { public $posts = array(); public function __construct($a = array()) {} }
function get_post_meta($id, $k, $single = false) { return isset($GLOBALS['fj_test_meta'][$k]) ? $GLOBALS['fj_test_meta'][$k] : ''; }
function maybe_unserialize($v) { $r = @unserialize($v); return ($r === false && $v !== 'b:0;') ? $v : $r; }

require __DIR__ . '/../fukujimai-cta.php';

/** h2が5本ある記事を模す */
function fj_dummy_content() {
	$h = '<p>リード文がここで終わります。各社が公開している条件だけを集めて並べています。</p>';
	$titles = array('出せる先を整理する', '持ち込みで手放す', '宅配で手放す', '費用の目安', 'まとめ');
	foreach ($titles as $i => $t) {
		$h .= '<h2 id="toc-id-1-' . ($i + 1) . '" class="wp-block-heading">' . $t . '</h2>';
		$h .= '<p>本文の段落その1です。' . $t . 'についての説明が入ります。</p>';
		$h .= '<p>本文の段落その2です。条件や手順の補足が入ります。</p>';
	}
	return $h;
}

$slug_sample = array(
	'uru'    => array('takuhai-kaitori', 'uru'),
	'souba'  => array('souba', 'uru'),
	'area'   => array('area-city', 'area'),
	'suteru' => array('shobun-hoho', 'suteru'),
	'kaishu' => array('kaishu-box', 'kaishu'),
	'suit'   => array('suit-sagyofuku', 'hinmoku2'),
	'kodomo' => array('kodomofuku'),
	'ihin'   => array('ihin'),
);

echo "<!doctype html>\n<html lang=\"ja\"><head><meta charset=\"utf-8\">";
echo '<meta name="viewport" content="width=device-width,initial-scale=1">';
echo '<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Noto+Sans+JP:wght@400;500;700&display=swap">';
echo '<link rel="stylesheet" href="../assets/fjcta.css">';
echo '<style>html{font-size:10px}body{font-family:"Noto Sans JP",sans-serif;background:#FAFBF7;color:#2C3730;'
	. 'margin:0;padding:34px;font-size:16px;line-height:1.9}.page{max-width:840px;margin:0 auto}'
	. 'h2.wp-block-heading{font-size:22px;background:#26765F;color:#fff;padding:.6em 1em;border-radius:4px;margin:20px 0 14px}'
	. '.pat{margin:64px 0 0;padding:14px 0 0;border-top:3px solid #26765F}'
	. '.pat b{font-size:15px;letter-spacing:.06em;color:#26765F}'
	. '.side-demo{margin:20px 0;padding:16px;border:1px dashed #9aa5a0;max-width:340px}'
	. '.side-demo .fj-bn-side:not([hidden]){display:block}</style>';
echo '<script src="../assets/fjcta.js" defer></script>';
echo "</head><body><div class=\"page\">";

$seen_pixel = array();
foreach (fjcta_patterns() as $key => $p) {
	$GLOBALS['fj_test_terms'] = $slug_sample[$key];
	$got = fjcta_pattern_key(1);

	echo '<div class="pat"><b>' . esc_html($key) . '</b> … 判定=' . esc_html($got)
		. ' ／ 主役=' . esc_html(fjcta_name($p['hero'])) . '</div>';

	if ($got !== $key) {
		echo '<p style="color:#b00">判定が想定と違います</p>';
		continue;
	}
	echo fjcta_inject_content(fj_dummy_content());

	echo '<div class="side-demo">サイドバー追従（PC）<br>' . fjcta_banner_html($p, 'side') . '</div>';
}

// 出さないカテゴリ
foreach (array('mofuku-kimono', 'seifuku', 'sonota-hinmoku') as $s) {
	$GLOBALS['fj_test_terms'] = array($s, 'hinmoku');
	$k = fjcta_pattern_key(1);
	$body = fjcta_inject_content('<p>x</p><h2>a</h2><p>y</p>');
	echo '<div class="pat"><b>' . esc_html($s) . '</b> … 判定=' . ($k === '' ? '（出さない）' : esc_html($k))
		. ' ／ 本文に差し込みあり=' . (strpos($body, 'fj-cta') !== false ? 'あり（想定外）' : 'なし') . '</div>';
}

// 除外ID
$GLOBALS['fj_test_option']['exclude_ids'] = '1, 2';
$GLOBALS['fj_test_terms'] = array('takuhai-kaitori');
echo '<div class="pat"><b>除外ID指定</b> … 判定=' . (fjcta_pattern_key(1) === '' ? '（出さない）' : '出る（想定外）') . '</div>';
$GLOBALS['fj_test_option']['exclude_ids'] = '';

// サイドバーを出さない記事（無料ツールのページ）
$GLOBALS['fj_test_terms'] = array('takuhai-kaitori');
$GLOBALS['fj_test_meta']  = array('diver_single_layout' => serialize(array(
	'title' => 'default', 'sidebar' => 'hide', 'size' => 'wide', 'width' => 'large', 'design' => 'default', 'gap' => '')));
$tool = fjcta_pattern(1);
echo '<div class="pat"><b>ツールのページ（sidebar=hide）</b> … 判定='
	. esc_html(fjcta_pattern_key(1))
	. ' ／ サイドバー非表示の検出=' . (fjcta_sidebar_hidden(1) ? 'はい' : 'いいえ（想定外）') . '</div>';
echo '<div class="side-demo">本文にh2が無いとき（ツールのページ）<br>'
	. fjcta_inject_content('<p>相場を調べるツールです。</p>') . '</div>';
echo fjcta_banner_html($tool, 'fix');
$GLOBALS['fj_test_meta'] = array();

// スマホ固定バーは1つだけ出す
$GLOBALS['fj_test_terms'] = array('area-city');
$pp = fjcta_pattern(1);
echo '<div class="pat"><b>通常記事のサイドバー非表示の検出</b> … '
	. (fjcta_sidebar_hidden(1) ? 'はい（想定外）' : 'いいえ') . '</div>';
echo fjcta_banner_html($pp, 'sp');

echo "</div></body></html>";
