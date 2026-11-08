/* Bookings and business setup; invoices and quotations. */
(function () {
	'use strict';
	var A = window.App, S = A.S, api = A.api, esc = A.esc, V = A.views;

	/* ---- Bookings ---- */
	A.bookingForm = function (done) {
		Promise.all([api('services'), api('spaces').catch(function () { return { items: [] }; })]).then(function (all) {
			var r = all[0], spaces = all[1].items;
			if (!r.items.length) { A.toast('Add a service first, under Bookings → Services.', true); return; }
			var key = A.uuid();
			A.formModal({
				title: 'Add booking', extra: '<div class="slots" data-slots></div>',
				fields: [
					{ k: 'service_id', label: 'Service', type: 'select', opts: A.options(r.items, ''), span: true },
					{ k: 'date', label: 'Date', type: 'date', value: A.localDay() },
					{ k: 'name', label: 'Customer name', required: true }, { k: 'phone', label: 'Phone', type: 'tel' }, { k: 'email', label: 'Email', type: 'email' },
					{ k: 'party_size', label: 'People', type: 'number', min: 1, value: 1 }, { k: 'space_id', label: 'Space or room', type: 'select', opts: A.options(spaces.map(function (s) { return [s.id, s.name]; }), '', 'Any suitable') },
						{ k: 'staff_id', label: 'Instructor or staff', type: 'select', opts: A.memberOptions('', 'Any') }, { k: 'repeat_weeks', label: 'Repeat weekly for', type: 'number', min: 1, value: 1, hint: 'weeks; 1 for a single booking' },
						{ k: 'credit_id', label: 'Use makeup credit number', type: 'number', min: 0, hint: 'optional' }, { k: 'waitlist', label: 'Join the waitlist if full', type: 'check' }, { k: 'notes', label: 'Notes', type: 'textarea', span: true }
				],
				ready: function (m) {
					var slotBox = m.el.querySelector('[data-slots]');
					m.slot = null;
					var load = function () {
						m.slot = null;
						slotBox.innerHTML = '<p class="muted">Loading…</p>';
						api('bookings/slots', { params: { service_id: m.el.querySelector('[name=service_id]').value, date: m.el.querySelector('[name=date]').value, staff_id: m.el.querySelector('[name=staff_id]').value } }).then(function (x) {
							slotBox.innerHTML = x.slots.length ? x.slots.map(function (s) { return '<button type="button" class="slot" data-t="' + esc(s.start) + '">' + esc(s.label) + '</button>'; }).join('') : '<p class="muted">No times on this day.</p>';
						}).catch(function (e) { slotBox.innerHTML = '<p class="form-error">' + esc(e.message) + '</p>'; });
					};
					slotBox.onclick = function (e) {
						var b = e.target.closest('[data-t]');
						if (!b) return;
						m.slot = b.getAttribute('data-t');
						slotBox.querySelectorAll('.slot').forEach(function (x) { x.style.background = x === b ? 'var(--brand)' : ''; x.style.color = x === b ? '#fff' : ''; });
					};
					m.el.querySelector('[name=service_id]').onchange = load;
					m.el.querySelector('[name=date]').onchange = load;
						m.el.querySelector('[name=staff_id]').onchange = load;
					load();
				},
				onSave: function (v, m) {
					if (!m.slot && !m.manual) return Promise.reject(new Error('Choose a time.'));
						v.start = m.slot || m.manual;
						v.idempotency_key = key;
						['space_id', 'staff_id', 'credit_id'].forEach(function (k) { if (!v[k]) delete v[k]; });
						v.repeat_weeks = Math.max(1, +v.repeat_weeks || 1);
						var send = function (body) {
							return api('bookings', { method: 'POST', body: body }).then(function (x) {
								if (x.created) A.toast('Booked ' + x.created.length + ' of ' + v.repeat_weeks + ' weeks' + (x.skipped.length ? '; ' + x.skipped.length + ' skipped (busy)' : ''));
								else A.toast(x.status === 'waitlisted' ? 'Added to the waitlist' : (x.warnings && x.warnings.length ? 'Booked with an approved override' : 'Booked'));
								(done || A.render)();
							}).catch(function (e) {
								var c = (e.data && e.data.conflicts) || [];
								if (e.status === 409 && c.length && ['owner', 'admin', 'manager'].indexOf(S.boot.role) > -1) {
									return A.ask('This clashes with ' + c.length + ' booking(s)', c.map(function (x) { return (x.title || x.kind) + ' (' + x.why + ', ' + A.fmtT(x.starts_at) + ')'; }).join('; ') + '. Give a reason to book anyway; it is recorded in the audit log.', { ok: 'Override and book' }).then(function (why) { if (!why) throw e; return send(Object.assign({}, body, { override: true, override_reason: why })); });
								}
								throw e;
							});
						};
						return send(v);
				}
			});
		}).catch(A.fail);
	};

	V.bookings = function (el, m) {
		A.setTitle('Bookings');
		if (!A.gate(el, 'bookings')) return;
		var tab = m[1] || 'list', mgr = A.can('bookings.manage');
		var tabs = [['list', 'Bookings'], ['availability', 'Availability'], ['services', 'Services'], ['spaces', 'Spaces & rooms'], ['pages', 'Booking pages'], ['credits', 'Makeup credits'], ['setup', 'Business setup'], ['reports', 'Reports']];
		el.innerHTML = '<div class="tabs">' + tabs.map(function (t) { return '<a href="#/bookings/' + t[0] + '" class="' + (t[0] === tab ? 'on' : '') + '">' + t[1] + '</a>'; }).join('') + '</div><div data-c>' + A.loading(5) + '</div>';
		var box = el.querySelector('[data-c]');
		return { list: bList, availability: bAvailability, services: bServices, spaces: bSpaces, pages: bPages, credits: bCredits, setup: bSetup, reports: bReports }[tab](box, mgr);
	};

	function bList(box) {
		var f = { from: A.localDay() };
		box.innerHTML = '<div class="toolbar"><input type="date" data-from value="' + esc(f.from) + '" aria-label="From date"><select data-st aria-label="Status">' + A.options([['confirmed', 'Confirmed'], ['pending', 'Pending'], ['waitlisted', 'Waitlisted'], ['completed', 'Completed'], ['cancelled', 'Cancelled'], ['no_show', 'No show']], '', 'All statuses') + '</select><input type="search" class="grow" data-q placeholder="Customer, phone or email" aria-label="Search">' + (A.can('bookings.write') ? '<button class="btn btn-primary" data-add>Add booking</button>' : '') + '</div><section class="card card-pad" data-l>' + A.loading(4) + '</section>';
		var list = box.querySelector('[data-l]');
		var load = function () {
			return api('bookings', { params: f }).then(function (r) {
				list.innerHTML = r.items.length ? A.tbl([
					['When', function (b) { return esc(A.fmtDT(b.starts_at)); }], ['Customer', function (b) { return '<b>' + esc(b.customer_name) + '</b><div class="muted small">' + esc(b.phone || b.email) + '</div>'; }], ['Service', function (b) { return esc(b.service_name) + (+b.party_size > 1 ? ' × ' + (+b.party_size) : '') + (b.override_by ? ' ' + A.badge('override') : ''); }], ['Space', function (b) { return esc(b.space_name || ''); }], ['Staff', function (b) { return esc(A.memberName(b.staff_id)); }], ['Status', function (b) { return A.badge(b.status); }]
				], r.items, function (b) { return 'class="click" data-o="' + b.id + '"'; }) : A.empty('No bookings', 'Share your booking page, or add one yourself.');
				list.onclick = function (e) {
					var o = e.target.closest('[data-o]');
					if (!o || !A.can('bookings.write')) return;
					var b = r.items.filter(function (x) { return +x.id === +o.getAttribute('data-o'); })[0];
					A.formModal({
						title: b.customer_name, values: b, fields: [{ k: 'status', label: 'Status', type: 'select', opts: A.options([['pending', 'Pending'], ['confirmed', 'Confirmed'], ['waitlisted', 'Waitlisted'], ['completed', 'Completed'], ['cancelled', 'Cancelled'], ['no_show', 'No show']], b.status) }, { k: 'makeup_credit', label: 'On cancel or no-show, give a makeup credit', type: 'check' }, { k: 'payment_status', label: 'Payment', type: 'select', opts: A.options([['unpaid', 'Unpaid'], ['partial', 'Part paid'], ['paid', 'Paid'], ['refunded', 'Refunded']], b.payment_status || 'unpaid') }, { k: 'notes', label: 'Notes', type: 'textarea', span: true }],
						extra: '<div class="toolbar" style="margin-top:8px">' + (A.has('invoice_management') && A.can('invoices.write') ? '<button type="button" class="btn btn-sm" data-binv>Create invoice</button>' : '') + (b.phone ? '<button type="button" class="btn btn-sm" data-bwa>WhatsApp</button>' : '') + '</div>',
						ready: function (md) {
							var bi = md.el.querySelector('[data-binv]'), bw = md.el.querySelector('[data-bwa]');
							if (bi) bi.onclick = function () { api('bookings/' + b.id + '/invoice', { method: 'POST', body: {} }).then(function (x) { md.close(); A.go('/invoices/' + x.id); }).catch(A.fail); };
							if (bw) bw.onclick = function () { A.openWhatsApp('bookings/' + b.id + '/whatsapp'); };
						},
						onSave: function (v) {
							var pay = v.payment_status;
								delete v.payment_status;
								if (!v.makeup_credit) delete v.makeup_credit;
							return api('bookings/' + b.id, { method: 'PUT', body: v }).then(function () { return pay !== (b.payment_status || 'unpaid') ? api('bookings/' + b.id + '/payment-status', { method: 'PUT', body: { payment_status: pay } }) : null; }).then(load);
						}
					});
				};
			});
		};
		box.querySelector('[data-from]').onchange = function (e) { f.from = e.target.value; load().catch(A.fail); };
		box.querySelector('[data-st]').onchange = function (e) { f.status = e.target.value; load().catch(A.fail); };
		var deb;
		box.querySelector('[data-q]').oninput = function (e) { clearTimeout(deb); deb = setTimeout(function () { f.q = e.target.value; load().catch(A.fail); }, 300); };
		var a = box.querySelector('[data-add]');
		if (a) a.onclick = function () { A.bookingForm(load); };
		var want = +((A.query || {}).open || 0);
		if (want) { // Opened from a notification or the Personal overview: show that booking.
			return api('bookings/' + want).then(function (b) {
				f.from = A.localDT(b.starts_at).slice(0, 10);
				box.querySelector('[data-from]').value = f.from;
				return load().then(function () { var row = list.querySelector('[data-o="' + want + '"]'); if (row) row.click(); });
			}, function () { return load(); });
		}
		return load();
	}

	function bServices(box, mgr) {
		var spaces = [];
		var load = function () {
			return Promise.all([api('services'), api('spaces')]).then(function (rr) {
				var r = rr[0];
				spaces = rr[1].items;
				var cat = A.category();
				box.innerHTML = '<section class="card card-pad">' + (mgr ? '<div class="toolbar"><span class="muted small">Service types for <b>' + esc(cat.label) + '</b>. Change the category in Business setup.</span><span class="grow"></span><button class="btn btn-primary" data-add>Add service</button></div>' : '') + (r.items.length ? r.items.map(function (s) { return '<div class="row"' + (mgr ? ' data-o="' + s.id + '" style="cursor:pointer"' : '') + '><div class="grow"><b>' + esc(s.name) + '</b><div class="muted small">' + esc(s.type_label || '') + (s.type_label ? ' · ' : '') + (+s.duration_min) + ' min · ' + A.inr(s.price) + (+s.capacity > 1 ? ' · ' + (+s.capacity) + ' seats' : '') + (s.kind_label ? ' · ' + esc(s.kind_label) : '') + (+s.cleanup_min || +s.setup_min ? ' · buffers ' + (+s.setup_min) + '/' + (+s.cleanup_min) + ' min' : '') + '</div></div>' + (+s.is_active ? '' : A.badge('hidden')) + '</div>'; }).join('') : A.empty('No services yet', 'Add what customers can book.')) + '</section>';
				var form = function (s) {
					var staff = (s.staff_ids || []).map(Number);
					var types = A.category().types.slice();
					if (s.type_key && !types.some(function (t) { return t.key === s.type_key; })) types.unshift({ key: s.type_key, label: s.type_label || s.type_key }); // From a former category: kept as it is.
					A.formModal({
						title: s.id ? 'Edit service' : 'Add service', values: { name: s.name, description: s.description, type_key: s.type_key, type_custom: s.type_custom, duration_min: s.duration_min || 30, price: s.price || 0, capacity: s.capacity || 1, is_active: s.id ? +s.is_active : 1, setup_min: s.setup_min || 0, cleanup_min: s.cleanup_min || 0, waitlist: +s.waitlist || 0, image_url: s.image_url || '' },
						fields: [
							{ k: 'name', label: 'Service name', required: true }, { k: 'type_key', label: 'Service type', type: 'select', required: true, opts: '<option value="">Choose a type</option>' + types.map(function (t) { return '<option value="' + esc(t.key) + '"' + (t.key === s.type_key ? ' selected' : '') + '>' + esc(t.label) + '</option>'; }).join('') },
							{ k: 'type_custom', label: 'Your service type', hint: 'saved for reuse' }, { k: 'booking_kind', label: 'Booking type', type: 'select', opts: A.options(((S.boot.industry || {}).booking_kinds || []).map(function (x) { return [x.key, x.label]; }), s.booking_kind || '', 'Not set') },
							{ k: 'duration_min', label: 'Duration (minutes)', type: 'number', min: 5 }, { k: 'price', label: 'Price', type: 'number', min: 0, step: '0.01' }, { k: 'capacity', label: 'Seats per time', type: 'number', min: 1, hint: '1 for one customer at a time' }, { k: 'setup_min', label: 'Setup time before (minutes)', type: 'number', min: 0, hint: 'the room is held' }, { k: 'cleanup_min', label: 'Clean-up time after (minutes)', type: 'number', min: 0 }, { k: 'waitlist', label: 'Offer a waitlist when full', type: 'check' }, { k: 'pay_mode', label: 'Payment on booking pages', type: 'select', opts: A.options([['venue', 'Pay later or at the venue (seat confirmed at once)'], ['online', 'Pay online to confirm (seat held until paid)']], s.pay_mode || 'venue'), hint: 'online needs your Razorpay keys in Accounts & Finance' }, { k: 'image_url', label: 'Image link', type: 'url' }, { k: 'is_active', label: 'Open for booking', type: 'check' }, { k: 'description', label: 'Description', type: 'textarea', span: true }
						],
						ready: function (md) {
							var sel = md.el.querySelector('[name=type_key]'), tc = md.el.querySelector('[name=type_custom]'), other = tc.closest('.field');
								var saved = (A.category().saved_custom || []);
								if (saved.length) { tc.setAttribute('list', 'nw-saved-types'); tc.insertAdjacentHTML('afterend', '<datalist id="nw-saved-types">' + saved.map(function (x) { return '<option value="' + esc(x) + '">'; }).join('') + '</datalist>'); }
							var sync = function () { other.hidden = sel.value !== 'other'; };
							sel.onchange = sync; sync();
						},
						extra: '<details class="card card-pad"><summary>Staff or instructors who provide this</summary>' + S.boot.members.map(function (m) { return '<label class="check"><input type="checkbox" data-st value="' + m.id + '"' + (staff.indexOf(+m.id) > -1 ? ' checked' : '') + '> ' + esc(m.name) + '</label>'; }).join('') + '</details><details class="card card-pad"' + (spaces.length ? ' open' : '') + '><summary>Spaces or rooms it can use</summary>' + (spaces.length ? spaces.map(function (x) { return '<label class="check"><input type="checkbox" data-sp value="' + x.id + '"' + ((s.space_ids || []).map(Number).indexOf(+x.id) > -1 ? ' checked' : '') + '> ' + esc(x.name) + '</label>'; }).join('') : '<p class="muted small">No spaces yet. Add them under Spaces &amp; rooms.</p>') + '<p class="muted small">A room holds one booking at a time, except the same class at the same time sharing its seats.</p></details>',
						del: s.id ? function () { return api('services/' + s.id, { method: 'DELETE' }).then(load); } : null,
						onSave: function (v, m) {
							if (!v.type_key) return Promise.reject(new Error('Choose a service type.'));
							if (v.type_key === 'other' && !String(v.type_custom || '').trim()) return Promise.reject(new Error('Enter your own service type.'));
							if (v.type_key !== 'other') v.type_custom = '';
							v.staff_ids = Array.prototype.map.call(m.el.querySelectorAll('[data-st]:checked'), function (i) { return +i.value; });
								v.space_ids = Array.prototype.map.call(m.el.querySelectorAll('[data-sp]:checked'), function (i) { return +i.value; });
							return api('services' + (s.id ? '/' + s.id : ''), { method: s.id ? 'PUT' : 'POST', body: v }).then(load);
						}
					});
				};
				var a = box.querySelector('[data-add]');
				if (a) a.onclick = function () { form({}); };
				box.querySelectorAll('[data-o]').forEach(function (x) { x.onclick = function () { form(r.items.filter(function (s) { return +s.id === +x.getAttribute('data-o'); })[0]); }; });
			});
		};
		return load();
	}

	/* Booking pages: one main page, four more included, extra pages as purchasable add-ons. */
	var PAGE_TYPES = [['all', 'Everything (main page)'], ['class', 'Classes'], ['workshop', 'Workshops and events'], ['rental', 'Studio and room rentals'], ['instructor', 'One instructor'], ['service', 'Chosen services']];
	function bPages(box, mgr) {
		var load = function () {
			return Promise.all([api('booking-pages'), api('services'), api('booking-pages/limits'), api('spaces')]).then(function (r) {
				var pages = r[0].items, lim = r[2], spaces = r[3].items;
				box.innerHTML = '<section class="card card-pad"><div class="toolbar"><span class="grow muted small">' + lim.used + ' of ' + lim.limit + ' pages used: your main page and ' + (lim.included - 1) + ' more are included' + (lim.limit > lim.included ? ', plus ' + (lim.limit - lim.included) + ' purchased' : '') + '.</span>' + (mgr ? (lim.used < lim.limit ? '<button class="btn btn-primary" data-wz>' + A.I.plus + ' Create a booking page</button><button class="btn" data-add>Quick add</button>' : '<button class="btn" data-buyp>Buy an extra page</button>') : '') + '</div>' + (pages.length ? pages.map(function (p) {
					return '<div class="row"><div class="grow"><b>' + esc(p.title) + '</b> ' + (p.is_main ? A.badge('main page') : '') + ' ' + A.badge(p.status === 'published' ? (+p.is_active ? 'published' : 'deactivated') : 'draft') + '<div class="small">' + (p.status === 'published' && +p.is_active ? '<a href="' + A.safeUrl(p.public_url) + '" target="_blank" rel="noopener">' + esc(String(p.public_url).replace(/^https?:\/\//, '')) + '</a>' : '<span class="muted">Not public yet</span>') + ' · <span class="muted">' + esc((PAGE_TYPES.filter(function (t) { return t[0] === p.page_type; })[0] || ['', 'Everything'])[1]) + '</span></div></div><button class="btn btn-sm" data-pv="' + p.id + '">Preview</button>' + (p.status === 'published' ? '<button class="btn btn-sm" data-copy="' + esc(p.public_url) + '">Copy link</button>' : '') + (mgr ? (p.status !== 'published' ? '<button class="btn btn-sm btn-primary" data-pub="' + p.id + '">Publish</button>' : '<button class="btn btn-sm" data-tog="' + p.id + '" data-on="' + (+p.is_active) + '">' + (+p.is_active ? 'Deactivate' : 'Activate') + '</button>') + '<button class="btn btn-sm" data-o="' + p.id + '">Edit</button>' : '') + '</div>';
				}).join('') : A.empty('No booking pages yet', 'Create your main booking page. It can show all your services, classes, workshops and rentals.')) + '</section>';
				box.querySelectorAll('[data-copy]').forEach(function (b) { b.onclick = function () { navigator.clipboard.writeText(b.getAttribute('data-copy')).then(function () { A.toast('Copied'); }); }; });
				box.querySelectorAll('[data-pub]').forEach(function (b) { b.onclick = function () { api('booking-pages/' + b.getAttribute('data-pub'), { method: 'PUT', body: { status: 'published', is_active: 1 } }).then(load).catch(A.fail); }; });
				box.querySelectorAll('[data-tog]').forEach(function (b) { b.onclick = function () { api('booking-pages/' + b.getAttribute('data-tog'), { method: 'PUT', body: { is_active: b.getAttribute('data-on') === '1' ? 0 : 1 } }).then(load).catch(A.fail); }; });
				box.querySelectorAll('[data-pv]').forEach(function (b) { b.onclick = function () { preview(+b.getAttribute('data-pv')); }; });
				var form = function (p) {
					var chosen = (p.service_ids || []).map(Number), chosenSp = (p.space_ids || []).map(Number), d = p.design || {};
					A.formModal({
						title: p.id ? 'Edit booking page' : 'Add booking page', size: 'lg', values: { title: p.title, slug: p.slug, intro: p.intro, page_type: p.page_type || 'all', staff_id: p.staff_id || '', min_notice_hours: p.id ? p.min_notice_hours : 2, max_days_ahead: p.id ? p.max_days_ahead : 60, allow_reschedule: p.id ? +p.allow_reschedule : 1, d_cover: d.cover_url || '', d_description: d.description || '' },
						fields: [{ k: 'title', label: 'Title', required: true, span: true }, { k: 'page_type', label: 'Page type', type: 'select', opts: A.options(PAGE_TYPES, p.page_type || 'all') }, { k: 'staff_id', label: 'Instructor (for an instructor page)', type: 'select', opts: A.memberOptions(p.staff_id || '', 'None') }, { k: 'slug', label: 'Address', hint: 'after /book/, blank to generate' }, { k: 'min_notice_hours', label: 'Minimum notice (hours)', type: 'number', min: 0 }, { k: 'max_days_ahead', label: 'Book up to (days ahead)', type: 'number', min: 1 }, { k: 'allow_reschedule', label: 'Customers can reschedule', type: 'check' }, { k: 'intro', label: 'Introduction', type: 'textarea', span: true }, { k: 'd_cover', label: 'Cover image link', type: 'url' }, { k: 'd_description', label: 'Description for this page', type: 'textarea', span: true }],
						extra: '<details class="card card-pad" open><summary>Services, classes and rentals on this page</summary><p class="muted small">None ticked means all that fit the page type.</p>' + r[1].items.map(function (s) { return '<label class="check"><input type="checkbox" data-sv value="' + s.id + '"' + (chosen.indexOf(+s.id) > -1 ? ' checked' : '') + '> ' + esc(s.name) + '</label>'; }).join('') + '</details>' +
							'<details class="card card-pad"><summary>Spaces this page can use</summary>' + (spaces.length ? spaces.map(function (s) { return '<label class="check"><input type="checkbox" data-spc value="' + s.id + '"' + (chosenSp.indexOf(+s.id) > -1 ? ' checked' : '') + '> ' + esc(s.name) + '</label>'; }).join('') : '<p class="muted small">No spaces yet.</p>') + '</details>' +
							'<details class="card card-pad"><summary>Questions for customers</summary><div data-qs>' + (p.fields || []).map(qRow).join('') + '</div><button type="button" class="btn btn-sm" data-qa>Add a question</button></details>',
						ready: function (md) { md.el.querySelector('[data-qa]').onclick = function () { md.el.querySelector('[data-qs]').insertAdjacentHTML('beforeend', qRow({})); }; md.el.addEventListener('click', function (e) { var x = e.target.closest('[data-qr]'); if (x) x.closest('.q-row').remove(); }); },
						del: p.id && !p.is_main ? function () { return api('booking-pages/' + p.id, { method: 'DELETE' }).then(load); } : null,
						onSave: function (v, m) {
							v.service_ids = Array.prototype.map.call(m.el.querySelectorAll('[data-sv]:checked'), function (i) { return +i.value; });
							v.space_ids = Array.prototype.map.call(m.el.querySelectorAll('[data-spc]:checked'), function (i) { return +i.value; });
							v.design = { cover_url: v.d_cover, description: v.d_description };
							v.fields = readQs(m.el);
							v.staff_id = v.staff_id ? +v.staff_id : 0;
							delete v.d_cover; delete v.d_description;
							return api('booking-pages' + (p.id ? '/' + p.id : ''), { method: p.id ? 'PUT' : 'POST', body: v }).then(load);
						}
					});
				};
				var a = box.querySelector('[data-add]');
				if (a) a.onclick = function () { form({}); };
				var buy = box.querySelector('[data-buyp]');
				if (buy) buy.onclick = function () {
					if (S.boot.workspace.account_type === 'agency') { A.toast('NeonDigital manages your package. Ask NeonDigital for an extra booking page.'); return; }
					if (!A.can('billing.manage')) { A.toast('Ask the Workspace owner to buy an extra booking page.', true); return; }
					api('billing/addons', { method: 'POST', body: { code: 'extra_booking_page' } }).then(function () { A.toast('Invoice created. Pay it to add the page.'); A.go('/billing'); }).catch(A.fail);
				};
				var wz = box.querySelector('[data-wz]');
				if (wz) wz.onclick = function () { wizard(r[1].items, spaces, !pages.length, load); };
				box.querySelectorAll('[data-o]').forEach(function (x) { x.onclick = function () { form(pages.filter(function (p) { return +p.id === +x.getAttribute('data-o'); })[0]); }; });
			});
		};
		var preview = function (id) {
			api('booking-pages/' + id + '/preview').then(function (d) {
				A.modal({ title: 'Preview: ' + d.page.title, size: 'lg', body: '<p class="muted small">' + (d.status === 'published' ? 'Published' : 'Draft, not public yet') + '</p><h2>' + esc(d.business.name) + '</h2>' + (d.page.design.description ? '<p>' + esc(d.page.design.description) + '</p>' : '') + (d.page.intro ? '<p class="muted">' + esc(d.page.intro) + '</p>' : '') + (d.services.length ? d.services.map(function (s) { return '<div class="row"><div class="grow"><b>' + esc(s.name) + '</b><div class="muted small">' + esc(s.kind_label || s.type_label || '') + ' · ' + (+s.duration_min) + ' min · ' + A.inr(s.price) + (s.spaces.length ? ' · ' + esc(s.spaces.map(function (x) { return x.name; }).join(', ')) : '') + '</div></div></div>'; }).join('') : A.empty('Nothing to book yet', 'Add services to this page.')) + (d.page.fields.length ? '<h3>Customers are also asked</h3><ul>' + d.page.fields.map(function (f) { return '<li>' + esc(f.label) + (f.required ? ' *' : '') + '</li>'; }).join('') + '</ul>' : '') });
			}).catch(A.fail);
		};
		return load();
	}
	function qRow(f) { return '<div class="q-row form-grid" style="border-bottom:1px solid var(--border);padding:6px 0"><label class="field"><span>Question</span><input data-ql value="' + esc(f.label || '') + '"></label><label class="field"><span>Answer type</span><select data-qt>' + A.options([['text', 'Short text'], ['textarea', 'Long text'], ['select', 'Choose from a list']], f.type || 'text') + '</select></label><label class="field"><span>Choices (for a list), comma separated</span><input data-qo value="' + esc((f.options || []).join(', ')) + '"></label><label class="check"><input type="checkbox" data-qq' + (f.required ? ' checked' : '') + '> Required <button type="button" class="link" data-qr>Remove</button></label></div>'; }
	function readQs(root) { return Array.prototype.map.call(root.querySelectorAll('.q-row'), function (r) { return { label: r.querySelector('[data-ql]').value, type: r.querySelector('[data-qt]').value, options: r.querySelector('[data-qo]').value.split(',').map(function (x) { return x.trim(); }).filter(Boolean), required: r.querySelector('[data-qq]').checked }; }).filter(function (q) { return q.label.trim(); }); }

	/* Guided creation: name and type → services → spaces → schedule → pricing → design → preview and publish. */
	function wizard(services, spaces, first, done) {
		var st = { step: 0, page: { title: '', page_type: first ? 'all' : 'class', intro: '', design: {}, fields: [] }, service_ids: [], new_services: [], space_ids: [], schedule: [], pricing: {}, publish: true, draft: { days: [], start: '09:00', end: '18:00', from: '', to: '', slot: '' }, dirty: false };
		var dayName = {};
		DAYS.forEach(function (d) { dayName[d[0]] = d[1]; });
		var blockText = function (x) { return (x.weekdays.length ? x.weekdays.map(function (d) { return dayName[d] || d; }).join(', ') : 'Every day') + ' · ' + x.start_time + '–' + x.end_time + (x.start_date ? ' · from ' + A.fmtD(x.start_date) : '') + (x.end_date ? ' to ' + A.fmtD(x.end_date) : '') + (x.slot_min ? ' · ' + x.slot_min + '-minute slots' : ''); };
		var sameBlock = function (a, b) { return a.weekdays.slice().sort().join() === b.weekdays.slice().sort().join() && a.start_time === b.start_time && a.end_time === b.end_time && (a.start_date || '') === (b.start_date || '') && (a.end_date || '') === (b.end_date || ''); };
		/** Checks the entered block and adds it. Returns an error message, or '' when saved. */
		var addBlock = function () {
			var d = st.draft;
			if (!/^\d{2}:\d{2}$/.test(d.start) || !/^\d{2}:\d{2}$/.test(d.end)) return 'Enter a start time and an end time.';
			if (d.end <= d.start) return 'The start time must be before the end time.';
			if (d.from && d.to && d.to < d.from) return 'The end date must be on or after the start date.';
			if (d.to && d.to < A.localDay()) return 'The end date is in the past.';
			if (d.slot && (+d.slot < 5 || +d.slot > 720)) return 'Slot length must be between 5 and 720 minutes.';
			var b = { weekdays: d.days.slice(), start_time: d.start, end_time: d.end, start_date: d.from, end_date: d.to, slot_min: +d.slot || 0 };
			for (var i = 0; i < st.schedule.length; i++) if (sameBlock(st.schedule[i], b)) return 'This time block is already added (time block ' + (i + 1) + ').';
			st.schedule.push(b);
			st.draft = { days: [], start: d.start, end: d.end, from: '', to: '', slot: '' };
			st.dirty = false;
			return '';
		};
		var names = ['Name and type', 'Services', 'Spaces', 'Dates and times', 'Pricing and capacity', 'Public page', 'Preview and publish'];
		var cat = A.category(), kinds = (S.boot.industry || {}).booking_kinds || [];
		var m = A.modal({ title: 'Create a booking page', size: 'lg', body: '<div class="steps" data-steps></div><div data-b></div><div class="form-error" hidden role="alert"></div>', foot: '<button class="btn" data-back>Back</button><button class="btn btn-primary" data-next>Next</button>' });
		var body = m.el.querySelector('[data-b]'), err = m.el.querySelector('.form-error'), nb = m.el.querySelector('[data-next]'), bb = m.el.querySelector('[data-back]');
		var all = function () { return services.concat(st.new_services.map(function (n, i) { return { id: 'n' + i, name: n.name + ' (new)', price: n.price, capacity: n.capacity, duration_min: n.duration_min }; })); };
		var draw = function () {
			m.el.querySelector('[data-steps]').innerHTML = names.map(function (n, i) { return '<span class="' + (i === st.step ? 'on' : '') + '">' + (i + 1) + '. ' + esc(n) + '</span>'; }).join('');
			err.hidden = true;
			bb.disabled = st.step === 0;
			nb.textContent = st.step === names.length - 1 ? (st.publish ? 'Create and publish' : 'Save as draft') : 'Next';
			var s = st.step;
			if (s === 0) body.innerHTML = A.fieldHtml({ k: 'title', label: 'Page name', required: true, placeholder: 'For example: Weekend Kathak classes' }, st.page.title) + A.fieldHtml({ k: 'page_type', label: 'What is this page for?', type: 'select', opts: A.options(PAGE_TYPES, st.page.page_type) }) + A.fieldHtml({ k: 'staff_id', label: 'Instructor (for an instructor page)', type: 'select', opts: A.memberOptions(st.page.staff_id || '', 'None') });
			if (s === 1) body.innerHTML = '<p class="muted small">Choose existing services, or create new ones here. New ones are also added to your service catalogue.</p>' + (services.length ? services.map(function (x) { return '<label class="check"><input type="checkbox" data-sv value="' + x.id + '"' + (st.service_ids.indexOf(+x.id) > -1 ? ' checked' : '') + '> ' + esc(x.name) + ' <small class="muted">' + (+x.duration_min) + ' min</small></label>'; }).join('') : '<p class="muted">No services yet.</p>') +
				'<h3 style="margin-top:12px">New services</h3>' + st.new_services.map(function (n, i) { return '<div class="row"><div class="grow">' + esc(n.name) + ' · ' + n.duration_min + ' min</div><button class="btn btn-sm" data-rmn="' + i + '">Remove</button></div>'; }).join('') +
				'<div class="form-grid">' + A.fieldHtml({ k: 'n_name', label: 'Name' }) + A.fieldHtml({ k: 'n_type', label: 'Service type', type: 'select', opts: A.options(cat.types.map(function (t) { return [t.key, t.label]; }), '') }) + A.fieldHtml({ k: 'n_custom', label: 'Your own type (for Other)' }) + A.fieldHtml({ k: 'n_kind', label: 'Booking type', type: 'select', opts: A.options(kinds.map(function (k) { return [k.key, k.label]; }), '', 'Not set') }) + A.fieldHtml({ k: 'n_dur', label: 'Duration (minutes)', type: 'number', value: 60 }) + A.fieldHtml({ k: 'n_cap', label: 'Seats', type: 'number', value: 1 }) + A.fieldHtml({ k: 'n_setup', label: 'Setup minutes before', type: 'number', value: 0 }) + A.fieldHtml({ k: 'n_clean', label: 'Clean-up minutes after', type: 'number', value: 0 }) + '</div><button type="button" class="btn btn-sm" data-addn>Add this service</button>';
			if (s === 2) body.innerHTML = '<p class="muted small">Which spaces or rooms can these bookings use? Each space takes one booking at a time, so different spaces can run at the same time.</p>' + (spaces.length ? spaces.map(function (x) { return '<label class="check"><input type="checkbox" data-sp value="' + x.id + '"' + (st.space_ids.indexOf(+x.id) > -1 ? ' checked' : '') + '> ' + esc(x.name) + '</label>'; }).join('') : '<p class="muted">No spaces yet. You can skip this, or add spaces under Bookings → Spaces &amp; rooms first.</p>');
			if (s === 3) {
				var dr = st.draft;
				body.innerHTML = '<p class="muted small">When can these be booked? Enter a time block and press <b>Next</b>: it is saved for you. To add several, use <b>Save and add more</b>. Times repeat by the service length unless you set a slot length.</p>' +
					(st.schedule.length ? '<div class="blocks"><h3>Saved time blocks (' + st.schedule.length + ')</h3>' + st.schedule.map(function (x, i) { return '<div class="row block-row"><span class="block-n">' + (i + 1) + '</span><div class="grow">' + esc(blockText(x)) + '</div><button type="button" class="btn btn-sm btn-ghost" data-rms="' + i + '">Remove</button></div>'; }).join('') + '</div>' : '') +
					'<div class="block-form"><h3>' + (st.schedule.length ? 'Add another time block (optional)' : 'Time block') + '</h3><fieldset class="day-pick"><legend>Days <small class="muted">none ticked means every day</small></legend><div class="day-chips">' + DAYS.map(function (d) { return '<label class="chip"><input type="checkbox" data-wd="' + d[0] + '"' + (dr.days.indexOf(d[0]) > -1 ? ' checked' : '') + '><span>' + d[1] + '</span></label>'; }).join('') + '</div></fieldset><div class="form-grid">' + A.fieldHtml({ k: 's_start', label: 'Start time', type: 'time' }, dr.start) + A.fieldHtml({ k: 's_end', label: 'End time', type: 'time' }, dr.end) + A.fieldHtml({ k: 's_from', label: 'Start date', type: 'date', hint: 'optional' }, dr.from) + A.fieldHtml({ k: 's_to', label: 'End date', type: 'date', hint: 'optional' }, dr.to) + A.fieldHtml({ k: 's_slot', label: 'Slot length (minutes)', type: 'number', hint: 'optional' }, dr.slot) + '</div><button type="button" class="btn btn-sm" data-adds>' + A.I.plus + ' Save and add more</button></div>';
			}
			if (s === 4) body.innerHTML = '<p class="muted small">Prices and seats for each service on this page.</p>' + all().filter(function (x) { return String(x.id).charAt(0) === 'n' || st.service_ids.indexOf(+x.id) > -1; }).map(function (x) { var p = st.pricing[x.id] || { price: x.price || 0, capacity: x.capacity || 1, waitlist: false }; return '<div class="row" data-pr="' + x.id + '"><div class="grow"><b>' + esc(x.name) + '</b></div><input type="number" step="0.01" min="0" data-p value="' + esc(p.price) + '" style="width:110px" aria-label="Price"><input type="number" min="1" data-c value="' + esc(p.capacity) + '" style="width:80px" aria-label="Seats"><label class="check" style="margin:0"><input type="checkbox" data-w' + (p.waitlist ? ' checked' : '') + '> Waitlist</label></div>'; }).join('');
			if (s === 5) body.innerHTML = '<p class="muted small">Every public booking page uses the same premium dark design, with your logo, services, prices and seats shown clearly.</p><div class="form-grid">' + A.fieldHtml({ k: 'intro', label: 'Introduction', type: 'textarea', span: true }, st.page.intro) + A.fieldHtml({ k: 'd_desc', label: 'Description', type: 'textarea', span: true }, st.page.design.description) + A.fieldHtml({ k: 'd_cover', label: 'Cover image link', type: 'url', span: true }, st.page.design.cover_url) + '</div><h3>Questions for customers</h3><div data-qs>' + st.page.fields.map(qRow).join('') + '</div><button type="button" class="btn btn-sm" data-qa>Add a question</button>';
			if (s === 6) body.innerHTML = '<dl class="totals" style="max-width:none"><dt>Page</dt><dd>' + esc(st.page.title) + '</dd><dt>Type</dt><dd>' + esc((PAGE_TYPES.filter(function (t) { return t[0] === st.page.page_type; })[0] || [])[1]) + '</dd><dt>Services</dt><dd>' + (st.service_ids.length + st.new_services.length) + '</dd><dt>Spaces</dt><dd>' + (st.space_ids.length || 'Any') + '</dd><dt>Time blocks</dt><dd>' + (st.schedule.length ? st.schedule.map(function (x) { return esc(blockText(x)); }).join('<br>') : 'None: the services use your general opening hours') + '</dd><dt>Questions</dt><dd>' + st.page.fields.length + '</dd></dl>' + A.fieldHtml({ k: 'publish', label: 'Publish now (otherwise save as a draft you can preview)', type: 'check' }, st.publish ? 1 : 0);
		};
		var collect = function () {
			var s = st.step, q = function (k) { var i = body.querySelector('[name=' + k + ']'); return i ? i.value : ''; };
			if (s === 0) { st.page.title = q('title'); st.page.page_type = q('page_type'); st.page.staff_id = +q('staff_id') || 0; if (!st.page.title.trim()) return 'Give the page a name.'; }
			if (s === 1) { st.service_ids = Array.prototype.map.call(body.querySelectorAll('[data-sv]:checked'), function (i) { return +i.value; }); if (!st.service_ids.length && !st.new_services.length) return 'Choose or create at least one service.'; }
			if (s === 2) st.space_ids = Array.prototype.map.call(body.querySelectorAll('[data-sp]:checked'), function (i) { return +i.value; });
			if (s === 3) st.draft = { days: Array.prototype.map.call(body.querySelectorAll('[data-wd]:checked'), function (i) { return i.getAttribute('data-wd'); }), start: q('s_start'), end: q('s_end'), from: q('s_from'), to: q('s_to'), slot: q('s_slot') };
			if (s === 4) body.querySelectorAll('[data-pr]').forEach(function (r) { st.pricing[r.getAttribute('data-pr')] = { price: +r.querySelector('[data-p]').value, capacity: +r.querySelector('[data-c]').value || 1, waitlist: r.querySelector('[data-w]').checked }; });
			if (s === 5) { st.page.intro = q('intro'); st.page.design = { description: q('d_desc'), cover_url: q('d_cover') }; st.page.fields = readQs(body); }
			if (s === 6) st.publish = body.querySelector('[name=publish]').checked;
			return '';
		};
		body.addEventListener('click', function (e) {
			var t = e.target;
			if (t.closest('[data-addn]')) {
				var q = function (k) { return body.querySelector('[name=' + k + ']').value; };
				if (!q('n_name').trim() || !q('n_type')) { err.textContent = 'Give the new service a name and a type.'; err.hidden = false; return; }
				if (q('n_type') === 'other' && !q('n_custom').trim()) { err.textContent = 'Enter your own service type.'; err.hidden = false; return; }
				st.service_ids = Array.prototype.map.call(body.querySelectorAll('[data-sv]:checked'), function (i) { return +i.value; });
				st.new_services.push({ name: q('n_name'), type_key: q('n_type'), type_custom: q('n_custom'), booking_kind: q('n_kind'), duration_min: +q('n_dur') || 60, capacity: +q('n_cap') || 1, setup_min: +q('n_setup') || 0, cleanup_min: +q('n_clean') || 0, price: 0 });
				draw();
			}
			var rn = t.closest('[data-rmn]');
			if (rn) { st.new_services.splice(+rn.getAttribute('data-rmn'), 1); draw(); }
			if (t.closest('[data-adds]')) {
				collect();
				var bad = addBlock();
				if (bad) { err.textContent = bad; err.hidden = false; return; }
				draw();
				A.toast('Time block saved. Add another, or press Next.');
			}
			var rs = t.closest('[data-rms]');
			if (rs) { collect(); st.schedule.splice(+rs.getAttribute('data-rms'), 1); draw(); }
			if (t.closest('[data-qa]')) body.querySelector('[data-qs]').insertAdjacentHTML('beforeend', qRow({}));
			var qr = t.closest('[data-qr]');
			if (qr) qr.closest('.q-row').remove();
		});
		// Typing in the time block marks it as entered, so Next saves it instead of dropping it.
		body.addEventListener('input', function (e) { if (st.step === 3 && e.target.closest('.block-form')) st.dirty = true; });
		body.addEventListener('change', function (e) { if (st.step === 3 && e.target.closest('.block-form')) st.dirty = true; });
		bb.onclick = function () { collect(); st.step = Math.max(0, st.step - 1); draw(); };
		nb.onclick = function () {
			var e = collect();
			if (e) { err.textContent = e; err.hidden = false; return; }
			if (st.step === 3 && (st.dirty || !st.schedule.length)) {
				var bad = addBlock();
				if (bad) { err.textContent = bad; err.hidden = false; return; }
			}
			if (st.step < names.length - 1) { st.step++; draw(); return; }
			var pricing = [], news = st.new_services.map(function (n, i) { var p = st.pricing['n' + i]; return p ? Object.assign({}, n, { price: p.price, capacity: p.capacity, waitlist: p.waitlist }) : n; });
			st.service_ids.forEach(function (id) { if (st.pricing[id]) pricing.push(Object.assign({ id: id }, st.pricing[id])); });
			nb.disabled = true;
			api('booking-pages/wizard', { method: 'POST', body: { page: st.page, service_ids: st.service_ids, new_services: news, pricing: pricing, space_ids: st.space_ids, schedule: st.schedule, publish: st.publish } }).then(function (r) { m.close(); A.toast(st.publish ? 'Published: ' + r.page.public_url : 'Saved as a draft'); done(); }).catch(function (x) { nb.disabled = false; err.textContent = x.message; err.hidden = false; });
		};
		draw();
	}

	/* Spaces and rooms: what can be booked at the same time. */
	function bSpaces(box, mgr) {
		var load = function () {
			return api('spaces').then(function (r) {
				box.innerHTML = '<section class="card card-pad">' + (mgr ? '<div class="toolbar"><span class="grow muted small">Rooms, studios, chairs or courts. Bookings in different spaces can run at the same time; the same space takes one at a time.</span><button class="btn btn-primary" data-add>' + A.I.plus + ' Add space</button></div>' : '') + (r.items.length ? r.items.map(function (s) { return '<div class="row"' + (mgr ? ' data-o="' + s.id + '" style="cursor:pointer"' : '') + '><div class="grow"><b>' + esc(s.name) + '</b><div class="muted small">' + esc(s.kind) + (+s.capacity > 1 ? ' · up to ' + (+s.capacity) + ' people' : '') + (s.description ? ' · ' + esc(s.description) : '') + '</div></div>' + (+s.is_active ? '' : A.badge('inactive')) + '</div>'; }).join('') : A.empty('No spaces yet', 'Add spaces such as Main Dance Studio, Practice Room or Music Room.')) + '</section>';
				var form = function (s) {
					A.formModal({ title: s.id ? 'Edit space' : 'Add space', values: { name: s.name, kind: s.kind || 'room', capacity: s.capacity || 1, description: s.description, is_active: s.id ? +s.is_active : 1, sort: s.sort || 0 },
						fields: [{ k: 'name', label: 'Name', required: true }, { k: 'kind', label: 'Kind', type: 'select', opts: A.options([['room', 'Room'], ['studio', 'Studio'], ['hall', 'Hall'], ['chair', 'Chair'], ['station', 'Station'], ['court', 'Court'], ['table', 'Table'], ['other', 'Other']], s.kind || 'room') }, { k: 'capacity', label: 'Holds up to (people)', type: 'number', min: 1 }, { k: 'sort', label: 'Order', type: 'number' }, { k: 'is_active', label: 'Active', type: 'check' }, { k: 'description', label: 'Description', type: 'textarea', span: true }],
						del: s.id ? function () { return api('spaces/' + s.id, { method: 'DELETE' }).then(load); } : null,
						onSave: function (v) { return api('spaces' + (s.id ? '/' + s.id : ''), { method: s.id ? 'PUT' : 'POST', body: v }).then(load); } });
				};
				var a = box.querySelector('[data-add]');
				if (a) a.onclick = function () { form({}); };
				box.querySelectorAll('[data-o]').forEach(function (x) { x.onclick = function () { form(r.items.filter(function (s) { return +s.id === +x.getAttribute('data-o'); })[0]); }; });
			});
		};
		return load();
	}

	function bCredits(box) {
		return api('bookings/credits').then(function (r) {
			box.innerHTML = '<section class="card card-pad"><p class="muted small">A credit is given when a booking is cancelled or missed with "give a makeup credit" ticked. Use its number when booking the makeup session.</p>' + (r.items.length ? A.tbl([['No.', function (c) { return '#' + c.id; }], ['Customer', function (c) { return esc(c.customer_name || ''); }], ['Service', function (c) { return esc(c.service_name || 'Any'); }], ['Reason', function (c) { return esc(c.reason); }], ['Expires', function (c) { return esc(A.fmtD(c.expires_on)); }], ['Status', function (c) { return A.badge(c.status); }]], r.items) : A.empty('No makeup credits', 'Credits appear here when a missed session is credited.')) + '</section>';
		});
	}

	/* The business's category and its service types, from the shared catalogue. */
	A.category = function () {
		var all = S.boot.categories || [], key = S.boot.workspace.category || 'other';
		return all.filter(function (c) { return c.key === key; })[0] || all.filter(function (c) { return c.key === 'other'; })[0] || { key: 'other', label: 'Other', types: [{ key: 'other', label: 'Other' }] };
	};

	var DAYS = [['mon', 'Mon'], ['tue', 'Tue'], ['wed', 'Wed'], ['thu', 'Thu'], ['fri', 'Fri'], ['sat', 'Sat'], ['sun', 'Sun']];
	function daysText(list) {
		if (!list || !list.length || list.length === 7) return 'Every day';
		if (list.length === 5 && ['mon', 'tue', 'wed', 'thu', 'fri'].every(function (d) { return list.indexOf(d) > -1; })) return 'Weekdays';
		return DAYS.filter(function (d) { return list.indexOf(d[0]) > -1; }).map(function (d) { return d[1]; }).join(', ');
	}
	/* Availability: the only source of bookable times. Nothing is pre-set. */
	function bAvailability(box, mgr) {
		return Promise.all([api('availability'), api('services'), api('spaces')]).then(function (r) {
			var rules = r[0].items, svc = r[1].items, spaces = r[2].items;
			var spName = function (id) { var x = spaces.filter(function (s) { return +s.id === +id; })[0]; return x ? x.name : ''; };
			var svcName = function (ids) { return !ids || !ids.length ? 'All services' : svc.filter(function (s) { return ids.indexOf(+s.id) > -1; }).map(function (s) { return s.name; }).join(', '); };
			box.innerHTML = '<div class="stack"><section class="card"><div class="card-h"><h2>When customers can book</h2>' + (mgr ? '<button class="btn btn-primary btn-sm" data-add>' + A.I.plus + ' Add availability</button>' : '') + '</div><div class="card-b">' +
				'<p class="muted small">Set your own hours. Each block opens bookings on the days and dates you choose. Times start at your start time and repeat every slot length (or every service length), and a booking must finish by the end time. Existing bookings and holidays are always respected.</p>' +
				(rules.length ? '<div class="av-list">' + rules.map(function (a) {
					return '<div class="av-item' + (+a.is_active ? '' : ' off') + '"><div class="av-main"><b>' + esc(a.label || 'Availability') + '</b><span class="av-time">' + esc(a.start_time) + ' – ' + esc(a.end_time) + '</span><span class="muted small">' + esc(daysText(a.weekdays)) + ' · ' + (a.slot_min ? 'every ' + (+a.slot_min) + ' min' : 'every service length') + ' · ' + (a.start_date || a.end_date ? (a.start_date ? esc(A.fmtD(a.start_date)) : 'Now') + ' → ' + (a.end_date ? esc(A.fmtD(a.end_date)) : 'no end') : 'No end date') + ' · ' + esc(svcName(a.service_ids)) + (a.space_id ? ' · ' + esc(spName(a.space_id)) : '') + (a.staff_id ? ' · ' + esc(A.memberName(a.staff_id)) : '') + '</span></div>' + (+a.is_active ? '' : A.badge('paused')) + (mgr ? '<button class="btn btn-sm" data-e="' + a.id + '">Edit</button>' : '') + '</div>';
				}).join('') + '</div>' : A.empty('No availability yet', 'Customers cannot book until you add at least one block of available time.')) + '</div></section>' +
				'<section class="card card-pad"><h2>Check the times customers see</h2><div class="form-grid"><label class="field"><span>Service</span><select data-ps>' + svc.map(function (s) { return '<option value="' + s.id + '">' + esc(s.name) + ' (' + (+s.duration_min) + ' min)</option>'; }).join('') + '</select></label><label class="field"><span>Date</span><input type="date" data-pd value="' + esc(A.localDay()) + '"></label></div><div data-pv class="slot-preview"></div></section></div>';
			var reload = function () { bAvailability(box, mgr).catch(A.fail); };
			var form = function (a) {
				a = a || { weekdays: [], is_active: 1 };
				var picked = a.weekdays || [];
				A.formModal({
					title: a.id ? 'Edit availability' : 'Add availability', values: { label: a.label, start_time: a.start_time || '', end_time: a.end_time || '', slot_min: a.slot_min || '', start_date: a.start_date || '', end_date: a.end_date || '', is_active: +a.is_active },
					fields: [{ k: 'label', label: 'Name', placeholder: 'For example: Weekday hours', span: true }, { k: 'start_time', label: 'Start time', type: 'time', required: true }, { k: 'end_time', label: 'End time', type: 'time', required: true }, { k: 'slot_min', label: 'Slot length (minutes)', type: 'number', min: 5, hint: 'leave empty to use each service\'s own length' }, { k: 'is_active', label: 'Open for booking', type: 'check' }, { k: 'start_date', label: 'From date', type: 'date', hint: 'optional' }, { k: 'end_date', label: 'Until date', type: 'date', hint: 'optional' }, { k: 'space_id', label: 'Only this space or room', type: 'select', opts: A.options(spaces.map(function (s) { return [s.id, s.name]; }), a.space_id || '', 'Any space') }, { k: 'staff_id', label: 'Only this instructor', type: 'select', opts: A.memberOptions(a.staff_id || '', 'Anyone') }],
					extra: '<fieldset class="day-pick"><legend>Days</legend><label class="check"><input type="checkbox" data-every' + (!picked.length ? ' checked' : '') + '> <span>Every day</span></label><div class="day-chips">' + DAYS.map(function (d) { return '<label class="chip"><input type="checkbox" data-day="' + d[0] + '"' + (picked.indexOf(d[0]) > -1 ? ' checked' : '') + '><span>' + d[1] + '</span></label>'; }).join('') + '</div></fieldset>' +
						'<details class="card card-pad"><summary>Only for some services</summary>' + (svc.length ? svc.map(function (s) { return '<label class="check"><input type="checkbox" data-sv value="' + s.id + '"' + ((a.service_ids || []).indexOf(+s.id) > -1 ? ' checked' : '') + '> ' + esc(s.name) + '</label>'; }).join('') : '<p class="muted small">No services yet.</p>') + '<p class="muted small">Leave all unticked to apply to every service.</p></details>',
					del: a.id ? function () { return api('availability/' + a.id, { method: 'DELETE' }).then(reload); } : null,
					ready: function (md) {
						var ev = md.el.querySelector('[data-every]'), days = md.el.querySelectorAll('[data-day]');
						var sync = function () { md.el.querySelector('.day-chips').classList.toggle('dim', ev.checked); };
						ev.onchange = function () { if (ev.checked) days.forEach(function (d) { d.checked = false; }); sync(); };
						days.forEach(function (d) { d.onchange = function () { ev.checked = !Array.prototype.some.call(days, function (x) { return x.checked; }); sync(); }; });
						sync();
					},
					onSave: function (v, md) {
						v.weekdays = md.el.querySelector('[data-every]').checked ? [] : Array.prototype.map.call(md.el.querySelectorAll('[data-day]:checked'), function (i) { return i.getAttribute('data-day'); });
						v.service_ids = Array.prototype.map.call(md.el.querySelectorAll('[data-sv]:checked'), function (i) { return +i.value; });
						v.slot_min = v.slot_min === '' ? 0 : +v.slot_min;
							v.space_id = v.space_id ? +v.space_id : 0;
							v.staff_id = v.staff_id ? +v.staff_id : 0;
						if (!v.start_time || !v.end_time) return Promise.reject(new Error('Enter the start and end times.'));
						return api('availability' + (a.id ? '/' + a.id : ''), { method: a.id ? 'PUT' : 'POST', body: v }).then(reload);
					}
				});
			};
			var add = box.querySelector('[data-add]');
			if (add) add.onclick = function () { form(null); };
			box.querySelectorAll('[data-e]').forEach(function (b) { b.onclick = function () { form(rules.filter(function (x) { return +x.id === +b.getAttribute('data-e'); })[0]); }; });
			var pv = box.querySelector('[data-pv]'), ps = box.querySelector('[data-ps]'), pd = box.querySelector('[data-pd]');
			var preview = function () {
				if (!ps.value) { pv.innerHTML = '<p class="muted small">Add a service to preview its times.</p>'; return; }
				pv.innerHTML = A.loading(1);
				api('bookings/slots', { params: { service_id: ps.value, date: pd.value } }).then(function (x) {
					var list = x.slots || x.items || [];
					pv.innerHTML = list.length ? '<div class="slot-chips">' + list.map(function (s) { return '<span class="chip">' + esc(s.label) + (s.remaining > 1 ? ' <small>' + s.remaining + ' left</small>' : '') + '</span>'; }).join('') + '</div>' : '<p class="muted small">No free times on this date.</p>';
				}).catch(function (e) { pv.innerHTML = '<p class="form-error">' + esc(e.message) + '</p>'; });
			};
			if (ps) { ps.onchange = preview; pd.onchange = preview; preview(); }
		});
	}

	function bSetup(box, mgr) {
		return Promise.all([api('business'), api('closures')]).then(function (r) {
			var b = r[0], soc = b.socials || {};
			var fields = [
				{ k: 'display_name', label: 'Business name', required: true },  { k: 'phone', label: 'Phone', type: 'tel' }, { k: 'email', label: 'Email', type: 'email' }, { k: 'logo_url', label: 'Logo link', type: 'url', hint: 'https address of an image' }, { k: 'cover_url', label: 'Cover image link', type: 'url' }, { k: 'map_url', label: 'Google Maps link', type: 'url' }, { k: 'brand_color', label: 'Brand colour', type: 'color', value: b.brand_color || '#18181B' },
				{ k: 'address', label: 'Address', type: 'textarea', span: true }, { k: 'description', label: 'Description', type: 'textarea', span: true }, { k: 'policies', label: 'Policies', type: 'textarea', span: true },
				{ k: 'website', label: 'Website', type: 'url' }, { k: 'registration_details', label: 'Business registration details', type: 'textarea', span: true },
				{ k: 'auto_invoice', label: 'Create an invoice for each booking', type: 'select', opts: A.options([['off', 'No, I will create them myself'], ['confirmed', 'When a booking is confirmed'], ['paid', 'When a booking is marked paid']], b.auto_invoice || 'off') },
				{ k: 'whatsapp_enabled', label: 'Ask for a phone number for WhatsApp on booking pages', type: 'check' },
				{ k: 'booking_hold_minutes', label: 'Hold a seat while the customer pays online (minutes)', type: 'number', min: 5, max: 120, hint: 'unpaid after this, the seat is released' }, { k: 'manual_hold_hours', label: 'Hold a seat for a booking marked Pending (hours)', type: 'number', min: 1, max: 168 }
			];
			box.innerHTML = '<div class="stack"><section class="card card-pad"><h2>Business details</h2><p class="muted small">Business type: <b>' + esc(b.category_label) + '</b>. To change it, the owner uses <a href="#/settings">Settings → Business type</a>, which shows what is kept and removed first.</p><form data-f><div class="form-grid">' + fields.map(function (f) { return A.fieldHtml(f, b[f.k]); }).join('') + '</div><div class="form-grid">' + ['instagram', 'facebook', 'youtube', 'website'].map(function (k) { return A.fieldHtml({ k: 'soc_' + k, label: k[0].toUpperCase() + k.slice(1), type: 'url' }, soc[k]); }).join('') + '</div>' + (mgr ? '<button class="btn btn-primary" data-s type="button">Save</button>' : '') + '</form></section>' +
				'<section class="card card-pad"><h2>Opening times</h2><p class="muted">Customers can book only the times you set in <a href="#/bookings/availability">Availability</a>: your own start and end times, days, dates and slot length.</p></section>' +
				'<section class="card card-pad"><h2>Holidays and closures</h2>' + (r[1].items.map(function (c) { return '<div class="row"><div class="grow">' + esc(A.fmtD(c.on_date)) + ' ' + esc(c.label) + '</div>' + (mgr ? '<button class="btn btn-sm" data-rc="' + c.id + '">Remove</button>' : '') + '</div>'; }).join('') || '<p class="muted">None.</p>') + (mgr ? '<div class="toolbar" style="margin-top:10px"><input type="date" data-cd aria-label="Date"><input data-cl placeholder="Label" aria-label="Label"><button class="btn" data-ac>Add</button></div>' : '') + '</section></div>';
			var reload = function () { bSetup(box, mgr).catch(A.fail); };
			if (mgr) { A.imagePicker(box, 'logo_url'); A.imagePicker(box, 'cover_url'); }
			var s = box.querySelector('[data-s]');
			if (s) s.onclick = function () {
				var v = A.readForm(box, fields), socials = {};
				['instagram', 'facebook', 'youtube', 'website'].forEach(function (k) { var x = box.querySelector('[name=soc_' + k + ']').value; if (x) socials[k] = x; });
				v.socials = socials;
				api('business', { method: 'PUT', body: v }).then(function () { A.toast('Saved'); return A.reloadBoot(); }).catch(A.fail);
			};
			var ac = box.querySelector('[data-ac]');
			if (ac) ac.onclick = function () { api('closures', { method: 'POST', body: { on_date: box.querySelector('[data-cd]').value, label: box.querySelector('[data-cl]').value } }).then(reload).catch(A.fail); };
			box.querySelectorAll('[data-rc]').forEach(function (x) { x.onclick = function () { api('closures/' + x.getAttribute('data-rc'), { method: 'DELETE' }).then(reload).catch(A.fail); }; });
		});
	}

	/** Adds an upload button after a URL field; the server checks the file's real type. */
	A.imagePicker = function (root, name) {
		var input = root.querySelector('[name=' + name + ']');
		if (!input) return;
		var lab = document.createElement('label');
		lab.className = 'btn btn-sm';
		lab.style.marginTop = '6px';
		lab.innerHTML = 'Upload image<input type="file" accept="image/png,image/jpeg,image/webp" hidden>';
		input.parentNode.appendChild(lab);
		lab.querySelector('input').onchange = function (e) {
			var fd = new FormData();
			fd.append('file', e.target.files[0]);
			fetch(window.NW.root + 'business/image', { method: 'POST', body: fd, credentials: 'same-origin', headers: { 'X-WP-Nonce': window.NW.nonce, 'X-NW-Workspace': S.workspaceId } }).then(function (x) {
				return x.json().then(function (d) { if (!x.ok) throw new Error(d.message); input.value = d.url; A.toast('Uploaded. Save to keep it.'); });
			}).catch(A.fail);
		};
	};

	function bReports(box) {
		return api('bookings/report').then(function (r) {
			var list = function (title, items, k) {
				return '<section class="card card-pad"><h2>' + esc(title) + '</h2>' + (items.length ? items.map(function (x) { return '<div class="row"><div class="grow">' + esc(String(x[k]).replace(/_/g, ' ')) + '</div><b>' + (+x.n) + '</b></div>'; }).join('') : '<p class="muted">No bookings yet.</p>') + '</section>';
			};
			box.innerHTML = '<p class="muted small">Last 30 days and the next 30. ' + (+r.waitlisted) + ' waitlisted now, ' + (+r.overrides) + ' approved overrides.</p><div class="grid">' + list('By status', r.by_status, 'status') + list('By service', r.by_service, 'name') + list('By booking type', r.by_kind.map(function (x) { return { kind: x.kind || 'not set', n: x.n }; }), 'kind') + list('By instructor or staff', r.by_staff, 'name') + list('By booking page', r.by_page, 'title') +
					'<section class="card card-pad"><h2>Space use</h2>' + (r.spaces.length ? r.spaces.map(function (x) { return '<div class="row"><div class="grow">' + esc(x.space) + '</div><span class="muted small">' + x.bookings + ' bookings</span><b>' + x.hours + ' h</b></div>'; }).join('') : '<p class="muted">No spaces yet.</p>') + '</section></div>';
		});
	}

	/* ---- Invoices ---- */
	var INV_S = [['draft', 'Draft'], ['sent', 'Sent'], ['partially_paid', 'Partially paid'], ['paid', 'Paid'], ['overdue', 'Overdue'], ['cancelled', 'Cancelled']];
	var QT_S = [['draft', 'Draft'], ['sent', 'Sent'], ['accepted', 'Accepted'], ['rejected', 'Rejected'], ['expired', 'Expired'], ['converted', 'Invoiced']];

	/**
	 * Phase 5: one document layout for the preview, the detail screen and (on the server) the PDF.
	 * `v` is the server's document view, so what is previewed is what is generated.
	 */
	var money = function (n) { return '₹' + Number(+n || 0).toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); };
	var lines = function (s) { return esc(String(s || '')).replace(/\n/g, '<br>'); };
	A.docView = function (v) {
		var b = v.business || {}, c = v.customer || {}, t = v.totals || {}, inv = v.type === 'invoice', gst = (v.tax_breakdown || []).length > 0;
		var meta = [[inv ? 'Invoice no.' : 'Quotation no.', esc(v.doc_no) + (v.number_pending ? ' <small class="muted">(given on save)</small>' : '')], [inv ? 'Invoice date' : 'Date', esc(A.fmtD(v.date))]];
		if (v.due_date) meta.push(['Due date', esc(A.fmtD(v.due_date))]);
		if (v.valid_until) meta.push(['Valid until', esc(A.fmtD(v.valid_until))]);
		if (gst && v.place_of_supply) meta.push(['Place of supply', esc(v.place_of_supply)]);
		var due = inv ? t.balance : t.grand_total;
		var stamp = ['paid', 'cancelled', 'overdue', 'accepted', 'rejected', 'expired', 'converted'].indexOf(v.status) > -1 ? '<span class="doc-stamp doc-stamp-' + esc(v.status) + '">' + esc(v.status === 'converted' ? 'Invoiced' : v.status) + '</span>' : '';
		var rows = (v.items || []).map(function (it, i) { return '<tr><td>' + (i + 1) + '</td><td><b>' + esc(it.description) + '</b>' + (it.hsn ? '<small>HSN/SAC ' + esc(it.hsn) + '</small>' : '') + '</td><td class="r">' + esc(+it.qty) + '</td><td class="r">' + money(it.unit_price) + '</td><td class="r">' + (+it.discount ? money(it.discount) : '–') + '</td>' + (gst ? '<td class="r">' + (+it.tax_rate ? esc(+it.tax_rate) + '%' : '–') + '</td>' : '') + '<td class="r"><b>' + money(it.amount) + '</b></td></tr>'; }).join('');
		var tot = '<dt>Subtotal</dt><dd>' + money(t.subtotal) + '</dd>' + (+t.discount_total ? '<dt>Discount</dt><dd>− ' + money(t.discount_total) + '</dd><dt>Taxable value</dt><dd>' + money(t.taxable) + '</dd>' : '') + (+t.cgst ? '<dt>CGST</dt><dd>' + money(t.cgst) + '</dd><dt>SGST</dt><dd>' + money(t.sgst) + '</dd>' : '') + (+t.igst ? '<dt>IGST</dt><dd>' + money(t.igst) + '</dd>' : '');
		var pay = v.payment_account, bank = String(v.bank_details || '').trim();
		return '<article class="doc-paper" aria-label="' + esc(v.title + ' ' + v.doc_no) + '">' +
			'<header class="doc-top"><div class="doc-biz">' + (b.logo_url ? '<img class="doc-logo" src="' + esc(A.safeUrl(b.logo_url)) + '" alt="">' : '') + '<h2>' + esc(b.name) + '</h2><p>' + lines(b.address) + (b.phone || b.email ? '<br>' + esc([b.phone, b.email].filter(Boolean).join(' · ')) : '') + (b.website ? '<br>' + esc(b.website) : '') + (b.gstin ? '<br>GSTIN ' + esc(b.gstin) : '') + '</p></div>' +
			'<div class="doc-id"><h1>' + esc(v.title) + '</h1><dl>' + meta.map(function (x) { return '<dt>' + x[0] + '</dt><dd>' + x[1] + '</dd>'; }).join('') + '</dl>' + stamp + '</div></header>' +
			'<div class="doc-parties"><div><span class="doc-cap">' + (inv ? 'Bill to' : 'Prepared for') + '</span><b class="doc-who">' + esc(c.business_name || c.name) + '</b><p>' + (c.business_name && c.name && c.name !== c.business_name ? 'Attn: ' + esc(c.contact_person || c.name) + '<br>' : '') + lines(c.billing_address) + (c.phone || c.email ? '<br>' + esc([c.phone, c.email].filter(Boolean).join(' · ')) : '') + (c.gstin ? '<br>GSTIN ' + esc(c.gstin) : '') + '</p>' + (c.shipping_address && c.shipping_address !== c.billing_address ? '<span class="doc-cap">Ship to</span><p>' + lines(c.shipping_address) + '</p>' : '') + '</div>' +
			'<div class="doc-due"><span>' + (inv ? (+t.amount_paid ? 'Balance due' : 'Amount due') : 'Quoted total') + '</span><b>' + money(due) + '</b><small>' + (inv ? (v.due_date ? 'Due by ' + esc(A.fmtD(v.due_date)) : 'Due on receipt') : (v.valid_until ? 'Valid until ' + esc(A.fmtD(v.valid_until)) : 'Valid on request')) + '</small></div></div>' +
			'<div class="doc-table-wrap"><table class="doc-table"><thead><tr><th>#</th><th>Description</th><th class="r">Qty</th><th class="r">Unit price</th><th class="r">Discount</th>' + (gst ? '<th class="r">GST</th>' : '') + '<th class="r">Amount</th></tr></thead><tbody>' + rows + '</tbody></table></div>' +
			'<div class="doc-sum"><div class="doc-words"><span class="doc-cap">Amount in words</span><p>' + esc(v.amount_words) + '</p>' + (gst ? '<span class="doc-cap">GST summary</span><table class="doc-gst"><thead><tr><th>Rate</th><th class="r">Taxable</th><th class="r">' + (+t.igst ? 'IGST' : 'CGST + SGST') + '</th></tr></thead><tbody>' + v.tax_breakdown.map(function (x) { return '<tr><td>' + esc(+x.rate) + '%</td><td class="r">' + money(x.taxable) + '</td><td class="r">' + (+t.igst ? money(x.igst) : money(x.cgst) + ' + ' + money(x.sgst)) + '</td></tr>'; }).join('') + '</tbody></table>' : '') + '</div>' +
			'<div class="doc-totals"><dl>' + tot + '</dl><div class="doc-grand"><span>' + (inv ? 'Total' : 'Quoted total') + '</span><b>' + money(t.grand_total) + '</b></div>' + (inv && +t.amount_paid ? '<dl><dt>Paid</dt><dd>− ' + money(t.amount_paid) + '</dd><dt class="b">Balance due</dt><dd class="b">' + money(t.balance) + '</dd></dl>' : '') + '</div></div>' +
			(inv && (bank || (pay && pay.qr_url)) ? '<section class="doc-pay"><div><span class="doc-cap">Payment details</span><p>' + lines(bank) + '</p></div>' + (pay && pay.qr_url ? '<figure><img src="' + esc(A.safeUrl(pay.qr_url)) + '" alt="Payment QR code"><figcaption>Scan to pay</figcaption></figure>' : '') + '</section>' : '') +
			(v.notes ? '<section class="doc-note"><span class="doc-cap">Notes</span><p>' + lines(v.notes) + '</p></section>' : '') +
			(v.terms ? '<section class="doc-note"><span class="doc-cap">' + (inv ? 'Terms and conditions' : 'Terms') + '</span><p>' + lines(v.terms) + '</p></section>' : '') +
			'<footer class="doc-sign">' + (b.signature_url ? '<img src="' + esc(A.safeUrl(b.signature_url)) + '" alt="">' : '') + '<span>Authorised signatory</span><b>For ' + esc(b.name) + '</b></footer></article>';
	};
	/** Opens a PDF in a new tab (the same file the download gives). */
	A.openPdf = function (path) {
		var w = window.open('', '_blank');
		fetch(window.NW.root + path, { headers: { 'X-WP-Nonce': window.NW.nonce, 'X-NW-Workspace': S.workspaceId }, credentials: 'same-origin' }).then(function (r) { if (!r.ok) throw new Error('The PDF could not be made.'); return r.blob(); }).then(function (b) {
			var u = URL.createObjectURL(b);
			if (w) w.location = u; else location.href = u;
		}).catch(function (e) { if (w) w.close(); A.toast(e.message, true); });
	};

	V.invoices = function (el, m) {
		A.setTitle('Invoices & Quotes');
		if (!A.gate(el, 'invoice_management')) return;
		var tab = m[1] || 'list', wr = A.can('invoices.write');
		el.innerHTML = '<div class="tabs"><a href="#/invoices" class="' + (tab === 'list' ? 'on' : '') + '">Invoices</a><a href="#/invoices/quotes" class="' + (tab === 'quotes' ? 'on' : '') + '">Quotations</a><a href="#/invoices/settings" class="' + (tab === 'settings' ? 'on' : '') + '">Settings</a></div><div data-c>' + A.loading(5) + '</div>';
		var box = el.querySelector('[data-c]');
		if (tab === 'settings') return invSettings(box, wr);
		if (tab === 'quotes') {
			var qf = { q: '', status: '', from: '', to: '', sort: 'desc' };
			box.innerHTML = '<div class="toolbar"><input type="search" class="grow" data-qq placeholder="Search number, customer, phone or email" aria-label="Search quotations"><input type="date" data-qfrom aria-label="From date"><input type="date" data-qto aria-label="To date"><select data-qsort aria-label="Order"><option value="desc">Newest first</option><option value="asc">Oldest first</option></select>' + (wr ? '<a class="btn btn-primary" href="#/invoices/quotes/new">' + A.I.plus + ' Create quotation</a>' : '') + '</div><div class="chips" data-qs role="group" aria-label="Status"></div><section class="card card-pad" data-ql>' + A.loading(4) + '</section>';
			var ql = box.querySelector('[data-ql]'), chips = box.querySelector('[data-qs]');
			var qload = function () {
				return api('quotes', { params: qf }).then(function (r) {
					var c = r.counts || {}, all = Object.keys(c).reduce(function (a, k) { return a + c[k]; }, 0);
					chips.innerHTML = '<button class="chip' + (!qf.status ? ' on' : '') + '" data-st="">All <small>' + all + '</small></button>' + QT_S.map(function (s) { return '<button class="chip' + (qf.status === s[0] ? ' on' : '') + '" data-st="' + s[0] + '">' + s[1] + ' <small>' + (c[s[0]] || 0) + '</small></button>'; }).join('');
					ql.innerHTML = r.items.length ? A.tbl([
						['No.', function (q) { return '<b>' + esc(q.doc_no) + '</b>'; }], ['Customer', function (q) { return esc(q.customer.business_name || q.customer.name) + (q.customer.phone ? '<div class="muted small">' + esc(q.customer.phone) + '</div>' : ''); }], ['Date', function (q) { return esc(A.fmtD(q.quote_date)); }], ['Valid until', function (q) { return q.valid_until ? esc(A.fmtD(q.valid_until)) : '–'; }], ['Total', function (q) { return '<b>' + A.inr(q.grand_total) + '</b>'; }], ['Status', function (q) { return A.badge(q.status === 'converted' ? 'invoiced' : q.status); }]
					], r.items, function (q) { return 'class="click" onclick="location.hash=\'#/invoices/quotes/' + (+q.id) + '\'"'; }) : A.empty(qf.q || qf.status || qf.from || qf.to ? 'No quotations match' : 'No quotations yet', qf.q || qf.status ? 'Change or clear the search and filters.' : 'Create a quotation and send it to a customer.');
				}).catch(function (e) { ql.innerHTML = '<p class="form-error">' + esc(e.message) + '</p>'; });
			};
			chips.addEventListener('click', function (e) { var b = e.target.closest('[data-st]'); if (b) { qf.status = b.getAttribute('data-st'); qload(); } });
			var qdeb;
			box.querySelector('[data-qq]').oninput = function (e) { clearTimeout(qdeb); qdeb = setTimeout(function () { qf.q = e.target.value.trim(); qload(); }, 300); };
			box.querySelector('[data-qfrom]').onchange = function (e) { qf.from = e.target.value; qload(); };
			box.querySelector('[data-qto]').onchange = function (e) { qf.to = e.target.value; qload(); };
			box.querySelector('[data-qsort]').onchange = function (e) { qf.sort = e.target.value; qload(); };
			return qload();
		}
		var f = {};
		box.innerHTML = '<div class="toolbar"><select data-st aria-label="Status">' + A.options(INV_S, '', 'All statuses') + '</select><input type="search" class="grow" data-q placeholder="Number or customer" aria-label="Search">' + (wr ? '<a class="btn btn-primary" href="#/invoices/new">Create invoice</a>' : '') + '</div><section class="card card-pad" data-l>' + A.loading(4) + '</section>';
		var list = box.querySelector('[data-l]');
		var load = function () {
			return api('invoices', { params: f }).then(function (r) {
				list.innerHTML = r.items.length ? A.tbl([
					['No.', function (v) { return '<b>' + esc(v.doc_no) + '</b>'; }], ['Customer', function (v) { return esc(v.customer.business_name || v.customer.name); }], ['Date', function (v) { return esc(A.fmtD(v.invoice_date)); }], ['Due', function (v) { return v.due_date ? esc(A.fmtD(v.due_date)) : ''; }], ['Total', function (v) { return A.inr(v.grand_total); }], ['Balance', function (v) { return A.inr(v.balance); }], ['Status', function (v) { return A.badge(v.status); }]
				], r.items, function (v) { return 'class="click" onclick="location.hash=\'#/invoices/' + (+v.id) + '\'"'; }) : A.empty('No invoices', 'Invoices you raise for your customers appear here.');
			});
		};
		box.querySelector('[data-st]').onchange = function (e) { f.status = e.target.value; load().catch(A.fail); };
		var deb;
		box.querySelector('[data-q]').oninput = function (e) { clearTimeout(deb); deb = setTimeout(function () { f.q = e.target.value; load().catch(A.fail); }, 300); };
		return load();
	};

	function invSettings(box, wr) {
		return Promise.all([api('invoices/series'), api('business')]).then(function (r) {
			var s = r[0], b = r[1];
			var fields = [
				{ k: 'gst_registered', label: 'This business is GST registered', type: 'check', span: true, hint: 'tax columns stay off otherwise' }, { k: 'gstin', label: 'GSTIN' }, { k: 'state_code', label: 'GST state code', hint: 'for example 27' },
				{ k: 'payment_terms', label: 'Default payment terms', type: 'textarea', span: true }, { k: 'bank_details', label: 'Bank or payment instructions', type: 'textarea', span: true }, { k: 'signature_url', label: 'Signature or stamp image link', type: 'url', span: true }
			];
			box.innerHTML = '<div class="stack"><section class="card card-pad"><h2>Tax and payment details</h2><div class="alert">Please confirm the tax treatment with your accountant. These documents are templates and are not certified as compliant for every tax situation.</div><div class="form-grid">' + fields.map(function (f) { return A.fieldHtml(f, b[f.k]); }).join('') + '</div>' + (wr ? '<button class="btn btn-primary" data-sb>Save</button>' : '') + '</section>' +
				'<section class="card card-pad"><h2>Numbering</h2><div class="form-grid">' + [['invoice', 'Invoice'], ['quote', 'Quotation']].map(function (x) {
					return A.fieldHtml({ k: x[0] + '_prefix', label: x[1] + ' prefix' }, s[x[0]].prefix) + A.fieldHtml({ k: x[0] + '_next', label: 'Next number', type: 'number', min: 1 }, s[x[0]].next_no);
				}).join('') + '</div>' + (wr ? '<button class="btn btn-primary" data-ss>Save numbering</button>' : '') + '</section></div>';
			if (wr) A.imagePicker(box, 'signature_url');
			var sb = box.querySelector('[data-sb]');
			if (sb) sb.onclick = function () { api('business', { method: 'PUT', body: A.readForm(box, fields) }).then(function () { A.toast('Saved'); return A.reloadBoot(); }).catch(A.fail); };
			var ss = box.querySelector('[data-ss]');
			if (ss) ss.onclick = function () {
				var g = function (n) { return box.querySelector('[name=' + n + ']').value; };
				api('invoices/series', { method: 'PUT', body: { invoice: { prefix: g('invoice_prefix'), next_no: g('invoice_next') }, quote: { prefix: g('quote_prefix'), next_no: g('quote_next') } } }).then(function () { A.toast('Saved'); }).catch(A.fail);
			};
		});
	}

	/** Invoice and quotation editor, with live totals. The server recalculates on save. */
	V.docEdit = function (el, m) {
		var type = m[1] === 'quotes' ? 'quote' : 'invoice', id = +m[2] || 0;
		A.setTitle(type === 'quote' ? 'Quotation' : 'Invoice');
		if (!A.gate(el, 'invoice_management')) return;
		if (!A.can('invoices.write')) { el.innerHTML = A.empty('Your role cannot edit invoices', ''); return; }
		var gst = S.boot.workspace.gst_registered;
		return Promise.all([api('customers', { params: { per_page: 200 } }), id ? api((type === 'quote' ? 'quotes/' : 'invoices/') + id) : Promise.resolve({ items: [{}] }), type === 'invoice' && A.has('accounts_finance') ? api('finance/accounts').catch(function () { return { items: [] }; }) : Promise.resolve({ items: [] })]).then(function (r) {
			var accts = (r[2].items || []).filter(function (a) { return +a.is_active !== 0; });
			var d = r[1], items = d.items.map(function (i) { return { description: i.description, hsn: i.hsn, qty: i.qty === undefined ? 1 : +i.qty, unit_price: +i.unit_price || 0, discount: +i.discount || 0, tax_rate: +i.tax_rate || 0 }; });
			var today = A.localDay();
			el.innerHTML = '<form novalidate><section class="card card-pad"><h2>Customer</h2><div class="form-grid">' +
				A.fieldHtml({ k: 'customer_id', label: 'Customer', type: 'select', opts: A.options(r[0].items, d.customer_id, 'Add a new customer') }) +
				A.fieldHtml({ k: 'place_of_supply', label: 'Place of supply', hint: 'GST state code', value: d.place_of_supply || '' }, d.place_of_supply) + '</div><div data-new class="form-grid">' +
				['name:Name', 'business_name:Business name', 'contact_person:Contact person', 'phone:Phone', 'email:Email', 'gstin:GSTIN'].map(function (x) { var p = x.split(':'); return A.fieldHtml({ k: 'nc_' + p[0], label: p[1] }); }).join('') +
				A.fieldHtml({ k: 'nc_billing_address', label: 'Billing address', type: 'textarea' }) + A.fieldHtml({ k: 'nc_shipping_address', label: 'Shipping address', type: 'textarea' }) + '</div></section>' +
				'<section class="card card-pad" style="margin-top:14px"><h2>Details</h2><div class="form-grid">' +
				(type === 'quote' ? A.fieldHtml({ k: 'quote_date', label: 'Date', type: 'date', value: today }, d.quote_date) + A.fieldHtml({ k: 'valid_until', label: 'Valid until', type: 'date' }, d.valid_until) : A.fieldHtml({ k: 'invoice_date', label: 'Invoice date', type: 'date', value: today }, d.invoice_date) + A.fieldHtml({ k: 'due_date', label: 'Due date', type: 'date' }, d.due_date)) + '</div></section>' +
				'<section class="card card-pad" style="margin-top:14px"><h2>Lines</h2><div data-lines></div><button type="button" class="btn btn-sm" data-al>Add line</button><dl class="totals" data-tot></dl>' +
				A.fieldHtml({ k: 'notes', label: 'Notes', type: 'textarea' }, d.notes) + A.fieldHtml({ k: 'terms', label: 'Payment terms', type: 'textarea', hint: 'blank uses your default' }, d.terms) + (type === 'invoice' ? (accts.length ? A.fieldHtml({ k: 'payment_account_id', label: 'Payment account shown on the invoice', type: 'select', opts: A.options(accts.map(function (a) { return [a.id, (a.label || a.kind) + (+a.is_default ? ' (default)' : '')]; }), d.payment_account_id || '', 'Default account or the instructions below') }, d.payment_account_id) : '') + A.fieldHtml({ k: 'bank_details', label: 'Bank or payment instructions', type: 'textarea', hint: 'blank uses the chosen account or your default' }, d.bank_details) : '') + '</section>' +
				'<div class="form-error" hidden role="alert"></div><div class="toolbar doc-actions" style="margin-top:14px"><a class="btn" href="#/invoices' + (type === 'quote' ? '/quotes' : '') + '">Cancel</a><span class="grow"></span><button class="btn" type="button" data-save>Save draft</button><button class="btn btn-primary" type="submit">Preview</button></div></form>';
			var linesBox = el.querySelector('[data-lines]'), tot = el.querySelector('[data-tot]'), form = el.querySelector('form');
			var newBox = el.querySelector('[data-new]'), cs = form.customer_id, pos = form.place_of_supply;
			var togg = function () { newBox.hidden = !!cs.value; };
			cs.onchange = function () {
				togg();
				var c = r[0].items.filter(function (x) { return +x.id === +cs.value; })[0];
				if (c && c.state_code) pos.value = c.state_code;
				totals();
			};
			var paintLines = function () {
				linesBox.innerHTML = items.map(function (it, i) {
					return '<div class="line" data-i="' + i + '"><label class="field span" style="margin:0"><span>Description</span><input data-k="description" value="' + esc(it.description || '') + '"></label><label class="field" style="margin:0"><span>Qty</span><input type="number" step="0.001" min="0" data-k="qty" value="' + esc(it.qty) + '"></label><label class="field" style="margin:0"><span>Price</span><input type="number" step="0.01" min="0" data-k="unit_price" value="' + esc(it.unit_price) + '"></label><label class="field" style="margin:0"><span>Discount</span><input type="number" step="0.01" min="0" data-k="discount" value="' + esc(it.discount) + '"></label>' + (gst ? '<label class="field" style="margin:0"><span>Tax %</span><input type="number" step="0.01" min="0" data-k="tax_rate" value="' + esc(it.tax_rate) + '"></label>' : '<span></span>') + '<button type="button" class="btn btn-sm" data-rm="' + i + '" aria-label="Remove line">×</button></div>';
				}).join('');
			};
			var p = function (n) { return Math.round((+n || 0) * 100); };
			var totals = function () {
				var sub = 0, dis = 0, tax = 0;
				items.forEach(function (it) { var g = Math.round((+it.qty || 0) * p(it.unit_price)), x = p(it.discount), n = g - x; sub += g; dis += x; if (gst) tax += Math.round(n * (+it.tax_rate || 0) / 100); });
				var inter = gst && pos.value && S.boot.workspace.state_code && pos.value !== S.boot.workspace.state_code;
				var rows = [['Subtotal', sub]];
				if (dis) rows.push(['Discount', -dis]);
				if (tax) { if (inter) rows.push(['IGST', tax]); else { var c = Math.floor(tax / 2); rows.push(['CGST', c]); rows.push(['SGST', tax - c]); } }
				tot.innerHTML = rows.map(function (x) { return '<dt>' + x[0] + '</dt><dd>' + A.inr(x[1] / 100) + '</dd>'; }).join('') + '<dt class="b">Total</dt><dd class="b">' + A.inr((sub - dis + tax) / 100) + '</dd>';
			};
			linesBox.addEventListener('input', function (e) {
				var k = e.target.getAttribute('data-k'), l = e.target.closest('[data-i]');
				if (k && l) { items[+l.getAttribute('data-i')][k] = e.target.value; totals(); }
			});
			linesBox.addEventListener('click', function (e) {
				var i = e.target.getAttribute('data-rm');
				if (i !== null) { items.splice(+i, 1); if (!items.length) items.push({ qty: 1, unit_price: 0, discount: 0, tax_rate: 0 }); paintLines(); totals(); }
			});
			el.querySelector('[data-al]').onclick = function () { items.push({ qty: 1, unit_price: 0, discount: 0, tax_rate: items.length ? items[items.length - 1].tax_rate : 0 }); paintLines(); };
			pos.oninput = totals;
			togg();
			paintLines();
			totals();
			var base = type === 'quote' ? 'quotes' : 'invoices', err = form.querySelector('.form-error');
			var collect = function () {
				var body = { items: items, place_of_supply: pos.value, notes: form.notes.value, terms: form.terms.value };
				if (form.bank_details && form.bank_details.value) body.bank_details = form.bank_details.value;
				if (form.payment_account_id) body.payment_account_id = +form.payment_account_id.value || 0;
				if (!form.terms.value) delete body.terms;
				if (cs.value) body.customer_id = cs.value;
				else {
					body.new_customer = {};
					['name', 'business_name', 'contact_person', 'phone', 'email', 'gstin', 'billing_address', 'shipping_address'].forEach(function (k) { body.new_customer[k] = form['nc_' + k].value; });
				}
				['invoice_date', 'due_date', 'quote_date', 'valid_until'].forEach(function (k) { if (form[k]) body[k] = form[k].value; });
				return body;
			};
			var save = function (body, pdf) {
				return api(base + (id ? '/' + id : ''), { method: id ? 'PUT' : 'POST', body: body }).then(function (x) {
					A.toast((type === 'quote' ? 'Quotation ' : 'Invoice ') + x.doc_no + ' saved');
					if (pdf) A.download(base + '/' + x.id + '/pdf', String(x.doc_no).replace(/[^A-Za-z0-9_-]/g, '-') + '.pdf');
					A.go('/invoices/' + (type === 'quote' ? 'quotes/' : '') + x.id);
				});
			};
			form.querySelector('[data-save]').onclick = function (e) { err.hidden = true; e.target.disabled = true; save(collect(), false).catch(function (x) { e.target.disabled = false; err.textContent = x.message; err.hidden = false; }); };
			// Preview: the server checks and totals everything exactly as saving would, writing nothing.
			form.addEventListener('submit', function (e) {
				e.preventDefault();
				err.hidden = true;
				var body = collect();
				if (id) body.id = id;
				api(base + '/preview', { method: 'POST', body: body }).then(function (v) {
					var md = A.modal({ title: 'Preview: ' + v.title + ' ' + v.doc_no, size: 'xl doc-modal', body: A.docView(v), foot: '<button class="btn" data-ed>Back to edit</button><span class="grow"></span><button class="btn" data-sv>Save ' + (type === 'quote' ? 'quotation' : 'invoice') + '</button><button class="btn btn-primary" data-pdf>Save and download PDF</button>' });
					md.el.querySelector('[data-ed]').onclick = md.close;
					var go = function (pdf, btn) { delete body.id; btn.disabled = true; save(body, pdf).then(md.close).catch(function (x) { btn.disabled = false; md.close(); err.textContent = x.message; err.hidden = false; }); };
					md.el.querySelector('[data-sv]').onclick = function (ev) { go(false, ev.target); };
					md.el.querySelector('[data-pdf]').onclick = function (ev) { go(true, ev.target); };
				}).catch(function (x) { err.textContent = x.message; err.hidden = false; err.scrollIntoView({ block: 'center' }); });
			});
		});
	};

	V.invoice = function (el, m) {
		var id = +m[1];
		if (!A.gate(el, 'invoice_management')) return;
		var wr = A.can('invoices.write');
		return api('invoices/' + id).then(function (v) {
			A.setTitle(v.doc_no);
			var c = v.customer, b = v.business, draft = v.stored_status === 'draft', dead = v.status === 'cancelled';
			var act = [];
			if (wr && draft) { act.push('<a class="btn" href="#/invoices/edit/' + v.id + '">Edit</a>'); act.push('<button class="btn btn-primary" data-send>Send</button>'); }
			if (wr && !draft && !dead) { act.push('<button class="btn" data-send>Email</button>'); if (v.balance > 0) { act.push('<button class="btn btn-primary" data-pay>Record payment</button>'); act.push('<button class="btn" data-rem>Send reminder</button>'); } act.push('<button class="btn" data-share>Copy link</button>'); act.push('<button class="btn" data-wa>WhatsApp</button>'); act.push('<button class="btn" data-acct>Payment details</button>'); }
			act.push('<button class="btn" data-pdf>Download PDF</button>');
			act.push('<button class="btn" data-print>Open PDF to print</button>');
			if (wr && !dead) act.push('<button class="btn btn-danger" data-can>Cancel</button>');
			el.innerHTML = '<div class="toolbar"><a class="link" href="#/invoices">← Invoices</a><span class="grow"></span>' + A.badge(v.status) + '</div><div class="toolbar">' + act.join('') + '</div>' +
				(dead ? '<div class="alert alert-bad">Cancelled: ' + esc(v.cancel_reason) + '</div>' : '') + (v.quote_id ? '<p class="small"><a href="#/invoices/quotes/' + v.quote_id + '">From a quotation</a></p>' : '') +
				A.docView(v.view) +
				'<section class="card" style="margin-top:14px"><div class="card-h"><h2>Payments</h2></div><div class="card-b">' + (v.payments.length ? v.payments.map(function (x) {
					return '<div class="row"><div class="grow">' + A.inr(x.amount) + ' <span class="muted small">' + esc(A.fmtD(x.paid_on)) + ' · ' + esc(x.method) + (x.reference ? ' · ' + esc(x.reference) : '') + '</span>' + (x.voided_at ? '<div class="small" style="color:var(--bad)">Voided: ' + esc(x.void_reason) + '</div>' : '') + '</div>' + (wr && !x.voided_at ? '<button class="btn btn-sm" data-void="' + x.id + '">Void</button>' : '') + '</div>';
				}).join('') : '<p class="muted">No payments yet.</p>') + '</div></section>' +
				'<section class="card" style="margin-top:14px"><div class="card-h"><h2>History</h2></div><div class="card-b">' + v.history.map(function (h) { return '<div class="row"><div class="grow">' + esc(String(h.action).replace(/_/g, ' ')) + '</div><span class="muted small">' + esc(A.fmtDT(h.created_at)) + '</span></div>'; }).join('') + '</div></section>';
			var q = function (s) { return el.querySelector(s); };
			var reload = function () { V.invoice(el, m); };
			if (q('[data-wa]')) q('[data-wa]').onclick = function () { A.openWhatsApp('invoices/' + v.id + '/whatsapp'); };
			if (q('[data-acct]')) q('[data-acct]').onclick = function () {
				api('finance/accounts').then(function (r) {
					A.formModal({
						title: 'Payment details on this invoice', ok: 'Save', values: { account_id: v.payment_account_id || '', online_pay: +v.online_pay },
						fields: [{ k: 'account_id', label: 'Show this account', type: 'select', span: true, opts: A.options(r.items.map(function (a) { return [a.id, a.label || a.kind]; }), v.payment_account_id || '', 'Keep what is on the invoice') }, { k: 'online_pay', label: 'Offer online payment on the shared link', type: 'check', span: true, hint: 'needs your Razorpay keys in Accounts & Finance' }],
						onSave: function (x) { return api('invoices/' + v.id + '/payment-account', { method: 'PUT', body: { account_id: +x.account_id || 0, online_pay: x.online_pay } }).then(reload); }
					});
				}).catch(A.fail);
			};
			q('[data-pdf]').onclick = function () { A.download('invoices/' + v.id + '/pdf', v.doc_no.replace(/[^A-Za-z0-9_-]/g, '-') + '.pdf'); };
			if (q('[data-print]')) q('[data-print]').onclick = function () { A.openPdf('invoices/' + v.id + '/pdf'); };
			if (q('[data-send]')) q('[data-send]').onclick = function () {
				A.formModal({
					title: 'Send invoice', ok: draft ? 'Mark as sent' : 'Send email', values: { to: c.email },
					fields: [{ k: 'to', label: 'Email to', hint: 'separate with commas, optional', span: true }],
					onSave: function (x) {
						return api('invoices/' + v.id + '/send', { method: 'POST', body: { to: x.to.split(',').map(function (s) { return s.trim(); }).filter(Boolean) } }).then(function (r) {
							A.toast(r.emailed ? 'Sent' : (r.email_configured ? 'Marked as sent. Email could not be sent.' : 'Marked as sent. Email is not set up on this site, so nothing was emailed.'));
							reload();
						});
					}
				});
			};
			if (q('[data-pay]')) q('[data-pay]').onclick = function () {
				var key = A.uuid();
				A.formModal({
					title: 'Record payment', values: { amount: v.balance, paid_on: A.localDay() },
					fields: [{ k: 'amount', label: 'Amount', type: 'number', step: '0.01', min: 0, required: true }, { k: 'paid_on', label: 'Date', type: 'date' }, { k: 'method', label: 'Method', type: 'select', opts: A.options([['upi', 'UPI'], ['bank', 'Bank transfer'], ['cash', 'Cash'], ['card', 'Card'], ['cheque', 'Cheque'], ['other', 'Other']], 'upi') }, { k: 'reference', label: 'Reference' }, { k: 'notes', label: 'Notes', type: 'textarea', span: true }],
					onSave: function (x) { x.idempotency_key = key; return api('invoices/' + v.id + '/payments', { method: 'POST', body: x }).then(function () { A.toast('Payment recorded'); reload(); }); }
				});
			};
			if (q('[data-rem]')) q('[data-rem]').onclick = function () { api('invoices/' + v.id + '/remind', { method: 'POST', body: {} }).then(function () { A.toast('Reminder sent'); reload(); }).catch(A.fail); };
			if (q('[data-share]')) q('[data-share]').onclick = function () {
				api('invoices/' + v.id + '/share', { method: 'POST', body: {} }).then(function (r) {
					var md = A.modal({ title: 'Share link', body: '<p class="muted small">Anyone with this link can see this invoice. Stop sharing to make the old link invalid.</p><input readonly value="' + esc(r.url) + '" onclick="this.select()">', foot: '<button class="btn btn-danger" data-rv>Stop sharing</button><button class="btn btn-primary" data-cp>Copy</button>' });
					md.el.querySelector('[data-cp]').onclick = function () { navigator.clipboard.writeText(r.url).then(function () { A.toast('Copied'); }); };
					md.el.querySelector('[data-rv]').onclick = function () { api('invoices/' + v.id + '/share', { method: 'POST', body: { revoke: true } }).then(function () { A.toast('Old link no longer works'); md.close(); }).catch(A.fail); };
				}).catch(A.fail);
			};
			if (q('[data-can]')) q('[data-can]').onclick = function () {
				A.ask('Cancel ' + v.doc_no, 'Reason (kept on the record)', { ok: 'Cancel invoice' }).then(function (why) { if (why !== null) api('invoices/' + v.id + '/cancel', { method: 'POST', body: { reason: why } }).then(reload).catch(A.fail); });
			};
			el.querySelectorAll('[data-void]').forEach(function (b) {
				b.onclick = function () { A.ask('Void payment', 'Reason (kept on the record)', { ok: 'Void' }).then(function (why) { if (why !== null) api('invoices/' + v.id + '/payments/' + b.getAttribute('data-void') + '/void', { method: 'POST', body: { reason: why } }).then(reload).catch(A.fail); }); };
			});
		});
	};

	V.quote = function (el, m) {
		var id = +m[1];
		if (!A.gate(el, 'invoice_management')) return;
		var wr = A.can('invoices.write');
		return api('quotes/' + id).then(function (q) {
			A.setTitle(q.doc_no);
			var act = [], st = q.stored_status || q.status;
			if (wr && st === 'draft') { act.push('<a class="btn" href="#/invoices/quotes/edit/' + q.id + '">Edit</a>'); act.push('<button class="btn btn-primary" data-st="sent">Mark as sent</button>'); }
			if (wr && st === 'sent') { act.push('<button class="btn btn-primary" data-st="accepted">Mark accepted</button>'); act.push('<button class="btn btn-danger" data-st="rejected">Mark rejected</button>'); }
			if (wr && st === 'accepted') act.push('<button class="btn btn-primary" data-conv>Convert to invoice</button>');
			act.push('<button class="btn" data-pdf>Download PDF</button>');
			act.push('<button class="btn" data-open>Open PDF</button>');
			el.innerHTML = '<div class="toolbar"><a class="link" href="#/invoices/quotes">← Quotations</a><span class="grow"></span>' + A.badge(q.status === 'converted' ? 'invoiced' : q.status) + '</div><div class="toolbar doc-actions">' + act.join('') + '</div>' +
				(q.status === 'expired' ? '<div class="alert">This quotation passed its validity date. You can still mark it accepted or rejected.</div>' : '') +
				(q.converted_invoice_id ? '<p><a href="#/invoices/' + q.converted_invoice_id + '">View the invoice made from this quotation</a></p>' : '') + A.docView(q.view);
			el.querySelectorAll('[data-st]').forEach(function (b) { b.onclick = function () { api('quotes/' + id + '/status', { method: 'POST', body: { status: b.getAttribute('data-st') } }).then(function () { V.quote(el, m); }).catch(A.fail); }; });
			var conv = el.querySelector('[data-conv]');
			if (conv) conv.onclick = function () { api('quotes/' + id + '/convert', { method: 'POST', body: {} }).then(function (v) { A.toast('Invoice ' + v.doc_no + ' created'); A.go('/invoices/' + v.id); }).catch(A.fail); };
			el.querySelector('[data-pdf]').onclick = function () { A.download('quotes/' + id + '/pdf', q.doc_no.replace(/[^A-Za-z0-9_-]/g, '-') + '.pdf'); };
			el.querySelector('[data-open]').onclick = function () { A.openPdf('quotes/' + id + '/pdf'); };
		});
	};

	A.route(/^\/bookings(?:\/(list|availability|services|spaces|pages|credits|setup|reports))?$/, 'bookings');
	A.route(/^\/invoices\/(quotes)\/new$/, 'docEdit');
	A.route(/^\/invoices\/(quotes)\/edit\/(\d+)$/, 'docEdit');
	A.route(/^\/invoices\/(new)$/, 'docEdit');
	A.route(/^\/invoices\/(edit)\/(\d+)$/, 'docEdit');
	A.route(/^\/invoices\/quotes\/(\d+)$/, 'quote');
	A.route(/^\/invoices\/(\d+)$/, 'invoice');
	A.route(/^\/invoices(?:\/(quotes|settings))?$/, 'invoices');
})();
