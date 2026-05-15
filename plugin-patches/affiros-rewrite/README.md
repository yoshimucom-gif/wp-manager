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

### Phase 2 (予定)
- 1記事のリライト実行（Claude API 呼び出し）
- 結果プレビュー / 保存

### Phase 3 (予定)
- 品質定義のインポート（Affiros からエクスポートした JSON）
- バッチリライト（複数記事まとめて）

### Phase 4 (予定)
- マーカー挿入（Affiros の DEFAULT_CARD_INSERTION_PATTERNS を PHP 移植）
- ai-product-inserter プラグインとの連携

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
