/* Public forms: conditional fields, submission. The server re-checks everything. */
(function () {
	'use strict';
	var f = document.getElementById('pub-form');
	if (!f) return;
	var C = window.NW, err = f.querySelector('.form-error'), btn = f.querySelector('[type=submit]');
	var key = Date.now().toString(36) + Math.random().toString(36).slice(2, 12);
	function value(name) {
		var els = f.querySelectorAll('[name="' + name + '"]');
		if (!els.length) return '';
		if (els[0].type === 'radio') { var c = f.querySelector('[name="' + name + '"]:checked'); return c ? c.value : ''; }
		if (els[0].type === 'checkbox') return els[0].checked ? 'Yes' : 'No';
		return els[0].value;
	}
	function apply() {
		f.querySelectorAll('[data-if]').forEach(function (d) { d.hidden = value(d.getAttribute('data-if')) !== d.getAttribute('data-eq'); });
	}
	f.addEventListener('input', apply);
	f.addEventListener('change', apply);
	apply();
	f.addEventListener('submit', function (e) {
		e.preventDefault();
		err.hidden = true;
		btn.disabled = true;
		var fd = new FormData();
		fd.append('idempotency_key', key);
		fd.append('website', f.website.value);
		f.querySelectorAll('.ff').forEach(function (d) {
			if (d.hidden) return;
			d.querySelectorAll('[name]').forEach(function (i) {
				if (i.type === 'file') { if (i.files[0]) fd.append(i.name, i.files[0]); }
				else if (i.type === 'checkbox') { if (i.checked) fd.append(i.name, '1'); }
				else if (i.type === 'radio') { if (i.checked) fd.append(i.name, i.value); }
				else fd.append(i.name, i.value);
			});
		});
		fetch(C.root + 'public/forms/' + f.getAttribute('data-slug'), { method: 'POST', body: fd }).then(function (r) {
			return r.json().catch(function () { return {}; }).then(function (d) { if (!r.ok) throw new Error(d.message || 'Something went wrong. Please try again.'); return d; });
		}).then(function (d) {
			var done = document.getElementById('pub-done');
			done.textContent = d.message;
			done.hidden = false;
			f.hidden = true;
		}).catch(function (x) {
			err.textContent = x.message;
			err.hidden = false;
			btn.disabled = false;
		});
	});
})();
