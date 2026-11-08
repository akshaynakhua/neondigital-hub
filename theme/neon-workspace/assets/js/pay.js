/* Online payment of a shared business invoice. Paid status only changes after the server verifies it. */
(function () {
	'use strict';
	var b = document.getElementById('pay-online');
	if (!b) return;
	var base = window.NW.root + 'public/pay/' + b.getAttribute('data-id') + '/' + b.getAttribute('data-token');
	function post(path, body) {
		return fetch(base + path, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body || {}) }).then(function (r) {
			return r.json().catch(function () { return {}; }).then(function (d) { if (!r.ok) throw new Error(d.message || 'Something went wrong. Please try again.'); return d; });
		});
	}
	function open(c) {
		new window.Razorpay({
			key: c.key, order_id: c.order_id, amount: c.amount, currency: c.currency, name: c.name, description: c.description,
			handler: function (resp) { post('/verify', resp).then(function () { location.reload(); }).catch(function (e) { alert(e.message); b.disabled = false; }); },
			modal: { ondismiss: function () { b.disabled = false; } }
		}).open();
	}
	b.onclick = function () {
		b.disabled = true;
		post('/order').then(function (r) {
			if (window.Razorpay) { open(r.checkout); return; }
			var s = document.createElement('script');
			s.src = 'https://checkout.razorpay.com/v1/checkout.js';
			s.onload = function () { open(r.checkout); };
			s.onerror = function () { alert('Could not load the payment window.'); b.disabled = false; };
			document.head.appendChild(s);
		}).catch(function (e) { alert(e.message); b.disabled = false; });
	};
})();
