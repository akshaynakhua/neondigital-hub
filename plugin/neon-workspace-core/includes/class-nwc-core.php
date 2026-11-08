<?php
/**
 * Shared helpers (NWC), tenancy and roles (NWC_Tenancy), the entitlement engine (NWC_Features).
 *
 * All access control starts here: a request's workspace comes from a membership row, never from
 * trust in a submitted id. A role in one workspace says nothing about any other workspace.
 */

defined( 'ABSPATH' ) || exit;

class NWC {

	public static function err( $msg, $status = 400, $code = 'nwc_invalid', $extra = array() ) {
		return new WP_Error( $code, $msg, array_merge( array( 'status' => $status ), $extra ) );
	}

	public static function now() {
		return gmdate( 'Y-m-d H:i:s' );
	}

	public static function today( $tz = 'UTC' ) {
		try {
			return ( new DateTimeImmutable( 'now', new DateTimeZone( $tz ) ) )->format( 'Y-m-d' );
		} catch ( Exception $e ) {
			return gmdate( 'Y-m-d' );
		}
	}

	public static function ip() {
		return isset( $_SERVER['REMOTE_ADDR'] ) ? substr( sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ), 0, 64 ) : '';
	}

	public static function valid_date( $v ) {
		if ( ! is_string( $v ) || ! preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $v, $m ) ) {
			return false;
		}
		return checkdate( (int) $m[2], (int) $m[3], (int) $m[1] );
	}

	/** Accepts "Y-m-d H:i:s", "Y-m-dTH:i" or ISO with offset; returns UTC "Y-m-d H:i:s" or null. */
	public static function to_utc( $v, $tz = 'UTC' ) {
		if ( ! is_string( $v ) || '' === $v ) {
			return null;
		}
		try {
			$d = new DateTimeImmutable( $v, new DateTimeZone( $tz ) );
			return $d->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );
		} catch ( Exception $e ) {
			return null;
		}
	}

	public static function norm_phone( $v ) {
		$d = preg_replace( '/\D+/', '', (string) $v );
		return strlen( $d ) > 10 ? substr( $d, -10 ) : $d;
	}

	public static function audit( $action, $ctx = array() ) {
		global $wpdb;
		$wpdb->insert( NWC_DB::t( 'audit' ), array(
			'workspace_id' => isset( $ctx['workspace_id'] ) ? (int) $ctx['workspace_id'] : ( NWC_Tenancy::$current ? (int) NWC_Tenancy::$current->id : null ),
			'kind'         => isset( $ctx['kind'] ) ? $ctx['kind'] : 'activity',
			'user_id'      => isset( $ctx['user_id'] ) ? (int) $ctx['user_id'] : get_current_user_id(),
			'action'       => $action,
			'source'       => isset( $ctx['source'] ) ? $ctx['source'] : ( isset( $ctx['user_id'] ) && ! $ctx['user_id'] ? 'system' : 'user' ),
			'dedupe_key'   => isset( $ctx['dedupe'] ) ? hash( 'sha256', (string) $ctx['dedupe'] ) : null,
			'entity'       => isset( $ctx['entity'] ) ? $ctx['entity'] : '',
			'entity_id'    => isset( $ctx['entity_id'] ) ? (int) $ctx['entity_id'] : null,
			'details'      => isset( $ctx['details'] ) ? wp_json_encode( $ctx['details'] ) : null,
			'ip'           => self::ip(),
			'created_at'   => self::now(),
		) );
	}

	private static function enc_key() {
		$base = defined( 'NWC_ENC_KEY' ) ? NWC_ENC_KEY : wp_salt( 'auth' );
		return hash( 'sha256', 'nwc|' . $base, true );
	}

	public static function seal( $plain ) {
		$iv  = random_bytes( 12 );
		$tag = '';
		$c   = openssl_encrypt( $plain, 'aes-256-gcm', self::enc_key(), OPENSSL_RAW_DATA, $iv, $tag );
		return base64_encode( $iv . $tag . $c ); // phpcs:ignore
	}

	public static function unseal( $sealed ) {
		$raw = base64_decode( (string) $sealed, true ); // phpcs:ignore
		if ( false === $raw || strlen( $raw ) < 29 ) {
			return '';
		}
		$p = openssl_decrypt( substr( $raw, 28 ), 'aes-256-gcm', self::enc_key(), OPENSSL_RAW_DATA, substr( $raw, 0, 12 ), substr( $raw, 12, 16 ) );
		return false === $p ? '' : $p;
	}

	/** Fixed-window limit. @return bool true when allowed. */
	public static function rate_ok( $bucket, $limit, $window = 60 ) {
		$k = 'nwc_rl_' . md5( $bucket . '|' . floor( time() / $window ) );
		$n = (int) get_transient( $k );
		set_transient( $k, $n + 1, $window * 2 );
		return $n < $limit;
	}

	/**
	 * Short advisory lock, portable across MySQL and the local SQLite setup. Used around capacity
	 * checks, numbering and payment application.
	 */
	public static function lock( $name, $wait_ms = 3000 ) {
		global $wpdb;
		$t   = NWC_DB::t( 'locks' );
		$end = microtime( true ) + $wait_ms / 1000;
		do {
			$wpdb->query( $wpdb->prepare( "DELETE FROM $t WHERE name = %s AND expires_at < %d", $name, time() ) ); // phpcs:ignore
			$wpdb->suppress_errors( true );
			$ok = $wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO $t (name, expires_at) VALUES (%s, %d)", $name, time() + 15 ) ); // phpcs:ignore
			$wpdb->suppress_errors( false );
			if ( $ok ) {
				return true;
			}
			usleep( 50000 );
		} while ( microtime( true ) < $end );
		return false;
	}

	public static function unlock( $name ) {
		global $wpdb;
		$wpdb->delete( NWC_DB::t( 'locks' ), array( 'name' => $name ) );
	}

	/**
	 * Runs $fn inside one database transaction. A WP_Error or exception from $fn rolls everything
	 * back, so a multi-step change is either complete or not applied at all.
	 *
	 * @return mixed|WP_Error What $fn returned.
	 */
	public static function tx( $fn ) {
		global $wpdb;
		$wpdb->query( 'START TRANSACTION' );
		try {
			$res = call_user_func( $fn );
		} catch ( Throwable $e ) {
			$wpdb->query( 'ROLLBACK' );
			return self::err( 'The change could not be completed and nothing was saved: ' . $e->getMessage(), 500, 'nwc_tx_failed' );
		}
		if ( is_wp_error( $res ) ) {
			$wpdb->query( 'ROLLBACK' );
			return $res;
		}
		$wpdb->query( 'COMMIT' );
		return $res;
	}

	public static function settings() {
		$s = get_option( 'nwc_settings', array() );
		return wp_parse_args( is_array( $s ) ? $s : array(), array(
			'trial_days'          => 14,
			'default_grace_days'  => 7,
			'default_plan'        => 'workspace_standard',
			'sharing_notice_ver'  => '1',
			'workdesk_url'        => '',
			'workdesk_key'        => '',
			'workdesk_secret'     => '',
			'gateway_mode'        => 'manual', // manual | simulated | test | live.
			'rz_test_key'         => '',
			'rz_test_secret'      => '',
			'rz_live_key'         => '',
			'rz_live_secret'      => '',
			'rz_webhook_secret'   => '',
			'live_confirmed'      => false,
			'email_enabled'       => false,
			'renewal_notice_days' => 14,
			'upload_max_mb'       => 10,
			'google_enabled'      => false,
			'google_client_id'    => '',
			'google_client_secret' => '',
			'facebook_enabled'    => false,
			'facebook_app_id'     => '',
			'facebook_app_secret' => '',
			'facebook_api_version' => 'v21.0',
			'terms_version'       => '1',
			'terms_url'           => '',
			'privacy_url'         => '',
			'trial_once_per_user' => true,
			'activity_retention_days' => 365,
		) );
	}

	/**
	 * The one password rule for every place a password is set: sign-up, invitations, subscription
	 * sign-up, password change and reset. @return string Empty when the password is fine.
	 */
	public static function password_problem( $p ) {
		$p = (string) $p;
		if ( strlen( $p ) < 6 || ! preg_match( '/[A-Z]/', $p ) || ! preg_match( '/[a-z]/', $p ) || ! preg_match( '/[0-9]/', $p ) ) {
			return 'Use at least 6 characters, with an uppercase letter, a lowercase letter and a number.';
		}
		return '';
	}

	public static function save_settings( $s ) {
		update_option( 'nwc_settings', $s, false );
	}
}

