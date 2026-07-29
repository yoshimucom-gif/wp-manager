<?php
/**
 * カテゴリー（ターム）側のUI。
 *  - 新規追加フォーム / 編集フォームに画像フィールド
 *  - 一覧にサムネイル列
 *  - 保存処理
 */

if (!defined('ABSPATH')) exit;

add_action('init', 'affiros_cat_eyecatch_register_term_hooks', 20);

function affiros_cat_eyecatch_register_term_hooks() {
    foreach (affiros_cat_eyecatch_enabled_taxonomies() as $tax) {
        add_action($tax . '_add_form_fields',  'affiros_cat_eyecatch_add_form_field');
        add_action($tax . '_edit_form_fields', 'affiros_cat_eyecatch_edit_form_field', 10, 2);
        add_action('created_' . $tax, 'affiros_cat_eyecatch_save_term_meta');
        add_action('edited_' . $tax,  'affiros_cat_eyecatch_save_term_meta');

        add_filter('manage_edit-' . $tax . '_columns',  'affiros_cat_eyecatch_term_columns');
        add_filter('manage_' . $tax . '_custom_column', 'affiros_cat_eyecatch_term_column_content', 10, 3);
    }
}

/** 新規追加フォーム（term_id がまだ無い） */
function affiros_cat_eyecatch_add_form_field($taxonomy) {
    ?>
    <div class="form-field">
        <label>カテゴリーアイキャッチ</label>
        <?php affiros_cat_eyecatch_render_picker('affiros_cat_eyecatch_id', 0); ?>
        <p>このカテゴリーの記事で<strong>アイキャッチが未設定のもの</strong>に、この画像が自動で使われます。</p>
    </div>
    <?php
}

/** 編集フォーム */
function affiros_cat_eyecatch_edit_form_field($term, $taxonomy) {
    $image_id = (int)get_term_meta($term->term_id, AFFIROS_CAT_EYECATCH_TERM_META, true);
    ?>
    <tr class="form-field">
        <th scope="row"><label>カテゴリーアイキャッチ</label></th>
        <td>
            <?php affiros_cat_eyecatch_render_picker('affiros_cat_eyecatch_id', $image_id); ?>
            <p class="description">
                このカテゴリーの記事で<strong>アイキャッチが未設定のもの</strong>に、この画像が自動で使われます。<br>
                記事側にアイキャッチが設定されていれば、そちらが常に優先されます。
            </p>
        </td>
    </tr>
    <?php
}

/**
 * 画像ピッカー本体（設定画面のデフォルト画像欄でも使い回す）
 */
function affiros_cat_eyecatch_render_picker($field_name, $image_id, $with_nonce = true) {
    $image_id = (int)$image_id;
    $has = $image_id && affiros_cat_eyecatch_is_valid_image($image_id);
    $thumb = $has ? wp_get_attachment_image($image_id, 'thumbnail', false, ['alt' => '']) : '';
    ?>
    <div class="ace-field">
        <?php if ($with_nonce) wp_nonce_field('affiros_cat_eyecatch_term', 'affiros_cat_eyecatch_term_nonce'); ?>
        <input type="hidden" class="ace-id" name="<?php echo esc_attr($field_name); ?>" value="<?php echo $has ? $image_id : ''; ?>">
        <div class="ace-preview"><?php echo $thumb; ?></div>
        <p class="ace-buttons">
            <button type="button" class="button ace-pick">画像を選択</button>
            <button type="button" class="button ace-remove"<?php echo $has ? '' : ' style="display:none"'; ?>>削除</button>
        </p>
    </div>
    <?php
}

/** 保存 */
function affiros_cat_eyecatch_save_term_meta($term_id) {
    if (!isset($_POST['affiros_cat_eyecatch_term_nonce'])) return;
    if (!wp_verify_nonce($_POST['affiros_cat_eyecatch_term_nonce'], 'affiros_cat_eyecatch_term')) return;
    if (!current_user_can('manage_categories')) return;

    $image_id = isset($_POST['affiros_cat_eyecatch_id']) ? (int)$_POST['affiros_cat_eyecatch_id'] : 0;
    if ($image_id > 0) {
        update_term_meta($term_id, AFFIROS_CAT_EYECATCH_TERM_META, $image_id);
    } else {
        delete_term_meta($term_id, AFFIROS_CAT_EYECATCH_TERM_META);
    }
}

/** 一覧の列見出し（チェックボックスの直後に差し込む） */
function affiros_cat_eyecatch_term_columns($columns) {
    $out = [];
    foreach ($columns as $key => $label) {
        $out[$key] = $label;
        if ($key === 'cb') $out['affiros_cat_eyecatch'] = 'アイキャッチ';
    }
    if (!isset($out['affiros_cat_eyecatch'])) $out['affiros_cat_eyecatch'] = 'アイキャッチ';
    return $out;
}

/** 一覧の列の中身 */
function affiros_cat_eyecatch_term_column_content($content, $column, $term_id) {
    if ($column !== 'affiros_cat_eyecatch') return $content;

    $own = (int)get_term_meta($term_id, AFFIROS_CAT_EYECATCH_TERM_META, true);
    if ($own && affiros_cat_eyecatch_is_valid_image($own)) {
        return wp_get_attachment_image($own, [50, 50], false, ['style' => 'width:50px;height:50px;object-fit:cover;border-radius:3px']);
    }

    // 自分に無い場合、親から継承していればそれを薄く表示する
    $s = affiros_cat_eyecatch_settings();
    if (!empty($s['inherit_parent'])) {
        $inherited = affiros_cat_eyecatch_term_image_id($term_id, true);
        if ($inherited) {
            return wp_get_attachment_image($inherited, [50, 50], false, [
                'style' => 'width:50px;height:50px;object-fit:cover;border-radius:3px;opacity:.45',
                'title' => '親カテゴリーから継承',
            ]) . '<div style="font-size:11px;color:#888">親から継承</div>';
        }
    }

    return '<span style="color:#bbb">—</span>';
}
