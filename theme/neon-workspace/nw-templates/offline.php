<?php
/** Cached by the service worker. Holds no customer data. */
defined( 'ABSPATH' ) || exit;
nw_head( 'Offline · Neon Workspace' );
?>
</head>
<body class="nw-public nw-auth">
<main class="auth-card card center">
	<?php nw_brand(); ?>
	<h1>You are offline</h1>
	<p class="muted">Neon Workspace needs a connection to show your records. Nothing is lost. Reconnect and try again.</p>
	<button class="btn btn-primary btn-block" onclick="location.href='<?php echo esc_js( home_url( '/app/' ) ); ?>'">Try again</button>
</main>
</body></html>
