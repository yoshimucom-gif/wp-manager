"""WordPress 連携プラグインの配布 zip を一括ビルドする。

出力先: ../plugin-downloads/  （Flask の /download/plugin/<key> が配信）

各プラグインの zip はトップレベルにプラグインディレクトリを1つ持つ
WordPress 標準構造で、パス区切りは forward slash（WP 互換）。

各プラグインのソースは plugin-patches/<plugin>/ に置く（git 追跡下）。

更新手順:
  1. plugin-patches/affiros-*/ のソースを編集（Version ヘッダーも上げる）
  2. 下の SPECS と app.py の PLUGIN_DOWNLOADS の zip 名・version を更新
  3. python plugin-patches/build-all.py を実行
  4. 生成された zip を git commit
"""
import os
import zipfile

ROOT = os.path.dirname(os.path.abspath(__file__))
OUT_DIR = os.path.join(ROOT, os.pardir, 'plugin-downloads')

# (ソースディレクトリ, zip 内トップディレクトリ名, 出力 zip 名)
SPECS = [
    (os.path.join(ROOT, 'affiros-product-inserter'),
     'affiros-product-inserter', 'affiros-product-inserter-1.9.31.zip'),
    (os.path.join(ROOT, 'affiros-decoration'),
     'affiros-decoration', 'affiros-decoration-1.2.3.zip'),
    (os.path.join(ROOT, 'affiros-rewrite'),
     'affiros-rewrite', 'affiros-rewrite-0.5.17.zip'),
    (os.path.join(ROOT, 'affiros-categorizer'),
     'affiros-categorizer', 'affiros-categorizer-0.1.1.zip'),
    (os.path.join(ROOT, 'affiros-dup-cleaner'),
     'affiros-dup-cleaner', 'affiros-dup-cleaner-1.0.1.zip'),
    (os.path.join(ROOT, 'affiros-paragraph-splitter'),
     'affiros-paragraph-splitter', 'affiros-paragraph-splitter-1.1.5.zip'),
    (os.path.join(ROOT, 'affiros-reschedule'),
     'affiros-reschedule', 'affiros-reschedule-1.1.0.zip'),
    (os.path.join(ROOT, 'affiros-mark-stripper'),
     'affiros-mark-stripper', 'affiros-mark-stripper-1.0.0.zip'),
    (os.path.join(ROOT, 'affiros-auto-inserter'),
     'affiros-auto-inserter', 'affiros-auto-inserter-0.6.0.zip'),
]


def build(src_dir, top_name, out_name):
    out_path = os.path.join(OUT_DIR, out_name)
    if not os.path.isdir(src_dir):
        print(f"SKIP  {out_name}: source not found ({src_dir})")
        return
    if os.path.exists(out_path):
        os.remove(out_path)
    count = 0
    with zipfile.ZipFile(out_path, 'w', zipfile.ZIP_DEFLATED) as zf:
        for root, _, files in os.walk(src_dir):
            for name in files:
                full = os.path.join(root, name)
                rel = top_name + '/' + os.path.relpath(full, src_dir).replace(os.sep, '/')
                zf.write(full, rel)
                count += 1
    print(f"OK    {out_name}: {count} files")


def main():
    os.makedirs(OUT_DIR, exist_ok=True)
    for src, top, out in SPECS:
        build(src, top, out)


if __name__ == '__main__':
    main()
