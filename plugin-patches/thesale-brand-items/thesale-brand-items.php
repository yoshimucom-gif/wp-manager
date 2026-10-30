<?php
/**
 * Plugin Name: THE SALE ブランド商品カード
 * Description: 記事のブランド名（ブランドの記事）または商品名（買い時の記事）で Amazon の商品を引き、在庫のある商品をカードで並べる。Amazon・楽天のボタン付き。記事への自動差し込み・サイドバー（ウィジェット）・ショートコード [thesale_items]（サイドバーは [thesale_items layout="side" n="3"]）。商品は1日1回まとめて取り直して保存する。Affiros オートインサーターとは別管理。
 * Version:     1.0.7
 * Author:      Keys
 * Text Domain: thesale-brand-items
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// 自動更新（機能より先に組み込む。wp-plugin-autoupdate の手順）
require_once __DIR__ . '/includes/plugin-updater.php';
add_action( 'init', function () {
	new Tsbi_Plugin_Updater(
		__FILE__,
		'https://raw.githubusercontent.com/yoshimucom-gif/wp-manager/main/plugin-host/api/plugin-update/thesale-brand-items'
	);
} );

/**
 * 新版の即時適用: POST /wp-json/tsbi/v1/selfupdate（thesale-schedule と同じ窓口）
 */
add_action( 'rest_api_init', function () {
	register_rest_route( 'tsbi/v1', '/selfupdate', array(
		'methods'             => 'POST',
		'permission_callback' => function () { return current_user_can( 'update_plugins' ); },
		'callback'            => function () {
			$base = plugin_basename( __FILE__ );
			$data = get_file_data( __FILE__, array( 'v' => 'Version' ) );
			delete_transient( 'tsbi_updater_' . md5( $base ) );
			delete_site_transient( 'update_plugins' );
			wp_update_plugins();
			$upd = get_site_transient( 'update_plugins' );
			if ( empty( $upd->response[ $base ] ) ) {
				return array( 'ok' => true, 'updated' => false, 'version' => $data['v'] );
			}
			require_once ABSPATH . 'wp-admin/includes/file.php';
			require_once ABSPATH . 'wp-admin/includes/misc.php';
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
			require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
			$up  = new Plugin_Upgrader( new Automatic_Upgrader_Skin() );
			$res = $up->upgrade( $base );
			$active = (array) get_option( 'active_plugins', array() );
			if ( ! in_array( $base, $active, true ) ) {
				$active[] = $base;
				update_option( 'active_plugins', array_values( array_unique( $active ) ) );
			}
			return array( 'ok' => true === $res, 'updated' => true === $res, 'version_before' => $data['v'],
				'to' => isset( $upd->response[ $base ]->new_version ) ? $upd->response[ $base ]->new_version : '' );
		},
	) );
} );

/* ── 設定 ──────────────────────────────────────────────── */

function tsbi_settings() {
	$d = array( 'enabled' => 1, 'count' => 6, 'position' => 'end', 'sidebar_count' => 3, 'top_count' => 3 );
	$s = get_option( 'tsbi_settings', array() );
	return is_array( $s ) ? array_merge( $d, $s ) : $d;
}

/**
 * 記事ごとの検索語（オプション tsbi_keywords。THE SALE のツール tools/push_item_keywords.py が書く）
 *  { "<スラッグ>": { "kw": "ドクターマーチン", "label": "ドクターマーチン", "kind": "brand", "match": ["dr.martens","ドクターマーチン"] } }
 *  kind=brand … ブランド/メーカー/商品名に match のどれかが入る商品だけ残す
 *  kind=product … 商品名に match のどれかが入る商品だけ残す
 *  無い記事・"off": true の記事には出さない（モール・季節・アウトレット等）
 */
function tsbi_keywords() {
	$k = get_option( 'tsbi_keywords', array() );
	if ( is_string( $k ) ) {
		$k = json_decode( $k, true );
	}
	return is_array( $k ) ? $k : array();
}

function tsbi_entry_for_post( $post = null ) {
	$post = get_post( $post );
	if ( ! $post || 'post' !== $post->post_type ) {
		return null;
	}
	$map = tsbi_keywords();
	$e   = isset( $map[ $post->post_name ] ) ? $map[ $post->post_name ] : null;
	if ( ! is_array( $e ) || empty( $e['kw'] ) || ! empty( $e['off'] ) ) {
		return null;
	}
	return $e;
}

