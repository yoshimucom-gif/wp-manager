# 04 Next Actions

## 最優先

新機能追加ではなく、まず1記事を確実に生成完了させる。

## 1. バックグラウンド生成のエラー詳細化

対象:

`app.py` の `batch_generate()` / `run_batch()`

やること:

- `stage = '初期化'` のような段階名を持つ
- 例外時に `traceback.format_exc()` を保存
- article と job の両方に保存

保存例:

```python
a['error'] = error_detail
a['error_stage'] = stage
a['error_trace'] = trace[-4000:]
```

job:

```python
update_job(
    last_error=error_detail,
    last_error_trace=trace[-4000:],
    message=error_detail
)
```

## 2. job_id指定APIを追加

対象:

`app.py`

追加:

```python
@app.route('/api/batch-jobs/<job_id>', methods=['GET'])
@login_required
def get_batch_job(job_id):
    job = next((j for j in load_batch_jobs() if j.get('id') == job_id), None)
    if not job:
        return jsonify({'error': 'ジョブが見つかりません'}), 404
    return jsonify(job)
```

## 3. フロントの進捗追跡を修正

対象:

`templates/index.html`

追加:

```javascript
async function loadBatchJob(jobId) {
  if (!jobId) return loadLatestBatchJob();
  const res = await fetch(`/api/batch-jobs/${jobId}`);
  if (!res.ok) return null;
  const job = await res.json();
  renderBatchJobStatus(job);
  return job;
}
```

変更:

```javascript
const job = await loadBatchJob(jobId) || await loadLatestBatchJob();
```

## 4. Claude呼び出しを安全化

`client.messages.create(...)` を直接呼ぶ箇所を減らす。

ヘルパー案:

```python
def create_claude_message(client, prompt, max_tokens=None, timeout=None):
    messages_api = getattr(client, 'messages', None)
    create = getattr(messages_api, 'create', None)
    if not callable(create):
        raise RuntimeError('Claude APIクライアントを初期化できませんでした')
    kwargs = {
        'model': CLAUDE_ARTICLE_MODEL,
        'max_tokens': max_tokens or CLAUDE_ARTICLE_MAX_TOKENS,
        'messages': [{'role': 'user', 'content': prompt}],
    }
    if timeout:
        kwargs['timeout'] = timeout
    return create(**kwargs)
```

## 5. 商標記事1本をテスト

対象:

```text
Andeor ネックウォーマーの口コミ・評判レビュー｜メリット・デメリットを解説
```

合格条件:

- 進捗が更新される
- 途中停止しない
- 完了後に本文が保存される
- 失敗時は stage と traceback が見える

