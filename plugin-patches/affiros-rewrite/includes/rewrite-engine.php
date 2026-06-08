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

        // === N選 スケール ===
        // ユーザーが target_chars を明示していない（=0「元記事に合わせる」）場合のみ、
        // タイトルから N選を読み取って自動的に下限を引き上げる。
        // ※冗長を避けるため上限は適正密度の130%でクランプ。
        if (intval($merged['target_chars'] ?? 0) <= 0) {
            $rc = Affiros_Rewrite_Article_Type::extract_ranking_count($post['title']);
            if ($rc && $rc > 5) {
                $source_chars = mb_strlen(trim(strip_tags($post['content'])));
                $n_target = min(14000, 3000 + ($rc - 5) * 500);
                // 元記事冗長を引き継がないよう適正密度の 1.3 倍を上限とする
                $merged['target_chars'] = min(
                    max($source_chars, $n_target),
                    (int)($n_target * 1.3)
                );
            }
        }

        // === 商品コンテキスト取得（B方式・product-inserter 連携） ===
        // ranking 記事の場合、商品挿入プラグインの AI_PI_Product_Selector を呼んで
        // Amazon/楽天の候補商品を取得し、Claude プロンプトに含める。
        // これにより Claude は「実在する商品」を見ながら H3 を書けるため、
        // 商品挿入時に H3 と挿入商品がミスマッチする事故を防げる。
        // product-inserter プラグインが無効/未インストールの場合は静かにスキップ。
        $product_candidates = [];
        if ($article_type === 'ranking' && class_exists('AI_PI_Product_Selector')) {
            $search_keyword = self::resolve_product_search_keyword($post, $merged);
            if ($search_keyword !== '') {
                $rc_for_pool = Affiros_Rewrite_Article_Type::extract_ranking_count($post['title']) ?: 5;
                // N選数より多めに候補を取って Claude が選びやすくする
                $per_source = min(15, max(8, $rc_for_pool + 3));
                try {
                    $product_candidates = AI_PI_Product_Selector::fetch_candidates($search_keyword, $per_source);
                } catch (Exception $e) {
                    $product_candidates = [];
                    error_log('[affiros-rewrite] 商品候補取得失敗: ' . $e->getMessage());
                }
            }
        }

        $prompt = self::build_prompt($post, $merged, $product_candidates);

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

        // === 見出し品質ガード（後処理） ===
        // タイトル丸ごとコピペ・｜の乱用・キーワード重複・孤立助詞を機械的に整える。
        // プロンプト指示と二重防御の関係。
        if (class_exists('Affiros_Rewrite_Heading_Sanitizer')) {
            $content = Affiros_Rewrite_Heading_Sanitizer::sanitize(
                $content,
                $new_title,
                $post['title'] // 元記事タイトルもキーワード候補として扱う
            );
        }

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
            'product_candidates_count' => count($product_candidates),
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
     * 商品検索用のキーワードを記事メタ（タイトル等）から解決する。
     *
     * 優先順位:
     *   1. opts.ad_keywords が指定されていればそれを使う
     *   2. opts.keywords があれば先頭の1個を使う
     *   3. タイトルから「N選」「おすすめ」等の修飾語を除いた核を抽出
     */
    private static function resolve_product_search_keyword($post, $opts) {
        $ad = trim((string)($opts['ad_keywords'] ?? ''));
        if ($ad !== '') {
            $first = trim(preg_split('/[,、]/u', $ad)[0]);
            if ($first !== '') return $first;
        }
        $kw = trim((string)($opts['keywords'] ?? ''));
        if ($kw !== '') {
            $first = trim(preg_split('/[,、]/u', $kw)[0]);
            if ($first !== '') return $first;
        }
        $title = (string)($post['title'] ?? '');
        // 「【2026年版】」「おすすめ」「商品N選」「N選」等の評価語・修飾語を除去
        $cleaned = preg_replace(
            '/【[^】]*】|\[[^\]]*\]|（[^）]*）|\([^)]*\)|'
            . '(?:おすすめ|ベスト|人気|厳選|まとめ|完全ガイド|徹底比較|'
            . '解説|紹介|レビュー|2[0-9]{3}年版|最新)|'
            . '[0-9０-9]+\s*選/u',
            ' ',
            $title
        );
        $cleaned = trim(preg_replace('/\s+/u', ' ', $cleaned));
        return $cleaned;
    }

    /**
     * 取得した商品候補リストを Claude プロンプト用のテキストに整形する。
     */
    private static function format_product_candidates_section($candidates) {
        if (empty($candidates)) {
            return '';
        }
        // 取得しすぎないよう最大 15 件に絞る
        $candidates = array_slice($candidates, 0, 15);
        $lines = [];
        foreach ($candidates as $idx => $p) {
            $no = $idx + 1;
            $source = $p['source'] ?? '?';
            $brand = $p['brand'] ?? '';
            $title = $p['title'] ?? '';
            $price = isset($p['price']) ? number_format((float)$p['price']) : '?';
            $rating = isset($p['rating']) ? sprintf('%.1f', (float)$p['rating']) : '';
            $review = isset($p['review_count']) ? '(' . (int)$p['review_count'] . '件)' : '';
            $meta = $rating !== '' ? "★{$rating}{$review}" : '';
            $brand_part = $brand !== '' ? "[{$brand}] " : '';
            $lines[] = "{$no}. {$brand_part}{$title} / 価格約¥{$price} / {$source} {$meta}";
        }
        $list_text = implode("\n", $lines);

        return <<<PRODUCTS

商品候補リスト（Amazon/楽天 API から取得した実在商品）:
ランキング記事を書く際は、以下の候補リストから商品を選んで H3 を組み立ててください。
**架空の商品名を H3 に書かない**。候補リストの商品名（ブランド名＋商品カテゴリ）を
H3 に使うことで、後段の商品挿入処理で正しい商品カードが挿入されます。

{$list_text}

商品 H3 の書き方:
- 「N位：ブランド名 商品カテゴリ（識別語1個）」の形式に絞る（15〜30文字）
- 例: 「1位：Ezprotekt キャスターストッパー（5個セット）」
- 候補リストの商品タイトルが長すぎる場合は、ブランド名＋商品カテゴリ部分だけ抽出する
PRODUCTS;
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
    private static function build_prompt($post, $opts, $product_candidates = []) {
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

        // 商品候補セクション（ranking 記事で商品候補を取得できたとき）
        $products_section = self::format_product_candidates_section($product_candidates);

        $original_title = $post['title'];
        $original_content = mb_substr((string)$post['content'], 0, self::MAX_SOURCE_CHARS);

        $heading_rules = <<<HEADING
見出し（H2/H3）の SEO 品質基準:

【ステップ1: 主要キーワードの抽出】
記事タイトルから「核となる短い語」を 1〜3 個抽出する。
- 抽出対象: 商品カテゴリ・対象物・状態・課題を表す名詞中心の語
- 除外: 「おすすめ」「ベスト」「人気」「商品N選」「N選」などの評価語・順序語・数詞

【ステップ2: H2 見出しの作り方】
両極端を避け、ちょうど良い具体性を持たせる:
- 過剰最適化 NG: タイトル丸ごとコピペ／｜区切りで3要素以上／同義語重複
- 不足 NG: 「選定基準」「よくある質問」「まとめ」のような単語1つだけの裸見出し（共起語ゼロでSEO弱い）
- 最適: 主要キーワードを1語、自然な日本語として12〜30文字に組み込む

NG例:
<h2>キャスターチェアによる床の傷を防止する｜選定基準｜この5製品を選んだ理由</h2>
<h2>選定基準</h2>（裸単語）

OK例:
<h2>キャスターチェアで床に傷がつく原因と対策</h2>
<h2>床傷防止グッズの選定基準｜4つの評価軸</h2>
<h2>失敗しない床傷防止アイテムの選び方</h2>
<h2>まとめ｜床傷防止グッズ選びで失敗しないために</h2>

【ステップ3: ランキング H3 の商品名】
WordPress プラグイン側で H3 が Amazon/楽天 API の検索クエリにも使われるため、
適切な長さと構造が必要。

必ず含める: ブランド名（検索精度の核）／ 商品カテゴリ／型番
任意で含める: 識別語1個まで（「5個セット」「黒」「Pro」など）
含めない: キャッチコピー／効能語／用途語／タイトル貼り付け

文字数目安: 15〜30 文字。Amazon の商品名そのままの貼り付けは禁止。

NG例:
<h3>1位：Ezprotekt キャスターストッパー 5個セット 毛玉がつかない キャスター 椅子 固定 傷防止 家具が楽に移動できる 振動吸収 家具保護パッド キズ防止 フローリング用</h3>
<h3>1位：商品名｜キャスターチェアによる床の傷を防止する</h3>
<h3>1位：キャスターストッパー</h3>（ブランド名なし → Amazon検索で別ブランド混入）
<h3>1位：ダイソン</h3>（カテゴリなし → 関係ない商品まで混入）

OK例:
<h3>1位：Ezprotekt キャスターストッパー（5個セット）</h3>
<h3>1位：ダイソン V15 Detect</h3>
<h3>1位：山善 オフィスチェア YHC-300</h3>

【メタ目次H2の禁止（重要）】
以下のような「記事の目次・要約」を意味するメタセクションは H2 で作らない:
NG: 「この記事でわかること」「この記事のポイント」「目次」「もくじ」
NG: 「本記事の概要」「記事の要点」「先に結論」「3行でわかる」
理由: リード文（記事冒頭の <p>）と内容が重複し、SEO 的に無価値で
読者にもノイズになるため。リード文で「この記事では○○を解説します」と
1〜2文で伝えれば十分。

【冗長を避ける（SEO Helpful Content 観点）】
NG: 「公式情報をご確認ください」「ご確認ください」を10回以上繰り返す
NG: 「対応キャスター径の確認」「素材の摩耗」のような共通注意点を全商品で重複
NG: 「5個セットで5本脚チェアに対応」のような評価フレーズを商品間で使い回す
NG: 同じテーマで H2 セクションを 2 つ以上作る（特に「選定基準」を 2 個並べる）
    例: 「選定基準｜4つの評価軸」と「N選を選ぶ際の選定基準」を併記するのは厳禁
NG: 「N選を選ぶ際の○○」「ランキングの判断基準」のように記事構造に言及する H2 を作る
NG: まとめ H2 以降に <p> を 6 個以上連ねる。「商品を選んだあとは…」「また、どの…」
    「この記事で紹介した…」「フェルト素材のストッパーは…」のような追記パラグラフを
    連発するのは絶対禁止

OK: 共通注意点は「選定基準」または「選び方」セクションで1回だけ書く
OK: 各商品は「ここでしか言えない独自の特徴」を1〜2個明示
OK: まとめは要点整理＋用途別 ul ＋自然な CTA で完結（<p> は 5 個以内）

【ランキング記事の密度勾配（ranking タイプのとき・重要）】
全件を均等な分量で書くと冗長になる。順位に応じて情報密度と装飾に勾配をつける:

- **1-3位**: 300〜500字（厚く・特徴段落＋注意点赤字ブロック1つ＋向いている人 ul）
  読者が最も注目し購入検討対象になる位置。独自性・強み・選定理由を具体的に。

- **4位以降**: 150〜250字（簡潔に・本文 <p> のみ）
  比較対象としての位置づけ。**注意点赤字ブロックも ul も付けない**。
  ❌ NG: <p><span style="color:#d32f2f"><strong>注意点：</strong>...
  ❌ NG: <ul><li><strong>向いている人</strong>：...
  → 注意があれば本文に1〜2文で自然に組み込む

- **最下位2件**: 100〜200字（最も簡潔に・存在意義のみ）

**4位以降に赤字注意点ブロックや向いている人 ul を付けるのは厳禁**。
冗長な広告感を演出し、SEO + CV 両面でマイナス。
信頼性アピールは上位3商品で十分。

共通注意点は商品個別では繰り返さず、選定基準セクションで一元化する。

【その他のルール】
- 記事冒頭は必ず <p> リード文（2〜4段落）から始める。冒頭にいきなり <h2> を置かない
- 「｜」記号は1見出しに最大1個まで
- 同じキーワードを同一見出し内で2回以上書かない
HEADING;

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

{$heading_rules}
{$type_section}
{$products_section}
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