class NWC_Tenancy {

	/** @var object|null */
	public static $current = null;
	/** @var object|null */
	public static $member = null;

	const ROLES = array(
		'owner'   => 'Owner',
		'admin'   => 'Admin',
		'manager' => 'Manager',
		'member'  => 'Team Member',
		'viewer'  => 'Restricted Viewer',
	);

	/** Role → permission. '*' means everything. */
	const PERMS = array(
		'owner'   => array( '*' ),
		'admin'   => array( 'projects.read', 'projects.write', 'projects.manage', 'tasks.write', 'crm.read', 'crm.write', 'crm.export', 'bookings.read', 'bookings.write', 'bookings.manage', 'invoices.read', 'invoices.write', 'team.read', 'team.manage', 'calendar.business', 'calendar.business.write', 'settings.manage', 'billing.read', 'agency.use', 'agency.approve', 'audit.read', 'reports.read', 'finance.read', 'finance.write' ),
		'manager' => array( 'projects.read', 'projects.write', 'projects.manage', 'tasks.write', 'crm.read', 'crm.write', 'bookings.read', 'bookings.write', 'invoices.read', 'invoices.write', 'team.read', 'calendar.business', 'calendar.business.write', 'agency.use', 'reports.read', 'finance.read' ),
		'member'  => array( 'projects.read', 'tasks.write', 'crm.read', 'crm.write', 'bookings.read', 'bookings.write', 'team.read', 'calendar.business', 'agency.use' ),
		'viewer'  => array( 'projects.read', 'bookings.read', 'team.read', 'calendar.business', 'agency.use' ),
	);

