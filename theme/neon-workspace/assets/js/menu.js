/* Public QR menu: browse, and order to the scanned table. Prices shown here are for display; the
 * server always prices the order from the menu. */
(function () {
	'use strict';
	var C = window.NW, box = document.getElementById('menu'), cart = [];
	var esc = function (s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (m) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[m]; }); };
	var inr = function (n) { return '₹' + Number(n || 0).toLocaleString('en-IN', { minimumFractionDigits: 0, maximumFractionDigits: 2 }); };
	var api = function (path, opt) {
		opt = opt || {};
		return fetch(C.root + path, { method: opt.method || 'GET', headers: { 'Content-Type': 'application/json' }, body: opt.body ? JSON.stringify(opt.body) : undefined }).then(function (r) { return r.json().then(function (d) { if (!r.ok) throw new Error(/^nwc_/.test(d.code || '') ? d.message : 'Something went wrong. Please ask the staff.'); return d; }); });
	};
	var key = Date.now().toString(36) + Math.random().toString(36).slice(2, 10);
	api('public/menu/' + encodeURIComponent(C.slug) + (C.table ? '?table=' + encodeURIComponent(C.table) : '')).then(function (d) {
		if (d.business.brand_color) document.documentElement.style.setProperty('--brand', d.business.brand_color);
		var draw = function () {
			var total = cart.reduce(function (s, c) { return s + c.price * c.qty; }, 0);
			box.innerHTML = '<header class="book-head">' + (d.business.logo_url ? '<img class="book-logo" src="' + esc(d.business.logo_url) + '" alt="">' : '') + '<div><h1>' + esc(d.business.name) + '</h1>' + (d.table ? '<p class="muted">Table ' + esc(d.table.title) + '</p>' : '<p class="muted">Menu</p>') + '</div></header>' +
				d.categories.concat([{ id: null, name: 'More' }]).map(function (c) {
					var items = d.items.filter(function (i) { return c.id === null ? !d.categories.some(function (x) { return x.id === i.category_id; }) : i.category_id === c.id; });
					return items.length ? '<h2>' + esc(c.name) + '</h2><div class="svc-list">' + items.map(function (i) { return '<div class="svc"><b>' + esc(i.name) + ' · ' + inr(i.price) + '</b>' + (i.description ? '<span class="muted small">' + esc(i.description) + '</span>' : '') + (d.ordering ? '<div>' + (i.variants.length ? i.variants : [{ name: '', price: i.price }]).map(function (v) { return '<button class="btn btn-sm" data-add="' + i.id + '" data-v="' + esc(v.name) + '" data-p="' + v.price + '" data-n="' + esc(i.name) + '">Add' + (v.name ? ' ' + esc(v.name) + ' ' + inr(v.price) : '') + '</button> '; }).join('') + '</div>' : '') + '</div>'; }).join('') + '</div>' : '';
				}).join('') +
				(d.ordering ? '<section class="card card-pad" style="margin-top:16px"><h2>Your order</h2>' + (cart.length ? cart.map(function (c, n) { return '<div class="row"><div class="grow">' + esc(c.name) + (c.variant ? ' (' + esc(c.variant) + ')' : '') + ' × ' + c.qty + '</div><button class="btn btn-sm" data-rm="' + n + '">Remove</button></div>'; }).join('') + '<p><b>About ' + inr(total) + '</b> <span class="muted small">before taxes</span></p><label class="field"><span>Your name (optional)</span><input data-nm autocomplete="name"></label><label class="hp" aria-hidden="true">Website <input data-hp tabindex="-1"></label><div class="form-error" hidden role="alert"></div><button class="btn btn-brand btn-block" data-go>Send order to the kitchen staff</button>' : '<p class="muted">Tap Add on any item.</p>') + '</section>' : '<p class="muted small">Scan the QR code on your table to order.</p>');
		};
		box.onclick = function (e) {
			var a = e.target.closest('[data-add]');
			if (a) { var id = +a.getAttribute('data-add'), v = a.getAttribute('data-v'), hit = cart.filter(function (c) { return c.id === id && c.variant === v; })[0]; if (hit) hit.qty++; else cart.push({ id: id, variant: v, name: a.getAttribute('data-n'), price: +a.getAttribute('data-p'), qty: 1 }); draw(); return; }
			var r = e.target.closest('[data-rm]');
			if (r) { cart.splice(+r.getAttribute('data-rm'), 1); draw(); return; }
			if (e.target.closest('[data-go]')) {
				var err = box.querySelector('.form-error');
				api('public/menu/' + encodeURIComponent(C.slug) + '/order', { method: 'POST', body: { table: C.table, name: box.querySelector('[data-nm]').value, website: box.querySelector('[data-hp]').value, idempotency_key: key, items: cart.map(function (c) { return { menu_item_id: c.id, variant: c.variant || undefined, qty: c.qty }; }) } }).then(function (r2) {
					cart = []; key = Date.now().toString(36) + Math.random().toString(36).slice(2, 10);
					box.innerHTML = '<section class="card card-pad"><h1>Order sent</h1><p>Your order ' + esc(r2.order_no) + ' is with the staff. Total so far ' + inr(r2.total) + '.</p><button class="btn" onclick="location.reload()">Order more</button></section>';
				}).catch(function (x) { err.textContent = x.message; err.hidden = false; });
			}
		};
		draw();
	}).catch(function (e) { box.innerHTML = '<p class="form-error">' + esc(e.message) + '</p>'; });
})();
