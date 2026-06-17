<?php
/**
 * Plugin Name: Affiros 重複投稿クリーンアップ
 * Description: 同タイトルで複数回投稿された記事を検出し、最古の1件を残して残りを削除する単機能ツール。Affiros9 v1.7.27 以前の予約投稿バグで発生した重複の片付け用。リライター・インサーターと独立。
 * Version: 1.0.1
 * Author: Affiros
 * License: GPL v2 or later
 */

if (!defined('ABSPATH')) exit;

define('AFFIROS_DUP_CLEANER_VERSION', '1.0.1');

// 自動更新通知（Affiros9 サーバーから定期チェック）
require_once __DIR__ . '/includes/plugin-updater.php';
add_action('init', function () {
    $host = defined('AFFIROS_UPDATE_HOST') ? AFFIROS_UPDATE_HOST : 'https://wp-manager.onrender.com';
    new Affiros_Plugin_Updater(__FILE__, rtrim($host, '/') . '/api/plugin-update/dup-cleaner');
});

/**
 * メニュー登録: 「ツール」配下に置く（リライター/インサーターと混じらないよう独立トップではなく Tools 配下）
 */
add_action('admin_menu', function () {
    add_management_page(
        '重複投稿クリーンアップ',
        '🧹 重複投稿クリーンアップ',
        'manage_options',
        'affiros-dup-cleaner',
        'affiros_dup_cleaner_render_page'
    );
});

/**
 * 設定不要、認証用 nonce だけ localize して埋める
 */
add_action('admin_enqueue_scripts', function ($hook) {
    if ($hook !== 'tools_page_affiros-dup-cleaner') return;
    wp_localize_script('jquery', 'AffirosDupCleaner', [
        'ajaxUrl' => admin_url('admin-ajax.php'),
        'nonce'   => wp_create_nonce('affiros_dup_cleaner_nonce'),
    ]);
});

/**
 * 管理画面本体
 */
