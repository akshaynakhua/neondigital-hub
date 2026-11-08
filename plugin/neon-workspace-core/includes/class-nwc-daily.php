<?php
/**
 * Daily Planner, Meetings, global search, the user-facing activity feed, and basic reports.
 * The planner only points at existing records. A meeting's date and time live in a calendar event,
 * so there is no second calendar, and its action items are ordinary tasks.
 */

defined( 'ABSPATH' ) || exit;

class NWC_Daily {

	public static function routes() {
		NWC_REST::route( '/planner', 'GET', array( __CLASS__, 'planner' ) );
		NWC_REST::route( '/planner', 'PUT', array( __CLASS__, 'planner_save' ) );
		NWC_REST::route( '/meetings', 'GET', array( __CLASS__, 'meetings' ) );
		NWC_REST::route( '/meetings', 'POST', array( __CLASS__, 'meeting_create' ) );
		NWC_REST::route( '/meetings/(?P<id>\d+)', 'GET', array( __CLASS__, 'meeting_show' ) );
		NWC_REST::route( '/meetings/(?P<id>\d+)', 'PUT,PATCH', array( __CLASS__, 'meeting_update' ) );
		NWC_REST::route( '/meetings/(?P<id>\d+)', 'DELETE', array( __CLASS__, 'meeting_remove' ) );
		NWC_REST::route( '/meetings/(?P<id>\d+)/actions', 'POST', array( __CLASS__, 'meeting_action' ) );
		NWC_REST::route( '/search', 'GET', array( __CLASS__, 'search' ) );
		NWC_REST::route( '/activity', 'GET', array( __CLASS__, 'activity' ) );
		NWC_REST::route( '/activity/export', 'GET', array( __CLASS__, 'activity_export' ) );
		NWC_REST::route( '/reports', 'GET', array( __CLASS__, 'reports' ) );
	}

	/* ---------------------------- Daily Planner ---------------------------- */

	public static function planner( WP_REST_Request $r ) {
		global $wpdb;
		$g = NWC_Features::guard( 'daily_planner' );
		if ( is_wp_error( $g ) ) {
			return $g;
		}
		$w    = NWC_Tenancy::$current;
		$ws   = (int) $w->id;
		$uid  = get_current_user_id();
		$date = NWC::valid_date( (string) $r['date'] ) ? (string) $r['date'] : NWC::today( $w->timezone );
		$out  = array( 'date' => $date, 'tasks' => array(), 'overdue' => array(), 'follow_ups' => array(), 'bookings' => array(), 'reminders' => array() );
		if ( NWC_Features::allowed( 'projects' ) && NWC_Tenancy::can( 'projects.read' ) ) {
			$t = NWC_DB::t( 'tasks' );
			$out['tasks']     = $wpdb->get_results( $wpdb->prepare( "SELECT id, title, status, priority, due_date, project_id FROM $t WHERE workspace_id = %d AND deleted_at IS NULL AND assignee_id = %d AND due_date = %s ORDER BY CASE priority WHEN 'urgent' THEN 0 WHEN 'high' THEN 1 WHEN 'medium' THEN 2 ELSE 3 END, id", $ws, $uid, $date ) ); // phpcs:ignore
			$out['overdue']   = $wpdb->get_results( $wpdb->prepare( "SELECT id, title, status, priority, due_date, project_id FROM $t WHERE workspace_id = %d AND deleted_at IS NULL AND assignee_id = %d AND due_date < %s AND status <> 'done' ORDER BY due_date ASC LIMIT 20", $ws, $uid, $date ) ); // phpcs:ignore
			$out['reminders'] = $wpdb->get_results( $wpdb->prepare( "SELECT id, title, reminder_at FROM $t WHERE workspace_id = %d AND deleted_at IS NULL AND assignee_id = %d AND reminder_at LIKE %s", $ws, $uid, $wpdb->esc_like( $date ) . '%' ) ); // phpcs:ignore
		}
		if ( NWC_Features::allowed( 'crm' ) && NWC_Tenancy::can( 'crm.read' ) ) {
			$out['follow_ups'] = $wpdb->get_results( $wpdb->prepare( 'SELECT id, name, phone, stage, enquiry, follow_up_on FROM ' . NWC_DB::t( 'leads' ) . " WHERE workspace_id = %d AND deleted_at IS NULL AND stage NOT IN ('converted','lost') AND follow_up_on <= %s AND (assigned_to = %d OR assigned_to IS NULL) ORDER BY follow_up_on ASC LIMIT 30", $ws, $date, $uid ) ); // phpcs:ignore
		}
		$day = $wpdb->get_row( $wpdb->prepare( 'SELECT priorities, notes FROM ' . NWC_DB::t( 'planner_days' ) . ' WHERE workspace_id = %d AND user_id = %d AND on_date = %s', $ws, $uid, $date ) ); // phpcs:ignore
		$out['priorities'] = $day ? ( json_decode( (string) $day->priorities, true ) ?: array() ) : array();
		$out['notes']      = $day ? (string) $day->notes : '';
		$done  = count( array_filter( $out['tasks'], function ( $t ) {
			return 'done' === $t->status;
		} ) ) + count( array_filter( $out['priorities'], function ( $p ) {
			return ! empty( $p['done'] );
		} ) );
		$total = count( $out['tasks'] ) + count( $out['priorities'] );
		$out['progress'] = array( 'done' => $done, 'total' => $total, 'percent' => $total ? (int) round( $done * 100 / $total ) : 0 );
		return $out;
	}

