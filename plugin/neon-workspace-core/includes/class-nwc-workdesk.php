<?php
/**
 * The Workspace end of Agency Connect, plus the consolidated-data outbox.
 *
 * Agency records are read live from WorkDesk through a signed API and never copied here (a short
 * cache only). Credentials stay on this server: the browser only ever talks to this site's REST
 * routes, which check the member's role and then call WorkDesk with the workspace's own key.
 */

defined( 'ABSPATH' ) || exit;

class NWC_WorkDesk {

	const MAX_SKEW = 300;

	public static function routes() {
		NWC_REST::route( '/agency/accept', 'POST', array( __CLASS__, 'accept' ), 'user' );
		NWC_REST::route( '/agency/accept-new', 'POST', array( __CLASS__, 'accept_new' ), 'public' );
		NWC_REST::route( '/agency/health', 'GET', array( __CLASS__, 'health' ) );
		foreach ( array(
			'overview'     => '/overview',
			'requests'     => '/work-requests',
			'deliverables' => '/deliverables',
			'packages'     => '/packages',
		) as $name => $remote ) {
			NWC_REST::route( '/agency/' . $name, 'GET', function () use ( $remote ) {
				return self::read( $remote );
			} );
		}
		// Project reads have no agency-feature gate here: a project on its own subscription keeps working.
		// WorkDesk decides project by project, and a locked one comes back with no details.
		NWC_REST::route( '/agency/projects', 'GET', function () {
			return self::project_read( '/projects' );
		} );
		NWC_REST::route( '/agency/tasks', 'GET', function () {
			return self::project_read( '/tasks' );
		} );
		NWC_REST::route( '/agency/projects/(?P<id>\d+)', 'GET', function ( WP_REST_Request $r ) {
			return self::project_read( '/projects/' . (int) $r['id'] );
		} );
		NWC_REST::route( '/agency/requests/(?P<id>\d+)', 'GET', function ( WP_REST_Request $r ) {
			return self::read( '/work-requests/' . (int) $r['id'], false );
		} );
		NWC_REST::route( '/agency/deliverables/(?P<id>\d+)', 'GET', function ( WP_REST_Request $r ) {
			return self::read( '/deliverables/' . (int) $r['id'], false );
		} );
		NWC_REST::route( '/agency/requests', 'POST', array( __CLASS__, 'request_create' ) );
		NWC_REST::route( '/agency/requests/(?P<id>\d+)/comments', 'POST', array( __CLASS__, 'request_comment' ) );
		NWC_REST::route( '/agency/requests/(?P<id>\d+)/cancel', 'POST', array( __CLASS__, 'request_cancel' ) );
		NWC_REST::route( '/agency/deliverables/(?P<id>\d+)/decision', 'POST', array( __CLASS__, 'decide' ) );
		NWC_REST::route( '/agency/deliverables/(?P<id>\d+)/comments', 'POST', array( __CLASS__, 'deliverable_comment' ) );
		NWC_REST::route( '/agency/invoices', 'GET', array( __CLASS__, 'invoices' ) );
		NWC_REST::route( '/agency/invoices/(?P<id>\d+)', 'GET', array( __CLASS__, 'invoice_one' ) );
		NWC_REST::route( '/agency/invoices/(?P<id>\d+)/pdf', 'GET', array( __CLASS__, 'invoice_pdf' ) );
		NWC_REST::route( '/agency/tasks/(?P<id>\d+)/comments', 'GET', function ( WP_REST_Request $r ) {
			return self::project_read( '/tasks/' . (int) $r['id'] . '/comments', false );
		} );
		NWC_REST::route( '/agency/tasks/(?P<id>\d+)/comments', 'POST', function ( WP_REST_Request $r ) {
			$u = wp_get_current_user();
			$g = self::guard();
			if ( is_wp_error( $g ) ) {
				return $g;
			}
			return self::call( NWC_Tenancy::$current->id, 'POST', '/tasks/' . (int) $r['id'] . '/comments', array( 'body' => (string) ( NWC_REST::body( $r )['body'] ?? '' ), 'author_name' => $u->display_name, 'external_id' => (string) ( NWC_REST::body( $r )['client_ref'] ?? '' ), 'attachments' => self::files( NWC_REST::body( $r ) ) ) );
		} );
		NWC_REST::route( '/agency/attachments/(?P<id>\d+)', 'GET', array( __CLASS__, 'attachment' ) );
		NWC_REST::route( '/agency/projects/(?P<id>\d+)/attachments', 'POST', function ( WP_REST_Request $r ) {
			$b = NWC_REST::body( $r );
			return self::write( 'agency.use', '/projects/' . (int) $r['id'] . '/attachments', array( 'attachments' => self::files( $b ), 'note' => mb_substr( sanitize_text_field( (string) ( $b['note'] ?? '' ) ), 0, 255 ), 'author_name' => wp_get_current_user()->display_name ) );
		} );
		NWC_REST::route( '/agency/pulse', 'GET', function () {
			return self::project_read( '/pulse', false );
		} );
		NWC_REST::route( '/webhooks/workdesk', 'POST', array( __CLASS__, 'webhook' ), 'public' );
	}

	public static function base_url() {
		$u = defined( 'NWC_WORKDESK_URL' ) ? NWC_WORKDESK_URL : NWC::settings()['workdesk_url'];
		// Someone pasting the API address gets the site address: /wp-json/wp-json/... has no route.
		return untrailingslashit( preg_replace( '#/(wp-json|index\.php)(/.*)?$#i', '', trim( (string) $u ) ) );
	}

	public static function site_credentials() {
		if ( defined( 'NWC_WORKDESK_KEY' ) && defined( 'NWC_WORKDESK_SECRET' ) ) {
			return array( NWC_WORKDESK_KEY, NWC_WORKDESK_SECRET );
		}
		$s = NWC::settings();
		return array( $s['workdesk_key'], '' !== $s['workdesk_secret'] ? NWC::unseal( $s['workdesk_secret'] ) : '' );
	}

