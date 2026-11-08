<?php
/**
 * One tenant-scoped CRUD layer for simple records. Each resource declares its fields, feature and
 * permissions, so workspace scoping, ownership checks on references, sanitising, soft deletes and
 * audit entries live in one place rather than being repeated per module.
 *
 * Field types: text:N, long, int, money, date, datetime, bool, enum:a|b, email, url, color, json,
 *              fk:<resource> (must exist in THIS workspace), user (must be a member of it).
 */

defined( 'ABSPATH' ) || exit;

class NWC_Resources {

	public static function defs() {
		return array(
			'projects'   => array(
				'table' => 'projects', 'feature' => 'projects', 'read' => 'projects.read', 'write' => 'projects.write', 'limit' => 'projects',
				'fields' => array( 'parent_id' => 'fk:projects', 'name' => 'text:190', 'description' => 'long', 'status' => 'enum:active|completed|archived', 'color' => 'color', 'start_date' => 'date', 'end_date' => 'date' ),
				'required' => array( 'name' ), 'search' => array( 'name', 'description' ), 'filters' => array( 'status', 'parent_id' ), 'order' => 'id DESC', 'owner_col' => 'created_by',
				'guard_write' => array( 'NWC_Projects', 'guard_project' ), 'scope' => array( 'NWC_Projects', 'scope_projects' ), 'after' => array( 'NWC_Projects', 'after_project' ), 'decorate' => array( 'NWC_Projects', 'decorate_project' ),
			),
			'tasks'      => array(
				'table' => 'tasks', 'feature' => 'projects', 'read' => 'projects.read', 'write' => 'tasks.write',
				'fields' => array( 'project_id' => 'fk:projects', 'title' => 'text:255', 'description' => 'long', 'status' => 'enum:todo|in_progress|review|done', 'priority' => 'enum:low|medium|high|urgent', 'assignee_id' => 'user', 'due_date' => 'date', 'checklist' => 'json' ),
				'required' => array( 'title' ), 'search' => array( 'title' ), 'filters' => array( 'status', 'project_id', 'assignee_id', 'priority' ), 'order' => 'due_date IS NULL, due_date ASC, id DESC', 'owner_col' => 'created_by',
				'scope' => array( 'NWC_Projects', 'scope_tasks' ), 'guard_write' => array( 'NWC_Projects', 'guard_task' ), 'after' => array( 'NWC_Projects', 'after_task' ),
			),
			'milestones' => array(
				'table' => 'milestones', 'feature' => 'projects', 'read' => 'projects.read', 'write' => 'projects.write',
				'fields' => array( 'project_id' => 'fk:projects', 'title' => 'text:190', 'due_date' => 'date', 'done' => 'bool' ),
				'required' => array( 'project_id', 'title' ), 'filters' => array( 'project_id' ), 'order' => 'due_date ASC, id ASC', 'owner_col' => 'created_by',
				'scope' => array( 'NWC_Projects', 'scope_children' ),
			),
			'customers'  => array(
				'table' => 'customers', 'feature' => 'crm', 'read' => 'crm.read', 'write' => 'crm.write',
				'fields' => array( 'name' => 'text:190', 'business_name' => 'text:190', 'contact_person' => 'text:190', 'phone' => 'text:40', 'email' => 'email', 'billing_address' => 'long', 'shipping_address' => 'long', 'gstin' => 'text:20', 'state_code' => 'text:4', 'notes' => 'long', 'source' => 'text:60', 'assigned_to' => 'user', 'marketing_consent' => 'bool', 'custom' => 'json' ),
				'required' => array( 'name' ), 'search' => array( 'name', 'business_name', 'email', 'phone' ), 'filters' => array( 'assigned_to', 'source' ), 'order' => 'name ASC', 'owner_col' => 'created_by', 'audit' => true,
				'before' => array( 'NWC_CRM', 'before_contact' ),
			),
			'leads'      => array(
				'table' => 'leads', 'feature' => 'crm', 'read' => 'crm.read', 'write' => 'crm.write',
				'fields' => array( 'customer_id' => 'fk:customers', 'name' => 'text:190', 'phone' => 'text:40', 'email' => 'email', 'enquiry' => 'text:255', 'lead_type' => 'text:40', 'source' => 'text:60', 'stage' => 'enum:new|contacted|interested|converted|lost', 'follow_up_on' => 'date', 'assigned_to' => 'user', 'notes' => 'long', 'marketing_consent' => 'bool', 'custom' => 'json' ),
				'required' => array( 'name' ), 'search' => array( 'name', 'email', 'phone', 'enquiry' ), 'filters' => array( 'stage', 'source', 'assigned_to' ), 'order' => 'id DESC', 'owner_col' => 'created_by', 'audit' => true,
				'before' => array( 'NWC_CRM', 'before_contact' ), 'after' => array( 'NWC_CRM', 'after_lead' ),
			),
			'services'   => array(
				'table' => 'services', 'feature' => 'bookings', 'read' => 'bookings.read', 'write' => 'bookings.manage',
				'fields' => array( 'name' => 'text:190', 'description' => 'long', 'type_key' => 'text:60', 'type_custom' => 'text:120', 'category' => 'text:60', 'duration_min' => 'int', 'price' => 'money', 'capacity' => 'int', 'staff_ids' => 'json', 'is_active' => 'bool', 'space_ids' => 'json', 'setup_min' => 'int', 'cleanup_min' => 'int', 'booking_kind' => 'text:30', 'waitlist' => 'bool', 'image_url' => 'url', 'pay_mode' => 'enum:venue|online' ),
				'required' => array( 'name', 'duration_min' ), 'search' => array( 'name' ), 'order' => 'name ASC',
				'before' => array( 'NWC_Bookings', 'before_service' ), 'decorate' => array( 'NWC_Bookings', 'decorate_service' ),
			),
			'spaces'     => array(
				'table' => 'spaces', 'feature' => 'bookings', 'read' => 'bookings.read', 'write' => 'bookings.manage',
				'fields' => array( 'name' => 'text:190', 'kind' => 'enum:room|studio|hall|chair|station|court|table|other', 'description' => 'long', 'capacity' => 'int', 'color' => 'color', 'is_active' => 'bool', 'sort' => 'int' ),
				'required' => array( 'name' ), 'search' => array( 'name' ), 'order' => 'sort ASC, name ASC', 'audit' => true,
			),
			'availability' => array(
				'table' => 'availability', 'feature' => 'bookings', 'read' => 'bookings.read', 'write' => 'bookings.manage',
				'fields' => array( 'label' => 'text:120', 'weekdays' => 'json', 'start_time' => 'text:5', 'end_time' => 'text:5', 'slot_min' => 'int', 'start_date' => 'date', 'end_date' => 'date', 'service_ids' => 'json', 'space_id' => 'fk:spaces', 'staff_id' => 'user', 'is_active' => 'bool' ),
				'required' => array( 'start_time', 'end_time' ), 'order' => 'start_date IS NULL DESC, start_date ASC, start_time ASC, id ASC', 'owner_col' => 'created_by', 'filters' => array( 'space_id', 'staff_id' ),
				'before' => array( 'NWC_Bookings', 'before_availability' ),
			),
			'booking_pages' => array(
				'table' => 'booking_pages', 'feature' => 'bookings', 'read' => 'bookings.read', 'write' => 'bookings.manage',
				'fields' => array( 'slug' => 'text:120', 'title' => 'text:190', 'intro' => 'long', 'service_ids' => 'json', 'min_notice_hours' => 'int', 'max_days_ahead' => 'int', 'allow_reschedule' => 'bool', 'is_active' => 'bool', 'page_type' => 'enum:all|service|class|instructor|workshop|rental', 'staff_id' => 'user', 'space_ids' => 'json', 'design' => 'json', 'fields' => 'json', 'status' => 'enum:draft|published' ),
				'required' => array( 'title' ), 'order' => 'is_default DESC, id ASC',
				'before' => array( 'NWC_Bookings', 'before_page' ), 'decorate' => array( 'NWC_Bookings', 'decorate_page' ), 'guard_delete' => array( 'NWC_Bookings', 'guard_page_delete' ),
			),
			'closures'   => array(
				'table' => 'closures', 'feature' => 'bookings', 'read' => 'bookings.read', 'write' => 'bookings.manage', 'hard_delete' => true, 'no_update_ts' => true,
				'fields' => array( 'on_date' => 'date', 'label' => 'text:120', 'staff_id' => 'user', 'space_id' => 'fk:spaces' ),
				'required' => array( 'on_date' ), 'order' => 'on_date ASC',
			),
			'notes'      => array(
				'table' => 'notes', 'feature' => null, 'read' => null, 'write' => null,
				'fields' => array( 'title' => 'text:190', 'body' => 'long', 'is_private' => 'bool', 'pinned' => 'bool' ),
				'search' => array( 'title', 'body' ), 'order' => 'pinned DESC, id DESC', 'owner_col' => 'user_id', 'owner_only_write' => true,
				'scope' => array( __CLASS__, 'scope_notes' ),
			),
			'goals'      => array(
				'table' => 'goals', 'feature' => null, 'read' => null, 'write' => null,
				'fields' => array( 'title' => 'text:190', 'target_date' => 'date', 'progress' => 'int', 'status' => 'enum:active|done|dropped' ),
				'required' => array( 'title' ), 'order' => 'id DESC', 'owner_col' => 'user_id', 'owner_only_write' => true,
				'scope' => array( __CLASS__, 'scope_own' ),
			),
		);
	}

