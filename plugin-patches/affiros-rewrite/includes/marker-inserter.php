<?php
/**
 * 商品カードマーカー挿入エンジン
 *
 * 本体 app.py の insert_card_markers / DEFAULT_CARD_INSERTION_PATTERNS /
 * _build_marker / _find_matome_h2_range / _find_first_h2_range /
 * strip_leading_introduction_h2 / strip_summary_table_sections を
 * 忠実に PHP 移植したもの。
 *
 * リライト後の HTML に <!--ai-product:vertical--> / <!--ai-product:ranking:3--> を
 * 記事タイプ別の規則で挿入する。実際の商品カード描画は affiros-product-inserter
 * プラグインが担当。
 *
 * 注: 本体は load_ad_insertion_patterns() でユーザー編集パターンを使えるが、
 *     プラグインは本体の JSON を読めないため DEFAULT 相当のみ対応。
 */

if (!defined('ABSPATH')) exit;

class Affiros_Rewrite_Marker_Inserter {

    /**
     * 記事タイプ別 既定の挿入パターン。
     * 本体 app.py:DEFAULT_CARD_INSERTION_PATTERNS と一致させること。
     *
     * v0.4.44 (2026-07-08): 本体との乖離を修正。
     * v0.4.45 (2026-07-08): まとめ後 ranking カードを TOP3 → TOP5 に変更。
     */
    public static function default_patterns() {
        return [
            'ranking' => [
                ['position' => 'after_each_h3_rank', 'design' => 'vertical', 'repeat' => 1],
                ['position' => 'after_last_h2',      'design' => 'ranking',  'count'  => 5],
            ],
            'column' => [
                ['position' => 'before_first_h2',    'design' => 'vertical', 'repeat' => 3],
                ['position' => 'after_last_h2',      'design' => 'ranking',  'count'  => 5],
            ],
            'brand' => [
                ['position' => 'after_first_h2',     'design' => 'vertical', 'repeat' => 1],
                ['position' => 'after_last_h2',      'design' => 'vertical', 'repeat' => 1],
            ],
        ];
    }

    /**
     * WPオプションに保存されたパターンを返す。未設定・空の場合はデフォルトにフォールバック。
     */
    public static function get_patterns() {
        $settings = function_exists('affiros_rewrite_get_settings') ? affiros_rewrite_get_settings() : [];
        $patterns = $settings['ad_patterns'] ?? [];
        if (!is_array($patterns)) {
            return self::default_patterns();
        }
        // 全記事タイプが空配列なら「未設定」とみなしデフォルトを使う
        $has_any = false;
        foreach (['ranking', 'column', 'brand'] as $t) {
            if (!empty($patterns[$t])) { $has_any = true; break; }
        }
        return $has_any ? $patterns : self::default_patterns();
    }

