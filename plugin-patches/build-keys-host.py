# -*- coding: utf-8 -*-
"""Affiros プラグイン配布/自動更新ホストを ke-ys.co.jp 用に静的生成する。

app.py の PLUGIN_DOWNLOADS / PLUGIN_UPDATE_META を ast で読み取り、
Render の /api/plugin-update/<key> と同じ JSON を静的ファイルとして出す。
"""
import ast
import json
import os
import shutil

CODEX = r"C:\Users\yoshi\OneDrive\デスクトップ\Codex"
APP_PY = os.path.join(CODEX, "app.py")
ZIP_SRC = os.path.join(CODEX, "plugin-downloads")
OUT = os.path.join(CODEX, "ke-ys-plugin-host")

HOST = "https://ke-ys.co.jp"
ZIP_URL_PREFIX = "/affiros-plugins"

# --- app.py から2つの辞書リテラルを取り出す（転記ミス防止） ---
tree = ast.parse(open(APP_PY, encoding="utf-8").read())
found = {}
for node in tree.body:
    if isinstance(node, ast.Assign):
        for t in node.targets:
            if isinstance(t, ast.Name) and t.id in ("PLUGIN_DOWNLOADS", "PLUGIN_UPDATE_META"):
                found[t.id] = ast.literal_eval(node.value)

DOWNLOADS = found["PLUGIN_DOWNLOADS"]
META = found["PLUGIN_UPDATE_META"]
print("PLUGIN_DOWNLOADS: %d件 / PLUGIN_UPDATE_META: %d件" % (len(DOWNLOADS), len(META)))

# --- 出力ディレクトリ ---
api_dir = os.path.join(OUT, "api", "plugin-update")
zip_dir = os.path.join(OUT, "affiros-plugins")
if os.path.isdir(OUT):
    shutil.rmtree(OUT)
os.makedirs(api_dir)
os.makedirs(zip_dir)

missing = []
ok = []

for key, info in DOWNLOADS.items():
    meta = META.get(key)
    if not meta:
        print("  [SKIP] %-22s PLUGIN_UPDATE_META に定義なし" % key)
        continue

    src_zip = os.path.join(ZIP_SRC, info["file"])
    if not os.path.exists(src_zip):
        missing.append((key, info["file"]))
        continue

    shutil.copy2(src_zip, os.path.join(zip_dir, info["file"]))

    payload = {
        "name":         info["name"],
        "slug":         meta["plugin_basename"].split("/")[0],
        "plugin":       meta["plugin_basename"],
        "version":      info["version"],
        "tested":       meta["tested"],
        "requires":     meta["requires"],
        "requires_php": meta["requires_php"],
        "author":       meta["author"],
        "download_url": "%s%s/%s" % (HOST, ZIP_URL_PREFIX, info["file"]),
        "sections": {
            "description": "%s 本体。ke-ys.co.jp から自動更新します。" % info["name"],
            "changelog":   "最新バージョン %s" % info["version"],
        },
    }
    # 拡張子なしのファイル名 = URL の最終セグメント
    with open(os.path.join(api_dir, key), "w", encoding="utf-8") as f:
        json.dump(payload, f, ensure_ascii=False, indent=2)
    ok.append((key, info["version"], info["file"]))

# --- .htaccess: 拡張子なしファイルを JSON として返す ---
with open(os.path.join(api_dir, ".htaccess"), "w", encoding="utf-8", newline="\n") as f:
    f.write(
        "# Affiros プラグイン更新メタ情報（拡張子なしファイル）を JSON として配信\n"
        "ForceType application/json\n"
        "<IfModule mod_headers.c>\n"
        "    Header set Cache-Control \"public, max-age=300\"\n"
        "</IfModule>\n"
    )

# --- zip ディレクトリ: 直リンク配布のみ許可、一覧は出さない ---
with open(os.path.join(zip_dir, ".htaccess"), "w", encoding="utf-8", newline="\n") as f:
    f.write(
        "# zip の直リンク配布のみ。ディレクトリ一覧は禁止\n"
        "Options -Indexes\n"
    )

# --- mu-plugin（各WPサイトの wp-content/mu-plugins/ に置く）---
MU_PLUGIN = """<?php
/**
 * Plugin Name: Affiros 更新ホスト設定
 * Description: Affiros系プラグインの自動更新チェック先を %(host)s に向ける。
 * Version: 1.0.0
 * Author: Affiros
 *
 * 設置場所: wp-content/mu-plugins/affiros-update-host.php
 */

if (!defined('ABSPATH')) exit;

if (!defined('AFFIROS_UPDATE_HOST')) {
    define('AFFIROS_UPDATE_HOST', '%(host)s');
}
""" % {"host": HOST}

with open(os.path.join(OUT, "affiros-update-host.php"), "w", encoding="utf-8", newline="\n") as f:
    f.write(MU_PLUGIN)

print()
print("=== 生成済み (%d件) ===" % len(ok))
for key, ver, fn in sorted(ok):
    print("  %-22s v%-10s %s" % (key, ver, fn))

if missing:
    print()
    print("=== zip が見つからず未生成 (%d件) ===" % len(missing))
    for key, fn in missing:
        print("  %-22s %s" % (key, fn))

print()
print("出力先:", OUT)
