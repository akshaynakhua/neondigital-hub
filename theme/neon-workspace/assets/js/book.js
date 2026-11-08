/* Public booking page and the customer's manage-booking page. */
(function () {
	'use strict';
	var C = window.NW || {};
	function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (m) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[m]; }); }
	function req(path, body) {
		var o = { credentials: 'omit', headers: { 'Content-Type': 'application/json' } };
		if (body) { o.method = 'POST'; o.body = JSON.stringify(body); }
		return fetch(C.root + path, o).then(function (r) {
			return r.json().catch(function () { return {}; }).then(function (d) {
				if (!r.ok) throw new Error(d.message || 'Something went wrong. Please try again.');
				return d;
			});
		});
	}
	function money(v) { return +v ? '₹' + Number(v).toLocaleString('en-IN') : ''; }
	function tz() { return (C.data && C.data.business.timezone) || 'Asia/Kolkata'; }
	function dayStr(offset, zone) {
		var d = new Date(Date.now() + offset * 864e5);
		return new Intl.DateTimeFormat('en-CA', { timeZone: zone, year: 'numeric', month: '2-digit', day: '2-digit' }).format(d);
	}
	function when(iso, zone) { return new Date(iso).toLocaleString('en-IN', { timeZone: zone, dateStyle: 'medium', timeStyle: 'short' }); }

	/* ---- Booking (Phase 5: one premium dark design, clear prices and seats, sticky total) ---- */
	var box = document.getElementById('book');
	if (box && C.data) {
		var S = { service: null, date: dayStr(0, tz()), staff: '', space: '', slot: null, left: 0, full: false, party: 1, key: Math.random().toString(36).slice(2) + Date.now().toString(36) };
		var services = C.data.services, qs = C.data.page.fields || [];
		var bar = document.getElementById('bk-bar'), barGo = bar.querySelector('[data-bar-go]');
		var inr = function (v) { return '₹' + Number(+v || 0).toLocaleString('en-IN', { maximumFractionDigits: 2 }); };
		var priceText = function (s) { return +s.price ? inr(s.price) : 'Free'; };
		var dur = function (m) { m = +m; return m >= 60 ? Math.floor(m / 60) + ' h' + (m % 60 ? ' ' + (m % 60) + ' min' : '') : m + ' min'; };
		var step = function (n) { document.querySelectorAll('.bk-steps li').forEach(function (li) { var k = +li.getAttribute('data-step'); li.classList.toggle('on', k === n); li.classList.toggle('done', k < n); }); };
		var setBar = function (o) {
			if (!o) { bar.hidden = true; document.body.classList.remove('has-bar'); return; }
			bar.hidden = false;
			document.body.classList.add('has-bar');
			bar.querySelector('[data-bar-label]').textContent = o.label || 'Total';
			bar.querySelector('[data-bar-total]').textContent = o.total;
			bar.querySelector('[data-bar-sub]').textContent = o.sub || '';
			barGo.textContent = o.cta;
			barGo.disabled = !!o.disabled;
			barGo.onclick = o.go || null;
		};
		var top = function () { var y = box.getBoundingClientRect().top + window.scrollY - 12; if (window.scrollY > y) window.scrollTo({ top: y, behavior: 'smooth' }); };

		var paintServices = function () {
			step(1);
			setBar(null);
			if (!services.length) { box.innerHTML = '<div class="bk-card"><p class="bk-muted">Nothing is open for booking yet. Please contact the business.</p></div>'; return; }
			box.innerHTML = '<h2 class="bk-h">Choose what to book</h2><div class="bk-grid">' + services.map(function (s) {
				var group = +s.capacity > 1;
				return '<article class="bk-svc">' + (s.image_url ? '<img class="bk-svc-img" src="' + esc(s.image_url) + '" alt="" loading="lazy">' : '') + '<div class="bk-svc-b"><div class="bk-svc-top"><h3>' + esc(s.name) + '</h3><span class="bk-price">' + priceText(s) + (+s.price && group ? '<small>per person</small>' : '') + '</span></div>' +
					'<div class="bk-tags">' + (s.kind_label || s.type_label ? '<span>' + esc(s.kind_label || s.type_label) + '</span>' : '') + '<span>' + esc(dur(s.duration_min)) + '</span>' + (group ? '<span>Up to ' + (+s.capacity) + ' seats</span>' : '<span>1 to 1</span>') + (s.spaces && s.spaces.length ? '<span>' + esc(s.spaces.map(function (x) { return x.name; }).join(', ')) + '</span>' : '') + (s.waitlist ? '<span>Waitlist when full</span>' : '') + '</div>' +
					(s.description ? '<p class="bk-svc-d">' + esc(s.description) + '</p>' : '') + '<button type="button" class="bk-btn" data-s="' + s.id + '">Book Now</button></div></article>';
			}).join('') + '</div>';
			box.querySelectorAll('[data-s]').forEach(function (b) {
				b.onclick = function () {
					S.service = services.filter(function (x) { return +x.id === +b.getAttribute('data-s'); })[0];
					S.slot = null; S.staff = ''; S.space = ''; S.party = 1;
					paintSlots();
					top();
				};
			});
		};
		var dateChips = function () {
			var out = '', zone = tz(), max = Math.min(14, +C.data.page.max_days_ahead || 14);
			for (var i = 0; i <= max; i++) {
				var d = dayStr(i, zone), dt = new Date(d + 'T12:00:00');
				out += '<button type="button" class="bk-day' + (d === S.date ? ' on' : '') + '" data-day="' + d + '"><small>' + (i === 0 ? 'Today' : i === 1 ? 'Tomorrow' : dt.toLocaleDateString('en-IN', { weekday: 'short' })) + '</small><b>' + dt.getDate() + '</b><small>' + dt.toLocaleDateString('en-IN', { month: 'short' }) + '</small></button>';
			}
			return out;
		};
		var paintSlots = function () {
			var svc = S.service;
			step(2);
			box.innerHTML = '<button type="button" class="bk-back" data-back>← All services</button><div class="bk-card bk-sel"><div><h2>' + esc(svc.name) + '</h2><p class="bk-muted">' + esc(dur(svc.duration_min)) + (+svc.capacity > 1 ? ' · up to ' + (+svc.capacity) + ' seats' : '') + '</p></div><span class="bk-price bk-price-lg">' + priceText(svc) + '</span></div>' +
				'<h2 class="bk-h">Pick a date</h2><div class="bk-days" role="group" aria-label="Dates">' + dateChips() + '</div><label class="field bk-more-date"><span>Another date</span><input type="date" data-date min="' + dayStr(0, tz()) + '" max="' + dayStr(C.data.page.max_days_ahead, tz()) + '" value="' + esc(S.date) + '"></label>' +
				'<div class="bk-row2">' + (svc.staff && svc.staff.length > 1 ? '<label class="field"><span>With</span><select data-staff><option value="">Anyone available</option>' + svc.staff.map(function (m) { return '<option value="' + m.id + '"' + (+S.staff === +m.id ? ' selected' : '') + '>' + esc(m.name) + '</option>'; }).join('') + '</select></label>' : '') +
				(svc.spaces && svc.spaces.length > 1 ? '<label class="field"><span>Room or space</span><select data-space><option value="">Any</option>' + svc.spaces.map(function (x) { return '<option value="' + x.id + '"' + (+S.space === +x.id ? ' selected' : '') + '>' + esc(x.name) + '</option>'; }).join('') + '</select></label>' : '') + '</div>' +
				'<h2 class="bk-h">Pick a time</h2><div data-slots class="bk-slots"><p class="bk-muted">Loading times…</p></div><p class="bk-muted small">Times are in ' + esc(tz()) + '.</p>';
			setBar({ label: svc.name, total: priceText(svc), sub: 'Choose a time', cta: 'Continue', disabled: true });
			box.querySelector('[data-back]').onclick = function () { paintServices(); top(); };
			var setDay = function (d) { S.date = d; S.slot = null; paintSlots(); };
			box.querySelectorAll('[data-day]').forEach(function (b) { b.onclick = function () { setDay(b.getAttribute('data-day')); }; });
			box.querySelector('[data-date]').onchange = function (e) { if (e.target.value) setDay(e.target.value); };
			var st = box.querySelector('[data-staff]');
			if (st) st.onchange = function (e) { S.staff = e.target.value; paintSlots(); };
			var spc = box.querySelector('[data-space]');
			if (spc) spc.onchange = function (e) { S.space = e.target.value; paintSlots(); };
			var q = 'public/book/' + C.slug + '/slots?service_id=' + svc.id + '&date=' + encodeURIComponent(S.date) + (S.staff ? '&staff_id=' + S.staff : '') + (S.space ? '&space_id=' + S.space : '');
			req(q).then(function (r) {
				var el = box.querySelector('[data-slots]');
				if (!el) return;
				var group = +svc.capacity > 1;
				el.innerHTML = r.slots.length ? r.slots.map(function (x) {
					var low = group && !x.full && +svc.capacity >= 5 && x.remaining <= Math.ceil(+svc.capacity * 0.2);
					return '<button type="button" class="bk-slot' + (x.full ? ' is-full' : '') + (low ? ' is-low' : '') + (S.slot === x.start ? ' on' : '') + '" data-t="' + esc(x.start) + '" data-left="' + (+x.remaining) + '"' + (x.full ? ' data-full="1"' : '') + '><b>' + esc(x.label) + '</b>' + (x.full ? '<small>Full · join waitlist</small>' : group ? '<small>' + x.remaining + ' of ' + (+svc.capacity) + (x.remaining === 1 ? ' seat left' : ' seats left') + '</small>' : '<small>Available</small>') + '</button>';
				}).join('') : '<div class="bk-card bk-empty"><b>No times on this day</b><p class="bk-muted">Try another date.</p></div>';
				el.querySelectorAll('[data-t]').forEach(function (b) {
					b.onclick = function () {
						S.slot = b.getAttribute('data-t'); S.full = b.hasAttribute('data-full'); S.left = +b.getAttribute('data-left');
						el.querySelectorAll('.bk-slot').forEach(function (x) { x.classList.toggle('on', x === b); });
						setBar({ label: svc.name + ' · ' + when(S.slot, tz()), total: priceText(svc), sub: S.full ? 'Waitlist' : (+svc.capacity > 1 ? S.left + (S.left === 1 ? ' seat left' : ' seats left') : ''), cta: 'Continue', go: function () { paintForm(); top(); } });
					};
				});
			}).catch(function (e) { var el = box.querySelector('[data-slots]'); if (el) el.innerHTML = '<p class="form-error">' + esc(e.message) + '</p>'; });
		};
		var paintForm = function () {
			var svc = S.service, group = +svc.capacity > 1, maxP = group ? Math.max(1, S.full ? +svc.capacity : Math.min(+svc.capacity, S.left || 1)) : 1;
			step(3);
			var total = function () { return +svc.price ? inr(+svc.price * S.party) : 'Free'; };
			box.innerHTML = '<button type="button" class="bk-back" data-back>← Change time</button>' +
				'<div class="bk-card bk-summary"><div><span class="bk-muted small">Your booking</span><h2>' + esc(svc.name) + '</h2><p>' + esc(when(S.slot, tz())) + ' · ' + esc(dur(svc.duration_min)) + '</p></div><span class="bk-price bk-price-lg">' + priceText(svc) + (+svc.price && group ? '<small>per person</small>' : '') + '</span></div>' +
				(S.full ? '<p class="bk-alert">This time is full. You will join the waitlist and be told if a place opens. Nothing is charged now.</p>' : '') +
				'<form data-f class="bk-card" novalidate>' +
				(group ? '<div class="field"><span>People</span><div class="bk-qty"><button type="button" data-qm aria-label="One fewer">−</button><output data-qv>1</output><button type="button" data-qp aria-label="One more">+</button><small class="bk-muted">' + (S.full ? 'waitlist' : maxP + ' seat' + (maxP === 1 ? '' : 's') + ' available') + '</small></div></div>' : '') +
				'<div class="bk-row2"><label class="field"><span>Your name</span><input name="name" autocomplete="name" required></label><label class="field"><span>Phone' + (C.data.whatsapp ? ' *' : '') + '</span><input name="phone" type="tel" autocomplete="tel"' + (C.data.whatsapp ? ' required' : '') + '></label></div>' +
				(C.data.whatsapp ? '<p class="bk-muted small">' + esc(C.data.business.name) + ' may message this number on WhatsApp about your booking and invoice.</p>' : '') + '<label class="field"><span>Email</span><input name="email" type="email" autocomplete="email"></label>' +
				qs.map(function (q) { var n = 'q_' + q.key, r = q.required ? ' required' : ''; return '<label class="field"><span>' + esc(q.label) + (q.required ? ' *' : '') + '</span>' + (q.type === 'select' ? '<select name="' + n + '"' + r + '><option value=""></option>' + q.options.map(function (o) { return '<option>' + esc(o) + '</option>'; }).join('') + '</select>' : q.type === 'textarea' ? '<textarea name="' + n + '" rows="2"' + r + '></textarea>' : '<input name="' + n + '"' + r + '>') + '</label>'; }).join('') +
				'<label class="field"><span>Notes</span><textarea name="notes" rows="2"></textarea></label><label class="hp" aria-hidden="true">Website <input name="website" tabindex="-1" autocomplete="off"></label>' +
				'<label class="check"><input type="checkbox" name="marketing_consent"> <span>Send me offers from ' + esc(C.data.business.name) + '. Optional.</span></label>' +
				'<p class="bk-muted small">' + esc(C.data.business.name) + ' receives these details to manage your booking. NeonDigital, which runs this booking platform, processes them only to provide, secure and support the service.</p>' +
				'<div class="form-error" role="alert" hidden></div></form>';
			box.querySelector('[data-back]').onclick = function () { paintSlots(); top(); };
			var f = box.querySelector('[data-f]'), err = f.querySelector('.form-error');
			var paintQty = function () {
				var v = box.querySelector('[data-qv]');
				if (v) { v.textContent = S.party; box.querySelector('[data-qm]').disabled = S.party <= 1; box.querySelector('[data-qp]').disabled = S.party >= maxP; }
				setBar({ label: 'Total' + (group && +svc.price ? ' · ' + S.party + ' × ' + inr(svc.price) : ''), total: total(), sub: when(S.slot, tz()), cta: S.full ? 'Join the waitlist' : (svc.pay_mode === 'online' && +svc.price ? 'Book and pay' : 'Confirm Booking'), go: submit });
			};
			if (group) {
				box.querySelector('[data-qm]').onclick = function () { if (S.party > 1) { S.party--; paintQty(); } };
				box.querySelector('[data-qp]').onclick = function () { if (S.party < maxP) { S.party++; paintQty(); } };
			}
			var submit = function () {
				err.hidden = true;
				var d = { service_id: svc.id, start: S.slot, staff_id: S.staff || 0, space_id: S.space || 0, waitlist: S.full, idempotency_key: S.key, party_size: S.party, custom: {} };
				new FormData(f).forEach(function (v, k) { if (k.indexOf('q_') === 0) d.custom[k.slice(2)] = v; else d[k] = v; });
				if (!String(d.name || '').trim()) { err.textContent = 'Please enter your name.'; err.hidden = false; f.name.focus(); return; }
				if (!String(d.phone || '').trim() && !String(d.email || '').trim()) { err.textContent = 'Please enter a phone number or email.'; err.hidden = false; return; }
				var miss = qs.filter(function (q) { return q.required && !String(d.custom[q.key] || '').trim(); })[0];
				if (miss) { err.textContent = 'Please answer: ' + miss.label; err.hidden = false; return; }
				d.marketing_consent = !!d.marketing_consent;
				if (C.data.whatsapp && !String(d.phone || '').trim()) { err.textContent = 'Please enter your phone number.'; err.hidden = false; return; }
				d.whatsapp_consent = !!C.data.whatsapp;
				barGo.disabled = true;
				barGo.textContent = 'Booking…';
				req('public/book/' + C.slug, d).then(function (r) {
					setBar(null);
					step(3);
					if (r.pay_url) { // Pay online to confirm: the seat is held until hold_expires_at.
						box.innerHTML = '<div class="bk-card bk-done"><h2>Your seat is held</h2><p><b>' + esc(svc.name) + '</b><br>' + esc(when(S.slot, tz())) + (group ? '<br>' + S.party + ' ' + (S.party === 1 ? 'person' : 'people') : '') + '</p><p class="bk-alert">Pay ' + inr(r.amount) + ' by ' + esc(new Date(r.hold_expires_at).toLocaleTimeString('en-IN', { timeZone: tz(), hour: 'numeric', minute: '2-digit' })) + ' to confirm. After that the seat is released.</p><p><a class="bk-btn bk-btn-lg" href="' + esc(r.pay_url) + '">Pay now</a></p></div>';
						top();
						return;
					}
					box.innerHTML = '<div class="bk-card bk-done"><span class="bk-tick" aria-hidden="true">✓</span><h2>' + (r.status === 'waitlisted' ? 'You are on the waitlist' : 'Booking confirmed') + '</h2><p><b>' + esc(svc.name) + '</b><br>' + esc(when(S.slot, tz())) + (group ? '<br>' + S.party + ' ' + (S.party === 1 ? 'person' : 'people') : '') + (+svc.price ? '<br>Total ' + total() : '') + '</p>' + (r.manage_url ? '<p><a class="bk-btn" href="' + esc(r.manage_url) + '">Reschedule or cancel</a></p><p class="bk-muted small">Keep this link. It is the only way to change this booking online.</p>' : '') + '</div>';
					top();
				}).catch(function (x) {
					err.textContent = x.message; err.hidden = false;
					paintQty();
					if (/just taken|not available/i.test(x.message)) { S.key = Math.random().toString(36).slice(2) + Date.now().toString(36); }
				});
			};
			f.addEventListener('submit', function (e) { e.preventDefault(); submit(); });
			paintQty();
		};
		paintServices();

		var enq = document.getElementById('enquiry');
		if (enq) {
			enq.addEventListener('submit', function (e) {
				e.preventDefault();
				var d = {}, err = enq.querySelector('.form-error');
				err.hidden = true;
				new FormData(enq).forEach(function (v, k) { d[k] = v; });
				req('public/enquiry/' + C.slug, d).then(function () { enq.innerHTML = '<p>Thanks. We will be in touch.</p>'; }).catch(function (x) { err.textContent = x.message; err.hidden = false; });
			});
		}
	}

	/* ---- Manage ---- */
	var mg = document.getElementById('manage');
	if (mg) {
		var load = function () {
			req('public/manage/' + C.token).then(function (b) {
				var zone = b.business.timezone, open = b.status === 'confirmed' || b.status === 'pending';
				mg.innerHTML = '<h1>' + esc(b.business.name) + '</h1><p>' + esc(b.service ? b.service.name : '') + '<br><b>' + esc(when(b.starts_at.replace(' ', 'T') + 'Z', zone)) + '</b></p><p class="muted">Status: ' + esc(b.status) + '</p>' +
					(open ? (b.can_reschedule && b.slug ? '<label class="field"><span>New date</span><input type="date" data-d min="' + dayStr(0, zone) + '"></label><div data-sl class="slots"></div>' : '') + '<p><button class="btn" data-cancel>Cancel booking</button></p><div class="form-error" role="alert" hidden></div>' : '');
				var err = mg.querySelector('.form-error');
				var show = function (x) { err.textContent = x.message; err.hidden = false; };
				var c = mg.querySelector('[data-cancel]');
				if (c) c.onclick = function () {
					if (window.confirm('Cancel this booking?')) req('public/manage/' + C.token, { action: 'cancel' }).then(load).catch(show);
				};
				var dd = mg.querySelector('[data-d]');
				if (dd) dd.onchange = function () {
					req('public/book/' + b.slug + '/slots?service_id=' + b.service.id + '&date=' + encodeURIComponent(dd.value)).then(function (r) {
						var el = mg.querySelector('[data-sl]');
						el.innerHTML = r.slots.length ? r.slots.map(function (x) { return '<button type="button" class="slot" data-t="' + esc(x.start) + '">' + esc(x.label) + '</button>'; }).join('') : '<p class="muted">No times on this day.</p>';
						el.querySelectorAll('[data-t]').forEach(function (t) {
							t.onclick = function () { req('public/manage/' + C.token, { action: 'reschedule', start: t.getAttribute('data-t') }).then(load).catch(show); };
						});
					}).catch(show);
				};
			}).catch(function (e) { mg.innerHTML = '<p class="form-error">' + esc(e.message) + '</p>'; });
		};
		load();
	}
})();
