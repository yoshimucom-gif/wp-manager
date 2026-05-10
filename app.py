import os
import json
import uuid
import threading
import re
import csv
import io
import math
import hashlib
from datetime import datetime, timedelta
from pathlib import Path
from functools import wraps
from html import escape, unescape
from html.parser import HTMLParser
from urllib.parse import quote_plus, urljoin

from flask import Flask, render_template, request, jsonify, session, redirect, url_for, Response, stream_with_context, send_from_directory
import anthropic
import openpyxl
import requests
from requests_aws4auth import AWS4Auth
try:
    from bs4 import BeautifulSoup, FeatureNotFound
except ImportError:
    BeautifulSoup = None
    FeatureNotFound = Exception

app = Flask(__name__)
app.secret_key = os.environ.get('SECRET_KEY', 'dev-secret-key-change-in-production')

DATA_DIR_WARNING = ''
CLAUDE_ARTICLE_MODEL = 'claude-sonnet-4-6'
SONNET_INPUT_USD_PER_MTOK = 3.0
SONNET_OUTPUT_USD_PER_MTOK = 15.0
USAGE_ESTIMATE_USD_JPY = 155

def is_writable_dir(path):
    try:
        path.mkdir(parents=True, exist_ok=True)
        test_path = path / '.affiros9_write_test'
        with open(test_path, 'w', encoding='utf-8') as f:
            f.write('ok')
        test_path.unlink(missing_ok=True)
        return True
    except Exception:
        return False

def resolve_data_dir():
    global DATA_DIR_WARNING
    configured = os.environ.get('DATA_DIR')
    if configured:
        path = Path(configured)
        if is_writable_dir(path):
            return path
        DATA_DIR_WARNING = f'DATA_DIR={configured} に書き込めません。RenderのPersistent Disk設定を確認してください。'
    if os.environ.get('RENDER') and Path('/data').exists() and is_writable_dir(Path('/data')):
        return Path('/data')
    fallback = Path('data')
    if is_writable_dir(fallback):
        if os.environ.get('RENDER') and not DATA_DIR_WARNING:
            DATA_DIR_WARNING = 'Renderの永続ディスクがマウントされていない可能性があります。'
        return fallback
    DATA_DIR_WARNING = DATA_DIR_WARNING or '保存先ディレクトリに書き込めません。'
    return fallback

DATA_DIR = resolve_data_dir()

ARTICLES_FILE = DATA_DIR / 'articles.json'
QUALITY_FILE = DATA_DIR / 'quality.json'
DECORATIONS_FILE = DATA_DIR / 'decorations.json'
SETTINGS_FILE = DATA_DIR / 'settings.json'
REWRITE_FILE = DATA_DIR / 'rewrite_items.json'
AD_DEFINITIONS_FILE = DATA_DIR / 'ad_definitions.json'
BATCH_JOBS_FILE = DATA_DIR / 'batch_jobs.json'


def load_json(path, default):
    if path.exists():
        with open(path, 'r', encoding='utf-8') as f:
            return json.load(f)
    return default

def save_json(path, data):
    path.parent.mkdir(parents=True, exist_ok=True)
    tmp_path = path.with_name(path.name + '.tmp')
    with open(tmp_path, 'w', encoding='utf-8') as f:
        json.dump(data, f, ensure_ascii=False, indent=2)
    os.replace(tmp_path, path)

def load_articles():
    return load_json(ARTICLES_FILE, [])

def save_articles(articles):
    save_json(ARTICLES_FILE, articles)

def load_batch_jobs():
    return load_json(BATCH_JOBS_FILE, [])

def save_batch_jobs(jobs):
    save_json(BATCH_JOBS_FILE, jobs[:50])

def load_rewrites():
    return load_json(REWRITE_FILE, [])

def save_rewrites(items):
    save_json(REWRITE_FILE, items)

def default_ad_definitions():
    now = datetime.now().isoformat()
    return [
        {
            'id': 'preset-ranking-comparison-rinker',
            'name': 'ランキング記事｜比較表直後 RINKER',
            'article_type': 'ranking',
            'source': 'both',
            'keyword_mode': 'ad_keywords',
            'search_keywords': '',
            'item_count': 5,
            'layout': 'rinker',
            'insertion_position': 'after_comparison',
            'amazon_button_label': 'Amazonで見る',
            'rakuten_button_label': '楽天市場で見る',
            'prompt': 'ランキング表や比較表の直後に、紹介商品と対応するカードを自然に配置。順位ごとの本文を邪魔しないよう、連続配置しすぎない。',
            'priority': 20,
            'enabled': True,
            'created_at': now,
            'updated_at': now,
        },
        {
            'id': 'preset-brand-review-rinker',
            'name': '商標記事｜レビューCTA RINKER',
            'article_type': 'brand',
            'source': 'both',
            'keyword_mode': 'ad_keywords',
            'search_keywords': '',
            'item_count': 2,
            'layout': 'rinker',
            'insertion_position': 'after_intro',
            'amazon_button_label': 'Amazonで見る',
            'rakuten_button_label': '楽天市場で見る',
            'prompt': '商品レビューの導入後、またはメリット・デメリット説明後に配置。公式リンクや結論CTAと競合しない位置に置く。',
            'priority': 30,
            'enabled': True,
            'created_at': now,
            'updated_at': now,
        },
        {
            'id': 'preset-column-recommendation-rinker',
            'name': 'コラム記事｜まとめ前おすすめ RINKER',
            'article_type': 'column',
            'source': 'both',
            'keyword_mode': 'article_keywords',
            'search_keywords': '',
            'item_count': 3,
            'layout': 'rinker',
            'insertion_position': 'before_summary',
            'amazon_button_label': 'Amazonで見る',
            'rakuten_button_label': '楽天市場で見る',
            'prompt': '悩み解決や選び方の説明が終わった後、まとめ前に関連商品を提案。記事内容と関係が薄い商品は避ける。',
            'priority': 55,
            'enabled': True,
            'created_at': now,
            'updated_at': now,
        },
        {
            'id': 'preset-rewrite-revenue-rinker',
            'name': 'SEOリライト｜収益導線補強 RINKER',
            'article_type': 'rewrite',
            'source': 'both',
            'keyword_mode': 'ad_keywords',
            'search_keywords': '',
            'item_count': 2,
            'layout': 'rinker',
            'insertion_position': 'auto',
            'amazon_button_label': 'Amazonで見る',
            'rakuten_button_label': '楽天市場で見る',
            'prompt': '既存記事の流れを崩さず、購入・比較意図がある見出し付近にだけ追加。情報記事では押し売り感を出さない。',
            'priority': 40,
            'enabled': True,
            'created_at': now,
            'updated_at': now,
        },
        {
            'id': 'preset-common-amazon-only',
            'name': '共通｜Amazonのみ補助CTA',
            'article_type': 'common',
            'source': 'amazon',
            'keyword_mode': 'ad_keywords',
            'search_keywords': '',
            'item_count': 2,
            'layout': 'rinker',
            'insertion_position': 'before_summary',
            'amazon_button_label': 'Amazonで見る',
            'rakuten_button_label': '楽天市場で見る',
            'prompt': 'Amazonでの購入意図が強い記事で使用。価格や在庫の断定は避け、比較・確認の導線として配置。',
            'priority': 80,
            'enabled': True,
            'created_at': now,
            'updated_at': now,
        },
        {
            'id': 'preset-common-rakuten-only',
            'name': '共通｜楽天のみ補助CTA',
            'article_type': 'common',
            'source': 'rakuten',
            'keyword_mode': 'ad_keywords',
            'search_keywords': '',
            'item_count': 2,
            'layout': 'rinker',
            'insertion_position': 'before_summary',
            'amazon_button_label': 'Amazonで見る',
            'rakuten_button_label': '楽天市場で見る',
            'prompt': '楽天市場との相性が高い商品記事で使用。ポイント訴求は控えめにし、商品確認の導線として配置。',
            'priority': 85,
            'enabled': True,
            'created_at': now,
            'updated_at': now,
        },
    ]

def load_ad_definitions():
    items = load_json(AD_DEFINITIONS_FILE, None)
    if items:
        return items
    settings = load_settings()
    if settings.get('ad_presets_seeded'):
        return items or []
    presets = default_ad_definitions()
    save_ad_definitions(presets)
    settings['ad_presets_seeded'] = True
    save_settings(settings)
    return presets

def save_ad_definitions(items):
    save_json(AD_DEFINITIONS_FILE, items)

OLD_DEFAULT_QUALITY_PROMPT = "SEOに最適化された、読みやすく情報量の多い記事を書いてください。見出しを適切に使い、具体例を含めてください。"
QUALITY_PRESET_VERSION = 3


def default_quality_presets():
    return [
        {
            "id": "default",
            "name": "SEO基本品質",
            "prompt": """SEOに強い記事の定義:
検索ユーザーの疑問・悩み・比較検討に対して、検索意図どおりに、信頼できる根拠と独自の判断材料を出し、読み終わった人が追加検索しなくても次の判断に進める記事にする。

必ず守る品質要件:
- 冒頭で「誰の、どんな悩みに、何を答える記事か」を明確にする
- 最初の1〜2見出し以内で結論や判断基準を提示し、読者を待たせない
- 検索意図に対する答え、理由、具体例、注意点、次の行動を本文内で完結させる
- 他サイトの一般論の要約で終わらせず、比較軸、選び方、判断コメント、失敗回避ポイントを入れる
- 価格、効果、口コミ、仕様、ランキング根拠など未確認情報は断定しない
- h2/h3の階層を崩さず、1見出し1テーマで整理する
- 読者が判断に迷う箇所にはFAQ、比較表、チェックリスト、注意ボックスを使う
- 広告やCTAは文脈に合う場所だけに置き、押し売りにしない
- 本文にAIの説明文、Markdown、Gutenbergコメント、サンプル文を出さない""",
            "target_chars": "",
            "tone": "ですます調",
            "extra_rules": "Helpful / Reliable / People-first を優先する。読者が読み終えた時点で「何を選ぶべきか」「次に何を確認すべきか」が分かる状態にする。",
            "is_default": True,
            "system_preset_version": QUALITY_PRESET_VERSION
        },
        {
            "id": "ranking-quality",
            "name": "ランキング記事品質（SEO強化）",
            "article_type": "ranking",
            "prompt": """ランキング記事でSEOに強い状態:
読者が「自分に合う商品・サービスはどれか」を追加検索せず判断できるように、選定基準、比較表、順位理由、弱点、向いている人をセットで提示する。

必ず守るランキング品質要件:
- タイトルに「N選」が含まれる場合、必ずN件の商品・サービス・選択肢を掲載する
- 比較表はヘッダーを除いてN行、個別ランキング見出しも1位〜N位まで欠番なく作る
- 冒頭で「選定基準」を3〜5個提示し、なぜその順位なのか読者が追えるようにする
- 各ランキング項目は「特徴」「おすすめ理由」「注意点・弱点」「向いている人」を必ず含める
- 比較表だけで終わらせず、1位からN位まで本文で個別解説する
- 価格、仕様、口コミ、効果は未確認なら断定せず「目安」「公式情報で確認」など安全な表現にする
- 全商品を無理に褒めず、向かない人や注意点も書いて信頼性を出す
- 最後に読者タイプ別の選び方、FAQ、迷った時の判断基準を入れる
- 比較表はスマホで崩れない列数にし、セル内を短くする
- 本文にAIの説明文、Markdown、Gutenbergコメント、サンプル文を出さない""",
            "target_chars": "",
            "tone": "ですます調",
            "extra_rules": "基本構成は、導入 → 結論/おすすめ早見表 → 選定基準 → 比較表 → 1位から順番に個別解説 → 選び方 → FAQ → まとめ。N選の件数不足は不可。",
            "is_default": False,
            "system_preset_version": QUALITY_PRESET_VERSION
        },
        {
            "id": "brand-quality",
            "name": "商標記事品質（SEO強化）",
            "article_type": "brand",
            "prompt": """商標記事でSEOに強い状態:
商品名・サービス名で検索した読者が、購入前/申込前の不安を解消し、自分に合うかを判断できる記事にする。

必ず守る商標記事品質要件:
- リード文は250〜350文字前後で、読者の検討状況、不安、この記事で分かることを明確にする
- 冒頭で結論を出し、「おすすめできる人」「おすすめしない人」を早めに提示する
- 口コミ・評判は良い点だけでなく悪い点・注意点も整理する
- 特徴、料金/価格、メリット、デメリット、使い方/購入方法、解約/返品/注意事項を必要に応じて入れる
- 公式情報で確認すべき項目は断定せず、確認導線を用意する
- 競合・代替商品がある場合は、軽い比較を入れて判断材料を増やす
- CTAは導入後、メリット説明後、まとめ前など文脈に合う位置だけに置く
- 本文にAIの説明文、Markdown、Gutenbergコメント、サンプル文を出さない""",
            "target_chars": "",
            "tone": "ですます調",
            "extra_rules": """基本構成:
リード文 250〜350文字前後
H2: ○○とは？特徴をわかりやすく解説
H2: ○○の口コミ・評判
H3: 良い口コミ・評判
H3: 悪い口コミ・注意点
H2: ○○のメリット
H2: ○○のデメリット・注意点
H2: ○○がおすすめな人・おすすめしない人
H2: ○○の購入方法・申込方法
H2: よくある質問
H2: まとめ
まとめでは判断材料を再整理し、自然なCTAを入れる。""",
            "is_default": False,
            "system_preset_version": QUALITY_PRESET_VERSION
        },
        {
            "id": "column-quality",
            "name": "コラム記事品質（SEO強化）",
            "article_type": "column",
            "prompt": """コラム記事でSEOに強い状態:
読者の悩み・疑問に対して、原因、背景、解決策、具体例を順番に示し、読み終わった時に次の行動が明確になる記事にする。

必ず守るコラム記事品質要件:
- リード文は250〜350文字前後で、読者の悩み、結論、この記事で分かることを示す
- 冒頭で結論や全体像を先に提示し、その後に理由や手順を深掘りする
- 原因/背景、具体例、解決策、注意点を入れて、薄い一般論で終わらせない
- 読者のレベルに合わせて専門用語を噛み砕く
- 必要に応じてチェックリスト、手順、比較表、FAQを入れる
- 収益導線は悩み解決の流れに合う場所だけに自然に入れる
- まとめでは要点を整理し、読者の次の行動を明確にする
- 本文にAIの説明文、Markdown、Gutenbergコメント、サンプル文を出さない""",
            "target_chars": "",
            "tone": "ですます調",
            "extra_rules": """基本構成:
リード文 250〜350文字前後
H2: ○○とは？まず結論
H2: ○○が重要な理由 / 起きる原因
H2: ○○を解決する方法
H3: 方法1
H3: 方法2
H3: 方法3
H2: 失敗しやすいポイント・注意点
H2: よくある質問
H2: まとめ
まとめでは本文の要点と、読者が次にやるべきことを提示する。""",
            "is_default": False,
            "system_preset_version": QUALITY_PRESET_VERSION
        }
    ]


def load_quality():
    presets = default_quality_presets()
    quality = load_json(QUALITY_FILE, presets)
    existing_ids = {q.get('id') for q in quality}
    changed = False
    for preset in presets:
        if preset['id'] not in existing_ids:
            quality.append(preset)
            changed = True
            continue
        existing = next((q for q in quality if q.get('id') == preset['id']), None)
        if not existing:
            continue
        version = int(existing.get('system_preset_version') or 0)
        should_upgrade = version < preset.get('system_preset_version', 0)
        if preset['id'] == 'default' and existing.get('prompt') == OLD_DEFAULT_QUALITY_PROMPT:
            should_upgrade = True
        if should_upgrade:
            preserve_default = existing.get('is_default', preset.get('is_default', False))
            preserve_reference = existing.get('reference_url', preset.get('reference_url', ''))
            existing.update({
                'name': preset.get('name', existing.get('name', '')),
                'article_type': preset.get('article_type', existing.get('article_type')),
                'prompt': preset.get('prompt', existing.get('prompt', '')),
                'target_chars': preset.get('target_chars', existing.get('target_chars', '')),
                'tone': preset.get('tone', existing.get('tone', 'ですます調')),
                'extra_rules': preset.get('extra_rules', existing.get('extra_rules', '')),
                'system_preset_version': preset.get('system_preset_version', version),
                'is_default': preserve_default,
                'reference_url': preserve_reference,
            })
            if existing.get('article_type') is None:
                existing.pop('article_type', None)
            changed = True
    if changed:
        save_json(QUALITY_FILE, quality)
    return quality

def save_quality(quality):
    save_json(QUALITY_FILE, quality)

