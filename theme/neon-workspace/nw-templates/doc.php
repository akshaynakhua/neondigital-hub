<?php
/** Shared invoice or quotation: reached only by its signed link. */
defined( 'ABSPATH' ) || exit;
$ctx  = $GLOBALS['nw_ctx'];
$d    = $ctx['doc']['doc'];
$type = $ctx['doc']['type'];
$biz  = json_decode( (string) $d->business_snapshot, true );
$cust = json_decode( (string) $d->customer_snapshot, true );
$inv  = 'invoice' === $type;
$rs   = function ( $v ) {
	return '₹' . number_format( (float) $v, 2 );
};
nw_head( ( $inv ? 'Invoice ' : 'Quotation ' ) . $d->doc_no );
$req = isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
$ctx['token'] = (string) get_query_var( 'nwc_c' );
?>
<?php if ( ! empty( $biz['brand_color'] ) && preg_match( '/^#[0-9a-fA-F]{6}$/', $biz['brand_color'] ) ) : ?><style>.nw-doc{--brand:<?php echo esc_html( $biz['brand_color'] ); ?>}</style><?php endif; ?>
</head>
<body class="nw-public nw-doc">
<main class="doc card card-pad">
	<div class="doc-actions no-print"><?php if ( ! empty( $ctx['pay'] ) ) : ?><button class="btn btn-primary" id="pay-online" data-id="<?php echo (int) $d->id; ?>" data-token="<?php echo esc_attr( $ctx['token'] ?? '' ); ?>">Pay online<?php echo 'test' === $ctx['pay']['mode'] ? ' (test mode)' : ''; ?></button><?php endif; ?><a class="btn" href="<?php echo esc_url( untrailingslashit( strtok( $req, '?' ) ) . '/pdf' ); ?>">Download PDF</a><button class="btn" onclick="window.print()">Print</button></div>
	<?php if ( 'cancelled' === $d->status ) : ?><p class="badge badge-bad">Cancelled</p><?php endif; ?>
	<header class="doc-head">
		<div>
			<?php if ( ! empty( $biz['logo_url'] ) ) : ?><img class="book-logo" src="<?php echo esc_url( $biz['logo_url'] ); ?>" alt=""><?php endif; ?>
			<h1><?php echo esc_html( $biz['name'] ); ?></h1>
			<p class="muted"><?php echo nl2br( esc_html( (string) $biz['address'] ) ); ?><br><?php echo esc_html( $biz['phone'] ); ?> <?php echo esc_html( $biz['email'] ); ?><?php if ( ! empty( $biz['gstin'] ) ) : ?><br>GSTIN: <?php echo esc_html( $biz['gstin'] ); ?><?php endif; ?></p>
		</div>
		<div class="doc-meta">
			<h2><?php echo $inv ? ( ! empty( $biz['gst_registered'] ) ? 'Tax Invoice' : 'Invoice' ) : 'Quotation'; ?></h2>
			<p><?php echo esc_html( $d->doc_no ); ?><br><?php echo esc_html( $inv ? $d->invoice_date : $d->quote_date ); ?>
			<?php if ( $inv && $d->due_date ) : ?><br>Due <?php echo esc_html( $d->due_date ); ?><?php endif; ?>
			<?php if ( ! $inv && $d->valid_until ) : ?><br>Valid until <?php echo esc_html( $d->valid_until ); ?><?php endif; ?></p>
		</div>
	</header>
	<p><b><?php echo $inv ? 'Bill to' : 'For'; ?></b><br><?php echo esc_html( $cust['business_name'] ? $cust['business_name'] : $cust['name'] ); ?><br><?php echo nl2br( esc_html( (string) $cust['billing_address'] ) ); ?><?php if ( $cust['gstin'] ) : ?><br>GSTIN: <?php echo esc_html( $cust['gstin'] ); ?><?php endif; ?></p>
	<div class="table-wrap"><table class="tbl stackable"><thead><tr><th>Description</th><th class="num">Qty</th><th class="num">Price</th><th class="num">Discount</th><th class="num">Tax</th><th class="num">Amount</th></tr></thead><tbody>
	<?php foreach ( $ctx['doc']['items'] as $it ) : ?>
		<tr><td data-th="Description"><?php echo esc_html( $it->description ); ?></td><td class="num" data-th="Qty"><?php echo esc_html( rtrim( rtrim( (string) $it->qty, '0' ), '.' ) ); ?></td><td class="num" data-th="Price"><?php echo esc_html( $rs( $it->unit_price ) ); ?></td><td class="num" data-th="Discount"><?php echo esc_html( $rs( $it->discount ) ); ?></td><td class="num" data-th="Tax"><?php echo (float) $it->tax_rate ? esc_html( (float) $it->tax_rate . '%' ) : '–'; ?></td><td class="num" data-th="Amount"><?php echo esc_html( $rs( $it->amount ) ); ?></td></tr>
	<?php endforeach; ?>
	</tbody></table></div>
	<dl class="totals">
		<dt>Subtotal</dt><dd><?php echo esc_html( $rs( $d->subtotal ) ); ?></dd>
		<?php if ( (float) $d->discount_total ) : ?><dt>Discount</dt><dd>-<?php echo esc_html( $rs( $d->discount_total ) ); ?></dd><?php endif; ?>
		<?php foreach ( array( 'CGST' => $d->cgst, 'SGST' => $d->sgst, 'IGST' => $d->igst ) as $k => $v ) : ?>
			<?php if ( (float) $v ) : ?><dt><?php echo esc_html( $k ); ?></dt><dd><?php echo esc_html( $rs( $v ) ); ?></dd><?php endif; ?>
		<?php endforeach; ?>
		<dt class="b">Total</dt><dd class="b"><?php echo esc_html( $rs( $d->grand_total ) ); ?></dd>
		<?php if ( $inv && (float) $d->amount_paid ) : ?><dt>Paid</dt><dd><?php echo esc_html( $rs( $d->amount_paid ) ); ?></dd><dt class="b">Balance</dt><dd class="b"><?php echo esc_html( $rs( (float) $d->grand_total - (float) $d->amount_paid ) ); ?></dd><?php endif; ?>
	</dl>
	<?php foreach ( array( 'Notes' => $d->notes, 'Terms' => $d->terms, 'Payment details' => $inv ? $d->bank_details : '' ) as $k => $v ) : ?>
		<?php if ( '' !== trim( (string) $v ) ) : ?><p><b><?php echo esc_html( $k ); ?></b><br><?php echo nl2br( esc_html( (string) $v ) ); ?></p><?php endif; ?>
	<?php endforeach; ?>
	<?php if ( ! empty( $ctx['qr'] ) ) : ?><p><b>Scan to pay</b><br><img class="doc-qr" src="<?php echo esc_url( $ctx['qr'] ); ?>" alt="UPI payment QR code" width="160" height="160"></p><?php endif; ?>
	<p class="doc-foot muted small">Generated with workspace.neondigital.in</p>
</main>
<?php if ( ! empty( $ctx['pay'] ) ) : ?>
<?php nw_config( $ctx['config'] ); ?>
<script src="<?php echo esc_url( nw_asset( 'js/pay.js' ) ); ?>"></script>
<?php endif; ?>
</body></html>
