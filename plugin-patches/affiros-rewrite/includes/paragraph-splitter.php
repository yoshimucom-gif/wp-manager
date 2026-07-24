<?php
/**
 * 段落整形モジュール（affiros-rewrite v0.5.0 で統合）
 *
 * 元は独立プラグイン affiros-paragraph-splitter だったが、
 * 章入れ替え/広告削除挿入/リライトと同じ「生成済み記事のHTML品質を機械的に整える」
 * 系機能なので affiros-rewrite に統合した。
 *
 * 共存ガード:
 * - 旧 affiros-paragraph-splitter プラグインが有効な環境では
 *   AFFIROS_PSPLIT_VERSION が先に define されている可能性があるので、
 *   このモジュールは何もせず終了する。
 * - 旧プラグイン側 v1.1.4 以降は逆に、統合版が読まれていたら自分は skip する
 *   (AFFIROS_PSPLIT_INTEGRATED_LOADED 定数で判定)
 */

if (!defined('ABSPATH')) exit;

// v0.5.10: 定数は**ガードより先に**無条件で定義する。
// これまで「function_exists 系ガードが発火 → return → 定数が定義されない」
// 状態で render_tab_body が別経路から呼ばれた時に PHP 8+ が
// "Undefined constant AFFIROS_PSPLIT_OPTION_KEY" Fatal error を出していた
// (bousui-goods.com 実測、段落整形ページで発生)。
// 定数はどのフローでも必ず存在するようにガードから外す。
if (!defined('AFFIROS_PSPLIT_VERSION')) {
    define('AFFIROS_PSPLIT_VERSION', '1.1.3');
}
if (!defined('AFFIROS_PSPLIT_OPTION_KEY')) {
    define('AFFIROS_PSPLIT_OPTION_KEY', 'affiros_psplit_settings');
}

// v0.5.13: 旧独立プラグインとの共存ガード。
//
// 【重要】v0.5.12 まで `function_exists('affiros_psplit_default_settings')` で
// 判定していたが、PHP の**関数ホイスティング**により、この判定は
// このファイル自身の後方で宣言している関数でも true を返してしまう。
// 結果としてガードが常に発火し、add_action(...)群 (admin_init /
// wp_ajax_* / admin_enqueue_scripts) が一切登録されず:
//   - 設定保存が「許可されたオプションリスト内にありません」で失敗
//   - AJAX が 400 status body="0" で失敗
//   - 表示だけは動く (関数はホイストされるので render_tab_body は呼べる)
// を引き起こしていた (karada-thermo.com 実測)。
//
// 修正: function_exists ではなく WordPress の active_plugins オプションを
// 直接見て、旧独立プラグインがアクティブかどうかを判定する。これなら
// ホイスティングの影響を受けない。
if (defined('AFFIROS_PSPLIT_INTEGRATED_LOADED')) return;

$__affiros_psplit_standalone_slug = 'affiros-paragraph-splitter/affiros-paragraph-splitter.php';
$__affiros_psplit_active_plugins  = (array) get_option('active_plugins', []);
$__affiros_psplit_standalone_path = (defined('WP_PLUGIN_DIR') ? WP_PLUGIN_DIR : WP_CONTENT_DIR . '/plugins')
    . '/' . $__affiros_psplit_standalone_slug;

if (in_array($__affiros_psplit_standalone_slug, $__affiros_psplit_active_plugins, true)
    && file_exists($__affiros_psplit_standalone_path)) {
    // 旧独立プラグインが active かつファイル存在 → そちらに任せる
    add_action('admin_notices', function () {
        if (!current_user_can('manage_options')) return;
        echo '<div class="notice notice-warning is-dismissible"><p>'
            . '<strong>Affiros:</strong> 「Affiros 段落整形」プラグインは Affiros ポストプロセッサー (v0.5.0〜) に統合されました。'
            . '<strong>プラグイン一覧で旧「Affiros 段落整形」を無効化・削除</strong>してください。設定と挙動は完全に引き継がれます。'
            . '</p></div>';
    });
    unset($__affiros_psplit_standalone_slug, $__affiros_psplit_active_plugins, $__affiros_psplit_standalone_path);
    return;
}
unset($__affiros_psplit_standalone_slug, $__affiros_psplit_active_plugins, $__affiros_psplit_standalone_path);
define('AFFIROS_PSPLIT_INTEGRATED_LOADED', true);

// =============================================================================
// 設定
// =============================================================================

function affiros_psplit_default_settings() {
    return [
        'min_paragraph_chars'  => 200,
        'min_sentence_chars'   => 60,
        'force_split_chars'    => 300,  // この文字数を超えたら読点でも分割
        'connectors'           => "また、\nただし、\nさらに、\n一方、\nつまり、\nなお、\nちなみに、\nそして、\nしかし、\nしたがって、\nこのように、\n特に、\n例えば、\n実際、\nもちろん、",
        'auto_on_save'         => 'no',   // 保存時 hook
        'add_heading_spacing'  => 'yes',  // H2/H3 前後の空段落
        'add_media_spacing'    => 'yes',  // 画像・表の前後の空段落
        'normalize_punctuation'=> 'yes',  // 「。。」→「。」
        'promote_headings'     => 'yes',  // 見出しっぽい段落を h4 に昇格
        'heading_level'        => '4',    // 昇格先のレベル (3 or 4)
        'heading_patterns'     => "ポイント\\d+[：:.]\nステップ\\d+[：:.]\n第\\d+[章節項段話回]\nFAQ\\d*[：:.]\nQ\\d+[：:.]\n質問\\d+[：:.]\n注意点\\d+[：:.]\n手順\\d+[：:.]\n方法\\d+[：:.]\n^【[^】]{2,30}】",
        'heading_max_chars'    => 60,     // 段落が何文字以下なら見出し候補にするか
        'split_strong_label_list' => 'yes',  // <li><strong>ラベル</strong>：内容 を見出し+段落に分割
        'split_min_content_chars' => 25,     // 説明文がこの文字数を超えるときだけ分割対象
        'split_every_period'   => 'no',   // v1.1.6: 句点(。！？) 毎に1段落 (縦読み感重視モード)
        'target_statuses'      => 'publish,future,draft',
    ];
}

function affiros_psplit_get_settings() {
    $saved = get_option(AFFIROS_PSPLIT_OPTION_KEY, []);
    return array_merge(affiros_psplit_default_settings(), is_array($saved) ? $saved : []);
}

// =============================================================================
// 整形ロジック（コア）
// =============================================================================

/**
 * post_content を段落整形する。
 *
 * 流れ:
 *   1. 句読点の正規化（「。。」→「。」など）
 *   2. 各 <p>...</p> を見て、長すぎるなら分割
 *   3. H2/H3 や画像の前後に空段落を入れて視覚的余白を確保
 *   4. 全 <p> を <!-- wp:paragraph --> ブロックでラップして返す
 */