	public static function scope_notes( &$vals ) {
		$vals[] = get_current_user_id();
		return '( user_id = %d OR is_private = 0 )';
	}

	public static function scope_own( &$vals ) {
		$vals[] = get_current_user_id();
		return 'user_id = %d';
	}

	public static function routes() {
		foreach ( self::defs() as $name => $d ) {
			$path = '/' . str_replace( '_', '-', $name );
			NWC_REST::route( $path, 'GET', function ( WP_REST_Request $r ) use ( $name ) {
				return self::index( $name, $r );
			} );
			NWC_REST::route( $path, 'POST', function ( WP_REST_Request $r ) use ( $name ) {
				return self::save( $name, 0, NWC_REST::body( $r ) );
			} );
			NWC_REST::route( $path . '/(?P<id>\d+)', 'GET', function ( WP_REST_Request $r ) use ( $name ) {
				return self::show( $name, (int) $r['id'] );
			} );
			NWC_REST::route( $path . '/(?P<id>\d+)', 'PUT,PATCH', function ( WP_REST_Request $r ) use ( $name ) {
				return self::save( $name, (int) $r['id'], NWC_REST::body( $r ) );
			} );
			NWC_REST::route( $path . '/(?P<id>\d+)', 'DELETE', function ( WP_REST_Request $r ) use ( $name ) {
				return self::remove( $name, (int) $r['id'] );
			} );
		}
	}

	private static function gate( $d, $action ) {
		$perm = $d[ $action ];
		if ( $perm && ! NWC_Tenancy::can( $perm ) ) {
			return NWC::err( 'Your role does not allow this.', 403, 'nwc_forbidden' );
		}
		if ( ! empty( $d['feature'] ) ) {
			return NWC_Features::guard( $d['feature'] );
		}
		return true;
	}

	private static function where( $d, &$vals ) {
		$sql    = 'workspace_id = %d';
		$vals[] = (int) NWC_Tenancy::$current->id;
		if ( empty( $d['hard_delete'] ) ) {
			$sql .= ' AND deleted_at IS NULL';
		}
		if ( ! empty( $d['scope'] ) ) {
			$args  = array( &$vals );
			$extra = call_user_func_array( $d['scope'], $args );
			if ( $extra ) {
				$sql .= ' AND ' . $extra;
			}
		}
		return $sql;
	}

