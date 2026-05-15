<?php
/**
 * リライトプロンプト生成 + 実行ロジック
 * Claude API へ投げる prompt を組み立て、結果を返す
 */

if (!defined('ABSPATH')) exit;

class Affiros_Rewrite_Engine {

    /**
     * 1記事をリライトする
     *
     * @param int $post_id
     * @param array $opts （UIから来る上書き値）
     *   - rewrite_mode, emphasis_level, tone, target_chars, tolerance_percent
     * @return array|WP_Error
     *   { post_id, original_title, original_content, rewritten_title, rewritten_content, usage }
     */
    public static function run($post_id, $opts = []) {
        $post = Affiros_Rewrite_Post_Fetcher::get_post_content($post_id);
        if (!$post) {
            return new WP_Error('post_not_found', '記事が見つかりません');
        }

        $settings = affiros_rewrite_get_settings();
        $merged = array_merge($settings, array_filter($opts, function ($v) {
            return $v !== '' && $v !== null;
        }));

        $prompt = self::build_prompt($post, $merged);

        $api = new Affiros_Rewrite_Claude_API();
        $result = $api->complete($prompt, 8000);
        if (is_wp_error($result)) {
            return $result;
        }

        $parsed = self::parse_output($result['text']);

        return [
            'post_id' => $post_id,
            'original_title' => $post['title'],
            'original_content' => $post['content'],
            'rewritten_title' => $parsed['title'] ?: $post['title'],
            'rewritten_content' => $parsed['content'],
            'usage' => $result['usage'] ?? [],
            'model' => $result['model'] ?? '',
        ];
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

        $original_title = $post['title'];
        // HTML を保持したまま prompt へ。長すぎる場合は冒頭 30000 文字に切る
        $original_content = mb_substr((string)$post['content'], 0, 30000);

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
     * Claude 出力を title / content に分割
     */
    private static function parse_output($text) {
        $title = '';
        $content = $text;

        if (preg_match('/===TITLE===\s*(.*?)\s*===CONTENT===\s*(.*)$/su', $text, $m)) {
            $title = trim($m[1]);
            $content = trim($m[2]);
        }

        // Claude が ```html ... ``` で囲ってきた場合は剥がす
        if (preg_match('/^```(?:html)?\s*(.*?)\s*```$/su', $content, $m)) {
            $content = $m[1];
        }

        return ['title' => $title, 'content' => $content];
    }
}