def load_decorations():
    return load_json(DECORATIONS_FILE, [])

def save_decorations(decorations):
    save_json(DECORATIONS_FILE, decorations)

def first_env(*names):
    for name in names:
        value = os.environ.get(name)
        if value:
            return value
    return ''

def apply_settings_env_fallbacks(settings):
    fallback_map = {
        'claude_api_key': ('ANTHROPIC_API_KEY', 'CLAUDE_API_KEY'),
        'amazon_access_key': ('AMAZON_ACCESS_KEY_ID', 'AMAZON_ACCESS_KEY'),
        'amazon_secret_key': ('AMAZON_SECRET_ACCESS_KEY', 'AMAZON_SECRET_KEY'),
        'amazon_partner_tag': ('AMAZON_PARTNER_TAG',),
        'rakuten_application_id': ('RAKUTEN_APPLICATION_ID',),
        'rakuten_affiliate_id': ('RAKUTEN_AFFILIATE_ID',),
    }
    for setting_key, env_names in fallback_map.items():
        if not settings.get(setting_key):
            env_value = first_env(*env_names)
            if env_value:
                settings[setting_key] = env_value
    return settings

def storage_status():
    data_dir = DATA_DIR.resolve()
    is_render = bool(os.environ.get('RENDER'))
    is_mount = os.path.ismount(str(data_dir))
    expected_persistent = not is_render or is_mount
    warning = DATA_DIR_WARNING
    if is_render and not is_mount:
        warning = warning or 'Renderの永続ディスクがマウントされていない可能性があります。保存データがデプロイや再起動で消える恐れがあります。'
    return {
        'data_dir': str(data_dir),
        'is_render': is_render,
        'is_mount': is_mount,
        'persistent': expected_persistent,
        'warning': warning,
    }

def build_data_snapshot():
    return {
        'version': 1,
        'exported_at': datetime.now().isoformat(),
        'storage': storage_status(),
        'settings': load_settings(),
        'articles': load_articles(),
        'quality': load_quality(),
        'decorations': load_decorations(),
        'rewrite_items': load_rewrites(),
        'ad_definitions': load_ad_definitions(),
    }

def has_user_data(snapshot):
    settings = snapshot.get('settings') or {}
    quality = snapshot.get('quality') or []
    non_default_quality = [
        q for q in quality
        if q.get('id') != 'default' or q.get('name') != '標準品質'
    ]
    setting_keys = (
        'sites', 'claude_api_key', 'amazon_access_key', 'amazon_secret_key',
        'amazon_partner_tag', 'rakuten_application_id', 'rakuten_affiliate_id',
        'rakuten_asp_enabled', 'rakuten_asp_name', 'rakuten_asp_link_template',
        'rakuten_asp_prompt', 'article_css'
    )
    return any([
        bool(snapshot.get('articles')),
        bool(snapshot.get('decorations')),
        bool(snapshot.get('rewrite_items')),
        bool(snapshot.get('ad_definitions')),
        bool(non_default_quality),
        any(bool(settings.get(k)) for k in setting_keys),
        any(bool(v) for v in (settings.get('quality_style_references') or {}).values()),
    ])

def restore_data_snapshot(snapshot):
    if isinstance(snapshot.get('settings'), dict):
        save_settings(snapshot['settings'])
    if isinstance(snapshot.get('articles'), list):
        save_articles(snapshot['articles'])
    if isinstance(snapshot.get('quality'), list):
        save_quality(snapshot['quality'])
    if isinstance(snapshot.get('decorations'), list):
        save_decorations(snapshot['decorations'])
    if isinstance(snapshot.get('rewrite_items'), list):
        save_rewrites(snapshot['rewrite_items'])
    if isinstance(snapshot.get('ad_definitions'), list):
        save_ad_definitions(snapshot['ad_definitions'])

def load_settings():
    settings = load_json(SETTINGS_FILE, {
        "sites": [],
        "claude_api_key": "",
        "default_quality_id": "default",
        "amazon_access_key": "",
        "amazon_secret_key": "",
        "amazon_partner_tag": "",
        "rakuten_application_id": "",
        "rakuten_affiliate_id": "",
        "rakuten_asp_enabled": False,
        "rakuten_asp_name": "",
        "rakuten_asp_link_template": "",
        "rakuten_asp_link_text": "楽天市場で詳細を見る",
        "rakuten_asp_prompt": "",
        "article_css": "",
        "ad_presets_seeded": False,
        "quality_style_references": {
            "ranking": "",
            "brand": "",
            "column": "",
        },
    })
    return apply_settings_env_fallbacks(settings)

MASK_CHAR = '•'

def mask_secret(value, visible_prefix=4):
    value = value or ''
    if not value:
        return ''
    prefix_len = min(visible_prefix, len(value))
    return value[:prefix_len] + (MASK_CHAR * (len(value) - prefix_len))

def is_masked_value(value):
    return MASK_CHAR in (value or '')

def looks_like_html(value):
    text = value or ''
    return bool(re.search(r'<!--\s*wp:', text, re.I) or re.search(r'</?[a-z][\s\S]*>', text, re.I))

class _TextExtractor(HTMLParser):
    SKIP = {'script', 'style', 'nav', 'header', 'footer', 'aside', 'noscript'}
    def __init__(self):
        super().__init__()
        self._parts = []
        self._depth = 0
    def handle_starttag(self, tag, attrs):
        if tag in self.SKIP: self._depth += 1
    def handle_endtag(self, tag):
        if tag in self.SKIP: self._depth = max(0, self._depth - 1)
    def handle_data(self, data):
        if self._depth == 0:
            t = data.strip()
            if t: self._parts.append(t)
    def text(self): return '\n'.join(self._parts)


class _LinkExtractor(HTMLParser):
    def __init__(self):
        super().__init__()
        self.links = []
        self._current = None

    def handle_starttag(self, tag, attrs):
        if tag != 'a':
            return
        data = dict(attrs)
        self._current = {'href': data.get('href', ''), 'text': []}

    def handle_data(self, data):
        if self._current is not None:
            text = data.strip()
            if text:
                self._current['text'].append(text)

    def handle_endtag(self, tag):
        if tag == 'a' and self._current is not None:
            self.links.append({
                'href': self._current['href'],
                'text': ' '.join(self._current['text']).strip()
            })
            self._current = None


def fetch_url_text(url, max_chars=4000):
    resp = requests.get(url, timeout=10, headers={'User-Agent': 'Mozilla/5.0'})
    resp.raise_for_status()
    p = _TextExtractor()
    p.feed(resp.text)
    return p.text()[:max_chars]


def html_to_text(html):
    parser = _TextExtractor()
    parser.feed(html or '')
    return parser.text()


def article_html_output_rules():
    return """出力ルール:
- 記事本文HTMLのみを出力する。説明文、前置き、「以下に作成しました」、Markdownの```は絶対に出力しない
- <style>、<script>、<html>、<body>、<article>、<main>、iframe、form、input、buttonは出力しない
- tableを使う場合は <table><thead><tbody><tr><th><td> を正しく閉じ、tableの外に他要素が漏れないようにする
- WordPress/Gutenbergコメント（<!-- wp:... -->、<!-- /wp:... -->）は出力しない
- 装飾サンプルはCSSクラスや構造の参考にするだけ。サンプル本文、人物画像URL、質問文、回答文、プレースホルダーは流用しない
- 比較表は横幅が崩れにくいように列を増やしすぎず、セル内は短くする
- 断定しすぎず、選び方・比較理由・向いている人・注意点を具体的に書く"""


def decoration_reference_prompt(sample_html, limit=4000):
    sample = str(sample_html or '').strip()
    if not sample:
        return ''
    return f"""
装飾サンプルHTML（参考専用・本文にコピー禁止）:
- 以下はCSSクラス、ボックス構造、見出し構造、装飾パターンを学ぶための資料です
- サンプル本文、画像URL、質問文、回答文、プレースホルダー、Gutenbergコメントをそのまま出力しないでください
- 記事テーマに合わせて中身を必ず置き換え、壊れたHTMLや途中で切れたブロックは出力しないでください

--- 装飾サンプルここから ---
{sample[:limit]}
--- 装飾サンプルここまで ---"""


def strip_wp_block_artifacts(html):
    text = str(html or '')
    text = re.sub(r'&lt;!--\s*/?wp:[\s\S]*?--&gt;', '', text, flags=re.I)
    text = re.sub(r'<!--\s*/?wp:[\s\S]*?-->', '', text, flags=re.I)
    text = re.sub(r'(?im)^\s*/?wp:[a-z0-9_/\-]+(?:\s+\{[^\n\r]*\})?\s*$', '', text)
    text = re.sub(r'(?i)(?:^|\s)/?wp:[a-z0-9_/\-]+(?:\s+\{[^<\n\r]*?\})?', ' ', text)
    return text


def strip_generated_noise(content):
    text = strip_wp_block_artifacts(content).strip()
    text = re.sub(r'(?m)^\s*`{3,}(?:html|HTML)?\s*$', '', text)
    text = re.sub(r'^\s*```(?:html|HTML)?\s*', '', text)
    text = re.sub(r'\s*```\s*$', '', text)
    text = text.replace('```html', '').replace('```HTML', '').replace('```', '')
    first = re.search(
        r'(<h[2-4]\b|<p\b|<ul\b|<ol\b|<table\b|<div\b|<!--\s*wp:(?!/))',
        text,
        flags=re.I
    )
    if first:
        text = text[first.start():]
    text = re.sub(r'^(?:\s*</[^>]+>\s*|\s*<!--\s*/wp:[\s\S]*?-->\s*)+', '', text, flags=re.I)
    return text.strip().strip('`').strip()


def balance_common_html_tags(html):
    closing_order = ['td', 'th', 'tr', 'tbody', 'thead', 'table', 'li', 'ul', 'ol', 'div']
    fixed = html
    for tag in closing_order:
        opens = len(re.findall(fr'<{tag}\b[^>]*>', fixed, flags=re.I))
        closes = len(re.findall(fr'</{tag}\s*>', fixed, flags=re.I))
        if opens > closes:
            fixed += ''.join(f'</{tag}>' for _ in range(opens - closes))
    return fixed


def sanitize_generated_html(content):
    html = strip_wp_block_artifacts(strip_generated_noise(content))
    html = re.sub(r'<\s*(script|style|iframe|object|embed|form|input|textarea|button)\b[\s\S]*?<\s*/\s*\1\s*>', '', html, flags=re.I)
    html = re.sub(r'<\s*(script|style|iframe|object|embed|form|input|textarea|button)\b[^>]*?/?>', '', html, flags=re.I)
    html = re.sub(r'</?\s*(html|body|article|main|head|meta|link)\b[^>]*>', '', html, flags=re.I)
    if BeautifulSoup:
        try:
            soup = BeautifulSoup(f'<div id="affiros9-root">{html}</div>', 'html5lib')
        except FeatureNotFound:
            soup = BeautifulSoup(f'<div id="affiros9-root">{html}</div>', 'html.parser')
        root = soup.find(id='affiros9-root')
        if not root:
            return balance_common_html_tags(html).strip().strip('`').strip()
        for tag in root.find_all(['script', 'style', 'iframe', 'object', 'embed', 'form', 'input', 'textarea', 'button']):
            tag.decompose()
        for tag in root.find_all(True):
            for attr in list(tag.attrs):
                if attr.lower().startswith('on'):
                    del tag.attrs[attr]
        html = ''.join(str(child) for child in root.contents)
    if not BeautifulSoup:
        html = balance_common_html_tags(html)
    return strip_wp_block_artifacts(html).strip().strip('`').strip()


def block_attrs(attrs=None):
    return '' if not attrs else ' ' + json.dumps(attrs, ensure_ascii=False, separators=(',', ':'))


def wp_block(name, inner='', attrs=None):
    return f'<!-- wp:{name}{block_attrs(attrs)} -->\n{inner.strip()}\n<!-- /wp:{name} -->'


def tag_inner_html(tag):
    return ''.join(str(child) for child in tag.contents)


def list_to_block(tag):
    ordered = tag.name == 'ol'
    tag.name = 'ol' if ordered else 'ul'
    classes = list(tag.get('class', []))
    if 'wp-block-list' not in classes:
        classes.append('wp-block-list')
    tag['class'] = classes
    list_name = tag.name
    item_blocks = []
    for li in tag.find_all('li', recursive=False):
        item_blocks.append(wp_block('list-item', str(li)))
    if item_blocks:
        attrs = ' '.join(f'{k}="{escape(" ".join(v) if isinstance(v, list) else str(v), quote=True)}"' for k, v in tag.attrs.items())
        inner = f'<{list_name} {attrs}>\n' + '\n'.join(item_blocks) + f'\n</{list_name}>'
    else:
        inner = str(tag)
    return wp_block('list', inner, {'ordered': True} if ordered else None)


def table_to_block(tag):
    table_html = str(tag)
    return wp_block('table', f'<figure class="wp-block-table">{table_html}</figure>')


def image_to_block(tag):
    return wp_block('image', f'<figure class="wp-block-image">{str(tag)}</figure>')


def node_to_gutenberg_block(node):
    if not getattr(node, 'name', None):
        text = str(node).strip()
        return wp_block('paragraph', f'<p>{escape(text)}</p>') if text else ''

    name = node.name.lower()
    if name in ('html', 'body'):
        return '\n\n'.join(filter(None, (node_to_gutenberg_block(child) for child in node.contents)))
    if name == 'p':
        if not node.get_text(strip=True) and not node.find(['img', 'a', 'mark', 'strong', 'em']):
            return ''
        if node.find('img') and len([c for c in node.contents if str(c).strip()]) == 1:
            img = node.find('img')
            return image_to_block(img)
        return wp_block('paragraph', str(node))
    if re.fullmatch(r'h[1-6]', name):
        level = int(name[1])
        if level == 1:
            level = 2
            node.name = 'h2'
        attrs = {'level': level} if level != 2 else None
        return wp_block('heading', str(node), attrs)
    if name in ('ul', 'ol'):
        return list_to_block(node)
    if name == 'table':
        return table_to_block(node)
    if name == 'figure':
        table = node.find('table')
        if table:
            node['class'] = list(set(list(node.get('class', [])) + ['wp-block-table']))
            return wp_block('table', str(node))
        image = node.find('img')
        if image:
            node['class'] = list(set(list(node.get('class', [])) + ['wp-block-image']))
            return wp_block('image', str(node))
        return wp_block('html', str(node))
    if name == 'img':
        return image_to_block(node)
    if name == 'blockquote':
        return wp_block('quote', str(node))
    if name == 'pre':
        return wp_block('code', str(node))
    if name == 'hr':
        return '<!-- wp:separator -->\n<hr class="wp-block-separator has-alpha-channel-opacity"/>\n<!-- /wp:separator -->'
    if name in ('div', 'section', 'aside'):
        if node.attrs:
            return wp_block('html', str(node))
        return '\n\n'.join(filter(None, (node_to_gutenberg_block(child) for child in node.contents)))
    return wp_block('html', str(node))


def convert_html_to_gutenberg_blocks(content):
    html = sanitize_generated_html(content)
    if not html:
        return ''
    if not BeautifulSoup:
        return html
    try:
        soup = BeautifulSoup(f'<div id="affiros9-block-root">{html}</div>', 'html5lib')
    except FeatureNotFound:
        soup = BeautifulSoup(f'<div id="affiros9-block-root">{html}</div>', 'html.parser')
    root = soup.find(id='affiros9-block-root')
    if not root:
        return html
    blocks = [node_to_gutenberg_block(child) for child in root.contents]
    return '\n\n'.join(block for block in blocks if block.strip())


def safe_article_css(value):
    css = str(value or '').strip()
    if not css or looks_like_html(css):
        return ''
    return re.sub(r'</?\s*style\b[^>]*>', '', css, flags=re.I).strip()


def prepare_article_content_for_publish(content, settings):
    clean_content = convert_html_to_gutenberg_blocks(content)
    article_css = safe_article_css(settings.get('article_css', ''))
    if article_css:
        css_block = wp_block('html', f'<style>{article_css}</style>')
        return css_block + '\n\n' + clean_content
    return clean_content


def normalize_article_type(value, default='ranking'):
    raw = str(value or '').strip().lower()
    mapping = {
        'ranking': 'ranking',
        'rank': 'ranking',
        'ランキング': 'ranking',
        'ランキング記事': 'ranking',
        'おすすめ': 'ranking',
        '比較': 'ranking',
        'brand': 'brand',
        'review': 'brand',
        '商標': 'brand',
        '商標記事': 'brand',
        'レビュー': 'brand',
        'レビュー記事': 'brand',
        'column': 'column',
        'コラム': 'column',
        'コラム記事': 'column',
        'rewrite': 'rewrite',
        'seoリライト': 'rewrite',
        'リライト': 'rewrite',
    }
    return mapping.get(raw, default)


