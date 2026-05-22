# Affiros プロダクトインサーター

AIが記事内容を解析し、Amazon・楽天市場の最適な商品アフィリエイトカードを自動挿入するWordPressプラグイン。Rinker代替を目指す。

記事本文に埋め込まれた `<!--ai-product:design-->` マーカーの位置に、AIが選定した商品カードを描画します。マーカーは wp-manager（Affiros9）で記事生成時に自動挿入されます。

## v1.9.0 主な変更点

| 種別 | 内容 |
|---|---|
| ⭐ 新機能 | 設定画面に **API接続テスト** を追加。Claude / Amazon / 楽天 の認証情報が有効か、保存前にワンクリックで検証できる（打ち間違い・無効キーのまま運用するのを防止） |

v1.8.0 以前の変更履歴は [CHANGELOG.md](CHANGELOG.md) を参照してください。

## 主な機能

- ✅ 記事本文の `<!--ai-product:design-->` マーカー位置に商品カードを自動挿入
- ✅ 6種類のカードデザイン（管理画面でプレビュー可能）
- ✅ Amazon PA-API v5 + 楽天市場API 両対応
- ✅ Claude API（Opus 4.7 / Sonnet 4.6 / Haiku 4.5）で商品を選定
- ✅ API接続テスト — 設定画面で各APIキーの有効性をワンクリック検証
- ✅ カードは常にAmazonベース。楽天で同一商品が見つかればペア化（楽天ボタン＝直リン）、見つからなければ検索URLフォールバック
- ✅ 一括処理（カテゴリ・タグで絞り込み）
- ✅ Amazon PA-API 24時間ルール対応（日次cronで期限切れフラグ）
- ✅ バックアップ・ロールバック機能

## カードデザイン

| デザイン | マーカー記法 | 商品数 | 用途 |
|---|---|---|---|
| `vertical` | `<!--ai-product:vertical-->` | 1 | 万能・主力。SEO記事の各H3直下や本文中の商品言及位置 |
| `mini` | `<!--ai-product:mini-->` | 1 | 軽量な1行カード。本文に馴染ませてさりげなく誘導 |
| `score` | `<!--ai-product:score-->` | 1 | 評価スコアを数値・ゲージで前面に。評価セクション向け |
| `proscons` | `<!--ai-product:proscons-->` | 1 | メリット・デメリット明示でCVR向上。主役商品の解説部分 |
| `compare` | `<!--ai-product:compare:N-->` | N | 上位N商品をHTMLテーブルで比較。SEO（表構造）に強い |
| `ranking` | `<!--ai-product:ranking:N-->` | N | 判断軸付きTOP N。「結局どれ？」への最終提示 |

`compare` / `ranking` の多商品カードは、記事内の単体マーカーでAIが選定した商品を流用します（本文の内容と一致する保証のため）。

各デザインの実物は管理画面 **「AI商品挿入 > 🎨 デザインプレビュー」** で実テンプレート + ダミーデータで確認できます。

## システム要件

- WordPress 6.0 以上
- PHP 7.4 以上
- Anthropic Claude APIキー
- Amazon PA-API v5アクセス（または楽天市場APIアプリID）

## インストール

1. `affiros-product-inserter-1.8.0.zip` をWordPress管理画面の「プラグイン > 新規追加 > プラグインのアップロード」からアップロード
2. 有効化（旧プラグイン `ai-product-inserter` が有効な場合は先に停止・削除）
3. 「AI商品挿入 > 設定」で各種APIキーを入力

## 使い方

wp-manager（Affiros9）で記事を生成すると、本文に `<!--ai-product:vertical-->` や `<!--ai-product:ranking:3-->` のようなマーカーが自動で埋め込まれます。

1. 記事をWordPressに投稿・保存
2. 編集画面の **「🛒 AI商品挿入」メタボックス** で「商品挿入を実行」をクリック
3. AIがマーカー位置に最適な商品カードを描画

カードの位置・デザイン・件数は wp-manager 側のマーカーで決定済みのため、本プラグインで設定するのは API認証情報と運用調整値（候補取得数・24時間ルール）だけです。

> マーカーはHTMLコメントです。WordPressの「ビジュアル」エディタはコメントを削除する場合があるため、「コード」エディタかGutenbergのカスタムHTMLブロックで扱ってください。

## アーキテクチャ

```
affiros-product-inserter/
├── affiros-product-inserter.php  # プラグインメイン
├── includes/
│   ├── claude-api.php            # Claude API クライアント
│   ├── amazon-api.php            # Amazon PA-API v5
│   ├── rakuten-api.php           # 楽天市場API
│   ├── product-selector.php      # 商品選定・ペア化・楽天タイトルクリーニング・類似度判定
│   ├── card-renderer.php         # カードHTMLレンダリング
│   ├── inserter.php              # 記事へのマーカー置換挿入処理
│   └── post-meta.php             # メタデータ管理
├── admin/
│   ├── settings.php              # 設定画面（API認証・運用調整）
│   ├── meta-box.php              # 編集画面メタボックス
│   ├── bulk-process.php          # 一括処理
│   ├── design-preview.php        # デザインプレビュー画面
│   ├── ajax-handler.php          # AJAX
│   └── logs.php                  # 処理ログ
├── templates/
│   ├── card-vertical.php         # 縦置きカード
│   ├── card-mini.php             # ミニカード
│   ├── card-score.php            # スコアカード
│   ├── card-proscons.php         # Pros/Consカード
│   ├── card-compare.php          # 比較表
│   └── card-ranking.php          # ランキングカード
├── prompts/
│   ├── keyword-extraction.txt
│   ├── product-selection-marker.txt
│   ├── product-selection-per-heading.txt
│   └── product-selection-ranking.txt
└── assets/
    ├── admin.css
    ├── admin.js
    └── frontend.css
```

## トラブルシューティング

### 楽天タイトルがまだ販促ノイズだらけに見える

`includes/product-selector.php` の `clean_rakuten_title()` に新しいパターンを追加してください。先頭から販促文言を反復的に剥がす設計です。

### AIが類似商品ばかり選ぶ

`prompts/product-selection-ranking.txt` の「商品の多様性」セクションを強化、または `dedupe_by_similarity()` の閾値（デフォルト 0.5）を下げてください。

### マーカーが処理されない

「マーカー検出: 0個」と出る場合、本文のHTMLコメントがエディタに削除されています。「コード」エディタで `<!--ai-product:vertical-->` が残っているか確認してください。

### 商品カードにレビュー（星）が表示されない

Amazon PA-APIはレビューを返さない仕様です。本プラグインは楽天で同一商品のペアが成立した場合のみ、そのレビューを引き継いで表示します。ペアが成立しない商品（楽天に同一商品が無い）はレビュー非表示のままになります。

## 既知の制限

- Amazon PA-APIは未承認アソシエイトプログラム参加者は使用不可
- Amazon PA-APIはレビュー（評価・件数）を返さない。レビューは楽天ペア成立時のみ表示
- 楽天市場APIは1日のリクエスト上限あり
- AI商品選定は完璧ではない。最初は5〜10件で必ずテスト実行を推奨
