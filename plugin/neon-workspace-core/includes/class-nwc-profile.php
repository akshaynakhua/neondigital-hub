<?php
/**
 * Phase 5: profile images.
 *
 *   Account avatar   - one per person, shown in the top-right account menu, the team list and the
 *                      account settings. Stored as user meta; never tied to a Workspace.
 *   Workspace logo   - one per business Workspace (business.logo_url), shown in the switcher, the
 *                      dashboard header, invoices, quotations and the public booking page. Changing
 *                      one Workspace's logo never touches another Workspace or the account avatar.
 *
 * Every image is checked by its bytes (JPEG, PNG or WebP only, at most 2 MB and 4000 px), then
 * re-encoded by GD into a fresh PNG of at most 512 px. Re-encoding drops anything that was hidden in
 * the original file (metadata, scripts, polyglots). Files get random names in folders that refuse
 * script execution. Without an image the app shows initials.
 */

defined( 'ABSPATH' ) || exit;

class NWC_Profile {

	const MAX_BYTES = 2097152; // 2 MB.
	const MAX_SIDE  = 4000;
	const OUT_SIDE  = 512;
	const META      = 'nwc_avatar_url';

	public static function routes() {
		NWC_REST::route( '/me/avatar', 'POST', array( __CLASS__, 'avatar_upload' ), 'user' );
		NWC_REST::route( '/me/avatar', 'DELETE', array( __CLASS__, 'avatar_remove' ), 'user' );
		NWC_REST::route( '/workspace/logo', 'POST', array( __CLASS__, 'logo_upload' ) );
		NWC_REST::route( '/workspace/logo', 'DELETE', array( __CLASS__, 'logo_remove' ) );
	}

	public static function avatar_url( $user_id ) {
		$u = (string) get_user_meta( (int) $user_id, self::META, true );
		return self::ours( $u ) ? $u : '';
	}

	/** Only URLs this site wrote into its own image folders are ever shown. */
	private static function ours( $url ) {
		$base = trailingslashit( wp_upload_dir()['baseurl'] ) . 'nwc-public/';
		return is_string( $url ) && '' !== $url && 0 === strpos( $url, $base ) && preg_match( '#^(avatars|\d+)/[a-z0-9-]+\.png$#', substr( $url, strlen( $base ) ) );
	}

	private static function path_of( $url ) {
		$up   = wp_upload_dir();
		$base = trailingslashit( $up['baseurl'] ) . 'nwc-public/';
		return self::ours( $url ) ? trailingslashit( $up['basedir'] ) . 'nwc-public/' . substr( $url, strlen( $base ) ) : '';
	}

	/** Folder for images, with script execution refused (Apache rule; Nginx: see docs/SECURITY.md). */
	private static function dir( $sub ) {
		$up   = wp_upload_dir();
		$root = trailingslashit( $up['basedir'] ) . 'nwc-public';
		wp_mkdir_p( $root . '/' . $sub );
		if ( ! file_exists( $root . '/.htaccess' ) ) {
			file_put_contents( $root . '/.htaccess', "<FilesMatch \"\\.(php|phtml|phar|pl|py|cgi|sh)$\">\nRequire all denied\n</FilesMatch>\nOptions -ExecCGI -Indexes\n" ); // phpcs:ignore
		}
		return $root . '/' . $sub;
	}

	/** The image bytes from a multipart upload ("file") or a JSON body ({data: base64 or data URL}). */
	private static function incoming( WP_REST_Request $r ) {
		$f = $r->get_file_params();
		if ( ! empty( $f['file'] ) ) {
			$f = $f['file'];
			if ( UPLOAD_ERR_OK !== (int) $f['error'] || ( ! is_uploaded_file( $f['tmp_name'] ) && ! defined( 'NWC_TESTING' ) ) ) {
				return NWC::err( 'No image was received.' );
			}
			if ( (int) $f['size'] > self::MAX_BYTES ) {
				return NWC::err( 'Images can be up to 2 MB.', 413, 'nwc_file_size' );
			}
			return (string) file_get_contents( $f['tmp_name'] ); // phpcs:ignore
		}
		$b    = NWC_REST::body( $r );
		$data = (string) ( $b['data'] ?? '' );
		if ( preg_match( '#^data:image/[a-z+]+;base64,#i', $data ) ) {
			$data = substr( $data, strpos( $data, ',' ) + 1 );
		}
		if ( strlen( $data ) > self::MAX_BYTES * 1.4 ) {
			return NWC::err( 'Images can be up to 2 MB.', 413, 'nwc_file_size' );
		}
		$raw = base64_decode( $data, true ); // phpcs:ignore
		return false === $raw || '' === $raw ? NWC::err( 'No image was received.' ) : $raw;
	}

