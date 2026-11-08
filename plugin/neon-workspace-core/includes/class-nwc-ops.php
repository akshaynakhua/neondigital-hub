<?php
/**
 * Category workflows with their own rules:
 *   NWC_Stays      hotel reservations: availability over the whole stay, rates, check-in/out, folio;
 *   NWC_Checkins   gym attendance by member code (QR or card) and live occupancy;
 *   NWC_Attendance attendance per batch session for dance and music academies.
 */

defined( 'ABSPATH' ) || exit;

class NWC_Stays {

	const LIVE = array( 'reserved', 'checked_in' );

	public static function routes() {
		NWC_REST::route( '/stays', 'GET', array( __CLASS__, 'index' ) );
		NWC_REST::route( '/stays', 'POST', array( __CLASS__, 'create' ) );
		NWC_REST::route( '/stays/availability', 'GET', array( __CLASS__, 'availability' ) );
		NWC_REST::route( '/stays/grid', 'GET', array( __CLASS__, 'grid' ) );
		NWC_REST::route( '/stays/tax-settings', 'GET', array( __CLASS__, 'tax_get' ) );
		NWC_REST::route( '/stays/tax-settings', 'PUT', array( __CLASS__, 'tax_save' ) );
		NWC_REST::route( '/stays/(?P<id>\d+)', 'GET', array( __CLASS__, 'show' ) );
		NWC_REST::route( '/stays/(?P<id>\d+)', 'PUT', array( __CLASS__, 'update' ) );
		NWC_REST::route( '/stays/(?P<id>\d+)/check-in', 'POST', array( __CLASS__, 'check_in' ) );
		NWC_REST::route( '/stays/(?P<id>\d+)/check-out', 'POST', array( __CLASS__, 'check_out' ) );
		NWC_REST::route( '/stays/(?P<id>\d+)/charges', 'POST', array( __CLASS__, 'add_charge' ) );
		NWC_REST::route( '/stays/(?P<id>\d+)/cancel', 'POST', array( __CLASS__, 'cancel' ) );
	}

	private static function guard( $write = false ) {
		return NWC_Industry::guard_module( 'stays', $write ? 'write' : 'read' );
	}

