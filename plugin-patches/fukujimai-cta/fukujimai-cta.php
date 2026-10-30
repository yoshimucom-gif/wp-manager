<?php
/**
 * Plugin Name: 服じまい CTA・追従バナー
 * Plugin URI:  https://fukujimai.jp/
 * Description: 記事のカテゴリに応じて買取サービスのCTAを本文の3箇所（最初のh2の上・途中・まとめのあと）に出し、PCはサイドバー追従・スマホは画面下固定でバナーを出します。品目によっては何も出しません。
 * Version:     1.0.3
 * Author:      Keys
 * License:     GPLv2 or later
 * Text Domain: fukujimai-cta
 */

if (!defined('ABSPATH')) exit;

define('FJCTA_VERSION', '1.0.3');
define('FJCTA_DIR', plugin_dir_path(__FILE__));
define('FJCTA_URL', plugin_dir_url(__FILE__));
define('FJCTA_UPDATE_URL', 'https://raw.githubusercontent.com/yoshimucom-gif/wp-manager/main/plugin-host/api/plugin-update/fukujimai-cta');

require_once FJCTA_DIR . 'includes/plugin-updater.php';
require_once FJCTA_DIR . 'includes/patterns.php';

add_action('init', function () {
	if (class_exists('Fukujimai_Plugin_Updater')) {
		new Fukujimai_Plugin_Updater(__FILE__, FJCTA_UPDATE_URL);
	}
});

/* ============================================================
 * 設定
 * ========================================================== */

function fjcta_defaults() {
	return array(
		'cta_enabled'    => 1,
		'banner_enabled' => 1,
		'exclude_ids'    => '',
	);
}

function fjcta_opt($key) {
	$o = get_option('fjcta_settings', array());
	$d = fjcta_defaults();
	if (!is_array($o)) $o = array();
	return array_key_exists($key, $o) ? $o[$key] : $d[$key];
}

function fjcta_excluded_ids() {
	$raw = (string) fjcta_opt('exclude_ids');
	if ($raw === '') return array();
	$ids = preg_split('/[^0-9]+/', $raw, -1, PREG_SPLIT_NO_EMPTY);
	return array_map('intval', $ids);
}

/* ============================================================
 * A8素材
 * ========================================================== */

function fjcta_materials() {
	static $cache = null;
	if ($cache !== null) return $cache;

	$path = FJCTA_DIR . 'includes/a8_materials.json';
	$cache = array();
	if (is_readable($path)) {
		$json = json_decode(file_get_contents($path), true);
		if (is_array($json)) {
			foreach (array('fukuro', 'ribla', 'okoku') as $k) {
				if (isset($json[$k])) $cache[$k] = $json[$k];
			}
		}
	}
	return $cache;
}

function fjcta_material($key) {
	$m = fjcta_materials();
	return isset($m[$key]) ? $m[$key] : null;
}

function fjcta_name($key) {
	$m = fjcta_material($key);
	return ($m && !empty($m['表示名'])) ? $m['表示名'] : '';
}

/** 広告リンク（テキスト・ボタン共通） */
function fjcta_a($key, $label, $class = '') {
	$m = fjcta_material($key);
	if (!$m || empty($m['text']['href'])) return esc_html($label);
	return sprintf(
		'<a %shref="%s" target="_blank" rel="nofollow sponsored noopener">%s</a>',
		$class ? 'class="' . esc_attr($class) . '" ' : '',
		esc_url($m['text']['href']),
		$label
	);
}

/** ボタン（矢印は外部リンクの意味で ↗） */
function fjcta_button($key, $label) {
	return fjcta_a($key, esc_html($label) . '<span class="fj-ar" aria-hidden="true">↗</span>', 'fj-btn');
}

/** 計測用の1×1画像。本文に1回だけ出す */
function fjcta_pixels($keys) {
	$out = '';
	foreach (array_unique($keys) as $k) {
		$m = fjcta_material($k);
		if ($m && !empty($m['text']['pixel'])) {
			$out .= '<img class="fj-px" width="1" height="1" alt="" src="' . esc_url($m['text']['pixel']) . '">';
		}
	}
	return $out ? '<div class="fj-px-wrap">' . $out . '</div>' : '';
}

