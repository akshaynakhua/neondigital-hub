<?php
/**
 * Neon Connect, project level. The master catalogue is pushed from here to WorkDesk. WorkDesk's
 * site-signed calls (reconnect, subscription changes) are answered here. A migrated project's own
 * subscription lives here too, on the existing invoice and payment path, and is kept apart from the
 * Workspace subscription, so neither can change the other.
 */

defined( 'ABSPATH' ) || exit;

class NWC_Connect {

	const LOCKED_MESSAGE = 'No Workspace package assigned to this project.';

	public static function routes() {
		NWC_REST::route( '/connect/reactivate', 'POST', array( __CLASS__, 'reactivate' ), 'public' );
		NWC_REST::route( '/connect/provision', 'POST', array( __CLASS__, 'provision' ), 'public' );
		NWC_REST::route( '/connect/subscription', 'POST', array( __CLASS__, 'subscription' ), 'public' );
		NWC_REST::route( '/connect/catalogue', 'GET', array( __CLASS__, 'catalogue_route' ), 'public' );
		NWC_REST::route( '/agency/projects/(?P<id>\d+)/activate', 'POST', array( __CLASS__, 'activate_project' ) );
		NWC_REST::route( '/agency/project-subscriptions', 'GET', array( __CLASS__, 'project_subscriptions' ) );
	}

	/* ---------------------------- Site-signed calls from WorkDesk ---------------------------- */

	/** @return true|WP_Error */
	public static function auth_site( WP_REST_Request $r ) {
		global $wpdb;
		list( $key, $secret ) = NWC_WorkDesk::site_credentials();
		$sent = (string) $r->get_header( 'x_neon_key' );
		if ( '' === (string) $key || '' === (string) $secret || ! hash_equals( (string) $key, $sent ) ) {
			return NWC::err( 'Unknown key.', 401, 'nwc_bad_key' );
		}
		$ts    = (string) $r->get_header( 'x_neon_timestamp' );
		$nonce = (string) $r->get_header( 'x_neon_nonce' );
		if ( ! ctype_digit( $ts ) || abs( time() - (int) $ts ) > NWC_WorkDesk::MAX_SKEW || ! preg_match( '/^[A-Za-z0-9_-]{16,64}$/', $nonce ) ) {
			return NWC::err( 'Stale request.', 401, 'nwc_stale' );
		}
		if ( ! hash_equals( NWC_WorkDesk::sign( $secret, $r->get_method(), $r->get_route(), $r->get_body(), $ts, $nonce ), (string) $r->get_header( 'x_neon_signature' ) ) ) {
			return NWC::err( 'Invalid signature.', 401, 'nwc_bad_signature' );
		}
		$wpdb->suppress_errors( true );
		$fresh = $wpdb->query( $wpdb->prepare( 'INSERT IGNORE INTO ' . NWC_DB::t( 'nonces' ) . ' (nonce_hash, expires_at) VALUES (%s, %d)', hash( 'sha256', $key . '|' . $nonce ), time() + 2 * NWC_WorkDesk::MAX_SKEW ) ); // phpcs:ignore
		$wpdb->suppress_errors( false );
		return $fresh ? true : NWC::err( 'Replay refused.', 409, 'nwc_replay' );
	}

