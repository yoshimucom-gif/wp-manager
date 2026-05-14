# ai-product-inserter v1.2.0 → v1.3.0 パッチ

Affiros9 連携のためにマーカー syntax を拡張する。

## 変更点

旧:
```
<!--ai-product-->
```

新（後方互換）:
```
<!--ai-product-->                   ← 既存通り（プラグイン設定のデフォルトデザイン）
<!--ai-product:vertical-->          ← 縦置きカードを強制
<!--ai-product:horizontal-->        ← 横長カードを強制
<!--ai-product:ranking:3-->         ← TOP3 ランキングブロック
<!--ai-product:ranking:5-->         ← TOP5
```

## 適用方法

1. `includes/inserter.php` の以下2か所を差し替え
2. プラグインを zip し直してアップロード（or 直接ファイル置き換え）
3. プラグインの設定はそのままでOK

詳細は `inserter.php.patch.md` を参照。
