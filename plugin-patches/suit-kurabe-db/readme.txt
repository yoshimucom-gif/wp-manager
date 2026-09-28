=== スーツくらべ 比較データ表示 ===
Version: 1.0.7

スーツ量販店比較サイト（suit-hub.com）の品目ページに、各社の公式通販から取った
比較データを表示します。100均くらべ用の kurabe-db をフォークし、店の定義を
データ駆動にしたものです。見出しと本文の見た目はテーマに任せ、このプラグインは
次の部品だけを描きます。

■ データの置き場所
投稿メタ kurabe_data（JSON文字列）。REST API の meta.kurabe_data で読み書きできます。
本文を書き換えずに表だけ最新にできます。

■ 店の定義（kurabe-db からの最大の変更点）
店名はプラグインにハードコードせず、kurabe_data の stores 配列で持ちます。
  "stores": [{"s":"洋服の青山","slug":"aoyama","label":"洋服の青山","color":"#1a3a5c"}, ...]
  s     = rows の s 値と一致するキー
  slug  = CSSクラス・タグslug用
  label = バッジ表記
  color = ブランド色（バッジ・帯グラフ・チップに style="--kurabe-c:#..." で渡す）
stores に無い店名が rows に来たら灰色（#666）でフォールバックします。

■ ショートコード
  [kurabe part="source"]  出どころの帯（最終確認日・出典と件数・空欄の扱い。
                          source_extra＝{dt,dd}の配列があれば任意の行を追加）
  [kurabe part="stats"]   数字4つ（帯グラフの色は stores の color）
  [kurabe part="table"]   絞り込みと並べ替えができる一覧表（行はサーバー側で出力）。
                          rows の p_regular があれば通常価格に取り消し線を付けて表示
  [kurabe part="related"] 関連する品目のリンク
  [kurabe part="shop"]    量販店にない条件の Amazon・楽天市場 検索リンクと広告表記
  [kurabe_list]           カテゴリー・店名タグの品目×掲載数一覧
  ※ part="scale"（縮尺図・早見表）はコード温存。mode が来なければ出ません。

■ アフィリエイトID
設定 → スーツくらべ で指定できます。空欄ならオートインサーター（affiros_ai_settings）の
amazon_partner_tag / rakuten_affiliate_id、無ければ Rinker の設定を使います。

■ 自動更新
GitHub直配信（yoshimucom-gif/wp-manager の plugin-host/api/plugin-update/suit-kurabe-db）。