    /**
     * 記事タイプに応じてマーカーを挿入する。
     *
     * @return array {
     *   'html'  => string マーカー挿入後のHTML
     *   'stats' => array {
     *     'rules_attempted' => int 設定上のルール総数
     *     'rules_applied'   => int 挿入に成功したルール数
     *     'rules_failed'    => array 失敗したルールの position 名
     *     'marker_count'    => int 実際に挿入したマーカー総数
     *     'per_position'    => array { position名 => 挿入数 }
     *     'fallback_used'   => bool 緊急フォールバック発動フラグ
     *   }
     * }
     */
    public static function insert($html, $article_type, $title = '') {
        $stats = [
            'rules_attempted' => 0,
            'rules_applied'   => 0,
            'rules_failed'    => [],
            'marker_count'    => 0,
            'per_position'    => [],
            'fallback_used'   => false,
        ];
        if ($html === '' || $html === null) {
            return ['html' => $html, 'stats' => $stats];
        }
        $patterns = self::get_patterns();
        $rules = $patterns[$article_type] ?? [];
        if (!$rules) {
            return ['html' => $html, 'stats' => $stats];
        }
        $stats['rules_attempted'] = count($rules);

        $text = (string)$html;
        $text = self::strip_leading_introduction_h2($text, $title);
        $text = self::strip_summary_table_sections($text);

        $matome_range   = self::find_matome_h2_range($text);
        $first_h2_range = self::find_first_h2_range($text);
        $last_h2_range  = self::find_last_h2_range($text);

        $insertions = [];   // [挿入バイト位置, 挿入文字列]
        $rule_applied = []; // 各ルールが何個マーカーを置いたか

        foreach ($rules as $idx => $rule) {
            $pos = $rule['position'] ?? '';
            $design = $rule['design'] ?? 'vertical';
            $count = $rule['count'] ?? null;
            $repeat = max(1, intval($rule['repeat'] ?? 1));
            $marker = self::build_marker($design, $count);
            $marker_block = str_repeat("\n" . $marker, $repeat);
            $rule_applied[$idx] = 0;

            if ($pos === 'top') {
                $insertions[] = [0, $marker_block . "\n"];
                $rule_applied[$idx] += $repeat;
            } elseif ($pos === 'bottom') {
                $insertions[] = [strlen($text), "\n" . $marker_block];
                $rule_applied[$idx] += $repeat;
            } elseif ($pos === 'before_first_h2' && $first_h2_range) {
                $insertions[] = [$first_h2_range[0], $marker_block . "\n"];
                $rule_applied[$idx] += $repeat;
            } elseif ($pos === 'after_first_h2' && $first_h2_range) {
                $insertions[] = [$first_h2_range[1], "\n" . $marker_block];
                $rule_applied[$idx] += $repeat;
            } elseif ($pos === 'before_matome_h2' && $matome_range) {
                $insertions[] = [$matome_range[0], $marker_block . "\n"];
                $rule_applied[$idx] += $repeat;
            } elseif ($pos === 'after_matome_h2' && $matome_range) {
                $insertions[] = [$matome_range[1], "\n" . $marker_block];
                $rule_applied[$idx] += $repeat;
            } elseif ($pos === 'after_last_h2' && $last_h2_range) {
                $insertions[] = [$last_h2_range[1], "\n" . $marker_block];
                $rule_applied[$idx] += $repeat;
            } elseif ($pos === 'after_each_h3_rank') {
                $h3_ins = self::collect_h3_rank_insertions($text, $marker, $matome_range, $first_h2_range, $title);
                foreach ($h3_ins as $ins) {
                    $insertions[] = $ins;
                    $rule_applied[$idx]++;
                }
            }

            if ($rule_applied[$idx] > 0) {
                $stats['rules_applied']++;
                $stats['marker_count'] += $rule_applied[$idx];
                $stats['per_position'][$pos] = ($stats['per_position'][$pos] ?? 0) + $rule_applied[$idx];
            } else {
                $stats['rules_failed'][] = $pos;
            }
        }

        // 緊急フォールバック: マーカーが1個も入らなかったときに記事末尾へ vertical を1個挿入
        if ($stats['marker_count'] === 0) {
            $insertions[] = [strlen($text), "\n" . self::build_marker('vertical')];
            $stats['marker_count']++;
            $stats['per_position']['bottom_fallback'] = 1;
            $stats['fallback_used'] = true;
        }

        // 後ろから挿入してバイト位置のズレを防ぐ
        usort($insertions, function ($a, $b) { return $b[0] - $a[0]; });
        foreach ($insertions as $ins) {
            $text = substr($text, 0, $ins[0]) . $ins[1] . substr($text, $ins[0]);
        }
        return ['html' => $text, 'stats' => $stats];
    }

    /**
     * プラグイン用マーカー文字列を組み立てる。本体 _build_marker の移植。
     * count が指定される設計: compare / ranking
     */
    private static function build_marker($design = 'vertical', $count = null) {
        if (!$design || $design === 'default') {
            return '<!--ai-product-->';
        }
        $count_designs = ['compare', 'ranking'];
        if (in_array($design, $count_designs, true) && $count) {
            return '<!--ai-product:' . $design . ':' . intval($count) . '-->';
        }
        return '<!--ai-product:' . $design . '-->';
    }

