<?php
/**
 * Front-end routes and PWA endpoints. The plugin decides what a URL means and checks access; the
 * theme only draws it (nw-templates/<route>.php). With another theme active, routes still answer
 * from the plugin's own minimal page and no data is affected.
 */

defined( 'ABSPATH' ) || exit;

class NWC_Public {

	const SW_VERSION = '2';

	public static function init() {
		add_action( 'init', array( __CLASS__, 'rewrites' ) );
		add_filter( 'query_vars', function ( $v ) {
			return array_merge( $v, array( 'nwc_route', 'nwc_a', 'nwc_b', 'nwc_c' ) );
		} );
		add_filter( 'request', array( __CLASS__, 'map_host' ) );
		add_action( 'template_redirect', array( __CLASS__, 'dispatch' ), 1 );
		add_action( 'admin_init', array( __CLASS__, 'keep_out_of_admin' ) );
		add_filter( 'show_admin_bar', function ( $s ) {
			return current_user_can( 'manage_options' ) ? $s : false;
		} );
		add_filter( 'login_redirect', function ( $to, $req, $user ) {
			return $user instanceof WP_User && ! user_can( $user, 'manage_options' ) ? home_url( '/app/' ) : $to;
		}, 20, 3 );
	}

	public static function rewrites() {
		$rules = array(
			'^app/?$'                                  => 'index.php?nwc_route=app',
			'^login/?$'                                => 'index.php?nwc_route=login',
			'^register/?$'                             => 'index.php?nwc_route=register',
			'^pricing/?$'                              => 'index.php?nwc_route=pricing',
			'^connect/?$'                              => 'index.php?nwc_route=connect',
			'^terms-and-conditions/?$'                 => 'index.php?nwc_route=legal&nwc_a=terms',
			'^privacy-policy/?$'                       => 'index.php?nwc_route=legal&nwc_a=privacy',
			'^auth/(google|facebook)/(start|callback)/?$' => 'index.php?nwc_route=oauth&nwc_a=$matches[1]&nwc_b=$matches[2]',
			'^auth/drive/callback/?$'                  => 'index.php?nwc_route=drive_cb',
			'^f/([a-z0-9-]+)/?$'                       => 'index.php?nwc_route=form&nwc_a=$matches[1]',
			'^r/([A-Za-z0-9_-]{20,})/?$'               => 'index.php?nwc_route=retention_dl&nwc_a=$matches[1]',
			'^shared/([A-Za-z0-9_-]{20,})/?$'          => 'index.php?nwc_route=shared_file&nwc_a=$matches[1]',
			'^portal/([A-Za-z0-9_-]{20,})/?$'          => 'index.php?nwc_route=portal&nwc_a=$matches[1]',
			'^portal-access/([a-z0-9-]+)/?$'           => 'index.php?nwc_route=portal_access&nwc_a=$matches[1]',
			'^offline/?$'                              => 'index.php?nwc_route=offline',
			'^book/manage/([A-Za-z0-9_-]+)/?$'         => 'index.php?nwc_route=manage&nwc_a=$matches[1]',
			'^book/([a-z0-9-]+)/?$'                    => 'index.php?nwc_route=book&nwc_a=$matches[1]',
			'^menu/([a-z0-9-]+)/?$'                    => 'index.php?nwc_route=menu&nwc_a=$matches[1]',
			'^d/(i|q)/(\d+)/([a-f0-9]{64})(/pdf)?/?$'  => 'index.php?nwc_route=doc&nwc_a=$matches[1]&nwc_b=$matches[2]&nwc_c=$matches[3]&pdf=$matches[4]',
			'^join/([A-Za-z0-9_-]+)/?$'                => 'index.php?nwc_route=join&nwc_a=$matches[1]',
			'^team-invite/([A-Za-z0-9_-]+)/?$'         => 'index.php?nwc_route=team_invite&nwc_a=$matches[1]',
			'^manifest\.webmanifest$'                  => 'index.php?nwc_route=manifest',
			'^sw\.js$'                                 => 'index.php?nwc_route=sw',
		);
		foreach ( $rules as $re => $to ) {
			add_rewrite_rule( $re, $to, 'top' );
		}
		if ( get_option( 'nwc_rewrite_version' ) !== NWC_VERSION ) {
			flush_rewrite_rules( false );
			update_option( 'nwc_rewrite_version', NWC_VERSION );
		}
	}

