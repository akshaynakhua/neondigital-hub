<?php
/**
 * Calendars: personal, business, project, agency and consolidated.
 *
 * Only real events live in the events table. Bookings, tasks and milestones appear through a
 * reference to their own record, so nothing is stored twice. Personal events and private notes
 * are private to their owner: they are excluded from every sync and from other users' feeds
 * (an explicit share shows the time and title only, never the private note).
 */

defined( 'ABSPATH' ) || exit;

class NWC_Calendar {

	const COLORS = array( 'personal' => '#7547DF', 'business' => '#4A3F66', 'project' => '#8A6A14', 'agency' => '#B4532A', 'booking' => '#7B4A78', 'task' => '#6D667A', 'milestone' => '#8A6A14' );

	public static function routes() {
		NWC_REST::route( '/calendar/feed', 'GET', array( __CLASS__, 'feed' ) );
		NWC_REST::route( '/calendar/list', 'GET', array( __CLASS__, 'calendars' ) );
		NWC_REST::route( '/calendar/conflicts', 'POST', array( __CLASS__, 'conflicts_route' ) );
		NWC_REST::route( '/calendar/events', 'POST', array( __CLASS__, 'create' ) );
		NWC_REST::route( '/calendar/events/(?P<id>\d+)', 'GET', array( __CLASS__, 'show' ) );
		NWC_REST::route( '/calendar/events/(?P<id>\d+)', 'PUT,PATCH', array( __CLASS__, 'update' ) );
		NWC_REST::route( '/calendar/events/(?P<id>\d+)', 'DELETE', array( __CLASS__, 'remove' ) );
		NWC_REST::route( '/calendar/shares', 'GET', array( __CLASS__, 'shares' ) );
		NWC_REST::route( '/calendar/shares', 'PUT', array( __CLASS__, 'set_shares' ) );
	}

