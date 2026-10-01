=== カタログギフトくらべ 比較データ表示 ===
Version: 1.2.2

カタログギフト比較サイト（catalog-kurabe.com）のページに、発行会社・ブランドの公式通販から取った
比較データを表示します。スーツくらべ用の suit-kurabe-db をフォークし、絞り込みの軸（filters）と
表の列名・件数の単位・注記をデータで指定できるようにしたものです。見出しと本文の見た目はテーマに任せ、このプラグインは
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
設定 → カタログギフトくらべ で指定できます。空欄ならオートインサーター（affiros_ai_settings）の
amazon_partner_tag / rakuten_affiliate_id、無ければ Rinker の設定を使います。

■ 自動更新
GitHub直配信（yoshimucom-gif/wp-manager の plugin-host/api/plugin-update/catalog-kurabe-db）。

■ カタログギフト版で足したデータ項目
  filters     : [{"key":"g","label":"ジャンル","values":["総合","グルメ"]}, ...]  rows[].f.g と照合するチップ
  store_col   : 1列目の見出し（既定「店」）／ name_col : 2列目の見出し（既定「商品名」）
  unit        : 出どころの帯の件数の単位（既定「種」）／ stamp_note : 表の上の注記の後半
  rows[].note : 商品名の下の小さな注記 ／ rows[].u_label : 右端リンクの文言 ／ rows[].aff : 広告リンクなら true
  shop[].links: [{"label":"…","url":"…"}] があれば Amazon・楽天の代わりにこれを出す
  価格チップは価格が9種類以上あると出さない（予算別ページは価格帯で絞ってあるため）
