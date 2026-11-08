<?php
/** Public explanation of NeonDigital Connect, and a way in for someone holding an invitation. */
defined( 'ABSPATH' ) || exit;
nw_head( 'NeonDigital Connect · Neon Workspace', array( 'index' => true, 'dark' => true, 'description' => 'How NeonDigital agency clients connect their Neon Workspace.' ) );
?>
</head>
<body class="nw-public nw-auth">
<main class="auth-card card">
	<a class="auth-home" href="<?php echo esc_url( home_url( '/' ) ); ?>" aria-label="Neon Workspace home"><?php nw_brand(); ?></a>
	<h1>NeonDigital Connect</h1>
	<p class="muted">Already working with NeonDigital? See your agency projects, client-visible tasks, approvals and deliverables alongside the rest of your workspace.</p>
	<p class="muted">NeonDigital sends each client a personal invitation link. Open it to set your password and connect. Paste the link here if you have it to hand.</p>
	<form id="connect-link" novalidate>
		<label class="field"><span>Your invitation link</span><input name="link" type="url" inputmode="url" autocomplete="off" required></label>
		<div class="form-error" role="alert" hidden></div>
		<button class="btn btn-primary btn-block" type="submit">Open my invitation</button>
	</form>
	<p class="muted center small">No invitation yet? <a href="<?php echo esc_url( apply_filters( 'nw_contact_url', 'https://neondigital.in' ) ); ?>" rel="noopener">Contact NeonDigital</a>, or <a href="<?php echo esc_url( home_url( '/pricing/' ) ); ?>">see Workspace plans</a>.</p>
	<?php nw_legal_links(); ?>
</main>
<script>
(function () {
	var f = document.getElementById('connect-link');
	f.addEventListener('submit', function (e) {
		e.preventDefault();
		var m = String(f.link.value).trim().match(/\/join\/([A-Za-z0-9_-]+)\/?(?:[?#].*)?$/);
		var err = f.querySelector('.form-error');
		if (!m) { err.textContent = 'That does not look like an invitation link. Please copy the whole link from your invitation.'; err.hidden = false; return; }
		location.href = <?php echo wp_json_encode( home_url( '/join/' ), JSON_UNESCAPED_SLASHES ); ?> + m[1];
	});
})();
</script>
</body></html>