def article_type_label(article_type):
    return {
        'ranking': 'ランキング記事',
        'brand': '商標記事',
        'column': 'コラム記事',
        'rewrite': 'SEOリライト',
    }.get(article_type, 'ランキング記事')


def clamp_int(value, default, min_value, max_value):
    try:
        number = int(value)
    except (TypeError, ValueError):
        return default
    return max(min_value, min(number, max_value))


def normalize_slug(value):
    slug = str(value or '').strip().strip('/')
    slug = re.sub(r'\s+', '-', slug)
    slug = re.sub(r'-{2,}', '-', slug)
    return slug


def build_schedule_datetime(index, schedule_data, date_override=None, slot_override=None):
    daily_limit = clamp_int(schedule_data.get('daily_limit'), 20, 1, 20)
    interval_minutes = clamp_int(schedule_data.get('interval_minutes'), 30, 1, 180)
    start_date = str(date_override or schedule_data.get('start_date') or '').strip()
    start_time = str(schedule_data.get('start_time') or '09:00').strip()
    try:
        base = datetime.strptime(start_date, '%Y-%m-%d')
    except ValueError:
        base = datetime.now() + timedelta(days=1)
        base = base.replace(hour=0, minute=0, second=0, microsecond=0)
    match = re.match(r'^(\d{1,2}):(\d{2})$', start_time)
    hour = clamp_int(match.group(1), 9, 0, 23) if match else 9
    minute = clamp_int(match.group(2), 0, 0, 59) if match else 0
    base = base.replace(hour=hour, minute=minute, second=0, microsecond=0)
    minimum = datetime.now() + timedelta(minutes=10)
    if base < minimum:
        base = minimum.replace(second=0, microsecond=0)
    day_offset = 0 if date_override else index // daily_limit
    slot_offset = slot_override if slot_override is not None else index % daily_limit
    return base + timedelta(days=day_offset, minutes=interval_minutes * slot_offset)


def normalize_schedule_date_key(value, fallback):
    try:
        return datetime.strptime(str(value or '').strip(), '%Y-%m-%d').strftime('%Y-%m-%d')
    except ValueError:
        return fallback


def estimate_tokens_from_text(text):
    return max(1, math.ceil(len(str(text or '')) / 2))


def extract_usage_value(usage, name):
    if not usage:
        return None
    if isinstance(usage, dict):
        return usage.get(name)
    return getattr(usage, name, None)


def build_article_usage(prompt, content, message=None):
    usage = getattr(message, 'usage', None) if message else None
    input_tokens = extract_usage_value(usage, 'input_tokens')
    output_tokens = extract_usage_value(usage, 'output_tokens')
    estimated = False
    if input_tokens is None:
        input_tokens = estimate_tokens_from_text(prompt)
        estimated = True
    if output_tokens is None:
        output_tokens = estimate_tokens_from_text(content)
        estimated = True
    cost_usd = (input_tokens / 1_000_000 * SONNET_INPUT_USD_PER_MTOK) + (output_tokens / 1_000_000 * SONNET_OUTPUT_USD_PER_MTOK)
    return {
        'model': CLAUDE_ARTICLE_MODEL,
        'input_tokens': int(input_tokens),
        'output_tokens': int(output_tokens),
        'cost_usd': round(cost_usd, 6),
        'cost_yen': round(cost_usd * USAGE_ESTIMATE_USD_JPY, 2),
        'estimated': estimated,
        'pricing': {
            'input_usd_per_mtok': SONNET_INPUT_USD_PER_MTOK,
            'output_usd_per_mtok': SONNET_OUTPUT_USD_PER_MTOK,
            'usd_jpy': USAGE_ESTIMATE_USD_JPY,
        }
    }


def append_generation_usage(article, usage, run_id=None, generated_at=None, content=''):
    if not isinstance(usage, dict):
        return
    generated_at = generated_at or datetime.now().isoformat()
    run_id = run_id or str(uuid.uuid4())
    event = dict(usage)
    event.update({
        'run_id': run_id,
        'created_at': generated_at,
        'content_chars': len(html_to_text(content or '')),
    })
    history = article.get('usage_history')
    if not isinstance(history, list):
        history = []
    history.append(event)
    article['usage_history'] = history[-500:]
    article['usage'] = usage
    article['last_generation_run_id'] = run_id
    article['generation_count'] = int(article.get('generation_count') or 0) + 1


def content_hash(content):
    return hashlib.sha256(str(content or '').encode('utf-8')).hexdigest()


SCORE_VERSION = 2


def critical_html_tag_issues(html):
    raw = re.sub(r'<!--[\s\S]*?-->', '', str(html or ''))
    issues = []
    for tag in ['table', 'thead', 'tbody', 'tr', 'td', 'th', 'ul', 'ol', 'li', 'div']:
        opens = len(re.findall(fr'<{tag}\b[^>]*>', raw, flags=re.I))
        closes = len(re.findall(fr'</{tag}\s*>', raw, flags=re.I))
        if opens != closes:
            issues.append(f'{tag}タグの開始/終了数が不一致です')
    return issues


def visible_generation_artifact_count(html, text):
    raw = str(html or '')
    visible = str(text or '')
    patterns = [
        r'/?wp:[a-z0-9_/\-]+',
        r'<!--\s*/?wp:',
        r'&lt;!--\s*/?wp:',
        r'```',
        r'className\s*:',
        r'iconColor\s*:',
        r'\{["\'](?:level|count|design|type)["\']\s*:',
        r'以下に.*作成',
        r'HTML構造',
    ]
    return sum(len(re.findall(pattern, raw, flags=re.I)) + len(re.findall(pattern, visible, flags=re.I)) for pattern in patterns)


def scoring_caps_and_penalties(title, html, text, keywords=''):
    suggestions = []
    penalties = 0
    caps = []

    artifact_count = visible_generation_artifact_count(html, text)
    if artifact_count:
        caps.append(20)
        penalties += min(30, artifact_count * 5)
        suggestions.append('GutenbergコメントやAI出力の残骸が本文に出ています。記事HTMLが崩壊しているため再生成が必要です。')

    tag_issues = critical_html_tag_issues(html)
    if tag_issues:
        caps.append(35)
        penalties += min(25, len(tag_issues) * 6)
        suggestions.append('HTMLタグの閉じ忘れや入れ子崩れがあります。WordPress表示崩れの原因になるため修復してください。')

    ranking_expected = extract_ranking_count({'title': title or '', 'keywords': keywords or ''})
    if ranking_expected:
        ranked_count = count_ranked_items_from_text(html)
        table_rows = count_table_rows_from_html(html)
        if ranked_count < ranking_expected:
            caps.append(35)
            penalties += 25
            suggestions.append(f'タイトルは{ranking_expected}選ですが、個別ランキング見出しが{ranked_count}件しかありません。')
        if table_rows < ranking_expected:
            caps.append(45)
            penalties += 15
            suggestions.append(f'タイトルは{ranking_expected}選ですが、比較表が{table_rows}行しかありません。')

    return caps, penalties, suggestions


def score_article_content(title, content, keywords=''):
    text = html_to_text(content)
    compact_text = re.sub(r'\s+', '', text)
    char_count = len(compact_text)
    html = str(content or '')
    h2_count = len(re.findall(r'<h2\b', html, re.I))
    h3_count = len(re.findall(r'<h3\b', html, re.I))
    list_count = len(re.findall(r'<(ul|ol)\b', html, re.I))
    table_count = len(re.findall(r'<table\b', html, re.I))
    image_count = len(re.findall(r'<img\b', html, re.I))
    link_count = len(re.findall(r'<a\b', html, re.I))
    cta_count = len(re.findall(r'(申し込|購入|詳細|公式|無料|資料請求|登録|チェック|見る)', text))
    paragraphs = re.findall(r'<p\b[^>]*>(.*?)</p>', html, re.I | re.S)
    long_paragraphs = sum(1 for p in paragraphs if len(re.sub(r'<[^>]+>|\s+', '', p)) > 260)

    terms = [t.strip() for t in re.split(r'[,、\s]+', keywords or '') if t.strip()]
    main_keyword = terms[0] if terms else ''
    title_has_keyword = bool(main_keyword and main_keyword in (title or ''))
    body_has_keyword = bool(main_keyword and main_keyword in text)

    score = 0
    suggestions = []
    caps, penalties, critical_suggestions = scoring_caps_and_penalties(title, html, text, keywords)
    suggestions.extend(critical_suggestions)

    if char_count >= 3500:
        score += 25
    elif char_count >= 2500:
        score += 21
    elif char_count >= 1600:
        score += 16
    elif char_count >= 900:
        score += 10
    else:
        score += 4
        suggestions.append('本文量が少ないため、検索意図に対する回答・比較軸・具体例を追加してください。')

    title_len = len(title or '')
    if 24 <= title_len <= 42:
        score += 12
    elif 16 <= title_len <= 55:
        score += 8
    else:
        score += 4
        suggestions.append('タイトルは検索結果で伝わりやすい長さに整えると改善余地があります。')

    if main_keyword:
        if title_has_keyword:
            score += 10
        else:
            suggestions.append('主要キーワードをタイトル前半に自然に含めるとSEO評価を上げやすくなります。')
        if body_has_keyword:
            score += 6
        else:
            suggestions.append('本文内に主要キーワードと関連語を自然に含めてください。')
    else:
        score += 6
        suggestions.append('狙うキーワードが未設定です。インポート時にキーワード列を入れると判定精度が上がります。')

    if h2_count >= 4:
        score += 12
    elif h2_count >= 2:
        score += 8
    else:
        score += 3
        suggestions.append('H2見出しを増やし、検索意図ごとに章立てしてください。')

    if h3_count >= 3:
        score += 6
    elif h3_count >= 1:
        score += 4
    else:
        suggestions.append('H3でメリット・デメリット・手順などを分解すると読みやすくなります。')

    if list_count or table_count:
        score += 9
    else:
        score += 2
        suggestions.append('比較表・箇条書き・要点ボックスを入れてスキャンしやすくしてください。')

    if image_count:
        score += 4
    else:
        suggestions.append('画像や商品カードがない場合は、視覚的な理解補助を追加すると改善できます。')

    if link_count >= 2:
        score += 5
    elif link_count == 1:
        score += 3
    else:
        suggestions.append('内部リンク・公式リンク・広告リンクなど、読者の次の行動先を用意してください。')

    if cta_count >= 2:
        score += 5
    elif cta_count == 1:
        score += 3
    else:
        suggestions.append('まとめ前後に自然なCTAを置くと収益導線が強くなります。')

    if long_paragraphs == 0:
        score += 6
    elif long_paragraphs <= 2:
        score += 3
    else:
        suggestions.append('長すぎる段落が多いため、短い段落や箇条書きに分割してください。')

    if penalties:
        score -= penalties
    if caps:
        score = min(score, min(caps))
    score = max(0, min(100, score))
    grade = 'A' if score >= 85 else 'B' if score >= 70 else 'C' if score >= 55 else 'D'
    priority = 'high' if score < 55 else 'middle' if score < 70 else 'low'
    if not suggestions and score < 90:
        suggestions.append('上位記事との差分として、独自体験・比較軸・最新情報を追加するとさらに伸ばせます。')

    return {
        'score': score,
        'grade': grade,
        'priority': priority,
        'suggestions': suggestions[:5],
        'metrics': {
            'char_count': char_count,
            'h2_count': h2_count,
            'h3_count': h3_count,
            'list_count': list_count,
            'table_count': table_count,
            'image_count': image_count,
            'link_count': link_count,
            'cta_count': cta_count,
            'long_paragraphs': long_paragraphs,
            'main_keyword': main_keyword,
            'title_has_keyword': title_has_keyword,
            'body_has_keyword': body_has_keyword,
            'artifact_count': visible_generation_artifact_count(html, text),
            'html_issues': critical_html_tag_issues(html),
            'score_version': SCORE_VERSION,
        },
        'score_version': SCORE_VERSION,
        'scored_at': datetime.now().isoformat(),
    }


def apply_score_fields(item, title=None, content=None, keywords=None):
    score_data = score_article_content(
        title if title is not None else item.get('title', ''),
        content if content is not None else item.get('content', ''),
        keywords if keywords is not None else item.get('keywords', '')
    )
    item['seo_score'] = score_data['score']
    item['score_grade'] = score_data['grade']
    item['rewrite_priority'] = score_data['priority']
    item['score_data'] = score_data
    item['score_version'] = SCORE_VERSION
    return item


def score_is_current(item):
    try:
        return int(item.get('score_version') or item.get('score_data', {}).get('score_version') or 0) >= SCORE_VERSION
    except (TypeError, ValueError):
        return False


def ensure_article_scores_current(articles):
    changed = False
    for article in articles:
        if article.get('content') and not score_is_current(article):
            apply_score_fields(article)
            changed = True
    return changed


SEO_NEWS_PAGE_URL = 'https://developers.google.com/search/blog?hl=ja'
SEO_NEWS_FALLBACK = [
    {
        'title': 'Google 検索セントラル ブログ',
        'link': SEO_NEWS_PAGE_URL,
        'source': 'Google 検索セントラル',
        'published': '',
        'summary': 'Google検索のアルゴリズム更新、Search Console、構造化データなどの公式情報を確認できます。'
    },
    {
        'title': 'Google 検索ランキングの更新履歴',
        'link': 'https://status.search.google.com/products/rGHU1u87FJnkP6W2GwMi/history?hl=ja',
        'source': 'Google 検索ステータス ダッシュボード',
        'published': '',
        'summary': 'コアアップデートなど、検索ランキングシステムの更新履歴を確認できます。'
    },
    {
        'title': 'SEO スターター ガイド',
        'link': 'https://developers.google.com/search/docs/fundamentals/seo-starter-guide?hl=ja',
        'source': 'Google 検索セントラル',
        'published': '',
        'summary': '検索エンジン向けの基本改善ポイントを見直すための公式ガイドです。'
    },
]


def japanese_search_blog_summary(title):
    if any(word in title for word in ('コア アップデート', 'ランキング', 'スパム')):
        return '検索順位や品質評価に関わる重要な更新です。リライト優先度の判断材料として確認してください。'
    if any(word in title for word in ('Search Console', '分析', 'レポート')):
        return 'Search Consoleや分析まわりの更新です。流入低下や改善候補の確認に役立ちます。'
    if any(word in title for word in ('クロール', 'Googlebot', 'インデックス')):
        return 'クロールやインデックス登録に関する更新です。技術SEOの見直しに使えます。'
    if any(word in title for word in ('構造化データ', 'リッチリザルト', 'マークアップ')):
        return '構造化データや検索での見え方に関する更新です。記事装飾や商品情報の改善に役立ちます。'
    return 'Google検索セントラルの日本語記事です。SEO施策や記事改善の参考として確認できます。'


def fetch_seo_news(limit=5):
    resp = requests.get(
        SEO_NEWS_PAGE_URL,
        timeout=10,
        headers={'User-Agent': 'Affiros9/1.0 (+https://wp-manager.onrender.com)'}
    )
    resp.raise_for_status()
    parser = _LinkExtractor()
    parser.feed(resp.text)
    items = []
    seen = set()
    for link in parser.links:
        href = link.get('href') or ''
        title = unescape(link.get('text') or '').strip()
        if not title or '/search/blog/' not in href or title in seen:
            continue
        if not re.search(r'/search/blog/\d{4}/\d{2}/', href):
            continue
        full_url = urljoin(SEO_NEWS_PAGE_URL, href)
        if 'hl=' not in full_url:
            full_url += ('&' if '?' in full_url else '?') + 'hl=ja'
        published_match = re.search(r'/search/blog/(\d{4})/(\d{2})/', href)
        published = '-'.join(published_match.groups()) if published_match else ''
        items.append({
            'title': title,
            'link': full_url,
            'source': 'Google 検索セントラル',
            'published': published,
            'summary': japanese_search_blog_summary(title)
        })
        seen.add(title)
        if len(items) >= limit:
            break
    return items or SEO_NEWS_FALLBACK[:limit]


