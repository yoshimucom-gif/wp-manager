/* インテリアグッズLab 条件で探す
   商品はPHPが全点描いている。ここでやるのは表示の出し入れと件数の付け替えだけ。
   読み込み時にURLの ?w=35-55&m=木 を読み、操作のたびに replaceState で書き戻す
   （絞った状態のURLを人に送れるようにする。こちらからリンクは張らない）。 */
(function () {
	'use strict';

	var SIZE = ['w', 'd', 'h'];          /* 寸法の軸。区分は "下限-上限" で持つ */
	var LIST = ['s', 'm', 'c', 'p', 'g', 'f'];  /* 段数・素材・色・置き場所・入れるもの・その他 */
	var LABEL = {
		w: '幅', d: '奥行', h: '高さ', s: '段数', m: '素材',
		c: '色', p: '置き場所', g: '入れるもの', f: '条件'
	};

	function init(root) {
		var cards = [].slice.call(root.querySelectorAll('.ifd-c'));
		var btns = [].slice.call(root.querySelectorAll('.ifd-b'));
		var fits = [].slice.call(root.querySelectorAll('[data-fit]'));
		var chips = root.querySelector('.ifd-chips');
		var count = root.querySelector('.ifd-count');
		var none = root.querySelector('.ifd-none');
		var reset = root.querySelector('.ifd-reset');
		if (!cards.length || !count) {
			return;
		}

		/* 選んだ条件。キーごとに値の集合（同じキーの中はOR、キーをまたぐとAND） */
		var sel = {};
		var fit = {};

		/* カードの仕様を先に配列へ写す。毎回DOMを読まない */
		var data = cards.map(function (el) {
			var o = { el: el, has: el.hasAttribute('data-w') };
			SIZE.forEach(function (k) { o[k] = parseFloat(el.getAttribute('data-' + k)); });
			o.s = el.getAttribute('data-s') || '';
			['m', 'c', 'p', 'g', 'f'].forEach(function (k) {
				var v = el.getAttribute('data-' + k) || '';
				o[k] = v ? v.split(',') : [];
			});
			return o;
		});

		function inBucket(v, b) {
			var p = b.split('-');
			var lo = parseFloat(p[0]), hi = parseFloat(p[1]);
			if (lo > 0 && !(v > lo)) { return false; }
			if (hi > 0 && !(v <= hi)) { return false; }
			return true;
		}

		/* キー key の条件を無視して判定する（その軸の選択肢の件数を数えるため）。
		   key が null なら全条件で判定する */
		function ok(it, skip) {
			var k, i;
			for (i = 0; i < SIZE.length; i++) {
				k = SIZE[i];
				var bs = sel[k], f = fit[k];
				if (k !== skip && bs && bs.length) {
					if (!it.has) { return false; }
					if (!bs.some(function (b) { return inBucket(it[k], b); })) { return false; }
				}
				if (k !== skip && f) {
					if (!it.has || !(it[k] <= f)) { return false; }
				}
			}
			for (i = 0; i < LIST.length; i++) {
				k = LIST[i];
				if (k === skip) { continue; }
				var vs = sel[k];
				if (!vs || !vs.length) { continue; }
				if (k === 's') {
					if (vs.indexOf(it.s) < 0) { return false; }
				} else if (!vs.some(function (v) { return it[k].indexOf(v) >= 0; })) {
					return false;
				}
			}
			return true;
		}

		function labelOf(btn) {
			var t = btn.cloneNode(true);
			var n = t.querySelector('.ifd-n');
			if (n) { n.parentNode.removeChild(n); }
			return (t.textContent || '').trim();
		}

		function apply() {
			var n = 0;
			data.forEach(function (it) {
				var show = ok(it, null);
				if (show) { n++; }
				if (it.el.hidden === !show) { return; }
				it.el.hidden = !show;
			});
			count.textContent = n;
			if (none) { none.hidden = n > 0; }

			/* 各選択肢に「いま押したら何点になるか」を出し、0点なら押せなくする */
			btns.forEach(function (b) {
				var k = b.getAttribute('data-key');
				var v = b.getAttribute('data-v');
				var hi = b.getAttribute('data-hi');
				var mine = SIZE.indexOf(k) >= 0 ? (b.getAttribute('data-lo') + '-' + hi) : v;
				var on = (sel[k] || []).indexOf(mine) >= 0;
				b.setAttribute('aria-pressed', on ? 'true' : 'false');
				var c = 0;
				data.forEach(function (it) {
					if (!ok(it, k)) { return; }
					if (SIZE.indexOf(k) >= 0) {
						if (it.has && inBucket(it[k], mine) && (!fit[k] || it[k] <= fit[k])) { c++; }
					} else if (k === 's') {
						if (it.s === v) { c++; }
					} else if (it[k].indexOf(v) >= 0) {
						c++;
					}
				});
				var badge = b.querySelector('.ifd-n');
				if (badge) { badge.textContent = c; }
				b.disabled = (c === 0 && !on);
			});

			/* 外した条件を1クリックで戻せるように、選んだ条件を並べる */
			if (chips) {
				chips.textContent = '';
				btns.forEach(function (b) {
					if (b.getAttribute('aria-pressed') !== 'true') { return; }
					var k = b.getAttribute('data-key');
					var el = document.createElement('button');
					el.type = 'button';
					el.className = 'ifd-chip';
					el.textContent = (SIZE.indexOf(k) >= 0 ? LABEL[k] + ' ' : '') + labelOf(b);
					el.addEventListener('click', function () { b.click(); });
					chips.appendChild(el);
				});
				SIZE.forEach(function (k) {
					if (!fit[k]) { return; }
					var el = document.createElement('button');
					el.type = 'button';
					el.className = 'ifd-chip';
					el.textContent = LABEL[k] + ' ' + fit[k] + 'cm以下に収まる';
					el.addEventListener('click', function () {
						fit[k] = 0;
						fits.forEach(function (i) { if (i.getAttribute('data-fit') === k) { i.value = ''; } });
						apply();
					});
					chips.appendChild(el);
				});
			}
			if (reset) {
				reset.hidden = !chips || !chips.children.length;
			}
			writeUrl();
		}

		function writeUrl() {
			if (!window.history || !history.replaceState) { return; }
			var q = [];
			Object.keys(sel).forEach(function (k) {
				if (sel[k] && sel[k].length) {
					q.push(encodeURIComponent(k) + '=' + sel[k].map(encodeURIComponent).join('.'));
				}
			});
			SIZE.forEach(function (k) {
				if (fit[k]) { q.push('fit' + k + '=' + fit[k]); }
			});
			var url = location.pathname + (q.length ? '?' + q.join('&') : '') + location.hash;
			history.replaceState(null, '', url);
		}

		function readUrl() {
			var s = location.search.replace(/^\?/, '');
			if (!s) { return; }
			s.split('&').forEach(function (pair) {
				var p = pair.split('=');
				var k = decodeURIComponent(p[0] || '');
				var v = decodeURIComponent(p[1] || '');
				if (!v) { return; }
				if (/^fit[wdh]$/.test(k)) {
					var f = parseFloat(v);
					if (f > 0) {
						fit[k.slice(3)] = f;
						fits.forEach(function (i) {
							if (i.getAttribute('data-fit') === k.slice(3)) { i.value = f; }
						});
					}
				} else if (SIZE.indexOf(k) >= 0 || LIST.indexOf(k) >= 0) {
					sel[k] = v.split('.');
				}
			});
		}

		btns.forEach(function (b) {
			b.addEventListener('click', function () {
				var k = b.getAttribute('data-key');
				var v = SIZE.indexOf(k) >= 0
					? (b.getAttribute('data-lo') + '-' + b.getAttribute('data-hi'))
					: b.getAttribute('data-v');
				sel[k] = sel[k] || [];
				var i = sel[k].indexOf(v);
				if (i >= 0) { sel[k].splice(i, 1); } else { sel[k].push(v); }
				apply();
			});
		});

		fits.forEach(function (i) {
			var run = function () {
				var k = i.getAttribute('data-fit');
				var v = parseFloat(i.value);
				fit[k] = (v > 0) ? v : 0;
				apply();
			};
			i.addEventListener('input', run);
			i.addEventListener('change', run);
		});

		if (reset) {
			reset.addEventListener('click', function () {
				sel = {};
				fit = {};
				fits.forEach(function (i) { i.value = ''; });
				apply();
			});
		}

		[].forEach.call(root.querySelectorAll('.ifd-p'), function (p) {
			p.addEventListener('click', function () {
				var q;
				try {
					q = JSON.parse(p.getAttribute('data-q'));
				} catch (e) {
					return;
				}
				sel = {};
				fit = {};
				fits.forEach(function (i) { i.value = ''; });
				Object.keys(q).forEach(function (k) {
					if (/^fit[wdh]$/.test(k)) {
						fit[k.slice(3)] = parseFloat(q[k]);
						fits.forEach(function (i) {
							if (i.getAttribute('data-fit') === k.slice(3)) { i.value = q[k]; }
						});
					} else {
						sel[k] = [].concat(q[k]);
					}
				});
				apply();
				var bar = root.querySelector('.ifd-bar');
				if (bar && bar.scrollIntoView) {
					bar.scrollIntoView({ behavior: 'smooth', block: 'start' });
				}
			});
		});

		readUrl();
		apply();
	}

	function boot() {
		[].forEach.call(document.querySelectorAll('.ifd'), init);
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', boot);
	} else {
		boot();
	}
})();
