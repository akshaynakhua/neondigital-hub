<?php
/**
 * Pricing-first signup: Pricing → Plan → Registration or Login → Onboarding → Plan activation.
 *
 * Nothing activates without a deliberate choice. A trial starts only if the user chose a trial plan.
 * A paid plan creates a pending_payment subscription and an invoice; access starts only when that
 * invoice is settled by a payment verified on the server.
 */

defined( 'ABSPATH' ) || exit;

class NWC_Signup {

	const CONSENT_KINDS = array( 'terms', 'privacy', 'data_sharing', 'connect_notice', 'marketing' );

	public static function routes() {
		NWC_REST::route( '/plans', 'GET', array( __CLASS__, 'plans_route' ), 'public' );
		NWC_REST::route( '/me/selected-plan', 'POST', array( __CLASS__, 'select_route' ), 'user' );
		NWC_REST::route( '/me/profile', 'PUT', array( __CLASS__, 'profile_route' ), 'user' );
	}

	/** New features reach existing plan records; a plan set up before v2 keeps what it had. */
	public static function migrate_plans() {
		global $wpdb;
		if ( (int) get_option( 'nwc_plans_v2' ) ) {
			return;
		}
		$t   = NWC_DB::t( 'plans' );
		$new = array( 'customer_portal', 'daily_planner', 'meetings', 'custom_forms', 'registration_forms', 'accounts_finance', 'google_drive', 'reports' );
		foreach ( $wpdb->get_results( "SELECT id, features FROM $t" ) as $p ) { // phpcs:ignore
			$f = (array) json_decode( (string) $p->features, true );
			foreach ( $new as $code ) {
				if ( ! isset( $f[ $code ] ) ) {
					$f[ $code ] = array();
				}
			}
			$wpdb->update( $t, array( 'features' => wp_json_encode( $f ) ), array( 'id' => $p->id ) );
		}
		// A site upgraded from 1.0 had no trial plan record. Add one so a trial can be chosen.
		if ( ! (int) $wpdb->get_var( "SELECT COUNT(*) FROM $t WHERE is_trial = 1" ) ) { // phpcs:ignore
			$features = array();
			foreach ( array_keys( NWC_Features::CATALOG ) as $code ) {
				if ( ! in_array( $code, NWC_Features::AGENCY_ONLY, true ) && ! in_array( $code, NWC_Features::COMING_SOON, true ) ) {
					$features[ $code ] = array();
				}
			}
			$now = NWC::now();
			$wpdb->insert( $t, array( 'code' => 'free_trial', 'name' => 'Free Trial', 'description' => 'Try everything in your plan before you pay.', 'price' => 0, 'is_trial' => 1, 'trial_days' => (int) NWC::settings()['trial_days'], 'features' => wp_json_encode( $features ), 'sort' => 0, 'created_at' => $now, 'updated_at' => $now ) );
		}
		update_option( 'nwc_plans_v2', 1 );
	}

	/* ---------------------------- Plans ---------------------------- */

	private static function shape_plan( $p ) {
		$feat   = (array) json_decode( (string) $p->features, true );
		$labels = array();
		foreach ( $feat as $code => $cfg ) {
			if ( isset( NWC_Features::CATALOG[ $code ] ) && ! in_array( $code, NWC_Features::COMING_SOON, true ) && ! in_array( $code, NWC_Features::AGENCY_ONLY, true ) ) {
				$labels[] = array( 'code' => $code, 'label' => NWC_Features::CATALOG[ $code ], 'limit' => isset( $cfg['limit'] ) ? (int) $cfg['limit'] : null );
			}
		}
		$trial = (bool) (int) $p->is_trial;
		return array(
			'code'         => $p->code,
			'name'         => $p->name,
			'description'  => (string) $p->description,
			'is_trial'     => $trial,
			'trial_days'   => $trial ? ( null !== $p->trial_days ? (int) $p->trial_days : (int) NWC::settings()['trial_days'] ) : null,
			'monthly'      => NWC_Billing::price_for( $p, 'month' ),
			'annual'       => NWC_Billing::price_for( $p, 'year' ),
			'currency'     => $p->currency,
			'user_limit'   => null !== $p->user_limit ? (int) $p->user_limit : null,
			'features'     => $labels,
			'addons'       => array_values( (array) json_decode( (string) $p->addon_codes, true ) ),
		);
	}

	public static function public_plans() {
		global $wpdb;
		$rows = $wpdb->get_results( 'SELECT * FROM ' . NWC_DB::t( 'plans' ) . ' WHERE is_active = 1 AND is_public = 1 ORDER BY sort ASC, id ASC' ); // phpcs:ignore
		return array_map( array( __CLASS__, 'shape_plan' ), $rows );
	}

