<?php
/**
 * Schema and migrations. Every business table carries workspace_id. dbDelta only: additive and
 * safe to repeat. Changing the theme or deactivating the plugin never touches these tables.
 */

defined( 'ABSPATH' ) || exit;

class NWC_DB {

	const VERSION = 9;

	public static function t( $name ) {
		global $wpdb;
		return $wpdb->prefix . 'nwc_' . $name;
	}

	public static function maybe_upgrade() {
		if ( (int) get_option( 'nwc_db_version', 0 ) < self::VERSION ) {
			self::install();
		}
	}

	public static function install() {
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$from = (int) get_option( 'nwc_db_version', 0 );
		foreach ( self::statements() as $sql ) {
			dbDelta( $sql );
		}
		if ( $from && $from < 5 ) {
			self::upgrade_v5();
		}
		if ( $from && $from < 6 ) {
			self::upgrade_v6();
		}
		if ( $from && $from < 8 ) {
			self::upgrade_v8();
		}
		if ( $from && $from < 9 ) {
			self::upgrade_v9();
		}
		NWC_Billing::seed();
		NWC_Billing::seed_addons();
		NWC_Signup::migrate_plans();
		NWC_Legal::install();
		add_role( 'nwc_user', 'Workspace User', array( 'read' => true ) );
		update_option( 'nwc_db_version', self::VERSION );
	}

