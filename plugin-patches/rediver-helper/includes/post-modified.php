<?php
/**
 * 記事の更新日（post_modified / post_modified_gmt）だけを指定の日時に戻す。
 *
 * POST /rdh/v1/post-modified
 *   {"items":[{"id":123,"modified":"2026-10-07T09:00:00"}, ...], "dry_run":true}
 *
 * ── なぜ要るか（2026-11-27 給湯器ドック）
 * CTAの生成側を直したあと全記事向けの差し込みを流し、本文の中身が変わらない書き換え
 * （バナーの alt だけ）で公開268本中253本の更新日が同じ日にそろった。サイトマップの
 * lastmod が全記事でそろうと「サイト全面改訂」と見なされて急落した事例がある
 * （ke-ys.co.jp 2026-11-12・-89%）。WordPress の REST では更新日を指定できない
 * （保存すると必ず現在時刻になる）ので、ここで直接戻す。
 *
 * - wp_update_post を通さない。本文・タイトル・公開日には触らず、save_post も走らない
 *   （IndexNow の自動通知やオートインサーターの商品の取り直しも起きない）
 * - modified はサイトのタイムゾーンの日時（REST の modified と同じ形）。GMT は換算して入れる
 * - 未来の日時・公開日より前の日時は受け付けない（壊れた日付を入れないため）
 * - 一度に扱うのは RDH_BULK_MAX 件まで
 * - 戻すときの控えとして、書き換え前の値を before で返す
 */

if (!defined('ABSPATH')) exit;

function rdh_post_modified_set(WP_REST_Request $req) {
    global $wpdb;
    $items   = $req->get_param('items');
    $dry_run = rest_sanitize_boolean($req->get_param('dry_run'));

    if (!is_array($items) || !$items) {
        return new WP_Error('rdh_no_items', 'items に {id, modified} の配列を渡してください。', ['status' => 400]);
    }
    if (count($items) > RDH_BULK_MAX) {
        return new WP_Error('rdh_too_many', '一度に扱えるのは ' . RDH_BULK_MAX . ' 件までです。', ['status' => 400]);
    }

    $now_gmt = current_time('mysql', true);
    $done = [];
    $skipped = [];
    foreach ($items as $it) {
        $id  = isset($it['id']) ? (int) $it['id'] : 0;
        $raw = isset($it['modified']) ? (string) $it['modified'] : '';
        if ($id <= 0 || !preg_match('/^\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}:\d{2}$/', $raw)) {
            $skipped[] = ['id' => $id, 'reason' => 'id か modified の形が正しくない'];
            continue;
        }
        $post = get_post($id);
        if (!$post || !in_array($post->post_type, ['post', 'page'], true)) {
            $skipped[] = ['id' => $id, 'reason' => '投稿・固定ページが見つからない'];
            continue;
        }
        $local = str_replace('T', ' ', $raw);
        $gmt   = get_gmt_from_date($local);
        if ($gmt > $now_gmt) {
            $skipped[] = ['id' => $id, 'reason' => '未来の日時'];
            continue;
        }
        if ($post->post_date_gmt !== '0000-00-00 00:00:00' && $gmt < $post->post_date_gmt) {
            $skipped[] = ['id' => $id, 'reason' => '公開日より前の日時'];
            continue;
        }
        $row = [
            'id'     => $id,
            'slug'   => $post->post_name,
            'before' => $post->post_modified,
            'after'  => $local,
        ];
        if (!$dry_run) {
            $wpdb->update($wpdb->posts,
                ['post_modified' => $local, 'post_modified_gmt' => $gmt],
                ['ID' => $id], ['%s', '%s'], ['%d']);
            clean_post_cache($id);
        }
        $done[] = $row;
    }

    return [
        'ok'      => true,
        'dry_run' => $dry_run,
        'count'   => count($done),
        'skipped' => $skipped,
        'items'   => $done,
    ];
}

add_action('rest_api_init', function () {
    register_rest_route(RDH_NS, '/post-modified', [
        'methods'             => 'POST',
        'permission_callback' => 'rdh_permission',
        'callback'            => 'rdh_post_modified_set',
    ]);
});
