<?php
/**
 * サイト所有確認の <meta> を <head> に出す（1.6.0）
 *
 * ── 目的
 * re:Diver には <head> に任意のタグを足す設定欄が無い。Pinterest・Google・Bing などの
 * ドメイン認証タグを、テーマを編集せずに REST から入れられるようにする。
 *
 * ── 書き方
 *   POST /wp-json/rdh/v1/option/rdh_head_meta
 *   {"value":{"p:domain_verify":"8e1e79a32e3959214336ef0e42ea4b87"}}
 * キーが name 属性、値が content 属性になる。1項目だけ足すときは merge=true。
 * 出せるのは <meta name content> だけ。任意のHTMLは受け付けない。
 *
 * 止めたいときは functions.php 等で:
 *   add_filter('rdh_head_meta_output', '__return_false');
 */

if (!defined('ABSPATH')) exit;

add_action('wp_head', function () {
    if (!apply_filters('rdh_head_meta_output', true)) return;

    $metas = get_option('rdh_head_meta', []);
    if (!is_array($metas) || !$metas) return;

    foreach ($metas as $name => $content) {
        $name    = (string) $name;
        $content = trim((string) $content);
        // name は認証タグで使う範囲の文字だけ（p:domain_verify / google-site-verification / msvalidate.01 など）
        if ($content === '' || !preg_match('/^[A-Za-z0-9:._-]{1,64}$/', $name)) continue;
        printf("<meta name=\"%s\" content=\"%s\" />\n", esc_attr($name), esc_attr($content));
    }
}, 1);