/** Amazon・楽天の認証情報。Affiros オートインサーターの設定があればそれを読む（新しい審査や設定は不要） */
function tsbi_creds() {
	$a = get_option( 'affiros_ai_settings', array() );
	$a = is_array( $a ) ? $a : array();
	return array(
		'client_id'     => isset( $a['amazon_client_id'] ) ? $a['amazon_client_id'] : '',
		'client_secret' => isset( $a['amazon_client_secret'] ) ? $a['amazon_client_secret'] : '',
		'tag'           => isset( $a['amazon_partner_tag'] ) ? $a['amazon_partner_tag'] : '',
		'marketplace'   => ! empty( $a['amazon_marketplace'] ) ? $a['amazon_marketplace'] : 'www.amazon.co.jp',
		'rakuten_aff'   => isset( $a['rakuten_affiliate_id'] ) ? $a['rakuten_affiliate_id'] : '',
	);
}

/* ── Amazon から取る（Creators API） ───────────────────────── */

function tsbi_amazon_token( $c ) {
	$t = get_transient( 'tsbi_amz_token' );
	if ( $t ) {
		return $t;
	}
	$r = wp_remote_post( 'https://api.amazon.com/auth/o2/token', array(
		'timeout' => 20,
		'headers' => array( 'Content-Type' => 'application/json' ),
		'body'    => wp_json_encode( array( 'grant_type' => 'client_credentials', 'client_id' => $c['client_id'],
			'client_secret' => $c['client_secret'], 'scope' => 'creatorsapi::default' ) ),
	) );
	$j = json_decode( wp_remote_retrieve_body( $r ), true );
	if ( empty( $j['access_token'] ) ) {
		return '';
	}
	set_transient( 'tsbi_amz_token', $j['access_token'], max( 60, (int) ( isset( $j['expires_in'] ) ? $j['expires_in'] : 3600 ) - 300 ) );
	return $j['access_token'];
}

function tsbi_norm( $s ) {
	$s = function_exists( 'mb_strtolower' ) ? mb_strtolower( (string) $s ) : strtolower( (string) $s );
	return preg_replace( '/[\s・.\-\'’&＆　]/u', '', $s );
}

/** 検索語で Amazon を引き、在庫あり・条件に合う商品だけを返す */
function tsbi_fetch( $e ) {
	$c = tsbi_creds();
	if ( '' === $c['client_id'] || '' === $c['tag'] ) {
		return array( 'error' => 'Amazonの認証情報が無い（Affirosの設定を確認）', 'items' => array() );
	}
	$tok = tsbi_amazon_token( $c );
	if ( '' === $tok ) {
		return array( 'error' => 'Amazonの認証に失敗', 'items' => array() );
	}
	$keys = array();
	foreach ( array_merge( array( $e['kw'] ), isset( $e['match'] ) ? (array) $e['match'] : array() ) as $m ) {
		$n = tsbi_norm( $m );
		if ( '' !== $n ) {
			$keys[] = $n;
		}
	}
	$kind  = isset( $e['kind'] ) ? $e['kind'] : 'brand';
	$items = array();
	$seen  = array();
	foreach ( array( 1, 2 ) as $page ) {
		$r = wp_remote_post( 'https://creatorsapi.amazon/catalog/v1/searchItems', array(
			'timeout' => 25,
			'headers' => array( 'Authorization' => 'Bearer ' . $tok, 'Content-Type' => 'application/json', 'x-marketplace' => $c['marketplace'] ),
			'body'    => wp_json_encode( array( 'keywords' => $e['kw'], 'itemCount' => 10, 'itemPage' => $page, 'partnerTag' => $c['tag'],
				'resources' => array( 'images.primary.large', 'itemInfo.title', 'itemInfo.byLineInfo', 'offersV2.listings.availability' ) ) ),
		) );
		$j = json_decode( wp_remote_retrieve_body( $r ), true );
		$list = isset( $j['searchResult']['items'] ) ? $j['searchResult']['items'] : array();
		foreach ( $list as $it ) {
			$asin = isset( $it['asin'] ) ? $it['asin'] : '';
			if ( '' === $asin || isset( $seen[ $asin ] ) || preg_match( '/^4\d{9}$/', $asin ) ) {   // 本（ISBN）は外す
				continue;
			}
			$seen[ $asin ] = 1;
			$av = isset( $it['offersV2']['listings'][0]['availability']['type'] ) ? $it['offersV2']['listings'][0]['availability']['type'] : '';
			if ( ! in_array( $av, array( 'IN_STOCK', 'IN_STOCK_SCARCE' ), true ) ) {
				continue;
			}
			$title = isset( $it['itemInfo']['title']['displayValue'] ) ? $it['itemInfo']['title']['displayValue'] : '';
			$brand = isset( $it['itemInfo']['byLineInfo']['brand']['displayValue'] ) ? $it['itemInfo']['byLineInfo']['brand']['displayValue'] : '';
			$maker = isset( $it['itemInfo']['byLineInfo']['manufacturer']['displayValue'] ) ? $it['itemInfo']['byLineInfo']['manufacturer']['displayValue'] : '';
			$hay   = tsbi_norm( 'product' === $kind ? $title : $brand . ' ' . $maker . ' ' . $title );
			$ok    = false;
			foreach ( $keys as $k ) {
				if ( false !== strpos( $hay, $k ) ) {
					$ok = true;
					break;
				}
			}
			$img = isset( $it['images']['primary']['large']['url'] ) ? $it['images']['primary']['large']['url'] : '';
			if ( ! $ok || '' === $img || '' === $title ) {
				continue;
			}
			// 整備済み品・中古は出さない（メーカーの新品ではない。2026-10-30 デルの記事で半分近くが整備済み品だった）
			if ( preg_match( '/整備済み|整備品|中古|リファービッシュ|refurbished/iu', $title ) ) {
				continue;
			}
			$items[] = array( 'asin' => $asin, 'title' => $title, 'image' => $img,
				'url' => isset( $it['detailPageURL'] ) ? $it['detailPageURL'] : 'https://www.amazon.co.jp/dp/' . $asin . '?tag=' . rawurlencode( $c['tag'] ) );
		}
		if ( count( $items ) >= 12 || count( $list ) < 10 ) {
			break;
		}
		usleep( 1100000 );   // Amazonは1秒1回まで
	}
	return array( 'error' => '', 'items' => array_slice( $items, 0, 12 ) );
}