function affiros_psplit_process_content($content, $settings = null) {
    if ($settings === null) $settings = affiros_psplit_get_settings();
    if (!$content || trim($content) === '') return $content;

    // 1) 一旦 wp:paragraph コメントを剥がす（再ラップは最後にする）
    $work = preg_replace('/<!--\s*\/?wp:paragraph[^>]*-->\s*/i', '', $content);

    // 2) 句読点の正規化
    if (($settings['normalize_punctuation'] ?? 'yes') === 'yes') {
        $work = affiros_psplit_normalize_punctuation($work);
    }

    // 3) 各 <p>...</p> を整形
    $min_p = max(80, intval($settings['min_paragraph_chars'] ?? 200));
    $min_s = max(20, intval($settings['min_sentence_chars'] ?? 60));
    $force = max(120, intval($settings['force_split_chars'] ?? 300));
    $connectors = affiros_psplit_parse_connectors($settings['connectors'] ?? '');

    $work = preg_replace_callback(
        '/<p\b([^>]*)>([\s\S]*?)<\/p>/i',
        function ($m) use ($min_p, $min_s, $force, $connectors) {
            $attr = $m[1];
            $inner = $m[2];

            // 画像・表・リスト・div を含む <p> はスキップ
            if (preg_match('/<(img|table|ul|ol|div|figure|iframe|hr|blockquote)\b/i', $inner)) {
                return $m[0];
            }
            $plain = trim(preg_replace('/<[^>]+>/u', '', $inner));
            $plain_len = mb_strlen($plain);
            if ($plain_len <= $min_p) {
                // 短いのでそのまま
                return '<p' . $attr . '>' . trim($inner) . '</p>';
            }

            $segments = affiros_psplit_split_inner($inner, $min_s, $force, $connectors);
            if (count($segments) < 2) {
                return '<p' . $attr . '>' . trim($inner) . '</p>';
            }
            $out = '';
            foreach ($segments as $seg) {
                $seg = trim($seg);
                if ($seg === '') continue;
                $out .= '<p' . $attr . '>' . $seg . '</p>';
            }
            return $out ?: $m[0];
        },
        $work
    );

    // 3.5) 見出しっぽい段落・<li> を見出しに昇格
    if (($settings['promote_headings'] ?? 'yes') === 'yes') {
        $work = affiros_psplit_promote_paragraph_headings($work, $settings);
        // <li> 全体が「ポイントN：」等の短いパターンならまるごと見出し化
        $work = affiros_psplit_promote_list_item_headings($work, $settings);
    }

    // 3.7) <li> 内の「<strong>ラベル</strong>：長い説明文」を 見出し + 段落 に分割
    //      親見出しレベルを検出して、その +1 を子見出しレベルに使う（context-aware）
    if (($settings['split_strong_label_list'] ?? 'yes') === 'yes') {
        $work = affiros_psplit_split_strong_label_list($work, $settings);
    }

    // 3.8) v1.1.6: 句点 (。！？) 毎に強制的に1段落にする「縦読みモード」
    //     min_paragraph_chars や min_sentence_chars を無視して、全ての段落を
    //     句点で機械的に区切る。1文=1段落。読みやすさ最優先。
    if (($settings['split_every_period'] ?? 'no') === 'yes') {
        $work = affiros_psplit_split_every_period($work);
    }

    // 4) H2/H3 前後の余白（空段落）
    if (($settings['add_heading_spacing'] ?? 'yes') === 'yes') {
        $work = affiros_psplit_add_heading_spacing($work);
    }

    // 5) 画像・表前後の余白
    if (($settings['add_media_spacing'] ?? 'yes') === 'yes') {
        $work = affiros_psplit_add_media_spacing($work);
    }

    // 6) 連続空段落・改行の正規化
    $work = preg_replace('/(<p[^>]*>(?:\s|&nbsp;)*<\/p>\s*){2,}/i', "<p></p>\n", $work);
    $work = preg_replace("/(\r?\n){3,}/", "\n\n", $work);

    // 7) 全 <p> を wp:paragraph ブロックでラップ
    $work = preg_replace_callback(
        '/<p\b([^>]*)>([\s\S]*?)<\/p>/i',
        function ($m) {
            return "<!-- wp:paragraph -->\n<p" . $m[1] . '>' . $m[2] . "</p>\n<!-- /wp:paragraph -->";
        },
        $work
    );

    return $work;
}

/**
 * <p> の内側 HTML を「句点」「接続詞」で分割する。
 * 各セグメントは min_s 文字以上になるよう蓄積してから区切る。
 */
function affiros_psplit_split_inner($inner_html, $min_sentence, $force_split_chars, $connectors) {
    // まず inner を「句点」位置で分割（タグの中身は分割対象外にするため、
    // タグはプレースホルダーに置換して plain text 上で位置を確定し、戻す）
    $tags = [];
    $plain_template = preg_replace_callback(
        '/<[^>]+>/u',
        function ($m) use (&$tags) {
            $tags[] = $m[0];
            return "\x02TAG" . (count($tags) - 1) . "\x03";
        },
        $inner_html
    );

    // 句点で分割（。！？の直後で切る）
    $pieces = preg_split('/(?<=[。！？\?\!])/u', $plain_template);
    if (!$pieces) return [$inner_html];

    // 接続詞の直前でも分割
    if (!empty($connectors)) {
        $expanded = [];
        foreach ($pieces as $piece) {
            // 各接続詞の前で更に細かく分割（最初の出現で1回だけ）
            $sub = [$piece];
            foreach ($connectors as $conn) {
                $new_sub = [];
                foreach ($sub as $p) {
                    // 「。」直後とくっついてる接続詞は二重分割になるのでスキップ判定:
                    // 「。また、」の場合、既に上で分割済みなのでスルー
                    $pos = mb_strpos($p, $conn);
                    if ($pos === false || $pos === 0) {
                        $new_sub[] = $p;
                        continue;
                    }
                    // 「接続詞の前」が十分長い場合のみ分割
                    $before = mb_substr($p, 0, $pos);
                    $before_plain = preg_replace('/\x02TAG\d+\x03/u', '', $before);
                    if (mb_strlen(trim($before_plain)) >= 30) {
                        $new_sub[] = $before;
                        $new_sub[] = mb_substr($p, $pos);
                    } else {
                        $new_sub[] = $p;
                    }
                }
                $sub = $new_sub;
            }
            foreach ($sub as $s) {
                if ($s !== '') $expanded[] = $s;
            }
        }
        $pieces = $expanded;
    }

    // セグメントを蓄積（min_sentence 字未満は前にくっつける）
    $segments = [];
    $buf = '';
    foreach ($pieces as $piece) {
        $buf .= $piece;
        $buf_plain = preg_replace('/\x02TAG\d+\x03/u', '', $buf);
        if (mb_strlen(trim($buf_plain)) >= $min_sentence) {
            $segments[] = $buf;
            $buf = '';
        }
    }
    if ($buf !== '') {
        if (!empty($segments)) {
            $segments[count($segments) - 1] .= $buf; // 末尾断片は前に結合
        } else {
            $segments[] = $buf;
        }
    }

    // force_split: それでもまだ長すぎる段落は読点で強制分割
    $final = [];
    foreach ($segments as $seg) {
        $seg_plain = preg_replace('/\x02TAG\d+\x03/u', '', $seg);
        if (mb_strlen(trim($seg_plain)) <= $force_split_chars) {
            $final[] = $seg;
            continue;
        }
        // 読点「、」で強制分割（min_sentence字以上のセグメントを目指す）
        $sub_pieces = preg_split('/(?<=、)/u', $seg);
        $sub_buf = '';
        foreach ($sub_pieces as $sp) {
            $sub_buf .= $sp;
            $sb_plain = preg_replace('/\x02TAG\d+\x03/u', '', $sub_buf);
            if (mb_strlen(trim($sb_plain)) >= $min_sentence) {
                $final[] = $sub_buf;
                $sub_buf = '';
            }
        }
        if ($sub_buf !== '') {
            if (!empty($final)) {
                $final[count($final) - 1] .= $sub_buf;
            } else {
                $final[] = $sub_buf;
            }
        }
    }

    // タグプレースホルダーを実タグに戻す
    foreach ($final as &$seg) {
        $seg = preg_replace_callback(
            '/\x02TAG(\d+)\x03/u',
            function ($m) use ($tags) {
                return $tags[intval($m[1])] ?? '';
            },
            $seg
        );
    }
    unset($seg);

    return $final;
}

function affiros_psplit_normalize_punctuation($html) {
    // 「。。」「？？」「！！」連続 → 1つに（文末の感嘆連続は無視するため4回以上のみ正規化）
    $html = preg_replace('/。{2,}/u', '。', $html);
    $html = preg_replace('/、{2,}/u', '、', $html);
    // 段落末の全角・半角スペース除去
    $html = preg_replace('/[ \t　]+(?=<\/p>)/u', '', $html);
    return $html;
}

function affiros_psplit_parse_connectors($raw) {
    $list = preg_split('/[\r\n,，]+/u', (string)$raw);
    $out = [];
    foreach ($list as $c) {
        $c = trim($c);
        if ($c !== '') $out[] = $c;
    }
    return $out;
}

/**
 * 「ポイントN：xxx」「ステップN：xxx」「【xxx】yyy」のような
 * 見出しっぽい段落を h4 (or h3) に昇格する。
 *
 * 判定条件（すべて満たすときだけ昇格）:
 *   - 段落が画像・リスト・他見出しを含まない
 *   - 段落の plain text が heading_max_chars 以下
 *   - 段落が見出しパターン（正規表現）にマッチ
 *   - 段落末尾に句点「。」が無い（文ではなく見出し）
 *
 * 既存 h2-h6 は触らない。<a>/<strong> 等のインラインタグは保持。
 */
function affiros_psplit_promote_paragraph_headings($html, $settings) {
    $max_chars = max(20, min(200, intval($settings['heading_max_chars'] ?? 60)));
    $level = in_array($settings['heading_level'] ?? '4', ['2', '3', '4', '5'], true)
        ? $settings['heading_level']
        : '4';
    $patterns_raw = $settings['heading_patterns'] ?? '';
    $patterns = array_filter(array_map('trim', preg_split('/\r?\n/', $patterns_raw)));
    if (empty($patterns)) return $html;

    // 各パターンを正規表現に変換（`^` 始まりでなければ自動付与）
    $regex_list = [];
    foreach ($patterns as $p) {
        if (strpos($p, '^') !== 0) {
            $p = '^\s*' . $p;
        }
        $regex_list[] = '/' . str_replace('/', '\/', $p) . '/u';
    }

    return preg_replace_callback(
        '/<p\b([^>]*)>([\s\S]*?)<\/p>/i',
        function ($m) use ($max_chars, $level, $regex_list) {
            $inner = $m[2];
            // 画像・表・リスト・他見出しを含む段落はスキップ
            if (preg_match('/<(img|table|ul|ol|h[1-6]|div|figure|iframe|hr|blockquote)\b/i', $inner)) {
                return $m[0];
            }
            $plain = trim(preg_replace('/<[^>]+>/u', '', $inner));
            if ($plain === '') return $m[0];
            if (mb_strlen($plain) > $max_chars) return $m[0];
            // 末尾「。」がある = 文章扱い、見出しじゃない
            if (preg_match('/[。.!?！？]\s*$/u', $plain)) return $m[0];

            // パターンマッチング
            $matched = false;
            foreach ($regex_list as $rx) {
                if (preg_match($rx, $plain)) { $matched = true; break; }
            }
            if (!$matched) return $m[0];

            // h タグに置換（インラインタグ保持）
            $level_attr = $level === '2' ? '' : ' {"level":' . intval($level) . '}';
            return "<!-- wp:heading{$level_attr} -->\n"
                 . "<h{$level} class=\"wp-block-heading\">" . trim($inner) . "</h{$level}>\n"
                 . "<!-- /wp:heading -->";
        },
        $html
    );
}

