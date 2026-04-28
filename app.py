import os
import json
import uuid
import hmac
import hashlib
import threading
from datetime import datetime, timezone
from pathlib import Path
from functools import wraps

from flask import Flask, render_template, request, jsonify, session, redirect, url_for, Response, stream_with_context
import anthropic
import openpyxl
import requests

app = Flask(__name__)
app.secret_key = os.environ.get('SECRET_KEY', 'dev-secret-key-change-in-production')

DATA_DIR = Path(os.environ.get('DATA_DIR', 'data'))
DATA_DIR.mkdir(exist_ok=True)

ARTICLES_FILE = DATA_DIR / 'articles.json'
QUALITY_FILE = DATA_DIR / 'quality.json'
DECORATIONS_FILE = DATA_DIR / 'decorations.json'
SETTINGS_FILE = DATA_DIR / 'settings.json'


def load_json(path, default):
    if path.exists():
        with open(path, 'r', encoding='utf-8') as f:
            return json.load(f)
    return default

def save_json(path, data):
    with open(path, 'w', encoding='utf-8') as f:
        json.dump(data, f, ensure_ascii=False, indent=2)

def load_articles():
    return load_json(ARTICLES_FILE, [])

def save_articles(articles):
    save_json(ARTICLES_FILE, articles)

def load_quality():
    return load_json(QUALITY_FILE, [
        {
            "id": "default",
            "name": "標準品質",
            "prompt": "SEOに最適化された、読みやすく情報量の多い記事を書いてください。見出しを適切に使い、具体例を含めてください。",
            "is_default": True
        }
    ])

def save_quality(quality):
    save_json(QUALITY_FILE, quality)

def load_decorations():
    return load_json(DECORATIONS_FILE, [])

def save_decorations(decorations):
    save_json(DECORATIONS_FILE, decorations)

def load_settings():
    return load_json(SETTINGS_FILE, {
        "sites": [],
        "claude_api_key": "",
        "default_quality_id": "default",
        "amazon_access_key": "",
        "amazon_secret_key": "",
        "amazon_partner_tag": "",
        "article_css": "",
    })

def amazon_search(keywords, access_key, secret_key, partner_tag, item_count=3):
    host = 'webservices.amazon.co.jp'
    path = '/paapi5/searchitems'
    target = 'com.amazon.paapi5.v1.ProductAdvertisingAPIv1.SearchItems'
    region = 'us-east-1'
    service = 'ProductAdvertisingAPI'
    content_type = 'application/json; charset=utf-8'

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

    t = datetime.now(timezone.utc)
    amzdate = t.strftime('%Y%m%dT%H%M%SZ')
    datestamp = t.strftime('%Y%m%d')

    payload_hash = hashlib.sha256(payload.encode('utf-8')).hexdigest()
    canonical_headers = (
        f'content-encoding:amz-1.0\n'
        f'content-type:{content_type}\n'
        f'host:{host}\n'
        f'x-amz-date:{amzdate}\n'
        f'x-amz-target:{target}\n'
    )
    signed_headers = 'content-encoding;content-type;host;x-amz-date;x-amz-target'
    canonical_request = '\n'.join(['POST', path, '', canonical_headers, signed_headers, payload_hash])

    credential_scope = f'{datestamp}/{region}/{service}/aws4_request'
    string_to_sign = '\n'.join([
        'AWS4-HMAC-SHA256', amzdate, credential_scope,
        hashlib.sha256(canonical_request.encode('utf-8')).hexdigest()
    ])

    def _sign(key, data):
        k = key if isinstance(key, bytes) else key.encode('utf-8')
        return hmac.new(k, data.encode('utf-8'), hashlib.sha256).digest()

    k = _sign('AWS4' + secret_key, datestamp)
    k = _sign(k, region)
    k = _sign(k, service)
    k = _sign(k, 'aws4_request')
    signature = hmac.new(k, string_to_sign.encode('utf-8'), hashlib.sha256).hexdigest()

    headers = {
        'content-encoding': 'amz-1.0',
        'content-type': content_type,
        'host': host,
        'x-amz-date': amzdate,
        'x-amz-target': target,
        'Authorization': (
            f'AWS4-HMAC-SHA256 Credential={access_key}/{credential_scope}, '
            f'SignedHeaders={signed_headers}, Signature={signature}'
        ),
    }

    resp = requests.post(f'https://{host}{path}', headers=headers, data=payload, timeout=10)
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


