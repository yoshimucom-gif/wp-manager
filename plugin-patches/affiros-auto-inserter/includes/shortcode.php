<?php
/**
 * サイドバー用ショートコード
 *
 * [affiros_ai_top]                                — 表示中の記事の1位商品をコンパクト表示
 * [affiros_ai_top rank="2"]                       — 2位を表示
 * [affiros_ai_top title="今日のイチオシ"]         — 見出しを個別指定 (title="" で見出しなし)
 *
 * 見出しの既定値は設定画面「サイドバーカードの見出し」(side_heading)。
 * title 属性を書いた場合のみそちらが優先される。
 *
 * 使い方: 外観 → ウィジェット → サイドバーに「ショートコード」ブロックを置いて
 * [affiros_ai_top] と書くだけ。記事ごとにその記事のキャッシュ済み商品を表示する。
 * 商品データがないページ (固定ページ・アーカイブ・未挿入記事・ランキング記事) では
 * 何も出力しない。
 */

if (!defined('ABSPATH')) exit;

add_shortcode('affiros_ai_top', function ($atts) {
    $atts = shortcode_atts([
        'rank'  => 1,
        'title' => null, // 未指定なら設定画面の side_heading を使う
    ], $atts, 'affiros_ai_top');

    // 個別記事ページ以外では絶対に出さない。
    // (get_the_ID() のフォールバックはトップ/アーカイブで「一覧最後の記事」の
    //  商品が漏れて表示されるバグになったため撤去。v0.7.2)
    if (!is_singular('post')) return '';
    $post_id = get_queried_object_id();
    if (!$post_id) return '';

    $data = get_post_meta($post_id, AFFIROS_AI_META_PRODUCTS, true);
    if (!is_array($data)) $data = json_decode((string)$data, true);
    if (empty($data)) return '';

    // in-content カードと同じ優先順: Amazon 主軸、なければ楽天
    $list = !empty($data['amazon']) ? $data['amazon'] : ($data['rakuten'] ?? []);
    $idx = max(1, intval($atts['rank'])) - 1;
    if (empty($list[$idx])) return '';

    $settings = affiros_ai_get_settings();
    $title = $atts['title'] !== null ? $atts['title'] : ($settings['side_heading'] ?? 'この記事のイチオシ');
    return Affiros_AI_Card_Renderer::render_single($list[$idx], [
        'keyword' => $data['keyword'] ?? get_post_meta($post_id, AFFIROS_AI_META_KEYWORD, true),
        'title'   => $title,
        'amazon_partner_tag'   => $settings['amazon_partner_tag']   ?? '',
        'rakuten_affiliate_id' => $settings['rakuten_affiliate_id'] ?? '',
    ]);
});
