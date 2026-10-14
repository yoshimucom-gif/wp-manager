=== THE SALE セール日程表示 ===
Requires at least: 6.0
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 1.0.1

THE SALE（the-sale.jp）のトップに出す「いま開催中のセール」「これから始まるセール」を、
オプション thesale_events のセール日程データから、表示のたびに日本時間で計算して出す。

ショートコード:
* [thesale_live] … いま開催中のセール（見出し2つき）。開始〜終了の間だけ出る
* [thesale_upcoming limit="6"] … これから始まるセール（日めくりカード）。始まったら消え、未発表のものは hide_after を過ぎたら消える
* [thesale_asof] … 当日の日付

== Changelog ==
= 1.0.1 =
* 終了が23:59のセールは時刻を出さず日付だけにする（「19日23:59」→「19日」）
= 1.0.0 =
* 初版。開催中・これからのセールを表示時に計算するショートコード。自動更新つき
