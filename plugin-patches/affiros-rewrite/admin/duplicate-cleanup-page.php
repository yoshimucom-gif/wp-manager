<?php
/**
 * 重複投稿クリーンアップページ
 *
 * Affiros9 本体の予約投稿バグ（v1.7.28 で修正済 / wp_post_id ガード未実装）の
 * せいで、同じタイトルの記事が WP 側に重複投稿されてしまった環境を救済する。
 *
 * 動作:
 *   1. 全 post を post_title でグルーピング
 *   2. 各グループに 2件以上ある場合、最古（=最小 ID）を「保持」、残りを「削除候補」
 *   3. 削除はデフォルトでゴミ箱送り（wp_trash_post）、永久削除はチェックボックスで明示
 *
 * 安全装置:
 *   - スキャン結果を必ず一度プレビュー表示してから実行
 *   - 既にゴミ箱の投稿は対象外
 *   - 削除は1件ずつ AJAX 実行で、進捗を表示
 */

if (!defined('ABSPATH')) exit;

function affiros_rewrite_render_duplicate_cleanup_page() {
    ?>
    <div class="wrap">
        <h1>📦 重複投稿クリーンアップ</h1>
        <p style="font-size:13px;line-height:1.7">
            Affiros9 本体の旧バージョン（〜v1.7.27）で発生していた予約投稿バグにより、
            同じタイトルの記事が複数回 WP に投稿されてしまった環境を救済します。<br>
            <strong>仕様</strong>: 同じタイトルの記事グループのうち、<strong>最古の1件を保持</strong>し、
            残りをゴミ箱に送ります（標準で 30 日後に永久削除）。<br>
            <strong>本体修正</strong>: Affiros9 v1.7.28 以降は <code>wp_post_id</code> ガードが入って
            重複投稿は発生しません。これは過去分の片付け用です。
        </p>

        <div style="background:#fffbeb;border:1px solid #fbbf24;padding:12px;margin:16px 0;border-radius:4px">
            <strong>⚠️ 注意</strong>
            <ul style="margin:6px 0 0 20px;line-height:1.7;font-size:13px">
                <li><strong>削除はゴミ箱送り（wp_trash_post）</strong>。ゴミ箱から復元できます。</li>
                <li>「永久削除」モードはチェックボックスで明示有効化したときだけ。</li>
                <li>対象は <code>post_type=post</code> のみ（固定ページ・カスタム投稿は対象外）。</li>
                <li>既にゴミ箱／自動下書きの投稿は対象外。</li>
                <li>スキャンに 30 秒以上かかる場合があります（投稿数次第）。</li>
            </ul>
        </div>

        <div style="margin:20px 0">
            <button type="button" id="afdc-scan-btn" class="button button-primary">🔍 重複スキャン</button>
            <label style="margin-left:18px;font-size:13px">
                <input type="checkbox" id="afdc-permanent-delete">
                永久削除モード（ゴミ箱を経由せず即削除）
            </label>
            <span id="afdc-scan-status" style="margin-left:12px;color:#666;font-size:13px"></span>
        </div>

        <div id="afdc-result" style="display:none;margin-top:16px">
            <h2 style="margin-bottom:8px">🚨 重複グループ</h2>
            <p id="afdc-summary" style="margin:4px 0 12px"></p>

            <div style="margin:0 0 12px">
                <button type="button" id="afdc-delete-all-btn" class="button button-primary">🗑 全重複を削除（保持は1件のみ）</button>
                <span id="afdc-delete-status" style="margin-left:12px;font-size:13px"></span>
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
                <tbody id="afdc-result-tbody"></tbody>
            </table>
        </div>
    </div>

    <script>
    (function ($) {
        const ajaxUrl = (window.AffirosRewrite && AffirosRewrite.ajaxUrl) || ajaxurl;
        const nonce = (window.AffirosRewrite && AffirosRewrite.nonce) || '';
        let scannedGroups = [];

        $('#afdc-scan-btn').on('click', scan);
        $('#afdc-delete-all-btn').on('click', deleteAll);

        async function scan() {
            $('#afdc-scan-btn').prop('disabled', true);
            $('#afdc-result').hide();
            $('#afdc-result-tbody').empty();
            $('#afdc-scan-status').text('スキャン中...');

            try {
                const res = await $.post(ajaxUrl, {
                    action: 'affiros_rewrite_dup_scan',
                    nonce: nonce,
                });
                if (!res || !res.success) {
                    alert('スキャン失敗: ' + (res && res.data ? res.data : 'unknown'));
                    return;
                }
                scannedGroups = res.data.groups || [];
                const totalDup = scannedGroups.reduce((sum, g) => sum + (g.duplicates.length), 0);
                $('#afdc-scan-status').text(
                    `完了: ${res.data.scanned}件チェック / 重複グループ ${scannedGroups.length}件 / 削除候補 ${totalDup}件`
                );
                $('#afdc-summary').text(`${scannedGroups.length} 件の重複グループ（合計 ${totalDup} 件の削除候補）が見つかりました`);
                renderGroups();
                if (scannedGroups.length) $('#afdc-result').show();
            } catch (e) {
                alert('通信エラー: ' + (e.responseText || e.statusText));
            } finally {
                $('#afdc-scan-btn').prop('disabled', false);
            }
        }

        function renderGroups() {
            const tbody = $('#afdc-result-tbody');
            tbody.empty();
            scannedGroups.forEach((g, idx) => {
                const keepLink = `<a href="${escapeUrl(g.keep.edit_url)}" target="_blank">#${g.keep.id} (${escapeHtml(g.keep.date)})</a>`;
                const dupLinks = g.duplicates.map(d =>
                    `<div data-id="${d.id}">
                        <a href="${escapeUrl(d.edit_url)}" target="_blank">#${d.id} (${escapeHtml(d.date)})</a>
                        <button type="button" class="button button-small afdc-del-one" data-id="${d.id}" style="margin-left:8px">🗑 削除</button>
                    </div>`
                ).join('');
                tbody.append(`
                    <tr data-idx="${idx}">
                        <td>${escapeHtml(g.title)}</td>
                        <td>${g.duplicates.length + 1}</td>
                        <td>${keepLink}</td>
                        <td>${dupLinks}</td>
                    </tr>
                `);
            });
            tbody.find('.afdc-del-one').on('click', function () {
                const id = parseInt($(this).data('id'), 10);
                if (!confirm(`#${id} を削除しますか？`)) return;
                deleteOne(id, $(this));
            });
        }

        async function deleteOne(id, btn) {
            if (btn) btn.prop('disabled', true).text('削除中...');
            const permanent = $('#afdc-permanent-delete').is(':checked');
            try {
                const res = await $.post(ajaxUrl, {
                    action: 'affiros_rewrite_dup_delete',
                    nonce: nonce,
                    post_id: id,
                    permanent: permanent ? 1 : 0,
                });
                if (res && res.success) {
                    if (btn) btn.closest('div').css({opacity: 0.4}).find('button').remove();
                    if (btn) btn.replaceWith(`<span style="color:#16a34a;font-weight:600">✓ 削除済み</span>`);
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
            scannedGroups.forEach(g => g.duplicates.forEach(d => ids.push(d.id)));
            if (!ids.length) { alert('削除対象がありません'); return; }
            const permanent = $('#afdc-permanent-delete').is(':checked');
            const mode = permanent ? '永久削除' : 'ゴミ箱送り';
            if (!confirm(`${ids.length} 件を ${mode} します。よろしいですか？`)) return;

            $('#afdc-delete-all-btn').prop('disabled', true);
            let done = 0, failed = 0;
            for (const id of ids) {
                $('#afdc-delete-status').text(`削除中... ${done + failed}/${ids.length}件`);
                const ok = await deleteOne(id, null);
                if (ok) done++; else failed++;
            }
            $('#afdc-delete-status').text(`完了: 成功 ${done}件 / 失敗 ${failed}件`);
            $('#afdc-delete-all-btn').prop('disabled', false);
        }

        function escapeHtml(s) {
            return String(s == null ? '' : s).replace(/[<>&"]/g, c =>
                ({'<':'&lt;','>':'&gt;','&':'&amp;','"':'&quot;'}[c])
            );
        }
        function escapeUrl(s) { return escapeHtml(s); }
    })(jQuery);
    </script>
    <?php
}