function tsbi_cache_key( $kw ) {
	return 'tsbi_items_' . md5( $kw );
}

/** 保存済みの商品（無ければ取りに行く予約だけ入れて空を返す。ページ表示中にAPIを待たせない） */
function tsbi_items( $e ) {
	$c = get_option( tsbi_cache_key( $e['kw'] ) );
	if ( is_array( $c ) && isset( $c['items'] ) ) {
		return $c['items'];
	}
	if ( ! wp_next_scheduled( 'tsbi_refresh_one', array( $e['kw'] ) ) ) {
		wp_schedule_single_event( time() + 30, 'tsbi_refresh_one', array( $e['kw'] ) );
	}
	return array();
}

function tsbi_refresh_kw( $kw ) {
	foreach ( tsbi_keywords() as $e ) {
		if ( is_array( $e ) && isset( $e['kw'] ) && $e['kw'] === $kw ) {
			$r = tsbi_fetch( $e );
			if ( '' === $r['error'] ) {   // 失敗したときは前の商品を残す
				update_option( tsbi_cache_key( $kw ), array( 'time' => time(), 'items' => $r['items'] ), false );
			}
			return $r;
		}
	}
	return array( 'error' => '検索語が登録されていない', 'items' => array() );
}
add_action( 'tsbi_refresh_one', 'tsbi_refresh_kw' );

/** 1日1回、登録された検索語をすべて取り直す */
function tsbi_refresh_all() {
	$done = array();
	foreach ( tsbi_keywords() as $e ) {
		if ( ! is_array( $e ) || empty( $e['kw'] ) || ! empty( $e['off'] ) || isset( $done[ $e['kw'] ] ) ) {
			continue;
		}
		$r = tsbi_refresh_kw( $e['kw'] );
		$done[ $e['kw'] ] = count( $r['items'] );
		usleep( 1200000 );
	}
	update_option( 'tsbi_last_refresh', array( 'time' => time(), 'counts' => $done ), false );
	return $done;
}
add_action( 'tsbi_refresh_all', 'tsbi_refresh_all' );
add_action( 'init', function () {
	if ( ! wp_next_scheduled( 'tsbi_refresh_all' ) ) {
		wp_schedule_event( strtotime( 'tomorrow 04:30:00' ) - (int) ( get_option( 'gmt_offset' ) * HOUR_IN_SECONDS ), 'daily', 'tsbi_refresh_all' );
	}
} );
register_deactivation_hook( __FILE__, function () {
	wp_clear_scheduled_hook( 'tsbi_refresh_all' );
} );

/* ── 表示 ──────────────────────────────────────────────── */

