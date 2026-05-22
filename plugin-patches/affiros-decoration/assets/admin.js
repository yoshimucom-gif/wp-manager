/* === AIデコレーションプラグイン 管理画面JS === */

(function($) {
    'use strict';

    $(document).ready(function() {

        // === メタボックス：装飾実行 ===
        $('.ai-deco-run').on('click', function() {
            const $box = $('.ai-deco-metabox');
            const postId = $box.data('post-id');
            const dryRun = $('#ai-deco-dry-run').is(':checked');
            const model = $box.find('input[name=ai_deco_model]:checked').val() || '';
            const level = $box.find('input[name=ai_deco_level_pick]:checked').val() || '';

            const modelInfo = (aiDeco.models && aiDeco.models[model]) ? aiDeco.models[model] : null;
            const costNote = modelInfo ? '（約' + modelInfo.cost_yen + '円）' : '';

            if (!confirm(dryRun
                ? 'プレビューモードで装飾を実行します' + costNote + '。よろしいですか？'
                : '実際に記事本文を装飾して保存します' + costNote + '。元の本文はバックアップされます。実行しますか？')) {
                return;
            }

            $box.find('.ai-deco-spinner').show();
            $box.find('.ai-deco-result').hide();
            $box.find('.ai-deco-run').prop('disabled', true);

            $.post(aiDeco.ajaxUrl, {
                action: 'ai_deco_decorate',
                nonce: aiDeco.nonce,
                post_id: postId,
                dry_run: dryRun ? 'true' : 'false',
                model: model,
                level: level,
            }).done(function(response) {
                $box.find('.ai-deco-spinner').hide();
                $box.find('.ai-deco-run').prop('disabled', false);

                if (response.success) {
                    const data = response.data;
                    let html = '';

                    if (data.validation) {
                        const v = data.validation;
                        const statusLabel = {
                            'ok': '✅ 正常',
                            'warning': '⚠️ 要確認',
                            'error': '❌ エラー'
                        }[v.status] || v.status;

                        html += '<div><strong>結果: ' + statusLabel + '</strong></div>';

                        if (data.model) {
                            const lbl = (aiDeco.models && aiDeco.models[data.model])
                                ? aiDeco.models[data.model].label : data.model;
                            html += '<div style="margin-top:6px;font-size:11px;color:#666;">使用モデル: ' + escapeHtml(lbl) + ' / レベル: ' + escapeHtml(data.level || '-') + '</div>';
                        }

                        if (v.errors && v.errors.length) {
                            html += '<div style="color:#e74c3c;margin-top:6px;">エラー:<br>' + v.errors.map(escapeHtml).join('<br>') + '</div>';
                        }
                        if (v.warnings && v.warnings.length) {
                            html += '<div style="color:#f39c12;margin-top:6px;">警告:<br>' + v.warnings.map(escapeHtml).join('<br>') + '</div>';
                        }
                        if (v.metrics) {
                            html += '<div style="margin-top:6px;font-size:11px;color:#666;">';
                            html += '文字数: ' + v.metrics.original_length + ' → ' + v.metrics.decorated_length;
                            html += ' (' + Math.round(v.metrics.ratio * 100) + '%)';
                            html += '</div>';
                        }
                    }

                    if (data.usage) {
                        html += '<div style="margin-top:6px;font-size:11px;color:#666;">';
                        html += 'トークン: 入力' + (data.usage.input_tokens || 0) + ' / 出力' + (data.usage.output_tokens || 0);
                        html += '</div>';
                    }

                    if (dryRun && data.decorated) {
                        html += '<div style="margin-top:10px;"><strong>装飾結果（プレビュー）:</strong></div>';
                        html += '<textarea readonly style="width:100%;height:200px;font-family:Consolas,monospace;font-size:10px;margin-top:4px;">' + escapeHtml(data.decorated) + '</textarea>';
                    } else if (!dryRun) {
                        html += '<div style="margin-top:10px;color:#27ae60;">✅ 保存完了。ページを再読み込みすると装飾後の本文が表示されます。</div>';
                        setTimeout(function() {
                            if (confirm('処理が完了しました。ページを再読み込みして結果を確認しますか？')) {
                                location.reload();
                            }
                        }, 500);
                    }

                    $box.find('.ai-deco-result-body').html(html);
                    $box.find('.ai-deco-result').show();
                } else {
                    alert('エラー: ' + (response.data.message || '不明なエラー'));
                }
            }).fail(function(xhr) {
                $box.find('.ai-deco-spinner').hide();
                $box.find('.ai-deco-run').prop('disabled', false);
                alert('通信エラーが発生しました: ' + xhr.statusText);
            });
        });

        // === メタボックス：ロールバック ===
        $('.ai-deco-rollback').on('click', function() {
            if (!confirm('装飾を元に戻します。よろしいですか？')) return;

            const $box = $('.ai-deco-metabox');
            const postId = $box.data('post-id');

            $.post(aiDeco.ajaxUrl, {
                action: 'ai_deco_rollback',
                nonce: aiDeco.nonce,
                post_id: postId,
            }).done(function(response) {
                if (response.success) {
                    alert('装飾を元に戻しました。ページを再読み込みします。');
                    location.reload();
                } else {
                    alert('エラー: ' + (response.data.message || '不明なエラー'));
                }
            });
        });

        // === メタボックス：除外フラグ ===
        $('.ai-deco-exclude').on('change', function() {
            const $box = $('.ai-deco-metabox');
            const postId = $box.data('post-id');
            const excluded = $(this).is(':checked');

            $.post(aiDeco.ajaxUrl, {
                action: 'ai_deco_toggle_exclude',
                nonce: aiDeco.nonce,
                post_id: postId,
                excluded: excluded ? 'true' : 'false',
            });
        });

        // === 一括処理画面：対象記事カウント ===
        $('.ai-deco-count-targets').on('click', function() {
            const categories = $('.ai-deco-cat:checked').map(function() { return $(this).val(); }).get();
            const tags = $('.ai-deco-tag:checked').map(function() { return $(this).val(); }).get();
            const filter = $('input[name=ai_deco_filter]:checked').val();
            const limit = parseInt($('#ai_deco_limit').val(), 10);
            const model = $('input[name=ai_deco_bulk_model]:checked').val() || '';

            const $btn = $(this);
            $btn.prop('disabled', true).text('集計中...');

            $.post(aiDeco.ajaxUrl, {
                action: 'ai_deco_count_targets',
                nonce: aiDeco.nonce,
                categories: categories,
                tags: tags,
                filter: filter,
                limit: limit,
                model: model,
            }).done(function(response) {
                $btn.prop('disabled', false).text('対象記事を確認');

                if (response.success) {
                    const data = response.data;
                    let html = '<p>条件に合致する記事: <strong>' + data.total + '</strong>件 / 今回処理する記事: <strong>' + data.target + '</strong>件</p>';
                    html += '<p>装飾品質: <strong>' + escapeHtml(data.model_label || '') + '</strong>（約' + data.cost_per_post + '円/記事）</p>';
                    html += '<p>推定コスト: 約 <strong>¥' + data.estimated_cost.toLocaleString() + '</strong> / 推定時間: 約 <strong>' + Math.ceil(data.estimated_time / 60) + '</strong>分</p>';

                    $('.ai-deco-targets-summary').html(html);

                    if (data.preview && data.preview.length) {
                        let listHtml = '<strong>対象記事プレビュー（最大20件）:</strong><ul>';
                        data.preview.forEach(function(p) {
                            listHtml += '<li><a href="' + p.edit_url + '" target="_blank">' + escapeHtml(p.title) + '</a> (ID: ' + p.id + ')</li>';
                        });
                        listHtml += '</ul>';
                        $('.ai-deco-targets-list').html(listHtml);
                    }

                    $('.ai-deco-targets-result').show();
                    $('.ai-deco-start-bulk').prop('disabled', data.target === 0).data('target-ids', data.target_ids);
                } else {
                    alert('エラー: ' + (response.data.message || '不明'));
                }
            });
        });

        // === 一括処理画面：開始 ===
        let bulkStopped = false;

        $('.ai-deco-start-bulk').on('click', function() {
            const targetIds = $(this).data('target-ids');
            if (!targetIds || !targetIds.length) {
                alert('対象記事がありません');
                return;
            }

            const level = $('input[name=ai_deco_level]:checked').val();
            const model = $('input[name=ai_deco_bulk_model]:checked').val() || '';
            const modelInfo = (aiDeco.models && aiDeco.models[model]) ? aiDeco.models[model] : null;
            const costNote = modelInfo ? '（' + modelInfo.label + ' 約' + modelInfo.cost_yen + '円×' + targetIds.length + '件）' : '';

            if (!confirm(targetIds.length + '件の記事を装飾します' + costNote + '。実行しますか？')) return;

            $(this).prop('disabled', true);
            $('.ai-deco-progress').show();
            $('.ai-deco-progress-log').html('');
            bulkStopped = false;

            processBulkOne(targetIds, 0, level, model);
        });

        $('.ai-deco-stop-bulk').on('click', function() {
            if (confirm('処理を中断します。よろしいですか？')) {
                bulkStopped = true;
                appendLog('warning', '⏹ ユーザーが処理を中断しました');
            }
        });

        function processBulkOne(ids, index, level, model) {
            if (bulkStopped || index >= ids.length) {
                appendLog('', '✅ すべての処理が完了しました');
                $('.ai-deco-start-bulk').prop('disabled', false);
                return;
            }

            const postId = ids[index];
            const progress = Math.round(((index + 1) / ids.length) * 100);
            $('.ai-deco-progress-fill').css('width', progress + '%').text(progress + '% (' + (index + 1) + '/' + ids.length + ')');

            $.post(aiDeco.ajaxUrl, {
                action: 'ai_deco_bulk_process_one',
                nonce: aiDeco.nonce,
                post_id: postId,
                level: level,
                model: model,
            }).done(function(response) {
                if (response.success) {
                    const d = response.data;
                    if (d.result === 'success') {
                        const statusIcon = {
                            'ok': '✅',
                            'warning': '⚠️',
                            'error': '❌'
                        }[d.status] || '✓';
                        appendLog(d.status === 'warning' ? 'warning' : 'success',
                            statusIcon + ' ID:' + d.post_id + ' ' + escapeHtml(d.title || '') + ' (' + d.status + ')');
                    } else {
                        appendLog('failure', '❌ ID:' + d.post_id + ' ' + escapeHtml(d.title || '') + ' - ' + (d.message || 'エラー'));
                    }
                }

                setTimeout(function() {
                    processBulkOne(ids, index + 1, level, model);
                }, 1000);
            }).fail(function() {
                appendLog('failure', '❌ ID:' + postId + ' 通信エラー');
                setTimeout(function() {
                    processBulkOne(ids, index + 1, level, model);
                }, 2000);
            });
        }

        function appendLog(type, message) {
            const className = type ? 'log-' + type : '';
            $('.ai-deco-progress-log').prepend(
                '<div class="log-item ' + className + '">[' + new Date().toLocaleTimeString() + '] ' + message + '</div>'
            );
        }

        function escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }

    });

})(jQuery);
