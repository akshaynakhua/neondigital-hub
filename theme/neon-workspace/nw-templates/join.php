<?php
/**
 * NeonDigital agency invitation. Every detail shown comes from WorkDesk, checked against the token
 * on the server. Nothing here is taken from the URL except the token itself.
 */
defined( 'ABSPATH' ) || exit;
$ctx = $GLOBALS['nw_ctx'];
$cfg = $ctx['config'];
$inv = $ctx['invite'];

nw_head( 'Join NeonDigital · Neon Workspace' );
?>
</head>
<body class="nw-public nw-auth">
<main class="auth-card card">
	<?php nw_brand(); ?>
	<h1>Connect with NeonDigital</h1>

	<?php if ( ! empty( $ctx['invite_error'] ) ) : ?>
		<p class="form-error" role="alert"><?php echo esc_html( $ctx['invite_error'] ); ?></p>
		<p class="muted small">Contact NeonDigital if you need a new invitation.</p>
		<a class="btn btn-block" href="<?php echo esc_url( home_url( '/' ) ); ?>">Go to Neon Workspace</a>

	<?php elseif ( ! empty( $ctx['signed_in'] ) && ! empty( $ctx['email_mismatch'] ) ) : ?>
		<p class="form-error" role="alert">This invitation was sent to <?php echo esc_html( $inv['email'] ); ?>, but you are signed in as <?php echo esc_html( $cfg['email'] ); ?>.</p>
		<p class="muted small">Sign out, then open this link again and sign in with the invited email.</p>
		<a class="btn btn-block" href="<?php echo esc_url( $ctx['logout_url'] ); ?>">Sign out</a>

	<?php elseif ( ! empty( $ctx['signed_in'] ) ) : ?>
		<dl class="invite-facts">
			<div><dt>Signed in as</dt><dd><?php echo esc_html( $cfg['email'] ); ?></dd></div>
			<div><dt>Business</dt><dd><?php echo esc_html( $inv['business_name'] ); ?></dd></div>
			<?php if ( ! empty( $inv['workspace_name'] ) && $inv['workspace_name'] !== $inv['business_name'] ) : ?><div><dt>New Workspace</dt><dd><?php echo esc_html( $inv['workspace_name'] ); ?></dd></div><?php endif; ?>
		</dl>
		<form id="join" data-token="<?php echo esc_attr( $ctx['token'] ); ?>" novalidate>
			<?php nw_consent_boxes(); ?>
			<div class="form-error" role="alert" hidden></div>
			<button class="btn btn-primary btn-block" type="submit">Connect my workspace</button>
		</form>
		<?php nw_config( $cfg ); ?>
		<script src="<?php echo esc_url( nw_asset( 'js/auth.js' ) ); ?>"></script>

	<?php elseif ( ! empty( $ctx['has_account'] ) ) : ?>
		<p class="muted">You already have a Neon Workspace account for <b><?php echo esc_html( $inv['email'] ); ?></b>. Sign in to accept this invitation for <b><?php echo esc_html( $inv['business_name'] ); ?></b>. Your password stays as it is.</p>
		<a class="btn btn-primary btn-block" href="<?php echo esc_url( $cfg['login'] ); ?>">Sign in to accept</a>

	<?php else : ?>
		<p class="muted">NeonDigital set up these details for you. Choose a password to get started.</p>
		<?php nw_social_buttons( $ctx['social'], 'login', '', 'month', '/join/' . $ctx['token'] ); ?>
		<form id="join-new" data-token="<?php echo esc_attr( $ctx['token'] ); ?>" novalidate>
			<label class="field"><span>Your name</span><input value="<?php echo esc_attr( $inv['name'] ); ?>" readonly aria-readonly="true"></label>
			<label class="field"><span>Email</span><input type="email" value="<?php echo esc_attr( $inv['email'] ); ?>" autocomplete="username" readonly aria-readonly="true"></label>
			<label class="field"><span>Business</span><input value="<?php echo esc_attr( $inv['business_name'] ); ?>" readonly aria-readonly="true"></label>
			<label class="field"><span>Password <small class="muted">at least 6 characters, with an uppercase letter, a lowercase letter and a number</small></span><input name="password" type="password" autocomplete="new-password" minlength="6" pattern="(?=.*[a-z])(?=.*[A-Z])(?=.*[0-9]).{6,}" required></label>
			<label class="field"><span>Confirm password</span><input name="password2" type="password" autocomplete="new-password" minlength="6" required></label>
			<?php nw_consent_boxes(); ?>
			<div class="form-error" role="alert" hidden></div>
			<button class="btn btn-primary btn-block" type="submit">Set password and connect</button>
		</form>
		<?php nw_config( $cfg ); ?>
		<script src="<?php echo esc_url( nw_asset( 'js/auth.js' ) ); ?>"></script>
	<?php endif; ?>
	<?php nw_legal_links(); ?>
</main>
</body></html>
