<?php
/**
 * 品質プリセット管理画面
 */

if (!defined('ABSPATH')) exit;

/**
 * プリセット保存（追加・更新）
 */
add_action('admin_post_affiros_rewrite_save_preset', function () {
    if (!current_user_can('manage_options')) wp_die('権限がありません');
    check_admin_referer('affiros_rewrite_save_preset');

    $input = $_POST['preset'] ?? [];
    $id = Affiros_Rewrite_Quality_Presets::upsert([
        'id' => sanitize_text_field($input['id'] ?? ''),
        'name' => sanitize_text_field($input['name'] ?? ''),
        'article_type' => sanitize_text_field($input['article_type'] ?? ''),
        'prompt' => wp_unslash($input['prompt'] ?? ''),
        'target_chars' => intval($input['target_chars'] ?? 0),
        'tone' => sanitize_text_field($input['tone'] ?? 'natural'),
        'reference_url' => esc_url_raw($input['reference_url'] ?? ''),
    ]);

    wp_safe_redirect(add_query_arg([
        'page' => 'affiros-rewrite-presets',
        'saved' => '1',
    ], admin_url('admin.php')));
    exit;
});

/**
 * プリセット削除
 */
add_action('admin_post_affiros_rewrite_delete_preset', function () {
    if (!current_user_can('manage_options')) wp_die('権限がありません');
    check_admin_referer('affiros_rewrite_delete_preset');

    $id = sanitize_text_field($_POST['preset_id'] ?? '');
    if ($id) Affiros_Rewrite_Quality_Presets::delete($id);

    wp_safe_redirect(add_query_arg([
        'page' => 'affiros-rewrite-presets',
        'deleted' => '1',
    ], admin_url('admin.php')));
    exit;
});

/**
 * JSONインポート
 */
add_action('admin_post_affiros_rewrite_import_presets', function () {
    if (!current_user_can('manage_options')) wp_die('権限がありません');
    check_admin_referer('affiros_rewrite_import_presets');

    $json = wp_unslash($_POST['import_json'] ?? '');
    $result = Affiros_Rewrite_Quality_Presets::import_json($json);
    if (is_wp_error($result)) {
        wp_safe_redirect(add_query_arg([
            'page' => 'affiros-rewrite-presets',
            'import_error' => urlencode($result->get_error_message()),
        ], admin_url('admin.php')));
        exit;
    }
    wp_safe_redirect(add_query_arg([
        'page' => 'affiros-rewrite-presets',
        'imported' => intval($result['imported']),
    ], admin_url('admin.php')));
    exit;
});

/**
 * 画面描画
 */
