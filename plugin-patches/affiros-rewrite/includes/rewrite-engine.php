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
     *   - article_type ('auto'|'ranking'|'brand'|'column'|'', 任意)
     *       'auto' は本体 infer_title_article_type 準拠でタイトルから判定する
     *   - insert_markers (bool, 任意)  trueなら記事タイプ別マーカー挿入
     * @return array|WP_Error
     */
    public static function run($post_id, $opts = []) {
        $post = Affiros_Rewrite_Post_Fetcher::get_post_content($post_id);
        if (!$post) {
            return new WP_Error('post_not_found', '記事が見つかりません');
        }

        // 既存の商品カード・マーカーを除去してから Claude に渡す。
        // これによりリライトは「完全に新規」のテキストとして行え、
        // 新マーカーを設定パターン通りに置き直せる（重複・位置ズレ防止）。
        if (class_exists('Affiros_Rewrite_Pre_Cleanup')) {
            $post['content'] = Affiros_Rewrite_Pre_Cleanup::clean($post['content']);
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

        // 記事タイプを確定する。
        // 'auto'      … 本体 infer_title_article_type 準拠で元記事タイトルから判定
        // ranking 等  … その値を採用
        // ''（指定なし）… タイプ無し（マーカーも挿入しない）
        $requested_type = $merged['article_type'] ?? '';
        if ($requested_type === 'auto') {
            $article_type = Affiros_Rewrite_Article_Type::infer('', $post['title']);
        } elseif ($requested_type !== '') {
            $article_type = Affiros_Rewrite_Article_Type::normalize($requested_type, 'ranking');
        } else {
            $article_type = '';
        }
        $merged['article_type'] = $article_type;

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
        $new_title = $parsed['title'] ?: $post['title'];

        // マーカー挿入（記事タイプが確定しかつ insert_markers が true）
        $marker_stats = null;
        $marker_validation = null;
        if (!empty($opts['insert_markers']) && $article_type) {
            $ins_result = Affiros_Rewrite_Marker_Inserter::insert($content, $article_type, $new_title);
            $content = is_array($ins_result) ? ($ins_result['html'] ?? $content) : $ins_result;
            $marker_stats = is_array($ins_result) ? ($ins_result['stats'] ?? null) : null;
            if (class_exists('Affiros_Rewrite_Marker_Validator') && $marker_stats) {
                $marker_validation = Affiros_Rewrite_Marker_Validator::check(
                    $marker_stats, $article_type, $new_title
                );
            }
        }

        // Gutenberg ブロック化（Classic ブロック化を防ぐ）
        // 注: マーカー挿入の後にブロック化することで、マーカー（HTMLコメント）も
        //     ブロック区切り位置に保持される。
        if (class_exists('Affiros_Rewrite_Gutenberg')) {
            $content = Affiros_Rewrite_Gutenberg::convert($content);
        }

        return [
            'post_id' => $post_id,
            'original_title' => $post['title'],
            'original_content' => $post['content'],
            'rewritten_title' => $new_title,
            'rewritten_content' => $content,
            'usage' => $result['usage'] ?? [],
            'model' => $result['model'] ?? '',
            'article_type' => $article_type,
            'article_type_auto' => ($requested_type === 'auto'),
            'markers_inserted' => !empty($opts['insert_markers']) && $article_type,
            'marker_stats' => $marker_stats,
            'marker_validation' => $marker_validation,
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
     * 記事タイプ別の指示。本体 build_article_type_prompt の移植。
     */
    private static function article_type_prompt($article_type) {
        $prompts = [
            'ranking' => "記事種類: ランキング記事\n"
                . "- おすすめ記事・比較記事を統合した構成にする\n"
                . "- 読者が商品やサービスを選びやすいよう、選定基準、比較軸、ランキング理由を明確にする\n"
                . "- 比較表、ランキング理由、選び方、向いている人、注意点を入れる\n"
                . "- 根拠のない順位付けを避け、比較軸ごとに理由を書く\n"
                . "- ランキング表は商品名、特徴、価格帯、向いている人程度に絞り、セルを長文にしない\n"
                . "- 各商品の個別解説は順位付きのh3にし、比較表だけで終わらせない",
            'brand' => "記事種類: 商標記事（レビュー記事）\n"
                . "- 特定の商品・サービス名で検索する読者に向けたレビュー記事にする\n"
                . "- 特徴、口コミ・評判、メリット・デメリット、向いている人、購入・申込前の注意点を整理する\n"
                . "- メリットとデメリット・注意点はH2の下にH3小見出しを置き、項目ごとに本文を分ける\n"
                . "- FAQ/よくある質問セクションは原則作らず、疑問点は本文内で自然に解消する\n"
                . "- 押し売りではなく、判断材料を丁寧に提示する",
            'column' => "記事種類: コラム記事\n"
                . "- 読者の悩みや疑問に対して、自然な読み物として理解を深める構成にする\n"
                . "- 導入、背景、具体例、解決策、まとめを自然につなげる\n"
                . "- アフィリエイト導線は必要な場所にだけ控えめに入れる",
        ];
        return $prompts[$article_type] ?? '';
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
            $char_section = "\n文字数条件（重要・必ず守ること）:\n"
                . "- 本文の目標文字数は {$target} 文字（HTMLタグを除いた、読者が実際に読む文字数）。これを基準として必ず目指す。\n"
                . "- 許容範囲は {$lower}〜{$upper} 文字。{$lower} 文字を下回ってはならない。\n"
                . "- リライトは短縮作業ではない。元記事が目標より短い場合でも、"
                . "具体例・手順・根拠・データ・注意点・FAQ など読者価値のある情報を加えて {$target} 文字前後まで充実させる。\n"
                . "- ただし、文字数合わせのための水増し・同じ内容の言い換え・冗長な前置きは禁止。情報の実質で目標に届かせる。";
        } else {
            $char_section = "\n文字数条件:\n- 元記事と同等の長さを目安にする（極端な短縮・引き伸ばしは避ける）";
        }

        // 記事タイプ別の指示（本体 build_article_type_prompt 準拠）
        $type_prompt = self::article_type_prompt($opts['article_type'] ?? '');
        $type_section = $type_prompt !== '' ? "\n" . $type_prompt : '';

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
- <!--more--> などのHTMLコメントは原文の位置に残す（ただし商品カードマーカーは含めない）
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