	public static function link( $workspace_id ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . NWC_DB::t( 'agency_links' ) . " WHERE workspace_id = %d AND status <> 'disconnected'", $workspace_id ) ); // phpcs:ignore
	}

	public static function sign( $secret, $method, $route, $body, $ts, $nonce, $query = '' ) {
		return hash_hmac( 'sha256', strtoupper( $method ) . "\n" . $route . "\n" . $query . "\n" . $ts . "\n" . $nonce . "\n" . hash( 'sha256', (string) $body ), $secret );
	}

	/** The only wording customers see when the link itself fails. Details stay in api_log. */
	const CONNECT_UNAVAILABLE = 'NeonDigital Connect is temporarily unavailable. Please contact NeonDigital support.';

	private static function log( $ws, $dir, $method, $route, $status, $error = '' ) {
		global $wpdb;
		$wpdb->insert( NWC_DB::t( 'api_log' ), array( 'workspace_id' => $ws ? (int) $ws : null, 'direction' => $dir, 'method' => $method, 'route' => mb_substr( $route, 0, 190 ), 'status' => (int) $status, 'error' => mb_substr( (string) $error, 0, 190 ), 'created_at' => NWC::now() ) );
	}

	/**
	 * One signed call. Errors from WorkDesk keep their status; network problems become a plain 502.
	 *
	 * @return array|WP_Error Decoded body. With $raw, {body, type} for files.
	 */
	public static function http( $key, $secret, $method, $path, $body = null, $query = array(), $ws = null, $raw = false ) {
		$base = self::base_url();
		if ( '' === $base || '' === (string) $key || '' === (string) $secret ) {
			self::log( $ws, 'out', $method, $path, 0, 'Connection is not configured: address, key or secret is empty.' );
			return NWC::err( self::CONNECT_UNAVAILABLE, 503, 'nwc_workdesk_unconfigured' );
		}
		$route = '/workdesk/v1' . $path;
		$json  = null === $body ? '' : wp_json_encode( $body );
		ksort( $query );
		$qs    = http_build_query( $query );
		$ts    = time();
		$nonce = bin2hex( random_bytes( 12 ) );
		$args  = array(
			'method'      => $method,
			'timeout'     => 15,
			'redirection' => 0,
			'headers'     => array( 'Content-Type' => 'application/json', 'X-Neon-Key' => $key, 'X-Neon-Timestamp' => (string) $ts, 'X-Neon-Nonce' => $nonce, 'X-Neon-Signature' => self::sign( $secret, $method, $route, $json, $ts, $nonce, $qs ) ),
			'body'        => null === $body ? null : $json,
		);
		$res = self::send( $base . '/wp-json' . $route . ( '' !== $qs ? '?' . $qs : '' ), $args, $ws, $route );
		// Without pretty permalinks /wp-json/ is an ordinary 404 page. Same signed request, other address.
		if ( ! is_wp_error( $res ) && 404 === (int) wp_remote_retrieve_response_code( $res ) && ! is_array( json_decode( (string) wp_remote_retrieve_body( $res ), true ) ) ) {
			$res = self::send( $base . '/?rest_route=' . rawurlencode( $route ) . ( '' !== $qs ? '&' . $qs : '' ), $args, $ws, $route );
		}
		if ( is_wp_error( $res ) ) {
			self::log( $ws, 'out', $method, $route, 0, $res->get_error_message() );
			return NWC::err( self::CONNECT_UNAVAILABLE, 502, 'nwc_workdesk_down' );
		}
		$code = (int) wp_remote_retrieve_response_code( $res );
		if ( $code >= 200 && $code < 300 ) {
			self::log( $ws, 'out', $method, $route, $code );
		}
		if ( $raw && 200 === $code ) {
			return array( 'body' => wp_remote_retrieve_body( $res ), 'type' => wp_remote_retrieve_header( $res, 'content-type' ), 'disposition' => wp_remote_retrieve_header( $res, 'content-disposition' ) );
		}
		$data = json_decode( (string) wp_remote_retrieve_body( $res ), true );
		if ( $code < 200 || $code >= 300 ) {
			$detail = is_array( $data ) && ! empty( $data['message'] ) ? sanitize_text_field( $data['message'] ) : '';
			$up     = is_array( $data ) && ! empty( $data['code'] ) ? sanitize_key( $data['code'] ) : '';
			self::log( $ws, 'out', $method, $route, $code, trim( $up . ' ' . $detail ) );
			// Only WorkDesk's own answers to the request reach the customer. A refused key or signature,
			// a server fault, or a route WordPress cannot find (rest_no_route) means the link itself
			// failed: that detail stays in api_log. WorkDesk's 401 is never passed through, because the
			// app treats a 401 as "you are signed out".
			if ( 401 === $code || $code >= 500 || '' === $detail || 0 !== strpos( $up, 'ndwd_' ) ) {
				return NWC::err( self::CONNECT_UNAVAILABLE, 502, 'nwc_workdesk_error' );
			}
			return NWC::err( $detail, $code, $up );
		}
		return is_array( $data ) ? $data : array();
	}

	/**
	 * WordPress would follow a redirect by turning POST into GET, and the signed call would reach
	 * /workdesk/v1/link as a GET, which has no route. So redirects within the same site (http to
	 * https, www to bare) are followed here with the same method and body, and logged so an admin
	 * can correct the saved address. A redirect to another site is treated as a failure.
	 */
	private static function send( $url, $args, $ws, $route ) {
		for ( $hop = 0; $hop < 3; $hop++ ) {
			$res = wp_remote_request( $url, $args );
			if ( is_wp_error( $res ) ) {
				return $res;
			}
			$code = (int) wp_remote_retrieve_response_code( $res );
			if ( ! in_array( $code, array( 301, 302, 303, 307, 308 ), true ) ) {
				return $res;
			}
			$to = (string) wp_remote_retrieve_header( $res, 'location' );
			if ( '' === $to || ! self::same_site( $url, $to ) ) {
				return new WP_Error( 'nwc_redirect', 'WorkDesk address redirects to another site: ' . preg_replace( '/\?.*/', '', $to ) );
			}
			self::log( $ws, 'out', $args['method'], $route, $code, 'Redirected. Save the final WorkDesk address: ' . preg_replace( '/\?.*/', '', $to ) );
			$url = $to;
		}
		return new WP_Error( 'nwc_redirect', 'Too many redirects from the WorkDesk address.' );
	}

	private static function same_site( $a, $b ) {
		$host = function ( $u ) {
			return preg_replace( '/^www\./', '', strtolower( (string) wp_parse_url( $u, PHP_URL_HOST ) ) );
		};
		return '' !== $host( $b ) && $host( $a ) === $host( $b );
	}

	/** Signed, read-only checks for the admin screen. Reports the method and route, never a secret. */
	public static function diagnose() {
		$out   = array();
		$base  = self::base_url();
		list( $key, $secret ) = self::site_credentials();
		$out[] = array( 'check' => 'WorkDesk address', 'ok' => '' !== $base, 'detail' => '' !== $base ? $base : 'Not set' );
		$out[] = array( 'check' => 'Site key and secret', 'ok' => '' !== (string) $key && '' !== (string) $secret, 'detail' => '' !== (string) $key ? 'Key ending ' . substr( (string) $key, -4 ) . ', secret stored' : 'Not set' );
		if ( '' === $base ) {
			return $out;
		}
		$res   = wp_remote_get( $base . '/wp-json/workdesk/v1', array( 'timeout' => 10, 'redirection' => 0 ) );
		$code  = is_wp_error( $res ) ? 0 : (int) wp_remote_retrieve_response_code( $res );
		$out[] = array( 'check' => 'GET /wp-json/workdesk/v1', 'ok' => 200 === $code, 'detail' => 200 === $code ? 'WorkDesk integration is installed' : ( is_wp_error( $res ) ? $res->get_error_message() : ( $code >= 300 && $code < 400 ? 'Redirects to ' . wp_remote_retrieve_header( $res, 'location' ) . '. Save that address instead.' : 'Status ' . $code . '. Check that WorkDesk 1.14 or newer is active at this address.' ) ) );
		$res   = wp_remote_request( $base . '/wp-json/workdesk/v1/link', array( 'method' => 'OPTIONS', 'timeout' => 10, 'redirection' => 0 ) );
		$code  = is_wp_error( $res ) ? 0 : (int) wp_remote_retrieve_response_code( $res );
		$data  = is_wp_error( $res ) ? null : json_decode( (string) wp_remote_retrieve_body( $res ), true );
		$post  = 200 === $code && is_array( $data ) && in_array( 'POST', (array) ( $data['endpoints'][0]['methods'] ?? array() ), true );
		$out[] = array( 'check' => 'POST /wp-json/workdesk/v1/link is registered', 'ok' => $post, 'detail' => $post ? 'Route found' : ( $code >= 300 && $code < 400 ? 'Redirects to ' . wp_remote_retrieve_header( $res, 'location' ) . '. Save that address instead. Connect my workspace now follows this, but every call pays for it.' : 'Status ' . $code . '. This is the call behind Connect my workspace. Check that WorkDesk 1.14 or newer is active at this address.' ) );
		$res   = self::http( $key, $secret, 'GET', '/health' );
		$out[] = array( 'check' => 'Signed GET /workdesk/v1/health', 'ok' => ! is_wp_error( $res ), 'detail' => is_wp_error( $res ) ? 'Refused or failed. See the log below for the reason.' : 'Signature accepted. WorkDesk ' . sanitize_text_field( (string) ( $res['workdesk_version'] ?? '' ) ) );
		return $out;
	}

	/** Role check here, access check at WorkDesk. Project access can never be decided by a browser. */
	private static function project_read( $path, $cache = true ) {
		$g = self::guard();
		if ( is_wp_error( $g ) ) {
			return $g;
		}
		$ws = (int) NWC_Tenancy::$current->id;
		return $cache ? self::cached( $ws, 'GET', $path ) : self::call( $ws, 'GET', $path );
	}

	public static function call( $workspace_id, $method, $path, $body = null, $query = array(), $raw = false ) {
		$l = self::link( $workspace_id );
		if ( ! $l ) {
			return NWC::err( 'This workspace is not connected to NeonDigital.', 403, 'nwc_not_linked' );
		}
		return self::http( $l->api_key, NWC::unseal( $l->api_secret ), $method, $path, $body, $query, $workspace_id, $raw );
	}

	/** Short cache, keyed by a version that any webhook bumps, so changes show immediately. */
	public static function cached( $workspace_id, $method, $path, $query = array(), $ttl = 60 ) {
		$l = self::link( $workspace_id );
		if ( ! $l ) {
			return NWC::err( 'This workspace is not connected to NeonDigital.', 403, 'nwc_not_linked' );
		}
		$k   = 'nwc_wd_' . md5( $workspace_id . '|' . $l->cache_version . '|' . $path . '|' . wp_json_encode( $query ) );
		$hit = get_transient( $k );
		if ( false !== $hit ) {
			return $hit;
		}
		$res = self::call( $workspace_id, $method, $path, null, $query );
		if ( ! is_wp_error( $res ) ) {
			set_transient( $k, $res, $ttl );
		}
		return $res;
	}

	/* ---------------------------- Linking ---------------------------- */

	/** Stable for one invitation, so a retry presents the same identity and links nothing twice. */
	private static function join_uid( $token ) {
		return 'ws_' . substr( hash( 'sha256', 'nwc-join|' . $token ), 0, 24 );
	}

	/** WorkDesk's details for a token. @return array|WP_Error {email,name,business_name,expires_at} */
	public static function invite_preview( $token ) {
		if ( ! preg_match( '/^[A-Za-z0-9_-]{20,}$/', (string) $token ) ) {
			return NWC::err( 'This invitation is not valid. Ask NeonDigital for a new one.', 404, 'ndwd_invite_invalid' );
		}
		list( $key, $secret ) = self::site_credentials();
		return self::http( $key, $secret, 'POST', '/invitations/preview', array( 'token' => (string) $token ) );
	}

	private static function workspace_by_uid( $uid ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . NWC_DB::t( 'workspaces' ) . ' WHERE uid = %s', $uid ) ); // phpcs:ignore
	}

	/**
	 * Links the invitation, then creates what is missing on this side. A retry after a lost
	 * response gets the same answer from WorkDesk and finds the same workspace here.
	 *
	 * @return array|WP_Error {workspace_id}
	 */
	private static function complete_link( $user, $token, $name, $b ) {
		global $wpdb;
		$uid  = self::join_uid( $token );
		$have = self::workspace_by_uid( $uid );
		if ( $have ) {
			$member = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . NWC_DB::t( 'members' ) . " WHERE workspace_id = %d AND user_id = %d AND status = 'active'", $have->id, $user->ID ) ); // phpcs:ignore
			if ( ! $member ) {
				return NWC::err( 'This invitation has already been used.', 409, 'ndwd_invite_used' );
			}
			return array( 'workspace_id' => (int) $have->id, 'already_connected' => true );
		}
		list( $key, $secret ) = self::site_credentials();
		$res = self::http( $key, $secret, 'POST', '/link', array( 'token' => $token, 'workspace_uid' => $uid, 'workspace_name' => $name, 'email' => $user->user_email ) );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		$cat = '' !== (string) ( $res['business_category'] ?? '' ) ? NWC_Catalog::category_key( (string) $res['business_category'] ) : '';
		$w   = NWC_Tenancy::create( array( 'name' => $name, 'account_type' => 'agency', 'kind' => 'business', 'category' => $cat, 'timezone' => $b['timezone'] ?? 'Asia/Kolkata' ), $user->ID );
		if ( is_wp_error( $w ) ) {
			return $w;
		}
		// The uid WorkDesk stored is the identity of this workspace.
		$wpdb->update( NWC_DB::t( 'workspaces' ), array( 'uid' => $uid ), array( 'id' => $w->id ) );
		$wpdb->insert( NWC_DB::t( 'agency_links' ), array( 'workspace_id' => (int) $w->id, 'api_key' => $res['api_key'], 'api_secret' => NWC::seal( $res['api_secret'] ), 'client_name' => mb_substr( sanitize_text_field( (string) ( $res['client']['name'] ?? '' ) ), 0, 190 ), 'linked_at' => NWC::now() ) );
		self::apply_entitlements( (int) $w->id, $res['entitlements'] ?? array(), $res['client'] ?? array() );

		NWC_Personal::mark_agency_client( $user->ID ); // An agency client gets one free Personal Workspace.
		NWC::audit( 'agency_linked', array( 'workspace_id' => $w->id, 'kind' => 'admin', 'entity' => 'workspace', 'entity_id' => $w->id, 'user_id' => $user->ID ) );
		NWC_Sync::enqueue( (int) $w->id, 'workspace', (int) $w->id );
		return array( 'workspace_id' => (int) $w->id );
	}

	/**
	 * Accept for someone already signed in. WorkDesk checks the token (single use, expiry, email)
	 * and only then is the agency workspace created here. WorkDesk's client record is never recreated.
	 */
	public static function accept( WP_REST_Request $r ) {
		$user = wp_get_current_user();
		$b    = NWC_REST::body( $r );
		if ( ! NWC::rate_ok( 'accept|' . $user->ID, 10, 300 ) ) {
			return NWC::err( 'Too many attempts. Please try again later.', 429, 'nwc_rate_limited' );
		}
		$token = (string) ( $b['token'] ?? '' );
		if ( '' === $token ) {
			return NWC::err( 'This invitation is not valid. Ask NeonDigital for a new one.', 404, 'ndwd_invite_invalid' );
		}
		if ( empty( $b['accept_legal'] ) ) {
			return NWC::err( 'Please confirm you have read and agree to the Privacy Policy and Terms & Conditions.', 422, 'nwc_terms_required' );
		}
		$first = true; // The invitation always asks for the legal tick, so it is always recorded.
		$pv   = self::invite_preview( $token );
		$name = trim( sanitize_text_field( (string) ( $b['workspace_name'] ?? '' ) ) );
		if ( is_wp_error( $pv ) ) {
			// Already used may be this same person retrying. complete_link decides that.
			if ( 'ndwd_invite_used' !== $pv->get_error_code() ) {
				return $pv;
			}
		} else {
			if ( 0 !== strcasecmp( (string) $user->user_email, (string) $pv['email'] ) ) {
				return NWC::err( 'This invitation was sent to a different email address.', 403, 'ndwd_invite_email' );
			}
			$name = (string) ( ! empty( $pv['workspace_name'] ) ? $pv['workspace_name'] : $pv['business_name'] );
		}
		$res = self::complete_link( $user, $token, '' !== $name ? $name : $user->display_name, $b );
		if ( ! is_wp_error( $res ) && $first ) {
			NWC_Legal::record_acceptance( $user->ID, $res['workspace_id'], 'invitation' );
			if ( ! empty( $b['marketing_consent'] ) ) {
				NWC_Legal::record_marketing( $user->ID, true, 'invitation' );
			}
		}
		return $res;
	}

	/**
	 * A new person, setting a password only. Name, email and business come from WorkDesk. An email
	 * that already has an account must sign in instead: no second account, no password change.
	 */
	public static function accept_new( WP_REST_Request $r ) {
		$b = NWC_REST::body( $r );
		if ( ! NWC::rate_ok( 'joinnew|' . NWC::ip(), 10, 300 ) ) {
			return NWC::err( 'Too many attempts. Please try again later.', 429, 'nwc_rate_limited' );
		}
		$token = (string) ( $b['token'] ?? '' );
		$pass  = (string) ( $b['password'] ?? '' );
		$weak  = self::password_problem( $pass );
		if ( $weak ) {
			return NWC::err( $weak );
		}
		if ( ! hash_equals( $pass, (string) ( $b['password2'] ?? '' ) ) ) {
			return NWC::err( 'The two passwords do not match.' );
		}
		if ( empty( $b['accept_legal'] ) ) {
			return NWC::err( 'Please confirm you have read and agree to the Privacy Policy and Terms & Conditions.', 422, 'nwc_terms_required' );
		}
		$pv = self::invite_preview( $token );
		if ( is_wp_error( $pv ) ) {
			return $pv;
		}
		$email = sanitize_email( (string) $pv['email'] );
		if ( email_exists( $email ) ) {
			return NWC::err( 'An account with this email already exists. Please sign in to accept this invitation.', 409, 'nwc_exists' );
		}
		// Link first: if WorkDesk refuses, no account is left behind.
		list( $key, $secret ) = self::site_credentials();
		$wname  = (string) ( ! empty( $pv['workspace_name'] ) ? $pv['workspace_name'] : $pv['business_name'] );
		$linked = self::http( $key, $secret, 'POST', '/link', array( 'token' => $token, 'workspace_uid' => self::join_uid( $token ), 'workspace_name' => $wname, 'email' => $email ) );
		if ( is_wp_error( $linked ) ) {
			return $linked;
		}
		$login = sanitize_user( strtok( $email, '@' ), true );
		$login = '' === $login ? 'user' : $login;
		$base  = $login;
		$n     = 1;
		while ( username_exists( $login ) ) {
			$login = $base . ( ++$n );
		}
		$name = mb_substr( sanitize_text_field( (string) $pv['name'] ), 0, 120 );
		$id   = wp_insert_user( array( 'user_login' => $login, 'user_email' => $email, 'user_pass' => $pass, 'display_name' => $name, 'first_name' => $name, 'role' => 'nwc_user' ) );
		if ( is_wp_error( $id ) ) {
			return NWC::err( 'Could not create the account. Please try again.' );
		}
		wp_set_current_user( $id );
		if ( ! defined( 'NWC_TESTING' ) ) {
			wp_set_auth_cookie( $id, true, is_ssl() );
		}
		NWC::audit( 'account_created', array( 'user_id' => $id, 'workspace_id' => null, 'kind' => 'auth', 'source' => 'invitation' ) );
		$res = self::complete_link( get_userdata( $id ), $token, $wname, $b );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		NWC_Legal::record_acceptance( $id, $res['workspace_id'], 'invitation' );
		if ( ! empty( $b['marketing_consent'] ) ) {
			NWC_Legal::record_marketing( $id, true, 'invitation' );
		}
		return $res;
	}

	/** The invitation password rule. Sign-up and other logins keep their own. @return string Empty when fine. */
	public static function password_problem( $p ) {
		return NWC::password_problem( $p );
	}

	/** Mirrors WorkDesk's feature grants, each with its own dates. */
	public static function apply_entitlements( $workspace_id, $items, $client ) {
		global $wpdb;
		$wpdb->delete( NWC_DB::t( 'feature_grants' ), array( 'workspace_id' => $workspace_id, 'source' => 'agency' ) );
		foreach ( (array) $items as $e ) {
			$code = sanitize_key( (string) ( $e['feature_code'] ?? '' ) );
			if ( isset( NWC_Features::CATALOG[ $code ] ) ) {
				NWC_Features::set_grant( $workspace_id, $code, 'agency', array( 'enabled' => ! empty( $e['enabled'] ), 'starts_on' => $e['starts_on'] ?? null, 'expires_on' => $e['expires_on'] ?? null, 'grace_days' => $e['grace_days'] ?? null, 'usage_limit' => $e['usage_limit'] ?? null ) );
			}
		}
		$row = array( 'entitlements_stale' => 0, 'last_sync_at' => NWC::now() );
		if ( $client ) {
			$row += array( 'client_name' => mb_substr( sanitize_text_field( (string) ( $client['name'] ?? '' ) ), 0, 190 ), 'status' => sanitize_key( (string) ( $client['status'] ?? 'active' ) ), 'agency_connect' => empty( $client['agency_connect'] ) ? 0 : 1, 'package_state' => sanitize_key( (string) ( $client['package_state'] ?? '' ) ), 'package_end' => isset( $client['package_end'] ) && NWC::valid_date( $client['package_end'] ) ? $client['package_end'] : null, 'permissions' => wp_json_encode( $client['permissions'] ?? array() ), 'independent_projects' => absint( $client['independent_projects'] ?? 0 ) );
		}
		$wpdb->update( NWC_DB::t( 'agency_links' ), $row, array( 'workspace_id' => $workspace_id ) );
		NWC_Features::flush();
	}

	/**
	 * Refreshes grants when a webhook marked them stale, or after an hour as a safety net. If
	 * WorkDesk is unreachable the last known grants stand: their dates still expire on their own.
	 */
	public static function refresh_if_stale( $workspace_id, $force = false ) {
		$l = self::link( $workspace_id );
		if ( ! $l ) {
			return;
		}
		$old = ! $l->last_sync_at || strtotime( $l->last_sync_at . ' UTC' ) < time() - HOUR_IN_SECONDS;
		if ( $force || (int) $l->entitlements_stale || $old ) {
			$res = self::call( $workspace_id, 'GET', '/entitlements' );
			if ( ! is_wp_error( $res ) ) {
				self::apply_entitlements( $workspace_id, $res['items'] ?? array(), $res['client'] ?? array() );
			}
		}
	}

	public static function summary( $workspace_id ) {
		$l = self::link( $workspace_id );
		if ( ! $l ) {
			return null;
		}
		return array( 'client_name' => $l->client_name, 'status' => $l->status, 'agency_connect' => (bool) $l->agency_connect, 'package_state' => $l->package_state, 'package_end' => $l->package_end, 'permissions' => json_decode( (string) $l->permissions, true ), 'last_sync_at' => $l->last_sync_at, 'independent_projects' => (int) $l->independent_projects );
	}

	/* ---------------------------- Proxied agency actions ---------------------------- */

	private static function guard( $perm = 'agency.use' ) {
		if ( ! NWC_Tenancy::can( $perm ) ) {
			return NWC::err( 'Your role does not allow this.', 403, 'nwc_forbidden' );
		}
		if ( ! self::link( NWC_Tenancy::$current->id ) ) {
			return NWC::err( 'This workspace is not connected to NeonDigital.', 403, 'nwc_not_linked' );
		}
		return true;
	}

	private static function read( $remote, $cache = true ) {
		$g = self::guard();
		if ( is_wp_error( $g ) ) {
			return $g;
		}
		$ws = (int) NWC_Tenancy::$current->id;
		if ( '/invoices' !== $remote ) { // Account-level invoices stay readable; the rest need the feature.
			$f = NWC_Features::guard( 'agency_connect' );
			if ( is_wp_error( $f ) ) {
				return $f;
			}
		}
		return $cache ? self::cached( $ws, 'GET', $remote ) : self::call( $ws, 'GET', $remote );
	}

	private static function bump() {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( 'UPDATE ' . NWC_DB::t( 'agency_links' ) . ' SET cache_version = cache_version + 1 WHERE workspace_id = %d', NWC_Tenancy::$current->id ) ); // phpcs:ignore
	}

	/** Derived from a key the browser generates, so a double submit is one request. */
	private static function ext_id( $key, $prefix ) {
		$k = trim( (string) $key );
		return $prefix . '_' . substr( hash( 'sha256', NWC_Tenancy::$current->uid . '|' . get_current_user_id() . '|' . ( '' !== $k ? $k : bin2hex( random_bytes( 16 ) ) ) ), 0, 40 );
	}

	private static function write( $perm, $path, $body ) {
		$g = self::guard( $perm );
		if ( is_wp_error( $g ) ) {
			return $g;
		}
		$f = NWC_Features::guard( 'agency_connect' );
		if ( is_wp_error( $f ) ) {
			return $f;
		}
		$res = self::call( NWC_Tenancy::$current->id, 'POST', $path, $body );
		if ( ! is_wp_error( $res ) ) {
			self::bump();
		}
		return $res;
	}

	public static function request_create( WP_REST_Request $r ) {
		$b = NWC_REST::body( $r );
		$u = wp_get_current_user();
		return self::write( 'agency.use', '/work-requests', array(
			'external_id'       => self::ext_id( $b['idempotency_key'] ?? '', 'req' ),
			'title'             => $b['title'] ?? '',
			'description'       => $b['description'] ?? '',
			'instructions'      => $b['instructions'] ?? '',
			'project_id'        => absint( $b['project_id'] ?? 0 ),
			'client_package_id' => absint( $b['client_package_id'] ?? 0 ),
			'item_id'           => absint( $b['item_id'] ?? 0 ),
			'preferred_date'    => $b['preferred_date'] ?? '',
			'reference_links'   => $b['reference_links'] ?? array(),
			'attachments'       => self::files( $b ),
			'requested_by'      => $u->display_name,
		) );
	}

	public static function request_comment( WP_REST_Request $r ) {
		$b = NWC_REST::body( $r );
		return self::write( 'agency.use', '/work-requests/' . (int) $r['id'] . '/comments', array( 'body' => $b['body'] ?? '', 'external_id' => self::ext_id( $b['idempotency_key'] ?? '', 'cmt' ), 'author_name' => wp_get_current_user()->display_name, 'attachments' => self::files( $b ) ) );
	}

	public static function request_cancel( WP_REST_Request $r ) {
		return self::write( 'agency.use', '/work-requests/' . (int) $r['id'] . '/cancel', array( 'actor_name' => wp_get_current_user()->display_name ) );
	}

	public static function decide( WP_REST_Request $r ) {
		$b = NWC_REST::body( $r );
		return self::write( 'agency.approve', '/deliverables/' . (int) $r['id'] . '/decision', array( 'decision' => $b['decision'] ?? '', 'comment' => $b['comment'] ?? '', 'external_id' => self::ext_id( $b['idempotency_key'] ?? '', 'dec' ), 'actor_name' => wp_get_current_user()->display_name ) );
	}

	public static function deliverable_comment( WP_REST_Request $r ) {
		$b = NWC_REST::body( $r );
		return self::write( 'agency.use', '/deliverables/' . (int) $r['id'] . '/comments', array( 'body' => $b['body'] ?? '', 'external_id' => self::ext_id( $b['idempotency_key'] ?? '', 'cmt' ), 'author_name' => wp_get_current_user()->display_name ) );
	}

	/* ---------------------------- Agency invoices: a read-only mirror ---------------------------- */

	private static function mirror_out( $x, $detail = false ) {
		$d   = json_decode( (string) $x->data, true );
		$d   = is_array( $d ) ? $d : array();
		$out = array( 'id' => (int) $x->wd_invoice_id, 'invoice_no' => $x->invoice_no, 'invoice_date' => $x->invoice_date, 'due_date' => $x->due_date, 'total' => (float) $x->total, 'amount_paid' => (float) $x->amount_paid, 'balance' => (float) $x->balance, 'status' => $x->status, 'status_label' => $x->status_label );
		if ( $detail ) {
			$out += array( 'lines' => $d['lines'] ?? array(), 'subtotal' => (float) ( $d['subtotal'] ?? 0 ), 'discount' => (float) ( $d['discount'] ?? 0 ), 'tax_label' => (string) ( $d['tax_label'] ?? '' ), 'tax_rate' => (float) ( $d['tax_rate'] ?? 0 ), 'tax_amount' => (float) ( $d['tax_amount'] ?? 0 ), 'package_name' => (string) ( $d['package_name'] ?? '' ) );
		}
		return $out;
	}

	/** Only invoices NeonDigital explicitly sent exist here. There is no route that changes one. */
	public static function invoices() {
		global $wpdb;
		if ( ! NWC_Tenancy::can( 'agency.use' ) ) {
			return NWC::err( 'Your role does not allow this.', 403, 'nwc_forbidden' );
		}
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . NWC_DB::t( 'agency_invoices' ) . ' WHERE workspace_id = %d ORDER BY invoice_date DESC, id DESC LIMIT 200', NWC_Tenancy::$current->id ) ); // phpcs:ignore
		return array( 'items' => array_map( array( __CLASS__, 'mirror_out' ), $rows ) );
	}

	private static function mirror_row( $wd_id ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . NWC_DB::t( 'agency_invoices' ) . ' WHERE workspace_id = %d AND wd_invoice_id = %d', NWC_Tenancy::$current->id, $wd_id ) ); // phpcs:ignore
	}

	public static function invoice_one( WP_REST_Request $r ) {
		if ( ! NWC_Tenancy::can( 'agency.use' ) ) {
			return NWC::err( 'Your role does not allow this.', 403, 'nwc_forbidden' );
		}
		$x = self::mirror_row( (int) $r['id'] );
		return $x ? self::mirror_out( $x, true ) : NWC::err( 'Invoice not found.', 404, 'nwc_not_found' );
	}

	/** A newer event replaces an older one, and a late retry of an old event changes nothing. */
	private static function store_invoice( $workspace_id, $i ) {
		global $wpdb;
		$wd_id = absint( $i['id'] ?? 0 );
		if ( ! $wd_id ) {
			return false;
		}
		$t     = NWC_DB::t( 'agency_invoices' );
		$now   = NWC::now();
		$stamp = absint( $i['updated_stamp'] ?? 0 );
		$old   = $wpdb->get_row( $wpdb->prepare( "SELECT id, source_stamp FROM $t WHERE workspace_id = %d AND wd_invoice_id = %d", $workspace_id, $wd_id ) ); // phpcs:ignore
		if ( $old && (int) $old->source_stamp > $stamp ) {
			return false;
		}
		$lines = array();
		foreach ( array_slice( (array) ( $i['lines'] ?? array() ), 0, 500 ) as $l ) {
			$lines[] = array( 'description' => mb_substr( sanitize_text_field( (string) ( $l['description'] ?? '' ) ), 0, 500 ), 'amount' => (float) ( $l['amount'] ?? 0 ) );
		}
		$row = array(
			'invoice_no'   => mb_substr( sanitize_text_field( (string) ( $i['invoice_no'] ?? '' ) ), 0, 60 ),
			'invoice_date' => NWC::valid_date( $i['invoice_date'] ?? '' ) ? $i['invoice_date'] : null,
			'due_date'     => NWC::valid_date( $i['due_date'] ?? '' ) ? $i['due_date'] : null,
			'total'        => (float) ( $i['total'] ?? 0 ),
			'amount_paid'  => (float) ( $i['amount_paid'] ?? 0 ),
			'balance'      => (float) ( $i['balance'] ?? 0 ),
			'status'       => mb_substr( sanitize_key( (string) ( $i['status'] ?? '' ) ), 0, 20 ),
			'status_label' => mb_substr( sanitize_text_field( (string) ( $i['status_label'] ?? '' ) ), 0, 40 ),
			'data'         => wp_json_encode( array( 'lines' => $lines, 'subtotal' => (float) ( $i['subtotal'] ?? 0 ), 'discount' => (float) ( $i['discount'] ?? 0 ), 'tax_label' => mb_substr( sanitize_text_field( (string) ( $i['tax_label'] ?? '' ) ), 0, 60 ), 'tax_rate' => (float) ( $i['tax_rate'] ?? 0 ), 'tax_amount' => (float) ( $i['tax_amount'] ?? 0 ), 'package_name' => mb_substr( sanitize_text_field( (string) ( $i['package_name'] ?? '' ) ), 0, 190 ) ) ),
			'source_stamp' => $stamp,
			'updated_at'   => $now,
		);
		if ( $old ) {
			$wpdb->update( $t, $row, array( 'id' => $old->id ) );
		} else {
			$wpdb->insert( $t, array_merge( $row, array( 'workspace_id' => (int) $workspace_id, 'wd_invoice_id' => $wd_id, 'received_at' => $now ) ) );
		}
		return true;
	}

	/**
	 * WorkDesk ended the integration. Workspace data stays with its owner, and only the agency
	 * grants, the sharing consent and queued sync end. Nothing about an account is deleted.
	 */
	private static function on_disconnected( $l, $w ) {
		global $wpdb;
		$wpdb->update( NWC_DB::t( 'agency_links' ), array( 'status' => 'disconnected', 'agency_connect' => 0, 'api_key' => 'disconnected_' . (int) $l->id, 'api_secret' => '' ), array( 'id' => $l->id ) );
		$wpdb->delete( NWC_DB::t( 'feature_grants' ), array( 'workspace_id' => (int) $l->workspace_id, 'source' => 'agency' ) );
		$wpdb->update( NWC_DB::t( 'workspaces' ), array( 'account_type' => 'independent', 'sharing_consent_at' => null, 'sharing_notice_version' => '' ), array( 'id' => (int) $l->workspace_id ) );
		$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . NWC_DB::t( 'sync_outbox' ) . " WHERE workspace_id = %d AND entity IN ('lead','booking')", $l->workspace_id ) ); // phpcs:ignore
		NWC_Features::flush();
		NWC::audit( 'agency_disconnected', array( 'workspace_id' => (int) $l->workspace_id, 'kind' => 'admin', 'user_id' => 0, 'source' => 'workdesk' ) );
	}

	/**
	 * Files the browser sends with a message or request, passed on as they are. WorkDesk checks type,
	 * content and size and stores them; nothing is kept on this site.
	 */
	private static function files( $b ) {
		$out = array();
		foreach ( array_slice( (array) ( $b['attachments'] ?? array() ), 0, 5 ) as $f ) {
			if ( is_array( $f ) && isset( $f['name'], $f['data'] ) ) {
				$out[] = array( 'name' => mb_substr( sanitize_file_name( (string) $f['name'] ), 0, 190 ), 'data' => (string) $f['data'] );
			}
		}
		return $out;
	}

	/**
	 * Downloads a NeonDigital file through this Workspace's own signed link. WorkDesk decides whether
	 * this Workspace may see it; an internal file, or one from another Workspace, is simply not found.
	 */
	public static function attachment( WP_REST_Request $r ) {
		$g = self::guard();
		if ( is_wp_error( $g ) ) {
			return $g;
		}
		$res = self::call( NWC_Tenancy::$current->id, 'GET', '/attachments/' . (int) $r['id'], null, array(), true );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		if ( defined( 'NWC_TESTING' ) ) {
			return array( 'size' => strlen( $res['body'] ), 'head' => substr( $res['body'], 0, 5 ), 'disposition' => (string) $res['disposition'] );
		}
		$type = preg_match( '~^[a-z]+/[a-z0-9.+-]+$~i', (string) $res['type'] ) ? (string) $res['type'] : 'application/octet-stream';
		$disp = preg_match( '~^(inline|attachment); filename="[^"\r\n]*"~', (string) $res['disposition'] ) ? (string) $res['disposition'] : 'attachment';
		while ( ob_get_level() ) {
			ob_end_clean();
		}
		nocache_headers();
		header( 'Content-Type: ' . $type );
		header( 'Content-Disposition: ' . $disp );
		header( 'Content-Length: ' . strlen( $res['body'] ) );
		header( 'X-Content-Type-Options: nosniff' );
		header( "Content-Security-Policy: default-src 'none'; sandbox" );
		echo $res['body']; // phpcs:ignore
		exit;
	}

	public static function invoice_pdf( WP_REST_Request $r ) {
		$g = self::guard();
		if ( is_wp_error( $g ) ) {
			return $g;
		}
		if ( ! self::mirror_row( (int) $r['id'] ) ) {
			return NWC::err( 'Invoice not found.', 404, 'nwc_not_found' );
		}
		$res = self::call( NWC_Tenancy::$current->id, 'GET', '/invoices/' . (int) $r['id'] . '/pdf', null, array(), true );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		if ( defined( 'NWC_TESTING' ) ) {
			return array( 'size' => strlen( $res['body'] ), 'head' => substr( $res['body'], 0, 5 ) );
		}
		nocache_headers();
		header( 'Content-Type: application/pdf' );
		header( 'X-Content-Type-Options: nosniff' );
		header( 'Content-Disposition: attachment; filename="invoice-' . (int) $r['id'] . '.pdf"' );
		echo $res['body']; // phpcs:ignore
		exit;
	}

	public static function health() {
		$g = self::guard();
		if ( is_wp_error( $g ) ) {
			return $g;
		}
		$res = self::call( NWC_Tenancy::$current->id, 'GET', '/connection/health' );
		return is_wp_error( $res ) ? array( 'ok' => false, 'error' => $res->get_error_message() ) : array( 'ok' => true, 'connection' => $res['connection'] ?? null );
	}

	/* ---------------------------- Incoming webhooks ---------------------------- */

	const EVENT_TEXT = array(
		'work_request.commented'      => array( 'New comment from NeonDigital', '#/agency/requests' ),
		'task.commented'              => array( 'New message from NeonDigital', '#/agency/tasks' ),
		'task.chat_opened'            => array( 'NeonDigital opened a chat on your request', '#/agency/requests' ),
		'project.package_changed'     => array( 'The package on one of your projects changed', '#/agency/projects' ),
		'project.migration_started'   => array( 'A project is moving to its own subscription. Activate it to keep using it.', '#/agency/projects' ),
		'project.migration_reminder'  => array( 'A project\'s grace period is ending soon. Activate its subscription to keep it open.', '#/agency/projects' ),
		'project.locked'              => array( 'A project was locked because its grace period ended. Your data is safe.', '#/agency/projects' ),
		'project.migration_reversed'  => array( 'NeonDigital moved a project back to the agency arrangement', '#/agency/projects' ),
		'task.visibility_changed'     => array( 'A task was shared with you', '#/agency/tasks' ),
		'deliverable.uploaded'        => array( 'A deliverable is ready for review', '#/agency/deliverables' ),
		'invoice.shared'              => array( 'New invoice from NeonDigital', '#/agency/invoices' ),
		'invoice.resent'              => array( 'NeonDigital sent an invoice again', '#/agency/invoices' ),
		'invoice.paid'                => array( 'Payment recorded by NeonDigital', '#/agency/invoices' ),
		'client.disconnected'         => array( 'NeonDigital Connect was disconnected. Your workspace and its data remain yours.', '#/settings' ),
		'workspace.deletion_requested' => array( 'NeonDigital recorded a request about deleting this account. Nothing has been deleted. Contact NeonDigital if you did not ask for this.', '#/settings' ),
		'project.created'             => array( 'New project from NeonDigital', '#/agency/projects' ),
		'project.assigned'            => array( 'A new project has been added to your Neon Workspace.', '#/agency/projects' ),
		'project.file_shared'         => array( 'NeonDigital shared a file on a project', '#/agency/projects' ),
		'project.completed'           => array( 'Project completed', '#/agency/projects' ),
		'client.suspended'            => array( 'Your agency access was suspended', '#/agency' ),
	);

	/**
	 * Phase 4: a notification opens the exact record it is about (the conversation, request,
	 * deliverable or project), not a list. Ids come from WorkDesk's event and are only ever used in
	 * the app's own routes, which ask WorkDesk again with this Workspace's credentials.
	 */
	public static function deep_link( $event, $data, $fallback ) {
		$n = function ( $k ) use ( $data ) {
			return absint( $data[ $k ] ?? 0 );
		};
		if ( 'task.commented' === $event || 'task.chat_opened' === $event || 'task.visibility_changed' === $event ) {
			return $n( 'request_id' ) ? '#/agency/requests/' . $n( 'request_id' ) : ( $n( 'task_id' ) ? '#/agency/tasks/' . $n( 'task_id' ) : $fallback );
		}
		if ( 'work_request.commented' === $event && $n( 'entity_id' ) ) {
			return 'deliverable' === ( $data['entity'] ?? '' ) ? '#/agency/deliverables/' . $n( 'entity_id' ) : '#/agency/requests/' . $n( 'entity_id' );
		}
		if ( 'deliverable.uploaded' === $event && $n( 'deliverable_id' ) ) {
			return '#/agency/deliverables/' . $n( 'deliverable_id' );
		}
		if ( 0 === strpos( $event, 'project.' ) && $n( 'project_id' ) ) {
			return '#/agency/projects/' . $n( 'project_id' );
		}
		return $fallback;
	}

	/**
	 * Checks signature, freshness and nonce, then processes each event id once. Events carry ids
	 * only: a webhook just invalidates the cache and marks grants for refresh.
	 */
	public static function webhook( WP_REST_Request $r ) {
		global $wpdb;
		$key = (string) $r->get_header( 'x_neon_key' );
		$l   = '' !== $key ? $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . NWC_DB::t( 'agency_links' ) . ' WHERE api_key = %s', $key ) ) : null; // phpcs:ignore
		$route = '/neon-workspace/v1/webhooks/workdesk';
		if ( ! $l ) {
			self::log( null, 'in', 'POST', $route, 401, 'unknown key' );
			return NWC::err( 'Unknown key.', 401, 'nwc_bad_key' );
		}
		$ts    = (string) $r->get_header( 'x_neon_timestamp' );
		$nonce = (string) $r->get_header( 'x_neon_nonce' );
		$sig   = (string) $r->get_header( 'x_neon_signature' );
		if ( ! ctype_digit( $ts ) || abs( time() - (int) $ts ) > self::MAX_SKEW || ! preg_match( '/^[A-Za-z0-9_-]{16,64}$/', $nonce ) ) {
			self::log( $l->workspace_id, 'in', 'POST', $route, 401, 'stale' );
			return NWC::err( 'Stale request.', 401, 'nwc_stale' );
		}
		if ( ! hash_equals( self::sign( NWC::unseal( $l->api_secret ), 'POST', $route, $r->get_body(), $ts, $nonce ), $sig ) ) {
			self::log( $l->workspace_id, 'in', 'POST', $route, 401, 'bad signature' );
			return NWC::err( 'Invalid signature.', 401, 'nwc_bad_signature' );
		}
		$wpdb->suppress_errors( true );
		$fresh = $wpdb->query( $wpdb->prepare( 'INSERT IGNORE INTO ' . NWC_DB::t( 'nonces' ) . ' (nonce_hash, expires_at) VALUES (%s, %d)', hash( 'sha256', $key . '|' . $nonce ), time() + 2 * self::MAX_SKEW ) ); // phpcs:ignore
		$wpdb->suppress_errors( false );
		if ( ! $fresh ) {
			self::log( $l->workspace_id, 'in', 'POST', $route, 409, 'replay' );
			return NWC::err( 'Replay refused.', 409, 'nwc_replay' );
		}
		$d     = json_decode( (string) $r->get_body(), true );
		$event = sanitize_text_field( (string) ( $d['event'] ?? '' ) );
		$id    = sanitize_text_field( (string) ( $d['event_id'] ?? '' ) );
		if ( '' === $id || '' === $event ) {
			return NWC::err( 'Malformed event.' );
		}
		$w = NWC_Tenancy::workspace( $l->workspace_id );
		if ( ! $w || ( $d['workspace_uid'] ?? '' ) !== $w->uid ) {
			self::log( $l->workspace_id, 'in', 'POST', $route, 403, 'wrong workspace' );
			return NWC::err( 'Event is for another workspace.', 403, 'nwc_forbidden' );
		}
		$wpdb->suppress_errors( true );
		$first = $wpdb->query( $wpdb->prepare( 'INSERT IGNORE INTO ' . NWC_DB::t( 'webhook_events' ) . ' (event_id, event, workspace_id, received_at) VALUES (%s, %s, %d, %s)', $id, $event, $l->workspace_id, NWC::now() ) ); // phpcs:ignore
		$wpdb->suppress_errors( false );
		self::log( $l->workspace_id, 'in', 'POST', $route, 200, $first ? '' : 'duplicate' );
		if ( ! $first ) {
			return array( 'ok' => true, 'duplicate' => true );
		}
		$patch = 'UPDATE ' . NWC_DB::t( 'agency_links' ) . ' SET cache_version = cache_version + 1';
		if ( 0 === strpos( $event, 'entitlements.' ) || 0 === strpos( $event, 'client.' ) || 0 === strpos( $event, 'package.' ) || 0 === strpos( $event, 'project.' ) ) {
			$patch .= ', entitlements_stale = 1';
		}
		$wpdb->query( $wpdb->prepare( $patch . ' WHERE id = %d', $l->id ) ); // phpcs:ignore
		if ( 0 === strpos( $event, 'invoice.' ) && isset( $d['data']['invoice'] ) && is_array( $d['data']['invoice'] ) ) {
			self::store_invoice( (int) $l->workspace_id, $d['data']['invoice'] );
		}
		if ( 'client.disconnected' === $event ) {
			self::on_disconnected( $l, $w );
		}
		if ( 'work_request.status_changed' === $event ) {
			$stage = sanitize_key( (string) ( $d['data']['stage'] ?? '' ) );
			$text  = array( 'received' => 'NeonDigital received your request', 'reviewed' => 'NeonDigital reviewed your request', 'assigned' => 'Your request was assigned to the team', 'started' => 'Work started on your request', 'completed' => 'Work on your request is complete', 'rejected' => 'NeonDigital could not take on your request' );
			if ( isset( $text[ $stage ] ) ) {
				NWC_Notify::workspace_owners( (int) $l->workspace_id, 'agency', $text[ $stage ], mb_substr( sanitize_textarea_field( (string) ( $d['data']['note'] ?? '' ) ), 0, 500 ), '#/agency/requests/' . absint( $d['data']['request_id'] ?? 0 ), 'wd:' . $id, 'rejected' === $stage );
			}
		} elseif ( isset( self::EVENT_TEXT[ $event ] ) ) {
			$data  = is_array( $d['data'] ?? null ) ? $d['data'] : array();
			$title = self::EVENT_TEXT[ $event ][0];
			if ( 'project.assigned' === $event && ! empty( $data['title'] ) ) {
				$title = mb_substr( sanitize_text_field( (string) $data['title'] ), 0, 190 );
			}
			NWC_Notify::workspace_owners( (int) $l->workspace_id, 'agency', $title, '', self::deep_link( $event, $data, self::EVENT_TEXT[ $event ][1] ), 'wd:' . $id, in_array( $event, array( 'invoice.shared', 'invoice.resent' ), true ) );
		}
		return array( 'ok' => true );
	}
}

