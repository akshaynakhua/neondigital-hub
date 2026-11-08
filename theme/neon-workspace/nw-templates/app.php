<?php
defined( 'ABSPATH' ) || exit;
$ctx = $GLOBALS['nw_ctx'];
nw_head( 'Neon Workspace' );
?>
</head>
<body class="nw-app">
<div id="app"><div class="boot"><img class="boot-logo" src="<?php echo esc_url( nw_asset( 'img/logo-mark.png' ) ); ?>" alt="NeonDigital" width="44" height="44"></div></div>
<div id="net" class="net" hidden role="status">You are offline. Changes need a connection.</div>
<div id="toasts" class="toasts" aria-live="polite"></div>
<noscript><p style="padding:24px">Neon Workspace needs JavaScript.</p></noscript>
<?php
nw_config( array_merge( $ctx['config'], array( 'logo' => nw_asset( 'img/logo-full.png' ), 'mark' => nw_asset( 'img/logo-mark.png' ), 'wordmark' => nw_asset( 'img/logo-wordmark.png' ), 'contact' => apply_filters( 'nw_contact_url', 'https://neondigital.in' ) ) ) );
foreach ( array( 'core', 'qr', 'views-home', 'views-work', 'views-biz', 'views-more', 'views-account', 'views-personal', 'views-industry' ) as $f ) {
	echo '<script src="' . esc_url( nw_asset( 'js/' . $f . '.js' ) ) . '"></script>';
}
?>
</body></html>
