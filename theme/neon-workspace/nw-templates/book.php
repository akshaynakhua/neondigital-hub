<?php
/**
 * Public booking page. Phase 5: one premium dark design for every business (no per-page colour),
 * the business's identity first, clear prices, seats and a sticky total with the booking action.
 */
defined( 'ABSPATH' ) || exit;
$ctx  = $GLOBALS['nw_ctx'];
$data = $ctx['payload'];
$biz  = $data['business'];
$pg   = $data['page'];
$cover = ! empty( $pg['design']->cover_url ) ? $pg['design']->cover_url : $biz['cover_url'];
nw_head( $pg['title'] . ' · ' . $biz['name'] );
?>
<meta name="theme-color" content="#09090B">
</head>
<body class="nw-public nw-book nw-book-dark">
<header class="bk-hero<?php echo $cover ? ' has-cover' : ''; ?>"<?php if ( $cover ) : ?> style="--cover:url('<?php echo esc_url( $cover ); ?>')"<?php endif; ?>>
	<div class="bk-hero-in">
		<?php if ( $biz['logo_url'] ) : ?><img class="bk-logo" src="<?php echo esc_url( $biz['logo_url'] ); ?>" alt="<?php echo esc_attr( $biz['name'] ); ?> logo"><?php else : ?><span class="bk-logo bk-logo-txt" aria-hidden="true"><?php echo esc_html( mb_strtoupper( mb_substr( $biz['name'], 0, 1 ) ) ); ?></span><?php endif; ?>
		<div class="bk-id">
			<p class="bk-biz"><?php echo esc_html( $biz['name'] ); ?><?php if ( $biz['category'] ) : ?> <span>· <?php echo esc_html( NWC_Catalog::category_label( $biz['category'] ) ); ?></span><?php endif; ?></p>
			<h1><?php echo esc_html( $pg['title'] ); ?></h1>
			<?php if ( $pg['intro'] ) : ?><p class="bk-intro"><?php echo esc_html( $pg['intro'] ); ?></p><?php endif; ?>
		</div>
	</div>
</header>
<main class="bk-wrap">
	<?php if ( ! empty( $pg['design']->description ) || $biz['description'] ) : ?><p class="bk-desc"><?php echo esc_html( ! empty( $pg['design']->description ) ? $pg['design']->description : $biz['description'] ); ?></p><?php endif; ?>
	<ol class="bk-steps" aria-label="Booking steps"><li data-step="1" class="on">Choose</li><li data-step="2">Date and time</li><li data-step="3">Your details</li></ol>
	<div id="book" class="bk-stage" aria-live="polite"><p class="bk-muted"><?php echo $data['accepting'] ? 'Loading…' : 'This business is not taking online bookings right now. Please contact them directly.'; ?></p></div>
	<?php if ( $data['accepting'] ) : ?>
	<details class="bk-card bk-enquiry">
		<summary>Have a question instead?</summary>
		<form id="enquiry" novalidate>
			<label class="field"><span>Your name</span><input name="name" autocomplete="name" required></label>
			<label class="field"><span>Phone</span><input name="phone" type="tel" autocomplete="tel"></label>
			<label class="field"><span>Email</span><input name="email" type="email" autocomplete="email"></label>
			<label class="field"><span>Message</span><textarea name="message" rows="3"></textarea></label>
			<label class="hp" aria-hidden="true">Website <input name="website" tabindex="-1" autocomplete="off"></label>
			<div class="form-error" role="alert" hidden></div>
			<button class="bk-btn" type="submit">Send</button>
		</form>
	</details>
	<?php endif; ?>
	<section class="bk-info">
		<?php if ( $biz['address'] ) : ?><div class="bk-card"><h2>Address</h2><p><?php echo nl2br( esc_html( $biz['address'] ) ); ?></p><?php if ( $biz['map_url'] ) : ?><a href="<?php echo esc_url( $biz['map_url'] ); ?>" target="_blank" rel="noopener noreferrer">Open map</a><?php endif; ?></div><?php endif; ?>
		<?php if ( $biz['phone'] || $biz['email'] ) : ?><div class="bk-card"><h2>Contact</h2><p><?php if ( $biz['phone'] ) : ?><a href="tel:<?php echo esc_attr( preg_replace( '/[^0-9+]/', '', $biz['phone'] ) ); ?>"><?php echo esc_html( $biz['phone'] ); ?></a><br><?php endif; ?><?php if ( $biz['email'] ) : ?><a href="mailto:<?php echo esc_attr( $biz['email'] ); ?>"><?php echo esc_html( $biz['email'] ); ?></a><?php endif; ?></p></div><?php endif; ?>
		<?php if ( $biz['policies'] ) : ?><div class="bk-card"><h2>Policies</h2><p><?php echo nl2br( esc_html( $biz['policies'] ) ); ?></p></div><?php endif; ?>
		<?php if ( $biz['socials'] ) : ?><div class="bk-card"><h2>Follow</h2><p><?php foreach ( $biz['socials'] as $k => $u ) : ?><a href="<?php echo esc_url( $u ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( ucfirst( $k ) ); ?></a> <?php endforeach; ?></p></div><?php endif; ?>
	</section>
</main>
<div class="bk-bar" id="bk-bar" hidden><div class="bk-bar-in"><div class="bk-bar-t"><small data-bar-label>Total</small><b data-bar-total></b><span data-bar-sub></span></div><button class="bk-btn bk-btn-lg" type="button" data-bar-go disabled>Continue</button></div></div>
<footer class="book-foot">Powered by <a href="https://neondigital.in" target="_blank" rel="noopener">NeonDigital.in</a></footer>
<?php
if ( $data['accepting'] ) {
	nw_config( array_merge( $ctx['config'], array( 'slug' => $ctx['slug'], 'data' => $data ) ) );
	echo '<script src="' . esc_url( nw_asset( 'js/book.js' ) ) . '"></script>';
}
?>
</body></html>