/**
 * Outbox for the consolidated copy in WorkDesk. Only specifically listed lead and booking fields go,
 * only after the workspace accepted the data-sharing notice, and with retries. Personal calendars,
 * notes, invoices and anything else are never queued: there is no code path that sends them.
 */
class NWC_Sync {

	public static function enqueue( $workspace_id, $entity, $entity_id, $deleted = false ) {
		global $wpdb;
		$w = NWC_Tenancy::workspace( $workspace_id );
		if ( $w && 'personal' === $w->account_type ) {
			return; // The Personal area is never shared with WorkDesk.
		}
		$t   = NWC_DB::t( 'sync_outbox' );
		$now = NWC::now();
		$row = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM $t WHERE entity = %s AND entity_id = %d", $entity, $entity_id ) ); // phpcs:ignore
		$d   = array( 'workspace_id' => (int) $workspace_id, 'entity' => $entity, 'entity_id' => (int) $entity_id, 'deleted' => $deleted ? 1 : 0, 'status' => 'pending', 'attempts' => 0, 'next_attempt_at' => $now, 'last_error' => '', 'updated_at' => $now );
		if ( $row ) {
			$wpdb->update( $t, $d, array( 'id' => $row ) );
		} else {
			$wpdb->insert( $t, $d );
		}
	}