    /**
     * 「まとめ」を含むH2の [開始, 終了] バイト位置を返す。無ければ null。
     *
     * ⚠️ 旧版は preg_match で「最初に見つかった」H2 を返していたが、これだと
     * 記事中ほどに「○○の選び方まとめ」のような区切り見出しがあると、本物の
     * まとめではなく途中位置にマーカーが入る事故が発生していた。
     * 新版は preg_match_all で全候補を取得し、最後（記事末尾側）の H2 を返す。
     * さらに「○○の選び方まとめ」「比較まとめ」「一覧まとめ」のような
     * 区切り見出しは除外する。
     */
    private static function find_matome_h2_range($html) {
        $re = '/<h2[^>]*>(?:(?!<\/h2>)[\s\S])*?(?:まとめ|総まとめ|結論|要点|おわりに|最後に|総括|ベストバイ)(?:(?!<\/h2>)[\s\S])*?<\/h2>/iu';
        if (!preg_match_all($re, $html, $matches, PREG_OFFSET_CAPTURE | PREG_SET_ORDER)) {
            return null;
        }
        // 区切り見出しを除外
        $section_re = '/(?:選び方|選定|比較|一覧|早見|ポイント|チェック|シーン|目的|用途|使い方|レビュー)\s*まとめ/iu';
        $non_section = [];
        foreach ($matches as $m) {
            $inner = preg_replace('/<[^>]+>/', '', $m[0][0]);
            if (!preg_match($section_re, $inner)) {
                $non_section[] = $m;
            }
        }
        $pool = !empty($non_section) ? $non_section : $matches;
        $chosen = end($pool);
        $start = $chosen[0][1];
        return [$start, $start + strlen($chosen[0][0])];
    }

    /**
     * 記事の最初のH2の [開始, 終了] バイト位置を返す。無ければ null。
     */
    private static function find_first_h2_range($html) {
        $re = '/<h2[^>]*>(?:(?!<\/h2>)[\s\S])*?<\/h2>/iu';
        if (preg_match($re, $html, $m, PREG_OFFSET_CAPTURE)) {
            $start = $m[0][1];
            return [$start, $start + strlen($m[0][0])];
        }
        return null;
    }

    /**
     * 記事の **本当に最後の** H2 の [開始, 終了] バイト位置を返す。
     *
     * ⚠️ 旧版は「まとめH2があればそれを返す」実装だったため、記事中の
     * 「○○の選び方まとめ」H2 にヒットして、after_last_h2 ルールでも本物の
     * 末尾 H2 に挿入されない事故が頻発していた。
     *
     * 新版はまとめキーワードを一切見ず、純粋に「全 H2 のうち最後の1個」を
     * 返す。after_matome_h2 とは明確に役割を分ける。
     */
    private static function find_last_h2_range($html) {
        $re = '/<h2[^>]*>(?:(?!<\/h2>)[\s\S])*?<\/h2>/iu';
        if (preg_match_all($re, $html, $matches, PREG_OFFSET_CAPTURE | PREG_SET_ORDER)) {
            $last = end($matches);
            $start = $last[0][1];
            return [$start, $start + strlen($last[0][0])];
        }
        return null;
    }

