<?php
/**
 * Plugin Name: THE SALE セール日程表示
 * Description: トップの「いま開催中のセール」「これから始まるセール」を、セール日程データ（オプション thesale_events）から表示のたびに日本時間で計算して出すショートコード。終わったセールは自動で消え、時点の日付も当日になる。
 * Version:     1.2.0
 * Author:      Keys
 * Text Domain: thesale-schedule
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// 自動更新（機能より先に組み込む。wp-plugin-autoupdate の手順）
require_once __DIR__ . '/includes/plugin-updater.php';
add_action( 'init', function () {
	new TheSale_Schedule_Updater(
		__FILE__,
		'https://raw.githubusercontent.com/yoshimucom-gif/wp-manager/main/plugin-host/api/plugin-update/thesale-schedule'
	);
} );

/**
 * 新版の即時適用（1.1.3）: POST /wp-json/thesale/v1/selfupdate
 * 更新キャッシュを捨てて配信元を確認し、このプラグインだけをその場で更新する。
 * 管理画面の更新ボタンを押さずに、リリース直後の版を反映するための窓口。
 */
add_action( 'rest_api_init', function () {
	register_rest_route( 'thesale/v1', '/selfupdate', array(
		'methods'             => 'POST',
		'permission_callback' => function () { return current_user_can( 'update_plugins' ); },
		'callback'            => function () {
			$base = plugin_basename( __FILE__ );
			$data = get_file_data( __FILE__, array( 'v' => 'Version' ) );
			delete_transient( 'thesale_sched_updater_' . md5( $base ) );
			delete_site_transient( 'update_plugins' );
			wp_update_plugins();
			$upd = get_site_transient( 'update_plugins' );
			if ( empty( $upd->response[ $base ] ) ) {
				return array( 'ok' => true, 'updated' => false, 'version' => $data['v'], 'note' => '配信版との差がない' );
			}
			require_once ABSPATH . 'wp-admin/includes/file.php';
			require_once ABSPATH . 'wp-admin/includes/misc.php';
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
			require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
			$up  = new Plugin_Upgrader( new Automatic_Upgrader_Skin() );
			$res = $up->upgrade( $base );
			// 更新の途中でWPはプラグインを無効にし、自分自身を更新したリクエストでは有効に戻らないので戻す
			$active = (array) get_option( 'active_plugins', array() );
			$was    = in_array( $base, $active, true );
			if ( ! $was ) {
				$active[] = $base;
				update_option( 'active_plugins', array_values( array_unique( $active ) ) );
			}
			return array(
				'ok'             => true === $res,
				'updated'        => true === $res,
				'version_before' => $data['v'],
				'to'             => isset( $upd->response[ $base ]->new_version ) ? $upd->response[ $base ]->new_version : '',
				'reactivated'    => ! $was,
				'error'          => is_wp_error( $res ) ? $res->get_error_message() : null,
			);
		},
	) );
} );

/**
 * セール日程データ。1件の形:
 * {
 *   "name": "楽天お買い物マラソン（第三弾）", "url": "/rakuten-marathon/", "site": "rakuten",
 *   "start": "2026-10-24T20:00:00+09:00", "end": "2026-10-27T01:59:00+09:00",   // 未発表なら空
 *   "month": "11月", "day": "中旬",                  // 日程未発表のときの目安（start があれば自動）
 *   "sort": "2026-11-15",                            // 並び順（未発表の目安日）
 *   "hide_after": "2026-11-30",                      // 未発表のまま、この日を過ぎたら出さない
 *   "status": "日程未発表・例年3か月ごと",            // 未発表のときの表示（確定なら自動）
 *   "note": "2025年は11月21日に開始",
 *   "official": {"url": "https://...", "label": "楽天公式"}
 * }
 */
function thesale_sched_events() {
	$ev = get_option( 'thesale_events', array() );
	if ( is_string( $ev ) ) {
		$ev = json_decode( $ev, true );
	}
	return is_array( $ev ) ? array_values( $ev ) : array();
}

