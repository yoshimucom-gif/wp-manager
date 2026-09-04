<?php
/**
 * 共通ヘルパー（権限判定・スラッシュ正規化・値の装飾・キーのガード・バックアップ・部分マージ）
 */

if (!defined('ABSPATH')) exit;

if (!defined('RDH_NS')) {
    define('RDH_NS', 'rdh/v1');
}
/** 変更前の値を退避しておくオプション名（復元用） */
if (!defined('RDH_BACKUP_OPTION')) {
    define('RDH_BACKUP_OPTION', 'rdh_backups');
}
/** 退避を残す件数 */
if (!defined('RDH_BACKUP_MAX')) {
    define('RDH_BACKUP_MAX', 50);
}
/** 一括更新で一度に扱える投稿数の上限 */
if (!defined('RDH_BULK_MAX')) {
    define('RDH_BULK_MAX', 500);
}
/** 1リクエストで添付URLを引く回数の上限（メタ一覧の問い合わせ爆発を止める） */
if (!defined('RDH_DECORATE_MAX')) {
    define('RDH_DECORATE_MAX', 300);
}

/** 管理者相当のみ許可 */
function rdh_permission() {
    if (current_user_can('manage_options')) {
        return true;
    }
    return new WP_Error('rdh_forbidden', '管理者権限が必要です。', ['status' => 403]);
}

/**
 * 添付IDらしき値なら画像URLを添える（画像系メタの確認が1往復で済むように）。
 *
 * 数字だけのメタは画像以外にも大量にあるため、
 *   - 同じIDは1回しか引かない（メモ化）
 *   - 1リクエストの問い合わせ回数を RDH_DECORATE_MAX で打ち止める
 * ことで、タームメタ一覧や投稿メタ全件取得での問い合わせ爆発を防ぐ。
 */
function rdh_decorate($value) {
    $out = ['value' => $value];
    if (!is_string($value) || !ctype_digit($value) || (int) $value <= 0) {
        return $out;
    }
    $id = (int) $value;

    static $memo    = [];
    static $lookups = 0;

    if (!array_key_exists($id, $memo)) {
        if ($lookups >= RDH_DECORATE_MAX) {
            $out['attachment_lookup'] = 'skipped';
            return $out;
        }
        $lookups++;
        $memo[$id] = wp_get_attachment_url($id);
    }
    if ($memo[$id]) {
        $out['attachment_id']  = $id;
        $out['attachment_url'] = $memo[$id];
    }
    return $out;
}

/** メタ配列を装飾して返す */
function rdh_decorate_meta($raw) {
    $meta = [];
    foreach ((array) $raw as $key => $values) {
        $meta[$key] = array_map('rdh_decorate', (array) $values);
    }
    return $meta;
}

/**
 * 書き換えるとサイトが壊れる／権限昇格につながるキーは拒否する。
 * テーマの設定は `_diver_...` のように _ 始まりが普通なので、_ 自体は許可する。
 * サイト側で更に絞りたいときは rdh_key_allowed フィルタで上書きする。
 *
 * $context は post / term / option / thememod。
 */
function rdh_key_allowed($key, $context) {
    global $wpdb;

    $deny = [
        'post'     => ['_edit_lock', '_edit_last', '_wp_trash_meta_status', '_wp_trash_meta_time'],
        'term'     => [],
        'thememod' => [],
        'option'   => [
            // サイトが起動しなくなる／権限昇格につながるもの
            'siteurl', 'home', 'template', 'stylesheet', 'active_plugins', 'admin_email',
            'users_can_register', 'default_role', 'wp_user_roles', 'db_version',
            'cron', 'rewrite_rules', 'recently_activated', 'uninstall_plugins',
        ],
    ];

    // 役割定義のオプション名はテーブル接頭辞で変わる。
    // 接頭辞を変えたインストールでは wp_user_roles ではないので、実物を足す。
    if (isset($wpdb) && is_object($wpdb)) {
        if (!empty($wpdb->prefix)) {
            $deny['option'][] = $wpdb->prefix . 'user_roles';
        }
        if (!empty($wpdb->base_prefix)) {
            $deny['option'][] = $wpdb->base_prefix . 'user_roles';
        }
    }

    $allowed = !in_array($key, $deny[$context] ?? [], true);

    // 内部用の接頭辞も拒否（_transient / _site_transient など）
    if ($context === 'option' && preg_match('/^_(site_)?transient_/', $key)) {
        $allowed = false;
    }
    return (bool) apply_filters('rdh_key_allowed', $allowed, $key, $context);
}