def amazon_search(keywords, access_key, secret_key, partner_tag, item_count=3):
    host = 'webservices.amazon.co.jp'
    path = '/paapi5/searchitems'
    target = 'com.amazon.paapi5.v1.ProductAdvertisingAPIv1.SearchItems'

    payload = json.dumps({
        'Keywords': keywords,
        'Resources': [
            'Images.Primary.Medium',
            'ItemInfo.Title',
            'Offers.Listings.Price',
            'CustomerReviews.Count',
            'CustomerReviews.StarRating',
        ],
        'SearchIndex': 'All',
        'ItemCount': item_count,
        'PartnerTag': partner_tag,
        'PartnerType': 'Associates',
        'Marketplace': 'www.amazon.co.jp',
        'LanguagesOfPreference': ['ja_JP'],
    })

    auth = AWS4Auth(access_key, secret_key, 'us-west-2', 'ProductAdvertisingAPI')
    resp = requests.post(
        f'https://{host}{path}',
        auth=auth,
        headers={
            'content-encoding': 'amz-1.0',
            'content-type': 'application/json; charset=utf-8',
            'x-amz-target': target,
        },
        data=payload,
        timeout=10
    )
    if not resp.ok:
        try:
            err_body = resp.json()
        except Exception:
            err_body = resp.text
        raise Exception(f'HTTP {resp.status_code}: {err_body}')
    data = resp.json()

    products = []
    for item in data.get('SearchResult', {}).get('Items', []):
        asin = item.get('ASIN', '')
        title = item.get('ItemInfo', {}).get('Title', {}).get('DisplayValue', '')
        image = item.get('Images', {}).get('Primary', {}).get('Medium', {}).get('URL', '')
        price = ''
        listings = item.get('Offers', {}).get('Listings', [])
        if listings:
            price = listings[0].get('Price', {}).get('DisplayAmount', '')
        rating = item.get('CustomerReviews', {}).get('StarRating', {}).get('Value')
        review_count = item.get('CustomerReviews', {}).get('Count')
        products.append({
            'asin': asin,
            'title': title,
            'image': image,
            'price': price,
            'rating': rating,
            'review_count': review_count,
            'url': f'https://www.amazon.co.jp/dp/{asin}?tag={partner_tag}',
        })
    return products


def rakuten_search(keywords, application_id, affiliate_id='', item_count=3):
    params = {
        'applicationId': application_id,
        'keyword': keywords,
        'hits': item_count,
        'format': 'json',
        'imageFlag': 1,
    }
    if affiliate_id:
        params['affiliateId'] = affiliate_id
    resp = requests.get(
        'https://app.rakuten.co.jp/services/api/IchibaItem/Search/20170706',
        params=params,
        timeout=10
    )
    if not resp.ok:
        raise Exception(f'HTTP {resp.status_code}: {resp.text[:200]}')
    data = resp.json()
    products = []
    for item in data.get('Items', []):
        aff_url = item.get('affiliateUrl') or item.get('itemUrl', '')
        medium_images = item.get('mediumImageUrls', [])
        image = medium_images[0].get('imageUrl', '') if medium_images else ''
        price = item.get('itemPrice')
        products.append({
            'title': item.get('itemName', ''),
            'price': f'¥{price:,}' if price else '',
            'image': image,
            'url': aff_url,
            'rating': item.get('reviewAverage'),
            'review_count': item.get('reviewCount'),
        })
    return products


def build_rinker_html(amazon_p=None, rakuten_p=None, amazon_label='Amazonで見る', rakuten_label='楽天市場で見る'):
    primary = amazon_p or rakuten_p
    if not primary:
        return ''
    title = escape(primary.get('title', ''))
    img = escape(primary.get('image', ''), quote=True)
    html = (
        '<div style="border:1px solid #e8e8e8;border-radius:8px;padding:16px 20px;margin:24px 0;'
        'background:#fff;box-shadow:0 1px 4px rgba(0,0,0,0.06)">'
        '<div style="display:flex;gap:16px;align-items:flex-start">'
    )
    if img:
        primary_url = escape(primary["url"], quote=True)
        html += (
            f'<a href="{primary_url}" target="_blank" rel="nofollow sponsored" style="flex-shrink:0">'
            f'<img src="{img}" alt="" style="width:110px;height:110px;object-fit:contain"></a>'
        )
    html += f'<div style="flex:1;min-width:0"><p style="margin:0 0 10px;font-weight:bold;font-size:14px;line-height:1.5">{title}</p>'
    prices = []
    if amazon_p and amazon_p.get('price'):
        prices.append(f'Amazon: <strong style="color:#B12704">{escape(str(amazon_p["price"]))}</strong>')
    if rakuten_p and rakuten_p.get('price'):
        prices.append(f'楽天: <strong style="color:#bf0000">{escape(str(rakuten_p["price"]))}</strong>')
    if prices:
        html += f'<p style="margin:0 0 12px;font-size:12px;color:#666">{" &nbsp;|&nbsp; ".join(prices)}</p>'
    html += '<div style="display:flex;gap:8px;flex-wrap:wrap">'
    if amazon_p:
        amazon_url = escape(amazon_p["url"], quote=True)
        safe_amazon_label = escape(amazon_label)
        html += (
            f'<a href="{amazon_url}" target="_blank" rel="nofollow sponsored" '
            f'style="display:inline-block;background:#ff9900;color:#111;padding:8px 18px;'
            f'text-decoration:none;border-radius:4px;font-weight:bold;font-size:13px;white-space:nowrap">'
            f'{safe_amazon_label}</a>'
        )
    if rakuten_p:
        rakuten_url = escape(rakuten_p["url"], quote=True)
        safe_rakuten_label = escape(rakuten_label)
        html += (
            f'<a href="{rakuten_url}" target="_blank" rel="nofollow sponsored" '
            f'style="display:inline-block;background:#bf0000;color:#fff;padding:8px 18px;'
            f'text-decoration:none;border-radius:4px;font-weight:bold;font-size:13px;white-space:nowrap">'
            f'{safe_rakuten_label}</a>'
        )
    html += '</div></div></div></div>'
    return html


def build_rakuten_asp_instruction(article, settings):
    if not settings.get('rakuten_asp_enabled'):
        return ''
    template = settings.get('rakuten_asp_link_template', '').strip()
    if not template:
        return ''

    title = article.get('title', '')
    keywords = article.get('keywords', '')
    primary_keyword = keywords.split(',')[0].strip() if keywords else title
    replacements = {
        '{title}': title,
        '{keyword}': primary_keyword,
        '{keywords}': keywords,
        '{encoded_title}': quote_plus(title),
        '{encoded_keyword}': quote_plus(primary_keyword),
        '{encoded_keywords}': quote_plus(keywords),
    }
    link_url = template
    for key, value in replacements.items():
        link_url = link_url.replace(key, value)

    link_text = settings.get('rakuten_asp_link_text') or '楽天市場で詳細を見る'
    safe_link_url = escape(link_url, quote=True)
    safe_link_text = escape(link_text, quote=True)
    asp_name = settings.get('rakuten_asp_name') or '楽天アフィリエイトASP'
    extra_prompt = settings.get('rakuten_asp_prompt', '').strip()
    instruction = f"""

楽天ASPリンク挿入:
- ASP名: {asp_name}
- 記事内の自然な購入導線として、以下のリンクを1〜3箇所に挿入してください。
- リンクは文脈に合う場所だけに入れ、不自然な連続配置は避けてください。
- HTMLは以下の形式を使ってください:
  <a href="{safe_link_url}" target="_blank" rel="nofollow sponsored noopener">{safe_link_text}</a>"""
    if extra_prompt:
        instruction += f"\n- 追加ルール: {extra_prompt}"
    return instruction


def select_ad_definition(data, article):
    definitions = load_ad_definitions()
    ad_definition_id = data.get('ad_definition_id') or article.get('ad_definition_id')
    if ad_definition_id:
        return next((d for d in definitions if d.get('id') == ad_definition_id), None)

    if not data.get('auto_ad_definition'):
        return None

    article_type = normalize_article_type(data.get('article_type') or article.get('article_type'), 'ranking')
    candidates = [
        d for d in definitions
        if d.get('enabled', True) and d.get('article_type', 'common') in ('common', article_type)
    ]
    candidates.sort(key=lambda d: clamp_int(d.get('priority'), 50, 1, 999))
    return candidates[0] if candidates else None


def ad_search_keywords(article, ad_definition):
    mode = (ad_definition or {}).get('keyword_mode', 'article_keywords')
    if mode == 'custom':
        return (ad_definition or {}).get('search_keywords', '').strip()
    if mode == 'title':
        return article.get('title', '').strip()
    if mode == 'ad_keywords':
        return article.get('ad_keywords', '').strip() or (ad_definition or {}).get('search_keywords', '').strip()
    return article.get('ad_keywords', '').strip() or article.get('keywords', '').strip() or article.get('title', '').strip()


def build_ad_product_blocks(article, settings, ad_definition=None, include_amazon=False, include_rakuten=False):
    source = (ad_definition or {}).get('source')
    if not source:
        if include_amazon and include_rakuten:
            source = 'both'
        elif include_amazon:
            source = 'amazon'
        elif include_rakuten:
            source = 'rakuten'
        else:
            return [], ''

    keywords = ad_search_keywords(article, ad_definition or {})
    if not keywords:
        return [], ''

    item_count = clamp_int((ad_definition or {}).get('item_count'), 3, 1, 10)
    amazon_label = (ad_definition or {}).get('amazon_button_label') or 'Amazonで見る'
    rakuten_label = (ad_definition or {}).get('rakuten_button_label') or '楽天市場で見る'

    amazon_products = []
    if source in ('amazon', 'both'):
        ak = settings.get('amazon_access_key', '')
        sk = settings.get('amazon_secret_key', '')
        pt = settings.get('amazon_partner_tag', '')
        if all([ak, sk, pt]):
            try:
                amazon_products = amazon_search(keywords, ak, sk, pt, item_count=item_count)
            except Exception:
                pass

    rakuten_products = []
    if source in ('rakuten', 'both'):
        ra_id = settings.get('rakuten_application_id', '')
        ra_aff = settings.get('rakuten_affiliate_id', '')
        if ra_id:
            try:
                rakuten_products = rakuten_search(keywords, ra_id, ra_aff, item_count=item_count)
            except Exception:
                pass

    product_blocks = []
    for i in range(max(len(amazon_products), len(rakuten_products))):
        a_p = amazon_products[i] if i < len(amazon_products) else None
        r_p = rakuten_products[i] if i < len(rakuten_products) else None
        product_blocks.append(build_rinker_html(a_p, r_p, amazon_label, rakuten_label))

    if not product_blocks:
        return [], ''

    position_map = {
        'auto': '記事の流れに合わせて自然な箇所',
        'after_intro': '導入文の直後',
        'before_summary': 'まとめ見出しの直前',
        'after_comparison': '比較・ランキング・レビュー説明の直後',
    }
    ad_name = (ad_definition or {}).get('name') or '手動広告挿入'
    position = position_map.get((ad_definition or {}).get('insertion_position', 'auto'), '記事の流れに合わせて自然な箇所')
    extra_rules = (ad_definition or {}).get('prompt', '').strip()
    prompt = f"""

広告挿入ルール:
- 広告定義: {ad_name}
- 検索キーワード: {keywords}
- 商品カードはHTMLを崩さず、{position}に自然に挿入してください。
- 広告リンクには rel="nofollow sponsored" が含まれています。"""
    if extra_rules:
        prompt += f"\n- 追加ルール: {extra_rules}"
    return product_blocks, prompt


def normalize_digits(text):
    return str(text or '').translate(str.maketrans('０１２３４５６７８９', '0123456789'))


def extract_ranking_count(article):
    source = normalize_digits(' '.join([
        article.get('title', ''),
        article.get('keywords', ''),
        article.get('category', ''),
    ]))
    match = re.search(r'([1-9][0-9]?)\s*選', source)
    if not match:
        return None
    count = int(match.group(1))
    return count if 2 <= count <= 30 else None


def build_ranking_count_prompt(article, article_type):
    if normalize_article_type(article_type, 'ranking') != 'ranking':
        return ''
    count = extract_ranking_count(article)
    if not count:
        return ''
    return f"""

ランキング件数の厳守:
- タイトルから「{count}選」と判断しています。本文では必ず{count}件を紹介してください。
- 比較表はヘッダーを除いて{count}行にしてください。
- 個別解説は「1位」から「{count}位」まで欠番・重複なしで作ってください。
- {count}件未満で終了しないでください。商品名や候補が不足する場合でも、記事テーマに合う候補を補って{count}件にしてください。"""


def count_table_rows_from_html(content):
    html = str(content or '')
    if BeautifulSoup:
        try:
            soup = BeautifulSoup(html, 'html5lib')
        except FeatureNotFound:
            soup = BeautifulSoup(html, 'html.parser')
        counts = []
        for table in soup.find_all('table'):
            body_rows = table.select('tbody tr')
            if body_rows:
                counts.append(len(body_rows))
            else:
                rows = table.find_all('tr')
                data_rows = [row for row in rows if row.find('td')]
                counts.append(len(data_rows))
        return max(counts or [0])
    table_counts = []
    for table in re.findall(r'<table\b[\s\S]*?</table>', html, flags=re.I):
        rows = re.findall(r'<tr\b[\s\S]*?</tr>', table, flags=re.I)
        data_rows = [row for row in rows if re.search(r'<td\b', row, flags=re.I)]
        table_counts.append(len(data_rows))
    return max(table_counts or [0])


def count_ranked_items_from_text(content):
    text = html_to_text(content)
    ranked = {
        int(m.group(1))
        for m in re.finditer(r'(?:^|\n|\s)(?:第\s*)?([1-9][0-9]?)\s*位(?:\s|[:：.、]|$)', text)
    }
    return len(ranked)


def detect_ranking_item_count(content):
    return max(count_ranked_items_from_text(content), count_table_rows_from_html(content))


def validate_generated_article(article, article_type, content):
    if normalize_article_type(article_type, 'ranking') != 'ranking':
        return ''
    expected = extract_ranking_count(article)
    if not expected:
        return ''
    ranked_count = count_ranked_items_from_text(content)
    table_rows = count_table_rows_from_html(content)
    if ranked_count < expected:
        return f'タイトルは{expected}選ですが、個別ランキング見出しが{ranked_count}件しか検出できませんでした。もう一度生成してください。'
    if table_rows < expected:
        return f'タイトルは{expected}選ですが、比較表が{table_rows}行しか検出できませんでした。もう一度生成してください。'
    return ''


def build_article_type_prompt(article_type):
    prompts = {
        'ranking': """記事種類: ランキング記事
- おすすめ記事・比較記事を統合した構成にする
- 読者が商品やサービスを選びやすいよう、選定基準、比較軸、ランキング理由を明確にする
- 比較表、ランキング理由、選び方、向いている人、注意点を入れる
- 根拠のない順位付けを避け、比較軸ごとに理由を書く
- ランキング表は商品名、特徴、価格帯、向いている人程度に絞り、セルを長文にしない
- 各商品の個別解説は順位付きのh3にし、比較表だけで終わらせない""",
        'brand': """記事種類: 商標記事（レビュー記事）
- 特定の商品・サービス名で検索する読者に向けたレビュー記事にする
- 特徴、口コミ・評判、メリット・デメリット、向いている人、購入・申込前の注意点を整理する
- 押し売りではなく、判断材料を丁寧に提示する""",
        'column': """記事種類: コラム記事
- 読者の悩みや疑問に対して、自然な読み物として理解を深める構成にする
- 導入、背景、具体例、解決策、まとめを自然につなげる
- アフィリエイト導線は必要な場所にだけ控えめに入れる""",
    }
    return prompts.get(article_type, '')


def build_quality_prompt(quality):
    if not quality:
        return ''
    parts = []
    base = quality.get('prompt', '')
    if base:
        parts.append(base)
    if quality.get('target_chars'):
        parts.append(f"目標文字数: {quality.get('target_chars')}文字を目安にしてください。")
    if quality.get('tone'):
        parts.append(f"文体: {quality.get('tone')}で統一してください。")
    if quality.get('extra_rules'):
        parts.append(f"追加品質ルール: {quality.get('extra_rules')}")
    return '\n'.join(parts)


def select_quality_definition(quality_list, quality_id=None, article_type='ranking'):
    if quality_id:
        found = next((q for q in quality_list if q.get('id') == quality_id), None)
        if found:
            return found
    normalized_type = normalize_article_type(article_type, 'ranking')
    return (
        next((q for q in quality_list if q.get('article_type') == normalized_type), None) or
        next((q for q in quality_list if q.get('is_default')), None) or
        (quality_list[0] if quality_list else None)
    )


def quality_style_reference_url(article_type, settings):
    refs = settings.get('quality_style_references') or {}
    normalized = normalize_article_type(article_type, 'ranking')
    return (refs.get(normalized) or '').strip()


def fetch_quality_style_reference(article_type, settings):
    url = quality_style_reference_url(article_type, settings)
    if not url:
        return '', ''
    return url, fetch_url_text(url)


