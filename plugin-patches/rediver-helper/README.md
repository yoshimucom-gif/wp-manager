# Re:Diver ヘルパー

**通常のREST APIでは触れないWordPress／テーマの設定を、スクリプトから読み書きできるようにする。**
サイト構築を機械化するための補助プラグイン。管理者権限必須。

## なぜ必要か（re:Diver の設定がどこに入っているか）

re:Diver の設定を全面的に調べた結果、**REST経由で触れる領域と触れない領域**がはっきり分かれた。

| 設定の種類 | 保存先 | 標準RESTで触れるか |
|---|---|---|
| サイト基本情報・表示設定 | options（登録済み） | ✅ `/wp/v2/settings` |
| メニュー・ウィジェット | 専用テーブル | ✅ `/wp/v2/menus` `/wp/v2/widgets` |
| 投稿・固定ページ・LP・firstview・cta | posts | ✅ 各 rest_base |
| ブロックエディタ拡張（プリセット・配色・APIキー） | options | ✅ `/dbp/v2/*`（diver-blocks が公開済み） |
| **カテゴリー画像・タイトルレイアウト等** | **termmeta** | ❌ **見えない・書けない** |
| **記事幅など投稿ごとのテーマ設定** | **postmeta（`_` 始まり）** | ❌ **見えない・書けない** |
| **カスタマイザー／テーマ設定** | **options（`diver_*` `rd_*` のシリアライズ配列）** | ❌ **見えない・書けない** |
| **theme_mod** | theme_mods_{stylesheet} | ❌ **見えない・書けない** |

さらに、この❌の領域は**代替手段も無い**ことを実測で確認した。

- 標準RESTに `meta` を渡しても**200が返るのにDBは変わらない**（未登録キーは黙殺される。成功と誤認しやすい）
- Search Regex が扱えるのは posts / comment / user / options / post-meta / comment-meta / user-meta / terms のみで、**term-meta が無い**
- Search Regex は**シリアライズ配列を書き換えられない**（スカラーは可）＝カスタマイザー設定に届かない
- WP 6.9 の Abilities API に登録されているのはサイト情報取得系の3つだけ

このプラグインは、その❌の4領域だけを最小限に開ける。

## エンドポイント

すべて `manage_options`（管理者相当）が必要。アプリケーションパスワードで認証できる。
`GET /wp-json/rdh/v1/help` で一覧が引ける。

### 調査（キー名の発見）

| パス | 用途 |
|---|---|
| `GET /rdh/v1/termmeta?taxonomy=category` | メタを持つターム一覧。カテゴリ画像のキー発見 |
| `GET /rdh/v1/postmeta-keys?post_type=post&limit=20` | 投稿メタのキーを使用数つきで一覧。記事幅のキー発見 |
| `GET /rdh/v1/options?search=diver` | テーマ設定オプションを検索（名前・型・トップキーのみ返す） |
| `GET /rdh/v1/thememods` | theme_mod 全件 |

### 読み書き

| パス | 用途 |
|---|---|
| `GET/POST /rdh/v1/termmeta/{term_id}` | タームメタ（`{key,value}`） |
| `GET/POST /rdh/v1/postmeta/{post_id}` | 投稿メタ（`{key,value}`） |
| `POST /rdh/v1/postmeta/bulk` | 投稿メタの一括適用（`{key,post_ids,value}`） |
| `GET/POST /rdh/v1/option/{name}` | オプション（`{value,merge,dry_run}`） |
| `POST /rdh/v1/thememods` | theme_mod（`{key,value}`） |
| `GET/POST /rdh/v1/backups` | 変更前の値の一覧・復元（`{id}`） |

## テーマを壊さないための作り

1. **丸ごと上書きの事故を止める** — 既存がシリアライズ配列で、送った値に既存キーが
   足りない場合は **409 で拒否**し、消えるキー名を返す。
   `diver_color` のように1本の配列に全設定が入っている項目を、
   うっかり部分JSONで上書きして他の設定を消す事故を防ぐ。
2. **merge=true で葉だけ差し替え** — 既存配列に再帰マージするので、
   指定した項目以外は元のまま残る。
3. **dry_run=true で書かずに確認** — `before` と `would_be` を返す。一括更新にも対応。
4. **自動退避と復元** — 書き込み前の値を `rdh_backups` に最大50件保存。
   `POST /rdh/v1/backups {"id":"..."}` でいつでも戻せる。
5. **before / after / changed を必ず返す** — `update_option` はサニタイズを通るため
   「200なのに値が変わらない」ことがある。**戻り値で実際に変わったか判定できる。**

## セキュリティ

- 全エンドポイントが `manage_options` 必須。権限が無ければ 403
- **サイトが壊れる／権限昇格につながるオプションは書き込み拒否**
  `siteurl` `home` `template` `stylesheet` `active_plugins` `admin_email`
  `users_can_register` `default_role` `wp_user_roles` `db_version` `cron`
  `rewrite_rules` `recently_activated` `uninstall_plugins` `_transient_*`
- WP内部の投稿メタ（`_edit_lock` `_edit_last` `_wp_trash_meta_*`）も拒否
- オプション検索は**値を返さない**（名前・長さ・型・トップキーのみ）。APIキー等の漏れを避ける
- 更に絞りたいときは `rdh_key_allowed($allowed, $key, $context)` フィルタで上書き
- 本文を送らないので ConoHa WAF の `<script>` 判定に当たらない

## 使用例

```bash
BASE=https://example.com/wp-json/rdh/v1
AUTH='user:xxxx xxxx xxxx xxxx xxxx xxxx'

# ① カテゴリ画像のキー名を突き止める
curl -u "$AUTH" "$BASE/termmeta?taxonomy=category"

# ② カテゴリ27に画像（添付ID 1234）を設定
curl -u "$AUTH" -H 'Content-Type: application/json' \
     -d '{"key":"<①のキー>","value":"1234"}' "$BASE/termmeta/27"

# ③ 記事幅のキーを探す
curl -u "$AUTH" "$BASE/postmeta-keys?post_type=post&limit=20"

# ④ まず dry_run で確認 → 問題なければ本実行
curl -u "$AUTH" -H 'Content-Type: application/json' \
     -d '{"key":"<③のキー>","post_ids":[101,102],"value":"wide","dry_run":true}' \
     "$BASE/postmeta/bulk"

# ⑤ カスタマイザー設定を「壊さずに」1項目だけ変える
curl -u "$AUTH" -H 'Content-Type: application/json' \
     -d '{"value":{"mode":"dark"},"merge":true,"dry_run":true}' "$BASE/option/diver_color"

# ⑥ 戻したくなったら
curl -u "$AUTH" "$BASE/backups"
curl -u "$AUTH" -H 'Content-Type: application/json' -d '{"id":"<backup_id>"}' "$BASE/backups"
```

## 注意

- **キー名は必ず①③の調査エンドポイントで実物を確認してから書く。**
  推測したキーに書き込むと、テーマの編集画面と食い違うゴミメタが増える
- 一括更新は影響が大きいので、**必ず dry_run を先に通す**
- テーマ更新でキー名が変わる可能性がある。更新後は調査エンドポイントで再確認する