def build_amazon_product_html(p):
    stars = ''
    if p.get('rating'):
        n = round(float(p['rating']))
        stars = '★' * n + '☆' * (5 - n)
        if p.get('review_count'):
            stars += f" ({p['review_count']}件)"
    html = (
        '<div style="border:1px solid #e0e0e0;border-radius:8px;padding:16px;margin:20px 0;'
        'display:flex;gap:16px;align-items:flex-start;background:#fafafa">'
    )
    if p.get('image'):
        html += f'<img src="{p["image"]}" alt="" style="width:120px;height:120px;object-fit:contain;flex-shrink:0">'
    html += '<div style="flex:1">'
    html += f'<p style="margin:0 0 8px;font-weight:bold">{p["title"]}</p>'
    if p.get('price'):
        html += f'<p style="margin:4px 0;color:#B12704;font-weight:bold">{p["price"]}</p>'
    if stars:
        html += f'<p style="margin:4px 0;color:#e47911">{stars}</p>'
    html += (
        f'<a href="{p["url"]}" target="_blank" rel="nofollow sponsored" '
        f'style="display:inline-block;margin-top:8px;background:#ff9900;color:#000;'
        f'padding:8px 16px;text-decoration:none;border-radius:4px;font-weight:bold">'
        f'Amazonで見る →</a>'
    )
    html += '</div></div>'
    return html


def get_site_credentials(article, settings):
    site_id = article.get('site_id')
    if site_id:
        for s in settings.get('sites', []):
            if s['id'] == site_id:
                return s['wp_url'].rstrip('/'), s['wp_user'], s['wp_password']
    return '', '', ''

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
def index():
    if not session.get('authenticated'):
        return redirect(url_for('login_page'))
    return render_template('index.html')

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
    return jsonify(load_articles())

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
            for key in ['title', 'keywords', 'content']:
                if key in data:
                    a[key] = data[key]
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


