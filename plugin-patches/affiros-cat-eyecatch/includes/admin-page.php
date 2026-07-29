<?php
/**
 * 設定画面（設定 → カテゴリーアイキャッチ）。
 * 基本設定と、一括適用ツールのUIをこの1ページに置く。
 */

if (!defined('ABSPATH')) exit;

add_action('admin_menu', function () {
    add_options_page(
        'カテゴリーアイキャッチ',
        'カテゴリーアイキャッチ',
        'manage_options',
        'affiros-cat-eyecatch',
        'affiros_cat_eyecatch_render_settings_page'
    );
});

add_action('admin_post_affiros_cat_eyecatch_save', function () {
    if (!current_user_can('manage_options')) wp_die('権限がありません');
    check_admin_referer('affiros_cat_eyecatch_settings');

    affiros_cat_eyecatch_save_settings([
        'enabled'          => isset($_POST['enabled']) ? 1 : 0,
        'inherit_parent'   => isset($_POST['inherit_parent']) ? 1 : 0,
        'post_types'       => isset($_POST['post_types']) ? (array)$_POST['post_types'] : [],
        'taxonomies'       => isset($_POST['taxonomies']) ? (array)$_POST['taxonomies'] : [],
        'default_image_id' => isset($_POST['default_image_id']) ? (int)$_POST['default_image_id'] : 0,
    ]);

    wp_safe_redirect(add_query_arg(
        ['page' => 'affiros-cat-eyecatch', 'updated' => '1'],
        admin_url('options-general.php')
    ));
    exit;
});

