<?php
/**
 * 一括分類画面
 *
 * 公開済みの記事を一覧から選び、まとめてカテゴリーを判定する。
 * 「未分類のみ」で絞り込めば、カテゴリー漏れの記事をまとめて修正できる。
 * 画面の JS は assets/admin.js（一括分類部分）。
 */

if (!defined('ABSPATH')) {
    exit;
}

function affiros_cat_render_classify_page() {
    if (!current_user_can('manage_options')) {
        return;
    }
    $settings = affiros_cat_get_settings();
    $configured = !empty($settings['claude_api_key']);
    $terms = Affiros_Cat_Classifier::get_target_terms();
    ?>
    <div class="wrap affiros-cat-wrap">
        <h1>Affiros カテゴライザー — 一括分類</h1>

        <?php if (!$configured): ?>
            <div class="notice notice-warning">
                <p>Claude API キーが未設定です。
                <a href="<?php echo esc_url(admin_url('admin.php?page=affiros-categorizer-settings')); ?>">設定画面</a>で入力してください。</p>
            </div>
        <?php endif; ?>

        <?php if (empty($terms)): ?>
            <div class="notice notice-warning">
                <p>判定対象のカテゴリーがありません。先に WordPress でカテゴリーを作成してください。</p>
            </div>
        <?php endif; ?>

        <p class="description">
            公開済みの記事を選択して、AI でカテゴリーを一括判定します。
            「未分類のみ」に絞り込めば、カテゴリー漏れの記事をまとめて修正できます。
        </p>

        <div class="affiros-cat-toolbar">
            <select id="affiros-cat-filter-cat">
                <option value="">すべてのカテゴリー</option>
                <option value="uncategorized">未分類のみ</option>
                <?php foreach ($terms as $t): ?>
                    <option value="<?php echo (int) $t->term_id; ?>"><?php echo esc_html($t->name); ?></option>
                <?php endforeach; ?>
            </select>
            <input type="search" id="affiros-cat-search" placeholder="タイトルで検索">
            <button type="button" class="button" id="affiros-cat-fetch">投稿を取得</button>
        </div>

        <div id="affiros-cat-bulkbar" style="display:none;">
            <label><input type="checkbox" id="affiros-cat-checkall"> 全選択</label>
            <button type="button" class="button button-primary" id="affiros-cat-run-bulk" <?php disabled(!$configured); ?>>選択した記事を分類</button>
            <span id="affiros-cat-progress"></span>
        </div>

        <table class="widefat striped" id="affiros-cat-table" style="display:none;margin-top:12px;">
            <thead>
                <tr>
                    <th style="width:28px;"></th>
                    <th>タイトル</th>
                    <th style="width:180px;">現在のカテゴリー</th>
                    <th style="width:220px;">判定結果</th>
                </tr>
            </thead>
            <tbody></tbody>
        </table>

        <p id="affiros-cat-empty" style="display:none;">該当する投稿がありません。</p>
        <div id="affiros-cat-pagination" style="margin-top:10px;"></div>
    </div>
    <?php
}
