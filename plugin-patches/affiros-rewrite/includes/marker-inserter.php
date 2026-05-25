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
     * 本体 DEFAULT_CARD_INSERTION_PATTERNS と一致させること。
     */
    public static function default_patterns() {
        return [
            'ranking' => [
                ['position' => 'after_each_h3_rank', 'design' => 'vertical',  'repeat' => 1],
                ['position' => 'after_last_h2',      'design' => 'compare',   'count'  => 5],
            ],
            'column' => [
                ['position' => 'before_first_h2',    'design' => 'compare',   'count'  => 3],
                ['position' => 'after_last_h2',      'design' => 'compare',   'count'  => 3],
            ],
            'brand' => [
                ['position' => 'after_first_h2',     'design' => 'vertical',  'repeat' => 1],
                ['position' => 'after_last_h2',      'design' => 'vertical',  'repeat' => 1],
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
     * 記事タイプに応じてマーカーを挿入する。本体 insert_card_markers の移植。
     *
     * @param string $html         リライト後のHTML
     * @param string $article_type 'ranking' | 'brand' | 'column'
     * @param string $title        記事タイトル（先頭introH2判定に使用）
     * @return string  マーカー挿入後のHTML
     */
    public static function insert($html, $article_type, $title = '') {
        if ($html === '' || $html === null) {
            return $html;
        }
        $patterns = self::get_patterns();
        $rules = $patterns[$article_type] ?? [];
        if (!$rules) {
            return $html;
        }

        $text = (string)$html;

        // 本体 insert_card_markers と同じ前処理:
        // 先頭の導入H2を削除し、早見表/比較表セクションを削除する。
        $text = self::strip_leading_introduction_h2($text, $title);
        $text = self::strip_summary_table_sections($text);

        $matome_range = self::find_matome_h2_range($text);
        $first_h2_range = self::find_first_h2_range($text);
        $last_h2_range  = self::find_last_h2_range($text);

        // [挿入バイト位置, 挿入文字列] のリスト
        $insertions = [];

        foreach ($rules as $rule) {
            $pos = $rule['position'] ?? '';
            $design = $rule['design'] ?? 'vertical';
            $count = $rule['count'] ?? null;
            $repeat = max(1, intval($rule['repeat'] ?? 1));
            $marker = self::build_marker($design, $count);
            $marker_block = str_repeat("\n" . $marker, $repeat);

            if ($pos === 'top') {
                $insertions[] = [0, $marker_block . "\n"];
            } elseif ($pos === 'bottom') {
                $insertions[] = [strlen($text), "\n" . $marker_block];
            } elseif ($pos === 'before_first_h2' && $first_h2_range) {
                $insertions[] = [$first_h2_range[0], $marker_block . "\n"];
            } elseif ($pos === 'after_first_h2' && $first_h2_range) {
                $insertions[] = [$first_h2_range[1], "\n" . $marker_block];
            } elseif ($pos === 'before_matome_h2' && $matome_range) {
                $insertions[] = [$matome_range[0], $marker_block . "\n"];
            } elseif ($pos === 'after_matome_h2' && $matome_range) {
                $insertions[] = [$matome_range[1], "\n" . $marker_block];
            } elseif ($pos === 'after_last_h2' && $last_h2_range) {
                $insertions[] = [$last_h2_range[1], "\n" . $marker_block];
            } elseif ($pos === 'after_each_h3_rank') {
                foreach (self::collect_h3_rank_insertions($text, $marker, $matome_range, $first_h2_range) as $ins) {
                    $insertions[] = $ins;
                }
            }
        }

        // 後ろから挿入してバイト位置のズレを防ぐ
        usort($insertions, function ($a, $b) { return $b[0] - $a[0]; });
        foreach ($insertions as $ins) {
            $text = substr($text, 0, $ins[0]) . $ins[1] . substr($text, $ins[0]);
        }
        return $text;
    }

    /**
     * プラグイン用マーカー文字列を組み立てる。本体 _build_marker の移植。
     * count が指定される設計: compare / ranking / proscons / mini
     */
    private static function build_marker($design = 'vertical', $count = null) {
        if (!$design || $design === 'default') {
            return '<!--ai-product-->';
        }
        $count_designs = ['compare', 'ranking', 'proscons', 'mini'];
        if (in_array($design, $count_designs, true) && $count) {
            return '<!--ai-product:' . $design . ':' . intval($count) . '-->';
        }
        return '<!--ai-product:' . $design . '-->';
    }

    /**
     * 「まとめ」を含むH2の [開始, 終了] バイト位置を返す。無ければ null。
     * 本体 _find_matome_h2_range の移植。
     */
    private static function find_matome_h2_range($html) {
        $re = '/<h2[^>]*>(?:(?!<\/h2>)[\s\S])*?(?:まとめ|総まとめ|結論|要点)(?:(?!<\/h2>)[\s\S])*?<\/h2>/iu';
        if (preg_match($re, $html, $m, PREG_OFFSET_CAPTURE)) {
            $start = $m[0][1];
            return [$start, $start + strlen($m[0][0])];
        }
        return null;
    }

    /**
     * 記事の最初のH2の [開始, 終了] バイト位置を返す。無ければ null。
     * 本体 _find_first_h2_range の移植。
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
     * 記事の最後のH2の [開始, 終了] バイト位置を返す。無ければ null。
     * まとめH2が存在する場合はそれを返す（= after_matome_h2 と同等）。
     * まとめH2が無い場合は最後のH2を探す。
     */
    private static function find_last_h2_range($html) {
        // まず まとめH2 を試みる（after_matome_h2 との一貫性）
        $matome = self::find_matome_h2_range($html);
        if ($matome) {
            return $matome;
        }
        // 全H2を探して最後を返す
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
    private static function collect_h3_rank_insertions($text, $marker, $matome_range, $first_h2_range) {
        // 「第N位」「No.N」「①②③」など各種フォーマットのランキングH3
        $h3_patterns = [
            '/<h3[^>]*>\s*(?:第\s*)?(?:\d+|[０-９]+)\s*位[\s:：、・　]*[^<]*?<\/h3>/iu',
            '/<h3[^>]*>\s*No\.?\s*(?:\d+|[０-９]+)[\s:：、・　]*[^<]*?<\/h3>/iu',
            '/<h3[^>]*>\s*[①②③④⑤⑥⑦⑧⑨⑩][\s:：、・　]*[^<]*?<\/h3>/iu',
        ];
        $insertions = [];
        $seen = [];
        foreach ($h3_patterns as $re) {
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
        // ゼロ件なら、最初のH2より後・まとめH2より前の全H3をランキングH3とみなしフォールバック
        if (!$seen) {
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

        $intro_keywords = [
            'とは', '結論', '選ぶポイント', '選定ポイント', '本記事の', '解説',
            'について', 'を知る', '記事のポイント',
            '完全ガイド', '完全攻略', '徹底ガイド', '徹底解説', '徹底比較',
            'おすすめ', '比較', 'ランキング', '選び方', '選定基準',
        ];
        $is_intro = false;
        foreach ($intro_keywords as $kw) {
            if (mb_strpos($h2_inner, $kw) !== false) {
                $is_intro = true;
                break;
            }
        }

        // タイトルとの類似度（タイトル繰り返しH2の対策）
        if (!$is_intro && $title !== '') {
            $norm = function ($s) {
                return mb_strtolower(preg_replace('/[\s\|｜・:：－—\-　]+/u', '', (string)$s), 'UTF-8');
            };
            $nt = $norm($title);
            $nh = $norm($h2_inner);
            if ($nt !== '' && $nh !== '') {
                if (mb_strpos($nt, $nh) !== false || mb_strpos($nh, $nt) !== false) {
                    $is_intro = true;
                } else {
                    $t_chars = self::unique_chars($nt);
                    $h_chars = self::unique_chars($nh);
                    $common = 0;
                    foreach ($t_chars as $c) {
                        if (in_array($c, $h_chars, true)) {
                            $common++;
                        }
                    }
                    $ratio = $common / max(1, count($t_chars));
                    if ($ratio >= 0.7) {
                        $is_intro = true;
                    }
                }
            }
        }

        if (!$is_intro) {
            return $text;
        }
        return ltrim(substr($text, $match_end));
    }

    /**
     * サマリー/比較系のH2セクションを削除する。
     * 本体 strip_summary_table_sections の移植。
     */
    private static function strip_summary_table_sections($html) {
        if ($html === '' || $html === null) {
            return $html;
        }
        $text = (string)$html;

        // (a) 比較・要約系キーワードを含むH2のセクション削除
        $kw = '早見表|比較表|比較一覧|一覧表|スペック比較|スペック表|主要スペック|ラインナップ|商品比較|一目で|早分かり|早わかり';
        $pat_keyword = '/(?:<!--\s*wp:heading[^>]*-->\s*)?'
            . '<h2[^>]*>(?:(?!<\/h2>)[\s\S])*?(?:' . $kw . ')(?:(?!<\/h2>)[\s\S])*?<\/h2>'
            . '(?:\s*<!--\s*\/wp:heading\s*-->)?'
            . '[\s\S]*?'
            . '(?=<h2|<!--\s*wp:heading|<h3[^>]*>\s*(?:<!--\s*wp:[^>]*-->\s*)?(?:第\s*)?[\d０-９]+\s*位|$)/iu';
        // (b) H2直後に table が来ているセクションも削除
        $pat_table = '/(?:<!--\s*wp:heading[^>]*-->\s*)?'
            . '<h2[^>]*>(?:(?!<\/h2>)[\s\S])*?<\/h2>'
            . '(?:\s*<!--\s*\/wp:heading\s*-->)?'
            . '\s*(?:<!--\s*wp:[^>]*-->\s*)?\s*<table\b[\s\S]*?<\/table>'
            . '(?:\s*<!--\s*\/wp:[^>]*-->)?'
            . '[\s\S]*?'
            . '(?=<h2|<!--\s*wp:heading|<h3[^>]*>\s*(?:<!--\s*wp:[^>]*-->\s*)?(?:第\s*)?[\d０-９]+\s*位|$)/iu';

        // 各パターンを2回適用（複数セクション対策）。
        // preg_replace は失敗時（バックトラック上限等）に null を返すので、
        // その場合は前処理を諦めて元のHTMLを保つ。
        foreach ([$pat_keyword, $pat_table] as $pat) {
            for ($i = 0; $i < 2; $i++) {
                $replaced = preg_replace($pat, '', $text);
                if ($replaced === null) {
                    return (string)$html;
                }
                $text = $replaced;
            }
        }
        return $text;
    }

    /** 文字列のユニーク文字（UTF-8）配列を返す。 */
    private static function unique_chars($s) {
        $chars = preg_split('//u', (string)$s, -1, PREG_SPLIT_NO_EMPTY);
        return is_array($chars) ? array_values(array_unique($chars)) : [];
    }
}