/**
 * 受け取った値のスラッシュを、送信経路に合わせて正規化する。
 *
 * WP の REST は **JSONボディのパラメータにスラッシュを付けない**。
 * スラッシュが付くのは application/x-www-form-urlencoded / multipart/form-data
 * （$_POST 由来。wp_magic_quotes が付ける）のときだけ。
 *
 * 経路を見ずに wp_unslash すると、JSONで送ったバックスラッシュが1段消える。
 * さらに update_post_meta / update_term_meta は内部でもう1段外すため、
 * 何もしないと `C:\path` が `C:path` に、CSSの `"\e89e"` が `"e89e"` になる。
 * 書き込み側は rdh_slash_for_meta() で付け直すこと。
 */
function rdh_clean($value, $req = null) {
    if ($req instanceof WP_REST_Request && rdh_request_is_slashed($req)) {
        return rdh_unslash_deep($value);
    }
    return $value;
}

/** このリクエストのパラメータがスラッシュ済みか（$_POST 由来か）を判定する */
function rdh_request_is_slashed(WP_REST_Request $req) {
    $ct = $req->get_content_type();
    if (!is_array($ct) || empty($ct['value'])) {
        return false;
    }
    $type = strtolower($ct['value']);
    return (strpos($type, 'x-www-form-urlencoded') !== false
         || strpos($type, 'multipart/form-data') !== false);
}

/** 配列も含めてスラッシュを外す */
function rdh_unslash_deep($value) {
    if (is_string($value)) {
        return wp_unslash($value);
    }
    if (is_array($value)) {
        return array_map('rdh_unslash_deep', $value);
    }
    return $value;
}

/**
 * 値が「消す指示」かどうか。
 *   null         → 消す
 *   ''（空文字） → 消す。空文字をそのまま保存したいときは allow_empty=true を付ける
 */
function rdh_is_delete_value($value, WP_REST_Request $req) {
    if ($value === null) {
        return true;
    }
    if ($value !== '') {
        return false;
    }
    return !rest_sanitize_boolean($req->get_param('allow_empty'));
}

/**
 * メタ書き込み用にスラッシュを付け直す。
 * update_post_meta / update_term_meta は内部（update_metadata）で wp_unslash するため、
 * 素の値を渡すとバックスラッシュが1段消える。直前でこれを通す。
 * update_option / set_theme_mod は外さないので、そちらには使わない。
 */
function rdh_slash_for_meta($value) {
    return wp_slash($value);
}

/**
 * 連想配列を再帰マージする。
 * テーマ設定は `diver_color` のような **シリアライズ配列1本** に全部入っているため、
 * 丸ごと上書きすると触っていない項目まで消える＝テーマの機能が壊れる。
 * merge=true のときはこの関数で「指定した葉だけ」差し替える。
 */
function rdh_merge_deep($base, $patch) {
    if (!is_array($base) || !is_array($patch)) {
        return $patch;
    }
    foreach ($patch as $k => $v) {
        if (is_array($v) && isset($base[$k]) && is_array($base[$k])) {
            $base[$k] = rdh_merge_deep($base[$k], $v);
        } else {
            $base[$k] = $v;
        }
    }
    return $base;
}

/**
 * 変更前の値を退避する（復元用）。
 * 直近 RDH_BACKUP_MAX 件だけ保持する。
 *
 * $type: option | post | post_bulk | term | thememod
 * post_bulk のときは $value に post_id => 変更前の値 の連想配列を渡す
 * （一括更新は件数が多いので、1件の退避にまとめて入れる）。
 */
function rdh_backup_push($type, $target, $key, $value) {
    $log = get_option(RDH_BACKUP_OPTION, []);
    if (!is_array($log)) {
        $log = [];
    }
    array_unshift($log, [
        'id'     => uniqid('rdh', true),
        'at'     => current_time('mysql'),
        'user'   => get_current_user_id(),
        'type'   => $type,      // option | post | post_bulk | term | thememod
        'target' => $target,    // 投稿ID・タームID・オプション名など
        'key'    => $key,
        'before' => $value,
    ]);
    $log = array_slice($log, 0, RDH_BACKUP_MAX);
    update_option(RDH_BACKUP_OPTION, $log, false);
    return $log[0]['id'];
}