	public static function can( $perm, $member = null ) {
		$m = $member ? $member : self::$member;
		if ( ! $m || ! isset( self::PERMS[ $m->role ] ) ) {
			return false;
		}
		$p = self::PERMS[ $m->role ];
		return in_array( '*', $p, true ) || in_array( $perm, $p, true );
	}

	/** Owner, admin or manager: may override clashes, void sent items and cancel started orders. */
	public static function is_manager( $member = null ) {
		$m = $member ? $member : self::$member;
		return $m && in_array( $m->role, array( 'owner', 'admin', 'manager' ), true );
	}

	public static function workspace( $id ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . NWC_DB::t( 'workspaces' ) . ' WHERE id = %d', $id ) ); // phpcs:ignore
	}

	public static function member_row( $workspace_id, $user_id ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . NWC_DB::t( 'members' ) . " WHERE workspace_id = %d AND user_id = %d AND status = 'active'", $workspace_id, $user_id ) ); // phpcs:ignore
	}

	/** Binds the request to a workspace the signed-in user really belongs to. */
	public static function bind( $workspace_id, $user_id ) {
		self::$current = null;
		self::$member  = null;
		$m = $workspace_id && $user_id ? self::member_row( $workspace_id, $user_id ) : null;
		if ( ! $m ) {
			return false;
		}
		$w = self::workspace( $workspace_id );
		if ( ! $w || 'active' !== $w->status ) {
			return false;
		}
		if ( 'personal' === $w->account_type && ! NWC_Personal::eligible( $user_id ) ) {
			return false; // Personal Workspace is for NeonDigital agency clients only.
		}
		self::$current = $w;
		self::$member  = $m;
		return true;
	}

	public static function for_user( $user_id ) {
		global $wpdb;
		// Phase 5: each Workspace carries its own logo and business type for the switcher.
		return $wpdb->get_results( $wpdb->prepare( 'SELECT w.id, w.uid, w.name, w.slug, w.account_type, w.kind, w.timezone, w.currency, m.role, COALESCE(b.logo_url, \'\') AS logo_url, COALESCE(b.category, w.category) AS category FROM ' . NWC_DB::t( 'members' ) . ' m JOIN ' . NWC_DB::t( 'workspaces' ) . ' w ON w.id = m.workspace_id LEFT JOIN ' . NWC_DB::t( 'business' ) . " b ON b.workspace_id = w.id WHERE m.user_id = %d AND m.status = 'active' AND w.status = 'active' AND w.account_type <> 'personal' ORDER BY w.name ASC", $user_id ) ); // phpcs:ignore
	}

	public static function unique_slug( $name ) {
		global $wpdb;
		$base = sanitize_title( $name );
		$base = '' === $base ? 'workspace' : substr( $base, 0, 100 );
		$slug = $base;
		$n    = 1;
		while ( in_array( $slug, array( 'manage', 'admin', 'api', 'www', 'app' ), true ) || $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . NWC_DB::t( 'workspaces' ) . ' WHERE slug = %s', $slug ) ) ) { // phpcs:ignore
			$n++;
			$slug = $base . '-' . $n;
		}
		return $slug;
	}

	/**
	 * @param array $in name, kind, category, timezone, account_type (internal), plan_code.
	 * @return object|WP_Error
	 */
	public static function create( $in, $user_id ) {
		global $wpdb;
		$name = mb_substr( sanitize_text_field( isset( $in['name'] ) ? (string) $in['name'] : '' ), 0, 190 );
		if ( '' === $name ) {
			return NWC::err( 'Give your workspace a name.' );
		}
		$tz = isset( $in['timezone'] ) ? (string) $in['timezone'] : 'Asia/Kolkata';
		if ( ! in_array( $tz, timezone_identifiers_list(), true ) ) {
			$tz = 'Asia/Kolkata';
		}
		$now  = NWC::now();
		$type = isset( $in['account_type'] ) && 'agency' === $in['account_type'] ? 'agency' : 'independent';
		$ok   = $wpdb->insert( NWC_DB::t( 'workspaces' ), array(
			'uid'                    => 'ws_' . bin2hex( random_bytes( 12 ) ),
			'name'                   => $name,
			'slug'                   => self::unique_slug( $name ),
			'account_type'           => $type,
			'kind'                   => isset( $in['kind'] ) && 'personal' === $in['kind'] ? 'personal' : 'business',
			'category'               => mb_substr( sanitize_text_field( isset( $in['category'] ) ? (string) $in['category'] : '' ), 0, 60 ),
			'timezone'               => $tz,
			'created_by'             => (int) $user_id,
			'created_at'             => $now,
			'updated_at'             => $now,
		) );
		if ( ! $ok ) {
			return NWC::err( 'Could not create the workspace.', 500, 'nwc_error' );
		}
		$id = (int) $wpdb->insert_id;
		$wpdb->insert( NWC_DB::t( 'members' ), array( 'workspace_id' => $id, 'user_id' => (int) $user_id, 'role' => 'owner', 'joined_at' => $now ) );
		$wpdb->insert( NWC_DB::t( 'business' ), array( 'workspace_id' => $id, 'display_name' => $name, 'category' => mb_substr( sanitize_text_field( isset( $in['category'] ) ? (string) $in['category'] : '' ), 0, 60 ), 'hours' => wp_json_encode( NWC_Bookings::default_hours() ), 'updated_at' => $now ) );
		NWC::audit( 'workspace_created', array( 'workspace_id' => $id, 'entity' => 'workspace', 'entity_id' => $id, 'user_id' => $user_id ) );
		NWC_Sync::enqueue( $id, 'workspace', $id );
		return self::workspace( $id );
	}

	public static function invite( $email, $role, $user_id ) {
		global $wpdb;
		$email = sanitize_email( (string) $email );
		if ( ! is_email( $email ) ) {
			return NWC::err( 'Enter a valid email address.' );
		}
		if ( ! isset( self::ROLES[ $role ] ) || 'owner' === $role ) {
			return NWC::err( 'Choose a valid role.' );
		}
		if ( 'admin' === $role && 'owner' !== self::$member->role ) {
			return NWC::err( 'Only the owner can invite admins.', 403, 'nwc_forbidden' );
		}
		$token = rtrim( strtr( base64_encode( random_bytes( 32 ) ), '+/', '-_' ), '=' ); // phpcs:ignore
		$wpdb->insert( NWC_DB::t( 'invites' ), array(
			'workspace_id' => (int) self::$current->id,
			'email'        => $email,
			'role'         => $role,
			'token_hash'   => hash( 'sha256', $token ),
			'expires_at'   => gmdate( 'Y-m-d H:i:s', time() + 14 * DAY_IN_SECONDS ),
			'invited_by'   => (int) $user_id,
			'created_at'   => NWC::now(),
		) );
		NWC::audit( 'team_invited', array( 'entity' => 'invite', 'entity_id' => (int) $wpdb->insert_id, 'details' => array( 'email' => $email, 'role' => $role ) ) );
		$url = home_url( '/team-invite/' . $token );
		NWC_Notify::email( $email, 'You have been invited to ' . self::$current->name, '<p>You have been invited to join <b>' . esc_html( self::$current->name ) . '</b> on Neon Workspace.</p><p><a href="' . esc_url( $url ) . '">Accept the invitation</a></p>' );
		return array( 'url' => $url );
	}

	public static function accept_invite( $token, $user ) {
		global $wpdb;
		$t   = NWC_DB::t( 'invites' );
		$inv = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $t WHERE token_hash = %s", hash( 'sha256', (string) $token ) ) ); // phpcs:ignore
		if ( ! $inv || $inv->revoked_at || $inv->accepted_at || $inv->expires_at < NWC::now() ) {
			return NWC::err( 'This invitation is no longer valid.', 410, 'nwc_invite_invalid' );
		}
		if ( 0 !== strcasecmp( $inv->email, $user->user_email ) ) {
			return NWC::err( 'This invitation was sent to a different email address.', 403, 'nwc_invite_email' );
		}
		$won = $wpdb->query( $wpdb->prepare( "UPDATE $t SET accepted_at = %s WHERE id = %d AND accepted_at IS NULL", NWC::now(), $inv->id ) ); // phpcs:ignore
		if ( ! $won ) {
			return NWC::err( 'This invitation is no longer valid.', 410, 'nwc_invite_invalid' );
		}
		$old = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . NWC_DB::t( 'members' ) . ' WHERE workspace_id = %d AND user_id = %d', $inv->workspace_id, $user->ID ) ); // phpcs:ignore
		if ( ! $old ) {
			$wpdb->insert( NWC_DB::t( 'members' ), array( 'workspace_id' => (int) $inv->workspace_id, 'user_id' => (int) $user->ID, 'role' => $inv->role, 'joined_at' => NWC::now() ) );
		} elseif ( 'active' !== $old->status ) { // Someone removed earlier and invited back.
			$wpdb->update( NWC_DB::t( 'members' ), array( 'status' => 'active', 'role' => $inv->role, 'joined_at' => NWC::now() ), array( 'id' => $old->id ) );
		}
		NWC::audit( 'team_joined', array( 'workspace_id' => $inv->workspace_id, 'entity' => 'invite', 'entity_id' => $inv->id ) );
		return (int) $inv->workspace_id;
	}
}

