/* === 装飾・整形プラグイン 管理画面JS === */

(function($) {
    'use strict';

    $(document).ready(function() {

        // === メタボックス：装飾実行 ===
        $('.decofmt-deco-run').on('click', function() {
            const $box = $('.decofmt-metabox');
            const postId = $box.data('post-id');
            const dryRun = $('#decofmt-deco-dry-run').is(':checked');
            const model = $box.find('input[name=decofmt_deco_model]:checked').val() || '';
            const level = $box.find('input[name=decofmt_deco_level_pick]:checked').val() || '';

            const modelInfo = (decofmt.models && decofmt.models[model]) ? decofmt.models[model] : null;
            const costNote = modelInfo ? '（約' + modelInfo.cost_yen + '円）' : '';

            if (!confirm(dryRun
                ? 'プレビューモードで装飾を実行します' + costNote + '。よろしいですか？'
                : '実際に記事本文を装飾して保存します' + costNote + '。元の本文はバックアップされます。実行しますか？')) {
                return;
            }

            $box.find('.decofmt-spinner').show();
            $box.find('.decofmt-result').hide();
            $box.find('.decofmt-deco-run').prop('disabled', true);

            // ★v1.0.23: サーバーはAPI1回だけ叩く（1リクエストを短く保って504を防ぐ）。
            //   検証エラー時のリトライはここで行い、前回のエラー内容をモデルに渡す。
            var MAX_ATTEMPTS = 3;
            runDecorate(1);

            function describeAjaxError(xhr) {
                var s = xhr && xhr.status;
                var body = (xhr && xhr.responseText) || '';
                try {
                    var j = JSON.parse(body);
                    if (j && j.data && j.data.message) return j.data.message;
                } catch (_) { /* JSONでなければ無視 */ }
                var t = body.match(/<title[^>]*>([^<]{0,120})<\/title>/i);
                var detail = t ? '［' + t[1].trim() + '］' : '';
                if (s === 400) return 'サーバーがリクエストを拒否(400)' + detail + ' — WAF／セキュリティプラグインがブロックしている可能性があります';
                if (s === 403) return 'アクセス拒否(403)' + detail + ' — WAF／SiteGuard等の設定をご確認ください';
                if (s === 504) return 'サーバータイムアウト(504)' + detail + ' — 処理が長すぎてサーバーが接続を切りました';
                if (s === 502) return 'サーバーエラー(502)' + detail + ' — PHPプロセスが落ちた可能性があります';
                if (s === 503) return 'サーバー過負荷(503)' + detail + ' — 時間をおいて再実行してください';
                if (s === 0)   return '接続が切れました（タブを閉じた／ネットワーク断／WAFによる遮断）';
                if (s)         return 'HTTP ' + s + detail;
                return (xhr && xhr.statusText) || '不明な通信エラー';
            }

            function runDecorate(attempt) {
                if (attempt > 1) {
                    $box.find('.decofmt-spinner').html(
                        '<span class="spinner is-active" style="float:none;"></span> 検証エラーのため再試行中…（' + attempt + '/' + MAX_ATTEMPTS + '）'
                    );
                }
                $.post(decofmt.ajaxUrl, {
                    action: 'decofmt_deco_decorate',
                    nonce: decofmt.nonce,
                    post_id: postId,
                    dry_run: dryRun ? 'true' : 'false',
                    model: model,
                    level: level,
                    // ★前回エラーの本文は送らない。サーバー側に保存済みのものを使う（WAF対策）
                    retry: attempt > 1 ? '1' : '',
                }).done(function(response) {
                    // 検証エラーなら再試行（修正指示はサーバー側に保存されている）
                    if (!response.success && response.data && response.data.retryable && attempt < MAX_ATTEMPTS) {
                        runDecorate(attempt + 1);
                        return;
                    }
                    renderResult(response, attempt);
                }).fail(function(xhr) {
                    $box.find('.decofmt-spinner').hide();
                    $box.find('.decofmt-deco-run').prop('disabled', false);
                    try { console.error('[装飾失敗] post=' + postId, xhr, (xhr && xhr.responseText || '').substring(0, 2000)); } catch (_) {}
                    alert('通信エラー: ' + describeAjaxError(xhr));
                });
            }

            function renderResult(response, attempt) {
                $box.find('.decofmt-spinner').hide();
                $box.find('.decofmt-deco-run').prop('disabled', false);

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

                        html += '<div><strong>結果: ' + statusLabel + '</strong>';
                        if (attempt > 1) {
                            html += ' <span style="font-size:11px;color:#888;">（' + attempt + '回目で成功）</span>';
                        }
                        html += '</div>';

                        if (data.model) {
                            const lbl = (decofmt.models && decofmt.models[data.model])
                                ? decofmt.models[data.model].label : data.model;
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

                    $box.find('.decofmt-result-body').html(html);
                    $box.find('.decofmt-result').show();
                } else {
                    var m = (response.data && response.data.message) ? response.data.message : '不明なエラー';
                    if (attempt >= MAX_ATTEMPTS) {
                        m += '\n\n（' + MAX_ATTEMPTS + '回試しましたが、検証を通る装飾結果が得られませんでした。'
                           + '装飾レベルを下げるか、上位モデルをお試しください）';
                    }
                    alert('エラー: ' + m);
                }
            }
        });

        // === メタボックス：ロールバック ===
        $('.decofmt-deco-rollback').on('click', function() {
            if (!confirm('装飾を元に戻します。よろしいですか？')) return;

            const $box = $('.decofmt-metabox');
            const postId = $box.data('post-id');

            $.post(decofmt.ajaxUrl, {
                action: 'decofmt_deco_rollback',
                nonce: decofmt.nonce,
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
        $('.decofmt-deco-exclude').on('change', function() {
            const $box = $('.decofmt-metabox');
            const postId = $box.data('post-id');
            const excluded = $(this).is(':checked');

            $.post(decofmt.ajaxUrl, {
                action: 'decofmt_deco_toggle_exclude',
                nonce: decofmt.nonce,
                post_id: postId,
                excluded: excluded ? 'true' : 'false',
            });
        });

        // === 一括処理画面：対象記事カウント ===
        $('.decofmt-deco-count-targets').on('click', function() {
            const categories = $('.decofmt-deco-cat:checked').map(function() { return $(this).val(); }).get();
            const tags = $('.decofmt-deco-tag:checked').map(function() { return $(this).val(); }).get();
            const filter = $('input[name=decofmt_deco_filter]:checked').val();
            const limit = parseInt($('#decofmt_deco_limit').val(), 10);
            const model = $('input[name=decofmt_deco_bulk_model]:checked').val() || '';

            const $btn = $(this);
            $btn.prop('disabled', true).text('集計中...');

            $.post(decofmt.ajaxUrl, {
                action: 'decofmt_deco_count_targets',
                nonce: decofmt.nonce,
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

                    $('.decofmt-targets-summary').html(html);

                    if (data.preview && data.preview.length) {
                        let listHtml = '<strong>対象記事プレビュー（最大20件）:</strong><ul>';
                        data.preview.forEach(function(p) {
                            listHtml += '<li><a href="' + p.edit_url + '" target="_blank">' + escapeHtml(p.title) + '</a> (ID: ' + p.id + ')</li>';
                        });
                        listHtml += '</ul>';
                        $('.decofmt-targets-list').html(listHtml);
                    }

                    $('.decofmt-targets-result').show();
                    $('.decofmt-deco-start-bulk').prop('disabled', data.target === 0).data('target-ids', data.target_ids);
                } else {
                    alert('エラー: ' + (response.data.message || '不明'));
                }
            });
        });

        // === 一括処理画面：開始 ===
        let bulkStopped = false;

        $('.decofmt-deco-start-bulk').on('click', function() {
            const targetIds = $(this).data('target-ids');
            if (!targetIds || !targetIds.length) {
                alert('対象記事がありません');
                return;
            }

            const level = $('input[name=decofmt_deco_level]:checked').val();
            const model = $('input[name=decofmt_deco_bulk_model]:checked').val() || '';
            const modelInfo = (decofmt.models && decofmt.models[model]) ? decofmt.models[model] : null;
            const costNote = modelInfo ? '（' + modelInfo.label + ' 約' + modelInfo.cost_yen + '円×' + targetIds.length + '件）' : '';

            if (!confirm(targetIds.length + '件の記事を装飾します' + costNote + '。実行しますか？')) return;

            $(this).prop('disabled', true);
            $('.decofmt-progress').show();
            $('.decofmt-progress-log').html('');
            bulkStopped = false;

            processBulkOne(targetIds, 0, level, model);
        });

        $('.decofmt-deco-stop-bulk').on('click', function() {
            if (confirm('処理を中断します。よろしいですか？')) {
                bulkStopped = true;
                appendLog('warning', '⏹ ユーザーが処理を中断しました');
            }
        });

        function processBulkOne(ids, index, level, model) {
            if (bulkStopped || index >= ids.length) {
                appendLog('', '✅ すべての処理が完了しました');
                $('.decofmt-deco-start-bulk').prop('disabled', false);
                return;
            }

            const postId = ids[index];
            const progress = Math.round(((index + 1) / ids.length) * 100);
            $('.decofmt-progress-fill').css('width', progress + '%').text(progress + '% (' + (index + 1) + '/' + ids.length + ')');

            $.post(decofmt.ajaxUrl, {
                action: 'decofmt_deco_bulk_process_one',
                nonce: decofmt.nonce,
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
            $('.decofmt-progress-log').prepend(
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
