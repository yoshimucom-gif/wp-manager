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
import hmac
import sqlite3
import difflib
import time
import traceback
import unicodedata
from datetime import datetime, timedelta, timezone
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
    from bs4.element import Comment as BS4Comment
except ImportError:
    BeautifulSoup = None
    FeatureNotFound = Exception
    BS4Comment = None
    NavigableString = None

app = Flask(__name__)
app.secret_key = os.environ.get('SECRET_KEY', 'dev-secret-key-change-in-production')

# ───────────────────────────────────────────────────────────────────────────
# データ整合性ロック（NON-NEGOTIABLE / ロスト・アップデート対策）
#
# JSON ファイル永続化は save_json() が tmp+os.replace でアトミックだが、
# 「load → 変更 → save」の一連の流れ（read-modify-write）は守られていない。
# gunicorn は --threads 8 で複数 HTTP リクエストを並行処理し、さらにバッチ
# 生成・タイトル生成はバックグラウンドスレッドで動く。これらが同じ JSON を
# 同時に read-modify-write すると後勝ちで片方の更新が丸ごと消える。
#
# 対策: 再入可能なグローバルロック _DATA_LOCK を1本用意し、
#   - データ変更系の HTTP ルートは @with_data_lock デコレータで囲む
#   - バックグラウンドスレッドの load→save スパンは `with _DATA_LOCK:` で囲む
# RLock なので、ロック保持中にさらにロックを取る入れ子呼び出しも安全。
#
# ⚠️ このロックは threading.RLock = 同一プロセス内でしか効かない。
#    gunicorn の --workers は必ず 1 にすること。2 以上にすると別プロセスとなり
#    ロックがすり抜けてロスト・アップデートが再発する（render.yaml 参照）。
# ───────────────────────────────────────────────────────────────────────────
_DATA_LOCK = threading.RLock()
_SCHED_PUBLISH_JOBS = {}  # job_id -> {status, total, completed, success, error, errors}

# WP一括投稿ジョブのインメモリストア（完了後に自動削除しない・最新20件保持）
_PUBLISH_JOBS: dict = {}
_PUBLISH_JOBS_LOCK = threading.Lock()


def with_data_lock(f):
    """データ変更系ルート用デコレータ。ハンドラ全体を _DATA_LOCK で直列化する。

    ⚠️ SSE ストリーミングルート（/api/generate など、長時間レスポンスを
       握り続けるもの）には付けないこと。ロックを長時間占有してしまう。
       それらはハンドラ内部の load→save スパンを個別に `with _DATA_LOCK:`
       で囲むこと。
    """
    @wraps(f)
    def wrapper(*args, **kwargs):
        with _DATA_LOCK:
            return f(*args, **kwargs)
    return wrapper


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
# Render Starter（単ワーカー）で安定稼働 + タイトル品質を担保できる上限
# 30KW × 3案/KW = 90タイトル/回。10バッチ並列5本で実測30〜45秒程度。
try:
    TITLE_IDEA_MAX_KEYWORDS = max(1, int(os.environ.get('TITLE_IDEA_MAX_KEYWORDS', '30')))
except ValueError:
    TITLE_IDEA_MAX_KEYWORDS = 30
try:
    CLAUDE_ARTICLE_MAX_TOKENS = int(os.environ.get('CLAUDE_ARTICLE_MAX_TOKENS', '20000'))
except ValueError:
    CLAUDE_ARTICLE_MAX_TOKENS = 20000

# WordPress REST API リクエスト共通ヘッダ
# 'python-requests/x.x' UA や bot 風 UA（'compatible; Name/ver; +url' 形式）は
# WAF / Wordfence / SiteGuard / Cloudflare で 403 になりやすい。
# そのため「実在ブラウザそのまま」の Chrome UA を付与する。
WP_REQUEST_HEADERS = {
    'User-Agent': 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36',
    'Accept': 'application/json',
}


def describe_wp_request_error(e):
    """WordPress REST 呼び出しの例外から、診断に役立つメッセージを組み立てる。

    requests の素の例外は「403 Client Error: Forbidden for url: ...」としか
    出ないが、403/401 の本当の理由はレスポンス body に入っていることが多い:
      - WordPress 自身のJSONエラー → {"code":"rest_...","message":"..."}
      - WAF / SiteGuard / セキュリティプラグイン → HTMLのブロックページ
    これを拾って原因切り分けできるメッセージにする。
    """
    import re as _re
    resp = getattr(e, 'response', None)
    if resp is None:
        return str(e)
    status = resp.status_code
    # WordPress のJSONエラーなら message / code を優先表示
    try:
        data = resp.json()
        if isinstance(data, dict) and (data.get('message') or data.get('code')):
            return f"HTTP {status}: {data.get('message') or ''}（code: {data.get('code') or '-'}）"
    except Exception:
        pass
    # JSONでない（WAF等のHTMLブロックページ）→ タグを除いて短く添える
    snippet = _re.sub(r'<[^>]+>', ' ', resp.text or '')
    snippet = _re.sub(r'\s+', ' ', snippet).strip()[:300]
    hint = ''
    if status == 401:
        hint = ' ／ アプリケーションパスワードが誤っている可能性'
    elif status == 403:
        hint = ' ／ 認証情報の誤り or セキュリティプラグイン・WAFによるブロックの可能性'
    return f"HTTP {status}: {snippet or 'Forbidden'}{hint}"
try:
    CLAUDE_ARTICLE_CONTINUATION_MAX_ROUNDS = int(os.environ.get('CLAUDE_ARTICLE_CONTINUATION_MAX_ROUNDS', '4'))
except ValueError:
    CLAUDE_ARTICLE_CONTINUATION_MAX_ROUNDS = 4
try:
    BATCH_GENERATION_MAX_RETRIES = int(os.environ.get('BATCH_GENERATION_MAX_RETRIES', '2'))
except ValueError:
    BATCH_GENERATION_MAX_RETRIES = 2
# Claude API 過負荷（HTTP 529 / overloaded_error）専用のリトライ回数。
# 529 は Anthropic サーバ側の一時的な混雑で、数十秒〜数分で回復する。
# 通常エラー用の2回・数秒バックオフでは足りずバッチが全滅するため、
# 過負荷は長め指数バックオフ＋多めリトライで待ち抜く（通常予算とは別枠）。
try:
    CLAUDE_OVERLOAD_MAX_RETRIES = int(os.environ.get('CLAUDE_OVERLOAD_MAX_RETRIES', '8'))
except ValueError:
    CLAUDE_OVERLOAD_MAX_RETRIES = 8
try:
    CLAUDE_SEGMENT_CONTINUATION_MAX_ROUNDS = int(os.environ.get('CLAUDE_SEGMENT_CONTINUATION_MAX_ROUNDS', '2'))
except ValueError:
    CLAUDE_SEGMENT_CONTINUATION_MAX_ROUNDS = 2
# 品質ゲート（#7）: 生成記事の SEO スコアがこの点数未満なら、不足点を
# フィードバックして品質改善（作り直し）を最大 QUALITY_GATE_MAX_POLISH 回まで試みる。
# 各試行を採点し最高スコア版を採用するため、改善が無ければ早期打ち切り。
try:
    QUALITY_GATE_MIN_SCORE = int(os.environ.get('QUALITY_GATE_MIN_SCORE', '60'))
except ValueError:
    QUALITY_GATE_MIN_SCORE = 60
try:
    QUALITY_GATE_MAX_POLISH = max(1, int(os.environ.get('QUALITY_GATE_MAX_POLISH', '2')))
except ValueError:
    QUALITY_GATE_MAX_POLISH = 2
DEFAULT_ARTICLE_TARGET_CHARS = 3000
SONNET_INPUT_USD_PER_MTOK = 3.0
SONNET_OUTPUT_USD_PER_MTOK = 15.0
USAGE_ESTIMATE_USD_JPY = 155

CLAUDE_ARTICLE_MODEL_PRICING = {
    'claude-sonnet-4-6': {'input_usd_per_mtok': 3.0, 'output_usd_per_mtok': 15.0},
    'claude-opus-4-7':   {'input_usd_per_mtok': 15.0, 'output_usd_per_mtok': 75.0},
}


def get_article_model(settings=None):
    """設定から記事生成モデル名を読み取る。未設定なら Sonnet 4.6（デフォルト）。
    ホワイトリスト外の値は無視してデフォルトを返す。"""
    if settings is None:
        try:
            settings = load_settings()
        except Exception:
            settings = {}
    value = str((settings or {}).get('claude_article_model') or '').strip()
    if value in CLAUDE_ARTICLE_MODEL_PRICING:
        return value
    return CLAUDE_ARTICLE_MODEL
