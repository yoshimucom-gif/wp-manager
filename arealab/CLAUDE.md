# エリアLab — CLAUDE.md

## プロジェクト概要

不動産売買仲介業で開業する際に、**近隣競合の状況を可視化して開業エリア選びを支援する**Webサービス。

ターゲット：不動産業で独立・開業を検討している人。「このエリアで開業したら競合がどれくらいいるか」「どのエリアが狙い目か」を診断できる。

## 構成

```
arealab/
├── index.html   # ユーザー向けLP + 診断画面（1ファイルSPA）
├── admin.html   # 管理画面（エリアデータ管理）
├── api/
│   └── area.js  # Vercel Serverless Function（Supabaseからデータ取得）
└── package.json # @supabase/supabase-js のみ依存
```

- フレームワークなし。HTML/CSS/JS + Leaflet（地図）
- バックエンド：Supabase（PostgreSQL）
- ホスティング：Vercel（https://arealab.vercel.app）
- GitHubなし（ローカル + Vercel直デプロイ）

## Supabaseのテーブル構成

| テーブル | 用途 |
|---|---|
| `areas` | エリアマスタ（key, name, グレード等） |
| `competitors` | 競合会社リスト（area_keyで紐付け） |
| `simulations` | 収支シミュレーションデータ |
| `costs` | 開業コストデータ |

## デプロイ方法

```
cd C:\Users\yoshi\OneDrive\デスクトップ\Claude\arealab
vercel --prod
```

環境変数（Vercelダッシュボードで設定）：
- `SUPABASE_URL`
- `SUPABASE_ANON_KEY`

## 現状・進捗

- LP（トップページ）：完成
- 診断画面：実装済み・動作確認済み
- 管理画面（admin.html）：実装済み
- Supabase連携：完了（三軒茶屋・下北沢・用賀の3エリアが本番DBに入っている）
- scrollToバグ修正済み

## 注意事項

- 現在テスト用にダッシュボードを初期画面にしている（本番リリース前にLPに戻す）
  → index.html の `screen-lp` に `active` を戻し、`screen-dashboard` から `active` を外す

## TODO（優先順）

- [ ] 宅建業者名簿CSV取り込み → 全国実データ化（競合データのソース）← 次回ここから
- [ ] 地点診断モード追加：住所入力 or 地図ピン移動 → 周辺競合を表示（実データ前提）
- [ ] 不動産情報ライブラリAPIで取引件数・価格をリアルデータに
- [ ] エリア追加の仕組み（管理画面からデータ登録）
- [ ] 認証・ログイン機能の実装

## サービスの2モード構想

- **エリア探索モード**（現在）：エリア名を選んでグレード・レポートを表示
- **地点診断モード**（追加予定）：住所入力 or 地図ピンドラッグ → その地点周辺の競合マップ・分析
