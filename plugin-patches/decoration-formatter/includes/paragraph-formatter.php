<?php
/**
 * 段落整形コアロジック
 */

if (!defined('ABSPATH')) exit;

// =============================================================================
// 設定
// =============================================================================

function decofmt_fmt_default_settings() {
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
        'one_sentence_per_paragraph' => 'no', // 1文ごとに改行モード（既定オフ）
        'target_statuses'      => 'publish,future,draft',
    ];
}

function decofmt_fmt_get_settings() {
    $saved = get_option('decofmt_fmt_settings', []);
    return array_merge(decofmt_fmt_default_settings(), is_array($saved) ? $saved : []);
}

/**
 * v1.0.27: 一括整形の絞り込み用。公開されている投稿タイプを
 * 「投稿 → 固定ページ → その他（カスタム投稿タイプ）」の順で返す。
 * 戻り値は [投稿タイプ名 => 表示ラベル]。添付ファイルは本文整形の対象外なので除く。
 */
function decofmt_fmt_get_post_types() {
    $objects = get_post_types(['public' => true], 'objects');
    unset($objects['attachment']);

    $list = [];
    foreach ($objects as $name => $obj) {
        $list[$name] = $obj->labels->name ?: ($obj->label ?: $name);
    }

    $head = [];
    foreach (['post', 'page'] as $key) {
        if (isset($list[$key])) {
            $head[$key] = $list[$key];
            unset($list[$key]);
        }
    }
    return $head + $list;
}

/**
 * v1.0.27: 投稿タイプ名 → 表示ラベル。未知の名前はそのまま返す。
 */
