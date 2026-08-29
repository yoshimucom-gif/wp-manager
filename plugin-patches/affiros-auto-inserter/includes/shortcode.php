<?php
/**
 * サイドバー用ショートコード
 *
 * [affiros_ai_top]                                — 表示中の記事の1位商品をコンパクト表示
 * [affiros_ai_top rank="2"]                       — 2位を表示
 * [affiros_ai_top title="で迷ったらこれ"]         — 接尾辞を個別指定 (title="" で見出しなし)
 *
 * 見出しは「{AIキーワード}」+接尾辞 の形式 (v0.16.0)。
 * 接尾辞の既定値は設定画面「サイドバー見出しの接尾辞」(side_heading_suffix)。
 * title 属性を書いた場合のみそちらが優先される。
 *
 * 使い方: 外観 → ウィジェット → サイドバーに「ショートコード」ブロックを置いて
 * [affiros_ai_top] と書くだけ。記事ごとにその記事のキャッシュ済み商品を表示する。
 * 商品データがないページ (固定ページ・アーカイブ・未挿入記事・ランキング記事) では
 * 何も出力しない。
 *
 * ── ショートコード非対応領域のフォールバック (v0.9.1) ──
 * テーマのポップアップ等、do_shortcode() を通らない場所に書かれた
 * [affiros_ai_top...] は文字のまま出力される。フロントJS
 * (assets/shortcode-fallback.js) がそれを検出し、AJAXで取得した
 * カードHTMLにその場で置換する。テーマに手を入れずに動く。
 */

if (!defined('ABSPATH')) exit;

/**
 * カードHTML生成の共通部 (ショートコード / AJAXフォールバック 両方から使う)
 * @param int $post_id
 * @param int $rank 1〜5
 * @param string|null $title 見出しの接尾辞。null なら設定の side_heading_suffix。'' なら見出しなし
 */
function affiros_ai_top_html($post_id, $rank = 1, $title = null) {
    $data = get_post_meta($post_id, AFFIROS_AI_META_PRODUCTS, true);
    if (!is_array($data)) $data = json_decode((string)$data, true);
    if (empty($data)) return '';

    // in-content カードと同じ優先順: Amazon 主軸、なければ楽天
    $list = !empty($data['amazon']) ? $data['amazon'] : ($data['rakuten'] ?? []);
    $idx = max(1, intval($rank)) - 1;
    if (empty($list[$idx])) return '';

    $settings = affiros_ai_get_settings();
    if ($title === null) $title = $settings['side_heading_suffix'] ?? 'で迷ったらこれ';
    $html = Affiros_AI_Card_Renderer::render_single($list[$idx], [
        'keyword' => $data['keyword'] ?? get_post_meta($post_id, AFFIROS_AI_META_KEYWORD, true),
        'title'   => $title,
        'amazon_partner_tag'   => $settings['amazon_partner_tag']   ?? '',
        'rakuten_affiliate_id' => $settings['rakuten_affiliate_id'] ?? '',
    ]);
    // 開催中セールのマイクロコピー (v0.17.0)。サイドバー/ポップアップも記事内と同じ表示
    return affiros_ai_sale_decorate($html);
}

add_shortcode('affiros_ai_top', function ($atts) {
    $atts = shortcode_atts([
        'rank'  => 1,
        'title' => null, // 未指定なら設定画面の side_heading_suffix を使う
    ], $atts, 'affiros_ai_top');

    // 個別記事ページ以外では絶対に出さない。
    // (get_the_ID() のフォールバックはトップ/アーカイブで「一覧最後の記事」の
    //  商品が漏れて表示されるバグになったため撤去。v0.7.2)
    if (!is_singular('post')) return '';
    $post_id = get_queried_object_id();
    if (!$post_id) return '';

    return affiros_ai_top_html($post_id, intval($atts['rank']), $atts['title']);
});

// ── ショートコード非対応領域向け AJAX (未ログイン閲覧者も叩くので公開記事限定) ──

function affiros_ai_ajax_render_top() {
    $post_id = intval($_POST['post_id'] ?? 0);
    // 未ログイン閲覧者には公開記事のみ。編集権限者には予約/下書きプレビューでも出す
    // (v0.9.2以前は publish 限定で、管理者が予約記事を見るとポップアップだけ空になった)
    $status_ok = get_post_status($post_id) === 'publish' || current_user_can('edit_post', $post_id);
    if (!$post_id || get_post_type($post_id) !== 'post' || !$status_ok) {
        wp_send_json_success(''); // 出せない場合は空 (エラーにしない)
    }
    $rank = max(1, min(5, intval($_POST['rank'] ?? 1)));
    $title = (($_POST['has_title'] ?? '') === '1')
        ? sanitize_text_field(wp_unslash($_POST['title'] ?? ''))
        : null;
    wp_send_json_success(affiros_ai_top_html($post_id, $rank, $title));
}
add_action('wp_ajax_affiros_ai_render_top', 'affiros_ai_ajax_render_top');
add_action('wp_ajax_nopriv_affiros_ai_render_top', 'affiros_ai_ajax_render_top');

// ── フォールバックJSの読み込み (公開済み記事ページのみ) ──

add_action('wp_enqueue_scripts', function () {
    if (!is_singular('post')) return;
    wp_enqueue_script(
        'affiros-ai-top-fallback',
        AFFIROS_AI_URL . 'assets/shortcode-fallback.js',
        [],
        AFFIROS_AI_VERSION,
        true
    );
    wp_localize_script('affiros-ai-top-fallback', 'AffirosAITop', [
        'ajaxUrl' => admin_url('admin-ajax.php'),
        'postId'  => get_queried_object_id(),
    ]);
});