	/**
	 * Validates the bytes and writes a clean PNG. @return string|WP_Error Public URL.
	 */
	public static function store( $raw, $sub, $prefix ) {
		if ( strlen( $raw ) > self::MAX_BYTES ) {
			return NWC::err( 'Images can be up to 2 MB.', 413, 'nwc_file_size' );
		}
		$info = @getimagesizefromstring( $raw ); // phpcs:ignore
		if ( ! $info || ! in_array( (int) $info[2], array( IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP ), true ) ) {
			return NWC::err( 'Use a JPG, PNG or WebP image.', 415, 'nwc_file_type' );
		}
		if ( $info[0] < 16 || $info[1] < 16 || $info[0] > self::MAX_SIDE || $info[1] > self::MAX_SIDE ) {
			return NWC::err( 'The image must be between 16 and ' . self::MAX_SIDE . ' pixels on each side.', 422, 'nwc_image_size' );
		}
		if ( ! function_exists( 'imagecreatefromstring' ) ) {
			return NWC::err( 'Image processing is not available on this server.', 500, 'nwc_no_gd' );
		}
		$src = @imagecreatefromstring( $raw ); // phpcs:ignore
		if ( ! $src ) {
			return NWC::err( 'This image could not be read.', 415, 'nwc_file_type' );
		}
		$w     = imagesx( $src );
		$h     = imagesy( $src );
		$scale = min( 1, self::OUT_SIDE / max( $w, $h ) );
		$nw    = max( 1, (int) round( $w * $scale ) );
		$nh    = max( 1, (int) round( $h * $scale ) );
		$out   = imagecreatetruecolor( $nw, $nh );
		imagealphablending( $out, false );
		imagesavealpha( $out, true );
		imagefill( $out, 0, 0, imagecolorallocatealpha( $out, 0, 0, 0, 127 ) );
		imagecopyresampled( $out, $src, 0, 0, 0, 0, $nw, $nh, $w, $h );
		$dir  = self::dir( $sub );
		$name = $prefix . '-' . bin2hex( random_bytes( 12 ) ) . '.png';
		if ( ! imagepng( $out, $dir . '/' . $name, 6 ) ) {
			return NWC::err( 'The image could not be saved.', 500, 'nwc_error' );
		}
		return trailingslashit( wp_upload_dir()['baseurl'] ) . 'nwc-public/' . $sub . '/' . $name;
	}

	private static function drop_file( $url ) {
		$p = self::path_of( $url );
		if ( $p && file_exists( $p ) ) {
			@unlink( $p ); // phpcs:ignore
		}
	}

	/* ---------------------------- Account avatar ---------------------------- */

	public static function avatar_upload( WP_REST_Request $r ) {
		$uid = get_current_user_id();
		if ( ! NWC::rate_ok( 'avatar|' . $uid, 20, 3600 ) ) {
			return NWC::err( 'Too many uploads. Please try again later.', 429, 'nwc_rate_limited' );
		}
		$raw = self::incoming( $r );
		if ( is_wp_error( $raw ) ) {
			return $raw;
		}
		$url = self::store( $raw, 'avatars', 'u' . $uid );
		if ( is_wp_error( $url ) ) {
			return $url;
		}
		self::drop_file( self::avatar_url( $uid ) );
		update_user_meta( $uid, self::META, $url );
		NWC::audit( 'avatar_changed', array( 'workspace_id' => null, 'kind' => 'activity', 'user_id' => $uid ) );
		return array( 'avatar_url' => $url );
	}

	public static function avatar_remove() {
		$uid = get_current_user_id();
		self::drop_file( self::avatar_url( $uid ) );
		delete_user_meta( $uid, self::META );
		NWC::audit( 'avatar_removed', array( 'workspace_id' => null, 'kind' => 'activity', 'user_id' => $uid ) );
		return array( 'avatar_url' => '' );
	}

	/* ---------------------------- Workspace logo ---------------------------- */

	private static function logo_guard() {
		$w = NWC_Tenancy::$current;
		if ( NWC_Personal::is_personal( $w ) ) {
			return NWC::err( 'Personal has no business logo. Use your account picture instead.', 409, 'nwc_personal' );
		}
		if ( ! NWC_Tenancy::can( 'settings.manage' ) ) {
			return NWC::err( 'Only the Workspace owner or an admin can change the logo.', 403, 'nwc_forbidden' );
		}
		return true;
	}

	public static function logo_upload( WP_REST_Request $r ) {
		global $wpdb;
		$g = self::logo_guard();
		if ( is_wp_error( $g ) ) {
			return $g;
		}
		$ws  = (int) NWC_Tenancy::$current->id;
		$raw = self::incoming( $r );
		if ( is_wp_error( $raw ) ) {
			return $raw;
		}
		$url = self::store( $raw, (string) $ws, 'logo' );
		if ( is_wp_error( $url ) ) {
			return $url;
		}
		$biz = NWC_Bookings::business( $ws );
		if ( $biz ) {
			self::drop_file( $biz->logo_url );
			$wpdb->update( NWC_DB::t( 'business' ), array( 'logo_url' => $url, 'updated_at' => NWC::now() ), array( 'workspace_id' => $ws ) );
		} else {
			$wpdb->insert( NWC_DB::t( 'business' ), array( 'workspace_id' => $ws, 'display_name' => NWC_Tenancy::$current->name, 'logo_url' => $url, 'updated_at' => NWC::now() ) );
		}
		NWC::audit( 'workspace_logo_changed', array( 'kind' => 'admin', 'entity' => 'workspace', 'entity_id' => $ws ) );
		return array( 'logo_url' => $url );
	}

	public static function logo_remove() {
		global $wpdb;
		$g = self::logo_guard();
		if ( is_wp_error( $g ) ) {
			return $g;
		}
		$ws  = (int) NWC_Tenancy::$current->id;
		$biz = NWC_Bookings::business( $ws );
		if ( $biz ) {
			self::drop_file( $biz->logo_url );
			$wpdb->update( NWC_DB::t( 'business' ), array( 'logo_url' => '', 'updated_at' => NWC::now() ), array( 'workspace_id' => $ws ) );
		}
		NWC::audit( 'workspace_logo_removed', array( 'kind' => 'admin', 'entity' => 'workspace', 'entity_id' => $ws ) );
		return array( 'logo_url' => '' );
	}
}
