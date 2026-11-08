<?php
/**
 * Business invoices and quotations: documents a workspace raises for ITS OWN customers.
 * Separate from subscription invoices (NWC_Billing) and agency invoices (owned by WorkDesk).
 *
 * Money is calculated in integer paise. GST is optional and follows the business's own settings;
 * the document is a template and is not represented as certified or compliant for every case.
 */

defined( 'ABSPATH' ) || exit;

class NWC_Invoices {

	const STATUSES = array( 'draft', 'sent', 'partially_paid', 'paid', 'overdue', 'cancelled', 'refunded' );

	public static function routes() {
		NWC_REST::route( '/invoices', 'GET', array( __CLASS__, 'index' ) );
		NWC_REST::route( '/invoices', 'POST', array( __CLASS__, 'create' ) );
		NWC_REST::route( '/invoices/preview', 'POST', array( __CLASS__, 'preview' ) );
		NWC_REST::route( '/quotes/preview', 'POST', array( __CLASS__, 'quote_preview' ) );
		NWC_REST::route( '/invoices/series', 'GET', array( __CLASS__, 'series' ) );
		NWC_REST::route( '/invoices/series', 'PUT', array( __CLASS__, 'save_series' ) );
		NWC_REST::route( '/invoices/(?P<id>\d+)', 'GET', array( __CLASS__, 'show' ) );
		NWC_REST::route( '/invoices/(?P<id>\d+)', 'PUT,PATCH', array( __CLASS__, 'update' ) );
		NWC_REST::route( '/invoices/(?P<id>\d+)/send', 'POST', array( __CLASS__, 'send' ) );
		NWC_REST::route( '/invoices/(?P<id>\d+)/cancel', 'POST', array( __CLASS__, 'cancel' ) );
		NWC_REST::route( '/invoices/(?P<id>\d+)/payments', 'POST', array( __CLASS__, 'add_payment' ) );
		NWC_REST::route( '/invoices/(?P<id>\d+)/payments/(?P<pid>\d+)/void', 'POST', array( __CLASS__, 'void_payment' ) );
		NWC_REST::route( '/invoices/(?P<id>\d+)/remind', 'POST', array( __CLASS__, 'remind' ) );
		NWC_REST::route( '/invoices/(?P<id>\d+)/share', 'POST', array( __CLASS__, 'share' ) );
		NWC_REST::route( '/invoices/(?P<id>\d+)/pdf', 'GET', array( __CLASS__, 'pdf' ) );
		NWC_REST::route( '/quotes', 'GET', array( __CLASS__, 'quotes' ) );
		NWC_REST::route( '/quotes', 'POST', array( __CLASS__, 'quote_create' ) );
		NWC_REST::route( '/quotes/(?P<id>\d+)', 'GET', array( __CLASS__, 'quote_show' ) );
		NWC_REST::route( '/quotes/(?P<id>\d+)', 'PUT,PATCH', array( __CLASS__, 'quote_update' ) );
		NWC_REST::route( '/quotes/(?P<id>\d+)/status', 'POST', array( __CLASS__, 'quote_status' ) );
		NWC_REST::route( '/quotes/(?P<id>\d+)/convert', 'POST', array( __CLASS__, 'quote_convert' ) );
		NWC_REST::route( '/quotes/(?P<id>\d+)/share', 'POST', array( __CLASS__, 'quote_share' ) );
		NWC_REST::route( '/quotes/(?P<id>\d+)/pdf', 'GET', array( __CLASS__, 'quote_pdf' ) );
	}

	private static function guard( $perm ) {
		if ( ! NWC_Tenancy::can( $perm ) ) {
			return NWC::err( 'Your role does not allow this.', 403, 'nwc_forbidden' );
		}
		return NWC_Features::guard( 'invoice_management' );
	}

	private static function paise( $v ) {
		return (int) round( (float) $v * 100 );
	}

	private static function rupees( $p ) {
		return round( $p / 100, 2 );
	}

	/* ---------------------------- Numbering ---------------------------- */

	private static function ensure_series( $ws, $type ) {
		global $wpdb;
		$t = NWC_DB::t( 'number_series' );
		$s = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $t WHERE workspace_id = %d AND doc_type = %s", $ws, $type ) ); // phpcs:ignore
		if ( ! $s ) {
			$wpdb->insert( $t, array( 'workspace_id' => (int) $ws, 'doc_type' => $type, 'prefix' => 'invoice' === $type ? 'INV-' : 'QT-', 'next_no' => 1, 'padding' => 4, 'updated_at' => NWC::now() ) );
			$s = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $t WHERE workspace_id = %d AND doc_type = %s", $ws, $type ) ); // phpcs:ignore
		}
		return $s;
	}

	/** Unique within the workspace: takes the lock, skips anything already used. */
	private static function next_number( $ws, $type ) {
		global $wpdb;
		$key = 'series:' . $ws . ':' . $type;
		if ( ! NWC::lock( $key ) ) {
			return NWC::err( 'Busy right now. Please try again.', 503, 'nwc_busy' );
		}
		try {
			$s     = self::ensure_series( $ws, $type );
			$table = NWC_DB::t( 'invoice' === $type ? 'biz_invoices' : 'biz_quotes' );
			$n     = (int) $s->next_no;
			do {
				$no = $s->prefix . str_pad( (string) $n, (int) $s->padding, '0', STR_PAD_LEFT );
				$n++;
			} while ( $wpdb->get_var( $wpdb->prepare( "SELECT id FROM $table WHERE workspace_id = %d AND doc_no = %s", $ws, $no ) ) ); // phpcs:ignore
			$wpdb->update( NWC_DB::t( 'number_series' ), array( 'next_no' => $n, 'updated_at' => NWC::now() ), array( 'id' => $s->id ) );
			return $no;
		} finally {
			NWC::unlock( $key );
		}
	}

	public static function series() {
		$g = self::guard( 'invoices.read' );
		if ( is_wp_error( $g ) ) {
			return $g;
		}
		$ws = (int) NWC_Tenancy::$current->id;
		return array( 'invoice' => self::ensure_series( $ws, 'invoice' ), 'quote' => self::ensure_series( $ws, 'quote' ) );
	}

	public static function save_series( WP_REST_Request $r ) {
		global $wpdb;
		$g = self::guard( 'invoices.write' );
		if ( is_wp_error( $g ) ) {
			return $g;
		}
		$b = NWC_REST::body( $r );
		$ws = (int) NWC_Tenancy::$current->id;
		foreach ( array( 'invoice', 'quote' ) as $type ) {
			if ( empty( $b[ $type ] ) || ! is_array( $b[ $type ] ) ) {
				continue;
			}
			$s = self::ensure_series( $ws, $type );
			$prefix = isset( $b[ $type ]['prefix'] ) ? preg_replace( '/[^A-Za-z0-9\/_-]/', '', (string) $b[ $type ]['prefix'] ) : $s->prefix;
			$next   = isset( $b[ $type ]['next_no'] ) ? max( 1, (int) $b[ $type ]['next_no'] ) : (int) $s->next_no;
			$wpdb->update( NWC_DB::t( 'number_series' ), array( 'prefix' => mb_substr( $prefix, 0, 20 ), 'next_no' => $next, 'padding' => isset( $b[ $type ]['padding'] ) ? max( 1, min( 10, (int) $b[ $type ]['padding'] ) ) : (int) $s->padding, 'updated_at' => NWC::now() ), array( 'id' => $s->id ) );
		}
		NWC::audit( 'invoice_series_changed', array( 'entity' => 'series', 'kind' => 'admin' ) );
		return self::series();
	}

	/* ---------------------------- Calculation ---------------------------- */

	/**
	 * Works out totals. Tax applies only when the business is GST registered:
	 * same state → CGST + SGST (half each); other state → IGST.
	 *
	 * @return array|WP_Error {items: [...], totals: {...}}
	 */
	public static function compute( $items, $biz, $place_of_supply ) {
		$rows = array();
		$sub  = 0;
		$disc = 0;
		$tax  = 0;
		foreach ( array_slice( (array) $items, 0, 200 ) as $i => $it ) {
			$desc = mb_substr( sanitize_text_field( (string) ( $it['description'] ?? '' ) ), 0, 500 );
			if ( '' === $desc ) {
				return NWC::err( 'Line ' . ( $i + 1 ) . ' needs a description.' );
			}
			$qty   = round( (float) ( $it['qty'] ?? 1 ), 3 );
			$price = self::paise( $it['unit_price'] ?? 0 );
			$d     = self::paise( $it['discount'] ?? 0 );
			$rate  = (float) ( $it['tax_rate'] ?? 0 );
			if ( $qty <= 0 || $price < 0 || $d < 0 || $rate < 0 || $rate > 100 ) {
				return NWC::err( 'Line ' . ( $i + 1 ) . ' has an invalid quantity, price, discount or tax rate.' );
			}
			$gross = (int) round( $qty * $price );
			if ( $d > $gross ) {
				return NWC::err( 'Line ' . ( $i + 1 ) . ': the discount is larger than the line amount.' );
			}
			$net  = $gross - $d;
			$rate = (int) $biz->gst_registered ? $rate : 0;
			$tax += (int) round( $net * $rate / 100 );
			$sub += $gross;
			$disc += $d;
			$rows[] = array( 'description' => $desc, 'hsn' => mb_substr( sanitize_text_field( (string) ( $it['hsn'] ?? '' ) ), 0, 12 ), 'qty' => $qty, 'unit_price' => self::rupees( $price ), 'discount' => self::rupees( $d ), 'tax_rate' => $rate, 'amount' => self::rupees( $net ), 'sort' => $i );
		}
		if ( ! $rows ) {
			return NWC::err( 'Add at least one line.' );
		}
		$cgst = 0;
		$sgst = 0;
		$igst = 0;
		if ( $tax ) {
			$inter = '' !== (string) $place_of_supply && '' !== (string) $biz->state_code && (string) $place_of_supply !== (string) $biz->state_code;
			if ( $inter ) {
				$igst = $tax;
			} else {
				$cgst = (int) floor( $tax / 2 );
				$sgst = $tax - $cgst;
			}
		}
		$taxable = $sub - $disc;
		return array( 'items' => $rows, 'totals' => array( 'subtotal' => self::rupees( $sub ), 'discount_total' => self::rupees( $disc ), 'taxable' => self::rupees( $taxable ), 'cgst' => self::rupees( $cgst ), 'sgst' => self::rupees( $sgst ), 'igst' => self::rupees( $igst ), 'tax_total' => self::rupees( $tax ), 'grand_total' => self::rupees( $taxable + $tax ) ) );
	}

	private static function snapshot_business( $biz ) {
		return wp_json_encode( array( 'name' => $biz->display_name, 'logo_url' => $biz->logo_url, 'address' => (string) $biz->address, 'phone' => $biz->phone, 'email' => $biz->email, 'gstin' => (int) $biz->gst_registered ? $biz->gstin : '', 'gst_registered' => (bool) $biz->gst_registered, 'state_code' => $biz->state_code, 'signature_url' => $biz->signature_url, 'brand_color' => $biz->brand_color ) );
	}

