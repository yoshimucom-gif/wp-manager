"""Affiros リライト プラグインを zip にパッケージ。
WordPress が解凍できるよう forward-slash パス。"""
import os
import zipfile

SRC_DIR = r"C:\Users\yoshi\OneDrive\デスクトップ\Codex\plugin-patches\affiros-rewrite"
OUT_ZIP = r"C:\Users\yoshi\Downloads\affiros-rewrite-0.1.0.zip"

if os.path.exists(OUT_ZIP):
    os.remove(OUT_ZIP)

with zipfile.ZipFile(OUT_ZIP, "w", zipfile.ZIP_DEFLATED) as zf:
    for root, _, files in os.walk(SRC_DIR):
        for name in files:
            full = os.path.join(root, name)
            # plugin-patches/affiros-rewrite からの相対パスにする（zip 内ルートに affiros-rewrite/ を作る）
            rel = os.path.relpath(full, os.path.dirname(SRC_DIR)).replace(os.sep, "/")
            zf.write(full, rel)

with zipfile.ZipFile(OUT_ZIP, "r") as zf:
    names = zf.namelist()
    print(f"Total entries: {len(names)}")
    for n in names:
        print(f"  {n}")