def get_site_credentials(article, settings):
    site_id = article.get('site_id')
    if site_id:
        for s in settings.get('sites', []):
            if s['id'] == site_id:
                return s['wp_url'].rstrip('/'), s['wp_user'], s['wp_password']
    return '', '', ''


def get_site_by_id(site_id, settings):
    return next((s for s in settings.get('sites', []) if s.get('id') == site_id), None)


def split_categories(value):
    return [c.strip() for c in re.split(r'[,、/|]+', str(value or '')) if c and c.strip()]


def resolve_wp_category_ids(wp_url, wp_user, wp_password, category_value):
    ids = []
    for category in split_categories(category_value):
        if category.isdigit():
            ids.append(int(category))
            continue
        try:
            search = requests.get(
                f"{wp_url}/wp-json/wp/v2/categories",
                auth=(wp_user, wp_password),
                params={'search': category, 'per_page': 100},
                timeout=15
            )
            search.raise_for_status()
            categories = search.json()
            found = next((c for c in categories if c.get('name') == category), None)
            if found:
                ids.append(found['id'])
                continue
            created = requests.post(
                f"{wp_url}/wp-json/wp/v2/categories",
                auth=(wp_user, wp_password),
                json={'name': category},
                timeout=15
            )
            created.raise_for_status()
            ids.append(created.json()['id'])
        except Exception:
            continue
    return ids


def fetch_wp_categories(site, limit=100):
    categories = []
    page = 1
    wp_url = site['wp_url'].rstrip('/')
    while len(categories) < limit:
        resp = requests.get(
            f"{wp_url}/wp-json/wp/v2/categories",
            auth=(site['wp_user'], site['wp_password']),
            params={
                'per_page': min(100, limit - len(categories)),
                'page': page,
                'orderby': 'name',
                'order': 'asc',
                'hide_empty': False,
            },
            timeout=15
        )
        if resp.status_code == 400 and page > 1:
            break
        resp.raise_for_status()
        chunk = resp.json()
        if not chunk:
            break
        categories.extend(chunk)
        total_pages = int(resp.headers.get('X-WP-TotalPages') or page)
        if page >= total_pages:
            break
        page += 1
    return [
        {
            'id': c.get('id'),
            'name': c.get('name', ''),
            'slug': c.get('slug', ''),
            'count': c.get('count', 0),
        }
        for c in categories
        if c.get('name')
    ]


def get_rewrite_style_prompt(data, settings):
    structure_mode = data.get('structure_mode', 'seo')
    tone = data.get('tone', 'natural')
    decoration_level = data.get('decoration_level', 'standard')
    target_chars = str(data.get('target_chars', '')).strip()
    tolerance = int(data.get('tolerance', 10) or 10)

    structure_map = {
        'keep': '元記事の構成を活かしながら、段落と見出しを読みやすく整える',
        'organize': '段落と見出しをしっかり整理し、情報の順序を改善する',
        'rebuild': '記事構成から見直し、読者が理解しやすい流れに再構成する',
        'seo': 'SEO記事として検索意図、見出し階層、網羅性を意識して再構成する',
        'cta': '読者の行動につながる導線とCTAを意識して再構成する',
    }
    tone_map = {
        'natural': '自然で読みやすい文体',
        'trust': '丁寧で信頼感のある文体',
        'friendly': '親しみやすい文体',
        'seo': 'SEOを意識した明確な文体',
        'concise': '簡潔で要点重視の文体',
    }
    decoration_map = {
        'none': '装飾は追加せず、基本的なHTMLタグだけを使う',
        'light': '重要箇所に軽くボックスやリストを入れる',
        'standard': '見出し、ボックス、リスト、CTAを標準的に整える',
        'rich': '比較表、注意ボックス、まとめ、CTAなどをしっかり使う',
    }

    prompt = f"""
リライト方針:
- {structure_map.get(structure_mode, structure_map['seo'])}
- {tone_map.get(tone, tone_map['natural'])}
- {decoration_map.get(decoration_level, decoration_map['standard'])}
- 元記事の事実関係、固有名詞、重要な主張は保持する
- 重複表現、冗長な表現、読みにくい段落を整理する
- WordPress本文として使えるHTML形式で出力する

{article_html_output_rules()}"""

    if target_chars:
        try:
            target = int(target_chars)
            lower = max(1, int(target * (100 - tolerance) / 100))
            upper = int(target * (100 + tolerance) / 100)
            prompt += f"""

文字数条件:
- 目標文字数: {target}文字
- 許容範囲: ±{tolerance}%
- {lower}文字から{upper}文字の範囲を目安にする
- 文字数を優先しすぎて不自然な言い回しにしない"""
        except ValueError:
            pass

    decoration_id = data.get('decoration_id')
    decoration = next((d for d in load_decorations() if d['id'] == decoration_id), None) if decoration_id else None
    if decoration and decoration.get('sample_html'):
        prompt += decoration_reference_prompt(decoration.get('sample_html'), limit=5000)
    elif safe_article_css(settings.get('article_css')):
        prompt += f"""

サイト共通CSS:
以下のCSSに合うHTML構造とクラス設計を意識してください。

{safe_article_css(settings.get('article_css'))[:3000]}"""

    return prompt

def save_settings(settings):
    save_json(SETTINGS_FILE, settings)

def login_required(f):
    @wraps(f)
    def decorated(*args, **kwargs):
        if not session.get('authenticated'):
            if request.is_json or request.headers.get('Accept') == 'text/event-stream':
                return jsonify({'error': '認証が必要です'}), 401
            return redirect(url_for('login_page'))
        return f(*args, **kwargs)
    return decorated


@app.route('/')
@app.route('/ranking')
@app.route('/brand')
@app.route('/column')
@app.route('/batch')
@app.route('/rewrite')
@app.route('/history')
@app.route('/articles')
@app.route('/quality')
@app.route('/decoration')
@app.route('/ads')
@app.route('/sites')
@app.route('/api-settings')
@app.route('/settings')
def index():
    if not session.get('authenticated'):
        return redirect(url_for('login_page'))
    return render_template('index.html')

@app.route('/favicon.ico')
def favicon():
    return send_from_directory(app.static_folder, 'favicon.svg', mimetype='image/svg+xml')

@app.route('/login', methods=['GET'])
def login_page():
    if session.get('authenticated'):
        return redirect(url_for('index'))
    return render_template('login.html')

@app.route('/login', methods=['POST'])
def login():
    password = request.json.get('password', '')
    app_password = os.environ.get('APP_PASSWORD', 'admin')
    if password == app_password:
        session['authenticated'] = True
        return jsonify({'success': True})
    return jsonify({'success': False, 'error': 'パスワードが違います'}), 401

@app.route('/logout')
def logout():
    session.clear()
    return redirect(url_for('login_page'))


# Articles
@app.route('/api/articles', methods=['GET'])
@login_required
def get_articles():
    articles = load_articles()
    if ensure_article_scores_current(articles):
        save_articles(articles)
    return jsonify(articles)


@app.route('/api/articles', methods=['POST'])
@login_required
def create_article():
    data = request.json or {}
    title = str(data.get('title') or '').strip()
    if not title:
        return jsonify({'error': 'タイトルを入力してください'}), 400
    article = {
        'id': str(uuid.uuid4()),
        'title': title,
        'keywords': data.get('keywords', ''),
        'category': data.get('category', ''),
        'slug': normalize_slug(data.get('slug')),
        'article_type': normalize_article_type(data.get('article_type'), 'ranking'),
        'ad_keywords': data.get('ad_keywords', ''),
        'priority': data.get('priority', ''),
        'schedule_date': data.get('schedule_date', ''),
        'memo': data.get('memo', ''),
        'status': 'pending',
        'content': data.get('content', ''),
        'created_at': datetime.now().isoformat(),
        'quality_id': data.get('quality_id') or None,
        'decoration_id': data.get('decoration_id') or None,
        'ad_definition_id': data.get('ad_definition_id') or None,
        'site_id': data.get('site_id') or None,
        'wp_post_id': None,
        'wp_url': None,
    }
    if article.get('content'):
        article['status'] = 'generated'
        article['generated_at'] = datetime.now().isoformat()
        apply_score_fields(article)
    articles = load_articles()
    articles.append(article)
    save_articles(articles)
    return jsonify(article)


@app.route('/api/articles/<article_id>', methods=['GET'])
@login_required
def get_article(article_id):
    article = next((a for a in load_articles() if a['id'] == article_id), None)
    if not article:
        return jsonify({'error': '記事が見つかりません'}), 404
    return jsonify(article)

@app.route('/api/articles/<article_id>', methods=['PUT'])
@login_required
def update_article(article_id):
    data = request.json
    articles = load_articles()
    for a in articles:
        if a['id'] == article_id:
            for key in [
                'title', 'keywords', 'content', 'article_type', 'ad_keywords',
                'category', 'priority', 'memo', 'schedule_date', 'quality_id',
                'decoration_id', 'ad_definition_id', 'scheduled_at'
            ]:
                if key in data:
                    a[key] = data[key]
            if 'slug' in data:
                a['slug'] = normalize_slug(data.get('slug'))
            if 'article_type' in data:
                a['article_type'] = normalize_article_type(data.get('article_type'), a.get('article_type', 'ranking'))
            if 'content' in data:
                apply_score_fields(a)
            break
    save_articles(articles)
    return jsonify({'success': True})

@app.route('/api/articles/<article_id>', methods=['DELETE'])
@login_required
def delete_article(article_id):
    articles = [a for a in load_articles() if a['id'] != article_id]
    save_articles(articles)
    return jsonify({'success': True})

@app.route('/api/articles/bulk-delete', methods=['POST'])
@login_required
def bulk_delete():
    ids = set(request.json.get('ids', []))
    articles = [a for a in load_articles() if a['id'] not in ids]
    save_articles(articles)
    return jsonify({'success': True})


@app.route('/api/articles/score', methods=['POST'])
@login_required
def score_articles():
    articles = load_articles()
    for article in articles:
        if article.get('content'):
            apply_score_fields(article)
    save_articles(articles)
    return jsonify({'success': True, 'scored': sum(1 for a in articles if a.get('content'))})


@app.route('/api/batch-jobs/latest', methods=['GET'])
@login_required
def get_latest_batch_job():
    jobs = load_batch_jobs()
    return jsonify(jobs[0] if jobs else None)


# Import
@app.route('/api/import', methods=['POST'])
@login_required
def import_excel():
    if 'file' not in request.files:
        return jsonify({'error': 'ファイルがありません'}), 400
    file = request.files['file']
    filename = (file.filename or '').lower()
    if not filename.endswith(('.xlsx', '.xls', '.csv')):
        return jsonify({'error': 'CSV/Excelファイル(.csv/.xlsx/.xls)を選択してください'}), 400

    if filename.endswith('.csv'):
        raw = file.read()
        for encoding in ('utf-8-sig', 'cp932'):
            try:
                text = raw.decode(encoding)
                break
            except UnicodeDecodeError:
                text = ''
        rows = list(csv.reader(io.StringIO(text)))
    else:
        wb = openpyxl.load_workbook(file, data_only=True)
        ws = wb.active
        rows = list(ws.iter_rows(values_only=True))
    articles = load_articles()
    imported = 0

    def norm_header(value):
        return re.sub(r'[\s_＿・（）()\[\]【】]+', '', str(value or '').strip().lower())

    aliases = {
        'title': {'title', 'タイトル', '記事タイトル', '記事名'},
        'keywords': {'keyword', 'keywords', 'キーワード', 'seoキーワード', '検索キーワード'},
        'category': {'category', 'categories', 'カテゴリ', 'カテゴリー', 'wpカテゴリー', '投稿カテゴリー'},
        'slug': {'slug', 'スラッグ', 'urlスラッグ', '投稿スラッグ', 'post_name'},
        'article_type': {'type', 'article_type', '記事種類', '記事種別', '種類', '種別'},
        'site': {'site', 'サイト', '投稿先', '投稿先サイト', 'site_id', 'サイトid'},
        'quality': {'quality', '品質', '品質定義', 'quality_id', '品質id'},
        'decoration': {'decoration', '装飾', '装飾定義', 'decoration_id', '装飾id'},
        'ad_definition': {'ad_definition', '広告定義', '広告', 'ad_definition_id', '広告id'},
        'ad_keywords': {'adkeyword', 'adkeywords', '広告キーワード', '商品キーワード', '広告検索語'},
        'priority': {'priority', '優先度'},
        'schedule_date': {'schedule', 'schedule_date', '予定日', '公開予定日', '執筆予定日'},
        'memo': {'memo', 'メモ', '備考'},
        'content': {'content', '本文', 'html', '記事本文'},
    }
    alias_lookup = {}
    for field, names in aliases.items():
        for name in names:
            alias_lookup[norm_header(name)] = field

    if not rows:
        return jsonify({'success': True, 'imported': 0})

    first = [norm_header(v) for v in rows[0]]
    has_headers = any(v in alias_lookup for v in first)
    header_map = {}
    if has_headers:
        for idx, header in enumerate(first):
            field = alias_lookup.get(header)
            if field and field not in header_map:
                header_map[field] = idx
        data_rows = rows[1:]
    else:
        header_map = {'title': 0, 'keywords': 1}
        data_rows = rows[1:]

    settings = load_settings()
    site_fallback = request.form.get('site_id') or None
    sites = settings.get('sites', [])
    quality_list = load_quality()
    decorations = load_decorations()
    ad_definitions = load_ad_definitions()

    def cell(row, field):
        idx = header_map.get(field)
        if idx is None or idx >= len(row) or row[idx] is None:
            return ''
        return str(row[idx]).strip()

    def resolve_id(value, items):
        if not value:
            return None
        needle = str(value).strip()
        return next((i.get('id') for i in items if i.get('id') == needle or i.get('name') == needle), None)

    for row in data_rows:
        title = cell(row, 'title')
        if not title:
            continue
        content = cell(row, 'content')
        article = {
            'id': str(uuid.uuid4()),
            'title': title,
            'keywords': cell(row, 'keywords'),
            'category': cell(row, 'category'),
            'slug': normalize_slug(cell(row, 'slug')),
            'article_type': normalize_article_type(cell(row, 'article_type'), 'ranking'),
            'ad_keywords': cell(row, 'ad_keywords'),
            'priority': cell(row, 'priority'),
            'schedule_date': cell(row, 'schedule_date'),
            'memo': cell(row, 'memo'),
            'status': 'pending',
            'content': content,
            'created_at': datetime.now().isoformat(),
            'quality_id': resolve_id(cell(row, 'quality'), quality_list),
            'decoration_id': resolve_id(cell(row, 'decoration'), decorations),
            'ad_definition_id': resolve_id(cell(row, 'ad_definition'), ad_definitions),
            'site_id': resolve_id(cell(row, 'site'), sites) or site_fallback,
            'wp_post_id': None,
            'wp_url': None,
        }
        if content:
            article['status'] = 'generated'
            article['generated_at'] = datetime.now().isoformat()
            apply_score_fields(article)
        articles.append(article)
        imported += 1

    save_articles(articles)
    return jsonify({'success': True, 'imported': imported, 'columns': list(header_map.keys())})


