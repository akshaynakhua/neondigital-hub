/* Public website only: mobile menu, header state, product tabs, FAQ, billing switch and reveal on scroll. */
(function () {
	'use strict';
	var head = document.getElementById('siteHead');
	var btn = document.getElementById('menuBtn');
	var nav = document.getElementById('headNav');

	if (head) {
		var onScroll = function () { head.classList.toggle('is-stuck', window.scrollY > 8); };
		window.addEventListener('scroll', onScroll, { passive: true });
		onScroll();
	}

	if (btn && nav) {
		var setMenu = function (open) {
			head.classList.toggle('menu-open', open);
			document.documentElement.classList.toggle('menu-lock', open);
			btn.setAttribute('aria-expanded', String(open));
			btn.setAttribute('aria-label', open ? 'Close menu' : 'Open menu');
		};
		btn.addEventListener('click', function () { setMenu(!head.classList.contains('menu-open')); });
		nav.addEventListener('click', function (e) { if (e.target.closest('a')) setMenu(false); });
		document.addEventListener('keydown', function (e) {
			if (e.key === 'Escape' && head.classList.contains('menu-open')) { setMenu(false); btn.focus(); }
		});
		window.addEventListener('resize', function () { if (window.innerWidth > 960) setMenu(false); });
	}

	/* Product tabs, with arrow-key movement as the tab pattern expects. */
	document.querySelectorAll('[data-tabs]').forEach(function (box) {
		var tabs = Array.prototype.slice.call(box.querySelectorAll('[role=tab]'));
		var select = function (tab, focus) {
			tabs.forEach(function (t) {
				var on = t === tab;
				t.setAttribute('aria-selected', String(on));
				if (on) t.removeAttribute('tabindex'); else t.setAttribute('tabindex', '-1');
				document.getElementById(t.getAttribute('aria-controls')).hidden = !on;
			});
			if (focus) tab.focus();
			if (tab.scrollIntoView && tab.parentNode.scrollWidth > tab.parentNode.clientWidth) {
				tab.parentNode.scrollLeft = tab.offsetLeft - (tab.parentNode.clientWidth - tab.offsetWidth) / 2;
			}
		};
		tabs.forEach(function (t, i) {
			t.addEventListener('click', function () { select(t); });
			t.addEventListener('keydown', function (e) {
				var n = e.key === 'ArrowRight' ? i + 1 : e.key === 'ArrowLeft' ? i - 1 : e.key === 'Home' ? 0 : e.key === 'End' ? tabs.length - 1 : null;
				if (n === null) return;
				e.preventDefault();
				select(tabs[(n + tabs.length) % tabs.length], true);
			});
		});
	});

	/* One FAQ answer open at a time. Without this script each item still opens by itself. */
	document.querySelectorAll('[data-faq]').forEach(function (box) {
		var items = box.querySelectorAll('details');
		items.forEach(function (d) {
			d.addEventListener('toggle', function () {
				if (d.open) items.forEach(function (o) { if (o !== d) o.open = false; });
			});
		});
	});

	/* Monthly and annual prices. Links keep the chosen period for registration. */
	document.querySelectorAll('input[name=cycle]').forEach(function (r) {
		r.addEventListener('change', function () {
			var c = r.value;
			document.querySelectorAll('[data-for]').forEach(function (x) { x.hidden = x.getAttribute('data-for') !== c; });
			document.querySelectorAll('[data-plan-link]').forEach(function (a) {
				var u = new URL(a.href);
				var can = a.getAttribute(c === 'year' ? 'data-year' : 'data-month');
				u.searchParams.set('cycle', c);
				a.href = u.toString();
				a.hidden = !can && !a.getAttribute('data-trial');
			});
		});
	});

	/* Reveal on scroll. Content is visible by default if this does not run. */
	var els = document.querySelectorAll('[data-reveal]');
	if (!('IntersectionObserver' in window) || window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
		els.forEach(function (el) { el.classList.add('is-in'); });
	} else {
		var io = new IntersectionObserver(function (entries) {
			entries.forEach(function (en) {
				if (en.isIntersecting) { en.target.classList.add('is-in'); io.unobserve(en.target); }
			});
		}, { rootMargin: '0px 0px -6% 0px', threshold: 0.05 });
		els.forEach(function (el) { io.observe(el); });
	}
})();
