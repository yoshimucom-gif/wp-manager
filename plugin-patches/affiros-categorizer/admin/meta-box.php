<?php
/**
 * 投稿編集画面のメタボックス（手動でカテゴリーを再判定）
 */

if (!defined('ABSPATH')) {
    exit;
}

add_action('add_meta_boxes', function () {
    add_meta_box(
        'affiros_cat_box',
        'Affiros カテゴライザー',
        'affiros_cat_render_meta_box',
        'post',
        'side',
        'default'
    );
});

function affiros_cat_render_meta_box($post) {
    $settings = affiros_cat_get_settings();
    $configured = !empty($settings['claude_api_key']);
    $log = get_post_meta($post->ID, '_affiros_cat_log', true);

    echo '<div class="affiros-cat-box">';

    if (!$configured) {
        echo '<p style="color:#c00;">⚠️ Claude API キーが未設定です。<br>'
            . '<a href="' . esc_url(admin_url('admin.php?page=affiros-categorizer-settings')) . '">設定画面へ</a></p>';
    } else {
        echo '<p style="margin-top:0;font-size:12px;color:#666;">本文をもとに、このサイトのカテゴリーから最適なものを AI が選びます。</p>';
        echo '<button type="button" class="button button-primary affiros-cat-run" data-post-id="' . (int) $post->ID . '" style="width:100%;">🤖 AI でカテゴリーを判定</button>';
        echo '<div class="affiros-cat-status" style="margin-top:8px;font-size:12px;"></div>';
    }

    if ($log) {
        echo '<p style="margin-top:10px;font-size:11px;color:#666;border-top:1px solid #eee;padding-top:8px;">'
            . '<strong>前回の判定</strong><br>' . nl2br(esc_html($log)) . '</p>';
    }

    echo '</div>';
}
