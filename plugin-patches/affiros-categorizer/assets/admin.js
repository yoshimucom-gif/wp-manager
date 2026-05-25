/**
 * Affiros カテゴライザー 管理画面 JS
 *  - 投稿編集画面のメタボックス（単記事の判定）
 *  - 一括分類画面（投稿一覧の取得・順次分類）
 */
(function ($) {
    'use strict';

    var META_LABEL = '🤖 AI でカテゴリーを判定';

    /* ============================================================
     * 投稿編集画面: メタボックスの判定ボタン
     * ============================================================ */
    $(document).on('click', '.affiros-cat-run', function () {
        var $btn = $(this),
            postId = $btn.data('post-id'),
            $status = $btn.siblings('.affiros-cat-status');

        $btn.prop('disabled', true).text('判定中...');
        $status.css('color', '#666').text('');

        $.post(AffirosCat.ajaxUrl, {
            action: 'affiros_cat_classify_post',
            nonce: AffirosCat.nonce,
            post_id: postId
        }).done(function (res) {
            if (res && res.success) {
                $status.css('color', '#0a7a2f')
                    .text('✅ 「' + res.data.category + '」に設定しました。リロードします...');
                setTimeout(function () { location.reload(); }, 1200);
            } else {
                var msg = (res && res.data && res.data.message) ? res.data.message : '失敗しました';
                $status.css('color', '#c00').text('❌ ' + msg);
                $btn.prop('disabled', false).text(META_LABEL);
            }
        }).fail(function () {
            $status.css('color', '#c00').text('❌ 通信エラー');
            $btn.prop('disabled', false).text(META_LABEL);
        });
    });

    /* ============================================================
     * 一括分類画面
     * ============================================================ */
    var $table = $('#affiros-cat-table');
    if (!$table.length) {
        return;
    }

    function fetchPosts(page) {
        var $fetch = $('#affiros-cat-fetch').prop('disabled', true).text('取得中...');
        $.post(AffirosCat.ajaxUrl, {
            action: 'affiros_cat_fetch_posts',
            nonce: AffirosCat.nonce,
            page: page || 1,
            search: $('#affiros-cat-search').val(),
            category: $('#affiros-cat-filter-cat').val()
        }).done(function (res) {
            $fetch.prop('disabled', false).text('投稿を取得');
            if (!res || !res.success) {
                alert('取得に失敗しました');
                return;
            }
            renderRows(res.data);
        }).fail(function () {
            $fetch.prop('disabled', false).text('投稿を取得');
            alert('通信エラー');
        });
    }

    function renderRows(data) {
        var $tbody = $table.find('tbody').empty();

        if (!data.items.length) {
            $table.hide();
            $('#affiros-cat-bulkbar').hide();
            $('#affiros-cat-empty').show();
            $('#affiros-cat-pagination').empty();
            return;
        }
        $('#affiros-cat-empty').hide();

        data.items.forEach(function (it) {
            var $tr = $('<tr>');
            $tr.append($('<td>').append(
                $('<input type="checkbox" class="affiros-cat-cb">').val(it.id)
            ));
            $tr.append($('<td>').append(
                $('<a target="_blank" rel="noopener">').attr('href', it.edit_link).text(it.title)
            ));
            $tr.append($('<td class="affiros-cat-current">').text(it.categories));
            $tr.append($('<td class="affiros-cat-result">').text('—'));
            $tbody.append($tr);
        });

        $table.show();
        $('#affiros-cat-bulkbar').show();
        $('#affiros-cat-checkall').prop('checked', false);
        renderPagination(data);
    }

    function renderPagination(data) {
        var $p = $('#affiros-cat-pagination').empty();
        if (data.total_pages <= 1) {
            $p.text('全 ' + data.total + ' 件');
            return;
        }
        $p.append($('<span>').text('全 ' + data.total + ' 件 ／ '));
        if (data.page > 1) {
            $p.append($('<button type="button" class="button">')
                .text('← 前')
                .on('click', function () { fetchPosts(data.page - 1); }));
        }
        $p.append($('<span>').css('margin', '0 8px').text(data.page + ' / ' + data.total_pages));
        if (data.page < data.total_pages) {
            $p.append($('<button type="button" class="button">')
                .text('次 →')
                .on('click', function () { fetchPosts(data.page + 1); }));
        }
    }

    function runBulk() {
        var $rows = $('.affiros-cat-cb:checked').closest('tr');
        if (!$rows.length) {
            alert('分類する記事を選択してください');
            return;
        }
        var $btn = $('#affiros-cat-run-bulk').prop('disabled', true),
            $fetch = $('#affiros-cat-fetch').prop('disabled', true),
            $prog = $('#affiros-cat-progress'),
            total = $rows.length;

        function step(i) {
            if (i >= total) {
                $prog.text('完了（' + total + ' 件処理）');
                $btn.prop('disabled', false);
                $fetch.prop('disabled', false);
                return;
            }
            var $tr = $rows.eq(i),
                postId = $tr.find('.affiros-cat-cb').val(),
                $result = $tr.find('.affiros-cat-result');

            $prog.text('処理中 ' + (i + 1) + ' / ' + total);
            $result.css('color', '#666').text('判定中...');

            $.post(AffirosCat.ajaxUrl, {
                action: 'affiros_cat_classify_post',
                nonce: AffirosCat.nonce,
                post_id: postId
            }).done(function (res) {
                if (res && res.success) {
                    $result.css('color', '#0a7a2f').text('✅ ' + res.data.category);
                    $tr.find('.affiros-cat-current').text(res.data.category);
                } else {
                    var msg = (res && res.data && res.data.message) ? res.data.message : '失敗';
                    $result.css('color', '#c00').text('❌ ' + msg);
                }
            }).fail(function () {
                $result.css('color', '#c00').text('❌ 通信エラー');
            }).always(function () {
                step(i + 1);
            });
        }
        step(0);
    }

    $('#affiros-cat-fetch').on('click', function () { fetchPosts(1); });
    $('#affiros-cat-search').on('keydown', function (e) {
        if (e.which === 13) {
            e.preventDefault();
            fetchPosts(1);
        }
    });
    $('#affiros-cat-checkall').on('change', function () {
        $('.affiros-cat-cb').prop('checked', $(this).prop('checked'));
    });
    $('#affiros-cat-run-bulk').on('click', runBulk);

})(jQuery);