    /**
     * after_each_h3_rank: ランキング見出しのH3直後にマーカーを集める。
     * 本体 insert_card_markers 内の after_each_h3_rank 分岐の移植。
     */
    private static function collect_h3_rank_insertions($text, $marker, $matome_range, $first_h2_range, $title = '') {
        // 強シグナル（必ず ranking 文脈）：「第N位」「N位」「No.N」「TOP1」「BEST1」「ベスト1」
        // 本体 app.py:5384-5390 と同期。
        $strong_patterns = [
            '/<h3[^>]*>[\s\[【★●■◆▼《「『（(]*(?:第\s*)?(?:\d+|[０-９]+)\s*位[\s\]】:：、・　]*[^<]*?<\/h3>/iu',
            '/<h3[^>]*>[\s\[【★●■◆▼《「『（(]*No\.?\s*(?:\d+|[０-９]+)[\s\]】:：、・　]*[^<]*?<\/h3>/iu',
            '/<h3[^>]*>[\s\[【★●■◆▼《「『（(]*(?:TOP|BEST|ベスト)\s*(?:\d+|[０-９]+)[\s\]】:：、・　]*[^<]*?<\/h3>/iu',
        ];
        $title_has_ranking_signal = class_exists('Affiros_Rewrite_Article_Type')
            ? Affiros_Rewrite_Article_Type::has_ranking_signal($title)
            : (bool) preg_match('/[0-9０-9]+\s*選|ランキング/u', (string)$title);

        $insertions = [];
        $seen = [];
        // 第1段: 強シグナルパターンで検出
        foreach ($strong_patterns as $re) {
            if (preg_match_all($re, $text, $matches, PREG_OFFSET_CAPTURE | PREG_SET_ORDER)) {
                foreach ($matches as $m) {
                    $start = $m[0][1];
                    if (isset($seen[$start])) {
                        continue;
                    }
                    $seen[$start] = true;
                    $insertions[] = [$start + strlen($m[0][0]), "\n" . $marker];
                }
            }
        }
        // 第2段: ①②③ 弱シグナル fallback（強シグナルで1個も見つからず、
        // かつタイトルに ranking signal がある時だけ発動）
        // 2026-07-08 事故（ws-outlet の 選び方 H3 ①②③④ に誤挿入されて quota 食い潰し）
        // の再発防止として v1.7.78 で fallback-only 化。
        if (!$seen && $title_has_ranking_signal) {
            $weak_re = '/<h3[^>]*>[\s\[【★●■◆▼《「『（(]*[①②③④⑤⑥⑦⑧⑨⑩][\s\]】:：、・　]*[^<]*?<\/h3>/iu';
            if (preg_match_all($weak_re, $text, $matches, PREG_OFFSET_CAPTURE | PREG_SET_ORDER)) {
                foreach ($matches as $m) {
                    $start = $m[0][1];
                    if (isset($seen[$start])) {
                        continue;
                    }
                    $seen[$start] = true;
                    $insertions[] = [$start + strlen($m[0][0]), "\n" . $marker];
                }
            }
        }
        // 第3段: 更なるフォールバック（全H3挿入）はタイトルがランキング文脈の時だけ
        // 旧版ではこの分岐が暴走してコラム記事のH3全部に商品カードが付くことがあった
        if (!$seen && $title_has_ranking_signal) {
            $end_limit = $matome_range ? $matome_range[0] : strlen($text);
            $start_limit = $first_h2_range ? $first_h2_range[1] : 0;
            if (preg_match_all('/<h3[^>]*>[^<]*?<\/h3>/iu', $text, $matches, PREG_OFFSET_CAPTURE | PREG_SET_ORDER)) {
                foreach ($matches as $m) {
                    $start = $m[0][1];
                    if ($start < $start_limit || $start >= $end_limit) {
                        continue;
                    }
                    $insertions[] = [$start + strlen($m[0][0]), "\n" . $marker];
                }
            }
        }
        return $insertions;
    }

