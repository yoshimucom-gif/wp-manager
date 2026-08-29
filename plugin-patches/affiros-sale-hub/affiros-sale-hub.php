<?php
/**
 * Plugin Name: Affiros セールハブ
 * Description: Amazon・楽天のセール情報を一元管理して全メディアサイトへ配信する。ke-ys.co.jp に設置する配信元プラグイン。各サイトの affiros-auto-inserter が1日1回ここのJSONを取得し、開催中のセールをカードボタン上のマイクロコピーとして表示する。
 * Version: 1.2.0
 * Author: Affiros
 * License: GPL v2 or later
 * Text Domain: affiros-sale-hub
 */

if (!defined('ABSPATH')) exit;

define('AFFIROS_SH_VERSION',    '1.2.0');
define('AFFIROS_SH_OPTION_KEY',  'affiros_sale_hub_sales');
define('AFFIROS_SH_TOKEN_KEY',   'affiros_sale_hub_token');
define('AFFIROS_SH_HISTORY_KEY', 'affiros_sale_hub_history');

/**
 * 自動連携用トークン。初回アクセス時にランダム生成して固定。
 * (公開リポジトリで配布するプラグインなので、既定値としては絶対に持たない)
 */
function affiros_sh_get_token() {
    $token = get_option(AFFIROS_SH_TOKEN_KEY, '');
    if (!is_string($token) || strlen($token) < 24) {
        $token = wp_generate_password(40, false, false);
        update_option(AFFIROS_SH_TOKEN_KEY, $token, false);
    }
    return $token;
}

require_once plugin_dir_path(__FILE__) . 'includes/plugin-updater.php';

add_action('init', function () {
    new Affiros_Plugin_Updater(
        __FILE__,
        'https://raw.githubusercontent.com/yoshimucom-gif/wp-manager/main/plugin-host/api/plugin-update/sale-hub'
    );
});

/**
 * マイクロコピーのアニメーションパターン (キーがfeedで配信され、受信側のCSSクラスになる)
 */
function affiros_sh_anims() {
    return [
        'none'   => 'なし',
        'blink'  => '点滅',
        'bounce' => 'バウンド',
        'pulse'  => '拡大縮小',
        'shake'  => 'ぷるぷる',
    ];
}

function affiros_sh_sanitize_anim($v) {
    return array_key_exists((string)$v, affiros_sh_anims()) ? (string)$v : 'blink';
}

/**
 * セール一覧を取得する。終了した行はここで実施履歴へ自動移動する
 * (配信・管理画面どちらの入口でも通るので、移動漏れは起きない)。
 * 返すのは開催中・開始前の行だけ。
 */
function affiros_sh_get_sales() {
    $sales = get_option(AFFIROS_SH_OPTION_KEY, []);
    if (!is_array($sales)) $sales = [];
    $now_ts = strtotime(current_time('mysql'));
    $keep = [];
    $ended = [];
    foreach ($sales as $s) {
        $end = strtotime((string)($s['end'] ?? ''));
        if (!$end) continue; // 壊れた行は捨てる
        if ($end < $now_ts) { $ended[] = $s; } else { $keep[] = $s; }
    }
    if ($ended || count($keep) !== count($sales)) {
        if ($ended) affiros_sh_history_add($ended);
        update_option(AFFIROS_SH_OPTION_KEY, array_values($keep), false);
    }
    return array_values($keep);
}

/**
 * 実施履歴へ追記 (id重複はスキップ・古い順で保持・直近500件)
 */
function affiros_sh_history_add($rows) {
    $h = get_option(AFFIROS_SH_HISTORY_KEY, []);
    if (!is_array($h)) $h = [];
    $ids = [];
    foreach ($h as $r) { $ids[(string)($r['id'] ?? '')] = true; }
    foreach ($rows as $r) {
        $id = (string)($r['id'] ?? '');
        if ($id === '' || isset($ids[$id])) continue;
        $h[] = $r;
        $ids[$id] = true;
    }
    usort($h, function ($a, $b) { return strcmp((string)$a['end'], (string)$b['end']); });
    if (count($h) > 500) $h = array_slice($h, -500);
    update_option(AFFIROS_SH_HISTORY_KEY, $h, false);
}

