# 05 Operations

## 作業フォルダ

```powershell
cd C:\Users\yoshi\OneDrive\デスクトップ\Codex
```

## ローカル確認

```text
http://localhost:5000/
```

## 構文チェック

Python:

```powershell
python -m py_compile app.py
```

JSは `templates/index.html` 内の script を抽出して構文チェックする。

## Git

状態確認:

```powershell
git status --short
```

コミット:

```powershell
git add app.py templates/index.html handover
git commit -m "Fix generation job tracking"
```

push:

```powershell
git push origin main
```

## Render

本番:

```text
https://wp-manager.onrender.com
```

確認:

```powershell
Invoke-WebRequest -Uri "https://wp-manager.onrender.com/login" -UseBasicParsing -TimeoutSec 45
```

デプロイ直後は 502 が出ることがある。30〜60秒待って再確認。

## 永続ディスク

Render本番では `/data` 保存が正しい。

以下が出たら危険:

```text
保存先: /opt/render/project/src/data
```

これは永続ディスクではない可能性がある。

消えてはいけないもの:

- API設定
- サイト管理
- 生成記事
- 品質定義
- 装飾定義
- 広告定義
- batch jobs

