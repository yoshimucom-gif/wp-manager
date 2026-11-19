<?php
/**
 * IndexNow で Bing（と参加している検索エンジン）に更新を知らせる（1.8.0・1.8.1で導入直後の403も再送）
 *
 * ── 目的
 * 記事を公開・更新・非公開にしたとき、そのURLを IndexNow に送る。
 * Bing の索引は Copilot・DuckDuckGo の土台なので、ここが速く反映されるとそちらにも効く。
 * 順位を上げる仕組みではない（Microsoft 公式）。「早く見つけてもらう」ためのもの。
 *
 * ── 動き
 * - キーは初回に自動で作り、オプション rdh_indexnow_key に保存する。
 *   /<キー>.txt へのリクエストに、キーそのものを返す（ファイルは置かない）。
 * - 公開状態が変わった投稿の URL をキュー（rdh_indexnow_queue）に積み、
 *   1分後の WP-Cron でまとめて1回だけ送る。REST で何百本も更新しても送信は数回で済む。
 * - 送らないもの: サイト全体が検索エンジンに非表示（blog_public=0）、
 *   公開でない投稿タイプ、パスワード付き、記事ごとの noindex（diver_single_seo）。
 *   公開→非公開・ゴミ箱は「消えた」ことを知らせるために送る。
 * - 結果は直近30回分を rdh_indexnow_log に残す。
 *
 * ── REST
 *   GET  /wp-json/rdh/v1/indexnow              キー・キーのURL・キュー・送信ログ
 *   POST /wp-json/rdh/v1/indexnow {"urls":[…]} 指定URLをすぐ送る
 *   POST /wp-json/rdh/v1/indexnow {"all":true} 公開中の投稿・固定ページを全部すぐ送る（初回用）
 *   どちらも {"dry_run":true} で送らずに件数だけ返す。
 *
 * 止めたいときは option rdh_indexnow_off を 1 にするか、functions.php 等で:
 *   add_filter('rdh_indexnow_enabled', '__return_false');
 */

if (!defined('ABSPATH')) exit;

if (!defined('RDH_INDEXNOW_ENDPOINT')) {
    define('RDH_INDEXNOW_ENDPOINT', 'https://api.indexnow.org/indexnow');
}
/** IndexNow の1リクエストの上限 */
if (!defined('RDH_INDEXNOW_BATCH')) {
    define('RDH_INDEXNOW_BATCH', 10000);
}

function rdh_indexnow_enabled() {
    if (get_option('rdh_indexnow_off') === '1') return false;
    if ((string) get_option('blog_public', '1') === '0') return false;
    return (bool) apply_filters('rdh_indexnow_enabled', true);
}

/** キー（8〜128文字の英数字とハイフン）。無ければ作る */
function rdh_indexnow_key() {
    $key = (string) get_option('rdh_indexnow_key', '');
    if (!preg_match('/^[A-Za-z0-9-]{8,128}$/', $key)) {
        $key = strtolower(wp_generate_password(32, false, false));
        $key = preg_replace('/[^a-z0-9]/', '', $key);
        if (strlen($key) < 8) $key = md5(home_url() . microtime(true));
        update_option('rdh_indexnow_key', $key, true);
    }
    return $key;
}

function rdh_indexnow_key_url() {
    return home_url('/' . rdh_indexnow_key() . '.txt');
}

/** /<キー>.txt を返す（ads.txt と同じく init で拾う） */
add_action('init', function () {
    $key = (string) get_option('rdh_indexnow_key', '');
    if ($key === '') return;
    $path = (string) parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);
    $home = rtrim((string) parse_url(home_url('/'), PHP_URL_PATH), '/');
    if ($path !== $home . '/' . $key . '.txt') return;
    status_header(200);
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: public, max-age=86400');
    header('X-Robots-Tag: noindex');
    echo $key;
    exit;
}, 0);

/** 有効化・更新の直後にキーを用意しておく（キーのURLが先に生きていないと送信が 403 になる） */
add_action('admin_init', 'rdh_indexnow_key');
add_action('rest_api_init', 'rdh_indexnow_key');

