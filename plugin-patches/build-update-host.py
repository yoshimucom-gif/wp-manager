# -*- coding: utf-8 -*-
"""Affiros プラグイン自動更新メタ (GitHub直配信) を静的生成する。

配信方式 (2026-08-09〜):
  - 更新チェックJSON: https://raw.githubusercontent.com/yoshimucom-gif/wp-manager/main/plugin-host/api/plugin-update/<key>
  - zip本体:          https://raw.githubusercontent.com/yoshimucom-gif/wp-manager/main/plugin-downloads/<file>
  リポジトリが公開なので raw がそのまま配信になる。サーバー・FTP・Actions 不要。
  push した瞬間が配信完了 (raw のキャッシュは数分)。

  旧方式の変遷: Render (〜2026-07-29 Suspend) → ke-ys.co.jp FTPS (〜2026-07-30、
  サーバー側フォルダ消失で死亡) → GitHub直配信 (現行)。

使い方: py plugin-patches/build-update-host.py
  app.py の PLUGIN_DOWNLOADS / PLUGIN_UPDATE_META を ast で読み取り (転記ミス防止)、
  plugin-host/api/plugin-update/ に JSON を吐く。生成物はコミットして push する。
"""
import ast
import json
import os
import shutil

CODEX = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
APP_PY = os.path.join(CODEX, "app.py")
ZIP_SRC = os.path.join(CODEX, "plugin-downloads")
OUT = os.path.join(CODEX, "plugin-host")

RAW_BASE = "https://raw.githubusercontent.com/yoshimucom-gif/wp-manager/main"

GH_HANDOVER = (
    "残り9本の更新チェック先を、停止した Render (wp-manager.onrender.com) から "
    "GitHub直配信の固定URLへ変更。7月末から止まっていた自動更新が復旧する。"
    "プラグインの機能そのものに変更はない。"
)
REDIVER_112 = (
    "1.1.2 — 洗い出した不具合の一括修正。(1) JSONで送ったバックスラッシュが消える不具合を修正。(2) 一括更新に退避が無く戻せなかったのを修正し、全件分を1件にまとめて保存・500件で頭打ち。(3) 配列のオプションをスカラーで丸ごと消せてしまう穴を塞いだ。(4) 案内だけで実装が無かった force=true を実装。(5) タームメタのDELETEに退避と存在確認を追加。(6) theme_mod もキーのガードを通るようにした。(7) 役割定義オプションの拒否をテーブル接頭辞に追従。(8) 更新チェックの失敗を5分キャッシュし、管理画面が毎回10秒待たされるのを解消。(9) 配布URLはhttpsのみ受け付ける。(10) 復元前の値も退避し復元を取り消せるようにした。"
)

