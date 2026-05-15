<?php
/**
 * リライト実行画面（投稿一覧 + リライト操作）
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

        <div class="affiros-rewrite-toolbar" style="display:flex;gap:10px;align-items:center;margin:18px 0;flex-wrap:wrap;">
            <input
                type="text"
                id="affiros-search"
                placeholder="タイトル・本文を検索..."
                style="flex:1;min-width:240px;padding:6px 10px;"
            >
            <select id="affiros-category" style="padding:6px;">
                <option value="0">全カテゴリー</option>
                <?php foreach ($categories as $c): ?>
                    <option value="<?php echo intval($c['id']); ?>">
                        <?php echo esc_html($c['name']); ?> (<?php echo intval($c['count']); ?>)
                    </option>
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

        <div id="affiros-result" style="background:#fff;border:1px solid #ccd0d4;padding:0;min-height:200px;">
            <div style="padding:40px;text-align:center;color:#888;">
                「投稿を取得」ボタンを押すと、このサイトの記事一覧が表示されます。
            </div>
        </div>

        <div id="affiros-pagination" style="margin-top:12px;text-align:center;"></div>
    </div>

    <script>
    jQuery(function($) {
        let currentPage = 1;

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
                return;
            }
            let html = '<table class="wp-list-table widefat striped"><thead><tr>';
            html += '<th style="width:40px;"><input type="checkbox" id="affiros-check-all"></th>';
            html += '<th>タイトル</th><th style="width:120px;">カテゴリー</th><th style="width:80px;">文字数</th>';
            html += '<th style="width:100px;">更新日</th><th style="width:120px;">操作</th>';
            html += '</tr></thead><tbody>';
            items.forEach(function(p) {
                html += '<tr>';
                html += '<td><input type="checkbox" class="affiros-pick" value="' + p.id + '"></td>';
                html += '<td><strong>' + escapeHtml(p.title) + '</strong>';
                if (p.excerpt) {
                    html += '<div style="font-size:11px;color:#888;margin-top:4px;">' + escapeHtml(p.excerpt.substr(0, 80)) + '...</div>';
                }
                html += '</td>';
                html += '<td>' + escapeHtml(p.category) + '</td>';
                html += '<td>' + p.word_count + '</td>';
                html += '<td>' + escapeHtml(p.modified) + '</td>';
                html += '<td>';
                html += '<a href="' + p.edit_link + '" target="_blank" class="button button-small">編集</a>';
                html += '</td>';
                html += '</tr>';
            });
            html += '</tbody></table>';
            html += '<div style="padding:10px;color:#666;">' + data.total + '件中 ' + items.length + '件表示</div>';
            $('#affiros-result').html(html);

            renderPagination(data);
        }

        function renderPagination(data) {
            const totalPages = data.total_pages;
            const page = data.page;
            if (totalPages <= 1) {
                $('#affiros-pagination').html('');
                return;
            }
            let html = '';
            if (page > 1) {
                html += '<button class="button" data-page="' + (page - 1) + '">← 前</button> ';
            }
            html += '<span style="margin:0 10px;">' + page + ' / ' + totalPages + '</span>';
            if (page < totalPages) {
                html += '<button class="button" data-page="' + (page + 1) + '">次 →</button>';
            }
            $('#affiros-pagination').html(html);
            $('#affiros-pagination button').on('click', function() {
                fetchPosts(parseInt($(this).data('page'), 10));
            });
        }

        function escapeHtml(s) {
            return String(s == null ? '' : s)
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;');
        }

        $('#affiros-fetch-btn').on('click', function() { fetchPosts(1); });
        $('#affiros-result').on('change', '#affiros-check-all', function() {
            $('.affiros-pick').prop('checked', $(this).prop('checked'));
        });
    });
    </script>
    <?php
}