	private static function lead_payload( $w, $id, $deleted ) {
		global $wpdb;
		if ( $deleted ) {
			return array( 'workspace_uid' => $w->uid, 'source_id' => (int) $id, 'deleted' => true );
		}
		$l = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . NWC_DB::t( 'leads' ) . ' WHERE id = %d AND workspace_id = %d', $id, $w->id ) ); // phpcs:ignore
		if ( ! $l || $l->deleted_at ) {
			return array( 'workspace_uid' => $w->uid, 'source_id' => (int) $id, 'deleted' => true );
		}
		$biz = NWC_Bookings::business( $w->id );
		$who = $l->assigned_to ? get_userdata( $l->assigned_to ) : null;
		return array( 'workspace_uid' => $w->uid, 'source_id' => (int) $l->id, 'business_name' => $biz ? $biz->display_name : $w->name, 'customer_name' => $l->name, 'contact_number' => $l->phone, 'email' => $l->email, 'enquiry' => $l->enquiry, 'lead_type' => $l->lead_type, 'lead_source' => $l->source, 'status' => $l->stage, 'lead_date' => $l->created_at, 'assigned_to' => $who ? $who->display_name : '', 'marketing_consent' => (bool) $l->marketing_consent );
	}

	private static function booking_payload( $w, $id ) {
		global $wpdb;
		$b = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . NWC_DB::t( 'bookings' ) . ' WHERE id = %d AND workspace_id = %d', $id, $w->id ) ); // phpcs:ignore
		if ( ! $b || $b->deleted_at ) {
			return array( 'workspace_uid' => $w->uid, 'source_id' => (int) $id, 'deleted' => true );
		}
		$s   = $wpdb->get_row( $wpdb->prepare( 'SELECT name, service_type FROM ' . NWC_DB::t( 'services' ) . ' WHERE id = %d', $b->service_id ) ); // phpcs:ignore
		$biz = NWC_Bookings::business( $w->id );
		return array( 'workspace_uid' => $w->uid, 'source_id' => (int) $b->id, 'business_name' => $biz ? $biz->display_name : $w->name, 'customer_name' => $b->customer_name, 'contact_number' => $b->phone, 'email' => $b->email, 'service' => $s ? $s->name : '', 'booking_type' => $s ? $s->service_type : '', 'booking_at' => $b->starts_at, 'status' => $b->status );
	}

	public static function directory_payload( $w ) {
		$sub = 'agency' === $w->account_type ? null : NWC_Billing::subscription( $w->id );
		// A keyed digest of the owner's address lets WorkDesk group a client's Workspaces. The address itself is not sent.
		list( , $secret ) = NWC_WorkDesk::site_credentials();
		$owner = get_userdata( (int) $w->created_by );
		$hash  = $owner && '' !== (string) $secret ? hash_hmac( 'sha256', strtolower( trim( (string) $owner->user_email ) ), $secret ) : '';
		return array( 'workspace_uid' => $w->uid, 'name' => $w->name, 'account_type' => $w->account_type, 'plan_code' => $sub ? $sub->plan_code : '', 'subscription_status' => $sub ? $sub->status : '', 'period_start' => $sub ? $sub->period_start : '', 'period_end' => $sub ? $sub->period_end : '', 'billing_cycle' => $sub ? $sub->billing_cycle : '', 'grace_days' => $sub ? (int) $sub->grace_days : '', 'extended_until' => $sub ? $sub->extended_until : '', 'owner_hash' => $hash, 'sharing_consent_at' => $w->sharing_consent_at, 'business_category' => NWC_Industry::config( $w )['category'], 'workspace_status' => $w->status );
	}

	/** Runs from cron. Without credentials everything simply waits. */
	public static function process( $limit = 100 ) {
		global $wpdb;
		list( $key, $secret ) = NWC_WorkDesk::site_credentials();
		if ( '' === NWC_WorkDesk::base_url() || '' === (string) $key || '' === (string) $secret ) {
			return array( 'skipped' => 'not configured' );
		}
		$t    = NWC_DB::t( 'sync_outbox' );
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $t WHERE status = 'pending' AND next_attempt_at <= %s ORDER BY id ASC LIMIT %d", NWC::now(), $limit ) ); // phpcs:ignore
		$groups = array( 'workspace' => array(), 'lead' => array(), 'booking' => array() );
		foreach ( $rows as $o ) {
			$w = NWC_Tenancy::workspace( $o->workspace_id );
			if ( ! $w ) {
				$wpdb->delete( $t, array( 'id' => $o->id ) );
				continue;
			}
			if ( 'workspace' === $o->entity ) {
				$groups['workspace'][] = array( $o, self::directory_payload( $w ) );
			} elseif ( 'agency' !== $w->account_type ) {
				// A self-purchased Workspace is not managed by the agency: its leads and bookings never go to WorkDesk.
				$wpdb->delete( $t, array( 'id' => $o->id ) );
			} elseif ( 'lead' === $o->entity ) {
				$groups['lead'][] = array( $o, self::lead_payload( $w, $o->entity_id, (bool) $o->deleted ) );
			} elseif ( 'booking' === $o->entity ) {
				$groups['booking'][] = array( $o, self::booking_payload( $w, $o->entity_id ) );
			}
		}
		$paths = array( 'workspace' => '/sync/workspaces', 'lead' => '/sync/leads', 'booking' => '/sync/bookings' );
		$sent  = 0;
		foreach ( $groups as $entity => $list ) {
			if ( ! $list ) {
				continue;
			}
			$res = NWC_WorkDesk::http( $key, $secret, 'POST', $paths[ $entity ], array( 'items' => wp_list_pluck( $list, 1 ) ) );
			foreach ( $list as $pair ) {
				$o = $pair[0];
				if ( is_wp_error( $res ) ) {
					$n = (int) $o->attempts + 1;
					$wpdb->update( $t, array( 'attempts' => $n, 'status' => $n > 8 ? 'failed' : 'pending', 'next_attempt_at' => gmdate( 'Y-m-d H:i:s', time() + min( 86400, 60 * pow( 3, $n ) ) ), 'last_error' => mb_substr( $res->get_error_message(), 0, 190 ), 'updated_at' => NWC::now() ), array( 'id' => $o->id ) );
				} else {
					$wpdb->delete( $t, array( 'id' => $o->id ) );
					$sent++;
				}
			}
		}
		return array( 'sent' => $sent );
	}

	/** Rows held by the old optional sharing switch (removed in Phase 5) are sent on. */
	public static function release_held( $workspace_id ) {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( 'UPDATE ' . NWC_DB::t( 'sync_outbox' ) . " SET status = 'pending', next_attempt_at = %s WHERE workspace_id = %d AND status = 'held'", NWC::now(), $workspace_id ) ); // phpcs:ignore
	}
}

