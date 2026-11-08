<?php
/**
 * Workspace subscriptions and the payment gateway layer.
 *
 * Status of the gateways today:
 *   manual    - works. An administrator records money received offline.
 *   simulated - works, only when an administrator turns it on. Marked is_real = 0, never counted as
 *               revenue and always labelled as simulated.
 *   razorpay  - written against Razorpay's documented API but NOT exercised against Razorpay: no
 *               test keys exist yet. Signature checks are unit-tested with computed signatures.
 *
 * A browser's "success" never settles anything: settlement needs a verified signature on the server,
 * and every step is idempotent. Card details never reach this server.
 *
 * Agency clients: WorkDesk owns their billing, so nothing here applies to them.
 */

defined( 'ABSPATH' ) || exit;

interface NWC_Gateway {
	public function id();
	public function mode();
	public function is_real();
	/** @return array|WP_Error {order_id, checkout} */
	public function create_order( $amount, $currency, $receipt );
	/** Checkout return, verified on the server. @return array|WP_Error {order_id, payment_id} */
	public function verify_payment( $in );
	/** @return bool */
	public function verify_webhook( $body, $signature );
	/** @return array|WP_Error */
	public function refund( $payment_id, $amount );
}

class NWC_Gateway_Manual implements NWC_Gateway {
	public function id() {
		return 'manual';
	}
	public function mode() {
		return 'manual';
	}
	public function is_real() {
		return true;
	}
	public function create_order( $amount, $currency, $receipt ) {
		return NWC::err( 'Online payment is not switched on. Pay by bank transfer and NeonDigital will record it.', 409, 'nwc_gateway_off' );
	}
	public function verify_payment( $in ) {
		return NWC::err( 'Not applicable.', 409, 'nwc_gateway_off' );
	}
	public function verify_webhook( $body, $signature ) {
		return false;
	}
	public function refund( $payment_id, $amount ) {
		return array( 'manual' => true );
	}
}

/** Stands in for a gateway when trying flows. Its transactions are flagged as not real. */
class NWC_Gateway_Simulated implements NWC_Gateway {
	public function id() {
		return 'simulated';
	}
	public function mode() {
		return 'simulated';
	}
	public function is_real() {
		return false;
	}
	private function key() {
		return hash( 'sha256', 'simulated|' . wp_salt( 'nonce' ) );
	}
	public function create_order( $amount, $currency, $receipt ) {
		$order = 'sim_order_' . bin2hex( random_bytes( 8 ) );
		$pay   = 'sim_pay_' . bin2hex( random_bytes( 8 ) );
		return array( 'order_id' => $order, 'checkout' => array( 'simulated' => true, 'label' => 'SIMULATED PAYMENT: no money moves', 'payment_id' => $pay, 'signature' => hash_hmac( 'sha256', $order . '|' . $pay, $this->key() ) ) );
	}
	public function verify_payment( $in ) {
		$o = (string) ( $in['order_id'] ?? '' );
		$p = (string) ( $in['payment_id'] ?? '' );
		if ( '' === $o || '' === $p || ! hash_equals( hash_hmac( 'sha256', $o . '|' . $p, $this->key() ), (string) ( $in['signature'] ?? '' ) ) ) {
			return NWC::err( 'Payment could not be verified.', 400, 'nwc_bad_signature' );
		}
		return array( 'order_id' => $o, 'payment_id' => $p );
	}
	public function verify_webhook( $body, $signature ) {
		return false;
	}
	public function refund( $payment_id, $amount ) {
		return array( 'simulated' => true );
	}
}

/**
 * Razorpay adapter: Orders API, checkout signature HMAC_SHA256(order_id|payment_id, key_secret),
 * webhook signature HMAC_SHA256(body, webhook_secret). Needs real keys from the administrator.
 */
class NWC_Gateway_Razorpay implements NWC_Gateway {
	private $mode;
	private $key;
	private $secret;
	private $webhook_secret;

