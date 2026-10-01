"""「Claudeメディア構築」フォルダのプラグインzipを、いつも最新版1つだけにする
（吉村さん 2026-10-01「フォルダ内にあるプラグイン全部最新にしろ。毎回。で古いのは削除しろ」）

- 対象: フォルダ直下にzipが置いてあるプラグイン（ここに無いプラグインは増やさない）
- 最新版の判定: plugin-host/api/plugin-update/<名前> の download_url（配信中の版＝正）
- 古い版・-latest の複製はごみ箱へ（完全削除はしない。git の plugin-downloads にも残っている）
- git の post-commit フックから毎回自動で呼ばれる（.git/hooks/post-commit）。手で流してもよい

  py tools/sync_media_folder.py
"""
import json, os, re, shutil, subprocess, sys

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
DEST = os.path.join(os.path.expanduser("~"), "OneDrive", "デスクトップ", "Claudeメディア構築")
HOST = os.path.join(ROOT, "plugin-host", "api", "plugin-update")
DL = os.path.join(ROOT, "plugin-downloads")
VER = re.compile(r"^(?P<name>.+?)-(?P<ver>\d+(?:\.\d+)+|latest)\.zip$")


def recycle(path):
    """ごみ箱へ送る（Windows）。戻したいときはごみ箱から元に戻せる"""
    q = path.replace("'", "''")
    ps = ("Add-Type -AssemblyName Microsoft.VisualBasic; "
          f"[Microsoft.VisualBasic.FileIO.FileSystem]::DeleteFile('{q}', 'OnlyErrorDialogs', 'SendToRecycleBin')")
    subprocess.run(["powershell", "-NoProfile", "-Command", ps], check=True, capture_output=True)


def latest():
    out = {}
    for f in os.listdir(HOST):
        try:
            j = json.load(open(os.path.join(HOST, f), encoding="utf-8"))
        except Exception:
            continue
        zipname = (j.get("download_url") or "").rsplit("/", 1)[-1]
        m = VER.match(zipname)
        if m and os.path.exists(os.path.join(DL, zipname)):
            out[m.group("name")] = zipname
    return out


def main():
    if not os.path.isdir(DEST):
        return
    lat = latest()
    present = {}
    for f in os.listdir(DEST):
        m = VER.match(f)
        if m and m.group("name") in lat:
            present.setdefault(m.group("name"), []).append(f)
    for name, files in sorted(present.items()):
        want = lat[name]
        if want not in files:
            shutil.copy2(os.path.join(DL, want), os.path.join(DEST, want))
            print("最新版を置いた", want)
        for f in files:
            if f != want:
                recycle(os.path.join(DEST, f))
                print("古い版をごみ箱へ", f)


if __name__ == "__main__":
    main()