/** In-app notifications and email. Email goes out only when an administrator has switched it on. */
class NWC_Notify {

	public static function enabled() {
		return (bool) NWC::settings()['email_enabled'];
	}

	/** Categories a person can switch off, per Personal area and per Workspace. */
	const CATEGORIES = array(
		'task'      => 'Tasks assigned to me',
		'booking'   => 'Bookings',
		'lead'      => 'New enquiries',
		'form'      => 'Form entries',
		'invoice'   => 'Invoices and payments',
		'billing'   => 'Subscription and billing',
		'agency'    => 'NeonDigital updates',
		'meeting'   => 'Meetings',
		'reminder'  => 'Reminders',
		'retention' => 'Data clean-up',
		'operations' => 'Orders, stays, memberships and fees',
	);

	const META = 'nwc_notify_prefs';

	public static function routes() {
		NWC_REST::route( '/me/notifications', 'GET', array( __CLASS__, 'rest_all' ), 'user' );
		NWC_REST::route( '/me/notifications/read', 'POST', array( __CLASS__, 'rest_read' ), 'user' );
		NWC_REST::route( '/me/notifications/count', 'GET', array( __CLASS__, 'rest_count' ), 'user' );
		NWC_REST::route( '/me/notification-preferences', 'GET', array( __CLASS__, 'rest_prefs' ), 'user' );
		NWC_REST::route( '/me/notification-preferences', 'PUT', array( __CLASS__, 'rest_save_prefs' ), 'user' );
	}

