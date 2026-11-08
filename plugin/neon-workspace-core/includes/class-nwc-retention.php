<?php
/**
 * Retention for completed bookings and registrations. Off until a business owner turns it on.
 *
 *   1. Daily: finds finished records older than the retention period.
 *   2. Notifies the owner, writes a private export, and creates an expiring download link.
 *   3. Deletes only after a further notice period, so nothing goes silently. The owner can cancel.
 *   4. Keeps a log with counts and no personal data.
 *
 * Never touched: future or active bookings, legal holds, anything with an invoice, invoices,
 * payments, financial records, subscription data, and files in the customer's Google Drive.
 */

defined( 'ABSPATH' ) || exit;

class NWC_Retention {

	const NOTICE_DAYS = 7;
	const LINK_DAYS   = 7;

	public static function routes() {
		NWC_REST::route( '/retention', 'GET', array( __CLASS__, 'status' ) );
		NWC_REST::route( '/retention', 'PUT', array( __CLASS__, 'save' ) );
		NWC_REST::route( '/retention/runs/(?P<id>\d+)/cancel', 'POST', array( __CLASS__, 'cancel' ) );
	}

	public static function status() {
		global $wpdb;
		if ( ! NWC_Tenancy::can( 'settings.manage' ) ) {
			return NWC::err( 'Your role does not allow this.', 403, 'nwc_forbidden' );
		}
		$ws  = (int) NWC_Tenancy::$current->id;
		$biz = NWC_Bookings::business( $ws );
		return array(
			'enabled' => $biz ? (bool) $biz->retention_enabled : false,
			'days'    => $biz ? (int) $biz->retention_days : 30,
			'email'   => $biz ? (string) $biz->retention_email : '',
			'notice_days' => self::NOTICE_DAYS,
			'eligible_now' => array_map( 'count', self::eligible( $ws, gmdate( 'Y-m-d H:i:s', time() - ( $biz ? (int) $biz->retention_days : 30 ) * DAY_IN_SECONDS ) ) ),
			'runs'    => $wpdb->get_results( $wpdb->prepare( 'SELECT id, status, cutoff, bookings_count, registrations_count, purge_after, noticed_at, purged_at, cancelled_at FROM ' . NWC_DB::t( 'retention_runs' ) . ' WHERE workspace_id = %d ORDER BY id DESC LIMIT 20', $ws ) ), // phpcs:ignore
		);
	}

	public static function save( WP_REST_Request $r ) {
		global $wpdb;
		if ( ! NWC_Tenancy::can( 'settings.manage' ) ) {
			return NWC::err( 'Your role does not allow this.', 403, 'nwc_forbidden' );
		}
		$b  = NWC_REST::body( $r );
		$ws = (int) NWC_Tenancy::$current->id;
		$old = NWC_Bookings::business( $ws );
		$days = absint( $b['days'] ?? ( $old ? $old->retention_days : 30 ) );
		if ( $days < 7 || $days > 3650 ) {
			return NWC::err( 'Choose between 7 and 3650 days.' );
		}
		$email = sanitize_email( (string) ( $b['email'] ?? ( $old ? $old->retention_email : '' ) ) );
		$row   = array( 'retention_enabled' => empty( $b['enabled'] ) ? 0 : 1, 'retention_days' => $days, 'retention_email' => $email );
		$wpdb->update( NWC_DB::t( 'business' ), $row, array( 'workspace_id' => $ws ) );
		if ( $old && ( (int) $old->retention_enabled !== $row['retention_enabled'] || (int) $old->retention_days !== $days ) ) {
			NWC::audit( 'retention_settings_changed', array( 'kind' => 'admin', 'details' => array( 'enabled' => $row['retention_enabled'], 'days' => $days, 'was_days' => (int) $old->retention_days ) ) );
		}
		if ( ! $row['retention_enabled'] ) {
			$wpdb->query( $wpdb->prepare( 'UPDATE ' . NWC_DB::t( 'retention_runs' ) . " SET status = 'cancelled', cancelled_at = %s WHERE workspace_id = %d AND status = 'noticed'", NWC::now(), $ws ) ); // phpcs:ignore
		}
		return self::status();
	}