function affiros_rewrite_render_presets_page() {
    if (!current_user_can('manage_options')) return;

    $presets = Affiros_Rewrite_Quality_Presets::all();
    $edit_id = isset($_GET['edit']) ? sanitize_text_field($_GET['edit']) : '';
    $editing = $edit_id ? Affiros_Rewrite_Quality_Presets::find($edit_id) : null;
    ?>
    <div class="wrap affiros-wrap">
        <h1>Affiros リライト — 品質プリセット</h1>
        <p class="description">
            リライト実行時に選択できるプリセット集。Affiros の品質定義 JSON をそのままインポートできます。
        </p>

        <?php if (!empty($_GET['saved'])): ?>
            <div class="notice notice-success is-dismissible"><p>プリセットを保存しました。</p></div>
        <?php endif; ?>
        <?php if (!empty($_GET['deleted'])): ?>
            <div class="notice notice-success is-dismissible"><p>プリセットを削除しました。</p></div>
        <?php endif; ?>
        <?php if (!empty($_GET['imported'])): ?>
            <div class="notice notice-success is-dismissible"><p><?php echo intval($_GET['imported']); ?>件のプリセットをインポートしました。</p></div>
        <?php endif; ?>
        <?php if (!empty($_GET['import_error'])): ?>
            <div class="notice notice-error is-dismissible"><p>インポート失敗: <?php echo esc_html(wp_unslash($_GET['import_error'])); ?></p></div>
        <?php endif; ?>

        <div style="display:grid;grid-template-columns:1fr 1fr;gap:24px;margin-top:20px;">

            <!-- 既存一覧 -->
            <div>
                <h2>// 保存済みプリセット</h2>
                <?php if (!$presets): ?>
                    <p class="description">まだプリセットがありません。右のフォームで作成するか、下のインポート機能で一括追加してください。</p>
                <?php else: ?>
                    <table class="wp-list-table widefat striped">
                        <thead>
                            <tr><th>名前</th><th style="width:90px;">記事タイプ</th><th style="width:80px;">文字数</th><th style="width:120px;">操作</th></tr>
                        </thead>
                        <tbody>
                        <?php foreach ($presets as $p):
                            $edit_url = admin_url('admin.php?page=affiros-rewrite-presets&edit=' . urlencode($p['id']));
                        ?>
                            <tr>
                                <td>
                                    <strong><?php echo esc_html($p['name'] ?? ''); ?></strong>
                                    <?php if (!empty($p['reference_url'])): ?>
                                        <div style="font-size:11px;color:#888;"><a href="<?php echo esc_url($p['reference_url']); ?>" target="_blank" rel="noopener">参考URL</a></div>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo esc_html($p['article_type'] ?: '—'); ?></td>
                                <td><?php echo intval($p['target_chars'] ?? 0) ?: '—'; ?></td>
                                <td>
                                    <a href="<?php echo esc_url($edit_url); ?>" class="button button-small">編集</a>
                                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline;" onsubmit="return confirm('削除しますか？');">
                                        <?php wp_nonce_field('affiros_rewrite_delete_preset'); ?>
                                        <input type="hidden" name="action" value="affiros_rewrite_delete_preset">
                                        <input type="hidden" name="preset_id" value="<?php echo esc_attr($p['id']); ?>">
                                        <button type="submit" class="button button-small button-link-delete">削除</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>

                <h2 style="margin-top:30px;">// JSONインポート</h2>
                <p class="description">Affiros の品質定義 JSON をそのまま貼り付けて一括追加できます。同じ id があれば置換、なければ追加。</p>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <?php wp_nonce_field('affiros_rewrite_import_presets'); ?>
                    <input type="hidden" name="action" value="affiros_rewrite_import_presets">
                    <textarea name="import_json" rows="8" style="width:100%;font-family:monospace;font-size:11px;" placeholder='[{"id":"...","name":"...","article_type":"ranking","prompt":"...","target_chars":3000,"tone":"natural","reference_url":""}]'></textarea>
                    <p><button type="submit" class="button">JSONをインポート</button></p>
                </form>
            </div>

            <!-- 編集フォーム -->
            <div>
                <h2>// <?php echo $editing ? '編集' : '新規追加'; ?></h2>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <?php wp_nonce_field('affiros_rewrite_save_preset'); ?>
                    <input type="hidden" name="action" value="affiros_rewrite_save_preset">
                    <input type="hidden" name="preset[id]" value="<?php echo esc_attr($editing['id'] ?? ''); ?>">

                    <table class="form-table">
                        <tr>
                            <th><label>名前</label></th>
                            <td><input type="text" name="preset[name]" value="<?php echo esc_attr($editing['name'] ?? ''); ?>" class="regular-text" required></td>
                        </tr>
                        <tr>
                            <th><label>記事タイプ</label></th>
                            <td>
                                <select name="preset[article_type]">
                                    <option value="" <?php selected(($editing['article_type'] ?? ''), ''); ?>>—（指定なし）</option>
                                    <option value="ranking" <?php selected(($editing['article_type'] ?? ''), 'ranking'); ?>>ランキング記事</option>
                                    <option value="brand" <?php selected(($editing['article_type'] ?? ''), 'brand'); ?>>商標（レビュー）記事</option>
                                    <option value="column" <?php selected(($editing['article_type'] ?? ''), 'column'); ?>>コラム記事</option>
                                </select>
                                <p class="description">マーカー挿入時の挿入規則の決定に使用</p>
                            </td>
                        </tr>
                        <tr>
                            <th><label>文体</label></th>
                            <td>
                                <select name="preset[tone]">
                                    <option value="natural" <?php selected(($editing['tone'] ?? 'natural'), 'natural'); ?>>自然</option>
                                    <option value="professional" <?php selected(($editing['tone'] ?? ''), 'professional'); ?>>専門的</option>
                                    <option value="casual" <?php selected(($editing['tone'] ?? ''), 'casual'); ?>>カジュアル</option>
                                </select>
                            </td>
                        </tr>
                        <tr>
                            <th><label>目標文字数</label></th>
                            <td><input type="number" name="preset[target_chars]" value="<?php echo esc_attr($editing['target_chars'] ?? 0); ?>" min="0" step="100" class="small-text"> 文字（0=元記事に合わせる）</td>
                        </tr>
                        <tr>
                            <th><label>参考URL（任意）</label></th>
                            <td><input type="url" name="preset[reference_url]" value="<?php echo esc_attr($editing['reference_url'] ?? ''); ?>" class="regular-text" placeholder="https://example.com/reference-article"></td>
                        </tr>
                        <tr>
                            <th><label>追加指示（カスタムプロンプト）</label></th>
                            <td>
                                <textarea name="preset[prompt]" rows="10" style="width:100%;font-family:monospace;font-size:12px;"><?php echo esc_textarea($editing['prompt'] ?? ''); ?></textarea>
                                <p class="description">リライトプロンプトに追加で挿入される指示。書き方ルール・口調・避けたい表現などを書く。</p>
                            </td>
                        </tr>
                    </table>

                    <p>
                        <button type="submit" class="button button-primary"><?php echo $editing ? '更新' : '追加'; ?></button>
                        <?php if ($editing): ?>
                            <a href="<?php echo esc_url(admin_url('admin.php?page=affiros-rewrite-presets')); ?>" class="button">キャンセル</a>
                        <?php endif; ?>
                    </p>
                </form>
            </div>
        </div>
    </div>
    <?php
}
