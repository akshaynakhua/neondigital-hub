<?php
/**
 * Sign-in with Google and Facebook, plus email and password. These are the only providers.
 *
 * Google: OpenID Connect authorization code flow with state, nonce and PKCE. The ID token comes
 * straight from Google's token endpoint over TLS, and its claims (iss, aud, exp, nonce) are checked.
 * Facebook: authorization code flow with state and PKCE, server-side exchange, the token's app and
 * user confirmed by debug_token, and appsecret_proof on the profile call.
 *
 * Tokens are used once on the server and dropped; only provider + subject id are stored. Two accounts
 * are never merged because of a matching email: linking needs a signed-in user who started the link.
 */

defined( 'ABSPATH' ) || exit;

class NWC_Social {

	const PROVIDERS   = array( 'google', 'facebook' );
	const STATE_TTL   = 600;
	const STATE_COOKIE = 'nwc_oauth_bind';

	public static function init() {
		add_action( 'nwc_social_login', array( __CLASS__, 'log_login' ), 10, 2 );
	}

	public static function routes() {
		NWC_REST::route( '/me/identities', 'GET', array( __CLASS__, 'list_route' ), 'user' );
		NWC_REST::route( '/me/identities/(?P<provider>google|facebook)', 'DELETE', array( __CLASS__, 'unlink_route' ), 'user' );
		NWC_REST::route( '/me/password', 'PUT', array( __CLASS__, 'password_route' ), 'user' );
		NWC_REST::route( '/me/logins', 'GET', array( __CLASS__, 'logins_route' ), 'user' );
	}

	/* ---------------------------- Configuration ---------------------------- */

	public static function config( $provider ) {
		$s = NWC::settings();
		if ( 'google' === $provider ) {
			return array( 'enabled' => (bool) $s['google_enabled'], 'id' => (string) $s['google_client_id'], 'secret' => '' !== (string) $s['google_client_secret'] ? (string) NWC::unseal( $s['google_client_secret'] ) : '' );
		}
		return array( 'enabled' => (bool) $s['facebook_enabled'], 'id' => (string) $s['facebook_app_id'], 'secret' => '' !== (string) $s['facebook_app_secret'] ? (string) NWC::unseal( $s['facebook_app_secret'] ) : '', 'version' => preg_match( '/^v\d+\.\d+$/', (string) $s['facebook_api_version'] ) ? $s['facebook_api_version'] : 'v21.0' );
	}

	/** A provider shows on the sign-in screens only when it is switched on and has credentials. */
	public static function enabled_providers() {
		$out = array();
		foreach ( self::PROVIDERS as $p ) {
			$c = self::config( $p );
			if ( $c['enabled'] && '' !== $c['id'] && '' !== $c['secret'] ) {
				$out[] = $p;
			}
		}
		return $out;
	}

	public static function callback_url( $provider ) {
		return home_url( '/auth/' . $provider . '/callback/' );
	}

	public static function error_text( $code ) {
		$map = array(
			'provider_off'   => 'That sign-in option is not available right now.',
			'state'          => 'That sign-in attempt expired. Please try again.',
			'denied'         => 'Sign-in was cancelled.',
			'exchange'       => 'We could not confirm your identity with the provider. Please try again.',
			'unverified'     => 'Your email address is not verified with the provider.',
			'email_exists'   => 'An account already uses this email. Sign in with your password, then link this login from Settings.',
			'linked_other'   => 'That login is already linked to a different account.',
			'already_linked' => 'Your account already has a login linked with this provider.',
			'choose_plan'    => 'Please choose a plan to create your account.',
			'rate'           => 'Too many attempts. Please try again later.',
		);
		return isset( $map[ $code ] ) ? $map[ $code ] : '';
	}

	/** Facebook may not give an email. Such accounts hold this until the user adds a real one. */
	public static function is_placeholder_email( $email ) {
		return (bool) preg_match( '/@social-login\.invalid$/', (string) $email );
	}

	/* ---------------------------- Flow ---------------------------- */

