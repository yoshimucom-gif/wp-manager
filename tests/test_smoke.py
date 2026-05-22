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


def test_find_h2_range_includes_gutenberg_wrapper():
    # wp:heading ラッパーごと範囲に含む → マーカーがブロック内側に入らない
    html = '<p>intro</p><!-- wp:heading --><h2>セクション1</h2><!-- /wp:heading --><p>x</p>'
    rng = app._find_first_h2_range(html)
    assert rng is not None
    seg = html[rng[0]:rng[1]]
    assert seg.startswith('<!-- wp:heading')
    assert seg.rstrip().endswith('/wp:heading -->')


def test_find_matome_range_keywords_broadened():
    for kw in ['まとめ', 'おわりに', '最後に', '結論', '総括', 'ベストバイ']:
        assert app._find_matome_h2_range(f'<h2>{kw}</h2><p>x</p>') is not None, kw
    # H2 が1つも無ければ None
    assert app._find_matome_h2_range('<p>本文だけ</p>') is None


def test_matome_range_falls_back_to_last_h2():
    # まとめがSEO別名でキーワードに一致しなくても「最後のH2」で確実に解決する
    html = '<p>x</p><h2>選び方</h2><p>y</p><h2>用途別ベストの考え方</h2><p>z</p>'
    rng = app._find_matome_h2_range(html)
    assert rng is not None
    assert '用途別ベスト' in html[rng[0]:rng[1]]


def test_strip_summary_keeps_comparison_table():
    # 比較表セクションは削除しない。早見表セクションだけ削除する。
    html = ('<h2>主要モデル比較</h2><table><tr><td>A</td></tr></table>'
            '<h2>おすすめ早見表</h2><table><tr><td>B</td></tr></table>'
            '<h2>本編</h2><p>x</p>')
    out = app.strip_summary_table_sections(html)
    assert '主要モデル比較' in out      # 比較表セクションは残る（コンテンツ保護）
    assert '<table' in out             # テーブル自体も残る
    assert 'おすすめ早見表' not in out  # 早見表セクションは消える


def test_after_matome_marker_placed_with_seo_heading():
    # まとめ見出しがSEO別名でも after_matome_h2 マーカーが必ず配置される
    # （広告挿入定義が「効かない」を防ぐ）
    html = '<p>導入</p><h2>選び方</h2><p>x</p><h2>用途別ベストバイ総括</h2><p>結び</p>'
    out, _ = app.insert_card_markers(
        html, 'column',
        patterns={'column': [{'position': 'after_matome_h2', 'design': 'ranking', 'count': 3}]})
    assert 'ai-product' in out


def test_insert_card_markers_outside_heading_blocks():
    # 広告マーカーが Gutenberg heading ブロックの内側に混入しないこと
    html = (
        '<p>導入文</p>'
        '<!-- wp:heading --><h2>選び方</h2><!-- /wp:heading --><p>本文</p>'
        '<!-- wp:heading --><h2>まとめ</h2><!-- /wp:heading --><p>結び</p>'
    )
    out, _ = app.insert_card_markers(html, 'column')
    assert 'ai-product' in out
    import re as _re
    for m in _re.finditer(r'<!--\s*wp:heading[^>]*-->[\s\S]*?<!--\s*/wp:heading\s*-->', out):
        assert 'ai-product' not in m.group(0), 'マーカーが heading ブロック内に混入'


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
# SQLite ドキュメントストア（ロードマップ #2）
# ─────────────────────────────────────────────
def test_save_load_doc_roundtrip():
    app.save_doc('test_doc_roundtrip', {'a': 1, 'list': [1, 2], 'jp': '日本語'})
    assert app.load_doc('test_doc_roundtrip', None) == {'a': 1, 'list': [1, 2], 'jp': '日本語'}


def test_load_doc_returns_default_when_missing():
    assert app.load_doc('definitely-no-such-key-xyz', []) == []
    assert app.load_doc('definitely-no-such-key-xyz', {'d': 1}) == {'d': 1}


def test_save_doc_upserts():
    app.save_doc('test_doc_upsert', {'v': 1})
    app.save_doc('test_doc_upsert', {'v': 2})  # 上書き
    assert app.load_doc('test_doc_upsert', None) == {'v': 2}