	public function __construct( $mode, $key, $secret, $webhook_secret ) {
		$this->mode           = $mode;
		$this->key            = $key;
		$this->secret         = $secret;
		$this->webhook_secret = $webhook_secret;
	}
	public function id() {
		return 'razorpay';
	}
	public function mode() {
		return $this->mode;
	}
	public function is_real() {
		return 'live' === $this->mode;
	}
	private function call( $method, $path, $body = null ) {
		$res = wp_remote_request( 'https://api.razorpay.com/v1' . $path, array(
			'method'  => $method,
			'timeout' => 20,
			'headers' => array( 'Authorization' => 'Basic ' . base64_encode( $this->key . ':' . $this->secret ), 'Content-Type' => 'application/json' ), // phpcs:ignore
			'body'    => null === $body ? null : wp_json_encode( $body ),
		) );
		if ( is_wp_error( $res ) ) {
			return NWC::err( 'Could not reach the payment gateway.', 502, 'nwc_gateway_error' );
		}
		$code = (int) wp_remote_retrieve_response_code( $res );
		$data = json_decode( (string) wp_remote_retrieve_body( $res ), true );
		if ( $code < 200 || $code >= 300 ) {
			return NWC::err( 'The payment gateway refused this request.', 502, 'nwc_gateway_error' );
		}
		return is_array( $data ) ? $data : array();
	}
	public function create_order( $amount, $currency, $receipt ) {
		$o = $this->call( 'POST', '/orders', array( 'amount' => (int) round( $amount * 100 ), 'currency' => $currency, 'receipt' => mb_substr( $receipt, 0, 40 ) ) );
		if ( is_wp_error( $o ) ) {
			return $o;
		}
		return array( 'order_id' => $o['id'], 'checkout' => array( 'key' => $this->key, 'order_id' => $o['id'], 'amount' => $o['amount'], 'currency' => $currency ) );
	}
	public function verify_payment( $in ) {
		$o = (string) ( $in['razorpay_order_id'] ?? '' );
		$p = (string) ( $in['razorpay_payment_id'] ?? '' );
		if ( '' === $this->secret || '' === $o || '' === $p || ! hash_equals( hash_hmac( 'sha256', $o . '|' . $p, $this->secret ), (string) ( $in['razorpay_signature'] ?? '' ) ) ) {
			return NWC::err( 'Payment could not be verified.', 400, 'nwc_bad_signature' );
		}
		return array( 'order_id' => $o, 'payment_id' => $p );
	}
	public function verify_webhook( $body, $signature ) {
		return '' !== (string) $this->webhook_secret && hash_equals( hash_hmac( 'sha256', (string) $body, $this->webhook_secret ), (string) $signature );
	}
	public function refund( $payment_id, $amount ) {
		return $this->call( 'POST', '/payments/' . rawurlencode( $payment_id ) . '/refund', array( 'amount' => (int) round( $amount * 100 ) ) );
	}
	/** Asks Razorpay whether the keys work. Moves no money. @return array {ok, message} */
	public function test_connection() {
		$res = $this->call( 'GET', '/orders?count=1' );
		return is_wp_error( $res ) ? array( 'ok' => false, 'message' => 'Razorpay refused these keys, or could not be reached.' ) : array( 'ok' => true, 'message' => 'Razorpay accepted these ' . $this->mode . ' keys.' );
	}
	/**
	 * Confirms with Razorpay what actually happened to a payment and captures an authorized one.
	 *
	 * @return array|WP_Error {status, amount} amount in paise.
	 */
	public function confirm_payment( $payment_id, $order_id ) {
		$p = $this->call( 'GET', '/payments/' . rawurlencode( $payment_id ) );
		if ( is_wp_error( $p ) ) {
			return $p;
		}
		if ( ( $p['order_id'] ?? '' ) !== $order_id ) {
			return NWC::err( 'This payment does not match the order.', 400, 'nwc_payment_mismatch' );
		}
		if ( 'authorized' === ( $p['status'] ?? '' ) ) {
			$p = $this->call( 'POST', '/payments/' . rawurlencode( $payment_id ) . '/capture', array( 'amount' => (int) $p['amount'], 'currency' => (string) ( $p['currency'] ?? 'INR' ) ) );
			if ( is_wp_error( $p ) ) {
				return $p;
			}
		}
		if ( 'captured' !== ( $p['status'] ?? '' ) ) {
			return NWC::err( 'This payment has not been completed.', 402, 'nwc_payment_incomplete' );
		}
		return array( 'status' => $p['status'], 'amount' => (int) $p['amount'] );
	}
}

class NWC_Billing {

	public static function routes() {
		NWC_REST::route( '/billing', 'GET', array( __CLASS__, 'overview' ) );
		NWC_REST::route( '/billing/change-plan', 'POST', array( __CLASS__, 'change_plan' ) );
		NWC_REST::route( '/billing/addons', 'POST', array( __CLASS__, 'buy_addon' ) );
		NWC_REST::route( '/billing/invoices/(?P<id>\d+)/checkout', 'POST', array( __CLASS__, 'checkout' ) );
		NWC_REST::route( '/billing/invoices/(?P<id>\d+)/verify', 'POST', array( __CLASS__, 'verify' ) );
		NWC_REST::route( '/webhooks/razorpay', 'POST', array( __CLASS__, 'razorpay_webhook' ), 'public' );
	}

	/** Prices are left empty on purpose: an administrator sets them before launch. */
	public static function seed() {
		global $wpdb;
		$t = NWC_DB::t( 'plans' );
		if ( (int) $wpdb->get_var( "SELECT COUNT(*) FROM $t" ) ) { // phpcs:ignore
			return;
		}
		$features = array();
		foreach ( array_keys( NWC_Features::CATALOG ) as $code ) {
			if ( ! in_array( $code, NWC_Features::AGENCY_ONLY, true ) && ! in_array( $code, NWC_Features::COMING_SOON, true ) ) {
				$features[ $code ] = array();
			}
		}
		$now = NWC::now();
		$sort = 0;
		foreach ( array( array( 'free_trial', 'Free Trial', 'Try everything in your plan before you pay.', 1 ), array( 'starter', 'Starter', 'For one person running their own bookings and customers.', 0 ), array( 'professional', 'Professional', 'For small teams with projects, invoices and finance.', 0 ), array( 'business', 'Business', 'For businesses with more staff and higher limits.', 0 ) ) as $p ) {
			$wpdb->insert( $t, array( 'code' => $p[0], 'name' => $p[1], 'description' => $p[2], 'price' => $p[3] ? 0 : null, 'is_trial' => $p[3], 'trial_days' => $p[3] ? 14 : null, 'features' => wp_json_encode( $features ), 'sort' => ++$sort * 10, 'created_at' => $now, 'updated_at' => $now ) );
		}
	}

	/**
	 * Add-ons the product needs, created once and left unpriced: an administrator sets the price in
	 * wp-admin → Neon Workspace → Plans before anyone can buy them. Existing rows are never changed.
	 */
	public static function seed_addons() {
		global $wpdb;
		$t = NWC_DB::t( 'addons' );
		if ( ! $wpdb->get_var( $wpdb->prepare( "SELECT id FROM $t WHERE code = %s", 'extra_booking_page' ) ) ) { // phpcs:ignore
			$wpdb->insert( $t, array( 'code' => 'extra_booking_page', 'name' => 'Extra booking page', 'description' => 'One more booking page beyond the main page and the four included.', 'price' => null, 'feature_code' => 'booking_pages', 'usage_limit' => 1, 'duration_days' => 30, 'is_active' => 1, 'created_at' => NWC::now(), 'updated_at' => NWC::now() ) );
		}
	}

