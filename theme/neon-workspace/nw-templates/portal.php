<?php
/** Customer Portal: one customer's own bookings, invoices, quotations and shared documents. */
defined( 'ABSPATH' ) || exit;
$ctx = $GLOBALS['nw_ctx'];
nw_head( 'Your account' );
?>
</head>
<body class="nw-public">
<main class="book-wrap" id="portal"><div class="skel"></div><div class="skel"></div></main>
<footer class="book-foot">Powered by <a href="https://neondigital.in" target="_blank" rel="noopener">NeonDigital.in</a></footer>
<?php nw_config( $ctx['config'] ); ?>
<script src="<?php echo esc_url( nw_asset( 'js/portal.js' ) ); ?>"></script>
</body></html>
