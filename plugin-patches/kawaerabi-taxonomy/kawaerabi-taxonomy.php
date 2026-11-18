<?php
/**
 * Plugin Name: 革えらび 掛け算タクソノミー
 * Description: 革えらびの掛け算の軸（革・性別・年代/相手・用途・仕様）をカスタムタクソノミーとして登録する。記事が少ない一覧ページは検索に出さない（noindex）。
 * Version:     1.0.0
 * Author:      Keys
 * Requires at least: 6.0
 * Requires PHP: 7.4
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'KAWAERABI_TAX_VERSION', '1.0.0' );
// 一覧ページを検索に出す最小の記事数（これ未満は noindex,follow）
define( 'KAWAERABI_TAX_MIN_INDEX', 3 );

require_once __DIR__ . '/includes/plugin-updater.php';
add_action( 'init', function () {
	new Kawaerabi_Plugin_Updater(
		__FILE__,
		'https://raw.githubusercontent.com/yoshimucom-gif/wp-manager/main/plugin-host/api/plugin-update/kawaerabi-taxonomy'
	);
} );

/**
 * 軸の定義。キー＝タクソノミー名、slug＝URLの階層、hierarchical＝親子を持つか。
 */
function kawaerabi_tax_axes() {
	return array(
		'kawa_leather' => array( 'label' => '革の種類', 'slug' => 'leather', 'hierarchical' => true ),
		'kawa_gender'  => array( 'label' => '性別', 'slug' => 'gender', 'hierarchical' => false ),
		'kawa_scene'   => array( 'label' => '年代・贈る相手', 'slug' => 'scene', 'hierarchical' => false ),
		'kawa_use'     => array( 'label' => '用途・シーン', 'slug' => 'use', 'hierarchical' => false ),
		'kawa_spec'    => array( 'label' => '仕様', 'slug' => 'spec', 'hierarchical' => false ),
	);
}

add_action( 'init', function () {
	foreach ( kawaerabi_tax_axes() as $tax => $a ) {
		register_taxonomy( $tax, array( 'post' ), array(
			'labels'            => array(
				'name'          => $a['label'],
				'singular_name' => $a['label'],
				'search_items'  => $a['label'] . 'を検索',
				'all_items'     => 'すべての' . $a['label'],
				'edit_item'     => $a['label'] . 'を編集',
				'add_new_item'  => $a['label'] . 'を追加',
				'menu_name'     => $a['label'],
			),
			'public'            => true,
			'hierarchical'      => $a['hierarchical'],
			'show_ui'           => true,
			'show_admin_column' => true,
			'show_in_rest'      => true,
			'rest_base'         => $a['slug'],
			'rewrite'           => array( 'slug' => $a['slug'], 'with_front' => false, 'hierarchical' => $a['hierarchical'] ),
		) );
	}
}, 5 );

// 有効化時にパーマリンクの規則を作り直す（一覧ページが404にならないように）
register_activation_hook( __FILE__, function () {
	foreach ( kawaerabi_tax_axes() as $tax => $a ) {
		register_taxonomy( $tax, array( 'post' ), array(
			'public'       => true,
			'hierarchical' => $a['hierarchical'],
			'rewrite'      => array( 'slug' => $a['slug'], 'with_front' => false, 'hierarchical' => $a['hierarchical'] ),
		) );
	}
	flush_rewrite_rules();
} );
register_deactivation_hook( __FILE__, 'flush_rewrite_rules' );

/**
 * 記事が少ない一覧ページは検索に出さない。中身の薄いページを量産しないため。
 */
add_filter( 'wp_robots', function ( $robots ) {
	if ( ! is_tax( array_keys( kawaerabi_tax_axes() ) ) ) {
		return $robots;
	}
	$term = get_queried_object();
	if ( $term && isset( $term->count ) && (int) $term->count < KAWAERABI_TAX_MIN_INDEX ) {
		$robots['noindex'] = true;
		$robots['follow']  = true;
		unset( $robots['max-image-preview'] );
	}
	return $robots;
} );

/**
 * サイトマップ（WP標準）からも、記事が少ない一覧ページを外す。
 */
add_filter( 'wp_sitemaps_taxonomies_query_args', function ( $args, $taxonomy ) {
	if ( array_key_exists( $taxonomy, kawaerabi_tax_axes() ) ) {
		$ids = get_terms( array( 'taxonomy' => $taxonomy, 'hide_empty' => true, 'fields' => 'id=>count' ) );
		$keep = array();
		if ( ! is_wp_error( $ids ) ) {
			foreach ( $ids as $id => $count ) {
				if ( (int) $count >= KAWAERABI_TAX_MIN_INDEX ) {
					$keep[] = (int) $id;
				}
			}
		}
		$args['include'] = $keep ? $keep : array( 0 );
	}
	return $args;
}, 10, 2 );