	public static function row( $id, $ws = null ) {
		global $wpdb;
		$ws = $ws ? $ws : ( NWC_Tenancy::$current ? NWC_Tenancy::$current->id : 0 );
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . NWC_DB::t( 'stays' ) . ' WHERE id = %d AND workspace_id = %d', $id, $ws ) ); // phpcs:ignore
	}

	private static function out( $s ) {
		$s->id      = (int) $s->id;
		$s->room_id = (int) $s->room_id;
		$s->charges = (array) json_decode( (string) $s->charges, true );
		$room       = NWC_Industry::row( $s->room_id, $s->workspace_id );
		$s->room    = $room ? $room->code : '';
		$s->nights  = self::nights( $s->check_in, $s->check_out );
		foreach ( array( 'nightly_rate', 'deposit', 'total' ) as $k ) {
			$s->$k = (float) $s->$k;
		}
		return $s;
	}

	/**
	 * GST on room nights by tariff per night. Defaults follow the slabs as understood for India
	 * from 22 Sep 2025 (up to ₹7,500 a night 5%, above 18%); every business must confirm its own
	 * rates with its tax adviser, and can change them in Reservations → GST on room nights.
	 */
	const DEFAULT_ROOM_SLABS = array( array( 'upto' => 7500, 'rate' => 5 ), array( 'upto' => null, 'rate' => 18 ) );

	public static function tax_settings( $ws ) {
		$biz = NWC_Bookings::business( $ws );
		$s   = $biz ? json_decode( (string) $biz->tax_settings, true ) : null;
		$sl  = is_array( $s ) && ! empty( $s['room_slabs'] ) ? $s['room_slabs'] : self::DEFAULT_ROOM_SLABS;
		return array( 'room_slabs' => $sl, 'confirmed' => ! empty( $s['confirmed'] ), 'is_default' => ! ( is_array( $s ) && ! empty( $s['room_slabs'] ) ), 'gst_registered' => $biz ? (bool) $biz->gst_registered : false );
	}

	/** The GST rate for a room priced at this amount per night. */
	public static function room_rate_for( $ws, $nightly ) {
		foreach ( self::tax_settings( $ws )['room_slabs'] as $s ) {
			if ( null === $s['upto'] || '' === $s['upto'] || (float) $nightly <= (float) $s['upto'] ) {
				return (float) $s['rate'];
			}
		}
		return 0.0;
	}

	public static function tax_get() {
		$g = self::guard();
		return is_wp_error( $g ) ? $g : self::tax_settings( (int) NWC_Tenancy::$current->id );
	}

	/** Body: room_slabs [{upto (null for "above"), rate}], confirmed. Slabs ascend and end open. */
	public static function tax_save( WP_REST_Request $r ) {
		global $wpdb;
		if ( ! NWC_Tenancy::can( 'settings.manage' ) ) {
			return NWC::err( 'Your role does not allow this.', 403, 'nwc_forbidden' );
		}
		$g = self::guard();
		if ( is_wp_error( $g ) ) {
			return $g;
		}
		$b    = NWC_REST::body( $r );
		$out  = array();
		$last = -1;
		foreach ( array_slice( (array) ( $b['room_slabs'] ?? array() ), 0, 6 ) as $s ) {
			$upto = isset( $s['upto'] ) && '' !== $s['upto'] && null !== $s['upto'] ? round( (float) $s['upto'], 2 ) : null;
			$rate = (float) ( $s['rate'] ?? -1 );
			if ( $rate < 0 || $rate > 28 ) {
				return NWC::err( 'Each GST rate must be between 0 and 28%.' );
			}
			if ( null !== $upto && $upto <= $last ) {
				return NWC::err( 'Slabs must go up in price, ending with "above".' );
			}
			$out[] = array( 'upto' => $upto, 'rate' => $rate );
			if ( null === $upto ) {
				break;
			}
			$last = $upto;
		}
		if ( ! $out || null !== end( $out )['upto'] ) {
			return NWC::err( 'End with a slab for every tariff above the last limit.' );
		}
		$wpdb->update( NWC_DB::t( 'business' ), array( 'tax_settings' => wp_json_encode( array( 'room_slabs' => $out, 'confirmed' => ! empty( $b['confirmed'] ) ) ), 'updated_at' => NWC::now() ), array( 'workspace_id' => NWC_Tenancy::$current->id ) );
		NWC::audit( 'room_gst_changed', array( 'kind' => 'admin', 'entity' => 'business', 'entity_id' => NWC_Tenancy::$current->id, 'details' => array( 'slabs' => $out ) ) );
		return self::tax_settings( (int) NWC_Tenancy::$current->id );
	}

	public static function nights( $in, $out ) {
		return max( 0, (int) round( ( strtotime( $out . ' 12:00:00 UTC' ) - strtotime( $in . ' 12:00:00 UTC' ) ) / DAY_IN_SECONDS ) );
	}

	/** Rooms of this type (or any) that are free for every night from check-in to check-out. */
	public static function free_rooms( $ws, $in, $out, $type_id = 0, $except_stay = 0 ) {
		global $wpdb;
		$R    = NWC_DB::t( 'biz_records' );
		$vals = array( $ws );
		$sql  = "workspace_id = %d AND entity = 'room' AND deleted_at IS NULL AND status NOT IN ('maintenance','out_of_order')";
		if ( $type_id ) {
			$sql   .= ' AND parent_id = %d';
			$vals[] = $type_id;
		}
		$rooms = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $R WHERE $sql ORDER BY code ASC", $vals ) ); // phpcs:ignore
		$taken = array_map( 'intval', $wpdb->get_col( $wpdb->prepare( 'SELECT DISTINCT room_id FROM ' . NWC_DB::t( 'stays' ) . " WHERE workspace_id = %d AND status IN ('reserved','checked_in') AND check_in < %s AND check_out > %s AND id <> %d", $ws, $out, $in, $except_stay ) ) ); // phpcs:ignore
		return array_values( array_filter( $rooms, function ( $r ) use ( $taken ) {
			return ! in_array( (int) $r->id, $taken, true );
		} ) );
	}

	/** Night-by-night price: a seasonal rate for the night if one covers it, else the base rate. */
	public static function price( $ws, $type_id, $in, $out ) {
		global $wpdb;
		$type    = NWC_Industry::row( $type_id, $ws );
		$base    = $type ? (float) $type->amount : 0;
		$seasons = $wpdb->get_results( $wpdb->prepare( 'SELECT on_date, end_date, amount FROM ' . NWC_DB::t( 'biz_records' ) . " WHERE workspace_id = %d AND entity = 'rate_season' AND parent_id = %d AND deleted_at IS NULL", $ws, $type_id ) ); // phpcs:ignore
		$nights  = array();
		for ( $t = strtotime( $in . ' 12:00:00 UTC' ); $t < strtotime( $out . ' 12:00:00 UTC' ); $t += DAY_IN_SECONDS ) {
			$d    = gmdate( 'Y-m-d', $t );
			$rate = $base;
			foreach ( $seasons as $s ) {
				if ( $d >= $s->on_date && $d <= $s->end_date ) {
					$rate = (float) $s->amount;
				}
			}
			$nights[] = array( 'date' => $d, 'rate' => $rate );
		}
		return $nights;
	}

	private static function dates( $b, $old = null ) {
		$in  = (string) ( $b['check_in'] ?? ( $old ? $old->check_in : '' ) );
		$out = (string) ( $b['check_out'] ?? ( $old ? $old->check_out : '' ) );
		if ( ! NWC::valid_date( $in ) || ! NWC::valid_date( $out ) ) {
			return NWC::err( 'Choose check-in and check-out dates.' );
		}
		if ( $out <= $in ) {
			return NWC::err( 'Check-out must be at least one night after check-in.' );
		}
		if ( self::nights( $in, $out ) > 90 ) {
			return NWC::err( 'A single reservation can be up to 90 nights.' );
		}
		return array( $in, $out );
	}

	public static function availability( WP_REST_Request $r ) {
		$g = self::guard();
		if ( is_wp_error( $g ) ) {
			return $g;
		}
		$d = self::dates( array( 'check_in' => $r->get_param( 'check_in' ), 'check_out' => $r->get_param( 'check_out' ) ) );
		if ( is_wp_error( $d ) ) {
			return $d;
		}
		$ws  = (int) NWC_Tenancy::$current->id;
		$out = array();
		foreach ( self::free_rooms( $ws, $d[0], $d[1], absint( $r->get_param( 'room_type_id' ) ) ) as $room ) {
			$n     = self::price( $ws, (int) $room->parent_id, $d[0], $d[1] );
			$out[] = array( 'room_id' => (int) $room->id, 'room' => $room->code, 'room_type_id' => (int) $room->parent_id, 'status' => $room->status, 'total' => round( array_sum( wp_list_pluck( $n, 'rate' ) ), 2 ), 'nights' => $n );
		}
		return array( 'check_in' => $d[0], 'check_out' => $d[1], 'rooms' => $out );
	}

	public static function index( WP_REST_Request $r ) {
		global $wpdb;
		$g = self::guard();
		if ( is_wp_error( $g ) ) {
			return $g;
		}
		$vals = array( (int) NWC_Tenancy::$current->id );
		$sql  = 'workspace_id = %d';
		$st   = sanitize_key( (string) $r->get_param( 'status' ) );
		if ( '' !== $st ) {
			$sql   .= ' AND status = %s';
			$vals[] = $st;
		}
		if ( NWC::valid_date( (string) $r->get_param( 'on' ) ) ) {
			$sql   .= ' AND check_in <= %s AND check_out >= %s';
			$vals[] = $r->get_param( 'on' );
			$vals[] = $r->get_param( 'on' );
		}
		$q = trim( sanitize_text_field( (string) $r->get_param( 'q' ) ) );
		if ( '' !== $q ) {
			$sql   .= ' AND (guest_name LIKE %s OR phone LIKE %s)';
			$vals[] = '%' . $wpdb->esc_like( $q ) . '%';
			$vals[] = '%' . $wpdb->esc_like( $q ) . '%';
		}
		return array( 'items' => array_map( array( __CLASS__, 'out' ), $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . NWC_DB::t( 'stays' ) . " WHERE $sql ORDER BY check_in DESC, id DESC LIMIT 300", $vals ) ) ) ); // phpcs:ignore
	}

	public static function show( WP_REST_Request $r ) {
		$g = self::guard();
		if ( is_wp_error( $g ) ) {
			return $g;
		}
		$s = self::row( (int) $r['id'] );
		return $s ? self::out( $s ) : NWC::err( 'Reservation not found.', 404, 'nwc_not_found' );
	}

	/** Room × dates grid for the availability calendar. */
	public static function grid( WP_REST_Request $r ) {
		global $wpdb;
		$g = self::guard();
		if ( is_wp_error( $g ) ) {
			return $g;
		}
		$ws   = (int) NWC_Tenancy::$current->id;
		$from = NWC::valid_date( (string) $r->get_param( 'from' ) ) ? $r->get_param( 'from' ) : NWC::today( NWC_Tenancy::$current->timezone );
		$days = min( 31, max( 1, (int) ( $r->get_param( 'days' ) ?: 14 ) ) );
		$to   = gmdate( 'Y-m-d', strtotime( $from . ' 12:00:00 UTC' ) + $days * DAY_IN_SECONDS );
		$rooms = $wpdb->get_results( $wpdb->prepare( 'SELECT id, code, title, parent_id, status FROM ' . NWC_DB::t( 'biz_records' ) . " WHERE workspace_id = %d AND entity = 'room' AND deleted_at IS NULL ORDER BY code ASC", $ws ) ); // phpcs:ignore
		$stays = $wpdb->get_results( $wpdb->prepare( 'SELECT id, room_id, guest_name, check_in, check_out, status FROM ' . NWC_DB::t( 'stays' ) . " WHERE workspace_id = %d AND status IN ('reserved','checked_in','checked_out') AND check_in < %s AND check_out > %s", $ws, $to, $from ) ); // phpcs:ignore
		return array( 'from' => $from, 'days' => $days, 'rooms' => $rooms, 'stays' => $stays );
	}

	/** Body: room_id or room_type_id, check_in, check_out, guest_name, phone, email, adults, children, source, deposit, nightly_rate (override), package_id. */
	public static function create( WP_REST_Request $r ) {
		global $wpdb;
		$g = self::guard( true );
		if ( is_wp_error( $g ) ) {
			return $g;
		}
		$b  = NWC_REST::body( $r );
		$ws = (int) NWC_Tenancy::$current->id;
		$d  = self::dates( $b );
		if ( is_wp_error( $d ) ) {
			return $d;
		}
		list( $in, $out ) = $d;
		$name = mb_substr( trim( sanitize_text_field( (string) ( $b['guest_name'] ?? '' ) ) ), 0, 190 );
		if ( '' === $name ) {
			return NWC::err( 'Enter the guest\'s name.' );
		}
		$adults = max( 1, absint( $b['adults'] ?? 1 ) );
		$kids   = absint( $b['children'] ?? 0 );
		if ( ! NWC::lock( 'stay:' . $ws ) ) {
			return NWC::err( 'Busy right now. Please try again.', 503, 'nwc_busy' );
		}
		try {
			$room_id = absint( $b['room_id'] ?? 0 );
			if ( $room_id ) {
				$room = NWC_Industry::row( $room_id, $ws );
				if ( ! $room || 'room' !== $room->entity ) {
					return NWC::err( 'Room not found.', 404, 'nwc_not_found' );
				}
				$free = wp_list_pluck( self::free_rooms( $ws, $in, $out ), 'id' );
				if ( ! in_array( (string) $room_id, array_map( 'strval', $free ), true ) ) {
					return NWC::err( 'Room ' . $room->code . ' is not free for every night of this stay.', 409, 'nwc_room_taken' );
				}
			} else {
				$free = self::free_rooms( $ws, $in, $out, absint( $b['room_type_id'] ?? 0 ) );
				if ( ! $free ) {
					return NWC::err( 'No room of this type is free for the whole stay.', 409, 'nwc_room_taken' );
				}
				$room = $free[0];
			}
			$type = NWC_Industry::row( (int) $room->parent_id, $ws );
			if ( $type && (int) $type->qty > 0 && $adults + $kids > (int) $type->qty ) {
				return NWC::err( 'This room type takes up to ' . (int) $type->qty . ' guests.', 409, 'nwc_conflict' );
			}
			$nights = self::price( $ws, (int) $room->parent_id, $in, $out );
			$over   = isset( $b['nightly_rate'] ) && '' !== $b['nightly_rate'] ? round( max( 0, (float) $b['nightly_rate'] ), 2 ) : null;
			$cid    = NWC_CRM::upsert_customer( $ws, array( 'name' => $name, 'phone' => (string) ( $b['phone'] ?? '' ), 'email' => (string) ( $b['email'] ?? '' ), 'source' => 'stay' ) );
			$charges = array();
			$pkg = absint( $b['package_id'] ?? 0 ) ? NWC_Industry::row( absint( $b['package_id'] ), $ws ) : null;
			if ( $pkg && 'stay_package' === $pkg->entity && (float) $pkg->amount > 0 ) {
				$charges[] = array( 'description' => $pkg->title . ' (' . count( $nights ) . ' nights)', 'amount' => round( (float) $pkg->amount * count( $nights ), 2 ), 'on' => $in );
			}
			$now = NWC::now();
			$wpdb->insert( NWC_DB::t( 'stays' ), array(
				'workspace_id' => $ws, 'room_id' => (int) $room->id, 'room_type_id' => (int) $room->parent_id, 'customer_id' => $cid, 'guest_name' => $name,
				'phone' => mb_substr( sanitize_text_field( (string) ( $b['phone'] ?? '' ) ), 0, 40 ), 'email' => sanitize_email( (string) ( $b['email'] ?? '' ) ),
				'check_in' => $in, 'check_out' => $out, 'adults' => $adults, 'children' => $kids, 'source' => mb_substr( sanitize_text_field( (string) ( $b['source'] ?? 'direct' ) ), 0, 40 ),
				'status' => 'reserved', 'nightly_rate' => null === $over ? ( $nights ? round( array_sum( wp_list_pluck( $nights, 'rate' ) ) / count( $nights ), 2 ) : 0 ) : $over,
				'deposit' => round( max( 0, (float) ( $b['deposit'] ?? 0 ) ), 2 ), 'charges' => wp_json_encode( $charges ), 'notes' => sanitize_textarea_field( (string) ( $b['notes'] ?? '' ) ),
				'created_by' => get_current_user_id(), 'created_at' => $now, 'updated_at' => $now,
			) );
			$id = (int) $wpdb->insert_id;
			self::retotal( $id, $ws, null === $over ? $nights : null );
		} finally {
			NWC::unlock( 'stay:' . $ws );
		}
		NWC::audit( 'stay_created', array( 'entity' => 'stay', 'entity_id' => $id, 'details' => array( 'room' => $room->code, 'in' => $in, 'out' => $out ) ) );
		NWC_Notify::workspace_owners( $ws, 'operations', 'New reservation: ' . $name, $in . ' to ' . $out . ' · Room ' . $room->code, '#/m/stays?open=' . $id, 'stay:' . $id );
		return self::out( self::row( $id, $ws ) );
	}

	private static function retotal( $id, $ws, $nights = null ) {
		global $wpdb;
		$s     = self::row( $id, $ws );
		$rooms = null === $nights ? (float) $s->nightly_rate * self::nights( $s->check_in, $s->check_out ) : array_sum( wp_list_pluck( $nights, 'rate' ) );
		$extra = 0;
		foreach ( (array) json_decode( (string) $s->charges, true ) as $c ) {
			$extra += (float) $c['amount'];
		}
		$wpdb->update( NWC_DB::t( 'stays' ), array( 'total' => round( $rooms + $extra, 2 ), 'updated_at' => NWC::now() ), array( 'id' => $id ) );
	}

	/** Dates or room change (a room transfer). Rechecked against every night of the new stay. */
	public static function update( WP_REST_Request $r ) {
		global $wpdb;
		$g = self::guard( true );
		if ( is_wp_error( $g ) ) {
			return $g;
		}
		$s = self::row( (int) $r['id'] );
		if ( ! $s || ! in_array( $s->status, self::LIVE, true ) ) {
			return NWC::err( 'Only a current reservation can be changed.', 409, 'nwc_conflict' );
		}
		$b  = NWC_REST::body( $r );
		$ws = (int) $s->workspace_id;
		$d  = self::dates( $b, $s );
		if ( is_wp_error( $d ) ) {
			return $d;
		}
		if ( 'checked_in' === $s->status && $d[0] !== $s->check_in ) {
			return NWC::err( 'The guest has checked in: the arrival date cannot change.', 409, 'nwc_conflict' );
		}
		if ( ! NWC::lock( 'stay:' . $ws ) ) {
			return NWC::err( 'Busy right now. Please try again.', 503, 'nwc_busy' );
		}
		try {
			$room_id = absint( $b['room_id'] ?? $s->room_id );
			$free    = array_map( 'intval', wp_list_pluck( self::free_rooms( $ws, $d[0], $d[1], 0, (int) $s->id ), 'id' ) );
			if ( ! in_array( $room_id, $free, true ) ) {
				return NWC::err( 'That room is not free for every night of this stay.', 409, 'nwc_room_taken' );
			}
			$room = NWC_Industry::row( $room_id, $ws );
			$row  = array( 'check_in' => $d[0], 'check_out' => $d[1], 'room_id' => $room_id, 'room_type_id' => (int) $room->parent_id, 'updated_at' => NWC::now() );
			foreach ( array( 'guest_name' => 190, 'phone' => 40, 'source' => 40 ) as $k => $len ) {
				if ( isset( $b[ $k ] ) ) {
					$row[ $k ] = mb_substr( sanitize_text_field( (string) $b[ $k ] ), 0, $len );
				}
			}
			if ( isset( $b['notes'] ) ) {
				$row['notes'] = sanitize_textarea_field( (string) $b['notes'] );
			}
			if ( isset( $b['deposit'] ) ) {
				$row['deposit'] = round( max( 0, (float) $b['deposit'] ), 2 );
			}
			if ( isset( $b['nightly_rate'] ) ) {
				$row['nightly_rate'] = round( max( 0, (float) $b['nightly_rate'] ), 2 );
			}
			$wpdb->update( NWC_DB::t( 'stays' ), $row, array( 'id' => $s->id ) );
			self::retotal( (int) $s->id, $ws );
		} finally {
			NWC::unlock( 'stay:' . $ws );
		}
		if ( $room_id !== (int) $s->room_id ) {
			NWC::audit( 'stay_room_transfer', array( 'entity' => 'stay', 'entity_id' => $s->id, 'details' => array( 'from' => (int) $s->room_id, 'to' => $room_id ) ) );
			if ( 'checked_in' === $s->status ) {
				$wpdb->update( NWC_DB::t( 'biz_records' ), array( 'status' => 'dirty', 'updated_at' => NWC::now() ), array( 'id' => $s->room_id, 'workspace_id' => $ws ) );
			}
		}
		return self::out( self::row( $s->id, $ws ) );
	}

	public static function check_in( WP_REST_Request $r ) {
		global $wpdb;
		$g = self::guard( true );
		if ( is_wp_error( $g ) ) {
			return $g;
		}
		$s = self::row( (int) $r['id'] );
		if ( ! $s || 'reserved' !== $s->status ) {
			return NWC::err( 'Only a reserved stay can check in.', 409, 'nwc_bad_transition' );
		}
		$today = NWC::today( NWC_Tenancy::$current->timezone );
		if ( $s->check_in > $today ) {
			return NWC::err( 'This reservation starts on ' . $s->check_in . '. Change the dates to check in early.', 409, 'nwc_conflict' );
		}
		$room = NWC_Industry::row( (int) $s->room_id, $s->workspace_id );
		if ( $room && in_array( $room->status, array( 'maintenance', 'out_of_order' ), true ) ) {
			return NWC::err( 'Room ' . $room->code . ' is out of service. Transfer the guest first.', 409, 'nwc_conflict' );
		}
		$wpdb->update( NWC_DB::t( 'stays' ), array( 'status' => 'checked_in', 'checked_in_at' => NWC::now(), 'updated_at' => NWC::now() ), array( 'id' => $s->id ) );
		NWC::audit( 'stay_checked_in', array( 'entity' => 'stay', 'entity_id' => $s->id ) );
		return self::out( self::row( $s->id ) );
	}

	public static function add_charge( WP_REST_Request $r ) {
		global $wpdb;
		$g = self::guard( true );
		if ( is_wp_error( $g ) ) {
			return $g;
		}
		$s = self::row( (int) $r['id'] );
		if ( ! $s || 'checked_in' !== $s->status ) {
			return NWC::err( 'Charges can be added while the guest is checked in.', 409, 'nwc_conflict' );
		}
		$b    = NWC_REST::body( $r );
		$desc = mb_substr( trim( sanitize_text_field( (string) ( $b['description'] ?? '' ) ) ), 0, 190 );
		$amt  = round( (float) ( $b['amount'] ?? 0 ), 2 );
		if ( '' === $desc || $amt <= 0 ) {
			return NWC::err( 'Enter what the charge is for and an amount.' );
		}
		$c   = (array) json_decode( (string) $s->charges, true );
		$c[] = array( 'description' => $desc, 'amount' => $amt, 'on' => NWC::today( NWC_Tenancy::$current->timezone ), 'tax_rate' => max( 0, min( 28, (float) ( $b['tax_rate'] ?? 0 ) ) ) );
		$wpdb->update( NWC_DB::t( 'stays' ), array( 'charges' => wp_json_encode( $c ) ), array( 'id' => $s->id ) );
		self::retotal( (int) $s->id, (int) $s->workspace_id );
		return self::out( self::row( $s->id ) );
	}

	/**
	 * Final bill: room nights, extras and guest-request charges on one GST invoice; the deposit is
	 * recorded as the first payment. Body may hold payments [{method, amount}] and room_tax_rate.
	 */
	public static function check_out( WP_REST_Request $r ) {
		global $wpdb;
		$g = self::guard( true );
		if ( is_wp_error( $g ) ) {
			return $g;
		}
		$s = self::row( (int) $r['id'] );
		if ( ! $s || 'checked_in' !== $s->status ) {
			return NWC::err( 'Only a checked-in guest can check out.', 409, 'nwc_bad_transition' );
		}
		$ws = (int) $s->workspace_id;
		$b  = NWC_REST::body( $r );
		if ( ! NWC::lock( 'stayout:' . $s->id ) ) {
			return NWC::err( 'Busy right now. Please try again.', 503, 'nwc_busy' );
		}
		try {
			$s = self::row( $s->id, $ws );
			if ( 'checked_in' !== $s->status ) {
				return NWC::err( 'Already checked out.', 409, 'nwc_conflict' );
			}
			$rate  = isset( $b['room_tax_rate'] ) && '' !== $b['room_tax_rate'] ? max( 0, min( 28, (float) $b['room_tax_rate'] ) ) : self::room_rate_for( $ws, (float) $s->nightly_rate ); // Tariff slab unless staff set one.
			$n     = self::nights( $s->check_in, $s->check_out );
			$room  = NWC_Industry::row( (int) $s->room_id, $ws );
			$items = array( array( 'description' => 'Room ' . ( $room ? $room->code : '' ) . ', ' . $n . ' night' . ( 1 === $n ? '' : 's' ) . ' (' . $s->check_in . ' to ' . $s->check_out . ')', 'qty' => $n, 'unit_price' => (float) $s->nightly_rate, 'tax_rate' => $rate, 'hsn' => '9963' ) );
			foreach ( (array) json_decode( (string) $s->charges, true ) as $c ) {
				$items[] = array( 'description' => $c['description'], 'qty' => 1, 'unit_price' => (float) $c['amount'], 'tax_rate' => (float) ( $c['tax_rate'] ?? 0 ) );
			}
			foreach ( $wpdb->get_results( $wpdb->prepare( 'SELECT id, title, amount FROM ' . NWC_DB::t( 'biz_records' ) . " WHERE workspace_id = %d AND entity = 'guest_request' AND ref_id = %d AND amount > 0 AND deleted_at IS NULL", $ws, $s->id ) ) as $gr ) { // phpcs:ignore
				$items[] = array( 'description' => $gr->title, 'qty' => 1, 'unit_price' => (float) $gr->amount, 'tax_rate' => 0 );
			}
			// Payments are checked against the bill first; then the invoice, payments and status change are one transaction.
			$biz  = NWC_Bookings::business( $ws );
			$calc = NWC_Invoices::compute( $items, $biz, (string) $biz->state_code );
			if ( is_wp_error( $calc ) ) {
				return $calc;
			}
			$due  = (float) $calc['totals']['grand_total'];
			$pays = array_slice( (array) ( $b['payments'] ?? array() ), 0, 4 );
			$sum  = min( (float) $s->deposit, $due ) + array_sum( array_map( function ( $p ) {
				return max( 0, (float) ( $p['amount'] ?? 0 ) );
			}, $pays ) );
			if ( $sum - $due > 0.001 ) {
				return NWC::err( 'The payments (' . NWC_Invoices::money( $sum ) . ' with the deposit) are more than the bill of ' . NWC_Invoices::money( $due ) . '.', 409, 'nwc_overpayment', array( 'total' => $due ) );
			}
			$v = NWC::tx( function () use ( $wpdb, $s, $ws, $items, $pays ) {
				$inv = NWC_Invoices::create_doc( array( 'customer_id' => (int) $s->customer_id, 'items' => $items, 'notes' => 'Stay #' . $s->id . ' · ' . $s->guest_name, 'terms' => '', 'bank_details' => '' ) );
				if ( is_wp_error( $inv ) ) {
					return $inv;
				}
				$wpdb->update( NWC_DB::t( 'biz_invoices' ), array( 'status' => 'sent', 'sent_at' => NWC::now() ), array( 'id' => $inv ) );
				$v = NWC_Invoices::row( $inv, $ws );
				if ( (float) $s->deposit > 0 ) {
					$dep = NWC_Invoices::apply_payment( $v, array( 'amount' => min( (float) $s->deposit, (float) $v->grand_total ), 'method' => 'other', 'reference' => 'Deposit', 'idempotency_key' => 'stay-dep-' . $s->id ) );
					if ( is_wp_error( $dep ) ) {
						return $dep;
					}
				}
				foreach ( $pays as $i => $p ) {
					if ( (float) ( $p['amount'] ?? 0 ) <= 0 ) {
						continue;
					}
					$v   = NWC_Invoices::row( $inv, $ws );
					$res = NWC_Invoices::apply_payment( $v, array( 'amount' => (float) $p['amount'], 'method' => in_array( $p['method'] ?? '', array( 'cash', 'upi', 'card', 'bank', 'other' ), true ) ? $p['method'] : 'other', 'reference' => (string) ( $p['reference'] ?? '' ), 'idempotency_key' => 'stay-' . $s->id . '-' . $i ) );
					if ( is_wp_error( $res ) ) {
						return $res;
					}
				}
				$v   = NWC_Invoices::row( $inv, $ws );
				$now = NWC::now();
				$wpdb->update( NWC_DB::t( 'stays' ), array( 'status' => 'checked_out', 'checked_out_at' => $now, 'invoice_id' => $inv, 'total' => (float) $v->grand_total, 'updated_at' => $now ), array( 'id' => $s->id ) );
				$wpdb->update( NWC_DB::t( 'biz_records' ), array( 'status' => 'dirty', 'updated_at' => $now ), array( 'id' => $s->room_id, 'workspace_id' => $ws ) );
				$wpdb->insert( NWC_DB::t( 'biz_records' ), array( 'workspace_id' => $ws, 'module' => 'housekeeping', 'entity' => 'housekeeping', 'title' => 'Clean after check-out', 'status' => 'open', 'parent_id' => (int) $s->room_id, 'on_date' => NWC::today( NWC_Tenancy::$current->timezone ), 'data' => '{}', 'created_by' => get_current_user_id(), 'created_at' => $now, 'updated_at' => $now ) );
				return $v;
			} );
			if ( is_wp_error( $v ) ) {
				return $v;
			}
			$inv = (int) $v->id;
		} finally {
			NWC::unlock( 'stayout:' . $s->id );
		}
		NWC::audit( 'stay_checked_out', array( 'entity' => 'stay', 'entity_id' => $s->id, 'details' => array( 'invoice' => $inv, 'total' => (float) $v->grand_total, 'paid' => (float) $v->amount_paid ) ) );
		return array_merge( (array) self::out( self::row( $s->id, $ws ) ), array( 'invoice' => array( 'id' => (int) $v->id, 'doc_no' => $v->doc_no, 'total' => (float) $v->grand_total, 'paid' => (float) $v->amount_paid, 'balance' => round( (float) $v->grand_total - (float) $v->amount_paid, 2 ) ) ) );
	}

	public static function cancel( WP_REST_Request $r ) {
		global $wpdb;
		$g = self::guard( true );
		if ( is_wp_error( $g ) ) {
			return $g;
		}
		$s = self::row( (int) $r['id'] );
		if ( ! $s || 'reserved' !== $s->status ) {
			return NWC::err( 'Only a reservation that has not checked in can be cancelled.', 409, 'nwc_bad_transition' );
		}
		$to = ! empty( NWC_REST::body( $r )['no_show'] ) ? 'no_show' : 'cancelled';
		$wpdb->update( NWC_DB::t( 'stays' ), array( 'status' => $to, 'updated_at' => NWC::now() ), array( 'id' => $s->id ) );
		NWC::audit( 'stay_' . $to, array( 'entity' => 'stay', 'entity_id' => $s->id, 'details' => array( 'deposit' => (float) $s->deposit ) ) );
		return self::out( self::row( $s->id ) );
	}
}

