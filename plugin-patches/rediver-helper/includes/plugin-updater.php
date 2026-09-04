<?php
/**
 * プラグイン自動更新チェッカー
 *
 * 配信サーバー(/api/plugin-update/<key>)をWordPress の更新サーバーとして使い、
 * 標準の「プラグイン更新」フローに組み込む。
 *
 * 動作:
 *   1. WP が定期的に pre_set_site_transient_update_plugins を叩く
 *   2. 本クラスが配信サーバーから JSON を取得し、ヘッダーバージョンと比較
 *   3. 新しければ transient->response に追加 → 「更新可能」バッジ表示
 *   4. ユーザーが「更新」クリック → WP が download_url から zip 取得・展開
 *
 * クラス名は本プラグイン専用（RDH_ = Re:Diver Helper）。
 * それでも二重読み込みで落ちないよう class_exists ガードを置く。
 *
 * 取得に失敗したときは「失敗した」ことも短時間キャッシュする。
 * これが無いと、配信URLが落ちている間は管理画面を開くたびに
 * 10秒のブロッキングHTTPを繰り返すことになる。
 */

if (!defined('ABSPATH')) exit;

if (!class_exists('RDH_Plugin_Updater')) :

class RDH_Plugin_Updater {
    /** プラグイン本体ファイル (__FILE__) */
    private $plugin_file;
    /** プラグインスラッグ (ディレクトリ名) */
    private $plugin_slug;
    /** プラグインベース名 (例: rediver-helper/rediver-helper.php) */
    private $plugin_basename;
    /** アップデート情報エンドポイント URL */
    private $update_url;
    /** リモート情報キャッシュキー */
    private $cache_key;
    /** リモート情報キャッシュ TTL (秒) */
    private $cache_ttl;
    /** 取得失敗を覚えておくキー（連続リトライで管理画面が固まるのを防ぐ） */
    private $fail_key;
    /** 取得失敗を覚えておく秒数 */
    private $fail_ttl = 300;

    public function __construct($plugin_file, $update_url, $cache_ttl = 1800) {
        $this->plugin_file     = $plugin_file;
        $this->plugin_basename = plugin_basename($plugin_file);
        $this->plugin_slug     = dirname($this->plugin_basename);
        $this->update_url      = $update_url;
        $this->cache_key       = 'rdh_updater_' . md5($this->plugin_basename);
        $this->fail_key        = 'rdh_updater_fail_' . md5($this->plugin_basename);
        $this->cache_ttl       = (int)$cache_ttl;

        add_filter('pre_set_site_transient_update_plugins', [$this, 'check_for_update']);
        add_filter('plugins_api',                            [$this, 'plugins_api_filter'], 10, 3);
        add_action('upgrader_process_complete',              [$this, 'purge_cache'], 10, 2);
    }

    /** 配信サーバーから最新メタ情報を取得（成功も失敗もキャッシュする） */
    private function fetch_remote_info() {
        $cached = get_transient($this->cache_key);
        if ($cached !== false) return $cached;

        // 直近で失敗しているなら、しばらく叩きに行かない
        if (get_transient($this->fail_key) !== false) return null;

        $response = wp_remote_get($this->update_url, [
            'timeout' => 10,
            'headers' => ['Accept' => 'application/json'],
        ]);
        if (is_wp_error($response) || (int)wp_remote_retrieve_response_code($response) !== 200) {
            return $this->remember_failure();
        }

        $data = json_decode(wp_remote_retrieve_body($response));
        if (!is_object($data) || empty($data->version)) {
            return $this->remember_failure();
        }

        set_transient($this->cache_key, $data, $this->cache_ttl);
        return $data;
    }

    /** 失敗を短時間だけ覚える */
    private function remember_failure() {
        set_transient($this->fail_key, 1, $this->fail_ttl);
        return null;
    }

