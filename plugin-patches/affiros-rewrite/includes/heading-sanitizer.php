<?php
/**
 * 見出しの SEO 過剰最適化を機械的に除去する。
 *
 * Claude にプロンプトで「タイトル丸ごとコピペ禁止」「｜は最大1個」と
 * 指示しているが、稀にスルーされるので二重防御として後処理で機械的に
 * 整える。本体 app.py の以下と同等の処理:
 *   - strip_title_substring_from_headings
 *   - strip_title_prefix_from_headings
 *   - reduce_heading_separators
 *   - collapse_repeated_keyword_in_heading
 *   - trim_orphan_particles_from_heading_start
 */

if (!defined('ABSPATH')) exit;

class Affiros_Rewrite_Heading_Sanitizer {

    /**
     * 見出し品質ガードを一括適用する。
     *
     * @param string $html 整形対象の本文 HTML
     * @param string $title 記事タイトル（NULL/空なら何もしない）
     * @param string $keywords カンマ/空白区切りのキーワード（任意）
     * @return string 整形後 HTML
     */
    public static function sanitize($html, $title = '', $keywords = '') {
        if (!$html || !$title) {
            return $html;
        }
        // 2パス回すことで「1回除去 → 残った｜だけ縮約」のような連鎖を吸収
        for ($i = 0; $i < 2; $i++) {
            $html = self::strip_title_substring($html, $title);
            $html = self::strip_title_prefix($html, $title);
            $html = self::reduce_separators($html);
            $html = self::collapse_repeated_keyword($html, $title, $keywords);
        }
        $html = self::trim_orphan_particles($html);
        // 「この記事でわかること」等のメタ目次 H2 セクションを丸ごと削除
        $html = self::remove_meta_toc_sections($html);
        // 「選定基準」「選び方」等の同テーマ重複 H2 セクションを削除
        $html = self::remove_duplicate_theme_sections($html);
        // ランキング4位以降の注意点ブロック・ul を削除
        $html = self::strip_lower_rank_decorations($html);
        // まとめ後の追記パラグラフ連発を削減
        $html = self::trim_excessive_post_matome_paragraphs($html);
        return $html;
    }

