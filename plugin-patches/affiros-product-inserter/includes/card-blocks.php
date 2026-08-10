<?php
/**
 * 商品カード／マーカーのブロック検出に共通で使うヘルパー。
 *
 * 「連続カード／マーカー検出」(admin/adjacent-cards.php) と
 * 「カード／マーカー一括削除」(admin/bulk-delete.php) の両方が使う。
 * 検出条件（どのデザインを拾うか・マーカーを含めるか）は用途ごとに違うが、
 *
 *   - <div> のネストを数えて対応する </div> を正しく見つける
 *   - class 属性から aipi-* のデザイン名を判定する
 *
 * の2点は完全に共通なのでここに置く。片方だけ直して片方が古いまま、
 * という食い違いを防ぐのが目的。
 */

if (!defined('ABSPATH')) exit;

/**
 * 開始タグ <div ...> の直後の位置から、対応する </div> の直後の
 * オフセットを返す（ネスト対応）。見つからなければ文字列末尾を返す。
 *
 * カードHTMLは <div class="aipi-compare"><div class="aipi-compare__inner">…</div></div>
 * のような入れ子構造なので、非greedy regex だと最初の </div> で切れて誤検出する。
 *
 * @param string $content
 * @param int    $open_tag_end 開始タグの「>」の次の位置
 * @return int
 */
function ai_pi_find_div_end($content, $open_tag_end) {
    $len   = strlen($content);
    $pos   = $open_tag_end;
    $depth = 1;
    $guard = 5000;

    while ($depth > 0 && $pos < $len && $guard-- > 0) {
        $next_open  = stripos($content, '<div', $pos);
        $next_close = stripos($content, '</div>', $pos);
        if ($next_close === false) break;

        if ($next_open !== false && $next_open < $next_close) {
            $depth++;
            $pos = $next_open + 4;
        } else {
            $depth--;
            if ($depth === 0) {
                return $next_close + 6;
            }
            $pos = $next_close + 6;
        }
    }

    return $len;
}

/**
 * <div ...> の属性文字列から商品カードのデザイン名を判定する。
 * 商品カードでなければ null を返す。
 *
 * 属性順が違う（<div data-x=".." class="aipi-…">）ケースも拾えるよう、
 * class 属性を狙い撃ちせず属性文字列全体から aipi-* クラスを抽出する。
 *
 * @param string $attrs       <div と > の間の文字列
 * @param bool   $legacy_too  true なら現行の生成ロジックでは出力されない
 *                            mini / proscons / score も拾う。
 *                            （削除ツールは過去に挿入されたカードも対象にするため true、
 *                              連続カード検出は現行デザインだけ見ればいいので false）
 * @return string|null vertical / compare / ranking / mini / proscons / score
 */
function ai_pi_classify_card_div($attrs, $legacy_too = false) {
    if (!preg_match_all('/(?<![a-z0-9_-])(aipi-[a-z][a-z0-9_-]*)/i', $attrs, $cm)) {
        return null;
    }

    foreach ($cm[1] as $cls) {
        $cls = strtolower($cls);

        // vertical は <div class="aipi-card aipi-card--vertical"> という2クラス構成
        if ($cls === 'aipi-card--vertical') {
            return 'vertical';
        }
        // compare / ranking は単独クラス
        if (preg_match('/^aipi-(compare|ranking)$/', $cls, $mc)) {
            return $mc[1];
        }
        // 旧デザイン（現行の生成ロジックでは出力されない）
        if ($legacy_too && preg_match('/^aipi-card--(mini|proscons|score)$/', $cls, $mc)) {
            return $mc[1];
        }
    }

    return null;
}