/** 記事ごとの noindex（re:Diver の SEO 設定） */
function rdh_indexnow_post_noindex($post_id) {
    $seo = get_post_meta($post_id, 'diver_single_seo', true);
    return is_array($seo) && !empty($seo['valid']) && !empty($seo['robots']['noindex']);
}

function rdh_indexnow_post_type_ok($post) {
    if (!$post || $post->post_type === 'attachment') return false;
    $pto = get_post_type_object($post->post_type);
    return $pto && $pto->public && is_post_type_viewable($pto);
}

/** URL をキューに積み、1分後に送る予約を入れる */
function rdh_indexnow_enqueue(array $urls) {
    $urls = array_values(array_filter(array_map('strval', $urls)));
    if (!$urls || !rdh_indexnow_enabled()) return;
    $q = get_option('rdh_indexnow_queue', []);
    if (!is_array($q)) $q = [];
    $q = array_values(array_unique(array_merge($q, $urls)));
    update_option('rdh_indexnow_queue', $q, false);
    if (!wp_next_scheduled('rdh_indexnow_flush')) {
        wp_schedule_single_event(time() + 60, 'rdh_indexnow_flush');
    }
}

add_action('transition_post_status', function ($new, $old, $post) {
    if (wp_is_post_revision($post) || wp_is_post_autosave($post)) return;
    if (!rdh_indexnow_post_type_ok($post)) return;
    if ($new !== 'publish' && $old !== 'publish') return;   // 公開に関係しない遷移は送らない
    if ($new === 'publish' && (post_password_required($post) || rdh_indexnow_post_noindex($post->ID))) return;

    $url = get_permalink($post);
    if ($new !== 'publish' && $old === 'publish') {
        // 非公開・ゴミ箱に移ったあとは get_permalink が ?p= や __trashed になる。公開時のURLを組み直す
        $clone = clone $post;
        $clone->post_status = 'publish';
        $clone->post_name   = preg_replace('/__trashed(-\d+)?$/', '', (string) $clone->post_name);
        $url = get_permalink($clone);
    }
    if ($url) rdh_indexnow_enqueue([$url]);
}, 10, 3);

/** 送信の本体。結果をログに1行残して返す */
function rdh_indexnow_send(array $urls, $source) {
    $urls = array_values(array_unique(array_filter($urls)));
    $host = (string) parse_url(home_url('/'), PHP_URL_HOST);
    // 自サイト以外の URL は IndexNow 側で 422 になるので先に落とす
    $urls = array_values(array_filter($urls, function ($u) use ($host) {
        return strcasecmp((string) parse_url($u, PHP_URL_HOST), $host) === 0;
    }));
    $results = [];
    foreach (array_chunk($urls, RDH_INDEXNOW_BATCH) as $chunk) {
        $res = wp_remote_post(RDH_INDEXNOW_ENDPOINT, [
            'timeout' => 15,
            'headers' => ['Content-Type' => 'application/json; charset=utf-8'],
            'body'    => wp_json_encode([
                'host'        => $host,
                'key'         => rdh_indexnow_key(),
                'keyLocation' => rdh_indexnow_key_url(),
                'urlList'     => $chunk,
            ]),
        ]);
        $code = is_wp_error($res) ? 0 : (int) wp_remote_retrieve_response_code($res);
        $row  = [
            'time'   => current_time('mysql'),
            'source' => $source,
            'count'  => count($chunk),
            'status' => $code,
            'ok'     => in_array($code, [200, 202], true),
            'error'  => is_wp_error($res) ? $res->get_error_message()
                : ($code >= 400 ? substr((string) wp_remote_retrieve_body($res), 0, 300) : ''),
            'sample' => $chunk[0] ?? '',
        ];
        $log = get_option('rdh_indexnow_log', []);
        if (!is_array($log)) $log = [];
        array_unshift($log, $row);
        update_option('rdh_indexnow_log', array_slice($log, 0, 30), false);
        $results[] = $row;
    }
    return $results;
}

