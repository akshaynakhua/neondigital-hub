<?php
/** A customer asks for a fresh portal link. The answer never reveals whether the email is known. */
defined( 'ABSPATH' ) || exit;
$ctx = $GLOBALS['nw_ctx'];
nw_head( 'Open your account' );
?>
</head>
<body class="nw-public nw-auth">
<main class="auth-card card">
	<h1>Open your account</h1>
	<p class="muted">Enter the email address you gave this business. We will send you a private link.</p>
	<form id="pa" novalidate>
		<label class="field"><span>Email</span><input name="email" type="email" autocomplete="email" required></label>
		<div class="form-error" role="alert" hidden></div>
		<button class="btn btn-primary btn-block" type="submit">Send my link</button>
	</form>
	<p id="pa-done" role="status" hidden></p>
</main>
<?php nw_config( $ctx['config'] ); ?>
<script>
(function () {
	var f = document.getElementById('pa'), C = window.NW, err = f.querySelector('.form-error');
	f.addEventListener('submit', function (e) {
		e.preventDefault();
		err.hidden = true;
		fetch(C.root + 'public/portal-access/' + C.slug, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ email: f.email.value }) }).then(function (r) {
			return r.json().then(function (d) { if (!r.ok) throw new Error(d.message); return d; });
		}).then(function (d) {
			var o = document.getElementById('pa-done');
			o.textContent = d.message;
			o.hidden = false;
			f.hidden = true;
		}).catch(function (x) { err.textContent = x.message; err.hidden = false; });
	});
})();
</script>
</body></html>
