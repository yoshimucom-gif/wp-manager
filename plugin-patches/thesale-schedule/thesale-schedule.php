<?php
/**
 * Plugin Name: THE SALE セール日程表示
 * Description: トップの「いま開催中のセール」「これから始まるセール」を、セール日程データ（オプション thesale_events）から表示のたびに日本時間で計算して出すショートコード。終わったセールは自動で消え、時点の日付も当日になる。
 * Version:     1.4.0
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

function thesale_card_pill( $state ) {
	$map = array(
		'live' => array( '#FFF3C4', '#7A5200', '● 開催中' ),
		'next' => array( '#EAF5EE', '#1E6B45', '● 次回' ),
		'est'  => array( '#F1F3F6', '#5B6274', '未発表' ),
		'tbd'  => array( '#F1F3F6', '#5B6274', '未発表' ),
	);
	$m = isset( $map[ $state ] ) ? $map[ $state ] : $map['tbd'];
	return sprintf(
		'<span style="display:inline-block;padding:3px 10px;border-radius:6px;font-size:13px;font-weight:700;line-height:1.5;white-space:nowrap;background:%s;color:%s">%s</span>',
		$m[0], $m[1], esc_html( $m[2] )
	);
}

function thesale_card_button( $url, $label, $bg, $full ) {
	if ( empty( $url ) ) {
		return '';
	}
	return sprintf(
		'<a href="%s" target="_blank" rel="nofollow sponsored noopener" style="box-sizing:border-box;display:%s;align-items:center;justify-content:center;gap:10px;%spadding:12px 22px;border-radius:8px;font-weight:700;font-size:15px;line-height:1.4;color:#fff;text-decoration:none;white-space:nowrap;background:%s">%s<span aria-hidden="true" style="font-weight:400">&#8599;</span></a>',
		esc_url( $url ),
		$full ? 'flex' : 'inline-flex',
		$full ? 'width:100%;' : '',
		esc_attr( $bg ),
		esc_html( $label )
	);
}

/** 上段（白いカード）: ラベル＋状態・見出し・補足・右にボタン */
function thesale_card_head( $label, $state, $title, $sub, $btn ) {
	return '<div style="box-sizing:border-box;display:flex;flex-wrap:wrap;align-items:center;gap:14px 20px;padding:20px 24px;background:#fff;border:1px solid #E3E8EF;border-radius:14px">'
		. '<div style="flex:1;min-width:220px">'
		. '<div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap"><span style="font-size:13px;font-weight:700;color:#5B6274">' . esc_html( $label ) . '</span>' . thesale_card_pill( $state ) . '</div>'
		. '<div style="font-size:20px;font-weight:700;line-height:1.5;color:#1F2937;margin-top:8px">' . esc_html( $title ) . '</div>'
		. '<div style="font-size:14px;line-height:1.6;color:#5B6274;margin-top:4px">' . esc_html( $sub ) . '</div>'
		. '</div>' . $btn . '</div>';
}