/**
 * <ul>/<ol> 内の <li> で見出しパターンにマッチするものを h4 (or h3) に昇格する。
 * 昇格した <li> はリストから切り出され、残りの <li> は同種のリストとして維持。
 *
 * 例:
 *   <ul>
 *     <li>ポイント1：xxx</li>
 *     <li>普通の項目</li>
 *     <li>ポイント2：yyy</li>
 *   </ul>
 *   →
 *   <h4>ポイント1：xxx</h4>
 *   <ul><li>普通の項目</li></ul>
 *   <h4>ポイント2：yyy</h4>
 *
 * 全 <li> がマッチした場合はリスト自体が消えて全部見出しに。
 */
function affiros_psplit_promote_list_item_headings($html, $settings) {
    $max_chars = max(20, min(200, intval($settings['heading_max_chars'] ?? 60)));
    $level = in_array($settings['heading_level'] ?? '4', ['2', '3', '4', '5'], true)
        ? $settings['heading_level']
        : '4';
    $patterns_raw = $settings['heading_patterns'] ?? '';
    $patterns = array_filter(array_map('trim', preg_split('/\r?\n/', $patterns_raw)));
    if (empty($patterns)) return $html;

    $regex_list = [];
    foreach ($patterns as $p) {
        if (strpos($p, '^') !== 0) $p = '^\s*' . $p;
        $regex_list[] = '/' . str_replace('/', '\/', $p) . '/u';
    }

    return preg_replace_callback(
        '/(?:<!--\s*wp:list[^>]*-->\s*)?<(ul|ol)\b([^>]*)>([\s\S]*?)<\/\1>(?:\s*<!--\s*\/wp:list\s*-->)?/i',
        function ($m) use ($max_chars, $level, $regex_list) {
            $list_tag = $m[1];
            $list_attr = $m[2];
            $list_inner = $m[3];

            // 各 <li> を順番に取り出す（ネストは雑に対応 — 通常記事ではネストは少ない）
            if (!preg_match_all('/<li\b[^>]*>([\s\S]*?)<\/li>/i', $list_inner, $li_matches, PREG_OFFSET_CAPTURE)) {
                return $m[0];
            }

            // 連続する非昇格 li と「昇格 li」の交互列を作る
            $segments = []; // each: ['type' => 'list'|'heading', 'content' => string]
            $current_list_items = [];
            $any_promoted = false;

            foreach ($li_matches[0] as $idx => $li_full) {
                $li_html = $li_full[0];
                $li_inner = $li_matches[1][$idx][0];

                // 子に block 要素含む li は昇格対象外
                if (preg_match('/<(img|table|ul|ol|h[1-6]|div|figure|iframe|hr|blockquote)\b/i', $li_inner)) {
                    $current_list_items[] = $li_html;
                    continue;
                }
                $plain = trim(preg_replace('/<[^>]+>/u', '', $li_inner));
                if ($plain === '' || mb_strlen($plain) > $max_chars) {
                    $current_list_items[] = $li_html;
                    continue;
                }
                if (preg_match('/[。.!?！？]\s*$/u', $plain)) {
                    $current_list_items[] = $li_html;
                    continue;
                }
                $matched = false;
                foreach ($regex_list as $rx) {
                    if (preg_match($rx, $plain)) { $matched = true; break; }
                }
                if (!$matched) {
                    $current_list_items[] = $li_html;
                    continue;
                }

                // 昇格対象: 直前までの list_items を flush して heading を挿入
                if (!empty($current_list_items)) {
                    $segments[] = ['type' => 'list', 'content' => implode('', $current_list_items)];
                    $current_list_items = [];
                }
                $segments[] = ['type' => 'heading', 'content' => trim($li_inner)];
                $any_promoted = true;
            }
            if (!empty($current_list_items)) {
                $segments[] = ['type' => 'list', 'content' => implode('', $current_list_items)];
            }

            // 昇格対象が1つも無ければ元のリストに何も変えずに戻す
            if (!$any_promoted) return $m[0];

            // 再構築
            $level_attr = $level === '2' ? '' : ' {"level":' . intval($level) . '}';
            $list_block_attr = ($list_tag === 'ol') ? ' {"ordered":true}' : '';
            $out = '';
            foreach ($segments as $seg) {
                if ($seg['type'] === 'heading') {
                    $out .= "\n<!-- wp:heading{$level_attr} -->\n"
                          . "<h{$level} class=\"wp-block-heading\">" . $seg['content'] . "</h{$level}>\n"
                          . "<!-- /wp:heading -->\n";
                } else {
                    $out .= "\n<!-- wp:list{$list_block_attr} -->\n"
                          . "<{$list_tag}{$list_attr}>" . $seg['content'] . "</{$list_tag}>\n"
                          . "<!-- /wp:list -->\n";
                }
            }
            return $out;
        },
        $html
    );
}

/**
 * <li> 内が「<strong>ラベル</strong>：長い説明文」の構造になっているとき、
 * 各 <li> を「ラベル → 見出し / 説明文 → 段落」に分割して、リストをほどく。
 *
 * 見出しレベルは context-aware: そのリストより手前にある最後の h2-h6 を「親」と
 * みなし、親レベル + 1 を子見出しレベルに使う。親が h3 なら子は h4。
 *
 * 例:
 *   <h3>素材の種類</h3>
 *   <ul>
 *     <li><strong>ターポリン</strong>：厚みがあり耐久性・防水性が高い。重量はやや増えます...</li>
 *     <li><strong>ナイロン</strong>：軽量で扱いやすい。バックパック型やタウンユース...</li>
 *   </ul>
 *   →
 *   <h3>素材の種類</h3>
 *   <h4>ターポリン</h4>
 *   <p>厚みがあり耐久性・防水性が高い。重量はやや増えます...</p>
 *   <h4>ナイロン</h4>
 *   <p>軽量で扱いやすい。バックパック型やタウンユース...</p>
 *
 * パターンに一致しない <li> はそのままリストに残す（混在対応）。
 */
function affiros_psplit_split_strong_label_list($html, $settings) {
    if (!$html) return $html;
    $min_content = max(10, intval($settings['split_min_content_chars'] ?? 25));

    // 全 h タグの位置とレベルを記録（親見出しレベル特定用）
    preg_match_all('/<h([1-6])\b[^>]*>/i', $html, $h_matches, PREG_OFFSET_CAPTURE);
    $heading_positions = [];
    foreach ($h_matches[0] as $i => $m) {
        $heading_positions[] = ['pos' => $m[1], 'level' => intval($h_matches[1][$i][0])];
    }

    // 全 <ul>/<ol> の位置を取得（wp:list コメントも含めて1ブロック）
    $list_pattern = '/(?:<!--\s*wp:list[^>]*-->\s*)?<(ul|ol)\b([^>]*)>([\s\S]*?)<\/\1>(?:\s*<!--\s*\/wp:list\s*-->)?/i';
    if (!preg_match_all($list_pattern, $html, $list_matches, PREG_OFFSET_CAPTURE)) {
        return $html;
    }

    // 置換は末尾から（オフセットを保持するため）
    $replacements = [];
    for ($i = 0; $i < count($list_matches[0]); $i++) {
        $list_full = $list_matches[0][$i][0];
        $list_pos  = $list_matches[0][$i][1];
        $list_tag  = $list_matches[1][$i][0];
        $list_attr = $list_matches[2][$i][0];
        $list_inner = $list_matches[3][$i][0];

        // 親見出しレベルを特定（このリストの直前にある最後の h タグ）
        $parent_level = 2;
        foreach ($heading_positions as $h) {
            if ($h['pos'] < $list_pos) {
                $parent_level = $h['level'];
            } else {
                break;
            }
        }
        $child_level = min(6, $parent_level + 1);

        $result = affiros_psplit_process_strong_list_inner(
            $list_inner, $list_tag, $list_attr, $child_level, $min_content
        );
        if ($result === null) continue;

        $replacements[] = [
            'start'       => $list_pos,
            'end'         => $list_pos + strlen($list_full),
            'replacement' => $result,
        ];
    }

    // 末尾から置換
    usort($replacements, function ($a, $b) { return $b['start'] - $a['start']; });
    foreach ($replacements as $r) {
        $html = substr($html, 0, $r['start']) . $r['replacement'] . substr($html, $r['end']);
    }
    return $html;
}

