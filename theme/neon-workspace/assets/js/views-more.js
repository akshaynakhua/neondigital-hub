/* Daily Planner, Meetings, Forms, Registrations, Finance, Files, Reports, Activity, Search, Settings extras. */
(function () {
	'use strict';
	var A = window.App, V = A.views, S = A.S, esc = A.esc, api = A.api;

	A.pager = function (r, cb) {
		if (!r.pages || r.pages < 2) return document.createDocumentFragment();
		var d = document.createElement('div');
		d.className = 'pager';
		var p = r.page, nums = [];
		for (var i = Math.max(1, p - 2); i <= Math.min(r.pages, p + 2); i++) nums.push(i);
		d.innerHTML = '<button class="btn btn-sm" data-p="' + (p - 1) + '"' + (p <= 1 ? ' disabled' : '') + '>Previous</button>' + nums.map(function (n) { return '<button class="btn btn-sm' + (n === p ? ' btn-primary' : '') + '" data-p="' + n + '"' + (n === p ? ' aria-current="page"' : '') + '>' + n + '</button>'; }).join('') + '<button class="btn btn-sm" data-p="' + (p + 1) + '"' + (p >= r.pages ? ' disabled' : '') + '>Next</button>';
		d.addEventListener('click', function (e) { var b = e.target.closest('[data-p]'); if (b && !b.disabled) cb(+b.getAttribute('data-p')); });
		return d;
	};
	function saveText(name, text) {
		var a = document.createElement('a');
		a.href = URL.createObjectURL(new Blob([text], { type: 'text/csv;charset=utf-8' }));
		a.download = name;
		a.click();
		setTimeout(function () { URL.revokeObjectURL(a.href); }, 5000);
	}
	function copy(text, ok) {
		var done = function () { A.toast(ok || 'Copied'); };
		if (navigator.clipboard) navigator.clipboard.writeText(text).then(done).catch(function () { A.ask('Copy this link', 'Link', {}); });
		else window.prompt('Copy this link', text);
	}
	A.copyText = copy;
	A.openWhatsApp = function (path) {
		api(path).then(function (r) {
			if (!r.has_number) A.toast('No valid phone number on this record. WhatsApp will ask you to choose a contact.');
			window.open(r.url, '_blank', 'noopener');
		}).catch(A.fail);
	};

	/* ---- Search ---- */
	V.search = function (el) {
		var q = A.query.q || '';
		A.setTitle('Search');
		el.innerHTML = '<p class="muted">Results for <b>' + esc(q) + '</b></p><section class="card card-pad" data-r>' + A.loading(3) + '</section>';
		return api('search', { params: { q: q } }).then(function (r) {
			el.querySelector('[data-r]').innerHTML = r.items.length ? r.items.map(function (x) { return '<a class="row" href="' + esc(x.link) + '"><span class="badge">' + esc(x.type) + '</span><div class="grow">' + esc(x.title || '(untitled)') + (x.sub ? ' <span class="muted small">' + esc(String(x.sub).replace(/_/g, ' ')) + '</span>' : '') + '</div></a>'; }).join('') : A.empty('Nothing found', 'Try a different word.');
		});
	};

	/* ---- Daily Planner: references only ---- */
	V.planner = function (el) {
		A.setTitle('Daily Planner');
		if (!A.gate(el, 'daily_planner')) return;
		var date = A.query.date || A.localDay();
		var nav = function (n) { A.go('/planner?date=' + A.addDays(date, n)); };
		return api('planner', { params: { date: date } }).then(function (d) {
			return api('calendar/feed', { params: { from: date, to: date } }).catch(function () { return { items: [] }; }).then(function (feed) {
				var ev = (feed.items || []).filter(function (e) { return e.source !== 'task'; });
				var taskRow = function (t) { return '<label class="row plan-task"><input type="checkbox" data-task="' + t.id + '"' + (t.status === 'done' ? ' checked' : '') + '><div class="grow">' + esc(t.title) + ' <span class="muted small">' + esc(t.priority) + (t.due_date !== date ? ' · due ' + esc(A.fmtD(t.due_date)) : '') + '</span></div></label>'; };
				el.innerHTML = '<div class="toolbar"><button class="btn btn-sm" data-prev>Previous</button><b class="grow center">' + esc(A.fmtD(date)) + '</b><button class="btn btn-sm" data-next>Next</button></div>' +
					'<section class="card card-pad"><div class="row" style="border:0"><div class="grow"><b>Today\'s progress</b> <span class="muted small">' + d.progress.done + ' of ' + d.progress.total + ' done</span></div><b>' + d.progress.percent + '%</b></div><div class="bar"><i style="width:' + d.progress.percent + '%"></i></div></section>' +
					'<div class="grid" style="margin-top:14px"><section class="card card-pad"><h2>Priorities</h2><div data-pri></div><form data-addp class="inline-add"><input placeholder="Add a priority" aria-label="Add a priority"><button class="btn btn-sm">Add</button></form></section>' +
					'<section class="card card-pad"><h2>Tasks</h2>' + (d.tasks.length ? d.tasks.map(taskRow).join('') : '<p class="muted">No tasks due.</p>') + (d.overdue.length ? '<h3 style="margin-top:12px">Overdue</h3>' + d.overdue.map(taskRow).join('') : '') + '</section>' +
					'<section class="card card-pad"><h2>Events</h2>' + (ev.length ? ev.map(function (e) { return '<div class="row"><div class="grow">' + esc(e.title) + '</div><span class="muted small">' + (e.all_day ? 'All day' : esc(A.fmtT(e.start))) + '</span></div>'; }).join('') : '<p class="muted">Nothing scheduled.</p>') + '</section>' +
					'<section class="card card-pad"><h2>Follow-ups</h2>' + (d.follow_ups.length ? d.follow_ups.map(function (l) { return '<a class="row" href="#/crm/leads?open=' + l.id + '"><div class="grow">' + esc(l.name) + ' <span class="muted small">' + esc(l.enquiry) + '</span></div><span class="muted small">' + esc(A.fmtD(l.follow_up_on)) + '</span></a>'; }).join('') : '<p class="muted">No follow-ups.</p>') + '</section>' +
					'<section class="card card-pad"><h2>Reminders</h2>' + (d.reminders.length ? d.reminders.map(function (t) { return '<div class="row"><div class="grow">' + esc(t.title) + '</div><span class="muted small">' + esc(A.fmtT(t.reminder_at)) + '</span></div>'; }).join('') : '<p class="muted">None for this day.</p>') + '</section>' +
					'<section class="card card-pad"><h2>Notes</h2><textarea data-notes rows="5" aria-label="Notes for this day">' + esc(d.notes) + '</textarea></section></div>';
				var pri = d.priorities.slice();
				var save = function () { return api('planner', { method: 'PUT', body: { date: date, priorities: pri, notes: el.querySelector('[data-notes]').value } }).catch(A.fail); };
				var drawPri = function () {
					el.querySelector('[data-pri]').innerHTML = pri.length ? pri.map(function (p, i) { return '<div class="row"><input type="checkbox" data-i="' + i + '"' + (p.done ? ' checked' : '') + ' aria-label="Done"><div class="grow">' + esc(p.text) + '</div><button class="icon-btn" data-del="' + i + '" aria-label="Remove">' + A.I.x + '</button></div>'; }).join('') : '<p class="muted">What matters most today?</p>';
				};
				drawPri();
				el.querySelector('[data-pri]').addEventListener('click', function (e) {
					var x = e.target.closest('[data-del]'), c = e.target.closest('[data-i]');
					if (x) { pri.splice(+x.getAttribute('data-del'), 1); drawPri(); save(); }
					if (c) { pri[+c.getAttribute('data-i')].done = c.checked; save(); }
				});
				var ap = el.querySelector('[data-addp]');
				ap.addEventListener('submit', function (e) { e.preventDefault(); var i = ap.querySelector('input'); if (i.value.trim()) { pri.push({ text: i.value.trim(), done: false }); i.value = ''; drawPri(); save(); } });
				el.querySelector('[data-notes]').addEventListener('change', save);
				el.querySelector('[data-prev]').onclick = function () { nav(-1); };
				el.querySelector('[data-next]').onclick = function () { nav(1); };
				el.querySelectorAll('[data-task]').forEach(function (c) { c.onchange = function () { api('tasks/' + c.getAttribute('data-task'), { method: 'PUT', body: { status: c.checked ? 'done' : 'todo' } }).catch(A.fail); }; });
			});
		});
	};

	/* ---- Meetings: stored on the calendar, one record ---- */
	function memberPicks(cur) {
		return '<div class="span-2"><span class="field-label">Participants</span><div class="pick-list">' + S.boot.members.map(function (m) { return '<label class="check"><input type="checkbox" data-mem value="' + m.id + '"' + (cur.indexOf(+m.id) > -1 ? ' checked' : '') + '> <span>' + esc(m.name) + '</span></label>'; }).join('') + '</div></div>';
	}
	function meetingForm(m, done) {
		var cur = m ? m.participants.map(function (p) { return +p.user_id; }) : [];
		return api('projects', { params: { status: 'active' } }).catch(function () { return { items: [] }; }).then(function (pr) {
			A.formModal({
				title: m ? 'Edit meeting' : 'New meeting', size: 'lg', ok: m ? 'Save' : 'Create',
				values: m ? { title: m.title, starts_at: A.localDT(m.starts_at), duration_min: m.duration_min, location: m.location, meeting_url: m.meeting_url, agenda: m.agenda, project_id: m.project_id } : { duration_min: 30 },
				fields: [{ k: 'title', label: 'Title', required: true, span: true }, { k: 'starts_at', label: 'Date and time', type: 'datetime-local', required: true }, { k: 'duration_min', label: 'Minutes', type: 'number', min: 1 }, { k: 'location', label: 'Location' }, { k: 'meeting_url', label: 'Meeting link', type: 'url' }, { k: 'project_id', label: 'Calendar', type: 'select', span: true, opts: A.options((pr.items || []).map(function (p) { return [p.id, 'Project: ' + p.name]; }), m ? m.project_id : '', 'Business calendar') }, { k: 'agenda', label: 'Agenda', type: 'textarea', span: true }],
				extra: memberPicks(cur),
				onSave: function (v, modal) {
					v.participants = Array.prototype.map.call(modal.el.querySelectorAll('[data-mem]:checked'), function (c) { return +c.value; });
					return api(m ? 'meetings/' + m.id : 'meetings', { method: m ? 'PUT' : 'POST', body: v }).then(done);
				},
				del: m ? function () { return api('meetings/' + m.id, { method: 'DELETE' }).then(function () { A.go('/meetings'); }); } : null
			});
		});
	}
	V.meetings = function (el) {
		A.setTitle('Meetings');
		if (!A.gate(el, 'meetings')) return;
		return api('meetings').then(function (r) {
			el.innerHTML = '<div class="toolbar"><span class="grow"></span>' + (A.can('tasks.write') ? '<button class="btn btn-primary btn-sm" data-new>New meeting</button>' : '') + '</div><section class="card card-pad">' + (r.items.length ? r.items.map(function (m) { return '<a class="row" href="#/meetings/' + m.id + '"><div class="grow"><b>' + esc(m.title) + '</b><div class="muted small">' + esc(A.fmtDT(m.starts_at)) + (m.location ? ' · ' + esc(m.location) : '') + '</div></div>' + A.badge(m.status) + '</a>'; }).join('') : A.empty('No meetings yet', 'Meetings appear on your calendar automatically.')) + '</section>';
			var n = el.querySelector('[data-new]');
			if (n) n.onclick = function () { meetingForm(null, function () { V.meetings(el); }); };
		});
	};
	V.meeting = function (el, m) {
		if (!A.gate(el, 'meetings')) return;
		return api('meetings/' + m[1]).then(function (d) {
			A.setTitle(d.title);
			var w = A.can('tasks.write');
			el.innerHTML = '<div class="toolbar"><a class="btn btn-sm" href="#/meetings">All meetings</a><span class="grow"></span>' + (w ? '<select data-st aria-label="Status">' + A.options([['scheduled', 'Scheduled'], ['completed', 'Completed'], ['cancelled', 'Cancelled']], d.status) + '</select><button class="btn btn-sm" data-edit>Edit</button>' : '') + '</div><div class="stack">' +
				'<section class="card card-pad"><p><b>' + esc(A.fmtDT(d.starts_at)) + '</b> · ' + (+d.duration_min) + ' minutes' + (d.location ? ' · ' + esc(d.location) : '') + '</p>' + (d.meeting_url ? '<p><a href="' + A.safeUrl(d.meeting_url) + '" target="_blank" rel="noopener">Join link</a></p>' : '') + '<p class="muted small">' + (d.participants.map(function (p) { return esc(p.name); }).join(', ') || 'No participants') + '</p>' + (d.agenda ? '<h3>Agenda</h3><p>' + esc(d.agenda).replace(/\n/g, '<br>') + '</p>' : '') + '</section>' +
				'<section class="card card-pad"><label class="field"><span>Discussion notes</span><textarea data-f="notes" rows="4"' + (w ? '' : ' readonly') + '>' + esc(d.notes) + '</textarea></label><label class="field"><span>Decisions</span><textarea data-f="decisions" rows="3"' + (w ? '' : ' readonly') + '>' + esc(d.decisions) + '</textarea></label></section>' +
				'<section class="card card-pad"><h2>Action items</h2>' + (d.actions.length ? d.actions.map(function (t) { return '<div class="row"><div class="grow">' + esc(t.title) + (t.assignee_id ? ' <span class="muted small">' + esc(A.memberName(t.assignee_id)) + '</span>' : '') + (t.due_date ? ' <span class="muted small">' + esc(A.fmtD(t.due_date)) + '</span>' : '') + '</div>' + A.badge(t.status) + '</div>'; }).join('') : '<p class="muted">None yet. Each one becomes a task.</p>') + (w ? '<button class="btn btn-sm" data-act style="margin-top:8px">Add action item</button>' : '') + '</section></div>';
			if (!w) return;
			el.querySelectorAll('[data-f]').forEach(function (t) { t.onchange = function () { var b = {}; b[t.getAttribute('data-f')] = t.value; api('meetings/' + d.id, { method: 'PUT', body: b }).then(function () { A.toast('Saved'); }).catch(A.fail); }; });
			el.querySelector('[data-st]').onchange = function (e) { api('meetings/' + d.id, { method: 'PUT', body: { status: e.target.value } }).then(function () { A.toast('Saved'); }).catch(A.fail); };
			el.querySelector('[data-edit]').onclick = function () { meetingForm(d, function () { V.meeting(el, m); }); };
			el.querySelector('[data-act]').onclick = function () {
				A.formModal({ title: 'Action item', fields: [{ k: 'title', label: 'What needs doing', required: true, span: true }, { k: 'assignee_id', label: 'Who', type: 'select', opts: A.memberOptions('', 'Nobody yet') }, { k: 'due_date', label: 'Due', type: 'date' }], onSave: function (v) { return api('meetings/' + d.id + '/actions', { method: 'POST', body: v }).then(function () { V.meeting(el, m); }); } });
			};
		});
	};

	/* ---- Form builder: custom and registration forms share it ---- */
	var FTYPES = [['text', 'Text'], ['email', 'Email'], ['phone', 'Phone'], ['number', 'Number'], ['date', 'Date'], ['dropdown', 'Dropdown'], ['choice', 'Multiple choice'], ['checkbox', 'Checkbox'], ['file', 'File upload'], ['textarea', 'Long text']];
	function formsList(kind) {
		var base = kind === 'registration' ? 'registrations' : 'forms', feat = kind === 'registration' ? 'registration_forms' : 'custom_forms', label = kind === 'registration' ? 'Registration Forms' : 'Custom Forms';
		return function (el) {
			A.setTitle(label);
			if (!A.gate(el, feat)) return;
			return api('forms', { params: { kind: kind } }).then(function (r) {
				el.innerHTML = '<div class="toolbar"><span class="muted small grow">' + (kind === 'registration' ? 'For workshops, events and classes, with capacity, waitlist and check-in.' : 'Share a link or embed a form on your site.') + '</span>' + (A.can('crm.write') ? '<a class="btn btn-primary btn-sm" href="#/' + base + '/new">New form</a>' : '') + '</div><section class="card card-pad">' + (r.items.length ? r.items.map(function (f) { return '<a class="row" href="#/' + base + '/' + f.id + '"><div class="grow"><b>' + esc(f.title) + '</b><div class="muted small">' + f.metrics.submissions + ' entries · ' + f.metrics.views + ' views · ' + f.metrics.conversion + '%</div></div>' + A.badge(f.status === 'published' ? 'active' : f.status, f.status) + '</a>'; }).join('') : A.empty('No forms yet', 'Create your first one.')) + '</section>';
			});
		};
	}
	V.forms = formsList('custom');
	V.registrations = formsList('registration');
	function formEdit(kind) {
		var base = kind === 'registration' ? 'registrations' : 'forms', feat = kind === 'registration' ? 'registration_forms' : 'custom_forms';
		return function (el, m) {
			if (!A.gate(el, feat)) return;
			var id = m[1] === 'new' ? 0 : +m[1];
			var start = function (f) {
				f = f || { title: '', description: '', fields: [{ key: 'name', type: 'text', label: 'Your name', required: true, options: [] }, { key: 'email', type: 'email', label: 'Email', required: true, options: [] }, { key: 'phone', type: 'phone', label: 'Phone', required: false, options: [] }], settings: { create_crm: 'lead', notify: true }, status: 'draft' };
				A.setTitle(id ? 'Edit form' : 'New form');
				var fields = f.fields.map(function (x) { return Object.assign({}, x); }), st = f.settings || {};
				el.innerHTML = '<div class="toolbar"><a class="btn btn-sm" href="#/' + base + (id ? '/' + id : '') + '">Back</a><span class="grow"></span><button class="btn btn-primary btn-sm" data-save>Save</button></div><div class="stack">' +
					'<section class="card card-pad"><div class="form-grid">' + A.fieldHtml({ k: 'title', label: 'Title', required: true, span: true }, f.title) + A.fieldHtml({ k: 'description', label: 'Description', type: 'textarea', span: true }, f.description) +
					(kind === 'registration' ? A.fieldHtml({ k: 'event_start', label: 'Event starts', type: 'datetime-local' }, f.event_start ? A.localDT(f.event_start) : '') + A.fieldHtml({ k: 'event_end', label: 'Event ends', type: 'datetime-local' }, f.event_end ? A.localDT(f.event_end) : '') + A.fieldHtml({ k: 'location', label: 'Location', span: true }, st.location) + A.fieldHtml({ k: 'opens_at', label: 'Registration opens', type: 'datetime-local' }, f.opens_at ? A.localDT(f.opens_at) : '') + A.fieldHtml({ k: 'closes_at', label: 'Registration closes', type: 'datetime-local' }, f.closes_at ? A.localDT(f.closes_at) : '') + A.fieldHtml({ k: 'capacity', label: 'Capacity', type: 'number', min: 1, hint: 'empty means no limit' }, f.capacity) + A.fieldHtml({ k: 'waitlist', label: 'Use a waitlist when full', type: 'check' }, +f.waitlist) : '') +
					'</div></section><section class="card card-pad"><h2>Fields</h2><div data-fields></div><button class="btn btn-sm" data-add>Add field</button></section>' +
					'<section class="card card-pad"><h2>After submitting</h2><div class="form-grid">' + A.fieldHtml({ k: 'confirmation', label: 'Confirmation message', type: 'textarea', span: true }, st.confirmation) + A.fieldHtml({ k: 'create_crm', label: 'Add to your CRM', type: 'select', opts: A.options([['none', 'No'], ['lead', 'As a lead'], ['customer', 'As a customer']], st.create_crm || 'none') }) + A.fieldHtml({ k: 'notify', label: 'Notify me of new entries', type: 'check' }, st.notify) + A.fieldHtml({ k: 'privacy_note', label: 'Privacy note shown on the form', type: 'textarea', span: true }, st.privacy_note) + '</div></section></div>';
				var box = el.querySelector('[data-fields]');
				var sync = function () {
					box.querySelectorAll('[data-row]').forEach(function (r) {
						var i = +r.getAttribute('data-row'), x = fields[i];
						x.label = r.querySelector('[data-k=label]').value;
						x.type = r.querySelector('[data-k=type]').value;
						x.required = r.querySelector('[data-k=required]').checked;
						var o = r.querySelector('[data-k=options]');
						x.options = o ? o.value.split('\n').map(function (s) { return s.trim(); }).filter(Boolean) : [];
						var dep = r.querySelector('[data-k=dep]').value, eq = r.querySelector('[data-k=eq]').value;
						if (dep) x.show_if = { field: dep, equals: eq }; else delete x.show_if;
					});
				};
				var draw = function () {
					box.innerHTML = fields.map(function (x, i) {
						var others = fields.filter(function (o, j) { return j !== i && o.key; });
						return '<div class="fb-row" data-row="' + i + '"><div class="form-grid"><label class="field"><span>Label</span><input data-k="label" value="' + esc(x.label) + '"></label><label class="field"><span>Type</span><select data-k="type">' + A.options(FTYPES, x.type) + '</select></label>' +
							(x.type === 'dropdown' || x.type === 'choice' ? '<label class="field span-2"><span>Options, one per line</span><textarea data-k="options" rows="3">' + esc((x.options || []).join('\n')) + '</textarea></label>' : '') +
							'<label class="field"><span>Show only if</span><select data-k="dep">' + A.options(others.map(function (o) { return [o.key, o.label]; }), x.show_if ? x.show_if.field : '', 'Always show') + '</select></label><label class="field"><span>equals</span><input data-k="eq" value="' + esc(x.show_if ? x.show_if.equals : '') + '"></label></div>' +
							'<div class="fb-acts"><label class="check"><input type="checkbox" data-k="required"' + (x.required ? ' checked' : '') + '> <span>Required</span></label><span class="grow"></span><button class="btn btn-sm" data-up="' + i + '"' + (i === 0 ? ' disabled' : '') + '>Up</button><button class="btn btn-sm" data-rm="' + i + '">Remove</button></div></div>';
					}).join('');
				};
				draw();
				box.addEventListener('change', function (e) { if (e.target.getAttribute('data-k') === 'type') { sync(); draw(); } });
				box.addEventListener('click', function (e) {
					var rm = e.target.closest('[data-rm]'), up = e.target.closest('[data-up]');
					if (rm) { sync(); fields.splice(+rm.getAttribute('data-rm'), 1); draw(); }
					if (up) { sync(); var i = +up.getAttribute('data-up'), t = fields[i]; fields[i] = fields[i - 1]; fields[i - 1] = t; draw(); }
				});
				el.querySelector('[data-add]').onclick = function () { sync(); fields.push({ key: 'q' + A.uuid().slice(-6), type: 'text', label: 'New question', required: false, options: [] }); draw(); };
				el.querySelector('[data-save]').onclick = function () {
					sync();
					var g = function (k) { var i = el.querySelector('[name=' + k + ']'); return i ? (i.type === 'checkbox' ? i.checked : i.value) : undefined; };
					var body = { kind: kind, title: g('title'), description: g('description'), fields: fields, settings: { confirmation: g('confirmation'), create_crm: g('create_crm'), notify: g('notify'), privacy_note: g('privacy_note'), location: g('location') } };
					if (kind === 'registration') ['event_start', 'event_end', 'opens_at', 'closes_at', 'capacity', 'waitlist'].forEach(function (k) { body[k] = g(k); });
					api(id ? 'forms/' + id : 'forms', { method: id ? 'PUT' : 'POST', body: body }).then(function (r) { A.toast('Saved'); A.go('/' + base + '/' + r.id); }).catch(A.fail);
				};
			};
			if (id) return api('forms/' + id).then(start);
			start();
		};
	}
	V.formEdit = formEdit('custom');
	V.registrationEdit = formEdit('registration');
	function formShow(kind) {
		var base = kind === 'registration' ? 'registrations' : 'forms', feat = kind === 'registration' ? 'registration_forms' : 'custom_forms';
		return function (el, m) {
			if (!A.gate(el, feat)) return;
			var page = 1, status = '', q = '';
			var go = function () {
				return Promise.all([api('forms/' + m[1]), api('forms/' + m[1] + '/submissions', { params: { page: page, status: status, q: q } })]).then(function (rs) {
					var f = rs[0], s = rs[1], w = A.can('crm.write');
					A.setTitle(f.title);
					var embed = '<iframe src="' + f.url + '?embed=1" width="100%" height="640" style="border:0" title="' + esc(f.title) + '"></iframe>';
					el.innerHTML = '<div class="toolbar"><a class="btn btn-sm" href="#/' + base + '">All forms</a><span class="grow"></span>' + (w ? '<select data-st aria-label="Status">' + A.options([['draft', 'Draft'], ['published', 'Published'], ['closed', 'Closed']], f.status) + '</select><a class="btn btn-sm" href="#/' + base + '/' + f.id + '/edit">Edit</a>' : '') + '</div><div class="stack">' +
						'<section class="card card-pad"><div class="stats"><div class="stat"><b>' + f.metrics.views + '</b><span class="muted small">Views</span></div><div class="stat"><b>' + f.metrics.submissions + '</b><span class="muted small">Entries</span></div><div class="stat"><b>' + f.metrics.conversion + '%</b><span class="muted small">Completed</span></div>' + (kind === 'registration' ? '<div class="stat"><b>' + (f.metrics.by_status.registered || 0) + (f.capacity ? ' / ' + f.capacity : '') + '</b><span class="muted small">Registered</span></div><div class="stat"><b>' + (f.metrics.by_status.waitlisted || 0) + '</b><span class="muted small">Waitlist</span></div>' : '') + '</div>' +
						(f.status === 'published' ? '<div class="share-row"><a href="' + A.safeUrl(f.url) + '" target="_blank" rel="noopener">' + esc(f.url) + '</a><button class="btn btn-sm" data-cp>Copy link</button><button class="btn btn-sm" data-em>Copy embed code</button></div>' : '<p class="muted small" style="margin-top:10px">Publish this form to get its link.</p>') + '</section>' +
						'<section class="card"><div class="card-h"><h2>Entries</h2></div><div class="card-b"><div class="toolbar"><input data-q placeholder="Search name, email or phone" value="' + esc(q) + '" aria-label="Search entries">' + (kind === 'registration' ? '<select data-fs aria-label="Filter by status">' + A.options([['registered', 'Registered'], ['waitlisted', 'Waitlist'], ['cancelled', 'Cancelled']], status, 'Everyone') + '</select>' : '') + (A.can('crm.export') ? '<button class="btn btn-sm" data-x>Export</button>' : '') + '</div>' +
						(s.items.length ? s.items.map(function (x) {
							var acts = kind === 'registration' && w ? (x.status === 'waitlisted' ? '<button class="btn btn-sm" data-en="' + x.id + '" data-to="registered">Move in</button>' : '') + (x.status === 'registered' ? '<button class="btn btn-sm" data-ck="' + x.id + '" data-on="' + (x.checked_in_at ? 0 : 1) + '">' + (x.checked_in_at ? 'Undo check-in' : 'Check in') + '</button>' : '') + (x.status !== 'cancelled' ? '<button class="btn btn-sm" data-en="' + x.id + '" data-to="cancelled">Cancel</button>' : '') : '';
							var answers = f.fields.map(function (fd) { var v = x.data[fd.key]; if (v === undefined || v === '') return ''; return '<div class="muted small">' + esc(fd.label) + ': ' + (fd.type === 'file' && v.name ? '<button class="link" data-file="' + x.id + '" data-fk="' + esc(fd.key) + '" data-fn="' + esc(v.name) + '">' + esc(v.name) + '</button>' : esc(v)) + '</div>'; }).join('');
							return '<div class="row entry"><div class="grow"><b>' + esc(x.name || 'Entry') + '</b> ' + (kind === 'registration' ? A.badge(x.status) : '') + (x.checked_in_at ? ' ' + A.badge('confirmed', 'checked in') : '') + answers + '<div class="muted small">' + esc(A.fmtDT(x.created_at)) + '</div></div>' + acts + '</div>';
						}).join('') : A.empty('No entries yet', '')) + '</div><div data-pg class="card-b"></div></section></div>';
					el.querySelector('[data-pg]').appendChild(A.pager(s, function (p) { page = p; go(); }));
					var st = el.querySelector('[data-st]');
					if (st) st.onchange = function () { api('forms/' + f.id, { method: 'PUT', body: { status: st.value } }).then(go).catch(A.fail); };
					var cp = el.querySelector('[data-cp]');
					if (cp) cp.onclick = function () { copy(f.url); };
					var em = el.querySelector('[data-em]');
					if (em) em.onclick = function () { copy(embed, 'Embed code copied'); };
					var qi = el.querySelector('[data-q]');
					qi.onchange = function () { q = qi.value; page = 1; go(); };
					var fs = el.querySelector('[data-fs]');
					if (fs) fs.onchange = function () { status = fs.value; page = 1; go(); };
					var ex = el.querySelector('[data-x]');
					if (ex) ex.onclick = function () { api('forms/' + f.id + '/export').then(function (d) { saveText(d.filename, d.csv); }).catch(A.fail); };
					el.querySelectorAll('[data-en]').forEach(function (b) { b.onclick = function () { api('forms/' + f.id + '/submissions/' + b.getAttribute('data-en'), { method: 'PUT', body: { status: b.getAttribute('data-to') } }).then(go).catch(A.fail); }; });
					el.querySelectorAll('[data-ck]').forEach(function (b) { b.onclick = function () { api('forms/' + f.id + '/submissions/' + b.getAttribute('data-ck'), { method: 'PUT', body: { checked_in: b.getAttribute('data-on') === '1' } }).then(go).catch(A.fail); }; });
					el.querySelectorAll('[data-file]').forEach(function (b) { b.onclick = function () { A.download('forms/' + f.id + '/submissions/' + b.getAttribute('data-file') + '/file?field=' + encodeURIComponent(b.getAttribute('data-fk')), b.getAttribute('data-fn')); }; });
				});
			};
			return go();
		};
	}
	V.formShow = formShow('custom');
	V.registrationShow = formShow('registration');

	/* ---- Accounts & Finance ---- */
	V.finance = function (el) {
		A.setTitle('Accounts & Finance');
		if (!A.gate(el, 'accounts_finance')) return;
		var to = A.localDay(), from = to.slice(0, 7) + '-01', page = 1, w = A.can('finance.write');
		var go = function () {
			return Promise.all([api('finance/summary', { params: { from: from, to: to } }), api('finance/entries', { params: { page: page, from: from, to: to } }), api('finance/accounts')]).then(function (rs) {
				var d = rs[0], e = rs[1], acc = rs[2].items;
				var stat = function (v, l) { return '<div class="stat"><b>' + A.inr(v) + '</b><span class="muted small">' + esc(l) + '</span></div>'; };
				el.innerHTML = '<div class="toolbar"><input type="date" data-from value="' + from + '" aria-label="From"><input type="date" data-to value="' + to + '" aria-label="To"><span class="grow"></span>' + (w ? '<button class="btn btn-sm" data-inc>Add income</button><button class="btn btn-primary btn-sm" data-exp>Add expense</button>' : '') + '</div><div class="stack">' +
					'<section class="card card-pad"><div class="stats">' + stat(d.income_total, 'Income') + stat(d.expenses, 'Expenses') + stat(d.net, 'Net') + stat(d.outstanding, 'Outstanding') + stat(d.booking_revenue, 'From bookings') + '</div><p class="muted small" style="margin:10px 0 0">A simple record of your business. It does not replace statutory accounts.</p></section>' +
					'<div class="grid"><section class="card card-pad"><h2>Payments by method</h2>' + (d.by_method.length ? d.by_method.map(function (x) { return '<div class="row"><div class="grow" style="text-transform:capitalize">' + esc(x.method) + '</div><b>' + A.inr(x.total) + '</b></div>'; }).join('') : '<p class="muted">None in this period.</p>') + '</section>' +
					'<section class="card card-pad"><h2>Expenses by category</h2>' + (d.expense_categories.length ? d.expense_categories.map(function (x) { return '<div class="row"><div class="grow">' + esc(x.category || 'Other') + '</div><b>' + A.inr(x.total) + '</b></div>'; }).join('') : '<p class="muted">None in this period.</p>') + '</section>' +
					'<section class="card card-pad"><h2>Who owes you</h2>' + (d.by_customer.length ? d.by_customer.map(function (x) { return '<div class="row"><div class="grow">' + esc(x.name || 'Customer') + ' <span class="muted small">' + (+x.invoices) + (+x.invoices === 1 ? ' invoice' : ' invoices') + '</span></div><b>' + A.inr(x.outstanding) + '</b></div>'; }).join('') : '<p class="muted">Nothing outstanding.</p>') + '</section></div>' +
					'<section class="card"><div class="card-h"><h2>Monthly summary</h2></div><div class="card-b"><div class="table-wrap"><table class="tbl"><thead><tr><th>Month</th><th class="num">Income</th><th class="num">Expenses</th><th class="num">Net</th></tr></thead><tbody>' + d.monthly.slice().reverse().filter(function (m) { return m.income || m.expense; }).map(function (m) { return '<tr><td>' + esc(m.month) + '</td><td class="num">' + A.inr(m.income) + '</td><td class="num">' + A.inr(m.expense) + '</td><td class="num">' + A.inr(m.income - m.expense) + '</td></tr>'; }).join('') + '</tbody></table></div></div></section>' +
					'<section class="card"><div class="card-h"><h2>Income and expense entries</h2></div><div class="card-b">' + (e.items.length ? e.items.map(function (x) { return '<div class="row" ' + (w ? 'data-e="' + x.id + '" style="cursor:pointer"' : '') + '><div class="grow"><b>' + esc(x.category || (x.entry_type === 'income' ? 'Income' : 'Expense')) + '</b> ' + A.badge(x.entry_type === 'income' ? 'active' : 'pending', x.entry_type) + '<div class="muted small">' + esc(A.fmtD(x.on_date)) + ' · ' + esc(x.method) + (x.reference ? ' · ' + esc(x.reference) : '') + '</div></div><b>' + A.inr(x.amount) + '</b></div>'; }).join('') : A.empty('No entries in this period', 'Customer payments on invoices are counted automatically.')) + '<div data-pg></div></div></section>' +
					(A.can('settings.manage') ? '<section class="card"><div class="card-h"><h2>Payment accounts</h2><button class="btn btn-sm" data-acc>Add</button></div><div class="card-b">' + (acc.length ? acc.map(function (a) { return '<div class="row" data-a="' + a.id + '" style="cursor:pointer"><div class="grow"><b>' + esc(a.label || a.kind) + '</b>' + (a.is_default ? ' ' + A.badge('active', 'default') : '') + '<div class="muted small">' + esc(a.kind === 'bank' ? [a.bank_name, a.account_last4 ? 'ending ' + a.account_last4 : ''].filter(Boolean).join(' · ') : a.upi_id) + '</div></div></div>'; }).join('') : '<p class="muted">Add a bank account or UPI ID to show on your invoices.</p>') + '</div></section>' +
					'<section class="card card-pad"><h2>Online payments for your invoices</h2><p class="muted small">Optional. Uses your own Razorpay account, separate from your Neon Workspace subscription. Money goes straight to you.</p><div data-gw>' + A.loading(1) + '</div></section>' : '') + '</div>';
				el.querySelector('[data-pg]').appendChild(A.pager(e, function (p) { page = p; go(); }));
				el.querySelector('[data-from]').onchange = function (x) { from = x.target.value; page = 1; go(); };
				el.querySelector('[data-to]').onchange = function (x) { to = x.target.value; page = 1; go(); };
				var entry = function (type, row) {
					A.formModal({
						title: (row ? 'Edit ' : 'Add ') + type, values: row || { on_date: A.localDay(), method: 'cash' },
						fields: [{ k: 'amount', label: 'Amount', type: 'number', step: '0.01', min: 0, required: true }, { k: 'on_date', label: 'Date', type: 'date', required: true }, { k: 'category', label: 'Category' }, { k: 'method', label: 'Paid by', type: 'select', opts: A.options([['cash', 'Cash'], ['bank', 'Bank'], ['upi', 'UPI'], ['card', 'Card'], ['cheque', 'Cheque'], ['other', 'Other']], row ? row.method : 'cash') }, { k: 'reference', label: 'Reference', span: true }, { k: 'notes', label: 'Notes', type: 'textarea', span: true }],
						onSave: function (v) { v.entry_type = type; return api(row ? 'finance/entries/' + row.id : 'finance/entries', { method: row ? 'PUT' : 'POST', body: v }).then(go); },
						del: row ? function () { return api('finance/entries/' + row.id, { method: 'DELETE' }).then(go); } : null
					});
				};
				var inc = el.querySelector('[data-inc]'), exp = el.querySelector('[data-exp]');
				if (inc) inc.onclick = function () { entry('income'); };
				if (exp) exp.onclick = function () { entry('expense'); };
				el.querySelectorAll('[data-e]').forEach(function (r) { r.onclick = function () { var x = e.items.filter(function (i) { return +i.id === +r.getAttribute('data-e'); })[0]; entry(x.entry_type, x); }; });
				var acct = function (a) {
					A.formModal({
						title: a ? 'Edit account' : 'Add payment account', size: 'lg', values: a || { kind: 'bank' }, ok: 'Save',
						fields: [{ k: 'kind', label: 'Type', type: 'select', opts: A.options([['bank', 'Bank'], ['upi', 'UPI'], ['cash', 'Cash'], ['other', 'Other']], a ? a.kind : 'bank') }, { k: 'label', label: 'Name for this account' }, { k: 'holder', label: 'Account holder' }, { k: 'bank_name', label: 'Bank name' }, { k: 'account_no', label: 'Account number', value: '' }, { k: 'ifsc', label: 'IFSC' }, { k: 'branch', label: 'Branch' }, { k: 'upi_id', label: 'UPI ID', hint: 'name@bank' }, { k: 'qr_url', label: 'UPI QR image link', type: 'url', span: true, hint: 'upload below, or leave empty' }, { k: 'instructions', label: 'Payment instructions', type: 'textarea', span: true }, { k: 'is_default', label: 'Use by default on new invoices', type: 'check' }],
						extra: '<label class="field"><span>Upload QR image</span><input type="file" data-qr accept="image/png,image/jpeg"></label>',
						ready: function (m) {
							m.el.querySelector('[data-qr]').onchange = function (e) {
								var f = e.target.files[0];
								if (!f) return;
								var fd = new FormData();
								fd.append('file', f);
								fetch(window.NW.root + 'business/image?kind=qr', { method: 'POST', headers: { 'X-WP-Nonce': window.NW.nonce, 'X-NW-Workspace': S.workspaceId }, credentials: 'same-origin', body: fd }).then(function (r) { return r.json().then(function (d) { if (!r.ok) throw new Error(d.message); return d; }); }).then(function (d) { m.el.querySelector('[name=qr_url]').value = d.url; A.toast('Uploaded. Scan it to check it before you use it.'); }).catch(A.fail);
							};
						},
						onSave: function (v) { return api(a ? 'finance/accounts/' + a.id : 'finance/accounts', { method: a ? 'PUT' : 'POST', body: v }).then(go); },
						del: a ? function () { return api('finance/accounts/' + a.id, { method: 'DELETE' }).then(go); } : null
					});
				};
				var ab = el.querySelector('[data-acc]');
				if (ab) ab.onclick = function () { acct(); };
				el.querySelectorAll('[data-a]').forEach(function (r) { r.onclick = function () { acct(acc.filter(function (i) { return +i.id === +r.getAttribute('data-a'); })[0]); }; });
				var gw = el.querySelector('[data-gw]');
				if (gw) api('finance/gateway').then(function (g) {
					gw.innerHTML = '<div class="form-grid">' + A.fieldHtml({ k: 'g_mode', label: 'Mode', type: 'select', opts: A.options([['off', 'Off'], ['test', 'Test mode'], ['live', 'Live mode']], g.mode) }) + A.fieldHtml({ k: 'g_test_key', label: 'Test Key ID' }, g.test_key) + A.fieldHtml({ k: 'g_test_secret', label: 'Test Key Secret', type: 'password', hint: g.has_test_secret ? 'saved; leave empty to keep' : '' }) + A.fieldHtml({ k: 'g_live_key', label: 'Live Key ID' }, g.live_key) + A.fieldHtml({ k: 'g_live_secret', label: 'Live Key Secret', type: 'password', hint: g.has_live_secret ? 'saved; leave empty to keep' : '' }) + A.fieldHtml({ k: 'g_webhook', label: 'Webhook secret', type: 'password', hint: g.has_webhook_secret ? 'saved; leave empty to keep' : '' }) + A.fieldHtml({ k: 'g_live_ok', label: 'I confirm real customers can be charged in live mode', type: 'check', span: true }, g.live_confirmed) + '</div><p class="muted small">Webhook URL for your Razorpay dashboard: <code>' + esc(g.webhook_url) + '</code></p><button class="btn btn-sm btn-primary" data-gs>Save</button> <button class="btn btn-sm" data-gt>Test connection</button>';
					var val = function (k) { var i = gw.querySelector('[name=' + k + ']'); return i.type === 'checkbox' ? i.checked : i.value; };
					gw.querySelector('[data-gs]').onclick = function () { api('finance/gateway', { method: 'PUT', body: { mode: val('g_mode'), test_key: val('g_test_key'), test_secret: val('g_test_secret'), live_key: val('g_live_key'), live_secret: val('g_live_secret'), webhook_secret: val('g_webhook'), live_confirmed: val('g_live_ok') } }).then(function () { A.toast('Saved'); }).catch(A.fail); };
					gw.querySelector('[data-gt]').onclick = function () { api('finance/gateway/test', { method: 'POST', body: {} }).then(function (r) { A.toast(r.message, !r.ok); }).catch(A.fail); };
				}).catch(function () {});
			});
		};
		return go();
	};

	/* ---- Files & Google Drive ---- */
	V.files = function (el) {
		A.setTitle('Files & Google Drive');
		if (!A.gate(el, 'google_drive')) return;
		var parent = '', trail = [], flash = { ok: 'Google Drive is connected.', denied: 'Google Drive was not connected.', exchange: 'Google did not confirm the connection. Please try again.', scope: 'Google did not grant the needed permission. Please try again and allow access.', state: 'That attempt expired. Please try again.' }[A.query.drive];
		var size = function (n) { n = +n || 0; return n > 1048576 ? (n / 1048576).toFixed(1) + ' MB' : n > 1024 ? Math.round(n / 1024) + ' KB' : n + ' B'; };
		var load = function () {
			return api('drive').then(function (d) {
				if (!d.connected) {
					var cfgMsg = !d.configured ? '<div class="alert"><b>Google Drive is not available yet.</b><br>NeonDigital is setting up Google Drive for Neon Workspace. Your Workspace files stay available here in the meantime.</div>' : '';
					el.innerHTML = (flash ? '<div class="alert">' + esc(flash) + '</div>' : '') + '<section class="card drive-hero"><div class="drive-ico">' + A.I.drive + '</div><div class="drive-copy"><h2>Connect Google Drive</h2>' +
						'<p>Connect your Google Drive to allow Neon Workspace to automatically create and manage its own folders for your Workspaces and projects. The application will only access files within the folders it is authorised to manage.</p>' +
						'<ul class="drive-points"><li>A visible <b>NeonDigital Workspace</b> folder is created in your Drive, with a folder for this Workspace and one for each project.</li><li>Access is limited to files Neon Workspace creates (Google\'s <code>drive.file</code> permission), not your whole Drive.</li><li>Disconnecting never deletes anything from your Drive.</li></ul>' + cfgMsg +
						(d.can_manage ? '<button class="btn btn-primary btn-lg" data-con' + (d.configured ? '' : ' disabled') + '>' + A.I.drive + ' Connect Google Drive</button>' : '<p class="muted">Ask the Workspace owner or an admin to connect Google Drive.</p>') +
						'<p class="muted small">Details are in the <a href="' + esc(window.NW.home) + 'privacy-policy/" target="_blank" rel="noopener">Privacy Policy</a>.</p></div></section>';
					var c = el.querySelector('[data-con]');
					if (c) c.onclick = function () { c.disabled = true; api('drive/connect', { method: 'POST', body: {} }).then(function (r) { location.href = r.url; }).catch(function (x) { c.disabled = false; A.fail(x); }); };
					return;
				}
				return browse(d);
			});
		};
		var browse = function (d, q) {
			return api('drive/files', { params: { parent: parent, sync: q ? '' : 1, q: q || '' } }).then(function (r) {
				var w = A.can('tasks.write');
				el.innerHTML = (flash ? '<div class="alert">' + esc(flash) + '</div>' : '') + '<section class="card drive-status"><div class="drive-ico ok">' + A.I.drive + '</div><div class="grow"><div><b>Google Drive</b> ' + A.badge('active', 'Connected') + '</div><div class="muted small">' + esc(d.account || 'Connected account') + ' · ' + (+d.file_count) + ' files · ' + (+d.linked) + ' linked to records' + (d.storage ? ' · ' + size(d.storage.used) + (d.storage.limit ? ' of ' + size(d.storage.limit) + ' used' : ' used') : '') + '</div>' + (d.last_error ? '<div class="form-error small">' + esc(d.last_error) + '</div>' : '') + '</div>' +
					'<div class="drive-acts">' + (d.root_link ? '<a class="btn btn-sm" href="' + A.safeUrl(d.root_link) + '" target="_blank" rel="noopener">Open ' + esc(d.root_name || 'NeonDigital Workspace') + ' folder</a>' : '') + (d.workspace_link ? '<a class="btn btn-sm" href="' + A.safeUrl(d.workspace_link) + '" target="_blank" rel="noopener">Open this Workspace\'s folder</a>' : '') + (w ? '<button class="btn btn-sm" data-sync>Update project folders</button>' : '') + (d.can_manage ? '<button class="btn btn-sm" data-recon>Reconnect</button><button class="btn btn-sm btn-danger" data-dis>Disconnect</button>' : '') + '</div></section>' +
					(d.folders && d.folders.length ? '<section class="card card-pad"><h2>Project folders</h2><div class="chips">' + d.folders.map(function (f) { return '<a class="chip" href="' + A.safeUrl(f.web_link || ('https://drive.google.com/drive/folders/' + f.drive_id)) + '" target="_blank" rel="noopener">' + A.I.folder + ' ' + esc(f.name) + '</a>'; }).join('') + '</div></section>' : '') +
					'<div class="toolbar" style="margin-top:14px">' + (trail.length ? '<button class="btn btn-sm" data-back>Back</button>' : '') + '<input data-q placeholder="Search files" value="' + esc(q || '') + '" aria-label="Search files"><span class="grow"></span>' + (w ? '<button class="btn btn-sm" data-mk>New folder</button><label class="btn btn-primary btn-sm">Upload<input type="file" data-up hidden></label>' : '') + '</div>' +
					'<section class="card card-pad">' + (r.items.length ? r.items.map(function (f) { return '<div class="row"><div class="grow">' + (f.is_folder ? '<button class="link" data-open="' + esc(f.parent_id) + '" data-fid="' + f.id + '"><b>' + esc(f.name) + '</b></button>' : '<b>' + esc(f.name) + '</b>') + '<div class="muted small">' + (f.is_folder ? 'Folder' : size(f.size)) + (f.entity ? ' · linked to ' + esc(f.entity) : '') + (f.shared ? ' · shared' : '') + '</div></div>' + (f.is_folder ? '' : '<button class="btn btn-sm" data-dl="' + f.id + '" data-n="' + esc(f.name) + '">Download</button>') + (w ? '<button class="btn btn-sm" data-more="' + f.id + '">More</button>' : '') + '</div>'; }).join('') : A.empty('Nothing here yet', 'Upload a file to put it in your Google Drive.')) + '</section><p class="muted small">Removing a file here only removes it from Workspace. It stays in your Google Drive.</p>';
				var again = function () { return browse(d); };
				var dis = el.querySelector('[data-dis]');
				if (dis) dis.onclick = function () { A.confirm('Disconnect Google Drive?', 'Neon Workspace stops using this Google account. Nothing is deleted: your folders and files stay in Google Drive, and reconnecting later uses the same folders.', 'Disconnect', true).then(function (y) { if (y) api('drive', { method: 'DELETE' }).then(load).catch(A.fail); }); };
				var rc = el.querySelector('[data-recon]');
				if (rc) rc.onclick = function () { rc.disabled = true; api('drive/connect', { method: 'POST', body: {} }).then(function (x) { location.href = x.url; }).catch(function (x) { rc.disabled = false; A.fail(x); }); };
				var sy = el.querySelector('[data-sync]');
				if (sy) sy.onclick = function () { sy.disabled = true; api('drive/folders/sync', { method: 'POST', body: {} }).then(function (x) { A.toast('Folders are up to date (' + (+x.projects) + ' project folders)'); return load(); }).catch(function (x) { sy.disabled = false; A.fail(x); }); };
				var bk = el.querySelector('[data-back]');
				if (bk) bk.onclick = function () { parent = trail.pop() || ''; again(); };
				var qi = el.querySelector('[data-q]');
				qi.onchange = function () { browse(d, qi.value); };
				var mk = el.querySelector('[data-mk]');
				if (mk) mk.onclick = function () { A.formModal({ title: 'New folder', fields: [{ k: 'name', label: 'Name', required: true, span: true }], onSave: function (v) { v.parent = parent; return api('drive/folders', { method: 'POST', body: v }).then(again); } }); };
				var up = el.querySelector('[data-up]');
				if (up) up.onchange = function () {
					var fd = new FormData();
					fd.append('file', up.files[0]);
					A.toast('Uploading…');
					fetch(window.NW.root + 'drive/files?parent=' + encodeURIComponent(parent), { method: 'POST', headers: { 'X-WP-Nonce': window.NW.nonce, 'X-NW-Workspace': S.workspaceId }, credentials: 'same-origin', body: fd }).then(function (x) { return x.json().then(function (j) { if (!x.ok) throw new Error(j.message); return j; }); }).then(function () { A.toast('Uploaded'); again(); }).catch(A.fail);
				};
				el.querySelectorAll('[data-dl]').forEach(function (b) { b.onclick = function () { A.download('drive/files/' + b.getAttribute('data-dl') + '/download', b.getAttribute('data-n')); }; });
				el.querySelectorAll('[data-fid]').forEach(function (b) {
					b.onclick = function () {
						var f = r.items.filter(function (i) { return +i.id === +b.getAttribute('data-fid'); })[0];
						trail.push(parent);
						parent = f.drive_id || f.raw_id || '';
						if (!parent) { parent = trail.pop(); A.toast('Could not open this folder.', true); return; }
						again();
					};
				});
				el.querySelectorAll('[data-more]').forEach(function (b) {
					b.onclick = function () {
						var f = r.items.filter(function (i) { return +i.id === +b.getAttribute('data-more'); })[0];
						A.formModal({
							title: f.name, ok: 'Save', values: { name: f.name, entity: f.entity, entity_id: f.entity_id || '' },
							fields: [{ k: 'name', label: 'Name', span: true }, { k: 'entity', label: 'Link to', type: 'select', opts: A.options([['project', 'Project'], ['task', 'Task'], ['booking', 'Booking'], ['event', 'Calendar event'], ['customer', 'Customer']], f.entity, 'Nothing') }, { k: 'entity_id', label: 'Record number', type: 'number', min: 1 }],
							extra: f.is_folder ? '' : '<p><button class="btn btn-sm" type="button" data-share>Create customer link</button></p>',
							ready: function (m) {
								var s = m.el.querySelector('[data-share]');
								if (s) s.onclick = function () { A.ask('Share with a customer', 'Customer number (optional, shows it in their portal)').then(function (c) { if (c === null) return; api('drive/files/' + f.id + '/share', { method: 'POST', body: { customer_id: +c || 0 } }).then(function (x) { copy(x.url, 'Link copied'); }).catch(A.fail); }); };
							},
							onSave: function (v) { return api('drive/files/' + f.id, { method: 'PUT', body: v }).then(again); },
							del: function () { return api('drive/files/' + f.id, { method: 'DELETE' }).then(again); }
						});
					};
				});
			});
		};
		return load();
	};

	/* ---- Reports ---- */
	V.reports = function (el) {
		A.setTitle('Reports');
		if (!A.gate(el, 'reports')) return;
		var to = A.localDay(), from = to.slice(0, 7) + '-01';
		var go = function () {
			return api('reports', { params: { from: from, to: to } }).then(function (d) {
				var block = function (t, rows, k) { return '<section class="card card-pad"><h2>' + t + '</h2>' + (rows && rows.length ? rows.map(function (x) { return '<div class="row"><div class="grow" style="text-transform:capitalize">' + esc(String(x[k]).replace(/_/g, ' ')) + '</div><b>' + (+x.n) + '</b></div>'; }).join('') : '<p class="muted">None in this period.</p>') + '</section>'; };
				el.innerHTML = '<div class="toolbar"><input type="date" data-from value="' + from + '" aria-label="From"><input type="date" data-to value="' + to + '" aria-label="To"></div>' + (d.invoiced !== undefined ? '<section class="card card-pad"><div class="stats"><div class="stat"><b>' + A.inr(d.invoiced) + '</b><span class="muted small">Invoiced</span></div><div class="stat"><b>' + A.inr(d.received) + '</b><span class="muted small">Received</span></div></div></section>' : '') + '<div class="grid" style="margin-top:14px">' + (d.bookings ? block('Bookings', d.bookings, 'status') : '') + (d.leads ? block('New leads', d.leads, 'stage') : '') + (d.tasks ? block('New tasks', d.tasks, 'status') : '') + '</div>';
				el.querySelector('[data-from]').onchange = function (x) { from = x.target.value; go(); };
				el.querySelector('[data-to]').onchange = function (x) { to = x.target.value; go(); };
			});
		};
		return go();
	};

	/* ---- Activity feed ---- */
	V.activity = function (el) {
		A.setTitle('Activity');
		var st = { page: 1, q: '', action: '', actor: '', from: '', to: '', entity: A.query.entity || '', entity_id: A.query.entity_id || '' };
		var go = function () {
			return api('activity', { params: st }).then(function (r) {
				var rows = A.collapseActivity(r.items);
				el.innerHTML = (st.entity ? '<div class="alert">Showing one record only. <a href="#/activity">Show all activity</a></div>' : '') +
					'<section class="card card-pad filters"><div class="filters-grid"><input type="search" data-k="q" placeholder="Search activity" value="' + esc(st.q) + '" aria-label="Search activity"><select data-k="action" aria-label="Filter by action">' + A.options(r.actions.map(function (a) { return [a, A.activityLabel({ action: a })]; }), st.action, 'All actions') + '</select><select data-k="actor" aria-label="Filter by person">' + A.memberOptions(st.actor, 'Everyone') + '</select><input type="date" data-k="from" value="' + esc(st.from) + '" aria-label="From date"><input type="date" data-k="to" value="' + esc(st.to) + '" aria-label="To date">' + (r.can_export ? '<button class="btn btn-sm" data-x>Export</button>' : '') + '</div></section>' +
					'<section class="card card-pad act-list"><p class="muted small act-count">' + (+r.total) + ' record' + (+r.total === 1 ? '' : 's') + (r.pages > 1 ? ', page ' + r.page + ' of ' + r.pages : '') + '</p>' + (rows.length ? rows.map(function (a) { return A.activityRow(a, esc(a.actor) + describe(a.details)); }).join('') : A.empty('Nothing here', 'Try different filters.')) + '<div data-pg></div></section>' +
					(A.can('audit.read') ? '<p class="muted small">Sign-in, billing and administration records are kept separately in <a href="#/settings">Settings</a>.</p>' : '');
				el.querySelector('[data-pg]').appendChild(A.pager(r, function (p) { st.page = p; go(); }));
				el.querySelectorAll('[data-k]').forEach(function (i) { i.onchange = function () { st[i.getAttribute('data-k')] = i.value; st.page = 1; go(); }; });
				var x = el.querySelector('[data-x]');
				if (x) x.onclick = function () { api('activity/export', { params: st }).then(function (d) { saveText(d.filename, d.csv); }).catch(A.fail); };
			});
		};
		return go();
	};
	function describe(d) {
		if (!d || typeof d !== 'object') return '';
		var parts = [];
		Object.keys(d).slice(0, 4).forEach(function (k) {
			var v = d[k];
			if (v && typeof v === 'object' && 'to' in v) parts.push(k.replace(/_/g, ' ') + ': ' + v.from + ' → ' + v.to);
			else if (v !== null && typeof v !== 'object') parts.push(k.replace(/_/g, ' ') + ': ' + v);
		});
		return parts.length ? ' · ' + esc(parts.join(', ')) : '';
	}

	/* ---- Settings additions ---- */
	A.settingsExtras = function (el) {
		var sec = el.querySelector('[data-sec]');
		if (sec) Promise.all([api('me/identities'), api('me/logins')]).then(function (rs) {
			var r = rs[0], linked = {};
			r.items.forEach(function (i) { linked[i.provider] = i; });
			var err = A.query.auth_error ? { linked_other: 'That login is already linked to a different account.', already_linked: 'Your account already has a login linked with this provider.', state: 'That attempt expired. Please try again.', denied: 'Linking was cancelled.', exchange: 'We could not confirm your identity with the provider.', unverified: 'Your email address is not verified with the provider.' }[A.query.auth_error] : '';
			var providers = ['google', 'facebook'].filter(function (p) { return linked[p] || r.available.indexOf(p) > -1; });
			sec.innerHTML = (err ? '<div class="alert alert-bad">' + esc(err) + '</div>' : '') + providers.map(function (p) { var l = linked[p]; return '<div class="row"><div class="grow"><b style="text-transform:capitalize">' + p + '</b><div class="muted small">' + (l ? 'Linked' + (l.email ? ': ' + esc(l.email) : '') : 'Not linked') + '</div></div>' + (l ? '<button class="btn btn-sm" data-un="' + p + '">Unlink</button>' : '<a class="btn btn-sm" href="' + esc(window.NW.authBase + p + '/start/?intent=link') + '">Link</a>') + '</div>'; }).join('') + '<div class="row"><div class="grow"><b>Password</b><div class="muted small">' + (r.has_password ? 'Set' : 'Not set. You sign in with a linked login.') + '</div></div><button class="btn btn-sm" data-pw>' + (r.has_password ? 'Change' : 'Set password') + '</button></div><details style="margin-top:10px"><summary>Recent sign-ins</summary>' + rs[1].items.map(function (x) { return '<div class="row"><div class="grow" style="text-transform:capitalize">' + esc(x.method) + '</div><span class="muted small">' + esc(A.fmtDT(x.created_at)) + '</span></div>'; }).join('') + '</details>';
			sec.querySelectorAll('[data-un]').forEach(function (b) { b.onclick = function () { api('me/identities/' + b.getAttribute('data-un'), { method: 'DELETE' }).then(function () { A.toast('Unlinked'); A.settingsExtras(el); }).catch(A.fail); }; });
			sec.querySelector('[data-pw]').onclick = function () {
				A.formModal({ title: r.has_password ? 'Change password' : 'Set a password', ok: 'Save', fields: (r.has_password ? [{ k: 'current', label: 'Current password', type: 'password', span: true }] : []).concat([{ k: 'password', label: 'New password', type: 'password', span: true, hint: 'at least 6 characters, with an uppercase letter, a lowercase letter and a number' }]), onSave: function (v) { if (!/^(?=.*[a-z])(?=.*[A-Z])(?=.*[0-9]).{6,}$/.test(v.password || '')) return Promise.reject(new Error('Use at least 6 characters, with an uppercase letter, a lowercase letter and a number.')); return api('me/password', { method: 'PUT', body: v }).then(function () { A.toast('Saved'); A.settingsExtras(el); }); } });
			};
		}).catch(function (e) { sec.innerHTML = '<p class="muted">' + esc(e.message) + '</p>'; });
		var ret = el.querySelector('[data-ret]');
		if (ret) api('retention').then(function (r) {
			ret.innerHTML = '<p class="muted small">Optional. Removes finished bookings and event registrations after the time you choose. You are told first, can download a copy, and can cancel for ' + (+r.notice_days) + ' days. Invoices, payments, active bookings and your Google Drive files are never removed.</p><div class="form-grid">' + A.fieldHtml({ k: 'r_enabled', label: 'Turn on clean-up', type: 'check', span: true }, r.enabled) + A.fieldHtml({ k: 'r_days', label: 'Days after completion', type: 'number', min: 7 }, r.days) + A.fieldHtml({ k: 'r_email', label: 'Send the export link to', type: 'email', hint: 'empty means the owner' }, r.email) + '</div><button class="btn btn-sm btn-primary" data-rs>Save</button>' + (r.runs.length ? '<h3 style="margin-top:14px">History</h3>' + r.runs.map(function (x) { return '<div class="row"><div class="grow">' + (+x.bookings_count) + ' bookings, ' + (+x.registrations_count) + ' registrations ' + A.badge(x.status) + '<div class="muted small">' + (x.status === 'noticed' ? 'Scheduled for ' + esc(A.fmtDT(x.purge_after)) : esc(A.fmtDT(x.purged_at || x.cancelled_at || x.noticed_at))) + '</div></div>' + (x.status === 'noticed' ? '<button class="btn btn-sm" data-rc="' + x.id + '">Cancel</button>' : '') + '</div>'; }).join('') : '');
			var v = function (k) { var i = ret.querySelector('[name=' + k + ']'); return i.type === 'checkbox' ? i.checked : i.value; };
			ret.querySelector('[data-rs]').onclick = function () { api('retention', { method: 'PUT', body: { enabled: v('r_enabled'), days: +v('r_days'), email: v('r_email') } }).then(function () { A.toast('Saved'); A.settingsExtras(el); }).catch(A.fail); };
			ret.querySelectorAll('[data-rc]').forEach(function (b) { b.onclick = function () { api('retention/runs/' + b.getAttribute('data-rc') + '/cancel', { method: 'POST', body: {} }).then(function () { A.toast('Cancelled'); A.settingsExtras(el); }).catch(A.fail); }; });
		}).catch(function (e) { ret.innerHTML = '<p class="muted">' + esc(e.message) + '</p>'; });
	};

	A.route(/^\/search$/, 'search');
	A.route(/^\/planner$/, 'planner');
	A.route(/^\/meetings$/, 'meetings');
	A.route(/^\/meetings\/(\d+)$/, 'meeting');
	A.route(/^\/forms$/, 'forms');
	A.route(/^\/forms\/(new)$/, 'formEdit');
	A.route(/^\/forms\/(\d+)\/edit$/, 'formEdit');
	A.route(/^\/forms\/(\d+)$/, 'formShow');
	A.route(/^\/registrations$/, 'registrations');
	A.route(/^\/registrations\/(new)$/, 'registrationEdit');
	A.route(/^\/registrations\/(\d+)\/edit$/, 'registrationEdit');
	A.route(/^\/registrations\/(\d+)$/, 'registrationShow');
	A.route(/^\/finance$/, 'finance');
	A.route(/^\/files$/, 'files');
	A.route(/^\/reports$/, 'reports');
	A.route(/^\/activity$/, 'activity');
})();