	/** Workspace ids (Personal included) this person can open right now. */
	public static function contexts( $user_id ) {
		global $wpdb;
		$personal_ok = NWC_Personal::eligible( $user_id );
		$out         = array();
		foreach ( $wpdb->get_results( $wpdb->prepare( 'SELECT w.id, w.account_type FROM ' . NWC_DB::t( 'members' ) . ' m JOIN ' . NWC_DB::t( 'workspaces' ) . " w ON w.id = m.workspace_id WHERE m.user_id = %d AND m.status = 'active' AND w.status = 'active'", $user_id ) ) as $x ) { // phpcs:ignore
			if ( 'personal' !== $x->account_type || $personal_ok ) {
				$out[] = (int) $x->id;
			}
		}
		return $out;
	}

	public static function prefs( $user_id, $workspace_id ) {
		$all = get_user_meta( $user_id, self::META, true );
		$off = is_array( $all ) && isset( $all[ $workspace_id ] ) && is_array( $all[ $workspace_id ] ) ? $all[ $workspace_id ] : array();
		$out = array();
		foreach ( self::CATEGORIES as $k => $label ) {
			$out[ $k ] = empty( $off[ $k ] );
		}
		return $out;
	}

	/** The originating context's saved choice decides. Kinds outside the list always arrive. */
	public static function wants( $user_id, $workspace_id, $kind ) {
		if ( ! isset( self::CATEGORIES[ $kind ] ) ) {
			return true;
		}
		return self::prefs( $user_id, $workspace_id )[ $kind ];
	}

