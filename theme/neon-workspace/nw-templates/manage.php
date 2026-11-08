<?php
defined( 'ABSPATH' ) || exit;
$ctx = $GLOBALS['nw_ctx'];
nw_head( 'Your booking' );
?>
</head>
<body class="nw-public nw-book">
<main class="book-wrap"><div id="manage" class="card card-pad"><p class="muted">Loading…</p></div></main>
<footer class="book-foot">Powered by <a href="https://neondigital.in" target="_blank" rel="noopener">NeonDigital.in</a></footer>
<?php nw_config( array_merge( $ctx['config'], array( 'token' => $ctx['token'] ) ) ); ?>
<script src="<?php echo esc_url( nw_asset( 'js/book.js' ) ); ?>"></script>
</body></html>