# Import
@app.route('/api/import', methods=['POST'])
@login_required
def import_excel():
    if 'file' not in request.files:
        return jsonify({'error': 'ファイルがありません'}), 400
    file = request.files['file']
    if not file.filename.endswith(('.xlsx', '.xls')):
        return jsonify({'error': 'Excelファイル(.xlsx/.xls)を選択してください'}), 400

    wb = openpyxl.load_workbook(file)
    ws = wb.active
    articles = load_articles()
    imported = 0

    site_id = request.form.get('site_id') or None
    for row in ws.iter_rows(min_row=2, values_only=True):
        if not row or not row[0]:
            continue
        articles.append({
            'id': str(uuid.uuid4()),
            'title': str(row[0]).strip(),
            'keywords': str(row[1]).strip() if len(row) > 1 and row[1] else '',
            'status': 'pending',
            'content': '',
            'created_at': datetime.now().isoformat(),
            'quality_id': None,
            'site_id': site_id,
            'wp_post_id': None,
            'wp_url': None,
        })
        imported += 1

    save_articles(articles)
    return jsonify({'success': True, 'imported': imported})


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

    quality_list = load_quality()
    quality = next((q for q in quality_list if q['id'] == quality_id), None)
    if not quality:
        quality = next((q for q in quality_list if q.get('is_default')), quality_list[0] if quality_list else None)

    quality_prompt = quality['prompt'] if quality else ''
    include_amazon = data.get('include_amazon', False)
    decoration_id = data.get('decoration_id')
    decoration = next((d for d in load_decorations() if d['id'] == decoration_id), None) if decoration_id else None

    amazon_products = []
    if include_amazon and article.get('keywords'):
        ak = settings.get('amazon_access_key', '')
        sk = settings.get('amazon_secret_key', '')
        pt = settings.get('amazon_partner_tag', '')
        if all([ak, sk, pt]):
            try:
                amazon_products = amazon_search(article['keywords'], ak, sk, pt, item_count=3)
            except Exception:
                pass

    def generate():
        client = anthropic.Anthropic(api_key=api_key)
        full_content = ''
        try:
            prompt = f"""以下の情報をもとに、WordPressに投稿する記事を書いてください。

タイトル: {article['title']}
キーワード: {article['keywords']}

品質要件:
{quality_prompt}

記事はHTML形式で書いてください。<article>タグは不要です。h2, h3, p, ul, li等のHTML要素を使用してください。"""

            if decoration and decoration.get('sample_html'):
                prompt += f'\n\n以下のサンプル記事のHTML構造・装飾スタイルを踏襲して記事を作成してください。同じクラス名・ボックスデザイン・見出し構造・装飾パターンを使用してください：\n\n{decoration["sample_html"][:4000]}'

            if amazon_products:
                prompt += '\n\n以下のAmazon商品を記事の適切な箇所に自然に組み込んでください。各商品カードのHTMLをそのまま挿入してください：\n'
                for p in amazon_products:
                    prompt += f'\n【{p["title"]}】\n{build_amazon_product_html(p)}\n'

            with client.messages.stream(
                model="claude-sonnet-4-6",
                max_tokens=4096,
                messages=[{"role": "user", "content": prompt}]
            ) as stream:
                for text in stream.text_stream:
                    full_content += text
                    yield f"data: {json.dumps({'text': text})}\n\n"

            current_articles = load_articles()
            for a in current_articles:
                if a['id'] == article_id:
                    a['content'] = full_content
                    a['status'] = 'generated'
                    a['quality_id'] = quality_id
                    a['generated_at'] = datetime.now().isoformat()
                    break
            save_articles(current_articles)
            yield f"data: {json.dumps({'done': True})}\n\n"
        except Exception as e:
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
    article_ids = set(data.get('article_ids', []))
    quality_id = data.get('quality_id')

    articles = load_articles()
    pending = [a for a in articles if a['id'] in article_ids and a['status'] in ('pending', 'error')]

    if not pending:
        return jsonify({'error': '処理対象の記事がありません'}), 400

    settings = load_settings()
    api_key = settings.get('claude_api_key') or os.environ.get('ANTHROPIC_API_KEY', '')

    if not api_key:
        return jsonify({'error': 'Claude APIキーが設定されていません'}), 400

    quality_list = load_quality()
    quality = next((q for q in quality_list if q['id'] == quality_id), None)
    if not quality:
        quality = next((q for q in quality_list if q.get('is_default')), quality_list[0] if quality_list else None)
    quality_prompt = quality['prompt'] if quality else ''
    include_amazon = data.get('include_amazon', False)
    decoration_id = data.get('decoration_id')
    decoration = next((d for d in load_decorations() if d['id'] == decoration_id), None) if decoration_id else None
    amazon_credentials = (
        settings.get('amazon_access_key', ''),
        settings.get('amazon_secret_key', ''),
        settings.get('amazon_partner_tag', ''),
    ) if include_amazon else None

    def run_batch():
        client = anthropic.Anthropic(api_key=api_key)
        for article in pending:
            try:
                prompt = f"""以下の情報をもとに、WordPressに投稿する記事を書いてください。

タイトル: {article['title']}
キーワード: {article['keywords']}

品質要件:
{quality_prompt}

記事はHTML形式で書いてください。<article>タグは不要です。h2, h3, p, ul, li等のHTML要素を使用してください。"""

                if decoration and decoration.get('sample_html'):
                    prompt += f'\n\n以下のサンプル記事のHTML構造・装飾スタイルを踏襲して記事を作成してください。同じクラス名・ボックスデザイン・見出し構造・装飾パターンを使用してください：\n\n{decoration["sample_html"][:4000]}'

                if amazon_credentials and all(amazon_credentials) and article.get('keywords'):
                    try:
                        products = amazon_search(article['keywords'], *amazon_credentials, item_count=3)
                        if products:
                            prompt += '\n\n以下のAmazon商品を記事の適切な箇所に自然に組み込んでください：\n'
                            for p in products:
                                prompt += f'\n【{p["title"]}】\n{build_amazon_product_html(p)}\n'
                    except Exception:
                        pass

                message = client.messages.create(
                    model="claude-sonnet-4-6",
                    max_tokens=4096,
                    messages=[{"role": "user", "content": prompt}]
                )
                content = message.content[0].text

                current_articles = load_articles()
                for a in current_articles:
                    if a['id'] == article['id']:
                        a['content'] = content
                        a['status'] = 'generated'
                        a['quality_id'] = quality_id
                        a['generated_at'] = datetime.now().isoformat()
                        break
                save_articles(current_articles)
            except Exception as e:
                current_articles = load_articles()
                for a in current_articles:
                    if a['id'] == article['id']:
                        a['status'] = 'error'
                        a['error'] = str(e)
                        break
                save_articles(current_articles)

    thread = threading.Thread(target=run_batch, daemon=True)
    thread.start()
    return jsonify({'success': True, 'message': f'{len(pending)}件の記事生成を開始しました'})


