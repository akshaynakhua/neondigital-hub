<?php
/**
 * REST API (neon-workspace/v1), authentication and account endpoints.
 *
 * Route levels:
 *   workspace (default) - signed in, cookie + nonce, and an active member of the workspace named in
 *                         the X-NW-Workspace header. Handlers then check role and feature.
 *   user                - signed in, no workspace yet.
 *   public              - no sign-in; each handler validates and rate-limits for itself.
 * Errors never include database text.
 */

defined( 'ABSPATH' ) || exit;

class NWC_REST {

	const NS = 'neon-workspace/v1';

	/**
	 * PHP drops a request body larger than post_max_size and the route would see an empty request.
	 * Say so plainly instead (usually attachments over the limit).
	 */
	public static function too_large( $result, $server, $request ) {
		$len = isset( $_SERVER['CONTENT_LENGTH'] ) ? (int) $_SERVER['CONTENT_LENGTH'] : 0; // phpcs:ignore
		$max = wp_convert_hr_to_bytes( (string) ini_get( 'post_max_size' ) );
		if ( null === $result && $max > 0 && $len > $max && 0 === strpos( (string) $request->get_route(), '/neon-workspace/v1/' ) ) {
			return NWC::err( 'This is too large to send. Attachments can be at most 5 MB in total.', 413, 'nwc_too_large' );
		}
		return $result;
	}

	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
		add_filter( 'rest_pre_dispatch', array( __CLASS__, 'too_large' ), 5, 3 );
		add_action( 'wp_login', array( __CLASS__, 'on_login' ), 10, 2 );
		add_action( 'wp_login_failed', array( __CLASS__, 'on_login_failed' ) );
		add_filter( 'authenticate', array( __CLASS__, 'brute_force' ), 30, 2 );
		// The same password rule on WordPress's own reset and profile screens.
		add_action( 'validate_password_reset', array( __CLASS__, 'check_reset_password' ), 10, 2 );
		add_action( 'user_profile_update_errors', array( __CLASS__, 'check_profile_password' ), 10, 3 );
	}

	public static function check_reset_password( $errors, $user ) {
		$p = isset( $_POST['pass1'] ) ? (string) wp_unslash( $_POST['pass1'] ) : ''; // phpcs:ignore
		if ( '' !== $p && NWC::password_problem( $p ) ) {
			$errors->add( 'nwc_weak_password', NWC::password_problem( $p ) );
		}
	}

	public static function check_profile_password( $errors, $update, $user ) {
		if ( isset( $user->user_pass ) && '' !== (string) $user->user_pass && ! empty( $_POST['pass1'] ) ) { // phpcs:ignore
			$p = (string) wp_unslash( $_POST['pass1'] ); // phpcs:ignore
			if ( NWC::password_problem( $p ) ) {
				$errors->add( 'nwc_weak_password', NWC::password_problem( $p ) );
			}
		}
	}

	public static function body( WP_REST_Request $r ) {
		$b = json_decode( (string) $r->get_body(), true );
		return is_array( $b ) ? $b : array();
	}

	public static function bind_request( WP_REST_Request $r ) {
		if ( ! is_user_logged_in() ) {
			return NWC::err( 'Please sign in.', 401, 'nwc_auth' );
		}
		$id = absint( $r->get_header( 'x_nw_workspace' ) );
		if ( ! $id || ! NWC_Tenancy::bind( $id, get_current_user_id() ) ) {
			return NWC::err( 'Workspace not found.', 403, 'nwc_no_workspace' );
		}
		if ( 'agency' === NWC_Tenancy::$current->account_type ) {
			NWC_WorkDesk::refresh_if_stale( $id );
		}
		return true;
	}

	public static function route( $path, $methods, $cb, $level = 'workspace' ) {
		$perm = array( __CLASS__, 'bind_request' );
		if ( 'public' === $level ) {
			$perm = '__return_true';
		} elseif ( 'user' === $level ) {
			$perm = function () {
				return is_user_logged_in() ? true : NWC::err( 'Please sign in.', 401, 'nwc_auth' );
			};
		}
		register_rest_route( self::NS, $path, array(
			'methods'             => $methods,
			'permission_callback' => $perm,
			'callback'            => function ( WP_REST_Request $r ) use ( $cb ) {
				$res = call_user_func( $cb, $r );
				return is_wp_error( $res ) ? $res : rest_ensure_response( $res );
			},
		) );
	}

	public static function routes() {
		NWC_Connect::routes();
		self::route( '/auth/register', 'POST', array( __CLASS__, 'register' ), 'public' );
		self::route( '/auth/login', 'POST', array( __CLASS__, 'login' ), 'public' );
		self::route( '/auth/logout', 'POST', array( __CLASS__, 'logout' ), 'user' );
		self::route( '/me', 'GET', array( __CLASS__, 'me' ), 'user' );
		self::route( '/me/preferences', 'PUT', array( __CLASS__, 'save_prefs' ), 'user' );
		self::route( '/workspaces', 'POST', array( __CLASS__, 'create_workspace' ), 'user' );
		self::route( '/team-invite/accept', 'POST', array( __CLASS__, 'accept_team_invite' ), 'user' );
		self::route( '/bootstrap', 'GET', array( __CLASS__, 'bootstrap' ) );
		self::route( '/dashboard', 'GET', array( __CLASS__, 'dashboard' ) );
		self::route( '/workspace', 'PUT', array( __CLASS__, 'update_workspace' ) );
		self::route( '/team', 'GET', array( __CLASS__, 'team' ) );
		self::route( '/team/invite', 'POST', array( __CLASS__, 'team_invite' ) );
		self::route( '/team/invites/(?P<id>\d+)', 'DELETE', array( __CLASS__, 'revoke_invite' ) );
		self::route( '/team/(?P<id>\d+)', 'PUT', array( __CLASS__, 'team_update' ) );
		self::route( '/team/(?P<id>\d+)', 'DELETE', array( __CLASS__, 'team_remove' ) );
		self::route( '/notifications', 'GET', array( __CLASS__, 'notifications' ) );
		self::route( '/notifications/read', 'POST', array( __CLASS__, 'notifications_read' ) );
		self::route( '/audit', 'GET', array( __CLASS__, 'audit' ) );
		NWC_Resources::routes();
		NWC_Projects::routes();
		NWC_CRM::routes();
		NWC_Calendar::routes();
		NWC_Bookings::routes();
		NWC_Invoices::routes();
		NWC_Billing::routes();
		NWC_WorkDesk::routes();
		NWC_Signup::routes();
		NWC_Social::routes();
		NWC_Forms::routes();
		NWC_Daily::routes();
		NWC_Portal::routes();
		NWC_Finance::routes();
		NWC_Drive::routes();
		NWC_Retention::routes();
		NWC_Legal::routes();
		NWC_Personal::routes();
		NWC_Notify::routes();
		NWC_Industry::routes();
		NWC_POS::routes();
		NWC_Stays::routes();
		NWC_Checkins::routes();
		NWC_Attendance::routes();
		NWC_Convert::routes();
		NWC_Payouts::routes();
		NWC_Profile::routes();
	}

	/* ---------------------------- Authentication ---------------------------- */

	public static function on_login( $login, $user ) {
		global $wpdb;
		$wpdb->insert( NWC_DB::t( 'logins' ), array( 'user_id' => (int) $user->ID, 'login' => mb_substr( $login, 0, 190 ), 'success' => 1, 'ip' => NWC::ip(), 'user_agent' => isset( $_SERVER['HTTP_USER_AGENT'] ) ? mb_substr( sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ), 0, 255 ) : '', 'created_at' => NWC::now() ) );
	}

	public static function on_login_failed( $login ) {
		global $wpdb;
		$wpdb->insert( NWC_DB::t( 'logins' ), array( 'login' => mb_substr( (string) $login, 0, 190 ), 'success' => 0, 'ip' => NWC::ip(), 'created_at' => NWC::now() ) );
		set_transient( 'nwc_fail_' . md5( NWC::ip() . '|' . strtolower( (string) $login ) ), (int) get_transient( 'nwc_fail_' . md5( NWC::ip() . '|' . strtolower( (string) $login ) ) ) + 1, 15 * MINUTE_IN_SECONDS );
	}

	/** Applies to every sign-in path, including wp-login.php. */
	public static function brute_force( $user, $username ) {
		if ( '' !== (string) $username && (int) get_transient( 'nwc_fail_' . md5( NWC::ip() . '|' . strtolower( (string) $username ) ) ) >= 8 ) {
			return new WP_Error( 'nwc_locked', 'Too many failed attempts. Try again in 15 minutes.' );
		}
		return $user;
	}

	public static function register( WP_REST_Request $r ) {
		$b = self::body( $r );
		if ( ! empty( $b['website'] ) ) {
			return NWC::err( 'Could not create the account.' );
		}
		if ( ! NWC::rate_ok( 'register|' . NWC::ip(), 10, 3600 ) ) {
			return NWC::err( 'Too many attempts. Please try again later.', 429, 'nwc_rate_limited' );
		}
		$email = sanitize_email( (string) ( $b['email'] ?? '' ) );
		$pass  = (string) ( $b['password'] ?? '' );
		$name  = mb_substr( trim( sanitize_text_field( (string) ( $b['name'] ?? '' ) ) ), 0, 120 );
		if ( '' === $name || ! is_email( $email ) ) {
			return NWC::err( 'Enter your name and a valid email address.' );
		}
		$weak = NWC::password_problem( $pass );
		if ( $weak ) {
			return NWC::err( $weak );
		}
		if ( empty( $b['accept_legal'] ) ) {
			return NWC::err( 'Please accept the Privacy Policy and Terms & Conditions to continue.', 422, 'nwc_terms_required' );
		}
		if ( email_exists( $email ) ) {
			return NWC::err( 'An account with this email already exists. Please sign in.', 409, 'nwc_exists' );
		}
		$login = sanitize_user( strtok( $email, '@' ), true );
		$login = '' === $login ? 'user' : $login;
		$base  = $login;
		$n     = 1;
		while ( username_exists( $login ) ) {
			$login = $base . ( ++$n );
		}
		$id = wp_insert_user( array( 'user_login' => $login, 'user_email' => $email, 'user_pass' => $pass, 'display_name' => $name, 'first_name' => $name, 'role' => 'nwc_user' ) );
		if ( is_wp_error( $id ) ) {
			return NWC::err( 'Could not create the account.' );
		}
		wp_set_current_user( $id );
		wp_set_auth_cookie( $id, true );
		NWC::audit( 'account_created', array( 'user_id' => $id, 'workspace_id' => null, 'kind' => 'auth' ) );
		NWC_Legal::record_acceptance( $id, null, 'password' );
		if ( ! empty( $b['marketing_consent'] ) ) {
			NWC_Legal::record_marketing( $id, true, 'password' );
		}
		NWC_Signup::remember_plan( $id, (string) ( $b['plan'] ?? '' ), (string) ( $b['cycle'] ?? '' ) );
		NWC_Signup::send_email( $id, 'welcome', array() );
		return array( 'ok' => true );
	}

	public static function login( WP_REST_Request $r ) {
		$b = self::body( $r );
		if ( ! NWC::rate_ok( 'login|' . NWC::ip(), 30, 300 ) ) {
			return NWC::err( 'Too many attempts. Please try again later.', 429, 'nwc_rate_limited' );
		}
		$u = wp_signon( array( 'user_login' => sanitize_text_field( (string) ( $b['email'] ?? '' ) ), 'user_password' => (string) ( $b['password'] ?? '' ), 'remember' => ! empty( $b['remember'] ) ), is_ssl() );
		if ( is_wp_error( $u ) ) {
			return 'nwc_locked' === $u->get_error_code() ? NWC::err( $u->get_error_message(), 429, 'nwc_locked' ) : NWC::err( 'Incorrect email or password.', 401, 'nwc_bad_login' );
		}
		NWC_Signup::remember_plan( $u->ID, (string) ( $b['plan'] ?? '' ), (string) ( $b['cycle'] ?? '' ) );
		return array( 'ok' => true );
	}

	public static function logout() {
		wp_logout();
		return array( 'ok' => true );
	}

	/* ---------------------------- Account ---------------------------- */

	public static function me() {
		$u = wp_get_current_user();
		return array( 'id' => $u->ID, 'name' => $u->display_name, 'email' => NWC_Social::is_placeholder_email( $u->user_email ) ? '' : $u->user_email, 'avatar_url' => NWC_Profile::avatar_url( $u->ID ), 'personal' => NWC_Personal::summary( NWC_Personal::ensure( $u->ID ) ), 'personal_eligible' => NWC_Personal::eligible( $u->ID ), 'workspaces' => NWC_Tenancy::for_user( $u->ID ), 'prefs' => self::prefs( $u->ID ), 'selected_plan' => NWC_Signup::selected_plan( $u->ID ), 'onboarding' => NWC_Signup::onboarding_info( $u->ID ), 'legal_pending' => NWC_Legal::pending_for( $u->ID ), 'categories' => NWC_Catalog::for_app() );
	}

	private static function prefs( $uid ) {
		$p = get_user_meta( $uid, 'nwc_prefs', true );
		return is_array( $p ) ? $p : array();
	}

	/** Only known, plain keys are stored. */
	public static function save_prefs( WP_REST_Request $r ) {
		$b   = self::body( $r );
		$uid = get_current_user_id();
		$p   = self::prefs( $uid );
		if ( isset( $b['theme'] ) && in_array( $b['theme'], array( 'light', 'dark', 'system' ), true ) ) {
			$p['theme'] = $b['theme'];
		}
		if ( isset( $b['widgets'] ) && is_array( $b['widgets'] ) ) {
			$p['widgets'] = array_values( array_map( 'sanitize_key', array_slice( $b['widgets'], 0, 40 ) ) );
		}
		if ( isset( $b['hidden_widgets'] ) && is_array( $b['hidden_widgets'] ) ) {
			$p['hidden_widgets'] = array_values( array_map( 'sanitize_key', array_slice( $b['hidden_widgets'], 0, 40 ) ) );
		}
		if ( isset( $b['calendar_view'] ) ) {
			$p['calendar_view'] = sanitize_key( $b['calendar_view'] );
		}
		update_user_meta( $uid, 'nwc_prefs', $p );
		return $p;
	}

	public static function create_workspace( WP_REST_Request $r ) {
		$b = self::body( $r );
		if ( ! NWC::rate_ok( 'mkws|' . get_current_user_id(), 10, 3600 ) ) {
			return NWC::err( 'Too many attempts. Please try again later.', 429, 'nwc_rate_limited' );
		}
		unset( $b['account_type'] ); // Agency workspaces only come from a WorkDesk invitation.
		return NWC_Signup::create_workspace( $b, get_current_user_id() );
	}

	public static function accept_team_invite( WP_REST_Request $r ) {
		$id = NWC_Tenancy::accept_invite( (string) ( self::body( $r )['token'] ?? '' ), wp_get_current_user() );
		return is_wp_error( $id ) ? $id : array( 'workspace_id' => $id );
	}

	public static function update_workspace( WP_REST_Request $r ) {
		global $wpdb;
		if ( ! NWC_Tenancy::can( 'settings.manage' ) ) {
			return NWC::err( 'Your role does not allow this.', 403, 'nwc_forbidden' );
		}
		$b   = self::body( $r );
		$w   = NWC_Tenancy::$current;
		$row = array( 'updated_at' => NWC::now() );
		if ( isset( $b['name'] ) && '' !== trim( (string) $b['name'] ) ) {
			$row['name'] = mb_substr( sanitize_text_field( (string) $b['name'] ), 0, 190 );
		}
		if ( isset( $b['timezone'] ) && in_array( $b['timezone'], timezone_identifiers_list(), true ) ) {
			$row['timezone'] = $b['timezone'];
		}
		// Phase 5: essential platform processing is not an optional consent, so there is no sharing
		// switch any more. A "sharing_consent" value sent by an older app is ignored.
		$wpdb->update( NWC_DB::t( 'workspaces' ), $row, array( 'id' => $w->id ) );
		NWC_Sync::enqueue( $w->id, 'workspace', $w->id );
		return array( 'ok' => true );
	}

	public static function bootstrap() {
		global $wpdb;
		$w   = NWC_Tenancy::$current;
		$m   = NWC_Tenancy::$member;
		$u   = wp_get_current_user();
		$all = array();
		foreach ( NWC_Tenancy::PERMS as $perm_list ) {
			$all = array_merge( $all, $perm_list );
		}
		$perms = array();
		foreach ( array_unique( array_merge( $all, array( 'billing.manage' ) ) ) as $p ) {
			if ( '*' !== $p ) {
				$perms[ $p ] = NWC_Tenancy::can( $p );
			}
		}
		$business = NWC_Bookings::business( $w->id );
		$personal = NWC_Personal::is_personal( $w );
		return array(
			'user'      => array( 'id' => $u->ID, 'name' => $u->display_name, 'email' => $u->user_email, 'avatar_url' => NWC_Profile::avatar_url( $u->ID ) ),
			'workspace' => array( 'id' => (int) $w->id, 'uid' => $w->uid, 'name' => $personal ? 'Personal' : $w->name, 'category' => $business ? NWC_Catalog::category_key( $business->category ) : 'other', 'category_label' => $business ? NWC_Catalog::category_label( $business->category ) : '', 'slug' => $w->slug, 'account_type' => $w->account_type, 'kind' => $w->kind, 'timezone' => $w->timezone, 'currency' => $w->currency, 'sharing_consent_at' => $w->sharing_consent_at, 'gst_registered' => $business ? (bool) $business->gst_registered : false, 'state_code' => $business ? $business->state_code : '', 'logo_url' => $business && ! $personal ? (string) $business->logo_url : '', 'owned' => 'owner' === $m->role, 'managed_by_agency' => 'agency' === $w->account_type ),
			'role'      => $m->role,
			'perms'     => $perms,
			'features'  => NWC_Features::resolve( $w ),
			'workspaces' => NWC_Tenancy::for_user( $u->ID ),
			'personal'  => NWC_Personal::summary( NWC_Personal::ensure( $u->ID ) ),
			'members'   => self::member_list( false ),
			'agency'    => $personal ? null : NWC_WorkDesk::summary( $w->id ),
			'unread'    => NWC_Notify::unread( $u->ID ),
			'unread_here' => NWC_Notify::unread( $u->ID, $w->id ),
			'categories' => NWC_Catalog::for_app( $personal ? 0 : (int) $w->id ),
			'industry'  => $personal ? null : NWC_Industry::config( $w ),
			'notify_categories' => NWC_Notify::CATEGORIES,
			'prefs'     => self::prefs( $u->ID ),
			'roles'     => NWC_Tenancy::ROLES,
			'sharing_notice_version' => NWC::settings()['sharing_notice_ver'],
			'subscription_state' => $personal ? null : NWC_Signup::subscription_state( $w ),
		);
	}

	/* ---------------------------- Dashboard ---------------------------- */

	/** Each block appears only when the caller's role and the workspace's features allow it. */
	public static function dashboard() {
		global $wpdb;
		$w     = NWC_Tenancy::$current;
		$ws    = (int) $w->id;
		$uid   = get_current_user_id();
		$today = NWC::today( $w->timezone );
		$tz    = new DateTimeZone( $w->timezone );
		$start = ( new DateTimeImmutable( $today . ' 00:00:00', $tz ) )->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );
		$out   = array( 'today' => $today );

		if ( NWC_Features::allowed( 'projects' ) && NWC_Tenancy::can( 'projects.read' ) ) {
			$vals = array( $ws );
			$sql  = "workspace_id = %d AND deleted_at IS NULL AND status = 'active'";
			$ex   = NWC_Projects::scope_projects( $vals );
			$sql .= $ex ? ' AND ' . $ex : '';
			$out['projects'] = array_map( array( 'NWC_Projects', 'decorate_project' ), $wpdb->get_results( $wpdb->prepare( 'SELECT id, name, color, status FROM ' . NWC_DB::t( 'projects' ) . " WHERE $sql ORDER BY id DESC LIMIT 6", $vals ) ) ); // phpcs:ignore
			$vals = array( $ws );
			$sql  = "workspace_id = %d AND deleted_at IS NULL AND status <> 'done'";
			$ex   = NWC_Projects::scope_tasks( $vals );
			$sql .= $ex ? ' AND ' . $ex : '';
			$out['tasks'] = $wpdb->get_results( $wpdb->prepare( 'SELECT id, title, status, priority, due_date, project_id, assignee_id FROM ' . NWC_DB::t( 'tasks' ) . " WHERE $sql ORDER BY due_date IS NULL, due_date ASC LIMIT 8", $vals ) ); // phpcs:ignore
			$out['tasks_due_today'] = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . NWC_DB::t( 'tasks' ) . " WHERE $sql AND due_date = %s", array_merge( $vals, array( $today ) ) ) ); // phpcs:ignore
			$out['workload'] = NWC_Tenancy::can( 'team.read' ) && NWC_Tenancy::can( 'projects.manage' ) ? array_map( function ( $x ) {
				$usr = get_userdata( $x->assignee_id );
				return array( 'name' => $usr ? $usr->display_name : 'Former member', 'open' => (int) $x->n );
			}, $wpdb->get_results( $wpdb->prepare( 'SELECT assignee_id, COUNT(*) n FROM ' . NWC_DB::t( 'tasks' ) . " WHERE workspace_id = %d AND deleted_at IS NULL AND status <> 'done' AND assignee_id IS NOT NULL GROUP BY assignee_id ORDER BY n DESC LIMIT 8", $ws ) ) ) : array(); // phpcs:ignore
		}
		if ( NWC_Features::allowed( 'bookings' ) && NWC_Tenancy::can( 'bookings.read' ) ) {
			$out['bookings'] = $wpdb->get_results( $wpdb->prepare( 'SELECT b.id, b.starts_at, b.customer_name, b.status, s.name AS service_name FROM ' . NWC_DB::t( 'bookings' ) . ' b LEFT JOIN ' . NWC_DB::t( 'services' ) . " s ON s.id = b.service_id WHERE b.workspace_id = %d AND b.deleted_at IS NULL AND b.status IN ('pending','confirmed') AND b.starts_at >= %s ORDER BY b.starts_at ASC LIMIT 6", $ws, $start ) ); // phpcs:ignore
			$out['bookings_count'] = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . NWC_DB::t( 'bookings' ) . " WHERE workspace_id = %d AND deleted_at IS NULL AND status IN ('pending','confirmed') AND starts_at >= %s", $ws, $start ) ); // phpcs:ignore
		}
		if ( NWC_Features::allowed( 'crm' ) && NWC_Tenancy::can( 'crm.read' ) ) {
			$out['leads'] = $wpdb->get_results( $wpdb->prepare( 'SELECT id, name, enquiry, stage, source, follow_up_on, created_at FROM ' . NWC_DB::t( 'leads' ) . " WHERE workspace_id = %d AND deleted_at IS NULL AND stage = 'new' ORDER BY id DESC LIMIT 6", $ws ) ); // phpcs:ignore
			$out['leads_count'] = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . NWC_DB::t( 'leads' ) . " WHERE workspace_id = %d AND deleted_at IS NULL AND stage = 'new'", $ws ) ); // phpcs:ignore
		}
		if ( NWC_Features::allowed( 'invoice_management' ) && NWC_Tenancy::can( 'invoices.read' ) ) {
			$t    = NWC_DB::t( 'biz_invoices' );
			$out['revenue'] = array(
				'outstanding'   => (float) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(SUM(grand_total - amount_paid),0) FROM $t WHERE workspace_id = %d AND status IN ('sent','partially_paid')", $ws ) ), // phpcs:ignore
				'overdue_count' => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $t WHERE workspace_id = %d AND status IN ('sent','partially_paid') AND due_date < %s", $ws, $today ) ), // phpcs:ignore
				'received_month' => (float) $wpdb->get_var( $wpdb->prepare( 'SELECT COALESCE(SUM(amount),0) FROM ' . NWC_DB::t( 'biz_payments' ) . ' WHERE workspace_id = %d AND ' . NWC_Finance::REAL_PAYMENT . ' AND paid_on >= %s', $ws, substr( $today, 0, 7 ) . '-01' ) ), // phpcs:ignore
			);
		}
		$out['goals'] = $wpdb->get_results( $wpdb->prepare( 'SELECT id, title, progress, target_date FROM ' . NWC_DB::t( 'goals' ) . " WHERE workspace_id = %d AND user_id = %d AND status = 'active' AND deleted_at IS NULL ORDER BY id DESC LIMIT 5", $ws, $uid ) ); // phpcs:ignore
		$out['notifications'] = $wpdb->get_results( $wpdb->prepare( 'SELECT id, title, body, link, read_at, created_at FROM ' . NWC_DB::t( 'notifications' ) . ' WHERE workspace_id = %d AND user_id = %d ORDER BY id DESC LIMIT 6', $ws, $uid ) ); // phpcs:ignore
		if ( NWC_WorkDesk::link( $ws ) && NWC_Features::allowed( 'agency_connect' ) && NWC_Tenancy::can( 'agency.use' ) ) {
			$a = NWC_WorkDesk::cached( $ws, 'GET', '/overview' );
			$out['agency'] = is_wp_error( $a ) ? array( 'error' => NWC_WorkDesk::CONNECT_UNAVAILABLE ) : $a;
			$out['agency_updates'] = $wpdb->get_results( $wpdb->prepare( 'SELECT id, title, body, link, created_at FROM ' . NWC_DB::t( 'notifications' ) . ' WHERE workspace_id = %d AND user_id = %d AND link LIKE %s ORDER BY id DESC LIMIT 5', $ws, $uid, $wpdb->esc_like( '#/agency' ) . '%' ) ); // phpcs:ignore
		}
		return $out;
	}

	/* ---------------------------- Team ---------------------------- */

	private static function member_list( $detail ) {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . NWC_DB::t( 'members' ) . " WHERE workspace_id = %d AND status = 'active'", NWC_Tenancy::$current->id ) ); // phpcs:ignore
		$out  = array();
		foreach ( $rows as $m ) {
			$u = get_userdata( $m->user_id );
			if ( ! $u ) {
				continue;
			}
			$o = array( 'id' => (int) $m->user_id, 'name' => $u->display_name, 'role' => $m->role, 'job_title' => $m->job_title, 'avatar_url' => NWC_Profile::avatar_url( $m->user_id ) );
			if ( $detail ) {
				$o += array( 'email' => $u->user_email, 'phone' => $m->phone, 'joined_at' => $m->joined_at, 'hours' => json_decode( (string) $m->hours, true ), 'breaks' => json_decode( (string) $m->breaks, true ) );
			}
			$out[] = $o;
		}
		return $out;
	}

	public static function team() {
		global $wpdb;
		if ( ! NWC_Tenancy::can( 'team.read' ) ) {
			return NWC::err( 'Your role does not allow this.', 403, 'nwc_forbidden' );
		}
		$out = array( 'members' => self::member_list( NWC_Tenancy::can( 'team.manage' ) ) );
		if ( NWC_Tenancy::can( 'team.manage' ) ) {
			$out['invites']  = $wpdb->get_results( $wpdb->prepare( 'SELECT id, email, role, expires_at FROM ' . NWC_DB::t( 'invites' ) . ' WHERE workspace_id = %d AND accepted_at IS NULL AND revoked_at IS NULL AND expires_at > %s', NWC_Tenancy::$current->id, NWC::now() ) ); // phpcs:ignore
			$out['activity'] = $wpdb->get_results( $wpdb->prepare( 'SELECT user_id, action, created_at FROM ' . NWC_DB::t( 'audit' ) . " WHERE workspace_id = %d AND user_id > 0 AND kind = 'activity' ORDER BY id DESC LIMIT 30", NWC_Tenancy::$current->id ) ); // phpcs:ignore
		}
		return $out;
	}

	public static function team_invite( WP_REST_Request $r ) {
		if ( ! NWC_Tenancy::can( 'team.manage' ) ) {
			return NWC::err( 'Your role does not allow this.', 403, 'nwc_forbidden' );
		}
		$g = NWC_Features::guard( 'team_management' );
		if ( is_wp_error( $g ) ) {
			return $g;
		}
		global $wpdb;
		$count = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . NWC_DB::t( 'members' ) . " WHERE workspace_id = %d AND status = 'active'", NWC_Tenancy::$current->id ) ); // phpcs:ignore
		$lim   = NWC_Features::check_limit( 'team_management', $count );
		if ( is_wp_error( $lim ) ) {
			return $lim;
		}
		$b = self::body( $r );
		return NWC_Tenancy::invite( $b['email'] ?? '', (string) ( $b['role'] ?? 'member' ), get_current_user_id() );
	}

	public static function revoke_invite( WP_REST_Request $r ) {
		global $wpdb;
		if ( ! NWC_Tenancy::can( 'team.manage' ) ) {
			return NWC::err( 'Your role does not allow this.', 403, 'nwc_forbidden' );
		}
		$wpdb->update( NWC_DB::t( 'invites' ), array( 'revoked_at' => NWC::now() ), array( 'id' => (int) $r['id'], 'workspace_id' => NWC_Tenancy::$current->id ) );
		return array( 'ok' => true );
	}

	/** Role rules: nobody grants more than they hold, and the owner role cannot be moved here. */
	public static function team_update( WP_REST_Request $r ) {
		global $wpdb;
		if ( ! NWC_Tenancy::can( 'team.manage' ) ) {
			return NWC::err( 'Your role does not allow this.', 403, 'nwc_forbidden' );
		}
		$uid = (int) $r['id'];
		$m   = NWC_Tenancy::member_row( NWC_Tenancy::$current->id, $uid );
		if ( ! $m ) {
			return NWC::err( 'Member not found.', 404, 'nwc_not_found' );
		}
		$b   = self::body( $r );
		$row = array();
		if ( isset( $b['role'] ) ) {
			if ( ! isset( NWC_Tenancy::ROLES[ $b['role'] ] ) || 'owner' === $b['role'] ) {
				return NWC::err( 'Choose a valid role.' );
			}
			if ( 'owner' === $m->role ) {
				return NWC::err( 'The owner\'s role cannot be changed here.', 403, 'nwc_forbidden' );
			}
			if ( ( 'admin' === $b['role'] || 'admin' === $m->role ) && 'owner' !== NWC_Tenancy::$member->role ) {
				return NWC::err( 'Only the owner can change admins.', 403, 'nwc_forbidden' );
			}
			$row['role'] = $b['role'];
		}
		if ( isset( $b['job_title'] ) ) {
			$row['job_title'] = mb_substr( sanitize_text_field( (string) $b['job_title'] ), 0, 120 );
		}
		if ( isset( $b['phone'] ) ) {
			$row['phone'] = mb_substr( sanitize_text_field( (string) $b['phone'] ), 0, 40 );
		}
		if ( $row ) {
			$wpdb->update( NWC_DB::t( 'members' ), $row, array( 'id' => $m->id ) );
			NWC::audit( 'member_updated', array( 'kind' => 'admin', 'entity' => 'member', 'entity_id' => $uid, 'details' => $row ) );
		}
		return array( 'ok' => true );
	}

	public static function team_remove( WP_REST_Request $r ) {
		global $wpdb;
		if ( ! NWC_Tenancy::can( 'team.manage' ) ) {
			return NWC::err( 'Your role does not allow this.', 403, 'nwc_forbidden' );
		}
		$uid = (int) $r['id'];
		$m   = NWC_Tenancy::member_row( NWC_Tenancy::$current->id, $uid );
		if ( ! $m ) {
			return NWC::err( 'Member not found.', 404, 'nwc_not_found' );
		}
		if ( 'owner' === $m->role || ( 'admin' === $m->role && 'owner' !== NWC_Tenancy::$member->role ) ) {
			return NWC::err( 'You cannot remove this member.', 403, 'nwc_forbidden' );
		}
		$wpdb->update( NWC_DB::t( 'members' ), array( 'status' => 'removed' ), array( 'id' => $m->id ) );
		$wpdb->delete( NWC_DB::t( 'project_members' ), array( 'workspace_id' => NWC_Tenancy::$current->id, 'user_id' => $uid ) );
		NWC::audit( 'member_removed', array( 'kind' => 'admin', 'entity' => 'member', 'entity_id' => $uid ) );
		return array( 'ok' => true );
	}

	/* ---------------------------- Notifications, audit ---------------------------- */

	public static function notifications() {
		global $wpdb;
		return array( 'items' => $wpdb->get_results( $wpdb->prepare( 'SELECT id, kind, title, body, link, read_at, created_at FROM ' . NWC_DB::t( 'notifications' ) . ' WHERE workspace_id = %d AND user_id = %d ORDER BY id DESC LIMIT 100', NWC_Tenancy::$current->id, get_current_user_id() ) ) ); // phpcs:ignore
	}

	public static function notifications_read( WP_REST_Request $r ) {
		global $wpdb;
		$b = self::body( $r );
		$t = NWC_DB::t( 'notifications' );
		if ( ! empty( $b['id'] ) ) {
			$wpdb->update( $t, array( 'read_at' => NWC::now() ), array( 'id' => absint( $b['id'] ), 'user_id' => get_current_user_id(), 'workspace_id' => NWC_Tenancy::$current->id ) );
		} else {
			$wpdb->query( $wpdb->prepare( "UPDATE $t SET read_at = %s WHERE user_id = %d AND workspace_id = %d AND read_at IS NULL", NWC::now(), get_current_user_id(), NWC_Tenancy::$current->id ) ); // phpcs:ignore
		}
		return array( 'ok' => true );
	}

	public static function audit( WP_REST_Request $r ) {
		global $wpdb;
		if ( ! NWC_Tenancy::can( 'audit.read' ) ) {
			return NWC::err( 'Your role does not allow this.', 403, 'nwc_forbidden' );
		}
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT user_id, kind, source, action, entity, entity_id, details, ip, created_at FROM ' . NWC_DB::t( 'audit' ) . " WHERE workspace_id = %d AND kind <> 'activity' ORDER BY id DESC LIMIT 200", NWC_Tenancy::$current->id ) ); // phpcs:ignore
		foreach ( $rows as $a ) {
			$u      = $a->user_id ? get_userdata( $a->user_id ) : null;
			$a->who = $u ? $u->display_name : 'System';
		}
		return array( 'items' => $rows );
	}
}