	public static function plan( $code ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . NWC_DB::t( 'plans' ) . ' WHERE code = %s', $code ) ); // phpcs:ignore
	}

	public static function subscription( $workspace_id ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . NWC_DB::t( 'subscriptions' ) . ' WHERE workspace_id = %d', $workspace_id ) ); // phpcs:ignore
	}

	/** Only ever called for a plan the user deliberately chose. See NWC_Signup::activate(). */
	public static function start_trial( $workspace_id, $plan ) {
		global $wpdb;
		$s     = NWC::settings();
		$days  = null !== $plan->trial_days ? (int) $plan->trial_days : (int) $s['trial_days'];
		$today = gmdate( 'Y-m-d' );
		$now   = NWC::now();
		$wpdb->insert( NWC_DB::t( 'subscriptions' ), array( 'workspace_id' => (int) $workspace_id, 'plan_code' => $plan->code, 'status' => 'trial', 'period_start' => $today, 'period_end' => gmdate( 'Y-m-d', strtotime( $today ) + $days * DAY_IN_SECONDS ), 'grace_days' => (int) $plan->grace_days, 'created_at' => $now, 'updated_at' => $now ) );
		NWC_Features::flush();
	}

	public static function gateway() {
		$s = NWC::settings();
		switch ( $s['gateway_mode'] ) {
			case 'simulated':
				return new NWC_Gateway_Simulated();
			case 'test':
				if ( $s['rz_test_key'] && $s['rz_test_secret'] ) {
					return new NWC_Gateway_Razorpay( 'test', $s['rz_test_key'], NWC::unseal( $s['rz_test_secret'] ), NWC::unseal( $s['rz_webhook_secret'] ) );
				}
				break;
			case 'live':
				if ( $s['live_confirmed'] && $s['rz_live_key'] && $s['rz_live_secret'] ) {
					return new NWC_Gateway_Razorpay( 'live', $s['rz_live_key'], NWC::unseal( $s['rz_live_secret'] ), NWC::unseal( $s['rz_webhook_secret'] ) );
				}
				break;
		}
		return new NWC_Gateway_Manual();
	}

	/** Refuses every self-service billing action for agency clients. */
	private static function independent_only() {
		if ( 'agency' === NWC_Tenancy::$current->account_type ) {
			return NWC::err( 'NeonDigital manages billing for this account. Contact NeonDigital.', 403, 'nwc_agency_billing' );
		}
		return NWC_Tenancy::can( 'billing.manage' ) ? true : NWC::err( 'Only the workspace owner can change billing.', 403, 'nwc_forbidden' );
	}

	public static function overview() {
		global $wpdb;
		$w = NWC_Tenancy::$current;
		if ( ! NWC_Tenancy::can( 'billing.read' ) && ! NWC_Tenancy::can( 'billing.manage' ) ) {
			return NWC::err( 'Your role does not allow this.', 403, 'nwc_forbidden' );
		}
		$features = array_values( NWC_Features::resolve( $w ) );
		if ( 'agency' === $w->account_type ) {
			$gw = self::gateway();
			return array( 'account_type' => 'agency', 'features' => $features, 'managed_by' => 'NeonDigital', 'invoices' => $wpdb->get_results( $wpdb->prepare( 'SELECT id, doc_no, purpose, plan_code, period_start, period_end, amount, currency, status, issued_at, paid_at FROM ' . NWC_DB::t( 'sub_invoices' ) . " WHERE workspace_id = %d AND purpose = 'project_sub' ORDER BY id DESC LIMIT 50", $w->id ) ), 'gateway' => array( 'id' => $gw->id(), 'mode' => $gw->mode(), 'online' => 'manual' !== $gw->id(), 'simulated' => 'simulated' === $gw->id() ), 'plans' => self::public_plan_list() ); // phpcs:ignore
		}
		$sub   = self::subscription( $w->id );
		$end   = $sub ? ( $sub->extended_until ? $sub->extended_until : $sub->period_end ) : null;
		$plans = self::public_plan_list();
		$gw    = self::gateway();
		return array(
			'account_type' => 'independent',
			'subscription' => $sub ? array( 'plan_code' => $sub->plan_code, 'pending_plan_code' => $sub->pending_plan_code, 'status' => $sub->status, 'state' => NWC_Features::lifecycle( null, $end, $sub->grace_days, NWC::today( $w->timezone ) ), 'period_start' => $sub->period_start, 'period_end' => $end, 'grace_days' => (int) $sub->grace_days ) : null,
			'plan'         => $sub ? self::plan( $sub->plan_code ) : null,
			'plans'        => $plans,
			'addons'       => $wpdb->get_results( 'SELECT code, name, description, price, feature_code, usage_limit, duration_days FROM ' . NWC_DB::t( 'addons' ) . ' WHERE is_active = 1' ), // phpcs:ignore
			'invoices'     => $wpdb->get_results( $wpdb->prepare( 'SELECT id, doc_no, purpose, plan_code, addon_code, period_start, period_end, amount, currency, status, issued_at, paid_at FROM ' . NWC_DB::t( 'sub_invoices' ) . ' WHERE workspace_id = %d ORDER BY id DESC LIMIT 50', $w->id ) ), // phpcs:ignore
			'payments'     => $wpdb->get_results( $wpdb->prepare( 'SELECT id, gateway, mode, is_real, amount, currency, status, reference, created_at FROM ' . NWC_DB::t( 'transactions' ) . " WHERE workspace_id = %d AND category = 'subscription' ORDER BY id DESC LIMIT 50", $w->id ) ), // phpcs:ignore
			'features'     => $features,
			'gateway'      => array( 'id' => $gw->id(), 'mode' => $gw->mode(), 'online' => 'manual' !== $gw->id(), 'simulated' => 'simulated' === $gw->id() ),
		);
	}

	private static function public_plan_list() {
		global $wpdb;
		$plans = $wpdb->get_results( 'SELECT code, name, description, price, annual_price, is_trial, trial_days, currency, billing_period, features FROM ' . NWC_DB::t( 'plans' ) . ' WHERE is_active = 1 AND is_public = 1 ORDER BY sort ASC, id ASC' ); // phpcs:ignore
		foreach ( $plans as $p ) {
			$p->features = array_keys( (array) json_decode( (string) $p->features, true ) );
		}
		return $plans;
	}

	/** @return float|null Amount for a billing cycle; null when the administrator has not set one. */
	public static function price_for( $plan, $cycle ) {
		if ( 'year' === $cycle ) {
			return null !== $plan->annual_price ? (float) $plan->annual_price : ( 'year' === $plan->billing_period && null !== $plan->price ? (float) $plan->price : null );
		}
		return 'month' === $plan->billing_period && null !== $plan->price ? (float) $plan->price : null;
	}

	public static function next_period( $plan, $from, $cycle = null ) {
		$months = 'year' === ( $cycle ? $cycle : $plan->billing_period ) ? 12 : 1;
		return gmdate( 'Y-m-d', strtotime( $from . ' +' . $months . ' month' ) );
	}

	/** One open invoice per purpose and period: repeating the action returns the same one. */
	public static function issue_invoice( $workspace_id, $purpose, $plan_code, $addon_code, $start, $end, $amount, $currency = 'INR' ) {
		global $wpdb;
		$t   = NWC_DB::t( 'sub_invoices' );
		$dup = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $t WHERE workspace_id = %d AND purpose = %s AND plan_code = %s AND addon_code = %s AND status = 'issued' ORDER BY id DESC LIMIT 1", $workspace_id, $purpose, $plan_code, $addon_code ) ); // phpcs:ignore
		if ( $dup ) {
			return $dup;
		}
		if ( ! NWC::lock( 'subinv' ) ) {
			return NWC::err( 'Busy right now. Please try again.', 503, 'nwc_busy' );
		}
		try {
			$n   = (int) get_option( 'nwc_subinv_next', 1 );
			$now = NWC::now();
			$wpdb->insert( $t, array( 'workspace_id' => (int) $workspace_id, 'doc_no' => 'NW-' . gmdate( 'Y' ) . '-' . str_pad( (string) $n, 5, '0', STR_PAD_LEFT ), 'purpose' => $purpose, 'plan_code' => $plan_code, 'addon_code' => $addon_code, 'period_start' => $start, 'period_end' => $end, 'amount' => round( (float) $amount, 2 ), 'currency' => $currency, 'status' => 'issued', 'issued_at' => $now, 'created_at' => $now, 'updated_at' => $now ) );
			$id = (int) $wpdb->insert_id;
			update_option( 'nwc_subinv_next', $n + 1, false );
		} finally {
			NWC::unlock( 'subinv' );
		}
		NWC::audit( 'subscription_invoice_issued', array( 'workspace_id' => $workspace_id, 'kind' => 'billing', 'entity' => 'sub_invoice', 'entity_id' => $id ) );
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $t WHERE id = %d", $id ) ); // phpcs:ignore
	}

	/**
	 * Upgrade: invoice now; the plan changes when it is paid. Downgrade: applies at the period end.
	 * A plan without a price cannot be charged, so it needs the administrator first.
	 */
	public static function change_plan( WP_REST_Request $r ) {
		global $wpdb;
		$g = self::independent_only();
		if ( is_wp_error( $g ) ) {
			return $g;
		}
		$b     = NWC_REST::body( $r );
		$cycle = 'year' === ( $b['cycle'] ?? '' ) ? 'year' : 'month';
		$plan  = self::plan( sanitize_key( (string) ( $b['plan_code'] ?? '' ) ) );
		if ( ! $plan || ! (int) $plan->is_active || ! (int) $plan->is_public ) {
			return NWC::err( 'Plan not found.', 404, 'nwc_not_found' );
		}
		$w   = NWC_Tenancy::$current;
		$sub = self::subscription( $w->id );
		if ( (int) $plan->is_trial ) {
			return NWC::err( 'A trial can only be chosen when a workspace is created.', 409, 'nwc_conflict' );
		}
		$price = self::price_for( $plan, $cycle );
		if ( null === $price ) {
			return NWC::err( 'This plan has no price yet. Contact NeonDigital.', 409, 'nwc_no_price' );
		}
		$cur = $sub ? self::plan( $sub->plan_code ) : null;
		if ( $sub && $cur && 'active' === $sub->status && (float) $price < (float) self::price_for( $cur, $sub->billing_cycle ) ) {
			$wpdb->update( NWC_DB::t( 'subscriptions' ), array( 'pending_plan_code' => $plan->code, 'updated_at' => NWC::now() ), array( 'id' => $sub->id ) );
			NWC::audit( 'plan_downgrade_scheduled', array( 'kind' => 'billing', 'entity' => 'subscription', 'entity_id' => $sub->id, 'details' => array( 'to' => $plan->code ) ) );
			return array( 'scheduled' => true, 'effective' => $sub->period_end );
		}
		$today = NWC::today( $w->timezone );
		$inv   = self::issue_invoice( $w->id, 'plan_change', $plan->code, '', $today, self::next_period( $plan, $today, $cycle ), $price, $plan->currency );
		return is_wp_error( $inv ) ? $inv : array( 'invoice' => $inv );
	}

	public static function buy_addon( WP_REST_Request $r ) {
		global $wpdb;
		$g = self::independent_only();
		if ( is_wp_error( $g ) ) {
			return $g;
		}
		$a = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . NWC_DB::t( 'addons' ) . ' WHERE code = %s AND is_active = 1', sanitize_key( (string) ( NWC_REST::body( $r )['code'] ?? '' ) ) ) ); // phpcs:ignore
		if ( ! $a ) {
			return NWC::err( 'Add-on not found.', 404, 'nwc_not_found' );
		}
		if ( null === $a->price ) {
			return NWC::err( 'This add-on has no price yet. Contact NeonDigital.', 409, 'nwc_no_price' );
		}
		$today = NWC::today( NWC_Tenancy::$current->timezone );
		$inv   = self::issue_invoice( NWC_Tenancy::$current->id, 'addon', '', $a->code, $today, gmdate( 'Y-m-d', strtotime( $today ) + (int) $a->duration_days * DAY_IN_SECONDS ), $a->price );
		return is_wp_error( $inv ) ? $inv : array( 'invoice' => $inv );
	}

	private static function own_invoice( $id ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . NWC_DB::t( 'sub_invoices' ) . ' WHERE id = %d AND workspace_id = %d', $id, NWC_Tenancy::$current->id ) ); // phpcs:ignore
	}

	/** A project's own subscription is the client's to pay, even on an agency Workspace. */
	private static function pay_guard( $v ) {
		if ( $v && 'project_sub' === $v->purpose ) {
			return NWC_Tenancy::can( 'billing.manage' ) ? true : NWC::err( 'Only the workspace owner can pay this.', 403, 'nwc_forbidden' );
		}
		return self::independent_only();
	}

	public static function checkout( WP_REST_Request $r ) {
		global $wpdb;
		$v = self::own_invoice( (int) $r['id'] );
		$g = self::pay_guard( $v );
		if ( is_wp_error( $g ) ) {
			return $g;
		}
		if ( ! $v || 'issued' !== $v->status ) {
			return NWC::err( 'Invoice not found or already settled.', 404, 'nwc_not_found' );
		}
		$gw    = self::gateway();
		$order = $gw->create_order( (float) $v->amount, $v->currency, $v->doc_no );
		if ( is_wp_error( $order ) ) {
			return $order;
		}
		$now = NWC::now();
		$wpdb->insert( NWC_DB::t( 'transactions' ), array( 'workspace_id' => (int) $v->workspace_id, 'category' => 'subscription', 'ref_id' => (int) $v->id, 'gateway' => $gw->id(), 'mode' => $gw->mode(), 'is_real' => $gw->is_real() ? 1 : 0, 'amount' => $v->amount, 'currency' => $v->currency, 'status' => 'created', 'gateway_order_id' => $order['order_id'], 'created_at' => $now, 'updated_at' => $now ) );
		$biz = 'Neon Workspace';
		return array( 'order_id' => $order['order_id'], 'checkout' => array_merge( $order['checkout'], array( 'name' => $biz, 'description' => 'Invoice ' . $v->doc_no ) ), 'gateway' => $gw->id(), 'mode' => $gw->mode() );
	}

	public static function verify( WP_REST_Request $r ) {
		$v = self::own_invoice( (int) $r['id'] );
		$g = self::pay_guard( $v );
		if ( is_wp_error( $g ) ) {
			return $g;
		}
		if ( ! $v ) {
			return NWC::err( 'Invoice not found.', 404, 'nwc_not_found' );
		}
		$gw  = self::gateway();
		$res = $gw->verify_payment( NWC_REST::body( $r ) );
		if ( is_wp_error( $res ) ) {
			NWC::audit( 'payment_verification_failed', array( 'kind' => 'billing', 'entity' => 'sub_invoice', 'entity_id' => $v->id ) );
			return $res;
		}
		return self::settle_order( $gw, $res['order_id'], $res['payment_id'], (int) $v->id );
	}

	/**
	 * Settles a verified payment once. The order must be one this server created for this
	 * invoice, and a repeated payment id changes nothing.
	 */
	public static function settle_order( $gw, $order_id, $payment_id, $expect_invoice = 0, $paid_paise = null ) {
		global $wpdb;
		$t  = NWC_DB::t( 'transactions' );
		$tx = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $t WHERE gateway = %s AND gateway_order_id = %s", $gw->id(), $order_id ) ); // phpcs:ignore
		if ( ! $tx || ( $expect_invoice && (int) $tx->ref_id !== (int) $expect_invoice ) ) {
			return NWC::err( 'This payment does not match the invoice.', 400, 'nwc_payment_mismatch' );
		}
		if ( ! NWC::lock( 'tx:' . $tx->id ) ) {
			return NWC::err( 'Busy right now. Please try again.', 503, 'nwc_busy' );
		}
		try {
			$tx = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $t WHERE id = %d", $tx->id ) ); // phpcs:ignore
			if ( 'captured' === $tx->status ) {
				return array( 'ok' => true, 'duplicate' => true, 'simulated' => ! (int) $tx->is_real );
			}
			// Confirmed with the gateway itself, unless a verified webhook already carries the amount.
			if ( null === $paid_paise && method_exists( $gw, 'confirm_payment' ) ) {
				$c = $gw->confirm_payment( $payment_id, $order_id );
				if ( is_wp_error( $c ) ) {
					return $c;
				}
				$paid_paise = $c['amount'];
			}
			if ( null !== $paid_paise && (int) $paid_paise !== (int) round( (float) $tx->amount * 100 ) ) {
				NWC::audit( 'payment_amount_mismatch', array( 'workspace_id' => $tx->workspace_id, 'user_id' => 0, 'kind' => 'billing', 'entity' => 'transaction', 'entity_id' => $tx->id, 'details' => array( 'expected' => (float) $tx->amount, 'paid_paise' => (int) $paid_paise ) ) );
				return NWC::err( 'The amount paid does not match the invoice.', 400, 'nwc_payment_mismatch' );
			}
			$wpdb->update( $t, array( 'status' => 'captured', 'gateway_payment_id' => $payment_id, 'updated_at' => NWC::now() ), array( 'id' => $tx->id ) );
			if ( 'subscription' === $tx->category ) {
				self::mark_paid( (int) $tx->ref_id );
			} elseif ( 'business_invoice' === $tx->category ) {
				NWC_Finance::apply_settled( $tx, $payment_id );
			}
		} finally {
			NWC::unlock( 'tx:' . $tx->id );
		}
		NWC::audit( 'payment_captured', array( 'workspace_id' => $tx->workspace_id, 'kind' => 'billing', 'entity' => 'transaction', 'entity_id' => $tx->id, 'details' => array( 'gateway' => $gw->id(), 'mode' => $gw->mode(), 'is_real' => (int) $tx->is_real ) ) );
		return array( 'ok' => true, 'duplicate' => false, 'simulated' => ! (int) $tx->is_real );
	}

	/** Paid invoice → renewed or upgraded access. Access comes back; no data was ever removed. */
	public static function mark_paid( $invoice_id ) {
		global $wpdb;
		$t = NWC_DB::t( 'sub_invoices' );
		$v = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $t WHERE id = %d", $invoice_id ) ); // phpcs:ignore
		if ( ! $v || 'paid' === $v->status ) {
			return false;
		}
		$won = $wpdb->query( $wpdb->prepare( "UPDATE $t SET status = 'paid', paid_at = %s, updated_at = %s WHERE id = %d AND status = 'issued'", NWC::now(), NWC::now(), $invoice_id ) ); // phpcs:ignore
		if ( ! $won ) {
			return false;
		}
		$s_t = NWC_DB::t( 'subscriptions' );
		$sub = self::subscription( $v->workspace_id );
		$was_locked = $sub && 'locked' === $sub->status;
		if ( 'project_sub' === $v->purpose ) {
			NWC_Connect::on_invoice_paid( $v );
		} elseif ( 'addon' === $v->purpose ) {
			$a = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . NWC_DB::t( 'addons' ) . ' WHERE code = %s', $v->addon_code ) ); // phpcs:ignore
			if ( $a ) {
				$wpdb->insert( NWC_DB::t( 'workspace_addons' ), array( 'workspace_id' => (int) $v->workspace_id, 'addon_code' => $a->code, 'feature_code' => $a->feature_code, 'usage_limit' => $a->usage_limit, 'starts_on' => $v->period_start, 'expires_on' => $v->period_end, 'sub_invoice_id' => (int) $v->id, 'created_at' => NWC::now() ) );
			}
		} elseif ( $sub ) {
			$plan = self::plan( '' !== $v->plan_code ? $v->plan_code : $sub->plan_code );
			$wpdb->update( $s_t, array( 'plan_code' => $plan ? $plan->code : $sub->plan_code, 'pending_plan_code' => '', 'status' => 'active', 'period_start' => $v->period_start, 'period_end' => $v->period_end, 'extended_until' => null, 'grace_days' => $plan ? (int) $plan->grace_days : (int) $sub->grace_days, 'updated_at' => NWC::now() ), array( 'id' => $sub->id ) );
		}
		NWC_Features::flush();
		NWC::audit( 'subscription_payment_applied', array( 'workspace_id' => $v->workspace_id, 'kind' => 'billing', 'entity' => 'sub_invoice', 'entity_id' => $v->id ) );
		NWC_Sync::enqueue( (int) $v->workspace_id, 'workspace', (int) $v->workspace_id );
		NWC_Notify::workspace_owners( (int) $v->workspace_id, 'billing', 'Payment received', 'Invoice ' . $v->doc_no . ' is paid.', '#/billing', 'subpaid:' . $v->id );
		NWC_Signup::email_owners( (int) $v->workspace_id, 'payment_success', array( 'invoice' => $v->doc_no ) );
		if ( $was_locked ) {
			NWC_Signup::email_owners( (int) $v->workspace_id, 'subscription_restored', array() );
		}
		return true;
	}

	/** Administrator records money received offline against a subscription invoice. */
	public static function admin_record_payment( $invoice_id, $reference, $user_id ) {
		global $wpdb;
		$v = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . NWC_DB::t( 'sub_invoices' ) . ' WHERE id = %d', $invoice_id ) ); // phpcs:ignore
		if ( ! $v || 'issued' !== $v->status ) {
			return NWC::err( 'Invoice not found or already settled.', 404, 'nwc_not_found' );
		}
		$now = NWC::now();
		$wpdb->insert( NWC_DB::t( 'transactions' ), array( 'workspace_id' => (int) $v->workspace_id, 'category' => 'subscription', 'ref_id' => (int) $v->id, 'gateway' => 'manual', 'mode' => 'manual', 'is_real' => 1, 'amount' => $v->amount, 'currency' => $v->currency, 'status' => 'captured', 'reference' => mb_substr( sanitize_text_field( (string) $reference ), 0, 120 ), 'recorded_by' => (int) $user_id, 'created_at' => $now, 'updated_at' => $now ) );
		return self::mark_paid( (int) $v->id );
	}

	/** Business invoice payments are recorded by the business; this keeps the separate reference. */
	public static function record_manual( $category, $v, $in ) {
		global $wpdb;
		$key = isset( $in['idempotency_key'] ) ? 'man_' . hash( 'sha256', $category . '|' . $v->id . '|' . $in['idempotency_key'] ) : null;
		if ( $key ) {
			$dup = $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . NWC_DB::t( 'transactions' ) . " WHERE gateway = 'manual' AND gateway_payment_id = %s", $key ) ); // phpcs:ignore
			if ( $dup ) {
				return (int) $dup;
			}
		}
		$now = NWC::now();
		$wpdb->insert( NWC_DB::t( 'transactions' ), array( 'workspace_id' => (int) $v->workspace_id, 'category' => $category, 'ref_id' => (int) $v->id, 'gateway' => 'manual', 'mode' => 'manual', 'is_real' => 1, 'amount' => round( (float) ( $in['amount'] ?? 0 ), 2 ), 'status' => 'captured', 'gateway_payment_id' => $key, 'reference' => mb_substr( sanitize_text_field( (string) ( $in['reference'] ?? '' ) ), 0, 120 ), 'recorded_by' => get_current_user_id(), 'created_at' => $now, 'updated_at' => $now ) );
		return (int) $wpdb->insert_id;
	}

	/** Verified by signature; each gateway event id is processed once. */
	public static function razorpay_webhook( WP_REST_Request $r ) {
		$gw = self::gateway();
		return 'razorpay' === $gw->id() ? self::process_webhook( $gw, $r, 'razorpay' ) : NWC::err( 'Invalid signature.', 401, 'nwc_bad_signature' );
	}

	/** Shared by the platform account and each business's own account. $source keeps their events apart. */
	public static function process_webhook( $gw, WP_REST_Request $r, $source ) {
		global $wpdb;
		$body = (string) $r->get_body();
		if ( ! $gw->verify_webhook( $body, (string) $r->get_header( 'x_razorpay_signature' ) ) ) {
			NWC::audit( 'webhook_signature_rejected', array( 'workspace_id' => null, 'user_id' => 0, 'kind' => 'billing', 'details' => array( 'source' => $source ) ) );
			return NWC::err( 'Invalid signature.', 401, 'nwc_bad_signature' );
		}
		$d  = json_decode( $body, true );
		$id = (string) ( $r->get_header( 'x_razorpay_event_id' ) ?: hash( 'sha256', $body ) );
		$wpdb->suppress_errors( true );
		$new = $wpdb->query( $wpdb->prepare( 'INSERT IGNORE INTO ' . NWC_DB::t( 'gateway_events' ) . ' (gateway, event_id, event, processed_at) VALUES (%s, %s, %s, %s)', mb_substr( $source, 0, 20 ), $id, (string) ( $d['event'] ?? '' ), NWC::now() ) ); // phpcs:ignore
		$wpdb->suppress_errors( false );
		if ( ! $new ) {
			return array( 'ok' => true, 'duplicate' => true );
		}
		$t   = NWC_DB::t( 'transactions' );
		$pay = $d['payload']['payment']['entity'] ?? array();
		if ( 'payment.captured' === ( $d['event'] ?? '' ) && ! empty( $pay['order_id'] ) && ! empty( $pay['id'] ) ) {
			$res = self::settle_order( $gw, $pay['order_id'], $pay['id'], 0, (int) ( $pay['amount'] ?? 0 ) );
			return is_wp_error( $res ) ? array( 'ok' => true, 'ignored' => true ) : array( 'ok' => true );
		}
		if ( 'payment.failed' === ( $d['event'] ?? '' ) && ! empty( $pay['order_id'] ) ) {
			$tx = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $t WHERE gateway = 'razorpay' AND gateway_order_id = %s AND status = 'created'", $pay['order_id'] ) ); // phpcs:ignore
			if ( $tx ) {
				$wpdb->update( $t, array( 'status' => 'failed', 'updated_at' => NWC::now() ), array( 'id' => $tx->id ) );
				NWC::audit( 'payment_failed', array( 'workspace_id' => $tx->workspace_id, 'user_id' => 0, 'kind' => 'billing', 'entity' => 'transaction', 'entity_id' => $tx->id ) );
				if ( 'subscription' === $tx->category ) {
					$inv = $wpdb->get_row( $wpdb->prepare( 'SELECT doc_no FROM ' . NWC_DB::t( 'sub_invoices' ) . ' WHERE id = %d', $tx->ref_id ) ); // phpcs:ignore
					NWC_Notify::workspace_owners( (int) $tx->workspace_id, 'billing', 'A payment failed', 'Please try again from Subscription & Billing.', '#/billing', 'payfail:' . $tx->id );
					NWC_Signup::email_owners( (int) $tx->workspace_id, 'payment_failure', array( 'invoice' => $inv ? $inv->doc_no : '' ) );
				}
			}
		}
		if ( 'refund.processed' === ( $d['event'] ?? '' ) && ! empty( $d['payload']['refund']['entity']['payment_id'] ) ) {
			self::mark_refunded( (string) $d['payload']['refund']['entity']['payment_id'], (int) ( $d['payload']['refund']['entity']['amount'] ?? 0 ) );
		}
		return array( 'ok' => true );
	}

	/** Records a refund once. A subscription stays as it is until an administrator decides. */
	public static function mark_refunded( $payment_id, $paise = 0 ) {
		global $wpdb;
		$t  = NWC_DB::t( 'transactions' );
		$tx = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $t WHERE gateway = 'razorpay' AND gateway_payment_id = %s", $payment_id ) ); // phpcs:ignore
		if ( ! $tx || 'refunded' === $tx->status ) {
			return false;
		}
		$wpdb->update( $t, array( 'status' => 'refunded', 'updated_at' => NWC::now() ), array( 'id' => $tx->id ) );
		if ( 'business_invoice' === $tx->category ) {
			$wpdb->query( $wpdb->prepare( 'UPDATE ' . NWC_DB::t( 'biz_payments' ) . " SET voided_at = %s, void_reason = 'Refunded through Razorpay' WHERE transaction_id = %d AND voided_at IS NULL", NWC::now(), $tx->id ) ); // phpcs:ignore
			NWC_Invoices::recalc( (int) $tx->ref_id, (int) $tx->workspace_id );
		}
		NWC::audit( 'payment_refunded', array( 'workspace_id' => $tx->workspace_id, 'user_id' => 0, 'kind' => 'billing', 'entity' => 'transaction', 'entity_id' => $tx->id, 'details' => array( 'paise' => $paise ) ) );
		return true;
	}

	/**
	 * Daily lifecycle: issue renewal invoices, send reminders, apply scheduled downgrades and set
	 * status. Locking only restricts features. No customer record is ever deleted here.
	 */
	public static function daily( $today = null ) {
		global $wpdb;
		$today  = $today ? $today : gmdate( 'Y-m-d' );
		$notice = (int) NWC::settings()['renewal_notice_days'];
		$rows   = $wpdb->get_results( 'SELECT * FROM ' . NWC_DB::t( 'subscriptions' ) ); // phpcs:ignore
		$n      = 0;
		foreach ( $rows as $sub ) {
			$end   = $sub->extended_until ? $sub->extended_until : $sub->period_end;
			$state = NWC_Features::lifecycle( null, $end, $sub->grace_days, $today, $notice );
			if ( 'pending_payment' === $sub->status ) {
				continue; // Nothing has started until the first payment is verified.
			}
			$plan  = self::plan( $sub->pending_plan_code ? $sub->pending_plan_code : $sub->plan_code );
			$label = array( 'active' => $sub->status, 'renewal_due' => 'renewal_due', 'grace' => 'grace', 'locked' => 'locked' );
			$new   = isset( $label[ $state ] ) ? $label[ $state ] : $sub->status;
			if ( 'active' === $state && in_array( $sub->status, array( 'renewal_due', 'grace', 'locked' ), true ) ) {
				$new = 'active';
			}
			if ( $new !== $sub->status ) {
				$wpdb->update( NWC_DB::t( 'subscriptions' ), array( 'status' => $new, 'updated_at' => NWC::now() ), array( 'id' => $sub->id ) );
				NWC::audit( 'subscription_' . $new, array( 'workspace_id' => $sub->workspace_id, 'kind' => 'billing', 'entity' => 'subscription', 'entity_id' => $sub->id, 'user_id' => 0 ) );
				NWC_Sync::enqueue( (int) $sub->workspace_id, 'workspace', (int) $sub->workspace_id );
				$n++;
			}
			// A lapsed account renews from today, so it does not pay for the time it was locked.
			$renew = $plan ? self::price_for( $plan, $sub->billing_cycle ) : null;
			if ( in_array( $state, array( 'renewal_due', 'grace', 'locked' ), true ) && null !== $renew && ! (int) $plan->is_trial && $end ) {
				$from = 'locked' === $state ? $today : $end;
				self::issue_invoice( $sub->workspace_id, 'renewal', $plan->code, '', $from, self::next_period( $plan, $from, $sub->billing_cycle ), $renew, $plan->currency );
			}
			if ( in_array( $state, array( 'renewal_due', 'grace', 'locked' ), true ) && $sub->last_reminder_on !== $today && ( 'locked' !== $state || ! $sub->last_reminder_on || $sub->last_reminder_on < gmdate( 'Y-m-d', strtotime( $today ) - 7 * DAY_IN_SECONDS ) ) ) {
				$msgs = array( 'renewal_due' => 'Your plan renews on ' . $end . '.', 'grace' => 'Your plan ended on ' . $end . '. Renew to keep access.', 'locked' => 'Your plan has ended. Your data is safe; renew to unlock it.' );
				$trial = 'trial' === $sub->status || ( $plan && (int) $plan->is_trial );
				NWC_Notify::workspace_owners( (int) $sub->workspace_id, 'billing', 'Subscription reminder', $msgs[ $state ], '#/billing', 'sub:' . $sub->id . ':' . $state . ':' . $today );
				$tpl = array( 'renewal_due' => $trial ? 'trial_expiring' : 'renewal_due', 'grace' => $trial ? 'trial_expired' : 'renewal_due', 'locked' => 'subscription_locked' );
				NWC_Signup::email_owners( (int) $sub->workspace_id, $tpl[ $state ], array( 'end_date' => $end, 'plan' => $plan ? $plan->name : '' ) );
				$wpdb->update( NWC_DB::t( 'subscriptions' ), array( 'last_reminder_on' => $today ), array( 'id' => $sub->id ) );
			}
		}
		NWC_Features::flush();
		return $n;
	}
}
