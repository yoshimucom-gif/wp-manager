# CHANGELOG / 引き継ぎメモ — Affiros カテゴライザー プラグイン

このファイルは、**別のチャット（セッション）で作業を引き継ぐため**の記録です。
新しいセッションでこのプラグインを触るときは、まず `README.md`（概要・使い方）と
このファイル（ファイル構成・改修履歴・設計判断・既知の制約）を読んでください。

---

## ファイル構成

```
affiros-categorizer/
├── affiros-categorizer.php … 本体。定数定義・設定取得・メニュー登録・自動分類トリガー
├── README.md               … 概要・インストール・設定・使い方
├── CHANGELOG.md            … このファイル（改修履歴・引き継ぎ）
├── admin/
│   ├── settings-page.php    … 設定画面（APIキー・モデル・サイト説明・自動分類動作）
│   ├── classify-page.php    … 一括分類画面（投稿一覧＋選択。JS は assets/admin.js）
│   ├── meta-box.php         … 投稿編集画面のメタボックス（単記事の再判定）
│   └── ajax-handler.php     … AJAX（fetch_posts / classify_post）
├── includes/
│   ├── claude-api.php       … Claude API ラッパー（自動リトライ付き）
│   └── classifier.php       … カテゴリー取得・プロンプト生成・分類実行
└── assets/
    ├── admin.css            … 管理画面 CSS
    └── admin.js             … 管理画面 JS（メタボックス＋一括分類）
```

## 設計上の要点

- **分類表を持たない**: `get_terms()` でサイトの実カテゴリーを動的に取得し、親子階層も
  WordPress のカテゴリーツリーから組み立てる。ハードコードした分類表が無いため、
  ジャンルの異なるどのサイトに入れてもそのまま動く。
- **判定ヒントはカテゴリーの「説明」欄**: AI に渡す一覧は「[ID] 名前 — 説明」形式。
  説明を埋めるほど精度が上がる。設定画面で未記入のカテゴリーを赤字で警告する。
- **AI は term ID で回答**: 名前ではなく term ID を返させる。同名カテゴリー（親違い）が
  あっても確実に特定でき、戻り値も `in_array` で厳密に検証する。
- **自動分類は `transition_post_status` + WP-Cron**: 公開遷移をフックし、実処理は
  WP-Cron の単発イベントに逃がして公開リクエストをブロックしない。`transition_post_status`
  は直接公開・下書き→公開のどちらでも発火し、`wp_insert_post` アクションとの順序問題も
  受けない。重複実行は分類ログメタ `_affiros_cat_log` の有無で防ぐ。
- **API キーは `wp-config.php` 定数 `AFFIROS_CATEGORIZER_API_KEY` を最優先**
  （affiros-rewrite と同じ方針。更新・再インストールでも消えない）。
- **上書きモード**: 自動分類時のみ「未分類の記事だけ（empty）」/「常に上書き（always）」
  を選べる。手動分類・一括分類は常に上書き（`classify($id, true)`）。

## バージョン履歴

### v0.1.0 (2026-05-22)
初版。`mikata-ai-classifier`（単一サイト専用・不動産メディア向けにカテゴリー表を
ハードコードしていたプラグイン）を、Affiros プラグイン規約に合わせて全面的に作り直した。

- ハードコードの分類表（34カテゴリー）と事業タイプ用カスタムタクソノミーを撤廃。
- `get_terms()` によるサイト実カテゴリーの動的取得に変更。どのサイトでも動作。
- Affiros 規約に統一: 接頭辞 `AFFIROS_CAT_` / `Affiros_Cat_`、設定は単一オプション
  `affiros_categorizer_settings`、`wp-config.php` 定数対応、claude-api ラッパー流用、
  モデルID マイグレーションマップ。
- 自動分類を WP-Cron 非同期化（旧版は公開時に同期実行していた）。
- 一括分類画面を新規追加（旧版に無かった。未分類記事の一括解消用）。
- wp_manager 本体に組み込み: `build-all.py` SPECS / `app.py` PLUGIN_DOWNLOADS /
  `templates/index.html` のプラグイン一覧カード（❹）。

## 既知の制約・今後の検討事項

- **WP-Cron 依存**: 公開時の自動分類は WP-Cron の単発イベントで走る。WP-Cron が無効な
  サイト、またはアクセスの極端に少ないサイトでは発火が遅れることがある。その場合は
  一括分類画面・メタボックスから手動実行できる。
- **カテゴリー説明欄が未記入だと精度が落ちる**: 説明が無いとカテゴリー名のみで判断する。
- **本文の解析範囲**: 先頭 2500 文字（`classifier.php` の `CONTENT_LIMIT`）。
- **アンインストール**: `uninstall.php` は無し（設定を意図的に DB へ残す方針）。
- **分類は1記事1カテゴリー**: 複数カテゴリーの同時付与には未対応。

## 関連プロジェクト

- **wp_manager (Affiros9)**: Flask + Claude の WordPress 記事生成ツール（本体）。
  このプラグインはその companion。
- **affiros-rewrite / affiros-decoration / affiros-product-inserter**: 同じ Affiros
  ファミリーの companion プラグイン。
