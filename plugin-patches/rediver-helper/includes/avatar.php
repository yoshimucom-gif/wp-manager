<?php
/**
 * 著者アバターをメディアライブラリの画像に差し替える。
 *
 * 設定は option `rdh_avatar_map`（{"ユーザーID": 添付ファイルID}）。
 * 既存の option エンドポイントで書ける:
 *   POST /rdh/v1/option/rdh_avatar_map  {"value":{"1":17}}
 *
 * Gravatar に画像を登録しなくても、サイト内のアバターを差し替えられる。
 * 無効にしたいときは option を空にするか、フィルタ rdh_avatar_override を false にする。
 */

if (!defined('ABSPATH')) exit;

add_filter('get_avatar_data', function ($args, $id_or_email) {
    if (!apply_filters('rdh_avatar_override', true)) {
        return $args;
    }
    $map = get_option('rdh_avatar_map');
    if (!is_array($map) || !$map) {
        return $args;
    }

    $user_id = 0;
    if (is_numeric($id_or_email)) {
        $user_id = (int) $id_or_email;
    } elseif ($id_or_email instanceof WP_User) {
        $user_id = (int) $id_or_email->ID;
    } elseif ($id_or_email instanceof WP_Post) {
        $user_id = (int) $id_or_email->post_author;
    } elseif ($id_or_email instanceof WP_Comment) {
        $user_id = (int) $id_or_email->user_id;
    } elseif (is_string($id_or_email) && is_email($id_or_email)) {
        $u = get_user_by('email', $id_or_email);
        if ($u) {
            $user_id = (int) $u->ID;
        }
    }
    if (!$user_id) {
        return $args;
    }

    $key = (string) $user_id;
    if (empty($map[$key])) {
        return $args;
    }
    $attachment_id = (int) $map[$key];
    if ($attachment_id <= 0 || get_post_type($attachment_id) !== 'attachment') {
        return $args;
    }

    $size = isset($args['size']) ? (int) $args['size'] : 96;
    $url  = wp_get_attachment_image_url($attachment_id, [$size, $size]);
    if (!$url) {
        $url = wp_get_attachment_image_url($attachment_id, 'full');
    }
    if (!$url) {
        return $args;
    }

    $args['url']          = $url;
    $args['found_avatar'] = true;
    // Gravatar 用の srcset が残ると 2x で別画像になるため落とす
    $args['default']      = '';
    $args['force_default'] = false;
    return $args;
}, 10, 2);