/* ============================================================
 * 記事ごとのパターン判定
 * ========================================================== */

/** 具体的なカテゴリから順に見る。親カテゴリは最後 */
function fjcta_slug_priority() {
	return array(
		'souba', 'takuhai-kaitori', 'mochikomi-kaitori',
		'suit-sagyofuku', 'kodomofuku', 'ihin',
		'area-city', 'area-pref',
		'kaishu-box', 'muryo-kaishu', 'kifu-recycle',
		'shobun-hoho', 'bunbetsu', 'tebanasu-kijun',
		'uru', 'kaishu', 'area', 'suteru',
	);
}

/**
 * 投稿のパターンキーを返す。出さないときは空文字。
 */
function fjcta_pattern_key($post_id) {
	if (in_array((int) $post_id, fjcta_excluded_ids(), true)) return '';

	$slugs = wp_get_post_terms($post_id, 'category', array('fields' => 'slugs'));
	if (is_wp_error($slugs) || !$slugs) return '';

	$map = fjcta_category_map();

	// 買取の対象になりにくい品目が付いていたら、ほかに何が付いていても出さない
	foreach ($slugs as $s) {
		if (isset($map[$s]) && $map[$s] === 'none') return '';
	}

	foreach (fjcta_slug_priority() as $s) {
		if (in_array($s, $slugs, true) && isset($map[$s]) && $map[$s] !== 'none') {
			return $map[$s];
		}
	}
	return '';
}

function fjcta_pattern($post_id) {
	$key = fjcta_pattern_key($post_id);
	if ($key === '') return null;
	$all = fjcta_patterns();
	return isset($all[$key]) ? $all[$key] : null;
}

/** いま表示している記事で出す対象か */
function fjcta_target_post_id() {
	if (!is_singular('post')) return 0;
	$id = (int) get_queried_object_id();
	return $id ? $id : 0;
}

/**
 * その記事がサイドバーを出さない設定か（無料ツールのページが該当する）。
 * re:Diverの diver_single_layout に sidebar=hide が入っている。
 */
function fjcta_sidebar_hidden($post_id) {
	$raw = get_post_meta($post_id, 'diver_single_layout', true);
	if (is_string($raw) && $raw !== '') $raw = maybe_unserialize($raw);
	if (!is_array($raw)) return false;
	return (isset($raw['sidebar']) && $raw['sidebar'] === 'hide');
}

/* ============================================================
 * CTAのHTML
 * ========================================================== */

function fjcta_block_lead($p) {
	$h  = '<div class="fj-cta fj-cta-lead">';
	$h .= '<p>' . wp_kses_post($p['lead']['text']) . '</p>';
	$h .= fjcta_button($p['hero'], $p['lead']['btn']);
	$h .= '</div>';
	return $h;
}

function fjcta_block_mid($p) {
	$m = $p['mid'];
	$h  = '<p class="fj-cta-mid">';
	$h .= wp_kses_post($m['before']);
	$h .= fjcta_a($p['hero'], esc_html($m['link']));
	$h .= wp_kses_post($m['after']);
	$h .= '</p>';
	return $h;
}

function fjcta_block_end($p) {
	$e = $p['end'];

	$others = $e['others'];
	foreach ($p['others'] as $i => $key) {
		$others = str_replace('{a' . $i . '}', fjcta_a($key, esc_html(fjcta_name($key))), $others);
	}

	$h  = '<div class="fj-cta fj-cta-end">';
	$h .= '<p class="fj-nm">' . esc_html($e['title']) . '</p>';
	$h .= '<p class="fj-de">' . wp_kses_post($e['desc']) . '</p>';
	$h .= '<p class="fj-cond">' . wp_kses_post($e['cond']) . '</p>';
	$h .= fjcta_button($p['hero'], $e['btn']);
	$h .= '<p class="fj-asof">広告主が公開している条件を2026年10月に確認しました。</p>';
	$h .= '</div>';
	// 残り2社は箱の外に置いて、主役との差をつける
	$h .= '<p class="fj-others">' . $others . '</p>';
	return $h;
}

/* ============================================================
 * 本文への挿入
 * ========================================================== */

