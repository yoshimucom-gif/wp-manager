<?php
/**
 * 装飾済みHTMLのバリデーション
 */

if (!defined('ABSPATH')) exit;

class AI_Deco_Validator {

    /**
     * 装飾済みHTMLを検証
     * @return array ['status' => 'ok|warning|error', 'errors' => [...], 'metrics' => [...]]
     */
    public static function validate($original, $decorated) {
        $errors = [];
        $warnings = [];

        // 1. Gutenbergブロックのペアチェック
        preg_match_all('/<!-- wp:(\S+)[^>]*-->/', $decorated, $opens);
        preg_match_all('/<!-- \/wp:(\S+) -->/', $decorated, $closes);

        $open_count = count($opens[1]);
        $close_count = count($closes[1]);

        if ($open_count !== $close_count) {
            $errors[] = "Gutenbergブロックの開始({$open_count})/終了({$close_count})が不一致";
        }

        // 2. divタグの整合性
        $div_open = substr_count($decorated, '<div');
        $div_close = substr_count($decorated, '</div>');
        if ($div_open !== $div_close) {
            $errors[] = "divタグが不一致（開始:{$div_open} / 終了:{$div_close}）";
        }

        // 3. JSON属性の妥当性（ネストJSON対応の手書きパーサ）
        $json_errors = self::validate_block_json($decorated);
        foreach ($json_errors as $err) {
            $errors[] = $err;
            if (count($errors) > 5) break; // エラー多数時は打ち切り
        }

        // 4. 文字数の極端な変化チェック
        $original_text = wp_strip_all_tags($original);
        $decorated_text = wp_strip_all_tags($decorated);
        $orig_len = mb_strlen($original_text);
        $deco_len = mb_strlen($decorated_text);

        $ratio = $orig_len > 0 ? $deco_len / $orig_len : 0;

        if ($ratio < 0.7) {
            $errors[] = "本文が大幅に減少しています（元の" . round($ratio * 100) . "%）";
        } elseif ($ratio < 0.85) {
            $warnings[] = "本文がやや減少しています（元の" . round($ratio * 100) . "%）";
        } elseif ($ratio > 2.0) {
            $warnings[] = "本文が大幅に増加しています（元の" . round($ratio * 100) . "%）";
        }

        // 5. 見出しの保持チェック
        preg_match_all('/<h2[^>]*>(.*?)<\/h2>/', $original, $orig_h2);
        preg_match_all('/<h2[^>]*>(.*?)<\/h2>/', $decorated, $deco_h2);
        $orig_h2_count = count($orig_h2[1]);
        $deco_h2_count = count($deco_h2[1]);

        if ($orig_h2_count !== $deco_h2_count) {
            // FAQ追加で増えるケースもあるので、減ったときだけエラー
            if ($deco_h2_count < $orig_h2_count) {
                $errors[] = "H2見出しが減少（元:{$orig_h2_count} / 装飾後:{$deco_h2_count}）";
            }
        }

        // ステータス判定
        if (!empty($errors)) {
            $status = 'error';
        } elseif (!empty($warnings)) {
            $status = 'warning';
        } else {
            $status = 'ok';
        }

        return [
            'status' => $status,
            'errors' => $errors,
            'warnings' => $warnings,
            'metrics' => [
                'original_length' => $orig_len,
                'decorated_length' => $deco_len,
                'ratio' => round($ratio, 2),
                'h2_count_original' => $orig_h2_count,
                'h2_count_decorated' => $deco_h2_count,
            ],
        ];
    }

    /**
     * 各 <!-- wp:xxx {...} --> ブロックの属性JSONをネスト対応で抽出・検証
     */
    private static function validate_block_json($content) {
        $errors = [];
        $pos = 0;
        $len = strlen($content);

        while ($pos < $len) {
            $start = strpos($content, '<!-- wp:', $pos);
            if ($start === false) break;

            $end = strpos($content, '-->', $start);
            if ($end === false) break;

            $tag = substr($content, $start, $end - $start);

            // 開始ブロックタグ内の最初の `{` を探す（属性JSONの開始）
            $brace_start = strpos($tag, '{');
            if ($brace_start !== false) {
                $json = self::extract_balanced_json(substr($tag, $brace_start));
                if ($json !== null) {
                    json_decode($json);
                    if (json_last_error() !== JSON_ERROR_NONE) {
                        $errors[] = "ブロック属性JSONが不正: " . substr($json, 0, 60) . '...';
                    }
                } else {
                    // 対応する閉じブレースが見つからない
                    $errors[] = "ブロック属性のブレース不整合: " . substr($tag, 0, 60) . '...';
                }
            }

            $pos = $end + 3;
        }

        return $errors;
    }

    /**
     * 文字列の先頭の `{` から対応する `}` までを抽出（ネスト対応・文字列リテラル考慮）
     */
    private static function extract_balanced_json($str) {
        if ($str === '' || $str[0] !== '{') return null;

        $depth = 0;
        $in_string = false;
        $escape = false;
        $len = strlen($str);

        for ($i = 0; $i < $len; $i++) {
            $ch = $str[$i];

            // 直前がエスケープなら今の文字はスキップ
            if ($escape) {
                $escape = false;
                continue;
            }

            // 文字列リテラル内の処理
            if ($in_string) {
                if ($ch === '\\') {
                    $escape = true;
                } elseif ($ch === '"') {
                    $in_string = false;
                }
                continue;
            }

            // 文字列リテラル外
            if ($ch === '"') {
                $in_string = true;
                continue;
            }

            if ($ch === '{') {
                $depth++;
            } elseif ($ch === '}') {
                $depth--;
                if ($depth === 0) {
                    return substr($str, 0, $i + 1);
                }
            }
        }

        return null;
    }
}
