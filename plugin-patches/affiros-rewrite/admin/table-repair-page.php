<?php
/**
 * テーブルブロック一括修復ページ
 *
 * v0.4.35 以前のリライターが残した「Gutenberg バリデーション不適合な
 * wp:table ブロック」（編集画面で『想定されていないコンテンツ』エラー →
 * 復旧を試みると消える）を、全記事スキャンして一括修復する。
 *
 * 検出/修復ロジックは Affiros_Rewrite_Gutenberg::{count_malformed_table_blocks,
 * repair_table_blocks} を再利用。
 */

if (!defined('ABSPATH')) exit;

/**
 * 直近100件の post をサンプルチェックして、壊れた wp:table を含む記事数を返す。
 * 結果は 1 時間キャッシュ（修復後はキャッシュをクリアする）。
 */
function affiros_rewrite_quick_count_broken_tables() {
    $cached = get_transient('affiros_rewrite_broken_tables_quick');
    if ($cached !== false) return intval($cached);

    $posts = get_posts([
        'post_type'        => 'post',
        'post_status'      => ['publish', 'draft', 'future', 'private'],
        'numberposts'      => 100,
        'orderby'          => 'modified',
        'order'            => 'DESC',
        'suppress_filters' => true,
        'no_found_rows'    => true,
    ]);
    $broken = 0;
    foreach ($posts as $p) {
        if (Affiros_Rewrite_Gutenberg::count_malformed_table_blocks((string)$p->post_content) > 0) {
            $broken++;
        }
    }
    set_transient('affiros_rewrite_broken_tables_quick', $broken, HOUR_IN_SECONDS);
    return $broken;
}

/**
 * 修復後に呼ばれて quick count キャッシュをクリアする。
 * 修復 AJAX の wp_ajax_affiros_rewrite_repair_tables 内から呼び出される。
 */
function affiros_rewrite_clear_broken_tables_cache() {
    delete_transient('affiros_rewrite_broken_tables_quick');
}

/**
 * リライター系の管理ページに「壊れたテーブルがあるよ」と警告を出す。
 * 「復旧を試みる」を押す前に修復ページへ誘導するのが目的。
 */
add_action('admin_notices', function () {
    $screen = function_exists('get_current_screen') ? get_current_screen() : null;
    if (!$screen) return;
    // Affiros リライターの管理ページにいるときだけ表示（他ページに毎回出すと邪魔）
    if (strpos((string)$screen->id, 'affiros-rewrite') === false) return;
    // 修復ページ自体には出さない（重複表示防止）
    if (strpos((string)$screen->id, 'affiros-rewrite-table-repair') !== false) return;

    $broken = affiros_rewrite_quick_count_broken_tables();
    if ($broken <= 0) return;

    $repair_url = admin_url('admin.php?page=affiros-rewrite-table-repair');
    ?>
    <div class="notice notice-warning" style="border-left-color:#dc2626">
        <p style="font-size:13px;line-height:1.7">
            <strong>⚠️ 壊れたテーブルブロックが見つかりました（直近100件中 <?php echo intval($broken); ?>件）</strong><br>
            これらの記事を編集画面で開くと「想定されていないコンテンツ」エラーが出ます。
            <strong style="color:#dc2626">「復旧を試みる」ボタンは絶対に押さないでください</strong>
            （押すとブロックが消えて中身が失われます）。
            <br>
            <a href="<?php echo esc_url($repair_url); ?>" class="button button-primary" style="margin-top:6px">🔧 テーブル修復ページを開く</a>
        </p>
    </div>
    <?php
});

