<?php
/** Public pricing. Every price, trial length and feature comes from the plans in wp-admin. */
defined( 'ABSPATH' ) || exit;
$ctx   = $GLOBALS['nw_ctx'];
$plans = $ctx['plans'];
nw_head( 'Pricing · Neon Workspace', array( 'index' => true, 'landing' => true, 'description' => 'Plans and pricing for Neon Workspace.' ) );
$choose_error = isset( $_GET['auth_error'] ) ? NWC_Social::error_text( sanitize_key( wp_unslash( $_GET['auth_error'] ) ) ) : ''; // phpcs:ignore
$nw_start     = nw_site_start_url( $plans );
$nw_home      = home_url( '/' );
?>
<script>document.documentElement.classList.add('js');</script>
</head>
<body class="nw-site">
<?php nw_site_header( $nw_home, $nw_start, true ); ?>
<main id="main">
	<section class="section page-top">
		<div class="wrap">
			<header class="sec-head">
				<span class="eyebrow">Pricing</span>
				<h1>Choose your plan</h1>
				<p>Your plan starts only after you choose it and finish setting up.</p>
			</header>
			<?php if ( $choose_error ) : ?><p class="notice-bad" role="alert"><?php echo esc_html( $choose_error ); ?></p><?php endif; ?>
			<?php if ( nw_plans_have_annual( $plans ) ) { nw_billing_switch(); } ?>
			<?php if ( ! $plans ) : ?>
				<p class="plans-empty">Plans will be published here soon.</p>
			<?php else : ?>
				<?php nw_plan_cards( $plans ); ?>
			<?php endif; ?>
			<?php if ( $ctx['addons'] ) : ?>
				<section class="addons">
					<h2>Add-ons</h2>
					<?php foreach ( $ctx['addons'] as $a ) : ?>
						<div class="addon-row">
							<div><b><?php echo esc_html( $a->name ); ?></b><?php if ( $a->description ) : ?><p><?php echo esc_html( $a->description ); ?></p><?php endif; ?></div>
							<span><?php echo esc_html( nw_money( $a->price ) ); ?> <small>for <?php echo (int) $a->duration_days; ?> days</small></span>
						</div>
					<?php endforeach; ?>
				</section>
			<?php endif; ?>
			<p class="plans-note">NeonDigital agency client? Use the invitation link from NeonDigital instead.</p>
		</div>
	</section>
</main>
<?php nw_site_footer( $nw_home, $nw_start, true ); ?>
<script src="<?php echo esc_url( nw_asset( 'js/landing.js' ) ); ?>"></script>
</body></html>
