<?php
defined( 'ABSPATH' ) || exit;
$ctx  = $GLOBALS['nw_ctx'];
$plan = $ctx['plan'];
nw_head( 'Create your account · Neon Workspace', array( 'dark' => true ) );
?>
</head>
<body class="nw-public nw-auth">
<main class="auth-card card">
	<a class="auth-home" href="<?php echo esc_url( home_url( '/' ) ); ?>" aria-label="Neon Workspace home"><?php nw_brand(); ?></a>
	<h1>Create your account</h1>
	<?php if ( $plan ) : ?>
		<div class="plan-chip">
			<div><b><?php echo esc_html( $plan['name'] ); ?></b>
			<small class="muted"><?php
			if ( $plan['is_trial'] ) {
				echo (int) $plan['trial_days'] . '-day free trial';
			} else {
				$v = 'year' === $ctx['cycle'] ? $plan['annual'] : $plan['monthly'];
				echo null !== $v ? esc_html( nw_money( $v ) . ( 'year' === $ctx['cycle'] ? ' a year' : ' a month' ) ) : 'Price on request';
			}
			?></small></div>
			<a class="link" href="<?php echo esc_url( home_url( '/pricing/' ) ); ?>">Change</a>
		</div>
	<?php endif; ?>
	<?php if ( $ctx['error'] ) : ?><p class="form-error" role="alert"><?php echo esc_html( $ctx['error'] ); ?></p><?php endif; ?>
	<?php nw_social_buttons( $plan ? $ctx['social'] : array(), 'register', $plan ? $plan['code'] : '', $ctx['cycle'] ); ?>
	<form id="auth" data-mode="register" novalidate>
		<label class="field"><span>Your name</span><input name="name" autocomplete="name" required></label>
		<label class="field"><span>Email</span><input name="email" type="email" autocomplete="email" required></label>
		<label class="field"><span>Password <small class="muted">at least 6 characters, with an uppercase letter, a lowercase letter and a number</small></span><input name="password" type="password" autocomplete="new-password" minlength="6" pattern="(?=.*[a-z])(?=.*[A-Z])(?=.*[0-9]).{6,}" required></label>
		<?php nw_consent_boxes(); ?>
		<label class="hp" aria-hidden="true">Website <input name="website" tabindex="-1" autocomplete="off"></label>
		<div class="form-error" role="alert" hidden></div>
		<button class="btn btn-primary btn-block" type="submit">Continue with Email</button>
	</form>
	<p class="muted center">Already have an account? <a href="<?php echo esc_url( add_query_arg( array_filter( array( 'plan' => $plan ? $plan['code'] : '', 'cycle' => $plan ? $ctx['cycle'] : '' ) ), home_url( '/login/' ) ) ); ?>">Sign in</a></p>
	<?php nw_legal_links(); ?>
</main>
<?php nw_config( $ctx['config'] ); ?>
<script src="<?php echo esc_url( nw_asset( 'js/auth.js' ) ); ?>"></script>
</body></html>