# Generate (SSE)
@app.route('/api/generate/<article_id>', methods=['POST'])
@login_required
def generate_article(article_id):
    articles = load_articles()
    article = next((a for a in articles if a['id'] == article_id), None)
    if not article:
        return jsonify({'error': '記事が見つかりません'}), 404

    data = request.json or {}
    quality_id = data.get('quality_id') or article.get('quality_id')
    settings = load_settings()
    api_key = settings.get('claude_api_key') or os.environ.get('ANTHROPIC_API_KEY', '')

    if not api_key:
        return jsonify({'error': 'Claude APIキーが設定されていません'}), 400

    article_work = dict(article)
    for key in ('title', 'keywords', 'category', 'ad_keywords', 'site_id', 'slug'):
        if key in data:
            article_work[key] = data.get(key) or ''
    article_type = normalize_article_type(data.get('article_type') or article_work.get('article_type'), 'ranking')
    article_work['article_type'] = article_type
    now = datetime.now().isoformat()
    generation_run_id = str(uuid.uuid4())
    previous_content_hash = content_hash(article.get('content', ''))
    for a in articles:
        if a['id'] == article_id:
            for key in ('title', 'keywords', 'category', 'ad_keywords', 'site_id', 'slug'):
                a[key] = article_work.get(key, '')
            a['article_type'] = article_type
            if quality_id:
                a['quality_id'] = quality_id
            if data.get('decoration_id'):
                a['decoration_id'] = data.get('decoration_id')
            if data.get('ad_definition_id'):
                a['ad_definition_id'] = data.get('ad_definition_id')
            a['status'] = 'generating'
            a['generation_run_id'] = generation_run_id
            a['generation_started_at'] = now
            a['updated_at'] = now
            a.pop('error', None)
            break
    save_articles(articles)
    quality_list = load_quality()
    quality = select_quality_definition(quality_list, quality_id, article_type)
    quality_prompt = build_quality_prompt(quality)
    article_type_prompt = build_article_type_prompt(article_type)
    ranking_count_prompt = build_ranking_count_prompt(article_work, article_type)
    reference_text = ''
    style_reference_url = ''
    style_reference_text = ''
    if quality and quality.get('reference_url'):
        try:
            reference_text = fetch_url_text(quality['reference_url'])
        except Exception:
            pass
    try:
        style_reference_url, style_reference_text = fetch_quality_style_reference(article_type, settings)
    except Exception:
        style_reference_text = ''
    include_amazon = data.get('include_amazon', False)
    include_rakuten = data.get('include_rakuten', False)
    decoration_id = data.get('decoration_id')
    decoration = next((d for d in load_decorations() if d['id'] == decoration_id), None) if decoration_id else None
    rakuten_asp_instruction = build_rakuten_asp_instruction(article_work, settings)
    ad_definition = select_ad_definition({**data, 'article_type': article_type}, article_work)
    try:
        product_blocks, ad_instruction = build_ad_product_blocks(
            article_work, settings, ad_definition, include_amazon=include_amazon, include_rakuten=include_rakuten
        )
    except Exception as e:
        current_articles = load_articles()
        for a in current_articles:
            if a['id'] == article_id:
                a['status'] = 'error'
                a['error'] = str(e)
                a['updated_at'] = datetime.now().isoformat()
                a['generation_finished_at'] = a['updated_at']
                break
        save_articles(current_articles)
        return jsonify({'error': str(e)}), 500

    def generate():
        full_content = ''
        try:
            yield f"data: {json.dumps({'status': 'started', 'run_id': generation_run_id})}\n\n"
            client = anthropic.Anthropic(api_key=api_key)
            prompt = f"""以下の情報をもとに、WordPressに投稿する記事を書いてください。

タイトル: {article_work.get('title', '')}
キーワード: {article_work.get('keywords', '')}
カテゴリー: {article_work.get('category', '')}

品質要件:
{quality_prompt}

{article_type_prompt}
{ranking_count_prompt}

{article_html_output_rules()}"""

            if reference_text:
                prompt += f'\n\n以下の参考記事の内容・構成・論点を参考にして執筆してください（コピーは不可）：\n\n{reference_text}'

            if style_reference_text:
                prompt += f'''\n\n記事品質の書き方参考:
- 参考URL: {style_reference_url}
- この参考記事は内容・事実・固有名詞を流用するためではありません。
- 文章構成、導入の作り方、権威性の示し方、根拠の置き方、説得力の作り方、CTAまでの流れだけを参考にしてください。
- テーマや読者に合わない表現は使わず、今回の記事内容に自然に合わせてください。

参考記事テキスト:
{style_reference_text[:5000]}'''

            if decoration and decoration.get('sample_html'):
                prompt += decoration_reference_prompt(decoration.get('sample_html'), limit=4000)

            if rakuten_asp_instruction:
                prompt += rakuten_asp_instruction

            if ad_instruction:
                prompt += ad_instruction

            if product_blocks:
                prompt += '\n\n以下の商品カード（HTML）を記事の適切な箇所に自然に組み込んでください。HTMLはそのまま使用してください：\n'
                for block in product_blocks:
                    prompt += f'\n{block}\n'

            with client.messages.stream(
                model=CLAUDE_ARTICLE_MODEL,
                max_tokens=8192,
                messages=[{"role": "user", "content": prompt}]
            ) as stream:
                for text in stream.text_stream:
                    full_content += text
                    yield f"data: {json.dumps({'text': text})}\n\n"
                try:
                    final_message = stream.get_final_message()
                except Exception:
                    final_message = None

            current_articles = load_articles()
            for a in current_articles:
                if a['id'] == article_id:
                    clean_content = sanitize_generated_html(full_content)
                    validation_error = validate_generated_article(article_work, article_type, clean_content)
                    content_chars = len(html_to_text(clean_content))
                    if not validation_error and content_chars < 500:
                        validation_error = f'生成結果が短すぎます（{content_chars}文字）。Claude生成が途中で止まった可能性があります。もう一度生成してください。'
                    if validation_error:
                        a['status'] = 'error'
                        a['error'] = validation_error
                        a['updated_at'] = datetime.now().isoformat()
                        a['generation_finished_at'] = a['updated_at']
                        save_articles(current_articles)
                        yield f"data: {json.dumps({'error': validation_error})}\n\n"
                        return
                    generated_at = datetime.now().isoformat()
                    new_content_hash = content_hash(clean_content)
                    changed = new_content_hash != previous_content_hash
                    a['content'] = clean_content
                    a['status'] = 'generated'
                    a['title'] = article_work.get('title', a.get('title', ''))
                    a['keywords'] = article_work.get('keywords', a.get('keywords', ''))
                    a['category'] = article_work.get('category', a.get('category', ''))
                    a['slug'] = normalize_slug(article_work.get('slug', a.get('slug', '')))
                    a['ad_keywords'] = article_work.get('ad_keywords', a.get('ad_keywords', ''))
                    a['site_id'] = article_work.get('site_id') or a.get('site_id')
                    a['quality_id'] = quality.get('id') if quality else quality_id
                    a['article_type'] = article_type
                    a['decoration_id'] = decoration_id or a.get('decoration_id')
                    if ad_definition:
                        a['ad_definition_id'] = ad_definition.get('id')
                    a['generated_at'] = generated_at
                    a['updated_at'] = generated_at
                    a['content_hash'] = new_content_hash
                    a['generation_finished_at'] = generated_at
                    a['last_generation_changed'] = changed
                    a['last_generation_chars'] = content_chars
                    usage = build_article_usage(prompt, clean_content, final_message)
                    append_generation_usage(a, usage, generation_run_id, generated_at, clean_content)
                    apply_score_fields(a)
                    break
            save_articles(current_articles)
            yield f"data: {json.dumps({'done': True, 'run_id': generation_run_id, 'content_chars': content_chars, 'changed': changed, 'usage': usage})}\n\n"
        except Exception as e:
            current_articles = load_articles()
            for a in current_articles:
                if a['id'] == article_id:
                    a['status'] = 'error'
                    a['error'] = str(e)
                    a['updated_at'] = datetime.now().isoformat()
                    a['generation_finished_at'] = a['updated_at']
                    break
            save_articles(current_articles)
            yield f"data: {json.dumps({'error': str(e)})}\n\n"

    return Response(
        stream_with_context(generate()),
        mimetype='text/event-stream',
        headers={'Cache-Control': 'no-cache', 'X-Accel-Buffering': 'no'}
    )


# Batch generate
@app.route('/api/batch-generate', methods=['POST'])
@login_required
def batch_generate():
    data = request.json or {}
    requested_ids = list(dict.fromkeys(data.get('article_ids', [])))
    article_ids = set(requested_ids)
    quality_id = data.get('quality_id')

    articles = load_articles()
    article_lookup = {a['id']: a for a in articles}
    pending = [article_lookup[i] for i in requested_ids if i in article_lookup and article_lookup[i].get('status') in ('pending', 'error')]

    if not pending:
        return jsonify({'error': '処理対象の記事がありません'}), 400

    settings = load_settings()
    api_key = settings.get('claude_api_key') or os.environ.get('ANTHROPIC_API_KEY', '')

    if not api_key:
        return jsonify({'error': 'Claude APIキーが設定されていません'}), 400

    quality_list = load_quality()
    batch_article_type = normalize_article_type(data.get('article_type'), 'ranking')
    quality = select_quality_definition(quality_list, quality_id, batch_article_type)
    quality_prompt = build_quality_prompt(quality)
    reference_text = ''
    if quality and quality.get('reference_url'):
        try:
            reference_text = fetch_url_text(quality['reference_url'])
        except Exception:
            pass
    style_reference_cache = {}
    include_amazon = data.get('include_amazon', False)
    include_rakuten = data.get('include_rakuten', False)
    decoration_id = data.get('decoration_id')
    decoration = next((d for d in load_decorations() if d['id'] == decoration_id), None) if decoration_id else None
    now = datetime.now().isoformat()
    job_id = str(uuid.uuid4())
    job = {
        'id': job_id,
        'type': 'generate',
        'status': 'running',
        'total': len(pending),
        'completed': 0,
        'failed': 0,
        'current_title': '',
        'article_ids': [a['id'] for a in pending],
        'started_at': now,
        'updated_at': now,
        'message': '一括生成を開始しました。ページを移動しても処理は継続します。',
    }
    jobs = load_batch_jobs()
    jobs.insert(0, job)
    save_batch_jobs(jobs)
    for a in articles:
        if a['id'] in job['article_ids']:
            a['status'] = 'generating'
            a['batch_job_id'] = job_id
            a.pop('error', None)
    save_articles(articles)

    def update_job(**changes):
        jobs = load_batch_jobs()
        for item in jobs:
            if item.get('id') == job_id:
                item.update(changes)
                item['updated_at'] = datetime.now().isoformat()
                break
        save_batch_jobs(jobs)

    def run_batch():
        client = anthropic.Anthropic(api_key=api_key)
        completed = 0
        failed = 0
        for article in pending:
            try:
                update_job(current_title=article.get('title', ''), message=f"生成中: {article.get('title', '')}")
                article_type = normalize_article_type(article.get('article_type') or batch_article_type, batch_article_type)
                article_type_prompt = build_article_type_prompt(article_type)
                ranking_count_prompt = build_ranking_count_prompt(article, article_type)
                style_reference_url, style_reference_text = style_reference_cache.get(article_type, ('', ''))
                if article_type not in style_reference_cache:
                    try:
                        style_reference_url, style_reference_text = fetch_quality_style_reference(article_type, settings)
                    except Exception:
                        style_reference_url, style_reference_text = '', ''
                    style_reference_cache[article_type] = (style_reference_url, style_reference_text)
                prompt = f"""以下の情報をもとに、WordPressに投稿する記事を書いてください。

タイトル: {article['title']}
キーワード: {article['keywords']}
カテゴリー: {article.get('category', '')}

品質要件:
{quality_prompt}

{article_type_prompt}
{ranking_count_prompt}

{article_html_output_rules()}"""

                if reference_text:
                    prompt += f'\n\n以下の参考記事の内容・構成・論点を参考にして執筆してください（コピーは不可）：\n\n{reference_text}'

                if style_reference_text:
                    prompt += f'''\n\n記事品質の書き方参考:
- 参考URL: {style_reference_url}
- この参考記事は内容・事実・固有名詞を流用するためではありません。
- 文章構成、導入の作り方、権威性の示し方、根拠の置き方、説得力の作り方、CTAまでの流れだけを参考にしてください。
- テーマや読者に合わない表現は使わず、今回の記事内容に自然に合わせてください。

参考記事テキスト:
{style_reference_text[:5000]}'''

                if decoration and decoration.get('sample_html'):
                    prompt += decoration_reference_prompt(decoration.get('sample_html'), limit=4000)

                rakuten_asp_instruction = build_rakuten_asp_instruction(article, settings)
                if rakuten_asp_instruction:
                    prompt += rakuten_asp_instruction

                ad_definition = select_ad_definition({**data, 'article_type': article_type}, article)
                product_blocks, ad_instruction = build_ad_product_blocks(
                    article, settings, ad_definition, include_amazon=include_amazon, include_rakuten=include_rakuten
                )
                if ad_instruction:
                    prompt += ad_instruction
                if product_blocks:
                    prompt += '\n\n以下の商品カード（HTML）を記事の適切な箇所に自然に組み込んでください。HTMLはそのまま使用してください：\n'
                    for block in product_blocks:
                        prompt += f'\n{block}\n'

                message = client.messages.create(
                    model=CLAUDE_ARTICLE_MODEL,
                    max_tokens=8192,
                    messages=[{"role": "user", "content": prompt}]
                )
                content = sanitize_generated_html(message.content[0].text)
                validation_error = validate_generated_article(article, article_type, content)
                content_chars = len(html_to_text(content))
                if not validation_error and content_chars < 500:
                    validation_error = f'生成結果が短すぎます（{content_chars}文字）。Claude生成が途中で止まった可能性があります。もう一度生成してください。'
                if validation_error:
                    raise ValueError(validation_error)
                generated_at = datetime.now().isoformat()
                run_id = str(uuid.uuid4())

                current_articles = load_articles()
                for a in current_articles:
                    if a['id'] == article['id']:
                        a['content'] = content
                        a['status'] = 'generated'
                        a.pop('batch_job_id', None)
                        a['quality_id'] = quality.get('id') if quality else quality_id
                        a['article_type'] = article_type
                        a['decoration_id'] = decoration_id or a.get('decoration_id')
                        if ad_definition:
                            a['ad_definition_id'] = ad_definition.get('id')
                        a['generated_at'] = generated_at
                        a['updated_at'] = generated_at
                        a['content_hash'] = content_hash(content)
                        usage = build_article_usage(prompt, content, message)
                        append_generation_usage(a, usage, run_id, generated_at, content)
                        apply_score_fields(a)
                        break
                save_articles(current_articles)
                completed += 1
                update_job(completed=completed, failed=failed, message=f"{completed}/{len(pending)}件生成済み")
            except Exception as e:
                current_articles = load_articles()
                for a in current_articles:
                    if a['id'] == article['id']:
                        a['status'] = 'error'
                        a['error'] = str(e)
                        a.pop('batch_job_id', None)
                        break
                save_articles(current_articles)
                failed += 1
                update_job(completed=completed, failed=failed, message=f"{completed}件生成済み / {failed}件エラー")
        final_status = 'completed' if failed == 0 else 'completed_with_errors'
        update_job(
            status=final_status,
            current_title='',
            completed=completed,
            failed=failed,
            completed_at=datetime.now().isoformat(),
            message=f"一括生成完了: 成功 {completed}件 / エラー {failed}件"
        )

    thread = threading.Thread(target=run_batch, daemon=True)
    thread.start()
    return jsonify({'success': True, 'job_id': job_id, 'message': f'{len(pending)}件の記事生成を開始しました'})


# WordPress publish
def update_wordpress_post_from_article(article, settings):
    if not article.get('content'):
        raise ValueError('記事コンテンツがありません。先に生成してください。')
    if not article.get('wp_post_id'):
        raise ValueError('既存のWordPress投稿IDがありません。先にWP投稿してください。')

    wp_url, wp_user, wp_password = get_site_credentials(article, settings)
    if not all([wp_url, wp_user, wp_password]):
        raise ValueError('サイトが設定されていません。記事にサイトを紐付けてください。')

    clean_content = sanitize_generated_html(article.get('content', ''))
    post_payload = {
        'title': article.get('title', ''),
        'content': prepare_article_content_for_publish(clean_content, settings),
    }
    slug = normalize_slug(article.get('slug'))
    if slug:
        post_payload['slug'] = slug
    category_ids = resolve_wp_category_ids(wp_url, wp_user, wp_password, article.get('category', ''))
    if category_ids:
        post_payload['categories'] = category_ids

    response = requests.post(
        f"{wp_url}/wp-json/wp/v2/posts/{article['wp_post_id']}",
        auth=(wp_user, wp_password),
        json=post_payload,
        timeout=30
    )
    response.raise_for_status()
    return response.json(), clean_content


@app.route('/api/publish/<article_id>', methods=['POST'])
@login_required
def publish_article(article_id):
    articles = load_articles()
    article = next((a for a in articles if a['id'] == article_id), None)
    if not article:
        return jsonify({'error': '記事が見つかりません'}), 404
    if not article.get('content'):
        return jsonify({'error': '記事コンテンツがありません。先に生成してください。'}), 400

    settings = load_settings()
    wp_url, wp_user, wp_password = get_site_credentials(article, settings)

    if not all([wp_url, wp_user, wp_password]):
        return jsonify({'error': 'サイトが設定されていません。記事にサイトを紐付けてください。'}), 400

    data = request.json or {}
    post_status = data.get('post_status', 'draft')
    content = prepare_article_content_for_publish(article['content'], settings)
    post_payload = {'title': article['title'], 'content': content, 'status': post_status}
    slug = normalize_slug(article.get('slug'))
    if slug:
        post_payload['slug'] = slug
    if data.get('scheduled_at'):
        scheduled_at = str(data.get('scheduled_at')).strip()
        post_payload['date'] = scheduled_at
        post_payload['status'] = 'future'
    category_ids = resolve_wp_category_ids(wp_url, wp_user, wp_password, article.get('category', ''))
    if category_ids:
        post_payload['categories'] = category_ids

    try:
        response = requests.post(
            f"{wp_url}/wp-json/wp/v2/posts",
            auth=(wp_user, wp_password),
            json=post_payload,
            timeout=30
        )
        response.raise_for_status()
        post_data = response.json()

        for a in articles:
            if a['id'] == article_id:
                a['status'] = 'published'
                a['wp_post_id'] = post_data['id']
                a['wp_url'] = post_data.get('link', '')
                a['published_at'] = datetime.now().isoformat()
                if data.get('scheduled_at'):
                    a['status'] = 'scheduled'
                    a['scheduled_at'] = data.get('scheduled_at')
                    a['schedule_date'] = str(data.get('scheduled_at'))[:10]
                break
        save_articles(articles)
        return jsonify({'success': True, 'wp_url': post_data.get('link', ''), 'wp_post_id': post_data['id']})
    except requests.exceptions.RequestException as e:
        return jsonify({'error': f'WordPress投稿エラー: {str(e)}'}), 500


