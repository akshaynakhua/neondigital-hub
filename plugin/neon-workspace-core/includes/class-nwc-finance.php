<?php
/**
 * Business Accounts & Finance: payment accounts, income and expense entries, summaries, invoices
 * raised from bookings, WhatsApp links, and optional online payment of business invoices through the
 * business's own Razorpay account.
 *
 * A lightweight record of the business's money, not a statutory accounting system. These are the
 * business's records: they are separate from Workspace subscription invoices and from NeonDigital's
 * agency invoices in WorkDesk.
 */

defined( 'ABSPATH' ) || exit;

class NWC_Finance {

	const KINDS   = array( 'cash', 'bank', 'upi', 'other' );
	const METHODS = array( 'cash', 'bank', 'upi', 'card', 'cheque', 'other' );

	public static function routes() {
		NWC_REST::route( '/finance/accounts', 'GET', array( __CLASS__, 'accounts' ) );
		NWC_REST::route( '/finance/accounts', 'POST', array( __CLASS__, 'account_save' ) );
		NWC_REST::route( '/finance/accounts/(?P<id>\d+)', 'PUT,PATCH', array( __CLASS__, 'account_save' ) );
		NWC_REST::route( '/finance/accounts/(?P<id>\d+)', 'DELETE', array( __CLASS__, 'account_remove' ) );
		NWC_REST::route( '/finance/entries', 'GET', array( __CLASS__, 'entries' ) );
		NWC_REST::route( '/finance/entries', 'POST', array( __CLASS__, 'entry_save' ) );
		NWC_REST::route( '/finance/entries/(?P<id>\d+)', 'PUT,PATCH', array( __CLASS__, 'entry_save' ) );
		NWC_REST::route( '/finance/entries/(?P<id>\d+)', 'DELETE', array( __CLASS__, 'entry_remove' ) );
		NWC_REST::route( '/finance/summary', 'GET', array( __CLASS__, 'summary' ) );
		NWC_REST::route( '/finance/gateway', 'GET', array( __CLASS__, 'gateway_get' ) );
		NWC_REST::route( '/finance/gateway', 'PUT', array( __CLASS__, 'gateway_save' ) );
		NWC_REST::route( '/finance/gateway/test', 'POST', array( __CLASS__, 'gateway_test' ) );
		NWC_REST::route( '/invoices/(?P<id>\d+)/payment-account', 'PUT', array( __CLASS__, 'invoice_account' ) );
		NWC_REST::route( '/invoices/(?P<id>\d+)/whatsapp', 'GET', array( __CLASS__, 'invoice_whatsapp' ) );
		NWC_REST::route( '/bookings/(?P<id>\d+)/whatsapp', 'GET', array( __CLASS__, 'booking_whatsapp' ) );
		NWC_REST::route( '/bookings/(?P<id>\d+)/invoice', 'POST', array( __CLASS__, 'booking_invoice' ) );
		NWC_REST::route( '/bookings/(?P<id>\d+)/payment-status', 'PUT', array( __CLASS__, 'booking_payment' ) );
		NWC_REST::route( '/public/pay/(?P<id>\d+)/(?P<token>[a-f0-9]{64})/order', 'POST', array( __CLASS__, 'public_order' ), 'public' );
		NWC_REST::route( '/public/pay/(?P<id>\d+)/(?P<token>[a-f0-9]{64})/verify', 'POST', array( __CLASS__, 'public_verify' ), 'public' );
		NWC_REST::route( '/webhooks/razorpay/biz/(?P<uid>[a-z0-9_]+)', 'POST', array( __CLASS__, 'biz_webhook' ), 'public' );
	}

	private static function guard( $perm ) {
		if ( ! NWC_Tenancy::can( $perm ) ) {
			return NWC::err( 'Your role does not allow this.', 403, 'nwc_forbidden' );
		}
		return NWC_Features::guard( 'accounts_finance' );
	}

	/* ---------------------------- Payment accounts ---------------------------- */

	private static function account_out( $a, $full ) {
		$o = array( 'id' => (int) $a->id, 'kind' => $a->kind, 'label' => $a->label, 'holder' => $a->holder, 'bank_name' => $a->bank_name, 'account_last4' => $a->account_last4, 'ifsc' => $a->ifsc, 'branch' => $a->branch, 'upi_id' => $a->upi_id, 'qr_url' => $a->qr_url, 'instructions' => (string) $a->instructions, 'is_default' => (bool) $a->is_default, 'is_active' => (bool) $a->is_active );
		if ( $full ) {
			$o['account_no'] = $a->account_no ? (string) NWC::unseal( $a->account_no ) : '';
		}
		return $o;
	}