	/** Existing CRM customer, or a new one added through the same no-duplicate path. */
	private static function resolve_customer( $b, $ws ) {
		global $wpdb;
		$t   = NWC_DB::t( 'customers' );
		$cid = absint( $b['customer_id'] ?? 0 );
		if ( ! $cid && ! empty( $b['new_customer'] ) && is_array( $b['new_customer'] ) ) {
			$n = $b['new_customer'];
			if ( '' === trim( (string) ( $n['name'] ?? '' ) ) ) {
				return NWC::err( 'Enter the customer\'s name.' );
			}
			$cid = NWC_CRM::upsert_customer( $ws, array( 'name' => $n['name'], 'phone' => $n['phone'] ?? '', 'email' => $n['email'] ?? '', 'source' => 'invoice' ) );
			$up  = array();
			foreach ( array( 'business_name' => 190, 'contact_person' => 190, 'gstin' => 20, 'state_code' => 4 ) as $k => $len ) {
				if ( ! empty( $n[ $k ] ) ) {
					$up[ $k ] = mb_substr( sanitize_text_field( (string) $n[ $k ] ), 0, $len );
				}
			}
			foreach ( array( 'billing_address', 'shipping_address' ) as $k ) {
				if ( ! empty( $n[ $k ] ) ) {
					$up[ $k ] = sanitize_textarea_field( (string) $n[ $k ] );
				}
			}
			if ( $up ) {
				$wpdb->update( $t, $up, array( 'id' => $cid ) );
			}
		}
		$c = $cid ? $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $t WHERE id = %d AND workspace_id = %d AND deleted_at IS NULL", $cid, $ws ) ) : null; // phpcs:ignore
		return $c ? $c : NWC::err( 'Choose a customer.', 400 );
	}

	private static function snapshot_customer( $c ) {
		return wp_json_encode( array( 'name' => $c->name, 'business_name' => $c->business_name, 'contact_person' => $c->contact_person, 'phone' => $c->phone, 'email' => $c->email, 'billing_address' => (string) $c->billing_address, 'shipping_address' => (string) $c->shipping_address, 'gstin' => $c->gstin, 'state_code' => $c->state_code ) );
	}

	private static function save_items( $ws, $type, $id, $items ) {
		global $wpdb;
		$t = NWC_DB::t( 'biz_items' );
		$wpdb->delete( $t, array( 'doc_type' => $type, 'doc_id' => $id, 'workspace_id' => $ws ) );
		foreach ( $items as $it ) {
			$wpdb->insert( $t, array_merge( $it, array( 'workspace_id' => (int) $ws, 'doc_type' => $type, 'doc_id' => (int) $id ) ) );
		}
	}

	private static function items( $ws, $type, $id ) {
		global $wpdb;
		return $wpdb->get_results( $wpdb->prepare( 'SELECT description, hsn, qty, unit_price, discount, tax_rate, amount FROM ' . NWC_DB::t( 'biz_items' ) . ' WHERE workspace_id = %d AND doc_type = %s AND doc_id = %d ORDER BY sort ASC', $ws, $type, $id ) ); // phpcs:ignore
	}

	/* ---------------------------- Invoices ---------------------------- */

