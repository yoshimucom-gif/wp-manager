<?php
/**
 * 目次に出るアイコンの豆腐（□）を直す
 *
 * ── 原因（2026-11-27 実測・給湯器ドック /kyutto/）
 * 見出しの頭に diver-blocks のインラインアイコンを置くと、保存される HTML は
 *   <h3 ...><span data-code="eaaa" class="dbp-e-icon material-icon ...">{U+EAAA}</span>見出し</h3>
 * になる。アイコンの正体は Unicode の私用領域（U+E000〜U+F8FF）の1文字で、
 * 本文ではアイコンフォントで描かれる。
 *
 * re:Diver の目次（.rd-toc）は見出しの文字をサーバー側で拾って
 *   <a href="#toc-id-1-3">{U+EAAA}見出し</a>
 * のように出す。目次のリンクはアイコンフォントではないので、その1文字が □ になる。
 * 商標記事（LP型）の「選ばれる3つの理由」の箱で必ず起きる
 * （給湯器ドック・いいカギGET・靴下ラボの3本で確認）。
 *
 * ── 直し方
 * 表に出る HTML のうち、目次のリンク（href="#toc-id-…"）の中だけから
 * 私用領域の文字を外す。本文の見出しのアイコンはそのまま残る。
 * 記事本文（DB）には一切触らない。
 *
 * 止めたいときは functions.php 等で:
 *   add_filter('rdh_toc_icon_fix', '__return_false');
 */

if (!defined('ABSPATH')) exit;

/**
 * 目次のリンクの中から私用領域の文字を外す（テストから直接呼べるよう関数にしておく）。
 */
function rdh_strip_toc_icons($html) {
    if (!is_string($html) || $html === '') return $html;
    // 目次が無いページ・私用領域の文字が無いページは何もしない（大半のページはここで抜ける）
    if (strpos($html, 'toc-id-') === false) return $html;
    if (!preg_match('/[\x{E000}-\x{F8FF}]/u', $html)) return $html;

    $out = preg_replace_callback(
        '#(<a\b[^>]*\bhref=(["\'])\#toc-id-[^"\']*\2[^>]*>)(.*?)(</a>)#su',
        function ($m) {
            $inner = preg_replace('/[\x{E000}-\x{F8FF}]/u', '', $m[3]);
            if ($inner === null) return $m[0];
            // アイコンの直後に入っていた空白（&nbsp; を含む）を先頭から落とす
            $inner = preg_replace('/^(?:\s|&nbsp;|\x{00A0})+/u', '', $inner);
            if ($inner === null) return $m[0];
            return $m[1] . $inner . $m[4];
        },
        $html
    );
    // 正規表現が失敗したとき（壊れたUTF-8など）は元の HTML をそのまま返す
    return $out === null ? $html : $out;
}

add_action('template_redirect', function () {
    if (is_admin() || wp_doing_ajax() || is_feed()) return;
    if (defined('REST_REQUEST') && REST_REQUEST) return;
    if (!is_singular()) return;
    if (!apply_filters('rdh_toc_icon_fix', true)) return;
    ob_start('rdh_strip_toc_icons');
}, 1);
