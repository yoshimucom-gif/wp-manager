# エリアLab 実データ化方針

## 結論

宅建業者の競合データは、まず東京都公式の「宅地建物取引業者免許情報提供サービス」を使う。

- 公式検索トップ: https://www.takken.metro.tokyo.lg.jp/
- 検索画面: https://www.takken.metro.tokyo.lg.jp/search
- 検索結果: https://www.takken.metro.tokyo.lg.jp/search/get
- 詳細例: https://www.takken.metro.tokyo.lg.jp/detail?disp=1&licenseno=13102925

このサービスはCSV一括配布ではないため、当面は検索結果ページと詳細ページを取得し、東京都全域の業者データを作る。

## 公式サイトで確認できたこと

- 東京都知事免許業者、国土交通大臣免許業者、届出業者を検索できる。
- 主たる事務所（本店）の区市町村名で検索できる。
- 詳細ページには以下が載っている。
  - 免許証番号
  - 法人・個人の別
  - 免許有効期間
  - 最初の免許取得年月日
  - 商号又は名称
  - 主たる事務所所在地
  - 資本金
  - 代表者氏名
  - 事務所一覧
  - 所在地
  - 電話番号
  - 監督処分情報

## 競合データ化の流れ

1. 東京都公式検索結果から東京都全域の業者一覧を取得する。
2. 詳細ページを取得し、事務所一覧の住所と電話番号を取得する。
3. 住所をジオコーディングして緯度経度を付ける。
4. 三軒茶屋、下北沢、用賀など各エリアの中心座標から半径1km以内の事務所を判定する。
5. `competitors` テーブルへ投入し、`areas.competitors` を実件数に更新する。

## ジオコーディング候補

第一候補は国土地理院の住所検索API。

```text
https://msearch.gsi.go.jp/address-search/AddressSearch?q=東京都世田谷区三軒茶屋1-32-9
```

返却されるGeoJSONの `geometry.coordinates` は `[lng, lat]`。

## 注意点

- 公式サイトにCSV一括ダウンロードは見当たらない。
- 検索結果ページはページングされるため、取得時は負荷をかけないように待機時間を入れる。
- 東京都全域は約2.8万件あるため、詳細取得とジオコーディングは時間がかかる。途中再開できるように、まずJSONへ保存する。
- 特殊文字は公式サイト上で `■` や `＊` として表示されることがある。
- 住所ジオコーディングは完全一致しない場合があるため、失敗リストを残して手修正できるようにする。
- 現在の `type` は `大手/FC/中小` だが、公式データだけでは分類できない。初期実データでは `中小` または `公式データ` として入れ、後でブランド辞書で分類する。

## 次に作るもの

- `scripts/scrape-tokyo-takken.mjs`
  - 東京都公式検索結果から東京都全域の宅建業者候補を抽出
  - 詳細ページから事務所住所と電話番号を取得
  - JSON/CSVへ保存
- `scripts/geocode-competitors.mjs`
  - 住所を国土地理院APIで緯度経度化
  - 失敗分を別ファイルへ保存
- `scripts/build-competitor-seed.mjs`
  - `competitors` と `areas.competitors` 更新用SQLを生成

## 実行手順

まず少数ページでテストする。

```powershell
node scripts/scrape-tokyo-takken.mjs --pages 2 --delay 500
```

東京都全域の一覧を取得する場合は `--pages` を外す。

```powershell
node scripts/scrape-tokyo-takken.mjs --delay 800
```

公式検索結果は約2,800ページあるため、全域取得には時間がかかる。最初は一覧だけ保存し、詳細ページ取得とジオコーディングは別工程で行う。
