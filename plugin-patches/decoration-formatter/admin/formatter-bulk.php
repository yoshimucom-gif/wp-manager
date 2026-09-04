<?php
/**
 * 段落整形 一括処理画面
 */

if (!defined('ABSPATH')) exit;

function decofmt_render_fmt_bulk_page() {
    if (!current_user_can('manage_options')) return;

    $fmt = decofmt_fmt_get_settings();
    $one_sentence = (($fmt['one_sentence_per_paragraph'] ?? 'no') === 'yes');

    // v1.0.27: 絞り込み用
    $post_types   = decofmt_fmt_get_post_types();
    $default_type = isset($post_types['post']) ? 'post' : (string) array_key_first($post_types);
    $type_has_cat = [];
    foreach ($post_types as $pt_name => $pt_label) {
        $type_has_cat[$pt_name] = is_object_in_taxonomy($pt_name, 'category');
    }
    $categories = get_categories(['hide_empty' => false]);
    ?>
    <div class="wrap decofmt-wrap">
        <h1>📝 段落整形 一括処理</h1>
        <p style="font-size:13px;line-height:1.7">
            既存の全記事をスキャンし、整形対象をリスト表示します。
            プレビューで before/after を確認してから個別 or 一括で適用できます。<br>
            WP リビジョンが自動保存されるので<strong>適用後でも元に戻せます</strong>。
        </p>

        <!-- v1.0.27: 投稿タイプ（投稿／固定ページ）とカテゴリで対象を絞り込む -->
        <div id="decofmt-fmt-filter-box" style="margin:16px 0;padding:14px 16px;border:1px solid #c3c4c7;border-left:4px solid #7c3aed;background:#fff;border-radius:4px;max-width:860px">
            <div style="font-weight:600;margin-bottom:8px">絞り込み</div>

            <table class="form-table" style="margin:0">
                <tr>
                    <th scope="row" style="width:120px;padding:8px 10px 8px 0">投稿タイプ</th>
                    <td style="padding:8px 0">
                        <?php foreach ($post_types as $pt_name => $pt_label): ?>
                            <label style="display:inline-block;margin:0 14px 4px 0">
                                <input type="radio" name="decofmt_fmt_post_type" value="<?php echo esc_attr($pt_name); ?>" <?php checked($default_type, $pt_name); ?>>
                                <?php echo esc_html($pt_label); ?>
                                <?php if (!$type_has_cat[$pt_name]): ?>
                                    <span style="color:#888;font-size:11px">（カテゴリなし）</span>
                                <?php endif; ?>
                            </label>
                        <?php endforeach; ?>
                        <p class="description" style="margin:4px 0 0">投稿と固定ページは<strong>まとめず1種類ずつ</strong>処理します。切り替えると自動で再スキャンします。</p>
                    </td>
                </tr>

                <?php if (!empty($categories)): ?>
                <tr id="decofmt-fmt-cat-row">
                    <th scope="row" style="width:120px;padding:8px 10px 8px 0">カテゴリ</th>
                    <td style="padding:8px 0">
                        <div class="decofmt-checkbox-list" id="decofmt-fmt-cat-list">
                            <?php foreach ($categories as $cat): ?>
                                <label>
                                    <input type="checkbox" class="decofmt-fmt-cat" value="<?php echo esc_attr($cat->term_id); ?>">
                                    <?php echo esc_html($cat->name); ?>
                                    <span class="decofmt-count">(<?php echo esc_html($cat->count); ?>)</span>
                                </label>
                            <?php endforeach; ?>
                        </div>
                        <p style="margin:6px 0 0">
                            <button type="button" class="button button-small" id="decofmt-fmt-cat-clear">選択を解除</button>
                            <span class="description" style="margin-left:8px">未選択＝全カテゴリ。複数選ぶと<strong>いずれかに属する記事</strong>が対象（子カテゴリも含む）。</span>
                        </p>
                        <p class="description" id="decofmt-fmt-cat-note" style="margin:4px 0 0;color:#b45309;display:none">
                            この投稿タイプはカテゴリを持たないため、カテゴリ絞り込みは適用されません。
                        </p>
                    </td>
                </tr>
                <?php endif; ?>
            </table>
        </div>

        <!-- v1.0.26: 設定画面を開かなくても「今どのモードで処理するか」が分かるよう、
             処理画面にモード切替を置く。保存先は設定画面と同じオプション。 -->
        <div id="decofmt-fmt-mode-box" style="margin:16px 0;padding:14px 16px;border:1px solid #c3c4c7;border-left:4px solid #2271b1;background:#fff;border-radius:4px;max-width:860px">
            <div style="font-weight:600;margin-bottom:8px">整形モード<span id="decofmt-fmt-mode-saving" style="margin-left:10px;font-weight:400;font-size:12px;color:#666"></span></div>

            <label style="display:block;margin:6px 0;line-height:1.6">
                <input type="radio" name="decofmt_fmt_mode" value="normal" <?php checked(!$one_sentence); ?>>
                <strong>通常</strong>
                <span style="color:#555">— 長い段落（既定200字超）と、密な段落・見出しっぽい段落だけを整形する</span>
            </label>

            <label style="display:block;margin:6px 0;line-height:1.6">
                <input type="radio" name="decofmt_fmt_mode" value="one_sentence" <?php checked($one_sentence); ?>>
                <strong>⚡ 1文ごとに改行</strong>
                <span style="color:#555">— 句点（。！？）2個以上の段落を、字数に関係なく<strong>全部句点で分割</strong>する</span>
            </label>

            <p class="description" style="margin:8px 0 0">
                変更すると<strong>その場で保存され、自動で再スキャン</strong>します（対象件数が変わるため）。<br>
                設定画面の「1文ごとに改行する」と同じ項目です。どちらで変えても同じ状態になります。
            </p>
        </div>

        <div style="margin:16px 0">
            <button type="button" id="decofmt-fmt-scan-btn" class="button button-primary">🔍 全記事スキャン</button>
            <span id="decofmt-fmt-scan-status" style="margin-left:12px;color:#666;font-size:13px"></span>
        </div>

        <div id="decofmt-fmt-result" style="display:none">
            <div style="margin:0 0 12px">
                <button type="button" id="decofmt-fmt-apply-all-btn" class="button button-primary">✨ 全件に適用</button>
                <span id="decofmt-fmt-apply-status" style="margin-left:12px;font-size:13px"></span>
            </div>
            <table class="wp-list-table widefat striped">
                <thead>
                    <tr>
                        <th style="width:60px">ID</th>
                        <th>タイトル</th>
                        <th style="width:90px">種別</th>
                        <th style="width:90px">段落数</th>
                        <th style="width:90px">最大字数</th>
                        <th style="width:120px">200字超</th>
                        <th style="width:140px">見出し昇格候補</th>
                        <th style="width:140px" title="&lt;li&gt;&lt;strong&gt;ラベル&lt;/strong&gt;：長文 パターン">strongラベル</th>
                        <th style="width:130px" id="decofmt-fmt-msc-head"><?php
                            echo $one_sentence ? '2句以上の段落' : '3句以上短段落';
                        ?></th>
                        <th style="width:220px">アクション</th>
                    </tr>
                </thead>
                <tbody id="decofmt-fmt-result-tbody"></tbody>
            </table>
        </div>
    </div>

    <script>
    (function ($) {
        // decofmt グローバルは wp_localize_script でフッターに出力されるため、
        // 本スクリプト（body内インライン）実行時点ではまだ未定義。
        // クリック時に評価するために関数化する。
        function cfg() {
            return {
                url:   (window.decofmt && decofmt.ajaxUrl) || (window.ajaxurl || ''),
                nonce: (window.decofmt && decofmt.nonce)   || ''
            };
        }
        let posts = [];

        // v1.0.27: 投稿タイプごとに「カテゴリを持つか」をPHPから受け取る
        const typeHasCat = <?php echo wp_json_encode($type_has_cat); ?>;

        $('#decofmt-fmt-scan-btn').on('click', scan);
        $('#decofmt-fmt-apply-all-btn').on('click', applyAll);

        // ------- 絞り込み -------
        function currentPostType() {
            return $('input[name=decofmt_fmt_post_type]:checked').val() || 'post';
        }
        function selectedCategories() {
            return $('.decofmt-fmt-cat:checked').map(function () { return $(this).val(); }).get();
        }
        function syncCategoryAvailability() {
            const hasCat = !!typeHasCat[currentPostType()];
            $('.decofmt-fmt-cat').prop('disabled', !hasCat);
            $('#decofmt-fmt-cat-list').css('opacity', hasCat ? '1' : '.45');
            $('#decofmt-fmt-cat-note').toggle(!hasCat);
        }
        // 絞り込みを変えたら対象が変わるので、結果表は一度たたんで再スキャンを促す
        $('input[name=decofmt_fmt_post_type]').on('change', function () {
            syncCategoryAvailability();
            scan();
        });
        $(document).on('change', '.decofmt-fmt-cat', function () {
            $('#decofmt-fmt-scan-status').text('絞り込みが変わりました。「🔍 スキャン」を押してください');
        });
        $('#decofmt-fmt-cat-clear').on('click', function () {
            $('.decofmt-fmt-cat').prop('checked', false);
            $('#decofmt-fmt-scan-status').text('絞り込みが変わりました。「🔍 スキャン」を押してください');
        });
        syncCategoryAvailability();

        // 現在のモード（表示用）。切り替えたら列見出しも合わせて変える。
        function isOneSentenceMode() {
            return $('input[name=decofmt_fmt_mode]:checked').val() === 'one_sentence';
        }
        function syncModeLabels() {
            const one = isOneSentenceMode();
            $('#decofmt-fmt-msc-head')
                .text(one ? '2句以上の段落' : '3句以上短段落')
                .attr('title', one
                    ? '句点2個以上のすべての段落（1文ごとに改行モード）'
                    : '200字以下だが句点3個以上ある密な段落');
        }

        // モード切替: その場で保存 → 対象件数が変わるので自動で再スキャン
        $('input[name=decofmt_fmt_mode]').on('change', async function () {
            const c = cfg();
            if (!c.nonce) { alert('プラグインJS未初期化：ページを再読み込みしてください'); return; }
            const mode = $(this).val();
            const $note = $('#decofmt-fmt-mode-saving');
            $note.css('color', '#666').text('保存中…');
            try {
                const res = await $.post(c.url, {
                    action: 'decofmt_fmt_set_mode',
                    nonce: c.nonce,
                    mode: mode
                });
                if (res === '-1' || res === -1) {
                    $note.css('color', '#dc2626').text('認証エラー。ページを再読み込みしてください');
                    return;
                }
                if (res && res.success) {
                    syncModeLabels();
                    $note.css('color', '#16a34a').text('✓ 保存しました。再スキャンします…');
                    await scan();
                    $note.text('');
                } else {
                    $note.css('color', '#dc2626').text('保存に失敗しました');
                }
            } catch (e) {
                $note.css('color', '#dc2626').text('通信エラー: ' + ((e && e.status) ? ('HTTP ' + e.status) : '不明'));
            }
        });

        async function scan() {
            const c = cfg();
            if (!c.nonce) { alert('プラグインJS未初期化：ページを再読み込みして再実行してください'); return; }
            $('#decofmt-fmt-scan-btn').prop('disabled', true);
            $('#decofmt-fmt-result').hide();
            $('#decofmt-fmt-result-tbody').empty();
            $('#decofmt-fmt-scan-status').text('スキャン中...');
            try {
                const res = await $.post(c.url, {
                    action: 'decofmt_fmt_scan',
                    nonce: c.nonce,
                    post_type: currentPostType(),
                    categories: selectedCategories(),
                });
                if (res === '-1' || res === -1) { alert('nonce認証エラー(-1)：ページを再読み込みして再実行してください'); return; }
                if (!res || !res.success) {
                    alert('スキャン失敗: ' + (res && res.data ? res.data : ''));
                    return;
                }
                posts = res.data.posts || [];
                const modeLabel = isOneSentenceMode() ? '⚡1文ごとに改行' : '通常';
                const d = res.data;
                let scope = d.post_type_label || currentPostType();
                if (d.category_applied) {
                    scope += ` / カテゴリ${d.category_count}件`;
                } else if (d.category_ignored) {
                    scope += ' / カテゴリ条件なし（この投稿タイプにはカテゴリがありません）';
                } else {
                    scope += ' / 全カテゴリ';
                }
                $('#decofmt-fmt-scan-status').text(
                    `スキャン完了: ${d.scanned}件チェック / 整形対象 ${posts.length}件（対象: ${scope}／モード: ${modeLabel}）`
                );
                render();
                if (posts.length) $('#decofmt-fmt-result').show();
            } catch (e) {
                const body = (e.responseText || '').trim();
                if (body === '-1') {
                    alert('nonce認証エラー(-1)：ページを再読み込みして再実行してください');
                } else {
                    alert('通信エラー: HTTP ' + (e.status || '?') + ' — ' + (body.substring(0, 300) || e.statusText || 'unknown'));
                }
            } finally {
                $('#decofmt-fmt-scan-btn').prop('disabled', false);
            }
        }

        function render() {
            const tbody = $('#decofmt-fmt-result-tbody').empty();
            posts.forEach(p => {
                // v1.0.13: タイトルクリックで記事の公開URLに飛ばす（編集画面ではない）
                const viewUrl = p.view_url || p.edit_url || `${location.origin}/?p=${p.id}`;
                const hc = p.heading_candidates || 0;
                const slc = p.strong_label_candidates || 0;
                const msc = p.multi_sentence_short || 0;
                tbody.append(`
                    <tr data-id="${p.id}">
                        <td>${p.id}</td>
                        <td><a href="${viewUrl}" target="_blank">${esc(p.title)}</a></td>
                        <td style="color:#6b7280">${esc(p.post_type_label || p.post_type || '')}</td>
                        <td>${p.count}</td>
                        <td>${p.max}字</td>
                        <td style="color:${p.over_200 > 0 ? '#dc2626' : '#6b7280'};font-weight:600">${p.over_200}件</td>
                        <td style="color:${hc > 0 ? '#d97706' : '#6b7280'};font-weight:600">${hc}件</td>
                        <td style="color:${slc > 0 ? '#2563eb' : '#6b7280'};font-weight:600">${slc}件</td>
                        <td style="color:${msc > 0 ? '#7c3aed' : '#6b7280'};font-weight:600">${msc}件</td>
                        <td>
                            <button type="button" class="button button-small decofmt-fmt-preview" data-id="${p.id}">👁 プレビュー</button>
                            <button type="button" class="button button-primary button-small decofmt-fmt-apply" data-id="${p.id}">✨ 適用</button>
                        </td>
                    </tr>
                `);
            });
            tbody.find('.decofmt-fmt-apply').on('click', function () {
                const id = $(this).data('id');
                applyOne(id, $(this));
            });
            tbody.find('.decofmt-fmt-preview').on('click', function () {
                const id = $(this).data('id');
                previewOne(id);
            });
        }

        async function previewOne(id) {
            const c = cfg();
            try {
                const res = await $.post(c.url, {
                    action: 'decofmt_fmt_preview',
                    nonce: c.nonce,
                    post_id: id,
                });
                if (res === '-1' || res === -1) { alert('nonce認証エラー(-1)：ページを再読み込みしてください'); return; }
                if (!res || !res.success) { alert('失敗'); return; }
                const w = window.open('', '_blank', 'width=1100,height=800');
                w.document.write(`
                    <html><head><title>プレビュー #${id}</title>
                    <style>body{font-family:sans-serif;font-size:14px;line-height:1.8;padding:20px;}
                    .grid{display:grid;grid-template-columns:1fr 1fr;gap:20px}
                    .col h2{margin-top:0;font-size:14px;background:#eee;padding:8px}
                    .col{border:1px solid #ddd;padding:12px;overflow:auto;max-height:90vh}
                    .col.after{background:#f0fdf4}
                    p{margin:0 0 14px;padding:6px;background:#fff;border-left:2px solid #d1d5db}
                    .col.after p{border-left-color:#16a34a}
                    </style></head><body>
                    <h1>段落整形プレビュー #${id}</h1>
                    <div class="grid">
                        <div class="col"><h2>Before</h2>${res.data.before_html}</div>
                        <div class="col after"><h2>After</h2>${res.data.after_html}</div>
                    </div>
                    </body></html>
                `);
                w.document.close();
            } catch (e) {
                alert('通信エラー: ' + (e.responseText || ''));
            }
        }

        // applyOne は {ok, changed, deltaTotal, remainingTotal} を返す
        async function applyOne(id, btn) {
            if (btn) btn.prop('disabled', true).text('適用中...');
            const c = cfg();
            try {
                const res = await $.post(c.url, {
                    action: 'decofmt_fmt_apply',
                    nonce: c.nonce,
                    post_id: id,
                });
                if (res === '-1' || res === -1) { alert('nonce認証エラー(-1)：ページを再読み込みしてください'); return { ok: false, changed: false, deltaTotal: 0, remainingTotal: 0 }; }
                if (res && res.success) {
                    const data = res.data || {};
                    const changed = !!data.changed;
                    const delta = data.delta || {};
                    const deltaTotal = (delta.over_200_resolved || 0) + (delta.heading_promoted || 0) + (delta.strong_label_split || 0) + (delta.multi_sentence_short_split || 0);
                    const remainingTotal = data.remaining_total || 0;
                    if (btn) {
                        let label;
                        if (deltaTotal > 0) {
                            label = `<span style="color:#16a34a;font-weight:600">✓ 変換 ${deltaTotal}カ所</span>`;
                        } else if (changed) {
                            // 内容は変わったが検出済みカウンタは動いていない（見出し前後の空段落など）
                            label = `<span style="color:#d97706;font-weight:600" title="整形はしたが検出済みパターンは分割できず">△ 整形のみ</span>`;
                        } else {
                            label = `<span style="color:#6b7280;font-weight:600">＝ 無変更</span>`;
                        }
                        if (remainingTotal > 0) {
                            label += ` <span style="color:#dc2626;font-size:11px">残 ${remainingTotal}件</span>`;
                        }
                        btn.replaceWith(label);
                    }
                    return { ok: true, changed, deltaTotal, remainingTotal };
                }
                alert('適用失敗: ' + (res && res.data ? res.data : ''));
            } catch (e) {
                alert('通信エラー: ' + (e.responseText || ''));
            } finally {
                if (btn && btn.prop) btn.prop('disabled', false);
            }
            return { ok: false, changed: false, deltaTotal: 0, remainingTotal: 0 };
        }

        async function applyAll() {
            if (!posts.length) { alert('対象がありません'); return; }
            const typeLabel = $('input[name=decofmt_fmt_post_type]:checked').closest('label').text().trim() || currentPostType();
            if (!confirm(`【${typeLabel}】${posts.length} 件に整形を適用します。リビジョンが自動保存されるので元に戻せます。よろしいですか？`)) return;
            $('#decofmt-fmt-apply-all-btn').prop('disabled', true);
            let done = 0, failed = 0, actuallyConverted = 0, noChange = 0, stillRemaining = 0;
            for (const p of posts) {
                $('#decofmt-fmt-apply-status').text(`適用中... ${done + failed}/${posts.length}件`);
                const btn = $(`tr[data-id="${p.id}"] .decofmt-fmt-apply`);
                const r = await applyOne(p.id, btn.length ? btn : null);
                if (r.ok) {
                    done++;
                    if (r.deltaTotal > 0) actuallyConverted++;
                    else noChange++;
                    if (r.remainingTotal > 0) stillRemaining++;
                } else {
                    failed++;
                }
            }
            $('#decofmt-fmt-apply-status').html(
                `完了: 成功 ${done}件 (実変換 ${actuallyConverted}件 / 変換なし ${noChange}件) / 失敗 ${failed}件` +
                (stillRemaining > 0 ? ` — <span style="color:#dc2626">検出済みパターンが残る記事 ${stillRemaining}件（下記スキャンで再確認）</span>` : '')
            );
            $('#decofmt-fmt-apply-all-btn').prop('disabled', false);
            // 適用後に自動で再スキャンして表を最新化する。
            // 従来は表がそのまま残り「27件成功したのにまだ27件ある」ように見えた。
            setTimeout(function () {
                $('#decofmt-fmt-scan-btn').trigger('click');
            }, 500);
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
