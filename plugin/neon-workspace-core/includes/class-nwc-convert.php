<?php
/**
 * Changing a Workspace's business type, and closing a Workspace.
 *
 * Business-type conversion (WorkDesk-controlled for agency clients; owner-controlled for independent
 * Workspaces):
 *   1. preview: what is kept and what is removed, counted;
 *   2. a full backup of every row that will change, written to private storage and read back and
 *      checked (hash and row counts) before anything is touched;
 *   3. one transaction: old category records removed, customer and lead records stripped back to
 *      basic identity and contact details, old services archived, booking pages emptied;
 *      financial records (invoices, items, payments, quotes, finance entries, subscriptions,
 *      transactions) are never touched; bookings or orders tied to an invoice are archived, not
 *      deleted;
 *   4. the new category with its own dashboard and modules; history kept on both sides.
 * Any failure rolls back and leaves the Workspace as it was. A completed conversion can be restored
 * from its backup.
 *
 * Personal areas and other Workspaces are never in scope: every statement is bound to one id.
 */

defined( 'ABSPATH' ) || exit;

class NWC_Convert {

	/** Category-specific tables removed by a conversion (all rows of the Workspace). */
	const OPS_TABLES = array( 'biz_records', 'pos_items', 'pos_kots', 'checkins', 'attendance', 'biz_lists', 'booking_credits', 'availability', 'spaces', 'closures' );

	/** Never deleted by a conversion or by closing a Workspace. */
	const FINANCIAL = array( 'biz_invoices', 'biz_items', 'biz_payments', 'biz_quotes', 'finance_entries', 'sub_invoices', 'transactions', 'subscriptions', 'agency_invoices', 'project_subscriptions', 'consents', 'audit', 'conversions' );

	const WINDOW_DAYS = 14;

	public static function routes() {
		NWC_REST::route( '/business-type/preview', 'GET', array( __CLASS__, 'owner_preview' ) );
		NWC_REST::route( '/business-type/convert', 'POST', array( __CLASS__, 'owner_convert' ) );
		NWC_REST::route( '/business-type/history', 'GET', array( __CLASS__, 'owner_history' ) );
		NWC_REST::route( '/connect/business-type/preview', 'POST', array( __CLASS__, 'site_preview' ), 'public' );
		NWC_REST::route( '/connect/business-type/convert', 'POST', array( __CLASS__, 'site_convert' ), 'public' );
		NWC_REST::route( '/connect/business-type/restore', 'POST', array( __CLASS__, 'site_restore' ), 'public' );
		NWC_REST::route( '/workspace/close-preview', 'GET', array( __CLASS__, 'close_preview' ) );
		NWC_REST::route( '/workspace/close', 'POST', array( __CLASS__, 'close' ) );
		NWC_REST::route( '/me/closing-workspaces', 'GET', array( __CLASS__, 'closing_list' ), 'user' );
		NWC_REST::route( '/me/closing-workspaces/(?P<id>\d+)/restore', 'POST', array( __CLASS__, 'close_undo' ), 'user' );
		NWC_REST::route( '/connect/workspace/delete-preview', 'POST', array( __CLASS__, 'site_delete_preview' ), 'public' );
		NWC_REST::route( '/connect/workspace/delete', 'POST', array( __CLASS__, 'site_delete' ), 'public' );
	}

	/* ============================ Preview ============================ */

