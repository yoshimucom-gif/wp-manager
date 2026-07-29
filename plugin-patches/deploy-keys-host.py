# -*- coding: utf-8 -*-
"""ke-ys.co.jp へプラグイン配信ファイルを FTPS でアップロードする（ローカル実行版）。

GitHub Actions が ConoHa の海外アクセス制限で弾かれる場合のフォールバック、
かつ push を挟まず今すぐ反映したいときの手段。

使い方:
    py plugin-patches\\deploy-keys-host.py

認証情報は Codex\\.env.deploy に書く（git 管理外）。
"""
import ftplib
import json
import os
import ssl
import subprocess
import sys
import urllib.request

CODEX = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
BUNDLE = os.path.join(CODEX, "ke-ys-plugin-host")
ENV_FILE = os.path.join(CODEX, ".env.deploy")
HOST_URL = "https://ke-ys.co.jp"
UPLOAD_DIRS = ("api", "affiros-plugins")


def load_env():
    if not os.path.exists(ENV_FILE):
        sys.exit("認証情報がありません: %s\n先に .env.deploy を埋めてください。" % ENV_FILE)
    env = {}
    with open(ENV_FILE, encoding="utf-8") as f:
        for line in f:
            line = line.strip()
            if not line or line.startswith("#") or "=" not in line:
                continue
            k, v = line.split("=", 1)
            env[k.strip()] = v.strip().strip('"').strip("'")
    missing = [k for k in ("FTP_HOST", "FTP_USER", "FTP_PASSWORD", "FTP_REMOTE_DIR") if not env.get(k)]
    if missing:
        sys.exit("%s の以下が未記入です: %s" % (ENV_FILE, ", ".join(missing)))
    return env


def ensure_dir(ftp, path):
    """リモートのディレクトリを掘りながら移動する。"""
    for part in path.strip("/").split("/"):
        try:
            ftp.cwd(part)
        except ftplib.error_perm:
            ftp.mkd(part)
            ftp.cwd(part)


def main():
    env = load_env()

    print("[1/3] 配信バンドルを生成")
    r = subprocess.run([sys.executable, os.path.join(CODEX, "plugin-patches", "build-keys-host.py")],
                       capture_output=True, text=True, encoding="utf-8", errors="replace")
    print(r.stdout.strip())
    if r.returncode != 0:
        sys.exit("生成に失敗:\n" + (r.stderr or ""))

    print("\n[2/3] FTPS アップロード -> %s%s" % (env["FTP_HOST"], env["FTP_REMOTE_DIR"]))
    ctx = ssl.create_default_context()
    ftp = ftplib.FTP_TLS(context=ctx)
    ftp.connect(env["FTP_HOST"], int(env.get("FTP_PORT") or 21), timeout=60)
    ftp.login(env["FTP_USER"], env["FTP_PASSWORD"])
    ftp.prot_p()

    root = ftp.pwd()
    sent = 0
    for top in UPLOAD_DIRS:
        local_top = os.path.join(BUNDLE, top)
        for dirpath, _dirnames, filenames in os.walk(local_top):
            rel_dir = os.path.relpath(dirpath, BUNDLE).replace("\\", "/")
            ftp.cwd(root)
            ensure_dir(ftp, env["FTP_REMOTE_DIR"].strip("/") + "/" + rel_dir)
            for fn in filenames:
                with open(os.path.join(dirpath, fn), "rb") as fh:
                    ftp.storbinary("STOR " + fn, fh)
                print("   %s/%s" % (rel_dir, fn))
                sent += 1
    ftp.quit()
    print("   %d ファイル送信" % sent)

    print("\n[3/3] 配信内容を検証")
    api_dir = os.path.join(BUNDLE, "api", "plugin-update")
    ng = 0
    for key in sorted(k for k in os.listdir(api_dir) if not k.startswith(".")):
        want = json.load(open(os.path.join(api_dir, key), encoding="utf-8"))["version"]
        try:
            with urllib.request.urlopen("%s/api/plugin-update/%s" % (HOST_URL, key), timeout=25) as res:
                live = json.loads(res.read().decode("utf-8"))["version"]
        except Exception as e:
            live = "取得失敗(%s)" % e
        mark = "OK " if live == want else "NG "
        if live != want:
            ng += 1
        print("   %s %-22s サーバー=%-10s 期待=%s" % (mark, key, live, want))

    print()
    sys.exit(1 if ng else 0)


if __name__ == "__main__":
    main()
