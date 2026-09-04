<?php
/**
 * タームメタ（カテゴリー画像・タイトルレイアウト等）の REST 経路
 *
 * テーマがカテゴリー編集画面に足した項目は termmeta に入るが、
 * register_term_meta が show_in_rest 付きで呼ばれていないため標準RESTから見えない。
 * 標準RESTに meta を渡しても「200が返るのにDBは変わらない」（未登録キーは黙殺される）。
 */

if (!defined('ABSPATH')) exit;

function rdh_termmeta_one(WP_REST_Request $req) {
    $term_id = (int) $req['id'];
    $term = get_term($term_id);
    if (!$term || is_wp_error($term)) {
        return new WP_Error('rdh_no_term', 'ターム が見つかりません。', ['status' => 404]);
    }
    return [
        'term_id'  => $term_id,
        'taxonomy' => $term->taxonomy,
        'slug'     => $term->slug,
        'name'     => $term->name,
        'meta'     => rdh_decorate_meta(get_term_meta($term_id)),
    ];
}

function rdh_termmeta_list(WP_REST_Request $req) {
    $taxonomy = $req->get_param('taxonomy') ?: 'category';
    $ow = $req->get_param('only_with_meta');
    $only_with_meta = ($ow === null) ? true : rest_sanitize_boolean($ow);

    $terms = get_terms(['taxonomy' => $taxonomy, 'hide_empty' => false]);
    if (is_wp_error($terms)) {
        return $terms;
    }
    $rows = [];
    foreach ($terms as $term) {
        $raw = get_term_meta($term->term_id);
        if ($only_with_meta && empty($raw)) {
            continue;
        }
        $rows[] = [
            'term_id'  => (int) $term->term_id,
            'taxonomy' => $term->taxonomy,
            'slug'     => $term->slug,
            'name'     => $term->name,
            'parent'   => (int) $term->parent,
            'meta'     => rdh_decorate_meta($raw),
        ];
    }
    return ['taxonomy' => $taxonomy, 'count' => count($rows), 'terms' => $rows];
}

function rdh_termmeta_write(WP_REST_Request $req) {
    $term_id = (int) $req['id'];
    $term = get_term($term_id);
    if (!$term || is_wp_error($term)) {
        return new WP_Error('rdh_no_term', 'ターム が見つかりません。', ['status' => 404]);
    }
    $key = (string) $req->get_param('key');
    if ($key === '') {
        return new WP_Error('rdh_no_key', 'key は必須です。', ['status' => 400]);
    }
    if (!rdh_key_allowed($key, 'term')) {
        return new WP_Error('rdh_key_denied', 'このキーは書き込み禁止です: ' . $key, ['status' => 403]);
    }

    $before = get_term_meta($term_id, $key, true);
    $value  = rdh_clean($req->get_param('value'), $req);

    if (rest_sanitize_boolean($req->get_param('dry_run'))) {
        return ['term_id' => $term_id, 'key' => $key, 'before' => $before,
                'would_be' => $value, 'would_delete' => rdh_is_delete_value($value, $req),
                'dry_run' => true];
    }

    $backup_id = rdh_backup_push('term', $term_id, $key, $before);
    if (rdh_is_delete_value($value, $req)) {
        delete_term_meta($term_id, $key);
        $after = '';
    } else {
        update_term_meta($term_id, $key, rdh_slash_for_meta($value));
        $after = get_term_meta($term_id, $key, true);
    }
    return ['term_id' => $term_id, 'key' => $key, 'before' => $before, 'after' => $after,
            'changed' => ($before !== $after), 'backup_id' => $backup_id];
}

function rdh_termmeta_delete(WP_REST_Request $req) {
    $term_id = (int) $req['id'];
    $term = get_term($term_id);
    if (!$term || is_wp_error($term)) {
        return new WP_Error('rdh_no_term', 'ターム が見つかりません。', ['status' => 404]);
    }
    $key = (string) $req->get_param('key');
    if ($key === '') {
        return new WP_Error('rdh_no_key', 'key は必須です。', ['status' => 400]);
    }
    if (!rdh_key_allowed($key, 'term')) {
        return new WP_Error('rdh_key_denied', 'このキーは削除禁止です: ' . $key, ['status' => 403]);
    }
    $before = get_term_meta($term_id, $key, true);

    if (rest_sanitize_boolean($req->get_param('dry_run'))) {
        return ['term_id' => $term_id, 'key' => $key, 'before' => $before,
                'would_delete' => true, 'dry_run' => true];
    }

    // 削除も戻せるように退避する
    $backup_id = rdh_backup_push('term', $term_id, $key, $before);
    delete_term_meta($term_id, $key);
    return ['term_id' => $term_id, 'key' => $key, 'before' => $before,
            'deleted' => true, 'backup_id' => $backup_id];
}

add_action('rest_api_init', function () {
    $ns = RDH_NS;

    register_rest_route($ns, '/termmeta', [
        'methods'             => 'GET',
        'callback'            => 'rdh_termmeta_list',
        'permission_callback' => 'rdh_permission',
        'args'                => [
            'taxonomy'       => ['type' => 'string', 'default' => 'category'],
            'only_with_meta' => ['type' => 'boolean', 'default' => true],
        ],
    ]);

    register_rest_route($ns, '/termmeta/(?P<id>\d+)', [
        ['methods' => 'GET',    'callback' => 'rdh_termmeta_one',
         'permission_callback' => 'rdh_permission'],
        ['methods' => 'POST',   'callback' => 'rdh_termmeta_write',
         'permission_callback' => 'rdh_permission',
         'args' => ['key' => ['type' => 'string', 'required' => true], 'value' => [],
                    'allow_empty' => ['type' => 'boolean', 'default' => false],
                    'dry_run' => ['type' => 'boolean', 'default' => false]]],
        ['methods' => 'DELETE', 'callback' => 'rdh_termmeta_delete',
         'permission_callback' => 'rdh_permission',
         'args' => ['key' => ['type' => 'string', 'required' => true],
                    'dry_run' => ['type' => 'boolean', 'default' => false]]],
    ]);
});

/**
 * 確定したテーマ側キーを標準RESTにも出したいとき用。
 * add_filter('rdh_term_rest_keys', fn($k) => $k + ['diver_category_image' => 'string']);
 */
add_action('init', function () {
    foreach ((array) apply_filters('rdh_term_rest_keys', []) as $key => $type) {
        register_term_meta('category', $key, [
            'type'          => is_string($type) ? $type : 'string',
            'single'        => true,
            'show_in_rest'  => true,
            'auth_callback' => function () { return current_user_can('manage_categories'); },
        ]);
    }
}, 20);
