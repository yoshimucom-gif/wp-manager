# エリアLab — 引き継ぎメモ

## このサービスについて

**不動産売買仲介で開業を考えている人向けに、開業候補エリアの競合（宅建業者）状況を可視化するサービス。**

- 開業エリアを入力 → 半径○km以内の宅建業者数・種類・位置がわかる
- 競合マップ、収益シミュレーション、AI総合判定をレポートとして表示
- ターゲット：不動産売買仲介での独立・開業を検討している人

---

## プロジェクト情報

- **URL**：https://arealab.vercel.app
- **ローカルパス**：`C:\Users\yoshi\OneDrive\デスクトップ\Claude\arealab`
- **ホスティング**：Vercel（GitHubなし、ローカルから直デプロイ）
- **構成**：HTML/CSS/JS（フレームワークなし）+ Vercel Serverless Functions + Supabase

```
arealab/
├── index.html     # ユーザー向けLP + 診断画面（1ファイルSPA）
├── admin.html     # 管理画面
├── api/
│   └── area.js    # Vercel Serverless Function（Supabaseからデータ取得）
└── package.json   # @supabase/supabase-jsのみ
```

---

## 現状

- LP・診断画面・管理画面・Supabase連携のコードは実装済み
- **ただしSupabaseのテーブルがまだ存在しない**
- 現在はindex.html内のモックデータ（三軒茶屋・下北沢・用賀）で動いている

---

## データ設計の方針（ここが重要）

### 競合データのソース
**国土交通省の宅建業者名簿CSV**を使う。
- 全国の免許を持つ宅建業者が全部載っている公開データ
- リアルタイムAPIは存在しないため、CSVダウンロード → 月1回バッチ更新で対応
- 住所をジオコーディング（国土地理院API）→ 緯度経度に変換 → 地図表示

### 取引価格・件数データのソース
**国土交通省 不動産情報ライブラリAPI**（2024年〜、無料・APIキー申請制）
- 取引件数・価格データをリアルタイム取得可能

### 人口データ
**e-Stat API**（国勢調査）

---

## Supabaseのテーブル設計（これから作る）

### `areas`テーブル
| カラム | 型 |
|---|---|
| key | text (PK) |
| name | text |
| prefecture | text |
| grade | text（A/B/C） |
| lat, lng | float |
| annual_transactions | int |
| median_price | int（万円） |
| competitors | int |
| potential | float |
| price_range | text |
| main_property | text |
| population | text |
| avg_age | text |
| color | text |
| tagline | text |
| ai_comment | text |

### `competitors`テーブル
| カラム | 型 |
|---|---|
| id | serial (PK) |
| area_key | text |
| name | text |
| lat, lng | float |
| type | text（大手/FC/中小） |

### `simulations`テーブル
| カラム | 型 |
|---|---|
| id | serial (PK) |
| area_key | text |
| sort_order | int |
| label | text |
| icon | text |
| transactions, fee, revenue, cost, net | int |
| highlight | boolean |

### `costs`テーブル
| カラム | 型 |
|---|---|
| id | serial (PK) |
| area_key | text |
| sort_order | int |
| label | text |
| value | text |

---

## 今やろうとしていたこと

Supabaseのプロジェクトを作成済み。次のステップ：

1. **Supabaseでテーブルを作成する**（SQL Editorにペーストするだけ）
2. **宅建業者名簿CSVをダウンロードしてインポートスクリプトを作る**
3. **Vercelの環境変数にSupabase URLとanon keyを設定する**
4. **api/area.jsを実データ対応に更新する**

---

## Vercelの環境変数（設定が必要）

| 変数名 | 値 |
|---|---|
| `SUPABASE_URL` | SupabaseのProject URL |
| `SUPABASE_ANON_KEY` | Supabaseのanon publicキー |
