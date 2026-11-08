<?php
defined( 'ABSPATH' ) || exit;
nw_head( 'Not found', array( 'dark' => true ) );
?>
</head>
<body class="nw-public nw-auth">
<main class="auth-card card center">
	<h1>This page is not available</h1>
	<p class="muted">The link may be wrong, or it may no longer be active.</p>
	<p><a class="btn btn-primary" href="<?php echo esc_url( home_url( '/' ) ); ?>">Back to homepage</a></p>
</main>
</body></html>
