<?php
/**
 * POS for restaurants and cafes: tables → open order → items added over time → kitchen or barista
 * tickets (KOT, shown on the kitchen display) → bill → payment. One table can hold one open order;
 * orders can be merged (tables joined) or split (items moved to a new bill).
 *
 * Money: prices always come from the menu on the server, never from the request. Totals go through
 * NWC_Invoices::compute, so a POS bill and its GST invoice always agree. Paying creates the invoice
 * in the Workspace's numbering series and records each payment through NWC_Invoices::apply_payment
 * (locked, idempotent, no overpayment).
 */

defined( 'ABSPATH' ) || exit;

class NWC_POS {

	const CHANNELS = array( 'dine_in', 'takeaway', 'delivery', 'qr' );
	const KOT_FLOW = array( 'new' => 'preparing', 'preparing' => 'ready', 'ready' => 'served' );

	public static function routes() {
		NWC_REST::route( '/pos/floor', 'GET', array( __CLASS__, 'floor' ) );
		NWC_REST::route( '/pos/menu', 'GET', array( __CLASS__, 'menu' ) );
		NWC_REST::route( '/pos/orders', 'GET', array( __CLASS__, 'orders' ) );
		NWC_REST::route( '/pos/orders', 'POST', array( __CLASS__, 'open' ) );
		NWC_REST::route( '/pos/orders/(?P<id>\d+)', 'GET', array( __CLASS__, 'show' ) );
		NWC_REST::route( '/pos/orders/(?P<id>\d+)', 'PUT', array( __CLASS__, 'update' ) );
		NWC_REST::route( '/pos/orders/(?P<id>\d+)/items', 'POST', array( __CLASS__, 'add_items' ) );
		NWC_REST::route( '/pos/orders/(?P<id>\d+)/items/(?P<item>\d+)', 'PUT', array( __CLASS__, 'edit_item' ) );
		NWC_REST::route( '/pos/orders/(?P<id>\d+)/kot', 'POST', array( __CLASS__, 'send_kot' ) );
		NWC_REST::route( '/pos/orders/(?P<id>\d+)/merge', 'POST', array( __CLASS__, 'merge' ) );
		NWC_REST::route( '/pos/orders/(?P<id>\d+)/split', 'POST', array( __CLASS__, 'split' ) );
		NWC_REST::route( '/pos/orders/(?P<id>\d+)/bill', 'POST', array( __CLASS__, 'bill' ) );
		NWC_REST::route( '/pos/orders/(?P<id>\d+)/pay', 'POST', array( __CLASS__, 'pay' ) );
		NWC_REST::route( '/pos/orders/(?P<id>\d+)/cancel', 'POST', array( __CLASS__, 'cancel' ) );
		NWC_REST::route( '/pos/kds', 'GET', array( __CLASS__, 'kds' ) );
		NWC_REST::route( '/pos/kots/(?P<id>\d+)', 'PUT', array( __CLASS__, 'kot_status' ) );
		NWC_REST::route( '/pos/report', 'GET', array( __CLASS__, 'report' ) );
		NWC_REST::route( '/public/menu/(?P<slug>[a-z0-9-]+)', 'GET', array( __CLASS__, 'public_menu' ), 'public' );
		NWC_REST::route( '/public/menu/(?P<slug>[a-z0-9-]+)/order', 'POST', array( __CLASS__, 'public_order' ), 'public' );
	}

	private static function guard( $write = false ) {
		$g = NWC_Industry::guard_module( 'pos', $write ? 'write' : 'read' );
		return $g;
	}

	private static function t( $n ) {
		return NWC_DB::t( $n );
	}