class NWC_Checkins {

	public static function routes() {
		NWC_REST::route( '/checkins', 'GET', array( __CLASS__, 'index' ) );
		NWC_REST::route( '/checkins', 'POST', array( __CLASS__, 'create' ) );
		NWC_REST::route( '/checkins/(?P<id>\d+)/out', 'POST', array( __CLASS__, 'out' ) );
	}

	public static function index( WP_REST_Request $r ) {
		global $wpdb;
		$g = NWC_Industry::guard_module( 'checkin', 'read' );
		if ( is_wp_error( $g ) ) {
			return $g;
		}
		$w   = NWC_Tenancy::$current;
		$day = NWC::valid_date( (string) $r->get_param( 'date' ) ) ? $r->get_param( 'date' ) : NWC::today( $w->timezone );
		$tz  = new DateTimeZone( $w->timezone );
		$d0  = ( new DateTimeImmutable( $day . ' 00:00:00', $tz ) )->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );
		$d1  = ( new DateTimeImmutable( $day . ' 23:59:59', $tz ) )->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );
		$C   = NWC_DB::t( 'checkins' );
		$R   = NWC_DB::t( 'biz_records' );
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT c.*, r.title AS member, r.code FROM $C c LEFT JOIN $R r ON r.id = c.record_id WHERE c.workspace_id = %d AND c.in_at BETWEEN %s AND %s ORDER BY c.id DESC LIMIT 500", $w->id, $d0, $d1 ) ); // phpcs:ignore
		$live = count( array_filter( $rows, function ( $x ) {
			return ! $x->out_at;
		} ) );
		return array( 'date' => $day, 'items' => $rows, 'live' => $live, 'total' => count( $rows ) );
	}

	/** Body: code (from the member card or QR) or record_id, branch. One open check-in per member. */
	public static function create( WP_REST_Request $r ) {
		global $wpdb;
		$g = NWC_Industry::guard_module( 'checkin', 'write' );
		if ( is_wp_error( $g ) ) {
			return $g;
		}
		$w    = NWC_Tenancy::$current;
		$b    = NWC_REST::body( $r );
		$R    = NWC_DB::t( 'biz_records' );
		$code = strtoupper( trim( sanitize_text_field( (string) ( $b['code'] ?? '' ) ) ) );
		$m    = '' !== $code ? $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $R WHERE workspace_id = %d AND entity = 'membership' AND UPPER(code) = %s AND deleted_at IS NULL", $w->id, $code ) ) : NWC_Industry::row( absint( $b['record_id'] ?? 0 ) ); // phpcs:ignore
		if ( ! $m || 'membership' !== $m->entity ) {
			return NWC::err( 'No member with that code.', 404, 'nwc_not_found' );
		}
		$state = NWC_Industry::membership_state( $m );
		if ( ! in_array( $state, array( 'active', 'expiring' ), true ) ) {
			return NWC::err( $m->title . '\'s membership is ' . $state . '. Renew it before check-in.', 409, 'nwc_membership_inactive', array( 'state' => $state ) );
		}
		$today = NWC::today( $w->timezone );
		if ( $m->on_date && $m->on_date > $today ) {
			return NWC::err( $m->title . '\'s membership starts on ' . $m->on_date . '.', 409, 'nwc_membership_inactive' );
		}
		$d0   = ( new DateTimeImmutable( $today . ' 00:00:00', new DateTimeZone( $w->timezone ) ) )->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );
		if ( ! NWC::lock( 'chk:' . $m->id ) ) {
			return NWC::err( 'Busy right now. Please try again.', 503, 'nwc_busy' );
		}
		try {
			$open = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . NWC_DB::t( 'checkins' ) . ' WHERE workspace_id = %d AND record_id = %d AND out_at IS NULL AND in_at >= %s', $w->id, $m->id, $d0 ) ); // phpcs:ignore
			if ( $open ) {
				return array( 'checkin' => $open, 'member' => $m->title, 'state' => $state, 'already' => true );
			}
			$wpdb->insert( NWC_DB::t( 'checkins' ), array( 'workspace_id' => (int) $w->id, 'record_id' => (int) $m->id, 'customer_id' => $m->customer_id, 'branch' => mb_substr( sanitize_text_field( (string) ( $b['branch'] ?? '' ) ), 0, 120 ), 'method' => '' !== $code ? 'code' : 'manual', 'in_at' => NWC::now(), 'created_by' => get_current_user_id() ) );
			$id = (int) $wpdb->insert_id;
		} finally {
			NWC::unlock( 'chk:' . $m->id );
		}
		return array( 'checkin' => $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . NWC_DB::t( 'checkins' ) . ' WHERE id = %d', $id ) ), 'member' => $m->title, 'state' => $state, 'ends' => $m->end_date, 'already' => false ); // phpcs:ignore
	}

	public static function out( WP_REST_Request $r ) {
		global $wpdb;
		$g = NWC_Industry::guard_module( 'checkin', 'write' );
		if ( is_wp_error( $g ) ) {
			return $g;
		}
		$n = $wpdb->query( $wpdb->prepare( 'UPDATE ' . NWC_DB::t( 'checkins' ) . ' SET out_at = %s WHERE id = %d AND workspace_id = %d AND out_at IS NULL', NWC::now(), (int) $r['id'], NWC_Tenancy::$current->id ) ); // phpcs:ignore
		return $n ? array( 'ok' => true ) : NWC::err( 'Check-in not found or already closed.', 404, 'nwc_not_found' );
	}
}