	public static function row( $id, $ws = null ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . NWC_DB::t( 'biz_invoices' ) . ' WHERE id = %d AND workspace_id = %d', $id, $ws ? $ws : NWC_Tenancy::$current->id ) ); // phpcs:ignore
	}

	/** Sent and part-paid invoices past their due date read as overdue. */
	public static function effective_status( $v, $today = null ) {
		$today = $today ? $today : NWC::today();
		if ( in_array( $v->status, array( 'sent', 'partially_paid' ), true ) && $v->due_date && $v->due_date < $today ) {
			return 'overdue';
		}
		return $v->status;
	}

	private static function out( $v, $detail = false ) {
		global $wpdb;
		$o = array(
			'id' => (int) $v->id, 'doc_no' => $v->doc_no, 'quote_id' => $v->quote_id ? (int) $v->quote_id : null, 'customer_id' => $v->customer_id ? (int) $v->customer_id : null,
			'customer' => json_decode( (string) $v->customer_snapshot, true ), 'invoice_date' => $v->invoice_date, 'due_date' => $v->due_date, 'grand_total' => (float) $v->grand_total, 'amount_paid' => (float) $v->amount_paid,
			'balance' => self::rupees( self::paise( $v->grand_total ) - self::paise( $v->amount_paid ) ), 'status' => self::effective_status( $v ), 'stored_status' => $v->status,
		);
		if ( $detail ) {
			$o += array(
				'business' => json_decode( (string) $v->business_snapshot, true ), 'place_of_supply' => $v->place_of_supply, 'subtotal' => (float) $v->subtotal, 'discount_total' => (float) $v->discount_total, 'taxable' => (float) $v->taxable, 'cgst' => (float) $v->cgst, 'sgst' => (float) $v->sgst, 'igst' => (float) $v->igst, 'tax_total' => (float) $v->tax_total,
				'notes' => (string) $v->notes, 'terms' => (string) $v->terms, 'bank_details' => (string) $v->bank_details, 'sent_at' => $v->sent_at, 'cancelled_at' => $v->cancelled_at, 'cancel_reason' => (string) $v->cancel_reason,
				'items' => self::items( $v->workspace_id, 'invoice', $v->id ),
				'payments' => $wpdb->get_results( $wpdb->prepare( 'SELECT id, amount, paid_on, method, reference, notes, voided_at, void_reason FROM ' . NWC_DB::t( 'biz_payments' ) . ' WHERE invoice_id = %d ORDER BY id ASC', $v->id ) ), // phpcs:ignore
				'history' => $wpdb->get_results( $wpdb->prepare( 'SELECT action, details, created_at FROM ' . NWC_DB::t( 'audit' ) . " WHERE workspace_id = %d AND entity = 'invoice' AND entity_id = %d ORDER BY id DESC LIMIT 50", $v->workspace_id, $v->id ) ), // phpcs:ignore
				'payment_account_id' => $v->payment_account_id ? (int) $v->payment_account_id : null,
				'online_pay' => (int) $v->online_pay,
				'view'    => self::stored_view( 'invoice', $v ),
			);
		}
		return $o;
	}

	public static function index( WP_REST_Request $r ) {
		global $wpdb;
		$g = self::guard( 'invoices.read' );
		if ( is_wp_error( $g ) ) {
			return $g;
		}
		$vals = array( (int) NWC_Tenancy::$current->id );
		$sql  = 'workspace_id = %d';
		if ( $r->get_param( 'customer_id' ) ) {
			$sql   .= ' AND customer_id = %d';
			$vals[] = absint( $r->get_param( 'customer_id' ) );
		}
		$q = trim( sanitize_text_field( (string) $r->get_param( 'q' ) ) );
		if ( '' !== $q ) {
			$like   = '%' . $wpdb->esc_like( $q ) . '%';
			$sql   .= ' AND (doc_no LIKE %s OR customer_snapshot LIKE %s)';
			$vals[] = $like;
			$vals[] = $like;
		}
		$rows = array_map( array( __CLASS__, 'out' ), $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . NWC_DB::t( 'biz_invoices' ) . " WHERE $sql ORDER BY id DESC LIMIT 300", $vals ) ) ); // phpcs:ignore
		$want = sanitize_key( (string) $r->get_param( 'status' ) );
		if ( $want ) {
			$rows = array_values( array_filter( $rows, function ( $i ) use ( $want ) {
				return $i['status'] === $want;
			} ) );
		}
		return array( 'items' => $rows );
	}

	public static function show( WP_REST_Request $r ) {
		$g = self::guard( 'invoices.read' );
		if ( is_wp_error( $g ) ) {
			return $g;
		}
		$v = self::row( (int) $r['id'] );
		return $v ? self::out( $v, true ) : NWC::err( 'Invoice not found.', 404, 'nwc_not_found' );
	}

	public static function create( WP_REST_Request $r ) {
		$g = self::guard( 'invoices.write' );
		if ( is_wp_error( $g ) ) {
			return $g;
		}
		$res = self::create_doc( NWC_REST::body( $r ) );
		return is_wp_error( $res ) ? $res : self::out( self::row( $res ), true );
	}

	/** @return int|WP_Error new invoice id */
	public static function create_doc( $b, $quote_id = null ) {
		global $wpdb;
		$ws  = (int) NWC_Tenancy::$current->id;
		$biz = NWC_Bookings::business( $ws );
		$c   = self::resolve_customer( $b, $ws );
		if ( is_wp_error( $c ) ) {
			return $c;
		}
		$pos = mb_substr( sanitize_text_field( (string) ( $b['place_of_supply'] ?? $c->state_code ) ), 0, 4 );
		$calc = self::compute( $b['items'] ?? array(), $biz, $pos );
		if ( is_wp_error( $calc ) ) {
			return $calc;
		}
		$date = isset( $b['invoice_date'] ) && NWC::valid_date( $b['invoice_date'] ) ? $b['invoice_date'] : NWC::today( NWC_Tenancy::$current->timezone );
		$due  = isset( $b['due_date'] ) && NWC::valid_date( $b['due_date'] ) ? $b['due_date'] : null;
		if ( $due && $due < $date ) {
			return NWC::err( 'The due date is before the invoice date.' );
		}
		$acct = self::payment_account_view( $ws, absint( $b['payment_account_id'] ?? 0 ) );
		if ( ! empty( $b['payment_account_id'] ) && ! $acct ) {
			return NWC::err( 'That payment account was not found.', 404, 'nwc_not_found' );
		}
		if ( $acct && ( ! isset( $b['bank_details'] ) || '' === trim( (string) $b['bank_details'] ) ) ) {
			$b['bank_details'] = $acct['text'];
		}
		$no = self::next_number( $ws, 'invoice' );
		if ( is_wp_error( $no ) ) {
			return $no;
		}
		$now = NWC::now();
		$ok  = $wpdb->insert( NWC_DB::t( 'biz_invoices' ), array_merge( $calc['totals'], array(
			'payment_account_id' => $acct ? $acct['id'] : null,
			'workspace_id' => $ws, 'doc_no' => $no, 'quote_id' => $quote_id, 'customer_id' => (int) $c->id, 'customer_snapshot' => self::snapshot_customer( $c ), 'business_snapshot' => self::snapshot_business( $biz ),
			'invoice_date' => $date, 'due_date' => $due, 'place_of_supply' => $pos, 'status' => 'draft', 'notes' => sanitize_textarea_field( (string) ( $b['notes'] ?? '' ) ), 'terms' => sanitize_textarea_field( (string) ( $b['terms'] ?? $biz->payment_terms ) ), 'bank_details' => sanitize_textarea_field( (string) ( $b['bank_details'] ?? $biz->bank_details ) ),
			'share_nonce' => bin2hex( random_bytes( 8 ) ), 'created_by' => get_current_user_id(), 'created_at' => $now, 'updated_at' => $now,
		) ) );
		if ( ! $ok ) {
			return NWC::err( 'Could not save the invoice.', 500, 'nwc_error' );
		}
		$id = (int) $wpdb->insert_id;
		self::save_items( $ws, 'invoice', $id, $calc['items'] );
		NWC::audit( 'invoice_created', array( 'entity' => 'invoice', 'entity_id' => $id, 'details' => array( 'doc_no' => $no, 'total' => $calc['totals']['grand_total'] ) ) );
		return $id;
	}

	/** Only a draft can be edited: a sent invoice is a record, so it is cancelled and reissued. */
	public static function update( WP_REST_Request $r ) {
		global $wpdb;
		$g = self::guard( 'invoices.write' );
		if ( is_wp_error( $g ) ) {
			return $g;
		}
		$v = self::row( (int) $r['id'] );
		if ( ! $v ) {
			return NWC::err( 'Invoice not found.', 404, 'nwc_not_found' );
		}
		if ( 'draft' !== $v->status ) {
			return NWC::err( 'Only a draft can be edited. Cancel this invoice and issue a new one.', 409, 'nwc_conflict' );
		}
		$b   = NWC_REST::body( $r );
		$ws  = (int) $v->workspace_id;
		$biz = NWC_Bookings::business( $ws );
		$row = array( 'updated_at' => NWC::now(), 'business_snapshot' => self::snapshot_business( $biz ) );
		if ( ! empty( $b['customer_id'] ) || ! empty( $b['new_customer'] ) ) {
			$c = self::resolve_customer( $b, $ws );
			if ( is_wp_error( $c ) ) {
				return $c;
			}
			$row['customer_id']       = (int) $c->id;
			$row['customer_snapshot'] = self::snapshot_customer( $c );
		}
		$pos = array_key_exists( 'place_of_supply', $b ) ? mb_substr( sanitize_text_field( (string) $b['place_of_supply'] ), 0, 4 ) : $v->place_of_supply;
		$row['place_of_supply'] = $pos;
		foreach ( array( 'invoice_date', 'due_date' ) as $k ) {
			if ( array_key_exists( $k, $b ) ) {
				if ( '' !== (string) $b[ $k ] && ! NWC::valid_date( $b[ $k ] ) ) {
					return NWC::err( 'Invalid date.' );
				}
				$row[ $k ] = '' === (string) $b[ $k ] ? null : $b[ $k ];
			}
		}
		foreach ( array( 'notes', 'terms', 'bank_details' ) as $k ) {
			if ( array_key_exists( $k, $b ) ) {
				$row[ $k ] = sanitize_textarea_field( (string) $b[ $k ] );
			}
		}
		if ( array_key_exists( 'payment_account_id', $b ) ) {
			$acct = absint( $b['payment_account_id'] ) ? self::payment_account_view( $ws, absint( $b['payment_account_id'] ) ) : null;
			if ( absint( $b['payment_account_id'] ) && ! $acct ) {
				return NWC::err( 'That payment account was not found.', 404, 'nwc_not_found' );
			}
			$row['payment_account_id'] = $acct ? $acct['id'] : null;
			if ( $acct && ( ! isset( $b['bank_details'] ) || '' === trim( (string) $b['bank_details'] ) ) ) {
				$row['bank_details'] = $acct['text'];
			}
		}
		if ( isset( $b['items'] ) ) {
			$calc = self::compute( $b['items'], $biz, $pos );
			if ( is_wp_error( $calc ) ) {
				return $calc;
			}
			$row = array_merge( $row, $calc['totals'] );
			self::save_items( $ws, 'invoice', $v->id, $calc['items'] );
		}
		$wpdb->update( NWC_DB::t( 'biz_invoices' ), $row, array( 'id' => $v->id, 'workspace_id' => $ws ) );
		NWC::audit( 'invoice_edited', array( 'entity' => 'invoice', 'entity_id' => $v->id ) );
		return self::out( self::row( $v->id ), true );
	}

	/** Marks as sent; emails it when recipients are given and email is configured. */
	public static function send( WP_REST_Request $r ) {
		global $wpdb;
		$g = self::guard( 'invoices.write' );
		if ( is_wp_error( $g ) ) {
			return $g;
		}
		$v = self::row( (int) $r['id'] );
		if ( ! $v ) {
			return NWC::err( 'Invoice not found.', 404, 'nwc_not_found' );
		}
		if ( 'cancelled' === $v->status ) {
			return NWC::err( 'This invoice is cancelled.', 409, 'nwc_conflict' );
		}
		$b  = NWC_REST::body( $r );
		$to = array_values( array_filter( array_map( 'sanitize_email', (array) ( $b['to'] ?? array() ) ), 'is_email' ) );
		if ( 'draft' === $v->status ) {
			$wpdb->update( NWC_DB::t( 'biz_invoices' ), array( 'status' => 'sent', 'sent_at' => NWC::now(), 'updated_at' => NWC::now() ), array( 'id' => $v->id ) );
		}
		$emailed = false;
		if ( $to ) {
			$biz = json_decode( (string) $v->business_snapshot, true );
			$url = self::share_url( 'invoice', $v );
			$emailed = NWC_Notify::email( $to, 'Invoice ' . $v->doc_no . ' from ' . $biz['name'], '<p>Please find invoice <b>' . esc_html( $v->doc_no ) . '</b> for ' . esc_html( self::money( $v->grand_total ) ) . '.</p><p><a href="' . esc_url( $url ) . '">View invoice</a></p>', $biz['name'] );
		}
		NWC::audit( 'invoice_sent', array( 'entity' => 'invoice', 'entity_id' => $v->id, 'details' => array( 'to' => $to, 'emailed' => $emailed ) ) );
		return array_merge( self::out( self::row( $v->id ), true ), array( 'emailed' => $emailed, 'email_configured' => NWC_Notify::enabled() ) );
	}

	/** Cancelling keeps the invoice and its payment history. Nothing is deleted. */
	public static function cancel( WP_REST_Request $r ) {
		global $wpdb;
		$g = self::guard( 'invoices.write' );
		if ( is_wp_error( $g ) ) {
			return $g;
		}
		$v = self::row( (int) $r['id'] );
		if ( ! $v ) {
			return NWC::err( 'Invoice not found.', 404, 'nwc_not_found' );
		}
		$b      = NWC_REST::body( $r );
		$reason = sanitize_textarea_field( (string) ( $b['reason'] ?? '' ) );
		if ( '' === trim( $reason ) ) {
			return NWC::err( 'Give the reason for cancelling.' );
		}
		if ( 'cancelled' === $v->status ) {
			return self::out( $v, true );
		}
		if ( self::paise( $v->amount_paid ) > 0 ) {
			return NWC::err( 'This invoice has payments recorded. Void them first, so the history stays correct.', 409, 'nwc_conflict' );
		}
		$wpdb->update( NWC_DB::t( 'biz_invoices' ), array( 'status' => 'cancelled', 'cancelled_at' => NWC::now(), 'cancel_reason' => $reason, 'updated_at' => NWC::now() ), array( 'id' => $v->id ) );
		NWC::audit( 'invoice_cancelled', array( 'entity' => 'invoice', 'entity_id' => $v->id, 'details' => array( 'reason' => $reason ) ) );
		return self::out( self::row( $v->id ), true );
	}

	/** Recalculates paid amount and status from the payments that stand. */
	public static function recalc( $id, $ws ) {
		global $wpdb;
		$v = self::row( $id, $ws );
		if ( ! $v ) {
			return;
		}
		$paid = self::paise( $wpdb->get_var( $wpdb->prepare( 'SELECT COALESCE(SUM(amount),0) FROM ' . NWC_DB::t( 'biz_payments' ) . ' WHERE invoice_id = %d AND voided_at IS NULL', $id ) ) ); // phpcs:ignore
		$tot  = self::paise( $v->grand_total );
		$st   = $v->status;
		if ( 'cancelled' !== $st && 'refunded' !== $st ) {
			$st = $paid >= $tot && $tot > 0 ? 'paid' : ( $paid > 0 ? 'partially_paid' : ( 'draft' === $st ? 'draft' : 'sent' ) );
		}
		$wpdb->update( NWC_DB::t( 'biz_invoices' ), array( 'amount_paid' => self::rupees( $paid ), 'status' => $st, 'updated_at' => NWC::now() ), array( 'id' => $id ) );
	}

	/**
	 * Applies a payment under a lock. A repeated idempotency key returns the first result, so a
	 * double click or retried request cannot pay twice, and overpayment is refused.
	 *
	 * @return object|WP_Error Payment row.
	 */
	public static function apply_payment( $v, $in, $transaction_id = null ) {
		global $wpdb;
		$t      = NWC_DB::t( 'biz_payments' );
		$amount = self::paise( $in['amount'] ?? 0 );
		$on     = isset( $in['paid_on'] ) && NWC::valid_date( $in['paid_on'] ) ? $in['paid_on'] : NWC::today( NWC_Tenancy::workspace( $v->workspace_id )->timezone );
		$key    = isset( $in['idempotency_key'] ) ? mb_substr( sanitize_text_field( (string) $in['idempotency_key'] ), 0, 64 ) : '';
		if ( $amount <= 0 ) {
			return NWC::err( 'Enter the amount received.' );
		}
		if ( ! NWC::lock( 'inv:' . $v->id ) ) {
			return NWC::err( 'Busy right now. Please try again.', 503, 'nwc_busy' );
		}
		try {
			if ( '' !== $key ) {
				$dup = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $t WHERE invoice_id = %d AND idempotency_key = %s", $v->id, $key ) ); // phpcs:ignore
				if ( $dup ) {
					return $dup;
				}
			}
			$fresh = self::row( $v->id, $v->workspace_id );
			if ( in_array( $fresh->status, array( 'cancelled', 'draft', 'refunded' ), true ) ) {
				return NWC::err( 'draft' === $fresh->status ? 'Send the invoice before recording a payment.' : 'This invoice cannot take payments.', 409, 'nwc_conflict' );
			}
			$left = self::paise( $fresh->grand_total ) - self::paise( $fresh->amount_paid );
			if ( $amount > $left ) {
				return NWC::err( 'That is more than the balance of ' . self::money( self::rupees( $left ) ) . '.', 409, 'nwc_overpayment' );
			}
			$wpdb->insert( $t, array(
				'workspace_id' => (int) $v->workspace_id, 'invoice_id' => (int) $v->id, 'amount' => self::rupees( $amount ), 'paid_on' => $on,
				'method' => mb_substr( sanitize_key( (string) ( $in['method'] ?? 'other' ) ), 0, 20 ), 'reference' => mb_substr( sanitize_text_field( (string) ( $in['reference'] ?? '' ) ), 0, 120 ), 'notes' => sanitize_textarea_field( (string) ( $in['notes'] ?? '' ) ),
				'idempotency_key' => '' !== $key ? $key : null, 'transaction_id' => $transaction_id, 'created_by' => get_current_user_id(), 'created_at' => NWC::now(),
			) );
			$pid = (int) $wpdb->insert_id;
			self::recalc( $v->id, $v->workspace_id );
		} finally {
			NWC::unlock( 'inv:' . $v->id );
		}
		NWC::audit( 'invoice_payment_recorded', array( 'workspace_id' => $v->workspace_id, 'entity' => 'invoice', 'entity_id' => $v->id, 'details' => array( 'amount' => self::rupees( $amount ), 'on' => $on ) ) );
		$after = self::row( $v->id, $v->workspace_id );
		if ( $after && $after->booking_id && 'paid' === $after->status ) { // A fully paid booking invoice confirms its booking.
			NWC_Bookings::payment_event( (int) $after->booking_id, (int) $v->workspace_id, 'paid', 'invoice:' . $v->id );
		}
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $t WHERE id = %d", $pid ) ); // phpcs:ignore
	}

	public static function add_payment( WP_REST_Request $r ) {
		$g = self::guard( 'invoices.write' );
		if ( is_wp_error( $g ) ) {
			return $g;
		}
		$v = self::row( (int) $r['id'] );
		if ( ! $v ) {
			return NWC::err( 'Invoice not found.', 404, 'nwc_not_found' );
		}
		$b   = NWC_REST::body( $r );
		$res = self::apply_payment( $v, $b, NWC_Billing::record_manual( 'business_invoice', $v, $b ) );
		return is_wp_error( $res ) ? $res : self::out( self::row( $v->id ), true );
	}

	public static function void_payment( WP_REST_Request $r ) {
		global $wpdb;
		$g = self::guard( 'invoices.write' );
		if ( is_wp_error( $g ) ) {
			return $g;
		}
		$v = self::row( (int) $r['id'] );
		if ( ! $v ) {
			return NWC::err( 'Invoice not found.', 404, 'nwc_not_found' );
		}
		$reason = sanitize_textarea_field( (string) ( NWC_REST::body( $r )['reason'] ?? '' ) );
		if ( '' === trim( $reason ) ) {
			return NWC::err( 'Give the reason.' );
		}
		$done = $wpdb->update( NWC_DB::t( 'biz_payments' ), array( 'voided_at' => NWC::now(), 'void_reason' => $reason ), array( 'id' => (int) $r['pid'], 'invoice_id' => $v->id, 'workspace_id' => $v->workspace_id, 'voided_at' => null ) );
		if ( ! $done ) {
			return NWC::err( 'Payment not found.', 404, 'nwc_not_found' );
		}
		self::recalc( $v->id, $v->workspace_id );
		NWC::audit( 'invoice_payment_voided', array( 'entity' => 'invoice', 'entity_id' => $v->id, 'details' => array( 'payment' => (int) $r['pid'], 'reason' => $reason ) ) );
		return self::out( self::row( $v->id ), true );
	}

	public static function remind( WP_REST_Request $r ) {
		$g = self::guard( 'invoices.write' );
		if ( is_wp_error( $g ) ) {
			return $g;
		}
		$v = self::row( (int) $r['id'] );
		if ( ! $v ) {
			return NWC::err( 'Invoice not found.', 404, 'nwc_not_found' );
		}
		$c  = json_decode( (string) $v->customer_snapshot, true );
		$to = array_values( array_filter( array_map( 'sanitize_email', (array) ( NWC_REST::body( $r )['to'] ?? array( $c['email'] ?? '' ) ) ), 'is_email' ) );
		if ( ! $to ) {
			return NWC::err( 'This customer has no email address.' );
		}
		if ( ! NWC_Notify::enabled() ) {
			return NWC::err( 'Email is not set up on this site yet, so no reminder was sent.', 409, 'nwc_email_off' );
		}
		$biz = json_decode( (string) $v->business_snapshot, true );
		$bal = self::rupees( self::paise( $v->grand_total ) - self::paise( $v->amount_paid ) );
		$ok  = NWC_Notify::email( $to, 'Payment reminder: ' . $v->doc_no, '<p>A balance of <b>' . esc_html( self::money( $bal ) ) . '</b> is due on invoice ' . esc_html( $v->doc_no ) . '.</p><p><a href="' . esc_url( self::share_url( 'invoice', $v ) ) . '">View invoice</a></p>', $biz['name'] );
		NWC::audit( 'invoice_reminder_sent', array( 'entity' => 'invoice', 'entity_id' => $v->id, 'details' => array( 'to' => $to, 'sent' => $ok ) ) );
		return array( 'sent' => (bool) $ok );
	}

	/* ---------------------------- Sharing ---------------------------- */

	private static function token( $type, $v ) {
		return hash_hmac( 'sha256', $type . '|' . $v->id . '|' . $v->workspace_id . '|' . $v->share_nonce, wp_salt( 'nonce' ) );
	}

	public static function share_url( $type, $v ) {
		return home_url( '/d/' . ( 'invoice' === $type ? 'i' : 'q' ) . '/' . (int) $v->id . '/' . self::token( $type, $v ) );
	}

	/** Rotating the nonce revokes every earlier link. */
	public static function share( WP_REST_Request $r ) {
		return self::share_doc( 'invoice', (int) $r['id'], NWC_REST::body( $r ) );
	}

	public static function quote_share( WP_REST_Request $r ) {
		return self::share_doc( 'quote', (int) $r['id'], NWC_REST::body( $r ) );
	}

	private static function share_doc( $type, $id, $b ) {
		global $wpdb;
		$g = self::guard( 'invoices.write' );
		if ( is_wp_error( $g ) ) {
			return $g;
		}
		$v = 'invoice' === $type ? self::row( $id ) : self::quote_row( $id );
		if ( ! $v ) {
			return NWC::err( 'Not found.', 404, 'nwc_not_found' );
		}
		if ( 'draft' === $v->status && 'invoice' === $type ) {
			return NWC::err( 'Send the invoice before sharing it.', 409, 'nwc_conflict' );
		}
		if ( ! empty( $b['revoke'] ) ) {
			$wpdb->update( NWC_DB::t( 'invoice' === $type ? 'biz_invoices' : 'biz_quotes' ), array( 'share_nonce' => bin2hex( random_bytes( 8 ) ) ), array( 'id' => $v->id ) );
			NWC::audit( 'share_link_revoked', array( 'entity' => $type, 'entity_id' => $v->id ) );
			$v = 'invoice' === $type ? self::row( $id ) : self::quote_row( $id );
		}
		return array( 'url' => self::share_url( $type, $v ) );
	}

	/** @return array|null Document for a valid public link. Cancelled invoices still open, marked. */
	public static function public_doc( $kind, $id, $token ) {
		global $wpdb;
		$type  = 'i' === $kind ? 'invoice' : 'quote';
		$table = NWC_DB::t( 'invoice' === $type ? 'biz_invoices' : 'biz_quotes' );
		$v     = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE id = %d", (int) $id ) ); // phpcs:ignore
		if ( ! $v || ! hash_equals( self::token( $type, $v ), (string) $token ) ) {
			return null;
		}
		if ( 'invoice' === $type && 'draft' === $v->status ) {
			return null;
		}
		NWC::audit( 'share_link_viewed', array( 'workspace_id' => $v->workspace_id, 'kind' => 'data_access', 'entity' => $type, 'entity_id' => $v->id, 'user_id' => 0 ) );
		return array( 'type' => $type, 'doc' => $v, 'items' => self::items( $v->workspace_id, $type, $v->id ) );
	}

	public static function money( $v ) {
		return 'Rs. ' . self::inr_number( $v );
	}

	/* ---------------------------- PDF ---------------------------- */

	public static function pdf( WP_REST_Request $r ) {
		$g = self::guard( 'invoices.read' );
		if ( is_wp_error( $g ) ) {
			return $g;
		}
		$v = self::row( (int) $r['id'] );
		if ( ! $v ) {
			return NWC::err( 'Invoice not found.', 404, 'nwc_not_found' );
		}
		return self::stream_pdf( 'invoice', $v );
	}

	public static function quote_pdf( WP_REST_Request $r ) {
		$g = self::guard( 'invoices.read' );
		if ( is_wp_error( $g ) ) {
			return $g;
		}
		$v = self::quote_row( (int) $r['id'] );
		if ( ! $v ) {
			return NWC::err( 'Quotation not found.', 404, 'nwc_not_found' );
		}
		return self::stream_pdf( 'quote', $v );
	}

	public static function stream_pdf( $type, $v ) {
		$path = self::build_pdf( $type, $v );
		if ( defined( 'NWC_TESTING' ) ) {
			$head = file_get_contents( $path, false, null, 0, 5 ); // phpcs:ignore
			$size = filesize( $path );
			unlink( $path ); // phpcs:ignore
			return array( 'head' => $head, 'size' => $size );
		}
		nocache_headers();
		header( 'Content-Type: application/pdf' );
		header( 'X-Content-Type-Options: nosniff' );
		header( 'Content-Disposition: attachment; filename="' . preg_replace( '/[^A-Za-z0-9_-]/', '-', $v->doc_no ) . '.pdf"' );
		header( 'Content-Length: ' . filesize( $path ) );
		readfile( $path ); // phpcs:ignore
		unlink( $path ); // phpcs:ignore
		exit;
	}

	/**
	 * Local JPEG for a PDF. Only images this site stored for the business are read, never an
	 * arbitrary address. @return string|null Temp file path; the caller deletes it.
	 */
	private static function pdf_image( $url ) {
		$up   = wp_upload_dir();
		$base = trailingslashit( $up['baseurl'] ) . 'nwc-public/';
		if ( ! is_string( $url ) || 0 !== strpos( $url, $base ) || ! function_exists( 'imagecreatefromstring' ) ) {
			return null;
		}
		$rel  = substr( $url, strlen( $base ) );
		if ( ! preg_match( '#^\d+/[a-f0-9]+\.(jpe?g|png|webp)$#', $rel ) ) {
			return null;
		}
		$path = trailingslashit( $up['basedir'] ) . 'nwc-public/' . $rel;
		if ( ! file_exists( $path ) ) {
			return null;
		}
		$src = @imagecreatefromstring( (string) file_get_contents( $path ) ); // phpcs:ignore
		if ( ! $src ) {
			return null;
		}
		$w   = imagesx( $src );
		$h   = imagesy( $src );
		$out = imagecreatetruecolor( $w, $h );
		imagefill( $out, 0, 0, imagecolorallocate( $out, 255, 255, 255 ) ); // Transparent areas become white.
		imagecopy( $out, $src, 0, 0, 0, 0, $w, $h );
		$tmp = tempnam( get_temp_dir(), 'nwi' );
		imagejpeg( $out, $tmp, 88 );
		return $tmp;
	}

	private static function pdf_place( $p, $url, $x, $y, $max_w, $max_h ) {
		$tmp = self::pdf_image( $url );
		if ( ! $tmp ) {
			return 0;
		}
		list( $w, $h ) = getimagesize( $tmp );
		$scale = min( $max_w / max( 1, $w ), $max_h / max( 1, $h ), 1 );
		$p->image_jpeg( $tmp, $x, $y, $w * $scale, $h * $scale );
		unlink( $tmp ); // phpcs:ignore
		return $h * $scale;
	}

	/* ---------------------------- One document view for preview, screen and PDF ---------------------------- */

	/** Indian digit grouping: 1,00,000.00. */
	public static function inr_number( $v ) {
		$neg = (float) $v < 0;
		$s   = number_format( abs( (float) $v ), 2, '.', '' );
		list( $int, $dec ) = explode( '.', $s );
		if ( strlen( $int ) > 3 ) {
			$last = substr( $int, -3 );
			$rest = substr( $int, 0, -3 );
			$rest = preg_replace( '/\B(?=(\d{2})+(?!\d))/', ',', $rest );
			$int  = $rest . ',' . $last;
		}
		return ( $neg ? '-' : '' ) . $int . '.' . $dec;
	}

	/** Rupees in words, Indian system ("Ten Thousand Rupees Only"). */
	public static function words( $amount ) {
		$a     = round( (float) $amount, 2 );
		$r     = (int) floor( $a );
		$p     = (int) round( ( $a - $r ) * 100 );
		$ones  = array( '', 'One', 'Two', 'Three', 'Four', 'Five', 'Six', 'Seven', 'Eight', 'Nine', 'Ten', 'Eleven', 'Twelve', 'Thirteen', 'Fourteen', 'Fifteen', 'Sixteen', 'Seventeen', 'Eighteen', 'Nineteen' );
		$tens  = array( '', '', 'Twenty', 'Thirty', 'Forty', 'Fifty', 'Sixty', 'Seventy', 'Eighty', 'Ninety' );
		$two   = function ( $n ) use ( $ones, $tens ) {
			return $n < 20 ? $ones[ $n ] : trim( $tens[ (int) ( $n / 10 ) ] . ' ' . $ones[ $n % 10 ] );
		};
		$three = function ( $n ) use ( $two, $ones ) {
			$h = (int) ( $n / 100 );
			$t = $n % 100;
			return trim( ( $h ? $ones[ $h ] . ' Hundred' : '' ) . ( $t ? ' ' . $two( $t ) : '' ) );
		};
		$say = function ( $n ) use ( $two, $three ) {
			if ( 0 === $n ) {
				return 'Zero';
			}
			$out = array();
			foreach ( array( 10000000 => 'Crore', 100000 => 'Lakh', 1000 => 'Thousand' ) as $unit => $name ) {
				if ( $n >= $unit ) {
					$q = (int) ( $n / $unit );
					$out[] = ( $q >= 100 ? self::words_plain( $q ) : $two( $q ) ) . ' ' . $name;
					$n %= $unit;
				}
			}
			if ( $n ) {
				$out[] = $three( $n );
			}
			return implode( ' ', $out );
		};
		return $say( $r ) . ' Rupees' . ( $p ? ' and ' . $two( $p ) . ' Paise' : '' ) . ' Only';
	}

	private static function words_plain( $n ) {
		$w = self::words( $n );
		return trim( str_replace( array( ' Rupees Only', ' Rupees' ), '', $w ) );
	}

	/** GST by rate, from the lines (what the tax summary on the document shows). */
	private static function tax_breakdown( $items, $doc ) {
		$by    = array();
		$inter = (float) $doc['igst'] > 0;
		foreach ( $items as $it ) {
			$rate = (float) $it['tax_rate'];
			if ( $rate <= 0 ) {
				continue;
			}
			$k = (string) $rate;
			if ( ! isset( $by[ $k ] ) ) {
				$by[ $k ] = array( 'rate' => $rate, 'taxable' => 0, 'tax' => 0 );
			}
			$net = (int) round( (float) $it['amount'] * 100 );
			$by[ $k ]['taxable'] += $net;
			$by[ $k ]['tax']     += (int) round( $net * $rate / 100 );
		}
		$out = array();
		foreach ( $by as $x ) {
			$c     = $inter ? 0 : (int) floor( $x['tax'] / 2 );
			$out[] = array( 'rate' => $x['rate'], 'taxable' => $x['taxable'] / 100, 'cgst' => $inter ? 0 : $c / 100, 'sgst' => $inter ? 0 : ( $x['tax'] - $c ) / 100, 'igst' => $inter ? $x['tax'] / 100 : 0, 'tax' => $x['tax'] / 100 );
		}
		return $out;
	}

	private static function payment_account_view( $ws, $id ) {
		global $wpdb;
		if ( ! $id ) {
			$id = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . NWC_DB::t( 'payment_accounts' ) . ' WHERE workspace_id = %d AND is_default = 1 AND is_active = 1 AND deleted_at IS NULL ORDER BY id ASC LIMIT 1', $ws ) ); // phpcs:ignore
		}
		$a = $id ? $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . NWC_DB::t( 'payment_accounts' ) . ' WHERE id = %d AND workspace_id = %d AND deleted_at IS NULL', $id, $ws ) ) : null; // phpcs:ignore
		return $a ? array( 'id' => (int) $a->id, 'kind' => $a->kind, 'label' => $a->label, 'text' => NWC_Finance::account_text( $a ), 'upi_id' => (string) $a->upi_id, 'qr_url' => (string) $a->qr_url ) : null;
	}

	/**
	 * The document exactly as the customer sees it, used by the preview, the detail screen and the
	 * PDF alike, so what was previewed is what is generated.
	 */
	public static function view( $type, $d ) {
		$items = array();
		foreach ( (array) $d['items'] as $it ) {
			$it      = (array) $it;
			$items[] = array( 'description' => (string) $it['description'], 'hsn' => (string) ( $it['hsn'] ?? '' ), 'qty' => (float) $it['qty'], 'unit_price' => (float) $it['unit_price'], 'discount' => (float) $it['discount'], 'tax_rate' => (float) $it['tax_rate'], 'amount' => (float) $it['amount'] );
		}
		$biz   = (array) $d['business'];
		$gst   = ! empty( $biz['gst_registered'] );
		$total = (float) $d['grand_total'];
		$paid  = 'invoice' === $type ? (float) ( $d['amount_paid'] ?? 0 ) : 0;
		return array(
			'type'          => $type,
			'title'         => 'invoice' === $type ? ( $gst ? 'Tax Invoice' : 'Invoice' ) : 'Quotation',
			'doc_no'        => (string) $d['doc_no'],
			'number_pending' => ! empty( $d['number_pending'] ),
			'date'          => (string) $d['date'],
			'due_date'      => 'invoice' === $type ? ( $d['due_date'] ?? null ) : null,
			'valid_until'   => 'quote' === $type ? ( $d['valid_until'] ?? null ) : null,
			'status'        => (string) ( $d['status'] ?? 'draft' ),
			'business'      => array( 'name' => (string) ( $biz['name'] ?? '' ), 'logo_url' => (string) ( $biz['logo_url'] ?? '' ), 'address' => (string) ( $biz['address'] ?? '' ), 'phone' => (string) ( $biz['phone'] ?? '' ), 'email' => (string) ( $biz['email'] ?? '' ), 'gstin' => (string) ( $biz['gstin'] ?? '' ), 'website' => (string) ( $biz['website'] ?? '' ), 'state_code' => (string) ( $biz['state_code'] ?? '' ), 'signature_url' => (string) ( $biz['signature_url'] ?? '' ), 'brand_color' => (string) ( $biz['brand_color'] ?? '' ) ),
			'customer'      => (array) $d['customer'],
			'place_of_supply' => (string) ( $d['place_of_supply'] ?? '' ),
			'items'         => $items,
			'totals'        => array( 'subtotal' => (float) $d['subtotal'], 'discount_total' => (float) $d['discount_total'], 'taxable' => (float) ( $d['taxable'] ?? ( (float) $d['subtotal'] - (float) $d['discount_total'] ) ), 'cgst' => (float) $d['cgst'], 'sgst' => (float) $d['sgst'], 'igst' => (float) $d['igst'], 'tax_total' => (float) ( $d['tax_total'] ?? ( (float) $d['cgst'] + (float) $d['sgst'] + (float) $d['igst'] ) ), 'grand_total' => $total, 'amount_paid' => $paid, 'balance' => round( $total - $paid, 2 ) ),
			'tax_breakdown' => $gst ? self::tax_breakdown( $items, $d ) : array(),
			'amount_words'  => self::words( $total ),
			'notes'         => (string) ( $d['notes'] ?? '' ),
			'terms'         => (string) ( $d['terms'] ?? '' ),
			'bank_details'  => (string) ( $d['bank_details'] ?? '' ),
			'payment_account' => $d['payment_account'] ?? null,
			'currency'      => 'INR',
		);
	}

	/** The stored document's view. */
	public static function stored_view( $type, $v ) {
		$items = self::items( $v->workspace_id, $type, $v->id );
		return self::view( $type, array_merge( (array) $v, array(
			'items'    => $items,
			'business' => (array) json_decode( (string) $v->business_snapshot, true ),
			'customer' => (array) json_decode( (string) $v->customer_snapshot, true ),
			'date'     => 'invoice' === $type ? $v->invoice_date : $v->quote_date,
			'status'   => 'invoice' === $type ? self::effective_status( $v ) : self::quote_status_of( $v ),
			'payment_account' => 'invoice' === $type && ! empty( $v->payment_account_id ) ? self::payment_account_view( (int) $v->workspace_id, (int) $v->payment_account_id ) : null,
		) ) );
	}

	/** The number the next document would get, without taking it. */
	private static function peek_number( $ws, $type ) {
		global $wpdb;
		$s     = self::ensure_series( $ws, $type );
		$table = NWC_DB::t( 'invoice' === $type ? 'biz_invoices' : 'biz_quotes' );
		$n     = (int) $s->next_no;
		do {
			$no = $s->prefix . str_pad( (string) $n, (int) $s->padding, '0', STR_PAD_LEFT );
			$n++;
		} while ( $wpdb->get_var( $wpdb->prepare( "SELECT id FROM $table WHERE workspace_id = %d AND doc_no = %s", $ws, $no ) ) ); // phpcs:ignore
		return $no;
	}

	/**
	 * POST /invoices/preview and /quotes/preview: the same checks and arithmetic as saving, but
	 * nothing is written (no customer, no number taken). Editing an existing draft passes its id.
	 */
	private static function preview_doc( $type, WP_REST_Request $r ) {
		global $wpdb;
		$g = self::guard( 'invoices.write' );
		if ( is_wp_error( $g ) ) {
			return $g;
		}
		$b   = NWC_REST::body( $r );
		$ws  = (int) NWC_Tenancy::$current->id;
		$biz = NWC_Bookings::business( $ws );
		$old = ! empty( $b['id'] ) ? ( 'invoice' === $type ? self::row( absint( $b['id'] ) ) : self::quote_row( absint( $b['id'] ) ) ) : null;
		$cid = absint( $b['customer_id'] ?? ( $old ? $old->customer_id : 0 ) );
		if ( $cid ) {
			$c = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . NWC_DB::t( 'customers' ) . ' WHERE id = %d AND workspace_id = %d AND deleted_at IS NULL', $cid, $ws ) ); // phpcs:ignore
			if ( ! $c ) {
				return NWC::err( 'Choose a customer.', 400 );
			}
			$cust = (array) json_decode( self::snapshot_customer( $c ), true );
			$cst  = (string) $c->state_code;
		} else {
			$n = is_array( $b['new_customer'] ?? null ) ? $b['new_customer'] : array();
			if ( '' === trim( (string) ( $n['name'] ?? '' ) ) ) {
				return NWC::err( 'Enter the customer\'s name.' );
			}
			$cust = array();
			foreach ( array( 'name', 'business_name', 'contact_person', 'phone', 'email', 'gstin', 'state_code' ) as $k ) {
				$cust[ $k ] = mb_substr( sanitize_text_field( (string) ( $n[ $k ] ?? '' ) ), 0, 190 );
			}
			foreach ( array( 'billing_address', 'shipping_address' ) as $k ) {
				$cust[ $k ] = sanitize_textarea_field( (string) ( $n[ $k ] ?? '' ) );
			}
			$cst = $cust['state_code'];
		}
		$pos  = mb_substr( sanitize_text_field( (string) ( $b['place_of_supply'] ?? $cst ) ), 0, 4 );
		$calc = self::compute( $b['items'] ?? array(), $biz, $pos );
		if ( is_wp_error( $calc ) ) {
			return $calc;
		}
		$today = NWC::today( NWC_Tenancy::$current->timezone );
		$date  = 'invoice' === $type ? ( isset( $b['invoice_date'] ) && NWC::valid_date( $b['invoice_date'] ) ? $b['invoice_date'] : $today ) : ( isset( $b['quote_date'] ) && NWC::valid_date( $b['quote_date'] ) ? $b['quote_date'] : $today );
		$due   = isset( $b['due_date'] ) && NWC::valid_date( $b['due_date'] ) ? $b['due_date'] : null;
		$valid = isset( $b['valid_until'] ) && NWC::valid_date( $b['valid_until'] ) ? $b['valid_until'] : null;
		if ( $due && $due < $date ) {
			return NWC::err( 'The due date is before the invoice date.' );
		}
		if ( $valid && $valid < $date ) {
			return NWC::err( 'The validity date is before the quotation date.' );
		}
		$acct = 'invoice' === $type ? self::payment_account_view( $ws, absint( $b['payment_account_id'] ?? 0 ) ) : null;
		$bank = isset( $b['bank_details'] ) && '' !== trim( (string) $b['bank_details'] ) ? sanitize_textarea_field( (string) $b['bank_details'] ) : ( $acct ? $acct['text'] : (string) $biz->bank_details );
		$doc  = array_merge( $calc['totals'], array(
			'doc_no'          => $old ? $old->doc_no : self::peek_number( $ws, $type ),
			'number_pending'  => ! $old,
			'date'            => $date,
			'due_date'        => $due,
			'valid_until'     => $valid,
			'status'          => 'draft',
			'business'        => (array) json_decode( self::snapshot_business( $biz ), true ),
			'customer'        => $cust,
			'place_of_supply' => $pos,
			'items'           => $calc['items'],
			'notes'           => sanitize_textarea_field( (string) ( $b['notes'] ?? '' ) ),
			'terms'           => sanitize_textarea_field( (string) ( isset( $b['terms'] ) && '' !== $b['terms'] ? $b['terms'] : ( 'invoice' === $type ? $biz->payment_terms : '' ) ) ),
			'bank_details'    => 'invoice' === $type ? $bank : '',
			'payment_account' => $acct,
		) );
		return self::view( $type, $doc );
	}

	public static function preview( WP_REST_Request $r ) {
		return self::preview_doc( 'invoice', $r );
	}

	public static function quote_preview( WP_REST_Request $r ) {
		return self::preview_doc( 'quote', $r );
	}

	/** Built on demand from the stored snapshot, so it never changes after issue. */
	public static function build_pdf( $type, $v ) {
		return self::render_pdf( self::stored_view( $type, $v ) );
	}

	private static function hex_rgb( $hex, $fallback ) {
		$hex = ltrim( (string) $hex, '#' );
		if ( ! preg_match( '/^[0-9a-fA-F]{6}$/', $hex ) ) {
			return $fallback;
		}
		$rgb = array( hexdec( substr( $hex, 0, 2 ) ), hexdec( substr( $hex, 2, 2 ) ), hexdec( substr( $hex, 4, 2 ) ) );
		// A very light brand colour would vanish on white paper: keep the default then.
		return ( 0.299 * $rgb[0] + 0.587 * $rgb[1] + 0.114 * $rgb[2] ) > 170 ? $fallback : $rgb;
	}

	private static function fdate( $d ) {
		return $d ? gmdate( 'd M Y', strtotime( $d . ' 12:00:00 UTC' ) ) : '';
	}

	private static function qty_text( $q ) {
		return rtrim( rtrim( number_format( (float) $q, 3, '.', '' ), '0' ), '.' );
	}

	/**
	 * Phase 5 document design (Workspace invoices and quotations only; WorkDesk's own agency
	 * invoices use their own template). A4 portrait, 40 pt margins, a brand accent band, header
	 * with logo and business details, a document box, Bill to and Amount due cards, a line table
	 * that repeats its header on every page, totals with the tax breakdown and the amount in words,
	 * payment details with the QR, notes, terms and signature, and a footer with page numbers.
	 */
	public static function render_pdf( $d ) {
		$type   = $d['type'];
		$biz    = $d['business'];
		$cust   = $d['customer'];
		$t      = $d['totals'];
		$ink    = array( 17, 24, 39 );
		$soft   = array( 75, 85, 99 );
		$faint  = array( 107, 114, 128 );
		$acc    = self::hex_rgb( $biz['brand_color'], array( 91, 33, 182 ) );
		$panel  = array( 246, 247, 250 );
		$p      = new NWC_PDF( $d['title'] . ' ' . $d['doc_no'], false, false );
		$p->set_margin( 40 );
		$m  = 40;
		$w  = $p->page_width();
		$cw = $w - 2 * $m;
		$p->set_footer( $d['doc_no'] . '  ·  ' . $biz['name'], 'Created with Neon Workspace' );

		// Accent band.
		$p->set_fill( $acc[0], $acc[1], $acc[2] );
		$p->rect( 0, 0, $w, 6, true );

		// Header left: logo, name, contact lines.
		$y      = 34;
		$logo_h = self::pdf_place( $p, $biz['logo_url'], $m, $y, 120, 54 );
		$ty     = $logo_h ? $y + $logo_h + 16 : $y + 12;
		$p->color( $ink[0], $ink[1], $ink[2] );
		$p->font( true, 16 );
		foreach ( array_slice( $p->wrap( $biz['name'], 280 ), 0, 2 ) as $line ) {
			$p->text( $m, $ty, $line );
			$ty += 18;
		}
		$p->font( false, 8.5 );
		$p->color( $soft[0], $soft[1], $soft[2] );
		$ty -= 4;
		$lines = array_filter( array_merge( preg_split( "/\r\n|\n|\r/", trim( $biz['address'] ) ), array( trim( implode( '  ·  ', array_filter( array( $biz['phone'], $biz['email'] ) ) ) ), $biz['website'], '' !== $biz['gstin'] ? 'GSTIN ' . $biz['gstin'] : '' ) ) );
		foreach ( $lines as $line ) {
			foreach ( $p->wrap( trim( $line ), 270 ) as $l ) {
				$p->text( $m, $ty, $l );
				$ty += 11;
			}
		}

		// Header right: document title and the details box.
		$bx = $w - $m - 200;
		$p->color( $acc[0], $acc[1], $acc[2] );
		$p->font( true, 22 );
		$p->text_right( $w - $m, $y + 18, strtoupper( $d['title'] ) );
		$meta = array( array( 'invoice' === $type ? 'Invoice no.' : 'Quotation no.', $d['doc_no'] . ( $d['number_pending'] ? ' (on save)' : '' ) ), array( 'invoice' === $type ? 'Invoice date' : 'Date', self::fdate( $d['date'] ) ) );
		if ( $d['due_date'] ) {
			$meta[] = array( 'Due date', self::fdate( $d['due_date'] ) );
		}
		if ( $d['valid_until'] ) {
			$meta[] = array( 'Valid until', self::fdate( $d['valid_until'] ) );
		}
		if ( '' !== $d['place_of_supply'] && ! empty( $d['tax_breakdown'] ) ) {
			$meta[] = array( 'Place of supply', $d['place_of_supply'] );
		}
		$bh = 14 + 15 * count( $meta );
		$by = $y + 30;
		$p->set_fill( $panel[0], $panel[1], $panel[2] );
		$p->rect( $bx, $by, 200, $bh, true );
		$ry = $by + 17;
		foreach ( $meta as $row ) {
			$p->font( false, 8.5 );
			$p->color( $faint[0], $faint[1], $faint[2] );
			$p->text( $bx + 10, $ry, $row[0] );
			$p->font( true, 9 );
			$p->color( $ink[0], $ink[1], $ink[2] );
			$p->text_right( $bx + 190, $ry, $row[1] );
			$ry += 15;
		}
		$status = $d['status'];
		if ( in_array( $status, array( 'paid', 'cancelled', 'overdue', 'accepted', 'rejected', 'expired', 'converted' ), true ) ) {
			$col = in_array( $status, array( 'paid', 'accepted', 'converted' ), true ) ? array( 22, 128, 61 ) : array( 185, 28, 28 );
			$p->font( true, 9 );
			$label = strtoupper( 'converted' === $status ? 'Invoiced' : $status );
			$lw    = $p->width( $label ) + 16;
			$p->set_fill( $col[0], $col[1], $col[2] );
			$p->rect( $w - $m - $lw, $by + $bh + 8, $lw, 16, true );
			$p->color( 255, 255, 255 );
			$p->text( $w - $m - $lw + 8, $by + $bh + 19, $label );
			$bh += 26;
		}
		$y = max( $ty, $by + $bh ) + 18;

		// Bill to (left) and the amount card (right).
		$p->stroke( 229, 231, 235 );
		$p->line( $m, $y, $w - $m, $y );
		$y += 16;
		$p->font( true, 7.5 );
		$p->color( $faint[0], $faint[1], $faint[2] );
		$p->text( $m, $y, 'invoice' === $type ? 'BILL TO' : 'PREPARED FOR' );
		$cy = $y + 15;
		$p->font( true, 11 );
		$p->color( $ink[0], $ink[1], $ink[2] );
		$who = '' !== (string) ( $cust['business_name'] ?? '' ) ? $cust['business_name'] : (string) ( $cust['name'] ?? '' );
		$p->text( $m, $cy, $who );
		$cy += 13;
		$p->font( false, 8.5 );
		$p->color( $soft[0], $soft[1], $soft[2] );
		$cl = array_filter( array_merge( array( '' !== (string) ( $cust['business_name'] ?? '' ) && (string) ( $cust['name'] ?? '' ) !== $cust['business_name'] ? 'Attn: ' . ( $cust['contact_person'] ?: $cust['name'] ) : '' ), preg_split( "/\r\n|\n|\r/", trim( (string) ( $cust['billing_address'] ?? '' ) ) ), array( trim( implode( '  ·  ', array_filter( array( $cust['phone'] ?? '', $cust['email'] ?? '' ) ) ) ), ! empty( $cust['gstin'] ) ? 'GSTIN ' . $cust['gstin'] : '' ) ) );
		foreach ( $cl as $line ) {
			foreach ( $p->wrap( trim( $line ), 260 ) as $l ) {
				$p->text( $m, $cy, $l );
				$cy += 11;
			}
		}
		if ( ! empty( $cust['shipping_address'] ) && trim( $cust['shipping_address'] ) !== trim( (string) ( $cust['billing_address'] ?? '' ) ) ) {
			$cy += 6;
			$p->font( true, 7.5 );
			$p->color( $faint[0], $faint[1], $faint[2] );
			$p->text( $m, $cy, 'SHIP TO' );
			$cy += 12;
			$p->font( false, 8.5 );
			$p->color( $soft[0], $soft[1], $soft[2] );
			foreach ( preg_split( "/\r\n|\n|\r/", trim( $cust['shipping_address'] ) ) as $l ) {
				$p->text( $m, $cy, trim( $l ) );
				$cy += 11;
			}
		}
		$ax = $w - $m - 200;
		$p->set_fill( $ink[0], $ink[1], $ink[2] );
		$p->rect( $ax, $y - 10, 200, 62, true );
		$p->font( true, 7.5 );
		$p->color( 203, 213, 225 );
		$due_amt = 'invoice' === $type ? $t['balance'] : $t['grand_total'];
		$p->text( $ax + 14, $y + 6, 'invoice' === $type ? ( $t['amount_paid'] > 0 ? 'BALANCE DUE' : 'AMOUNT DUE' ) : 'QUOTED TOTAL' );
		$p->font( true, 18 );
		$p->color( 255, 255, 255 );
		$p->text( $ax + 14, $y + 28, 'Rs. ' . self::inr_number( $due_amt ) );
		$p->font( false, 8 );
		$p->color( 203, 213, 225 );
		$sub = 'invoice' === $type ? ( $d['due_date'] ? 'Due by ' . self::fdate( $d['due_date'] ) : 'Due on receipt' ) : ( $d['valid_until'] ? 'Valid until ' . self::fdate( $d['valid_until'] ) : 'Valid on request' );
		$p->text( $ax + 14, $y + 43, $sub );
		$p->set_y( max( $cy, $y + 52 ) + 18 );

		// Lines.
		$p->set_table_style( array( 'head_fill' => $ink, 'head_text' => array( 255, 255, 255 ), 'zebra' => array( 249, 250, 251 ), 'text' => array( 31, 41, 55 ), 'line' => array( 229, 231, 235 ), 'row_lines' => true, 'pad' => 6, 'head_h' => 12 ) );
		$gst  = ! empty( $d['tax_breakdown'] );
		$rows = array();
		foreach ( $d['items'] as $n => $it ) {
			$row = array( (string) ( $n + 1 ), $it['description'] . ( '' !== $it['hsn'] ? "\nHSN/SAC " . $it['hsn'] : '' ), self::qty_text( $it['qty'] ), self::inr_number( $it['unit_price'] ), $it['discount'] ? self::inr_number( $it['discount'] ) : '-' );
			if ( $gst ) {
				$row[] = $it['tax_rate'] ? self::qty_text( $it['tax_rate'] ) . '%' : '-';
			}
			$row[]  = self::inr_number( $it['amount'] );
			$rows[] = $row;
		}
		$head   = $gst ? array( '#', 'DESCRIPTION', 'QTY', 'UNIT PRICE', 'DISCOUNT', 'GST', 'AMOUNT' ) : array( '#', 'DESCRIPTION', 'QTY', 'UNIT PRICE', 'DISCOUNT', 'AMOUNT' );
		$widths = $gst ? array( 20, 205, 38, 70, 62, 38, 82 ) : array( 20, 243, 40, 72, 66, 84 );
		$align  = $gst ? array( 'L', 'L', 'R', 'R', 'R', 'R', 'R' ) : array( 'L', 'L', 'R', 'R', 'R', 'R' );
		$p->table( $head, $rows, $widths, 8.5, $align );

		// Totals (right) with words and the GST summary (left).
		$tot = array( array( 'Subtotal', $t['subtotal'], false ) );
		if ( $t['discount_total'] ) {
			$tot[] = array( 'Discount', -1 * $t['discount_total'], false );
			$tot[] = array( 'Taxable value', $t['taxable'], false );
		}
		foreach ( array( 'CGST' => $t['cgst'], 'SGST' => $t['sgst'], 'IGST' => $t['igst'] ) as $k => $val ) {
			if ( $val ) {
				$tot[] = array( $k, $val, false );
			}
		}
		$need = 26 + 16 * count( $tot ) + ( 'invoice' === $type && $t['amount_paid'] ? 36 : 0 );
		$p->ensure( max( $need, 80 ) );
		$y0 = $p->get_y() - 4;
		$tx = $w - $m - 220;
		$yy = $y0;
		foreach ( $tot as $row ) {
			$p->font( false, 9 );
			$p->color( $soft[0], $soft[1], $soft[2] );
			$p->text( $tx + 8, $yy + 10, $row[0] );
			$p->color( $ink[0], $ink[1], $ink[2] );
			$p->text_right( $w - $m - 8, $yy + 10, ( $row[1] < 0 ? '- ' : '' ) . 'Rs. ' . self::inr_number( abs( $row[1] ) ) );
			$yy += 16;
		}
		$p->set_fill( $acc[0], $acc[1], $acc[2] );
		$p->rect( $tx, $yy, 220, 24, true );
		$p->color( 255, 255, 255 );
		$p->font( true, 10.5 );
		$p->text( $tx + 8, $yy + 16, 'invoice' === $type ? 'Total' : 'Quoted total' );
		$p->text_right( $w - $m - 8, $yy + 16, 'Rs. ' . self::inr_number( $t['grand_total'] ) );
		$yy += 24;
		if ( 'invoice' === $type && $t['amount_paid'] ) {
			$p->font( false, 9 );
			$p->color( $soft[0], $soft[1], $soft[2] );
			$p->text( $tx + 8, $yy + 14, 'Paid' );
			$p->text_right( $w - $m - 8, $yy + 14, '- Rs. ' . self::inr_number( $t['amount_paid'] ) );
			$p->font( true, 10 );
			$p->color( $ink[0], $ink[1], $ink[2] );
			$p->text( $tx + 8, $yy + 30, 'Balance due' );
			$p->text_right( $w - $m - 8, $yy + 30, 'Rs. ' . self::inr_number( $t['balance'] ) );
			$yy += 36;
		}
		$ly = $y0;
		$lw = $tx - $m - 20;
		$p->font( true, 7.5 );
		$p->color( $faint[0], $faint[1], $faint[2] );
		$p->text( $m, $ly + 10, 'AMOUNT IN WORDS' );
		$ly += 22;
		$p->font( false, 9 );
		$p->color( $ink[0], $ink[1], $ink[2] );
		foreach ( $p->wrap( $d['amount_words'], $lw ) as $l ) {
			$p->text( $m, $ly, $l );
			$ly += 12;
		}
		if ( $gst ) {
			$ly += 8;
			$p->font( true, 7.5 );
			$p->color( $faint[0], $faint[1], $faint[2] );
			$p->text( $m, $ly, 'GST SUMMARY' );
			$ly += 13;
			$cols = array( $m, $m + 48, $m + 140, $m + 230 );
			$p->font( true, 8 );
			$p->color( $soft[0], $soft[1], $soft[2] );
			$p->text( $cols[0], $ly, 'Rate' );
			$p->text_right( $cols[2] - 8, $ly, 'Taxable' );
			$p->text_right( min( $cols[3], $m + $lw ), $ly, (float) $t['igst'] ? 'IGST' : 'CGST + SGST' );
			$ly += 4;
			$p->stroke( 229, 231, 235 );
			$p->line( $m, $ly, $m + $lw, $ly );
			$ly += 11;
			$p->font( false, 8.5 );
			$p->color( $ink[0], $ink[1], $ink[2] );
			foreach ( $d['tax_breakdown'] as $x ) {
				$p->text( $cols[0], $ly, self::qty_text( $x['rate'] ) . '%' );
				$p->text_right( $cols[2] - 8, $ly, self::inr_number( $x['taxable'] ) );
				$p->text_right( min( $cols[3], $m + $lw ), $ly, (float) $t['igst'] ? self::inr_number( $x['igst'] ) : self::inr_number( $x['cgst'] ) . ' + ' . self::inr_number( $x['sgst'] ) );
				$ly += 12;
			}
		}
		$p->set_y( max( $yy, $ly ) + 22 );

		// Payment details, with the QR when the chosen account has one.
		$acct = $d['payment_account'];
		$bank = trim( (string) $d['bank_details'] );
		if ( 'invoice' === $type && ( '' !== $bank || ( $acct && $acct['qr_url'] ) ) ) {
			$bl   = '' !== $bank ? $p->wrap( $bank, $cw - 150 ) : array();
			$hgt  = max( 34 + 12 * count( $bl ), $acct && $acct['qr_url'] ? 104 : 0 );
			$p->ensure( $hgt + 10 );
			$py = $p->get_y();
			$p->set_fill( $panel[0], $panel[1], $panel[2] );
			$p->rect( $m, $py, $cw, $hgt, true );
			$p->set_fill( $acc[0], $acc[1], $acc[2] );
			$p->rect( $m, $py, 3, $hgt, true );
			$p->font( true, 7.5 );
			$p->color( $faint[0], $faint[1], $faint[2] );
			$p->text( $m + 14, $py + 16, 'PAYMENT DETAILS' );
			$p->font( false, 9 );
			$p->color( $ink[0], $ink[1], $ink[2] );
			$ky = $py + 31;
			foreach ( $bl as $l ) {
				$p->text( $m + 14, $ky, $l );
				$ky += 12;
			}
			if ( $acct && $acct['qr_url'] ) {
				$qh = self::pdf_place( $p, $acct['qr_url'], $w - $m - 98, $py + 8, 88, 88 );
				if ( $qh ) {
					$p->font( false, 7 );
					$p->color( $faint[0], $faint[1], $faint[2] );
					$p->text_center( $w - $m - 54, $py + $hgt - 2, 'Scan to pay' );
				}
			}
			$p->set_y( $py + $hgt + 18 );
		}

		// Notes and terms.
		foreach ( array( 'NOTES' => $d['notes'], 'invoice' === $type ? 'TERMS AND CONDITIONS' : 'TERMS' => $d['terms'] ) as $k => $val ) {
			if ( '' === trim( (string) $val ) ) {
				continue;
			}
			$p->ensure( 40 );
			$p->font( true, 7.5 );
			$p->color( $faint[0], $faint[1], $faint[2] );
			$p->text( $m, $p->get_y(), $k );
			$p->space( 4 );
			$p->paragraph( (string) $val, 8.5, $soft );
			$p->space( 4 );
		}

		// Signature.
		$p->ensure( 90 );
		$sy    = $p->get_y() + 6;
		$sig_h = '' !== $biz['signature_url'] ? self::pdf_place( $p, $biz['signature_url'], $w - $m - 140, $sy, 140, 50 ) : 0;
		$line  = $sy + max( $sig_h, 40 ) + 6;
		$p->stroke( 203, 213, 225 );
		$p->line( $w - $m - 160, $line, $w - $m, $line );
		$p->font( false, 8 );
		$p->color( $faint[0], $faint[1], $faint[2] );
		$p->text_right( $w - $m, $line + 12, 'Authorised signatory' );
		$p->font( true, 8.5 );
		$p->color( $ink[0], $ink[1], $ink[2] );
		$p->text_right( $w - $m, $line + 24, 'For ' . $biz['name'] );
		if ( 'invoice' === $type ) {
			$p->font( false, 8 );
			$p->color( $faint[0], $faint[1], $faint[2] );
			$p->text( $m, $line + 24, 'Thank you for your business.' );
		}
		$p->set_y( $line + 30 );
		$file = tempnam( get_temp_dir(), 'nwc' );
		$p->save( $file );
		return $file;
	}

	/* ---------------------------- Quotations ---------------------------- */

	/** Draft or sent quotations past their validity read as expired; the stored status stays. */
	public static function quote_status_of( $q, $today = null ) {
		$today = $today ? $today : NWC::today( NWC_Tenancy::$current ? NWC_Tenancy::$current->timezone : 'UTC' );
		if ( in_array( $q->status, array( 'draft', 'sent' ), true ) && $q->valid_until && $q->valid_until < $today ) {
			return 'expired';
		}
		return $q->status;
	}

	public static function quote_row( $id ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . NWC_DB::t( 'biz_quotes' ) . ' WHERE id = %d AND workspace_id = %d', $id, NWC_Tenancy::$current->id ) ); // phpcs:ignore
	}

	private static function quote_out( $q, $detail = false ) {
		$o = array( 'id' => (int) $q->id, 'doc_no' => $q->doc_no, 'customer_id' => $q->customer_id ? (int) $q->customer_id : null, 'customer' => json_decode( (string) $q->customer_snapshot, true ), 'quote_date' => $q->quote_date, 'valid_until' => $q->valid_until, 'grand_total' => (float) $q->grand_total, 'status' => self::quote_status_of( $q ), 'stored_status' => $q->status, 'converted_invoice_id' => $q->converted_invoice_id ? (int) $q->converted_invoice_id : null );
		if ( $detail ) {
			$o += array( 'business' => json_decode( (string) $q->business_snapshot, true ), 'place_of_supply' => $q->place_of_supply, 'subtotal' => (float) $q->subtotal, 'discount_total' => (float) $q->discount_total, 'cgst' => (float) $q->cgst, 'sgst' => (float) $q->sgst, 'igst' => (float) $q->igst, 'notes' => (string) $q->notes, 'terms' => (string) $q->terms, 'items' => self::items( $q->workspace_id, 'quote', $q->id ), 'view' => self::stored_view( 'quote', $q ) );
		}
		return $o;
	}

	/**
	 * GET /quotes?q=&status=&from=&to=&sort= : number or customer (name, business, phone, email),
	 * status (draft, sent, accepted, rejected, expired, converted) and a date range.
	 */
	public static function quotes( WP_REST_Request $r = null ) {
		global $wpdb;
		$g = self::guard( 'invoices.read' );
		if ( is_wp_error( $g ) ) {
			return $g;
		}
		$vals = array( (int) NWC_Tenancy::$current->id );
		$sql  = 'workspace_id = %d';
		$q    = $r ? trim( sanitize_text_field( (string) $r->get_param( 'q' ) ) ) : '';
		if ( '' !== $q ) {
			$like   = '%' . $wpdb->esc_like( $q ) . '%';
			$sql   .= ' AND (doc_no LIKE %s OR customer_snapshot LIKE %s)';
			$vals[] = $like;
			$vals[] = $like;
		}
		foreach ( array( 'from' => '>=', 'to' => '<=' ) as $k => $op ) {
			$d = $r ? (string) $r->get_param( $k ) : '';
			if ( NWC::valid_date( $d ) ) {
				$sql   .= " AND quote_date $op %s";
				$vals[] = $d;
			}
		}
		$order = $r && 'asc' === $r->get_param( 'sort' ) ? 'ASC' : 'DESC';
		$rows  = array_map( array( __CLASS__, 'quote_out' ), $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . NWC_DB::t( 'biz_quotes' ) . " WHERE $sql ORDER BY quote_date $order, id $order LIMIT 300", $vals ) ) ); // phpcs:ignore
		$want  = $r ? sanitize_key( (string) $r->get_param( 'status' ) ) : '';
		if ( '' !== $want ) {
			$rows = array_values( array_filter( $rows, function ( $x ) use ( $want ) {
				return $x['status'] === $want;
			} ) );
		}
		$counts = array();
		foreach ( $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . NWC_DB::t( 'biz_quotes' ) . ' WHERE workspace_id = %d', NWC_Tenancy::$current->id ) ) as $x ) { // phpcs:ignore
			$s            = self::quote_status_of( $x );
			$counts[ $s ] = ( $counts[ $s ] ?? 0 ) + 1;
		}
		return array( 'items' => $rows, 'counts' => $counts );
	}

	public static function quote_show( WP_REST_Request $r ) {
		$g = self::guard( 'invoices.read' );
		if ( is_wp_error( $g ) ) {
			return $g;
		}
		$q = self::quote_row( (int) $r['id'] );
		return $q ? self::quote_out( $q, true ) : NWC::err( 'Quotation not found.', 404, 'nwc_not_found' );
	}

	public static function quote_create( WP_REST_Request $r ) {
		global $wpdb;
		$g = self::guard( 'invoices.write' );
		if ( is_wp_error( $g ) ) {
			return $g;
		}
		$b   = NWC_REST::body( $r );
		$ws  = (int) NWC_Tenancy::$current->id;
		$biz = NWC_Bookings::business( $ws );
		$c   = self::resolve_customer( $b, $ws );
		if ( is_wp_error( $c ) ) {
			return $c;
		}
		$pos  = mb_substr( sanitize_text_field( (string) ( $b['place_of_supply'] ?? $c->state_code ) ), 0, 4 );
		$calc = self::compute( $b['items'] ?? array(), $biz, $pos );
		if ( is_wp_error( $calc ) ) {
			return $calc;
		}
		$no = self::next_number( $ws, 'quote' );
		if ( is_wp_error( $no ) ) {
			return $no;
		}
		$now = NWC::now();
		$wpdb->insert( NWC_DB::t( 'biz_quotes' ), array_merge( $calc['totals'], array(
			'workspace_id' => $ws, 'doc_no' => $no, 'customer_id' => (int) $c->id, 'customer_snapshot' => self::snapshot_customer( $c ), 'business_snapshot' => self::snapshot_business( $biz ),
			'quote_date' => isset( $b['quote_date'] ) && NWC::valid_date( $b['quote_date'] ) ? $b['quote_date'] : NWC::today( NWC_Tenancy::$current->timezone ), 'valid_until' => isset( $b['valid_until'] ) && NWC::valid_date( $b['valid_until'] ) ? $b['valid_until'] : null,
			'place_of_supply' => $pos, 'status' => 'draft', 'notes' => sanitize_textarea_field( (string) ( $b['notes'] ?? '' ) ), 'terms' => sanitize_textarea_field( (string) ( $b['terms'] ?? '' ) ), 'share_nonce' => bin2hex( random_bytes( 8 ) ), 'created_by' => get_current_user_id(), 'created_at' => $now, 'updated_at' => $now,
		) ) );
		$id = (int) $wpdb->insert_id;
		self::save_items( $ws, 'quote', $id, $calc['items'] );
		NWC::audit( 'quote_created', array( 'entity' => 'quote', 'entity_id' => $id ) );
		return self::quote_out( self::quote_row( $id ), true );
	}

	public static function quote_update( WP_REST_Request $r ) {
		global $wpdb;
		$g = self::guard( 'invoices.write' );
		if ( is_wp_error( $g ) ) {
			return $g;
		}
		$q = self::quote_row( (int) $r['id'] );
		if ( ! $q ) {
			return NWC::err( 'Quotation not found.', 404, 'nwc_not_found' );
		}
		if ( 'draft' !== $q->status ) {
			return NWC::err( 'Only a draft quotation can be edited.', 409, 'nwc_conflict' );
		}
		$b   = NWC_REST::body( $r );
		$biz = NWC_Bookings::business( $q->workspace_id );
		$row = array( 'updated_at' => NWC::now() );
		$pos = array_key_exists( 'place_of_supply', $b ) ? mb_substr( sanitize_text_field( (string) $b['place_of_supply'] ), 0, 4 ) : $q->place_of_supply;
		$row['place_of_supply'] = $pos;
		if ( isset( $b['items'] ) ) {
			$calc = self::compute( $b['items'], $biz, $pos );
			if ( is_wp_error( $calc ) ) {
				return $calc;
			}
			$row = array_merge( $row, $calc['totals'] );
			self::save_items( $q->workspace_id, 'quote', $q->id, $calc['items'] );
		}
		foreach ( array( 'notes', 'terms' ) as $k ) {
			if ( array_key_exists( $k, $b ) ) {
				$row[ $k ] = sanitize_textarea_field( (string) $b[ $k ] );
			}
		}
		if ( array_key_exists( 'valid_until', $b ) ) {
			$row['valid_until'] = NWC::valid_date( $b['valid_until'] ) ? $b['valid_until'] : null;
		}
		$wpdb->update( NWC_DB::t( 'biz_quotes' ), $row, array( 'id' => $q->id, 'workspace_id' => $q->workspace_id ) );
		return self::quote_out( self::quote_row( $q->id ), true );
	}

	public static function quote_status( WP_REST_Request $r ) {
		global $wpdb;
		$g = self::guard( 'invoices.write' );
		if ( is_wp_error( $g ) ) {
			return $g;
		}
		$q = self::quote_row( (int) $r['id'] );
		if ( ! $q ) {
			return NWC::err( 'Quotation not found.', 404, 'nwc_not_found' );
		}
		$s = (string) ( NWC_REST::body( $r )['status'] ?? '' );
		if ( ! in_array( $s, array( 'sent', 'accepted', 'rejected' ), true ) ) {
			return NWC::err( 'Unknown status.' );
		}
		if ( 'converted' === $q->status ) {
			return NWC::err( 'This quotation has already become an invoice.', 409, 'nwc_conflict' );
		}
		if ( 'sent' !== $s && 'draft' === $q->status ) {
			return NWC::err( 'Mark the quotation as sent first.', 409, 'nwc_conflict' );
		}
		$wpdb->update( NWC_DB::t( 'biz_quotes' ), array( 'status' => $s, 'updated_at' => NWC::now() ), array( 'id' => $q->id ) );
		NWC::audit( 'quote_' . $s, array( 'entity' => 'quote', 'entity_id' => $q->id ) );
		return self::quote_out( self::quote_row( $q->id ), true );
	}

	/** Accepted quotation → one invoice, keeping a reference to the original. */
	public static function quote_convert( WP_REST_Request $r ) {
		global $wpdb;
		$g = self::guard( 'invoices.write' );
		if ( is_wp_error( $g ) ) {
			return $g;
		}
		$q = self::quote_row( (int) $r['id'] );
		if ( ! $q ) {
			return NWC::err( 'Quotation not found.', 404, 'nwc_not_found' );
		}
		if ( 'accepted' !== $q->status ) {
			return NWC::err( $q->converted_invoice_id ? 'This quotation has already become an invoice.' : 'Only an accepted quotation can become an invoice.', 409, 'nwc_conflict' );
		}
		$won = $wpdb->query( $wpdb->prepare( 'UPDATE ' . NWC_DB::t( 'biz_quotes' ) . " SET status = 'converted', updated_at = %s WHERE id = %d AND status = 'accepted'", NWC::now(), $q->id ) ); // phpcs:ignore
		if ( ! $won ) {
			return NWC::err( 'This quotation has already become an invoice.', 409, 'nwc_conflict' );
		}
		$items = array_map( function ( $i ) {
			return (array) $i;
		}, self::items( $q->workspace_id, 'quote', $q->id ) );
		$body  = array( 'customer_id' => (int) $q->customer_id, 'items' => $items, 'place_of_supply' => $q->place_of_supply, 'notes' => $q->notes );
		if ( '' !== (string) $q->terms ) {
			$body['terms'] = $q->terms;
		}
		$id = self::create_doc( $body, (int) $q->id );
		if ( is_wp_error( $id ) ) {
			$wpdb->update( NWC_DB::t( 'biz_quotes' ), array( 'status' => 'accepted' ), array( 'id' => $q->id ) );
			return $id;
		}
		$wpdb->update( NWC_DB::t( 'biz_quotes' ), array( 'converted_invoice_id' => $id ), array( 'id' => $q->id ) );
		NWC::audit( 'quote_converted', array( 'entity' => 'quote', 'entity_id' => $q->id, 'details' => array( 'invoice_id' => $id ) ) );
		return self::out( self::row( $id ), true );
	}
}
