/* Affiros カテゴリーアイキャッチ — 管理画面 */
(function ($) {
    'use strict';

    var L = window.AffirosCatEyecatch || {};

    /* ---------- 画像ピッカー（タームフォーム／設定画面 共通） ---------- */

    $(document).on('click', '.ace-pick', function (e) {
        e.preventDefault();
        var $field = $(this).closest('.ace-field');

        var frame = wp.media({
            title: L.frameTitle || '画像を選択',
            library: { type: 'image' },
            button: { text: L.frameButton || 'この画像を使う' },
            multiple: false
        });

        frame.on('select', function () {
            var att = frame.state().get('selection').first().toJSON();
            var url = (att.sizes && att.sizes.thumbnail) ? att.sizes.thumbnail.url : att.url;
            $field.find('.ace-id').val(att.id);
            $field.find('.ace-preview').html($('<img>').attr('src', url));
            $field.find('.ace-remove').show();
        });

        frame.open();
    });

    $(document).on('click', '.ace-remove', function (e) {
        e.preventDefault();
        var $field = $(this).closest('.ace-field');
        $field.find('.ace-id').val('');
        $field.find('.ace-preview').empty();
        $(this).hide();
    });

    // カテゴリー新規追加後はフォームがリセットされるので、プレビューも消す
    $(document).ajaxComplete(function (event, xhr, settings) {
        if (!settings || !settings.data || settings.data.indexOf('action=add-tag') === -1) return;
        $('#addtag .ace-field').each(function () {
            $(this).find('.ace-id').val('');
            $(this).find('.ace-preview').empty();
            $(this).find('.ace-remove').hide();
        });
    });

    /* ---------- 一括適用ツール（設定画面のみ） ---------- */

    if (!$('#ace-scan').length) return;

    var BATCH = 50;
    var pendingIds = [];

    var $status = $('#ace-status');

    function post(action, extra) {
        return $.post(L.ajaxUrl, $.extend({ action: action, nonce: L.nonce }, extra || {}));
    }

    function busy(on) {
        $('#ace-scan, #ace-apply, #ace-revert').prop('disabled', !!on);
        if (!on) $('#ace-apply').prop('disabled', pendingIds.length === 0);
    }

    $('#ace-scan').on('click', function () {
        busy(true);
        $status.text('スキャン中...');
        post('affiros_cat_eyecatch_scan').done(function (res) {
            if (!res || !res.success) {
                $status.text('');
                alert('スキャン失敗: ' + ((res && res.data) || 'unknown'));
                return;
            }
            var d = res.data;
            pendingIds = d.ids || [];
            $('#ace-n-total').text(d.total + ' 件');
            $('#ace-n-missing').text(d.missing + ' 件');
            $('#ace-n-resolvable').text(d.resolvable + ' 件');
            $('#ace-n-unresolvable').text(d.unresolvable + ' 件');
            $('#ace-n-applied').text(d.applied + ' 件');
            renderEmptyTerms(d.empty_terms || []);
            $('#ace-scan-result').show();
            $status.text('スキャン完了');
        }).fail(function (xhr) {
            $status.text('');
            alert('通信エラー: ' + (xhr.responseText || xhr.statusText));
        }).always(function () {
            busy(false);
        });
    });

    function renderEmptyTerms(terms) {
        var $tbody = $('#ace-terms-table tbody').empty();
        if (!terms.length) {
            $('#ace-terms-table').hide();
            $('#ace-terms-empty-note').text('すべてのカテゴリーに画像が設定されています。');
            return;
        }
        $('#ace-terms-empty-note').text(terms.length + ' 件のカテゴリーに画像がありません（この記事は埋まりません）。');
        terms.forEach(function (t) {
            var $name = t.link
                ? $('<a>').attr('href', t.link).attr('target', '_blank').text(t.name)
                : $('<span>').text(t.name);
            $tbody.append(
                $('<tr>').append($('<td>').append($name), $('<td>').text(t.count + ' 件'))
            );
        });
        $('#ace-terms-table').show();
    }

    $('#ace-apply').on('click', function () {
        if (!pendingIds.length) { alert('先にスキャンしてください'); return; }
        if (!confirm(pendingIds.length + ' 件の記事に、カテゴリー画像を実アイキャッチとして書き込みます。よろしいですか？')) return;
        runBatches('affiros_cat_eyecatch_apply', pendingIds.slice(), '書き込み', function (done, skipped) {
            $status.text('完了: 書き込み ' + done + ' 件 / スキップ ' + skipped + ' 件');
            pendingIds = [];
            $('#ace-scan').trigger('click');
        });
    });

    $('#ace-revert').on('click', function () {
        busy(true);
        $status.text('取り消し対象を確認中...');
        post('affiros_cat_eyecatch_revert_scan').done(function (res) {
            if (!res || !res.success) {
                $status.text('');
                alert('確認失敗: ' + ((res && res.data) || 'unknown'));
                busy(false);
                return;
            }
            var ids = res.data.ids || [];
            if (!ids.length) {
                $status.text('取り消す対象はありません');
                busy(false);
                return;
            }
            if (!confirm(ids.length + ' 件の書き込みを取り消して、アイキャッチ未設定に戻します。よろしいですか？\n（あとから手で別の画像に差し替えた記事はそのまま残します）')) {
                $status.text('');
                busy(false);
                return;
            }
            runBatches('affiros_cat_eyecatch_revert', ids, '取り消し', function (done, kept) {
                $status.text('完了: 取り消し ' + done + ' 件 / 手動変更のため保持 ' + kept + ' 件');
                pendingIds = [];
                $('#ace-scan').trigger('click');
            });
        }).fail(function (xhr) {
            $status.text('');
            alert('通信エラー: ' + (xhr.responseText || xhr.statusText));
            busy(false);
        });
    });

    /**
     * ids を BATCH 件ずつ送る。1リクエストが重くなり過ぎないようにするだけで、
     * 途中で止まっても既に処理した分はDBに残る（再スキャンすれば続きから）。
     */
    function runBatches(action, ids, label, onDone) {
        busy(true);
        var total = ids.length;
        var a = 0, b = 0;

        (function next() {
            if (!ids.length) {
                busy(false);
                onDone(a, b);
                return;
            }
            var chunk = ids.splice(0, BATCH);
            $status.text(label + '中... ' + (total - ids.length) + '/' + total + ' 件');
            post(action, { ids: chunk }).done(function (res) {
                if (res && res.success) {
                    a += (res.data.done || 0);
                    b += (res.data.skipped || res.data.kept || 0);
                    next();
                } else {
                    busy(false);
                    alert(label + '失敗: ' + ((res && res.data) || 'unknown'));
                }
            }).fail(function (xhr) {
                busy(false);
                alert('通信エラー: ' + (xhr.responseText || xhr.statusText));
            });
        })();
    }

})(jQuery);