class NWC_Attendance {

	public static function routes() {
		NWC_REST::route( '/attendance', 'GET', array( __CLASS__, 'sheet' ) );
		NWC_REST::route( '/attendance', 'PUT', array( __CLASS__, 'save' ) );
		NWC_REST::route( '/attendance/student/(?P<id>\d+)', 'GET', array( __CLASS__, 'history' ) );
	}

	/** Students on a batch for one session date, with their mark. */
	public static function sheet( WP_REST_Request $r ) {
		global $wpdb;
		$g = NWC_Industry::guard_module( 'attendance', 'read' );
		if ( is_wp_error( $g ) ) {
			return $g;
		}
		$w     = NWC_Tenancy::$current;
		$batch = NWC_Industry::row( absint( $r->get_param( 'batch_id' ) ) );
		$day   = NWC::valid_date( (string) $r->get_param( 'date' ) ) ? $r->get_param( 'date' ) : NWC::today( $w->timezone );
		if ( ! $batch || 'batch' !== $batch->entity ) {
			return NWC::err( 'Choose a batch.', 404, 'nwc_not_found' );
		}
		$R     = NWC_DB::t( 'biz_records' );
		$marks = array();
		foreach ( $wpdb->get_results( $wpdb->prepare( 'SELECT student_id, status, note FROM ' . NWC_DB::t( 'attendance' ) . ' WHERE batch_id = %d AND session_date = %s', $batch->id, $day ) ) as $a ) { // phpcs:ignore
			$marks[ (int) $a->student_id ] = $a;
		}
		$students = $wpdb->get_results( $wpdb->prepare( "SELECT s.id, s.title, s.code FROM $R e JOIN $R s ON s.id = e.parent_id WHERE e.workspace_id = %d AND e.entity = 'enrolment' AND e.ref_id = %d AND e.status = 'active' AND e.deleted_at IS NULL AND s.deleted_at IS NULL ORDER BY s.title ASC", $w->id, $batch->id ) ); // phpcs:ignore
		$sub = $wpdb->get_row( $wpdb->prepare( "SELECT staff_id FROM $R WHERE workspace_id = %d AND entity = 'substitution' AND parent_id = %d AND on_date = %s AND deleted_at IS NULL", $w->id, $batch->id, $day ) ); // phpcs:ignore
		$day_key = NWC_Bookings::DAYS[ (int) gmdate( 'N', strtotime( $day . ' 12:00:00 UTC' ) ) - 1 ];
		$bd      = json_decode( (string) $batch->data, true );
		return array(
			'batch' => array( 'id' => (int) $batch->id, 'title' => $batch->title, 'instructor' => (int) ( $sub ? $sub->staff_id : $batch->staff_id ), 'substitute' => (bool) $sub, 'meets_today' => in_array( $day_key, (array) ( $bd['weekdays'] ?? array() ), true ) ),
			'date'  => $day,
			'students' => array_map( function ( $s ) use ( $marks ) {
				$m = $marks[ (int) $s->id ] ?? null;
				return array( 'id' => (int) $s->id, 'name' => $s->title, 'code' => $s->code, 'status' => $m ? $m->status : '', 'note' => $m ? $m->note : '' );
			}, $students ),
		);
	}

