# Affiros リライト プラグイン

WordPress 記事を Claude API でリライトする WP プラグイン。

## 設計目的

Affiros（外部 SaaS）から WP REST API 経由でリライトしようとすると、
ホスティング（ConoHa など）の WAF / 海外 IP 制限で 403 になるケースが多い。

このプラグインは **WP 内部関数（WP_Query / wp_update_post）だけで完結** するため、
ホスティングのファイアウォール設定の影響を受けない。

## 機能（Phase 別）

### Phase 1 (実装済)
- プラグイン設定画面（Claude APIキー、リライト動作デフォルト）
- 投稿一覧（WP_Query で取得・REST API 不使用）
- カテゴリー / 公開状態 / 検索 フィルタ
- ページネーション

### Phase 2 (実装済 / v0.2.0)
- 1記事のリライト実行（Claude API 呼び出し）
- 結果プレビュー（元記事と左右比較・編集可）
- WP投稿への上書き保存（リビジョンで個別ロールバック可）
- 一括リライト（複数選択 → 進捗バー表示で順次実行）

### Phase 3 (実装済 / v0.3.0)
- 品質プリセット管理画面（追加/編集/削除）
- Affiros の品質定義 JSON インポート
- リライト実行時にプリセット選択 → article_type / target_chars / tone / custom prompt / reference_url を上書き

### Phase 4 (実装済 / v0.3.0)
- マーカー挿入（Affiros の DEFAULT_CARD_INSERTION_PATTERNS を PHP 移植）
- 記事タイプ別の規則: ranking / brand / column
- 位置: each_h3 / after_first_h2 / before_first_h2 / after_matome_h2 / before_matome_h2
- デザイン: vertical / ranking(count)
- Affiros プロダクトインサーター（affiros-product-inserter）プラグインが
  `<!--ai-product:...-->` マーカーを実際の商品カードに置換

### v0.3.1
- Claude モデルIDを wp_manager 本体と統一（Sonnet 4.6 / Opus 4.7 / Haiku 4.5）
- 旧モデルID（Sonnet 4.5 / Opus 4.1 / Haiku 3.5）は読み込み時に自動マイグレーション

## インストール

WP 管理画面 → プラグイン → 新規追加 → アップロード → ZIP を選択 → インストール → 有効化

## 設定

1. WP 管理画面 → Affiros リライト → 設定
2. Claude APIキーを入力（Anthropic Console で発行）
3. 「設定を保存」

## 使い方

1. WP 管理画面 → Affiros リライト → リライト実行
2. 「投稿を取得」で記事一覧表示
3. （Phase 2 以降）チェックボックスで選択 → リライト実行

## ライセンス

GPL v2 or later
