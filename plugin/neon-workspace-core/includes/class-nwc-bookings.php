<?php
/**
 * Booking engine: business profile, availability, slots, public booking pages and their records.
 * A booking is one row. Calendar, CRM and the consolidated sync refer to it; none copy it.
 * Every public page shows the business's identity, with discreet NeonDigital footer credit.
 */

defined( 'ABSPATH' ) || exit;

class NWC_Bookings {

	const DAYS = array( 'mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun' );

	/** Opening hours start empty. Bookable times come only from the availability the business sets. */
	public static function default_hours() {
		return array_fill_keys( self::DAYS, array() );
	}

	public static function routes() {
		NWC_REST::route( '/business', 'GET', array( __CLASS__, 'get_business' ) );
		NWC_REST::route( '/business', 'PUT', array( __CLASS__, 'save_business' ) );
		NWC_REST::route( '/business/image', 'POST', array( __CLASS__, 'upload_image' ) );
		NWC_REST::route( '/bookings', 'GET', array( __CLASS__, 'index' ) );
		NWC_REST::route( '/bookings', 'POST', array( __CLASS__, 'create_internal' ) );
		NWC_REST::route( '/bookings/slots', 'GET', array( __CLASS__, 'slots_internal' ) );
		NWC_REST::route( '/bookings/report', 'GET', array( __CLASS__, 'report' ) );
		NWC_REST::route( '/bookings/credits', 'GET', array( __CLASS__, 'credits' ) );
		NWC_REST::route( '/bookings/conflicts', 'GET', array( __CLASS__, 'conflicts_rest' ) );
		NWC_REST::route( '/booking-pages/wizard', 'POST', array( __CLASS__, 'wizard' ) );
		NWC_REST::route( '/booking-pages/(?P<id>\d+)/preview', 'GET', array( __CLASS__, 'preview' ) );
		NWC_REST::route( '/booking-pages/limits', 'GET', array( __CLASS__, 'limits' ) );
		NWC_REST::route( '/bookings/(?P<id>\d+)', 'GET', array( __CLASS__, 'show' ) );
		NWC_REST::route( '/bookings/(?P<id>\d+)', 'PUT,PATCH', array( __CLASS__, 'update' ) );
		NWC_REST::route( '/staff/(?P<id>\d+)/availability', 'PUT', array( __CLASS__, 'save_staff' ) );
		NWC_REST::route( '/public/book/(?P<slug>[a-z0-9-]+)', 'GET', array( __CLASS__, 'public_page' ), 'public' );
		NWC_REST::route( '/public/book/(?P<slug>[a-z0-9-]+)/slots', 'GET', array( __CLASS__, 'public_slots' ), 'public' );
		NWC_REST::route( '/public/book/(?P<slug>[a-z0-9-]+)', 'POST', array( __CLASS__, 'public_create' ), 'public' );
		NWC_REST::route( '/public/manage/(?P<token>[A-Za-z0-9_-]+)', 'GET', array( __CLASS__, 'public_manage' ), 'public' );
		NWC_REST::route( '/public/manage/(?P<token>[A-Za-z0-9_-]+)', 'POST', array( __CLASS__, 'public_manage_action' ), 'public' );
	}

	/* ---------------------------- Resource hooks ---------------------------- */

	public static function before_service( $row, $old = null, $in = array() ) {
		if ( isset( $row['duration_min'] ) && ( $row['duration_min'] < 5 || $row['duration_min'] > 1440 ) ) {
			return NWC::err( 'Duration must be between 5 minutes and 24 hours.' );
		}
		if ( isset( $row['capacity'] ) ) {
			$row['capacity'] = max( 1, $row['capacity'] );
		}
		// Callers from before category-based types send only service_type: keep them working.
		$legacy = array( 'appointment' => 'Appointment', 'class' => 'Class', 'group' => 'Group', 'event' => 'Event' );
		if ( ! array_key_exists( 'type_key', $row ) && ( ! $old || '' === (string) $old->type_key ) ) {
			$st = is_array( $in ) && isset( $in['service_type'] ) && isset( $legacy[ $in['service_type'] ] ) ? $in['service_type'] : ( $old ? $old->service_type : 'appointment' );
			$row['type_key']    = NWC_Catalog::OTHER;
			$row['type_custom'] = $legacy[ $st ] ?? 'Appointment';
		}
		$biz = self::business( NWC_Tenancy::$current->id );
		$row = NWC_Catalog::apply_type( $row, $old, $biz ? $biz->category : '' );
		if ( is_wp_error( $row ) ) {
			return $row;
		}
		if ( isset( $row['staff_ids'] ) ) {
			$ids = array_values( array_filter( array_map( 'absint', (array) json_decode( $row['staff_ids'], true ) ) ) );
			foreach ( $ids as $u ) {
				if ( ! NWC_Tenancy::member_row( NWC_Tenancy::$current->id, $u ) ) {
					return NWC::err( 'Staff must be members of this workspace.' );
				}
			}
			$row['staff_ids'] = wp_json_encode( $ids );
		}
		if ( isset( $row['space_ids'] ) ) {
			$ids = array_values( array_filter( array_map( 'absint', (array) json_decode( $row['space_ids'], true ) ) ) );
			foreach ( $ids as $sp ) {
				if ( ! self::space( NWC_Tenancy::$current->id, $sp ) ) {
					return NWC::err( 'A selected space was not found.', 404, 'nwc_not_found' );
				}
			}
			$row['space_ids'] = wp_json_encode( $ids );
		}
		foreach ( array( 'setup_min', 'cleanup_min' ) as $k ) {
			if ( isset( $row[ $k ] ) && $row[ $k ] > 240 ) {
				return NWC::err( 'Setup and clean-up time can be up to 4 hours.' );
			}
		}
		if ( isset( $row['booking_kind'] ) && '' !== $row['booking_kind'] && ! isset( NWC_Industry::BOOKING_KINDS[ $row['booking_kind'] ] ) ) {
			return NWC::err( 'Choose a valid booking type.' );
		}
		if ( ! empty( $row['type_custom'] ) && NWC_Catalog::OTHER === ( $row['type_key'] ?? '' ) ) {
			NWC_Industry::remember( NWC_Tenancy::$current->id, 'service_type', $row['type_custom'] ); // Kept for reuse.
		}
		return $row;
	}

	public static function decorate_service( $s ) {
		$s->type_label = NWC_Catalog::type_label( (string) $s->type_key, (string) $s->type_custom );
		$s->kind_label = NWC_Industry::BOOKING_KINDS[ (string) ( $s->booking_kind ?? '' ) ] ?? '';
		unset( $s->buffer_min );
		return $s;
	}

