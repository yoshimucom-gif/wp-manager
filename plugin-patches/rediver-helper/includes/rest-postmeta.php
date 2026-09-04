<?php
/**
 * 投稿メタ（記事幅・サイドバー有無・目次設定など、テーマが投稿編集画面に足した項目）の REST 経路
 *
 * テーマの投稿設定は `_diver_...` のような **_ 始まりの非公開メタ**に入ることが多く、
 * register_post_meta が show_in_rest 付きで呼ばれていないため標準RESTからは見えない。
 * ここでは _ 始まりも読み書きできるようにする（壊れると困るWP内部キーだけ拒否）。
 */

if (!defined('ABSPATH')) exit;

function rdh_postmeta_one(WP_REST_Request $req) {
    $post_id = (int) $req['id'];
    $post = get_post($post_id);
    if (!$post) {
        return new WP_Error('rdh_no_post', '投稿が見つかりません。', ['status' => 404]);
    }
    return [
        'post_id'   => $post_id,
        'post_type' => $post->post_type,
        'slug'      => $post->post_name,
        'title'     => get_the_title($post_id),
        'meta'      => rdh_decorate_meta(get_post_meta($post_id)),
    ];
}

/**
 * 投稿タイプ横断で「どのメタキーが使われているか」を数える。
 * テーマ項目のキー名を発見するための調査用。
 */
function rdh_postmeta_keys(WP_REST_Request $req) {
    $post_type = $req->get_param('post_type') ?: 'post';
    $limit     = min(max((int) ($req->get_param('limit') ?: 20), 1), 100);

    $ids = get_posts([
        'post_type'      => $post_type,
        'post_status'    => 'any',
        'posts_per_page' => $limit,
        'fields'         => 'ids',
        'orderby'        => 'ID',
        'order'          => 'DESC',
    ]);

    $keys = [];
    $samples = [];
    foreach ($ids as $id) {
        foreach (get_post_meta($id) as $key => $values) {
            if (!isset($keys[$key])) {
                $keys[$key] = 0;
                $samples[$key] = ['post_id' => (int) $id, 'value' => (array) $values];
            }
            $keys[$key]++;
        }
    }
    arsort($keys);
    $rows = [];
    foreach ($keys as $key => $count) {
        $rows[] = ['key' => $key, 'used_in' => $count, 'sample' => $samples[$key]];
    }
    return ['post_type' => $post_type, 'scanned' => count($ids), 'keys' => $rows];
}

function rdh_postmeta_write(WP_REST_Request $req) {
    $post_id = (int) $req['id'];
    if (!get_post($post_id)) {
        return new WP_Error('rdh_no_post', '投稿が見つかりません。', ['status' => 404]);
    }
    $key = (string) $req->get_param('key');
    if ($key === '') {
        return new WP_Error('rdh_no_key', 'key は必須です。', ['status' => 400]);
    }
    if (!rdh_key_allowed($key, 'post')) {
        return new WP_Error('rdh_key_denied', 'このキーは書き込み禁止です: ' . $key, ['status' => 403]);
    }

    $before = get_post_meta($post_id, $key, true);
    $value  = rdh_clean($req->get_param('value'), $req);

    if (rest_sanitize_boolean($req->get_param('dry_run'))) {
        return ['post_id' => $post_id, 'key' => $key, 'before' => $before,
                'would_be' => $value, 'would_delete' => rdh_is_delete_value($value, $req),
                'dry_run' => true];
    }

    $backup_id = rdh_backup_push('post', $post_id, $key, $before);
    if (rdh_is_delete_value($value, $req)) {
        delete_post_meta($post_id, $key);
        $after = '';
    } else {
        update_post_meta($post_id, $key, rdh_slash_for_meta($value));
        $after = get_post_meta($post_id, $key, true);
    }
    return ['post_id' => $post_id, 'key' => $key, 'before' => $before, 'after' => $after,
            'changed' => ($before !== $after), 'backup_id' => $backup_id];
}

/**
 * 同じキーを複数投稿へ一括適用（記事幅を全記事に揃える等）。
 *
 * 一括は影響が大きいので:
 *   - 変更前の値を **1件の退避にまとめて** 保存する（post_bulk）。
 *     1投稿1件で積むと RDH_BACKUP_MAX を即座に溢れさせ、戻せなくなるため。
 *   - 件数を RDH_BULK_MAX で頭打ちにする。
 */
