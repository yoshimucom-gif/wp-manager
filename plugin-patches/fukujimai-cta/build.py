# -*- coding: utf-8 -*-
"""服じまい CTA・追従バナー のzipを作る。

    py plugin-patches/fukujimai-cta/build.py 1.0.0

やること:
  1. 本体PHPの Version: ヘッダーと FJCTA_VERSION を引数のバージョンに揃える
  2. data/a8_materials.json を服じまいプロジェクトの正から取り直す
  3. zipを作る（区切りは「/」。PowerShellのCompress-Archiveは使わない）
  4. zipの中身を検証して、古いzipを消す
"""
import io
import json
import os
import re
import shutil
import sys
import zipfile

SLUG = 'fukujimai-cta'
SRC = os.path.dirname(os.path.abspath(__file__))
REPO = os.path.abspath(os.path.join(SRC, '..', '..'))
DOWNLOADS = os.path.join(REPO, 'plugin-downloads')
HANDOFF = r'C:\Users\yoshi\OneDrive\デスクトップ\Claudeメディア構築'
MATERIALS_SRC = os.path.join(HANDOFF, '服じまい', 'data', 'a8_materials.json')

FILES = [
    'fukujimai-cta.php',
    'includes/plugin-updater.php',
    'includes/patterns.php',
    'includes/a8_materials.json',
    'assets/fjcta.css',
    'assets/fjcta.js',
]


def bump(version):
    path = os.path.join(SRC, 'fukujimai-cta.php')
    s = io.open(path, encoding='utf-8').read()
    s, n1 = re.subn(r"(?m)^(\s*\*\s*Version:\s*).*$", r"\g<1>" + version, s, count=1)
    s, n2 = re.subn(r"(define\('FJCTA_VERSION',\s*')[^']*(')", r"\g<1>" + version + r"\g<2>", s, count=1)
    if n1 != 1 or n2 != 1:
        sys.exit('バージョンの置換に失敗しました: header=%s const=%s' % (n1, n2))
    io.open(path, 'w', encoding='utf-8', newline='\n').write(s)
    print('バージョンを %s に揃えました' % version)


def sync_materials():
    if not os.path.exists(MATERIALS_SRC):
        print('! A8素材の正が見つかりません。同梱済みのものを使います: %s' % MATERIALS_SRC)
        return
    dst = os.path.join(SRC, 'includes', 'a8_materials.json')
    shutil.copyfile(MATERIALS_SRC, dst)
    d = json.load(io.open(dst, encoding='utf-8'))
    for k in ('fukuro', 'ribla', 'okoku'):
        if k not in d:
            sys.exit('A8素材に %s がありません' % k)
        for part in ('text', 'banner_300x250', 'banner_320x50'):
            if not d[k].get(part, {}).get('a8mat'):
                sys.exit('%s の %s に a8mat がありません' % (k, part))
    print('A8素材を取り直しました（3社 × text/300x250/320x50 を確認）')


def build(version):
    if not os.path.isdir(DOWNLOADS):
        os.makedirs(DOWNLOADS)
    out = os.path.join(DOWNLOADS, '%s-%s.zip' % (SLUG, version))

    with zipfile.ZipFile(out, 'w', zipfile.ZIP_DEFLATED) as z:
        for rel in FILES:
            path = os.path.join(SRC, rel.replace('/', os.sep))
            if not os.path.exists(path):
                sys.exit('ファイルがありません: %s' % path)
            z.write(path, '%s/%s' % (SLUG, rel))

    names = zipfile.ZipFile(out).namelist()
    bad = [n for n in names if '\\' in n]
    if bad:
        sys.exit('区切り文字が「\\」のエントリがあります: %s' % bad)
    if len(names) != len(FILES):
        sys.exit('中身の数が合いません: %s' % names)

    body = zipfile.ZipFile(out).read('%s/fukujimai-cta.php' % SLUG).decode('utf-8')
    if ("Version:     %s" % version) not in body and ("Version: %s" % version) not in body:
        sys.exit('zip内のPHPのバージョンが %s になっていません' % version)
    if 'Affiros_Plugin_Updater' in zipfile.ZipFile(out).read('%s/includes/plugin-updater.php' % SLUG).decode('utf-8'):
        sys.exit('更新チェッカーのクラス名が変わっていません')

    print('できました: %s' % out)
    for n in names:
        print('   %s' % n)

    for f in os.listdir(DOWNLOADS):
        if f.startswith(SLUG + '-') and f.endswith('.zip') and f != os.path.basename(out):
            os.remove(os.path.join(DOWNLOADS, f))
            print('古いzipを消しました: %s' % f)

    handoff = os.path.join(HANDOFF, '%s-%s.zip' % (SLUG, version))
    if os.path.isdir(HANDOFF):
        for f in os.listdir(HANDOFF):
            if f.startswith(SLUG + '-') and f.endswith('.zip') and f != os.path.basename(handoff):
                os.remove(os.path.join(HANDOFF, f))
        shutil.copyfile(out, handoff)
        print('受け渡し用: %s' % handoff)

    return out


if __name__ == '__main__':
    if len(sys.argv) < 2:
        sys.exit('使い方: py build.py <version>')
    v = sys.argv[1]
    if not re.match(r'^\d+\.\d+\.\d+$', v):
        sys.exit('バージョンの形が違います: %s' % v)
    bump(v)
    sync_materials()
    build(v)