def test_articles_persist_through_db():
    # save_articles / load_articles が DB 経由で往復すること
    sample = [{'id': 'test-a1', 'title': 'テスト記事', 'status': 'pending'}]
    original = app.load_articles()
    try:
        app.save_articles(sample)
        assert app.load_articles() == sample
    finally:
        app.save_articles(original)  # 後始末


# ─────────────────────────────────────────────
# 記事品質スコアリング・品質ゲート（ロードマップ #7）
# ─────────────────────────────────────────────
def test_score_article_content_structure():
    html = (
        '<h2>選び方</h2><p>' + 'あ' * 400 + '</p>'
        '<h3>ポイント</h3><ul><li>項目</li></ul>'
        '<h2>まとめ</h2><p>結論</p>'
    )
    sd = app.score_article_content('テスト記事 おすすめ', html, 'テスト')
    assert 0 <= sd['score'] <= 100
    assert sd['grade'] in ('A', 'B', 'C', 'D')
    assert isinstance(sd['suggestions'], list)
    assert 'metrics' in sd


def test_score_article_content_rich_beats_empty():
    sd_empty = app.score_article_content('', '', '')
    sd_rich = app.score_article_content(
        'ネッククーラー おすすめ',
        '<h2>選び方</h2><p>' + 'x' * 600 + '</p>'
        '<h2>比較</h2><table><tr><td>A</td></tr></table>'
        '<h3>詳細</h3><ul><li>1</li><li>2</li></ul>'
        '<h2>まとめ</h2><p>結論</p>',
        'ネッククーラー',
    )
    # 充実した記事のほうが空記事よりスコアが高い
    assert sd_rich['score'] > sd_empty['score']


def test_quality_gate_config_is_sane():
    assert isinstance(app.QUALITY_GATE_MIN_SCORE, int)
    assert 0 <= app.QUALITY_GATE_MIN_SCORE <= 100
    assert app.QUALITY_GATE_MAX_POLISH >= 1


def test_is_overload_error():
    # Claude API 過負荷（529 / overloaded_error）を検出
    assert app.is_overload_error(
        "Error code: 529 - {'type': 'error', 'error': "
        "{'type': 'overloaded_error', 'message': 'Overloaded'}}")
    assert app.is_overload_error("Overloaded")
    # 過負荷以外のエラーは False
    assert not app.is_overload_error("Error code: 401 - authentication_error")
    assert not app.is_overload_error("Error code: 400 - invalid_request")
    assert not app.is_overload_error("req_011CbH3Emxbr4VssfbpHB9yt")
    assert not app.is_overload_error("")
    assert not app.is_overload_error(None)
    assert app.CLAUDE_OVERLOAD_MAX_RETRIES >= 1


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


def test_reveal_secret_endpoint():
    client = app.app.test_client()
    # 未認証はブロック
    assert client.get('/api/settings/reveal-secret/claude_api_key').status_code in (401, 302)
    client.post('/login', json={'password': 'testpass'})
    # 既知フィールドは 200 + value キー
    r = client.get('/api/settings/reveal-secret/claude_api_key')
    assert r.status_code == 200
    assert 'value' in r.get_json()
    # ホワイトリスト外フィールドは 404
    assert client.get('/api/settings/reveal-secret/bogus_field').status_code == 404


def test_settings_get_masks_secrets():
    client = app.app.test_client()
    client.post('/login', json={'password': 'testpass'})
    # 既知のキーを保存 → GET はマスクされて返ること
    app.save_settings({**app.load_settings(), 'claude_api_key': 'sk-ant-SECRETVALUE1234567890'})
    body = client.get('/api/settings').get_json()
    assert '•' in body['claude_api_key']                # マスクされている
    assert 'SECRETVALUE' not in body['claude_api_key']   # 後半は露出しない
    # reveal は実値を返す
    revealed = client.get('/api/settings/reveal-secret/claude_api_key').get_json()
    assert revealed['value'] == 'sk-ant-SECRETVALUE1234567890'


def test_reveal_site_password_endpoint():
    client = app.app.test_client()
    # 未認証はブロック
    assert client.get('/api/sites/x/reveal-password').status_code in (401, 302)
    client.post('/login', json={'password': 'testpass'})
    # 存在しないサイトは 404
    assert client.get('/api/sites/nonexistent-site/reveal-password').status_code == 404
