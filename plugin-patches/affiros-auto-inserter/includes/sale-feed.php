<?php
/**
 * セールハブ連携 (v0.17.0)
 *
 * ke-ys.co.jp の affiros-sale-hub が配信するセール情報JSONを1日1回取得し、
 * 開催中のセールを該当モールのボタン直上にマイクロコピー
 * 「＼お買い物マラソン開催中／」として表示する。
 *
 * 設計:
 *   - 取得は日次cron (affiros_ai_daily_refresh) と設定保存時のみ。
 *     ページ表示中には絶対に外部HTTPを撃たない (キャッシュ読みだけ)。
 *   - 開催中判定は表示側 = このサイトの現在時刻で行う (二重期間ゲート)。
 *     取得が止まっても終了日時を過ぎれば表示は消える。安全側にしか倒れない。
 *   - 表示は the_content フィルタ等の「表示時注入」。焼き込み済みカードにも
 *     再挿入なしで即反映され、セールが終われば即消える。
 */

if (!defined('ABSPATH')) exit;

define('AFFIROS_AI_SALES_CACHE_KEY', 'affiros_ai_sales_cache');

/**
 * セールハブからJSONを取得してキャッシュする。
 * @return int|false 取り込んだ件数。取得失敗は false (既存キャッシュは温存)
 */
function affiros_ai_sale_fetch() {
    $settings = affiros_ai_get_settings();
    $url = trim((string)($settings['sale_feed_url'] ?? ''));
    if ($url === '') return false;

    $res = wp_remote_get($url, ['timeout' => 10, 'headers' => ['Accept' => 'application/json']]);
    if (is_wp_error($res) || (int)wp_remote_retrieve_response_code($res) !== 200) return false;
    $data = json_decode(wp_remote_retrieve_body($res), true);
    if (!is_array($data) || !isset($data['sales']) || !is_array($data['sales'])) return false;

    $rows = [];
    foreach (array_slice($data['sales'], 0, 20) as $r) {
        if (!is_array($r)) continue;
        $mall = (string)($r['mall'] ?? '');
        if (!in_array($mall, ['amazon', 'rakuten'], true)) continue;
        $label = mb_substr(wp_strip_all_tags(trim((string)($r['label'] ?? ''))), 0, 40);
        $start = strtotime((string)($r['start'] ?? ''));
        $end   = strtotime((string)($r['end'] ?? ''));
        if ($label === '' || !$start || !$end || $end <= $start) continue;
        $anim = (string)($r['anim'] ?? 'blink');
        if (!in_array($anim, ['none', 'blink', 'bounce', 'pulse', 'shake'], true)) $anim = 'blink';
        $rows[] = [
            'mall'  => $mall,
            'label' => $label,
            'start' => date('Y-m-d H:i', $start),
            'end'   => date('Y-m-d H:i', $end),
            'anim'  => $anim,
        ];
    }
    // マイクロコピーの文字色 (ハブ側で一元管理・モール別)
    $colors = [];
    if (isset($data['colors']) && is_array($data['colors'])) {
        foreach (['amazon', 'rakuten'] as $m) {
            $v = (string)($data['colors'][$m] ?? '');
            if (preg_match('/^#[0-9a-fA-F]{6}$/', $v)) $colors[$m] = strtolower($v);
        }
    }
    update_option(AFFIROS_AI_SALES_CACHE_KEY, [
        'fetched' => current_time('mysql'),
        'sales'   => $rows,
        'colors'  => $colors,
    ]);
    return count($rows);
}

/**
 * いま開催中のセール (モール別)。複数重複時は終了が近いものを優先
 * (セールハブ側の管理画面プレビューと同じ規則)。
 */