/** トップの一覧（開催中・これから）に出す日程。"top": false の日程は記事冒頭の状況の箱だけで使う（1.1.5〜。アウトレットの施設のセールなど） */
function thesale_sched_top_events() {
	return array_values( array_filter( thesale_sched_events(), function ( $e ) {
		return ! ( isset( $e['top'] ) && false === $e['top'] );
	} ) );
}

function thesale_sched_now() {
	return new DateTimeImmutable( 'now', new DateTimeZone( 'Asia/Tokyo' ) );
}

function thesale_sched_dt( $s ) {
	if ( empty( $s ) ) {
		return null;
	}
	try {
		return new DateTimeImmutable( $s, new DateTimeZone( 'Asia/Tokyo' ) );
	} catch ( Exception $e ) {
		return null;
	}
}

function thesale_sched_asof() {
	$n = thesale_sched_now();
	return $n->format( 'Y' ) . '年' . $n->format( 'n' ) . '月' . $n->format( 'j' ) . '日';
}

function thesale_sched_url( $u ) {
	if ( empty( $u ) ) {
		return '';
	}
	return ( 0 === strpos( $u, 'http' ) ) ? $u : home_url( $u );
}

function thesale_sched_official( $e, $box ) {
	if ( empty( $e['official']['url'] ) ) {
		return '';
	}
	$is_amazon = false !== strpos( $e['official']['url'], 'amazon' );
	$label     = ! empty( $e['official']['label'] ) ? $e['official']['label'] : ( $is_amazon ? 'Amazon公式' : '楽天公式' );
	$cls       = 'ts-off' . ( $box ? ' ts-off-box' : '' ) . ( $is_amazon ? ' ts-off-amazon' : ' ts-off-rakuten' );
	return sprintf(
		'<a class="%s" href="%s" target="_blank" rel="nofollow sponsored noopener">%s</a>',
		esc_attr( $cls ),
		esc_url( $e['official']['url'] ),
		esc_html( $label )
	);
}

/** 開始・終了の表示（例: 10月14日20:00〜17日9:59） */
function thesale_sched_period( $s, $e ) {
	$fmt = function ( $d, $with_month ) {
		// 0:00 と 23:59 は「その日いっぱい」なので時刻を出さない（「19日23:59」→「19日」）
		$t = in_array( $d->format( 'H:i' ), array( '00:00', '23:59' ), true ) ? '' : $d->format( 'G:i' );
		return ( $with_month ? $d->format( 'n' ) . '月' : '' ) . $d->format( 'j' ) . '日' . $t;
	};
	if ( ! $s ) {
		return '';
	}
	$out = $fmt( $s, true ) . '〜';
	if ( $e ) {
		$out .= $fmt( $e, $e->format( 'n' ) !== $s->format( 'n' ) );
	}
	return $out;
}

/** [thesale_live] いま開催中のセール（見出し2つき） */
function thesale_sched_live( $atts ) {
	$now  = thesale_sched_now();
	$rows = array();
	foreach ( thesale_sched_top_events() as $e ) {
		$s = thesale_sched_dt( isset( $e['start'] ) ? $e['start'] : '' );
		$t = thesale_sched_dt( isset( $e['end'] ) ? $e['end'] : '' );
		if ( $s && $t && $s <= $now && $now < $t ) {
			$rows[] = sprintf(
				'<li><span class="ts-tag ts-tag-on">開催中</span><span class="ts-lc"><a class="ts-ev" href="%s">%s</a><span class="ts-when">%s</span>%s</span></li>',
				esc_url( thesale_sched_url( $e['url'] ) ),
				esc_html( $e['name'] ),
				esc_html( thesale_sched_period( $s, $t ) ),
				thesale_sched_official( $e, false )
			);
		}
	}
	$h = '<h2 class="wp-block-heading ts-live-h">いま開催中のセール<span class="ts-asof-s">（' . esc_html( thesale_sched_asof() ) . '時点）</span></h2>';
	if ( ! $rows ) {
		return $h . '<ul class="wp-block-list ts-live"><li><span class="ts-lc"><span class="ts-when">いま開催中の大型セールはありません。次のセールは下の一覧で確認できます。</span></span></li></ul>';
	}
	return $h . '<ul class="wp-block-list ts-live">' . implode( '', $rows ) . '</ul>';
}
add_shortcode( 'thesale_live', 'thesale_sched_live' );

