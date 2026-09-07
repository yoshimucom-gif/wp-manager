<?php
/**
 * re:Diver の「どこに保存されているか分からない設定」のカタログ。
 *
 * GET /rdh/v1/diver-keys
 *
 * カスタマイザーの項目は theme_mod・オプション・グローバルスタイルに散っていて、
 * キー名を知らないと「管理画面でしかできません」と誤って答えることになる。
 * ここは **現在値つきの索引** を返す。値を見れば、設定済みか未設定かもその場で分かる。
 *
 * 判定つきで返すもの:
 *   - 配色のカスタムカラーが「値は入っているのにフラグが立っていない」状態の検出
 *     （diver_color.isCustom が false だと diver_color_custom は丸ごと無視される）
 */

if (!defined('ABSPATH')) exit;

/**
 * ブロックのプリセット色を持つグローバルスタイル投稿のID
 */
function rdh_diver_global_styles_id() {
    if (class_exists('WP_Theme_JSON_Resolver')
        && method_exists('WP_Theme_JSON_Resolver', 'get_user_global_styles_post_id')) {
        return WP_Theme_JSON_Resolver::get_user_global_styles_post_id();
    }
    return null;
}

function rdh_diver_keys(WP_REST_Request $req) {
    $mods = get_theme_mods();
    $mods = is_array($mods) ? $mods : [];
    $get  = function ($key) use ($mods) {
        return array_key_exists($key, $mods) ? $mods[$key] : null;
    };

    $color        = $get('diver_color');
    $color_custom = $get('diver_color_custom');
    $is_custom    = is_array($color) && !empty($color['isCustom']);
    $has_custom   = is_array($color_custom) && count(array_filter($color_custom)) > 0;

    $warnings = [];
    if ($has_custom && !$is_custom) {
        $warnings[] = 'diver_color_custom に色が入っているのに diver_color.isCustom が立っていない。'
            . 'この状態ではテーマは既定の配色のまま描画する。'
            . 'POST /thememods {"key":"diver_color","value":{"theme":"light-black","isCustom":true}} で有効化する。';
    }

    $gs_id = rdh_diver_global_styles_id();

    $items = [
        [
            'label'   => '配色（カスタマイズ > カラー > カスタムカラー）',
            'where'   => 'theme_mod',
            'keys'    => ['diver_color', 'diver_color_custom'],
            'current' => ['diver_color' => $color, 'diver_color_custom' => $color_custom],
            'write'   => [
                'POST /thememods {"key":"diver_color","value":{"theme":"light-black","isCustom":true}}',
                'POST /thememods {"key":"diver_color_custom","value":'
                    . '{"accent":"#6F7E5C","link":"#5C6A4C","secondary":"#46372E","text":"#46372E","background":"#FAF7F2"}}',
            ],
            'note'    => 'isCustom を立てないと diver_color_custom は無視される。'
                . 'secondary は濃い面（ヘッダー帯・フッター・H2のbox・タブの選択色）。'
                . '反映はフロントの --rd--c--text 等で照合する（0 0 0 のままなら効いていない）。'
                . '追加CSSで --rd--c--* を上書きする必要はない。',
        ],
        [
            'label'   => 'ブロックのプリセット色（has-diver-secondary-* など）',
            'where'   => 'グローバルスタイル（wp_global_styles）',
            'keys'    => ['settings.color.palette.theme'],
            'current' => ['global_styles_post_id' => $gs_id],
            'write'   => [
                'POST /wp/v2/global-styles/' . ($gs_id ?: '<id>')
                    . ' {"settings":{"color":{"palette":{"theme":[ …23件… ]}}}}',
            ],
            'note'    => '上の配色設定では変わらない別系統。TOPの帯・タブ・カテゴリカードがここ。'
                . '23スラッグ（status系7 ＋ text-1..4 / text / background / secondary-1..4 / '
                . 'secondary / secondary-on / primary / primary-on / link / accent）を配列ごと差し替える。'
                . 'IDは GET /wp/v2/themes?status=active の _links["wp:user-global-styles"] にも出る。'
                . 're:Diver は classic theme なので styles.css（グローバルスタイルの追加CSS）はフロントに出力されない。',
        ],
        [
            'label'   => '見出しのデザインと色（カスタマイズ > 見出しデザイン）',
            'where'   => 'theme_mod',
            'keys'    => ['diver_content_heading'],
            'current' => ['diver_content_heading' => $get('diver_content_heading')],
            'write'   => [
                'POST /thememods {"key":"diver_content_heading","value":'
                    . '{"style":{"h2":"box","h4":"border"},'
                    . '"color":{"h3":"{\\"background\\":\\"#6F7E5C\\",\\"text\\":\\"#46372E\\"}"}}}',
            ],
            'note'    => 'color の値は配列ではなく **JSON文字列**。'
                . '背景を指定すると、テーマ側が薄め（0.1）と左バー（0.8）に自動で振る。'
                . '空にしておけば配色の secondary を使うので、カスタムカラーだけで揃うことも多い。',
        ],
        [
            'label'   => 'メインビジュアル（カスタマイズ > メインビジュアル）',
            'where'   => 'theme_mod ＋ firstview 投稿タイプ',
            'keys'    => ['diver_firstview_id', 'diver_firstview_visible'],
            'current' => [
                'diver_firstview_id'      => $get('diver_firstview_id'),
                'diver_firstview_visible' => $get('diver_firstview_visible'),
            ],
            'write'   => [
                'POST /wp/v2/firstview  （中身は通常のdbpブロック）',
                'POST /thememods {"key":"diver_firstview_id","value":<投稿ID>}',
                'POST /thememods {"key":"diver_firstview_visible","value":{"type":"custom","custom":["is_front_page"],"layout":"full"}}',
            ],
            'note'    => 'そのページの投稿メタ diver_single_layout で sidebar を hide にすると、'
                . 'メインビジュアルは描画されない。1カラム全幅にしたいなら、'
                . '同じ構成を固定ページ本文の先頭に置く。',
        ],
        [
            'label'   => 'ロゴ・キャッチフレーズ',
            'where'   => 'theme_mod ／ 標準の settings',
            'keys'    => ['custom_logo', 'blogdescription'],
            'current' => ['custom_logo' => $get('custom_logo'),
                          'blogdescription' => get_option('blogdescription')],
            'write'   => [
                'POST /thememods {"key":"custom_logo","value":<添付ID>}',
                'POST /wp/v2/settings {"description":"…"}',
            ],
            'note'    => 'キャッチフレーズはTOPの meta description の出力元。空だと description が出ない。',
        ],
        [
            'label'   => 'カテゴリ画像',
            'where'   => 'termmeta',
            'keys'    => ['media_id'],
            'current' => null,
            'write'   => ['POST /termmeta/<term_id> {"key":"media_id","value":"<添付ID>"}'],
            'note'    => 'アーカイブの見出しには出ない。使われるのは dbp/category ブロックのカードなど。',
        ],
        [
            'label'   => '記事・固定ページのレイアウト（記事幅・サイドバー・タイトル）',
            'where'   => 'postmeta（PHPシリアライズ配列）',
            'keys'    => ['diver_single_layout', 'diver_single_toc', 'diver_single_cta'],
            'current' => null,
            'write'   => [
                'POST /postmeta/<post_id> {"key":"diver_single_layout","value":'
                    . '{"title":"hide","sidebar":"hide","size":"full","design":"flat","width":"large","content_gap":6,"gap":4}}',
            ],
            'note'    => '🚨 読んだシリアライズ文字列をそのまま書き戻すと二重シリアライズになる。'
                . '**JSONのオブジェクトで渡す**（PHP側でシリアライズする）。',
        ],
        [
            'label'   => 'テーマ設定（RE:DIVER > 基本設定）',
            'where'   => 'wp_options',
            'keys'    => ['diver_ga', 'diver_site_maintenance', 'diver_site_publisher', 'diver_sidebar'],
            'current' => null,
            'write'   => ['POST /option/diver_ga {"value":{"id":"G-XXXX"},"merge":true}'],
            'note'    => '管理画面で一度も保存していないとオプション行が存在せず、'
                . 'GET /option/<name> は404を返す。その場合は値を丸ごとPOSTして作る。',
        ],
        [
            'label'   => '追加CSS',
            'where'   => 'custom_css 投稿（標準RESTに出ない）',
            'keys'    => ['custom_css_post_id'],
            'current' => ['custom_css_post_id' => $get('custom_css_post_id')],
            'write'   => ['Search Regex の差分置換（/search-regex/v1/search に action=replace, save=true）'],
            'note'    => '配色のためにここを使う必要はない。テーマのカスタムカラーで足りる。',
        ],
    ];

    return [
        'version'      => RDH_VERSION,
        'stylesheet'   => get_stylesheet(),
        'color_status' => [
            'isCustom'          => $is_custom,
            'has_custom_colors' => $has_custom,
            'effective'         => $is_custom && $has_custom,
        ],
        'warnings'     => $warnings,
        'items'        => $items,
        'notes'        => [
            'ここに出ているものは全部RESTで書ける。「管理画面でしかできません」と答えない。',
            '書いたあとは必ずフロントのHTML（CSS変数や実際のクラス）で照合する。changed=true でも描画に出ないことがある。',
        ],
    ];
}

add_action('rest_api_init', function () {
    register_rest_route(RDH_NS, '/diver-keys', [
        'methods'             => 'GET',
        'permission_callback' => 'rdh_permission',
        'callback'            => 'rdh_diver_keys',
    ]);
});
