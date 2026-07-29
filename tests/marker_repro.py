#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
広告マーカー挿入の オフライン再現ハーネス（rewrite プラグイン側）

目的:
  リライトプラグイン (affiros-rewrite) の
    includes/marker-inserter.php  (Affiros_Rewrite_Marker_Inserter::insert)
    includes/gutenberg-converter.php (Affiros_Rewrite_Gutenberg::convert)
  を Python に忠実移植し、API を1円も叩かずに
  「設定どおりの位置にマーカーが入るか」を検証する。

  正規表現は PHP 版と文字列一致になるよう移植している（PCRE → re）。
  PHP は preg(/u) + substr/strlen がバイト単位、本移植は str(文字単位)だが、
  挿入位置はすべてマッチ境界（</h2> 直後 等）から取るため出力HTMLは論理的に一致する。

使い方:
  python tests/marker_repro.py            # 同梱フィクスチャを検証
  python tests/marker_repro.py <htmlfile> <article_type> "<title>"
"""
import re
import sys

# ──────────────────────────────────────────────────────────────────────────
# marker-inserter.php 移植
# ──────────────────────────────────────────────────────────────────────────

DEFAULT_PATTERNS = {
    'ranking': [
        {'position': 'after_each_h3_rank', 'design': 'vertical', 'repeat': 1},
        {'position': 'after_last_h2',      'design': 'compare',  'count': 5},
    ],
    'column': [
        {'position': 'before_first_h2',    'design': 'compare',  'count': 3},
        {'position': 'after_last_h2',      'design': 'compare',  'count': 3},
    ],
    'brand': [
        {'position': 'after_first_h2',     'design': 'vertical', 'repeat': 1},
        {'position': 'after_last_h2',      'design': 'vertical', 'repeat': 1},
    ],
}

_H2_RE = r'<h2[^>]*>(?:(?!</h2>)[\s\S])*?</h2>'


def build_marker(design='vertical', count=None):
    if not design or design == 'default':
        return '<!--ai-product-->'
    if design in ('compare', 'ranking', 'proscons', 'mini') and count:
        return f'<!--ai-product:{design}:{int(count)}-->'
    return f'<!--ai-product:{design}-->'


def find_matome_h2_range(html):
    re_kw = re.compile(
        r'<h2[^>]*>(?:(?!</h2>)[\s\S])*?'
        r'(?:まとめ|総まとめ|結論|要点|おわりに|最後に|総括|ベストバイ)'
        r'(?:(?!</h2>)[\s\S])*?</h2>', re.IGNORECASE)
    matches = list(re_kw.finditer(html))
    if not matches:
        return None
    section_re = re.compile(
        r'(?:選び方|選定|比較|一覧|早見|ポイント|チェック|シーン|目的|用途|使い方|レビュー)\s*まとめ',
        re.IGNORECASE)
    non_section = [m for m in matches
                   if not section_re.search(re.sub(r'<[^>]+>', '', m.group(0)))]
    pool = non_section or matches
    chosen = pool[-1]
    return (chosen.start(), chosen.end())


def find_first_h2_range(html):
    m = re.search(_H2_RE, html, re.IGNORECASE)
    return (m.start(), m.end()) if m else None


def find_last_h2_range(html):
    ms = list(re.finditer(_H2_RE, html, re.IGNORECASE))
    if not ms:
        return None
    return (ms[-1].start(), ms[-1].end())


def has_ranking_signal(title):
    return bool(re.search(r'[0-9０-９]+\s*選|ランキング', str(title or '')))


def collect_h3_rank_insertions(text, marker, matome_range, first_h2_range, title=''):
    h3_patterns = [
        r'<h3[^>]*>\s*(?:第\s*)?(?:\d+|[０-９]+)\s*位[\s:：、・　]*[^<]*?</h3>',
        r'<h3[^>]*>\s*No\.?\s*(?:\d+|[０-９]+)[\s:：、・　]*[^<]*?</h3>',
    ]
    if has_ranking_signal(title):
        h3_patterns.append(r'<h3[^>]*>\s*[①②③④⑤⑥⑦⑧⑨⑩][\s:：、・　]*[^<]*?</h3>')
    insertions = []
    seen = set()
    for pat in h3_patterns:
        for m in re.finditer(pat, text, re.IGNORECASE):
            if m.start() in seen:
                continue
            seen.add(m.start())
            insertions.append((m.end(), '\n' + marker))
    if not seen and has_ranking_signal(title):
        end_limit = matome_range[0] if matome_range else len(text)
        start_limit = first_h2_range[1] if first_h2_range else 0
        for m in re.finditer(r'<h3[^>]*>[^<]*?</h3>', text, re.IGNORECASE):
            if m.start() < start_limit or m.start() >= end_limit:
                continue
            insertions.append((m.end(), '\n' + marker))
    return insertions


def strip_leading_introduction_h2(html, title=''):
    if not html:
        return html
    text = str(html)
    m = re.search(
        r'\A\s*(?:<!--\s*wp:heading[^>]*-->\s*)?<h2([^>]*)>'
        r'((?:(?!</h2>)[\s\S])*?)</h2>(?:\s*<!--\s*/wp:heading\s*-->)?',
        text, re.IGNORECASE)
    if not m:
        return text
    match_end = m.end()
    h2_inner = re.sub(r'<[^>]+>', '', m.group(2)).strip()
    intro_keywords = ['とは', '結論', '本記事の', 'について', 'を知る',
                      '記事のポイント', 'この記事では', 'この記事の目的', 'はじめに']
    is_intro = any(kw in h2_inner for kw in intro_keywords)
    if not is_intro and title:
        norm = lambda s: re.sub(r'[\s\|｜・:：－—\-　]+', '', str(s)).lower()
        nt, nh = norm(title), norm(h2_inner)
        if nt and nh and len(nh) >= 8:
            if nh == nt:
                is_intro = True
            elif nt.find(nh) != -1 and len(nh) >= len(nt) * 0.85:
                is_intro = True
            elif nh.find(nt) != -1 and len(nt) >= len(nh) * 0.85:
                is_intro = True
    if not is_intro:
        return text
    return text[match_end:].lstrip()


def strip_summary_table_sections(html):
    if not html:
        return html
    text = str(html)
    kw = '早見表|早分かり|早わかり|一目でわかる|一目で分かる'
    pat = (r'(?:<!--\s*wp:heading[^>]*-->\s*)?'
           r'<h2[^>]*>(?:(?!</h2>)[\s\S])*?(?:' + kw + r')(?:(?!</h2>)[\s\S])*?</h2>'
           r'(?:\s*<!--\s*/wp:heading\s*-->)?'
           r'[\s\S]*?'
           r'(?=<h2|<!--\s*wp:heading|<h3[^>]*>\s*(?:<!--\s*wp:[^>]*-->\s*)?(?:第\s*)?[\d０-９]+\s*位|$)')
    rx = re.compile(pat, re.IGNORECASE)
    for _ in range(2):
        text = rx.sub('', text)
    return text


def insert(html, article_type, title='', patterns=None):
    stats = {'rules_attempted': 0, 'rules_applied': 0, 'rules_failed': [],
             'marker_count': 0, 'per_position': {}, 'fallback_used': False}
    if not html:
        return html, stats
    patterns = patterns or DEFAULT_PATTERNS
    rules = patterns.get(article_type) or []
    if not rules:
        return html, stats
    stats['rules_attempted'] = len(rules)

    text = str(html)
    text = strip_leading_introduction_h2(text, title)
    text = strip_summary_table_sections(text)

    matome_range = find_matome_h2_range(text)
    first_h2_range = find_first_h2_range(text)
    last_h2_range = find_last_h2_range(text)

    insertions = []
    for rule in rules:
        pos = rule.get('position', '')
        design = rule.get('design', 'vertical')
        count = rule.get('count')
        repeat = max(1, int(rule.get('repeat', 1)))
        marker = build_marker(design, count)
        marker_block = ('\n' + marker) * repeat
        placed = 0

        if pos == 'top':
            insertions.append((0, marker_block + '\n')); placed = repeat
        elif pos == 'bottom':
            insertions.append((len(text), '\n' + marker_block)); placed = repeat
        elif pos == 'before_first_h2' and first_h2_range:
            insertions.append((first_h2_range[0], marker_block + '\n')); placed = repeat
        elif pos == 'after_first_h2' and first_h2_range:
            insertions.append((first_h2_range[1], '\n' + marker_block)); placed = repeat
        elif pos == 'before_matome_h2' and matome_range:
            insertions.append((matome_range[0], marker_block + '\n')); placed = repeat
        elif pos == 'after_matome_h2' and matome_range:
            insertions.append((matome_range[1], '\n' + marker_block)); placed = repeat
        elif pos == 'after_last_h2' and last_h2_range:
            insertions.append((last_h2_range[1], '\n' + marker_block)); placed = repeat
        elif pos == 'after_each_h3_rank':
            h3ins = collect_h3_rank_insertions(text, marker, matome_range, first_h2_range, title)
            insertions.extend(h3ins); placed = len(h3ins)

        if placed > 0:
            stats['rules_applied'] += 1
            stats['marker_count'] += placed
            stats['per_position'][pos] = stats['per_position'].get(pos, 0) + placed
        else:
            stats['rules_failed'].append(pos)

    if stats['marker_count'] == 0:
        insertions.append((len(text), '\n' + build_marker('vertical')))
        stats['marker_count'] += 1
        stats['per_position']['bottom_fallback'] = 1
        stats['fallback_used'] = True

    insertions.sort(key=lambda x: x[0], reverse=True)
    for pos, txt in insertions:
        text = text[:pos] + txt + text[pos:]
    return text, stats


# ──────────────────────────────────────────────────────────────────────────
# gutenberg-converter.php 移植
# ──────────────────────────────────────────────────────────────────────────

_BLOCK_TAGS = ['h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'p', 'ul', 'ol',
               'table', 'blockquote', 'figure', 'pre']


def _wrap_block(element):
    if element[:4] == '<!--':
        return element
    m = re.match(r'^<([a-z][a-z0-9]*)', element, re.IGNORECASE)
    if not m:
        return element
    tag = m.group(1).lower()
    wraps = {
        'h1': ('<!-- wp:heading {"level":1} -->', '<!-- /wp:heading -->'),
        'h2': ('<!-- wp:heading -->', '<!-- /wp:heading -->'),
        'h3': ('<!-- wp:heading {"level":3} -->', '<!-- /wp:heading -->'),
        'h4': ('<!-- wp:heading {"level":4} -->', '<!-- /wp:heading -->'),
        'h5': ('<!-- wp:heading {"level":5} -->', '<!-- /wp:heading -->'),
        'h6': ('<!-- wp:heading {"level":6} -->', '<!-- /wp:heading -->'),
        'p':  ('<!-- wp:paragraph -->', '<!-- /wp:paragraph -->'),
        'ul': ('<!-- wp:list -->', '<!-- /wp:list -->'),
        'ol': ('<!-- wp:list {"ordered":true} -->', '<!-- /wp:list -->'),
        'blockquote': ('<!-- wp:quote -->', '<!-- /wp:quote -->'),
        'pre': ('<!-- wp:preformatted -->', '<!-- /wp:preformatted -->'),
        'figure': ('<!-- wp:image -->', '<!-- /wp:image -->'),
        'hr': ('<!-- wp:separator -->', '<!-- /wp:separator -->'),
    }
    if tag == 'table':
        if re.match(r'^<table', element, re.IGNORECASE):
            return f'<!-- wp:table -->\n<figure class="wp-block-table">{element}</figure>\n<!-- /wp:table -->'
        return f'<!-- wp:table -->\n{element}\n<!-- /wp:table -->'
    if tag in wraps:
        o, c = wraps[tag]
        return f'{o}\n{element}\n{c}'
    return element


def _wrap_paragraph(text):
    if re.match(r'^<[a-z]', text, re.IGNORECASE):
        return text
    return f'<!-- wp:paragraph -->\n<p>{text}</p>\n<!-- /wp:paragraph -->'


def gutenberg_convert(html):
    if not html:
        return str(html or '')
    html = str(html)
    if '<!-- wp:' in html:
        return html
    tags = '|'.join(_BLOCK_TAGS)
    pattern = re.compile(
        r'(<(' + tags + r')\b[^>]*>[\s\S]*?</\2>|<!--[\s\S]*?-->|<hr\b[^>]*/?>)',
        re.IGNORECASE)
    result = []
    last_end = 0
    for m in pattern.finditer(html):
        element = m.group(0)
        start = m.start()
        if start > last_end:
            piece = html[last_end:start].strip()
            if piece:
                result.append(_wrap_paragraph(piece))
        result.append(_wrap_block(element))
        last_end = m.end()
    if last_end < len(html):
        tail = html[last_end:].strip()
        if tail:
            result.append(_wrap_paragraph(tail))
    if not result:
        piece = html.strip()
        return '' if not piece else _wrap_paragraph(piece)
    return '\n\n'.join(result)


# ──────────────────────────────────────────────────────────────────────────
# 診断ユーティリティ
# ──────────────────────────────────────────────────────────────────────────

MARKER_RE = re.compile(r'<!--\s*ai-product(?::[a-z]+(?::[a-z0-9]+)?)?\s*-->', re.IGNORECASE)


def diagnose(html, article_type, title):
    """marker挿入 → gutenberg変換 を通し、マーカーがブロック境界に
    独立して並んでいるか（=商品挿入が壊れないか）を判定する。"""
    inserted, stats = insert(html, article_type, title)
    converted = gutenberg_convert(inserted)

    problems = []
    # 各マーカーが「ブロックの内側」に閉じ込められていないかを確認する。
    # 健全なら マーカー行は <!-- /wp:... --> と <!-- wp:... --> の間に独立して立つ。
    for m in MARKER_RE.finditer(converted):
        before = converted[max(0, m.start() - 60):m.start()]
        after = converted[m.end():m.end() + 60]
        # マーカーの直前が見出し/段落の閉じタグで、まだブロックが閉じていない場合は埋没
        # （例: </h2>\n<!--ai-product...-->\n<!-- /wp:heading -->）
        if re.search(r'</(h[1-6]|p|li|td|th|figure|blockquote)>\s*$', before) and \
           re.match(r'\s*<!--\s*/wp:', after):
            problems.append(f'マーカー埋没: ...{before[-30:]}[MARKER]{after[:30]}...')
    return {
        'stats': stats,
        'inserted': inserted,
        'converted': converted,
        'problems': problems,
    }


# ──────────────────────────────────────────────────────────────────────────
# 同梱フィクスチャ（過去に壊れた構造の再現）
# ──────────────────────────────────────────────────────────────────────────

FIXTURES = [
    {
        'name': 'ranking_選び方まとめ区切りあり',
        'type': 'ranking',
        'title': '洗える ルームシューズ おすすめ 10選',
        'html': (
            '<h2>ルームシューズの選び方</h2>\n<p>選び方の本文。</p>\n'
            '<h3>第1位 商品A</h3>\n<p>解説A。</p>\n'
            '<h3>第2位 商品B</h3>\n<p>解説B。</p>\n'
            '<h2>シーン別の選び方まとめ</h2>\n<p>区切り見出し。本物のまとめではない。</p>\n'
            '<h2>まとめ｜用途で選ぶのが失敗しないコツ</h2>\n<p>結びの本文。</p>'
        ),
    },
    {
        'name': 'column_先頭introH2あり',
        'type': 'column',
        'title': '冷感タオル 効果',
        'html': (
            '<h2>冷感タオルとは｜仕組みを知る</h2>\n<p>導入。これは intro なので削除対象。</p>\n'
            '<h2>冷感タオルの効果</h2>\n<p>本文。</p>\n'
            '<h2>まとめ</h2>\n<p>結び。</p>'
        ),
    },
    {
        'name': 'brand_標準',
        'type': 'brand',
        'title': 'ABC冷却ベスト レビュー',
        'html': (
            '<h2>ABC冷却ベストの特徴</h2>\n<p>特徴。</p>\n'
            '<h2>口コミ・評判</h2>\n<p>口コミ。</p>\n'
            '<h2>まとめ</h2>\n<p>結び。</p>'
        ),
    },
]


def main():
    if len(sys.argv) >= 4:
        path, atype, title = sys.argv[1], sys.argv[2], sys.argv[3]
        html = open(path, encoding='utf-8').read()
        cases = [{'name': path, 'type': atype, 'title': title, 'html': html}]
    else:
        cases = FIXTURES

    for c in cases:
        print('=' * 70)
        print(f"[{c['name']}]  type={c['type']}  title={c['title']}")
        r = diagnose(c['html'], c['type'], c['title'])
        print('  stats:', r['stats'])
        if r['problems']:
            print('  ⚠ 問題:')
            for p in r['problems']:
                print('   -', p)
        else:
            print('  ✓ マーカーはブロック境界に独立配置（埋没なし）')
        # マーカー周辺だけ抜粋表示
        print('  --- 変換後のマーカー周辺 ---')
        for m in MARKER_RE.finditer(r['converted']):
            ctx = r['converted'][max(0, m.start() - 50):m.end() + 20]
            print('   …' + ctx.replace('\n', '⏎') + '…')


if __name__ == '__main__':
    main()
