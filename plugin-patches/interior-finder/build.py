"""インテリアグッズLab 条件で探す プラグインのzipを作る

  py build.py 1.0.1 "変更点"

- プラグインヘッダーと VERSION 定数を指定バージョンに合わせる
- data/*.json は見つかったものを全部同梱する（品目が増えても手で足さない）
- zip は「/」区切り（WPが展開できる形）で plugin-downloads/ に置き、古い版を消す
- app.py の interior-finder の登録だけを書き換える（他プラグインは触らない）
- 配信JSON（plugin-host/api/plugin-update/interior-finder）も同じ版に揃える
"""
import glob
import json
import os
import re
import sys
import zipfile

HERE = os.path.dirname(os.path.abspath(__file__))
REPO = os.path.dirname(os.path.dirname(HERE))
SLUG = "interior-finder"
FILES = ["interior-finder.php", "includes/plugin-updater.php",
         "assets/finder.css", "assets/finder.js", "readme.txt"]


def main(ver, note=""):
    assert re.fullmatch(r"\d+\.\d+\.\d+", ver), ver

    p = os.path.join(HERE, SLUG + ".php")
    s = open(p, encoding="utf-8").read()
    s = re.sub(r"(\* Version:\s+)\S+", r"\g<1>" + ver, s, count=1)
    s = re.sub(r"(const VERSION\s*=\s*')[^']+(')", r"\g<1>" + ver + r"\g<2>", s, count=1)
    open(p, "w", encoding="utf-8", newline="\n").write(s)
    assert ("Version:     " + ver) in s or ("Version: " + ver) in s
    assert ("const VERSION = '" + ver + "'") in s

    r = os.path.join(HERE, "readme.txt")
    t = open(r, encoding="utf-8").read()
    open(r, "w", encoding="utf-8", newline="\n").write(
        re.sub(r"^Version: .*$", "Version: " + ver, t, count=1, flags=re.M))

    # 同梱データ。品目を足したら勝手に入る
    sets = sorted(os.path.basename(x) for x in glob.glob(os.path.join(HERE, "data", "*.json")))
    assert sets, "data/*.json が無い"
    for name in sets:
        json.load(open(os.path.join(HERE, "data", name), encoding="utf-8"))   # 壊れたJSONを配らない

    dl = os.path.join(REPO, "plugin-downloads")
    for old in glob.glob(os.path.join(dl, SLUG + "-*.zip")):
        os.remove(old)
    out = os.path.join(dl, "%s-%s.zip" % (SLUG, ver))
    with zipfile.ZipFile(out, "w", zipfile.ZIP_DEFLATED) as z:
        for f in FILES:
            z.write(os.path.join(HERE, f), "%s/%s" % (SLUG, f))
        for name in sets:
            z.write(os.path.join(HERE, "data", name), "%s/data/%s" % (SLUG, name))

    names = zipfile.ZipFile(out).namelist()
    assert not [n for n in names if "\\" in n], names
    assert "%s/%s.php" % (SLUG, SLUG) in names
    body = zipfile.ZipFile(out).read("%s/%s.php" % (SLUG, SLUG)).decode("utf-8")
    assert ver in body, "zipの中のPHPが古い"

    a = os.path.join(REPO, "app.py")
    s = open(a, encoding="utf-8", newline="").read()
    pat = re.compile(r"('%s': \{'file': ')[^']+(', 'name': '[^']+', 'version': ')[^']+(')" % SLUG)
    s, n = pat.subn(lambda m: m.group(1) + "%s-%s.zip" % (SLUG, ver) + m.group(2) + ver + m.group(3), s)
    assert n == 1, "app.py の置換件数が想定外: %d" % n
    open(a, "w", encoding="utf-8", newline="").write(s)

    # 配信情報（本番の自動更新が見るファイル）も同じ版に揃える。
    # ★build-update-host.py は全プラグイン分を作り直して他のJSONを巻き添えにするので、
    #   ここでは自分のファイルだけを書く（無ければ作る）。
    h = os.path.join(REPO, "plugin-host", "api", "plugin-update", SLUG)
    if os.path.exists(h):
        info = json.load(open(h, encoding="utf-8"))
    else:
        name = re.search(r"\* Plugin Name:\s*(.+)", body).group(1).strip()
        info = {"name": name, "slug": SLUG, "plugin": "%s/%s.php" % (SLUG, SLUG),
                "tested": "6.7", "requires": "6.0", "requires_php": "7.4", "author": "Keys",
                "sections": {"description": name + " 本体。GitHub から自動更新します。"}}
    info["version"] = ver
    info["download_url"] = ("https://raw.githubusercontent.com/yoshimucom-gif/wp-manager/"
                            "main/plugin-downloads/%s-%s.zip" % (SLUG, ver))
    info.setdefault("sections", {})["changelog"] = note or ("最新バージョン " + ver)
    open(h, "w", encoding="utf-8", newline="\n").write(
        json.dumps(info, ensure_ascii=False, indent=2) + "\n")

    print("built", out)
    print("  同梱データ:", ", ".join(sets))
    print("  ファイル:", len(names), "件")
    print("コミットに必ず含める:", os.path.relpath(h, REPO))


if __name__ == "__main__":
    main(sys.argv[1], sys.argv[2] if len(sys.argv) > 2 else "")