/**
 * 配信ペイロード。終了前 (開催中 + 開始前) の行だけを返す。
 * 開催中かどうかの最終判定は受信側が自分の現在時刻で行う
 * (配信が1日止まってもセール終了後に表示が残らないための二重ゲート)。
 */
function affiros_sh_feed_payload() {
    $now = current_time('mysql');
    $now_ts = strtotime($now);
    $rows = [];
    foreach (affiros_sh_get_sales() as $s) {
        $end = strtotime((string)($s['end'] ?? ''));
        if (!$end || $end < $now_ts) continue;
        $rows[] = [
            'mall'  => $s['mall'],
            'label' => $s['label'],
            'start' => $s['start'],
            'end'   => $s['end'],
            'anim'  => affiros_sh_sanitize_anim($s['anim'] ?? 'blink'),
        ];
    }
    return [
        'version'  => AFFIROS_SH_VERSION,
        'timezone' => 'Asia/Tokyo',
        'now'      => $now,
        'sales'    => $rows,
    ];
}

// 配信エンドポイント①: admin-ajax (SiteGuard の REST 無効化やWAFの影響を受けない主経路)
add_action('wp_ajax_affiros_sales',        'affiros_sh_ajax_feed');
add_action('wp_ajax_nopriv_affiros_sales', 'affiros_sh_ajax_feed');
function affiros_sh_ajax_feed() {
    wp_send_json(affiros_sh_feed_payload());
}

// 配信エンドポイント②: REST (予備経路。?rest_route=/affiros/v1/sales でも到達可)
add_action('rest_api_init', function () {
    register_rest_route('affiros/v1', '/sales', [
        'methods'             => 'GET',
        'permission_callback' => '__return_true',
        'callback'            => function () {
            return affiros_sh_feed_payload();
        },
    ]);
    // 自動連携セットアップ用: トークン取得 (管理者のアプリケーションパスワード認証が必要)
    register_rest_route('affiros/v1', '/push-token', [
        'methods'             => 'GET',
        'permission_callback' => function () { return current_user_can('manage_options'); },
        'callback'            => function () { return ['token' => affiros_sh_get_token()]; },
    ]);
});

/**
 * 1行分の入力値を検証して保存形式に整える (手動追加・自動プッシュ共通)。
 * 不正なら null。
 */
function affiros_sh_validate_row($mall, $label, $start, $end, $source, $anim = 'blink') {
    if (!in_array($mall, ['amazon', 'rakuten'], true)) return null;
    $label = mb_substr(wp_strip_all_tags(trim((string)$label)), 0, 40);
    $start = affiros_sh_normalize_dt($start);
    $end   = affiros_sh_normalize_dt($end);
    if ($label === '' || !$start || !$end) return null;
    if (strtotime($end) <= strtotime($start)) return null;
    return [
        'id'     => uniqid('sale_'),
        'mall'   => $mall,
        'label'  => $label,
        'start'  => $start,
        'end'    => $end,
        'anim'   => affiros_sh_sanitize_anim($anim),
        'source' => $source,
    ];
}

/**
 * 書き込みエンドポイント: 毎朝の自動巡回エージェントがセール情報をプッシュする。
 *
 * セマンティクス = replace-auto (冪等):
 *   - source=auto の行はペイロードの内容で総入れ替え
 *   - 手動登録した行 (source=manual / 旧バージョンの source なし) には一切触らない
 *   - 手動行と同モール・期間重複の auto 行は捨てる (手動が常に勝つ)
 * 同じペイロードを何度送っても結果は同じ。エージェントの調査が空振りした日は
 * auto 行が消えるだけで、誤表示 (根拠のない「開催中」) 側には倒れない。
 */