	/**
	 * Reconnect without a new invitation: the same workspace, the same data, fresh credentials.
	 * The data-sharing consent ended at disconnect and stays ended until the owner agrees again.
	 */
	public static function reactivate( WP_REST_Request $r ) {
		global $wpdb;
		$g = self::auth_site( $r );
		if ( is_wp_error( $g ) ) {
			return $g;
		}
		$b   = NWC_REST::body( $r );
		$uid = sanitize_text_field( (string) ( $b['workspace_uid'] ?? '' ) );
		$key = sanitize_text_field( (string) ( $b['api_key'] ?? '' ) );
		$sec = (string) ( $b['api_secret'] ?? '' );
		if ( ! preg_match( '/^wdc_[a-f0-9]{24}$/', $key ) || ! preg_match( '/^[a-f0-9]{64}$/', $sec ) ) {
			return NWC::err( 'Invalid credentials.' );
		}
		$w = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . NWC_DB::t( 'workspaces' ) . ' WHERE uid = %s', $uid ) ); // phpcs:ignore
		$l = $w ? $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . NWC_DB::t( 'agency_links' ) . ' WHERE workspace_id = %d', $w->id ) ) : null; // phpcs:ignore
		if ( ! $w || ! $l ) {
			return NWC::err( 'This Workspace no longer exists here. Send a new invitation.', 404, 'nwc_not_found' );
		}
		$sub = NWC_Billing::subscription( $w->id );
		if ( $sub && 'pending_payment' !== $sub->status && 'locked' !== $sub->status ) {
			return NWC::err( 'This Workspace now has its own subscription, so it cannot return to the agency arrangement automatically. Speak to the client first.', 409, 'nwc_has_subscription' );
		}
		$wpdb->update( NWC_DB::t( 'agency_links' ), array( 'status' => 'active', 'agency_connect' => 1, 'api_key' => $key, 'api_secret' => NWC::seal( $sec ), 'entitlements_stale' => 1, 'cache_version' => (int) $l->cache_version + 1 ), array( 'id' => $l->id ) );
		$wpdb->update( NWC_DB::t( 'workspaces' ), array( 'account_type' => 'agency', 'updated_at' => NWC::now() ), array( 'id' => $w->id ) );
		NWC_Features::flush();
		NWC::audit( 'agency_reconnected', array( 'workspace_id' => (int) $w->id, 'kind' => 'admin', 'user_id' => 0, 'source' => 'workdesk' ) );
		NWC_Notify::workspace_owners( (int) $w->id, 'agency', 'NeonDigital Connect was reconnected', 'Your workspace and data are as you left them. NeonDigital manages this Workspace again under your agency arrangement.', '#/agency', 'reconnect:' . time() );
		NWC_Sync::enqueue( (int) $w->id, 'workspace', (int) $w->id );
		return array( 'ok' => true );
	}

	/**
	 * Phase 4: WorkDesk adds a Workspace for a project to a client's existing account. One account per
	 * email: no invitation, no new password and no second Terms acceptance (consent is per person and
	 * was recorded when the account was made). The account owns the new Workspace and is told in the
	 * app; the notification opens the project there. An email with no account here gets 404
	 * nwc_no_account and nothing is created, so WorkDesk sends the usual invitation instead.
	 * Repeating the call with the same identity changes nothing.
	 */
	public static function provision( WP_REST_Request $r ) {
		global $wpdb;
		$g = self::auth_site( $r );
		if ( is_wp_error( $g ) ) {
			return $g;
		}
		$b     = NWC_REST::body( $r );
		$email = sanitize_email( (string) ( $b['email'] ?? '' ) );
		$uid   = sanitize_text_field( (string) ( $b['workspace_uid'] ?? '' ) );
		$key   = sanitize_text_field( (string) ( $b['api_key'] ?? '' ) );
		$sec   = (string) ( $b['api_secret'] ?? '' );
		$name  = mb_substr( trim( sanitize_text_field( (string) ( $b['workspace_name'] ?? '' ) ) ), 0, 190 );
		if ( ! is_email( $email ) || ! preg_match( '/^[A-Za-z0-9_-]{8,64}$/', $uid ) || ! preg_match( '/^wdc_[a-f0-9]{24}$/', $key ) || ! preg_match( '/^[a-f0-9]{64}$/', $sec ) || '' === $name ) {
			return NWC::err( 'Invalid request.' );
		}
		$have = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . NWC_DB::t( 'workspaces' ) . ' WHERE uid = %s', $uid ) ); // phpcs:ignore
		if ( $have ) {
			$l = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . NWC_DB::t( 'agency_links' ) . ' WHERE workspace_id = %d', $have->id ) ); // phpcs:ignore
			return $l && hash_equals( (string) $l->api_key, $key ) ? array( 'ok' => true, 'workspace_id' => (int) $have->id, 'already' => true ) : NWC::err( 'This Workspace identity is already in use.', 409, 'nwc_conflict' );
		}
		$user = get_user_by( 'email', $email );
		if ( ! $user ) {
			return NWC::err( 'No Neon Workspace account uses this email yet. Send an invitation instead.', 404, 'nwc_no_account' );
		}
		// The new Workspace starts in the timezone of the account's latest business Workspace.
		$tz = (string) $wpdb->get_var( $wpdb->prepare( 'SELECT w.timezone FROM ' . NWC_DB::t( 'workspaces' ) . ' w JOIN ' . NWC_DB::t( 'members' ) . " m ON m.workspace_id = w.id WHERE m.user_id = %d AND w.kind = 'business' ORDER BY w.id DESC LIMIT 1", $user->ID ) ); // phpcs:ignore
		$cat = '' !== (string) ( $b['business_category'] ?? '' ) ? NWC_Catalog::category_key( (string) $b['business_category'] ) : '';
		$w   = NWC_Tenancy::create( array( 'name' => $name, 'account_type' => 'agency', 'kind' => 'business', 'category' => $cat, 'timezone' => '' !== $tz ? $tz : 'Asia/Kolkata' ), $user->ID );
		if ( is_wp_error( $w ) ) {
			return $w;
		}
		$wpdb->update( NWC_DB::t( 'workspaces' ), array( 'uid' => $uid ), array( 'id' => $w->id ) );
		$wpdb->insert( NWC_DB::t( 'agency_links' ), array( 'workspace_id' => (int) $w->id, 'api_key' => $key, 'api_secret' => NWC::seal( $sec ), 'client_name' => mb_substr( sanitize_text_field( (string) ( $b['client']['name'] ?? '' ) ), 0, 190 ), 'linked_at' => NWC::now() ) );
		NWC_WorkDesk::apply_entitlements( (int) $w->id, (array) ( $b['entitlements'] ?? array() ), (array) ( $b['client'] ?? array() ) );
		NWC_Personal::mark_agency_client( $user->ID );
		NWC::audit( 'agency_provisioned', array( 'workspace_id' => (int) $w->id, 'kind' => 'admin', 'entity' => 'workspace', 'entity_id' => (int) $w->id, 'user_id' => 0, 'source' => 'workdesk' ) );
		$title = mb_substr( sanitize_text_field( (string) ( $b['title'] ?? '' ) ), 0, 190 );
		$link  = preg_match( '~^#/agency(/projects/\d+)?$~', (string) ( $b['link'] ?? '' ) ) ? (string) $b['link'] : '#/agency/projects';
		NWC_Notify::user( (int) $w->id, (int) $user->ID, 'agency', '' !== $title ? $title : 'A new project has been added to your Neon Workspace.', 'Open it from the Workspace switcher. There is nothing new to accept.', $link, 'provision:' . $uid );
		NWC_Sync::enqueue( (int) $w->id, 'workspace', (int) $w->id );
		return array( 'ok' => true, 'workspace_id' => (int) $w->id );
	}

	/** Staff with Workspace Management changing plan or dates. Billing history is never touched. */
	public static function subscription( WP_REST_Request $r ) {
		global $wpdb;
		$g = self::auth_site( $r );
		if ( is_wp_error( $g ) ) {
			return $g;
		}
		$b = NWC_REST::body( $r );
		$w = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . NWC_DB::t( 'workspaces' ) . ' WHERE uid = %s', sanitize_text_field( (string) ( $b['workspace_uid'] ?? '' ) ) ) ); // phpcs:ignore
		if ( ! $w ) {
			return NWC::err( 'Workspace not found.', 404, 'nwc_not_found' );
		}
		if ( 'agency' === $w->account_type ) {
			return NWC::err( 'NeonDigital bills this Workspace as an agency client, so it has no subscription to change.', 409, 'nwc_agency_billing' );
		}
		$sub = NWC_Billing::subscription( $w->id );
		if ( ! $sub ) {
			return NWC::err( 'This Workspace has no subscription yet.', 409, 'nwc_no_subscription' );
		}
		$row = array();
		if ( ! empty( $b['plan_code'] ) ) {
			$plan = NWC_Billing::plan( sanitize_key( (string) $b['plan_code'] ) );
			if ( ! $plan ) {
				return NWC::err( 'Plan not found.', 404, 'nwc_not_found' );
			}
			$row['plan_code'] = $plan->code;
		}
		if ( isset( $b['billing_cycle'] ) && in_array( $b['billing_cycle'], array( 'month', 'year' ), true ) ) {
			$row['billing_cycle'] = $b['billing_cycle'];
		}
		foreach ( array( 'period_end', 'extended_until' ) as $k ) {
			if ( array_key_exists( $k, $b ) ) {
				if ( '' !== (string) $b[ $k ] && ! NWC::valid_date( $b[ $k ] ) ) {
					return NWC::err( 'Enter a valid date.' );
				}
				$row[ $k ] = '' !== (string) $b[ $k ] ? $b[ $k ] : null;
			}
		}
		if ( isset( $b['grace_days'] ) && '' !== $b['grace_days'] ) {
			$row['grace_days'] = min( 365, absint( $b['grace_days'] ) );
		}
		if ( isset( $b['status'] ) && in_array( $b['status'], array( 'active', 'trial', 'renewal_due', 'grace', 'locked' ), true ) ) {
			$row['status'] = $b['status'];
		}
		if ( ! $row ) {
			return NWC::err( 'Nothing to change.' );
		}
		$row['updated_at'] = NWC::now();
		$wpdb->update( NWC_DB::t( 'subscriptions' ), $row, array( 'id' => $sub->id ) );
		NWC_Features::flush();
		NWC::audit( 'subscription_changed_by_neondigital', array( 'workspace_id' => (int) $w->id, 'kind' => 'billing', 'entity' => 'subscription', 'entity_id' => (int) $sub->id, 'user_id' => 0, 'source' => 'workdesk', 'details' => array( 'changes' => $row, 'by' => mb_substr( sanitize_text_field( (string) ( $b['actor'] ?? '' ) ), 0, 120 ) ) ) );
		NWC_Sync::enqueue( (int) $w->id, 'workspace', (int) $w->id );
		NWC_Notify::workspace_owners( (int) $w->id, 'billing', 'NeonDigital updated your subscription', '', '#/billing', 'subchg:' . $sub->id . ':' . time() );
		return array( 'ok' => true, 'directory' => NWC_Sync::directory_payload( NWC_Tenancy::workspace( $w->id ) ) );
	}

	/* ---------------------------- Master catalogue ---------------------------- */

	public static function catalogue() {
		global $wpdb;
		$items = array();
		foreach ( $wpdb->get_results( 'SELECT * FROM ' . NWC_DB::t( 'plans' ) . ' ORDER BY sort ASC, id ASC' ) as $p ) { // phpcs:ignore
			$items[] = array( 'kind' => 'plan', 'code' => $p->code, 'name' => $p->name, 'description' => (string) $p->description, 'price' => $p->price, 'annual_price' => $p->annual_price, 'billing_period' => $p->billing_period, 'currency' => $p->currency, 'features' => (array) json_decode( (string) $p->features, true ), 'grace_days' => (int) $p->grace_days, 'is_trial' => (int) $p->is_trial, 'is_public' => (int) $p->is_public, 'is_active' => (int) $p->is_active );
		}
		foreach ( $wpdb->get_results( 'SELECT * FROM ' . NWC_DB::t( 'addons' ) . ' ORDER BY id ASC' ) as $a ) { // phpcs:ignore
			$items[] = array( 'kind' => 'addon', 'code' => $a->code, 'name' => $a->name, 'description' => (string) $a->description, 'price' => $a->price, 'currency' => 'INR', 'feature_code' => $a->feature_code, 'usage_limit' => $a->usage_limit, 'duration_days' => (int) $a->duration_days, 'is_active' => (int) $a->is_active );
		}
		return $items;
	}

	public static function catalogue_route( WP_REST_Request $r ) {
		$g = self::auth_site( $r );
		return is_wp_error( $g ) ? $g : array( 'items' => self::catalogue() );
	}

	/**
	 * Sends the catalogue to WorkDesk when it has changed since the last successful send. WorkDesk
	 * treats a repeat as no change, so a retry is safe. Without credentials it just waits.
	 */
	public static function push_catalogue( $force = false ) {
		list( $key, $secret ) = NWC_WorkDesk::site_credentials();
		if ( '' === NWC_WorkDesk::base_url() || '' === (string) $key || '' === (string) $secret ) {
			return array( 'skipped' => 'not configured' );
		}
		$items = self::catalogue();
		$hash  = md5( (string) wp_json_encode( $items ) );
		if ( ! $force && get_option( 'nwc_catalogue_sent' ) === $hash ) {
			return array( 'skipped' => 'unchanged' );
		}
		$res = NWC_WorkDesk::http( $key, $secret, 'POST', '/sync/catalogue', array( 'items' => $items, 'full' => true ) );
		if ( is_wp_error( $res ) ) {
			return array( 'error' => $res->get_error_message() );
		}
		update_option( 'nwc_catalogue_sent', $hash, false );
		return $res;
	}

	/* ---------------------------- Project subscriptions ---------------------------- */

	private static function sub_row( $workspace_id, $wd_project_id ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . NWC_DB::t( 'project_subscriptions' ) . ' WHERE workspace_id = %d AND wd_project_id = %d ORDER BY id DESC LIMIT 1', $workspace_id, $wd_project_id ) ); // phpcs:ignore
	}

	private static function sub_array( $s ) {
		return array( 'id' => (int) $s->id, 'wd_project_id' => (int) $s->wd_project_id, 'wd_migration_id' => (int) $s->wd_migration_id, 'project_name' => $s->project_name, 'plan_code' => $s->plan_code, 'package_name' => $s->package_name, 'amount' => (float) $s->amount, 'billing_cycle' => $s->billing_cycle, 'status' => $s->status, 'invoice_id' => $s->invoice_id ? (int) $s->invoice_id : null, 'period_start' => $s->period_start, 'period_end' => $s->period_end );
	}

	public static function project_subscriptions() {
		global $wpdb;
		if ( ! NWC_Tenancy::can( 'billing.read' ) && ! NWC_Tenancy::can( 'billing.manage' ) ) {
			return NWC::err( 'Your role does not allow this.', 403, 'nwc_forbidden' );
		}
		return array( 'items' => array_map( array( __CLASS__, 'sub_array' ), $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . NWC_DB::t( 'project_subscriptions' ) . ' WHERE workspace_id = %d ORDER BY id DESC', NWC_Tenancy::$current->id ) ) ) ); // phpcs:ignore
	}

	/**
	 * The client activates one project's subscription. WorkDesk says what was agreed, so a browser
	 * cannot change the price. ₹0 activates at once; anything else goes through the normal invoice and
	 * payment path. Asking twice returns the same invoice, so nobody is charged twice.
	 */
	public static function activate_project( WP_REST_Request $r ) {
		global $wpdb;
		if ( ! NWC_Tenancy::can( 'billing.manage' ) ) {
			return NWC::err( 'Only the workspace owner can activate a subscription.', 403, 'nwc_forbidden' );
		}
		$w   = NWC_Tenancy::$current;
		$pid = (int) $r['id'];
		$res = NWC_WorkDesk::call( $w->id, 'GET', '/projects' );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		$found = null;
		foreach ( (array) ( $res['items'] ?? array() ) as $i ) {
			if ( (int) $i['id'] === $pid ) {
				$found = $i;
			}
		}
		$m = $found['access']['migration'] ?? null;
		if ( ! $found || ! $m ) {
			return NWC::err( 'This project has no subscription to activate.', 404, 'nwc_not_found' );
		}
		if ( 'active' === $m['status'] ) {
			return array( 'status' => 'active', 'already' => true );
		}
		$t     = NWC_DB::t( 'project_subscriptions' );
		$cycle = 'year' === $m['billing_cycle'] ? 'year' : 'month';
		$today = NWC::today( $w->timezone );
		$end   = gmdate( 'Y-m-d', strtotime( $today . ' +' . ( 'year' === $cycle ? 12 : 1 ) . ' month' ) );
		$price = null === $m['price'] ? null : round( (float) $m['price'], 2 );
		if ( null === $price ) {
			return NWC::err( 'NeonDigital has not set a price for this yet. Contact NeonDigital.', 409, 'nwc_no_price' );
		}
		$now = NWC::now();
		$s   = self::sub_row( $w->id, $pid );
		if ( ! $s || (int) $s->wd_migration_id !== (int) $m['id'] ) {
			$wpdb->insert( $t, array( 'workspace_id' => (int) $w->id, 'wd_project_id' => $pid, 'wd_migration_id' => (int) $m['id'], 'project_name' => mb_substr( sanitize_text_field( (string) $found['name'] ), 0, 190 ), 'plan_code' => sanitize_key( (string) $m['plan_code'] ), 'package_name' => mb_substr( sanitize_text_field( (string) $m['package_name'] ), 0, 190 ), 'amount' => $price, 'billing_cycle' => $cycle, 'status' => 'pending', 'created_at' => $now, 'updated_at' => $now ) );
			$s = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $t WHERE id = %d", $wpdb->insert_id ) ); // phpcs:ignore
		}
		if ( $price <= 0 ) {
			return self::finish( $s, $today, $end, 'zero-price' );
		}
		// One open invoice per project: issue_invoice treats the code as its identity.
		$inv = NWC_Billing::issue_invoice( $w->id, 'project_sub', sanitize_key( (string) $m['plan_code'] ), 'p' . $pid, $today, $end, $price );
		if ( is_wp_error( $inv ) ) {
			return $inv;
		}
		$wpdb->update( $t, array( 'invoice_id' => (int) $inv->id, 'amount' => (float) $inv->amount, 'period_start' => $inv->period_start, 'period_end' => $inv->period_end, 'updated_at' => $now ), array( 'id' => $s->id ) );
		NWC::audit( 'project_subscription_invoiced', array( 'kind' => 'billing', 'entity' => 'project', 'entity_id' => $pid, 'details' => array( 'invoice' => (int) $inv->id, 'amount' => (float) $inv->amount ) ) );
		return array( 'status' => 'invoice', 'invoice_id' => (int) $inv->id, 'doc_no' => $inv->doc_no, 'amount' => (float) $inv->amount );
	}

	/** Tells WorkDesk. A refused or failed call keeps the local state for the daily retry. */
	private static function finish( $s, $start, $end, $ref ) {
		global $wpdb;
		$wpdb->update( NWC_DB::t( 'project_subscriptions' ), array( 'status' => 'paid', 'period_start' => $start, 'period_end' => $end, 'updated_at' => NWC::now() ), array( 'id' => $s->id ) );
		$res = NWC_WorkDesk::call( (int) $s->workspace_id, 'POST', '/migrations/' . (int) $s->wd_migration_id . '/activate', array( 'period_end' => $end, 'external_ref' => $ref ) );
		NWC::audit( 'project_subscription_activated', array( 'workspace_id' => (int) $s->workspace_id, 'kind' => 'billing', 'entity' => 'project', 'entity_id' => (int) $s->wd_project_id, 'user_id' => 0, 'details' => array( 'told_workdesk' => ! is_wp_error( $res ), 'period_end' => $end ) ) );
		if ( is_wp_error( $res ) ) {
			return array( 'status' => 'waiting', 'message' => 'Paid. It will take effect as soon as NeonDigital Connect answers.' );
		}
		$wpdb->update( NWC_DB::t( 'project_subscriptions' ), array( 'status' => 'active', 'updated_at' => NWC::now() ), array( 'id' => $s->id ) );
		$wpdb->query( $wpdb->prepare( 'UPDATE ' . NWC_DB::t( 'agency_links' ) . ' SET cache_version = cache_version + 1 WHERE workspace_id = %d', $s->workspace_id ) ); // phpcs:ignore
		return array( 'status' => 'active', 'period_end' => $end );
	}

	/** From NWC_Billing::mark_paid(): only this project's subscription moves. */
	public static function on_invoice_paid( $v ) {
		global $wpdb;
		$s = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . NWC_DB::t( 'project_subscriptions' ) . ' WHERE invoice_id = %d', $v->id ) ); // phpcs:ignore
		if ( $s ) {
			self::finish( $s, $v->period_start, $v->period_end, $v->doc_no );
		}
	}

	/** Renewal invoices, and retries for anything WorkDesk has not heard about yet. */
	public static function daily( $today = null ) {
		global $wpdb;
		$today = $today ? $today : gmdate( 'Y-m-d' );
		$t     = NWC_DB::t( 'project_subscriptions' );
		foreach ( $wpdb->get_results( "SELECT * FROM $t WHERE status = 'paid'" ) as $s ) { // phpcs:ignore
			self::finish( $s, $s->period_start, $s->period_end, 'retry' );
		}
		$soon = gmdate( 'Y-m-d', strtotime( $today ) + 14 * DAY_IN_SECONDS );
		foreach ( $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $t WHERE status = 'active' AND amount > 0 AND period_end IS NOT NULL AND period_end <= %s", $soon ) ) as $s ) { // phpcs:ignore
			$next = gmdate( 'Y-m-d', strtotime( $s->period_end . ' +' . ( 'year' === $s->billing_cycle ? 12 : 1 ) . ' month' ) );
			$inv  = NWC_Billing::issue_invoice( (int) $s->workspace_id, 'project_sub', $s->plan_code, 'p' . (int) $s->wd_project_id, $s->period_end, $next, (float) $s->amount );
			if ( ! is_wp_error( $inv ) && (int) $inv->id !== (int) $s->invoice_id ) {
				$wpdb->update( $t, array( 'invoice_id' => (int) $inv->id, 'updated_at' => NWC::now() ), array( 'id' => $s->id ) );
				NWC_Notify::workspace_owners( (int) $s->workspace_id, 'billing', 'Renewal due for ' . $s->project_name, 'Invoice ' . $inv->doc_no . ' keeps this project running.', '#/billing', 'projrenew:' . $inv->id );
			}
		}
		self::push_catalogue();
	}
}