	/**
	 * Phase 2, additive only. Existing rows keep every value they had:
	 *   - each business's saved weekly hours become availability rules, so open times do not change;
	 *   - services saved before category-based types get type "Other" with their old type as its name.
	 * Nothing is deleted. The old hours and service_type columns stay as they were.
	 */
	public static function upgrade_v5() {
		global $wpdb;
		$av = self::t( 'availability' );
		$now = NWC::now();
		foreach ( $wpdb->get_results( 'SELECT workspace_id, hours FROM ' . self::t( 'business' ) ) as $b ) { // phpcs:ignore
			if ( (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $av WHERE workspace_id = %d", $b->workspace_id ) ) ) { // phpcs:ignore
				continue;
			}
			$h      = json_decode( (string) $b->hours, true );
			$groups = array();
			foreach ( NWC_Bookings::DAYS as $d ) {
				foreach ( (array) ( is_array( $h ) && isset( $h[ $d ] ) ? $h[ $d ] : array() ) as $r ) {
					if ( is_array( $r ) && isset( $r[0], $r[1] ) ) {
						$groups[ $r[0] . '-' . $r[1] ][] = $d;
					}
				}
			}
			foreach ( $groups as $range => $days ) {
				list( $s, $e ) = explode( '-', $range );
				$wpdb->insert( $av, array( 'workspace_id' => (int) $b->workspace_id, 'label' => 'Working hours', 'weekdays' => wp_json_encode( array_values( array_unique( $days ) ) ), 'start_time' => $s, 'end_time' => $e, 'is_active' => 1, 'created_at' => $now, 'updated_at' => $now ) );
			}
		}
		$legacy = array( 'appointment' => 'Appointment', 'class' => 'Class', 'group' => 'Group', 'event' => 'Event' );
		foreach ( $legacy as $k => $label ) {
			$wpdb->query( $wpdb->prepare( 'UPDATE ' . self::t( 'services' ) . " SET type_key = 'other', type_custom = %s WHERE type_key = '' AND service_type = %s", $label, $k ) ); // phpcs:ignore
		}
		NWC_Legal::refresh_privacy_seed();
		update_option( 'nwc_v5_upgraded_at', $now, false );
	}

	/**
	 * Phase 3, additive only. Services keep their timing (setup and clean-up start at 0; the old
	 * "Gap after" value stays stored but unused). The first page of each Workspace becomes its main
	 * booking page; pages that were live stay published. Category keys are left as they were.
	 */
	public static function upgrade_v6() {
		global $wpdb;
		$p = self::t( 'booking_pages' );
		foreach ( $wpdb->get_col( "SELECT DISTINCT workspace_id FROM $p WHERE deleted_at IS NULL" ) as $ws ) { // phpcs:ignore
			$main = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM $p WHERE workspace_id = %d AND deleted_at IS NULL ORDER BY is_default DESC, id ASC LIMIT 1", $ws ) ); // phpcs:ignore
			$wpdb->query( $wpdb->prepare( "UPDATE $p SET kind = 'additional', is_default = 0 WHERE workspace_id = %d AND id <> %d", $ws, $main ) ); // phpcs:ignore
			$wpdb->query( $wpdb->prepare( "UPDATE $p SET kind = 'main', is_default = 1 WHERE id = %d", $main ) ); // phpcs:ignore
		}
		$wpdb->query( "UPDATE $p SET status = 'published', published_at = created_at WHERE is_active = 1" ); // phpcs:ignore
		NWC_Legal::refresh_privacy_seed(); // Draft 1.2, only over an unedited 1.0 or 1.1 draft.
		update_option( 'nwc_v6_upgraded_at', NWC::now(), false );
	}

	/**
	 * Phase 5, additive. The optional sharing switch is gone: lead and booking rows it was holding
	 * are sent on for agency-managed Workspaces and dropped for self-purchased ones (WorkDesk never
	 * receives those). Owners of agency Workspaces are marked as agency clients, so their free
	 * Personal Workspace stays. Nothing is deleted from any business table.
	 */
	public static function upgrade_v8() {
		global $wpdb;
		$o = self::t( 'sync_outbox' );
		$w = self::t( 'workspaces' );
		$wpdb->query( $wpdb->prepare( "UPDATE $o SET status = 'pending', next_attempt_at = %s, last_error = '' WHERE status = 'held' AND workspace_id IN (SELECT id FROM $w WHERE account_type = 'agency')", NWC::now() ) ); // phpcs:ignore
		$wpdb->query( "DELETE FROM $o WHERE status = 'held' AND entity IN ('lead','booking') AND workspace_id NOT IN (SELECT id FROM $w WHERE account_type = 'agency')" ); // phpcs:ignore
		foreach ( $wpdb->get_col( 'SELECT DISTINCT m.user_id FROM ' . self::t( 'members' ) . " m JOIN $w x ON x.id = m.workspace_id WHERE m.role = 'owner' AND x.account_type = 'agency'" ) as $uid ) { // phpcs:ignore
			update_user_meta( (int) $uid, 'nwc_agency_client', 1 );
		}
		NWC_Legal::refresh_privacy_seed(); // Draft 1.3, only over an unedited 1.0, 1.1 or 1.2 draft.
		update_option( 'nwc_v8_upgraded_at', NWC::now(), false );
	}

	/**
	 * Booking holds, additive. A booking already "pending" keeps its seat for the manual hold period
	 * from the upgrade (never released at once); confirmed bookings are unchanged.
	 */
	public static function upgrade_v9() {
		global $wpdb;
		$b = self::t( 'bookings' );
		foreach ( $wpdb->get_results( "SELECT DISTINCT workspace_id FROM $b WHERE status = 'pending' AND hold_expires_at IS NULL AND deleted_at IS NULL" ) as $x ) { // phpcs:ignore
			$hours = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT manual_hold_hours FROM ' . self::t( 'business' ) . ' WHERE workspace_id = %d', $x->workspace_id ) ); // phpcs:ignore
			$until = gmdate( 'Y-m-d H:i:s', time() + max( 1, $hours ? $hours : 24 ) * HOUR_IN_SECONDS );
			$wpdb->query( $wpdb->prepare( "UPDATE $b SET hold_expires_at = %s WHERE workspace_id = %d AND status = 'pending' AND hold_expires_at IS NULL AND deleted_at IS NULL", $until, $x->workspace_id ) ); // phpcs:ignore
		}
		update_option( 'nwc_v9_upgraded_at', NWC::now(), false );
	}

	private static function statements() {
		global $wpdb;
		$c = $wpdb->get_charset_collate();
		$id = 'id bigint(20) unsigned NOT NULL AUTO_INCREMENT';
		$ws = 'workspace_id bigint(20) unsigned NOT NULL';
		$ts = 'created_at datetime NOT NULL,
  updated_at datetime NOT NULL';
		$defs = array(
			'workspaces'    => "$id, uid varchar(64) NOT NULL, name varchar(190) NOT NULL, slug varchar(120) NOT NULL, account_type varchar(20) NOT NULL DEFAULT 'independent', kind varchar(20) NOT NULL DEFAULT 'business', category varchar(60) NOT NULL DEFAULT '', timezone varchar(60) NOT NULL DEFAULT 'Asia/Kolkata', currency char(3) NOT NULL DEFAULT 'INR', status varchar(20) NOT NULL DEFAULT 'active', delete_after datetime NULL, closed_at datetime NULL, sharing_consent_at datetime NULL, sharing_notice_version varchar(20) NOT NULL DEFAULT '', created_by bigint(20) unsigned NOT NULL, $ts, PRIMARY KEY  (id), UNIQUE KEY uid (uid), UNIQUE KEY slug (slug)",
			'members'       => "$id, $ws, user_id bigint(20) unsigned NOT NULL, role varchar(20) NOT NULL DEFAULT 'member', status varchar(20) NOT NULL DEFAULT 'active', job_title varchar(120) NOT NULL DEFAULT '', phone varchar(40) NOT NULL DEFAULT '', hours longtext NULL, breaks longtext NULL, joined_at datetime NOT NULL, PRIMARY KEY  (id), UNIQUE KEY ws_user (workspace_id,user_id), KEY user_id (user_id)",
			'invites'       => "$id, $ws, email varchar(190) NOT NULL, role varchar(20) NOT NULL DEFAULT 'member', token_hash char(64) NOT NULL, expires_at datetime NOT NULL, accepted_at datetime NULL, revoked_at datetime NULL, invited_by bigint(20) unsigned NOT NULL, created_at datetime NOT NULL, PRIMARY KEY  (id), UNIQUE KEY token_hash (token_hash), KEY workspace_id (workspace_id)",
			'plans'         => "$id, code varchar(60) NOT NULL, name varchar(120) NOT NULL, description text NULL, price decimal(14,2) NULL, currency char(3) NOT NULL DEFAULT 'INR', billing_period varchar(10) NOT NULL DEFAULT 'month', features longtext NULL, grace_days int(10) unsigned NOT NULL DEFAULT 7, is_public tinyint(1) NOT NULL DEFAULT 1, is_active tinyint(1) NOT NULL DEFAULT 1, sort int(11) NOT NULL DEFAULT 0, is_trial tinyint(1) NOT NULL DEFAULT 0, trial_days int(10) unsigned NULL, annual_price decimal(14,2) NULL, user_limit int(10) unsigned NULL, addon_codes longtext NULL, renewal_notice_days int(10) unsigned NULL, $ts, PRIMARY KEY  (id), UNIQUE KEY code (code)",
			'addons'        => "$id, code varchar(60) NOT NULL, name varchar(120) NOT NULL, description text NULL, price decimal(14,2) NULL, feature_code varchar(60) NOT NULL, usage_limit int(11) NULL, duration_days int(10) unsigned NOT NULL DEFAULT 30, is_active tinyint(1) NOT NULL DEFAULT 1, $ts, PRIMARY KEY  (id), UNIQUE KEY code (code)",
			'subscriptions' => "$id, $ws, plan_code varchar(60) NOT NULL, pending_plan_code varchar(60) NOT NULL DEFAULT '', status varchar(20) NOT NULL DEFAULT 'trial', billing_cycle varchar(10) NOT NULL DEFAULT 'month', period_start date NULL, period_end date NULL, grace_days int(10) unsigned NOT NULL DEFAULT 7, extended_until date NULL, last_reminder_on date NULL, $ts, PRIMARY KEY  (id), UNIQUE KEY workspace_id (workspace_id), KEY period_end (period_end)",
			'workspace_addons' => "$id, $ws, addon_code varchar(60) NOT NULL, feature_code varchar(60) NOT NULL, usage_limit int(11) NULL, starts_on date NULL, expires_on date NULL, sub_invoice_id bigint(20) unsigned NULL, created_at datetime NOT NULL, PRIMARY KEY  (id), KEY workspace_id (workspace_id)",
			'feature_grants' => "$id, $ws, feature_code varchar(60) NOT NULL, source varchar(20) NOT NULL DEFAULT 'manual', enabled tinyint(1) NOT NULL DEFAULT 1, starts_on date NULL, expires_on date NULL, grace_days int(11) NULL, usage_limit int(11) NULL, notes varchar(255) NOT NULL DEFAULT '', updated_by bigint(20) unsigned NOT NULL DEFAULT 0, updated_at datetime NOT NULL, PRIMARY KEY  (id), UNIQUE KEY ws_feat_src (workspace_id,feature_code,source)",
			'audit'         => "$id, workspace_id bigint(20) unsigned NULL, kind varchar(20) NOT NULL DEFAULT 'activity', source varchar(20) NOT NULL DEFAULT 'user', dedupe_key varchar(64) NULL, user_id bigint(20) unsigned NOT NULL DEFAULT 0, action varchar(60) NOT NULL, entity varchar(30) NOT NULL DEFAULT '', entity_id bigint(20) unsigned NULL, details longtext NULL, ip varchar(64) NOT NULL DEFAULT '', created_at datetime NOT NULL, PRIMARY KEY  (id), KEY ws_time (workspace_id,created_at), KEY entity (entity,entity_id), KEY kind (kind), UNIQUE KEY dedupe_key (dedupe_key)",
			'logins'        => "$id, user_id bigint(20) unsigned NOT NULL DEFAULT 0, login varchar(190) NOT NULL DEFAULT '', method varchar(20) NOT NULL DEFAULT 'password', success tinyint(1) NOT NULL DEFAULT 0, ip varchar(64) NOT NULL DEFAULT '', user_agent varchar(255) NOT NULL DEFAULT '', created_at datetime NOT NULL, PRIMARY KEY  (id), KEY user_id (user_id), KEY ip_time (ip,created_at)",
			'locks'         => 'name varchar(120) NOT NULL, expires_at bigint(20) unsigned NOT NULL, PRIMARY KEY  (name)',

			'projects'      => "$id, $ws, parent_id bigint(20) unsigned NULL, name varchar(190) NOT NULL, description text NULL, status varchar(20) NOT NULL DEFAULT 'active', color varchar(9) NOT NULL DEFAULT '', start_date date NULL, end_date date NULL, created_by bigint(20) unsigned NOT NULL DEFAULT 0, $ts, deleted_at datetime NULL, PRIMARY KEY  (id), KEY workspace_id (workspace_id), KEY parent_id (parent_id)",
			'project_members' => "$id, $ws, project_id bigint(20) unsigned NOT NULL, user_id bigint(20) unsigned NOT NULL, added_at datetime NOT NULL, PRIMARY KEY  (id), UNIQUE KEY pu (project_id,user_id), KEY workspace_id (workspace_id)",
			'tasks'         => "$id, $ws, project_id bigint(20) unsigned NULL, title varchar(255) NOT NULL, description text NULL, status varchar(20) NOT NULL DEFAULT 'todo', priority varchar(20) NOT NULL DEFAULT 'medium', assignee_id bigint(20) unsigned NULL, due_date date NULL, checklist longtext NULL, meeting_id bigint(20) unsigned NULL, reminder_at datetime NULL, reminder_sent tinyint(1) NOT NULL DEFAULT 0, created_by bigint(20) unsigned NOT NULL DEFAULT 0, $ts, deleted_at datetime NULL, PRIMARY KEY  (id), KEY workspace_id (workspace_id), KEY project_id (project_id), KEY assignee_id (assignee_id), KEY due_date (due_date)",
			'milestones'    => "$id, $ws, project_id bigint(20) unsigned NOT NULL, title varchar(190) NOT NULL, due_date date NULL, done tinyint(1) NOT NULL DEFAULT 0, created_by bigint(20) unsigned NOT NULL DEFAULT 0, $ts, deleted_at datetime NULL, PRIMARY KEY  (id), KEY workspace_id (workspace_id), KEY project_id (project_id)",
			'comments'      => "$id, $ws, entity varchar(30) NOT NULL, entity_id bigint(20) unsigned NOT NULL, user_id bigint(20) unsigned NOT NULL, body text NOT NULL, created_at datetime NOT NULL, PRIMARY KEY  (id), KEY entity (workspace_id,entity,entity_id)",
			'files'         => "$id, $ws, entity varchar(30) NOT NULL, entity_id bigint(20) unsigned NOT NULL, name varchar(190) NOT NULL, path varchar(255) NOT NULL, mime varchar(100) NOT NULL DEFAULT '', size bigint(20) unsigned NOT NULL DEFAULT 0, uploaded_by bigint(20) unsigned NOT NULL, created_at datetime NOT NULL, PRIMARY KEY  (id), KEY entity (workspace_id,entity,entity_id)",

			'events'        => "$id, $ws, calendar varchar(20) NOT NULL, project_id bigint(20) unsigned NULL, owner_id bigint(20) unsigned NOT NULL, title varchar(190) NOT NULL, description text NULL, private_note text NULL, location varchar(190) NOT NULL DEFAULT '', event_type varchar(30) NOT NULL DEFAULT 'event', starts_at datetime NOT NULL, ends_at datetime NULL, all_day tinyint(1) NOT NULL DEFAULT 0, rrule longtext NULL, color varchar(9) NOT NULL DEFAULT '', reminder_minutes int(11) NULL, reminder_sent_for datetime NULL, $ts, deleted_at datetime NULL, PRIMARY KEY  (id), KEY ws_cal (workspace_id,calendar), KEY owner_id (owner_id), KEY starts_at (starts_at)",
			'event_participants' => "$id, $ws, event_id bigint(20) unsigned NOT NULL, user_id bigint(20) unsigned NULL, email varchar(190) NOT NULL DEFAULT '', created_at datetime NOT NULL, PRIMARY KEY  (id), KEY event_id (event_id), KEY user_id (user_id)",
			'calendar_shares' => "$id, $ws, owner_id bigint(20) unsigned NOT NULL, user_id bigint(20) unsigned NOT NULL, created_at datetime NOT NULL, PRIMARY KEY  (id), UNIQUE KEY pair (workspace_id,owner_id,user_id), KEY user_id (user_id)",

			'customers'     => "$id, $ws, name varchar(190) NOT NULL, business_name varchar(190) NOT NULL DEFAULT '', contact_person varchar(190) NOT NULL DEFAULT '', phone varchar(40) NOT NULL DEFAULT '', phone_norm varchar(20) NOT NULL DEFAULT '', email varchar(190) NOT NULL DEFAULT '', billing_address text NULL, shipping_address text NULL, gstin varchar(20) NOT NULL DEFAULT '', state_code varchar(4) NOT NULL DEFAULT '', notes text NULL, source varchar(60) NOT NULL DEFAULT 'manual', assigned_to bigint(20) unsigned NULL, marketing_consent tinyint(1) NOT NULL DEFAULT 0, custom longtext NULL, created_by bigint(20) unsigned NOT NULL DEFAULT 0, $ts, deleted_at datetime NULL, PRIMARY KEY  (id), KEY workspace_id (workspace_id), KEY ws_phone (workspace_id,phone_norm), KEY ws_email (workspace_id,email)",
			'leads'         => "$id, $ws, customer_id bigint(20) unsigned NULL, name varchar(190) NOT NULL, phone varchar(40) NOT NULL DEFAULT '', phone_norm varchar(20) NOT NULL DEFAULT '', email varchar(190) NOT NULL DEFAULT '', enquiry varchar(255) NOT NULL DEFAULT '', lead_type varchar(40) NOT NULL DEFAULT 'enquiry', source varchar(60) NOT NULL DEFAULT 'manual', source_ref varchar(60) NOT NULL DEFAULT '', stage varchar(20) NOT NULL DEFAULT 'new', follow_up_on date NULL, assigned_to bigint(20) unsigned NULL, notes text NULL, marketing_consent tinyint(1) NOT NULL DEFAULT 0, custom longtext NULL, created_by bigint(20) unsigned NOT NULL DEFAULT 0, $ts, deleted_at datetime NULL, PRIMARY KEY  (id), KEY workspace_id (workspace_id), KEY stage (workspace_id,stage), KEY ws_phone (workspace_id,phone_norm), KEY ws_email (workspace_id,email)",
			'activities'    => "$id, $ws, entity varchar(30) NOT NULL, entity_id bigint(20) unsigned NOT NULL, kind varchar(30) NOT NULL DEFAULT 'note', note text NULL, user_id bigint(20) unsigned NOT NULL DEFAULT 0, created_at datetime NOT NULL, PRIMARY KEY  (id), KEY entity (workspace_id,entity,entity_id)",

			'business'      => "$ws, display_name varchar(190) NOT NULL DEFAULT '', logo_url varchar(500) NOT NULL DEFAULT '', cover_url varchar(500) NOT NULL DEFAULT '', description text NULL, category varchar(60) NOT NULL DEFAULT '', address text NULL, phone varchar(40) NOT NULL DEFAULT '', email varchar(190) NOT NULL DEFAULT '', map_url varchar(500) NOT NULL DEFAULT '', brand_color varchar(9) NOT NULL DEFAULT '', policies text NULL, socials longtext NULL, hours longtext NULL, gst_registered tinyint(1) NOT NULL DEFAULT 0, gstin varchar(20) NOT NULL DEFAULT '', state_code varchar(4) NOT NULL DEFAULT '', payment_terms text NULL, bank_details text NULL, signature_url varchar(500) NOT NULL DEFAULT '', website varchar(255) NOT NULL DEFAULT '', registration_details text NULL, whatsapp_enabled tinyint(1) NOT NULL DEFAULT 0, auto_invoice varchar(20) NOT NULL DEFAULT 'off', retention_enabled tinyint(1) NOT NULL DEFAULT 0, retention_days int(10) unsigned NOT NULL DEFAULT 30, retention_email varchar(190) NOT NULL DEFAULT '', modules longtext NULL, custom_label varchar(190) NOT NULL DEFAULT '', service_charge_pct decimal(6,2) NOT NULL DEFAULT 0, tax_settings longtext NULL, booking_hold_minutes int(10) unsigned NOT NULL DEFAULT 15, manual_hold_hours int(10) unsigned NOT NULL DEFAULT 24, updated_at datetime NOT NULL, PRIMARY KEY  (workspace_id)",
			'domains'       => "$id, $ws, host varchar(190) NOT NULL, page_id bigint(20) unsigned NULL, verified_at datetime NULL, created_at datetime NOT NULL, PRIMARY KEY  (id), UNIQUE KEY host (host), KEY workspace_id (workspace_id)",
			'closures'      => "$id, $ws, on_date date NOT NULL, label varchar(120) NOT NULL DEFAULT '', staff_id bigint(20) unsigned NULL, space_id bigint(20) unsigned NULL, created_at datetime NOT NULL, PRIMARY KEY  (id), KEY ws_date (workspace_id,on_date)",
			'services'      => "$id, $ws, name varchar(190) NOT NULL, description text NULL, service_type varchar(20) NOT NULL DEFAULT 'appointment', type_key varchar(60) NOT NULL DEFAULT '', type_custom varchar(120) NOT NULL DEFAULT '', category varchar(60) NOT NULL DEFAULT '', duration_min int(10) unsigned NOT NULL DEFAULT 30, buffer_min int(10) unsigned NOT NULL DEFAULT 0, price decimal(14,2) NOT NULL DEFAULT 0, capacity int(10) unsigned NOT NULL DEFAULT 1, staff_ids longtext NULL, is_active tinyint(1) NOT NULL DEFAULT 1, space_ids longtext NULL, setup_min int(10) unsigned NOT NULL DEFAULT 0, cleanup_min int(10) unsigned NOT NULL DEFAULT 0, booking_kind varchar(30) NOT NULL DEFAULT '', waitlist tinyint(1) NOT NULL DEFAULT 0, image_url varchar(500) NOT NULL DEFAULT '', archived_at datetime NULL, pay_mode varchar(20) NOT NULL DEFAULT 'venue', $ts, deleted_at datetime NULL, PRIMARY KEY  (id), KEY workspace_id (workspace_id)",
			'booking_pages' => "$id, $ws, slug varchar(120) NOT NULL, title varchar(190) NOT NULL, intro text NULL, service_ids longtext NULL, min_notice_hours int(10) unsigned NOT NULL DEFAULT 2, max_days_ahead int(10) unsigned NOT NULL DEFAULT 60, allow_reschedule tinyint(1) NOT NULL DEFAULT 1, is_active tinyint(1) NOT NULL DEFAULT 1, is_default tinyint(1) NOT NULL DEFAULT 0, kind varchar(20) NOT NULL DEFAULT 'additional', page_type varchar(30) NOT NULL DEFAULT 'all', staff_id bigint(20) unsigned NULL, space_ids longtext NULL, design longtext NULL, fields longtext NULL, status varchar(20) NOT NULL DEFAULT 'draft', published_at datetime NULL, $ts, deleted_at datetime NULL, PRIMARY KEY  (id), UNIQUE KEY slug (slug), KEY workspace_id (workspace_id)",
			'bookings'      => "$id, $ws, page_id bigint(20) unsigned NULL, service_id bigint(20) unsigned NOT NULL, staff_id bigint(20) unsigned NULL, customer_id bigint(20) unsigned NULL, lead_id bigint(20) unsigned NULL, starts_at datetime NOT NULL, ends_at datetime NOT NULL, party_size int(10) unsigned NOT NULL DEFAULT 1, status varchar(20) NOT NULL DEFAULT 'confirmed', payment_status varchar(20) NOT NULL DEFAULT 'unpaid', legal_hold tinyint(1) NOT NULL DEFAULT 0, whatsapp_consent tinyint(1) NOT NULL DEFAULT 0, customer_name varchar(190) NOT NULL DEFAULT '', phone varchar(40) NOT NULL DEFAULT '', email varchar(190) NOT NULL DEFAULT '', notes text NULL, source varchar(60) NOT NULL DEFAULT 'manual', manage_token_hash char(64) NULL, idempotency_key varchar(64) NULL, reminder_sent tinyint(1) NOT NULL DEFAULT 0, marketing_consent tinyint(1) NOT NULL DEFAULT 0, space_id bigint(20) unsigned NULL, block_start datetime NULL, block_end datetime NULL, override_reason text NULL, override_by bigint(20) unsigned NULL, series_key varchar(40) NULL, credit_id bigint(20) unsigned NULL, custom longtext NULL, waitlisted_at datetime NULL, hold_expires_at datetime NULL, paid_at datetime NULL, release_reason varchar(40) NOT NULL DEFAULT '', created_by bigint(20) unsigned NOT NULL DEFAULT 0, $ts, deleted_at datetime NULL, PRIMARY KEY  (id), KEY workspace_id (workspace_id), KEY ws_start (workspace_id,starts_at), KEY hold (status,hold_expires_at), KEY ws_space (workspace_id,space_id), KEY service_id (service_id), KEY staff_id (staff_id), UNIQUE KEY ws_idem (workspace_id,idempotency_key), UNIQUE KEY manage (manage_token_hash)",

			'number_series' => "$id, $ws, doc_type varchar(20) NOT NULL, prefix varchar(20) NOT NULL DEFAULT '', next_no int(10) unsigned NOT NULL DEFAULT 1, padding int(10) unsigned NOT NULL DEFAULT 4, updated_at datetime NOT NULL, PRIMARY KEY  (id), UNIQUE KEY ws_type (workspace_id,doc_type)",
			'biz_invoices'  => "$id, $ws, doc_no varchar(40) NOT NULL, quote_id bigint(20) unsigned NULL, booking_id bigint(20) unsigned NULL, payment_account_id bigint(20) unsigned NULL, online_pay tinyint(1) NOT NULL DEFAULT 0, customer_id bigint(20) unsigned NULL, customer_snapshot longtext NULL, business_snapshot longtext NULL, invoice_date date NOT NULL, due_date date NULL, place_of_supply varchar(4) NOT NULL DEFAULT '', subtotal decimal(14,2) NOT NULL DEFAULT 0, discount_total decimal(14,2) NOT NULL DEFAULT 0, taxable decimal(14,2) NOT NULL DEFAULT 0, cgst decimal(14,2) NOT NULL DEFAULT 0, sgst decimal(14,2) NOT NULL DEFAULT 0, igst decimal(14,2) NOT NULL DEFAULT 0, tax_total decimal(14,2) NOT NULL DEFAULT 0, grand_total decimal(14,2) NOT NULL DEFAULT 0, amount_paid decimal(14,2) NOT NULL DEFAULT 0, status varchar(20) NOT NULL DEFAULT 'draft', notes text NULL, terms text NULL, bank_details text NULL, share_nonce varchar(32) NOT NULL DEFAULT '', sent_at datetime NULL, cancelled_at datetime NULL, cancel_reason text NULL, created_by bigint(20) unsigned NOT NULL DEFAULT 0, $ts, PRIMARY KEY  (id), UNIQUE KEY ws_no (workspace_id,doc_no), UNIQUE KEY ws_booking (workspace_id,booking_id), KEY status (workspace_id,status), KEY customer_id (customer_id)",
			'biz_quotes'    => "$id, $ws, doc_no varchar(40) NOT NULL, customer_id bigint(20) unsigned NULL, customer_snapshot longtext NULL, business_snapshot longtext NULL, quote_date date NOT NULL, valid_until date NULL, place_of_supply varchar(4) NOT NULL DEFAULT '', subtotal decimal(14,2) NOT NULL DEFAULT 0, discount_total decimal(14,2) NOT NULL DEFAULT 0, taxable decimal(14,2) NOT NULL DEFAULT 0, cgst decimal(14,2) NOT NULL DEFAULT 0, sgst decimal(14,2) NOT NULL DEFAULT 0, igst decimal(14,2) NOT NULL DEFAULT 0, tax_total decimal(14,2) NOT NULL DEFAULT 0, grand_total decimal(14,2) NOT NULL DEFAULT 0, status varchar(20) NOT NULL DEFAULT 'draft', converted_invoice_id bigint(20) unsigned NULL, notes text NULL, terms text NULL, share_nonce varchar(32) NOT NULL DEFAULT '', created_by bigint(20) unsigned NOT NULL DEFAULT 0, $ts, PRIMARY KEY  (id), UNIQUE KEY ws_no (workspace_id,doc_no), KEY customer_id (customer_id)",
			'biz_items'     => "$id, $ws, doc_type varchar(10) NOT NULL, doc_id bigint(20) unsigned NOT NULL, description varchar(500) NOT NULL, hsn varchar(12) NOT NULL DEFAULT '', qty decimal(12,3) NOT NULL DEFAULT 1, unit_price decimal(14,2) NOT NULL DEFAULT 0, discount decimal(14,2) NOT NULL DEFAULT 0, tax_rate decimal(6,2) NOT NULL DEFAULT 0, amount decimal(14,2) NOT NULL DEFAULT 0, sort int(11) NOT NULL DEFAULT 0, PRIMARY KEY  (id), KEY doc (doc_type,doc_id)",
			'biz_payments'  => "$id, $ws, invoice_id bigint(20) unsigned NOT NULL, amount decimal(14,2) NOT NULL, paid_on date NOT NULL, method varchar(20) NOT NULL DEFAULT 'other', reference varchar(120) NOT NULL DEFAULT '', notes text NULL, idempotency_key varchar(64) NULL, transaction_id bigint(20) unsigned NULL, created_by bigint(20) unsigned NOT NULL DEFAULT 0, created_at datetime NOT NULL, voided_at datetime NULL, void_reason text NULL, PRIMARY KEY  (id), KEY invoice_id (invoice_id), UNIQUE KEY inv_idem (invoice_id,idempotency_key)",

			'sub_invoices'  => "$id, $ws, doc_no varchar(40) NOT NULL, purpose varchar(20) NOT NULL DEFAULT 'renewal', plan_code varchar(60) NOT NULL DEFAULT '', addon_code varchar(60) NOT NULL DEFAULT '', period_start date NULL, period_end date NULL, amount decimal(14,2) NOT NULL DEFAULT 0, currency char(3) NOT NULL DEFAULT 'INR', status varchar(20) NOT NULL DEFAULT 'issued', issued_at datetime NOT NULL, paid_at datetime NULL, voided_at datetime NULL, $ts, PRIMARY KEY  (id), UNIQUE KEY doc_no (doc_no), KEY workspace_id (workspace_id)",
			'transactions'  => "$id, workspace_id bigint(20) unsigned NULL, category varchar(20) NOT NULL, ref_id bigint(20) unsigned NOT NULL, gateway varchar(20) NOT NULL, mode varchar(10) NOT NULL, is_real tinyint(1) NOT NULL DEFAULT 0, amount decimal(14,2) NOT NULL, currency char(3) NOT NULL DEFAULT 'INR', status varchar(20) NOT NULL DEFAULT 'created', gateway_order_id varchar(100) NULL, gateway_payment_id varchar(100) NULL, reference varchar(120) NOT NULL DEFAULT '', notes text NULL, recorded_by bigint(20) unsigned NOT NULL DEFAULT 0, $ts, PRIMARY KEY  (id), UNIQUE KEY gw_pay (gateway,gateway_payment_id), UNIQUE KEY gw_order (gateway,gateway_order_id), KEY ref (category,ref_id), KEY workspace_id (workspace_id)",
			'gateway_events' => "$id, gateway varchar(20) NOT NULL, event_id varchar(120) NOT NULL, event varchar(60) NOT NULL DEFAULT '', processed_at datetime NOT NULL, PRIMARY KEY  (id), UNIQUE KEY gw_evt (gateway,event_id)",

			'notifications' => "$id, $ws, user_id bigint(20) unsigned NOT NULL, kind varchar(30) NOT NULL DEFAULT 'info', title varchar(190) NOT NULL, body text NULL, link varchar(255) NOT NULL DEFAULT '', dedupe varchar(120) NULL, read_at datetime NULL, created_at datetime NOT NULL, PRIMARY KEY  (id), KEY user_ws (user_id,workspace_id,read_at), UNIQUE KEY dedupe (user_id,dedupe)",
			'notes'         => "$id, $ws, user_id bigint(20) unsigned NOT NULL, title varchar(190) NOT NULL DEFAULT '', body text NULL, is_private tinyint(1) NOT NULL DEFAULT 1, pinned tinyint(1) NOT NULL DEFAULT 0, $ts, deleted_at datetime NULL, PRIMARY KEY  (id), KEY ws_user (workspace_id,user_id)",
			'goals'         => "$id, $ws, user_id bigint(20) unsigned NOT NULL, title varchar(190) NOT NULL, target_date date NULL, progress int(10) unsigned NOT NULL DEFAULT 0, status varchar(20) NOT NULL DEFAULT 'active', $ts, deleted_at datetime NULL, PRIMARY KEY  (id), KEY workspace_id (workspace_id)",

			'agency_links'  => "$id, $ws, api_key varchar(64) NOT NULL, api_secret text NOT NULL, client_name varchar(190) NOT NULL DEFAULT '', status varchar(20) NOT NULL DEFAULT 'active', agency_connect tinyint(1) NOT NULL DEFAULT 1, package_state varchar(20) NOT NULL DEFAULT '', package_end date NULL, permissions longtext NULL, independent_projects int(10) unsigned NOT NULL DEFAULT 0, entitlements_stale tinyint(1) NOT NULL DEFAULT 1, cache_version int(10) unsigned NOT NULL DEFAULT 1, last_sync_at datetime NULL, linked_at datetime NOT NULL, PRIMARY KEY  (id), UNIQUE KEY workspace_id (workspace_id), UNIQUE KEY api_key (api_key)",
			'webhook_events' => "$id, event_id varchar(64) NOT NULL, event varchar(60) NOT NULL, workspace_id bigint(20) unsigned NULL, status varchar(20) NOT NULL DEFAULT 'processed', received_at datetime NOT NULL, PRIMARY KEY  (id), UNIQUE KEY event_id (event_id), KEY workspace_id (workspace_id)",
			'nonces'        => 'nonce_hash char(64) NOT NULL, expires_at bigint(20) unsigned NOT NULL, PRIMARY KEY  (nonce_hash), KEY expires_at (expires_at)',
			'api_log'       => "$id, workspace_id bigint(20) unsigned NULL, direction varchar(10) NOT NULL DEFAULT 'out', method varchar(8) NOT NULL DEFAULT '', route varchar(190) NOT NULL DEFAULT '', status int(11) NOT NULL DEFAULT 0, error varchar(190) NOT NULL DEFAULT '', created_at datetime NOT NULL, PRIMARY KEY  (id), KEY created_at (created_at)",
			'sync_outbox'   => "$id, $ws, entity varchar(20) NOT NULL, entity_id bigint(20) unsigned NOT NULL, deleted tinyint(1) NOT NULL DEFAULT 0, status varchar(20) NOT NULL DEFAULT 'pending', attempts int(10) unsigned NOT NULL DEFAULT 0, next_attempt_at datetime NOT NULL, last_error varchar(190) NOT NULL DEFAULT '', updated_at datetime NOT NULL, PRIMARY KEY  (id), UNIQUE KEY one_row (entity,entity_id), KEY due (status,next_attempt_at)",

			// v2.
			'consents'      => "$id, user_id bigint(20) unsigned NOT NULL, workspace_id bigint(20) unsigned NULL, kind varchar(30) NOT NULL, version varchar(20) NOT NULL DEFAULT '', granted tinyint(1) NOT NULL DEFAULT 1, method varchar(20) NOT NULL DEFAULT '', ip varchar(64) NOT NULL DEFAULT '', created_at datetime NOT NULL, PRIMARY KEY  (id), KEY user_id (user_id), KEY workspace_id (workspace_id)",
			'identities'    => "$id, user_id bigint(20) unsigned NOT NULL, provider varchar(20) NOT NULL, subject varchar(190) NOT NULL, email varchar(190) NOT NULL DEFAULT '', created_at datetime NOT NULL, last_used_at datetime NULL, PRIMARY KEY  (id), UNIQUE KEY prov_sub (provider,subject), UNIQUE KEY user_prov (user_id,provider)",
			'forms'         => "$id, $ws, kind varchar(20) NOT NULL DEFAULT 'custom', slug varchar(120) NOT NULL, title varchar(190) NOT NULL, description text NULL, fields longtext NULL, settings longtext NULL, status varchar(20) NOT NULL DEFAULT 'draft', views bigint(20) unsigned NOT NULL DEFAULT 0, event_start datetime NULL, event_end datetime NULL, opens_at datetime NULL, closes_at datetime NULL, capacity int(10) unsigned NULL, waitlist tinyint(1) NOT NULL DEFAULT 0, legal_hold tinyint(1) NOT NULL DEFAULT 0, created_by bigint(20) unsigned NOT NULL DEFAULT 0, $ts, deleted_at datetime NULL, PRIMARY KEY  (id), UNIQUE KEY slug (slug), KEY workspace_id (workspace_id)",
			'form_submissions' => "$id, $ws, form_id bigint(20) unsigned NOT NULL, data longtext NULL, name varchar(190) NOT NULL DEFAULT '', email varchar(190) NOT NULL DEFAULT '', phone varchar(40) NOT NULL DEFAULT '', status varchar(20) NOT NULL DEFAULT 'received', customer_id bigint(20) unsigned NULL, lead_id bigint(20) unsigned NULL, idempotency_key varchar(64) NULL, checked_in_at datetime NULL, created_at datetime NOT NULL, deleted_at datetime NULL, PRIMARY KEY  (id), KEY form_id (form_id), KEY workspace_id (workspace_id), UNIQUE KEY form_idem (form_id,idempotency_key)",
			'planner_days'  => "$id, $ws, user_id bigint(20) unsigned NOT NULL, on_date date NOT NULL, priorities longtext NULL, notes text NULL, updated_at datetime NOT NULL, PRIMARY KEY  (id), UNIQUE KEY day_key (workspace_id,user_id,on_date)",
			'meetings'      => "$id, $ws, event_id bigint(20) unsigned NOT NULL, agenda text NULL, notes text NULL, decisions text NULL, meeting_url varchar(500) NOT NULL DEFAULT '', status varchar(20) NOT NULL DEFAULT 'scheduled', created_by bigint(20) unsigned NOT NULL DEFAULT 0, $ts, deleted_at datetime NULL, PRIMARY KEY  (id), UNIQUE KEY event_id (event_id), KEY workspace_id (workspace_id)",
			'portal_tokens' => "$id, $ws, customer_id bigint(20) unsigned NOT NULL, token_hash char(64) NOT NULL, expires_at datetime NOT NULL, revoked_at datetime NULL, last_used_at datetime NULL, created_by bigint(20) unsigned NOT NULL DEFAULT 0, created_at datetime NOT NULL, PRIMARY KEY  (id), UNIQUE KEY token_hash (token_hash), KEY customer_id (customer_id)",
			'payment_accounts' => "$id, $ws, kind varchar(20) NOT NULL DEFAULT 'bank', label varchar(120) NOT NULL DEFAULT '', holder varchar(190) NOT NULL DEFAULT '', bank_name varchar(190) NOT NULL DEFAULT '', account_no text NULL, account_last4 varchar(4) NOT NULL DEFAULT '', ifsc varchar(20) NOT NULL DEFAULT '', branch varchar(190) NOT NULL DEFAULT '', upi_id varchar(120) NOT NULL DEFAULT '', qr_url varchar(500) NOT NULL DEFAULT '', instructions text NULL, is_default tinyint(1) NOT NULL DEFAULT 0, is_active tinyint(1) NOT NULL DEFAULT 1, $ts, deleted_at datetime NULL, PRIMARY KEY  (id), KEY workspace_id (workspace_id)",
			'finance_entries' => "$id, $ws, entry_type varchar(10) NOT NULL DEFAULT 'expense', category varchar(60) NOT NULL DEFAULT '', amount decimal(14,2) NOT NULL DEFAULT 0, on_date date NOT NULL, method varchar(20) NOT NULL DEFAULT 'cash', account_id bigint(20) unsigned NULL, customer_id bigint(20) unsigned NULL, reference varchar(120) NOT NULL DEFAULT '', notes text NULL, created_by bigint(20) unsigned NOT NULL DEFAULT 0, $ts, deleted_at datetime NULL, PRIMARY KEY  (id), KEY ws_date (workspace_id,on_date)",
			'biz_gateways'  => "$ws, mode varchar(10) NOT NULL DEFAULT 'off', test_key varchar(120) NOT NULL DEFAULT '', test_secret text NULL, live_key varchar(120) NOT NULL DEFAULT '', live_secret text NULL, webhook_secret text NULL, live_confirmed tinyint(1) NOT NULL DEFAULT 0, updated_at datetime NOT NULL, PRIMARY KEY  (workspace_id)",
			'drive_links'   => "$ws, account_email varchar(190) NOT NULL DEFAULT '', refresh_token text NULL, access_token text NULL, expires_at bigint(20) unsigned NOT NULL DEFAULT 0, scope text NULL, folder_id varchar(120) NOT NULL DEFAULT '', status varchar(20) NOT NULL DEFAULT 'connected', connected_by bigint(20) unsigned NOT NULL DEFAULT 0, last_error varchar(190) NOT NULL DEFAULT '', $ts, PRIMARY KEY  (workspace_id)",
			'drive_files'   => "$id, $ws, drive_id varchar(120) NOT NULL, name varchar(255) NOT NULL DEFAULT '', mime varchar(120) NOT NULL DEFAULT '', size bigint(20) unsigned NOT NULL DEFAULT 0, is_folder tinyint(1) NOT NULL DEFAULT 0, parent_id varchar(120) NOT NULL DEFAULT '', web_link varchar(500) NOT NULL DEFAULT '', entity varchar(30) NOT NULL DEFAULT '', entity_id bigint(20) unsigned NULL, customer_id bigint(20) unsigned NULL, share_hash char(64) NULL, created_by bigint(20) unsigned NOT NULL DEFAULT 0, $ts, removed_at datetime NULL, PRIMARY KEY  (id), UNIQUE KEY ws_drive (workspace_id,drive_id), KEY entity (workspace_id,entity,entity_id), UNIQUE KEY share_hash (share_hash)",
			// v3.
			'legal_docs'    => "doc_key varchar(20) NOT NULL, draft_md longtext NULL, state varchar(20) NOT NULL DEFAULT 'draft', version varchar(20) NOT NULL DEFAULT '1.0', effective_date varchar(10) NOT NULL DEFAULT '', last_updated varchar(10) NOT NULL DEFAULT '', published_revision bigint(20) unsigned NULL, updated_by bigint(20) unsigned NOT NULL DEFAULT 0, updated_at datetime NOT NULL, PRIMARY KEY  (doc_key)",
			'legal_revisions' => "$id, doc_key varchar(20) NOT NULL, version varchar(20) NOT NULL DEFAULT '', state varchar(20) NOT NULL DEFAULT 'draft', body_md longtext NULL, html longtext NULL, note varchar(255) NOT NULL DEFAULT '', editor_id bigint(20) unsigned NOT NULL DEFAULT 0, is_published tinyint(1) NOT NULL DEFAULT 0, effective_date varchar(10) NOT NULL DEFAULT '', last_updated varchar(10) NOT NULL DEFAULT '', reaccept tinyint(1) NOT NULL DEFAULT 0, change_notice text NULL, confirmations text NULL, created_at datetime NOT NULL, PRIMARY KEY  (id), KEY doc_key (doc_key)",
			'agency_invoices' => "$id, $ws, wd_invoice_id bigint(20) unsigned NOT NULL, invoice_no varchar(60) NOT NULL DEFAULT '', invoice_date date NULL, due_date date NULL, total decimal(14,2) NOT NULL DEFAULT 0, amount_paid decimal(14,2) NOT NULL DEFAULT 0, balance decimal(14,2) NOT NULL DEFAULT 0, status varchar(20) NOT NULL DEFAULT '', status_label varchar(40) NOT NULL DEFAULT '', data longtext NULL, source_stamp bigint(20) unsigned NOT NULL DEFAULT 0, received_at datetime NOT NULL, updated_at datetime NOT NULL, PRIMARY KEY  (id), UNIQUE KEY ws_inv (workspace_id,wd_invoice_id)",
			// v4.
			'project_subscriptions' => "$id, $ws, wd_project_id bigint(20) unsigned NOT NULL, wd_migration_id bigint(20) unsigned NOT NULL, project_name varchar(190) NOT NULL DEFAULT '', plan_code varchar(60) NOT NULL DEFAULT '', package_name varchar(190) NOT NULL DEFAULT '', amount decimal(14,2) NOT NULL DEFAULT 0, billing_cycle varchar(10) NOT NULL DEFAULT 'month', status varchar(20) NOT NULL DEFAULT 'pending', invoice_id bigint(20) unsigned NULL, period_start date NULL, period_end date NULL, $ts, PRIMARY KEY  (id), UNIQUE KEY ws_mig (workspace_id,wd_migration_id), KEY invoice_id (invoice_id), KEY status (status)",
			// v5.
			'availability'  => "$id, $ws, label varchar(120) NOT NULL DEFAULT '', weekdays varchar(80) NOT NULL DEFAULT '', start_time varchar(5) NOT NULL DEFAULT '', end_time varchar(5) NOT NULL DEFAULT '', slot_min int(10) unsigned NULL, start_date date NULL, end_date date NULL, service_ids longtext NULL, space_id bigint(20) unsigned NULL, staff_id bigint(20) unsigned NULL, is_active tinyint(1) NOT NULL DEFAULT 1, created_by bigint(20) unsigned NOT NULL DEFAULT 0, $ts, deleted_at datetime NULL, PRIMARY KEY  (id), KEY workspace_id (workspace_id)",
			'drive_folders' => "$id, $ws, account_email varchar(190) NOT NULL DEFAULT '', entity varchar(30) NOT NULL, entity_id bigint(20) unsigned NOT NULL DEFAULT 0, drive_id varchar(120) NOT NULL, name varchar(255) NOT NULL DEFAULT '', web_link varchar(500) NOT NULL DEFAULT '', created_at datetime NOT NULL, PRIMARY KEY  (id), UNIQUE KEY one_folder (workspace_id,account_email,entity,entity_id), KEY drive_id (drive_id)",
			'retention_runs' => "$id, $ws, status varchar(20) NOT NULL DEFAULT 'noticed', cutoff datetime NOT NULL, bookings_count int(10) unsigned NOT NULL DEFAULT 0, registrations_count int(10) unsigned NOT NULL DEFAULT 0, export_path varchar(255) NOT NULL DEFAULT '', export_hash char(64) NULL, export_expires_at datetime NULL, purge_after datetime NOT NULL, noticed_at datetime NOT NULL, purged_at datetime NULL, cancelled_at datetime NULL, PRIMARY KEY  (id), KEY ws_status (workspace_id,status), UNIQUE KEY export_hash (export_hash)",
			// v6.
			'spaces'        => "$id, $ws, name varchar(190) NOT NULL, kind varchar(30) NOT NULL DEFAULT 'room', description text NULL, capacity int(10) unsigned NOT NULL DEFAULT 1, color varchar(9) NOT NULL DEFAULT '', is_active tinyint(1) NOT NULL DEFAULT 1, sort int(11) NOT NULL DEFAULT 0, $ts, deleted_at datetime NULL, PRIMARY KEY  (id), KEY workspace_id (workspace_id)",
			'booking_credits' => "$id, $ws, customer_id bigint(20) unsigned NULL, service_id bigint(20) unsigned NULL, source_booking_id bigint(20) unsigned NULL, used_booking_id bigint(20) unsigned NULL, reason varchar(190) NOT NULL DEFAULT '', expires_on date NULL, status varchar(20) NOT NULL DEFAULT 'open', created_by bigint(20) unsigned NOT NULL DEFAULT 0, $ts, PRIMARY KEY  (id), KEY workspace_id (workspace_id), KEY customer_id (customer_id), UNIQUE KEY src (source_booking_id)",
			'biz_records'   => "$id, $ws, module varchar(40) NOT NULL, entity varchar(40) NOT NULL, title varchar(255) NOT NULL DEFAULT '', status varchar(30) NOT NULL DEFAULT '', code varchar(60) NULL, customer_id bigint(20) unsigned NULL, parent_id bigint(20) unsigned NULL, ref_id bigint(20) unsigned NULL, staff_id bigint(20) unsigned NULL, space_id bigint(20) unsigned NULL, amount decimal(14,2) NOT NULL DEFAULT 0, qty decimal(14,3) NOT NULL DEFAULT 0, on_date date NULL, end_date date NULL, data longtext NULL, created_by bigint(20) unsigned NOT NULL DEFAULT 0, $ts, deleted_at datetime NULL, PRIMARY KEY  (id), KEY ws_ent (workspace_id,entity,status), KEY parent_id (parent_id), KEY customer_id (customer_id), UNIQUE KEY ws_code (workspace_id,entity,code)",
			'biz_lists'     => "$id, $ws, list_key varchar(60) NOT NULL, value varchar(120) NOT NULL, created_by bigint(20) unsigned NOT NULL DEFAULT 0, created_at datetime NOT NULL, PRIMARY KEY  (id), UNIQUE KEY ws_val (workspace_id,list_key,value)",
			'pos_orders'    => "$id, $ws, order_no varchar(30) NOT NULL, channel varchar(20) NOT NULL DEFAULT 'dine_in', table_ids longtext NULL, status varchar(20) NOT NULL DEFAULT 'open', customer_id bigint(20) unsigned NULL, customer_name varchar(190) NOT NULL DEFAULT '', phone varchar(40) NOT NULL DEFAULT '', guests int(10) unsigned NOT NULL DEFAULT 0, waiter_id bigint(20) unsigned NULL, subtotal decimal(14,2) NOT NULL DEFAULT 0, discount decimal(14,2) NOT NULL DEFAULT 0, service_charge decimal(14,2) NOT NULL DEFAULT 0, tax decimal(14,2) NOT NULL DEFAULT 0, total decimal(14,2) NOT NULL DEFAULT 0, paid decimal(14,2) NOT NULL DEFAULT 0, invoice_id bigint(20) unsigned NULL, merged_into bigint(20) unsigned NULL, split_from bigint(20) unsigned NULL, source varchar(20) NOT NULL DEFAULT 'staff', notes text NULL, idempotency_key varchar(64) NULL, created_by bigint(20) unsigned NOT NULL DEFAULT 0, closed_at datetime NULL, $ts, PRIMARY KEY  (id), UNIQUE KEY ws_no (workspace_id,order_no), KEY ws_status (workspace_id,status), UNIQUE KEY ws_idem (workspace_id,idempotency_key)",
			'pos_items'     => "$id, $ws, order_id bigint(20) unsigned NOT NULL, menu_item_id bigint(20) unsigned NULL, name varchar(190) NOT NULL, variant varchar(120) NOT NULL DEFAULT '', addons longtext NULL, qty decimal(10,3) NOT NULL DEFAULT 1, unit_price decimal(14,2) NOT NULL DEFAULT 0, amount decimal(14,2) NOT NULL DEFAULT 0, tax_rate decimal(6,2) NOT NULL DEFAULT 0, kot_id bigint(20) unsigned NULL, status varchar(20) NOT NULL DEFAULT 'new', notes varchar(255) NOT NULL DEFAULT '', created_by bigint(20) unsigned NOT NULL DEFAULT 0, $ts, PRIMARY KEY  (id), KEY order_id (order_id), KEY kot_id (kot_id)",
			'pos_kots'      => "$id, $ws, order_id bigint(20) unsigned NOT NULL, kot_no int(10) unsigned NOT NULL, station varchar(20) NOT NULL DEFAULT 'kitchen', status varchar(20) NOT NULL DEFAULT 'new', $ts, PRIMARY KEY  (id), KEY ws_status (workspace_id,status), KEY order_id (order_id)",
			'stays'         => "$id, $ws, room_id bigint(20) unsigned NOT NULL, room_type_id bigint(20) unsigned NULL, customer_id bigint(20) unsigned NULL, guest_name varchar(190) NOT NULL DEFAULT '', phone varchar(40) NOT NULL DEFAULT '', email varchar(190) NOT NULL DEFAULT '', check_in date NOT NULL, check_out date NOT NULL, adults int(10) unsigned NOT NULL DEFAULT 1, children int(10) unsigned NOT NULL DEFAULT 0, source varchar(40) NOT NULL DEFAULT 'direct', status varchar(20) NOT NULL DEFAULT 'reserved', nightly_rate decimal(14,2) NOT NULL DEFAULT 0, deposit decimal(14,2) NOT NULL DEFAULT 0, charges longtext NULL, total decimal(14,2) NOT NULL DEFAULT 0, invoice_id bigint(20) unsigned NULL, notes text NULL, checked_in_at datetime NULL, checked_out_at datetime NULL, created_by bigint(20) unsigned NOT NULL DEFAULT 0, $ts, PRIMARY KEY  (id), KEY ws_room (workspace_id,room_id,check_in), KEY ws_status (workspace_id,status)",
			'checkins'      => "$id, $ws, record_id bigint(20) unsigned NOT NULL, customer_id bigint(20) unsigned NULL, branch varchar(120) NOT NULL DEFAULT '', method varchar(20) NOT NULL DEFAULT 'manual', in_at datetime NOT NULL, out_at datetime NULL, created_by bigint(20) unsigned NOT NULL DEFAULT 0, PRIMARY KEY  (id), KEY ws_in (workspace_id,in_at), KEY record_id (record_id)",
			'attendance'    => "$id, $ws, batch_id bigint(20) unsigned NOT NULL, session_date date NOT NULL, student_id bigint(20) unsigned NOT NULL, status varchar(20) NOT NULL DEFAULT 'present', note varchar(255) NOT NULL DEFAULT '', marked_by bigint(20) unsigned NOT NULL DEFAULT 0, $ts, PRIMARY KEY  (id), UNIQUE KEY one_mark (batch_id,session_date,student_id), KEY workspace_id (workspace_id)",
			// v8.
			'deletions'     => "$id, $ws, workspace_uid varchar(64) NOT NULL DEFAULT '', workspace_name varchar(190) NOT NULL DEFAULT '', account_type varchar(20) NOT NULL DEFAULT '', via varchar(20) NOT NULL DEFAULT '', actor_user bigint(20) unsigned NOT NULL DEFAULT 0, actor_name varchar(190) NOT NULL DEFAULT '', reason text NULL, counts longtext NULL, archive_file varchar(80) NOT NULL DEFAULT '', archive_hash char(64) NULL, retain_until date NULL, archive_purged_at datetime NULL, created_at datetime NOT NULL, PRIMARY KEY  (id), KEY workspace_id (workspace_id)",
			'conversions'   => "$id, $ws, from_category varchar(60) NOT NULL DEFAULT '', to_category varchar(60) NOT NULL DEFAULT '', reason text NULL, consent_note text NULL, consent_at datetime NULL, requested_via varchar(20) NOT NULL DEFAULT 'workspace', staff_name varchar(190) NOT NULL DEFAULT '', user_id bigint(20) unsigned NOT NULL DEFAULT 0, status varchar(20) NOT NULL DEFAULT 'started', backup_path varchar(255) NOT NULL DEFAULT '', backup_hash char(64) NULL, counts longtext NULL, error text NULL, created_at datetime NOT NULL, completed_at datetime NULL, restored_at datetime NULL, PRIMARY KEY  (id), KEY workspace_id (workspace_id)",
		);
		$out = array();
		foreach ( $defs as $name => $cols ) {
			// dbDelta needs each column on its own line.
			$lines = preg_split( '/,\s+(?=[a-zA-Z_][a-zA-Z0-9_]* [a-z(]|PRIMARY KEY|UNIQUE KEY|KEY )/', $cols );
			$out[] = 'CREATE TABLE ' . self::t( $name ) . " (\n  " . implode( ",\n  ", $lines ) . "\n) $c;";
		}
		return $out;
	}
}
