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
| `GET /rdh/v1/options?search=diver` | テーマ設定オプションを検索（名前・型・トップキー・書き込み可否のみ返す） |
| `GET /rdh/v1/thememods` | theme_mod 全件 |

### 読み書き

| パス | 用途 |
|---|---|
| `GET/POST /rdh/v1/termmeta/{term_id}` | タームメタ（`{key,value}`） |
| `DELETE /rdh/v1/termmeta/{term_id}` | タームメタの削除（`{key}`。退避を取ってから消す） |
| `GET/POST /rdh/v1/postmeta/{post_id}` | 投稿メタ（`{key,value}`） |
| `POST /rdh/v1/postmeta/bulk` | 投稿メタの一括適用（`{key,post_ids,value}`） |
| `GET/POST /rdh/v1/option/{name}` | オプション（`{value,merge,force,dry_run}`） |
| `POST /rdh/v1/thememods` | theme_mod（`{key,value}`） |
| `GET/POST /rdh/v1/backups` | 変更前の値の一覧・復元（`{id}`） |
| `GET /rdh/v1/backups?id={backup_id}` | その退避で**何に戻るか**を先に確認する |

### 共通パラメータ

| 名前 | 効果 |
|---|---|
| `dry_run` | 書かずに `before` と `would_be` を返す |
| `merge` | オプションが配列のとき、指定した葉だけ差し替える |
| `force` | `merge` を使わず丸ごと置き換える（既存キーが消えるのを承知のとき） |
| `allow_empty` | 空文字を「削除」ではなく「空文字として保存」にする |

## テーマを壊さないための作り

1. **丸ごと上書きの事故を止める** — 既存がシリアライズ配列のとき、
   **消えるキーがある場合**も、**配列でない値で置き換えようとした場合**も **409 で拒否**し、
   何が消えるかを返す。`diver_color` のように1本の配列に全設定が入っている項目を、
   うっかり部分JSONやスカラーで上書きして他の設定を消す事故を防ぐ。
   本当に置き換えたいときだけ `force=true`。
2. **merge=true で葉だけ差し替え** — 既存配列に再帰マージするので、
   指定した項目以外は元のまま残る。
3. **dry_run=true で書かずに確認** — `before` と `would_be` を返す。一括更新にも対応。
4. **自動退避と復元** — 書き込み・削除の前の値を `rdh_backups` に最大50件保存。
   `POST /rdh/v1/backups {"id":"..."}` でいつでも戻せる。
   **一括更新は全件の変更前の値を1件の退避にまとめて保存する**ので、
   何百件書いても1回で全部戻せる（1投稿1件で積むと50件の上限を即座に溢れさせるため）。
   復元する前の値も退避されるので、復元自体も取り消せる（`undo_backup_id`）。
5. **before / after / changed を必ず返す** — `update_option` はサニタイズを通るため
   「200なのに値が変わらない」ことがある。**戻り値で実際に変わったか判定できる。**

## 値の扱い（バックスラッシュ）

WP の REST は **JSONボディのパラメータにスラッシュを付けない**。付くのはフォーム送信
（`application/x-www-form-urlencoded` / `multipart/form-data`）のときだけ。
さらに `update_post_meta` / `update_term_meta` は内部でもう1段スラッシュを外す。

このため素直に書くと `C:\path` が `C:path` に、CSSの `"\e89e"` が `"e89e"` になる。
本プラグインは**送信経路を見てから正規化し、メタ書き込みの直前で付け直す**ので、
JSONで送った値はそのまま保存される（1.1.2で修正）。

## 上限

- 一括更新の `post_ids` は **500件**まで（`RDH_BULK_MAX`）。超えたら400で返す
- 退避は **50件**まで（`RDH_BACKUP_MAX`）
- メタ一覧で添付URLを引く回数は1リクエスト **300回**まで（`RDH_DECORATE_MAX`）

## セキュリティ

- 全エンドポイントが `manage_options` 必須。権限が無ければ 403
- **サイトが壊れる／権限昇格につながるオプションは書き込み拒否**
  `siteurl` `home` `template` `stylesheet` `active_plugins` `admin_email`
  `users_can_register` `default_role` `{接頭辞}user_roles` `db_version` `cron`
  `rewrite_rules` `recently_activated` `uninstall_plugins` `_transient_*`
  （役割定義はテーブル接頭辞を変えたインストールでも実物の名前で拒否する）
- WP内部の投稿メタ（`_edit_lock` `_edit_last` `_wp_trash_meta_*`）も拒否
- **復元も書き込みなので、同じ拒否リストを通る**
- オプション検索は**値を返さない**（名前・長さ・型・トップキーのみ）。APIキー等の漏れを避ける
- 更に絞りたいときは `rdh_key_allowed($allowed, $key, $context)` フィルタで上書き
  （`$context` は `post` / `term` / `option` / `thememod`。theme_mod にも効く）
- 自動更新は **https の配布URLしか受け付けない**
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

# ⑥ 戻したくなったら（何に戻るか見てから戻す）
curl -u "$AUTH" "$BASE/backups"
curl -u "$AUTH" "$BASE/backups?id=<backup_id>"
curl -u "$AUTH" -H 'Content-Type: application/json' -d '{"id":"<backup_id>"}' "$BASE/backups"
```

## 注意

- **キー名は必ず①③の調査エンドポイントで実物を確認してから書く。**
  推測したキーに書き込むと、テーマの編集画面と食い違うゴミメタが増える
- 一括更新は影響が大きいので、**必ず dry_run を先に通す**
- テーマ更新でキー名が変わる可能性がある。更新後は調査エンドポイントで再確認する

## 変更履歴

### 1.1.2

- **JSONで送ったバックスラッシュが消える不具合を修正**（送信経路を見て正規化し、メタ書き込み直前で付け直す）
- **一括更新に退避が無かったのを修正**。全件分を1件の退避にまとめて保存し、`post_ids` は500件で頭打ち
- **配列のオプションをスカラーで丸ごと消せてしまう穴を塞いだ**（409で拒否）
- **`force=true` を実装**（それまで409のメッセージだけが案内していて、実際には効かなかった）
- タームメタの `DELETE` に退避とターム存在確認を追加
- theme_mod の書き込みも `rdh_key_allowed` フィルタを通るようにした
- 役割定義オプションの拒否をテーブル接頭辞に追従させた（`wp_user_roles` 決め打ちをやめた）
- 更新チェックが失敗したときに**失敗も5分キャッシュ**するようにした（管理画面が毎回10秒待たされるのを解消）
- 更新の配布URLは **https のみ**受け付けるようにした
- 復元も拒否リストを通し、復元前の値も退避するようにした（復元の取り消しが可能）
- `GET /backups?id=` で戻る値を確認できるようにした
- メタ一覧の添付URL取得をメモ化＋上限つきにした（問い合わせ爆発の抑制）
- `allow_empty` を追加（空文字を保存できるようにした）
- `Requires at least` / `Requires PHP` / `Update URI` ヘッダーと `uninstall.php` を追加

### 1.1.1

1.1.0 で API一覧の説明文にクォートの入れ子があり、有効化すると Parse error でサイトが停止した。説明文を書き直して修正。

### 1.1.0

外部リンクアイコンの豆腐（□）を修正。

### 1.0.0

初版。
