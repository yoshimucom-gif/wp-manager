import os
import json
import uuid
import threading
from datetime import datetime
from pathlib import Path
from functools import wraps
from html import escape
from html.parser import HTMLParser
from urllib.parse import quote_plus

from flask import Flask, render_template, request, jsonify, session, redirect, url_for, Response, stream_with_context
import anthropic
import openpyxl
import requests
from requests_aws4auth import AWS4Auth

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
        "rakuten_application_id": "",
        "rakuten_affiliate_id": "",
        "rakuten_asp_enabled": False,
        "rakuten_asp_name": "",
        "rakuten_asp_link_template": "",
        "rakuten_asp_link_text": "楽天市場で詳細を見る",
        "rakuten_asp_prompt": "",
        "article_css": "",
    })

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

def fetch_url_text(url, max_chars=4000):
    resp = requests.get(url, timeout=10, headers={'User-Agent': 'Mozilla/5.0'})
    resp.raise_for_status()
    p = _TextExtractor()
    p.feed(resp.text)
    return p.text()[:max_chars]


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

    auth = AWS4Auth(access_key, secret_key, 'us-east-1', 'ProductAdvertisingAPI')
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


def build_rinker_html(amazon_p=None, rakuten_p=None):
    primary = amazon_p or rakuten_p
    if not primary:
        return ''
    title = primary.get('title', '')
    img = primary.get('image', '')
    html = (
        '<div style="border:1px solid #e8e8e8;border-radius:8px;padding:16px 20px;margin:24px 0;'
        'background:#fff;box-shadow:0 1px 4px rgba(0,0,0,0.06)">'
        '<div style="display:flex;gap:16px;align-items:flex-start">'
    )
    if img:
        html += (
            f'<a href="{primary["url"]}" target="_blank" rel="nofollow sponsored" style="flex-shrink:0">'
            f'<img src="{img}" alt="" style="width:110px;height:110px;object-fit:contain"></a>'
        )
    html += f'<div style="flex:1;min-width:0"><p style="margin:0 0 10px;font-weight:bold;font-size:14px;line-height:1.5">{title}</p>'
    prices = []
    if amazon_p and amazon_p.get('price'):
        prices.append(f'Amazon: <strong style="color:#B12704">{amazon_p["price"]}</strong>')
    if rakuten_p and rakuten_p.get('price'):
        prices.append(f'楽天: <strong style="color:#bf0000">{rakuten_p["price"]}</strong>')
    if prices:
        html += f'<p style="margin:0 0 12px;font-size:12px;color:#666">{" &nbsp;|&nbsp; ".join(prices)}</p>'
    html += '<div style="display:flex;gap:8px;flex-wrap:wrap">'
    if amazon_p:
        html += (
            f'<a href="{amazon_p["url"]}" target="_blank" rel="nofollow sponsored" '
            f'style="display:inline-block;background:#ff9900;color:#111;padding:8px 18px;'
            f'text-decoration:none;border-radius:4px;font-weight:bold;font-size:13px;white-space:nowrap">'
            f'Amazonで見る</a>'
        )
    if rakuten_p:
        html += (
            f'<a href="{rakuten_p["url"]}" target="_blank" rel="nofollow sponsored" '
            f'style="display:inline-block;background:#bf0000;color:#fff;padding:8px 18px;'
            f'text-decoration:none;border-radius:4px;font-weight:bold;font-size:13px;white-space:nowrap">'
            f'楽天市場で見る</a>'
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
    reference_text = ''
    if quality and quality.get('reference_url'):
        try:
            reference_text = fetch_url_text(quality['reference_url'])
        except Exception:
            pass
    include_amazon = data.get('include_amazon', False)
    include_rakuten = data.get('include_rakuten', False)
    decoration_id = data.get('decoration_id')
    decoration = next((d for d in load_decorations() if d['id'] == decoration_id), None) if decoration_id else None
    rakuten_asp_instruction = build_rakuten_asp_instruction(article, settings)

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

    rakuten_products = []
    if include_rakuten and article.get('keywords'):
        ra_id = settings.get('rakuten_application_id', '')
        ra_aff = settings.get('rakuten_affiliate_id', '')
        if ra_id:
            try:
                rakuten_products = rakuten_search(article['keywords'], ra_id, ra_aff, item_count=3)
            except Exception:
                pass

    product_blocks = []
    if amazon_products or rakuten_products:
        for i in range(max(len(amazon_products), len(rakuten_products))):
            a_p = amazon_products[i] if i < len(amazon_products) else None
            r_p = rakuten_products[i] if i < len(rakuten_products) else None
            product_blocks.append(build_rinker_html(a_p, r_p))

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

            if reference_text:
                prompt += f'\n\n以下の参考記事の内容・構成・論点を参考にして執筆してください（コピーは不可）：\n\n{reference_text}'

            if decoration and decoration.get('sample_html'):
                prompt += f'\n\n以下のサンプル記事のHTML構造・装飾スタイルを踏襲して記事を作成してください。同じクラス名・ボックスデザイン・見出し構造・装飾パターンを使用してください：\n\n{decoration["sample_html"][:4000]}'

            if rakuten_asp_instruction:
                prompt += rakuten_asp_instruction

            if product_blocks:
                prompt += '\n\n以下の商品カード（HTML）を記事の適切な箇所に自然に組み込んでください。HTMLはそのまま使用してください：\n'
                for block in product_blocks:
                    prompt += f'\n{block}\n'

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
    reference_text = ''
    if quality and quality.get('reference_url'):
        try:
            reference_text = fetch_url_text(quality['reference_url'])
        except Exception:
            pass
    include_amazon = data.get('include_amazon', False)
    include_rakuten = data.get('include_rakuten', False)
    decoration_id = data.get('decoration_id')
    decoration = next((d for d in load_decorations() if d['id'] == decoration_id), None) if decoration_id else None
    amazon_credentials = (
        settings.get('amazon_access_key', ''),
        settings.get('amazon_secret_key', ''),
        settings.get('amazon_partner_tag', ''),
    ) if include_amazon else None
    rakuten_credentials = (
        settings.get('rakuten_application_id', ''),
        settings.get('rakuten_affiliate_id', ''),
    ) if include_rakuten else None

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

                if reference_text:
                    prompt += f'\n\n以下の参考記事の内容・構成・論点を参考にして執筆してください（コピーは不可）：\n\n{reference_text}'

                if decoration and decoration.get('sample_html'):
                    prompt += f'\n\n以下のサンプル記事のHTML構造・装飾スタイルを踏襲して記事を作成してください。同じクラス名・ボックスデザイン・見出し構造・装飾パターンを使用してください：\n\n{decoration["sample_html"][:4000]}'

                rakuten_asp_instruction = build_rakuten_asp_instruction(article, settings)
                if rakuten_asp_instruction:
                    prompt += rakuten_asp_instruction

                amazon_products = []
                if amazon_credentials and all(amazon_credentials) and article.get('keywords'):
                    try:
                        amazon_products = amazon_search(article['keywords'], *amazon_credentials, item_count=3)
                    except Exception:
                        pass
                rakuten_products = []
                if rakuten_credentials and rakuten_credentials[0] and article.get('keywords'):
                    try:
                        rakuten_products = rakuten_search(article['keywords'], *rakuten_credentials, item_count=3)
                    except Exception:
                        pass
                if amazon_products or rakuten_products:
                    product_blocks = []
                    for i in range(max(len(amazon_products), len(rakuten_products))):
                        a_p = amazon_products[i] if i < len(amazon_products) else None
                        r_p = rakuten_products[i] if i < len(rakuten_products) else None
                        product_blocks.append(build_rinker_html(a_p, r_p))
                    prompt += '\n\n以下の商品カード（HTML）を記事の適切な箇所に自然に組み込んでください。HTMLはそのまま使用してください：\n'
                    for block in product_blocks:
                        prompt += f'\n{block}\n'

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
        'reference_url': data.get('reference_url', ''),
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


@app.route('/api/rakuten/search', methods=['POST'])
@login_required
def api_rakuten_search():
    data = request.json or {}
    keywords = data.get('keywords', '')
    if not keywords:
        return jsonify({'error': 'キーワードが必要です'}), 400
    settings = load_settings()
    app_id = settings.get('rakuten_application_id', '')
    aff_id = settings.get('rakuten_affiliate_id', '')
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
    def mask(v):
        return v[:4] + '••••••••' if len(v) > 4 else v
    safe = {
        'claude_api_key': mask(settings.get('claude_api_key', '')),
        'default_quality_id': settings.get('default_quality_id', 'default'),
        'amazon_access_key': mask(settings.get('amazon_access_key', '')),
        'amazon_secret_key': '••••••••' if settings.get('amazon_secret_key') else '',
        'amazon_partner_tag': settings.get('amazon_partner_tag', ''),
        'rakuten_application_id': mask(settings.get('rakuten_application_id', '')),
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
    if data.get('claude_api_key') and '••••••••' not in data['claude_api_key']:
        settings['claude_api_key'] = data['claude_api_key']
    if data.get('amazon_access_key') and '••••••••' not in data['amazon_access_key']:
        settings['amazon_access_key'] = data['amazon_access_key']
    if data.get('amazon_secret_key') and '••••••••' not in data['amazon_secret_key']:
        settings['amazon_secret_key'] = data['amazon_secret_key']
    if 'amazon_partner_tag' in data:
        settings['amazon_partner_tag'] = data['amazon_partner_tag']
    if data.get('rakuten_application_id') and '••••••••' not in data['rakuten_application_id']:
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
        settings['article_css'] = data['article_css']
    save_settings(settings)
    return jsonify({'success': True})


if __name__ == '__main__':
    port = int(os.environ.get('PORT', 5000))
    app.run(host='0.0.0.0', port=port, debug=os.environ.get('FLASK_DEBUG', 'false').lower() == 'true')