# WordPress publish
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
    article_css = settings.get('article_css', '')
    content = article['content']
    if article_css:
        content = f'<style>{article_css}</style>\n' + content

    try:
        response = requests.post(
            f"{wp_url}/wp-json/wp/v2/posts",
            auth=(wp_user, wp_password),
            json={'title': article['title'], 'content': content, 'status': post_status},
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
                break
        save_articles(articles)
        return jsonify({'success': True, 'wp_url': post_data.get('link', ''), 'wp_post_id': post_data['id']})
    except requests.exceptions.RequestException as e:
        return jsonify({'error': f'WordPress投稿エラー: {str(e)}'}), 500


# Batch publish
@app.route('/api/batch-publish', methods=['POST'])
@login_required
def batch_publish():
    data = request.json or {}
    article_ids = set(data.get('article_ids', []))
    post_status = data.get('post_status', 'draft')

    settings = load_settings()
    articles = load_articles()
    targets = [a for a in articles if a['id'] in article_ids and a.get('content')]

    article_css = settings.get('article_css', '')
    results = {'success': 0, 'error': 0, 'errors': []}
    for article in targets:
        wp_url, wp_user, wp_password = get_site_credentials(article, settings)
        if not all([wp_url, wp_user, wp_password]):
            results['error'] += 1
            results['errors'].append({'title': article['title'], 'error': 'サイト未設定'})
            continue
        content = article['content']
        if article_css:
            content = f'<style>{article_css}</style>\n' + content
        try:
            response = requests.post(
                f"{wp_url}/wp-json/wp/v2/posts",
                auth=(wp_user, wp_password),
                json={'title': article['title'], 'content': content, 'status': post_status},
                timeout=30
            )
            response.raise_for_status()
            post_data = response.json()
            for a in articles:
                if a['id'] == article['id']:
                    a['status'] = 'published'
                    a['wp_post_id'] = post_data['id']
                    a['wp_url'] = post_data.get('link', '')
                    a['published_at'] = datetime.now().isoformat()
                    break
            results['success'] += 1
        except Exception as e:
            results['error'] += 1
            results['errors'].append({'title': article['title'], 'error': str(e)})

    save_articles(articles)
    return jsonify(results)


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


# Sites
@app.route('/api/sites', methods=['GET'])
@login_required
def get_sites():
    settings = load_settings()
    safe = []
    for s in settings.get('sites', []):
        sc = dict(s)
        if sc.get('wp_password'):
            sc['wp_password'] = '••••••••'
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
        sc['wp_password'] = '••••••••'
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
            if data.get('wp_password') and data['wp_password'] != '••••••••':
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


# Settings
@app.route('/api/amazon/search', methods=['POST'])
@login_required
def api_amazon_search():
    data = request.json or {}
    keywords = data.get('keywords', '')
    if not keywords:
        return jsonify({'error': 'キーワードが必要です'}), 400
    settings = load_settings()
    access_key = settings.get('amazon_access_key', '')
    secret_key = settings.get('amazon_secret_key', '')
    partner_tag = settings.get('amazon_partner_tag', '')
    if not all([access_key, secret_key, partner_tag]):
        return jsonify({'error': 'Amazon API設定が不完全です'}), 400
    try:
        products = amazon_search(keywords, access_key, secret_key, partner_tag, item_count=data.get('item_count', 3))
        return jsonify(products)
    except Exception as e:
        return jsonify({'error': str(e)}), 500


@app.route('/api/settings', methods=['GET'])
@login_required
def get_settings():
    settings = load_settings()
    def mask(v):
        return v[:4] + '••••••••' if len(v) > 4 else v
    safe = {
        'claude_api_key': mask(settings.get('claude_api_key', '')),
        'default_quality_id': settings.get('default_quality_id', 'default'),
        'amazon_access_key': mask(settings.get('amazon_access_key', '')),
        'amazon_secret_key': '••••••••' if settings.get('amazon_secret_key') else '',
        'amazon_partner_tag': settings.get('amazon_partner_tag', ''),
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
    if data.get('claude_api_key') and '••••••••' not in data['claude_api_key']:
        settings['claude_api_key'] = data['claude_api_key']
    if data.get('amazon_access_key') and '••••••••' not in data['amazon_access_key']:
        settings['amazon_access_key'] = data['amazon_access_key']
    if data.get('amazon_secret_key') and '••••••••' not in data['amazon_secret_key']:
        settings['amazon_secret_key'] = data['amazon_secret_key']
    if 'amazon_partner_tag' in data:
        settings['amazon_partner_tag'] = data['amazon_partner_tag']
    if 'article_css' in data:
        settings['article_css'] = data['article_css']
    save_settings(settings)
    return jsonify({'success': True})


if __name__ == '__main__':
    port = int(os.environ.get('PORT', 5000))
    app.run(host='0.0.0.0', port=port, debug=os.environ.get('FLASK_DEBUG', 'false').lower() == 'true')
