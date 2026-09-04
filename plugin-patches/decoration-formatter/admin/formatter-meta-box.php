<?php
/**
 * 投稿編集画面のメタボックス（段落整形）
 */

if (!defined('ABSPATH')) exit;

add_action('add_meta_boxes', 'decofmt_fmt_add_meta_box');
function decofmt_fmt_add_meta_box() {
    add_meta_box(
        'decofmt-fmt-metabox',
        '📝 段落整形',
        'decofmt_fmt_render_meta_box',
        // v1.0.27: 固定ページでも整形できるように（AI装飾側は元から post/page 両対応だった）
        ['post', 'page'],
        'side',
        'default'
    );
}

function decofmt_fmt_render_meta_box($post) {
    $stats = decofmt_fmt_stats($post->post_content);
    $hc = $stats['heading_candidates'] ?? 0;
    $slc = $stats['strong_label_candidates'] ?? 0;
    $msc = $stats['multi_sentence_short'] ?? 0;
    $fmt_settings = decofmt_fmt_get_settings();
    $one_sentence = (($fmt_settings['one_sentence_per_paragraph'] ?? 'no') === 'yes');
    ?>
    <div style="font-size:11px;color:#555;background:#f6f7f7;padding:5px 7px;border-radius:3px;margin-bottom:8px">
        モード: <strong><?php echo $one_sentence ? '⚡1文ごとに改行' : '通常'; ?></strong>
    </div>
    <div style="font-size:12px;line-height:1.7">
        <div>段落数: <strong><?php echo intval($stats['count']); ?></strong></div>
        <div>最大字数: <strong><?php echo intval($stats['max']); ?>字</strong></div>
        <div>200字超: <strong style="color:<?php echo $stats['over_200'] > 0 ? '#dc2626' : '#16a34a'; ?>"><?php echo intval($stats['over_200']); ?>件</strong></div>
        <div>見出し昇格候補: <strong style="color:<?php echo $hc > 0 ? '#d97706' : '#16a34a'; ?>"><?php echo intval($hc); ?>件</strong></div>
        <div>strongラベル: <strong style="color:<?php echo $slc > 0 ? '#2563eb' : '#16a34a'; ?>" title="&lt;li&gt;&lt;strong&gt;ラベル&lt;/strong&gt;：長文 パターン"><?php echo intval($slc); ?>件</strong></div>
        <div><?php echo $one_sentence ? '2句以上の段落' : '3句以上短段落'; ?>: <strong style="color:<?php echo $msc > 0 ? '#7c3aed' : '#16a34a'; ?>"><?php echo intval($msc); ?>件</strong></div>
    </div>
    <hr style="margin:10px 0">
    <button type="button" class="button button-primary" id="decofmt-fmt-mb-apply" data-id="<?php echo intval($post->ID); ?>" style="width:100%">✨ この記事を整形</button>
    <div id="decofmt-fmt-mb-status" style="margin-top:8px;font-size:12px"></div>
    <script>
    jQuery(function ($) {
        $('#decofmt-fmt-mb-apply').on('click', async function () {
            const btn = $(this);
            const id = btn.data('id');
            if (!confirm('この記事を段落整形します。リビジョンが自動保存されます。実行しますか？')) return;
            btn.prop('disabled', true).text('適用中...');
            try {
                const res = await $.post(
                    (window.decofmt && decofmt.ajaxUrl) || ajaxurl,
                    {
                        action: 'decofmt_fmt_apply',
                        nonce: (window.decofmt && decofmt.nonce) || '',
                        post_id: id,
                    }
                );
                if (res === '-1' || res === -1) {
                    $('#decofmt-fmt-mb-status').html('<span style="color:#dc2626">nonce認証エラー(-1)：ページを再読み込みしてください</span>');
                } else if (res && res.success) {
                    $('#decofmt-fmt-mb-status').html('<span style="color:#16a34a;font-weight:600">✓ 整形しました。ページを再読み込みして確認してください</span>');
                } else {
                    $('#decofmt-fmt-mb-status').html('<span style="color:#dc2626">失敗: ' + (res && res.data ? res.data : '') + '</span>');
                }
            } catch (e) {
                $('#decofmt-fmt-mb-status').html('<span style="color:#dc2626">通信エラー</span>');
            } finally {
                btn.prop('disabled', false).text('✨ この記事を整形');
            }
        });
    });
    </script>
    <?php
}