function tsbi_rakuten_url( $title ) {
	$c = tsbi_creds();
	$q = preg_replace( '/[\[\]【】（）()「」]/u', ' ', $title );
	$q = trim( preg_replace( '/\s+/u', ' ', $q ) );
	$q = function_exists( 'mb_substr' ) ? mb_substr( $q, 0, 40 ) : substr( $q, 0, 80 );
	$u = 'https://search.rakuten.co.jp/search/mall/' . rawurlencode( $q ) . '/';
	if ( '' === $c['rakuten_aff'] ) {
		return $u;
	}
	return 'https://hb.afl.rakuten.co.jp/hgc/' . $c['rakuten_aff'] . '/?pc=' . rawurlencode( $u ) . '&m=' . rawurlencode( $u );
}

/** 商品画像の alt（商品名の先頭60字。【】の宣伝表記は外し、[ブランド名] は中身を残す） */
function tsbi_alt( $title ) {
	$t = preg_replace( '/【[^】]*】/u', ' ', (string) $title );          // 【送料無料】などの宣伝表記は外す
	$t = preg_replace( '/[\[［]([^\]］]*)[\]］]/u', '$1 ', $t );        // [ナイキ] のブランド名は中身を残す
	$t = trim( preg_replace( '/\s+/u', ' ', $t ) );
	return function_exists( 'mb_substr' ) ? mb_substr( $t, 0, 60 ) : substr( $t, 0, 120 );
}

function tsbi_card( $it, $compact = false ) {
	return '<div class="tsbi-card' . ( $compact ? ' is-compact' : '' ) . '">'
		. '<a class="tsbi-img" href="' . esc_url( $it['url'] ) . '" target="_blank" rel="nofollow sponsored noopener"><img src="' . esc_url( $it['image'] ) . '" alt="' . esc_attr( tsbi_alt( $it['title'] ) ) . '" loading="lazy" width="300" height="300"></a>'
		. '<p class="tsbi-title">' . esc_html( $it['title'] ) . '</p>'
		. '<div class="tsbi-btns"><a class="tsbi-btn tsbi-am" href="' . esc_url( $it['url'] ) . '" target="_blank" rel="nofollow sponsored noopener">Amazonで見る<span aria-hidden="true">&#8599;</span></a>'
		. '<a class="tsbi-btn tsbi-rk" href="' . esc_url( tsbi_rakuten_url( $it['title'] ) ) . '" target="_blank" rel="nofollow sponsored noopener">楽天で探す<span aria-hidden="true">&#8599;</span></a></div>'
		. '</div>';
}

function tsbi_block( $e, $n, $offset = 0 ) {
	$all   = tsbi_items( $e );
	// 最初の見出しの上に出した商品と重ならないよう、ずらして取る（足りなければ先頭から）
	$items = array_slice( $all, $offset, max( 1, (int) $n ) );
	if ( count( $items ) < 3 ) {
		$items = array_slice( $all, 0, max( 1, (int) $n ) );
	}
	if ( count( $items ) < 3 ) {   // 3点に満たないときは出さない（並びが崩れる・選択肢として弱い）
		return '';
	}
	$label = ! empty( $e['label'] ) ? $e['label'] : $e['kw'];
	$o = '<section class="tsbi"><p class="tsbi-head">' . esc_html( $label ) . 'の商品をAmazon・楽天で見る</p>'
		. '<p class="tsbi-note">Amazonで在庫のある商品です。価格・在庫は変わるため、リンク先でご確認ください。</p><div class="tsbi-grid">';
	foreach ( $items as $it ) {
		$o .= tsbi_card( $it );
	}
	return $o . '</div></section>';
}

/**
 * 最初のH2見出しの上に出す3点（1.0.4〜）。
 * 1.0.5〜: Affiros オートインサーターが入っていれば、その3商品比較カードの描画と
 * 開催中セールのマイクロコピー（＼お買い物マラソン開催中／）をそのまま使う＝見た目と挙動を既存カードにそろえる。
 * 入っていないときだけ、このプラグインの小さめのカードで出す。
 */
