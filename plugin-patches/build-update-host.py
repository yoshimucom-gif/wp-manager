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

GH_HANDOVER = (
    "残り9本の更新チェック先を、停止した Render (wp-manager.onrender.com) から "
    "GitHub直配信の固定URLへ変更。7月末から止まっていた自動更新が復旧する。"
    "プラグインの機能そのものに変更はない。"
)
REDIVER_112 = (
    "1.1.2 — 洗い出した不具合の一括修正。(1) JSONで送ったバックスラッシュが消える不具合を修正。(2) 一括更新に退避が無く戻せなかったのを修正し、全件分を1件にまとめて保存・500件で頭打ち。(3) 配列のオプションをスカラーで丸ごと消せてしまう穴を塞いだ。(4) 案内だけで実装が無かった force=true を実装。(5) タームメタのDELETEに退避と存在確認を追加。(6) theme_mod もキーのガードを通るようにした。(7) 役割定義オプションの拒否をテーブル接頭辞に追従。(8) 更新チェックの失敗を5分キャッシュし、管理画面が毎回10秒待たされるのを解消。(9) 配布URLはhttpsのみ受け付ける。(10) 復元前の値も退避し復元を取り消せるようにした。"
)

# 更新画面に出る changelog の上書き。(キー, 版) 単位で持つ。
#   版まで含めて一致したときだけ使うので、次の版に上げれば自動で既定文に戻る
#   (古い説明が新しい版に貼り付いたまま配信される事故を防ぐため)。
# ここに無いものは "最新バージョン <版>" になる。
#   ※この仕組みが無かった頃、本スクリプトを回すと手書きの changelog が
#     既定文で上書きされて消えていた (2026-09-07 に rediver-helper 1.1.2 で発覚)。
CHANGELOGS = {
    ("cat-eyecatch",       "1.0.1"): GH_HANDOVER,
    ("categorizer",        "0.1.2"): GH_HANDOVER,
    ("decoration",         "1.2.4"): GH_HANDOVER,
    ("dup-cleaner",        "1.0.2"): GH_HANDOVER,
    ("mark-stripper",      "1.0.1"): GH_HANDOVER,
    ("paragraph-splitter", "1.1.6"): GH_HANDOVER,
    ("product-inserter",   "1.10.1"): GH_HANDOVER,
    ("reschedule",         "1.1.1"): GH_HANDOVER,
    ("rewrite",            "0.5.18"): GH_HANDOVER,
    ("rediver-helper",     "1.1.2"): REDIVER_112,
}

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
            "changelog":   CHANGELOGS.get((key, info["version"]),
                                          "最新バージョン %s" % info["version"]),
        },
    }
    with open(os.path.join(api_dir, key), "w", encoding="utf-8") as f:
        json.dump(payload, f, ensure_ascii=False, indent=2)
        f.write("\n")   # 末尾改行。無いと毎回diffに出る
    ok.append((key, info["version"]))

print("=== 生成済み (%d件) → plugin-host/api/plugin-update/ ===" % len(ok))
for key, ver in sorted(ok):
    print("  %-22s v%s" % (key, ver))
if missing:
    print("=== zipなしスキップ (%d件) ===" % len(missing))
    for key, fn in missing:
        print("  %-22s %s" % (key, fn))
