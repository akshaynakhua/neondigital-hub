<?php
/**
 * Custom Forms and Registration Forms: one builder and one submission store, two feature gates.
 * Registration forms add event details, a registration window, capacity, a waitlist and check-in.
 * Customer and lead records go through NWC_CRM, so they are matched rather than duplicated.
 */

defined( 'ABSPATH' ) || exit;

class NWC_Forms {

	const TYPES = array( 'text', 'email', 'phone', 'number', 'date', 'dropdown', 'choice', 'checkbox', 'file', 'textarea' );

	public static function routes() {
		NWC_REST::route( '/forms', 'GET', array( __CLASS__, 'index' ) );
		NWC_REST::route( '/forms', 'POST', array( __CLASS__, 'create' ) );
		NWC_REST::route( '/forms/(?P<id>\d+)', 'GET', array( __CLASS__, 'show' ) );
		NWC_REST::route( '/forms/(?P<id>\d+)', 'PUT,PATCH', array( __CLASS__, 'update' ) );
		NWC_REST::route( '/forms/(?P<id>\d+)', 'DELETE', array( __CLASS__, 'remove' ) );
		NWC_REST::route( '/forms/(?P<id>\d+)/submissions', 'GET', array( __CLASS__, 'submissions' ) );
		NWC_REST::route( '/forms/(?P<id>\d+)/export', 'GET', array( __CLASS__, 'export' ) );
		NWC_REST::route( '/forms/(?P<id>\d+)/submissions/(?P<sid>\d+)', 'PUT,PATCH', array( __CLASS__, 'update_submission' ) );
		NWC_REST::route( '/forms/(?P<id>\d+)/submissions/(?P<sid>\d+)/file', 'GET', array( __CLASS__, 'submission_file' ) );
		NWC_REST::route( '/public/forms/(?P<slug>[a-z0-9-]+)', 'POST', array( __CLASS__, 'submit' ), 'public' );
	}

	private static function feature_for( $kind ) {
		return 'registration' === $kind ? 'registration_forms' : 'custom_forms';
	}

	private static function guard( $kind, $write = false ) {
		if ( ! NWC_Tenancy::can( $write ? 'crm.write' : 'crm.read' ) ) {
			return NWC::err( 'Your role does not allow this.', 403, 'nwc_forbidden' );
		}
		return NWC_Features::guard( self::feature_for( $kind ) );
	}