	public static function public_plan( $code ) {
		if ( '' === (string) $code ) {
			return null;
		}
		$p = NWC_Billing::plan( $code );
		return $p && (int) $p->is_active && (int) $p->is_public ? self::shape_plan( $p ) : null;
	}

	public static function public_addons() {
		global $wpdb;
		return $wpdb->get_results( 'SELECT code, name, description, price, feature_code, duration_days FROM ' . NWC_DB::t( 'addons' ) . ' WHERE is_active = 1 AND price IS NOT NULL' ); // phpcs:ignore
	}

	public static function plans_route() {
		return array( 'plans' => self::public_plans(), 'addons' => self::public_addons() );
	}

	/* ---------------------------- Selected plan ---------------------------- */

	public static function remember_plan( $user_id, $code, $cycle ) {
		$code = sanitize_key( (string) $code );
		if ( '' !== $code && self::public_plan( $code ) ) {
			update_user_meta( $user_id, 'nwc_selected_plan', array( 'code' => $code, 'cycle' => 'year' === $cycle ? 'year' : 'month' ) );
		}
	}

	/** @return array|null The plan this user chose and has not used yet. */
	public static function selected_plan( $user_id ) {
		$s = get_user_meta( $user_id, 'nwc_selected_plan', true );
		if ( ! is_array( $s ) || empty( $s['code'] ) ) {
			return null;
		}
		$p = self::public_plan( $s['code'] );
		return $p ? array_merge( $p, array( 'cycle' => $s['cycle'] ) ) : null;
	}

	public static function select_route( WP_REST_Request $r ) {
		$b    = NWC_REST::body( $r );
		$plan = self::public_plan( sanitize_key( (string) ( $b['plan'] ?? '' ) ) );
		if ( ! $plan ) {
			return NWC::err( 'Plan not found.', 404, 'nwc_not_found' );
		}
		self::remember_plan( get_current_user_id(), $plan['code'], (string) ( $b['cycle'] ?? 'month' ) );
		return array( 'selected_plan' => self::selected_plan( get_current_user_id() ) );
	}

	public static function onboarding_info( $user_id ) {
		$s = NWC::settings();
		$u = get_userdata( $user_id );
		return array(
			'needs_email'     => $u ? NWC_Social::is_placeholder_email( $u->user_email ) : false,
			'terms_url'       => NWC_Legal::url( 'terms' ),
			'privacy_url'     => NWC_Legal::url( 'privacy' ),
			'needs_legal'     => ! NWC_Legal::has_accepted_any( $user_id ),
			'trial_available' => ! ( $s['trial_once_per_user'] && get_user_meta( $user_id, 'nwc_trial_used', true ) ),
			'plans'           => self::public_plans(),
		);
	}

	/** Missing details after social sign-up. The email must be free, and is unverified until used. */
	public static function profile_route( WP_REST_Request $r ) {
		$b   = NWC_REST::body( $r );
		$uid = get_current_user_id();
		$row = array( 'ID' => $uid );
		if ( isset( $b['name'] ) && '' !== trim( (string) $b['name'] ) ) {
			$row['display_name'] = mb_substr( sanitize_text_field( (string) $b['name'] ), 0, 120 );
		}
		if ( isset( $b['email'] ) ) {
			$email = sanitize_email( (string) $b['email'] );
			if ( ! is_email( $email ) ) {
				return NWC::err( 'Enter a valid email address.' );
			}
			$other = email_exists( $email );
			if ( $other && (int) $other !== $uid ) {
				return NWC::err( 'That email belongs to another account. Sign in there and link this login from Settings.', 409, 'nwc_exists' );
			}
			$row['user_email'] = $email;
		}
		if ( count( $row ) > 1 ) {
			$res = wp_update_user( $row );
			if ( is_wp_error( $res ) ) {
				return NWC::err( 'Could not save your details.' );
			}
			NWC::audit( 'profile_updated', array( 'workspace_id' => null, 'kind' => 'auth', 'user_id' => $uid, 'details' => array( 'fields' => array_keys( array_diff_key( $row, array( 'ID' => 1 ) ) ) ) ) );
		}
		return array( 'ok' => true );
	}

	/* ---------------------------- Workspace creation and activation ---------------------------- */

	public static function record_consent( $user_id, $workspace_id, $kind, $version, $granted = true ) {
		global $wpdb;
		if ( in_array( $kind, self::CONSENT_KINDS, true ) ) {
			$wpdb->insert( NWC_DB::t( 'consents' ), array( 'user_id' => (int) $user_id, 'workspace_id' => $workspace_id ? (int) $workspace_id : null, 'kind' => $kind, 'version' => mb_substr( (string) $version, 0, 20 ), 'granted' => $granted ? 1 : 0, 'ip' => NWC::ip(), 'created_at' => NWC::now() ) );
		}
	}

