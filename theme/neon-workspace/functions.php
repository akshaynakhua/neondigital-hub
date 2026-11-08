<?php
/**
 * Presentation only. Business logic and data live in the Neon Workspace Core plugin, so changing
 * this theme can never remove or break a customer's records.
 */

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/site-helpers.php';

function nw_asset( $rel ) {
	$file = get_template_directory() . '/assets/' . $rel;
	return add_query_arg( 'v', file_exists( $file ) ? filemtime( $file ) : '1', get_template_directory_uri() . '/assets/' . $rel );
}

/** Shared <head>. The viewport keeps pinch-zoom; inputs are 16px so iOS does not auto-zoom. */
function nw_head( $title, $opts = array() ) {
	$robots  = ! empty( $opts['index'] ) ? 'index, follow' : 'noindex, nofollow';
	$landing = ! empty( $opts['landing'] );
	$dark    = $landing || ! empty( $opts['dark'] ); // The public website is dark only. The signed-in app keeps its own theme choice.
	$pref    = is_user_logged_in() ? 'dark' : 'light'; // Public pages open light. The signed-in app opens in the graphite dark theme until someone chooses.
	if ( is_user_logged_in() ) {
		$p = get_user_meta( get_current_user_id(), 'nwc_prefs', true );
		if ( is_array( $p ) && ! empty( $p['theme'] ) ) {
			$pref = $p['theme'];
		}
	}
	?><!doctype html>
<html lang="en" data-theme-pref="<?php echo esc_attr( $dark ? 'dark' : $pref ); ?>"<?php echo $dark ? ' data-theme="dark"' : ''; ?>>
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="robots" content="<?php echo esc_attr( $robots ); ?>">
<meta name="theme-color" content="<?php echo $dark ? '#15131B' : '#F7F5FB'; ?>">
<?php if ( ! empty( $opts['description'] ) ) : ?><meta name="description" content="<?php echo esc_attr( $opts['description'] ); ?>">
<?php endif; ?>
<title><?php echo esc_html( $title ); ?></title>
<link rel="manifest" href="<?php echo esc_url( home_url( '/manifest.webmanifest' ) ); ?>">
<link rel="icon" href="<?php echo esc_url( nw_asset( 'img/favicon.png' ) ); ?>">
<link rel="apple-touch-icon" href="<?php echo esc_url( nw_asset( 'img/apple-touch-icon.png' ) ); ?>">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-title" content="Neon Workspace">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=DM+Sans:opsz,wght@9..40,400;9..40,500;9..40,600;9..40,700&family=Manrope:wght@600;700;800&display=swap">
<?php if ( $landing ) : // Homepage and pricing use only the website stylesheet, never app.css. ?>
<link rel="stylesheet" href="<?php echo esc_url( nw_asset( 'css/landing.css' ) ); ?>">
<?php return; endif; ?>
<link rel="stylesheet" href="<?php echo esc_url( nw_asset( 'css/app.css' ) ); ?>">
<?php if ( $dark ) : // Sign-in, legal and Connect pages: the app's dark tokens, retuned by a stylesheet only these pages load. ?>
<link rel="stylesheet" href="<?php echo esc_url( nw_asset( 'css/site-pages.css' ) ); ?>">
<?php return; endif; ?>
<script>
(function(){var r=document.documentElement,p=r.getAttribute('data-theme-pref');try{var l=localStorage.getItem('nw-theme');if(l)p=l;}catch(e){}
r.setAttribute('data-theme',p==='dark'||(p!=='light'&&window.matchMedia&&matchMedia('(prefers-color-scheme: dark)').matches)?'dark':'light');})();
</script>
	<?php
}

function nw_brand( $class = 'nw-logo' ) {
	echo '<img class="' . esc_attr( $class ) . '" src="' . esc_url( nw_asset( 'img/logo-full.png' ) ) . '" alt="NeonDigital">';
}

