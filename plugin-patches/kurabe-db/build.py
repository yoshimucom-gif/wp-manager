"""100均くらべ 比較データ表示プラグインのzipを作る

  py build.py 1.0.1

- プラグインヘッダーと VERSION 定数を指定バージョンに合わせる
- zip は「/」区切り（WPが展開できる形）で plugin-downloads/ に置き、古い版を消す
- app.py の kurabe-db の登録だけを書き換える（他プラグインは触らない）
"""
import os, re, sys, zipfile, glob

HERE = os.path.dirname(os.path.abspath(__file__))
REPO = os.path.dirname(os.path.dirname(HERE))
SLUG = "kurabe-db"
FILES = ["kurabe-db.php", "includes/plugin-updater.php", "assets/kurabe.css", "assets/kurabe.js", "readme.txt"]


def main(ver):
    assert re.fullmatch(r"\d+\.\d+\.\d+", ver), ver
    p = os.path.join(HERE, "kurabe-db.php")
    s = open(p, encoding="utf-8").read()
    s = re.sub(r"(\* Version:\s+)\S+", r"\g<1>" + ver, s, count=1)
    s = re.sub(r"(const VERSION\s+=\s+')[^']+(')", r"\g<1>" + ver + r"\g<2>", s, count=1)
    open(p, "w", encoding="utf-8", newline="\n").write(s)
    r = os.path.join(HERE, "readme.txt")
    t = open(r, encoding="utf-8").read()
    open(r, "w", encoding="utf-8", newline="\n").write(re.sub(r"^Version: .*$", "Version: " + ver, t, count=1, flags=re.M))

    dl = os.path.join(REPO, "plugin-downloads")
    for old in glob.glob(os.path.join(dl, SLUG + "-*.zip")):
        os.remove(old)
    out = os.path.join(dl, f"{SLUG}-{ver}.zip")
    with zipfile.ZipFile(out, "w", zipfile.ZIP_DEFLATED) as z:
        for f in FILES:
            z.write(os.path.join(HERE, f), f"{SLUG}/{f}")
    names = zipfile.ZipFile(out).namelist()
    assert not [n for n in names if "\\" in n], names
    assert f"{SLUG}/kurabe-db.php" in names

    a = os.path.join(REPO, "app.py")
    s = open(a, encoding="utf-8", newline="").read()
    pat = re.compile(r"('kurabe-db': \{'file': ')[^']+(', 'name': '[^']+', 'version': ')[^']+(')")
    s, n = pat.subn(lambda m: m.group(1) + f"{SLUG}-{ver}.zip" + m.group(2) + ver + m.group(3), s)
    assert n == 1, f"app.py の置換件数が想定外: {n}"
    open(a, "w", encoding="utf-8", newline="").write(s)

    # 配信情報（本番の自動更新が見るファイル）も同じ版に書き換える。
    # これを上げ忘れると本番に更新が届かない（2026-09-29 1.1.9 で半日届かなかった）
    import json
    h = os.path.join(REPO, "plugin-host", "api", "plugin-update", SLUG)
    info = json.load(open(h, encoding="utf-8"))
    info["version"] = ver
    info["download_url"] = f"https://raw.githubusercontent.com/yoshimucom-gif/wp-manager/main/plugin-downloads/{SLUG}-{ver}.zip"
    info.setdefault("sections", {})["changelog"] = f"最新バージョン {ver}"
    open(h, "w", encoding="utf-8", newline="\n").write(json.dumps(info, ensure_ascii=False, indent=2) + "\n")
    print("built", out, names)
    print("コミットに必ず含める:", os.path.relpath(h, REPO))


if __name__ == "__main__":
    main(sys.argv[1])