    /**
     * 配布zipのURLとして受け入れてよいURLか。
     * WP は package をそのままダウンロードして展開するので、
     * https 以外（http・file 等）は受け付けない。
     */
    private function safe_package_url($url) {
        $url = is_string($url) ? trim($url) : '';
        if ($url === '') return '';
        $parts = wp_parse_url($url);
        if (empty($parts['scheme']) || strtolower($parts['scheme']) !== 'https') return '';
        if (empty($parts['host'])) return '';
        return $url;
    }

    /** WP の更新チェックに割り込んで、自分の更新情報を注入する */
    public function check_for_update($transient) {
        if (!is_object($transient)) return $transient;
        // checked が空でも update_plugins transient は来うる。早期 return しない。

        $remote = $this->fetch_remote_info();
        if (!$remote) return $transient;

        $current_version = $this->current_installed_version();
        if (!$current_version) return $transient;

        $package = $this->safe_package_url(isset($remote->download_url) ? $remote->download_url : '');

        // https の配布URLが無いなら「更新あり」を出さない。
        // 出してしまうと、押した瞬間に落ちてくる先が不明なまま展開されることになる。
        if (version_compare($current_version, $remote->version, '<') && $package !== '') {
            $entry = (object)[
                'id'           => $this->plugin_basename,
                'slug'         => $this->plugin_slug,
                'plugin'       => $this->plugin_basename,
                'new_version'  => $remote->version,
                'url'          => isset($remote->homepage) ? $remote->homepage : '',
                'package'      => $package,
                'tested'       => isset($remote->tested) ? $remote->tested : '',
                'requires'     => isset($remote->requires) ? $remote->requires : '',
                'requires_php' => isset($remote->requires_php) ? $remote->requires_php : '',
                'icons'        => [],
                'banners'      => [],
            ];
            if (!isset($transient->response) || !is_array($transient->response)) {
                $transient->response = [];
            }
            $transient->response[$this->plugin_basename] = $entry;
        } else {
            // 最新だが no_update に登録しておくと UI が "最新" 表示になる
            if (!isset($transient->no_update) || !is_array($transient->no_update)) {
                $transient->no_update = [];
            }
            $transient->no_update[$this->plugin_basename] = (object)[
                'id'          => $this->plugin_basename,
                'slug'        => $this->plugin_slug,
                'plugin'      => $this->plugin_basename,
                'new_version' => $remote->version,
                'url'         => '',
                'package'     => '',
            ];
        }
        return $transient;
    }

    /** 「詳細を表示」モーダル用 */
    public function plugins_api_filter($result, $action, $args) {
        if ($action !== 'plugin_information') return $result;
        if (!isset($args->slug) || $args->slug !== $this->plugin_slug) return $result;

        $remote = $this->fetch_remote_info();
        if (!$remote) return $result;

        return (object)[
            'name'         => isset($remote->name) ? $remote->name : $this->plugin_slug,
            'slug'         => $this->plugin_slug,
            'version'      => $remote->version,
            'tested'       => isset($remote->tested) ? $remote->tested : '',
            'requires'     => isset($remote->requires) ? $remote->requires : '',
            'requires_php' => isset($remote->requires_php) ? $remote->requires_php : '',
            'author'       => isset($remote->author) ? $remote->author : 'Keys',
            'download_link'=> $this->safe_package_url(isset($remote->download_url) ? $remote->download_url : ''),
            'sections'     => isset($remote->sections) ? (array)$remote->sections : [],
            'banners'      => [],
        ];
    }

    /** 更新完了後にキャッシュを破棄して再チェックを促す */
    public function purge_cache($upgrader, $hook_extra) {
        if (!is_array($hook_extra)) return;
        if (($hook_extra['action'] ?? '') !== 'update') return;
        if (($hook_extra['type']   ?? '') !== 'plugin') return;
        delete_transient($this->cache_key);
        delete_transient($this->fail_key);
    }

    /** 現在インストール済みのバージョン（プラグインヘッダー）を取得 */
    private function current_installed_version() {
        if (!function_exists('get_plugin_data')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        $data = get_plugin_data($this->plugin_file, false, false);
        return isset($data['Version']) ? $data['Version'] : '';
    }
}

endif;