function affiros_cat_eyecatch_render_settings_page() {
    if (!current_user_can('manage_options')) return;
    $s = affiros_cat_eyecatch_settings();
    ?>
    <div class="wrap">
        <h1>🖼 カテゴリーアイキャッチ</h1>

        <?php if (!empty($_GET['updated'])) : ?>
            <div class="notice notice-success is-dismissible"><p>設定を保存しました。</p></div>
        <?php endif; ?>

        <p style="font-size:13px;line-height:1.8;max-width:820px">
            カテゴリー編集画面で設定した画像を、<strong>アイキャッチ未設定の記事</strong>に自動で使います。<br>
            既定は<strong>仮想適用</strong>（記事のデータベースには書き込まない）なので、あとから記事にアイキャッチを設定すればそちらが優先され、
            プラグインを止めれば元の状態に戻ります。
        </p>

        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <input type="hidden" name="action" value="affiros_cat_eyecatch_save">
            <?php wp_nonce_field('affiros_cat_eyecatch_settings'); ?>

            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row">自動適用</th>
                    <td>
                        <label>
                            <input type="checkbox" name="enabled" value="1" <?php checked($s['enabled'], 1); ?>>
                            アイキャッチ未設定の記事にカテゴリー画像を使う
                        </label>
                        <p class="description">オフにすると、カテゴリーに画像を設定してもフロントには反映されません（設定値は保持されます）。</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">対象の投稿タイプ</th>
                    <td>
                        <?php foreach (affiros_cat_eyecatch_selectable_post_types() as $name => $label) : ?>
                            <label style="display:inline-block;margin:0 16px 6px 0">
                                <input type="checkbox" name="post_types[]" value="<?php echo esc_attr($name); ?>"
                                    <?php checked(in_array($name, $s['post_types'], true)); ?>>
                                <?php echo esc_html($label); ?> <code><?php echo esc_html($name); ?></code>
                            </label>
                        <?php endforeach; ?>
                    </td>
                </tr>
                <tr>
                    <th scope="row">画像を設定できる分類</th>
                    <td>
                        <?php foreach (affiros_cat_eyecatch_selectable_taxonomies() as $name => $label) : ?>
                            <label style="display:inline-block;margin:0 16px 6px 0">
                                <input type="checkbox" name="taxonomies[]" value="<?php echo esc_attr($name); ?>"
                                    <?php checked(in_array($name, $s['taxonomies'], true)); ?>>
                                <?php echo esc_html($label); ?> <code><?php echo esc_html($name); ?></code>
                            </label>
                        <?php endforeach; ?>
                        <p class="description">チェックした分類の編集画面に画像フィールドが出ます。複数チェックした場合は上から順に探します。</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">親からの継承</th>
                    <td>
                        <label>
                            <input type="checkbox" name="inherit_parent" value="1" <?php checked($s['inherit_parent'], 1); ?>>
                            子カテゴリーに画像がなければ親カテゴリーの画像を使う
                        </label>
                    </td>
                </tr>
                <tr>
                    <th scope="row">全体のデフォルト画像</th>
                    <td>
                        <?php affiros_cat_eyecatch_render_picker('default_image_id', $s['default_image_id'], false); ?>
                        <p class="description">どのカテゴリーにも画像がない場合の最後の受け皿。空でも構いません。</p>
                    </td>
                </tr>
            </table>

            <?php submit_button('設定を保存'); ?>
        </form>

        <hr style="margin:32px 0">

        <h2>🔧 実アイキャッチとして一括適用</h2>
        <p style="font-size:13px;line-height:1.8;max-width:820px">
            仮想適用はフロント表示・OGP に十分効きますが、<strong>外部サービスが REST API 経由で記事を読む場合</strong>
            （headless、一部のSNS連携・キャッシュ系プラグイン）には乗りません。
            確実に持たせたい場合だけ、ここで<strong>実アイキャッチとしてDBに書き込み</strong>できます。
        </p>

        <div style="background:#fffbeb;border:1px solid #fbbf24;padding:12px;margin:16px 0;border-radius:4px;max-width:820px">
            <strong>⚠️ 実行前に</strong>
            <ul style="margin:6px 0 0 20px;line-height:1.8;font-size:13px">
                <li>対象は<strong>アイキャッチが未設定の記事だけ</strong>。既存のアイキャッチは絶対に上書きしません。</li>
                <li>書き込んだ記事には目印を付けるので、<strong>「一括取り消し」で元の未設定状態に戻せます</strong>（本プラグインが付けた分だけ）。</li>
                <li>まず「スキャン」で件数を確認してから実行してください。</li>
            </ul>
        </div>

        <p>
            <button type="button" class="button" id="ace-scan">🔍 スキャン</button>
            <button type="button" class="button button-primary" id="ace-apply" disabled>✍ 一括適用</button>
            <button type="button" class="button" id="ace-revert">↩ 一括取り消し</button>
            <span id="ace-status" style="margin-left:12px;font-size:13px;color:#555"></span>
        </p>

        <div id="ace-scan-result" style="display:none;margin-top:12px">
            <table class="widefat striped" style="max-width:560px">
                <tbody>
                    <tr><td>対象記事（設定した投稿タイプ）</td><td id="ace-n-total" style="text-align:right;font-weight:600"></td></tr>
                    <tr><td>うちアイキャッチ未設定</td><td id="ace-n-missing" style="text-align:right;font-weight:600"></td></tr>
                    <tr><td>カテゴリー画像で埋まる</td><td id="ace-n-resolvable" style="text-align:right;font-weight:600;color:#16a34a"></td></tr>
                    <tr><td>埋まらない（カテゴリー画像なし）</td><td id="ace-n-unresolvable" style="text-align:right;font-weight:600;color:#b91c1c"></td></tr>
                    <tr><td>本プラグインが書き込み済み</td><td id="ace-n-applied" style="text-align:right;font-weight:600"></td></tr>
                </tbody>
            </table>

            <h3 style="margin:20px 0 6px">画像が未設定のカテゴリー</h3>
            <p class="description" id="ace-terms-empty-note" style="margin-top:0"></p>
            <table class="widefat striped" style="max-width:560px" id="ace-terms-table">
                <thead><tr><th>カテゴリー</th><th style="width:90px">記事数</th></tr></thead>
                <tbody></tbody>
            </table>
        </div>
    </div>
    <?php
}
