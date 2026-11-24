<?php
/**
 * 本文に直書きされた Amazon のトラッキングID（tag=xxx-22）を、別のIDへ置き換える。
 *
 * POST /rdh/v1/amazon-tag  {"from":"yoshimu0904-22","to":"good-diy-22","dry_run":true}
 *
 * - 対象は post / page / wp_block の本文（下書き・予約も含む。リビジョンは触らない）
 * - wp_update_post を通さず本文だけを書き換える。更新日（post_modified）は動かさない＝
 *   サイトマップの lastmod が全記事でそろうのを避ける。save_post も走らないので、
 *   オートインサーターが商品を取り直すこともない。
 * - 置き換えるのは「?tag= / &tag= / &amp;tag= / &#038;tag=」の直後にある ID だけ。
 * - 一度に扱うのは RDH_BULK_MAX 件まで。残りがあれば remaining を返すので繰り返し呼ぶ。
 */

if (!defined('ABSPATH')) exit;

function rdh_amazon_tag_valid($tag) {
    return is_string($tag) && preg_match('/^[A-Za-z0-9][A-Za-z0-9\-]{0,60}-2[0-9]$/', $tag);
}

function rdh_amazon_tag_replace(WP_REST_Request $req) {
    global $wpdb;
    $from    = (string) $req->get_param('from');
    $to      = (string) $req->get_param('to');
    $dry_run = rest_sanitize_boolean($req->get_param('dry_run'));

    if (!rdh_amazon_tag_valid($from) || !rdh_amazon_tag_valid($to)) {
        return new WP_Error('rdh_bad_tag', 'from / to はトラッキングIDの形（例 site-22）で指定してください。', ['status' => 400]);
    }
    if ($from === $to) {
        return new WP_Error('rdh_same_tag', 'from と to が同じです。', ['status' => 400]);
    }

    $like = '%' . $wpdb->esc_like('tag=' . $from) . '%';
    $rows = $wpdb->get_results($wpdb->prepare(
        "SELECT ID, post_type, post_status, post_content FROM {$wpdb->posts}
         WHERE post_type IN ('post','page','wp_block')
           AND post_status IN ('publish','future','draft','pending','private')
           AND post_content LIKE %s
         ORDER BY ID ASC LIMIT %d",
        $like, RDH_BULK_MAX + 1
    ));

    $remaining = 0;
    if (count($rows) > RDH_BULK_MAX) {
        array_pop($rows);
        $remaining = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->posts}
             WHERE post_type IN ('post','page','wp_block')
               AND post_status IN ('publish','future','draft','pending','private')
               AND post_content LIKE %s", $like)) - RDH_BULK_MAX;
    }

    $pattern = '/(?<=[?&;]tag=)' . preg_quote($from, '/') . '(?![A-Za-z0-9_\-])/';
    $changed = [];
    $links   = 0;
    foreach ($rows as $row) {
        $n = 0;
        $new = preg_replace($pattern, $to, $row->post_content, -1, $n);
        if ($new === null || $n === 0) {
            continue;
        }
        $links += $n;
        $changed[] = ['id' => (int) $row->ID, 'type' => $row->post_type, 'status' => $row->post_status, 'links' => $n];
        if (!$dry_run) {
            $wpdb->update($wpdb->posts, ['post_content' => $new], ['ID' => (int) $row->ID], ['%s'], ['%d']);
            clean_post_cache((int) $row->ID);
        }
    }

    return [
        'ok'        => true,
        'dry_run'   => $dry_run,
        'from'      => $from,
        'to'        => $to,
        'posts'     => count($changed),
        'links'     => $links,
        'remaining' => $dry_run ? $remaining : max(0, $remaining),
        'changed'   => $changed,
    ];
}

add_action('rest_api_init', function () {
    register_rest_route(RDH_NS, '/amazon-tag', [
        'methods'             => 'POST',
        'permission_callback' => 'rdh_permission',
        'callback'            => 'rdh_amazon_tag_replace',
    ]);
});