# 更新画面に出る changelog の上書き。(キー, 版) 単位で持つ。
#   版まで含めて一致したときだけ使うので、次の版に上げれば自動で既定文に戻る
#   (古い説明が新しい版に貼り付いたまま配信される事故を防ぐため)。
# ここに無いものは "最新バージョン <版>" になる。
#   ※この仕組みが無かった頃、本スクリプトを回すと手書きの changelog が
#     既定文で上書きされて消えていた (2026-09-07 に rediver-helper 1.1.2 で発覚)。
CHANGELOGS = {
    ("auto-inserter",      "0.21.2"): "0.21.2 — サイドバーの商品カード（[affiros_ai_top]）のAmazonリンクを、表示のたびにいまの設定のトラッキングIDで付け直すようにした（商品データに保存時のIDが残っていて、IDを切り替えてもサイドバーだけ古いIDのままになっていた）。 / 0.21.1 — おすすめ記事のAmazon／楽天ボタンを同じ幅にそろえて広げる（PCは横並び各320px、スマホは縦積みの全幅）。記事の書き換えは不要。 / 0.21.0 — セールのマイクロコピーを、おすすめ記事の標準ボタン（Amazonで見る／楽天市場で見る）にも表示。リンク先でモールを判定し、比較表などのテキストリンクには出さない。片方のモールだけセール中でも横並びボタンの下端をそろえる。記事の書き換えは不要。 / 0.20.3 — 挿入位置の設定が両方オフのとき、誤解を招く「H2/まとめが本文にない」ではなく設定オフであることをエラーに明示。 / 0.20.2 — ランキング記事の既定タイトルパターン「選」を「\\d+選」に修正。「選び方」を含むタイトルがランキング扱いになり自動挿入がスキップされる誤判定を解消（既に保存済みの設定があるサイトは保存値が優先されるため挙動は変わりません）。",
    ("auto-inserter",      "0.21.0"): "0.21.0 — セールのマイクロコピーを、おすすめ記事の標準ボタン（Amazonで見る／楽天市場で見る）にも表示。リンク先でモールを判定し、比較表などのテキストリンクには出さない。片方のモールだけセール中でも横並びボタンの下端をそろえる。記事の書き換えは不要。 / 0.20.3 — 挿入位置の設定が両方オフのとき、誤解を招く「H2/まとめが本文にない」ではなく設定オフであることをエラーに明示。 / 0.20.2 — ランキング記事の既定タイトルパターン「選」を「\\d+選」に修正。「選び方」を含むタイトルがランキング扱いになり自動挿入がスキップされる誤判定を解消（既に保存済みの設定があるサイトは保存値が優先されるため挙動は変わりません）。",
    ("fukujimai-cta",      "1.0.3"): "1.0.3 — CTAの角丸をやめた。囲みもボタンも直角にして、記事本文の図（角丸）と役割の違いが出るようにした。",
    ("fukujimai-cta",      "1.0.2"): "1.0.2 — CTAの見た目を記事本文の囲みに合わせた。余白が無く左端に貼り付いていたのを、本文の図と同じ余白・角丸・罫の太さで組み直し、地と罫の色だけ変えて見分けがつくようにした。定型の小見出し（キッカー）は廃止。まとめのあとの他社リンクは箱の外に出して、主役との差をつけた。",
    ("fukujimai-cta",      "1.0.1"): "1.0.1 — 無料ツールのページ（サイドバーを出さない設定の記事）でPCのバナーが出なかったのを修正（置き場所が無いので画面右下に固定する）。追従バナーの重ね順をテーマの実測値に合わせ、ポップアップや目次が開いたときは前に出ないようにした。 / 1.0.0 — 記事のカテゴリに応じて買取サービスのCTAを本文の3箇所（最初のh2の上・途中・まとめのあと）に出し、PCはサイドバー追従・スマホは画面下に固定でバナーを出す。喪服・着物／制服・体操服／靴・家具のカテゴリには出さない。",
    ("fukujimai-cta",      "1.0.0"): "1.0.0 — 記事のカテゴリに応じて買取サービスのCTAを本文の3箇所（最初のh2の上・途中・まとめのあと）に出し、PCはサイドバー追従・スマホは画面下に固定でバナーを出す。喪服・着物／制服・体操服／靴・家具のカテゴリには出さない。",
    ("cat-eyecatch",       "1.0.1"): GH_HANDOVER,
    ("categorizer",        "0.1.2"): GH_HANDOVER,
    ("decoration",         "1.2.4"): GH_HANDOVER,
    ("dup-cleaner",        "1.0.2"): GH_HANDOVER,
    ("mark-stripper",      "1.0.1"): GH_HANDOVER,
    ("paragraph-splitter", "1.1.6"): GH_HANDOVER,
    ("product-inserter",   "1.10.1"): GH_HANDOVER,
    ("reschedule",         "1.1.1"): GH_HANDOVER,
    ("rewrite",            "0.5.18"): GH_HANDOVER,
    ("rewrite",            "0.5.19"): "0.5.19 — リライトの執筆モデルを Claude Opus 5.5 に固定。保存済みの Sonnet 4.6 / Opus 4.7 / Haiku 4.5 の設定は自動で Opus 5.5 に切り替わる（設定の保存し直しは不要）。Opus 5.5 は考えてから書くため、出力上限と待ち時間（300秒）を広げた。",
    ("rediver-helper",     "1.1.2"): REDIVER_112,
    ("rediver-helper",     "1.11.0"): "1.11.0 — 目次（.rd-toc）に出るアイコンの豆腐（□）を直した。見出しの頭に diver-blocks のインラインアイコンを置くと、re:Diver の目次がアイコンの文字（Unicode の私用領域）までそのまま拾い、目次では □ になっていた（商標記事の「選ばれる3つの理由」の箱で必ず起きる）。表に出る HTML のうち目次のリンク（href=\"#toc-id-…\"）の中だけから私用領域の文字を外す。本文の見出しのアイコンは残り、記事本文（DB）には触らない。止めるときは add_filter('rdh_toc_icon_fix', '__return_false')。 / 1.10.0 — 本文に直書きされたAmazonのトラッキングIDを別のIDに置き換える POST /rdh/v1/amazon-tag {from,to,dry_run} を追加。更新日（post_modified）を動かさず、save_post も走らせない（サイトマップの lastmod が全記事でそろうのを避け、オートインサーターの商品の取り直しも起こさない）。対象は投稿・固定ページ・再利用ブロック。1回500件まで。 / 1.9.0 — 自前配信プラグイン（Affiros系など）をその場で更新する POST /rdh/v1/plugin-update {plugin} を追加。配信元が自前（GitHub yoshimucom-gif / ke-ys.co.jp）のプラグインだけが対象。更新中に無効になったものは、更新前に有効だったものに限って有効に戻す。 / 1.8.1 — IndexNow を導入した直後、検索エンジン側のキー確認が終わるまで返る 403（SiteVerificationNotCompleted）も10分後の再送の対象にした（初回の自動通知が落ちないように）。 / 1.8.0 — IndexNow で Bing（Copilot・DuckDuckGo の土台）に更新を知らせる機能を追加。記事の公開・更新・非公開/ゴミ箱を検知してURLを1分後にまとめて送る。キーは自動生成し / .txt で返す（ファイル不要）。サイトが検索エンジン非表示・記事ごとの noindex・パスワード付きは送らない。GET /rdh/v1/indexnow で状態と直近30回の送信ログ、POST /rdh/v1/indexnow {all:true} で公開中の全URLを一括送信。止めるときは option rdh_indexnow_off=1。 / 1.7.1 — Flamingoの期間集計を /?rdh_flamingo_summary=1 でも返すようにした（Xserverの国外IPアクセス制限で /wp-json/ が海外から403になり、Google Apps Scriptから呼べないため）。認証はRESTと同じアプリケーションパスワード。 / 1.7.0 — Flamingo（CF7の受信記録）の期間集計 GET /rdh/v1/flamingo-summary を追加。指定項目（既定 price）の合計・件数を返す。受信トレイだけを数え、サイト自身のドメイン・指定ドメイン・「テスト」「test」を含む送信は除外して理由つきで返す。 / 1.6.0 — サイト所有確認の （Pinterest の p:domain_verify・Google・Bing など）をオプション rdh_head_meta から に出す機能を追加（テーマを編集せずに REST で入れられる。出せるのは name/content の meta だけ。無効化フィルタ rdh_head_meta_output）。 / 1.5.0 — ads.txt をオプション rdh_ads_txt から配信する機能を追加（サーバーにファイルを置かずに REST で書ける。空なら配信しない。無効化フィルタ rdh_ads_txt_serve）。 / 1.4.1 — noindex にしたページを WP標準のサイトマップ（wp-sitemap.xml）から外す（Search Console の送信エラーを防ぐ）。 / 1.4.0 — (1) 編集画面のSEO設定（noindex / nofollow）が に出ないテーマの不具合を、wp_robots で同じ値を足して修正（無効化フィルタ rdh_single_robots_fix）。(2) option rdh_cf7_style を 1 にしたサイトだけ、Contact Form 7 のフォームをサイトの配色で整える（既定はオフ）。(3) 新版の即時適用 POST /rdh/v1/selfupdate を追加。 / 1.3.0 — 著者アバターをメディアライブラリの画像に差し替える機能を追加。option rdh_avatar_map に「ユーザーID: 添付ファイルID」を入れると、記事下の著者欄・コメント欄・著者アーカイブのアバターが差し替わる。Gravatarへの画像登録は不要。無効化するフィルタ名は rdh_avatar_override。 / 1.2.1 — diver-keys の記述を実測に合わせて訂正。ブロックのプリセット色は diver_color_custom から自動生成されるので設定不要（設定直後だけ transient のせいで古い色に見えることがある）。グローバルスタイルの上書きや追加CSSに逃げないよう注意書きを追加。 / 1.2.0 — re:Diverの設定キー索引 GET /rdh/v1/diver-keys を追加。配色（theme_mod diver_color ＋ diver_color_custom）・ブロックのプリセット色（グローバルスタイルのパレット）・見出しの色（diver_content_heading）・メインビジュアル・記事幅・カテゴリ画像・追加CSSが、どこに何というキーで入っていて、どう書くかを現在値つきで1回で返す。さらに「色は入っているのに diver_color.isCustom が立っておらず、テーマが既定の配色のまま描画している」状態を検出して warnings に出す。 / 1.1.2 — 洗い出した不具合の一括修正。(1) JSONで送ったバックスラッシュが消える不具合を修正。(2) 一括更新に退避が無く戻せなかったのを修正し、全件分を1件にまとめて保存・500件で頭打ち。(3) 配列のオプションをスカラーで丸ごと消せてしまう穴を塞いだ。(4) 案内だけで実装が無かった force=true を実装。(5) タームメタのDELETEに退避と存在確認を追加。(6) theme_mod もキーのガードを通るようにした。(7) 役割定義オプションの拒否をテーブル接頭辞に追従。(8) 更新チェックの失敗を5分キャッシュし、管理画面が毎回10秒待たされるのを解消。(9) 配布URLはhttpsのみ受け付ける。(10) 復元前の値も退避し復元を取り消せるようにした。",
    ("rediver-helper",     "1.10.0"): "1.10.0 — 本文に直書きされたAmazonのトラッキングIDを別のIDに置き換える POST /rdh/v1/amazon-tag {from,to,dry_run} を追加。更新日（post_modified）を動かさず、save_post も走らせない（サイトマップの lastmod が全記事でそろうのを避け、オートインサーターの商品の取り直しも起こさない）。対象は投稿・固定ページ・再利用ブロック。1回500件まで。 / 1.9.0 — 自前配信プラグイン（Affiros系など）をその場で更新する POST /rdh/v1/plugin-update {plugin} を追加。配信元が自前（GitHub yoshimucom-gif / ke-ys.co.jp）のプラグインだけが対象。更新中に無効になったものは、更新前に有効だったものに限って有効に戻す。 / 1.8.1 — IndexNow を導入した直後、検索エンジン側のキー確認が終わるまで返る 403（SiteVerificationNotCompleted）も10分後の再送の対象にした（初回の自動通知が落ちないように）。 / 1.8.0 — IndexNow で Bing（Copilot・DuckDuckGo の土台）に更新を知らせる機能を追加。記事の公開・更新・非公開/ゴミ箱を検知してURLを1分後にまとめて送る。キーは自動生成し / .txt で返す（ファイル不要）。サイトが検索エンジン非表示・記事ごとの noindex・パスワード付きは送らない。GET /rdh/v1/indexnow で状態と直近30回の送信ログ、POST /rdh/v1/indexnow {all:true} で公開中の全URLを一括送信。止めるときは option rdh_indexnow_off=1。 / 1.7.1 — Flamingoの期間集計を /?rdh_flamingo_summary=1 でも返すようにした（Xserverの国外IPアクセス制限で /wp-json/ が海外から403になり、Google Apps Scriptから呼べないため）。認証はRESTと同じアプリケーションパスワード。 / 1.7.0 — Flamingo（CF7の受信記録）の期間集計 GET /rdh/v1/flamingo-summary を追加。指定項目（既定 price）の合計・件数を返す。受信トレイだけを数え、サイト自身のドメイン・指定ドメイン・「テスト」「test」を含む送信は除外して理由つきで返す。 / 1.6.0 — サイト所有確認の （Pinterest の p:domain_verify・Google・Bing など）をオプション rdh_head_meta から に出す機能を追加（テーマを編集せずに REST で入れられる。出せるのは name/content の meta だけ。無効化フィルタ rdh_head_meta_output）。 / 1.5.0 — ads.txt をオプション rdh_ads_txt から配信する機能を追加（サーバーにファイルを置かずに REST で書ける。空なら配信しない。無効化フィルタ rdh_ads_txt_serve）。 / 1.4.1 — noindex にしたページを WP標準のサイトマップ（wp-sitemap.xml）から外す（Search Console の送信エラーを防ぐ）。 / 1.4.0 — (1) 編集画面のSEO設定（noindex / nofollow）が に出ないテーマの不具合を、wp_robots で同じ値を足して修正（無効化フィルタ rdh_single_robots_fix）。(2) option rdh_cf7_style を 1 にしたサイトだけ、Contact Form 7 のフォームをサイトの配色で整える（既定はオフ）。(3) 新版の即時適用 POST /rdh/v1/selfupdate を追加。 / 1.3.0 — 著者アバターをメディアライブラリの画像に差し替える機能を追加。option rdh_avatar_map に「ユーザーID: 添付ファイルID」を入れると、記事下の著者欄・コメント欄・著者アーカイブのアバターが差し替わる。Gravatarへの画像登録は不要。無効化するフィルタ名は rdh_avatar_override。 / 1.2.1 — diver-keys の記述を実測に合わせて訂正。ブロックのプリセット色は diver_color_custom から自動生成されるので設定不要（設定直後だけ transient のせいで古い色に見えることがある）。グローバルスタイルの上書きや追加CSSに逃げないよう注意書きを追加。 / 1.2.0 — re:Diverの設定キー索引 GET /rdh/v1/diver-keys を追加。配色（theme_mod diver_color ＋ diver_color_custom）・ブロックのプリセット色（グローバルスタイルのパレット）・見出しの色（diver_content_heading）・メインビジュアル・記事幅・カテゴリ画像・追加CSSが、どこに何というキーで入っていて、どう書くかを現在値つきで1回で返す。さらに「色は入っているのに diver_color.isCustom が立っておらず、テーマが既定の配色のまま描画している」状態を検出して warnings に出す。 / 1.1.2 — 洗い出した不具合の一括修正。(1) JSONで送ったバックスラッシュが消える不具合を修正。(2) 一括更新に退避が無く戻せなかったのを修正し、全件分を1件にまとめて保存・500件で頭打ち。(3) 配列のオプションをスカラーで丸ごと消せてしまう穴を塞いだ。(4) 案内だけで実装が無かった force=true を実装。(5) タームメタのDELETEに退避と存在確認を追加。(6) theme_mod もキーのガードを通るようにした。(7) 役割定義オプションの拒否をテーブル接頭辞に追従。(8) 更新チェックの失敗を5分キャッシュし、管理画面が毎回10秒待たされるのを解消。(9) 配布URLはhttpsのみ受け付ける。(10) 復元前の値も退避し復元を取り消せるようにした。",
    ("rediver-helper",     "1.8.1"): "1.8.1 — IndexNow を導入した直後、検索エンジン側のキー確認が終わるまで返る 403（SiteVerificationNotCompleted）も10分後の再送の対象にした（初回の自動通知が落ちないように）。 / 1.8.0 — IndexNow で Bing（Copilot・DuckDuckGo の土台）に更新を知らせる機能を追加。記事の公開・更新・非公開/ゴミ箱を検知してURLを1分後にまとめて送る。キーは自動生成し /<キー>.txt で返す（ファイル不要）。サイトが検索エンジン非表示・記事ごとの noindex・パスワード付きは送らない。GET /rdh/v1/indexnow で状態と直近30回の送信ログ、POST /rdh/v1/indexnow {all:true} で公開中の全URLを一括送信。止めるときは option rdh_indexnow_off=1。 / 1.7.1 — Flamingoの期間集計を /?rdh_flamingo_summary=1 でも返すようにした（Xserverの国外IPアクセス制限で /wp-json/ が海外から403になり、Google Apps Scriptから呼べないため）。認証はRESTと同じアプリケーションパスワード。 / 1.7.0 — Flamingo（CF7の受信記録）の期間集計 GET /rdh/v1/flamingo-summary を追加。指定項目（既定 price）の合計・件数を返す。受信トレイだけを数え、サイト自身のドメイン・指定ドメイン・「テスト」「test」を含む送信は除外して理由つきで返す。 / 1.6.0 — サイト所有確認の <meta>（Pinterest の p:domain_verify・Google・Bing など）をオプション rdh_head_meta から <head> に出す機能を追加（テーマを編集せずに REST で入れられる。出せるのは name/content の meta だけ。無効化フィルタ rdh_head_meta_output）。 / 1.5.0 — ads.txt をオプション rdh_ads_txt から配信する機能を追加（サーバーにファイルを置かずに REST で書ける。空なら配信しない。無効化フィルタ rdh_ads_txt_serve）。 / 1.4.1 — noindex にしたページを WP標準のサイトマップ（wp-sitemap.xml）から外す（Search Console の送信エラーを防ぐ）。 / 1.4.0 — (1) 編集画面のSEO設定（noindex / nofollow）が <meta name=\"robots\"> に出ないテーマの不具合を、wp_robots で同じ値を足して修正（無効化フィルタ rdh_single_robots_fix）。(2) option rdh_cf7_style を 1 にしたサイトだけ、Contact Form 7 のフォームをサイトの配色で整える（既定はオフ）。(3) 新版の即時適用 POST /rdh/v1/selfupdate を追加。 / 1.3.0 — 著者アバターをメディアライブラリの画像に差し替える機能を追加。option rdh_avatar_map に「ユーザーID: 添付ファイルID」を入れると、記事下の著者欄・コメント欄・著者アーカイブのアバターが差し替わる。Gravatarへの画像登録は不要。無効化するフィルタ名は rdh_avatar_override。 / 1.2.1 — diver-keys の記述を実測に合わせて訂正。ブロックのプリセット色は diver_color_custom から自動生成されるので設定不要（設定直後だけ transient のせいで古い色に見えることがある）。グローバルスタイルの上書きや追加CSSに逃げないよう注意書きを追加。 / 1.2.0 — re:Diverの設定キー索引 GET /rdh/v1/diver-keys を追加。配色（theme_mod diver_color ＋ diver_color_custom）・ブロックのプリセット色（グローバルスタイルのパレット）・見出しの色（diver_content_heading）・メインビジュアル・記事幅・カテゴリ画像・追加CSSが、どこに何というキーで入っていて、どう書くかを現在値つきで1回で返す。さらに「色は入っているのに diver_color.isCustom が立っておらず、テーマが既定の配色のまま描画している」状態を検出して warnings に出す。 / 1.1.2 — 洗い出した不具合の一括修正。(1) JSONで送ったバックスラッシュが消える不具合を修正。(2) 一括更新に退避が無く戻せなかったのを修正し、全件分を1件にまとめて保存・500件で頭打ち。(3) 配列のオプションをスカラーで丸ごと消せてしまう穴を塞いだ。(4) 案内だけで実装が無かった force=true を実装。(5) タームメタのDELETEに退避と存在確認を追加。(6) theme_mod もキーのガードを通るようにした。(7) 役割定義オプションの拒否をテーブル接頭辞に追従。(8) 更新チェックの失敗を5分キャッシュし、管理画面が毎回10秒待たされるのを解消。(9) 配布URLはhttpsのみ受け付ける。(10) 復元前の値も退避し復元を取り消せるようにした。",
    ("rediver-helper",     "1.8.0"): "1.8.0 — IndexNow で Bing（Copilot・DuckDuckGo の土台）に更新を知らせる機能を追加。記事の公開・更新・非公開/ゴミ箱を検知してURLを1分後にまとめて送る。キーは自動生成し /<キー>.txt で返す（ファイル不要）。サイトが検索エンジン非表示・記事ごとの noindex・パスワード付きは送らない。GET /rdh/v1/indexnow で状態と直近30回の送信ログ、POST /rdh/v1/indexnow {all:true} で公開中の全URLを一括送信。止めるときは option rdh_indexnow_off=1。 / 1.7.1 — Flamingoの期間集計を /?rdh_flamingo_summary=1 でも返すようにした（Xserverの国外IPアクセス制限で /wp-json/ が海外から403になり、Google Apps Scriptから呼べないため）。認証はRESTと同じアプリケーションパスワード。 / 1.7.0 — Flamingo（CF7の受信記録）の期間集計 GET /rdh/v1/flamingo-summary を追加。指定項目（既定 price）の合計・件数を返す。受信トレイだけを数え、サイト自身のドメイン・指定ドメイン・「テスト」「test」を含む送信は除外して理由つきで返す。 / 1.6.0 — サイト所有確認の <meta>（Pinterest の p:domain_verify・Google・Bing など）をオプション rdh_head_meta から <head> に出す機能を追加（テーマを編集せずに REST で入れられる。出せるのは name/content の meta だけ。無効化フィルタ rdh_head_meta_output）。 / 1.5.0 — ads.txt をオプション rdh_ads_txt から配信する機能を追加（サーバーにファイルを置かずに REST で書ける。空なら配信しない。無効化フィルタ rdh_ads_txt_serve）。 / 1.4.1 — noindex にしたページを WP標準のサイトマップ（wp-sitemap.xml）から外す（Search Console の送信エラーを防ぐ）。 / 1.4.0 — (1) 編集画面のSEO設定（noindex / nofollow）が <meta name=\"robots\"> に出ないテーマの不具合を、wp_robots で同じ値を足して修正（無効化フィルタ rdh_single_robots_fix）。(2) option rdh_cf7_style を 1 にしたサイトだけ、Contact Form 7 のフォームをサイトの配色で整える（既定はオフ）。(3) 新版の即時適用 POST /rdh/v1/selfupdate を追加。 / 1.3.0 — 著者アバターをメディアライブラリの画像に差し替える機能を追加。option rdh_avatar_map に「ユーザーID: 添付ファイルID」を入れると、記事下の著者欄・コメント欄・著者アーカイブのアバターが差し替わる。Gravatarへの画像登録は不要。無効化するフィルタ名は rdh_avatar_override。 / 1.2.1 — diver-keys の記述を実測に合わせて訂正。ブロックのプリセット色は diver_color_custom から自動生成されるので設定不要（設定直後だけ transient のせいで古い色に見えることがある）。グローバルスタイルの上書きや追加CSSに逃げないよう注意書きを追加。 / 1.2.0 — re:Diverの設定キー索引 GET /rdh/v1/diver-keys を追加。配色（theme_mod diver_color ＋ diver_color_custom）・ブロックのプリセット色（グローバルスタイルのパレット）・見出しの色（diver_content_heading）・メインビジュアル・記事幅・カテゴリ画像・追加CSSが、どこに何というキーで入っていて、どう書くかを現在値つきで1回で返す。さらに「色は入っているのに diver_color.isCustom が立っておらず、テーマが既定の配色のまま描画している」状態を検出して warnings に出す。 / 1.1.2 — 洗い出した不具合の一括修正。(1) JSONで送ったバックスラッシュが消える不具合を修正。(2) 一括更新に退避が無く戻せなかったのを修正し、全件分を1件にまとめて保存・500件で頭打ち。(3) 配列のオプションをスカラーで丸ごと消せてしまう穴を塞いだ。(4) 案内だけで実装が無かった force=true を実装。(5) タームメタのDELETEに退避と存在確認を追加。(6) theme_mod もキーのガードを通るようにした。(7) 役割定義オプションの拒否をテーブル接頭辞に追従。(8) 更新チェックの失敗を5分キャッシュし、管理画面が毎回10秒待たされるのを解消。(9) 配布URLはhttpsのみ受け付ける。(10) 復元前の値も退避し復元を取り消せるようにした。",
}

