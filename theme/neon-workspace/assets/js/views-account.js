/* NeonDigital Connect, subscription and billing, modules, integrations, settings, notifications. */
(function () {
	'use strict';
	var A = window.App, S = A.S, api = A.api, esc = A.esc, V = A.views;

	/* ---- NeonDigital Connect ---- */
	function agencyPerm(k) { var p = S.boot.agency && S.boot.agency.permissions; return !!(p && p[k]); }

	/** "Main project › Sub-project" for any project row that carries parent_id. */
	A.projectPath = function (p, byId) {
		var par = p && p.parent_id && byId ? byId[p.parent_id] : null;
		return (par ? par.name + ' › ' : (p && p.parent_name ? p.parent_name + ' › ' : '')) + (p ? p.name : '');
	};
	/**
	 * Submit work request. opts.project_id preselects the project the form was opened from (a main
	 * project or a sub-project). Only this Workspace's open projects are offered, main projects with
	 * their sub-projects under them, so the request always goes to a project WorkDesk will accept.
	 */
	A.requestForm = function (done, opts) {
		opts = opts || {};
		var key = A.uuid();
		Promise.all([api('agency/projects'), api('agency/packages').catch(function () { return { items: [] }; })]).then(function (r) {
			var all = r[0].items, byId = {}, open = [], seen = {};
			all.forEach(function (p) { byId[p.id] = p; });
			var add = function (p) { if (!seen[p.id] && !p.locked) { seen[p.id] = 1; open.push(p); } };
			all.filter(function (p) { return !p.parent_id || !byId[p.parent_id]; }).forEach(function (m) { add(m); all.filter(function (x) { return +x.parent_id === +m.id; }).forEach(add); });
			all.forEach(add);
			var want = opts.project_id && byId[opts.project_id] && !byId[opts.project_id].locked ? +opts.project_id : (open.length === 1 ? open[0].id : '');
			var svc = [];
			r[1].items.forEach(function (p) { p.items.forEach(function (i) { svc.push([p.id + ':' + i.id, i.service + ' (' + p.name + ', ' + i.available + ' available)']); }); });
			var pick = null;
			A.formModal({
				title: 'Submit work request', ok: 'Send to NeonDigital',
				fields: [
					{ k: 'title', label: 'What do you need?', required: true, span: true },
					{ k: 'project_id', label: 'Project', type: 'select', opts: A.options(open.map(function (p) { return [p.id, (p.parent_id && byId[p.parent_id] ? '↳ ' : '') + A.projectPath(p, byId)]; }), want, open.length ? 'No specific project' : 'No open projects in this Workspace') },
					{ k: 'service', label: 'Service', type: 'select', opts: A.options(svc, '', 'Not sure') },
					{ k: 'preferred_date', label: 'Preferred delivery date', type: 'date' }, { k: 'ref', label: 'Reference link', type: 'url', placeholder: 'https://' }, { k: 'description', label: 'Description', type: 'textarea', span: true }, { k: 'instructions', label: 'Additional instructions', type: 'textarea', span: true }
				],
				extra: '<div class="field"><span>Attachments</span><div data-rq-att></div></div><p class="muted small" data-rq-to></p><p class="muted small">NeonDigital reviews every request and confirms scope, package usage and timing. A unit is reserved while it is reviewed and released if the request is declined.</p>',
				ready: function (m) {
					pick = A.attachPicker(m.el.querySelector('[data-rq-att]'));
					var sel = m.el.querySelector('[name="project_id"]'), to = m.el.querySelector('[data-rq-to]');
					var say = function () { var p = byId[sel.value]; to.textContent = p ? 'Goes to ' + A.projectPath(p, byId) + ' in ' + S.boot.workspace.name + '.' : 'Goes to NeonDigital for ' + S.boot.workspace.name + ', with no project.'; };
					sel.onchange = say; say();
				},
				onSave: function (v) {
					var pid = +v.project_id || 0;
					if (pid && !open.some(function (p) { return +p.id === pid; })) return Promise.reject(new Error('Choose one of the open projects in this Workspace.'));
					var p = (v.service || ':').split(':');
					return pick.files().then(function (files) {
						if (files.length) pick.status('uploading');
						return api('agency/requests', { method: 'POST', body: { idempotency_key: key, title: v.title, description: v.description, instructions: v.instructions, project_id: pid, client_package_id: p[0], item_id: p[1], preferred_date: v.preferred_date, reference_links: v.ref ? [{ url: v.ref, label: 'Reference' }] : [], attachments: files } });
					}).then(function (q) { A.toast('Sent to NeonDigital'); if (done) done(q); else A.go('/agency/requests/' + q.id); }, function (e) { if (pick.count()) pick.status('failed', e.message); throw e; });
				}
			});
		}).catch(A.fail);
	};

	V.agency = function (el, m) {
		A.setTitle('NeonDigital Connect');
		if (!S.boot.agency) { el.innerHTML = '<section class="card locked"><h2>NeonDigital Connect</h2><p class="muted">For NeonDigital agency clients. Use the invitation link from NeonDigital to connect.</p></section>'; return; }
		var tab = m[1] || 'overview', id = +m[2] || 0;
		var tabs = [['overview', 'Overview'], ['projects', 'Projects'], ['tasks', 'Tasks'], ['requests', 'Work requests'], ['deliverables', 'Deliverables'], ['packages', 'Package'], ['invoices', 'Invoices']];
		var agencyBlocked = !A.has('agency_connect') || S.boot.agency.status === 'suspended' || !S.boot.agency.agency_connect;
		// Projects always show, locked if they have no package. A project on its own subscription also
		// keeps its tasks after the agency arrangement ends.
		if (agencyBlocked && S.boot.agency.status !== 'suspended' && S.boot.agency.agency_connect !== false || S.boot.agency.independent_projects > 0 && S.boot.agency.status !== 'suspended') {
			if (agencyBlocked) {
				var solo = S.boot.agency.independent_projects > 0;
				tabs = tabs.filter(function (t) { return t[0] === 'projects' || t[0] === 'invoices' || (solo && t[0] === 'tasks'); });
				if (!tabs.some(function (t) { return t[0] === tab; })) tab = 'projects';
			}
			agencyBlocked = false;
		}
		if (agencyBlocked && tab !== 'invoices') {
			if (S.boot.agency.status === 'suspended') el.innerHTML = '<section class="card locked"><h2>Access is suspended</h2><p class="muted">Your records are safe. Contact NeonDigital to restore access.</p><a class="btn btn-primary" href="' + esc(window.NW.contact) + '" target="_blank" rel="noopener">Contact NeonDigital</a></section>';
			else A.locked(el, 'agency_connect');
			return;
		}
		el.innerHTML = '<div class="tabs">' + tabs.map(function (t) { return '<a href="#/agency/' + t[0] + '" class="' + (t[0] === tab ? 'on' : '') + '">' + t[1] + '</a>'; }).join('') + '</div><div data-c>' + A.loading(5) + '</div>';
		var box = el.querySelector('[data-c]');
		var map = { overview: aOverview, projects: id ? aProject : aProjects, tasks: id ? aTask : aTasks, requests: id ? aRequest : aRequests, deliverables: id ? aDeliverable : aDeliverables, packages: aPackages, invoices: aInvoices };
		return map[tab](box, id);
	};

	function aOverview(box) {
		return Promise.all([api('agency/overview'), api('agency/packages')]).then(function (r) {
			var a = r[0], stat = function (l, v, h) { return '<a class="stat" href="' + h + '"><b>' + (+v) + '</b><span class="muted small">' + esc(l) + '</span></a>'; };
			box.innerHTML = '<div class="stack"><section class="card card-pad"><div class="stats">' + stat('Active projects', a.active_projects, '#/agency/projects') + stat('Open work requests', a.work_requests, '#/agency/requests') + stat('Pending tasks', a.pending_tasks, '#/agency/projects') + stat('In progress', a.in_progress, '#/agency/projects') + stat('Completed', a.completed, '#/agency/projects') + stat('Pending approvals', a.pending_approvals, '#/agency/deliverables') + '</div></section>' +
				(agencyPerm('work_requests') ? '<button class="btn btn-primary" data-new>Submit work request</button>' : '') +
				'<section class="card"><div class="card-h"><h2>Upcoming deadlines</h2></div><div class="card-b">' + (a.deadlines.length ? a.deadlines.map(function (t) { return '<div class="row"><div class="grow">' + esc(t.title) + '</div><span class="muted small">' + esc(A.fmtD(t.due_date)) + '</span></div>'; }).join('') : '<p class="muted">Nothing due soon.</p>') + '</div></section>' + pkgCards(r[1]) + '</div>';
			var n = box.querySelector('[data-new]');
			if (n) n.onclick = function () { A.requestForm(); };
		});
	}
	/* Client-visible tasks and a near-real-time thread. WorkDesk decides what appears here. */
	function aTasks(box) {
		var draw = function () {
			return api('agency/tasks').then(function (r) {
				box.innerHTML = '<section class="card card-pad">' + (r.items.length ? r.items.map(function (t) { return '<a class="row" href="#/agency/tasks/' + t.id + '"><div class="grow"><b>' + esc(t.title) + '</b><div class="muted small">' + (t.due_date ? 'Due ' + esc(A.fmtD(t.due_date)) : '') + (t.assignees && t.assignees.length ? ' · ' + esc(t.assignees.join(', ')) : '') + (t.team ? ' · ' + esc(t.team) : '') + '</div></div>' + A.badge(t.status, t.status_label) + '</a>'; }).join('') : A.empty('No tasks are shared yet', 'NeonDigital chooses which tasks appear here.')) + '</section>';
			});
		};
		A.live(draw);
		return draw();
	}
	function aTask(box, id) {
		var draw = function () {
			if (box.querySelector('[data-cin]') && box.querySelector('[data-cin]').value) return Promise.resolve();
			return api('agency/tasks/' + id + '/comments').then(function (r) {
				var t = r.task;
				box.innerHTML = '<div class="toolbar"><a class="btn btn-sm" href="#/agency/tasks">All tasks</a></div><section class="card card-pad"><h2>' + esc(t.title) + '</h2><p>' + A.badge(t.status, t.status_label) + (t.due_date ? ' <span class="muted small">Due ' + esc(A.fmtD(t.due_date)) + '</span>' : '') + '</p>' + (t.description ? '<p>' + esc(t.description) + '</p>' : '') + '<p class="muted small">From ' + esc(t.origin || 'NeonDigital WorkDesk') + (t.assignees && t.assignees.length ? '. Assigned to ' + esc(t.assignees.join(', ')) : '') + (t.team ? ', ' + esc(t.team) : '') + '</p></section>' +
					(t.deliverables && t.deliverables.length ? '<section class="card card-pad" style="margin-top:14px"><h2>Deliverables</h2>' + t.deliverables.map(function (d) { return '<div class="row"><a class="grow" href="#/agency/deliverables/' + d.id + '">' + esc(d.title) + ' <span class="muted small">v' + (+d.revision) + '</span></a>' + A.badge(d.status, d.status_label) + '</div>'; }).join('') + '</section>' : '') +
					(t.timeline && t.timeline.length ? '<section class="card card-pad" style="margin-top:14px"><h2>Assignment history</h2>' + t.timeline.map(function (h) { return '<div class="row"><div class="grow">Assigned to ' + esc(h.to) + '</div><span class="muted small">' + esc(A.fmtDT(h.at)) + '</span></div>'; }).join('') + '</section>' : '') +
					(t.show_comments ? '<section class="card chat-card" style="margin-top:14px"><div class="card-h"><h2>Messages</h2></div><div data-chat></div></section>' : '');
				var cb = box.querySelector('[data-chat]');
				if (cb) A.chat(cb, { messages: r.comments, canSend: !!t.chat_open, canAttach: true, closedText: 'Chat opens once a NeonDigital team member starts it.', onSend: function (v, files) { return api('agency/tasks/' + id + '/comments', { method: 'POST', body: { body: v, client_ref: A.uuid(), attachments: files || [] } }).then(draw); } });
			});
		};
		A.live(draw);
		return draw();
	}
	/* Checks a small change marker every few seconds while the page is in view, and reloads only when it moves. */
	var liveTimer = null, liveStamp = null;
	A.live = function (redraw) {
		if (liveTimer) clearInterval(liveTimer);
		liveStamp = null;
		var at = location.hash;
		var tick = function () {
			if (location.hash !== at) { clearInterval(liveTimer); liveTimer = null; return; }
			if (document.hidden) return;
			api('agency/pulse').then(function (p) {
				var typing = Array.prototype.some.call(document.querySelectorAll('[data-view] textarea, [data-view] input[type=text]'), function (i) { return !!i.value; });
				if (typing) return; // Checks again on the next tick, so nothing being typed is lost.
				if (liveStamp !== null && liveStamp !== p.stamp) redraw().catch(function () {});
				liveStamp = p.stamp;
			}).catch(function () {});
		};
		tick();
		liveTimer = setInterval(tick, 8000);
	};
	function pkgCards(res) {
		if (res.hidden) return '';
		return res.items.map(function (p) {
			return '<section class="card"><div class="card-h"><h2>' + esc(p.name) + '</h2>' + A.badge(p.status) + '</div><div class="card-b"><p class="muted small">' + esc(p.type) + ' · valid ' + esc(A.fmtD(p.start_date)) + (p.end_date ? ' to ' + esc(A.fmtD(p.end_date)) : '') + (p.cycle ? ' · ' + esc(p.cycle.label) : '') + '</p>' + (p.items.length ? A.tbl([['Service', function (i) { return esc(i.service); }], ['Total', function (i) { return +i.total; }], ['Reserved', function (i) { return +i.reserved; }], ['Consumed', function (i) { return +i.consumed; }], ['Available', function (i) { return '<b>' + (+i.available) + '</b>'; }]], p.items) : '<p class="muted">No unit-based services.</p>') + '</div></section>';
		}).join('') || '<section class="card card-pad"><p class="muted">No package is assigned yet.</p></section>';
	}
	function aPackages(box) {
		return api('agency/packages').then(function (r) {
			box.innerHTML = r.hidden ? '<section class="card locked"><h2>Package details</h2><p class="muted">Not shared on this account.</p><a class="btn btn-primary" href="' + esc(window.NW.contact) + '" target="_blank" rel="noopener">Contact NeonDigital to Unlock</a></section>' : '<div class="stack">' + pkgCards(r) + '<p class="muted small">Usage goes Available → Reserved → Consumed. Declined requests release their unit, and normal revisions use no extra units. Add-ons and renewals are arranged with NeonDigital.</p></div>';
		});
	}
	function lockIcon() { return '<span aria-hidden="true">🔒</span> '; }
	function aProjects(box) {
		return api('agency/projects').then(function (r) {
			var known = {};
			r.items.forEach(function (x) { known[x.id] = x; });
			var top = r.items.filter(function (p) { return !p.parent_id || !known[p.parent_id]; });
			var subRow = function (s) { return '<a class="proj-sub" href="#/agency/projects/' + s.id + '"><span class="proj-sub-l" aria-hidden="true">↳</span><span class="grow"><b>' + (s.locked ? lockIcon() : '') + esc(s.name) + '</b>' + (s.locked ? '<span class="muted small"> ' + esc((s.access || {}).message || 'Locked') + '</span>' : '<span class="muted small"> · ' + (+s.task_done) + ' of ' + (+s.task_total) + ' tasks done</span>') + '</span>' + (s.locked ? '' : A.badge(s.status)) + '</a>'; };
			box.innerHTML = top.length ? '<div class="stack">' + top.map(function (p) {
				var subs = r.items.filter(function (x) { return +x.parent_id === +p.id; });
				var subHtml = subs.length ? '<div class="proj-subs"><div class="muted small proj-subs-h">Sub-projects</div>' + subs.map(subRow).join('') + '</div>' : '';
				var a = p.access || {}, m = a.migration;
				var head = p.locked ? '<a class="proj-main proj-card-locked" href="#/agency/projects/' + p.id + '"><b>' + lockIcon() + esc(p.name) + '</b><div class="muted small" style="margin-top:6px">' + esc(a.message || 'Locked') + '</div></a>' :
					'<a class="proj-main" href="#/agency/projects/' + p.id + '"><div class="proj-top"><b>' + esc(p.name) + '</b> ' + A.badge(p.status) + '</div>' + (m && m.status === 'pending' ? '<div class="alert alert-warn small" style="margin:8px 0">' + (+a.days_left) + ' days left to activate</div>' : '') + '<div class="muted small" style="margin:6px 0">Main project · ' + (+p.task_done) + ' of ' + (+p.task_total) + ' shared tasks done</div><div class="bar"><i style="width:' + (p.task_total ? Math.round(100 * p.task_done / p.task_total) : 0) + '%"></i></div></a>';
				return '<section class="card card-pad proj-tree">' + head + subHtml + '</section>';
			}).join('') + '</div>' : '<section class="card card-pad">' + A.empty('No projects yet', 'Projects from NeonDigital appear here.') + '</section>';
		});
	}
		/* A grey stand-in, not a blurred copy of anything real: nothing about a locked project reaches the browser. */
	function lockedView(box, name, a) {
		var m = a.migration, canPay = m && m.status !== 'active' && A.can('billing.manage');
		var ghost = '<div class="proj-ghost" aria-hidden="true"><div class="proj-ghost-h"></div><div class="grid">' + [1, 2, 3].map(function () { return '<div class="card card-pad"><div class="proj-ghost-l"></div><div class="proj-ghost-l s"></div></div>'; }).join('') + '</div><div class="card card-pad">' + [1, 2, 3, 4].map(function () { return '<div class="proj-ghost-l"></div>'; }).join('') + '</div></div>';
		box.innerHTML = '<p><a class="link" href="#/agency/projects">← Projects</a></p><div class="proj-locked">' + ghost + '<section class="card card-pad proj-veil" role="alert"><div class="access-i">' + lockIcon() + '</div><h2>' + esc(name) + '</h2><p>' + esc(a.message || 'No Workspace package assigned to this project.') + '</p><div class="locked-acts">' + (canPay ? '<button class="btn btn-primary" data-act>Activate subscription' + (m.price !== null && m.price !== undefined ? ', ' + A.inr(m.price) : '') + '</button>' : '') + '<a class="btn' + (canPay ? '' : ' btn-primary') + '" href="' + esc(window.NW.contact) + '" target="_blank" rel="noopener">Contact NeonDigital to Unlock</a></div></section></div>';
		var b = box.querySelector('[data-act]');
		if (b) b.onclick = function () { activate(box, b.getAttribute('data-id') || activate.pid, activate.redraw); };
	}
	function migrationBanner(a) {
		var m = a && a.migration;
		if (!m) return '';
		if (m.status === 'active') return '<div class="alert">This project runs on its own <b>' + esc(m.package_name) + '</b> subscription' + (m.period_end ? ', renewing ' + esc(A.fmtD(m.period_end)) : '') + '.</div>';
		if (m.status !== 'pending') return '';
		return '<div class="alert alert-warn"><b>This project is moving to its own subscription.</b> Everything in it stays. Activate <b>' + esc(m.package_name) + '</b>' + (m.price !== null && m.price !== undefined ? ' for ' + A.inr(m.price) + ' a ' + (m.billing_cycle === 'year' ? 'year' : 'month') : '') + ' by ' + esc(A.fmtD(m.grace_ends_on)) + ' (' + (+a.days_left) + ' days left) to keep using it. If not, only this project locks.' + (A.can('billing.manage') ? ' <button class="btn btn-sm btn-primary" data-act>Activate subscription</button>' : ' Ask the workspace owner to activate it.') + '</div>';
	}
	function activate(box, id, redraw) {
		return api('agency/projects/' + id + '/activate', { method: 'POST', body: {} }).then(function (r) {
			if (r.status === 'active') { A.toast('Subscription active'); return A.reloadBoot().then(redraw); }
			if (r.status === 'waiting') { A.toast(r.message); return redraw(); }
			return api('billing').then(function (b) { pay(r.invoice_id, b.gateway, function () { return A.reloadBoot().then(redraw); }); });
		}).catch(A.fail);
	}
	function aProject(box, id) {
		var redraw = function () { return aProject(box, id); };
		activate.pid = id; activate.redraw = redraw;
		return api('agency/projects').then(function (l) {
			var byId = {};
			l.items.forEach(function (x) { byId[x.id] = x; });
			var row = byId[id];
			if (!row) { box.innerHTML = '<p><a class="link" href="#/agency/projects">← Projects</a></p><section class="card card-pad">' + A.empty('Project not found', 'It may belong to another of your Workspaces. Use the switcher at the top.') + '</section>'; return; }
			A.setTitle(row.name);
			if (row.locked) { lockedView(box, row.name, row.access || {}); return; }
			return api('agency/projects/' + id).then(function (p) {
				var par = p.parent, sec = function (title, body, act) { return '<section class="card"><div class="card-h"><h2>' + title + '</h2><span class="grow"></span>' + (act || '') + '</div><div class="card-b">' + body + '</div></section>'; };
				var crumbs = '<nav class="crumbs" aria-label="Project"><a class="link" href="#/agency/projects">Projects</a>' + (par ? ' <span aria-hidden="true">›</span> <a class="link" href="#/agency/projects/' + par.id + '">' + esc(par.name) + '</a>' : '') + ' <span aria-hidden="true">›</span> <b>' + esc(p.name) + '</b></nav>';
				var kind = par ? 'Sub-project of <a class="link" href="#/agency/projects/' + par.id + '">' + esc(par.name) + '</a>' : 'Main project';
				var canReq = agencyPerm('work_requests') && A.can('agency.use');
				box.innerHTML = crumbs + '<div class="stack">' + migrationBanner(p.access) +
					'<section class="card card-pad"><h2>' + esc(p.name) + ' ' + A.badge(p.status) + '</h2><p class="muted small">' + kind + ' · ' + esc(S.boot.workspace.name) + '</p>' + (p.description ? '<p class="muted">' + esc(p.description) + '</p>' : '') +
						'<div class="stats">' + '<div class="stat"><b>' + (+p.task_total) + '</b><span class="muted small">Shared tasks</span></div><div class="stat"><b>' + (+p.task_done) + '</b><span class="muted small">Done</span></div><div class="stat"><b>' + p.requests.filter(function (q) { return q.status === 'submitted' || q.status === 'in_review'; }).length + '</b><span class="muted small">Open requests</span></div><div class="stat"><b>' + p.deliverables.filter(function (d) { return d.status === 'pending_approval'; }).length + '</b><span class="muted small">Awaiting approval</span></div></div>' +
						(p.access && p.access.package ? '<p class="muted small">Package: ' + esc(p.access.package.name) + (p.access.inherited ? ' (from ' + esc(par ? par.name : 'the main project') + ')' : '') + (p.access.package.expires_on ? ', to ' + esc(A.fmtD(p.access.package.expires_on)) : '') + '</p>' : '') +
						(canReq ? '<button class="btn btn-primary" data-new>Submit work request for ' + esc(p.name) + '</button>' : '') + '</section>' +
					(!par && p.subprojects && p.subprojects.length ? sec('Sub-projects', p.subprojects.map(function (s) { return '<a class="row" href="#/agency/projects/' + s.id + '"><div class="grow"><b>' + (s.locked ? lockIcon() : '') + esc(s.name) + '</b></div><span class="muted small">Open →</span></a>'; }).join('')) : '') +
					sec('Work requests', p.requests.map(function (q) { return '<a class="row" href="#/agency/requests/' + q.id + '"><div class="grow"><b>' + esc(q.title) + '</b><div class="muted small">' + esc(A.fmtD(q.created_at)) + (q.attachments && q.attachments.length ? ' · ' + q.attachments.length + ' file' + (q.attachments.length === 1 ? '' : 's') : '') + '</div></div>' + A.badge(q.status, q.status_label) + '</a>'; }).join('') || '<p class="muted">No requests on this ' + (par ? 'sub-project' : 'project') + ' yet.</p>') +
					sec('Tasks', p.tasks.map(function (t) { return '<a class="row" href="#/agency/tasks/' + t.id + '"><div class="grow">' + esc(t.title) + (t.due_date ? '<div class="muted small">Due ' + esc(A.fmtD(t.due_date)) + '</div>' : '') + '</div>' + A.badge(t.status, t.status_label) + '</a>'; }).join('') || '<p class="muted">No tasks are shared on this ' + (par ? 'sub-project' : 'project') + '.</p>') +
					sec('Deliverables', p.deliverables.map(function (d) { return '<div class="row"><a class="grow" href="#/agency/deliverables/' + d.id + '">' + esc(d.title) + ' <span class="muted small">v' + (+d.revision) + '</span></a>' + A.badge(d.status, d.status_label) + '</div>' + A.attachList(d.attachments); }).join('') || '<p class="muted">None yet.</p>') +
					sec('Files and updates', (A.attachList(p.attachments) || '<p class="muted">No files shared on this ' + (par ? 'sub-project' : 'project') + ' yet.</p>') + (canReq ? '<div class="proj-up" data-up></div><button class="btn btn-sm" data-upgo>Share with NeonDigital</button>' : '')) +
					sec('Meetings and milestones', p.events.map(function (e) { return '<div class="row"><div class="grow">' + esc(e.title) + '</div><span class="muted small">' + esc(A.fmtDT(e.start)) + '</span></div>'; }).join('') || '<p class="muted">None scheduled.</p>') + '</div>';
				var b = box.querySelector('[data-act]');
				if (b) b.onclick = function () { activate(box, id, redraw); };
				var n = box.querySelector('[data-new]');
				if (n) n.onclick = function () { A.requestForm(function () { redraw(); }, { project_id: id }); };
				var upHost = box.querySelector('[data-up]');
				if (upHost) {
					var pk = A.attachPicker(upHost, p.attachment_rules);
					box.querySelector('[data-upgo]').onclick = function (e) {
						if (!pk.count()) { A.toast('Choose a file first', true); return; }
						e.target.disabled = true;
						pk.files().then(function (files) { pk.status('uploading'); return api('agency/projects/' + id + '/attachments', { method: 'POST', body: { attachments: files } }); })
							.then(function () { pk.status('sent'); A.toast('Shared with NeonDigital'); return redraw(); }, function (x) { pk.status('failed', x.message); e.target.disabled = false; A.fail(x); });
					};
				}
			});
		});
	}
		function aRequests(box) {
		return api('agency/requests').then(function (r) {
			box.innerHTML = (agencyPerm('work_requests') ? '<div class="toolbar"><button class="btn btn-primary" data-new>Submit work request</button></div>' : '') + '<section class="card card-pad">' + (r.items.length ? A.tbl([
				['Request', function (q) { return '<b>' + esc(q.title) + '</b>' + (q.project_name ? '<div class="muted small">' + esc((q.parent_project_name ? q.parent_project_name + ' › ' : '') + q.project_name) + '</div>' : ''); }], ['Service', function (q) { return esc(q.service_name); }], ['Preferred', function (q) { return q.preferred_date ? esc(A.fmtD(q.preferred_date)) : ''; }], ['Status', function (q) { return A.badge(q.status, q.status_label); }]
			], r.items, function (q) { return 'class="click" onclick="location.hash=\'#/agency/requests/' + (+q.id) + '\'"'; }) : A.empty('No work requests yet', '')) + '</section>';
			var n = box.querySelector('[data-new]');
			if (n) n.onclick = function () { A.requestForm(function () { aRequests(box); }); };
		});
	}
	function aRequest(box, id) {
		var draw = function () {
			var typing = box.querySelector('[data-cin]');
			if (typing && typing.value) return Promise.resolve();
			return api('agency/requests/' + id).then(function (q) {
				var open = q.status === 'submitted' || q.status === 'in_review';
				var steps = (q.progress || []).map(function (s) { return '<span class="step' + (s.done ? ' on' : '') + '">' + esc(s.label) + '</span>'; }).join('');
				box.innerHTML = '<p><a class="link" href="#/agency/requests">← Work requests</a>' + (q.project_id ? ' · <a class="link" href="#/agency/projects/' + q.project_id + '">' + esc((q.parent_project_name ? q.parent_project_name + ' › ' : '') + q.project_name) + '</a>' : '') + '</p><section class="card card-pad"><h2>' + esc(q.title) + ' ' + A.badge(q.status, q.status_label) + '</h2>' + (steps ? '<div class="steps">' + steps + '</div>' : '') + '<p>' + esc(q.description) + '</p>' + A.attachList(q.attachments) + (q.instructions ? '<p class="muted small">' + esc(q.instructions) + '</p>' : '') + (q.review_note ? '<div class="alert"><b>From NeonDigital:</b> ' + esc(q.review_note) + '</div>' : '') + (q.reference_links.map(function (l) { return '<p class="small"><a href="' + A.safeUrl(l.url) + '" target="_blank" rel="noopener noreferrer">' + esc(l.label || l.url) + '</a></p>'; }).join('')) + (open && agencyPerm('work_requests') ? '<button class="btn btn-danger btn-sm" data-c>Cancel request</button>' : '') + '</section>' +
					'<section class="card chat-card" style="margin-top:14px"><div class="card-h"><h2>Messages</h2></div><div data-chat></div></section>';
				A.chat(box.querySelector('[data-chat]'), { messages: q.comments, canSend: !!q.chat_active && agencyPerm('work_requests'), canAttach: true, onSend: function (v, files) { return api('agency/requests/' + id + '/comments', { method: 'POST', body: { body: v, idempotency_key: A.uuid(), attachments: files || [] } }).then(draw); } });
				var c = box.querySelector('[data-c]');
				if (c) c.onclick = function () { A.confirm('Cancel this request?', 'Any reserved unit goes back to your package.', 'Cancel request', true).then(function (y) { if (y) api('agency/requests/' + id + '/cancel', { method: 'POST', body: {} }).then(draw).catch(A.fail); }); };
			});
		};
		A.live(draw);
		return draw();
	}
	function aDeliverables(box) {
		return api('agency/deliverables').then(function (r) {
			box.innerHTML = '<section class="card card-pad">' + (r.items.length ? r.items.map(function (d) { return '<div class="row"><a class="grow" href="#/agency/deliverables/' + d.id + '"><b>' + esc(d.title) + '</b> <span class="muted small">v' + (+d.revision) + '</span><div class="muted small">' + esc(d.project_name) + (d.attachments && d.attachments.length ? ' · ' + d.attachments.length + ' file' + (d.attachments.length === 1 ? '' : 's') : '') + '</div></a>' + A.badge(d.status, d.status_label) + '</div>'; }).join('') : A.empty('No deliverables yet', '')) + '</section>';
		});
	}
	function aDeliverable(box, id) {
		return api('agency/deliverables/' + id).then(function (d) {
			var mayDecide = d.status === 'pending_approval' && A.can('agency.approve') && agencyPerm('approvals');
			box.innerHTML = '<p><a class="link" href="#/agency/deliverables">← Deliverables</a></p><section class="card card-pad"><h2>' + esc(d.title) + ' <span class="muted small">v' + (+d.revision) + '</span> ' + A.badge(d.status, d.status_label) + '</h2><p>' + esc(d.description) + '</p>' + (d.file_url ? '<p><a class="btn" href="' + A.safeUrl(d.file_url) + '" target="_blank" rel="noopener noreferrer">Open file</a></p>' : '') + A.attachList(d.attachments) +
				(mayDecide ? '<div class="toolbar"><button class="btn btn-primary" data-d="approve">Approve</button><button class="btn" data-d="revision">Request revision</button><button class="btn btn-danger" data-d="reject">Reject</button></div>' : (d.status === 'pending_approval' ? '<p class="muted small">Ask the workspace owner or an admin to decide.</p>' : '')) + '</section>' +
				'<section class="card card-pad" style="margin-top:14px"><h2>Revision history</h2>' + (d.approvals.map(function (a) { return '<div class="row"><div class="grow"><b>v' + (+a.revision) + '</b> ' + esc(a.decision) + ' by ' + esc(a.actor_name) + (a.comment ? '<div class="muted small">' + esc(a.comment) + '</div>' : '') + '</div><span class="muted small">' + esc(A.fmtDT(a.created_at)) + '</span></div>'; }).join('') || '<p class="muted">No decisions yet.</p>') + '</section>' +
				'<section class="card card-pad" style="margin-top:14px"><h2>Comments</h2>' + (d.comments.map(function (c) { return '<p><b>' + esc(c.author_name || c.author_type) + '</b> <span class="muted small">' + esc(A.fmtDT(c.created_at)) + '</span><br>' + esc(c.body) + '</p>' + A.attachList(c.attachments); }).join('') || '<p class="muted">No comments yet.</p>') + '<label class="field"><span>Add a comment</span><textarea data-b rows="2"></textarea></label><button class="btn btn-primary" data-p>Post</button></section>';
			var keyC = A.uuid();
			box.querySelector('[data-p]').onclick = function () { api('agency/deliverables/' + id + '/comments', { method: 'POST', body: { body: box.querySelector('[data-b]').value, idempotency_key: keyC } }).then(function () { aDeliverable(box, id); }).catch(A.fail); };
			var decide = function (dec, comment) {
				return api('agency/deliverables/' + id + '/decision', { method: 'POST', body: { decision: dec, comment: comment, idempotency_key: A.uuid() } }).then(function () { A.toast('Sent to NeonDigital'); aDeliverable(box, id); }).catch(A.fail);
			};
			box.querySelectorAll('[data-d]').forEach(function (b) {
				b.onclick = function () {
					var dec = b.getAttribute('data-d');
					if (dec === 'approve') decide('approve', '');
					else A.ask(dec === 'reject' ? 'Reject deliverable' : 'Request a revision', 'Explain what needs to change', { ok: 'Send' }).then(function (c) { if (c !== null) decide(dec, c); });
				};
			});
		});
	}
	function aInvoices(box) {
		return api('agency/invoices').then(function (r) {
			box.innerHTML = '<section class="card card-pad"><p class="muted small">Invoices NeonDigital has sent you. This is a read-only copy: NeonDigital keeps the record, and payments update here automatically.</p>' + (r.items.length ? A.tbl([['No.', function (v) { return '<button class="link" data-inv="' + v.id + '"><b>' + esc(v.invoice_no) + '</b></button>'; }], ['Date', function (v) { return esc(A.fmtD(v.invoice_date)); }], ['Due', function (v) { return esc(A.fmtD(v.due_date)); }], ['Total', function (v) { return A.inr(v.total); }], ['Paid', function (v) { return A.inr(v.amount_paid); }], ['Balance', function (v) { return A.inr(v.balance); }], ['Status', function (v) { return A.badge(v.status, v.status_label); }], ['', function (v) { return '<button class="btn btn-sm" data-pdf="' + v.id + '" data-n="' + esc(v.invoice_no) + '">PDF</button>'; }]], r.items) : A.empty('No invoices yet', 'Invoices appear here when NeonDigital sends them to you.')) + '</section>';
			box.querySelectorAll('[data-inv]').forEach(function (b) { b.onclick = function () { api('agency/invoices/' + b.getAttribute('data-inv')).then(function (v) {
				A.modal({ title: 'Invoice ' + v.invoice_no, body: '<p class="muted small">Issued ' + esc(A.fmtD(v.invoice_date)) + ', due ' + esc(A.fmtD(v.due_date)) + (v.package_name ? ', ' + esc(v.package_name) : '') + '. Read-only.</p>' + A.tbl([['Item', function (l) { return esc(l.description); }], ['Amount', function (l) { return A.inr(l.amount); }]], v.lines) + '<dl class="totals"><dt>Subtotal</dt><dd>' + A.inr(v.subtotal) + '</dd>' + (+v.discount ? '<dt>Discount</dt><dd>' + A.inr(v.discount) + '</dd>' : '') + (+v.tax_amount ? '<dt>' + esc(v.tax_label || 'Tax') + '</dt><dd>' + A.inr(v.tax_amount) + '</dd>' : '') + '<dt class="b">Total</dt><dd class="b">' + A.inr(v.total) + '</dd><dt>Paid</dt><dd>' + A.inr(v.amount_paid) + '</dd><dt class="b">Balance</dt><dd class="b">' + A.inr(v.balance) + '</dd></dl>' + A.badge(v.status, v.status_label) });
			}).catch(A.fail); }; });
			box.querySelectorAll('[data-pdf]').forEach(function (b) { b.onclick = function () { A.download('agency/invoices/' + b.getAttribute('data-pdf') + '/pdf', b.getAttribute('data-n').replace(/[^A-Za-z0-9_-]/g, '-') + '.pdf'); }; });
		});
	}

	/* ---- Billing ---- */
	function featureTable(list) {
		return A.tbl([
			['Feature', function (f) { return esc(f.label); }],
			['Access', function (f) { return f.coming_soon ? A.badge('coming soon') : f.enabled ? A.badge(f.state) : '<span class="muted small">' + esc(f.cta) + '</span>'; }],
			['Ends', function (f) { return f.enabled && f.expires_on ? esc(A.fmtD(f.expires_on)) : ''; }]
		], list);
	}
	/* Project subscriptions, kept apart from anything NeonDigital bills as an agency. */
	function projectBilling(b) {
		var open = (b.invoices || []);
		if (!open.length) return '';
		return '<section class="card"><div class="card-h"><h2>Project subscriptions</h2></div><div class="card-b">' + open.map(function (i) { return '<div class="row"><div class="grow"><b>' + esc(i.doc_no) + '</b> <span class="muted small">' + esc(A.fmtD(i.period_start)) + ' to ' + esc(A.fmtD(i.period_end)) + '</span></div><span>' + A.inr(i.amount) + '</span>' + A.badge(i.status) + (i.status === 'issued' && A.can('billing.manage') ? '<button class="btn btn-sm btn-primary" data-pay="' + i.id + '">Pay</button>' : '') + '</div>'; }).join('') + '</div></section>';
	}
	/* Each Workspace has its own subscription, data and dates, so buying one changes nothing in another. */
	function moreWorkspaces(b) {
		if (!A.can('billing.manage')) return '';
		var ws = S.boot.workspaces || [];
		return '<section class="card card-pad"><h2>Your Workspaces</h2>' + ws.map(function (w) { return '<div class="row"><div class="grow"><b>' + esc(w.name) + '</b>' + (+w.id === +S.boot.workspace.id ? ' <span class="muted small">this one</span>' : '') + '</div><span class="muted small">' + (w.account_type === 'agency' ? 'NeonDigital' : 'Subscription') + '</span></div>'; }).join('') + '<button class="btn" data-more-ws style="margin-top:10px">Add another Workspace</button></section>';
	}
	function bindMoreWorkspaces(el, b) {
		var btn = el.querySelector('[data-more-ws]');
		if (!btn) return;
		btn.onclick = function () {
			var plans = (b.plans || []).filter(function (p) { return !+p.is_trial; });
			var label = function (p) { return p.name + ' · ' + (p.price === null && p.annual_price === null ? 'Price on request' : (p.price !== null ? A.inr(p.price) + ' a month' : A.inr(p.annual_price) + ' a year')); };
			var m = A.modal({ title: 'Add another Workspace', body: '<p class="muted small">It gets its own plan, billing, projects, calendar, settings and data. This Workspace does not change.</p><label class="field"><span>Workspace name</span><input data-n></label><label class="field"><span>Plan</span><select data-p>' + plans.map(function (p) { return '<option value="' + esc(p.code) + '">' + esc(label(p)) + '</option>'; }).join('') + '</select></label><label class="field"><span>Billing</span><select data-c><option value="month">Monthly</option><option value="year">Yearly</option></select></label>', foot: '<button class="btn btn-primary" data-y>Continue to payment</button>' });
			m.el.querySelector('[data-y]').onclick = function () {
				api('workspaces', { method: 'POST', body: { name: m.el.querySelector('[data-n]').value, kind: 'business', plan_code: m.el.querySelector('[data-p]').value, cycle: m.el.querySelector('[data-c]').value, timezone: S.boot.workspace.timezone } }).then(function (r) {
					try { localStorage.setItem('nw-workspace', r.id); } catch (x) {}
					location.hash = '#/billing';
					location.reload();
				}).catch(A.fail);
			};
		};
	}

	V.billing = function (el) {
		var agency = S.boot.workspace.account_type === 'agency';
		A.setTitle(agency ? 'Plan & Access' : 'Subscription & Billing');
		return api('billing').then(function (b) {
			if (agency) {
				el.innerHTML = '<div class="stack"><section class="card card-pad"><h2>Managed by NeonDigital</h2><p class="muted">NeonDigital manages your package, features and billing. Contact NeonDigital to unlock or renew anything here.</p>' + (S.boot.agency ? '<p>Package: ' + A.badge(S.boot.agency.package_state || 'active') + (S.boot.agency.package_end ? ' <span class="muted small">to ' + esc(A.fmtD(S.boot.agency.package_end)) + '</span>' : '') + '</p>' : '') + '</section>' + projectBilling(b) + moreWorkspaces(b) + '<section class="card card-pad"><h2>Features</h2>' + featureTable(b.features) + '</section></div>';
				bindMoreWorkspaces(el, b);
				var again = function () { return A.reloadBoot().then(function () { V.billing(el); }); };
				el.querySelectorAll('[data-pay]').forEach(function (x) { x.onclick = function () { pay(+x.getAttribute('data-pay'), b.gateway, again); }; });
				return;
			}
			var s = b.subscription, owner = A.can('billing.manage'), price = function (p) { if (+p.is_trial) return (+p.trial_days || 0) + '-day free trial'; if (p.price === null && p.annual_price === null) return 'Price on request'; return (p.price !== null ? A.inr(p.price) + ' a month' : '') + (p.price !== null && p.annual_price !== null ? ' · ' : '') + (p.annual_price !== null ? A.inr(p.annual_price) + ' a year' : ''); };
			el.innerHTML = '<div class="stack">' + (b.gateway.simulated ? '<div class="alert alert-warn"><b>Simulated payments are on.</b> No money moves and nothing here counts as revenue.</div>' : '') +
				(s ? '<section class="card card-pad"><h2>Your plan</h2><p><b>' + esc(b.plan ? b.plan.name : s.plan_code) + '</b> ' + A.badge(s.status) + '</p><p class="muted small">' + (s.period_end ? (s.state === 'locked' ? 'Ended ' : 'Current period ends ') + esc(A.fmtD(s.period_end)) : '') + (s.pending_plan_code ? ' · changes to ' + esc(s.pending_plan_code) + ' at the end of the period' : '') + '</p>' + (s.state === 'locked' ? '<div class="alert alert-bad">Paid features are locked. Your data is safe and comes back as soon as you renew.</div>' : s.state === 'grace' ? '<div class="alert alert-warn">Your plan has ended. Renew soon to keep access.</div>' : '') + '</section>' : '') +
				'<section class="card" data-focus="plans"><div class="card-h"><h2>Plans</h2></div><div class="card-b">' + (b.plans.map(function (p) { return '<div class="row"><div class="grow"><b>' + esc(p.name) + '</b><div class="muted small">' + esc(p.description) + '<br>' + esc(price(p)) + '</div></div>' + (s && s.plan_code === p.code ? A.badge('current') : (owner && !+p.is_trial && p.price !== null ? '<button class="btn btn-sm" data-plan="' + esc(p.code) + '">Choose monthly</button> ' : '') + (owner && !+p.is_trial && p.annual_price !== null ? '<button class="btn btn-sm" data-plan="' + esc(p.code) + '" data-cycle="year">Choose yearly</button>' : '')) + '</div>'; }).join('') || '<p class="muted">No plans are published yet.</p>') + '</div></section>' +
				(b.addons.length ? '<section class="card" data-focus="addons"><div class="card-h"><h2>Add-ons</h2></div><div class="card-b">' + b.addons.map(function (a) { return '<div class="row"><div class="grow"><b>' + esc(a.name) + '</b><div class="muted small">' + esc(a.price === null ? 'Price not set' : A.inr(a.price)) + ' · ' + (+a.duration_days) + ' days</div></div>' + (owner && a.price !== null ? '<button class="btn btn-sm" data-addon="' + esc(a.code) + '">Add</button>' : '') + '</div>'; }).join('') + '</div></section>' : '') +
				'<section class="card"><div class="card-h"><h2>Subscription invoices</h2></div><div class="card-b">' + (b.invoices.length ? A.tbl([['No.', function (i) { return '<b>' + esc(i.doc_no) + '</b>'; }], ['For', function (i) { return esc(i.purpose.replace('_', ' ')); }], ['Amount', function (i) { return A.inr(i.amount); }], ['Status', function (i) { return A.badge(i.status); }], ['', function (i) { return i.status === 'issued' && owner ? '<button class="btn btn-sm btn-primary" data-pay="' + i.id + '">Pay</button>' : ''; }]], b.invoices) : '<p class="muted">None yet.</p>') + '</div></section>' +
				(b.payments.length ? '<section class="card"><div class="card-h"><h2>Payment history</h2></div><div class="card-b">' + b.payments.map(function (p) { return '<div class="row"><div class="grow">' + A.inr(p.amount) + ' <span class="muted small">' + esc(p.gateway) + (+p.is_real ? '' : ' (simulated, not real money)') + '</span></div>' + A.badge(p.status) + '<span class="muted small">' + esc(A.fmtDT(p.created_at)) + '</span></div>'; }).join('') + '</div></section>' : '') +
				moreWorkspaces(b) + '<section class="card card-pad"><h2>Features</h2>' + featureTable(b.features) + '</section></div>';
			bindMoreWorkspaces(el, b);
			var focus = el.querySelector('[data-focus="' + (A.query.focus || '') + '"]') || (A.query.focus ? el.querySelector('[data-focus=plans]') : null);
			if (focus) focus.scrollIntoView();
			var reload = function () { return A.reloadBoot().then(function () { V.billing(el); }); };
			el.querySelectorAll('[data-plan]').forEach(function (x) {
				x.onclick = function () { api('billing/change-plan', { method: 'POST', body: { plan_code: x.getAttribute('data-plan'), cycle: x.getAttribute('data-cycle') || 'month' } }).then(function (r) { A.toast(r.scheduled ? 'This change applies at the end of your period.' : 'Invoice created. Pay it to switch.'); reload(); }).catch(A.fail); };
			});
			el.querySelectorAll('[data-addon]').forEach(function (x) {
				x.onclick = function () { api('billing/addons', { method: 'POST', body: { code: x.getAttribute('data-addon') } }).then(function () { A.toast('Invoice created.'); reload(); }).catch(A.fail); };
			});
			el.querySelectorAll('[data-pay]').forEach(function (x) { x.onclick = function () { pay(+x.getAttribute('data-pay'), b.gateway, reload); }; });
		});
	};

	function pay(id, gw, reload) {
		if (!gw.online) {
			A.modal({ title: 'How to pay', body: '<p>Online payment is not switched on yet. Please pay by bank transfer and tell NeonDigital the reference. Your access starts once they record it.</p>' });
			return;
		}
		api('billing/invoices/' + id + '/checkout', { method: 'POST', body: {} }).then(function (c) {
			if (c.gateway === 'simulated') {
				var m = A.modal({ title: 'Simulated payment', body: '<div class="alert alert-warn"><b>' + esc(c.checkout.label) + '</b></div><p>This only tries the flow. It is recorded as simulated and never counts as revenue.</p>', foot: '<button class="btn btn-primary" data-y>Simulate success</button>' });
				m.el.querySelector('[data-y]').onclick = function () {
					api('billing/invoices/' + id + '/verify', { method: 'POST', body: { order_id: c.order_id, payment_id: c.checkout.payment_id, signature: c.checkout.signature } }).then(function () { m.close(); A.toast('Simulated payment applied'); reload(); }).catch(A.fail);
				};
				return;
			}
			if (window.Razorpay) { checkoutRazorpay(id, c, reload); return; }
			var s = document.createElement('script');
			s.src = 'https://checkout.razorpay.com/v1/checkout.js';
			s.onload = function () { checkoutRazorpay(id, c, reload); };
			s.onerror = function () { A.toast('Could not load the payment window.', true); };
			document.head.appendChild(s);
		}).catch(A.fail);
	}
	function checkoutRazorpay(id, c, reload) {
		new window.Razorpay({
			key: c.checkout.key, order_id: c.checkout.order_id, amount: c.checkout.amount, currency: c.checkout.currency, name: c.checkout.name || 'Neon Workspace', description: c.checkout.description || '', theme: { color: '#8B5CF6' },
			handler: function (resp) { api('billing/invoices/' + id + '/verify', { method: 'POST', body: resp }).then(function () { A.toast('Payment received'); reload(); }).catch(A.fail); },
			modal: { ondismiss: function () { A.toast('Payment cancelled. Nothing was charged by us.'); } }
		}).open();
	}

	/* ---- Modules, integrations: honest labels, no fake controls ---- */
	V.automations = function (el) {
		A.setTitle('Automations');
		el.innerHTML = '<section class="card locked"><h2>Automations</h2><p class="muted">Automations are not available yet. Nothing here runs in the background.</p><span class="badge">COMING SOON</span></section>';
	};
	V.integrations = function (el) {
		A.setTitle('Integrations');
		var cards = [['Meta Business', 'Connect your Meta business account.'], ['WhatsApp Business API', 'Official WhatsApp messaging.'], ['Instagram', 'Your Instagram account.'], ['Facebook', 'Your Facebook page.'], ['Meta Ads', 'Your ad campaigns.'], ['Neon WhatsApp', 'NeonDigital\'s WhatsApp product. Separate subscription and billing; your WhatsApp data stays on Neon WhatsApp.']];
		el.innerHTML = '<div class="grid">' + cards.map(function (c) { return '<div class="card card-pad"><b>' + esc(c[0]) + '</b><p class="muted small">' + esc(c[1]) + '</p><span class="badge">COMING SOON</span></div>'; }).join('') + '</div>';
	};

	/* ---- Notifications ---- */
	V.notifications_single = function (el) {
		A.setTitle('Notifications');
		return api('notifications').then(function (r) {
			el.innerHTML = '<div class="toolbar"><span class="grow"></span><button class="btn btn-sm" data-all>Mark all read</button></div><section class="card card-pad">' + (r.items.length ? r.items.map(function (n) { return '<div class="row"><div class="grow">' + (n.read_at ? '' : '<b>') + esc(n.title) + (n.read_at ? '' : '</b>') + (n.body ? '<div class="muted small">' + esc(n.body) + '</div>' : '') + '<div class="muted small">' + esc(A.fmtDT(n.created_at)) + '</div></div>' + (/^#\//.test(n.link || '') ? '<a class="btn btn-sm" href="' + esc(n.link) + '">Open</a>' : '') + '</div>'; }).join('') : A.empty('You are all caught up', '')) + '</section>';
			el.querySelector('[data-all]').onclick = function () { api('notifications/read', { method: 'POST', body: {} }).then(function () { A.setUnread(0); V.notifications_single(el); }).catch(A.fail); };
		});
	};

	/* ---- Settings ---- */
	V.settings = function (el) {
		A.setTitle('Settings');
		var w = S.boot.workspace, personal = A.isPersonal(), mgr = A.can('settings.manage') && !personal;
		var pref = document.documentElement.getAttribute('data-theme-pref');
		try { pref = localStorage.getItem('nw-theme') || pref; } catch (e) {}
		var u = S.boot.user;
		el.innerHTML = '<div class="stack">' +
			'<section class="card card-pad" id="account"><h2>Your account picture</h2><div class="pic-row"><span class="pic pic-round" data-apic>' + A.avatarHtml(u.avatar_url, u.name) + '</span><div><p class="muted small">Shown in the account menu and to your team. It belongs to you, not to a Workspace. JPG, PNG or WebP, up to 2 MB.</p><label class="btn btn-sm">' + (u.avatar_url ? 'Change picture' : 'Upload picture') + '<input type="file" data-aup accept="image/png,image/jpeg,image/webp" hidden></label>' + (u.avatar_url ? ' <button class="btn btn-sm btn-ghost" data-arm>Remove</button>' : '') + '</div></div></section>' +
			(mgr ? '<section class="card card-pad"><h2>Workspace</h2><div class="pic-row"><span class="pic" data-wpic>' + A.wsAvatar(w, false) + '</span><div><p class="muted small">This Workspace\'s logo: the switcher, dashboard, invoices, quotations and booking pages use it. Each Workspace has its own; your other Workspaces and your account picture do not change.</p><label class="btn btn-sm">' + (w.logo_url ? 'Change logo' : 'Upload logo') + '<input type="file" data-wup accept="image/png,image/jpeg,image/webp" hidden></label>' + (w.logo_url ? ' <button class="btn btn-sm btn-ghost" data-wrm>Remove</button>' : '') + '</div></div><div class="form-grid">' + A.fieldHtml({ k: 'name', label: 'Name' }, w.name) + A.fieldHtml({ k: 'timezone', label: 'Timezone' }, w.timezone) + '</div><button class="btn btn-primary" data-sw>Save</button></section>' : '') +
			'<section class="card card-pad"><h2>Install the app</h2><div data-inst></div></section>' +
			(!personal ? '<section class="card card-pad"><h2>How your data is handled</h2><p class="muted small">NeonDigital runs Neon Workspace, so this Workspace\'s data is processed on its platform to provide, secure, back up and support the service. That processing is part of the service, not an option. NeonDigital staff open data only when needed for support, security, troubleshooting or backups, under role-based, least-privilege access. ' + (w.managed_by_agency ? 'Because NeonDigital manages this Workspace for you, it also receives its leads and bookings for that work.' : 'This Workspace is yours: NeonDigital\'s agency team has no access to it.') + ' Your Personal area stays private. Details: <a href="' + esc(window.NW.home) + 'privacy-policy/" target="_blank" rel="noopener">Privacy Policy</a>.</p></section>' : '') +
			'<section class="card card-pad" data-np></section>' +
			'<section class="card card-pad"><h2>Communication and legal</h2><label class="check"><input type="checkbox" data-mkt> <span>Send me product news and offers. <small class="muted">Optional. You can change this at any time. Service and security messages still reach you.</small></span></label><p class="small"><a href="' + esc(window.NW.home) + 'terms-and-conditions/" target="_blank" rel="noopener">Terms &amp; Conditions</a> · <a href="' + esc(window.NW.home) + 'privacy-policy/" target="_blank" rel="noopener">Privacy Policy</a></p></section>' +
			'<section class="card card-pad"><h2>Sign-in and security</h2><div data-sec>' + A.loading(2) + '</div></section>' +
				(mgr ? '<section class="card card-pad"><h2>Record retention</h2><div data-ret>' + A.loading(2) + '</div></section>' : '') +
			'<section class="card card-pad"><h2>' + (S.boot.personal ? 'Personal and your Workspaces' : 'Your Workspaces') + '</h2>' + (S.boot.personal ? '<div class="row"><div class="grow">Personal <span class="muted small">private, free for NeonDigital clients</span></div>' + (personal ? A.badge('current') : '<button class="btn btn-sm" data-sws="' + S.boot.personal.id + '">Switch</button>') + '</div>' : '') + S.boot.workspaces.map(function (x) { return '<div class="row"><div class="grow">' + esc(x.name) + ' <span class="muted small">' + esc(S.boot.roles[x.role]) + '</span></div>' + (+x.id === +w.id ? A.badge('current') : '<button class="btn btn-sm" data-sws="' + x.id + '">Switch</button>') + '</div>'; }).join('') + '<button class="btn btn-sm" data-addws style="margin-top:10px">' + A.I.plus + ' Add Workspace</button></section>' +
			(A.can('audit.read') ? '<details class="card fold" data-audit-fold><summary>Admin and security log</summary><div class="card-b"><p class="muted small">Sign-ins, billing and administration. Everyday changes are in <a href="#/activity">Activity</a>.</p><div data-audit>' + A.loading(3) + '</div></div></details>' : '') +
			'<section class="card card-pad"><p class="muted small">Signed in as ' + esc(S.boot.user.email) + '</p><button class="btn" data-out>Sign out</button></section></div>';
		var inst = el.querySelector('[data-inst]');
		var standalone = window.matchMedia && matchMedia('(display-mode: standalone)').matches;
		if (standalone) inst.innerHTML = '<p class="muted">You are using the installed app.</p>';
		else if (S.installPrompt) {
			inst.innerHTML = '<button class="btn btn-primary">Install Neon Workspace</button>';
			inst.firstChild.onclick = function () { S.installPrompt.prompt(); };
		} else if (/iphone|ipad|ipod/i.test(navigator.userAgent)) inst.innerHTML = '<p class="muted">In Safari, tap Share, then Add to Home Screen.</p>';
		else inst.innerHTML = '<p class="muted">Use your browser\'s menu and choose Install app or Add to Home screen.</p>';
		var sw = el.querySelector('[data-sw]');
		if (sw) sw.onclick = function () {
			api('workspace', { method: 'PUT', body: { name: el.querySelector('[name=name]').value, timezone: el.querySelector('[name=timezone]').value } }).then(function () { A.toast('Saved'); return A.reloadBoot(); }).catch(A.fail);
		};
		var mkt = el.querySelector('[data-mkt]');
		api('me/marketing').then(function (r) { mkt.checked = !!r.granted; }).catch(function () {});
		mkt.onchange = function () {
			api('me/marketing', { method: 'PUT', body: { granted: mkt.checked } }).then(function () { A.toast(mkt.checked ? 'You will receive product news and offers' : 'You will no longer receive product news and offers'); }).catch(function (x) { mkt.checked = !mkt.checked; A.fail(x); });
		};
		// Pictures: checked and re-encoded on the server; the page reloads its header after a change.
		var upload = function (input, path, done) {
			input.onchange = function () {
				var f = input.files[0];
				if (!f) return;
				if (f.size > 2 * 1024 * 1024) { A.toast('Images can be up to 2 MB.', true); input.value = ''; return; }
				if (!/^image\/(png|jpeg|webp)$/.test(f.type)) { A.toast('Use a JPG, PNG or WebP image.', true); input.value = ''; return; }
				var fd = new FormData();
				fd.append('file', f);
				fetch(window.NW.root + path, { method: 'POST', body: fd, credentials: 'same-origin', headers: { 'X-WP-Nonce': window.NW.nonce, 'X-NW-Workspace': S.workspaceId } }).then(function (x) {
					return x.json().then(function (d) { if (!x.ok) throw new Error(d.message || 'Upload failed.'); done(d); });
				}).catch(A.fail);
			};
		};
		var aup = el.querySelector('[data-aup]'), arm = el.querySelector('[data-arm]'), wup = el.querySelector('[data-wup]'), wrm = el.querySelector('[data-wrm]');
		var refresh = function (msg) { A.toast(msg); A.reloadBoot().then(function () { location.reload(); }); };
		if (aup) upload(aup, 'me/avatar', function () { refresh('Account picture saved'); });
		if (arm) arm.onclick = function () { api('me/avatar', { method: 'DELETE' }).then(function () { refresh('Account picture removed'); }).catch(A.fail); };
		if (wup) upload(wup, 'workspace/logo', function () { refresh('Workspace logo saved'); });
		if (wrm) wrm.onclick = function () { api('workspace/logo', { method: 'DELETE' }).then(function () { refresh('Workspace logo removed'); }).catch(A.fail); };
		el.querySelectorAll('[data-sws]').forEach(function (b) { b.onclick = function () { A.switchTo(+b.getAttribute('data-sws')); }; });
		var adw = el.querySelector('[data-addws]');
		if (adw) adw.onclick = A.addWorkspace;
		if (location.hash.indexOf('#account') > -1) { var ac = el.querySelector('#account'); if (ac) ac.scrollIntoView(); }
		A.notifyPrefsCard(el.querySelector('[data-np]'));
		A.settingsExtras(el);
		el.querySelector('[data-out]').onclick = A.logout;
		var au = el.querySelector('[data-audit]');
		if (au) api('audit').then(function (r) { au.innerHTML = A.collapseActivity(r.items).slice(0, 40).map(function (a) { return A.activityRow(a, esc(a.who)); }).join('') || '<p class="muted">Nothing yet.</p>'; }).catch(function (e) { au.innerHTML = '<p class="muted">' + esc(e.message) + '</p>'; });
	};

	A.route(/^\/agency(?:\/([a-z]+))?(?:\/(\d+))?$/, 'agency');
	A.route(/^\/billing$/, 'billing');
	A.route(/^\/automations$/, 'automations');
	A.route(/^\/integrations$/, 'integrations');
	A.route(/^\/notifications$/, 'notifications');
	A.route(/^\/settings$/, 'settings');

	A.start();
})();