/**
 * [thesale_card slug="..."] 記事冒頭のカード（1.2.0〜。1.2.1でデザインを吉村さんの案に合わせた）
 *  ブランドの記事: 上段＝ブランド公式のセール＋公式サイト、下段＝Amazon・楽天の公式ストアで開かれる大型セール（2列）
 *  モールの記事: 上段だけ（そのモールのセール＋セール会場）
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
	$st = $own['state'];
	$outer = '<div class="ts-card ts-card-' . esc_attr( $kind ) . ' ts-card-' . esc_attr( $st ) . '" style="box-sizing:border-box;padding:18px;background:#EEF2F7;border:1px solid #E3E8EF;border-radius:18px">';

	if ( 'mall' === $kind ) {
		$btn_url = ! empty( $own['e']['official']['url'] ) ? $own['e']['official']['url'] : ( isset( $c['official'] ) ? $c['official'] : '' );
		$site    = isset( $c['site'] ) ? $c['site'] : '';
		$btn_bg  = 'rakuten' === $site ? '#BF0000' : ( 'amazon' === $site ? '#B35900' : '#14213D' );
		$title   = '' !== $tx['name'] ? $tx['name'] : $name;
		$sub     = 'tbd' === $st ? '次のセールはまだ発表されていません' : $tx['when'];
		$btn     = thesale_card_button( $btn_url, 'live' === $st ? 'セール会場へ' : '公式サイト', $btn_bg, false );
		return $outer . thesale_card_head( 'セールの状況', $st, $title, $sub, $btn ) . '</div>';
	}

	$official = isset( $c['official'] ) ? $c['official'] : '';
	if ( 'live' === $st && ! empty( $own['e']['official']['url'] ) ) {
		$official = $own['e']['official']['url'];
	}
	$title = ( in_array( $st, array( 'live', 'next' ), true ) && '' !== $tx['name'] ) ? $tx['name'] : $name . '公式';
	$sub   = 'tbd' === $st ? '次のセールはまだ発表されていません' : $tx['when'];
	$html  = $outer . thesale_card_head( '公式のセール', $st, $title, $sub,
		thesale_card_button( $official, 'live' === $st ? '公式のセール会場へ' : '公式サイト', '#14213D', false ) );

	// 下段: Amazon・楽天の公式ストアで開かれる大型セール（トップに出しているモールの日程から選ぶ）
	$malls = array(
		'amazon'  => array( 'Amazon', '#B35900', 'Amazon公式ストア', '/^Amazon\s*/u' ),
		'rakuten' => array( '楽天', '#BF0000', '楽天公式ショップ', '/^楽天(市場)?\s*/u' ),
	);
	$cols = '';
	foreach ( $malls as $site => $m ) {
		if ( empty( $c[ $site ] ) ) {
			continue;
		}
		$p = thesale_card_pick( function ( $e ) use ( $site ) {
			return isset( $e['site'] ) && $site === $e['site'] && ! ( isset( $e['top'] ) && false === $e['top'] );
		} );
		$t2    = thesale_card_text( $p );
		$ev    = '' !== $t2['name'] ? preg_replace( $m[3], '', $t2['name'] ) : '大型セール';
		$when  = 'tbd' === $p['state'] ? '次のセールはまだ発表されていません' : $t2['when'];
		$cols .= '<div style="box-sizing:border-box;display:flex;flex-direction:column;gap:10px;padding:20px 24px;background:#fff;border:1px solid #E3E8EF;border-radius:14px">'
			. '<div style="display:flex;align-items:center;justify-content:space-between;gap:10px"><span style="font-size:17px;font-weight:700;color:' . esc_attr( $m[1] ) . '">' . esc_html( $m[0] ) . '</span>' . thesale_card_pill( $p['state'] ) . '</div>'
			. '<div style="font-size:18px;font-weight:700;line-height:1.6;color:#1F2937">' . esc_html( $ev ) . '</div>'
			. '<div style="font-size:14px;line-height:1.6;color:#5B6274;flex:1">' . esc_html( $when ) . '</div>'
			. thesale_card_button( $c[ $site ], $m[2], $m[1], true )
			. '</div>';
	}
	if ( '' !== $cols ) {
		$html .= '<div style="font-size:14px;font-weight:700;color:#5B6274;margin:18px 2px 10px">通販サイトの公式ストアで開かれるセール</div>'
			. '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:14px">' . $cols . '</div>';
	}
	return $html . '</div>';
}

add_shortcode( 'thesale_card', 'thesale_card' );

/*
 * ── カードの自動挿入（1.3.0〜）────────────────────────────
 * 設定 > THE SALE カード で選んだ場所に、セール記事（thesale_cards に載っている投稿）へ自動でカードを入れる。
 * 本文に [thesale_card] を置いた記事は自動では入れず、置いた場所を優先する（記事ごとの例外）。
 */
function thesale_card_positions_all() {
	return array(
		'after_lead'   => 'リード文の直後（最初の見出しの直前）',
		'after_h2'     => '最初の見出しの直後',
		'middle'       => '記事の真ん中（真ん中あたりの見出しの直前）',
		'before_sum'   => '「まとめ」の見出しの直前',
		'end'          => '記事の最後（まとめの直後）',
	);
}

function thesale_card_positions() {
	$v = get_option( 'thesale_card_positions', null );
	if ( null === $v ) {
		return array( 'after_lead' );
	}
	return is_array( $v ) ? array_values( array_intersect( $v, array_keys( thesale_card_positions_all() ) ) ) : array();
}

