<?php
/**
 * Plugin Name: 給湯器エラーコード診断
 * Plugin URI:  https://oyu-navi.com/
 * Description: リモコンに出たエラー番号から、メーカー公式の記載をもとに意味と対処を表示します。ショートコード [kyutoki_shindan] で設置します。
 * Version:     1.0.2
 * Author:      Keys株式会社
 * License:     GPLv2 or later
 * Text Domain: kyutoki-shindan
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

define( 'KYUTOKI_SHINDAN_VER', '1.0.2' );
define( 'KYUTOKI_SHINDAN_DIR', plugin_dir_path( __FILE__ ) );
define( 'KYUTOKI_SHINDAN_URL', plugin_dir_url( __FILE__ ) );

// 自動更新（GitHub直配信の更新チェック）
require_once __DIR__ . '/includes/plugin-updater.php';
add_action( 'init', function () {
	new Kyutoki_Plugin_Updater( __FILE__, 'https://raw.githubusercontent.com/yoshimucom-gif/wp-manager/main/plugin-host/api/plugin-update/kyutoki-shindan' );
} );

/**
 * 公式ファクトの読み込み。ビルド時に data/codes.json を同梱している。
 */
function kyutoki_shindan_data() {
	static $data = null;
	if ( null !== $data ) { return $data; }
	$path = KYUTOKI_SHINDAN_DIR . 'data/codes.json';
	$data = file_exists( $path )
		? json_decode( file_get_contents( $path ), true )
		: array();
	return $data;
}

/**
 * 記事下のCTA設定。提携先が決まったら管理画面から入れ替える。
 */
function kyutoki_shindan_cta_defaults() {
	return array(
		'repair_title' => '修理できる業者を探す',
		'repair_text'  => '出張費や見積もりの扱いは業者ごとに違います。複数社の条件を比べてから決めてください。',
		'repair_label' => '修理の見積もりを見る',
		'repair_url'   => '',
		'replace_title' => '交換を検討する',
		'replace_text'  => '設置から10年を超えている場合は、修理より交換のほうが結果的に安く済むことがあります。',
		'replace_label' => '交換の見積もりを見る',
		'replace_url'   => '',
	);
}

function kyutoki_shindan_cta() {
	$saved = get_option( 'kyutoki_shindan_cta', array() );
	return wp_parse_args( is_array( $saved ) ? $saved : array(),
		kyutoki_shindan_cta_defaults() );
}

/**
 * CSS・JSはキューに載せる（本文に直書きしない）。
 * ショートコードのある画面でだけ読み込む。
 */
function kyutoki_shindan_register_assets() {
	wp_register_style(
		'kyutoki-shindan',
		KYUTOKI_SHINDAN_URL . 'assets/shindan.css',
		array(),
		KYUTOKI_SHINDAN_VER
	);
	wp_register_script(
		'kyutoki-shindan',
		KYUTOKI_SHINDAN_URL . 'assets/shindan.js',
		array(),
		KYUTOKI_SHINDAN_VER,
		true
	);
}
add_action( 'wp_enqueue_scripts', 'kyutoki_shindan_register_assets' );

/**
 * ★ショートコードが本文にある画面では、本文の処理を待たずにここで読み込む。
 *   ショートコードの中だけで enqueue すると、CSSが head に間に合わず
 *   一瞬だけ素の状態が見えることがある。
 */
function kyutoki_shindan_maybe_enqueue() {
	if ( ! is_singular() ) { return; }
	$post = get_post();
	if ( $post && has_shortcode( $post->post_content, 'kyutoki_shindan' ) ) {
		kyutoki_shindan_enqueue();
	}
}
add_action( 'wp_enqueue_scripts', 'kyutoki_shindan_maybe_enqueue', 20 );

function kyutoki_shindan_enqueue() {
	static $done = false;
	if ( $done ) { return; }          // 1画面に2つ置いても二重に出さない
	$done = true;
	wp_enqueue_style( 'kyutoki-shindan' );
	wp_enqueue_script( 'kyutoki-shindan' );
	wp_localize_script( 'kyutoki-shindan', 'KYUTOKI_SHINDAN', array(
		'makers'    => kyutoki_shindan_data(),
		'cta'       => kyutoki_shindan_cta(),
		'fetchedAt' => get_option( 'kyutoki_shindan_fetched_at', '2026-08-04' ),
	) );
}

/**
 * ショートコード [kyutoki_shindan]
 */
