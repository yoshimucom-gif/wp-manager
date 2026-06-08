# wp_manager — CLAUDE.md

## プロジェクト概要

**WordPressサイト向けのAI記事生成・管理ツール。** ClaudeがSEO最適化された記事を自動生成し、複数のWordPressサイトに投稿できる。AmazonアフィリエイトおよびRINKERスタイルの楽天アフィリエイト商品挿入に対応。

## バージョン管理（必須）

本体のバージョンは `app.py` の `APP_VERSION` 定数で管理する（セマンティックバージョニング）。
**改修したらこの値を必ず上げる**こと。具体的には：

- **MAJOR**: 互換性のない大変更（データ構造変更等）
- **MINOR**: 機能追加・大きな品質改善
- **PATCH**: バグ修正・微調整

改修コミット時のチェックリスト：
1. `app.py` の `APP_VERSION` を上げる
2. `templates/index.html` の改修履歴ページ（`<div id="page-changelog">`）に新しいセクションを追加
3. プラグイン側の改修なら、該当プラグインの `Version:` ヘッダーと `AI_PI_VERSION` / `AFFIROS_REWRITE_VERSION` 定数も同期、`build-all.py` と `app.py PLUGIN_DOWNLOADS` も追従

参照: `/api/version` で本体バージョンを取得可能。ナビ左上に `v{APP_VERSION}` を常時表示。

## 構成

```
wp_manager/
├── app.py              # Flaskアプリ本体（メインロジック全部）
├── requirements.txt    # Python依存パッケージ
├── render.yaml         # Render.comデプロイ設定
├── templates/
│   ├── index.html      # メイン画面
│   └── login.html      # ログイン画面
└── data/               # JSONファイルでデータ永続化
    ├── articles.json   # 記事データ
    ├── quality.json    # 品質プリセット
    ├── decorations.json # 装飾設定
    └── settings.json   # サイト設定・APIキー等
```

- バックエンド：Python / Flask
- AI生成：Anthropic API（Claude Sonnet 4.6、SSEストリーミング）
- 外部連携：WordPress REST API、Amazon PA-API v5、楽天市場商品検索API
- ホスティング：Render.com（Singaporeリージョン）
- データ永続化：JSONファイル（Renderのディスク /data にマウント）

## 環境変数（Renderで設定）

| 変数名 | 内容 |
|---|---|
| `APP_PASSWORD` | ログイン用パスワード |
| `SECRET_KEY` | Flaskセッションキー（自動生成） |
| `DATA_DIR` | データ保存先（`/data`） |
| `ANTHROPIC_API_KEY` | Claude APIキー |

※ Amazon/楽天のAPIキー・WordPressのURL/ユーザー/パスワードはUI上から設定してJSONに保存。

## デプロイ方法

Render.comのダッシュボードから手動デプロイ、またはGit連携で自動デプロイ。

## 主な機能

### 記事管理
- 複数WordPressサイトの管理
- Excelインポート（タイトル・キーワード一括登録）
- 記事の一覧・編集・削除・一括削除
- サイト・品質・キーワードでフィルタ・検索
- ステータス管理（pending / generating / generated / published / error）

### 記事生成
- ClaudeによるSEO記事自動生成（SSEストリーミング表示）
- バッチ生成（複数記事を非同期バックグラウンド処理）
- 品質プリセット（プロンプト＋参考URL）によるカスタマイズ
- 装飾定義（WordPressのサンプル記事HTMLからスタイル踏襲）

### アフィリエイト商品挿入（RINKERスタイル）
- Amazon PA-API v5 で商品検索・挿入
- 楽天市場商品検索API で商品検索・挿入
- Amazon・楽天を同時に有効化すると**RINKERスタイルの商品カード**を生成
  - 商品画像・タイトル・価格比較
  - 「Amazonで見る」ボタン（オレンジ）＋「楽天市場で見る」ボタン（赤）並列表示
- 単体有効化も可能（Amazonのみ、楽天のみ）

| Amazon | 楽天 | 出力 |
|--------|------|------|
| ✓ | — | Amazonボタンのみのカード |
| — | ✓ | 楽天ボタンのみのカード |
| ✓ | ✓ | 両ボタン並びのRINKERスタイルカード |

### WordPress投稿
- 単体投稿・一括投稿（draft / publish 選択可）
- グローバルCSS定義（記事先頭に自動挿入）

## 設定項目（UIから設定）

| 設定 | 説明 |
|---|---|
| Claude APIキー | Anthropic APIキー |
| Amazon Access Key ID | PA-API v5 認証情報 |
| Amazon Secret Access Key | PA-API v5 認証情報 |
| Amazon Partner Tag | アソシエイトID（例: yourname-22） |
| 楽天 アプリケーションID | 楽天ウェブサービスのID |
| 楽天 アフィリエイトID | 楽天アフィリエイトのID（任意） |
| 記事CSS | 全サイト共通スタイル（投稿時に先頭挿入） |
| デフォルト品質定義 | 生成時のデフォルトプリセット |

## APIエンドポイント一覧

### 認証
- `GET /login` / `POST /login` / `GET /logout`

### 記事
- `GET /api/articles` — 一覧取得
- `GET /api/articles/<id>` — 個別取得
- `PUT /api/articles/<id>` — 更新
- `DELETE /api/articles/<id>` — 削除
- `POST /api/articles/bulk-delete` — 一括削除
- `PUT /api/articles/<id>/site` — サイト紐付け変更

### 生成・投稿
- `POST /api/import` — Excelインポート
- `POST /api/generate/<id>` — SSEストリーミング生成
- `POST /api/batch-generate` — バッチ生成（非同期）
- `POST /api/publish/<id>` — WordPress単体投稿
- `POST /api/batch-publish` — WordPress一括投稿

### アフィリエイト
- `POST /api/amazon/search` — Amazon商品検索テスト
- `POST /api/rakuten/search` — 楽天商品検索テスト

### 品質定義
- `GET/POST /api/quality`
- `PUT/DELETE /api/quality/<id>`

### 装飾定義
- `GET/POST /api/decorations`
- `PUT/DELETE /api/decorations/<id>`
- `POST /api/decorations/fetch` — WordPressから記事HTML取得

### サイト管理
- `GET/POST /api/sites`
- `PUT/DELETE /api/sites/<id>`

### 設定
- `GET/POST /api/settings`

## 主要関数（app.py）

| 関数 | 役割 |
|---|---|
| `amazon_search()` | Amazon PA-API v5 商品検索 |
| `rakuten_search()` | 楽天市場商品検索API |
| `build_rinker_html(amazon_p, rakuten_p)` | RINKERスタイル商品カードHTML生成 |
| `fetch_url_text()` | 参考URLのテキスト抽出 |
| `get_site_credentials()` | 記事からWP接続情報を取得 |
| `login_required` | 認証デコレータ |
