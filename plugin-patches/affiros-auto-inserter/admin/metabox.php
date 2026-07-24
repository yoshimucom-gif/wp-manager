<?php
/**
 * 投稿編集画面のメタボックス
 *
 * - 除外チェックボックス (この記事は自動挿入対象外)
 * - 現在の状態表示 (キーワード / 最終挿入 / エラー)
 * - 手動挿入ボタン (今すぐ実行)
 * - カード削除ボタン (この記事のカードを剥がす)
 */

if (!defined('ABSPATH')) exit;

add_action('add_meta_boxes', function () {
    add_meta_box(
        'affiros-ai-metabox',
        '🛒 Affiros オートインサーター',
        'affiros_ai_render_metabox',
        'post',
        'side',
        'default'
    );
});

function affiros_ai_render_metabox($post) {
    $excluded    = get_post_meta($post->ID, AFFIROS_AI_META_EXCLUDED, true);
    $keyword     = get_post_meta($post->ID, AFFIROS_AI_META_KEYWORD, true);
    $last_insert = get_post_meta($post->ID, AFFIROS_AI_META_LAST_INSERT_AT, true);
    $last_error  = get_post_meta($post->ID, AFFIROS_AI_META_LAST_ERROR, true);
    $is_ranking  = Affiros_AI_Ranking_Detector::is_ranking($post);
    ?>
    <div style="font-size:12px;line-height:1.7">
        <?php if ($excluded === 'yes'): ?>
            <div style="background:#fff3e0;color:#8a5800;padding:6px 8px;border-radius:3px;margin-bottom:8px;">除外設定中 (自動挿入されません)</div>
        <?php elseif ($is_ranking): ?>
            <div style="background:#e7f3ff;color:#0057a3;padding:6px 8px;border-radius:3px;margin-bottom:8px;">ランキング記事と判定 → スキップ対象</div>
        <?php endif; ?>

        <div>キーワード: <strong><?php echo esc_html($keyword ?: '(未抽出)'); ?></strong></div>
        <div>最終挿入: <strong><?php echo esc_html($last_insert ?: '(未挿入)'); ?></strong></div>
        <?php if ($last_error): ?>
            <div style="color:#c62828;margin-top:4px;">⚠️ 前回エラー: <?php echo esc_html($last_error); ?></div>
        <?php endif; ?>
    </div>

    <hr style="margin:10px 0">

    <label style="display:block;margin-bottom:8px;">
        <input type="checkbox" id="ai-mb-excluded" <?php checked($excluded, 'yes'); ?>>
        この記事は自動挿入対象外にする
    </label>

    <button type="button" class="button button-primary" id="ai-mb-apply" data-id="<?php echo intval($post->ID); ?>" style="width:100%;margin-bottom:6px;">✨ この記事に今すぐ挿入</button>
    <button type="button" class="button" id="ai-mb-strip" data-id="<?php echo intval($post->ID); ?>" style="width:100%;">🗑 挿入済みカードを削除</button>

    <div id="ai-mb-status" style="margin-top:8px;font-size:12px;"></div>

    <script>
    jQuery(function ($) {
        const ajaxUrl = (window.AffirosAI && AffirosAI.ajaxUrl) || ajaxurl;
        const nonce   = (window.AffirosAI && AffirosAI.nonce) || '';
        const postId  = <?php echo intval($post->ID); ?>;

        $('#ai-mb-excluded').on('change', async function () {
            const val = $(this).is(':checked') ? 'yes' : 'no';
            try {
                const res = await $.post(ajaxUrl + '?action=affiros_ai_toggle_exclude', {
                    action: 'affiros_ai_toggle_exclude',
                    nonce: nonce,
                    post_id: postId,
                    excluded: val,
                });
                if (res && res.success) {
                    $('#ai-mb-status').html('<span style="color:#0a7a2f">✓ 更新しました</span>');
                }
            } catch (e) {
                $('#ai-mb-status').html('<span style="color:#c62828">通信エラー</span>');
            }
        });

        $('#ai-mb-apply').on('click', async function () {
            if (!confirm('この記事に商品カードを挿入します。実行しますか？')) return;
            $(this).prop('disabled', true).text('実行中...');
            $('#ai-mb-status').text('');
            try {
                const res = await $.post(ajaxUrl + '?action=affiros_ai_apply', {
                    action: 'affiros_ai_apply',
                    nonce: nonce,
                    post_id: postId,
                });
                if (res && res.success) {
                    $('#ai-mb-status').html('<span style="color:#0a7a2f;font-weight:600">✓ ' + escapeHtml(res.data.message || '完了') + '</span><br><small>ページを再読み込みして確認してください</small>');
                } else {
                    $('#ai-mb-status').html('<span style="color:#c62828">✗ ' + escapeHtml(res.data || 'failed') + '</span>');
                }
            } catch (e) {
                $('#ai-mb-status').html('<span style="color:#c62828">通信エラー: ' + (e && e.status) + '</span>');
            } finally {
                $('#ai-mb-apply').prop('disabled', false).text('✨ この記事に今すぐ挿入');
            }
        });

        $('#ai-mb-strip').on('click', async function () {
            if (!confirm('この記事から挿入済みのカードを削除します。実行しますか？')) return;
            $(this).prop('disabled', true).text('削除中...');
            try {
                const res = await $.post(ajaxUrl + '?action=affiros_ai_strip', {
                    action: 'affiros_ai_strip',
                    nonce: nonce,
                    post_id: postId,
                });
                if (res && res.success) {
                    $('#ai-mb-status').html('<span style="color:#0a7a2f">✓ 削除しました。ページを再読み込みしてください</span>');
                } else {
                    $('#ai-mb-status').html('<span style="color:#c62828">✗ ' + escapeHtml(res.data || 'failed') + '</span>');
                }
            } catch (e) {
                $('#ai-mb-status').html('<span style="color:#c62828">通信エラー</span>');
            } finally {
                $('#ai-mb-strip').prop('disabled', false).text('🗑 挿入済みカードを削除');
            }
        });

        function escapeHtml(s) {
            return String(s == null ? '' : s).replace(/[<>&"]/g, c => ({'<':'&lt;','>':'&gt;','&':'&amp;','"':'&quot;'}[c]));
        }
    });
    </script>
    <?php
}

// =============================================================================
// AJAX (metabox 用)
// =============================================================================

add_action('wp_ajax_affiros_ai_toggle_exclude', function () {
    check_ajax_referer('affiros_ai_nonce', 'nonce');
    if (!current_user_can('manage_options')) wp_send_json_error('権限なし');
    $post_id = intval($_POST['post_id'] ?? 0);
    if (!$post_id) wp_send_json_error('post_id 不正');
    $excluded = ($_POST['excluded'] ?? 'no') === 'yes' ? 'yes' : 'no';
    update_post_meta($post_id, AFFIROS_AI_META_EXCLUDED, $excluded);
    wp_send_json_success(['excluded' => $excluded]);
});

add_action('wp_ajax_affiros_ai_strip', function () {
    check_ajax_referer('affiros_ai_nonce', 'nonce');
    if (!current_user_can('manage_options')) wp_send_json_error('権限なし');
    $post_id = intval($_POST['post_id'] ?? 0);
    if (!$post_id) wp_send_json_error('post_id 不正');
    $post = get_post($post_id);
    if (!$post) wp_send_json_error('記事なし');

    $new = Affiros_AI_Inserter::strip_existing_cards($post->post_content);
    if ($new === $post->post_content) {
        wp_send_json_success(['changed' => false, 'message' => '削除対象なし']);
    }
    $upd = wp_update_post(['ID' => $post_id, 'post_content' => $new], true);
    if (is_wp_error($upd)) wp_send_json_error($upd->get_error_message());
    delete_post_meta($post_id, AFFIROS_AI_META_LAST_INSERT_AT);
    wp_send_json_success(['changed' => true]);
});
