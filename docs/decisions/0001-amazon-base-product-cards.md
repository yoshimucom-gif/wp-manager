# ADR 0001: Amazon ベースで商品カードを構築する

- **決定日**: 2026-05-19
- **状態**: 採用 (NON-NEGOTIABLE)
- **対象**: AI Product Inserter プラグイン (`plugin-patches/product-selector.php`)
- **関連コミット**: `3680a9e` (v1.7.2)、`453cc01` (v1.7.3)

## 結論（先に書く）

**商品カードのソースは必ず Amazon にする。楽天単独商品はカードに出さない。**

楽天で見つかったが Amazon で対応商品が見つからなかった場合は、その商品はカードとして
レンダリングしない。Amazon が取れていない時点で CV 機会が薄いと判断し、不完全なカードを
並べるより Amazon 確定商品だけに絞る方が運用上望ましい。

## 背景

ユーザー（アフィ運用者）と過去のチャットで合意した方針：

- Amazon が CV のメインライン
- 楽天は補助（Amazon と同一商品があれば直リン併設、なければ Amazon の検索URLにフォールバック）
- ただし**カード自体は Amazon に商品が存在する場合だけ出す**

## なぜ過去にブレたか

| 時期 | バージョン | 状況 |
|---|---|---|
| ~v1.5.0 | strict | `pair_candidates()` が「Amazon と楽天両方にある商品のみ」に外側フィルター |
| v1.6.0 | hybrid | RINKER 風 hybrid 化のため**外側フィルターを撤去**。同時に `merge_duplicates` が抱えていた「楽天マッチ無し → 楽天そのまま採用」分岐が露出し、楽天単独カードが並ぶ事象が発生 |
| v1.7.0 | enrich | Amazon → 楽天 enrichment 追加。Amazon ベースは保ったが、楽天 → Amazon enrichment は意図的に未実装（PA-API レート保護） |
| v1.7.1 | enrich tuning | 類似度閾値修正。Amazon → 楽天 enrichment 精度向上 |
| **v1.7.2** | **Amazon-only** | **`merge_duplicates` 自体で楽天単独を捨てるよう修正。本ADR で固定** |

### 根本原因

`merge_duplicates()` 関数の内部に「楽天ヒット → Amazonマッチ無し → 楽天そのまま採用」の
分岐がずっと残っていた。v1.5.0 は外側フィルターで隠していたが、v1.6.0 でフィルターが
外れたために露出した。

- 「Amazonベース」の意図は**コード自体には書かれていなかった**（コメント無し）
- v1.6.0 の commit message は「両ボタン常に表示」は書いたが「楽天単独除外」は明記してなかった
- テストもアサーションも無いので自動検知できなかった

## 守り方

1. `merge_duplicates()` 冒頭の DocBlock で「Amazonベース不可逆」を明記し、本ADRへの参照を入れた
2. 本ADRファイルでフルコンテキストを残した
3. 将来「楽天単独もフォールバックで出したい」要望が来た場合は、本ADRを上書き／新ADRを作成して
   議論記録を残してから変更する

## やってはいけないこと

- `merge_duplicates()` で `if ($c['source'] === 'rakuten') { ... else { $merged[] = $c; } }` の
  ような「楽天そのまま追加」分岐を再導入すること
- `pair_candidates()` 外側で楽天単独を許可するフィルターを追加すること
- 「優先サイト = 楽天のみ」設定を UI に再追加すること（v1.7.3 で撤去済み）

## 関連ファイル

- `plugin-patches/product-selector.php` (master)
- `plugin-patches/build/ai-product-inserter/includes/product-selector.php` (build copy)
- `plugin-patches/build/ai-product-inserter/templates/card-vertical.php` (card-side fallback logic)