	public static function space( $workspace_id, $id ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . NWC_DB::t( 'spaces' ) . ' WHERE id = %d AND workspace_id = %d AND deleted_at IS NULL', $id, $workspace_id ) ); // phpcs:ignore
	}

	/** One availability rule: times, weekdays, optional dates, optional services. */
	public static function before_availability( $row, $old, $in ) {
		global $wpdb;
		$hm = '/^([01]\d|2[0-3]):[0-5]\d$/';
		$s  = array_key_exists( 'start_time', $row ) ? (string) $row['start_time'] : ( $old ? $old->start_time : '' );
		$e  = array_key_exists( 'end_time', $row ) ? (string) $row['end_time'] : ( $old ? $old->end_time : '' );
		if ( ! preg_match( $hm, $s ) || ! preg_match( $hm, $e ) ) {
			return NWC::err( 'Enter start and end times as HH:MM.' );
		}
		if ( $s >= $e ) {
			return NWC::err( 'The end time must be after the start time.' );
		}
		if ( array_key_exists( 'weekdays', $row ) ) {
			$days = (array) json_decode( (string) $row['weekdays'], true );
			$days = array_values( array_intersect( self::DAYS, array_map( 'strval', $days ) ) );
			$row['weekdays'] = wp_json_encode( $days ); // Empty list means every day.
		}
		if ( array_key_exists( 'slot_min', $row ) && null !== $row['slot_min'] && '' !== $row['slot_min'] ) {
			$n = (int) $row['slot_min'];
			if ( 0 === $n ) {
				$row['slot_min'] = null;
			} elseif ( $n < 5 || $n > 1440 ) {
				return NWC::err( 'Slot length must be between 5 minutes and 24 hours.' );
			}
		}
		$sd = array_key_exists( 'start_date', $row ) ? $row['start_date'] : ( $old ? $old->start_date : null );
		$ed = array_key_exists( 'end_date', $row ) ? $row['end_date'] : ( $old ? $old->end_date : null );
		if ( $sd && $ed && $ed < $sd ) {
			return NWC::err( 'The end date must be on or after the start date.' );
		}
		if ( array_key_exists( 'service_ids', $row ) ) {
			$ids = array_values( array_filter( array_map( 'absint', (array) json_decode( (string) $row['service_ids'], true ) ) ) );
			foreach ( $ids as $sid ) {
				if ( ! self::service( NWC_Tenancy::$current->id, $sid ) ) {
					return NWC::err( 'A selected service was not found.', 404, 'nwc_not_found' );
				}
			}
			$row['service_ids'] = wp_json_encode( $ids );
		}
		return $row;
	}

	/**
	 * Rules that open this service on this local date. A rule tied to a space or an instructor
	 * opens only that space or instructor: availability is per resource, not per page.
	 */
	public static function rules_for( $workspace_id, $service_id, $date ) {
		global $wpdb;
		$day  = self::DAYS[ (int) ( new DateTimeImmutable( $date . ' 12:00:00', new DateTimeZone( 'UTC' ) ) )->format( 'N' ) - 1 ];
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . NWC_DB::t( 'availability' ) . ' WHERE workspace_id = %d AND is_active = 1 AND deleted_at IS NULL AND ( start_date IS NULL OR start_date <= %s ) AND ( end_date IS NULL OR end_date >= %s ) ORDER BY start_time ASC, id ASC', $workspace_id, $date, $date ) ); // phpcs:ignore
		return array_values( array_filter( $rows, function ( $a ) use ( $day, $service_id ) {
			$days = (array) json_decode( (string) $a->weekdays, true );
			$svc  = array_map( 'intval', (array) json_decode( (string) $a->service_ids, true ) );
			return ( ! $days || in_array( $day, $days, true ) ) && ( ! $svc || in_array( (int) $service_id, $svc, true ) );
		} ) );
	}

	public static function before_page( $row, $old ) {
		global $wpdb;
		$t    = NWC_DB::t( 'booking_pages' );
		if ( isset( $row['design'] ) ) { // Phase 5: public pages share one premium design; no page colour.
			$d = is_array( $row['design'] ) ? $row['design'] : json_decode( (string) $row['design'], true );
			if ( is_array( $d ) ) {
				unset( $d['brand_color'] );
				$row['design'] = is_array( $row['design'] ) ? $d : wp_json_encode( $d );
			}
		}
		$base = isset( $row['slug'] ) && '' !== $row['slug'] ? sanitize_title( $row['slug'] ) : ( $old ? $old->slug : sanitize_title( NWC_Tenancy::$current->slug . '-' . ( $row['title'] ?? 'booking' ) ) );
		$first = ! $old && ! (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $t WHERE workspace_id = %d AND deleted_at IS NULL", NWC_Tenancy::$current->id ) ); // phpcs:ignore
		if ( $first && empty( $row['slug'] ) ) {
			$base = NWC_Tenancy::$current->slug; // First page: workspace.neondigital.in/book/business-name.
		}
		if ( in_array( $base, array( 'manage', 'admin' ), true ) ) {
			return NWC::err( 'That address is reserved. Choose another.' );
		}
		$slug = $base;
		$n    = 1;
		while ( $wpdb->get_var( $wpdb->prepare( "SELECT id FROM $t WHERE slug = %s AND id <> %d", $slug, $old ? $old->id : 0 ) ) ) { // phpcs:ignore
			if ( isset( $row['slug'] ) && '' !== $row['slug'] && $old && $row['slug'] !== $old->slug ) {
				return NWC::err( 'That address is already taken.' );
			}
			$n++;
			$slug = $base . '-' . $n;
		}
		$row['slug'] = $slug;
		if ( $first ) {
			$row['is_default'] = 1;
			$row['kind']       = 'main';
		} elseif ( ! $old ) {
			$row['kind'] = 'additional';
			$lim         = self::page_limit( NWC_Tenancy::$current->id );
			$have        = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $t WHERE workspace_id = %d AND deleted_at IS NULL", NWC_Tenancy::$current->id ) ); // phpcs:ignore
			if ( $have >= $lim ) {
				return NWC::err( 'You have ' . $have . ' booking pages: your main page and ' . ( $lim - 1 ) . ' more. Buy an extra booking page add-on to create another.', 402, 'nwc_limit_reached', array( 'feature' => 'booking_pages', 'limit' => $lim ) );
			}
		}
		if ( ! $old && ! isset( $row['status'] ) ) {
			$row['status'] = 'published'; // The guided workflow saves drafts explicitly.
		}
		if ( isset( $row['status'] ) && 'published' === $row['status'] && ( ! $old || 'published' !== $old->status ) ) {
			$row['published_at'] = NWC::now();
		}
		if ( isset( $row['space_ids'] ) ) {
			$ids = array_values( array_filter( array_map( 'absint', (array) json_decode( $row['space_ids'], true ) ) ) );
			foreach ( $ids as $sp ) {
				if ( ! self::space( NWC_Tenancy::$current->id, $sp ) ) {
					return NWC::err( 'A selected space was not found.', 404, 'nwc_not_found' );
				}
			}
			$row['space_ids'] = wp_json_encode( $ids );
		}
		if ( isset( $row['fields'] ) ) { // Extra customer questions: label, type text|textarea|select, options, required.
			$clean = array();
			foreach ( array_slice( (array) json_decode( $row['fields'], true ), 0, 15 ) as $f ) {
				$label = mb_substr( trim( (string) ( $f['label'] ?? '' ) ), 0, 120 );
				if ( '' === $label ) {
					continue;
				}
				$clean[] = array( 'key' => 'f_' . substr( md5( $label ), 0, 8 ), 'label' => $label, 'type' => in_array( $f['type'] ?? '', array( 'text', 'textarea', 'select' ), true ) ? $f['type'] : 'text', 'options' => array_values( array_slice( array_map( 'strval', (array) ( $f['options'] ?? array() ) ), 0, 20 ) ), 'required' => ! empty( $f['required'] ) );
			}
			$row['fields'] = wp_json_encode( $clean );
		}
		if ( isset( $row['service_ids'] ) ) {
			$ids = array_values( array_filter( array_map( 'absint', (array) json_decode( $row['service_ids'], true ) ) ) );
			foreach ( $ids as $sid ) {
				if ( (int) $wpdb->get_var( $wpdb->prepare( 'SELECT workspace_id FROM ' . NWC_DB::t( 'services' ) . ' WHERE id = %d', $sid ) ) !== (int) NWC_Tenancy::$current->id ) { // phpcs:ignore
					return NWC::err( 'A selected service was not found.', 404, 'nwc_not_found' );
				}
			}
			$row['service_ids'] = wp_json_encode( $ids );
		}
		return $row;
	}

	public static function decorate_page( $p ) {
		$p->public_url = home_url( '/book/' . $p->slug );
		$p->is_main    = 'main' === ( $p->kind ?? '' );
		return $p;
	}

	/** Pages included: one main and four more. Each extra-page add-on adds its usage limit (default 1). */
	public static function page_limit( $workspace_id ) {
		global $wpdb;
		$extra = 0;
		$today = gmdate( 'Y-m-d' );
		foreach ( $wpdb->get_results( $wpdb->prepare( 'SELECT usage_limit, starts_on, expires_on FROM ' . NWC_DB::t( 'workspace_addons' ) . " WHERE workspace_id = %d AND feature_code = 'booking_pages'", $workspace_id ) ) as $a ) { // phpcs:ignore
			if ( ( ! $a->starts_on || $a->starts_on <= $today ) && ( ! $a->expires_on || $a->expires_on >= $today ) ) {
				$extra += null === $a->usage_limit ? 1 : max( 0, (int) $a->usage_limit );
			}
		}
		foreach ( $wpdb->get_results( $wpdb->prepare( 'SELECT usage_limit FROM ' . NWC_DB::t( 'feature_grants' ) . " WHERE workspace_id = %d AND feature_code = 'booking_pages' AND enabled = 1", $workspace_id ) ) as $g ) { // phpcs:ignore
			$extra += max( 0, (int) $g->usage_limit );
		}
		return 5 + $extra;
	}

	/** The main page can be switched off but not deleted: it is the Workspace's booking address. */
	public static function guard_page_delete( $old ) {
		return 'main' === $old->kind ? NWC::err( 'The main booking page cannot be deleted. You can deactivate it instead.', 409, 'nwc_conflict' ) : true;
	}

	/* ---------------------------- Business profile ---------------------------- */

	public static function business( $workspace_id ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . NWC_DB::t( 'business' ) . ' WHERE workspace_id = %d', $workspace_id ) ); // phpcs:ignore
	}

	public static function get_business() {
		$b = self::business( NWC_Tenancy::$current->id );
		if ( ! $b ) {
			return NWC::err( 'Not found.', 404, 'nwc_not_found' );
		}
		$b->hours   = json_decode( (string) $b->hours, true );
		$b->category_key   = NWC_Catalog::category_key( (string) $b->category );
		$b->category_label = NWC_Catalog::category_label( (string) $b->category );
		$b->socials = json_decode( (string) $b->socials, true );
		return $b;
	}

	private static function clean_hours( $h ) {
		$out = array();
		foreach ( self::DAYS as $d ) {
			$out[ $d ] = array();
			foreach ( (array) ( is_array( $h ) && isset( $h[ $d ] ) ? $h[ $d ] : array() ) as $r ) {
				if ( is_array( $r ) && isset( $r[0], $r[1] ) && preg_match( '/^([01]\d|2[0-3]):[0-5]\d$/', (string) $r[0] ) && preg_match( '/^([01]\d|2[0-3]):[0-5]\d$/', (string) $r[1] ) && $r[0] < $r[1] ) {
					$out[ $d ][] = array( $r[0], $r[1] );
				}
			}
		}
		return $out;
	}

	public static function save_business( WP_REST_Request $r ) {
		global $wpdb;
		if ( ! NWC_Tenancy::can( 'settings.manage' ) && ! NWC_Tenancy::can( 'bookings.manage' ) && ! NWC_Tenancy::can( 'invoices.write' ) ) {
			return NWC::err( 'Your role does not allow this.', 403, 'nwc_forbidden' );
		}
		$b   = NWC_REST::body( $r );
		$row = array( 'updated_at' => NWC::now() );
		if ( array_key_exists( 'category', $b ) ) {
			$b['category'] = NWC_Catalog::category_key( (string) $b['category'] );
			$cur           = self::business( NWC_Tenancy::$current->id );
			// Phase 3: a business type is chosen once; later changes go through the controlled conversion.
			if ( $cur && '' !== (string) $cur->category && NWC_Catalog::category_key( $cur->category ) !== $b['category'] ) {
				return NWC::err( 'The business type changes through Settings → Business type, which shows what is kept and removed first.', 409, 'nwc_use_conversion' );
			}
		}
		foreach ( array( 'display_name' => 190, 'category' => 60, 'phone' => 40, 'gstin' => 20, 'state_code' => 4 ) as $k => $len ) {
			if ( array_key_exists( $k, $b ) ) {
				$row[ $k ] = mb_substr( sanitize_text_field( (string) $b[ $k ] ), 0, $len );
			}
		}
		if ( array_key_exists( 'website', $b ) ) {
			$row['website'] = esc_url_raw( (string) $b['website'], array( 'http', 'https' ) );
		}
		if ( array_key_exists( 'whatsapp_enabled', $b ) ) {
			$row['whatsapp_enabled'] = empty( $b['whatsapp_enabled'] ) ? 0 : 1;
		}
		if ( array_key_exists( 'auto_invoice', $b ) && in_array( $b['auto_invoice'], array( 'off', 'confirmed', 'paid' ), true ) ) {
			$row['auto_invoice'] = $b['auto_invoice'];
		}
		if ( isset( $b['booking_hold_minutes'] ) ) { // Seat hold while a customer pays online: 5 minutes to 2 hours.
			$row['booking_hold_minutes'] = max( 5, min( 120, (int) $b['booking_hold_minutes'] ) );
		}
		if ( isset( $b['manual_hold_hours'] ) ) { // Seat hold for a booking staff mark "pending": 1 hour to 7 days.
			$row['manual_hold_hours'] = max( 1, min( 168, (int) $b['manual_hold_hours'] ) );
		}
		foreach ( array( 'description', 'address', 'policies', 'payment_terms', 'bank_details', 'registration_details' ) as $k ) {
			if ( array_key_exists( $k, $b ) ) {
				$row[ $k ] = sanitize_textarea_field( (string) $b[ $k ] );
			}
		}
		foreach ( array( 'logo_url', 'cover_url', 'map_url', 'signature_url' ) as $k ) {
			if ( array_key_exists( $k, $b ) ) {
				$row[ $k ] = esc_url_raw( (string) $b[ $k ], array( 'http', 'https' ) );
			}
		}
		if ( array_key_exists( 'email', $b ) ) {
			$row['email'] = sanitize_email( (string) $b['email'] );
		}
		if ( array_key_exists( 'brand_color', $b ) ) {
			$row['brand_color'] = (string) sanitize_hex_color( (string) $b['brand_color'] );
		}
		if ( array_key_exists( 'gst_registered', $b ) ) {
			$row['gst_registered'] = empty( $b['gst_registered'] ) ? 0 : 1;
		}
		if ( array_key_exists( 'hours', $b ) ) {
			$row['hours'] = wp_json_encode( self::clean_hours( $b['hours'] ) );
		}
		if ( array_key_exists( 'socials', $b ) && is_array( $b['socials'] ) ) {
			$row['socials'] = wp_json_encode( array_filter( array_map( function ( $u ) {
				return esc_url_raw( (string) $u, array( 'http', 'https' ) );
			}, $b['socials'] ) ) );
		}
		$wpdb->update( NWC_DB::t( 'business' ), $row, array( 'workspace_id' => NWC_Tenancy::$current->id ) );
		NWC::audit( 'business_updated', array( 'entity' => 'business', 'entity_id' => NWC_Tenancy::$current->id ) );
		return self::get_business();
	}

	/** Logo, cover or signature. These appear on public pages, so they live in a public folder. */
	public static function upload_image( WP_REST_Request $r ) {
		if ( ! NWC_Tenancy::can( 'settings.manage' ) && ! NWC_Tenancy::can( 'bookings.manage' ) ) {
			return NWC::err( 'Your role does not allow this.', 403, 'nwc_forbidden' );
		}
		$f = $r->get_file_params();
		$f = isset( $f['file'] ) ? $f['file'] : null;
		if ( ! $f || UPLOAD_ERR_OK !== (int) $f['error'] || ! is_uploaded_file( $f['tmp_name'] ) ) {
			return NWC::err( 'No file received.' );
		}
		$v = NWC_Projects::validate_upload( $f['name'], $f['tmp_name'], (int) $f['size'] );
		if ( is_wp_error( $v ) ) {
			return $v;
		}
		if ( ! in_array( $v['ext'], array( 'jpg', 'jpeg', 'png', 'webp' ), true ) ) {
			return NWC::err( 'Use a JPG, PNG or WebP image.', 415, 'nwc_file_type' );
		}
		$up   = wp_upload_dir();
		$ws   = (int) NWC_Tenancy::$current->id;
		$dir  = trailingslashit( $up['basedir'] ) . 'nwc-public/' . $ws;
		wp_mkdir_p( $dir );
		$name = bin2hex( random_bytes( 12 ) ) . '.' . $v['ext'];
		if ( ! move_uploaded_file( $f['tmp_name'], $dir . '/' . $name ) ) {
			return NWC::err( 'Could not store the file.', 500, 'nwc_error' );
		}
		return array( 'url' => trailingslashit( $up['baseurl'] ) . 'nwc-public/' . $ws . '/' . $name );
	}

	public static function save_staff( WP_REST_Request $r ) {
		global $wpdb;
		$uid = (int) $r['id'];
		if ( $uid !== get_current_user_id() && ! NWC_Tenancy::can( 'team.manage' ) ) {
			return NWC::err( 'Your role does not allow this.', 403, 'nwc_forbidden' );
		}
		$m = NWC_Tenancy::member_row( NWC_Tenancy::$current->id, $uid );
		if ( ! $m ) {
			return NWC::err( 'Not found.', 404, 'nwc_not_found' );
		}
		$b = NWC_REST::body( $r );
		$wpdb->update( NWC_DB::t( 'members' ), array( 'hours' => wp_json_encode( self::clean_hours( $b['hours'] ?? array() ) ), 'breaks' => wp_json_encode( self::clean_hours( $b['breaks'] ?? array() ) ) ), array( 'id' => $m->id ) );
		return array( 'ok' => true );
	}

	/* ---------------------------- Availability ---------------------------- */

	private static function service( $workspace_id, $id ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . NWC_DB::t( 'services' ) . ' WHERE id = %d AND workspace_id = %d AND deleted_at IS NULL', $id, $workspace_id ) ); // phpcs:ignore
	}

	/**
	 * Open slots for one service on one local date, in the workspace's timezone.
	 *
	 * @return array [{start: UTC ISO, label: "10:30", staff_id, remaining}]
	 */
	public static function slots( $w, $service, $date, $staff_id = 0, $min_notice_hours = 0, $ignore_booking = 0, $space_filter = array(), $include_full = false ) {
		global $wpdb;
		$tz      = new DateTimeZone( $w->timezone );
		$closures = $wpdb->get_results( $wpdb->prepare( 'SELECT staff_id, space_id FROM ' . NWC_DB::t( 'closures' ) . ' WHERE workspace_id = %d AND on_date = %s', $w->id, $date ) ); // phpcs:ignore
		$off        = array();
		$space_off  = array();
		foreach ( $closures as $c ) {
			if ( ! $c->staff_id && ! $c->space_id ) {
				return array(); // Business holiday or blackout date.
			}
			if ( $c->staff_id ) {
				$off[] = (int) $c->staff_id;
			}
			if ( $c->space_id ) {
				$space_off[] = (int) $c->space_id;
			}
		}
		$rules = self::rules_for( (int) $w->id, (int) $service->id, $date );
		if ( ! $rules ) {
			return array();
		}
		$staff = array_map( 'intval', (array) json_decode( (string) $service->staff_ids, true ) );
		if ( $staff_id ) {
			if ( $staff && ! in_array( (int) $staff_id, $staff, true ) ) {
				return array();
			}
			$staff = array( (int) $staff_id );
		}
		if ( ! $staff ) {
			$staff = array( 0 ); // No staff configured: the business itself is the resource.
		}
		$spaces = array();
		foreach ( array_map( 'intval', (array) json_decode( (string) ( $service->space_ids ?? '' ), true ) ) as $sp ) {
			$row = self::space( $w->id, $sp );
			if ( $row && (int) $row->is_active && ! in_array( $sp, $space_off, true ) && ( ! $space_filter || in_array( $sp, $space_filter, true ) ) ) {
				$spaces[] = $sp;
			}
		}
		if ( ! $spaces ) {
			if ( json_decode( (string) ( $service->space_ids ?? '' ), true ) ) {
				return array(); // Every eligible space is off or inactive that day.
			}
			$spaces = array( 0 );
		}
		$dur      = (int) $service->duration_min * 60;
		$pre      = (int) ( $service->setup_min ?? 0 ) * 60;
		$post     = (int) ( $service->cleanup_min ?? 0 ) * 60;
		$earliest = time() + $min_notice_hours * HOUR_IN_SECONDS;
		$day_start = ( new DateTimeImmutable( $date . ' 00:00:00', $tz ) )->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );
		$day_end   = ( new DateTimeImmutable( $date . ' 23:59:59', $tz ) )->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );
		$existing = self::busy( $w, $day_start, $day_end, $date, $tz );
		$out  = array();
		$seen = array();
		foreach ( $rules as $rule ) {
			$step = max( 5, $rule->slot_min ? (int) $rule->slot_min : (int) $service->duration_min );
			$t    = ( new DateTimeImmutable( $date . ' ' . $rule->start_time, $tz ) )->getTimestamp();
			$end  = ( new DateTimeImmutable( $date . ' ' . $rule->end_time, $tz ) )->getTimestamp();
			$r_staff  = $rule->staff_id ? array_values( array_intersect( $staff, array( (int) $rule->staff_id ) ) ) : $staff;
			$r_spaces = $rule->space_id ? array_values( array_intersect( $spaces, array( (int) $rule->space_id ) ) ) : $spaces;
			if ( $rule->staff_id && array( 0 ) === $staff ) {
				$r_staff = array( (int) $rule->staff_id );
			}
			for ( ; $t + $dur <= $end; $t += $step * 60 ) {
				if ( $t < $earliest || isset( $seen[ $t ] ) ) {
					continue;
				}
				foreach ( $r_staff as $sid ) {
					foreach ( $r_spaces as $sp ) {
						$left = self::slot_left( $w, $service, $sid, $t, $dur, $pre, $post, $date, $tz, $existing, $off, $ignore_booking, $sp );
						if ( $left > 0 || ( $include_full && 0 === $left && (int) $service->capacity > 1 ) ) {
							$seen[ $t ] = true;
							$out[] = array( 'start' => gmdate( 'Y-m-d\TH:i:s\Z', $t ), 'label' => ( new DateTimeImmutable( '@' . $t ) )->setTimezone( $tz )->format( 'H:i' ), 'staff_id' => $sid ? $sid : null, 'space_id' => $sp ? $sp : null, 'remaining' => max( 0, $left ), 'full' => $left <= 0 );
							break 2;
						}
					}
				}
			}
		}
		usort( $out, function ( $a, $b ) {
			return strcmp( $a['start'], $b['start'] );
		} );
		return $out;
	}

	/**
	 * Everything that occupies a room or a person on this local date: live bookings (with their
	 * setup and clean-up blocks) and the academy's recurring batches meeting that weekday.
	 */
	private static function busy( $w, $day_start, $day_end, $date, $tz ) {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT id, service_id, staff_id, space_id, starts_at, ends_at, block_start, block_end, party_size FROM ' . NWC_DB::t( 'bookings' ) . ' WHERE workspace_id = %d AND deleted_at IS NULL AND ' . self::live_sql() . ' AND COALESCE(block_start, starts_at) <= %s AND COALESCE(block_end, ends_at) >= %s', $w->id, NWC::now(), $day_end, $day_start ) ); // phpcs:ignore
		$day  = self::DAYS[ (int) ( new DateTimeImmutable( $date . ' 12:00:00', new DateTimeZone( 'UTC' ) ) )->format( 'N' ) - 1 ];
		$subs = array();
		foreach ( $wpdb->get_results( $wpdb->prepare( 'SELECT parent_id, staff_id FROM ' . NWC_DB::t( 'biz_records' ) . " WHERE workspace_id = %d AND entity = 'substitution' AND on_date = %s AND deleted_at IS NULL", $w->id, $date ) ) as $s ) { // phpcs:ignore
			$subs[ (int) $s->parent_id ] = (int) $s->staff_id;
		}
		foreach ( $wpdb->get_results( $wpdb->prepare( 'SELECT id, title, staff_id, space_id, on_date, end_date, data FROM ' . NWC_DB::t( 'biz_records' ) . " WHERE workspace_id = %d AND entity = 'batch' AND status = 'active' AND deleted_at IS NULL", $w->id ) ) as $b ) { // phpcs:ignore
			$d = json_decode( (string) $b->data, true );
			if ( ! in_array( $day, (array) ( $d['weekdays'] ?? array() ), true ) || empty( $d['start_time'] ) || empty( $d['end_time'] ) || ( $b->on_date && $b->on_date > $date ) || ( $b->end_date && $b->end_date < $date ) ) {
				continue;
			}
			$s      = ( new DateTimeImmutable( $date . ' ' . $d['start_time'], $tz ) )->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );
			$e      = ( new DateTimeImmutable( $date . ' ' . $d['end_time'], $tz ) )->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );
			$rows[] = (object) array( 'id' => 0, 'batch_id' => (int) $b->id, 'title' => $b->title, 'service_id' => 0, 'staff_id' => $subs[ (int) $b->id ] ?? $b->staff_id, 'space_id' => $b->space_id, 'starts_at' => $s, 'ends_at' => $e, 'block_start' => $s, 'block_end' => $e, 'party_size' => 0 );
		}
		return $rows;
	}

	/**
	 * What this request would clash with: bookings or batches in the same space or with the same
	 * instructor whose blocks (start minus setup, end plus clean-up) overlap. Same class at the same
	 * time and in the same space shares seats instead of clashing.
	 *
	 * @return array [{kind, id, title, starts_at, ends_at, why}]
	 */
	public static function conflicts( $w, $service, $start_ts, $staff_id, $space_id, $ignore = 0 ) {
		$tz   = new DateTimeZone( $w->timezone );
		$dur  = (int) $service->duration_min * 60;
		$pre  = (int) ( $service->setup_min ?? 0 ) * 60;
		$post = (int) ( $service->cleanup_min ?? 0 ) * 60;
		$date = ( new DateTimeImmutable( '@' . $start_ts ) )->setTimezone( $tz )->format( 'Y-m-d' );
		$out  = array();
		foreach ( self::busy( $w, gmdate( 'Y-m-d H:i:s', $start_ts - $pre - DAY_IN_SECONDS ), gmdate( 'Y-m-d H:i:s', $start_ts + $dur + $post + DAY_IN_SECONDS ), $date, $tz ) as $b ) {
			if ( (int) $b->id && (int) $b->id === (int) $ignore ) {
				continue;
			}
			$bs = strtotime( ( $b->block_start ? $b->block_start : $b->starts_at ) . ' UTC' );
			$be = strtotime( ( $b->block_end ? $b->block_end : $b->ends_at ) . ' UTC' );
			if ( $start_ts - $pre >= $be || $start_ts + $dur + $post <= $bs ) {
				continue;
			}
			if ( (int) $service->capacity > 1 && (int) $b->service_id === (int) $service->id && strtotime( $b->starts_at . ' UTC' ) === $start_ts && (int) $b->space_id === (int) $space_id ) {
				continue;
			}
			$why = '';
			if ( $space_id && (int) $b->space_id === (int) $space_id ) {
				$why = 'space';
			} elseif ( $staff_id && (int) $b->staff_id === (int) $staff_id ) {
				$why = 'instructor';
			} elseif ( ! $staff_id && ! $space_id && ! $b->staff_id && ! $b->space_id && (int) $b->id ) {
				$why = 'business';
			}
			if ( $why ) {
				$out[] = array( 'kind' => (int) $b->id ? 'booking' : 'batch', 'id' => (int) $b->id ? (int) $b->id : (int) $b->batch_id, 'title' => $b->title ?? '', 'starts_at' => $b->starts_at, 'ends_at' => $b->ends_at, 'why' => $why );
			}
		}
		return $out;
	}

	/** Seats left at this time for this staff member and space, after hours, breaks, bookings and batches. */
	private static function slot_left( $w, $service, $sid, $t, $dur, $pre, $post, $date, $tz, $existing, $off, $ignore, $space = 0 ) {
		if ( $sid ) {
			if ( in_array( (int) $sid, array_map( 'intval', $off ), true ) ) {
				return -1; // Blocked.
			}
			$m = NWC_Tenancy::member_row( $w->id, $sid );
			if ( ! $m ) {
				return -1; // Blocked.
			}
			$day = self::DAYS[ (int) ( new DateTimeImmutable( $date, $tz ) )->format( 'N' ) - 1 ];
			$h   = json_decode( (string) $m->hours, true );
			if ( is_array( $h ) && $h ) { // Personal hours narrow the business hours when set.
				$ok = false;
				foreach ( (array) ( $h[ $day ] ?? array() ) as $r ) {
					if ( $t >= ( new DateTimeImmutable( $date . ' ' . $r[0], $tz ) )->getTimestamp() && $t + $dur <= ( new DateTimeImmutable( $date . ' ' . $r[1], $tz ) )->getTimestamp() ) {
						$ok = true;
					}
				}
				if ( ! $ok ) {
					return -1; // Blocked.
				}
			}
			foreach ( (array) ( json_decode( (string) $m->breaks, true )[ $day ] ?? array() ) as $r ) {
				$bs = ( new DateTimeImmutable( $date . ' ' . $r[0], $tz ) )->getTimestamp();
				$be = ( new DateTimeImmutable( $date . ' ' . $r[1], $tz ) )->getTimestamp();
				if ( $t < $be && $t + $dur > $bs ) {
					return -1; // Blocked.
				}
			}
		}
		$cap   = max( 1, (int) $service->capacity );
		if ( $space ) {
			$sp = self::space( $w->id, $space );
			if ( $sp && (int) $sp->capacity > 0 && $cap > 1 ) {
				$cap = min( $cap, (int) $sp->capacity ); // A class cannot seat more than the room holds.
			}
		}
		$taken = 0;
		foreach ( $existing as $b ) {
			if ( (int) $b->id && (int) $b->id === (int) $ignore ) {
				continue;
			}
			$bs = strtotime( ( $b->block_start ? $b->block_start : $b->starts_at ) . ' UTC' );
			$be = strtotime( ( $b->block_end ? $b->block_end : $b->ends_at ) . ' UTC' );
			if ( $t - $pre >= $be || $t + $dur + $post <= $bs ) {
				continue;
			}
			if ( $cap > 1 && (int) $b->service_id === (int) $service->id && strtotime( $b->starts_at . ' UTC' ) === $t && (int) $b->space_id === (int) $space ) {
				$taken += (int) $b->party_size; // Same class at the same time in the same room: shares seats.
				continue;
			}
			if ( $space && (int) $b->space_id === (int) $space ) {
				return -1; // Blocked. // Room in use.
			}
			if ( $sid && (int) $b->staff_id === (int) $sid ) {
				return -1; // Blocked. // Instructor busy.
			}
			if ( ! $sid && ! $space && ! $b->staff_id && ! $b->space_id && (int) $b->id ) {
				return -1; // Blocked. // The business itself is the only resource.
			}
		}
		return max( 0, $cap - $taken );
	}

	/* ---------------- Phase 5 capacity policy: seats, holds and payments ----------------
	 *
	 * A seat is taken by a CONFIRMED booking (paid, or pay-at-venue) or by a PENDING booking whose
	 * hold has not expired. A pending booking always has a hold: online pre-payment holds for the
	 * business's booking_hold_minutes (default 15) and staff-marked pending bookings for
	 * manual_hold_hours (default 24). Expiry is time-based, so an expired hold frees its seat at
	 * once, even before the clean-up job marks it "expired". Releasing (expiry, failed payment,
	 * refund, cancellation) is one conditional update, so a seat is given back exactly once however
	 * often the event repeats. Every seat check runs inside the per-Workspace booking lock.
	 */

	/** SQL for a booking that occupies its seat; the caller binds the current UTC time. */
	public static function live_sql( $p = '' ) {
		return "({$p}status = 'confirmed' OR ({$p}status = 'pending' AND ({$p}hold_expires_at IS NULL OR {$p}hold_expires_at > %s)))";
	}

	public static function is_live( $b ) {
		return 'confirmed' === $b->status || ( 'pending' === $b->status && ( empty( $b->hold_expires_at ) || $b->hold_expires_at > NWC::now() ) );
	}

	private static function hold_until( $workspace_id, $manual ) {
		$biz = self::business( $workspace_id );
		if ( $manual ) {
			return gmdate( 'Y-m-d H:i:s', time() + max( 1, (int) ( $biz->manual_hold_hours ?? 24 ) ) * HOUR_IN_SECONDS );
		}
		return gmdate( 'Y-m-d H:i:s', time() + max( 5, (int) ( $biz->booking_hold_minutes ?? 15 ) ) * MINUTE_IN_SECONDS );
	}

	/** Online pre-payment applies only on public pages, for a priced service, with the business's gateway on. */
	private static function needs_prepay( $w, $service, $source ) {
		return 'booking_page' === $source && 'online' === ( $service->pay_mode ?? 'venue' ) && (float) $service->price > 0 && NWC_Finance::biz_gateway( (int) $w->id );
	}

	/**
	 * Frees a live booking's seat once. @return bool True only for the call that released it.
	 */
	public static function release( $b, $reason, $to = 'cancelled' ) {
		global $wpdb;
		$t   = NWC_DB::t( 'bookings' );
		$won = $wpdb->query( $wpdb->prepare( "UPDATE $t SET status = %s, release_reason = %s, hold_expires_at = NULL, updated_at = %s WHERE id = %d AND status IN ('pending','confirmed')", $to, mb_substr( sanitize_key( $reason ), 0, 40 ), NWC::now(), $b->id ) ); // phpcs:ignore
		if ( ! $won ) {
			return false;
		}
		// An unpaid invoice made for an online pre-payment is cancelled with the hold.
		if ( in_array( $reason, array( 'hold_expired', 'payment_failed', 'refunded' ), true ) ) {
			$inv = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . NWC_DB::t( 'biz_invoices' ) . ' WHERE workspace_id = %d AND booking_id = %d', $b->workspace_id, $b->id ) ); // phpcs:ignore
			if ( $inv && in_array( $inv->status, array( 'draft', 'sent' ), true ) && (float) $inv->amount_paid <= 0 ) {
				$wpdb->update( NWC_DB::t( 'biz_invoices' ), array( 'status' => 'cancelled', 'online_pay' => 0, 'cancelled_at' => NWC::now(), 'cancel_reason' => 'hold_expired' === $reason ? 'Booking hold expired before payment' : ( 'refunded' === $reason ? 'Booking refunded' : 'Payment failed' ), 'updated_at' => NWC::now() ), array( 'id' => $inv->id ) );
			}
		}
		NWC::audit( 'booking_released', array( 'workspace_id' => (int) $b->workspace_id, 'user_id' => get_current_user_id(), 'entity' => 'booking', 'entity_id' => (int) $b->id, 'details' => array( 'reason' => $reason, 'from' => $b->status, 'to' => $to, 'seats' => (int) $b->party_size ) ) );
		if ( self::is_live( $b ) || 'hold_expired' === $reason ) { // A lapsed hold's seat goes to the waitlist too.
			self::promote_waitlist( $b );
		}
		NWC_Sync::enqueue( (int) $b->workspace_id, 'booking', (int) $b->id );
		return true;
	}

	/** Cron: marks holds past their time as expired (their seats were already free). */
	public static function expire_holds( $limit = 200 ) {
		global $wpdb;
		$n = 0;
		foreach ( $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . NWC_DB::t( 'bookings' ) . " WHERE status = 'pending' AND hold_expires_at IS NOT NULL AND hold_expires_at <= %s AND deleted_at IS NULL LIMIT %d", NWC::now(), $limit ) ) as $b ) { // phpcs:ignore
			if ( ! NWC::lock( 'book:' . $b->workspace_id ) ) {
				continue; // Busy: the next run picks it up; its seat is already free by time.
			}
			try {
				$done = self::release( $b, 'hold_expired', 'expired' );
			} finally {
				NWC::unlock( 'book:' . $b->workspace_id );
			}
			if ( $done ) {
				$n++;
				NWC_Notify::workspace_owners( (int) $b->workspace_id, 'booking', 'Booking hold expired', $b->customer_name . ': not paid in time, the seat was released.', '#/bookings/list?open=' . (int) $b->id, 'holdexp:' . $b->id );
			}
		}
		return $n;
	}

	/**
	 * One entry point for payment outcomes, whatever reports them (gateway, invoice payment, staff).
	 * paid: confirms a held booking; after its hold ran out it is confirmed only if the seats are
	 * still free, otherwise it stays released and the owner is told to refund. failed: releases a
	 * held booking. refunded: releases a live booking. Repeating any event changes nothing.
	 *
	 * @return array {status, payment_status, changed}
	 */
	public static function payment_event( $booking_id, $workspace_id, $event, $ref = '' ) {
		global $wpdb;
		$t = NWC_DB::t( 'bookings' );
		if ( ! NWC::lock( 'book:' . $workspace_id ) ) {
			return NWC::err( 'Busy right now. Please try again.', 503, 'nwc_busy' );
		}
		try {
			$b = self::row( $workspace_id, $booking_id );
			if ( ! $b ) {
				return NWC::err( 'Booking not found.', 404, 'nwc_not_found' );
			}
			$changed = false;
			$now     = NWC::now();
			if ( 'paid' === $event ) {
				if ( 'paid' !== $b->payment_status ) {
					$wpdb->update( $t, array( 'payment_status' => 'paid', 'paid_at' => $now, 'updated_at' => $now ), array( 'id' => $b->id ) );
					$changed = true;
				}
				if ( 'pending' === $b->status && self::is_live( $b ) ) {
					$wpdb->update( $t, array( 'status' => 'confirmed', 'hold_expires_at' => null, 'updated_at' => $now ), array( 'id' => $b->id ) );
					$changed = true;
				} elseif ( 'pending' === $b->status || ( in_array( $b->status, array( 'expired', 'cancelled' ), true ) && in_array( $b->release_reason, array( 'hold_expired', 'payment_failed' ), true ) ) ) {
					// Paid after the hold ran out: confirm only if the seats are still free.
					$w    = NWC_Tenancy::workspace( $workspace_id );
					$s    = self::service( $workspace_id, $b->service_id );
					$st   = strtotime( $b->starts_at . ' UTC' );
					$free = false;
					if ( $w && $s && $st > time() ) {
						$local = ( new DateTimeImmutable( '@' . $st ) )->setTimezone( new DateTimeZone( $w->timezone ) )->format( 'Y-m-d' );
						foreach ( self::slots( $w, $s, $local, (int) $b->staff_id, 0, (int) $b->id, $b->space_id ? array( (int) $b->space_id ) : array() ) as $slot ) {
							$free = $free || ( strtotime( $slot['start'] ) === $st && $slot['remaining'] >= (int) $b->party_size );
						}
					}
					if ( $free ) {
						$wpdb->update( $t, array( 'status' => 'confirmed', 'hold_expires_at' => null, 'release_reason' => '', 'updated_at' => $now ), array( 'id' => $b->id ) );
						NWC::audit( 'booking_confirmed_after_hold', array( 'workspace_id' => $workspace_id, 'user_id' => 0, 'entity' => 'booking', 'entity_id' => $b->id ) );
					} else {
						if ( 'pending' === $b->status ) {
							$wpdb->update( $t, array( 'status' => 'expired', 'release_reason' => 'hold_expired', 'hold_expires_at' => null, 'updated_at' => $now ), array( 'id' => $b->id ) );
						}
						NWC::audit( 'booking_paid_after_release', array( 'workspace_id' => $workspace_id, 'user_id' => 0, 'kind' => 'billing', 'entity' => 'booking', 'entity_id' => $b->id, 'details' => array( 'ref' => $ref ) ) );
						NWC_Notify::workspace_owners( (int) $workspace_id, 'booking', 'Refund needed: paid after the seat was released', $b->customer_name . ' paid after their hold expired and the time is no longer free.', '#/bookings/list?open=' . (int) $b->id, 'paidlate:' . $b->id );
					}
					$changed = true;
				}
			} elseif ( 'failed' === $event ) {
				if ( 'pending' === $b->status ) {
					$wpdb->update( $t, array( 'payment_status' => 'failed', 'updated_at' => $now ), array( 'id' => $b->id ) );
					$changed = self::release( $b, 'payment_failed', 'cancelled' );
				}
			} elseif ( 'refunded' === $event ) {
				if ( 'refunded' !== $b->payment_status ) {
					$wpdb->update( $t, array( 'payment_status' => 'refunded', 'updated_at' => $now ), array( 'id' => $b->id ) );
					$changed = true;
				}
				$changed = self::release( $b, 'refunded', 'cancelled' ) || $changed;
			} else {
				return NWC::err( 'Unknown payment event.' );
			}
			if ( $changed ) {
				NWC::audit( 'booking_payment_' . $event, array( 'workspace_id' => $workspace_id, 'user_id' => get_current_user_id(), 'entity' => 'booking', 'entity_id' => $b->id, 'details' => array( 'ref' => $ref ) ) );
				NWC_Sync::enqueue( (int) $workspace_id, 'booking', (int) $b->id );
			}
			$fresh = self::row( $workspace_id, $booking_id );
		} finally {
			NWC::unlock( 'book:' . $workspace_id );
		}
		if ( 'paid' === $event && $changed && 'confirmed' === $fresh->status ) {
			NWC_Finance::maybe_auto_invoice( $fresh, 'paid' );
		}
		return array( 'status' => $fresh->status, 'payment_status' => $fresh->payment_status, 'changed' => $changed );
	}

	/** A checkout started inside the hold keeps the seat for the gateway's time, once, at most 10 minutes more. */
	public static function extend_hold_for_checkout( $workspace_id, $booking_id ) {
		global $wpdb;
		$b = self::row( $workspace_id, $booking_id );
		if ( ! $b || 'pending' !== $b->status || ! self::is_live( $b ) ) {
			return false;
		}
		$cap  = gmdate( 'Y-m-d H:i:s', strtotime( $b->created_at . ' UTC' ) + max( 5, (int) ( self::business( $workspace_id )->booking_hold_minutes ?? 15 ) ) * MINUTE_IN_SECONDS + 10 * MINUTE_IN_SECONDS );
		$want = min( $cap, gmdate( 'Y-m-d H:i:s', time() + 10 * MINUTE_IN_SECONDS ) );
		return $want > $b->hold_expires_at ? (bool) $wpdb->update( NWC_DB::t( 'bookings' ), array( 'hold_expires_at' => $want ), array( 'id' => $b->id ) ) : false;
	}

	/** Creates a booking under a lock, so two people cannot take the last seat. */
	private static function book( $w, $service, $in, $source, $page = null ) {
		global $wpdb;
		$tz    = new DateTimeZone( $w->timezone );
		$start = NWC::to_utc( (string) ( $in['start'] ?? '' ), 'UTC' );
		if ( ! $start ) {
			return NWC::err( 'Choose a time.' );
		}
		$st    = strtotime( $start . ' UTC' );
		$local = ( new DateTimeImmutable( '@' . $st ) )->setTimezone( $tz )->format( 'Y-m-d' );
		$party = max( 1, (int) ( $in['party_size'] ?? 1 ) );
		$name  = mb_substr( trim( sanitize_text_field( (string) ( $in['name'] ?? '' ) ) ), 0, 190 );
		$phone = mb_substr( sanitize_text_field( (string) ( $in['phone'] ?? '' ) ), 0, 40 );
		$email = sanitize_email( (string) ( $in['email'] ?? '' ) );
		if ( '' === $name || ( '' === NWC::norm_phone( $phone ) && '' === $email ) ) {
			return NWC::err( 'Enter your name and a phone number or email.' );
		}
		$idem = isset( $in['idempotency_key'] ) ? sanitize_text_field( (string) $in['idempotency_key'] ) : '';
		if ( '' !== $idem ) {
			$dup = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . NWC_DB::t( 'bookings' ) . ' WHERE workspace_id = %d AND idempotency_key = %s', $w->id, $idem ) ); // phpcs:ignore
			if ( $dup ) {
				return array( 'booking' => $dup, 'token' => null, 'created' => false );
			}
		}
		$want_space = absint( $in['space_id'] ?? 0 );
		$want_staff = absint( $in['staff_id'] ?? 0 );
		$page_spaces = $page ? array_map( 'intval', (array) json_decode( (string) ( $page->space_ids ?? '' ), true ) ) : array();
		if ( $want_space && $page_spaces && ! in_array( $want_space, $page_spaces, true ) ) {
			return NWC::err( 'That space is not offered on this page.' );
		}
		$override = ! empty( $in['override'] ) && 'manual' === $source;
		if ( $override && ( ! NWC_Tenancy::is_manager() || '' === trim( (string) ( $in['override_reason'] ?? '' ) ) ) ) {
			return NWC::err( 'Only a manager can override a clash, and a reason is required.', 403, 'nwc_forbidden' );
		}
		// The final check runs inside the lock, so two people cannot take the same room or seat.
		if ( ! NWC::lock( 'book:' . $w->id ) ) {
			return NWC::err( 'Busy right now. Please try again.', 503, 'nwc_busy' );
		}
		$warnings = array();
		try {
			$filter = $want_space ? array( $want_space ) : $page_spaces;
			$open   = self::slots( $w, $service, $local, $want_staff, $page ? (int) $page->min_notice_hours : 0, 0, $filter );
			$slot   = null;
			foreach ( $open as $s ) {
				if ( strtotime( $s['start'] ) === $st ) {
					$slot = $s;
					break;
				}
			}
			$status = 'confirmed';
			if ( ! $slot || $slot['remaining'] < $party ) {
				if ( $override ) {
					$warnings = self::conflicts( $w, $service, $st, $want_staff, $want_space, 0 );
					$slot     = array( 'staff_id' => $want_staff ? $want_staff : null, 'space_id' => $want_space ? $want_space : null );
				} elseif ( (int) ( $service->waitlist ?? 0 ) && ! empty( $in['waitlist'] ) && array_filter( self::slots( $w, $service, $local, $want_staff, 0, 0, $filter, true ), function ( $x ) use ( $st ) {
					return strtotime( $x['start'] ) === $st;
				} ) ) { // Only a class time that exists and is full takes a waitlist.
					$status = 'waitlisted';
					$slot   = array( 'staff_id' => $want_staff ? $want_staff : null, 'space_id' => $want_space ? $want_space : ( $filter ? $filter[0] : null ) );
				} else {
					$c = self::conflicts( $w, $service, $st, $want_staff, $want_space, 0 );
					return NWC::err( 'That time was just taken. Please choose another.', 409, 'nwc_slot_taken', array( 'conflicts' => $c, 'waitlist' => (bool) (int) ( $service->waitlist ?? 0 ) ) );
				}
			}
			$hold = null;
			if ( 'confirmed' === $status && self::needs_prepay( $w, $service, $source ) ) {
				$status = 'pending'; // The seat is held while the customer pays; payment confirms it.
				$hold   = self::hold_until( (int) $w->id, false );
			}
			$credit = null;
			if ( ! empty( $in['credit_id'] ) ) {
				$credit = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . NWC_DB::t( 'booking_credits' ) . " WHERE id = %d AND workspace_id = %d AND status = 'open'", absint( $in['credit_id'] ), $w->id ) ); // phpcs:ignore
				if ( ! $credit || ( $credit->expires_on && $credit->expires_on < NWC::today( $w->timezone ) ) || ( $credit->service_id && (int) $credit->service_id !== (int) $service->id ) ) {
					return NWC::err( 'That makeup credit cannot be used for this booking.', 409, 'nwc_conflict' );
				}
			}
			$consent = ! empty( $in['marketing_consent'] );
			$cid     = NWC_CRM::upsert_customer( (int) $w->id, array( 'name' => $name, 'phone' => $phone, 'email' => $email, 'source' => $source, 'marketing_consent' => $consent ) );
			if ( $credit && $credit->customer_id && (int) $credit->customer_id !== (int) $cid ) {
				return NWC::err( 'That makeup credit belongs to another customer.', 409, 'nwc_conflict' );
			}
			$token   = rtrim( strtr( base64_encode( random_bytes( 24 ) ), '+/', '-_' ), '=' ); // phpcs:ignore
			$now     = NWC::now();
			$end_ts  = $st + (int) $service->duration_min * 60;
			$custom  = isset( $in['custom'] ) && is_array( $in['custom'] ) ? $in['custom'] : array();
			$ok      = $wpdb->insert( NWC_DB::t( 'bookings' ), array(
				'workspace_id' => (int) $w->id, 'page_id' => $page ? (int) $page->id : null, 'service_id' => (int) $service->id, 'staff_id' => $slot['staff_id'], 'space_id' => $slot['space_id'] ?? null, 'customer_id' => $cid,
				'starts_at' => $start, 'ends_at' => gmdate( 'Y-m-d H:i:s', $end_ts ), 'block_start' => gmdate( 'Y-m-d H:i:s', $st - (int) ( $service->setup_min ?? 0 ) * 60 ), 'block_end' => gmdate( 'Y-m-d H:i:s', $end_ts + (int) ( $service->cleanup_min ?? 0 ) * 60 ),
				'party_size' => $party, 'status' => $status, 'waitlisted_at' => 'waitlisted' === $status ? $now : null, 'hold_expires_at' => $hold,
				'customer_name' => $name, 'phone' => $phone, 'email' => $email, 'notes' => sanitize_textarea_field( (string) ( $in['notes'] ?? '' ) ), 'source' => $source,
				'override_reason' => $override ? sanitize_textarea_field( (string) $in['override_reason'] ) : null, 'override_by' => $override ? get_current_user_id() : null,
				'series_key' => isset( $in['series_key'] ) ? sanitize_key( (string) $in['series_key'] ) : null, 'credit_id' => $credit ? (int) $credit->id : null, 'custom' => $custom ? wp_json_encode( $custom ) : null,
				'manage_token_hash' => hash( 'sha256', $token ), 'idempotency_key' => '' !== $idem ? $idem : null, 'marketing_consent' => $consent ? 1 : 0, 'created_by' => get_current_user_id(), 'created_at' => $now, 'updated_at' => $now,
			) );
			if ( ! $ok ) {
				return NWC::err( 'Could not save the booking.', 500, 'nwc_error' );
			}
			$id = (int) $wpdb->insert_id;
			if ( $credit ) {
				$wpdb->update( NWC_DB::t( 'booking_credits' ), array( 'status' => 'used', 'used_booking_id' => $id, 'updated_at' => $now ), array( 'id' => $credit->id ) );
			}
		} finally {
			NWC::unlock( 'book:' . $w->id );
		}
		if ( $override ) {
			NWC::audit( 'booking_override', array( 'workspace_id' => $w->id, 'kind' => 'admin', 'entity' => 'booking', 'entity_id' => $id, 'details' => array( 'reason' => (string) $in['override_reason'], 'conflicts' => $warnings ) ) );
		}
		$lead = NWC_CRM::upsert_lead( (int) $w->id, array( 'name' => $name, 'phone' => $phone, 'email' => $email, 'customer_id' => $cid, 'enquiry' => $service->name, 'lead_type' => 'booking', 'source' => $source, 'source_ref' => 'booking:' . $id, 'marketing_consent' => $consent ) );
		$wpdb->update( NWC_DB::t( 'bookings' ), array( 'lead_id' => $lead ), array( 'id' => $id ) );
		NWC_Sync::enqueue( (int) $w->id, 'booking', $id );
		return array( 'booking' => self::row( $w->id, $id ), 'token' => $token, 'created' => true, 'warnings' => $warnings );
	}

	private static function row( $workspace_id, $id ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . NWC_DB::t( 'bookings' ) . ' WHERE id = %d AND workspace_id = %d AND deleted_at IS NULL', $id, $workspace_id ) ); // phpcs:ignore
	}

	private static function out( $b ) {
		$s = self::service( $b->workspace_id, $b->service_id );
		unset( $b->manage_token_hash, $b->idempotency_key, $b->deleted_at );
		$b->service_name = $s ? $s->name : '';
		$sp              = $b->space_id ? self::space( $b->workspace_id, $b->space_id ) : null;
		$b->space_name   = $sp ? $sp->name : '';
		$b->custom       = $b->custom ? json_decode( (string) $b->custom, true ) : null;
		return $b;
	}

	/* ---------------------------- Internal ---------------------------- */

	private static function guard( $perm ) {
		if ( ! NWC_Tenancy::can( $perm ) ) {
			return NWC::err( 'Your role does not allow this.', 403, 'nwc_forbidden' );
		}
		return NWC_Features::guard( 'bookings' );
	}

	public static function index( WP_REST_Request $r ) {
		global $wpdb;
		$g = self::guard( 'bookings.read' );
		if ( is_wp_error( $g ) ) {
			return $g;
		}
		$vals = array( (int) NWC_Tenancy::$current->id );
		$sql  = 'workspace_id = %d AND deleted_at IS NULL';
		foreach ( array( 'status', 'service_id', 'staff_id', 'page_id', 'customer_id' ) as $f ) {
			if ( $r->get_param( $f ) ) {
				$sql   .= " AND $f = %s";
				$vals[] = (string) $r->get_param( $f );
			}
		}
		if ( NWC::valid_date( (string) $r->get_param( 'from' ) ) ) {
			$sql   .= ' AND starts_at >= %s';
			$vals[] = $r->get_param( 'from' ) . ' 00:00:00';
		}
		if ( NWC::valid_date( (string) $r->get_param( 'to' ) ) ) {
			$sql   .= ' AND starts_at <= %s';
			$vals[] = $r->get_param( 'to' ) . ' 23:59:59';
		}
		$q = trim( sanitize_text_field( (string) $r->get_param( 'q' ) ) );
		if ( '' !== $q ) {
			$like   = '%' . $wpdb->esc_like( $q ) . '%';
			$sql   .= ' AND (customer_name LIKE %s OR phone LIKE %s OR email LIKE %s)';
			$vals   = array_merge( $vals, array( $like, $like, $like ) );
		}
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . NWC_DB::t( 'bookings' ) . " WHERE $sql ORDER BY starts_at DESC LIMIT 300", $vals ) ); // phpcs:ignore
		return array( 'items' => array_map( array( __CLASS__, 'out' ), $rows ) );
	}

	public static function show( WP_REST_Request $r ) {
		$g = self::guard( 'bookings.read' );
		if ( is_wp_error( $g ) ) {
			return $g;
		}
		$b = self::row( NWC_Tenancy::$current->id, (int) $r['id'] );
		return $b ? self::out( $b ) : NWC::err( 'Booking not found.', 404, 'nwc_not_found' );
	}

	public static function slots_internal( WP_REST_Request $r ) {
		$g = self::guard( 'bookings.read' );
		if ( is_wp_error( $g ) ) {
			return $g;
		}
		$s = self::service( NWC_Tenancy::$current->id, absint( $r->get_param( 'service_id' ) ) );
		$d = (string) $r->get_param( 'date' );
		if ( ! $s || ! NWC::valid_date( $d ) ) {
			return NWC::err( 'Choose a service and date.' );
		}
		return array( 'slots' => self::slots( NWC_Tenancy::$current, $s, $d, absint( $r->get_param( 'staff_id' ) ) ) );
	}

	public static function create_internal( WP_REST_Request $r ) {
		$g = self::guard( 'bookings.write' );
		if ( is_wp_error( $g ) ) {
			return $g;
		}
		$b = NWC_REST::body( $r );
		$s = self::service( NWC_Tenancy::$current->id, absint( $b['service_id'] ?? 0 ) );
		if ( ! $s ) {
			return NWC::err( 'Service not found.', 404, 'nwc_not_found' );
		}
		$repeat = max( 1, min( 52, (int) ( $b['repeat_weeks'] ?? 1 ) ) );
		if ( $repeat > 1 ) { // A weekly series: each date is checked on its own; clashing dates are skipped and listed.
			$key     = 'ser' . bin2hex( random_bytes( 6 ) );
			$start   = strtotime( (string) NWC::to_utc( (string) ( $b['start'] ?? '' ), 'UTC' ) . ' UTC' );
			$made    = array();
			$skipped = array();
			for ( $i = 0; $i < $repeat; $i++ ) {
				$one = array_merge( $b, array( 'start' => gmdate( 'Y-m-d\TH:i:s\Z', $start + $i * 7 * DAY_IN_SECONDS ), 'series_key' => $key, 'idempotency_key' => isset( $b['idempotency_key'] ) ? $b['idempotency_key'] . '-' . $i : null ) );
				$res = self::book( NWC_Tenancy::$current, $s, $one, 'manual' );
				if ( is_wp_error( $res ) ) {
					$skipped[] = array( 'start' => $one['start'], 'reason' => $res->get_error_message() );
				} else {
					$made[] = self::out( $res['booking'] );
				}
			}
			if ( ! $made ) {
				return NWC::err( 'None of the dates in this series are free.', 409, 'nwc_slot_taken', array( 'skipped' => $skipped ) );
			}
			return array( 'series_key' => $key, 'created' => $made, 'skipped' => $skipped );
		}
		$res = self::book( NWC_Tenancy::$current, $s, $b, 'manual' );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		$out = self::out( $res['booking'] );
		$out->warnings = $res['warnings'];
		return $out;
	}

	/** The first waitlisted booking for this class time takes a freed seat, and its customer is told. */
	private static function promote_waitlist( $freed ) {
		global $wpdb;
		$w = NWC_Tenancy::workspace( $freed->workspace_id );
		$s = self::service( $w->id, $freed->service_id );
		if ( ! $s ) {
			return;
		}
		foreach ( $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . NWC_DB::t( 'bookings' ) . " WHERE workspace_id = %d AND service_id = %d AND starts_at = %s AND status = 'waitlisted' AND deleted_at IS NULL ORDER BY waitlisted_at ASC, id ASC LIMIT 5", $w->id, $s->id, $freed->starts_at ) ) as $wb ) { // phpcs:ignore
			$local = ( new DateTimeImmutable( $wb->starts_at . ' UTC' ) )->setTimezone( new DateTimeZone( $w->timezone ) )->format( 'Y-m-d' );
			$ok    = false;
			foreach ( self::slots( $w, $s, $local, (int) $wb->staff_id, 0, 0, $wb->space_id ? array( (int) $wb->space_id ) : array() ) as $slot ) {
				if ( strtotime( $slot['start'] ) === strtotime( $wb->starts_at . ' UTC' ) && $slot['remaining'] >= (int) $wb->party_size ) {
					$wpdb->update( NWC_DB::t( 'bookings' ), array( 'status' => 'confirmed', 'staff_id' => $slot['staff_id'], 'space_id' => $slot['space_id'], 'updated_at' => NWC::now() ), array( 'id' => $wb->id ) );
					$ok = true;
					break;
				}
			}
			if ( ! $ok ) {
				break;
			}
			NWC::audit( 'booking_waitlist_promoted', array( 'workspace_id' => $w->id, 'entity' => 'booking', 'entity_id' => $wb->id, 'user_id' => 0 ) );
			if ( $wb->email ) {
				$biz = self::business( $w->id );
				NWC_Notify::email( $wb->email, 'A place opened up with ' . $biz->display_name, '<p>Good news: your waitlisted booking for <b>' . esc_html( $s->name ) . '</b> is now confirmed.</p>', $biz->display_name );
			}
			NWC_Notify::workspace_owners( (int) $w->id, 'booking', 'Waitlist moved up: ' . $wb->customer_name, $s->name, '#/bookings/list?open=' . (int) $wb->id, 'wl:' . $wb->id );
		}
	}

	public static function credits( WP_REST_Request $r ) {
		global $wpdb;
		$g = self::guard( 'bookings.read' );
		if ( is_wp_error( $g ) ) {
			return $g;
		}
		$vals = array( (int) NWC_Tenancy::$current->id );
		$sql  = 'c.workspace_id = %d';
		if ( $r->get_param( 'customer_id' ) ) {
			$sql   .= ' AND c.customer_id = %d';
			$vals[] = absint( $r->get_param( 'customer_id' ) );
		}
		if ( $r->get_param( 'status' ) ) {
			$sql   .= ' AND c.status = %s';
			$vals[] = sanitize_key( (string) $r->get_param( 'status' ) );
		}
		return array( 'items' => $wpdb->get_results( $wpdb->prepare( 'SELECT c.*, cu.name AS customer_name, s.name AS service_name FROM ' . NWC_DB::t( 'booking_credits' ) . ' c LEFT JOIN ' . NWC_DB::t( 'customers' ) . ' cu ON cu.id = c.customer_id LEFT JOIN ' . NWC_DB::t( 'services' ) . " s ON s.id = c.service_id WHERE $sql ORDER BY c.id DESC LIMIT 300", $vals ) ) ); // phpcs:ignore
	}

	/** Booking page preview for staff, published or not. */
	public static function preview( WP_REST_Request $r ) {
		global $wpdb;
		$g = self::guard( 'bookings.read' );
		if ( is_wp_error( $g ) ) {
			return $g;
		}
		$p = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . NWC_DB::t( 'booking_pages' ) . ' WHERE id = %d AND workspace_id = %d AND deleted_at IS NULL', (int) $r['id'], NWC_Tenancy::$current->id ) ); // phpcs:ignore
		return $p ? array_merge( self::public_payload( $p ), array( 'preview' => true, 'status' => $p->status, 'is_active' => (bool) (int) $p->is_active ) ) : NWC::err( 'Booking page not found.', 404, 'nwc_not_found' );
	}

	/**
	 * Guided page creation in one transaction: new services go into the catalogue, chosen services
	 * get their pricing and capacity, availability rules are added for the chosen spaces and days,
	 * then the page itself. Any failure leaves nothing half-made.
	 *
	 * Body: page{title, page_type, intro, design, fields, min_notice_hours, max_days_ahead, staff_id},
	 * service_ids[], new_services[{name, type_key, type_custom, duration_min, price, capacity, booking_kind, setup_min, cleanup_min}],
	 * pricing[{id, price, capacity}], space_ids[], schedule[{weekdays[], start_time, end_time, start_date, end_date, slot_min}], publish.
	 */
	/**
	 * Phase 5: the wizard's time blocks. Each needs valid days, a start before its end and a date
	 * range in order; the same block twice is refused instead of silently doubling the slots.
	 *
	 * @return array|WP_Error Clean blocks.
	 */
	public static function clean_schedule( $list ) {
		$out  = array();
		$seen = array();
		foreach ( array_values( $list ) as $i => $x ) {
			$x    = (array) $x;
			$n    = $i + 1;
			$days = array_values( array_unique( array_map( 'sanitize_key', (array) ( $x['weekdays'] ?? array() ) ) ) );
			if ( array_diff( $days, self::DAYS ) ) {
				return NWC::err( 'Time block ' . $n . ' has an unknown day.', 422, 'nwc_bad_schedule' );
			}
			$s = (string) ( $x['start_time'] ?? '' );
			$e = (string) ( $x['end_time'] ?? '' );
			if ( ! preg_match( '/^([01]\d|2[0-3]):[0-5]\d$/', $s ) || ! preg_match( '/^([01]\d|2[0-3]):[0-5]\d$/', $e ) || $e <= $s ) {
				return NWC::err( 'Time block ' . $n . ': the start time must be before the end time.', 422, 'nwc_bad_schedule' );
			}
			$from = (string) ( $x['start_date'] ?? '' );
			$to   = (string) ( $x['end_date'] ?? '' );
			if ( ( '' !== $from && ! NWC::valid_date( $from ) ) || ( '' !== $to && ! NWC::valid_date( $to ) ) || ( '' !== $from && '' !== $to && $to < $from ) ) {
				return NWC::err( 'Time block ' . $n . ': the end date must be on or after the start date.', 422, 'nwc_bad_schedule' );
			}
			$slot = absint( $x['slot_min'] ?? 0 );
			if ( $slot && ( $slot < 5 || $slot > 720 ) ) {
				return NWC::err( 'Time block ' . $n . ': the slot length must be between 5 and 720 minutes.', 422, 'nwc_bad_schedule' );
			}
			sort( $days );
			$key = implode( ',', $days ) . '|' . $s . '|' . $e . '|' . $from . '|' . $to . '|' . absint( $x['staff_id'] ?? 0 );
			if ( isset( $seen[ $key ] ) ) {
				return NWC::err( 'Time block ' . $n . ' is the same as time block ' . $seen[ $key ] . '.', 422, 'nwc_duplicate_block' );
			}
			$seen[ $key ] = $n;
			$out[]        = array( 'weekdays' => $days, 'start_time' => $s, 'end_time' => $e, 'start_date' => '' !== $from ? $from : null, 'end_date' => '' !== $to ? $to : null, 'slot_min' => $slot ? $slot : null, 'staff_id' => absint( $x['staff_id'] ?? 0 ) ? absint( $x['staff_id'] ) : null );
		}
		return $out;
	}

	public static function wizard( WP_REST_Request $r ) {
		$g = self::guard( 'bookings.manage' );
		if ( is_wp_error( $g ) ) {
			return $g;
		}
		$b = NWC_REST::body( $r );
		return NWC::tx( function () use ( $b ) {
			$ids = array_values( array_filter( array_map( 'absint', (array) ( $b['service_ids'] ?? array() ) ) ) );
			$spaces = array_values( array_filter( array_map( 'absint', (array) ( $b['space_ids'] ?? array() ) ) ) );
			foreach ( (array) ( $b['new_services'] ?? array() ) as $ns ) {
				$ns  = (array) $ns;
				$res = NWC_Resources::save( 'services', 0, array_merge( array( 'is_active' => 1, 'capacity' => 1 ), $ns, array( 'space_ids' => $spaces ) ) );
				if ( is_wp_error( $res ) ) {
					return $res;
				}
				$ids[] = (int) $res->id;
			}
			if ( ! $ids ) {
				return NWC::err( 'Add at least one service, class or rental to the page.' );
			}
			foreach ( (array) ( $b['pricing'] ?? array() ) as $p ) {
				$p = (array) $p;
				if ( in_array( absint( $p['id'] ?? 0 ), $ids, true ) ) {
					$res = NWC_Resources::save( 'services', absint( $p['id'] ), array_intersect_key( $p, array( 'price' => 1, 'capacity' => 1, 'waitlist' => 1 ) ) );
					if ( is_wp_error( $res ) ) {
						return $res;
					}
				}
			}
			if ( $spaces ) { // Chosen spaces become eligible for every service on the page.
				foreach ( $ids as $sid ) {
					$svc  = self::service( NWC_Tenancy::$current->id, $sid );
					$have = array_map( 'intval', (array) json_decode( (string) $svc->space_ids, true ) );
					$res  = NWC_Resources::save( 'services', $sid, array( 'space_ids' => array_values( array_unique( array_merge( $have, $spaces ) ) ) ) );
					if ( is_wp_error( $res ) ) {
						return $res;
					}
				}
			}
			$sched = self::clean_schedule( (array) ( $b['schedule'] ?? array() ) );
			if ( is_wp_error( $sched ) ) {
				return $sched;
			}
			foreach ( $sched as $rule ) {
				foreach ( $spaces ? $spaces : array( 0 ) as $sp ) {
					$res = NWC_Resources::save( 'availability', 0, array_merge( array( 'label' => mb_substr( (string) ( $b['page']['title'] ?? 'Booking page' ), 0, 120 ), 'is_active' => 1 ), array_intersect_key( $rule, array_flip( array( 'weekdays', 'start_time', 'end_time', 'start_date', 'end_date', 'slot_min', 'staff_id' ) ) ), array( 'service_ids' => $ids, 'space_id' => $sp ? $sp : null ) ) );
					if ( is_wp_error( $res ) ) {
						return $res;
					}
				}
			}
			$page = array_merge( (array) ( $b['page'] ?? array() ), array( 'service_ids' => $ids, 'space_ids' => $spaces, 'status' => ! empty( $b['publish'] ) ? 'published' : 'draft', 'is_active' => 1 ) );
			$res  = NWC_Resources::save( 'booking_pages', 0, $page );
			if ( is_wp_error( $res ) ) {
				return $res;
			}
			NWC::audit( 'booking_page_wizard', array( 'entity' => 'booking_page', 'entity_id' => (int) $res->id, 'details' => array( 'services' => $ids, 'spaces' => $spaces ) ) );
			return array( 'page' => $res, 'service_ids' => $ids );
		} );
	}

	public static function update( WP_REST_Request $r ) {
		global $wpdb;
		$g = self::guard( 'bookings.write' );
		if ( is_wp_error( $g ) ) {
			return $g;
		}
		$old = self::row( NWC_Tenancy::$current->id, (int) $r['id'] );
		if ( ! $old ) {
			return NWC::err( 'Booking not found.', 404, 'nwc_not_found' );
		}
		$b   = NWC_REST::body( $r );
		$row = array( 'updated_at' => NWC::now() );
		if ( isset( $b['status'] ) ) {
			if ( ! in_array( $b['status'], array( 'pending', 'confirmed', 'cancelled', 'completed', 'no_show', 'waitlisted', 'expired' ), true ) ) {
				return NWC::err( 'Unknown status.' );
			}
			$row['status'] = $b['status'];
		}
		if ( array_key_exists( 'notes', $b ) ) {
			$row['notes'] = sanitize_textarea_field( (string) $b['notes'] );
		}
		if ( isset( $b['party_size'] ) && max( 1, (int) $b['party_size'] ) !== (int) $old->party_size ) {
			$row['party_size'] = max( 1, (int) $b['party_size'] );
			$old->party_size   = $row['party_size']; // The seat check below counts the new size.
			$resize            = true;
		}
		if ( ! NWC::lock( 'book:' . $old->workspace_id ) ) {
			return NWC::err( 'Busy right now. Please try again.', 503, 'nwc_busy' );
		}
		$cur = self::row( $old->workspace_id, $old->id ); // Decide on the state inside the lock, not before it.
		if ( $cur && $cur->status !== $old->status ) {
			$cur->party_size = $old->party_size;
			$old             = $cur;
		}
		$old_live = self::is_live( $old );
		$reviving = isset( $row['status'] ) && in_array( $row['status'], array( 'pending', 'confirmed' ), true ) && ! $old_live;
		if ( isset( $row['status'] ) && 'pending' === $row['status'] && 'pending' !== $old->status ) {
			$row['hold_expires_at'] = self::hold_until( (int) $old->workspace_id, true );
		} elseif ( isset( $row['status'] ) && 'confirmed' === $row['status'] ) {
			$row['hold_expires_at'] = null;
		}
		$releasing = isset( $row['status'] ) && in_array( $row['status'], array( 'cancelled', 'no_show', 'expired' ), true );
		try {
			$live = in_array( isset( $row['status'] ) ? $row['status'] : $old->status, array( 'pending', 'confirmed' ), true );
			if ( ! empty( $b['start'] ) || ! empty( $b['space_id'] ) || $reviving || ( ! empty( $resize ) && $live ) ) { // Time, room or a cancelled booking coming back: recheck the clash rules.
				$res = self::move( $old, ! empty( $b['start'] ) ? (string) $b['start'] : gmdate( 'Y-m-d\TH:i:s\Z', strtotime( $old->starts_at . ' UTC' ) ), absint( $b['staff_id'] ?? $old->staff_id ), absint( $b['space_id'] ?? $old->space_id ), $b );
				if ( is_wp_error( $res ) ) {
					return $res;
				}
				$row += $res;
			}
			$freed = false;
			if ( $releasing ) { // One conditional release: a repeated cancel gives nothing back twice.
				$to = $row['status'];
				unset( $row['status'] );
				$freed = in_array( $old->status, array( 'pending', 'confirmed' ), true ) && self::release( $old, 'staff_' . $to, $to ) && $old_live;
				$wpdb->update( NWC_DB::t( 'bookings' ), $row, array( 'id' => $old->id ) );
				$row['status'] = $to;
			} else {
				$wpdb->update( NWC_DB::t( 'bookings' ), $row, array( 'id' => $old->id ) );
			}
		} finally {
			NWC::unlock( 'book:' . $old->workspace_id );
		}
		if ( $freed && ! empty( $b['makeup_credit'] ) && $old->customer_id ) { // Makeup lesson: a credit for a later booking.
			$wpdb->suppress_errors( true );
			$wpdb->insert( NWC_DB::t( 'booking_credits' ), array( 'workspace_id' => (int) $old->workspace_id, 'customer_id' => (int) $old->customer_id, 'service_id' => (int) $old->service_id, 'source_booking_id' => (int) $old->id, 'reason' => mb_substr( sanitize_text_field( (string) ( $b['credit_reason'] ?? 'Missed session' ) ), 0, 190 ), 'expires_on' => gmdate( 'Y-m-d', time() + max( 7, (int) ( $b['credit_days'] ?? 60 ) ) * DAY_IN_SECONDS ), 'status' => 'open', 'created_by' => get_current_user_id(), 'created_at' => NWC::now(), 'updated_at' => NWC::now() ) );
			$wpdb->suppress_errors( false );
		}
		if ( ! empty( $b['start'] ) && $old_live ) { // A moved booking frees its old time (a released one was promoted by release()).
			self::promote_waitlist( $old );
		}
		// Logged only when something really changed, with what it was before.
		$changed = array();
		foreach ( array_diff_key( $row, array( 'updated_at' => 1, 'reminder_sent' => 1 ) ) as $k => $v ) {
			if ( (string) $old->$k !== (string) $v ) {
				$changed[ $k ] = array( 'from' => $old->$k, 'to' => $v );
			}
		}
		if ( $changed ) {
			NWC::audit( 'booking_updated', array( 'entity' => 'booking', 'entity_id' => $old->id, 'details' => $changed ) );
		}
		NWC_Sync::enqueue( (int) $old->workspace_id, 'booking', (int) $old->id );
		$fresh = self::row( $old->workspace_id, $old->id );
		if ( isset( $changed['status'] ) && 'confirmed' === $fresh->status ) {
			NWC_Finance::maybe_auto_invoice( $fresh, 'confirmed' );
		}
		return self::out( $fresh );
	}

	/** @return array|WP_Error New time fields, once the slot is confirmed open. */
	private static function move( $old, $start, $staff_id, $space_id = 0, $in = array() ) {
		$w   = NWC_Tenancy::workspace( $old->workspace_id );
		$s   = self::service( $w->id, $old->service_id );
		$utc = NWC::to_utc( $start, 'UTC' );
		if ( ! $s || ! $utc ) {
			return NWC::err( 'Choose a valid time.' );
		}
		$st    = strtotime( $utc . ' UTC' );
		$local = ( new DateTimeImmutable( '@' . $st ) )->setTimezone( new DateTimeZone( $w->timezone ) )->format( 'Y-m-d' );
		$times = function ( $staff, $space ) use ( $utc, $st, $s ) {
			$end = $st + (int) $s->duration_min * 60;
			return array( 'starts_at' => $utc, 'ends_at' => gmdate( 'Y-m-d H:i:s', $end ), 'block_start' => gmdate( 'Y-m-d H:i:s', $st - (int) $s->setup_min * 60 ), 'block_end' => gmdate( 'Y-m-d H:i:s', $end + (int) $s->cleanup_min * 60 ), 'staff_id' => $staff ? $staff : null, 'space_id' => $space ? $space : null, 'reminder_sent' => 0 );
		};
		foreach ( self::slots( $w, $s, $local, $staff_id, 0, $old->id, $space_id ? array( $space_id ) : array() ) as $slot ) {
			if ( strtotime( $slot['start'] ) === $st && $slot['remaining'] >= (int) $old->party_size ) {
				return $times( $slot['staff_id'], $slot['space_id'] );
			}
		}
		if ( ! empty( $in['override'] ) && NWC_Tenancy::is_manager() && '' !== trim( (string) ( $in['override_reason'] ?? '' ) ) ) {
			NWC::audit( 'booking_override', array( 'kind' => 'admin', 'entity' => 'booking', 'entity_id' => $old->id, 'details' => array( 'reason' => sanitize_text_field( (string) $in['override_reason'] ), 'conflicts' => self::conflicts( $w, $s, $st, $staff_id, $space_id, $old->id ) ) ) );
			return array_merge( $times( $staff_id, $space_id ), array( 'override_reason' => sanitize_textarea_field( (string) $in['override_reason'] ), 'override_by' => get_current_user_id() ) );
		}
		return NWC::err( 'That time is not available.', 409, 'nwc_slot_taken', array( 'conflicts' => self::conflicts( $w, $s, $st, $staff_id, $space_id, $old->id ) ) );
	}

	public static function report( WP_REST_Request $r ) {
		global $wpdb;
		$g = self::guard( 'bookings.read' );
		if ( is_wp_error( $g ) ) {
			return $g;
		}
		$ws   = (int) NWC_Tenancy::$current->id;
		$t    = NWC_DB::t( 'bookings' );
		$from = NWC::valid_date( (string) $r->get_param( 'from' ) ) ? $r->get_param( 'from' ) . ' 00:00:00' : gmdate( 'Y-m-d H:i:s', time() - 30 * DAY_IN_SECONDS );
		$to   = NWC::valid_date( (string) $r->get_param( 'to' ) ) ? $r->get_param( 'to' ) . ' 23:59:59' : gmdate( 'Y-m-d H:i:s', time() + 30 * DAY_IN_SECONDS );
		$util = array();
		$hours = max( 1, ( strtotime( $to . ' UTC' ) - strtotime( $from . ' UTC' ) ) / HOUR_IN_SECONDS );
		$per = array();
		foreach ( $wpdb->get_results( $wpdb->prepare( "SELECT space_id, starts_at, ends_at FROM $t WHERE workspace_id = %d AND deleted_at IS NULL AND space_id IS NOT NULL AND status IN ('confirmed','completed') AND starts_at BETWEEN %s AND %s", $ws, $from, $to ) ) as $x ) { // phpcs:ignore
			$k = (int) $x->space_id;
			$per[ $k ] = $per[ $k ] ?? array( 0, 0 );
			$per[ $k ][0]++;
			$per[ $k ][1] += max( 0, strtotime( $x->ends_at . ' UTC' ) - strtotime( $x->starts_at . ' UTC' ) );
		}
		foreach ( $wpdb->get_results( $wpdb->prepare( 'SELECT id, name FROM ' . NWC_DB::t( 'spaces' ) . ' WHERE workspace_id = %d AND deleted_at IS NULL ORDER BY sort ASC, name ASC', $ws ) ) as $x ) { // phpcs:ignore
			$p      = $per[ (int) $x->id ] ?? array( 0, 0 );
			$util[] = array( 'space' => $x->name, 'bookings' => $p[0], 'hours' => round( $p[1] / 3600, 1 ) );
		}
		return array(
			'from'       => $from, 'to' => $to,
			'by_status'  => $wpdb->get_results( $wpdb->prepare( "SELECT status, COUNT(*) n FROM $t WHERE workspace_id = %d AND deleted_at IS NULL AND starts_at BETWEEN %s AND %s GROUP BY status", $ws, $from, $to ) ), // phpcs:ignore
			'by_service' => $wpdb->get_results( $wpdb->prepare( "SELECT s.name, COUNT(*) n, COALESCE(SUM(s.price * b.party_size),0) value FROM $t b JOIN " . NWC_DB::t( 'services' ) . " s ON s.id = b.service_id WHERE b.workspace_id = %d AND b.deleted_at IS NULL AND b.status IN ('confirmed','completed') AND b.starts_at BETWEEN %s AND %s GROUP BY s.name ORDER BY n DESC LIMIT 10", $ws, $from, $to ) ), // phpcs:ignore
			'by_kind'    => $wpdb->get_results( $wpdb->prepare( "SELECT s.booking_kind AS kind, COUNT(*) n FROM $t b JOIN " . NWC_DB::t( 'services' ) . " s ON s.id = b.service_id WHERE b.workspace_id = %d AND b.deleted_at IS NULL AND b.starts_at BETWEEN %s AND %s GROUP BY s.booking_kind", $ws, $from, $to ) ), // phpcs:ignore
			'by_staff'   => array_map( function ( $x ) {
				$u = $x->staff_id ? get_userdata( (int) $x->staff_id ) : null;
				return array( 'name' => $u ? $u->display_name : 'Unassigned', 'n' => (int) $x->n );
			}, $wpdb->get_results( $wpdb->prepare( "SELECT staff_id, COUNT(*) n FROM $t WHERE workspace_id = %d AND deleted_at IS NULL AND status IN ('confirmed','completed') AND starts_at BETWEEN %s AND %s GROUP BY staff_id ORDER BY n DESC LIMIT 10", $ws, $from, $to ) ) ), // phpcs:ignore
			'by_page'    => $wpdb->get_results( $wpdb->prepare( "SELECT p.title, COUNT(*) n FROM $t b JOIN " . NWC_DB::t( 'booking_pages' ) . " p ON p.id = b.page_id WHERE b.workspace_id = %d AND b.deleted_at IS NULL AND b.starts_at BETWEEN %s AND %s GROUP BY p.title ORDER BY n DESC LIMIT 10", $ws, $from, $to ) ), // phpcs:ignore
			'spaces'     => $util,
			'hours_in_range' => round( $hours ),
			'waitlisted' => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $t WHERE workspace_id = %d AND deleted_at IS NULL AND status = 'waitlisted'", $ws ) ), // phpcs:ignore
			'overrides'  => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $t WHERE workspace_id = %d AND deleted_at IS NULL AND override_by IS NOT NULL AND starts_at BETWEEN %s AND %s", $ws, $from, $to ) ), // phpcs:ignore
		);
	}

	public static function conflicts_rest( WP_REST_Request $r ) {
		$g = self::guard( 'bookings.read' );
		if ( is_wp_error( $g ) ) {
			return $g;
		}
		$s   = self::service( NWC_Tenancy::$current->id, absint( $r->get_param( 'service_id' ) ) );
		$utc = NWC::to_utc( (string) $r->get_param( 'start' ), 'UTC' );
		if ( ! $s || ! $utc ) {
			return NWC::err( 'Choose a service and a time.' );
		}
		return array( 'conflicts' => self::conflicts( NWC_Tenancy::$current, $s, strtotime( $utc . ' UTC' ), absint( $r->get_param( 'staff_id' ) ), absint( $r->get_param( 'space_id' ) ), absint( $r->get_param( 'ignore' ) ) ) );
	}

	public static function limits() {
		global $wpdb;
		$g = self::guard( 'bookings.read' );
		if ( is_wp_error( $g ) ) {
			return $g;
		}
		$ws = (int) NWC_Tenancy::$current->id;
		return array( 'limit' => self::page_limit( $ws ), 'used' => (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . NWC_DB::t( 'booking_pages' ) . ' WHERE workspace_id = %d AND deleted_at IS NULL', $ws ) ), 'included' => 5 ); // phpcs:ignore
	}

	/* ---------------------------- Public ---------------------------- */

	public static function page_by_slug( $slug ) {
		global $wpdb;
		$p = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . NWC_DB::t( 'booking_pages' ) . " WHERE slug = %s AND is_active = 1 AND status = 'published' AND deleted_at IS NULL", $slug ) ); // phpcs:ignore
		if ( $p && 'main' !== $p->kind ) { // Pages beyond the included five and live add-ons stop being public when an add-on lapses.
			$rank = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . NWC_DB::t( 'booking_pages' ) . " WHERE workspace_id = %d AND deleted_at IS NULL AND (kind = 'main' OR id <= %d)", $p->workspace_id, $p->id ) ); // phpcs:ignore
			if ( $rank > self::page_limit( (int) $p->workspace_id ) ) {
				return null;
			}
		}
		return $p;
	}

	/** Which services a page shows: its own list, narrowed by its type and instructor. */
	private static function page_services( $page, $rows ) {
		$ids   = array_map( 'intval', (array) json_decode( (string) $page->service_ids, true ) );
		$type  = (string) ( $page->page_type ?? 'all' );
		$kinds = array( 'class' => array( 'regular_class', 'dance_class', 'music_class', 'group_class', 'trial_class' ), 'workshop' => array( 'workshop', 'event' ), 'rental' => array( 'studio_rental', 'practice_session', 'rehearsal' ) );
		return array_values( array_filter( $rows, function ( $s ) use ( $ids, $type, $kinds, $page ) {
			if ( $ids && ! in_array( (int) $s->id, $ids, true ) ) {
				return false;
			}
			if ( isset( $kinds[ $type ] ) && ! $ids && ! in_array( (string) $s->booking_kind, $kinds[ $type ], true ) ) {
				return false;
			}
			if ( 'instructor' === $type && ! empty( $page->staff_id ) ) {
				$staff = array_map( 'intval', (array) json_decode( (string) $s->staff_ids, true ) );
				return ! $staff || in_array( (int) $page->staff_id, $staff, true );
			}
			return true;
		} ) );
	}

	/** Only what a visitor needs. Internal fields are not selected into this payload. */
	public static function public_payload( $page ) {
		global $wpdb;
		$w   = NWC_Tenancy::workspace( $page->workspace_id );
		$biz = self::business( $w->id );
		$sql = 'SELECT id, name, description, duration_min, price, capacity, service_type, type_key, type_custom, staff_ids, space_ids, booking_kind, waitlist, image_url, pay_mode FROM ' . NWC_DB::t( 'services' ) . ' WHERE workspace_id = %d AND is_active = 1 AND deleted_at IS NULL AND archived_at IS NULL';
		$rows = self::page_services( $page, $wpdb->get_results( $wpdb->prepare( $sql, $w->id ) ) ); // phpcs:ignore
		$svc = array();
		$page_spaces = array_map( 'intval', (array) json_decode( (string) ( $page->space_ids ?? '' ), true ) );
		foreach ( $rows as $s ) {
			$s->spaces = array();
			foreach ( array_map( 'intval', (array) json_decode( (string) $s->space_ids, true ) ) as $sp ) {
				$row = self::space( $w->id, $sp );
				if ( $row && (int) $row->is_active && ( ! $page_spaces || in_array( $sp, $page_spaces, true ) ) ) {
					$s->spaces[] = array( 'id' => (int) $row->id, 'name' => $row->name );
				}
			}
			unset( $s->space_ids );
			$s->kind_label = NWC_Industry::BOOKING_KINDS[ (string) $s->booking_kind ] ?? '';
			$s->waitlist   = (bool) (int) $s->waitlist;
			if ( true ) {
				$staff = array();
				foreach ( (array) json_decode( (string) $s->staff_ids, true ) as $u ) {
					$usr = get_userdata( (int) $u );
					if ( $usr ) {
						$staff[] = array( 'id' => (int) $u, 'name' => $usr->display_name );
					}
				}
				unset( $s->staff_ids );
				$s->type_label = NWC_Catalog::type_label( (string) $s->type_key, (string) $s->type_custom );
				unset( $s->type_key, $s->type_custom );
				$s->staff = $staff;
				$svc[]    = $s;
			}
		}
		$socials = json_decode( (string) $biz->socials, true );
		return array(
			'whatsapp' => (bool) ( NWC_Bookings::business( $page->workspace_id )->whatsapp_enabled ?? false ),
			'accepting' => NWC_Features::allowed( 'bookings', $w ),
			'page'      => array( 'title' => $page->title, 'intro' => (string) $page->intro, 'min_notice_hours' => (int) $page->min_notice_hours, 'max_days_ahead' => (int) $page->max_days_ahead, 'page_type' => (string) ( $page->page_type ?? 'all' ), 'is_main' => 'main' === ( $page->kind ?? '' ), 'design' => (object) array_diff_key( (array) ( json_decode( (string) ( $page->design ?? '' ), true ) ?: array() ), array( 'brand_color' => 1 ) ), 'fields' => (array) ( json_decode( (string) ( $page->fields ?? '' ), true ) ?: array() ) ),
			'business'  => array( 'name' => $biz->display_name, 'logo_url' => $biz->logo_url, 'cover_url' => $biz->cover_url, 'description' => (string) $biz->description, 'category' => $biz->category, 'address' => (string) $biz->address, 'phone' => $biz->phone, 'email' => $biz->email, 'map_url' => $biz->map_url, 'policies' => (string) $biz->policies, 'socials' => is_array( $socials ) ? $socials : array(), 'timezone' => $w->timezone ),
			'services'  => $svc,
		);
	}

	public static function public_page( WP_REST_Request $r ) {
		$page = self::page_by_slug( (string) $r['slug'] );
		return $page ? self::public_payload( $page ) : NWC::err( 'Booking page not found.', 404, 'nwc_not_found' );
	}

	private static function page_context( $slug ) {
		$page = self::page_by_slug( $slug );
		if ( ! $page ) {
			return NWC::err( 'Booking page not found.', 404, 'nwc_not_found' );
		}
		$w = NWC_Tenancy::workspace( $page->workspace_id );
		if ( ! $w || ! NWC_Features::allowed( 'bookings', $w ) ) {
			return NWC::err( 'This business is not taking online bookings right now.', 403, 'nwc_unavailable' );
		}
		return array( $page, $w );
	}

	public static function public_slots( WP_REST_Request $r ) {
		if ( ! NWC::rate_ok( 'slots|' . NWC::ip(), 120 ) ) {
			return NWC::err( 'Too many requests.', 429, 'nwc_rate_limited' );
		}
		$ctx = self::page_context( (string) $r['slug'] );
		if ( is_wp_error( $ctx ) ) {
			return $ctx;
		}
		list( $page, $w ) = $ctx;
		$sid = absint( $r->get_param( 'service_id' ) );
		$s   = self::service( $w->id, $sid );
		$d   = (string) $r->get_param( 'date' );
		if ( ! $s || ! (int) $s->is_active || ! in_array( $sid, array_map( 'intval', wp_list_pluck( self::page_services( $page, array( $s ) ), 'id' ) ), true ) || ! NWC::valid_date( $d ) ) {
			return NWC::err( 'Choose a service and date.' );
		}
		$today = NWC::today( $w->timezone );
		if ( $d < $today || $d > gmdate( 'Y-m-d', strtotime( $today ) + (int) $page->max_days_ahead * DAY_IN_SECONDS ) ) {
			return array( 'slots' => array() );
		}
		$spaces = array_map( 'intval', (array) json_decode( (string) ( $page->space_ids ?? '' ), true ) );
		$want   = absint( $r->get_param( 'space_id' ) );
		$staff  = absint( $r->get_param( 'staff_id' ) ) ? absint( $r->get_param( 'staff_id' ) ) : ( 'instructor' === $page->page_type ? (int) $page->staff_id : 0 );
		$slots  = self::slots( $w, $s, $d, $staff, (int) $page->min_notice_hours, 0, $want ? array( $want ) : $spaces, (bool) (int) $s->waitlist );
		return array( 'slots' => array_map( function ( $x ) {
			return array( 'start' => $x['start'], 'label' => $x['label'], 'remaining' => $x['remaining'], 'full' => $x['full'] );
		}, $slots ), 'waitlist' => (bool) (int) $s->waitlist );
	}

	public static function public_create( WP_REST_Request $r ) {
		$b = NWC_REST::body( $r );
		if ( ! empty( $b['website'] ) ) { // Honeypot.
			return array( 'ok' => true );
		}
		if ( ! NWC::rate_ok( 'book|' . NWC::ip(), 10, 300 ) ) {
			return NWC::err( 'Too many attempts. Please try again later.', 429, 'nwc_rate_limited' );
		}
		$ctx = self::page_context( (string) $r['slug'] );
		if ( is_wp_error( $ctx ) ) {
			return $ctx;
		}
		list( $page, $w ) = $ctx;
		$sid = absint( $b['service_id'] ?? 0 );
		$s   = self::service( $w->id, $sid );
		if ( ! $s || ! (int) $s->is_active || ! self::page_services( $page, array( $s ) ) ) {
			return NWC::err( 'Service not found.', 404, 'nwc_not_found' );
		}
		$custom = array();
		foreach ( (array) json_decode( (string) ( $page->fields ?? '' ), true ) as $f ) { // The page's own questions.
			$v = trim( sanitize_textarea_field( (string) ( $b['custom'][ $f['key'] ] ?? '' ) ) );
			if ( ! empty( $f['required'] ) && '' === $v ) {
				return NWC::err( 'Please answer: ' . $f['label'], 422, 'nwc_invalid_field' );
			}
			if ( 'select' === $f['type'] && '' !== $v && ! in_array( $v, (array) $f['options'], true ) ) {
				return NWC::err( 'Choose an option for: ' . $f['label'], 422, 'nwc_invalid_field' );
			}
			if ( '' !== $v ) {
				$custom[ $f['label'] ] = mb_substr( $v, 0, 1000 );
			}
		}
		$b['custom'] = $custom;
		unset( $b['override'], $b['credit_id'] );
		if ( 'instructor' === $page->page_type && $page->staff_id && empty( $b['staff_id'] ) ) {
			$b['staff_id'] = (int) $page->staff_id;
		}
		$start = NWC::to_utc( (string) ( $b['start'] ?? '' ), 'UTC' );
		$today = NWC::today( $w->timezone );
		if ( ! $start || strtotime( $start . ' UTC' ) > strtotime( $today ) + ( (int) $page->max_days_ahead + 1 ) * DAY_IN_SECONDS ) {
			return NWC::err( 'Choose a time within the booking window.' );
		}
		$biz0 = self::business( $w->id );
		if ( $biz0 && (int) $biz0->whatsapp_enabled && '' === trim( (string) ( $b['phone'] ?? '' ) ) ) {
			return NWC::err( 'Please enter your phone number.', 422, 'nwc_invalid_field' );
		}
		$res = self::book( $w, $s, $b, 'booking_page', $page );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		if ( $res['created'] && $biz0 && (int) $biz0->whatsapp_enabled ) {
			$GLOBALS['wpdb']->update( NWC_DB::t( 'bookings' ), array( 'whatsapp_consent' => 1 ), array( 'id' => $res['booking']->id ) );
		}
		if ( $res['created'] ) {
			$biz = self::business( $w->id );
			$when = ( new DateTimeImmutable( $res['booking']->starts_at . ' UTC' ) )->setTimezone( new DateTimeZone( $w->timezone ) )->format( 'j M Y, g:i A' );
			$mng  = home_url( '/book/manage/' . $res['token'] );
			if ( $res['booking']->email ) {
				$what = 'waitlisted' === $res['booking']->status ? 'is on the waitlist' : 'is confirmed';
				NWC_Notify::email( $res['booking']->email, 'Your booking with ' . $biz->display_name, '<p>Your booking for <b>' . esc_html( $s->name ) . '</b> ' . $what . ' for <b>' . esc_html( $when ) . '</b>.</p><p><a href="' . esc_url( $mng ) . '">Reschedule or cancel</a></p>', $biz->display_name );
			}
			NWC_Notify::workspace_owners( (int) $w->id, 'booking', 'New booking', $res['booking']->customer_name . ' · ' . $s->name . ' · ' . $when, '#/bookings/list?open=' . (int) $res['booking']->id, 'booking:' . $res['booking']->id );
			if ( 'confirmed' === $res['booking']->status ) {
				NWC_Finance::maybe_auto_invoice( $res['booking'], 'confirmed' );
			}
		}
		$out = array( 'ok' => true, 'starts_at' => $res['booking']->starts_at, 'manage_url' => $res['token'] ? home_url( '/book/manage/' . $res['token'] ) : null, 'status' => $res['booking']->status );
		$bk  = self::row( $w->id, (int) $res['booking']->id );
		if ( $bk && 'pending' === $bk->status && $bk->hold_expires_at && 'paid' !== $bk->payment_status ) {
			$v = NWC_Finance::invoice_from_booking( $bk, 'online' );
			if ( ! is_wp_error( $v ) ) {
				if ( 'draft' === $v->status ) {
					$GLOBALS['wpdb']->update( NWC_DB::t( 'biz_invoices' ), array( 'status' => 'sent', 'online_pay' => 1, 'sent_at' => NWC::now(), 'due_date' => $v->invoice_date, 'updated_at' => NWC::now() ), array( 'id' => $v->id ) );
					$v = NWC_Invoices::row( (int) $v->id, (int) $w->id );
				}
				$out['pay_url'] = NWC_Invoices::share_url( 'invoice', $v );
				$out['amount']  = (float) $v->grand_total;
			}
			$out['hold_expires_at'] = gmdate( 'Y-m-d\TH:i:s\Z', strtotime( $bk->hold_expires_at . ' UTC' ) );
		}
		return $out;
	}

	private static function by_token( $token ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . NWC_DB::t( 'bookings' ) . ' WHERE manage_token_hash = %s AND deleted_at IS NULL', hash( 'sha256', (string) $token ) ) ); // phpcs:ignore
	}

	public static function public_manage( WP_REST_Request $r ) {
		if ( ! NWC::rate_ok( 'manage|' . NWC::ip(), 60 ) ) {
			return NWC::err( 'Too many requests.', 429, 'nwc_rate_limited' );
		}
		$b = self::by_token( (string) $r['token'] );
		if ( ! $b ) {
			return NWC::err( 'Booking not found.', 404, 'nwc_not_found' );
		}
		$s    = self::service( $b->workspace_id, $b->service_id );
		$page = $b->page_id ? self::page_row( $b->page_id ) : null;
		$biz  = self::business( $b->workspace_id );
		$w    = NWC_Tenancy::workspace( $b->workspace_id );
		return array(
			'business' => array( 'name' => $biz->display_name, 'logo_url' => $biz->logo_url, 'brand_color' => $biz->brand_color, 'phone' => $biz->phone, 'timezone' => $w->timezone ),
			'service'  => $s ? array( 'id' => (int) $s->id, 'name' => $s->name ) : null,
			'starts_at' => $b->starts_at, 'status' => $b->status, 'name' => $b->customer_name,
			'can_reschedule' => $page ? (bool) $page->allow_reschedule : true,
			'slug' => $page ? $page->slug : '',
		);
	}

	private static function page_row( $id ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . NWC_DB::t( 'booking_pages' ) . ' WHERE id = %d', $id ) ); // phpcs:ignore
	}

	public static function public_manage_action( WP_REST_Request $r ) {
		global $wpdb;
		if ( ! NWC::rate_ok( 'manage|' . NWC::ip(), 30 ) ) {
			return NWC::err( 'Too many requests.', 429, 'nwc_rate_limited' );
		}
		$b = self::by_token( (string) $r['token'] );
		if ( ! $b ) {
			return NWC::err( 'Booking not found.', 404, 'nwc_not_found' );
		}
		if ( ! in_array( $b->status, array( 'pending', 'confirmed' ), true ) ) {
			return NWC::err( 'This booking can no longer be changed.', 409, 'nwc_conflict' );
		}
		$in   = NWC_REST::body( $r );
		$page = $b->page_id ? self::page_row( $b->page_id ) : null;
		if ( 'cancel' === ( $in['action'] ?? '' ) ) {
			if ( ! NWC::lock( 'book:' . $b->workspace_id ) ) {
				return NWC::err( 'Busy right now. Please try again.', 503, 'nwc_busy' );
			}
			try {
				self::release( $b, 'customer_cancelled', 'cancelled' );
			} finally {
				NWC::unlock( 'book:' . $b->workspace_id );
			}
		} elseif ( 'reschedule' === ( $in['action'] ?? '' ) ) {
			if ( $page && ! (int) $page->allow_reschedule ) {
				return NWC::err( 'Please contact the business to change this booking.', 403, 'nwc_forbidden' );
			}
			if ( ! NWC::lock( 'book:' . $b->workspace_id ) ) {
				return NWC::err( 'Busy right now. Please try again.', 503, 'nwc_busy' );
			}
			try {
				$res = self::move( $b, (string) ( $in['start'] ?? '' ), (int) $b->staff_id );
				if ( is_wp_error( $res ) ) {
					return $res;
				}
				$res['updated_at'] = NWC::now();
				$wpdb->update( NWC_DB::t( 'bookings' ), $res, array( 'id' => $b->id ) );
			} finally {
				NWC::unlock( 'book:' . $b->workspace_id );
			}
		} else {
			return NWC::err( 'Unknown action.' );
		}
		NWC_Sync::enqueue( (int) $b->workspace_id, 'booking', (int) $b->id );
		NWC_Notify::workspace_owners( (int) $b->workspace_id, 'booking', 'Booking changed by the customer', $b->customer_name, '#/bookings/list?open=' . (int) $b->id );
		return array( 'ok' => true );
	}
}
