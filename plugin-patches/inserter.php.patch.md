# inserter.php パッチ

`ai-product-inserter/includes/inserter.php` を以下のように修正してください。

---

## 修正1: マーカー正規表現 (2か所)

### 旧コード
```php
$marker_pattern = '/<!--\s*ai-product\s*-->/i';
```

### 新コード
```php
// 新マーカー syntax: <!--ai-product:design[:count]-->
// design = vertical | horizontal | ranking
// count  = ランキングデザインの場合のアイテム数 (例: ranking:3)
// 旧 <!--ai-product--> も後方互換でマッチする
$marker_pattern = '/<!--\s*ai-product(?::([a-z]+)(?::(\d+))?)?\s*-->/i';
```

この変更を `process_marker_mode()` と `process_marker_per_heading_mode()` の **両方** で行う（合計2か所）。

---

## 修正2: `process_marker_mode` の置換コールバック

`process_marker_mode` 内の `preg_replace_callback` を以下に置き換え：

### 旧コード（135-155行目あたり）
```php
$selected_products = [];
$marker_counter = 0;

$new_content = preg_replace_callback(
    $marker_pattern,
    function($match) use (&$marker_counter, $selections_by_index, $all_candidates, $design, &$selected_products) {
        $current_idx = $marker_counter;
        $marker_counter++;

        if (!isset($selections_by_index[$current_idx])) return $match[0];

        $sel = $selections_by_index[$current_idx];
        $product = AI_PI_Product_Selector::find_by_id($all_candidates, $sel['product_id']);
        if (!$product) return $match[0];

        $selected_products[] = $product;
        return AI_PI_Card_Renderer::render($product, $design);
    },
    $content
);
```

### 新コード
```php
$selected_products = [];
$marker_counter = 0;

$new_content = preg_replace_callback(
    $marker_pattern,
    function($match) use (&$marker_counter, $selections_by_index, $all_candidates, $design, &$selected_products) {
        $current_idx = $marker_counter;
        $marker_counter++;

        // マーカーから design hint を取得（無ければ既定）
        $marker_design = !empty($match[1]) ? strtolower($match[1]) : $design;
        $marker_count = !empty($match[2]) ? intval($match[2]) : 3;

        // ランキングマーカー: 候補から上位N件で ranking ブロックを生成
        if ($marker_design === 'ranking') {
            $ranking_products = array_slice($all_candidates, 0, $marker_count);
            if (empty($ranking_products)) return $match[0];
            foreach ($ranking_products as $p) {
                $selected_products[] = $p;
            }
            return AI_PI_Card_Renderer::render_ranking($ranking_products);
        }

        // 通常マーカー: 1商品を vertical / horizontal で表示
        if (!isset($selections_by_index[$current_idx])) return $match[0];

        $sel = $selections_by_index[$current_idx];
        $product = AI_PI_Product_Selector::find_by_id($all_candidates, $sel['product_id']);
        if (!$product) return $match[0];

        $selected_products[] = $product;
        return AI_PI_Card_Renderer::render($product, $marker_design);
    },
    $content
);
```

---

## 修正3: `process_marker_per_heading_mode` の置換コールバック

同じく `preg_replace_callback` を置き換え：

### 旧コード（235-255行目あたり）
```php
$selected_products = [];
$marker_counter = 0;

$new_content = preg_replace_callback(
    $marker_pattern,
    function($match) use (&$marker_counter, $selections_by_index, $all_candidates_pool, $design, &$selected_products) {
        $current_idx = $marker_counter;
        $marker_counter++;

        if (!isset($selections_by_index[$current_idx])) return $match[0];

        $sel = $selections_by_index[$current_idx];
        $product = AI_PI_Product_Selector::find_by_id($all_candidates_pool, $sel['product_id']);
        if (!$product) return $match[0];

        $selected_products[] = $product;
        return AI_PI_Card_Renderer::render($product, $design);
    },
    $content
);
```

### 新コード
```php
$selected_products = [];
$marker_counter = 0;

$new_content = preg_replace_callback(
    $marker_pattern,
    function($match) use (&$marker_counter, $selections_by_index, $all_candidates_pool, $design, &$selected_products) {
        $current_idx = $marker_counter;
        $marker_counter++;

        $marker_design = !empty($match[1]) ? strtolower($match[1]) : $design;
        $marker_count = !empty($match[2]) ? intval($match[2]) : 3;

        if ($marker_design === 'ranking') {
            $ranking_products = array_slice(array_values($all_candidates_pool), 0, $marker_count);
            if (empty($ranking_products)) return $match[0];
            foreach ($ranking_products as $p) {
                $selected_products[] = $p;
            }
            return AI_PI_Card_Renderer::render_ranking($ranking_products);
        }

        if (!isset($selections_by_index[$current_idx])) return $match[0];

        $sel = $selections_by_index[$current_idx];
        $product = AI_PI_Product_Selector::find_by_id($all_candidates_pool, $sel['product_id']);
        if (!$product) return $match[0];

        $selected_products[] = $product;
        return AI_PI_Card_Renderer::render($product, $marker_design);
    },
    $content
);
```

---

## 動作確認

修正後、テスト記事に以下を入れて確認：

```html
<h2>テスト</h2>
<!--ai-product:vertical-->
<!--ai-product:horizontal-->
<!--ai-product:ranking:3-->
```

プラグインを実行すると：
- 1個目: 縦置きカード（1商品）
- 2個目: 横長カード（1商品）
- 3個目: ランキングTOP3ブロック

旧マーカー `<!--ai-product-->` もそのまま動く。