add_filter( 'the_content', function ( $content ) {
	if ( ! is_singular( 'post' ) || ! in_the_loop() || ! is_main_query() ) {
		return $content;
	}
	if ( false !== strpos( $content, 'ts-card' ) || false !== strpos( $content, '[thesale_card' ) ) {
		return $content; // 本文にカードを置いた記事は、その場所を優先する
	}
	$slug  = (string) get_post_field( 'post_name', get_the_ID() );
	$cards = thesale_card_data();
	if ( '' === $slug || ! isset( $cards[ $slug ] ) ) {
		return $content;
	}
	$pos = thesale_card_positions();
	if ( ! $pos ) {
		return $content;
	}
	$card = thesale_card( array( 'slug' => $slug ) );
	if ( '' === $card ) {
		return $content;
	}
	$card = '<div class="ts-card-auto" style="margin-top:1.6em;margin-bottom:1.6em">' . $card . '</div>';
	// この時点の本文はブロックの描画前（<!-- wp:heading --> の注釈つき）。見出しブロックの内側に入れると
	// ブロックが壊れるので、注釈の外側（見出しブロックの前後）に入れる
	$h_open  = '/(?:<!-- wp:heading[^>]*-->\s*)?<h2/u';
	$h_close = '/<\/h2>(?:\s*<!-- \/wp:heading -->)?/u';
	$ins     = array(); // 挿入位置（文字位置） => true
	if ( in_array( 'after_lead', $pos, true ) && preg_match( $h_open, $content, $m, PREG_OFFSET_CAPTURE ) ) {
		$ins[ $m[0][1] ] = true;
	}
	if ( in_array( 'after_h2', $pos, true ) && preg_match( $h_close, $content, $m, PREG_OFFSET_CAPTURE ) ) {
		$ins[ $m[0][1] + strlen( $m[0][0] ) ] = true;
	}
	if ( in_array( 'middle', $pos, true ) && preg_match_all( '/(?:<!-- wp:heading[^>]*-->\s*)?<h2[^>]*>((?:(?!<\/h2>).)*)<\/h2>/su', $content, $mm, PREG_OFFSET_CAPTURE ) ) {
		// 「よくある質問」「まとめ」を除いた本文の見出しのうち、真ん中の見出しの直前（見出しが3本以上あるとき）
		$body = array();
		foreach ( $mm[0] as $k => $hit ) {
			if ( ! preg_match( '/よくある質問|まとめ/u', wp_strip_all_tags( $mm[1][ $k ][0] ) ) ) {
				$body[] = $hit[1];
			}
		}
		if ( count( $body ) >= 3 ) {
			$ins[ $body[ (int) floor( count( $body ) / 2 ) ] ] = true;
		}
	}
	if ( in_array( 'before_sum', $pos, true ) && preg_match( '/(?:<!-- wp:heading[^>]*-->\s*)?<h2[^>]*>(?:(?!<\/h2>).)*まとめ/su', $content, $m, PREG_OFFSET_CAPTURE ) ) {
		$ins[ $m[0][1] ] = true;
	}
	if ( in_array( 'end', $pos, true ) ) {
		$ins[ strlen( $content ) ] = true;
	}
	krsort( $ins );
	foreach ( array_keys( $ins ) as $at ) {
		$content = substr( $content, 0, $at ) . $card . substr( $content, $at );
	}
	return $content;
}, 1 ); // テーマが最初の見出しの前に差し込む編集部の欄・目次より前（リード文の直後）に入れるため、ブロックの描画より先に動かす

add_action( 'admin_init', function () {
	register_setting( 'thesale_card', 'thesale_card_positions', array(
		'type'              => 'array',
		'sanitize_callback' => function ( $v ) {
			return is_array( $v ) ? array_values( array_intersect( array_map( 'sanitize_key', $v ), array_keys( thesale_card_positions_all() ) ) ) : array();
		},
		'default'           => array( 'after_lead' ),
	) );
} );