/** [thesale_upcoming limit="6"] これから始まるセール（日めくりカード） */
function thesale_sched_upcoming( $atts ) {
	$a     = shortcode_atts( array( 'limit' => 6 ), $atts );
	$now   = thesale_sched_now();
	$today = $now->format( 'Y-m-d' );
	$list  = array();
	foreach ( thesale_sched_top_events() as $e ) {
		$s = thesale_sched_dt( isset( $e['start'] ) ? $e['start'] : '' );
		if ( $s ) {
			if ( $s <= $now ) {
				continue; // 始まったものは「開催中」側へ
			}
			$e['_sort'] = $s->format( 'Y-m-d H:i' );
			$e['_s']    = $s;
		} else {
			if ( ! empty( $e['hide_after'] ) && $today > $e['hide_after'] ) {
				continue; // 未発表のまま目安の時期を過ぎた
			}
			$e['_sort'] = isset( $e['sort'] ) ? $e['sort'] : '9999';
			$e['_s']    = null;
		}
		$list[] = $e;
	}
	usort( $list, function ( $x, $y ) {
		return strcmp( $x['_sort'], $y['_sort'] );
	} );
	$list = array_slice( $list, 0, max( 1, (int) $a['limit'] ) );
	$out  = array();
	foreach ( $list as $e ) {
		$s = $e['_s'];
		if ( $s ) {
			$month  = $s->format( 'n' ) . '月';
			$day    = $s->format( 'j' );
			$time   = ( '00:00' === $s->format( 'H:i' ) ) ? '' : '・' . $s->format( 'G:i' ) . '〜';
			$status = '開催予定' . $time;
			$cls    = 'ts-st ts-st-ok';
		} else {
			$month  = isset( $e['month'] ) ? $e['month'] : '';
			$day    = isset( $e['day'] ) ? $e['day'] : '';
			$status = ! empty( $e['status'] ) ? $e['status'] : '日程未発表';
			$cls    = 'ts-st ts-st-tbd';
		}
		$small = ctype_digit( (string) $day ) ? '' : ' ts-leaf-s';
		$note  = ! empty( $e['note'] ) ? '<span class="ts-cn">' . esc_html( $e['note'] ) . '</span>' : '';
		$out[] = sprintf(
			'<li><span class="ts-leaf"><b style="background:#14213D;color:#FFFFFF">%s</b><span class="ts-leaf-d%s">%s</span></span>'
			. '<span class="ts-cb"><span class="%s">%s</span><a class="ts-ev" href="%s">%s</a>%s</span>%s</li>',
			esc_html( $month ),
			esc_attr( $small ),
			esc_html( $day ),
			esc_attr( $cls ),
			esc_html( $status ),
			esc_url( thesale_sched_url( $e['url'] ) ),
			esc_html( $e['name'] ),
			$note,
			thesale_sched_official( $e, true )
		);
	}
	return '<p class="ts-lead ts-asof">' . esc_html( thesale_sched_asof() ) . '時点。日程が未発表のものは、過去の開催時期を目安として載せています。</p>'
		. '<ul class="wp-block-list ts-cards">' . implode( '', $out ) . '</ul>';
}
add_shortcode( 'thesale_upcoming', 'thesale_sched_upcoming' );

/**
 * [thesale_status slug="udemy-sale"] 記事の冒頭に出す「いまの状況」の箱（1.1.0〜）
 * セール日程データのうち、url がこの記事の場合か related にこの記事のスラッグを含むものを見て、
 *   開催中 → 赤「開催中・〜◯日」＋公式ボタン ／ 次回が決まっている → 緑「次回は◯月◯日から」
 *   どちらも無い → 灰色「次回の日程は未発表（◯年◯月◯日時点）」
 * 本文の「◯年◯月◯日時点」が古くなっても、この箱は毎日その日の状況になる。
 * 固定ページ・投稿の<style>は表示時に落ちる環境があるので、見た目は style 属性で付ける。
 */
