<?php
/**
 * リライトプロンプト生成 + 実行ロジック
 */

if (!defined('ABSPATH')) exit;

class Affiros_Rewrite_Engine {

    /** 元記事HTMLの上限。これを超える記事は末尾欠落を避けるためリライトを中断する。 */
    const MAX_SOURCE_CHARS = 30000;

    /**
     * 1記事をリライトする
     *
     * @param int $post_id
     * @param array $opts
     *   - rewrite_mode, emphasis_level, tone, target_chars, tolerance_percent
     *   - article_type ('ranking'|'brand'|'column'|'', 任意)
     *   - insert_markers (bool, 任意)  trueなら記事タイプ別マーカー挿入
     * @return array|WP_Error
     */
    public static function run($post_id, $opts = []) {
        $post = Affiros_Rewrite_Post_Fetcher::get_post_content($post_id);
        if (!$post) {
            return new WP_Error('post_not_found', '記事が見つかりません');
        }

        // 元記事が長すぎる場合、末尾を失ったまま上書きしてしまうのを防ぐため中断する
        $source_len = mb_strlen((string)$post['content']);
        if ($source_len > self::MAX_SOURCE_CHARS) {
            return new WP_Error(
                'source_too_long',
                "元記事が長すぎます（{$source_len}文字 / 上限" . self::MAX_SOURCE_CHARS . "文字）。記事を分割してから実行してください。"
            );
        }

        $settings = affiros_rewrite_get_settings();
        $merged = array_merge($settings, array_filter($opts, function ($v) {
            return $v !== '' && $v !== null;
        }));

        $prompt = self::build_prompt($post, $merged);

        // 目標文字数に応じて出力上限を決める（固定だと長文指定で途中切れする）
        $max_tokens = self::calc_max_tokens($merged['target_chars'] ?? 0);

        $api = new Affiros_Rewrite_Claude_API();
        $result = $api->complete($prompt, $max_tokens);
        if (is_wp_error($result)) {
            return $result;
        }

        // 出力が max_tokens で打ち切られた = 記事が途中で切れている → 保存させない
        if (($result['stop_reason'] ?? '') === 'max_tokens') {
            return new WP_Error(
                'output_truncated',
                'リライト結果が出力上限に達し途中で切れました。目標文字数を下げて再実行してください。'
            );
        }

        $parsed = self::parse_output($result['text']);

        // 指定フォーマット（===TITLE===/===CONTENT===）で返らなかった場合、
        // 前置き等が混入したテキストをそのまま記事へ保存しないよう失敗扱いにする
        if (!$parsed['ok']) {
            return new WP_Error(
                'parse_failed',
                'リライト結果が想定したフォーマットで返りませんでした。再実行してください。'
            );
        }
        $content = $parsed['content'];

        // マーカー挿入（記事タイプが指定されかつ insert_markers が true）
        $article_type = $merged['article_type'] ?? '';
        if (!empty($opts['insert_markers']) && $article_type) {
            $content = Affiros_Rewrite_Marker_Inserter::insert($content, $article_type);
        }

        return [
            'post_id' => $post_id,
            'original_title' => $post['title'],
            'original_content' => $post['content'],
            'rewritten_title' => $parsed['title'] ?: $post['title'],
            'rewritten_content' => $content,
            'usage' => $result['usage'] ?? [],
            'model' => $result['model'] ?? '',
            'article_type' => $article_type,
            'markers_inserted' => !empty($opts['insert_markers']) && $article_type,
        ];
    }

    /**
     * 目標文字数から出力 max_tokens を見積もる。
     * 日本語HTMLは概ね 1文字 ≒ 1トークン強。安全側に倍以上を確保する。
     */
    private static function calc_max_tokens($target_chars) {
        $target = intval($target_chars);
        if ($target <= 0) {
            return 8000; // 「元記事に合わせる」指定 → 従来どおりの既定値
        }
        $est = (int)ceil($target * 2.5) + 1000;
        return max(2000, min(32000, $est));
    }

