<?php
/**
 * Files & Google Drive through the official Drive API v3. No third-party connector.
 *
 * Each business connects its own Google account. Consent is separate from Google sign-in and asks
 * only for drive.file, which covers files this app created or the user opened with it. Tokens stay
 * on the server, sealed. Workspace stores references and metadata, not file contents.
 *
 * Removing something here removes the Workspace reference. It never deletes the customer's file.
 *
 * Folder layout, created after the connection and kept in drive_folders:
 *   NeonDigital Workspace/            one per Google account, shared by all its Workspaces
 *     <Workspace name>/               one per Workspace
 *       <Project name>/               one per project of that Workspace
 * Each folder carries a private appProperties key, so a reconnect finds the same folders instead of
 * creating new ones. The hidden appDataFolder is never used: the folders are visible in Drive.
 */

defined( 'ABSPATH' ) || exit;

class NWC_Drive {

	const SCOPE       = 'https://www.googleapis.com/auth/drive.file';
	const API         = 'https://www.googleapis.com/drive/v3';
	const UPLOAD      = 'https://www.googleapis.com/upload/drive/v3/files';
	const FIELDS      = 'id,name,mimeType,size,parents,webViewLink,trashed';
	const FOLDER_MIME = 'application/vnd.google-apps.folder';
	const ROOT_NAME   = 'NeonDigital Workspace';

	public static function routes() {
		NWC_REST::route( '/drive', 'GET', array( __CLASS__, 'status' ) );
		NWC_REST::route( '/drive', 'DELETE', array( __CLASS__, 'disconnect' ) );
		NWC_REST::route( '/drive/connect', 'POST', array( __CLASS__, 'connect' ) );
		NWC_REST::route( '/drive/files', 'GET', array( __CLASS__, 'files' ) );
		NWC_REST::route( '/drive/files', 'POST', array( __CLASS__, 'upload' ) );
		NWC_REST::route( '/drive/folders', 'POST', array( __CLASS__, 'mkdir' ) );
		NWC_REST::route( '/drive/folders/sync', 'POST', array( __CLASS__, 'rest_sync_folders' ) );
		NWC_REST::route( '/drive/files/(?P<id>\d+)', 'PUT,PATCH', array( __CLASS__, 'update' ) );
		NWC_REST::route( '/drive/files/(?P<id>\d+)', 'DELETE', array( __CLASS__, 'remove_reference' ) );
		NWC_REST::route( '/drive/files/(?P<id>\d+)/download', 'GET', array( __CLASS__, 'download' ) );
		NWC_REST::route( '/drive/files/(?P<id>\d+)/share', 'POST', array( __CLASS__, 'share' ) );
		NWC_REST::route( '/drive/files/(?P<id>\d+)/share', 'DELETE', array( __CLASS__, 'unshare' ) );
	}

	private static function guard( $write = false ) {
		if ( ! NWC_Tenancy::can( $write ? 'tasks.write' : 'projects.read' ) ) {
			return NWC::err( 'Your role does not allow this.', 403, 'nwc_forbidden' );
		}
		return NWC_Features::guard( 'google_drive' );
	}