function thesale_sched_status( $atts ) {
	$a    = shortcode_atts( array( 'slug' => '' ), $atts );
	$slug = trim( $a['slug'], '/' );
	if ( '' === $slug ) {
		return '';
	}
	$now  = thesale_sched_now();
	$live = null;
	$next = null;
	foreach ( thesale_sched_events() as $e ) {
		$own     = trim( (string) wp_parse_url( isset( $e['url'] ) ? $e['url'] : '', PHP_URL_PATH ), '/' );
		$related = isset( $e['related'] ) && is_array( $e['related'] ) ? $e['related'] : array();
		if ( $own !== $slug && ! in_array( $slug, $related, true ) ) {
			continue;
		}
		$s = thesale_sched_dt( isset( $e['start'] ) ? $e['start'] : '' );
		$t = thesale_sched_dt( isset( $e['end'] ) ? $e['end'] : '' );
		if ( $s && $t && $s <= $now && $now < $t ) {
			$live = array( $e, $s, $t );
		} elseif ( $s && $s > $now && ( ! $next || $s < $next[1] ) ) {
			$next = array( $e, $s, $t );
		}
	}
	$box = 'display:flex;flex-wrap:wrap;align-items:center;gap:10px 14px;padding:14px 16px;border-radius:8px;';
	$tag = 'display:inline-flex;align-items:center;justify-content:center;min-width:64px;height:30px;padding:0 10px;border-radius:4px;font-weight:700;font-size:14px;color:#fff;';
	$btn = function ( $e ) {
		if ( empty( $e['official']['url'] ) ) {
			return '';
		}
		$am = false !== strpos( $e['official']['url'], 'amazon' );
		return sprintf(
			'<a href="%s" target="_blank" rel="nofollow sponsored noopener" style="margin-left:auto;display:inline-block;padding:7px 16px;border-radius:4px;font-weight:700;font-size:14px;color:#fff;text-decoration:none;background:%s">%s</a>',
			esc_url( $e['official']['url'] ),
			$am ? '#D16800' : '#BF0000',
			esc_html( ! empty( $e['official']['label'] ) ? $e['official']['label'] : ( $am ? 'Amazon公式' : '楽天公式' ) )
		);
	};
	if ( $live ) {
		list( $e, $s, $t ) = $live;
		return sprintf(
			'<div class="ts-status ts-status-live" style="%sbackground:#FFF6D6;border:1px solid #FFD966"><span style="%sbackground:#FFC20E;color:#14213D">開催中</span><span style="font-weight:700">%s：%s</span>%s</div>',
			$box, $tag, esc_html( $e['name'] ), esc_html( thesale_sched_period( $s, $t ) ), $btn( $e )
		);
	}
	if ( $next ) {
		list( $e, $s, $t ) = $next;
		return sprintf(
			'<div class="ts-status ts-status-next" style="%sbackground:#EAF5EE;border:1px solid #B5DCC3"><span style="%sbackground:#1E6B45">次回決定</span><span style="font-weight:700">%s：%s</span>%s</div>',
			$box, $tag, esc_html( $e['name'] ), esc_html( thesale_sched_period( $s, $t ) ), $btn( $e )
		);
	}
	return sprintf(
		'<div class="ts-status ts-status-tbd" style="%sbackground:#F3F4F6;border:1px solid #DADDE3"><span style="%sbackground:#5B6274">未発表</span><span>%s時点で、次回の日程はまだ発表されていません。発表されしだい、ここに表示します。</span></div>',
		$box, $tag, esc_html( thesale_sched_asof() )
	);
}
add_shortcode( 'thesale_status', 'thesale_sched_status' );

/**
 * カード用の店データ（オプション thesale_cards。tools/push_card_data.py が書く）
 * { "記事スラッグ": {"name": "ニューバランス", "kind": "brand"|"mall", "site": "amazon"（mallのとき）,
 *                   "official": "https://...", "amazon": "https://...（アフィリエイト）", "rakuten": "https://..."} }
 */
function thesale_card_data() {
	$c = get_option( 'thesale_cards', array() );
	if ( is_string( $c ) ) {
		$c = json_decode( $c, true );
	}
	return is_array( $c ) ? $c : array();
}

/**
 * 条件に合う日程から、いまの状態を1つ選ぶ。
 * 返り値: array( 'state' => 'live'|'next'|'est'|'tbd', 'e' => 日程, 's' => 開始, 't' => 終了 )
 * est = 日程は未発表だが目安（month/day）がある回。tbd = どれも無い
 */