class NWC_Features {

	/** code → label, the catalogue shared with WorkDesk. 'kind': which plans can include it. */
	const CATALOG = array(
		'calendar_personal'  => 'Personal Calendar',
		'calendar_business'  => 'Business Calendar',
		'calendar_project'   => 'Project Calendars',
		'calendar_agency'    => 'NeonDigital Agency Calendar',
		'projects'           => 'Projects & Tasks',
		'crm'                => 'CRM',
		'bookings'           => 'Bookings',
		'team_management'    => 'Team Management',
		'invoice_management' => 'Invoices & Quotations',
		'advanced_analytics' => 'Advanced Analytics',
		'automation'         => 'Automation',
		'customer_portal'    => 'Customer Portal',
		'agency_connect'     => 'NeonDigital Connect',
		'daily_planner'      => 'Daily Planner',
		'meetings'           => 'Meetings',
		'custom_forms'       => 'Custom Forms',
		'registration_forms' => 'Registration Forms',
		'accounts_finance'   => 'Accounts & Finance',
		'google_drive'       => 'Files & Google Drive',
		'reports'            => 'Reports',
	);

	/** Sold only as add-ons (counted by their own module, not part of plans): code → label. */
	const ADDON_ONLY = array( 'booking_pages' => 'Extra booking page' );

