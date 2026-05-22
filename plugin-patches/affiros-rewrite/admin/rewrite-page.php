<?php
/**
 * リライト実行画面（投稿一覧 + 単記事・一括リライト操作）
 */

if (!defined('ABSPATH')) exit;

function affiros_rewrite_render_rewrite_page() {
    if (!current_user_can('manage_options')) return;

    $settings = affiros_rewrite_get_settings();
    $has_api_key = !empty($settings['claude_api_key']);
    $categories = Affiros_Rewrite_Post_Fetcher::get_categories();
    ?>
    <div class="wrap affiros-wrap">
        <h1>Affiros リライト</h1>
        <p class="description">
            WP_Query で記事を内部取得するため、ホスティングの WAF / 海外IP制限の影響を受けません（403回避）。
        </p>

        <?php if (!$has_api_key): ?>
            <div class="notice notice-warning">
                <p>
                    Claude APIキーが未設定です。
                    <a href="<?php echo esc_url(admin_url('admin.php?page=affiros-rewrite-settings')); ?>">設定画面</a>
                    で入力してください。
                </p>
            </div>
        <?php endif; ?>

        <?php if (defined('WP_POST_REVISIONS') && WP_POST_REVISIONS === false): ?>
            <div class="notice notice-warning">
                <p>
                    このサイトはリビジョンが無効（<code>WP_POST_REVISIONS</code> が <code>false</code>）です。
                    リライトで上書きした記事は<strong>元に戻せません</strong>。実行前に必ずバックアップしてください。
                </p>
            </div>
        <?php endif; ?>

        <div class="affiros-rewrite-toolbar" style="display:flex;gap:10px;align-items:center;margin:18px 0;flex-wrap:wrap;">
            <input type="text" id="affiros-search" placeholder="タイトル・本文を検索..." style="flex:1;min-width:240px;padding:6px 10px;">
            <select id="affiros-category" style="padding:6px;">
                <option value="0">全カテゴリー</option>
                <?php foreach ($categories as $c): ?>
                    <option value="<?php echo intval($c['id']); ?>"><?php echo esc_html($c['name']); ?> (<?php echo intval($c['count']); ?>)</option>
                <?php endforeach; ?>
            </select>
            <select id="affiros-status" style="padding:6px;">
                <option value="publish">公開済</option>
                <option value="draft">下書き</option>
                <option value="any">すべて</option>
            </select>
            <select id="affiros-per-page" style="padding:6px;">
                <option value="20">20件/ページ</option>
                <option value="50">50件/ページ</option>
                <option value="100">100件/ページ</option>
            </select>
            <button type="button" class="button button-primary" id="affiros-fetch-btn">投稿を取得</button>
        </div>

        <!-- リライト共通オプション -->
        <div style="margin-bottom:14px;padding:12px;background:#fafafa;border:1px solid #e0e0e0;border-radius:4px;">
            <strong style="display:block;margin-bottom:8px;">リライト オプション</strong>
            <div style="display:flex;gap:12px;align-items:center;flex-wrap:wrap;">
                <label>
                    記事タイプ:
                    <select id="affiros-article-type">
                        <option value="">— 指定なし</option>
                        <option value="ranking">ランキング</option>
                        <option value="brand">商標</option>
                        <option value="column">コラム</option>
                    </select>
                </label>
                <label>
                    <input type="checkbox" id="affiros-insert-markers">
                    商品カードマーカーを挿入する（記事タイプ別の規則）
                </label>
            </div>
        </div>

        <!-- 一括操作バー -->
        <div id="affiros-bulk-bar" style="display:none;margin-bottom:10px;padding:10px;background:#f0f6fc;border-left:4px solid #2271b1;">
            <strong><span id="affiros-bulk-count">0</span></strong> 件選択中
            <button type="button" class="button button-primary" id="affiros-bulk-rewrite-btn" style="margin-left:12px;" <?php echo $has_api_key ? '' : 'disabled'; ?>>
                ✍ 選択した記事を一括リライト
            </button>
            <?php if ($has_api_key): ?>
                <span class="description" style="margin-left:10px;">上のオプションが適用されます。確認画面を経由せず順次実行</span>
            <?php else: ?>
                <span class="description" style="margin-left:10px;color:#b32d2e;">
                    ⚠ Claude APIキーが未設定のため実行できません。
                    <a href="<?php echo esc_url(admin_url('admin.php?page=affiros-rewrite-settings')); ?>">設定画面で入力 →</a>
                </span>
            <?php endif; ?>
        </div>

        <div id="affiros-result" style="background:#fff;border:1px solid #ccd0d4;padding:0;min-height:200px;">
            <div style="padding:40px;text-align:center;color:#888;">
                「投稿を取得」ボタンを押すと、このサイトの記事一覧が表示されます。
            </div>
        </div>

        <div id="affiros-pagination" style="margin-top:12px;text-align:center;"></div>
    </div>

    <!-- リライト結果モーダル（単記事用） -->
    <div id="affiros-modal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:99999;align-items:center;justify-content:center;">
        <div style="background:#fff;width:90%;max-width:1200px;max-height:90vh;display:flex;flex-direction:column;border-radius:6px;overflow:hidden;">
            <div style="padding:12px 18px;border-bottom:1px solid #ddd;display:flex;justify-content:space-between;align-items:center;">
                <strong id="affiros-modal-title">リライト結果</strong>
                <button type="button" class="button" id="affiros-modal-close">×</button>
            </div>
            <div style="padding:14px 18px;display:grid;grid-template-columns:1fr 1fr;gap:14px;overflow:auto;flex:1;">
                <div>
                    <div style="font-weight:600;margin-bottom:6px;color:#666;">元記事</div>
                    <input type="text" id="affiros-modal-orig-title" readonly style="width:100%;margin-bottom:6px;background:#f6f7f7;">
                    <textarea id="affiros-modal-orig-content" readonly style="width:100%;height:50vh;background:#f6f7f7;font-family:monospace;font-size:11px;"></textarea>
                </div>
                <div>
                    <div style="font-weight:600;margin-bottom:6px;color:#2271b1;">リライト結果（編集可）</div>
                    <input type="text" id="affiros-modal-new-title" style="width:100%;margin-bottom:6px;">
                    <textarea id="affiros-modal-new-content" style="width:100%;height:50vh;font-family:monospace;font-size:11px;"></textarea>
                </div>
            </div>
            <div style="padding:12px 18px;border-top:1px solid #ddd;display:flex;justify-content:space-between;align-items:center;background:#fafafa;">
                <span id="affiros-modal-usage" style="color:#666;font-size:11px;"></span>
                <div>
                    <button type="button" class="button" id="affiros-modal-discard">破棄</button>
                    <button type="button" class="button button-primary" id="affiros-modal-save">WP投稿に上書き保存</button>
                </div>
            </div>
        </div>
    </div>

    <!-- 一括リライト進捗モーダル -->
    <div id="affiros-bulk-modal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:99999;align-items:center;justify-content:center;">
        <div style="background:#fff;width:90%;max-width:680px;max-height:90vh;display:flex;flex-direction:column;border-radius:6px;overflow:hidden;">
            <div style="padding:12px 18px;border-bottom:1px solid #ddd;display:flex;justify-content:space-between;align-items:center;">
                <strong>一括リライト</strong>
                <button type="button" class="button" id="affiros-bulk-close" style="display:none;">閉じる</button>
            </div>
            <div style="padding:14px 18px;overflow:auto;flex:1;">
                <div style="margin-bottom:10px;">
                    <span id="affiros-bulk-status">準備中...</span>
                    <span style="float:right;color:#666;"><span id="affiros-bulk-done">0</span> / <span id="affiros-bulk-total">0</span></span>
                </div>
                <div style="height:8px;background:#eee;border-radius:4px;overflow:hidden;margin-bottom:14px;">
                    <div id="affiros-bulk-progress" style="height:100%;background:#2271b1;width:0%;transition:width .2s;"></div>
                </div>
                <div id="affiros-bulk-log" style="font-family:monospace;font-size:11px;background:#f6f7f7;padding:10px;height:300px;overflow:auto;border:1px solid #ddd;"></div>
            </div>
            <div style="padding:12px 18px;border-top:1px solid #ddd;background:#fafafa;text-align:right;">
                <button type="button" class="button" id="affiros-bulk-cancel">中止</button>
            </div>
        </div>
    </div>

    <script>
    jQuery(function($) {
        let currentPage = 1;
        let bulkAbort = false;

        // --- 投稿取得 ---
        function fetchPosts(page) {
            currentPage = page || 1;
            $('#affiros-result').html('<div style="padding:40px;text-align:center;">読み込み中...</div>');
            $.post(AffirosRewrite.ajaxUrl, {
                action: 'affiros_rewrite_fetch_posts',
                nonce: AffirosRewrite.nonce,
                page: currentPage,
                per_page: $('#affiros-per-page').val(),
                search: $('#affiros-search').val(),
                category: $('#affiros-category').val(),
                status: $('#affiros-status').val(),
            }).done(function(resp) {
                if (!resp.success) {
                    $('#affiros-result').html('<div style="padding:40px;color:#c00;">エラー: ' + (resp.data?.message || '不明') + '</div>');
                    return;
                }
                renderTable(resp.data);
            }).fail(function(xhr) {
                $('#affiros-result').html('<div style="padding:40px;color:#c00;">通信エラー: ' + xhr.status + '</div>');
            });
        }

        function renderTable(data) {
            const items = data.items || [];
            if (!items.length) {
                $('#affiros-result').html('<div style="padding:40px;text-align:center;color:#888;">該当する記事がありません。</div>');
                $('#affiros-pagination').html('');
                updateBulkBar();
                return;
            }
            let html = '<table class="wp-list-table widefat striped affiros-post-table"><thead><tr>';
            html += '<th style="width:32px;"><input type="checkbox" id="affiros-check-all"></th>';
            html += '<th>タイトル</th><th style="width:120px;">カテゴリー</th><th style="width:70px;">文字数</th>';
            html += '<th style="width:90px;">更新日</th><th style="width:220px;">操作</th>';
            html += '</tr></thead><tbody>';
            items.forEach(function(p) {
                html += '<tr data-post-id="' + p.id + '">';
                html += '<td><input type="checkbox" class="affiros-pick" value="' + p.id + '"></td>';
                html += '<td><strong>' + escapeHtml(p.title) + '</strong>';
                if (p.excerpt) html += '<div style="font-size:11px;color:#888;margin-top:4px;">' + escapeHtml(p.excerpt.substr(0, 80)) + '...</div>';
                html += '</td>';
                html += '<td>' + escapeHtml(p.category) + '</td>';
                html += '<td>' + p.word_count + '</td>';
                html += '<td>' + escapeHtml(p.modified) + '</td>';
                html += '<td>';
                html += '<button type="button" class="button button-primary button-small affiros-rewrite-btn" data-post-id="' + p.id + '">✍ リライト</button> ';
                html += '<a href="' + p.edit_link + '" target="_blank" class="button button-small">編集</a>';
                html += '</td>';
                html += '</tr>';
            });
            html += '</tbody></table>';
            html += '<div style="padding:10px;color:#666;">' + data.total + '件中 ' + items.length + '件表示</div>';
            $('#affiros-result').html(html);
            renderPagination(data);
            updateBulkBar();
        }

        function renderPagination(data) {
            const totalPages = data.total_pages, page = data.page;
            if (totalPages <= 1) { $('#affiros-pagination').html(''); return; }
            let html = '';
            if (page > 1) html += '<button class="button" data-page="' + (page - 1) + '">← 前</button> ';
            html += '<span style="margin:0 10px;">' + page + ' / ' + totalPages + '</span>';
            if (page < totalPages) html += '<button class="button" data-page="' + (page + 1) + '">次 →</button>';
            $('#affiros-pagination').html(html);
            $('#affiros-pagination button').on('click', function() { fetchPosts(parseInt($(this).data('page'), 10)); });
        }

        function escapeHtml(s) {
            return String(s == null ? '' : s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
        }

        // --- 共通オプション取得 ---
        function rewriteOpts() {
            return {
                article_type: $('#affiros-article-type').val() || '',
                insert_markers: $('#affiros-insert-markers').is(':checked') ? '1' : '0',
            };
        }

        // --- 単記事リライト ---
        function runSingleRewrite(postId) {
            const $row = $('tr[data-post-id="' + postId + '"]');
            const $btn = $row.find('.affiros-rewrite-btn');
            const origLabel = $btn.html();
            $btn.prop('disabled', true).html('リライト中...');

            return $.post(AffirosRewrite.ajaxUrl, Object.assign({
                action: 'affiros_rewrite_run_single',
                nonce: AffirosRewrite.nonce,
                post_id: postId,
            }, rewriteOpts())).done(function(resp) {
                if (!resp.success) {
                    alert('リライトに失敗しました: ' + (resp.data?.message || '不明'));
                    return;
                }
                openResultModal(resp.data);
            }).fail(function(xhr) {
                alert('通信エラー: HTTP ' + xhr.status);
            }).always(function() {
                $btn.prop('disabled', false).html(origLabel);
            });
        }

        function openResultModal(data) {
            $('#affiros-modal-title').text('リライト結果: ' + (data.rewritten_title || ''));
            $('#affiros-modal-orig-title').val(data.original_title || '');
            $('#affiros-modal-orig-content').val(data.original_content || '');
            $('#affiros-modal-new-title').val(data.rewritten_title || '');
            $('#affiros-modal-new-content').val(data.rewritten_content || '');
            const usage = data.usage || {};
            const tokens = (usage.input_tokens || 0) + '/' + (usage.output_tokens || 0) + ' tokens (in/out)';
            const tags = [];
            if (data.article_type) tags.push('タイプ: ' + data.article_type);
            if (data.markers_inserted) tags.push('マーカー挿入: ✓');
            const tagsLine = tags.length ? ' / ' + tags.join(' / ') : '';
            $('#affiros-modal-usage').text('モデル: ' + (data.model || '?') + ' / ' + tokens + tagsLine);
            $('#affiros-modal').data('post-id', data.post_id).css('display', 'flex');
        }

        function closeResultModal() { $('#affiros-modal').hide(); }

        function saveModal() {
            const postId = $('#affiros-modal').data('post-id');
            const title = $('#affiros-modal-new-title').val();
            const content = $('#affiros-modal-new-content').val();
            if (!content.trim()) { alert('本文が空です'); return; }
            if (!confirm('この内容でWordPress投稿を上書き保存します。\n（WordPressのリビジョン機能で元に戻せます）\n\nよろしいですか?')) return;
            const $btn = $('#affiros-modal-save').prop('disabled', true).text('保存中...');
            $.post(AffirosRewrite.ajaxUrl, {
                action: 'affiros_rewrite_save',
                nonce: AffirosRewrite.nonce,
                post_id: postId,
                title: title,
                content: content,
            }).done(function(resp) {
                if (!resp.success) {
                    alert('保存失敗: ' + (resp.data?.message || '不明'));
                    return;
                }
                alert('保存しました。\n編集画面: ' + resp.data.edit_link);
                closeResultModal();
                fetchPosts(currentPage);
            }).fail(function(xhr) {
                alert('通信エラー: HTTP ' + xhr.status);
            }).always(function() {
                $btn.prop('disabled', false).text('WP投稿に上書き保存');
            });
        }

        // --- 一括リライト ---
        function updateBulkBar() {
            const n = $('.affiros-pick:checked').length;
            $('#affiros-bulk-count').text(n);
            $('#affiros-bulk-bar').toggle(n > 0);
        }

        async function runBulkRewrite() {
            const ids = $('.affiros-pick:checked').map(function() { return parseInt($(this).val(), 10); }).get();
            if (!ids.length) return;
            if (!confirm(ids.length + '件の記事をリライトし、即座にWordPress投稿へ上書き保存します。\n（リビジョン機能で1件ずつ元に戻せます）\n\n実行しますか?')) return;

            bulkAbort = false;
            $('#affiros-bulk-modal').css('display', 'flex');
            $('#affiros-bulk-total').text(ids.length);
            $('#affiros-bulk-done').text(0);
            $('#affiros-bulk-progress').css('width', '0%');
            $('#affiros-bulk-log').html('');
            $('#affiros-bulk-close').hide();
            $('#affiros-bulk-cancel').show();
            $('#affiros-bulk-status').text('開始しています...');

            let done = 0, succeeded = 0, failed = 0;
            for (const id of ids) {
                if (bulkAbort) {
                    appendBulkLog('中止しました', 'warn');
                    break;
                }
                appendBulkLog('[' + (done + 1) + '/' + ids.length + '] post #' + id + ' リライト中...', 'info');
                $('#affiros-bulk-status').text('[' + (done + 1) + '/' + ids.length + '] post #' + id + ' をリライト中...');

                try {
                    const result = await jqXhrPromise($.post(AffirosRewrite.ajaxUrl, Object.assign({
                        action: 'affiros_rewrite_run_single',
                        nonce: AffirosRewrite.nonce,
                        post_id: id,
                    }, rewriteOpts())));
                    if (!result.success) throw new Error(result.data?.message || 'unknown');

                    const saved = await jqXhrPromise($.post(AffirosRewrite.ajaxUrl, {
                        action: 'affiros_rewrite_save',
                        nonce: AffirosRewrite.nonce,
                        post_id: id,
                        title: result.data.rewritten_title,
                        content: result.data.rewritten_content,
                    }));
                    if (!saved.success) throw new Error(saved.data?.message || 'save failed');
                    appendBulkLog('  ✓ #' + id + ' 保存完了', 'success');
                    succeeded++;
                } catch (e) {
                    appendBulkLog('  ✗ #' + id + ' 失敗: ' + e.message, 'error');
                    failed++;
                }
                done++;
                $('#affiros-bulk-done').text(done);
                $('#affiros-bulk-progress').css('width', (done / ids.length * 100) + '%');
            }

            $('#affiros-bulk-status').text('完了: 成功 ' + succeeded + ' / 失敗 ' + failed + ' / 全 ' + ids.length);
            $('#affiros-bulk-close').show();
            $('#affiros-bulk-cancel').hide();
        }

        function jqXhrPromise(jqXhr) {
            return new Promise(function(resolve, reject) {
                jqXhr.done(resolve).fail(function(xhr) { reject(new Error('HTTP ' + xhr.status)); });
            });
        }

        function appendBulkLog(msg, kind) {
            const colors = { info: '#333', success: '#0a7a2f', error: '#c00', warn: '#a06000' };
            const c = colors[kind] || '#333';
            $('#affiros-bulk-log').append('<div style="color:' + c + ';">' + escapeHtml(msg) + '</div>').scrollTop(99999);
        }

        // --- イベントバインド ---
        $('#affiros-fetch-btn').on('click', function() { fetchPosts(1); });
        $('#affiros-result').on('change', '#affiros-check-all', function() {
            $('.affiros-pick').prop('checked', $(this).prop('checked'));
            updateBulkBar();
        });
        $('#affiros-result').on('change', '.affiros-pick', updateBulkBar);
        $('#affiros-result').on('click', '.affiros-rewrite-btn', function() {
            runSingleRewrite(parseInt($(this).data('post-id'), 10));
        });
        $('#affiros-bulk-rewrite-btn').on('click', runBulkRewrite);

        $('#affiros-modal-close, #affiros-modal-discard').on('click', closeResultModal);
        $('#affiros-modal-save').on('click', saveModal);

        $('#affiros-bulk-close').on('click', function() {
            $('#affiros-bulk-modal').hide();
            fetchPosts(currentPage);
        });
        $('#affiros-bulk-cancel').on('click', function() {
            bulkAbort = true;
            $('#affiros-bulk-status').text('中止中... 現在のリクエスト完了後に停止します');
        });
    });
    </script>
    <?php
}