function rdh_postmeta_bulk(WP_REST_Request $req) {
    $key = (string) $req->get_param('key');
    if ($key === '') {
        return new WP_Error('rdh_no_key', 'key は必須です。', ['status' => 400]);
    }
    if (!rdh_key_allowed($key, 'post')) {
        return new WP_Error('rdh_key_denied', 'このキーは書き込み禁止です: ' . $key, ['status' => 403]);
    }
    $ids   = (array) $req->get_param('post_ids');
    $value = rdh_clean($req->get_param('value'), $req);
    if (empty($ids)) {
        return new WP_Error('rdh_no_ids', 'post_ids は必須です。', ['status' => 400]);
    }
    if (count($ids) > RDH_BULK_MAX) {
        return new WP_Error('rdh_too_many',
            '一度に扱えるのは ' . RDH_BULK_MAX . ' 件までです（指定: ' . count($ids) . ' 件）。分けて実行してください。',
            ['status' => 400]);
    }

    $delete = rdh_is_delete_value($value, $req);

    if (rest_sanitize_boolean($req->get_param('dry_run'))) {
        $preview = [];
        foreach ($ids as $raw_id) {
            $id = (int) $raw_id;
            $preview[] = ['post_id' => $id, 'before' => get_post_meta($id, $key, true),
                          'would_be' => $value];
        }
        return ['key' => $key, 'total' => count($ids), 'dry_run' => true,
                'would_delete' => $delete, 'results' => $preview];
    }

    // 先に全件の変更前の値を集めて、まとめて1件退避する
    $snapshot = [];
    foreach ($ids as $raw_id) {
        $id = (int) $raw_id;
        if (!get_post($id)) {
            continue;
        }
        $snapshot[$id] = get_post_meta($id, $key, true);
    }
    $backup_id = rdh_backup_push('post_bulk', 'post_ids:' . count($snapshot), $key, $snapshot);

    $changed = 0;
    $results = [];
    foreach ($ids as $raw_id) {
        $id = (int) $raw_id;
        if (!get_post($id)) {
            $results[] = ['post_id' => $id, 'error' => 'not_found'];
            continue;
        }
        $before = get_post_meta($id, $key, true);
        if ($delete) {
            delete_post_meta($id, $key);
            $after = '';
        } else {
            update_post_meta($id, $key, rdh_slash_for_meta($value));
            $after = get_post_meta($id, $key, true);
        }
        if ($before !== $after) {
            $changed++;
        }
        $results[] = ['post_id' => $id, 'before' => $before, 'after' => $after,
                      'changed' => ($before !== $after)];
    }
    return ['key' => $key, 'total' => count($ids), 'changed' => $changed,
            'backup_id' => $backup_id, 'results' => $results];
}

add_action('rest_api_init', function () {
    $ns = RDH_NS;

    register_rest_route($ns, '/postmeta-keys', [
        'methods'             => 'GET',
        'callback'            => 'rdh_postmeta_keys',
        'permission_callback' => 'rdh_permission',
        'args'                => [
            'post_type' => ['type' => 'string', 'default' => 'post'],
            'limit'     => ['type' => 'integer', 'default' => 20],
        ],
    ]);

    register_rest_route($ns, '/postmeta/bulk', [
        'methods'             => 'POST',
        'callback'            => 'rdh_postmeta_bulk',
        'permission_callback' => 'rdh_permission',
        'args'                => [
            'key'         => ['type' => 'string', 'required' => true],
            'post_ids'    => ['type' => 'array', 'required' => true],
            'value'       => [],
            'allow_empty' => ['type' => 'boolean', 'default' => false],
            'dry_run'     => ['type' => 'boolean', 'default' => false],
        ],
    ]);

    register_rest_route($ns, '/postmeta/(?P<id>\d+)', [
        ['methods' => 'GET',  'callback' => 'rdh_postmeta_one',
         'permission_callback' => 'rdh_permission'],
        ['methods' => 'POST', 'callback' => 'rdh_postmeta_write',
         'permission_callback' => 'rdh_permission',
         'args' => ['key' => ['type' => 'string', 'required' => true], 'value' => [],
                    'allow_empty' => ['type' => 'boolean', 'default' => false],
                    'dry_run' => ['type' => 'boolean', 'default' => false]]],
    ]);
});