add_action('wp_ajax_affiros_sales_push',        'affiros_sh_ajax_push');
add_action('wp_ajax_nopriv_affiros_sales_push', 'affiros_sh_ajax_push');
function affiros_sh_ajax_push() {
    if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? '')) !== 'POST') {
        wp_send_json(['ok' => false, 'error' => 'POST only'], 405);
    }
    $token = (string)($_POST['token'] ?? '');
    if ($token === '' || !hash_equals(affiros_sh_get_token(), $token)) {
        wp_send_json(['ok' => false, 'error' => 'bad token'], 403);
    }
    $data = json_decode(wp_unslash((string)($_POST['payload'] ?? '')), true);
    if (!is_array($data)) {
        wp_send_json(['ok' => false, 'error' => 'payload must be JSON'], 400);
    }
    $rows = (isset($data['sales']) && is_array($data['sales'])) ? $data['sales'] : $data;

    $autos = [];
    foreach (array_slice($rows, 0, 20) as $r) {
        if (!is_array($r)) continue;
        $row = affiros_sh_validate_row(
            $r['mall'] ?? '', $r['label'] ?? '', $r['start'] ?? '', $r['end'] ?? '', 'auto',
            $r['anim'] ?? 'blink'
        );
        if ($row) $autos[] = $row;
    }

    $manual = array_values(array_filter(affiros_sh_get_sales(), function ($s) {
        return ($s['source'] ?? 'manual') !== 'auto';
    }));

    // 手動行と同モール・期間重複の auto 行は捨てる
    $autos = array_values(array_filter($autos, function ($a) use ($manual) {
        foreach ($manual as $m) {
            if ($m['mall'] === $a['mall']
                && strtotime($a['start']) < strtotime($m['end'])
                && strtotime($m['start']) < strtotime($a['end'])) return false;
        }
        return true;
    }));

    $sales = array_merge($manual, $autos);
    usort($sales, function ($a, $b) { return strcmp($a['start'], $b['start']); });
    update_option(AFFIROS_SH_OPTION_KEY, $sales, false);
    wp_send_json(['ok' => true, 'auto' => count($autos), 'manual' => count($manual)]);
}

/**
 * 管理メニュー
 */
add_action('admin_menu', function () {
    add_menu_page(
        'Affiros セールハブ',
        'セール配信',
        'manage_options',
        'affiros-sale-hub',
        'affiros_sh_render_admin_page',
        'dashicons-megaphone',
        78
    );
});

/**
 * datetime-local の値 (2026-09-04T20:00) を保存形式 (2026-09-04 20:00) に揃える
 */
function affiros_sh_normalize_dt($raw) {
    $v = trim(str_replace('T', ' ', (string)$raw));
    $ts = strtotime($v);
    return $ts ? date('Y-m-d H:i', $ts) : '';
}

/**
 * 管理画面 (追加・削除・一覧)
 */