	public static function dispatch( $provider, $step ) {
		nocache_headers();
		header( 'X-Content-Type-Options: nosniff' );
		header( 'Referrer-Policy: no-referrer' );
		if ( 'start' === $step ) {
			self::redirect( self::start( $provider, $_GET ) ); // phpcs:ignore
		}
		self::redirect( self::callback( $provider, $_GET ) ); // phpcs:ignore
	}

	private static function redirect( $url ) {
		if ( defined( 'NWC_TESTING' ) ) {
			return;
		}
		wp_redirect( $url ); // phpcs:ignore -- Provider URLs are built here, and not user-supplied.
		exit;
	}

	private static function fail_url( $code, $intent = 'login' ) {
		return 'link' === $intent ? home_url( '/app/#/settings?auth_error=' . $code ) : add_query_arg( 'auth_error', $code, home_url( '/login/' ) );
	}

	/** @param array $in intent=login|register|link, plan, cycle. @return string URL to send the browser to. */
	public static function start( $provider, $in ) {
		$c = self::config( $provider );
		if ( ! in_array( $provider, self::enabled_providers(), true ) ) {
			return self::fail_url( 'provider_off' );
		}
		if ( ! NWC::rate_ok( 'oauth|' . NWC::ip(), 40, 300 ) ) {
			return self::fail_url( 'rate' );
		}
		$intent = isset( $in['intent'] ) && in_array( $in['intent'], array( 'login', 'register', 'link' ), true ) ? $in['intent'] : 'login';
		if ( 'link' === $intent && ! is_user_logged_in() ) {
			return self::fail_url( 'state' );
		}
		$state    = rtrim( strtr( base64_encode( random_bytes( 32 ) ), '+/', '-_' ), '=' ); // phpcs:ignore
		$nonce    = bin2hex( random_bytes( 16 ) );
		$verifier = rtrim( strtr( base64_encode( random_bytes( 48 ) ), '+/', '-_' ), '=' ); // phpcs:ignore
		$bind     = bin2hex( random_bytes( 16 ) );
		$plan     = sanitize_key( (string) ( $in['plan'] ?? '' ) );
		set_transient( 'nwc_oauth_' . hash( 'sha256', $state ), array(
			'provider' => $provider,
			'intent'   => $intent,
			'nonce'    => $nonce,
			'verifier' => $verifier,
			'bind'     => hash( 'sha256', $bind ),
			'user'     => 'link' === $intent ? get_current_user_id() : 0,
			'plan'     => $plan,
			'next'     => self::invite_path( (string) ( $in['next'] ?? '' ) ),
			'cycle'    => isset( $in['cycle'] ) && 'year' === $in['cycle'] ? 'year' : 'month',
		), self::STATE_TTL );
		// Ties the state to this browser, so a state value alone cannot complete someone else's flow.
		if ( ! headers_sent() && ! defined( 'NWC_TESTING' ) ) {
			setcookie( self::STATE_COOKIE, $bind, array( 'expires' => time() + self::STATE_TTL, 'path' => COOKIEPATH ? COOKIEPATH : '/', 'secure' => is_ssl(), 'httponly' => true, 'samesite' => 'Lax' ) );
		}
		$GLOBALS['nwc_oauth_test_bind'] = $bind;
		$challenge = rtrim( strtr( base64_encode( hash( 'sha256', $verifier, true ) ), '+/', '-_' ), '=' ); // phpcs:ignore
		if ( 'google' === $provider ) {
			return 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query( array( 'client_id' => $c['id'], 'redirect_uri' => self::callback_url( 'google' ), 'response_type' => 'code', 'scope' => 'openid email profile', 'state' => $state, 'nonce' => $nonce, 'code_challenge' => $challenge, 'code_challenge_method' => 'S256', 'prompt' => 'select_account' ), '', '&', PHP_QUERY_RFC3986 );
		}
		return 'https://www.facebook.com/' . $c['version'] . '/dialog/oauth?' . http_build_query( array( 'client_id' => $c['id'], 'redirect_uri' => self::callback_url( 'facebook' ), 'response_type' => 'code', 'scope' => 'public_profile,email', 'state' => $state, 'code_challenge' => $challenge, 'code_challenge_method' => 'S256' ), '', '&', PHP_QUERY_RFC3986 );
	}

