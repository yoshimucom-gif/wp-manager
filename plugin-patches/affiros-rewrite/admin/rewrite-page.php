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
    $tags = Affiros_Rewrite_Post_Fetcher::get_tags();
    ?>
    <div class="wrap affiros-wrap">
        <h1>Affiros リライター</h1>
        <p class="description">
            WP_Query で記事を内部取得するため、ホスティングの WAF / 海外IP制限の影響を受けません（403回避）。
        </p>

        <!-- v0.4.47: タブナビゲーション（機能ごとに UI を切替） -->
        <h2 class="nav-tab-wrapper" style="margin-top:18px;">
            <a href="#" class="nav-tab nav-tab-active affiros-tab-nav" data-tab="rewrite">✍ リライト</a>
            <a href="#" class="nav-tab affiros-tab-nav" data-tab="ads">📢 広告削除&amp;挿入</a>
            <a href="#" class="nav-tab affiros-tab-nav" data-tab="reorder">🔀 章入れ替え</a>
        </h2>
        <div class="affiros-tab-desc" data-tab="rewrite" style="margin:8px 0 12px;padding:8px 12px;background:#f0f6fc;border-left:3px solid #2271b1;font-size:13px;">
            <strong>✍ リライト</strong>: Claude API で記事本文を書き直します。料金あり（¥数十/記事）。マーカー挿入も同時実行可（オプション参照）。
        </div>
        <div class="affiros-tab-desc" data-tab="ads" style="display:none;margin:8px 0 12px;padding:8px 12px;background:#f0f6fc;border-left:3px solid #2271b1;font-size:13px;">
            <strong>📢 広告削除&amp;挿入</strong>: 既存の商品カード・マーカーの除去 / 新規マーカー挿入 / 除去+挿入の一括リセット。Claude 呼ばず料金ゼロ。
        </div>
        <div class="affiros-tab-desc" data-tab="reorder" style="display:none;margin:8px 0 12px;padding:8px 12px;background:#f0f6fc;border-left:3px solid #2271b1;font-size:13px;">
            <strong>🔀 章入れ替え</strong>: H2 章の順序を SEO 最適（選定基準→ランキング→選び方→FAQ→まとめ）に並び替え。本文自体は変更しない。Claude 呼ばず料金ゼロ。
        </div>

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
            <select id="affiros-category" style="padding:6px 28px 6px 10px;min-width:160px;">
                <option value="0">全カテゴリー</option>
                <?php foreach ($categories as $c): ?>
                    <option value="<?php echo intval($c['id']); ?>"><?php echo esc_html($c['name']); ?> (<?php echo intval($c['count']); ?>)</option>
                <?php endforeach; ?>
            </select>
            <select id="affiros-status" style="padding:6px 28px 6px 10px;min-width:110px;">
                <option value="publish">公開済</option>
                <option value="draft">下書き</option>
                <option value="any">すべて</option>
            </select>
            <select id="affiros-marker-filter" style="padding:6px 28px 6px 10px;min-width:170px;" title="マーカー検証結果でフィルタ">
                <option value="">マーカー状態：全て</option>
                <option value="ok">✅ 正常のみ</option>
                <option value="warning">⚠️ 警告のみ</option>
                <option value="error">❌ 異常のみ</option>
                <option value="warning_or_error">⚠️/❌ 要対応のみ</option>
                <option value="unknown">未計測のみ</option>
            </select>
            <select id="affiros-per-page" style="padding:6px 28px 6px 10px;min-width:120px;">
                <option value="20">20件/ページ</option>
                <option value="50">50件/ページ</option>
                <option value="100">100件/ページ</option>
            </select>
            <button type="button" class="button button-primary" id="affiros-fetch-btn">投稿を取得</button>
        </div>

        <!-- 件数別目安（参考情報） -->
        <details style="margin:0 0 14px;background:#f6f9fc;border:1px solid #cfd9e6;border-radius:4px;">
            <summary style="padding:8px 12px;cursor:pointer;font-weight:600;color:#2271b1;">📊 件数別の所要時間・コスト目安（クリックで展開）</summary>
            <table style="width:100%;border-collapse:collapse;font-size:12px;margin:0;">
                <thead>
                    <tr style="background:#f0f6fc;">
                        <th style="padding:6px 10px;border-bottom:1px solid #ddd;text-align:left;">件数</th>
                        <th style="padding:6px 10px;border-bottom:1px solid #ddd;text-align:left;">所要時間</th>
                        <th style="padding:6px 10px;border-bottom:1px solid #ddd;text-align:left;">コスト目安</th>
                        <th style="padding:6px 10px;border-bottom:1px solid #ddd;text-align:left;">実用性</th>
                    </tr>
                </thead>
                <tbody>
                    <tr><td style="padding:6px 10px;">5件</td><td style="padding:6px 10px;">5〜10分</td><td style="padding:6px 10px;">〜30円</td><td style="padding:6px 10px;color:#0a7a2f;">⭐⭐⭐⭐⭐ テスト最適</td></tr>
                    <tr style="background:#fafafa;"><td style="padding:6px 10px;">10件</td><td style="padding:6px 10px;">10〜20分</td><td style="padding:6px 10px;">〜60円</td><td style="padding:6px 10px;color:#0a7a2f;">⭐⭐⭐⭐⭐ 快適</td></tr>
                    <tr><td style="padding:6px 10px;">20件</td><td style="padding:6px 10px;">20〜40分</td><td style="padding:6px 10px;">〜120円</td><td style="padding:6px 10px;color:#0a7a2f;">⭐⭐⭐⭐ 日常運用◎</td></tr>
                    <tr style="background:#fafafa;"><td style="padding:6px 10px;">50件</td><td style="padding:6px 10px;">50分〜1.5時間</td><td style="padding:6px 10px;">〜300円</td><td style="padding:6px 10px;color:#a06000;">⭐⭐⭐ ブラウザ拘束辛い</td></tr>
                    <tr><td style="padding:6px 10px;">100件</td><td style="padding:6px 10px;">1.5〜3時間</td><td style="padding:6px 10px;">〜600円</td><td style="padding:6px 10px;color:#c00;">⭐⭐ 拷問レベル</td></tr>
                </tbody>
            </table>
            <div style="padding:8px 12px;background:#fff8f0;border-top:1px solid #f0d8a0;font-size:11px;color:#8a5800;line-height:1.6;">
                <strong>⚠️ 大量実行時の注意</strong><br>
                ・ブラウザタブを閉じると残りは処理されません（JS ループ方式）<br>
                ・PC スリープ・WiFi 切断で停止します<br>
                ・SiteGuard / WAF が連続POST で 403 を返すことがあります（管理ページアクセス制限を一時OFF推奨）<br>
                ・<strong>大量処理は 20〜30件ずつ × 数回の分割実行が現実的</strong><br>
                ・100件以上を一晩で回したい場合は Affiros9 本体側のリライト機能を検討してください
            </div>
        </details>

        <!-- 除外フィルター -->
        <details style="margin-bottom:14px;background:#fff8f0;border:1px solid #f0d8a0;border-radius:4px;">
            <summary style="padding:10px 14px;cursor:pointer;font-weight:600;color:#8a5800;">🚫 除外設定（タグ・カテゴリ・キーワードで一覧から除外）</summary>
            <div style="padding:12px 14px;border-top:1px solid #f0d8a0;display:flex;gap:12px;flex-wrap:wrap;">
                <label style="display:flex;flex-direction:column;gap:4px;min-width:240px;flex:1;">
                    <span style="font-size:12px;color:#666;">除外タグ（複数選択可）</span>
                    <select id="affiros-exclude-tags" multiple size="5" style="padding:4px;min-height:110px;">
                        <?php foreach ($tags as $t): ?>
                            <option value="<?php echo intval($t['id']); ?>"><?php echo esc_html($t['name']); ?> (<?php echo intval($t['count']); ?>)</option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label style="display:flex;flex-direction:column;gap:4px;min-width:240px;flex:1;">
                    <span style="font-size:12px;color:#666;">除外カテゴリ（複数選択可）</span>
                    <select id="affiros-exclude-cats" multiple size="5" style="padding:4px;min-height:110px;">
                        <?php foreach ($categories as $c): ?>
                            <option value="<?php echo intval($c['id']); ?>"><?php echo esc_html($c['name']); ?> (<?php echo intval($c['count']); ?>)</option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label style="display:flex;flex-direction:column;gap:4px;min-width:240px;flex:1;">
                    <span style="font-size:12px;color:#666;">除外キーワード（タイトルに含む記事を除外。カンマ区切りで複数可）</span>
                    <textarea id="affiros-exclude-kw" rows="5" placeholder="PR, レビュー, スポンサード" style="padding:6px;font-family:inherit;"></textarea>
                    <span style="font-size:11px;color:#888;">例: 「PR」「レビュー」など、リライトしたくない記事のタイトル特徴語</span>
                </label>
            </div>
            <div style="padding:8px 14px;border-top:1px solid #f0d8a0;background:#fff;">
                <button type="button" class="button" id="affiros-clear-excludes">除外条件をクリア</button>
                <span class="description" style="margin-left:8px;color:#666;">変更後は「投稿を取得」を押して反映してください</span>
            </div>
        </details>

        <!-- リライト共通オプション（リライト・広告タブでのみ表示） -->
        <div class="affiros-tab-panel" data-tab="rewrite ads" style="margin-bottom:14px;padding:12px;background:#fafafa;border:1px solid #e0e0e0;border-radius:4px;">
            <strong style="display:block;margin-bottom:8px;">オプション</strong>
            <div style="display:flex;gap:12px;align-items:center;flex-wrap:wrap;">
                <label>
                    記事タイプ:
                    <select id="affiros-article-type">
                        <option value="">— 指定なし（マーカー挿入なし）</option>
                        <option value="auto" selected>自動判定（タイトルから判定）</option>
                        <option value="ranking">ランキング</option>
                        <option value="brand">商標</option>
                        <option value="column">コラム</option>
                    </select>
                </label>
                <label class="affiros-tab-only" data-tab="rewrite">
                    <input type="checkbox" id="affiros-insert-markers" checked>
                    リライト後にマーカーを挿入する
                </label>
            </div>
        </div>

        <!-- 一括操作バー：タブごとに表示ボタンを切替 -->
        <div id="affiros-bulk-bar" style="display:none;margin-bottom:10px;padding:10px;background:#f0f6fc;border-left:4px solid #2271b1;">
            <strong><span id="affiros-bulk-count">0</span></strong> 件選択中
            <!-- リライトタブのボタン -->
            <span class="affiros-tab-only" data-tab="rewrite">
                <button type="button" class="button button-primary" id="affiros-bulk-rewrite-btn" style="margin-left:12px;" <?php echo $has_api_key ? '' : 'disabled'; ?>>
                    ✍ 一括リライト
                </button>
            </span>
            <!-- 広告タブのボタン -->
            <span class="affiros-tab-only" data-tab="ads">
                <button type="button" class="button" id="affiros-bulk-cleanup-btn" style="margin-left:12px;" title="選択記事のカード・マーカーを全部除去（挿入はしない）">
                    🗑 一括除去
                </button>
                <button type="button" class="button" id="affiros-bulk-insert-btn" style="margin-left:6px;" title="選択記事にマーカー挿入（既存があれば拒否・N選ならstrict判定）">
                    🎯 一括挿入
                </button>
                <button type="button" class="button button-primary" id="affiros-bulk-reset-btn" style="margin-left:6px;" title="🗑除去→🎯挿入 を1記事ごとに連続実行（マーカー位置を一発で治す）">
                    🔁 一括リセット（除去→挿入）
                </button>
            </span>
            <!-- 章並替タブのボタン -->
            <span class="affiros-tab-only" data-tab="reorder">
                <button type="button" class="button button-primary" id="affiros-bulk-reorder-btn" style="margin-left:12px;" title="選択記事の H2 章順序を SEO 最適に並び替え（Claude 不要）">
                    🔀 一括章並替
                </button>
            </span>
            <span class="description" style="margin-left:10px;">実行前に確認ダイアログあり。各記事の結果はログに順次表示。</span>
            <?php if (!$has_api_key): ?>
                <div class="affiros-tab-only" data-tab="rewrite" style="margin-top:6px;color:#b32d2e;font-size:12px;">
                    ⚠ Claude APIキーが未設定のため「一括リライト」は実行できません。
                    <a href="<?php echo esc_url(admin_url('admin.php?page=affiros-rewrite-settings')); ?>">設定画面で入力 →</a>
                </div>
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
                <div style="margin-bottom:8px;font-size:11px;color:#a06000;">※ 処理はブラウザ上で動きます。完了までこのタブを閉じないでください（閉じると残りは処理されません）。</div>
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
            var excludeTags = $('#affiros-exclude-tags').val() || [];
            var excludeCats = $('#affiros-exclude-cats').val() || [];
            var excludeKw   = ($('#affiros-exclude-kw').val() || '').trim();
            var markerFilter = $('#affiros-marker-filter').val() || '';
            $.post(AffirosRewrite.ajaxUrl, {
                action: 'affiros_rewrite_fetch_posts',
                nonce: AffirosRewrite.nonce,
                page: currentPage,
                per_page: $('#affiros-per-page').val(),
                search: $('#affiros-search').val(),
                category: $('#affiros-category').val(),
                status: $('#affiros-status').val(),
                'exclude_tags[]':       excludeTags,
                'exclude_categories[]': excludeCats,
                exclude_keywords:       excludeKw,
                marker_filter:          markerFilter,
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
            html += '<th style="width:120px;">リライト履歴</th>';
            html += '<th style="width:140px;">マーカー状態</th>';
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
                // リライト履歴カラム
                var rwCount = parseInt(p.rewrite_count, 10) || 0;
                var rwHtml = '';
                if (rwCount === 0) {
                    rwHtml = '<span style="color:#aaa;font-size:11px;">未実施</span>';
                } else {
                    var color = rwCount >= 5 ? '#c00' : (rwCount >= 3 ? '#d97706' : '#0a7a2f');
                    rwHtml = '<span style="color:' + color + ';font-weight:600;">🔄 ' + rwCount + ' 回</span>';
                    if (p.rewrite_last_at) {
                        rwHtml += '<div style="font-size:10px;color:#888;margin-top:2px;">' + escapeHtml(p.rewrite_last_at) + '</div>';
                    }
                }
                html += '<td>' + rwHtml + '</td>';
                // マーカー状態カラム
                var mkHtml = '';
                if (!p.marker_status) {
                    mkHtml = '<span style="color:#aaa;font-size:11px;">未計測</span>';
                } else if (p.marker_status === 'ok') {
                    mkHtml = '<span style="color:#0a7a2f;font-weight:600;">✅ 正常</span>';
                } else if (p.marker_status === 'warning') {
                    mkHtml = '<span style="color:#d97706;font-weight:600;">⚠️ 警告</span>'
                          + '<div style="font-size:10px;color:#888;margin-top:2px;">' + escapeHtml(p.marker_summary || '') + '</div>';
                } else if (p.marker_status === 'error') {
                    mkHtml = '<span style="color:#c00;font-weight:600;">❌ 異常</span>'
                          + '<div style="font-size:10px;color:#c00;margin-top:2px;">' + escapeHtml(p.marker_summary || '') + '</div>';
                } else {
                    mkHtml = '<span style="color:#aaa;font-size:11px;">' + escapeHtml(p.marker_status) + '</span>';
                }
                html += '<td>' + mkHtml + '</td>';
                html += '<td>' + escapeHtml(p.modified) + '</td>';
                html += '<td>';
                // タブ別ボタン: 各ボタンに data-tab を付けて、activeTab に応じて表示/非表示
                html += '<span class="affiros-tab-only" data-tab="rewrite"><button type="button" class="button button-primary button-small affiros-rewrite-btn" data-post-id="' + p.id + '">✍ リライト</button></span> ';
                html += '<span class="affiros-tab-only" data-tab="ads"><button type="button" class="button button-small affiros-cleanup-btn" data-post-id="' + p.id + '" title="既存の商品カード・マーカーを全て除去（挿入はしない）。除去後に🎯挿入で新規配置します。">🗑 マーカー消す</button></span> ';
                html += '<span class="affiros-tab-only" data-tab="ads"><button type="button" class="button button-small affiros-insert-btn" data-post-id="' + p.id + '" title="Claude を呼ばずに記事タイプ別ルールでマーカー挿入。既存マーカーが検出されたら🗑で先に消してください。ランキング記事は N選 のN個 全部揃わないと保存拒否。">🎯 マーカー挿入</button></span> ';
                html += '<span class="affiros-tab-only" data-tab="reorder"><button type="button" class="button button-small affiros-reorder-btn" data-post-id="' + p.id + '" title="H2 章の順序を SEO 最適（選定基準→ランキング→選び方→FAQ→まとめ）に並び替え。Claude 不要。">🔀 章並替</button></span> ';
                html += '<a href="' + p.edit_link + '" target="_blank" class="button button-small">編集</a>';
                html += '</td>';
                html += '</tr>';
            });
            html += '</tbody></table>';
            html += '<div style="padding:10px;color:#666;">' + data.total + '件中 ' + items.length + '件表示</div>';
            $('#affiros-result').html(html);
            renderPagination(data);
            updateBulkBar();
            // v0.4.47: テーブル再描画後にタブ切替を適用（新しい .affiros-tab-only 要素にも反映）
            if (typeof applyTab === 'function' && typeof currentTab !== 'undefined') applyTab(currentTab);
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

        // 記事タイプが選ばれたらマーカーチェックを自動ON、「指定なし」のみ無効化
        function syncMarkerCheckbox() {
            const hasType = !!$('#affiros-article-type').val();
            $('#affiros-insert-markers').prop('disabled', !hasType);
            if (hasType) {
                $('#affiros-insert-markers').prop('checked', true);
            } else {
                $('#affiros-insert-markers').prop('checked', false);
            }
        }
        $('#affiros-article-type').on('change', syncMarkerCheckbox);
        syncMarkerCheckbox();

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

        // --- 🗑 マーカー消す（v0.4.42）---
        // Pre_Cleanup だけ実行。既存の商品カード・マーカーを全部除去。
        function runCleanup(postId) {
            const $row = $('tr[data-post-id="' + postId + '"]');
            const $btn = $row.find('.affiros-cleanup-btn');
            const origLabel = $btn.html();
            $btn.prop('disabled', true).html('除去中...');
            return $.post(AffirosRewrite.ajaxUrl, {
                action: 'affiros_rewrite_cleanup_markers',
                nonce: AffirosRewrite.nonce,
                post_id: postId,
            }).done(function(resp) {
                if (!resp.success) {
                    alert('マーカー除去に失敗しました: ' + (resp.data?.message || '不明'));
                    return;
                }
                openResultModal(resp.data);
            }).fail(function(xhr) {
                alert('通信エラー: HTTP ' + xhr.status);
            }).always(function() {
                $btn.prop('disabled', false).html(origLabel);
            });
        }
        $('#affiros-result').on('click', '.affiros-cleanup-btn', function() {
            const postId = $(this).data('post-id');
            runCleanup(postId);
        });

        // --- 🎯 マーカー挿入（v0.4.42、Pre_Cleanup しない）---
        // 既存マーカー検出時はサーバー側で保存拒否。ランキング記事は strict 判定。
        function runInsert(postId) {
            const $row = $('tr[data-post-id="' + postId + '"]');
            const $btn = $row.find('.affiros-insert-btn');
            const origLabel = $btn.html();
            $btn.prop('disabled', true).html('挿入中...');
            const articleType = $('#affiros-article-type').val() || 'auto';
            return $.post(AffirosRewrite.ajaxUrl, {
                action: 'affiros_rewrite_insert_markers_new',
                nonce: AffirosRewrite.nonce,
                post_id: postId,
                article_type: articleType,
            }).done(function(resp) {
                if (!resp.success) {
                    // existing_markers_detected の時は特別なガイダンスを出す
                    const code = resp.data?.code || '';
                    const msg = resp.data?.message || '不明';
                    if (code === 'existing_markers_detected') {
                        alert('⚠️ ' + msg);
                    } else if (code === 'ranking_marker_count_mismatch') {
                        alert('⚠️ ランキング数不整合\n\n' + msg);
                    } else {
                        alert('マーカー挿入に失敗しました: ' + msg);
                    }
                    return;
                }
                openResultModal(resp.data);
            }).fail(function(xhr) {
                alert('通信エラー: HTTP ' + xhr.status);
            }).always(function() {
                $btn.prop('disabled', false).html(origLabel);
            });
        }
        $('#affiros-result').on('click', '.affiros-insert-btn', function() {
            const postId = $(this).data('post-id');
            runInsert(postId);
        });

        // --- 🔀 章並び替え（v0.4.46）---
        function runReorder(postId) {
            const $row = $('tr[data-post-id="' + postId + '"]');
            const $btn = $row.find('.affiros-reorder-btn');
            const origLabel = $btn.html();
            $btn.prop('disabled', true).html('並替中...');
            return $.post(AffirosRewrite.ajaxUrl, {
                action: 'affiros_rewrite_reorder_sections',
                nonce: AffirosRewrite.nonce,
                post_id: postId,
            }).done(function(resp) {
                if (!resp.success) {
                    const code = resp.data?.code || '';
                    const msg = resp.data?.message || '不明';
                    if (code === 'reorder_no_change') {
                        alert('ℹ️ ' + msg);
                    } else {
                        alert('章並び替えに失敗しました: ' + msg);
                    }
                    return;
                }
                openResultModal(resp.data);
            }).fail(function(xhr) {
                alert('通信エラー: HTTP ' + xhr.status);
            }).always(function() {
                $btn.prop('disabled', false).html(origLabel);
            });
        }
        $('#affiros-result').on('click', '.affiros-reorder-btn', function() {
            const postId = $(this).data('post-id');
            runReorder(postId);
        });

        function openResultModal(data) {
            $('#affiros-modal-title').text('リライト結果: ' + (data.rewritten_title || ''));
            $('#affiros-modal-orig-title').val(data.original_title || '');
            $('#affiros-modal-orig-content').val(data.original_content || '');
            $('#affiros-modal-new-title').val(data.rewritten_title || '');
            $('#affiros-modal-new-content').val(data.rewritten_content || '');
            const usage = data.usage || {};
            const tokens = (usage.input_tokens || 0) + '/' + (usage.output_tokens || 0) + ' tokens (in/out)';
            const tags = [];
            if (data.article_type) tags.push('タイプ: ' + data.article_type + (data.article_type_auto ? '（自動判定）' : ''));
            if (data.markers_inserted) tags.push('マーカー挿入: ✓');
            // マーカー検証結果バッジ
            const mv = data.marker_validation || null;
            if (mv && mv.status) {
                const label = mv.status === 'ok' ? '✅ マーカー正常'
                            : mv.status === 'warning' ? '⚠️ マーカー警告'
                            : '❌ マーカー異常';
                tags.push(label + (mv.summary && mv.status !== 'ok' ? '（' + mv.summary + '）' : ''));
            }
            const tagsLine = tags.length ? ' / ' + tags.join(' / ') : '';
            $('#affiros-modal-usage').text('モデル: ' + (data.model || '?') + ' / ' + tokens + tagsLine);
            // 検証結果を保存時に forward するため data 属性に格納
            $('#affiros-modal').data('post-id', data.post_id)
                               .data('marker-validation', mv ? JSON.stringify(mv) : '')
                               .css('display', 'flex');
        }

        function closeResultModal() { $('#affiros-modal').hide(); }

        function saveModal() {
            const postId = $('#affiros-modal').data('post-id');
            const title = $('#affiros-modal-new-title').val();
            const content = $('#affiros-modal-new-content').val();
            const markerValidation = $('#affiros-modal').data('marker-validation') || '';
            if (!content.trim()) { alert('本文が空です'); return; }
            if (!confirm('この内容でWordPress投稿を上書き保存します。\n（WordPressのリビジョン機能で元に戻せます）\n\nよろしいですか?')) return;
            const $btn = $('#affiros-modal-save').prop('disabled', true).text('保存中...');
            $.post(AffirosRewrite.ajaxUrl, {
                action: 'affiros_rewrite_save',
                nonce: AffirosRewrite.nonce,
                post_id: postId,
                title: title,
                content: content,
                marker_validation: markerValidation,
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
            // 実行中にタブを閉じる/離脱すると残りが処理されないため、ブラウザの離脱警告を出す
            window.onbeforeunload = function() {
                return '一括リライトを実行中です。このページを離れると残りの記事は処理されません。';
            };
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

                    const mvObj = result.data.marker_validation || null;
                    const saved = await jqXhrPromise($.post(AffirosRewrite.ajaxUrl, {
                        action: 'affiros_rewrite_save',
                        nonce: AffirosRewrite.nonce,
                        post_id: id,
                        title: result.data.rewritten_title,
                        content: result.data.rewritten_content,
                        marker_validation: mvObj ? JSON.stringify(mvObj) : '',
                    }));
                    if (!saved.success) throw new Error(saved.data?.message || 'save failed');
                    const t = result.data.article_type ? ' [' + result.data.article_type + (result.data.article_type_auto ? '/自動判定' : '') + ']' : '';
                    const mk = result.data.markers_inserted ? ' +マーカー' : '';
                    const mvStatus = mvObj && mvObj.status
                        ? (mvObj.status === 'ok' ? ' ✅マーカーOK'
                           : mvObj.status === 'warning' ? ' ⚠️マーカー警告:' + (mvObj.summary || '')
                           : ' ❌マーカー異常:' + (mvObj.summary || ''))
                        : '';
                    appendBulkLog('  ✓ #' + id + ' 保存完了' + t + mk + mvStatus,
                        mvObj && mvObj.status === 'error' ? 'warn' : 'success');
                    succeeded++;
                } catch (e) {
                    appendBulkLog('  ✗ #' + id + ' 失敗: ' + e.message, 'error');
                    failed++;
                }
                done++;
                $('#affiros-bulk-done').text(done);
                $('#affiros-bulk-progress').css('width', (done / ids.length * 100) + '%');
            }

            window.onbeforeunload = null;
            $('#affiros-bulk-status').text('完了: 成功 ' + succeeded + ' / 失敗 ' + failed + ' / 全 ' + ids.length);
            $('#affiros-bulk-close').show();
            $('#affiros-bulk-cancel').hide();
        }

        function jqXhrPromise(jqXhr) {
            return new Promise(function(resolve, reject) {
                jqXhr.done(resolve).fail(function(xhr) { reject(new Error('HTTP ' + xhr.status)); });
            });
        }

        // ---- v0.4.43 一括処理: 🗑 除去 / 🎯 挿入 / 🔁 リセット ----
        // 各記事1件ずつ順次実行（confirm 1回、進捗ログ表示、リビジョン自動作成）。
        async function runBulkOp(mode) {
            const ids = $('.affiros-pick:checked').map(function() { return parseInt($(this).val(), 10); }).get();
            if (!ids.length) return;

            const modeLabels = {
                cleanup: '🗑 マーカー除去',
                insert:  '🎯 マーカー挿入',
                reset:   '🔁 リセット（除去→挿入）',
                reorder: '🔀 章順序を SEO 最適化',
            };
            const label = modeLabels[mode] || mode;
            if (!confirm(ids.length + ' 件の記事に「' + label + '」を実行し、即座に WP へ上書き保存します。\n\n' +
                         '各記事にリビジョンが自動作成されるので個別に元に戻せます。\n\n' +
                         '実行しますか？')) return;

            const articleType = $('#affiros-article-type').val() || 'auto';

            bulkAbort = false;
            window.onbeforeunload = function() {
                return '一括処理を実行中です。このページを離れると残りの記事は処理されません。';
            };
            $('#affiros-bulk-modal').css('display', 'flex');
            $('#affiros-bulk-total').text(ids.length);
            $('#affiros-bulk-done').text(0);
            $('#affiros-bulk-progress').css('width', '0%');
            $('#affiros-bulk-log').html('');
            $('#affiros-bulk-close').hide();
            $('#affiros-bulk-cancel').show();
            $('#affiros-bulk-status').text('開始しています... (' + label + ')');

            let done = 0, succeeded = 0, failed = 0, skipped = 0;
            for (const id of ids) {
                if (bulkAbort) { appendBulkLog('中止しました', 'warn'); break; }
                appendBulkLog('[' + (done + 1) + '/' + ids.length + '] post #' + id + ' 処理中...', 'info');
                $('#affiros-bulk-status').text('[' + (done + 1) + '/' + ids.length + '] post #' + id);

                try {
                    // mode に応じて処理選択
                    if (mode === 'reset') {
                        // 1. cleanup → save → 2. insert → save
                        const c = await jqXhrPromise($.post(AffirosRewrite.ajaxUrl, {
                            action: 'affiros_rewrite_cleanup_markers',
                            nonce: AffirosRewrite.nonce,
                            post_id: id,
                        }));
                        if (!c.success) throw new Error('除去失敗: ' + (c.data?.message || '不明'));
                        const s1 = await jqXhrPromise($.post(AffirosRewrite.ajaxUrl, {
                            action: 'affiros_rewrite_save',
                            nonce: AffirosRewrite.nonce,
                            post_id: id,
                            title: c.data.rewritten_title,
                            content: c.data.rewritten_content,
                        }));
                        if (!s1.success) throw new Error('除去後保存失敗: ' + (s1.data?.message || '不明'));

                        const i = await jqXhrPromise($.post(AffirosRewrite.ajaxUrl, {
                            action: 'affiros_rewrite_insert_markers_new',
                            nonce: AffirosRewrite.nonce,
                            post_id: id,
                            article_type: articleType,
                        }));
                        if (!i.success) {
                            const errCode = i.data?.code || '';
                            const errMsg = i.data?.message || '不明';
                            throw new Error('挿入失敗 [' + errCode + ']: ' + errMsg);
                        }
                        const mv = i.data.marker_validation || null;
                        const s2 = await jqXhrPromise($.post(AffirosRewrite.ajaxUrl, {
                            action: 'affiros_rewrite_save',
                            nonce: AffirosRewrite.nonce,
                            post_id: id,
                            title: i.data.rewritten_title,
                            content: i.data.rewritten_content,
                            marker_validation: mv ? JSON.stringify(mv) : '',
                        }));
                        if (!s2.success) throw new Error('挿入後保存失敗: ' + (s2.data?.message || '不明'));

                        const mkCount = (i.data.marker_stats?.marker_count) || 0;
                        const mvStatus = mv?.status === 'ok' ? ' ✅' : mv?.status === 'warning' ? ' ⚠️' : '';
                        appendBulkLog('  ✓ #' + id + ' リセット完了（' + mkCount + '個マーカー挿入）' + mvStatus, 'success');
                    } else if (mode === 'cleanup') {
                        const c = await jqXhrPromise($.post(AffirosRewrite.ajaxUrl, {
                            action: 'affiros_rewrite_cleanup_markers',
                            nonce: AffirosRewrite.nonce,
                            post_id: id,
                        }));
                        if (!c.success) throw new Error(c.data?.message || '不明');
                        const s = await jqXhrPromise($.post(AffirosRewrite.ajaxUrl, {
                            action: 'affiros_rewrite_save',
                            nonce: AffirosRewrite.nonce,
                            post_id: id,
                            title: c.data.rewritten_title,
                            content: c.data.rewritten_content,
                        }));
                        if (!s.success) throw new Error('保存失敗: ' + (s.data?.message || '不明'));
                        const rep = c.data.cleanup_report || {};
                        appendBulkLog('  ✓ #' + id + ' 除去完了（カード ' + (rep.cards_before || 0) + '→' + (rep.cards_after || 0) +
                                      ' / マーカー ' + (rep.markers_before || 0) + '→' + (rep.markers_after || 0) + '）', 'success');
                    } else if (mode === 'reorder') {
                        const r = await jqXhrPromise($.post(AffirosRewrite.ajaxUrl, {
                            action: 'affiros_rewrite_reorder_sections',
                            nonce: AffirosRewrite.nonce,
                            post_id: id,
                        }));
                        if (!r.success) {
                            const errCode = r.data?.code || '';
                            const errMsg = r.data?.message || '不明';
                            // reorder_no_change は「変更なし」なのでスキップ扱い
                            if (errCode === 'reorder_no_change') {
                                appendBulkLog('  ⏭ #' + id + ' スキップ（既に最適順序）', 'info');
                                skipped++;
                                done++;
                                $('#affiros-bulk-done').text(done);
                                $('#affiros-bulk-progress').css('width', (done / ids.length * 100) + '%');
                                continue;
                            }
                            throw new Error('[' + errCode + '] ' + errMsg);
                        }
                        const s = await jqXhrPromise($.post(AffirosRewrite.ajaxUrl, {
                            action: 'affiros_rewrite_save',
                            nonce: AffirosRewrite.nonce,
                            post_id: id,
                            title: r.data.rewritten_title,
                            content: r.data.rewritten_content,
                        }));
                        if (!s.success) throw new Error('保存失敗: ' + (s.data?.message || '不明'));
                        const sections = r.data.reorder_report?.sections || [];
                        const sectionSummary = sections.map(s => s.category).join('→');
                        appendBulkLog('  ✓ #' + id + ' 並替完了 (' + sectionSummary + ')', 'success');
                    } else if (mode === 'insert') {
                        const i = await jqXhrPromise($.post(AffirosRewrite.ajaxUrl, {
                            action: 'affiros_rewrite_insert_markers_new',
                            nonce: AffirosRewrite.nonce,
                            post_id: id,
                            article_type: articleType,
                        }));
                        if (!i.success) {
                            const errCode = i.data?.code || '';
                            const errMsg = i.data?.message || '不明';
                            // 既存マーカー検出はスキップ扱い（エラーではなく警告）
                            if (errCode === 'existing_markers_detected') {
                                appendBulkLog('  ⏭ #' + id + ' スキップ（既存マーカーあり・先に🗑除去してください）', 'warn');
                                skipped++;
                                done++;
                                $('#affiros-bulk-done').text(done);
                                $('#affiros-bulk-progress').css('width', (done / ids.length * 100) + '%');
                                continue;
                            }
                            throw new Error('[' + errCode + '] ' + errMsg);
                        }
                        const mv = i.data.marker_validation || null;
                        const s = await jqXhrPromise($.post(AffirosRewrite.ajaxUrl, {
                            action: 'affiros_rewrite_save',
                            nonce: AffirosRewrite.nonce,
                            post_id: id,
                            title: i.data.rewritten_title,
                            content: i.data.rewritten_content,
                            marker_validation: mv ? JSON.stringify(mv) : '',
                        }));
                        if (!s.success) throw new Error('保存失敗: ' + (s.data?.message || '不明'));
                        const mkCount = (i.data.marker_stats?.marker_count) || 0;
                        const mvStatus = mv?.status === 'ok' ? ' ✅' : mv?.status === 'warning' ? ' ⚠️' : '';
                        appendBulkLog('  ✓ #' + id + ' 挿入完了（' + mkCount + '個マーカー）' + mvStatus, 'success');
                    }
                    succeeded++;
                } catch (e) {
                    appendBulkLog('  ✗ #' + id + ' 失敗: ' + e.message, 'error');
                    failed++;
                }
                done++;
                $('#affiros-bulk-done').text(done);
                $('#affiros-bulk-progress').css('width', (done / ids.length * 100) + '%');
            }

            window.onbeforeunload = null;
            const skipMsg = skipped > 0 ? ' / スキップ ' + skipped : '';
            $('#affiros-bulk-status').text('完了: 成功 ' + succeeded + ' / 失敗 ' + failed + skipMsg + ' / 全 ' + ids.length);
            $('#affiros-bulk-close').show();
            $('#affiros-bulk-cancel').hide();
        }

        function appendBulkLog(msg, kind) {
            const colors = { info: '#333', success: '#0a7a2f', error: '#c00', warn: '#a06000' };
            const c = colors[kind] || '#333';
            $('#affiros-bulk-log').append('<div style="color:' + c + ';">' + escapeHtml(msg) + '</div>').scrollTop(99999);
        }

        // --- v0.4.47: タブナビゲーション ---
        // localStorage で選択タブを永続化。次回開いた時に前回のタブを復元。
        let currentTab = localStorage.getItem('affiros_rewrite_tab') || 'rewrite';

        function applyTab(tab) {
            currentTab = tab;
            localStorage.setItem('affiros_rewrite_tab', tab);

            // タブナビの active 表示切替
            $('.affiros-tab-nav').removeClass('nav-tab-active');
            $('.affiros-tab-nav[data-tab="' + tab + '"]').addClass('nav-tab-active');

            // タブ説明の切替
            $('.affiros-tab-desc').hide();
            $('.affiros-tab-desc[data-tab="' + tab + '"]').show();

            // タブ限定要素の表示切替
            $('.affiros-tab-only').each(function() {
                const allowed = ($(this).data('tab') || '').toString().split(/\s+/);
                if (allowed.includes(tab)) {
                    $(this).show();
                } else {
                    $(this).hide();
                }
            });

            // タブ切替時にモーダルを閉じる（別タブの操作が残ってると混乱するので）
            $('#affiros-bulk-modal').hide();
            closeResultModal();
        }

        $('.affiros-tab-nav').on('click', function(e) {
            e.preventDefault();
            applyTab($(this).data('tab'));
        });

        // ページロード時に前回タブを適用
        applyTab(currentTab);

        // --- イベントバインド ---
        $('#affiros-fetch-btn').on('click', function() { fetchPosts(1); });
        $('#affiros-clear-excludes').on('click', function() {
            $('#affiros-exclude-tags').val([]);
            $('#affiros-exclude-cats').val([]);
            $('#affiros-exclude-kw').val('');
            fetchPosts(1);
        });
        $('#affiros-result').on('change', '#affiros-check-all', function() {
            $('.affiros-pick').prop('checked', $(this).prop('checked'));
            updateBulkBar();
        });
        $('#affiros-result').on('change', '.affiros-pick', updateBulkBar);
        $('#affiros-result').on('click', '.affiros-rewrite-btn', function() {
            runSingleRewrite(parseInt($(this).data('post-id'), 10));
        });
        $('#affiros-bulk-rewrite-btn').on('click', runBulkRewrite);
        // v0.4.43 一括処理ボタン
        $('#affiros-bulk-cleanup-btn').on('click', function() { runBulkOp('cleanup'); });
        $('#affiros-bulk-insert-btn').on('click', function() { runBulkOp('insert'); });
        $('#affiros-bulk-reset-btn').on('click', function() { runBulkOp('reset'); });
        $('#affiros-bulk-reorder-btn').on('click', function() { runBulkOp('reorder'); });

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