function affiros_dup_cleaner_render_page() {
    if (!current_user_can('manage_options')) return;
    ?>
    <div class="wrap">
        <h1>🧹 重複投稿クリーンアップ</h1>
        <p style="font-size:13px;line-height:1.7">
            同じタイトルで複数回投稿された記事を検出し、<strong>最古の1件を残して残りを削除</strong>します。<br>
            Affiros9 本体 v1.7.27 以前で発生していた予約投稿の重複送信バグの片付け用。<br>
            <strong>このプラグインは単機能</strong>: スキャン → プレビュー → 削除、だけ。他の機能や設定はありません。
        </p>

        <div style="background:#fffbeb;border:1px solid #fbbf24;padding:12px;margin:16px 0;border-radius:4px">
            <strong>⚠️ 注意</strong>
            <ul style="margin:6px 0 0 20px;line-height:1.7;font-size:13px">
                <li>既定は<strong>ゴミ箱送り</strong>（<code>wp_trash_post</code>）。ゴミ箱から復元できます。</li>
                <li>「永久削除」モードはチェックボックスで明示有効化したときだけ。</li>
                <li>対象は <code>post_type = post</code> のみ（固定ページ・カスタム投稿は対象外）。</li>
                <li>ゴミ箱・自動下書きの投稿は最初から対象外です。</li>
                <li>投稿数が多い環境はスキャンに数十秒かかります。</li>
            </ul>
        </div>

        <div style="margin:20px 0">
            <button type="button" id="adc-scan-btn" class="button button-primary">🔍 重複スキャン</button>
            <label style="margin-left:18px;font-size:13px">
                <input type="checkbox" id="adc-permanent">
                永久削除モード（ゴミ箱を経由せず即削除）
            </label>
            <span id="adc-scan-status" style="margin-left:12px;color:#666;font-size:13px"></span>
        </div>

        <div id="adc-result" style="display:none;margin-top:16px">
            <h2 style="margin-bottom:8px">🚨 重複グループ</h2>
            <p id="adc-summary" style="margin:4px 0 12px"></p>

            <div style="margin:0 0 12px">
                <button type="button" id="adc-del-all-btn" class="button button-primary">🗑 全重複を削除（保持は1件のみ）</button>
                <span id="adc-del-status" style="margin-left:12px;font-size:13px"></span>
            </div>

            <table class="wp-list-table widefat striped">
                <thead>
                    <tr>
                        <th>タイトル</th>
                        <th style="width:90px">件数</th>
                        <th>保持 (最古)</th>
                        <th>削除候補</th>
                    </tr>
                </thead>
                <tbody id="adc-result-tbody"></tbody>
            </table>
        </div>
    </div>

    <script>
    (function ($) {
        const ajaxUrl = (window.AffirosDupCleaner && AffirosDupCleaner.ajaxUrl) || ajaxurl;
        const nonce   = (window.AffirosDupCleaner && AffirosDupCleaner.nonce) || '';
        let groups = [];

        $('#adc-scan-btn').on('click', scan);
        $('#adc-del-all-btn').on('click', deleteAll);

        async function scan() {
            $('#adc-scan-btn').prop('disabled', true);
            $('#adc-result').hide();
            $('#adc-result-tbody').empty();
            $('#adc-scan-status').text('スキャン中...');
            try {
                const res = await $.post(ajaxUrl, {
                    action: 'affiros_dup_cleaner_scan',
                    nonce: nonce,
                });
                if (!res || !res.success) {
                    alert('スキャン失敗: ' + (res && res.data ? res.data : 'unknown'));
                    return;
                }
                groups = res.data.groups || [];
                const totalDup = groups.reduce((s, g) => s + g.duplicates.length, 0);
                $('#adc-scan-status').text(
                    `完了: ${res.data.scanned}件チェック / 重複グループ ${groups.length}件 / 削除候補 ${totalDup}件`
                );
                $('#adc-summary').text(`${groups.length} 件の重複グループ（合計 ${totalDup} 件の削除候補）が見つかりました`);
                render();
                if (groups.length) $('#adc-result').show();
            } catch (e) {
                alert('通信エラー: ' + (e.responseText || e.statusText));
            } finally {
                $('#adc-scan-btn').prop('disabled', false);
            }
        }

        function render() {
            const tbody = $('#adc-result-tbody').empty();
            groups.forEach((g, idx) => {
                const keepLink = `<a href="${esc(g.keep.edit_url)}" target="_blank">#${g.keep.id} (${esc(g.keep.date)})</a>`;
                const dupLinks = g.duplicates.map(d =>
                    `<div data-id="${d.id}">
                        <a href="${esc(d.edit_url)}" target="_blank">#${d.id} (${esc(d.date)})</a>
                        <button type="button" class="button button-small adc-del-one" data-id="${d.id}" style="margin-left:8px">🗑 削除</button>
                    </div>`
                ).join('');
                tbody.append(`
                    <tr data-idx="${idx}">
                        <td>${esc(g.title)}</td>
                        <td>${g.duplicates.length + 1}</td>
                        <td>${keepLink}</td>
                        <td>${dupLinks}</td>
                    </tr>
                `);
            });
            tbody.find('.adc-del-one').on('click', function () {
                const id = parseInt($(this).data('id'), 10);
                if (!confirm(`#${id} を削除しますか？`)) return;
                deleteOne(id, $(this));
            });
        }

        async function deleteOne(id, btn) {
            if (btn) btn.prop('disabled', true).text('削除中...');
            const permanent = $('#adc-permanent').is(':checked');
            try {
                const res = await $.post(ajaxUrl, {
                    action: 'affiros_dup_cleaner_delete',
                    nonce: nonce,
                    post_id: id,
                    permanent: permanent ? 1 : 0,
                });
                if (res && res.success) {
                    if (btn) {
                        btn.closest('div').css({opacity: 0.4}).find('button').remove();
                        btn.replaceWith('<span style="color:#16a34a;font-weight:600">✓ 削除済み</span>');
                    }
                    return true;
                }
                alert('削除失敗: ' + (res && res.data ? res.data : 'unknown'));
            } catch (e) {
                alert('通信エラー: ' + (e.responseText || e.statusText));
            } finally {
                if (btn && btn.prop) btn.prop('disabled', false);
            }
            return false;
        }

        async function deleteAll() {
            const ids = [];
            groups.forEach(g => g.duplicates.forEach(d => ids.push(d.id)));
            if (!ids.length) { alert('削除対象がありません'); return; }
            const permanent = $('#adc-permanent').is(':checked');
            const mode = permanent ? '永久削除' : 'ゴミ箱送り';
            if (!confirm(`${ids.length} 件を ${mode} します。よろしいですか？`)) return;
            $('#adc-del-all-btn').prop('disabled', true);
            let done = 0, failed = 0;
            for (const id of ids) {
                $('#adc-del-status').text(`削除中... ${done + failed}/${ids.length}件`);
                const ok = await deleteOne(id, null);
                if (ok) done++; else failed++;
            }
            $('#adc-del-status').text(`完了: 成功 ${done}件 / 失敗 ${failed}件`);
            $('#adc-del-all-btn').prop('disabled', false);
        }

        function esc(s) {
            return String(s == null ? '' : s).replace(/[<>&"]/g, c =>
                ({'<':'&lt;','>':'&gt;','&':'&amp;','"':'&quot;'}[c])
            );
        }
    })(jQuery);
    </script>
    <?php
}

/**
 * AJAX: 重複スキャン
 */
add_action('wp_ajax_affiros_dup_cleaner_scan', function () {
    check_ajax_referer('affiros_dup_cleaner_nonce', 'nonce');
    if (!current_user_can('manage_options')) wp_send_json_error('権限がありません');
    @set_time_limit(120);

    global $wpdb;
    $rows = $wpdb->get_results(
        "SELECT ID, post_title, post_date, post_status
         FROM {$wpdb->posts}
         WHERE post_type = 'post'
           AND post_status NOT IN ('trash', 'auto-draft', 'inherit')
           AND TRIM(post_title) <> ''
         ORDER BY ID ASC",
        ARRAY_A
    );

    $groups = [];
    foreach ($rows as $r) {
        $title = trim($r['post_title']);
        if (!isset($groups[$title])) $groups[$title] = [];
        $groups[$title][] = [
            'id'       => intval($r['ID']),
            'date'     => $r['post_date'],
            'status'   => $r['post_status'],
            'edit_url' => admin_url('post.php?action=edit&post=' . intval($r['ID'])),
        ];
    }

    $result = [];
    foreach ($groups as $title => $posts) {
        if (count($posts) < 2) continue;
        $keep = array_shift($posts);
        $result[] = [
            'title'      => $title,
            'keep'       => $keep,
            'duplicates' => $posts,
        ];
    }
    // 削除候補の多い順に並べる（運用しやすさ）
    usort($result, function ($a, $b) {
        return count($b['duplicates']) - count($a['duplicates']);
    });

    wp_send_json_success([
        'scanned' => count($rows),
        'groups'  => $result,
    ]);
});

/**
 * AJAX: 1件削除（permanent=1 で永久削除、それ以外はゴミ箱送り）
 */
add_action('wp_ajax_affiros_dup_cleaner_delete', function () {
    check_ajax_referer('affiros_dup_cleaner_nonce', 'nonce');
    if (!current_user_can('manage_options')) wp_send_json_error('権限がありません');
    @set_time_limit(60);

    $post_id = intval($_POST['post_id'] ?? 0);
    $permanent = !empty($_POST['permanent']);
    if (!$post_id) wp_send_json_error('post_id が不正です');

    $post = get_post($post_id);
    if (!$post) wp_send_json_error('記事が見つかりません');
    if ($post->post_type !== 'post') wp_send_json_error('post タイプ以外は削除しません');

    if ($permanent) {
        $result = wp_delete_post($post_id, true);
    } else {
        $result = wp_trash_post($post_id);
    }
    if (!$result) wp_send_json_error('削除に失敗しました');
    wp_send_json_success(['message' => $permanent ? '永久削除しました' : 'ゴミ箱に送りました']);
});
