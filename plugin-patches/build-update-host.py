# -*- coding: utf-8 -*-
"""Affiros プラグイン自動更新メタ (GitHub直配信) を静的生成する。

配信方式 (2026-08-09〜):
  - 更新チェックJSON: https://raw.githubusercontent.com/yoshimucom-gif/wp-manager/main/plugin-host/api/plugin-update/<key>
  - zip本体:          https://raw.githubusercontent.com/yoshimucom-gif/wp-manager/main/plugin-downloads/<file>
  リポジトリが公開なので raw がそのまま配信になる。サーバー・FTP・Actions 不要。
  push した瞬間が配信完了 (raw のキャッシュは数分)。

  旧方式の変遷: Render (〜2026-07-29 Suspend) → ke-ys.co.jp FTPS (〜2026-07-30、
  サーバー側フォルダ消失で死亡) → GitHub直配信 (現行)。

使い方: py plugin-patches/build-update-host.py
  app.py の PLUGIN_DOWNLOADS / PLUGIN_UPDATE_META を ast で読み取り (転記ミス防止)、
  plugin-host/api/plugin-update/ に JSON を吐く。生成物はコミットして push する。
"""
import ast
import json
import os
import shutil

CODEX = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
APP_PY = os.path.join(CODEX, "app.py")
ZIP_SRC = os.path.join(CODEX, "plugin-downloads")
OUT = os.path.join(CODEX, "plugin-host")

RAW_BASE = "https://raw.githubusercontent.com/yoshimucom-gif/wp-manager/main"

# --- app.py から2つの辞書リテラルを取り出す ---
tree = ast.parse(open(APP_PY, encoding="utf-8").read())
found = {}
for node in tree.body:
    if isinstance(node, ast.Assign):
        for t in node.targets:
            if isinstance(t, ast.Name) and t.id in ("PLUGIN_DOWNLOADS", "PLUGIN_UPDATE_META"):
                found[t.id] = ast.literal_eval(node.value)

DOWNLOADS = found["PLUGIN_DOWNLOADS"]
META = found["PLUGIN_UPDATE_META"]

api_dir = os.path.join(OUT, "api", "plugin-update")
if os.path.isdir(OUT):
    shutil.rmtree(OUT)
os.makedirs(api_dir)

ok, missing = [], []
for key, info in DOWNLOADS.items():
    meta = META.get(key)
    if not meta:
        continue
    if not os.path.exists(os.path.join(ZIP_SRC, info["file"])):
        missing.append((key, info["file"]))
        continue

    payload = {
        "name":         info["name"],
        "slug":         meta["plugin_basename"].split("/")[0],
        "plugin":       meta["plugin_basename"],
        "version":      info["version"],
        "tested":       meta["tested"],
        "requires":     meta["requires"],
        "requires_php": meta["requires_php"],
        "author":       meta["author"],
        "download_url": "%s/plugin-downloads/%s" % (RAW_BASE, info["file"]),
        "sections": {
            "description": "%s 本体。GitHub から自動更新します。" % info["name"],
            "changelog":   "最新バージョン %s" % info["version"],
        },
    }
    with open(os.path.join(api_dir, key), "w", encoding="utf-8") as f:
        json.dump(payload, f, ensure_ascii=False, indent=2)
    ok.append((key, info["version"]))

print("=== 生成済み (%d件) → plugin-host/api/plugin-update/ ===" % len(ok))
for key, ver in sorted(ok):
    print("  %-22s v%s" % (key, ver))
if missing:
    print("=== zipなしスキップ (%d件) ===" % len(missing))
    for key, fn in missing:
        print("  %-22s %s" % (key, fn))