	public static function planner_save( WP_REST_Request $r ) {
		global $wpdb;
		$g = NWC_Features::guard( 'daily_planner' );
		if ( is_wp_error( $g ) ) {
			return $g;
		}
		$b    = NWC_REST::body( $r );
		$date = (string) ( $b['date'] ?? '' );
		if ( ! NWC::valid_date( $date ) ) {
			return NWC::err( 'Choose a valid date.' );
		}
		$pri = array();
		foreach ( array_slice( (array) ( $b['priorities'] ?? array() ), 0, 30 ) as $p ) {
			$text = mb_substr( trim( sanitize_text_field( (string) ( $p['text'] ?? '' ) ) ), 0, 255 );
			if ( '' !== $text ) {
				$pri[] = array( 'text' => $text, 'done' => ! empty( $p['done'] ) );
			}
		}
		$ws  = (int) NWC_Tenancy::$current->id;
		$uid = get_current_user_id();
		$t   = NWC_DB::t( 'planner_days' );
		$row = array( 'priorities' => wp_json_encode( $pri ), 'notes' => sanitize_textarea_field( (string) ( $b['notes'] ?? '' ) ), 'updated_at' => NWC::now() );
		$id  = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM $t WHERE workspace_id = %d AND user_id = %d AND on_date = %s", $ws, $uid, $date ) ); // phpcs:ignore
		if ( $id ) {
			$wpdb->update( $t, $row, array( 'id' => $id ) );
		} else {
			$wpdb->insert( $t, array_merge( $row, array( 'workspace_id' => $ws, 'user_id' => $uid, 'on_date' => $date ) ) );
		}
		return array( 'ok' => true );
	}

	/* ---------------------------- Meetings ---------------------------- */

	private static function meeting_guard( $write = false ) {
		if ( $write && ! NWC_Tenancy::can( 'tasks.write' ) ) {
			return NWC::err( 'Your role does not allow this.', 403, 'nwc_forbidden' );
		}
		return NWC_Features::guard( 'meetings' );
	}

	private static function meeting_row( $id ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT m.*, e.title, e.starts_at, e.ends_at, e.location, e.calendar, e.project_id FROM ' . NWC_DB::t( 'meetings' ) . ' m JOIN ' . NWC_DB::t( 'events' ) . ' e ON e.id = m.event_id WHERE m.id = %d AND m.workspace_id = %d AND m.deleted_at IS NULL AND e.deleted_at IS NULL', $id, NWC_Tenancy::$current->id ) ); // phpcs:ignore
	}

