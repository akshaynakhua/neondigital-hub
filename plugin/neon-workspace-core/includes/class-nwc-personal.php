<?php
/**
 * The Personal area: one per person, selected by default after sign-in.
 *
 * It is a real workspace row (account_type 'personal') so personal calendar, planner, notes and
 * goals keep working through the normal tenancy rules. It has no plan, no team, no billing and is
 * never synced to WorkDesk.
 *
 * The consolidated views read every Workspace the person belongs to, one at a time, each bound
 * through NWC_Tenancy so that Workspace's own role and feature rules decide what is returned.
 * Nothing is ever copied into the Personal area.
 */

defined( 'ABSPATH' ) || exit;

class NWC_Personal {

	/** Modules the Personal area can open. Everything else stays off there. */
	const FEATURES = array( 'calendar_personal', 'daily_planner', 'projects' );

	public static function routes() {
		NWC_REST::route( '/personal', 'GET', array( __CLASS__, 'rest_get' ), 'user' );
		NWC_REST::route( '/personal/overview', 'GET', array( __CLASS__, 'rest_overview' ), 'user' );
	}

	public static function is_personal( $w ) {
		return $w && 'personal' === $w->account_type;
	}

	/** User meta set when someone becomes a NeonDigital agency client (invitation accepted or Workspace provisioned). */
	const AGENCY_CLIENT_META = 'nwc_agency_client';

