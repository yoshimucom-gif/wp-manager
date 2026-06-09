<?php
/**
 * リビジョン復元ページ
 *
 * リライト済み記事を WP のリビジョンから「リライト直前」の状態に
 * 一括で戻す UI。
 */

if (!defined('ABSPATH')) exit;

function affiros_rewrite_render_restore_page() {
    if (!current_user_can('manage_options')) {
        wp_die(__('権限がありません', 'affiros-rewrite'));
    }
    ?>
    <div class="wrap">
        <h1>⏮ リビジョン復元</h1>

        <div class="notice notice-warning" style="padding:12px 16px;margin:14px 0">
            <p style="margin:0 0 6px"><strong>⚠️ リビジョン復元の注意点</strong></p>
            <ul style="margin:0 0 0 18px;list-style:disc">
                <li>WordPress 標準のリビジョン機能を使って「リライト前」の状態に戻します</li>
                <li><strong>リライト後に手動編集や商品挿入を行った変更は失われます</strong>（リライト前の状態にリセットされるため）</li>
                <li>復元は1件ごとに即時反映されます。元に戻したくない場合は実行前にリビジョンプレビューでご確認ください</li>
                <li>WP のリビジョン保存が無効化されているサイト（<code>WP_POST_REVISIONS = false</code>）では復元できません</li>
                <li><strong>復元モード</strong>:
                    「<strong>1回分戻す</strong>」= 最新リライトの直前に戻る（複数回リライトしている記事は段階的に戻る）／
                    「<strong>最古まで戻す</strong>」= リライト履歴より前で最も古いリビジョンに戻る（複数回リライトの全部を一気に取り消す）／
                    「<strong>指定日時より前</strong>」= 指定した日時以前で最新のリビジョンに戻す（リライト履歴メタに依存しないため、履歴が消えた記事や複数回リライトした記事に有効）</li>
            </ul>
        </div>

        <p>
            <label style="margin-right:14px"><strong>復元モード:</strong>
                <select id="affiros-restore-mode" style="margin-left:6px">
                    <option value="one_step" selected>1回分戻す（最新リライトの直前）</option>
                    <option value="oldest">最古まで戻す（リライトをすべて取り消す）</option>
                    <option value="before_date">指定日時より前に戻す（時期指定）</option>
                </select>
            </label>
            <span id="affiros-restore-date-wrap" style="margin-left:14px;display:none">
                <label><strong>基準日時:</strong>
                    <input type="datetime-local" id="affiros-restore-target-date" style="margin-left:6px" value="2025-12-31T23:59">
                </label>
                <span style="color:#666;font-size:11px;margin-left:6px">（この日時以前で最新のリビジョンに戻します）</span>
            </span>
        </p>

        <p>
            <button type="button" class="button button-primary" id="affiros-restore-load">📋 リライト済み記事を読み込む</button>
            <button type="button" class="button button-secondary" id="affiros-restore-bulk" disabled style="margin-left:8px">⏮ 選択した記事をまとめて復元</button>
            <button type="button" class="button button-danger" id="affiros-restore-all" style="margin-left:8px;background:#d63638;color:#fff;border-color:#d63638">🗂 全リライト履歴を一括復元（全件）</button>
            <span id="affiros-restore-bulk-count" style="margin-left:8px;color:#666"></span>
        </p>

        <div id="affiros-restore-status" style="margin:12px 0;padding:8px 12px;border-radius:4px;display:none"></div>

        <table class="wp-list-table widefat fixed striped" id="affiros-restore-table" style="display:none">
            <thead>
                <tr>
                    <th style="width:30px"><input type="checkbox" id="affiros-restore-checkall"></th>
                    <th style="width:60px">ID</th>
                    <th>タイトル</th>
                    <th style="width:90px">状態</th>
                    <th style="width:80px">リライト回数</th>
                    <th style="width:130px">最終リライト</th>
                    <th style="width:100px">リビジョン数</th>
                    <th style="width:180px">操作</th>
                </tr>
            </thead>
            <tbody id="affiros-restore-tbody"></tbody>
        </table>

        <div id="affiros-restore-pagination" style="margin-top:12px"></div>
    </div>

    <script>
    (function($){
        const ajaxUrl = '<?php echo esc_js(admin_url('admin-ajax.php')); ?>';
        const nonce = '<?php echo esc_js(wp_create_nonce('affiros_rewrite_nonce')); ?>';
        let currentPage = 1;
        let totalPages = 1;

        function setStatus(text, type) {
            const $s = $('#affiros-restore-status');
            const colors = {
                info: ['#0a5d9e', '#e8f3fa'],
                ok: ['#0a7a2f', '#e8f9ee'],
                warn: ['#a06000', '#fef4e0'],
                err: ['#c00', '#fde8e8'],
            };
            const [color, bg] = colors[type] || colors.info;
            $s.show().css({color, background: bg, whiteSpace: 'pre-line'}).text(text);
        }

        function updateBulkCount() {
            const n = $('.affiros-restore-row:checked').length;
            $('#affiros-restore-bulk-count').text(n > 0 ? '(' + n + '件選択中)' : '');
            $('#affiros-restore-bulk').prop('disabled', n === 0);
        }

        function loadList(page) {
            page = page || 1;
            currentPage = page;
            setStatus('読み込み中...', 'info');
            const mode = currentMode();
            const action = mode === 'before_date'
                ? 'affiros_rewrite_restore_before_date_list'
                : 'affiros_rewrite_restore_list';
            const payload = {
                action: action,
                nonce: nonce,
                page: page,
                per_page: 20,
            };
            if (mode === 'before_date') {
                payload.target_date = currentTargetDate();
            }
            $.post(ajaxUrl, payload).done(function(resp){
                if (!resp.success) {
                    setStatus('エラー: ' + (resp.data?.message || '不明'), 'err');
                    return;
                }
                const data = resp.data;
                totalPages = data.total_pages || 1;
                renderTable(data.items);
                renderPagination(data.total, data.page, data.total_pages);
                if (data.items.length === 0) {
                    setStatus('リライト履歴がある記事はありません', 'info');
                    $('#affiros-restore-table').hide();
                } else {
                    setStatus(data.total + ' 件のリライト済み記事 (' + page + '/' + totalPages + ' ページ)', 'info');
                    $('#affiros-restore-table').show();
                }
            }).fail(function(){
                setStatus('通信エラー', 'err');
            });
        }

        function renderTable(items) {
            const rows = items.map(function(p){
                const hasRev = p.has_revision;
                const revBadge = hasRev
                    ? '<span style="color:#0a7a2f;font-weight:600">' + p.revisions + '個</span>'
                    : '<span style="color:#c00;font-weight:600">なし</span>';
                const disabled = !hasRev ? 'disabled' : '';
                const cb = hasRev
                    ? '<input type="checkbox" class="affiros-restore-row" value="' + p.id + '">'
                    : '';
                return '<tr data-id="' + p.id + '">'
                    + '<td>' + cb + '</td>'
                    + '<td>' + p.id + '</td>'
                    + '<td><a href="' + p.edit_url + '" target="_blank">' + escapeHtml(p.title) + '</a></td>'
                    + '<td>' + p.status + '</td>'
                    + '<td>' + p.rewrite_count + '</td>'
                    + '<td>' + p.rewrite_last + '</td>'
                    + '<td>' + revBadge + '</td>'
                    + '<td>'
                    +   '<button class="button button-small affiros-restore-preview" ' + disabled + '>👁 確認</button> '
                    +   '<button class="button button-small button-primary affiros-restore-one" ' + disabled + '>⏮ 復元</button>'
                    + '</td>'
                    + '</tr>';
            }).join('');
            $('#affiros-restore-tbody').html(rows);
            updateBulkCount();
        }

        function renderPagination(total, page, totalPg) {
            const $p = $('#affiros-restore-pagination');
            if (totalPg <= 1) { $p.empty(); return; }
            let html = '<div style="display:flex;gap:6px;align-items:center">';
            html += '<button class="button" id="affiros-restore-prev" ' + (page <= 1 ? 'disabled' : '') + '>← 前</button>';
            html += '<span>' + page + ' / ' + totalPg + '</span>';
            html += '<button class="button" id="affiros-restore-next" ' + (page >= totalPg ? 'disabled' : '') + '>次 →</button>';
            html += '</div>';
            $p.html(html);
        }

        function escapeHtml(s) {
            return String(s == null ? '' : s)
                .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
        }

        // ボタン: 読み込み
        $('#affiros-restore-load').on('click', function(){ loadList(1); });

        // ボタン: 全選択
        $(document).on('change', '#affiros-restore-checkall', function(){
            const checked = $(this).is(':checked');
            $('.affiros-restore-row').prop('checked', checked);
            updateBulkCount();
        });
        $(document).on('change', '.affiros-restore-row', updateBulkCount);

        // ページネーション
        $(document).on('click', '#affiros-restore-prev', function(){
            if (currentPage > 1) loadList(currentPage - 1);
        });
        $(document).on('click', '#affiros-restore-next', function(){
            if (currentPage < totalPages) loadList(currentPage + 1);
        });

        // ボタン: 個別プレビュー
        $(document).on('click', '.affiros-restore-preview', function(){
            const $btn = $(this);
            const id = $btn.closest('tr').data('id');
            $btn.prop('disabled', true).text('確認中...');
            $.post(ajaxUrl, {
                action: 'affiros_rewrite_restore_preview',
                nonce: nonce,
                post_id: id,
            }).done(function(resp){
                if (!resp.success) {
                    alert('プレビュー失敗: ' + (resp.data?.message || '不明'));
                    return;
                }
                const d = resp.data;
                alert(
                    'リビジョン復元プレビュー (ID:' + d.post_id + ')\n\n'
                    + '対象リビジョン: ' + d.target_modified + '\n'
                    + '現在の更新日: ' + d.current_modified + '\n'
                    + '最終リライト: ' + d.rewrite_last + '\n\n'
                    + '【タイトル】\n'
                    + '前: ' + d.title_before + '\n'
                    + '後: ' + d.title_after + '\n\n'
                    + '【本文文字数】\n'
                    + '前: ' + d.content_chars_before + '字\n'
                    + '後: ' + d.content_chars_after + '字\n\n'
                    + '「復元」を押すと、対象リビジョンの状態に戻ります。'
                );
            }).always(function(){
                $btn.prop('disabled', false).text('👁 確認');
            });
        });

        function currentMode() {
            return $('#affiros-restore-mode').val() || 'one_step';
        }

        function modeLabel(mode) {
            if (mode === 'oldest') return '最古まで戻す（全リライト取り消し）';
            if (mode === 'before_date') return '指定日時より前に戻す（時期指定）';
            return '1回分戻す（最新リライト直前）';
        }

        function currentTargetDate() {
            const v = $('#affiros-restore-target-date').val();
            if (!v) return '';
            // "YYYY-MM-DDTHH:MM" → "YYYY-MM-DD HH:MM:00"
            return v.replace('T', ' ') + ':00';
        }

        // モード切替で日時 input の表示・非表示と「全件復元」ボタンのラベル変更
        $(document).on('change', '#affiros-restore-mode', function(){
            const mode = $(this).val();
            $('#affiros-restore-date-wrap').toggle(mode === 'before_date');
            if (mode === 'before_date') {
                $('#affiros-restore-all').text('🗂 指定日時より前のリビジョンに一括復元（全件）');
                $('#affiros-restore-load').text('📋 指定日時より後に更新された記事を読み込む');
            } else {
                $('#affiros-restore-all').text('🗂 全リライト履歴を一括復元（全件）');
                $('#affiros-restore-load').text('📋 リライト済み記事を読み込む');
            }
        });

        // ボタン: 個別復元
        $(document).on('click', '.affiros-restore-one', function(){
            const $btn = $(this);
            const id = $btn.closest('tr').data('id');
            const mode = currentMode();
            const targetDate = currentTargetDate();
            if (!confirm('ID:' + id + ' を「' + modeLabel(mode) + '」で復元します。よろしいですか？')) return;
            $btn.prop('disabled', true).text('復元中...');
            $.post(ajaxUrl, {
                action: 'affiros_rewrite_restore_one',
                nonce: nonce,
                post_id: id,
                mode: mode,
                target_date: targetDate,
            }).done(function(resp){
                if (!resp.success) {
                    alert('復元失敗: ' + (resp.data?.message || '不明'));
                    $btn.prop('disabled', false).text('⏮ 復元');
                    return;
                }
                $btn.closest('tr').css('background', '#e8f9ee').find('td:last').html('<span style="color:#0a7a2f">✅ 復元完了</span>');
            }).fail(function(){
                alert('通信エラー');
                $btn.prop('disabled', false).text('⏮ 復元');
            });
        });

        // 共通バッチ復元処理
        function runBatchRestore(ids, $btn, btnOrigText) {
            const total = ids.length;
            const mode = currentMode();
            const targetDate = currentTargetDate();
            let done = 0, ok = 0, ng = 0;
            const errorSamples = [];
            $btn.prop('disabled', true).text('実行中... 0/' + total);
            const next = function() {
                if (done >= total) {
                    let msg = '一括復元完了 (' + modeLabel(mode) + '): 成功 ' + ok + ' / 失敗 ' + ng;
                    if (ng > 0 && errorSamples.length) {
                        msg += '\n失敗の代表理由（先頭3件）:\n  - ' + errorSamples.slice(0, 3).join('\n  - ');
                    }
                    setStatus(msg, ok && !ng ? 'ok' : 'warn');
                    $btn.prop('disabled', false).text(btnOrigText);
                    return;
                }
                const id = ids[done];
                $.post(ajaxUrl, {
                    action: 'affiros_rewrite_restore_one',
                    nonce: nonce,
                    post_id: id,
                    mode: mode,
                    target_date: targetDate,
                }).done(function(resp){
                    const $row = $('tr[data-id="' + id + '"]');
                    if (resp.success) {
                        ok++;
                        $row.css('background', '#e8f9ee').find('td:last').html('<span style="color:#0a7a2f">✅ 復元完了</span>');
                    } else {
                        ng++;
                        const errMsg = resp.data?.message || '失敗';
                        if (errorSamples.length < 5 && !errorSamples.includes(errMsg)) {
                            errorSamples.push(errMsg);
                        }
                        $row.css('background', '#fde8e8').find('td:last').html('<span style="color:#c00">❌ ' + errMsg + '</span>');
                    }
                }).fail(function(){
                    ng++;
                    if (errorSamples.length < 5 && !errorSamples.includes('通信エラー')) {
                        errorSamples.push('通信エラー');
                    }
                }).always(function(){
                    done++;
                    $btn.text('実行中... ' + done + '/' + total);
                    setTimeout(next, 300); // WP DB 負荷軽減
                });
            };
            next();
        }

        // ボタン: 選択した記事を一括復元
        $('#affiros-restore-bulk').on('click', function(){
            const ids = $('.affiros-restore-row:checked').map(function(){ return $(this).val(); }).get();
            if (!ids.length) return;
            const mode = currentMode();
            if (!confirm(ids.length + ' 件の記事を「' + modeLabel(mode) + '」で復元します。よろしいですか？')) return;
            runBatchRestore(ids, $(this), '⏮ 選択した記事をまとめて復元');
        });

        // ボタン: 全件復元
        $('#affiros-restore-all').on('click', function(){
            const $btn = $(this);
            const mode = currentMode();
            const origText = $btn.text();
            const action = mode === 'before_date'
                ? 'affiros_rewrite_restore_before_date_all_ids'
                : 'affiros_rewrite_restore_all_ids';
            const payload = { action: action, nonce: nonce };
            if (mode === 'before_date') {
                payload.target_date = currentTargetDate();
            }
            $btn.prop('disabled', true).text('対象記事を取得中...');
            $.post(ajaxUrl, payload).done(function(resp){
                if (!resp.success) {
                    alert('対象記事の取得に失敗: ' + (resp.data?.message || '不明'));
                    $btn.prop('disabled', false).text(origText);
                    return;
                }
                const ids = resp.data.ids || [];
                const total = ids.length;
                if (!total) {
                    setStatus('リライト履歴がある記事はありません', 'info');
                    $btn.prop('disabled', false).text(origText);
                    return;
                }
                const ok = confirm(
                    total + ' 件のリライト履歴がある記事を全部「' + modeLabel(mode) + '」で復元します。\n\n'
                    + '⚠️ この操作は取り消せません。リライト後の手動編集や商品挿入の変更も失われます。\n\n'
                    + 'よろしいですか？'
                );
                if (!ok) {
                    $btn.prop('disabled', false).text(origText);
                    return;
                }
                // 一覧テーブルを再読み込みしておく（処理中の進捗をテーブルでも表示するため）
                loadList(1);
                runBatchRestore(ids, $btn, origText);
            }).fail(function(){
                alert('通信エラー');
                $btn.prop('disabled', false).text(origText);
            });
        });
    })(jQuery);
    </script>
    <?php
}
