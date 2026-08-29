<?php
/**
 * Plugin Name: Affiros セールハブ
 * Description: Amazon・楽天のセール情報を一元管理して全メディアサイトへ配信する。ke-ys.co.jp に設置する配信元プラグイン。各サイトの affiros-auto-inserter が1日1回ここのJSONを取得し、開催中のセールをカードボタン上のマイクロコピーとして表示する。
 * Version: 1.1.0
 * Author: Affiros
 * License: GPL v2 or later
 * Text Domain: affiros-sale-hub
 */

if (!defined('ABSPATH')) exit;

define('AFFIROS_SH_VERSION',    '1.1.0');
define('AFFIROS_SH_OPTION_KEY', 'affiros_sale_hub_sales');
define('AFFIROS_SH_TOKEN_KEY',  'affiros_sale_hub_token');

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
 * セール一覧を取得する。終了から60日超の行はここで自動掃除する
 * (配信・管理画面どちらの入口でも通るので、掃除漏れは起きない)。
 */
function affiros_sh_get_sales() {
    $sales = get_option(AFFIROS_SH_OPTION_KEY, []);
    if (!is_array($sales)) $sales = [];
    $cutoff = strtotime(current_time('mysql')) - 60 * DAY_IN_SECONDS;
    $kept = array_values(array_filter($sales, function ($s) use ($cutoff) {
        $end = strtotime((string)($s['end'] ?? ''));
        return $end && $end >= $cutoff;
    }));
    if (count($kept) !== count($sales)) {
        update_option(AFFIROS_SH_OPTION_KEY, $kept, false);
    }
    return $kept;
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
function affiros_sh_validate_row($mall, $label, $start, $end, $source) {
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
            $r['mall'] ?? '', $r['label'] ?? '', $r['start'] ?? '', $r['end'] ?? '', 'auto'
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
    ?>
    <div class="wrap">
        <h1>📣 Affiros セールハブ <small style="font-size:12px;color:#888">v<?php echo esc_html(AFFIROS_SH_VERSION); ?></small></h1>
        <p style="color:#555">ここに登録したセールが、各メディアサイトの商品カードのボタン上に<br>
        「＼<strong>お買い物マラソン開催中</strong>／」のようなマイクロコピーとして表示されます (期間内のみ・自動で消滅)。</p>

        <?php if ($notice): ?><div class="notice notice-success"><p><?php echo esc_html($notice); ?></p></div><?php endif; ?>
        <?php if ($error):  ?><div class="notice notice-error"><p><?php echo esc_html($error); ?></p></div><?php endif; ?>

        <h2>セールを追加</h2>
        <form method="post" style="background:#fff;border:1px solid #ccd0d4;padding:16px;max-width:720px">
            <?php wp_nonce_field('affiros_sh_save'); ?>
            <table class="form-table" style="margin-top:0">
                <tr>
                    <th style="width:120px">モール</th>
                    <td>
                        <label style="margin-right:16px"><input type="radio" name="mall" value="amazon" checked> Amazon</label>
                        <label><input type="radio" name="mall" value="rakuten"> 楽天</label>
                    </td>
                </tr>
                <tr>
                    <th>表示する文言</th>
                    <td>
                        <input type="text" name="label" list="affiros-sh-presets" class="regular-text" maxlength="40" placeholder="お買い物マラソン開催中">
                        <datalist id="affiros-sh-presets">
                            <option value="お買い物マラソン開催中">
                            <option value="楽天スーパーセール開催中">
                            <option value="楽天ブラックフライデー開催中">
                            <option value="楽天大感謝祭開催中">
                            <option value="楽天イーグルス感謝祭開催中">
                            <option value="スマイルセール開催中">
                            <option value="プライムデー開催中">
                            <option value="プライム感謝祭開催中">
                            <option value="ブラックフライデー開催中">
                            <option value="タイムセール祭り開催中">
                        </datalist>
                        <p class="description">「＼」「／」は表示側が付けるので不要。<strong>モール公式のセール名をそのまま</strong>使う (景表法対策: 独自の煽り文言・根拠のない「最大○%」は入れない)。</p>
                    </td>
                </tr>
                <tr>
                    <th>開始日時</th>
                    <td><input type="datetime-local" name="start"> <span class="description">日本時間</span></td>
                </tr>
                <tr>
                    <th>終了日時</th>
                    <td><input type="datetime-local" name="end"> <span class="description">日本時間。過ぎると全サイトで自動的に表示が消える</span></td>
                </tr>
            </table>
            <p><button type="submit" name="affiros_sh_add" value="1" class="button button-primary">登録する</button></p>
        </form>

        <h2 style="margin-top:28px">登録済みセール</h2>
        <?php if (!$sales): ?>
            <p>登録はありません。</p>
        <?php else: ?>
        <table class="widefat striped" style="max-width:900px">
            <thead><tr><th>状態</th><th>モール</th><th>文言</th><th>開始</th><th>終了</th><th>登録元</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($sales as $s):
                $st = strtotime($s['start']); $en = strtotime($s['end']);
                if ($now_ts > $en)       { $badge = '<span style="color:#999">終了</span>'; }
                elseif ($now_ts >= $st)  { $badge = '<span style="color:#d63638;font-weight:bold">● 開催中</span>'; }
                else                     { $badge = '<span style="color:#2271b1">開始前</span>'; }
                $mall_badge = $s['mall'] === 'amazon'
                    ? '<span style="background:#ff9900;color:#fff;padding:2px 8px;border-radius:3px;font-size:11px">Amazon</span>'
                    : '<span style="background:#bf0000;color:#fff;padding:2px 8px;border-radius:3px;font-size:11px">楽天</span>';
            ?>
                <tr>
                    <td><?php echo $badge; ?></td>
                    <td><?php echo $mall_badge; ?></td>
                    <td>＼<?php echo esc_html($s['label']); ?>／</td>
                    <td><?php echo esc_html($s['start']); ?></td>
                    <td><?php echo esc_html($s['end']); ?></td>
                    <td><?php echo ($s['source'] ?? 'manual') === 'auto' ? '🤖 自動' : '✍️ 手動'; ?></td>
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