	private static function event_row( $id ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . NWC_DB::t( 'events' ) . ' WHERE id = %d AND workspace_id = %d AND deleted_at IS NULL', $id, NWC_Tenancy::$current->id ) ); // phpcs:ignore
	}

	private static function shared_owner_ids( $uid ) {
		global $wpdb;
		return array_map( 'intval', $wpdb->get_col( $wpdb->prepare( 'SELECT owner_id FROM ' . NWC_DB::t( 'calendar_shares' ) . ' WHERE workspace_id = %d AND user_id = %d', NWC_Tenancy::$current->id, $uid ) ) ); // phpcs:ignore
	}

	/** Can the current user see this event at all? */
	private static function can_view( $e, $uid ) {
		if ( 'personal' === $e->calendar ) {
			return (int) $e->owner_id === (int) $uid || in_array( (int) $e->owner_id, self::shared_owner_ids( $uid ), true );
		}
		if ( 'business' === $e->calendar ) {
			return NWC_Tenancy::can( 'calendar.business' ) && NWC_Features::allowed( 'calendar_business' );
		}
		return NWC_Features::allowed( 'calendar_project' ) && $e->project_id && NWC_Projects::can_see( $e->project_id );
	}

	private static function can_edit( $e, $uid ) {
		if ( 'personal' === $e->calendar ) {
			return (int) $e->owner_id === (int) $uid;
		}
		if ( 'business' === $e->calendar ) {
			return NWC_Tenancy::can( 'calendar.business.write' ) || (int) $e->owner_id === (int) $uid;
		}
		return NWC_Tenancy::can( 'tasks.write' ) && $e->project_id && NWC_Projects::can_see( $e->project_id );
	}

	/** Which feature a calendar needs. @return true|WP_Error */
	private static function calendar_guard( $cal ) {
		$map = array( 'personal' => 'calendar_personal', 'business' => 'calendar_business', 'project' => 'calendar_project' );
		if ( ! isset( $map[ $cal ] ) ) {
			return NWC::err( 'Unknown calendar.' );
		}
		return NWC_Features::guard( $map[ $cal ] );
	}

	private static function clean_rrule( $r ) {
		if ( ! is_array( $r ) || empty( $r['freq'] ) || ! in_array( $r['freq'], array( 'daily', 'weekly', 'monthly', 'yearly' ), true ) ) {
			return null;
		}
		$o = array( 'freq' => $r['freq'], 'interval' => max( 1, min( 365, (int) ( $r['interval'] ?? 1 ) ) ) );
		if ( ! empty( $r['until'] ) && NWC::valid_date( $r['until'] ) ) {
			$o['until'] = $r['until'];
		}
		if ( ! empty( $r['count'] ) ) {
			$o['count'] = max( 1, min( 1000, (int) $r['count'] ) );
		}
		return $o;
	}

	private static function fields( $b, $old, $tz ) {
		$title = array_key_exists( 'title', $b ) ? mb_substr( sanitize_text_field( (string) $b['title'] ), 0, 190 ) : ( $old ? $old->title : '' );
		if ( '' === $title ) {
			return NWC::err( 'Give the event a title.' );
		}
		$row = array( 'title' => $title );
		$all = array_key_exists( 'all_day', $b ) ? (bool) $b['all_day'] : ( $old ? (bool) $old->all_day : false );
		if ( array_key_exists( 'starts_at', $b ) ) {
			$row['starts_at'] = NWC::to_utc( (string) $b['starts_at'], $tz );
			if ( ! $row['starts_at'] ) {
				return NWC::err( 'Choose when the event starts.' );
			}
		} elseif ( ! $old ) {
			return NWC::err( 'Choose when the event starts.' );
		}
		if ( array_key_exists( 'ends_at', $b ) ) {
			$row['ends_at'] = '' === (string) $b['ends_at'] ? null : NWC::to_utc( (string) $b['ends_at'], $tz );
		}
		$s = isset( $row['starts_at'] ) ? $row['starts_at'] : $old->starts_at;
		$e = array_key_exists( 'ends_at', $row ) ? $row['ends_at'] : ( $old ? $old->ends_at : null );
		if ( $e && $e < $s ) {
			return NWC::err( 'The event ends before it starts.' );
		}
		$row['all_day'] = $all ? 1 : 0;
		foreach ( array( 'description' => 'long', 'private_note' => 'long', 'location' => 190, 'event_type' => 30 ) as $k => $t ) {
			if ( array_key_exists( $k, $b ) ) {
				$row[ $k ] = 'long' === $t ? sanitize_textarea_field( (string) $b[ $k ] ) : mb_substr( sanitize_text_field( (string) $b[ $k ] ), 0, $t );
			}
		}
		if ( array_key_exists( 'color', $b ) ) {
			$row['color'] = (string) sanitize_hex_color( (string) $b['color'] );
		}
		if ( array_key_exists( 'rrule', $b ) ) {
			$rr           = self::clean_rrule( $b['rrule'] );
			$row['rrule'] = $rr ? wp_json_encode( $rr ) : null;
		}
		if ( array_key_exists( 'reminder_minutes', $b ) ) {
			$row['reminder_minutes'] = '' === (string) $b['reminder_minutes'] || null === $b['reminder_minutes'] ? null : max( 0, min( 43200, (int) $b['reminder_minutes'] ) );
		}
		return $row;
	}

	private static function save_participants( $event_id, $list ) {
		global $wpdb;
		$ws = (int) NWC_Tenancy::$current->id;
		$wpdb->delete( NWC_DB::t( 'event_participants' ), array( 'event_id' => $event_id, 'workspace_id' => $ws ) );
		foreach ( array_slice( (array) $list, 0, 100 ) as $p ) {
			$uid   = is_array( $p ) ? absint( $p['user_id'] ?? 0 ) : absint( $p );
			$email = is_array( $p ) ? sanitize_email( (string) ( $p['email'] ?? '' ) ) : '';
			if ( $uid && ! NWC_Tenancy::member_row( $ws, $uid ) ) {
				continue;
			}
			if ( $uid || $email ) {
				$wpdb->insert( NWC_DB::t( 'event_participants' ), array( 'workspace_id' => $ws, 'event_id' => (int) $event_id, 'user_id' => $uid ? $uid : null, 'email' => $email, 'created_at' => NWC::now() ) );
			}
		}
	}

	public static function create( WP_REST_Request $r ) {
		global $wpdb;
		$b   = NWC_REST::body( $r );
		$cal = (string) ( $b['calendar'] ?? 'personal' );
		$g   = self::calendar_guard( $cal );
		if ( is_wp_error( $g ) ) {
			return $g;
		}
		$uid = get_current_user_id();
		$pid = null;
		if ( 'business' === $cal && ! NWC_Tenancy::can( 'calendar.business.write' ) ) {
			return NWC::err( 'Your role cannot add to the business calendar.', 403, 'nwc_forbidden' );
		}
		if ( 'project' === $cal ) {
			$pid = absint( $b['project_id'] ?? 0 );
			if ( ! $pid || ! NWC_Projects::can_see( $pid ) ) {
				return NWC::err( 'Project not found.', 404, 'nwc_not_found' );
			}
			if ( ! NWC_Tenancy::can( 'tasks.write' ) ) {
				return NWC::err( 'Your role does not allow this.', 403, 'nwc_forbidden' );
			}
		}
		$row = self::fields( $b, null, NWC_Tenancy::$current->timezone );
		if ( is_wp_error( $row ) ) {
			return $row;
		}
		$now = NWC::now();
		$ok  = $wpdb->insert( NWC_DB::t( 'events' ), array_merge( $row, array( 'workspace_id' => (int) NWC_Tenancy::$current->id, 'calendar' => $cal, 'project_id' => $pid, 'owner_id' => $uid, 'created_at' => $now, 'updated_at' => $now ) ) );
		if ( ! $ok ) {
			return NWC::err( 'Could not save the event.', 500, 'nwc_error' );
		}
		$id = (int) $wpdb->insert_id;
		if ( isset( $b['participants'] ) ) {
			self::save_participants( $id, $b['participants'] );
		}
		return self::event_out( self::event_row( $id ), $uid );
	}

	public static function show( WP_REST_Request $r ) {
		$uid = get_current_user_id();
		$e   = self::event_row( (int) $r['id'] );
		if ( ! $e || ! self::can_view( $e, $uid ) ) {
			return NWC::err( 'Event not found.', 404, 'nwc_not_found' );
		}
		return self::event_out( $e, $uid );
	}

	private static function event_out( $e, $uid ) {
		global $wpdb;
		$o = array(
			'id' => (int) $e->id, 'calendar' => $e->calendar, 'project_id' => $e->project_id ? (int) $e->project_id : null, 'owner_id' => (int) $e->owner_id, 'title' => $e->title, 'description' => (string) $e->description, 'location' => $e->location,
			'event_type' => $e->event_type, 'starts_at' => $e->starts_at, 'ends_at' => $e->ends_at, 'all_day' => (bool) $e->all_day, 'rrule' => json_decode( (string) $e->rrule, true ), 'color' => $e->color, 'reminder_minutes' => null === $e->reminder_minutes ? null : (int) $e->reminder_minutes,
			'can_edit' => self::can_edit( $e, $uid ),
			'participants' => $wpdb->get_results( $wpdb->prepare( 'SELECT user_id, email FROM ' . NWC_DB::t( 'event_participants' ) . ' WHERE event_id = %d', $e->id ) ), // phpcs:ignore
		);
		if ( (int) $e->owner_id === (int) $uid ) { // Private notes are for the owner alone.
			$o['private_note'] = (string) $e->private_note;
		}
		return $o;
	}

	public static function update( WP_REST_Request $r ) {
		global $wpdb;
		$uid = get_current_user_id();
		$e   = self::event_row( (int) $r['id'] );
		if ( ! $e || ! self::can_view( $e, $uid ) ) {
			return NWC::err( 'Event not found.', 404, 'nwc_not_found' );
		}
		if ( ! self::can_edit( $e, $uid ) ) {
			return NWC::err( 'You can only view this event.', 403, 'nwc_forbidden' );
		}
		$g = self::calendar_guard( $e->calendar );
		if ( is_wp_error( $g ) ) {
			return $g;
		}
		$b   = NWC_REST::body( $r );
		$row = self::fields( $b, $e, NWC_Tenancy::$current->timezone );
		if ( is_wp_error( $row ) ) {
			return $row;
		}
		if ( (int) $e->owner_id !== $uid ) {
			unset( $row['private_note'] );
		}
		$row['updated_at'] = NWC::now();
		$wpdb->update( NWC_DB::t( 'events' ), $row, array( 'id' => $e->id, 'workspace_id' => NWC_Tenancy::$current->id ) );
		if ( isset( $b['participants'] ) ) {
			self::save_participants( $e->id, $b['participants'] );
		}
		return self::event_out( self::event_row( $e->id ), $uid );
	}

	public static function remove( WP_REST_Request $r ) {
		global $wpdb;
		$uid = get_current_user_id();
		$e   = self::event_row( (int) $r['id'] );
		if ( ! $e || ! self::can_view( $e, $uid ) ) {
			return NWC::err( 'Event not found.', 404, 'nwc_not_found' );
		}
		if ( ! self::can_edit( $e, $uid ) ) {
			return NWC::err( 'You can only view this event.', 403, 'nwc_forbidden' );
		}
		$wpdb->update( NWC_DB::t( 'events' ), array( 'deleted_at' => NWC::now() ), array( 'id' => $e->id ) );
		return array( 'ok' => true );
	}

	public static function shares() {
		global $wpdb;
		return array( 'user_ids' => array_map( 'intval', $wpdb->get_col( $wpdb->prepare( 'SELECT user_id FROM ' . NWC_DB::t( 'calendar_shares' ) . ' WHERE workspace_id = %d AND owner_id = %d', NWC_Tenancy::$current->id, get_current_user_id() ) ) ) ); // phpcs:ignore
	}

	/** A person shares their own personal calendar, read-only, with colleagues in this workspace. */
	public static function set_shares( WP_REST_Request $r ) {
		global $wpdb;
		$ws  = (int) NWC_Tenancy::$current->id;
		$own = get_current_user_id();
		$ids = array_values( array_unique( array_map( 'absint', (array) ( NWC_REST::body( $r )['user_ids'] ?? array() ) ) ) );
		foreach ( $ids as $u ) {
			if ( ! NWC_Tenancy::member_row( $ws, $u ) ) {
				return NWC::err( 'Only workspace members can see a shared calendar.' );
			}
		}
		$wpdb->delete( NWC_DB::t( 'calendar_shares' ), array( 'workspace_id' => $ws, 'owner_id' => $own ) );
		foreach ( $ids as $u ) {
			if ( $u !== $own ) {
				$wpdb->insert( NWC_DB::t( 'calendar_shares' ), array( 'workspace_id' => $ws, 'owner_id' => $own, 'user_id' => $u, 'created_at' => NWC::now() ) );
			}
		}
		return array( 'user_ids' => $ids );
	}

	/** Expands one event into occurrences within a UTC range. */
	public static function occurrences( $e, $from, $to, $limit = 366 ) {
		$start = strtotime( $e->starts_at . ' UTC' );
		$dur   = $e->ends_at ? strtotime( $e->ends_at . ' UTC' ) - $start : 0;
		$rr    = json_decode( (string) $e->rrule, true );
		$fromt = strtotime( $from . ' 00:00:00 UTC' );
		$tot   = strtotime( $to . ' 23:59:59 UTC' );
		$out   = array();
		if ( ! $rr ) {
			if ( $start <= $tot && $start + $dur >= $fromt ) {
				$out[] = array( $start, $start + $dur );
			}
			return $out;
		}
		$until = ! empty( $rr['until'] ) ? strtotime( $rr['until'] . ' 23:59:59 UTC' ) : PHP_INT_MAX;
		$count = isset( $rr['count'] ) ? (int) $rr['count'] : PHP_INT_MAX;
		$i     = (int) $rr['interval'];
		$d     = new DateTimeImmutable( '@' . $start );
		$unit  = array( 'daily' => 'D', 'weekly' => 'W', 'monthly' => 'M', 'yearly' => 'Y' )[ $rr['freq'] ];
		$iv    = new DateInterval( 'P' . $i . $unit );
		$anchor_day = (int) $d->format( 'j' );
		for ( $n = 0; $n < $count && $n < 5000; $n++ ) {
			$cur = $d;
			$t   = $cur->getTimestamp();
			if ( $t > $tot || $t > $until ) {
				break;
			}
			if ( $t + $dur >= $fromt ) {
				$out[] = array( $t, $t + $dur );
				if ( count( $out ) >= $limit ) {
					break;
				}
			}
			$d = $d->add( $iv );
			// 31 Jan monthly must not drift: months without that day use their last day.
			if ( in_array( $unit, array( 'M', 'Y' ), true ) && (int) $d->format( 'j' ) !== $anchor_day ) {
				$d = $d->modify( 'last day of previous month' );
			}
		}
		return $out;
	}

	public static function calendars() {
		global $wpdb;
		$out = array();
		foreach ( array( 'personal' => 'calendar_personal', 'business' => 'calendar_business', 'agency' => 'calendar_agency' ) as $k => $f ) {
			if ( 'business' === $k && ! NWC_Tenancy::can( 'calendar.business' ) ) {
				continue;
			}
			$out[] = array( 'key' => $k, 'label' => 'agency' === $k ? 'NeonDigital Agency' : ucfirst( $k ) . ' Calendar', 'color' => self::COLORS[ $k ], 'allowed' => NWC_Features::allowed( $f ), 'feature' => $f );
		}
		if ( NWC_Features::allowed( 'bookings' ) && NWC_Tenancy::can( 'bookings.read' ) ) {
			$out[] = array( 'key' => 'bookings', 'label' => 'Bookings', 'color' => self::COLORS['booking'], 'allowed' => true, 'feature' => 'bookings' );
		}
		if ( NWC_Features::allowed( 'calendar_project' ) && NWC_Features::allowed( 'projects' ) ) {
			$vals = array();
			$sql  = 'workspace_id = %d AND deleted_at IS NULL AND status <> \'archived\'';
			$vals[] = (int) NWC_Tenancy::$current->id;
			$extra  = NWC_Projects::scope_projects( $vals );
			if ( $extra ) {
				$sql .= ' AND ' . $extra;
			}
			foreach ( $wpdb->get_results( $wpdb->prepare( 'SELECT id, name, color FROM ' . NWC_DB::t( 'projects' ) . " WHERE $sql ORDER BY name ASC", $vals ) ) as $p ) { // phpcs:ignore
				$out[] = array( 'key' => 'project:' . $p->id, 'label' => $p->name, 'color' => $p->color ? $p->color : self::COLORS['project'], 'allowed' => true, 'feature' => 'calendar_project' );
			}
		}
		return array( 'calendars' => $out, 'timezone' => NWC_Tenancy::$current->timezone );
	}

	/**
	 * Unified feed. `calendars` lists keys; default is everything the user is authorised to see.
	 * Each item names its source so the UI opens the real record.
	 */
	public static function feed( WP_REST_Request $r ) {
		global $wpdb;
		$from = (string) $r->get_param( 'from' );
		$to   = (string) $r->get_param( 'to' );
		if ( ! NWC::valid_date( $from ) || ! NWC::valid_date( $to ) || $to < $from || strtotime( $to ) - strtotime( $from ) > 400 * DAY_IN_SECONDS ) {
			return NWC::err( 'Give a valid date range (up to 400 days).' );
		}
		$uid     = get_current_user_id();
		$ws      = (int) NWC_Tenancy::$current->id;
		$want    = array_filter( explode( ',', (string) $r->get_param( 'calendars' ) ) );
		$wanted  = function ( $k ) use ( $want ) {
			return ! $want || in_array( $k, $want, true );
		};
		$q       = trim( sanitize_text_field( (string) $r->get_param( 'q' ) ) );
		$matches = function ( $title ) use ( $q ) {
			return '' === $q || false !== mb_stripos( (string) $title, $q );
		};
		$items   = array();

		// Events: recurring ones may start before the range, so only the range end bounds the query.
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . NWC_DB::t( 'events' ) . ' WHERE workspace_id = %d AND deleted_at IS NULL AND starts_at <= %s', $ws, $to . ' 23:59:59' ) ); // phpcs:ignore
		$shared = self::shared_owner_ids( $uid );
		foreach ( $rows as $e ) {
			if ( ! $matches( $e->title ) || ! self::can_view( $e, $uid ) ) {
				continue;
			}
			if ( 'personal' === $e->calendar ) {
				if ( ! NWC_Features::allowed( 'calendar_personal' ) ) {
					continue;
				}
				$key = 'personal';
			} elseif ( 'business' === $e->calendar ) {
				$key = 'business';
			} else {
				$key = 'project:' . $e->project_id;
			}
			if ( ! $wanted( $key ) ) {
				continue;
			}
			foreach ( self::occurrences( $e, $from, $to ) as $occ ) {
				$items[] = array(
					'id'       => 'event-' . $e->id . '@' . gmdate( 'YmdHis', $occ[0] ),
					'source'   => 'event',
					'ref_id'   => (int) $e->id,
					'calendar' => $key,
					'title'    => $e->title,
					'start'    => gmdate( 'Y-m-d\TH:i:s\Z', $occ[0] ),
					'end'      => $occ[1] > $occ[0] ? gmdate( 'Y-m-d\TH:i:s\Z', $occ[1] ) : null,
					'all_day'  => (bool) $e->all_day,
					'color'    => $e->color ? $e->color : ( isset( self::COLORS[ $e->calendar ] ) ? self::COLORS[ $e->calendar ] : self::COLORS['business'] ),
					'recurring' => (bool) $e->rrule,
					'editable' => self::can_edit( $e, $uid ) && ! $e->rrule,
					'shared_from' => 'personal' === $e->calendar && (int) $e->owner_id !== $uid,
					'type'     => (string) $e->event_type,
					'project_id' => $e->project_id ? (int) $e->project_id : null,
				);
			}
		}

		// Bookings: one record, shown by reference.
		if ( $wanted( 'bookings' ) && NWC_Features::allowed( 'bookings' ) && NWC_Tenancy::can( 'bookings.read' ) ) {
			$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT b.id, b.starts_at, b.ends_at, b.status, b.customer_name, s.name AS service FROM ' . NWC_DB::t( 'bookings' ) . ' b LEFT JOIN ' . NWC_DB::t( 'services' ) . " s ON s.id = b.service_id WHERE b.workspace_id = %d AND b.deleted_at IS NULL AND b.status <> 'cancelled' AND b.starts_at BETWEEN %s AND %s LIMIT 1000", $ws, $from . ' 00:00:00', $to . ' 23:59:59' ) ); // phpcs:ignore
			foreach ( $rows as $b ) {
				$title = $b->customer_name . ' · ' . $b->service;
				if ( $matches( $title ) ) {
					$items[] = array( 'id' => 'booking-' . $b->id, 'source' => 'booking', 'ref_id' => (int) $b->id, 'calendar' => 'bookings', 'title' => $title, 'start' => gmdate( 'Y-m-d\TH:i:s\Z', strtotime( $b->starts_at . ' UTC' ) ), 'end' => gmdate( 'Y-m-d\TH:i:s\Z', strtotime( $b->ends_at . ' UTC' ) ), 'all_day' => false, 'color' => self::COLORS['booking'], 'editable' => false, 'status' => $b->status );
				}
			}
		}

		// Registration events: one form record, shown by reference on the business calendar.
		if ( $wanted( 'business' ) && NWC_Features::allowed( 'registration_forms' ) && NWC_Tenancy::can( 'calendar.business' ) ) {
			$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT id, title, event_start, event_end FROM ' . NWC_DB::t( 'forms' ) . " WHERE workspace_id = %d AND kind = 'registration' AND status <> 'draft' AND deleted_at IS NULL AND event_start BETWEEN %s AND %s LIMIT 200", $ws, $from . ' 00:00:00', $to . ' 23:59:59' ) ); // phpcs:ignore
			foreach ( $rows as $f ) {
				if ( $matches( $f->title ) ) {
					$items[] = array( 'id' => 'registration-' . $f->id, 'source' => 'registration', 'ref_id' => (int) $f->id, 'calendar' => 'business', 'title' => $f->title, 'start' => gmdate( 'Y-m-d\TH:i:s\Z', strtotime( $f->event_start . ' UTC' ) ), 'end' => gmdate( 'Y-m-d\TH:i:s\Z', strtotime( ( $f->event_end ? $f->event_end : $f->event_start ) . ' UTC' ) ), 'all_day' => false, 'color' => self::COLORS['booking'], 'editable' => false );
				}
			}
		}

		// Project tasks and milestones: by reference, filtered by project visibility.
		if ( NWC_Features::allowed( 'calendar_project' ) && NWC_Features::allowed( 'projects' ) && NWC_Tenancy::can( 'projects.read' ) ) {
			$vals = array( $ws, $from, $to );
			$sql  = 'workspace_id = %d AND deleted_at IS NULL AND due_date BETWEEN %s AND %s AND project_id IS NOT NULL';
			$ex   = NWC_Projects::scope_tasks( $vals );
			if ( $ex ) {
				$sql .= ' AND ' . $ex;
			}
			foreach ( $wpdb->get_results( $wpdb->prepare( 'SELECT id, title, due_date, project_id, status FROM ' . NWC_DB::t( 'tasks' ) . " WHERE $sql LIMIT 1000", $vals ) ) as $t ) { // phpcs:ignore
				if ( $wanted( 'project:' . $t->project_id ) && $matches( $t->title ) ) {
					$items[] = array( 'id' => 'task-' . $t->id, 'source' => 'task', 'ref_id' => (int) $t->id, 'calendar' => 'project:' . $t->project_id, 'title' => $t->title, 'start' => $t->due_date, 'end' => null, 'all_day' => true, 'color' => self::COLORS['task'], 'editable' => false, 'status' => $t->status );
				}
			}
			$vals = array( $ws, $from, $to );
			$sql  = 'workspace_id = %d AND deleted_at IS NULL AND due_date BETWEEN %s AND %s';
			$ex   = NWC_Projects::scope_children( $vals );
			if ( $ex ) {
				$sql .= ' AND ' . $ex;
			}
			foreach ( $wpdb->get_results( $wpdb->prepare( 'SELECT id, title, due_date, project_id FROM ' . NWC_DB::t( 'milestones' ) . " WHERE $sql LIMIT 500", $vals ) ) as $m ) { // phpcs:ignore
				if ( $wanted( 'project:' . $m->project_id ) && $matches( $m->title ) ) {
					$items[] = array( 'id' => 'milestone-' . $m->id, 'source' => 'milestone', 'ref_id' => (int) $m->id, 'calendar' => 'project:' . $m->project_id, 'title' => $m->title, 'start' => $m->due_date, 'end' => null, 'all_day' => true, 'color' => self::COLORS['milestone'], 'editable' => false );
				}
			}
		}

		// Agency items come live from WorkDesk. Nothing is copied here.
		$agency_error = null;
		if ( $wanted( 'agency' ) && NWC_Features::allowed( 'calendar_agency' ) && NWC_WorkDesk::link( $ws ) ) {
			$res = NWC_WorkDesk::cached( $ws, 'GET', '/calendar', array( 'from' => $from, 'to' => $to ) );
			if ( is_wp_error( $res ) ) {
				$agency_error = $res->get_error_message();
			} else {
				foreach ( (array) ( $res['items'] ?? array() ) as $a ) {
					$start = str_replace( ' ', 'T', (string) $a['start'] ) . 'Z';
					$all   = ' 00:00:00' === substr( (string) $a['start'], 10 );
					if ( $matches( $a['title'] ) ) {
						$items[] = array( 'id' => 'agency-' . $a['id'], 'source' => 'agency', 'ref_id' => $a['id'], 'calendar' => 'agency', 'title' => $a['title'], 'start' => $all ? substr( (string) $a['start'], 0, 10 ) : $start, 'end' => ! empty( $a['end'] ) ? str_replace( ' ', 'T', $a['end'] ) . 'Z' : null, 'all_day' => $all, 'color' => self::COLORS['agency'], 'editable' => false, 'type' => $a['type'] ?? '', 'project_id' => isset( $a['project_id'] ) ? (int) $a['project_id'] : null, 'project_name' => (string) ( $a['project_name'] ?? '' ), 'parent_id' => isset( $a['parent_id'] ) ? (int) $a['parent_id'] : null, 'parent_name' => (string) ( $a['parent_name'] ?? '' ) );
					}
				}
			}
		}
		usort( $items, function ( $a, $b ) {
			return strcmp( $a['start'], $b['start'] );
		} );
		return array( 'items' => $items, 'agency_error' => $agency_error );
	}

	/**
	 * Overlaps for one person: their events, shared-with-them events and bookings they staff.
	 *
	 * @return array Conflicting items, titles shown only where the viewer may see them.
	 */
	public static function conflicts( $user_id, $start_utc, $end_utc, $exclude_event = 0 ) {
		global $wpdb;
		$ws  = (int) NWC_Tenancy::$current->id;
		$out = array();
		$end = $end_utc ? $end_utc : $start_utc;
		$day = substr( $start_utc, 0, 10 );
		$to  = substr( $end, 0, 10 );
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT e.* FROM ' . NWC_DB::t( 'events' ) . ' e WHERE e.workspace_id = %d AND e.deleted_at IS NULL AND e.all_day = 0 AND e.starts_at <= %s AND ( e.owner_id = %d OR e.id IN (SELECT event_id FROM ' . NWC_DB::t( 'event_participants' ) . ' WHERE user_id = %d) )', $ws, $end, $user_id, $user_id ) ); // phpcs:ignore
		$st   = strtotime( $start_utc . ' UTC' );
		$et   = strtotime( $end . ' UTC' );
		foreach ( $rows as $e ) {
			if ( (int) $e->id === (int) $exclude_event ) {
				continue;
			}
			foreach ( self::occurrences( $e, $day, $to ) as $occ ) {
				if ( $occ[0] < $et && max( $occ[1], $occ[0] + 60 ) > $st ) {
					$mine  = (int) $e->owner_id === get_current_user_id();
					$out[] = array( 'source' => 'event', 'title' => $mine || 'personal' !== $e->calendar ? $e->title : 'Busy', 'start' => gmdate( 'Y-m-d\TH:i:s\Z', $occ[0] ) );
					break;
				}
			}
		}
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT id, starts_at, customer_name FROM ' . NWC_DB::t( 'bookings' ) . " WHERE workspace_id = %d AND deleted_at IS NULL AND status IN ('pending','confirmed') AND staff_id = %d AND starts_at < %s AND ends_at > %s", $ws, $user_id, $end, $start_utc ) ); // phpcs:ignore
		foreach ( $rows as $b ) {
			$out[] = array( 'source' => 'booking', 'title' => 'Booking: ' . $b->customer_name, 'start' => gmdate( 'Y-m-d\TH:i:s\Z', strtotime( $b->starts_at . ' UTC' ) ) );
		}
		return $out;
	}

	public static function conflicts_route( WP_REST_Request $r ) {
		$b     = NWC_REST::body( $r );
		$tz    = NWC_Tenancy::$current->timezone;
		$start = NWC::to_utc( (string) ( $b['starts_at'] ?? '' ), $tz );
		if ( ! $start ) {
			return NWC::err( 'Give a start time.' );
		}
		$user = absint( $b['user_id'] ?? 0 );
		$user = $user && NWC_Tenancy::member_row( NWC_Tenancy::$current->id, $user ) && NWC_Tenancy::can( 'calendar.business' ) ? $user : get_current_user_id();
		return array( 'conflicts' => self::conflicts( $user, $start, NWC::to_utc( (string) ( $b['ends_at'] ?? '' ), $tz ), absint( $b['exclude_event'] ?? 0 ) ) );
	}
}