add_filter('the_content', 'fjcta_inject_content', 20);

function fjcta_inject_content($content) {
	if (is_admin() || !fjcta_opt('cta_enabled')) return $content;
	if (!is_singular('post') || !in_the_loop() || !is_main_query()) return $content;
	if (strpos($content, 'fj-cta') !== false) return $content;

	$post_id = get_the_ID();
	$p = fjcta_pattern($post_id);
	if (!$p) return $content;

	$keys = array_merge(array($p['hero']), $p['others']);

	if (!preg_match_all('/<h2[\s>]/i', $content, $m, PREG_OFFSET_CAPTURE)) {
		return $content . fjcta_block_end($p) . fjcta_pixels($keys);
	}

	$pos = array();
	foreach ($m[0] as $hit) $pos[] = (int) $hit[1];
	$n = count($pos);

	$ins = array();
	$ins[$pos[0]] = fjcta_block_lead($p);

	// 途中は、中ほどの見出しの最初の段落のあと。まとめの見出しは避ける
	if ($n >= 3) {
		$idx = intdiv($n, 2);
		if ($idx > $n - 2) $idx = $n - 2;
		$p_end = stripos($content, '</p>', $pos[$idx]);
		if ($p_end !== false) {
			$at = $p_end + 4;
			if (!isset($ins[$at])) $ins[$at] = fjcta_block_mid($p);
		}
	}

	ksort($ins);
	$out  = '';
	$prev = 0;
	foreach ($ins as $at => $html) {
		$out .= substr($content, $prev, $at - $prev) . $html;
		$prev = $at;
	}
	$out .= substr($content, $prev);
	$out .= fjcta_block_end($p);
	$out .= fjcta_pixels($keys);

	return $out;
}

/* ============================================================
 * 追従バナー
 * ========================================================== */

function fjcta_banner_html($p, $size) {
	$m = fjcta_material($p['hero']);
	$k = ($size === 'sp') ? 'banner_320x50' : 'banner_300x250';
	if (!$m || empty($m[$k]['href']) || empty($m[$k]['img'])) return '';

	$b   = $m[$k];
	$map = array(
		'sp'   => 'fj-bn fj-bn-sp',
		'side' => 'fj-bn fj-bn-side',
		'fix'  => 'fj-bn fj-bn-fix',
	);
	$cls = isset($map[$size]) ? $map[$size] : $map['side'];

	$h  = '<div class="' . $cls . '" data-fj-bn="' . esc_attr($size) . '" hidden>';
	$h .= '<button type="button" class="fj-bn-x" aria-label="広告を閉じる">×</button>';
	$h .= '<a href="' . esc_url($b['href']) . '" target="_blank" rel="nofollow sponsored noopener">';
	$h .= '<img src="' . esc_url($b['img']) . '" width="' . (int) $b['w'] . '" height="' . (int) $b['h'] . '" alt="' . esc_attr(fjcta_name($p['hero'])) . '" loading="lazy">';
	$h .= '</a>';
	if (!empty($b['pixel'])) {
		$h .= '<img class="fj-px" width="1" height="1" alt="" src="' . esc_url($b['pixel']) . '">';
	}
	$h .= '</div>';
	return $h;
}

/** PC: サイドバー（追従）の末尾 */
add_action('dynamic_sidebar_after', 'fjcta_banner_sidebar', 10, 2);

function fjcta_banner_sidebar($index, $has_widgets = true) {
	if ($index !== 'sidebar-primary-sticky') return;
	if (!fjcta_opt('banner_enabled')) return;
	$id = fjcta_target_post_id();
	if (!$id) return;
	$p = fjcta_pattern($id);
	if (!$p) return;
	echo fjcta_banner_html($p, 'side');
}

/**
 * スマホ: 画面下に固定。
 * サイドバーを出さない記事（無料ツールのページ）は、PCでも置き場所が無いので画面右下に固定する。
 */
add_action('wp_footer', 'fjcta_banner_footer', 20);

