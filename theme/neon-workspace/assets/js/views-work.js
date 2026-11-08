/* Projects, tasks, CRM, team, notes and goals. */
(function () {
	'use strict';
	var A = window.App, S = A.S, api = A.api, esc = A.esc, V = A.views;

	function tbl(cols, rows, rowAttr) {
		return '<div class="table-wrap"><table class="tbl stackable"><thead><tr>' + cols.map(function (c) { return '<th>' + esc(c[0]) + '</th>'; }).join('') + '</tr></thead><tbody>' + rows.map(function (r) {
			return '<tr' + (rowAttr ? ' ' + rowAttr(r) : '') + '>' + cols.map(function (c) { return '<td data-th="' + esc(c[0]) + '">' + c[1](r) + '</td>'; }).join('') + '</tr>';
		}).join('') + '</tbody></table></div>';
	}
	A.tbl = tbl;

	/* ---- Projects ---- */
	A.projectForm = function (p, done) {
		var editing = !!p.id;
		A.formModal({
			title: editing ? 'Edit project' : 'Create project', values: p,
			fields: [
				{ k: 'name', label: 'Name', required: true, span: true },
				{ k: 'parent_id', label: 'Main project', type: 'select', hint: 'choose one to make this a sub-project', opts: '' },
				{ k: 'status', label: 'Status', type: 'select', opts: A.options([['active', 'Active'], ['completed', 'Completed'], ['archived', 'Archived']], p.status || 'active') },
				{ k: 'color', label: 'Colour', type: 'color', value: p.color || '#7547DF' },
				{ k: 'start_date', label: 'Start', type: 'date' }, { k: 'end_date', label: 'End', type: 'date' },
				{ k: 'description', label: 'Description', type: 'textarea', span: true }
			],
			del: editing ? function () { return api('projects/' + p.id, { method: 'DELETE' }).then(function () { A.go('/projects'); }); } : null,
			ready: function (md) {
				var sel = md.el.querySelector('[name=parent_id]'), field = sel.closest('.field');
				if (editing && +p.sub_count) { field.hidden = true; return; } // A project with sub-projects stays a main project.
				sel.innerHTML = '<option value="">None, this is a main project</option>';
				api('projects', { params: { top: 1, status: 'active', per_page: 200 } }).then(function (r) {
					sel.insertAdjacentHTML('beforeend', r.items.filter(function (x) { return +x.id !== +p.id; }).map(function (x) { return '<option value="' + x.id + '"' + (+x.id === +p.parent_id ? ' selected' : '') + '>' + esc(x.name) + '</option>'; }).join(''));
				}).catch(function () { field.hidden = true; });
			},
			onSave: function (v) {
				v.parent_id = v.parent_id ? +v.parent_id : 0;
				return api('projects' + (editing ? '/' + p.id : ''), { method: editing ? 'PUT' : 'POST', body: v }).then(function (r) { A.toast('Saved'); if (done) done(r); else A.go('/projects/' + r.id); if (!done && editing) A.render(); });
			}
		});
	};

	V.projects = function (el) {
		A.setTitle('Projects');
		if (!A.gate(el, 'projects')) return;
		var status = 'active';
		el.innerHTML = '<div class="toolbar"><div class="tabs" style="margin:0;padding:0" data-s><button class="on" data-v="active">Active</button><button data-v="completed">Completed</button><button data-v="archived">Archived</button></div><span class="grow"></span>' + (A.can('projects.write') ? '<button class="btn btn-primary" data-new>Create project</button>' : '') + '</div><div class="grid" data-l>' + A.loading(4) + '</div>';
		var n = el.querySelector('[data-new]');
		if (n) n.onclick = function () { A.projectForm({}); };
		if (S.boot.agency && A.can('agency.use')) {
			api('agency/projects').then(function (r) {
				var l = el.querySelector('[data-l]');
				if (!r.items.length || !l) return;
				var s = document.createElement('section');
				s.className = 'card card-pad';
				s.style.marginBottom = '14px';
				var subsOf = function (id) { return r.items.filter(function (x) { return +x.parent_id === +id; }); };
				var line = function (p, sub) { return '<a class="row' + (sub ? ' row-sub' : '') + '" href="#/agency/projects/' + p.id + '"><div class="grow"><b>' + (p.locked ? '🔒 ' : '') + A.esc(p.name) + '</b>' + (p.locked ? '<div class="muted small">' + A.esc(p.access.message) + '</div>' : '') + '</div>' + (p.locked ? '' : A.badge(p.status)) + '</a>'; };
				s.innerHTML = '<h2>From NeonDigital</h2>' + r.items.filter(function (p) { return !p.parent_id || !r.items.some(function (x) { return +x.id === +p.parent_id; }); }).map(function (p) { return line(p, false) + subsOf(p.id).map(function (c) { return line(c, true); }).join(''); }).join('');
				l.parentNode.insertBefore(s, l);
			}).catch(function () {});
		}
		var load = function () {
			return api('projects', { params: { status: status, top: 1 } }).then(function (r) {
				var box = el.querySelector('[data-l]');
				if (box) box.innerHTML = r.items.length ? r.items.map(function (p) {
					return '<a class="card card-pad proj-card" href="#/projects/' + p.id + '"><div class="proj-top"><b>' + esc(p.name) + '</b>' + (p.color ? '<i class="proj-dot" style="background:' + esc(p.color) + '"></i>' : '') + '</div><div class="muted small">' + (+p.task_done) + ' of ' + (+p.task_total) + ' tasks done' + (+p.sub_count ? ' · ' + (+p.sub_count) + ' sub-project' + (+p.sub_count === 1 ? '' : 's') : '') + '</div><div class="bar"><i style="width:' + (+p.progress) + '%"></i></div>' + (p.end_date ? '<div class="muted small">Ends ' + esc(A.fmtD(p.end_date)) + '</div>' : '') + '</a>';
				}).join('') : A.empty('No projects here', A.can('projects.write') ? 'Create one to get started.' : '');
			});
		};
		el.querySelector('[data-s]').onclick = function (e) {
			var b = e.target.closest('[data-v]');
			if (!b) return;
			status = b.getAttribute('data-v');
			el.querySelectorAll('[data-v]').forEach(function (x) { x.classList.toggle('on', x === b); });
			load().catch(function (x) { A.viewError(el, x); });
		};
		return load();
	};

	V.project = function (el, m) {
		var id = +m[1];
		if (!A.gate(el, 'projects')) return;
		return api('projects/' + id).then(function (p) {
			A.setTitle(p.name);
			var tabs = [['tasks', 'Tasks']].concat(p.parent_id ? [] : [['subs', 'Sub-projects' + (+p.sub_count ? ' (' + (+p.sub_count) + ')' : '')]]).concat([['calendar', 'Calendar'], ['milestones', 'Milestones'], ['members', 'Members'], ['files', 'Files'], ['discussion', 'Discussion'], ['history', 'History']]);
			el.innerHTML = '<div class="toolbar"><a class="link" href="#/projects">← Projects</a>' + (p.parent_id ? '<a class="link" href="#/projects/' + p.parent_id + '" data-parent>Main project</a>' : '') + '<span class="grow"></span>' + A.badge(p.status) + (A.can('projects.write') ? '<button class="btn btn-sm" data-edit>Edit</button>' : '') + '</div>' + (p.description ? '<p class="muted">' + esc(p.description) + '</p>' : '') +
				'<div class="bar" style="margin-bottom:12px"><i style="width:' + (+p.progress) + '%"></i></div><div class="tabs" data-tabs>' + tabs.map(function (t, i) { return '<button data-t="' + t[0] + '" class="' + (i ? '' : 'on') + '">' + t[1] + '</button>'; }).join('') + '</div><div data-c></div>';
			var e = el.querySelector('[data-edit]');
			if (e) e.onclick = function () { A.projectForm(p, function () { A.render(); }); };
			var box = el.querySelector('[data-c]');
			var show = function (t) {
				box.innerHTML = '<div class="card card-pad">' + A.loading(3) + '</div>';
				var r = { tasks: pTasks, subs: pSubs, calendar: pCal, milestones: pMiles, members: pMembers, files: pFiles, discussion: pDisc, history: pHist }[t](box, p);
				if (r && r.catch) r.catch(function (x) { A.viewError(box, x); });
			};
			el.querySelector('[data-tabs]').onclick = function (ev) {
				var b = ev.target.closest('[data-t]');
				if (!b) return;
				el.querySelectorAll('[data-t]').forEach(function (x) { x.classList.toggle('on', x === b); });
				show(b.getAttribute('data-t'));
			};
			show('tasks');
			var want = +((A.query || {}).task || 0);
			if (want) api('tasks/' + want).then(function (t) { A.taskForm(t, function () { show('tasks'); }); }).catch(function () {});
		});
	};

	function pSubs(box, p) {
		var load = function () {
			return api('projects', { params: { parent_id: p.id, per_page: 200 } }).then(function (r) {
				box.innerHTML = '<section class="card card-pad">' + (A.can('projects.write') ? '<div class="toolbar"><span class="muted small">Sub-projects share this project\'s Workspace and team.</span><span class="grow"></span><button class="btn btn-sm btn-primary" data-ns>Add sub-project</button></div>' : '') +
					(r.items.length ? r.items.map(function (s) { return '<a class="row" href="#/projects/' + s.id + '"><div class="grow"><b>' + esc(s.name) + '</b><div class="muted small">' + (+s.task_done) + ' of ' + (+s.task_total) + ' tasks done</div></div>' + A.badge(s.status) + '</a>'; }).join('') : A.empty('No sub-projects', 'Split this project into smaller parts.')) + '</section>';
				var n = box.querySelector('[data-ns]');
				if (n) n.onclick = function () { A.projectForm({ parent_id: p.id }, function () { load(); }); };
			});
		};
		return load();
	}

	function taskRows(items, reload) {
		return items.length ? items.map(function (t) {
			var ck = t.checklist || [], done = ck.filter(function (c) { return c.done; }).length;
			return '<div class="row"><input type="checkbox" data-done="' + t.id + '"' + (t.status === 'done' ? ' checked' : '') + ' aria-label="Mark ' + esc(t.title) + ' done"' + (A.can('tasks.write') ? '' : ' disabled') + '><div class="grow" data-open="' + t.id + '" style="cursor:pointer">' + (t.status === 'done' ? '<s>' + esc(t.title) + '</s>' : esc(t.title)) + '<div class="muted small">' + (t.assignee_id ? esc(A.memberName(t.assignee_id)) + ' · ' : '') + (t.due_date ? esc(A.fmtD(t.due_date)) : 'No date') + (ck.length ? ' · ' + done + '/' + ck.length : '') + '</div></div>' + (t.priority === 'high' || t.priority === 'urgent' ? A.badge(t.priority) : '') + A.badge(t.status) + '</div>';
		}).join('') : A.empty('No tasks', A.can('tasks.write') ? 'Add the first one.' : '');
	}
	function bindTasks(root, items, reload) {
		root.onclick = function (e) {
			var o = e.target.closest('[data-open]');
			if (o) A.taskForm(items.filter(function (t) { return t.id == o.getAttribute('data-open'); })[0], reload); // eslint-disable-line eqeqeq
		};
		root.onchange = function (e) {
			var id = e.target.getAttribute('data-done');
			if (id) api('tasks/' + id, { method: 'PUT', body: { status: e.target.checked ? 'done' : 'todo' } }).then(reload).catch(A.fail);
		};
	}

	A.taskForm = function (t, done) {
		var editing = !!t.id;
		var ck = (t.checklist || []).slice();
		api(A.can('projects.read') ? 'projects' : 'me').then(function (r) {
			var fields = [
				{ k: 'title', label: 'Title', required: true, span: true },
				{ k: 'project_id', label: 'Project', type: 'select', opts: A.options(r.items || [], t.project_id, 'No project') },
				{ k: 'assignee_id', label: 'Assign to', type: 'select', opts: A.memberOptions(t.assignee_id, 'Nobody') },
				{ k: 'status', label: 'Status', type: 'select', opts: A.options([['todo', 'To do'], ['in_progress', 'In progress'], ['review', 'Review'], ['done', 'Done']], t.status || 'todo') },
				{ k: 'priority', label: 'Priority', type: 'select', opts: A.options([['low', 'Low'], ['medium', 'Medium'], ['high', 'High'], ['urgent', 'Urgent']], t.priority || 'medium') },
				{ k: 'due_date', label: 'Deadline', type: 'date' },
				{ k: 'description', label: 'Details', type: 'textarea', span: true }
			];
			A.formModal({
				title: editing ? 'Edit task' : 'Add task', fields: fields, values: t, extra: '<b class="small">Checklist</b><div data-ck></div><div class="toolbar"><input data-ckn placeholder="Add an item" class="grow" aria-label="New checklist item"><button type="button" class="btn btn-sm" data-cka>Add</button></div>',
				del: editing ? function () { return api('tasks/' + t.id, { method: 'DELETE' }).then(done || A.render); } : null,
				ready: function (m) {
					var box = m.el.querySelector('[data-ck]');
					var paint = function () {
						box.innerHTML = ck.map(function (c, i) { return '<div class="row"><input type="checkbox" data-i="' + i + '"' + (c.done ? ' checked' : '') + ' aria-label="' + esc(c.text) + '"><div class="grow">' + esc(c.text) + '</div><button type="button" class="btn btn-sm" data-rm="' + i + '" aria-label="Remove">×</button></div>'; }).join('');
					};
					paint();
					box.addEventListener('change', function (e) { var i = e.target.getAttribute('data-i'); if (i !== null) ck[+i].done = e.target.checked; });
					box.addEventListener('click', function (e) { var i = e.target.getAttribute('data-rm'); if (i !== null) { ck.splice(+i, 1); paint(); } });
					m.el.querySelector('[data-cka]').onclick = function () {
						var n = m.el.querySelector('[data-ckn]');
						if (n.value.trim()) { ck.push({ text: n.value.trim(), done: false }); n.value = ''; paint(); }
					};
				},
				onSave: function (v) {
					v.checklist = ck;
					return api('tasks' + (editing ? '/' + t.id : ''), { method: editing ? 'PUT' : 'POST', body: v }).then(function () { A.toast('Saved'); (done || A.render)(); });
				}
			});
		}).catch(A.fail);
	};

	function pTasks(box, p) {
		var load = function () {
			return api('tasks', { params: { project_id: p.id } }).then(function (r) {
				box.innerHTML = '<section class="card card-pad">' + (A.can('tasks.write') ? '<div class="toolbar"><button class="btn btn-sm btn-primary" data-add>Add task</button></div>' : '') + '<div data-tl>' + taskRows(r.items) + '</div></section>';
				var a = box.querySelector('[data-add]');
				if (a) a.onclick = function () { A.taskForm({ project_id: p.id }, load); };
				bindTasks(box.querySelector('[data-tl]'), r.items, load);
			});
		};
		return load();
	}
	function pCal(box, p) {
		if (!A.gate(box, 'calendar_project')) return;
		return A.calendarInto(box, { fixed: 'project:' + p.id, projectId: p.id });
	}
	function pMiles(box, p) {
		var load = function () {
			return api('milestones', { params: { project_id: p.id } }).then(function (r) {
				box.innerHTML = '<section class="card card-pad">' + (A.can('projects.write') ? '<div class="toolbar"><button class="btn btn-sm btn-primary" data-add>Add milestone</button></div>' : '') + (r.items.length ? r.items.map(function (x) {
					return '<div class="row"><input type="checkbox" data-m="' + x.id + '"' + (+x.done ? ' checked' : '') + (A.can('projects.write') ? '' : ' disabled') + ' aria-label="' + esc(x.title) + ' done"><div class="grow">' + esc(x.title) + '</div><span class="muted small">' + esc(A.fmtD(x.due_date)) + '</span></div>';
				}).join('') : A.empty('No milestones yet', '')) + '</section>';
				var a = box.querySelector('[data-add]');
				if (a) a.onclick = function () {
					A.formModal({ title: 'Add milestone', fields: [{ k: 'title', label: 'Title', required: true }, { k: 'due_date', label: 'Date', type: 'date' }], onSave: function (v) { v.project_id = p.id; return api('milestones', { method: 'POST', body: v }).then(load); } });
				};
				box.onchange = function (e) { var i = e.target.getAttribute('data-m'); if (i) api('milestones/' + i, { method: 'PUT', body: { done: e.target.checked } }).catch(A.fail); };
			});
		};
		return load();
	}
	function pMembers(box, p) {
		return api('projects/' + p.id + '/members').then(function (r) {
			var can = A.can('projects.write');
			box.innerHTML = '<section class="card card-pad">' + S.boot.members.map(function (m) { return '<label class="check"><input type="checkbox" value="' + m.id + '"' + (r.user_ids.indexOf(+m.id) > -1 ? ' checked' : '') + (can ? '' : ' disabled') + '> ' + esc(m.name) + ' <small class="muted">' + esc(S.boot.roles[m.role] || m.role) + '</small></label>'; }).join('') + (can ? '<button class="btn btn-primary" data-s>Save members</button>' : '') + '</section>';
			var s = box.querySelector('[data-s]');
			if (s) s.onclick = function () {
				var ids = Array.prototype.map.call(box.querySelectorAll('input:checked'), function (i) { return +i.value; });
				api('projects/' + p.id + '/members', { method: 'PUT', body: { user_ids: ids } }).then(function () { A.toast('Saved'); }).catch(A.fail);
			};
		});
	}
	function pFiles(box, p) {
		var load = function () {
			return api('files', { params: { entity: 'project', entity_id: p.id } }).then(function (r) {
				box.innerHTML = '<section class="card card-pad">' + (A.can('tasks.write') ? '<label class="btn btn-sm btn-primary" style="margin-bottom:10px">Upload<input type="file" hidden data-f></label>' : '') + (r.items.length ? r.items.map(function (f) { return '<div class="row"><div class="grow">' + esc(f.name) + '<div class="muted small">' + Math.ceil(f.size / 1024) + ' KB</div></div><button class="btn btn-sm" data-d="' + f.id + '" data-n="' + esc(f.name) + '">Download</button></div>'; }).join('') : A.empty('No reference files', 'Images, PDFs, documents and ZIP files up to the upload limit.')) + '</section>';
				var inp = box.querySelector('[data-f]');
				if (inp) inp.onchange = function () {
					var fd = new FormData();
					fd.append('file', inp.files[0]);
					fetch(window.NW.root + 'files?entity=project&entity_id=' + p.id, { method: 'POST', body: fd, credentials: 'same-origin', headers: { 'X-WP-Nonce': window.NW.nonce, 'X-NW-Workspace': S.workspaceId } }).then(function (x) { return x.json().then(function (d) { if (!x.ok) throw new Error(d.message); }); }).then(function () { A.toast('Uploaded'); load(); }).catch(A.fail);
				};
				box.querySelectorAll('[data-d]').forEach(function (b) { b.onclick = function () { A.download('files/' + b.getAttribute('data-d') + '/download', b.getAttribute('data-n')); }; });
			});
		};
		return load();
	}
	function pDisc(box, p) {
		var load = function () {
			return api('comments', { params: { entity: 'project', entity_id: p.id } }).then(function (r) {
				box.innerHTML = '<section class="card card-pad">' + (r.items.map(function (c) { return '<p><b>' + esc(c.author) + '</b> <span class="muted small">' + esc(A.fmtDT(c.created_at)) + '</span><br>' + esc(c.body) + '</p>'; }).join('') || '<p class="muted">No comments yet.</p>') + (A.can('tasks.write') ? '<label class="field"><span>Add a comment</span><textarea data-b rows="2"></textarea></label><button class="btn btn-primary" data-s>Post</button>' : '') + '</section>';
				var s = box.querySelector('[data-s]');
				if (s) s.onclick = function () { api('comments', { method: 'POST', body: { entity: 'project', entity_id: p.id, body: box.querySelector('[data-b]').value } }).then(load).catch(A.fail); };
			});
		};
		return load();
	}
	function pHist(box, p) {
		return api('history', { params: { entity: 'project', entity_id: p.id } }).then(function (r) {
			var rows = A.collapseActivity(r.items);
			box.innerHTML = '<section class="card card-pad">' + (rows.slice(0, 20).map(function (h) { return A.activityRow(h, esc(h.who)); }).join('') || A.empty('No history yet', '')) + (rows.length > 20 ? '<p class="small" style="margin:10px 0 0"><a href="#/activity?entity=projects&entity_id=' + p.id + '">See older records</a></p>' : '') + '</section>';
		});
	}

	/* ---- Tasks ---- */
	V.tasks = function (el) {
		A.setTitle('Tasks');
		if (!A.gate(el, 'projects')) return;
		var f = { status: '', assignee_id: '' };
		el.innerHTML = '<div class="toolbar"><select data-f="status" aria-label="Status">' + A.options([['todo', 'To do'], ['in_progress', 'In progress'], ['review', 'Review'], ['done', 'Done']], '', 'All statuses') + '</select><select data-f="assignee_id" aria-label="Assignee">' + A.memberOptions('', 'Anyone') + '</select><input type="search" data-q class="grow" placeholder="Search" aria-label="Search tasks">' + (A.can('tasks.write') ? '<button class="btn btn-primary" data-add>Add task</button>' : '') + '</div><section class="card card-pad" data-l>' + A.loading(5) + '</section>';
		var box = el.querySelector('[data-l]');
		if (S.boot.agency && A.can('agency.use')) {
			api('agency/tasks').then(function (r) {
				if (!r.items.length || !box.isConnected) return;
				var s = document.createElement('section');
				s.className = 'card card-pad';
				s.style.marginBottom = '14px';
				s.innerHTML = '<h2>From NeonDigital WorkDesk</h2>' + r.items.slice(0, 20).map(function (t) { return '<a class="row" href="#/agency/tasks/' + t.id + '"><div class="grow"><b>' + A.esc(t.title) + '</b><div class="muted small">' + (t.due_date ? 'Due ' + A.esc(A.fmtD(t.due_date)) : '') + (t.assignees && t.assignees.length ? ' · ' + A.esc(t.assignees.join(', ')) : '') + (t.team ? ' · ' + A.esc(t.team) : '') + '</div></div>' + A.badge(t.status, t.status_label) + '</a>'; }).join('');
				box.parentNode.insertBefore(s, box);
			}).catch(function () {});
		}
		var load = function () {
			return api('tasks', { params: f }).then(function (r) { box.innerHTML = taskRows(r.items); bindTasks(box, r.items, load); });
		};
		var want = +((A.query || {}).task || 0);
		if (want) api('tasks/' + want).then(function (t) { A.taskForm(t, load); }).catch(function () {});
		el.querySelectorAll('[data-f]').forEach(function (i) { i.onchange = function () { f[i.getAttribute('data-f')] = i.value; load().catch(A.fail); }; });
		var deb;
		el.querySelector('[data-q]').oninput = function (e) { clearTimeout(deb); deb = setTimeout(function () { f.q = e.target.value; load().catch(A.fail); }, 300); };
		var a = el.querySelector('[data-add]');
		if (a) a.onclick = function () { A.taskForm({}, load); };
		return load();
	};

	/* ---- CRM ---- */
	A.customerForm = function (c, done) {
		var editing = !!c.id;
		A.formModal({
			title: editing ? 'Edit customer' : 'Add customer', values: c,
			fields: [
				{ k: 'name', label: 'Name', required: true }, { k: 'business_name', label: 'Business name' }, { k: 'contact_person', label: 'Contact person' }, { k: 'phone', label: 'Phone', type: 'tel' }, { k: 'email', label: 'Email', type: 'email' }, { k: 'assigned_to', label: 'Assigned to', type: 'select', opts: A.memberOptions(c.assigned_to, 'Nobody') },
				{ k: 'gstin', label: 'GSTIN', hint: 'if applicable' }, { k: 'state_code', label: 'GST state code', hint: 'for example 27' },
				{ k: 'billing_address', label: 'Billing address', type: 'textarea' }, { k: 'shipping_address', label: 'Shipping address', type: 'textarea' }, { k: 'notes', label: 'Notes', type: 'textarea', span: true },
				{ k: 'marketing_consent', label: 'Agreed to receive marketing', type: 'check', span: true, hint: 'only tick with their agreement' }
			],
			del: editing ? function () { return api('customers/' + c.id, { method: 'DELETE' }).then(done || A.render); } : null,
			onSave: function (v) { return api('customers' + (editing ? '/' + c.id : ''), { method: editing ? 'PUT' : 'POST', body: v }).then(function (r) { A.toast('Saved'); (done || A.render)(r); }); }
		});
	};
	A.leadForm = function (l, done) {
		var editing = !!l.id;
		A.formModal({
			title: editing ? 'Edit lead' : 'Add lead', values: l,
			fields: [
				{ k: 'name', label: 'Name', required: true }, { k: 'phone', label: 'Phone', type: 'tel' }, { k: 'email', label: 'Email', type: 'email' },
				{ k: 'stage', label: 'Stage', type: 'select', opts: A.options([['new', 'New'], ['contacted', 'Contacted'], ['interested', 'Interested'], ['converted', 'Converted'], ['lost', 'Lost']], l.stage || 'new') },
				{ k: 'enquiry', label: 'Enquiry', span: true }, { k: 'source', label: 'Source', value: 'manual' }, { k: 'follow_up_on', label: 'Follow up on', type: 'date' }, { k: 'assigned_to', label: 'Assigned to', type: 'select', opts: A.memberOptions(l.assigned_to, 'Nobody') },
				{ k: 'notes', label: 'Notes', type: 'textarea', span: true }, { k: 'marketing_consent', label: 'Agreed to receive marketing', type: 'check', span: true }
			],
			del: editing ? function () { return api('leads/' + l.id, { method: 'DELETE' }).then(done || A.render); } : null,
			onSave: function (v) { return api('leads' + (editing ? '/' + l.id : ''), { method: editing ? 'PUT' : 'POST', body: v }).then(function () { A.toast('Saved'); (done || A.render)(); }); }
		});
	};

	V.crm = function (el, m) {
		A.setTitle('Customers & Leads');
		if (!A.gate(el, 'crm')) return;
		var tab = m[1] || 'customers';
		el.innerHTML = '<div class="tabs"><a href="#/crm/customers" class="' + (tab === 'customers' ? 'on' : '') + '">Customers</a><a href="#/crm/leads" class="' + (tab === 'leads' ? 'on' : '') + '">Leads</a></div><div class="toolbar">' + (tab === 'leads' ? '<select data-stage aria-label="Stage">' + A.options([['new', 'New'], ['contacted', 'Contacted'], ['interested', 'Interested'], ['converted', 'Converted'], ['lost', 'Lost']], '', 'All stages') + '</select>' : '') + '<input type="search" class="grow" data-q placeholder="Search name, phone or email" aria-label="Search">' + (A.can('crm.export') ? '<button class="btn btn-sm" data-exp>Export</button>' : '') + (A.can('crm.write') ? '<button class="btn btn-primary" data-add>Add ' + (tab === 'leads' ? 'lead' : 'customer') + '</button>' : '') + '</div><section class="card card-pad" data-l>' + A.loading(5) + '</section>';
		var f = {}, box = el.querySelector('[data-l]');
		var load = function () {
			return api(tab, { params: f }).then(function (r) {
				if (!r.items.length) { box.innerHTML = A.empty('Nothing here yet', tab === 'leads' ? 'Leads from your booking pages and enquiry forms appear here.' : 'Add a customer, or take a booking.'); return; }
				box.innerHTML = tab === 'leads' ? tbl([
					['Name', function (x) { return '<b>' + esc(x.name) + '</b>'; }], ['Contact', function (x) { return esc(x.phone || x.email); }], ['Enquiry', function (x) { return esc(x.enquiry); }], ['Source', function (x) { return esc(String(x.source).replace(/_/g, ' ')); }], ['Stage', function (x) { return A.badge(x.stage); }], ['Follow up', function (x) { return x.follow_up_on ? esc(A.fmtD(x.follow_up_on)) : ''; }]
				], r.items, function (x) { return 'class="click" data-o="' + x.id + '"'; }) : tbl([
					['Name', function (x) { return '<b>' + esc(x.name) + '</b>' + (x.business_name ? '<div class="muted small">' + esc(x.business_name) + '</div>' : ''); }], ['Phone', function (x) { return esc(x.phone); }], ['Email', function (x) { return esc(x.email); }], ['Source', function (x) { return esc(String(x.source).replace(/_/g, ' ')); }]
				], r.items, function (x) { return 'class="click" data-o="' + x.id + '"'; });
				box.onclick = function (e) {
					var o = e.target.closest('[data-o]');
					if (o) contactDrawer(tab, r.items.filter(function (x) { return +x.id === +o.getAttribute('data-o'); })[0], load);
				};
			});
		};
		var st = el.querySelector('[data-stage]');
		if (st) st.onchange = function () { f.stage = st.value; load().catch(A.fail); };
		var deb;
		el.querySelector('[data-q]').oninput = function (e) { clearTimeout(deb); deb = setTimeout(function () { f.q = e.target.value; load().catch(A.fail); }, 300); };
		var a = el.querySelector('[data-add]');
		if (a) a.onclick = function () { (tab === 'leads' ? A.leadForm : A.customerForm)({}, load); };
		var x = el.querySelector('[data-exp]');
		if (x) x.onclick = function () {
			api('crm/export', { params: { what: tab } }).then(function (r) {
				var l = document.createElement('a');
				l.href = URL.createObjectURL(new Blob([r.csv], { type: 'text/csv' }));
				l.download = r.filename;
				l.click();
			}).catch(A.fail);
		};
		return load();
	};

	function contactDrawer(tab, x, reload) {
		var type = tab === 'leads' ? 'lead' : 'customer';
		var m = A.modal({ title: x.name, size: 'lg', body: A.loading(4), foot: A.can('crm.write') ? (type === 'customer' && A.has('customer_portal') ? '<button class="btn" data-portal>Portal link</button>' : '') + '<button class="btn" data-e>Edit</button>' : '' });
		var pb = m.el.querySelector('[data-portal]');
		if (pb) pb.onclick = function () { api('customers/' + x.id + '/portal-link', { method: 'POST', body: {} }).then(function (r) { A.copyText(r.url, 'Link copied. It works for ' + r.expires_days + ' days.'); }).catch(A.fail); };
		var e = m.el.querySelector('[data-e]');
		if (e) e.onclick = function () { m.close(); (type === 'lead' ? A.leadForm : A.customerForm)(x, reload); };
		var reqs = [api('crm/activities', { params: { entity: type, entity_id: x.id } })];
		if (type === 'customer') reqs.push(api('crm/customers/' + x.id + '/history'));
		Promise.all(reqs).then(function (r) {
			var h = r[1];
			m.body.innerHTML = '<p>' + esc(x.phone) + (x.phone && x.email ? ' · ' : '') + esc(x.email) + '</p>' + (x.notes ? '<p class="muted">' + esc(x.notes) + '</p>' : '') +
				(h ? '<h3>Bookings</h3>' + (h.bookings.length ? h.bookings.map(function (b) { return '<div class="row"><div class="grow">' + esc(A.fmtDT(b.starts_at)) + '</div>' + A.badge(b.status) + '</div>'; }).join('') : '<p class="muted">None.</p>') + (A.can('invoices.read') ? '<h3>Invoices</h3>' + (h.invoices.length ? h.invoices.map(function (i) { return '<div class="row"><a class="grow" href="#/invoices/' + i.id + '">' + esc(i.doc_no) + '</a><span>' + A.inr(i.grand_total) + '</span>' + A.badge(i.status) + '</div>'; }).join('') : '<p class="muted">None.</p>') : '') : '') +
				'<h3>Activity</h3>' + (r[0].items.map(function (a) { return '<div class="row"><div class="grow">' + esc(a.note) + '</div><span class="muted small">' + esc(A.fmtDT(a.created_at)) + '</span></div>'; }).join('') || '<p class="muted">No activity yet.</p>') +
				(A.can('crm.write') ? '<label class="field"><span>Add a note</span><textarea data-n rows="2"></textarea></label><button class="btn btn-sm" data-ns>Save note</button>' : '');
			var ns = m.body.querySelector('[data-ns]');
			if (ns) ns.onclick = function () { api('crm/activities', { method: 'POST', body: { entity: type, entity_id: x.id, note: m.body.querySelector('[data-n]').value } }).then(function () { m.close(); contactDrawer(tab, x, reload); }).catch(A.fail); };
		}).catch(function (err) { m.body.innerHTML = '<p class="form-error">' + esc(err.message) + '</p>'; });
	}

	/* ---- Team ---- */
	V.team = function (el) {
		A.setTitle('Team');
		if (!A.gate(el, 'team_management')) return;
		return api('team').then(function (r) {
			var mgr = A.can('team.manage');
			el.innerHTML = '<div class="stack"><section class="card"><div class="card-h"><h2>Members</h2>' + (mgr ? '<button class="btn btn-sm btn-primary" data-inv>Invite</button>' : '') + '</div><div class="card-b">' + r.members.map(function (m) {
				return '<div class="row"><div class="grow"><b>' + esc(m.name) + '</b><div class="muted small">' + esc(m.job_title || '') + (m.email ? ' ' + esc(m.email) : '') + '</div></div>' + A.badge(m.role, S.boot.roles[m.role]) + (mgr && m.role !== 'owner' ? '<button class="btn btn-sm" data-m="' + m.id + '">Edit</button>' : '') + ((mgr || +m.id === +S.boot.user.id) ? '<button class="btn btn-sm" data-av="' + m.id + '">Hours</button>' : '') + '</div>';
			}).join('') + '</div></section>' +
				(r.invites && r.invites.length ? '<section class="card"><div class="card-h"><h2>Open invitations</h2></div><div class="card-b">' + r.invites.map(function (i) { return '<div class="row"><div class="grow">' + esc(i.email) + '<div class="muted small">' + esc(S.boot.roles[i.role]) + '</div></div><button class="btn btn-sm" data-rv="' + i.id + '">Revoke</button></div>'; }).join('') + '</div></section>' : '') +
				(r.activity && r.activity.length ? '<details class="card fold"><summary>Recent team activity</summary><div class="card-b">' + A.collapseActivity(r.activity).slice(0, 5).map(function (a) { return A.activityRow(a, esc(A.memberName(a.user_id) || 'Someone')); }).join('') + '<p class="small" style="margin:10px 0 0"><a href="#/activity">See all activity</a></p></div></details>' : '') + '</div>';
			var inv = el.querySelector('[data-inv]');
			var roles = Object.keys(S.boot.roles).filter(function (k) { return k !== 'owner' && (k !== 'admin' || S.boot.role === 'owner'); }).map(function (k) { return [k, S.boot.roles[k]]; });
			if (inv) inv.onclick = function () {
				A.formModal({
					title: 'Invite a team member', ok: 'Create invitation', fields: [{ k: 'email', label: 'Email', type: 'email', required: true, span: true }, { k: 'role', label: 'Role', type: 'select', span: true, opts: A.options(roles, 'member') }],
					onSave: function (v) {
						return api('team/invite', { method: 'POST', body: v }).then(function (x) {
							A.modal({ title: 'Invitation link', body: '<p class="muted small">Send this link to them yourself. It works once, for that email address, for 14 days.</p><input readonly value="' + esc(x.url) + '" onclick="this.select()">' });
							V.team(el);
						});
					}
				});
			};
			el.querySelectorAll('[data-rv]').forEach(function (b) { b.onclick = function () { api('team/invites/' + b.getAttribute('data-rv'), { method: 'DELETE' }).then(function () { V.team(el); }).catch(A.fail); }; });
			el.querySelectorAll('[data-m]').forEach(function (b) {
				b.onclick = function () {
					var m = r.members.filter(function (x) { return +x.id === +b.getAttribute('data-m'); })[0];
					A.formModal({
						title: m.name, values: m, fields: [{ k: 'role', label: 'Role', type: 'select', opts: A.options(roles, m.role) }, { k: 'job_title', label: 'Job title' }, { k: 'phone', label: 'Phone', type: 'tel' }],
						del: function () { return api('team/' + m.id, { method: 'DELETE' }).then(function () { return A.reloadBoot(); }).then(function () { V.team(el); }); },
						onSave: function (v) { return api('team/' + m.id, { method: 'PUT', body: v }).then(function () { return A.reloadBoot(); }).then(function () { V.team(el); }); }
					});
				};
			});
			el.querySelectorAll('[data-av]').forEach(function (b) { b.onclick = function () { availability(r.members.filter(function (x) { return +x.id === +b.getAttribute('data-av'); })[0]); }; });
		});
	};

	/* Working hours editor, used for staff and for the business. */
	A.hoursEditor = function (h) {
		var days = [['mon', 'Mon'], ['tue', 'Tue'], ['wed', 'Wed'], ['thu', 'Thu'], ['fri', 'Fri'], ['sat', 'Sat'], ['sun', 'Sun']];
		h = h || {};
		return days.map(function (d) {
			var r = (h[d[0]] || [])[0];
			return '<div class="row" data-day="' + d[0] + '"><label class="check" style="margin:0;min-width:80px"><input type="checkbox" data-open' + (r ? ' checked' : '') + '> ' + d[1] + '</label><input type="time" data-from value="' + esc(r ? r[0] : '09:00') + '" aria-label="' + d[1] + ' from" style="flex:1;min-width:0"><input type="time" data-to value="' + esc(r ? r[1] : '18:00') + '" aria-label="' + d[1] + ' to" style="flex:1;min-width:0"></div>';
		}).join('');
	};
	A.readHours = function (root) {
		var o = {};
		root.querySelectorAll('[data-day]').forEach(function (row) {
			o[row.getAttribute('data-day')] = row.querySelector('[data-open]').checked ? [[row.querySelector('[data-from]').value, row.querySelector('[data-to]').value]] : [];
		});
		return o;
	};
	function availability(m) {
		var md = A.modal({ title: 'Hours for ' + m.name, body: '<p class="muted small">These narrow the business hours for bookings with this person. Leave a day unticked if they are off.</p><div data-h>' + A.hoursEditor(m.hours || {}) + '</div><h3 style="margin-top:12px">Break</h3><div data-b>' + A.hoursEditor(m.breaks || {}) + '</div>', foot: '<button class="btn btn-primary" data-s>Save</button>' });
		md.el.querySelector('[data-s]').onclick = function () {
			api('staff/' + m.id + '/availability', { method: 'PUT', body: { hours: A.readHours(md.el.querySelector('[data-h]')), breaks: A.readHours(md.el.querySelector('[data-b]')) } }).then(function () { A.toast('Saved'); md.close(); }).catch(A.fail);
		};
	}

	/* ---- Notes and goals ---- */
	A.noteForm = function (n, done) {
		var editing = !!n.id;
		A.formModal({
			title: editing ? 'Edit note' : 'Add note', values: { title: n.title, body: n.body, is_private: n.id ? +n.is_private : 1, pinned: +n.pinned },
			fields: [{ k: 'title', label: 'Title', span: true }, { k: 'body', label: 'Note', type: 'textarea', rows: 6, span: true }, { k: 'is_private', label: 'Private to me', type: 'check' }, { k: 'pinned', label: 'Pin', type: 'check' }],
			del: editing ? function () { return api('notes/' + n.id, { method: 'DELETE' }).then(done || A.render); } : null,
			onSave: function (v) { return api('notes' + (editing ? '/' + n.id : ''), { method: editing ? 'PUT' : 'POST', body: v }).then(function () { A.toast('Saved'); (done || A.render)(); }); }
		});
	};
	V.notes = function (el) {
		A.setTitle('Notes & Goals');
		return Promise.all([api('notes'), api('goals')]).then(function (r) {
			var me = +S.boot.user.id;
			el.innerHTML = '<div class="grid"><section class="card"><div class="card-h"><h2>Notes</h2><button class="btn btn-sm btn-primary" data-an>Add note</button></div><div class="card-b">' + (r[0].items.map(function (n) { return '<div class="row"' + (+n.user_id === me ? ' data-n="' + n.id + '" style="cursor:pointer"' : '') + '><div class="grow"><b>' + esc(n.title || 'Note') + '</b><div class="muted small">' + esc(String(n.body || '').slice(0, 120)) + '</div></div>' + (+n.is_private ? '' : A.badge('shared')) + '</div>'; }).join('') || A.empty('No notes yet', '')) + '</div></section>' +
				'<section class="card"><div class="card-h"><h2>Goals</h2><button class="btn btn-sm btn-primary" data-ag>Add goal</button></div><div class="card-b">' + (r[1].items.map(function (g) { return '<div class="row" data-g="' + g.id + '" style="cursor:pointer"><div class="grow"><b>' + esc(g.title) + '</b><div class="bar" style="margin-top:4px"><i style="width:' + Math.min(100, +g.progress) + '%"></i></div></div><span class="muted small">' + (+g.progress) + '%</span></div>'; }).join('') || A.empty('No goals yet', '')) + '</div></section></div>';
			var reload = function () { V.notes(el); };
			el.querySelector('[data-an]').onclick = function () { A.noteForm({}, reload); };
			var goal = function (g) {
				A.formModal({
					title: g.id ? 'Edit goal' : 'Add goal', values: g,
					fields: [{ k: 'title', label: 'Goal', required: true, span: true }, { k: 'target_date', label: 'Target date', type: 'date' }, { k: 'progress', label: 'Progress %', type: 'number', min: 0 }, { k: 'status', label: 'Status', type: 'select', opts: A.options([['active', 'Active'], ['done', 'Done'], ['dropped', 'Dropped']], g.status || 'active') }],
					del: g.id ? function () { return api('goals/' + g.id, { method: 'DELETE' }).then(reload); } : null,
					onSave: function (v) { return api('goals' + (g.id ? '/' + g.id : ''), { method: g.id ? 'PUT' : 'POST', body: v }).then(reload); }
				});
			};
			el.querySelector('[data-ag]').onclick = function () { goal({}); };
			el.querySelectorAll('[data-n]').forEach(function (x) { x.onclick = function () { A.noteForm(r[0].items.filter(function (n) { return +n.id === +x.getAttribute('data-n'); })[0], reload); }; });
			el.querySelectorAll('[data-g]').forEach(function (x) { x.onclick = function () { goal(r[1].items.filter(function (n) { return +n.id === +x.getAttribute('data-g'); })[0]); }; });
		});
	};

	A.route(/^\/projects$/, 'projects');
	A.route(/^\/projects\/(\d+)$/, 'project');
	A.route(/^\/tasks$/, 'tasks');
	A.route(/^\/crm(?:\/(customers|leads))?$/, 'crm');
	A.route(/^\/team$/, 'team');
	A.route(/^\/notes$/, 'notes');
})();