	public static function accounts() {
		global $wpdb;
		if ( ! NWC_Tenancy::can( 'invoices.read' ) && ! NWC_Tenancy::can( 'finance.read' ) ) {
			return NWC::err( 'Your role does not allow this.', 403, 'nwc_forbidden' );
		}
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . NWC_DB::t( 'payment_accounts' ) . ' WHERE workspace_id = %d AND deleted_at IS NULL ORDER BY is_default DESC, id ASC', NWC_Tenancy::$current->id ) ); // phpcs:ignore
		$full = NWC_Tenancy::can( 'settings.manage' );
		return array( 'items' => array_map( function ( $a ) use ( $full ) {
			return self::account_out( $a, $full );
		}, $rows ) );
	}

	public static function account_save( WP_REST_Request $r ) {
		global $wpdb;
		if ( ! NWC_Tenancy::can( 'settings.manage' ) ) {
			return NWC::err( 'Your role does not allow this.', 403, 'nwc_forbidden' );
		}
		$t  = NWC_DB::t( 'payment_accounts' );
		$ws = (int) NWC_Tenancy::$current->id;
		$id = (int) $r['id'];
		$old = $id ? $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $t WHERE id = %d AND workspace_id = %d AND deleted_at IS NULL", $id, $ws ) ) : null; // phpcs:ignore
		if ( $id && ! $old ) {
			return NWC::err( 'Account not found.', 404, 'nwc_not_found' );
		}
		$b    = NWC_REST::body( $r );
		$kind = in_array( $b['kind'] ?? '', self::KINDS, true ) ? $b['kind'] : ( $old ? $old->kind : 'bank' );
		$row  = array( 'kind' => $kind, 'updated_at' => NWC::now() );
		foreach ( array( 'label' => 120, 'holder' => 190, 'bank_name' => 190, 'ifsc' => 20, 'branch' => 190, 'upi_id' => 120 ) as $k => $len ) {
			if ( isset( $b[ $k ] ) ) {
				$row[ $k ] = mb_substr( trim( sanitize_text_field( (string) $b[ $k ] ) ), 0, $len );
			}
		}
		if ( isset( $b['ifsc'] ) ) {
			$row['ifsc'] = strtoupper( $row['ifsc'] );
			if ( '' !== $row['ifsc'] && ! preg_match( '/^[A-Z]{4}0[A-Z0-9]{6}$/', $row['ifsc'] ) ) {
				return NWC::err( 'That IFSC does not look right.' );
			}
		}
		if ( isset( $b['upi_id'] ) && '' !== $row['upi_id'] && ! preg_match( '/^[A-Za-z0-9._-]+@[A-Za-z0-9.-]+$/', $row['upi_id'] ) ) {
			return NWC::err( 'That UPI ID does not look right.' );
		}
		if ( isset( $b['instructions'] ) ) {
			$row['instructions'] = sanitize_textarea_field( (string) $b['instructions'] );
		}
		if ( isset( $b['qr_url'] ) ) {
			$row['qr_url'] = esc_url_raw( (string) $b['qr_url'], array( 'http', 'https' ) );
		}
		if ( isset( $b['account_no'] ) && '' !== trim( (string) $b['account_no'] ) ) {
			$no = preg_replace( '/[^A-Za-z0-9]/', '', (string) $b['account_no'] );
			$row['account_no']    = NWC::seal( $no );
			$row['account_last4'] = substr( $no, -4 );
		}
		if ( isset( $b['is_active'] ) ) {
			$row['is_active'] = empty( $b['is_active'] ) ? 0 : 1;
		}
		if ( ! empty( $b['is_default'] ) ) {
			$wpdb->update( $t, array( 'is_default' => 0 ), array( 'workspace_id' => $ws ) );
			$row['is_default'] = 1;
		}
		if ( $old ) {
			$wpdb->update( $t, $row, array( 'id' => $id ) );
		} else {
			$wpdb->insert( $t, array_merge( $row, array( 'workspace_id' => $ws, 'created_at' => NWC::now() ) ) );
			$id = (int) $wpdb->insert_id;
		}
		NWC::audit( $old ? 'payment_account_updated' : 'payment_account_added', array( 'kind' => 'admin', 'entity' => 'payment_account', 'entity_id' => $id, 'details' => array( 'label' => $row['label'] ?? $old->label ) ) );
		return self::account_out( $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $t WHERE id = %d", $id ) ), true ); // phpcs:ignore
	}

	public static function account_remove( WP_REST_Request $r ) {
		global $wpdb;
		if ( ! NWC_Tenancy::can( 'settings.manage' ) ) {
			return NWC::err( 'Your role does not allow this.', 403, 'nwc_forbidden' );
		}
		$n = $wpdb->update( NWC_DB::t( 'payment_accounts' ), array( 'deleted_at' => NWC::now(), 'is_default' => 0 ), array( 'id' => (int) $r['id'], 'workspace_id' => NWC_Tenancy::$current->id ) );
		if ( $n ) {
			NWC::audit( 'payment_account_removed', array( 'kind' => 'admin', 'entity' => 'payment_account', 'entity_id' => (int) $r['id'] ) );
		}
		return array( 'ok' => true );
	}

	/** The payment text printed on the invoice. Issued invoices keep their copy, like the business details. */
	public static function account_text( $a ) {
		$l = array();
		if ( 'bank' === $a->kind ) {
			$no = $a->account_no ? (string) NWC::unseal( $a->account_no ) : '';
			foreach ( array( 'Account holder' => $a->holder, 'Bank' => $a->bank_name, 'Account number' => $no, 'IFSC' => $a->ifsc, 'Branch' => $a->branch ) as $k => $v ) {
				if ( '' !== (string) $v ) {
					$l[] = $k . ': ' . $v;
				}
			}
		} elseif ( 'upi' === $a->kind && $a->upi_id ) {
			$l[] = 'UPI: ' . $a->upi_id;
		} elseif ( 'cash' === $a->kind ) {
			$l[] = 'Cash';
		} elseif ( $a->label ) {
			$l[] = $a->label;
		}
		if ( $a->instructions ) {
			$l[] = $a->instructions;
		}
		return implode( "\n", $l );
	}

	public static function invoice_account( WP_REST_Request $r ) {
		global $wpdb;
		if ( ! NWC_Tenancy::can( 'invoices.write' ) ) {
			return NWC::err( 'Your role does not allow this.', 403, 'nwc_forbidden' );
		}
		$v = NWC_Invoices::row( (int) $r['id'] );
		if ( ! $v ) {
			return NWC::err( 'Invoice not found.', 404, 'nwc_not_found' );
		}
		if ( 'cancelled' === $v->status ) {
			return NWC::err( 'This invoice is cancelled.', 409, 'nwc_conflict' );
		}
		$b   = NWC_REST::body( $r );
		$aid = absint( $b['account_id'] ?? 0 );
		$row = array( 'updated_at' => NWC::now() );
		if ( $aid ) {
			$a = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . NWC_DB::t( 'payment_accounts' ) . ' WHERE id = %d AND workspace_id = %d AND deleted_at IS NULL', $aid, $v->workspace_id ) ); // phpcs:ignore
			if ( ! $a ) {
				return NWC::err( 'Account not found.', 404, 'nwc_not_found' );
			}
			$row['payment_account_id'] = $aid;
			$row['bank_details']       = self::account_text( $a );
		} else {
			$row['payment_account_id'] = null;
		}
		if ( isset( $b['online_pay'] ) ) {
			$row['online_pay'] = empty( $b['online_pay'] ) ? 0 : 1;
		}
		$wpdb->update( NWC_DB::t( 'biz_invoices' ), $row, array( 'id' => $v->id ) );
		return array( 'ok' => true );
	}

	/** QR for a shared or printed invoice. Null when the chosen account has none. */
	public static function invoice_qr( $v ) {
		global $wpdb;
		if ( empty( $v->payment_account_id ) ) {
			return '';
		}
		return (string) $wpdb->get_var( $wpdb->prepare( 'SELECT qr_url FROM ' . NWC_DB::t( 'payment_accounts' ) . ' WHERE id = %d AND workspace_id = %d', $v->payment_account_id, $v->workspace_id ) ); // phpcs:ignore
	}

	/* ---------------------------- Income and expenses ---------------------------- */

	public static function entries( WP_REST_Request $r ) {
		global $wpdb;
		$g = self::guard( 'finance.read' );
		if ( is_wp_error( $g ) ) {
			return $g;
		}
		$t    = NWC_DB::t( 'finance_entries' );
		$page = max( 1, absint( $r['page'] ) );
		$vals = array( NWC_Tenancy::$current->id );
		$sql  = 'workspace_id = %d AND deleted_at IS NULL';
		if ( in_array( $r['type'], array( 'income', 'expense' ), true ) ) {
			$sql   .= ' AND entry_type = %s';
			$vals[] = $r['type'];
		}
		if ( NWC::valid_date( (string) $r['from'] ) ) {
			$sql   .= ' AND on_date >= %s';
			$vals[] = $r['from'];
		}
		if ( NWC::valid_date( (string) $r['to'] ) ) {
			$sql   .= ' AND on_date <= %s';
			$vals[] = $r['to'];
		}
		$tot  = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $t WHERE $sql", $vals ) ); // phpcs:ignore
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $t WHERE $sql ORDER BY on_date DESC, id DESC LIMIT 20 OFFSET %d", array_merge( $vals, array( ( $page - 1 ) * 20 ) ) ) ); // phpcs:ignore
		return array( 'items' => $rows, 'page' => $page, 'pages' => (int) ceil( $tot / 20 ), 'total' => $tot );
	}

	public static function entry_save( WP_REST_Request $r ) {
		global $wpdb;
		$g = self::guard( 'finance.write' );
		if ( is_wp_error( $g ) ) {
			return $g;
		}
		$t   = NWC_DB::t( 'finance_entries' );
		$ws  = (int) NWC_Tenancy::$current->id;
		$id  = (int) $r['id'];
		$old = $id ? $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $t WHERE id = %d AND workspace_id = %d AND deleted_at IS NULL", $id, $ws ) ) : null; // phpcs:ignore
		if ( $id && ! $old ) {
			return NWC::err( 'Entry not found.', 404, 'nwc_not_found' );
		}
		$b      = NWC_REST::body( $r );
		$amount = round( (float) ( $b['amount'] ?? ( $old ? $old->amount : 0 ) ), 2 );
		$date   = (string) ( $b['on_date'] ?? ( $old ? $old->on_date : '' ) );
		if ( $amount <= 0 ) {
			return NWC::err( 'Enter an amount above zero.' );
		}
		if ( ! NWC::valid_date( $date ) ) {
			return NWC::err( 'Choose a valid date.' );
		}
		$cust = absint( $b['customer_id'] ?? ( $old ? $old->customer_id : 0 ) );
		if ( $cust && ! $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . NWC_DB::t( 'customers' ) . ' WHERE id = %d AND workspace_id = %d', $cust, $ws ) ) ) { // phpcs:ignore
			return NWC::err( 'Customer not found.', 404, 'nwc_not_found' );
		}
		$acct = absint( $b['account_id'] ?? ( $old ? $old->account_id : 0 ) );
		if ( $acct && ! $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . NWC_DB::t( 'payment_accounts' ) . ' WHERE id = %d AND workspace_id = %d', $acct, $ws ) ) ) { // phpcs:ignore
			return NWC::err( 'Account not found.', 404, 'nwc_not_found' );
		}
		$row = array(
			'entry_type' => in_array( $b['entry_type'] ?? '', array( 'income', 'expense' ), true ) ? $b['entry_type'] : ( $old ? $old->entry_type : 'expense' ),
			'category'   => mb_substr( sanitize_text_field( (string) ( $b['category'] ?? ( $old ? $old->category : '' ) ) ), 0, 60 ),
			'amount'     => $amount,
			'on_date'    => $date,
			'method'     => in_array( $b['method'] ?? '', self::METHODS, true ) ? $b['method'] : ( $old ? $old->method : 'cash' ),
			'account_id' => $acct ? $acct : null,
			'customer_id' => $cust ? $cust : null,
			'reference'  => mb_substr( sanitize_text_field( (string) ( $b['reference'] ?? ( $old ? $old->reference : '' ) ) ), 0, 120 ),
			'notes'      => sanitize_textarea_field( (string) ( $b['notes'] ?? ( $old ? $old->notes : '' ) ) ),
			'updated_at' => NWC::now(),
		);
		if ( $old ) {
			$wpdb->update( $t, $row, array( 'id' => $id ) );
		} else {
			$wpdb->insert( $t, array_merge( $row, array( 'workspace_id' => $ws, 'created_by' => get_current_user_id(), 'created_at' => NWC::now() ) ) );
			$id = (int) $wpdb->insert_id;
		}
		NWC::audit( $old ? 'finance_entry_updated' : 'finance_entry_added', array( 'kind' => 'billing', 'entity' => 'finance_entry', 'entity_id' => $id, 'details' => array( 'type' => $row['entry_type'], 'amount' => $amount, 'was' => $old ? (float) $old->amount : null ) ) );
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $t WHERE id = %d", $id ) ); // phpcs:ignore
	}

	/** Kept with a deleted mark and an audit record. */
	public static function entry_remove( WP_REST_Request $r ) {
		global $wpdb;
		$g = self::guard( 'finance.write' );
		if ( is_wp_error( $g ) ) {
			return $g;
		}
		$n = $wpdb->update( NWC_DB::t( 'finance_entries' ), array( 'deleted_at' => NWC::now() ), array( 'id' => (int) $r['id'], 'workspace_id' => NWC_Tenancy::$current->id ) );
		if ( $n ) {
			NWC::audit( 'finance_entry_removed', array( 'kind' => 'billing', 'entity' => 'finance_entry', 'entity_id' => (int) $r['id'] ) );
		}
		return array( 'ok' => true );
	}

	/** Test-mode gateway receipts are excluded everywhere money is added up. */
	const REAL_PAYMENT = "voided_at IS NULL AND method <> 'razorpay_test'";

	public static function summary( WP_REST_Request $r ) {
		global $wpdb;
		$g = self::guard( 'finance.read' );
		if ( is_wp_error( $g ) ) {
			return $g;
		}
		$w    = NWC_Tenancy::$current;
		$ws   = (int) $w->id;
		$to   = NWC::valid_date( (string) $r['to'] ) ? (string) $r['to'] : NWC::today( $w->timezone );
		$from = NWC::valid_date( (string) $r['from'] ) ? (string) $r['from'] : substr( $to, 0, 7 ) . '-01';
		$pay  = NWC_DB::t( 'biz_payments' );
		$inv  = NWC_DB::t( 'biz_invoices' );
		$ent  = NWC_DB::t( 'finance_entries' );
		$cus  = NWC_DB::t( 'customers' );
		$by_method = $wpdb->get_results( $wpdb->prepare( "SELECT method, SUM(amount) total FROM $pay WHERE workspace_id = %d AND " . self::REAL_PAYMENT . ' AND paid_on BETWEEN %s AND %s GROUP BY method', $ws, $from, $to ) ); // phpcs:ignore
		$paid  = 0.0;
		foreach ( $by_method as $x ) {
			$paid += (float) $x->total;
		}
		$manual_income  = (float) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(SUM(amount),0) FROM $ent WHERE workspace_id = %d AND deleted_at IS NULL AND entry_type = 'income' AND on_date BETWEEN %s AND %s", $ws, $from, $to ) ); // phpcs:ignore
		$expenses       = (float) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(SUM(amount),0) FROM $ent WHERE workspace_id = %d AND deleted_at IS NULL AND entry_type = 'expense' AND on_date BETWEEN %s AND %s", $ws, $from, $to ) ); // phpcs:ignore
		$booking_rev    = (float) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(SUM(p.amount),0) FROM $pay p JOIN $inv i ON i.id = p.invoice_id WHERE p.workspace_id = %d AND p.voided_at IS NULL AND p.method <> 'razorpay_test' AND i.booking_id IS NOT NULL AND p.paid_on BETWEEN %s AND %s", $ws, $from, $to ) ); // phpcs:ignore
		$cats           = $wpdb->get_results( $wpdb->prepare( "SELECT category, SUM(amount) total FROM $ent WHERE workspace_id = %d AND deleted_at IS NULL AND entry_type = 'expense' AND on_date BETWEEN %s AND %s GROUP BY category ORDER BY total DESC", $ws, $from, $to ) ); // phpcs:ignore
		$outstanding    = (float) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(SUM(grand_total - amount_paid),0) FROM $inv WHERE workspace_id = %d AND status IN ('sent','partially_paid')", $ws ) ); // phpcs:ignore
		$by_customer    = $wpdb->get_results( $wpdb->prepare( "SELECT i.customer_id, c.name, SUM(i.grand_total - i.amount_paid) outstanding, COUNT(*) invoices FROM $inv i LEFT JOIN $cus c ON c.id = i.customer_id WHERE i.workspace_id = %d AND i.status IN ('sent','partially_paid') GROUP BY i.customer_id, c.name ORDER BY outstanding DESC LIMIT 50", $ws ) ); // phpcs:ignore
		// Twelve months for the table, worked out here so it behaves the same on any database.
		$months = array();
		$first  = strtotime( gmdate( 'Y-m-01', strtotime( $to ) ) . ' -11 months' );
		for ( $i = 0; $i < 12; $i++ ) {
			$m = gmdate( 'Y-m', strtotime( '+' . $i . ' months', $first ) );
			$months[ $m ] = array( 'month' => $m, 'income' => 0.0, 'expense' => 0.0 );
		}
		$since = gmdate( 'Y-m-d', $first );
		foreach ( $wpdb->get_results( $wpdb->prepare( "SELECT paid_on, amount FROM $pay WHERE workspace_id = %d AND " . self::REAL_PAYMENT . ' AND paid_on BETWEEN %s AND %s', $ws, $since, $to ) ) as $x ) { // phpcs:ignore
			$months[ substr( $x->paid_on, 0, 7 ) ]['income'] += (float) $x->amount;
		}
		foreach ( $wpdb->get_results( $wpdb->prepare( "SELECT on_date, amount, entry_type FROM $ent WHERE workspace_id = %d AND deleted_at IS NULL AND on_date BETWEEN %s AND %s", $ws, $since, $to ) ) as $x ) { // phpcs:ignore
			$months[ substr( $x->on_date, 0, 7 ) ][ 'income' === $x->entry_type ? 'income' : 'expense' ] += (float) $x->amount;
		}
		return array(
			'from' => $from, 'to' => $to,
			'income_total' => round( $paid + $manual_income, 2 ), 'customer_payments' => round( $paid, 2 ), 'other_income' => $manual_income, 'expenses' => $expenses, 'net' => round( $paid + $manual_income - $expenses, 2 ),
			'booking_revenue' => $booking_rev, 'by_method' => $by_method, 'expense_categories' => $cats, 'outstanding' => round( $outstanding, 2 ), 'by_customer' => $by_customer, 'monthly' => array_values( $months ),
		);
	}

	/* ---------------------------- Invoices from bookings ---------------------------- */

	/**
	 * Raises one invoice for one booking. The unique key on (workspace, booking) makes a second one
	 * impossible, even if two triggers fire together.
	 *
	 * @return object|WP_Error Invoice row.
	 */
	public static function invoice_from_booking( $b, $trigger, $user_id = 0 ) {
		global $wpdb;
		$t   = NWC_DB::t( 'biz_invoices' );
		$dup = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $t WHERE workspace_id = %d AND booking_id = %d", $b->workspace_id, $b->id ) ); // phpcs:ignore
		if ( $dup ) {
			return $dup;
		}
		$svc = $wpdb->get_row( $wpdb->prepare( 'SELECT name, price FROM ' . NWC_DB::t( 'services' ) . ' WHERE id = %d AND workspace_id = %d', $b->service_id, $b->workspace_id ) ); // phpcs:ignore
		if ( ! $svc ) {
			return NWC::err( 'The service for this booking no longer exists.', 409, 'nwc_conflict' );
		}
		$cust = (int) $b->customer_id;
		if ( ! $cust ) {
			if ( '' === trim( (string) $b->customer_name ) ) {
				return NWC::err( 'This booking has no customer details.', 409, 'nwc_conflict' );
			}
			$cust = (int) NWC_CRM::upsert_customer( (int) $b->workspace_id, array( 'name' => $b->customer_name, 'phone' => $b->phone, 'email' => $b->email, 'source' => 'booking' ) );
			$wpdb->update( NWC_DB::t( 'bookings' ), array( 'customer_id' => $cust ), array( 'id' => $b->id ) );
		}
		$prev = NWC_Tenancy::$current;
		NWC_Tenancy::$current = NWC_Tenancy::workspace( $b->workspace_id );
		$w    = NWC_Tenancy::$current;
		$when = ( new DateTimeImmutable( $b->starts_at . ' UTC' ) )->setTimezone( new DateTimeZone( $w->timezone ) )->format( 'j M Y, g:i A' );
		$default = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . NWC_DB::t( 'payment_accounts' ) . ' WHERE workspace_id = %d AND deleted_at IS NULL AND is_active = 1 ORDER BY is_default DESC, id ASC LIMIT 1', $b->workspace_id ) ); // phpcs:ignore
		try {
			$id = NWC_Invoices::create_doc( array( 'customer_id' => $cust, 'items' => array( array( 'description' => $svc->name . ' (' . $when . ')', 'qty' => max( 1, (int) $b->party_size ), 'unit_price' => (float) $svc->price ) ) ) );
		} finally {
			NWC_Tenancy::$current = $prev;
		}
		if ( is_wp_error( $id ) ) {
			return $id;
		}
		$row = array( 'booking_id' => (int) $b->id, 'status' => 'sent', 'sent_at' => NWC::now(), 'updated_at' => NWC::now() );
		if ( $default ) {
			$row['payment_account_id'] = (int) $default->id;
			$row['bank_details']       = self::account_text( $default );
		}
		$wpdb->suppress_errors( true );
		$ok = $wpdb->update( $t, $row, array( 'id' => $id ) );
		$wpdb->suppress_errors( false );
		if ( false === $ok ) { // Lost a race with another trigger. Keep the winner, drop this draft.
			$wpdb->delete( NWC_DB::t( 'biz_items' ), array( 'doc_type' => 'invoice', 'doc_id' => $id ) );
			$wpdb->delete( $t, array( 'id' => $id, 'status' => 'draft' ) );
			return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $t WHERE workspace_id = %d AND booking_id = %d", $b->workspace_id, $b->id ) ); // phpcs:ignore
		}
		$v = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $t WHERE id = %d", $id ) ); // phpcs:ignore
		NWC::audit( 'booking_invoice_issued', array( 'workspace_id' => $b->workspace_id, 'user_id' => $user_id, 'entity' => 'booking', 'entity_id' => $b->id, 'details' => array( 'invoice' => $v->doc_no, 'trigger' => $trigger ) ) );
		NWC_Notify::workspace_owners( (int) $b->workspace_id, 'invoice', 'Invoice ' . $v->doc_no . ' issued', 'For ' . $b->customer_name . '. Share it from the invoice page.', '#/invoices/' . $v->id, 'bookinv:' . $b->id );
		return $v;
	}

	/** Called by the booking code on each change. Does nothing unless the business chose this trigger. */
	public static function maybe_auto_invoice( $b, $trigger ) {
		$biz = NWC_Bookings::business( $b->workspace_id );
		if ( ! $biz || $biz->auto_invoice !== $trigger ) {
			return;
		}
		$w = NWC_Tenancy::workspace( $b->workspace_id );
		if ( NWC_Features::allowed( 'invoice_management', $w ) ) {
			self::invoice_from_booking( $b, $trigger );
		}
	}

	private static function booking_row( $id ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . NWC_DB::t( 'bookings' ) . ' WHERE id = %d AND workspace_id = %d AND deleted_at IS NULL', $id, NWC_Tenancy::$current->id ) ); // phpcs:ignore
	}

	public static function booking_invoice( WP_REST_Request $r ) {
		if ( ! NWC_Tenancy::can( 'invoices.write' ) ) {
			return NWC::err( 'Your role does not allow this.', 403, 'nwc_forbidden' );
		}
		$g = NWC_Features::guard( 'invoice_management' );
		if ( is_wp_error( $g ) ) {
			return $g;
		}
		$b = self::booking_row( (int) $r['id'] );
		if ( ! $b ) {
			return NWC::err( 'Booking not found.', 404, 'nwc_not_found' );
		}
		$v = self::invoice_from_booking( $b, 'manual', get_current_user_id() );
		return is_wp_error( $v ) ? $v : array( 'id' => (int) $v->id, 'doc_no' => $v->doc_no );
	}

	public static function booking_payment( WP_REST_Request $r ) {
		global $wpdb;
		if ( ! NWC_Tenancy::can( 'bookings.write' ) ) {
			return NWC::err( 'Your role does not allow this.', 403, 'nwc_forbidden' );
		}
		$b = self::booking_row( (int) $r['id'] );
		if ( ! $b ) {
			return NWC::err( 'Booking not found.', 404, 'nwc_not_found' );
		}
		$s = (string) ( NWC_REST::body( $r )['payment_status'] ?? '' );
		if ( ! in_array( $s, array( 'unpaid', 'partial', 'paid', 'refunded', 'failed' ), true ) ) {
			return NWC::err( 'Choose a valid payment status.' );
		}
		if ( in_array( $s, array( 'paid', 'refunded', 'failed' ), true ) ) { // Seat rules apply: one shared path.
			$res = NWC_Bookings::payment_event( (int) $b->id, (int) $b->workspace_id, $s, 'staff:' . get_current_user_id() );
			return is_wp_error( $res ) ? $res : array_merge( array( 'ok' => true ), $res );
		}
		if ( $s !== $b->payment_status ) {
			$wpdb->update( NWC_DB::t( 'bookings' ), array( 'payment_status' => $s, 'updated_at' => NWC::now() ), array( 'id' => $b->id ) );
			NWC::audit( 'booking_payment_status_changed', array( 'entity' => 'booking', 'entity_id' => $b->id, 'details' => array( 'from' => $b->payment_status, 'to' => $s ) ) );
			if ( 'paid' === $s ) {
				self::maybe_auto_invoice( $b, 'paid' );
			}
		}
		return array( 'ok' => true );
	}

	/* ---------------------------- WhatsApp: a link the user opens themselves ---------------------------- */

	/** Digits only. A bare 10-digit number is taken as Indian. @return string Empty when unusable. */
	public static function wa_number( $phone ) {
		$d = preg_replace( '/\D/', '', (string) $phone );
		if ( 10 === strlen( $d ) ) {
			$d = '91' . $d;
		}
		return strlen( $d ) >= 11 && strlen( $d ) <= 15 ? $d : '';
	}

	public static function wa_link( $phone, $text ) {
		$n = self::wa_number( $phone );
		return 'https://wa.me/' . $n . '?text=' . rawurlencode( $text );
	}

	public static function invoice_whatsapp( WP_REST_Request $r ) {
		if ( ! NWC_Tenancy::can( 'invoices.read' ) ) {
			return NWC::err( 'Your role does not allow this.', 403, 'nwc_forbidden' );
		}
		$v = NWC_Invoices::row( (int) $r['id'] );
		if ( ! $v ) {
			return NWC::err( 'Invoice not found.', 404, 'nwc_not_found' );
		}
		if ( 'draft' === $v->status ) {
			return NWC::err( 'Send the invoice before sharing it.', 409, 'nwc_conflict' );
		}
		$c   = json_decode( (string) $v->customer_snapshot, true ) ?: array();
		$biz = json_decode( (string) $v->business_snapshot, true ) ?: array();
		$msg = 'Hello ' . ( $c['name'] ?? '' ) . ', here is invoice ' . $v->doc_no . ' from ' . ( $biz['name'] ?? '' ) . ' for ' . NWC_Invoices::money( $v->grand_total ) . ': ' . NWC_Invoices::share_url( 'invoice', $v );
		$num = self::wa_number( $c['phone'] ?? '' );
		return array( 'url' => 'https://wa.me/' . $num . '?text=' . rawurlencode( $msg ), 'has_number' => '' !== $num );
	}

	public static function booking_whatsapp( WP_REST_Request $r ) {
		if ( ! NWC_Tenancy::can( 'bookings.read' ) ) {
			return NWC::err( 'Your role does not allow this.', 403, 'nwc_forbidden' );
		}
		$b = self::booking_row( (int) $r['id'] );
		if ( ! $b ) {
			return NWC::err( 'Booking not found.', 404, 'nwc_not_found' );
		}
		$w    = NWC_Tenancy::$current;
		$biz  = NWC_Bookings::business( $w->id );
		$when = ( new DateTimeImmutable( $b->starts_at . ' UTC' ) )->setTimezone( new DateTimeZone( $w->timezone ) )->format( 'j M Y, g:i A' );
		$msg  = 'Hello ' . $b->customer_name . ', your booking with ' . ( $biz ? $biz->display_name : $w->name ) . ' is on ' . $when . '.';
		$v    = $GLOBALS['wpdb']->get_row( $GLOBALS['wpdb']->prepare( 'SELECT * FROM ' . NWC_DB::t( 'biz_invoices' ) . " WHERE workspace_id = %d AND booking_id = %d AND status <> 'draft'", $b->workspace_id, $b->id ) ); // phpcs:ignore
		if ( $v ) {
			$msg .= ' Your invoice ' . $v->doc_no . ': ' . NWC_Invoices::share_url( 'invoice', $v );
		}
		$num = self::wa_number( $b->phone );
		return array( 'url' => 'https://wa.me/' . $num . '?text=' . rawurlencode( $msg ), 'has_number' => '' !== $num );
	}

	/* ---------------------------- The business's own Razorpay account ---------------------------- */

	public static function gateway_row( $ws ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . NWC_DB::t( 'biz_gateways' ) . ' WHERE workspace_id = %d', $ws ) ); // phpcs:ignore
	}

	/** @return NWC_Gateway_Razorpay|null */
	public static function biz_gateway( $ws ) {
		$g = self::gateway_row( $ws );
		if ( ! $g ) {
			return null;
		}
		$hook = $g->webhook_secret ? NWC::unseal( $g->webhook_secret ) : '';
		if ( 'test' === $g->mode && $g->test_key && $g->test_secret ) {
			return new NWC_Gateway_Razorpay( 'test', $g->test_key, NWC::unseal( $g->test_secret ), $hook );
		}
		if ( 'live' === $g->mode && (int) $g->live_confirmed && $g->live_key && $g->live_secret ) {
			return new NWC_Gateway_Razorpay( 'live', $g->live_key, NWC::unseal( $g->live_secret ), $hook );
		}
		return null;
	}

	public static function gateway_get() {
		if ( ! NWC_Tenancy::can( 'settings.manage' ) ) {
			return NWC::err( 'Your role does not allow this.', 403, 'nwc_forbidden' );
		}
		$g = self::gateway_row( NWC_Tenancy::$current->id );
		return array(
			'mode' => $g ? $g->mode : 'off', 'test_key' => $g ? $g->test_key : '', 'live_key' => $g ? $g->live_key : '', 'has_test_secret' => $g && $g->test_secret, 'has_live_secret' => $g && $g->live_secret, 'has_webhook_secret' => $g && $g->webhook_secret, 'live_confirmed' => $g && (int) $g->live_confirmed,
			'webhook_url' => rest_url( NWC_REST::NS . '/webhooks/razorpay/biz/' . NWC_Tenancy::$current->uid ),
		);
	}

	public static function gateway_save( WP_REST_Request $r ) {
		global $wpdb;
		if ( ! NWC_Tenancy::can( 'settings.manage' ) ) {
			return NWC::err( 'Your role does not allow this.', 403, 'nwc_forbidden' );
		}
		$b  = NWC_REST::body( $r );
		$ws = (int) NWC_Tenancy::$current->id;
		$g  = self::gateway_row( $ws );
		$mode = in_array( $b['mode'] ?? '', array( 'off', 'test', 'live' ), true ) ? $b['mode'] : ( $g ? $g->mode : 'off' );
		$row  = array( 'mode' => $mode, 'live_confirmed' => 'live' === $mode && ! empty( $b['live_confirmed'] ) ? 1 : 0, 'updated_at' => NWC::now() );
		if ( 'live' === $mode && ! $row['live_confirmed'] ) {
			return NWC::err( 'Confirm that real customers can be charged before turning on live mode.' );
		}
		foreach ( array( 'test_key', 'live_key' ) as $k ) {
			if ( isset( $b[ $k ] ) ) {
				$row[ $k ] = mb_substr( sanitize_text_field( (string) $b[ $k ] ), 0, 120 );
			}
		}
		foreach ( array( 'test_secret', 'live_secret', 'webhook_secret' ) as $k ) {
			if ( ! empty( $b[ $k ] ) ) {
				$row[ $k ] = NWC::seal( trim( (string) $b[ $k ] ) );
			}
		}
		if ( $g ) {
			$wpdb->update( NWC_DB::t( 'biz_gateways' ), $row, array( 'workspace_id' => $ws ) );
		} else {
			$wpdb->insert( NWC_DB::t( 'biz_gateways' ), array_merge( $row, array( 'workspace_id' => $ws ) ) );
		}
		if ( ! $g || $g->mode !== $mode ) {
			NWC::audit( 'business_gateway_mode_changed', array( 'kind' => 'billing', 'details' => array( 'from' => $g ? $g->mode : 'off', 'to' => $mode ) ) );
		}
		return self::gateway_get();
	}

	public static function gateway_test() {
		if ( ! NWC_Tenancy::can( 'settings.manage' ) ) {
			return NWC::err( 'Your role does not allow this.', 403, 'nwc_forbidden' );
		}
		$gw = self::biz_gateway( NWC_Tenancy::$current->id );
		if ( ! $gw ) {
			return NWC::err( 'Choose test or live mode and enter the keys first.', 409, 'nwc_gateway_off' );
		}
		return $gw->test_connection();
	}

	/** Online payment is offered only if the business turned it on for this invoice and has a working gateway. */
	public static function online_pay_info( $v ) {
		if ( empty( $v->online_pay ) || ! in_array( NWC_Invoices::effective_status( $v ), array( 'sent', 'partially_paid', 'overdue' ), true ) ) {
			return null;
		}
		$gw = self::biz_gateway( $v->workspace_id );
		return $gw ? array( 'mode' => $gw->mode() ) : null;
	}

	private static function public_invoice( WP_REST_Request $r ) {
		$doc = NWC_Invoices::public_doc( 'i', (int) $r['id'], (string) $r['token'] );
		return $doc && 'invoice' === $doc['type'] ? $doc['doc'] : null;
	}

	public static function public_order( WP_REST_Request $r ) {
		global $wpdb;
		if ( ! NWC::rate_ok( 'payorder|' . NWC::ip(), 20, 300 ) ) {
			return NWC::err( 'Too many attempts. Please try again later.', 429, 'nwc_rate_limited' );
		}
		$v = self::public_invoice( $r );
		if ( ! $v || ! self::online_pay_info( $v ) ) {
			return NWC::err( 'Online payment is not available for this invoice.', 404, 'nwc_not_found' );
		}
		$gw  = self::biz_gateway( $v->workspace_id );
		$due = round( (float) $v->grand_total - (float) $v->amount_paid, 2 );
		if ( $due <= 0 ) {
			return NWC::err( 'This invoice is already paid.', 409, 'nwc_conflict' );
		}
		if ( $v->booking_id ) { // Keep a held seat while the customer is in the gateway's checkout.
			$bk = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . NWC_DB::t( 'bookings' ) . ' WHERE id = %d AND workspace_id = %d', $v->booking_id, $v->workspace_id ) ); // phpcs:ignore
			if ( $bk && 'pending' === $bk->status && ! NWC_Bookings::is_live( $bk ) ) {
				return NWC::err( 'The time held for this booking has run out. Please book again.', 409, 'nwc_hold_expired' );
			}
			NWC_Bookings::extend_hold_for_checkout( (int) $v->workspace_id, (int) $v->booking_id );
		}
		$order = $gw->create_order( $due, 'INR', $v->doc_no );
		if ( is_wp_error( $order ) ) {
			return $order;
		}
		$now = NWC::now();
		$wpdb->insert( NWC_DB::t( 'transactions' ), array( 'workspace_id' => (int) $v->workspace_id, 'category' => 'business_invoice', 'ref_id' => (int) $v->id, 'gateway' => 'razorpay', 'mode' => $gw->mode(), 'is_real' => $gw->is_real() ? 1 : 0, 'amount' => $due, 'currency' => 'INR', 'status' => 'created', 'gateway_order_id' => $order['order_id'], 'created_at' => $now, 'updated_at' => $now ) );
		$biz = json_decode( (string) $v->business_snapshot, true ) ?: array();
		return array( 'checkout' => array_merge( $order['checkout'], array( 'name' => $biz['name'] ?? '', 'description' => 'Invoice ' . $v->doc_no ) ), 'mode' => $gw->mode() );
	}

	public static function public_verify( WP_REST_Request $r ) {
		$v = self::public_invoice( $r );
		if ( ! $v ) {
			return NWC::err( 'Invoice not found.', 404, 'nwc_not_found' );
		}
		$gw = self::biz_gateway( $v->workspace_id );
		if ( ! $gw ) {
			return NWC::err( 'Online payment is not available.', 409, 'nwc_gateway_off' );
		}
		$body = NWC_REST::body( $r );
		if ( ! empty( $body['failed'] ) ) { // The checkout reported a failed or abandoned payment: release the hold.
			if ( $v->booking_id ) {
				NWC_Bookings::payment_event( (int) $v->booking_id, (int) $v->workspace_id, 'failed', 'checkout' );
			}
			return array( 'ok' => true, 'released' => (bool) $v->booking_id );
		}
		$res = $gw->verify_payment( $body );
		if ( is_wp_error( $res ) ) {
			NWC::audit( 'payment_verification_failed', array( 'workspace_id' => $v->workspace_id, 'user_id' => 0, 'kind' => 'billing', 'entity' => 'invoice', 'entity_id' => $v->id ) );
			return $res;
		}
		return NWC_Billing::settle_order( $gw, $res['order_id'], $res['payment_id'], (int) $v->id );
	}

	public static function biz_webhook( WP_REST_Request $r ) {
		global $wpdb;
		$w = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . NWC_DB::t( 'workspaces' ) . ' WHERE uid = %s', (string) $r['uid'] ) ); // phpcs:ignore
		$gw = $w ? self::biz_gateway( $w->id ) : null;
		return $gw ? NWC_Billing::process_webhook( $gw, $r, 'razorpay_biz_' . $w->id ) : NWC::err( 'Invalid signature.', 401, 'nwc_bad_signature' );
	}

	/** Called from NWC_Billing::settle_order for a verified business invoice payment. */
	public static function apply_settled( $tx, $payment_id ) {
		$v = NWC_Invoices::row( (int) $tx->ref_id, (int) $tx->workspace_id );
		if ( ! $v ) {
			return;
		}
		$res = NWC_Invoices::apply_payment( $v, array( 'amount' => $tx->amount, 'method' => (int) $tx->is_real ? 'razorpay' : 'razorpay_test', 'reference' => $payment_id . ( (int) $tx->is_real ? '' : ' (TEST)' ), 'idempotency_key' => 'rzp_' . $payment_id ), (int) $tx->id );
		if ( is_wp_error( $res ) ) { // Money arrived but the invoice cannot take it, such as a cancelled invoice.
			NWC::audit( 'payment_needs_reconciliation', array( 'workspace_id' => $tx->workspace_id, 'user_id' => 0, 'kind' => 'billing', 'entity' => 'transaction', 'entity_id' => $tx->id, 'details' => array( 'reason' => $res->get_error_code() ) ) );
			NWC_Notify::workspace_owners( (int) $tx->workspace_id, 'billing', 'A payment needs your attention', 'A payment for invoice ' . $v->doc_no . ' could not be applied. Check it in your Razorpay dashboard.', '#/invoices/' . $v->id, 'recon:' . $tx->id );
			return;
		}
		NWC_Notify::workspace_owners( (int) $tx->workspace_id, 'invoice', 'Payment received', 'Invoice ' . $v->doc_no . ( (int) $tx->is_real ? '' : ' (test mode, no real money)' ), '#/invoices/' . $v->id, 'bizpay:' . $tx->id );
	}
}