	/** Checks a choice before anything is written. @return object|WP_Error Plan row. */
	private static function validate_choice( $code, $cycle, $user_id ) {
		$plan = '' !== $code ? NWC_Billing::plan( $code ) : null;
		if ( ! $plan || ! (int) $plan->is_active || ! (int) $plan->is_public ) {
			return NWC::err( 'Choose a plan first.', 409, 'nwc_plan_required' );
		}
		if ( (int) $plan->is_trial ) {
			if ( NWC::settings()['trial_once_per_user'] && get_user_meta( $user_id, 'nwc_trial_used', true ) ) {
				return NWC::err( 'You have already used your free trial. Please choose a paid plan.', 409, 'nwc_trial_used' );
			}
			return $plan;
		}
		if ( null === NWC_Billing::price_for( $plan, $cycle ) ) {
			return NWC::err( 'This plan has no price for that billing period yet. Contact NeonDigital.', 409, 'nwc_no_price' );
		}
		return $plan;
	}

	/**
	 * Final step of onboarding. Terms must be accepted here. The plan comes from the body, or from
	 * the plan chosen before registering: the server never picks one on the user's behalf.
	 */
	public static function create_workspace( $b, $user_id ) {
		$saved = self::selected_plan( $user_id );
		$code  = sanitize_key( (string) ( $b['plan_code'] ?? ( $saved ? $saved['code'] : '' ) ) );
		$cycle = 'year' === ( $b['cycle'] ?? ( $saved ? $saved['cycle'] : '' ) ) ? 'year' : 'month';
		$plan  = self::validate_choice( $code, $cycle, $user_id );
		if ( is_wp_error( $plan ) ) {
			return $plan;
		}
		// Someone who registered with a password accepted there. A social sign-up accepts here.
		$first_acceptance = ! NWC_Legal::has_accepted_any( $user_id );
		if ( $first_acceptance && empty( $b['accept_terms'] ) ) {
			return NWC::err( 'Please accept the Privacy Policy and Terms & Conditions to continue.', 422, 'nwc_terms_required' );
		}
		$u = get_userdata( $user_id );
		if ( $u && NWC_Social::is_placeholder_email( $u->user_email ) ) {
			return NWC::err( 'Please add your email address first.', 422, 'nwc_email_required' );
		}
		$w = NWC_Tenancy::create( $b, $user_id );
		if ( is_wp_error( $w ) ) {
			return $w;
		}
		global $wpdb;
		$s = NWC::settings();
		if ( $first_acceptance ) {
			$how = get_user_meta( $user_id, 'nwc_no_password', true ) ? 'social' : 'onboarding';
			NWC_Legal::record_acceptance( $user_id, $w->id, $how );
			if ( ! empty( $b['marketing_consent'] ) ) {
				NWC_Legal::record_marketing( $user_id, true, $how );
			}
		} else { // Evidence from registration, now that there is a workspace to attach it to.
			$wpdb->query( $wpdb->prepare( 'UPDATE ' . NWC_DB::t( 'consents' ) . " SET workspace_id = %d WHERE user_id = %d AND workspace_id IS NULL AND kind IN ('terms','privacy')", $w->id, $user_id ) ); // phpcs:ignore
		}
		$res = self::activate( (int) $w->id, $user_id, $plan, $cycle );
		delete_user_meta( $user_id, 'nwc_selected_plan' );
		return array_merge( array( 'id' => (int) $w->id, 'slug' => $w->slug ), $res );
	}

	/** @return array {status, invoice_id?} */
	public static function activate( $workspace_id, $user_id, $plan, $cycle ) {
		global $wpdb;
		if ( (int) $plan->is_trial ) {
			NWC_Billing::start_trial( $workspace_id, $plan );
			update_user_meta( $user_id, 'nwc_trial_used', 1 );
			$sub = NWC_Billing::subscription( $workspace_id );
			NWC::audit( 'trial_started', array( 'workspace_id' => $workspace_id, 'kind' => 'billing', 'entity' => 'subscription', 'entity_id' => $sub->id, 'user_id' => $user_id, 'details' => array( 'plan' => $plan->code, 'ends' => $sub->period_end ) ) );
			self::send_email( $user_id, 'trial_activated', array( 'plan' => $plan->name, 'end_date' => $sub->period_end ) );
			return array( 'status' => 'trial', 'ends' => $sub->period_end );
		}
		$now = NWC::now();
		$wpdb->insert( NWC_DB::t( 'subscriptions' ), array( 'workspace_id' => (int) $workspace_id, 'plan_code' => $plan->code, 'status' => 'pending_payment', 'billing_cycle' => $cycle, 'grace_days' => (int) $plan->grace_days, 'created_at' => $now, 'updated_at' => $now ) );
		NWC_Features::flush();
		$today = gmdate( 'Y-m-d' );
		$inv   = NWC_Billing::issue_invoice( $workspace_id, 'signup', $plan->code, '', $today, NWC_Billing::next_period( $plan, $today, $cycle ), NWC_Billing::price_for( $plan, $cycle ), $plan->currency );
		return array( 'status' => 'pending_payment', 'invoice_id' => is_wp_error( $inv ) ? 0 : (int) $inv->id );
	}