function affiros_sh_render_admin_page() {
    if (!current_user_can('manage_options')) return;

    $notice = '';
    $error  = '';

    // 追加
    if (isset($_POST['affiros_sh_add']) && check_admin_referer('affiros_sh_save')) {
        $mall  = in_array($_POST['mall'] ?? '', ['amazon', 'rakuten'], true) ? $_POST['mall'] : '';
        $label = mb_substr(wp_strip_all_tags(trim((string)($_POST['label'] ?? ''))), 0, 40);
        $start = affiros_sh_normalize_dt($_POST['start'] ?? '');
        $end   = affiros_sh_normalize_dt($_POST['end'] ?? '');
        if (!$mall || $label === '' || !$start || !$end) {
            $error = 'モール・文言・開始・終了はすべて必須です。';
        } elseif (strtotime($end) <= strtotime($start)) {
            $error = '終了日時は開始日時より後にしてください。';
        } else {
            $sales = affiros_sh_get_sales();
            $sales[] = [
                'id'     => uniqid('sale_'),
                'mall'   => $mall,
                'label'  => $label,
                'start'  => $start,
                'end'    => $end,
                'anim'   => affiros_sh_sanitize_anim($_POST['anim'] ?? 'blink'),
                'source' => 'manual',
            ];
            usort($sales, function ($a, $b) { return strcmp($a['start'], $b['start']); });
            update_option(AFFIROS_SH_OPTION_KEY, $sales, false);
            $notice = 'セールを登録しました。次回の各サイト日次取得 (24時間以内) から反映されます。';
        }
    }

    // 削除
    if (isset($_POST['affiros_sh_delete']) && check_admin_referer('affiros_sh_save')) {
        $del_id = sanitize_text_field((string)($_POST['sale_id'] ?? ''));
        $sales = array_values(array_filter(affiros_sh_get_sales(), function ($s) use ($del_id) {
            return ($s['id'] ?? '') !== $del_id;
        }));
        update_option(AFFIROS_SH_OPTION_KEY, $sales, false);
        $notice = '削除しました。';
    }

    $sales  = affiros_sh_get_sales();
    $now_ts = strtotime(current_time('mysql'));
    $ajax_feed = admin_url('admin-ajax.php') . '?action=affiros_sales';
    $rest_feed = home_url('/?rest_route=/affiros/v1/sales');
    $tab = (($_GET['tab'] ?? '') === 'history') ? 'history' : 'manage';
    ?>
    <style>
    /* マイクロコピーのアニメーション (受信側 auto-inserter と同一のデザイン契約) */
    @keyframes affiros-sh-blink  { 0%,100% { opacity: 1; } 50% { opacity: .25; } }
    @keyframes affiros-sh-bounce { 0%,20%,50%,80%,100% { transform: translateY(0); } 40% { transform: translateY(-4px); } 60% { transform: translateY(-2px); } }
    @keyframes affiros-sh-pulse  { 0%,100% { transform: scale(1); } 50% { transform: scale(1.1); } }
    @keyframes affiros-sh-shake  { 0%,88%,100% { transform: rotate(0); } 90% { transform: rotate(2deg); } 92% { transform: rotate(-2deg); } 94% { transform: rotate(1.5deg); } 96% { transform: rotate(-1.5deg); } 98% { transform: rotate(.5deg); } }
    .affiros-sh-anim-none   { animation: none; }
    .affiros-sh-anim-blink  { animation: affiros-sh-blink 1.4s ease-in-out infinite; }
    .affiros-sh-anim-bounce { animation: affiros-sh-bounce 2.2s ease-in-out infinite; }
    .affiros-sh-anim-pulse  { animation: affiros-sh-pulse 1.6s ease-in-out infinite; }
    .affiros-sh-anim-shake  { animation: affiros-sh-shake 3s ease-in-out infinite; }
    @media (prefers-reduced-motion: reduce) { [class^="affiros-sh-anim-"] { animation: none; } }
    </style>
    <div class="wrap">
        <h1>📣 Affiros セールハブ <small style="font-size:12px;color:#888">v<?php echo esc_html(AFFIROS_SH_VERSION); ?></small></h1>
        <h2 class="nav-tab-wrapper" style="margin-bottom:16px">
            <a href="<?php echo esc_url(admin_url('admin.php?page=affiros-sale-hub')); ?>" class="nav-tab <?php echo $tab === 'manage' ? 'nav-tab-active' : ''; ?>">セール管理</a>
            <a href="<?php echo esc_url(admin_url('admin.php?page=affiros-sale-hub&tab=history')); ?>" class="nav-tab <?php echo $tab === 'history' ? 'nav-tab-active' : ''; ?>">📚 実施履歴</a>
        </h2>

        <?php if ($tab === 'history'):
            $history = get_option(AFFIROS_SH_HISTORY_KEY, []);
            if (!is_array($history)) $history = [];
            usort($history, function ($a, $b) { return strcmp((string)$b['end'], (string)$a['end']); }); // 新しい順
        ?>
            <p style="color:#555">終了したセールは自動的にここへ移動します (直近500件)。</p>
            <?php if (!$history): ?>
                <p>まだ実施履歴はありません。</p>
            <?php else: ?>
            <table class="widefat striped" style="max-width:900px">
                <thead><tr><th>モール</th><th>文言</th><th>開始</th><th>終了</th><th>日数</th><th>登録元</th></tr></thead>
                <tbody>
                <?php foreach ($history as $s):
                    $days = max(1, (int)ceil((strtotime($s['end']) - strtotime($s['start'])) / DAY_IN_SECONDS));
                    $mall_badge = ($s['mall'] ?? '') === 'amazon'
                        ? '<span style="background:#ff9900;color:#fff;padding:2px 8px;border-radius:3px;font-size:11px">Amazon</span>'
                        : '<span style="background:#bf0000;color:#fff;padding:2px 8px;border-radius:3px;font-size:11px">楽天</span>';
                ?>
                    <tr>
                        <td><?php echo $mall_badge; ?></td>
                        <td>＼<?php echo esc_html($s['label']); ?>／</td>
                        <td style="white-space:nowrap"><?php echo esc_html($s['start']); ?></td>
                        <td style="white-space:nowrap"><?php echo esc_html($s['end']); ?></td>
                        <td><?php echo $days; ?>日</td>
                        <td style="white-space:nowrap"><?php echo ($s['source'] ?? 'manual') === 'auto' ? '🤖 自動' : '✍️ 手動'; ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>
    </div>
    <?php return; endif; ?>

        <p style="color:#555">ここに登録したセールが、各メディアサイトの商品カードのボタン上に<br>
        「＼<strong>お買い物マラソン開催中</strong>／」のようなマイクロコピーとして表示されます (期間内のみ・自動で消滅)。終了したセールは「📚 実施履歴」タブへ自動移動します。</p>

        <?php if ($notice): ?><div class="notice notice-success"><p><?php echo esc_html($notice); ?></p></div><?php endif; ?>
        <?php if ($error):  ?><div class="notice notice-error"><p><?php echo esc_html($error); ?></p></div><?php endif; ?>

        <p class="description" style="max-width:900px">文言の「＼」「／」は表示側が付けるので不要。<strong>モール公式のセール名をそのまま</strong>使う (景表法対策: 独自の煽り文言・根拠のない「最大○%」は入れない)。日時はすべて日本時間。終了日時を過ぎると全サイトで自動的に表示が消える。</p>

        <div style="display:flex;gap:20px;flex-wrap:wrap;align-items:flex-start;margin-top:12px">
        <?php
        $mall_defs = [
            'amazon'  => ['title' => 'Amazon', 'color' => '#ff9900', 'placeholder' => 'スマイルセール開催中',
                'btn_bg' => 'linear-gradient(180deg,#ffa726,#fb8c00)', 'copy_color' => '#e65100',
                'presets' => [
                'スマイルセール開催中', 'プライムデー開催中', 'プライム感謝祭開催中',
                'ブラックフライデー開催中', 'タイムセール祭り開催中', '初売りセール開催中',
            ]],
            'rakuten' => ['title' => '楽天', 'color' => '#bf0000', 'placeholder' => 'お買い物マラソン開催中',
                'btn_bg' => 'linear-gradient(180deg,#d63a3a,#b71c1c)', 'copy_color' => '#b71c1c',
                'presets' => [
                'お買い物マラソン開催中', '楽天スーパーセール開催中', '楽天ブラックフライデー開催中',
                '楽天大感謝祭開催中', '楽天イーグルス感謝祭開催中', '楽天超ポイントバック祭開催中',
            ]],
        ];
        foreach ($mall_defs as $mall_key => $mc):
            $mall_sales = array_values(array_filter($sales, function ($s) use ($mall_key) {
                return ($s['mall'] ?? '') === $mall_key;
            }));
            // いま本番で表示される開催中セール (終了が近いものを優先 = 受信側と同じ規則)
            $active = null;
            foreach ($mall_sales as $s) {
                if ($now_ts >= strtotime($s['start']) && $now_ts <= strtotime($s['end'])) {
                    if (!$active || strtotime($s['end']) < strtotime($active['end'])) $active = $s;
                }
            }
            $pv_label = $active ? $active['label'] : $mc['placeholder'];
            $pv_anim  = affiros_sh_sanitize_anim($active ? ($active['anim'] ?? 'blink') : 'blink');
        ?>
            <div style="flex:1;min-width:430px;max-width:560px;background:#fff;border:1px solid #ccd0d4;border-top:3px solid <?php echo $mc['color']; ?>;padding:16px">
                <h2 style="margin:0 0 4px"><span style="background:<?php echo $mc['color']; ?>;color:#fff;padding:2px 12px;border-radius:3px;font-size:14px"><?php echo $mc['title']; ?></span> のセール</h2>

                <form method="post" style="margin:12px 0 4px">
                    <?php wp_nonce_field('affiros_sh_save'); ?>
                    <input type="hidden" name="mall" value="<?php echo esc_attr($mall_key); ?>">
                    <table class="form-table" style="margin:0">
                        <tr>
                            <th style="width:90px;padding:8px 0">文言</th>
                            <td style="padding:8px 0">
                                <input type="text" name="label" list="affiros-sh-presets-<?php echo esc_attr($mall_key); ?>" maxlength="40" placeholder="<?php echo esc_attr($mc['placeholder']); ?>" style="width:100%" oninput="document.getElementById('affiros-sh-pv-<?php echo esc_attr($mall_key); ?>').textContent='＼'+(this.value||this.placeholder)+'／'">
                                <datalist id="affiros-sh-presets-<?php echo esc_attr($mall_key); ?>">
                                    <?php foreach ($mc['presets'] as $p): ?><option value="<?php echo esc_attr($p); ?>"><?php endforeach; ?>
                                </datalist>
                            </td>
                        </tr>
                        <tr>
                            <th style="padding:8px 0">開始日時</th>
                            <td style="padding:8px 0"><input type="datetime-local" name="start"></td>
                        </tr>
                        <tr>
                            <th style="padding:8px 0">終了日時</th>
                            <td style="padding:8px 0"><input type="datetime-local" name="end"></td>
                        </tr>
                        <tr>
                            <th style="padding:8px 0">動き</th>
                            <td style="padding:8px 0">
                                <select name="anim" onchange="document.getElementById('affiros-sh-pv-<?php echo esc_attr($mall_key); ?>').className='affiros-sh-anim-'+this.value">
                                    <?php foreach (affiros_sh_anims() as $ak => $an): ?>
                                        <option value="<?php echo esc_attr($ak); ?>"<?php echo $ak === 'blink' ? ' selected' : ''; ?>><?php echo esc_html($an); ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <span class="description">下の表示イメージで動きを確認できます</span>
                            </td>
                        </tr>
                    </table>
                    <p style="margin:8px 0 0"><button type="submit" name="affiros_sh_add" value="1" class="button button-primary"><?php echo $mc['title']; ?>のセールを登録</button></p>
                </form>

                <?php if (!$mall_sales): ?>
                    <p style="color:#888;margin:14px 0 0">登録はありません。</p>
                <?php else: ?>
                <table class="widefat striped" style="margin-top:14px">
                    <thead><tr><th>状態</th><th>文言</th><th>開始</th><th>終了</th><th>登録元</th><th></th></tr></thead>
                    <tbody>
                    <?php foreach ($mall_sales as $s):
                        $st = strtotime($s['start']); $en = strtotime($s['end']);
                        if ($now_ts > $en)       { $badge = '<span style="color:#999">終了</span>'; }
                        elseif ($now_ts >= $st)  { $badge = '<span style="color:#d63638;font-weight:bold">● 開催中</span>'; }
                        else                     { $badge = '<span style="color:#2271b1">開始前</span>'; }
                    ?>
                        <tr>
                            <td style="white-space:nowrap"><?php echo $badge; ?></td>
                            <td>＼<?php echo esc_html($s['label']); ?>／ <span style="color:#999;font-size:11px">(<?php echo esc_html(affiros_sh_anims()[affiros_sh_sanitize_anim($s['anim'] ?? 'blink')]); ?>)</span></td>
                            <td style="white-space:nowrap"><?php echo esc_html($s['start']); ?></td>
                            <td style="white-space:nowrap"><?php echo esc_html($s['end']); ?></td>
                            <td style="white-space:nowrap"><?php echo ($s['source'] ?? 'manual') === 'auto' ? '🤖 自動' : '✍️ 手動'; ?></td>
                            <td>
                                <form method="post" style="display:inline" onsubmit="return confirm('このセールを削除しますか？');">
                                    <?php wp_nonce_field('affiros_sh_save'); ?>
                                    <input type="hidden" name="sale_id" value="<?php echo esc_attr($s['id']); ?>">
                                    <button type="submit" name="affiros_sh_delete" value="1" class="button button-small">削除</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <?php endif; ?>

                <div style="margin-top:16px;border-top:1px dashed #ddd;padding-top:12px">
                    <div style="font-size:12px;color:#666;font-weight:600;margin-bottom:8px">表示イメージ (本番サイトのカード下部)</div>
                    <div style="background:#f6f7f7;border-radius:8px;padding:16px 20px;max-width:340px">
                        <div style="background:#fff;border:1px solid #ececec;border-radius:10px;padding:14px 16px;box-shadow:0 1px 4px rgba(0,0,0,.06)">
                            <div style="text-align:center;font-weight:700;font-size:13px;line-height:1.5">ニトリ(NITORI) インテリア仏壇 NB01<br><span style="font-weight:400;color:#999;font-size:11.5px">※ 商品名はサンプル</span></div>
                            <div style="margin-top:10px">
                                <?php if ($mall_key === 'amazon'): ?>
                                    <div id="affiros-sh-pv-amazon" class="affiros-sh-anim-<?php echo esc_attr($pv_anim); ?>" style="text-align:center;font-size:12.5px;font-weight:700;color:<?php echo $mall_defs['amazon']['copy_color']; ?>;margin-bottom:3px">＼<?php echo esc_html($pv_label); ?>／</div>
                                <?php endif; ?>
                                <span style="display:block;width:100%;box-sizing:border-box;padding:12px 10px;border-radius:6px;font-size:13px;font-weight:700;line-height:1.4;text-align:center;color:#fff;box-shadow:0 1px 3px rgba(0,0,0,.12);background:<?php echo $mall_defs['amazon']['btn_bg']; ?>">Amazonで見る</span>
                                <?php if ($mall_key === 'rakuten'): ?>
                                    <div id="affiros-sh-pv-rakuten" class="affiros-sh-anim-<?php echo esc_attr($pv_anim); ?>" style="text-align:center;font-size:12.5px;font-weight:700;color:<?php echo $mall_defs['rakuten']['copy_color']; ?>;margin:8px 0 3px">＼<?php echo esc_html($pv_label); ?>／</div>
                                <?php endif; ?>
                                <span style="display:block;width:100%;box-sizing:border-box;padding:12px 10px;border-radius:6px;font-size:13px;font-weight:700;line-height:1.4;text-align:center;color:#fff;box-shadow:0 1px 3px rgba(0,0,0,.12);background:<?php echo $mall_defs['rakuten']['btn_bg']; ?>;<?php echo $mall_key === 'rakuten' ? '' : 'margin-top:8px'; ?>">楽天市場で見る</span>
                            </div>
                        </div>
                    </div>
                    <p class="description" style="margin-top:6px">
                        <?php if ($active): ?>
                            <span style="color:#d63638;font-weight:700">● いま本番で表示される文言です</span> (開催中のセール)
                        <?php else: ?>
                            いまは開催中のセールがないため、本番ではマイクロコピーなしのボタンだけが出ます (上はサンプル)。文言欄に入力するとリアルタイムで反映されます。
                        <?php endif; ?>
                    </p>
                </div>
            </div>
        <?php endforeach; ?>
        </div>

        <h2 style="margin-top:28px">配信エンドポイント</h2>
        <table class="widefat" style="max-width:900px">
            <tbody>
                <tr>
                    <td style="width:120px"><strong>主経路</strong> (admin-ajax)</td>
                    <td><a href="<?php echo esc_url($ajax_feed); ?>" target="_blank"><?php echo esc_html($ajax_feed); ?></a></td>
                </tr>
                <tr>
                    <td><strong>予備</strong> (REST)</td>
                    <td><a href="<?php echo esc_url($rest_feed); ?>" target="_blank"><?php echo esc_html($rest_feed); ?></a></td>
                </tr>
            </tbody>
        </table>
        <p class="description" style="margin-top:8px">各サイトの affiros-auto-inserter が1日1回このJSONを取得してキャッシュします。登録・削除の反映は最長24時間後。終了だけは受信側でも期間判定するので、終了日時を過ぎれば取得を待たずに表示が消えます。</p>

        <h2 style="margin-top:28px">自動巡回 (毎朝のセール情報自動登録)</h2>
        <p style="color:#555;max-width:720px">毎朝1回、AIエージェントが Amazon・楽天の公式セール情報を調査して「🤖 自動」行を書き込みます。<br>
        ✍️ 手動で登録した行には触りません (同モール・期間重複の自動行は手動が勝ちます)。自動行が間違っていたら手動で同じセールを登録すれば上書きできます。</p>
        <table class="form-table" style="max-width:900px">
            <tr>
                <th style="width:160px">連携トークン</th>
                <td>
                    <input type="password" id="affiros-sh-token" readonly value="<?php echo esc_attr(affiros_sh_get_token()); ?>" class="regular-text" style="font-family:monospace">
                    <button type="button" class="button" onclick="var f=document.getElementById('affiros-sh-token');f.type=f.type==='password'?'text':'password';this.textContent=f.type==='password'?'表示':'隠す';">表示</button>
                    <p class="description">自動巡回エージェントだけに渡す。漏れた場合はこのプラグインを無効化→有効化…ではなく、DBの <code>affiros_sale_hub_token</code> オプションを削除すると再生成される。</p>
                </td>
            </tr>
        </table>
    </div>
    <?php
}
