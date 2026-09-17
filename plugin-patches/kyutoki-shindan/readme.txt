=== 給湯器エラーコード診断 ===
Contributors: Keys
Requires at least: 6.0
Tested up to: 6.7
Stable tag: 1.0.1
License: GPLv2 or later

リモコンに出たエラー番号から、メーカーが公式に公開している意味と対処を表示します。

== 使い方 ==

1. 記事または固定ページに `[kyutoki_shindan]` と書きます
2. 見出しを変えたいときは `[kyutoki_shindan title="エラー番号を調べる"]`
3. 依頼先のリンクは「設定 → 給湯器エラー診断」で登録します

== 収録データ ==

* ノーリツ: 10件
* リンナイ: 10件
* 長府製作所: 16件
* パーパス: 1件
* コロナ: 1件
* パロマ: 未収録（公式情報を取得できていない）

データはメーカー公式ページのみを出典としています。
更新は tools/build_shindan_plugin.py を流し直して差し替えます。