	/** What the app needs to explain the account's state. */
	public static function subscription_state( $w ) {
		if ( 'agency' === $w->account_type || 'personal' === $w->account_type ) {
			return null;
		}
		$sub = NWC_Billing::subscription( $w->id );
		if ( ! $sub ) {
			return array( 'status' => 'none' );
		}
		$end = $sub->extended_until ? $sub->extended_until : $sub->period_end;
		return array( 'status' => $sub->status, 'plan_code' => $sub->plan_code, 'ends' => $end, 'state' => 'pending_payment' === $sub->status ? 'pending_payment' : NWC_Features::lifecycle( null, $end, $sub->grace_days, NWC::today( $w->timezone ) ) );
	}

	/* ---------------------------- Subscription email templates ---------------------------- */

	public static function default_templates() {
		return array(
			'welcome'              => array( 'Welcome to Neon Workspace', '<p>Hello {name},</p><p>Your Neon Workspace account is ready. Finish setting up your workspace at {app_url}.</p>' ),
			'trial_activated'      => array( 'Your free trial has started', '<p>Hello {name},</p><p>Your {plan} is active until {end_date}.</p>' ),
			'trial_reminder'       => array( 'Your trial is under way', '<p>Hello {name},</p><p>Your trial runs until {end_date}. Choose a plan any time at {app_url}.</p>' ),
			'trial_expiring'       => array( 'Your trial is ending soon', '<p>Hello {name},</p><p>Your trial ends on {end_date}. Choose a plan to keep your access.</p>' ),
			'trial_expired'        => array( 'Your trial has ended', '<p>Hello {name},</p><p>Your trial ended on {end_date}. Your data is safe. Choose a plan to continue.</p>' ),
			'renewal_due'          => array( 'Your subscription is due for renewal', '<p>Hello {name},</p><p>Your {plan} plan renews on {end_date}. You can pay at {app_url}.</p>' ),
			'payment_success'      => array( 'Payment received', '<p>Hello {name},</p><p>We received your payment for invoice {invoice}. Thank you.</p>' ),
			'payment_failure'      => array( 'Your payment did not go through', '<p>Hello {name},</p><p>Your payment for invoice {invoice} failed. No money was taken by us. Please try again at {app_url}.</p>' ),
			'subscription_locked'  => array( 'Your subscription has ended', '<p>Hello {name},</p><p>Paid features are locked because your plan ended on {end_date}. Your data is safe. Renew at {app_url} to restore access.</p>' ),
			'subscription_restored' => array( 'Your subscription is active again', '<p>Hello {name},</p><p>Thank you. Your access has been restored.</p>' ),
		);
	}

	public static function templates() {
		$saved = get_option( 'nwc_email_templates', array() );
		$out   = self::default_templates();
		foreach ( $out as $k => $v ) {
			if ( is_array( $saved ) && isset( $saved[ $k ] ) && is_array( $saved[ $k ] ) ) {
				$out[ $k ] = $saved[ $k ];
			}
		}
		return $out;
	}

	public static function render_template( $key, $vars ) {
		$tpls = self::templates();
		if ( ! isset( $tpls[ $key ] ) ) {
			return null;
		}
		$rep = array();
		foreach ( array_merge( array( 'app_url' => home_url( '/app/' ) ), $vars ) as $k => $v ) {
			$rep[ '{' . $k . '}' ] = esc_html( (string) $v );
		}
		return array( strtr( $tpls[ $key ][0], $rep ), strtr( $tpls[ $key ][1], $rep ) );
	}

	/** @return bool Whether the mailer accepted it. False while email is switched off. */
	public static function send_email( $user_id, $key, $vars ) {
		$u = get_userdata( $user_id );
		if ( ! $u || NWC_Social::is_placeholder_email( $u->user_email ) ) {
			return false;
		}
		$m = self::render_template( $key, array_merge( array( 'name' => $u->display_name ), $vars ) );
		return $m ? NWC_Notify::email( $u->user_email, $m[0], $m[1] ) : false;
	}

	public static function email_owners( $workspace_id, $key, $vars ) {
		global $wpdb;
		foreach ( $wpdb->get_col( $wpdb->prepare( 'SELECT user_id FROM ' . NWC_DB::t( 'members' ) . " WHERE workspace_id = %d AND status = 'active' AND role = 'owner'", $workspace_id ) ) as $u ) { // phpcs:ignore
			self::send_email( (int) $u, $key, $vars );
		}
	}
}
