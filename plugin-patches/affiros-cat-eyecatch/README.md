# Affiros カテゴリーアイキャッチ

カテゴリーごとにアイキャッチ画像を設定し、**アイキャッチが未設定の記事**に自動で適用する
WordPress プラグイン。記事量産サイトで「アイキャッチだけ空のまま」を潰すためのもの。

## できること

| 機能 | 場所 |
|---|---|
| カテゴリーに画像を設定 | 投稿 → カテゴリー → 編集画面「カテゴリーアイキャッチ」 |
| カテゴリー一覧でサムネイル確認 | 投稿 → カテゴリー（一覧の「アイキャッチ」列） |
| 動作設定・カバー状況の確認 | 設定 → カテゴリーアイキャッチ |
| 実アイキャッチとして一括書き込み／取り消し | 同上（ページ下部） |

## 適用の優先順位

1. 記事に設定された**実アイキャッチ**（あればこれが常に勝つ）
2. **主要カテゴリー**の画像（Yoast SEO / Rank Math の primary term を読む）
3. 記事が属する残りのカテゴリーを term_id 昇順に見て、最初に画像を持つもの
4. 画像のないカテゴリーは**親カテゴリーを遡って継承**（設定でオフ可）
5. **全体のデフォルト画像**（設定画面で指定、任意）

## 仮想適用について

既定では記事のデータベースに書き込まず、`_thumbnail_id` の読み出しに割り込んで返す。

- `has_post_thumbnail()` / `the_post_thumbnail()` / `get_the_post_thumbnail_url()` が
  そのまま追随するので、**テーマの改修は不要**
- 各SEOプラグインの OGP 画像にも乗る
- 記事側にアイキャッチを設定すればそちらが優先され、プラグインを止めれば元通り

**管理画面と REST API では意図的に無効。** ブロックエディタは REST 経由で
`featured_media` を読むため、そこで仮想値を返すと「記事を開いて保存しただけで
アイキャッチが焼き付く」事故になる。

REST 経由でも確実に持たせたい場合（headless、一部のSNS連携やキャッシュ系プラグイン）は
設定画面の**一括適用**で実体として書き込む。書き込んだ記事には目印を残すので、
**一括取り消し**で未設定状態に戻せる（あとから手で差し替えた記事は巻き込まない）。

## フィルタ

```php
// 無限スクロール等でフロントの admin-ajax にも効かせたい場合
add_filter('affiros_cat_eyecatch_enable_fallback', function ($active) {
    if (wp_doing_ajax() && isset($_REQUEST['action']) && $_REQUEST['action'] === 'my_infinite_scroll') {
        return true;
    }
    return $active;
});

// 主要カテゴリーの判定を独自に差し替える
add_filter('affiros_cat_eyecatch_primary_term_id', function ($term_id, $post_id, $taxonomy) {
    return $term_id;
}, 10, 3);
```

## 自動更新

`includes/plugin-updater.php` が `AFFIROS_UPDATE_HOST`（既定は mu-plugin で
`https://ke-ys.co.jp`）の `/api/plugin-update/cat-eyecatch` を見て、新しい版が
配信されていれば WP 管理画面に更新通知を出す。

## ファイル構成

```
affiros-cat-eyecatch/
├── affiros-cat-eyecatch.php   # ヘッダー・定数・アセット読み込み
├── includes/
│   ├── settings.php           # 設定の読み書きとサニタイズ
│   ├── resolver.php           # 「どの画像を使うか」の判定ロジック（正本）
│   ├── fallback.php           # フロントの仮想適用（get_post_metadata フィルタ）
│   ├── term-fields.php        # カテゴリー編集画面のUIと保存
│   ├── admin-page.php         # 設定画面
│   ├── bulk-tool.php          # 一括書き込み／取り消し（AJAX）
│   └── plugin-updater.php     # 自動更新チェッカー（Affiros 共通）
└── assets/
    ├── admin.js
    └── admin.css
```
