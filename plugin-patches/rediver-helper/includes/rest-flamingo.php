<?php
/**
 * Flamingo（Contact Form 7 の受信記録）の期間集計（1.7.0）
 *
 * GET /rdh/v1/flamingo-summary
 *   from=YYYY-MM-DD   既定＝今月1日（サイトのタイムゾーン）
 *   to=YYYY-MM-DD     既定＝今日（その日を含む）
 *   field=price       合計するフォーム項目（Flamingo のメタ _field_<field>）
 *   exclude_domains=a.jp,b.com   集計から外す送信元ドメイン（サイト自身のドメインは常に外す）
 *   exclude_emails=x@y.jp,...     集計から外すメールアドレス
 *   rows=1            対象行の一覧も返す
 *
 * 毎週の売上報告で「Flamingoをエクセルに出して price を手で合計する」作業の代わり。
 * 受信トレイ（post_status=publish）だけを数え、迷惑メール・ゴミ箱は数えない。
 * テスト送信（社内ドメイン・名前/会社名/メールに「テスト」「test」）は除外し、
 * 除外した行は理由つきで excluded に返す（誤除外を目で確かめられるように）。
 */

if (!defined('ABSPATH')) exit;

function rdh_flamingo_to_number($v) {
    if (is_array($v)) $v = implode('', $v);
    $v = (string) $v;
    if (function_exists('mb_convert_kana')) $v = mb_convert_kana($v, 'n', 'UTF-8');
    $v = preg_replace('/[^0-9\-]/', '', $v);
    return ($v === '' || $v === '-') ? 0 : (int) $v;
}

function rdh_flamingo_summary(WP_REST_Request $req) {
    if (!post_type_exists('flamingo_inbound')) {
        return new WP_Error('rdh_no_flamingo', 'Flamingo が有効ではありません。', ['status' => 404]);
    }
    $tz    = wp_timezone();
    $today = new DateTimeImmutable('now', $tz);
    $from  = $req->get_param('from') ?: $today->format('Y-m-01');
    $to    = $req->get_param('to') ?: $today->format('Y-m-d');
    foreach (['from' => $from, 'to' => $to] as $k => $d) {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) {
            return new WP_Error('rdh_bad_date', "$k は YYYY-MM-DD で指定してください。", ['status' => 400]);
        }
    }
    $field = sanitize_key($req->get_param('field') ?: 'price');

    $split = function ($s) {
        return array_values(array_filter(array_map(function ($x) { return strtolower(trim($x)); }, explode(',', (string) $s))));
    };
    $host = strtolower((string) wp_parse_url(home_url(), PHP_URL_HOST));
    $host = preg_replace('/^www\./', '', $host);
    $ex_domains = array_unique(array_merge([$host], $split($req->get_param('exclude_domains'))));
    $ex_emails  = $split($req->get_param('exclude_emails'));

    $ids = get_posts([
        'post_type'        => 'flamingo_inbound',
        'post_status'      => 'publish',
        'posts_per_page'   => -1,
        'fields'           => 'ids',
        'orderby'          => 'date',
        'order'            => 'ASC',
        'suppress_filters' => true,
        'date_query'       => [[
            'after'     => $from . ' 00:00:00',
            'before'    => $to . ' 23:59:59',
            'inclusive' => true,
        ]],
    ]);

    $sum = 0; $count = 0; $priced = 0;
    $rows = []; $excluded = [];
    foreach ($ids as $id) {
        $email = strtolower(trim((string) get_post_meta($id, '_from_email', true)));
        if ($email === '') $email = strtolower(trim((string) get_post_meta($id, '_field_your-email', true)));
        $names = [];
        foreach (get_post_meta($id) as $key => $vals) {
            if ($key === '_from_name' || preg_match('/^_field_.*(name|company|kaisha|corp)/i', $key)) {
                $names[] = implode(' ', (array) $vals);
            }
        }
        $price = rdh_flamingo_to_number(get_post_meta($id, '_field_' . $field, true));
        $row = [
            'id'      => (int) $id,
            'date'    => get_post_field('post_date', $id),
            'service' => (string) get_post_meta($id, '_field_service', true),
            'email'   => $email,
            $field    => $price,
        ];

        $domain = substr(strrchr($email, '@') ?: '', 1);
        $reason = '';
        if ($domain !== '') {
            foreach ($ex_domains as $d) {
                if ($domain === $d || substr($domain, -strlen('.' . $d)) === '.' . $d) { $reason = '社内ドメイン ' . $d; break; }
            }
        }
        if (!$reason && in_array($email, $ex_emails, true)) $reason = '除外アドレス';
        if (!$reason && preg_match('/(^|[^a-z])test/i', $email)) $reason = 'メールに test';
        if (!$reason) {
            $hay = implode(' ', $names);
            if (preg_match('/テスト|ﾃｽﾄ|(^|[^a-z])test([^a-z]|$)/iu', $hay)) $reason = '名前・会社名にテスト';
        }

        if ($reason) {
            $row['reason'] = $reason;
            $excluded[] = $row;
            continue;
        }
        $count++;
        $sum += $price;
        if ($price !== 0) $priced++;
        $rows[] = $row;
    }

    $out = [
        'site'      => home_url(),
        'from'      => $from,
        'to'        => $to,
        'field'     => $field,
        'sum'       => $sum,
        'count'     => $count,
        'count_priced' => $priced,
        'excluded_count' => count($excluded),
        'excluded'  => $excluded,
        'exclude_domains' => $ex_domains,
        'generated' => $today->format('Y-m-d H:i:s'),
    ];
    if ($req->get_param('rows')) $out['rows'] = $rows;
    return $out;
}

/**
 * 同じ集計を REST 以外の URL でも返す（1.7.1）
 *   GET /?rdh_flamingo_summary=1&from=...&to=...&field=price&exclude_domains=...
 *
 * Xserver の「国外IPアクセス制限」は /wp-json/ を海外IPから 403 にするため、
 * Google Apps Script（米国のサーバーから接続する）から REST を呼べない。
 * 認証は REST と同じアプリケーションパスワード（Basic認証）で、管理者でなければ 401 を返す。
 */
add_filter('application_password_is_api_request', function ($is) {
    return $is || isset($_GET['rdh_flamingo_summary']);
});
add_action('init', function () {
    if (!isset($_GET['rdh_flamingo_summary'])) return;
    nocache_headers();
    if (!current_user_can('manage_options')) {
        wp_send_json(['code' => 'rdh_forbidden', 'message' => '管理者のアプリケーションパスワードが必要です。'], 401);
    }
    $req = new WP_REST_Request('GET');
    foreach (['from', 'to', 'field', 'exclude_domains', 'exclude_emails', 'rows'] as $k) {
        if (isset($_GET[$k])) $req->set_param($k, sanitize_text_field(wp_unslash($_GET[$k])));
    }
    $res = rdh_flamingo_summary($req);
    if (is_wp_error($res)) {
        $data = $res->get_error_data();
        wp_send_json(['code' => $res->get_error_code(), 'message' => $res->get_error_message()], $data['status'] ?? 400);
    }
    wp_send_json($res);
}, 100);

add_action('rest_api_init', function () {
    register_rest_route(RDH_NS, '/flamingo-summary', [
        'methods'             => 'GET',
        'permission_callback' => 'rdh_permission',
        'callback'            => 'rdh_flamingo_summary',
    ]);
});
