/* AIプロダクトインサーター 管理画面JS */

(function($) {
    'use strict';

    $(document).ready(function() {

        // === メタボックス：商品挿入実行 ===
        $('.aipi-run').on('click', function() {
            const $box = $('.aipi-metabox');
            const postId = $box.data('post-id');
            const dryRun = $('#aipi-dry-run').is(':checked');
            const mode = $('input[name=aipi_mode]').val() || 'marker';
            const design = $('input[name=aipi_design]').val() || 'vertical';
            const isReinsert = $(this).text().includes('再挿入');

            let confirmMessage;
            if (dryRun) {
                confirmMessage = 'プレビューモードで商品挿入を実行します。よろしいですか？';
            } else if (isReinsert) {
                confirmMessage = '⚠️ 再挿入を実行します。\n\n' +
                    '前回の挿入後にこの記事を手動編集していた場合、その変更は失われます。\n' +
                    '（バックアップ済みの「マーカー入り原本」から再描画します）\n\n' +
                    '続けますか？';
            } else {
                confirmMessage = '実際に記事本文に商品を挿入して保存します。元の本文はバックアップされます。実行しますか？';
            }
            if (!confirm(confirmMessage)) {
                return;
            }

            $box.find('.aipi-spinner').show();
            $box.find('.aipi-result').hide();
            $box.find('.aipi-run').prop('disabled', true);

            $.post(aiPI.ajaxUrl, {
                action: 'ai_pi_insert',
                nonce: aiPI.nonce,
                post_id: postId,
                dry_run: dryRun ? 'true' : 'false',
                mode: mode,
                design: design,
            }).done(function(response) {
                $box.find('.aipi-spinner').hide();
                $box.find('.aipi-run').prop('disabled', false);

                if (response.success) {
                    const data = response.data;
                    let html = '<div><strong>✅ 処理成功</strong></div>';

                    if (data.products && data.products.length) {
                        html += '<div style="margin-top:6px;"><strong>挿入商品: ' + data.products.length + '個</strong></div>';
                        html += '<ul style="margin:4px 0;padding-left:18px;font-size:11px;">';
                        data.products.forEach(function(p) {
                            const rank = p.rank ? '<strong>' + p.rank + '位</strong> ' : '';
                            html += '<li>' + rank + escapeHtml((p.title || '').substring(0, 50)) + '...</li>';
                        });
                        html += '</ul>';
                    }

                    if (data.criteria) {
                        html += '<div style="margin-top:6px;font-size:11px;color:#666;">判断軸: ' + escapeHtml(data.criteria) + '</div>';
                    }

                    if (data.keywords && data.keywords.length) {
                        html += '<div style="margin-top:4px;font-size:11px;color:#666;">検索キーワード: ' + data.keywords.map(escapeHtml).join(', ') + '</div>';
                    }

                    if (data.usage) {
                        html += '<div style="margin-top:6px;font-size:11px;color:#666;">トークン: 入力' + (data.usage.input_tokens || 0) + ' / 出力' + (data.usage.output_tokens || 0) + '</div>';
                    }

                    if (dryRun && data.preview) {
                        html += '<div style="margin-top:10px;"><strong>プレビュー（先頭部分）:</strong></div>';
                        html += '<textarea readonly style="width:100%;height:200px;font-family:Consolas,monospace;font-size:10px;margin-top:4px;">' + escapeHtml(data.preview) + '</textarea>';
                    } else if (!dryRun) {
                        html += '<div style="margin-top:10px;color:#27ae60;">✅ 保存完了</div>';
                        setTimeout(function() {
                            if (confirm('処理が完了しました。ページを再読み込みして結果を確認しますか？')) {
                                location.reload();
                            }
                        }, 500);
                    }

                    $box.find('.aipi-result-body').html(html);
                    $box.find('.aipi-result').show();
                } else {
                    alert('エラー: ' + (response.data.message || '不明なエラー'));
                }
            }).fail(function(xhr) {
                $box.find('.aipi-spinner').hide();
                $box.find('.aipi-run').prop('disabled', false);
                alert('通信エラー: ' + xhr.statusText);
            });
        });

        // === メタボックス：ロールバック ===
        $('.aipi-rollback').on('click', function() {
            if (!confirm('挿入を元に戻します。よろしいですか？')) return;
            const $box = $('.aipi-metabox');
            const postId = $box.data('post-id');

            $.post(aiPI.ajaxUrl, {
                action: 'ai_pi_rollback',
                nonce: aiPI.nonce,
                post_id: postId,
            }).done(function(response) {
                if (response.success) {
                    alert('元に戻しました。ページを再読み込みします。');
                    location.reload();
                } else {
                    alert('エラー: ' + (response.data.message || '不明'));
                }
            });
        });

        // === 除外フラグ ===
        $('.aipi-exclude').on('change', function() {
            const $box = $('.aipi-metabox');
            const postId = $box.data('post-id');
            const excluded = $(this).is(':checked');

            $.post(aiPI.ajaxUrl, {
                action: 'ai_pi_toggle_exclude',
                nonce: aiPI.nonce,
                post_id: postId,
                excluded: excluded ? 'true' : 'false',
            });
        });

        // === 一括処理：対象記事カウント ===
        $('.aipi-count-targets').on('click', function() {
            const categories = $('.aipi-cat:checked').map(function() { return $(this).val(); }).get();
            const tags = $('.aipi-tag:checked').map(function() { return $(this).val(); }).get();
            const filter = $('input[name=aipi_filter]:checked').val();
            const limit = parseInt($('#aipi_limit').val(), 10);

            const $btn = $(this);
            $btn.prop('disabled', true).text('集計中...');

            $.post(aiPI.ajaxUrl, {
                action: 'ai_pi_count_targets',
                nonce: aiPI.nonce,
                categories: categories,
                tags: tags,
                filter: filter,
                limit: limit,
            }).done(function(response) {
                $btn.prop('disabled', false).text('対象記事を確認');

                if (response.success) {
                    const data = response.data;
                    let html = '<p>条件合致: <strong>' + data.total + '</strong>件 / 処理予定: <strong>' + data.target + '</strong>件</p>';
                    html += '<p>推定コスト: 約 <strong>¥' + data.estimated_cost + '</strong> / 推定時間: 約 <strong>' + Math.ceil(data.estimated_time / 60) + '</strong>分</p>';

                    $('.aipi-targets-summary').html(html);

                    if (data.preview && data.preview.length) {
                        let listHtml = '<strong>処理対象プレビュー（最大20件）:</strong><ul>';
                        data.preview.forEach(function(p) {
                            listHtml += '<li><a href="' + p.edit_url + '" target="_blank">' + escapeHtml(p.title) + '</a> (ID: ' + p.id + ')</li>';
                        });
                        listHtml += '</ul>';
                        $('.aipi-targets-list').html(listHtml);
                    } else {
                        $('.aipi-targets-list').html('<em>対象記事がありません</em>');
                    }

                    $('.aipi-targets-result').show();
                    $('.aipi-start-bulk').prop('disabled', data.target === 0).data('target-ids', data.target_ids);
                }
            });
        });

        // === 一括処理：開始 ===
        let bulkStopped = false;

        $('.aipi-start-bulk').on('click', function() {
            const targetIds = $(this).data('target-ids');
            if (!targetIds || !targetIds.length) {
                alert('対象記事がありません');
                return;
            }

            if (!confirm(targetIds.length + '件の記事に商品を挿入します。実行しますか？')) return;

            const mode = $('input[name=aipi_bulk_mode]').val() || 'marker';
            const design = $('input[name=aipi_bulk_design]').val() || 'vertical';

            $(this).prop('disabled', true);
            $('.aipi-progress').show();
            $('.aipi-progress-log').html('');
            bulkStopped = false;

            processBulkOne(targetIds, 0, mode, design);
        });

        $('.aipi-stop-bulk').on('click', function() {
            if (confirm('処理を中断します。よろしいですか？')) {
                bulkStopped = true;
                appendLog('warning', '⏹ ユーザーが処理を中断しました');
            }
        });

        function processBulkOne(ids, index, mode, design) {
            if (bulkStopped || index >= ids.length) {
                appendLog('', '✅ すべての処理が完了しました');
                $('.aipi-start-bulk').prop('disabled', false);
                return;
            }

            const postId = ids[index];
            const progress = Math.round(((index + 1) / ids.length) * 100);
            $('.aipi-progress-fill').css('width', progress + '%').text(progress + '% (' + (index + 1) + '/' + ids.length + ')');

            $.post(aiPI.ajaxUrl, {
                action: 'ai_pi_bulk_process_one',
                nonce: aiPI.nonce,
                post_id: postId,
                mode: mode,
                design: design,
            }).done(function(response) {
                if (response.success) {
                    const d = response.data;
                    if (d.result === 'success') {
                        appendLog('success', '✅ ID:' + d.post_id + ' ' + escapeHtml(d.title || '') + ' (商品' + d.product_count + '個挿入)');
                    } else {
                        appendLog('failure', '❌ ID:' + d.post_id + ' ' + escapeHtml(d.title || '') + ' - ' + (d.message || 'エラー'));
                    }
                }

                // 次の記事を処理（2秒ディレイ：API負荷軽減）
                setTimeout(function() {
                    processBulkOne(ids, index + 1, mode, design);
                }, 2000);
            }).fail(function() {
                appendLog('failure', '❌ ID:' + postId + ' 通信エラー');
                setTimeout(function() {
                    processBulkOne(ids, index + 1, mode, design);
                }, 3000);
            });
        }

        function appendLog(type, message) {
            const className = type ? 'log-' + type : '';
            $('.aipi-progress-log').prepend(
                '<div class="log-item ' + className + '">[' + new Date().toLocaleTimeString() + '] ' + message + '</div>'
            );
        }

        function escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text || '';
            return div.innerHTML;
        }

    });

})(jQuery);