/**
 * リスト1つの中身を処理して、strong+コロンのある <li> を 見出し+段落 に分割する。
 * パターン無し <li> はそのまま現リストに残す。
 *
 * @return string|null 変換結果。何も変換しなかった場合は null（呼び元はスキップ）
 */
function affiros_psplit_process_strong_list_inner($list_inner, $list_tag, $list_attr, $child_level, $min_content) {
    if (!preg_match_all('/<li\b([^>]*)>([\s\S]*?)<\/li>/i', $list_inner, $li_matches)) return null;

    // <strong>ラベル</strong>：内容 のパターン
    // - <strong>～</strong> の中身は短いラベル
    // - 直後に全角／半角コロン
    // - その後に長い内容（min_content 文字超）
    $strong_pattern = '/^\s*<strong[^>]*>([^<]+?)<\/strong>\s*[：:]\s*([\s\S]+)$/iu';

    $segments = []; // ['type' => 'list'|'heading', ...]
    $current_items = [];
    $any_converted = false;

    foreach ($li_matches[2] as $idx => $li_inner) {
        $li_attr = $li_matches[1][$idx];
        $matched = false;

        if (preg_match($strong_pattern, $li_inner, $sm)) {
            $label = trim(strip_tags($sm[1]));
            $content_html = trim($sm[2]);
            $content_plain = trim(preg_replace('/<[^>]+>/u', '', $content_html));

            // v1.1.3: stats 側は >= min_content で数えるので split 側も揃える。
            //         off-by-one で境界の1件が「検出はする、分割しない」
            //         状態になっていた。
            if ($label !== '' && mb_strlen($content_plain) >= $min_content) {
                // 変換対象 → 直前までの list_items を flush
                if (!empty($current_items)) {
                    $segments[] = ['type' => 'list', 'items' => $current_items];
                    $current_items = [];
                }
                $segments[] = ['type' => 'split', 'label' => $label, 'content' => $content_html];
                $any_converted = true;
                $matched = true;
            }
        }

        if (!$matched) {
            $current_items[] = ['attr' => $li_attr, 'inner' => $li_inner];
        }
    }

    if (!empty($current_items)) {
        $segments[] = ['type' => 'list', 'items' => $current_items];
    }

    if (!$any_converted) return null;

    // 再構築
    $level_attr = $child_level === 2 ? '' : ' {"level":' . $child_level . '}';
    $list_block_attr = ($list_tag === 'ol') ? ' {"ordered":true}' : '';
    $out = '';
    foreach ($segments as $seg) {
        if ($seg['type'] === 'split') {
            $out .= "\n<!-- wp:heading{$level_attr} -->\n"
                  . "<h{$child_level} class=\"wp-block-heading\">" . $seg['label'] . "</h{$child_level}>\n"
                  . "<!-- /wp:heading -->\n"
                  . "<!-- wp:paragraph -->\n"
                  . "<p>" . $seg['content'] . "</p>\n"
                  . "<!-- /wp:paragraph -->\n";
        } else {
            $items_html = '';
            foreach ($seg['items'] as $item) {
                $items_html .= '<li' . $item['attr'] . '>' . $item['inner'] . '</li>';
            }
            $out .= "\n<!-- wp:list{$list_block_attr} -->\n"
                  . "<{$list_tag}{$list_attr}>" . $items_html . "</{$list_tag}>\n"
                  . "<!-- /wp:list -->\n";
        }
    }
    return $out;
}

/**
 * v1.1.6: 句点 (。！？) ごとに <p> を強制分割する「縦読みモード」
 *
 * 通常の分割ロジック (min_paragraph_chars / min_sentence_chars で蓄積) を
 * 無視して、全ての <p> を機械的に句点で切る。1文=1段落。
 *
 * スキップ条件:
 * - 画像・表・リスト・見出しを含む <p> (壊れるため)
 * - 句点が1つ以下 (分割不要)
 *
 * タグをまたいだ分割は避けるためプレースホルダー方式で
 * 「タグを一時退避 → plain text で句点分割 → タグを戻す」を採用。
 */
function affiros_psplit_split_every_period($html) {
    return preg_replace_callback(
        '/<p\b([^>]*)>([\s\S]*?)<\/p>/i',
        function ($m) {
            $attr = $m[1];
            $inner = $m[2];

            // 特殊要素を含む段落はスキップ
            if (preg_match('/<(img|table|ul|ol|div|figure|iframe|hr|blockquote|h[1-6])\b/i', $inner)) {
                return $m[0];
            }

            // タグをプレースホルダーに退避 (タグの中身を句点で切らないため)
            $tags = [];
            $plain_template = preg_replace_callback(
                '/<[^>]+>/u',
                function ($tag) use (&$tags) {
                    $tags[] = $tag[0];
                    return "\x02TAG" . (count($tags) - 1) . "\x03";
                },
                $inner
            );

            // 句点 (。！？!?) 直後で分割
            $pieces = preg_split('/(?<=[。！？!?])/u', $plain_template);
            if (!$pieces || count($pieces) < 2) return $m[0];

            $out = '';
            $emitted = 0;
            foreach ($pieces as $piece) {
                // タグを戻す
                $piece = preg_replace_callback(
                    '/\x02TAG(\d+)\x03/u',
                    function ($t) use ($tags) { return $tags[intval($t[1])] ?? ''; },
                    $piece
                );
                $piece = trim($piece);
                if ($piece === '') continue;
                // plain text が空なら (タグだけ) スキップ
                $plain = trim(preg_replace('/<[^>]+>/u', '', $piece));
                if ($plain === '') continue;
                $out .= '<p' . $attr . '>' . $piece . '</p>';
                $emitted++;
            }
            return $emitted > 0 ? $out : $m[0];
        },
        $html
    );
}

function affiros_psplit_add_heading_spacing($html) {
    // H2/H3 ブロックの直前に空 <p> が無ければ入れる
    return preg_replace_callback(
        '/(?<!<p><\/p>\s)(<!--\s*wp:heading\s*-->)/i',
        function ($m) {
            return "<!-- wp:paragraph -->\n<p></p>\n<!-- /wp:paragraph -->\n\n" . $m[1];
        },
        $html
    );
}

function affiros_psplit_add_media_spacing($html) {
    // <figure> や <table> ブロックの前後に空 <p> 段落を入れる（簡易版）
    $html = preg_replace_callback(
        '/(<!--\s*wp:(?:image|table|gallery)[^>]*-->)/i',
        function ($m) {
            return "<!-- wp:paragraph -->\n<p></p>\n<!-- /wp:paragraph -->\n\n" . $m[1];
        },
        $html
    );
    return $html;
}

/**
 * before/after の plain-text 段落分布を返す（プレビュー用に簡易統計）
 * 昇格候補（見出しっぽい段落）の数も返す。
 */
