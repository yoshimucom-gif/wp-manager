<?php
/**
 * テーマ設定（カスタマイザー／テーマオプション）の REST 経路
 *
 * re:Diver のカスタマイザー項目は `diver_color[mode]` `diver_firstview[header][over]`
 * のような **1本のシリアライズ配列オプション**に束ねて保存される
 * （diver_color / diver_firstview / diver_global_style / diver_header_items /
 *   rd_background / rd_footer / single_navigation ほか）。
 *
 * このため:
 *   - 標準RESTには一切出ない
 *   - Search Regex はシリアライズ配列を書き換えられない（スカラーは可）
 *   - 丸ごと上書きすると触っていない項目まで消える＝テーマが壊れる
 *
 * ここでは get_option / update_option を素直に使い、
 *   merge=true  … 指定した葉だけ差し替える（既定の壊さないモード）
 *   dry_run=true… 書かずに差分だけ返す
 *   force=true  … 既存キーが消えるのを承知で丸ごと上書きする
 *   自動退避     … 変更前の値を保存し、いつでも復元できる
 * を用意する。
 */

if (!defined('ABSPATH')) exit;

/* ---------------- theme_mod ---------------- */

function rdh_thememods_list(WP_REST_Request $req) {
    $mods = get_theme_mods();
    return [
        'stylesheet' => get_stylesheet(),
        'template'   => get_template(),
        'count'      => is_array($mods) ? count($mods) : 0,
        'mods'       => is_array($mods) ? $mods : [],
    ];
}

function rdh_thememods_write(WP_REST_Request $req) {
    $key = (string) $req->get_param('key');
    if ($key === '') {
        return new WP_Error('rdh_no_key', 'key は必須です。', ['status' => 400]);
    }
    // theme_mod もキーのガードを通す（rdh_key_allowed フィルタで絞れるようにするため）
    if (!rdh_key_allowed($key, 'thememod')) {
        return new WP_Error('rdh_key_denied', 'このキーは書き込み禁止です: ' . $key, ['status' => 403]);
    }
    $dry    = rest_sanitize_boolean($req->get_param('dry_run'));
    $before = get_theme_mod($key, null);
    // set_theme_mod は内部で update_option。スラッシュを外さないので付け直しは不要。
    $value  = rdh_clean($req->get_param('value'), $req);
    $delete = rdh_is_delete_value($value, $req);

    if ($dry) {
        return ['key' => $key, 'before' => $before, 'would_be' => $value,
                'would_delete' => $delete, 'dry_run' => true];
    }

    $backup_id = rdh_backup_push('thememod', get_stylesheet(), $key, $before);
    if ($delete) {
        remove_theme_mod($key);
    } else {
        set_theme_mod($key, $value);
    }
    $after = get_theme_mod($key, null);
    return ['key' => $key, 'before' => $before, 'after' => $after,
            'changed' => ($before !== $after), 'backup_id' => $backup_id];
}

/* ---------------- option ---------------- */