	public static function order( $id, $ws = null ) {
		global $wpdb;
		$ws = $ws ? $ws : NWC_Tenancy::$current->id;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::t( 'pos_orders' ) . ' WHERE id = %d AND workspace_id = %d', $id, $ws ) ); // phpcs:ignore
	}

	private static function items( $order_id ) {
		global $wpdb;
		return $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::t( 'pos_items' ) . ' WHERE order_id = %d ORDER BY id ASC', $order_id ) ); // phpcs:ignore
	}

	/** Table ids held by open or billed orders. */
	public static function busy_tables( $ws, $except = 0 ) {
		global $wpdb;
		$ids = array();
		foreach ( $wpdb->get_results( $wpdb->prepare( 'SELECT id, table_ids FROM ' . self::t( 'pos_orders' ) . " WHERE workspace_id = %d AND status IN ('open','billed') AND id <> %d", $ws, $except ) ) as $o ) { // phpcs:ignore
			foreach ( (array) json_decode( (string) $o->table_ids, true ) as $t ) {
				$ids[ (int) $t ] = (int) $o->id;
			}
		}
		return $ids;
	}

	private static function out( $o, $detail = true ) {
		$o->id        = (int) $o->id;
		$o->table_ids = array_map( 'intval', (array) json_decode( (string) $o->table_ids, true ) );
		$o->tables    = array_map( function ( $t ) {
			$r = NWC_Industry::row( $t, null );
			return $r ? $r->title : '';
		}, $o->table_ids );
		foreach ( array( 'subtotal', 'discount', 'service_charge', 'tax', 'total', 'paid' ) as $k ) {
			$o->$k = (float) $o->$k;
		}
		unset( $o->idempotency_key );
		if ( $detail ) {
			$o->items = array_map( function ( $i ) {
				$i->id = (int) $i->id;
				$i->qty = (float) $i->qty;
				$i->unit_price = (float) $i->unit_price;
				$i->amount = (float) $i->amount;
				$i->addons = json_decode( (string) $i->addons, true );
				return $i;
			}, self::items( $o->id ) );
			global $wpdb;
			$o->kots = $wpdb->get_results( $wpdb->prepare( 'SELECT id, kot_no, station, status, created_at FROM ' . self::t( 'pos_kots' ) . ' WHERE order_id = %d ORDER BY id ASC', $o->id ) ); // phpcs:ignore
			$o->unsent = count( array_filter( $o->items, function ( $i ) {
				return 'new' === $i->status;
			} ) );
		}
		return $o;
	}

	/* ---------------------------- Floor and menu ---------------------------- */

	public static function floor() {
		global $wpdb;
		$g = self::guard();
		if ( is_wp_error( $g ) ) {
			return $g;
		}
		$ws    = (int) NWC_Tenancy::$current->id;
		$busy  = self::busy_tables( $ws );
		$R     = self::t( 'biz_records' );
		$areas = $wpdb->get_results( $wpdb->prepare( "SELECT id, title FROM $R WHERE workspace_id = %d AND entity = 'dining_area' AND deleted_at IS NULL ORDER BY title ASC", $ws ) ); // phpcs:ignore
		$today = NWC::today( NWC_Tenancy::$current->timezone );
		$resv  = array();
		foreach ( $wpdb->get_results( $wpdb->prepare( "SELECT ref_id, title, data FROM $R WHERE workspace_id = %d AND entity = 'table_reservation' AND deleted_at IS NULL AND on_date = %s AND status = 'booked'", $ws, $today ) ) as $x ) { // phpcs:ignore
			$resv[ (int) $x->ref_id ][] = array( 'name' => $x->title, 'time' => (string) ( json_decode( (string) $x->data, true )['time'] ?? '' ) );
		}
		$tables = array_map( function ( $t ) use ( $busy, $resv ) {
			$o = isset( $busy[ (int) $t->id ] ) ? self::order( $busy[ (int) $t->id ] ) : null;
			return array( 'id' => (int) $t->id, 'title' => $t->title, 'area_id' => $t->parent_id ? (int) $t->parent_id : null, 'seats' => (int) $t->qty, 'order_id' => $o ? (int) $o->id : null, 'order_status' => $o ? $o->status : null, 'order_total' => $o ? (float) $o->total : 0, 'since' => $o ? $o->created_at : null, 'reservations' => $resv[ (int) $t->id ] ?? array() );
		}, $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $R WHERE workspace_id = %d AND entity = 'dining_table' AND status = 'active' AND deleted_at IS NULL ORDER BY title ASC", $ws ) ) ); // phpcs:ignore
		$open = array_map( function ( $o ) {
			return self::out( $o, false );
		}, $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::t( 'pos_orders' ) . " WHERE workspace_id = %d AND status IN ('open','billed') ORDER BY id DESC LIMIT 100", $ws ) ) ); // phpcs:ignore
		return array( 'areas' => $areas, 'tables' => $tables, 'open_orders' => $open );
	}

	public static function menu() {
		$g = self::guard();
		return is_wp_error( $g ) ? $g : self::menu_payload( (int) NWC_Tenancy::$current->id, false );
	}

	public static function menu_payload( $ws, $public ) {
		global $wpdb;
		$R    = self::t( 'biz_records' );
		$cats = $wpdb->get_results( $wpdb->prepare( "SELECT id, title, data FROM $R WHERE workspace_id = %d AND entity = 'menu_category' AND deleted_at IS NULL", $ws ) ); // phpcs:ignore
		usort( $cats, function ( $a, $b ) {
			return ( (int) ( json_decode( (string) $a->data, true )['order'] ?? 0 ) <=> (int) ( json_decode( (string) $b->data, true )['order'] ?? 0 ) ) ?: strcmp( $a->title, $b->title );
		} );
		$where = $public ? "AND status = 'available'" : "AND status <> 'hidden'";
		$items = array();
		foreach ( $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $R WHERE workspace_id = %d AND entity = 'menu_item' AND deleted_at IS NULL $where ORDER BY title ASC", $ws ) ) as $m ) { // phpcs:ignore
			$d       = json_decode( (string) $m->data, true );
			$items[] = array( 'id' => (int) $m->id, 'name' => $m->title, 'category_id' => $m->parent_id ? (int) $m->parent_id : null, 'price' => (float) $m->amount, 'description' => (string) ( $d['description'] ?? '' ), 'image' => (string) ( $d['image'] ?? '' ), 'veg' => (string) ( $d['veg'] ?? '' ), 'variants' => (array) ( $d['variants'] ?? array() ), 'addons' => (array) ( $d['addons'] ?? array() ), 'status' => $m->status, 'code' => $public ? null : $m->code );
		}
		return array( 'categories' => array_map( function ( $c ) {
			return array( 'id' => (int) $c->id, 'name' => $c->title );
		}, $cats ), 'items' => $items );
	}

	/* ---------------------------- Orders ---------------------------- */

	public static function orders( WP_REST_Request $r ) {
		global $wpdb;
		$g = self::guard();
		if ( is_wp_error( $g ) ) {
			return $g;
		}
		$vals = array( (int) NWC_Tenancy::$current->id );
		$sql  = 'workspace_id = %d';
		$st   = sanitize_key( (string) $r->get_param( 'status' ) );
		if ( 'active' === $st ) {
			$sql .= " AND status IN ('open','billed')";
		} elseif ( '' !== $st ) {
			$sql   .= ' AND status = %s';
			$vals[] = $st;
		}
		if ( NWC::valid_date( (string) $r->get_param( 'date' ) ) ) {
			$tz     = new DateTimeZone( NWC_Tenancy::$current->timezone );
			$sql   .= ' AND created_at BETWEEN %s AND %s';
			$vals[] = ( new DateTimeImmutable( $r->get_param( 'date' ) . ' 00:00:00', $tz ) )->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );
			$vals[] = ( new DateTimeImmutable( $r->get_param( 'date' ) . ' 23:59:59', $tz ) )->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );
		}
		return array( 'items' => array_map( function ( $o ) {
			return self::out( $o, false );
		}, $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::t( 'pos_orders' ) . " WHERE $sql ORDER BY id DESC LIMIT 200", $vals ) ) ) ); // phpcs:ignore
	}

	public static function show( WP_REST_Request $r ) {
		$g = self::guard();
		if ( is_wp_error( $g ) ) {
			return $g;
		}
		$o = self::order( (int) $r['id'] );
		return $o ? self::out( $o ) : NWC::err( 'Order not found.', 404, 'nwc_not_found' );
	}

	private static function valid_tables( $ws, $ids ) {
		$out = array();
		foreach ( array_unique( array_filter( array_map( 'absint', (array) $ids ) ) ) as $t ) {
			$row = NWC_Industry::row( $t, $ws );
			if ( ! $row || 'dining_table' !== $row->entity || 'active' !== $row->status ) {
				return NWC::err( 'A selected table was not found.', 404, 'nwc_not_found' );
			}
			$out[] = (int) $t;
		}
		return $out;
	}

	/** @return object|WP_Error */
	public static function create_order( $ws, $in, $source = 'staff' ) {
		global $wpdb;
		$ch = in_array( $in['channel'] ?? '', self::CHANNELS, true ) ? $in['channel'] : 'dine_in';
		$tables = self::valid_tables( $ws, $in['table_ids'] ?? array() );
		if ( is_wp_error( $tables ) ) {
			return $tables;
		}
		if ( in_array( $ch, array( 'dine_in', 'qr' ), true ) && ! $tables ) {
			return NWC::err( 'Choose a table for a dine-in order.' );
		}
		$idem = mb_substr( sanitize_text_field( (string) ( $in['idempotency_key'] ?? '' ) ), 0, 64 );
		if ( ! NWC::lock( 'pos:' . $ws ) ) {
			return NWC::err( 'Busy right now. Please try again.', 503, 'nwc_busy' );
		}
		try {
			if ( '' !== $idem ) {
				$dup = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::t( 'pos_orders' ) . ' WHERE workspace_id = %d AND idempotency_key = %s', $ws, $idem ) ); // phpcs:ignore
				if ( $dup ) {
					return $dup;
				}
			}
			$busy = self::busy_tables( $ws );
			foreach ( $tables as $t ) {
				if ( isset( $busy[ $t ] ) ) {
					return NWC::err( 'That table already has an open order. Add items to it, or merge.', 409, 'nwc_table_busy', array( 'order_id' => $busy[ $t ] ) );
				}
			}
			$n   = 1 + (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . self::t( 'pos_orders' ) . ' WHERE workspace_id = %d', $ws ) ); // phpcs:ignore
			$now = NWC::now();
			$phone = mb_substr( sanitize_text_field( (string) ( $in['phone'] ?? '' ) ), 0, 40 );
			$name  = mb_substr( sanitize_text_field( (string) ( $in['customer_name'] ?? '' ) ), 0, 190 );
			$cid   = absint( $in['customer_id'] ?? 0 );
			if ( ! $cid && '' !== $name && '' !== NWC::norm_phone( $phone ) ) {
				$cid = NWC_CRM::upsert_customer( $ws, array( 'name' => $name, 'phone' => $phone, 'source' => 'pos' ) );
			}
			$ok = $wpdb->insert( self::t( 'pos_orders' ), array(
				'workspace_id' => $ws, 'order_no' => 'ORD-' . str_pad( (string) $n, 5, '0', STR_PAD_LEFT ), 'channel' => $ch, 'table_ids' => wp_json_encode( $tables ),
				'status' => 'open', 'customer_id' => $cid ? $cid : null, 'customer_name' => $name, 'phone' => $phone, 'guests' => absint( $in['guests'] ?? 0 ),
				'waiter_id' => get_current_user_id() ? get_current_user_id() : null, 'source' => $source, 'notes' => sanitize_textarea_field( (string) ( $in['notes'] ?? '' ) ),
				'idempotency_key' => '' !== $idem ? $idem : null, 'created_by' => get_current_user_id(), 'created_at' => $now, 'updated_at' => $now,
			) );
			if ( ! $ok ) {
				return NWC::err( 'Could not open the order.', 500, 'nwc_error' );
			}
			$id = (int) $wpdb->insert_id;
		} finally {
			NWC::unlock( 'pos:' . $ws );
		}
		NWC::audit( 'pos_order_opened', array( 'workspace_id' => $ws, 'entity' => 'pos_order', 'entity_id' => $id, 'details' => array( 'channel' => $ch, 'tables' => $tables ) ) );
		return self::order( $id, $ws );
	}

	public static function open( WP_REST_Request $r ) {
		$g = self::guard( true );
		if ( is_wp_error( $g ) ) {
			return $g;
		}
		$b = NWC_REST::body( $r );
		$o = self::create_order( (int) NWC_Tenancy::$current->id, $b );
		if ( is_wp_error( $o ) ) {
			return $o;
		}
		if ( ! empty( $b['items'] ) ) {
			$res = self::insert_items( $o, $b['items'] );
			if ( is_wp_error( $res ) ) {
				return $res;
			}
		}
		return self::out( self::order( $o->id ) );
	}

	public static function update( WP_REST_Request $r ) {
		global $wpdb;
		$g = self::guard( true );
		if ( is_wp_error( $g ) ) {
			return $g;
		}
		$o = self::order( (int) $r['id'] );
		if ( ! $o || ! in_array( $o->status, array( 'open', 'billed' ), true ) ) {
			return NWC::err( 'Only an open order can be changed.', 409, 'nwc_conflict' );
		}
		$b   = NWC_REST::body( $r );
		$row = array( 'updated_at' => NWC::now() );
		foreach ( array( 'customer_name' => 190, 'phone' => 40 ) as $k => $len ) {
			if ( array_key_exists( $k, $b ) ) {
				$row[ $k ] = mb_substr( sanitize_text_field( (string) $b[ $k ] ), 0, $len );
			}
		}
		if ( array_key_exists( 'guests', $b ) ) {
			$row['guests'] = absint( $b['guests'] );
		}
		if ( array_key_exists( 'notes', $b ) ) {
			$row['notes'] = sanitize_textarea_field( (string) $b['notes'] );
		}
		if ( array_key_exists( 'customer_id', $b ) ) {
			$cid = absint( $b['customer_id'] );
			if ( $cid && (int) $wpdb->get_var( $wpdb->prepare( 'SELECT workspace_id FROM ' . self::t( 'customers' ) . ' WHERE id = %d', $cid ) ) !== (int) $o->workspace_id ) { // phpcs:ignore
				return NWC::err( 'That customer was not found.', 404, 'nwc_not_found' );
			}
			$row['customer_id'] = $cid ? $cid : null;
		}
		if ( array_key_exists( 'table_ids', $b ) ) { // Move the order to other tables.
			$tables = self::valid_tables( $o->workspace_id, $b['table_ids'] );
			if ( is_wp_error( $tables ) ) {
				return $tables;
			}
			$busy = self::busy_tables( $o->workspace_id, $o->id );
			foreach ( $tables as $t ) {
				if ( isset( $busy[ $t ] ) ) {
					return NWC::err( 'That table already has an open order.', 409, 'nwc_table_busy', array( 'order_id' => $busy[ $t ] ) );
				}
			}
			$row['table_ids'] = wp_json_encode( $tables );
		}
		$wpdb->update( self::t( 'pos_orders' ), $row, array( 'id' => $o->id ) );
		return self::out( self::order( $o->id ) );
	}

	/** Line priced from the menu: base or chosen variant, plus chosen add-ons. */
	private static function price_line( $ws, $it ) {
		$m = NWC_Industry::row( absint( $it['menu_item_id'] ?? 0 ), $ws );
		if ( ! $m || 'menu_item' !== $m->entity ) {
			return NWC::err( 'A menu item was not found.', 404, 'nwc_not_found' );
		}
		if ( 'available' !== $m->status ) {
			return NWC::err( $m->title . ' is not available right now.', 409, 'nwc_sold_out' );
		}
		$d     = json_decode( (string) $m->data, true );
		$price = (float) $m->amount;
		$var   = '';
		if ( ! empty( $it['variant'] ) ) {
			$found = false;
			foreach ( (array) ( $d['variants'] ?? array() ) as $v ) {
				if ( $v['name'] === $it['variant'] ) {
					$price = (float) $v['price'];
					$var   = $v['name'];
					$found = true;
				}
			}
			if ( ! $found ) {
				return NWC::err( 'Choose a valid size or variant for ' . $m->title . '.' );
			}
		}
		$adds = array();
		foreach ( array_slice( (array) ( $it['addons'] ?? array() ), 0, 20 ) as $name ) {
			$ok = false;
			foreach ( (array) ( $d['addons'] ?? array() ) as $a ) {
				if ( $a['name'] === $name ) {
					$price += (float) $a['price'];
					$adds[] = $a['name'];
					$ok     = true;
				}
			}
			if ( ! $ok ) {
				return NWC::err( 'Choose valid add-ons for ' . $m->title . '.' );
			}
		}
		$qty = round( (float) ( $it['qty'] ?? 1 ), 3 );
		if ( $qty <= 0 || $qty > 999 ) {
			return NWC::err( 'Enter a quantity for ' . $m->title . '.' );
		}
		return array( 'menu_item_id' => (int) $m->id, 'name' => $m->title, 'variant' => $var, 'addons' => wp_json_encode( $adds ), 'qty' => $qty, 'unit_price' => round( $price, 2 ), 'amount' => round( $price * $qty, 2 ), 'tax_rate' => isset( $d['tax_rate'] ) && null !== $d['tax_rate'] ? (float) $d['tax_rate'] : 5.0, 'notes' => mb_substr( sanitize_text_field( (string) ( $it['notes'] ?? '' ) ), 0, 255 ) );
	}

	/** @return true|WP_Error */
	public static function insert_items( $o, $items ) {
		global $wpdb;
		$lines = array();
		foreach ( array_slice( (array) $items, 0, 60 ) as $it ) {
			$l = self::price_line( (int) $o->workspace_id, (array) $it );
			if ( is_wp_error( $l ) ) {
				return $l;
			}
			$lines[] = $l;
		}
		if ( ! $lines ) {
			return NWC::err( 'Add at least one item.' );
		}
		$now = NWC::now();
		foreach ( $lines as $l ) {
			$wpdb->insert( self::t( 'pos_items' ), array_merge( $l, array( 'workspace_id' => (int) $o->workspace_id, 'order_id' => (int) $o->id, 'status' => 'new', 'created_by' => get_current_user_id(), 'created_at' => $now, 'updated_at' => $now ) ) );
		}
		$up = array( 'updated_at' => $now );
		if ( 'billed' === $o->status ) {
			$up['status'] = 'open'; // More items after the bill was printed: back to open, re-bill.
		}
		$wpdb->update( self::t( 'pos_orders' ), $up, array( 'id' => $o->id ) );
		self::recalc( $o->id, (int) $o->workspace_id );
		return true;
	}

	public static function add_items( WP_REST_Request $r ) {
		$g = self::guard( true );
		if ( is_wp_error( $g ) ) {
			return $g;
		}
		$o = self::order( (int) $r['id'] );
		if ( ! $o || ! in_array( $o->status, array( 'open', 'billed' ), true ) ) {
			return NWC::err( 'Items can only be added to an open order.', 409, 'nwc_conflict' );
		}
		$res = self::insert_items( $o, NWC_REST::body( $r )['items'] ?? array() );
		return is_wp_error( $res ) ? $res : self::out( self::order( $o->id ) );
	}

	/** Unsent lines change freely. A line already in the kitchen is voided with a reason, never edited. */
	public static function edit_item( WP_REST_Request $r ) {
		global $wpdb;
		$g = self::guard( true );
		if ( is_wp_error( $g ) ) {
			return $g;
		}
		$o = self::order( (int) $r['id'] );
		if ( ! $o || ! in_array( $o->status, array( 'open', 'billed' ), true ) ) {
			return NWC::err( 'Only an open order can be changed.', 409, 'nwc_conflict' );
		}
		$it = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::t( 'pos_items' ) . ' WHERE id = %d AND order_id = %d', (int) $r['item'], $o->id ) ); // phpcs:ignore
		if ( ! $it || 'void' === $it->status ) {
			return NWC::err( 'Item not found.', 404, 'nwc_not_found' );
		}
		$b = NWC_REST::body( $r );
		if ( ! empty( $b['void'] ) ) {
			if ( 'new' !== $it->status && ! NWC_Tenancy::is_manager() ) {
				return NWC::err( 'A manager must void an item that was already sent to the kitchen.', 403, 'nwc_forbidden' );
			}
			$reason = trim( sanitize_text_field( (string) ( $b['reason'] ?? '' ) ) );
			if ( 'new' !== $it->status && '' === $reason ) {
				return NWC::err( 'Give a reason for voiding this item.' );
			}
			$wpdb->update( self::t( 'pos_items' ), array( 'status' => 'void', 'notes' => mb_substr( 'Void: ' . $reason, 0, 255 ), 'updated_at' => NWC::now() ), array( 'id' => $it->id ) );
			NWC::audit( 'pos_item_voided', array( 'entity' => 'pos_order', 'entity_id' => $o->id, 'details' => array( 'item' => $it->name, 'qty' => (float) $it->qty, 'sent' => 'new' !== $it->status, 'reason' => $reason ) ) );
		} elseif ( isset( $b['qty'] ) ) {
			if ( 'new' !== $it->status ) {
				return NWC::err( 'This item is already in the kitchen. Void it and add a new line instead.', 409, 'nwc_conflict' );
			}
			$q = round( (float) $b['qty'], 3 );
			if ( $q <= 0 ) {
				return NWC::err( 'Enter a quantity above zero, or remove the item.' );
			}
			$wpdb->update( self::t( 'pos_items' ), array( 'qty' => $q, 'amount' => round( $q * (float) $it->unit_price, 2 ), 'updated_at' => NWC::now() ), array( 'id' => $it->id ) );
		}
		if ( 'billed' === $o->status ) {
			$wpdb->update( self::t( 'pos_orders' ), array( 'status' => 'open' ), array( 'id' => $o->id ) );
		}
		self::recalc( $o->id, (int) $o->workspace_id );
		return self::out( self::order( $o->id ) );
	}

	/** One ticket per station for the lines not yet sent. Ingredients are taken out of stock here. */
	public static function send_kot( WP_REST_Request $r ) {
		global $wpdb;
		$g = self::guard( true );
		if ( is_wp_error( $g ) ) {
			return $g;
		}
		$o = self::order( (int) $r['id'] );
		if ( ! $o || ! in_array( $o->status, array( 'open', 'billed' ), true ) ) {
			return NWC::err( 'Only an open order can send tickets.', 409, 'nwc_conflict' );
		}
		$ws = (int) $o->workspace_id;
		if ( ! NWC::lock( 'kot:' . $ws ) ) {
			return NWC::err( 'Busy right now. Please try again.', 503, 'nwc_busy' );
		}
		$made = array();
		try {
			$new = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::t( 'pos_items' ) . " WHERE order_id = %d AND status = 'new'", $o->id ) ); // phpcs:ignore
			if ( ! $new ) {
				return NWC::err( 'There is nothing new to send.', 409, 'nwc_nothing' );
			}
			$default = NWC_Industry::config( NWC_Tenancy::workspace( $ws ) )['station'];
			$by      = array();
			foreach ( $new as $it ) {
				$m = $it->menu_item_id ? NWC_Industry::row( $it->menu_item_id, $ws ) : null;
				$d = $m ? json_decode( (string) $m->data, true ) : array();
				$st = in_array( $d['station'] ?? '', array( 'kitchen', 'barista', 'bar' ), true ) ? $d['station'] : $default;
				$by[ $st ][] = array( $it, $d );
			}
			$now = NWC::now();
			$no  = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COALESCE(MAX(kot_no),0) FROM ' . self::t( 'pos_kots' ) . ' WHERE workspace_id = %d', $ws ) ); // phpcs:ignore
			foreach ( $by as $station => $lines ) {
				$wpdb->insert( self::t( 'pos_kots' ), array( 'workspace_id' => $ws, 'order_id' => (int) $o->id, 'kot_no' => ++$no, 'station' => $station, 'status' => 'new', 'created_at' => $now, 'updated_at' => $now ) );
				$kid    = (int) $wpdb->insert_id;
				$made[] = $kid;
				foreach ( $lines as $pair ) {
					list( $it, $d ) = $pair;
					$wpdb->update( self::t( 'pos_items' ), array( 'status' => 'sent', 'kot_id' => $kid, 'updated_at' => $now ), array( 'id' => $it->id ) );
					foreach ( (array) ( $d['recipe'] ?? array() ) as $rc ) { // name = ingredient id, price = quantity per item.
						$ing = NWC_Industry::row( absint( $rc['name'] ), $ws );
						if ( $ing && 'ingredient' === $ing->entity ) {
							$wpdb->query( $wpdb->prepare( 'UPDATE ' . self::t( 'biz_records' ) . ' SET qty = qty - %f, updated_at = %s WHERE id = %d', (float) $rc['price'] * (float) $it->qty, $now, $ing->id ) ); // phpcs:ignore
						}
					}
				}
			}
		} finally {
			NWC::unlock( 'kot:' . $ws );
		}
		NWC::audit( 'pos_kot_sent', array( 'entity' => 'pos_order', 'entity_id' => $o->id, 'details' => array( 'kots' => $made ) ) );
		return self::out( self::order( $o->id ) );
	}

	public static function kds( WP_REST_Request $r ) {
		global $wpdb;
		$g = NWC_Industry::guard_module( 'kds', 'read' );
		if ( is_wp_error( $g ) ) {
			return $g;
		}
		$ws   = (int) NWC_Tenancy::$current->id;
		$vals = array( $ws );
		$sql  = "workspace_id = %d AND status IN ('new','preparing','ready')";
		$st   = sanitize_key( (string) $r->get_param( 'station' ) );
		if ( '' !== $st ) {
			$sql   .= ' AND station = %s';
			$vals[] = $st;
		}
		$out = array();
		foreach ( $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::t( 'pos_kots' ) . " WHERE $sql ORDER BY id ASC LIMIT 100", $vals ) ) as $k ) { // phpcs:ignore
			$o     = self::order( $k->order_id, $ws );
			$out[] = array( 'id' => (int) $k->id, 'kot_no' => (int) $k->kot_no, 'station' => $k->station, 'status' => $k->status, 'created_at' => $k->created_at, 'order_no' => $o ? $o->order_no : '', 'channel' => $o ? $o->channel : '', 'tables' => $o ? self::out( clone $o, false )->tables : array(),
				'items' => array_map( function ( $i ) {
					return array( 'name' => $i->name, 'variant' => $i->variant, 'addons' => json_decode( (string) $i->addons, true ), 'qty' => (float) $i->qty, 'notes' => $i->notes, 'void' => 'void' === $i->status );
				}, $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::t( 'pos_items' ) . ' WHERE kot_id = %d', $k->id ) ) ) ); // phpcs:ignore
		}
		return array( 'tickets' => $out );
	}

	/** new → preparing → ready → served, one step at a time. */
	public static function kot_status( WP_REST_Request $r ) {
		global $wpdb;
		$g = NWC_Industry::guard_module( 'kds', 'write' );
		if ( is_wp_error( $g ) ) {
			return $g;
		}
		$k = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::t( 'pos_kots' ) . ' WHERE id = %d AND workspace_id = %d', (int) $r['id'], NWC_Tenancy::$current->id ) ); // phpcs:ignore
		if ( ! $k ) {
			return NWC::err( 'Ticket not found.', 404, 'nwc_not_found' );
		}
		$to = sanitize_key( (string) ( NWC_REST::body( $r )['status'] ?? '' ) );
		if ( ( self::KOT_FLOW[ $k->status ] ?? '' ) !== $to ) {
			return NWC::err( 'A ticket that is ' . $k->status . ' cannot move to ' . $to . '.', 409, 'nwc_bad_transition' );
		}
		$won = $wpdb->query( $wpdb->prepare( 'UPDATE ' . self::t( 'pos_kots' ) . ' SET status = %s, updated_at = %s WHERE id = %d AND status = %s', $to, NWC::now(), $k->id, $k->status ) ); // phpcs:ignore
		if ( ! $won ) {
			return NWC::err( 'Someone else just updated this ticket.', 409, 'nwc_conflict' );
		}
		$item_state = array( 'preparing' => 'preparing', 'ready' => 'ready', 'served' => 'served' )[ $to ];
		$wpdb->query( $wpdb->prepare( 'UPDATE ' . self::t( 'pos_items' ) . " SET status = %s WHERE kot_id = %d AND status <> 'void'", $item_state, $k->id ) ); // phpcs:ignore
		return array( 'ok' => true, 'status' => $to );
	}

	/** Moves another open order (its items and tables) into this one. */
	public static function merge( WP_REST_Request $r ) {
		global $wpdb;
		$g = self::guard( true );
		if ( is_wp_error( $g ) ) {
			return $g;
		}
		$into = self::order( (int) $r['id'] );
		$from = self::order( absint( NWC_REST::body( $r )['from_order_id'] ?? 0 ) );
		if ( ! $into || ! $from || $into->id === $from->id || ! in_array( $into->status, array( 'open', 'billed' ), true ) || ! in_array( $from->status, array( 'open', 'billed' ), true ) ) {
			return NWC::err( 'Choose two different open orders.', 409, 'nwc_conflict' );
		}
		$tables = array_values( array_unique( array_merge( (array) json_decode( (string) $into->table_ids, true ), (array) json_decode( (string) $from->table_ids, true ) ) ) );
		$wpdb->query( $wpdb->prepare( 'UPDATE ' . self::t( 'pos_items' ) . ' SET order_id = %d WHERE order_id = %d', $into->id, $from->id ) ); // phpcs:ignore
		$wpdb->query( $wpdb->prepare( 'UPDATE ' . self::t( 'pos_kots' ) . ' SET order_id = %d WHERE order_id = %d', $into->id, $from->id ) ); // phpcs:ignore
		$wpdb->update( self::t( 'pos_orders' ), array( 'status' => 'merged', 'merged_into' => $into->id, 'table_ids' => '[]', 'closed_at' => NWC::now(), 'updated_at' => NWC::now() ), array( 'id' => $from->id ) );
		$wpdb->update( self::t( 'pos_orders' ), array( 'table_ids' => wp_json_encode( array_map( 'intval', $tables ) ), 'status' => 'open', 'guests' => (int) $into->guests + (int) $from->guests, 'updated_at' => NWC::now() ), array( 'id' => $into->id ) );
		self::recalc( $into->id, (int) $into->workspace_id );
		self::recalc( $from->id, (int) $from->workspace_id );
		NWC::audit( 'pos_orders_merged', array( 'entity' => 'pos_order', 'entity_id' => $into->id, 'details' => array( 'from' => $from->order_no ) ) );
		return self::out( self::order( $into->id ) );
	}

	/**
	 * Moves chosen lines (optionally part of a line's quantity) to a new bill for the same table.
	 * Body: {items: [{id, qty?}]}.
	 */
	public static function split( WP_REST_Request $r ) {
		global $wpdb;
		$g = self::guard( true );
		if ( is_wp_error( $g ) ) {
			return $g;
		}
		$o = self::order( (int) $r['id'] );
		if ( ! $o || ! in_array( $o->status, array( 'open', 'billed' ), true ) ) {
			return NWC::err( 'Only an open order can be split.', 409, 'nwc_conflict' );
		}
		$pick = (array) ( NWC_REST::body( $r )['items'] ?? array() );
		$live = array();
		foreach ( self::items( $o->id ) as $it ) {
			if ( 'void' !== $it->status ) {
				$live[ (int) $it->id ] = $it;
			}
		}
		$moves = array();
		foreach ( $pick as $p ) {
			$id = absint( is_array( $p ) ? ( $p['id'] ?? 0 ) : $p );
			if ( ! isset( $live[ $id ] ) ) {
				return NWC::err( 'A chosen item is not on this order.', 404, 'nwc_not_found' );
			}
			$q = is_array( $p ) && isset( $p['qty'] ) ? round( (float) $p['qty'], 3 ) : (float) $live[ $id ]->qty;
			if ( $q <= 0 || $q > (float) $live[ $id ]->qty ) {
				return NWC::err( 'Choose a quantity up to ' . (float) $live[ $id ]->qty . ' for ' . $live[ $id ]->name . '.' );
			}
			$moves[ $id ] = $q;
		}
		if ( ! $moves || count( $moves ) === count( $live ) && array_sum( array_map( function ( $id ) use ( $moves, $live ) {
			return $moves[ $id ] >= (float) $live[ $id ]->qty ? 1 : 0;
		}, array_keys( $moves ) ) ) === count( $live ) ) {
			return NWC::err( 'Leave at least one item on the first bill.' );
		}
		$now = NWC::now();
		$n   = 1 + (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . self::t( 'pos_orders' ) . ' WHERE workspace_id = %d', $o->workspace_id ) ); // phpcs:ignore
		$wpdb->insert( self::t( 'pos_orders' ), array( 'workspace_id' => (int) $o->workspace_id, 'order_no' => 'ORD-' . str_pad( (string) $n, 5, '0', STR_PAD_LEFT ), 'channel' => $o->channel, 'table_ids' => '[]', 'status' => 'open', 'customer_id' => $o->customer_id, 'customer_name' => $o->customer_name, 'phone' => $o->phone, 'split_from' => (int) $o->id, 'source' => 'split', 'created_by' => get_current_user_id(), 'created_at' => $now, 'updated_at' => $now ) );
		$nid = (int) $wpdb->insert_id;
		foreach ( $moves as $id => $q ) {
			$it = $live[ $id ];
			if ( $q >= (float) $it->qty ) {
				$wpdb->update( self::t( 'pos_items' ), array( 'order_id' => $nid, 'updated_at' => $now ), array( 'id' => $id ) );
			} else {
				$left = round( (float) $it->qty - $q, 3 );
				$wpdb->update( self::t( 'pos_items' ), array( 'qty' => $left, 'amount' => round( $left * (float) $it->unit_price, 2 ), 'updated_at' => $now ), array( 'id' => $id ) );
				$copy = (array) $it;
				unset( $copy['id'] );
				$wpdb->insert( self::t( 'pos_items' ), array_merge( $copy, array( 'order_id' => $nid, 'qty' => $q, 'amount' => round( $q * (float) $it->unit_price, 2 ), 'created_at' => $now, 'updated_at' => $now ) ) );
			}
		}
		$wpdb->update( self::t( 'pos_orders' ), array( 'status' => 'open', 'updated_at' => $now ), array( 'id' => $o->id ) );
		self::recalc( $o->id, (int) $o->workspace_id );
		self::recalc( $nid, (int) $o->workspace_id );
		NWC::audit( 'pos_order_split', array( 'entity' => 'pos_order', 'entity_id' => $o->id, 'details' => array( 'new_order' => $nid ) ) );
		return array( 'order' => self::out( self::order( $o->id ) ), 'new_order' => self::out( self::order( $nid ) ) );
	}

	/** Invoice-ready lines for an order, with the discount spread over lines and service charge last. */
	private static function lines( $o, $discount_paise, $service_pct ) {
		$live = array_values( array_filter( self::items( $o->id ), function ( $i ) {
			return 'void' !== $i->status;
		} ) );
		$gross = 0;
		foreach ( $live as $i ) {
			$gross += (int) round( (float) $i->amount * 100 );
		}
		$discount_paise = min( max( 0, $discount_paise ), $gross );
		$out  = array();
		$left = $discount_paise;
		$max_rate = 0;
		foreach ( $live as $k => $i ) {
			$amt  = (int) round( (float) $i->amount * 100 );
			$dl   = $k === count( $live ) - 1 ? $left : ( $gross ? (int) floor( $discount_paise * $amt / $gross ) : 0 );
			$left -= $dl;
			$desc = $i->name . ( $i->variant ? ' (' . $i->variant . ')' : '' );
			$adds = (array) json_decode( (string) $i->addons, true );
			if ( $adds ) {
				$desc .= ' + ' . implode( ', ', $adds );
			}
			$out[]    = array( 'description' => $desc, 'qty' => (float) $i->qty, 'unit_price' => (float) $i->unit_price, 'discount' => $dl / 100, 'tax_rate' => (float) $i->tax_rate );
			$max_rate = max( $max_rate, (float) $i->tax_rate );
		}
		$svc = 0;
		if ( $service_pct > 0 && $out ) {
			$svc   = (int) round( ( $gross - $discount_paise ) * $service_pct / 100 );
			$out[] = array( 'description' => 'Service charge (' . rtrim( rtrim( number_format( $service_pct, 2 ), '0' ), '.' ) . '%)', 'qty' => 1, 'unit_price' => $svc / 100, 'discount' => 0, 'tax_rate' => $max_rate );
		}
		return array( $out, $svc / 100, $discount_paise / 100 );
	}

	public static function recalc( $id, $ws, $discount = null, $service_pct = null ) {
		global $wpdb;
		$o = self::order( $id, $ws );
		if ( ! $o ) {
			return null;
		}
		$biz  = NWC_Bookings::business( $ws );
		$disc = null === $discount ? (int) round( (float) $o->discount * 100 ) : (int) round( $discount * 100 );
		$pct  = null === $service_pct ? ( (float) $o->subtotal > 0 && (float) $o->service_charge > 0 ? round( 100 * (float) $o->service_charge / max( 0.01, (float) $o->subtotal - (float) $o->discount ), 2 ) : 0 ) : (float) $service_pct;
		list( $lines, $svc, $disc_r ) = self::lines( $o, $disc, $pct );
		$sub = 0;
		$tax = 0;
		$tot = 0;
		if ( $lines ) {
			$c = NWC_Invoices::compute( $lines, $biz, (string) $biz->state_code );
			if ( ! is_wp_error( $c ) ) {
				$sub = (float) $c['totals']['subtotal'] - $svc;
				$tax = (float) $c['totals']['tax_total'];
				$tot = (float) $c['totals']['grand_total'];
			}
		}
		$wpdb->update( self::t( 'pos_orders' ), array( 'subtotal' => round( $sub, 2 ), 'discount' => $disc_r, 'service_charge' => $svc, 'tax' => $tax, 'total' => $tot, 'updated_at' => NWC::now() ), array( 'id' => $id ) );
		return array( $lines, $svc );
	}

	/** Body: {discount_type: percent|amount, discount, service_charge_pct}. Freezes the bill. */
	public static function bill( WP_REST_Request $r ) {
		global $wpdb;
		$g = self::guard( true );
		if ( is_wp_error( $g ) ) {
			return $g;
		}
		$o = self::order( (int) $r['id'] );
		if ( ! $o || ! in_array( $o->status, array( 'open', 'billed' ), true ) ) {
			return NWC::err( 'Only an open order can be billed.', 409, 'nwc_conflict' );
		}
		$b    = NWC_REST::body( $r );
		$live = array_filter( self::items( $o->id ), function ( $i ) {
			return 'void' !== $i->status;
		} );
		if ( ! $live ) {
			return NWC::err( 'This order has no items to bill.' );
		}
		$gross = array_sum( array_map( function ( $i ) {
			return (float) $i->amount;
		}, $live ) );
		$dv   = max( 0, (float) ( $b['discount'] ?? 0 ) );
		$disc = 'percent' === ( $b['discount_type'] ?? 'amount' ) ? round( $gross * min( 100, $dv ) / 100, 2 ) : min( $gross, $dv );
		if ( $disc > 0 && ! NWC_Tenancy::can( 'invoices.write' ) ) {
			return NWC::err( 'Your role cannot give discounts.', 403, 'nwc_forbidden' );
		}
		$biz = NWC_Bookings::business( $o->workspace_id );
		$pct = array_key_exists( 'service_charge_pct', $b ) ? min( 20, max( 0, (float) $b['service_charge_pct'] ) ) : (float) $biz->service_charge_pct;
		self::recalc( $o->id, (int) $o->workspace_id, $disc, $pct );
		$wpdb->update( self::t( 'pos_orders' ), array( 'status' => 'billed', 'updated_at' => NWC::now() ), array( 'id' => $o->id ) );
		if ( $disc > 0 ) {
			NWC::audit( 'pos_discount', array( 'entity' => 'pos_order', 'entity_id' => $o->id, 'details' => array( 'amount' => $disc ) ) );
		}
		return self::out( self::order( $o->id ) );
	}

	private static function walk_in_customer( $ws ) {
		global $wpdb;
		$id = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . self::t( 'customers' ) . " WHERE workspace_id = %d AND source = 'pos_walkin' AND deleted_at IS NULL ORDER BY id ASC LIMIT 1", $ws ) ); // phpcs:ignore
		if ( $id ) {
			return $id;
		}
		$wpdb->insert( self::t( 'customers' ), array( 'workspace_id' => $ws, 'name' => 'Walk-in customer', 'source' => 'pos_walkin', 'created_at' => NWC::now(), 'updated_at' => NWC::now() ) );
		return (int) $wpdb->insert_id;
	}

	/**
	 * Settles a billed order. Body: {payments: [{method, amount, reference}], idempotency_key}.
	 * Creates the GST invoice once, then applies each payment. Partly paid orders stay billed.
	 */
	public static function pay( WP_REST_Request $r ) {
		global $wpdb;
		$g = self::guard( true );
		if ( is_wp_error( $g ) ) {
			return $g;
		}
		$o = self::order( (int) $r['id'] );
		if ( ! $o || 'billed' !== $o->status ) {
			return NWC::err( 'Bill the order before taking payment.', 409, 'nwc_conflict' );
		}
		$b   = NWC_REST::body( $r );
		$ws  = (int) $o->workspace_id;
		$key = mb_substr( sanitize_text_field( (string) ( $b['idempotency_key'] ?? '' ) ), 0, 50 );
		if ( ! NWC::lock( 'pospay:' . $o->id ) ) {
			return NWC::err( 'Busy right now. Please try again.', 503, 'nwc_busy' );
		}
		try {
			$o    = self::order( $o->id, $ws );
			$sum  = array_sum( array_map( function ( $p ) {
				return max( 0, (float) ( $p['amount'] ?? 0 ) );
			}, array_slice( (array) ( $b['payments'] ?? array() ), 0, 6 ) ) );
			$left = round( (float) $o->total - (float) $o->paid, 2 );
			if ( $sum <= 0 ) {
				return NWC::err( 'Enter the amount received.' );
			}
			if ( $sum - $left > 0.001 && '' === $key ) { // A retried request with its key is answered from the first result below.
				return NWC::err( 'That is more than the balance of ' . NWC_Invoices::money( $left ) . '.', 409, 'nwc_overpayment' );
			}
			if ( ! $o->invoice_id ) {
				list( $lines ) = self::recalc( $o->id, $ws );
				$o   = self::order( $o->id, $ws );
				$inv = NWC_Invoices::create_doc( array( 'customer_id' => $o->customer_id ? (int) $o->customer_id : self::walk_in_customer( $ws ), 'items' => $lines, 'notes' => 'POS ' . $o->order_no, 'terms' => '', 'bank_details' => '' ) );
				if ( is_wp_error( $inv ) ) {
					return $inv;
				}
				$wpdb->update( self::t( 'biz_invoices' ), array( 'status' => 'sent', 'sent_at' => NWC::now() ), array( 'id' => $inv, 'workspace_id' => $ws ) );
				$wpdb->update( self::t( 'pos_orders' ), array( 'invoice_id' => $inv ), array( 'id' => $o->id ) );
				$o = self::order( $o->id, $ws );
			}
			$v = NWC_Invoices::row( (int) $o->invoice_id, $ws );
			foreach ( array_slice( (array) ( $b['payments'] ?? array() ), 0, 6 ) as $i => $p ) {
				$method = in_array( $p['method'] ?? '', array( 'cash', 'upi', 'card', 'bank', 'wallet', 'other' ), true ) ? $p['method'] : 'other';
				$res    = NWC_Invoices::apply_payment( $v, array( 'amount' => (float) ( $p['amount'] ?? 0 ), 'method' => $method, 'reference' => (string) ( $p['reference'] ?? '' ), 'idempotency_key' => '' !== $key ? $key . '-' . $i : '' ) );
				if ( is_wp_error( $res ) ) {
					return $res;
				}
				$v = NWC_Invoices::row( (int) $o->invoice_id, $ws );
			}
			$paid = (float) $v->amount_paid;
			$done = 'paid' === $v->status;
			$wpdb->update( self::t( 'pos_orders' ), array( 'paid' => $paid, 'status' => $done ? 'paid' : 'billed', 'closed_at' => $done ? NWC::now() : null, 'updated_at' => NWC::now() ), array( 'id' => $o->id ) );
		} finally {
			NWC::unlock( 'pospay:' . $o->id );
		}
		if ( $done && $o->customer_id ) { // Loyalty: one point per ₹100 and one visit.
			$acc = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::t( 'biz_records' ) . " WHERE workspace_id = %d AND entity = 'loyalty' AND customer_id = %d AND deleted_at IS NULL", $ws, $o->customer_id ) ); // phpcs:ignore
			if ( $acc ) {
				$d = json_decode( (string) $acc->data, true );
				$d['visits'] = (int) ( $d['visits'] ?? 0 ) + 1;
				$wpdb->update( self::t( 'biz_records' ), array( 'qty' => (float) $acc->qty + floor( (float) $o->total / 100 ), 'data' => wp_json_encode( $d ), 'updated_at' => NWC::now() ), array( 'id' => $acc->id ) );
			}
		}
		NWC::audit( 'pos_order_paid', array( 'entity' => 'pos_order', 'entity_id' => $o->id, 'details' => array( 'paid' => $paid, 'closed' => $done ) ) );
		return self::out( self::order( $o->id, $ws ) );
	}

	public static function cancel( WP_REST_Request $r ) {
		global $wpdb;
		$g = self::guard( true );
		if ( is_wp_error( $g ) ) {
			return $g;
		}
		$o = self::order( (int) $r['id'] );
		if ( ! $o || ! in_array( $o->status, array( 'open', 'billed' ), true ) || $o->invoice_id ) {
			return NWC::err( 'Only an open order with no payment can be cancelled.', 409, 'nwc_conflict' );
		}
		$sent = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . self::t( 'pos_items' ) . " WHERE order_id = %d AND status NOT IN ('new','void')", $o->id ) ); // phpcs:ignore
		$reason = trim( sanitize_text_field( (string) ( NWC_REST::body( $r )['reason'] ?? '' ) ) );
		if ( $sent && ( ! NWC_Tenancy::is_manager() || '' === $reason ) ) {
			return NWC::err( 'Food already went to the kitchen. A manager must cancel with a reason.', 403, 'nwc_forbidden' );
		}
		$wpdb->update( self::t( 'pos_orders' ), array( 'status' => 'cancelled', 'table_ids' => '[]', 'notes' => trim( $o->notes . "\nCancelled: " . $reason ), 'closed_at' => NWC::now(), 'updated_at' => NWC::now() ), array( 'id' => $o->id ) );
		NWC::audit( 'pos_order_cancelled', array( 'entity' => 'pos_order', 'entity_id' => $o->id, 'details' => array( 'reason' => $reason, 'sent_items' => $sent ) ) );
		return self::out( self::order( $o->id ) );
	}

	public static function report( WP_REST_Request $r ) {
		global $wpdb;
		$g = self::guard();
		if ( is_wp_error( $g ) ) {
			return $g;
		}
		if ( ! NWC_Tenancy::can( 'reports.read' ) ) {
			return NWC::err( 'Your role does not allow this.', 403, 'nwc_forbidden' );
		}
		$ws   = (int) NWC_Tenancy::$current->id;
		$from = NWC::valid_date( (string) $r->get_param( 'from' ) ) ? $r->get_param( 'from' ) : gmdate( 'Y-m-d', time() - 29 * DAY_IN_SECONDS );
		$O    = self::t( 'pos_orders' );
		$I    = self::t( 'pos_items' );
		$R    = self::t( 'biz_records' );
		$cost = 0.0;
		foreach ( $wpdb->get_results( $wpdb->prepare( "SELECT i.menu_item_id, SUM(i.qty) q FROM $I i JOIN $O o ON o.id = i.order_id WHERE o.workspace_id = %d AND o.status = 'paid' AND i.status <> 'void' AND o.closed_at >= %s GROUP BY i.menu_item_id", $ws, $from ) ) as $x ) { // phpcs:ignore
			$m = $x->menu_item_id ? NWC_Industry::row( $x->menu_item_id, $ws ) : null;
			foreach ( $m ? (array) ( json_decode( (string) $m->data, true )['recipe'] ?? array() ) : array() as $rc ) {
				$ing = NWC_Industry::row( absint( $rc['name'] ), $ws );
				$cost += $ing ? (float) $ing->amount * (float) $rc['price'] * (float) $x->q : 0;
			}
		}
		$sales = (float) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(SUM(total - tax),0) FROM $O WHERE workspace_id = %d AND status = 'paid' AND closed_at >= %s", $ws, $from ) ); // phpcs:ignore
		return array(
			'from'        => $from,
			'sales'       => $sales,
			'tax'         => (float) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(SUM(tax),0) FROM $O WHERE workspace_id = %d AND status = 'paid' AND closed_at >= %s", $ws, $from ) ), // phpcs:ignore
			'orders'      => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $O WHERE workspace_id = %d AND status = 'paid' AND closed_at >= %s", $ws, $from ) ), // phpcs:ignore
			'by_channel'  => $wpdb->get_results( $wpdb->prepare( "SELECT channel, COUNT(*) n, SUM(total) total FROM $O WHERE workspace_id = %d AND status = 'paid' AND closed_at >= %s GROUP BY channel", $ws, $from ) ), // phpcs:ignore
			'best_sellers' => $wpdb->get_results( $wpdb->prepare( "SELECT i.name, SUM(i.qty) qty, SUM(i.amount) amount FROM $I i JOIN $O o ON o.id = i.order_id WHERE o.workspace_id = %d AND o.status = 'paid' AND i.status <> 'void' AND o.closed_at >= %s GROUP BY i.name ORDER BY qty DESC LIMIT 10", $ws, $from ) ), // phpcs:ignore
			'recipe_cost' => round( $cost, 2 ),
			'gross_profit' => round( $sales - $cost, 2 ),
			'wastage'     => (float) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(SUM(w.qty * i.amount),0) FROM $R w JOIN $R i ON i.id = w.parent_id WHERE w.workspace_id = %d AND w.entity = 'wastage' AND w.deleted_at IS NULL AND w.on_date >= %s", $ws, $from ) ), // phpcs:ignore
		);
	}

	/* ---------------------------- QR menu ---------------------------- */

	private static function public_ws( $slug ) {
		global $wpdb;
		$w = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::t( 'workspaces' ) . " WHERE slug = %s AND status = 'active' AND account_type <> 'personal'", $slug ) ); // phpcs:ignore
		if ( ! $w || ! NWC_Industry::module_enabled( $w, 'pos' ) ) {
			return NWC::err( 'Menu not found.', 404, 'nwc_not_found' );
		}
		return $w;
	}

	public static function public_menu( WP_REST_Request $r ) {
		global $wpdb;
		if ( ! NWC::rate_ok( 'menu|' . NWC::ip(), 120 ) ) {
			return NWC::err( 'Too many requests.', 429, 'nwc_rate_limited' );
		}
		$w = self::public_ws( (string) $r['slug'] );
		if ( is_wp_error( $w ) ) {
			return $w;
		}
		$biz   = NWC_Bookings::business( $w->id );
		$table = null;
		$code  = sanitize_key( (string) $r->get_param( 'table' ) );
		if ( '' !== $code ) {
			$t = $wpdb->get_row( $wpdb->prepare( 'SELECT id, title FROM ' . self::t( 'biz_records' ) . " WHERE workspace_id = %d AND entity = 'dining_table' AND status = 'active' AND code = %s AND deleted_at IS NULL", $w->id, $code ) ); // phpcs:ignore
			$table = $t ? array( 'title' => $t->title ) : null;
		}
		return array_merge( self::menu_payload( (int) $w->id, true ), array( 'business' => array( 'name' => $biz->display_name, 'logo_url' => $biz->logo_url, 'brand_color' => $biz->brand_color, 'phone' => $biz->phone, 'gst_registered' => (bool) $biz->gst_registered ), 'table' => $table, 'ordering' => (bool) $table ) );
	}

	/** A guest at a table orders from the QR menu. Staff see it on the floor and send the ticket. */
	public static function public_order( WP_REST_Request $r ) {
		global $wpdb;
		$b = NWC_REST::body( $r );
		if ( ! empty( $b['website'] ) ) {
			return array( 'ok' => true );
		}
		if ( ! NWC::rate_ok( 'qrorder|' . NWC::ip(), 12, 600 ) ) {
			return NWC::err( 'Too many orders from this device. Please ask the staff.', 429, 'nwc_rate_limited' );
		}
		$w = self::public_ws( (string) $r['slug'] );
		if ( is_wp_error( $w ) ) {
			return $w;
		}
		$code = sanitize_key( (string) ( $b['table'] ?? '' ) );
		$t    = '' !== $code ? $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::t( 'biz_records' ) . " WHERE workspace_id = %d AND entity = 'dining_table' AND status = 'active' AND code = %s AND deleted_at IS NULL", $w->id, $code ) ) : null; // phpcs:ignore
		if ( ! $t ) {
			return NWC::err( 'Scan the QR code on your table to order.', 404, 'nwc_not_found' );
		}
		$busy = self::busy_tables( (int) $w->id );
		$o    = isset( $busy[ (int) $t->id ] ) ? self::order( $busy[ (int) $t->id ], $w->id ) : null;
		if ( ! $o ) {
			$o = self::create_order( (int) $w->id, array( 'channel' => 'qr', 'table_ids' => array( (int) $t->id ), 'customer_name' => (string) ( $b['name'] ?? '' ), 'phone' => (string) ( $b['phone'] ?? '' ), 'idempotency_key' => (string) ( $b['idempotency_key'] ?? '' ) ), 'qr' );
			if ( is_wp_error( $o ) ) {
				return $o;
			}
		}
		$res = self::insert_items( $o, $b['items'] ?? array() );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		NWC_Notify::workspace_owners( (int) $w->id, 'operations', 'QR order at ' . $t->title, 'Send it to the kitchen from POS.', '#/m/pos?order=' . (int) $o->id, 'qr:' . $o->id . ':' . time() );
		$fresh = self::order( $o->id, $w->id );
		return array( 'ok' => true, 'order_no' => $fresh->order_no, 'total' => (float) $fresh->total );
	}
}