@app.route('/api/articles/<article_id>/repair-post', methods=['POST'])
@login_required
def repair_article_post(article_id):
    articles = load_articles()
    article = next((a for a in articles if a['id'] == article_id), None)
    if not article:
        return jsonify({'error': '記事が見つかりません'}), 404

    settings = load_settings()
    try:
        post_data, clean_content = update_wordpress_post_from_article(article, settings)
        for a in articles:
            if a['id'] == article_id:
                a['content'] = clean_content
                if a.get('status') != 'scheduled':
                    a['status'] = 'published'
                a['wp_url'] = post_data.get('link', a.get('wp_url', ''))
                a['repaired_at'] = datetime.now().isoformat()
                a['updated_at'] = datetime.now().isoformat()
                apply_score_fields(a)
                break
        save_articles(articles)
        return jsonify({
            'success': True,
            'wp_url': post_data.get('link', article.get('wp_url', '')),
            'content_chars': len(html_to_text(clean_content)),
            'content_hash': content_hash(clean_content),
        })
    except ValueError as e:
        return jsonify({'error': str(e)}), 400
    except requests.exceptions.RequestException as e:
        return jsonify({'error': f'WordPress上書き更新エラー: {str(e)}'}), 500


@app.route('/api/articles/bulk-repair-posts', methods=['POST'])
@login_required
def bulk_repair_article_posts():
    ids = set((request.json or {}).get('ids', []))
    articles = load_articles()
    settings = load_settings()
    results = {'success': 0, 'error': 0, 'errors': []}
    now = datetime.now().isoformat()

    for article in articles:
        if article.get('id') not in ids:
            continue
        try:
            post_data, clean_content = update_wordpress_post_from_article(article, settings)
            article['content'] = clean_content
            if article.get('status') != 'scheduled':
                article['status'] = 'published'
            article['wp_url'] = post_data.get('link', article.get('wp_url', ''))
            article['repaired_at'] = now
            article['updated_at'] = now
            apply_score_fields(article)
            results['success'] += 1
        except Exception as e:
            results['error'] += 1
            results['errors'].append({'title': article.get('title', ''), 'error': str(e)})

    save_articles(articles)
    return jsonify(results)


# Batch publish
@app.route('/api/batch-publish', methods=['POST'])
@login_required
def batch_publish():
    data = request.json or {}
    article_ids = data.get('article_ids', [])
    post_status = data.get('post_status', 'draft')
    schedule_enabled = bool(data.get('schedule_enabled'))
    schedule_data = data.get('schedule') or {}

    settings = load_settings()
    articles = load_articles()
    article_lookup = {a['id']: a for a in articles}
    targets = [article_lookup[i] for i in article_ids if i in article_lookup and article_lookup[i].get('content')]
    if post_status == 'publish' and not schedule_enabled and len(targets) > 20:
        return jsonify({'error': '即時公開は1日20件までです。21件以上は予約投稿を有効にしてください。'}), 400

    results = {'success': 0, 'error': 0, 'errors': []}
    daily_limit = clamp_int(schedule_data.get('daily_limit'), 20, 1, 20)
    schedule_start_key = normalize_schedule_date_key(schedule_data.get('start_date'), (datetime.now() + timedelta(days=1)).strftime('%Y-%m-%d'))
    schedule_day_counts = {}
    for index, article in enumerate(targets):
        wp_url, wp_user, wp_password = get_site_credentials(article, settings)
        if not all([wp_url, wp_user, wp_password]):
            results['error'] += 1
            results['errors'].append({'title': article['title'], 'error': 'サイト未設定'})
            continue
        content = prepare_article_content_for_publish(article['content'], settings)
        scheduled_at = None
        payload_status = post_status
        if schedule_enabled:
            date_key = normalize_schedule_date_key(article.get('schedule_date'), schedule_start_key)
            while schedule_day_counts.get(date_key, 0) >= daily_limit:
                date_key = (datetime.strptime(date_key, '%Y-%m-%d') + timedelta(days=1)).strftime('%Y-%m-%d')
            slot_index = schedule_day_counts.get(date_key, 0)
            schedule_day_counts[date_key] = slot_index + 1
            scheduled_dt = build_schedule_datetime(index, schedule_data, date_override=date_key, slot_override=slot_index)
            scheduled_at = scheduled_dt.strftime('%Y-%m-%dT%H:%M:%S')
            payload_status = 'future'
        post_payload = {'title': article['title'], 'content': content, 'status': payload_status}
        slug = normalize_slug(article.get('slug'))
        if slug:
            post_payload['slug'] = slug
        if scheduled_at:
            post_payload['date'] = scheduled_at
        category_ids = resolve_wp_category_ids(wp_url, wp_user, wp_password, article.get('category', ''))
        if category_ids:
            post_payload['categories'] = category_ids
        try:
            response = requests.post(
                f"{wp_url}/wp-json/wp/v2/posts",
                auth=(wp_user, wp_password),
                json=post_payload,
                timeout=30
            )
            response.raise_for_status()
            post_data = response.json()
            for a in articles:
                if a['id'] == article['id']:
                    a['status'] = 'scheduled' if scheduled_at else 'published'
                    a['wp_post_id'] = post_data['id']
                    a['wp_url'] = post_data.get('link', '')
                    a['published_at'] = datetime.now().isoformat()
                    if scheduled_at:
                        a['scheduled_at'] = scheduled_at
                        a['schedule_date'] = scheduled_at[:10]
                    break
            results['success'] += 1
        except Exception as e:
            results['error'] += 1
            results['errors'].append({'title': article['title'], 'error': str(e)})

    save_articles(articles)
    return jsonify(results)


# Rewrite existing WordPress posts
@app.route('/api/rewrite/items', methods=['GET'])
@login_required
def get_rewrite_items():
    return jsonify(load_rewrites())


@app.route('/api/rewrite/fetch', methods=['POST'])
@login_required
def fetch_rewrite_items():
    data = request.json or {}
    site_id = data.get('site_id')
    per_page = int(data.get('per_page', 20) or 20)
    max_pages = int(data.get('max_pages', 3) or 3)
    statuses = data.get('statuses') or ['publish']
    per_page = max(1, min(per_page, 100))
    max_pages = max(1, min(max_pages, 10))

    settings = load_settings()
    site = get_site_by_id(site_id, settings)
    if not site:
        return jsonify({'error': 'サイトを選択してください'}), 400

    wp_url = site['wp_url'].rstrip('/')
    fetched = []
    category_cache = {}

    def post_category_names(ids):
        names = []
        missing = [cid for cid in (ids or []) if cid not in category_cache]
        if missing:
            try:
                cat_resp = requests.get(
                    f"{wp_url}/wp-json/wp/v2/categories",
                    auth=(site['wp_user'], site['wp_password']),
                    params={'include': ','.join(str(cid) for cid in missing), 'per_page': 100},
                    timeout=15
                )
                cat_resp.raise_for_status()
                for cat in cat_resp.json():
                    category_cache[cat.get('id')] = cat.get('name', '')
            except Exception:
                pass
        for cid in (ids or []):
            if category_cache.get(cid):
                names.append(category_cache[cid])
        return ', '.join(names)

    try:
        for status in statuses:
            for page in range(1, max_pages + 1):
                resp = requests.get(
                    f"{wp_url}/wp-json/wp/v2/posts",
                    auth=(site['wp_user'], site['wp_password']),
                    params={
                        'per_page': per_page,
                        'page': page,
                        'status': status,
                        'orderby': 'date',
                        'order': 'desc',
                    },
                    timeout=20
                )
                if resp.status_code == 400 and page > 1:
                    break
                resp.raise_for_status()
                posts = resp.json()
                if not posts:
                    break
                for post in posts:
                    item = {
                        'id': f"{site_id}:{post.get('id')}",
                        'site_id': site_id,
                        'site_name': site.get('name', ''),
                        'wp_post_id': post.get('id'),
                        'title': unescape(post.get('title', {}).get('rendered', '') or ''),
                        'content': post.get('content', {}).get('rendered', '') or '',
                        'category': post_category_names(post.get('categories', [])),
                        'original_status': post.get('status', ''),
                        'article_type': 'rewrite',
                        'post_date': post.get('date', ''),
                        'modified_at': post.get('modified', ''),
                        'link': post.get('link', ''),
                        'status': 'fetched',
                        'rewritten_content': '',
                        'fetched_at': datetime.now().isoformat(),
                        'rewritten_at': None,
                        'updated_at': None,
                    }
                    apply_score_fields(item, item['title'], item['content'], '')
                    fetched.append(item)
    except Exception as e:
        return jsonify({'error': f'WordPress記事取得エラー: {str(e)}'}), 500

    existing = {item['id']: item for item in load_rewrites()}
    for item in fetched:
        if item['id'] in existing and existing[item['id']].get('rewritten_content'):
            item['rewritten_content'] = existing[item['id']].get('rewritten_content', '')
            item['status'] = existing[item['id']].get('status', item['status'])
            item['rewritten_at'] = existing[item['id']].get('rewritten_at')
            item['updated_at'] = existing[item['id']].get('updated_at')
            item['rewritten_score_data'] = existing[item['id']].get('rewritten_score_data')
        existing[item['id']] = item
    items = list(existing.values())
    items.sort(key=lambda x: x.get('fetched_at', ''), reverse=True)
    save_rewrites(items)
    return jsonify({'success': True, 'fetched': len(fetched), 'total': len(items)})


@app.route('/api/rewrite/<path:item_id>', methods=['GET'])
@login_required
def get_rewrite_item(item_id):
    item = next((i for i in load_rewrites() if i['id'] == item_id), None)
    if not item:
        return jsonify({'error': 'リライト対象が見つかりません'}), 404
    return jsonify(item)


@app.route('/api/rewrite/<path:item_id>', methods=['POST'])
@login_required
def rewrite_item(item_id):
    items = load_rewrites()
    item = next((i for i in items if i['id'] == item_id), None)
    if not item:
        return jsonify({'error': 'リライト対象が見つかりません'}), 404

    data = request.json or {}
    settings = load_settings()
    api_key = settings.get('claude_api_key') or os.environ.get('ANTHROPIC_API_KEY', '')
    if not api_key:
        return jsonify({'error': 'Claude APIキーが設定されていません'}), 400

    style_prompt = get_rewrite_style_prompt(data, settings)

    def generate():
        client = anthropic.Anthropic(api_key=api_key)
        full_content = ''
        try:
            prompt = f"""以下のWordPress記事をリライトしてください。

タイトル:
{item.get('title', '')}

元記事HTML:
{item.get('content', '')[:30000]}

{style_prompt}"""

            with client.messages.stream(
                model="claude-sonnet-4-6",
                max_tokens=int(data.get('max_tokens', 4096) or 4096),
                messages=[{"role": "user", "content": prompt}]
            ) as stream:
                for text in stream.text_stream:
                    full_content += text
                    yield f"data: {json.dumps({'text': text})}\n\n"

            current_items = load_rewrites()
            for i in current_items:
                if i['id'] == item_id:
                    i['rewritten_content'] = full_content
                    i['status'] = 'rewritten'
                    i['rewritten_at'] = datetime.now().isoformat()
                    i['rewritten_score_data'] = score_article_content(i.get('title', ''), full_content, '')
                    break
            save_rewrites(current_items)
            yield f"data: {json.dumps({'done': True})}\n\n"
        except Exception as e:
            current_items = load_rewrites()
            for i in current_items:
                if i['id'] == item_id:
                    i['status'] = 'error'
                    i['error'] = str(e)
                    break
            save_rewrites(current_items)
            yield f"data: {json.dumps({'error': str(e)})}\n\n"

    return Response(
        stream_with_context(generate()),
        mimetype='text/event-stream',
        headers={'Cache-Control': 'no-cache', 'X-Accel-Buffering': 'no'}
    )


@app.route('/api/rewrite/<path:item_id>/update', methods=['POST'])
@login_required
def update_rewritten_post(item_id):
    items = load_rewrites()
    item = next((i for i in items if i['id'] == item_id), None)
    if not item:
        return jsonify({'error': 'リライト対象が見つかりません'}), 404
    data = request.json or {}
    content = data.get('content') or item.get('rewritten_content')
    if not content:
        return jsonify({'error': '先にリライトを実行してください'}), 400

    settings = load_settings()
    site = get_site_by_id(item.get('site_id'), settings)
    if not site:
        return jsonify({'error': 'サイト設定が見つかりません'}), 404

    try:
        publish_content = prepare_article_content_for_publish(content, settings)
        resp = requests.post(
            f"{site['wp_url'].rstrip('/')}/wp-json/wp/v2/posts/{item['wp_post_id']}",
            auth=(site['wp_user'], site['wp_password']),
            json={'content': publish_content},
            timeout=30
        )
        resp.raise_for_status()
        post = resp.json()
        for i in items:
            if i['id'] == item_id:
                i['status'] = 'updated'
                i['content'] = content
                i['link'] = post.get('link', i.get('link', ''))
                i['updated_at'] = datetime.now().isoformat()
                apply_score_fields(i, i.get('title', ''), content, '')
                break
        save_rewrites(items)
        return jsonify({'success': True, 'wp_url': post.get('link', '')})
    except Exception as e:
        return jsonify({'error': f'WordPress更新エラー: {str(e)}'}), 500


@app.route('/api/rewrite/bulk-delete', methods=['POST'])
@login_required
def delete_rewrite_items():
    ids = set((request.json or {}).get('ids', []))
    items = [i for i in load_rewrites() if i['id'] not in ids]
    save_rewrites(items)
    return jsonify({'success': True})


@app.route('/api/rewrite/score', methods=['POST'])
@login_required
def score_rewrite_items():
    items = load_rewrites()
    for item in items:
        apply_score_fields(item, item.get('title', ''), item.get('content', ''), '')
        if item.get('rewritten_content'):
            item['rewritten_score_data'] = score_article_content(item.get('title', ''), item.get('rewritten_content', ''), '')
    save_rewrites(items)
    return jsonify({'success': True, 'scored': len(items)})


@app.route('/api/seo-news', methods=['GET'])
@login_required
def get_seo_news():
    limit = clamp_int(request.args.get('limit'), 5, 1, 8)
    try:
        items = fetch_seo_news(limit)
        return jsonify({
            'success': True,
            'source': 'Google 検索セントラル ブログ',
            'feed_url': SEO_NEWS_PAGE_URL,
            'items': items,
            'fetched_at': datetime.now().isoformat()
        })
    except Exception as e:
        return jsonify({
            'success': False,
            'source': 'Google 検索セントラル ブログ',
            'feed_url': SEO_NEWS_PAGE_URL,
            'items': SEO_NEWS_FALLBACK[:limit],
            'error': str(e)[:160],
            'fetched_at': datetime.now().isoformat()
        })


# Ad definitions
@app.route('/api/ad-definitions', methods=['GET'])
@login_required
def get_ad_definitions():
    return jsonify(load_ad_definitions())


@app.route('/api/ad-definitions', methods=['POST'])
@login_required
def create_ad_definition():
    data = request.json or {}
    definitions = load_ad_definitions()
    item = {
        'id': str(uuid.uuid4()),
        'name': data.get('name', ''),
        'article_type': data.get('article_type', 'common'),
        'source': data.get('source', 'both'),
        'keyword_mode': data.get('keyword_mode', 'article_keywords'),
        'search_keywords': data.get('search_keywords', ''),
        'item_count': clamp_int(data.get('item_count'), 3, 1, 10),
        'layout': data.get('layout', 'rinker'),
        'insertion_position': data.get('insertion_position', 'auto'),
        'amazon_button_label': data.get('amazon_button_label', 'Amazonで見る'),
        'rakuten_button_label': data.get('rakuten_button_label', '楽天市場で見る'),
        'prompt': data.get('prompt', ''),
        'priority': clamp_int(data.get('priority'), 50, 1, 999),
        'enabled': bool(data.get('enabled', True)),
        'created_at': datetime.now().isoformat(),
        'updated_at': datetime.now().isoformat(),
    }
    definitions.append(item)
    save_ad_definitions(definitions)
    return jsonify(item)