	public static function fetch( $name, $id ) {
		global $wpdb;
		$d    = self::defs()[ $name ];
		$vals = array();
		$sql  = self::where( $d, $vals ) . ' AND id = %d';
		$vals[] = (int) $id;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . NWC_DB::t( $d['table'] ) . " WHERE $sql", $vals ) ); // phpcs:ignore
	}

	public static function index( $name, WP_REST_Request $r ) {
		global $wpdb;
		$d = self::defs()[ $name ];
		$g = self::gate( $d, 'read' );
		if ( is_wp_error( $g ) ) {
			return $g;
		}
		$vals = array();
		$sql  = self::where( $d, $vals );
		foreach ( isset( $d['filters'] ) ? $d['filters'] : array() as $f ) {
			$v = $r->get_param( $f );
			if ( null !== $v && '' !== $v ) {
				$sql   .= " AND $f = %s";
				$vals[] = (string) $v;
			}
		}
		if ( 'projects' === $name && $r->get_param( 'top' ) ) {
			$sql .= ' AND parent_id IS NULL';
		}
		$q = trim( sanitize_text_field( (string) $r->get_param( 'q' ) ) );
		if ( '' !== $q && ! empty( $d['search'] ) ) {
			$like  = '%' . $wpdb->esc_like( $q ) . '%';
			$parts = array();
			foreach ( $d['search'] as $col ) {
				$parts[] = "$col LIKE %s";
				$vals[]  = $like;
			}
			$sql .= ' AND (' . implode( ' OR ', $parts ) . ')';
		}
		$per   = min( 200, max( 1, (int) ( $r->get_param( 'per_page' ) ?: 100 ) ) );
		$page  = max( 1, (int) ( $r->get_param( 'page' ) ?: 1 ) );
		$table = NWC_DB::t( $d['table'] );
		$total = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $table WHERE $sql", $vals ) ); // phpcs:ignore
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $table WHERE $sql ORDER BY {$d['order']} LIMIT %d OFFSET %d", array_merge( $vals, array( $per, ( $page - 1 ) * $per ) ) ) ); // phpcs:ignore
		return array( 'items' => array_map( function ( $row ) use ( $d ) {
			return self::out( $d, $row );
		}, $rows ), 'total' => $total, 'page' => $page, 'per_page' => $per );
	}

	private static function out( $d, $row ) {
		foreach ( $d['fields'] as $f => $type ) {
			if ( ! isset( $row->$f ) ) {
				continue;
			}
			$base = strtok( $type, ':' );
			if ( 'json' === $base ) {
				$row->$f = json_decode( (string) $row->$f, true );
			} elseif ( in_array( $base, array( 'int', 'fk', 'user', 'bool' ), true ) ) {
				$row->$f = (int) $row->$f;
			} elseif ( 'money' === $base ) {
				$row->$f = (float) $row->$f;
			}
		}
		$row->id = (int) $row->id;
		$row->workspace_id = (int) $row->workspace_id;
		unset( $row->deleted_at, $row->phone_norm );
		if ( ! empty( $d['decorate'] ) ) {
			$row = call_user_func( $d['decorate'], $row );
		}
		return $row;
	}

	public static function show( $name, $id ) {
		$d = self::defs()[ $name ];
		$g = self::gate( $d, 'read' );
		if ( is_wp_error( $g ) ) {
			return $g;
		}
		$row = self::fetch( $name, $id );
		return $row ? self::out( $d, $row ) : NWC::err( 'Not found.', 404, 'nwc_not_found' );
	}

	/** @return array|WP_Error Clean values for the given input. */
	public static function clean( $d, $in, $old ) {
		global $wpdb;
		$out = array();
		$tz  = NWC_Tenancy::$current->timezone;
		foreach ( $d['fields'] as $f => $type ) {
			if ( ! array_key_exists( $f, $in ) ) {
				continue;
			}
			$v     = $in[ $f ];
			$parts = explode( ':', $type, 2 );
			switch ( $parts[0] ) {
				case 'text':
					$out[ $f ] = mb_substr( sanitize_text_field( (string) $v ), 0, (int) $parts[1] );
					break;
				case 'long':
					$out[ $f ] = sanitize_textarea_field( (string) $v );
					break;
				case 'int':
					$out[ $f ] = max( 0, (int) $v );
					break;
				case 'money':
					$out[ $f ] = round( max( 0, (float) $v ), 2 );
					break;
				case 'bool':
					$out[ $f ] = empty( $v ) ? 0 : 1;
					break;
				case 'date':
					if ( '' !== (string) $v && null !== $v && ! NWC::valid_date( $v ) ) {
						return NWC::err( 'Invalid date in ' . $f . '.' );
					}
					$out[ $f ] = '' === (string) $v ? null : $v;
					break;
				case 'datetime':
					$out[ $f ] = NWC::to_utc( (string) $v, $tz );
					break;
				case 'enum':
					if ( ! in_array( (string) $v, explode( '|', $parts[1] ), true ) ) {
						return NWC::err( 'Invalid value for ' . $f . '.' );
					}
					$out[ $f ] = (string) $v;
					break;
				case 'email':
					if ( '' !== (string) $v && ! is_email( $v ) ) {
						return NWC::err( 'Enter a valid email address.' );
					}
					$out[ $f ] = sanitize_email( (string) $v );
					break;
				case 'url':
					$out[ $f ] = esc_url_raw( (string) $v, array( 'http', 'https' ) );
					break;
				case 'color':
					$out[ $f ] = (string) sanitize_hex_color( (string) $v );
					break;
				case 'json':
					$out[ $f ] = wp_json_encode( self::clean_json( $v ) );
					break;
				case 'fk':
					$id = absint( $v );
					if ( $id ) {
						$target = self::defs()[ $parts[1] ];
						$owner  = $wpdb->get_var( $wpdb->prepare( 'SELECT workspace_id FROM ' . NWC_DB::t( $target['table'] ) . ' WHERE id = %d', $id ) ); // phpcs:ignore
						if ( (int) $owner !== (int) NWC_Tenancy::$current->id ) {
							return NWC::err( 'That linked record was not found.', 404, 'nwc_not_found' );
						}
					}
					$out[ $f ] = $id ? $id : null;
					break;
				case 'user':
					$id = absint( $v );
					if ( $id && ! NWC_Tenancy::member_row( NWC_Tenancy::$current->id, $id ) ) {
						return NWC::err( 'That person is not a member of this workspace.', 400 );
					}
					$out[ $f ] = $id ? $id : null;
					break;
			}
		}
		foreach ( isset( $d['required'] ) ? $d['required'] : array() as $f ) {
			$cur = array_key_exists( $f, $out ) ? $out[ $f ] : ( $old ? $old->$f : null );
			if ( null === $cur || '' === $cur || 0 === $cur ) {
				return NWC::err( ucfirst( str_replace( '_', ' ', $f ) ) . ' is required.' );
			}
		}
		return $out;
	}

	private static function clean_json( $v ) {
		if ( is_array( $v ) ) {
			return array_map( array( __CLASS__, 'clean_json' ), $v );
		}
		if ( is_bool( $v ) || is_int( $v ) || is_float( $v ) || null === $v ) {
			return $v;
		}
		return sanitize_text_field( (string) $v );
	}

	public static function save( $name, $id, $in ) {
		global $wpdb;
		$d = self::defs()[ $name ];
		$g = self::gate( $d, 'write' );
		if ( is_wp_error( $g ) ) {
			return $g;
		}
		$old = null;
		if ( $id ) {
			$old = self::fetch( $name, $id );
			if ( ! $old ) {
				return NWC::err( 'Not found.', 404, 'nwc_not_found' );
			}
			if ( ! empty( $d['owner_only_write'] ) && (int) $old->{$d['owner_col']} !== get_current_user_id() ) {
				return NWC::err( 'Only its owner can change this.', 403, 'nwc_forbidden' );
			}
		}
		$row = self::clean( $d, $in, $old );
		if ( is_wp_error( $row ) ) {
			return $row;
		}
		foreach ( array( 'guard_write', 'before' ) as $hook ) {
			if ( ! empty( $d[ $hook ] ) ) {
				$row = call_user_func( $d[ $hook ], $row, $old, $in );
				if ( is_wp_error( $row ) ) {
					return $row;
				}
			}
		}
		$table = NWC_DB::t( $d['table'] );
		$now   = NWC::now();
		if ( empty( $d['no_update_ts'] ) ) {
			$row['updated_at'] = $now;
		}
		$changed = ! $old;
		if ( $old ) {
			foreach ( $row as $col => $val ) {
				if ( 'updated_at' !== $col && ( ! property_exists( $old, $col ) || (string) $old->$col !== (string) $val ) ) {
					$changed = true;
					break;
				}
			}
			if ( $row ) {
				$wpdb->update( $table, $row, array( 'id' => $old->id, 'workspace_id' => NWC_Tenancy::$current->id ) );
			}
			$new_id = (int) $old->id;
		} else {
			if ( ! empty( $d['limit'] ) ) {
				$count = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $table WHERE workspace_id = %d AND deleted_at IS NULL", NWC_Tenancy::$current->id ) ); // phpcs:ignore
				$lim   = NWC_Features::check_limit( $d['limit'], $count );
				if ( is_wp_error( $lim ) ) {
					return $lim;
				}
			}
			$row['workspace_id'] = (int) NWC_Tenancy::$current->id;
			$row['created_at']   = $now;
			if ( ! empty( $d['owner_col'] ) ) {
				$row[ $d['owner_col'] ] = get_current_user_id();
			}
			if ( ! $wpdb->insert( $table, $row ) ) {
				return NWC::err( 'Could not save this.', 500, 'nwc_error' );
			}
			$new_id = (int) $wpdb->insert_id;
		}
		$saved = self::fetch( $name, $new_id );
		if ( ! $saved ) { // Saved, but now out of this user's scope (for example reassigned away).
			$saved = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE id = %d", $new_id ) ); // phpcs:ignore
		}
		if ( $changed ) {
			NWC::audit( $name . ( $old ? '_updated' : '_created' ), array( 'entity' => $name, 'entity_id' => $new_id ) );
		}
		if ( ! empty( $d['after'] ) ) {
			call_user_func( $d['after'], $saved, $old, $in );
		}
		return self::out( $d, $saved );
	}

	/** Soft delete: the record stays recoverable in the database. */
	public static function remove( $name, $id ) {
		global $wpdb;
		$d = self::defs()[ $name ];
		$g = self::gate( $d, 'write' );
		if ( is_wp_error( $g ) ) {
			return $g;
		}
		$old = self::fetch( $name, $id );
		if ( ! $old ) {
			return NWC::err( 'Not found.', 404, 'nwc_not_found' );
		}
		if ( ! empty( $d['owner_only_write'] ) && (int) $old->{$d['owner_col']} !== get_current_user_id() ) {
			return NWC::err( 'Only its owner can delete this.', 403, 'nwc_forbidden' );
		}
		if ( ! empty( $d['guard_delete'] ) ) {
			$ok = call_user_func( $d['guard_delete'], $old );
			if ( is_wp_error( $ok ) ) {
				return $ok;
			}
		}
		$table = NWC_DB::t( $d['table'] );
		if ( ! empty( $d['hard_delete'] ) ) {
			$wpdb->delete( $table, array( 'id' => $id, 'workspace_id' => NWC_Tenancy::$current->id ) );
		} else {
			$wpdb->update( $table, array( 'deleted_at' => NWC::now() ), array( 'id' => $id, 'workspace_id' => NWC_Tenancy::$current->id ) );
		}
		NWC::audit( $name . '_deleted', array( 'entity' => $name, 'entity_id' => $id ) );
		if ( 'leads' === $name ) {
			NWC_Sync::enqueue( NWC_Tenancy::$current->id, 'lead', $id, true );
		}
		return array( 'ok' => true );
	}
}

