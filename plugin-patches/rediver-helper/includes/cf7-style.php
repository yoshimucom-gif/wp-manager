<?php
/**
 * Contact Form 7 のフォームを、サイトの配色に合わせた見た目にする（1.4.0）
 *
 * ── なぜ要るか（2026-10-15 吉村さん「CF7のデザインださい」）
 * re:Diver は CF7 の入力欄に何も当てないので、ブラウザ既定の細い枠・小さい文字・
 * 灰色の送信ボタンのまま出る。スマホでは文字が16px未満で、入力のたびに拡大される。
 *
 * ── 作り
 * 色はテーマの配色変数（--rd--c--accent など。カスタマイズ > カラーの値）を読むので、
 * サイトごとに色を書かない。配色を変えればフォームも追従する。
 * フォーム本文に <span class="rdh-req">必須</span> / <span class="rdh-opt">任意</span> を
 * 置くと、ラベルの横に小さな札で出る。
 *
 * ── 有効にする（サイトごと・既定はオフ。他サイトの既存フォームの見た目を変えないため）
 *   POST /wp-json/rdh/v1/option/rdh_cf7_style  {"value":"1"}
 * CF7 のフォームがあるページにだけ出す（wpcf7 のスタイルが読まれたときだけ）。
 */

if (!defined('ABSPATH')) exit;

add_action('wp_enqueue_scripts', function () {
    if (!rdh_flag_on(get_option('rdh_cf7_style', ''))) return;
    if (!wp_style_is('contact-form-7', 'enqueued') && !wp_style_is('contact-form-7', 'registered')) return;

    $a = 'rgb(var(--rd--c--accent,51 51 51))';
    $t = 'rgb(var(--rd--c--text,51 51 51))';
    $css = <<<CSS
.wpcf7{max-width:680px;margin:2em auto 0}
.wpcf7-form{background:#fff;border:1px solid rgb(0 0 0/.08);border-radius:16px;padding:32px 32px 28px;box-shadow:0 2px 12px rgb(0 0 0/.04)}
.wpcf7-form>p{margin:0 0 22px;line-height:1.8}
.wpcf7-form>p:first-of-type:not(:has(label)){font-size:.95em;color:{$t};opacity:.85;padding-bottom:20px;border-bottom:1px solid rgb(0 0 0/.08);margin-bottom:26px}
.wpcf7-form label{display:block;font-weight:700;font-size:.95em;color:{$t};line-height:1.5}
.wpcf7-form label br{display:none}
.wpcf7-form .wpcf7-form-control-wrap{display:block;margin-top:8px}
.rdh-req,.rdh-opt{display:inline-block;margin-left:8px;padding:2px 8px;border-radius:4px;font-size:11px;font-weight:700;line-height:1.5;vertical-align:2px;letter-spacing:.04em}
.rdh-req{background:{$a};color:#fff}
.rdh-opt{background:rgb(0 0 0/.06);color:rgb(0 0 0/.55)}
.wpcf7-form input[type=text],.wpcf7-form input[type=email],.wpcf7-form input[type=tel],.wpcf7-form input[type=url],.wpcf7-form input[type=number],.wpcf7-form select,.wpcf7-form textarea{
 width:100%;box-sizing:border-box;padding:13px 16px;font-size:16px;font-weight:400;line-height:1.6;color:{$t};
 background:#fafafa;border:1px solid rgb(0 0 0/.16);border-radius:10px;box-shadow:none;transition:border-color .15s,box-shadow .15s,background .15s;-webkit-appearance:none;appearance:none}
.wpcf7-form textarea{min-height:200px;resize:vertical}
.wpcf7-form select{padding-right:44px;background-image:linear-gradient(45deg,transparent 50%,currentColor 50%),linear-gradient(135deg,currentColor 50%,transparent 50%);background-position:calc(100% - 22px) 52%,calc(100% - 16px) 52%;background-size:6px 6px;background-repeat:no-repeat}
.wpcf7-form input::placeholder,.wpcf7-form textarea::placeholder{color:rgb(0 0 0/.35)}
.wpcf7-form input:focus,.wpcf7-form select:focus,.wpcf7-form textarea:focus{outline:none;background:#fff;border-color:{$a};box-shadow:0 0 0 3px rgb(var(--rd--c--accent,51 51 51)/.15)}
.wpcf7-form .wpcf7-not-valid{border-color:#d93025;background:#fff8f7}
.wpcf7-not-valid-tip{margin-top:6px;font-size:13px;font-weight:700;color:#d93025}
.wpcf7-form .wpcf7-acceptance .wpcf7-list-item{margin:0}
.wpcf7-form .wpcf7-acceptance label{display:flex;gap:10px;align-items:flex-start;font-weight:400}
.wpcf7-form input[type=checkbox]{width:20px;height:20px;margin:2px 0 0;flex:none;accent-color:{$a}}
.wpcf7-form a{color:{$a};text-decoration:underline;text-underline-offset:3px}
.wpcf7-form>p:has(.wpcf7-submit){margin:30px 0 0;text-align:center;position:relative}
.wpcf7-form .wpcf7-submit{display:inline-block;min-width:260px;max-width:100%;padding:16px 40px;font-size:16px;font-weight:700;letter-spacing:.08em;color:#fff;background:{$a};border:0;border-radius:999px;cursor:pointer;box-shadow:0 4px 14px rgb(var(--rd--c--accent,51 51 51)/.28);transition:transform .15s,box-shadow .15s,opacity .15s;-webkit-appearance:none;appearance:none}
.wpcf7-form .wpcf7-submit:hover{transform:translateY(-1px);box-shadow:0 6px 18px rgb(var(--rd--c--accent,51 51 51)/.34)}
.wpcf7-form .wpcf7-submit:disabled{opacity:.5;cursor:not-allowed;transform:none}
.wpcf7-form .wpcf7-spinner{position:absolute;top:50%;margin:-12px 0 0 12px}
.wpcf7 form .wpcf7-response-output{margin:24px 0 0;padding:14px 18px;border-radius:10px;border-width:1px;font-size:14px;line-height:1.7}
.wpcf7 form.sent .wpcf7-response-output{border-color:#1e8e3e;background:#f1f8f3;color:#1e6b34}
.wpcf7 form.invalid .wpcf7-response-output,.wpcf7 form.unaccepted .wpcf7-response-output,.wpcf7 form.payment-required .wpcf7-response-output{border-color:#e8a200;background:#fff9e8;color:#7a5600}
.wpcf7 form.failed .wpcf7-response-output,.wpcf7 form.aborted .wpcf7-response-output,.wpcf7 form.spam .wpcf7-response-output{border-color:#d93025;background:#fff8f7;color:#a52714}
@media (max-width:600px){
 .wpcf7-form{padding:22px 18px 20px;border-radius:12px}
 .wpcf7-form .wpcf7-submit{width:100%;min-width:0}
}
CSS;
    wp_add_inline_style('contact-form-7', $css);
}, 30);
