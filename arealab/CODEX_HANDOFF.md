# エリアLab Codex引き継ぎ

## 概要

不動産売買仲介で独立・開業を検討している人向けに、候補エリアの競合状況、取引規模、収益シミュレーション、AIコメントを見せるWebサービス。

現在の実装はフレームワークなしの `index.html` / `admin.html` と、Vercel Serverless Function の `api/area.js` で構成されています。

## 作業コピー

元ファイル:

`C:\Users\yoshi\OneDrive\デスクトップ\Claude\arealab`

Codex作業コピー:

`C:\Users\yoshi\Documents\Codex\2026-05-05\c-users-yoshi-onedrive-claude\arealab`

## 主要ファイル

- `index.html`: ユーザー向けLP、ログイン画面、ダッシュボード、検索、レポート、比較画面を含む1ファイルSPA
- `admin.html`: 管理画面。現状はユーザー、売上、問い合わせのモック管理UI
- `api/area.js`: `areas`、`competitors`、`simulations`、`costs` をSupabaseから取得してフロント形式で返すAPI
- `supabase-schema.sql`: Supabase SQL Editorに貼る初期テーブル定義
- `supabase-seed-initial.sql`: 三軒茶屋・下北沢・用賀の初期データ投入SQL
- `.env.example`: Vercel/ローカル用の環境変数サンプル

## 現状

- `index.html` は三軒茶屋、下北沢、用賀のモックデータで動きます。
- `/api/area` への fetch が失敗した場合、モックデータへフォールバックします。
- `screen-dashboard` が初期表示になっています。正式公開時は `screen-lp` に `active` を戻してください。
- `HANDOFF.md` と `CLAUDE.md` でSupabaseの状態に差があります。新しい作業メモとしては `HANDOFF.md` を優先してください。

## 次にやること

1. Supabase SQL Editorで `supabase-schema.sql` を実行する。
2. 続けて `supabase-seed-initial.sql` を実行し、3エリア分の初期データを投入する。
3. `.env.example` をもとに Vercel に `SUPABASE_URL` と `SUPABASE_ANON_KEY` を設定する。
4. 宅建業者名簿CSVの取得元、CSV列名、文字コードを確認し、インポートスクリプトを作る。
5. 住所を緯度経度に変換するジオコーディング処理を作る。
6. 競合データを `competitors` に投入し、地点診断モードを設計する。

## 開発コマンド

```powershell
cd C:\Users\yoshi\Documents\Codex\2026-05-05\c-users-yoshi-onedrive-claude\arealab
npm install
npm run check
npm run dev
```

`npm run dev` はVercel CLIが必要です。未導入なら `npm i -g vercel` または `npx vercel dev` を使います。

## 注意点

- フロントの `normalizeArea()` は、DB列名のゆれに備えて `competitors_count` / `competitors` と `is_highlight` / `highlight` の両方を受け付けるように調整済みです。
- `admin.html` はSupabase書き込みにはまだ接続されていません。管理画面からのエリア追加は未実装です。
- 国土交通省の宅建業者名簿CSVはリアルタイムAPIではなく、CSV更新をバッチ取り込みする想定です。
