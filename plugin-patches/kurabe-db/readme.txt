=== 100均くらべ 比較データ表示 ===
Version: 1.6.9

100均くらべ（100kin-kurabe.com）の品目ページに、ダイソー・キャンドゥ・ワッツの公式通販から取った
比較データを表示します。見出しと本文の見た目はテーマに任せ、このプラグインは次の部品だけを描きます。

■ データの置き場所
投稿メタ kurabe_data（JSON文字列）。REST API の meta.kurabe_data で読み書きできます。
データの取得と更新は手元のスクリプト（Claudeメディア構築\100均くらべ\kurabe）が行い、
本文を書き換えずに表だけ最新にできます。

■ ショートコード
  [kurabe part="source"]  出どころの帯（最終確認日・出典と件数・照合方法・空欄の扱い）
  [kurabe part="stats"]   数字4つ
  [kurabe part="table"]   絞り込みと並べ替えができる一覧表（行はサーバー側で出力）
  [kurabe part="scale"]   サイズを同じ縮尺で並べた図
  [kurabe part="shop"]    100均にない条件の Amazon・楽天市場 検索リンクと広告表記

■ アフィリエイトID
設定 → 100均くらべ で指定できます。空欄ならオートインサーター（affiros_ai_settings）の
amazon_partner_tag / rakuten_affiliate_id、無ければ Rinker の設定を使います。

■ 自動更新
GitHub直配信（yoshimucom-gif/wp-manager の plugin-host/api/plugin-update/kurabe-db）。