function affiros_psplit_stats($html, $settings = null) {
    if ($settings === null) $settings = affiros_psplit_get_settings();

    // <p> の統計取得（over_200 用）
    $lens = [];
    if (preg_match_all('/<p\b[^>]*>([\s\S]*?)<\/p>/i', $html, $m)) {
        foreach ($m[1] as $inner) {
            $plain = trim(preg_replace('/<[^>]+>/u', '', $inner));
            if ($plain === '') continue;
            $lens[] = mb_strlen($plain);
        }
    }

    // v1.1.2: strong+コロンの <li> パターンを別カウンタで数える。
    // 従来は heading_candidates として数えていたが、
    // 「<li> 全体の plain text が heading_max_chars(=60) を超える場合 SKIP」
    // されるため、実際の <li><strong>ラベル</strong>：長文</li>（合計 60字超）
    // が全部カウント漏れしていた。整形対象0件と誤表示される真因。
    $strong_label_candidates = 0;
    if (($settings['split_strong_label_list'] ?? 'yes') === 'yes') {
        $min_content = max(10, intval($settings['split_min_content_chars'] ?? 25));
        if (preg_match_all('/<li\b[^>]*>([\s\S]*?)<\/li>/i', $html, $li_m)) {
            $strong_pattern = '/^\s*<strong[^>]*>([^<]+?)<\/strong>\s*[：:]\s*([\s\S]+)$/iu';
            foreach ($li_m[1] as $inner) {
                if (!preg_match($strong_pattern, $inner, $sm)) continue;
                // 中身（コロン後の長文）の plain text 長を測る
                $content_plain = trim(preg_replace('/<[^>]+>/u', '', $sm[2]));
                if (mb_strlen($content_plain) < $min_content) continue;
                $strong_label_candidates++;
            }
        }
    }

    // 見出し昇格候補のカウント（既存ロジック）
    $heading_candidates = 0;
    if (($settings['promote_headings'] ?? 'yes') === 'yes') {
        $max_chars = intval($settings['heading_max_chars'] ?? 60);
        $patterns = array_filter(array_map('trim', preg_split('/\r?\n/', $settings['heading_patterns'] ?? '')));
        $regex_list = [];
        foreach ($patterns as $p) {
            if (strpos($p, '^') !== 0) $p = '^\s*' . $p;
            $regex_list[] = '/' . str_replace('/', '\/', $p) . '/u';
        }
        if (isset($m[1])) {
            foreach ($m[1] as $inner) {
                if (preg_match('/<(img|table|ul|ol|h[1-6]|div|figure|iframe|hr|blockquote)\b/i', $inner)) continue;
                $plain = trim(preg_replace('/<[^>]+>/u', '', $inner));
                if ($plain === '' || mb_strlen($plain) > $max_chars) continue;
                if (preg_match('/[。.!?！？]\s*$/u', $plain)) continue;
                foreach ($regex_list as $rx) {
                    if (preg_match($rx, $plain)) { $heading_candidates++; break; }
                }
            }
        }
        // <li> も昇格候補としてカウント
        if (preg_match_all('/<li\b[^>]*>([\s\S]*?)<\/li>/i', $html, $li_m2)) {
            foreach ($li_m2[1] as $inner) {
                if (preg_match('/<(img|table|ul|ol|h[1-6]|div|figure|iframe|hr|blockquote)\b/i', $inner)) continue;
                $plain = trim(preg_replace('/<[^>]+>/u', '', $inner));
                if ($plain === '' || mb_strlen($plain) > $max_chars) continue;
                if (preg_match('/[。.!?！？]\s*$/u', $plain)) continue;
                foreach ($regex_list as $rx) {
                    if (preg_match($rx, $plain)) { $heading_candidates++; break; }
                }
            }
        }
    }

    if (empty($lens)) {
        return [
            'count'              => 0,
            'avg'                => 0,
            'max'                => 0,
            'over_200'           => 0,
            'heading_candidates' => $heading_candidates,
            'strong_label_candidates' => $strong_label_candidates,
        ];
    }

    return [
        'count'              => count($lens),
        'avg'                => intval(array_sum($lens) / count($lens)),
        'max'                => max($lens),
        'over_200'           => count(array_filter($lens, function ($l) { return $l > 200; })),
        'heading_candidates' => $heading_candidates,
        'strong_label_candidates' => $strong_label_candidates,
    ];
}

// =============================================================================
// 保存時 hook（オプション）
// =============================================================================

add_action('save_post_post', 'affiros_psplit_on_save', 20, 3);
function affiros_psplit_on_save($post_id, $post, $update) {
    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
    if (wp_is_post_revision($post_id) || wp_is_post_autosave($post_id)) return;

    $settings = affiros_psplit_get_settings();
    if (($settings['auto_on_save'] ?? 'no') !== 'yes') return;

    $allowed = explode(',', $settings['target_statuses'] ?? 'publish,future,draft');
    if (!in_array($post->post_status, $allowed, true)) return;

    // 再帰防止フラグ
    if (get_transient('affiros_psplit_skip_' . $post_id)) return;

    $new_content = affiros_psplit_process_content($post->post_content, $settings);
    if ($new_content !== $post->post_content) {
        set_transient('affiros_psplit_skip_' . $post_id, 1, 30);
        remove_action('save_post_post', 'affiros_psplit_on_save', 20);
        wp_update_post(['ID' => $post_id, 'post_content' => $new_content]);
        add_action('save_post_post', 'affiros_psplit_on_save', 20, 3);
        delete_transient('affiros_psplit_skip_' . $post_id);
    }
}

// =============================================================================
// 管理画面: 設定登録（メニューは affiros-rewrite の「📝 段落整形」タブに統合）
// =============================================================================

add_action('admin_init', function () {
    register_setting('affiros_psplit_group', AFFIROS_PSPLIT_OPTION_KEY, [
        'sanitize_callback' => 'affiros_psplit_sanitize',
    ]);
});

function affiros_psplit_sanitize($input) {
    $existing = get_option(AFFIROS_PSPLIT_OPTION_KEY, []);
    $output = is_array($existing) ? $existing : [];
    $output['min_paragraph_chars']   = max(80, min(1000, intval($input['min_paragraph_chars'] ?? 200)));
    $output['min_sentence_chars']    = max(20, min(500, intval($input['min_sentence_chars'] ?? 60)));
    $output['force_split_chars']     = max(120, min(2000, intval($input['force_split_chars'] ?? 300)));
    $output['connectors']            = sanitize_textarea_field($input['connectors'] ?? '');
    $output['auto_on_save']          = ($input['auto_on_save'] ?? 'no') === 'yes' ? 'yes' : 'no';
    $output['add_heading_spacing']   = ($input['add_heading_spacing'] ?? 'yes') === 'yes' ? 'yes' : 'no';
    $output['add_media_spacing']     = ($input['add_media_spacing'] ?? 'yes') === 'yes' ? 'yes' : 'no';
    $output['normalize_punctuation'] = ($input['normalize_punctuation'] ?? 'yes') === 'yes' ? 'yes' : 'no';
    $output['promote_headings']        = ($input['promote_headings'] ?? 'yes') === 'yes' ? 'yes' : 'no';
    $output['heading_level']           = in_array($input['heading_level'] ?? '4', ['2', '3', '4', '5'], true) ? $input['heading_level'] : '4';
    $output['heading_patterns']        = sanitize_textarea_field($input['heading_patterns'] ?? '');
    $output['heading_max_chars']       = max(20, min(200, intval($input['heading_max_chars'] ?? 60)));
    $output['split_strong_label_list'] = ($input['split_strong_label_list'] ?? 'yes') === 'yes' ? 'yes' : 'no';
    $output['split_min_content_chars'] = max(10, min(500, intval($input['split_min_content_chars'] ?? 25)));
    $output['split_every_period']      = ($input['split_every_period'] ?? 'no') === 'yes' ? 'yes' : 'no';
    $output['target_statuses']         = sanitize_text_field($input['target_statuses'] ?? 'publish,future,draft');
    return $output;
}

add_action('admin_enqueue_scripts', function ($hook) {
    // affiros-rewrite の管理画面全般で AffirosPsplit を利用可能にする
    // （タブ「📝 段落整形」および投稿編集画面のメタボックスで使用）
    if (strpos($hook, 'affiros-rewrite') === false && $hook !== 'post.php' && $hook !== 'post-new.php') return;
    wp_localize_script('jquery', 'AffirosPsplit', [
        'ajaxUrl' => admin_url('admin-ajax.php'),
        'nonce'   => wp_create_nonce('affiros_psplit_nonce'),
    ]);
});

// =============================================================================
// 管理画面: メインページ
// =============================================================================

/**
 * v0.5.0 統合版: タブ埋め込み用にラッパーの div.wrap と h1 を除去した本体。
 * affiros-rewrite の「📝 段落整形」タブから呼び出す。
 */
