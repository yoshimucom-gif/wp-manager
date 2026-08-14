<?php
/**
 * 一括スキャン&適用ページ
 *
 * 全記事をスキャンして「未挿入 (last_insert_at メタなし)」を抽出。
 * ボタン1発で全件に順次適用。
 */

if (!defined('ABSPATH')) exit;

function affiros_ai_render_bulk_page() {
    if (!current_user_can('manage_options')) return;
    $settings = affiros_ai_get_settings();

    // 事前チェック: API 未設定なら警告
    $checks = [];
    $checks['claude']  = !empty($settings['claude_api_key']);
    $checks['amazon']  = !empty($settings['amazon_client_id']) && !empty($settings['amazon_client_secret']) && !empty($settings['amazon_partner_tag']);
    $checks['rakuten'] = !empty($settings['rakuten_app_id']) && !empty($settings['rakuten_access_key']);

    ?>
    <div class="wrap">
        <h1>✨ Affiros オートインサーター — 一括挿入</h1>

        <?php if (!$checks['claude']): ?>
            <div class="notice notice-error"><p>Claude API キーが未設定です。<a href="<?php echo esc_url(admin_url('admin.php?page=affiros-ai-settings')); ?>">設定画面</a>で入力してください。</p></div>
        <?php endif; ?>
        <?php if (!$checks['amazon'] && !$checks['rakuten']): ?>
            <div class="notice notice-error"><p>Amazon / 楽天 の API が両方とも未設定です。少なくともどちらか設定してください。</p></div>
        <?php endif; ?>

        <p style="font-size:13px;line-height:1.7">
            全記事をスキャンして「未挿入」「除外」「対象外(ランキング)」に振り分けます。「未挿入」の記事に一括で商品カードを挿入できます。
            <br>1記事あたり Claude Haiku 約<strong>¥0.5</strong>（キーワード抽出＋商品のAI検品）＋ Amazon/楽天 API (無料)。
            <br>検索結果が全て別カテゴリ商品でキーワード再抽出が発動した記事は 約¥1.0〜1.4（概算・全体の1割前後の想定）。100件で <strong>¥50〜60目安</strong>。
        </p>

        <div style="margin:16px 0">
            <button type="button" id="ai-scan-btn" class="button button-primary" <?php disabled(!$checks['claude']); ?>>🔍 全記事スキャン</button>
            <span id="ai-scan-status" style="margin-left:12px;color:#666;font-size:13px"></span>
        </div>

        <div id="ai-result" style="display:none">
            <div style="margin:0 0 10px;display:flex;gap:18px;align-items:center;flex-wrap:wrap;font-size:13px;background:#fff;border:1px solid #ccd0d4;border-radius:4px;padding:10px 14px">
                <strong>🔎 絞り込み</strong>
                <label>カテゴリー:
                    <select id="ai-filter-cat"><option value="">すべて</option></select>
                </label>
                <label>最終挿入:
                    <select id="ai-filter-date">
                        <option value="">すべて</option>
                        <option value="never">未挿入のみ</option>
                        <option value="7">7日以上前</option>
                        <option value="30">30日以上前</option>
                        <option value="90">90日以上前</option>
                    </select>
                </label>
                <label>公開日:
                    <input type="date" id="ai-filter-pubdate" style="font-size:13px"> 以降
                </label>
                <label>カード:
                    <select id="ai-filter-cards">
                        <option value="">すべて</option>
                        <option value="lost">⚠️ 消失 (挿入済なのに0枚)</option>
                        <option value="1">1枚</option>
                        <option value="2">2枚</option>
                    </select>
                </label>
                <span id="ai-filter-count" style="color:#666"></span>
                <span style="color:#a06000;font-size:12px">※ 一括ボタンは絞り込み表示中の記事だけに実行されます</span>
            </div>
            <div style="margin:0 0 12px">
                <button type="button" id="ai-apply-all-btn" class="button button-primary">✨ 未挿入・消失の記事に一括適用</button>
                <button type="button" id="ai-reapply-all-btn" class="button" style="margin-left:8px">🔄 挿入済の記事に一括再挿入</button>
                <span id="ai-apply-status" style="margin-left:12px;font-size:13px"></span>
                <div style="font-size:12px;color:#666;margin-top:6px">
                    ⚠️ 適用にはブラウザタブを開いたままにする必要があります (JS ループ方式)。
                    ・100件処理 ≒ 10〜20分 ・¥30程度
                    <br>🔄 再挿入は既存カードを削除して入れ直します (位置ルール変更やキーワード精度改善を既存記事に反映する用。重複しません)
                </div>
            </div>
            <table class="wp-list-table widefat striped">
                <thead>
                    <tr>
                        <th style="width:60px">ID</th>
                        <th>タイトル</th>
                        <th style="width:120px">状態</th>
                        <th style="width:70px">カード</th>
                        <th style="width:140px">キーワード</th>
                        <th style="width:180px">最終挿入</th>
                        <th style="width:200px">アクション</th>
                    </tr>
                </thead>
                <tbody id="ai-result-tbody"></tbody>
            </table>
        </div>

        <h2 style="margin-top:36px">🕐 月次リフレッシュ履歴</h2>
        <?php
        $mr_on = ($settings['monthly_refresh'] ?? 'yes') === 'yes';
        $next  = wp_next_scheduled('affiros_ai_daily_refresh');
        $log   = get_option('affiros_ai_refresh_log', []);
        if (!is_array($log)) $log = [];
        ?>
        <p style="font-size:13px;color:#666">
            状態: <?php echo $mr_on ? '<span style="color:#0a7a2f;font-weight:600">有効</span>' : '<span style="color:#c62828;font-weight:600">無効</span>'; ?>
            （挿入から30日経過した記事を毎日10件ずつ自動更新・リビジョン無し・更新日保持）
            <?php if ($next): ?>
                ・次回実行: <?php echo esc_html(date_i18n('Y-m-d H:i', $next + (int)(get_option('gmt_offset') * 3600))); ?>
            <?php endif; ?>
        </p>
        <?php if (empty($log)): ?>
            <p style="color:#999;font-size:13px">まだ履歴がありません（30日経過した記事が出てくると自動で記録されます）。</p>
        <?php else: ?>
            <table class="wp-list-table widefat striped" style="max-width:900px">
                <thead><tr>
                    <th style="width:150px">日時</th>
                    <th>記事</th>
                    <th>結果</th>
                </tr></thead>
                <tbody>
                <?php foreach (array_slice(array_reverse($log), 0, 50) as $e):
                    $title = get_the_title($e['id']) ?: ('#' . $e['id']);
                    $link  = get_permalink($e['id']);
                    if ($e['ok'] && empty($e['skip'])) {
                        $result = '<span style="color:#0a7a2f">✓ ' . esc_html($e['msg']) . '</span>';
                    } elseif (!empty($e['skip'])) {
                        $result = '<span style="color:#888">− ' . esc_html($e['msg']) . '</span>';
                    } else {
                        $result = '<span style="color:#c62828;font-weight:600">✗ ' . esc_html($e['msg']) . '</span>';
                    }
                ?>
                    <tr>
                        <td><?php echo esc_html($e['t']); ?></td>
                        <td><?php if ($link): ?><a href="<?php echo esc_url($link); ?>" target="_blank"><?php echo esc_html($title); ?></a><?php else: echo esc_html($title); endif; ?></td>
                        <td><?php echo $result; ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>

        <script>
        jQuery(function ($) {
            const ajaxUrl = (window.AffirosAI && AffirosAI.ajaxUrl) || ajaxurl;
            const nonce   = (window.AffirosAI && AffirosAI.nonce) || '';
            let posts = [];
            let abort = false;

            $('#ai-scan-btn').on('click', scan);
            $('#ai-apply-all-btn').on('click', () => applyBatch(['pending', 'lost'], '挿入'));
            $('#ai-reapply-all-btn').on('click', () => applyBatch(['done'], '再挿入'));
            $('#ai-filter-cat, #ai-filter-date, #ai-filter-cards, #ai-filter-pubdate').on('change', render);

            // 現在の絞り込み条件を通過した記事だけを返す
            function filteredPosts() {
                const cat = $('#ai-filter-cat').val();
                const dateOpt = $('#ai-filter-date').val();
                const cardsOpt = $('#ai-filter-cards').val();
                const pubFrom = $('#ai-filter-pubdate').val(); // YYYY-MM-DD or ''
                let th = null;
                if (dateOpt && dateOpt !== 'never') {
                    const d = new Date(Date.now() - parseInt(dateOpt, 10) * 86400000);
                    th = d.toISOString().slice(0, 10); // YYYY-MM-DD
                }
                return posts.filter(p => {
                    if (cat && !(p.cats || []).includes(parseInt(cat, 10))) return false;
                    if (dateOpt === 'never') {
                        if (p.last_insert_at) return false;
                    } else if (th) {
                        // 「N日以上前」= 挿入済みかつ最終挿入がしきい値より古い
                        if (!p.last_insert_at || p.last_insert_at.slice(0, 10) > th) return false;
                    }
                    if (cardsOpt === 'lost') {
                        if (!(p.last_insert_at && (p.cards || 0) === 0)) return false;
                    } else if (cardsOpt !== '') {
                        if ((p.cards || 0) !== parseInt(cardsOpt, 10)) return false;
                    }
                    if (pubFrom && (!p.date || p.date < pubFrom)) return false;
                    return true;
                });
            }

            async function scan() {
                $('#ai-scan-btn').prop('disabled', true);
                $('#ai-result').hide();
                $('#ai-scan-status').text('スキャン中...');
                try {
                    const res = await $.post(ajaxUrl + '?action=affiros_ai_scan', {
                        action: 'affiros_ai_scan',
                        nonce: nonce,
                    });
                    if (!res || !res.success) {
                        alert('スキャン失敗: ' + (res && res.data ? res.data : '(unknown)'));
                        return;
                    }
                    posts = res.data.posts || [];
                    // カテゴリー絞り込みの選択肢を構築 (記事があるカテゴリーだけ、件数付き)
                    const catCount = {};
                    posts.forEach(p => (p.cats || []).forEach(c => { catCount[c] = (catCount[c] || 0) + 1; }));
                    const $catSel = $('#ai-filter-cat').empty().append('<option value="">すべて</option>');
                    (res.data.categories || []).forEach(c => {
                        if (catCount[c.id]) $catSel.append(`<option value="${c.id}">${esc(c.name)} (${catCount[c.id]})</option>`);
                    });
                    const stats = res.data.stats || {};
                    const lostHtml = (stats.lost || 0) > 0
                        ? ` / <span style="color:#c62828;font-weight:700">⚠️ カード消失 ${stats.lost}件</span>`
                        : '';
                    $('#ai-scan-status').html(
                        `スキャン完了: ${res.data.scanned}件 / 未挿入 <strong>${stats.pending || 0}</strong>件 / 挿入済 ${stats.done || 0}件 / 除外 ${stats.excluded || 0}件 / 除外(分類) ${stats.taxonomy || 0}件 / ランキング ${stats.ranking || 0}件${lostHtml}`
                    );
                    render();
                    $('#ai-result').show();
                } catch (e) {
                    alert('通信エラー\nstatus=' + (e && e.status) + ' text=' + (e && e.statusText) + '\n' + ((e && e.responseText) ? String(e.responseText).slice(0, 300) : ''));
                    if (window.console) console.error('scan failed', e);
                } finally {
                    $('#ai-scan-btn').prop('disabled', false);
                }
            }

            function render() {
                const tbody = $('#ai-result-tbody').empty();
                const list = filteredPosts();
                $('#ai-filter-count').text(`表示中 ${list.length}件 / 全${posts.length}件`);
                list.forEach(p => {
                    const editUrl = `${location.origin}/wp-admin/post.php?post=${p.id}&action=edit`;
                    const viewUrl = p.link || editUrl; // タイトル = 公開URL (確認用)
                    const stateBadge = badge(p.state);
                    const canApply = (p.state === 'pending' || p.state === 'done' || p.state === 'lost');
                    const cardsCell = (p.last_insert_at && (p.cards || 0) === 0)
                        ? '<span style="color:#c62828;font-weight:700">⚠️ 0枚</span>'
                        : `${p.cards || 0}枚`;
                    tbody.append(`
                        <tr data-id="${p.id}">
                            <td>${p.id}</td>
                            <td><a href="${viewUrl}" target="_blank">${esc(p.title)}</a> <a href="${editUrl}" target="_blank" style="font-size:11px;color:#999;text-decoration:none">[編集]</a></td>
                            <td>${stateBadge}</td>
                            <td>${cardsCell}</td>
                            <td>${esc(p.keyword || '')}</td>
                            <td>${esc(p.last_insert_at || '')}</td>
                            <td>
                                ${canApply ? `<button type="button" class="button button-small ai-apply-one" data-id="${p.id}">✨ 適用</button>` : ''}
                            </td>
                        </tr>
                    `);
                });
                $('.ai-apply-one').on('click', function () {
                    const id = parseInt($(this).data('id'), 10);
                    applyOne(id, $(this));
                });
            }

            function badge(state) {
                const styles = {
                    pending:  ['#c62828', '未挿入'],
                    lost:     ['#c62828', '⚠️ 消失'],
                    done:     ['#0a7a2f', '挿入済'],
                    excluded: ['#666',    '除外'],
                    taxonomy: ['#666',    '除外(分類)'],
                    ranking:  ['#a06000', 'ランキング'],
                };
                const [c, label] = styles[state] || ['#999', state];
                return `<span style="color:${c};font-weight:600">${label}</span>`;
            }

            async function applyOne(id, btn) {
                if (btn) btn.prop('disabled', true).text('適用中...');
                try {
                    const res = await $.post(ajaxUrl + '?action=affiros_ai_apply', {
                        action: 'affiros_ai_apply',
                        nonce: nonce,
                        post_id: id,
                    });
                    if (res && res.success) {
                        const data = res.data || {};
                        if (btn) {
                            const label = data.changed
                                ? `<span style="color:#16a34a;font-weight:600">✓ ${esc(data.message || '完了')}</span>`
                                : `<span style="color:#666">${esc(data.message || '無変更')}</span>`;
                            btn.replaceWith(label);
                        }
                        return { ok: true, changed: !!data.changed };
                    } else {
                        const msg = res && res.data ? String(res.data) : '不明';
                        if (btn) btn.replaceWith(`<span style="color:#c62828">✗ ${esc(msg).slice(0, 40)}</span>`);
                        return { ok: false, message: msg };
                    }
                } catch (e) {
                    if (btn) btn.replaceWith(`<span style="color:#c62828">✗ 通信エラー ${e && e.status}</span>`);
                    return { ok: false, message: 'network' };
                }
            }

            async function applyBatch(targetStates, verb) {
                const filtered = filteredPosts();
                const targets = filtered.filter(p => targetStates.includes(p.state));
                const filterOn = filtered.length !== posts.length;
                if (!targets.length) { alert(`${targetStates.includes('pending') ? '未挿入・消失' : '挿入済'}の対象がありません${filterOn ? ' (絞り込み適用中)' : ''}`); return; }
                if (!confirm(`${filterOn ? '【絞り込み適用中】' : ''}${targets.length} 件に順次${verb}します。想定コスト: ¥${Math.round(targets.length * 0.5)}〜¥${Math.round(targets.length * 0.65)} 前後 (再抽出発動分を含む概算)。よろしいですか？`)) return;

                abort = false;
                $('#ai-apply-all-btn, #ai-reapply-all-btn').prop('disabled', true);
                let done = 0, failed = 0;
                for (const p of targets) {
                    if (abort) break;
                    $('#ai-apply-status').text(`${verb}中 ${done + failed + 1}/${targets.length}... #${p.id}`);
                    const btn = $(`tr[data-id="${p.id}"] .ai-apply-one`);
                    const r = await applyOne(p.id, btn.length ? btn : null);
                    if (r.ok && r.changed) done++;
                    else failed++;
                    await sleep(300); // API連続叩き回避
                }
                $('#ai-apply-status').html(`完了: 成功 <strong>${done}</strong>件 / 失敗 ${failed}件`);
                $('#ai-apply-all-btn, #ai-reapply-all-btn').prop('disabled', false);
            }

            function sleep(ms) { return new Promise(r => setTimeout(r, ms)); }
            function esc(s) {
                return String(s == null ? '' : s).replace(/[<>&"]/g, c => ({'<':'&lt;','>':'&gt;','&':'&amp;','"':'&quot;'}[c]));
            }
        });
        </script>
    </div>
    <?php
}

// =============================================================================
// AJAX
// =============================================================================

add_action('wp_ajax_affiros_ai_scan', function () {
    check_ajax_referer('affiros_ai_nonce', 'nonce');
    if (!current_user_can('manage_options')) wp_send_json_error('権限なし');
    @set_time_limit(120);

    $settings = affiros_ai_get_settings();
    $statuses = array_filter(array_map('trim', explode(',', $settings['target_statuses'] ?? 'publish,future,draft')));
    if (empty($statuses)) $statuses = ['publish'];

    global $wpdb;
    $placeholders = implode(',', array_fill(0, count($statuses), '%s'));
    $rows = $wpdb->get_results($wpdb->prepare(
        "SELECT ID, post_title, post_status, post_date FROM {$wpdb->posts}
         WHERE post_type = 'post' AND post_status IN ($placeholders) ORDER BY ID DESC",
        ...$statuses
    ));

    $posts = [];
    $stats = ['pending' => 0, 'done' => 0, 'excluded' => 0, 'ranking' => 0, 'taxonomy' => 0, 'lost' => 0];
    foreach ($rows as $r) {
        $post_id = intval($r->ID);
        $post_obj = get_post($post_id);
        $keyword = get_post_meta($post_id, AFFIROS_AI_META_KEYWORD, true);
        $last_insert = get_post_meta($post_id, AFFIROS_AI_META_LAST_INSERT_AT, true);
        $excluded = get_post_meta($post_id, AFFIROS_AI_META_EXCLUDED, true);

        // 本文のカード枚数を実測 (挿入済のはずなのに0枚 = 他プロセスの上書きで消失)
        $cards = substr_count((string)$post_obj->post_content, 'affiros-ai-card-start');

        if ($excluded === 'yes') {
            $state = 'excluded'; $stats['excluded']++;
        } elseif (Affiros_AI_Ranking_Detector::is_ranking($post_obj)) {
            $state = 'ranking'; $stats['ranking']++;
        } elseif (affiros_ai_taxonomy_excluded($post_id, $settings)) {
            $state = 'taxonomy'; $stats['taxonomy']++;
        } elseif (!empty($last_insert)) {
            // 挿入メタはあるが本文にカードが無い = 他プロセスの上書きで消失。
            // 実質未挿入なので独立状態にし、「未挿入に一括適用」の対象へ含める
            if ($cards === 0) {
                $state = 'lost'; $stats['lost']++;
            } else {
                $state = 'done'; $stats['done']++;
            }
        } else {
            $state = 'pending'; $stats['pending']++;
        }

        $posts[] = [
            'id' => $post_id,
            'title' => $r->post_title,
            'link' => get_permalink($post_id),
            'state' => $state,
            'keyword' => $keyword,
            'last_insert_at' => $last_insert,
            'cats' => array_map('intval', wp_get_post_categories($post_id)),
            'cards' => $cards,
            'date' => substr((string)$r->post_date, 0, 10), // 公開日 (YYYY-MM-DD)
        ];
    }

    $categories = array_map(function ($c) {
        return ['id' => $c->term_id, 'name' => $c->name];
    }, get_categories(['hide_empty' => false, 'orderby' => 'name']));

    wp_send_json_success([
        'scanned' => count($rows),
        'stats'   => $stats,
        'posts'   => $posts,
        'categories' => array_values($categories),
    ]);
});

add_action('wp_ajax_affiros_ai_apply', function () {
    check_ajax_referer('affiros_ai_nonce', 'nonce');
    if (!current_user_can('manage_options')) wp_send_json_error('権限なし');
    @set_time_limit(60);

    $post_id = intval($_POST['post_id'] ?? 0);
    if (!$post_id) wp_send_json_error('post_id 不正');

    // 手動実行 = 「今の結果をやり直したい」なのでキーワードも再抽出する
    // (プロンプト改善を既存記事のキャッシュ済みKWにも反映させる。Haiku 約¥0.5/回)
    $res = Affiros_AI_Inserter::process($post_id, [
        'force_refresh_keyword'  => true,
        'force_refresh_products' => true,
    ]);
    if (!$res['success']) wp_send_json_error($res['message'] ?? 'unknown');
    wp_send_json_success($res);
});