	/** Body: batch_id, date, marks: [{student_id, status, note}]. Re-saving updates, never duplicates. */
	public static function save( WP_REST_Request $r ) {
		global $wpdb;
		$g = NWC_Industry::guard_module( 'attendance', 'write' );
		if ( is_wp_error( $g ) ) {
			return $g;
		}
		$b     = NWC_REST::body( $r );
		$w     = NWC_Tenancy::$current;
		$batch = NWC_Industry::row( absint( $b['batch_id'] ?? 0 ) );
		$day   = (string) ( $b['date'] ?? '' );
		if ( ! $batch || 'batch' !== $batch->entity || ! NWC::valid_date( $day ) ) {
			return NWC::err( 'Choose a batch and a date.' );
		}
		if ( $day > NWC::today( $w->timezone ) ) {
			return NWC::err( 'Attendance cannot be marked for a future date.' );
		}
		$enrolled = array_map( 'intval', $wpdb->get_col( $wpdb->prepare( 'SELECT parent_id FROM ' . NWC_DB::t( 'biz_records' ) . " WHERE workspace_id = %d AND entity = 'enrolment' AND ref_id = %d AND status = 'active' AND deleted_at IS NULL", $w->id, $batch->id ) ) ); // phpcs:ignore
		$T   = NWC_DB::t( 'attendance' );
		$now = NWC::now();
		$n   = 0;
		foreach ( array_slice( (array) ( $b['marks'] ?? array() ), 0, 300 ) as $m ) {
			$sid = absint( $m['student_id'] ?? 0 );
			$st  = in_array( $m['status'] ?? '', array( 'present', 'absent', 'late', 'excused' ), true ) ? $m['status'] : '';
			if ( ! in_array( $sid, $enrolled, true ) || '' === $st ) {
				continue;
			}
			$note = mb_substr( sanitize_text_field( (string) ( $m['note'] ?? '' ) ), 0, 255 );
			$old  = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM $T WHERE batch_id = %d AND session_date = %s AND student_id = %d", $batch->id, $day, $sid ) ); // phpcs:ignore
			if ( $old ) {
				$wpdb->update( $T, array( 'status' => $st, 'note' => $note, 'marked_by' => get_current_user_id(), 'updated_at' => $now ), array( 'id' => $old ) );
			} else {
				$wpdb->insert( $T, array( 'workspace_id' => (int) $w->id, 'batch_id' => (int) $batch->id, 'session_date' => $day, 'student_id' => $sid, 'status' => $st, 'note' => $note, 'marked_by' => get_current_user_id(), 'created_at' => $now, 'updated_at' => $now ) );
			}
			$n++;
		}
		NWC::audit( 'attendance_marked', array( 'entity' => 'batch', 'entity_id' => $batch->id, 'details' => array( 'date' => $day, 'marks' => $n ) ) );
		$r2 = new WP_REST_Request( 'GET' );
		$r2->set_param( 'batch_id', $batch->id );
		$r2->set_param( 'date', $day );
		return array_merge( self::sheet( $r2 ), array( 'saved' => $n ) );
	}