	private static function count( $sql, $vals ) {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( $sql, $vals ) ); // phpcs:ignore
	}

	/** Ids of bookings and orders that an invoice points at: archived, not deleted. */
	private static function invoiced( $ws ) {
		global $wpdb;
		return array(
			'bookings' => array_map( 'intval', $wpdb->get_col( $wpdb->prepare( 'SELECT booking_id FROM ' . NWC_DB::t( 'biz_invoices' ) . ' WHERE workspace_id = %d AND booking_id IS NOT NULL', $ws ) ) ), // phpcs:ignore
		);
	}

	public static function preview( $w, $to ) {
		$ws   = (int) $w->id;
		$cfg  = NWC_Industry::config( $w );
		$from = $cfg['category'];
		$to   = NWC_Catalog::category_key( $to );
		$err  = self::check_target( $w, $from, $to );
		if ( is_wp_error( $err ) ) {
			return $err;
		}
		$t      = function ( $n ) {
			return NWC_DB::t( $n );
		};
		$by_ent = array();
		global $wpdb;
		$ents = NWC_Industry::entities();
		foreach ( $wpdb->get_results( $wpdb->prepare( 'SELECT entity, COUNT(*) n FROM ' . $t( 'biz_records' ) . ' WHERE workspace_id = %d AND deleted_at IS NULL GROUP BY entity', $ws ) ) as $x ) { // phpcs:ignore
			$by_ent[] = array( 'label' => $ents[ $x->entity ]['plural'] ?? $x->entity, 'count' => (int) $x->n );
		}
		$inv    = self::invoiced( $ws );
		$remove = array_merge( $by_ent, array_values( array_filter( array(
			array( 'label' => 'POS orders and their items and kitchen tickets', 'count' => self::count( 'SELECT COUNT(*) FROM ' . $t( 'pos_orders' ) . ' WHERE workspace_id = %d', array( $ws ) ) ),
			array( 'label' => 'Room reservations', 'count' => self::count( 'SELECT COUNT(*) FROM ' . $t( 'stays' ) . ' WHERE workspace_id = %d', array( $ws ) ) ),
			array( 'label' => 'Check-ins', 'count' => self::count( 'SELECT COUNT(*) FROM ' . $t( 'checkins' ) . ' WHERE workspace_id = %d', array( $ws ) ) ),
			array( 'label' => 'Attendance marks', 'count' => self::count( 'SELECT COUNT(*) FROM ' . $t( 'attendance' ) . ' WHERE workspace_id = %d', array( $ws ) ) ),
			array( 'label' => 'Bookings', 'count' => self::count( 'SELECT COUNT(*) FROM ' . $t( 'bookings' ) . ' WHERE workspace_id = %d AND deleted_at IS NULL', array( $ws ) ) ),
			array( 'label' => 'Availability rules, spaces and blackout dates', 'count' => self::count( 'SELECT COUNT(*) FROM ' . $t( 'availability' ) . ' WHERE workspace_id = %d AND deleted_at IS NULL', array( $ws ) ) + self::count( 'SELECT COUNT(*) FROM ' . $t( 'spaces' ) . ' WHERE workspace_id = %d AND deleted_at IS NULL', array( $ws ) ) + self::count( 'SELECT COUNT(*) FROM ' . $t( 'closures' ) . ' WHERE workspace_id = %d', array( $ws ) ) ),
			array( 'label' => 'Saved custom types and dropdown values', 'count' => self::count( 'SELECT COUNT(*) FROM ' . $t( 'biz_lists' ) . ' WHERE workspace_id = %d', array( $ws ) ) ),
			array( 'label' => 'Notes, descriptions and custom fields on customers and leads', 'count' => self::count( 'SELECT COUNT(*) FROM ' . $t( 'customers' ) . " WHERE workspace_id = %d AND deleted_at IS NULL AND ((notes IS NOT NULL AND notes <> '') OR (custom IS NOT NULL AND custom NOT IN ('', '[]', '{}', 'null')))", array( $ws ) ) + self::count( 'SELECT COUNT(*) FROM ' . $t( 'leads' ) . " WHERE workspace_id = %d AND deleted_at IS NULL AND ((notes IS NOT NULL AND notes <> '') OR enquiry <> '' OR (custom IS NOT NULL AND custom NOT IN ('', '[]', '{}', 'null')))", array( $ws ) ) ),
			array( 'label' => 'Customer and lead activity notes', 'count' => self::count( 'SELECT COUNT(*) FROM ' . $t( 'activities' ) . " WHERE workspace_id = %d AND entity IN ('customer','lead')", array( $ws ) ) ),
		), function ( $x ) {
			return $x['count'] > 0;
		} ) ) );
		$keep = array_values( array_filter( array(
			array( 'label' => 'Customers (name, phone, email, address, GSTIN)', 'count' => self::count( 'SELECT COUNT(*) FROM ' . $t( 'customers' ) . ' WHERE workspace_id = %d AND deleted_at IS NULL', array( $ws ) ) ),
			array( 'label' => 'Leads (name, phone, email, stage)', 'count' => self::count( 'SELECT COUNT(*) FROM ' . $t( 'leads' ) . ' WHERE workspace_id = %d AND deleted_at IS NULL', array( $ws ) ) ),
			array( 'label' => 'Invoices, quotations and payments', 'count' => self::count( 'SELECT COUNT(*) FROM ' . $t( 'biz_invoices' ) . ' WHERE workspace_id = %d', array( $ws ) ) + self::count( 'SELECT COUNT(*) FROM ' . $t( 'biz_quotes' ) . ' WHERE workspace_id = %d', array( $ws ) ) + self::count( 'SELECT COUNT(*) FROM ' . $t( 'biz_payments' ) . ' WHERE workspace_id = %d', array( $ws ) ) ),
			array( 'label' => 'Income and expense entries', 'count' => self::count( 'SELECT COUNT(*) FROM ' . $t( 'finance_entries' ) . ' WHERE workspace_id = %d AND deleted_at IS NULL', array( $ws ) ) ),
			array( 'label' => 'Projects, tasks and calendar events', 'count' => self::count( 'SELECT COUNT(*) FROM ' . $t( 'projects' ) . ' WHERE workspace_id = %d AND deleted_at IS NULL', array( $ws ) ) + self::count( 'SELECT COUNT(*) FROM ' . $t( 'tasks' ) . ' WHERE workspace_id = %d AND deleted_at IS NULL', array( $ws ) ) + self::count( 'SELECT COUNT(*) FROM ' . $t( 'events' ) . ' WHERE workspace_id = %d AND deleted_at IS NULL', array( $ws ) ) ),
			array( 'label' => 'Team, subscription, files and settings', 'count' => self::count( 'SELECT COUNT(*) FROM ' . $t( 'members' ) . " WHERE workspace_id = %d AND status = 'active'", array( $ws ) ) ),
		), function ( $x ) {
			return $x['count'] > 0;
		} ) );
		$archive = count( $inv['bookings'] );
		$p_to = NWC_Industry::profiles()[ NWC_Industry::profile_key( $to ) ];
		return array(
			'workspace' => array( 'id' => $ws, 'uid' => $w->uid, 'name' => $w->name ),
			'from' => array( 'key' => $from, 'label' => NWC_Catalog::category_label( $from ) ),
			'to'   => array( 'key' => $to, 'label' => NWC_Catalog::category_label( $to ), 'modules' => array_values( array_map( function ( $m ) {
				return NWC_Industry::modules()[ $m ]['label'];
			}, $p_to['modules'] ) ) ),
			'remove' => $remove, 'keep' => $keep,
			'archived_with_invoice' => $archive,
			'notes' => array( 'Invoices, payments and tax records are kept unchanged.', 'Bookings and orders that an invoice refers to are archived with restricted access, not deleted.', 'Your Personal area and your other Workspaces are not affected.', 'A full backup is taken and checked before anything is removed.' ),
		);
	}

	private static function check_target( $w, $from, $to ) {
		if ( NWC_Personal::is_personal( $w ) ) {
			return NWC::err( 'A Personal area has no business type.', 409, 'nwc_conflict' );
		}
		if ( ! in_array( $to, NWC_Catalog::selectable(), true ) ) {
			return NWC::err( 'Choose a valid business type.' );
		}
		if ( $to === $from ) {
			return NWC::err( 'This Workspace already has that business type.', 409, 'nwc_conflict' );
		}
		if ( 'active' !== $w->status ) {
			return NWC::err( 'This Workspace is not active.', 409, 'nwc_conflict' );
		}
		return true;
	}

	/* ============================ Backup ============================ */

	private static function backup_dir( $ws ) {
		$dir = NWC_Projects::storage_dir( $ws ) . '/conversions';
		wp_mkdir_p( $dir );
		if ( ! file_exists( $dir . '/index.html' ) ) {
			file_put_contents( $dir . '/index.html', '' ); // phpcs:ignore
		}
		return $dir;
	}

	/** Every row a conversion can touch, in full. */
	private static function snapshot( $ws ) {
		global $wpdb;
		$out = array();
		foreach ( array_merge( self::OPS_TABLES, array( 'pos_orders', 'stays', 'bookings', 'customers', 'leads', 'services', 'booking_pages', 'business' ) ) as $tb ) {
			$out[ $tb ] = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . NWC_DB::t( $tb ) . ' WHERE workspace_id = %d', $ws ), ARRAY_A ); // phpcs:ignore
		}
		$out['activities'] = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . NWC_DB::t( 'activities' ) . " WHERE workspace_id = %d AND entity IN ('customer','lead')", $ws ), ARRAY_A ); // phpcs:ignore
		$out['workspaces'] = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . NWC_DB::t( 'workspaces' ) . ' WHERE id = %d', $ws ), ARRAY_A ); // phpcs:ignore
		return $out;
	}

	/** @return array|WP_Error {path, hash, counts} once the file reads back identically. */
	private static function write_backup( $ws, $conv_id, $snap ) {
		$json = wp_json_encode( array( 'workspace_id' => $ws, 'conversion_id' => $conv_id, 'taken_at' => NWC::now(), 'tables' => $snap ) );
		if ( false === $json ) {
			return NWC::err( 'The backup could not be prepared. Nothing was changed.', 500, 'nwc_backup_failed' );
		}
		$gz   = gzencode( $json, 6 );
		$path = self::backup_dir( $ws ) . '/conversion-' . $conv_id . '-' . bin2hex( random_bytes( 8 ) ) . '.json.gz';
		if ( false === file_put_contents( $path, $gz ) ) { // phpcs:ignore
			return NWC::err( 'The backup could not be written. Nothing was changed.', 500, 'nwc_backup_failed' );
		}
		$hash = hash( 'sha256', $gz );
		$back = json_decode( (string) gzdecode( (string) file_get_contents( $path ) ), true ); // phpcs:ignore
		$counts = array();
		foreach ( $snap as $tb => $rows ) {
			$counts[ $tb ] = count( $rows );
			if ( ! is_array( $back ) || ! isset( $back['tables'][ $tb ] ) || ! is_array( $back['tables'][ $tb ] ) || count( $back['tables'][ $tb ] ) !== count( $rows ) ) {
				return NWC::err( 'The backup did not verify. Nothing was changed.', 500, 'nwc_backup_failed' );
			}
		}
		if ( hash_file( 'sha256', $path ) !== $hash ) {
			return NWC::err( 'The backup did not verify. Nothing was changed.', 500, 'nwc_backup_failed' );
		}
		return array( 'path' => $path, 'hash' => $hash, 'counts' => $counts );
	}

	/* ============================ Convert ============================ */

	/**
	 * @param array $meta reason, consent_note, consent_at, via (workspace|workdesk), staff_name, user_id.
	 * @return array|WP_Error
	 */
	public static function convert( $w, $to, $meta ) {
		global $wpdb;
		$ws   = (int) $w->id;
		$from = NWC_Industry::config( $w )['category'];
		$to   = NWC_Catalog::category_key( $to );
		$ok   = self::check_target( $w, $from, $to );
		if ( is_wp_error( $ok ) ) {
			return $ok;
		}
		$reason = trim( sanitize_textarea_field( (string) ( $meta['reason'] ?? '' ) ) );
		if ( mb_strlen( $reason ) < 5 ) {
			return NWC::err( 'Give the reason for this change.' );
		}
		if ( ! NWC::lock( 'convert:' . $ws, 1000 ) ) {
			return NWC::err( 'A change is already running for this Workspace.', 409, 'nwc_busy' );
		}
		try {
			$now = NWC::now();
			$wpdb->insert( NWC_DB::t( 'conversions' ), array( 'workspace_id' => $ws, 'from_category' => $from, 'to_category' => $to, 'reason' => $reason, 'consent_note' => sanitize_textarea_field( (string) ( $meta['consent_note'] ?? '' ) ), 'consent_at' => NWC::to_utc( (string) ( $meta['consent_at'] ?? '' ), 'UTC' ) ?: $now, 'requested_via' => 'workdesk' === ( $meta['via'] ?? '' ) ? 'workdesk' : 'workspace', 'staff_name' => mb_substr( sanitize_text_field( (string) ( $meta['staff_name'] ?? '' ) ), 0, 190 ), 'user_id' => (int) ( $meta['user_id'] ?? 0 ), 'status' => 'started', 'created_at' => $now ) );
			$cid    = (int) $wpdb->insert_id;
			$snap   = self::snapshot( $ws );
			$backup = self::write_backup( $ws, $cid, $snap );
			if ( is_wp_error( $backup ) ) {
				$wpdb->update( NWC_DB::t( 'conversions' ), array( 'status' => 'failed', 'error' => $backup->get_error_message() ), array( 'id' => $cid ) );
				return $backup;
			}
			$wpdb->update( NWC_DB::t( 'conversions' ), array( 'backup_path' => $backup['path'], 'backup_hash' => $backup['hash'] ), array( 'id' => $cid ) );
			$res = NWC::tx( function () use ( $ws, $to, $now ) {
				return self::apply( $ws, $to, $now );
			} );
			if ( is_wp_error( $res ) ) {
				$wpdb->update( NWC_DB::t( 'conversions' ), array( 'status' => 'failed', 'error' => $res->get_error_message() ), array( 'id' => $cid ) );
				return $res;
			}
			$wpdb->update( NWC_DB::t( 'conversions' ), array( 'status' => 'completed', 'counts' => wp_json_encode( $res ), 'completed_at' => NWC::now() ), array( 'id' => $cid ) );
		} finally {
			NWC::unlock( 'convert:' . $ws );
		}
		NWC_Features::flush();
		NWC::audit( 'business_type_changed', array( 'workspace_id' => $ws, 'kind' => 'admin', 'entity' => 'workspace', 'entity_id' => $ws, 'user_id' => (int) ( $meta['user_id'] ?? 0 ), 'source' => 'workdesk' === ( $meta['via'] ?? '' ) ? 'workdesk' : 'user', 'details' => array( 'from' => $from, 'to' => $to, 'reason' => $reason, 'conversion' => $cid, 'staff' => (string) ( $meta['staff_name'] ?? '' ) ) ) );
		NWC_Notify::workspace_owners( $ws, 'agency', 'Your business type is now ' . NWC_Catalog::category_label( $to ), 'Your dashboard and modules have changed. Invoices and payments are unchanged.', '#/', 'conv:' . $cid );
		NWC_Sync::enqueue( $ws, 'workspace', $ws );
		return array( 'ok' => true, 'conversion_id' => $cid, 'from' => $from, 'to' => $to, 'removed' => $res, 'backup_verified' => true );
	}

	/** The destructive part, run inside a transaction. @return array counts */
	private static function apply( $ws, $to, $now ) {
		global $wpdb;
		$t   = function ( $n ) {
			return NWC_DB::t( $n );
		};
		$n   = array();
		$inv = self::invoiced( $ws );
		foreach ( self::OPS_TABLES as $tb ) {
			$n[ $tb ] = (int) $wpdb->query( $wpdb->prepare( 'DELETE FROM ' . $t( $tb ) . ' WHERE workspace_id = %d', $ws ) ); // phpcs:ignore
		}
		$n['pos_orders'] = (int) $wpdb->query( $wpdb->prepare( 'DELETE FROM ' . $t( 'pos_orders' ) . ' WHERE workspace_id = %d', $ws ) ); // phpcs:ignore
		$n['stays']      = (int) $wpdb->query( $wpdb->prepare( 'DELETE FROM ' . $t( 'stays' ) . ' WHERE workspace_id = %d', $ws ) ); // phpcs:ignore
		$keep = $inv['bookings'] ? implode( ',', array_map( 'intval', $inv['bookings'] ) ) : '0';
		// Bookings an invoice points at stay, archived with legal hold and hidden from the app.
		$n['bookings_archived'] = (int) $wpdb->query( $wpdb->prepare( 'UPDATE ' . $t( 'bookings' ) . " SET deleted_at = %s, legal_hold = 1, notes = NULL, custom = NULL WHERE workspace_id = %d AND id IN ($keep) AND deleted_at IS NULL", $now, $ws ) ); // phpcs:ignore
		$n['bookings']          = (int) $wpdb->query( $wpdb->prepare( 'DELETE FROM ' . $t( 'bookings' ) . " WHERE workspace_id = %d AND id NOT IN ($keep)", $ws ) ); // phpcs:ignore
		$n['services_archived'] = (int) $wpdb->query( $wpdb->prepare( 'UPDATE ' . $t( 'services' ) . ' SET is_active = 0, archived_at = %s, staff_ids = NULL, space_ids = NULL WHERE workspace_id = %d AND archived_at IS NULL', $now, $ws ) ); // phpcs:ignore
		$wpdb->query( $wpdb->prepare( 'UPDATE ' . $t( 'booking_pages' ) . " SET service_ids = '[]', space_ids = '[]', fields = '[]', page_type = 'all', staff_id = NULL WHERE workspace_id = %d", $ws ) ); // phpcs:ignore
		$wpdb->query( $wpdb->prepare( 'UPDATE ' . $t( 'booking_pages' ) . " SET is_active = 0, status = 'draft' WHERE workspace_id = %d AND kind <> 'main'", $ws ) ); // phpcs:ignore
		$n['customers_cleaned'] = (int) $wpdb->query( $wpdb->prepare( 'UPDATE ' . $t( 'customers' ) . ' SET notes = NULL, custom = NULL, updated_at = %s WHERE workspace_id = %d', $now, $ws ) ); // phpcs:ignore
		$n['leads_cleaned']     = (int) $wpdb->query( $wpdb->prepare( 'UPDATE ' . $t( 'leads' ) . " SET notes = NULL, custom = NULL, enquiry = '', lead_type = 'enquiry', updated_at = %s WHERE workspace_id = %d", $now, $ws ) ); // phpcs:ignore
		$n['activities']        = (int) $wpdb->query( $wpdb->prepare( 'DELETE FROM ' . $t( 'activities' ) . " WHERE workspace_id = %d AND entity IN ('customer','lead')", $ws ) ); // phpcs:ignore
		$wpdb->update( $t( 'business' ), array( 'category' => $to, 'modules' => null, 'custom_label' => '', 'service_charge_pct' => 0, 'updated_at' => $now ), array( 'workspace_id' => $ws ) );
		$wpdb->update( $t( 'workspaces' ), array( 'category' => $to, 'updated_at' => $now ), array( 'id' => $ws ) );
		if ( $wpdb->last_error ) {
			return NWC::err( 'The conversion stopped on a database error and nothing was changed.', 500, 'nwc_tx_failed' );
		}
		return $n;
	}

	/**
	 * Puts back what a conversion removed or stripped, from its verified backup. Records created
	 * under the new type stay (hidden, since their modules are no longer active).
	 */
	public static function restore( $w, $conversion_id ) {
		global $wpdb;
		$c = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . NWC_DB::t( 'conversions' ) . " WHERE id = %d AND workspace_id = %d AND status = 'completed'", $conversion_id, $w->id ) ); // phpcs:ignore
		if ( ! $c ) {
			return NWC::err( 'No completed conversion with that number for this Workspace.', 404, 'nwc_not_found' );
		}
		$later = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . NWC_DB::t( 'conversions' ) . " WHERE workspace_id = %d AND id > %d AND status = 'completed'", $w->id, $c->id ) ); // phpcs:ignore
		if ( $later ) {
			return NWC::err( 'A later conversion exists. Restore that one first.', 409, 'nwc_conflict' );
		}
		if ( ! is_file( $c->backup_path ) || hash_file( 'sha256', $c->backup_path ) !== $c->backup_hash ) {
			return NWC::err( 'The backup file is missing or changed, so nothing was restored.', 409, 'nwc_backup_invalid' );
		}
		$data = json_decode( (string) gzdecode( (string) file_get_contents( $c->backup_path ) ), true ); // phpcs:ignore
		if ( ! is_array( $data ) || (int) $data['workspace_id'] !== (int) $w->id ) {
			return NWC::err( 'The backup does not belong to this Workspace.', 409, 'nwc_backup_invalid' );
		}
		$ws  = (int) $w->id;
		$res = NWC::tx( function () use ( $data, $ws ) {
			global $wpdb;
			$n = 0;
			foreach ( array_merge( self::OPS_TABLES, array( 'pos_orders', 'stays', 'bookings', 'activities' ) ) as $tb ) {
				foreach ( (array) ( $data['tables'][ $tb ] ?? array() ) as $row ) {
					if ( (int) $row['workspace_id'] !== $ws ) {
						continue;
					}
					$wpdb->replace( NWC_DB::t( $tb ), $row );
					$n++;
				}
			}
			foreach ( array( 'customers' => array( 'notes', 'custom' ), 'leads' => array( 'notes', 'custom', 'enquiry', 'lead_type' ), 'services' => array( 'is_active', 'archived_at', 'staff_ids', 'space_ids' ), 'booking_pages' => array( 'service_ids', 'space_ids', 'fields', 'page_type', 'staff_id', 'is_active', 'status' ) ) as $tb => $cols ) {
				foreach ( (array) ( $data['tables'][ $tb ] ?? array() ) as $row ) {
					$wpdb->update( NWC_DB::t( $tb ), array_intersect_key( $row, array_flip( $cols ) ), array( 'id' => (int) $row['id'], 'workspace_id' => $ws ) );
				}
			}
			$biz = $data['tables']['business'][0] ?? null;
			if ( $biz ) {
				$wpdb->update( NWC_DB::t( 'business' ), array_intersect_key( $biz, array_flip( array( 'category', 'modules', 'custom_label', 'service_charge_pct' ) ) ), array( 'workspace_id' => $ws ) );
				$wpdb->update( NWC_DB::t( 'workspaces' ), array( 'category' => $biz['category'] ), array( 'id' => $ws ) );
			}
			if ( $wpdb->last_error ) {
				return NWC::err( 'Restore stopped on a database error; nothing was changed.', 500, 'nwc_tx_failed' );
			}
			return $n;
		} );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		$wpdb->update( NWC_DB::t( 'conversions' ), array( 'status' => 'restored', 'restored_at' => NWC::now() ), array( 'id' => $c->id ) );
		NWC::audit( 'business_type_restored', array( 'workspace_id' => $ws, 'kind' => 'admin', 'entity' => 'workspace', 'entity_id' => $ws, 'details' => array( 'conversion' => (int) $c->id, 'rows' => $res, 'back_to' => $c->from_category ) ) );
		NWC_Sync::enqueue( $ws, 'workspace', $ws );
		return array( 'ok' => true, 'restored_rows' => $res, 'category' => $c->from_category );
	}

	/* ---------------------------- Owner (independent Workspaces) ---------------------------- */

	private static function owner_gate() {
		$w = NWC_Tenancy::$current;
		if ( 'owner' !== NWC_Tenancy::$member->role ) {
			return NWC::err( 'Only the owner can change the business type.', 403, 'nwc_forbidden' );
		}
		if ( 'agency' === $w->account_type ) {
			return NWC::err( 'NeonDigital manages this Workspace. Ask NeonDigital to change the business type.', 403, 'nwc_agency_managed' );
		}
		return true;
	}

	public static function owner_preview( WP_REST_Request $r ) {
		$g = self::owner_gate();
		return is_wp_error( $g ) ? $g : self::preview( NWC_Tenancy::$current, (string) $r->get_param( 'to' ) );
	}

	public static function owner_convert( WP_REST_Request $r ) {
		$g = self::owner_gate();
		if ( is_wp_error( $g ) ) {
			return $g;
		}
		$b = NWC_REST::body( $r );
		if ( 'CHANGE' !== (string) ( $b['confirm'] ?? '' ) ) {
			return NWC::err( 'Type CHANGE to confirm.', 422, 'nwc_confirm_required' );
		}
		$u = wp_get_current_user();
		return self::convert( NWC_Tenancy::$current, (string) ( $b['to'] ?? '' ), array( 'reason' => (string) ( $b['reason'] ?? '' ), 'consent_note' => 'Confirmed by the owner in Workspace settings', 'via' => 'workspace', 'staff_name' => $u->display_name, 'user_id' => $u->ID ) );
	}

	public static function owner_history() {
		global $wpdb;
		if ( ! NWC_Tenancy::can( 'settings.manage' ) ) {
			return NWC::err( 'Your role does not allow this.', 403, 'nwc_forbidden' );
		}
		return array( 'items' => $wpdb->get_results( $wpdb->prepare( 'SELECT id, from_category, to_category, reason, requested_via, staff_name, status, created_at, completed_at, restored_at FROM ' . NWC_DB::t( 'conversions' ) . ' WHERE workspace_id = %d ORDER BY id DESC LIMIT 50', NWC_Tenancy::$current->id ) ) ); // phpcs:ignore
	}

	/* ---------------------------- WorkDesk (site-signed) ---------------------------- */

	private static function site_ws( WP_REST_Request $r ) {
		global $wpdb;
		$g = NWC_Connect::auth_site( $r );
		if ( is_wp_error( $g ) ) {
			return $g;
		}
		$b = NWC_REST::body( $r );
		$w = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . NWC_DB::t( 'workspaces' ) . ' WHERE uid = %s', sanitize_text_field( (string) ( $b['workspace_uid'] ?? '' ) ) ) ); // phpcs:ignore
		if ( ! $w || ! NWC_WorkDesk::link( (int) $w->id ) ) {
			return NWC::err( 'This Workspace is not connected to NeonDigital.', 404, 'nwc_not_found' );
		}
		NWC_Tenancy::$current = $w;
		return array( $w, $b );
	}

	public static function site_preview( WP_REST_Request $r ) {
		$x = self::site_ws( $r );
		return is_wp_error( $x ) ? $x : self::preview( $x[0], (string) ( $x[1]['to'] ?? '' ) );
	}

	public static function site_convert( WP_REST_Request $r ) {
		$x = self::site_ws( $r );
		if ( is_wp_error( $x ) ) {
			return $x;
		}
		list( $w, $b ) = $x;
		if ( empty( $b['client_consent'] ) ) {
			return NWC::err( 'Record the client\'s consent first.', 422, 'nwc_consent_required' );
		}
		return self::convert( $w, (string) ( $b['to'] ?? '' ), array( 'reason' => (string) ( $b['reason'] ?? '' ), 'consent_note' => (string) ( $b['consent_note'] ?? '' ), 'consent_at' => (string) ( $b['consent_at'] ?? '' ), 'via' => 'workdesk', 'staff_name' => (string) ( $b['staff_name'] ?? '' ), 'user_id' => 0 ) );
	}

	public static function site_restore( WP_REST_Request $r ) {
		$x = self::site_ws( $r );
		return is_wp_error( $x ) ? $x : self::restore( $x[0], absint( $x[1]['conversion_id'] ?? 0 ) );
	}

	/* ============================ Closing a Workspace ============================ */

	public static function close_preview() {
		global $wpdb;
		$w = NWC_Tenancy::$current;
		if ( NWC_Personal::is_personal( $w ) || 'owner' !== NWC_Tenancy::$member->role ) {
			return NWC::err( 'Only the owner can delete a Workspace. A Personal area cannot be deleted.', 403, 'nwc_forbidden' );
		}
		$ws = (int) $w->id;
		return array(
			'name' => $w->name, 'window_days' => self::WINDOW_DAYS,
			'removed' => array( 'Projects, tasks, calendars, notes, customers, leads, bookings, booking pages, forms and category records', 'Files stored in Neon Workspace (files in your Google Drive stay in your Drive)', 'Team access for every member' ),
			'kept' => array( 'Invoices, quotations, payments, income and expense entries and subscription invoices, moved into an encrypted restricted archive for ' . self::ARCHIVE_YEARS . ' years as tax law requires', 'The audit record of the deletion' ),
			'invoices' => self::count( 'SELECT COUNT(*) FROM ' . NWC_DB::t( 'biz_invoices' ) . ' WHERE workspace_id = %d', array( $ws ) ),
			'members'  => self::count( 'SELECT COUNT(*) FROM ' . NWC_DB::t( 'members' ) . " WHERE workspace_id = %d AND status = 'active'", array( $ws ) ),
			'agency'   => 'agency' === $w->account_type,
			'unpaid_subscription' => self::count( 'SELECT COUNT(*) FROM ' . NWC_DB::t( 'sub_invoices' ) . " WHERE workspace_id = %d AND status = 'issued'", array( $ws ) ),
		);
	}

	/** Owner only: confirm by typing the Workspace name and the account password. Reversible for 14 days. */
	public static function close( WP_REST_Request $r ) {
		global $wpdb;
		$p = self::close_preview();
		if ( is_wp_error( $p ) ) {
			return $p;
		}
		$w = NWC_Tenancy::$current;
		$b = NWC_REST::body( $r );
		if ( trim( (string) ( $b['confirm_name'] ?? '' ) ) !== $w->name ) {
			return NWC::err( 'Type the Workspace name exactly to confirm.', 422, 'nwc_confirm_required' );
		}
		$u = wp_get_current_user();
		if ( ! get_user_meta( $u->ID, 'nwc_no_password', true ) && ! wp_check_password( (string) ( $b['password'] ?? '' ), $u->user_pass, $u->ID ) ) {
			return NWC::err( 'Your password is not correct.', 403, 'nwc_bad_password' );
		}
		$after = gmdate( 'Y-m-d H:i:s', time() + self::WINDOW_DAYS * DAY_IN_SECONDS );
		$wpdb->update( NWC_DB::t( 'workspaces' ), array( 'status' => 'closing', 'delete_after' => $after, 'updated_at' => NWC::now() ), array( 'id' => $w->id ) );
		NWC::audit( 'workspace_close_requested', array( 'workspace_id' => (int) $w->id, 'kind' => 'admin', 'entity' => 'workspace', 'entity_id' => (int) $w->id, 'details' => array( 'delete_after' => $after, 'reason' => sanitize_text_field( (string) ( $b['reason'] ?? '' ) ) ) ) );
		foreach ( $wpdb->get_col( $wpdb->prepare( 'SELECT user_id FROM ' . NWC_DB::t( 'members' ) . " WHERE workspace_id = %d AND status = 'active' AND user_id <> %d", $w->id, $u->ID ) ) as $m ) { // phpcs:ignore
			$usr = get_userdata( (int) $m );
			if ( $usr ) {
				NWC_Notify::email( $usr->user_email, $w->name . ' is being closed', '<p>The owner of <b>' . esc_html( $w->name ) . '</b> has closed this Workspace. Your access has ended.</p>' );
			}
		}
		NWC_Sync::enqueue( (int) $w->id, 'workspace', (int) $w->id );
		$home = NWC_Personal::ensure( $u->ID );
		return array( 'ok' => true, 'delete_after' => $after, 'personal_id' => $home ? (int) $home->id : 0 );
	}

	public static function closing_list() {
		global $wpdb;
		return array( 'items' => $wpdb->get_results( $wpdb->prepare( 'SELECT w.id, w.name, w.delete_after FROM ' . NWC_DB::t( 'workspaces' ) . ' w JOIN ' . NWC_DB::t( 'members' ) . " m ON m.workspace_id = w.id WHERE m.user_id = %d AND m.role = 'owner' AND w.status = 'closing'", get_current_user_id() ) ) ); // phpcs:ignore
	}

	public static function close_undo( WP_REST_Request $r ) {
		global $wpdb;
		$id = (int) $r['id'];
		$w  = NWC_Tenancy::workspace( $id );
		$m  = $w ? $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . NWC_DB::t( 'members' ) . " WHERE workspace_id = %d AND user_id = %d AND role = 'owner' AND status = 'active'", $id, get_current_user_id() ) ) : null; // phpcs:ignore
		if ( ! $w || ! $m || 'closing' !== $w->status ) {
			return NWC::err( 'Nothing to restore.', 404, 'nwc_not_found' );
		}
		$wpdb->update( NWC_DB::t( 'workspaces' ), array( 'status' => 'active', 'delete_after' => null, 'updated_at' => NWC::now() ), array( 'id' => $id ) );
		NWC::audit( 'workspace_close_cancelled', array( 'workspace_id' => $id, 'kind' => 'admin', 'entity' => 'workspace', 'entity_id' => $id ) );
		NWC_Sync::enqueue( $id, 'workspace', $id );
		return array( 'ok' => true );
	}

	/** Daily: Workspaces past their window lose operational data; financial records are retained. */
	public static function purge_due() {
		global $wpdb;
		foreach ( $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . NWC_DB::t( 'workspaces' ) . " WHERE status = 'closing' AND delete_after IS NOT NULL AND delete_after <= %s LIMIT 10", NWC::now() ) ) as $w ) { // phpcs:ignore
			self::purge( $w );
		}
		self::expire_archives();
	}

	/** The owner's 14-day window has passed: the same real deletion as any other. */
	public static function purge( $w ) {
		$res = self::delete_now( $w, 'owner_window', 0, '' );
		return is_wp_error( $res ) ? $res : true;
	}

	/* ============================ Real deletion (Phase 5) ============================ */

	/** Operational tables: everything here is deleted for the Workspace. */
	const OPERATIONAL = array( 'projects', 'project_members', 'tasks', 'milestones', 'comments', 'files', 'events', 'event_participants', 'calendar_shares', 'customers', 'leads', 'activities', 'business', 'domains', 'closures', 'services', 'booking_pages', 'bookings', 'notifications', 'notes', 'goals', 'invites', 'forms', 'form_submissions', 'planner_days', 'meetings', 'portal_tokens', 'payment_accounts', 'drive_files', 'drive_folders', 'drive_links', 'biz_gateways', 'availability', 'retention_runs', 'sync_outbox', 'feature_grants', 'workspace_addons', 'spaces', 'booking_credits', 'biz_records', 'biz_lists', 'pos_orders', 'pos_items', 'pos_kots', 'stays', 'checkins', 'attendance', 'conversions', 'agency_invoices', 'number_series', 'subscriptions' );

	/** Financial and tax records: moved into the encrypted restricted archive, then deleted here. */
	const ARCHIVED = array( 'biz_invoices', 'biz_quotes', 'biz_items', 'biz_payments', 'finance_entries', 'sub_invoices', 'transactions', 'project_subscriptions' );

	/** Years a deleted Workspace's financial archive is kept (Indian GST and Companies Act records). */
	const ARCHIVE_YEARS = 8;

	private static function ws_count( $table, $ws ) {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . NWC_DB::t( $table ) . ' WHERE workspace_id = %d', $ws ) ); // phpcs:ignore
	}

	private static function dir_files( $dir ) {
		$n = 0;
		if ( is_dir( $dir ) ) {
			foreach ( new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ) ) as $f ) {
				$n += $f->isFile() ? 1 : 0;
			}
		}
		return $n;
	}

	private static function rm_tree( $dir ) {
		if ( ! is_dir( $dir ) ) {
			return;
		}
		foreach ( new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST ) as $f ) {
			$f->isDir() ? @rmdir( $f->getPathname() ) : @unlink( $f->getPathname() ); // phpcs:ignore
		}
		@rmdir( $dir ); // phpcs:ignore
	}

	/**
	 * Exactly what a deletion removes and keeps, counted from the database and the file store, so the
	 * person confirming sees the real numbers.
	 */
	public static function deletion_preview( $w ) {
		global $wpdb;
		$ws  = (int) $w->id;
		$up  = wp_upload_dir();
		$out = array(
			'workspace' => array( 'uid' => $w->uid, 'name' => $w->name, 'account_type' => $w->account_type, 'status' => $w->status ),
			'members'   => (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . NWC_DB::t( 'members' ) . " WHERE workspace_id = %d AND status = 'active'", $ws ) ), // phpcs:ignore
			'deleted'   => array(),
			'archived'  => array(),
			'files'     => self::dir_files( trailingslashit( $up['basedir'] ) . 'nwc-private/' . $ws ) + self::dir_files( trailingslashit( $up['basedir'] ) . 'nwc-public/' . $ws ),
		);
		foreach ( self::OPERATIONAL as $t ) {
			$n = self::ws_count( $t, $ws );
			if ( $n ) {
				$out['deleted'][ $t ] = $n;
			}
		}
		foreach ( self::ARCHIVED as $t ) {
			$n = self::ws_count( $t, $ws );
			if ( $n ) {
				$out['archived'][ $t ] = $n;
			}
		}
		$out['kept'] = array( 'Invoices, quotations, payments, income and expense entries and subscription invoices go into an encrypted restricted archive for ' . self::ARCHIVE_YEARS . ' years, as tax law requires, then are destroyed', 'The audit trail of this deletion', 'Consent records of the people involved (proof of what they accepted)' );
		return $out;
	}

	/** Restricted archive folder: outside any web route, denied by rule, random file names. */
	public static function archive_dir() {
		$dir = trailingslashit( wp_upload_dir()['basedir'] ) . 'nwc-archive';
		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
		}
		if ( ! file_exists( $dir . '/.htaccess' ) ) {
			file_put_contents( $dir . '/.htaccess', "Require all denied\nDeny from all\n" ); // phpcs:ignore
			file_put_contents( $dir . '/index.html', '' ); // phpcs:ignore
		}
		return $dir;
	}

	/** Seals the Workspace's financial rows into one encrypted file. @return array|WP_Error {path, hash, counts} */
	private static function archive_financial( $w ) {
		global $wpdb;
		$ws    = (int) $w->id;
		$data  = array( 'workspace' => array( 'uid' => $w->uid, 'name' => $w->name, 'account_type' => $w->account_type, 'created_at' => $w->created_at ), 'archived_at' => NWC::now(), 'tables' => array() );
		$count = 0;
		foreach ( self::ARCHIVED as $t ) {
			$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . NWC_DB::t( $t ) . ' WHERE workspace_id = %d', $ws ), ARRAY_A ); // phpcs:ignore
			$data['tables'][ $t ] = $rows;
			$count += count( $rows );
		}
		// The customers the invoices were raised for (their snapshot is on each document as well).
		$data['tables']['customers'] = $wpdb->get_results( $wpdb->prepare( 'SELECT id, name, business_name, phone, email, billing_address, gstin, state_code FROM ' . NWC_DB::t( 'customers' ) . ' WHERE workspace_id = %d AND (id IN (SELECT customer_id FROM ' . NWC_DB::t( 'biz_invoices' ) . ' WHERE workspace_id = %d) OR id IN (SELECT customer_id FROM ' . NWC_DB::t( 'biz_quotes' ) . ' WHERE workspace_id = %d))', $ws, $ws, $ws ), ARRAY_A ); // phpcs:ignore
		if ( ! $count ) {
			return array( 'path' => '', 'hash' => null, 'rows' => 0 );
		}
		$json = wp_json_encode( $data );
		$path = self::archive_dir() . '/' . bin2hex( random_bytes( 16 ) ) . '.bin';
		if ( false === file_put_contents( $path, NWC::seal( (string) $json ) ) ) { // phpcs:ignore
			return NWC::err( 'The financial records could not be archived, so nothing was deleted.', 500, 'nwc_archive_failed' );
		}
		$check = json_decode( NWC::unseal( (string) file_get_contents( $path ) ), true ); // phpcs:ignore
		if ( ! is_array( $check ) || count( $check['tables'] ?? array() ) !== count( $data['tables'] ) ) {
			@unlink( $path ); // phpcs:ignore
			return NWC::err( 'The financial archive could not be verified, so nothing was deleted.', 500, 'nwc_archive_failed' );
		}
		return array( 'path' => basename( $path ), 'hash' => hash_file( 'sha256', $path ), 'rows' => $count );
	}

	/**
	 * Deletes a Workspace for real: financial records into the restricted archive, every other row
	 * and every stored file removed, every membership, invitation, portal link, booking link, share
	 * link and API credential gone, and sessions ended for people left with no access at all. The
	 * Workspace row stays only as a tombstone (status "deleted") so the deletion record has an
	 * anchor. Nothing is claimed unless the database work committed.
	 *
	 * @param string $via   'workdesk' | 'owner_window' | 'platform_admin'.
	 * @return array|WP_Error {ok, deletion_id, deleted, archived, files_removed, sessions_ended}
	 */
	public static function delete_now( $w, $via, $actor_user = 0, $actor_name = '', $reason = '' ) {
		global $wpdb;
		if ( ! $w || NWC_Personal::is_personal( $w ) ) {
			return NWC::err( 'A Personal area cannot be deleted this way.', 403, 'nwc_forbidden' );
		}
		if ( 'deleted' === $w->status ) {
			return NWC::err( 'This Workspace has already been deleted.', 409, 'nwc_already_deleted' );
		}
		$ws      = (int) $w->id;
		$preview = self::deletion_preview( $w );
		$members = array_map( 'intval', $wpdb->get_col( $wpdb->prepare( 'SELECT user_id FROM ' . NWC_DB::t( 'members' ) . ' WHERE workspace_id = %d', $ws ) ) ); // phpcs:ignore
		$arch    = self::archive_financial( $w );
		if ( is_wp_error( $arch ) ) {
			NWC::audit( 'workspace_delete_failed', array( 'workspace_id' => $ws, 'kind' => 'admin', 'user_id' => (int) $actor_user, 'source' => 'owner_window' === $via ? 'system' : $via, 'details' => array( 'error' => $arch->get_error_message() ) ) );
			return $arch;
		}
		$res = NWC::tx( function () use ( $ws ) {
			global $wpdb;
			foreach ( array_merge( self::OPERATIONAL, self::ARCHIVED ) as $t ) {
				$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . NWC_DB::t( $t ) . ' WHERE workspace_id = %d', $ws ) ); // phpcs:ignore
			}
			$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . NWC_DB::t( 'members' ) . ' WHERE workspace_id = %d', $ws ) ); // phpcs:ignore
			$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . NWC_DB::t( 'agency_links' ) . ' WHERE workspace_id = %d', $ws ) ); // phpcs:ignore
			$wpdb->update( NWC_DB::t( 'workspaces' ), array( 'status' => 'deleted', 'slug' => 'deleted-' . $ws . '-' . strtolower( wp_generate_password( 6, false, false ) ), 'delete_after' => null, 'closed_at' => NWC::now(), 'sharing_consent_at' => null, 'updated_at' => NWC::now() ), array( 'id' => $ws ) );
			return $wpdb->last_error ? NWC::err( 'The database refused the deletion: nothing was deleted.', 500, 'nwc_delete_failed' ) : true;
		} );
		if ( is_wp_error( $res ) ) {
			if ( $arch['path'] ) {
				@unlink( self::archive_dir() . '/' . $arch['path'] ); // phpcs:ignore
			}
			NWC::audit( 'workspace_delete_failed', array( 'workspace_id' => $ws, 'kind' => 'admin', 'user_id' => (int) $actor_user, 'details' => array( 'error' => $res->get_error_message() ) ) );
			return $res;
		}
		// Stored files: private uploads (attachments, conversion backups, exports) and public images.
		$up = wp_upload_dir();
		self::rm_tree( trailingslashit( $up['basedir'] ) . 'nwc-private/' . $ws );
		self::rm_tree( trailingslashit( $up['basedir'] ) . 'nwc-public/' . $ws );
		NWC_Features::flush();
		// People with no Workspace left at all are signed out everywhere.
		$ended = 0;
		foreach ( array_unique( $members ) as $uid ) {
			if ( ! NWC_Notify::contexts( $uid ) && class_exists( 'WP_Session_Tokens' ) ) {
				WP_Session_Tokens::get_instance( $uid )->destroy_all();
				$ended++;
			}
		}
		$wpdb->insert( NWC_DB::t( 'deletions' ), array(
			'workspace_id'  => $ws,
			'workspace_uid' => (string) $w->uid,
			'workspace_name' => mb_substr( (string) $w->name, 0, 190 ),
			'account_type'  => (string) $w->account_type,
			'via'           => $via,
			'actor_user'    => (int) $actor_user,
			'actor_name'    => mb_substr( sanitize_text_field( (string) $actor_name ), 0, 190 ),
			'reason'        => sanitize_textarea_field( (string) $reason ),
			'counts'        => wp_json_encode( array( 'deleted' => $preview['deleted'], 'archived' => $preview['archived'], 'files' => $preview['files'], 'members' => $preview['members'], 'sessions_ended' => $ended ) ),
			'archive_file'  => $arch['path'],
			'archive_hash'  => $arch['hash'],
			'retain_until'  => $arch['path'] ? gmdate( 'Y-m-d', strtotime( '+' . self::ARCHIVE_YEARS . ' years' ) ) : null,
			'created_at'    => NWC::now(),
		) );
		$did = (int) $wpdb->insert_id;
		NWC::audit( 'workspace_deleted', array( 'workspace_id' => $ws, 'kind' => 'admin', 'entity' => 'workspace', 'entity_id' => $ws, 'user_id' => (int) $actor_user, 'source' => 'owner_window' === $via ? 'system' : ( 'workdesk' === $via ? 'workdesk' : 'user' ), 'details' => array( 'deletion_id' => $did, 'via' => $via, 'by' => $actor_name, 'deleted' => $preview['deleted'], 'archived_rows' => $arch['rows'], 'files' => $preview['files'] ) ) );
		return array( 'ok' => true, 'deletion_id' => $did, 'deleted' => $preview['deleted'], 'archived' => $preview['archived'], 'files_removed' => $preview['files'], 'members_removed' => $preview['members'], 'sessions_ended' => $ended, 'archive_until' => $arch['path'] ? gmdate( 'Y-m-d', strtotime( '+' . self::ARCHIVE_YEARS . ' years' ) ) : null );
	}

	/** Daily: archives past their legal retention period are destroyed. */
	public static function expire_archives() {
		global $wpdb;
		foreach ( $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . NWC_DB::t( 'deletions' ) . " WHERE archive_file <> '' AND archive_purged_at IS NULL AND retain_until IS NOT NULL AND retain_until < %s", gmdate( 'Y-m-d' ) ) ) as $d ) { // phpcs:ignore
			@unlink( self::archive_dir() . '/' . basename( $d->archive_file ) ); // phpcs:ignore
			$wpdb->update( NWC_DB::t( 'deletions' ), array( 'archive_purged_at' => NWC::now() ), array( 'id' => $d->id ) );
			NWC::audit( 'deletion_archive_destroyed', array( 'workspace_id' => (int) $d->workspace_id, 'kind' => 'admin', 'user_id' => 0, 'source' => 'system', 'details' => array( 'deletion_id' => (int) $d->id ) ) );
		}
	}

	/** Platform administrators only (wp-admin), audited: the decrypted archive of a deleted Workspace. */
	public static function read_archive( $deletion_id ) {
		global $wpdb;
		$d = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . NWC_DB::t( 'deletions' ) . ' WHERE id = %d', $deletion_id ) ); // phpcs:ignore
		if ( ! $d || '' === (string) $d->archive_file || $d->archive_purged_at ) {
			return null;
		}
		$path = self::archive_dir() . '/' . basename( $d->archive_file );
		if ( ! is_readable( $path ) || ! hash_equals( (string) $d->archive_hash, hash_file( 'sha256', $path ) ) ) {
			return null;
		}
		NWC::audit( 'deletion_archive_opened', array( 'workspace_id' => (int) $d->workspace_id, 'kind' => 'data_access', 'details' => array( 'deletion_id' => (int) $d->id ) ) );
		return NWC::unseal( (string) file_get_contents( $path ) ); // phpcs:ignore
	}

	/* ---- WorkDesk's site-signed calls: only for agency-managed Workspaces it really holds ---- */

	private static function site_target( WP_REST_Request $r ) {
		global $wpdb;
		$g = NWC_Connect::auth_site( $r );
		if ( is_wp_error( $g ) ) {
			return $g;
		}
		$b = NWC_REST::body( $r );
		$w = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . NWC_DB::t( 'workspaces' ) . ' WHERE uid = %s', sanitize_text_field( (string) ( $b['workspace_uid'] ?? '' ) ) ) ); // phpcs:ignore
		if ( ! $w || 'deleted' === $w->status ) {
			return NWC::err( 'This Workspace does not exist here (it may already have been deleted).', 404, 'nwc_not_found' );
		}
		if ( 'agency' !== $w->account_type ) {
			return NWC::err( 'This Workspace is owned and paid for by its owner, not managed by NeonDigital, so WorkDesk cannot delete it.', 409, 'nwc_not_agency' );
		}
		$l = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . NWC_DB::t( 'agency_links' ) . ' WHERE workspace_id = %d', $w->id ) ); // phpcs:ignore
		if ( ! $l || ! hash_equals( (string) $l->api_key, (string) ( $b['api_key'] ?? '' ) ) ) {
			return NWC::err( 'This Workspace is not linked to that WorkDesk connection.', 403, 'nwc_forbidden' );
		}
		return array( $w, $b );
	}

	public static function site_delete_preview( WP_REST_Request $r ) {
		$x = self::site_target( $r );
		return is_wp_error( $x ) ? $x : self::deletion_preview( $x[0] );
	}

	public static function site_delete( WP_REST_Request $r ) {
		$x = self::site_target( $r );
		if ( is_wp_error( $x ) ) {
			return $x;
		}
		list( $w, $b ) = $x;
		if ( trim( (string) ( $b['confirm_name'] ?? '' ) ) !== (string) $w->name ) {
			return NWC::err( 'The Workspace name typed does not match, so nothing was deleted.', 422, 'nwc_confirm_required' );
		}
		return self::delete_now( $w, 'workdesk', 0, (string) ( $b['actor'] ?? '' ), (string) ( $b['reason'] ?? '' ) );
	}
}
