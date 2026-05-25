<?php
/**
 * AI商品挿入 実行履歴ページ
 */

if (!defined('ABSPATH')) exit;

function ai_pi_render_history_page() {
    if (!current_user_can('manage_options')) return;
    ?>
    <div class="wrap aipi-wrap">
        <h1>AI商品挿入 実行履歴</h1>
        <p class="description">
            バックグラウンドジョブの進捗。<strong>10分ごとに3記事ずつ</strong>順次処理されます。
            画面を閉じても処理は継続します。
        </p>
        <div id="aipi-jobs" style="margin-top:18px;">
            <div style="padding:30px;text-align:center;color:#888;">読み込み中...</div>
        </div>
    </div>

    <div id="aipi-detail" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:99999;align-items:center;justify-content:center;">
        <div style="background:#fff;width:90%;max-width:880px;max-height:90vh;display:flex;flex-direction:column;border-radius:6px;overflow:hidden;">
            <div style="padding:12px 18px;border-bottom:1px solid #ddd;display:flex;justify-content:space-between;align-items:center;">
                <strong id="aipi-detail-title">ジョブ詳細</strong>
                <button type="button" class="button" id="aipi-detail-close">×</button>
            </div>
            <div id="aipi-detail-body" style="padding:14px 18px;overflow:auto;flex:1;"></div>
        </div>
    </div>

    <script>
    jQuery(function($) {
        function fmtTime(ts) {
            if (!ts) return '-';
            var d = new Date(ts * 1000);
            return d.toLocaleString('ja-JP');
        }
        function esc(s) {
            return String(s == null ? '' : s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
        }

        var statusMeta = {
            queued:     {label:'⏳ 待機中', color:'#888'},
            processing: {label:'⚙ 処理中', color:'#2271b1'},
            completed:  {label:'✓ 完了',  color:'#0a7a2f'},
            failed:     {label:'✗ 失敗',  color:'#c00'},
            cancelled:  {label:'⏹ 中断',  color:'#a06000'},
        };

        function loadJobs() {
            $.post(aiPI.ajaxUrl, {
                action: 'ai_pi_jobs_list',
                nonce: aiPI.nonce,
            }).done(function(resp) {
                if (!resp || !resp.success) return;
                renderJobs(resp.data.jobs || []);
            });
        }

        function renderJobs(jobs) {
            if (!jobs.length) {
                $('#aipi-jobs').html('<div style="padding:30px;text-align:center;color:#888;">ジョブはまだありません。<br>「一括処理」ページから商品挿入を開始してください。</div>');
                return;
            }
            var html = '<table class="wp-list-table widefat striped">';
            html += '<thead><tr>';
            html += '<th style="width:120px;">ジョブID</th>';
            html += '<th style="width:110px;">状態</th>';
            html += '<th>進捗</th>';
            html += '<th style="width:120px;">結果</th>';
            html += '<th style="width:150px;">開始</th>';
            html += '<th style="width:150px;">完了</th>';
            html += '<th style="width:180px;">操作</th>';
            html += '</tr></thead><tbody>';
            jobs.forEach(function(j) {
                var meta = statusMeta[j.status] || {label:j.status, color:'#000'};
                var total = (j.stats && j.stats.total) || 0;
                var done  = (j.stats && j.stats.done)  || 0;
                var pct   = total ? Math.round(done / total * 100) : 0;
                html += '<tr>';
                html += '<td><code>' + esc(j.id) + '</code></td>';
                html += '<td style="color:' + meta.color + ';font-weight:600;">' + meta.label + '</td>';
                html += '<td>';
                html += '<div style="background:#eee;border-radius:3px;overflow:hidden;height:12px;">';
                html += '<div style="background:' + meta.color + ';height:100%;width:' + pct + '%;transition:width .3s;"></div>';
                html += '</div>';
                html += '<div style="font-size:11px;color:#666;margin-top:3px;">' + done + ' / ' + total + ' (' + pct + '%)</div>';
                html += '</td>';
                html += '<td><span style="color:#0a7a2f">✓' + ((j.stats && j.stats.success) || 0) + '</span> / <span style="color:#c00">✗' + ((j.stats && j.stats.failed) || 0) + '</span></td>';
                html += '<td>' + fmtTime(j.created_at) + '</td>';
                html += '<td>' + fmtTime(j.completed_at) + '</td>';
                html += '<td>';
                html += '<button class="button button-small aipi-view" data-job-id="' + esc(j.id) + '">詳細</button> ';
                if (j.status === 'queued' || j.status === 'processing') {
                    html += '<button class="button button-small aipi-cancel" data-job-id="' + esc(j.id) + '" style="color:#a06000;">中断</button> ';
                } else {
                    html += '<button class="button button-small aipi-delete" data-job-id="' + esc(j.id) + '" style="color:#c00;">削除</button>';
                }
                html += '</td>';
                html += '</tr>';
            });
            html += '</tbody></table>';
            $('#aipi-jobs').html(html);
        }

        function showDetail(jobId) {
            $.post(aiPI.ajaxUrl, {
                action: 'ai_pi_job_status',
                nonce: aiPI.nonce,
                job_id: jobId,
            }).done(function(resp) {
                if (!resp || !resp.success) { alert('取得失敗'); return; }
                var j = resp.data;
                $('#aipi-detail-title').text('ジョブ詳細: ' + j.id);
                var html = '<p>状態: <strong>' + esc((statusMeta[j.status]||{}).label || j.status) + '</strong></p>';
                html += '<table class="wp-list-table widefat striped"><thead><tr>';
                html += '<th>記事</th><th style="width:90px;">状態</th><th style="width:60px;">リトライ</th><th>エラー</th>';
                html += '</tr></thead><tbody>';
                (j.items || []).forEach(function(it) {
                    var itStatus = it.status === 'success' ? '<span style="color:#0a7a2f">✓ 成功</span>'
                                  : it.status === 'failed'  ? '<span style="color:#c00">✗ 失敗</span>'
                                  : it.status === 'pending' ? '<span style="color:#888">⏳ 待機</span>'
                                  : it.status;
                    var editLink = '/wp-admin/post.php?post=' + it.post_id + '&action=edit';
                    html += '<tr>';
                    html += '<td><a href="' + editLink + '" target="_blank">' + esc(it.post_title || '#'+it.post_id) + '</a></td>';
                    html += '<td>' + itStatus + '</td>';
                    html += '<td>' + (it.retry_count || 0) + '</td>';
                    html += '<td style="font-size:11px;color:#c00;">' + esc(it.error || '') + '</td>';
                    html += '</tr>';
                });
                html += '</tbody></table>';
                $('#aipi-detail-body').html(html);
                $('#aipi-detail').css('display','flex');
            });
        }

        $('#aipi-detail-close').on('click', function() { $('#aipi-detail').hide(); });
        $('#aipi-jobs').on('click', '.aipi-view', function() {
            showDetail($(this).data('job-id'));
        });
        $('#aipi-jobs').on('click', '.aipi-cancel', function() {
            if (!confirm('このジョブを中断しますか？処理中の記事は完了まで進みます。')) return;
            $.post(aiPI.ajaxUrl, {
                action: 'ai_pi_job_cancel',
                nonce: aiPI.nonce,
                job_id: $(this).data('job-id'),
            }).done(loadJobs);
        });
        $('#aipi-jobs').on('click', '.aipi-delete', function() {
            if (!confirm('このジョブの履歴を削除しますか？')) return;
            $.post(aiPI.ajaxUrl, {
                action: 'ai_pi_job_delete',
                nonce: aiPI.nonce,
                job_id: $(this).data('job-id'),
            }).done(loadJobs);
        });

        loadJobs();
        setInterval(loadJobs, 5000);
    });
    </script>
    <?php
}