/** Project membership rules and project collaboration. */
class NWC_Projects {

	private static function sees_all() {
		return NWC_Tenancy::can( 'projects.manage' );
	}

	public static function scope_projects( &$vals ) {
		if ( self::sees_all() ) {
			return '';
		}
		$vals[] = get_current_user_id();
		return 'id IN (SELECT project_id FROM ' . NWC_DB::t( 'project_members' ) . ' WHERE user_id = %d)';
	}

	public static function scope_tasks( &$vals ) {
		if ( self::sees_all() ) {
			return '';
		}
		$vals[] = get_current_user_id();
		$vals[] = get_current_user_id();
		return '( assignee_id = %d OR project_id IN (SELECT project_id FROM ' . NWC_DB::t( 'project_members' ) . ' WHERE user_id = %d) )';
	}

	public static function scope_children( &$vals ) {
		if ( self::sees_all() ) {
			return '';
		}
		$vals[] = get_current_user_id();
		return 'project_id IN (SELECT project_id FROM ' . NWC_DB::t( 'project_members' ) . ' WHERE user_id = %d)';
	}

	public static function can_see( $project_id ) {
		return (bool) NWC_Resources::fetch( 'projects', $project_id );
	}

	/** One level only: a sub-project's parent is a top-level project of the same workspace. */
	public static function guard_project( $row, $old, $in ) {
		global $wpdb;
		if ( ! array_key_exists( 'parent_id', $row ) || ! $row['parent_id'] ) {
			return $row;
		}
		$pid = (int) $row['parent_id'];
		if ( $old && (int) $old->id === $pid ) {
			return NWC::err( 'A project cannot be its own sub-project.' );
		}
		$parent = self::can_see( $pid ) ? NWC_Resources::fetch( 'projects', $pid ) : null;
		if ( ! $parent ) {
			return NWC::err( 'Parent project not found.', 404, 'nwc_not_found' );
		}
		if ( $parent->parent_id ) {
			return NWC::err( 'Sub-projects go directly under a main project.' );
		}
		if ( $old && (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . NWC_DB::t( 'projects' ) . ' WHERE parent_id = %d AND deleted_at IS NULL', $old->id ) ) ) { // phpcs:ignore
			return NWC::err( 'This project has its own sub-projects, so it stays a main project.' );
		}
		return $row;
	}

	public static function guard_task( $row, $old, $in ) {
		$pid = array_key_exists( 'project_id', $row ) ? $row['project_id'] : ( $old ? $old->project_id : null );
		if ( $pid && ! self::can_see( $pid ) ) {
			return NWC::err( 'Project not found.', 404, 'nwc_not_found' );
		}
		return $row;
	}

	public static function after_project( $p, $old ) {
		global $wpdb;
		if ( ! $old ) { // The creator is always a member.
			$wpdb->insert( NWC_DB::t( 'project_members' ), array( 'workspace_id' => (int) $p->workspace_id, 'project_id' => (int) $p->id, 'user_id' => get_current_user_id(), 'added_at' => NWC::now() ) );
		}
	}

	public static function after_task( $t, $old ) {
		if ( $t->assignee_id && ( ! $old || (int) $old->assignee_id !== (int) $t->assignee_id ) && (int) $t->assignee_id !== get_current_user_id() ) {
			NWC_Notify::user( (int) $t->workspace_id, (int) $t->assignee_id, 'task', 'Task assigned to you', $t->title, $t->project_id ? '#/projects/' . (int) $t->project_id . '?task=' . (int) $t->id : '#/tasks?task=' . (int) $t->id );
		}
	}

	public static function decorate_project( $p ) {
		global $wpdb;
		$t            = NWC_DB::t( 'tasks' );
		$p->task_total = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $t WHERE project_id = %d AND deleted_at IS NULL", $p->id ) ); // phpcs:ignore
		$p->task_done  = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $t WHERE project_id = %d AND deleted_at IS NULL AND status = 'done'", $p->id ) ); // phpcs:ignore
		$p->progress   = $p->task_total ? (int) round( 100 * $p->task_done / $p->task_total ) : 0;
		$p->parent_id  = $p->parent_id ? (int) $p->parent_id : null;
		$p->sub_count  = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . NWC_DB::t( 'projects' ) . ' WHERE parent_id = %d AND deleted_at IS NULL', $p->id ) ); // phpcs:ignore
		return $p;
	}

	public static function routes() {
		NWC_REST::route( '/projects/(?P<id>\d+)/members', 'GET', array( __CLASS__, 'members' ) );
		NWC_REST::route( '/projects/(?P<id>\d+)/members', 'PUT', array( __CLASS__, 'set_members' ) );
		NWC_REST::route( '/comments', 'GET', array( __CLASS__, 'comments' ) );
		NWC_REST::route( '/comments', 'POST', array( __CLASS__, 'add_comment' ) );
		NWC_REST::route( '/files', 'GET', array( __CLASS__, 'files' ) );
		NWC_REST::route( '/files', 'POST', array( __CLASS__, 'upload' ) );
		NWC_REST::route( '/files/(?P<id>\d+)/download', 'GET', array( __CLASS__, 'download' ) );
		NWC_REST::route( '/history', 'GET', array( __CLASS__, 'history' ) );
	}

	public static function members( WP_REST_Request $r ) {
		global $wpdb;
		if ( ! self::can_see( (int) $r['id'] ) ) {
			return NWC::err( 'Project not found.', 404, 'nwc_not_found' );
		}
		return array( 'user_ids' => array_map( 'intval', $wpdb->get_col( $wpdb->prepare( 'SELECT user_id FROM ' . NWC_DB::t( 'project_members' ) . ' WHERE project_id = %d AND workspace_id = %d', (int) $r['id'], NWC_Tenancy::$current->id ) ) ) ); // phpcs:ignore
	}

	public static function set_members( WP_REST_Request $r ) {
		global $wpdb;
		if ( ! NWC_Tenancy::can( 'projects.write' ) ) {
			return NWC::err( 'Your role does not allow this.', 403, 'nwc_forbidden' );
		}
		$id = (int) $r['id'];
		if ( ! self::can_see( $id ) ) {
			return NWC::err( 'Project not found.', 404, 'nwc_not_found' );
		}
		$ws  = (int) NWC_Tenancy::$current->id;
		$ids = array_values( array_unique( array_map( 'absint', (array) ( NWC_REST::body( $r )['user_ids'] ?? array() ) ) ) );
		foreach ( $ids as $u ) {
			if ( ! NWC_Tenancy::member_row( $ws, $u ) ) {
				return NWC::err( 'Only workspace members can join a project.' );
			}
		}
		$wpdb->delete( NWC_DB::t( 'project_members' ), array( 'project_id' => $id, 'workspace_id' => $ws ) );
		foreach ( $ids as $u ) {
			$wpdb->insert( NWC_DB::t( 'project_members' ), array( 'workspace_id' => $ws, 'project_id' => $id, 'user_id' => $u, 'added_at' => NWC::now() ) );
		}
		NWC::audit( 'project_members_changed', array( 'entity' => 'projects', 'entity_id' => $id, 'details' => array( 'user_ids' => $ids ) ) );
		return array( 'user_ids' => $ids );
	}

	/** Comments, files and history attach to a record the caller can already see. */
	private static function attachable( $entity, $id ) {
		$map = array( 'project' => 'projects', 'task' => 'tasks', 'customer' => 'customers', 'lead' => 'leads' );
		if ( ! isset( $map[ $entity ] ) ) {
			return NWC::err( 'Unknown record type.' );
		}
		$d = NWC_Resources::defs()[ $map[ $entity ] ];
		if ( ! NWC_Tenancy::can( $d['read'] ) ) {
			return NWC::err( 'Your role does not allow this.', 403, 'nwc_forbidden' );
		}
		$g = NWC_Features::guard( $d['feature'] );
		if ( is_wp_error( $g ) ) {
			return $g;
		}
		return NWC_Resources::fetch( $map[ $entity ], $id ) ? true : NWC::err( 'Not found.', 404, 'nwc_not_found' );
	}

	public static function comments( WP_REST_Request $r ) {
		global $wpdb;
		$e  = sanitize_key( (string) $r->get_param( 'entity' ) );
		$id = absint( $r->get_param( 'entity_id' ) );
		$ok = self::attachable( $e, $id );
		if ( is_wp_error( $ok ) ) {
			return $ok;
		}
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . NWC_DB::t( 'comments' ) . ' WHERE workspace_id = %d AND entity = %s AND entity_id = %d ORDER BY id ASC', NWC_Tenancy::$current->id, $e, $id ) ); // phpcs:ignore
		foreach ( $rows as $c ) {
			$u          = get_userdata( $c->user_id );
			$c->author  = $u ? $u->display_name : 'Former member';
		}
		return array( 'items' => $rows );
	}

	public static function add_comment( WP_REST_Request $r ) {
		global $wpdb;
		$b    = NWC_REST::body( $r );
		$e    = sanitize_key( (string) ( $b['entity'] ?? '' ) );
		$id   = absint( $b['entity_id'] ?? 0 );
		$ok   = self::attachable( $e, $id );
		$text = sanitize_textarea_field( (string) ( $b['body'] ?? '' ) );
		if ( is_wp_error( $ok ) ) {
			return $ok;
		}
		if ( ! NWC_Tenancy::can( 'tasks.write' ) && ! NWC_Tenancy::can( 'crm.write' ) ) {
			return NWC::err( 'Your role does not allow this.', 403, 'nwc_forbidden' );
		}
		if ( '' === trim( $text ) ) {
			return NWC::err( 'Write a comment first.' );
		}
		$wpdb->insert( NWC_DB::t( 'comments' ), array( 'workspace_id' => (int) NWC_Tenancy::$current->id, 'entity' => $e, 'entity_id' => $id, 'user_id' => get_current_user_id(), 'body' => $text, 'created_at' => NWC::now() ) );
		return array( 'id' => (int) $wpdb->insert_id );
	}

	public static function history( WP_REST_Request $r ) {
		global $wpdb;
		$e  = sanitize_key( (string) $r->get_param( 'entity' ) );
		$id = absint( $r->get_param( 'entity_id' ) );
		$ok = self::attachable( $e, $id );
		if ( is_wp_error( $ok ) ) {
			return $ok;
		}
		$map  = array( 'project' => 'projects', 'task' => 'tasks', 'customer' => 'customers', 'lead' => 'leads' );
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT user_id, action, created_at FROM ' . NWC_DB::t( 'audit' ) . " WHERE workspace_id = %d AND kind = 'activity' AND entity = %s AND entity_id = %d ORDER BY id DESC LIMIT 100", NWC_Tenancy::$current->id, $map[ $e ], $id ) ); // phpcs:ignore
		foreach ( $rows as $h ) {
			$u        = get_userdata( $h->user_id );
			$h->who   = $u ? $u->display_name : 'System';
			$h->label = str_replace( '_', ' ', $h->action );
			unset( $h->user_id );
		}
		return array( 'items' => $rows );
	}

	public static function files( WP_REST_Request $r ) {
		global $wpdb;
		$e  = sanitize_key( (string) $r->get_param( 'entity' ) );
		$id = absint( $r->get_param( 'entity_id' ) );
		$ok = self::attachable( $e, $id );
		if ( is_wp_error( $ok ) ) {
			return $ok;
		}
		return array( 'items' => $wpdb->get_results( $wpdb->prepare( 'SELECT id, name, mime, size, created_at FROM ' . NWC_DB::t( 'files' ) . ' WHERE workspace_id = %d AND entity = %s AND entity_id = %d ORDER BY id DESC', NWC_Tenancy::$current->id, $e, $id ) ) ); // phpcs:ignore
	}

	const ALLOWED_MIME = array( 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp', 'gif' => 'image/gif', 'pdf' => 'application/pdf', 'txt' => 'text/plain', 'csv' => 'text/csv', 'doc' => 'application/msword', 'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'xls' => 'application/vnd.ms-excel', 'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'zip' => 'application/zip' );

	/** Private folder with a deny rule and random names. Files are only ever served by download(). */
	public static function storage_dir( $workspace_id ) {
		$up  = wp_upload_dir();
		$dir = trailingslashit( $up['basedir'] ) . 'nwc-private/' . (int) $workspace_id;
		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
		}
		$root = trailingslashit( $up['basedir'] ) . 'nwc-private';
		if ( ! file_exists( $root . '/.htaccess' ) ) {
			file_put_contents( $root . '/.htaccess', "Require all denied\nDeny from all\n" ); // phpcs:ignore
			file_put_contents( $root . '/index.html', '' ); // phpcs:ignore
		}
		return $dir;
	}

	/**
	 * Checks content, not just the name: the extension must be allowed, and the bytes must match it.
	 *
	 * @return array|WP_Error {ext, mime}
	 */
	public static function validate_upload( $name, $tmp, $size ) {
		$max = (int) NWC::settings()['upload_max_mb'] * MB_IN_BYTES;
		if ( $size <= 0 || $size > $max ) {
			return NWC::err( 'Files can be up to ' . (int) NWC::settings()['upload_max_mb'] . ' MB.', 413, 'nwc_file_size' );
		}
		$ext = strtolower( pathinfo( (string) $name, PATHINFO_EXTENSION ) );
		if ( ! isset( self::ALLOWED_MIME[ $ext ] ) ) {
			return NWC::err( 'This file type is not allowed.', 415, 'nwc_file_type' );
		}
		$finfo = new finfo( FILEINFO_MIME_TYPE );
		$real  = (string) $finfo->file( $tmp );
		$want  = self::ALLOWED_MIME[ $ext ];
		$ok    = $real === $want;
		if ( ! $ok && in_array( $ext, array( 'docx', 'xlsx', 'zip' ), true ) ) {
			$ok = in_array( $real, array( 'application/zip', 'application/octet-stream', $want ), true );
		}
		if ( ! $ok && in_array( $ext, array( 'doc', 'xls' ), true ) ) {
			$ok = in_array( $real, array( 'application/CDFV2', 'application/x-ole-storage', 'application/octet-stream', $want ), true );
		}
		if ( ! $ok && in_array( $ext, array( 'txt', 'csv' ), true ) ) {
			$ok = 0 === strpos( $real, 'text/' ) && false === stripos( (string) file_get_contents( $tmp, false, null, 0, 4096 ), '<?php' ); // phpcs:ignore
		}
		if ( ! $ok ) {
			return NWC::err( 'The file content does not match its type.', 415, 'nwc_file_type' );
		}
		return array( 'ext' => $ext, 'mime' => $want );
	}

	public static function upload( WP_REST_Request $r ) {
		global $wpdb;
		$e  = sanitize_key( (string) $r->get_param( 'entity' ) );
		$id = absint( $r->get_param( 'entity_id' ) );
		$ok = self::attachable( $e, $id );
		if ( is_wp_error( $ok ) ) {
			return $ok;
		}
		if ( ! NWC_Tenancy::can( 'tasks.write' ) && ! NWC_Tenancy::can( 'crm.write' ) ) {
			return NWC::err( 'Your role does not allow this.', 403, 'nwc_forbidden' );
		}
		$f = $r->get_file_params();
		$f = isset( $f['file'] ) ? $f['file'] : null;
		if ( ! $f || UPLOAD_ERR_OK !== (int) $f['error'] || ! is_uploaded_file( $f['tmp_name'] ) ) {
			return NWC::err( 'No file received.' );
		}
		$v = self::validate_upload( $f['name'], $f['tmp_name'], (int) $f['size'] );
		if ( is_wp_error( $v ) ) {
			return $v;
		}
		$ws   = (int) NWC_Tenancy::$current->id;
		$name = bin2hex( random_bytes( 16 ) ) . '.' . $v['ext'];
		$path = self::storage_dir( $ws ) . '/' . $name;
		if ( ! move_uploaded_file( $f['tmp_name'], $path ) ) {
			return NWC::err( 'Could not store the file.', 500, 'nwc_error' );
		}
		$wpdb->insert( NWC_DB::t( 'files' ), array( 'workspace_id' => $ws, 'entity' => $e, 'entity_id' => $id, 'name' => mb_substr( sanitize_file_name( $f['name'] ), 0, 190 ), 'path' => $ws . '/' . $name, 'mime' => $v['mime'], 'size' => (int) $f['size'], 'uploaded_by' => get_current_user_id(), 'created_at' => NWC::now() ) );
		$file_id = (int) $wpdb->insert_id;
		NWC::audit( 'file_uploaded', array( 'entity' => 'file', 'entity_id' => $file_id ) );
		return array( 'id' => $file_id );
	}

	public static function download( WP_REST_Request $r ) {
		global $wpdb;
		$f = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . NWC_DB::t( 'files' ) . ' WHERE id = %d AND workspace_id = %d', (int) $r['id'], NWC_Tenancy::$current->id ) ); // phpcs:ignore
		if ( ! $f ) {
			return NWC::err( 'File not found.', 404, 'nwc_not_found' );
		}
		$ok = self::attachable( $f->entity, $f->entity_id );
		if ( is_wp_error( $ok ) ) {
			return $ok;
		}
		$up   = wp_upload_dir();
		$path = trailingslashit( $up['basedir'] ) . 'nwc-private/' . $f->path;
		if ( ! file_exists( $path ) ) {
			return NWC::err( 'File not found.', 404, 'nwc_not_found' );
		}
		if ( defined( 'NWC_TESTING' ) ) {
			return array( 'ok' => true, 'name' => $f->name );
		}
		nocache_headers();
		header( 'Content-Type: ' . $f->mime );
		header( 'X-Content-Type-Options: nosniff' );
		header( 'Content-Disposition: attachment; filename="' . str_replace( '"', '', $f->name ) . '"' );
		header( 'Content-Length: ' . filesize( $path ) );
		readfile( $path ); // phpcs:ignore
		exit;
	}
}

