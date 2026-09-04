<?php
/**
 * 外部リンクアイコンの豆腐（□）を直す
 *
 * ── 原因（2026-09-04 実測）
 * re:Diver の style.min.css に次の1行がある:
 *   .wp-block-paragraph a[target=_blank]:not([class]):after{
 *     content:"\e89e";  font-family:Google Symbols }
 *
 * ところがテーマは Google Symbols を `&text=` で必要な字だけ切り出して読み込む。
 * 実測したサブセットは 10〜11 字で、そこに U+E89E（open_in_new）が入っていない。
 *   bouhan-get.com : E000 E313 E5CC E5CD E5D8 E866 E876 E88E E8B6 E90C
 *   e-kagi.com     : 上記 + E5D2
 * つまり本文に外部リンクを置くと、re:Diver のサイトでは必ず豆腐になる。
 *
 * ── 直し方
 * 同じセレクタに、フォントに依存しない実文字の矢印を上書きする。
 * 記事本文には一切触らない（既存記事を書き換えずに全ページが直る）。
 *
 * 注意: CSS に "\2197" のようなユニコードエスケープを書かない。
 *       実文字を書く（保存経路によってバックスラッシュが落ちて壊れるため）。
 *
 * 止めたいときは functions.php 等で:
 *   add_filter('rdh_extlink_icon_fix', '__return_false');
 */

if (!defined('ABSPATH')) exit;

add_action('wp_head', function () {
    if (is_admin()) return;
    if (!apply_filters('rdh_extlink_icon_fix', true)) return;

    // テーマ本体の style.min.css より後に出す（wp_head の優先度で担保）
    $css = '.wp-block-paragraph a[target="_blank"]:not([class])::after{'
         . 'content:"↗";'
         . 'font-family:inherit;'
         . 'font-size:.82em;'
         . 'line-height:1;'
         . 'margin-left:.15em;'
         . 'opacity:.65;'
         . 'vertical-align:.05em;'
         . '}';

    echo "\n<style id=\"rdh-extlink-icon\">" . $css . "</style>\n";
}, 100);