add_action( 'admin_menu', function () {
	add_options_page( 'THE SALE カード', 'THE SALE カード', 'manage_options', 'thesale-card', function () {
		$cur = thesale_card_positions();
		echo '<div class="wrap"><h1>THE SALE カード</h1>';
		echo '<p>セール記事に、セールの状況と公式サイト・Amazon・楽天のボタンをまとめたカードを自動で入れます。チェックを入れた場所すべてに入ります。</p>';
		echo '<p>本文に <code>[thesale_card slug="記事のスラッグ"]</code> を置いた記事は、自動では入れず、置いた場所に出します。</p>';
		echo '<form method="post" action="options.php">';
		settings_fields( 'thesale_card' );
		echo '<input type="hidden" name="thesale_card_positions[]" value="">';
		echo '<table class="form-table" role="presentation"><tr><th scope="row">挿入する場所</th><td><fieldset>';
		foreach ( thesale_card_positions_all() as $k => $label ) {
			printf(
				'<label style="display:block;margin:6px 0"><input type="checkbox" name="thesale_card_positions[]" value="%s" %s> %s</label>',
				esc_attr( $k ),
				checked( in_array( $k, $cur, true ), true, false ),
				esc_html( $label )
			);
		}
		echo '</fieldset></td></tr></table>';
		submit_button( '保存' );
		echo '</form></div>';
	} );
} );

/** 設定の読み書きをRESTからも（POST /wp-json/thesale/v1/card-positions {"positions": [...]}） */
add_action( 'rest_api_init', function () {
	register_rest_route( 'thesale/v1', '/card-positions', array(
		array(
			'methods'             => 'GET',
			'permission_callback' => function () { return current_user_can( 'manage_options' ); },
			'callback'            => function () {
				return array( 'positions' => thesale_card_positions(), 'choices' => thesale_card_positions_all() );
			},
		),
		array(
			'methods'             => 'POST',
			'permission_callback' => function () { return current_user_can( 'manage_options' ); },
			'callback'            => function ( $req ) {
				$v = $req->get_param( 'positions' );
				$v = is_array( $v ) ? array_values( array_intersect( array_map( 'sanitize_key', $v ), array_keys( thesale_card_positions_all() ) ) ) : array();
				update_option( 'thesale_card_positions', $v );
				return array( 'positions' => thesale_card_positions() );
			},
		),
	) );
} );

/** [thesale_asof] 当日の日付（「2026年10月14日」） */
add_shortcode( 'thesale_asof', function () {
	return esc_html( thesale_sched_asof() );
} );

/*
 * ── トップページ [thesale_home]（1.4.0〜。吉村さんのデザイン案「買いどきを、逃さない。」）──────────
 * 右の黒いカード＝開催中のセール（無ければ次に始まるセール）、「この先のセール。」＝その次の4件。
 * どちらも日程データ（thesale_events のうちトップに出すもの）から表示のたびに日本時間で選ぶ。
 * CSS はショートコードのページだけキューに載せる（本文に<style>を書くと落ちる・先に捨てられる描画で消える）。
 */