	private static function check_context( $user_id, $workspace_id ) {
		return in_array( (int) $workspace_id, self::contexts( $user_id ), true );
	}

	public static function rest_prefs( WP_REST_Request $r ) {
		$uid = get_current_user_id();
		$ws  = absint( $r->get_param( 'workspace_id' ) );
		if ( ! self::check_context( $uid, $ws ) ) {
			return NWC::err( 'Workspace not found.', 404, 'nwc_not_found' );
		}
		$p   = self::prefs( $uid, $ws );
		$out = array();
		foreach ( self::CATEGORIES as $k => $label ) {
			$out[] = array( 'key' => $k, 'label' => $label, 'on' => $p[ $k ] );
		}
		return array( 'workspace_id' => $ws, 'categories' => $out );
	}

	public static function rest_save_prefs( WP_REST_Request $r ) {
		$uid = get_current_user_id();
		$b   = NWC_REST::body( $r );
		$ws  = absint( $b['workspace_id'] ?? 0 );
		if ( ! self::check_context( $uid, $ws ) ) {
			return NWC::err( 'Workspace not found.', 404, 'nwc_not_found' );
		}
		$all = get_user_meta( $uid, self::META, true );
		$all = is_array( $all ) ? $all : array();
		$off = array();
		foreach ( (array) ( $b['categories'] ?? array() ) as $k => $on ) {
			if ( isset( self::CATEGORIES[ $k ] ) && ! $on ) {
				$off[ $k ] = 1;
			}
		}
		$all[ $ws ] = $off;
		update_user_meta( $uid, self::META, $all );
		NWC::audit( 'notification_preferences_saved', array( 'workspace_id' => $ws, 'kind' => 'activity', 'user_id' => $uid, 'details' => array( 'off' => array_keys( $off ) ) ) );
		$r2 = new WP_REST_Request( 'GET' );
		$r2->set_param( 'workspace_id', $ws );
		return self::rest_prefs( $r2 );
	}

	/** Every notification for this person, from every Workspace they can still open. */
	public static function rest_all( WP_REST_Request $r ) {
		global $wpdb;
		$uid = get_current_user_id();
		$ids = self::contexts( $uid );
		if ( ! $ids ) {
			return array( 'items' => array(), 'unread' => 0 );
		}
		$t     = NWC_DB::t( 'notifications' );
		$w     = NWC_DB::t( 'workspaces' );
		$in    = implode( ',', array_map( 'intval', $ids ) );
		$vals  = array( $uid );
		$where = "n.user_id = %d AND n.workspace_id IN ($in)";
		$only  = absint( $r->get_param( 'workspace_id' ) );
		if ( $only ) {
			$where .= ' AND n.workspace_id = %d';
			$vals[] = $only;
		}
		if ( $r->get_param( 'unread' ) ) {
			$where .= ' AND n.read_at IS NULL';
		}
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT n.id, n.workspace_id, n.kind, n.title, n.body, n.link, n.read_at, n.created_at, w.name AS workspace_name, w.account_type FROM $t n JOIN $w w ON w.id = n.workspace_id WHERE $where ORDER BY n.id DESC LIMIT 150", $vals ) ); // phpcs:ignore
		$module = (string) $r->get_param( 'module' );
		if ( '' !== $module ) {
			$rows = array_values( array_filter( $rows, function ( $x ) use ( $module ) {
				return self::module_of( $x->link, $x->kind ) === $module;
			} ) );
		}
		foreach ( $rows as $x ) {
			$x->id           = (int) $x->id;
			$x->workspace_id = (int) $x->workspace_id;
			$x->created_at   = $x->created_at ? str_replace( ' ', 'T', $x->created_at ) . 'Z' : null;
			if ( 'personal' === $x->account_type ) {
				$x->workspace_name = 'Personal';
			}
		}
		return array( 'items' => $rows, 'unread' => self::unread( $uid ) );
	}