	public static function cancel( WP_REST_Request $r ) {
		global $wpdb;
		if ( ! NWC_Tenancy::can( 'settings.manage' ) ) {
			return NWC::err( 'Your role does not allow this.', 403, 'nwc_forbidden' );
		}
		$n = $wpdb->query( $wpdb->prepare( 'UPDATE ' . NWC_DB::t( 'retention_runs' ) . " SET status = 'cancelled', cancelled_at = %s WHERE id = %d AND workspace_id = %d AND status = 'noticed'", NWC::now(), (int) $r['id'], NWC_Tenancy::$current->id ) ); // phpcs:ignore
		if ( ! $n ) {
			return NWC::err( 'That clean-up cannot be cancelled now.', 409, 'nwc_conflict' );
		}
		NWC::audit( 'retention_run_cancelled', array( 'kind' => 'admin', 'entity' => 'retention_run', 'entity_id' => (int) $r['id'] ) );
		return array( 'ok' => true );
	}

	/**
	 * What may be cleaned for a workspace, finished before $cutoff.
	 *
	 * @return array {bookings: id[], registrations: id[]}
	 */
	public static function eligible( $ws, $cutoff ) {
		global $wpdb;
		$b = NWC_DB::t( 'bookings' );
		$i = NWC_DB::t( 'biz_invoices' );
		$bookings = $wpdb->get_col( $wpdb->prepare( "SELECT b.id FROM $b b LEFT JOIN $i i ON i.workspace_id = b.workspace_id AND i.booking_id = b.id WHERE b.workspace_id = %d AND b.legal_hold = 0 AND b.status IN ('completed','cancelled','no_show') AND b.ends_at < %s AND i.id IS NULL LIMIT 5000", $ws, $cutoff ) ); // phpcs:ignore
		$f = NWC_DB::t( 'forms' );
		$s = NWC_DB::t( 'form_submissions' );
		$regs = $wpdb->get_col( $wpdb->prepare( "SELECT s.id FROM $s s JOIN $f f ON f.id = s.form_id WHERE s.workspace_id = %d AND s.deleted_at IS NULL AND f.kind = 'registration' AND f.legal_hold = 0 AND f.event_end IS NOT NULL AND f.event_end < %s LIMIT 5000", $ws, $cutoff ) ); // phpcs:ignore
		return array( 'bookings' => array_map( 'intval', $bookings ), 'registrations' => array_map( 'intval', $regs ) );
	}

	public static function daily( $now = null ) {
		global $wpdb;
		$now = $now ? $now : time();
		$rows = $wpdb->get_results( 'SELECT workspace_id, retention_days, retention_email FROM ' . NWC_DB::t( 'business' ) . ' WHERE retention_enabled = 1' ); // phpcs:ignore
		foreach ( $rows as $biz ) {
			self::run_for( (int) $biz->workspace_id, (int) $biz->retention_days, (string) $biz->retention_email, $now );
		}
		// Finished exports do not linger.
		$old = $wpdb->get_results( $wpdb->prepare( 'SELECT id, export_path FROM ' . NWC_DB::t( 'retention_runs' ) . " WHERE export_path <> '' AND export_expires_at < %s", gmdate( 'Y-m-d H:i:s', $now ) ) ); // phpcs:ignore
		foreach ( $old as $r ) {
			if ( is_file( $r->export_path ) ) {
				@unlink( $r->export_path ); // phpcs:ignore
			}
			$wpdb->update( NWC_DB::t( 'retention_runs' ), array( 'export_path' => '', 'export_hash' => null ), array( 'id' => $r->id ) );
		}
	}