function decofmt_fmt_get_post_type_label($name) {
    $list = decofmt_fmt_get_post_types();
    return $list[$name] ?? $name;
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
function decofmt_fmt_process_content($content, $settings = null) {
    if ($settings === null) $settings = decofmt_fmt_get_settings();
    if (!$content || trim($content) === '') return $content;

    // 1) 一旦 wp:paragraph コメントを剥がす（再ラップは最後にする）
    $work = preg_replace('/<!--\s*\/?wp:paragraph[^>]*-->\s*/i', '', $content);

    // 2) 句読点の正規化
    if (($settings['normalize_punctuation'] ?? 'yes') === 'yes') {
        $work = decofmt_fmt_normalize_punctuation($work);
    }

    // 3) 各 <p>...</p> を整形
    $min_p = max(80, intval($settings['min_paragraph_chars'] ?? 200));
    $min_s = max(20, intval($settings['min_sentence_chars'] ?? 60));
    $force = max(120, intval($settings['force_split_chars'] ?? 300));
    $connectors = decofmt_fmt_parse_connectors($settings['connectors'] ?? '');

    // ★v1.0.25: クロージャの外で判定してから use で渡す。
    //   以前は closure 内で $settings を参照していたが、PHPのクロージャは
    //   外側の変数を自動では引き継がないため $settings が未定義になり、
    //   「。で区切る」モードが実処理側では常にOFFになっていた
    //   （カウンタ側は設定を読むので「対象23件・変換0件」という食い違いが出た）。
    $one_sentence_mode = ($settings['one_sentence_per_paragraph'] ?? 'no') === 'yes';

    $work = preg_replace_callback(
        '/<p\b([^>]*)>([\s\S]*?)<\/p>/i',
        function ($m) use ($min_p, $min_s, $force, $connectors, $one_sentence_mode) {
            $attr = $m[1];
            $inner = $m[2];

            // 画像・表・リスト・div を含む <p> はスキップ
            if (preg_match('/<(img|table|ul|ol|div|figure|iframe|hr|blockquote)\b/i', $inner)) {
                return $m[0];
            }
            $plain = trim(preg_replace('/<[^>]+>/u', '', $inner));
            $plain_len = mb_strlen($plain);
            $sentence_count = preg_match_all('/[。！？]/u', $plain);
            // 対象条件:
            //   - 長段落（min_paragraph_chars 超）
            //   - 「短めだが句点3個以上かつ60字以上」の密段落
            //   - one_sentence_per_paragraph モード時は句点2個以上の段落すべて
            $is_long_by_chars  = $plain_len > $min_p;
            $is_dense_short    = ($sentence_count >= 3 && $plain_len >= 60);
            $is_multi_sentence = $one_sentence_mode && $sentence_count >= 2;
            if (!$is_long_by_chars && !$is_dense_short && !$is_multi_sentence) {
                return '<p' . $attr . '>' . trim($inner) . '</p>';
            }

            // 分割粒度の決定:
            //   - 1文モード or 3+句短段落 → min=1（句点ごとに完全分割）
            //     dense_short で min=60 だと不均等分布時（例: 5句×35字）に
            //     (2句)+(3句) の分割になり、3句側が「3+句短段落」として counter に残る
            //     問題があるため、v1.0.12 で最初から min=1 に統一。
            //   - 長段落（200字超・2句以下）→ min=60（意味的まとまりを保つ）
            $effective_min_s = ($one_sentence_mode || $is_dense_short) ? 1 : $min_s;
            $segments = decofmt_fmt_split_inner($inner, $effective_min_s, $force, $connectors);
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
        $work = decofmt_fmt_promote_paragraph_headings($work, $settings);
        $work = decofmt_fmt_promote_list_item_headings($work, $settings);
    }

    // 3.7) <li> 内の「<strong>ラベル</strong>：長い説明文」を 見出し + 段落 に分割
    if (($settings['split_strong_label_list'] ?? 'yes') === 'yes') {
        $work = decofmt_fmt_split_strong_label_list($work, $settings);
    }

    // 4) H2/H3 前後の余白
    if (($settings['add_heading_spacing'] ?? 'yes') === 'yes') {
        $work = decofmt_fmt_add_heading_spacing($work);
    }

    // 5) 画像・表前後の余白
    if (($settings['add_media_spacing'] ?? 'yes') === 'yes') {
        $work = decofmt_fmt_add_media_spacing($work);
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
 * v1.0.28: 文の区切りの直後に続く「閉じ」文字。
 *   「効果は薄いのでは？」のように句点のあとへ閉じカッコが来る場合、
 *   句点の直後で切ると閉じカッコだけが次の段落の先頭に落ちて
 *   「」と不安を感じる方も少なくありません。」という段落ができてしまう。
 *   分割位置を決めるときは、この文字が続くあいだは切らない。
 */
function decofmt_fmt_closing_chars() {
    return '」』）〉》】］｝”’〟、。！？!?,.';
}

/**
 * <p> の内側 HTML を「句点」「接続詞」で分割する。
 */
function decofmt_fmt_split_inner($inner_html, $min_sentence, $force_split_chars, $connectors) {
    $tags = [];
    $plain_template = preg_replace_callback(
        '/<[^>]+>/u',
        function ($m) use (&$tags) {
            $tags[] = $m[0];
            return "\x02TAG" . (count($tags) - 1) . "\x03";
        },
        $inner_html
    );

    $closers = decofmt_fmt_closing_chars();
    // 句点の直後でも、閉じカッコや句読点が続くならそこでは切らない（v1.0.28）
    $pieces = preg_split('/(?<=[。！？\?\!])(?![' . $closers . '])/u', $plain_template);
    if (!$pieces) return [$inner_html];

    if (!empty($connectors)) {
        $expanded = [];
        foreach ($pieces as $piece) {
            $sub = [$piece];
            foreach ($connectors as $conn) {
                $new_sub = [];
                foreach ($sub as $p) {
                    $pos = mb_strpos($p, $conn);
                    if ($pos === false || $pos === 0) {
                        $new_sub[] = $p;
                        continue;
                    }
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
            $segments[count($segments) - 1] .= $buf;
        } else {
            $segments[] = $buf;
        }
    }

    // force_split
    $final = [];
    foreach ($segments as $seg) {
        $seg_plain = preg_replace('/\x02TAG\d+\x03/u', '', $seg);
        if (mb_strlen(trim($seg_plain)) <= $force_split_chars) {
            $final[] = $seg;
            continue;
        }
        $sub_pieces = preg_split('/(?<=、)(?![' . $closers . '])/u', $seg);
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

    // タグ開閉バランスチェック: <a>...</a> 等が別セグメントに跨ぐと HTML が壊れる
    // （Gutenberg「無効なコンテンツ」の原因）。アンバランスな場合は次と結合する。
    if (!empty($final)) {
        // タグ種別を分類
        // v1.0.11: HTML コメント（<!-- xxx -->）を 'self' 扱いに追加。
        //   以前は fallback で 'open' 扱いだったため、コメントを含む段落が
        //   全部マージされて「候補15件・変換0件」になっていた。
        $void_re = '#^<(?:br|img|hr|input|meta|link|source|track|wbr|area|base|col|embed|param)\b#i';
        $tag_kind = [];
        foreach ($tags as $i => $tag) {
            if (preg_match('#^<!--#', $tag)) {
                $tag_kind[$i] = 'self'; // HTML コメントは開閉に影響しない
            } elseif (preg_match('#^</\w#', $tag)) {
                $tag_kind[$i] = 'close';
            } elseif (preg_match('#/>\s*$#', $tag) || preg_match($void_re, $tag)) {
                $tag_kind[$i] = 'self';
            } else {
                $tag_kind[$i] = 'open';
            }
        }
        // セグメント順に走査し、stack != 0 なら次と結合
        $balanced = [];
        $carry = '';
        foreach ($final as $seg) {
            $current = $carry . $seg;
            $carry = '';
            preg_match_all('/\x02TAG(\d+)\x03/u', $current, $mm);
            $stack = 0;
            foreach ($mm[1] as $idx) {
                $kind = $tag_kind[intval($idx)] ?? 'open';
                if ($kind === 'open') $stack++;
                elseif ($kind === 'close') $stack--;
            }
            if ($stack !== 0) {
                $carry = $current; // 未閉じあり → 次ピースと結合
            } else {
                $balanced[] = $current;
            }
        }
        if ($carry !== '') {
            if (empty($balanced)) {
                $balanced[] = $carry;
            } else {
                $balanced[count($balanced) - 1] .= $carry;
            }
        }
        $final = $balanced;
    }

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

function decofmt_fmt_normalize_punctuation($html) {
    $html = preg_replace('/。{2,}/u', '。', $html);
    $html = preg_replace('/、{2,}/u', '、', $html);
    $html = preg_replace('/[ \t　]+(?=<\/p>)/u', '', $html);
    return $html;
}

function decofmt_fmt_parse_connectors($raw) {
    $list = preg_split('/[\r\n,，]+/u', (string)$raw);
    $out = [];
    foreach ($list as $c) {
        $c = trim($c);
        if ($c !== '') $out[] = $c;
    }
    return $out;
}

/**
 * 「ポイントN：xxx」「ステップN：xxx」「【xxx】yyy」等の段落を h4 (or h3) に昇格。
 */
function decofmt_fmt_promote_paragraph_headings($html, $settings) {
    $max_chars = max(20, min(200, intval($settings['heading_max_chars'] ?? 60)));
    $level = in_array($settings['heading_level'] ?? '4', ['2', '3', '4', '5'], true)
        ? $settings['heading_level']
        : '4';
    $patterns_raw = $settings['heading_patterns'] ?? '';
    $patterns = array_filter(array_map('trim', preg_split('/\r?\n/', $patterns_raw)));
    if (empty($patterns)) return $html;

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
            if (preg_match('/<(img|table|ul|ol|h[1-6]|div|figure|iframe|hr|blockquote)\b/i', $inner)) {
                return $m[0];
            }
            $plain = trim(preg_replace('/<[^>]+>/u', '', $inner));
            if ($plain === '') return $m[0];
            if (mb_strlen($plain) > $max_chars) return $m[0];
            if (preg_match('/[。.!?！？]\s*$/u', $plain)) return $m[0];

            $matched = false;
            foreach ($regex_list as $rx) {
                if (preg_match($rx, $plain)) { $matched = true; break; }
            }
            if (!$matched) return $m[0];

            $level_attr = $level === '2' ? '' : ' {"level":' . intval($level) . '}';
            return "<!-- wp:heading{$level_attr} -->\n"
                 . "<h{$level} class=\"wp-block-heading\">" . trim($inner) . "</h{$level}>\n"
                 . "<!-- /wp:heading -->";
        },
        $html
    );
}

/**
 * <ul>/<ol> 内の <li> で見出しパターンにマッチするものを h4 に昇格。
 */
function decofmt_fmt_promote_list_item_headings($html, $settings) {
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

            if (!preg_match_all('/<li\b[^>]*>([\s\S]*?)<\/li>/i', $list_inner, $li_matches, PREG_OFFSET_CAPTURE)) {
                return $m[0];
            }

            $segments = [];
            $current_list_items = [];
            $any_promoted = false;

            foreach ($li_matches[0] as $idx => $li_full) {
                $li_html = $li_full[0];
                $li_inner = $li_matches[1][$idx][0];

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

            if (!$any_promoted) return $m[0];

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
 * <li> 内が「<strong>ラベル</strong>：長い説明文」の構造を「ラベル → 見出し / 説明文 → 段落」に分割。
 */
function decofmt_fmt_split_strong_label_list($html, $settings) {
    if (!$html) return $html;
    $min_content = max(10, intval($settings['split_min_content_chars'] ?? 25));

    preg_match_all('/<h([1-6])\b[^>]*>/i', $html, $h_matches, PREG_OFFSET_CAPTURE);
    $heading_positions = [];
    foreach ($h_matches[0] as $i => $m) {
        $heading_positions[] = ['pos' => $m[1], 'level' => intval($h_matches[1][$i][0])];
    }

    $list_pattern = '/(?:<!--\s*wp:list[^>]*-->\s*)?<(ul|ol)\b([^>]*)>([\s\S]*?)<\/\1>(?:\s*<!--\s*\/wp:list\s*-->)?/i';
    if (!preg_match_all($list_pattern, $html, $list_matches, PREG_OFFSET_CAPTURE)) {
        return $html;
    }

    $replacements = [];
    for ($i = 0; $i < count($list_matches[0]); $i++) {
        $list_full = $list_matches[0][$i][0];
        $list_pos  = $list_matches[0][$i][1];
        $list_tag  = $list_matches[1][$i][0];
        $list_attr = $list_matches[2][$i][0];
        $list_inner = $list_matches[3][$i][0];

        $parent_level = 2;
        foreach ($heading_positions as $h) {
            if ($h['pos'] < $list_pos) {
                $parent_level = $h['level'];
            } else {
                break;
            }
        }
        $child_level = min(6, $parent_level + 1);

        $result = decofmt_fmt_process_strong_list_inner(
            $list_inner, $list_tag, $list_attr, $child_level, $min_content
        );
        if ($result === null) continue;

        $replacements[] = [
            'start'       => $list_pos,
            'end'         => $list_pos + strlen($list_full),
            'replacement' => $result,
        ];
    }

    usort($replacements, function ($a, $b) { return $b['start'] - $a['start']; });
    foreach ($replacements as $r) {
        $html = substr($html, 0, $r['start']) . $r['replacement'] . substr($html, $r['end']);
    }
    return $html;
}

/**
 * <li> 内の「太字ラベル + 区切り文字 + 説明文」を検出する正規表現を返す（v1.0.21）
 *
 * ★この関数を必ず経由すること。
 *   以前は同じ正規表現が「実際に分割する処理」と「件数を数えるカウンタ」の2箇所に
 *   コピペされていた。片方だけ直すと「検出したのに変換されない／その逆」という
 *   噛み合わないバグになるため、1箇所に集約している。
 *
 * 対応する区切り文字: 全角/半角コロン、全角/半角スラッシュ、縦棒、各種ダッシュ
 *   例) <strong>ラベル</strong>：説明   <strong>ラベル</strong>／説明
 * タグは <strong> と <b> の両方に対応。
 */
function decofmt_fmt_strong_label_pattern() {
    return '/^\s*<(?:strong|b)[^>]*>([^<]+?)<\/(?:strong|b)>\s*[：:／\/｜|―—–−-]\s*([\s\S]+)$/iu';
}

function decofmt_fmt_process_strong_list_inner($list_inner, $list_tag, $list_attr, $child_level, $min_content) {
    if (!preg_match_all('/<li\b([^>]*)>([\s\S]*?)<\/li>/i', $list_inner, $li_matches)) return null;

    $strong_pattern = decofmt_fmt_strong_label_pattern();

    $segments = [];
    $current_items = [];
    $any_converted = false;

    foreach ($li_matches[2] as $idx => $li_inner) {
        $li_attr = $li_matches[1][$idx];
        $matched = false;

        if (preg_match($strong_pattern, $li_inner, $sm)) {
            $label = trim(strip_tags($sm[1]));
            $content_html = trim($sm[2]);
            $content_plain = trim(preg_replace('/<[^>]+>/u', '', $content_html));

            // stats 側は >= min_content で数えるので split 側も揃える。
            // off-by-one で境界の1件が「検出はする、分割しない」状態になっていた。
            if ($label !== '' && mb_strlen($content_plain) >= $min_content) {
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

function decofmt_fmt_add_heading_spacing($html) {
    // v1.0.11: 空段落挿入は Gutenberg エディタに空ブロックを残して見づらいだけ
    // だったので撤廃。見出し前後の余白は WP テーマの CSS（H2 の margin-top 等）に
    // 任せる。ほぼ全ての現代テーマが十分な余白を持っている。
    // 設定「見出し前後の余白」は互換のため残すが、実効なし（no-op）。
    return $html;
}

function decofmt_fmt_add_media_spacing($html) {
    // 同上: 空段落挿入撤廃。テーマCSSに任せる。
    return $html;
}

/**
 * 段落統計（プレビュー用）
 *
 * - `heading_candidates`: 「ポイントN:」等のパターンにマッチする <p>/<li>（heading_max_chars 以内）
 * - `strong_label_candidates`: <li><strong>ラベル</strong>:長文</li> パターン
 *   （**heading_candidates と重複しない別カウンタ**。前者は heading_max_chars=60 で切られるため
 *    「合計60字超だが split_strong_label_list なら分割対象」の <li> がカウント漏れしていた）
 */
function decofmt_fmt_stats($html, $settings = null) {
    if ($settings === null) $settings = decofmt_fmt_get_settings();

    // <p> の統計取得（over_200 用）
    $lens = [];
    if (preg_match_all('/<p\b[^>]*>([\s\S]*?)<\/p>/i', $html, $m)) {
        foreach ($m[1] as $inner) {
            $plain = trim(preg_replace('/<[^>]+>/u', '', $inner));
            if ($plain === '') continue;
            $lens[] = mb_strlen($plain);
        }
    }

    // 短めだが句点3個以上（通常モード）or 句点2個以上（1文モード）の <p> をカウント。
    // process_content の判定と同じロジックを通し、実際に split_inner が2セグメント以上
    // 返すものだけをカウント（counter = 実際の変換数、を保証）。
    $multi_sentence_short = 0;
    $one_sentence_mode = ($settings['one_sentence_per_paragraph'] ?? 'no') === 'yes';
    $conn_parsed = decofmt_fmt_parse_connectors($settings['connectors'] ?? '');
    $min_s_setting = max(20, intval($settings['min_sentence_chars'] ?? 60));
    $force_setting = max(120, intval($settings['force_split_chars'] ?? 300));
    $min_sc_threshold = $one_sentence_mode ? 2 : 3;
    if (isset($m[1])) {
        foreach ($m[1] as $inner) {
            if (preg_match('/<(img|table|ul|ol|div|figure|iframe|hr|blockquote)\b/i', $inner)) continue;
            $plain = trim(preg_replace('/<[^>]+>/u', '', $inner));
            if ($plain === '') continue;
            $len = mb_strlen($plain);
            if ($len > 200) continue; // over_200 側でカウント済み
            $sc = preg_match_all('/[。！？]/u', $plain);
            if ($sc < $min_sc_threshold) continue;
            if (!$one_sentence_mode && $len < 60) continue; // 通常モードでは60字未満スキップ
            // process_content と同じ粒度で split_inner を呼んで、実際に割れるか確認
            // dense_short（3+句 & 60字以上）は min=1 で判定（v1.0.12 で process_content と揃えた）
            $test = decofmt_fmt_split_inner($inner, 1, $force_setting, $conn_parsed);
            if (count($test) < 2) continue;
            $multi_sentence_short++;
        }
    }

    // strong+コロンの <li> パターンを別カウンタで数える。
    // 従来は heading_candidates として数えていたが、<li> 全体の plain text が
    // heading_max_chars(=60) を超える場合に SKIP されるため、
    // 実際の <li><strong>ラベル</strong>：長文</li>（合計60字超）が全部カウント漏れしていた。
    $strong_label_candidates = 0;
    if (($settings['split_strong_label_list'] ?? 'yes') === 'yes') {
        $min_content = max(10, intval($settings['split_min_content_chars'] ?? 25));
        if (preg_match_all('/<li\b[^>]*>([\s\S]*?)<\/li>/i', $html, $li_m)) {
            $strong_pattern = decofmt_fmt_strong_label_pattern(); // ★process 側と同じものを使う
            foreach ($li_m[1] as $inner) {
                if (!preg_match($strong_pattern, $inner, $sm)) continue;
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
            'count'                   => 0,
            'avg'                     => 0,
            'max'                     => 0,
            'over_200'                => 0,
            'heading_candidates'      => $heading_candidates,
            'strong_label_candidates' => $strong_label_candidates,
            'multi_sentence_short'    => $multi_sentence_short,
        ];
    }

    return [
        'count'                   => count($lens),
        'avg'                     => intval(array_sum($lens) / count($lens)),
        'max'                     => max($lens),
        'over_200'                => count(array_filter($lens, function ($l) { return $l > 200; })),
        'heading_candidates'      => $heading_candidates,
        'strong_label_candidates' => $strong_label_candidates,
        'multi_sentence_short'    => $multi_sentence_short,
    ];
}

/**
 * 設定サニタイズ
 */
function decofmt_fmt_sanitize($input) {
    $existing = get_option('decofmt_fmt_settings', []);
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
    $output['one_sentence_per_paragraph'] = ($input['one_sentence_per_paragraph'] ?? 'no') === 'yes' ? 'yes' : 'no';
    $output['target_statuses']         = sanitize_text_field($input['target_statuses'] ?? 'publish,future,draft');
    return $output;
}

// =============================================================================
// 保存時 hook（オプション）
// =============================================================================

add_action('save_post_post', 'decofmt_fmt_on_save', 20, 3);
function decofmt_fmt_on_save($post_id, $post, $update) {
    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
    if (wp_is_post_revision($post_id) || wp_is_post_autosave($post_id)) return;

    $settings = decofmt_fmt_get_settings();
    if (($settings['auto_on_save'] ?? 'no') !== 'yes') return;

    $allowed = explode(',', $settings['target_statuses'] ?? 'publish,future,draft');
    if (!in_array($post->post_status, $allowed, true)) return;

    if (get_transient('decofmt_fmt_skip_' . $post_id)) return;

    $new_content = decofmt_fmt_process_content($post->post_content, $settings);
    if ($new_content !== $post->post_content) {
        set_transient('decofmt_fmt_skip_' . $post_id, 1, 30);
        remove_action('save_post_post', 'decofmt_fmt_on_save', 20);
        wp_update_post(['ID' => $post_id, 'post_content' => $new_content]);
        add_action('save_post_post', 'decofmt_fmt_on_save', 20, 3);
        delete_transient('decofmt_fmt_skip_' . $post_id);
    }
}