	public static function history( WP_REST_Request $r ) {
		global $wpdb;
		$g = NWC_Industry::guard_module( 'attendance', 'read' );
		if ( is_wp_error( $g ) ) {
			return $g;
		}
		$s = NWC_Industry::row( (int) $r['id'] );
		if ( ! $s || 'student' !== $s->entity ) {
			return NWC::err( 'Student not found.', 404, 'nwc_not_found' );
		}
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT a.session_date, a.status, a.note, b.title AS batch FROM ' . NWC_DB::t( 'attendance' ) . ' a LEFT JOIN ' . NWC_DB::t( 'biz_records' ) . ' b ON b.id = a.batch_id WHERE a.workspace_id = %d AND a.student_id = %d ORDER BY a.session_date DESC LIMIT 200', NWC_Tenancy::$current->id, $s->id ) ); // phpcs:ignore
		$present = count( array_filter( $rows, function ( $x ) {
			return in_array( $x->status, array( 'present', 'late' ), true );
		} ) );
		return array( 'student' => $s->title, 'items' => $rows, 'rate' => $rows ? round( 100 * $present / count( $rows ) ) : null );
	}
}

/**
 * Staff pay from what was actually done in a period, in the Workspace's timezone:
 *   salon commissions: revenue of completed appointments (service price × people) and finished
 *   walk-ins, per stylist, times that person's commission rule;
 *   trainer pay: completed sessions × pay per session, plus a share of the fees actually paid by
 *   members assigned to the trainer who joined in the period, plus a fixed amount.
 * Only rules and records the business entered are used; nothing is estimated.
 */
