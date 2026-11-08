/* Sign-in, registration and invitation forms. */
(function () {
	'use strict';
	var C = window.NW || {};

	function post(path, body) {
		var h = { 'Content-Type': 'application/json' };
		if (C.nonce) h['X-WP-Nonce'] = C.nonce;
		return fetch(C.root + path, { method: 'POST', credentials: 'same-origin', headers: h, body: JSON.stringify(body) }).then(function (r) {
			return r.json().catch(function () { return {}; }).then(function (d) {
				// Only messages this product wrote reach the page. Raw platform errors never do.
				if (!r.ok) throw new Error(/^(nwc|ndwd)_/.test(String(d.code || '')) && d.message ? d.message : 'Something went wrong. Please try again, or contact NeonDigital support.');
				return d;
			});
		});
	}
	function bind(form, send) {
		if (!form) return;
		var err = form.querySelector('.form-error'), btn = form.querySelector('[type=submit]');
		form.addEventListener('submit', function (e) {
			e.preventDefault();
			err.hidden = true;
			btn.disabled = true;
			var d = {};
			new FormData(form).forEach(function (v, k) { d[k] = v; });
			send(d).catch(function (x) {
				err.textContent = x.message;
				err.hidden = false;
				btn.disabled = false;
			});
		});
	}

	var auth = document.getElementById('auth');
	if (auth) {
		bind(auth, function (d) {
			var mode = auth.getAttribute('data-mode');
			if (mode === 'login') d.remember = !!d.remember;
			if (mode === 'register') {
				if (!/^(?=.*[a-z])(?=.*[A-Z])(?=.*[0-9]).{6,}$/.test(d.password || '')) return Promise.reject(new Error('Use at least 6 characters, with an uppercase letter, a lowercase letter and a number.'));
				d.accept_legal = !!d.accept_legal;
				if (!d.accept_legal) return Promise.reject(new Error('Please confirm you have read and agree to the Privacy Policy and Terms & Conditions.'));
				d.marketing_consent = !!d.marketing_consent;
			}
			if (C.plan) { d.plan = C.plan; d.cycle = C.cycle; }
			return post('auth/' + mode, d).then(function () { location.href = C.next || C.home; });
		});
	}
	var join = document.getElementById('join');
	if (join) {
		bind(join, function (d) {
			d.token = join.getAttribute('data-token');
			d.accept_legal = !!d.accept_legal;
			if (!d.accept_legal) return Promise.reject(new Error('Please confirm you have read and agree to the Privacy Policy and Terms & Conditions.'));
			d.marketing_consent = !!d.marketing_consent;
			try { d.timezone = Intl.DateTimeFormat().resolvedOptions().timeZone; } catch (e) {}
			return post('agency/accept', d).then(function (r) {
				try { sessionStorage.setItem('nw-ctx', r.workspace_id); } catch (e) {}
				location.href = C.app;
			});
		});
	}
	var joinNew = document.getElementById('join-new');
	if (joinNew) {
		bind(joinNew, function (d) {
			d.token = joinNew.getAttribute('data-token');
			d.accept_legal = !!d.accept_legal;
			d.marketing_consent = !!d.marketing_consent;
			if (!/^(?=.*[a-z])(?=.*[A-Z])(?=.*[0-9]).{6,}$/.test(d.password || '')) return Promise.reject(new Error('Use at least 6 characters, with an uppercase letter, a lowercase letter and a number.'));
			if (d.password !== d.password2) return Promise.reject(new Error('The two passwords do not match.'));
			if (!d.accept_legal) return Promise.reject(new Error('Please confirm you have read and agree to the Privacy Policy and Terms & Conditions.'));
			try { d.timezone = Intl.DateTimeFormat().resolvedOptions().timeZone; } catch (e) {}
			return post('agency/accept-new', d).then(function (r) {
				try { sessionStorage.setItem('nw-ctx', r.workspace_id); } catch (e) {}
				location.href = C.app;
			});
		});
	}
	var team = document.getElementById('team-join');
	if (team) {
		bind(team, function () {
			return post('team-invite/accept', { token: team.getAttribute('data-token') }).then(function (r) {
				try { sessionStorage.setItem('nw-ctx', r.workspace_id); } catch (e) {}
				location.href = C.app;
			});
		});
	}
})();
