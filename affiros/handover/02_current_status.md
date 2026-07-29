# 02 Current Status

## 主要ファイル

```text
app.py
templates\index.html
requirements.txt
render.yaml
data\
static\
```

## できていること

- Affiros9 UI
- ログイン
- トップページ
- 記事作成画面
- 一括処理画面
- SEOリライト画面
- 対応履歴
- 品質定義
- 装飾定義
- 広告定義
- サイト管理
- API設定
- WordPress投稿
- WordPress既存投稿への上書き送信
- CSVインポート
- サンプルCSVダウンロード
- 投稿スケジュール項目
- Amazon / 楽天 API 設定
- 楽天ASP設定
- APIキーの長さを保ったマスク表示
- Render永続ディスク警告

## まだ不安定なこと

最重要:

```text
記事生成が安定して完了しない。
```

過去に出た問題:

- 生成完了信号を受信できない
- 保存優先モードも失敗する
- HTTP 500 が返る
- 本文が短すぎて保存されない
- 商標記事で `NoneType object is not callable`
- ランキング記事で途中停止
- 一括処理に耐えられる状態ではない

## ユーザーの現在の不満

- 1記事すらまともに作れない
- 待ち時間が長い
- 進捗がわからない
- エラー内容が意味不明
- 同じところで何度も止まっている

次は必ず「生成処理の安定化」から入ること。