APP_STARTED_AT = datetime.now()
STALE_ARTICLE_STATUS_MINUTES = {
    'queued': 60,  # 待機中は1時間以上動きが無ければ強制復旧
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
BATCH_JOBS_FILE = DATA_DIR / 'batch_jobs.json'
TITLE_DEFINITION_FILE = DATA_DIR / 'title_definition.json'
TITLE_IDEA_JOBS_FILE = DATA_DIR / 'title_idea_jobs.json'
AD_INSERTION_FILE = DATA_DIR / 'ad_insertion.json'
PUBLISH_JOBS_FILE  = DATA_DIR / 'publish_jobs.json'

# 日本時間 (JST = UTC+9) のタイムゾーン定数。
# 全てのタイムスタンプはJST固定でISO文字列化する（+09:00 サフィックス付き）。
# Render は UTC で動くので、もしこれを使わず datetime.now() を使うと UTC が保存される。
JST_TZ = timezone(timedelta(hours=9))

def now_iso():
    """現在時刻をJST + ISO8601 で返す。
    例: '2026-05-19T10:30:00+09:00'
    全ての保存タイムスタンプは必ずこの関数で生成する。
    フロント側は new Date(...) でこの文字列を解釈すれば、ブラウザTZに
    関わらず JST の絶対時刻として扱われる。
    """
    return datetime.now(JST_TZ).isoformat(timespec='seconds')


# ───────────────────────────────────────────────────────────────────────────
# SQLite ドキュメントストア（永続化レイヤ / ロードマップ #2）
#
# articles / settings / quality / batch_jobs / title_definition /
# title_idea_jobs / ad_insertion を、ばらばらの JSON ファイルではなく
# 1つの SQLite DB (wpmanager.db) の documents テーブルに JSON 値として持つ。
#
# 旧 JSON ファイル方式の問題:
#   - tmp ファイル + os.replace は「アトミックなファイル置換」ではあるが、
#     ディスクや OS レベルの中断・並行 tmp 衝突に対し SQLite ほど堅くない
#   - バックアップ＝ディレクトリ内の複数 JSON を集める必要があった
# SQLite の利点:
#   - WAL モードで ACID。書き込み中クラッシュでも DB は壊れない
#   - バックアップは wpmanager.db 1ファイルをコピーするだけ
#
# 注意: これは「ドキュメント丸ごと1行」の KV ストア。read-modify-write の
#       ロスト・アップデート対策は引き続き _DATA_LOCK が担う（SQLite 化だけでは
#       解決しない）。--workers 1 制約も _DATA_LOCK がプロセス内ロックである限り継続。
# ───────────────────────────────────────────────────────────────────────────
DB_FILE = DATA_DIR / 'wpmanager.db'


def _db_connect():
    conn = sqlite3.connect(str(DB_FILE), timeout=30.0)
    conn.execute('PRAGMA journal_mode=WAL')
    conn.execute('PRAGMA synchronous=NORMAL')
    return conn


def _db_init():
    """documents テーブルを用意する（冪等）。"""
    try:
        DATA_DIR.mkdir(parents=True, exist_ok=True)
    except Exception:
        pass
    conn = _db_connect()
    try:
        with conn:
            conn.execute(
                'CREATE TABLE IF NOT EXISTS documents ('
                ' key TEXT PRIMARY KEY,'
                ' value TEXT NOT NULL,'
                ' updated_at TEXT)'
            )
    finally:
        conn.close()


_db_init()


def load_doc(key, default):
    """ドキュメント(JSON値)を SQLite から読む。未保存・破損時は default。"""
    try:
        conn = _db_connect()
        try:
            row = conn.execute(
                'SELECT value FROM documents WHERE key=?', (key,)
            ).fetchone()
        finally:
            conn.close()
    except Exception:
        return default
    if row is None:
        return default
    try:
        return json.loads(row[0])
    except (ValueError, TypeError):
        return default


def save_doc(key, data):
    """ドキュメント(JSON値)を SQLite に保存（UPSERT / トランザクション）。"""
    payload = json.dumps(data, ensure_ascii=False)
    ts = now_iso()
    conn = _db_connect()
    try:
        with conn:
            conn.execute(
                'INSERT INTO documents(key, value, updated_at) VALUES(?,?,?) '
                'ON CONFLICT(key) DO UPDATE SET '
                'value=excluded.value, updated_at=excluded.updated_at',
                (key, payload, ts)
            )
    finally:
        conn.close()


DEFAULT_TITLE_DEFINITION = {
    'version': 1,
    'char_max': 35,
    'forbidden_phrases': [
        '完全ガイド', '決定版', '○○のすべて',
        '神', 'No.1', '絶対', '必見', '驚愕', '衝撃',
        'プロが選ぶ', 'プロおすすめ', 'プロ厳選',
    ],
    'ranking_default_count': 5,
    'ranking_max_count': 7,
    'additional_instructions': '',
    'example_titles': [
        '防水バッグおすすめ5選！通勤・登山で濡らさない最強モデルを徹底比較',
        '洗えるネックウォーマーおすすめ5選！コスパと暖かさで選ぶ厳選モデル',
        '【電気代を抑える】6〜10畳用加湿器おすすめ5選！コスパ最強比較',
        '【腰痛対策】1日8時間座っても疲れない在宅ワーク向けチェア5選',
        '「マズい」を卒業！初心者でも飲みやすく続けやすいプロテイン5選',
        '防水バッグで後悔しない選び方！知っておくべきIPX等級の落とし穴',
        '【防寒対決】ネックウォーマーvsマフラー本当に暖かいのはどっち？',
        '【もう臭わない】加湿器のカビを防ぐ簡単お手入れと正しい置き場所',
        '【脱・腰痛】デスクワークで腰が痛い5つの原因と今すぐできる改善策',
        'プロテインを飲むゴールデンタイムは？目的別の効果的なタイミング',
    ],
}


def load_title_definition():
    raw = load_doc('title_definition', None)
    if not isinstance(raw, dict):
        return dict(DEFAULT_TITLE_DEFINITION)
    merged = dict(DEFAULT_TITLE_DEFINITION)
    merged.update({k: v for k, v in raw.items() if k in DEFAULT_TITLE_DEFINITION})
    # 配列フィールドは型確認
    for key in ('forbidden_phrases', 'example_titles'):
        if not isinstance(merged.get(key), list):
            merged[key] = list(DEFAULT_TITLE_DEFINITION[key])
    # 旧デフォルト値（45）が保存されていたら現行デフォルト（35）に移行
    if merged.get('char_max') == 45:
        merged['char_max'] = DEFAULT_TITLE_DEFINITION['char_max']
    # サンプル例に含まれる語が禁止リストに残っていたら自動除外（矛盾防止）
    example_text = ' '.join(merged.get('example_titles') or [])
    merged['forbidden_phrases'] = [
        p for p in merged.get('forbidden_phrases', []) if p not in example_text
    ]
    return merged


def save_title_definition(definition):
    clean = dict(DEFAULT_TITLE_DEFINITION)
    for k, v in (definition or {}).items():
        if k not in DEFAULT_TITLE_DEFINITION:
            continue
        if k in ('forbidden_phrases', 'example_titles'):
            if isinstance(v, list):
                clean[k] = [str(x).strip() for x in v if str(x).strip()]
        elif k in ('char_max', 'ranking_default_count', 'ranking_max_count'):
            try:
                clean[k] = int(v)
            except (TypeError, ValueError):
                pass
        else:
            clean[k] = str(v or '').strip()
    save_doc('title_definition', clean)
    return clean


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


# 旧 JSON ファイル → SQLite documents テーブル の対応表
_DOC_FILE_MAP = {
    'articles': ARTICLES_FILE,
    'quality': QUALITY_FILE,
    'settings': SETTINGS_FILE,
    'batch_jobs': BATCH_JOBS_FILE,
    'title_definition': TITLE_DEFINITION_FILE,
    'title_idea_jobs': TITLE_IDEA_JOBS_FILE,
    'ad_insertion': AD_INSERTION_FILE,
    'publish_jobs': PUBLISH_JOBS_FILE,
}


def _migrate_json_files_to_db():
    """旧 JSON ファイルが残っていて DB に未取り込みなら、一度だけ取り込む。

    起動時に1回呼ぶ。DB に既に該当キーがあればスキップするので冪等。
    旧ファイル自体は削除せず残す（移行前状態のバックアップとして）。
    """
    for key, path in _DOC_FILE_MAP.items():
        try:
            conn = _db_connect()
            try:
                exists = conn.execute(
                    'SELECT 1 FROM documents WHERE key=?', (key,)
                ).fetchone()
            finally:
                conn.close()
            if exists:
                continue
            if not path.exists():
                continue
            data = load_json(path, None)
            if data is None:
                continue
            save_doc(key, data)
            try:
                app.logger.info('[db-migrate] %s を %s から取り込みました', key, path.name)
            except Exception:
                pass
        except Exception as e:
            try:
                app.logger.warning('[db-migrate] %s の取り込みに失敗: %s', key, e)
            except Exception:
                pass


_migrate_json_files_to_db()


def load_articles():
    return load_doc('articles', [])

def save_articles(articles):
    save_doc('articles', articles)

def load_batch_jobs():
    return load_doc('batch_jobs', [])

def save_batch_jobs(jobs):
    save_doc('batch_jobs', jobs[:50])


def load_publish_jobs():
    return load_doc('publish_jobs', [])

def save_publish_jobs(jobs):
    save_doc('publish_jobs', jobs[:30])


def load_title_idea_jobs():
    return load_doc('title_idea_jobs', [])


def save_title_idea_jobs(jobs):
    # 直近20件だけ保持
    save_doc('title_idea_jobs', jobs[:20])


def update_title_idea_job(job_id, **changes):
    with _DATA_LOCK:
        jobs = load_title_idea_jobs()
        for item in jobs:
            if item.get('id') == job_id:
                item.update(changes)
                item['updated_at'] = now_iso()
                break
        save_title_idea_jobs(jobs)

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
    # parse_saved_datetime は tzinfo を剥がして naive を返すため、
    # 比較側も naive JST に揃える（aware と naive は減算できない制約）
    now = datetime.now(JST_TZ).replace(tzinfo=None)
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
        # コンテンツ有無で文言を分ける（無いものに「再生成」は誤解を招く）
        if article.get('content'):
            article['generation_warning'] = '前回の再生成が中断されましたが、本文は保存されています。必要なら再実行してください。'
        else:
            article['generation_warning'] = '前回の生成が中断されました。改めて一括処理を実行してください（初回生成のため課金されます）。'
        article['last_generation_interrupted'] = True
        article['updated_at'] = now_iso()
        article['generation_finished_at'] = now_iso()
        article.pop('batch_job_id', None)
        article.pop('processing_message', None)
        article.pop('error', None)
        changed = True

    return changed

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
    quality = load_doc('quality', presets)
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
            save_doc('quality', quality)
        except Exception as e:
            app.logger.warning('Failed to persist quality presets: %s', e)
    return quality

def save_quality(quality):
    save_doc('quality', quality)

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
        'exported_at': now_iso(),
        'storage': storage_status(),
        'settings': load_settings(),
        'articles': load_articles(),
        'quality': load_quality(),
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

def load_settings():
    settings = load_doc('settings', {
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


RAKUTEN_SEARCH_ENDPOINT = 'https://app.rakuten.co.jp/services/api/IchibaItem/Search/20220601'


def rakuten_search(query, app_id, affiliate_id=None, limit=20, timeout=8):
    """楽天市場 商品検索 API を叩いて整形済みリストを返す。

    Returns: list[dict] with keys: name, price, url, image_url, shop_name,
             review_count, review_avg, item_caption.
    Raises: ValueError on missing config, requests.HTTPError on API failure.
    """
    if not str(query or '').strip():
        return []
    if not str(app_id or '').strip():
        raise ValueError('楽天アプリケーションIDが未設定です')
    params = {
        'applicationId': app_id,
        'keyword': query.strip(),
        'hits': max(1, min(30, int(limit) if str(limit).isdigit() else 20)),
        'format': 'json',
        'imageFlag': 1,
        'availability': 1,
    }
    if affiliate_id:
        params['affiliateId'] = affiliate_id
    resp = requests.get(RAKUTEN_SEARCH_ENDPOINT, params=params, timeout=timeout)
    resp.raise_for_status()
    payload = resp.json()
    results = []
    for entry in payload.get('Items', []) or []:
        item = entry.get('Item') if isinstance(entry, dict) else None
        if not isinstance(item, dict):
            continue
        image_url = ''
        for image_entry in (item.get('mediumImageUrls') or item.get('smallImageUrls') or []):
            if isinstance(image_entry, dict):
                image_url = image_entry.get('imageUrl') or ''
            elif isinstance(image_entry, str):
                image_url = image_entry
            if image_url:
                break
        results.append({
            'name': str(item.get('itemName') or '').strip(),
            'price': item.get('itemPrice'),
            'url': str(item.get('affiliateUrl') or item.get('itemUrl') or '').strip(),
            'image_url': re.sub(r'\?_ex=\d+x\d+$', '', image_url),
            'shop_name': str(item.get('shopName') or '').strip(),
            'review_count': item.get('reviewCount') or 0,
            'review_avg': item.get('reviewAverage') or 0,
            'item_caption': str(item.get('itemCaption') or '').strip()[:240],
        })
    return results


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
- 見出し（H2/H3）は **SEO を意識して具体的に**。「選び方」「よくある質問」「まとめ」のような単語だけの見出しは避け、主要キーワードや検索意図に沿った語を自然に含める（例:「冷感ヘッドバンドの選び方｜5つのチェックポイント」「冷感ヘッドバンドのよくある質問」「まとめ｜用途で選ぶのが失敗しないコツ」）。ただし全見出しにキーワードを詰め込みすぎない（過剰最適化を避ける）
- 「結論早見表」「おすすめ早見表」のような早見表セクションは作らない（比較表があれば十分。重複は不要）
- 装飾は <strong>太字</strong>、<span style="color:#d32f2f">赤字</span>、<mark>マーカー</mark>、<ul><li>リスト</li></ul>、<table>表</table> だけを使う
- 装飾目的の複雑なdiv、独自class、吹き出し、ボックス、カード、GutenbergブロックHTMLは出力しない
- 比較表は横幅が崩れにくいように列を増やしすぎず、セル内は短くする
- 1つの<p>は必ず2〜3文以内で改行する。4文以上の長い<p>は読みづらいので絶対に作らない。
  例（良い）: <p>○○です。△△が特徴です。</p><p>一方で□□には注意が必要です。</p>
  例（悪い・禁止）: <p>○○です。△△が特徴で、一方で□□には注意が必要で、さらに××もあって...</p>
- 断定しすぎず、選び方・比較理由・向いている人・注意点を具体的に書く
- 広告カード/アフィリエイトリンク/RINKER風カードHTMLは自分で書かない。Affiros9 側で各商品見出し（<h3>N位：商品名</h3>）の直後に自動で商品カードを挿入するので、本文側ではHTMLマーカーやAFFI番号を書く必要はない"""


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
        r'(<h[2-4]\b|<p\b|<ul\b|<ol\b|<table\b|<div\b|<!--\s*wp:(?!/)|<!--\s*ai-product)',
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
        # コメントノードは str() すると <!--..--> が剥がれるので明示的に復元
        def _serialize_child(child):
            if BS4Comment is not None and isinstance(child, BS4Comment):
                return f'<!--{str(child)}-->'
            return str(child)
        html = ''.join(_serialize_child(child) for child in root.contents)
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


def _split_inner_html_by_sentence(inner_html):
    """インライン HTML を文末記号で分割（タグの開閉を保護）

    - 文末記号: 。！？!?
    - depth == 0 のときだけ分割（タグの中の。は無視）
    - 直後が 」 』 ） ) の場合は分割しない（引用閉じ前を保護）
    """
    if not inner_html:
        return []
    parts = []
    current = ''
    depth = 0
    i = 0
    n = len(inner_html)
    void_tags_re = re.compile(r'<(br|img|hr|meta|link|input)\b', re.I)
    while i < n:
        c = inner_html[i]
        if c == '<':
            tag_end = inner_html.find('>', i)
            if tag_end == -1:
                current += inner_html[i:]
                break
            tag_content = inner_html[i:tag_end + 1]
            if tag_content.startswith('</'):
                depth = max(0, depth - 1)
            elif not tag_content.endswith('/>') and not void_tags_re.match(tag_content):
                depth += 1
            current += tag_content
            i = tag_end + 1
            continue
        if depth == 0 and c in '。！？!?':
            current += c
            next_c = inner_html[i + 1] if i + 1 < n else ''
            i += 1
            # 閉じ括弧の前では分割しない（「内側」。 のような構造を保護）
            if next_c and next_c in '」』）)':
                continue
            if current.strip():
                parts.append(current)
                current = ''
            continue
        current += c
        i += 1
    if current.strip():
        parts.append(current)
    return parts


def split_paragraphs_per_sentence(soup):
    """各 <p> を文末記号で分割し、1文1段落 (1文改行) スタイルにする

    - <strong>/<a>/<em>/<mark>/<span> 等のインラインタグは保持
    - 画像・表・リスト等のブロック要素を含む <p> はスキップ
    - 結果として短すぎる断片（10文字未満）は前の文と結合
    """
    if not BeautifulSoup:
        return
    for p in list(soup.find_all('p')):
        # ブロック要素を含む段落はスキップ
        if p.find(['img', 'table', 'ul', 'ol', 'div', 'figure', 'iframe']):
            continue
        inner_html = ''.join(str(child) for child in p.contents)
        if not inner_html.strip():
            continue
        parts = _split_inner_html_by_sentence(inner_html)
        if len(parts) <= 1:
            continue
        # 短い断片を前の文と結合
        merged = []
        for part in parts:
            text_only = re.sub(r'<[^>]+>', '', part).strip()
            if merged and len(text_only) < 10:
                merged[-1] = merged[-1] + part
            else:
                merged.append(part)
        if len(merged) <= 1:
            continue
        # 既存 <p> の前に新 <p> 群を挿入し、元の <p> を削除
        for part in merged:
            wrapped = f'<p>{part.strip()}</p>'
            tmp = BeautifulSoup(wrapped, 'html.parser')
            new_p = tmp.find('p')
            if new_p is None:
                continue
            p.insert_before(new_p.extract())
        p.decompose()


def merge_duplicate_label_bullets(soup):
    """同じラベル（例「向いている人：」）が連続する <ul> bullet を1つにマージする

    Claude が <ul> 内で同じラベルを複数行で繰り返した場合、
    値部分をカンマ区切りで1行にまとめる。
    例:
      <li><strong>向いている人</strong>：A</li>
      <li><strong>向いている人</strong>：B</li>
      <li><strong>向いている人</strong>：C</li>
      →
      <li><strong>向いている人</strong>：A、B、C</li>
    """
    if not BeautifulSoup:
        return
    label_pattern = re.compile(r'^\s*(?:<strong>)?([^：:<]{1,20})(?:</strong>)?\s*[：:]\s*(.*)$', re.S)
    for ul in list(soup.find_all('ul')):
        items = list(ul.find_all('li', recursive=False))
        if len(items) < 2:
            continue
        # 各 <li> をラベル/値に分解
        parsed = []
        for li in items:
            inner = li.decode_contents().strip()
            m = label_pattern.match(inner)
            if m:
                label = m.group(1).strip()
                value = m.group(2).strip()
                parsed.append((label, value, li))
            else:
                parsed.append((None, inner, li))

        # ラベルでグループ化（連続だけでなく ul 全体）
        from collections import OrderedDict
        groups = OrderedDict()
        for label, value, li in parsed:
            key = label if label else f'__no_label_{id(li)}'
            if key not in groups:
                groups[key] = {'label': label, 'values': [], 'first_li': li, 'extra_lis': []}
            else:
                groups[key]['extra_lis'].append(li)
            groups[key]['values'].append(value)

        # 重複ラベルがなければスキップ
        if all(len(g['extra_lis']) == 0 for g in groups.values()):
            continue

        # 各グループを1つの li にマージ
        for key, g in groups.items():
            if not g['extra_lis']:
                continue
            label = g['label']
            values = [v for v in g['values'] if v.strip()]
            # 重複値を除去
            seen = set()
            unique_values = []
            for v in values:
                if v not in seen:
                    seen.add(v)
                    unique_values.append(v)
            merged_value = '、'.join(unique_values)
            new_inner = f'<strong>{label}</strong>：{merged_value}' if label else merged_value
            # 最初の li の内容を置き換え
            wrapped = BeautifulSoup(f'<li>{new_inner}</li>', 'html.parser')
            new_li = wrapped.find('li')
            if new_li:
                g['first_li'].clear()
                for child in list(new_li.contents):
                    g['first_li'].append(child.extract())
            # 残りの li を削除
            for extra in g['extra_lis']:
                extra.decompose()


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
    split_paragraphs_per_sentence(root)
    merge_duplicate_label_bullets(root)
    add_marker_to_first_keyword(root, keyword)
    # コメントノード（ai-product マーカー等）は str() すると <!--...--> が剥がれるので明示復元
    def _serialize_enhance_child(child):
        if BS4Comment is not None and isinstance(child, BS4Comment):
            return f'<!--{str(child)}-->'
        return str(child)
    return format_block_html(''.join(_serialize_enhance_child(child) for child in root.contents))


def safe_enhance_generated_article_html(content, article, article_type):
    try:
        return enhance_generated_article_html(content, article, article_type), ''
    except Exception as e:
        # 装飾後処理は致命的ではない（本文は保存される）。
        # ユーザーに見せない代わりに Render ログに traceback を残して原因究明できるように。
        try:
            app.logger.warning(
                '[enhance-skip] article=%s type=%s err=%s\n%s',
                (article or {}).get('id', '?'),
                article_type,
                e,
                traceback.format_exc(),
            )
        except Exception:
            pass
        html = sanitize_generated_html(content)
        # 警告は記事に保存しない（ユーザー側でノイズになるため）
        return format_block_html(html), ''


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
    # HTMLコメント（ai-productマーカー等）はwp:htmlブロックで包んで原型を維持
    # 通常の段落変換を通すとコメント形式が失われプラグインに認識されない
    if BS4Comment is not None and isinstance(node, BS4Comment):
        comment_text = str(node)
        if not comment_text.strip():
            return ''
        return wp_block('html', f'<!--{comment_text}-->')
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
    }
    return mapping.get(raw, default)


def article_type_label(article_type):
    return {
        'ranking': 'ランキング記事',
        'brand': '商標記事',
        'column': 'コラム記事',
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


def auto_slug_from_brand_name(product_name):
    """商品名から英字 slug を自動生成（商標記事用）。

    商品名の英字・数字トークンを抽出してハイフン連結し、末尾に '-review' を付ける。
    例:
      'Andeor ネックウォーマー'        → 'andeor-review'
      'CHIC DIARY バラクラバ'          → 'chic-diary-review'
      'Odejaa 360°フェイスカバー'      → 'odejaa-360-review'
    英字トークンが全く無い場合は空文字（呼び出し側でフォールバックを判断）。
    """
    raw = str(product_name or '')
    if not raw.strip():
        return ''
    # 全角英数字を半角化
    normalized = unicodedata.normalize('NFKC', raw).lower()
    # 英数字トークン抽出
    tokens = re.findall(r'[a-z0-9]+', normalized)
    if not tokens:
        return ''
    # 各トークンを15字以内に、全体40字以内に
    tokens = [t[:15] for t in tokens if t][:5]
    base = re.sub(r'-{2,}', '-', '-'.join(tokens)).strip('-')[:40].strip('-')
    if not base:
        return ''
    return f'{base}-review'


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
    """
    タイトル案生成フロー専用のタイプ正規化。
    商標記事（brand）は別ワークフロー（ランキング→商標化）で作るため、
    このフローでは brand を返さず、ranking か column に振り分ける。
    """
    normalized = normalize_article_type(value, '')
    if normalized == 'brand':
        # brand相当のシグナルがある場合、商品比較性が見えればranking、それ以外は column 寄せ
        text = f'{keyword} {title}'
        if re.search(r'(?:おすすめ|比較|ランキング|人気|厳選|ベスト|[0-9０-９]+\s*選)', text):
            return 'ranking'
        return 'column'
    if normalized in ('ranking', 'column'):
        return normalized
    inferred = infer_title_article_type(keyword, title)
    return 'ranking' if inferred == 'ranking' else 'column'


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


def title_generation_prompt(keywords, count_per_keyword, category='', article_type_filter=None, categories=None):
    d = load_title_definition()
    forbidden_list = '、'.join(d.get('forbidden_phrases') or [])
    additional = (d.get('additional_instructions') or '').strip()
    additional_block = f'\n【追加指示】\n{additional}\n' if additional else ''

    # カテゴリー振り分けブロックとJSONフィールドを構築
    valid_categories = [str(c.get('name') or '').strip() for c in (categories or []) if isinstance(c, dict) and str(c.get('name') or '').strip()]
    if valid_categories:
        cat_names = '\n'.join(f'- {n}' for n in valid_categories)
        category_block = (
            f'\n【カテゴリー振り分け（必須）】\n'
            f'以下のカテゴリーから最も適切なものを1つ選び、"category" フィールドにカテゴリー名をそのまま入れてください。\n'
            f'{cat_names}\n'
            f'※ リスト以外のカテゴリー名は禁止。必ずリスト内の名前を使うこと。\n'
        )
        category_json_field = '      "category": "上記カテゴリーリストから選択（必須）",'
    else:
        category_block = f'\nカテゴリー: {category or "未指定"}\n' if category else ''
        category_json_field = ''

    # サンプル例を記事種別でフィルタ（ランキング例 = \d+選 を含む）
    all_examples = [t for t in (d.get('example_titles') or []) if t and t.strip()]
    if article_type_filter == 'ranking':
        examples = [t for t in all_examples if re.search(r'\d+選', t)]
    elif article_type_filter == 'column':
        examples = [t for t in all_examples if not re.search(r'\d+選', t)]
    else:
        examples = all_examples
    examples_block = (
        '\n【タイトル参考例（この水準・スタイルを目標に）】\n'
        + '\n'.join(f'- {t}' for t in examples)
        + '\n'
    ) if examples else ''

    # ranking追加ルールはcolumn固定時は不要
    ranking_rule_block = (
        '\n【ranking のときの追加ルール】\n'
        f'- **必ず「おすすめ」と「○選」を両方入れる**。デフォルトは{d["ranking_default_count"]}選、最大{d["ranking_max_count"]}選。\n'
    ) if article_type_filter != 'column' else ''

    if article_type_filter == 'ranking':
        type_intro = f'以下のキーワードごとに、**ランキング記事**のタイトル案を{count_per_keyword}個ずつ作ってください。'
        article_type_field = '"ランキング"（固定・変更禁止）'
        type_rule_block = (
            '【記事種別固定: ランキング記事（ranking）】\n'
            '全てのタイトル案を ranking として生成してください。\n'
            'article_type フィールドは必ず "ranking" を返すこと。column や brand は絶対に返さないこと。'
        )
    elif article_type_filter == 'column':
        type_intro = f'以下のキーワードごとに、**コラム記事**のタイトル案を{count_per_keyword}個ずつ作ってください。'
        article_type_field = '"コラム"（固定・変更禁止）'
        type_rule_block = (
            '【記事種別固定: コラム記事（column）】\n'
            '全てのタイトル案を column（解説・情報・ハウツー記事）として生成してください。\n'
            'article_type フィールドは必ず "column" を返すこと。ranking や brand は絶対に返さないこと。'
        )
    else:
        type_intro = f'以下のキーワードごとに、検索意図に合うタイトル案を{count_per_keyword}個ずつ作り、記事種類も自動分類してください。'
        article_type_field = '"ranking または column のいずれか（brand は禁止）"'
        type_rule_block = (
            '【記事種類判定】\n'
            '- 広い商品ジャンルの「おすすめ・比較・人気」は ranking。\n'
            '- 「とは・選び方・使い方・原因・対策・違い」は column。\n'
            '- **brand（商標記事）は絶対に生成しない**。商標記事は別ワークフロー（ランキング記事 → 商品抽出）で作るため、ここでは brand を返さないでください。\n'
            '- 具体的な商品名・型番が含まれるKWでも、ここでは ranking か column のどちらかに振り分けてください\n'
            '  （例: 「Andeor 防水バッグ 口コミ」のような商品名KWでも、ここでは無理に取り扱わず column 扱いで構いません）。'
        )

    return f"""あなたはSEO記事の編集者です。クリックされる記事タイトルを設計してください。

{type_intro}
{category_block}
キーワード:
{chr(10).join(f'- {kw}' for kw in keywords)}

出力形式（全フィールド必須・特に title は必ず入れる）:
{{
  "ideas": [
    {{
      "title": "記事タイトル（必須・最重要・絶対に省略しない）",
      "keyword": "対象キーワード（入力されたKWそのまま）",
      "target_keywords": "メインKW, 関連KW1, 関連KW2, 関連KW3",
      "slug": "english-slug",
      "article_type": {article_type_field},
{category_json_field}
      "search_intent": "読者の検索意図を短く",
      "reason": "このタイトルにした理由を短く",
      "priority": "高/中/低"
    }}
  ]
}}

【target_keywords 生成ルール】
- `target_keywords` は **記事のSEOターゲットKWリスト**。カンマ区切りで2〜4個。
- 1個目は入力された対象キーワード（メインKW）そのままを入れる。
- 2個目以降は **同じ検索意図に近い関連サジェスト語** を入れる。
  例: メインKWが「防水バッグ」なら「防水バッグ おすすめ」「防水バッグ 比較」「防水バッグ 安い」など。
- メインKWの形を活かした複合語（メインKW + 修飾語）を中心に。完全に別ジャンルのKWは入れない。
- 後で記事生成時にClaudeへ渡されSEO構成のヒントになる。Amazon/楽天検索のヒントにもなる。

{type_rule_block}

【ルール】
- **文字数は{d['char_max']}字以内**（SERPでの表示切れを防ぐ絶対上限）
- メインキーワードはタイトルの先頭〜中盤に置く
- 同じKW内でタイトルが似た構文・似た語尾にならないよう切り口を変える
- 以下の表現は使用禁止: {forbidden_list}

{ranking_rule_block}
【slug】
- slug は英語のみ・小文字・ハイフン区切り（kebab-case）。3〜4単語、最大30文字以内。
  記事内容を端的に表すSEOフレンドリーな英語に翻訳/要約する（直訳のローマ字化は禁止）。
  例: 「ネックウォーマーおすすめランキング」→「neck-warmer-ranking」。
{examples_block}{additional_block}
【出力】
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


def coerce_title_ideas(payload, keywords, count_per_keyword, article_type_filter=None):
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
        # 縦棒は1タイトル最大1個まで。2個目以降の縦棒があったら、その位置でカット。
        pipe_positions = [m.start() for m in re.finditer(r'[｜|]', title)]
        if len(pipe_positions) >= 2:
            title = title[:pipe_positions[1]].rstrip()
        title = re.sub(r'\s+', ' ', title).strip()
        keyword = str(item.get('keyword') or '').strip()
        matched = keyword_set.get(normalize_title_key(keyword))
        if not matched:
            matched = next((kw for kw in keywords if kw in title), '')
        raw_slug = re.sub(r'[^a-z0-9-]', '', normalize_slug(str(item.get('slug') or '').lower()))[:30].strip('-')
        # target_keywords は記事のSEOターゲットKWリスト（カンマ区切り）。
        # Claudeが配列or文字列で返してくる可能性に対応。古い `keywords` フィールドもフォールバックで受ける。
        raw_kws = item.get('target_keywords')
        if raw_kws is None:
            raw_kws = item.get('keywords')
        if isinstance(raw_kws, list):
            kw_list = [str(k).strip() for k in raw_kws if str(k).strip()]
        else:
            kw_list = [k.strip() for k in re.split(r'[,、]', str(raw_kws or '')) if k.strip()]
        # 重複除去（順序保持）+ 上限4個
        seen_kw = set()
        deduped = []
        for k in kw_list:
            kl = k.lower()
            if kl in seen_kw:
                continue
            seen_kw.add(kl)
            deduped.append(k)
        main_kw = matched or keyword or (keywords[0] if keywords else '')
        # メインKWが先頭に無ければ差し込む
        if main_kw and (not deduped or deduped[0].lower() != main_kw.lower()):
            deduped = [main_kw] + [k for k in deduped if k.lower() != main_kw.lower()]
        keywords_csv = ', '.join(deduped[:4])
        idea = {
            'keyword': main_kw,
            'keywords': keywords_csv,
            'title': title[:120],
            'slug': raw_slug,
            'search_intent': str(item.get('search_intent') or item.get('intent') or '').strip()[:160],
            'reason': str(item.get('reason') or '').strip()[:220],
            'priority': str(item.get('priority') or '中').strip()[:10],
            'article_type': article_type_filter or coerce_title_article_type(item.get('article_type'), matched or keyword, title),
            'category': str(item.get('category') or '').strip()[:80],
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


def enrich_title_ideas(ideas, category='', site_id='', existing_title_keys=None):
    """
    existing_title_keys: 呼び出し側で事前計算したキーセット（重複チェック用）。
    None の場合は articles.json をロードして毎回計算する（後方互換）。
    大量バッチ並列実行時のI/O負荷を避けるため、ワーカーは事前に1度だけ計算して渡す。
    """
    if existing_title_keys is None:
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
        keywords_csv = str(idea.get('keywords') or '').strip() or keyword
        article_type = coerce_title_article_type(idea.get('article_type'), keyword, title)
        score = score_title_idea(title, keyword, article_type, existing_title_keys)
        enriched.append({
            'id': str(uuid.uuid4()),
            'keyword': keyword,
            'keywords': keywords_csv,
            'title': title,
            'slug': str(idea.get('slug') or '').strip(),
            'search_intent': str(idea.get('search_intent') or '').strip(),
            'reason': str(idea.get('reason') or '').strip(),
            'priority': str(idea.get('priority') or ('高' if score >= 82 else '中')).strip() or '中',
            'score': score,
            'duplicate': key in existing_title_keys,
            'article_type': article_type,
            'category': str(idea.get('category') or '').strip() or category,
            'site_id': site_id or None,
            'quality_id': None,
        })
    return enriched


def estimate_tokens_from_text(text):
    return max(1, math.ceil(len(str(text or '')) / 2))


def extract_usage_value(usage, name):
    if not usage:
        return None
    if isinstance(usage, dict):
        return usage.get(name)
    return getattr(usage, name, None)


def build_article_usage(prompt, content, message=None):
    model_id = get_article_model()
    pricing = CLAUDE_ARTICLE_MODEL_PRICING.get(model_id, CLAUDE_ARTICLE_MODEL_PRICING['claude-sonnet-4-6'])
    in_rate = pricing['input_usd_per_mtok']
    out_rate = pricing['output_usd_per_mtok']
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
    cost_usd = (input_tokens / 1_000_000 * in_rate) + (output_tokens / 1_000_000 * out_rate)
    return {
        'model': model_id,
        'input_tokens': int(input_tokens),
        'output_tokens': int(output_tokens),
        'cost_usd': round(cost_usd, 6),
        'cost_yen': round(cost_usd * USAGE_ESTIMATE_USD_JPY, 2),
        'estimated': estimated,
        'pricing': {
            'input_usd_per_mtok': in_rate,
            'output_usd_per_mtok': out_rate,
            'usd_jpy': USAGE_ESTIMATE_USD_JPY,
        }
    }


def combine_article_usages(usages):
    valid = [u for u in (usages or []) if isinstance(u, dict)]
    if not valid:
        return build_article_usage('', '')
    model_id = get_article_model()
    pricing = CLAUDE_ARTICLE_MODEL_PRICING.get(model_id, CLAUDE_ARTICLE_MODEL_PRICING['claude-sonnet-4-6'])
    in_rate = pricing['input_usd_per_mtok']
    out_rate = pricing['output_usd_per_mtok']
    input_tokens = sum(int(u.get('input_tokens') or 0) for u in valid)
    output_tokens = sum(int(u.get('output_tokens') or 0) for u in valid)
    cost_usd = (input_tokens / 1_000_000 * in_rate) + (output_tokens / 1_000_000 * out_rate)
    return {
        'model': model_id,
        'input_tokens': int(input_tokens),
        'output_tokens': int(output_tokens),
        'cost_usd': round(cost_usd, 6),
        'cost_yen': round(cost_usd * USAGE_ESTIMATE_USD_JPY, 2),
        'estimated': any(bool(u.get('estimated')) for u in valid),
        'calls': len(valid),
        'pricing': {
            'input_usd_per_mtok': in_rate,
            'output_usd_per_mtok': out_rate,
            'usd_jpy': USAGE_ESTIMATE_USD_JPY,
        }
    }


def create_claude_message(client, prompt, max_tokens=None, timeout=None, model=None):
    messages_api = getattr(client, 'messages', None)
    create = getattr(messages_api, 'create', None)
    if not callable(create):
        raise RuntimeError('Claude API client is not ready: messages.create is unavailable')
    kwargs = {
        'model': model or get_article_model(),
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


def is_overload_error(error):
    """Claude API の一時的な過負荷（HTTP 529 / overloaded_error）かどうか。

    529 は Anthropic サーバ側の混雑で、こちらの設定やコードの不具合ではない。
    数十秒〜数分で回復するので、長めに待ってリトライすれば成功する。
    """
    text = str(error or '').lower()
    return 'overloaded' in text or 'error code: 529' in text


def title_idea_max_tokens(keyword_count, count_per_keyword):
    # max_tokens は「安全上限」であって課金基準ではない（Anthropic は実際の出力分だけ課金）。
    # Haiku 4.5 / Sonnet どちらも余裕で対応できる 8000 固定にして、
    # 出力途中切れ（stop_reason=max_tokens → JSON 不完全 → coerce 空）を根絶する。
    return 8000


def claude_title_idea_models():
    models = []
    for model in [CLAUDE_TITLE_IDEA_MODEL] + CLAUDE_TITLE_IDEA_FALLBACK_MODELS:
        if model and model not in models:
            models.append(model)
    return models


def is_model_not_found_error(error):
    text = str(error or '').lower()
    return 'not_found' in text or 'model' in text and '404' in text


def generate_claude_title_ideas_once(api_key, keywords, count_per_keyword, category, article_type_filter=None, categories=None):
    prompt = title_generation_prompt(keywords, count_per_keyword, category, article_type_filter, categories)
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
            stop_reason = getattr(message, 'stop_reason', None) or (
                message.get('stop_reason') if isinstance(message, dict) else None
            )
            ideas = coerce_title_ideas(extract_title_ideas_payload(text), keywords, count_per_keyword, article_type_filter)
            if not ideas:
                # デバッグ用：失敗時に stop_reason と Claudeの応答先頭/末尾をログに残す。
                # max_tokens で切れている場合は length-truncated と分かる。
                snippet_head = (text or '')[:300].replace('\n', ' ')
                snippet_tail = (text or '')[-200:].replace('\n', ' ')
                app.logger.warning(
                    '[TITLE-IDEA] no usable ideas. model=%s stop_reason=%s text_len=%d kw_count=%d head=%r tail=%r',
                    model, stop_reason, len(text or ''), len(keywords), snippet_head, snippet_tail
                )
                raise ValueError('Claude returned no usable title ideas')
            return ideas, model
        except Exception as e:
            last_error = e
            app.logger.warning('Claude title idea model failed (%s): %s', model, e)
            if not is_model_not_found_error(e):
                break
    raise last_error or RuntimeError('Claude title idea generation failed')


def generate_claude_title_ideas_resilient(api_key, keywords, count_per_keyword, category, categories=None):
    retry_notes = []
    try:
        ideas, model_used = generate_claude_title_ideas_once(api_key, keywords, count_per_keyword, category, categories=categories)
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
            chunk_ideas, chunk_model = generate_claude_title_ideas_once(api_key, [keyword], count_per_keyword, category, categories=categories)
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
    generated_at = generated_at or now_iso()
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
        if ranked_count < ranking_expected:
            caps.append(35)
            penalties += 25
            suggestions.append(f'タイトルは{ranking_expected}選ですが、個別ランキング見出しが{ranked_count}件しかありません。')
        # 比較表は plugin の compare デザインで描画されるため、本文中のテーブル有無はスコア評価しない

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
        'scored_at': now_iso(),
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
        return '検索順位や品質評価に関わる重要な更新です。記事改善の判断材料として確認してください。'
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
    count = extract_ranking_count(article) or 5  # ranking は最低5選
    return f"""

ランキング件数の厳守:
- タイトルから「{count}選」と判断しています。本文では必ず{count}件を紹介してください。
- 個別解説は「1位」から「{count}位」まで欠番・重複なしで作ってください。
- {count}件未満で終了しないでください。商品名や候補が不足する場合でも、記事テーマに合う候補を補って{count}件にしてください。"""


def build_ranking_structure_prompt(article, article_type):
    if normalize_article_type(article_type, 'ranking') != 'ranking':
        return ''
    count = extract_ranking_count(article) or 5  # ranking は最低5選
    return f"""

ランキング記事の必須構成:
- リード文 → 選定基準 → ランキング本文 → 選び方 → FAQ → まとめ、の順で書いてください。
- **比較表・早見表は絶対に本文に書かないでください**（後処理で別途レンダリングされます）。
- ランキング本文では、必ず <h3>1位：商品名</h3> から <h3>{count}位：商品名</h3> まで、順位番号入りのh3見出しを{count}個出してください。
- 各順位のh3ごとに、特徴・おすすめな人・注意点を最低2段落以上で書いてください。
- {count}位まで書き終える前に「選び方」「FAQ」「まとめ」へ進まないでください。
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
    """検証用の最低文字数。これより短いと「生成失敗」扱いになる。

    冗長化を避けるため、target に対する比率は意図的に低めに設定。
    自然に書いた結果として短くなっても合格扱いとする。
    """
    target = effective_target_chars(quality)
    # 300文字を底辺に、目標の30%程度を最低限の合格ライン。
    # これ以下なら「途中で止まった/構造不足」の可能性が高い。
    return max(300, int(target * 0.3))


def claude_max_tokens_for_quality(quality=None, floor=3200, ceiling=12000):
    target = effective_target_chars(quality)
    return max(floor, min(ceiling, int(target * 2.2) + 2400))


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
- 記事本文の文字量目安: 日本語本文換算で約{target}文字。ただしこれは **ガイドライン** であり厳格な目標ではない。
- 情報の質と必要十分な密度を優先。文字数のために冗長な前置き・繰り返し・水増しはしないこと。自然に書いた結果として目安より短くなっても構わない。
- すべての主要見出しを書き切り、最後に必ず「まとめ」セクションで記事を完結させてください。
- 途中で出力が長くなりそうな場合は、{priority}を優先し、装飾や冗長な説明を削ってください。
{extra_text}
"""


AMAZON_PAAPI_HOST = 'webservices.amazon.co.jp'
AMAZON_PAAPI_PATH = '/paapi5/searchitems'
AMAZON_PAAPI_REGION = 'us-west-2'
AMAZON_PAAPI_SERVICE = 'ProductAdvertisingAPI'
AMAZON_PAAPI_TARGET = 'com.amazon.paapi5.v1.ProductAdvertisingAPIv1.SearchItems'


def amazon_search(query, access_key, secret_key, partner_tag, limit=10, timeout=10):
    """Amazon PA-API v5 SearchItems を SigV4 署名付きで叩く。

    Returns: list[dict] with keys: name, price, url, image_url, asin,
             review_count, review_avg.
    Raises: ValueError on missing config, requests.HTTPError on API failure.
    """
    if not str(query or '').strip():
        return []
    if not (access_key and secret_key and partner_tag):
        raise ValueError('Amazon PA-API の設定が不完全です（Access Key / Secret / Partner Tag が必要）')

    body = json.dumps({
        'Keywords': query.strip(),
        'Resources': [
            'Images.Primary.Medium',
            'ItemInfo.Title',
            'ItemInfo.Features',
            'Offers.Listings.Price',
            'Offers.Summaries.LowestPrice',
            'CustomerReviews.StarRating',
            'CustomerReviews.Count',
        ],
        'PartnerTag': partner_tag,
        'PartnerType': 'Associates',
        'Marketplace': 'www.amazon.co.jp',
        'ItemCount': max(1, min(10, int(limit) if str(limit).isdigit() else 10)),
    }, separators=(',', ':'))
    body_bytes = body.encode('utf-8')
    body_hash = hashlib.sha256(body_bytes).hexdigest()

    now = datetime.utcnow()
    amz_date = now.strftime('%Y%m%dT%H%M%SZ')
    date_stamp = now.strftime('%Y%m%d')

    canonical_headers = (
        f'content-encoding:amz-1.0\n'
        f'host:{AMAZON_PAAPI_HOST}\n'
        f'x-amz-date:{amz_date}\n'
        f'x-amz-target:{AMAZON_PAAPI_TARGET}\n'
    )
    signed_headers = 'content-encoding;host;x-amz-date;x-amz-target'
    canonical_request = f'POST\n{AMAZON_PAAPI_PATH}\n\n{canonical_headers}\n{signed_headers}\n{body_hash}'

    credential_scope = f'{date_stamp}/{AMAZON_PAAPI_REGION}/{AMAZON_PAAPI_SERVICE}/aws4_request'
    string_to_sign = (
        f'AWS4-HMAC-SHA256\n{amz_date}\n{credential_scope}\n'
        f'{hashlib.sha256(canonical_request.encode("utf-8")).hexdigest()}'
    )

    def hmac_sha256(key, msg):
        return hmac.new(key, msg.encode('utf-8'), hashlib.sha256).digest()

    k_date = hmac_sha256(('AWS4' + secret_key).encode('utf-8'), date_stamp)
    k_region = hmac_sha256(k_date, AMAZON_PAAPI_REGION)
    k_service = hmac_sha256(k_region, AMAZON_PAAPI_SERVICE)
    k_signing = hmac_sha256(k_service, 'aws4_request')
    signature = hmac.new(k_signing, string_to_sign.encode('utf-8'), hashlib.sha256).hexdigest()

    headers = {
        'Content-Type': 'application/json; charset=UTF-8',
        'Content-Encoding': 'amz-1.0',
        'Host': AMAZON_PAAPI_HOST,
        'X-Amz-Date': amz_date,
        'X-Amz-Target': AMAZON_PAAPI_TARGET,
        'Authorization': (
            f'AWS4-HMAC-SHA256 Credential={access_key}/{credential_scope}, '
            f'SignedHeaders={signed_headers}, Signature={signature}'
        ),
    }

    resp = requests.post(f'https://{AMAZON_PAAPI_HOST}{AMAZON_PAAPI_PATH}', data=body_bytes, headers=headers, timeout=timeout)
    resp.raise_for_status()
    payload = resp.json()

    results = []
    for item in (payload.get('SearchResult') or {}).get('Items') or []:
        title = ((item.get('ItemInfo') or {}).get('Title') or {}).get('DisplayValue') or ''
        offers = item.get('Offers') or {}
        listings = offers.get('Listings') or []
        summaries = offers.get('Summaries') or []
        price_amount = None
        price_display = ''
        # まず Listings から取得
        if listings:
            price_obj = (listings[0] or {}).get('Price') or {}
            price_amount = price_obj.get('Amount')
            price_display = price_obj.get('DisplayAmount') or ''
        # Listings に無ければ Summaries.LowestPrice にフォールバック
        if not price_amount and not price_display and summaries:
            for summary in summaries:
                low = (summary or {}).get('LowestPrice') or {}
                if low.get('Amount') or low.get('DisplayAmount'):
                    price_amount = price_amount or low.get('Amount')
                    price_display = price_display or low.get('DisplayAmount') or ''
                    break
        image_url = (((item.get('Images') or {}).get('Primary') or {}).get('Medium') or {}).get('URL', '')
        reviews = item.get('CustomerReviews') or {}
        review_count_raw = reviews.get('Count')
        if isinstance(review_count_raw, dict):
            review_count = review_count_raw.get('Value') or 0
        else:
            review_count = review_count_raw or 0
        review_avg_raw = reviews.get('StarRating')
        if isinstance(review_avg_raw, dict):
            review_avg = review_avg_raw.get('Value') or 0
        else:
            review_avg = review_avg_raw or 0
        results.append({
            'name': title.strip(),
            'price': price_amount,
            'price_display': price_display,
            'url': item.get('DetailPageURL') or '',
            'image_url': image_url,
            'asin': item.get('ASIN') or '',
            'review_count': review_count,
            'review_avg': review_avg,
        })
    return results


def _normalize_product_name_for_match(name):
    text = re.sub(r'[【】\[\]（）()「」『』\s\-_/／・,、。!\?！？]+', '', str(name or '').lower())
    return text


def merge_products_by_similarity(rakuten_items, amazon_items, threshold=0.45):
    """楽天とAmazonの商品リストを名前類似度でマージ。返り値の各要素には rakuten/amazon キーが入る（無い側はNone）。"""
    merged = []
    used_amazon = set()
    rakuten_keys = [_normalize_product_name_for_match(r.get('name')) for r in rakuten_items]
    amazon_keys = [_normalize_product_name_for_match(a.get('name')) for a in amazon_items]

    for i, r in enumerate(rakuten_items):
        best_idx = -1
        best_score = 0.0
        for j, a in enumerate(amazon_items):
            if j in used_amazon:
                continue
            if not rakuten_keys[i] or not amazon_keys[j]:
                continue
            score = difflib.SequenceMatcher(None, rakuten_keys[i], amazon_keys[j]).ratio()
            if score > best_score:
                best_score = score
                best_idx = j
        if best_idx >= 0 and best_score >= threshold:
            used_amazon.add(best_idx)
            merged.append({'rakuten': r, 'amazon': amazon_items[best_idx]})
        else:
            merged.append({'rakuten': r, 'amazon': None})

    for j, a in enumerate(amazon_items):
        if j not in used_amazon:
            merged.append({'rakuten': None, 'amazon': a})
    return merged


def fetch_product_context(article, settings, limit=15):
    """商品データを取得して統合リストを返す。

    優先順位:
    1. Amazon PA-API が設定されていれば Amazon のみ使用（CV重視）
    2. Amazon 設定が無いか検索失敗時のみ 楽天 にフォールバック

    Returns: (list[dict], status_string).
    各dictは {'rakuten': {..} or None, 'amazon': {..} or None} 構造。
    """
    query = str(
        article.get('ad_keywords')
        or article.get('keywords')
        or article.get('title')
        or ''
    ).strip()
    if not query:
        return [], 'no_query'

    rakuten_app_id = settings.get('rakuten_app_id') or ''
    rakuten_affiliate_id = settings.get('rakuten_affiliate_id') or ''
    amazon_access_key = settings.get('amazon_access_key') or ''
    amazon_secret_key = settings.get('amazon_secret_key') or ''
    amazon_partner_tag = settings.get('amazon_partner_tag') or ''

    amazon_configured = bool(amazon_access_key and amazon_secret_key and amazon_partner_tag)
    rakuten_configured = bool(rakuten_app_id)

    if not (amazon_configured or rakuten_configured):
        return [], 'no_provider'

    # Amazon優先: 設定があれば最初に試す
    if amazon_configured:
        try:
            amazon_items = amazon_search(query, amazon_access_key, amazon_secret_key, amazon_partner_tag, limit=min(10, limit))
            if amazon_items:
                return [{'amazon': item, 'rakuten': None} for item in amazon_items], 'ok'
        except Exception as e:
            app.logger.warning('Amazon search failed for "%s": %s', query, e)

    # フォールバック: Amazon設定無し or 検索結果ゼロの時だけ楽天を使う
    if rakuten_configured:
        try:
            rakuten_items = rakuten_search(query, rakuten_app_id, rakuten_affiliate_id, limit=limit)
            if rakuten_items:
                return [{'rakuten': item, 'amazon': None} for item in rakuten_items], 'ok'
        except Exception as e:
            app.logger.warning('Rakuten search failed for "%s": %s', query, e)

    return [], 'empty'


def _product_display_name(item):
    return (item or {}).get('name', '').strip()


def _product_display_price(item):
    if not item:
        return ''
    if item.get('price_display'):
        return item['price_display']
    price = item.get('price')
    if isinstance(price, (int, float)) and price:
        return f'¥{int(price):,}'
    return ''


def build_product_context_prompt(products, article_type='ranking'):
    """マージ済み商品リスト [{rakuten, amazon}, ...] をClaudeに渡すためのプロンプトブロックを生成する。"""
    if not products:
        return ''
    is_ranking = article_type == 'ranking'
    rows = []
    for idx, p in enumerate(products, 1):
        rakuten = p.get('rakuten')
        amazon = p.get('amazon')
        primary = rakuten or amazon
        if not primary:
            continue
        name = _product_display_name(primary)[:90]
        availability_parts = []
        if rakuten:
            availability_parts.append('楽天')
        if amazon:
            availability_parts.append('Amazon')
        availability = '・'.join(availability_parts)
        prices = []
        for src, label in ((rakuten, '楽天'), (amazon, 'Amazon')):
            disp = _product_display_price(src)
            if disp:
                prices.append(f'{label}: {disp}')
        price_text = ' / '.join(prices)
        review_parts = []
        for src in (rakuten, amazon):
            if not src:
                continue
            avg = src.get('review_avg')
            cnt = src.get('review_count')
            if isinstance(avg, (int, float)) and avg:
                review_parts.append(f'★{float(avg):.1f} ({int(cnt or 0)}件)')
                break
        caption = (primary.get('item_caption') if rakuten else '') or ''
        caption_line = f'\n   特徴メモ: {caption[:160]}' if caption else ''
        review_line = f'\n   レビュー: {", ".join(review_parts)}' if review_parts else ''
        rows.append(
            f'AFFI:{idx} | 取扱: {availability}\n'
            f'   商品名: {name}\n'
            f'   価格: {price_text}{review_line}{caption_line}'
        )
    header = '実商品データ（楽天市場 / Amazon 検索結果をマージ。AFFI番号はカード挿入用の識別子です）:'
    instruction = (
        '使い方:\n'
        '- 上記の実商品から記事のキーワードに合うものだけを選び、ランキングや比較で紹介してください。\n'
        '- 商品名は上記のものをそのまま使う（架空名や「候補1」は禁止）。\n'
        '- 価格・レビューは上記の数値を引用してください（必要な数値だけでOK）。\n'
        '- 上記に含まれない商品は本文に登場させないでください。\n'
        '- **同じ商品の販売バリエーション**（同じ商品名で別ショップ・別価格）が複数ある場合は\n'
        '  1件として最も代表的な AFFI 番号を選んで紹介。同じ商品を「最安値版」「送料無料版」\n'
        '  のように順位を分けて複数掲載しないこと。\n'
        '\n'
        '**商品カードについて**:\n'
        '商品見出し（<h3>N位：商品名</h3>）の直後に、Affiros9 側で楽天/Amazonの\n'
        '商品カード（画像・価格・レビュー・ボタン）を**自動挿入**します。\n'
        '本文側ではAFFI番号やカードHTMLを書く必要は一切ありません。\n'
        '商品名は商品リストの正確な名前を使ってください（カード挿入の照合に使われます）。'
        if is_ranking else
        '使い方:\n'
        '- 必要なときだけ自然に商品名や価格帯を引用してください。\n'
        '- 上記に含まれない実商品名は新たに発明しないでください。'
    )
    return f"\n\n{header}\n" + '\n'.join(rows) + f"\n\n{instruction}\n"


def build_product_card_html(product):
    """マージ済み商品エントリ {rakuten, amazon} から RINKER スタイルの商品カードHTMLを生成。
    価格・レビュー・ショップ等は <table> のミニ表で表示する。"""
    if not product:
        return ''
    rakuten = product.get('rakuten')
    amazon = product.get('amazon')
    primary = rakuten or amazon
    if not primary:
        return ''
    from html import escape as _esc
    name = _esc(_product_display_name(primary))
    image_url = primary.get('image_url') or (amazon.get('image_url') if amazon else '') or ''

    rows = []
    amazon_price = _product_display_price(amazon)
    rakuten_price = _product_display_price(rakuten)
    if amazon_price and rakuten_price:
        rows.append(f'<tr><th>価格</th><td>Amazon {_esc(amazon_price)} / 楽天 {_esc(rakuten_price)}</td></tr>')
    elif amazon_price:
        rows.append(f'<tr><th>Amazon価格</th><td>{_esc(amazon_price)}</td></tr>')
    elif rakuten_price:
        rows.append(f'<tr><th>楽天価格</th><td>{_esc(rakuten_price)}</td></tr>')

    review_text = ''
    for src in (rakuten, amazon):
        if not src:
            continue
        avg = src.get('review_avg')
        cnt = src.get('review_count')
        if isinstance(avg, (int, float)) and avg:
            review_text = f'★{float(avg):.1f}（{int(cnt or 0):,}件）'
            break
    if review_text:
        rows.append(f'<tr><th>レビュー</th><td>{review_text}</td></tr>')

    shop = (rakuten or {}).get('shop_name', '') if rakuten else ''
    if shop:
        rows.append(f'<tr><th>ショップ</th><td>{_esc(shop[:40])}</td></tr>')

    table_html = f'<table class="aff-product-info"><tbody>{"".join(rows)}</tbody></table>' if rows else ''

    buttons = []
    if amazon and amazon.get('url'):
        buttons.append(f'<a class="aff-btn aff-btn-amazon" href="{_esc(amazon["url"])}" target="_blank" rel="nofollow sponsored noopener">Amazonで見る</a>')
    if rakuten and rakuten.get('url'):
        buttons.append(f'<a class="aff-btn aff-btn-rakuten" href="{_esc(rakuten["url"])}" target="_blank" rel="nofollow sponsored noopener">楽天市場で見る</a>')
    buttons_html = '<div class="aff-product-buttons">' + ''.join(buttons) + '</div>' if buttons else ''
    image_html = (
        f'<div class="aff-product-image"><img src="{_esc(image_url)}" alt="{name}" loading="lazy"></div>'
        if image_url else ''
    )
    return (
        f'<div class="aff-product-card">'
        f'{image_html}'
        f'<div class="aff-product-body">'
        f'<div class="aff-product-name">{name}</div>'
        f'{table_html}'
        f'{buttons_html}'
        f'</div>'
        f'</div>'
    )


def _tokenize_product_name(name):
    """商品名から検索用トークン（日本語+英数字）を抽出。"""
    text = str(name or '').lower()
    # 漢字連続 / ひらがな連続 / カタカナ連続 / 英数字連続 をトークンとして抽出
    tokens = re.findall(
        r'[a-z0-9]+|[一-鿿]+|[゠-ヿ]+|[぀-ゟ]+',
        text
    )
    # 2文字以上のトークンに絞る（ノイズ除去）
    return [t for t in tokens if len(t) >= 2]


def _find_best_product_match(query_name, products, threshold=0.4):
    """商品名（h3見出しテキスト等）から最も類似度の高い商品インデックスを返す。

    Claude の短い商品名 vs Amazon の長い商品名でも安定するよう、
    トークン重複率（クエリ側基準）で評価する。
    """
    if not query_name or not products:
        return None
    query_norm = _normalize_product_name_for_match(query_name)
    if not query_norm:
        return None
    query_tokens = _tokenize_product_name(query_name)
    if not query_tokens:
        return None
    # ノイズ除去: 「ネックウォーマー」「冷感」のような汎用語はトークンとして低価値
    # ブランド名や固有名詞（英数字）の方が一致判定の根拠として強い
    distinctive_tokens = [t for t in query_tokens if re.search(r'[a-z0-9]', t) or len(t) >= 4]
    best_score = 0.0
    best_idx = None
    for idx, p in enumerate(products):
        primary = p.get('rakuten') or p.get('amazon')
        if not primary:
            continue
        pname_norm = _normalize_product_name_for_match(primary.get('name'))
        if not pname_norm:
            continue
        # クエリ側のトークンが商品名にいくつ含まれるか（substring判定）
        matched = sum(1 for t in query_tokens if t.lower() in pname_norm)
        score = matched / len(query_tokens)
        # 識別力の高いトークン（英数字/長い語）が含まれていれば大幅加点
        if distinctive_tokens:
            distinctive_matched = sum(1 for t in distinctive_tokens if t.lower() in pname_norm)
            if distinctive_matched:
                distinctive_ratio = distinctive_matched / len(distinctive_tokens)
                score = max(score, distinctive_ratio * 0.9)
        # 完全包含なら満点扱い
        if query_norm in pname_norm or (len(query_norm) >= 4 and query_norm[:20] in pname_norm):
            score = max(score, 0.95)
        if score > best_score:
            best_score = score
            best_idx = idx
    return best_idx if (best_idx is not None and best_score >= threshold) else None


def strip_leading_introduction_h2(html, title=None):
    """記事冒頭の introduction-style H2 を物理削除し、リード段落を露出させる。

    検出条件:
      (a) intro 系キーワード（とは / 結論 / 完全ガイド / おすすめN選 など）を含むH2
      (b) タイトルと内容が酷似するH2（タイトル繰り返し対策）
      (c) リード文を内包せず即次のH2に続くダミーH2
    """
    if not html:
        return html
    text = str(html)
    m = re.match(
        r'^\s*(?:<!--\s*wp:heading[^>]*-->\s*)?<h2([^>]*)>((?:(?!</h2>)[\s\S])*?)</h2>(?:\s*<!--\s*/wp:heading\s*-->)?',
        text,
        re.IGNORECASE
    )
    if not m:
        return text
    h2_inner = re.sub(r'<[^>]+>', '', m.group(2)).strip()

    # (a) intro キーワード
    intro_keywords = [
        'とは', '結論', '選ぶポイント', '選定ポイント', '本記事の', '解説',
        'について', 'を知る', '記事のポイント',
        '完全ガイド', '完全攻略', '徹底ガイド', '徹底解説', '徹底比較',
        'おすすめ', '比較', 'ランキング', '選び方', '選定基準',
    ]
    is_intro = any(kw in h2_inner for kw in intro_keywords)

    # (b) タイトルとの類似度
    if not is_intro and title:
        def _normalize(s):
            return re.sub(r'[\s\|｜・:：－—\-　]+', '', str(s)).lower()
        norm_title = _normalize(title)
        norm_h2 = _normalize(h2_inner)
        if norm_title and norm_h2:
            # 包含関係 or 強い文字オーバーラップ
            if norm_h2 in norm_title or norm_title in norm_h2:
                is_intro = True
            else:
                # 共通文字の割合（タイトル基準）
                common = sum(1 for c in set(norm_title) if c in set(norm_h2))
                ratio = common / max(1, len(set(norm_title)))
                if ratio >= 0.7:
                    is_intro = True

    if not is_intro:
        return text

    print(f'[INTRO-H2] stripped leading introduction H2: "{h2_inner[:60]}"', flush=True)
    return text[m.end():].lstrip()


def strip_summary_table_sections(html):
    """「早見表」セクションだけを削除する。

    ⚠️ 方針変更（広告挿入位置の安定化）:
      旧実装は「H2直後に<table>があれば問答無用で削除」していたため、
      コラム記事などの**正規の比較表セクションを丸ごと消し**、内容が欠落＋
      後続の広告マーカー位置がずれる原因になっていた。
      比較表はユーザーの正当なコンテンツなので削除しない。
      生成プロンプトで「作るな」と明示している「早見表」だけを掃除する。
    削除範囲: 早見表H2 から「次のH2 / ランキング個別H3 / 末尾」まで。
    """
    if not html:
        return html
    text = str(html)

    # 「早見表」系キーワードを含むH2セクションのみ削除（比較表は残す）
    summary_keywords = '早見表|早分かり|早わかり|一目でわかる|一目で分かる'
    pattern_keyword = re.compile(
        r'(?:<!--\s*wp:heading[^>]*-->\s*)?'
        r'<h2[^>]*>(?:(?!</h2>)[\s\S])*?(?:' + summary_keywords + r')(?:(?!</h2>)[\s\S])*?</h2>'
        r'(?:\s*<!--\s*/wp:heading\s*-->)?'
        r'[\s\S]*?'
        r'(?=<h2|<!--\s*wp:heading|<h3[^>]*>\s*(?:<!--\s*wp:[^>]*-->\s*)?(?:第\s*)?[\d０-９]+\s*位|$)',
        re.IGNORECASE
    )
    cleaned = pattern_keyword.sub('', text)
    cleaned = pattern_keyword.sub('', cleaned)  # 複数の早見表対策
    return cleaned


# 記事種別ごとの広告マーカー挿入ルール（既定値）。
# 各ルール: {position, design, count?, repeat?}
# position: 'before_first_h2', 'after_first_h2', 'after_each_h3_rank',
#           'before_matome_h2', 'after_matome_h2', 'after_last_h2', 'top', 'bottom'
# design: 'vertical', 'horizontal', 'ranking'
# count: ランキングデザインの場合の件数 (TOP3 等)
# repeat: 同じ位置に何個マーカーを並べるか (既定1)
DEFAULT_CARD_INSERTION_PATTERNS = {
    'ranking': [
        {'position': 'after_each_h3_rank', 'design': 'vertical'},
        {'position': 'after_last_h2', 'design': 'ranking', 'count': 3},
    ],
    'brand': [
        # 商標記事は1商品深掘り構造。
        # after_first_h2: 冒頭近くでCV機会を最大化。
        # after_last_h2: キーワード依存なし・記事末尾確定位置（最後のH2直後）。
        #   intro H2 が strip されて after_first_h2 が失敗しても、
        #   after_last_h2 で必ず1つ以上挿入される。
        {'position': 'after_first_h2', 'design': 'vertical'},
        {'position': 'after_last_h2', 'design': 'vertical'},
    ],
    'column': [
        {'position': 'before_first_h2', 'design': 'vertical', 'repeat': 3},
        # after_last_h2: キーワードに依存しない確定位置。
        # after_matome_h2 はFAQ等のH2が記事末尾にある場合まとめから離れるため、
        # 無条件に「記事内の最後のH2直後」を使う方が安定。
        {'position': 'after_last_h2', 'design': 'ranking', 'count': 3},
    ],
}

# 広告挿入定義 UI のホワイトリスト
AD_INSERTION_ALLOWED_POSITIONS = (
    'top',
    'before_first_h2',
    'after_first_h2',
    'after_each_h3_rank',
    'before_matome_h2',
    'after_matome_h2',
    'after_last_h2',
    'bottom',
)
AD_INSERTION_ALLOWED_DESIGNS = ('vertical', 'ranking', 'compare')
AD_INSERTION_ALLOWED_TYPES = ('ranking', 'brand', 'column')


def _sanitize_ad_insertion_rules(rules):
    """ユーザー入力された rules リストをサニタイズして返す。"""
    if not isinstance(rules, list):
        return []
    clean = []
    for r in rules:
        if not isinstance(r, dict):
            continue
        position = str(r.get('position') or '').strip()
        design = str(r.get('design') or 'vertical').strip()
        if position not in AD_INSERTION_ALLOWED_POSITIONS:
            continue
        if design not in AD_INSERTION_ALLOWED_DESIGNS:
            design = 'vertical'
        item = {'position': position, 'design': design}
        # count（rankingデザインのみ意味あり、1〜10）
        try:
            count = int(r.get('count'))
            if 1 <= count <= 10:
                item['count'] = count
        except (TypeError, ValueError):
            pass
        # repeat（縦置きデザインで同じ位置に何個並べるか、1〜5）
        try:
            repeat = int(r.get('repeat'))
            if 2 <= repeat <= 5:
                item['repeat'] = repeat
        except (TypeError, ValueError):
            pass
        clean.append(item)
    return clean


def load_ad_insertion_patterns():
    """広告挿入定義をディスクから読み込む。未保存時はデフォルトを返す。

    マイグレーション:
      after_matome_h2 はキーワード依存のため brand/column で不安定だった。
      after_last_h2 と置き換えることで確実にマーカーが挿入される。
      ロード時に自動マイグレーションし、変更があれば上書き保存する。
    """
    raw = load_doc('ad_insertion', None)
    if not isinstance(raw, dict):
        return {k: [dict(r) for r in v] for k, v in DEFAULT_CARD_INSERTION_PATTERNS.items()}
    merged = {}
    for t in AD_INSERTION_ALLOWED_TYPES:
        if t in raw and isinstance(raw[t], list):
            merged[t] = _sanitize_ad_insertion_rules(raw[t])
        else:
            merged[t] = [dict(r) for r in DEFAULT_CARD_INSERTION_PATTERNS.get(t, [])]

    # ── after_matome_h2 → after_last_h2 自動マイグレーション ──────────────
    # brand / column の after_matome_h2 はキーワード検出が失敗すると last H2 に
    # フォールバックするが、そもそも after_last_h2 を直接使う方が確実。
    # ranking は after_matome_h2 の「まとめ狙い」に意図がある場合があるので残す。
    _MIGRATE_TYPES = ('brand', 'column')
    migrated = False
    for t in _MIGRATE_TYPES:
        for rule in merged.get(t, []):
            if rule.get('position') == 'after_matome_h2':
                rule['position'] = 'after_last_h2'
                migrated = True
    if migrated:
        try:
            save_ad_insertion_patterns(merged)
            print('[AD-MIGRATION] after_matome_h2 → after_last_h2 applied and saved', flush=True)
        except Exception as _e:
            print(f'[AD-MIGRATION] save failed: {_e}', flush=True)
    # ──────────────────────────────────────────────────────────────────────

    # brand: after_last_h2 が1つも無い場合はデフォルトルールを追加
    brand_has_last = any(r.get('position') == 'after_last_h2' for r in merged.get('brand', []))
    if not brand_has_last:
        merged['brand'] = merged.get('brand', []) + [{'position': 'after_last_h2', 'design': 'vertical'}]
        try:
            save_ad_insertion_patterns(merged)
            print('[AD-MIGRATION] brand: after_last_h2 added as fallback', flush=True)
        except Exception:
            pass

    return merged


def save_ad_insertion_patterns(patterns):
    clean = {}
    for t in AD_INSERTION_ALLOWED_TYPES:
        clean[t] = _sanitize_ad_insertion_rules((patterns or {}).get(t, []))
    save_doc('ad_insertion', clean)
    return clean


def _build_marker(design='vertical', count=None, brand=False):
    """プラグイン用のマーカー文字列を組み立てる。

    Examples:
      _build_marker('vertical')          → <!--ai-product:vertical-->
      _build_marker('ranking', count=3)  → <!--ai-product:ranking:3-->
      _build_marker('vertical', brand=True) → <!--ai-product:vertical:brand-->

    brand=True: 商標記事モード。プラグインはこのマーカーを見たら
    商品選定を1回だけ行い、記事内の全 :brand マーカーに同一商品を配置する。
    """
    if not design or design == 'default':
        return '<!--ai-product-->'
    if design in ('ranking', 'compare') and count:
        return f'<!--ai-product:{design}:{int(count)}-->'
    if brand:
        return f'<!--ai-product:{design}:brand-->'
    return f'<!--ai-product:{design}-->'


# H2ブロック1個分（Gutenberg wp:heading ラッパー込み）の正規表現
_H2_BLOCK_RE = (
    r'(?:<!--\s*wp:heading[^>]*-->\s*)?'
    r'<h2[^>]*>(?:(?!</h2>)[\s\S])*?</h2>'
    r'(?:\s*<!--\s*/wp:heading\s*-->)?'
)


def _find_matome_h2_range(html):
    """「まとめ」H2のブロック範囲 (start, end)。無ければ「最後のH2」を返す。

    ⚠️ NON-NEGOTIABLE（広告挿入定義の信頼性）:
      after_matome_h2 / before_matome_h2 のマーカーは「まとめH2」を基準に置く。
      旧実装はキーワード一致のみで、まとめが「総括」「ベストバイ」等のSEO見出し
      （生成プロンプト自体が推奨）になるとマッチせず、マーカーが0個＝定義が
      まるごと無効化されていた（「定義が効かない」の主因）。
      キーワードで見つからない場合は、記事構造上まとめにあたる "最後のH2" へ
      確実にフォールバックする。
    """
    kw = re.compile(
        r'(?:<!--\s*wp:heading[^>]*-->\s*)?'
        r'<h2[^>]*>(?:(?!</h2>)[\s\S])*?'
        r'(?:まとめ|総まとめ|結論|要点|おわりに|最後に|総括|ベストバイ)'
        r'(?:(?!</h2>)[\s\S])*?</h2>'
        r'(?:\s*<!--\s*/wp:heading\s*-->)?',
        re.IGNORECASE
    )
    m = kw.search(html)
    if m:
        return m.start(), m.end()
    # キーワード不一致 → 最後のH2をまとめとみなす（確実に解決させる）
    h2s = list(re.finditer(_H2_BLOCK_RE, html, re.IGNORECASE))
    if h2s:
        return h2s[-1].start(), h2s[-1].end()
    return None


def _find_first_h2_range(html):
    """記事の最初のH2のブロック範囲 (start, end) を返す。無ければ None。

    ⚠️ Gutenberg の <!--wp:heading--> ラッパーを範囲に含める（理由は
    _find_matome_h2_range のコメント参照）。
    """
    m = re.search(
        r'(?:<!--\s*wp:heading[^>]*-->\s*)?'
        r'<h2[^>]*>(?:(?!</h2>)[\s\S])*?</h2>'
        r'(?:\s*<!--\s*/wp:heading\s*-->)?',
        html, re.IGNORECASE
    )
    if not m:
        return None
    return m.start(), m.end()


def insert_card_markers(html, article_type='ranking', patterns=None, title=None):
    """記事種別ごとの広告マーカー (<!--ai-product:...-->) を本文に挿入する。

    本文には直接カードHTMLを書かず、後工程のプラグインが解釈する。
    patterns 未指定なら DEFAULT_CARD_INSERTION_PATTERNS から取得。

    Returns: (new_html, stats_dict)
    stats: {marker_count, rules_applied, positions}
    """
    stats = {'marker_count': 0, 'rules_applied': 0, 'positions': []}
    if not html:
        return html, stats
    # patterns 未指定なら 永続化された広告挿入定義 をロードする
    # （UI から編集された設定があればそれを優先、無ければDEFAULT）
    if patterns is None:
        try:
            patterns = load_ad_insertion_patterns()
        except Exception:
            patterns = DEFAULT_CARD_INSERTION_PATTERNS
    rules = patterns.get(article_type) or []
    if not rules:
        return html, stats

    text = str(html)

    # まずは前処理: 早見表削除と先頭introH2削除
    text = strip_leading_introduction_h2(text, title=title)
    text = strip_summary_table_sections(text)

    matome_range = _find_matome_h2_range(text)
    first_h2_range = _find_first_h2_range(text)

    # 各位置への挿入を後ろから処理（インデックス保持のため）
    insertions = []  # [(insert_pos, marker_text)]

    # 商標記事は1商品深掘り構造なので、全マーカーに :brand サフィックスを付ける。
    # プラグイン側でこの印を見たら商品選定を1回だけ実施し全マーカーに同一商品を配置する。
    is_brand = (article_type == 'brand')

    for rule in rules:
        pos = rule.get('position')
        design = rule.get('design', 'vertical')
        count = rule.get('count')
        repeat = max(1, int(rule.get('repeat', 1)))
        marker = _build_marker(design, count, brand=is_brand)
        marker_block = ('\n' + marker) * repeat

        if pos == 'top':
            insertions.append((0, marker_block + '\n'))
            stats['rules_applied'] += 1
            stats['marker_count'] += repeat
            stats['positions'].append(pos)
        elif pos == 'bottom':
            insertions.append((len(text), '\n' + marker_block))
            stats['rules_applied'] += 1
            stats['marker_count'] += repeat
            stats['positions'].append(pos)
        elif pos == 'before_first_h2' and first_h2_range:
            insertions.append((first_h2_range[0], marker_block + '\n'))
            stats['rules_applied'] += 1
            stats['marker_count'] += repeat
            stats['positions'].append(pos)
        elif pos == 'after_first_h2' and first_h2_range:
            insertions.append((first_h2_range[1], '\n' + marker_block))
            stats['rules_applied'] += 1
            stats['marker_count'] += repeat
            stats['positions'].append(pos)
        elif pos == 'before_matome_h2' and matome_range:
            insertions.append((matome_range[0], marker_block + '\n'))
            stats['rules_applied'] += 1
            stats['marker_count'] += repeat
            stats['positions'].append(pos)
        elif pos == 'after_matome_h2' and matome_range:
            insertions.append((matome_range[1], '\n' + marker_block))
            stats['rules_applied'] += 1
            stats['marker_count'] += repeat
            stats['positions'].append(pos)
        elif pos == 'after_last_h2':
            # キーワードに依存せず記事内の最後のH2直後に挿入。
            # after_matome_h2 はFAQ等のH2が末尾にある場合まとめから離れるため、
            # 安定性重視の場合はこちらを推奨。
            h2s_all = list(re.finditer(_H2_BLOCK_RE, text, re.IGNORECASE))
            if h2s_all:
                last_h2 = h2s_all[-1]
                insertions.append((last_h2.end(), '\n' + marker_block))
                stats['rules_applied'] += 1
                stats['marker_count'] += repeat
                stats['positions'].append(pos)
        elif pos == 'after_each_h3_rank':
            # h3ランキング見出し直下に1個ずつ
            # 「第1位」「1位」「No.1」「①」など各種フォーマットに対応
            h3_patterns = [
                # 第N位 / N位 / 第N位:
                r'<h3[^>]*>\s*(?:第\s*)?(?:\d+|[０-９]+)\s*位[\s:：、・　]*[^<]*?</h3>',
                # No.N / No N
                r'<h3[^>]*>\s*No\.?\s*(?:\d+|[０-９]+)[\s:：、・　]*[^<]*?</h3>',
                # ①②③… (丸数字)
                r'<h3[^>]*>\s*[①②③④⑤⑥⑦⑧⑨⑩][\s:：、・　]*[^<]*?</h3>',
            ]
            matched_positions = set()
            for pat in h3_patterns:
                rx = re.compile(pat, re.IGNORECASE)
                for m in rx.finditer(text):
                    if m.start() in matched_positions:
                        continue
                    matched_positions.add(m.start())
                    insertions.append((m.end(), '\n' + marker))
                    stats['marker_count'] += 1
            # 上記でゼロ件なら、まとめH2より前の全H3 をランキングH3とみなしてフォールバック
            if not matched_positions:
                # 早見表削除済み・先頭intro削除済みなので、最初のH2より後・まとめH2より前の H3 を対象
                end_limit = matome_range[0] if matome_range else len(text)
                start_limit = first_h2_range[1] if first_h2_range else 0
                fallback_rx = re.compile(r'<h3[^>]*>[^<]*?</h3>', re.IGNORECASE)
                for m in fallback_rx.finditer(text):
                    if m.start() < start_limit or m.start() >= end_limit:
                        continue
                    insertions.append((m.end(), '\n' + marker))
                    stats['marker_count'] += 1
                    matched_positions.add(m.start())
                if matched_positions:
                    print(f'[MARKER] after_each_h3_rank: fallback to all-H3-in-body used ({len(matched_positions)} markers)', flush=True)
            stats['rules_applied'] += 1
            stats['positions'].append(pos)

    # 後ろから挿入してインデックスずれを防ぐ
    insertions.sort(key=lambda x: x[0], reverse=True)
    for pos, marker_text in insertions:
        text = text[:pos] + marker_text + text[pos:]

    # ── 絶対フォールバック ─────────────────────────────────────────────────
    # 全ルール適用後にマーカーが1つも挿入できていない場合（H2なし等の構造的理由）、
    # 記事末尾に必ず1つ挿入する。WPプラグインの「マーカーが見つかりません」エラーを
    # 根絶するための最終安全網。
    if stats['marker_count'] == 0 and rules:
        fallback_marker = _build_marker('vertical', brand=(article_type == 'brand'))
        text = text + '\n' + fallback_marker
        stats['marker_count'] += 1
        stats['rules_applied'] += 1
        stats['positions'].append('bottom_fallback')
        print(f'[MARKER] fallback: article_type={article_type}, all rules failed → inserted at bottom', flush=True)
    # ──────────────────────────────────────────────────────────────────────

    print(f'[MARKER] inserted: article_type={article_type}, stats={stats}', flush=True)
    return text, stats


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


def should_use_segmented_generation(article_type, quality=None, article=None):
    normalized = normalize_article_type(article_type, 'ranking')
    target = effective_target_chars(quality)
    if normalized == 'ranking':
        count = extract_ranking_count(article) if article else 0
        # 件数が多い（7以上）か、目標文字数が多い場合は分割。
        # 10選で各商品の解説をしっかり入れると単発生成では途中切れしやすい。
        return target >= 6000 or (count or 0) >= 7
    return normalized in ('brand', 'column') and target >= 7000


def build_segmented_article_steps(article, article_type):
    normalized = normalize_article_type(article_type, 'ranking')
    if normalized == 'ranking':
        count = extract_ranking_count(article) or 5
        steps = [{
            'name': '導入・選定基準',
            'prompt': f"""リード文、この記事でわかること、選定基準だけを書いてください。
- **リード文は H2 の前**に、<p>段落だけ</p>で書く。リード文を H2 で囲まない。
  記事タイトル（H1）は WordPress が表示するので、本文の最初は <p> から始める。
  「○○で猛暑を乗り切る｜選び方と比較ポイント」のような導入用H2は作らない。
- リード文は読者の悩みと記事の結論を簡潔に。自然に2〜3段落で十分。
- **早見表・比較表は絶対に本文に書かない**（後処理でランキングカードに置換されます）。具体的に禁止するH2例:
  「結論：◯◯おすすめ早見表」「おすすめ早見表」「◯選 早見表」「比較表」「◯◯比較」等。
- 順位・商品名・特徴・価格などをまとめる表は一切作らない。
- 冗長な前置きや同じ内容の繰り返しは避け、必要な情報を密度高くまとめる。

理想的な構造:
  <p>リード文1段落目</p>
  <p>リード文2段落目（記事の結論を含む）</p>
  <h2 class="wp-block-heading">この記事でわかること</h2>
  <ul>...</ul>
  <h2 class="wp-block-heading">{count}選を選ぶ際の選定基準</h2>
  <ul>...</ul>"""
        }]
        chunk_size = 2
        for start in range(1, count + 1, chunk_size):
            end = min(count, start + chunk_size - 1)
            num_products = end - start + 1
            steps.append({
                'name': f'ランキング個別解説 {start}〜{end}位',
                'prompt': f"""ランキング本文のうち、{start}位から{end}位までの個別解説だけを書いてください。
- 必ず <h3 class="wp-block-heading">{start}位：商品名</h3> から順に書いてください。
  商品名は商品リストの正確な名前を使う（または短縮した代表名）。
- {start}〜{end}位の順位番号を欠番・重複なしで入れてください。
- 商品カード（画像・価格・ボタン）は **Affiros9 側で h3 の直後に自動挿入** するので、
  本文側で AFFI マーカーや商品カードHTMLを書く必要はない。
- 比較表やリード文は繰り返さないでください。
- {end}位を書き終えたら、選び方やFAQへ進まず止めてください。

【各H3商品セクションの構造（必須）】
順位ごとに以下の構造で書く:
1. 商品の特徴説明: <p> 2〜3段落で具体的に
2. 注意点・デメリット: <p><span style="color:#d32f2f"><strong>注意点：</strong>...</span></p> として明示
3. 最後に <ul> で 3〜4行の箇条書き。**各行は必ず異なるラベル**を使う

★絶対ルール: <ul>内で同じラベル（例「向いている人」）を複数行で繰り返さない★

【良いリスト例】
<ul>
  <li><strong>向いている人</strong>：寒冷地在住、登山やスキーをする方、保温性最優先の方</li>
  <li><strong>向いていない人</strong>：温暖地域、軽い防寒で十分な方</li>
  <li><strong>価格帯</strong>：2,000円台前半</li>
  <li><strong>サイズ・フィット感</strong>：フリーサイズ、首周り40cm前後まで対応</li>
</ul>

【悪い例（禁止）】
<ul>
  <li><strong>向いている人</strong>：寒冷地在住</li>
  <li><strong>向いている人</strong>：登山やスキーをする方</li>
  <li><strong>向いている人</strong>：保温性最優先の方</li>
</ul>
→ 同じラベルを繰り返さない。複数条件はカンマで1行にまとめる。

文字数のために水増ししない。冗長な前置き・同じ内容の繰り返しは避ける。
読者が「どんな商品か・誰向けか・注意点は何か」をすぐ理解できる密度を優先。"""
            })
        steps.append({
            'name': '選び方・FAQ',
            'prompt': """選び方とFAQを書いてください。まとめはまだ書かない（次のステップで書く）。

【H2 選び方セクション】
- H2は「（テーマ）の選び方｜...」のように具体的なサブタイトルを含める
- 中身は H3で3〜5項目に分けて、それぞれ簡潔に説明
- 素材・価格・用途・サイズ感など判断軸を整理

【H2 よくある質問セクション（必須）】
- H2「（テーマ）のよくある質問」を必ず作る
- 質問ごとにH3見出しを使い、3〜5問入れる
- 各回答は2〜4文程度で簡潔に。だらだら書かない
- **FAQセクションは絶対に省略しない**。リード文で「FAQをまとめた」と書いた以上、必ず存在させる

【共通ルール】
- ランキング個別解説は繰り返さない
- まとめH2はまだ作らない（次のステップで書く）
- **段落の分け方が最重要**: 1つの<p>は必ず2〜3文以内で改行する。
  長い説明（4文以上）は必ず複数の<p>に分割する。読者が画面でスクロールしやすい密度に。
- 例（良い）: <p>○○です。△△が特徴です。</p><p>一方で□□には注意。</p>
- 例（悪い）: <p>○○です。△△が特徴で、一方□□には注意で、さらに××もあって...</p>"""
        })
        steps.append({
            'name': 'まとめ',
            'prompt': """記事のまとめだけを書いて完結させてください。

- H2「まとめ｜...」のように具体的なサブタイトルを1つだけ作る
- 読者の用途別おすすめを <ul><li>...</li></ul> で簡潔に整理（例:「○○重視なら△△」）
- 最後の段落で読者の次の行動を促す自然な締めくくり
- 比較表・FAQ・ランキング解説は繰り返さない
- 段落の分け方が最重要: 1つの<p>は必ず2〜3文以内で改行する"""
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
            'name': 'FAQ',
            'prompt': """よくある質問セクションだけを書いてください。まとめはまだ書かない（次のステップ）。

- H2「（テーマ）のよくある質問」を必ず作る
- 質問ごとにH3見出しを使い、3〜5問入れる
- 各回答は2〜4文程度で簡潔に
- **FAQセクションは絶対に省略しない**
- 各段落（<p>）は2〜3文以内で改行する"""
        },
        {
            'name': 'まとめ',
            'prompt': """記事のまとめだけを書いて完結させてください。

- H2「まとめ｜...」のように具体的なサブタイトルを1つだけ作る
- 読者の次の行動を明確にする
- FAQや解決策セクションは繰り返さない
- 各段落（<p>）は2〜3文以内で改行する"""
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
- 文字数の目安: 記事全体で約{effective_target_chars(quality)}文字、今回の工程で約{section_target}文字。
  ただしこれは **ガイドライン** であって厳格な制約ではありません。
  情報を水増しせず、必要十分な内容で自然に書く方を優先してください。
  冗長な繰り返しや同じ内容の言い換えは避ける。自然に書いた結果として目安より短くなるのは構わない。
- 今回の範囲を書き切るまで途中で止めないでください。
- Gutenbergコメント（<!-- wp:... -->）は出力しないでください。
- h2/h3見出しには、できるだけ狙う主要KW「{main_keyword}」を自然に含めてください。
- <p>は長くしすぎず、2〜3文ごとに分けてください。長い説明は段落を増やしてください。
- 重要な結論・注意点・選び方の要点には、太字、赤字、マーカー、リスト、表だけを自然に使ってください。
- 商品カード/RINKER風HTMLは自分で作らない。Affiros9 側で各 <h3>N位：商品名</h3> の直後に楽天/Amazonの商品カードを自動挿入するので、本文側ではAFFIマーカーやカードHTMLは不要。

現在までの本文（重複禁止・文脈確認用）:
{previous_tail}

今回書く範囲: {step.get('name')}
{step.get('prompt')}
"""


def segment_target_chars(quality, total):
    target = effective_target_chars(quality)
    per_segment = math.ceil(target / max(total, 1))
    # target に応じて min/max を動的に。
    # target が小さい時に min 900 で押し上げて全体文字数を超過する問題を抑える。
    floor = 500 if target <= 3500 else (700 if target <= 6000 else 900)
    ceiling = 900 if target <= 3500 else (1200 if target <= 6000 else 1500)
    return max(floor, min(ceiling, per_segment))


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
                model=get_article_model(),
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


def _looks_truncated(content):
    """生成本文が途中で切れているか簡易判定。"""
    text = html_to_text(content or '').rstrip()
    if not text:
        return True
    # 末尾が文末記号 / 閉じ括弧 で終わっていなければ途中切れの可能性
    last = text[-1]
    if last in '。．！？!?）)」』】〕》>…':
        return False
    # 「。」が直近30文字以内にあれば許容（多少のはみ出しは許す）
    tail = text[-30:]
    return not any(c in tail for c in '。．！？!?')


def validate_generated_article(article, article_type, content, quality=None):
    content_chars = len(html_to_text(content))
    min_chars = minimum_required_content_chars(quality)
    if content_chars < min_chars:
        return f'生成本文が短すぎます（{content_chars}文字）。最低{min_chars}文字以上必要です。途中で止まっている可能性が高いため保存しません。'

    # 途中切れ検出: まとめセクションが無い or 文末が途中
    has_matome = bool(re.search(r'<h2[^>]*>(?:(?!</h2>)[\s\S])*?(?:まとめ|総まとめ|結論として)(?:(?!</h2>)[\s\S])*?</h2>', content, flags=re.I))
    if not has_matome:
        return '記事に「まとめ」セクションが見当たりません。途中で切れている可能性が高いため、続きを生成して記事を完結させてください。'
    if _looks_truncated(content):
        return '本文末尾が文の途中で終わっています。途中で切れている可能性が高いため、続きを生成して記事を完結させてください。'

    if normalize_article_type(article_type, 'ranking') != 'ranking':
        return ''
    expected = extract_ranking_count(article) or 5  # ranking は最低5選
    ranked_count = count_ranked_items_from_text(content)
    if ranked_count < expected:
        return f'ランキング記事は{expected}件の個別解説が必要ですが、{ranked_count}件しか検出できませんでした。もう一度生成してください。'
    # 比較表は plugin の compare デザインが代替するため、本文中の <table> 有無は検証しない
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
                headers=WP_REQUEST_HEADERS,
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
                headers=WP_REQUEST_HEADERS,
                timeout=15
            )
            created.raise_for_status()
            ids.append(created.json()['id'])
        except Exception:
            continue
    return ids


def save_settings(settings):
    save_doc('settings', settings)

def login_required(f):
    """認証不要（シングルユーザー運用）。デコレータは互換のため残す。"""
    @wraps(f)
    def decorated(*args, **kwargs):
        return f(*args, **kwargs)
    return decorated


@app.route('/')
@app.route('/ranking')
@app.route('/brand')
@app.route('/column')
@app.route('/import')
@app.route('/batch')
@app.route('/history')
@app.route('/articles')
@app.route('/quality')
@app.route('/title-definition')
@app.route('/ad-insertion')
@app.route('/ads')
@app.route('/sites')
@app.route('/api-settings')
@app.route('/settings')
@app.route('/plugins')
def index():
    # プラグインバージョン表記を index.html 側でハードコードしない（同期忘れ防止）。
    # PLUGIN_DOWNLOADS が唯一の Source of Truth。
    return render_template('index.html', plugin_downloads=PLUGIN_DOWNLOADS)

@app.route('/favicon.ico')
def favicon():
    return send_from_directory(app.static_folder, 'favicon.svg', mimetype='image/svg+xml')

# 配布プラグイン（WordPress 連携プラグインの zip）
PLUGIN_DIR = os.path.join(os.path.dirname(os.path.abspath(__file__)), 'plugin-downloads')
PLUGIN_DOWNLOADS = {
    'product-inserter': {
        'file': 'affiros-product-inserter-1.9.6.zip',
        'name': 'Affiros プロダクトインサーター',
        'version': '1.9.6',
    },
    'decoration': {
        'file': 'affiros-decoration-1.2.1.zip',
        'name': 'Affiros デコレーター',
        'version': '1.2.1',
    },
    'rewrite': {
        'file': 'affiros-rewrite-0.4.11.zip',
        'name': 'Affiros リライター',
        'version': '0.4.11',
    },
    'categorizer': {
        'file': 'affiros-categorizer-0.1.0.zip',
        'name': 'Affiros カテゴライザー',
        'version': '0.1.0',
    },
}

@app.route('/download/plugin/<plugin_key>')
@login_required
def download_plugin(plugin_key):
    """WordPress 連携プラグインの zip をダウンロードさせる。"""
    info = PLUGIN_DOWNLOADS.get(plugin_key)
    if not info:
        return jsonify({'error': 'unknown plugin'}), 404
    target = os.path.join(PLUGIN_DIR, info['file'])
    if not os.path.exists(target):
        return jsonify({'error': 'plugin file not found', 'file': info['file']}), 404
    return send_from_directory(
        PLUGIN_DIR, info['file'],
        as_attachment=True, download_name=info['file'],
        mimetype='application/zip',
    )


# WordPress プラグイン自動更新用のメタ情報。
# WP の plugins_api / pre_set_site_transient_update_plugins フィルタ経由で
# 各プラグインがここを叩き、新バージョンを検知して自動更新する。
PLUGIN_UPDATE_META = {
    'rewrite': {
        'plugin_basename': 'affiros-rewrite/affiros-rewrite.php',
        'tested':   '6.6',
        'requires': '5.8',
        'requires_php': '7.4',
        'author':   'Affiros',
    },
    'product-inserter': {
        'plugin_basename': 'affiros-product-inserter/affiros-product-inserter.php',
        'tested':   '6.6',
        'requires': '5.8',
        'requires_php': '7.4',
        'author':   'Affiros',
    },
}


@app.route('/api/plugin-update/<plugin_key>')
def plugin_update_info(plugin_key):
    """WordPress 自動更新用のメタ情報を JSON で返す。

    各プラグインに同梱した Affiros_Plugin_Updater がこの URL を 6h ごとに
    叩いて、Version ヘッダーと比較する。download_url から zip を取得して
    WP 標準のプラグイン更新フローで自動インストールする。
    """
    info = PLUGIN_DOWNLOADS.get(plugin_key)
    meta = PLUGIN_UPDATE_META.get(plugin_key)
    if not info or not meta:
        return jsonify({'error': 'unknown plugin'}), 404
    base = request.host_url.rstrip('/')
    return jsonify({
        'name':         info['name'],
        'slug':         meta['plugin_basename'].split('/')[0],
        'plugin':       meta['plugin_basename'],
        'version':      info['version'],
        'tested':       meta['tested'],
        'requires':     meta['requires'],
        'requires_php': meta['requires_php'],
        'author':       meta['author'],
        'download_url': f"{base}/download/plugin/{plugin_key}",
        'sections': {
            'description': f"{info['name']} 本体。Affiros9 サーバーから自動更新します。",
            'changelog':   f"最新バージョン {info['version']}",
        },
    })

@app.route('/login', methods=['GET', 'POST'])
def login_page():
    return redirect(url_for('index'))

@app.route('/logout')
def logout():
    return redirect(url_for('index'))


# Title ideas
@app.route('/api/title-ideas/generate', methods=['POST'])
@login_required
def generate_title_ideas():
    """
    タイトル案生成をバックグラウンドジョブとして起動する。
    SSE接続に縛られないため、ページ離脱・30秒タイムアウトの影響を受けない。
    フロントは job_id でポーリングする。
    """
    try:
        data = request.get_json(silent=True) or {}
    except Exception:
        data = {}
    keywords = split_title_keywords(data.get('keywords', ''))
    count_per_keyword = clamp_int(data.get('count_per_keyword'), 3, 1, 5)
    category = str(data.get('category') or '').strip()
    categories = [c for c in (data.get('categories') or []) if isinstance(c, dict) and str(c.get('name') or '').strip()]
    site_id = data.get('site_id') or ''
    _atf = str(data.get('article_type_filter') or '').strip().lower()
    article_type_filter = _atf if _atf in ('ranking', 'column') else None

    if not keywords:
        return jsonify({'error': 'キーワードを1行以上入力してください'}), 400
    if len(keywords) > TITLE_IDEA_MAX_KEYWORDS:
        return jsonify({
            'error': f'キーワードは1回に最大 {TITLE_IDEA_MAX_KEYWORDS} 件まで（現在 {len(keywords)} 件）。'
                     f'それ以上は分割して実行してください。'
        }), 400

    try:
        settings = load_settings()
    except Exception as e:
        app.logger.warning('Title idea settings load failed: %s', e)
        settings = {}

    claude_key = settings.get('claude_api_key')
    if not claude_key:
        return jsonify({'error': 'タイトル案生成にはClaude APIキーが必要です。'}), 400

    batches = [keywords[i:i + TITLE_IDEA_BATCH_SIZE] for i in range(0, len(keywords), TITLE_IDEA_BATCH_SIZE)]
    expected_count = len(keywords) * count_per_keyword

    now = now_iso()
    job_id = str(uuid.uuid4())
    job = {
        'id': job_id,
        'type': 'title-ideas',
        'status': 'running',
        'keywords': keywords,
        'count_per_keyword': count_per_keyword,
        'category': category,
        'categories': categories,
        'site_id': site_id,
        'total_batches': len(batches),
        'completed_batches': 0,
        'expected_count': expected_count,
        'ideas': [],
        'message': f'Claudeでタイトル案を生成中... ({len(keywords)}KW / {len(batches)}バッチ)',
        'article_type_filter': article_type_filter or '',
        'started_at': now,
        'updated_at': now,
    }
    with _DATA_LOCK:
        jobs = load_title_idea_jobs()
        jobs.insert(0, job)
        save_title_idea_jobs(jobs)

    def worker():
        try:
            # ワーカー起動時に articles.json を1回だけロードしてキーセット化。
            # 以降の enrich_title_ideas はこのキーセットを使い回し、I/O を激減させる。
            try:
                articles_for_dup = load_articles()
                if not isinstance(articles_for_dup, list):
                    articles_for_dup = []
            except Exception as e:
                app.logger.warning('Worker: load_articles for dup check failed: %s', e)
                articles_for_dup = []
            existing_title_keys = {
                normalize_title_key(a.get('title'))
                for a in articles_for_dup
                if isinstance(a, dict)
            }

            all_ideas = []
            batch_errors = []
            model_used = CLAUDE_TITLE_IDEA_MODEL
            last_error = None
            completed = 0
            # 部分結果の enrich は重い（250KW想定で毎バッチ走らせると Render 単ワーカーが詰まる）。
            # 5バッチごと or 最後 にのみ enrich + ジョブ保存する。
            partial_enrich_interval = max(1, min(5, max(1, len(batches) // 10)))
            max_workers = min(TITLE_IDEA_PARALLEL_BATCHES, len(batches))
            with ThreadPoolExecutor(max_workers=max_workers) as executor:
                future_to_idx = {
                    executor.submit(generate_claude_title_ideas_once, claude_key, batch, count_per_keyword, category, article_type_filter, categories): (idx, batch)
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

                    # 進捗 + 部分結果をジョブに保存
                    msg = f'Claudeでタイトル案を生成中 ({completed}/{len(batches)}バッチ完了 / 取得済 {len(all_ideas)}件)'
                    partial_payload = {
                        'completed_batches': completed,
                        'message': msg,
                    }
                    # enrich は節約モード: 一定間隔 or 最終バッチ完了時のみ
                    should_enrich = (
                        completed == len(batches)
                        or (completed % partial_enrich_interval == 0)
                    )
                    if all_ideas and should_enrich:
                        try:
                            partial_payload['ideas'] = enrich_title_ideas(
                                list(all_ideas),
                                category=category,
                                site_id=site_id,
                                existing_title_keys=existing_title_keys,
                            )
                        except Exception as e:
                            app.logger.warning('Partial enrich failed: %s', e)
                    update_title_idea_job(job_id, **partial_payload)

            if not all_ideas:
                error_text = ' / '.join(batch_errors) if batch_errors else (compact_ai_error(last_error) if last_error else 'タイトル案を取得できませんでした')
                update_title_idea_job(
                    job_id,
                    status='error',
                    error=error_text,
                    provider_errors=batch_errors[-5:] if batch_errors else [],
                    message=f'タイトル案生成に失敗しました: {error_text}',
                    completed_at=now_iso(),
                )
                return

            enriched = enrich_title_ideas(
                all_ideas,
                category=category,
                site_id=site_id,
                existing_title_keys=existing_title_keys,
            )
            warnings = []
            if batch_errors:
                warnings.append(f'{len(batch_errors)}/{len(batches)}バッチが失敗しました。')
            if len(enriched) < expected_count:
                warnings.append(f'AI返却が{len(enriched)}/{expected_count}件でした。足りない分はテンプレ補完していません。')
            update_title_idea_job(
                job_id,
                status='completed',
                ideas=enriched,
                model=model_used,
                source='claude',
                ai_used=True,
                warning=' '.join(warnings) if warnings else '',
                provider_warnings=batch_errors[-5:] if batch_errors else [],
                completed_batches=len(batches),
                message=f'タイトル案 {len(enriched)}件 生成完了',
                completed_at=now_iso(),
            )
        except Exception as e:
            app.logger.error('Title idea worker hard-failed: %s\n%s', e, traceback.format_exc())
            update_title_idea_job(
                job_id,
                status='error',
                error=compact_ai_error(e),
                message=f'タイトル案生成で例外: {compact_ai_error(e)}',
                completed_at=now_iso(),
            )

    threading.Thread(target=worker, daemon=True).start()
    return jsonify({'success': True, 'job_id': job_id, 'message': job['message']})


@app.route('/api/title-ideas/jobs/<job_id>', methods=['GET'])
@login_required
def get_title_idea_job(job_id):
    job = next((j for j in load_title_idea_jobs() if j.get('id') == job_id), None)
    if not job:
        return jsonify({'error': 'ジョブが見つかりません'}), 404
    return jsonify(job)


@app.route('/api/title-ideas/jobs/latest', methods=['GET'])
@login_required
def get_latest_title_idea_job():
    jobs = load_title_idea_jobs()
    if not jobs:
        return jsonify({'job': None})
    # まず running があればそれを、なければ最新
    running = next((j for j in jobs if j.get('status') == 'running'), None)
    return jsonify({'job': running or jobs[0]})


@app.route('/api/title-ideas/save', methods=['POST'])
@login_required
@with_data_lock
def save_title_ideas():
    data = request.get_json(silent=True) or {}
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
        keyword = str(idea.get('keyword') or '').strip()
        # keywords は SEOターゲットKW（カンマ区切り）。記事生成・Amazon/楽天検索ヒントに使われる。
        keywords_csv = str(idea.get('keywords') or '').strip() or keyword
        article_type = coerce_title_article_type(idea.get('article_type'), keyword, title)
        now = now_iso()
        memo_parts = ['タイトル案から作成']
        if idea.get('search_intent'):
            memo_parts.append(f"検索意図: {idea.get('search_intent')}")
        if idea.get('reason'):
            memo_parts.append(f"理由: {idea.get('reason')}")
        article = {
            'id': str(uuid.uuid4()),
            'title': title,
            'keywords': keywords_csv,
            'category': str(idea.get('category') or default_category),
            'slug': normalize_slug(idea.get('slug')),
            'article_type': article_type,
            'ad_keywords': infer_ad_keywords_from_title(title, keywords_csv, article_type),
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
    with _DATA_LOCK:
        articles = load_articles()
        changed = recover_stale_article_statuses(articles, load_batch_jobs())
        if ensure_article_scores_current(articles):
            changed = True
        if changed:
            save_articles(articles)
    # 現在サイトでフィルタ（?site_id=xxx）。指定なしまたは 'all' で全件返す（後方互換）
    site_id = request.args.get('site_id', '').strip()
    if site_id and site_id != 'all':
        articles = [a for a in articles if str(a.get('site_id') or '') == site_id]
    return jsonify(articles)


@app.route('/api/current-site', methods=['GET'])
@login_required
def get_current_site():
    """現在選択中のサイトIDを返す。"""
    return jsonify({'site_id': session.get('current_site_id') or ''})


@app.route('/api/current-site', methods=['POST'])
@login_required
def set_current_site():
    """現在サイトを設定（'' or 'all' でクリア = ダッシュボード表示）"""
    data = request.get_json(silent=True) or {}
    site_id = str(data.get('site_id') or '').strip()
    if site_id and site_id != 'all':
        settings = load_settings()
        sites = settings.get('sites', [])
        if not any(s.get('id') == site_id for s in sites):
            return jsonify({'success': False, 'error': '不正なサイトIDです'}), 400
        session['current_site_id'] = site_id
    else:
        session.pop('current_site_id', None)
    return jsonify({'success': True, 'site_id': session.get('current_site_id') or ''})


def _start_batch_worker(job_id, api_key, quality_id, batch_article_type, pending_articles):
    """バッチ生成ワーカーのモジュールレベルエントリポイント。

    呼び出し元:
    - batch_generate(): 新規ジョブ起動時
    - resume_orphan_batches_on_startup(): Render再デプロイ後のレジューム時

    前提:
    - batch_jobs.json にジョブが既に存在し quality_id / batch_article_type / article_ids が
      保存されていること
    - pending_articles の各記事は既に status='queued' でディスクに保存されていること
    """
    settings = load_settings()
    quality_list = load_quality()
    quality_cache = {}

    def resolve_quality_for(art_type):
        if art_type not in quality_cache:
            q = select_quality_definition(quality_list, quality_id, art_type)
            quality_cache[art_type] = (q, build_quality_prompt(q))
        return quality_cache[art_type]

    style_reference_cache = {}

    def update_job(**changes):
        with _DATA_LOCK:
            jobs = load_batch_jobs()
            for item in jobs:
                if item.get('id') == job_id:
                    item.update(changes)
                    item['updated_at'] = now_iso()
                    break
            save_batch_jobs(jobs)

    def is_cancel_requested():
        for j in load_batch_jobs():
            if j.get('id') == job_id:
                return bool(j.get('cancel_requested'))
        return False

    def run_batch():
        # ジョブから現在のカウンタを読む（レジューム時に途中から継続するため）
        _jobs = load_batch_jobs()
        _job = next((j for j in _jobs if j.get('id') == job_id), None)
        completed = int((_job or {}).get('completed') or 0)
        failed = int((_job or {}).get('failed') or 0)
        retried = int((_job or {}).get('retried') or 0)
        total_for_msg = int((_job or {}).get('total') or len(pending_articles))

        client = anthropic.Anthropic(api_key=api_key) if api_key else None
        attempt_counts = {}
        overload_counts = {}  # 記事ごとの「Claude API過負荷(529)」リトライ回数（通常予算とは別枠）
        queue_articles = list(pending_articles)
        while queue_articles:
            if is_cancel_requested():
                remaining_ids = {a['id'] for a in queue_articles}
                with _DATA_LOCK:
                    current_articles = load_articles()
                    for a in current_articles:
                        # キュー残の記事は 'queued' だけでなく、リトライ待ちで
                        # 'generating' になっているものも pending に戻す。
                        # （これを 'queued' だけにすると、リトライ待ち記事が
                        #   generating のまま残り、UIで開始ボタンが無効化され続ける）
                        if a['id'] in remaining_ids and a.get('status') in ('queued', 'generating'):
                            a['status'] = 'pending'
                            a.pop('batch_job_id', None)
                            a['updated_at'] = now_iso()
                    save_articles(current_articles)
                update_job(
                    status='cancelled',
                    current_title='',
                    completed=completed,
                    failed=failed,
                    retried=retried,
                    completed_at=now_iso(),
                    message=f"ユーザーがキャンセル: 成功 {completed}件 / エラー {failed}件 / 残り {len(queue_articles)}件は pending に戻しました"
                )
                return
            article = queue_articles.pop(0)
            article_id = article.get('id')
            attempt_counts[article_id] = attempt_counts.get(article_id, 0) + 1
            attempt_no = attempt_counts[article_id]
            stage = 'starting'
            try:
                stage = 'prepare article'
                retry_suffix = f"（リトライ{attempt_no - 1}/{BATCH_GENERATION_MAX_RETRIES}）" if attempt_no > 1 else ''
                update_job(current_title=article.get('title', ''), message=f"生成中{retry_suffix}: {article.get('title', '')}")
                with _DATA_LOCK:
                    current_articles = load_articles()
                    for a in current_articles:
                        if a['id'] == article['id']:
                            a['status'] = 'generating'
                            a['updated_at'] = now_iso()
                            break
                    save_articles(current_articles)
                article_type = normalize_article_type(article.get('article_type') or batch_article_type, batch_article_type)
                quality, quality_prompt = resolve_quality_for(article_type)
                # 品質定義の「書き方参考URL」を一括生成でも使う。
                # （以前は False ハードコードで、参考URLが一括生成では完全に無視されていた。
                #   単体生成(SSE)では使われるのに不整合だった）。
                # fetch は記事タイプ単位でキャッシュ＋try/exceptされるので低コスト・安全。
                use_generation_extras = True
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
                stage = 'fetch products'
                update_job(current_title=article.get('title', ''), message=f"Amazon/楽天で実商品データ取得中: {article.get('title', '')}")
                products, _ = fetch_product_context(article, settings, limit=15)
                stage = 'build prompt'
                update_job(current_title=article.get('title', ''), message=f"プロンプト構築中: {article.get('title', '')}")
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

                prompt += build_product_context_prompt(products, article_type)

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
                if client and should_use_segmented_generation(article_type, quality, article):
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
                    update_job(current_title=article.get('title', ''), message=f"Claudeで本文生成中: {article.get('title', '')}")
                    message = create_claude_message(client, prompt, max_tokens=claude_max_tokens_for_quality(quality))
                    raw_content = anthropic_message_text(message)
                    usage_parts = [build_article_usage(prompt, raw_content, message)]
                stage = 'inject affiliate markers'
                update_job(current_title=article.get('title', ''), message=f"商品カードマーカー挿入中: {article.get('title', '')}")
                raw_content, marker_stats = insert_card_markers(raw_content, article_type, title=article.get('title') if isinstance(article, dict) else None)
                card_stats = {'h3_count': 0, 'matched_count': 0, 'products_available': 0,
                              'fallback_count': 0, 'marker_count': marker_stats.get('marker_count', 0), 'mode': 'marker_only'}
                if card_stats.get('h3_count') and not card_stats.get('matched_count'):
                    update_job(current_title=article.get('title', ''), message=f"商品カード未挿入（取得商品 {card_stats.get('products_available', 0)}件 / 見出し {card_stats.get('h3_count', 0)}件）: {article.get('title', '')}")
                stage = 'enhance and validate content'
                update_job(current_title=article.get('title', ''), message=f"本文を整形・検証中: {article.get('title', '')}")
                content, enhance_warning = safe_enhance_generated_article_html(raw_content, article, article_type)
                content = strip_summary_table_sections(content)
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
                generated_at = now_iso()
                run_id = str(uuid.uuid4())

                stage = 'save generated article'
                with _DATA_LOCK:
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
                        # ── 品質ゲート (#7) ──────────────────────────────────
                        # SEOスコアを機械採点し、不足点をフィードバックして品質改善
                        # （作り直し）を最大 QUALITY_GATE_MAX_POLISH 回まで試みる。
                        # 各試行を採点し「最もスコアが高い版」を必ず採用する
                        # （品質改善で悪化した版は捨て、初回生成を下回らせない）。
                        best_content = content
                        best_score_data = score_article_content(
                            article.get('title', ''), best_content, article.get('keywords', ''))
                        base_gate_score = best_score_data['score']
                        for polish_round in range(1, QUALITY_GATE_MAX_POLISH + 1):
                            update_job(
                                current_title=article.get('title', ''),
                                message=f"品質改善 {polish_round}/{QUALITY_GATE_MAX_POLISH}（現在 {best_score_data['score']}点）: {article.get('title', '')}"
                            )
                            feedback = ' / '.join(dict.fromkeys(
                                pipeline_warnings + (best_score_data.get('suggestions') or [])
                            ))
                            polish_prompt = build_article_polish_prompt(
                                article, article_type, quality, best_content, feedback
                            )
                            polish_message = create_claude_message(
                                client, polish_prompt,
                                max_tokens=claude_max_tokens_for_quality(quality, floor=2400, ceiling=7000)
                            )
                            polished_raw = anthropic_message_text(polish_message)
                            polished_content, enhance_warning = safe_enhance_generated_article_html(
                                polished_raw, article, article_type
                            )
                            if enhance_warning:
                                postprocess_warnings.append(enhance_warning)
                            usage_parts.append(build_article_usage(polish_prompt, polished_raw, polish_message))
                            long_enough = len(html_to_text(polished_content)) >= max(
                                500, int(len(html_to_text(best_content)) * 0.75)
                            )
                            polished_valid = validate_generated_article(
                                article, article_type, polished_content, quality
                            )
                            polished_score_data = score_article_content(
                                article.get('title', ''), polished_content, article.get('keywords', '')
                            )
                            improved = (
                                long_enough and not polished_valid
                                and polished_score_data['score'] > best_score_data['score']
                            )
                            if improved:
                                best_content = polished_content
                                best_score_data = polished_score_data
                            # 基準達成 or 今回改善しなかった → これ以上回さず打ち切り
                            if best_score_data['score'] >= QUALITY_GATE_MIN_SCORE or not improved:
                                break
                        post_content = best_content
                        if best_score_data['score'] > base_gate_score:
                            postprocess_warnings.append(
                                f'品質ゲート: スコアを {base_gate_score}→{best_score_data["score"]}点 に改善しました。'
                            )
                        elif best_score_data['score'] < QUALITY_GATE_MIN_SCORE:
                            postprocess_warnings.append(
                                f'品質スコア {best_score_data["score"]}点 が基準({QUALITY_GATE_MIN_SCORE})に届きませんでした。手動での見直しを推奨します。'
                            )

                    update_job(current_title=article.get('title', ''), message=f"本文保存済み。本文HTMLを整えています: {article.get('title', '')}")
                    if post_content != content:
                        post_content, enhance_warning = safe_enhance_generated_article_html(post_content, article, article_type)
                        if enhance_warning:
                            postprocess_warnings.append(enhance_warning)
                        post_content, post_marker_stats = insert_card_markers(
                            post_content, article_type,
                            title=article.get('title') if isinstance(article, dict) else None,
                        )
                        post_generated_at = now_iso()
                        with _DATA_LOCK:
                            current_articles = load_articles()
                            for a in current_articles:
                                if a['id'] == article['id']:
                                    # 競合ガード: ベース生成後に別経路（対応履歴からの
                                    # 再生成など）で本文が書き換わっていたら、品質改善
                                    # (polish) 結果で上書きしない（ユーザーの再生成を保護）。
                                    if a.get('content_hash') != content_hash(content):
                                        break
                                    a['content'] = post_content
                                    a['generation_phase'] = 'postprocessed'
                                    a['updated_at'] = post_generated_at
                                    a['content_hash'] = content_hash(post_content)
                                    a['last_generation_chars'] = len(html_to_text(post_content))
                                    usage = combine_article_usages(usage_parts)
                                    a['usage'] = usage
                                    a['card_injection_stats'] = {
                                        'h3_count': 0, 'matched_count': 0, 'products_available': 0,
                                        'fallback_count': 0,
                                        'marker_count': post_marker_stats.get('marker_count', 0),
                                        'mode': 'marker_only',
                                    }
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
                    with _DATA_LOCK:
                        current_articles = load_articles()
                        for a in current_articles:
                            if a['id'] == article['id']:
                                warnings = pipeline_warnings + postprocess_warnings
                                a['generation_warning'] = ' / '.join(dict.fromkeys(warnings))
                                a['generation_phase'] = 'base_saved_with_postprocess_warning'
                                a['updated_at'] = now_iso()
                                break
                        save_articles(current_articles)
                completed += 1
                update_job(completed=completed, failed=failed, retried=retried, message=f"{completed}/{total_for_msg}件生成済み")
            except Exception as e:
                trace = traceback.format_exc()
                error_text = str(e) or e.__class__.__name__
                error_detail = f'{stage}: {error_text}'
                # ── Claude API 過負荷 (HTTP 529 / overloaded_error) の特別扱い ──
                # 529 は Anthropic サーバ側の一時的な混雑。通常エラーの2回・数秒
                # バックオフでは回復前にリトライを使い切りバッチが全滅する。
                # 過負荷専用に「長め指数バックオフ・多めリトライ」で待ち抜き、
                # かつ通常リトライ予算（BATCH_GENERATION_MAX_RETRIES）を消費しない。
                if is_overload_error(e):
                    ov = overload_counts.get(article_id, 0) + 1
                    overload_counts[article_id] = ov
                    if ov <= CLAUDE_OVERLOAD_MAX_RETRIES:
                        wait = min(180, 20 * (2 ** (ov - 1)))  # 20,40,80,160,180,180...
                        # 過負荷分は通常リトライ予算に数えない（次回 pickup の +1 を相殺）
                        attempt_counts[article_id] = attempt_no - 1
                        retried += 1
                        with _DATA_LOCK:
                            current_articles = load_articles()
                            for a in current_articles:
                                if a['id'] == article['id']:
                                    # リトライ待ちは 'queued'（待機中）にする。'generating' に
                                    # すると UI で複数件「処理中」に見え、キャンセル戻し対象から
                                    # も漏れる。実処理に入る時に worker が generating へ戻す。
                                    a['status'] = 'queued'
                                    a['error'] = f'Claude APIが混雑中（529 Overloaded）。{wait}秒待って自動再試行します'
                                    a['updated_at'] = now_iso()
                                    break
                            save_articles(current_articles)
                        queue_articles.append(article)
                        update_job(
                            completed=completed,
                            failed=failed,
                            retried=retried,
                            current_title=article.get('title', ''),
                            last_error=error_detail,
                            message=f"Claude APIが混雑中（529 Overloaded）。{wait}秒待って再試行します（過負荷リトライ {ov}/{CLAUDE_OVERLOAD_MAX_RETRIES}）"
                        )
                        time.sleep(wait)
                        continue
                    # 過負荷リトライも尽きた → 通常のエラー処理へ流す
                if attempt_no <= BATCH_GENERATION_MAX_RETRIES:
                    retried += 1
                    with _DATA_LOCK:
                        current_articles = load_articles()
                        for a in current_articles:
                            if a['id'] == article['id']:
                                # リトライ待ちは 'queued'（待機中）。実処理時に generating へ戻す
                                a['status'] = 'queued'
                                a['error'] = f'一時エラーのため自動リトライ待ち: {error_detail}'
                                a['error_stage'] = stage
                                a['error_trace'] = trace[-4000:]
                                a['generation_retry_count'] = attempt_no
                                a['updated_at'] = now_iso()
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
                with _DATA_LOCK:
                    current_articles = load_articles()
                    for a in current_articles:
                        if a['id'] == article['id']:
                            a['status'] = 'error'
                            a['error'] = error_detail
                            a['error_stage'] = stage
                            a['error_trace'] = trace[-4000:]
                            a.pop('batch_job_id', None)
                            a['generation_retry_count'] = attempt_no - 1
                            a['updated_at'] = now_iso()
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
            completed_at=now_iso(),
            message=f"一括生成完了: 成功 {completed}件 / エラー {failed}件 / 自動リトライ {retried}回"
        )

    def run_batch_safe():
        try:
            run_batch()
        except Exception as outer_e:
            outer_trace = traceback.format_exc()
            app.logger.error('Batch worker outer exception: %s\n%s', outer_e, outer_trace)
            try:
                with _DATA_LOCK:
                    current_articles = load_articles()
                    for a in current_articles:
                        if a.get('batch_job_id') == job_id and a.get('status') in ('queued', 'generating'):
                            new_status = fallback_article_status(a)
                            a['status'] = new_status
                            a.pop('batch_job_id', None)
                            a['updated_at'] = now_iso()
                            if new_status == 'generated':
                                a['generation_warning'] = f'バッチが予期せず終了しましたが、本文は保存済みです: {compact_ai_error(outer_e, 120)}'
                            else:
                                a['generation_warning'] = f'バッチが予期せず終了: {compact_ai_error(outer_e, 120)}'
                    save_articles(current_articles)
            except Exception:
                pass
            try:
                update_job(
                    status='crashed',
                    current_title='',
                    last_error=str(outer_e),
                    last_error_trace=outer_trace[-4000:],
                    completed_at=now_iso(),
                    message=f"バッチが予期せず終了しました: {compact_ai_error(outer_e, 200)}"
                )
            except Exception:
                pass

    thread = threading.Thread(target=run_batch_safe, daemon=True)
    thread.start()
    return thread


def recover_orphan_batches_on_startup():
    """[DEPRECATED] resume_orphan_batches_on_startup() に置き換え済み。
    互換性のため空関数として残す（後方参照対策）。"""
    pass


def resume_orphan_batches_on_startup():
    """アプリ起動時、status='running'/'queued' のまま残ってる「孤児バッチ」を検出して、
    未完了の記事を継続処理する。Render の再デプロイで thread が死んでもジョブが
    実質的に止まらないようにするための仕組み。
    """
    try:
        jobs = load_batch_jobs()
        articles = load_articles()
        settings = load_settings()
    except Exception as e:
        try:
            app.logger.warning('resume_orphan_batches_on_startup: load failed: %s', e)
        except Exception:
            pass
        return

    api_key = settings.get('claude_api_key') or os.environ.get('ANTHROPIC_API_KEY', '')
    article_by_id = {a.get('id'): a for a in articles}
    now = now_iso()
    changed_jobs = False
    changed_articles = False
    resumed_count = 0

    for j in jobs:
        if j.get('status') not in ('queued', 'running'):
            continue
        if j.get('cancel_requested'):
            # ユーザーが既にキャンセル要求済 → レジュームしない
            j['status'] = 'cancelled'
            j['completed_at'] = now
            j['updated_at'] = now
            j['message'] = (j.get('message') or '') + ' [再起動時にキャンセル確定]'
            changed_jobs = True
            article_ids = set(j.get('article_ids') or [])
            for a in articles:
                if a.get('id') in article_ids and a.get('status') in ('queued', 'generating'):
                    new_status = fallback_article_status(a)
                    a['status'] = new_status
                    a.pop('batch_job_id', None)
                    a['updated_at'] = now
                    changed_articles = True
            continue

        job_id = j.get('id')
        quality_id = j.get('quality_id')
        batch_article_type = j.get('batch_article_type') or 'ranking'
        article_ids = j.get('article_ids') or []

        # 残記事を抽出（既に完了している記事は除外）
        completed_states = ('generated', 'published', 'scheduled', 'updated')
        pending_articles = []
        for aid in article_ids:
            a = article_by_id.get(aid)
            if not a:
                continue
            if a.get('status') in completed_states:
                continue
            pending_articles.append(a)

        if not pending_articles:
            # 全件処理済 → ジョブを completed マーク
            j['status'] = 'completed'
            j['completed_at'] = now
            j['updated_at'] = now
            j['message'] = (j.get('message') or '') + ' [再起動時に全件処理済を確認]'
            changed_jobs = True
            continue

        # 残記事を 'queued' に揃え直し、batch_job_id を再設定
        pending_ids_set = {a['id'] for a in pending_articles}
        for a in articles:
            if a.get('id') in pending_ids_set:
                a['status'] = 'queued'
                a['batch_job_id'] = job_id
                a['updated_at'] = now
                a.pop('error', None)
                a.pop('error_stage', None)
                a.pop('error_trace', None)
                # generation_warning は読み手にレジュームを伝えるため上書きしない
                changed_articles = True

        # ジョブのメッセージとステータスを再開モードに
        j['status'] = 'running'
        j['updated_at'] = now
        j['message'] = f'再起動レジューム中: 残り {len(pending_articles)}件を継続処理します'
        changed_jobs = True

        # 先にディスクへ反映してから worker thread を起動
        # （worker は load_batch_jobs を読むので保存が先に必要）
        try:
            save_batch_jobs(jobs)
            changed_jobs = False  # 既に保存済みフラグ
            save_articles(articles)
            changed_articles = False
        except Exception as e:
            try:
                app.logger.warning('resume_orphan_batches_on_startup: pre-worker save failed: %s', e)
            except Exception:
                pass

        # worker thread 起動
        try:
            _start_batch_worker(job_id, api_key, quality_id, batch_article_type, pending_articles)
            resumed_count += 1
            try:
                app.logger.info('[startup-resume] resumed job %s with %d remaining articles', job_id, len(pending_articles))
            except Exception:
                pass
        except Exception as e:
            try:
                app.logger.error('[startup-resume] failed to start worker for job %s: %s', job_id, e)
            except Exception:
                pass

    if changed_jobs:
        try:
            save_batch_jobs(jobs)
        except Exception:
            pass
    if changed_articles:
        try:
            save_articles(articles)
        except Exception:
            pass
    if resumed_count:
        try:
            app.logger.info('[startup-resume] total resumed jobs: %d', resumed_count)
        except Exception:
            pass


def recover_stale_batch_jobs(jobs, articles):
    """ステータス running/queued なのに対象記事が全部処理完了してるジョブを finished に矯正。
    Render の再起動などで thread が落ちて job 状態だけ残ってる時の保険。
    """
    article_by_id = {a.get('id'): a for a in articles}
    pending_states = ('pending', 'queued', 'generating')
    changed = False
    # ⚠️ ローカル変数名は関数 now_iso() と被らないように now_iso_str にする
    now_iso_str = now_iso()
    for j in jobs:
        if j.get('status') not in ('queued', 'running'):
            continue
        ids = j.get('article_ids') or []
        if not ids:
            continue
        still_running = False
        for aid in ids:
            a = article_by_id.get(aid)
            if a and a.get('status') in pending_states:
                still_running = True
                break
        if not still_running:
            j['status'] = 'completed_stale_recovered'
            j['completed_at'] = j.get('completed_at') or now_iso_str
            j['message'] = j.get('message', '') + ' [自動復旧: 対象記事が全て処理完了状態のため終了マーク]'
            changed = True
    if changed:
        save_batch_jobs(jobs)
    return changed


@app.route('/api/dashboard/sites', methods=['GET'])
@login_required
@with_data_lock
def get_sites_dashboard():
    """各サイトの統計情報をダッシュボード用に集計して返す。"""
    settings = load_settings()
    sites = settings.get('sites', [])
    articles = load_articles()
    jobs = load_batch_jobs()
    # 古い running ジョブを自動復旧
    recover_stale_batch_jobs(jobs, articles)
    pending_states = ('pending', 'queued', 'generating')
    result = []
    for site in sites:
        sid = site.get('id')
        site_articles = [a for a in articles if str(a.get('site_id') or '') == sid]
        # 「ジョブが running/queued かつ そのジョブの対象記事の中に
        # まだ pending/generating の記事が存在するサイト記事を含む」場合のみアクティブとカウント
        active_jobs = [
            j for j in jobs
            if j.get('status') in ('queued', 'running')
            and any(
                str(a.get('site_id') or '') == sid
                and a.get('status') in pending_states
                for a in articles if a.get('id') in (j.get('article_ids') or [])
            )
        ]
        published = [a for a in site_articles if a.get('status') == 'published']
        recent_published = sorted(
            published,
            key=lambda a: a.get('published_at') or a.get('updated_at') or '',
            reverse=True
        )
        last_published_at = recent_published[0].get('published_at') if recent_published else None
        result.append({
            'id': sid,
            'name': site.get('name') or site.get('wp_url') or '(無名サイト)',
            'wp_url': site.get('wp_url') or '',
            'sheet_url': site.get('sheet_url') or '',
            'counts': {
                'total': len(site_articles),
                'pending': sum(1 for a in site_articles if a.get('status') == 'pending'),
                'generating': sum(1 for a in site_articles if a.get('status') == 'generating'),
                'generated': sum(1 for a in site_articles if a.get('status') == 'generated'),
                'published': len(published),
                'error': sum(1 for a in site_articles if a.get('status') == 'error'),
            },
            'active_batch_count': len(active_jobs),
            'last_published_at': last_published_at,
        })
    return jsonify({
        'sites': result,
        'current_site_id': session.get('current_site_id') or '',
    })


@app.route('/api/articles', methods=['POST'])
@login_required
@with_data_lock
def create_article():
    data = request.get_json(silent=True) or {}
    title = str(data.get('title') or '').strip()
    if not title:
        return jsonify({'error': 'タイトルを入力してください'}), 400
    article_type = normalize_article_type(data.get('article_type'), 'ranking')
    keywords = data.get('keywords', '')
    ad_keywords = str(data.get('ad_keywords') or '').strip() or infer_ad_keywords_from_title(title, keywords, article_type)
    # 商標記事はランキング記事から自動生成されるケースが多く、フロントは slug を送ってこない。
    # source_product_name から英字 slug を自動生成する（'andeor-review' のような形）。
    slug = normalize_slug(data.get('slug'))
    if not slug and article_type == 'brand':
        source_name = data.get('source_product_name') or ''
        slug = auto_slug_from_brand_name(source_name) or auto_slug_from_brand_name(title)
    article = {
        'id': str(uuid.uuid4()),
        'title': title,
        'keywords': keywords,
        'category': data.get('category', ''),
        'slug': slug,
        'article_type': article_type,
        'ad_keywords': ad_keywords,
        'priority': data.get('priority', ''),
        'schedule_date': data.get('schedule_date', ''),
        'memo': data.get('memo', ''),
        'status': 'pending',
        'content': data.get('content', ''),
        'created_at': now_iso(),
        'quality_id': data.get('quality_id') or None,
        'site_id': data.get('site_id') or None,
        'parent_article_id': data.get('parent_article_id') or None,
        'source_product_name': data.get('source_product_name') or '',
        'wp_post_id': None,
        'wp_url': None,
    }
    if article.get('content'):
        article['status'] = 'generated'
        article['generated_at'] = now_iso()
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
@with_data_lock
def update_article(article_id):
    data = request.get_json(silent=True) or {}
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
@with_data_lock
def recover_generated_content(article_id):
    data = request.get_json(silent=True) or {}
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

        now = now_iso()
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
@with_data_lock
def delete_article(article_id):
    articles = [a for a in load_articles() if a['id'] != article_id]
    save_articles(articles)
    return jsonify({'success': True})

@app.route('/api/articles/bulk-delete', methods=['POST'])
@login_required
@with_data_lock
def bulk_delete():
    ids = set((request.get_json(silent=True) or {}).get('ids', []))
    articles = [a for a in load_articles() if a['id'] not in ids]
    save_articles(articles)
    return jsonify({'success': True})


@app.route('/api/articles/score', methods=['POST'])
@login_required
@with_data_lock
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


@app.route('/api/batch-jobs/<job_id>/cancel', methods=['POST'])
@login_required
@with_data_lock
def cancel_batch_job(job_id):
    """一括処理にキャンセルフラグを立てる。
    ワーカーは次の記事を取り出す前にこのフラグを確認し、true なら停止する。
    停止時、残り queued 記事は pending に戻され、ジョブ status は cancelled になる。
    """
    jobs = load_batch_jobs()
    target = None
    for j in jobs:
        if j.get('id') == job_id:
            target = j
            break
    if not target:
        return jsonify({'error': 'ジョブが見つかりません'}), 404
    if target.get('status') not in ('queued', 'running'):
        return jsonify({'error': f'このジョブは既に終了状態です（status={target.get("status")}）'}), 400
    target['cancel_requested'] = True
    target['updated_at'] = now_iso()
    target['message'] = (target.get('message') or '') + ' [キャンセル要求受信。次の記事を取り出す前に停止します]'
    save_batch_jobs(jobs)
    return jsonify({'success': True, 'message': 'キャンセル要求を送信しました。間もなく停止します。'})


@app.route('/api/batch-jobs/<job_id>/force-terminate', methods=['POST'])
@login_required
@with_data_lock
def force_terminate_batch_job(job_id):
    """ジョブを強制終了マークする。
    ワーカースレッドは Python から強制停止できないため:
    - ジョブ status を 'terminated' に矯正
    - 該当ジョブの queued / generating 記事を pending に戻す
    - 進行中の thread は最後の API 呼出が終わるまで動き続けるが、結果は反映されない
      （save_articles 時に既に pending なので衝突しても上書きされるだけ）
    """
    jobs = load_batch_jobs()
    target = None
    for j in jobs:
        if j.get('id') == job_id:
            target = j
            break
    if not target:
        return jsonify({'error': 'ジョブが見つかりません'}), 404
    target['cancel_requested'] = True
    target['status'] = 'terminated'
    target['updated_at'] = now_iso()
    target['completed_at'] = target.get('completed_at') or target['updated_at']
    target['message'] = (target.get('message') or '') + ' [強制終了]'
    save_batch_jobs(jobs)

    article_ids = set(target.get('article_ids') or [])
    articles = load_articles()
    changed = 0
    for a in articles:
        if a.get('id') in article_ids and a.get('status') in ('queued', 'generating'):
            a['status'] = 'pending'
            a.pop('batch_job_id', None)
            a['updated_at'] = now_iso()
            a['generation_warning'] = '一括処理を強制終了しました。必要なら再生成してください。'
            changed += 1
    if changed:
        save_articles(articles)
    return jsonify({
        'success': True,
        'reset_count': changed,
        'message': f'強制終了しました。{changed}件の記事を未生成に戻しました。',
    })


# Import
@app.route('/api/import', methods=['POST'])
@login_required
@with_data_lock
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
            'created_at': now_iso(),
            'quality_id': resolve_id(cell(row, 'quality'), quality_list),
            'site_id': resolve_id(cell(row, 'site'), sites) or site_fallback,
            'wp_post_id': None,
            'wp_url': None,
        }
        if content:
            article['status'] = 'generated'
            article['generated_at'] = now_iso()
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

    data = request.get_json(silent=True) or {}
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
    now = now_iso()
    generation_run_id = str(uuid.uuid4())
    previous_content = article.get('content', '')
    previous_content_hash = content_hash(previous_content)
    previous_content_text = html_to_text(previous_content)
    is_regeneration = bool(previous_content_text.strip())
    regeneration_instruction = build_regeneration_instruction(previous_content)
    with _DATA_LOCK:
        articles = load_articles()
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
            yield f"data: {json.dumps({'status': 'fetching_products', 'message': 'Amazon/楽天で実商品データを取得しています'})}\n\n"
            products, product_status = fetch_product_context(article_work, settings, limit=15)
            if products:
                provider_label = 'Amazon' if products[0].get('amazon') else '楽天'
                yield f"data: {json.dumps({'status': 'products_loaded', 'count': len(products), 'message': f'{provider_label}から実商品 {len(products)}件 を取得しました'})}\n\n"
            elif product_status == 'no_provider':
                yield f"data: {json.dumps({'status': 'products_skipped', 'message': 'Amazon/楽天APIキー未設定のため、実商品データなしで生成します'})}\n\n"
            else:
                yield f"data: {json.dumps({'status': 'products_skipped', 'message': f'検索結果が空でした（{product_status}）。実商品データなしで生成します'})}\n\n"
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

            prompt += build_product_context_prompt(products, article_type)

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
            if client and should_use_segmented_generation(article_type, quality, article_work):
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

            print(f'[GEN-SSE] BEFORE inject: products={len(products) if products else 0}, content_len={len(full_content)}, has_card={"aff-product-card" in full_content}', flush=True)
            # プラグイン連携前提でマーカー挿入に固定（UIセレクタ廃止）
            full_content, marker_stats = insert_card_markers(full_content, article_type, title=article_work.get('title') if isinstance(article_work, dict) else None)
            card_stats = {'h3_count': 0, 'matched_count': 0, 'products_available': 0, 'fallback_count': 0,
                          'marker_count': marker_stats.get('marker_count', 0), 'mode': 'marker_only'}
            _cards_msg = f'広告マーカー挿入: {card_stats["marker_count"]}件（プラグイン処理）'
            print(f'[GEN-SSE] AFTER inject: content_len={len(full_content)}, has_card={"aff-product-card" in full_content}, stats={card_stats}', flush=True)
            yield f"data: {json.dumps({'status': 'cards_injected', 'message': _cards_msg})}\n\n"
            clean_content, enhance_warning = safe_enhance_generated_article_html(full_content, article_work, article_type)
            print(f'[GEN-SSE] AFTER enhance: clean_content_len={len(clean_content)}, has_card={"aff-product-card" in clean_content}, enhance_warning={enhance_warning!r}', flush=True)
            clean_content = strip_summary_table_sections(clean_content)
            print(f'[GEN-SSE] AFTER strip_summary: clean_content_len={len(clean_content)}, has_card={"aff-product-card" in clean_content}', flush=True)
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

            # ロック内では load→変更→save のみ。yield はロック解放後に行う
            # （ジェネレータが yield 中に放棄されてもロックを掴んだままにしないため）
            similarity = 0
            changed = False
            usage = {}
            generation_warning = enhance_warning or ''
            with _DATA_LOCK:
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
                            a['updated_at'] = now_iso()
                            a['generation_finished_at'] = a['updated_at']
                        else:
                            generated_at = now_iso()
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
                            a['last_generation_title'] = article_work.get('title', a.get('title', ''))
                            a['last_generation_keywords'] = article_work.get('keywords', a.get('keywords', ''))
                            a['card_injection_stats'] = card_stats
                            usage = combine_article_usages(usage_parts)
                            append_generation_usage(a, usage, generation_run_id, generated_at, clean_content)
                            apply_score_fields(a)
                        break
                save_articles(current_articles)
            if validation_error:
                yield f"data: {json.dumps({'error': validation_error})}\n\n"
                return
            yield f"data: {json.dumps({'done': True, 'run_id': generation_run_id, 'content_chars': content_chars, 'changed': changed, 'similarity': round(similarity, 4), 'warning': generation_warning, 'usage': usage, 'card_stats': card_stats})}\n\n"
        except Exception as e:
            with _DATA_LOCK:
                current_articles = load_articles()
                for a in current_articles:
                    if a['id'] == article_id:
                        a['status'] = 'error'
                        a['error'] = str(e)
                        a['updated_at'] = now_iso()
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

    data = request.get_json(silent=True) or {}
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
    now = now_iso()
    with _DATA_LOCK:
        articles = load_articles()
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
        products, _ = fetch_product_context(article_work, settings, limit=15)
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
{build_product_context_prompt(products, article_type)}
{build_quality_structure_html_prompt(quality)}
{build_article_completion_prompt(quality, article_type)}
"""
        if client and should_use_segmented_generation(article_type, quality, article_work):
            raw_content, usage_parts = generate_segmented_article_sync(
                client,
                base_prompt,
                article_work,
                article_type,
                quality
            )
        elif client:
            message = create_claude_message(client, base_prompt, max_tokens=claude_max_tokens_for_quality(quality))
            raw_content = anthropic_message_text(message)
            usage_parts = [build_article_usage(base_prompt, raw_content, message)]
        else:
            raise ValueError('Claude APIキーが設定されていません')
        # プラグイン連携前提でマーカー挿入に固定（UIセレクタ廃止）
        raw_content, _marker_stats = insert_card_markers(raw_content, article_type, title=article_work.get('title') if isinstance(article_work, dict) else None)
        clean_content, enhance_warning = safe_enhance_generated_article_html(raw_content, article_work, article_type)
        clean_content = strip_summary_table_sections(clean_content)
        validation_error = validate_generated_article(article_work, article_type, clean_content, quality)
        content_chars = len(html_to_text(clean_content))
        if not validation_error and content_chars < 500:
            validation_error = f'生成結果が短すぎます（{content_chars}文字）。もう一度生成してください。'
        if validation_error:
            raise RuntimeError(validation_error)

        saved_article = None
        generated_at = now_iso()
        similarity = content_similarity(previous_content, clean_content) if is_regeneration else 0
        changed = content_hash(clean_content) != previous_content_hash
        usage = combine_article_usages(usage_parts)
        with _DATA_LOCK:
            current_articles = load_articles()
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
                    a['last_generation_title'] = article_work.get('title', a.get('title', ''))
                    a['last_generation_keywords'] = article_work.get('keywords', a.get('keywords', ''))
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
        with _DATA_LOCK:
            current_articles = load_articles()
            for a in current_articles:
                if a['id'] == article_id:
                    a['status'] = 'error'
                    a['error'] = str(e)
                    a['updated_at'] = now_iso()
                    a['generation_finished_at'] = a['updated_at']
                    break
            save_articles(current_articles)
        return jsonify({'error': str(e)}), 500


# Batch generate
@app.route('/api/batch-generate', methods=['POST'])
@login_required
@with_data_lock
def batch_generate():
    data = request.get_json(silent=True) or {}
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

    batch_article_type = normalize_article_type(data.get('article_type'), 'ranking')
    if not api_key and batch_article_type != 'ranking':
        return jsonify({'error': 'Claude APIキーが設定されていません'}), 400

    now = now_iso()
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
        # 起動時レジューム用に保存するメタデータ
        'quality_id': quality_id,
        'batch_article_type': batch_article_type,
        'started_at': now,
        'updated_at': now,
        'message': '一括生成を開始しました。ページを移動しても処理は継続します。',
    }
    jobs = load_batch_jobs()
    jobs.insert(0, job)
    save_batch_jobs(jobs)
    # バッチ開始時: 全件を 'queued'（待機中）にする。ワーカーが各記事を取り出した時に
    # 'generating'（処理中）に書き換える。これで「いま実際に処理されている1件」と
    # 「待機中の残り」をUIで明確に区別できる。
    pending_ids_set = set(job['article_ids'])
    for a in articles:
        if a['id'] in pending_ids_set:
            a['status'] = 'queued'
            a['batch_job_id'] = job_id
            a['generation_started_at'] = now
            a['updated_at'] = now
            a.pop('error', None)
            a.pop('error_stage', None)
            a.pop('error_trace', None)
            a.pop('generation_warning', None)
            a.pop('last_generation_interrupted', None)
    save_articles(articles)

    # モジュールレベルの worker を起動。
    # この関数は startup-resume からも呼ばれる共通エントリポイント。
    _start_batch_worker(job_id, api_key, quality_id, batch_article_type, pending)
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
        headers=WP_REQUEST_HEADERS,
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
        headers=WP_REQUEST_HEADERS,
        timeout=30
    )
    response.raise_for_status()
    post_data = response.json()
    # POST レスポンスから検証用コンテンツを取得（2回目フェッチを廃止して時間短縮）。
    # Render エッジの30秒タイムアウトを避けるため、検証は POST 応答内のデータで行う。
    after_content = extract_wp_edit_content(post_data)
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
@with_data_lock
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

    data = request.get_json(silent=True) or {}
    post_status = data.get('post_status', 'draft')
    content = prepare_article_content_for_publish(article['content'], settings)
    post_payload = {'title': article['title'], 'content': content, 'status': post_status}
    slug = normalize_slug(article.get('slug'))
    if slug:
        post_payload['slug'] = slug
    category_ids = resolve_wp_category_ids(wp_url, wp_user, wp_password, article.get('category', ''))
    if category_ids:
        post_payload['categories'] = category_ids

    try:
        response = requests.post(
            f"{wp_url}/wp-json/wp/v2/posts",
            auth=(wp_user, wp_password),
            json=post_payload,
            headers=WP_REQUEST_HEADERS,
            timeout=30
        )
        response.raise_for_status()
        post_data = response.json()

        for a in articles:
            if a['id'] == article_id:
                a['status'] = 'published'
                a['wp_post_id'] = post_data['id']
                a['wp_url'] = post_data.get('link', '')
                a['published_at'] = now_iso()
                break
        save_articles(articles)
        return jsonify({'success': True, 'wp_url': post_data.get('link', ''), 'wp_post_id': post_data['id']})
    except requests.exceptions.RequestException as e:
        return jsonify({'error': f'WordPress投稿エラー: {describe_wp_request_error(e)}'}), 500


@app.route('/api/articles/<article_id>/unlink-wp', methods=['POST'])
@login_required
@with_data_lock
def unlink_wp_post(article_id):
    """記事から wp_post_id / wp_url の紐付けを外す（再投稿で新規作成したい時に使う）。"""
    articles = load_articles()
    target = None
    for a in articles:
        if a['id'] == article_id:
            target = a
            a.pop('wp_post_id', None)
            a.pop('wp_url', None)
            a.pop('posted_at', None)
            a.pop('repaired_at', None)
            if a.get('status') in ('published', 'scheduled'):
                a['status'] = 'generated' if a.get('content') else 'pending'
            a['updated_at'] = now_iso()
            break
    if not target:
        return jsonify({'error': '記事が見つかりません'}), 404
    save_articles(articles)
    return jsonify({'success': True})


@app.route('/api/articles/<article_id>/repair-post', methods=['POST'])
@login_required
@with_data_lock
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
                a['repaired_at'] = now_iso()
                a['updated_at'] = now_iso()
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
    except requests.exceptions.HTTPError as e:
        status_code = e.response.status_code if e.response is not None else 0
        if status_code == 404:
            return jsonify({
                'error': f'WordPress側で投稿ID {article.get("wp_post_id")} が見つかりません（削除された可能性）',
                'wp_post_not_found': True,
                'wp_post_id': article.get('wp_post_id'),
            }), 404
        return jsonify({'error': f'WordPress上書き更新エラー: {str(e)}'}), 500
    except requests.exceptions.RequestException as e:
        return jsonify({'error': f'WordPress上書き更新エラー: {str(e)}'}), 500


@app.route('/api/articles/bulk-repair-posts', methods=['POST'])
@login_required
@with_data_lock
def bulk_repair_article_posts():
    ids = set((request.get_json(silent=True) or {}).get('ids', []))
    articles = load_articles()
    settings = load_settings()
    results = {'success': 0, 'unchanged': 0, 'mismatch': 0, 'error': 0, 'errors': []}
    now = now_iso()

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


# Batch publish (background job)
@app.route('/api/batch-publish', methods=['POST'])
@login_required
def batch_publish():
    """
    WP一括投稿をバックグラウンドジョブとして起動。
    300記事対応:
      - ThreadPoolExecutor(max_workers=5) で並列投稿
      - 10件ごとにまとめてarticles.jsonを書き込む（I/O削減）
      - ジョブをpublish_jobs.jsonに永続化（ページ更新後も進捗復元可能）
      - _DATA_LOCK はJSON読み書き時のみ取得（WP通信中は解放）
    """
    data = request.get_json(silent=True) or {}
    article_ids = data.get('article_ids', [])
    post_status = data.get('post_status', 'publish')  # デフォルト公開

    with _DATA_LOCK:
        settings       = load_settings()
        articles_snap  = load_articles()
        quality_list   = load_quality()

    article_lookup = {a['id']: a for a in articles_snap}
    targets = [article_lookup[i] for i in article_ids
               if i in article_lookup and article_lookup[i].get('content')]

    if not targets:
        return jsonify({'error': '投稿可能な記事（本文あり）が見つかりません'}), 400

    job_id = str(uuid.uuid4())
    now    = now_iso()
    job = {
        'id':           job_id,
        'status':       'running',
        'total':        len(targets),
        'completed':    0,
        'failed':       0,
        'errors':       [],
        'current_title': '',
        'post_status':  post_status,
        'started_at':   now,
        'updated_at':   now,
    }
    with _PUBLISH_JOBS_LOCK:
        _PUBLISH_JOBS[job_id] = job
    # ディスクにも保存（ページ更新後に復元できるよう）
    with _DATA_LOCK:
        pjobs = load_publish_jobs()
        pjobs.insert(0, job)
        save_publish_jobs(pjobs)

    def worker():
        # カテゴリーIDキャッシュ: (wp_url, category) → [id]
        # 同一サイト×同一カテゴリーのWP呼び出しを1回に抑える
        cat_cache = {}

        # 結果バッファ: {article_id: (wp_post_id, wp_link, published_at)} or None=失敗
        result_buf = {}
        result_lock = threading.Lock()

        def _publish_one(article):
            """1記事のWP投稿 → (success, wp_id, wp_link, err_msg)"""
            quality = select_quality_definition(
                quality_list,
                article.get('quality_id'),
                article.get('article_type', 'ranking')
            )
            verr = validate_generated_article(
                article,
                article.get('article_type', 'ranking'),
                article.get('content', ''),
                quality
            )
            if verr:
                return False, None, None, f'品質チェック未通過: {verr}'

            wp_url, wp_user, wp_pass = get_site_credentials(article, settings)
            if not all([wp_url, wp_user, wp_pass]):
                return False, None, None, 'サイト未設定'

            content = prepare_article_content_for_publish(article['content'], settings)
            payload = {'title': article['title'], 'content': content, 'status': post_status}
            slug = normalize_slug(article.get('slug'))
            if slug:
                payload['slug'] = slug

            cache_key = (wp_url, article.get('category', ''))
            if cache_key not in cat_cache:
                cat_cache[cache_key] = resolve_wp_category_ids(
                    wp_url, wp_user, wp_pass, article.get('category', '')
                )
            cat_ids = cat_cache[cache_key]
            if cat_ids:
                payload['categories'] = cat_ids

            try:
                resp = requests.post(
                    f"{wp_url}/wp-json/wp/v2/posts",
                    auth=(wp_user, wp_pass),
                    json=payload,
                    headers=WP_REQUEST_HEADERS,
                    timeout=30,
                )
                resp.raise_for_status()
                pd = resp.json()
                return True, pd['id'], pd.get('link', ''), None
            except requests.exceptions.RequestException as e:
                return False, None, None, describe_wp_request_error(e)
            except Exception as e:
                return False, None, None, str(e)

        def _flush_buf(force=False):
            """result_buf を articles.json に一括書き込み（10件ごと or 強制）"""
            with result_lock:
                if not result_buf:
                    return
                # force=True か 10件以上溜まった時のみ書き込む
                if not force and len(result_buf) < 10:
                    return
                to_write = dict(result_buf)
                result_buf.clear()
            with _DATA_LOCK:
                arts = load_articles()
                for a in arts:
                    entry = to_write.get(a['id'])
                    if entry:
                        a['status']       = 'published'
                        a['wp_post_id']   = entry[0]
                        a['wp_url']       = entry[1]
                        a['published_at'] = entry[2]
                save_articles(arts)

        done = 0
        with ThreadPoolExecutor(max_workers=5) as executor:
            future_map = {executor.submit(_publish_one, a): a for a in targets}
            for future in as_completed(future_map):
                article = future_map[future]
                with _PUBLISH_JOBS_LOCK:
                    _PUBLISH_JOBS[job_id]['current_title'] = article['title'][:50]
                try:
                    ok, wp_id, wp_link, err = future.result()
                except Exception as e:
                    ok, wp_id, wp_link, err = False, None, None, str(e)

                if ok:
                    with result_lock:
                        result_buf[article['id']] = (wp_id, wp_link, now_iso())
                    with _PUBLISH_JOBS_LOCK:
                        _PUBLISH_JOBS[job_id]['completed'] += 1
                else:
                    with _PUBLISH_JOBS_LOCK:
                        j = _PUBLISH_JOBS[job_id]
                        j['failed'] += 1
                        j['errors'].append({'title': article['title'], 'error': err})

                done += 1
                with _PUBLISH_JOBS_LOCK:
                    _PUBLISH_JOBS[job_id]['updated_at'] = now_iso()

                _flush_buf(force=False)  # 10件溜まったら書き込む

        _flush_buf(force=True)  # 残りを全部書き込む

        # ジョブ完了をメモリ＋ディスクに記録
        with _PUBLISH_JOBS_LOCK:
            j = _PUBLISH_JOBS[job_id]
            j['status']     = 'completed'
            j['updated_at'] = now_iso()
            job_snapshot    = dict(j)
        with _DATA_LOCK:
            pjobs = load_publish_jobs()
            for pj in pjobs:
                if pj.get('id') == job_id:
                    pj.update(job_snapshot)
                    break
            save_publish_jobs(pjobs)

    threading.Thread(target=worker, daemon=True).start()
    return jsonify({
        'job_id':  job_id,
        'total':   len(targets),
        'message': f'{len(targets)}件のWP投稿をバックグラウンドで開始しました。',
    })


@app.route('/api/batch-publish/jobs/<job_id>', methods=['GET'])
@login_required
def get_publish_job(job_id):
    with _PUBLISH_JOBS_LOCK:
        job = _PUBLISH_JOBS.get(job_id)
    if not job:
        # メモリにない場合はディスクから復元
        with _DATA_LOCK:
            pjobs = load_publish_jobs()
        job = next((j for j in pjobs if j.get('id') == job_id), None)
    if not job:
        return jsonify({'error': 'ジョブが見つかりません'}), 404
    return jsonify(job)


@app.route('/api/batch-publish/jobs/latest', methods=['GET'])
@login_required
def get_latest_publish_job():
    """ページ初期化時に直近の実行中ジョブを復元するためのエンドポイント"""
    # まずメモリの running ジョブを探す
    with _PUBLISH_JOBS_LOCK:
        running = [j for j in _PUBLISH_JOBS.values() if j.get('status') == 'running']
    if running:
        latest = max(running, key=lambda j: j.get('started_at', ''))
        return jsonify(latest)
    # なければディスクから直近1件
    with _DATA_LOCK:
        pjobs = load_publish_jobs()
    if pjobs:
        return jsonify(pjobs[0])
    return jsonify({'status': 'none'})


# Scheduled (future) publish
def _run_sched_publish_worker(job_id, targets, daily_cap, start_date):
    """予約投稿をバックグラウンドで処理するワーカー。"""
    start_hour = 10
    spacing_minutes = max(5, (12 * 60) // daily_cap)

    with _DATA_LOCK:
        settings = load_settings()

    for idx, article in enumerate(targets):
        day_offset = idx // daily_cap
        slot = idx % daily_cap
        total_minutes = start_hour * 60 + slot * spacing_minutes
        total_minutes = min(total_minutes, 23 * 60 + 59)
        sched_dt = datetime(
            start_date.year, start_date.month, start_date.day,
            total_minutes // 60, total_minutes % 60, 0,
        ) + timedelta(days=day_offset)
        sched_dt_str = sched_dt.strftime('%Y-%m-%dT%H:%M:%S')

        wp_url, wp_user, wp_password = get_site_credentials(article, settings)
        if not all([wp_url, wp_user, wp_password]):
            _SCHED_PUBLISH_JOBS[job_id]['error'] += 1
            _SCHED_PUBLISH_JOBS[job_id]['errors'].append(
                {'title': article.get('title', ''), 'error': 'サイト未設定'})
            _SCHED_PUBLISH_JOBS[job_id]['completed'] += 1
            continue

        content = prepare_article_content_for_publish(article['content'], settings)
        post_payload = {
            'title': article['title'],
            'content': content,
            'status': 'future',
            'date': sched_dt_str,
        }
        slug = normalize_slug(article.get('slug'))
        if slug:
            post_payload['slug'] = slug
        category_ids = resolve_wp_category_ids(wp_url, wp_user, wp_password, article.get('category', ''))
        if category_ids:
            post_payload['categories'] = category_ids

        try:
            resp = requests.post(
                f"{wp_url}/wp-json/wp/v2/posts",
                auth=(wp_user, wp_password),
                json=post_payload,
                headers=WP_REQUEST_HEADERS,
                timeout=30,
            )
            resp.raise_for_status()
            post_data = resp.json()
            with _DATA_LOCK:
                articles = load_articles()
                for a in articles:
                    if a['id'] == article['id']:
                        a['status'] = 'scheduled'
                        a['wp_post_id'] = post_data['id']
                        a['wp_url'] = post_data.get('link', '')
                        a['scheduled_at'] = sched_dt_str
                        a['published_at'] = now_iso()
                        break
                save_articles(articles)
            _SCHED_PUBLISH_JOBS[job_id]['success'] += 1
        except requests.exceptions.RequestException as e:
            _SCHED_PUBLISH_JOBS[job_id]['error'] += 1
            _SCHED_PUBLISH_JOBS[job_id]['errors'].append(
                {'title': article.get('title', ''), 'error': describe_wp_request_error(e)})
        except Exception as e:
            _SCHED_PUBLISH_JOBS[job_id]['error'] += 1
            _SCHED_PUBLISH_JOBS[job_id]['errors'].append(
                {'title': article.get('title', ''), 'error': str(e)})

        _SCHED_PUBLISH_JOBS[job_id]['completed'] += 1

    _SCHED_PUBLISH_JOBS[job_id]['status'] = 'done'


@app.route('/api/schedule-publish', methods=['POST'])
@login_required
def schedule_publish():
    """予約投稿をバックグラウンドジョブとして開始し、job_id を即返す。"""
    from datetime import date as _date
    data = request.get_json(silent=True) or {}
    article_ids = data.get('article_ids') or []
    start_date_str = str(data.get('start_date') or '').strip()
    daily_cap = clamp_int(data.get('daily_cap', 20), 20, 1, 200)

    try:
        start_date = datetime.strptime(start_date_str, '%Y-%m-%d').date()
    except ValueError:
        start_date = _date.today() + timedelta(days=1)

    with _DATA_LOCK:
        articles = load_articles()
    article_lookup = {a['id']: a for a in articles}
    targets = [article_lookup[i] for i in article_ids
               if i in article_lookup and article_lookup[i].get('content')]
    if not targets:
        return jsonify({'error': '生成済み本文のある記事を選択してください'}), 400

    job_id = str(uuid.uuid4())
    _SCHED_PUBLISH_JOBS[job_id] = {
        'status': 'running',
        'total': len(targets),
        'completed': 0,
        'success': 0,
        'error': 0,
        'errors': [],
    }
    threading.Thread(
        target=_run_sched_publish_worker,
        args=(job_id, targets, daily_cap, start_date),
        daemon=True,
    ).start()

    return jsonify({'success': True, 'job_id': job_id, 'total': len(targets)})


@app.route('/api/schedule-publish/jobs/<job_id>', methods=['GET'])
@login_required
def get_sched_publish_job(job_id):
    job = _SCHED_PUBLISH_JOBS.get(job_id)
    if not job:
        return jsonify({'error': 'ジョブが見つかりません'}), 404
    return jsonify(job)

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
            'fetched_at': now_iso()
        })
    except Exception as e:
        return jsonify({
            'success': False,
            'source': 'Google 検索セントラル ブログ',
            'feed_url': SEO_NEWS_PAGE_URL,
            'items': SEO_NEWS_FALLBACK[:limit],
            'error': str(e)[:160],
            'fetched_at': now_iso()
        })


# Title definition (タイトル生成ルール)
@app.route('/api/title-definition', methods=['GET'])
@login_required
def get_title_definition():
    return jsonify({
        'definition': load_title_definition(),
        'defaults': DEFAULT_TITLE_DEFINITION,
    })


@app.route('/api/title-definition', methods=['PUT'])
@login_required
@with_data_lock
def update_title_definition():
    data = request.get_json(silent=True) or {}
    saved = save_title_definition(data)
    return jsonify({'success': True, 'definition': saved})


@app.route('/api/title-definition/reset', methods=['POST'])
@login_required
@with_data_lock
def reset_title_definition():
    saved = save_title_definition(dict(DEFAULT_TITLE_DEFINITION))
    return jsonify({'success': True, 'definition': saved})


# Ad insertion definition (記事種類ごとの広告マーカー挿入ルール)
@app.route('/api/ad-insertion', methods=['GET'])
@login_required
def get_ad_insertion():
    return jsonify({
        'definition': load_ad_insertion_patterns(),
        'defaults': {k: [dict(r) for r in v] for k, v in DEFAULT_CARD_INSERTION_PATTERNS.items()},
        'allowed_positions': list(AD_INSERTION_ALLOWED_POSITIONS),
        'allowed_designs': list(AD_INSERTION_ALLOWED_DESIGNS),
    })


@app.route('/api/ad-insertion', methods=['PUT'])
@login_required
@with_data_lock
def update_ad_insertion():
    data = request.get_json(silent=True) or {}
    saved = save_ad_insertion_patterns(data.get('definition') or data)
    return jsonify({'success': True, 'definition': saved})


@app.route('/api/ad-insertion/reset', methods=['POST'])
@login_required
@with_data_lock
def reset_ad_insertion():
    saved = save_ad_insertion_patterns(
        {k: [dict(r) for r in v] for k, v in DEFAULT_CARD_INSERTION_PATTERNS.items()}
    )
    return jsonify({'success': True, 'definition': saved})


# Quality
@app.route('/api/quality', methods=['GET'])
@login_required
def get_quality():
    return jsonify(load_quality())

@app.route('/api/quality', methods=['POST'])
@login_required
@with_data_lock
def create_quality():
    data = request.get_json(silent=True) or {}
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
@with_data_lock
def update_quality(quality_id):
    data = request.get_json(silent=True) or {}
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
@with_data_lock
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
@with_data_lock
def update_quality_style_references():
    data = request.get_json(silent=True) or {}
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
@with_data_lock
def create_site():
    data = request.get_json(silent=True) or {}
    settings = load_settings()
    sites = settings.get('sites', [])
    site = {
        'id': str(uuid.uuid4()),
        'name': data.get('name', ''),
        'wp_url': data.get('wp_url', '').rstrip('/'),
        'wp_user': data.get('wp_user', ''),
        'wp_password': data.get('wp_password', ''),
        'sheet_url': data.get('sheet_url', ''),
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
@with_data_lock
def update_site(site_id):
    data = request.get_json(silent=True) or {}
    settings = load_settings()
    for s in settings.get('sites', []):
        if s['id'] == site_id:
            s['name'] = data.get('name', s['name'])
            s['wp_url'] = data.get('wp_url', s['wp_url']).rstrip('/')
            s['wp_user'] = data.get('wp_user', s['wp_user'])
            if data.get('wp_password') and not is_masked_value(data['wp_password']):
                s['wp_password'] = data['wp_password']
            if 'sheet_url' in data:
                s['sheet_url'] = str(data['sheet_url']).strip()
            break
    save_settings(settings)
    return jsonify({'success': True})

@app.route('/api/sites/<site_id>', methods=['DELETE'])
@login_required
@with_data_lock
def delete_site(site_id):
    settings = load_settings()
    settings['sites'] = [s for s in settings.get('sites', []) if s['id'] != site_id]
    save_settings(settings)
    return jsonify({'success': True})

@app.route('/api/articles/<article_id>/site', methods=['PUT'])
@login_required
@with_data_lock
def update_article_site(article_id):
    data = request.get_json(silent=True) or {}
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
@with_data_lock
def restore_data_snapshot_api():
    snapshot = request.get_json(silent=True) or {}
    if not isinstance(snapshot, dict):
        return jsonify({'error': 'スナップショット形式が不正です'}), 400
    restore_data_snapshot(snapshot)
    return jsonify({'success': True, 'storage': storage_status()})


# Settings
@app.route('/api/settings', methods=['GET'])
@login_required
def get_settings():
    settings = load_settings()
    # 先頭10文字までを見せ、残りをマスク（識別しやすく・全露出は避ける）。
    # 全体を見たい場合は /api/settings/reveal-secret/<field> を使う。
    safe = {
        'claude_api_key': mask_secret(settings.get('claude_api_key', ''), 10),
        'claude_article_model': settings.get('claude_article_model', 'claude-sonnet-4-6'),
        'default_quality_id': settings.get('default_quality_id', 'default'),
        'article_css': settings.get('article_css', ''),
        'amazon_access_key': mask_secret(settings.get('amazon_access_key', ''), 10),
        'amazon_secret_key': mask_secret(settings.get('amazon_secret_key', ''), 10),
        'amazon_partner_tag': settings.get('amazon_partner_tag', ''),
        'rakuten_app_id': mask_secret(settings.get('rakuten_app_id', ''), 10),
        'rakuten_affiliate_id': settings.get('rakuten_affiliate_id', ''),
        'schedule_daily_cap': int(settings.get('schedule_daily_cap') or 20),
    }
    return jsonify(safe)


# 「表示」ボタンで全体を取得できる秘匿フィールドのホワイトリスト
REVEALABLE_SECRETS = ('claude_api_key', 'amazon_access_key', 'amazon_secret_key', 'rakuten_app_id')


@app.route('/api/settings/reveal-secret/<field>', methods=['GET'])
@login_required
def reveal_secret(field):
    """APIキー等の「表示」ボタン用。ログイン中のみ、指定フィールドの実値を返す。"""
    if field not in REVEALABLE_SECRETS:
        return jsonify({'error': 'unknown field'}), 404
    settings = load_settings()
    return jsonify({'field': field, 'value': settings.get(field, '') or ''})


@app.route('/api/sites/<site_id>/reveal-password', methods=['GET'])
@login_required
def reveal_site_password(site_id):
    """サイト編集モーダルの「表示」ボタン用。ログイン中のみ、
    指定サイトの WordPress アプリケーションパスワード実値を返す。"""
    settings = load_settings()
    for s in settings.get('sites', []):
        if s.get('id') == site_id:
            return jsonify({'value': s.get('wp_password', '') or ''})
    return jsonify({'error': 'site not found'}), 404

@app.route('/api/settings', methods=['POST'])
@login_required
@with_data_lock
def update_settings():
    data = request.get_json(silent=True) or {}
    settings = load_settings()
    if 'default_quality_id' in data:
        settings['default_quality_id'] = data['default_quality_id']
    if 'claude_article_model' in data:
        # ホワイトリスト検証
        model_val = str(data.get('claude_article_model') or '').strip()
        if model_val in ('claude-sonnet-4-6', 'claude-opus-4-7'):
            settings['claude_article_model'] = model_val
    if data.get('claude_api_key') and not is_masked_value(data['claude_api_key']):
        settings['claude_api_key'] = data['claude_api_key']
    if 'article_css' in data:
        if looks_like_html(data.get('article_css', '')):
            return jsonify({'success': False, 'error': '記事CSS定義にはHTMLを保存できません。CSSだけを入力してください。'}), 400
        settings['article_css'] = data['article_css']
    for key in ('amazon_access_key', 'amazon_secret_key', 'rakuten_app_id'):
        if data.get(key) and not is_masked_value(data[key]):
            settings[key] = data[key].strip()
        elif data.get(key) == '':
            settings[key] = ''
    for key in ('amazon_partner_tag', 'rakuten_affiliate_id'):
        if key in data:
            settings[key] = str(data.get(key) or '').strip()
    if 'schedule_daily_cap' in data:
        settings['schedule_daily_cap'] = clamp_int(data['schedule_daily_cap'], 20, 1, 200)
    # card_insertion_mode はUIから廃止。マーカー挿入固定なので保存しない
    save_settings(settings)
    return jsonify({'success': True})


# ---- KW計画 ----

def _kw_plan_call_claude(api_key, prompt, max_tokens=4000):
    """Claude を同期呼び出しして JSON テキストを返す。失敗時は例外。"""
    client = anthropic.Anthropic(api_key=api_key)
    resp = client.messages.create(
        model='claude-haiku-4-5-20251001',
        max_tokens=max_tokens,
        messages=[{'role': 'user', 'content': prompt}],
    )
    raw = resp.content[0].text.strip()
    m = re.search(r'\{.*\}', raw, re.DOTALL)
    if not m:
        raise ValueError('AIの出力をJSONとしてパースできませんでした')
    return json.loads(m.group())


@app.route('/api/kw-plan/slugs', methods=['POST'])
@login_required
def kw_plan_generate_slugs():
    """カテゴリー名リストに対してSEO英語スラッグをClaudeで生成する。"""
    data = request.get_json(silent=True) or {}
    names = [str(n).strip() for n in (data.get('names') or []) if str(n).strip()]
    if not names:
        return jsonify({'error': 'カテゴリー名を入力してください'}), 400
    if len(names) > 50:
        return jsonify({'error': 'カテゴリーは50件以下にしてください'}), 400

    settings = load_settings()
    api_key = settings.get('claude_api_key')
    if not api_key:
        return jsonify({'error': 'Claude APIキーが必要です'}), 400

    names_text = '\n'.join(f'- {n}' for n in names)
    prompt = f"""以下のカテゴリー名それぞれに対して、SEOに適した英語スラッグを生成してください。

ルール:
- 英語のみ・小文字・ハイフン区切り（kebab-case）
- 2〜4単語、最大30文字以内
- 直訳のローマ字化は絶対禁止（例: ベビー・キッズ → baby-kids ○ / bebi-kizzu ✕）
- 検索されやすいSEOフレンドリーな英語に意訳する

カテゴリー名:
{names_text}

出力形式（JSONのみ・説明文・コードフェンス不要）:
[
  {{"name": "元のカテゴリー名", "slug": "english-slug"}},
  ...
]"""

    try:
        client = anthropic.Anthropic(api_key=api_key)
        message = client.messages.create(
            model='claude-haiku-4-5-20251001',
            max_tokens=600,
            messages=[{'role': 'user', 'content': prompt}]
        )
        text = anthropic_message_text(message)
        raw = re.sub(r'^\s*```(?:json)?\s*', '', str(text or '').strip(), flags=re.I)
        raw = re.sub(r'\s*```\s*$', '', raw)
        try:
            items = json.loads(raw)
        except Exception:
            start = raw.find('[')
            end = raw.rfind(']')
            items = json.loads(raw[start:end + 1]) if start >= 0 and end > start else []

        slug_map = {}
        for item in (items if isinstance(items, list) else []):
            if not isinstance(item, dict):
                continue
            name = str(item.get('name') or '').strip()
            slug = re.sub(r'[^a-z0-9-]', '', str(item.get('slug') or '').lower())
            slug = re.sub(r'-+', '-', slug).strip('-')
            if name and slug:
                slug_map[name] = slug

        result = [{'name': n, 'slug': slug_map.get(n) or f'cat-{i + 1}'} for i, n in enumerate(names)]
        return jsonify({'slugs': result})
    except Exception as e:
        app.logger.error('kw_plan_generate_slugs error: %s', e)
        return jsonify({'error': str(e)}), 500


@app.route('/api/kw-plan/categorize', methods=['POST'])
@login_required
def kw_plan_categorize():
    """キーワードリストを ≤10 カテゴリーに分類して返す。"""
    data = request.get_json(silent=True) or {}
    keywords = [str(k).strip() for k in (data.get('keywords') or []) if str(k).strip()]
    max_cat = min(10, max(1, int(data.get('max_categories') or 10)))
    if not keywords:
        return jsonify({'error': 'キーワードが空です'}), 400
    if len(keywords) > 400:
        return jsonify({'error': 'キーワードは400件以下にしてください'}), 400
    settings = load_settings()
    api_key = settings.get('claude_api_key') or ''
    if not api_key:
        return jsonify({'error': 'Claude APIキーが未設定です'}), 400

    prompt = (
        f"以下のキーワードリストを、{max_cat}個以下の包括的なカテゴリーに分類してください。\n"
        "カテゴリーは広すぎず狭すぎず、ECサイトの商品カテゴリーのような粒度が理想です（例:「防音・吸音グッズ」「ペット用安全グッズ」）。\n"
        "各キーワードは必ずいずれかのカテゴリーに含めてください。\n\n"
        "キーワード:\n" + '\n'.join(keywords) + "\n\n"
        "以下のJSON形式のみで出力してください（前置き・説明不要）:\n"
        '{"categories": [{"name": "カテゴリー名", "keywords": ["kw1", "kw2", ...]}]}'
    )
    try:
        result = _kw_plan_call_claude(api_key, prompt, max_tokens=4000)
        return jsonify(result)
    except Exception as e:
        return jsonify({'error': str(e)[:300]}), 500


@app.route('/api/kw-plan/titles', methods=['POST'])
@login_required
def kw_plan_titles():
    """キーワード（≤30件）＋カテゴリーからタイトル案を生成して返す。"""
    data = request.get_json(silent=True) or {}
    keywords = [str(k).strip() for k in (data.get('keywords') or []) if str(k).strip()]
    categories = [c for c in (data.get('categories') or []) if c.get('name')]
    if not keywords:
        return jsonify({'error': 'キーワードが空です'}), 400
    if not categories:
        return jsonify({'error': 'カテゴリーが空です'}), 400
    if len(keywords) > 50:
        return jsonify({'error': '1回のリクエストは50件以下にしてください'}), 400
    settings = load_settings()
    api_key = settings.get('claude_api_key') or ''
    if not api_key:
        return jsonify({'error': 'Claude APIキーが未設定です'}), 400

    cat_lines = '\n'.join(f'- {c["name"]}' for c in categories)
    kw_lines = '\n'.join(keywords)
    prompt = (
        "以下のキーワードそれぞれに対して、アフィリエイトSEO記事のタイトル案を作成してください。\n\n"
        f"カテゴリー一覧:\n{cat_lines}\n\n"
        "タイトルルール:\n"
        "- 35文字以内（厳守）\n"
        "- 禁止: 完全ガイド・決定版・神・No.1・絶対・必見\n"
        "- ranking記事: タイトルに「おすすめ」+「○選」を含める（デフォルト5選）\n"
        "- 各キーワードを上記カテゴリーの中から最も適切なものに1つ振り分ける\n"
        "- article_type は ranking / brand / column のいずれか\n\n"
        f"キーワード:\n{kw_lines}\n\n"
        "以下のJSON形式のみで出力してください（前置き・説明不要）:\n"
        '{"ideas": [{"keyword": "...", "title": "...", "category": "カテゴリー名", "article_type": "ranking"}]}'
    )
    try:
        result = _kw_plan_call_claude(api_key, prompt, max_tokens=8000)
        return jsonify(result)
    except Exception as e:
        return jsonify({'error': str(e)[:300]}), 500


@app.route('/api/products/search', methods=['POST'])
@login_required
def search_products():
    """商品検索（楽天 + Amazon）テスト用エンドポイント。プロバイダ別に結果を返す。"""
    data = request.get_json(silent=True) or {}
    query = str(data.get('query') or '').strip()
    if not query:
        return jsonify({'error': '検索キーワードを入力してください'}), 400
    limit = data.get('limit') or 10
    provider = (data.get('provider') or 'both').lower()
    settings = load_settings()
    result = {'success': True, 'query': query, 'rakuten': [], 'amazon': [], 'errors': {}}
    if provider in ('rakuten', 'both'):
        rakuten_app_id = settings.get('rakuten_app_id') or ''
        if rakuten_app_id:
            try:
                result['rakuten'] = rakuten_search(query, rakuten_app_id, settings.get('rakuten_affiliate_id') or '', limit=limit)
            except Exception as e:
                result['errors']['rakuten'] = str(e)[:200]
        elif provider == 'rakuten':
            return jsonify({'error': '楽天アプリケーションIDが未設定です'}), 400
    if provider in ('amazon', 'both'):
        amazon_access_key = settings.get('amazon_access_key') or ''
        amazon_secret_key = settings.get('amazon_secret_key') or ''
        amazon_partner_tag = settings.get('amazon_partner_tag') or ''
        if amazon_access_key and amazon_secret_key and amazon_partner_tag:
            try:
                result['amazon'] = amazon_search(query, amazon_access_key, amazon_secret_key, amazon_partner_tag, limit=min(10, limit))
            except Exception as e:
                result['errors']['amazon'] = str(e)[:200]
        elif provider == 'amazon':
            return jsonify({'error': 'Amazon PA-API の設定が不完全です'}), 400
    return jsonify(result)


# --- Startup hooks ---
# モジュールロード時に1度だけ実行される。
# Render の場合 gunicorn worker が起動した時にここが走るので、
# 前回の dyno で残った孤児バッチがあれば自動的にレジュームして処理を継続する。
# （これでデプロイ・dyno再起動でもバッチが完走する）
resume_orphan_batches_on_startup()


if __name__ == '__main__':
    port = int(os.environ.get('PORT', 5000))
    app.run(host='0.0.0.0', port=port, debug=os.environ.get('FLASK_DEBUG', 'false').lower() == 'true')
