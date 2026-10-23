<?php
/**
 * Plugin Name: THE SALE セール日程表示
 * Description: トップの「いま開催中のセール」「これから始まるセール」を、セール日程データ（オプション thesale_events）から表示のたびに日本時間で計算して出すショートコード。終わったセールは自動で消え、時点の日付も当日になる。
 * Version:     1.5.3
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
.th-home a.th-next,.th-home a.th-next *{color:#fff}
.th-home a.th-btn,.th-home a.th-btn *{color:#fff}
.th-home{background:var(--th-bg);box-shadow:0 0 0 100vmax var(--th-bg);clip-path:inset(0 -100vmax)}
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
.th-home .th-next .th-next-label,.th-home .th-next .th-lime{color:var(--th-lime)}
.th-next-label{color:var(--th-lime);font-weight:800;font-size:16px;letter-spacing:.12em}
.th-home .th-next .th-badge{color:var(--th-ink)}
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
@media (max-width:820px){.th-next{padding:24px 22px}.th-next-label{font-size:13px;letter-spacing:.06em}.th-badge{white-space:nowrap;font-size:13px;padding:6px 10px}.th-date{margin:24px 0 18px}.th-date b{font-size:72px}.th-next-name{font-size:24px}.th-next-foot{font-size:15px}.th-hero{grid-template-columns:1fr;gap:36px;padding-top:28px}.th-next{min-height:0}.th-grid{grid-template-columns:1fr}.th-btn{min-width:0;width:100%}.th-malls{flex-direction:column}}
';
}

function thesale_home_assets() {
	if ( ! wp_style_is( 'thesale-home', 'enqueued' ) ) {
		wp_enqueue_style( 'thesale-home-fonts', 'https://fonts.googleapis.com/css2?family=Inter:wght@500;700;800;900&family=Noto+Sans+JP:wght@500;700;900&display=swap', array(), null );
		wp_register_style( 'thesale-home', false, array(), '1.4.0' );
		wp_enqueue_style( 'thesale-home' );
		wp_add_inline_style( 'thesale-home', thesale_home_css() . thesale_home_lower_css() );
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
			. '<div class="th-next-foot"><span>' . esc_html( $foot ) . '</span><span style="color:#D8F35A!important" class="th-lime">日程・参加条件 ' . thesale_home_arrow() . '</span></div>'
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
		. '</p></section>' . thesale_home_lower( $now ) . '</div>';
	return $o;
}
add_shortcode( 'thesale_home', 'thesale_home' );

/*
 * ── トップページの下半分（1.5.0〜）: 02 月別カレンダー／03 ブランド一覧／読みもの／カテゴリ／編集方針 ─────
 * データはオプション thesale_home（tools/push_home_data.py が書く）。月の切り替え・検索・さらに表示は小さなJS。
 */
