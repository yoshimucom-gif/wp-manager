<?php
/**
 * リライト前クリーンアップ
 *
 * 既存の商品カード HTML・マーカーを記事本文から削除する。
 * これによりリライトは「完全に新規」のテキストとして行え、
 * 新しいマーカーを設定パターン通りに置き直せる。
 *
 * 削除対象:
 *   1. 商品カード本体:
 *      - <div class="aipi-card ...">...</div>
 *      - <div class="aipi-compare">...</div>
 *      - <div class="aipi-ranking">...</div>
 *   2. それらを包む Gutenberg ブロック:
 *      <!-- wp:html -->  ...商品カード...  <!-- /wp:html -->
 *   3. 未処理マーカー:
 *      <!--ai-product:...-->
 */

if (!defined('ABSPATH')) exit;

class Affiros_Rewrite_Pre_Cleanup {

    /**
     * @param string $html
     * @return string
     */
    public static function clean($html) {
        if ($html === null || $html === '') return (string)$html;
        $html = (string)$html;

        // 1) wp:html ブロックでラップされた商品カードを丸ごと除去
        //    （wp:html の中身が aipi-* で始まるものだけ）
        $html = preg_replace_callback(
            '/<!--\s*wp:html\s*-->\s*([\s\S]*?)\s*<!--\s*\/wp:html\s*-->/i',
            function ($m) {
                $inner = trim($m[1]);
                if (preg_match('/^<div\s+class="aipi-/i', $inner)) {
                    return '';
                }
                return $m[0];
            },
            $html
        ) ?: $html;

        // 2) aipi-* で始まる div ブロックを balanced-div matching で除去
        $html = self::strip_aipi_divs($html);

        // 3) 未処理マーカーを除去（リライト後に新規で置くため）
        // v0.4.49: regex を広く取り直し。
        // 旧: /<!--\s*ai-product(?::[a-z]+(?::[a-z0-9]+)?)?\s*-->/i
        //     → コロン2個までしか許容せず、3コロン以上や変則形式が消せず残っていた
        // 新: /<!--[^>]*?ai-product[^>]*?-->/i
        //     → 「ai-product を含むHTMLコメント」を無条件で削除
        //     → insert_markers_new の検出 regex と挙動を揃える（詰み状態撲滅）
        $html = preg_replace('/<!--[^>]*?ai-product[^>]*?-->/i', '', $html);

        // 4) Gutenberg ブロック区切りコメントを除去
        //    <!-- wp:paragraph --> <!-- /wp:paragraph --> 等。残しても Claude には
        //    意味がない上、生HTML長を 5〜6 倍に膨らませて MAX_SOURCE_CHARS 判定を
        //    狂わせる。出力側は gutenberg-converter.php が再生成する。
        $html = preg_replace('/<!--\s*\/?wp:[a-zA-Z0-9\/\-]+(?:\s+\{[^}]*\})?\s*\/?-->/', '', $html);

        // 5) 連続改行を整理
        $html = preg_replace("/(\r?\n){3,}/", "\n\n", $html);

        return trim((string)$html);
    }

    /**
     * <div class="aipi-..."> を balanced-div matching で削除する。
     * preg_replace では入れ子に対応できないため自前で開閉カウントする。
     */
    private static function strip_aipi_divs($html) {
        $offset = 0;
        $loop_guard = 100; // 無限ループ防止
        while ($loop_guard-- > 0) {
            // class が aipi- で始まる div の開始位置を探す
            if (!preg_match('/<div\s+class="aipi-[^"]*"[^>]*>/i', $html, $m, PREG_OFFSET_CAPTURE, $offset)) {
                break;
            }
            $start = $m[0][1];
            $open_len = strlen($m[0][0]);
            $end = self::find_matching_div_close($html, $start + $open_len);
            if ($end === null) {
                // 閉じタグが見つからない → 無理に削らず終了
                break;
            }
            // <div...>...</div> を削除（行末改行も巻き取る）
            $close_len = strlen('</div>');
            $segment_len = $end + $close_len - $start;
            // 直後の \n も巻き取る（連続改行を防ぐ）
            if (substr($html, $start + $segment_len, 1) === "\n") {
                $segment_len++;
            }
            $html = substr($html, 0, $start) . substr($html, $start + $segment_len);
            // offset は削除後の同じ位置から再開
            $offset = $start;
        }
        return $html;
    }

    /**
     * $start_pos から始めて、対応する </div> の位置を返す。見つからなければ null。
     */
    private static function find_matching_div_close($html, $start_pos) {
        $depth = 1;
        $pos = $start_pos;
        $len = strlen($html);
        while ($pos < $len) {
            $open = stripos($html, '<div', $pos);
            $close = stripos($html, '</div>', $pos);
            if ($close === false) return null;
            if ($open !== false && $open < $close) {
                $depth++;
                $pos = $open + 4;
            } else {
                $depth--;
                if ($depth === 0) {
                    return $close;
                }
                $pos = $close + 6;
            }
        }
        return null;
    }
}
