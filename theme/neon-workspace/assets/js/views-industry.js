/* Business-type modules: records, POS, kitchen display, front desk, check-in, attendance;
 * the business dashboard strip; Settings for business type, modules, conversion and deletion. */
(function () {
	'use strict';
	var A = window.App, S = A.S, api = A.api, esc = A.esc, V = A.views;
	var DAYS = [['mon', 'Mon'], ['tue', 'Tue'], ['wed', 'Wed'], ['thu', 'Thu'], ['fri', 'Fri'], ['sat', 'Sat'], ['sun', 'Sun']];
	var nice = function (s) { return String(s == null ? '' : s).replace(/_/g, ' ').replace(/^\w/, function (c) { return c.toUpperCase(); }); };
	var money = function (v) { return A.inr(v); };

	A.industry = function () {
		if (S.ind) return Promise.resolve(S.ind);
		return api('industry').then(function (r) { S.ind = r; return r; });
	};
	var lookups = {};
	function lookup(kind, entity) {
		var k = kind + ':' + (entity || '');
		if (lookups[k]) return lookups[k];
		var p = kind === 'record' ? api('records', { params: { entity: entity } }).then(function (r) { return r.items.map(function (x) { return { id: x.id, name: x.code && x.title ? x.code + ' · ' + x.title : (x.title || x.code || '#' + x.id) }; }); })
			: kind === 'space' ? api('spaces').then(function (r) { return r.items.map(function (x) { return { id: x.id, name: x.name }; }); })
			: kind === 'customer' ? api('customers', { params: { per_page: 200 } }).then(function (r) { return r.items.map(function (x) { return { id: x.id, name: x.name + (x.phone ? ' · ' + x.phone : '') }; }); })
			: Promise.resolve(S.boot.members.map(function (m) { return { id: m.id, name: m.name }; }));
		lookups[k] = p.catch(function () { return []; });
		setTimeout(function () { delete lookups[k]; }, 30000);
		return lookups[k];
	}

	/* ---------------------------- Record forms ---------------------------- */
	function fieldInput(f, v, opts) {
		var id = 'f_' + f.key, val = v == null ? (f.def == null ? '' : f.def) : v, req = f.req ? ' required' : '';
		var lab = '<span>' + esc(f.label) + (f.req ? ' *' : '') + '</span>';
		switch (f.type) {
			case 'textarea': return '<label class="field span-2">' + lab + '<textarea name="' + f.key + '" rows="3">' + esc(val) + '</textarea></label>';
			case 'bool': return '<label class="check"><input type="checkbox" name="' + f.key + '"' + (val && val !== '0' ? ' checked' : '') + '> <span>' + esc(f.label) + '</span></label>';
			case 'days': return '<fieldset class="day-pick span-2"><legend>' + esc(f.label) + '</legend><div class="day-chips">' + DAYS.map(function (d) { return '<label class="chip"><input type="checkbox" data-day="' + f.key + '" value="' + d[0] + '"' + ((val || []).indexOf(d[0]) > -1 ? ' checked' : '') + '><span>' + d[1] + '</span></label>'; }).join('') + '</div></fieldset>';
			case 'pairs': return '<div class="field span-2">' + lab + '<div class="pairs" data-pairs="' + f.key + '">' + (val || []).map(function (x) { return pairRow(x, opts[f.key]); }).join('') + '</div><button type="button" class="btn btn-sm" data-addpair="' + f.key + '">Add</button></div>';
			case 'select':
				var list = (f.opts || []).concat((f.saved || []).filter(function (x) { return (f.opts || []).indexOf(x) < 0; }));
				if (f.list) return '<label class="field">' + lab + '<input name="' + f.key + '" list="' + id + '" value="' + esc(val) + '" placeholder="Choose or type your own"' + req + '><datalist id="' + id + '">' + list.map(function (o) { return '<option value="' + esc(o) + '">'; }).join('') + '</datalist></label>';
				return '<label class="field">' + lab + '<select name="' + f.key + '"' + req + '><option value=""></option>' + list.map(function (o) { return '<option value="' + esc(o) + '"' + (String(o) === String(val) ? ' selected' : '') + '>' + esc(nice(o)) + '</option>'; }).join('') + '</select></label>';
			case 'record': case 'staff': case 'space': case 'customer':
				return '<label class="field">' + lab + '<select name="' + f.key + '"' + req + '><option value="">—</option>' + (opts[f.key] || []).map(function (o) { return '<option value="' + o.id + '"' + (+o.id === +val ? ' selected' : '') + '>' + esc(o.name) + '</option>'; }).join('') + '</select></label>';
			default:
				var t = { number: 'number', money: 'number', date: 'date', time: 'time', email: 'email', phone: 'tel', url: 'url', image: 'url' }[f.type] || 'text';
				return '<label class="field">' + lab + '<input name="' + f.key + '" type="' + t + '" value="' + esc(val) + '"' + (f.type === 'money' ? ' step="0.01" min="0"' : f.type === 'number' ? ' step="any"' : '') + (f.readonly ? ' readonly' : '') + req + '></label>';
		}
	}
	function pairRow(p, pick) { p = p || {}; return '<div class="pair">' + (pick ? '<select data-pn><option value="">Choose</option>' + pick.map(function (o) { return '<option value="' + o.id + '"' + (String(o.id) === String(p.name) ? ' selected' : '') + '>' + esc(o.name) + '</option>'; }).join('') + '</select>' : '<input data-pn placeholder="Name" value="' + esc(p.name || '') + '">') + '<input data-pp type="number" step="any" placeholder="Value" value="' + esc(p.price == null ? '' : p.price) + '"><button type="button" class="icon-btn" data-rmpair aria-label="Remove">' + A.I.x + '</button></div>'; }
	function readFields(root, fields) {
		var o = {};
		fields.forEach(function (f) {
			if (f.readonly) return;
			if (f.type === 'days') { o[f.key] = Array.prototype.map.call(root.querySelectorAll('[data-day="' + f.key + '"]:checked'), function (i) { return i.value; }); return; }
			if (f.type === 'pairs') { o[f.key] = Array.prototype.map.call(root.querySelectorAll('[data-pairs="' + f.key + '"] .pair'), function (r) { return { name: r.querySelector('[data-pn]').value, price: r.querySelector('[data-pp]').value }; }).filter(function (p) { return p.name; }); return; }
			var i = root.querySelector('[name="' + f.key + '"]');
			if (!i) return;
			o[f.key] = f.type === 'bool' ? i.checked : i.value;
		});
		return o;
	}
	A.recordForm = function (ent, rec, done) {
		rec = rec || {};
		var need = ent.fields.filter(function (f) { return ['record', 'staff', 'space', 'customer'].indexOf(f.type) > -1 || f.pick; });
		var canW = A.can(moduleOf(ent).write);
		Promise.all(need.map(function (f) { return f.pick ? lookup('record', f.pick) : lookup(f.type, f.entity); })).then(function (lists) {
			var opts = {};
			need.forEach(function (f, i) { opts[f.key] = lists[i]; });
			var m = A.modal({ title: (rec.id ? '' : 'New ') + ent.label.toLowerCase().replace(/^\w/, function (c) { return c.toUpperCase(); }) + (rec.id && (rec.title || rec.code) ? ': ' + (rec.title || rec.code) : ''), size: 'lg',
				body: '<form novalidate><div class="form-grid">' + ent.fields.map(function (f) { return fieldInput(f, rec[f.key], opts); }).join('') + '</div><div data-extra></div><div class="form-error" hidden role="alert"></div></form>',
				foot: (rec.id && canW && !ent.immutable ? '<button class="btn btn-danger" data-del>Delete</button>' : '') + '<button class="btn" data-c>Close</button>' + (canW && !(rec.id && ent.immutable) ? '<button class="btn btn-primary" data-s>Save</button>' : '') });
			var err = m.el.querySelector('.form-error');
			m.el.querySelector('[data-c]').onclick = m.close;
			m.el.addEventListener('click', function (e) {
				var ap = e.target.closest('[data-addpair]');
				if (ap) { m.el.querySelector('[data-pairs="' + ap.getAttribute('data-addpair') + '"]').insertAdjacentHTML('beforeend', pairRow({}, opts[ap.getAttribute('data-addpair')])); }
				var rp = e.target.closest('[data-rmpair]');
				if (rp) rp.closest('.pair').remove();
			});
			var save = function (extra) {
				err.hidden = true;
				var body = Object.assign(readFields(m.el, ent.fields), extra || {});
				if (!rec.id) body.entity = ent.key;
				return api('records' + (rec.id ? '/' + rec.id : ''), { method: rec.id ? 'PUT' : 'POST', body: body }).then(function (x) { m.close(); A.toast('Saved'); if (done) done(x); }).catch(function (x) {
					if (x.status === 409 && x.data && x.data.conflict && (S.boot.role === 'owner' || S.boot.role === 'admin' || S.boot.role === 'manager')) {
						return A.ask('Override this clash?', x.message + ' Give a reason to save anyway (recorded in the audit log).', { ok: 'Override and save' }).then(function (why) { if (why) return save({ override: true, override_reason: why }); });
					}
					err.textContent = x.message; err.hidden = false;
				});
			};
			var s = m.el.querySelector('[data-s]');
			if (s) s.onclick = function () { save(); };
			var d = m.el.querySelector('[data-del]');
			if (d) d.onclick = function () { A.confirm('Delete this?', 'It will be removed from your lists.', 'Delete', true).then(function (y) { if (y) api('records/' + rec.id, { method: 'DELETE', body: {} }).then(function () { m.close(); if (done) done(); }).catch(A.fail); }); };
			recordExtras(ent, rec, m.el.querySelector('[data-extra]'));
		}).catch(A.fail);
	};
	function moduleOf(ent) { return ((S.ind && S.ind.modules) || []).filter(function (m) { return m.key === ent.module; })[0] || { write: 'crm.write', read: 'crm.read' }; }

	/* Per-entity actions inside the form: QR codes, member cards, attendance history. */
	function recordExtras(ent, rec, box) {
		if (!rec.id) return;
		if (ent.key === 'dining_table' && rec.code) {
			var url = window.NW.home + 'menu/' + S.boot.workspace.slug + '?table=' + rec.code;
			box.innerHTML = '<section class="card card-pad qr-box"><h3>Table QR menu</h3><p class="muted small">Guests scan this to see the menu and order to this table. Print it and place it on the table.</p><div data-q></div><p class="small"><a href="' + esc(url) + '" target="_blank" rel="noopener">' + esc(url) + '</a></p><button type="button" class="btn btn-sm" data-pr>Print</button></section>';
			box.querySelector('[data-q]').appendChild(A.qr(url, null, 5));
			box.querySelector('[data-pr]').onclick = function () { printNode('<h2>' + esc(rec.title) + '</h2><p>Scan to see the menu and order</p>', A.qr(url, null, 8)); };
		}
		if (ent.key === 'membership' && rec.code) {
			box.innerHTML = '<section class="member-card"><b>' + esc(S.boot.workspace.name) + '</b><div style="font-size:20px;margin:6px 0">' + esc(rec.title) + '</div><div class="mono">' + esc(rec.code) + '</div><div class="small" style="opacity:.75">Valid until ' + esc(A.fmtD(rec.end_date)) + ' · ' + esc(nice(rec.state)) + '</div><div data-q style="margin-top:10px"></div></section><button type="button" class="btn btn-sm" data-pr style="margin-top:8px">Print member card</button>';
			box.querySelector('[data-q]').appendChild(A.qr(rec.code, null, 4));
			box.querySelector('[data-pr]').onclick = function () { printNode('<h2>' + esc(rec.title) + '</h2><p class="mono">' + esc(rec.code) + '</p>', A.qr(rec.code, null, 7)); };
		}
		if (ent.key === 'student') {
			box.innerHTML = '<details class="card card-pad"><summary>Attendance history</summary><div data-h>' + A.loading(2) + '</div></details>';
			box.querySelector('details').ontoggle = function () {
				api('attendance/student/' + rec.id).then(function (r) { box.querySelector('[data-h]').innerHTML = (r.rate != null ? '<p><b>' + r.rate + '%</b> attended</p>' : '') + (r.items.length ? r.items.map(function (a) { return '<div class="row"><div class="grow">' + esc(A.fmtD(a.session_date)) + ' · ' + esc(a.batch) + '</div>' + A.badge(a.status) + '</div>'; }).join('') : '<p class="muted">No attendance yet.</p>'); }).catch(A.fail);
			};
		}
	}
	function printNode(head, canvas) {
		var w = window.open('', '_blank', 'width=480,height=640');
		if (!w) { A.toast('Allow pop-ups to print.', true); return; }
		w.document.write('<!doctype html><title>Print</title><body style="font-family:sans-serif;text-align:center;padding:24px">' + head + '<img src="' + canvas.toDataURL() + '" style="width:300px"><script>setTimeout(function(){print()},300)<\/script></body>');
		w.document.close();
	}

	/* ---------------------------- Module page ---------------------------- */
	V.module = function (el, m) {
		return A.industry().then(function (ind) {
			var mod = (ind.modules || []).filter(function (x) { return x.key === m[1]; })[0];
			if (!mod || !mod.enabled) { A.setTitle('Not available'); el.innerHTML = '<section class="card card-pad">' + A.empty('Not part of this Workspace', 'This module is not used by this business type, or it is switched off in Settings.') + '</section>'; return; }
			A.setTitle(mod.label);
			if (!A.can(mod.read)) { el.innerHTML = '<section class="card card-pad">' + A.empty('No access', 'Your role does not include this module.') + '</section>'; return; }
			var views = { pos: posView, kds: kdsView, stays: staysView, checkin: checkinView, attendance: attendanceView };
			if (views[mod.view]) return views[mod.view](el, mod, ind);
			return recordsView(el, mod, ind);
		});
	};

	function cellFor(f, x, lk) {
		var v = x[f.key];
		if (v == null || v === '') return '';
		if (f.type === 'money') return money(v);
		if (f.type === 'date') return esc(A.fmtD(v));
		if (f.type === 'days') return esc((v || []).map(function (d) { return d.slice(0, 1).toUpperCase() + d.slice(1); }).join(', '));
		if (f.key === 'status') return A.badge(x.state && x.state !== v ? x.state : v) + (x.overdue ? ' ' + A.badge('overdue') : '');
		if (f.type === 'select') return esc(nice(v));
		if (['record', 'staff', 'space', 'customer'].indexOf(f.type) > -1) { var o = (lk[f.key] || []).filter(function (y) { return +y.id === +v; })[0]; return esc(o ? o.name : '#' + v); }
		if (f.key === 'title' || f.key === 'code') return '<span class="rec-cell-main">' + esc(v) + '</span>';
		return esc(v);
	}
	function recordsView(el, mod, ind) {
		var ents = mod.entities.map(function (k) { return ind.entities[k]; }).filter(Boolean);
		var cur = ents.filter(function (e) { return e.key === (A.query || {}).entity; })[0] || ents[0];
		var f = { q: '', status: '' };
		el.innerHTML = (ents.length > 1 ? '<div class="tabs rec-tabs">' + ents.map(function (e) { return '<a href="#/m/' + mod.key + '?entity=' + e.key + '" class="' + (e === cur ? 'on' : '') + '">' + esc(e.plural) + '</a>'; }).join('') + '</div>' : '') +
			'<div class="toolbar"><input type="search" class="grow" data-q placeholder="Search ' + esc(cur.plural.toLowerCase()) + '" aria-label="Search">' + (function () { var st = cur.fields.filter(function (x) { return x.key === 'status'; })[0]; return st ? '<select data-st aria-label="Status"><option value="">All statuses</option>' + st.opts.map(function (o) { return '<option value="' + esc(o) + '">' + esc(nice(o)) + '</option>'; }).join('') + '</select>' : ''; })() + ((mod.key === 'salon_ops' || mod.key === 'training') && (A.can('reports.read') || A.can('finance.read')) ? '<button class="btn" data-pay>' + (mod.key === 'salon_ops' ? 'Commission report' : 'Trainer pay') + '</button>' : '') + (A.can(mod.write) ? '<button class="btn btn-primary" data-add>' + A.I.plus + ' Add ' + esc(cur.label.toLowerCase()) + '</button>' : '') + '</div><section class="card card-pad" data-l>' + A.loading(5) + '</section>';
		var list = el.querySelector('[data-l]'), cols = cur.fields.filter(function (x) { return x.show; }).slice(0, 7);
		var rel = cols.filter(function (x) { return ['record', 'staff', 'space', 'customer'].indexOf(x.type) > -1; });
		var load = function () {
			return Promise.all([api('records', { params: { entity: cur.key, q: f.q, status: f.status } })].concat(rel.map(function (x) { return lookup(x.type, x.entity); }))).then(function (r) {
				var lk = {};
				rel.forEach(function (x, i) { lk[x.key] = r[i + 1]; });
				var items = r[0].items;
				list.innerHTML = items.length ? A.tbl(cols.map(function (c) { return [c.label, function (x) { return cellFor(c, x, lk); }]; }), items, function (x) { return 'class="click" data-o="' + x.id + '"'; }) : A.empty('No ' + cur.plural.toLowerCase() + ' yet', A.can(mod.write) ? 'Add the first one with the button above.' : '');
				list.onclick = function (e) { var o = e.target.closest('[data-o]'); if (o) A.recordForm(cur, items.filter(function (x) { return +x.id === +o.getAttribute('data-o'); })[0], load); };
				var want = +((A.query || {}).open || 0);
				if (want) { A.query.open = 0; var hit = items.filter(function (x) { return +x.id === want; })[0]; if (hit) A.recordForm(cur, hit, load); }
			});
		};
		var deb;
		el.querySelector('[data-q]').oninput = function (e) { clearTimeout(deb); deb = setTimeout(function () { f.q = e.target.value; load().catch(A.fail); }, 300); };
		var st = el.querySelector('[data-st]');
		if (st) st.onchange = function () { f.status = st.value; load().catch(A.fail); };
		var add = el.querySelector('[data-add]');
		if (add) add.onclick = function () { A.recordForm(cur, null, load); };
		var pay = el.querySelector('[data-pay]');
		if (pay) pay.onclick = function () { payReport(mod.key === 'salon_ops' ? 'commissions' : 'trainer-pay', load); };
		return load();
	}

	/* Commission and trainer pay, calculated on the server from completed work and the rules entered. */
	function payReport(kind, done) {
		var today = A.localDay(), from = today.slice(0, 8) + '01';
		var m = A.modal({ title: kind === 'commissions' ? 'Staff commissions' : 'Trainer pay', size: 'lg', body: '<div class="toolbar"><input type="date" data-f value="' + from + '" aria-label="From"><input type="date" data-t value="' + today + '" aria-label="To"><button class="btn" data-go>Calculate</button></div><div data-r>' + A.loading(3) + '</div>', foot: (kind === 'trainer-pay' ? '<button class="btn btn-primary" data-rec>Record pending payouts</button>' : '') + '<button class="btn" data-x2>Close</button>' });
		var q = function (s) { return m.el.querySelector(s); };
		var run = function () {
			api('industry/' + kind, { params: { from: q('[data-f]').value, to: q('[data-t]').value } }).then(function (r) {
				var cols = kind === 'commissions' ? [['Staff', function (x) { return esc(x.name); }], ['Appointments', function (x) { return x.appointments + ' · ' + money(x.appointment_revenue); }], ['Walk-ins', function (x) { return x.walkins + ' · ' + money(x.walkin_revenue); }], ['Revenue', function (x) { return money(x.revenue); }], ['Rate', function (x) { return x.rate == null ? '<span class="muted">no rule</span>' : x.rate + '%'; }], ['Commission', function (x) { return x.commission == null ? '—' : '<b>' + money(x.commission) + '</b>'; }]]
					: [['Trainer', function (x) { return esc(x.name); }], ['Sessions', function (x) { return x.sessions + ' × ' + money(x.per_session) + ' = ' + money(x.session_pay); }], ['Member fees', function (x) { return x.members + ' members · ' + money(x.member_fees) + ' × ' + x.share_pct + '% = ' + money(x.share_pay); }], ['Fixed', function (x) { return money(x.fixed); }], ['Total', function (x) { return '<b>' + money(x.total) + '</b>'; }]];
				q('[data-r]').innerHTML = (r.items.length ? A.tbl(cols, r.items) + '<p class="right"><b>Total ' + money(r.total) + '</b></p>' : A.empty('Nothing to pay', kind === 'commissions' ? 'No completed appointments or walk-ins with a stylist in this period.' : 'Add a trainer pay rule first.')) + (r.missing_rules ? '<p class="alert alert-warn">' + r.missing_rules + ' staff member(s) earned revenue but have no commission rule.</p>' : '') + '<p class="muted small">Counts only completed appointments and sessions' + (kind === 'commissions' ? ' and walk-ins marked done' : ', and fees members actually paid') + ', from ' + esc(A.fmtD(r.from)) + ' to ' + esc(A.fmtD(r.to)) + '.</p>';
			}).catch(function (e) { q('[data-r]').innerHTML = '<p class="form-error">' + esc(e.message) + '</p>'; });
		};
		q('[data-go]').onclick = run;
		q('[data-x2]').onclick = m.close;
		var rec = q('[data-rec]');
		if (rec) rec.onclick = function () { api('industry/trainer-pay', { method: 'POST', body: { from: q('[data-f]').value, to: q('[data-t]').value } }).then(function (x) { A.toast(x.created + ' payout(s) recorded' + (x.already ? ', ' + x.already + ' already there' : '')); m.close(); if (done) done(); }).catch(A.fail); };
		run();
	}

	/* ---------------------------- POS ---------------------------- */
	function posView(el, mod) {
		var st = { order: +((A.query || {}).order || 0) };
		var floor = function () {
			return api('pos/floor').then(function (r) {
				el.innerHTML = '<div class="toolbar"><div class="grow muted small">Tap a free table to open an order, or a busy one to add items, send to the kitchen, bill and take payment.</div><button class="btn" data-ta>Takeaway</button><button class="btn" data-de>Delivery</button><a class="btn" href="#/m/tables?entity=dining_table">Manage tables</a></div>' +
					(r.tables.length ? (r.areas.length ? r.areas : [{ id: null, title: '' }]).concat(r.areas.length ? [{ id: 0, title: 'Other tables' }] : []).map(function (a) {
						var ts = r.tables.filter(function (t) { return r.areas.length ? (a.id === 0 ? !t.area_id : +t.area_id === +a.id) : true; });
						return ts.length ? (a.title ? '<h2 style="margin:14px 0 8px">' + esc(a.title) + '</h2>' : '') + '<div class="pos-floor">' + ts.map(function (t) {
							return '<button class="pos-table' + (t.order_id ? (t.order_status === 'billed' ? ' billed' : ' busy') : '') + (t.reservations.length ? ' resv' : '') + '" data-t="' + t.id + '"' + (t.order_id ? ' data-ord="' + t.order_id + '"' : '') + '><b>' + esc(t.title) + '</b><span class="muted small">' + (+t.seats ? t.seats + ' seats' : '') + '</span>' + (t.order_id ? '<span>' + esc(nice(t.order_status)) + ' · ' + money(t.order_total) + '</span>' : '<span class="muted small">Free</span>') + (t.reservations.length ? '<span class="small">Reserved ' + esc(t.reservations.map(function (x) { return x.time; }).join(', ')) + '</span>' : '') + '</button>';
						}).join('') + '</div>' : '';
					}).join('') : '<section class="card card-pad">' + A.empty('No tables yet', 'Add your tables under Tables & Reservations. Takeaway and delivery work without tables.') + '</section>') +
					(r.open_orders.filter(function (o) { return !o.table_ids.length; }).length ? '<h2 style="margin:18px 0 8px">Takeaway and delivery</h2><section class="card card-pad">' + r.open_orders.filter(function (o) { return !o.table_ids.length; }).map(function (o) { return '<div class="row click" data-ord="' + o.id + '" style="cursor:pointer"><div class="grow"><b>' + esc(o.order_no) + '</b> <span class="muted small">' + esc(nice(o.channel)) + ' ' + esc(o.customer_name) + '</span></div>' + A.badge(o.status) + '<b>' + money(o.total) + '</b></div>'; }).join('') + '</section>' : '');
				el.onclick = function (e) {
					var b = e.target.closest('[data-ord]');
					if (b) { st.order = +b.getAttribute('data-ord'); orderScreen(); return; }
					var t = e.target.closest('[data-t]');
					if (t && A.can(mod.write)) openOrder({ channel: 'dine_in', table_ids: [+t.getAttribute('data-t')] });
				};
				el.querySelector('[data-ta]').onclick = function () { openOrder({ channel: 'takeaway' }); };
				el.querySelector('[data-de]').onclick = function () { openOrder({ channel: 'delivery' }); };
			});
		};
		var openOrder = function (body) {
			A.formModal({ title: body.channel === 'dine_in' ? 'Open table' : 'New ' + body.channel + ' order', fields: [{ k: 'guests', label: 'Guests', type: 'number', min: 0, value: body.channel === 'dine_in' ? 2 : 0 }, { k: 'customer_name', label: 'Customer name', hint: 'optional' }, { k: 'phone', label: 'Phone', type: 'tel', hint: 'for loyalty and receipts' }],
				onSave: function (v) { return api('pos/orders', { method: 'POST', body: Object.assign(body, v, { idempotency_key: A.uuid() }) }).then(function (o) { st.order = o.id; orderScreen(); }); } });
		};
		var orderScreen = function () {
			A.go('/m/pos?order=' + st.order);
		};
		if (st.order) return orderView(el, st.order, mod, function () { A.go('/m/pos'); });
		return floor();
	}
	function orderView(el, id, mod, back) {
		var menu = null, cat = '';
		var draw = function (o) {
			var live = o.items.filter(function (i) { return i.status !== 'void'; });
			var canW = A.can(mod.write), open = o.status === 'open' || o.status === 'billed';
			el.innerHTML = '<div class="toolbar"><button class="btn" data-back>← Floor</button><h2 class="grow" style="margin:0">' + esc(o.order_no) + ' · ' + esc(o.tables.length ? 'Table ' + o.tables.join(' + ') : nice(o.channel)) + '</h2>' + A.badge(o.status) + '</div><div class="pos-layout"><section class="card card-pad" data-menu>' +
				(open && canW ? '<div class="tabs" data-cats><button class="' + (cat === '' ? 'on' : '') + '" data-cat="">All</button>' + menu.categories.map(function (c) { return '<button class="' + (+cat === +c.id ? 'on' : '') + '" data-cat="' + c.id + '">' + esc(c.name) + '</button>'; }).join('') + '</div><div class="menu-grid">' + menu.items.filter(function (x) { return cat === '' || +x.category_id === +cat; }).map(function (x) { return '<button class="menu-item' + (x.status !== 'available' ? ' out' : '') + '" data-mi="' + x.id + '"' + (x.status !== 'available' ? ' disabled' : '') + '>' + (x.image ? '<img src="' + A.safeUrl(x.image) + '" alt="">' : '') + '<b>' + (x.veg ? '<i class="veg ' + esc(x.veg) + '"></i>' : '') + esc(x.name) + '</b><span class="muted small">' + money(x.price) + (x.variants.length ? ' · sizes' : '') + (x.status !== 'available' ? ' · sold out' : '') + '</span></button>'; }).join('') + '</div>' : '<p class="muted">This order is ' + esc(o.status) + '.</p>') + '</section>' +
				'<section class="card card-pad pos-ticket"><h2>Order</h2>' + (o.items.length ? o.items.map(function (i) { return '<div class="ticket-line' + (i.status === 'void' ? ' void' : '') + '"><div><b>' + esc(i.name) + '</b>' + (i.variant ? ' <span class="muted small">' + esc(i.variant) + '</span>' : '') + ((i.addons || []).length ? '<div class="muted small">+ ' + esc(i.addons.join(', ')) + '</div>' : '') + '<div class="small">' + A.badge(i.status === 'new' ? 'not sent' : i.status) + '</div></div><span>× ' + (+i.qty) + '</span><span>' + money(i.amount) + (open && canW && i.status !== 'void' ? ' <button class="icon-btn" data-void="' + i.id + '" data-sent="' + (i.status !== 'new' ? 1 : 0) + '" aria-label="Remove">' + A.I.x + '</button>' : '') + '</span></div>'; }).join('') : '<p class="muted">No items yet. Tap the menu to add.</p>') +
				'<dl class="totals"><dt>Items</dt><dd>' + money(o.subtotal) + '</dd>' + (o.discount ? '<dt>Discount</dt><dd>−' + money(o.discount) + '</dd>' : '') + (o.service_charge ? '<dt>Service charge</dt><dd>' + money(o.service_charge) + '</dd>' : '') + (o.tax ? '<dt>GST</dt><dd>' + money(o.tax) + '</dd>' : '') + '<dt class="b">Total</dt><dd class="b">' + money(o.total) + '</dd>' + (o.paid ? '<dt>Paid</dt><dd>' + money(o.paid) + '</dd>' : '') + '</dl>' +
				(open && canW ? '<div class="toolbar">' + (o.unsent ? '<button class="btn btn-primary" data-kot>Send ' + o.unsent + ' to kitchen</button>' : '') + (live.length ? '<button class="btn" data-bill>' + (o.status === 'billed' ? 'Re-bill' : 'Bill') + '</button>' : '') + (o.status === 'billed' ? '<button class="btn btn-primary" data-pay>Take payment</button>' : '') + '</div><div class="toolbar"><button class="btn btn-sm" data-split>Split bill</button><button class="btn btn-sm" data-merge>Merge table</button><button class="btn btn-sm" data-move>Move table</button><button class="btn btn-sm btn-danger" data-cancel>Cancel order</button></div>' : '') +
				(o.invoice_id ? '<p class="small"><a href="#/invoices/' + o.invoice_id + '">Open the GST invoice and receipt</a></p>' : '') + '</section></div>';
			el.querySelector('[data-back]').onclick = back;
			el.onclick = function (e) {
				var c = e.target.closest('[data-cat]');
				if (c) { cat = c.getAttribute('data-cat'); draw(o); return; }
				var mi = e.target.closest('[data-mi]');
				if (mi) addItem(menu.items.filter(function (x) { return +x.id === +mi.getAttribute('data-mi'); })[0]);
				var vd = e.target.closest('[data-void]');
				if (vd) {
					var sent = vd.getAttribute('data-sent') === '1';
					(sent ? A.ask('Void a sent item', 'Reason (a manager must void items the kitchen already has)', { ok: 'Void' }) : Promise.resolve('')).then(function (why) {
						if (why === null) return;
						api('pos/orders/' + o.id + '/items/' + vd.getAttribute('data-void'), { method: 'PUT', body: { void: true, reason: why } }).then(draw).catch(A.fail);
					});
				}
			};
			var on = function (sel, fn) { var b = el.querySelector(sel); if (b) b.onclick = fn; };
			on('[data-kot]', function () { api('pos/orders/' + o.id + '/kot', { method: 'POST', body: {} }).then(function (x) { A.toast('Sent to the kitchen'); draw(x); }).catch(A.fail); });
			on('[data-bill]', function () {
				A.formModal({ title: 'Bill ' + o.order_no, fields: [{ k: 'discount_type', label: 'Discount type', type: 'select', opts: A.options([['amount', 'Amount (₹)'], ['percent', 'Percent']], 'amount') }, { k: 'discount', label: 'Discount', type: 'number', min: 0, value: 0, step: '0.01' }, { k: 'service_charge_pct', label: 'Service charge %', type: 'number', min: 0, value: 0, step: '0.5', hint: 'optional, up to 20%' }],
					onSave: function (v) { return api('pos/orders/' + o.id + '/bill', { method: 'POST', body: v }).then(draw); } });
			});
			on('[data-pay]', function () {
				var due = Math.round((o.total - o.paid) * 100) / 100, key = A.uuid();
				A.formModal({ title: 'Payment · ' + money(due) + ' due', fields: [{ k: 'm1', label: 'Method', type: 'select', opts: A.options([['cash', 'Cash'], ['upi', 'UPI'], ['card', 'Card'], ['wallet', 'Wallet'], ['other', 'Other']], 'cash') }, { k: 'a1', label: 'Amount', type: 'number', step: '0.01', value: due }, { k: 'm2', label: 'Second method (split payment)', type: 'select', opts: A.options([['cash', 'Cash'], ['upi', 'UPI'], ['card', 'Card']], '', 'None') }, { k: 'a2', label: 'Second amount', type: 'number', step: '0.01', value: '' }, { k: 'ref', label: 'Reference', hint: 'UPI or card reference' }],
					onSave: function (v) { var p = [{ method: v.m1, amount: +v.a1, reference: v.ref }]; if (v.m2 && +v.a2 > 0) p.push({ method: v.m2, amount: +v.a2 }); return api('pos/orders/' + o.id + '/pay', { method: 'POST', body: { payments: p, idempotency_key: key } }).then(function (x) { A.toast(x.status === 'paid' ? 'Paid. Table is free.' : 'Part payment recorded'); if (x.status === 'paid') back(); else draw(x); }); } });
			});
			on('[data-split]', function () {
				A.modal({ title: 'Move items to a new bill', body: '<p class="muted small">Choose items and how many to move.</p>' + live.map(function (i) { return '<div class="row"><div class="grow">' + esc(i.name) + ' × ' + (+i.qty) + '</div><input type="number" min="0" max="' + (+i.qty) + '" step="1" value="0" data-sq="' + i.id + '" style="width:90px"></div>'; }).join(''), foot: '<button class="btn btn-primary" data-go>Split</button>' }).el.querySelector('[data-go]').onclick = function (e) {
					var md = e.target.closest('.overlay'), items = Array.prototype.filter.call(md.querySelectorAll('[data-sq]'), function (x) { return +x.value > 0; }).map(function (x) { return { id: +x.getAttribute('data-sq'), qty: +x.value }; });
					api('pos/orders/' + o.id + '/split', { method: 'POST', body: { items: items } }).then(function (r) { md.remove(); A.toast('New bill ' + r.new_order.order_no); draw(r.order); }).catch(A.fail);
				};
			});
			on('[data-merge]', function () {
				api('pos/orders', { params: { status: 'active' } }).then(function (r) {
					var others = r.items.filter(function (x) { return x.id !== o.id; });
					if (!others.length) { A.toast('No other open order to merge.', true); return; }
					A.formModal({ title: 'Merge another order into this one', fields: [{ k: 'from_order_id', label: 'Order', type: 'select', opts: A.options(others.map(function (x) { return [x.id, x.order_no + ' · ' + (x.tables.join(', ') || nice(x.channel)) + ' · ' + money(x.total)]; }), '') }], onSave: function (v) { return api('pos/orders/' + o.id + '/merge', { method: 'POST', body: v }).then(draw); } });
				}).catch(A.fail);
			});
			on('[data-move]', function () {
				api('pos/floor').then(function (f) {
					A.formModal({ title: 'Move to another table', fields: [{ k: 't', label: 'Table', type: 'select', opts: A.options(f.tables.filter(function (t) { return !t.order_id; }).map(function (t) { return [t.id, t.title]; }), '') }], onSave: function (v) { return api('pos/orders/' + o.id, { method: 'PUT', body: { table_ids: [+v.t] } }).then(draw); } });
				}).catch(A.fail);
			});
			on('[data-cancel]', function () { A.ask('Cancel this order', 'Reason (required if food was sent to the kitchen)', { ok: 'Cancel order' }).then(function (why) { if (why !== null) api('pos/orders/' + o.id + '/cancel', { method: 'POST', body: { reason: why } }).then(back).catch(A.fail); }); });
		};
		var addItem = function (x) {
			if (!x) return;
			var go = function (b) { api('pos/orders/' + id + '/items', { method: 'POST', body: { items: [b] } }).then(draw).catch(A.fail); };
			if (!x.variants.length && !x.addons.length) { go({ menu_item_id: x.id, qty: 1 }); return; }
			A.formModal({ title: x.name, fields: [x.variants.length ? { k: 'variant', label: 'Size or variant', type: 'select', opts: A.options(x.variants.map(function (v) { return [v.name, v.name + ' · ' + money(v.price)]; }), x.variants[0].name) } : null, { k: 'qty', label: 'Quantity', type: 'number', min: 1, value: 1 }, { k: 'notes', label: 'Note for the kitchen' }].filter(Boolean),
				extra: x.addons.length ? '<fieldset class="day-pick"><legend>Add-ons</legend>' + x.addons.map(function (a) { return '<label class="check"><input type="checkbox" data-ad value="' + esc(a.name) + '"> ' + esc(a.name) + ' (+' + money(a.price) + ')</label>'; }).join('') + '</fieldset>' : '',
				onSave: function (v, md) { v.menu_item_id = x.id; v.addons = Array.prototype.map.call(md.el.querySelectorAll('[data-ad]:checked'), function (i) { return i.value; }); go(v); } });
		};
		return Promise.all([api('pos/menu'), api('pos/orders/' + id)]).then(function (r) { menu = r[0]; draw(r[1]); });
	}

	/* ---------------------------- Kitchen display ---------------------------- */
	function kdsView(el, mod, ind) {
		var station = '', timer;
		var NEXT = { new: ['preparing', 'Start'], preparing: ['ready', 'Ready'], ready: ['served', 'Served'] };
		var load = function () {
			if (!el.isConnected) { clearInterval(timer); return; }
			return api('pos/kds', { params: { station: station } }).then(function (r) {
				var head = '<div class="toolbar"><div class="seg">' + [['', 'All'], ['kitchen', 'Kitchen'], ['barista', 'Barista'], ['bar', 'Bar']].map(function (s) { return '<button class="' + (s[0] === station ? 'on' : '') + '" data-stn="' + s[0] + '">' + s[1] + '</button>'; }).join('') + '</div><span class="grow muted small">Refreshes every 10 seconds.</span></div>';
				el.innerHTML = head + (r.tickets.length ? '<div class="kds">' + r.tickets.map(function (k) { return '<article class="kot ' + k.status + '"><div class="kot-h"><b>KOT ' + k.kot_no + '</b><span>' + esc(k.tables.length ? 'Table ' + k.tables.join(' + ') : nice(k.channel)) + '<br><small class="muted">' + esc(A.fmtT(k.created_at)) + ' · ' + esc(nice(k.station)) + '</small></span></div><ul>' + k.items.map(function (i) { return '<li class="' + (i.void ? 'void' : '') + '"><b>' + (+i.qty) + ' ×</b> ' + esc(i.name) + (i.variant ? ' (' + esc(i.variant) + ')' : '') + ((i.addons || []).length ? '<div class="muted small">+ ' + esc(i.addons.join(', ')) + '</div>' : '') + (i.notes ? '<div class="small">' + esc(i.notes) + '</div>' : '') + '</li>'; }).join('') + '</ul>' + (A.can(mod.write) ? '<div class="kot-f"><button class="btn btn-primary btn-block" data-k="' + k.id + '" data-to="' + NEXT[k.status][0] + '">' + NEXT[k.status][1] + '</button></div>' : '') + '</article>'; }).join('') + '</div>' : '<section class="card card-pad">' + A.empty('No tickets', 'New kitchen and barista tickets appear here as soon as they are sent.') + '</section>');
				el.querySelectorAll('[data-stn]').forEach(function (b) { b.onclick = function () { station = b.getAttribute('data-stn'); load(); }; });
				el.querySelectorAll('[data-k]').forEach(function (b) { b.onclick = function () { api('pos/kots/' + b.getAttribute('data-k'), { method: 'PUT', body: { status: b.getAttribute('data-to') } }).then(load).catch(A.fail); }; });
			});
		};
		station = ind.station === 'barista' ? 'barista' : '';
		timer = setInterval(function () { if (!document.hidden) load(); }, 10000);
		return load();
	}

	/* ---------------------------- Hotel front desk ---------------------------- */
	function staysView(el, mod) {
		var from = A.localDay(), days = 14;
		var load = function () {
			return Promise.all([api('stays/grid', { params: { from: from, days: days } }), api('stays', { params: { on: A.localDay() } })]).then(function (r) {
				var g = r[0], dates = [];
				for (var i = 0; i < g.days; i++) dates.push(A.addDays(g.from, i));
				var today = A.localDay(), here = r[1].items;
				el.innerHTML = '<div class="toolbar"><button class="btn" data-pv>←</button><input type="date" data-from value="' + esc(from) + '" aria-label="From"><button class="btn" data-nx>→</button><span class="grow"></span>' + (A.can('settings.manage') ? '<button class="btn" data-gst>GST on room nights</button>' : '') + (A.can(mod.write) ? '<button class="btn btn-primary" data-new>' + A.I.plus + ' New reservation</button>' : '') + '</div>' +
					'<div class="grid" style="margin-bottom:14px"><section class="card card-pad"><h2>Arriving today</h2>' + (here.filter(function (s) { return s.status === 'reserved' && s.check_in === today; }).map(stayRow).join('') || '<p class="muted">No arrivals.</p>') + '</section><section class="card card-pad"><h2>In house</h2>' + (here.filter(function (s) { return s.status === 'checked_in'; }).map(stayRow).join('') || '<p class="muted">No guests checked in.</p>') + '</section></div>' +
					(g.rooms.length ? '<div class="stay-grid"><table><thead><tr><th class="room">Room</th>' + dates.map(function (d) { return '<th>' + esc(new Date(d + 'T12:00:00Z').toLocaleDateString('en-IN', { timeZone: 'UTC', weekday: 'short', day: 'numeric' })) + '</th>'; }).join('') + '</tr></thead><tbody>' + g.rooms.map(function (rm) {
						var cells = '', i = 0;
						while (i < dates.length) {
							var d = dates[i], s = g.stays.filter(function (x) { return +x.room_id === +rm.id && x.check_in <= d && x.check_out > d; })[0];
							if (s) { var span = 0; while (i + span < dates.length && dates[i + span] < s.check_out) span++; cells += '<td colspan="' + span + '"><span class="cell ' + s.status + '" data-s="' + s.id + '" title="' + esc(s.guest_name) + '">' + esc(s.guest_name) + '</span></td>'; i += span; }
							else { cells += '<td class="free" data-room="' + rm.id + '" data-d="' + d + '"></td>'; i++; }
						}
						return '<tr><th class="room">' + esc(rm.code) + ' <small class="muted">' + esc(nice(rm.status)) + '</small></th>' + cells + '</tr>';
					}).join('') + '</tbody></table></div>' : '<section class="card card-pad">' + A.empty('No rooms yet', 'Add room types and rooms under Rooms & Room Types.') + '</section>');
				el.querySelector('[data-pv]').onclick = function () { from = A.addDays(from, -7); load().catch(A.fail); };
				el.querySelector('[data-nx]').onclick = function () { from = A.addDays(from, 7); load().catch(A.fail); };
				el.querySelector('[data-from]').onchange = function (e) { from = e.target.value; load().catch(A.fail); };
				var nb = el.querySelector('[data-new]');
				if (nb) nb.onclick = function () { newStay({}); };
				var gb = el.querySelector('[data-gst]');
				if (gb) gb.onclick = gstSettings;
				el.onclick = function (e) {
					var s = e.target.closest('[data-s]');
					if (s) { stayDetail(+s.getAttribute('data-s')); return; }
					var f = e.target.closest('[data-room]');
					if (f && A.can(mod.write)) newStay({ room_id: +f.getAttribute('data-room'), check_in: f.getAttribute('data-d'), check_out: A.addDays(f.getAttribute('data-d'), 1) });
				};
				var want = +((A.query || {}).open || 0);
				if (want) { A.query.open = 0; stayDetail(want); }
			});
		};
		var gstSettings = function () {
			api('stays/tax-settings').then(function (t) {
				var row = function (s) { return '<div class="pair"><input type="number" min="0" step="1" data-up value="' + (s && s.upto != null ? esc(s.upto) : '') + '" placeholder="Up to ₹ a night (empty = above)"><input type="number" min="0" max="28" step="0.5" data-rt value="' + (s ? esc(s.rate) : '') + '" placeholder="GST %"><button type="button" class="icon-btn" data-rm aria-label="Remove">' + A.I.x + '</button></div>'; };
				var m = A.modal({ title: 'GST on room nights', body: '<p class="muted small">The rate is chosen by the room\'s tariff per night. ' + (t.is_default ? 'These are starting values: up to ₹7,500 a night 5%, above 18%, as understood for India from 22 September 2025.' : 'Your saved slabs.') + ' Confirm the rates that apply to you with your tax adviser.' + (t.gst_registered ? '' : ' This business is not marked GST-registered, so invoices show no GST until that is set in Bookings → Business setup.') + '</p><div class="pairs" data-sl>' + t.room_slabs.map(row).join('') + '</div><button type="button" class="btn btn-sm" data-add>Add a slab</button><label class="check"><input type="checkbox" data-cf' + (t.confirmed ? ' checked' : '') + '> These rates were checked with our tax adviser.</label><div class="form-error" hidden role="alert"></div>', foot: '<button class="btn" data-x2>Cancel</button><button class="btn btn-primary" data-ok>Save</button>' });
				var q = function (s) { return m.el.querySelector(s); };
				q('[data-add]').onclick = function () { q('[data-sl]').insertAdjacentHTML('beforeend', row(null)); };
				m.el.addEventListener('click', function (e) { var x = e.target.closest('[data-rm]'); if (x) x.closest('.pair').remove(); });
				q('[data-x2]').onclick = m.close;
				q('[data-ok]').onclick = function () {
					var sl = Array.prototype.map.call(m.el.querySelectorAll('.pair'), function (r) { var u = r.querySelector('[data-up]').value; return { upto: u === '' ? null : +u, rate: +r.querySelector('[data-rt]').value }; });
					api('stays/tax-settings', { method: 'PUT', body: { room_slabs: sl, confirmed: q('[data-cf]').checked } }).then(function () { m.close(); A.toast('Saved'); }).catch(function (x) { var er = q('.form-error'); er.textContent = x.message; er.hidden = false; });
				};
			}).catch(A.fail);
		};
		var stayRow = function (s) { return '<div class="row click" data-s="' + s.id + '" style="cursor:pointer"><div class="grow"><b>' + esc(s.guest_name) + '</b><div class="muted small">Room ' + esc(s.room) + ' · ' + esc(A.fmtD(s.check_in)) + ' → ' + esc(A.fmtD(s.check_out)) + '</div></div>' + A.badge(s.status) + '</div>'; };
		var newStay = function (pre) {
			A.recordTypes = null;
			lookup('record', 'room_type').then(function (types) {
				A.formModal({ title: 'New reservation', fields: [{ k: 'guest_name', label: 'Guest name', required: true }, { k: 'phone', label: 'Phone', type: 'tel' }, { k: 'email', label: 'Email', type: 'email' }, { k: 'check_in', label: 'Check-in', type: 'date', value: pre.check_in || A.localDay() }, { k: 'check_out', label: 'Check-out', type: 'date', value: pre.check_out || A.addDays(A.localDay(), 1) }, { k: 'room_type_id', label: 'Room type', type: 'select', opts: A.options(types.map(function (t) { return [t.id, t.name]; }), '', 'Any') }, { k: 'adults', label: 'Adults', type: 'number', min: 1, value: 2 }, { k: 'children', label: 'Children', type: 'number', min: 0, value: 0 }, { k: 'source', label: 'Booking source', type: 'select', opts: A.options([['direct', 'Direct'], ['phone', 'Phone'], ['walk_in', 'Walk-in'], ['website', 'Website'], ['ota', 'Online travel agent'], ['corporate', 'Corporate']], 'direct') }, { k: 'deposit', label: 'Deposit received', type: 'number', min: 0, step: '0.01', value: 0 }, { k: 'notes', label: 'Notes', type: 'textarea', span: true }],
					extra: '<div data-av class="card card-pad" style="margin-top:8px"><button type="button" class="btn btn-sm" data-chk>Check availability</button><div data-rooms></div></div>',
					ready: function (md) {
						md.room = pre.room_id || 0;
						md.el.querySelector('[data-chk]').onclick = function () {
							var v = A.readForm(md.el, [{ k: 'check_in' }, { k: 'check_out' }, { k: 'room_type_id' }]);
							api('stays/availability', { params: v }).then(function (r) {
								md.el.querySelector('[data-rooms]').innerHTML = r.rooms.length ? '<p class="small">Free for every night:</p><div class="slot-chips">' + r.rooms.map(function (x) { return '<label class="chip"><input type="radio" name="room_pick" value="' + x.room_id + '"' + (+md.room === +x.room_id ? ' checked' : '') + '> Room ' + esc(x.room) + ' · ' + money(x.total) + '</label>'; }).join('') + '</div>' : '<p class="form-error">No room is free for the whole stay.</p>';
								md.el.querySelectorAll('[name=room_pick]').forEach(function (i) { i.onchange = function () { md.room = +i.value; }; });
							}).catch(A.fail);
						};
						if (pre.room_id) md.el.querySelector('[data-chk]').click();
					},
					onSave: function (v, md) { if (md.room) v.room_id = md.room; return api('stays', { method: 'POST', body: v }).then(function (s) { A.toast('Reserved room ' + s.room + ' · ' + money(s.total)); load(); }); } });
			});
		};
		var stayDetail = function (id) {
			api('stays/' + id).then(function (s) {
				var acts = '';
				if (A.can(mod.write)) {
					if (s.status === 'reserved') acts += '<button class="btn btn-primary" data-ci>Check in</button><button class="btn btn-danger" data-cx>Cancel</button><button class="btn" data-ns>No show</button>';
					if (s.status === 'checked_in') acts += '<button class="btn" data-ch>Add charge</button><button class="btn" data-mv>Room transfer or dates</button><button class="btn btn-primary" data-co>Check out and bill</button>';
				}
				var m = A.modal({ title: s.guest_name + ' · Room ' + s.room, body: '<dl class="totals" style="max-width:none;margin:0"><dt>Status</dt><dd>' + A.badge(s.status) + '</dd><dt>Stay</dt><dd>' + esc(A.fmtD(s.check_in)) + ' → ' + esc(A.fmtD(s.check_out)) + ' (' + s.nights + ' nights)</dd><dt>Guests</dt><dd>' + s.adults + ' adults, ' + s.children + ' children</dd><dt>Rate</dt><dd>' + money(s.nightly_rate) + ' a night</dd><dt>Deposit</dt><dd>' + money(s.deposit) + '</dd>' + s.charges.map(function (c) { return '<dt>' + esc(c.description) + '</dt><dd>' + money(c.amount) + '</dd>'; }).join('') + '<dt class="b">Estimated total</dt><dd class="b">' + money(s.total) + '</dd></dl>' + (s.invoice_id ? '<p><a href="#/invoices/' + s.invoice_id + '">Open the invoice</a></p>' : '') + (s.phone ? '<p class="muted small">' + esc(s.phone) + '</p>' : ''), foot: acts + '<button class="btn" data-x2>Close</button>' });
				var on = function (sel, fn) { var b = m.el.querySelector(sel); if (b) b.onclick = fn; };
				on('[data-x2]', m.close);
				on('[data-ci]', function () { api('stays/' + id + '/check-in', { method: 'POST', body: {} }).then(function () { m.close(); load(); }).catch(A.fail); });
				on('[data-cx]', function () { api('stays/' + id + '/cancel', { method: 'POST', body: {} }).then(function () { m.close(); load(); }).catch(A.fail); });
				on('[data-ns]', function () { api('stays/' + id + '/cancel', { method: 'POST', body: { no_show: true } }).then(function () { m.close(); load(); }).catch(A.fail); });
				on('[data-ch]', function () { m.close(); A.formModal({ title: 'Add a charge', fields: [{ k: 'description', label: 'For', required: true, placeholder: 'Room service, laundry, minibar' }, { k: 'amount', label: 'Amount', type: 'number', step: '0.01' }, { k: 'tax_rate', label: 'GST %', type: 'number', value: 0 }], onSave: function (v) { return api('stays/' + id + '/charges', { method: 'POST', body: v }).then(load); } }); });
				on('[data-mv]', function () { m.close(); lookup('record', 'room').then(function (rooms) { A.formModal({ title: 'Change room or dates', values: { room_id: s.room_id, check_out: s.check_out }, fields: [{ k: 'room_id', label: 'Room', type: 'select', opts: A.options(rooms.map(function (r) { return [r.id, r.name]; }), s.room_id) }, { k: 'check_out', label: 'Check-out', type: 'date' }], onSave: function (v) { return api('stays/' + id, { method: 'PUT', body: v }).then(load); } }); }); });
				on('[data-co]', function () { m.close(); A.formModal({ title: 'Check out ' + s.guest_name, fields: [{ k: 'room_tax_rate', label: 'GST % on room nights', type: 'number', value: '', hint: 'leave empty to use your slab for this tariff' }, { k: 'method', label: 'Payment method', type: 'select', opts: A.options([['cash', 'Cash'], ['upi', 'UPI'], ['card', 'Card'], ['bank', 'Bank transfer']], 'card') }, { k: 'amount', label: 'Amount received now', type: 'number', step: '0.01', hint: 'the deposit is applied automatically' }], onSave: function (v) { return api('stays/' + id + '/check-out', { method: 'POST', body: { room_tax_rate: v.room_tax_rate === '' ? '' : +v.room_tax_rate, payments: +v.amount > 0 ? [{ method: v.method, amount: +v.amount }] : [] } }).then(function (r) { A.toast('Checked out. Invoice ' + r.invoice.doc_no + (r.invoice.balance > 0 ? ', ' + money(r.invoice.balance) + ' still due' : ', fully paid')); load(); }); } }); });
			}).catch(A.fail);
		};
		return load();
	}

	/* ---------------------------- Gym check-in desk ---------------------------- */
	function checkinView(el, mod) {
		el.innerHTML = '<div class="desk"><section class="card card-pad"><h2>Check in a member</h2><p class="muted small">Scan the member card QR code (scanners type the code here) or type the member code.</p><form class="desk-input" data-f><input name="code" autocomplete="off" placeholder="MEM-0001" aria-label="Member code"' + (A.can(mod.write) ? '' : ' disabled') + '></form><div data-r></div></section><section class="card card-pad"><div class="card-h" style="padding:0 0 10px;border:0"><h2>Today</h2><span data-live class="badge"></span></div><div data-l>' + A.loading(4) + '</div></section></div>';
		var input = el.querySelector('[name=code]'), res = el.querySelector('[data-r]');
		var list = function () {
			return api('checkins').then(function (r) {
				el.querySelector('[data-live]').textContent = r.live + ' in now';
				el.querySelector('[data-l]').innerHTML = r.items.length ? r.items.map(function (c) { return '<div class="row"><div class="grow"><b>' + esc(c.member) + '</b> <span class="muted small">' + esc(c.code) + ' · in ' + esc(A.fmtT(c.in_at)) + (c.out_at ? ', out ' + esc(A.fmtT(c.out_at)) : '') + '</span></div>' + (!c.out_at && A.can(mod.write) ? '<button class="btn btn-sm" data-out="' + c.id + '">Check out</button>' : A.badge(c.out_at ? 'left' : 'in')) + '</div>'; }).join('') : '<p class="muted">No check-ins yet today.</p>';
				el.querySelectorAll('[data-out]').forEach(function (b) { b.onclick = function () { api('checkins/' + b.getAttribute('data-out') + '/out', { method: 'POST', body: {} }).then(list).catch(A.fail); }; });
			});
		};
		el.querySelector('[data-f]').onsubmit = function (e) {
			e.preventDefault();
			var code = input.value.trim();
			if (!code) return;
			api('checkins', { method: 'POST', body: { code: code } }).then(function (r) {
				res.innerHTML = '<div class="desk-result ok"><b>' + esc(r.member) + '</b> ' + (r.already ? 'is already checked in.' : 'checked in.') + (r.state === 'expiring' ? ' Membership ends ' + esc(A.fmtD(r.ends)) + ': remind them to renew.' : '') + '</div>';
				input.value = ''; list();
			}).catch(function (x) { res.innerHTML = '<div class="desk-result bad">' + esc(x.message) + '</div>'; input.select(); });
		};
		input.focus();
		return list();
	}

	/* ---------------------------- Academy attendance ---------------------------- */
	function attendanceView(el, mod) {
		var day = A.localDay(), batch = 0;
		return api('records', { params: { entity: 'batch', status: 'active' } }).then(function (b) {
			if (!b.items.length) { el.innerHTML = '<section class="card card-pad">' + A.empty('No active batches', 'Create batches under Batches & Courses and enrol students first.') + '</section>'; return; }
			batch = b.items[0].id;
			el.innerHTML = '<div class="toolbar"><select data-b aria-label="Batch">' + b.items.map(function (x) { return '<option value="' + x.id + '">' + esc(x.title) + (x.start_time ? ' · ' + esc(x.start_time) : '') + '</option>'; }).join('') + '</select><input type="date" data-d value="' + esc(day) + '" max="' + esc(day) + '" aria-label="Date"></div><section class="card card-pad" data-s>' + A.loading(4) + '</section>';
			var sheet = el.querySelector('[data-s]');
			var load = function () {
				return api('attendance', { params: { batch_id: batch, date: day } }).then(function (r) {
					sheet.innerHTML = '<div class="biz-head"><h2>' + esc(r.batch.title) + '</h2><span class="muted small">' + esc(A.fmtD(r.date)) + (r.batch.meets_today ? '' : ' · this batch does not usually meet on this day') + (r.batch.instructor ? ' · ' + esc(A.memberName(r.batch.instructor)) + (r.batch.substitute ? ' (substitute)' : '') : '') + '</span></div>' +
						(r.students.length ? r.students.map(function (s) { return '<div class="att-row" data-st="' + s.id + '"><div class="grow"><b>' + esc(s.name) + '</b> <span class="muted small">' + esc(s.code || '') + '</span></div><div class="seg">' + [['present', 'Present'], ['late', 'Late'], ['absent', 'Absent'], ['excused', 'Excused']].map(function (o) { return '<button type="button" class="' + (s.status === o[0] ? 'on' : '') + '" data-v="' + o[0] + '">' + o[1] + '</button>'; }).join('') + '</div></div>'; }).join('') + (A.can(mod.write) ? '<div class="toolbar" style="margin-top:12px"><button class="btn" data-all>Mark all present</button><button class="btn btn-primary" data-save>Save attendance</button></div>' : '') : A.empty('No students in this batch', 'Enrol students under Students & Parents → Enrolments.'));
					sheet.onclick = function (e) { var v = e.target.closest('[data-v]'); if (v) { v.parentNode.querySelectorAll('button').forEach(function (x) { x.classList.toggle('on', x === v); }); } };
					var all = sheet.querySelector('[data-all]');
					if (all) all.onclick = function () { sheet.querySelectorAll('.att-row').forEach(function (r2) { r2.querySelectorAll('button').forEach(function (x) { x.classList.toggle('on', x.getAttribute('data-v') === 'present'); }); }); };
					var sv = sheet.querySelector('[data-save]');
					if (sv) sv.onclick = function () {
						var marks = Array.prototype.map.call(sheet.querySelectorAll('.att-row'), function (r2) { var on = r2.querySelector('button.on'); return on ? { student_id: +r2.getAttribute('data-st'), status: on.getAttribute('data-v') } : null; }).filter(Boolean);
						api('attendance', { method: 'PUT', body: { batch_id: batch, date: day, marks: marks } }).then(function (x) { A.toast('Saved ' + x.saved + ' marks'); }).catch(A.fail);
					};
				});
			};
			el.querySelector('[data-b]').onchange = function (e) { batch = +e.target.value; load().catch(A.fail); };
			el.querySelector('[data-d]').onchange = function (e) { day = e.target.value; load().catch(A.fail); };
			return load();
		});
	}

	/* ---------------------------- Business dashboard strip ---------------------------- */
	var baseDash = V.dashboard;
	V.dashboard = function (el, m) {
		if (A.isPersonal() || !S.boot.industry) return baseDash(el, m);
		el.innerHTML = '<section data-biz style="margin-bottom:16px"></section><div data-base></div>';
		var biz = el.querySelector('[data-biz]');
		api('industry/dashboard').then(function (d) {
			if (!d.kpis || !d.kpis.length) return;
			biz.innerHTML = '<div class="biz-head"><h2>' + esc(d.label) + '</h2><span class="muted small">Today at a glance</span></div><div class="kpis">' + d.kpis.map(function (k) { return '<a class="kpi" href="' + esc(k.link) + '"><span>' + esc(k.label) + '</span><b>' + (k.format === 'money' ? money(k.value) : k.format === 'pct' ? (+k.value) + '%' : esc(k.value)) + '</b>' + (k.hint ? '<small>' + esc(k.hint) + '</small>' : '') + '</a>'; }).join('') + '</div>' +
				(d.lists && d.lists.items && d.lists.items.length ? '<section class="card card-pad" style="margin-top:14px"><div class="biz-head"><h2>' + esc(d.lists.title) + '</h2><a class="small" href="' + esc(d.lists.link) + '">See all</a></div>' + d.lists.items.map(function (x) { return '<a class="row" href="' + esc(x.link) + '"><div class="grow"><b>' + esc(x.title) + '</b><div class="muted small">' + esc(x.sub) + '</div></div></a>'; }).join('') + '</section>' : '');
		}).catch(function () {});
		return baseDash(el.querySelector('[data-base]'), m);
	};

	/* ---------------------------- Settings additions ---------------------------- */
	var baseSettings = V.settings;
	V.settings = function (el, m) {
		var r = baseSettings(el, m);
		if (A.isPersonal()) { closingCard(el); return r; }
		var stack = el.querySelector('.stack') || el, w = S.boot.workspace, owner = S.boot.role === 'owner', mgr = A.can('settings.manage'), agency = w.account_type === 'agency';
		var box = document.createElement('div');
		box.className = 'stack';
		box.style.marginTop = '14px';
		box.innerHTML = '<section class="card card-pad" data-bt><h2>Business type and modules</h2>' + A.loading(2) + '</section>' + (owner ? '<section class="card card-pad danger-zone"><h2>Delete this Workspace</h2><p class="muted small">Closes the Workspace for everyone. You have ' + 14 + ' days to undo. Invoices, payments and tax records are kept as the law requires; everything else is then removed.</p><button class="btn btn-danger" data-close>Delete Workspace…</button></section>' : '');
		stack.appendChild(box);
		A.industry().then(function (ind) {
			var sec = box.querySelector('[data-bt]');
			var optional = ind.common.filter(function (c) { return c.optional; }), core = ind.modules;
			sec.innerHTML = '<h2>Business type and modules</h2><p><b>' + esc(ind.label) + '</b> <span class="muted small">' + esc(ind.description) + '</span></p>' +
				(mgr ? '<h3>Modules</h3><p class="muted small">Switching a module off hides it; its records are kept.</p><div class="form-grid">' + core.map(function (x) { return '<label class="check"><input type="checkbox" data-mod="' + x.key + '"' + (x.enabled ? ' checked' : '') + '> ' + esc(x.label) + '</label>'; }).join('') + optional.map(function (x) { return '<label class="check"><input type="checkbox" data-opt="' + x.key + '"' + (x.enabled ? ' checked' : '') + '> ' + esc(x.label) + ' <small class="muted">optional</small></label>'; }).join('') + '</div>' + (ind.profile === 'other' ? A.fieldHtml({ k: 'custom_label', label: 'What kind of business is this?', placeholder: 'For example: Pottery studio' }, ind.custom_label) : '') + '<button class="btn" data-savemod>Save modules</button>' +
					'<h3 style="margin-top:16px">Your own customer fields</h3><p class="muted small">Extra fields shown on every customer.</p><div data-cf></div><div class="toolbar"><input data-cfv placeholder="Field name, for example: T-shirt size"><button class="btn" data-cfa>Add field</button></div>' : '') +
				'<h3 style="margin-top:16px">Change business type</h3>' + (agency ? '<p class="muted small">NeonDigital manages this Workspace. Ask NeonDigital to change its business type; they will show you what is kept and removed first.</p>' : owner ? '<p class="muted small">Changing type switches your dashboard and modules. You see exactly what is kept and removed before anything happens, and a backup is taken first.</p><button class="btn" data-conv>Change business type…</button><div data-hist></div>' : '<p class="muted small">Only the owner can change the business type.</p>');
			var drawCf = function () { api('lists', { params: { key: 'customer_field' } }).then(function (x) { var cf = sec.querySelector('[data-cf]'); if (cf) cf.innerHTML = x.values.map(function (v) { return '<span class="chip">' + esc(v) + ' <button class="link" data-cfr="' + esc(v) + '">remove</button></span> '; }).join('') || '<p class="muted small">None yet.</p>'; }).catch(function () {}); };
			drawCf();
			var on = function (sel, fn) { var b = sec.querySelector(sel); if (b) b.onclick = fn; };
			on('[data-savemod]', function () {
				var off = Array.prototype.filter.call(sec.querySelectorAll('[data-mod]'), function (i) { return !i.checked; }).map(function (i) { return i.getAttribute('data-mod'); });
				var onl = Array.prototype.filter.call(sec.querySelectorAll('[data-opt]'), function (i) { return i.checked; }).map(function (i) { return i.getAttribute('data-opt'); });
				var lab = sec.querySelector('[name=custom_label]');
				api('industry/modules', { method: 'PUT', body: { on: onl, off: off, custom_label: lab ? lab.value : undefined } }).then(function () { S.ind = null; A.toast('Saved'); return A.reloadBoot(); }).catch(A.fail);
			});
			on('[data-cfa]', function () { var v = sec.querySelector('[data-cfv]').value.trim(); if (v) api('lists', { method: 'POST', body: { key: 'customer_field', value: v } }).then(function () { sec.querySelector('[data-cfv]').value = ''; S.ind = null; drawCf(); }).catch(A.fail); });
			sec.addEventListener('click', function (e) { var x = e.target.closest('[data-cfr]'); if (x) api('lists', { method: 'DELETE', body: { key: 'customer_field', value: x.getAttribute('data-cfr') } }).then(drawCf).catch(A.fail); });
			on('[data-conv]', function () { convertFlow(ind); });
			var hist = sec.querySelector('[data-hist]');
			if (hist) api('business-type/history').then(function (h) { if (h.items.length) hist.innerHTML = '<details style="margin-top:10px"><summary class="small">History</summary>' + h.items.map(function (x) { return '<div class="row small"><div class="grow">' + esc(nice(x.from_category)) + ' → ' + esc(nice(x.to_category)) + ' · ' + esc(x.reason) + '</div>' + A.badge(x.status) + '<span class="muted">' + esc(A.fmtDT(x.created_at)) + '</span></div>'; }).join('') + '</details>'; }).catch(function () {});
		}).catch(A.fail);
		var cl = box.querySelector('[data-close]');
		if (cl) cl.onclick = closeFlow;
		return r;
	};
	function convertFlow(ind) {
		A.formModal({ title: 'Change business type', fields: [{ k: 'to', label: 'New business type', type: 'select', opts: A.options(ind.categories.filter(function (c) { return c.key !== ind.category; }).map(function (c) { return [c.key, c.label]; }), '') }],
			ok: 'Show what changes', onSave: function (v) {
				return api('business-type/preview', { params: { to: v.to } }).then(function (p) {
					var li = function (x) { return '<li>' + esc(x.label) + ' <b>(' + x.count + ')</b></li>'; };
					A.formModal({ title: p.from.label + ' → ' + p.to.label, size: 'lg', ok: 'Change business type',
						fields: [{ k: 'reason', label: 'Reason for the change', type: 'textarea', span: true, required: true }, { k: 'confirm', label: 'Type CHANGE to confirm', span: true }],
						extra: '<div class="grid" style="margin-top:8px"><section><h3>Removed</h3><ul class="remove-list">' + (p.remove.map(li).join('') || '<li>Nothing</li>') + '</ul></section><section><h3>Kept</h3><ul class="keep-list">' + p.keep.map(li).join('') + '</ul></section></div><p class="small"><b>New modules:</b> ' + esc(p.to.modules.join(', ')) + '</p><ul class="muted small">' + p.notes.map(function (n) { return '<li>' + esc(n) + '</li>'; }).join('') + '</ul>',
						onSave: function (c) { return api('business-type/convert', { method: 'POST', body: { to: v.to, reason: c.reason, confirm: c.confirm } }).then(function () { A.toast('Business type changed'); S.ind = null; location.hash = '#/'; location.reload(); }); } });
				});
			} });
	}
	function closeFlow() {
		api('workspace/close-preview').then(function (p) {
			A.formModal({ title: 'Delete ' + p.name, size: 'lg', ok: 'Delete Workspace',
				fields: [{ k: 'confirm_name', label: 'Type the Workspace name: ' + p.name, span: true, required: true }, { k: 'password', label: 'Your password', type: 'password', span: true }, { k: 'reason', label: 'Reason (optional)', span: true }],
				extra: '<div class="grid"><section><h3>Removed after ' + p.window_days + ' days</h3><ul class="remove-list">' + p.removed.map(function (x) { return '<li>' + esc(x) + '</li>'; }).join('') + '</ul></section><section><h3>Kept</h3><ul>' + p.kept.map(function (x) { return '<li>' + esc(x) + '</li>'; }).join('') + '</ul></section></div>' + (p.agency ? '<p class="alert">NeonDigital will be told that this Workspace is closing.</p>' : '') + (p.unpaid_subscription ? '<p class="alert alert-warn">This Workspace has unpaid subscription invoices. Closing does not cancel what is already owed.</p>' : ''),
				onSave: function (v) { return api('workspace/close', { method: 'POST', body: v }).then(function (r) { A.toast('Workspace closed. You can undo this for ' + p.window_days + ' days from Settings. After that it is deleted for good.'); A.switchTo(r.personal_id || 0); }); } });
		}).catch(A.fail);
	}
	/* In Personal: Workspaces you closed that can still be restored. */
	var basePersonalSettings = V.personal_settings;
	if (basePersonalSettings) V.personal_settings = function (el, m) { var r = basePersonalSettings(el, m); closingCard(el); return r; };
	function closingCard(el) {
		api('me/closing-workspaces').then(function (r) {
			if (!r.items.length) return;
			var s = document.createElement('section');
			s.className = 'card card-pad';
			s.style.marginTop = '14px';
			s.innerHTML = '<h2>Closing Workspaces</h2>' + r.items.map(function (w) { return '<div class="row"><div class="grow"><b>' + esc(w.name) + '</b><div class="muted small">Data is removed after ' + esc(A.fmtDT(w.delete_after)) + '</div></div><button class="btn btn-sm" data-undo="' + w.id + '">Restore</button></div>'; }).join('');
			(el.querySelector('.stack') || el).appendChild(s);
			s.querySelectorAll('[data-undo]').forEach(function (b) { b.onclick = function () { api('me/closing-workspaces/' + b.getAttribute('data-undo') + '/restore', { method: 'POST', body: {} }).then(function () { A.switchTo(+b.getAttribute('data-undo')); }).catch(A.fail); }; });
		}).catch(function () {});
	}
	A.closingCard = closingCard;

	A.route(/^\/m\/([a-z_]+)$/, 'module');
})();