    /**
     * 記事冒頭の導入H2を物理削除する。本体 strip_leading_introduction_h2 の移植。
     */
    private static function strip_leading_introduction_h2($html, $title = '') {
        if ($html === '' || $html === null) {
            return $html;
        }
        $text = (string)$html;
        $re = '/\A\s*(?:<!--\s*wp:heading[^>]*-->\s*)?<h2([^>]*)>((?:(?!<\/h2>)[\s\S])*?)<\/h2>(?:\s*<!--\s*\/wp:heading\s*-->)?/iu';
        if (!preg_match($re, $text, $m, PREG_OFFSET_CAPTURE)) {
            return $text;
        }
        $match_end = $m[0][1] + strlen($m[0][0]);
        $h2_inner = trim(preg_replace('/<[^>]+>/', '', $m[2][0]));

        // (a) intro キーワード
        // ⚠️ 過去版は「完全ガイド/おすすめ/比較/ランキング/選び方/...」を含めていたが、
        // これらは正規のSEO見出しで頻出する語のため、含まれているだけで削除すると
        // 「○○の選び方」「コルクマット完全ガイド」みたいな正当な H2 まで誤削除し、
        // before_first_h2 のマーカーが入らなくなる事故が発生していた。
        $intro_keywords = [
            'とは', '結論', '本記事の', 'について', 'を知る', '記事のポイント',
            'この記事では', 'この記事の目的', 'はじめに',
        ];
        $is_intro = false;
        foreach ($intro_keywords as $kw) {
            if (mb_strpos($h2_inner, $kw) !== false) {
                $is_intro = true;
                break;
            }
        }

        // (b) タイトルそのものの繰り返し H2 だけ削除（誤削除を抑える）
        if (!$is_intro && $title !== '') {
            $norm = function ($s) {
                return mb_strtolower(preg_replace('/[\s\|｜・:：－—\-　]+/u', '', (string)$s), 'UTF-8');
            };
            $nt = $norm($title);
            $nh = $norm($h2_inner);
            if ($nt !== '' && $nh !== '' && mb_strlen($nh) >= 8) {
                if ($nh === $nt) {
                    $is_intro = true;
                } elseif (mb_strpos($nt, $nh) !== false && mb_strlen($nh) >= mb_strlen($nt) * 0.85) {
                    $is_intro = true;
                } elseif (mb_strpos($nh, $nt) !== false && mb_strlen($nt) >= mb_strlen($nh) * 0.85) {
                    $is_intro = true;
                }
            }
        }

        if (!$is_intro) {
            return $text;
        }
        return ltrim(substr($text, $match_end));
    }

    /**
     * 「早見表」セクションだけを削除する。
     *
     * ⚠️ 旧版は「比較表/比較一覧/一覧表/スペック比較/スペック表/主要スペック/
     * ラインナップ/商品比較」も削除キーワードに入れており、コラム記事の正規の
     * 比較表セクションを丸ごと消して内容欠落＋マーカー位置ずれを引き起こす
     * 原因になっていた。比較表はユーザーの正当なコンテンツなので削除しない。
     * 生成プロンプトで「作るな」と明示している「早見表」だけを掃除する。
     *
     * (b) の「H2直後にtable」マッチも、コラム記事の正規 H2 + table 構造を
     * 丸ごと消す副作用があったため削除した。
     */
    private static function strip_summary_table_sections($html) {
        if ($html === '' || $html === null) {
            return $html;
        }
        $text = (string)$html;

        $kw = '早見表|早分かり|早わかり|一目でわかる|一目で分かる';
        $pat_keyword = '/(?:<!--\s*wp:heading[^>]*-->\s*)?'
            . '<h2[^>]*>(?:(?!<\/h2>)[\s\S])*?(?:' . $kw . ')(?:(?!<\/h2>)[\s\S])*?<\/h2>'
            . '(?:\s*<!--\s*\/wp:heading\s*-->)?'
            . '[\s\S]*?'
            . '(?=<h2|<!--\s*wp:heading|<h3[^>]*>\s*(?:<!--\s*wp:[^>]*-->\s*)?(?:第\s*)?[\d０-９]+\s*位|$)/iu';

        for ($i = 0; $i < 2; $i++) {  // 複数早見表に対応
            $replaced = preg_replace($pat_keyword, '', $text);
            if ($replaced === null) {
                return (string)$html;
            }
            $text = $replaced;
        }
        return $text;
    }

    /** 文字列のユニーク文字（UTF-8）配列を返す。 */
    private static function unique_chars($s) {
        $chars = preg_split('//u', (string)$s, -1, PREG_SPLIT_NO_EMPTY);
        return is_array($chars) ? array_values(array_unique($chars)) : [];
    }
}
