"""wp_manager スモークテスト。

目的: app.py の重要なロジックに最低限の安全網を張る。
      （これまでテストゼロで、毎デプロイがギャンブル状態だった）

実行: pip install -r requirements-dev.txt && pytest

カバー範囲:
  - 純粋ヘルパー関数（slug / 記事種別 / ハッシュ 等）
  - データ整合性ロック _DATA_LOCK が実際に直列化するか
  - save_json / load_json のアトミック往復
  - 広告マーカー生成・サニタイズ
  - Flask ルートの認証ガードと基本疎通
"""
import datetime
import threading
import time

import app  # conftest.py が DATA_DIR を一時ディレクトリへ設定済み


# ─────────────────────────────────────────────
# 純粋ヘルパー関数
# ─────────────────────────────────────────────
def test_now_iso_is_jst_and_parseable():
    s = app.now_iso()
    assert s.endswith('+09:00'), f'JSTオフセットが付いていない: {s}'
    # ISO8601 としてパースできること
    datetime.datetime.fromisoformat(s)


def test_normalize_article_type():
    assert app.normalize_article_type('ランキング記事') == 'ranking'
    assert app.normalize_article_type('商標') == 'brand'
    assert app.normalize_article_type('レビュー') == 'brand'
    assert app.normalize_article_type('コラム') == 'column'
    assert app.normalize_article_type('') == 'ranking'            # 既定値
    assert app.normalize_article_type('謎の値') == 'ranking'       # 不正値→既定
    assert app.normalize_article_type('謎', default='column') == 'column'


def test_clamp_int():
    assert app.clamp_int(5, 3, 1, 10) == 5
    assert app.clamp_int(99, 3, 1, 10) == 10      # 上限クランプ
    assert app.clamp_int(-5, 3, 1, 10) == 1       # 下限クランプ
    assert app.clamp_int('abc', 3, 1, 10) == 3    # 数値化失敗→既定
    assert app.clamp_int(None, 3, 1, 10) == 3


def test_normalize_slug():
    assert app.normalize_slug('  hello world ') == 'hello-world'
    assert app.normalize_slug('/foo/') == 'foo'
    assert app.normalize_slug('a   b') == 'a-b'
    assert app.normalize_slug('a---b') == 'a-b'
    assert app.normalize_slug(None) == ''


def test_auto_slug_from_brand_name():
    # 英字商品名 → ハイフン連結 + -review
    assert app.auto_slug_from_brand_name('Andeor ネックウォーマー') == 'andeor-review'
    assert app.auto_slug_from_brand_name('CHIC DIARY バラクラバ') == 'chic-diary-review'
    # 英数字が全く無い → 空文字（呼び出し側でフォールバック判断）
    assert app.auto_slug_from_brand_name('ネックウォーマー') == ''
    assert app.auto_slug_from_brand_name('') == ''
    assert app.auto_slug_from_brand_name(None) == ''


def test_content_hash_is_stable_and_distinct():
    assert app.content_hash('abc') == app.content_hash('abc')
    assert app.content_hash('abc') != app.content_hash('abd')
    # None と '' は同一視される
    assert app.content_hash(None) == app.content_hash('')


def test_content_similarity():
    assert app.content_similarity('', 'x') == 0.0
    assert app.content_similarity('<p>同じ文章</p>', '<p>同じ文章</p>') == 1.0
    low = app.content_similarity('<p>まったく違う内容A</p>', '<p>無関係なテキストB</p>')
    assert 0.0 <= low < 1.0


def test_split_title_keywords():
    # 改行区切り・空行スキップ
    assert app.split_title_keywords('a\nb\n\nc') == ['a', 'b', 'c']
    # 行頭の箇条書き記号・番号は除去される
    assert app.split_title_keywords('1. foo\n2. bar') == ['foo', 'bar']
    assert app.split_title_keywords('- alpha\n* beta') == ['alpha', 'beta']
    # 重複は除去される
    assert app.split_title_keywords('foo\nfoo') == ['foo']
    assert app.split_title_keywords('') == []


def test_html_to_text_strips_tags():
    text = app.html_to_text('<p>こんにちは</p><p>世界</p>')
    assert 'こんにちは' in text
    assert '世界' in text
    assert '<p>' not in text


# ─────────────────────────────────────────────
# 広告マーカー生成・サニタイズ
# ─────────────────────────────────────────────
def test_build_marker():
    assert app._build_marker('vertical') == '<!--ai-product:vertical-->'
    assert app._build_marker('ranking', 3) == '<!--ai-product:ranking:3-->'
    assert app._build_marker('default') == '<!--ai-product-->'
    assert app._build_marker('') == '<!--ai-product-->'


