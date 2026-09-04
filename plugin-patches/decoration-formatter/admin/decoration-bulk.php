<?php
/**
 * AI装飾 一括処理画面（v1.0.16: 整形と同じスキャン→テーブル→行ごと適用のUIに統一）
 */

if (!defined('ABSPATH')) exit;

function decofmt_render_deco_bulk_page() {
    if (!current_user_can('manage_options')) return;

    $categories = get_categories(['hide_empty' => false]);
    $tags = get_tags(['hide_empty' => false]);
    $settings = get_option('decofmt_deco_settings', []);
    $default_model = $settings['model'] ?? DECOFMT_DEFAULT_MODEL;
    $default_level = $settings['decoration_level'] ?? 'standard';
    $models = decofmt_get_models();
    ?>
    <div class="wrap decofmt-wrap">
        <h1>🎨 AI装飾 一括処理</h1>

        <div class="notice notice-warning">
            <p><strong>⚠️ 注意：</strong>装飾は Claude APIコストが発生します（Sonnet で約19円/記事）。最初は少数件で必ずテストしてください。<strong>元の本文はバックアップされ、装飾を元に戻せます。</strong></p>
        </div>

        <h2>絞り込み</h2>
        <table class="form-table">
            <tr>
                <th scope="row">カテゴリ</th>
                <td>
                    <div class="decofmt-checkbox-list">
                        <?php foreach ($categories as $cat): ?>
                            <label>
                                <input type="checkbox" class="decofmt-deco-cat" value="<?php echo esc_attr($cat->term_id); ?>">
                                <?php echo esc_html($cat->name); ?>
                                <span class="decofmt-count">(<?php echo esc_html($cat->count); ?>)</span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                    <p class="description">未選択=全カテゴリ対象</p>
                </td>
            </tr>

            <tr>
                <th scope="row">タグ</th>
                <td>
                    <div class="decofmt-checkbox-list">
                        <?php foreach ($tags as $tag): ?>
                            <label>
                                <input type="checkbox" class="decofmt-deco-tag" value="<?php echo esc_attr($tag->term_id); ?>">
                                <?php echo esc_html($tag->name); ?>
                                <span class="decofmt-count">(<?php echo esc_html($tag->count); ?>)</span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                    <p class="description">未選択=タグ条件なし</p>
                </td>
            </tr>

            <tr>
                <th scope="row">処理対象</th>
                <td>
                    <label><input type="radio" name="decofmt_deco_filter" value="undecorated" checked> 未装飾の記事のみ</label><br>
                    <label><input type="radio" name="decofmt_deco_filter" value="warning"> ⚠️要確認の記事のみ（再処理）</label><br>
                    <label><input type="radio" name="decofmt_deco_filter" value="all"> 全件（装飾済みも再処理）</label>
                </td>
            </tr>

            <tr>
                <th scope="row">装飾品質</th>
                <td>
                    <?php foreach ($models as $key => $m): ?>
                        <label style="display:block;margin:4px 0;">
                            <input type="radio" name="decofmt_deco_bulk_model" value="<?php echo esc_attr($key); ?>" <?php checked($default_model, $key); ?>>
                            <strong><?php echo esc_html($m['label']); ?></strong>
                            <span style="color:#888;">／ 約<?php echo esc_html($m['cost_yen']); ?>円/記事</span>
                        </label>
                    <?php endforeach; ?>
                </td>
            </tr>

            <tr>
                <th scope="row">装飾レベル</th>
                <td>
                    <label><input type="radio" name="decofmt_deco_level" value="light" <?php checked($default_level, 'light'); ?>> 軽め</label>&nbsp;&nbsp;
                    <label><input type="radio" name="decofmt_deco_level" value="standard" <?php checked($default_level, 'standard'); ?>> 標準</label>&nbsp;&nbsp;
                    <label><input type="radio" name="decofmt_deco_level" value="heavy" <?php checked($default_level, 'heavy'); ?>> 盛り盛り</label>
                </td>
            </tr>

            <tr>
                <th scope="row">スキャン上限</th>
                <td>
                    <input type="number" id="decofmt_deco_limit" value="<?php echo esc_attr(DECOFMT_DEFAULT_SCAN_LIMIT); ?>" min="1" max="500" style="width:80px;">
                    <p class="description">一覧に表示する記事数の上限。多いと重くなるので通常 20〜50 で十分。実際に装飾するかは一覧の「適用」or「全件に適用」で選ぶ。</p>
                </td>
            </tr>

            <tr>
                <th scope="row">同時実行数</th>
                <td>
                    <select id="decofmt_deco_concurrency">
                        <option value="1">1（順番に処理・最も安全）</option>
                        <option value="2" selected>2（推奨）</option>
                        <option value="3">3</option>
                        <option value="4">4</option>
                        <option value="5">5（最速・APIレート制限に当たりやすい）</option>
                    </select>
                    <p class="description">
                        「全件に適用」で何件を同時に処理するか。1記事あたり1〜3分かかる（検証エラー時は自動リトライで最大3回APIを叩く）ため、
                        <strong>順番に処理すると100記事で十数時間かかります</strong>。3並列なら約1/3の時間で終わります。<br>
                        ⚠️ 上げすぎると2つの問題が出ます。①サーバーのPHPプロセスを占有してサイト全体が重くなる
                        ②Claude APIのレート制限に当たって待たされ、かえって遅くなる（タイムアウトの原因にも）。<br>
                        共有サーバーなら <strong>2</strong> を推奨。うまくいかない場合は 1 に下げてください。
                    </p>
                </td>
            </tr>
        </table>

        <p style="margin:16px 0">
            <button type="button" id="decofmt-deco-scan-btn" class="button button-primary">🔍 対象記事をスキャン</button>
            <span id="decofmt-deco-scan-status" style="margin-left:12px;color:#666;font-size:13px"></span>
        </p>

        <div id="decofmt-deco-result" style="display:none;">
            <div style="margin:0 0 12px;padding:12px;background:#f0f9ff;border-left:4px solid #3498db;border-radius:4px;">
                <div id="decofmt-deco-cost-summary" style="font-size:14px;margin-bottom:8px;"></div>
                <button type="button" id="decofmt-deco-apply-all-btn" class="button button-primary">✨ 全件に適用</button>
                <button type="button" id="decofmt-deco-stop-bulk-btn" class="button" style="display:none;">⏹ 中断</button>
                <span id="decofmt-deco-apply-status" style="margin-left:12px;font-size:13px"></span>
            </div>
            <table class="wp-list-table widefat striped">
                <thead>
                    <tr>
                        <th style="width:60px">ID</th>
                        <th>タイトル</th>
                        <th style="width:130px">装飾状態</th>
                        <th style="width:160px">前回モデル / レベル</th>
                        <th style="width:130px">装飾日時</th>
                        <th style="width:240px">アクション</th>
                    </tr>
                </thead>
                <tbody id="decofmt-deco-result-tbody"></tbody>
            </table>
        </div>
    </div>

    <script>
    (function ($) {
        // decofmt グローバルは wp_localize_script でフッターに出力されるため、
        // 各処理時に再評価する。
        function cfg() {
            return {
                url:    (window.decofmt && decofmt.ajaxUrl) || (window.ajaxurl || ''),
                nonce:  (window.decofmt && decofmt.nonce)   || '',
                models: (window.decofmt && decofmt.models)  || {}
            };
        }
        let posts = [];
        let bulkStopped = false;

        $('#decofmt-deco-scan-btn').on('click', scan);
        $('#decofmt-deco-apply-all-btn').on('click', applyAll);
        $('#decofmt-deco-stop-bulk-btn').on('click', function () {
            if (confirm('処理を中断します。よろしいですか？')) bulkStopped = true;
        });

        async function scan() {
            const c = cfg();
            if (!c.nonce) { alert('プラグインJS未初期化：ページを再読み込みして再実行してください'); return; }

            const categories = $('.decofmt-deco-cat:checked').map(function () { return $(this).val(); }).get();
            const tags = $('.decofmt-deco-tag:checked').map(function () { return $(this).val(); }).get();
            const filter = $('input[name=decofmt_deco_filter]:checked').val();
            const model  = $('input[name=decofmt_deco_bulk_model]:checked').val() || '';
            const limit  = parseInt($('#decofmt_deco_limit').val(), 10) || 20;

            $('#decofmt-deco-scan-btn').prop('disabled', true).text('スキャン中...');
            $('#decofmt-deco-result').hide();
            $('#decofmt-deco-result-tbody').empty();

            try {
                const res = await $.post(c.url, {
                    action: 'decofmt_deco_count_targets',
                    nonce: c.nonce,
                    categories: categories,
                    tags: tags,
                    filter: filter,
                    model: model,
                    limit: limit
                });
                if (res === '-1' || res === -1) { alert('nonce認証エラー(-1)：ページを再読み込みしてください'); return; }
                if (!res || !res.success) { alert('スキャン失敗: ' + (res && res.data ? res.data.message : '')); return; }

                const data = res.data;
                posts = data.rows || [];
                $('#decofmt-deco-scan-status').text('スキャン完了: 条件合致 ' + data.total + '件 / 今回リスト ' + data.target + '件');
                renderCostSummary(data);
                render();
                if (posts.length) $('#decofmt-deco-result').show();
            } catch (e) {
                const body = (e.responseText || '').trim();
                alert('通信エラー: ' + (body.substring(0, 300) || e.statusText || 'unknown'));
            } finally {
                $('#decofmt-deco-scan-btn').prop('disabled', false).text('🔍 対象記事をスキャン');
            }
        }

        // 直近スキャン結果を保持して、同時実行数を変えたら所要時間の表示も更新する
        let lastScan = null;

        function renderCostSummary(data) {
            lastScan = data;
            const conc = Math.max(1, Math.min(5, parseInt($('#decofmt_deco_concurrency').val(), 10) || 3));
            const totalMin = Math.ceil(data.estimated_time / 60);      // 1並列のときの合計
            const wallMin  = Math.ceil(totalMin / conc);               // 同時実行を考慮した実時間
            let timeText = '約 <strong>' + wallMin + '</strong>分';
            if (conc > 1) {
                timeText += '<span style="color:#666"> （' + conc + '並列。順番に処理すると約' + totalMin + '分）</span>';
            }
            $('#decofmt-deco-cost-summary').html(
                '装飾品質: <strong>' + esc(data.model_label || '') + '</strong>（約' + data.cost_per_post + '円/記事）&nbsp;/&nbsp;' +
                '推定コスト: 約 <strong>¥' + data.estimated_cost.toLocaleString() + '</strong>&nbsp;/&nbsp;' +
                '推定時間: ' + timeText +
                '<br><span style="font-size:12px;color:#666">※1記事あたり1〜3分（検証エラー時は自動リトライで最大3回APIを叩くため）。実際の残り時間は処理開始後に実測ベースで表示します。</span>'
            );
        }

        // 同時実行数を変更したら所要時間の表示を即座に更新
        $('#decofmt_deco_concurrency').on('change', function () {
            if (lastScan) renderCostSummary(lastScan);
        });

        function render() {
            const tbody = $('#decofmt-deco-result-tbody').empty();
            const statusHtml = {
                'ok':      '<span style="color:#16a34a;font-weight:600">✅ 装飾済</span>',
                'warning': '<span style="color:#d97706;font-weight:600">⚠️ 要確認</span>',
                'error':   '<span style="color:#dc2626;font-weight:600">❌ エラー</span>'
            };
            posts.forEach(p => {
                const viewUrl = p.view_url || p.edit_url || (location.origin + '/?p=' + p.id);
                const status = statusHtml[p.status] || '<span style="color:#6b7280">未装飾</span>';
                const modelLine = p.past_model_label ? esc(p.past_model_label) : '-';
                const levelLine = p.past_level ? ' / ' + esc(p.past_level) : '';
                tbody.append(
                    '<tr data-id="' + p.id + '">' +
                        '<td>' + p.id + '</td>' +
                        '<td><a href="' + viewUrl + '" target="_blank">' + esc(p.title) + '</a></td>' +
                        '<td>' + status + '</td>' +
                        '<td style="font-size:11px">' + modelLine + levelLine + '</td>' +
                        '<td style="font-size:11px">' + (p.date || '-') + '</td>' +
                        '<td>' +
                            '<button type="button" class="button button-small decofmt-deco-row-preview" data-id="' + p.id + '">👁 プレビュー</button> ' +
                            '<button type="button" class="button button-primary button-small decofmt-deco-row-apply" data-id="' + p.id + '">✨ 適用</button>' +
                        '</td>' +
                    '</tr>'
                );
            });

            tbody.find('.decofmt-deco-row-preview').on('click', function () {
                const id = $(this).data('id');
                previewOne(id);
            });
            tbody.find('.decofmt-deco-row-apply').on('click', function () {
                const id = $(this).data('id');
                applyOne(id, $(this));
            });
        }

        async function previewOne(id) {
            const c = cfg();
            const level = $('input[name=decofmt_deco_level]:checked').val() || 'standard';
            const model = $('input[name=decofmt_deco_bulk_model]:checked').val() || '';
            const w = window.open('', '_blank', 'width=1200,height=800');
            w.document.write('<html><head><title>装飾プレビュー #' + id + '</title><meta charset="UTF-8"></head><body style="font-family:sans-serif;padding:20px;">Claude APIで装飾中… 30秒〜2分ほどかかります。</body></html>');
            w.document.close();

            try {
                const res = await $.post(c.url, {
                    action: 'decofmt_deco_decorate',
                    nonce: c.nonce,
                    post_id: id,
                    dry_run: 'true',
                    level: level,
                    model: model
                });
                if (!res || !res.success) {
                    w.document.body.innerHTML = '<p style="color:#dc2626">装飾失敗: ' + esc(res && res.data ? res.data.message : '不明なエラー') + '</p>';
                    return;
                }
                const d = res.data;
                const usage = d.usage || {};
                w.document.body.innerHTML =
                    '<style>' +
                    'body{font-family:sans-serif;font-size:14px;padding:20px;}' +
                    '.grid{display:grid;grid-template-columns:1fr 1fr;gap:20px}' +
                    '.col h2{margin:0 0 8px;font-size:14px;background:#eee;padding:8px}' +
                    '.col{border:1px solid #ddd;padding:12px;overflow:auto;max-height:80vh}' +
                    '.col.after{background:#f0fdf4}' +
                    'pre{white-space:pre-wrap;word-break:break-all;font-family:Consolas,Monaco,monospace;font-size:11px;margin:0;line-height:1.6}' +
                    '.meta{background:#f5f5f5;padding:8px 12px;border-radius:4px;margin-bottom:12px;font-size:12px}' +
                    '</style>' +
                    '<h1 style="font-size:16px">装飾プレビュー #' + id + '</h1>' +
                    '<div class="meta">' +
                        'モデル: <strong>' + esc(d.model || '-') + '</strong> / ' +
                        'レベル: <strong>' + esc(d.level || '-') + '</strong> / ' +
                        'トークン: 入力' + (usage.input_tokens || 0) + ' / 出力' + (usage.output_tokens || 0) + ' / ' +
                        '検証: <strong>' + (d.validation ? d.validation.status : '?') + '</strong>' +
                    '</div>' +
                    '<div class="grid">' +
                        '<div class="col"><h2>Before</h2><pre>' + esc(d.before || '') + '</pre></div>' +
                        '<div class="col after"><h2>After（装飾済）</h2><pre>' + esc(d.decorated || '') + '</pre></div>' +
                    '</div>';
            } catch (e) {
                w.document.body.innerHTML = '<p style="color:#dc2626">通信エラー: ' + esc(e.statusText || '') + '</p>';
            }
        }

        // 通信エラーを人が読める短文にする。
        // 504/502 はサーバー(XSERVER等)のプロキシがHTMLのエラーページを返すので、
        // そのまま出すと巨大なHTMLがalertに出てしまう（v1.0.22までの挙動）。
        function describeAjaxError(e) {
            const status = e && e.status;
            const body = (e && e.responseText) || '';

            // サーバーがJSONで返していれば、その message を最優先で使う
            try {
                const j = JSON.parse(body);
                if (j && j.data && j.data.message) return j.data.message;
            } catch (_) { /* JSONでなければ無視 */ }

            // HTMLのエラーページなら <title> を拾って手がかりにする
            const t = body.match(/<title[^>]*>([^<]{0,120})<\/title>/i);
            const detail = t ? '［' + t[1].trim() + '］' : '';

            if (status === 400) {
                return 'サーバーがリクエストを拒否(400)' + detail
                     + ' — WAF／セキュリティプラグインがブロックしている可能性があります';
            }
            if (status === 403) return 'アクセス拒否(403)' + detail + ' — WAF／SiteGuard等の設定をご確認ください';
            if (status === 504) return 'サーバータイムアウト(504)' + detail + ' — 処理が長すぎてサーバーが接続を切りました';
            if (status === 502) return 'サーバーエラー(502)' + detail + ' — PHPプロセスが落ちた可能性があります';
            if (status === 503) return 'サーバー過負荷(503)' + detail + ' — 同時実行数を下げてください';
            if (status === 0)   return '接続が切れました（タブを閉じた／ネットワーク断／WAFによる遮断）';
            if (status)         return 'HTTP ' + status + detail;
            return e && e.statusText ? e.statusText : '不明な通信エラー';
        }

        // 失敗の生ログ。原因調査用にブラウザのコンソールへ全文を残す
        function logFailure(id, kind, e, msg) {
            try {
                console.error('[装飾失敗] post=' + id + ' / ' + kind + ' / ' + msg, e);
                if (e && e.responseText) console.error('  responseText:', e.responseText.substring(0, 2000));
            } catch (_) {}
        }

        // 1記事を装飾する。
        // ★v1.0.23: サーバーはAPI1回だけ呼ぶ（1リクエストを短く保って504を防ぐ）。
        //   検証エラーで失敗した場合のリトライはここ（ブラウザ側）で行い、
        //   前回のエラー内容を retry_feedback としてサーバー経由でモデルに渡す。
        const MAX_ATTEMPTS = 3;

        // 失敗表示。全文は title 属性とコンソールに残し、行には要約を出す
        function showFailure(btn, msg) {
            if (!btn) return;
            const short = msg.length > 60 ? msg.substring(0, 60) + '…' : msg;
            btn.replaceWith(
                '<span style="color:#dc2626;font-weight:600;display:inline-block;max-width:340px;white-space:normal;line-height:1.4" ' +
                'title="' + esc(msg) + '">❌ ' + esc(short) + '</span>'
            );
        }

        async function applyOne(id, btn, attempt) {
            attempt = attempt || 1;
            if (btn) {
                btn.prop('disabled', true).text(attempt > 1 ? '再試行 ' + attempt + '…' : '装飾中…');
            }
            const c = cfg();
            const level = $('input[name=decofmt_deco_level]:checked').val() || 'standard';
            const model = $('input[name=decofmt_deco_bulk_model]:checked').val() || '';

            try {
                const res = await $.post(c.url, {
                    action: 'decofmt_deco_bulk_process_one',
                    nonce: c.nonce,
                    post_id: id,
                    level: level,
                    model: model,
                    // ★前回エラーの本文は送らない。サーバー側に保存済みのものを使う（WAF対策）
                    retry: attempt > 1 ? '1' : ''
                });
                if (res === '-1' || res === -1) {
                    showFailure(btn, 'nonce認証エラー(-1)。ページを再読み込みしてください');
                    return false;
                }
                if (res && res.success) {
                    const d = res.data;
                    if (d.result === 'success') {
                        if (btn) {
                            const statusIcon = { 'ok': '✅', 'warning': '⚠️', 'error': '❌' }[d.status] || '✓';
                            const note = attempt > 1 ? ' <span style="font-size:11px;color:#888">(' + attempt + '回目)</span>' : '';
                            btn.replaceWith('<span style="color:#16a34a;font-weight:600">' + statusIcon + ' ' + esc(d.status) + '</span>' + note);
                        }
                        return true;
                    }
                    // 検証エラーなら再試行（修正指示はサーバー側に保存されている）
                    if (d.retryable && attempt < MAX_ATTEMPTS) {
                        return await applyOne(id, btn, attempt + 1);
                    }
                    logFailure(id, 'api', null, d.message || '失敗');
                    showFailure(btn, d.message || '失敗');
                    return false;
                }
                logFailure(id, 'bad-response', res, '応答が不正');
                showFailure(btn, '応答が不正です（詳細はブラウザのコンソールに出力しました）');
            } catch (e) {
                const msg = describeAjaxError(e);
                logFailure(id, 'http', e, msg);
                showFailure(btn, msg);
            }
            return false;
        }

        async function applyAll() {
            if (!posts.length) { alert('対象がありません'); return; }
            const model = $('input[name=decofmt_deco_bulk_model]:checked').val() || '';
            const modelInfo = cfg().models[model];
            const cost = modelInfo ? (posts.length * modelInfo.cost_yen) : 0;
            const costNote = modelInfo
                ? '（' + modelInfo.label + ' 約' + modelInfo.cost_yen + '円×' + posts.length + '件 = 約 ¥' + cost.toLocaleString() + '）'
                : '';
            const concurrency = Math.max(1, Math.min(5, parseInt($('#decofmt_deco_concurrency').val(), 10) || 3));

            if (!confirm(
                posts.length + '件を装飾します' + costNote + '。\n' +
                '同時実行数: ' + concurrency + '\n\n' +
                '⚠️ 処理中はこのタブを閉じないでください（閉じると残りが止まります）。\n' +
                '実行しますか？'
            )) return;

            $('#decofmt-deco-apply-all-btn').prop('disabled', true);
            $('#decofmt-deco-stop-bulk-btn').show();
            bulkStopped = false;

            const total = posts.length;
            const queue = posts.slice();   // 取り出し用のキュー（元配列は壊さない）
            const startedAt = Date.now();
            let done = 0, failed = 0;

            // サーキットブレーカー: 連続で失敗し続けるなら、残り全部を無駄に消費する前に止める。
            // （APIキー切れ・残高不足・WAFブロック等は、続けても全部失敗するため）
            const ABORT_AFTER_CONSECUTIVE_FAILURES = 5;
            let consecutiveFailures = 0;
            let abortedByFailures = false;

            function updateStatus() {
                const finished = done + failed;
                let msg = '適用中... ' + finished + '/' + total + '件（成功 ' + done + ' / 失敗 ' + failed + '）';
                if (finished >= 3) {
                    // 実測ペースから残り時間を出す（見積もりではなく実測）
                    const perItem = (Date.now() - startedAt) / finished;
                    const remainMin = Math.ceil((perItem * (total - finished)) / 60000);
                    msg += ' — 残り約 ' + remainMin + ' 分';
                }
                $('#decofmt-deco-apply-status').text(msg);
            }

            // ワーカーを concurrency 個だけ起動し、各自がキューから取って処理する。
            // 前の記事の完了を待たずに次が走るので、API待ち時間が重なって全体が短くなる。
            async function worker() {
                while (true) {
                    if (bulkStopped) return;
                    const p = queue.shift();
                    if (!p) return;
                    const btn = $('tr[data-id="' + p.id + '"] .decofmt-deco-row-apply');
                    const ok = await applyOne(p.id, btn.length ? btn : null);
                    if (ok) {
                        done++;
                        consecutiveFailures = 0;
                    } else {
                        failed++;
                        consecutiveFailures++;
                        if (consecutiveFailures >= ABORT_AFTER_CONSECUTIVE_FAILURES) {
                            abortedByFailures = true;
                            bulkStopped = true;   // 他のワーカーも止める
                            return;
                        }
                    }
                    updateStatus();
                }
            }

            updateStatus();
            await Promise.all(Array.from({ length: concurrency }, worker));

            const mins = Math.round((Date.now() - startedAt) / 60000);
            if (abortedByFailures) {
                $('#decofmt-deco-apply-status').html(
                    '<span style="color:#dc2626;font-weight:600">⛔ ' + ABORT_AFTER_CONSECUTIVE_FAILURES + '件連続で失敗したため中止しました</span>' +
                    '（成功 ' + done + ' / 失敗 ' + failed + ' / 未処理 ' + queue.length + '）<br>' +
                    '<span style="font-size:12px">同じ原因で残りも失敗する可能性が高いため、残りは実行していません。' +
                    '各行の赤い文字（マウスを乗せると全文）とブラウザのコンソール（F12）にエラーの詳細が出ています。</span>'
                );
            } else if (bulkStopped) {
                $('#decofmt-deco-apply-status').text(
                    '中断: 成功 ' + done + '件 / 失敗 ' + failed + '件（未処理 ' + queue.length + '件）'
                );
            } else {
                $('#decofmt-deco-apply-status').text(
                    '完了: 成功 ' + done + '件 / 失敗 ' + failed + '件（所要 約' + mins + '分）'
                );
            }
            $('#decofmt-deco-apply-all-btn').prop('disabled', false);
            $('#decofmt-deco-stop-bulk-btn').hide();
        }

        function esc(s) {
            return String(s == null ? '' : s).replace(/[<>&"]/g, function (c) {
                return ({'<':'&lt;','>':'&gt;','&':'&amp;','"':'&quot;'})[c];
            });
        }
    })(jQuery);
    </script>
    <?php
}
