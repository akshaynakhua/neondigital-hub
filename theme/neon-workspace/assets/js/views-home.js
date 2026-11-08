/* Dashboard, quick add and the calendars. */
(function () {
	'use strict';
	var A = window.App, S = A.S, api = A.api, esc = A.esc, V = A.views;

	/* ---- Quick add ---- */
	A.quickAdd = function () {
		var items = [
			['Add Event', function () { A.eventForm({}); }, true],
			['Add Task', function () { A.taskForm({}); }, A.has('projects') && A.can('tasks.write')],
			['Create Project', function () { A.projectForm({}); }, A.has('projects') && A.can('projects.write')],
			['Add Booking', function () { A.bookingForm(); }, A.has('bookings') && A.can('bookings.write')],
			['Add Lead', function () { A.leadForm({}); }, A.has('crm') && A.can('crm.write')],
			['Add Customer', function () { A.customerForm({}); }, A.has('crm') && A.can('crm.write')],
			['Create Invoice', function () { A.go('/invoices/new'); }, A.has('invoice_management') && A.can('invoices.write')],
			['Submit Work Request', function () { A.requestForm(); }, !!S.boot.agency && A.has('agency_connect') && A.can('agency.use')],
			['Add Note', function () { A.noteForm({}); }, true]
		].filter(function (x) { return x[2]; });
		var m = A.modal({ title: 'New', body: '<div class="quick">' + items.map(function (x, i) { return '<button class="btn" data-q="' + i + '">' + esc(x[0]) + '</button>'; }).join('') + '</div>' });
		m.el.querySelectorAll('[data-q]').forEach(function (b) {
			b.onclick = function () { m.close(); items[+b.getAttribute('data-q')][1](); };
		});
	};

	/* ---- Dashboard ---- */
	var WIDGETS = [
		['today', "Today's overview"], ['personal', 'My Personal Calendar'], ['business', 'Business Calendar Preview'], ['upcoming', 'Upcoming Events'], ['tasks', 'Pending Tasks'],
		['projects', 'Active Projects'], ['bookings', 'Upcoming Bookings'], ['leads', 'New Leads'], ['approvals', 'Pending Approvals'], ['workload', 'Team Workload'],
		['agency', 'NeonDigital Connect'], ['notifications', 'Notifications'], ['goals', 'Goals'], ['revenue', 'Revenue and Invoice Overview'], ['access', 'Subscription and Feature Access']
	];
	var SECTIONS = [
		['overview', "Today's overview", ['today']],
		['daily', 'Daily work', ['personal', 'business', 'upcoming', 'tasks']],
		['biz', 'Business overview', ['projects', 'bookings', 'leads', 'approvals', 'workload']],
		['connect', 'NeonDigital Connect', ['agency']],
		['account', 'Account', ['notifications', 'goals', 'revenue', 'access']]
	];
	var ROWS = 4;
	function connectActive() {
		var a = S.boot.agency;
		return !!a && a.status !== 'suspended' && !!a.agency_connect && A.has('agency_connect') && A.can('agency.use');
	}
	/** Widgets this person could ever see. Customise lists only these. */
	function widgetAvailable(k) {
		switch (k) {
			case 'personal': return A.has('calendar_personal');
			case 'business': return A.has('calendar_business') && A.can('calendar.business');
			case 'tasks': case 'projects': return A.has('projects') && A.can('projects.read');
			case 'bookings': return A.has('bookings') && A.can('bookings.read');
			case 'leads': return A.has('crm') && A.can('crm.read');
			case 'approvals': case 'agency': return connectActive();
			case 'workload': return A.can('team.read') && A.can('projects.manage');
			case 'revenue': return A.has('invoice_management') && A.can('invoices.read');
			default: return true;
		}
	}
	function widgetOrder() {
		var p = S.boot.prefs || {}, have = WIDGETS.map(function (w) { return w[0]; });
		var ord = (p.widgets || []).filter(function (k) { return have.indexOf(k) > -1; });
		have.forEach(function (k) { if (ord.indexOf(k) < 0) ord.push(k); });
		return ord;
	}
	function widgetName(k) { return WIDGETS.filter(function (w) { return w[0] === k; })[0][1]; }
	function card(title, body, link, label, cls) {
		return '<section class="card dash-card ' + (cls || '') + '"><div class="card-h"><h3>' + esc(title) + '</h3>' + (link ? '<a class="card-link" href="' + esc(link) + '">' + esc(label || 'View all') + '</a>' : '') + '</div><div class="card-b">' + body + '</div></section>';
	}
	/** "1:37 am" today, otherwise "5 Nov, 1:37 am". The year would only push titles out of the row. */
	function shortWhen(v, allDay) {
		if (allDay) return shortDay(v);
		var d = A.toDate(v);
		if (A.localDay(d) === A.localDay()) return A.fmtT(v);
		return shortDay(A.localDay(d)) + ', ' + A.fmtT(v);
	}
	function shortDay(v) {
		return new Date(String(v).slice(0, 10) + 'T12:00:00Z').toLocaleDateString('en-IN', { timeZone: 'UTC', day: 'numeric', month: 'short' });
	}
	function none(text) { return '<p class="dash-none">' + esc(text) + '</p>'; }
	function evList(items, text) {
		if (!items.length) return none(text);
		return items.slice(0, ROWS).map(function (e) {
			return '<a class="row" href="#/calendar"><span class="cal-swatch" style="background:' + esc(e.color) + '"></span><div class="grow clip">' + esc(e.title) + '</div><span class="muted small nowrap">' + esc(shortWhen(e.start, e.all_day)) + '</span></a>';
		}).join('');
	}
	function kpi(icon, n, label, href) {
		return '<a class="kpi" href="' + esc(href) + '"><span class="kpi-i">' + A.I[icon] + '</span><span class="kpi-t"><b>' + (+n) + '</b><small>' + esc(label) + '</small></span></a>';
	}
	var builders = {
		today: function (d) {
			return '<div class="kpis">' + kpi('cal', d.todayItems.length, 'Calendar activities', '#/calendar') + (d.tasks ? kpi('task', d.tasks_due_today, 'Tasks due today', '#/tasks') : '') + (d.bookings ? kpi('book', d.bookings_count, 'Upcoming bookings', '#/bookings') : '') + (d.leads ? kpi('lead', d.leads_count, 'New leads', '#/crm/leads') : '') + '</div>';
		},
		personal: function (d) { return widgetAvailable('personal') ? card('My Personal Calendar', evList(d.upcoming.filter(function (e) { return e.calendar === 'personal'; }), 'Nothing coming up.'), '#/calendar') : ''; },
		business: function (d) { return widgetAvailable('business') ? card('Business Calendar Preview', evList(d.upcoming.filter(function (e) { return e.calendar === 'business' || e.calendar === 'bookings'; }), 'Nothing coming up.'), '#/calendar') : ''; },
		upcoming: function (d) { return card('Upcoming Events', evList(d.upcoming, 'Your next two weeks are clear.'), '#/calendar'); },
		tasks: function (d) {
			if (!d.tasks) return '';
			return card('Pending Tasks', d.tasks.length ? d.tasks.slice(0, ROWS).map(function (t) {
				var late = t.due_date && t.due_date < d.today;
				return '<a class="row" href="#/tasks"><div class="grow clip">' + esc(t.title) + '</div>' + (t.due_date ? '<span class="small nowrap ' + (late ? 'late' : 'muted') + '">' + esc(shortDay(t.due_date)) + '</span>' : '') + '</a>';
			}).join('') : none('Nothing pending.'), '#/tasks');
		},
		projects: function (d) {
			if (!d.projects) return '';
			return card('Active Projects', d.projects.length ? d.projects.slice(0, ROWS).map(function (p) {
				return '<a class="row" href="#/projects/' + p.id + '"><div class="grow"><div class="clip">' + esc(p.name) + '</div><div class="bar"><i style="width:' + (+p.progress) + '%"></i></div></div><span class="muted small nowrap">' + (+p.progress) + '%</span></a>';
			}).join('') : none('No active projects.'), '#/projects');
		},
		bookings: function (d) {
			if (!d.bookings) return '';
			return card('Upcoming Bookings', d.bookings.length ? d.bookings.slice(0, ROWS).map(function (b) { return '<a class="row" href="#/bookings"><div class="grow"><div class="clip">' + esc(b.customer_name) + '</div><div class="muted small clip">' + esc(b.service_name) + '</div></div><span class="muted small nowrap">' + esc(shortWhen(b.starts_at)) + '</span></a>'; }).join('') : none('No upcoming bookings.'), '#/bookings');
		},
		leads: function (d) {
			if (!d.leads) return '';
			return card('New Leads', d.leads.length ? d.leads.slice(0, ROWS).map(function (l) { return '<a class="row" href="#/crm/leads"><div class="grow"><div class="clip">' + esc(l.name) + '</div>' + (l.enquiry ? '<div class="muted small clip">' + esc(l.enquiry) + '</div>' : '') + '</div></a>'; }).join('') : none('No new leads.'), '#/crm/leads');
		},
		approvals: function (d) {
			if (!widgetAvailable('approvals') || !d.agency || d.agency.error) return '';
			var n = +d.agency.pending_approvals;
			return card('Pending Approvals', '<a class="big-num" href="#/agency/deliverables"><b>' + n + '</b><span class="muted small">deliverable' + (n === 1 ? '' : 's') + ' waiting for your decision</span></a>', '#/agency/deliverables');
		},
		workload: function (d) {
			if (!d.workload || !d.workload.length) return '';
			return card('Team Workload', '<div class="pills">' + d.workload.map(function (w) { return '<span class="pill">' + esc(w.name) + ' <b>' + (+w.open) + '</b></span>'; }).join('') + '</div>', '#/team', '', 'dash-wide');
		},
		agency: function (d) {
			if (!widgetAvailable('agency') || !d.agency) return '';
			if (d.agency.error) return '<div class="alert alert-warn" role="status">' + esc(A.CONNECT_MSG) + '</div>';
			var a = d.agency, s = function (n, l, h) { return '<a class="stat" href="' + h + '"><b>' + (+n) + '</b><span class="muted small">' + esc(l) + '</span></a>'; };
			var ups = d.agency_updates || [];
			return '<div class="connect-grid"><section class="card dash-card"><div class="card-b"><div class="stats">' + s(a.active_projects, 'Active agency projects', '#/agency/projects') + s(a.work_requests, 'Work requests', '#/agency/requests') + s(a.in_progress, 'Work in progress', '#/agency/tasks') + s(a.completed, 'Completed work', '#/agency/tasks') + s(a.pending_approvals, 'Pending approvals', '#/agency/deliverables') + '</div></div></section>' +
				card('Recent updates', ups.length ? ups.slice(0, ROWS).map(function (n) { return '<a class="row" href="' + (/^#\//.test(n.link || '') ? esc(n.link) : '#/agency') + '"><div class="grow"><div class="clip">' + esc(n.title) + '</div>' + (n.body ? '<div class="muted small clip">' + esc(n.body) + '</div>' : '') + '</div><span class="muted small nowrap">' + esc(shortWhen(n.created_at)) + '</span></a>'; }).join('') : none('No updates from NeonDigital yet.'), '#/agency', 'Open') + '</div>';
		},
		notifications: function (d) {
			return card('Notifications', d.notifications.length ? d.notifications.slice(0, ROWS).map(function (n) { return '<a class="row" href="' + (/^#\//.test(n.link || '') ? esc(n.link) : '#/notifications') + '"><div class="grow"><div class="clip' + (n.read_at ? '' : ' strong') + '">' + esc(n.title) + '</div>' + (n.body ? '<div class="muted small clip">' + esc(n.body) + '</div>' : '') + '</div></a>'; }).join('') : none('You are all caught up.'), '#/notifications');
		},
		goals: function (d) {
			return card('Goals', d.goals.length ? d.goals.slice(0, ROWS).map(function (g) { return '<a class="row" href="#/notes"><div class="grow"><div class="clip">' + esc(g.title) + '</div><div class="bar"><i style="width:' + Math.min(100, +g.progress) + '%"></i></div></div><span class="muted small nowrap">' + (+g.progress) + '%</span></a>'; }).join('') : none('Add a goal to keep it in view.'), '#/notes', d.goals.length ? 'View all' : 'Add a goal');
		},
		revenue: function (d) {
			if (!d.revenue) return '';
			return card('Revenue and Invoice Overview', '<div class="money"><a href="#/invoices"><span class="muted small">Received this month</span><b>' + A.inr(d.revenue.received_month) + '</b></a><a href="#/invoices"><span class="muted small">Outstanding</span><b>' + A.inr(d.revenue.outstanding) + '</b></a><a href="#/invoices"><span class="muted small">Overdue invoices</span><b' + (+d.revenue.overdue_count ? ' class="late"' : '') + '>' + (+d.revenue.overdue_count) + '</b></a></div>', '#/invoices');
		},
		access: function () {
			var f = S.boot.features, keys = Object.keys(f), agency = S.boot.workspace.account_type === 'agency';
			var usable = keys.filter(function (k) { return !f[k].coming_soon; });
			var off = usable.filter(function (k) { return !f[k].allowed && (agency || f[k].cta === 'Upgrade'); });
			var on = usable.filter(function (k) { return f[k].allowed; });
			var due = keys.filter(function (k) { return f[k].enabled && (f[k].state === 'renewal_due' || f[k].state === 'grace'); });
			var manage = A.can('billing.read') || A.can('billing.manage');
			var body = (due.length ? '<div class="alert alert-warn">' + due.slice(0, 2).map(function (k) { return esc(f[k].label) + (f[k].expires_on ? ' ends ' + esc(A.fmtD(f[k].expires_on)) : ''); }).join('<br>') + '</div>' : '') +
				'<p class="big-line"><b>' + on.length + '</b> of ' + (on.length + off.length) + ' features available</p>' +
				(off.length ? '<div class="pills">' + off.slice(0, 3).map(function (k) { return '<button class="pill pill-lock" data-unlock="' + esc(k) + '">' + A.I.lock + esc(f[k].label) + '</button>'; }).join('') + (off.length > 3 ? '<span class="pill pill-more">+' + (off.length - 3) + ' more</span>' : '') + '</div>' : '');
			return card('Subscription and Feature Access', body, manage ? '#/billing' : '', agency ? 'View access' : 'Manage plan');
		}
	};

	V.dashboard = function (el) {
		A.setTitle('My Dashboard');
		ROWS = window.innerWidth < 640 ? 3 : 4;
		el.innerHTML = '<div class="dash"><div class="dash-loading kpis">' + new Array(5).join('<div class="kpi">' + A.loading(1) + '</div>') + '</div></div>';
		el.onclick = function (e) {
			var u = e.target.closest('[data-unlock]');
			if (u) { A.featureAccess(u.getAttribute('data-unlock')); return; }
			if (e.target.closest('[data-cust]')) customise(function () { V.dashboard(el); });
		};
		var today = A.localDay();
		return Promise.all([api('dashboard'), api('calendar/feed', { params: { from: today, to: A.addDays(today, 14) } }).catch(function () { return { items: [] }; })]).then(function (r) {
			var d = r[0], now = Date.now(), feed = r[1].items;
			d.upcoming = feed.filter(function (e) { return e.all_day ? e.start >= today : A.toDate(e.start).getTime() >= now - 36e5; });
			d.todayItems = feed.filter(function (e) { return (e.all_day ? e.start : A.localDay(A.toDate(e.start))) === today; });
			var box = el.querySelector('.dash');
			if (!box) return;
			var hidden = (S.boot.prefs && S.boot.prefs.hidden_widgets) || [], ord = widgetOrder(), first = true;
			var custBtn = '<button class="btn btn-sm" data-cust>Customise</button>';
			var html = SECTIONS.map(function (sec) {
				var keys = ord.filter(function (k) { return sec[2].indexOf(k) > -1 && hidden.indexOf(k) < 0; });
				var inner = keys.filter(function (k) { return k !== 'workload'; }).map(function (k) { return builders[k](d); }).join('');
				var wide = keys.indexOf('workload') > -1 ? builders.workload(d) : '';
				if (!inner && !wide) return '';
				var single = sec[0] === 'overview' || sec[0] === 'connect';
				var n = (inner.match(/class="card dash-card/g) || []).length;
				var content = single ? inner : (inner ? '<div class="dash-grid n' + n + '">' + inner + '</div>' : '') + wide;
				// Phones keep the long sections folded, so the first screen stays on today's work.
				if (window.innerWidth < 640 && (sec[0] === 'biz' || sec[0] === 'account') && !first) {
					return '<details class="dash-sec dash-fold" data-sec="' + sec[0] + '"><summary class="sec-h"><h2>' + esc(sec[1]) + '</h2></summary>' + content + '</details>';
				}
				var head = '<div class="sec-h"><h2>' + esc(sec[1]) + '</h2>' + (first ? custBtn : '') + '</div>';
				first = false;
				return '<section class="dash-sec" data-sec="' + sec[0] + '">' + head + content + '</section>';
			}).join('');
			box.innerHTML = html || '<div class="sec-h"><h2>Your dashboard</h2>' + custBtn + '</div>' + A.empty('All sections are hidden', 'Use Customise to bring some back.');
		});
	};

	/** Show or hide whole sections or single widgets, and reorder within a section. */
	function customise(done) {
		var ord = widgetOrder(), hid = ((S.boot.prefs && S.boot.prefs.hidden_widgets) || []).slice();
		var m = A.modal({ title: 'Customise dashboard', body: '<p class="muted small">Your choices are saved to your account, for this dashboard only.</p><div data-l></div>', foot: '<button class="btn" data-reset>Reset</button><button class="btn btn-primary" data-s>Save</button>' });
		var inSec = function (sec) { return ord.filter(function (k) { return sec[2].indexOf(k) > -1 && widgetAvailable(k); }); };
		var paint = function () {
			m.el.querySelector('[data-l]').innerHTML = SECTIONS.map(function (sec) {
				var keys = inSec(sec);
				if (!keys.length) return '';
				var shown = keys.filter(function (k) { return hid.indexOf(k) < 0; }).length;
				return '<fieldset class="cust-sec"><label class="check cust-h"><input type="checkbox" data-sec="' + sec[0] + '"' + (shown ? ' checked' : '') + '> <b>' + esc(sec[1]) + '</b></label>' + (keys.length > 1 ? keys.map(function (k, i) {
					return '<div class="row cust-row"><label class="check grow"><input type="checkbox" data-t="' + k + '"' + (hid.indexOf(k) < 0 ? ' checked' : '') + '> <span>' + esc(widgetName(k)) + '</span></label><button class="btn btn-sm" data-mv="' + k + '" data-by="-1" aria-label="Move ' + esc(widgetName(k)) + ' up"' + (i ? '' : ' disabled') + '>↑</button><button class="btn btn-sm" data-mv="' + k + '" data-by="1" aria-label="Move ' + esc(widgetName(k)) + ' down"' + (i < keys.length - 1 ? '' : ' disabled') + '>↓</button></div>';
				}).join('') : '') + '</fieldset>';
			}).join('');
		};
		paint();
		m.body.addEventListener('click', function (e) {
			var b = e.target.closest('[data-mv]');
			if (!b) return;
			var k = b.getAttribute('data-mv'), sec = SECTIONS.filter(function (s) { return s[2].indexOf(k) > -1; })[0], keys = inSec(sec), i = keys.indexOf(k), o = keys[i + (+b.getAttribute('data-by'))];
			if (!o) return;
			var a = ord.indexOf(k), z = ord.indexOf(o);
			ord[a] = o; ord[z] = k;
			paint();
		});
		m.body.addEventListener('change', function (e) {
			var k = e.target.getAttribute('data-t'), s = e.target.getAttribute('data-sec');
			if (k) {
				hid = hid.filter(function (x) { return x !== k; });
				if (!e.target.checked) hid.push(k);
			} else if (s) {
				SECTIONS.filter(function (x) { return x[0] === s; })[0][2].forEach(function (w) {
					hid = hid.filter(function (x) { return x !== w; });
					if (!e.target.checked) hid.push(w);
				});
			}
			paint();
		});
		var save = function (widgets, hidden) {
			api('me/preferences', { method: 'PUT', body: { widgets: widgets, hidden_widgets: hidden } }).then(function (p) { S.boot.prefs = p; m.close(); done(); }).catch(A.fail);
		};
		m.el.querySelector('[data-s]').onclick = function () { save(ord, hid); };
		m.el.querySelector('[data-reset]').onclick = function () { save(WIDGETS.map(function (w) { return w[0]; }), []); };
	}

	/* ---- Calendar ---- */
	var CS = { view: null, day: null, hidden: null, q: '' };

	A.eventForm = function (o, done) {
		var editing = !!o.id, cal = o.calendar || 'personal';
		var cals = [];
		if (A.has('calendar_personal')) cals.push(['personal', 'Personal (private)']);
		if (A.has('calendar_business') && A.can('calendar.business.write')) cals.push(['business', 'Business']);
		var run = function (projects) {
			if (A.has('calendar_project') && A.can('tasks.write') && projects.length) cals.push(['project', 'A project']);
			if (!cals.length && !editing) { A.toast('No calendar is available on this account.', true); return; }
			var rr = o.rrule || {};
			var fields = [
				{ k: 'title', label: 'Title', required: true, span: true },
				{ k: 'calendar', label: 'Calendar', type: 'select', opts: A.options(editing ? [[o.calendar, o.calendar]] : cals, cal) },
				{ k: 'project_id', label: 'Project', type: 'select', opts: A.options(projects, o.project_id, 'Choose') },
				{ k: 'starts_at', label: 'Starts', type: 'datetime-local', required: true },
				{ k: 'ends_at', label: 'Ends', type: 'datetime-local' },
				{ k: 'all_day', label: 'All day', type: 'check' },
				{ k: 'location', label: 'Location' },
				{ k: 'freq', label: 'Repeats', type: 'select', opts: A.options([['daily', 'Daily'], ['weekly', 'Weekly'], ['monthly', 'Monthly'], ['yearly', 'Yearly']], rr.freq, 'Does not repeat') },
				{ k: 'until', label: 'Repeat until', type: 'date' },
				{ k: 'reminder_minutes', label: 'Reminder', type: 'select', opts: A.options([['0', 'At the start'], ['10', '10 minutes before'], ['30', '30 minutes before'], ['60', '1 hour before'], ['1440', '1 day before']], o.reminder_minutes, 'None') },
				{ k: 'color', label: 'Colour', type: 'color', value: '#7547DF' },
				{ k: 'description', label: 'Description', type: 'textarea', span: true },
				{ k: 'private_note', label: 'Private note', type: 'textarea', span: true, hint: 'only you ever see this' }
			];
			var parts = (o.participants || []).map(function (p) { return +p.user_id; }).filter(Boolean);
			var extra = S.boot.members.length > 1 ? '<details class="card card-pad" style="margin-bottom:12px"><summary>Participants</summary>' + S.boot.members.map(function (m) { return '<label class="check"><input type="checkbox" data-part value="' + m.id + '"' + (parts.indexOf(+m.id) > -1 ? ' checked' : '') + '> ' + esc(m.name) + '</label>'; }).join('') + '</details>' : '';
			var vals = { title: o.title, starts_at: o.starts_at ? A.localDT(o.starts_at) : (o.day ? o.day + 'T09:00' : ''), ends_at: o.ends_at ? A.localDT(o.ends_at) : '', all_day: o.all_day, location: o.location, until: rr.until, description: o.description, private_note: o.private_note, color: o.color || '#7547DF' };
			A.formModal({
				title: editing ? 'Edit event' : 'Add event', fields: fields, values: vals, extra: extra + '<div data-conf></div>',
				del: editing && o.can_edit ? function () { return api('calendar/events/' + o.id, { method: 'DELETE' }).then(function () { (done || A.render)(); }); } : null,
				ready: function (m) {
					var sel = m.el.querySelector('[name=calendar]'), pj = m.el.querySelector('[name=project_id]').closest('.field');
					var toggle = function () { pj.hidden = sel.value !== 'project'; };
					sel.onchange = toggle; toggle();
					var pn = m.el.querySelector('[name=private_note]').closest('.field');
					if (editing && o.private_note === undefined) pn.hidden = true;
					var chk = function () {
						var s = m.el.querySelector('[name=starts_at]').value, en = m.el.querySelector('[name=ends_at]').value, box = m.el.querySelector('[data-conf]');
						if (!s) { box.innerHTML = ''; return; }
						api('calendar/conflicts', { method: 'POST', body: { starts_at: s, ends_at: en, exclude_event: o.id || 0 } }).then(function (r) {
							box.innerHTML = r.conflicts.length ? '<div class="alert alert-warn"><b>This overlaps with:</b><br>' + r.conflicts.map(function (c) { return esc(c.title) + ' (' + esc(A.fmtDT(c.start)) + ')'; }).join('<br>') + '</div>' : '';
						}).catch(function () {});
					};
					m.el.querySelector('[name=starts_at]').addEventListener('change', chk);
					m.el.querySelector('[name=ends_at]').addEventListener('change', chk);
				},
				onSave: function (v, m) {
					var b = { title: v.title, starts_at: v.starts_at, ends_at: v.ends_at, all_day: v.all_day, location: v.location, description: v.description, color: v.color, reminder_minutes: v.reminder_minutes, private_note: v.private_note };
					b.rrule = v.freq ? { freq: v.freq, interval: 1, until: v.until } : null;
					b.participants = Array.prototype.map.call(m.el.querySelectorAll('[data-part]:checked'), function (i) { return +i.value; });
					if (!editing) { b.calendar = v.calendar; b.project_id = v.project_id; }
					return api('calendar/events' + (editing ? '/' + o.id : ''), { method: editing ? 'PUT' : 'POST', body: b }).then(function () { A.toast('Saved'); (done || A.render)(); });
				}
			});
		};
		if (A.has('projects') && A.has('calendar_project') && A.can('projects.read')) api('projects').then(function (r) { run(r.items); }).catch(function () { run([]); }); else run([]);
	};

	function openItem(it, reload) {
		if (it.source === 'event') {
			api('calendar/events/' + it.ref_id).then(function (e) { A.eventForm(e, reload); }).catch(A.fail);
		} else if (it.source === 'booking') {
			A.go('/bookings');
		} else if (it.source === 'task' || it.source === 'milestone') {
			A.go('/projects/' + String(it.calendar).split(':')[1]);
		} else if (it.source === 'agency') {
			A.modal({ title: it.title, body: '<p>' + A.badge('agency', 'NeonDigital Agency') + '</p><p>' + (it.all_day ? esc(A.fmtD(it.start)) : esc(A.fmtDT(it.start))) + '</p><p class="muted small">From NeonDigital. Read only.</p>' });
		}
	}
	function dayOf(it) { return it.all_day ? String(it.start).slice(0, 10) : A.localDay(A.toDate(it.start)); }

	/** Moves an event by whole days, or to a new time on the same day, keeping its length. */
	function moveEvent(it, toDay, reload) {
		var from = dayOf(it);
		if (from === toDay) return;
		var diff = Math.round((new Date(toDay + 'T12:00:00Z') - new Date(from + 'T12:00:00Z')) / 864e5) * 864e5;
		var s = A.toDate(it.start).getTime() + diff;
		var body = { starts_at: new Date(s).toISOString() };
		if (it.end) body.ends_at = new Date(A.toDate(it.end).getTime() + diff).toISOString();
		api('calendar/events/' + it.ref_id, { method: 'PUT', body: body }).then(function () { A.toast('Moved'); reload(); }).catch(A.fail);
	}

	/**
	 * Calendar view. opts.fixed limits it to one calendar key (a project's own calendar) and
	 * hides the filter list.
	 */
	A.calendarInto = function (el, opts) {
		opts = opts || {};
		CS.view = CS.view || (S.boot.prefs && S.boot.prefs.calendar_view) || (window.innerWidth < 640 ? 'agenda' : 'month');
		CS.day = CS.day || A.localDay();
		var paint, load;
		var range = function () {
			var d = CS.day;
			if (CS.view === 'day') return [d, d];
			if (CS.view === 'week' || CS.view === 'agenda') {
				var dow = (new Date(d + 'T12:00:00Z').getUTCDay() + 6) % 7, st = A.addDays(d, -dow);
				return CS.view === 'week' ? [st, A.addDays(st, 6)] : [d, A.addDays(d, 30)];
			}
			var first = d.slice(0, 8) + '01', fd = (new Date(first + 'T12:00:00Z').getUTCDay() + 6) % 7, s0 = A.addDays(first, -fd);
			return [s0, A.addDays(s0, 41)];
		};
		var title = function () {
			var d = new Date(CS.day + 'T12:00:00Z'), o = { timeZone: 'UTC' };
			if (CS.view === 'day') { o.weekday = 'short'; o.day = 'numeric'; o.month = 'short'; } else if (CS.view === 'week') { var r = range(); return A.fmtD(r[0]) + ' – ' + A.fmtD(r[1]); } else if (CS.view === 'agenda') { return 'From ' + A.fmtD(CS.day); } else { o.month = 'long'; o.year = 'numeric'; }
			return d.toLocaleDateString('en-IN', o);
		};
		var shift = function (n) {
			if (CS.view === 'day') CS.day = A.addDays(CS.day, n);
			else if (CS.view === 'week') CS.day = A.addDays(CS.day, 7 * n);
			else if (CS.view === 'agenda') CS.day = A.addDays(CS.day, 30 * n);
			else { var d = new Date(CS.day.slice(0, 8) + '15T12:00:00Z'); d.setUTCMonth(d.getUTCMonth() + n); CS.day = d.toISOString().slice(0, 8) + '01'; }
			load();
		};
		el.innerHTML = '<div class="cal-layout">' + (opts.fixed ? '' : '<div class="cal-chips" data-chips role="group" aria-label="Show calendars"></div><details class="card card-pad cal-filter"' + (window.innerWidth >= 1000 ? ' open' : '') + '><summary><b class="small">All calendars</b></summary><div data-filter>' + A.loading(4) + '</div></details>') + '<div class="cal-main">' +
			'<div class="toolbar"><button class="btn btn-sm" data-p aria-label="Previous">‹</button><button class="btn btn-sm" data-t>Today</button><button class="btn btn-sm" data-n aria-label="Next">›</button><b class="grow" data-h></b></div>' +
			'<div class="toolbar"><div class="tabs" style="margin:0;padding:0" data-v>' + ['day', 'week', 'month', 'agenda'].map(function (v) { return '<button data-vv="' + v + '" class="' + (CS.view === v ? 'on' : '') + '">' + v[0].toUpperCase() + v.slice(1) + '</button>'; }).join('') + '</div><input class="grow" type="search" data-q placeholder="Search" value="' + esc(CS.q) + '" aria-label="Search calendar">' + (opts.fixed ? '' : '<button class="btn btn-sm" data-share>Share</button>') + '</div>' +
			'<div data-note></div><div class="card card-pad" data-body>' + A.loading(5) + '</div><p class="muted small" style="margin-top:8px">Times are in ' + esc(A.tz()) + '.</p></div></div>';
		var body = el.querySelector('[data-body]');
		el.querySelector('[data-p]').onclick = function () { shift(-1); };
		el.querySelector('[data-n]').onclick = function () { shift(1); };
		el.querySelector('[data-t]').onclick = function () { CS.day = A.localDay(); load(); };
		el.querySelector('[data-v]').onclick = function (e) {
			var b = e.target.closest('[data-vv]');
			if (!b) return;
			CS.view = b.getAttribute('data-vv');
			el.querySelectorAll('[data-vv]').forEach(function (x) { x.classList.toggle('on', x === b); });
			api('me/preferences', { method: 'PUT', body: { calendar_view: CS.view } }).catch(function () {});
			load();
		};
		var deb;
		el.querySelector('[data-q]').addEventListener('input', function (e) { clearTimeout(deb); deb = setTimeout(function () { CS.q = e.target.value; load(); }, 300); });
		var sh = el.querySelector('[data-share]');
		if (sh) sh.onclick = shareModal;

		var items = [];
		load = function () {
			el.querySelector('[data-h]').textContent = title();
			var r = range(), p = { from: r[0], to: r[1], q: CS.q };
			if (opts.fixed) p.calendars = opts.fixed;
			else if (CS.calKeys) p.calendars = CS.calKeys.filter(function (k) { return (CS.hidden || []).indexOf(k) < 0; }).join(',') || 'none';
			return api('calendar/feed', { params: p }).then(function (res) {
				items = res.items;
				var note = el.querySelector('[data-note]');
				if (note) note.innerHTML = res.agency_error ? '<div class="alert alert-warn" role="status">' + esc(A.CONNECT_MSG) + '</div>' : '';
				paint();
			}).catch(function (e) { if (body.isConnected) A.viewError(body, e); });
		};
		var chip = function (it) {
			return '<button class="ev" style="background:' + esc(it.color) + '" data-it="' + esc(it.id) + '"' + (it.editable && !it.all_day ? ' draggable="true"' : '') + '>' + (it.all_day ? '' : esc(A.fmtT(it.start)) + ' ') + esc(it.title) + '</button>';
		};
		paint = function () {
			var by = {};
			items.forEach(function (it) { (by[dayOf(it)] = by[dayOf(it)] || []).push(it); });
			var today = A.localDay();
			if (CS.view === 'month') {
				var r = range(), html = '<div class="month">' + ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'].map(function (d) { return '<div class="dow">' + d + '</div>'; }).join('');
				for (var i = 0; i < 42; i++) {
					var day = A.addDays(r[0], i), other = day.slice(0, 7) !== CS.day.slice(0, 7), list = by[day] || [];
					html += '<div class="day' + (other ? ' other' : '') + (day === today ? ' today' : '') + '" data-day="' + day + '" tabindex="0" aria-label="' + esc(A.fmtD(day)) + ', ' + list.length + ' items"><span class="num">' + (+day.slice(8)) + '</span>' + list.slice(0, 3).map(chip).join('') + (list.length > 3 ? '<span class="muted small">+' + (list.length - 3) + '</span>' : '') + '</div>';
				}
				body.innerHTML = html + '</div>';
			} else if (CS.view === 'agenda') {
				var ds = Object.keys(by).sort();
				body.innerHTML = ds.length ? ds.map(function (d) {
					return '<div class="agenda-day">' + esc(A.fmtD(d)) + '</div>' + by[d].map(function (it) {
						return '<button class="agenda-item" data-it="' + esc(it.id) + '"><span class="cal-swatch" style="background:' + esc(it.color) + '"></span><span class="grow"><b>' + esc(it.title) + '</b></span><span class="muted small">' + (it.all_day ? 'All day' : esc(A.fmtT(it.start))) + '</span></button>';
					}).join('');
				}).join('') : A.empty('Nothing in the next 30 days', 'Tap + to add something.');
			} else {
				var rr = range(), days = [], d = rr[0];
				while (d <= rr[1]) { days.push(d); d = A.addDays(d, 1); }
				var cols = days.map(function (day) {
					var all = (by[day] || []).filter(function (x) { return x.all_day; }), timed = (by[day] || []).filter(function (x) { return !x.all_day; });
					var evs = timed.map(function (it) {
						var parts = A.localDT(it.start).slice(11).split(':'), top = (+parts[0] + (+parts[1]) / 60) * 44;
						var h = it.end ? Math.max(22, (A.toDate(it.end) - A.toDate(it.start)) / 36e5 * 44) : 22;
						return '<button class="tev" style="top:' + top + 'px;height:' + h + 'px;background:' + esc(it.color) + '" data-it="' + esc(it.id) + '"' + (it.editable ? ' draggable="true"' : '') + '>' + esc(A.fmtT(it.start)) + ' ' + esc(it.title) + '</button>';
					}).join('');
					return '<div class="tcol" data-day="' + day + '"><div class="small center" style="padding:4px;border-bottom:1px solid var(--border)"><b' + (day === today ? ' style="color:var(--accent-text)"' : '') + '>' + esc(new Date(day + 'T12:00:00Z').toLocaleDateString('en-IN', { timeZone: 'UTC', weekday: 'short', day: 'numeric' })) + '</b></div><div style="min-height:24px">' + all.map(chip).join('') + '</div><div style="position:relative">' + new Array(25).join('<div class="tslot"></div>') + evs + '</div></div>';
				}).join('');
				var labels = '<div><div style="height:56px"></div>';
				for (var h = 0; h < 24; h++) labels += '<div class="tlabel">' + (h % 12 || 12) + (h < 12 ? 'a' : 'p') + '</div>';
				body.innerHTML = '<div class="timeline" style="grid-template-columns:36px minmax(0,1fr)">' + labels + '</div><div class="week-grid" style="grid-template-columns:repeat(' + days.length + ',minmax(0,1fr))">' + cols + '</div></div>';
			}
			var find = function (id) { return items.filter(function (x) { return x.id === id; })[0]; };
			body.onclick = function (e) {
				var b = e.target.closest('[data-it]');
				if (b) { e.stopPropagation(); openItem(find(b.getAttribute('data-it')), load); return; }
				var c = e.target.closest('[data-day]');
				if (c && CS.view === 'month' && window.innerWidth < 640) {
					CS.day = c.getAttribute('data-day');
					CS.view = 'day';
					el.querySelectorAll('[data-vv]').forEach(function (x) { x.classList.toggle('on', x.getAttribute('data-vv') === 'day'); });
					load();
					return;
				}
				if (c) { A.eventForm({ day: c.getAttribute('data-day'), calendar: opts.fixed ? 'project' : undefined, project_id: opts.projectId }, load); }
			};
			var drag = null;
			body.ondragstart = function (e) { var b = e.target.closest('[data-it]'); if (b) { drag = find(b.getAttribute('data-it')); e.dataTransfer.effectAllowed = 'move'; e.dataTransfer.setData('text/plain', drag.id); } };
			body.ondragover = function (e) { var c = e.target.closest('[data-day]'); if (c && drag) { e.preventDefault(); c.classList.add('drop'); } };
			body.ondragleave = function (e) { var c = e.target.closest('[data-day]'); if (c) c.classList.remove('drop'); };
			body.ondrop = function (e) { var c = e.target.closest('[data-day]'); if (c && drag) { e.preventDefault(); c.classList.remove('drop'); moveEvent(drag, c.getAttribute('data-day'), load); drag = null; } };
		};
		if (opts.fixed) return load();
		return api('calendar/list').then(function (r) {
			var f = el.querySelector('[data-filter]'), chips = el.querySelector('[data-chips]');
			// NeonDigital Agency exists only for NeonDigital clients. Everyone else never sees it.
			var cals = r.calendars.filter(function (c) { return c.key !== 'agency' || S.boot.workspace.account_type === 'agency'; });
			var avail = cals.filter(function (c) { return c.allowed; });
			CS.calKeys = avail.map(function (c) { return c.key; });
			if (!CS.hidden) { try { CS.hidden = JSON.parse(localStorage.getItem('nw-cal-hidden-' + S.workspaceId) || '[]'); } catch (x) { CS.hidden = []; } }
			var GROUPS = [
				['personal', 'Personal', function (c) { return c.key === 'personal'; }],
				['business', 'Business', function (c) { return c.key === 'business' || c.key === 'bookings'; }],
				['project', 'Project', function (c) { return c.key.indexOf('project:') === 0; }],
				['agency', 'NeonDigital Agency', function (c) { return c.key === 'agency'; }]
			];
			var paintFilters = function () {
				chips.innerHTML = GROUPS.map(function (g) {
					var all = cals.filter(g[2]), open = all.filter(function (c) { return c.allowed; });
					if (!all.length) return '';
					var sw = '<span class="cal-swatch" style="background:' + esc(all[0].color) + '"></span>';
					if (!open.length) return '<button class="chip chip-lock" data-lockcal="' + esc(all[0].feature) + '" data-name="' + esc(g[1]) + '">' + A.I.lock + esc(g[1]) + '</button>';
					var on = open.some(function (c) { return CS.hidden.indexOf(c.key) < 0; });
					return '<button class="chip' + (on ? ' on' : '') + '" data-g="' + g[0] + '" aria-pressed="' + on + '">' + sw + esc(g[1]) + '</button>';
				}).join('');
				f.innerHTML = avail.map(function (c) {
					return '<label><input type="checkbox" data-c="' + esc(c.key) + '"' + (CS.hidden.indexOf(c.key) < 0 ? ' checked' : '') + '><span class="cal-swatch" style="background:' + esc(c.color) + '"></span>' + esc(c.label) + '</label>';
				}).join('');
			};
			var store = function () {
				try { localStorage.setItem('nw-cal-hidden-' + S.workspaceId, JSON.stringify(CS.hidden)); } catch (x) {}
				paintFilters();
				load();
			};
			paintFilters();
			f.onchange = function (e) {
				var k = e.target.getAttribute('data-c');
				if (!k) return;
				CS.hidden = CS.hidden.filter(function (x) { return x !== k; });
				if (!e.target.checked) CS.hidden.push(k);
				store();
			};
			chips.onclick = function (e) {
				var l = e.target.closest('[data-lockcal]');
				if (l) { A.featureAccess(l.getAttribute('data-lockcal'), l.getAttribute('data-name')); return; }
				var b = e.target.closest('[data-g]');
				if (!b) return;
				var g = GROUPS.filter(function (x) { return x[0] === b.getAttribute('data-g'); })[0];
				var keys = avail.filter(g[2]).map(function (c) { return c.key; }), wasOn = keys.some(function (k) { return CS.hidden.indexOf(k) < 0; });
				CS.hidden = CS.hidden.filter(function (x) { return keys.indexOf(x) < 0; });
				if (wasOn) CS.hidden = CS.hidden.concat(keys);
				store();
			};
			return load();
		});
	};

	function shareModal() {
		api('calendar/shares').then(function (r) {
			var others = S.boot.members.filter(function (m) { return +m.id !== +S.boot.user.id; });
			var m = A.modal({ title: 'Share my personal calendar', body: '<p class="muted small">People you choose can see the time and title of your personal events, in this workspace only. They can never see private notes or change anything. Nothing here goes to NeonDigital.</p>' + (others.length ? others.map(function (x) { return '<label class="check"><input type="checkbox" value="' + x.id + '"' + (r.user_ids.indexOf(+x.id) > -1 ? ' checked' : '') + '> ' + esc(x.name) + '</label>'; }).join('') : A.empty('No colleagues yet', '')), foot: '<button class="btn btn-primary" data-s>Save</button>' });
			m.el.querySelector('[data-s]').onclick = function () {
				var ids = Array.prototype.map.call(m.body.querySelectorAll('input:checked'), function (i) { return +i.value; });
				api('calendar/shares', { method: 'PUT', body: { user_ids: ids } }).then(function () { A.toast('Saved'); m.close(); }).catch(A.fail);
			};
		}).catch(A.fail);
	}

	V.calendar = function (el) {
		A.setTitle('Calendar');
		return A.calendarInto(el);
	};

	A.route(/^\/$/, 'dashboard');
	A.route(/^\/calendar$/, 'calendar');
})();
