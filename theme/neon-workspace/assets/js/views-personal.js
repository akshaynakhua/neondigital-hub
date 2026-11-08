/* Neon Workspace: the Personal area, consolidated views, notifications from every Workspace, and the
 * conversation component. Every item names the Workspace it belongs to and opens there. */
(function () {
	'use strict';
	var A = window.App, S = A.S, V = A.views, I = A.I, esc = A.esc, api = A.api;

	var TYPE = { tasks: 'Tasks', events: 'Calendar', bookings: 'Bookings', projects: 'Projects' };
	var STATUS = {
		tasks: [['open', 'Open'], ['todo', 'To do'], ['in_progress', 'In progress'], ['review', 'In review'], ['done', 'Done']],
		bookings: [['confirmed', 'Confirmed'], ['pending', 'Pending'], ['completed', 'Completed'], ['cancelled', 'Cancelled'], ['no_show', 'No-show']],
		projects: [['active', 'Active'], ['completed', 'Completed'], ['archived', 'Archived']]
	};
	var wsTag = function (x) { return '<span class="ws-tag" title="' + esc(x.workspace_name) + '">' + esc(x.workspace_name) + '</span>'; };
	var when = function (iso, allDay) {
		if (!iso) return '';
		var d = A.toDate(iso);
		var day = d.toLocaleDateString('en-IN', { timeZone: A.tz(), weekday: 'short', day: 'numeric', month: 'short' });
		return allDay ? day : day + ', ' + d.toLocaleTimeString('en-IN', { timeZone: A.tz(), hour: 'numeric', minute: '2-digit' });
	};
	function openRows(root, items) {
		root.querySelectorAll('[data-open]').forEach(function (b) {
			b.onclick = function () { var x = items[+b.getAttribute('data-open')]; A.openIn(x.workspace_id, x.link); };
		});
	}
	function row(x, i, kind) {
		var right = '', sub = '';
		if (kind === 'tasks') { sub = (x.project_name ? esc(x.project_name) + ' · ' : '') + (x.due_date ? 'Due ' + esc(A.fmtD(x.due_date)) : 'No due date'); right = A.badge(x.status); }
		if (kind === 'events') { sub = esc(when(x.start, x.all_day)) + (x.parent_name ? ' · ' + esc(x.parent_name) + ' › ' + esc(x.project_name) : x.project_name ? ' · ' + esc(x.project_name) : ''); right = '<span class="badge">' + esc(x.event_type_label || x.source) + '</span>'; }
		if (kind === 'bookings') { sub = esc(when(x.start)); right = A.badge(x.status); }
		if (kind === 'projects') { sub = x.end_date ? 'Ends ' + esc(A.fmtD(x.end_date)) : 'No end date'; right = A.badge(x.status); }
		return '<button class="list-row" data-open="' + i + '"><span class="lr-main"><b>' + esc(x.title || x.name) + '</b><span class="lr-sub">' + sub + '</span></span>' + wsTag(x) + right + '</button>';
	}

	/* ---- Personal dashboard ---- */
	V.personal_dashboard = function (el) {
		A.setTitle('My Dashboard');
		el.innerHTML = '<section class="card card-pad">' + A.loading(4) + '</section>';
		return api('personal/overview').then(function (r) {
			var cards = r.workspaces.filter(function (w) { return w.account_type !== 'personal'; });
			var me = r.workspaces.filter(function (w) { return w.account_type === 'personal'; })[0] || {};
			var sum = function (k) { return r.workspaces.reduce(function (a, w) { return a + (+w[k] || 0); }, 0); };
			var stat = function (n, l, h) { return '<a class="kpi" href="' + h + '"><b>' + n + '</b><span>' + esc(l) + '</span></a>'; };
			var block = function (kind, title, items, emptyT, emptyB) {
				var list = items.slice(0, 6);
				return '<section class="card"><div class="card-h"><h2>' + esc(title) + '</h2><a class="link small" href="#/overview?type=' + kind + '">See all</a></div><div class="card-b list" data-k="' + kind + '">' + (list.length ? list.map(function (x, i) { return row(x, i, kind); }).join('') : A.empty(emptyT, emptyB)) + '</div></section>';
			};
			el.innerHTML = '<div class="kpis">' + stat(sum('open_tasks'), 'Open tasks assigned to me', '#/overview?type=tasks') + stat(r.counts.events, 'Calendar items, next 14 days', '#/overview?type=events') + stat(sum('bookings_today'), 'Bookings today', '#/overview?type=bookings') + stat(sum('unread'), 'Unread notifications', '#/notifications') + '</div>' +
				'<section class="card"><div class="card-h"><h2>My Business Workspaces</h2><button class="btn btn-sm" data-addws>' + I.plus + ' Add Workspace</button></div><div class="card-b">' +
				(cards.length ? '<div class="ws-cards">' + cards.map(function (w) {
					var st = w.subscription && w.subscription.state && w.subscription.state !== 'active' ? A.badge(w.subscription.state) : '';
					var full = S.boot.workspaces.filter(function (x) { return +x.id === +w.id; })[0] || w;
				return '<button class="ws-card" data-sw="' + w.id + '">' + A.wsAvatar(full, false) + '<span class="ws-card-t"><b>' + esc(w.name) + '</b><small>' + esc((w.account_type === 'agency' ? 'Managed by NeonDigital · ' : 'Your subscription · ') + w.role_label) + '</small></span>' + st +
						'<span class="ws-card-n"><span><b>' + w.open_tasks + '</b> open tasks</span><span><b>' + w.bookings_today + '</b> bookings today</span><span><b>' + w.unread + '</b> unread</span></span><span class="ws-card-go">Open ' + esc(w.name) + ' →</span></button>';
				}).join('') + '</div>' : A.empty('No Workspaces yet', 'Add a Workspace for your business, or open the invitation link NeonDigital sent you.')) + '</div></section>' +
				'<div class="grid">' + block('tasks', 'My tasks', r.tasks, 'Nothing assigned to you', 'Tasks assigned to you in any Workspace appear here.') + block('events', 'Coming up', r.events, 'Your next two weeks are clear', '') + block('bookings', 'Bookings', r.bookings, 'No bookings in the next two weeks', '') + block('projects', 'Projects', r.projects, 'No projects yet', '') + '</div>' +
				(me.unread ? '' : '');
			el.querySelectorAll('[data-sw]').forEach(function (b) { b.onclick = function () { A.switchTo(+b.getAttribute('data-sw')); }; });
			var adw = el.querySelector('[data-addws]');
			if (adw) adw.onclick = A.addWorkspace;
			['tasks', 'events', 'bookings', 'projects'].forEach(function (k) { var box = el.querySelector('[data-k="' + k + '"]'); if (box) openRows(box, r[k].slice(0, 6)); });
		});
	};

	/* ---- All my work: one filtered list across Personal and every Workspace ---- */
	V.overview = function (el) {
		A.setTitle('All my work');
		var q = A.query || {};
		var f = { type: TYPE[q.type] ? q.type : 'tasks', workspace: q.workspace || '', project: q.project || '', status: q.status || '', from: q.from || A.localDay(), to: q.to || A.addDays(A.localDay(), 13) };
		var ws = [{ id: S.boot.personal ? S.boot.personal.id : '', name: 'Personal' }].concat(S.boot.workspaces);
		el.innerHTML = '<section class="card"><div class="filters" role="search" aria-label="Filter">' +
			'<div class="seg" role="tablist">' + Object.keys(TYPE).map(function (k) { return '<button role="tab" data-type="' + k + '"' + (k === f.type ? ' class="on" aria-selected="true"' : '') + '>' + TYPE[k] + '</button>'; }).join('') + '</div>' +
			'<label class="field"><span>Workspace</span><select data-f="workspace"><option value="">All</option>' + ws.map(function (w) { return '<option value="' + w.id + '"' + (String(w.id) === String(f.workspace) ? ' selected' : '') + '>' + esc(w.name) + '</option>'; }).join('') + '</select></label>' +
			'<label class="field" data-pf><span>Project</span><select data-f="project"><option value="">All projects</option></select></label>' +
			'<label class="field" data-sf><span>Status</span><select data-f="status"></select></label>' +
			'<label class="field" data-df><span>From</span><input type="date" data-f="from" value="' + esc(f.from) + '"></label><label class="field" data-df><span>To</span><input type="date" data-f="to" value="' + esc(f.to) + '"></label>' +
			'<button class="btn btn-sm btn-ghost" data-reset>Clear filters</button></div><div class="card-b list" data-list>' + A.loading(5) + '</div></section>';
		var list = el.querySelector('[data-list]');
		var g = function (k) { return el.querySelector('[data-f="' + k + '"]'); };
		function paintControls() {
			var st = STATUS[f.type];
			el.querySelector('[data-sf]').hidden = !st;
			if (st) g('status').innerHTML = '<option value="">Any</option>' + st.map(function (s) { return '<option value="' + s[0] + '"' + (s[0] === f.status ? ' selected' : '') + '>' + s[1] + '</option>'; }).join('');
			el.querySelectorAll('[data-df]').forEach(function (x) { x.hidden = f.type === 'tasks' || f.type === 'projects'; });
			el.querySelector('[data-pf]').hidden = f.type === 'bookings';
		}
		function load() {
			var p = { type: f.type, workspace: f.workspace, project: f.project, status: f.status, from: f.from, to: f.to };
			history.replaceState(null, '', '#/overview?' + Object.keys(p).filter(function (k) { return p[k]; }).map(function (k) { return k + '=' + encodeURIComponent(p[k]); }).join('&'));
			list.innerHTML = A.loading(4);
			return api('personal/overview', { params: p }).then(function (r) {
				if (!list.isConnected) return;
				var items = r[f.type] || [];
				var pj = g('project'), had = pj.value;
				var opts = {};
				(r.projects || []).concat(r.tasks || []).forEach(function (x) { var id = x.project_id || (f.type === 'projects' ? x.id : null); if (id) opts[x.workspace_id + ':' + id] = { id: id, ws: x.workspace_id, name: (x.project_name || x.name) + ' · ' + x.workspace_name }; });
				if (f.workspace) pj.innerHTML = '<option value="">All projects</option>' + Object.keys(opts).filter(function (k) { return String(opts[k].ws) === String(f.workspace); }).map(function (k) { return '<option value="' + opts[k].id + '"' + (String(opts[k].id) === String(had || f.project) ? ' selected' : '') + '>' + esc(opts[k].name) + '</option>'; }).join('');
				else pj.innerHTML = '<option value="">Choose a Workspace first</option>';
				pj.disabled = !f.workspace;
				list.innerHTML = items.length ? '<p class="muted small list-count">' + items.length + ' ' + TYPE[f.type].toLowerCase() + (r.workspaces.length > 1 && !f.workspace ? ' across ' + r.workspaces.length + ' places' : '') + '</p>' + items.map(function (x, i) { return row(x, i, f.type); }).join('') : A.empty('Nothing matches', 'Change or clear the filters.');
				openRows(list, items);
			}).catch(function (e) { list.innerHTML = '<p class="form-error" role="alert">' + esc(e.message) + '</p>'; });
		}
		el.querySelectorAll('[data-type]').forEach(function (b) { b.onclick = function () { f.type = b.getAttribute('data-type'); f.status = ''; el.querySelectorAll('[data-type]').forEach(function (x) { x.classList.toggle('on', x === b); x.setAttribute('aria-selected', String(x === b)); }); paintControls(); load(); }; });
		['workspace', 'project', 'status', 'from', 'to'].forEach(function (k) { g(k).onchange = function () { f[k] = g(k).value; if (k === 'workspace') f.project = ''; load(); }; });
		el.querySelector('[data-reset]').onclick = function () { f.workspace = ''; f.project = ''; f.status = ''; f.from = A.localDay(); f.to = A.addDays(f.from, 13); g('workspace').value = ''; g('from').value = f.from; g('to').value = f.to; paintControls(); load(); };
		paintControls();
		return load();
	};
	A.route(/^\/overview$/, 'overview');

	/* ---- Phase 5: the consolidated Personal calendar ----
	 * Everything the person may see, from Personal and from every Workspace: NeonDigital project and
	 * sub-project items, their own Workspaces' calendars and their private Personal events. Each item
	 * says where it came from and opens the right project or sub-project there. Private Personal
	 * events stay in Personal: no Workspace or NeonDigital ever sees them. */
	var baseCalendar = V.calendar;
	V.personal_calendar = function (el, m) {
		A.setTitle('My Calendar');
		var q = A.query || {};
		if (q.mode === 'mine' && baseCalendar) {
			el.innerHTML = '<div class="tabs"><a href="#/calendar">All calendars</a><a class="on" href="#/calendar?mode=mine">My private calendar</a></div><div data-own></div>';
			return baseCalendar(el.querySelector('[data-own]'), m);
		}
		var f = { workspace: q.workspace || '', proj: q.proj || '', event_type: q.event_type || '', scope: q.scope || '', q: q.q || '', sort: q.sort === 'desc' ? 'desc' : 'asc', from: q.from || A.localDay(), to: q.to || A.addDays(A.localDay(), 30) };
		var ws = [{ id: S.boot.personal ? S.boot.personal.id : '', name: 'Personal' }].concat(S.boot.workspaces);
		el.innerHTML = '<div class="tabs"><a class="on" href="#/calendar">All calendars</a><a href="#/calendar?mode=mine">My private calendar</a></div>' +
			'<section class="card"><div class="filters cal-filters" role="search" aria-label="Filter the calendar">' +
			'<label class="field"><span>Search</span><input type="search" data-f="q" placeholder="Title, project or Workspace" value="' + esc(f.q) + '"></label>' +
			'<label class="field"><span>Show</span><select data-f="scope"><option value="">Personal and Business</option><option value="personal"' + (f.scope === 'personal' ? ' selected' : '') + '>Personal only</option><option value="business"' + (f.scope === 'business' ? ' selected' : '') + '>Business only</option></select></label>' +
			'<label class="field"><span>Workspace</span><select data-f="workspace"><option value="">All</option>' + ws.map(function (w) { return '<option value="' + w.id + '"' + (String(w.id) === String(f.workspace) ? ' selected' : '') + '>' + esc(w.name) + '</option>'; }).join('') + '</select></label>' +
			'<label class="field"><span>Project or sub-project</span><select data-f="proj"><option value="">All projects</option></select></label>' +
			'<label class="field"><span>Type</span><select data-f="event_type"><option value="">All types</option></select></label>' +
			'<label class="field"><span>From</span><input type="date" data-f="from" value="' + esc(f.from) + '"></label><label class="field"><span>To</span><input type="date" data-f="to" value="' + esc(f.to) + '"></label>' +
			'<label class="field"><span>Order</span><select data-f="sort"><option value="asc">Soonest first</option><option value="desc"' + (f.sort === 'desc' ? ' selected' : '') + '>Latest first</option></select></label>' +
			'<button class="btn btn-sm btn-ghost" data-reset>Clear filters</button></div><div class="card-b" data-list>' + A.loading(5) + '</div></section>';
		var list = el.querySelector('[data-list]'), g = function (k) { return el.querySelector('[data-f="' + k + '"]'); }, facets = { projects: [], event_types: {} };
		function paintFacets() {
			var pj = g('proj'), ty = g('event_type');
			var mains = facets.projects.filter(function (p) { return !p.parent_key; }), subs = facets.projects.filter(function (p) { return p.parent_key; });
			var opt = function (p, label) { return '<option value="' + esc(p.key) + '"' + (p.key === f.proj ? ' selected' : '') + '>' + esc(label) + '</option>'; };
			pj.innerHTML = '<option value="">All projects</option>' + mains.map(function (p) { return opt(p, p.name + ' · ' + p.workspace_name) + subs.filter(function (s) { return s.parent_key === p.key; }).map(function (s) { return opt(s, '\u2003› ' + s.name); }).join(''); }).join('') + subs.filter(function (s) { return !mains.some(function (p) { return p.key === s.parent_key; }); }).map(function (s) { return opt(s, (s.parent_name ? s.parent_name + ' › ' : '') + s.name); }).join('');
			ty.innerHTML = '<option value="">All types</option>' + Object.keys(facets.event_types).map(function (k) { return '<option value="' + esc(k) + '"' + (k === f.event_type ? ' selected' : '') + '>' + esc(facets.event_types[k]) + '</option>'; }).join('');
		}
		function load() {
			var p = { type: 'events', workspace: f.workspace, proj: f.proj, event_type: f.event_type, scope: f.scope, q: f.q, sort: f.sort, from: f.from, to: f.to };
			history.replaceState(null, '', '#/calendar?' + Object.keys(p).filter(function (k) { return p[k] && k !== 'type'; }).map(function (k) { return k + '=' + encodeURIComponent(p[k]); }).join('&'));
			list.innerHTML = A.loading(4);
			return api('personal/overview', { params: p }).then(function (r) {
				if (!list.isConnected) return;
				if (!f.proj && !f.event_type) facets = r.facets || facets;
				paintFacets();
				var items = r.events || [], days = {}, order = [];
				items.forEach(function (x, i) { var k = String(x.start).slice(0, 10); if (!days[k]) { days[k] = []; order.push(k); } days[k].push(i); });
				list.innerHTML = items.length ? '<p class="muted small list-count">' + items.length + ' item' + (items.length === 1 ? '' : 's') + '</p>' + order.map(function (k) {
					return '<div class="cal-day"><h3>' + esc(A.fmtD(k)) + '</h3>' + days[k].map(function (i) {
						var x = items[i];
						var origin = '<span class="ws-tag">' + esc(x.workspace_name) + '</span>' + (x.parent_name ? '<span class="cal-path">' + esc(x.parent_name) + ' › <b>' + esc(x.project_name) + '</b></span>' : x.project_name ? '<span class="cal-path"><b>' + esc(x.project_name) + '</b></span>' : '');
						return '<button class="list-row cal-item' + (x.scope === 'personal' ? ' is-personal' : '') + '" data-open="' + i + '"><span class="cal-time">' + (x.all_day ? 'All day' : esc(A.fmtT(x.start))) + '</span><span class="lr-main"><b>' + esc(x.title) + '</b><span class="lr-sub cal-origin">' + origin + '</span></span><span class="badge' + (x.scope === 'personal' ? ' badge-violet' : '') + '">' + esc(x.event_type_label) + '</span></button>';
					}).join('') + '</div>';
				}).join('') : A.empty('Nothing in this period', 'Change the dates or clear the filters.');
				openRows(list, items);
			}).catch(function (e) { list.innerHTML = '<p class="form-error" role="alert">' + esc(e.message) + '</p>'; });
		}
		var deb;
		['workspace', 'proj', 'event_type', 'scope', 'sort', 'from', 'to'].forEach(function (k) { g(k).onchange = function () { f[k] = g(k).value; if (k === 'workspace' || k === 'scope') { f.proj = ''; facets = { projects: [], event_types: {} }; } load(); }; });
		g('q').oninput = function () { clearTimeout(deb); deb = setTimeout(function () { f.q = g('q').value.trim(); load(); }, 300); };
		el.querySelector('[data-reset]').onclick = function () { f = { workspace: '', proj: '', event_type: '', scope: '', q: '', sort: 'asc', from: A.localDay(), to: A.addDays(A.localDay(), 30) }; ['workspace', 'proj', 'event_type', 'scope', 'q'].forEach(function (k) { g(k).value = ''; }); g('sort').value = 'asc'; g('from').value = f.from; g('to').value = f.to; facets = { projects: [], event_types: {} }; load(); };
		return load();
	};

	/* ---- Notifications from every Workspace ---- */
	V.notifications = function (el) {
		A.setTitle('Notifications');
		var f = { workspace_id: '', unread: '' };
		var ws = [{ id: S.boot.personal ? S.boot.personal.id : '', name: 'Personal' }].concat(S.boot.workspaces);
		el.innerHTML = '<section class="card"><div class="filters"><label class="field"><span>From</span><select data-w><option value="">Personal and all Workspaces</option>' + ws.map(function (w) { return '<option value="' + w.id + '">' + esc(w.name) + '</option>'; }).join('') + '</select></label><label class="check"><input type="checkbox" data-u> <span>Unread only</span></label><span class="grow"></span><button class="btn btn-sm" data-all>Mark all read</button></div><div class="card-b note-page" data-list>' + A.loading(5) + '</div></section>' +
			'<p class="muted small center">Choose what each Workspace sends you in <a href="#/settings">Settings → Notifications</a>.</p>';
		var list = el.querySelector('[data-list]');
		function load() {
			return api('me/notifications', { params: f }).then(function (r) {
				A.setUnread(r.unread);
				list.innerHTML = r.items.length ? r.items.map(function (n) { return A.noteRow(n, true); }).join('') : A.empty(f.unread ? 'No unread notifications' : 'No notifications yet', 'Notifications from Personal and all your Workspaces appear here, whichever one is open.');
				list.querySelectorAll('[data-note]').forEach(function (b) { b.onclick = function () { A.openNotification(r.items.filter(function (n) { return +n.id === +b.getAttribute('data-note'); })[0]); }; });
			}).catch(function (e) { list.innerHTML = '<p class="form-error" role="alert">' + esc(e.message) + '</p>'; });
		}
		el.querySelector('[data-w]').onchange = function (e) { f.workspace_id = e.target.value; load(); };
		el.querySelector('[data-u]').onchange = function (e) { f.unread = e.target.checked ? 1 : ''; load(); };
		el.querySelector('[data-all]').onclick = function () { api('me/notifications/read', { method: 'POST', body: f.workspace_id ? { workspace_id: f.workspace_id } : {} }).then(load).catch(A.fail); };
		return load();
	};

	/* ---- Notification preferences: Personal and each Workspace keep their own ---- */
	A.notifyPrefsCard = function (box) {
		var ws = [{ id: S.boot.personal ? S.boot.personal.id : '', name: 'Personal' }].concat(S.boot.workspaces);
		var cur = S.boot.workspace.id;
		box.innerHTML = '<h2>Notifications</h2><p class="muted small">Choose what reaches you from each place. You receive notifications from every Workspace you belong to, whichever one is open.</p><label class="field"><span>Settings for</span><select data-pw>' + ws.map(function (w) { return '<option value="' + w.id + '"' + (+w.id === +cur ? ' selected' : '') + '>' + esc(w.name) + '</option>'; }).join('') + '</select></label><div data-pc>' + A.loading(2) + '</div>';
		var sel = box.querySelector('[data-pw]'), pc = box.querySelector('[data-pc]');
		function load() {
			return api('me/notification-preferences', { params: { workspace_id: sel.value } }).then(function (r) {
				pc.innerHTML = '<div class="toggles">' + r.categories.map(function (c) { return '<label class="switch-row"><span>' + esc(c.label) + '</span><input type="checkbox" role="switch" data-cat="' + c.key + '"' + (c.on ? ' checked' : '') + '></label>'; }).join('') + '</div>';
				pc.querySelectorAll('[data-cat]').forEach(function (i) {
					i.onchange = function () {
						var cats = {};
						pc.querySelectorAll('[data-cat]').forEach(function (x) { cats[x.getAttribute('data-cat')] = x.checked; });
						api('me/notification-preferences', { method: 'PUT', body: { workspace_id: +sel.value, categories: cats } }).then(function () { A.toast('Saved for ' + sel.options[sel.selectedIndex].text); }).catch(function (e) { i.checked = !i.checked; A.fail(e); });
					};
				});
			}).catch(function (e) { pc.innerHTML = '<p class="form-error">' + esc(e.message) + '</p>'; });
		}
		sel.onchange = load;
		return load();
	};

	/* ---- Conversation: bubbles, day dividers, a proper composer ---- */
	/**
	 * @param box  element to fill
	 * @param o    {messages:[{author_type,author_name,body,created_at,read,attachments}], canSend, closedText, onSend(text, files) → Promise, mineType, canAttach, rules}
	 */
	A.chat = function (box, o) {
		var mine = o.mineType || 'client';
		var keep = box.querySelector('[data-cin]') ? box.querySelector('[data-cin]').value : '';
		var dayKey = function (v) { return A.localDT(v).slice(0, 10); };
		var today = A.localDay(), yday = A.addDays(today, -1), last = '';
		var msgs = o.messages || [];
		var body = msgs.length ? msgs.map(function (m) {
			var me = m.author_type === mine, d = dayKey(m.created_at), out = '';
			if (d !== last) { last = d; out += '<div class="chat-day" role="separator"><span>' + (d === today ? 'Today' : d === yday ? 'Yesterday' : esc(A.fmtD(d))) + '</span></div>'; }
			return out + '<div class="chat-row' + (me ? ' me' : '') + '"><div class="chat-bubble"><div class="chat-who">' + esc(m.author_name || (me ? 'You' : 'NeonDigital')) + '</div><div class="chat-text">' + esc(m.body) + '</div>' + A.attachList(m.attachments) + '<div class="chat-meta"><time datetime="' + esc(m.created_at) + '" title="' + esc(A.fmtDT(m.created_at)) + '">' + esc(A.fmtT(m.created_at)) + '</time>' + (me && m.read ? ' · Seen' : '') + '</div></div></div>';
		}).join('') : '<div class="chat-empty"><b>No messages yet</b><span>' + esc(o.canSend ? 'Write the first message below.' : 'Messages appear here.') + '</span></div>';
		box.innerHTML = '<div class="chat"><div class="chat-scroll" data-cs aria-live="polite">' + body + '</div>' +
			(o.canSend ? '<form class="chat-compose" data-cf><textarea data-cin rows="1" placeholder="Write a message" aria-label="Write a message"></textarea><button class="btn btn-primary" data-csend aria-label="Send">Send</button></form>' + (o.canAttach ? '<div class="chat-attach" data-cat></div>' : '') + '<p class="muted small chat-hint">Enter to send · Shift + Enter for a new line' + (o.canAttach ? ' · Files up to 5 MB in total' : '') + '</p>' : '<p class="muted small chat-closed">' + esc(o.closedText || 'Chat opens once a NeonDigital team member starts it. You will see it here without refreshing.') + '</p>') + '</div>';
		var sc = box.querySelector('[data-cs]');
		sc.scrollTop = sc.scrollHeight;
		var f = box.querySelector('[data-cf]');
		if (!f) return;
		var t = f.querySelector('[data-cin]'), btn = f.querySelector('[data-csend]');
		var pick = o.canAttach ? A.attachPicker(box.querySelector('[data-cat]'), o.rules) : null;
		t.value = keep;
		var grow = function () { t.style.height = 'auto'; t.style.height = Math.min(160, t.scrollHeight) + 'px'; };
		t.addEventListener('input', grow); grow();
		t.addEventListener('keydown', function (e) { if (e.key === 'Enter' && !e.shiftKey && !e.isComposing) { e.preventDefault(); f.requestSubmit ? f.requestSubmit() : btn.click(); } });
		f.addEventListener('submit', function (e) {
			e.preventDefault();
			var v = t.value, n = pick ? pick.count() : 0;
			if (!v.trim() && !n) { t.focus(); return; }
			btn.disabled = true; t.disabled = true;
			var files = pick && n ? pick.files() : Promise.resolve([]);
			files.then(function (list) {
				if (list.length) pick.status('uploading');
				t.value = ''; // Cleared first, so the live refresh after sending is not held back by a draft.
				return Promise.resolve(o.onSend(v.trim() ? v : 'Shared ' + list.length + ' file' + (list.length === 1 ? '' : 's'), list)).then(function () { if (pick) pick.clear(); });
			}).catch(function (x) { if (t.isConnected && !t.value) t.value = v; if (pick && n) pick.status('failed', x.message); A.fail(x); }).finally(function () { btn.disabled = false; t.disabled = false; });
		});
	};
})();
