<?php
/**
 * Terms & Conditions and Privacy Policy. Public, readable without signing in, responsive, printable.
 * The plugin decides what may be shown: the published snapshot, or a holding notice until a document
 * is published. An authorised editor can preview the draft, clearly marked as a draft.
 */
defined( 'ABSPATH' ) || exit;
$ctx = $GLOBALS['nw_ctx'];
$d   = $ctx['doc'];
nw_head( $d['title'] . ' · Neon Workspace', array( 'dark' => true, 'index' => ! empty( $ctx['published'] ) && empty( $ctx['preview'] ), 'description' => $d['title'] . ' for Neon Workspace by NeonDigital.' ) );
?>
</head>
<body class="nw-public nw-legal">
<header class="lp-head no-print">
	<a href="<?php echo esc_url( home_url( '/' ) ); ?>"><?php nw_brand(); ?></a>
	<nav><button class="btn btn-sm" type="button" onclick="window.print()">Print</button></nav>
</header>
<main class="legal-page">
	<?php if ( ! empty( $ctx['preview'] ) ) : ?>
		<p class="alert alert-warn no-print" role="status"><b>Preview only.</b> Draft, not published. Visitors do not see this. State: <?php echo esc_html( $d['state_label'] ); ?>.</p>
	<?php endif; ?>
	<?php if ( ! empty( $ctx['html'] ) ) : ?>
		<?php if ( ! empty( $ctx['meta'] ) ) : ?>
			<p class="legal-meta muted small">Version <?php echo esc_html( $ctx['meta']['version'] ); ?><?php echo $ctx['meta']['effective'] ? ' · Effective ' . esc_html( $ctx['meta']['effective'] ) : ''; ?><?php echo $ctx['meta']['updated'] ? ' · Last updated ' . esc_html( $ctx['meta']['updated'] ) : ''; ?></p>
		<?php endif; ?>
		<article class="legal-body"><?php echo wp_kses_post( $ctx['html'] ); ?></article>
	<?php else : ?>
		<article class="legal-body">
			<h1><?php echo esc_html( $d['title'] ); ?></h1>
			<p>This document is being finalised and has not been published yet. It will appear here once NeonDigital has completed its review.</p>
			<p>If you need it now, please contact NeonDigital.</p>
		</article>
	<?php endif; ?>
</main>
<footer class="lp-foot muted no-print">
	<a href="<?php echo esc_url( home_url( '/terms-and-conditions/' ) ); ?>">Terms &amp; Conditions</a> · <a href="<?php echo esc_url( home_url( '/privacy-policy/' ) ); ?>">Privacy Policy</a> · © <?php echo esc_html( gmdate( 'Y' ) ); ?> NeonDigital
</footer>
</body></html>
