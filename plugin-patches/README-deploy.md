# プラグイン配信の運用

Affiros系WPプラグインの自動更新は **ke-ys.co.jp** の静的ファイルから配信する。
（Render 停止に伴い `wp-manager.onrender.com` から移設）

## 更新手順

```powershell
python plugin-patches/build-all.py     # 1. zip を生成
# 2. app.py の PLUGIN_DOWNLOADS のバージョンを更新
git push origin main                   # 3. これだけ
```

push すると `.github/workflows/deploy-plugin-host.yml` が走り、
`build-keys-host.py` で配信バンドルを生成 → FTPS で ke-ys.co.jp へアップロード →
10本の配信バージョンを HTTP で検証する。**手動アップロードは不要。**

## 配信先

| サーバー上のパス | URL |
|---|---|
| `public_html/ke-ys.co.jp/api/plugin-update/<key>` | `https://ke-ys.co.jp/api/plugin-update/<key>` |
| `public_html/ke-ys.co.jp/affiros-plugins/<file>.zip` | `https://ke-ys.co.jp/affiros-plugins/<file>.zip` |

各WPサイトは `wp-content/mu-plugins/affiros-update-host.php` で
`AFFIROS_UPDATE_HOST` を定義して参照先を切り替えている（全サイト設置済み・変更不要）。

## 更新通知が出る条件

**サーバーの配信版 > サイトのインストール済み版** のときだけ。
同じ版なら「最新です」と出るのが正常。プラグイン側は更新情報を30分キャッシュする。

## フォールバック

GitHub Actions が ConoHa の海外アクセス制限で弾かれる場合は、国内IPのPCから実行する。

```powershell
py plugin-patches\deploy-keys-host.py
```

認証情報は `.env.deploy`（git管理外）。

## 外部からの確認方法

- mu-plugin設置: `https://<site>/wp-content/mu-plugins/affiros-update-host.php` が **200かつ0バイト**なら存在、404なら未設置
- プラグイン導入: `https://<site>/wp-content/plugins/<slug>/` が **403=あり / 404=なし**
- 導入バージョン: トップページHTMLの `<slug>/assets/*.css?ver=X.Y.Z`

## 注意

`deploy-plugin-host.yml` の `dangerous-clean-slate` は**絶対に true にしない**。
配信先が WordPress のドキュメントルートなので、サイトが消える。