/** Contact matching so forms and bookings never create duplicates. */
class NWC_CRM {

	public static function before_contact( $row, $old ) {
		if ( array_key_exists( 'phone', $row ) ) {
			$row['phone_norm'] = NWC::norm_phone( $row['phone'] );
		}
		return $row;
	}

	public static function after_lead( $lead, $old ) {
		NWC_Sync::enqueue( (int) $lead->workspace_id, 'lead', (int) $lead->id );
		if ( $old && $old->stage !== $lead->stage ) {
			self::log( (int) $lead->workspace_id, 'lead', (int) $lead->id, 'stage', 'Stage: ' . $old->stage . ' → ' . $lead->stage );
		}
		if ( 'converted' === $lead->stage && ! $lead->customer_id ) {
			global $wpdb;
			$cid = self::upsert_customer( (int) $lead->workspace_id, array( 'name' => $lead->name, 'phone' => $lead->phone, 'email' => $lead->email, 'source' => $lead->source, 'marketing_consent' => (int) $lead->marketing_consent ) );
			$wpdb->update( NWC_DB::t( 'leads' ), array( 'customer_id' => $cid ), array( 'id' => $lead->id ) );
		}
	}

	public static function log( $ws, $entity, $id, $kind, $note ) {
		global $wpdb;
		$wpdb->insert( NWC_DB::t( 'activities' ), array( 'workspace_id' => (int) $ws, 'entity' => $entity, 'entity_id' => (int) $id, 'kind' => $kind, 'note' => $note, 'user_id' => get_current_user_id(), 'created_at' => NWC::now() ) );
	}