class NWC_Payouts {

	public static function routes() {
		NWC_REST::route( '/industry/commissions', 'GET', array( __CLASS__, 'commissions' ) );
		NWC_REST::route( '/industry/trainer-pay', 'GET', array( __CLASS__, 'trainer_pay' ) );
		NWC_REST::route( '/industry/trainer-pay', 'POST', array( __CLASS__, 'trainer_pay_record' ) );
	}

	private static function period( WP_REST_Request $r ) {
		$tz   = NWC_Tenancy::$current->timezone;
		$to   = NWC::valid_date( (string) $r->get_param( 'to' ) ) ? $r->get_param( 'to' ) : NWC::today( $tz );
		$from = NWC::valid_date( (string) $r->get_param( 'from' ) ) ? $r->get_param( 'from' ) : substr( $to, 0, 8 ) . '01';
		if ( $from > $to ) {
			return NWC::err( 'The start date is after the end date.' );
		}
		$z = new DateTimeZone( $tz );
		return array( $from, $to, ( new DateTimeImmutable( $from . ' 00:00:00', $z ) )->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' ), ( new DateTimeImmutable( $to . ' 23:59:59', $z ) )->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' ) );
	}

	private static function gate( $module ) {
		$g = NWC_Industry::guard_module( $module, 'read' );
		if ( is_wp_error( $g ) ) {
			return $g;
		}
		return NWC_Tenancy::can( 'reports.read' ) || NWC_Tenancy::can( 'finance.read' ) ? true : NWC::err( 'Your role does not allow this.', 403, 'nwc_forbidden' );
	}

	private static function name( $uid ) {
		$u = $uid ? get_userdata( (int) $uid ) : null;
		return $u ? $u->display_name : 'Unassigned';
	}

	private static function blank() {
		return array( 'appointments' => 0, 'appointment_revenue' => 0.0, 'walkins' => 0, 'walkin_revenue' => 0.0 );
	}

	public static function commission_rows( $ws, $d0, $d1, $from, $to ) {
		global $wpdb;
		$R     = NWC_DB::t( 'biz_records' );
		$rules = array();
		foreach ( $wpdb->get_results( $wpdb->prepare( "SELECT staff_id, qty FROM $R WHERE workspace_id = %d AND entity = 'commission_rule' AND deleted_at IS NULL", $ws ) ) as $x ) { // phpcs:ignore
			$rules[ (int) $x->staff_id ] = (float) $x->qty;
		}
		$by = array();
		foreach ( $wpdb->get_results( $wpdb->prepare( 'SELECT b.staff_id, s.price, b.party_size FROM ' . NWC_DB::t( 'bookings' ) . ' b JOIN ' . NWC_DB::t( 'services' ) . " s ON s.id = b.service_id WHERE b.workspace_id = %d AND b.deleted_at IS NULL AND b.status = 'completed' AND b.staff_id IS NOT NULL AND b.starts_at BETWEEN %s AND %s", $ws, $d0, $d1 ) ) as $b ) { // phpcs:ignore
			$k = (int) $b->staff_id;
			$by[ $k ] = $by[ $k ] ?? self::blank();
			$by[ $k ]['appointments']++;
			$by[ $k ]['appointment_revenue'] += (float) $b->price * max( 1, (int) $b->party_size );
		}
		foreach ( $wpdb->get_results( $wpdb->prepare( "SELECT staff_id, amount FROM $R WHERE workspace_id = %d AND entity = 'walkin' AND status = 'done' AND deleted_at IS NULL AND staff_id IS NOT NULL AND on_date BETWEEN %s AND %s", $ws, $from, $to ) ) as $w ) { // phpcs:ignore
			$k = (int) $w->staff_id;
			$by[ $k ] = $by[ $k ] ?? self::blank();
			$by[ $k ]['walkins']++;
			$by[ $k ]['walkin_revenue'] += (float) $w->amount;
		}
		$out = array();
		foreach ( array_unique( array_merge( array_keys( $by ), array_keys( $rules ) ) ) as $k ) {
			$x     = $by[ $k ] ?? self::blank();
			$rev   = round( $x['appointment_revenue'] + $x['walkin_revenue'], 2 );
			$pct   = $rules[ $k ] ?? null;
			$out[] = array_merge( $x, array( 'staff_id' => $k, 'name' => self::name( $k ), 'revenue' => $rev, 'rate' => $pct, 'commission' => null === $pct ? null : round( $rev * $pct / 100, 2 ) ) );
		}
		usort( $out, function ( $a, $b ) {
			return $b['revenue'] <=> $a['revenue'];
		} );
		return $out;
	}

	public static function commissions( WP_REST_Request $r ) {
		$g = self::gate( 'salon_ops' );
		if ( is_wp_error( $g ) ) {
			return $g;
		}
		$p = self::period( $r );
		if ( is_wp_error( $p ) ) {
			return $p;
		}
		$rows = self::commission_rows( (int) NWC_Tenancy::$current->id, $p[2], $p[3], $p[0], $p[1] );
		return array(
			'from' => $p[0], 'to' => $p[1], 'items' => $rows,
			'total' => round( array_sum( array_map( function ( $x ) {
				return (float) $x['commission'];
			}, $rows ) ), 2 ),
			'missing_rules' => count( array_filter( $rows, function ( $x ) {
				return null === $x['rate'] && $x['revenue'] > 0;
			} ) ),
		);
	}

	public static function trainer_rows( $ws, $d0, $d1, $from, $to ) {
		global $wpdb;
		$R   = NWC_DB::t( 'biz_records' );
		$out = array();
		foreach ( $wpdb->get_results( $wpdb->prepare( "SELECT staff_id, amount, qty, data FROM $R WHERE workspace_id = %d AND entity = 'trainer_rate' AND deleted_at IS NULL", $ws ) ) as $rule ) { // phpcs:ignore
			$k        = (int) $rule->staff_id;
			$sessions = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . NWC_DB::t( 'bookings' ) . " WHERE workspace_id = %d AND deleted_at IS NULL AND status = 'completed' AND staff_id = %d AND starts_at BETWEEN %s AND %s", $ws, $k, $d0, $d1 ) ); // phpcs:ignore
			$fees     = 0.0;
			$members  = 0;
			foreach ( $wpdb->get_results( $wpdb->prepare( "SELECT amount, data FROM $R WHERE workspace_id = %d AND entity = 'membership' AND deleted_at IS NULL AND staff_id = %d AND on_date BETWEEN %s AND %s", $ws, $k, $from, $to ) ) as $m ) { // phpcs:ignore
				$md    = json_decode( (string) $m->data, true );
				$fees += min( (float) $m->amount, (float) ( $md['paid'] ?? 0 ) ); // Only what members actually paid.
				$members++;
			}
			$rd    = json_decode( (string) $rule->data, true );
			$fixed = (float) ( $rd['fixed'] ?? 0 );
			$share = round( $fees * (float) $rule->qty / 100, 2 );
			$out[] = array( 'staff_id' => $k, 'name' => self::name( $k ), 'sessions' => $sessions, 'per_session' => (float) $rule->amount, 'session_pay' => round( $sessions * (float) $rule->amount, 2 ), 'members' => $members, 'member_fees' => round( $fees, 2 ), 'share_pct' => (float) $rule->qty, 'share_pay' => $share, 'fixed' => $fixed, 'total' => round( $sessions * (float) $rule->amount + $share + $fixed, 2 ) );
		}
		return $out;
	}

	public static function trainer_pay( WP_REST_Request $r ) {
		$g = self::gate( 'training' );
		if ( is_wp_error( $g ) ) {
			return $g;
		}
		$p = self::period( $r );
		if ( is_wp_error( $p ) ) {
			return $p;
		}
		$rows = self::trainer_rows( (int) NWC_Tenancy::$current->id, $p[2], $p[3], $p[0], $p[1] );
		return array( 'from' => $p[0], 'to' => $p[1], 'items' => $rows, 'total' => round( array_sum( wp_list_pluck( $rows, 'total' ) ), 2 ) );
	}

	/** Creates one pending payout per trainer for the period. Running it again changes nothing. */
	public static function trainer_pay_record( WP_REST_Request $r ) {
		global $wpdb;
		$g = NWC_Industry::guard_module( 'training', 'write' );
		if ( is_wp_error( $g ) ) {
			return $g;
		}
		if ( ! NWC_Tenancy::can( 'finance.read' ) && ! NWC_Tenancy::is_manager() ) {
			return NWC::err( 'Your role does not allow this.', 403, 'nwc_forbidden' );
		}
		$b  = NWC_REST::body( $r );
		$rq = new WP_REST_Request( 'GET' );
		$rq->set_param( 'from', $b['from'] ?? '' );
		$rq->set_param( 'to', $b['to'] ?? '' );
		$p = self::period( $rq );
		if ( is_wp_error( $p ) ) {
			return $p;
		}
		$ws    = (int) NWC_Tenancy::$current->id;
		$title = $p[0] . ' to ' . $p[1];
		$made  = 0;
		$kept  = 0;
		if ( ! NWC::lock( 'payout:' . $ws ) ) {
			return NWC::err( 'Busy right now. Please try again.', 503, 'nwc_busy' );
		}
		try {
			foreach ( self::trainer_rows( $ws, $p[2], $p[3], $p[0], $p[1] ) as $x ) {
				$have = $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . NWC_DB::t( 'biz_records' ) . " WHERE workspace_id = %d AND entity = 'trainer_pay' AND staff_id = %d AND title = %s AND deleted_at IS NULL", $ws, $x['staff_id'], $title ) ); // phpcs:ignore
				if ( $have ) {
					$kept++;
					continue;
				}
				$now = NWC::now();
				$wpdb->insert( NWC_DB::t( 'biz_records' ), array( 'workspace_id' => $ws, 'module' => 'training', 'entity' => 'trainer_pay', 'title' => $title, 'status' => 'pending', 'staff_id' => $x['staff_id'], 'qty' => $x['sessions'], 'amount' => $x['total'], 'data' => wp_json_encode( array( 'calc' => $x ) ), 'created_by' => get_current_user_id(), 'created_at' => $now, 'updated_at' => $now ) );
				$made++;
			}
		} finally {
			NWC::unlock( 'payout:' . $ws );
		}
		NWC::audit( 'trainer_pay_recorded', array( 'entity' => 'trainer_pay', 'details' => array( 'period' => $title, 'created' => $made, 'already' => $kept ) ) );
		return array( 'created' => $made, 'already' => $kept, 'period' => $title );
	}
}