function kyutoki_shindan_shortcode( $atts ) {
	kyutoki_shindan_enqueue();

	$atts = shortcode_atts( array(
		'title' => '給湯器エラーコード診断',
	), $atts, 'kyutoki_shindan' );

	$makers = kyutoki_shindan_data();

	// 1画面に2つ置かれてもラベルの結び付きが壊れないよう、入力欄のidを個別にする
	static $seq = 0;
	$seq++;
	$input_id = 'kys-input-' . $seq;

	ob_start(); ?>
	<div class="kys" data-kys>
		<h2 class="kys__title"><?php echo esc_html( $atts['title'] ); ?></h2>
		<p class="kys__lead">リモコンに出ている番号を選ぶと、メーカーが公式に出している意味と対処を表示します。表示している内容は、すべてメーカーの公式ページで確認したものです。</p>

		<div class="kys__step" data-kys-step="1">
			<p class="kys__steplabel"><span class="kys__num">1</span>メーカーを選んでください</p>
			<div class="kys__makers">
				<?php foreach ( $makers as $key => $m ) : ?>
					<button type="button" class="kys__maker" data-kys-maker="<?php echo esc_attr( $key ); ?>">
						<?php echo esc_html( $m['name'] ); ?>
					</button>
				<?php endforeach; ?>
			</div>
			<p class="kys__hint">メーカーは、給湯器本体の正面か側面に貼ってある銘板（型番の書かれたシール）で確認できます。</p>
		</div>

		<div class="kys__step" data-kys-step="2" hidden>
			<p class="kys__steplabel"><span class="kys__num">2</span><span data-kys-makername></span>で出ている番号を選んでください</p>
			<div class="kys__codes" data-kys-codes></div>
			<div class="kys__manual">
				<label for="<?php echo esc_attr( $input_id ); ?>">一覧にない番号はこちらに入力してください</label>
				<div class="kys__inputrow">
					<input type="text" id="<?php echo esc_attr( $input_id ); ?>" data-kys-input inputmode="numeric" autocomplete="off" placeholder="例：111">
					<button type="button" class="kys__go" data-kys-go>調べる</button>
				</div>
			</div>
			<button type="button" class="kys__back" data-kys-back>メーカーを選び直す</button>
		</div>

		<div class="kys__result" data-kys-result role="status" aria-live="polite" hidden></div>

		<p class="kys__note">この診断は、メーカーが公式ページで公開している内容をそのまま表示するものです。同じ番号でもメーカーや機種によって意味が変わります。実際の点検・修理はメーカーまたは有資格の業者へ依頼してください。ガス機器の分解や改造は行わないでください。</p>
	</div>
	<?php
	return ob_get_clean();
}
add_shortcode( 'kyutoki_shindan', 'kyutoki_shindan_shortcode' );

/**
 * 設定画面（CTAの文言とリンク先）
 */
function kyutoki_shindan_menu() {
	add_options_page(
		'給湯器エラー診断',
		'給湯器エラー診断',
		'manage_options',
		'kyutoki-shindan',
		'kyutoki_shindan_settings_page'
	);
}
add_action( 'admin_menu', 'kyutoki_shindan_menu' );

function kyutoki_shindan_settings_init() {
	register_setting( 'kyutoki_shindan', 'kyutoki_shindan_cta', array(
		'type'              => 'array',
		'sanitize_callback' => 'kyutoki_shindan_sanitize_cta',
		'default'           => kyutoki_shindan_cta_defaults(),
	) );
}
add_action( 'admin_init', 'kyutoki_shindan_settings_init' );

function kyutoki_shindan_sanitize_cta( $in ) {
	$out = array();
	foreach ( kyutoki_shindan_cta_defaults() as $k => $default ) {
		$v = isset( $in[ $k ] ) ? $in[ $k ] : $default;
		$out[ $k ] = ( '_url' === substr( $k, -4 ) )
			? esc_url_raw( trim( $v ) )
			: sanitize_text_field( $v );
	}
	return $out;
}

function kyutoki_shindan_settings_page() {
	$cta    = kyutoki_shindan_cta();
	$data   = kyutoki_shindan_data();
	$total  = 0;
	foreach ( $data as $m ) { $total += count( $m['codes'] ); }
	?>
	<div class="wrap">
		<h1>給湯器エラー診断</h1>
		<p>記事や固定ページに <code>[kyutoki_shindan]</code> と書くと診断が表示されます。
			現在 <strong><?php echo (int) $total; ?>件</strong>のエラーコードを収録しています。</p>
		<form method="post" action="options.php">
			<?php settings_fields( 'kyutoki_shindan' ); ?>
			<h2>修理向けの案内</h2>
			<p>「業者に依頼」と判定されたときに出す案内です。</p>
			<table class="form-table"><tbody>
				<?php foreach ( array(
					'repair_title' => '見出し',
					'repair_text'  => '説明文',
					'repair_label' => 'ボタンの文字',
					'repair_url'   => 'リンク先URL',
				) as $k => $label ) : ?>
				<tr>
					<th scope="row"><label for="kys-<?php echo esc_attr( $k ); ?>"><?php echo esc_html( $label ); ?></label></th>
					<td><input type="text" class="regular-text" id="kys-<?php echo esc_attr( $k ); ?>"
						name="kyutoki_shindan_cta[<?php echo esc_attr( $k ); ?>]"
						value="<?php echo esc_attr( $cta[ $k ] ); ?>"></td>
				</tr>
				<?php endforeach; ?>
			</tbody></table>

			<h2>交換向けの案内</h2>
			<p>寿命・部品交換が絡む番号のときに、修理の案内とあわせて出します。</p>
			<table class="form-table"><tbody>
				<?php foreach ( array(
					'replace_title' => '見出し',
					'replace_text'  => '説明文',
					'replace_label' => 'ボタンの文字',
					'replace_url'   => 'リンク先URL',
				) as $k => $label ) : ?>
				<tr>
					<th scope="row"><label for="kys-<?php echo esc_attr( $k ); ?>"><?php echo esc_html( $label ); ?></label></th>
					<td><input type="text" class="regular-text" id="kys-<?php echo esc_attr( $k ); ?>"
						name="kyutoki_shindan_cta[<?php echo esc_attr( $k ); ?>]"
						value="<?php echo esc_attr( $cta[ $k ] ); ?>"></td>
				</tr>
				<?php endforeach; ?>
			</tbody></table>
			<p class="description">リンク先URLが空のあいだは、ボタンは表示されません。</p>
			<?php submit_button(); ?>
		</form>
	</div>
	<?php
}