	/** Same phone or email in the same workspace means the same person. */
	private static function match( $table, $ws, $phone_norm, $email ) {
		global $wpdb;
		$t = NWC_DB::t( $table );
		if ( '' !== $phone_norm ) {
			$id = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM $t WHERE workspace_id = %d AND phone_norm = %s AND deleted_at IS NULL ORDER BY id ASC LIMIT 1", $ws, $phone_norm ) ); // phpcs:ignore
			if ( $id ) {
				return (int) $id;
			}
		}
		if ( '' !== $email ) {
			$id = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM $t WHERE workspace_id = %d AND email = %s AND deleted_at IS NULL ORDER BY id ASC LIMIT 1", $ws, $email ) ); // phpcs:ignore
			if ( $id ) {
				return (int) $id;
			}
		}
		return 0;
	}

	public static function upsert_customer( $ws, $c ) {
		global $wpdb;
		$phone = mb_substr( sanitize_text_field( (string) ( $c['phone'] ?? '' ) ), 0, 40 );
		$norm  = NWC::norm_phone( $phone );
		$email = sanitize_email( (string) ( $c['email'] ?? '' ) );
		$id    = self::match( 'customers', $ws, $norm, $email );
		$now   = NWC::now();
		if ( $id ) {
			$patch = array( 'updated_at' => $now );
			if ( ! empty( $c['marketing_consent'] ) ) {
				$patch['marketing_consent'] = 1; // Consent is only ever raised by the person's own opt-in.
			}
			$wpdb->update( NWC_DB::t( 'customers' ), $patch, array( 'id' => $id ) );
			return $id;
		}
		$wpdb->insert( NWC_DB::t( 'customers' ), array( 'workspace_id' => (int) $ws, 'name' => mb_substr( sanitize_text_field( (string) $c['name'] ), 0, 190 ), 'phone' => $phone, 'phone_norm' => $norm, 'email' => $email, 'source' => mb_substr( sanitize_text_field( (string) ( $c['source'] ?? 'manual' ) ), 0, 60 ), 'marketing_consent' => empty( $c['marketing_consent'] ) ? 0 : 1, 'created_at' => $now, 'updated_at' => $now ) );
		return (int) $wpdb->insert_id;
	}