	/** @return string URL to send the browser to. */
	public static function callback( $provider, $in, $bind_override = null ) {
		$state_raw = isset( $in['state'] ) ? (string) $in['state'] : '';
		$key       = 'nwc_oauth_' . hash( 'sha256', $state_raw );
		$st        = '' !== $state_raw ? get_transient( $key ) : false;
		if ( ! $st || $st['provider'] !== $provider ) {
			self::log_failure( $provider, 'state' );
			return self::fail_url( 'state' );
		}
		delete_transient( $key ); // A state works once.
		$bind = null !== $bind_override ? $bind_override : ( isset( $_COOKIE[ self::STATE_COOKIE ] ) ? sanitize_text_field( wp_unslash( $_COOKIE[ self::STATE_COOKIE ] ) ) : '' );
		if ( ! hash_equals( $st['bind'], hash( 'sha256', (string) $bind ) ) ) {
			self::log_failure( $provider, 'browser' );
			return self::fail_url( 'state', $st['intent'] );
		}
		if ( ! empty( $in['error'] ) || empty( $in['code'] ) ) {
			return self::fail_url( 'denied', $st['intent'] );
		}
		$identity = 'google' === $provider ? self::google_identity( (string) $in['code'], $st ) : self::facebook_identity( (string) $in['code'], $st );
		if ( is_wp_error( $identity ) ) {
			self::log_failure( $provider, $identity->get_error_code() );
			return self::fail_url( $identity->get_error_code(), $st['intent'] );
		}
		return self::resolve_identity( $provider, $identity, $st );
	}

	private static function http_error() {
		return new WP_Error( 'exchange', 'exchange' );
	}

	private static function decode_jwt_claims( $jwt ) {
		$p = explode( '.', (string) $jwt );
		if ( 3 !== count( $p ) ) {
			return null;
		}
		$c = json_decode( (string) base64_decode( strtr( $p[1], '-_', '+/' ) ), true ); // phpcs:ignore
		return is_array( $c ) ? $c : null;
	}

	private static function google_identity( $code, $st ) {
		$c   = self::config( 'google' );
		$res = wp_remote_post( 'https://oauth2.googleapis.com/token', array( 'timeout' => 15, 'body' => array( 'code' => $code, 'client_id' => $c['id'], 'client_secret' => $c['secret'], 'redirect_uri' => self::callback_url( 'google' ), 'grant_type' => 'authorization_code', 'code_verifier' => $st['verifier'] ) ) );
		if ( is_wp_error( $res ) || 200 !== (int) wp_remote_retrieve_response_code( $res ) ) {
			return self::http_error();
		}
		$tok = json_decode( (string) wp_remote_retrieve_body( $res ), true );
		$cl  = self::decode_jwt_claims( $tok['id_token'] ?? '' );
		if ( ! $cl || ! in_array( $cl['iss'] ?? '', array( 'https://accounts.google.com', 'accounts.google.com' ), true ) || ( $cl['aud'] ?? '' ) !== $c['id'] || (int) ( $cl['exp'] ?? 0 ) < time() || ! hash_equals( $st['nonce'], (string) ( $cl['nonce'] ?? '' ) ) || empty( $cl['sub'] ) ) {
			return self::http_error();
		}
		if ( empty( $cl['email'] ) || empty( $cl['email_verified'] ) ) {
			return new WP_Error( 'unverified', 'unverified' );
		}
		return array( 'subject' => (string) $cl['sub'], 'email' => sanitize_email( $cl['email'] ), 'name' => sanitize_text_field( (string) ( $cl['name'] ?? '' ) ) );
	}