function fjcta_banner_footer() {
	if (!fjcta_opt('banner_enabled')) return;
	$id = fjcta_target_post_id();
	if (!$id) return;
	$p = fjcta_pattern($id);
	if (!$p) return;

	echo fjcta_banner_html($p, 'sp');
	if (fjcta_sidebar_hidden($id)) {
		echo fjcta_banner_html($p, 'fix');
	}
}

/* ============================================================
 * CSS / JS
 * ========================================================== */

add_action('wp_enqueue_scripts', function () {
	$id = fjcta_target_post_id();
	if (!$id || !fjcta_pattern($id)) return;

	wp_enqueue_style('fjcta', FJCTA_URL . 'assets/fjcta.css', array(), FJCTA_VERSION);
	if (fjcta_opt('banner_enabled')) {
		wp_enqueue_script('fjcta', FJCTA_URL . 'assets/fjcta.js', array(), FJCTA_VERSION, true);
	}
});

/* ============================================================
 * 設定画面
 * ========================================================== */

add_action('admin_menu', function () {
	add_options_page('服じまい CTA', '服じまい CTA', 'manage_options', 'fjcta', 'fjcta_settings_page');
});

function fjcta_settings_page() {
	if (!current_user_can('manage_options')) return;

	if (isset($_POST['fjcta_nonce']) && wp_verify_nonce($_POST['fjcta_nonce'], 'fjcta_save')) {
		update_option('fjcta_settings', array(
			'cta_enabled'    => isset($_POST['cta_enabled']) ? 1 : 0,
			'banner_enabled' => isset($_POST['banner_enabled']) ? 1 : 0,
			'exclude_ids'    => sanitize_text_field(wp_unslash($_POST['exclude_ids'])),
		));
		echo '<div class="notice notice-success"><p>保存しました。</p></div>';
	}

	$counts = array();
	$q = new WP_Query(array(
		'post_type'      => 'post',
		'post_status'    => 'publish',
		'posts_per_page' => -1,
		'fields'         => 'ids',
		'no_found_rows'  => true,
	));
	foreach ($q->posts as $pid) {
		$k = fjcta_pattern_key($pid);
		$k = ($k === '') ? '（出さない）' : $k;
		$counts[$k] = isset($counts[$k]) ? $counts[$k] + 1 : 1;
	}
	ksort($counts);
	?>
	<div class="wrap">
		<h1>服じまい CTA・追従バナー <span style="font-size:13px;color:#666">v<?php echo esc_html(FJCTA_VERSION); ?></span></h1>
		<form method="post">
			<?php wp_nonce_field('fjcta_save', 'fjcta_nonce'); ?>
			<table class="form-table">
				<tr>
					<th scope="row">本文のCTA</th>
					<td><label><input type="checkbox" name="cta_enabled" value="1" <?php checked(fjcta_opt('cta_enabled'), 1); ?>> 最初のh2の上・途中・まとめのあとに出す</label></td>
				</tr>
				<tr>
					<th scope="row">追従バナー</th>
					<td><label><input type="checkbox" name="banner_enabled" value="1" <?php checked(fjcta_opt('banner_enabled'), 1); ?>> PCはサイドバー追従、スマホは画面下に固定</label></td>
				</tr>
				<tr>
					<th scope="row">出さない記事ID</th>
					<td>
						<input type="text" class="regular-text" name="exclude_ids" value="<?php echo esc_attr(fjcta_opt('exclude_ids')); ?>" placeholder="1234, 1235">
						<p class="description">カンマ区切り。喪服・着物／制服・体操服／靴・家具のカテゴリは指定しなくても出ません。</p>
					</td>
				</tr>
			</table>
			<?php submit_button(); ?>
		</form>

		<h2>いまの振り分け（公開記事）</h2>
		<table class="widefat striped" style="max-width:520px">
			<thead><tr><th>パターン</th><th>主役</th><th>記事数</th></tr></thead>
			<tbody>
			<?php
			$all = fjcta_patterns();
			foreach ($counts as $k => $v) {
				$hero = isset($all[$k]['hero']) ? fjcta_name($all[$k]['hero']) : '—';
				echo '<tr><td>' . esc_html($k) . '</td><td>' . esc_html($hero) . '</td><td>' . (int) $v . '</td></tr>';
			}
			?>
			</tbody>
		</table>
	</div>
	<?php
}
