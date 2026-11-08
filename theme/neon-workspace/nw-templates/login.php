<?php
defined( 'ABSPATH' ) || exit;
$ctx  = $GLOBALS['nw_ctx'];
$plan = $ctx['plan'];
nw_head( 'Sign in · Neon Workspace', array( 'dark' => true ) );
?>
</head>
<body class="nw-public nw-auth">
<main class="auth-card card">
	<a class="auth-home" href="<?php echo esc_url( home_url( '/' ) ); ?>" aria-label="Neon Workspace home"><?php nw_brand(); ?></a>
	<h1>Welcome back</h1>
	<?php if ( $ctx['error'] ) : ?><p class="form-error" role="alert"><?php echo esc_html( $ctx['error'] ); ?></p><?php endif; ?>
	<?php nw_social_buttons( $ctx['social'], 'login', $plan ? $plan['code'] : '', $ctx['cycle'], $ctx['next_path'] ?? '' ); ?>
	<form id="auth" data-mode="login" novalidate>
		<label class="field"><span>Email</span><input name="email" type="email" autocomplete="username" required></label>
		<label class="field"><span>Password</span><input name="password" type="password" autocomplete="current-password" required></label>
		<label class="check"><input type="checkbox" name="remember" checked> Keep me signed in</label>
		<div class="form-error" role="alert" hidden></div>
		<button class="btn btn-primary btn-block" type="submit">Continue with Email</button>
	</form>
	<p class="muted center"><a href="<?php echo esc_url( wp_lostpassword_url() ); ?>">Forgot password?</a></p>
	<p class="muted center">New here? <a href="<?php echo esc_url( home_url( '/pricing/' ) ); ?>">View pricing</a></p>
	<?php nw_legal_links(); ?>
</main>
<?php nw_config( $ctx['config'] ); ?>
<script src="<?php echo esc_url( nw_asset( 'js/auth.js' ) ); ?>"></script>
</body></html>
