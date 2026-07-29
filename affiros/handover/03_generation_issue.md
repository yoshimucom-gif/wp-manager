# 03 Generation Issue

## 直近の致命的エラー

商標記事生成で5分以上待ったあと、以下が表示された。

```text
'NoneType' object is not callable
```

対象記事:

```text
Andeor ネックウォーマーの口コミ・評判レビュー｜メリット・デメリットを解説
```

画面表示:

```text
// バックグラウンドで分割生成中です。完了後に本文を表示します。
```

## 関連関数

`app.py`

```text
generate_article(article_id)
batch_generate()
generate_segmented_article_sync(...)
generate_segmented_article_sse(...)
stream_claude_sse(...)
validate_generated_article(...)
enhance_generated_article_html(...)
build_ad_product_blocks(...)
```

`templates/index.html`

```text
startGenerate()
waitForSingleGenerateCompletion(articleId, jobId)
finalizeGenerateSuccess(...)
loadLatestBatchJob()
renderBatchJobStatus(...)
toast(...)
```

## 疑わしい点

### 1. エラー詳細が保存されていない

`batch_generate()` の例外処理が `str(e)` だけを保存している。

そのため、どの段階で落ちたかわからない。

必要:

- stage
- traceback
- job.last_error
- article.error_trace

### 2. 単発生成が latest job を見ている

`waitForSingleGenerateCompletion(articleId, jobId)` に jobId が渡っているのに、実際は `loadLatestBatchJob()` を見ている。

別ジョブと混ざる可能性がある。

必要:

```text
GET /api/batch-jobs/<job_id>
```

### 3. 分割生成の途中保存が弱い

生成が最後まで終わらないと本文が残りにくい。

理想:

- セクション単位で生成
- セクションごとに保存
- 最後に結合
- 失敗したセクションだけ再試行

## 今後の生成方式

1回で5000文字を出し切るより、1000文字前後のセクション単位で確実に進める。

例:

1. 構成案
2. 導入
3. 基本情報
4. メリット
5. デメリット
6. 比較
7. FAQ
8. まとめ
9. 結合・整形・検証

