import os
import json
import uuid
import threading
import queue
from concurrent.futures import ThreadPoolExecutor, as_completed
import re
import csv
import io
import math
import hashlib
import difflib
import time
import traceback
from datetime import datetime, timedelta
from pathlib import Path
from functools import wraps
from html import escape, unescape
from html.parser import HTMLParser
from urllib.parse import urljoin

from flask import Flask, render_template, request, jsonify, session, redirect, url_for, Response, stream_with_context, send_from_directory
from werkzeug.exceptions import HTTPException
import anthropic
import openpyxl
import requests
try:
    from bs4 import BeautifulSoup, FeatureNotFound, NavigableString
except ImportError:
    BeautifulSoup = None
    FeatureNotFound = Exception
    NavigableString = None

app = Flask(__name__)
app.secret_key = os.environ.get('SECRET_KEY', 'dev-secret-key-change-in-production')


@app.errorhandler(Exception)
def handle_unexpected_error(error):
    if isinstance(error, HTTPException):
        status_code = error.code or 500
        message = error.description or error.name
    else:
        status_code = 500
        message = str(error) or error.__class__.__name__
        app.logger.error('Unhandled exception: %s\n%s', message, traceback.format_exc())

    if request.path.startswith('/api/'):
        return jsonify({
            'success': False,
            'error': message,
            'error_type': error.__class__.__name__,
        }), status_code
    if isinstance(error, HTTPException):
        return error
    return '<h1><p>Internal Server Error</p></h1>', 500

DATA_DIR_WARNING = ''
CLAUDE_ARTICLE_MODEL = 'claude-sonnet-4-6'
CLAUDE_TITLE_IDEA_DEFAULT_MODEL = 'claude-haiku-4-5-20251001'
CLAUDE_TITLE_IDEA_MODEL = os.environ.get('CLAUDE_TITLE_IDEA_MODEL', CLAUDE_TITLE_IDEA_DEFAULT_MODEL)
CLAUDE_TITLE_IDEA_FALLBACK_MODELS = [
    model.strip()
    for model in os.environ.get(
        'CLAUDE_TITLE_IDEA_FALLBACK_MODELS',
        f'claude-haiku-4-5,{CLAUDE_ARTICLE_MODEL},claude-3-5-haiku-20241022,claude-3-5-haiku-latest,claude-sonnet-4-5-20250929,claude-sonnet-4-20250514'
    ).split(',')
    if model.strip()
]
try:
    TITLE_IDEA_AI_TIMEOUT_SECONDS = int(os.environ.get('TITLE_IDEA_AI_TIMEOUT_SECONDS', '20'))
except ValueError:
    TITLE_IDEA_AI_TIMEOUT_SECONDS = 20
TITLE_IDEA_PER_KEYWORD_RETRY = os.environ.get('TITLE_IDEA_PER_KEYWORD_RETRY', '0') == '1'
try:
    TITLE_IDEA_BATCH_SIZE = max(1, int(os.environ.get('TITLE_IDEA_BATCH_SIZE', '3')))
except ValueError:
    TITLE_IDEA_BATCH_SIZE = 3
try:
    TITLE_IDEA_PARALLEL_BATCHES = max(1, int(os.environ.get('TITLE_IDEA_PARALLEL_BATCHES', '5')))
except ValueError:
    TITLE_IDEA_PARALLEL_BATCHES = 5
try:
    CLAUDE_ARTICLE_MAX_TOKENS = int(os.environ.get('CLAUDE_ARTICLE_MAX_TOKENS', '20000'))
except ValueError:
    CLAUDE_ARTICLE_MAX_TOKENS = 20000
try:
    CLAUDE_ARTICLE_CONTINUATION_MAX_ROUNDS = int(os.environ.get('CLAUDE_ARTICLE_CONTINUATION_MAX_ROUNDS', '4'))
except ValueError:
    CLAUDE_ARTICLE_CONTINUATION_MAX_ROUNDS = 4
try:
    BATCH_GENERATION_MAX_RETRIES = int(os.environ.get('BATCH_GENERATION_MAX_RETRIES', '2'))
except ValueError:
    BATCH_GENERATION_MAX_RETRIES = 2
try:
    CLAUDE_SEGMENT_CONTINUATION_MAX_ROUNDS = int(os.environ.get('CLAUDE_SEGMENT_CONTINUATION_MAX_ROUNDS', '2'))
except ValueError:
    CLAUDE_SEGMENT_CONTINUATION_MAX_ROUNDS = 2
DEFAULT_ARTICLE_TARGET_CHARS = 3000
SONNET_INPUT_USD_PER_MTOK = 3.0
SONNET_OUTPUT_USD_PER_MTOK = 15.0
USAGE_ESTIMATE_USD_JPY = 155
APP_STARTED_AT = datetime.now()
STALE_ARTICLE_STATUS_MINUTES = {
    'generating': 30,
    'publishing': 15,
    'repairing': 15,
}

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
SETTINGS_FILE = DATA_DIR / 'settings.json'
REWRITE_FILE = DATA_DIR / 'rewrite_items.json'
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

def parse_saved_datetime(value):
    if not value:
        return None
    try:
        parsed = datetime.fromisoformat(str(value))
        if parsed.tzinfo:
            parsed = parsed.replace(tzinfo=None)
        return parsed
    except (TypeError, ValueError):
        return None

def fallback_article_status(article):
    if article.get('wp_post_id') or article.get('wp_url'):
        return 'published'
    if article.get('content'):
        return 'generated'
    return 'pending'

def recover_stale_article_statuses(articles, jobs=None):
    now = datetime.now()
    jobs_by_id = {job.get('id'): job for job in (jobs or []) if job.get('id')}
    changed = False

    for article in articles:
        status = article.get('status')
        limit_minutes = STALE_ARTICLE_STATUS_MINUTES.get(status)
        if not limit_minutes:
            continue

        timestamps = [
            article.get('generation_started_at'),
            article.get('updated_at'),
            article.get('created_at'),
        ]
        started_at = next((dt for dt in (parse_saved_datetime(ts) for ts in timestamps) if dt), None)

        job = jobs_by_id.get(article.get('batch_job_id'))
        job_updated_at = None
        if job:
            job_status = job.get('status')
            job_updated_at = parse_saved_datetime(job.get('updated_at') or job.get('started_at'))
            if (
                job_status == 'running'
                and job_updated_at
                and job_updated_at >= APP_STARTED_AT - timedelta(seconds=5)
                and now - job_updated_at <= timedelta(minutes=limit_minutes)
            ):
                continue
            if job_status == 'running' and not started_at:
                started_at = job_updated_at

        active_at = job_updated_at or started_at
        if active_at and active_at < APP_STARTED_AT - timedelta(seconds=5):
            pass
        elif started_at and now - started_at <= timedelta(minutes=limit_minutes):
            continue

        article['status'] = fallback_article_status(article)
        article['generation_warning'] = '前回の処理が途中で止まったため、操作できる状態に戻しました。必要なら再生成してください。'
        article['last_generation_interrupted'] = True
        article['updated_at'] = now.isoformat()
        article['generation_finished_at'] = now.isoformat()
        article.pop('batch_job_id', None)
        article.pop('processing_message', None)
        article.pop('error', None)
        changed = True

    return changed

def load_rewrites():
    return load_json(REWRITE_FILE, [])

def save_rewrites(items):
    save_json(REWRITE_FILE, items)

OLD_DEFAULT_QUALITY_PROMPT = "SEOに最適化された、読みやすく情報量の多い記事を書いてください。見出しを適切に使い、具体例を含めてください。"
QUALITY_PRESET_VERSION = 8


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
- 読者が判断に迷う箇所にはFAQ、比較表、チェックリスト、リストを使う
- 広告やCTAは文脈に合う場所だけに置き、押し売りにしない
- 本文にAIの説明文、Markdown、Gutenbergコメント、サンプル文を出さない""",
            "target_chars": "3000",
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
            "target_chars": "3000",
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
- よくある質問/FAQセクションは原則作らず、疑問点は注意点・購入方法・まとめの中で解消する
- 本文にAIの説明文、Markdown、Gutenbergコメント、サンプル文を出さない""",
            "target_chars": "3000",
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
            "target_chars": "3000",
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
            preserve_structure = existing.get('structure_html', preset.get('structure_html', ''))
            existing_target = str(existing.get('target_chars', '')).strip()
            target_chars = (
                preset.get('target_chars', existing_target)
                if existing_target in ('', '2500', '5000')
                else existing_target
            )
            existing.update({
                'name': preset.get('name', existing.get('name', '')),
                'article_type': preset.get('article_type', existing.get('article_type')),
                'prompt': preset.get('prompt', existing.get('prompt', '')),
                'target_chars': target_chars,
                'tone': preset.get('tone', existing.get('tone', 'ですます調')),
                'extra_rules': preset.get('extra_rules', existing.get('extra_rules', '')),
                'system_preset_version': preset.get('system_preset_version', version),
                'is_default': preserve_default,
                'reference_url': preserve_reference,
                'structure_html': preserve_structure,
            })
            if existing.get('article_type') is None:
                existing.pop('article_type', None)
            changed = True
    if changed:
        try:
            save_json(QUALITY_FILE, quality)
        except Exception as e:
            app.logger.warning('Failed to persist quality presets: %s', e)
    return quality

def save_quality(quality):
    save_json(QUALITY_FILE, quality)

def first_env(*names):
    for name in names:
        value = os.environ.get(name)
        if value:
            return value
    return ''

def apply_settings_env_fallbacks(settings):
    fallback_map = {
        'claude_api_key': ('ANTHROPIC_API_KEY', 'CLAUDE_API_KEY'),
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
        'rewrite_items': load_rewrites(),
    }