	private static function facebook_identity( $code, $st ) {
		$c    = self::config( 'facebook' );
		$base = 'https://graph.facebook.com/' . $c['version'];
		$res  = wp_remote_get( add_query_arg( array( 'client_id' => $c['id'], 'client_secret' => $c['secret'], 'redirect_uri' => rawurlencode( self::callback_url( 'facebook' ) ), 'code' => rawurlencode( $code ), 'code_verifier' => $st['verifier'] ), $base . '/oauth/access_token' ), array( 'timeout' => 15 ) );
		if ( is_wp_error( $res ) || 200 !== (int) wp_remote_retrieve_response_code( $res ) ) {
			return self::http_error();
		}
		$token = (string) ( json_decode( (string) wp_remote_retrieve_body( $res ), true )['access_token'] ?? '' );
		if ( '' === $token ) {
			return self::http_error();
		}
		$dbg = wp_remote_get( add_query_arg( array( 'input_token' => rawurlencode( $token ), 'access_token' => rawurlencode( $c['id'] . '|' . $c['secret'] ) ), $base . '/debug_token' ), array( 'timeout' => 15 ) );
		$d   = is_wp_error( $dbg ) ? array() : (array) ( json_decode( (string) wp_remote_retrieve_body( $dbg ), true )['data'] ?? array() );
		if ( empty( $d['is_valid'] ) || (string) ( $d['app_id'] ?? '' ) !== $c['id'] || empty( $d['user_id'] ) ) {
			return self::http_error();
		}
		$me = wp_remote_get( add_query_arg( array( 'fields' => 'id,name,email', 'access_token' => rawurlencode( $token ), 'appsecret_proof' => hash_hmac( 'sha256', $token, $c['secret'] ) ), $base . '/me' ), array( 'timeout' => 15 ) );
		$u  = is_wp_error( $me ) ? array() : (array) json_decode( (string) wp_remote_retrieve_body( $me ), true );
		if ( empty( $u['id'] ) || (string) $u['id'] !== (string) $d['user_id'] ) {
			return self::http_error();
		}
		return array( 'subject' => (string) $u['id'], 'email' => sanitize_email( (string) ( $u['email'] ?? '' ) ), 'name' => sanitize_text_field( (string) ( $u['name'] ?? '' ) ) );
	}

	/** Decides sign-in, sign-up or link. Same email alone never joins two accounts. */
	private static function resolve_identity( $provider, $id, $st ) {
		global $wpdb;
		$t   = NWC_DB::t( 'identities' );
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $t WHERE provider = %s AND subject = %s", $provider, $id['subject'] ) ); // phpcs:ignore

		if ( 'link' === $st['intent'] ) {
			$uid = (int) $st['user'];
			if ( ! $uid || get_current_user_id() !== $uid ) {
				return self::fail_url( 'state', 'link' );
			}
			if ( $row ) {
				return (int) $row->user_id === $uid ? home_url( '/app/#/settings' ) : self::fail_url( 'linked_other', 'link' );
			}
			if ( $wpdb->get_var( $wpdb->prepare( "SELECT id FROM $t WHERE user_id = %d AND provider = %s", $uid, $provider ) ) ) { // phpcs:ignore
				return self::fail_url( 'already_linked', 'link' );
			}
			self::attach( $uid, $provider, $id );
			NWC::audit( 'login_linked', array( 'workspace_id' => null, 'kind' => 'auth', 'user_id' => $uid, 'details' => array( 'provider' => $provider ) ) );
			return home_url( '/app/#/settings' );
		}

		if ( $row ) {
			$u = get_userdata( (int) $row->user_id );
			if ( ! $u ) {
				return self::fail_url( 'exchange' );
			}
			$wpdb->update( $t, array( 'last_used_at' => NWC::now() ), array( 'id' => $row->id ) );
			NWC_Signup::remember_plan( $u->ID, $st['plan'], $st['cycle'] );
			return self::sign_in( $u, $provider, $st['next'] ?? '' );
		}

		if ( '' !== $id['email'] && email_exists( $id['email'] ) ) {
			self::log_failure( $provider, 'email_exists' );
			return self::fail_url( 'email_exists' );
		}
		// A brand-new account starts from the pricing page, with the plan carried through this flow.
		// An invitation is its own reason to create an account.
		if ( ! NWC_Signup::public_plan( $st['plan'] ) && empty( $st['next'] ) ) {
			return add_query_arg( 'auth_error', 'choose_plan', home_url( '/pricing/' ) );
		}
		$uid = self::create_user( $provider, $id );
		if ( ! $uid ) {
			return self::fail_url( 'exchange' );
		}
		NWC_Signup::remember_plan( $uid, $st['plan'], $st['cycle'] );
		NWC_Signup::send_email( $uid, 'welcome', array() );
		return self::sign_in( get_userdata( $uid ), $provider, $st['next'] ?? '' );
	}