def test_sanitize_ad_insertion_rules():
    rules = [
        {'position': 'INVALID_POS', 'design': 'vertical'},   # 不正position→除外
        {'position': 'after_first_h2', 'design': 'vertical'},  # 正常
        {'position': 'after_each_h3_rank', 'design': '謎'},    # 不正design→verticalへ
        'not a dict',                                          # 非dict→スキップ
    ]
    clean = app._sanitize_ad_insertion_rules(rules)
    assert len(clean) == 2
    assert clean[0] == {'position': 'after_first_h2', 'design': 'vertical'}
    assert clean[1]['position'] == 'after_each_h3_rank'
    assert clean[1]['design'] == 'vertical'  # 不正designはverticalに矯正
    # リスト以外は空
    assert app._sanitize_ad_insertion_rules('not a list') == []
    assert app._sanitize_ad_insertion_rules(None) == []


def test_sanitize_ad_insertion_rules_count_and_repeat():
    clean = app._sanitize_ad_insertion_rules([
        {'position': 'after_matome_h2', 'design': 'ranking', 'count': 3},
        {'position': 'before_first_h2', 'design': 'vertical', 'repeat': 3},
        {'position': 'top', 'design': 'ranking', 'count': 999},  # 範囲外countは無視
    ])
    assert clean[0].get('count') == 3
    assert clean[1].get('repeat') == 3
    assert 'count' not in clean[2]  # 1〜10外は付与されない


def test_insert_card_markers_embeds_markers():
    html = (
        '<h2>はじめに</h2><p>導入文</p>'
        '<h3>商品A</h3><p>説明A</p>'
        '<h3>商品B</h3><p>説明B</p>'
        '<h2>まとめ</h2><p>結論</p>'
    )
    out, stats = app.insert_card_markers(html, 'ranking')
    assert 'ai-product' in out
    assert stats['marker_count'] >= 1
    # 空入力は素通し
    empty_out, empty_stats = app.insert_card_markers('', 'ranking')
    assert empty_out == ''
    assert empty_stats['marker_count'] == 0


# ─────────────────────────────────────────────
# データ整合性ロック（今回の最重要修正）
# ─────────────────────────────────────────────
def test_data_lock_is_reentrant():
    # RLock なので同一スレッドでの再取得がデッドロックしない
    with app._DATA_LOCK:
        with app._DATA_LOCK:
            assert True


def test_with_data_lock_decorator_passes_through():
    @app.with_data_lock
    def add(a, b):
        return a + b
    assert add(2, 3) == 5


def test_data_lock_serializes_critical_sections():
    """複数スレッドが _DATA_LOCK 下のクリティカルセクションに入っても、
    start→end が割り込まれず必ずペアで並ぶ（＝直列化されている）ことを確認。
    これが壊れているとロスト・アップデートが再発する。"""
    events = []

    def worker(n):
        with app._DATA_LOCK:
            events.append((n, 'start'))
            time.sleep(0.03)
            events.append((n, 'end'))

    threads = [threading.Thread(target=worker, args=(i,)) for i in range(4)]
    for t in threads:
        t.start()
    for t in threads:
        t.join()

    assert len(events) == 8
    for i in range(0, 8, 2):
        start_n, start_kind = events[i]
        end_n, end_kind = events[i + 1]
        assert start_kind == 'start'
        assert end_kind == 'end'
        assert start_n == end_n, '別スレッドに割り込まれた（ロックが効いていない）'


# ─────────────────────────────────────────────
# 永続化レイヤ
# ─────────────────────────────────────────────
def test_save_load_json_roundtrip(tmp_path):
    p = tmp_path / 'sample.json'
    data = {'a': 1, 'b': [1, 2, 3], 'jp': '日本語'}
    app.save_json(p, data)
    assert app.load_json(p, None) == data


def test_save_json_is_atomic_no_tmp_left(tmp_path):
    p = tmp_path / 'sample.json'
    app.save_json(p, {'k': 'v'})
    # tmp ファイルが残っていない（os.replace で確定済み）
    assert not (tmp_path / 'sample.json.tmp').exists()
    assert p.exists()


def test_load_json_returns_default_when_missing(tmp_path):
    missing = tmp_path / 'does-not-exist.json'
    assert app.load_json(missing, []) == []
    assert app.load_json(missing, {'x': 1}) == {'x': 1}


# ─────────────────────────────────────────────
# Flask ルート疎通
# ─────────────────────────────────────────────
def test_unauthenticated_api_is_blocked():
    client = app.app.test_client()
    r = client.get('/api/articles')
    # 未認証は 401(JSON) か 302(リダイレクト)
    assert r.status_code in (401, 302)


def test_login_then_get_articles():
    client = app.app.test_client()
    login = client.post('/login', json={'password': 'testpass'})
    assert login.status_code == 200
    assert login.get_json().get('success') is True

    r = client.get('/api/articles')
    assert r.status_code == 200
    assert isinstance(r.get_json(), list)


def test_login_rejects_wrong_password():
    client = app.app.test_client()
    r = client.post('/login', json={'password': 'wrong-password'})
    assert r.status_code == 401


def test_plugins_page_route_serves():
    client = app.app.test_client()
    client.post('/login', json={'password': 'testpass'})
    r = client.get('/plugins')
    assert r.status_code == 200