	/** Only agency clients can hold these, whatever a plan says. */
	const AGENCY_ONLY = array( 'agency_connect', 'calendar_agency' );

	/** Built but not yet operational. Shown as Coming Soon; never unlockable controls. */
	const COMING_SOON = array( 'automation', 'advanced_analytics' );

	/** @var array Request cache. */
	private static $cache = array();

	public static function flush() {
		self::$cache = array();
	}

	/** Same rules as WorkDesk: Active → Renewal Due → Grace → Locked. */
	public static function lifecycle( $start, $end, $grace, $today = null, $notice = 14 ) {
		$today = $today ? $today : gmdate( 'Y-m-d' );
		if ( $start && $start > $today ) {
			return 'scheduled';
		}
		if ( ! $end ) {
			return 'active';
		}
		$days = (int) floor( ( strtotime( $end ) - strtotime( $today ) ) / DAY_IN_SECONDS );
		if ( $days > $notice ) {
			return 'active';
		}
		if ( $days >= 0 ) {
			return 'renewal_due';
		}
		return -$days <= max( 0, (int) $grace ) ? 'grace' : 'locked';
	}

	/**
	 * Resolves every feature for a workspace.
	 *   independent: plan + add-ons + manual grants, under the subscription's dates;
	 *   agency: only what WorkDesk granted, each feature with its own dates.
	 * Agency-only features can never come from a plan. Personal calendar stays with the owner of an
	 * independent account for as long as the plan includes it.
	 *
	 * @return array code → {feature_code,label,source,enabled,state,allowed,starts_on,expires_on,grace_days,usage_limit,coming_soon,cta}
	 */
	public static function resolve( $w ) {
		global $wpdb;
		if ( isset( self::$cache[ $w->id ] ) ) {
			return self::$cache[ $w->id ];
		}
		if ( 'personal' === $w->account_type ) {
			return self::$cache[ $w->id ] = NWC_Personal::features();
		}
		$today  = NWC::today( $w->timezone );
		$grants = array();
		foreach ( $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . NWC_DB::t( 'feature_grants' ) . ' WHERE workspace_id = %d', $w->id ) ) as $g ) { // phpcs:ignore
			$grants[ $g->feature_code ][ $g->source ] = $g;
		}
		$agency = 'agency' === $w->account_type;
		$out    = array();
		$sub    = $agency ? null : NWC_Billing::subscription( $w->id );
		$plan   = $sub ? NWC_Billing::plan( $sub->plan_code ) : null;
		$pf     = $plan ? (array) json_decode( (string) $plan->features, true ) : array();
		$addons = array();
		if ( ! $agency ) {
			foreach ( $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . NWC_DB::t( 'workspace_addons' ) . ' WHERE workspace_id = %d', $w->id ) ) as $a ) { // phpcs:ignore
				$addons[ $a->feature_code ] = $a;
			}
		}
		foreach ( self::CATALOG as $code => $label ) {
			$row = array( 'feature_code' => $code, 'label' => $label, 'source' => $agency ? 'agency' : 'subscription', 'enabled' => false, 'state' => 'disabled', 'starts_on' => null, 'expires_on' => null, 'grace_days' => 0, 'usage_limit' => null );
			$manual = isset( $grants[ $code ]['manual'] ) ? $grants[ $code ]['manual'] : null;
			if ( $agency ) {
				$g = isset( $grants[ $code ]['agency'] ) ? $grants[ $code ]['agency'] : null;
				if ( $g && (int) $g->enabled ) {
					$row = array_merge( $row, array( 'enabled' => true, 'starts_on' => $g->starts_on, 'expires_on' => $g->expires_on, 'grace_days' => null === $g->grace_days ? 0 : (int) $g->grace_days, 'usage_limit' => null === $g->usage_limit ? null : (int) $g->usage_limit ) );
					$row['state'] = self::lifecycle( $g->starts_on, $g->expires_on, $row['grace_days'], $today );
				}
			} elseif ( ! in_array( $code, self::AGENCY_ONLY, true ) ) {
				if ( $sub && 'pending_payment' !== $sub->status && isset( $pf[ $code ] ) ) {
					$end = $sub->extended_until ? $sub->extended_until : $sub->period_end;
					$row = array_merge( $row, array( 'enabled' => true, 'starts_on' => $sub->period_start, 'expires_on' => $end, 'grace_days' => (int) $sub->grace_days, 'usage_limit' => isset( $pf[ $code ]['limit'] ) ? (int) $pf[ $code ]['limit'] : null ) );
					$row['state'] = self::lifecycle( null, $end, $sub->grace_days, $today );
				}
				if ( isset( $addons[ $code ] ) ) {
					$a     = $addons[ $code ];
					$state = self::lifecycle( $a->starts_on, $a->expires_on, $sub ? $sub->grace_days : 0, $today );
					if ( ! $row['enabled'] || in_array( $state, array( 'active', 'renewal_due' ), true ) ) {
						$row = array_merge( $row, array( 'enabled' => true, 'source' => 'addon', 'starts_on' => $a->starts_on, 'expires_on' => $a->expires_on, 'state' => $state, 'usage_limit' => null === $a->usage_limit ? $row['usage_limit'] : (int) $a->usage_limit ) );
					}
				}
			}
			if ( $manual && ! ( $agency && in_array( $code, self::AGENCY_ONLY, true ) ) ) { // A platform admin's manual extension or grant wins.
				$row = array_merge( $row, array( 'source' => 'manual', 'enabled' => (bool) $manual->enabled, 'starts_on' => $manual->starts_on, 'expires_on' => $manual->expires_on, 'usage_limit' => null === $manual->usage_limit ? $row['usage_limit'] : (int) $manual->usage_limit ) );
				$row['state'] = $manual->enabled ? self::lifecycle( $manual->starts_on, $manual->expires_on, null === $manual->grace_days ? 0 : (int) $manual->grace_days, $today ) : 'disabled';
			}
			$row['coming_soon'] = in_array( $code, self::COMING_SOON, true );
			$row['allowed']     = ! $row['coming_soon'] && $row['enabled'] && in_array( $row['state'], array( 'active', 'renewal_due', 'grace' ), true );
			$row['cta']         = $row['coming_soon'] ? 'Coming Soon' : ( $agency ? 'Contact NeonDigital to Unlock' : ( in_array( $code, self::AGENCY_ONLY, true ) ? 'For NeonDigital agency clients' : 'Upgrade' ) );
			$out[ $code ]       = $row;
		}
		self::$cache[ $w->id ] = $out;
		return $out;
	}

	public static function allowed( $code, $w = null ) {
		$w = $w ? $w : NWC_Tenancy::$current;
		if ( ! $w ) {
			return false;
		}
		$all = self::resolve( $w );
		return isset( $all[ $code ] ) && $all[ $code ]['allowed'];
	}

	/** @return true|WP_Error A clean, typed refusal the UI turns into the locked screen. */
	public static function guard( $code, $w = null ) {
		if ( self::allowed( $code, $w ) ) {
			return true;
		}
		$w   = $w ? $w : NWC_Tenancy::$current;
		$all = self::resolve( $w );
		$f   = isset( $all[ $code ] ) ? $all[ $code ] : array( 'label' => $code, 'state' => 'disabled', 'cta' => 'Upgrade' );
		return NWC::err( $f['label'] . ' is not available on this account.', 402, 'nwc_feature_locked', array( 'feature' => $code, 'state' => $f['state'], 'cta' => $f['cta'] ) );
	}

	/** @return true|WP_Error */
	public static function check_limit( $code, $current_count ) {
		$all = self::resolve( NWC_Tenancy::$current );
		$lim = isset( $all[ $code ]['usage_limit'] ) ? $all[ $code ]['usage_limit'] : null;
		if ( null !== $lim && $current_count >= $lim ) {
			return NWC::err( 'Your plan allows ' . $lim . ' here. Upgrade to add more.', 402, 'nwc_limit_reached', array( 'feature' => $code, 'limit' => $lim ) );
		}
		return true;
	}

	public static function set_grant( $workspace_id, $code, $source, $data, $user_id = 0 ) {
		global $wpdb;
		$t   = NWC_DB::t( 'feature_grants' );
		$old = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $t WHERE workspace_id = %d AND feature_code = %s AND source = %s", $workspace_id, $code, $source ) ); // phpcs:ignore
		$row = array(
			'enabled'     => empty( $data['enabled'] ) ? 0 : 1,
			'starts_on'   => isset( $data['starts_on'] ) && NWC::valid_date( $data['starts_on'] ) ? $data['starts_on'] : null,
			'expires_on'  => isset( $data['expires_on'] ) && NWC::valid_date( $data['expires_on'] ) ? $data['expires_on'] : null,
			'grace_days'  => isset( $data['grace_days'] ) && '' !== $data['grace_days'] ? (int) $data['grace_days'] : null,
			'usage_limit' => isset( $data['usage_limit'] ) && '' !== $data['usage_limit'] ? (int) $data['usage_limit'] : null,
			'notes'       => isset( $data['notes'] ) ? mb_substr( sanitize_text_field( (string) $data['notes'] ), 0, 255 ) : '',
			'updated_by'  => (int) $user_id,
			'updated_at'  => NWC::now(),
		);
		$changed = ! $old || (int) $old->enabled !== $row['enabled'] || $old->starts_on !== $row['starts_on'] || $old->expires_on !== $row['expires_on'] || (string) $old->grace_days !== (string) $row['grace_days'] || (string) $old->usage_limit !== (string) $row['usage_limit'];
		if ( $old ) {
			$wpdb->update( $t, $row, array( 'id' => $old->id ) );
		} else {
			$wpdb->insert( $t, array_merge( $row, array( 'workspace_id' => (int) $workspace_id, 'feature_code' => $code, 'source' => $source ) ) );
		}
		if ( $changed ) {
			NWC::audit( 'entitlement_changed', array( 'workspace_id' => $workspace_id, 'kind' => 'admin', 'entity' => 'feature', 'details' => array( 'feature' => $code, 'source' => $source, 'to' => $row ), 'user_id' => $user_id ) );
		}
		self::flush();
	}
}