function tsbi_top_block( $e, $n ) {
	$items = array_slice( tsbi_items( $e ), 0, max( 1, (int) $n ) );
	if ( count( $items ) < 3 ) {
		return '';
	}
	$label = ! empty( $e['label'] ) ? $e['label'] : $e['kw'];
	if ( class_exists( 'Affiros_AI_Card_Renderer' ) ) {
		$aff   = get_option( 'affiros_ai_settings', array() );
		$aff   = is_array( $aff ) ? $aff : array();
		$prods = array();
		foreach ( $items as $it ) {
			$prods[] = array( 'source' => 'amazon', 'title' => $it['title'], 'image' => $it['image'], 'url' => $it['url'], 'brand' => '' );
		}
		$html = Affiros_AI_Card_Renderer::render( $prods, array(), array(
			'keyword'              => $label,
			'count'                => count( $prods ),
			'card_heading_suffix'  => isset( $aff['card_heading_suffix'] ) ? $aff['card_heading_suffix'] : '',
			'amazon_partner_tag'   => isset( $aff['amazon_partner_tag'] ) ? $aff['amazon_partner_tag'] : '',
			'rakuten_affiliate_id' => isset( $aff['rakuten_affiliate_id'] ) ? $aff['rakuten_affiliate_id'] : '',
		) );
		if ( function_exists( 'affiros_ai_sale_decorate' ) ) {
			$html = affiros_ai_sale_decorate( $html );   // 開催中セールのマイクロコピーをボタンの上に
		}
		if ( '' !== $html ) {
			return '<div class="tsbi-top-aff">' . $html . '</div>';
		}
	}
	$o = '<section class="tsbi tsbi-top"><p class="tsbi-head">' . esc_html( $label ) . 'の商品をチェック</p><div class="tsbi-grid">';
	foreach ( $items as $it ) {
		$o .= tsbi_card( $it, true );
	}
	return $o . '</div></section>';
}