	private static function run_for( $ws, $days, $email, $now ) {
		global $wpdb;
		$t    = NWC_DB::t( 'retention_runs' );
		$open = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $t WHERE workspace_id = %d AND status = 'noticed' ORDER BY id DESC LIMIT 1", $ws ) ); // phpcs:ignore
		if ( $open ) {
			if ( strtotime( $open->purge_after . ' UTC' ) <= $now ) {
				self::purge( $open );
			}
			return;
		}
		$cutoff = gmdate( 'Y-m-d H:i:s', $now - $days * DAY_IN_SECONDS );
		$list   = self::eligible( $ws, $cutoff );
		if ( ! $list['bookings'] && ! $list['registrations'] ) {
			return;
		}
		$path  = self::write_export( $ws, $list );
		$token = rtrim( strtr( base64_encode( random_bytes( 32 ) ), '+/', '-_' ), '=' ); // phpcs:ignore
		$nowdt = gmdate( 'Y-m-d H:i:s', $now );
		$wpdb->insert( $t, array( 'workspace_id' => $ws, 'status' => 'noticed', 'cutoff' => $cutoff, 'bookings_count' => count( $list['bookings'] ), 'registrations_count' => count( $list['registrations'] ), 'export_path' => (string) $path, 'export_hash' => $path ? hash( 'sha256', $token ) : null, 'export_expires_at' => gmdate( 'Y-m-d H:i:s', $now + self::LINK_DAYS * DAY_IN_SECONDS ), 'purge_after' => gmdate( 'Y-m-d H:i:s', $now + self::NOTICE_DAYS * DAY_IN_SECONDS ), 'noticed_at' => $nowdt ) );
		$run  = (int) $wpdb->insert_id;
		$msg  = count( $list['bookings'] ) . ' completed bookings and ' . count( $list['registrations'] ) . ' event registrations will be removed on ' . gmdate( 'j M Y', $now + self::NOTICE_DAYS * DAY_IN_SECONDS ) . '. Download your copy or cancel in Settings.';
		NWC_Notify::workspace_owners( $ws, 'retention', 'Scheduled clean-up of old records', $msg, '#/settings', 'retention:' . $run );
		$url = $path ? home_url( '/r/' . $token . '/' ) : '';
		if ( $url ) {
			$to = '' !== $email ? array( $email ) : array();
			if ( ! $to ) {
				foreach ( $wpdb->get_col( $wpdb->prepare( 'SELECT user_id FROM ' . NWC_DB::t( 'members' ) . " WHERE workspace_id = %d AND role = 'owner' AND status = 'active'", $ws ) ) as $u ) { // phpcs:ignore
					$usr = get_userdata( $u );
					if ( $usr ) {
						$to[] = $usr->user_email;
					}
				}
			}
			foreach ( $to as $addr ) {
				NWC_Notify::email( $addr, 'Download your records before clean-up', '<p>' . esc_html( $msg ) . '</p><p><a href="' . esc_url( $url ) . '">Download the export</a>. This link works for ' . self::LINK_DAYS . ' days.</p>' );
			}
			$GLOBALS['nwc_last_retention_url'] = $url;
		}
		NWC::audit( 'retention_run_noticed', array( 'workspace_id' => $ws, 'kind' => 'admin', 'user_id' => 0, 'entity' => 'retention_run', 'entity_id' => $run, 'details' => array( 'bookings' => count( $list['bookings'] ), 'registrations' => count( $list['registrations'] ) ) ) );
	}