# --- app.py から2つの辞書リテラルを取り出す ---
tree = ast.parse(open(APP_PY, encoding="utf-8").read())
found = {}
for node in tree.body:
    if isinstance(node, ast.Assign):
        for t in node.targets:
            if isinstance(t, ast.Name) and t.id in ("PLUGIN_DOWNLOADS", "PLUGIN_UPDATE_META"):
                found[t.id] = ast.literal_eval(node.value)

DOWNLOADS = found["PLUGIN_DOWNLOADS"]
META = found["PLUGIN_UPDATE_META"]

# 作り直すのは plugin-update/ だけ。plugin-host/ 丸ごと消すと、セール巡回エージェントが
# コミットする api/sales-auto.json (sale-hub v1.5.0 のGitHubリレー置き場) まで消える
api_dir = os.path.join(OUT, "api", "plugin-update")
if os.path.isdir(api_dir):
    shutil.rmtree(api_dir)
os.makedirs(api_dir)

ok, missing = [], []
for key, info in DOWNLOADS.items():
    meta = META.get(key)
    if not meta:
        continue
    if not os.path.exists(os.path.join(ZIP_SRC, info["file"])):
        missing.append((key, info["file"]))
        continue

    payload = {
        "name":         info["name"],
        "slug":         meta["plugin_basename"].split("/")[0],
        "plugin":       meta["plugin_basename"],
        "version":      info["version"],
        "tested":       meta["tested"],
        "requires":     meta["requires"],
        "requires_php": meta["requires_php"],
        "author":       meta["author"],
        "download_url": "%s/plugin-downloads/%s" % (RAW_BASE, info["file"]),
        "sections": {
            "description": "%s 本体。GitHub から自動更新します。" % info["name"],
            "changelog":   CHANGELOGS.get((key, info["version"]),
                                          "最新バージョン %s" % info["version"]),
        },
    }
    with open(os.path.join(api_dir, key), "w", encoding="utf-8") as f:
        json.dump(payload, f, ensure_ascii=False, indent=2)
        f.write("\n")   # 末尾改行。無いと毎回diffに出る
    ok.append((key, info["version"]))

print("=== 生成済み (%d件) → plugin-host/api/plugin-update/ ===" % len(ok))
for key, ver in sorted(ok):
    print("  %-22s v%s" % (key, ver))
if missing:
    print("=== zipなしスキップ (%d件) ===" % len(missing))
    for key, fn in missing:
        print("  %-22s %s" % (key, fn))