function tsbi_css() {
	return '
.tsbi{margin-top:2em;margin-bottom:2em;padding:22px;background:#F6F7F2;border:1px solid #E4E5DE}
.tsbi-head{font-size:18px;font-weight:900;margin:0 0 4px;line-height:1.5}
.tsbi-note{font-size:12px;color:#6B6E66;margin:0 0 16px}
.tsbi-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px}
.tsbi-card{background:#fff;border:1px solid #E4E5DE;padding:12px;display:flex;flex-direction:column;gap:10px;min-width:0}
.tsbi-img{display:flex;align-items:center;justify-content:center;aspect-ratio:1/1;background:#fff}
.tsbi-img img{max-width:100%;max-height:100%;width:auto;height:auto;object-fit:contain;margin:0!important}
.tsbi-title{font-size:13px;line-height:1.55;margin:0!important;display:-webkit-box;-webkit-line-clamp:3;-webkit-box-orient:vertical;overflow:hidden;flex:1;color:#1F221C}
.tsbi-btns{display:flex;flex-direction:column;gap:6px}
.tsbi-btn{display:flex;justify-content:center;align-items:center;gap:6px;padding:8px 6px;font-size:13px;font-weight:800;text-decoration:none!important;line-height:1.3}
.tsbi-btn span{font-weight:400;font-size:.9em}
.tsbi-am{background:#FF9900;color:#fff!important}
.tsbi-rk{background:#BF0000;color:#fff!important}
.tsbi-btn:hover{opacity:.88}
.tsbi-side .tsbi-card{flex-direction:row;align-items:flex-start;padding:10px;margin-bottom:10px}
.tsbi-side .tsbi-img{width:84px;flex-shrink:0}
.tsbi-side .tsbi-body{display:flex;flex-direction:column;gap:8px;min-width:0;flex:1}
.tsbi-side .tsbi-title{-webkit-line-clamp:3;font-size:12px}
.tsbi-side .tsbi-btn{padding:6px 4px;font-size:12px}
.tsbi-top{padding:16px 18px}.tsbi-top .tsbi-head{font-size:15px;margin-bottom:12px}.tsbi-top .tsbi-grid{gap:10px}.tsbi-top .tsbi-card{padding:10px;gap:8px}.tsbi-top .tsbi-title{-webkit-line-clamp:2;font-size:12px}.tsbi-top .tsbi-btn{padding:6px 4px;font-size:12px}
/* サイドバーで中身の無いウィジェット（商品が無いページの商品カード等）は白い枠ごと消す */
.widget_block:not(:has(*)){display:none!important}
@media (max-width:640px){.tsbi{padding:16px}.tsbi-grid{grid-template-columns:repeat(2,minmax(0,1fr));gap:8px}.tsbi-card{padding:8px}.tsbi-btn{font-size:12px}.tsbi-top .tsbi-grid{grid-template-columns:repeat(3,minmax(0,1fr));gap:6px}.tsbi-top .tsbi-card{padding:6px}.tsbi-top .tsbi-btn{font-size:11px;padding:5px 2px}.tsbi-top .tsbi-btn span{display:none}}
';
}

add_action( 'wp_enqueue_scripts', function () {
	// サイドバー（追従欄）の空枠を消す指定も含むので、全ページで読む（小さいCSSのみ）
	wp_register_style( 'tsbi', false, array(), '1.0.0' );
	wp_enqueue_style( 'tsbi' );
	wp_add_inline_style( 'tsbi', tsbi_css() );
} );

/** 記事への自動差し込み。位置は 設定 > THE SALE 商品カード */
add_filter( 'the_content', function ( $content ) {
	if ( ! is_singular( 'post' ) || ! in_the_loop() || ! is_main_query() ) {
		return $content;
	}
	$s = tsbi_settings();
	if ( empty( $s['enabled'] ) || false !== strpos( $content, 'class="tsbi"' ) || false !== strpos( $content, 'tsbi-top-aff' ) ) {
		return $content;
	}
	$e = tsbi_entry_for_post();
	if ( ! $e ) {
		return $content;
	}
	$top_n = isset( $s['top_count'] ) ? (int) $s['top_count'] : 3;
	$block = tsbi_block( $e, $s['count'], $top_n );
	if ( '' === $block ) {
		return $content;
	}
	// 最初のH2見出しの上に3点（設定で0にすると出さない）
	if ( $top_n > 0 && preg_match( '/<h2[\s>]/', $content, $fm, PREG_OFFSET_CAPTURE ) ) {
		$top = tsbi_top_block( $e, $top_n );
		if ( '' !== $top ) {
			$content = substr( $content, 0, $fm[0][1] ) . $top . substr( $content, $fm[0][1] );
		}
	}
	if ( 'before_sum' === $s['position'] && preg_match( '/<h2[^>]*>[^<]*まとめ/u', $content, $m, PREG_OFFSET_CAPTURE ) ) {
		return substr( $content, 0, $m[0][1] ) . $block . substr( $content, $m[0][1] );
	}
	if ( 'middle' === $s['position'] && preg_match_all( '/<h2[\s>]/', $content, $mm, PREG_OFFSET_CAPTURE ) && count( $mm[0] ) >= 3 ) {
		$at = $mm[0][ (int) floor( count( $mm[0] ) / 2 ) ][1];
		return substr( $content, 0, $at ) . $block . substr( $content, $at );
	}
	return $content . $block;   // end（記事の最後・まとめの後）
}, 30 );

add_shortcode( 'thesale_items', function ( $atts ) {
	$a = shortcode_atts( array( 'kw' => '', 'n' => 6, 'layout' => '' ), $atts );
	// サイドバーなどループの外でも、表示中の記事で引く
	// 一覧ページ（カテゴリー・トップ等）では、ループの最後の記事（グローバルの $post）で引かれて関係ない店の商品が出るので、語の指定が無ければ出さない（1.0.6〜）
	if ( '' === $a['kw'] && ! is_singular( 'post' ) ) {
		return '';
	}
	$cur = is_singular( 'post' ) ? get_queried_object_id() : null;
	$e = '' !== $a['kw'] ? array( 'kw' => $a['kw'], 'label' => $a['kw'], 'kind' => 'product', 'match' => array( $a['kw'] ) ) : tsbi_entry_for_post( $cur );
	if ( ! $e ) {
		return '';
	}
	if ( '' !== $a['kw'] ) {   // ショートコードで直接指定した語も、保存して1日1回取り直す対象にする
		$map = tsbi_keywords();
		$key = '_sc_' . md5( $a['kw'] );
		if ( ! isset( $map[ $key ] ) ) {
			$map[ $key ] = $e;
			update_option( 'tsbi_keywords', $map, false );
		}
	}
	return 'side' === $a['layout'] ? tsbi_side_html( $e, (int) $a['n'] ) : tsbi_block( $e, (int) $a['n'] );
} );

/** サイドバー用の縦並び（ウィジェット・[thesale_items layout="side"] 共通）。見出しはサイドバーの他のブロックと同じ h2 */
function tsbi_side_html( $e, $n, $with_head = true ) {
	$items = array_slice( tsbi_items( $e ), 0, max( 1, (int) $n ) );
	if ( count( $items ) < 2 ) {
		return '';
	}
	$label = ! empty( $e['label'] ) ? $e['label'] : $e['kw'];
	$o = $with_head ? '<h2 class="wp-block-heading">' . esc_html( $label ) . 'の商品</h2>' : '';
	$o .= '<div class="tsbi-side">';
	foreach ( $items as $it ) {
		$o .= '<div class="tsbi-card"><a class="tsbi-img" href="' . esc_url( $it['url'] ) . '" target="_blank" rel="nofollow sponsored noopener"><img src="' . esc_url( $it['image'] ) . '" alt="' . esc_attr( tsbi_alt( $it['title'] ) ) . '" loading="lazy" width="120" height="120"></a>'
			. '<div class="tsbi-body"><p class="tsbi-title">' . esc_html( $it['title'] ) . '</p><div class="tsbi-btns">'
			. '<a class="tsbi-btn tsbi-am" href="' . esc_url( $it['url'] ) . '" target="_blank" rel="nofollow sponsored noopener">Amazon<span aria-hidden="true">&#8599;</span></a>'
			. '<a class="tsbi-btn tsbi-rk" href="' . esc_url( tsbi_rakuten_url( $it['title'] ) ) . '" target="_blank" rel="nofollow sponsored noopener">楽天<span aria-hidden="true">&#8599;</span></a></div></div></div>';
	}
	return $o . '</div>';
}

/** サイドバー（ウィジェット）: 記事ページで、その記事の商品を縦に並べる */
class Tsbi_Widget extends WP_Widget {
	public function __construct() {
		parent::__construct( 'tsbi_widget', 'THE SALE 商品カード', array( 'description' => '記事のブランド・商品の商品カード（記事ページだけに出る）' ) );
	}
	public function widget( $args, $instance ) {
		if ( ! is_singular( 'post' ) ) {
			return;
		}
		$e = tsbi_entry_for_post( get_queried_object_id() );
		if ( ! $e ) {
			return;
		}
		$n    = ! empty( $instance['n'] ) ? (int) $instance['n'] : (int) tsbi_settings()['sidebar_count'];
		$html = tsbi_side_html( $e, $n, false );
		if ( '' === $html ) {
			return;
		}
		$label = ! empty( $e['label'] ) ? $e['label'] : $e['kw'];
		echo $args['before_widget'] . $args['before_title'] . esc_html( $label ) . 'の商品' . $args['after_title'] . $html . $args['after_widget'];   // phpcs:ignore
	}
	public function form( $instance ) {
		$n = ! empty( $instance['n'] ) ? (int) $instance['n'] : 3;
		echo '<p><label>表示する数 <input type="number" min="1" max="6" name="' . esc_attr( $this->get_field_name( 'n' ) ) . '" value="' . esc_attr( $n ) . '" style="width:60px"></label></p>';
	}
	public function update( $new, $old ) {
		return array( 'n' => max( 1, min( 6, (int) $new['n'] ) ) );
	}
}
add_action( 'widgets_init', function () {
	register_widget( 'Tsbi_Widget' );
} );

/* ── 管理画面と REST ─────────────────────────────────────── */

add_action( 'admin_menu', function () {
	add_options_page( 'THE SALE 商品カード', 'THE SALE 商品カード', 'manage_options', 'tsbi', 'tsbi_admin_page' );
} );

function tsbi_admin_page() {
	if ( isset( $_POST['tsbi_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['tsbi_nonce'] ) ), 'tsbi_save' ) ) {
		$pos = isset( $_POST['position'] ) ? sanitize_key( wp_unslash( $_POST['position'] ) ) : 'end';
		update_option( 'tsbi_settings', array(
			'enabled'       => empty( $_POST['enabled'] ) ? 0 : 1,
			'count'         => max( 3, min( 12, (int) ( isset( $_POST['count'] ) ? $_POST['count'] : 6 ) ) ),
			'position'      => in_array( $pos, array( 'end', 'before_sum', 'middle' ), true ) ? $pos : 'end',
			'sidebar_count' => max( 1, min( 6, (int) ( isset( $_POST['sidebar_count'] ) ? $_POST['sidebar_count'] : 3 ) ) ),
			'top_count'     => max( 0, min( 3, (int) ( isset( $_POST['top_count'] ) ? $_POST['top_count'] : 3 ) ) ),
		) );
		if ( ! empty( $_POST['refresh'] ) ) {
			tsbi_refresh_all();
		}
		echo '<div class="updated"><p>保存しました。</p></div>';
	}
	$s    = tsbi_settings();
	$last = get_option( 'tsbi_last_refresh', array() );
	$map  = tsbi_keywords();
	echo '<div class="wrap"><h1>THE SALE 商品カード</h1><form method="post">';
	wp_nonce_field( 'tsbi_save', 'tsbi_nonce' );
	echo '<table class="form-table">'
		. '<tr><th>記事に自動で差し込む</th><td><label><input type="checkbox" name="enabled" value="1"' . checked( 1, (int) $s['enabled'], false ) . '> 差し込む</label></td></tr>'
		. '<tr><th>記事に並べる数</th><td><input type="number" name="count" min="3" max="12" value="' . esc_attr( $s['count'] ) . '"> 点（PCは3列）</td></tr>'
		. '<tr><th>差し込む位置</th><td><select name="position">';
	foreach ( array( 'end' => '記事の最後（まとめの後）', 'before_sum' => 'まとめの直前', 'middle' => '記事の真ん中（中央の見出しの前）' ) as $k => $v ) {
		echo '<option value="' . esc_attr( $k ) . '"' . selected( $s['position'], $k, false ) . '>' . esc_html( $v ) . '</option>';
	}
	echo '</select></td></tr>'
		. '<tr><th>最初の見出しの上に並べる数</th><td><input type="number" name="top_count" min="0" max="3" value="' . esc_attr( $s['top_count'] ) . '"> 点（0で出さない）</td></tr>'
		. '<tr><th>サイドバーの数（既定）</th><td><input type="number" name="sidebar_count" min="1" max="6" value="' . esc_attr( $s['sidebar_count'] ) . '"> 点（外観 &gt; ウィジェット の「THE SALE 商品カード」）</td></tr>'
		. '<tr><th>商品の取り直し</th><td><label><input type="checkbox" name="refresh" value="1"> 保存と同時に、全部の検索語を今すぐ取り直す（1語あたり1〜2秒）</label><p class="description">ふだんは毎日4:30ごろに自動で取り直します。'
		. ( ! empty( $last['time'] ) ? ' 前回: ' . esc_html( wp_date( 'Y-m-d H:i', $last['time'] ) ) : '' ) . '</p></td></tr>'
		. '</table>';
	submit_button( '保存' );
	echo '</form><h2>記事ごとの検索語（' . count( $map ) . '記事）</h2><p>登録の無い記事（モール・季節・アウトレットなど）には出しません。</p><table class="widefat striped"><thead><tr><th>記事</th><th>検索語</th><th>種類</th><th>保存済みの商品</th></tr></thead><tbody>';
	foreach ( $map as $slug => $e ) {
		if ( ! is_array( $e ) ) {
			continue;
		}
		$c = get_option( tsbi_cache_key( isset( $e['kw'] ) ? $e['kw'] : '' ) );
		echo '<tr><td>' . esc_html( $slug ) . '</td><td>' . esc_html( isset( $e['kw'] ) ? $e['kw'] : '' ) . ( ! empty( $e['off'] ) ? '（停止）' : '' ) . '</td><td>'
			. esc_html( isset( $e['kind'] ) && 'product' === $e['kind'] ? '商品' : 'ブランド' ) . '</td><td>' . ( is_array( $c ) ? count( $c['items'] ) . '点' : '未取得' ) . '</td></tr>';
	}
	echo '</tbody></table></div>';
}

add_action( 'rest_api_init', function () {
	$perm = function () { return current_user_can( 'manage_options' ); };
	// 取り直し: {"kw":"ドクターマーチン"} で1語、無ければ全部
	register_rest_route( 'tsbi/v1', '/refresh', array( 'methods' => 'POST', 'permission_callback' => $perm,
		'callback' => function ( WP_REST_Request $r ) {
			$kw = (string) $r->get_param( 'kw' );
			if ( '' !== $kw ) {
				$x = tsbi_refresh_kw( $kw );
				return array( 'kw' => $kw, 'count' => count( $x['items'] ), 'error' => $x['error'] );
			}
			return array( 'counts' => tsbi_refresh_all() );
		} ) );
	register_rest_route( 'tsbi/v1', '/status', array( 'methods' => 'GET', 'permission_callback' => $perm,
		'callback' => function () {
			$out = array();
			foreach ( tsbi_keywords() as $slug => $e ) {
				if ( is_array( $e ) && ! empty( $e['kw'] ) ) {
					$c = get_option( tsbi_cache_key( $e['kw'] ) );
					$out[ $slug ] = array( 'kw' => $e['kw'], 'items' => is_array( $c ) ? count( $c['items'] ) : null );
				}
			}
			return array( 'settings' => tsbi_settings(), 'last' => get_option( 'tsbi_last_refresh', array() ), 'posts' => $out );
		} ) );
} );
