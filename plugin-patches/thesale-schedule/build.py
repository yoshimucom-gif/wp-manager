# -*- coding: utf-8 -*-
"""thesale-schedule の配布zipを作る（区切りは必ず「/」。PowerShellのzipはWPが展開できない）

  py plugin-patches/thesale-schedule/build.py
出力: plugin-downloads/thesale-schedule-<VERSION>.zip と、Claudeメディア構築直下に最新版だけ
"""
import glob
import os
import re
import shutil
import zipfile

HERE = os.path.dirname(os.path.abspath(__file__))
REPO = os.path.dirname(os.path.dirname(HERE))
SLUG = "thesale-schedule"
MEDIA_DIR = r"C:\Users\yoshi\OneDrive\デスクトップ\Claudeメディア構築"

main_php = open(os.path.join(HERE, f"{SLUG}.php"), encoding="utf-8").read()
VERSION = re.search(r"\* Version:\s*([\d.]+)", main_php).group(1)
out = os.path.join(REPO, "plugin-downloads", f"{SLUG}-{VERSION}.zip")

for old in glob.glob(os.path.join(REPO, "plugin-downloads", f"{SLUG}-*.zip")):
    if old != out:
        os.remove(old)
with zipfile.ZipFile(out, "w", zipfile.ZIP_DEFLATED) as z:
    for root, _, files in os.walk(HERE):
        for f in files:
            if f in ("build.py",) or f.endswith(".pyc"):
                continue
            p = os.path.join(root, f)
            arc = SLUG + "/" + os.path.relpath(p, HERE).replace(os.sep, "/")
            z.write(p, arc)
names = zipfile.ZipFile(out).namelist()
assert not [n for n in names if "\\" in n], "区切りに \\ が混じっている"
assert f"{SLUG}/{SLUG}.php" in names and f"{SLUG}/includes/plugin-updater.php" in names
for old in glob.glob(os.path.join(MEDIA_DIR, f"{SLUG}-*.zip")):
    os.remove(old)
shutil.copy2(out, os.path.join(MEDIA_DIR, os.path.basename(out)))
print(VERSION, out, names)