	/**
	 * businessname.<base> and verified custom domains open that business's booking page.
	 * Inactive until wildcard DNS and SSL exist and NWC_BOOKING_BASE_DOMAIN is defined.
	 *
	 * @return string|null Booking page slug for a host.
	 */
	public static function slug_for_host( $host, $base = null ) {
		global $wpdb;
		$host = strtolower( preg_replace( '/:\d+$/', '', (string) $host ) );
		$base = null !== $base ? $base : ( defined( 'NWC_BOOKING_BASE_DOMAIN' ) ? NWC_BOOKING_BASE_DOMAIN : '' );
		$home = (string) wp_parse_url( home_url(), PHP_URL_HOST );
		if ( '' === $base || '' === $host || $host === $home ) {
			return null;
		}
		$suffix = '.' . strtolower( $base );
		if ( strlen( $host ) > strlen( $suffix ) && substr( $host, -strlen( $suffix ) ) === $suffix ) {
			$sub = substr( $host, 0, -strlen( $suffix ) );
			if ( ! preg_match( '/^[a-z0-9-]+$/', $sub ) || in_array( $sub, array( 'www', 'app', 'workspace', 'workdesk', 'api', 'mail' ), true ) ) {
				return null;
			}
			$page = NWC_Bookings::page_by_slug( $sub );
			return $page ? $page->slug : null;
		}
		$d = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . NWC_DB::t( 'domains' ) . ' WHERE host = %s AND verified_at IS NOT NULL', $host ) ); // phpcs:ignore
		if ( ! $d ) {
			return null;
		}
		$sql = 'SELECT slug FROM ' . NWC_DB::t( 'booking_pages' ) . ' WHERE workspace_id = %d AND is_active = 1 AND deleted_at IS NULL ' . ( $d->page_id ? 'AND id = %d' : 'AND is_default = 1 AND id > %d' ) . ' LIMIT 1';
		return $wpdb->get_var( $wpdb->prepare( $sql, $d->workspace_id, $d->page_id ? $d->page_id : 0 ) ); // phpcs:ignore
	}

	public static function map_host( $vars ) {
		if ( ! defined( 'NWC_BOOKING_BASE_DOMAIN' ) || empty( $_SERVER['HTTP_HOST'] ) ) {
			return $vars;
		}
		$slug = self::slug_for_host( sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ) ) );
		if ( $slug && empty( $vars['nwc_route'] ) ) {
			return array( 'nwc_route' => 'book', 'nwc_a' => $slug );
		}
		return $vars;
	}

	public static function keep_out_of_admin() {
		if ( wp_doing_ajax() || ( defined( 'DOING_CRON' ) && DOING_CRON ) ) {
			return;
		}
		if ( is_user_logged_in() && ! current_user_can( 'edit_posts' ) && ! current_user_can( 'manage_options' ) && ! current_user_can( NWC_Legal::CAP_EDIT ) ) {
			wp_safe_redirect( home_url( '/app/' ) );
			exit;
		}
	}

	private static function security_headers() {
		header( 'X-Frame-Options: SAMEORIGIN' );
		header( 'X-Content-Type-Options: nosniff' );
		header( 'Referrer-Policy: same-origin' );
	}

	public static function dispatch() {
		$route = get_query_var( 'nwc_route' );
		if ( ! $route ) {
			return;
		}
		if ( 'manifest' === $route ) {
			self::manifest();
		}
		if ( 'sw' === $route ) {
			self::service_worker();
		}
		if ( 'oauth' === $route ) {
			NWC_Social::dispatch( (string) get_query_var( 'nwc_a' ), (string) get_query_var( 'nwc_b' ) );
		}
		if ( 'drive_cb' === $route ) {
			NWC_Drive::callback();
		}
		if ( 'retention_dl' === $route ) {
			NWC_Retention::download( (string) get_query_var( 'nwc_a' ) );
		}
		if ( 'shared_file' === $route ) {
			NWC_Drive::shared_download( (string) get_query_var( 'nwc_a' ) );
		}
		// A form may opt in to being embedded on the business's own site; every other page stays unframeable.
		if ( ! ( 'form' === $route && isset( $_GET['embed'] ) ) ) { // phpcs:ignore
			self::security_headers();
		} else {
			header( 'X-Content-Type-Options: nosniff' );
			header( 'Referrer-Policy: same-origin' );
		}
		$ctx = array( 'route' => $route );
		switch ( $route ) {
			case 'pricing':
				$ctx['plans']  = NWC_Signup::public_plans();
				$ctx['addons'] = NWC_Signup::public_addons();
				$ctx['signed_in'] = is_user_logged_in();
				break;
			case 'form':
				$form = NWC_Forms::public_form( (string) get_query_var( 'nwc_a' ) );
				if ( ! $form ) {
					self::not_found();
				}
				$ctx['form']   = $form;
				$ctx['embed']  = isset( $_GET['embed'] ); // phpcs:ignore
				$ctx['config'] = array( 'root' => esc_url_raw( rest_url( NWC_REST::NS . '/' ) ) );
				break;
			case 'portal':
				nocache_headers();
				$ctx['token']  = (string) get_query_var( 'nwc_a' );
				$ctx['config'] = array( 'root' => esc_url_raw( rest_url( NWC_REST::NS . '/' ) ), 'token' => $ctx['token'] );
				break;
			case 'portal_access':
				nocache_headers();
				$ctx['slug']   = (string) get_query_var( 'nwc_a' );
				$ctx['config'] = array( 'root' => esc_url_raw( rest_url( NWC_REST::NS . '/' ) ), 'slug' => $ctx['slug'] );
				break;
			case 'app':
				nocache_headers();
				if ( ! is_user_logged_in() ) {
					wp_safe_redirect( home_url( '/login/' ) );
					exit;
				}
				$ctx['config'] = self::app_config();
				break;
			case 'login':
			case 'register':
				nocache_headers();
				$plan  = isset( $_GET['plan'] ) ? sanitize_key( wp_unslash( $_GET['plan'] ) ) : ''; // phpcs:ignore
				$cycle = isset( $_GET['cycle'] ) && 'year' === $_GET['cycle'] ? 'year' : 'month'; // phpcs:ignore
				if ( is_user_logged_in() ) {
					NWC_Signup::remember_plan( get_current_user_id(), $plan, $cycle );
					wp_safe_redirect( home_url( '/app/' ) );
					exit;
				}
				// Pricing comes first: a new account needs a chosen plan, or an invitation link to follow.
				if ( 'register' === $route && ! NWC_Signup::public_plan( $plan ) && empty( $_GET['next'] ) ) { // phpcs:ignore
					wp_safe_redirect( home_url( '/pricing/' ) );
					exit;
				}
				$ctx['plan']   = NWC_Signup::public_plan( $plan );
				$ctx['cycle']  = $cycle;
				$ctx['social'] = NWC_Social::enabled_providers();
				$ctx['error']  = isset( $_GET['auth_error'] ) ? NWC_Social::error_text( sanitize_key( wp_unslash( $_GET['auth_error'] ) ) ) : ''; // phpcs:ignore
				$ctx['next_path'] = (string) wp_parse_url( self::safe_next(), PHP_URL_PATH );
				$ctx['config'] = array( 'root' => esc_url_raw( rest_url( NWC_REST::NS . '/' ) ), 'home' => home_url( '/' ), 'next' => self::safe_next(), 'plan' => $ctx['plan'] ? $ctx['plan']['code'] : '', 'cycle' => $cycle );
				break;
			case 'book':
				$page = NWC_Bookings::page_by_slug( (string) get_query_var( 'nwc_a' ) );
				if ( ! $page ) {
					self::not_found();
				}
				$ctx['payload'] = NWC_Bookings::public_payload( $page );
				$ctx['slug']    = $page->slug;
				$ctx['config']  = array( 'root' => esc_url_raw( rest_url( NWC_REST::NS . '/' ) ) );
				break;
			case 'menu':
				$ctx['slug']   = sanitize_title( (string) get_query_var( 'nwc_a' ) );
				$ctx['config'] = array( 'root' => esc_url_raw( rest_url( NWC_REST::NS . '/' ) ), 'slug' => $ctx['slug'], 'table' => isset( $_GET['table'] ) ? sanitize_key( wp_unslash( $_GET['table'] ) ) : '' ); // phpcs:ignore
				break;
			case 'manage':
				nocache_headers();
				$ctx['token']  = (string) get_query_var( 'nwc_a' );
				$ctx['config'] = array( 'root' => esc_url_raw( rest_url( NWC_REST::NS . '/' ) ) );
				break;
			case 'doc':
				nocache_headers();
				$doc = NWC_Invoices::public_doc( (string) get_query_var( 'nwc_a' ), (int) get_query_var( 'nwc_b' ), (string) get_query_var( 'nwc_c' ) );
				if ( ! $doc ) {
					self::not_found();
				}
				if ( isset( $_SERVER['REQUEST_URI'] ) && false !== strpos( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ), '/pdf' ) ) {
					NWC_Invoices::stream_pdf( $doc['type'], $doc['doc'] );
					exit;
				}
				$ctx['doc'] = $doc;
				$ctx['config'] = array( 'root' => esc_url_raw( rest_url( NWC_REST::NS . '/' ) ) );
				if ( 'invoice' === $doc['type'] ) {
					$ctx['qr']  = NWC_Finance::invoice_qr( $doc['doc'] );
					$ctx['pay'] = NWC_Finance::online_pay_info( $doc['doc'] );
				}
				break;
			case 'legal':
				nocache_headers();
				$key = (string) get_query_var( 'nwc_a' );
				$ctx = array_merge( $ctx, NWC_Legal::page_context( $key ) );
				break;
			case 'join':
				nocache_headers();
				$ctx = array_merge( $ctx, self::join_context( (string) get_query_var( 'nwc_a' ) ) );
				break;
			case 'team_invite':
				nocache_headers();
				$ctx['token']  = (string) get_query_var( 'nwc_a' );
				$ctx['config'] = array( 'root' => esc_url_raw( rest_url( NWC_REST::NS . '/' ) ), 'nonce' => is_user_logged_in() ? wp_create_nonce( 'wp_rest' ) : '', 'email' => is_user_logged_in() ? wp_get_current_user()->user_email : '', 'login' => home_url( '/login/?next=' . rawurlencode( ( 'join' === $route ? '/join/' : '/team-invite/' ) . $ctx['token'] ) ), 'register' => home_url( '/register/?next=' . rawurlencode( ( 'join' === $route ? '/join/' : '/team-invite/' ) . $ctx['token'] ) ), 'app' => home_url( '/app/' ) );
				$ctx['signed_in'] = is_user_logged_in();
				break;
		}
		self::render( str_replace( '_', '-', $route ), $ctx );
	}

	/**
	 * What the invitation page may show. WorkDesk checks the token and supplies the details, so
	 * nothing about identity, client or features is ever taken from the URL.
	 */
	private static function join_context( $token ) {
		$signed = is_user_logged_in();
		$user   = wp_get_current_user();
		$ctx    = array(
			'token'       => $token,
			'signed_in'   => $signed,
			'social'      => NWC_Social::enabled_providers(),
			'privacy_url' => NWC_Legal::url( 'privacy' ),
			'logout_url'  => wp_logout_url( home_url( '/join/' . $token ) ),
			'invite'      => null,
			'config'      => array( 'root' => esc_url_raw( rest_url( NWC_REST::NS . '/' ) ), 'nonce' => $signed ? wp_create_nonce( 'wp_rest' ) : '', 'email' => $signed ? $user->user_email : '', 'login' => home_url( '/login/?next=' . rawurlencode( '/join/' . $token ) ), 'app' => home_url( '/app/' ) ),
		);
		$pv = NWC_WorkDesk::invite_preview( $token );
		if ( is_wp_error( $pv ) ) {
			$ctx['invite_error'] = $pv->get_error_message();
			return $ctx;
		}
		$ctx['invite']         = $pv;
		$ctx['email_mismatch'] = $signed && 0 !== strcasecmp( (string) $user->user_email, (string) $pv['email'] );
		$ctx['has_account']    = ! $signed && (bool) email_exists( $pv['email'] );
		$ctx['needs_legal']    = $signed && ! NWC_Legal::has_accepted_any( $user->ID );
		return $ctx;
	}

	/** Only a same-site path, so a sign-in link cannot redirect elsewhere. */
	private static function safe_next() {
		$n = isset( $_GET['next'] ) ? sanitize_text_field( wp_unslash( $_GET['next'] ) ) : ''; // phpcs:ignore
		return preg_match( '#^/[A-Za-z0-9/_-]*$#', $n ) && 0 !== strpos( $n, '//' ) ? home_url( $n ) : home_url( '/app/' );
	}

	public static function app_config() {
		return array(
			'root'     => esc_url_raw( rest_url( NWC_REST::NS . '/' ) ),
			'nonce'    => wp_create_nonce( 'wp_rest' ),
			'home'     => home_url( '/' ),
			'swUrl'    => home_url( '/sw.js' ),
			'logoutTo' => home_url( '/login/' ),
			'pricing'  => home_url( '/pricing/' ),
			'authBase' => home_url( '/auth/' ),
			'formBase' => home_url( '/f/' ),
			'social'   => NWC_Social::enabled_providers(),
			'bookBase' => home_url( '/book/' ),
		);
	}

	private static function not_found() {
		status_header( 404 );
		self::render( '404', array( 'route' => '404' ) );
	}

	private static function render( $name, $ctx ) {
		$file = locate_template( 'nw-templates/' . $name . '.php' );
		if ( $file ) {
			$GLOBALS['nw_ctx'] = $ctx;
			include $file;
		} else {
			status_header( 200 );
			echo '<!doctype html><meta name="viewport" content="width=device-width, initial-scale=1"><title>Neon Workspace</title><p style="font:16px system-ui;padding:24px">The Neon Workspace theme is not active. Your data is safe. Ask the site administrator to activate it.</p>';
		}
		exit;
	}

	public static function asset_url( $rel ) {
		return NWC_URL . 'assets/' . $rel;
	}

	private static function manifest() {
		header( 'Content-Type: application/manifest+json; charset=utf-8' );
		header( 'Cache-Control: public, max-age=3600' );
		$icons = array();
		foreach ( array( array( 'icon-192.png', '192x192', 'any' ), array( 'icon-512.png', '512x512', 'any' ), array( 'maskable-192.png', '192x192', 'maskable' ), array( 'maskable-512.png', '512x512', 'maskable' ) ) as $i ) {
			$icons[] = array( 'src' => self::asset_url( 'pwa/' . $i[0] ), 'sizes' => $i[1], 'type' => 'image/png', 'purpose' => $i[2] );
		}
		echo wp_json_encode( array(
			'name'             => 'Neon Workspace',
			'short_name'       => 'Workspace',
			'description'      => 'Your daily business workspace.',
			'start_url'        => wp_make_link_relative( home_url( '/app/' ) ) . '?source=pwa',
			'scope'            => wp_make_link_relative( home_url( '/' ) ),
			'display'          => 'standalone',
			'orientation'      => 'any',
			'background_color' => '#F7F5FB',
			'theme_color'      => '#251E35',
			'icons'            => $icons,
		), JSON_UNESCAPED_SLASHES );
		exit;
	}

	/**
	 * Caches versioned static files and the offline page only. Never HTML of signed-in pages, never
	 * API responses, never documents: customer data does not enter a cache.
	 */
	private static function service_worker() {
		header( 'Content-Type: application/javascript; charset=utf-8' );
		header( 'Cache-Control: no-cache' );
		header( 'Service-Worker-Allowed: /' );
		$offline = wp_make_link_relative( home_url( '/offline/' ) );
		$ver     = self::SW_VERSION . '-' . NWC_VERSION . '-' . wp_get_theme()->get( 'Version' );
		echo "'use strict';\nvar CACHE='nw-shell-" . esc_js( $ver ) . "';var OFFLINE=" . wp_json_encode( $offline, JSON_UNESCAPED_SLASHES ) . ";\n";
		?>
self.addEventListener('install',function(e){e.waitUntil(caches.open(CACHE).then(function(c){return c.add(new Request(OFFLINE,{credentials:'omit'}));}));});
self.addEventListener('activate',function(e){e.waitUntil(caches.keys().then(function(k){return Promise.all(k.filter(function(n){return n.indexOf('nw-shell-')===0&&n!==CACHE;}).map(function(n){return caches.delete(n);}));}).then(function(){return self.clients.claim();}));});
self.addEventListener('message',function(e){if(e.data==='skipWaiting')self.skipWaiting();});
function isStatic(u){return u.origin===self.location.origin&&/\/wp-content\/(themes\/neon-workspace|plugins\/neon-workspace-core)\/assets\//.test(u.pathname)&&/\.(css|js|png|svg|woff2?)$/.test(u.pathname);}
self.addEventListener('fetch',function(e){
	if(e.request.method!=='GET')return;
	var u=new URL(e.request.url);
	if(isStatic(u)){
		e.respondWith(fetch(e.request).then(function(r){if(r.ok){var c=r.clone();caches.open(CACHE).then(function(x){x.put(e.request,c);});}return r;}).catch(function(){return caches.match(e.request);}));
		return;
	}
	if(e.request.mode==='navigate'){
		e.respondWith(fetch(e.request).catch(function(){return caches.match(OFFLINE);}));
	}
});
		<?php
		exit;
	}
}
