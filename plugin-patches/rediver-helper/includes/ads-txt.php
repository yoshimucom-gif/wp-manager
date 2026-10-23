<?php
/**
 * ads.txt をオプションから配信する（1.5.0）
 *
 * ── 目的
 * サーバーにファイルを置かずに、REST から ads.txt を書けるようにする。
 * WordPress はドキュメントルートに無いパスを index.php に回すので、
 * /ads.txt へのリクエストを init で拾ってオプションの中身を返す。
 *
 * ── 書き方
 *   POST /wp-json/rdh/v1/option/rdh_ads_txt
 *   {"value":"google.com, pub-0000000000000000, DIRECT, f08c47fec0942fa0"}
 * 複数行は改行（\n）で区切る。空にすると配信を止め、従来どおり 404 になる。
 * 実ファイルの ads.txt がある場合はサーバーがそちらを返すので、このコードは動かない。
 *
 * 止めたいときは functions.php 等で:
 *   add_filter('rdh_ads_txt_serve', '__return_false');
 */

if (!defined('ABSPATH')) exit;

add_action('init', function () {
    if (!apply_filters('rdh_ads_txt_serve', true)) return;

    $path = (string) parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);
    $home = rtrim((string) parse_url(home_url('/'), PHP_URL_PATH), '/');
    if ($path !== $home . '/ads.txt') return;

    $body = trim((string) get_option('rdh_ads_txt', ''));
    if ($body === '') return;

    // 改行を LF にそろえ、行末の空白を落とす
    $lines = array_map('rtrim', preg_split('/\r\n|\r|\n/', $body));
    status_header(200);
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: public, max-age=3600');
    header('X-Robots-Tag: noindex');
    echo implode("\n", $lines) . "\n";
    exit;
}, 0);
