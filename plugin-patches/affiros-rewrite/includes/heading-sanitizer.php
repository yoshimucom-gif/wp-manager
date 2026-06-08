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
        return $html;
    }

    /**
     * 見出しに含まれる「タイトルの長い substring」を除去する。
     * 完全一致だけでなく部分文字列も検出するため、タイトルの前半だけを
     * コピペされた場合（「○○の傷を防止する」など）にも有効。
     */
    private static function strip_title_substring($html, $title, $min_length = 8) {
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
                // 助詞+漢字/カタカナ で始まるケース
                $new_inner = preg_replace(
                    '/^[\s　]*[でをにがはとの](?=[\x{4E00}-\x{9FFF}\x{30A1}-\x{30F6}ー])/u',
                    '',
                    $inner
                );
                // 区切り記号で始まったら整理
                $new_inner = preg_replace('/^[\s　]*[｜|：:・\-―—][\s　]*/u', '', $new_inner);
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