	private static function row( $id ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . NWC_DB::t( 'forms' ) . ' WHERE id = %d AND workspace_id = %d AND deleted_at IS NULL', $id, NWC_Tenancy::$current->id ) ); // phpcs:ignore
	}

	private static function out( $f, $counts = false ) {
		global $wpdb;
		$f->fields   = json_decode( (string) $f->fields, true ) ?: array();
		$f->settings = json_decode( (string) $f->settings, true ) ?: array();
		$f->url      = home_url( '/f/' . $f->slug . '/' );
		if ( $counts ) {
			$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT status, COUNT(*) n FROM ' . NWC_DB::t( 'form_submissions' ) . ' WHERE form_id = %d AND deleted_at IS NULL GROUP BY status', $f->id ) ); // phpcs:ignore
			$by   = array();
			$all  = 0;
			foreach ( $rows as $x ) {
				$by[ $x->status ] = (int) $x->n;
				$all             += (int) $x->n;
			}
			$f->metrics = array( 'views' => (int) $f->views, 'submissions' => $all, 'by_status' => $by, 'conversion' => (int) $f->views ? round( $all * 100 / (int) $f->views, 1 ) : 0 );
		}
		return $f;
	}

	public static function index( WP_REST_Request $r ) {
		global $wpdb;
		$kind = 'registration' === $r['kind'] ? 'registration' : 'custom';
		$g    = self::guard( $kind );
		if ( is_wp_error( $g ) ) {
			return $g;
		}
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . NWC_DB::t( 'forms' ) . ' WHERE workspace_id = %d AND kind = %s AND deleted_at IS NULL ORDER BY id DESC LIMIT 200', NWC_Tenancy::$current->id, $kind ) ); // phpcs:ignore
		return array( 'items' => array_map( function ( $f ) {
			return self::out( $f, true );
		}, $rows ) );
	}

	public static function show( WP_REST_Request $r ) {
		$f = self::row( (int) $r['id'] );
		if ( ! $f ) {
			return NWC::err( 'Form not found.', 404, 'nwc_not_found' );
		}
		$g = self::guard( $f->kind );
		return is_wp_error( $g ) ? $g : self::out( $f, true );
	}

	/** Field list from the builder. Unknown types and malformed conditions are refused. */
	public static function clean_fields( $in ) {
		if ( ! is_array( $in ) ) {
			return NWC::err( 'Add at least one field.' );
		}
		$out  = array();
		$seen = array();
		foreach ( array_slice( $in, 0, 60 ) as $i => $f ) {
			$type  = (string) ( $f['type'] ?? 'text' );
			$label = mb_substr( trim( sanitize_text_field( (string) ( $f['label'] ?? '' ) ) ), 0, 190 );
			if ( ! in_array( $type, self::TYPES, true ) || '' === $label ) {
				return NWC::err( 'Every field needs a label and a valid type.' );
			}
			$key = sanitize_key( (string) ( $f['key'] ?? '' ) );
			$key = '' !== $key && ! isset( $seen[ $key ] ) ? $key : 'f' . ( $i + 1 ) . '_' . substr( md5( $label . $i ), 0, 5 );
			$seen[ $key ] = true;
			$opts = array();
			if ( in_array( $type, array( 'dropdown', 'choice' ), true ) ) {
				$opts = array_values( array_filter( array_map( function ( $o ) {
					return mb_substr( trim( sanitize_text_field( (string) $o ) ), 0, 190 );
				}, (array) ( $f['options'] ?? array() ) ), 'strlen' ) );
				if ( ! $opts ) {
					return NWC::err( '"' . $label . '" needs at least one option.' );
				}
			}
			$row = array( 'key' => $key, 'type' => $type, 'label' => $label, 'required' => ! empty( $f['required'] ), 'options' => $opts, 'help' => mb_substr( sanitize_text_field( (string) ( $f['help'] ?? '' ) ), 0, 255 ) );
			if ( ! empty( $f['show_if']['field'] ) ) {
				$row['show_if'] = array( 'field' => sanitize_key( (string) $f['show_if']['field'] ), 'equals' => mb_substr( sanitize_text_field( (string) ( $f['show_if']['equals'] ?? '' ) ), 0, 190 ) );
			}
			$out[] = $row;
		}
		if ( ! $out ) {
			return NWC::err( 'Add at least one field.' );
		}
		$keys = array_column( $out, 'key' );
		foreach ( $out as $f ) {
			if ( isset( $f['show_if'] ) && ! in_array( $f['show_if']['field'], $keys, true ) ) {
				return NWC::err( '"' . $f['label'] . '" depends on a field that does not exist.' );
			}
		}
		return $out;
	}

	private static function values( $b, $old, $ws ) {
		global $wpdb;
		$row = array( 'updated_at' => NWC::now() );
		if ( isset( $b['title'] ) || ! $old ) {
			$row['title'] = mb_substr( trim( sanitize_text_field( (string) ( $b['title'] ?? '' ) ) ), 0, 190 );
			if ( '' === $row['title'] ) {
				return NWC::err( 'Give the form a title.' );
			}
		}
		if ( isset( $b['description'] ) ) {
			$row['description'] = sanitize_textarea_field( (string) $b['description'] );
		}
		if ( isset( $b['fields'] ) || ! $old ) {
			$fields = self::clean_fields( $b['fields'] ?? null );
			if ( is_wp_error( $fields ) ) {
				return $fields;
			}
			$row['fields'] = wp_json_encode( $fields );
		}
		if ( isset( $b['status'] ) && in_array( $b['status'], array( 'draft', 'published', 'closed' ), true ) ) {
			$row['status'] = $b['status'];
		}
		if ( isset( $b['settings'] ) && is_array( $b['settings'] ) ) {
			$s = $b['settings'];
			$row['settings'] = wp_json_encode( array( 'confirmation' => sanitize_textarea_field( (string) ( $s['confirmation'] ?? '' ) ), 'create_crm' => in_array( $s['create_crm'] ?? '', array( 'none', 'lead', 'customer' ), true ) ? $s['create_crm'] : 'none', 'notify' => ! empty( $s['notify'] ), 'name_field' => sanitize_key( (string) ( $s['name_field'] ?? '' ) ), 'email_field' => sanitize_key( (string) ( $s['email_field'] ?? '' ) ), 'phone_field' => sanitize_key( (string) ( $s['phone_field'] ?? '' ) ), 'location' => mb_substr( sanitize_text_field( (string) ( $s['location'] ?? '' ) ), 0, 190 ), 'privacy_note' => sanitize_textarea_field( (string) ( $s['privacy_note'] ?? '' ) ) ) );
		}
		$tz = NWC_Tenancy::$current->timezone;
		foreach ( array( 'event_start', 'event_end', 'opens_at', 'closes_at' ) as $k ) {
			if ( array_key_exists( $k, $b ) ) {
				$row[ $k ] = '' === (string) $b[ $k ] || null === $b[ $k ] ? null : NWC::to_utc( (string) $b[ $k ], $tz );
			}
		}
		if ( array_key_exists( 'capacity', $b ) ) {
			$row['capacity'] = '' === (string) $b['capacity'] || null === $b['capacity'] ? null : absint( $b['capacity'] );
		}
		if ( isset( $b['waitlist'] ) ) {
			$row['waitlist'] = empty( $b['waitlist'] ) ? 0 : 1;
		}
		if ( isset( $b['legal_hold'] ) && NWC_Tenancy::can( 'settings.manage' ) ) {
			$row['legal_hold'] = empty( $b['legal_hold'] ) ? 0 : 1;
		}
		if ( ! $old ) {
			$base = sanitize_title( (string) ( $b['slug'] ?? $row['title'] ) );
			$base = '' === $base ? 'form' : mb_substr( $base, 0, 100 );
			$slug = $base;
			$n    = 1;
			while ( $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . NWC_DB::t( 'forms' ) . ' WHERE slug = %s', $slug ) ) ) { // phpcs:ignore
				$slug = $base . '-' . ( ++$n );
			}
			$row['slug'] = $slug;
		}
		return $row;
	}

	public static function create( WP_REST_Request $r ) {
		global $wpdb;
		$b    = NWC_REST::body( $r );
		$kind = 'registration' === ( $b['kind'] ?? '' ) ? 'registration' : 'custom';
		$g    = self::guard( $kind, true );
		if ( is_wp_error( $g ) ) {
			return $g;
		}
		$ws  = (int) NWC_Tenancy::$current->id;
		$row = self::values( $b, null, $ws );
		if ( is_wp_error( $row ) ) {
			return $row;
		}
		$wpdb->insert( NWC_DB::t( 'forms' ), array_merge( array( 'workspace_id' => $ws, 'kind' => $kind, 'created_by' => get_current_user_id(), 'created_at' => NWC::now() ), $row ) );
		$id = (int) $wpdb->insert_id;
		NWC::audit( 'form_created', array( 'entity' => 'form', 'entity_id' => $id, 'details' => array( 'title' => $row['title'], 'kind' => $kind ) ) );
		return self::out( self::row( $id ), true );
	}

	public static function update( WP_REST_Request $r ) {
		global $wpdb;
		$f = self::row( (int) $r['id'] );
		if ( ! $f ) {
			return NWC::err( 'Form not found.', 404, 'nwc_not_found' );
		}
		$g = self::guard( $f->kind, true );
		if ( is_wp_error( $g ) ) {
			return $g;
		}
		$row = self::values( NWC_REST::body( $r ), $f, (int) $f->workspace_id );
		if ( is_wp_error( $row ) ) {
			return $row;
		}
		$wpdb->update( NWC_DB::t( 'forms' ), $row, array( 'id' => $f->id ) );
		if ( isset( $row['status'] ) && $row['status'] !== $f->status ) {
			NWC::audit( 'form_status_changed', array( 'entity' => 'form', 'entity_id' => $f->id, 'details' => array( 'from' => $f->status, 'to' => $row['status'] ) ) );
		}
		return self::out( self::row( $f->id ), true );
	}

	public static function remove( WP_REST_Request $r ) {
		global $wpdb;
		$f = self::row( (int) $r['id'] );
		if ( ! $f ) {
			return NWC::err( 'Form not found.', 404, 'nwc_not_found' );
		}
		$g = self::guard( $f->kind, true );
		if ( is_wp_error( $g ) ) {
			return $g;
		}
		$wpdb->update( NWC_DB::t( 'forms' ), array( 'deleted_at' => NWC::now(), 'status' => 'closed' ), array( 'id' => $f->id ) );
		NWC::audit( 'form_deleted', array( 'entity' => 'form', 'entity_id' => $f->id, 'details' => array( 'title' => $f->title ) ) );
		return array( 'ok' => true );
	}

	/* ---------------------------- Submissions ---------------------------- */

	public static function submissions( WP_REST_Request $r ) {
		global $wpdb;
		$f = self::row( (int) $r['id'] );
		if ( ! $f ) {
			return NWC::err( 'Form not found.', 404, 'nwc_not_found' );
		}
		$g = self::guard( $f->kind );
		if ( is_wp_error( $g ) ) {
			return $g;
		}
		$t     = NWC_DB::t( 'form_submissions' );
		$page  = max( 1, absint( $r['page'] ) );
		$sql   = "form_id = %d AND workspace_id = %d AND deleted_at IS NULL";
		$vals  = array( $f->id, $f->workspace_id );
		if ( $r['status'] ) {
			$sql   .= ' AND status = %s';
			$vals[] = sanitize_key( (string) $r['status'] );
		}
		if ( $r['q'] ) {
			$like   = '%' . $wpdb->esc_like( sanitize_text_field( (string) $r['q'] ) ) . '%';
			$sql   .= ' AND (name LIKE %s OR email LIKE %s OR phone LIKE %s)';
			$vals   = array_merge( $vals, array( $like, $like, $like ) );
		}
		$total = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $t WHERE $sql", $vals ) ); // phpcs:ignore
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $t WHERE $sql ORDER BY id DESC LIMIT 20 OFFSET %d", array_merge( $vals, array( ( $page - 1 ) * 20 ) ) ) ); // phpcs:ignore
		foreach ( $rows as $x ) {
			$x->data = json_decode( (string) $x->data, true ) ?: array();
		}
		return array( 'items' => $rows, 'total' => $total, 'page' => $page, 'pages' => (int) ceil( $total / 20 ) );
	}

	/** Attendance, cancelling and promoting from the waitlist. */
	public static function update_submission( WP_REST_Request $r ) {
		global $wpdb;
		$f = self::row( (int) $r['id'] );
		if ( ! $f ) {
			return NWC::err( 'Form not found.', 404, 'nwc_not_found' );
		}
		$g = self::guard( $f->kind, true );
		if ( is_wp_error( $g ) ) {
			return $g;
		}
		$t = NWC_DB::t( 'form_submissions' );
		$s = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $t WHERE id = %d AND form_id = %d AND deleted_at IS NULL", (int) $r['sid'], $f->id ) ); // phpcs:ignore
		if ( ! $s ) {
			return NWC::err( 'Entry not found.', 404, 'nwc_not_found' );
		}
		$b   = NWC_REST::body( $r );
		$row = array();
		if ( isset( $b['status'] ) && in_array( $b['status'], array( 'received', 'registered', 'waitlisted', 'cancelled' ), true ) ) {
			if ( 'registered' === $b['status'] && 'registered' !== $s->status && $f->capacity && self::registered_count( $f->id ) >= (int) $f->capacity ) {
				return NWC::err( 'This event is full.', 409, 'nwc_full' );
			}
			$row['status'] = $b['status'];
		}
		if ( array_key_exists( 'checked_in', $b ) ) {
			$row['checked_in_at'] = $b['checked_in'] ? NWC::now() : null;
		}
		if ( $row ) {
			$wpdb->update( $t, $row, array( 'id' => $s->id ) );
			if ( isset( $row['status'] ) && $row['status'] !== $s->status ) {
				NWC::audit( 'registration_status_changed', array( 'entity' => 'form', 'entity_id' => $f->id, 'details' => array( 'entry' => (int) $s->id, 'from' => $s->status, 'to' => $row['status'] ) ) );
			}
		}
		return array( 'ok' => true );
	}

	private static function registered_count( $form_id ) {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . NWC_DB::t( 'form_submissions' ) . " WHERE form_id = %d AND status = 'registered' AND deleted_at IS NULL", $form_id ) ); // phpcs:ignore
	}

	/** Spreadsheet-safe CSV. Export needs its own permission. */
	public static function export( WP_REST_Request $r ) {
		global $wpdb;
		$f = self::row( (int) $r['id'] );
		if ( ! $f ) {
			return NWC::err( 'Form not found.', 404, 'nwc_not_found' );
		}
		if ( ! NWC_Tenancy::can( 'crm.export' ) ) {
			return NWC::err( 'Your role does not allow exports.', 403, 'nwc_forbidden' );
		}
		$g = self::guard( $f->kind );
		if ( is_wp_error( $g ) ) {
			return $g;
		}
		$fields = json_decode( (string) $f->fields, true ) ?: array();
		$rows   = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . NWC_DB::t( 'form_submissions' ) . ' WHERE form_id = %d AND deleted_at IS NULL ORDER BY id ASC', $f->id ) ); // phpcs:ignore
		$csv    = self::csv( $fields, $rows );
		NWC::audit( 'form_exported', array( 'kind' => 'admin', 'entity' => 'form', 'entity_id' => $f->id, 'details' => array( 'rows' => count( $rows ) ) ) );
		return array( 'filename' => $f->slug . '.csv', 'csv' => $csv );
	}

	public static function csv( $fields, $rows ) {
		$safe = function ( $v ) {
			$v = is_array( $v ) ? implode( ', ', $v ) : (string) $v;
			if ( '' !== $v && in_array( $v[0], array( '=', '+', '-', '@', "\t", "\r" ), true ) ) {
				$v = "'" . $v;
			}
			return '"' . str_replace( '"', '""', $v ) . '"';
		};
		$head = array_merge( array( 'Submitted', 'Status', 'Checked in' ), array_column( $fields, 'label' ) );
		$out  = implode( ',', array_map( $safe, $head ) ) . "\r\n";
		foreach ( $rows as $s ) {
			$d    = json_decode( (string) $s->data, true ) ?: array();
			$line = array( $s->created_at, $s->status, (string) $s->checked_in_at );
			foreach ( $fields as $x ) {
				$v      = $d[ $x['key'] ] ?? '';
				$line[] = is_array( $v ) && isset( $v['name'] ) ? $v['name'] : $v;
			}
			$out .= implode( ',', array_map( $safe, $line ) ) . "\r\n";
		}
		return $out;
	}

	public static function submission_file( WP_REST_Request $r ) {
		global $wpdb;
		$f = self::row( (int) $r['id'] );
		if ( ! $f ) {
			return NWC::err( 'Not found.', 404, 'nwc_not_found' );
		}
		$g = self::guard( $f->kind );
		if ( is_wp_error( $g ) ) {
			return $g;
		}
		$s   = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . NWC_DB::t( 'form_submissions' ) . ' WHERE id = %d AND form_id = %d', (int) $r['sid'], $f->id ) ); // phpcs:ignore
		$key = sanitize_key( (string) $r['field'] );
		$d   = $s ? json_decode( (string) $s->data, true ) : array();
		if ( empty( $d[ $key ]['path'] ) ) {
			return NWC::err( 'File not found.', 404, 'nwc_not_found' );
		}
		$path = NWC_Projects::storage_dir( $f->workspace_id ) . '/' . basename( $d[ $key ]['path'] );
		if ( ! is_file( $path ) ) {
			return NWC::err( 'File not found.', 404, 'nwc_not_found' );
		}
		if ( defined( 'NWC_TESTING' ) ) {
			return array( 'name' => $d[ $key ]['name'] );
		}
		header( 'Content-Type: application/octet-stream' );
		header( 'X-Content-Type-Options: nosniff' );
		header( 'Content-Disposition: attachment; filename="' . sanitize_file_name( $d[ $key ]['name'] ) . '"' );
		readfile( $path ); // phpcs:ignore
		exit;
	}

	/* ---------------------------- Public side ---------------------------- */

	/** @return array|null What a visitor may see. Counts a view. */
	public static function public_form( $slug ) {
		global $wpdb;
		$f = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . NWC_DB::t( 'forms' ) . " WHERE slug = %s AND status IN ('published','closed') AND deleted_at IS NULL", $slug ) ); // phpcs:ignore
		if ( ! $f ) {
			return null;
		}
		$w = NWC_Tenancy::workspace( $f->workspace_id );
		if ( ! $w || 'active' !== $w->status || ! NWC_Features::allowed( self::feature_for( $f->kind ), $w ) ) {
			return null;
		}
		$wpdb->query( $wpdb->prepare( 'UPDATE ' . NWC_DB::t( 'forms' ) . ' SET views = views + 1 WHERE id = %d', $f->id ) ); // phpcs:ignore
		$biz   = NWC_Bookings::business( $f->workspace_id );
		$fmt   = function ( $v ) use ( $w ) {
			return $v ? ( new DateTimeImmutable( $v . ' UTC' ) )->setTimezone( new DateTimeZone( $w->timezone ) )->format( 'j M Y, g:i A' ) : '';
		};
		$s     = json_decode( (string) $f->settings, true ) ?: array();
		$state = self::open_state( $f );
		return array(
			'slug' => $f->slug, 'kind' => $f->kind, 'title' => $f->title, 'description' => (string) $f->description,
			'fields' => json_decode( (string) $f->fields, true ) ?: array(), 'state' => $state,
			'event_start' => $fmt( $f->event_start ), 'event_end' => $fmt( $f->event_end ), 'location' => $s['location'] ?? '', 'privacy_note' => $s['privacy_note'] ?? '',
			'spots_left' => $f->capacity ? max( 0, (int) $f->capacity - self::registered_count( $f->id ) ) : null, 'waitlist' => (bool) $f->waitlist,
			'business' => array( 'name' => $biz ? ( $biz->display_name ?: $w->name ) : $w->name, 'logo_url' => $biz ? $biz->logo_url : '', 'color' => $biz && preg_match( '/^#[0-9a-fA-F]{6}$/', (string) $biz->brand_color ) ? $biz->brand_color : '#0f9d8a' ),
		);
	}

	private static function open_state( $f ) {
		$now = NWC::now();
		if ( 'closed' === $f->status ) {
			return 'closed';
		}
		if ( $f->opens_at && $now < $f->opens_at ) {
			return 'not_open';
		}
		if ( $f->closes_at && $now > $f->closes_at ) {
			return 'closed';
		}
		if ( 'registration' === $f->kind && $f->capacity && self::registered_count( $f->id ) >= (int) $f->capacity ) {
			return $f->waitlist ? 'waitlist' : 'full';
		}
		return 'open';
	}

	/** Conditional fields: hidden answers are dropped, and a hidden field is never required. */
	public static function visible( $field, $answers ) {
		if ( empty( $field['show_if']['field'] ) ) {
			return true;
		}
		$v = $answers[ $field['show_if']['field'] ] ?? '';
		return is_array( $v ) ? in_array( $field['show_if']['equals'], $v, true ) : (string) $v === (string) $field['show_if']['equals'];
	}

	/** @return array|WP_Error Clean answers. */
	public static function validate( $fields, $in, $files = array(), $ws = 0 ) {
		$out = array();
		foreach ( $fields as $f ) {
			$k = $f['key'];
			if ( ! self::visible( $f, $in ) ) {
				continue;
			}
			$raw = $in[ $k ] ?? '';
			if ( 'file' === $f['type'] ) {
				if ( isset( $files[ $k ] ) && ! empty( $files[ $k ]['tmp_name'] ) ) {
					$v = NWC_Projects::validate_upload( (string) $files[ $k ]['name'], (string) $files[ $k ]['tmp_name'], (int) $files[ $k ]['size'] );
					if ( is_wp_error( $v ) ) {
						return $v;
					}
					$safe = wp_generate_password( 24, false ) . '.' . strtolower( pathinfo( (string) $files[ $k ]['name'], PATHINFO_EXTENSION ) );
					$dest = NWC_Projects::storage_dir( $ws ) . '/' . $safe;
					if ( ! @rename( $files[ $k ]['tmp_name'], $dest ) && ! @copy( $files[ $k ]['tmp_name'], $dest ) ) { // phpcs:ignore
						return NWC::err( 'The file could not be saved.', 500, 'nwc_error' );
					}
					$out[ $k ] = array( 'name' => sanitize_file_name( (string) $files[ $k ]['name'] ), 'path' => $safe );
				} elseif ( ! empty( $f['required'] ) ) {
					return NWC::err( 'Please attach "' . $f['label'] . '".', 422, 'nwc_invalid_field', array( 'field' => $k ) );
				}
				continue;
			}
			if ( 'checkbox' === $f['type'] ) {
				$on = ! empty( $raw ) && 'false' !== $raw;
				if ( ! empty( $f['required'] ) && ! $on ) {
					return NWC::err( 'Please tick "' . $f['label'] . '".', 422, 'nwc_invalid_field', array( 'field' => $k ) );
				}
				$out[ $k ] = $on ? 'Yes' : 'No';
				continue;
			}
			$v = is_scalar( $raw ) ? trim( (string) $raw ) : '';
			if ( '' === $v ) {
				if ( ! empty( $f['required'] ) ) {
					return NWC::err( 'Please fill in "' . $f['label'] . '".', 422, 'nwc_invalid_field', array( 'field' => $k ) );
				}
				continue;
			}
			switch ( $f['type'] ) {
				case 'email':
					if ( ! is_email( $v ) ) {
						return NWC::err( '"' . $f['label'] . '" needs a valid email address.', 422, 'nwc_invalid_field', array( 'field' => $k ) );
					}
					$v = sanitize_email( $v );
					break;
				case 'phone':
					if ( strlen( preg_replace( '/\D/', '', $v ) ) < 7 ) {
						return NWC::err( '"' . $f['label'] . '" needs a valid phone number.', 422, 'nwc_invalid_field', array( 'field' => $k ) );
					}
					$v = mb_substr( sanitize_text_field( $v ), 0, 40 );
					break;
				case 'number':
					if ( ! is_numeric( $v ) ) {
						return NWC::err( '"' . $f['label'] . '" needs a number.', 422, 'nwc_invalid_field', array( 'field' => $k ) );
					}
					break;
				case 'date':
					if ( ! NWC::valid_date( $v ) ) {
						return NWC::err( '"' . $f['label'] . '" needs a valid date.', 422, 'nwc_invalid_field', array( 'field' => $k ) );
					}
					break;
				case 'dropdown':
				case 'choice':
					if ( ! in_array( $v, $f['options'], true ) ) {
						return NWC::err( 'Choose an option for "' . $f['label'] . '".', 422, 'nwc_invalid_field', array( 'field' => $k ) );
					}
					break;
				case 'textarea':
					$v = mb_substr( sanitize_textarea_field( $v ), 0, 5000 );
					break;
				default:
					$v = mb_substr( sanitize_text_field( $v ), 0, 1000 );
			}
			$out[ $k ] = $v;
		}
		return $out;
	}

	/** Multipart when there are files, otherwise JSON. Rate-limited, with a honeypot. */
	public static function submit( WP_REST_Request $r ) {
		global $wpdb;
		$f = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . NWC_DB::t( 'forms' ) . " WHERE slug = %s AND status = 'published' AND deleted_at IS NULL", (string) $r['slug'] ) ); // phpcs:ignore
		if ( ! $f ) {
			return NWC::err( 'This form is not available.', 404, 'nwc_not_found' );
		}
		$w = NWC_Tenancy::workspace( $f->workspace_id );
		if ( ! $w || ! NWC_Features::allowed( self::feature_for( $f->kind ), $w ) ) {
			return NWC::err( 'This form is not available.', 404, 'nwc_not_found' );
		}
		if ( ! NWC::rate_ok( 'form|' . $f->id . '|' . NWC::ip(), 15, 3600 ) ) {
			return NWC::err( 'Too many attempts. Please try again later.', 429, 'nwc_rate_limited' );
		}
		$b = $r->get_json_params();
		if ( ! is_array( $b ) || ! $b ) {
			$b = $r->get_body_params();
		}
		if ( ! empty( $b['website'] ) ) {
			return array( 'ok' => true, 'status' => 'received', 'message' => 'Thank you.' ); // Honeypot: looks fine, stores nothing.
		}
		$state = self::open_state( $f );
		if ( ! in_array( $state, array( 'open', 'waitlist' ), true ) ) {
			$why = array( 'full' => 'This event is full.', 'not_open' => 'Registration has not opened yet.' );
			return NWC::err( $why[ $state ] ?? 'This form is closed.', 409, 'nwc_closed' );
		}
		$fields  = json_decode( (string) $f->fields, true ) ?: array();
		$answers = self::validate( $fields, is_array( $b['answers'] ?? null ) ? $b['answers'] : $b, $r->get_file_params(), (int) $f->workspace_id );
		if ( is_wp_error( $answers ) ) {
			return $answers;
		}
		$s     = json_decode( (string) $f->settings, true ) ?: array();
		$pick  = function ( $setting, $type ) use ( $s, $fields, $answers ) {
			if ( ! empty( $s[ $setting ] ) && isset( $answers[ $s[ $setting ] ] ) ) {
				return (string) $answers[ $s[ $setting ] ];
			}
			foreach ( $fields as $x ) {
				if ( $type === $x['type'] && isset( $answers[ $x['key'] ] ) && is_string( $answers[ $x['key'] ] ) ) {
					if ( 'text' !== $type || preg_match( '/name/i', $x['label'] ) ) {
						return $answers[ $x['key'] ];
					}
				}
			}
			return '';
		};
		$name  = $pick( 'name_field', 'text' );
		$email = $pick( 'email_field', 'email' );
		$phone = $pick( 'phone_field', 'phone' );
		$lock  = 'form:' . $f->id;
		if ( ! NWC::lock( $lock ) ) {
			return NWC::err( 'Busy right now. Please try again.', 503, 'nwc_busy' );
		}
		try {
			$status = 'received';
			if ( 'registration' === $f->kind ) {
				$full   = $f->capacity && self::registered_count( $f->id ) >= (int) $f->capacity;
				if ( $full && ! $f->waitlist ) {
					return NWC::err( 'This event is full.', 409, 'nwc_full' );
				}
				$status = $full ? 'waitlisted' : 'registered';
			}
			$key = isset( $b['idempotency_key'] ) ? mb_substr( sanitize_text_field( (string) $b['idempotency_key'] ), 0, 64 ) : null;
			if ( $key ) {
				$dup = $wpdb->get_row( $wpdb->prepare( 'SELECT id, status FROM ' . NWC_DB::t( 'form_submissions' ) . ' WHERE form_id = %d AND idempotency_key = %s', $f->id, $key ) ); // phpcs:ignore
				if ( $dup ) {
					return array( 'ok' => true, 'duplicate' => true, 'status' => $dup->status, 'message' => self::confirmation( $s, $dup->status ) );
				}
			}
			$lead_id = null;
			$cust_id = null;
			$mode    = $s['create_crm'] ?? 'none';
			if ( 'none' !== $mode && '' !== $name && ( '' !== $email || '' !== $phone ) ) {
				if ( 'customer' === $mode ) {
					$cust_id = NWC_CRM::upsert_customer( (int) $f->workspace_id, array( 'name' => $name, 'phone' => $phone, 'email' => $email, 'source' => 'form' ) );
				} else {
					$lead_id = NWC_CRM::upsert_lead( (int) $f->workspace_id, array( 'name' => $name, 'phone' => $phone, 'email' => $email, 'enquiry' => mb_substr( $f->title, 0, 255 ), 'lead_type' => 'registration' === $f->kind ? 'registration' : 'form', 'source' => 'form', 'source_ref' => 'form:' . $f->id ) );
				}
			}
			$wpdb->insert( NWC_DB::t( 'form_submissions' ), array( 'workspace_id' => (int) $f->workspace_id, 'form_id' => (int) $f->id, 'data' => wp_json_encode( $answers ), 'name' => mb_substr( $name, 0, 190 ), 'email' => mb_substr( $email, 0, 190 ), 'phone' => mb_substr( $phone, 0, 40 ), 'status' => $status, 'customer_id' => $cust_id, 'lead_id' => $lead_id, 'idempotency_key' => $key ?: null, 'created_at' => NWC::now() ) );
		} finally {
			NWC::unlock( $lock );
		}
		if ( ! empty( $s['notify'] ) ) {
			NWC_Notify::workspace_owners( (int) $f->workspace_id, 'form', 'New entry: ' . $f->title, '' !== $name ? $name : 'A visitor submitted this form.', 'registration' === $f->kind ? '#/registrations/' . $f->id : '#/forms/' . $f->id );
		}
		return array( 'ok' => true, 'status' => $status, 'message' => self::confirmation( $s, $status ) );
	}

	private static function confirmation( $s, $status ) {
		if ( 'waitlisted' === $status ) {
			return 'The event is full, so you are on the waitlist. You will be contacted if a place opens.';
		}
		return ! empty( $s['confirmation'] ) ? $s['confirmation'] : 'Thank you. We have received your details.';
	}
}