	private static function attach( $uid, $provider, $id ) {
		global $wpdb;
		$wpdb->insert( NWC_DB::t( 'identities' ), array( 'user_id' => (int) $uid, 'provider' => $provider, 'subject' => $id['subject'], 'email' => mb_substr( $id['email'], 0, 190 ), 'created_at' => NWC::now(), 'last_used_at' => NWC::now() ) );
	}

	private static function create_user( $provider, $id ) {
		$email = '' !== $id['email'] ? $id['email'] : 'u' . hash( 'sha256', $provider . '|' . $id['subject'] ) . '@social-login.invalid';
		$base  = sanitize_user( '' !== $id['email'] ? strtok( $id['email'], '@' ) : $provider . '_user', true );
		$base  = '' === $base ? 'user' : $base;
		$login = $base;
		$n     = 1;
		while ( username_exists( $login ) ) {
			$login = $base . ( ++$n );
		}
		$name = '' !== $id['name'] ? $id['name'] : 'New user';
		$uid  = wp_insert_user( array( 'user_login' => $login, 'user_email' => $email, 'user_pass' => wp_generate_password( 40, true, true ), 'display_name' => $name, 'first_name' => $name, 'role' => 'nwc_user' ) );
		if ( is_wp_error( $uid ) ) {
			return 0;
		}
		update_user_meta( $uid, 'nwc_no_password', 1 );
		self::attach( $uid, $provider, $id );
		NWC::audit( 'account_created', array( 'workspace_id' => null, 'kind' => 'auth', 'user_id' => $uid, 'source' => $provider ) );
		return (int) $uid;
	}

	/** The only places a social sign-in may return to, other than the app. */
	private static function invite_path( $path ) {
		return preg_match( '#^/(join|team-invite)/[A-Za-z0-9_-]+$#', $path ) ? $path : '';
	}

	private static function sign_in( $u, $provider, $next = '' ) {
		wp_set_current_user( $u->ID );
		if ( ! defined( 'NWC_TESTING' ) ) {
			wp_set_auth_cookie( $u->ID, true, is_ssl() );
		}
		do_action( 'nwc_social_login', $u, $provider );
		return '' !== self::invite_path( (string) $next ) ? home_url( $next ) : home_url( '/app/' );
	}