/** Official provider marks, kept as plain inline SVG. */
function nw_social_buttons( $social, $intent, $plan = '', $cycle = 'month', $next = '' ) {
	if ( ! $social ) {
		return;
	}
	$icons = array(
		'google'   => '<svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true"><path fill="#EA4335" d="M12 10.2v3.9h5.5c-.2 1.300-1.600 3.800-5.500 3.800-3.300 0-6-2.700-6-6.100s2.700-6.100 6-6.100c1.900 0 3.100.8 3.800 1.500l2.600-2.500C16.800 3.100 14.600 2 12 2 6.500 2 2 6.500 2 12s4.500 10 10 10c5.800 0 9.600-4 9.600-9.700 0-.7-.1-1.200-.2-1.700z"/><path fill="#34A853" d="M3.200 7.300l3.200 2.400C7.300 7.700 9.400 6.100 12 6.100c1.900 0 3.100.8 3.800 1.500l2.600-2.500C16.800 3.100 14.600 2 12 2 8.200 2 4.900 4.200 3.200 7.300z"/><path fill="#FBBC05" d="M12 22c2.500 0 4.700-.8 6.300-2.300l-2.900-2.400c-.8.600-1.900 1-3.400 1-3.900 0-5.200-2.600-5.500-3.800l-3.200 2.500C4.900 19.800 8.200 22 12 22z"/><path fill="#4285F4" d="M21.600 12.300c0-.7-.1-1.200-.2-1.700H12v3.900h5.500c-.3 1.200-1 2.200-2.100 2.800l2.900 2.400c1.700-1.600 3.300-4.100 3.300-7.400z"/></svg>',
		'facebook' => '<svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true"><path fill="#1877F2" d="M24 12a12 12 0 1 0-13.900 11.900v-8.400H7.100V12h3V9.400c0-3 1.800-4.700 4.500-4.700 1.300 0 2.700.2 2.700.2v3h-1.500c-1.500 0-2 .9-2 1.900V12h3.400l-.5 3.500h-2.900v8.400A12 12 0 0 0 24 12z"/></svg>',
	);
	foreach ( $social as $p ) {
		$url = add_query_arg( array_filter( array( 'intent' => $intent, 'plan' => $plan, 'cycle' => $plan ? $cycle : '', 'next' => $next ) ), home_url( '/auth/' . $p . '/start/' ) );
		echo '<a class="btn btn-block btn-social" href="' . esc_url( $url ) . '">' . $icons[ $p ] . ' Continue with ' . esc_html( ucfirst( $p ) ) . '</a>'; // phpcs:ignore
	}
	echo '<div class="auth-divider"><span>or</span></div>';
}

/** Exactly two general checkboxes, used wherever a new account is created. Both start unticked. */
/** The same two boxes on every sign-up and invitation: one required legal tick, one optional marketing tick. */
function nw_consent_boxes( $unused = false ) {
	?>
	<label class="check"><input type="checkbox" name="accept_legal" required> <span>I have read and agree to the <a href="<?php echo esc_url( home_url( '/privacy-policy/' ) ); ?>" target="_blank" rel="noopener">Privacy Policy</a> and <a href="<?php echo esc_url( home_url( '/terms-and-conditions/' ) ); ?>" target="_blank" rel="noopener">Terms &amp; Conditions</a>.</span></label>
	<label class="check"><input type="checkbox" name="marketing_consent"> <span>Send me product news and offers. (Optional)</span></label>
	<?php
}

function nw_legal_links() {
	echo '<p class="legal-links muted small center"><a href="' . esc_url( home_url( '/terms-and-conditions/' ) ) . '">Terms &amp; Conditions</a> · <a href="' . esc_url( home_url( '/privacy-policy/' ) ) . '">Privacy Policy</a></p>';
}

function nw_money( $v ) {
	return '₹' . ( fmod( (float) $v, 1 ) ? number_format( (float) $v, 2 ) : number_format( (float) $v ) );
}

function nw_config( $config ) {
	echo '<script>window.NW=' . wp_json_encode( $config, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP ) . ';</script>';
}

add_action( 'after_setup_theme', function () {
	add_theme_support( 'title-tag' );
} );