function affiros_psplit_render_tab_body() {
    if (!current_user_can('manage_options')) return;
    $settings = affiros_psplit_get_settings();
    $defaults = affiros_psplit_default_settings();
    ?>
    <div class="affiros-psplit-tab-body">
        <p style="font-size:13px;line-height:1.7">
            長すぎる段落を句点・接続詞・最大文字数で機械的に分割し、視覚的に読みやすくします。<br>
            画像・表・リストを含む段落は壊さないようスキップ。WP リビジョンが自動保存されるので<strong>適用後でも元に戻せます</strong>。
        </p>

        <h2>① 設定</h2>
        <form method="post" action="options.php">
            <?php settings_fields('affiros_psplit_group'); ?>
            <table class="form-table">
                <tr>
                    <th>段落の最小文字数（分割対象判定）</th>
                    <td>
                        <input type="number" name="<?php echo AFFIROS_PSPLIT_OPTION_KEY; ?>[min_paragraph_chars]" value="<?php echo esc_attr($settings['min_paragraph_chars']); ?>" min="80" max="1000" style="width:80px"> 字以上を「長い」と判定して分割対象に
                        <p class="description">既定 200。これ未満の段落は触らない。</p>
                    </td>
                </tr>
                <tr>
                    <th>1文の最小文字数（分割粒度）</th>
                    <td>
                        <input type="number" name="<?php echo AFFIROS_PSPLIT_OPTION_KEY; ?>[min_sentence_chars]" value="<?php echo esc_attr($settings['min_sentence_chars']); ?>" min="20" max="500" style="width:80px"> 字以上で1段落を区切る
                        <p class="description">既定 60。これ未満は前の文に結合する。細切れになりすぎないよう守る値。</p>
                    </td>
                </tr>
                <tr>
                    <th>強制分割しきい値</th>
                    <td>
                        <input type="number" name="<?php echo AFFIROS_PSPLIT_OPTION_KEY; ?>[force_split_chars]" value="<?php echo esc_attr($settings['force_split_chars']); ?>" min="120" max="2000" style="width:80px"> 字超は読点でも強制分割
                        <p class="description">既定 300。句点も接続詞も無い超長文を救う最終手段。</p>
                    </td>
                </tr>
                <tr>
                    <th>接続詞リスト（前で改行）</th>
                    <td>
                        <textarea name="<?php echo AFFIROS_PSPLIT_OPTION_KEY; ?>[connectors]" rows="8" style="width:400px;font-family:monospace"><?php echo esc_textarea($settings['connectors']); ?></textarea>
                        <p class="description">1行1個。これらの直前で改行する。読点までセットで書く（例: <code>また、</code>）。</p>
                    </td>
                </tr>
                <tr>
                    <th>句読点の正規化</th>
                    <td><label><input type="checkbox" name="<?php echo AFFIROS_PSPLIT_OPTION_KEY; ?>[normalize_punctuation]" value="yes" <?php checked($settings['normalize_punctuation'], 'yes'); ?>> 「。。」→「。」のような連続句読点を正規化</label></td>
                </tr>
                <tr>
                    <th>見出し前後の余白</th>
                    <td><label><input type="checkbox" name="<?php echo AFFIROS_PSPLIT_OPTION_KEY; ?>[add_heading_spacing]" value="yes" <?php checked($settings['add_heading_spacing'], 'yes'); ?>> H2/H3 の直前に空段落を入れて視覚的余白を確保</label></td>
                </tr>
                <tr>
                    <th>画像・表前後の余白</th>
                    <td><label><input type="checkbox" name="<?php echo AFFIROS_PSPLIT_OPTION_KEY; ?>[add_media_spacing]" value="yes" <?php checked($settings['add_media_spacing'], 'yes'); ?>> 画像・表・ギャラリーの前に空段落を入れる</label></td>
                </tr>
                <tr>
                    <th>見出しっぽい段落を昇格</th>
                    <td>
                        <label><input type="checkbox" name="<?php echo AFFIROS_PSPLIT_OPTION_KEY; ?>[promote_headings]" value="yes" <?php checked($settings['promote_headings'] ?? 'yes', 'yes'); ?>> 「ポイント3：xxx」「ステップ1：xxx」「【xxx】yyy」等を見出しに変換</label>
                        <p class="description">単に太字や囲みボックスで表示されてる「ポイントN」「ステップN」などを正しい h タグにします。</p>
                    </td>
                </tr>
                <tr>
                    <th>昇格先の見出しレベル</th>
                    <td>
                        <select name="<?php echo AFFIROS_PSPLIT_OPTION_KEY; ?>[heading_level]">
                            <option value="3" <?php selected($settings['heading_level'] ?? '4', '3'); ?>>H3</option>
                            <option value="4" <?php selected($settings['heading_level'] ?? '4', '4'); ?>>H4（推奨）</option>
                            <option value="5" <?php selected($settings['heading_level'] ?? '4', '5'); ?>>H5</option>
                        </select>
                        <p class="description">H2 配下のサブ見出しとして使うので H4 推奨。</p>
                    </td>
                </tr>
                <tr>
                    <th>段落の最大文字数（見出し候補判定）</th>
                    <td>
                        <input type="number" name="<?php echo AFFIROS_PSPLIT_OPTION_KEY; ?>[heading_max_chars]" value="<?php echo esc_attr($settings['heading_max_chars'] ?? 60); ?>" min="20" max="200" style="width:80px"> 字以下
                        <p class="description">この文字数を超える段落は「文章」として昇格対象外。既定 60。</p>
                    </td>
                </tr>
                <tr>
                    <th>見出しパターン（正規表現）</th>
                    <td>
                        <textarea name="<?php echo AFFIROS_PSPLIT_OPTION_KEY; ?>[heading_patterns]" rows="10" style="width:480px;font-family:monospace"><?php echo esc_textarea($settings['heading_patterns'] ?? ''); ?></textarea>
                        <p class="description">1行1パターン。各パターンに一致する段落を見出しに昇格。<code>\\d+</code> で数字、<code>[：:]</code> で全角/半角コロン。`^` 始まりでなければ自動で行頭マッチを付与。<br>例: <code>ポイント\\d+[：:]</code> → 「ポイント3：形状と...」にマッチ。<br>判定: パターンマッチ AND 文字数閾値以下 AND 末尾「。」なし。</p>
                    </td>
                </tr>
                <tr>
                    <th>strong+コロンの &lt;li&gt; を見出し+段落に分割</th>
                    <td>
                        <label><input type="checkbox" name="<?php echo AFFIROS_PSPLIT_OPTION_KEY; ?>[split_strong_label_list]" value="yes" <?php checked($settings['split_strong_label_list'] ?? 'yes', 'yes'); ?>> 各 <code>&lt;li&gt;&lt;strong&gt;ラベル&lt;/strong&gt;：長い説明文&lt;/li&gt;</code> を「見出し + 段落」に分解</label>
                        <p class="description">
                            読みづらい「太字ラベル＋コロン＋長文」のリスト形式を、ラベルを見出し（親見出しレベル+1）に格上げして、説明文を段落にする。<br>
                            例: <code>&lt;h3&gt;素材&lt;/h3&gt;</code> 配下のリストなら <code>&lt;h4&gt;</code> に昇格、<code>&lt;h2&gt;</code> 配下なら <code>&lt;h3&gt;</code> に昇格。
                        </p>
                    </td>
                </tr>
                <tr>
                    <th>分割対象とする説明文の最小文字数</th>
                    <td>
                        <input type="number" name="<?php echo AFFIROS_PSPLIT_OPTION_KEY; ?>[split_min_content_chars]" value="<?php echo esc_attr($settings['split_min_content_chars'] ?? 25); ?>" min="10" max="500" style="width:80px"> 字超
                        <p class="description">コロン直後の説明文がこの文字数を超える時だけ分割対象。既定 25 字。短い「<code>長所：軽い</code>」みたいなのは触らない。</p>
                    </td>
                </tr>
                <tr>
                    <th>「。」ごとに1段落 (縦読みモード)</th>
                    <td>
                        <label><input type="checkbox" name="<?php echo AFFIROS_PSPLIT_OPTION_KEY; ?>[split_every_period]" value="yes" <?php checked($settings['split_every_period'] ?? 'no', 'yes'); ?>> <strong>全ての段落を句点 (。！？) ごとに1文=1段落に強制分割する</strong></label>
                        <p class="description">
                            ONにすると <code>min_paragraph_chars</code> や <code>min_sentence_chars</code> の設定を無視して、<strong>すべての <code>&lt;p&gt;</code> を句点で機械分割</strong>します。<br>
                            スマホでの読みやすさ重視・縦にスクロールする印象を強めたい記事向け。<br>
                            画像・表・リスト・見出しを含む段落はスキップ。
                        </p>
                    </td>
                </tr>
                <tr>
                    <th>保存時に自動整形（hook）</th>
                    <td>
                        <label><input type="checkbox" name="<?php echo AFFIROS_PSPLIT_OPTION_KEY; ?>[auto_on_save]" value="yes" <?php checked($settings['auto_on_save'], 'yes'); ?>> 投稿保存時に自動で整形する</label>
                        <p class="description">⚠️ ONにすると今後の保存全てに効くので、まず手動一括で挙動確認してからONを推奨。</p>
                    </td>
                </tr>
                <tr>
                    <th>対象ステータス</th>
                    <td>
                        <input type="text" name="<?php echo AFFIROS_PSPLIT_OPTION_KEY; ?>[target_statuses]" value="<?php echo esc_attr($settings['target_statuses']); ?>" style="width:280px">
                        <p class="description">既定 <code>publish,future,draft</code>。カンマ区切り。</p>
                    </td>
                </tr>
            </table>
            <?php submit_button('設定を保存'); ?>
        </form>

        <hr style="margin:32px 0">

        <h2>② 一括整形</h2>
        <p style="font-size:13px;line-height:1.7">
            既存の全記事をスキャンし、整形対象（200字超の段落を含む記事）をリスト表示。
            プレビューで before/after を確認してから個別 or 一括で適用できます。
        </p>

        <div style="margin:16px 0">
            <button type="button" id="aps-scan-btn" class="button button-primary">🔍 全記事スキャン</button>
            <span id="aps-scan-status" style="margin-left:12px;color:#666;font-size:13px"></span>
        </div>

        <div id="aps-result" style="display:none">
            <div style="margin:0 0 12px">
                <button type="button" id="aps-apply-all-btn" class="button button-primary">✨ 全件に適用</button>
                <span id="aps-apply-status" style="margin-left:12px;font-size:13px"></span>
            </div>
            <table class="wp-list-table widefat striped">
                <thead>
                    <tr>
                        <th style="width:60px">ID</th>
                        <th>タイトル</th>
                        <th style="width:90px">段落数</th>
                        <th style="width:90px">最大字数</th>
                        <th style="width:120px">200字超</th>
                        <th style="width:140px">見出し昇格候補</th>
                        <th style="width:140px" title="&lt;li&gt;&lt;strong&gt;ラベル&lt;/strong&gt;：長文 パターン">strongラベル</th>
                        <th style="width:220px">アクション</th>
                    </tr>
                </thead>
                <tbody id="aps-result-tbody"></tbody>
            </table>
        </div>
    </div>

    <script>
    (function ($) {
        const ajaxUrl = (window.AffirosPsplit && AffirosPsplit.ajaxUrl) || ajaxurl;
        const nonce   = (window.AffirosPsplit && AffirosPsplit.nonce) || '';
        let posts = [];

        $('#aps-scan-btn').on('click', scan);
        $('#aps-apply-all-btn').on('click', applyAll);

        async function scan() {
            $('#aps-scan-btn').prop('disabled', true);
            $('#aps-result').hide();
            $('#aps-result-tbody').empty();
            $('#aps-scan-status').text('スキャン中...');
            try {
                // v0.5.12: action を URL クエリにも入れる（POST body から
                // action を消すキャッシュ/セキュリティプラグイン対策。
                // 実測 karada-thermo.com で「status=400 responseText=0」発生）
                const res = await $.post(ajaxUrl + (ajaxUrl.indexOf('?') === -1 ? '?' : '&') + 'action=affiros_psplit_scan', {
                    action: 'affiros_psplit_scan',
                    nonce: nonce,
                });
                if (!res || !res.success) {
                    alert('スキャン失敗: ' + (res && res.data ? res.data : ''));
                    return;
                }
                posts = res.data.posts || [];
                $('#aps-scan-status').text(`スキャン完了: ${res.data.scanned}件チェック / 整形対象 ${posts.length}件`);
                render();
                if (posts.length) $('#aps-result').show();
            } catch (e) {
                alert('通信エラー\nstatus=' + (e && e.status) + ' statusText=' + (e && e.statusText) + '\nresponseText=' + ((e && e.responseText) ? String(e.responseText).slice(0, 500) : '(empty)'));
                if (window.console) console.error('[psplit scan] AJAX failed:', e);
            } finally {
                $('#aps-scan-btn').prop('disabled', false);
            }
        }

        function render() {
            const tbody = $('#aps-result-tbody').empty();
            posts.forEach(p => {
                const editUrl = `${location.origin}/wp-admin/post.php?post=${p.id}&action=edit`;
                const hc = p.heading_candidates || 0;
                const slc = p.strong_label_candidates || 0;
                tbody.append(`
                    <tr data-id="${p.id}">
                        <td>${p.id}</td>
                        <td><a href="${editUrl}" target="_blank">${esc(p.title)}</a></td>
                        <td>${p.count}</td>
                        <td>${p.max}字</td>
                        <td style="color:${p.over_200 > 0 ? '#dc2626' : '#6b7280'};font-weight:600">${p.over_200}件</td>
                        <td style="color:${hc > 0 ? '#d97706' : '#6b7280'};font-weight:600">${hc}件</td>
                        <td style="color:${slc > 0 ? '#2563eb' : '#6b7280'};font-weight:600">${slc}件</td>
                        <td>
                            <button type="button" class="button button-small aps-preview" data-id="${p.id}">👁 プレビュー</button>
                            <button type="button" class="button button-primary button-small aps-apply" data-id="${p.id}">✨ 適用</button>
                        </td>
                    </tr>
                `);
            });
            tbody.find('.aps-apply').on('click', function () {
                const id = $(this).data('id');
                applyOne(id, $(this));
            });
            tbody.find('.aps-preview').on('click', function () {
                const id = $(this).data('id');
                previewOne(id);
            });
        }

        async function previewOne(id) {
            try {
                // v0.5.12: action は URL クエリにも入れる（POST body 加工対策）
                const res = await $.post(ajaxUrl + (ajaxUrl.indexOf('?') === -1 ? '?' : '&') + 'action=affiros_psplit_preview', {
                    action: 'affiros_psplit_preview',
                    nonce: nonce,
                    post_id: id,
                });
                if (!res || !res.success) { alert('失敗'); return; }
                const w = window.open('', '_blank', 'width=1100,height=800');
                w.document.write(`
                    <html><head><title>プレビュー #${id}</title>
                    <style>body{font-family:sans-serif;font-size:14px;line-height:1.8;padding:20px;}
                    .grid{display:grid;grid-template-columns:1fr 1fr;gap:20px}
                    .col h2{margin-top:0;font-size:14px;background:#eee;padding:8px}
                    .col{border:1px solid #ddd;padding:12px;overflow:auto;max-height:90vh}
                    .col.after{background:#f0fdf4}
                    p{margin:0 0 14px;padding:6px;background:#fff;border-left:2px solid #d1d5db}
                    .col.after p{border-left-color:#16a34a}
                    </style></head><body>
                    <h1>段落整形プレビュー #${id}</h1>
                    <div class="grid">
                        <div class="col"><h2>Before</h2>${res.data.before_html}</div>
                        <div class="col after"><h2>After</h2>${res.data.after_html}</div>
                    </div>
                    </body></html>
                `);
                w.document.close();
            } catch (e) {
                alert('通信エラー\nstatus=' + (e && e.status) + ' statusText=' + (e && e.statusText) + '\nresponseText=' + ((e && e.responseText) ? String(e.responseText).slice(0, 500) : '(empty)'));
                if (window.console) console.error('[psplit ajax] failed:', e);
            }
        }

        // v1.1.3: applyOne は {ok, changed, deltaTotal, remainingTotal} を返す
        async function applyOne(id, btn) {
            if (btn) btn.prop('disabled', true).text('適用中...');
            try {
                // v0.5.12: action は URL クエリにも入れる（POST body 加工対策）
                const res = await $.post(ajaxUrl + (ajaxUrl.indexOf('?') === -1 ? '?' : '&') + 'action=affiros_psplit_apply', {
                    action: 'affiros_psplit_apply',
                    nonce: nonce,
                    post_id: id,
                });
                if (res && res.success) {
                    const data = res.data || {};
                    const changed = !!data.changed;
                    const delta = data.delta || {};
                    const deltaTotal = (delta.over_200_resolved || 0) + (delta.heading_promoted || 0) + (delta.strong_label_split || 0);
                    const remainingTotal = data.remaining_total || 0;
                    if (btn) {
                        let label;
                        if (deltaTotal > 0) {
                            label = `<span style="color:#16a34a;font-weight:600">✓ 変換 ${deltaTotal}カ所</span>`;
                        } else if (changed) {
                            // 内容は変わったが検出済みカウンタは動いていない（見出し前後の空段落など）
                            label = `<span style="color:#d97706;font-weight:600" title="整形はしたが検出済みパターンは分割できず">△ 整形のみ</span>`;
                        } else {
                            label = `<span style="color:#6b7280;font-weight:600">＝ 無変更</span>`;
                        }
                        if (remainingTotal > 0) {
                            label += ` <span style="color:#dc2626;font-size:11px">残 ${remainingTotal}件</span>`;
                        }
                        btn.replaceWith(label);
                    }
                    return { ok: true, changed, deltaTotal, remainingTotal };
                }
                alert('適用失敗: ' + (res && res.data ? res.data : ''));
            } catch (e) {
                alert('通信エラー\nstatus=' + (e && e.status) + ' statusText=' + (e && e.statusText) + '\nresponseText=' + ((e && e.responseText) ? String(e.responseText).slice(0, 500) : '(empty)'));
                if (window.console) console.error('[psplit ajax] failed:', e);
            } finally {
                if (btn && btn.prop) btn.prop('disabled', false);
            }
            return { ok: false, changed: false, deltaTotal: 0, remainingTotal: 0 };
        }

        async function applyAll() {
            if (!posts.length) { alert('対象がありません'); return; }
            if (!confirm(`${posts.length} 件に整形を適用します。リビジョンが自動保存されるので元に戻せます。よろしいですか？`)) return;
            $('#aps-apply-all-btn').prop('disabled', true);
            let done = 0, failed = 0, actuallyConverted = 0, noChange = 0, stillRemaining = 0;
            for (const p of posts) {
                $('#aps-apply-status').text(`適用中... ${done + failed}/${posts.length}件`);
                const btn = $(`tr[data-id="${p.id}"] .aps-apply`);
                const r = await applyOne(p.id, btn.length ? btn : null);
                if (r.ok) {
                    done++;
                    if (r.deltaTotal > 0) actuallyConverted++;
                    else noChange++;
                    if (r.remainingTotal > 0) stillRemaining++;
                } else {
                    failed++;
                }
            }
            $('#aps-apply-status').html(
                `完了: 成功 ${done}件 (実変換 ${actuallyConverted}件 / 変換なし ${noChange}件) / 失敗 ${failed}件` +
                (stillRemaining > 0 ? ` — <span style="color:#dc2626">検出済みパターンが残る記事 ${stillRemaining}件（下記スキャンで再確認）</span>` : '')
            );
            $('#aps-apply-all-btn').prop('disabled', false);
            // v1.1.3: 適用後に自動で再スキャンして表を最新化する。
            // 従来は表がそのまま残り「27件成功したのにまだ27件ある」ように見えた。
            setTimeout(function () {
                $('#aps-scan-btn').trigger('click');
            }, 500);
        }

        function esc(s) {
            return String(s == null ? '' : s).replace(/[<>&"]/g, c =>
                ({'<':'&lt;','>':'&gt;','&':'&amp;','"':'&quot;'}[c])
            );
        }
    })(jQuery);
    </script>
    <?php
}

// =============================================================================
// AJAX
// =============================================================================

add_action('wp_ajax_affiros_psplit_scan', function () {
    check_ajax_referer('affiros_psplit_nonce', 'nonce');
    if (!current_user_can('manage_options')) wp_send_json_error('権限がありません');
    @set_time_limit(120);

    $settings = affiros_psplit_get_settings();
    $statuses = array_filter(array_map('trim', explode(',', $settings['target_statuses'] ?? 'publish,future,draft')));
    if (empty($statuses)) $statuses = ['publish', 'future', 'draft'];

    global $wpdb;
    $placeholders = implode(',', array_fill(0, count($statuses), '%s'));
    $query = $wpdb->prepare(
        "SELECT ID, post_title, post_content FROM {$wpdb->posts}
         WHERE post_type = 'post' AND post_status IN ($placeholders) ORDER BY ID DESC",
        ...$statuses
    );
    $rows = $wpdb->get_results($query);

    $targets = [];
    foreach ($rows as $r) {
        $stats = affiros_psplit_stats($r->post_content, $settings);
        // v1.1.2: 「長段落」「見出し昇格候補」「strong+コロン <li>」のいずれかで対象に
        if ($stats['over_200'] <= 0
            && ($stats['heading_candidates'] ?? 0) <= 0
            && ($stats['strong_label_candidates'] ?? 0) <= 0) continue;
        $targets[] = [
            'id'                       => (int)$r->ID,
            'title'                    => $r->post_title,
            'count'                    => $stats['count'],
            'max'                      => $stats['max'],
            'over_200'                 => $stats['over_200'],
            'heading_candidates'       => $stats['heading_candidates'] ?? 0,
            'strong_label_candidates'  => $stats['strong_label_candidates'] ?? 0,
        ];
    }
    wp_send_json_success([
        'scanned' => count($rows),
        'posts'   => $targets,
    ]);
});

add_action('wp_ajax_affiros_psplit_preview', function () {
    check_ajax_referer('affiros_psplit_nonce', 'nonce');
    if (!current_user_can('manage_options')) wp_send_json_error('権限がありません');

    $post_id = intval($_POST['post_id'] ?? 0);
    if (!$post_id) wp_send_json_error('post_id 不正');
    $post = get_post($post_id);
    if (!$post) wp_send_json_error('記事が見つかりません');

    // before: 簡易レンダリング用に wp ブロックコメントを剥がして <p> だけにする
    $before = preg_replace('/<!--\s*\/?wp:[^>]*-->\s*/i', '', $post->post_content);
    $after_raw = affiros_psplit_process_content($post->post_content);
    $after = preg_replace('/<!--\s*\/?wp:[^>]*-->\s*/i', '', $after_raw);

    wp_send_json_success([
        'before_html' => $before,
        'after_html'  => $after,
    ]);
});

add_action('wp_ajax_affiros_psplit_apply', function () {
    check_ajax_referer('affiros_psplit_nonce', 'nonce');
    if (!current_user_can('manage_options')) wp_send_json_error('権限がありません');
    @set_time_limit(60);

    $post_id = intval($_POST['post_id'] ?? 0);
    if (!$post_id) wp_send_json_error('post_id 不正');
    $post = get_post($post_id);
    if (!$post) wp_send_json_error('記事が見つかりません');

    // v1.1.3: 変換前後の stats を取ることで、何が実際に変換されたか報告する。
    // これまでは「$new !== $original」だけを見ていたので、例えば見出し前後の
    // 空段落追加(step 4/5)だけで「成功」扱いになり、strong ラベルは分割されず
    // 残り続けても気付けなかった。
    $settings = affiros_psplit_get_settings();
    $before_stats = affiros_psplit_stats($post->post_content, $settings);
    $new = affiros_psplit_process_content($post->post_content, $settings);
    $after_stats = affiros_psplit_stats($new, $settings);

    $delta = [
        'over_200_resolved' => max(0, $before_stats['over_200'] - $after_stats['over_200']),
        'heading_promoted'  => max(0, ($before_stats['heading_candidates'] ?? 0) - ($after_stats['heading_candidates'] ?? 0)),
        'strong_label_split'=> max(0, ($before_stats['strong_label_candidates'] ?? 0) - ($after_stats['strong_label_candidates'] ?? 0)),
    ];
    $delta_total = array_sum($delta);
    $remaining_after = [
        'over_200'                => $after_stats['over_200'],
        'heading_candidates'      => $after_stats['heading_candidates'] ?? 0,
        'strong_label_candidates' => $after_stats['strong_label_candidates'] ?? 0,
    ];
    $remaining_total = array_sum($remaining_after);

    if ($new === $post->post_content) {
        wp_send_json_success([
            'changed'         => false,
            'message'         => '変更なし',
            'delta'           => $delta,
            'remaining'       => $remaining_after,
            'remaining_total' => $remaining_total,
        ]);
    }

    set_transient('affiros_psplit_skip_' . $post_id, 1, 30);
    $result = wp_update_post(['ID' => $post_id, 'post_content' => $new], true);
    delete_transient('affiros_psplit_skip_' . $post_id);
    if (is_wp_error($result)) wp_send_json_error($result->get_error_message());

    wp_send_json_success([
        'changed'         => true,
        'delta'           => $delta,
        'delta_total'     => $delta_total,
        'remaining'       => $remaining_after,
        'remaining_total' => $remaining_total,
    ]);
});

// =============================================================================
// 編集画面メタボックス（個別記事の即整形）
// =============================================================================

add_action('add_meta_boxes', function () {
    add_meta_box(
        'affiros-psplit-metabox',
        '📝 段落整形',
        'affiros_psplit_render_metabox',
        'post',
        'side',
        'default'
    );
});

function affiros_psplit_render_metabox($post) {
    $stats = affiros_psplit_stats($post->post_content);
    $hc = $stats['heading_candidates'] ?? 0;
    $slc = $stats['strong_label_candidates'] ?? 0;
    ?>
    <div style="font-size:12px;line-height:1.7">
        <div>段落数: <strong><?php echo intval($stats['count']); ?></strong></div>
        <div>最大字数: <strong><?php echo intval($stats['max']); ?>字</strong></div>
        <div>200字超: <strong style="color:<?php echo $stats['over_200'] > 0 ? '#dc2626' : '#16a34a'; ?>"><?php echo intval($stats['over_200']); ?>件</strong></div>
        <div>見出し昇格候補: <strong style="color:<?php echo $hc > 0 ? '#d97706' : '#16a34a'; ?>"><?php echo intval($hc); ?>件</strong></div>
        <div>strongラベル: <strong style="color:<?php echo $slc > 0 ? '#2563eb' : '#16a34a'; ?>" title="&lt;li&gt;&lt;strong&gt;ラベル&lt;/strong&gt;：長文 パターン"><?php echo intval($slc); ?>件</strong></div>
    </div>
    <hr style="margin:10px 0">
    <button type="button" class="button button-primary" id="aps-mb-apply" data-id="<?php echo intval($post->ID); ?>" style="width:100%">✨ この記事を整形</button>
    <div id="aps-mb-status" style="margin-top:8px;font-size:12px"></div>
    <script>
    jQuery(function ($) {
        $('#aps-mb-apply').on('click', async function () {
            const btn = $(this);
            const id = btn.data('id');
            if (!confirm('この記事を段落整形します。リビジョンが自動保存されます。実行しますか？')) return;
            btn.prop('disabled', true).text('適用中...');
            try {
                const res = await $.post(
                    (window.AffirosPsplit && AffirosPsplit.ajaxUrl) || ajaxurl,
                    {
                        action: 'affiros_psplit_apply',
                        nonce: (window.AffirosPsplit && AffirosPsplit.nonce) || '',
                        post_id: id,
                    }
                );
                if (res && res.success) {
                    $('#aps-mb-status').html('<span style="color:#16a34a;font-weight:600">✓ 整形しました。ページを再読み込みして確認してください</span>');
                } else {
                    $('#aps-mb-status').html('<span style="color:#dc2626">失敗: ' + (res.data || '') + '</span>');
                }
            } catch (e) {
                $('#aps-mb-status').html('<span style="color:#dc2626">通信エラー</span>');
            } finally {
                btn.prop('disabled', false).text('✨ この記事を整形');
            }
        });
    });
    </script>
    <?php
}

// メタボックス画面でも nonce を渡せるよう admin_enqueue_scripts を post.php にも反応させる
add_action('admin_enqueue_scripts', function ($hook) {
    if (!in_array($hook, ['post.php', 'post-new.php'], true)) return;
    wp_localize_script('jquery', 'AffirosPsplit', [
        'ajaxUrl' => admin_url('admin-ajax.php'),
        'nonce'   => wp_create_nonce('affiros_psplit_nonce'),
    ]);
});