	public static function log_login( $u, $provider ) {
		global $wpdb;
		$wpdb->insert( NWC_DB::t( 'logins' ), array( 'user_id' => (int) $u->ID, 'login' => $provider, 'method' => $provider, 'success' => 1, 'ip' => NWC::ip(), 'user_agent' => isset( $_SERVER['HTTP_USER_AGENT'] ) ? mb_substr( sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ), 0, 255 ) : '', 'created_at' => NWC::now() ) );
	}

	private static function log_failure( $provider, $reason ) {
		global $wpdb;
		$wpdb->insert( NWC_DB::t( 'logins' ), array( 'login' => mb_substr( $provider . ':' . $reason, 0, 190 ), 'method' => $provider, 'success' => 0, 'ip' => NWC::ip(), 'created_at' => NWC::now() ) );
	}

	/* ---------------------------- Linked accounts ---------------------------- */

	private static function has_password( $uid ) {
		return ! get_user_meta( $uid, 'nwc_no_password', true );
	}

	public static function list_route() {
		global $wpdb;
		$uid  = get_current_user_id();
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT provider, email, created_at, last_used_at FROM ' . NWC_DB::t( 'identities' ) . ' WHERE user_id = %d', $uid ) ); // phpcs:ignore
		return array( 'items' => $rows, 'has_password' => self::has_password( $uid ), 'available' => self::enabled_providers() );
	}

	/** The user's last way in can never be removed. */
	public static function unlink_route( WP_REST_Request $r ) {
		global $wpdb;
		$uid = get_current_user_id();
		$t   = NWC_DB::t( 'identities' );
		$n   = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $t WHERE user_id = %d", $uid ) ); // phpcs:ignore
		$has = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $t WHERE user_id = %d AND provider = %s", $uid, $r['provider'] ) ); // phpcs:ignore
		if ( ! $has ) {
			return NWC::err( 'That login is not linked.', 404, 'nwc_not_found' );
		}
		if ( 1 === $n && ! self::has_password( $uid ) ) {
			return NWC::err( 'This is your only way to sign in. Set a password or link another login first.', 409, 'nwc_last_login' );
		}
		$wpdb->delete( $t, array( 'user_id' => $uid, 'provider' => $r['provider'] ) );
		NWC::audit( 'login_unlinked', array( 'workspace_id' => null, 'kind' => 'auth', 'user_id' => $uid, 'details' => array( 'provider' => $r['provider'] ) ) );
		return array( 'ok' => true );
	}

	public static function password_route( WP_REST_Request $r ) {
		$b   = NWC_REST::body( $r );
		$uid = get_current_user_id();
		$u   = get_userdata( $uid );
		$new = (string) ( $b['password'] ?? '' );
		$weak = NWC::password_problem( $new );
		if ( $weak ) {
			return NWC::err( $weak );
		}
		if ( self::has_password( $uid ) && ! wp_check_password( (string) ( $b['current'] ?? '' ), $u->user_pass, $uid ) ) {
			return NWC::err( 'Your current password is not correct.', 403, 'nwc_forbidden' );
		}
		wp_set_password( $new, $uid );
		delete_user_meta( $uid, 'nwc_no_password' );
		if ( ! defined( 'NWC_TESTING' ) ) {
			wp_set_auth_cookie( $uid, true, is_ssl() ); // wp_set_password ended the session.
		}
		NWC::audit( 'password_changed', array( 'workspace_id' => null, 'kind' => 'auth', 'user_id' => $uid ) );
		return array( 'ok' => true );
	}

	public static function logins_route() {
		global $wpdb;
		return array( 'items' => $wpdb->get_results( $wpdb->prepare( 'SELECT method, success, ip, created_at FROM ' . NWC_DB::t( 'logins' ) . ' WHERE user_id = %d ORDER BY id DESC LIMIT 20', get_current_user_id() ) ) ); // phpcs:ignore
	}

	/* ---------------------------- Admin configuration test ---------------------------- */

	/**
	 * Asks the provider whether these credentials are recognised. Does not prove that a real
	 * sign-in works: that needs a person, a browser and the callback URL registered.
	 *
	 * @return array {ok, message}
	 */
	public static function test_config( $provider ) {
		$c = self::config( $provider );
		if ( '' === $c['id'] || '' === $c['secret'] ) {
			return array( 'ok' => false, 'message' => 'Enter the ID and the secret first.' );
		}
		if ( 'google' === $provider ) {
			$res = wp_remote_post( 'https://oauth2.googleapis.com/token', array( 'timeout' => 15, 'body' => array( 'code' => 'nwc-config-test', 'client_id' => $c['id'], 'client_secret' => $c['secret'], 'redirect_uri' => self::callback_url( 'google' ), 'grant_type' => 'authorization_code' ) ) );
			if ( is_wp_error( $res ) ) {
				return array( 'ok' => false, 'message' => 'Could not reach Google from this server.' );
			}
			$err = (string) ( json_decode( (string) wp_remote_retrieve_body( $res ), true )['error'] ?? '' );
			return 'invalid_grant' === $err ? array( 'ok' => true, 'message' => 'Google recognises this client ID and secret. Try a real sign-in to finish testing.' ) : array( 'ok' => false, 'message' => 'Google answered: ' . sanitize_text_field( '' !== $err ? $err : 'unexpected response' ) );
		}
		$res = wp_remote_get( add_query_arg( array( 'client_id' => $c['id'], 'client_secret' => $c['secret'], 'grant_type' => 'client_credentials' ), 'https://graph.facebook.com/' . $c['version'] . '/oauth/access_token' ), array( 'timeout' => 15 ) );
		if ( is_wp_error( $res ) ) {
			return array( 'ok' => false, 'message' => 'Could not reach Facebook from this server.' );
		}
		return 200 === (int) wp_remote_retrieve_response_code( $res ) ? array( 'ok' => true, 'message' => 'Facebook recognises this app ID and secret. Try a real sign-in to finish testing.' ) : array( 'ok' => false, 'message' => 'Facebook refused these credentials.' );
	}
}
