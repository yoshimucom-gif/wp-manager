/* 服じまい 追従バナー
   PCのサイドバー枠は読み込み後そのまま表示。
   スマホの下固定バーは少し読み進めてから出す。閉じたらその訪問のあいだは出さない。 */
(function () {
	'use strict';

	var STORE = 'fjctaClosed:';

	function isClosed(k) {
		try { return window.sessionStorage.getItem(STORE + k) === '1'; }
		catch (e) { return false; }
	}

	function markClosed(k) {
		try { window.sessionStorage.setItem(STORE + k, '1'); }
		catch (e) { /* プライベートウィンドウでは記憶しない */ }
	}

	function bindClose(el, kind) {
		var btn = el.querySelector('.fj-bn-x');
		if (!btn) return;
		btn.addEventListener('click', function () {
			el.setAttribute('hidden', 'hidden');
			el.classList.remove('is-in');
			if (kind === 'sp') document.body.classList.remove('fj-bn-sp-on');
			markClosed(kind);
		});
	}

	function init() {
		var list = document.querySelectorAll('[data-fj-bn]');
		if (!list.length) return;

		Array.prototype.forEach.call(list, function (el) {
			var kind = el.getAttribute('data-fj-bn');
			if (isClosed(kind)) return;
			bindClose(el, kind);

			if (kind === 'side') {
				el.removeAttribute('hidden');
				return;
			}

			// スマホは300pxほど読み進めてから
			var shown = false;
			var show = function () {
				if (shown) return;
				if ((window.pageYOffset || document.documentElement.scrollTop) < 300) return;
				shown = true;
				el.removeAttribute('hidden');
				document.body.classList.add('fj-bn-sp-on');
				// hidden を外した直後だと transition が効かないので1フレーム置く
				window.requestAnimationFrame(function () {
					window.requestAnimationFrame(function () { el.classList.add('is-in'); });
				});
				window.removeEventListener('scroll', show);
			};
			window.addEventListener('scroll', show, { passive: true });
			show();
		});
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
})();