function thesale_home_lower_css() {
	return '
.th-band{box-shadow:0 0 0 100vmax var(--th-band);clip-path:inset(0 -100vmax);background:var(--th-band);padding:64px 0}
.th-band-sage{--th-band:#ECEFE5}.th-band-lime{--th-band:#D6F55C}.th-band-paper{--th-band:#F7F7F2}
.th-tabs{display:grid;grid-template-columns:repeat(12,minmax(0,1fr));border:1px solid var(--th-line);background:#FAFAF6;margin-bottom:34px}
.th-tab{appearance:none;border:0;border-left:1px solid var(--th-line);background:transparent;padding:22px 0;font:800 17px "Noto Sans JP",sans-serif;color:var(--th-ink);cursor:pointer}
.th-tab:first-child{border-left:0}
.th-tab.is-on{background:var(--th-ink);color:#fff}
.th-cal{display:grid;grid-template-columns:240px minmax(0,1fr);gap:28px}
.th-cal-panel{display:none}.th-cal-panel.is-on{display:grid}
.th-cal-year{font-size:17px;letter-spacing:.14em;margin:12px 0 18px}
.th-cal-num{font-size:150px;font-weight:900;line-height:.85;letter-spacing:-.04em}
.th-cal-unit{font-size:26px;margin:14px 0 30px 8px}
.th-cal-list{border-top:1px solid var(--th-ink)}
.th-cal-row{display:grid;grid-template-columns:150px minmax(0,1fr) 30px;align-items:center;gap:12px;padding:26px 0;border-bottom:1px solid var(--th-line)}
.th-cal-site{font-size:22px;font-weight:800}
.th-cal-ev b{display:block;font-size:22px;font-weight:800;margin-bottom:6px}
.th-cal-ev span{font-size:16px;color:var(--th-muted)}
.th-cal-empty{padding:26px 0;color:var(--th-muted);border-bottom:1px solid var(--th-line)}
.th-small{font-size:16px;color:var(--th-muted);margin:30px 0 0}
.th-search{display:grid;grid-template-columns:minmax(0,1fr) 300px;gap:18px;margin-bottom:28px}
.th-search label{display:flex;align-items:center;gap:14px;background:#F8FBEA;border:1px solid rgba(31,34,28,.55);padding:0 24px;height:88px}
.th-search input{border:0;background:transparent;font:500 20px "Noto Sans JP",sans-serif;width:100%;outline:0;color:var(--th-ink)}
.th-search select{border:1px solid rgba(31,34,28,.55);background:transparent;font:600 17px "Noto Sans JP",sans-serif;padding:0 22px;height:90px;color:var(--th-ink)}
.th-brands{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:0 22px;border-top:1px solid rgba(31,34,28,.45)}
.th-brand{display:flex;justify-content:space-between;align-items:center;padding:26px 0;border-bottom:1px solid rgba(31,34,28,.45)}
.th-brand b{display:block;font-size:24px;font-weight:800;margin-bottom:8px}
.th-brand span{font-size:15px;color:#3E4433}
.th-brand.is-hidden{display:none}
.th-brand-foot{display:flex;justify-content:space-between;align-items:center;gap:16px;flex-wrap:wrap;margin-top:30px;font-size:16px;color:#3E4433}
.th-more{appearance:none;border:1px solid var(--th-ink);background:transparent;font:800 17px "Noto Sans JP",sans-serif;padding:18px 26px;cursor:pointer;color:var(--th-ink)}
.th-feature{display:block;margin-bottom:36px}
.th-art{display:flex;flex-direction:column;justify-content:space-between;padding:34px 30px;min-height:240px;box-sizing:border-box}
.th-art-dark{background:var(--th-ink);color:var(--th-lime)}
.th-art-sage{background:#ECEFE5;border:1px solid var(--th-line);color:var(--th-ink)}
.th-art-lime{background:#D6F55C;color:var(--th-ink)}
.th-art small{font-size:15px;font-weight:800;letter-spacing:.14em}
.th-art strong{font-family:"Inter","Noto Sans JP",sans-serif;font-size:clamp(40px,5.6vw,66px);font-weight:900;line-height:1.02;letter-spacing:-.03em;white-space:pre-line}
.th-art-sage strong{font-family:Georgia,"Times New Roman",serif;font-style:italic;font-weight:400;letter-spacing:-.02em}
.th-art-lime strong{font-family:"Noto Sans JP",sans-serif;font-size:clamp(34px,4.4vw,52px);line-height:1.35}
.th-feature-meta{display:flex;justify-content:space-between;color:var(--th-muted);font-size:15px;margin:18px 0 12px}
.th-feature-title{font-size:21px;font-weight:800;line-height:1.7;margin:0}
.th-feature-row{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:30px}
.th-rank{border-top:1px solid var(--th-line);margin-top:10px;padding-top:26px;list-style:none;margin-left:0;padding-left:0}
.th-rank li{display:flex;gap:26px;padding:12px 0;font-size:18px;margin:0}
.th-rank li::before{content:none}
.th-rank em{font-style:normal;font-weight:800;color:var(--th-muted);font-family:"Inter",sans-serif}
.th-cats{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));border-top:1px solid var(--th-ink)}
.th-cat{display:flex;justify-content:space-between;align-items:center;padding:42px 28px 42px 0;border-bottom:1px solid var(--th-line);border-left:1px solid var(--th-line);padding-left:26px}
.th-cat:nth-child(3n+1){border-left:0;padding-left:0}
.th-cat b{display:block;font-size:24px;font-weight:800;margin-bottom:12px}
.th-cat span{font-size:15px;letter-spacing:.12em;color:var(--th-muted)}
.th-std{display:grid;grid-template-columns:minmax(0,.8fr) minmax(0,1.2fr);gap:60px}
.th-std h2{font-size:clamp(32px,4vw,40px);font-weight:900;line-height:1.55;margin:0 0 40px}
.th-std ol{list-style:none;margin:0;padding:0}
.th-std li{display:grid;grid-template-columns:62px minmax(0,1fr);margin:0 0 34px}
.th-std li::before{content:none}
.th-std li em{font-style:normal;color:var(--th-muted);font-family:"Inter",sans-serif;padding-top:4px}
.th-std li b{display:block;font-size:20px;font-weight:800;margin-bottom:8px}
.th-std li span{color:var(--th-muted);line-height:1.95;font-size:16px}
.th-home .th-arrow,.th-home .th-brand .th-arrow,.th-home .th-cat .th-arrow{font-size:28px;font-family:"Inter",sans-serif;color:var(--th-ink);letter-spacing:0}
.th-home h2,.th-home h2.wp-block-heading{background:none!important;color:var(--th-ink)!important;padding:0!important;border:0!important;box-shadow:none!important;border-radius:0!important}
.th-home h2::before,.th-home h2::after{display:none!important;content:none!important}
.th-search svg{flex-shrink:0}
.th-search svg,.th-search svg *{fill:none!important;stroke:currentColor}
.th-home .th-arrow span,.th-home .th-arrow .th-en{font-size:inherit!important;color:inherit!important;letter-spacing:0}
@media (max-width:820px){
.th-tabs{grid-template-columns:repeat(6,minmax(0,1fr))}.th-tab:nth-child(7){border-left:0}.th-tab:nth-child(n+7){border-top:1px solid var(--th-line)}
.th-cal{grid-template-columns:1fr}.th-cal-num{font-size:110px}.th-cal-row{grid-template-columns:96px minmax(0,1fr) 24px}.th-cal-site,.th-cal-ev b{font-size:18px}
.th-search{grid-template-columns:1fr}.th-search label,.th-search select{height:64px}
.th-brands{grid-template-columns:repeat(2,minmax(0,1fr))}.th-brand b{font-size:19px}
.th-feature-row,.th-std{grid-template-columns:1fr}.th-cats{grid-template-columns:1fr 1fr}.th-cat{padding:28px 12px}.th-cat:nth-child(3n+1){border-left:1px solid var(--th-line);padding-left:12px}.th-cat:nth-child(2n+1){border-left:0;padding-left:0}.th-cat b{font-size:19px}
.th-sec-num{display:none}
.th-sec-head{flex-direction:column;align-items:flex-start;gap:20px;margin-bottom:32px}.th-sec-head>a{align-self:flex-start}}
';
}

function thesale_home_lower_js() {
	return <<<'JS'
document.addEventListener('DOMContentLoaded',function(){
  document.querySelectorAll('.th-tabs').forEach(function(t){
    t.addEventListener('click',function(ev){var b=ev.target.closest('.th-tab');if(!b)return;
      t.querySelectorAll('.th-tab').forEach(function(x){x.classList.toggle('is-on',x===b);x.setAttribute('aria-selected',x===b?'true':'false')});
      var root=t.parentNode;root.querySelectorAll('.th-cal-panel').forEach(function(p){p.classList.toggle('is-on',p.dataset.m===b.dataset.m)});});
  });
  document.querySelectorAll('.th-bi').forEach(function(root){
    var q=root.querySelector('input'),sel=root.querySelector('select'),more=root.querySelector('.th-more'),cnt=root.querySelector('.th-count'),
        items=[].slice.call(root.querySelectorAll('.th-brand')),shown=8;
    function apply(){var k=(q.value||'').trim().toLowerCase(),g=sel.value,hit=0,vis=0;
      items.forEach(function(it){var ok=(!k||it.dataset.n.toLowerCase().indexOf(k)>=0)&&(!g||it.dataset.g===g);
        if(ok){hit++;}var show=ok&&(k||g?true:hit<=shown);it.classList.toggle('is-hidden',!show);if(show)vis++;});
      cnt.textContent='掲載'+items.length+'件 / '+vis+'件を表示';more.style.display=(k||g||vis>=hit)?'none':'';}
    q.addEventListener('input',apply);sel.addEventListener('change',apply);
    more.addEventListener('click',function(){shown+=8;apply();});apply();
  });
});
JS;
}

function thesale_home_lower( $now ) {
	$d = get_option( 'thesale_home', array() );
	if ( is_string( $d ) ) {
		$d = json_decode( $d, true );
	}
	if ( ! is_array( $d ) ) {
		return '';
	}
	wp_register_script( 'thesale-home', false, array(), '1.5.0', true );
	wp_enqueue_script( 'thesale-home' );
	wp_add_inline_script( 'thesale-home', thesale_home_lower_js() );
	$a  = thesale_home_arrow();
	$o  = '';

	// 02 カレンダー
	$cal = isset( $d['calendar'] ) ? $d['calendar'] : array();
	$en  = array( 1 => 'january', 'february', 'march', 'april', 'may', 'june', 'july', 'august', 'september', 'october', 'november', 'december' );
	$m0  = (int) $now->format( 'n' );
	$y0  = (int) $now->format( 'Y' );
	// 月の途中なら翌月から（10月23日なら11月始まり。1日〜10日なら当月から）
	if ( (int) $now->format( 'j' ) > 10 ) {
		$m0 = 12 === $m0 ? 1 : $m0 + 1;
		$y0 = 1 === $m0 ? $y0 + 1 : $y0;
	}
	$tabs = '';
	$pans = '';
	for ( $i = 0; $i < 12; $i++ ) {
		$m = ( ( $m0 - 1 + $i ) % 12 ) + 1;
		$y = $y0 + (int) floor( ( $m0 - 1 + $i ) / 12 );
		$on = 0 === $i ? ' is-on' : '';
		$tabs .= '<button type="button" class="th-tab' . $on . '" data-m="' . $m . '" aria-selected="' . ( $on ? 'true' : 'false' ) . '">' . $m . '月</button>';
		$art = isset( $cal['month_article'][ (string) $m ] ) ? $cal['month_article'][ (string) $m ] : str_replace( '{en}', $en[ $m ], isset( $cal['month_article_default'] ) ? $cal['month_article_default'] : 'amazon-sale-{en}' );
		$rows = '';
		$list = isset( $cal['months'][ (string) $m ] ) && is_array( $cal['months'][ (string) $m ] ) ? $cal['months'][ (string) $m ] : array();
		foreach ( $list as $r ) {
			$rows .= '<a class="th-cal-row" href="' . esc_url( home_url( '/' . $r[2] . '/' ) ) . '"><span class="th-cal-site th-en">' . esc_html( $r[0] ) . '</span>'
				. '<span class="th-cal-ev"><b>' . esc_html( $r[1] ) . '</b><span>例年の開催傾向 · 正式日程は記事で確認</span></span><span class="th-arrow">' . $a . '</span></a>';
		}
		if ( '' === $rows ) {
			$rows = '<p class="th-cal-empty">この月に例年開かれる大型セールはありません。月ごとの記事で、小さなセールの過去の日程を確かめられます。</p>';
		}
		$pans .= '<div class="th-cal th-cal-panel' . $on . '" data-m="' . $m . '"><div><p class="th-cal-year th-en">' . $y . '</p>'
			. '<div class="th-cal-num th-en">' . $m . '</div><p class="th-cal-unit">月</p>'
			. '<a class="th-link" href="' . esc_url( home_url( '/' . $art . '/' ) ) . '">この月の記事へ' . $a . '</a></div>'
			. '<div class="th-cal-list">' . $rows . '</div></div>';
	}
	$o .= '<section class="th-band th-band-sage"><div class="th-sec-head"><div><p class="th-sec-eyebrow th-en">PLAN YOUR SHOPPING</p>'
		. '<h2 class="th-sec-title">買い時を、先読み。</h2></div><span class="th-sec-num th-en">02 / SALE CALENDAR</span></div>'
		. '<div class="th-calwrap"><div class="th-tabs" role="tablist">' . $tabs . '</div>' . $pans . '</div>'
		. '<p class="th-small">Amazon・楽天市場・Qoo10の、例年の主なセールを掲載しています。開催時期の傾向であり、確定した日程ではありません。</p></section>';

	// 03 ブランド一覧
	$brands = isset( $d['brands'] ) && is_array( $d['brands'] ) ? $d['brands'] : array();
	$groups = isset( $d['groups'] ) && is_array( $d['groups'] ) ? $d['groups'] : array();
	$opts   = '<option value="">すべてのカテゴリ</option>';
	foreach ( $groups as $g ) {
		$opts .= '<option value="' . esc_attr( $g ) . '">' . esc_html( $g ) . '</option>';
	}
	$items = '';
	foreach ( $brands as $b ) {
		$items .= '<a class="th-brand" data-n="' . esc_attr( $b['name'] ) . '" data-g="' . esc_attr( $b['group'] ) . '" href="' . esc_url( home_url( $b['url'] ) ) . '">'
			. '<span><b>' . esc_html( $b['name'] ) . '</b><span>' . ( '' !== $b['verified'] ? '最終確認 ' . esc_html( $b['verified'] ) : '' ) . '</span></span><span class="th-arrow">' . $a . '</span></a>';
	}
	$n = count( $brands );
	$o .= '<section class="th-band th-band-lime th-bi"><div class="th-sec-head"><div><p class="th-sec-eyebrow th-en">THE BRAND INDEX</p>'
		. '<h2 class="th-sec-title">好きなお店の、次のセール。</h2></div><span class="th-sec-num th-en">03 / ' . $n . ' SHOPS &amp; MORE</span></div>'
		. '<div class="th-search"><label><svg aria-hidden="true" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="7"/><path d="M20 20l-4-4"/></svg><input type="search" placeholder="店名・ブランド名で探す" aria-label="店名・ブランド名で探す"></label>'
		. '<select aria-label="カテゴリ">' . $opts . '</select></div>'
		. '<div class="th-brands">' . $items . '</div>'
		. '<div class="th-brand-foot"><span class="th-count">掲載' . $n . '件</span><button type="button" class="th-more">さらに表示する</button>'
		. '<a class="th-link" href="' . esc_url( home_url( '/sale-list/' ) ) . '">掲載ショップ・ブランドをすべて見る' . $a . '</a></div></section>';

	// 読みもの
	$edit = isset( $d['edit'] ) ? $d['edit'] : array();
	$card = function ( $f ) use ( $a ) {
		$p = get_page_by_path( $f['slug'], OBJECT, 'post' );
		if ( ! $p ) {
			return '';
		}
		$cats  = get_the_category( $p->ID );
		$cat   = $cats ? $cats[0]->name : '';
		$title = get_the_title( $p );
		$title = preg_replace( '/？/u', '？<br>', esc_html( $title ), 1 );
		return '<a class="th-feature" href="' . esc_url( get_permalink( $p ) ) . '"><div class="th-art th-art-' . esc_attr( $f['style'] ) . '">'
			. '<small class="th-en">' . esc_html( $f['eyebrow'] ) . '</small><strong>' . esc_html( $f['art'] ) . '</strong></div>'
			. '<div class="th-feature-meta"><span>' . esc_html( $cat ) . '</span><span class="th-en">' . esc_html( get_the_date( 'Y.m.d', $p ) ) . '</span></div>'
			. '<p class="th-feature-title">' . $title . '</p></a>';
	};
	$fs   = isset( $edit['features'] ) && is_array( $edit['features'] ) ? $edit['features'] : array();
	$feat = '';
	if ( $fs ) {
		$feat .= $card( $fs[0] );
		$rest  = '';
		foreach ( array_slice( $fs, 1 ) as $f ) {
			$rest .= $card( $f );
		}
		if ( '' !== $rest ) {
			$feat .= '<div class="th-feature-row">' . $rest . '</div>';
		}
	}
	$rank = '';
	$i    = 0;
	foreach ( ( isset( $edit['list'] ) && is_array( $edit['list'] ) ? $edit['list'] : array() ) as $slug ) {
		$p = get_page_by_path( $slug, OBJECT, 'post' );
		if ( ! $p ) {
			continue;
		}
		$i++;
		$rank .= '<li><em>' . sprintf( '%02d', $i ) . '</em><a href="' . esc_url( get_permalink( $p ) ) . '">' . esc_html( get_the_title( $p ) ) . '</a></li>';
	}
	$o .= '<section class="th-band th-band-paper"><div class="th-sec-head"><div><p class="th-sec-eyebrow th-en">THE SHOPPING EDIT</p>'
		. '<h2 class="th-sec-title">買い物の前に、読む。</h2></div><a class="th-link" href="' . esc_url( home_url( '/articles/' ) ) . '" style="min-width:120px">記事一覧' . $a . '</a></div>'
		. $feat . ( '' !== $rank ? '<ol class="th-rank">' . $rank . '</ol>' : '' ) . '</section>';

	// カテゴリ
	$cats = array( array( 'mall', 'ネット通販', 'ONLINE MALL' ), array( 'season', '季節のセール', 'SEASONAL SALE' ), array( 'gadget', '家電・ガジェット', 'TECH &amp; HOME' ),
		array( 'game', 'ゲーム', 'GAMES' ), array( 'fashion', 'ファッション', 'FASHION' ), array( 'otoku', 'お得に買うコツ', 'SHOPPING TIPS' ) );
	$ct   = '';
	foreach ( $cats as $c ) {
		$t = get_category_by_slug( $c[0] );
		if ( ! $t ) {
			continue;
		}
		$ct .= '<a class="th-cat" href="' . esc_url( get_category_link( $t ) ) . '"><span><b>' . esc_html( $c[1] ) . '</b><span class="th-en">' . $c[2] . '</span></span><span class="th-arrow">' . $a . '</span></a>';
	}
	$o .= '<section class="th-band th-band-paper" style="border-top:1px solid var(--th-line)"><div class="th-sec-head"><div><p class="th-sec-eyebrow th-en">FIND YOUR NEXT</p>'
		. '<h2 class="th-sec-title">何のセールを探す？</h2></div><a class="th-link" href="' . esc_url( home_url( '/category-list/' ) ) . '" style="min-width:120px">全カテゴリ' . $a . '</a></div>'
		. '<div class="th-cats">' . $ct . '</div></section>';

	// 編集方針
	$o .= '<section class="th-band th-band-sage"><div class="th-std"><div><p class="th-sec-eyebrow th-en">OUR EDITORIAL STANDARD</p>'
		. '<h2>日程を知る。<br>根拠も、わかる。</h2><a class="th-link" href="' . esc_url( home_url( '/information-policy/' ) ) . '">情報の取得方法と掲載基準' . $a . '</a></div>'
		. '<ol><li><em>01</em><div><b>公式の情報を起点に。</b><span>通販サイトやブランドの公式発表を優先して、セールの開催日程をまとめています。</span></div></li>'
		. '<li><em>02</em><div><b>予定と、過去の実績を分けて。</b><span>日程がまだ発表されていないものは「日程未発表」と表示。過去の開催時期を目安として紹介します。</span></div></li>'
		. '<li><em>03</em><div><b>いつ確認した情報か、見えるように。</b><span>ブランド一覧では「最終確認日」を掲載。公式の告知を最後に確認した日がわかります。</span></div></li></ol></div></section>';
	return $o;
}