	public static function unread( $user_id, $workspace_id = 0 ) {
		global $wpdb;
		$ids = self::contexts( $user_id );
		if ( ! $ids ) {
			return 0;
		}
		$in = implode( ',', array_map( 'intval', $ids ) );
		if ( $workspace_id ) {
			return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . NWC_DB::t( 'notifications' ) . " WHERE user_id = %d AND workspace_id = %d AND workspace_id IN ($in) AND read_at IS NULL", $user_id, $workspace_id ) ); // phpcs:ignore
		}
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . NWC_DB::t( 'notifications' ) . " WHERE user_id = %d AND workspace_id IN ($in) AND read_at IS NULL", $user_id ) ); // phpcs:ignore
	}

	/** Kinds without a link still belong to a module. */
	const KIND_MODULE = array( 'booking' => '/bookings', 'lead' => '/crm', 'task' => '/tasks', 'invoice' => '/invoices', 'form' => '/forms', 'meeting' => '/meetings', 'agency' => '/agency', 'billing' => '/billing', 'retention' => '/settings' );

	/**
	 * Phase 5: the sidebar module a notification belongs to, from the record it opens (its link),
	 * so a count beside Bookings means unread booking notifications, and nothing is counted twice.
	 */
	public static function module_of( $link, $kind ) {
		$l = (string) $link;
		if ( preg_match( '~^#/projects/\d+\?task=~', $l ) ) {
			return '/tasks';
		}
		if ( preg_match( '~^#/m/([a-z0-9_-]+)~', $l, $m ) ) {
			return '/m/' . $m[1];
		}
		if ( preg_match( '~^#/([a-z0-9_-]+)~', $l, $m ) ) {
			return '/' . $m[1];
		}
		return self::KIND_MODULE[ (string) $kind ] ?? '';
	}

	public static function rest_count( WP_REST_Request $r = null ) {
		global $wpdb;
		$uid = get_current_user_id();
		$by  = array();
		$mod = array();
		$ids = self::contexts( $uid );
		if ( $ids ) {
			$in = implode( ',', array_map( 'intval', $ids ) );
			foreach ( $wpdb->get_results( $wpdb->prepare( 'SELECT workspace_id, COUNT(*) AS n FROM ' . NWC_DB::t( 'notifications' ) . " WHERE user_id = %d AND workspace_id IN ($in) AND read_at IS NULL GROUP BY workspace_id", $uid ) ) as $x ) { // phpcs:ignore
				$by[ (int) $x->workspace_id ] = (int) $x->n;
			}
			// Per sidebar module, for the Workspace that is open (only one the person can open).
			$here = $r ? absint( $r->get_param( 'workspace_id' ) ) : 0;
			if ( $here && in_array( $here, $ids, true ) ) {
				foreach ( $wpdb->get_results( $wpdb->prepare( 'SELECT link, kind, COUNT(*) AS n FROM ' . NWC_DB::t( 'notifications' ) . ' WHERE user_id = %d AND workspace_id = %d AND read_at IS NULL GROUP BY link, kind', $uid, $here ) ) as $x ) { // phpcs:ignore
					$k = self::module_of( $x->link, $x->kind );
					if ( '' !== $k ) {
						$mod[ $k ] = ( $mod[ $k ] ?? 0 ) + (int) $x->n;
					}
				}
			}
		}
		return array( 'unread' => array_sum( $by ), 'by_workspace' => (object) $by, 'by_module' => (object) $mod, 'latest_id' => (int) $wpdb->get_var( $wpdb->prepare( 'SELECT MAX(id) FROM ' . NWC_DB::t( 'notifications' ) . ' WHERE user_id = %d', $uid ) ) ); // phpcs:ignore
	}

	/** Marks one (by id), one context's, or all of this person's notifications read. Never anyone else's. */
	public static function rest_read( WP_REST_Request $r ) {
		global $wpdb;
		$uid = get_current_user_id();
		$b   = NWC_REST::body( $r );
		$t   = NWC_DB::t( 'notifications' );
		if ( ! empty( $b['id'] ) ) {
			$wpdb->query( $wpdb->prepare( "UPDATE $t SET read_at = %s WHERE id = %d AND user_id = %d AND read_at IS NULL", NWC::now(), absint( $b['id'] ), $uid ) ); // phpcs:ignore
		} elseif ( ! empty( $b['workspace_id'] ) && ! empty( $b['module'] ) ) {
			foreach ( $wpdb->get_results( $wpdb->prepare( "SELECT id, link, kind FROM $t WHERE workspace_id = %d AND user_id = %d AND read_at IS NULL", absint( $b['workspace_id'] ), $uid ) ) as $x ) { // phpcs:ignore
				if ( self::module_of( $x->link, $x->kind ) === (string) $b['module'] ) {
					$wpdb->update( $t, array( 'read_at' => NWC::now() ), array( 'id' => (int) $x->id, 'user_id' => $uid ) );
				}
			}
		} elseif ( ! empty( $b['workspace_id'] ) ) {
			$wpdb->query( $wpdb->prepare( "UPDATE $t SET read_at = %s WHERE workspace_id = %d AND user_id = %d AND read_at IS NULL", NWC::now(), absint( $b['workspace_id'] ), $uid ) ); // phpcs:ignore
		} else {
			$wpdb->query( $wpdb->prepare( "UPDATE $t SET read_at = %s WHERE user_id = %d AND read_at IS NULL", NWC::now(), $uid ) ); // phpcs:ignore
		}
		return array( 'ok' => true, 'unread' => self::unread( $uid ) );
	}

	public static function user( $workspace_id, $user_id, $kind, $title, $body = '', $link = '', $dedupe = null ) {
		global $wpdb;
		if ( ! self::wants( (int) $user_id, (int) $workspace_id, (string) $kind ) ) {
			return;
		}
		$wpdb->suppress_errors( true );
		$wpdb->insert( NWC_DB::t( 'notifications' ), array( 'workspace_id' => (int) $workspace_id, 'user_id' => (int) $user_id, 'kind' => $kind, 'title' => mb_substr( $title, 0, 190 ), 'body' => $body, 'link' => $link, 'dedupe' => $dedupe, 'created_at' => NWC::now() ) );
		$wpdb->suppress_errors( false );
	}

	public static function workspace_owners( $workspace_id, $kind, $title, $body = '', $link = '', $dedupe = null, $email = false ) {
		global $wpdb;
		$ids = $wpdb->get_col( $wpdb->prepare( 'SELECT user_id FROM ' . NWC_DB::t( 'members' ) . " WHERE workspace_id = %d AND status = 'active' AND role IN ('owner','admin')", $workspace_id ) ); // phpcs:ignore
		foreach ( $ids as $u ) {
			self::user( $workspace_id, $u, $kind, $title, $body, $link, $dedupe ? $dedupe . ':' . $u : null );
			if ( $email ) {
				$usr = get_userdata( $u );
				if ( $usr ) {
					self::email( $usr->user_email, $title, '<p>' . esc_html( $body ) . '</p>' );
				}
			}
		}
	}

	/** @return bool Whether it was handed to the mailer. */
	public static function email( $to, $subject, $html, $from_name = 'Neon Workspace' ) {
		if ( ! self::enabled() ) {
			return false;
		}
		$headers = array( 'Content-Type: text/html; charset=UTF-8', 'From: ' . preg_replace( '/[\r\n<>"]/', '', (string) $from_name ) . ' <' . get_option( 'admin_email' ) . '>' );
		return (bool) wp_mail( $to, $subject, $html, $headers );
	}

	/** Booking reminders and event/task reminders, each sent once. */
	public static function reminders() {
		global $wpdb;
		$now  = time();
		$soon = gmdate( 'Y-m-d H:i:s', $now + DAY_IN_SECONDS );
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . NWC_DB::t( 'bookings' ) . " WHERE status = 'confirmed' AND reminder_sent = 0 AND deleted_at IS NULL AND starts_at BETWEEN %s AND %s LIMIT 200", NWC::now(), $soon ) ); // phpcs:ignore
		foreach ( $rows as $b ) {
			$w   = NWC_Tenancy::workspace( $b->workspace_id );
			$biz = NWC_Bookings::business( $b->workspace_id );
			if ( $b->email && $w && $biz ) {
				$when = ( new DateTimeImmutable( $b->starts_at . ' UTC' ) )->setTimezone( new DateTimeZone( $w->timezone ) )->format( 'j M Y, g:i A' );
				self::email( $b->email, 'Reminder: your booking with ' . $biz->display_name, '<p>This is a reminder of your booking on <b>' . esc_html( $when ) . '</b>.</p>', $biz->display_name );
			}
			$wpdb->update( NWC_DB::t( 'bookings' ), array( 'reminder_sent' => 1 ), array( 'id' => $b->id ) );
		}
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . NWC_DB::t( 'events' ) . ' WHERE reminder_minutes IS NOT NULL AND deleted_at IS NULL AND starts_at <= %s', gmdate( 'Y-m-d H:i:s', $now + 31 * DAY_IN_SECONDS ) ) ); // phpcs:ignore
		foreach ( $rows as $e ) {
			foreach ( NWC_Calendar::occurrences( $e, gmdate( 'Y-m-d', $now ), gmdate( 'Y-m-d', $now + 31 * DAY_IN_SECONDS ) ) as $occ ) {
				if ( $occ[0] < $now ) {
					continue;
				}
				if ( $occ[0] - (int) $e->reminder_minutes * 60 <= $now ) {
					$for = gmdate( 'Y-m-d H:i:s', $occ[0] );
					if ( $e->reminder_sent_for !== $for ) {
						self::user( (int) $e->workspace_id, (int) $e->owner_id, 'reminder', 'Reminder: ' . $e->title, '', '#/calendar', 'evt:' . $e->id . ':' . $for );
						$wpdb->update( NWC_DB::t( 'events' ), array( 'reminder_sent_for' => $for ), array( 'id' => $e->id ) );
					}
				}
				break;
			}
		}
	}
}