function affiros_rewrite_render_table_repair_page() {
    ?>
    <div class="wrap">
        <h1>🔧 テーブルブロック一括修復</h1>
        <p style="font-size:13px;line-height:1.7">
            v0.4.35 以前のリライターで生成された記事に含まれる、
            Gutenberg バリデーションを通らない <code>wp:table</code> ブロックを検出し、
            正常な形式に修復します。<br>
            （これを実行しないと編集画面で「想定されていないコンテンツが含まれています」と
            赤エラー表示され、「復旧を試みる」を押すとブロックごと消失します。）
        </p>

        <div style="background:#fffbeb;border:1px solid #fbbf24;padding:12px;margin:16px 0;border-radius:4px">
            <strong>⚠️ 注意</strong>
            <ul style="margin:6px 0 0 20px;line-height:1.7;font-size:13px">
                <li>修復前に各記事のリビジョンが自動で作成されます（WordPress 標準動作）</li>
                <li>同じ記事に対して2回実行しても安全（idempotent）</li>
                <li>1000件規模だとスキャンに数十秒〜数分かかります</li>
                <li>修復対象は <code>post_status</code> が publish / draft / future / private の post 全件</li>
            </ul>
        </div>

        <div style="margin:20px 0">
            <button type="button" id="afrt-scan-btn" class="button button-primary">🔍 全件スキャン</button>
            <span id="afrt-scan-status" style="margin-left:12px;color:#666;font-size:13px"></span>
        </div>

        <div id="afrt-scan-result" style="display:none;margin-top:16px">
            <h2 style="margin-bottom:8px">🚨 修復対象</h2>
            <p id="afrt-scan-summary" style="margin:4px 0 12px"></p>

            <div style="margin:0 0 12px">
                <button type="button" id="afrt-repair-selected-btn" class="button button-primary">✨ 選択を一括修復</button>
                <button type="button" id="afrt-repair-all-btn" class="button">⚡ 全件を一括修復</button>
                <span id="afrt-repair-status" style="margin-left:12px;font-size:13px"></span>
            </div>

            <table class="wp-list-table widefat striped">
                <thead>
                    <tr>
                        <th style="width:30px"><input type="checkbox" id="afrt-check-all" checked></th>
                        <th style="width:60px">ID</th>
                        <th>タイトル</th>
                        <th style="width:120px">壊れたブロック数</th>
                        <th style="width:160px">アクション</th>
                    </tr>
                </thead>
                <tbody id="afrt-result-tbody"></tbody>
            </table>
        </div>
    </div>

    <script>
    (function ($) {
        const ajaxUrl = (window.AffirosRewrite && AffirosRewrite.ajaxUrl) || ajaxurl;
        const nonce = (window.AffirosRewrite && AffirosRewrite.nonce) || '';
        const pageSize = 100;
        let foundPosts = [];

        $('#afrt-scan-btn').on('click', startScan);
        $('#afrt-check-all').on('change', function () {
            $('.afrt-row-check').prop('checked', this.checked);
        });
        $('#afrt-repair-selected-btn').on('click', function () {
            const ids = $('.afrt-row-check:checked').map(function () {
                return parseInt($(this).val(), 10);
            }).get();
            if (!ids.length) { alert('修復する記事を選択してください'); return; }
            if (!confirm(ids.length + '件を修復します。よろしいですか？')) return;
            repairPosts(ids);
        });
        $('#afrt-repair-all-btn').on('click', function () {
            if (!foundPosts.length) return;
            if (!confirm(foundPosts.length + '件を一括修復します。よろしいですか？')) return;
            repairPosts(foundPosts.map(function (p) { return p.id; }));
        });

        async function startScan() {
            $('#afrt-scan-btn').prop('disabled', true);
            $('#afrt-scan-result').hide();
            $('#afrt-result-tbody').empty();
            foundPosts = [];
            let offset = 0;
            let scannedTotal = 0;
            while (true) {
                $('#afrt-scan-status').text('スキャン中... ' + scannedTotal + '件 / 壊れた記事 ' + foundPosts.length + '件');
                let res;
                try {
                    res = await $.post(ajaxUrl, {
                        action: 'affiros_rewrite_scan_tables',
                        nonce: nonce,
                        offset: offset,
                        limit: pageSize,
                    });
                } catch (e) {
                    alert('スキャン通信エラー: ' + (e.responseText || e.statusText || ''));
                    $('#afrt-scan-btn').prop('disabled', false);
                    return;
                }
                if (!res || !res.success) {
                    alert('スキャン失敗: ' + (res && res.data ? res.data : 'unknown'));
                    $('#afrt-scan-btn').prop('disabled', false);
                    return;
                }
                scannedTotal += res.data.scanned;
                offset += pageSize;
                (res.data.found || []).forEach(function (p) {
                    foundPosts.push(p);
                    addRow(p);
                });
                if (res.data.scanned < pageSize) break;
            }
            $('#afrt-scan-status').text('スキャン完了: ' + scannedTotal + '件チェック / 壊れた記事 ' + foundPosts.length + '件発見');
            $('#afrt-scan-summary').text(foundPosts.length + '件の記事に修復対象のテーブルブロックがあります');
            $('#afrt-scan-btn').prop('disabled', false);
            if (foundPosts.length) {
                $('#afrt-scan-result').show();
            }
        }

        function addRow(post) {
            const editUrl = location.origin + '/wp-admin/post.php?post=' + post.id + '&action=edit';
            const row = $(
                '<tr>' +
                '<td><input type="checkbox" class="afrt-row-check" value="' + post.id + '" checked></td>' +
                '<td>' + post.id + '</td>' +
                '<td><a href="' + editUrl + '" target="_blank">' + escapeHtml(post.title) + '</a></td>' +
                '<td>' + post.broken_count + '</td>' +
                '<td><button type="button" class="button button-small afrt-repair-one" data-id="' + post.id + '">この記事のみ修復</button></td>' +
                '</tr>'
            );
            row.find('.afrt-repair-one').on('click', function () {
                repairPosts([post.id]);
            });
            $('#afrt-result-tbody').append(row);
        }

        async function repairPosts(ids) {
            $('#afrt-repair-selected-btn,#afrt-repair-all-btn').prop('disabled', true);
            let done = 0, failed = 0;
            for (const id of ids) {
                $('#afrt-repair-status').text('修復中... ' + (done + failed) + '/' + ids.length + '件');
                try {
                    const res = await $.post(ajaxUrl, {
                        action: 'affiros_rewrite_repair_tables',
                        nonce: nonce,
                        post_id: id,
                    });
                    if (res && res.success) {
                        done++;
                        const row = $('.afrt-row-check[value="' + id + '"]').closest('tr');
                        row.css('background-color', '#dcfce7').find('td:last').html('<span style="color:#16a34a">✅ 修復済み</span>');
                    } else {
                        failed++;
                    }
                } catch (e) {
                    failed++;
                }
            }
            $('#afrt-repair-status').text('修復完了: 成功 ' + done + '件 / 失敗 ' + failed + '件');
            $('#afrt-repair-selected-btn,#afrt-repair-all-btn').prop('disabled', false);
        }

        function escapeHtml(s) {
            return String(s == null ? '' : s).replace(/[<>&"]/g, function (c) {
                return ({ '<': '&lt;', '>': '&gt;', '&': '&amp;', '"': '&quot;' })[c];
            });
        }
    })(jQuery);
    </script>
    <?php
}
