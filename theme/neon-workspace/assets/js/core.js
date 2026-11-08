/* Neon Workspace app core: API, helpers, shell, router, PWA. No build step and no dependencies. */
(function () {
	'use strict';
	var C = window.NW;
	var A = window.App = { views: {}, S: {} };
	var S = A.S;

	var esc = A.esc = function (s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (m) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[m]; }); };
	A.safeUrl = function (u) { return /^https?:\/\//i.test(String(u || '')) ? esc(u) : '#'; };
	A.uuid = function () { return Date.now().toString(36) + Math.random().toString(36).slice(2, 12); };

	function icon(p) { return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' + p + '</svg>'; }
	var I = A.I = {
		home: icon('<path d="M3 11l9-8 9 8"/><path d="M5 10v10h14V10"/>'),
		cal: icon('<rect x="3" y="5" width="18" height="16" rx="2"/><path d="M3 10h18M8 3v4M16 3v4"/>'),
		task: icon('<path d="M9 11l3 3 8-8"/><path d="M20 12v7a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h9"/>'),
		book: icon('<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>'),
		more: icon('<path d="M4 6h16M4 12h16M4 18h16"/>'),
		bell: icon('<path d="M6 9a6 6 0 1 1 12 0c0 7 3 8 3 8H3s3-1 3-8"/><path d="M10 21h4"/>'),
		plus: icon('<path d="M12 5v14M5 12h14"/>'),
		x: icon('<path d="M6 6l12 12M18 6L6 18"/>'),
		lock: icon('<rect x="5" y="11" width="14" height="10" rx="2"/><path d="M8 11V7a4 4 0 0 1 8 0v4"/>'),
		folder: icon('<path d="M3 7a2 2 0 0 1 2-2h4l2 2h8a2 2 0 0 1 2 2v9a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/>'),
		sun: icon('<path d="M4 6h16M4 12h10M4 18h7"/><path d="M17 15l2 2 3-4"/>'),
		users: icon('<circle cx="9" cy="8" r="3.5"/><path d="M3 20c0-3.3 2.7-6 6-6s6 2.7 6 6"/><path d="M16 11a3 3 0 0 0 0-6M21 20c0-2.6-1.6-4.8-4-5.6"/>'),
		tag: icon('<path d="M3 12V5a2 2 0 0 1 2-2h7l9 9-9 9z"/><circle cx="8" cy="8" r="1.5"/>'),
		doc: icon('<path d="M6 3h9l4 4v14H6z"/><path d="M15 3v4h4M9 12h7M9 16h7"/>'),
		coin: icon('<circle cx="12" cy="12" r="9"/><path d="M9 8h6M9 11h6M10 8c3 0 3 6-1 6l5 3"/>'),
		team: icon('<circle cx="12" cy="8" r="3.5"/><path d="M5 20c0-3.9 3.1-7 7-7s7 3.1 7 7"/>'),
		drive: icon('<path d="M8 3h8l6 10-4 7H6l-4-7z"/><path d="M8 3l6 10h8M2 13h12l4 7"/>'),
		link: icon('<path d="M10 14a4 4 0 0 0 6 0l3-3a4 4 0 0 0-6-6l-1 1"/><path d="M14 10a4 4 0 0 0-6 0l-3 3a4 4 0 0 0 6 6l1-1"/>'),
		form: icon('<rect x="4" y="3" width="16" height="18" rx="2"/><path d="M8 8h8M8 12h8M8 16h4"/>'),
		ticket: icon('<path d="M3 8a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2v2a2 2 0 0 0 0 4v2a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-2a2 2 0 0 0 0-4z"/><path d="M14 6v12"/>'),
		meet: icon('<rect x="3" y="6" width="13" height="12" rx="2"/><path d="M16 10l5-3v10l-5-3"/>'),
		note: icon('<path d="M5 3h14v12l-6 6H5z"/><path d="M19 15h-6v6"/>'),
		chart: icon('<path d="M4 20V10M10 20V4M16 20v-7M22 20H2"/>'),
		bolt: icon('<path d="M13 2L4 14h7l-1 8 9-12h-7z"/>'),
		plug: icon('<path d="M9 2v6M15 2v6M6 8h12v4a6 6 0 0 1-12 0zM12 18v4"/>'),
		card: icon('<rect x="2" y="5" width="20" height="14" rx="2"/><path d="M2 10h20"/>'),
		gear: icon('<circle cx="12" cy="12" r="3"/><path d="M19 12l2-1-1-3-2 .5-1.500-1.500.5-2-3-1-1 2h-2l-1-2-3 1 .5 2L6 8.500l-2-.5-1 3 2 1v1l-2 1 1 3 2-.5L7.500 17l-.5 2 3 1 1-2h1l1 2 3-1-.5-2 1.500-1.500 2 .5 1-3-2-1z"/>'),
		sunI: icon('<circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4 12H2M22 12h-2M5.600 5.600L4.200 4.200M19.800 19.800l-1.400-1.400M18.400 5.600l1.400-1.400M4.200 19.800l1.400-1.400"/>'),
		moon: icon('<path d="M21 13a9 9 0 1 1-10-10 7 7 0 0 0 10 10z"/>'),
		auto: icon('<rect x="3" y="4" width="18" height="13" rx="2"/><path d="M8 21h8M12 17v4"/>'),
		search: icon('<circle cx="11" cy="11" r="7"/><path d="M20 20l-3.500-3.500"/>'),
		target: icon('<circle cx="12" cy="12" r="9"/><circle cx="12" cy="12" r="4"/>'),
		lead: icon('<circle cx="10" cy="8" r="3.500"/><path d="M3 20c0-3.600 3-6 7-6 1.500 0 2.800.3 4 .9"/><path d="M18 14v6M15 17h6"/>')
	};

	/* ---- API ---- */
	A.api = function (path, o) {
		o = o || {};
		var url = C.root + path, h = { 'X-WP-Nonce': C.nonce };
		if (S.workspaceId) h['X-NW-Workspace'] = S.workspaceId;
		if (o.params) {
			var q = Object.keys(o.params).filter(function (k) { return o.params[k] !== '' && o.params[k] != null; }).map(function (k) { return encodeURIComponent(k) + '=' + encodeURIComponent(o.params[k]); }).join('&');
			if (q) url += (url.indexOf('?') > -1 ? '&' : '?') + q;
		}
		var opt = { method: o.method || 'GET', headers: h, credentials: 'same-origin' };
		if (o.body !== undefined) { h['Content-Type'] = 'application/json'; opt.body = JSON.stringify(o.body); }
		return fetch(url, opt).catch(function () {
			var e = new Error(navigator.onLine ? 'Could not reach the server. Please try again.' : 'You are offline. This needs a connection.');
			e.network = true;
			throw e;
		}).then(function (r) {
			return r.json().catch(function () { return {}; }).then(function (d) {
				if (!r.ok) {
					var known = /^(nwc|ndwd)_/.test(String(d.code || ''));
					// Only messages this product wrote reach the page. Raw platform errors stay out of sight.
					var e = new Error(known && d.message ? d.message : (r.status >= 500 ? 'Something went wrong on our side. Please try again.' : 'Something went wrong. Please try again.'));
					e.status = r.status; e.code = d.code; e.data = d.data || {};
					throw e;
				}
				return d;
			});
		});
	};
	A.download = function (path, name) {
		return fetch(C.root + path, { headers: { 'X-WP-Nonce': C.nonce, 'X-NW-Workspace': S.workspaceId }, credentials: 'same-origin' }).then(function (r) {
			if (!r.ok) return r.json().catch(function () { return {}; }).then(function (d) { throw new Error(/^(nwc|ndwd)_/.test(String(d.code || '')) && d.message ? d.message : 'Download failed. Please try again.'); });
			return r.blob();
		}).then(function (b) {
			var a = document.createElement('a');
			a.href = URL.createObjectURL(b);
			a.download = name;
			a.click();
			setTimeout(function () { URL.revokeObjectURL(a.href); }, 5000);
		}).catch(function (e) { A.toast(e.message, true); });
	};

	/* ---- NeonDigital attachments (Phase 4): stored by WorkDesk, read only through this Workspace's signed link ---- */
	A.FILE_RULES = { types: ['pdf', 'png', 'jpg', 'jpeg', 'gif', 'webp', 'docx', 'xlsx', 'pptx', 'zip', 'txt', 'csv'], max_total: 5242880, max_files: 5 };
	A.fileSize = function (n) { n = +n || 0; return n < 1024 ? n + ' B' : n < 1048576 ? (n / 1024).toFixed(n < 10240 ? 1 : 0) + ' KB' : (n / 1048576).toFixed(1) + ' MB'; };
	var extOf = function (n) { var m = /\.([a-z0-9]+)$/i.exec(String(n || '')); return m ? m[1].toLowerCase() : ''; };
	A.fileIcon = function (ext) { return { pdf: 'PDF', png: 'IMG', jpg: 'IMG', jpeg: 'IMG', gif: 'IMG', webp: 'IMG', docx: 'DOC', xlsx: 'XLS', pptx: 'PPT', zip: 'ZIP', csv: 'CSV', txt: 'TXT' }[ext] || 'FILE'; };
	/** Opens (preview) or saves a file. Never a direct URL: the bytes come through the signed route. */
	A.openAttachment = function (id, name, preview) {
		if (!preview) return A.download('agency/attachments/' + (+id), name);
		var w = window.open('', '_blank');
		return fetch(C.root + 'agency/attachments/' + (+id), { headers: { 'X-WP-Nonce': C.nonce, 'X-NW-Workspace': S.workspaceId }, credentials: 'same-origin' }).then(function (r) {
			if (!r.ok) throw new Error('This file is not available.');
			return r.blob();
		}).then(function (b) { var u = URL.createObjectURL(b); if (w) w.location = u; else A.download('agency/attachments/' + (+id), name); setTimeout(function () { URL.revokeObjectURL(u); }, 60000); })
			.catch(function (e) { if (w) w.close(); A.toast(e.message, true); });
	};
	/** A list of attached files: name, type, size; click to preview (images, PDF) or download. */
	A.attachList = function (list) {
		if (!list || !list.length) return '';
		return '<div class="att-list">' + list.map(function (f) {
			return '<button type="button" class="att" data-att="' + (+f.id) + '" data-att-name="' + esc(f.name) + '" data-att-prev="' + (f.preview ? 1 : 0) + '" title="' + (f.preview ? 'Preview ' : 'Download ') + esc(f.name) + '"><span class="att-ic">' + A.fileIcon(f.ext || extOf(f.name)) + '</span><span class="att-txt"><b>' + esc(f.name) + '</b><small class="muted">' + esc((f.ext || extOf(f.name)).toUpperCase()) + ' · ' + A.fileSize(f.size) + (f.note ? ' · ' + esc(f.note) : '') + '</small></span></button>';
		}).join('') + '</div>';
	};
	document.addEventListener('click', function (e) {
		var b = e.target.closest && e.target.closest('[data-att]');
		if (!b) return;
		e.preventDefault();
		A.openAttachment(b.getAttribute('data-att'), b.getAttribute('data-att-name'), b.getAttribute('data-att-prev') === '1');
	});
	/**
	 * A file picker that shows each chosen file with its type, size and status (ready, uploading, sent,
	 * refused). Checks type and the 5 MB total before anything is sent. picker.files() → Promise of
	 * [{name, data}] ready to send; picker.status('uploading'|'sent'|'failed', message); picker.clear().
	 */
	A.attachPicker = function (host, rules) {
		rules = rules || A.FILE_RULES;
		var chosen = [], state = '', note = '';
		host.innerHTML = '<div class="att-pick"><label class="btn btn-sm att-add"><input type="file" multiple hidden data-af accept="' + rules.types.map(function (t) { return '.' + t; }).join(',') + '">Attach files</label><div data-al></div></div>';
		var input = host.querySelector('[data-af]'), out = host.querySelector('[data-al]');
		var total = function () { return chosen.reduce(function (s, f) { return s + f.size; }, 0); };
		var paint = function () {
			out.innerHTML = chosen.length ? '<div class="att-list att-pending">' + chosen.map(function (f, i) {
				var st = f.error ? '<span class="att-st bad">' + esc(f.error) + '</span>' : state === 'uploading' ? '<span class="att-st">Uploading…</span>' : state === 'sent' ? '<span class="att-st ok">Sent</span>' : state === 'failed' ? '<span class="att-st bad">Not sent</span>' : '<span class="att-st">Ready</span>';
				return '<div class="att' + (f.error ? ' att-bad' : '') + '"><span class="att-ic">' + A.fileIcon(extOf(f.name)) + '</span><span class="att-txt"><b>' + esc(f.name) + '</b><small class="muted">' + esc(extOf(f.name).toUpperCase()) + ' · ' + A.fileSize(f.size) + '</small></span>' + st + (state === 'uploading' ? '' : '<button type="button" class="link small" data-arm="' + i + '" aria-label="Remove ' + esc(f.name) + '">Remove</button>') + '</div>';
			}).join('') + '</div><p class="muted small">' + A.fileSize(total()) + ' of ' + A.fileSize(rules.max_total) + (note ? ' · ' + esc(note) : '') + '</p>' : '';
			out.querySelectorAll('[data-arm]').forEach(function (b) { b.onclick = function () { chosen.splice(+b.getAttribute('data-arm'), 1); state = ''; paint(); }; });
		};
		input.onchange = function () {
			Array.prototype.forEach.call(input.files, function (f) {
				var err = rules.types.indexOf(extOf(f.name)) < 0 ? 'Type not allowed' : '';
				if (!err && chosen.length >= rules.max_files) err = 'At most ' + rules.max_files + ' files';
				chosen.push({ file: f, name: f.name, size: f.size, error: err });
			});
			input.value = '';
			state = '';
			note = total() > rules.max_total ? 'Over the 5 MB limit: remove a file' : '';
			paint();
		};
		return {
			count: function () { return chosen.length; },
			files: function () {
				if (chosen.some(function (f) { return f.error; })) return Promise.reject(new Error('Remove the files that cannot be sent first.'));
				if (total() > rules.max_total) return Promise.reject(new Error('Attachments can be at most 5 MB in total.'));
				return Promise.all(chosen.map(function (f) {
					return new Promise(function (ok, no) { var rd = new FileReader(); rd.onload = function () { ok({ name: f.name, data: String(rd.result).replace(/^data:[^,]*,/, '') }); }; rd.onerror = function () { no(new Error(f.name + ' could not be read.')); }; rd.readAsDataURL(f.file); });
				}));
			},
			status: function (s, msg) { state = s; note = msg || ''; paint(); },
			clear: function () { chosen = []; state = ''; note = ''; paint(); }
		};
	};

	/* ---- UI helpers ---- */
	A.toast = function (msg, bad) {
		var t = document.createElement('div');
		t.className = 'toast' + (bad ? ' err' : '');
		t.setAttribute('role', bad ? 'alert' : 'status');
		t.textContent = msg;
		document.getElementById('toasts').appendChild(t);
		setTimeout(function () { t.remove(); }, bad ? 6000 : 3000);
	};
	A.fail = function (e) { A.toast(e && e.message ? e.message : String(e), true); };
	A.modal = function (o) {
		var ov = document.createElement('div');
		ov.className = 'overlay';
		ov.setAttribute('role', 'dialog');
		ov.setAttribute('aria-modal', 'true');
		ov.innerHTML = '<div class="modal ' + (o.size || '') + '"><div class="modal-h"><h2>' + esc(o.title) + '</h2><button class="icon-btn" data-x aria-label="Close">' + I.x + '</button></div><div class="modal-b"></div>' + (o.foot ? '<div class="modal-f">' + o.foot + '</div>' : '') + '</div>';
		var body = ov.querySelector('.modal-b');
		if (typeof o.body === 'string') body.innerHTML = o.body; else if (o.body) body.appendChild(o.body);
		document.body.appendChild(ov);
		var prev = document.activeElement;
		var close = function () { ov.remove(); document.removeEventListener('keydown', esck); if (prev && prev.focus) prev.focus(); };
		var esck = function (e) { if (e.key === 'Escape') close(); };
		document.addEventListener('keydown', esck);
		ov.querySelector('[data-x]').onclick = close;
		ov.addEventListener('mousedown', function (e) { if (e.target === ov) close(); });
		return { el: ov, body: body, close: close };
	};
	A.confirm = function (title, text, ok, danger) {
		return new Promise(function (res) {
			var m = A.modal({ title: title, body: '<p>' + esc(text) + '</p>', foot: '<button class="btn" data-n>Back</button><button class="btn ' + (danger ? 'btn-danger' : 'btn-primary') + '" data-y>' + esc(ok || 'Confirm') + '</button>' });
			m.el.querySelector('[data-n]').onclick = function () { m.close(); res(false); };
			m.el.querySelector('[data-y]').onclick = function () { m.close(); res(true); };
		});
	};
	A.ask = function (title, label, opt) {
		opt = opt || {};
		return new Promise(function (res) {
			var m = A.modal({ title: title, body: '<label class="field"><span>' + esc(label) + '</span><textarea data-v rows="3"></textarea></label>', foot: '<button class="btn" data-n>Back</button><button class="btn btn-primary" data-y>' + esc(opt.ok || 'Save') + '</button>' });
			m.el.querySelector('[data-n]').onclick = function () { m.close(); res(null); };
			m.el.querySelector('[data-y]').onclick = function () { var v = m.el.querySelector('[data-v]').value; m.close(); res(v); };
		});
	};
	A.empty = function (title, text) { return '<div class="empty"><b>' + esc(title) + '</b>' + esc(text || '') + '</div>'; };
	A.loading = function (n) { return new Array((n || 3) + 1).join('<div class="skel"></div>'); };
	A.badge = function (s, label) {
		var t = { active: 'ok', confirmed: 'ok', paid: 'ok', done: 'ok', completed: 'ok', approved: 'ok', accepted: 'ok', converted: 'ok', captured: 'ok', renewal_due: 'warn', grace: 'warn', pending: 'warn', pending_approval: 'warn', partially_paid: 'warn', revision_requested: 'warn', review: 'warn', trial: 'info', sent: 'info', submitted: 'info', in_review: 'info', in_progress: 'info', in_process: 'info', new: 'info', interested: 'violet', contacted: 'info', locked: 'bad', overdue: 'bad', rejected: 'bad', cancelled: 'bad', lost: 'bad', no_show: 'bad', urgent: 'bad', high: 'warn' }[s];
		return '<span class="badge' + (t ? ' badge-' + t : '') + '">' + esc(label || String(s).replace(/_/g, ' ')) + '</span>';
	};
	A.inr = function (n) { var v = Number(n || 0); return (v < 0 ? '-' : '') + '₹' + Math.abs(v).toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); };

	/* Dates: stored in UTC, shown in the workspace's timezone. */
	A.tz = function () { return S.boot ? S.boot.workspace.timezone : 'Asia/Kolkata'; };
	A.toDate = function (v) { return new Date(/Z$|[+-]\d\d:\d\d$/.test(v) ? v : String(v).replace(' ', 'T') + 'Z'); };
	A.fmtDT = function (v) { return v ? A.toDate(v).toLocaleString('en-IN', { timeZone: A.tz(), dateStyle: 'medium', timeStyle: 'short' }) : '—'; };
	A.fmtT = function (v) { return A.toDate(v).toLocaleTimeString('en-IN', { timeZone: A.tz(), hour: 'numeric', minute: '2-digit' }); };
	A.fmtD = function (v) { return v ? new Date(String(v).slice(0, 10) + 'T12:00:00Z').toLocaleDateString('en-IN', { timeZone: 'UTC', day: 'numeric', month: 'short', year: 'numeric' }) : '—'; };
	A.localDay = function (d) { return new Intl.DateTimeFormat('en-CA', { timeZone: A.tz(), year: 'numeric', month: '2-digit', day: '2-digit' }).format(d || new Date()); };
	A.localDT = function (v) {
		if (!v) return '';
		var p = new Intl.DateTimeFormat('en-CA', { timeZone: A.tz(), year: 'numeric', month: '2-digit', day: '2-digit', hour: '2-digit', minute: '2-digit', hourCycle: 'h23' }).formatToParts(A.toDate(v));
		var g = function (t) { return p.filter(function (x) { return x.type === t; })[0].value; };
		return g('year') + '-' + g('month') + '-' + g('day') + 'T' + g('hour') + ':' + g('minute');
	};
	A.addDays = function (day, n) { var d = new Date(day + 'T12:00:00Z'); d.setUTCDate(d.getUTCDate() + n); return d.toISOString().slice(0, 10); };

	A.memberName = function (id) { var m = (S.boot.members || []).filter(function (x) { return +x.id === +id; })[0]; return m ? m.name : ''; };
	A.memberOptions = function (cur, empty) {
		return (empty !== undefined ? '<option value="">' + esc(empty) + '</option>' : '') + S.boot.members.map(function (m) { return '<option value="' + m.id + '"' + (+cur === +m.id ? ' selected' : '') + '>' + esc(m.name) + '</option>'; }).join('');
	};
	A.options = function (list, cur, empty) {
		return (empty !== undefined ? '<option value="">' + esc(empty) + '</option>' : '') + list.map(function (o) {
			var v = Array.isArray(o) ? o[0] : o.id, l = Array.isArray(o) ? o[1] : (o.name || o.title);
			return '<option value="' + esc(v) + '"' + (String(cur == null ? '' : cur) === String(v) ? ' selected' : '') + '>' + esc(l) + '</option>';
		}).join('');
	};
	A.can = function (p) { return !!(S.boot && S.boot.perms[p]); };
	A.feature = function (c) { return S.boot.features[c] || {}; };
	A.has = function (c) { return !!A.feature(c).allowed; };

	/**
	 * Form in a modal, from a field list: {k, label, type, opts, span, required, hint, value}.
	 * onSave(values) returns a promise.
	 */
	A.fieldHtml = function (f, v) {
		var val = v === undefined || v === null ? (f.value === undefined ? '' : f.value) : v, inner;
		var req = f.required ? ' required' : '';
		if (f.type === 'textarea') inner = '<textarea name="' + f.k + '" rows="' + (f.rows || 3) + '"' + req + '>' + esc(val) + '</textarea>';
		else if (f.type === 'select') inner = '<select name="' + f.k + '"' + req + '>' + f.opts + '</select>';
		else if (f.type === 'check') return '<label class="check ' + (f.span ? 'span-2' : '') + '"><input type="checkbox" name="' + f.k + '"' + (val && val !== '0' ? ' checked' : '') + '> <span>' + esc(f.label) + (f.hint ? ' <small class="muted">' + esc(f.hint) + '</small>' : '') + '</span></label>';
		else inner = '<input name="' + f.k + '" type="' + (f.type || 'text') + '" value="' + esc(val) + '"' + req + (f.step ? ' step="' + f.step + '"' : '') + (f.min !== undefined ? ' min="' + f.min + '"' : '') + (f.placeholder ? ' placeholder="' + esc(f.placeholder) + '"' : '') + '>';
		return '<label class="field ' + (f.span ? 'span-2' : '') + '"><span>' + esc(f.label) + (f.hint ? ' <small class="muted">' + esc(f.hint) + '</small>' : '') + '</span>' + inner + '</label>';
	};
	A.readForm = function (root, fields) {
		var o = {};
		fields.forEach(function (f) {
			var i = root.querySelector('[name="' + f.k + '"]');
			if (!i) return;
			o[f.k] = f.type === 'check' ? i.checked : i.value;
		});
		return o;
	};
	A.formModal = function (o) {
		var m = A.modal({
			title: o.title, size: o.size,
			body: '<form novalidate><div class="form-grid">' + o.fields.map(function (f) { return A.fieldHtml(f, o.values ? o.values[f.k] : undefined); }).join('') + '</div>' + (o.extra || '') + '<div class="form-error" hidden role="alert"></div></form>',
			foot: (o.del ? '<button class="btn btn-danger" data-del>Delete</button>' : '') + '<button class="btn" data-c>Cancel</button><button class="btn btn-primary" data-s>' + esc(o.ok || 'Save') + '</button>'
		});
		var err = m.el.querySelector('.form-error'), save = m.el.querySelector('[data-s]');
		m.el.querySelector('[data-c]').onclick = m.close;
		var go = function () {
			err.hidden = true;
			save.disabled = true;
			Promise.resolve(o.onSave(A.readForm(m.el, o.fields), m)).then(function () { m.close(); }).catch(function (e) {
				save.disabled = false;
				err.textContent = e.message || String(e);
				err.hidden = false;
			});
		};
		save.onclick = go;
		m.el.querySelector('form').addEventListener('submit', function (e) { e.preventDefault(); go(); });
		var d = m.el.querySelector('[data-del]');
		if (d) d.onclick = function () {
			A.confirm('Delete this?', 'It will be removed from your lists.', 'Delete', true).then(function (y) {
				if (y) Promise.resolve(o.del()).then(m.close).catch(A.fail);
			});
		};
		if (o.ready) o.ready(m);
		return m;
	};

	/** The one wording customers see when NeonDigital Connect cannot be reached. */
	A.CONNECT_MSG = 'NeonDigital Connect is temporarily unavailable. Please contact NeonDigital support.';

	/**
	 * What a locked feature means for this account, and the one thing to do about it.
	 * Agency clients never get a purchase option: NeonDigital controls their features.
	 */
	function accessInfo(code, label) {
		var f = A.feature(code), agency = S.boot.workspace.account_type === 'agency', manage = A.can('billing.manage');
		var name = label || f.label || 'This feature', o = { title: name, why: '', acts: [], note: '', soon: false, upsell: false };
		if (f.coming_soon) {
			o.soon = true;
			o.why = name + ' is still being built. There is nothing to buy yet, and nothing here runs in the background.';
		} else if (f.state === 'scheduled') {
			o.why = name + ' starts on ' + A.fmtD(f.starts_on) + '.';
		} else if (agency) {
			o.why = f.state === 'locked' ? 'Access to ' + name + ' has ended. Your records are safe and come back as soon as NeonDigital renews it.' : name + ' is not part of your current NeonDigital package. NeonDigital manages your package, so there is nothing to buy here.';
			o.acts.push('<a class="btn btn-primary" href="' + esc(C.contact) + '" target="_blank" rel="noopener">Contact NeonDigital to Unlock</a>');
		} else if (f.cta && f.cta !== 'Upgrade') {
			o.why = name + ' is for NeonDigital agency clients. Use the invitation link from NeonDigital to connect this workspace.';
		} else if (f.state === 'locked') {
			o.why = 'Access to ' + name + ' has ended. Your records are safe and come back as soon as it is renewed.';
			if (manage) o.acts.push('<a class="btn btn-primary" href="#/billing?focus=plans">Renew plan</a>');
			else o.note = 'Ask the workspace owner to renew.';
		} else {
			o.upsell = true;
			o.why = name + ' is not included in your current plan. Your existing records are not affected.';
			if (manage) {
				o.acts.push('<a class="btn btn-primary" href="#/billing?focus=plans">Upgrade Plan</a>');
				o.acts.push('<a class="btn" data-addons href="#/billing?focus=addons">View Add-ons</a>');
			} else o.note = 'Ask the workspace owner to upgrade.';
		}
		return o;
	}
	/** Names the plans that include the feature, and drops View Add-ons when no add-on offers it. */
	function fillPlans(root, code, info) {
		if (!info.upsell || !(A.can('billing.read') || A.can('billing.manage'))) return;
		A.api('billing').then(function (b) {
			var inc = (b.plans || []).filter(function (p) { return (p.features || []).indexOf(code) > -1 && !+p.is_trial; }).map(function (p) { return p.name; });
			var box = root.querySelector('[data-included]');
			if (box && inc.length) box.textContent = 'Included in: ' + inc.join(', ') + '.';
			var add = (b.addons || []).filter(function (x) { return x.feature_code === code; });
			var btn = root.querySelector('[data-addons]');
			if (btn && !add.length) btn.remove();
		}).catch(function () {});
	}
	A.featureAccess = function (code, label) {
		var i = accessInfo(code, label);
		var m = A.modal({
			title: i.title,
			body: '<div class="access"><span class="access-i">' + (i.soon ? I.bolt : I.lock) + '</span><div><span class="badge">' + (i.soon ? 'Coming soon' : 'Locked') + '</span><p>' + esc(i.why) + '</p><p class="muted small" data-included></p>' + (i.note ? '<p class="muted small">' + esc(i.note) + '</p>' : '') + '</div></div>',
			foot: i.acts.length ? i.acts.join('') : '<button class="btn" data-ok>Close</button>'
		});
		var ok = m.el.querySelector('[data-ok]');
		if (ok) ok.onclick = m.close;
		m.el.querySelector('.modal-f').addEventListener('click', function (e) { if (e.target.closest('a')) m.close(); });
		fillPlans(m.el, code, i);
		return m;
	};
	/** Replaces a view with the right refusal when the server says a feature is locked. */
	A.locked = function (el, featureCode) {
		var i = accessInfo(featureCode);
		el.innerHTML = '<section class="card locked"><span class="access-i">' + (i.soon ? I.bolt : I.lock) + '</span><h2>' + esc(i.title) + '</h2><p class="muted">' + esc(i.why) + '</p><p class="muted small" data-included></p>' + (i.note ? '<p class="muted small">' + esc(i.note) + '</p>' : '') + (i.acts.length ? '<div class="locked-acts">' + i.acts.join('') + '</div>' : '') + '</section>';
		fillPlans(el, featureCode, i);
	};
	A.gate = function (el, code) {
		if (A.has(code)) return true;
		A.locked(el, code);
		return false;
	};
	/** Handles errors from a view's loading promise in place. */
	A.viewError = function (el, e) {
		if (e && e.code === 'nwc_feature_locked' && e.data && e.data.feature) { A.locked(el, e.data.feature); return; }
		if (e && e.status === 401) { location.href = C.logoutTo; return; }
		el.innerHTML = '<section class="card card-pad"><p class="form-error" role="alert">' + esc(e.message || e) + '</p><button class="btn" data-r>Try again</button></section>';
		el.querySelector('[data-r]').onclick = render;
	};

	/* ---- Activity: shared by the feed, project history and the team page ---- */
	A.activityLabel = function (a) {
		var s = String(a.label || a.action || '').replace(/_/g, ' ').toLowerCase();
		s = s.replace(/^([a-z]+?)s (created|updated|deleted)$/, '$1 $2');
		return s.charAt(0).toUpperCase() + s.slice(1);
	};
	/** Folds runs of the same change to the same record, so repeats read as one line. */
	A.collapseActivity = function (items) {
		var out = [];
		(items || []).forEach(function (a) {
			var k = [a.actor || a.who || a.user_id, a.action, a.entity, a.entity_id, JSON.stringify(a.details || null)].join('|'), l = out[out.length - 1];
			if (l && l._k === k) { l.repeat++; return; }
			out.push(Object.assign({}, a, { _k: k, repeat: 1 }));
		});
		return out;
	};
	A.activityRow = function (a, sub) {
		return '<div class="row act-row"><span class="act-dot" aria-hidden="true"></span><div class="grow"><b>' + esc(A.activityLabel(a)) + '</b>' + (a.repeat > 1 ? ' <span class="muted small">' + a.repeat + ' times</span>' : '') + (sub ? '<div class="muted small">' + sub + '</div>' : '') + '</div><span class="muted small nowrap">' + esc(A.fmtDT(a.created_at)) + '</span></div>';
	};

	/* ---- Theme ---- */
	A.setTheme = function (p) {
		try { localStorage.setItem('nw-theme', p); } catch (e) {}
		document.documentElement.setAttribute('data-theme-pref', p);
		applyTheme(p);
		if (S.boot) A.api('me/preferences', { method: 'PUT', body: { theme: p } }).catch(function () {});
	};
	function applyTheme(p) {
		var dark = p === 'dark' || (p !== 'light' && window.matchMedia && matchMedia('(prefers-color-scheme: dark)').matches);
		document.documentElement.setAttribute('data-theme', dark ? 'dark' : 'light');
		var meta = document.querySelector('meta[name=theme-color]');
		if (meta) meta.setAttribute('content', dark ? '#0B0B0D' : '#F4F4F5');
		paintThemeBtn();
	}
	function paintThemeBtn() {
		var b = document.querySelector('[data-theme-btn]');
		if (!b) return;
		var p = themePref(), next = THEME_NEXT[p] || 'light', names = { light: 'Light', dark: 'Dark', system: 'System' };
		b.innerHTML = p === 'dark' ? I.moon : p === 'light' ? I.sunI : I.auto;
		var say = 'Theme: ' + names[p] + '. Switch to ' + names[next];
		b.setAttribute('aria-label', say);
		b.setAttribute('title', say);
	}
	var THEME_NEXT = { light: 'dark', dark: 'system', system: 'light' };
	if (window.matchMedia) {
		matchMedia('(prefers-color-scheme: dark)').addEventListener('change', function () {
			var p = document.documentElement.getAttribute('data-theme-pref');
			try { p = localStorage.getItem('nw-theme') || p; } catch (e) {}
			if (p === 'system') applyTheme('system');
		});
	}

	/* ---- Navigation ---- */
	A.isPersonal = function () { return !!(S.boot && S.boot.workspace.account_type === 'personal'); };
	function navItems() {
		var agency = !!S.boot.agency;
		if (A.isPersonal()) {
			return [
				{ group: 'Personal' },
				{ p: '/', l: 'My Dashboard', i: 'home' },
				{ p: '/overview', l: 'All my work', i: 'chart' },
				{ p: '/tasks', l: 'My Tasks', i: 'task' },
				{ p: '/calendar', l: 'My Calendar', i: 'cal' },
				{ p: '/planner', l: 'Daily Planner', i: 'sun' },
				{ p: '/notes', l: 'Notes & Goals', i: 'note' },
				{ group: 'Account' },
				{ p: '/notifications', l: 'Notifications', i: 'bell' },
				{ p: '/settings', l: 'Settings', i: 'gear' }
			];
		}
		/* Business: the sidebar follows the Workspace's business type. Irrelevant modules are hidden. */
		var ind = S.boot.industry || { modules: [], common: [] }, cm = {};
		(ind.common || []).forEach(function (c) { cm[c.key] = c; });
		var on = function (k) { return !cm[k] || cm[k].enabled; }, lab = function (k, d) { return cm[k] ? cm[k].label : d; };
		var mods = (ind.modules || []).filter(function (m) { return m.enabled; }).map(function (m) { return { p: '/m/' + m.key, l: m.label, i: m.icon, perm: m.read }; });
		return [
			{ group: 'Daily' },
			{ p: '/', l: 'Dashboard', i: 'home' },
			{ p: '/calendar', l: 'Calendar', i: 'cal' },
			on('projects') ? { p: '/tasks', l: 'Tasks', i: 'task', f: 'projects', perm: 'projects.read' } : null,
			on('projects') ? { p: '/projects', l: 'Projects', i: 'folder', f: 'projects', perm: 'projects.read' } : null,
			on('planner') ? { p: '/planner', l: 'Daily Planner', i: 'sun', f: 'daily_planner' } : null,
			mods.length ? { group: ind.label || 'Business' } : null
		].concat(mods).concat([
			{ group: 'Business' },
			on('bookings') ? { p: '/bookings', l: lab('bookings', 'Bookings'), i: 'book', f: 'bookings', perm: 'bookings.read' } : null,
			on('crm') ? { p: '/crm', l: lab('crm', 'Customers & Leads'), i: 'users', f: 'crm', perm: 'crm.read' } : null,
			on('invoices') ? { p: '/invoices', l: lab('invoices', 'Invoices & Quotes'), i: 'doc', f: 'invoice_management', perm: 'invoices.read' } : null,
			on('finance') ? { p: '/finance', l: lab('finance', 'Accounts & Expenses'), i: 'coin', f: 'accounts_finance', perm: 'finance.read' } : null,
			on('team') ? { p: '/team', l: lab('team', 'Team'), i: 'team', f: 'team_management', perm: 'team.read' } : null,
			on('files') ? { p: '/files', l: 'Files & Google Drive', i: 'drive', f: 'google_drive', perm: 'projects.read' } : null,
			agency ? { group: 'NeonDigital' } : null,
			agency ? { p: '/agency', l: 'NeonDigital Connect', i: 'link', perm: 'agency.use' } : null,
			{ group: 'More' },
			on('forms') ? { p: '/forms', l: 'Custom Forms', i: 'form', f: 'custom_forms', perm: 'crm.read' } : null,
			on('registrations') ? { p: '/registrations', l: 'Registration Forms', i: 'ticket', f: 'registration_forms', perm: 'crm.read' } : null,
			on('meetings') ? { p: '/meetings', l: 'Meetings', i: 'meet', f: 'meetings' } : null,
			{ p: '/notes', l: 'Notes & Goals', i: 'note' },
			on('reports') ? { p: '/reports', l: 'Reports', i: 'chart', f: 'reports', perm: 'reports.read' } : null,
			{ p: '/integrations', l: 'Integrations', i: 'plug' },
			{ group: 'Account' },
			{ p: '/billing', l: agency ? 'Plan & Access' : 'Subscription & Billing', i: 'card', perm: 'billing.read' },
			{ p: '/settings', l: 'Settings', i: 'gear' }
		]).filter(Boolean);
	}
	function navVisible(n) { return !(n.perm && !A.can(n.perm) && !(n.perm === 'billing.read' && A.can('billing.manage'))); }
	function renderNav() {
		return navItems().map(function (n) {
			if (n.group) return '<div class="side-group">' + esc(n.group) + '</div>';
			if (!navVisible(n)) return '';
			var ft = n.f ? A.feature(n.f) : null, locked = n.f && !A.has(n.f), soon = locked && ft && ft.coming_soon;
			return '<a href="#' + n.p + '" data-nav="' + n.p + '"' + (locked && !soon ? ' data-lock="' + esc(n.f) + '" data-lock-label="' + esc(n.l) + '" aria-label="' + esc(n.l) + ', locked"' : '') + '>' + (I[n.i] || '') + '<span class="side-label">' + esc(n.l) + '</span>' + (locked ? (soon ? '<span class="lock lock-soon">Soon</span>' : '<span class="lock" title="Locked">' + I.lock + '</span>') : '<span class="nav-n" data-navn="' + esc(n.p) + '" role="button" tabindex="0" hidden></span>') + '</a>';
		}).join('');
	}
	/* The bottom bar carries the four most used modules this account can actually open. */
	function bottomNav() {
		var pool;
		if (A.isPersonal()) pool = [{ p: '/', l: 'Home', i: 'home' }, { p: '/overview', l: 'All work', i: 'chart' }, { p: '/calendar', l: 'Calendar', i: 'cal' }, { p: '/notifications', l: 'Alerts', i: 'bell' }];
		else {
			var items = navItems().filter(function (n) { return !n.group; }), mods = items.filter(function (n) { return /^\/m\//.test(n.p); });
			pool = [items[0]].concat(mods.slice(0, 2), items.filter(function (n) { return ['/calendar', '/bookings', '/crm', '/tasks', '/invoices'].indexOf(n.p) > -1; }))
				.filter(function (n) { return navVisible(n) && (!n.f || A.has(n.f)); }).slice(0, 4);
		}
		return pool.map(function (n) { return '<a href="#' + n.p + '" data-nav="' + n.p + '">' + I[n.i] + '<span>' + esc(n.l) + '</span></a>'; }).join('') + '<button data-menu aria-label="Open menu">' + I.more + '<span>More</span></button>';
	}

	function themePref() {
		var p = document.documentElement.getAttribute('data-theme-pref') || 'system';
		try { p = localStorage.getItem('nw-theme') || p; } catch (e) {}
		return p;
	}
	function menu(anchor, html) {
		closeMenus();
		var d = document.createElement('div');
		d.className = 'pop';
		d.setAttribute('role', 'menu');
		d.innerHTML = html;
		document.body.appendChild(d);
		var r = anchor.getBoundingClientRect();
		var w = Math.min(anchor.hasAttribute('data-bell') ? 380 : 320, window.innerWidth - 16);
		d.style.width = w + 'px';
		d.style.left = Math.max(8, Math.min(anchor.hasAttribute('data-ws') ? r.left : r.right - w, window.innerWidth - w - 8)) + 'px';
		d.style.top = (r.bottom + 6) + 'px';
		setTimeout(function () { document.addEventListener('click', closeMenus, { once: true }); }, 0);
		return d;
	}
	A.menu = menu;
	A.closeMenus = closeMenus;
	function closeMenus() { document.querySelectorAll('.pop').forEach(function (x) { x.remove(); }); }

	function brandLockup(cls) {
		return '<a class="brand ' + cls + '" href="#/" aria-label="Neon Workspace, go to dashboard"><img class="brand-mark" src="' + esc(C.mark) + '" alt="" width="26" height="26"><img class="brand-word" src="' + esc(C.wordmark) + '" alt="NeonDigital" height="15"><span class="brand-product">Workspace</span></a>';
	}
	/* Phase 5: the account picture and each Workspace's own logo, with initials as the fallback. */
	A.initials = function (name) { return String(name || '?').trim().split(/\s+/).map(function (x) { return x.charAt(0); }).slice(0, 2).join('').toUpperCase() || '?'; };
	A.avatarHtml = function (url, name, cls) { return url ? '<img class="' + (cls || 'av-img') + '" src="' + esc(A.safeUrl(url)) + '" alt="">' : esc(A.initials(name)); };
	A.wsAvatar = function (w, personal) { return '<span class="ws-av' + (personal ? ' ws-av-p' : '') + (w && w.logo_url && !personal ? ' ws-av-img' : '') + '">' + (w && w.logo_url && !personal ? '<img src="' + esc(A.safeUrl(w.logo_url)) + '" alt="">' : esc(String((personal ? 'Personal' : (w && w.name) || '?')).charAt(0).toUpperCase())) + '</span>'; };
	function shell() {
		var B = S.boot, ws = B.workspaces;
		var initials = A.avatarHtml(B.user.avatar_url, B.user.name);
		var searchForm = function (cls) { return '<form class="top-search ' + cls + '" data-search role="search">' + I.search + '<input type="search" name="q" placeholder="Search" aria-label="Search this workspace" autocomplete="off"></form>'; };
		var cur = B.workspace, curType = cur.account_type === 'personal' ? 'Personal · private' : cur.account_type === 'agency' ? 'Business · NeonDigital managed' : 'Business · your subscription';
		var wsBtn = function (cls) { return '<button class="ws-switch ' + cls + '" data-ws aria-haspopup="menu" aria-label="Switch between Personal and Business Workspaces. Current: ' + esc(cur.name) + '">' + A.wsAvatar(cur, cur.account_type === 'personal') + '<span class="ws-txt"><b>' + esc(cur.name) + '</b><small>' + esc(curType) + '</small></span><svg class="ws-caret" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M6 9l6 6 6-6"/></svg></button>'; };
		document.getElementById('app').innerHTML =
			'<div class="shell"><div class="scrim" data-scrim></div><aside class="sidebar" aria-label="Main menu"><div class="side-top">' + brandLockup('side-brand') + '<button class="icon-btn side-close" data-close aria-label="Close menu">' + I.x + '</button></div><div class="side-tools">' + wsBtn('side-only') + searchForm('') + '</div><nav class="side-nav">' + renderNav() + '</nav></aside>' +
			'<div class="main"><header class="topbar"><button class="icon-btn menu-btn" data-menu aria-label="Open menu" aria-expanded="false">' + I.more + '</button>' + brandLockup('top-brand') + wsBtn('tb-switch') +
			'<div class="tb-head"><h1 data-title></h1><p class="tb-sub" data-sub hidden></p></div>' +
			'<div class="tb-actions">' + searchForm('tb-only') +
			'<button class="btn btn-primary btn-sm top-add" data-fab aria-label="Quick new">' + I.plus + '<span>New</span></button>' +
			'<button class="icon-btn" data-theme-btn></button>' +
			'<button class="icon-btn" data-bell aria-haspopup="true" aria-label="Notifications from Personal and all your Workspaces">' + I.bell + '<span class="dot" data-unread' + (B.unread ? '' : ' hidden') + '>' + (+B.unread) + '</span></button>' +
			'<button class="avatar' + (B.user.avatar_url ? ' has-img' : '') + '" data-profile aria-haspopup="true" aria-label="Your account">' + initials + '</button></div></header>' +
			subBanner() + '<main class="content" data-view></main></div>' +
			'<nav class="bottom-nav" aria-label="Main">' + bottomNav() + '</nav></div>';
		var sh = document.querySelector('.shell');
		var setNav = function (open) {
			sh.classList.toggle('nav-open', open);
			document.documentElement.classList.toggle('nav-lock', open);
			document.querySelectorAll('.menu-btn').forEach(function (b) { b.setAttribute('aria-expanded', String(open)); });
		};
		document.querySelectorAll('[data-menu]').forEach(function (b) { b.onclick = function () { setNav(!sh.classList.contains('nav-open')); }; });
		document.querySelector('[data-scrim]').onclick = function () { setNav(false); };
		document.querySelector('[data-close]').onclick = function () { setNav(false); };
		document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && sh.classList.contains('nav-open')) setNav(false); });
		document.querySelector('.side-nav').addEventListener('click', function (e) {
			var cnt = e.target.closest('[data-navn]');
			if (cnt) { e.preventDefault(); e.stopPropagation(); A.bellMenu(cnt, cnt.getAttribute('data-navn')); return; }
			var a = e.target.closest('a');
			if (!a) return;
			setNav(false);
			if (a.hasAttribute('data-lock')) { e.preventDefault(); A.featureAccess(a.getAttribute('data-lock'), a.getAttribute('data-lock-label')); }
		});
		document.querySelector('[data-fab]').onclick = function () { A.quickAdd(); };
		document.querySelectorAll('[data-ws]').forEach(function (btn) {
			btn.onclick = function (e) {
				e.stopPropagation();
				var by = S.unreadBy || {};
				var item = function (w, label, sub, personal) {
					var n = +by[w.id] || 0;
					return '<button role="menuitemradio" aria-checked="' + (+w.id === +B.workspace.id) + '" data-sw="' + w.id + '"' + (+w.id === +B.workspace.id ? ' class="on"' : '') + '>' + A.wsAvatar(w, personal) + '<span class="ws-txt"><b>' + esc(label) + '</b><small class="muted">' + esc(sub) + '</small></span>' + (n ? '<span class="ws-n" aria-label="' + n + ' unread">' + n + '</span>' : '') + '</button>';
				};
				var html = (B.personal ? '<div class="pop-sec">Personal</div>' + item(B.personal, 'Personal', 'Private: your dashboard and calendar', true) : '') +
					'<div class="pop-sec">Business Workspaces</div>' + (ws.length ? ws.map(function (w) { return item(w, w.name, (w.account_type === 'agency' ? 'Managed by NeonDigital · ' : 'Your subscription · ') + (B.roles[w.role] || w.role), false); }).join('') : '<div class="pop-lock">' + I.lock + '<span><b>Business is locked</b><small class="muted">This account has no Business Workspace yet. Add one to open a business dashboard.</small></span></div>') +
					'<button role="menuitem" class="pop-add" data-addws>' + I.plus + ' Add Workspace</button>';
				var d = menu(e.currentTarget, html);
				d.classList.add('ws-pop');
				d.addEventListener('click', function (x) { if (x.target.closest('[data-addws]')) { closeMenus(); A.addWorkspace(); return; } var b = x.target.closest('[data-sw]'); if (b && +b.getAttribute('data-sw') !== +B.workspace.id) switchTo(+b.getAttribute('data-sw')); });
			};
		});
		document.querySelector('[data-bell]').onclick = function (e) { e.stopPropagation(); A.bellMenu(e.currentTarget); };
		document.querySelector('[data-theme-btn]').onclick = function () {
			A.setTheme(THEME_NEXT[themePref()] || 'light');
		};
		paintThemeBtn();
		document.querySelector('[data-profile]').onclick = function (e) {
			e.stopPropagation();
			var d = menu(e.currentTarget, '<div class="pop-h pop-me"><span class="avatar' + (B.user.avatar_url ? ' has-img' : '') + '">' + A.avatarHtml(B.user.avatar_url, B.user.name) + '</span><span><b>' + esc(B.user.name) + '</b><small class="muted">' + esc(B.user.email) + '</small></span></div><a role="menuitem" href="#/settings#account">Account and picture</a><a role="menuitem" href="#/settings">Settings</a><a role="menuitem" href="#/activity">Activity</a>' + '<a role="menuitem" href="' + esc(C.home) + 'terms-and-conditions/" target="_blank" rel="noopener">Terms &amp; Conditions</a><a role="menuitem" href="' + esc(C.home) + 'privacy-policy/" target="_blank" rel="noopener">Privacy Policy</a><button role="menuitem" data-out>Sign out</button>');
			d.querySelector('[data-out]').onclick = A.logout;
		};
		document.querySelectorAll('[data-search]').forEach(function (sf) {
			sf.addEventListener('submit', function (e) {
				e.preventDefault();
				var q = sf.q.value.trim();
				setNav(false);
				if (q.length >= 2) A.go('/search?q=' + encodeURIComponent(q));
			});
		});
	}

	/* ---- Header greeting and clock, in the workspace's timezone, updated once a minute ---- */
	function inTz(opt) {
		try { return new Intl.DateTimeFormat('en-GB', Object.assign({ timeZone: A.tz() }, opt)); } catch (e) { return new Intl.DateTimeFormat('en-GB', opt); }
	}
	/** Text for one moment. Separate from the timer so it can be checked for any time of day. */
	A.greetingText = function (now) {
		now = now || new Date();
		var h = parseInt(inTz({ hour: 'numeric', hourCycle: 'h23' }).format(now), 10);
		var hi = h < 12 ? 'Good morning' : h < 17 ? 'Good afternoon' : 'Good evening';
		var first = String(S.boot.user.name || '').trim().split(/\s+/)[0];
		var weekday = inTz({ weekday: 'long' }).format(now), date = inTz({ day: 'numeric', month: 'long', year: 'numeric' }).format(now);
		var time = inTz({ hour: 'numeric', minute: '2-digit', hour12: true }).format(now).replace(/\s+/g, ' ').toUpperCase();
		return (first ? hi + ', ' + first : hi) + ' · ' + weekday + ', ' + date + ' · ' + time;
	};
	var clockTimer = null;
	function clockTick() {
		clearTimeout(clockTimer);
		var el = document.querySelector('[data-sub]');
		if (!el || el.hidden) return;
		var t = A.greetingText();
		if (el.textContent !== t) el.textContent = t;
		clockTimer = setTimeout(clockTick, 60000 - (Date.now() % 60000) + 50);
	}
	function headerClock(on) {
		var el = document.querySelector('[data-sub]');
		if (!el) return;
		el.hidden = !on;
		document.querySelector('.topbar').classList.toggle('has-sub', on);
		if (on) clockTick(); else clearTimeout(clockTimer);
	}
	document.addEventListener('visibilitychange', function () { if (!document.hidden) clockTick(); else clearTimeout(clockTimer); });

	function subBanner() {
		var st = S.boot.subscription_state;
		if (!st) return '';
		if (st.status === 'pending_payment') return '<div class="banner"><span>Your plan starts as soon as your first payment is verified.</span><a class="btn btn-sm btn-primary" href="#/billing">Complete payment</a></div>';
		if (st.status === 'none') return '<div class="banner"><span>This workspace has no plan yet.</span><a class="btn btn-sm btn-primary" href="#/billing">Choose a plan</a></div>';
		if (st.state === 'locked') return '<div class="banner banner-bad"><span>Your plan has ended. Your data is safe. Renew to unlock paid features.</span><a class="btn btn-sm btn-primary" href="#/billing">Renew</a></div>';
		if (st.state === 'grace') return '<div class="banner"><span>Your plan has ended and is in its grace period.</span><a class="btn btn-sm" href="#/billing">Renew</a></div>';
		return '';
	}
	/**
	 * Switching context reloads the app bound to that Workspace (or Personal). The choice lasts for
	 * this browser session only, so every new sign-in starts in Personal. `hash` opens a record there.
	 */
	function switchTo(id, hash) {
		try { sessionStorage.setItem('nw-ctx', id); } catch (e) {}
		S.boot = null; // The page reloads in the new context; the old one must not render the new link first.
		location.hash = hash ? String(hash).replace(/^#?/, '#') : '#/';
		location.reload();
	}
	/** Opens a link in the context it belongs to, switching first when needed. */
	A.openIn = function (workspaceId, link) {
		var h = link ? String(link).replace(/^#?/, '#') : '#/';
		if (+workspaceId && +workspaceId !== +S.boot.workspace.id) { switchTo(+workspaceId, h); return; }
		location.hash = h;
	};
	/* ---- Notifications from every context, whichever one is open ---- */
	S.unreadBy = {};
	A.paintModuleCounts = function (by) {
		S.unreadModules = by || {};
		document.querySelectorAll('[data-navn]').forEach(function (b) {
			var n = +S.unreadModules[b.getAttribute('data-navn')] || 0;
			b.hidden = !n;
			b.textContent = n > 99 ? '99+' : String(n);
			b.setAttribute('aria-label', n + ' unread notification' + (n === 1 ? '' : 's') + '. Open them');
		});
	};
	A.pollUnread = function () {
		return A.api('me/notifications/count', { params: { workspace_id: S.boot ? S.boot.workspace.id : '' } }).then(function (r) {
			S.unreadBy = r.by_workspace || {};
			A.paintModuleCounts(r.by_module);
			A.setUnread(r.unread);
			if (S.lastNoteId && r.latest_id > S.lastNoteId && document.hidden === false) A.toast('New notification');
			S.lastNoteId = r.latest_id;
			return r;
		}).catch(function () {});
	};
	A.openNotification = function (n) {
		var go = function () { A.openIn(n.workspace_id, /^#\//.test(n.link || '') ? n.link : '#/notifications'); A.pollUnread(); };
		if (n.read_at) { go(); return; }
		A.api('me/notifications/read', { method: 'POST', body: { id: n.id } }).then(go, go);
	};
	A.noteRow = function (n, showWs) {
		return '<button class="note-row' + (n.read_at ? '' : ' unread') + '" data-note="' + n.id + '"><span class="note-dot" aria-hidden="true"></span><span class="note-main"><b>' + esc(n.title) + '</b>' + (n.body ? '<span class="note-body">' + esc(n.body) + '</span>' : '') +
			'<span class="note-meta">' + (showWs ? '<span class="note-ws">' + esc(n.workspace_name) + '</span> · ' : '') + esc(A.fmtDT(n.created_at)) + '</span></span></button>';
	};
	/** The bell (everything) or one sidebar module's unread notifications (module = its nav path). */
	A.bellMenu = function (anchor, module) {
		var label = '';
		if (module) { var nav = document.querySelector('.side-nav a[data-nav="' + module + '"] .side-label'); label = nav ? nav.textContent : ''; }
		var d = menu(anchor, '<div class="pop-h"><b>' + (module ? 'New in ' + esc(label) : 'Notifications') + '</b><button class="link small" data-allread>' + (module ? 'Mark these read' : 'Mark all read') + '</button></div><div class="note-list" data-nl>' + A.loading(3) + '</div>' + (module ? '<a role="menuitem" class="pop-foot" href="#' + esc(module) + '">Open ' + esc(label) + '</a>' : '<a role="menuitem" class="pop-foot" href="#/notifications">See all notifications</a>'));
		d.classList.add('note-pop');
		d.addEventListener('click', function (e) { e.stopPropagation(); if (e.target.closest('a')) closeMenus(); });
		var params = module ? { unread: 1, workspace_id: S.boot.workspace.id, module: module } : { unread: '' };
		A.api('me/notifications', { params: params }).then(function (r) {
			var items = r.items.slice(0, module ? 20 : 8), list = d.querySelector('[data-nl]');
			list.innerHTML = items.length ? items.map(function (n) { return A.noteRow(n, !module); }).join('') : '<div class="empty small"><b>You are all caught up</b>' + (module ? 'Nothing new here.' : 'New notifications from Personal and every Workspace appear here.') + '</div>';
			list.querySelectorAll('[data-note]').forEach(function (b) { b.onclick = function () { closeMenus(); A.openNotification(items.filter(function (n) { return +n.id === +b.getAttribute('data-note'); })[0]); }; });
		}).catch(function (e) { d.querySelector('[data-nl]').innerHTML = '<p class="form-error">' + esc(e.message) + '</p>'; });
		d.querySelector('[data-allread]').onclick = function () {
			var body = module ? { workspace_id: S.boot.workspace.id, module: module } : {};
			A.api('me/notifications/read', { method: 'POST', body: body }).then(function (r) { A.setUnread(r.unread); if (!module) S.unreadBy = {}; closeMenus(); A.pollUnread(); }).catch(A.fail);
		};
	};

	/**
	 * Phase 5: Add Workspace from the switcher. Plans and prices come from the existing catalogue;
	 * the purchase runs through the existing sign-up path (trial starts at once, a paid plan creates
	 * its invoice and the Workspace opens on Billing to pay). The new Workspace is independent:
	 * owned and paid for by this account, never part of an agency project.
	 */
	A.addWorkspace = function () {
		var m = A.modal({ title: 'Add a Workspace', size: 'lg', body: A.loading(4), foot: '<button class="btn" data-x2>Cancel</button><button class="btn btn-primary" data-go disabled>Continue</button>' });
		m.el.querySelector('[data-x2]').onclick = m.close;
		var cats = ((S.boot && S.boot.categories) || []).filter(function (c) { return !c.legacy; });
		A.api('plans').then(function (r) {
			var plans = (r.plans || []).filter(function (p) { return p.is_trial || p.monthly != null || p.annual != null; });
			var st = { plan: '', cycle: 'month' };
			var zone = (S.boot && S.boot.workspace.timezone) || 'Asia/Kolkata';
			var price = function (p) { if (p.is_trial) return '<b>Free</b><small>' + (+p.trial_days) + '-day trial</small>'; var v = st.cycle === 'year' ? p.annual : p.monthly; return v == null ? '<b>On request</b><small>contact NeonDigital</small>' : '<b>' + A.inr(v) + '</b><small>per ' + (st.cycle === 'year' ? 'year' : 'month') + '</small>'; };
			var paint = function () {
				m.el.querySelector('.modal-b').innerHTML = '<p class="muted">A new Business Workspace has its own dashboard, business data, settings, team, subscription and billing. It belongs to your account and is separate from any NeonDigital project.</p>' +
					'<div class="seg" role="group" aria-label="Billing period"><button type="button" data-cy="month" class="' + (st.cycle === 'month' ? 'on' : '') + '">Monthly</button><button type="button" data-cy="year" class="' + (st.cycle === 'year' ? 'on' : '') + '">Yearly</button></div>' +
					'<div class="plan-grid">' + (plans.length ? plans.map(function (p) { return '<button type="button" class="plan-card' + (st.plan === p.code ? ' on' : '') + '" data-pl="' + esc(p.code) + '" aria-pressed="' + (st.plan === p.code) + '"><span class="plan-name">' + esc(p.name) + '</span><span class="plan-price">' + price(p) + '</span>' + (p.description ? '<span class="muted small">' + esc(p.description) + '</span>' : '') + '<span class="plan-feat">' + p.features.slice(0, 6).map(function (f) { return '<span>' + esc(f.label) + '</span>'; }).join('') + (p.features.length > 6 ? '<span>+' + (p.features.length - 6) + ' more</span>' : '') + '</span></button>'; }).join('') : '<p class="muted">No plans are on sale right now. Contact NeonDigital.</p>') + '</div>' +
					'<div class="form-grid"><label class="field"><span>Business name</span><input data-nm maxlength="190" required></label><label class="field"><span>Business type <small class="muted">sets the dashboard, modules and forms</small></span><select data-ct><option value="">Choose one</option>' + cats.map(function (c) { return '<option value="' + esc(c.key) + '">' + esc(c.label) + '</option>'; }).join('') + '</select></label></div>' +
					'<label class="check" data-tw hidden><input type="checkbox" data-terms> <span>I accept the <a href="' + esc(C.home) + 'privacy-policy/" target="_blank" rel="noopener">Privacy Policy</a> and <a href="' + esc(C.home) + 'terms-and-conditions/" target="_blank" rel="noopener">Terms &amp; Conditions</a>.</span></label><div class="form-error" hidden role="alert"></div>';
				var go = m.el.querySelector('[data-go]');
				go.disabled = !st.plan;
				var chosen = plans.filter(function (p) { return p.code === st.plan; })[0];
				go.textContent = !chosen ? 'Continue' : chosen.is_trial ? 'Start free trial' : 'Continue to payment';
			};
			paint();
			var keep = {};
			m.el.addEventListener('input', function (e) { if (e.target.matches('[data-nm]')) keep.nm = e.target.value; });
			m.el.addEventListener('change', function (e) { if (e.target.matches('[data-ct]')) keep.ct = e.target.value; });
			m.el.addEventListener('click', function (e) {
				var cy = e.target.closest('[data-cy]'), pl = e.target.closest('[data-pl]');
				if (cy) st.cycle = cy.getAttribute('data-cy');
				if (pl) st.plan = pl.getAttribute('data-pl');
				if (cy || pl) { paint(); var n = m.el.querySelector('[data-nm]'), c = m.el.querySelector('[data-ct]'); if (keep.nm) n.value = keep.nm; if (keep.ct) c.value = keep.ct; }
			});
			m.el.querySelector('[data-go]').onclick = function (e) {
				var err = m.el.querySelector('.form-error'), name = (m.el.querySelector('[data-nm]').value || '').trim(), cat = m.el.querySelector('[data-ct]').value, t = m.el.querySelector('[data-terms]');
				err.hidden = true;
				if (!st.plan) { err.textContent = 'Choose a plan.'; err.hidden = false; return; }
				if (!name) { err.textContent = 'Enter the business name.'; err.hidden = false; return; }
				if (!cat) { err.textContent = 'Choose the business type.'; err.hidden = false; return; }
				e.target.disabled = true;
				A.api('workspaces', { method: 'POST', body: { plan_code: st.plan, cycle: st.cycle, name: name, kind: 'business', category: cat, timezone: zone, accept_terms: t ? t.checked : false } }).then(function (res) {
					A.toast(res.status === 'pending_payment' ? 'Workspace created. Pay the first invoice to start it.' : 'Your new Workspace is ready.');
					switchTo(res.id, res.status === 'pending_payment' ? '#/billing' : '#/');
				}).catch(function (x) {
					e.target.disabled = false;
					if (x.code === 'nwc_terms_required') m.el.querySelector('[data-tw]').hidden = false;
					err.textContent = x.message; err.hidden = false;
				});
			};
		}).catch(function (x) { m.el.querySelector('.modal-b').innerHTML = '<p class="form-error">' + esc(x.message) + '</p>'; });
	};
	A.switchTo = switchTo;
	A.setTitle = function (t) { var el = document.querySelector('[data-title]'); if (el) el.textContent = t; document.title = t + ' · Neon Workspace'; };
	A.go = function (p) { location.hash = '#' + p; };
	A.reloadBoot = function () { return A.api('bootstrap').then(function (b) { S.boot = b; var n = document.querySelector('.side-nav'); if (n) n.innerHTML = renderNav(); }); };
	A.setUnread = function (n) { S.boot.unread = n; var d = document.querySelector('[data-unread]'); if (d) { d.textContent = n > 99 ? '99+' : n; d.hidden = !n; } };

	/* ---- Router ---- */
	var routes = [];
	A.route = function (re, view) { routes.push([re, view]); };
	function render() {
		var raw = (location.hash || '#/').slice(1) || '/', qi = raw.indexOf('?');
		var path = qi > -1 ? raw.slice(0, qi) : raw;
		A.query = {};
		if (qi > -1) raw.slice(qi + 1).split('&').forEach(function (kv) { var p = kv.split('='); if (p[0]) A.query[decodeURIComponent(p[0])] = decodeURIComponent(p[1] || ''); });
		var view = null, m = null;
		routes.some(function (r) { m = path.match(r[0]); if (m) { view = r[1]; return true; } return false; });
		if (!view) { path = '/'; view = 'dashboard'; m = ['/']; }
		if (A.isPersonal() && A.views['personal_' + view]) view = 'personal_' + view;
		document.querySelectorAll('[data-nav]').forEach(function (a) {
			var p = a.getAttribute('data-nav');
			a.classList.toggle('on', p === path || (p !== '/' && path.indexOf(p + '/') === 0));
		});
		headerClock(path === '/');
		var host = document.querySelector('[data-view]');
		var el = document.createElement('div');
		host.innerHTML = '';
		host.appendChild(el);
		window.scrollTo(0, 0);
		try {
			var r = A.views[view](el, m);
			if (r && r.catch) {
				if (!el.innerHTML) el.innerHTML = '<section class="card card-pad">' + A.loading(4) + '</section>';
				r.catch(function (e) { if (el.isConnected) A.viewError(el, e); });
			}
		} catch (e) {
			A.viewError(el, e);
		}
	}
	A.render = render;
	window.addEventListener('hashchange', function () { if (S.boot) render(); });

	/* ---- Onboarding: account, workspace type, business, notices, chosen plan ---- */
	function planLine(p, cycle) {
		if (p.is_trial) return p.trial_days + '-day free trial';
		var v = cycle === 'year' ? p.annual : p.monthly;
		return v === null || v === undefined ? 'Price on request' : A.inr(v) + (cycle === 'year' ? ' a year' : ' a month');
	}
	function onboarding(me) {
		var ob = me.onboarding, plan = me.selected_plan;
		var zones = ['Asia/Kolkata', 'Asia/Dubai', 'Asia/Singapore', 'Europe/London', 'America/New_York', 'UTC'], mine = 'Asia/Kolkata';
		try { mine = Intl.DateTimeFormat().resolvedOptions().timeZone || mine; } catch (e) {}
		if (zones.indexOf(mine) < 0) zones.unshift(mine);
		var box = function (h) { document.getElementById('app').innerHTML = '<div class="nw-auth"><main class="auth-card card"><img class="nw-logo" src="' + esc(C.logo) + '" alt="NeonDigital">' + h + '<p class="center"><button class="link" data-out>Sign out</button></p></main></div>'; document.querySelector('[data-out]').onclick = A.logout; };
		if (!plan) {
			box('<h1>Choose a plan to add a Workspace</h1><p class="muted">Welcome, ' + esc(me.name) + '. Pick a plan to set up your workspace. Nothing starts until you choose.</p><a class="btn btn-primary btn-block" href="' + esc(C.pricing) + '">View pricing</a><p class="muted small center">Have an invitation from NeonDigital or a team? Open its link instead.</p>');
			return;
		}
		var link = function (u, t) { return u ? '<a href="' + esc(u) + '" target="_blank" rel="noopener">' + t + '</a>' : t; };
		box('<h1>Set up your workspace</h1><div class="plan-chip"><div><b>' + esc(plan.name) + '</b><small class="muted">' + esc(planLine(plan, plan.cycle)) + '</small></div><a class="link" href="' + esc(C.pricing) + '">Change</a></div>' +
			'<form novalidate>' + (ob.needs_email ? '<label class="field"><span>Your email address</span><input name="email" type="email" autocomplete="email" required></label>' : '') +
			'<label class="field"><span>Who is it for?</span><select name="kind"><option value="business">A business</option><option value="personal">Just me (individual or freelancer)</option></select></label>' +
			'<label class="field"><span>Workspace or business name</span><input name="name" required></label>' +
			'<label class="field" data-cat><span>Business category <small class="muted">sets up your dashboard, modules and forms</small></span><select name="category"><option value="">Choose one</option>' + (S.categories || []).filter(function (x) { return !x.legacy; }).map(function (x) { return '<option value="' + esc(x.key) + '">' + esc(x.label) + '</option>'; }).join('') + '</select></label>' +
			'<label class="field"><span>Timezone</span><select name="timezone">' + zones.map(function (z) { return '<option' + (z === mine ? ' selected' : '') + '>' + esc(z) + '</option>'; }).join('') + '</select></label>' +
			(ob.needs_legal ? '<label class="check"><input type="checkbox" name="accept_terms" required> <span>I accept the ' + link(ob.privacy_url, 'Privacy Policy') + ' and ' + link(ob.terms_url, 'Terms &amp; Conditions') + '.</span></label>' +
				'<label class="check"><input type="checkbox" name="marketing_consent"> <span>Send me product news and offers. (Optional)</span></label>' : '') +
			'<div class="form-error" hidden role="alert"></div><button class="btn btn-primary btn-block" type="submit">' + (plan.is_trial ? 'Start my free trial' : 'Continue to payment') + '</button></form>');
		var f = document.querySelector('form'), err = f.querySelector('.form-error');
		f.kind.onchange = function () { document.querySelector('[data-cat]').hidden = f.kind.value === 'personal'; };
		f.addEventListener('submit', function (e) {
			e.preventDefault();
			err.hidden = true;
			var d = { name: f.name.value, kind: f.kind.value, category: f.category.value, timezone: f.timezone.value };
			if (d.kind === 'business' && !d.category) { err.textContent = 'Choose your business category.'; err.hidden = false; return; }
			if (ob.needs_legal) { d.accept_terms = f.accept_terms.checked; d.marketing_consent = f.marketing_consent.checked; }
			var first = ob.needs_email ? A.api('me/profile', { method: 'PUT', body: { email: f.email.value } }) : Promise.resolve();
			first.then(function () { return A.api('workspaces', { method: 'POST', body: d }); }).then(function (r) {
				try { sessionStorage.setItem('nw-ctx', r.id); } catch (x) {}
				if (r.status === 'pending_payment') location.hash = '#/billing';
				location.reload();
			}).catch(function (x) { err.textContent = x.message; err.hidden = false; });
		});
	}
	/* An updated Terms or Privacy Policy that asks for acceptance. Nothing is assumed on anyone's behalf. */
	function legalGate(list) {
		document.getElementById('app').innerHTML = '<div class="nw-auth"><main class="auth-card card"><img class="nw-logo" src="' + esc(C.logo) + '" alt="NeonDigital"><h1>Please review our updated documents</h1>' +
			list.map(function (d) { return '<p><a href="' + esc(d.url) + '" target="_blank" rel="noopener"><b>' + esc(d.title) + '</b></a> <span class="muted small">version ' + esc(d.version) + '</span>' + (d.notice ? '<br><span class="muted small">' + esc(d.notice) + '</span>' : '') + '</p>'; }).join('') +
			'<label class="check"><input type="checkbox" data-a> <span>I accept these documents.</span></label><div class="form-error" hidden role="alert"></div><button class="btn btn-primary btn-block" data-g>Continue</button><p class="center"><button class="link" data-out>Sign out</button></p></main></div>';
		var err = document.querySelector('.form-error');
		document.querySelector('[data-out]').onclick = A.logout;
		document.querySelector('[data-g]').onclick = function () {
			if (!document.querySelector('[data-a]').checked) { err.textContent = 'Please tick the box to continue.'; err.hidden = false; return; }
			A.api('me/legal/accept', { method: 'POST', body: { documents: list.map(function (d) { return d.key; }) } }).then(function () { location.reload(); }).catch(function (x) { err.textContent = x.message; err.hidden = false; });
		};
	}
	A.logout = function () { A.api('auth/logout', { method: 'POST', body: {} }).finally(function () { try { sessionStorage.removeItem('nw-ctx'); localStorage.removeItem('nw-workspace'); } catch (e) {} location.href = C.logoutTo; }); };

	/* ---- PWA: service worker, update notice, network state ---- */
	function pwa() {
		var net = document.getElementById('net');
		var upd = function () { net.hidden = navigator.onLine; };
		window.addEventListener('online', upd);
		window.addEventListener('offline', upd);
		upd();
		window.addEventListener('beforeinstallprompt', function (e) { e.preventDefault(); S.installPrompt = e; });
		if (!('serviceWorker' in navigator) || !/^https:|^http:\/\/localhost/.test(location.href)) return;
		navigator.serviceWorker.register(C.swUrl, { scope: new URL(C.home).pathname }).then(function (reg) {
			var notice = function (w) {
				if (document.querySelector('.update-bar')) return;
				var bar = document.createElement('div');
				bar.className = 'update-bar';
				bar.innerHTML = '<span>A new version is ready.</span><button class="btn btn-sm btn-primary">Update</button>';
				bar.querySelector('button').onclick = function () { w.postMessage('skipWaiting'); };
				document.body.appendChild(bar);
			};
			if (reg.waiting && navigator.serviceWorker.controller) notice(reg.waiting);
			reg.addEventListener('updatefound', function () {
				var w = reg.installing;
				if (w) w.addEventListener('statechange', function () { if (w.state === 'installed' && navigator.serviceWorker.controller) notice(w); });
			});
		}).catch(function () {});
		var reloaded = false;
		navigator.serviceWorker.addEventListener('controllerchange', function () {
			if (!reloaded && S.hadController) { reloaded = true; location.reload(); }
		});
		S.hadController = !!navigator.serviceWorker.controller;
	}

	/* ---- Start ---- */
	A.start = function () {
		pwa();
		A.api('me').then(function (me) {
			if (me.legal_pending && me.legal_pending.length) { legalGate(me.legal_pending); return; }
			if (me.selected_plan) { S.categories = me.categories; onboarding(me); return; }
			var want = 0;
			try { want = +sessionStorage.getItem('nw-ctx'); } catch (e) {}
			var all = (me.personal ? [me.personal] : []).concat(me.workspaces);
			var pick = all.filter(function (w) { return +w.id === want; })[0] || me.personal || me.workspaces[0];
			if (!pick) { onboarding(me); return; }
			S.workspaceId = pick.id;
			try { sessionStorage.setItem('nw-ctx', pick.id); } catch (e) {}
			return A.api('bootstrap').then(function (b) {
				S.boot = b;
				if (b.prefs && b.prefs.theme) { try { if (!localStorage.getItem('nw-theme')) applyTheme(b.prefs.theme); } catch (e) {} }
				shell();
				render();
				A.pollUnread();
				setInterval(function () { if (!document.hidden) A.pollUnread(); }, 30000);
				document.addEventListener('visibilitychange', function () { if (!document.hidden) A.pollUnread(); });
			});
		}).catch(function (e) {
			if (e.status === 401) { location.href = C.logoutTo; return; }
			document.getElementById('app').innerHTML = '<div class="content"><p class="form-error" role="alert">' + esc(e.message) + '</p><button class="btn" onclick="location.reload()">Try again</button></div>';
		});
	};
})();