function thesale_card_pick( $match ) {
	$now   = thesale_sched_now();
	$today = $now->format( 'Y-m-d' );
	$live  = null;
	$next  = null;
	$est   = null;
	foreach ( thesale_sched_events() as $e ) {
		if ( ! $match( $e ) ) {
			continue;
		}
		$s = thesale_sched_dt( isset( $e['start'] ) ? $e['start'] : '' );
		$t = thesale_sched_dt( isset( $e['end'] ) ? $e['end'] : '' );
		if ( $s && $t && $s <= $now && $now < $t ) {
			if ( ! $live || $t < $live['t'] ) {
				$live = array( 'state' => 'live', 'e' => $e, 's' => $s, 't' => $t );
			}
		} elseif ( $s && $s > $now ) {
			if ( ! $next || $s < $next['s'] ) {
				$next = array( 'state' => 'next', 'e' => $e, 's' => $s, 't' => $t );
			}
		} elseif ( ! $s && ! empty( $e['month'] ) ) {
			$hide = isset( $e['hide_after'] ) ? (string) $e['hide_after'] : '';
			$sort = isset( $e['sort'] ) ? (string) $e['sort'] : '9999';
			if ( ( '' === $hide || $hide >= $today ) && ( ! $est || $sort < $est['sort'] ) ) {
				$est = array( 'state' => 'est', 'e' => $e, 's' => null, 't' => null, 'sort' => $sort );
			}
		}
	}
	if ( $live ) {
		return $live;
	}
	if ( $next ) {
		return $next;
	}
	if ( $est ) {
		return $est;
	}
	return array( 'state' => 'tbd', 'e' => null, 's' => null, 't' => null );
}

/** 状態ごとの文言（ラベル・期間の文） */
function thesale_card_text( $p ) {
	$now = thesale_sched_now();
	if ( 'live' === $p['state'] ) {
		$left = (int) ceil( ( $p['t']->getTimestamp() - $now->getTimestamp() ) / 86400 );
		return array(
			'label' => '開催中',
			'sub'   => $left <= 1 ? '今日まで' : 'あと' . $left . '日',
			'name'  => $p['e']['name'],
			'when'  => thesale_sched_period( $p['s'], $p['t'] ),
		);
	}
	if ( 'next' === $p['state'] ) {
		return array(
			'label' => '次回',
			'sub'   => $p['s']->format( 'n/j' ) . '〜',
			'name'  => $p['e']['name'],
			'when'  => thesale_sched_period( $p['s'], $p['t'] ),
		);
	}
	if ( 'est' === $p['state'] ) {
		$m = isset( $p['e']['month'] ) ? $p['e']['month'] : '';
		$d = isset( $p['e']['day'] ) ? $p['e']['day'] : '';
		return array(
			'label' => '未発表',
			'sub'   => $m,
			'name'  => $p['e']['name'],
			'when'  => '日程は未発表（例年' . $m . $d . 'ごろ）',
		);
	}
	return array( 'label' => '未発表', 'sub' => '', 'name' => '', 'when' => '次のセールはまだ発表されていません' );
}

function thesale_card_btn( $url, $label, $bg ) {
	if ( empty( $url ) ) {
		return '';
	}
	return sprintf(
		'<a href="%s" target="_blank" rel="nofollow sponsored noopener" style="display:inline-block;padding:7px 14px;border-radius:4px;font-weight:700;font-size:14px;line-height:1.4;color:#fff;text-decoration:none;white-space:nowrap;background:%s">%s</a>',
		esc_url( $url ),
		esc_attr( $bg ),
		esc_html( $label )
	);
}

/**
 * [thesale_card slug="..."] 記事冒頭のカード（1.2.0〜。状況の箱 [thesale_status] と公式リンクを1枚にまとめる）
 *  ブランドの記事: 上段＝ブランド公式のセール＋公式サイト、下段＝Amazon・楽天の公式ストアで開かれる大型セール＋公式ストア
 *  モールの記事: そのモールのセールの1段だけ＋セール会場
 *  表示のたびに日本時間で判定する。見た目は style 属性で付ける（記事の<style>は落ちる環境がある）
 */
