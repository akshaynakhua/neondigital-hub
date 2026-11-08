<?php
/**
 * Customer Portal: for a business's own customers. Not NeonDigital Connect.
 *
 * A customer has no WordPress account. Access is an expiring secret link tied to one customer in one
 * workspace; every query below is filtered by that pair, so no other customer's records can be
 * reached by changing an id.
 */

defined( 'ABSPATH' ) || exit;

class NWC_Portal {

	const LINK_DAYS = 30;

	public static function routes() {
		NWC_REST::route( '/customers/(?P<id>\d+)/portal-link', 'POST', array( __CLASS__, 'create_link' ) );
		NWC_REST::route( '/customers/(?P<id>\d+)/portal-link', 'DELETE', array( __CLASS__, 'revoke_links' ) );
		NWC_REST::route( '/public/portal/(?P<token>[A-Za-z0-9_-]+)', 'GET', array( __CLASS__, 'view' ), 'public' );
		NWC_REST::route( '/public/portal/(?P<token>[A-Za-z0-9_-]+)', 'PUT', array( __CLASS__, 'update_profile' ), 'public' );
		NWC_REST::route( '/public/portal-access/(?P<slug>[a-z0-9-]+)', 'POST', array( __CLASS__, 'request_access' ), 'public' );
	}

	private static function issue( $ws, $customer_id, $user_id ) {
		global $wpdb;
		$token = rtrim( strtr( base64_encode( random_bytes( 32 ) ), '+/', '-_' ), '=' ); // phpcs:ignore
		$wpdb->insert( NWC_DB::t( 'portal_tokens' ), array( 'workspace_id' => (int) $ws, 'customer_id' => (int) $customer_id, 'token_hash' => hash( 'sha256', $token ), 'expires_at' => gmdate( 'Y-m-d H:i:s', time() + self::LINK_DAYS * DAY_IN_SECONDS ), 'created_by' => (int) $user_id, 'created_at' => NWC::now() ) );
		return home_url( '/portal/' . $token . '/' );
	}

	private static function guard_owner() {
		if ( ! NWC_Tenancy::can( 'crm.write' ) ) {
			return NWC::err( 'Your role does not allow this.', 403, 'nwc_forbidden' );
		}
		return NWC_Features::guard( 'customer_portal' );
	}