@app.route('/api/ad-definitions/<ad_definition_id>', methods=['PUT'])
@login_required
def update_ad_definition(ad_definition_id):
    data = request.json or {}
    definitions = load_ad_definitions()
    for item in definitions:
        if item['id'] == ad_definition_id:
            for key in [
                'name', 'article_type', 'source', 'keyword_mode', 'search_keywords',
                'layout', 'insertion_position', 'amazon_button_label',
                'rakuten_button_label', 'prompt'
            ]:
                if key in data:
                    item[key] = data[key]
            if 'item_count' in data:
                item['item_count'] = clamp_int(data.get('item_count'), item.get('item_count', 3), 1, 10)
            if 'priority' in data:
                item['priority'] = clamp_int(data.get('priority'), item.get('priority', 50), 1, 999)
            if 'enabled' in data:
                item['enabled'] = bool(data.get('enabled'))
            item['updated_at'] = datetime.now().isoformat()
            break
    save_ad_definitions(definitions)
    return jsonify({'success': True})


@app.route('/api/ad-definitions/<ad_definition_id>', methods=['DELETE'])
@login_required
def delete_ad_definition(ad_definition_id):
    definitions = [d for d in load_ad_definitions() if d['id'] != ad_definition_id]
    save_ad_definitions(definitions)
    return jsonify({'success': True})


@app.route('/api/ad-definitions/<ad_definition_id>/preview', methods=['POST'])
@login_required
def preview_ad_definition(ad_definition_id):
    definitions = load_ad_definitions()
    ad_definition = next((d for d in definitions if d['id'] == ad_definition_id), None)
    if not ad_definition:
        return jsonify({'error': '広告定義が見つかりません'}), 404
    data = request.json or {}
    article = {
        'title': data.get('title', ''),
        'keywords': data.get('keywords', ''),
        'ad_keywords': data.get('ad_keywords', ''),
    }
    settings = load_settings()
    blocks, instruction = build_ad_product_blocks(article, settings, ad_definition)
    return jsonify({'html': '\n'.join(blocks), 'count': len(blocks), 'instruction': instruction})


# Decorations
@app.route('/api/decorations', methods=['GET'])
@login_required
def get_decorations():
    return jsonify(load_decorations())

@app.route('/api/decorations', methods=['POST'])
@login_required
def create_decoration():
    data = request.json
    decorations = load_decorations()
    d = {
        'id': str(uuid.uuid4()),
        'name': data.get('name', ''),
        'article_type': data.get('article_type', 'common'),
        'description': data.get('description', ''),
        'sample_html': data.get('sample_html', ''),
        'source_url': data.get('source_url', ''),
    }
    decorations.append(d)
    save_decorations(decorations)
    return jsonify(d)

@app.route('/api/decorations/<decoration_id>', methods=['PUT'])
@login_required
def update_decoration(decoration_id):
    data = request.json
    decorations = load_decorations()
    for d in decorations:
        if d['id'] == decoration_id:
            d['name'] = data.get('name', d['name'])
            d['article_type'] = data.get('article_type', d.get('article_type', 'common'))
            d['description'] = data.get('description', d.get('description', ''))
            d['sample_html'] = data.get('sample_html', d['sample_html'])
            d['source_url'] = data.get('source_url', d.get('source_url', ''))
            break
    save_decorations(decorations)
    return jsonify({'success': True})

@app.route('/api/decorations/<decoration_id>', methods=['DELETE'])
@login_required
def delete_decoration(decoration_id):
    decorations = [d for d in load_decorations() if d['id'] != decoration_id]
    save_decorations(decorations)
    return jsonify({'success': True})

@app.route('/api/decorations/fetch', methods=['POST'])
@login_required
def fetch_decoration():
    data = request.json or {}
    site_id = data.get('site_id')
    post_id = data.get('post_id')
    if not site_id or not post_id:
        return jsonify({'error': 'site_id と post_id は必須です'}), 400
    settings = load_settings()
    site = next((s for s in settings.get('sites', []) if s['id'] == site_id), None)
    if not site:
        return jsonify({'error': 'サイトが見つかりません'}), 404
    try:
        resp = requests.get(
            f"{site['wp_url'].rstrip('/')}/wp-json/wp/v2/posts/{post_id}",
            auth=(site['wp_user'], site['wp_password']),
            timeout=10
        )
        resp.raise_for_status()
        post = resp.json()
        content = post.get('content', {}).get('rendered', '')
        title = post.get('title', {}).get('rendered', '')
        link = post.get('link', '')
        return jsonify({'content': content, 'title': title, 'link': link})
    except Exception as e:
        return jsonify({'error': str(e)}), 500


# Quality
@app.route('/api/quality', methods=['GET'])
@login_required
def get_quality():
    return jsonify(load_quality())

@app.route('/api/quality', methods=['POST'])
@login_required
def create_quality():
    data = request.json
    quality_list = load_quality()
    q = {
        'id': str(uuid.uuid4()),
        'name': data.get('name', ''),
        'reference_url': data.get('reference_url', ''),
        'target_chars': data.get('target_chars', ''),
        'tone': data.get('tone', 'ですます調'),
        'extra_rules': data.get('extra_rules', ''),
        'prompt': data.get('prompt', ''),
        'is_default': False,
    }
    quality_list.append(q)
    save_quality(quality_list)
    return jsonify(q)

@app.route('/api/quality/<quality_id>', methods=['PUT'])
@login_required
def update_quality(quality_id):
    data = request.json
    quality_list = load_quality()
    for q in quality_list:
        if q['id'] == quality_id:
            q['name'] = data.get('name', q['name'])
            q['reference_url'] = data.get('reference_url', q.get('reference_url', ''))
            q['target_chars'] = data.get('target_chars', q.get('target_chars', ''))
            q['tone'] = data.get('tone', q.get('tone', 'ですます調'))
            q['extra_rules'] = data.get('extra_rules', q.get('extra_rules', ''))
            q['prompt'] = data.get('prompt', q['prompt'])
            if data.get('is_default'):
                for other in quality_list:
                    other['is_default'] = False
                q['is_default'] = True
            break
    save_quality(quality_list)
    return jsonify({'success': True})

@app.route('/api/quality/<quality_id>', methods=['DELETE'])
@login_required
def delete_quality(quality_id):
    quality_list = [q for q in load_quality() if q['id'] != quality_id]
    save_quality(quality_list)
    return jsonify({'success': True})


@app.route('/api/quality/style-references', methods=['GET'])
@login_required
def get_quality_style_references():
    settings = load_settings()
    refs = settings.get('quality_style_references') or {}
    return jsonify({
        'ranking': refs.get('ranking', ''),
        'brand': refs.get('brand', ''),
        'column': refs.get('column', ''),
    })


@app.route('/api/quality/style-references', methods=['POST'])
@login_required
def update_quality_style_references():
    data = request.json or {}
    settings = load_settings()
    settings['quality_style_references'] = {
        'ranking': data.get('ranking', '').strip(),
        'brand': data.get('brand', '').strip(),
        'column': data.get('column', '').strip(),
    }
    save_settings(settings)
    return jsonify({'success': True, 'quality_style_references': settings['quality_style_references']})


# Sites
@app.route('/api/sites', methods=['GET'])
@login_required
def get_sites():
    settings = load_settings()
    safe = []
    for s in settings.get('sites', []):
        sc = dict(s)
        if sc.get('wp_password'):
            sc['wp_password'] = mask_secret(sc['wp_password'], visible_prefix=0)
        safe.append(sc)
    return jsonify(safe)

@app.route('/api/sites', methods=['POST'])
@login_required
def create_site():
    data = request.json
    settings = load_settings()
    sites = settings.get('sites', [])
    site = {
        'id': str(uuid.uuid4()),
        'name': data.get('name', ''),
        'wp_url': data.get('wp_url', '').rstrip('/'),
        'wp_user': data.get('wp_user', ''),
        'wp_password': data.get('wp_password', ''),
    }
    sites.append(site)
    settings['sites'] = sites
    save_settings(settings)
    sc = dict(site)
    if sc.get('wp_password'):
        sc['wp_password'] = mask_secret(sc['wp_password'], visible_prefix=0)
    return jsonify(sc)

@app.route('/api/sites/<site_id>', methods=['PUT'])
@login_required
def update_site(site_id):
    data = request.json
    settings = load_settings()
    for s in settings.get('sites', []):
        if s['id'] == site_id:
            s['name'] = data.get('name', s['name'])
            s['wp_url'] = data.get('wp_url', s['wp_url']).rstrip('/')
            s['wp_user'] = data.get('wp_user', s['wp_user'])
            if data.get('wp_password') and not is_masked_value(data['wp_password']):
                s['wp_password'] = data['wp_password']
            break
    save_settings(settings)
    return jsonify({'success': True})

@app.route('/api/sites/<site_id>', methods=['DELETE'])
@login_required
def delete_site(site_id):
    settings = load_settings()
    settings['sites'] = [s for s in settings.get('sites', []) if s['id'] != site_id]
    save_settings(settings)
    return jsonify({'success': True})

@app.route('/api/sites/<site_id>/categories', methods=['GET'])
@login_required
def get_site_categories(site_id):
    settings = load_settings()
    site = get_site_by_id(site_id, settings)
    if not site:
        return jsonify({'error': 'サイトが見つかりません'}), 404
    try:
        limit = clamp_int(request.args.get('limit'), 100, 1, 200)
        return jsonify(fetch_wp_categories(site, limit=limit))
    except Exception as e:
        return jsonify({'error': f'カテゴリー取得エラー: {str(e)}'}), 500

@app.route('/api/articles/<article_id>/site', methods=['PUT'])
@login_required
def update_article_site(article_id):
    data = request.json
    articles = load_articles()
    for a in articles:
        if a['id'] == article_id:
            a['site_id'] = data.get('site_id')
            break
    save_articles(articles)
    return jsonify({'success': True})


@app.route('/api/storage/status', methods=['GET'])
@login_required
def api_storage_status():
    return jsonify(storage_status())

@app.route('/api/data-snapshot', methods=['GET'])
@login_required
def get_data_snapshot():
    snapshot = build_data_snapshot()
    snapshot['has_user_data'] = has_user_data(snapshot)
    return jsonify(snapshot)

@app.route('/api/data-snapshot', methods=['POST'])
@login_required
def restore_data_snapshot_api():
    snapshot = request.json or {}
    if not isinstance(snapshot, dict):
        return jsonify({'error': 'スナップショット形式が不正です'}), 400
    restore_data_snapshot(snapshot)
    return jsonify({'success': True, 'storage': storage_status()})


# Settings
@app.route('/api/amazon/search', methods=['POST'])
@login_required
def api_amazon_search():
    data = request.json or {}
    keywords = data.get('keywords', '')
    if not keywords:
        return jsonify({'error': 'キーワードが必要です'}), 400
    settings = load_settings()
    requested_access_key = (data.get('amazon_access_key') or '').strip()
    requested_secret_key = (data.get('amazon_secret_key') or '').strip()
    requested_partner_tag = (data.get('amazon_partner_tag') or '').strip()
    access_key = settings.get('amazon_access_key', '')
    secret_key = settings.get('amazon_secret_key', '')
    partner_tag = settings.get('amazon_partner_tag', '')
    if requested_access_key and not is_masked_value(requested_access_key):
        access_key = requested_access_key
    if requested_secret_key and not is_masked_value(requested_secret_key):
        secret_key = requested_secret_key
    if requested_partner_tag:
        partner_tag = requested_partner_tag
    if not all([access_key, secret_key, partner_tag]):
        return jsonify({'error': 'Amazon API設定が不完全です'}), 400
    try:
        products = amazon_search(keywords, access_key, secret_key, partner_tag, item_count=data.get('item_count', 3))
        return jsonify(products)
    except Exception as e:
        return jsonify({'error': str(e)}), 500


@app.route('/api/rakuten/search', methods=['POST'])
@login_required
def api_rakuten_search():
    data = request.json or {}
    keywords = data.get('keywords', '')
    if not keywords:
        return jsonify({'error': 'キーワードが必要です'}), 400
    settings = load_settings()
    requested_app_id = (data.get('rakuten_application_id') or '').strip()
    requested_aff_id = (data.get('rakuten_affiliate_id') or '').strip()
    app_id = settings.get('rakuten_application_id', '')
    aff_id = settings.get('rakuten_affiliate_id', '')
    if requested_app_id and not is_masked_value(requested_app_id):
        app_id = requested_app_id
    if requested_aff_id:
        aff_id = requested_aff_id
    if not app_id:
        return jsonify({'error': '楽天APIのアプリケーションIDが設定されていません'}), 400
    try:
        products = rakuten_search(keywords, app_id, aff_id, item_count=data.get('item_count', 3))
        return jsonify(products)
    except Exception as e:
        return jsonify({'error': str(e)}), 500


@app.route('/api/settings', methods=['GET'])
@login_required
def get_settings():
    settings = load_settings()
    safe = {
        'claude_api_key': mask_secret(settings.get('claude_api_key', '')),
        'default_quality_id': settings.get('default_quality_id', 'default'),
        'amazon_access_key': mask_secret(settings.get('amazon_access_key', '')),
        'amazon_secret_key': mask_secret(settings.get('amazon_secret_key', ''), visible_prefix=0),
        'amazon_partner_tag': settings.get('amazon_partner_tag', ''),
        'rakuten_application_id': mask_secret(settings.get('rakuten_application_id', '')),
        'rakuten_affiliate_id': settings.get('rakuten_affiliate_id', ''),
        'rakuten_asp_enabled': settings.get('rakuten_asp_enabled', False),
        'rakuten_asp_name': settings.get('rakuten_asp_name', ''),
        'rakuten_asp_link_template': settings.get('rakuten_asp_link_template', ''),
        'rakuten_asp_link_text': settings.get('rakuten_asp_link_text', '楽天市場で詳細を見る'),
        'rakuten_asp_prompt': settings.get('rakuten_asp_prompt', ''),
        'article_css': settings.get('article_css', ''),
    }
    return jsonify(safe)

@app.route('/api/settings', methods=['POST'])
@login_required
def update_settings():
    data = request.json
    settings = load_settings()
    if 'default_quality_id' in data:
        settings['default_quality_id'] = data['default_quality_id']
    if data.get('claude_api_key') and not is_masked_value(data['claude_api_key']):
        settings['claude_api_key'] = data['claude_api_key']
    if data.get('amazon_access_key') and not is_masked_value(data['amazon_access_key']):
        settings['amazon_access_key'] = data['amazon_access_key']
    if data.get('amazon_secret_key') and not is_masked_value(data['amazon_secret_key']):
        settings['amazon_secret_key'] = data['amazon_secret_key']
    if 'amazon_partner_tag' in data:
        settings['amazon_partner_tag'] = data['amazon_partner_tag']
    if data.get('rakuten_application_id') and not is_masked_value(data['rakuten_application_id']):
        settings['rakuten_application_id'] = data['rakuten_application_id']
    if 'rakuten_affiliate_id' in data:
        settings['rakuten_affiliate_id'] = data['rakuten_affiliate_id']
    if 'rakuten_asp_enabled' in data:
        settings['rakuten_asp_enabled'] = bool(data['rakuten_asp_enabled'])
    if 'rakuten_asp_name' in data:
        settings['rakuten_asp_name'] = data['rakuten_asp_name']
    if 'rakuten_asp_link_template' in data:
        settings['rakuten_asp_link_template'] = data['rakuten_asp_link_template']
    if 'rakuten_asp_link_text' in data:
        settings['rakuten_asp_link_text'] = data['rakuten_asp_link_text']
    if 'rakuten_asp_prompt' in data:
        settings['rakuten_asp_prompt'] = data['rakuten_asp_prompt']
    if 'article_css' in data:
        if looks_like_html(data.get('article_css', '')):
            return jsonify({'success': False, 'error': '記事CSS定義にはHTMLを保存できません。装飾定義のサンプルHTMLに貼り付けてください。'}), 400
        settings['article_css'] = data['article_css']
    save_settings(settings)
    return jsonify({'success': True})


if __name__ == '__main__':
    port = int(os.environ.get('PORT', 5000))
    app.run(host='0.0.0.0', port=port, debug=os.environ.get('FLASK_DEBUG', 'false').lower() == 'true')