	/** Keeps a single open lead per person; a repeat enquiry adds to its history. */
	public static function upsert_lead( $ws, $l ) {
		global $wpdb;
		$phone = mb_substr( sanitize_text_field( (string) ( $l['phone'] ?? '' ) ), 0, 40 );
		$norm  = NWC::norm_phone( $phone );
		$email = sanitize_email( (string) ( $l['email'] ?? '' ) );
		$id    = self::match( 'leads', $ws, $norm, $email );
		$now   = NWC::now();
		if ( $id ) {
			self::log( $ws, 'lead', $id, 'enquiry', 'Repeat enquiry: ' . mb_substr( sanitize_text_field( (string) ( $l['enquiry'] ?? '' ) ), 0, 200 ) );
			$wpdb->update( NWC_DB::t( 'leads' ), array( 'updated_at' => $now ), array( 'id' => $id ) );
			NWC_Sync::enqueue( $ws, 'lead', $id );
			return $id;
		}
		$wpdb->insert( NWC_DB::t( 'leads' ), array( 'workspace_id' => (int) $ws, 'customer_id' => isset( $l['customer_id'] ) ? (int) $l['customer_id'] : null, 'name' => mb_substr( sanitize_text_field( (string) $l['name'] ), 0, 190 ), 'phone' => $phone, 'phone_norm' => $norm, 'email' => $email, 'enquiry' => mb_substr( sanitize_text_field( (string) ( $l['enquiry'] ?? '' ) ), 0, 255 ), 'lead_type' => mb_substr( sanitize_text_field( (string) ( $l['lead_type'] ?? 'enquiry' ) ), 0, 40 ), 'source' => mb_substr( sanitize_text_field( (string) ( $l['source'] ?? 'form' ) ), 0, 60 ), 'source_ref' => mb_substr( sanitize_text_field( (string) ( $l['source_ref'] ?? '' ) ), 0, 60 ), 'marketing_consent' => empty( $l['marketing_consent'] ) ? 0 : 1, 'created_at' => $now, 'updated_at' => $now ) );
		$id = (int) $wpdb->insert_id;
		NWC_Sync::enqueue( $ws, 'lead', $id );
		return $id;
	}