	public static function link( $ws ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . NWC_DB::t( 'drive_links' ) . " WHERE workspace_id = %d AND status = 'connected'", $ws ) ); // phpcs:ignore
	}

	public static function ready() {
		$c = NWC_Social::config( 'google' );
		return '' !== $c['id'] && '' !== $c['secret'];
	}

	public static function callback_url() {
		return home_url( '/auth/drive/callback/' );
	}

	/* ---------------------------- Connecting ---------------------------- */

	public static function status() {
		global $wpdb;
		$g = self::guard();
		if ( is_wp_error( $g ) ) {
			return $g;
		}
		$ws = (int) NWC_Tenancy::$current->id;
		$l  = self::link( $ws );
		$out = array( 'configured' => self::ready(), 'connected' => (bool) $l, 'can_manage' => NWC_Tenancy::can( 'settings.manage' ) );
		if ( ! $l ) {
			return $out;
		}
		$t    = NWC_DB::t( 'drive_files' );
		$root = self::mapped( $ws, $l->account_email, 'root', 0 );
		$mine = self::mapped( $ws, $l->account_email, 'workspace', $ws );
		$out += array(
			'account'    => $l->account_email,
			'folder_id'  => $l->folder_id,
			'root_link'  => $root ? ( $root->web_link ? $root->web_link : 'https://drive.google.com/drive/folders/' . rawurlencode( $root->drive_id ) ) : '',
			'workspace_link' => $mine ? ( $mine->web_link ? $mine->web_link : 'https://drive.google.com/drive/folders/' . rawurlencode( $mine->drive_id ) ) : '',
			'root_name'  => self::ROOT_NAME,
			'folders'    => array_map( function ( $f ) {
				return array( 'entity' => $f->entity, 'entity_id' => (int) $f->entity_id, 'name' => $f->name, 'drive_id' => $f->drive_id, 'web_link' => $f->web_link );
			}, $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . NWC_DB::t( 'drive_folders' ) . " WHERE workspace_id = %d AND account_email = %s AND entity = 'project' ORDER BY name ASC", $ws, $l->account_email ) ) ), // phpcs:ignore
			'file_count' => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $t WHERE workspace_id = %d AND is_folder = 0 AND removed_at IS NULL", $ws ) ), // phpcs:ignore
			'linked'     => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $t WHERE workspace_id = %d AND is_folder = 0 AND removed_at IS NULL AND entity <> ''", $ws ) ), // phpcs:ignore
			'last_error' => $l->last_error,
		);
		$about = self::call( $ws, 'GET', self::API . '/about?fields=storageQuota' );
		if ( ! is_wp_error( $about ) && isset( $about['storageQuota'] ) ) {
			$out['storage'] = array( 'used' => (float) ( $about['storageQuota']['usage'] ?? 0 ), 'limit' => isset( $about['storageQuota']['limit'] ) ? (float) $about['storageQuota']['limit'] : null );
		}
		return $out;
	}

	/** Separate consent from sign-in, for this one permission. */
	public static function connect() {
		$g = self::guard();
		if ( is_wp_error( $g ) ) {
			return $g;
		}
		if ( ! NWC_Tenancy::can( 'settings.manage' ) ) {
			return NWC::err( 'Ask the workspace owner to connect Google Drive.', 403, 'nwc_forbidden' );
		}
		if ( ! self::ready() ) {
			return NWC::err( 'Google Drive is not available yet. NeonDigital is setting it up.', 409, 'nwc_not_configured' );
		}
		$c        = NWC_Social::config( 'google' );
		$state    = bin2hex( random_bytes( 24 ) );
		$verifier = rtrim( strtr( base64_encode( random_bytes( 48 ) ), '+/', '-_' ), '=' ); // phpcs:ignore
		set_transient( 'nwc_drive_' . hash( 'sha256', $state ), array( 'ws' => (int) NWC_Tenancy::$current->id, 'user' => get_current_user_id(), 'verifier' => $verifier ), 600 );
		$challenge = rtrim( strtr( base64_encode( hash( 'sha256', $verifier, true ) ), '+/', '-_' ), '=' ); // phpcs:ignore
		return array( 'url' => 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query( array( 'client_id' => $c['id'], 'redirect_uri' => self::callback_url(), 'response_type' => 'code', 'scope' => self::SCOPE . ' email', 'access_type' => 'offline', 'include_granted_scopes' => 'true', 'prompt' => 'consent', 'state' => $state, 'code_challenge' => $challenge, 'code_challenge_method' => 'S256' ), '', '&', PHP_QUERY_RFC3986 ) );
	}

	/** Browser returns here. The person must be the signed-in user who started it. */
	public static function callback( $in = null ) {
		$in    = null === $in ? $_GET : $in; // phpcs:ignore
		$to    = home_url( '/app/#/files' );
		$state = isset( $in['state'] ) ? (string) $in['state'] : '';
		$key   = 'nwc_drive_' . hash( 'sha256', $state );
		$st    = '' !== $state ? get_transient( $key ) : false;
		$res   = 'state';
		if ( $st && is_user_logged_in() && get_current_user_id() === (int) $st['user'] && NWC_Tenancy::bind( (int) $st['ws'], (int) $st['user'] ) && NWC_Tenancy::can( 'settings.manage' ) ) {
			delete_transient( $key );
			$res = empty( $in['code'] ) ? 'denied' : self::finish_connect( (int) $st['ws'], (int) $st['user'], (string) $in['code'], $st['verifier'] );
		}
		if ( defined( 'NWC_TESTING' ) ) {
			return $res;
		}
		wp_safe_redirect( $to . '?drive=' . rawurlencode( $res ) );
		exit;
	}

	private static function finish_connect( $ws, $uid, $code, $verifier ) {
		global $wpdb;
		$c   = NWC_Social::config( 'google' );
		$res = wp_remote_post( 'https://oauth2.googleapis.com/token', array( 'timeout' => 15, 'body' => array( 'code' => $code, 'client_id' => $c['id'], 'client_secret' => $c['secret'], 'redirect_uri' => self::callback_url(), 'grant_type' => 'authorization_code', 'code_verifier' => $verifier ) ) );
		if ( is_wp_error( $res ) || 200 !== (int) wp_remote_retrieve_response_code( $res ) ) {
			return 'exchange';
		}
		$tok = json_decode( (string) wp_remote_retrieve_body( $res ), true );
		if ( empty( $tok['access_token'] ) || empty( $tok['refresh_token'] ) || false === strpos( (string) ( $tok['scope'] ?? '' ), self::SCOPE ) ) {
			return 'scope';
		}
		$email = '';
		$p     = explode( '.', (string) ( $tok['id_token'] ?? '' ) );
		if ( 3 === count( $p ) ) {
			$cl    = json_decode( (string) base64_decode( strtr( $p[1], '-_', '+/' ) ), true ); // phpcs:ignore
			$email = sanitize_email( (string) ( $cl['email'] ?? '' ) );
		}
		$now = NWC::now();
		$t   = NWC_DB::t( 'drive_links' );
		$row = array( 'account_email' => $email, 'refresh_token' => NWC::seal( $tok['refresh_token'] ), 'access_token' => NWC::seal( $tok['access_token'] ), 'expires_at' => time() + (int) ( $tok['expires_in'] ?? 3600 ) - 60, 'scope' => (string) $tok['scope'], 'status' => 'connected', 'connected_by' => $uid, 'last_error' => '', 'updated_at' => $now );
		if ( $wpdb->get_var( $wpdb->prepare( "SELECT workspace_id FROM $t WHERE workspace_id = %d", $ws ) ) ) { // phpcs:ignore
			$wpdb->update( $t, $row, array( 'workspace_id' => $ws ) );
		} else {
			$wpdb->insert( $t, array_merge( $row, array( 'workspace_id' => $ws, 'created_at' => $now ) ) );
		}
		$made = self::sync_folders( $ws );
		if ( is_wp_error( $made ) ) {
			$wpdb->update( $t, array( 'last_error' => mb_substr( $made->get_error_message(), 0, 190 ) ), array( 'workspace_id' => $ws ) );
		}
		NWC::audit( 'google_drive_connected', array( 'workspace_id' => $ws, 'kind' => 'admin', 'user_id' => $uid, 'details' => array( 'account' => $email ) ) );
		return 'ok';
	}

	/** Stops Workspace using the account and revokes the token. Files stay in the customer's Drive. */
	public static function disconnect() {
		global $wpdb;
		if ( ! NWC_Tenancy::can( 'settings.manage' ) ) {
			return NWC::err( 'Your role does not allow this.', 403, 'nwc_forbidden' );
		}
		$ws = (int) NWC_Tenancy::$current->id;
		$l  = self::link( $ws );
		if ( $l ) {
			wp_remote_post( 'https://oauth2.googleapis.com/revoke', array( 'timeout' => 10, 'body' => array( 'token' => NWC::unseal( $l->refresh_token ) ) ) );
			$wpdb->update( NWC_DB::t( 'drive_links' ), array( 'status' => 'disconnected', 'refresh_token' => null, 'access_token' => null, 'updated_at' => NWC::now() ), array( 'workspace_id' => $ws ) );
			NWC::audit( 'google_drive_disconnected', array( 'kind' => 'admin' ) );
		}
		return array( 'ok' => true );
	}

	/* ---------------------------- Managed folders ---------------------------- */

	private static function mapped( $ws, $email, $entity, $entity_id ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . NWC_DB::t( 'drive_folders' ) . ' WHERE workspace_id = %d AND account_email = %s AND entity = %s AND entity_id = %d', $ws, (string) $email, $entity, (int) $entity_id ) ); // phpcs:ignore
	}

	private static function remember( $ws, $email, $entity, $entity_id, $f ) {
		global $wpdb;
		$t   = NWC_DB::t( 'drive_folders' );
		$row = array( 'drive_id' => (string) $f['id'], 'name' => mb_substr( (string) ( $f['name'] ?? '' ), 0, 255 ), 'web_link' => esc_url_raw( (string) ( $f['webViewLink'] ?? '' ) ) );
		$old = self::mapped( $ws, $email, $entity, $entity_id );
		if ( $old ) {
			$wpdb->update( $t, $row, array( 'id' => $old->id ) );
		} else {
			$wpdb->insert( $t, array_merge( $row, array( 'workspace_id' => (int) $ws, 'account_email' => (string) $email, 'entity' => $entity, 'entity_id' => (int) $entity_id, 'created_at' => NWC::now() ) ) );
		}
	}

	/**
	 * Finds or creates one managed folder. Order: the stored mapping (if the folder still exists and
	 * is not in the bin), then a folder this app made earlier with the same key, then a new one.
	 *
	 * @return array|WP_Error Drive file {id,name,webViewLink}.
	 */
	public static function ensure_folder( $ws, $email, $entity, $entity_id, $name, $parent, $key, $share_root_from = true ) {
		global $wpdb;
		$have = self::mapped( $ws, $email, $entity, $entity_id );
		if ( ! $have && 'root' === $entity && $share_root_from ) { // Another Workspace on the same Google account already made it.
			$have = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . NWC_DB::t( 'drive_folders' ) . " WHERE account_email = %s AND entity = 'root' AND account_email <> '' ORDER BY id ASC LIMIT 1", (string) $email ) ); // phpcs:ignore
		}
		if ( $have ) {
			$f = self::call( $ws, 'GET', self::API . '/files/' . rawurlencode( $have->drive_id ) . '?fields=' . self::FIELDS );
			if ( ! is_wp_error( $f ) && empty( $f['trashed'] ) && ! empty( $f['id'] ) ) {
				if ( $name !== ( $f['name'] ?? '' ) && 'root' !== $entity ) { // Keep the folder name in step with the record.
					$r2 = self::call( $ws, 'PATCH', self::API . '/files/' . rawurlencode( $f['id'] ) . '?fields=' . self::FIELDS, array( 'name' => $name ) );
					$f  = is_wp_error( $r2 ) ? $f : $r2;
				}
				self::remember( $ws, $email, $entity, $entity_id, $f );
				return $f;
			}
			if ( is_wp_error( $f ) && 'nwc_not_found' !== $f->get_error_code() ) {
				return $f;
			}
		}
		$q   = "appProperties has { key='nwc_key' and value='" . $key . "' } and mimeType = '" . self::FOLDER_MIME . "' and trashed = false";
		$res = self::call( $ws, 'GET', add_query_arg( array( 'q' => rawurlencode( $q ), 'fields' => rawurlencode( 'files(' . self::FIELDS . ')' ), 'pageSize' => 5 ), self::API . '/files' ) );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		if ( ! empty( $res['files'][0]['id'] ) ) {
			$f = $res['files'][0];
		} else {
			$body = array( 'name' => $name, 'mimeType' => self::FOLDER_MIME, 'appProperties' => array( 'nwc_key' => $key ) );
			if ( $parent ) {
				$body['parents'] = array( $parent );
			}
			$f = self::call( $ws, 'POST', self::API . '/files?fields=' . self::FIELDS, $body );
			if ( is_wp_error( $f ) ) {
				return $f;
			}
		}
		self::remember( $ws, $email, $entity, $entity_id, $f );
		return $f;
	}

	/** Folder names: Drive allows almost anything, but slashes read badly in a path. */
	private static function folder_name( $s ) {
		$s = trim( str_replace( array( '/', '\\' ), '-', (string) $s ) );
		return '' === $s ? 'Untitled' : mb_substr( $s, 0, 200 );
	}

	/**
	 * Root, this Workspace's folder and one folder per project. Safe to repeat.
	 *
	 * @return array|WP_Error {root, workspace, projects}
	 */
	public static function sync_folders( $ws ) {
		global $wpdb;
		$l = self::link( $ws );
		if ( ! $l ) {
			return NWC::err( 'Google Drive is not connected.', 409, 'nwc_drive_off' );
		}
		$w     = NWC_Tenancy::workspace( $ws );
		$email = (string) $l->account_email;
		$root  = self::ensure_folder( $ws, $email, 'root', 0, self::ROOT_NAME, '', 'root:' . md5( strtolower( $email ) ) );
		if ( is_wp_error( $root ) ) {
			return $root;
		}
		$name = 'personal' === $w->account_type ? 'Personal' : $w->name;
		$mine = self::ensure_folder( $ws, $email, 'workspace', $ws, self::folder_name( $name ), $root['id'], 'ws:' . $w->uid );
		if ( is_wp_error( $mine ) ) {
			return $mine;
		}
		$wpdb->update( NWC_DB::t( 'drive_links' ), array( 'folder_id' => $mine['id'], 'last_error' => '' ), array( 'workspace_id' => $ws ) );
		self::store( $ws, $mine, (int) $l->connected_by, $root['id'] );
		$made = 0;
		foreach ( $wpdb->get_results( $wpdb->prepare( 'SELECT id, name FROM ' . NWC_DB::t( 'projects' ) . ' WHERE workspace_id = %d AND deleted_at IS NULL ORDER BY id ASC LIMIT 300', $ws ) ) as $p ) { // phpcs:ignore
			$f = self::project_folder( $ws, (int) $p->id );
			if ( is_wp_error( $f ) ) {
				return $f;
			}
			$made++;
		}
		return array( 'root' => $root['id'], 'workspace' => $mine['id'], 'projects' => $made );
	}

	/** A project's folder inside this Workspace's folder, made on first need. */
	public static function project_folder( $ws, $project_id ) {
		global $wpdb;
		$l = self::link( $ws );
		$p = $wpdb->get_row( $wpdb->prepare( 'SELECT id, name, parent_id FROM ' . NWC_DB::t( 'projects' ) . ' WHERE id = %d AND workspace_id = %d AND deleted_at IS NULL', $project_id, $ws ) ); // phpcs:ignore
		if ( ! $l || ! $p ) {
			return NWC::err( 'Project not found.', 404, 'nwc_not_found' );
		}
		$w      = NWC_Tenancy::workspace( $ws );
		$parent = $p->parent_id ? self::project_folder( $ws, (int) $p->parent_id ) : null; // Sub-projects sit inside their parent's folder.
		if ( is_wp_error( $parent ) ) {
			return $parent;
		}
		if ( ! $parent ) {
			$mine = self::mapped( $ws, $l->account_email, 'workspace', $ws );
			if ( ! $mine ) {
				$s = self::sync_folders( $ws );
				if ( is_wp_error( $s ) ) {
					return $s;
				}
				$mine = self::mapped( $ws, $l->account_email, 'workspace', $ws );
			}
			$parent = array( 'id' => $mine->drive_id );
		}
		$f = self::ensure_folder( $ws, (string) $l->account_email, 'project', (int) $p->id, self::folder_name( $p->name ), $parent['id'], 'ws:' . $w->uid . ':p:' . (int) $p->id );
		if ( ! is_wp_error( $f ) ) {
			self::store( $ws, $f, (int) $l->connected_by, $parent['id'] );
			$wpdb->update( NWC_DB::t( 'drive_files' ), array( 'entity' => 'project', 'entity_id' => (int) $p->id ), array( 'workspace_id' => $ws, 'drive_id' => $f['id'] ) );
		}
		return $f;
	}

	public static function rest_sync_folders() {
		$g = self::guard( true );
		if ( is_wp_error( $g ) ) {
			return $g;
		}
		return self::sync_folders( (int) NWC_Tenancy::$current->id );
	}

	/* ---------------------------- Talking to Google ---------------------------- */

	private static function access_token( $ws ) {
		global $wpdb;
		$l = self::link( $ws );
		if ( ! $l ) {
			return NWC::err( 'Google Drive is not connected.', 409, 'nwc_drive_off' );
		}
		if ( (int) $l->expires_at > time() ) {
			return NWC::unseal( $l->access_token );
		}
		$c   = NWC_Social::config( 'google' );
		$res = wp_remote_post( 'https://oauth2.googleapis.com/token', array( 'timeout' => 15, 'body' => array( 'client_id' => $c['id'], 'client_secret' => $c['secret'], 'refresh_token' => NWC::unseal( $l->refresh_token ), 'grant_type' => 'refresh_token' ) ) );
		$d   = is_wp_error( $res ) ? array() : json_decode( (string) wp_remote_retrieve_body( $res ), true );
		if ( empty( $d['access_token'] ) ) {
			if ( ! is_wp_error( $res ) && 'invalid_grant' === ( $d['error'] ?? '' ) ) {
				$wpdb->update( NWC_DB::t( 'drive_links' ), array( 'status' => 'disconnected', 'last_error' => 'Access was revoked in Google. Connect again.' ), array( 'workspace_id' => $ws ) );
				return NWC::err( 'Access to Google Drive was removed. Please connect it again.', 409, 'nwc_drive_off' );
			}
			return NWC::err( 'Could not reach Google Drive. Please try again.', 502, 'nwc_drive_error' );
		}
		$wpdb->update( NWC_DB::t( 'drive_links' ), array( 'access_token' => NWC::seal( $d['access_token'] ), 'expires_at' => time() + (int) ( $d['expires_in'] ?? 3600 ) - 60 ), array( 'workspace_id' => $ws ) );
		return $d['access_token'];
	}

	/** One API call. Backs off once on a rate limit, then tells the user to retry. */
	private static function call( $ws, $method, $url, $json = null, $raw = null, $content_type = null ) {
		$tok = self::access_token( $ws );
		if ( is_wp_error( $tok ) ) {
			return $tok;
		}
		$args = array( 'method' => $method, 'timeout' => 30, 'headers' => array( 'Authorization' => 'Bearer ' . $tok ) );
		if ( null !== $json ) {
			$args['headers']['Content-Type'] = 'application/json';
			$args['body'] = wp_json_encode( $json );
		} elseif ( null !== $raw ) {
			$args['headers']['Content-Type'] = $content_type;
			$args['body'] = $raw;
		}
		for ( $try = 0; $try < 2; $try++ ) {
			$res = wp_remote_request( $url, $args );
			if ( is_wp_error( $res ) ) {
				return NWC::err( 'Could not reach Google Drive. Please try again.', 502, 'nwc_drive_error' );
			}
			$code = (int) wp_remote_retrieve_response_code( $res );
			$body = (string) wp_remote_retrieve_body( $res );
			$d    = json_decode( $body, true );
			$why  = (string) ( $d['error']['errors'][0]['reason'] ?? '' );
			if ( 429 === $code || ( 403 === $code && in_array( $why, array( 'rateLimitExceeded', 'userRateLimitExceeded' ), true ) ) ) {
				if ( 0 === $try && ! defined( 'NWC_TESTING' ) ) {
					sleep( 1 );
				}
				continue;
			}
			break;
		}
		if ( 429 === $code || ( 403 === $code && in_array( $why, array( 'rateLimitExceeded', 'userRateLimitExceeded' ), true ) ) ) {
			return NWC::err( 'Google Drive is busy for this account. Please try again shortly.', 429, 'nwc_drive_rate' );
		}
		if ( 403 === $code && 'storageQuotaExceeded' === $why ) {
			return NWC::err( 'This Google Drive is full.', 409, 'nwc_drive_full' );
		}
		if ( 404 === $code ) {
			return NWC::err( 'That file is no longer available in Google Drive.', 404, 'nwc_not_found' );
		}
		if ( $code < 200 || $code >= 300 ) {
			return NWC::err( 'Google Drive refused this request.', 502, 'nwc_drive_error' );
		}
		return null === $d ? array( 'raw' => $body ) : $d;
	}

	/* ---------------------------- Files ---------------------------- */

	private static function store( $ws, $f, $uid, $parent = '' ) {
		global $wpdb;
		$t   = NWC_DB::t( 'drive_files' );
		$now = NWC::now();
		$row = array( 'name' => mb_substr( (string) $f['name'], 0, 255 ), 'mime' => mb_substr( (string) ( $f['mimeType'] ?? '' ), 0, 120 ), 'size' => (int) ( $f['size'] ?? 0 ), 'is_folder' => self::FOLDER_MIME === ( $f['mimeType'] ?? '' ) ? 1 : 0, 'parent_id' => (string) ( $f['parents'][0] ?? $parent ), 'web_link' => esc_url_raw( (string) ( $f['webViewLink'] ?? '' ) ), 'removed_at' => null, 'updated_at' => $now );
		$id  = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM $t WHERE workspace_id = %d AND drive_id = %s", $ws, $f['id'] ) ); // phpcs:ignore
		if ( $id ) {
			$wpdb->update( $t, $row, array( 'id' => $id ) );
			return (int) $id;
		}
		$wpdb->insert( $t, array_merge( $row, array( 'workspace_id' => $ws, 'drive_id' => $f['id'], 'created_by' => (int) $uid, 'created_at' => $now ) ) );
		return (int) $wpdb->insert_id;
	}

	private static function row( $id ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . NWC_DB::t( 'drive_files' ) . ' WHERE id = %d AND workspace_id = %d AND removed_at IS NULL', $id, NWC_Tenancy::$current->id ) ); // phpcs:ignore
	}

	private static function out( $x ) {
		return array( 'id' => (int) $x->id, 'drive_id' => $x->drive_id, 'name' => $x->name, 'mime' => $x->mime, 'size' => (int) $x->size, 'is_folder' => (bool) $x->is_folder, 'parent_id' => $x->parent_id, 'web_link' => $x->web_link, 'entity' => $x->entity, 'entity_id' => $x->entity_id ? (int) $x->entity_id : null, 'customer_id' => $x->customer_id ? (int) $x->customer_id : null, 'shared' => ! empty( $x->share_hash ) );
	}

	/**
	 * Lists references held here. With sync=1, also asks Google what this app can see in the folder,
	 * since drive.file only exposes those files.
	 */
	public static function files( WP_REST_Request $r ) {
		global $wpdb;
		$g = self::guard();
		if ( is_wp_error( $g ) ) {
			return $g;
		}
		$ws = (int) NWC_Tenancy::$current->id;
		$l  = self::link( $ws );
		if ( ! $l ) {
			return NWC::err( 'Google Drive is not connected.', 409, 'nwc_drive_off' );
		}
		$parent = sanitize_text_field( (string) ( $r['parent'] ?: $l->folder_id ) );
		if ( $r['sync'] && preg_match( '/^[A-Za-z0-9_-]+$/', $parent ) ) {
			$res = self::call( $ws, 'GET', add_query_arg( array( 'q' => rawurlencode( "'" . $parent . "' in parents and trashed = false" ), 'fields' => rawurlencode( 'files(' . self::FIELDS . ')' ), 'pageSize' => 200 ), self::API . '/files' ) );
			if ( is_wp_error( $res ) ) {
				return $res;
			}
			foreach ( (array) ( $res['files'] ?? array() ) as $f ) {
				self::store( $ws, $f, get_current_user_id(), $parent );
			}
		}
		$t    = NWC_DB::t( 'drive_files' );
		$vals = array( $ws );
		$sql  = 'workspace_id = %d AND removed_at IS NULL';
		if ( $r['q'] ) {
			$sql   .= ' AND name LIKE %s';
			$vals[] = '%' . $wpdb->esc_like( sanitize_text_field( (string) $r['q'] ) ) . '%';
		} elseif ( $r['entity'] ) {
			$sql   .= ' AND entity = %s AND entity_id = %d';
			$vals[] = sanitize_key( (string) $r['entity'] );
			$vals[] = absint( $r['entity_id'] );
		} else {
			$sql   .= ' AND parent_id = %s';
			$vals[] = $parent;
		}
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $t WHERE $sql ORDER BY is_folder DESC, name ASC LIMIT 500", $vals ) ); // phpcs:ignore
		return array( 'items' => array_map( array( __CLASS__, 'out' ), $rows ), 'parent' => $parent, 'root' => $l->folder_id );
	}

	public static function mkdir( WP_REST_Request $r ) {
		$g = self::guard( true );
		if ( is_wp_error( $g ) ) {
			return $g;
		}
		$ws = (int) NWC_Tenancy::$current->id;
		$l  = self::link( $ws );
		if ( ! $l ) {
			return NWC::err( 'Google Drive is not connected.', 409, 'nwc_drive_off' );
		}
		$b    = NWC_REST::body( $r );
		$name = mb_substr( trim( sanitize_text_field( (string) ( $b['name'] ?? '' ) ) ), 0, 190 );
		if ( '' === $name ) {
			return NWC::err( 'Give the folder a name.' );
		}
		$parent = self::known_parent( $ws, (string) ( $b['parent'] ?? '' ), $l );
		$f = self::call( $ws, 'POST', self::API . '/files?fields=' . self::FIELDS, array( 'name' => $name, 'mimeType' => self::FOLDER_MIME, 'parents' => array( $parent ) ) );
		if ( is_wp_error( $f ) ) {
			return $f;
		}
		return self::out( self::row( self::store( $ws, $f, get_current_user_id(), $parent ) ) );
	}

	/** A parent must be the app folder or a folder this workspace already references. */
	private static function known_parent( $ws, $parent, $l ) {
		global $wpdb;
		if ( '' !== $parent && $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . NWC_DB::t( 'drive_files' ) . ' WHERE workspace_id = %d AND drive_id = %s AND is_folder = 1 AND removed_at IS NULL', $ws, $parent ) ) ) { // phpcs:ignore
			return $parent;
		}
		return $l->folder_id;
	}

	/** Goes straight to the customer's Drive. Nothing is kept on this server. */
	public static function upload( WP_REST_Request $r ) {
		$g = self::guard( true );
		if ( is_wp_error( $g ) ) {
			return $g;
		}
		$ws = (int) NWC_Tenancy::$current->id;
		$l  = self::link( $ws );
		if ( ! $l ) {
			return NWC::err( 'Google Drive is not connected.', 409, 'nwc_drive_off' );
		}
		$files = $r->get_file_params();
		$file  = $files['file'] ?? null;
		if ( ! $file || ! empty( $file['error'] ) ) {
			return NWC::err( 'Choose a file to upload.' );
		}
		$v = NWC_Projects::validate_upload( (string) $file['name'], (string) $file['tmp_name'], (int) $file['size'] );
		if ( is_wp_error( $v ) ) {
			return $v;
		}
		$parent = self::known_parent( $ws, (string) $r['parent'], $l );
		if ( '' === (string) $r['parent'] && 'project' === (string) $r['entity'] && absint( $r['entity_id'] ) && NWC_Projects::can_see( absint( $r['entity_id'] ) ) ) {
			$pf = self::project_folder( $ws, absint( $r['entity_id'] ) );
			if ( ! is_wp_error( $pf ) ) {
				$parent = $pf['id'];
			}
		}
		$mime   = is_array( $v ) && ! empty( $v['mime'] ) ? (string) $v['mime'] : 'application/octet-stream';
		$bound  = 'nwc' . bin2hex( random_bytes( 12 ) );
		$body   = "--$bound\r\nContent-Type: application/json; charset=UTF-8\r\n\r\n" . wp_json_encode( array( 'name' => sanitize_file_name( (string) $file['name'] ), 'parents' => array( $parent ) ) ) . "\r\n--$bound\r\nContent-Type: $mime\r\n\r\n" . file_get_contents( $file['tmp_name'] ) . "\r\n--$bound--"; // phpcs:ignore
		$f      = self::call( $ws, 'POST', self::UPLOAD . '?uploadType=multipart&fields=' . self::FIELDS, null, $body, 'multipart/related; boundary=' . $bound );
		if ( is_wp_error( $f ) ) {
			return $f;
		}
		$id = self::store( $ws, $f, get_current_user_id(), $parent );
		NWC::audit( 'drive_file_uploaded', array( 'entity' => 'drive_file', 'entity_id' => $id, 'details' => array( 'name' => $f['name'] ) ) );
		$res = self::link_entity( $id, $r->get_params() );
		return is_wp_error( $res ) ? $res : self::out( self::row( $id ) );
	}

	private static function entity_table( $entity ) {
		$map = array( 'project' => 'projects', 'task' => 'tasks', 'booking' => 'bookings', 'event' => 'events', 'customer' => 'customers' );
		return isset( $map[ $entity ] ) ? NWC_DB::t( $map[ $entity ] ) : null;
	}

	private static function link_entity( $id, $in ) {
		global $wpdb;
		if ( ! array_key_exists( 'entity', $in ) ) {
			return true;
		}
		$entity = sanitize_key( (string) $in['entity'] );
		$eid    = absint( $in['entity_id'] ?? 0 );
		if ( '' === $entity ) {
			$wpdb->update( NWC_DB::t( 'drive_files' ), array( 'entity' => '', 'entity_id' => null ), array( 'id' => $id ) );
			return true;
		}
		$t = self::entity_table( $entity );
		if ( ! $t || ! $eid || ! $wpdb->get_var( $wpdb->prepare( "SELECT id FROM $t WHERE id = %d AND workspace_id = %d", $eid, NWC_Tenancy::$current->id ) ) ) { // phpcs:ignore
			return NWC::err( 'That record was not found.', 404, 'nwc_not_found' );
		}
		$wpdb->update( NWC_DB::t( 'drive_files' ), array( 'entity' => $entity, 'entity_id' => $eid ), array( 'id' => $id ) );
		return true;
	}

	/** Rename, move to a folder, or link to a record. */
	public static function update( WP_REST_Request $r ) {
		global $wpdb;
		$g = self::guard( true );
		if ( is_wp_error( $g ) ) {
			return $g;
		}
		$x = self::row( (int) $r['id'] );
		if ( ! $x ) {
			return NWC::err( 'File not found.', 404, 'nwc_not_found' );
		}
		$ws   = (int) $x->workspace_id;
		$b    = NWC_REST::body( $r );
		$meta = array();
		$url  = self::API . '/files/' . rawurlencode( $x->drive_id ) . '?fields=' . self::FIELDS;
		if ( isset( $b['name'] ) && '' !== trim( (string) $b['name'] ) ) {
			$meta['name'] = mb_substr( sanitize_text_field( (string) $b['name'] ), 0, 190 );
		}
		if ( isset( $b['parent'] ) ) {
			$l  = self::link( $ws );
			$to = $l ? self::known_parent( $ws, (string) $b['parent'], $l ) : '';
			if ( '' !== $to && $to !== $x->parent_id ) {
				$url .= '&addParents=' . rawurlencode( $to ) . ( $x->parent_id ? '&removeParents=' . rawurlencode( $x->parent_id ) : '' );
				$moved = $to;
			}
		}
		if ( $meta || isset( $moved ) ) {
			$f = self::call( $ws, 'PATCH', $url, (object) $meta );
			if ( is_wp_error( $f ) ) {
				return $f;
			}
			self::store( $ws, $f, (int) $x->created_by, $moved ?? $x->parent_id );
		}
		$res = self::link_entity( $x->id, $b );
		return is_wp_error( $res ) ? $res : self::out( self::row( $x->id ) );
	}

	/** Forgets the reference only. The file stays in Google Drive. */
	public static function remove_reference( WP_REST_Request $r ) {
		global $wpdb;
		$g = self::guard( true );
		if ( is_wp_error( $g ) ) {
			return $g;
		}
		$x = self::row( (int) $r['id'] );
		if ( ! $x ) {
			return NWC::err( 'File not found.', 404, 'nwc_not_found' );
		}
		$wpdb->update( NWC_DB::t( 'drive_files' ), array( 'removed_at' => NWC::now(), 'share_hash' => null, 'customer_id' => null ), array( 'id' => $x->id ) );
		NWC::audit( 'drive_reference_removed', array( 'entity' => 'drive_file', 'entity_id' => $x->id, 'details' => array( 'name' => $x->name ) ) );
		return array( 'ok' => true );
	}

	public static function download( WP_REST_Request $r ) {
		$g = self::guard();
		if ( is_wp_error( $g ) ) {
			return $g;
		}
		$x = self::row( (int) $r['id'] );
		if ( ! $x || (int) $x->is_folder ) {
			return NWC::err( 'File not found.', 404, 'nwc_not_found' );
		}
		return self::stream( $x );
	}

	private static function stream( $x ) {
		$d = self::call( (int) $x->workspace_id, 'GET', self::API . '/files/' . rawurlencode( $x->drive_id ) . '?alt=media' );
		if ( is_wp_error( $d ) ) {
			return $d;
		}
		$body = isset( $d['raw'] ) ? $d['raw'] : wp_json_encode( $d );
		if ( defined( 'NWC_TESTING' ) ) {
			return array( 'name' => $x->name, 'bytes' => strlen( $body ) );
		}
		header( 'Content-Type: application/octet-stream' );
		header( 'X-Content-Type-Options: nosniff' );
		header( 'Cache-Control: private, no-store' );
		header( 'Content-Disposition: attachment; filename="' . sanitize_file_name( $x->name ) . '"' );
		echo $body; // phpcs:ignore
		exit;
	}

	/** Shares through a Workspace link for one customer. Drive permissions are not changed. */
	public static function share( WP_REST_Request $r ) {
		global $wpdb;
		$g = self::guard( true );
		if ( is_wp_error( $g ) ) {
			return $g;
		}
		$x = self::row( (int) $r['id'] );
		if ( ! $x || (int) $x->is_folder ) {
			return NWC::err( 'File not found.', 404, 'nwc_not_found' );
		}
		$cid = absint( NWC_REST::body( $r )['customer_id'] ?? 0 );
		if ( $cid && ! $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . NWC_DB::t( 'customers' ) . ' WHERE id = %d AND workspace_id = %d', $cid, $x->workspace_id ) ) ) { // phpcs:ignore
			return NWC::err( 'Customer not found.', 404, 'nwc_not_found' );
		}
		$token = rtrim( strtr( base64_encode( random_bytes( 32 ) ), '+/', '-_' ), '=' ); // phpcs:ignore
		$wpdb->update( NWC_DB::t( 'drive_files' ), array( 'share_hash' => hash( 'sha256', $token ), 'customer_id' => $cid ? $cid : null ), array( 'id' => $x->id ) );
		update_option( 'nwc_drive_share_' . $x->id, NWC::seal( $token ), false );
		NWC::audit( 'drive_file_shared', array( 'entity' => 'drive_file', 'entity_id' => $x->id, 'details' => array( 'name' => $x->name, 'customer' => $cid ) ) );
		return array( 'url' => home_url( '/shared/' . $token . '/' ) );
	}

	public static function unshare( WP_REST_Request $r ) {
		global $wpdb;
		$g = self::guard( true );
		if ( is_wp_error( $g ) ) {
			return $g;
		}
		$x = self::row( (int) $r['id'] );
		if ( ! $x ) {
			return NWC::err( 'File not found.', 404, 'nwc_not_found' );
		}
		$wpdb->update( NWC_DB::t( 'drive_files' ), array( 'share_hash' => null, 'customer_id' => null ), array( 'id' => $x->id ) );
		delete_option( 'nwc_drive_share_' . $x->id );
		return array( 'ok' => true );
	}

	public static function shared_download( $token ) {
		global $wpdb;
		$x = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . NWC_DB::t( 'drive_files' ) . ' WHERE share_hash = %s AND removed_at IS NULL', hash( 'sha256', (string) $token ) ) ); // phpcs:ignore
		$w = $x ? NWC_Tenancy::workspace( $x->workspace_id ) : null;
		if ( ! $x || ! $w || ! NWC_Features::allowed( 'google_drive', $w ) || ! NWC::rate_ok( 'share|' . NWC::ip(), 60, 300 ) ) {
			status_header( 404 );
			exit( 'This link is not available.' );
		}
		$res = self::stream( $x );
		if ( is_wp_error( $res ) ) {
			status_header( 404 );
			exit( 'This file is not available right now.' );
		}
		return $res;
	}

	/** For the customer portal: only what was shared with this customer. */
	public static function shared_for_customer( $ws, $customer_id ) {
		global $wpdb;
		$out = array();
		foreach ( $wpdb->get_results( $wpdb->prepare( 'SELECT id, name FROM ' . NWC_DB::t( 'drive_files' ) . ' WHERE workspace_id = %d AND customer_id = %d AND share_hash IS NOT NULL AND removed_at IS NULL ORDER BY id DESC LIMIT 100', $ws, $customer_id ) ) as $x ) { // phpcs:ignore
			$tok = get_option( 'nwc_drive_share_' . $x->id );
			if ( $tok ) {
				$out[] = array( 'name' => $x->name, 'url' => home_url( '/shared/' . NWC::unseal( $tok ) . '/' ) );
			}
		}
		return $out;
	}
}