function thesale_card( $atts ) {
	$a    = shortcode_atts( array( 'slug' => '' ), $atts );
	$slug = trim( $a['slug'], '/' );
	if ( '' === $slug ) {
		return '';
	}
	$cards = thesale_card_data();
	$c     = isset( $cards[ $slug ] ) && is_array( $cards[ $slug ] ) ? $cards[ $slug ] : array();
	$name  = isset( $c['name'] ) ? $c['name'] : '';
	$kind  = isset( $c['kind'] ) ? $c['kind'] : 'brand';

	$own = thesale_card_pick( function ( $e ) use ( $slug ) {
		$u       = trim( (string) wp_parse_url( isset( $e['url'] ) ? $e['url'] : '', PHP_URL_PATH ), '/' );
		$related = isset( $e['related'] ) && is_array( $e['related'] ) ? $e['related'] : array();
		return $u === $slug || in_array( $slug, $related, true );
	} );
	$tx = thesale_card_text( $own );

	$sq_bg = array( 'live' => '#FFC20E', 'next' => '#1E6B45', 'est' => '#5B6274', 'tbd' => '#5B6274' );
	$sq_fg = array( 'live' => '#14213D', 'next' => '#FFFFFF', 'est' => '#FFFFFF', 'tbd' => '#FFFFFF' );
	$wrap  = array(
		'live' => 'border:2px solid #FFC20E;background:#FFFBEA',
		'next' => 'border:1px solid #B5DCC3;background:#F4FAF6',
		'est'  => 'border:1px solid #DADDE3;background:#FFFFFF',
		'tbd'  => 'border:1px solid #DADDE3;background:#FFFFFF',
	);
	$st = $own['state'];

	if ( 'mall' === $kind ) {
		$btn_url = ! empty( $own['e']['official']['url'] ) ? $own['e']['official']['url'] : ( isset( $c['official'] ) ? $c['official'] : '' );
		$site    = isset( $c['site'] ) ? $c['site'] : '';
		$btn_bg  = 'rakuten' === $site ? '#BF0000' : ( 'amazon' === $site ? '#D16800' : '#14213D' );
		$title   = '' !== $tx['name'] ? $tx['name'] : $name;
		return sprintf(
			'<div class="ts-card ts-card-mall ts-card-%1$s" style="box-sizing:border-box;display:flex;flex-wrap:wrap;align-items:center;gap:12px 14px;padding:12px 14px;border-radius:8px;%2$s">'
			. '<div style="width:60px;height:60px;border-radius:6px;background:%3$s;color:%4$s;display:flex;flex-direction:column;align-items:center;justify-content:center;font-weight:700;font-size:14px;line-height:1.3;flex-shrink:0">%5$s<span style="font-size:11px;font-weight:700">%6$s</span></div>'
			. '<div style="flex:1;min-width:180px"><div style="font-weight:700;font-size:16px;line-height:1.5">%7$s</div><div style="font-size:13px;color:#5B6274;line-height:1.5">%8$s</div></div>'
			. '%9$s</div>',
			esc_attr( $st ), $wrap[ $st ], $sq_bg[ $st ], $sq_fg[ $st ], esc_html( $tx['label'] ), esc_html( $tx['sub'] ),
			esc_html( $title ), esc_html( $tx['when'] ), thesale_card_btn( $btn_url, 'live' === $st ? 'セール会場へ' : '公式サイト', $btn_bg )
		);
	}

	// ブランドの記事: 上段
	$top_title = $name . '公式' . ( '' !== $tx['name'] ? '：' . $tx['name'] : '' );
	$top_sub   = 'tbd' === $st ? '次のセールはまだ発表されていません' : $tx['when'];
	$official  = isset( $c['official'] ) ? $c['official'] : '';
	if ( 'live' === $st && ! empty( $own['e']['official']['url'] ) ) {
		$official = $own['e']['official']['url'];
	}
	$html = sprintf(
		'<div class="ts-card ts-card-brand ts-card-%1$s" style="box-sizing:border-box;border-radius:8px;overflow:hidden;%2$s">'
		. '<div style="display:flex;flex-wrap:wrap;align-items:center;gap:12px 14px;padding:12px 14px">'
		. '<div style="width:60px;height:60px;border-radius:6px;background:%3$s;color:%4$s;display:flex;flex-direction:column;align-items:center;justify-content:center;font-weight:700;font-size:14px;line-height:1.3;flex-shrink:0">%5$s<span style="font-size:11px;font-weight:700">%6$s</span></div>'
		. '<div style="flex:1;min-width:180px"><div style="font-size:12px;color:#5B6274;line-height:1.5">公式のセール</div><div style="font-weight:700;font-size:16px;line-height:1.5">%7$s</div><div style="font-size:13px;color:#5B6274;line-height:1.5">%8$s</div></div>'
		. '%9$s</div>',
		esc_attr( $st ), $wrap[ $st ], $sq_bg[ $st ], $sq_fg[ $st ], esc_html( $tx['label'] ), esc_html( $tx['sub'] ),
		esc_html( $top_title ), esc_html( $top_sub ), thesale_card_btn( $official, 'live' === $st ? '公式のセール会場へ' : '公式サイト', '#14213D' )
	);

	// 下段: Amazon・楽天の公式ストアで開かれる大型セール（トップに出しているモールの日程から選ぶ）
	$rows = '';
	$malls = array(
		'amazon'  => array( 'Amazon公式ストア', '#D16800', 'Amazon' ),
		'rakuten' => array( '楽天公式ショップ', '#BF0000', '楽天市場' ),
	);
	foreach ( $malls as $site => $m ) {
		if ( empty( $c[ $site ] ) ) {
			continue;
		}
		$p = thesale_card_pick( function ( $e ) use ( $site ) {
			return isset( $e['site'] ) && $site === $e['site'] && ! ( isset( $e['top'] ) && false === $e['top'] );
		} );
		$t2    = thesale_card_text( $p );
		$ps    = $p['state'];
		$badge = array( 'live' => array( '#FFC20E', '#14213D' ), 'next' => array( '#1E6B45', '#FFFFFF' ), 'est' => array( '#5B6274', '#FFFFFF' ), 'tbd' => array( '#5B6274', '#FFFFFF' ) );
		$rbg   = array( 'live' => 'background:#FFFBEA;border:1px solid #FFC20E', 'next' => 'background:#F4FAF6;border:1px solid #B5DCC3', 'est' => 'background:#F7F8FA;border:1px solid #E3E5EA', 'tbd' => 'background:#F7F8FA;border:1px solid #E3E5EA' );
		$line  = '' !== $t2['name'] ? $t2['name'] . '　' . $t2['when'] : $m[2] . '：' . $t2['when'];
		$rows .= sprintf(
			'<div style="display:flex;flex-wrap:wrap;align-items:center;gap:8px 10px;padding:8px 10px;border-radius:6px;margin-top:6px;%1$s">'
			. '<span style="display:inline-block;padding:3px 8px;border-radius:4px;font-size:12px;font-weight:700;background:%2$s;color:%3$s;white-space:nowrap">%4$s</span>'
			. '<span style="flex:1;min-width:160px;font-size:14px;font-weight:700;line-height:1.5">%5$s</span>%6$s</div>',
			$rbg[ $ps ], $badge[ $ps ][0], $badge[ $ps ][1], esc_html( 'live' === $ps ? '開催中' : ( 'next' === $ps ? '次回' : '未発表' ) ),
			esc_html( $line ), thesale_card_btn( $c[ $site ], $m[0], $m[1] )
		);
	}
	if ( '' !== $rows ) {
		$html .= '<div style="border-top:1px solid rgba(0,0,0,.08);padding:4px 14px 12px;background:#FFFFFF">'
			. '<div style="font-size:12px;color:#5B6274;margin-top:6px;line-height:1.5">通販サイトの公式ストアで開かれるセール</div>'
			. $rows . '</div>';
	}
	return $html . '</div>';
}
add_shortcode( 'thesale_card', 'thesale_card' );

/** [thesale_asof] 当日の日付（「2026年10月14日」） */
add_shortcode( 'thesale_asof', function () {
	return esc_html( thesale_sched_asof() );
} );