	public static function routes() {
		NWC_REST::route( '/crm/activities', 'GET', array( __CLASS__, 'activities' ) );
		NWC_REST::route( '/crm/activities', 'POST', array( __CLASS__, 'add_activity' ) );
		NWC_REST::route( '/crm/customers/(?P<id>\d+)/history', 'GET', array( __CLASS__, 'customer_history' ) );
		NWC_REST::route( '/crm/export', 'GET', array( __CLASS__, 'export' ) );
		NWC_REST::route( '/public/enquiry/(?P<slug>[a-z0-9-]+)', 'POST', array( __CLASS__, 'public_enquiry' ), 'public' );
	}

	private static function entity_ok( $entity, $id ) {
		$map = array( 'lead' => 'leads', 'customer' => 'customers' );
		return isset( $map[ $entity ] ) && NWC_Tenancy::can( 'crm.read' ) && ! is_wp_error( NWC_Features::guard( 'crm' ) ) && NWC_Resources::fetch( $map[ $entity ], $id );
	}

	public static function activities( WP_REST_Request $r ) {
		global $wpdb;
		$e  = sanitize_key( (string) $r->get_param( 'entity' ) );
		$id = absint( $r->get_param( 'entity_id' ) );
		if ( ! self::entity_ok( $e, $id ) ) {
			return NWC::err( 'Not found.', 404, 'nwc_not_found' );
		}
		return array( 'items' => $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . NWC_DB::t( 'activities' ) . ' WHERE workspace_id = %d AND entity = %s AND entity_id = %d ORDER BY id DESC LIMIT 100', NWC_Tenancy::$current->id, $e, $id ) ) ); // phpcs:ignore
	}

	public static function add_activity( WP_REST_Request $r ) {
		$b = NWC_REST::body( $r );
		$e = sanitize_key( (string) ( $b['entity'] ?? '' ) );
		$i = absint( $b['entity_id'] ?? 0 );
		if ( ! NWC_Tenancy::can( 'crm.write' ) || ! self::entity_ok( $e, $i ) ) {
			return NWC::err( 'Not found.', 404, 'nwc_not_found' );
		}
		self::log( NWC_Tenancy::$current->id, $e, $i, 'note', sanitize_textarea_field( (string) ( $b['note'] ?? '' ) ) );
		return array( 'ok' => true );
	}

	public static function customer_history( WP_REST_Request $r ) {
		global $wpdb;
		$id = (int) $r['id'];
		if ( ! self::entity_ok( 'customer', $id ) ) {
			return NWC::err( 'Not found.', 404, 'nwc_not_found' );
		}
		$ws = (int) NWC_Tenancy::$current->id;
		return array(
			'bookings' => $wpdb->get_results( $wpdb->prepare( 'SELECT id, service_id, starts_at, status FROM ' . NWC_DB::t( 'bookings' ) . ' WHERE workspace_id = %d AND customer_id = %d AND deleted_at IS NULL ORDER BY starts_at DESC LIMIT 50', $ws, $id ) ), // phpcs:ignore
			'invoices' => NWC_Tenancy::can( 'invoices.read' ) ? $wpdb->get_results( $wpdb->prepare( 'SELECT id, doc_no, invoice_date, grand_total, status FROM ' . NWC_DB::t( 'biz_invoices' ) . ' WHERE workspace_id = %d AND customer_id = %d ORDER BY id DESC LIMIT 50', $ws, $id ) ) : array(), // phpcs:ignore
		);
	}

	/** Permission-controlled, logged, and safe to open in a spreadsheet. */
	public static function export( WP_REST_Request $r ) {
		global $wpdb;
		if ( ! NWC_Tenancy::can( 'crm.export' ) ) {
			return NWC::err( 'Your role does not allow exports.', 403, 'nwc_forbidden' );
		}
		$g = NWC_Features::guard( 'crm' );
		if ( is_wp_error( $g ) ) {
			return $g;
		}
		$what = 'leads' === $r->get_param( 'what' ) ? 'leads' : 'customers';
		$cols = 'leads' === $what ? 'name, phone, email, enquiry, source, stage, follow_up_on, created_at' : 'name, business_name, contact_person, phone, email, gstin, source, created_at';
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT $cols FROM " . NWC_DB::t( $what ) . ' WHERE workspace_id = %d AND deleted_at IS NULL ORDER BY id ASC', NWC_Tenancy::$current->id ), ARRAY_A ); // phpcs:ignore
		$fh   = fopen( 'php://temp', 'w+' );
		if ( $rows ) {
			fputcsv( $fh, array_keys( $rows[0] ) );
			foreach ( $rows as $row ) {
				fputcsv( $fh, array_map( function ( $v ) {
					return is_string( $v ) && preg_match( '/^[=+\-@]/', $v ) ? "'" . $v : $v;
				}, $row ) );
			}
		}
		rewind( $fh );
		NWC::audit( 'crm_export', array( 'kind' => 'export', 'entity' => $what, 'details' => array( 'rows' => count( $rows ) ) ) );
		return array( 'filename' => $what . '-' . gmdate( 'Y-m-d' ) . '.csv', 'csv' => stream_get_contents( $fh ) );
	}

	/** Public enquiry form on a booking page. */
	public static function public_enquiry( WP_REST_Request $r ) {
		$page = NWC_Bookings::page_by_slug( (string) $r['slug'] );
		if ( ! $page ) {
			return NWC::err( 'Not found.', 404, 'nwc_not_found' );
		}
		$b = NWC_REST::body( $r );
		if ( ! empty( $b['website'] ) ) { // Honeypot: pretend success.
			return array( 'ok' => true );
		}
		if ( ! NWC::rate_ok( 'enquiry|' . NWC::ip(), 10, 300 ) ) {
			return NWC::err( 'Too many attempts. Please try again later.', 429, 'nwc_rate_limited' );
		}
		$name  = trim( sanitize_text_field( (string) ( $b['name'] ?? '' ) ) );
		$phone = sanitize_text_field( (string) ( $b['phone'] ?? '' ) );
		$email = sanitize_email( (string) ( $b['email'] ?? '' ) );
		if ( '' === $name || ( '' === NWC::norm_phone( $phone ) && '' === $email ) ) {
			return NWC::err( 'Enter your name and a phone number or email.' );
		}
		$w = NWC_Tenancy::workspace( $page->workspace_id );
		if ( ! NWC_Features::allowed( 'crm', $w ) && ! NWC_Features::allowed( 'bookings', $w ) ) {
			return NWC::err( 'This business is not taking enquiries online right now.', 403, 'nwc_unavailable' );
		}
		$id = self::upsert_lead( (int) $page->workspace_id, array( 'name' => $name, 'phone' => $phone, 'email' => $email, 'enquiry' => $b['message'] ?? '', 'source' => 'enquiry_form', 'source_ref' => 'page:' . $page->id, 'marketing_consent' => ! empty( $b['marketing_consent'] ) ) );
		NWC_Notify::workspace_owners( (int) $page->workspace_id, 'lead', 'New enquiry', $name, '#/crm/leads', 'lead:' . $id );
		return array( 'ok' => true );
	}
}