    /**
     * ランキング H3 のうち 4 位以降から「注意点赤字ブロック」と
     * 「向いている人 ul」を物理削除する。1〜3 位は維持。
     */
    private static function strip_lower_rank_decorations($html) {
        if (!$html) return $html;

        // H2 / H3 の位置を全部集める（セクション境界判定用）
        preg_match_all('/<(?:h2|h3)\b[^>]*>/iu', $html, $boundary_matches, PREG_OFFSET_CAPTURE);
        $boundaries = array_map(function($m){ return $m[1]; }, $boundary_matches[0]);
        $boundaries[] = strlen($html);

        // ランキング H3 を全部走査
        if (!preg_match_all('/<h3\b[^>]*>(.*?)<\/h3>/isu', $html, $h3_matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
            return $html;
        }

        $target_labels = [
            '向いている人', '向いていない人', 'セット内容', '価格帯',
            '対応床材', '対応用途', '対応サイズ', 'サイズ・素材',
            'タイプ', '強み', '差別化ポイント', '1位との違い',
            '評価軸スコア', 'サイズ',
        ];

        $removals = [];

        foreach ($h3_matches as $m) {
            $h3_start = $m[0][1];
            $bare = trim(preg_replace('/<[^>]+>/u', '', $m[1][0]));
            if (!preg_match('/^(?:第)?([1-9][0-9]?)\s*位[:：]/u', $bare, $rm)) continue;
            $rank = intval($rm[1]);
            if ($rank <= 3) continue;

            // 次の境界（h2/h3）まで
            $next_pos = strlen($html);
            foreach ($boundaries as $bp) {
                if ($bp > $h3_start) { $next_pos = $bp; break; }
            }
            $section = substr($html, $h3_start, $next_pos - $h3_start);

            // 注意点赤字ブロック
            $notice_re = '/<p[^>]*>\s*<span[^>]*color:\s*#d32f2f[^>]*>\s*<strong>\s*注意点\s*[:：]\s*<\/strong>[\s\S]*?<\/span>\s*<\/p>/iu';
            if (preg_match_all($notice_re, $section, $nm, PREG_OFFSET_CAPTURE)) {
                foreach ($nm[0] as $nh) {
                    $removals[] = [$h3_start + $nh[1], $h3_start + $nh[1] + strlen($nh[0])];
                }
            }
            // 注意点プレーン
            $notice_plain_re = '/<p[^>]*>\s*(?:<strong>\s*)?注意点\s*[:：][\s\S]*?<\/p>/iu';
            if (preg_match_all($notice_plain_re, $section, $nm2, PREG_OFFSET_CAPTURE)) {
                foreach ($nm2[0] as $nh) {
                    $abs_start = $h3_start + $nh[1];
                    $abs_end = $abs_start + strlen($nh[0]);
                    $dup = false;
                    foreach ($removals as $r) {
                        if ($r[0] <= $abs_start && $abs_end <= $r[1]) { $dup = true; break; }
                    }
                    if (!$dup) $removals[] = [$abs_start, $abs_end];
                }
            }

            // 「向いている人 ul」
            if (preg_match_all('/<ul\b[^>]*>([\s\S]*?)<\/ul>/iu', $section, $ul_matches, PREG_OFFSET_CAPTURE)) {
                foreach ($ul_matches[0] as $idx => $um) {
                    $ul_inner = $ul_matches[1][$idx][0];
                    if (preg_match('/<li[^>]*>\s*<strong[^>]*>\s*([^<]+)\s*<\/strong>/iu', $ul_inner, $sm)) {
                        $label = trim($sm[1]);
                        foreach ($target_labels as $t) {
                            if (mb_strpos($label, $t) === 0) {
                                $removals[] = [$h3_start + $um[1], $h3_start + $um[1] + strlen($um[0])];
                                break;
                            }
                        }
                    }
                }
            }
        }

        if (empty($removals)) return $html;
        // start 降順に並べて末尾から削除
        usort($removals, function($a, $b){ return $b[0] - $a[0]; });
        $new_html = $html;
        foreach ($removals as $r) {
            $new_html = substr($new_html, 0, $r[0]) . substr($new_html, $r[1]);
        }
        return $new_html;
    }

    /**
     * 同テーマの H2 セクションが複数ある場合、2回目以降を物理削除する。
     * 「○○の選定基準」と「N選を選ぶ際の選定基準」のような重複を解消。
     */
    private static function remove_duplicate_theme_sections($html) {
        if (!$html) return $html;
        $theme_patterns = [
            'selection' => '/選定(?:基準|方法)|評価軸|評価基準|ランキング(?:の)?基準|判断軸/u',
            'howto'     => '/選び方(?!のポイント)|比較ポイント|判断基準|選定のポイント/u',
        ];
        if (!preg_match_all('/<h2\b[^>]*>(.*?)<\/h2>/isu', $html, $matches, PREG_OFFSET_CAPTURE)) {
            return $html;
        }
        $positions = $matches[0];
        $inners = $matches[1];
        if (count($positions) < 2) return $html;

        $seen_themes = [];
        $duplicates = [];
        foreach ($positions as $i => $pos) {
            $bare = trim(preg_replace('/<[^>]+>/u', '', $inners[$i][0]));
            foreach ($theme_patterns as $theme => $pat) {
                if (preg_match($pat, $bare)) {
                    if (isset($seen_themes[$theme])) {
                        $start = $pos[1];
                        $end = ($i + 1 < count($positions)) ? $positions[$i + 1][1] : strlen($html);
                        $duplicates[] = [$start, $end];
                    } else {
                        $seen_themes[$theme] = true;
                    }
                    break;
                }
            }
        }
        if (empty($duplicates)) return $html;
        foreach (array_reverse($duplicates) as $range) {
            $html = substr($html, 0, $range[0]) . substr($html, $range[1]);
        }
        return $html;
    }

    /**
     * まとめ H2 以降の <p> が 6 個以上連ねられている場合、最初の 4 個までを
     * 残してそれ以降を削除する。
     */
    private static function trim_excessive_post_matome_paragraphs($html) {
        if (!$html) return $html;
        if (!preg_match('/<h2[^>]*>[^<]*(?:まとめ|総括|結論)/iu', $html, $m, PREG_OFFSET_CAPTURE)) {
            return $html;
        }
        $matome_start = $m[0][1];
        $head = substr($html, 0, $matome_start);
        $tail = substr($html, $matome_start);

        if (!preg_match_all('/<p\b[^>]*>.*?<\/p>/isu', $tail, $pm, PREG_OFFSET_CAPTURE)) {
            return $html;
        }
        $p_positions = $pm[0];
        if (count($p_positions) <= 6) return $html;

        // 最初の 5 個までは残す。それ以降を削除。
        $keep_threshold = 5;
        $removals = [];
        foreach ($p_positions as $idx => $pos) {
            if ($idx < $keep_threshold) continue;
            $start = $pos[1];
            $end = $pos[1] + strlen($pos[0]);
            $removals[] = [$start, $end];
        }
        if (empty($removals)) return $html;
        // 末尾から削れば index が崩れない
        foreach (array_reverse($removals) as $range) {
            $tail = substr($tail, 0, $range[0]) . substr($tail, $range[1]);
        }
        return $head . $tail;
    }

    /**
     * 「この記事でわかること」「目次」のような無価値メタH2セクションを
     * H2 から次の H2 / 末尾までまるごと除去する。
     * リード文と内容が重複し SEO 的にも価値が無いため。
     */
    private static function remove_meta_toc_sections($html) {
        if (!$html) return $html;

        $meta_re = '/(?:この記事(?:で(?:わかること|学べること|得られる(?:こと|情報))?|の(?:ポイント|要点|概要|まとめ))'
                 . '|本記事(?:の(?:概要|要点|ポイント|内容))'
                 . '|目次|もくじ|読む前(?:に|の)(?:チェック|確認)'
                 . '|先に結論|結論から(?:言う|お伝え)|3行(?:で)?(?:わかる|要約))/iu';

        if (!preg_match_all('/<h2\b[^>]*>(.*?)<\/h2>/isu', $html, $matches, PREG_OFFSET_CAPTURE)) {
            return $html;
        }

        $sections_to_remove = [];
        $positions = $matches[0];
        $inners = $matches[1];
        for ($i = 0; $i < count($positions); $i++) {
            $bare = trim(preg_replace('/<[^>]+>/u', '', $inners[$i][0]));
            if (preg_match($meta_re, $bare)) {
                $start = $positions[$i][1];
                $end = ($i + 1 < count($positions)) ? $positions[$i + 1][1] : strlen($html);
                $sections_to_remove[] = [$start, $end];
            }
        }
        // 末尾から削ればインデックスがズレない
        foreach (array_reverse($sections_to_remove) as $range) {
            $html = substr($html, 0, $range[0]) . substr($html, $range[1]);
        }
        return $html;
    }

    /**
     * 見出しに含まれる「タイトルの長い substring」を除去する。
     * 完全一致だけでなく部分文字列も検出するため、タイトルの前半だけを
     * コピペされた場合（「○○の傷を防止する」など）にも有効。
     */
    private static function strip_title_substring($html, $title, $min_length = 12) {
        $norm = trim((string)$title);
        if (mb_strlen($norm) < $min_length) {
            return $html;
        }
        $sep_pattern = '[\s　]*[｜|｜:：・\-―—]+[\s　]*';

        return preg_replace_callback(
            '/(<h[23][^>]*>)(.*?)(<\/h[23]>)/isu',
            function ($m) use ($norm, $min_length, $sep_pattern) {
                $opening = $m[1];
                $inner = $m[2];
                $closing = $m[3];
                $title_len = mb_strlen($norm);
                // 長い substring から順に試す
                $max_try = min($title_len, 30);
                for ($len = $max_try; $len >= $min_length; $len--) {
                    for ($start = 0; $start <= $title_len - $len; $start++) {
                        $cand = mb_substr($norm, $start, $len);
                        if ($cand === '' || mb_strpos($inner, $cand) === false) continue;
                        $cand_q = preg_quote($cand, '/');
                        // 区切り記号と一緒に削る（後ろ or 前）
                        $new = preg_replace('/' . $cand_q . $sep_pattern . '/u', '', $inner, 1, $cnt1);
                        if ($cnt1 === 0) {
                            $new = preg_replace('/' . $sep_pattern . $cand_q . '/u', '', $inner, 1, $cnt2);
                            if ($cnt2 === 0) {
                                // 区切りが無いケースは普通に1回だけ消す
                                $pos = mb_strpos($inner, $cand);
                                $new = mb_substr($inner, 0, $pos) . mb_substr($inner, $pos + mb_strlen($cand));
                            }
                        }
                        $bare = trim(preg_replace('/<[^>]+>/u', '', $new));
                        if (mb_strlen($bare) < 2) {
                            return $m[0]; // 過剰削除になるので維持
                        }
                        return $opening . $new . $closing;
                    }
                }
                return $m[0];
            },
            $html
        );
    }

    /**
     * タイトル完全一致のプレフィックス／サフィックスを除去する。
     */
    private static function strip_title_prefix($html, $title) {
        $norm = trim((string)$title);
        if (mb_strlen($norm) < 6) {
            return $html;
        }
        $title_q = preg_quote($norm, '/');
        $sep = '[\s　]*[｜|｜:：・\-―—]+[\s　]*';
        $prefix_re = '/^' . $title_q . $sep . '/u';
        $suffix_re = '/' . $sep . $title_q . '$/u';

        return preg_replace_callback(
            '/(<h[23][^>]*>)(.*?)(<\/h[23]>)/isu',
            function ($m) use ($prefix_re, $suffix_re) {
                $opening = $m[1];
                $inner = $m[2];
                $closing = $m[3];
                $bare = trim(preg_replace('/<[^>]+>/u', '', $inner));
                $new_bare = preg_replace($prefix_re, '', $bare, 1);
                $new_bare = preg_replace($suffix_re, '', $new_bare, 1);
                if ($new_bare === $bare || mb_strlen($new_bare) < 2) {
                    return $m[0];
                }
                return $opening . $new_bare . $closing;
            },
            $html
        );
    }

    /**
     * 1見出しに「｜」が2個以上ある場合、1個目を残して残りはスペースへ。
     */
    private static function reduce_separators($html) {
        return preg_replace_callback(
            '/(<h[23][^>]*>)(.*?)(<\/h[23]>)/isu',
            function ($m) {
                $inner = $m[2];
                $bare = preg_replace('/<[^>]+>/u', '', $inner);
                $sep_count = (mb_substr_count($bare, '｜') + mb_substr_count($bare, '|'));
                if ($sep_count < 2) {
                    return $m[0];
                }
                $seen = false;
                $new_inner = preg_replace_callback(
                    '/[｜|]/u',
                    function () use (&$seen) {
                        if (!$seen) {
                            $seen = true;
                            return '｜';
                        }
                        return ' ';
                    },
                    $inner
                );
                return $m[1] . $new_inner . $m[3];
            },
            $html
        );
    }

    /**
     * 同一見出し内で同じキーワードが2回以上書かれている場合、2回目以降を削除。
     */
    private static function collapse_repeated_keyword($html, $title, $keywords) {
        $candidates = [];
        if ($title) {
            preg_match_all('/[\x{4E00}-\x{9FFF}\x{3041}-\x{3096}]{4,}/u', (string)$title, $m1);
            $candidates = array_merge($candidates, $m1[0]);
            preg_match_all('/[\x{30A1}-\x{30F6}ー]{4,}/u', (string)$title, $m2);
            $candidates = array_merge($candidates, $m2[0]);
        }
        if ($keywords) {
            foreach (preg_split('/[,、\s]+/u', (string)$keywords) as $kw) {
                $kw = trim($kw);
                if (mb_strlen($kw) >= 4) $candidates[] = $kw;
            }
        }
        $candidates = array_values(array_unique($candidates));
        usort($candidates, function ($a, $b) {
            return mb_strlen($b) - mb_strlen($a);
        });
        if (empty($candidates)) {
            return $html;
        }

        return preg_replace_callback(
            '/(<h[23][^>]*>)(.*?)(<\/h[23]>)/isu',
            function ($m) use ($candidates) {
                $new_inner = $m[2];
                foreach ($candidates as $word) {
                    $word_q = preg_quote($word, '/');
                    $positions = [];
                    $offset = 0;
                    while (preg_match('/' . $word_q . '/u', $new_inner, $found, PREG_OFFSET_CAPTURE, $offset)) {
                        $positions[] = $found[0][1];
                        $offset = $found[0][1] + strlen($found[0][0]);
                    }
                    if (count($positions) < 2) continue;
                    // 末尾から削るとインデックスがズレない
                    foreach (array_reverse(array_slice($positions, 1)) as $pos) {
                        $new_inner = substr($new_inner, 0, $pos) . substr($new_inner, $pos + strlen($word));
                    }
                }
                return $m[1] . $new_inner . $m[3];
            },
            $html
        );
    }

    /**
     * タイトル除去後に冒頭に残った孤立助詞を整理する。
     * 「で／の／に／を／が／は／と」の直後が漢字orカタカナなら削る。
     * 「でも」「では」のような正当な接続詞は誤マッチしない。
     */
    private static function trim_orphan_particles($html) {
        return preg_replace_callback(
            '/(<h[23][^>]*>)(.*?)(<\/h[23]>)/isu',
            function ($m) {
                $inner = $m[2];
                $new_inner = $inner;
                // (1) 助詞+漢字/カタカナ で始まるケース
                $new_inner = preg_replace(
                    '/^[\s　]*[でをにがはとの](?=[\x{4E00}-\x{9FFF}\x{30A1}-\x{30F6}ー])/u',
                    '',
                    $new_inner
                );
                // (2) 冒頭の区切り記号で始まったら整理
                $new_inner = preg_replace('/^[\s　]*[｜|：:・\-―—][\s　]*/u', '', $new_inner);
                // (3) 「のの」が連続したら「の」に
                $new_inner = preg_replace('/のの+/u', 'の', $new_inner);
                // (4) 連続した区切り記号「｜｜」→「｜」
                $new_inner = preg_replace('/[｜|][\s　]*[｜|]/u', '｜', $new_inner);
                // (5) 末尾の区切り取り残し
                $new_inner = preg_replace('/[\s　]*[｜|：:・\-―—][\s　]*$/u', '', $new_inner);

                if ($new_inner === $inner) {
                    return $m[0];
                }
                $bare = trim(preg_replace('/<[^>]+>/u', '', $new_inner));
                if (mb_strlen($bare) < 2) {
                    return $m[0];
                }
                return $m[1] . $new_inner . $m[3];
            },
            $html
        );
    }
}