	private static function own_customer( $id ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . NWC_DB::t( 'customers' ) . ' WHERE id = %d AND workspace_id = %d AND deleted_at IS NULL', $id, NWC_Tenancy::$current->id ) ); // phpcs:ignore
	}

	public static function create_link( WP_REST_Request $r ) {
		$g = self::guard_owner();
		if ( is_wp_error( $g ) ) {
			return $g;
		}
		$c = self::own_customer( (int) $r['id'] );
		if ( ! $c ) {
			return NWC::err( 'Customer not found.', 404, 'nwc_not_found' );
		}
		$url = self::issue( $c->workspace_id, $c->id, get_current_user_id() );
		NWC::audit( 'portal_link_created', array( 'kind' => 'admin', 'entity' => 'customer', 'entity_id' => $c->id ) );
		return array( 'url' => $url, 'expires_days' => self::LINK_DAYS );
	}

	public static function revoke_links( WP_REST_Request $r ) {
		global $wpdb;
		$g = self::guard_owner();
		if ( is_wp_error( $g ) ) {
			return $g;
		}
		$c = self::own_customer( (int) $r['id'] );
		if ( ! $c ) {
			return NWC::err( 'Customer not found.', 404, 'nwc_not_found' );
		}
		$n = $wpdb->query( $wpdb->prepare( 'UPDATE ' . NWC_DB::t( 'portal_tokens' ) . ' SET revoked_at = %s WHERE customer_id = %d AND workspace_id = %d AND revoked_at IS NULL', NWC::now(), $c->id, $c->workspace_id ) ); // phpcs:ignore
		if ( $n ) {
			NWC::audit( 'portal_links_revoked', array( 'kind' => 'admin', 'entity' => 'customer', 'entity_id' => $c->id, 'details' => array( 'count' => (int) $n ) ) );
		}
		return array( 'ok' => true );
	}

	/** @return object|null Customer for a live token. */
	private static function customer_for( $token ) {
		global $wpdb;
		$t = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . NWC_DB::t( 'portal_tokens' ) . ' WHERE token_hash = %s AND revoked_at IS NULL AND expires_at > %s', hash( 'sha256', (string) $token ), NWC::now() ) ); // phpcs:ignore
		if ( ! $t ) {
			return null;
		}
		$w = NWC_Tenancy::workspace( $t->workspace_id );
		if ( ! $w || ! NWC_Features::allowed( 'customer_portal', $w ) ) {
			return null;
		}
		$wpdb->update( NWC_DB::t( 'portal_tokens' ), array( 'last_used_at' => NWC::now() ), array( 'id' => $t->id ) );
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . NWC_DB::t( 'customers' ) . ' WHERE id = %d AND workspace_id = %d AND deleted_at IS NULL', $t->customer_id, $t->workspace_id ) ); // phpcs:ignore
	}

	public static function view( WP_REST_Request $r ) {
		global $wpdb;
		if ( ! NWC::rate_ok( 'portal|' . NWC::ip(), 120, 60 ) ) {
			return NWC::err( 'Too many requests. Please wait a moment.', 429, 'nwc_rate_limited' );
		}
		$c = self::customer_for( (string) $r['token'] );
		if ( ! $c ) {
			return NWC::err( 'This link has expired or is not valid. Ask the business for a new one.', 404, 'nwc_not_found' );
		}
		$ws  = (int) $c->workspace_id;
		$cid = (int) $c->id;
		$biz = NWC_Bookings::business( $ws );
		$bookings = $wpdb->get_results( $wpdb->prepare( 'SELECT b.id, b.starts_at, b.ends_at, b.status, b.payment_status, b.party_size, s.name AS service_name FROM ' . NWC_DB::t( 'bookings' ) . ' b LEFT JOIN ' . NWC_DB::t( 'services' ) . ' s ON s.id = b.service_id WHERE b.workspace_id = %d AND b.customer_id = %d AND b.deleted_at IS NULL ORDER BY b.starts_at DESC LIMIT 100', $ws, $cid ) ); // phpcs:ignore
		$inv = array();
		foreach ( $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . NWC_DB::t( 'biz_invoices' ) . " WHERE workspace_id = %d AND customer_id = %d AND status <> 'draft' ORDER BY id DESC LIMIT 100", $ws, $cid ) ) as $v ) { // phpcs:ignore
			$inv[] = array( 'doc_no' => $v->doc_no, 'date' => $v->invoice_date, 'due' => $v->due_date, 'total' => (float) $v->grand_total, 'paid' => (float) $v->amount_paid, 'status' => NWC_Invoices::effective_status( $v ), 'url' => NWC_Invoices::share_url( 'invoice', $v ) );
		}
		$quo = array();
		foreach ( $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . NWC_DB::t( 'biz_quotes' ) . " WHERE workspace_id = %d AND customer_id = %d AND status <> 'draft' ORDER BY id DESC LIMIT 100", $ws, $cid ) ) as $v ) { // phpcs:ignore
			$quo[] = array( 'doc_no' => $v->doc_no, 'date' => $v->quote_date, 'total' => (float) $v->grand_total, 'status' => $v->status, 'url' => NWC_Invoices::share_url( 'quote', $v ) );
		}
		return array(
			'business'  => array( 'name' => $biz ? $biz->display_name : '', 'logo_url' => $biz ? $biz->logo_url : '', 'color' => $biz ? $biz->brand_color : '', 'phone' => $biz ? $biz->phone : '', 'email' => $biz ? $biz->email : '', 'timezone' => NWC_Tenancy::workspace( $ws )->timezone ),
			'profile'   => array( 'name' => $c->name, 'email' => $c->email, 'phone' => $c->phone, 'billing_address' => (string) $c->billing_address ),
			'bookings'  => $bookings,
			'invoices'  => $inv,
			'quotes'    => $quo,
			'documents' => NWC_Drive::shared_for_customer( $ws, $cid ),
		);
	}

	public static function update_profile( WP_REST_Request $r ) {
		global $wpdb;
		if ( ! NWC::rate_ok( 'portalw|' . NWC::ip(), 20, 300 ) ) {
			return NWC::err( 'Too many requests. Please wait a moment.', 429, 'nwc_rate_limited' );
		}
		$c = self::customer_for( (string) $r['token'] );
		if ( ! $c ) {
			return NWC::err( 'This link has expired or is not valid.', 404, 'nwc_not_found' );
		}
		$b   = NWC_REST::body( $r );
		$row = array();
		if ( isset( $b['phone'] ) ) {
			$row['phone']      = mb_substr( sanitize_text_field( (string) $b['phone'] ), 0, 40 );
			$row['phone_norm'] = NWC::norm_phone( $row['phone'] );
		}
		if ( isset( $b['billing_address'] ) ) {
			$row['billing_address'] = sanitize_textarea_field( (string) $b['billing_address'] );
		}
		if ( $row ) {
			$row['updated_at'] = NWC::now();
			$wpdb->update( NWC_DB::t( 'customers' ), $row, array( 'id' => $c->id, 'workspace_id' => $c->workspace_id ) );
			NWC::audit( 'portal_profile_updated', array( 'workspace_id' => $c->workspace_id, 'user_id' => 0, 'source' => 'customer', 'entity' => 'customer', 'entity_id' => $c->id ) );
		}
		return array( 'ok' => true );
	}

	/** The same answer whether the email is known or not, so addresses cannot be discovered. */
	public static function request_access( WP_REST_Request $r ) {
		global $wpdb;
		$ok = array( 'ok' => true, 'message' => 'If that email belongs to a customer of this business, a link is on its way.' );
		if ( ! NWC::rate_ok( 'portalreq|' . NWC::ip(), 5, 3600 ) ) {
			return NWC::err( 'Too many requests. Please try again later.', 429, 'nwc_rate_limited' );
		}
		$email = sanitize_email( (string) ( NWC_REST::body( $r )['email'] ?? '' ) );
		$w     = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . NWC_DB::t( 'workspaces' ) . ' WHERE slug = %s', (string) $r['slug'] ) ); // phpcs:ignore
		if ( ! $w || ! is_email( $email ) || ! NWC_Features::allowed( 'customer_portal', $w ) ) {
			return $ok;
		}
		$c = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . NWC_DB::t( 'customers' ) . ' WHERE workspace_id = %d AND email = %s AND deleted_at IS NULL LIMIT 1', $w->id, $email ) ); // phpcs:ignore
		if ( $c ) {
			$biz = NWC_Bookings::business( $w->id );
			$url = self::issue( $w->id, $c->id, 0 );
			NWC_Notify::email( $email, 'Your link for ' . $w->name, '<p>Open your bookings and invoices with ' . esc_html( $w->name ) . ':</p><p><a href="' . esc_url( $url ) . '">' . esc_html( $url ) . '</a></p><p>This link works for ' . self::LINK_DAYS . ' days. If you did not ask for it, you can ignore this email.</p>', $biz ? $biz->display_name : $w->name );
		}
		return $ok;
	}
}