function thesale_home_css() {
	return '
.th-home{--th-ink:#1F221C;--th-lime:#D8F35A;--th-muted:#5F6159;--th-line:#E4E5DE;--th-bg:#F7F7F2;color:var(--th-ink);font-family:"Noto Sans JP",sans-serif}
.th-home a{color:inherit;text-decoration:none}
.th-home .th-en{font-family:"Inter","Noto Sans JP",sans-serif}
.th-hero{display:grid;grid-template-columns:minmax(0,1.1fr) minmax(0,1fr);gap:48px;align-items:stretch;padding:48px 0 40px}
.th-eyebrow{font-size:14px;font-weight:800;letter-spacing:.16em;margin:0 0 28px}
.th-catch{font-size:clamp(44px,6.4vw,76px);font-weight:900;line-height:1.28;letter-spacing:.01em;margin:0 0 28px}
.th-mark{background:linear-gradient(transparent 70%,var(--th-lime) 70%);padding:0 4px 0 0}
.th-lead{font-size:16px;line-height:2.25;color:var(--th-muted);margin:0 0 36px}
.th-btns{display:flex;flex-wrap:wrap;gap:16px 32px;align-items:center}
.th-btn{display:inline-flex;align-items:center;justify-content:space-between;gap:40px;background:var(--th-ink);color:#fff!important;font-weight:800;font-size:17px;padding:22px 30px;min-width:270px;box-sizing:border-box}
.th-link{display:inline-flex;align-items:center;justify-content:space-between;gap:28px;font-weight:800;font-size:17px;padding:12px 0;border-bottom:2px solid var(--th-ink);min-width:190px}
.th-next{background:var(--th-ink);color:#fff;padding:32px 34px;display:flex;flex-direction:column;min-height:440px;box-sizing:border-box}
.th-next-top{display:flex;justify-content:space-between;align-items:center;gap:12px}
.th-next-label{color:var(--th-lime);font-weight:800;font-size:16px;letter-spacing:.12em}
.th-badge{display:inline-block;background:var(--th-lime);color:var(--th-ink);font-weight:800;font-size:15px;padding:8px 14px;line-height:1.3}
.th-badge-off{background:transparent;border:1px solid var(--th-line);color:var(--th-muted);font-weight:700}
.th-date{display:flex;align-items:flex-end;gap:12px;margin:36px 0 26px}
.th-date b{font-size:clamp(64px,9vw,104px);font-weight:900;line-height:.9;letter-spacing:-.02em}
.th-date span{font-size:20px;font-weight:700;padding-bottom:6px}
.th-next-name{font-size:30px;font-weight:900;line-height:1.55;margin:0}
.th-next-foot{margin-top:auto;border-top:1px solid rgba(255,255,255,.18);padding-top:22px;display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;font-size:17px;font-weight:700}
.th-next-foot a{color:var(--th-lime)!important;font-weight:800}
.th-malls{display:flex;justify-content:space-between;flex-wrap:wrap;gap:12px;font-size:16px;color:var(--th-muted);padding:22px 0 30px;border-bottom:1px solid var(--th-line)}
.th-malls i{font-style:normal;margin:0 14px;opacity:.6}
.th-sec{padding:56px 0 24px}
.th-sec-head{display:flex;justify-content:space-between;align-items:flex-end;gap:12px;margin-bottom:40px}
.th-sec-eyebrow{font-size:16px;font-weight:800;letter-spacing:.16em;margin:0 0 10px}
.th-sec-title{font-size:clamp(34px,4.6vw,48px);font-weight:900;margin:0;line-height:1.3}
.th-sec-num{font-size:16px;color:var(--th-muted);letter-spacing:.06em}
.th-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:0 24px}
.th-item{border-top:3px solid var(--th-ink);padding:24px 0 0;margin-bottom:34px;display:flex;flex-direction:column}
.th-item-when{display:flex;align-items:baseline;gap:14px}
.th-item-when b{font-size:46px;font-weight:900;line-height:1.1}
.th-item-when span{font-size:17px;color:var(--th-muted)}
.th-item .th-badge{align-self:flex-start;margin:18px 0 22px;font-size:15px}
.th-item-name{font-size:24px;font-weight:900;line-height:1.65;margin:0 0 22px}
.th-item-foot{border-top:1px solid var(--th-line);padding:20px 0 0;display:flex;justify-content:space-between;color:var(--th-muted);font-size:16px}
.th-note{display:flex;align-items:center;gap:10px;color:var(--th-muted);font-size:15px;padding:4px 0 40px}
@media (max-width:820px){.th-hero{grid-template-columns:1fr;gap:36px;padding-top:28px}.th-next{min-height:0}.th-grid{grid-template-columns:1fr}.th-btn{min-width:0;width:100%}.th-malls{flex-direction:column}}
';
}

function thesale_home_assets() {
	if ( ! wp_style_is( 'thesale-home', 'enqueued' ) ) {
		wp_enqueue_style( 'thesale-home-fonts', 'https://fonts.googleapis.com/css2?family=Inter:wght@500;700;800;900&family=Noto+Sans+JP:wght@500;700;900&display=swap', array(), null );
		wp_register_style( 'thesale-home', false, array(), '1.4.0' );
		wp_enqueue_style( 'thesale-home' );
		wp_add_inline_style( 'thesale-home', thesale_home_css() );
	}
}
add_action( 'wp_enqueue_scripts', function () {
	if ( ! is_singular() ) {
		return;
	}
	$p = get_post();
	if ( $p && has_shortcode( $p->post_content, 'thesale_home' ) ) {
		thesale_home_assets();
	}
} );

/** 日程の名前を「通販サイト名」と「セール名」と「回（第三弾など）」に分ける */
function thesale_home_split( $e ) {
	$name   = isset( $e['name'] ) ? (string) $e['name'] : '';
	$prefix = array( 'Amazon' => 'Amazon', '楽天' => '楽天', 'Qoo10' => 'Qoo10', 'Yahoo!ショッピング' => 'Yahoo!ショッピング', 'Steam' => 'Steam', 'au PAY マーケット' => 'au PAY マーケット' );
	$site   = '';
	foreach ( $prefix as $k => $label ) {
		if ( 0 === strpos( $name, $k ) ) {
			$site = $label;
			$name = trim( substr( $name, strlen( $k ) ) );
			break;
		}
	}
	$round = '';
	if ( preg_match( '/（([^）]*)）\s*$/u', $name, $m ) ) {
		$round = $m[1];
		$name  = trim( preg_replace( '/（[^）]*）\s*$/u', '', $name ) );
	}
	$name = trim( preg_replace( '/\s*20\d\d$/u', '', $name ) );
	return array( $site, $name, $round );
}

function thesale_home_arrow() {
	return '<span aria-hidden="true" class="th-en">&#8599;</span>';
}

function thesale_home( $atts ) {
	thesale_home_assets();
	$now   = thesale_sched_now();
	$today = $now->format( 'Y-m-d' );
	$live  = array();
	$up    = array();
	foreach ( thesale_sched_top_events() as $e ) {
		$s = thesale_sched_dt( isset( $e['start'] ) ? $e['start'] : '' );
		$t = thesale_sched_dt( isset( $e['end'] ) ? $e['end'] : '' );
		if ( $s && $t && $s <= $now && $now < $t ) {
			$live[] = array( 'e' => $e, 's' => $s, 't' => $t, 'key' => $t->format( 'Y-m-d H:i' ) );
		} elseif ( $s && $s > $now ) {
			$up[] = array( 'e' => $e, 's' => $s, 't' => $t, 'key' => $s->format( 'Y-m-d H:i' ) );
		} elseif ( ! $s && ! empty( $e['month'] ) ) {
			$hide = isset( $e['hide_after'] ) ? (string) $e['hide_after'] : '';
			if ( '' === $hide || $hide >= $today ) {
				$up[] = array( 'e' => $e, 's' => null, 't' => null, 'key' => ( isset( $e['sort'] ) ? (string) $e['sort'] : '9999' ) . ' 00:00' );
			}
		}
	}
	usort( $live, function ( $a, $b ) { return strcmp( $a['key'], $b['key'] ); } );
	usort( $up, function ( $a, $b ) { return strcmp( $a['key'], $b['key'] ); } );

	// 右の黒いカード: 開催中があればそれ、無ければ日付の決まっている次のセール
	$hero = null;
	$hero_live = false;
	if ( $live ) {
		$hero      = $live[0];
		$hero_live = true;
	} else {
		foreach ( $up as $i => $u ) {
			if ( $u['s'] ) {
				$hero = $u;
				array_splice( $up, $i, 1 );
				break;
			}
		}
	}

	$o  = '<div class="th-home">';
	$o .= '<section class="th-hero"><div>';
	$o .= '<p class="th-eyebrow th-en">GOOD TIMING. GREAT SHOPPING.</p>';
	$o .= '<h1 class="th-catch">買いどきを、<br><span class="th-mark">逃さない。</span></h1>';
	$o .= '<p class="th-lead">いつものお店も、気になるブランドも。<br>次のセールが、ひと目でわかる。<br>買い物の予定は、ここから。</p>';
	$o .= '<div class="th-btns"><a class="th-btn" href="#th-coming">次のセールを見る' . thesale_home_arrow() . '</a>'
		. '<a class="th-link" href="' . esc_url( home_url( '/sale-list/' ) ) . '">ブランドから探す' . thesale_home_arrow() . '</a></div>';
	$o .= '</div>';

	if ( $hero ) {
		list( $site, $nm, $round ) = thesale_home_split( $hero['e'] );
		$d     = $hero_live ? $hero['t'] : $hero['s'];
		$time  = $hero_live ? '〜' . ( in_array( $d->format( 'H:i' ), array( '00:00', '23:59' ), true ) ? 'まで' : $d->format( 'G:i' ) . 'まで' )
			: ( in_array( $d->format( 'H:i' ), array( '00:00', '23:59' ), true ) ? '〜' : $d->format( 'G:i' ) . '–' );
		$foot  = $hero['s']->format( 'Y' ) . '年' . $hero['s']->format( 'n' ) . '月' . ( '' !== $round ? '・' . $round : '' );
		$o .= '<a class="th-next" href="' . esc_url( thesale_sched_url( isset( $hero['e']['url'] ) ? $hero['e']['url'] : '' ) ) . '">'
			. '<div class="th-next-top"><span class="th-next-label th-en">' . ( $hero_live ? 'NOW ON / 開催中のセール' : 'NEXT UP / 次のセール' ) . '</span>'
			. '<span class="th-badge">' . ( $hero_live ? '開催中' : '開催予定' ) . '</span></div>'
			. '<div class="th-date th-en"><b>' . esc_html( $d->format( 'n.d' ) ) . '</b><span>' . esc_html( $time ) . '</span></div>'
			. '<p class="th-next-name">' . ( '' !== $site ? esc_html( $site ) . '<br>' : '' ) . esc_html( $nm ) . '</p>'
			. '<div class="th-next-foot"><span>' . esc_html( $foot ) . '</span><span style="color:#D8F35A">日程・参加条件 ' . thesale_home_arrow() . '</span></div>'
			. '</a>';
	}
	$o .= '</section>';

	$o .= '<div class="th-malls"><span>Amazon<i>/</i>楽天市場<i>/</i>Yahoo!ショッピング<i>/</i>Qoo10<i>/</i>and more</span>'
		. '<a href="' . esc_url( home_url( '/information-policy/' ) ) . '">情報の取得方法と掲載基準 ' . thesale_home_arrow() . '</a></div>';

	$o .= '<section class="th-sec" id="th-coming"><div class="th-sec-head"><div><p class="th-sec-eyebrow th-en">COMING NEXT</p>'
		. '<h2 class="th-sec-title">この先のセール。</h2></div><span class="th-sec-num th-en">01 / UPCOMING</span></div><div class="th-grid">';
	foreach ( array_slice( $up, 0, 4 ) as $u ) {
		list( $site, $nm, $round ) = thesale_home_split( $u['e'] );
		if ( $u['s'] ) {
			$month = $u['s']->format( 'n' ) . '月';
			$sub   = $u['s']->format( 'j' ) . '日';
			$badge = '<span class="th-badge">開催予定</span>';
		} else {
			$month = isset( $u['e']['month'] ) ? $u['e']['month'] : '';
			$sub   = ( isset( $u['e']['day'] ) ? $u['e']['day'] : '' ) . '目安';
			$badge = '<span class="th-badge th-badge-off">日程未発表</span>';
		}
		$o .= '<a class="th-item" href="' . esc_url( thesale_sched_url( isset( $u['e']['url'] ) ? $u['e']['url'] : '' ) ) . '">'
			. '<div class="th-item-when"><b>' . esc_html( $month ) . '</b><span>' . esc_html( $sub ) . '</span></div>' . $badge
			. '<p class="th-item-name">' . ( '' !== $site ? esc_html( $site ) . '<br>' : '' ) . esc_html( $nm ) . '</p>'
			. '<div class="th-item-foot"><span>日程・開催実績を確認</span>' . thesale_home_arrow() . '</div></a>';
	}
	$o .= '</div>';
	$o .= '<p class="th-note"><span aria-hidden="true" style="display:inline-flex;width:20px;height:20px;border:1.5px solid currentColor;border-radius:50%;align-items:center;justify-content:center;font-size:12px;font-weight:700">i</span>'
		. esc_html( $now->format( 'n' ) . '月' . $now->format( 'j' ) . '日時点、' . ( $live ? '開催中の大型セールは' . count( $live ) . '件です。' : '開催中の大型セールはありません。' ) . '未発表の日程は、過去の開催時期を目安にしています。' )
		. '</p></section></div>';
	return $o;
}
add_shortcode( 'thesale_home', 'thesale_home' );
