# 変更履歴 (CHANGELOG)

Affiros プロダクトインサーター の改修履歴。新しいバージョンを上に記載。

> **別チャット・別セッションで開発を引き継ぐ場合:**
> このプラグインは zip で配布され、開発コンテキストは zip 同梱ファイルで引き継ぐ。
> 新しいセッションではまず **このファイル → README.md** の順に読むこと。
> 末尾の「引き継ぎメモ（既知の未対応事項）」も必ず確認する。

---

## [1.9.0] - 2026-05-22

### 追加
- **API接続テスト機能。** 設定画面に「🔌 接続テスト（API有効性チェック）」ボタンを追加。
  入力中の値（保存前でも可）で Claude / Amazon PA-API / 楽天市場API へ実際に接続し、
  キーの有効性を ✅／❌／➖（未入力）で表示する。打ち間違い・無効キーのまま運用するのを防ぐ。
  - `admin/ajax-handler.php` — `ai_pi_test_credentials` AJAXハンドラを追加
  - `includes/{claude,amazon,rakuten}-api.php` — コンストラクタに設定オーバーライド引数
    （`$config`）を追加。保存前のフォーム入力値でテストできるようにした。
    `AI_PI_Claude_API::test_connection()` を新設
  - 楽天はアフィリエイトID込みで失敗した場合、ID無しで再試行して
    「アプリID／アフィリエイトID のどちらが原因か」を切り分けて表示
  - `admin/settings.php` / `assets/admin.js` / `assets/admin.css` — ボタン・結果表示UI

---

## [1.8.0] - 2026-05-22

### 追加
- カードデザイン **`score`（スコアカード）** と **`mini`（ミニカード）** を新規実装。
  以前からマーカー語彙（`admin/meta-box.php`）には載っていたがテンプレートが無く、
  指定しても `card-renderer.php` が無音で `card-vertical.php` にフォールバックしていた。
  - `templates/card-score.php` — 評価スコアをバッジ+ゲージで前面化。
    レビュー非取得の商品ではスコア表示を省き、通常の商品カードとして描画
  - `templates/card-mini.php` — サムネ+商品名+価格+ボタンの1行軽量カード
  - `assets/frontend.css` に両カードのスタイル+レスポンシブを追加
  - `admin/design-preview.php` にプレビュー（⑤⑥）と早見表の行を追加

### 修正
- **Amazonレビュー矛盾の解消。** Amazon PA-API は CustomerReviews を返さず
  Amazon商品の評価・件数が常に 0。一方プロンプトは「レビュー10件以上を優先」と
  指示しており、AIがAmazon商品を不当に減点しうる状態だった。
  - `includes/product-selector.php` — ペア成立した楽天商品のレビュー（評価・件数）を
    同一商品の代理値として Amazonベース商品へ引き継ぐ
    （`merge_duplicates` / `enrich_with_rakuten_pair` の両方）
  - `includes/claude-api.php` — `format_review()` を追加。レビュー実数が無い候補は
    「データなし」と明示（「0件」表示で低品質と誤読されるのを防ぐ）
  - プロンプト3本（marker / ranking / per-heading）— 「データなし＝低品質ではない、
    減点しない」と明示
  - 副次効果: 楽天ペアが成立した Amazon カードにレビュー星が表示されるように
- 再挿入・ロールバック時に `_ai_pi_expired`（24時間期限切れ）フラグが残り続け、
  再挿入直後でも「⚠️24h経過」が表示される問題を修正（`includes/inserter.php`）
- 一括処理のコスト試算が Claude Opus 4.7 に未対応だった
  （コスト表のキーが旧 `claude-opus-4-6` のまま。`admin/ajax-handler.php`）

### ドキュメント
- `README.md` を現状（6デザイン・2ボタン構成・実ファイル構成）に全面改稿
- `admin/design-preview.php` の「3ボタン(Amazon/楽天/Yahoo!)」誤記を「2ボタン」に修正

---

## 過去バージョン（コード・旧READMEからの再構成）

> 1.7.x 以前の正確な履歴は残っていない。以下はコード内コメント・マイグレーション
> 処理・旧 README から再構成したもので、リリース日は不明。v1.3〜v1.5 と
> v1.7.0〜v1.7.1 は情報が無く欠落している。

### [1.7.3]
- プラグインのディレクトリ名・メインファイル名を
  `ai-product-inserter` → `affiros-product-inserter` にリネーム。
  旧プラグインが有効なままだと定数・クラスが二重定義され白画面になるため、
  `AI_PI_VERSION` 定義済みチェックで初期化をスキップするガードを追加

### [1.7.2]
- 商品マージ処理を「Amazonベース固定」に統合（`preferred_site` は `both` 固定）。
  カードの source は常に amazon、楽天はペア化または検索URLフォールバック

### [1.6.0]
- 「楽天単独商品をフォールバック表示」する改修を実施 → ユーザー差し戻し。
  以降、Amazon未マッチの楽天単独商品はカードに出さない方針が確定
  （`includes/product-selector.php` の `merge_duplicates` に NON-NEGOTIABLE コメントあり）

### [1.2.0]
- 挿入指定を「方式 × デザイン × 位置」の3軸に再編（後に設定UIはスリム化、
  マーカー方式固定へ）
- バグ修正: 順位欠番の解消／類似商品の重複除去／楽天タイトルの販促ノイズ除去／
  判断軸のジャンル特化
- デザインプレビュー画面を追加

### [1.1.0]
- `auto_top3_position` 設定を追加（後に `default_position` へマイグレーション）

---

## 引き継ぎメモ（既知の未対応事項）

別チャットで続きを行う際の改修候補。v1.8.0 時点で**未対応**。

1. **マーカーモードの文脈ズレ** — `includes/inserter.php` の `process_marker_mode`。
   ranking/compare マーカーが単体マーカーと混在すると、AIに渡す
   `[[AI_PRODUCT_MARKER_N]]`（全マーカー通し番号）と描画側の単体マーカーカウンタ
   `single_counter` がズレ、単体マーカーが想定と違う文脈で選定された商品を
   表示しうる。
2. **per-heading モードの候補ソース不一致** — `includes/claude-api.php`
   `select_products_per_heading` に渡る候補はペア化前の生候補。一方 selection の
   検証は `find_by_id($all_candidates_pool, ...)`（ペア化後＝Amazonベース）に対して
   行う。AIが楽天単独の `R_` ID を選ぶとプールから消えていてスキップされる。
3. **`enable_24h_refresh` のデフォルト不一致** — `affiros-product-inserter.php` の
   activate では `'yes'`、`admin/settings.php` の sanitize では `'no'`。
4. **デッドコード** — `includes/card-renderer.php` の `build_yahoo_search_url()` は
   どのテンプレートからも未使用。
5. **欠落ドキュメント** — `includes/product-selector.php` のコメントが
   `docs/decisions/0001-amazon-base-product-cards.md` を「必ず読むこと」と参照して
   いるが、そのファイルは存在しない。