add_action('rdh_indexnow_flush', function () {
    $q = get_option('rdh_indexnow_queue', []);
    update_option('rdh_indexnow_queue', [], false);
    if (!is_array($q) || !$q || !rdh_indexnow_enabled()) return;
    $results = rdh_indexnow_send($q, 'auto');
    // 回線・サーバー側の一時的な失敗（0 / 429 / 5xx）だけ、10分後にもう1回試す
    $retry = false;
    foreach ($results as $r) {
        if ($r['status'] === 0 || $r['status'] === 429 || $r['status'] >= 500) $retry = true;
        // 導入直後は検索エンジン側のキー確認が終わるまで 403 SiteVerificationNotCompleted が返る（1.8.1）
        if ($r['status'] === 403 && strpos((string) $r['error'], 'SiteVerificationNotCompleted') !== false) $retry = true;
    }
    if ($retry && !get_transient('rdh_indexnow_retried')) {
        set_transient('rdh_indexnow_retried', 1, 30 * MINUTE_IN_SECONDS);
        rdh_indexnow_enqueue($q);
        wp_clear_scheduled_hook('rdh_indexnow_flush');
        wp_schedule_single_event(time() + 10 * MINUTE_IN_SECONDS, 'rdh_indexnow_flush');
    }
});

/** 公開中の投稿・固定ページ（＋公開の独自投稿タイプ）のURLを全部集める */
function rdh_indexnow_all_urls() {
    $types = array_values(array_filter(get_post_types(['public' => true]), function ($t) {
        return $t !== 'attachment' && is_post_type_viewable($t);
    }));
    $ids = get_posts([
        'post_type'        => $types,
        'post_status'      => 'publish',
        'posts_per_page'   => -1,
        'fields'           => 'ids',
        'has_password'     => false,
        'suppress_filters' => true,
        'no_found_rows'    => true,
    ]);
    $urls = [home_url('/')];
    foreach ($ids as $id) {
        if (rdh_indexnow_post_noindex($id)) continue;
        $u = get_permalink($id);
        if ($u) $urls[] = $u;
    }
    return array_values(array_unique($urls));
}

add_action('rest_api_init', function () {
    register_rest_route(RDH_NS, '/indexnow', [
        [
            'methods'             => 'GET',
            'permission_callback' => 'rdh_permission',
            'callback'            => function () {
                $q = get_option('rdh_indexnow_queue', []);
                return [
                    'enabled'      => rdh_indexnow_enabled(),
                    'key'          => rdh_indexnow_key(),
                    'key_url'      => rdh_indexnow_key_url(),
                    'endpoint'     => RDH_INDEXNOW_ENDPOINT,
                    'queue'        => is_array($q) ? count($q) : 0,
                    'next_flush'   => ($t = wp_next_scheduled('rdh_indexnow_flush')) ? gmdate('c', $t) : null,
                    'log'          => get_option('rdh_indexnow_log', []),
                ];
            },
        ],
        [
            'methods'             => 'POST',
            'permission_callback' => 'rdh_permission',
            'callback'            => function (WP_REST_Request $req) {
                if (!rdh_indexnow_enabled()) {
                    return new WP_Error('rdh_indexnow_off', 'IndexNow は無効です（rdh_indexnow_off か blog_public=0）。', ['status' => 409]);
                }
                $urls = $req->get_param('all') ? rdh_indexnow_all_urls() : (array) $req->get_param('urls');
                $urls = array_values(array_filter(array_map('esc_url_raw', $urls)));
                if (!$urls) return new WP_Error('rdh_indexnow_empty', '送るURLがありません。', ['status' => 400]);
                if ($req->get_param('dry_run')) {
                    return ['dry_run' => true, 'count' => count($urls), 'sample' => array_slice($urls, 0, 5)];
                }
                return ['count' => count($urls), 'results' => rdh_indexnow_send($urls, $req->get_param('all') ? 'all' : 'manual')];
            },
        ],
    ]);
});
