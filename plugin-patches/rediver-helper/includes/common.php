<?php
/**
 * 共通ヘルパー（権限判定・値の装飾・キーのガード・バックアップ・部分マージ）
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

/** 管理者相当のみ許可 */
function rdh_permission() {
    if (current_user_can('manage_options')) {
        return true;
    }
    return new WP_Error('rdh_forbidden', '管理者権限が必要です。', ['status' => 403]);
}

/** 添付IDらしき値なら画像URLを添える（画像系メタの確認が1往復で済むように） */
function rdh_decorate($value) {
    $out = ['value' => $value];
    if (is_string($value) && ctype_digit($value) && (int) $value > 0) {
        $url = wp_get_attachment_url((int) $value);
        if ($url) {
            $out['attachment_id']  = (int) $value;
            $out['attachment_url'] = $url;
        }
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
 */
function rdh_key_allowed($key, $context) {
    $deny = [
        'post'   => ['_edit_lock', '_edit_last', '_wp_trash_meta_status', '_wp_trash_meta_time'],
        'term'   => [],
        'option' => [
            // サイトが起動しなくなる／権限昇格につながるもの
            'siteurl', 'home', 'template', 'stylesheet', 'active_plugins', 'admin_email',
            'users_can_register', 'default_role', 'wp_user_roles', 'db_version',
            'cron', 'rewrite_rules', 'recently_activated', 'uninstall_plugins',
        ],
    ];
    $allowed = !in_array($key, $deny[$context] ?? [], true);
    // 内部用の接頭辞も拒否（_transient / _site_transient など）
    if ($context === 'option' && preg_match('/^_(site_)?transient_/', $key)) {
        $allowed = false;
    }
    return (bool) apply_filters('rdh_key_allowed', $allowed, $key, $context);
}

/** 値のスラッシュを外す（WPのREST経由だとエスケープが乗るため） */
function rdh_clean($value) {
    if (is_string($value)) {
        return wp_unslash($value);
    }
    if (is_array($value)) {
        return array_map('rdh_clean', $value);
    }
    return $value;
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
        'type'   => $type,      // option | post | term | thememod
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

/** 退避から1件戻す */
function rdh_backup_restore($id) {
    foreach (rdh_backup_list() as $row) {
        if (($row['id'] ?? '') !== $id) {
            continue;
        }
        switch ($row['type']) {
            case 'option':
                update_option($row['target'], $row['before']);
                break;
            case 'post':
                if ($row['before'] === '' || $row['before'] === null) {
                    delete_post_meta((int) $row['target'], $row['key']);
                } else {
                    update_post_meta((int) $row['target'], $row['key'], $row['before']);
                }
                break;
            case 'term':
                if ($row['before'] === '' || $row['before'] === null) {
                    delete_term_meta((int) $row['target'], $row['key']);
                } else {
                    update_term_meta((int) $row['target'], $row['key'], $row['before']);
                }
                break;
            case 'thememod':
                if ($row['before'] === null) {
                    remove_theme_mod($row['key']);
                } else {
                    set_theme_mod($row['key'], $row['before']);
                }
                break;
            default:
                return new WP_Error('rdh_bad_type', '不明な種別: ' . $row['type'], ['status' => 400]);
        }
        return ['restored' => true, 'entry' => $row];
    }
    return new WP_Error('rdh_no_backup', 'その退避IDは見つかりません: ' . $id, ['status' => 404]);
}