/** テーマ設定のキーを探す（値は返さず名前と長さだけ＝取り違え防止と情報漏れ防止） */
function rdh_options_search(WP_REST_Request $req) {
    global $wpdb;
    $search = (string) ($req->get_param('search') ?: '');
    $limit  = min(max((int) ($req->get_param('limit') ?: 100), 1), 500);
    if ($search === '') {
        return new WP_Error('rdh_no_search',
            'search は必須です（例: diver / rd_ / rediver / dbp）。', ['status' => 400]);
    }
    $like = '%' . $wpdb->esc_like($search) . '%';
    $rows = $wpdb->get_results($wpdb->prepare(
        "SELECT option_name, LENGTH(option_value) AS len, autoload
           FROM {$wpdb->options}
          WHERE option_name LIKE %s
          ORDER BY option_name ASC
          LIMIT %d", $like, $limit), ARRAY_A);
    foreach ($rows as &$r) {
        $r['len'] = (int) $r['len'];
        $v = get_option($r['option_name'], null);
        $r['type'] = gettype($v);
        if (is_array($v)) {
            $r['top_keys'] = array_slice(array_keys($v), 0, 12);
        }
        $r['writable'] = rdh_key_allowed($r['option_name'], 'option');
    }
    unset($r);
    return ['search' => $search, 'count' => count($rows), 'options' => $rows];
}

function rdh_option_get(WP_REST_Request $req) {
    $name  = (string) $req['name'];
    $value = get_option($name, null);
    if ($value === null) {
        return new WP_Error('rdh_no_option', 'オプションが存在しません: ' . $name, ['status' => 404]);
    }
    return ['name' => $name, 'type' => gettype($value), 'value' => $value,
            'writable' => rdh_key_allowed($name, 'option')];
}

function rdh_option_set(WP_REST_Request $req) {
    $name = (string) $req['name'];
    if (!rdh_key_allowed($name, 'option')) {
        return new WP_Error('rdh_key_denied',
            'このオプションはサイトが壊れるため書き込み禁止です: ' . $name, ['status' => 403]);
    }
    $before = get_option($name, null);
    // update_option は内部でスラッシュを外さないので、付け直しはしない
    $value  = rdh_clean($req->get_param('value'), $req);
    $merge  = rest_sanitize_boolean($req->get_param('merge'));
    $force  = rest_sanitize_boolean($req->get_param('force'));
    $dry    = rest_sanitize_boolean($req->get_param('dry_run'));

    // 既存がシリアライズ配列のときの保護。
    // 配列 → スカラー の置き換えは中身が全部消えるので、これも止める。
    if (is_array($before) && !$merge && !$force) {
        if (!is_array($value)) {
            return new WP_Error('rdh_would_replace_array',
                '既存は配列（' . count($before) . '項目）ですが、配列でない値で上書きしようとしています。'
                . '中身が全部消えます。1項目だけ変えるなら merge=true、'
                . '本当に置き換えるなら force=true を付けてください。',
                ['status' => 409, 'before_keys' => array_slice(array_keys($before), 0, 20)]);
        }
        $lost = array_diff(array_keys($before), array_keys($value));
        if (!empty($lost)) {
            return new WP_Error('rdh_would_lose_keys',
                '上書きすると既存キーが消えます: ' . implode(', ', array_slice($lost, 0, 20)) .
                ' … 消したくない場合は merge=true を付けてください（消すつもりなら force=true）。',
                ['status' => 409, 'lost_keys' => array_values($lost)]);
        }
    }
    if ($merge && is_array($before)) {
        if (!is_array($value)) {
            return new WP_Error('rdh_merge_needs_array',
                'merge=true のときは value に配列（オブジェクト）を渡してください。', ['status' => 400]);
        }
        $value = rdh_merge_deep($before, $value);
    }

    if ($dry) {
        return ['name' => $name, 'before' => $before, 'would_be' => $value,
                'merge' => (bool) $merge, 'force' => (bool) $force, 'dry_run' => true];
    }

    $backup_id = rdh_backup_push('option', $name, $name, $before);
    update_option($name, $value);
    $after = get_option($name, null);

    return [
        'name'      => $name,
        'before'    => $before,
        'after'     => $after,
        'changed'   => ($before !== $after),
        'merge'     => (bool) $merge,
        'force'     => (bool) $force,
        'backup_id' => $backup_id,
        'note'      => ($before !== $after) ? null
            : 'DBが変わっていない。update_option のサニタイズに弾かれたか、同値の可能性がある。',
    ];
}

/* ---------------- 退避と復元 ---------------- */

function rdh_backups_get(WP_REST_Request $req) {
    // id を指定したときは中身（戻る値）まで返す＝復元前に何に戻るか確認できる
    $id = (string) ($req->get_param('id') ?: '');
    if ($id !== '') {
        $row = rdh_backup_find($id);
        if (!$row) {
            return new WP_Error('rdh_no_backup', 'その退避IDは見つかりません: ' . $id, ['status' => 404]);
        }
        if (($row['type'] ?? '') === 'post_bulk') {
            $row['before_count'] = count((array) $row['before']);
        }
        return $row;
    }

    $rows = rdh_backup_list();
    $out = [];
    foreach ($rows as $r) {
        $entry = [
            'id' => $r['id'], 'at' => $r['at'], 'type' => $r['type'],
            'target' => $r['target'], 'key' => $r['key'],
            'before_type' => gettype($r['before']),
        ];
        if (($r['type'] ?? '') === 'post_bulk') {
            $entry['before_count'] = count((array) $r['before']);
        }
        $out[] = $entry;
    }
    return ['count' => count($out), 'backups' => $out,
            'hint' => 'GET /backups?id=<backup_id> で戻る値そのものを確認できる。'];
}

function rdh_backups_restore(WP_REST_Request $req) {
    $id = (string) $req->get_param('id');
    if ($id === '') {
        return new WP_Error('rdh_no_id', 'id は必須です。', ['status' => 400]);
    }
    return rdh_backup_restore($id);
}

add_action('rest_api_init', function () {
    $ns = RDH_NS;

    register_rest_route($ns, '/thememods', [
        ['methods' => 'GET',  'callback' => 'rdh_thememods_list',
         'permission_callback' => 'rdh_permission'],
        ['methods' => 'POST', 'callback' => 'rdh_thememods_write',
         'permission_callback' => 'rdh_permission',
         'args' => ['key' => ['type' => 'string', 'required' => true],
                    'value' => [],
                    'allow_empty' => ['type' => 'boolean', 'default' => false],
                    'dry_run' => ['type' => 'boolean', 'default' => false]]],
    ]);

    register_rest_route($ns, '/options', [
        'methods'             => 'GET',
        'callback'            => 'rdh_options_search',
        'permission_callback' => 'rdh_permission',
        'args'                => ['search' => ['type' => 'string', 'required' => true],
                                  'limit'  => ['type' => 'integer', 'default' => 100]],
    ]);

    // オプション名には . や : を含むものがあるため、英数字と _ - だけでは足りない
    register_rest_route($ns, '/option/(?P<name>[A-Za-z0-9_.:\-]+)', [
        ['methods' => 'GET',  'callback' => 'rdh_option_get',
         'permission_callback' => 'rdh_permission'],
        ['methods' => 'POST', 'callback' => 'rdh_option_set',
         'permission_callback' => 'rdh_permission',
         'args' => ['value' => [],
                    'merge'   => ['type' => 'boolean', 'default' => false],
                    'force'   => ['type' => 'boolean', 'default' => false],
                    'dry_run' => ['type' => 'boolean', 'default' => false]]],
    ]);

    register_rest_route($ns, '/backups', [
        ['methods' => 'GET',  'callback' => 'rdh_backups_get',
         'permission_callback' => 'rdh_permission',
         'args' => ['id' => ['type' => 'string']]],
        ['methods' => 'POST', 'callback' => 'rdh_backups_restore',
         'permission_callback' => 'rdh_permission',
         'args' => ['id' => ['type' => 'string', 'required' => true]]],
    ]);
});