    /**
     * Claude へ投げる prompt
     */
    private static function build_prompt($post, $opts) {
        $mode_map = [
            'seo' => 'SEO観点で検索意図を満たし、見出し構造・キーワード網羅性を強化する',
            'readability' => '読みやすさを最優先に、段落分け・改行・冗長表現の整理に重点を置く',
            'freshness' => '古い情報・時系列表現を最新の感覚に更新し、現在性のある記事に整える',
        ];
        $emphasis_map = [
            'light' => '太字・マーカーは控えめに、本当に重要な箇所のみ',
            'standard' => '太字・マーカー・赤字・リスト・表を適度に使い、読みやすく整える',
            'strong' => '太字・マーカー・赤字・リスト・表を積極的に使い、視覚的にメリハリを出す',
        ];
        $tone_map = [
            'natural' => '自然で読みやすい文体（ですます調を基本に、堅すぎず柔らかすぎず）',
            'professional' => '丁寧で信頼感のある専門家風の文体',
            'casual' => '親しみやすく話しかけるようなカジュアル文体',
        ];

        $mode = $mode_map[$opts['rewrite_mode']] ?? $mode_map['seo'];
        $emphasis = $emphasis_map[$opts['emphasis_level']] ?? $emphasis_map['standard'];
        $tone = $tone_map[$opts['tone']] ?? $tone_map['natural'];

        $char_section = '';
        $target = intval($opts['target_chars'] ?? 0);
        $tolerance = max(0, min(50, intval($opts['tolerance_percent'] ?? 10)));
        if ($target > 0) {
            $lower = max(1, (int)($target * (100 - $tolerance) / 100));
            $upper = (int)($target * (100 + $tolerance) / 100);
            $char_section = "\n文字数条件:\n- 目標 {$target} 文字（許容範囲 ±{$tolerance}%）\n- {$lower}〜{$upper} 文字を目安にする\n- 文字数を優先しすぎて不自然にしない";
        } else {
            $char_section = "\n文字数条件:\n- 元記事と同等の長さを目安にする（極端な短縮・引き伸ばしは避ける）";
        }

        $article_type = $opts['article_type'] ?? '';
        $type_section = '';
        if ($article_type === 'ranking') {
            $type_section = "\n記事タイプ: ランキング記事\n- 比較・おすすめ視点で構成する\n- 商品紹介セクションは h3 単位で区切る（後段で商品カードを挿入するため）";
        } elseif ($article_type === 'brand') {
            $type_section = "\n記事タイプ: 商標（レビュー）記事\n- 1商品に絞り、メリット・デメリット・実体験ベースの記述を意識";
        } elseif ($article_type === 'column') {
            $type_section = "\n記事タイプ: コラム記事\n- 読み物として自然に流れる構成。最後にまとめセクションを置く";
        }

        $original_title = $post['title'];
        $original_content = mb_substr((string)$post['content'], 0, self::MAX_SOURCE_CHARS);

        $prompt = <<<PROMPT
以下のWordPress記事をリライトしてください。

リライト方針:
- {$mode}
- {$tone}
- {$emphasis}
- 元記事の事実関係、固有名詞、商品名、価格などの数値情報は保持する
- 重複表現や冗長な段落を整理する
- WordPress本文として使えるHTML形式で出力する（h2, h3, p, ul, ol, strong, em, span class="marker" など）
- 既存の <!--ai-product:...--> や <!--more--> などのHTMLコメントは原文の位置に残す
- WordPressショートコード（[xxx]）はそのまま残す
{$type_section}
{$char_section}

出力フォーマット（必ずこの形式で出力すること）:
===TITLE===
（新しいタイトル。元のタイトルから大きく外れないこと）
===CONTENT===
（リライト済みのHTML本文。記事本文のみ。説明文・前置きは不要）

---
元記事タイトル:
{$original_title}

元記事HTML:
{$original_content}
PROMPT;

        return $prompt;
    }

    /**
     * Claude の出力をタイトルと本文に分解する。
     *
     * @return array { ok: bool, title: string, content: string }
     *   ok=false は指定フォーマットで返らなかったことを示す（保存させない）。
     */
    private static function parse_output($text) {
        $title = '';
        $content = '';
        $ok = false;

        if (preg_match('/===TITLE===\s*(.*?)\s*===CONTENT===\s*(.*)$/su', $text, $m)) {
            $title = trim($m[1]);
            $content = trim($m[2]);
            $ok = ($content !== '');
        }
        if ($ok && preg_match('/^```(?:html)?\s*(.*?)\s*```$/su', $content, $m)) {
            $content = trim($m[1]);
        }
        return ['ok' => $ok, 'title' => $title, 'content' => $content];
    }
}
