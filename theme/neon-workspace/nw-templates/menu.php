<?php
/** Public QR menu for restaurants and cafes. Ordering opens only with a table's QR key. */
defined( 'ABSPATH' ) || exit;
$ctx = $GLOBALS['nw_ctx'];
nw_head( 'Menu' );
?>
</head>
<body class="nw-public nw-book">
<main class="book-wrap" id="menu"><p class="muted">Loading the menu…</p></main>
<footer class="book-foot">Powered by <a href="https://neondigital.in" target="_blank" rel="noopener">NeonDigital.in</a></footer>
<?php nw_config( $ctx['config'] ); ?>
<script src="<?php echo esc_url( nw_asset( 'js/menu.js' ) ); ?>"></script>
</body></html>