/** 退避一覧 */
function rdh_backup_list() {
    $log = get_option(RDH_BACKUP_OPTION, []);
    return is_array($log) ? $log : [];
}

/** 退避1件を取り出す */
function rdh_backup_find($id) {
    foreach (rdh_backup_list() as $row) {
        if (($row['id'] ?? '') === $id) {
            return $row;
        }
    }
    return null;
}

/** 退避の種別から、キーのガードで使う context を返す */
function rdh_backup_context($type) {
    $map = [
        'option'    => 'option',
        'post'      => 'post',
        'post_bulk' => 'post',
        'term'      => 'term',
        'thememod'  => 'thememod',
    ];
    return $map[$type] ?? null;
}

/** 退避を戻す前に、いま入っている値を取得する（復元自体を取り消せるように退避するため） */
function rdh_current_value($type, $target, $key, $before) {
    switch ($type) {
        case 'option':
            return get_option($target, null);
        case 'post':
            return get_post_meta((int) $target, $key, true);
        case 'term':
            return get_term_meta((int) $target, $key, true);
        case 'thememod':
            return get_theme_mod($key, null);
        case 'post_bulk':
            $now = [];
            foreach ((array) $before as $post_id => $_ignored) {
                $now[(int) $post_id] = get_post_meta((int) $post_id, $key, true);
            }
            return $now;
    }
    return null;
}

/** 退避から1件戻す */
function rdh_backup_restore($id) {
    $row = rdh_backup_find($id);
    if (!$row) {
        return new WP_Error('rdh_no_backup', 'その退避IDは見つかりません: ' . $id, ['status' => 404]);
    }

    $type = $row['type'] ?? '';
    $key  = $row['key'] ?? '';

    $context = rdh_backup_context($type);
    if ($context === null) {
        return new WP_Error('rdh_bad_type', '不明な種別: ' . $type, ['status' => 400]);
    }
    // 復元も書き込みなので、書き込み禁止キーは通さない
    $guard_key = ($context === 'option') ? (string) $row['target'] : (string) $key;
    if (!rdh_key_allowed($guard_key, $context)) {
        return new WP_Error('rdh_key_denied',
            'このキーは書き込み禁止のため復元できません: ' . $guard_key, ['status' => 403]);
    }

    // 戻す前の値も退避しておく（復元を取り消せるように）
    $undo_id = rdh_backup_push($type, $row['target'], $key,
        rdh_current_value($type, $row['target'], $key, $row['before'] ?? null));

    $restored_posts = null;

    switch ($type) {
        case 'option':
            if ($row['before'] === null) {
                delete_option($row['target']);
            } else {
                update_option($row['target'], $row['before']);
            }
            break;

        case 'post':
            if ($row['before'] === '' || $row['before'] === null) {
                delete_post_meta((int) $row['target'], $key);
            } else {
                update_post_meta((int) $row['target'], $key, rdh_slash_for_meta($row['before']));
            }
            break;

        case 'post_bulk':
            $restored_posts = 0;
            foreach ((array) $row['before'] as $post_id => $before) {
                $post_id = (int) $post_id;
                if (!get_post($post_id)) {
                    continue;
                }
                if ($before === '' || $before === null) {
                    delete_post_meta($post_id, $key);
                } else {
                    update_post_meta($post_id, $key, rdh_slash_for_meta($before));
                }
                $restored_posts++;
            }
            break;

        case 'term':
            if ($row['before'] === '' || $row['before'] === null) {
                delete_term_meta((int) $row['target'], $key);
            } else {
                update_term_meta((int) $row['target'], $key, rdh_slash_for_meta($row['before']));
            }
            break;

        case 'thememod':
            if ($row['before'] === null) {
                remove_theme_mod($key);
            } else {
                set_theme_mod($key, $row['before']);
            }
            break;
    }

    $entry = $row;
    if ($type === 'post_bulk') {
        // 一括の退避は中身が大きいので、返すときは件数だけにする
        $entry['before'] = '(' . count((array) $row['before']) . '件)';
    }

    $result = ['restored' => true, 'entry' => $entry, 'undo_backup_id' => $undo_id];
    if ($restored_posts !== null) {
        $result['restored_posts'] = $restored_posts;
    }
    return $result;
}