function affiros_ai_sale_active($mall) {
    $cache = get_option(AFFIROS_AI_SALES_CACHE_KEY, []);
    if (!is_array($cache) || empty($cache['sales']) || !is_array($cache['sales'])) return null;
    $now = strtotime(current_time('mysql'));
    $best = null;
    foreach ($cache['sales'] as $s) {
        if (!is_array($s) || ($s['mall'] ?? '') !== $mall) continue;
        $st = strtotime((string)($s['start'] ?? ''));
        $en = strtotime((string)($s['end'] ?? ''));
        if (!$st || !$en || $now < $st || $now > $en) continue;
        if (!$best || $en < strtotime($best['end'])) $best = $s;
    }
    return $best;
}

/**
 * カードHTML内の Amazon/楽天ボタン直上にマイクロコピーを注入する。
 * 記事内カード・サイドバー・ポップアップ (AJAX) すべてこの1本を通す。
 */
function affiros_ai_sale_decorate($html) {
    if (!is_string($html) || $html === '' || strpos($html, 'affiros-ai-btn') === false) return $html;

    $settings = affiros_ai_get_settings();
    if (($settings['sale_display'] ?? 'yes') !== 'yes') return $html;

    static $active = null; // 同一リクエスト内キャッシュ (カード数ぶん再計算しない)
    if ($active === null) {
        $cache = get_option(AFFIROS_AI_SALES_CACHE_KEY, []);
        $active = [
            'amazon'  => affiros_ai_sale_active('amazon'),
            'rakuten' => affiros_ai_sale_active('rakuten'),
            'colors'  => (is_array($cache) && !empty($cache['colors']) && is_array($cache['colors'])) ? $cache['colors'] : [],
        ];
    }

    foreach (['amazon', 'rakuten'] as $mall) {
        if (!$active[$mall]) continue;
        // ハブ指定の色をインラインで上書き (CSS既定はポチップ準拠の薄め)
        $style = isset($active['colors'][$mall])
            ? ' style="color:' . esc_attr($active['colors'][$mall]) . ' !important"'
            : '';
        $copy = '<div class="affiros-ai-sale affiros-ai-sale-' . $mall
              . ' affiros-sh-anim-' . esc_attr($active[$mall]['anim']) . '"' . $style . '>＼'
              . esc_html($active[$mall]['label']) . '／</div>';
        $html = preg_replace(
            '/(<a[^>]*affiros-ai-btn-' . $mall . '[^>]*>)/u',
            $copy . '$1',
            $html
        );
    }
    return $html;
}

// 記事本文 (焼き込み済みカード含む)。見出し差し替え (priority 20) の後に走らせる
add_filter('the_content', function ($content) {
    if (strpos($content, 'affiros-ai-btn') === false) return $content;
    return affiros_ai_sale_decorate($content);
}, 21);

// 設定保存時に即取得 (URLを入れた直後から使えるように)
add_action('update_option_' . AFFIROS_AI_OPTION_KEY, function () {
    affiros_ai_sale_fetch();
});

/**
 * 即時取得トリガー (v0.17.1)。ハブでセールを登録・変更した直後に
 * 各サイトの日次取得を待たず反映させる運用用:
 *   curl -X POST https://サイト/wp-admin/admin-ajax.php -d action=affiros_ai_sale_refresh
 * 取得元は設定済みfeed URLだけ・返すのは件数だけなので公開しても情報は漏れない。
 * 連打対策に10分スロットル。
 */
add_action('wp_ajax_affiros_ai_sale_refresh',        'affiros_ai_sale_refresh_endpoint');
add_action('wp_ajax_nopriv_affiros_ai_sale_refresh', 'affiros_ai_sale_refresh_endpoint');
function affiros_ai_sale_refresh_endpoint() {
    if (get_transient('affiros_ai_sale_refresh_lock')) {
        wp_send_json(['ok' => false, 'error' => 'throttled']);
    }
    set_transient('affiros_ai_sale_refresh_lock', 1, 10 * MINUTE_IN_SECONDS);
    $n = affiros_ai_sale_fetch();
    wp_send_json(['ok' => $n !== false, 'count' => $n === false ? null : $n]);
}