	/** Private folder, not web-served by name: reached only through the signed route. */
	private static function write_export( $ws, $list ) {
		global $wpdb;
		$dir = NWC_Projects::storage_dir( $ws );
		if ( ! $dir || ! is_dir( $dir ) ) {
			return '';
		}
		$file = $dir . '/retention-' . wp_generate_password( 24, false ) . '.csv';
		$safe = function ( $v ) {
			$v = (string) $v;
			if ( '' !== $v && in_array( $v[0], array( '=', '+', '-', '@', "\t", "\r" ), true ) ) {
				$v = "'" . $v;
			}
			return '"' . str_replace( '"', '""', $v ) . '"';
		};
		$out = "\"Type\",\"Name\",\"Phone\",\"Email\",\"When (UTC)\",\"Status\",\"Details\"\r\n";
		foreach ( array_chunk( $list['bookings'], 500 ) as $ids ) {
			$ph = implode( ',', array_map( 'intval', $ids ) );
			foreach ( $wpdb->get_results( 'SELECT b.*, s.name AS sn FROM ' . NWC_DB::t( 'bookings' ) . ' b LEFT JOIN ' . NWC_DB::t( 'services' ) . " s ON s.id = b.service_id WHERE b.id IN ($ph)" ) as $b ) { // phpcs:ignore
				$out .= implode( ',', array_map( $safe, array( 'Booking', $b->customer_name, $b->phone, $b->email, $b->starts_at, $b->status, $b->sn ) ) ) . "\r\n";
			}
		}
		foreach ( array_chunk( $list['registrations'], 500 ) as $ids ) {
			$ph = implode( ',', array_map( 'intval', $ids ) );
			foreach ( $wpdb->get_results( 'SELECT * FROM ' . NWC_DB::t( 'form_submissions' ) . " WHERE id IN ($ph)" ) as $s ) { // phpcs:ignore
				$out .= implode( ',', array_map( $safe, array( 'Registration', $s->name, $s->phone, $s->email, $s->created_at, $s->status, (string) $s->data ) ) ) . "\r\n";
			}
		}
		return false !== file_put_contents( $file, $out ) ? $file : ''; // phpcs:ignore
	}

	/** Re-checks eligibility right now, so a record that gained an invoice or a hold is spared. */
	private static function purge( $run ) {
		global $wpdb;
		$list = self::eligible( (int) $run->workspace_id, $run->cutoff );
		foreach ( array_chunk( $list['bookings'], 500 ) as $ids ) {
			$wpdb->query( 'DELETE FROM ' . NWC_DB::t( 'bookings' ) . ' WHERE id IN (' . implode( ',', array_map( 'intval', $ids ) ) . ')' ); // phpcs:ignore
		}
		foreach ( array_chunk( $list['registrations'], 500 ) as $ids ) {
			$wpdb->query( 'DELETE FROM ' . NWC_DB::t( 'form_submissions' ) . ' WHERE id IN (' . implode( ',', array_map( 'intval', $ids ) ) . ')' ); // phpcs:ignore
		}
		$wpdb->update( NWC_DB::t( 'retention_runs' ), array( 'status' => 'purged', 'purged_at' => NWC::now(), 'bookings_count' => count( $list['bookings'] ), 'registrations_count' => count( $list['registrations'] ) ), array( 'id' => $run->id ) );
		NWC::audit( 'retention_run_purged', array( 'workspace_id' => $run->workspace_id, 'kind' => 'admin', 'user_id' => 0, 'entity' => 'retention_run', 'entity_id' => $run->id, 'details' => array( 'bookings' => count( $list['bookings'] ), 'registrations' => count( $list['registrations'] ) ) ) );
		NWC_Notify::workspace_owners( (int) $run->workspace_id, 'retention', 'Clean-up finished', count( $list['bookings'] ) . ' bookings and ' . count( $list['registrations'] ) . ' registrations were removed.', '#/settings', 'retention-done:' . $run->id );
	}

	public static function download( $token ) {
		global $wpdb;
		$r = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . NWC_DB::t( 'retention_runs' ) . ' WHERE export_hash = %s AND export_expires_at > %s', hash( 'sha256', (string) $token ), NWC::now() ) ); // phpcs:ignore
		if ( ! $r || ! is_file( $r->export_path ) ) {
			status_header( 404 );
			nocache_headers();
			exit( 'This link has expired.' );
		}
		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'X-Content-Type-Options: nosniff' );
		header( 'Content-Disposition: attachment; filename="records-export.csv"' );
		readfile( $r->export_path ); // phpcs:ignore
		exit;
	}
}