def has_user_data(snapshot):
    settings = snapshot.get('settings') or {}
    quality = snapshot.get('quality') or []
    non_default_quality = [
        q for q in quality
        if q.get('id') != 'default' or q.get('name') != '標準品質'
    ]
    setting_keys = (
        'sites', 'claude_api_key', 'article_css'
    )
    return any([
        bool(snapshot.get('articles')),
        bool(snapshot.get('rewrite_items')),
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
    if isinstance(snapshot.get('rewrite_items'), list):
        save_rewrites(snapshot['rewrite_items'])
def load_settings():
    settings = load_json(SETTINGS_FILE, {
        "sites": [],
        "claude_api_key": "",
        "default_quality_id": "default",
        "article_css": "",
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


def fetch_url_text(url, max_chars=2500, timeout=5):
    resp = requests.get(url, timeout=timeout, headers={'User-Agent': 'Mozilla/5.0'})
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
- H2は主要セクション、H3はH2内の小項目に使う。メリット、デメリット・注意点、よくある質問を作る場合は、H2の直下に各項目・各質問を <h3 class="wp-block-heading">...</h3> で分ける
- 装飾は <strong>太字</strong>、<span style="color:#d32f2f">赤字</span>、<mark>マーカー</mark>、<ul><li>リスト</li></ul>、<table>表</table> だけを使う
- 装飾目的の複雑なdiv、独自class、吹き出し、ボックス、カード、GutenbergブロックHTMLは出力しない
- 比較表は横幅が崩れにくいように列を増やしすぎず、セル内は短くする
- 1つの<p>は長くしすぎず、原則2〜3文で区切る。長い説明は複数段落に分ける
- 断定しすぎず、選び方・比較理由・向いている人・注意点を具体的に書く
- 広告カード、アフィリエイトリンク、RINKER風の商品カードは出力しない。広告挿入はWordPress側のプラグインに任せる"""


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


COMMERCE_VISIBLE_URL_RE = re.compile(
    r'https?://(?:www\.amazon\.co\.jp|amazon\.co\.jp|search\.rakuten\.co\.jp|hb\.afl\.rakuten\.co\.jp|[^/\s<>"\']*\.rakuten\.co\.jp|[^/\s<>"\']*\.rakuten\.ne\.jp)[^\s<>"\']*',
    re.I
)


def commerce_link_label(href):
    lower = str(href or '').lower()
    if 'amazon.co.jp' in lower:
        return 'Amazonで見る'
    if 'rakuten' in lower:
        return '楽天市場で見る'
    return '商品を見る'


def has_visible_commerce_url(text):
    value = str(text or '')
    lower = value.lower()
    return bool(
        COMMERCE_VISIBLE_URL_RE.search(value)
        or 'tag=' in lower
        or 'amazon.co.jp' in lower
        or 'rakuten.co.jp' in lower
        or 'rakuten.ne.jp' in lower
        or 'hb.afl.rakuten.co.jp' in lower
    )


def strip_visible_commerce_urls_text(text):
    return COMMERCE_VISIBLE_URL_RE.sub('', str(text or '')).replace('tag=', '').strip()


def strip_visible_commerce_urls_regex(html):
    parts = re.split(r'(<[^>]+>)', str(html or ''))
    for index in range(0, len(parts), 2):
        parts[index] = strip_visible_commerce_urls_text(parts[index])
    return ''.join(parts)


def strip_non_affiliate_commerce_links_regex(html):
    def repl(match):
        href = match.group(1)
        label = match.group(2)
        lower = href.lower()
        if 'amazon.co.jp' in lower and 'tag=' not in lower:
            return commerce_link_label(href)
        if (
            ('rakuten.co.jp' in lower or 'rakuten.ne.jp' in lower)
            and 'hb.afl.rakuten.co.jp' not in lower
            and 'affiliateurl=' not in lower
        ):
            return commerce_link_label(href)
        if has_visible_commerce_url(label) and '<img' not in label.lower():
            return re.sub(r'>([\s\S]*?)</a>$', f'>{commerce_link_label(href)}</a>', match.group(0), flags=re.I)
        return match.group(0)
    html = re.sub(r'<a\b[^>]*href=["\']([^"\']+)["\'][^>]*>([\s\S]*?)</a>', repl, html, flags=re.I)
    return strip_visible_commerce_urls_regex(html)


def clean_visible_commerce_urls(root):
    if not BeautifulSoup:
        return
    for a in root.find_all('a', href=True):
        href = str(a.get('href') or '')
        lower = href.lower()
        if 'amazon.co.jp' in lower and 'tag=' not in lower:
            a.replace_with(commerce_link_label(href))
            continue
        if (
            ('rakuten.co.jp' in lower or 'rakuten.ne.jp' in lower)
            and 'hb.afl.rakuten.co.jp' not in lower
            and 'affiliateurl=' not in lower
        ):
            a.replace_with(commerce_link_label(href))
            continue
        label_text = a.get_text(' ', strip=True)
        if label_text and not a.find('img') and has_visible_commerce_url(label_text):
            a.clear()
            a.append(commerce_link_label(href))
    if not NavigableString:
        return
    for text_node in list(root.find_all(string=True)):
        parent = getattr(text_node, 'parent', None)
        if not parent or parent.name in ('script', 'style', 'a'):
            continue
        value = str(text_node)
        if has_visible_commerce_url(value):
            text_node.replace_with(NavigableString(strip_visible_commerce_urls_text(value)))


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
        clean_visible_commerce_urls(root)
        html = ''.join(str(child) for child in root.contents)
    if not BeautifulSoup:
        html = strip_non_affiliate_commerce_links_regex(balance_common_html_tags(html))
    return strip_wp_block_artifacts(html).strip().strip('`').strip()


def primary_article_keyword(article):
    def usable(value):
        candidate = re.sub(r'\s+', ' ', str(value or '')).strip()
        if not (2 <= len(candidate) <= 30):
            return ''
        if not re.search(r'[\wぁ-んァ-ヶ一-龠]', candidate):
            return ''
        stripped = re.sub(r'[0-9０-９,\.\s円台選個本枚種類社]+', '', candidate)
        if len(stripped) < 2:
            return ''
        return candidate

    inferred = infer_ad_keywords_from_title(
        article.get('title', ''),
        article.get('keywords', ''),
        article.get('article_type', 'ranking')
    )
    candidates = [
        str(article.get('keywords') or '').split(',')[0].split('、')[0].strip(),
        str(article.get('ad_keywords') or '').split(',')[0].split('、')[0].strip(),
        str(article.get('category') or '').split(',')[0].split('、')[0].strip(),
        inferred,
    ]
    for candidate in candidates:
        candidate = usable(candidate)
        if candidate:
            return candidate
    return ''


def keyword_heading_text(original, keyword, article_type='ranking'):
    text = re.sub(r'\s+', ' ', str(original or '')).strip()
    if not keyword or keyword in text:
        return text
    normalized = normalize_article_type(article_type, 'ranking')
    if text in ('まとめ', '総括'):
        return f'まとめ｜{keyword}選びで失敗しないために'
    if text in ('よくある質問', 'FAQ', 'Q&A'):
        return f'{keyword}のよくある質問'
    if '選び方' in text:
        return text.replace('選び方', f'{keyword}の選び方')
    if normalized == 'ranking' and ('ランキング' in text or '個別解説' in text or 'おすすめ' in text):
        return f'{keyword}{text}'
    if re.search(r'(?:第?\s*)?[1-9][0-9]?\s*位', text):
        return f'{text}｜{keyword}'
    if len(text) <= 32:
        return f'{keyword}｜{text}'
    return text


def split_plain_paragraphs(soup):
    for p in list(soup.find_all('p')):
        if p.find(['a', 'img', 'table', 'ul', 'ol', 'div']):
            continue
        text = p.get_text('', strip=True)
        if len(text) < 100:
            continue
        sentences = [s for s in re.split(r'(?<=[。！？])', text) if s.strip()]
        if len(sentences) < 2:
            continue
        chunks = []
        current = ''
        for sentence in sentences:
            if current and len(current) + len(sentence) > 90:
                chunks.append(current)
                current = sentence
            else:
                current += sentence
        if current:
            chunks.append(current)
        if len(chunks) < 2:
            continue
        for chunk in reversed(chunks):
            new_p = soup.new_tag('p')
            new_p.string = chunk.strip()
            p.insert_after(new_p)
        p.decompose()


def add_marker_to_first_keyword(soup, keyword):
    if not keyword:
        return
    if soup.select_one('mark'):
        return
    for p in soup.find_all('p'):
        if p.find(['a', 'img', 'table', 'ul', 'ol', 'div', 'mark']):
            continue
        text = p.get_text('', strip=True)
        index = text.find(keyword)
        if index < 0:
            continue
        before = text[:index]
        target = text[index:index + len(keyword)]
        after = text[index + len(keyword):]
        marker = soup.new_tag('mark')
        marker.string = target
        p.clear()
        if before:
            p.append(before)
        p.append(marker)
        if after:
            p.append(after)
        return


def format_block_html(html):
    html = re.sub(r'(</(?:p|h2|h3|h4|ul|ol|table|figure|div)>)\s*(<(?:p|h2|h3|h4|ul|ol|table|figure|div)\b)', r'\1\n\n\2', html, flags=re.I)
    html = re.sub(r'(</tr>)\s*(<tr\b)', r'\1\n\2', html, flags=re.I)
    return html.strip()


def enhance_generated_article_html_fallback(html, keyword, article_type):
    if keyword:
        def heading_repl(match):
            tag = match.group(1)
            attrs = match.group(2) or ''
            inner = re.sub(r'<[^>]+>', '', match.group(3)).strip()
            return f'<{tag}{attrs}>{escape(keyword_heading_text(inner, keyword, article_type))}</{tag}>'
        html = re.sub(r'<(h[23])([^>]*)>([\s\S]*?)</\1>', heading_repl, html, flags=re.I)

    def paragraph_repl(match):
        attrs = match.group(1) or ''
        inner = match.group(2)
        if '<' in inner or len(re.sub(r'\s+', '', inner)) < 100:
            return match.group(0)
        sentences = [s for s in re.split(r'(?<=[。！？])', inner) if s.strip()]
        if len(sentences) < 2:
            return match.group(0)
        chunks = []
        current = ''
        for sentence in sentences:
            if current and len(current) + len(sentence) > 90:
                chunks.append(current)
                current = sentence
            else:
                current += sentence
        if current:
            chunks.append(current)
        if len(chunks) < 2:
            return match.group(0)
        return ''.join(f'<p{attrs}>{chunk.strip()}</p>' for chunk in chunks)
    html = re.sub(r'<p([^>]*)>([^<]{100,})</p>', paragraph_repl, html, flags=re.I)

    if keyword and '<mark' not in html and keyword in html:
        html = html.replace(keyword, f'<mark>{escape(keyword)}</mark>', 1)
    return html


def enhance_generated_article_html(content, article, article_type):
    html = sanitize_generated_html(content)
    keyword = primary_article_keyword({**article, 'article_type': article_type})
    if not html:
        return format_block_html(html)
    if not BeautifulSoup:
        return format_block_html(enhance_generated_article_html_fallback(html, keyword, article_type))
    try:
        soup = BeautifulSoup(f'<div id="affiros9-enhance-root">{html}</div>', 'html5lib')
    except FeatureNotFound:
        soup = BeautifulSoup(f'<div id="affiros9-enhance-root">{html}</div>', 'html.parser')
    root = soup.find(id='affiros9-enhance-root')
    if not root:
        return format_block_html(html)
    if keyword:
        for heading in root.find_all(['h2', 'h3']):
            heading.string = keyword_heading_text(heading.get_text(' ', strip=True), keyword, article_type)
    split_plain_paragraphs(root)
    add_marker_to_first_keyword(root, keyword)
    return format_block_html(''.join(str(child) for child in root.contents))


def safe_enhance_generated_article_html(content, article, article_type):
    try:
        return enhance_generated_article_html(content, article, article_type), ''
    except Exception as e:
        html = sanitize_generated_html(content)
        return format_block_html(html), f'HTML整形をスキップしました: {e}'


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


def infer_ad_keywords_from_title(title, keywords='', article_type='ranking'):
    source = str(title or '').strip()

    def clean_candidate(value):
        text = str(value or '').strip()
        text = re.split(r'[｜|]', text, maxsplit=1)[0]
        text = re.sub(r'【[^】]{0,40}】', ' ', text)
        text = re.sub(r'\[[^\]]{0,40}\]', ' ', text)
        text = re.sub(r'（[^）]{0,40}）', ' ', text)
        text = re.sub(r'\([^)]{0,40}\)', ' ', text)
        text = re.sub(r'[「」『』“”"\'`]', ' ', text)
        text = re.sub(r'[0-9０-９][0-9０-９,，.．]*\s*円台?(?:で買える|以下|以内|前後)?', ' ', text)
        text = re.sub(r'[0-9０-９]+\s*(?:選|社|個|本|枚|種類|台)\s*.*$', '', text)
        prefix_pattern = r'^(?:最新版|最新|徹底|安い|格安|高コスパ|コスパ|おすすめ|人気|厳選|初心者向け|保存版|完全版|比較)\s*'
        previous = None
        while previous != text:
            previous = text
            text = re.sub(prefix_pattern, '', text)
        suffix_pattern = r'(?:の)?(?:おすすめ|比較|ランキング|口コミ|評判|レビュー|選び方|使い方|料金|価格|効果|メリット|デメリット|とは|まとめ|向け|ランキング形式).*$'
        match = re.search(r'(.+?)' + suffix_pattern, text)
        if match and len(match.group(1).strip()) >= 2:
            text = match.group(1)
        else:
            text = re.sub(suffix_pattern, '', text)
        text = re.sub(r'(?:で買える|で購入できる|買える|購入できる|探している|欲しい|選ぶべき)', ' ', text)
        text = re.sub(r'[!?！？、。:：;；/／・\-―–—]+', ' ', text)
        text = re.sub(r'\s+', ' ', text).strip()
        return text[:80]

    candidate = clean_candidate(source)
    if len(candidate) >= 2:
        return candidate

    keyword_source = str(keywords or '').strip()
    first_keyword = re.split(r'[,、\n]', keyword_source, maxsplit=1)[0].strip()
    candidate = clean_candidate(first_keyword)
    if len(candidate) >= 2:
        return candidate

    return first_keyword[:80]


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


def normalize_title_key(value):
    return re.sub(r'\s+', '', str(value or '').strip()).lower()


def split_title_keywords(value, limit=50):
    raw = str(value or '')
    parts = re.split(r'[\r\n]+', raw)
    keywords = []
    seen = set()
    for part in parts:
        text = re.sub(r'^\s*[-*・\d０-９]+[.)．、\s]*', '', part).strip()
        if not text:
            continue
        key = normalize_title_key(text)
        if key in seen:
            continue
        keywords.append(text[:120])
        seen.add(key)
        if len(keywords) >= limit:
            break
    return keywords


def infer_title_article_type(keyword='', title=''):
    text = f'{keyword} {title}'.strip()
    if re.search(r'(?:口コミ|評判|レビュー|メリット|デメリット)', text):
        has_specific_name = bool(re.search(r'[A-Za-z][A-Za-z0-9-]{2,}|[A-Z]{2,}\s*-?\s*\d+|[A-Za-z]+\s*\d+', text))
        if has_specific_name and not re.search(r'(?:おすすめ|比較|ランキング|選び方|人気|厳選)', text):
            return 'brand'
    if re.search(r'(?:とは|選び方|使い方|洗い方|原因|対策|方法|違い|必要|いつ|なぜ|ポイント)', text):
        return 'column'
    if re.search(r'(?:おすすめ|比較|ランキング|人気|厳選|ベスト|[0-9０-９]+\s*選)', text):
        return 'ranking'
    return 'ranking'


def coerce_title_article_type(value, keyword='', title=''):
    normalized = normalize_article_type(value, '')
    if normalized in ('ranking', 'brand', 'column'):
        return normalized
    return infer_title_article_type(keyword, title)


def title_base_keyword(value):
    text = str(value or '').strip()
    text = re.sub(
        r'[\s　]+(?:おすすめ|比較|ランキング|人気|厳選|口コミ|評判|レビュー|選び方|使い方|とは|方法|メリット|デメリット)\s*$',
        '',
        text,
        flags=re.I,
    ).strip()
    return text or str(value or '').strip()


def score_title_idea(title, keyword, article_type, existing_title_keys):
    title = str(title or '').strip()
    keyword = str(keyword or '').strip()
    base_keyword = title_base_keyword(keyword)
    score = 45
    length = len(title)
    if (keyword and keyword in title) or (base_keyword and base_keyword in title):
        score += 20
    if 28 <= length <= 45:
        score += 18
    elif 20 <= length <= 55:
        score += 10
    else:
        score -= 8
    if article_type == 'brand' and re.search(r'口コミ|評判|レビュー|メリット|デメリット|注意点', title):
        score += 12
    elif article_type == 'column' and re.search(r'とは|方法|原因|対策|基礎|ポイント|解説', title):
        score += 12
    elif article_type == 'ranking' and re.search(r'おすすめ|比較|ランキング|選び方|厳選', title):
        score += 12
    if re.search(r'完全無料|絶対|必ず|最強|神|ヤバい', title):
        score -= 12
    if normalize_title_key(title) in existing_title_keys:
        score -= 30
    return max(1, min(100, score))


def title_generation_prompt(keywords, count_per_keyword, category=''):
    return f"""あなたはSEO記事の編集者です。
以下のキーワードごとに、検索意図に合う記事タイトル案を{count_per_keyword}個ずつ作り、記事種類も自動分類してください。

カテゴリー: {category or '未指定'}
キーワード:
{chr(10).join(f'- {kw}' for kw in keywords)}

出力形式:
{{
  "ideas": [
    {{
      "keyword": "対象キーワード",
      "title": "記事タイトル",
      "slug": "english-slug",
      "article_type": "ranking/brand/column のいずれか",
      "search_intent": "読者の検索意図を短く",
      "reason": "このタイトルにした理由を短く",
      "priority": "高/中/低"
    }}
  ]
}}

ルール:
- 1キーワードにつき必ず{count_per_keyword}案。
- titleには対象キーワードの主要語を自然に含める。
- 似た語尾、似た構文、同じ切り口を連発しない。
- 1キーワード内で「比較」「選び方」「悩み解決」「購入判断」など切り口を分ける。
- 広い商品ジャンルの「おすすめ・比較・人気」は ranking。
- 「とは・選び方・使い方・原因・対策・違い」は column。
- 具体的な商品名・サービス名・型番の口コミ/評判/レビューは brand。
- 広いジャンル名だけなら brand にしない。
- 釣りタイトル、誇大表現、根拠のない断定は禁止。
- 文字数は日本語で28〜45字前後を基本にする。
- slug は英語のみ・小文字・ハイフン区切り（kebab-case）。3〜4単語、最大30文字以内。
  記事内容を端的に表すSEOフレンドリーな英語に翻訳/要約する（直訳のローマ字化は禁止）。
  例: 「ネックウォーマーおすすめランキング」→「neck-warmer-ranking」。
- JSON以外の説明文、Markdown、コードフェンスは禁止。"""


def extract_title_ideas_payload(text):
    data = extract_json_object(text)
    if data:
        return data
    raw = str(text or '').strip()
    raw = re.sub(r'^\s*```(?:json)?\s*', '', raw, flags=re.I)
    raw = re.sub(r'\s*```\s*$', '', raw)
    start = raw.find('[')
    end = raw.rfind(']')
    if start >= 0 and end > start:
        try:
            return {'ideas': json.loads(raw[start:end + 1])}
        except Exception:
            return {}
    return {}


def coerce_title_ideas(payload, keywords, count_per_keyword):
    raw_ideas = payload.get('ideas') if isinstance(payload, dict) else []
    if not isinstance(raw_ideas, list):
        raw_ideas = []
    keyword_set = {normalize_title_key(k): k for k in keywords}
    grouped = {kw: [] for kw in keywords}
    loose = []
    for item in raw_ideas:
        if not isinstance(item, dict):
            continue
        title = str(item.get('title') or '').strip()
        if not title:
            continue
        keyword = str(item.get('keyword') or '').strip()
        matched = keyword_set.get(normalize_title_key(keyword))
        if not matched:
            matched = next((kw for kw in keywords if kw in title), '')
        raw_slug = re.sub(r'[^a-z0-9-]', '', normalize_slug(str(item.get('slug') or '').lower()))[:30].strip('-')
        idea = {
            'keyword': matched or keyword or (keywords[0] if keywords else ''),
            'title': title[:120],
            'slug': raw_slug,
            'search_intent': str(item.get('search_intent') or item.get('intent') or '').strip()[:160],
            'reason': str(item.get('reason') or '').strip()[:220],
            'priority': str(item.get('priority') or '中').strip()[:10],
            'article_type': coerce_title_article_type(item.get('article_type'), matched or keyword, title),
        }
        if matched:
            grouped[matched].append(idea)
        else:
            loose.append(idea)
    ideas = []
    for kw in keywords:
        ideas.extend(grouped.get(kw, [])[:count_per_keyword])
    ideas.extend(loose)
    return ideas[:len(keywords) * count_per_keyword]


def enrich_title_ideas(ideas, category='', site_id=''):
    try:
        articles = load_articles()
        if not isinstance(articles, list):
            articles = []
    except Exception as e:
        app.logger.warning('Failed to load articles for title duplicate check: %s', e)
        articles = []
    existing_title_keys = {normalize_title_key(a.get('title')) for a in articles if isinstance(a, dict)}
    enriched = []
    seen = set()
    for idea in ideas:
        title = str(idea.get('title') or '').strip()
        if not title:
            continue
        key = normalize_title_key(title)
        if key in seen:
            continue
        seen.add(key)
        keyword = str(idea.get('keyword') or '').strip()
        article_type = coerce_title_article_type(idea.get('article_type'), keyword, title)
        score = score_title_idea(title, keyword, article_type, existing_title_keys)
        enriched.append({
            'id': str(uuid.uuid4()),
            'keyword': keyword,
            'title': title,
            'slug': str(idea.get('slug') or '').strip(),
            'search_intent': str(idea.get('search_intent') or '').strip(),
            'reason': str(idea.get('reason') or '').strip(),
            'priority': str(idea.get('priority') or ('高' if score >= 82 else '中')).strip() or '中',
            'score': score,
            'duplicate': key in existing_title_keys,
            'article_type': article_type,
            'category': category,
            'site_id': site_id or None,
            'quality_id': None,
        })
    return enriched


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


def combine_article_usages(usages):
    valid = [u for u in (usages or []) if isinstance(u, dict)]
    if not valid:
        return build_article_usage('', '')
    input_tokens = sum(int(u.get('input_tokens') or 0) for u in valid)
    output_tokens = sum(int(u.get('output_tokens') or 0) for u in valid)
    cost_usd = (input_tokens / 1_000_000 * SONNET_INPUT_USD_PER_MTOK) + (output_tokens / 1_000_000 * SONNET_OUTPUT_USD_PER_MTOK)
    return {
        'model': CLAUDE_ARTICLE_MODEL,
        'input_tokens': int(input_tokens),
        'output_tokens': int(output_tokens),
        'cost_usd': round(cost_usd, 6),
        'cost_yen': round(cost_usd * USAGE_ESTIMATE_USD_JPY, 2),
        'estimated': any(bool(u.get('estimated')) for u in valid),
        'calls': len(valid),
        'pricing': {
            'input_usd_per_mtok': SONNET_INPUT_USD_PER_MTOK,
            'output_usd_per_mtok': SONNET_OUTPUT_USD_PER_MTOK,
            'usd_jpy': USAGE_ESTIMATE_USD_JPY,
        }
    }


def create_claude_message(client, prompt, max_tokens=None, timeout=None, model=None):
    messages_api = getattr(client, 'messages', None)
    create = getattr(messages_api, 'create', None)
    if not callable(create):
        raise RuntimeError('Claude API client is not ready: messages.create is unavailable')
    kwargs = {
        'model': model or CLAUDE_ARTICLE_MODEL,
        'max_tokens': max_tokens or CLAUDE_ARTICLE_MAX_TOKENS,
        'messages': [{'role': 'user', 'content': prompt}],
    }
    if timeout is not None:
        kwargs['timeout'] = timeout
    return create(**kwargs)


def compact_ai_error(error, limit=260):
    text = re.sub(r'\s+', ' ', str(error or error.__class__.__name__)).strip()
    text = re.sub(r'sk-ant-[A-Za-z0-9_\-]+', 'sk-ant-***', text)
    return text[:limit]


def non_retryable_ai_error(error):
    text = str(error or '').lower()
    return bool(re.search(r'401|403|authentication|unauthorized|permission|api key|invalid key|credit|quota|billing|balance', text))


def title_idea_max_tokens(keyword_count, count_per_keyword):
    return min(8000, max(800, keyword_count * count_per_keyword * 160))


def claude_title_idea_models():
    models = []
    for model in [CLAUDE_TITLE_IDEA_MODEL] + CLAUDE_TITLE_IDEA_FALLBACK_MODELS:
        if model and model not in models:
            models.append(model)
    return models


def is_model_not_found_error(error):
    text = str(error or '').lower()
    return 'not_found' in text or 'model' in text and '404' in text


def generate_claude_title_ideas_once(api_key, keywords, count_per_keyword, category):
    prompt = title_generation_prompt(keywords, count_per_keyword, category)
    client = anthropic.Anthropic(api_key=api_key)
    last_error = None
    for model in claude_title_idea_models():
        try:
            message = create_claude_message(
                client,
                prompt,
                max_tokens=title_idea_max_tokens(len(keywords), count_per_keyword),
                timeout=TITLE_IDEA_AI_TIMEOUT_SECONDS,
                model=model,
            )
            text = anthropic_message_text(message)
            ideas = coerce_title_ideas(extract_title_ideas_payload(text), keywords, count_per_keyword)
            if not ideas:
                raise ValueError('Claude returned no usable title ideas')
            return ideas, model
        except Exception as e:
            last_error = e
            app.logger.warning('Claude title idea model failed (%s): %s', model, e)
            if not is_model_not_found_error(e):
                break
    raise last_error or RuntimeError('Claude title idea generation failed')


def generate_claude_title_ideas_resilient(api_key, keywords, count_per_keyword, category):
    retry_notes = []
    try:
        ideas, model_used = generate_claude_title_ideas_once(api_key, keywords, count_per_keyword, category)
        return ideas, retry_notes, model_used
    except Exception as e:
        first_error = compact_ai_error(e)
        retry_notes.append(f'一括生成失敗: {first_error}')
        app.logger.warning('Claude title idea batch generation failed: %s', e)
        if not TITLE_IDEA_PER_KEYWORD_RETRY or non_retryable_ai_error(e) or len(keywords) <= 1:
            raise RuntimeError(first_error)

    ideas = []
    model_used = CLAUDE_TITLE_IDEA_MODEL
    failed_keywords = []
    for keyword in keywords:
        try:
            chunk_ideas, chunk_model = generate_claude_title_ideas_once(api_key, [keyword], count_per_keyword, category)
            ideas.extend(chunk_ideas)
            model_used = chunk_model
        except Exception as e:
            failed_keywords.append(f'{keyword}: {compact_ai_error(e, 120)}')
            app.logger.warning('Claude title idea keyword retry failed for %s: %s', keyword, e)
            if non_retryable_ai_error(e):
                break

    if ideas:
        if failed_keywords:
            retry_notes.append('一部キーワード失敗: ' + ' / '.join(failed_keywords[:5]))
        return ideas, retry_notes, model_used
    raise RuntimeError(' / '.join(retry_notes + failed_keywords) or 'Claude title idea generation failed')


def title_ideas_failure_payload(error, keywords=None, provider_errors=None):
    return {
        'success': False,
        'ai_used': False,
        'source': 'none',
        'keywords': keywords or [],
        'ideas': [],
        'error': error,
        'provider_errors': provider_errors or [],
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


def content_similarity(a, b):
    left = re.sub(r'\s+', '', html_to_text(a or ''))[:20000]
    right = re.sub(r'\s+', '', html_to_text(b or ''))[:20000]
    if not left or not right:
        return 0.0
    return difflib.SequenceMatcher(None, left, right).ratio()


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
        suggestions.append('比較表・箇条書きを入れてスキャンしやすくしてください。')

    if image_count:
        score += 4
    else:
        suggestions.append('必要に応じて表やリストを使い、読みやすく整理すると改善できます。')

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


def build_ranking_structure_prompt(article, article_type):
    if normalize_article_type(article_type, 'ranking') != 'ranking':
        return ''
    count = extract_ranking_count(article)
    if not count:
        return ''
    return f"""

ランキング記事の必須構成:
- リード文 → 結論早見表 → 比較表 → ランキング本文 → 選び方 → FAQ → まとめ、の順で書いてください。
- 比較表は <table><tbody> に商品行を必ず{count}行入れてください。
- ランキング本文では、必ず <h3>1位：商品名</h3> から <h3>{count}位：商品名</h3> まで、順位番号入りのh3見出しを{count}個出してください。
- 各順位のh3ごとに、特徴・おすすめな人・注意点を最低2段落以上で書いてください。
- {count}位まで書き終える前に「選定基準」「選び方」「FAQ」「まとめ」へ進まないでください。
- 比較表だけで商品紹介を終わらせないでください。比較表とは別に、必ず{count}商品の個別解説を本文に含めてください。
- 途中で終わりそうな場合は、装飾よりも{count}位までの個別解説とまとめを優先してください。
"""


def parse_target_chars(value):
    try:
        target = int(str(value or '').replace(',', '').strip())
    except (TypeError, ValueError):
        return DEFAULT_ARTICLE_TARGET_CHARS
    return target if target > 0 else DEFAULT_ARTICLE_TARGET_CHARS


def effective_target_chars(quality=None):
    return parse_target_chars((quality or {}).get('target_chars'))


def minimum_required_content_chars(quality=None):
    target = effective_target_chars(quality)
    if target < 3000:
        return max(500, min(target, int(target * 0.75)))
    return max(1800, min(target - 500, int(target * 0.65)))


def claude_max_tokens_for_quality(quality=None, floor=2800, ceiling=8000):
    target = effective_target_chars(quality)
    return max(floor, min(ceiling, int(target * 1.4) + 1200))


def claude_segment_max_tokens(quality=None, total=1):
    segment_target = max(900, math.ceil(effective_target_chars(quality) / max(total, 1)))
    return max(2400, min(5500, int(segment_target * 1.8) + 1200))


def claude_continuation_max_tokens(quality=None):
    return claude_max_tokens_for_quality(quality, floor=1800, ceiling=4500)


def build_article_completion_prompt(quality, article_type, has_decoration=False):
    target = effective_target_chars(quality)
    minimum = minimum_required_content_chars(quality)
    upper = max(target, int(target * 1.15))
    normalized_type = normalize_article_type(article_type, 'ranking')
    extras = []
    if has_decoration:
        extras.append('- 装飾は太字、赤字、マーカー、リスト、表だけに絞り、複雑なボックスや独自classは使わないでください。')
    if normalized_type == 'brand':
        extras.append('- 商標記事ではFAQ/よくある質問セクションは原則入れず、疑問点は口コミ・注意点・購入方法・まとめの中で自然に解消してください。')
        extras.append('- 商標記事のメリット、デメリット・注意点はH2だけで終わらせず、H3小見出しを2〜3個使って項目ごとに分けてください。')
    else:
        extras.append('- よくある質問を入れる場合は、H2「よくある質問」の直下に質問ごとのH3を置き、その下に回答段落を書いてください。')
    extra_text = '\n'.join(extras)
    priority = (
        '本文の完結、口コミ・評判、メリット・デメリット、向いている人、購入前の注意点、まとめ'
        if normalized_type == 'brand'
        else '本文の完結、商品解説、比較理由、FAQ、まとめ'
    )
    return f"""

文字量・完了条件:
- 記事本文は日本語本文換算で{target}文字前後を目標にしてください。HTMLタグや装飾量ではなく、読者が読む説明文を十分に書いてください。
- 最低でも本文換算{minimum}文字以上になるまで、途中で終了しないでください。
- 長くても本文換算{upper}文字以内を目安にし、冗長なFAQ・前置き・重複説明で水増ししないでください。
- すべての主要見出しを書き切り、最後に必ず「まとめ」セクションで記事を完結させてください。
- 途中で出力が長くなりそうな場合は、装飾の量よりも{priority}を優先してください。
{extra_text}
"""


def build_quality_structure_html_prompt(quality, limit=6000):
    html = str((quality or {}).get('structure_html') or '').strip()
    if not html:
        return ''
    if len(html) > limit:
        html = html[:limit] + '\n\n...（構成HTMLが長いため後半を省略）'
    return f"""

記事構成HTMLの参考:
- 以下は完成記事のHTML構成見本です。内容、固有名詞、口コミ、価格、リンク、商品名、事実関係は流用しないでください。
- 見出し階層、ブロック順、比較表の位置、FAQやまとめへの流れだけを参考にしてください。
- 今回の記事テーマに合わない見出しや要素は無理に使わず、自然な構成へ調整してください。

```html
{html}
```
"""


def build_article_continuation_prompt(article, article_type, quality, current_content, validation_error):
    target = effective_target_chars(quality)
    minimum = minimum_required_content_chars(quality)
    current_chars = len(html_to_text(current_content))
    remaining = max(800, target - current_chars)
    current_tail = str(current_content or '')[-18000:]
    normalized_type = normalize_article_type(article_type, 'ranking')
    priority = (
        '未完了の口コミ・評判、メリット・デメリット、向いている人、購入/申込方法、まとめ'
        if normalized_type == 'brand'
        else '未完了のランキング個別解説、選び方、FAQ、まとめ'
    )
    no_faq = '- 商標記事ではFAQ/よくある質問見出しを追加しないでください。\n' if normalized_type == 'brand' else ''
    heading_rule = (
        '- メリット、デメリット・注意点の続きではH3小見出しを使い、項目ごとに本文を分けてください。'
        if normalized_type == 'brand'
        else '- FAQを書く場合は質問ごとにH3見出しを使ってください。'
    )
    return f"""以下の記事本文は途中で終わっているか、品質チェックに未達です。

タイトル: {article.get('title', '')}
キーワード: {article.get('keywords', '')}
カテゴリー: {article.get('category', '')}
記事種別: {article_type}
現在の本文文字数: {current_chars}文字
目標本文文字数: {target}文字前後
最低本文文字数: {minimum}文字以上
未達理由: {validation_error}

やること:
- 既存本文の続きを、WordPress本文HTMLとして出力してください。
- すでに書かれている文章・見出し・比較表・ランキング項目を繰り返さないでください。
- 出力は「続きのHTML本文だけ」にしてください。説明文、作業メモ、Markdown、コードフェンスは不要です。
- {priority}を優先して書き切ってください。
{no_faq.rstrip()}
- {heading_rule}
- 最後は必ず「まとめ」セクションで完結させてください。
- 追加本文は日本語本文換算で最低{remaining}文字を目安にしてください。

{build_ranking_count_prompt(article, article_type)}
{build_ranking_structure_prompt(article, article_type)}
{article_html_output_rules()}

現在までの本文（この続きだけを書く。重複禁止）:
{current_tail}
"""


def build_article_polish_prompt(article, article_type, quality, current_content, warning_text=''):
    target = effective_target_chars(quality)
    minimum = minimum_required_content_chars(quality)
    current_chars = len(html_to_text(current_content))
    current_tail = str(current_content or '')[-20000:]
    normalized_type = normalize_article_type(article_type, 'ranking')
    default_warning = (
        '導入、口コミ・評判、判断材料、購入前の注意点、まとめを読みやすく整える'
        if normalized_type == 'brand'
        else '導入、見出し、判断材料、FAQ、まとめを読みやすく整える'
    )
    no_faq = '- 商標記事ではFAQ/よくある質問見出しを追加しないでください。疑問点は本文内に吸収してください。\n' if normalized_type == 'brand' else ''
    heading_rule = (
        '- メリット、デメリット・注意点はH2だけで終わらせず、H3小見出しを2〜3個使って項目ごとに整理してください。'
        if normalized_type == 'brand'
        else '- よくある質問を入れる場合は、質問ごとにH3見出しを使ってください。'
    )
    return f"""以下のWordPress本文HTMLを、記事として完成度が高い状態へ整えてください。

タイトル: {article.get('title', '')}
キーワード: {article.get('keywords', '')}
カテゴリー: {article.get('category', '')}
記事種別: {article_type}
現在の本文文字数: {current_chars}文字
目標本文文字数: {target}文字前後
最低本文文字数: {minimum}文字以上
改善理由・不足:
{warning_text or default_warning}

やること:
- 既存本文の内容を活かし、WordPress本文HTMLとして全文を出力してください。
- 文字数が不足している場合は、重複せずに不足分を追加してください。
- 導入で結論を早めに示し、メリット・デメリット・注意点・向いている人を補強してください。
- 最後は必ず「まとめ」セクションで完結させてください。
{no_faq.rstrip()}
- {heading_rule}
- 商品リンクや広告カードは新規作成しないでください。装飾は太字・赤字・マーカー・リスト・表だけに絞ってください。
- 説明文、作業メモ、Markdown、コードフェンスは禁止です。

{build_article_type_prompt(article_type)}
{build_ranking_count_prompt(article, article_type)}
{build_ranking_structure_prompt(article, article_type)}
{article_html_output_rules()}

現在の本文HTML:
{current_tail}
"""


def anthropic_message_text(message):
    parts = []
    for block in getattr(message, 'content', []) or []:
        if isinstance(block, dict):
            text = block.get('text') or ''
        else:
            text = getattr(block, 'text', '') or ''
        if text:
            parts.append(text)
    return ''.join(parts)


def extract_json_object(text):
    raw = str(text or '').strip()
    raw = re.sub(r'^\s*```(?:json)?\s*', '', raw, flags=re.I)
    raw = re.sub(r'\s*```\s*$', '', raw)
    start = raw.find('{')
    end = raw.rfind('}')
    if start >= 0 and end > start:
        raw = raw[start:end + 1]
    try:
        return json.loads(raw)
    except Exception:
        return {}


def ranking_subject(article):
    inferred = infer_ad_keywords_from_title(
        article.get('title', ''),
        article.get('keywords', ''),
        'ranking'
    )
    return inferred or article.get('keywords') or article.get('category') or article.get('title') or '商品'


def coerce_plan_list(value, limit=None):
    if isinstance(value, list):
        items = value
    elif value:
        items = [value]
    else:
        items = []
    if limit:
        items = items[:limit]
    return items


def coerce_ranking_plan(article, raw_plan):
    count = extract_ranking_count(article) or 7
    subject = ranking_subject(article)
    plan = raw_plan if isinstance(raw_plan, dict) else {}
    defaults = {
        'criteria': [
            {'name': '保温性', 'description': '素材や厚みだけでなく、首元に熱を逃がしにくい構造かを確認します。'},
            {'name': '防風性', 'description': '自転車や屋外作業では、風を通しにくい生地や二重構造が重要です。'},
            {'name': '肌触り', 'description': '長時間使うものなので、チクチク感や締め付け感の少なさを見ます。'},
            {'name': '使いやすさ', 'description': '着脱のしやすさ、洗いやすさ、通勤やスポーツへの合わせやすさを評価します。'},
            {'name': '価格とのバランス', 'description': '安さだけでなく、価格に対して十分な機能があるかを重視します。'},
        ],
        'faqs': [
            {'question': f'{subject}は安いものでも十分使えますか？', 'answer': '用途に合う素材と構造を選べば、低価格帯でも日常使いには十分対応できます。'},
            {'question': '通勤とスポーツで同じものを使えますか？', 'answer': '使えますが、汗をかく場面では速乾性、通勤では防風性や見た目の自然さを重視すると失敗しにくくなります。'},
            {'question': '迷ったらどのタイプを選ぶべきですか？', 'answer': '最初の一枚なら、防風性と肌触りのバランスが良い汎用タイプを選ぶのがおすすめです。'},
        ]
    }
    products = []
    raw_products = coerce_plan_list(plan.get('products'), count)
    for i in range(count):
        item = raw_products[i] if i < len(raw_products) and isinstance(raw_products[i], dict) else {}
        rank = i + 1
        name = str(item.get('name') or item.get('product_name') or f'{subject} 候補{rank}').strip()
        products.append({
            'rank': rank,
            'name': name,
            'feature': str(item.get('feature') or item.get('summary') or f'{subject}として使いやすい基本性能を備えた候補です。').strip(),
            'price_band': str(item.get('price_band') or item.get('price') or '2,000円台目安').strip(),
            'best_for': str(item.get('best_for') or item.get('recommended_for') or '日常使いで失敗したくない人').strip(),
            'reason': str(item.get('reason') or item.get('ranking_reason') or '価格と使いやすさのバランスがよく、はじめて選ぶ人でも検討しやすいためです。').strip(),
            'strengths': str(item.get('strengths') or item.get('merit') or '普段使いしやすく、用途を選びにくい点が魅力です。').strip(),
            'cautions': str(item.get('cautions') or item.get('caution') or '本格的な極寒環境では、厚みや防風性を追加で確認してください。').strip(),
            'use_case': str(item.get('use_case') or item.get('scene') or '通勤、買い物、軽い外出など幅広いシーン').strip(),
            'comparison_note': str(item.get('comparison_note') or item.get('compare') or '上位候補ほど保温性や扱いやすさのバランスを重視しています。').strip(),
        })
    criteria = coerce_plan_list(plan.get('criteria'), 5) or defaults['criteria']
    normalized_criteria = []
    for item in criteria[:5]:
        if isinstance(item, dict):
            normalized_criteria.append({
                'name': str(item.get('name') or '選定基準').strip(),
                'description': str(item.get('description') or item.get('detail') or '').strip(),
            })
        else:
            normalized_criteria.append({'name': str(item), 'description': ''})
    while len(normalized_criteria) < 5:
        normalized_criteria.append(defaults['criteria'][len(normalized_criteria)])
    faqs = coerce_plan_list(plan.get('faqs') or plan.get('faq'), 5) or defaults['faqs']
    normalized_faqs = []
    for item in faqs[:5]:
        if isinstance(item, dict):
            normalized_faqs.append({
                'question': str(item.get('question') or item.get('q') or 'よくある質問').strip(),
                'answer': str(item.get('answer') or item.get('a') or '').strip(),
            })
        else:
            normalized_faqs.append({'question': str(item), 'answer': ''})
    while len(normalized_faqs) < 3:
        normalized_faqs.append(defaults['faqs'][len(normalized_faqs)])
    return {
        'subject': subject,
        'count': count,
        'lead_angle': str(plan.get('lead_angle') or plan.get('intro_angle') or f'{subject}を価格だけで選ぶと、暖かさや使いやすさで後悔しやすくなります。').strip(),
        'products': products,
        'criteria': normalized_criteria,
        'faqs': normalized_faqs,
    }


def ranking_plan_prompt(article, quality):
    count = extract_ranking_count(article) or 7
    subject = ranking_subject(article)
    return f"""ランキング記事の設計データをJSONだけで返してください。

タイトル: {article.get('title', '')}
キーワード: {article.get('keywords', '')}
カテゴリー: {article.get('category', '')}
主題: {subject}
必要件数: {count}

返却形式:
{{
  "lead_angle": "読者の悩みと結論の方向性",
  "criteria": [
    {{"name": "基準名", "description": "評価する理由"}}
  ],
  "products": [
    {{
      "rank": 1,
      "name": "商品名または候補名",
      "feature": "短い特徴",
      "price_band": "価格目安",
      "best_for": "向いている人",
      "reason": "順位理由",
      "strengths": "良い点",
      "cautions": "注意点",
      "use_case": "おすすめシーン",
      "comparison_note": "他候補との違い"
    }}
  ],
  "faqs": [
    {{"question": "質問", "answer": "回答の要点"}}
  ]
}}

ルール:
- products は必ず{count}件。
- rank は1から{count}まで欠番なし。
- 事実確認できない断定や架空のレビュー数は入れない。
- JSON以外の説明文、Markdown、コードフェンスは禁止。"""


def generate_ranking_plan(client, article, quality):
    prompt = ranking_plan_prompt(article, quality)
    message = create_claude_message(client, prompt, max_tokens=3500, timeout=45)
    text = anthropic_message_text(message)
    return coerce_ranking_plan(article, extract_json_object(text)), build_article_usage(prompt, text, message)


def html_p(text):
    return f'<p>{escape(str(text or ""))}</p>'


def html_li(text):
    return f'<li>{escape(str(text or ""))}</li>'


def build_structured_ranking_html(article, plan):
    subject = plan['subject']
    count = plan['count']
    products = plan['products']
    title = article.get('title') or f'{subject}おすすめ{count}選'
    html = []
    html.append(html_p(f'「{subject}をできるだけ失敗なく選びたい」「価格を抑えつつ、使いやすいものを見つけたい」と考えている方は多いのではないでしょうか。{plan["lead_angle"]}'))
    html.append(html_p(f'この記事では、{subject}を選ぶときに確認したい基準を整理しながら、用途や価格とのバランスを見ておすすめ候補を{count}件紹介します。比較表、順位ごとの理由、選び方、FAQまでまとめているので、購入前の判断材料として使えます。'))
    html.append('<h2 class="wp-block-heading">この記事でわかること</h2>')
    html.append('<ul>')
    html.append(html_li(f'{subject}を選ぶときの重要な比較ポイント'))
    html.append(html_li(f'コスパ重視で検討しやすい{count}候補の違い'))
    html.append(html_li('迷ったときにどのタイプを選ぶべきか'))
    html.append('</ul>')

    html.append(f'<h2 class="wp-block-heading">まず結論：{subject}おすすめ早見表</h2>')
    html.append(html_p('先に全体像を確認できるよう、特徴と向いている人を一覧にしました。詳しい理由は後半の個別解説で確認できます。'))
    html.append('<table><thead><tr><th>順位</th><th>商品名</th><th>特徴</th><th>価格目安</th><th>向いている人</th></tr></thead><tbody>')
    for p in products:
        html.append(
            '<tr>'
            f'<td>{p["rank"]}位</td>'
            f'<td>{escape(p["name"])}</td>'
            f'<td>{escape(p["feature"])}</td>'
            f'<td>{escape(p["price_band"])}</td>'
            f'<td>{escape(p["best_for"])}</td>'
            '</tr>'
        )
    html.append('</tbody></table>')

    html.append(f'<h2 class="wp-block-heading">ランキングの選定基準｜なぜこの{count}つを選んだのか</h2>')
    html.append(html_p('順位は価格の安さだけではなく、実際に使う場面で差が出やすいポイントを総合的に見て決めています。特に以下の基準を重視しました。'))
    html.append('<ul class="wp-block-list">')
    for c in plan['criteria']:
        html.append(html_li(f'{c["name"]}: {c["description"]}'))
    html.append('</ul>')

    html.append(f'<h2 class="wp-block-heading">{subject}おすすめ{count}選</h2>')
    for p in products:
        html.append(f'<h3 class="wp-block-heading">{p["rank"]}位：{escape(p["name"])}</h3>')
        html.append(html_p(f'{p["name"]}は、{p["feature"]}という特徴がある候補です。{p["best_for"]}に向いており、{p["use_case"]}で使う場面を想定すると検討しやすい一品です。'))
        html.append(html_p(f'{p["rank"]}位にした理由は、{p["reason"]}。{p["strengths"]} 価格だけで選ぶのではなく、使うシーンとの相性まで見ると、この候補の良さが分かりやすくなります。'))
        html.append(html_p(f'一方で、{p["cautions"]} {p["comparison_note"]} 購入前にはサイズ感、素材、洗濯方法、着用シーンを確認しておくと失敗を避けやすくなります。'))
        html.append('<ul class="wp-block-list">')
        html.append(html_li(f'おすすめな人: {p["best_for"]}'))
        html.append(html_li(f'主なシーン: {p["use_case"]}'))
        html.append(html_li(f'注意点: {p["cautions"]}'))
        html.append('</ul>')

    html.append(f'<h2 class="wp-block-heading">{subject}の選び方</h2>')
    html.append(html_p(f'{subject}を選ぶときは、ランキング順位だけでなく、自分の使い方に合うかどうかを見ることが大切です。ここでは失敗しにくい選び方を整理します。'))
    for c in plan['criteria']:
        html.append(f'<h3 class="wp-block-heading">{escape(c["name"])}を確認する</h3>')
        html.append(html_p(f'{c["description"]} 特に毎日使う場合は、短時間の印象だけでなく、着用時間、保管のしやすさ、手入れのしやすさまで含めて考えると選びやすくなります。'))

    html.append('<h2 class="wp-block-heading">よくある質問</h2>')
    for faq in plan['faqs']:
        html.append(f'<h3 class="wp-block-heading">Q. {escape(faq["question"])}</h3>')
        html.append(html_p(f'A. {faq["answer"]} 迷った場合は、価格だけでなく用途、素材、サイズ感を見比べてください。使用シーンが明確になるほど、自分に合う候補を選びやすくなります。'))

    html.append('<h2 class="wp-block-heading">まとめ</h2>')
    html.append(html_p(f'{title}について、比較表とランキング形式で{count}件を紹介しました。まずは自分が重視するポイントを決め、保温性・使いやすさ・価格とのバランスを見ながら選ぶのがおすすめです。'))
    html.append(html_p(f'迷ったときは、上位候補から用途に合うものを選ぶと失敗しにくくなります。購入前には最新価格、サイズ、素材、レビュー傾向を確認し、自分の使い方に合う{subject}を選んでください。'))
    return '\n'.join(html)


def generate_structured_ranking_article_sync(client, article, quality, on_step=None):
    if on_step:
        on_step(1, 2, 'ランキング設計データ')
    try:
        plan, usage = generate_ranking_plan(client, article, quality)
    except Exception as e:
        prompt = ranking_plan_prompt(article, quality)
        plan = coerce_ranking_plan(article, {})
        usage = build_article_usage(prompt, '')
        usage['structured_builder_fallback'] = True
        usage['plan_error'] = str(e)
    if on_step:
        on_step(2, 2, '固定骨組みHTML')
    content = build_structured_ranking_html(article, plan)
    usage['structured_builder'] = True
    return content, [usage]


def generate_structured_ranking_article_sse(client, article, quality):
    yield f"data: {json.dumps({'status': 'segment', 'round': 1, 'total': 2, 'message': 'ランキング設計データを生成しています（1/2）'})}\n\n"
    prompt = ranking_plan_prompt(article, quality)
    try:
        plan_text, message = yield from stream_claude_sse(
            client,
            prompt,
            'ランキング設計データをClaudeから取得中です。処理は継続しています。',
            emit_text=False,
            max_tokens=3500
        )
        plan = coerce_ranking_plan(article, extract_json_object(plan_text))
        usage = build_article_usage(prompt, plan_text, message)
    except Exception as e:
        yield f"data: {json.dumps({'status': 'segment_fallback', 'round': 1, 'total': 2, 'message': 'ランキング設計データの取得に失敗したため、固定構成で生成を継続します。'})}\n\n"
        plan = coerce_ranking_plan(article, {})
        usage = build_article_usage(prompt, '')
        usage['structured_builder_fallback'] = True
        usage['plan_error'] = str(e)
    yield f"data: {json.dumps({'status': 'segment', 'round': 2, 'total': 2, 'message': 'Affiros9側でランキングHTMLを組み立てています（2/2）'})}\n\n"
    content = build_structured_ranking_html(article, plan)
    usage['structured_builder'] = True
    for offset in range(0, len(content), 4000):
        yield f"data: {json.dumps({'text': content[offset:offset + 4000]})}\n\n"
    return content, [usage]


def should_use_segmented_generation(article_type, quality=None):
    normalized = normalize_article_type(article_type, 'ranking')
    target = effective_target_chars(quality)
    if normalized == 'ranking':
        return target >= 6000
    return normalized in ('brand', 'column') and target >= 7000


def build_segmented_article_steps(article, article_type):
    normalized = normalize_article_type(article_type, 'ranking')
    if normalized == 'ranking':
        count = extract_ranking_count(article) or 7
        steps = [{
            'name': '導入・早見表・比較表',
            'prompt': f"""リード文、この記事でわかること、結論早見表、比較表だけを書いてください。
- リード文は250〜350文字。
- 比較表は必ずヘッダーを除いて{count}行にしてください。
- 日本語本文換算で900〜1200文字を目安にしてください。
- 比較表のあとにランキング本文へ入らず、ここで止めてください。"""
        }]
        chunk_size = 2
        for start in range(1, count + 1, chunk_size):
            end = min(count, start + chunk_size - 1)
            steps.append({
                'name': f'ランキング個別解説 {start}〜{end}位',
                'prompt': f"""ランキング本文のうち、{start}位から{end}位までの個別解説だけを書いてください。
- 必ず <h3 class="wp-block-heading">{start}位：商品名</h3> から順に書いてください。
- {start}〜{end}位の順位番号を欠番・重複なしで入れてください。
- 各商品の解説は、特徴・おすすめな人・注意点・他候補との違いを含めて最低2段落以上。
- この工程全体で日本語本文換算900〜1200文字を目安にしてください。
- 比較表やリード文は繰り返さないでください。
- {end}位を書き終えたら、選び方やFAQへ進まず止めてください。"""
            })
        steps.append({
            'name': '選び方・FAQ・まとめ',
            'prompt': """選び方、購入前の注意点、FAQ、まとめだけを書いてください。
- H2「選び方」を入れ、素材・価格・用途・サイズ感など判断軸を整理してください。
- FAQは3〜5問。質問ごとにH3見出しを使い、その下に回答段落を書いてください。
- 日本語本文換算で900〜1200文字を目安にしてください。
- 最後に必ずH2「まとめ」を入れ、読者の次の行動まで示して記事を完結させてください。
- ランキング個別解説は繰り返さないでください。"""
        })
        return steps

    if normalized == 'brand':
        return [
            {
                'name': '導入・結論・基本情報',
                'prompt': """リード文、先に結論、商品/サービスの基本情報を書いてください。
- リード文は250〜350文字。
- 読者が最初に知りたい結論を明確にする。
- 口コミや評判の章にはまだ進まないでください。"""
            },
            {
                'name': '特徴・メリット・デメリット',
                'prompt': """特徴、メリット、デメリット、向いている人/向いていない人を書いてください。
- H2「メリット」とH2「デメリット・注意点」を作る場合は、それぞれの中にH3小見出しを2〜3個入れて項目別に説明してください。
- 押し売りではなく、判断材料として書く。
- 既出の導入や基本情報を繰り返さないでください。"""
            },
            {
                'name': '評判・比較・注意点',
                'prompt': """口コミ/評判の見方、競合や代替との比較、購入/申込前の注意点を書いてください。
- 良い面と悪い面の両方を扱う。
- 根拠のない断定は避けてください。"""
            },
            {
                'name': '購入前の注意点・まとめ',
                'prompt': """購入/申込前の注意点とまとめを書いて記事を完結させてください。
- FAQ/よくある質問セクションは作らないでください。
- 読者の疑問は注意点、購入方法、向いている人の整理、まとめの中で自然に解消してください。
- 最後にH2「まとめ」を入れ、どんな人におすすめかを再整理してください。"""
            },
        ]

    return [
        {
            'name': '導入・問題提起',
            'prompt': """リード文、読者の悩み、記事で解決することを書いてください。
- リード文は250〜350文字。
- 背景説明に入りすぎず、読者の検索意図を明確にしてください。"""
        },
        {
            'name': '原因・背景・基礎知識',
            'prompt': """悩みの原因、背景、知っておくべき基礎知識を書いてください。
- 専門用語は噛み砕いて説明する。
- 解決策の章にはまだ進みすぎないでください。"""
        },
        {
            'name': '解決策・具体例',
            'prompt': """具体的な解決策、手順、例、注意点を書いてください。
- 読者が実行できる粒度にする。
- 必要ならチェックリストや表を使ってください。"""
        },
        {
            'name': 'FAQ・まとめ',
            'prompt': """FAQとまとめを書いて記事を完結させてください。
- FAQは3〜5問。質問ごとにH3見出しを使い、その下に回答段落を書いてください。
- 最後にH2「まとめ」を入れ、次の行動を明確にしてください。"""
        },
    ]


def build_segment_common_context(base_prompt):
    if not base_prompt:
        return ''
    text = str(base_prompt)
    limit = 8000
    if len(text) > limit:
        head = text[:5500]
        tail = text[-2000:]
        text = f"{head}\n\n...（中略）...\n\n{tail}"
    return f"""

共通追加指示・広告/装飾/参考情報:
{text}
"""


def build_segment_prompt(base_prompt, article, article_type, quality, step, index, total, current_content):
    section_target = segment_target_chars(quality, total)
    previous_tail = str(current_content or '')[-14000:]
    quality_prompt = build_quality_prompt(quality)
    common_context = build_segment_common_context(base_prompt)
    main_keyword = primary_article_keyword({**article, 'article_type': article_type})
    return f"""WordPressに投稿する記事本文の一部を書いてください。

タイトル: {article.get('title', '')}
キーワード: {article.get('keywords', '')}
カテゴリー: {article.get('category', '')}
狙う主要KW: {main_keyword}

品質要件:
{quality_prompt}

{build_article_type_prompt(article_type)}
{build_ranking_count_prompt(article, article_type)}
{build_ranking_structure_prompt(article, article_type)}
{article_html_output_rules()}
{common_context}

分割生成モード:
- 記事全体ではなく、指定された今回の範囲だけを書いてください。
- 出力はWordPress本文HTMLのみ。説明文、作業メモ、Markdown、コードフェンスは禁止。
- 既に書いた内容を繰り返さず、現在までの本文の続きとして自然につなげてください。
- この分割記事は全{total}工程中の{index}工程目です。
- 今回の出力目安は日本語本文換算で{section_target}文字前後です。
- 今回の範囲を書き切るまで途中で止めないでください。
- Gutenbergコメント（<!-- wp:... -->）は出力しないでください。
- h2/h3見出しには、できるだけ狙う主要KW「{main_keyword}」を自然に含めてください。
- <p>は長くしすぎず、2〜3文ごとに分けてください。長い説明は段落を増やしてください。
- 重要な結論・注意点・選び方の要点には、太字、赤字、マーカー、リスト、表だけを自然に使ってください。
- 広告カード、アフィリエイトリンク、RINKER風の商品カードは作らないでください。広告挿入はWordPress側のプラグインに任せます。

現在までの本文（重複禁止・文脈確認用）:
{previous_tail}

今回書く範囲: {step.get('name')}
{step.get('prompt')}
"""


def segment_target_chars(quality, total):
    target = effective_target_chars(quality)
    return max(900, min(1300, math.ceil(target / max(total, 1))))


def segment_minimum_chars(quality, total):
    target = segment_target_chars(quality, total)
    return max(750, math.ceil(target * 0.78))


def build_segment_continuation_prompt(article, article_type, quality, step, index, total, current_content, segment_text, min_chars):
    segment_chars = len(html_to_text(segment_text))
    previous_tail = str(current_content or '')[-12000:]
    segment_tail = str(segment_text or '')[-10000:]
    return f"""分割生成の今回工程が短すぎます。今回工程の続きを追記してください。

タイトル: {article.get('title', '')}
記事種別: {article_type}
工程: {step.get('name')}（{index}/{total}）
今回工程の現在文字数: {segment_chars}文字
今回工程の最低文字数: {min_chars}文字以上

やること:
- 出力は今回工程の「続きのHTML本文だけ」にしてください。
- 既に書いた内容を繰り返さないでください。
- ほかの工程へ進みすぎず、今回工程の範囲を深掘りしてください。
- 商品解説・比較理由・具体例・注意点・FAQなど、読者判断に必要な本文を足してください。
- Gutenbergコメント、Markdown、作業メモは禁止です。

今回工程の指示:
{step.get('prompt')}

現在までの記事本文（文脈確認用）:
{previous_tail}

今回工程で既に書いた本文（この続きだけを書く）:
{segment_tail}
"""


def generate_segmented_article_sync(client, base_prompt, article, article_type, quality, on_step=None):
    steps = build_segmented_article_steps(article, article_type)
    full_content = ''
    usage_parts = []
    total = len(steps)
    segment_max_tokens = claude_segment_max_tokens(quality, total)
    for index, step in enumerate(steps, 1):
        if on_step:
            on_step(index, total, step.get('name', ''))
        segment_prompt = build_segment_prompt(base_prompt, article, article_type, quality, step, index, total, full_content)
        message = create_claude_message(client, segment_prompt, max_tokens=segment_max_tokens)
        text = anthropic_message_text(message)
        usage_parts.append(build_article_usage(segment_prompt, text, message))
        min_chars = segment_minimum_chars(quality, total)
        continuation_round = 0
        while len(html_to_text(text)) < min_chars and continuation_round < CLAUDE_SEGMENT_CONTINUATION_MAX_ROUNDS:
            continuation_round += 1
            if on_step:
                on_step(index, total, f"{step.get('name', '')} の追記 {continuation_round}")
            continuation_prompt = build_segment_continuation_prompt(
                article,
                article_type,
                quality,
                step,
                index,
                total,
                full_content,
                text,
                min_chars
            )
            continuation_message = create_claude_message(client, continuation_prompt, max_tokens=segment_max_tokens)
            continuation_text = anthropic_message_text(continuation_message)
            usage_parts.append(build_article_usage(continuation_prompt, continuation_text, continuation_message))
            if not html_to_text(continuation_text).strip():
                break
            text += '\n' + continuation_text
        full_content += ('\n' if full_content else '') + text
    return full_content, usage_parts


def generate_segmented_article_sse(client, base_prompt, article, article_type, quality):
    steps = build_segmented_article_steps(article, article_type)
    full_content = ''
    usage_parts = []
    total = len(steps)
    segment_max_tokens = claude_segment_max_tokens(quality, total)
    for index, step in enumerate(steps, 1):
        name = step.get('name', '')
        yield f"data: {json.dumps({'status': 'segment', 'round': index, 'total': total, 'message': f'分割生成中: {name}（{index}/{total}）'})}\n\n"
        if full_content:
            full_content += '\n'
            yield f"data: {json.dumps({'text': '\\n'})}\n\n"
        segment_prompt = build_segment_prompt(base_prompt, article, article_type, quality, step, index, total, full_content)
        text, message = yield from stream_claude_sse(
            client,
            segment_prompt,
            f'分割生成中: {name}（{index}/{total}）。Claude応答待ちです。',
            max_tokens=segment_max_tokens
        )
        usage_parts.append(build_article_usage(segment_prompt, text, message))
        min_chars = segment_minimum_chars(quality, total)
        continuation_round = 0
        while len(html_to_text(text)) < min_chars and continuation_round < CLAUDE_SEGMENT_CONTINUATION_MAX_ROUNDS:
            continuation_round += 1
            yield f"data: {json.dumps({'status': 'segment_continuing', 'round': index, 'total': total, 'segment_retry': continuation_round, 'message': f'{name} の本文が短いため追記しています（{continuation_round}/{CLAUDE_SEGMENT_CONTINUATION_MAX_ROUNDS}）'})}\n\n"
            continuation_prompt = build_segment_continuation_prompt(
                article,
                article_type,
                quality,
                step,
                index,
                total,
                full_content,
                text,
                min_chars
            )
            yield f"data: {json.dumps({'text': '\\n'})}\n\n"
            continuation_text, continuation_message = yield from stream_claude_sse(
                client,
                continuation_prompt,
                f'{name} の追記を生成中です。Claude応答待ちです。',
                max_tokens=segment_max_tokens
            )
            usage_parts.append(build_article_usage(continuation_prompt, continuation_text, continuation_message))
            if not html_to_text(continuation_text).strip():
                break
            text += '\n' + continuation_text
        full_content += text
    return full_content, usage_parts


def stream_claude_sse(client, prompt, wait_message='Claudeの応答を待っています。', emit_text=True, max_tokens=None):
    events = queue.Queue()

    def worker():
        try:
            with client.messages.stream(
                model=CLAUDE_ARTICLE_MODEL,
                max_tokens=max_tokens or CLAUDE_ARTICLE_MAX_TOKENS,
                messages=[{"role": "user", "content": prompt}]
            ) as stream:
                for text in stream.text_stream:
                    events.put(('text', text))
                try:
                    final_message = stream.get_final_message()
                except Exception:
                    final_message = None
            events.put(('done', final_message))
        except Exception as e:
            events.put(('error', e))

    threading.Thread(target=worker, daemon=True).start()
    content = ''
    while True:
        try:
            kind, value = events.get(timeout=8)
        except queue.Empty:
            yield f"data: {json.dumps({'status': 'heartbeat', 'message': wait_message})}\n\n"
            continue
        if kind == 'text':
            content += value
            if emit_text:
                yield f"data: {json.dumps({'text': value})}\n\n"
            continue
        if kind == 'error':
            raise value
        if kind == 'done':
            return content, value


def build_regeneration_instruction(previous_content):
    if not previous_content or not html_to_text(previous_content).strip():
        return ''
    return f"""

再生成モード:
- これは既存記事の「再生成」です。下に旧本文があります。
- 旧本文の文章・見出し順・言い回し・比較表をそのまま流用せず、検索意図から逆算して全面的に書き直してください。
- 旧本文の良くない点を改善し、導入、見出し構成、比較軸、結論、CTAを作り直してください。
- 商品数や記事種別の品質条件は必ず守ってください。
- 出力は新しい記事本文のみ。旧本文との差分説明、作業メモ、前置きは出力しないでください。

旧本文（参考。コピー禁止）:
{previous_content[:25000]}
"""


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


def validate_generated_article(article, article_type, content, quality=None):
    content_chars = len(html_to_text(content))
    min_chars = minimum_required_content_chars(quality)
    if content_chars < min_chars:
        return f'生成本文が短すぎます（{content_chars}文字）。目標は{effective_target_chars(quality)}文字前後、最低{min_chars}文字以上です。途中で止まっている可能性が高いため保存しません。'

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
- メリットとデメリット・注意点はH2の下にH3小見出しを置き、項目ごとに本文を分ける
- FAQ/よくある質問セクションは原則作らず、疑問点は本文内で自然に解消する
- 押し売りではなく、判断材料を丁寧に提示する""",
        'column': """記事種類: コラム記事
- 読者の悩みや疑問に対して、自然な読み物として理解を深める構成にする
- 導入、背景、具体例、解決策、まとめを自然につなげる
- アフィリエイト導線は必要な場所にだけ控えめに入れる""",
    }
    return prompts.get(article_type, '')


def build_quality_prompt(quality):
    if not quality:
        quality = {}
    parts = []
    base = quality.get('prompt', '')
    if base:
        parts.append(base)
    target = effective_target_chars(quality)
    parts.append(f"目標文字数: {target}文字前後を目安にしてください。")
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


def quality_style_reference_url(article_type, settings, quality=None):
    quality_url = str((quality or {}).get('reference_url') or '').strip()
    if quality_url:
        return quality_url
    refs = settings.get('quality_style_references') or {}
    normalized = normalize_article_type(article_type, 'ranking')
    return (refs.get(normalized) or '').strip()


def fetch_quality_style_reference(article_type, settings, quality=None):
    url = quality_style_reference_url(article_type, settings, quality)
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
        'light': '太字、マーカー、リストを軽く使う',
        'standard': '太字、赤字、マーカー、リスト、表だけで読みやすく整える',
        'rich': '太字、赤字、マーカー、リスト、表を使うが、複雑なボックスや独自classは使わない',
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

    if safe_article_css(settings.get('article_css')):
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
@app.route('/title-ideas')
@app.route('/batch')
@app.route('/rewrite')
@app.route('/history')
@app.route('/articles')
@app.route('/quality')
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


# Title ideas
@app.route('/api/title-ideas/generate', methods=['POST'])
@login_required
def generate_title_ideas():
    try:
        data = request.get_json(silent=True) or {}
    except Exception:
        data = {}
    keywords = split_title_keywords(data.get('keywords', ''))
    count_per_keyword = clamp_int(data.get('count_per_keyword'), 3, 1, 5)
    category = str(data.get('category') or '').strip()
    site_id = data.get('site_id') or ''

    def sse(obj):
        return f"data: {json.dumps(obj, ensure_ascii=False)}\n\n"

    def stream():
        if not keywords:
            yield sse(title_ideas_failure_payload('キーワードを1行以上入力してください', keywords))
            return

        try:
            settings = load_settings()
        except Exception as e:
            app.logger.warning('Title idea settings load failed: %s', e)
            settings = {}

        claude_key = settings.get('claude_api_key')
        if not claude_key:
            yield sse(title_ideas_failure_payload(
                'タイトル案生成にはClaude APIキーが必要です。テンプレ生成には切り替えません。',
                keywords,
            ))
            return

        result_queue = queue.Queue()
        expected_count = len(keywords) * count_per_keyword
        batches = [keywords[i:i + TITLE_IDEA_BATCH_SIZE] for i in range(0, len(keywords), TITLE_IDEA_BATCH_SIZE)]

        def worker():
            try:
                all_ideas = []
                batch_errors = []
                model_used = CLAUDE_TITLE_IDEA_MODEL
                last_error = None
                completed = 0
                if len(batches) > 1:
                    result_queue.put(('progress', f'Claudeでタイトル案を生成中 (0/{len(batches)}バッチ完了)'))
                max_workers = min(TITLE_IDEA_PARALLEL_BATCHES, len(batches))
                with ThreadPoolExecutor(max_workers=max_workers) as executor:
                    future_to_idx = {
                        executor.submit(generate_claude_title_ideas_once, claude_key, batch, count_per_keyword, category): (idx, batch)
                        for idx, batch in enumerate(batches, 1)
                    }
                    for future in as_completed(future_to_idx):
                        idx, batch = future_to_idx[future]
                        completed += 1
                        try:
                            batch_ideas, m = future.result()
                            all_ideas.extend(batch_ideas)
                            model_used = m
                        except Exception as e:
                            last_error = e
                            batch_errors.append(f'バッチ{idx} ({len(batch)}KW): {compact_ai_error(e, 100)}')
                            app.logger.warning('Title idea batch %d/%d failed: %s', idx, len(batches), e)
                        if len(batches) > 1:
                            result_queue.put(('progress', f'Claudeでタイトル案を生成中 ({completed}/{len(batches)}バッチ完了 / 取得済 {len(all_ideas)}件)'))
                        if all_ideas:
                            try:
                                partial_enriched = enrich_title_ideas(list(all_ideas), category=category, site_id=site_id)
                                result_queue.put(('partial', {
                                    'success': True,
                                    'ai_used': True,
                                    'source': 'claude',
                                    'model': model_used,
                                    'keywords': keywords,
                                    'ideas': partial_enriched,
                                    'partial': True,
                                    'progress': {'completed': completed, 'total': len(batches)},
                                }))
                            except Exception as e:
                                app.logger.warning('Partial enrich failed: %s', e)
                if not all_ideas:
                    error_text = ' / '.join(batch_errors) if batch_errors else (compact_ai_error(last_error) if last_error else 'タイトル案を取得できませんでした')
                    result_queue.put(('err', error_text))
                    return
                enriched = enrich_title_ideas(all_ideas, category=category, site_id=site_id)
                payload = {
                    'success': True,
                    'ai_used': True,
                    'source': 'claude',
                    'model': model_used,
                    'keywords': keywords,
                    'ideas': enriched,
                }
                warnings = []
                if batch_errors:
                    warnings.append(f'{len(batch_errors)}/{len(batches)}バッチが失敗しました。')
                if len(enriched) < expected_count:
                    warnings.append(f'AI返却が{len(enriched)}/{expected_count}件でした。足りない分はテンプレ補完していません。')
                if warnings:
                    payload['warning'] = ' '.join(warnings)
                if batch_errors:
                    payload['provider_warnings'] = batch_errors[-5:]
                result_queue.put(('ok', payload))
            except Exception as e:
                app.logger.warning('Claude title idea generation failed: %s', e)
                result_queue.put(('err', compact_ai_error(e)))

        threading.Thread(target=worker, daemon=True).start()

        initial_msg = f'Claudeでタイトル案を生成中... ({len(keywords)}KW / {len(batches)}バッチ)' if len(batches) > 1 else 'Claudeでタイトル案を生成中...'
        yield sse({'type': 'status', 'message': initial_msg})

        heartbeat_interval = 2
        idle_timeout = 22
        idle_elapsed = 0
        while True:
            try:
                kind, payload = result_queue.get(timeout=heartbeat_interval)
            except queue.Empty:
                idle_elapsed += heartbeat_interval
                if idle_elapsed >= idle_timeout:
                    yield sse(title_ideas_failure_payload(
                        f'Claudeから{idle_timeout}秒応答がありませんでした。しばらく時間を置いて再試行してください。',
                        keywords,
                    ))
                    return
                yield f": keepalive {idle_elapsed}s\n\n"
                continue
            idle_elapsed = 0
            if kind == 'progress':
                yield sse({'type': 'status', 'message': payload})
                continue
            if kind == 'partial':
                yield sse({'type': 'partial', **payload})
                continue
            if kind == 'ok':
                yield sse(payload)
            else:
                yield sse(title_ideas_failure_payload(
                    'ClaudeでのAIタイトル案生成に失敗しました。テンプレ生成には切り替えていません。APIキー・残高・モデル状態を確認してください。',
                    keywords,
                    [payload],
                ))
            return

    def safe_stream():
        try:
            yield from stream()
        except Exception as e:
            app.logger.error('Title ideas route hard-failed: %s\n%s', e, traceback.format_exc())
            yield f"data: {json.dumps(title_ideas_failure_payload('タイトル案APIの内部処理で失敗しました。テンプレ生成には切り替えていません。', keywords, [compact_ai_error(e)]), ensure_ascii=False)}\n\n"

    return Response(
        stream_with_context(safe_stream()),
        mimetype='text/event-stream',
        headers={'Cache-Control': 'no-cache', 'X-Accel-Buffering': 'no'},
    )


@app.route('/api/title-ideas/save', methods=['POST'])
@login_required
def save_title_ideas():
    data = request.json or {}
    ideas = data.get('ideas') or []
    if not isinstance(ideas, list) or not ideas:
        return jsonify({'error': '保存するタイトル案を選択してください'}), 400
    default_category = str(data.get('category') or '').strip()
    default_site_id = data.get('site_id') or None
    default_quality_id = None
    articles = load_articles()
    existing_title_keys = {normalize_title_key(a.get('title')) for a in articles}
    created = []
    skipped = []
    for idea in ideas[:200]:
        if not isinstance(idea, dict):
            continue
        title = str(idea.get('title') or '').strip()
        if not title:
            continue
        title_key = normalize_title_key(title)
        if title_key in existing_title_keys:
            skipped.append({'title': title, 'reason': '既存タイトルと重複'})
            continue
        keyword = str(idea.get('keyword') or idea.get('keywords') or '').strip()
        article_type = coerce_title_article_type(idea.get('article_type'), keyword, title)
        now = datetime.now().isoformat()
        memo_parts = ['タイトル案から作成']
        if idea.get('search_intent'):
            memo_parts.append(f"検索意図: {idea.get('search_intent')}")
        if idea.get('reason'):
            memo_parts.append(f"理由: {idea.get('reason')}")
        article = {
            'id': str(uuid.uuid4()),
            'title': title,
            'keywords': keyword,
            'category': str(idea.get('category') or default_category),
            'slug': normalize_slug(idea.get('slug')),
            'article_type': article_type,
            'ad_keywords': infer_ad_keywords_from_title(title, keyword, article_type),
            'priority': str(idea.get('priority') or ''),
            'schedule_date': '',
            'memo': '\n'.join(memo_parts),
            'status': 'pending',
            'content': '',
            'created_at': now,
            'quality_id': default_quality_id,
            'site_id': idea.get('site_id') or default_site_id,
            'parent_article_id': None,
            'source_product_name': '',
            'wp_post_id': None,
            'wp_url': None,
            'title_idea_score': idea.get('score'),
        }
        articles.append(article)
        created.append(article)
        existing_title_keys.add(title_key)
    if created:
        save_articles(articles)
    return jsonify({
        'success': True,
        'created': len(created),
        'skipped': len(skipped),
        'articles': created,
        'skipped_items': skipped,
    })


# Articles
@app.route('/api/articles', methods=['GET'])
@login_required
def get_articles():
    articles = load_articles()
    changed = recover_stale_article_statuses(articles, load_batch_jobs())
    if ensure_article_scores_current(articles):
        changed = True
    if changed:
        save_articles(articles)
    return jsonify(articles)


@app.route('/api/articles', methods=['POST'])
@login_required
def create_article():
    data = request.json or {}
    title = str(data.get('title') or '').strip()
    if not title:
        return jsonify({'error': 'タイトルを入力してください'}), 400
    article_type = normalize_article_type(data.get('article_type'), 'ranking')
    keywords = data.get('keywords', '')
    ad_keywords = str(data.get('ad_keywords') or '').strip() or infer_ad_keywords_from_title(title, keywords, article_type)
    article = {
        'id': str(uuid.uuid4()),
        'title': title,
        'keywords': keywords,
        'category': data.get('category', ''),
        'slug': normalize_slug(data.get('slug')),
        'article_type': article_type,
        'ad_keywords': ad_keywords,
        'priority': data.get('priority', ''),
        'schedule_date': data.get('schedule_date', ''),
        'memo': data.get('memo', ''),
        'status': 'pending',
        'content': data.get('content', ''),
        'created_at': datetime.now().isoformat(),
        'quality_id': data.get('quality_id') or None,
        'site_id': data.get('site_id') or None,
        'parent_article_id': data.get('parent_article_id') or None,
        'source_product_name': data.get('source_product_name') or '',
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
                'scheduled_at', 'site_id',
                'parent_article_id', 'source_product_name'
            ]:
                if key in data:
                    a[key] = data[key]
            if 'slug' in data:
                a['slug'] = normalize_slug(data.get('slug'))
            if 'article_type' in data:
                a['article_type'] = normalize_article_type(data.get('article_type'), a.get('article_type', 'ranking'))
            if ('ad_keywords' in data or 'title' in data or 'keywords' in data) and not str(a.get('ad_keywords') or '').strip():
                a['ad_keywords'] = infer_ad_keywords_from_title(
                    a.get('title', ''),
                    a.get('keywords', ''),
                    a.get('article_type', 'ranking')
                )
            if 'content' in data:
                apply_score_fields(a)
            break
    save_articles(articles)
    return jsonify({'success': True})

@app.route('/api/articles/<article_id>/recover-generated-content', methods=['POST'])
@login_required
def recover_generated_content(article_id):
    data = request.json or {}
    clean_content = sanitize_generated_html(data.get('content', ''))
    content_chars = len(html_to_text(clean_content))
    if content_chars < 500:
        return jsonify({'success': False, 'error': f'復旧できる本文が短すぎます（{content_chars}文字）'}), 400

    articles = load_articles()
    for article in articles:
        if article['id'] != article_id:
            continue

        article_type = normalize_article_type(
            data.get('article_type') or article.get('article_type'),
            'ranking'
        )
        validation_article = dict(article)
        for key in ('title', 'keywords', 'category', 'ad_keywords', 'site_id', 'slug'):
            if key in data:
                validation_article[key] = data.get(key) or ''
        validation_article['article_type'] = article_type
        validation_quality = select_quality_definition(
            load_quality(),
            data.get('quality_id') or article.get('quality_id'),
            article_type
        )
        validation_error = validate_generated_article(validation_article, article_type, clean_content, validation_quality)
        if validation_error:
            return jsonify({'success': False, 'error': validation_error}), 400

        previous_hash = content_hash(article.get('content', ''))
        for key in ('title', 'keywords', 'category', 'ad_keywords', 'site_id', 'slug'):
            if key in data:
                article[key] = data.get(key) or ''
        if 'slug' in data:
            article['slug'] = normalize_slug(data.get('slug'))
        article['article_type'] = article_type
        if not str(article.get('ad_keywords') or '').strip():
            article['ad_keywords'] = infer_ad_keywords_from_title(
                article.get('title', ''),
                article.get('keywords', ''),
                article.get('article_type', 'ranking')
            )
        for key in ('quality_id',):
            if key in data:
                article[key] = data.get(key) or None

        now = datetime.now().isoformat()
        new_hash = content_hash(clean_content)
        article['content'] = clean_content
        article['status'] = 'generated'
        article['generated_at'] = now
        article['updated_at'] = now
        article['generation_finished_at'] = now
        article['content_hash'] = new_hash
        article['last_generation_changed'] = new_hash != previous_hash
        article['last_generation_chars'] = content_chars
        article['last_generation_recovered'] = True
        article.pop('error', None)
        article.pop('generation_warning', None)
        article.pop('last_generation_interrupted', None)
        usage = build_article_usage('', clean_content)
        usage['estimated_from_recovery'] = True
        append_generation_usage(article, usage, data.get('run_id') or str(uuid.uuid4()), now, clean_content)
        apply_score_fields(article)
        save_articles(articles)
        return jsonify({
            'success': True,
            'content_chars': content_chars,
            'changed': article['last_generation_changed'],
            'usage': usage,
        })

    return jsonify({'success': False, 'error': '記事が見つかりません'}), 404

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


@app.route('/api/batch-jobs/<job_id>', methods=['GET'])
@login_required
def get_batch_job(job_id):
    job = next((j for j in load_batch_jobs() if j.get('id') == job_id), None)
    if not job:
        return jsonify({'error': 'ジョブが見つかりません'}), 404
    return jsonify(job)


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
        article_type = normalize_article_type(cell(row, 'article_type'), 'ranking')
        keywords = cell(row, 'keywords')
        ad_keywords = cell(row, 'ad_keywords') or infer_ad_keywords_from_title(title, keywords, article_type)
        article = {
            'id': str(uuid.uuid4()),
            'title': title,
            'keywords': keywords,
            'category': cell(row, 'category'),
            'slug': normalize_slug(cell(row, 'slug')),
            'article_type': article_type,
            'ad_keywords': ad_keywords,
            'priority': cell(row, 'priority'),
            'schedule_date': cell(row, 'schedule_date'),
            'memo': cell(row, 'memo'),
            'status': 'pending',
            'content': content,
            'created_at': datetime.now().isoformat(),
            'quality_id': resolve_id(cell(row, 'quality'), quality_list),
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

    article_work = dict(article)
    for key in ('title', 'keywords', 'category', 'ad_keywords', 'site_id', 'slug'):
        if key in data:
            article_work[key] = data.get(key) or ''
    article_type = normalize_article_type(data.get('article_type') or article_work.get('article_type'), 'ranking')
    article_work['article_type'] = article_type
    if not api_key and article_type != 'ranking':
        return jsonify({'error': 'Claude APIキーが設定されていません'}), 400
    if not str(article_work.get('ad_keywords') or '').strip():
        article_work['ad_keywords'] = infer_ad_keywords_from_title(
            article_work.get('title', ''),
            article_work.get('keywords', ''),
            article_type
        )
    now = datetime.now().isoformat()
    generation_run_id = str(uuid.uuid4())
    previous_content = article.get('content', '')
    previous_content_hash = content_hash(previous_content)
    previous_content_text = html_to_text(previous_content)
    is_regeneration = bool(previous_content_text.strip())
    regeneration_instruction = build_regeneration_instruction(previous_content)
    for a in articles:
        if a['id'] == article_id:
            for key in ('title', 'keywords', 'category', 'ad_keywords', 'site_id', 'slug'):
                a[key] = article_work.get(key, '')
            a['article_type'] = article_type
            if quality_id:
                a['quality_id'] = quality_id
            a['status'] = 'generating'
            a['generation_run_id'] = generation_run_id
            a['generation_started_at'] = now
            a['updated_at'] = now
            a.pop('error', None)
            a.pop('generation_warning', None)
            a.pop('last_generation_interrupted', None)
            break
    save_articles(articles)
    quality_list = load_quality()
    quality = select_quality_definition(quality_list, quality_id, article_type)
    quality_prompt = build_quality_prompt(quality)
    article_type_prompt = build_article_type_prompt(article_type)
    ranking_count_prompt = (
        build_ranking_count_prompt(article_work, article_type) +
        build_ranking_structure_prompt(article_work, article_type)
    )
    style_reference_url = ''
    style_reference_text = ''
    try:
        style_reference_url, style_reference_text = fetch_quality_style_reference(article_type, settings, quality)
    except Exception:
        style_reference_text = ''
    def generate():
        full_content = ''
        try:
            yield f"data: {json.dumps({'status': 'started', 'run_id': generation_run_id})}\n\n"
            client = anthropic.Anthropic(api_key=api_key) if api_key else None
            prompt = f"""以下の情報をもとに、WordPressに投稿する記事を書いてください。

タイトル: {article_work.get('title', '')}
キーワード: {article_work.get('keywords', '')}
カテゴリー: {article_work.get('category', '')}

品質要件:
{quality_prompt}

{article_type_prompt}
{ranking_count_prompt}

{article_html_output_rules()}
{regeneration_instruction}"""

            if style_reference_text:
                prompt += f'''\n\n記事品質の書き方参考:
- 参考URL: {style_reference_url}
- この参考記事は内容・事実・固有名詞を流用するためではありません。
- 文章構成、導入の作り方、権威性の示し方、根拠の置き方、説得力の作り方、CTAまでの流れだけを参考にしてください。
- テーマや読者に合わない表現は使わず、今回の記事内容に自然に合わせてください。

参考記事テキスト:
{style_reference_text[:2500]}'''

            prompt += build_quality_structure_html_prompt(quality)

            prompt += build_article_completion_prompt(
                quality,
                article_type,
                has_decoration=False
            )

            usage_parts = []
            if article_type == 'ranking' and client and should_use_segmented_generation(article_type, quality):
                full_content, usage_parts = yield from generate_segmented_article_sse(
                    client,
                    prompt,
                    article_work,
                    article_type,
                    quality
                )
            elif article_type == 'ranking':
                full_content, usage_parts = yield from generate_structured_ranking_article_sse(
                    client,
                    article_work,
                    quality
                )
            elif should_use_segmented_generation(article_type, quality):
                full_content, usage_parts = yield from generate_segmented_article_sse(
                    client,
                    prompt,
                    article_work,
                    article_type,
                    quality
                )
            else:
                full_content, final_message = yield from stream_claude_sse(
                    client,
                    prompt,
                    'Claude生成中です。応答待ちです。',
                    max_tokens=claude_max_tokens_for_quality(quality)
                )
                usage_parts.append(build_article_usage(prompt, full_content, final_message))

            clean_content, enhance_warning = safe_enhance_generated_article_html(full_content, article_work, article_type)
            validation_error = validate_generated_article(article_work, article_type, clean_content, quality)
            content_chars = len(html_to_text(clean_content))
            continuation_round = 0
            while validation_error and continuation_round < CLAUDE_ARTICLE_CONTINUATION_MAX_ROUNDS:
                continuation_round += 1
                yield f"data: {json.dumps({'status': 'continuing', 'round': continuation_round, 'content_chars': content_chars, 'message': f'本文が短い/未完了のため続きを生成しています（{continuation_round}回目）'})}\n\n"
                continuation_prompt = build_article_continuation_prompt(
                    article_work,
                    article_type,
                    quality,
                    clean_content,
                    validation_error
                )
                continuation_text = ''
                full_content += '\n'
                yield f"data: {json.dumps({'text': '\\n'})}\n\n"
                continuation_text, continuation_message = yield from stream_claude_sse(
                    client,
                    continuation_prompt,
                    f'続きを生成中です（{continuation_round}回目）。Claude応答待ちです。',
                    max_tokens=claude_continuation_max_tokens(quality)
                )
                full_content += continuation_text
                usage_parts.append(build_article_usage(continuation_prompt, continuation_text, continuation_message))
                if not html_to_text(continuation_text).strip():
                    break
                clean_content, continuation_enhance_warning = safe_enhance_generated_article_html(full_content, article_work, article_type)
                if continuation_enhance_warning and not enhance_warning:
                    enhance_warning = continuation_enhance_warning
                validation_error = validate_generated_article(article_work, article_type, clean_content, quality)
                content_chars = len(html_to_text(clean_content))

            current_articles = load_articles()
            for a in current_articles:
                if a['id'] == article_id:
                    similarity = content_similarity(previous_content, clean_content) if is_regeneration else 0
                    generation_warning = enhance_warning or ''
                    if not validation_error and content_chars < 500:
                        validation_error = f'生成結果が短すぎます（{content_chars}文字）。Claude生成が途中で止まった可能性があります。もう一度生成してください。'
                    if not validation_error and is_regeneration and similarity >= 0.985:
                        generation_warning = f'再生成結果は既存本文とほぼ同じです（類似度 {round(similarity * 100, 1)}%）。本文は生成済みとして保持しました。'
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
                    if generation_warning:
                        a['generation_warning'] = generation_warning
                    else:
                        a.pop('generation_warning', None)
                    a.pop('error', None)
                    a.pop('last_generation_interrupted', None)
                    a['title'] = article_work.get('title', a.get('title', ''))
                    a['keywords'] = article_work.get('keywords', a.get('keywords', ''))
                    a['category'] = article_work.get('category', a.get('category', ''))
                    a['slug'] = normalize_slug(article_work.get('slug', a.get('slug', '')))
                    a['ad_keywords'] = article_work.get('ad_keywords', a.get('ad_keywords', ''))
                    a['site_id'] = article_work.get('site_id') or a.get('site_id')
                    a['quality_id'] = quality.get('id') if quality else quality_id
                    a['article_type'] = article_type
                    a['generated_at'] = generated_at
                    a['updated_at'] = generated_at
                    a['content_hash'] = new_content_hash
                    a['generation_finished_at'] = generated_at
                    a['last_generation_changed'] = changed
                    a['last_generation_chars'] = content_chars
                    a['last_generation_similarity'] = round(similarity, 4)
                    usage = combine_article_usages(usage_parts)
                    append_generation_usage(a, usage, generation_run_id, generated_at, clean_content)
                    apply_score_fields(a)
                    break
            save_articles(current_articles)
            yield f"data: {json.dumps({'done': True, 'run_id': generation_run_id, 'content_chars': content_chars, 'changed': changed, 'similarity': round(similarity, 4), 'warning': generation_warning, 'usage': usage})}\n\n"
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


@app.route('/api/generate-direct/<article_id>', methods=['POST'])
@login_required
def generate_article_direct(article_id):
    articles = load_articles()
    article = next((a for a in articles if a['id'] == article_id), None)
    if not article:
        return jsonify({'error': '記事が見つかりません'}), 404

    data = request.json or {}
    settings = load_settings()
    api_key = settings.get('claude_api_key') or os.environ.get('ANTHROPIC_API_KEY', '')

    article_work = dict(article)
    for key in ('title', 'keywords', 'category', 'ad_keywords', 'site_id', 'slug'):
        if key in data:
            article_work[key] = data.get(key) or ''
    article_type = normalize_article_type(data.get('article_type') or article_work.get('article_type'), 'ranking')
    article_work['article_type'] = article_type
    if article_type != 'ranking':
        return jsonify({'error': '直接復旧生成は現在ランキング記事のみ対応しています'}), 400
    if not str(article_work.get('ad_keywords') or '').strip():
        article_work['ad_keywords'] = infer_ad_keywords_from_title(
            article_work.get('title', ''),
            article_work.get('keywords', ''),
            article_type
        )

    quality_id = data.get('quality_id') or article.get('quality_id')
    quality = select_quality_definition(load_quality(), quality_id, article_type)
    now = datetime.now().isoformat()
    for a in articles:
        if a['id'] == article_id:
            for key in ('title', 'keywords', 'category', 'ad_keywords', 'site_id', 'slug'):
                a[key] = article_work.get(key, '')
            a['article_type'] = article_type
            if quality_id:
                a['quality_id'] = quality_id
            a['status'] = 'generating'
            a['generation_started_at'] = now
            a['updated_at'] = now
            a.pop('error', None)
            break
    save_articles(articles)

    try:
        previous_content = article.get('content', '')
        previous_content_hash = content_hash(previous_content)
        is_regeneration = bool(html_to_text(previous_content).strip())
        client = anthropic.Anthropic(api_key=api_key) if api_key else None
        if client and should_use_segmented_generation(article_type, quality):
            base_prompt = f"""以下の情報をもとに、WordPressに投稿する記事を書いてください。

タイトル: {article_work.get('title', '')}
キーワード: {article_work.get('keywords', '')}
カテゴリー: {article_work.get('category', '')}

品質要件:
{build_quality_prompt(quality)}

{build_article_type_prompt(article_type)}
{build_ranking_count_prompt(article_work, article_type)}
{build_ranking_structure_prompt(article_work, article_type)}

{article_html_output_rules()}
{build_quality_structure_html_prompt(quality)}
{build_article_completion_prompt(quality, article_type)}
"""
            raw_content, usage_parts = generate_segmented_article_sync(
                client,
                base_prompt,
                article_work,
                article_type,
                quality
            )
        else:
            raw_content, usage_parts = generate_structured_ranking_article_sync(
                client,
                article_work,
                quality
            )
        clean_content, enhance_warning = safe_enhance_generated_article_html(raw_content, article_work, article_type)
        validation_error = validate_generated_article(article_work, article_type, clean_content, quality)
        content_chars = len(html_to_text(clean_content))
        if not validation_error and content_chars < 500:
            validation_error = f'生成結果が短すぎます（{content_chars}文字）。もう一度生成してください。'
        if validation_error:
            raise RuntimeError(validation_error)

        current_articles = load_articles()
        saved_article = None
        generated_at = datetime.now().isoformat()
        similarity = content_similarity(previous_content, clean_content) if is_regeneration else 0
        changed = content_hash(clean_content) != previous_content_hash
        usage = combine_article_usages(usage_parts)
        for a in current_articles:
            if a['id'] == article_id:
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
                a['generated_at'] = generated_at
                a['updated_at'] = generated_at
                a['content_hash'] = content_hash(clean_content)
                a['generation_finished_at'] = generated_at
                a['last_generation_changed'] = changed
                a['last_generation_chars'] = content_chars
                a['last_generation_similarity'] = round(similarity, 4)
                a.pop('error', None)
                if enhance_warning:
                    a['generation_warning'] = enhance_warning
                else:
                    a.pop('generation_warning', None)
                a.pop('last_generation_interrupted', None)
                append_generation_usage(a, usage, str(uuid.uuid4()), generated_at, clean_content)
                apply_score_fields(a)
                saved_article = a
                break
        save_articles(current_articles)
        return jsonify({
            'success': True,
            'article': saved_article,
            'content_chars': content_chars,
            'changed': changed,
            'similarity': round(similarity, 4),
            'usage': usage,
            'direct_fallback': True,
        })
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
    generation_statuses = ('pending', 'error', 'generated', 'published', 'scheduled')
    pending = [article_lookup[i] for i in requested_ids if i in article_lookup and article_lookup[i].get('status') in generation_statuses]

    if not pending:
        return jsonify({'error': '処理対象の記事がありません'}), 400

    settings = load_settings()
    api_key = settings.get('claude_api_key') or os.environ.get('ANTHROPIC_API_KEY', '')

    quality_list = load_quality()
    batch_article_type = normalize_article_type(data.get('article_type'), 'ranking')
    if not api_key and batch_article_type != 'ranking':
        return jsonify({'error': 'Claude APIキーが設定されていません'}), 400
    quality_cache = {}
    def resolve_quality_for(art_type):
        if art_type not in quality_cache:
            q = select_quality_definition(quality_list, quality_id, art_type)
            quality_cache[art_type] = (q, build_quality_prompt(q))
        return quality_cache[art_type]
    style_reference_cache = {}
    now = datetime.now().isoformat()
    job_id = str(uuid.uuid4())
    job = {
        'id': job_id,
        'type': 'generate',
        'status': 'running',
        'total': len(pending),
        'completed': 0,
        'failed': 0,
        'retried': 0,
        'max_retries': BATCH_GENERATION_MAX_RETRIES,
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
            a['generation_started_at'] = now
            a['updated_at'] = now
            a.pop('error', None)
            a.pop('error_stage', None)
            a.pop('error_trace', None)
            a.pop('generation_warning', None)
            a.pop('last_generation_interrupted', None)
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
        client = anthropic.Anthropic(api_key=api_key) if api_key else None
        completed = 0
        failed = 0
        retried = 0
        attempt_counts = {}
        queue_articles = list(pending)
        while queue_articles:
            article = queue_articles.pop(0)
            article_id = article.get('id')
            attempt_counts[article_id] = attempt_counts.get(article_id, 0) + 1
            attempt_no = attempt_counts[article_id]
            stage = 'starting'
            try:
                stage = 'prepare article'
                retry_suffix = f"（リトライ{attempt_no - 1}/{BATCH_GENERATION_MAX_RETRIES}）" if attempt_no > 1 else ''
                update_job(current_title=article.get('title', ''), message=f"生成中{retry_suffix}: {article.get('title', '')}")
                article_type = normalize_article_type(article.get('article_type') or batch_article_type, batch_article_type)
                quality, quality_prompt = resolve_quality_for(article_type)
                use_generation_extras = False
                pipeline_warnings = []
                if not api_key and article_type != 'ranking':
                    raise ValueError('Claude APIキーが設定されていません')
                if not str(article.get('ad_keywords') or '').strip():
                    article['ad_keywords'] = infer_ad_keywords_from_title(
                        article.get('title', ''),
                        article.get('keywords', ''),
                        article_type
                    )
                article_type_prompt = build_article_type_prompt(article_type)
                ranking_count_prompt = (
                    build_ranking_count_prompt(article, article_type) +
                    build_ranking_structure_prompt(article, article_type)
                )
                regeneration_instruction = build_regeneration_instruction(article.get('content', ''))
                style_reference_url, style_reference_text = style_reference_cache.get(article_type, ('', ''))
                if use_generation_extras and article_type not in style_reference_cache:
                    stage = 'fetch style reference'
                    try:
                        style_reference_url, style_reference_text = fetch_quality_style_reference(article_type, settings, quality)
                    except Exception:
                        style_reference_url, style_reference_text = '', ''
                    style_reference_cache[article_type] = (style_reference_url, style_reference_text)
                stage = 'build prompt'
                prompt = f"""以下の情報をもとに、WordPressに投稿する記事を書いてください。

タイトル: {article['title']}
キーワード: {article['keywords']}
カテゴリー: {article.get('category', '')}

品質要件:
{quality_prompt}

{article_type_prompt}
{ranking_count_prompt}

{article_html_output_rules()}
{regeneration_instruction}"""

                if style_reference_text:
                    prompt += f'''\n\n記事品質の書き方参考:
- 参考URL: {style_reference_url}
- この参考記事は内容・事実・固有名詞を流用するためではありません。
- 文章構成、導入の作り方、権威性の示し方、根拠の置き方、説得力の作り方、CTAまでの流れだけを参考にしてください。
- テーマや読者に合わない表現は使わず、今回の記事内容に自然に合わせてください。

参考記事テキスト:
{style_reference_text[:2500]}'''

                prompt += build_quality_structure_html_prompt(quality)

                prompt += build_article_completion_prompt(
                    quality,
                    article_type,
                    has_decoration=False
                )

                stage = 'generate content'
                if article_type == 'ranking' and client and should_use_segmented_generation(article_type, quality):
                    raw_content, usage_parts = generate_segmented_article_sync(
                        client,
                        prompt,
                        article,
                        article_type,
                        quality,
                        on_step=lambda step_index, step_total, step_name: update_job(
                            current_title=article.get('title', ''),
                            message=f"分割生成中: {article.get('title', '')} / {step_name} ({step_index}/{step_total})"
                        )
                    )
                elif article_type == 'ranking':
                    raw_content, usage_parts = generate_structured_ranking_article_sync(
                        client,
                        article,
                        quality,
                        on_step=lambda step_index, step_total, step_name: update_job(
                            current_title=article.get('title', ''),
                            message=f"固定骨組み生成中: {article.get('title', '')} / {step_name} ({step_index}/{step_total})"
                        )
                    )
                elif should_use_segmented_generation(article_type, quality):
                    raw_content, usage_parts = generate_segmented_article_sync(
                        client,
                        prompt,
                        article,
                        article_type,
                        quality,
                        on_step=lambda step_index, step_total, step_name: update_job(
                            current_title=article.get('title', ''),
                            message=f"分割生成中: {article.get('title', '')} / {step_name} ({step_index}/{step_total})"
                        )
                    )
                else:
                    message = create_claude_message(client, prompt, max_tokens=claude_max_tokens_for_quality(quality))
                    raw_content = anthropic_message_text(message)
                    usage_parts = [build_article_usage(prompt, raw_content, message)]
                stage = 'enhance and validate content'
                content, enhance_warning = safe_enhance_generated_article_html(raw_content, article, article_type)
                if enhance_warning:
                    pipeline_warnings.append(enhance_warning)
                validation_error = validate_generated_article(article, article_type, content, quality)
                content_chars = len(html_to_text(content))
                continuation_round = 0
                while validation_error and continuation_round < CLAUDE_ARTICLE_CONTINUATION_MAX_ROUNDS:
                    continuation_round += 1
                    update_job(
                        current_title=article.get('title', ''),
                        message=f"本文が短い/未完了のため続きを生成中: {article.get('title', '')} ({continuation_round}回目)"
                    )
                    continuation_prompt = build_article_continuation_prompt(
                        article,
                        article_type,
                        quality,
                        content,
                        validation_error
                    )
                    continuation_message = create_claude_message(client, continuation_prompt, max_tokens=claude_continuation_max_tokens(quality))
                    continuation_text = anthropic_message_text(continuation_message)
                    usage_parts.append(build_article_usage(continuation_prompt, continuation_text, continuation_message))
                    if not html_to_text(continuation_text).strip():
                        break
                    raw_content += '\n' + continuation_text
                    content, enhance_warning = safe_enhance_generated_article_html(raw_content, article, article_type)
                    if enhance_warning:
                        pipeline_warnings.append(enhance_warning)
                    validation_error = validate_generated_article(article, article_type, content, quality)
                    content_chars = len(html_to_text(content))
                if not validation_error and content_chars < 500:
                    validation_error = f'生成結果が短すぎます（{content_chars}文字）。Claude生成が途中で止まった可能性があります。もう一度生成してください。'
                if validation_error:
                    if content_chars < 500:
                        raise ValueError(validation_error)
                    pipeline_warnings.append(validation_error)
                generated_at = datetime.now().isoformat()
                run_id = str(uuid.uuid4())

                stage = 'save generated article'
                current_articles = load_articles()
                for a in current_articles:
                    if a['id'] == article['id']:
                        a['content'] = content
                        a['status'] = 'generated'
                        a.pop('batch_job_id', None)
                        a.pop('error', None)
                        a.pop('error_stage', None)
                        a.pop('error_trace', None)
                        if pipeline_warnings:
                            a['generation_warning'] = ' / '.join(dict.fromkeys(pipeline_warnings))
                        else:
                            a.pop('generation_warning', None)
                        a.pop('last_generation_interrupted', None)
                        a['quality_id'] = quality.get('id') if quality else quality_id
                        a['article_type'] = article_type
                        a['ad_keywords'] = article.get('ad_keywords', a.get('ad_keywords', ''))
                        a['generation_phase'] = 'base_saved'
                        a['generated_at'] = generated_at
                        a['updated_at'] = generated_at
                        a['content_hash'] = content_hash(content)
                        usage = combine_article_usages(usage_parts)
                        append_generation_usage(a, usage, run_id, generated_at, content)
                        apply_score_fields(a)
                        break
                save_articles(current_articles)
                stage = 'postprocess article'
                postprocess_warnings = []
                try:
                    post_content = content
                    if client:
                        update_job(current_title=article.get('title', ''), message=f"本文保存済み。品質改善中: {article.get('title', '')}")
                        polish_prompt = build_article_polish_prompt(
                            article,
                            article_type,
                            quality,
                            post_content,
                            ' / '.join(pipeline_warnings)
                        )
                        polish_message = create_claude_message(
                            client,
                            polish_prompt,
                            max_tokens=claude_max_tokens_for_quality(quality, floor=2400, ceiling=7000)
                        )
                        polished_raw = anthropic_message_text(polish_message)
                        polished_content, enhance_warning = safe_enhance_generated_article_html(polished_raw, article, article_type)
                        if enhance_warning:
                            postprocess_warnings.append(enhance_warning)
                        if len(html_to_text(polished_content)) >= max(500, int(len(html_to_text(post_content)) * 0.75)):
                            post_content = polished_content
                            usage_parts.append(build_article_usage(polish_prompt, polished_raw, polish_message))
                        else:
                            postprocess_warnings.append('品質改善後の本文が短すぎたため、本文生成直後の内容を維持しました。')

                    update_job(current_title=article.get('title', ''), message=f"本文保存済み。本文HTMLを整えています: {article.get('title', '')}")
                    if post_content != content:
                        post_content, enhance_warning = safe_enhance_generated_article_html(post_content, article, article_type)
                        if enhance_warning:
                            postprocess_warnings.append(enhance_warning)
                        post_generated_at = datetime.now().isoformat()
                        current_articles = load_articles()
                        for a in current_articles:
                            if a['id'] == article['id']:
                                a['content'] = post_content
                                a['generation_phase'] = 'postprocessed'
                                a['updated_at'] = post_generated_at
                                a['content_hash'] = content_hash(post_content)
                                a['last_generation_chars'] = len(html_to_text(post_content))
                                usage = combine_article_usages(usage_parts)
                                a['usage'] = usage
                                warnings = pipeline_warnings + postprocess_warnings
                                if warnings:
                                    a['generation_warning'] = ' / '.join(dict.fromkeys(warnings))
                                else:
                                    a.pop('generation_warning', None)
                                apply_score_fields(a)
                                break
                        save_articles(current_articles)
                except Exception as post_error:
                    postprocess_warnings.append(f'本文後処理をスキップしました: {post_error}')
                    current_articles = load_articles()
                    for a in current_articles:
                        if a['id'] == article['id']:
                            warnings = pipeline_warnings + postprocess_warnings
                            a['generation_warning'] = ' / '.join(dict.fromkeys(warnings))
                            a['generation_phase'] = 'base_saved_with_postprocess_warning'
                            a['updated_at'] = datetime.now().isoformat()
                            break
                    save_articles(current_articles)
                completed += 1
                update_job(completed=completed, failed=failed, retried=retried, message=f"{completed}/{len(pending)}件生成済み")
            except Exception as e:
                trace = traceback.format_exc()
                error_text = str(e) or e.__class__.__name__
                error_detail = f'{stage}: {error_text}'
                if attempt_no <= BATCH_GENERATION_MAX_RETRIES:
                    retried += 1
                    current_articles = load_articles()
                    for a in current_articles:
                        if a['id'] == article['id']:
                            a['status'] = 'generating'
                            a['error'] = f'一時エラーのため自動リトライ待ち: {error_detail}'
                            a['error_stage'] = stage
                            a['error_trace'] = trace[-4000:]
                            a['generation_retry_count'] = attempt_no
                            a['updated_at'] = datetime.now().isoformat()
                            break
                    save_articles(current_articles)
                    queue_articles.append(article)
                    update_job(
                        completed=completed,
                        failed=failed,
                        retried=retried,
                        current_title=article.get('title', ''),
                        last_error=error_detail,
                        last_error_stage=stage,
                        last_error_trace=trace[-4000:],
                        message=f"一時エラー。後で自動リトライします（{attempt_no}/{BATCH_GENERATION_MAX_RETRIES}）: {error_detail}"
                    )
                    time.sleep(min(10, 2 * attempt_no))
                    continue
                current_articles = load_articles()
                for a in current_articles:
                    if a['id'] == article['id']:
                        a['status'] = 'error'
                        a['error'] = error_detail
                        a['error_stage'] = stage
                        a['error_trace'] = trace[-4000:]
                        a.pop('batch_job_id', None)
                        a['generation_retry_count'] = attempt_no - 1
                        a['updated_at'] = datetime.now().isoformat()
                        a['generation_finished_at'] = a['updated_at']
                        break
                save_articles(current_articles)
                failed += 1
                update_job(
                    completed=completed,
                    failed=failed,
                    retried=retried,
                    last_error=error_detail,
                    last_error_stage=stage,
                    last_error_trace=trace[-4000:],
                    message=f"{completed}件生成済み / {failed}件エラー / リトライ {retried}回: {error_detail}"
                )
        final_status = 'completed' if failed == 0 else 'completed_with_errors'
        update_job(
            status=final_status,
            current_title='',
            completed=completed,
            failed=failed,
            retried=retried,
            completed_at=datetime.now().isoformat(),
            message=f"一括生成完了: 成功 {completed}件 / エラー {failed}件 / 自動リトライ {retried}回"
        )

    thread = threading.Thread(target=run_batch, daemon=True)
    thread.start()
    return jsonify({'success': True, 'job_id': job_id, 'message': f'{len(pending)}件の記事生成を開始しました'})


# WordPress publish
def extract_wp_edit_content(post_data):
    content = post_data.get('content') if isinstance(post_data, dict) else {}
    if isinstance(content, dict):
        return content.get('raw') or content.get('rendered') or ''
    return str(content or '')


def normalized_text_hash(content):
    text = re.sub(r'\s+', '', html_to_text(content or ''))
    return content_hash(text)


def fetch_wordpress_post_for_edit(wp_url, wp_user, wp_password, post_id):
    response = requests.get(
        f"{wp_url}/wp-json/wp/v2/posts/{post_id}",
        auth=(wp_user, wp_password),
        params={'context': 'edit'},
        timeout=30
    )
    response.raise_for_status()
    return response.json()


def update_wordpress_post_from_article(article, settings):
    if not article.get('content'):
        raise ValueError('記事コンテンツがありません。先に生成してください。')
    if not article.get('wp_post_id'):
        raise ValueError('既存のWordPress投稿IDがありません。先にWP投稿してください。')

    wp_url, wp_user, wp_password = get_site_credentials(article, settings)
    if not all([wp_url, wp_user, wp_password]):
        raise ValueError('サイトが設定されていません。記事にサイトを紐付けてください。')

    clean_content = sanitize_generated_html(article.get('content', ''))
    validation_error = validate_generated_article(
        article,
        article.get('article_type', 'ranking'),
        clean_content,
        select_quality_definition(load_quality(), article.get('quality_id'), article.get('article_type', 'ranking'))
    )
    if validation_error:
        raise ValueError(f'この記事は品質チェックに通っていないため、WordPressへ上書き送信しません: {validation_error}')

    publish_content = prepare_article_content_for_publish(clean_content, settings)
    before_data = fetch_wordpress_post_for_edit(wp_url, wp_user, wp_password, article['wp_post_id'])
    before_content = extract_wp_edit_content(before_data)
    post_payload = {
        'title': article.get('title', ''),
        'content': publish_content,
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
    post_data = response.json()
    after_data = fetch_wordpress_post_for_edit(wp_url, wp_user, wp_password, article['wp_post_id'])
    after_content = extract_wp_edit_content(after_data)
    repair_info = {
        'source_content_chars': len(html_to_text(clean_content)),
        'sent_content_chars': len(html_to_text(publish_content)),
        'before_content_chars': len(html_to_text(before_content)),
        'after_content_chars': len(html_to_text(after_content)),
        'before_hash': content_hash(before_content),
        'after_hash': content_hash(after_content),
        'sent_hash': content_hash(publish_content),
        'before_text_hash': normalized_text_hash(before_content),
        'after_text_hash': normalized_text_hash(after_content),
        'sent_text_hash': normalized_text_hash(publish_content),
    }
    repair_info['wp_changed'] = repair_info['before_text_hash'] != repair_info['after_text_hash']
    repair_info['wp_matches_sent'] = repair_info['after_text_hash'] == repair_info['sent_text_hash']
    if not repair_info['wp_matches_sent']:
        raise ValueError(
            'WordPressへ送信しましたが、保存後の本文が送信本文と一致しませんでした。'
            f"送信 {repair_info['sent_content_chars']}文字 / 保存後 {repair_info['after_content_chars']}文字。"
            ' キャッシュではなくWordPress保存処理側で差分が発生している可能性があります。'
        )
    return post_data, clean_content, repair_info


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
    quality = select_quality_definition(
        load_quality(),
        article.get('quality_id'),
        article.get('article_type', 'ranking')
    )
    validation_error = validate_generated_article(
        article,
        article.get('article_type', 'ranking'),
        article.get('content', ''),
        quality
    )
    if validation_error:
        return jsonify({'error': f'この記事は品質チェックに通っていないため、WordPressへ投稿しません: {validation_error}'}), 400

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
        post_data, clean_content, repair_info = update_wordpress_post_from_article(article, settings)
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
            'repair_info': repair_info,
            'wp_changed': repair_info.get('wp_changed'),
            'wp_matches_sent': repair_info.get('wp_matches_sent'),
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
    results = {'success': 0, 'unchanged': 0, 'mismatch': 0, 'error': 0, 'errors': []}
    now = datetime.now().isoformat()

    for article in articles:
        if article.get('id') not in ids:
            continue
        try:
            post_data, clean_content, repair_info = update_wordpress_post_from_article(article, settings)
            article['content'] = clean_content
            if article.get('status') != 'scheduled':
                article['status'] = 'published'
            article['wp_url'] = post_data.get('link', article.get('wp_url', ''))
            article['repaired_at'] = now
            article['updated_at'] = now
            apply_score_fields(article)
            results['success'] += 1
            if not repair_info.get('wp_changed'):
                results['unchanged'] += 1
            if not repair_info.get('wp_matches_sent'):
                results['mismatch'] += 1
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
    quality_list = load_quality()
    article_lookup = {a['id']: a for a in articles}
    targets = [article_lookup[i] for i in article_ids if i in article_lookup and article_lookup[i].get('content')]
    if post_status == 'publish' and not schedule_enabled and len(targets) > 20:
        return jsonify({'error': '即時公開は1日20件までです。21件以上は予約投稿を有効にしてください。'}), 400

    results = {'success': 0, 'error': 0, 'errors': []}
    daily_limit = clamp_int(schedule_data.get('daily_limit'), 20, 1, 20)
    schedule_start_key = normalize_schedule_date_key(schedule_data.get('start_date'), (datetime.now() + timedelta(days=1)).strftime('%Y-%m-%d'))
    schedule_day_counts = {}
    for index, article in enumerate(targets):
        quality = select_quality_definition(
            quality_list,
            article.get('quality_id'),
            article.get('article_type', 'ranking')
        )
        validation_error = validate_generated_article(
            article,
            article.get('article_type', 'ranking'),
            article.get('content', ''),
            quality
        )
        if validation_error:
            results['error'] += 1
            results['errors'].append({
                'title': article['title'],
                'error': f'品質チェック未通過のため投稿しません: {validation_error}'
            })
            continue
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
    article_type = normalize_article_type(data.get('article_type'), '') if data.get('article_type') else ''
    q = {
        'id': str(uuid.uuid4()),
        'name': data.get('name', ''),
        'reference_url': data.get('reference_url', ''),
        'target_chars': data.get('target_chars', ''),
        'tone': data.get('tone', 'ですます調'),
        'extra_rules': data.get('extra_rules', ''),
        'structure_html': data.get('structure_html', ''),
        'prompt': data.get('prompt', ''),
        'is_default': bool(data.get('is_default')),
    }
    if article_type:
        q['article_type'] = article_type
    if q['is_default']:
        for other in quality_list:
            other['is_default'] = False
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
            if 'article_type' in data:
                article_type = normalize_article_type(data.get('article_type'), '') if data.get('article_type') else ''
                if article_type:
                    q['article_type'] = article_type
                else:
                    q.pop('article_type', None)
            q['target_chars'] = data.get('target_chars', q.get('target_chars', ''))
            q['tone'] = data.get('tone', q.get('tone', 'ですます調'))
            q['extra_rules'] = data.get('extra_rules', q.get('extra_rules', ''))
            q['structure_html'] = data.get('structure_html', q.get('structure_html', ''))
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
@app.route('/api/settings', methods=['GET'])
@login_required
def get_settings():
    settings = load_settings()
    safe = {
        'claude_api_key': mask_secret(settings.get('claude_api_key', '')),
        'default_quality_id': settings.get('default_quality_id', 'default'),
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
    if 'article_css' in data:
        if looks_like_html(data.get('article_css', '')):
            return jsonify({'success': False, 'error': '記事CSS定義にはHTMLを保存できません。CSSだけを入力してください。'}), 400
        settings['article_css'] = data['article_css']
    save_settings(settings)
    return jsonify({'success': True})


if __name__ == '__main__':
    port = int(os.environ.get('PORT', 5000))
    app.run(host='0.0.0.0', port=port, debug=os.environ.get('FLASK_DEBUG', 'false').lower() == 'true')
