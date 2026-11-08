/* Customer portal. Everything comes from the server, already limited to this customer. */
(function () {
	'use strict';
	var C = window.NW, el = document.getElementById('portal'), url = C.root + 'public/portal/' + C.token;
	var esc = function (s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (m) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[m]; }); };
	var inr = function (n) { return '₹' + Number(n || 0).toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); };
	var safe = function (u) { return /^https?:\/\//i.test(String(u || '')) ? esc(u) : '#'; };
	function load() {
		fetch(url).then(function (r) { return r.json().then(function (d) { if (!r.ok) throw new Error(d.message); return d; }); }).then(draw).catch(function (e) {
			el.innerHTML = '<section class="card card-pad"><p>' + esc(e.message) + '</p></section>';
		});
	}
	function draw(d) {
		var tz = d.business.timezone, when = function (v) { return new Date(String(v).replace(' ', 'T') + 'Z').toLocaleString('en-IN', { timeZone: tz, dateStyle: 'medium', timeStyle: 'short' }); };
		var list = function (title, rows, empty) { return '<section class="card card-pad"><h2>' + title + '</h2>' + (rows.length ? rows.join('') : '<p class="muted">' + empty + '</p>') + '</section>'; };
		if (d.business.color && /^#[0-9a-f]{6}$/i.test(d.business.color)) document.documentElement.style.setProperty('--brand', d.business.color);
		el.innerHTML = '<div class="book-head">' + (d.business.logo_url ? '<img class="book-logo" src="' + safe(d.business.logo_url) + '" alt="">' : '') + '<div><p class="muted small" style="margin:0">' + esc(d.business.name) + '</p><h1>Hello, ' + esc(d.profile.name) + '</h1></div></div><div class="stack">' +
			list('Your bookings', d.bookings.map(function (b) { return '<div class="row"><div class="grow"><b>' + esc(b.service_name || 'Booking') + '</b><div class="muted small">' + esc(when(b.starts_at)) + '</div></div><span class="badge">' + esc(String(b.status).replace('_', ' ')) + '</span></div>'; }), 'No bookings yet.') +
			list('Invoices', d.invoices.map(function (v) { return '<div class="row"><div class="grow"><b>' + esc(v.doc_no) + '</b><div class="muted small">' + esc(v.date) + ' · ' + inr(v.total) + (v.paid ? ' · paid ' + inr(v.paid) : '') + '</div></div><span class="badge">' + esc(String(v.status).replace('_', ' ')) + '</span><a class="btn btn-sm" href="' + safe(v.url) + '">Open</a></div>'; }), 'No invoices yet.') +
			list('Quotations', d.quotes.map(function (v) { return '<div class="row"><div class="grow"><b>' + esc(v.doc_no) + '</b><div class="muted small">' + esc(v.date) + ' · ' + inr(v.total) + '</div></div><span class="badge">' + esc(v.status) + '</span><a class="btn btn-sm" href="' + safe(v.url) + '">Open</a></div>'; }), 'No quotations yet.') +
			(d.documents.length ? list('Shared documents', d.documents.map(function (x) { return '<div class="row"><div class="grow">' + esc(x.name) + '</div><a class="btn btn-sm" href="' + safe(x.url) + '">Download</a></div>'; }), '') : '') +
			'<section class="card card-pad"><h2>Your details</h2><p class="muted small">' + esc(d.profile.email) + '</p><form id="pf" novalidate><label class="field"><span>Phone</span><input name="phone" type="tel" value="' + esc(d.profile.phone) + '"></label><label class="field"><span>Address</span><textarea name="billing_address" rows="2">' + esc(d.profile.billing_address) + '</textarea></label><button class="btn" type="submit">Save</button> <span id="pf-ok" class="muted small" role="status"></span></form>' +
			(d.business.phone || d.business.email ? '<p class="muted small" style="margin-top:12px">Questions? Contact ' + esc(d.business.name) + ': ' + esc([d.business.phone, d.business.email].filter(Boolean).join(' · ')) + '</p>' : '') + '</section></div>';
		var f = document.getElementById('pf');
		f.addEventListener('submit', function (e) {
			e.preventDefault();
			fetch(url, { method: 'PUT', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ phone: f.phone.value, billing_address: f.billing_address.value }) }).then(function (r) {
				document.getElementById('pf-ok').textContent = r.ok ? 'Saved.' : 'Could not save. Please try again.';
			});
		});
	}
	load();
})();