	/**
	 * Phase 5: the Personal Workspace is a free benefit for NeonDigital agency clients only, never a
	 * hidden entitlement of every account. Eligible: the person owns, or was made the client of, an
	 * agency-managed Workspace. Not eligible: invited team members (they hold another role, never
	 * owner) and Workspace-only subscribers (their Workspaces are 'independent').
	 */
	public static function eligible( $user_id ) {
		global $wpdb;
		$user_id = (int) $user_id;
		if ( ! $user_id ) {
			return false;
		}
		if ( get_user_meta( $user_id, self::AGENCY_CLIENT_META, true ) ) {
			return true;
		}
		$own = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . NWC_DB::t( 'members' ) . ' m JOIN ' . NWC_DB::t( 'workspaces' ) . " w ON w.id = m.workspace_id WHERE m.user_id = %d AND m.role = 'owner' AND m.status = 'active' AND w.account_type = 'agency' AND w.status IN ('active','suspended')", $user_id ) ); // phpcs:ignore
		if ( $own ) {
			update_user_meta( $user_id, self::AGENCY_CLIENT_META, 1 );
			return true;
		}
		return false;
	}

	/** Called when a person accepts a NeonDigital invitation or WorkDesk provisions a Workspace for them. */
	public static function mark_agency_client( $user_id ) {
		if ( (int) $user_id ) {
			update_user_meta( (int) $user_id, self::AGENCY_CLIENT_META, 1 );
		}
	}

	/**
	 * The person's Personal area, created on first use for an eligible agency client. Returns null for
	 * everyone else. An earlier Personal area of a person who is not eligible is kept untouched in the
	 * database (nothing is deleted) but cannot be opened.
	 */
	public static function ensure( $user_id ) {
		global $wpdb;
		$user_id = (int) $user_id;
		if ( ! $user_id || ! self::eligible( $user_id ) ) {
			return null;
		}
		$find = function () use ( $wpdb, $user_id ) {
			return $wpdb->get_row( $wpdb->prepare( 'SELECT w.* FROM ' . NWC_DB::t( 'workspaces' ) . ' w JOIN ' . NWC_DB::t( 'members' ) . " m ON m.workspace_id = w.id WHERE w.account_type = 'personal' AND w.created_by = %d AND m.user_id = %d AND m.role = 'owner' ORDER BY w.id ASC LIMIT 1", $user_id, $user_id ) ); // phpcs:ignore
		};
		$have = $find();
		if ( $have ) {
			return $have;
		}
		if ( ! NWC::lock( 'personal:' . $user_id ) ) {
			return $find();
		}
		try {
			$have = $find();
			if ( $have ) {
				return $have;
			}
			$tz = (string) $wpdb->get_var( $wpdb->prepare( 'SELECT w.timezone FROM ' . NWC_DB::t( 'members' ) . ' m JOIN ' . NWC_DB::t( 'workspaces' ) . " w ON w.id = m.workspace_id WHERE m.user_id = %d AND w.account_type <> 'personal' ORDER BY w.id ASC LIMIT 1", $user_id ) ); // phpcs:ignore
			$now = NWC::now();
			$wpdb->insert( NWC_DB::t( 'workspaces' ), array(
				'uid'          => 'pa_' . bin2hex( random_bytes( 12 ) ),
				'name'         => 'Personal',
				'slug'         => 'personal-' . $user_id . '-' . strtolower( wp_generate_password( 6, false, false ) ),
				'account_type' => 'personal',
				'kind'         => 'personal',
				'timezone'     => in_array( $tz, timezone_identifiers_list(), true ) ? $tz : 'Asia/Kolkata',
				'created_by'   => $user_id,
				'created_at'   => $now,
				'updated_at'   => $now,
			) );
			$id = (int) $wpdb->insert_id;
			if ( ! $id ) {
				return null;
			}
			$wpdb->insert( NWC_DB::t( 'members' ), array( 'workspace_id' => $id, 'user_id' => $user_id, 'role' => 'owner', 'joined_at' => $now ) );
			return NWC_Tenancy::workspace( $id );
		} finally {
			NWC::unlock( 'personal:' . $user_id );
		}
	}

	public static function summary( $w ) {
		return $w ? array( 'id' => (int) $w->id, 'uid' => $w->uid, 'name' => 'Personal', 'account_type' => 'personal', 'kind' => 'personal', 'timezone' => $w->timezone, 'role' => 'owner' ) : null;
	}

	public static function rest_get() {
		return self::summary( self::ensure( get_current_user_id() ) );
	}

	/** Feature rows for the Personal area: its own small set, never a plan. */
	public static function features() {
		$out = array();
		foreach ( NWC_Features::CATALOG as $code => $label ) {
			$on           = in_array( $code, self::FEATURES, true );
			$out[ $code ] = array( 'feature_code' => $code, 'label' => $label, 'source' => 'personal', 'enabled' => $on, 'state' => $on ? 'active' : 'disabled', 'starts_on' => null, 'expires_on' => null, 'grace_days' => 0, 'usage_limit' => null, 'coming_soon' => false, 'allowed' => $on, 'cta' => $on ? '' : 'Open a Workspace to use this', 'personal_hidden' => ! $on );
		}
		return $out;
	}

	/**
	 * Every Workspace this person can open, Personal excluded. Status 'active' only: a deactivated
	 * Workspace keeps its data but stops showing up.
	 */
	public static function memberships( $user_id ) {
		return NWC_Tenancy::for_user( $user_id );
	}

	/** Runs $fn inside one workspace with that membership's role, then restores the request. */
	public static function within( $workspace_id, $user_id, $fn ) {
		$prev_w = NWC_Tenancy::$current;
		$prev_m = NWC_Tenancy::$member;
		$out    = null;
		if ( NWC_Tenancy::bind( (int) $workspace_id, (int) $user_id ) ) {
			$out = $fn( NWC_Tenancy::$current, NWC_Tenancy::$member );
		}
		NWC_Tenancy::$current = $prev_w;
		NWC_Tenancy::$member  = $prev_m;
		return $out;
	}

	private static function clean_filters( WP_REST_Request $r, $tz ) {
		$today = NWC::today( $tz );
		$from  = (string) $r->get_param( 'from' );
		$to    = (string) $r->get_param( 'to' );
		$from  = NWC::valid_date( $from ) ? $from : $today;
		$to    = NWC::valid_date( $to ) ? $to : gmdate( 'Y-m-d', strtotime( $from . ' 12:00:00 UTC' ) + 13 * DAY_IN_SECONDS );
		if ( $to < $from ) {
			$to = $from;
		}
		if ( strtotime( $to ) - strtotime( $from ) > 120 * DAY_IN_SECONDS ) {
			$to = gmdate( 'Y-m-d', strtotime( $from . ' 12:00:00 UTC' ) + 120 * DAY_IN_SECONDS );
		}
		$type  = sanitize_key( (string) $r->get_param( 'type' ) );
		$proj  = (string) $r->get_param( 'proj' );
		$scope = sanitize_key( (string) $r->get_param( 'scope' ) );
		return array(
			'workspace'  => absint( $r->get_param( 'workspace' ) ),
			'project'    => absint( $r->get_param( 'project' ) ),
			// Phase 5: one key for a main project or sub-project, local ("p12") or NeonDigital ("a34").
			'proj'       => preg_match( '/^[pa]\d{1,12}$/', $proj ) ? $proj : '',
			'event_type' => mb_substr( sanitize_key( (string) $r->get_param( 'event_type' ) ), 0, 30 ),
			'scope'      => in_array( $scope, array( 'personal', 'business' ), true ) ? $scope : '',
			'q'          => mb_substr( trim( sanitize_text_field( (string) $r->get_param( 'q' ) ) ), 0, 80 ),
			'sort'       => 'desc' === $r->get_param( 'sort' ) ? 'desc' : 'asc',
			'status'     => sanitize_key( (string) $r->get_param( 'status' ) ),
			'type'       => in_array( $type, array( 'tasks', 'events', 'bookings', 'projects' ), true ) ? $type : '',
			'from'       => $from,
			'to'         => $to,
		);
	}

	const EVENT_TYPES = array( 'event' => 'Event', 'meeting' => 'Meeting', 'deadline' => 'Deadline', 'deliverable' => 'Deliverable', 'task' => 'Task due', 'milestone' => 'Milestone', 'registration' => 'Registration event', 'personal' => 'Personal' );

	/**
	 * Names the project and sub-project an item belongs to and the record it opens. Local projects
	 * are keyed "p<id>", NeonDigital (WorkDesk) projects "a<id>".
	 */
	private static function tag_event( $e, $w, &$cache ) {
		global $wpdb;
		$pk   = '';
		$pn   = '';
		$rk   = '';
		$rn   = '';
		$link = '#/calendar';
		if ( 'agency' === ( $e['source'] ?? '' ) ) {
			$type = (string) ( $e['type'] ?? '' );
			if ( ! empty( $e['project_id'] ) ) {
				$pk   = 'a' . (int) $e['project_id'];
				$pn   = (string) ( $e['project_name'] ?? '' );
				$rk   = ! empty( $e['parent_id'] ) ? 'a' . (int) $e['parent_id'] : '';
				$rn   = (string) ( $e['parent_name'] ?? '' );
				$link = '#/agency/projects/' . (int) $e['project_id'];
			} else {
				$link = '#/agency';
			}
		} else {
			$type = in_array( $e['source'] ?? '', array( 'task', 'milestone', 'registration' ), true ) ? $e['source'] : ( 'personal' === ( $e['calendar'] ?? '' ) ? 'personal' : (string) ( $e['type'] ?? 'event' ) );
			$pid  = 0 === strpos( (string) ( $e['calendar'] ?? '' ), 'project:' ) ? (int) substr( $e['calendar'], 8 ) : 0;
			if ( $pid ) {
				if ( ! isset( $cache[ $pid ] ) ) {
					$p   = $wpdb->get_row( $wpdb->prepare( 'SELECT id, name, parent_id FROM ' . NWC_DB::t( 'projects' ) . ' WHERE id = %d AND workspace_id = %d', $pid, $w->id ) ); // phpcs:ignore
					$par = $p && $p->parent_id ? $wpdb->get_row( $wpdb->prepare( 'SELECT id, name FROM ' . NWC_DB::t( 'projects' ) . ' WHERE id = %d AND workspace_id = %d', $p->parent_id, $w->id ) ) : null; // phpcs:ignore
					$cache[ $pid ] = array( $p ? (string) $p->name : '', $par ? (int) $par->id : 0, $par ? (string) $par->name : '' );
				}
				$pk   = 'p' . $pid;
				$pn   = $cache[ $pid ][0];
				$rk   = $cache[ $pid ][1] ? 'p' . $cache[ $pid ][1] : '';
				$rn   = $cache[ $pid ][2];
				$link = 'task' === $type ? '#/projects/' . $pid . '?task=' . (int) ( $e['ref_id'] ?? 0 ) : '#/projects/' . $pid;
			}
		}
		$type = '' === $type ? 'event' : sanitize_key( $type );
		return array( 'id' => $e['id'], 'title' => $e['title'], 'source' => $e['source'], 'calendar' => $e['calendar'], 'start' => $e['start'], 'end' => $e['end'], 'all_day' => $e['all_day'], 'event_type' => $type, 'event_type_label' => self::EVENT_TYPES[ $type ] ?? ucfirst( str_replace( '_', ' ', $type ) ), 'project_key' => $pk, 'project_name' => $pn, 'parent_key' => $rk, 'parent_name' => $rn, 'link' => $link );
	}

	/**
	 * GET /personal/overview?workspace=&project=&proj=&event_type=&scope=&q=&sort=&status=&type=&from=&to=
	 *
	 * @return array {filters, workspaces[], tasks[], events[], bookings[], projects[], counts}
	 */
	public static function rest_overview( WP_REST_Request $r ) {
		global $wpdb;
		$uid  = get_current_user_id();
		$home = self::ensure( $uid );
		if ( ! $home ) {
			return NWC::err( 'The Personal Workspace and its consolidated view are for NeonDigital agency clients.', 403, 'nwc_no_personal' );
		}
		$f    = self::clean_filters( $r, $home->timezone );
		$want = function ( $t ) use ( $f ) {
			return '' === $f['type'] || $t === $f['type'];
		};
		$all  = self::memberships( $uid );
		$list = array_merge( array( (object) array( 'id' => $home->id, 'name' => 'Personal', 'account_type' => 'personal', 'role' => 'owner' ) ), $all );
		$out  = array( 'filters' => $f, 'workspaces' => array(), 'tasks' => array(), 'events' => array(), 'bookings' => array(), 'projects' => array() );
		$nt   = NWC_DB::t( 'notifications' );
		foreach ( $list as $m ) {
			$wid = (int) $m->id;
			$one = self::within( $wid, $uid, function ( $w, $member ) use ( $wpdb, $f, $want, $uid, $nt, &$out ) {
				$wid    = (int) $w->id;
				$name   = self::is_personal( $w ) ? 'Personal' : $w->name;
				$tag    = array( 'workspace_id' => $wid, 'workspace_name' => $name, 'scope' => self::is_personal( $w ) ? 'personal' : 'business' );
				$picked = ( ! $f['workspace'] || $f['workspace'] === $wid ) && ( ! $f['scope'] || $f['scope'] === $tag['scope'] );
				$card   = array(
					'id'           => $wid,
					'name'         => $name,
					'account_type' => $w->account_type,
					'role'         => $member->role,
					'role_label'   => NWC_Tenancy::ROLES[ $member->role ] ?? $member->role,
					'unread'       => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $nt WHERE user_id = %d AND workspace_id = %d AND read_at IS NULL", $uid, $wid ) ), // phpcs:ignore
					'open_tasks'   => 0,
					'bookings_today' => 0,
					'subscription' => self::is_personal( $w ) ? null : NWC_Signup::subscription_state( $w ),
				);
				$features = self::is_personal( $w ) ? self::features() : NWC_Features::resolve( $w );
				$has      = function ( $code ) use ( $features ) {
					return ! empty( $features[ $code ]['allowed'] );
				};
				// Tasks: the ones assigned to this person, inside projects they may see.
				if ( $has( 'projects' ) && NWC_Tenancy::can( 'projects.read' ) ) {
					$vals = array( $wid, $uid );
					$sql  = 'SELECT t.id, t.title, t.status, t.priority, t.due_date, t.project_id, p.name AS project_name FROM ' . NWC_DB::t( 'tasks' ) . ' t LEFT JOIN ' . NWC_DB::t( 'projects' ) . ' p ON p.id = t.project_id WHERE t.workspace_id = %d AND t.deleted_at IS NULL AND t.assignee_id = %d';
					$card['open_tasks'] = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . NWC_DB::t( 'tasks' ) . " WHERE workspace_id = %d AND deleted_at IS NULL AND assignee_id = %d AND status <> 'done'", $wid, $uid ) ); // phpcs:ignore
					if ( $picked && $want( 'tasks' ) ) {
						if ( $f['project'] ) {
							$sql   .= ' AND t.project_id = %d';
							$vals[] = $f['project'];
						}
						if ( in_array( $f['status'], array( 'todo', 'in_progress', 'review', 'done' ), true ) ) {
							$sql   .= ' AND t.status = %s';
							$vals[] = $f['status'];
						} elseif ( 'open' === $f['status'] || '' === $f['status'] ) {
							$sql .= " AND t.status <> 'done'";
						}
						$sql .= ' ORDER BY t.due_date IS NULL, t.due_date ASC, t.id DESC LIMIT 100';
						// Same rule as the Workspace's own task list: a task assigned to you is yours to see.
						foreach ( $wpdb->get_results( $wpdb->prepare( $sql, $vals ) ) as $t ) { // phpcs:ignore
							$out['tasks'][] = array_merge( $tag, array( 'id' => (int) $t->id, 'title' => $t->title, 'status' => $t->status, 'priority' => $t->priority, 'due_date' => $t->due_date, 'project_id' => $t->project_id ? (int) $t->project_id : null, 'project_name' => (string) $t->project_name, 'link' => $t->project_id && NWC_Projects::can_see( (int) $t->project_id ) ? '/projects/' . (int) $t->project_id . '?task=' . (int) $t->id : '/tasks?task=' . (int) $t->id ) );
						}
					}
				}
				// Projects this person may see.
				if ( $picked && $want( 'projects' ) && $has( 'projects' ) && NWC_Tenancy::can( 'projects.read' ) ) {
					$vals = array( $wid );
					$sql  = 'SELECT id, name, status, end_date FROM ' . NWC_DB::t( 'projects' ) . ' WHERE workspace_id = %d AND deleted_at IS NULL AND parent_id IS NULL';
					if ( in_array( $f['status'], array( 'active', 'completed', 'archived' ), true ) ) {
						$sql   .= ' AND status = %s';
						$vals[] = $f['status'];
					}
					if ( $f['project'] ) {
						$sql   .= ' AND id = %d';
						$vals[] = $f['project'];
					}
					foreach ( $wpdb->get_results( $wpdb->prepare( $sql . ' ORDER BY name ASC LIMIT 100', $vals ) ) as $p ) { // phpcs:ignore
						if ( NWC_Projects::can_see( (int) $p->id ) ) {
							$out['projects'][] = array_merge( $tag, array( 'id' => (int) $p->id, 'name' => $p->name, 'status' => $p->status, 'end_date' => $p->end_date, 'link' => '/projects/' . (int) $p->id ) );
						}
					}
				}
				// Bookings in the date range, where the role may read them.
				if ( $has( 'bookings' ) && NWC_Tenancy::can( 'bookings.read' ) ) {
					$tz    = new DateTimeZone( $w->timezone );
					$day0  = ( new DateTimeImmutable( NWC::today( $w->timezone ) . ' 00:00:00', $tz ) )->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );
					$day1  = ( new DateTimeImmutable( NWC::today( $w->timezone ) . ' 23:59:59', $tz ) )->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );
					$card['bookings_today'] = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . NWC_DB::t( 'bookings' ) . " WHERE workspace_id = %d AND deleted_at IS NULL AND status IN ('pending','confirmed') AND starts_at BETWEEN %s AND %s", $wid, $day0, $day1 ) ); // phpcs:ignore
					if ( $picked && $want( 'bookings' ) && ! $f['project'] ) {
						$a    = ( new DateTimeImmutable( $f['from'] . ' 00:00:00', $tz ) )->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );
						$b    = ( new DateTimeImmutable( $f['to'] . ' 23:59:59', $tz ) )->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );
						$vals = array( $wid, $a, $b );
						$sql  = 'SELECT b.id, b.starts_at, b.ends_at, b.status, b.customer_name, s.name AS service FROM ' . NWC_DB::t( 'bookings' ) . ' b LEFT JOIN ' . NWC_DB::t( 'services' ) . ' s ON s.id = b.service_id WHERE b.workspace_id = %d AND b.deleted_at IS NULL AND b.starts_at BETWEEN %s AND %s';
						if ( in_array( $f['status'], array( 'pending', 'confirmed', 'completed', 'cancelled', 'no_show' ), true ) ) {
							$sql   .= ' AND b.status = %s';
							$vals[] = $f['status'];
						} else {
							$sql .= " AND b.status <> 'cancelled'";
						}
						foreach ( $wpdb->get_results( $wpdb->prepare( $sql . ' ORDER BY b.starts_at ASC LIMIT 100', $vals ) ) as $x ) { // phpcs:ignore
							$out['bookings'][] = array_merge( $tag, array( 'id' => (int) $x->id, 'title' => $x->customer_name . ( $x->service ? ' · ' . $x->service : '' ), 'status' => $x->status, 'start' => gmdate( 'Y-m-d\TH:i:s\Z', strtotime( $x->starts_at . ' UTC' ) ), 'end' => gmdate( 'Y-m-d\TH:i:s\Z', strtotime( $x->ends_at . ' UTC' ) ), 'link' => '/bookings/list?open=' . (int) $x->id ) );
						}
					}
				}
				// Calendar: the same feed the Workspace's own calendar uses, so the same rules apply. Every
				// item says which Workspace, main project and sub-project it came from, and links there.
				if ( $picked && $want( 'events' ) && ! $f['status'] ) {
					$req = new WP_REST_Request( 'GET', '/nwc/v1/calendar' );
					$req->set_param( 'from', $f['from'] );
					$req->set_param( 'to', $f['to'] );
					$req->set_param( 'calendars', self::is_personal( $w ) ? 'personal' : '' );
					$feed = NWC_Calendar::feed( $req );
					if ( ! is_wp_error( $feed ) ) {
						$local = array();
						foreach ( (array) ( $feed['items'] ?? array() ) as $e ) {
							if ( 'booking' === $e['source'] ) {
								continue; // Listed under bookings.
							}
							if ( 'personal' === ( $e['calendar'] ?? '' ) && ! self::is_personal( $w ) ) {
								continue; // Private Personal events live only in Personal.
							}
							$ev = self::tag_event( $e, $w, $local );
							if ( $f['project'] && ( $e['calendar'] ?? '' ) !== 'project:' . $f['project'] ) {
								continue;
							}
							if ( $f['proj'] && $f['proj'] !== $ev['project_key'] && $f['proj'] !== $ev['parent_key'] ) {
								continue;
							}
							if ( '' !== $f['event_type'] && $f['event_type'] !== $ev['event_type'] ) {
								continue;
							}
							if ( '' !== $f['q'] && false === mb_stripos( $ev['title'] . ' ' . $ev['project_name'] . ' ' . $ev['parent_name'] . ' ' . $name, $f['q'] ) ) {
								continue;
							}
							$out['events'][] = array_merge( $tag, $ev );
						}
					}
				}
				return $card;
			} );
			if ( $one ) {
				$out['workspaces'][] = $one;
			}
		}
		$dir = 'desc' === $f['sort'] ? -1 : 1;
		usort( $out['events'], function ( $a, $b ) use ( $dir ) {
			return $dir * strcmp( $a['start'], $b['start'] );
		} );
		// Facets for the filters: the projects and event types actually present.
		$facets = array( 'projects' => array(), 'event_types' => array() );
		foreach ( $out['events'] as $e ) {
			if ( $e['project_key'] && ! isset( $facets['projects'][ $e['project_key'] ] ) ) {
				$facets['projects'][ $e['project_key'] ] = array( 'key' => $e['project_key'], 'name' => $e['project_name'], 'parent_key' => $e['parent_key'], 'parent_name' => $e['parent_name'], 'workspace_id' => $e['workspace_id'], 'workspace_name' => $e['workspace_name'] );
			}
			if ( $e['parent_key'] && ! isset( $facets['projects'][ $e['parent_key'] ] ) ) {
				$facets['projects'][ $e['parent_key'] ] = array( 'key' => $e['parent_key'], 'name' => $e['parent_name'], 'parent_key' => '', 'parent_name' => '', 'workspace_id' => $e['workspace_id'], 'workspace_name' => $e['workspace_name'] );
			}
			$facets['event_types'][ $e['event_type'] ] = $e['event_type_label'];
		}
		$out['facets'] = array( 'projects' => array_values( $facets['projects'] ), 'event_types' => $facets['event_types'] );
		usort( $out['bookings'], function ( $a, $b ) {
			return strcmp( $a['start'], $b['start'] );
		} );
		usort( $out['tasks'], function ( $a, $b ) {
			return strcmp( $a['due_date'] ? $a['due_date'] : '9999', $b['due_date'] ? $b['due_date'] : '9999' );
		} );
		$out['events'] = array_slice( $out['events'], 0, 200 );
		$out['counts'] = array( 'tasks' => count( $out['tasks'] ), 'events' => count( $out['events'] ), 'bookings' => count( $out['bookings'] ), 'projects' => count( $out['projects'] ) );
		return $out;
	}
}