	/** A meeting can be seen by its creator, its participants and people who can see that calendar. */
	private static function meeting_visible( $m ) {
		global $wpdb;
		if ( 'business' === $m->calendar && NWC_Tenancy::can( 'calendar.business' ) ) {
			return true;
		}
		if ( 'project' === $m->calendar && $m->project_id && NWC_Projects::can_see( (int) $m->project_id ) ) {
			return true;
		}
		$uid = get_current_user_id();
		return (int) $m->created_by === $uid || (bool) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . NWC_DB::t( 'event_participants' ) . ' WHERE event_id = %d AND user_id = %d', $m->event_id, $uid ) ); // phpcs:ignore
	}

	private static function meeting_out( $m ) {
		global $wpdb;
		$p = $wpdb->get_results( $wpdb->prepare( 'SELECT user_id, email FROM ' . NWC_DB::t( 'event_participants' ) . ' WHERE event_id = %d', $m->event_id ) ); // phpcs:ignore
		$m->participants = array_map( function ( $x ) {
			$u = $x->user_id ? get_userdata( $x->user_id ) : null;
			return array( 'user_id' => (int) $x->user_id, 'email' => $x->email, 'name' => $u ? $u->display_name : $x->email );
		}, $p );
		$m->duration_min = $m->ends_at ? (int) round( ( strtotime( $m->ends_at ) - strtotime( $m->starts_at ) ) / 60 ) : 0;
		$m->actions      = $wpdb->get_results( $wpdb->prepare( 'SELECT id, title, status, assignee_id, due_date FROM ' . NWC_DB::t( 'tasks' ) . ' WHERE workspace_id = %d AND meeting_id = %d AND deleted_at IS NULL ORDER BY id', $m->workspace_id, $m->id ) ); // phpcs:ignore
		return $m;
	}

	public static function meetings( WP_REST_Request $r ) {
		global $wpdb;
		$g = self::meeting_guard();
		if ( is_wp_error( $g ) ) {
			return $g;
		}
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT m.*, e.title, e.starts_at, e.ends_at, e.location, e.calendar, e.project_id FROM ' . NWC_DB::t( 'meetings' ) . ' m JOIN ' . NWC_DB::t( 'events' ) . ' e ON e.id = m.event_id WHERE m.workspace_id = %d AND m.deleted_at IS NULL AND e.deleted_at IS NULL ORDER BY e.starts_at DESC LIMIT 200', NWC_Tenancy::$current->id ) ); // phpcs:ignore
		return array( 'items' => array_values( array_filter( $rows, array( __CLASS__, 'meeting_visible' ) ) ) );
	}

	public static function meeting_show( WP_REST_Request $r ) {
		$g = self::meeting_guard();
		if ( is_wp_error( $g ) ) {
			return $g;
		}
		$m = self::meeting_row( (int) $r['id'] );
		return $m && self::meeting_visible( $m ) ? self::meeting_out( $m ) : NWC::err( 'Meeting not found.', 404, 'nwc_not_found' );
	}

	/** Writes through the calendar's own table so the meeting shows in the right calendar once. */
	private static function event_fields( $b, $old = null ) {
		$tz  = NWC_Tenancy::$current->timezone;
		$row = array();
		if ( isset( $b['title'] ) || ! $old ) {
			$row['title'] = mb_substr( trim( sanitize_text_field( (string) ( $b['title'] ?? '' ) ) ), 0, 190 );
			if ( '' === $row['title'] ) {
				return NWC::err( 'Give the meeting a title.' );
			}
		}
		if ( isset( $b['starts_at'] ) || ! $old ) {
			$start = NWC::to_utc( (string) ( $b['starts_at'] ?? '' ), $tz );
			if ( ! $start ) {
				return NWC::err( 'Choose a date and time.' );
			}
			$row['starts_at'] = $start;
		}
		if ( isset( $row['starts_at'] ) || isset( $b['duration_min'] ) ) {
			$start = $row['starts_at'] ?? $old->starts_at;
			$mins  = isset( $b['duration_min'] ) ? max( 1, min( 1440, absint( $b['duration_min'] ) ) ) : ( $old && $old->ends_at ? (int) round( ( strtotime( $old->ends_at ) - strtotime( $old->starts_at ) ) / 60 ) : 30 );
			$row['ends_at'] = gmdate( 'Y-m-d H:i:s', strtotime( $start . ' UTC' ) + $mins * 60 );
		}
		if ( isset( $b['location'] ) ) {
			$row['location'] = mb_substr( sanitize_text_field( (string) $b['location'] ), 0, 190 );
		}
		if ( ! $old || isset( $b['project_id'] ) ) {
			$pid = absint( $b['project_id'] ?? 0 );
			if ( $pid ) {
				if ( ! NWC_Projects::can_see( $pid ) ) {
					return NWC::err( 'Project not found.', 404, 'nwc_not_found' );
				}
				$row['calendar']   = 'project';
				$row['project_id'] = $pid;
			} else {
				$row['calendar']   = 'business';
				$row['project_id'] = null;
			}
		}
		return $row;
	}

	private static function set_participants( $event_id, $ids ) {
		global $wpdb;
		$ws = (int) NWC_Tenancy::$current->id;
		$t  = NWC_DB::t( 'event_participants' );
		$wpdb->delete( $t, array( 'event_id' => $event_id, 'workspace_id' => $ws ) );
		foreach ( array_unique( array_map( 'absint', (array) $ids ) ) as $uid ) {
			if ( $uid && NWC_Tenancy::member_row( $ws, $uid ) ) {
				$wpdb->insert( $t, array( 'workspace_id' => $ws, 'event_id' => $event_id, 'user_id' => $uid, 'created_at' => NWC::now() ) );
			}
		}
	}

	public static function meeting_create( WP_REST_Request $r ) {
		global $wpdb;
		$g = self::meeting_guard( true );
		if ( is_wp_error( $g ) ) {
			return $g;
		}
		$b  = NWC_REST::body( $r );
		$ev = self::event_fields( $b );
		if ( is_wp_error( $ev ) ) {
			return $ev;
		}
		$ws  = (int) NWC_Tenancy::$current->id;
		$now = NWC::now();
		$uid = get_current_user_id();
		$wpdb->insert( NWC_DB::t( 'events' ), array_merge( array( 'workspace_id' => $ws, 'owner_id' => $uid, 'event_type' => 'meeting', 'created_at' => $now, 'updated_at' => $now ), $ev ) );
		$eid = (int) $wpdb->insert_id;
		$wpdb->insert( NWC_DB::t( 'meetings' ), array( 'workspace_id' => $ws, 'event_id' => $eid, 'agenda' => sanitize_textarea_field( (string) ( $b['agenda'] ?? '' ) ), 'notes' => sanitize_textarea_field( (string) ( $b['notes'] ?? '' ) ), 'decisions' => sanitize_textarea_field( (string) ( $b['decisions'] ?? '' ) ), 'meeting_url' => esc_url_raw( (string) ( $b['meeting_url'] ?? '' ), array( 'http', 'https' ) ), 'created_by' => $uid, 'created_at' => $now, 'updated_at' => $now ) );
		$id = (int) $wpdb->insert_id;
		self::set_participants( $eid, $b['participants'] ?? array() );
		foreach ( (array) ( $b['participants'] ?? array() ) as $p ) {
			if ( (int) $p !== $uid && NWC_Tenancy::member_row( $ws, (int) $p ) ) {
				NWC_Notify::user( $ws, (int) $p, 'meeting', 'Meeting: ' . $ev['title'], '', '#/meetings/' . $id, 'meet:' . $id . ':' . (int) $p );
			}
		}
		NWC::audit( 'meeting_created', array( 'entity' => 'meeting', 'entity_id' => $id, 'details' => array( 'title' => $ev['title'] ) ) );
		return self::meeting_out( self::meeting_row( $id ) );
	}

	public static function meeting_update( WP_REST_Request $r ) {
		global $wpdb;
		$g = self::meeting_guard( true );
		if ( is_wp_error( $g ) ) {
			return $g;
		}
		$m = self::meeting_row( (int) $r['id'] );
		if ( ! $m || ! self::meeting_visible( $m ) ) {
			return NWC::err( 'Meeting not found.', 404, 'nwc_not_found' );
		}
		$b  = NWC_REST::body( $r );
		$ev = self::event_fields( $b, $m );
		if ( is_wp_error( $ev ) ) {
			return $ev;
		}
		if ( $ev ) {
			$wpdb->update( NWC_DB::t( 'events' ), array_merge( $ev, array( 'updated_at' => NWC::now() ) ), array( 'id' => $m->event_id ) );
		}
		$row = array();
		foreach ( array( 'agenda', 'notes', 'decisions' ) as $k ) {
			if ( isset( $b[ $k ] ) ) {
				$row[ $k ] = sanitize_textarea_field( (string) $b[ $k ] );
			}
		}
		if ( isset( $b['meeting_url'] ) ) {
			$row['meeting_url'] = esc_url_raw( (string) $b['meeting_url'], array( 'http', 'https' ) );
		}
		if ( isset( $b['status'] ) && in_array( $b['status'], array( 'scheduled', 'completed', 'cancelled' ), true ) ) {
			$row['status'] = $b['status'];
		}
		if ( $row ) {
			$wpdb->update( NWC_DB::t( 'meetings' ), array_merge( $row, array( 'updated_at' => NWC::now() ) ), array( 'id' => $m->id ) );
		}
		if ( isset( $b['participants'] ) ) {
			self::set_participants( (int) $m->event_id, $b['participants'] );
		}
		if ( isset( $row['status'] ) && $row['status'] !== $m->status ) {
			NWC::audit( 'meeting_status_changed', array( 'entity' => 'meeting', 'entity_id' => $m->id, 'details' => array( 'from' => $m->status, 'to' => $row['status'] ) ) );
		}
		return self::meeting_out( self::meeting_row( $m->id ) );
	}

	public static function meeting_remove( WP_REST_Request $r ) {
		global $wpdb;
		$g = self::meeting_guard( true );
		if ( is_wp_error( $g ) ) {
			return $g;
		}
		$m = self::meeting_row( (int) $r['id'] );
		if ( ! $m || ! self::meeting_visible( $m ) ) {
			return NWC::err( 'Meeting not found.', 404, 'nwc_not_found' );
		}
		$now = NWC::now();
		$wpdb->update( NWC_DB::t( 'meetings' ), array( 'deleted_at' => $now ), array( 'id' => $m->id ) );
		$wpdb->update( NWC_DB::t( 'events' ), array( 'deleted_at' => $now ), array( 'id' => $m->event_id ) );
		NWC::audit( 'meeting_deleted', array( 'entity' => 'meeting', 'entity_id' => $m->id, 'details' => array( 'title' => $m->title ) ) );
		return array( 'ok' => true );
	}

	/** An action item is a normal task carrying a reference to the meeting. */
	public static function meeting_action( WP_REST_Request $r ) {
		global $wpdb;
		$g = self::meeting_guard( true );
		if ( is_wp_error( $g ) ) {
			return $g;
		}
		$m = self::meeting_row( (int) $r['id'] );
		if ( ! $m || ! self::meeting_visible( $m ) ) {
			return NWC::err( 'Meeting not found.', 404, 'nwc_not_found' );
		}
		$b   = NWC_REST::body( $r );
		$res = NWC_Resources::save( 'tasks', 0, array( 'title' => $b['title'] ?? '', 'assignee_id' => $b['assignee_id'] ?? null, 'due_date' => $b['due_date'] ?? null, 'project_id' => $m->project_id, 'priority' => 'medium', 'status' => 'todo' ) );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		$id = is_object( $res ) ? (int) $res->id : (int) ( $res['id'] ?? 0 );
		$wpdb->update( NWC_DB::t( 'tasks' ), array( 'meeting_id' => $m->id ), array( 'id' => $id, 'workspace_id' => $m->workspace_id ) );
		return self::meeting_out( self::meeting_row( $m->id ) );
	}

	/* ---------------------------- Global search ---------------------------- */

	/** Respects each module's feature gate, role and project visibility. */
	public static function search( WP_REST_Request $r ) {
		global $wpdb;
		$q = trim( sanitize_text_field( (string) $r['q'] ) );
		if ( mb_strlen( $q ) < 2 ) {
			return array( 'items' => array() );
		}
		if ( ! NWC::rate_ok( 'search|' . get_current_user_id(), 120, 60 ) ) {
			return NWC::err( 'Too many searches. Please wait a moment.', 429, 'nwc_rate_limited' );
		}
		$ws   = (int) NWC_Tenancy::$current->id;
		$like = '%' . $wpdb->esc_like( $q ) . '%';
		$out  = array();
		$add  = function ( $type, $rows, $link ) use ( &$out ) {
			foreach ( $rows as $x ) {
				$out[] = array( 'type' => $type, 'id' => (int) $x->id, 'title' => (string) $x->t, 'sub' => isset( $x->s ) ? (string) $x->s : '', 'link' => sprintf( $link, $x->id ) );
			}
		};
		if ( NWC_Features::allowed( 'projects' ) && NWC_Tenancy::can( 'projects.read' ) ) {
			$vals = array( $ws, $like );
			$ex   = NWC_Projects::scope_projects( $vals );
			$add( 'Project', $wpdb->get_results( $wpdb->prepare( 'SELECT id, name t, status s FROM ' . NWC_DB::t( 'projects' ) . ' WHERE workspace_id = %d AND deleted_at IS NULL AND name LIKE %s' . ( $ex ? ' AND ' . $ex : '' ) . ' LIMIT 5', $vals ) ), '#/projects/%d' ); // phpcs:ignore
			$vals = array( $ws, $like );
			$ex   = NWC_Projects::scope_tasks( $vals );
			$add( 'Task', $wpdb->get_results( $wpdb->prepare( 'SELECT id, title t, status s FROM ' . NWC_DB::t( 'tasks' ) . ' WHERE workspace_id = %d AND deleted_at IS NULL AND title LIKE %s' . ( $ex ? ' AND ' . $ex : '' ) . ' LIMIT 5', $vals ) ), '#/tasks?open=%d' ); // phpcs:ignore
		}
		if ( NWC_Features::allowed( 'crm' ) && NWC_Tenancy::can( 'crm.read' ) ) {
			$add( 'Customer', $wpdb->get_results( $wpdb->prepare( 'SELECT id, name t, phone s FROM ' . NWC_DB::t( 'customers' ) . ' WHERE workspace_id = %d AND deleted_at IS NULL AND (name LIKE %s OR email LIKE %s OR phone LIKE %s) LIMIT 5', $ws, $like, $like, $like ) ), '#/crm/customers?open=%d' ); // phpcs:ignore
			$add( 'Lead', $wpdb->get_results( $wpdb->prepare( 'SELECT id, name t, stage s FROM ' . NWC_DB::t( 'leads' ) . ' WHERE workspace_id = %d AND deleted_at IS NULL AND (name LIKE %s OR email LIKE %s OR phone LIKE %s) LIMIT 5', $ws, $like, $like, $like ) ), '#/crm/leads?open=%d' ); // phpcs:ignore
		}
		if ( NWC_Features::allowed( 'invoice_management' ) && NWC_Tenancy::can( 'invoices.read' ) ) {
			$add( 'Invoice', $wpdb->get_results( $wpdb->prepare( 'SELECT id, doc_no t, status s FROM ' . NWC_DB::t( 'biz_invoices' ) . ' WHERE workspace_id = %d AND doc_no LIKE %s ORDER BY id DESC LIMIT 5', $ws, $like ) ), '#/invoices/%d' ); // phpcs:ignore
		}
		if ( NWC_Features::allowed( 'bookings' ) && NWC_Tenancy::can( 'bookings.read' ) ) {
			$add( 'Booking', $wpdb->get_results( $wpdb->prepare( 'SELECT id, customer_name t, status s FROM ' . NWC_DB::t( 'bookings' ) . ' WHERE workspace_id = %d AND deleted_at IS NULL AND (customer_name LIKE %s OR phone LIKE %s OR email LIKE %s) ORDER BY id DESC LIMIT 5', $ws, $like, $like, $like ) ), '#/bookings/list?open=%d' ); // phpcs:ignore
		}
		$vals = array( $ws, $like, $like );
		$ex   = NWC_Resources::scope_notes( $vals );
		$add( 'Note', $wpdb->get_results( $wpdb->prepare( 'SELECT id, title t FROM ' . NWC_DB::t( 'notes' ) . ' WHERE workspace_id = %d AND deleted_at IS NULL AND (title LIKE %s OR body LIKE %s)' . ( $ex ? ' AND ' . $ex : '' ) . ' LIMIT 5', $vals ) ), '#/notes?open=%d' ); // phpcs:ignore
		return array( 'items' => $out );
	}

	/* ---------------------------- Activity ---------------------------- */

	const PER_PAGE = 20;

	/** Built from filters. Everyone sees their workspace's activity kind; the rest is the audit log. */
	private static function activity_query( WP_REST_Request $r, &$vals ) {
		global $wpdb;
		$vals = array( (int) NWC_Tenancy::$current->id );
		$sql  = "workspace_id = %d AND kind = 'activity'";
		if ( $r['action'] ) {
			$sql   .= ' AND action = %s';
			$vals[] = sanitize_key( (string) $r['action'] );
		}
		if ( $r['actor'] ) {
			$sql   .= ' AND user_id = %d';
			$vals[] = absint( $r['actor'] );
		}
		if ( NWC::valid_date( (string) $r['from'] ) ) {
			$sql   .= ' AND created_at >= %s';
			$vals[] = $r['from'] . ' 00:00:00';
		}
		if ( NWC::valid_date( (string) $r['to'] ) ) {
			$sql   .= ' AND created_at <= %s';
			$vals[] = $r['to'] . ' 23:59:59';
		}
		if ( $r['q'] ) {
			$like   = '%' . $wpdb->esc_like( sanitize_text_field( (string) $r['q'] ) ) . '%';
			$sql   .= ' AND (action LIKE %s OR details LIKE %s)';
			$vals[] = $like;
			$vals[] = $like;
		}
		if ( $r['entity'] ) {
			$sql   .= ' AND entity = %s AND entity_id = %d';
			$vals[] = sanitize_key( (string) $r['entity'] );
			$vals[] = absint( $r['entity_id'] );
		}
		return $sql;
	}

	private static function activity_row( $a ) {
		$u = $a->user_id ? get_userdata( $a->user_id ) : null;
		return array( 'id' => (int) $a->id, 'action' => $a->action, 'label' => ucfirst( str_replace( '_', ' ', $a->action ) ), 'actor_id' => (int) $a->user_id, 'actor' => $u ? $u->display_name : ( 'workdesk' === $a->source ? 'NeonDigital' : 'System' ), 'source' => $a->source, 'entity' => $a->entity, 'entity_id' => $a->entity_id ? (int) $a->entity_id : null, 'details' => json_decode( (string) $a->details, true ), 'created_at' => $a->created_at );
	}

	public static function activity( WP_REST_Request $r ) {
		global $wpdb;
		$t    = NWC_DB::t( 'audit' );
		$vals = array();
		$sql  = self::activity_query( $r, $vals );
		$page = max( 1, absint( $r['page'] ) );
		$tot  = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $t WHERE $sql", $vals ) ); // phpcs:ignore
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $t WHERE $sql ORDER BY id DESC LIMIT %d OFFSET %d", array_merge( $vals, array( self::PER_PAGE, ( $page - 1 ) * self::PER_PAGE ) ) ) ); // phpcs:ignore
		$acts = $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT action FROM $t WHERE workspace_id = %d AND kind = 'activity' ORDER BY action LIMIT 100", NWC_Tenancy::$current->id ) ); // phpcs:ignore
		return array( 'items' => array_map( array( __CLASS__, 'activity_row' ), $rows ), 'page' => $page, 'pages' => (int) ceil( $tot / self::PER_PAGE ), 'total' => $tot, 'actions' => $acts, 'can_export' => NWC_Tenancy::can( 'audit.read' ) );
	}

	public static function activity_export( WP_REST_Request $r ) {
		global $wpdb;
		if ( ! NWC_Tenancy::can( 'audit.read' ) ) {
			return NWC::err( 'Your role does not allow this.', 403, 'nwc_forbidden' );
		}
		$vals = array();
		$sql  = self::activity_query( $r, $vals );
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . NWC_DB::t( 'audit' ) . " WHERE $sql ORDER BY id DESC LIMIT 5000", $vals ) ); // phpcs:ignore
		$safe = function ( $v ) {
			$v = (string) $v;
			if ( '' !== $v && in_array( $v[0], array( '=', '+', '-', '@', "\t", "\r" ), true ) ) {
				$v = "'" . $v;
			}
			return '"' . str_replace( '"', '""', $v ) . '"';
		};
		$csv = "\"When (UTC)\",\"Who\",\"Action\",\"Record\",\"Details\"\r\n";
		foreach ( $rows as $a ) {
			$x    = self::activity_row( $a );
			$csv .= implode( ',', array_map( $safe, array( $x['created_at'], $x['actor'], $x['label'], trim( $x['entity'] . ' ' . $x['entity_id'] ), wp_json_encode( $x['details'] ) ) ) ) . "\r\n";
		}
		NWC::audit( 'activity_exported', array( 'kind' => 'admin', 'details' => array( 'rows' => count( $rows ) ) ) );
		return array( 'filename' => 'activity.csv', 'csv' => $csv );
	}

	/** Only the user-facing activity kind. Security, billing and admin records are never purged here. */
	public static function purge_old_activity() {
		global $wpdb;
		$days = (int) NWC::settings()['activity_retention_days'];
		if ( $days < 30 ) {
			return 0;
		}
		return (int) $wpdb->query( $wpdb->prepare( 'DELETE FROM ' . NWC_DB::t( 'audit' ) . " WHERE kind = 'activity' AND created_at < %s", gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS ) ) ); // phpcs:ignore
	}

	/* ---------------------------- Reports ---------------------------- */

	public static function reports( WP_REST_Request $r ) {
		global $wpdb;
		$g = NWC_Features::guard( 'reports' );
		if ( is_wp_error( $g ) ) {
			return $g;
		}
		if ( ! NWC_Tenancy::can( 'reports.read' ) ) {
			return NWC::err( 'Your role does not allow this.', 403, 'nwc_forbidden' );
		}
		$w    = NWC_Tenancy::$current;
		$ws   = (int) $w->id;
		$to   = NWC::valid_date( (string) $r['to'] ) ? (string) $r['to'] : NWC::today( $w->timezone );
		$from = NWC::valid_date( (string) $r['from'] ) ? (string) $r['from'] : gmdate( 'Y-m-01', strtotime( $to ) );
		$end  = $to . ' 23:59:59';
		$out  = array( 'from' => $from, 'to' => $to );
		if ( NWC_Features::allowed( 'bookings' ) ) {
			$out['bookings'] = $wpdb->get_results( $wpdb->prepare( 'SELECT status, COUNT(*) n FROM ' . NWC_DB::t( 'bookings' ) . ' WHERE workspace_id = %d AND deleted_at IS NULL AND starts_at BETWEEN %s AND %s GROUP BY status', $ws, $from, $end ) ); // phpcs:ignore
		}
		if ( NWC_Features::allowed( 'crm' ) ) {
			$out['leads'] = $wpdb->get_results( $wpdb->prepare( 'SELECT stage, COUNT(*) n FROM ' . NWC_DB::t( 'leads' ) . ' WHERE workspace_id = %d AND deleted_at IS NULL AND created_at BETWEEN %s AND %s GROUP BY stage', $ws, $from, $end ) ); // phpcs:ignore
		}
		if ( NWC_Features::allowed( 'projects' ) ) {
			$out['tasks'] = $wpdb->get_results( $wpdb->prepare( 'SELECT status, COUNT(*) n FROM ' . NWC_DB::t( 'tasks' ) . ' WHERE workspace_id = %d AND deleted_at IS NULL AND created_at BETWEEN %s AND %s GROUP BY status', $ws, $from, $end ) ); // phpcs:ignore
		}
		if ( NWC_Features::allowed( 'invoice_management' ) && NWC_Tenancy::can( 'invoices.read' ) ) {
			$out['invoiced'] = (float) $wpdb->get_var( $wpdb->prepare( 'SELECT COALESCE(SUM(grand_total),0) FROM ' . NWC_DB::t( 'biz_invoices' ) . " WHERE workspace_id = %d AND status NOT IN ('draft','cancelled') AND invoice_date BETWEEN %s AND %s", $ws, $from, $to ) ); // phpcs:ignore
			$out['received'] = (float) $wpdb->get_var( $wpdb->prepare( 'SELECT COALESCE(SUM(amount),0) FROM ' . NWC_DB::t( 'biz_payments' ) . ' WHERE workspace_id = %d AND ' . NWC_Finance::REAL_PAYMENT . ' AND paid_on BETWEEN %s AND %s', $ws, $from, $to ) ); // phpcs:ignore
		}
		return $out;
	}
}
