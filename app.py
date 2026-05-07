import os
import json
import uuid
import threading
import re
import csv
import io
from datetime import datetime
from pathlib import Path
from functools import wraps
from html import escape, unescape
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
REWRITE_FILE = DATA_DIR / 'rewrite_items.json'
AD_DEFINITIONS_FILE = DATA_DIR / 'ad_definitions.json'


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

def load_rewrites():
    return load_json(REWRITE_FILE, [])

def save_rewrites(items):
    save_json(REWRITE_FILE, items)

def load_ad_definitions():
    return load_json(AD_DEFINITIONS_FILE, [])

def save_ad_definitions(items):
    save_json(AD_DEFINITIONS_FILE, items)

def load_quality():
    return load_json(QUALITY_FILE, [
        {
            "id": "default",
            "name": "標準品質",
            "prompt": "SEOに最適化された、読みやすく情報量の多い記事を書いてください。見出しを適切に使い、具体例を含めてください。",
            "target_chars": "",
            "tone": "ですます調",
            "extra_rules": "",
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


def html_to_text(html):
    parser = _TextExtractor()
    parser.feed(html or '')
    return parser.text()


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


def score_article_content(title, content, keywords=''):
    text = html_to_text(content)
    compact_text = re.sub(r'\s+', '', text)
    char_count = len(compact_text)
    html = content or ''
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
        },
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
    return item


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


def build_article_type_prompt(article_type):
    prompts = {
        'ranking': """記事種類: ランキング記事
- おすすめ記事・比較記事を統合した構成にする
- 読者が商品やサービスを選びやすいよう、選定基準、比較軸、ランキング理由を明確にする
- 可能であれば比較表、ランキング枠、メリット・デメリット、選び方を入れる""",
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
- <article>タグ、```、解説文、前置きは出力しない"""

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
        prompt += f"""

装飾ルール:
以下のサンプル記事のHTML構造・CSSクラス・装飾パターンを参考にしてください。同じクラス名やボックス構造を優先して使ってください。

{decoration['sample_html'][:5000]}"""
    elif settings.get('article_css'):
        prompt += f"""

サイト共通CSS:
以下のCSSに合うHTML構造とクラス設計を意識してください。

{settings.get('article_css', '')[:3000]}"""

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
            for key in [
                'title', 'keywords', 'content', 'article_type', 'ad_keywords',
                'category', 'priority', 'memo', 'schedule_date', 'quality_id',
                'decoration_id', 'ad_definition_id'
            ]:
                if key in data:
                    a[key] = data[key]
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

    quality_list = load_quality()
    quality = next((q for q in quality_list if q['id'] == quality_id), None)
    if not quality:
        quality = next((q for q in quality_list if q.get('is_default')), quality_list[0] if quality_list else None)

    article_work = dict(article)
    for key in ('title', 'keywords', 'category', 'ad_keywords', 'site_id'):
        if key in data:
            article_work[key] = data.get(key) or ''
    article_type = normalize_article_type(data.get('article_type') or article_work.get('article_type'), 'ranking')
    article_work['article_type'] = article_type
    quality_prompt = build_quality_prompt(quality)
    article_type_prompt = build_article_type_prompt(article_type)
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
    rakuten_asp_instruction = build_rakuten_asp_instruction(article_work, settings)
    ad_definition = select_ad_definition({**data, 'article_type': article_type}, article_work)
    product_blocks, ad_instruction = build_ad_product_blocks(
        article_work, settings, ad_definition, include_amazon=include_amazon, include_rakuten=include_rakuten
    )

    def generate():
        client = anthropic.Anthropic(api_key=api_key)
        full_content = ''
        try:
            prompt = f"""以下の情報をもとに、WordPressに投稿する記事を書いてください。

タイトル: {article_work.get('title', '')}
キーワード: {article_work.get('keywords', '')}
カテゴリー: {article_work.get('category', '')}

品質要件:
{quality_prompt}

{article_type_prompt}

記事はHTML形式で書いてください。<article>タグは不要です。h2, h3, p, ul, li等のHTML要素を使用してください。"""

            if reference_text:
                prompt += f'\n\n以下の参考記事の内容・構成・論点を参考にして執筆してください（コピーは不可）：\n\n{reference_text}'

            if decoration and decoration.get('sample_html'):
                prompt += f'\n\n以下のサンプル記事のHTML構造・装飾スタイルを踏襲して記事を作成してください。同じクラス名・ボックスデザイン・見出し構造・装飾パターンを使用してください：\n\n{decoration["sample_html"][:4000]}'

            if rakuten_asp_instruction:
                prompt += rakuten_asp_instruction

            if ad_instruction:
                prompt += ad_instruction

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
                    a['title'] = article_work.get('title', a.get('title', ''))
                    a['keywords'] = article_work.get('keywords', a.get('keywords', ''))
                    a['category'] = article_work.get('category', a.get('category', ''))
                    a['ad_keywords'] = article_work.get('ad_keywords', a.get('ad_keywords', ''))
                    a['site_id'] = article_work.get('site_id') or a.get('site_id')
                    a['quality_id'] = quality_id
                    a['article_type'] = article_type
                    a['decoration_id'] = decoration_id or a.get('decoration_id')
                    if ad_definition:
                        a['ad_definition_id'] = ad_definition.get('id')
                    a['generated_at'] = datetime.now().isoformat()
                    apply_score_fields(a)
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
    batch_article_type = normalize_article_type(data.get('article_type'), 'ranking')
    quality_prompt = build_quality_prompt(quality)
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

    def run_batch():
        client = anthropic.Anthropic(api_key=api_key)
        for article in pending:
            try:
                article_type = normalize_article_type(article.get('article_type') or batch_article_type, batch_article_type)
                article_type_prompt = build_article_type_prompt(article_type)
                prompt = f"""以下の情報をもとに、WordPressに投稿する記事を書いてください。

タイトル: {article['title']}
キーワード: {article['keywords']}
カテゴリー: {article.get('category', '')}

品質要件:
{quality_prompt}

{article_type_prompt}

記事はHTML形式で書いてください。<article>タグは不要です。h2, h3, p, ul, li等のHTML要素を使用してください。"""

                if reference_text:
                    prompt += f'\n\n以下の参考記事の内容・構成・論点を参考にして執筆してください（コピーは不可）：\n\n{reference_text}'

                if decoration and decoration.get('sample_html'):
                    prompt += f'\n\n以下のサンプル記事のHTML構造・装飾スタイルを踏襲して記事を作成してください。同じクラス名・ボックスデザイン・見出し構造・装飾パターンを使用してください：\n\n{decoration["sample_html"][:4000]}'

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
                        a['article_type'] = article_type
                        a['decoration_id'] = decoration_id or a.get('decoration_id')
                        if ad_definition:
                            a['ad_definition_id'] = ad_definition.get('id')
                        a['generated_at'] = datetime.now().isoformat()
                        apply_score_fields(a)
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
    post_payload = {'title': article['title'], 'content': content, 'status': post_status}
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
        post_payload = {'title': article['title'], 'content': content, 'status': post_status}
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
        resp = requests.post(
            f"{site['wp_url'].rstrip('/')}/wp-json/wp/v2/posts/{item['wp_post_id']}",
            auth=(site['wp_user'], site['wp_password']),
            json={'content': content},
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
