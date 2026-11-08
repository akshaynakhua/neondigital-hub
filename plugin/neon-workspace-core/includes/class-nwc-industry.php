<?php
/**
 * Business types. One platform, configured per Workspace:
 *
 *   - every Workspace has one business category (NWC_Catalog keys);
 *   - the category picks a profile: its dashboard, its own modules, its customer fields, the labels
 *     used for common modules and the common modules that are relevant to it;
 *   - category modules keep their records in one tenant-scoped table (biz_records), described by
 *     the entity definitions below, so a new field or entity is a definition, not a new table;
 *   - workflows that need real rules (POS, room stays, check-ins, attendance) have their own
 *     classes and tables and use these records for their master data (menu, tables, rooms...).
 *
 * Personal areas never have a category. Irrelevant modules are hidden, not shown empty. Optional
 * modules can be switched on or off per Workspace by its owner or admins.
 */

defined( 'ABSPATH' ) || exit;

class NWC_Industry {

	/** Physical columns a field may map to. Everything else is kept in `data`. */
	const COLS = array( 'title', 'status', 'code', 'customer_id', 'parent_id', 'ref_id', 'staff_id', 'space_id', 'amount', 'qty', 'on_date', 'end_date' );

	/** Common modules: key → default label, route, feature, read permission. */
	const COMMON = array(
		'projects'      => array( 'Projects & Tasks', '/projects', 'projects', 'projects.read' ),
		'bookings'      => array( 'Bookings', '/bookings', 'bookings', 'bookings.read' ),
		'crm'           => array( 'Customers & Leads', '/crm', 'crm', 'crm.read' ),
		'invoices'      => array( 'Invoices & Quotes', '/invoices', 'invoice_management', 'invoices.read' ),
		'finance'       => array( 'Accounts & Expenses', '/finance', 'accounts_finance', 'finance.read' ),
		'team'          => array( 'Team', '/team', 'team_management', 'team.read' ),
		'files'         => array( 'Files & Google Drive', '/files', 'google_drive', 'projects.read' ),
		'forms'         => array( 'Custom Forms', '/forms', 'custom_forms', 'crm.read' ),
		'registrations' => array( 'Registration Forms', '/registrations', 'registration_forms', 'crm.read' ),
		'meetings'      => array( 'Meetings', '/meetings', 'meetings', '' ),
		'planner'       => array( 'Daily Planner', '/planner', 'daily_planner', '' ),
		'reports'       => array( 'Reports', '/reports', 'reports', 'reports.read' ),
	);

	/* ============================ Profiles ============================ */

	/**
	 * key → label, description, modules (category modules, in order), common (on by default),
	 * optional (common modules off by default but available), labels (common module renames),
	 * customer_fields, booking_kinds, dashboard (KPI keys).
	 */
	public static function profiles() {
		$learn_cf = array( array( 'dob', 'Date of birth', 'date' ), array( 'parent_name', 'Parent or guardian', 'text' ), array( 'parent_phone', 'Parent phone', 'phone' ), array( 'emergency', 'Emergency contact', 'text' ) );
		$food_cf  = array( array( 'diet', 'Dietary preference', 'select', array( 'opts' => array( 'Vegetarian', 'Vegan', 'Jain', 'Non-vegetarian', 'Eggetarian' ), 'list' => 'diet' ) ), array( 'allergies', 'Allergies', 'text' ), array( 'birthday', 'Birthday', 'date' ), array( 'anniversary', 'Anniversary', 'date' ) );
		$p = array(
			'dance_studio' => array(
				'label' => 'Dance studio / academy', 'description' => 'Classes, batches, students and parents, attendance, fees, workshops and recitals.',
				'modules' => array( 'academy', 'students', 'attendance', 'fees', 'studio_events' ),
				'common' => array( 'bookings', 'crm', 'invoices', 'finance', 'team', 'files', 'reports', 'projects' ),
				'optional' => array( 'forms', 'registrations', 'meetings', 'planner' ),
				'labels' => array( 'bookings' => 'Classes & Bookings', 'crm' => 'Enquiries & Contacts', 'team' => 'Instructors & Staff' ),
				'customer_fields' => $learn_cf,
				'booking_kinds' => array( 'regular_class', 'dance_class', 'group_class', 'individual_lesson', 'workshop', 'event', 'studio_rental', 'practice_session', 'rehearsal', 'trial_class' ),
				'dashboard' => array( 'students_active', 'batches_active', 'sessions_today', 'attendance_30d', 'fees_due', 'trials_upcoming', 'bookings_today' ),
			),
			'music_academy' => array(
				'label' => 'Music academy / studio', 'description' => 'Instruments, lessons, teachers, attendance, practice, recitals and instrument lending.',
				'modules' => array( 'academy', 'students', 'attendance', 'fees', 'lending', 'studio_events' ),
				'common' => array( 'bookings', 'crm', 'invoices', 'finance', 'team', 'files', 'reports', 'projects' ),
				'optional' => array( 'forms', 'registrations', 'meetings', 'planner' ),
				'labels' => array( 'bookings' => 'Lessons & Bookings', 'crm' => 'Enquiries & Contacts', 'team' => 'Teachers & Staff' ),
				'customer_fields' => $learn_cf,
				'booking_kinds' => array( 'music_class', 'individual_lesson', 'group_class', 'trial_class', 'workshop', 'event', 'studio_rental', 'practice_session', 'rehearsal' ),
				'dashboard' => array( 'students_active', 'batches_active', 'sessions_today', 'attendance_30d', 'fees_due', 'instruments_lent', 'bookings_today' ),
			),
			'restaurant' => array(
				'label' => 'Restaurant', 'description' => 'Tables and floor, POS billing, kitchen tickets, menu, inventory and loyalty.',
				'modules' => array( 'pos', 'kds', 'menu', 'tables', 'inventory', 'loyalty' ),
				'common' => array( 'crm', 'invoices', 'finance', 'team', 'reports', 'files' ),
				'optional' => array( 'bookings', 'projects', 'forms', 'meetings', 'planner', 'registrations' ),
				'labels' => array( 'crm' => 'Guests & Customers', 'team' => 'Staff & Waiters', 'bookings' => 'Events & Catering' ),
				'customer_fields' => $food_cf,
				'booking_kinds' => array( 'event' ),
				'dashboard' => array( 'sales_today', 'orders_open', 'tables_occupied', 'kots_pending', 'reservations_today', 'low_stock', 'best_sellers' ),
				'station' => 'kitchen',
				'menu_categories' => array( 'Starters', 'Soups', 'Main course', 'Breads', 'Rice & biryani', 'Desserts', 'Beverages' ),
			),
			'cafe' => array(
				'label' => 'Cafe', 'description' => 'Counter and table orders, barista tickets, menu with sizes and add-ons, inventory and loyalty.',
				'modules' => array( 'pos', 'kds', 'menu', 'tables', 'inventory', 'loyalty' ),
				'common' => array( 'crm', 'invoices', 'finance', 'team', 'reports' ),
				'optional' => array( 'bookings', 'files', 'projects', 'forms', 'meetings', 'planner', 'registrations' ),
				'labels' => array( 'crm' => 'Guests & Regulars', 'team' => 'Cashiers, Baristas & Staff', 'bookings' => 'Workshops & Events' ),
				'customer_fields' => $food_cf,
				'booking_kinds' => array( 'workshop', 'event' ),
				'dashboard' => array( 'sales_today', 'orders_open', 'takeaway_today', 'kots_pending', 'best_sellers', 'low_stock', 'repeat_guests' ),
				'station' => 'barista',
				'menu_categories' => array( 'Coffee', 'Tea', 'Beverages', 'Snacks', 'Desserts', 'Food' ),
			),
			'hotel' => array(
				'label' => 'Hotel / hospitality', 'description' => 'Rooms, availability, reservations, check-in and check-out, housekeeping and guest billing.',
				'modules' => array( 'rooms', 'stays', 'housekeeping', 'guest_services', 'rates' ),
				'common' => array( 'crm', 'invoices', 'finance', 'team', 'reports', 'files' ),
				'optional' => array( 'bookings', 'projects', 'forms', 'meetings', 'planner', 'registrations' ),
				'labels' => array( 'crm' => 'Guests', 'team' => 'Staff', 'bookings' => 'Spa, Events & Extras' ),
				'customer_fields' => array( array( 'id_type', 'ID type', 'select', array( 'opts' => array( 'Aadhaar', 'Passport', 'Driving licence', 'Voter ID', 'PAN' ) ) ), array( 'id_number', 'ID number', 'text' ), array( 'nationality', 'Nationality', 'text' ), array( 'preferences', 'Preferences', 'textarea' ) ),
				'booking_kinds' => array( 'event' ),
				'dashboard' => array( 'occupancy_today', 'arrivals_today', 'departures_today', 'in_house', 'rooms_dirty', 'requests_open', 'room_revenue_month' ),
			),
			'gym' => array(
				'label' => 'Gym / fitness centre', 'description' => 'Members and plans, renewals, check-ins, trainers, classes, workouts and equipment.',
				'modules' => array( 'members', 'checkin', 'training', 'equipment', 'branches' ),
				'common' => array( 'bookings', 'crm', 'invoices', 'finance', 'team', 'reports' ),
				'optional' => array( 'files', 'projects', 'forms', 'meetings', 'planner', 'registrations' ),
				'labels' => array( 'bookings' => 'Classes & Sessions', 'crm' => 'Leads & Contacts', 'team' => 'Trainers & Staff' ),
				'customer_fields' => array( array( 'dob', 'Date of birth', 'date' ), array( 'height_cm', 'Height (cm)', 'number' ), array( 'weight_kg', 'Weight (kg)', 'number' ), array( 'goal', 'Fitness goal', 'select', array( 'opts' => array( 'Weight loss', 'Muscle gain', 'General fitness', 'Strength', 'Rehabilitation' ), 'list' => 'goal' ) ), array( 'medical', 'Medical notes', 'textarea' ), array( 'emergency', 'Emergency contact', 'text' ) ),
				'booking_kinds' => array( 'group_class', 'individual_lesson', 'trial_class', 'workshop', 'event' ),
				'dashboard' => array( 'members_active', 'members_expiring', 'live_occupancy', 'checkins_today', 'member_dues', 'bookings_today', 'equipment_due' ),
			),
			'salon' => array(
				'label' => 'Salon / beauty / spa', 'description' => 'Appointments by stylist and chair, walk-ins, packages, products, loyalty and commissions.',
				'modules' => array( 'salon_ops', 'packages', 'products', 'loyalty' ),
				'common' => array( 'bookings', 'crm', 'invoices', 'finance', 'team', 'reports' ),
				'optional' => array( 'files', 'projects', 'forms', 'meetings', 'planner', 'registrations' ),
				'labels' => array( 'bookings' => 'Appointments', 'crm' => 'Clients', 'team' => 'Stylists & Staff' ),
				'customer_fields' => array( array( 'hair_type', 'Hair type', 'select', array( 'opts' => array( 'Straight', 'Wavy', 'Curly', 'Coily' ) ) ), array( 'skin_type', 'Skin type', 'select', array( 'opts' => array( 'Normal', 'Dry', 'Oily', 'Combination', 'Sensitive' ) ) ), array( 'allergies', 'Allergies', 'text' ), array( 'preferences', 'Preferences and service notes', 'textarea' ), array( 'birthday', 'Birthday', 'date' ) ),
				'booking_kinds' => array( 'appointment' ),
				'dashboard' => array( 'bookings_today', 'walkins_today', 'revenue_today', 'top_staff', 'packages_active', 'low_stock' ),
			),
			'real_estate' => array(
				'label' => 'Real estate agency / broker / developer', 'description' => 'Listings, owners, enquiries and pipeline, site visits, offers, tokens, agreements, commissions and developer inventory.',
				'modules' => array( 'properties', 'pipeline', 'deals', 'developer' ),
				'common' => array( 'crm', 'invoices', 'finance', 'team', 'files', 'reports' ),
				'optional' => array( 'bookings', 'projects', 'forms', 'meetings', 'planner', 'registrations' ),
				'labels' => array( 'crm' => 'Buyers, Tenants & Leads', 'team' => 'Agents & Staff', 'bookings' => 'Appointments' ),
				'customer_fields' => array( array( 'client_type', 'Client type', 'select', array( 'opts' => array( 'Buyer', 'Seller', 'Tenant', 'Landlord', 'Investor' ) ) ), array( 'budget_min', 'Budget from', 'money' ), array( 'budget_max', 'Budget up to', 'money' ), array( 'preferred_location', 'Preferred location', 'text' ), array( 'preferred_type', 'Preferred property type', 'select', array( 'opts' => array( 'Flat', 'Apartment', 'Villa', 'Plot', 'Commercial', 'Office', 'Shop' ), 'list' => 'property_type' ) ) ),
				'booking_kinds' => array( 'appointment' ),
				'dashboard' => array( 'properties_available', 'leads_new', 'visits_upcoming', 'deals_open', 'tokens_month', 'instalments_due', 'units_available' ),
			),
			'other' => array(
				'label' => 'Other business', 'description' => 'Your own services, booking types, categories, fields, statuses and workflow stages.',
				'modules' => array( 'custom' ),
				'common' => array( 'projects', 'bookings', 'crm', 'invoices', 'finance', 'team', 'files', 'reports' ),
				'optional' => array( 'forms', 'registrations', 'meetings', 'planner' ),
				'labels' => array(),
				'customer_fields' => array(),
				'booking_kinds' => array( 'appointment', 'regular_class', 'group_class', 'individual_lesson', 'workshop', 'event', 'studio_rental', 'practice_session', 'rehearsal' ),
				'dashboard' => array( 'bookings_today', 'custom_open', 'leads_new' ),
			),
		);
		return apply_filters( 'nwc_business_profiles', $p );
	}

	/** Earlier category keys use the nearest profile. */
	public static function profile_key( $category ) {
		$k   = NWC_Catalog::category_key( $category );
		$map = array( 'fitness' => 'gym', 'makeup_artist' => 'salon', 'consulting' => 'other', 'events' => 'other', 'freelance' => 'other' );
		$k   = $map[ $k ] ?? $k;
		return isset( self::profiles()[ $k ] ) ? $k : 'other';
	}

	const BOOKING_KINDS = array(
		'appointment' => 'Appointment', 'regular_class' => 'Regular class', 'dance_class' => 'Dance class', 'music_class' => 'Music class',
		'group_class' => 'Group class', 'individual_lesson' => 'Individual lesson', 'trial_class' => 'Trial class', 'workshop' => 'Workshop',
		'event' => 'Event', 'studio_rental' => 'Studio rental', 'practice_session' => 'Practice session', 'rehearsal' => 'Rehearsal',
	);

	/* ============================ Modules and entities ============================ */

	/** Category module → label, icon, view (records|pos|kds|stays|checkin|attendance), entities, perms. */
	public static function modules() {
		return array(
			'academy'       => array( 'label' => 'Batches & Courses', 'icon' => 'cal', 'view' => 'records', 'entities' => array( 'batch', 'style', 'level', 'substitution' ), 'read' => 'bookings.read', 'write' => 'bookings.manage' ),
			'students'      => array( 'label' => 'Students & Parents', 'icon' => 'users', 'view' => 'records', 'entities' => array( 'student', 'enrolment', 'admission', 'progress', 'practice' ), 'read' => 'crm.read', 'write' => 'crm.write' ),
			'attendance'    => array( 'label' => 'Attendance', 'icon' => 'task', 'view' => 'attendance', 'entities' => array(), 'read' => 'bookings.read', 'write' => 'bookings.write' ),
			'fees'          => array( 'label' => 'Fees & Memberships', 'icon' => 'coin', 'view' => 'records', 'entities' => array( 'fee_plan', 'fee_due', 'class_pass' ), 'read' => 'invoices.read', 'write' => 'invoices.write' ),
			'studio_events' => array( 'label' => 'Workshops, Events & Recitals', 'icon' => 'ticket', 'view' => 'records', 'entities' => array( 'recital', 'recital_entry' ), 'read' => 'bookings.read', 'write' => 'bookings.manage' ),
			'lending'       => array( 'label' => 'Instruments & Lending', 'icon' => 'tag', 'view' => 'records', 'entities' => array( 'instrument', 'loan' ), 'read' => 'bookings.read', 'write' => 'bookings.write' ),

			'pos'           => array( 'label' => 'POS & Orders', 'icon' => 'coin', 'view' => 'pos', 'entities' => array(), 'read' => 'bookings.read', 'write' => 'bookings.write' ),
			'kds'           => array( 'label' => 'Kitchen Display', 'icon' => 'bolt', 'view' => 'kds', 'entities' => array(), 'read' => 'bookings.read', 'write' => 'bookings.write' ),
			'menu'          => array( 'label' => 'Menu', 'icon' => 'doc', 'view' => 'records', 'entities' => array( 'menu_item', 'menu_category' ), 'read' => 'bookings.read', 'write' => 'bookings.manage' ),
			'tables'        => array( 'label' => 'Tables & Reservations', 'icon' => 'book', 'view' => 'records', 'entities' => array( 'table_reservation', 'dining_table', 'dining_area' ), 'read' => 'bookings.read', 'write' => 'bookings.write' ),
			'inventory'     => array( 'label' => 'Inventory & Suppliers', 'icon' => 'folder', 'view' => 'records', 'entities' => array( 'ingredient', 'supplier', 'purchase', 'wastage' ), 'read' => 'finance.read', 'write' => 'bookings.manage' ),
			'loyalty'       => array( 'label' => 'Loyalty & Offers', 'icon' => 'sun', 'view' => 'records', 'entities' => array( 'loyalty', 'offer' ), 'read' => 'crm.read', 'write' => 'crm.write' ),

			'rooms'         => array( 'label' => 'Rooms & Room Types', 'icon' => 'folder', 'view' => 'records', 'entities' => array( 'room', 'room_type' ), 'read' => 'bookings.read', 'write' => 'bookings.manage' ),
			'stays'         => array( 'label' => 'Reservations & Front Desk', 'icon' => 'book', 'view' => 'stays', 'entities' => array(), 'read' => 'bookings.read', 'write' => 'bookings.write' ),
			'housekeeping'  => array( 'label' => 'Housekeeping & Maintenance', 'icon' => 'task', 'view' => 'records', 'entities' => array( 'housekeeping', 'maintenance' ), 'read' => 'bookings.read', 'write' => 'bookings.write' ),
			'guest_services' => array( 'label' => 'Guest Requests', 'icon' => 'bell', 'view' => 'records', 'entities' => array( 'guest_request' ), 'read' => 'bookings.read', 'write' => 'bookings.write' ),
			'rates'         => array( 'label' => 'Rates & Packages', 'icon' => 'tag', 'view' => 'records', 'entities' => array( 'rate_season', 'stay_package' ), 'read' => 'bookings.read', 'write' => 'bookings.manage' ),

			'members'       => array( 'label' => 'Members & Plans', 'icon' => 'users', 'view' => 'records', 'entities' => array( 'membership', 'membership_plan' ), 'read' => 'crm.read', 'write' => 'crm.write' ),
			'checkin'       => array( 'label' => 'Check-in & Occupancy', 'icon' => 'task', 'view' => 'checkin', 'entities' => array(), 'read' => 'bookings.read', 'write' => 'bookings.write' ),
			'training'      => array( 'label' => 'Trainers & Workouts', 'icon' => 'sun', 'view' => 'records', 'entities' => array( 'pt_package', 'workout_plan', 'assessment', 'trainer_rate', 'trainer_pay' ), 'read' => 'bookings.read', 'write' => 'bookings.write' ),
			'equipment'     => array( 'label' => 'Equipment & Maintenance', 'icon' => 'gear', 'view' => 'records', 'entities' => array( 'equipment' ), 'read' => 'bookings.read', 'write' => 'bookings.manage' ),
			'branches'      => array( 'label' => 'Branches', 'icon' => 'link', 'view' => 'records', 'entities' => array( 'branch' ), 'read' => 'bookings.read', 'write' => 'settings.manage' ),

			'salon_ops'     => array( 'label' => 'Walk-ins & Commissions', 'icon' => 'users', 'view' => 'records', 'entities' => array( 'walkin', 'commission_rule' ), 'read' => 'bookings.read', 'write' => 'bookings.write' ),
			'packages'      => array( 'label' => 'Packages & Prepaid', 'icon' => 'ticket', 'view' => 'records', 'entities' => array( 'salon_package', 'package_sale' ), 'read' => 'bookings.read', 'write' => 'bookings.manage' ),
			'products'      => array( 'label' => 'Products & Stock', 'icon' => 'tag', 'view' => 'records', 'entities' => array( 'product' ), 'read' => 'bookings.read', 'write' => 'bookings.manage' ),

			'properties'    => array( 'label' => 'Properties & Owners', 'icon' => 'home', 'view' => 'records', 'entities' => array( 'property', 'owner' ), 'read' => 'crm.read', 'write' => 'crm.write' ),
			'pipeline'      => array( 'label' => 'Enquiries & Site Visits', 'icon' => 'chart', 'view' => 'records', 'entities' => array( 'site_visit', 'offer_neg', 'campaign' ), 'read' => 'crm.read', 'write' => 'crm.write' ),
			'deals'         => array( 'label' => 'Tokens, Agreements & Commissions', 'icon' => 'coin', 'view' => 'records', 'entities' => array( 'deal', 'instalment', 'commission' ), 'read' => 'crm.read', 'write' => 'crm.write' ),
			'developer'     => array( 'label' => 'Developer Inventory', 'icon' => 'folder', 'view' => 'records', 'entities' => array( 'tower', 'unit' ), 'read' => 'crm.read', 'write' => 'crm.write' ),

			'custom'        => array( 'label' => 'My Records', 'icon' => 'note', 'view' => 'records', 'entities' => array( 'custom_record' ), 'read' => 'crm.read', 'write' => 'crm.write' ),
		);
	}

	/** Shorthand for one field definition. */
	public static function f( $key, $label, $type = 'text', $x = array() ) {
		return array_merge( array( 'key' => $key, 'label' => $label, 'type' => $type ), $x );
	}

	/**
	 * Entities. Field types: text, textarea, number, money, date, time, select, bool, phone, email,
	 * url, image, customer, staff, space, record (needs `entity`). `col` maps a field to a column.
	 * `person` entities also keep a CRM customer with the same name, phone and email, so basic
	 * identity survives a later change of business type.
	 */
	public static function entities() {
		$f  = array( __CLASS__, 'f' );
		$st = function ( $opts, $def = null ) use ( $f ) {
			return call_user_func( $f, 'status', 'Status', 'select', array( 'col' => 'status', 'opts' => $opts, 'def' => $def ? $def : $opts[0], 'show' => true ) );
		};
		$name  = call_user_func( $f, 'title', 'Name', 'text', array( 'col' => 'title', 'req' => true, 'show' => true ) );
		$phone = call_user_func( $f, 'phone', 'Phone', 'phone', array( 'show' => true ) );
		$email = call_user_func( $f, 'email', 'Email', 'email' );
		$notes = call_user_func( $f, 'notes', 'Notes', 'textarea' );
		$e = array(
			/* ---- Dance and music ---- */
			'style'       => array( 'label' => 'Style or instrument', 'plural' => 'Styles & instruments', 'fields' => array( $name, call_user_func( $f, 'description', 'Description', 'textarea' ) ) ),
			'level'       => array( 'label' => 'Level', 'plural' => 'Course levels', 'fields' => array( $name, call_user_func( $f, 'order', 'Order', 'number' ) ) ),
			'batch'       => array( 'label' => 'Batch', 'plural' => 'Batches & classes', 'fields' => array(
				$name, call_user_func( $f, 'parent_id', 'Style or instrument', 'record', array( 'col' => 'parent_id', 'entity' => 'style', 'show' => true ) ), call_user_func( $f, 'ref_id', 'Level', 'record', array( 'col' => 'ref_id', 'entity' => 'level' ) ),
				call_user_func( $f, 'staff_id', 'Instructor', 'staff', array( 'col' => 'staff_id', 'show' => true ) ), call_user_func( $f, 'space_id', 'Studio or room', 'space', array( 'col' => 'space_id', 'show' => true ) ),
				call_user_func( $f, 'weekdays', 'Days', 'days', array( 'show' => true ) ), call_user_func( $f, 'start_time', 'Starts', 'time', array( 'show' => true ) ), call_user_func( $f, 'end_time', 'Ends', 'time' ),
				call_user_func( $f, 'qty', 'Capacity', 'number', array( 'col' => 'qty' ) ), call_user_func( $f, 'amount', 'Monthly fee', 'money', array( 'col' => 'amount' ) ),
				call_user_func( $f, 'on_date', 'Starts on', 'date', array( 'col' => 'on_date' ) ), call_user_func( $f, 'end_date', 'Ends on', 'date', array( 'col' => 'end_date' ) ),
				$st( array( 'active', 'paused', 'completed' ) ),
			), 'hook' => 'check_batch' ),
			'substitution' => array( 'label' => 'Substitution', 'plural' => 'Instructor substitutions', 'fields' => array(
				call_user_func( $f, 'parent_id', 'Batch', 'record', array( 'col' => 'parent_id', 'entity' => 'batch', 'req' => true, 'show' => true ) ), call_user_func( $f, 'on_date', 'Date', 'date', array( 'col' => 'on_date', 'req' => true, 'show' => true ) ),
				call_user_func( $f, 'staff_id', 'Substitute instructor', 'staff', array( 'col' => 'staff_id', 'req' => true, 'show' => true ) ), $notes,
			), 'hook' => 'check_substitution' ),
			'student'     => array( 'label' => 'Student', 'plural' => 'Students', 'person' => true, 'fields' => array(
				$name, $phone, $email, call_user_func( $f, 'dob', 'Date of birth', 'date' ), call_user_func( $f, 'parent_name', 'Parent or guardian', 'text', array( 'show' => true ) ), call_user_func( $f, 'parent_phone', 'Parent phone', 'phone' ),
				call_user_func( $f, 'code', 'Student ID', 'text', array( 'col' => 'code', 'auto' => 'STU', 'show' => true ) ), call_user_func( $f, 'on_date', 'Joined on', 'date', array( 'col' => 'on_date' ) ),
				$st( array( 'active', 'trial', 'paused', 'left' ) ), $notes,
			) ),
			'enrolment'   => array( 'label' => 'Enrolment', 'plural' => 'Enrolments', 'fields' => array(
				call_user_func( $f, 'parent_id', 'Student', 'record', array( 'col' => 'parent_id', 'entity' => 'student', 'req' => true, 'show' => true ) ), call_user_func( $f, 'ref_id', 'Batch', 'record', array( 'col' => 'ref_id', 'entity' => 'batch', 'req' => true, 'show' => true ) ),
				call_user_func( $f, 'on_date', 'From', 'date', array( 'col' => 'on_date' ) ), $st( array( 'active', 'waitlisted', 'ended' ) ),
			), 'hook' => 'check_enrolment' ),
			'admission'   => array( 'label' => 'Admission or trial', 'plural' => 'Trials & admissions', 'fields' => array(
				$name, $phone, $email, call_user_func( $f, 'ref_id', 'Batch', 'record', array( 'col' => 'ref_id', 'entity' => 'batch', 'show' => true ) ), call_user_func( $f, 'on_date', 'Trial date', 'date', array( 'col' => 'on_date', 'show' => true ) ),
				$st( array( 'enquiry', 'trial_booked', 'trial_done', 'admitted', 'not_joined' ) ), $notes,
			), 'person' => true ),
			'progress'    => array( 'label' => 'Progress note', 'plural' => 'Student progress', 'fields' => array(
				call_user_func( $f, 'parent_id', 'Student', 'record', array( 'col' => 'parent_id', 'entity' => 'student', 'req' => true, 'show' => true ) ), call_user_func( $f, 'on_date', 'Date', 'date', array( 'col' => 'on_date', 'show' => true ) ),
				call_user_func( $f, 'title', 'Milestone or grade', 'text', array( 'col' => 'title', 'req' => true, 'show' => true ) ), call_user_func( $f, 'qty', 'Score (0-100)', 'number', array( 'col' => 'qty' ) ), $notes,
			) ),
			'practice'    => array( 'label' => 'Practice assignment', 'plural' => 'Practice assignments', 'fields' => array(
				call_user_func( $f, 'parent_id', 'Student', 'record', array( 'col' => 'parent_id', 'entity' => 'student', 'req' => true, 'show' => true ) ), call_user_func( $f, 'title', 'Assignment', 'text', array( 'col' => 'title', 'req' => true, 'show' => true ) ),
				call_user_func( $f, 'end_date', 'Due', 'date', array( 'col' => 'end_date', 'show' => true ) ), $st( array( 'assigned', 'submitted', 'reviewed' ) ), $notes,
			) ),
			'fee_plan'    => array( 'label' => 'Fee plan', 'plural' => 'Fee plans', 'fields' => array(
				$name, call_user_func( $f, 'period', 'Billing period', 'select', array( 'opts' => array( 'monthly', 'quarterly', 'half_yearly', 'yearly', 'one_time' ), 'show' => true ) ), call_user_func( $f, 'amount', 'Amount', 'money', array( 'col' => 'amount', 'show' => true ) ),
				call_user_func( $f, 'ref_id', 'Batch', 'record', array( 'col' => 'ref_id', 'entity' => 'batch' ) ), $st( array( 'active', 'retired' ) ),
			) ),
			'fee_due'     => array( 'label' => 'Fee', 'plural' => 'Fees due', 'fields' => array(
				call_user_func( $f, 'parent_id', 'Student', 'record', array( 'col' => 'parent_id', 'entity' => 'student', 'req' => true, 'show' => true ) ), call_user_func( $f, 'ref_id', 'Fee plan', 'record', array( 'col' => 'ref_id', 'entity' => 'fee_plan', 'show' => true ) ),
				call_user_func( $f, 'title', 'For (period)', 'text', array( 'col' => 'title', 'show' => true ) ), call_user_func( $f, 'amount', 'Amount', 'money', array( 'col' => 'amount', 'show' => true ) ), call_user_func( $f, 'end_date', 'Due on', 'date', array( 'col' => 'end_date', 'req' => true, 'show' => true ) ),
				call_user_func( $f, 'on_date', 'Paid on', 'date', array( 'col' => 'on_date' ) ), $st( array( 'due', 'paid', 'waived' ) ),
			), 'hook' => 'check_fee' ),
			'class_pass'  => array( 'label' => 'Class pass', 'plural' => 'Class passes', 'fields' => array(
				call_user_func( $f, 'parent_id', 'Student', 'record', array( 'col' => 'parent_id', 'entity' => 'student', 'req' => true, 'show' => true ) ), call_user_func( $f, 'qty', 'Classes left', 'number', array( 'col' => 'qty', 'show' => true ) ),
				call_user_func( $f, 'amount', 'Price', 'money', array( 'col' => 'amount' ) ), call_user_func( $f, 'end_date', 'Valid until', 'date', array( 'col' => 'end_date', 'show' => true ) ), $st( array( 'active', 'used', 'expired' ) ),
			) ),
			'recital'     => array( 'label' => 'Event', 'plural' => 'Workshops, events & recitals', 'fields' => array(
				$name, call_user_func( $f, 'kind', 'Kind', 'select', array( 'opts' => array( 'workshop', 'recital', 'showcase', 'competition', 'exam' ), 'show' => true ) ), call_user_func( $f, 'on_date', 'Date', 'date', array( 'col' => 'on_date', 'req' => true, 'show' => true ) ),
				call_user_func( $f, 'space_id', 'Venue or studio', 'space', array( 'col' => 'space_id' ) ), call_user_func( $f, 'qty', 'Seats', 'number', array( 'col' => 'qty' ) ), call_user_func( $f, 'amount', 'Fee', 'money', array( 'col' => 'amount' ) ),
				$st( array( 'planned', 'open', 'closed', 'done' ) ), $notes,
			) ),
			'recital_entry' => array( 'label' => 'Event registration', 'plural' => 'Event registrations', 'fields' => array(
				call_user_func( $f, 'parent_id', 'Event', 'record', array( 'col' => 'parent_id', 'entity' => 'recital', 'req' => true, 'show' => true ) ), call_user_func( $f, 'ref_id', 'Student', 'record', array( 'col' => 'ref_id', 'entity' => 'student', 'req' => true, 'show' => true ) ),
				call_user_func( $f, 'amount', 'Fee paid', 'money', array( 'col' => 'amount' ) ), $st( array( 'registered', 'waitlisted', 'cancelled' ) ),
			), 'hook' => 'check_registration' ),
			'instrument'  => array( 'label' => 'Instrument', 'plural' => 'Instruments & equipment', 'fields' => array(
				$name, call_user_func( $f, 'code', 'Asset tag', 'text', array( 'col' => 'code', 'auto' => 'INS', 'show' => true ) ), call_user_func( $f, 'space_id', 'Room', 'space', array( 'col' => 'space_id' ) ),
				$st( array( 'available', 'lent', 'repair', 'retired' ) ), $notes,
			) ),
			'loan'        => array( 'label' => 'Loan', 'plural' => 'Instrument loans', 'fields' => array(
				call_user_func( $f, 'parent_id', 'Instrument', 'record', array( 'col' => 'parent_id', 'entity' => 'instrument', 'req' => true, 'show' => true ) ), call_user_func( $f, 'ref_id', 'Student', 'record', array( 'col' => 'ref_id', 'entity' => 'student', 'req' => true, 'show' => true ) ),
				call_user_func( $f, 'on_date', 'Lent on', 'date', array( 'col' => 'on_date', 'show' => true ) ), call_user_func( $f, 'end_date', 'Due back', 'date', array( 'col' => 'end_date', 'show' => true ) ), call_user_func( $f, 'amount', 'Deposit', 'money', array( 'col' => 'amount' ) ),
				$st( array( 'out', 'returned', 'lost' ) ),
			), 'hook' => 'check_loan' ),

			/* ---- Restaurant and cafe ---- */
			'menu_category' => array( 'label' => 'Menu category', 'plural' => 'Menu categories', 'fields' => array( $name, call_user_func( $f, 'order', 'Order', 'number' ) ) ),
			'menu_item'   => array( 'label' => 'Menu item', 'plural' => 'Menu items', 'fields' => array(
				$name, call_user_func( $f, 'parent_id', 'Category', 'record', array( 'col' => 'parent_id', 'entity' => 'menu_category', 'show' => true ) ), call_user_func( $f, 'amount', 'Price', 'money', array( 'col' => 'amount', 'req' => true, 'show' => true ) ),
				call_user_func( $f, 'description', 'Description', 'textarea' ), call_user_func( $f, 'image', 'Image', 'image' ), call_user_func( $f, 'veg', 'Type', 'select', array( 'opts' => array( 'veg', 'non_veg', 'egg', 'vegan' ) ) ),
				call_user_func( $f, 'tax_rate', 'GST %', 'number', array( 'def' => 5 ) ), call_user_func( $f, 'station', 'Prepared at', 'select', array( 'opts' => array( 'kitchen', 'barista', 'bar' ) ) ),
				call_user_func( $f, 'variants', 'Sizes or variants (name and price)', 'pairs' ), call_user_func( $f, 'addons', 'Add-ons (name and price)', 'pairs' ), call_user_func( $f, 'recipe', 'Recipe (ingredient and quantity used per item)', 'pairs', array( 'pick' => 'ingredient' ) ),
				call_user_func( $f, 'code', 'Short code', 'text', array( 'col' => 'code' ) ), $st( array( 'available', 'sold_out', 'hidden' ) ),
			) ),
			'dining_area' => array( 'label' => 'Dining area', 'plural' => 'Dining areas', 'fields' => array( $name, call_user_func( $f, 'order', 'Order', 'number' ) ) ),
			'dining_table' => array( 'label' => 'Table', 'plural' => 'Tables', 'fields' => array(
				call_user_func( $f, 'title', 'Table', 'text', array( 'col' => 'title', 'req' => true, 'show' => true ) ), call_user_func( $f, 'parent_id', 'Area', 'record', array( 'col' => 'parent_id', 'entity' => 'dining_area', 'show' => true ) ),
				call_user_func( $f, 'qty', 'Seats', 'number', array( 'col' => 'qty', 'show' => true ) ), call_user_func( $f, 'code', 'QR code key', 'text', array( 'col' => 'code', 'auto' => 'qr', 'readonly' => true ) ), $st( array( 'active', 'inactive' ) ),
			) ),
			'table_reservation' => array( 'label' => 'Reservation', 'plural' => 'Table reservations & waitlist', 'person' => true, 'fields' => array(
				$name, $phone, call_user_func( $f, 'on_date', 'Date', 'date', array( 'col' => 'on_date', 'req' => true, 'show' => true ) ), call_user_func( $f, 'time', 'Time', 'time', array( 'req' => true, 'show' => true ) ),
				call_user_func( $f, 'qty', 'Guests', 'number', array( 'col' => 'qty', 'show' => true ) ), call_user_func( $f, 'ref_id', 'Table', 'record', array( 'col' => 'ref_id', 'entity' => 'dining_table', 'show' => true ) ), call_user_func( $f, 'duration', 'Minutes', 'number', array( 'def' => 90 ) ),
				$st( array( 'booked', 'waitlist', 'seated', 'completed', 'cancelled', 'no_show' ) ), $notes,
			), 'hook' => 'check_reservation' ),
			'ingredient'  => array( 'label' => 'Ingredient', 'plural' => 'Ingredients & stock', 'fields' => array(
				$name, call_user_func( $f, 'unit', 'Unit', 'select', array( 'opts' => array( 'kg', 'g', 'l', 'ml', 'pcs', 'pack' ), 'show' => true ) ), call_user_func( $f, 'qty', 'In stock', 'number', array( 'col' => 'qty', 'show' => true ) ),
				call_user_func( $f, 'reorder', 'Reorder level', 'number', array( 'show' => true ) ), call_user_func( $f, 'amount', 'Cost per unit', 'money', array( 'col' => 'amount' ) ), call_user_func( $f, 'ref_id', 'Usual supplier', 'record', array( 'col' => 'ref_id', 'entity' => 'supplier' ) ),
			) ),
			'supplier'    => array( 'label' => 'Supplier', 'plural' => 'Suppliers', 'fields' => array( $name, $phone, $email, call_user_func( $f, 'gstin', 'GSTIN', 'text' ), $notes ) ),
			'purchase'    => array( 'label' => 'Purchase', 'plural' => 'Purchases (stock in)', 'fields' => array(
				call_user_func( $f, 'parent_id', 'Ingredient', 'record', array( 'col' => 'parent_id', 'entity' => 'ingredient', 'req' => true, 'show' => true ) ), call_user_func( $f, 'ref_id', 'Supplier', 'record', array( 'col' => 'ref_id', 'entity' => 'supplier', 'show' => true ) ),
				call_user_func( $f, 'qty', 'Quantity', 'number', array( 'col' => 'qty', 'req' => true, 'show' => true ) ), call_user_func( $f, 'amount', 'Total cost', 'money', array( 'col' => 'amount', 'show' => true ) ), call_user_func( $f, 'on_date', 'Date', 'date', array( 'col' => 'on_date', 'show' => true ) ),
			), 'hook' => 'stock_in', 'immutable' => true ),
			'wastage'     => array( 'label' => 'Wastage', 'plural' => 'Wastage', 'fields' => array(
				call_user_func( $f, 'parent_id', 'Ingredient', 'record', array( 'col' => 'parent_id', 'entity' => 'ingredient', 'req' => true, 'show' => true ) ), call_user_func( $f, 'qty', 'Quantity', 'number', array( 'col' => 'qty', 'req' => true, 'show' => true ) ),
				call_user_func( $f, 'reason', 'Reason', 'select', array( 'opts' => array( 'Spoiled', 'Expired', 'Spilled', 'Returned by guest', 'Other' ), 'show' => true ) ), call_user_func( $f, 'on_date', 'Date', 'date', array( 'col' => 'on_date', 'show' => true ) ),
			), 'hook' => 'stock_out', 'immutable' => true ),
			'loyalty'     => array( 'label' => 'Loyalty account', 'plural' => 'Loyalty', 'fields' => array(
				call_user_func( $f, 'customer_id', 'Customer', 'customer', array( 'col' => 'customer_id', 'req' => true, 'show' => true ) ), call_user_func( $f, 'qty', 'Points', 'number', array( 'col' => 'qty', 'show' => true ) ),
				call_user_func( $f, 'tier', 'Tier', 'select', array( 'opts' => array( 'Regular', 'Silver', 'Gold', 'Platinum' ), 'show' => true ) ), call_user_func( $f, 'visits', 'Visits', 'number', array( 'show' => true ) ),
			) ),
			'offer'       => array( 'label' => 'Offer', 'plural' => 'Offers & discounts', 'fields' => array(
				$name, call_user_func( $f, 'kind', 'Discount type', 'select', array( 'opts' => array( 'percent', 'amount' ), 'show' => true ) ), call_user_func( $f, 'amount', 'Value', 'money', array( 'col' => 'amount', 'show' => true ) ),
				call_user_func( $f, 'on_date', 'From', 'date', array( 'col' => 'on_date' ) ), call_user_func( $f, 'end_date', 'Until', 'date', array( 'col' => 'end_date', 'show' => true ) ), $st( array( 'active', 'paused', 'ended' ) ),
			) ),

			/* ---- Hotel ---- */
			'room_type'   => array( 'label' => 'Room type', 'plural' => 'Room types', 'fields' => array(
				$name, call_user_func( $f, 'amount', 'Base nightly rate', 'money', array( 'col' => 'amount', 'req' => true, 'show' => true ) ), call_user_func( $f, 'qty', 'Max guests', 'number', array( 'col' => 'qty', 'show' => true ) ),
				call_user_func( $f, 'description', 'Description', 'textarea' ), call_user_func( $f, 'image', 'Image', 'image' ),
			) ),
			'room'        => array( 'label' => 'Room', 'plural' => 'Rooms', 'fields' => array(
				call_user_func( $f, 'code', 'Room number', 'text', array( 'col' => 'code', 'req' => true, 'show' => true ) ), call_user_func( $f, 'title', 'Name', 'text', array( 'col' => 'title' ) ),
				call_user_func( $f, 'parent_id', 'Room type', 'record', array( 'col' => 'parent_id', 'entity' => 'room_type', 'req' => true, 'show' => true ) ), call_user_func( $f, 'floor', 'Floor', 'text', array( 'show' => true ) ),
				$st( array( 'clean', 'dirty', 'inspected', 'maintenance', 'out_of_order' ) ),
			) ),
			'housekeeping' => array( 'label' => 'Housekeeping task', 'plural' => 'Housekeeping', 'fields' => array(
				call_user_func( $f, 'parent_id', 'Room', 'record', array( 'col' => 'parent_id', 'entity' => 'room', 'req' => true, 'show' => true ) ), call_user_func( $f, 'title', 'Task', 'text', array( 'col' => 'title', 'def' => 'Clean room', 'show' => true ) ),
				call_user_func( $f, 'staff_id', 'Assigned to', 'staff', array( 'col' => 'staff_id', 'show' => true ) ), call_user_func( $f, 'on_date', 'Date', 'date', array( 'col' => 'on_date', 'show' => true ) ), $st( array( 'open', 'in_progress', 'done' ) ),
			), 'hook' => 'after_housekeeping' ),
			'maintenance' => array( 'label' => 'Maintenance job', 'plural' => 'Maintenance', 'fields' => array(
				call_user_func( $f, 'parent_id', 'Room', 'record', array( 'col' => 'parent_id', 'entity' => 'room', 'show' => true ) ), call_user_func( $f, 'title', 'Issue', 'text', array( 'col' => 'title', 'req' => true, 'show' => true ) ),
				call_user_func( $f, 'staff_id', 'Assigned to', 'staff', array( 'col' => 'staff_id' ) ), call_user_func( $f, 'amount', 'Cost', 'money', array( 'col' => 'amount' ) ), $st( array( 'open', 'in_progress', 'done' ) ),
			) ),
			'guest_request' => array( 'label' => 'Guest request', 'plural' => 'Guest requests & complaints', 'fields' => array(
				call_user_func( $f, 'title', 'Request', 'text', array( 'col' => 'title', 'req' => true, 'show' => true ) ), call_user_func( $f, 'kind', 'Kind', 'select', array( 'opts' => array( 'request', 'complaint', 'room_service' ), 'show' => true ) ),
				call_user_func( $f, 'ref_id', 'Stay number', 'number', array( 'col' => 'ref_id', 'show' => true ) ), call_user_func( $f, 'amount', 'Charge to stay', 'money', array( 'col' => 'amount' ) ),
				call_user_func( $f, 'staff_id', 'Assigned to', 'staff', array( 'col' => 'staff_id' ) ), $st( array( 'open', 'in_progress', 'resolved' ) ), $notes,
			), 'hook' => 'check_guest_request' ),
			'rate_season' => array( 'label' => 'Seasonal rate', 'plural' => 'Seasonal rates', 'fields' => array(
				call_user_func( $f, 'title', 'Season', 'text', array( 'col' => 'title', 'req' => true, 'show' => true ) ), call_user_func( $f, 'parent_id', 'Room type', 'record', array( 'col' => 'parent_id', 'entity' => 'room_type', 'req' => true, 'show' => true ) ),
				call_user_func( $f, 'on_date', 'From', 'date', array( 'col' => 'on_date', 'req' => true, 'show' => true ) ), call_user_func( $f, 'end_date', 'To', 'date', array( 'col' => 'end_date', 'req' => true, 'show' => true ) ),
				call_user_func( $f, 'amount', 'Nightly rate', 'money', array( 'col' => 'amount', 'req' => true, 'show' => true ) ),
			) ),
			'stay_package' => array( 'label' => 'Package', 'plural' => 'Stay packages', 'fields' => array( $name, call_user_func( $f, 'amount', 'Price per night (extra)', 'money', array( 'col' => 'amount', 'show' => true ) ), call_user_func( $f, 'description', 'Includes', 'textarea' ), $st( array( 'active', 'retired' ) ) ) ),

			/* ---- Gym ---- */
			'membership_plan' => array( 'label' => 'Membership plan', 'plural' => 'Membership plans', 'fields' => array(
				$name, call_user_func( $f, 'qty', 'Length in days', 'number', array( 'col' => 'qty', 'req' => true, 'show' => true ) ), call_user_func( $f, 'amount', 'Price', 'money', array( 'col' => 'amount', 'show' => true ) ),
				call_user_func( $f, 'sessions', 'PT sessions included', 'number' ), call_user_func( $f, 'is_trial', 'Trial plan', 'bool' ), $st( array( 'active', 'retired' ) ),
			) ),
			'membership'  => array( 'label' => 'Member', 'plural' => 'Members', 'person' => true, 'fields' => array(
				$name, $phone, $email, call_user_func( $f, 'code', 'Member code', 'text', array( 'col' => 'code', 'auto' => 'MEM', 'show' => true ) ),
				call_user_func( $f, 'ref_id', 'Plan', 'record', array( 'col' => 'ref_id', 'entity' => 'membership_plan', 'req' => true, 'show' => true ) ), call_user_func( $f, 'on_date', 'Starts', 'date', array( 'col' => 'on_date' ) ), call_user_func( $f, 'end_date', 'Ends', 'date', array( 'col' => 'end_date', 'show' => true ) ),
				call_user_func( $f, 'amount', 'Fee', 'money', array( 'col' => 'amount' ) ), call_user_func( $f, 'paid', 'Paid', 'money' ), call_user_func( $f, 'staff_id', 'Trainer', 'staff', array( 'col' => 'staff_id' ) ),
				call_user_func( $f, 'branch', 'Home branch', 'record', array( 'entity' => 'branch' ) ), $st( array( 'active', 'frozen', 'cancelled' ) ),
			), 'hook' => 'check_membership' ),
			'pt_package'  => array( 'label' => 'PT package', 'plural' => 'Personal training', 'fields' => array(
				call_user_func( $f, 'parent_id', 'Member', 'record', array( 'col' => 'parent_id', 'entity' => 'membership', 'req' => true, 'show' => true ) ), call_user_func( $f, 'staff_id', 'Trainer', 'staff', array( 'col' => 'staff_id', 'req' => true, 'show' => true ) ),
				call_user_func( $f, 'qty', 'Sessions left', 'number', array( 'col' => 'qty', 'show' => true ) ), call_user_func( $f, 'amount', 'Price', 'money', array( 'col' => 'amount' ) ), call_user_func( $f, 'end_date', 'Valid until', 'date', array( 'col' => 'end_date' ) ), $st( array( 'active', 'used', 'expired' ) ),
			) ),
			'workout_plan' => array( 'label' => 'Workout plan', 'plural' => 'Workout plans', 'fields' => array(
				call_user_func( $f, 'parent_id', 'Member', 'record', array( 'col' => 'parent_id', 'entity' => 'membership', 'req' => true, 'show' => true ) ), call_user_func( $f, 'title', 'Plan', 'text', array( 'col' => 'title', 'req' => true, 'show' => true ) ),
				call_user_func( $f, 'staff_id', 'Trainer', 'staff', array( 'col' => 'staff_id' ) ), call_user_func( $f, 'plan', 'Exercises', 'textarea' ), call_user_func( $f, 'end_date', 'Review on', 'date', array( 'col' => 'end_date' ) ),
			) ),
			'assessment'  => array( 'label' => 'Fitness assessment', 'plural' => 'Fitness assessments', 'fields' => array(
				call_user_func( $f, 'parent_id', 'Member', 'record', array( 'col' => 'parent_id', 'entity' => 'membership', 'req' => true, 'show' => true ) ), call_user_func( $f, 'on_date', 'Date', 'date', array( 'col' => 'on_date', 'show' => true ) ),
				call_user_func( $f, 'weight_kg', 'Weight (kg)', 'number', array( 'show' => true ) ), call_user_func( $f, 'body_fat', 'Body fat %', 'number' ), call_user_func( $f, 'bmi', 'BMI', 'number' ), $notes,
			) ),
			'trainer_rate' => array( 'label' => 'Trainer pay rule', 'plural' => 'Trainer pay rules', 'fields' => array(
				call_user_func( $f, 'staff_id', 'Trainer', 'staff', array( 'col' => 'staff_id', 'req' => true, 'show' => true ) ), call_user_func( $f, 'amount', 'Pay per completed session', 'money', array( 'col' => 'amount', 'show' => true ) ),
				call_user_func( $f, 'qty', 'Share of fees from assigned members (%)', 'number', array( 'col' => 'qty', 'show' => true ) ), call_user_func( $f, 'fixed', 'Fixed amount per period', 'money', array( 'show' => true ) ), $notes,
			), 'hook' => 'check_one_rule' ),
			'trainer_pay' => array( 'label' => 'Trainer payout', 'plural' => 'Trainer compensation', 'fields' => array(
				call_user_func( $f, 'staff_id', 'Trainer', 'staff', array( 'col' => 'staff_id', 'req' => true, 'show' => true ) ), call_user_func( $f, 'title', 'Period', 'text', array( 'col' => 'title', 'show' => true ) ),
				call_user_func( $f, 'qty', 'Sessions', 'number', array( 'col' => 'qty' ) ), call_user_func( $f, 'amount', 'Amount', 'money', array( 'col' => 'amount', 'show' => true ) ), $st( array( 'pending', 'paid' ) ),
			) ),
			'equipment'   => array( 'label' => 'Equipment', 'plural' => 'Equipment', 'fields' => array(
				$name, call_user_func( $f, 'code', 'Asset tag', 'text', array( 'col' => 'code', 'auto' => 'EQ' ) ), call_user_func( $f, 'end_date', 'Next service', 'date', array( 'col' => 'end_date', 'show' => true ) ),
				call_user_func( $f, 'branch', 'Branch', 'record', array( 'entity' => 'branch' ) ), $st( array( 'working', 'service_due', 'out_of_order', 'retired' ) ),
			) ),
			'branch'      => array( 'label' => 'Branch', 'plural' => 'Branches', 'fields' => array( $name, call_user_func( $f, 'address', 'Address', 'textarea' ), $phone, call_user_func( $f, 'qty', 'Capacity', 'number', array( 'col' => 'qty', 'show' => true ) ) ) ),

			/* ---- Salon ---- */
			'walkin'      => array( 'label' => 'Walk-in', 'plural' => 'Walk-ins', 'person' => true, 'fields' => array(
				$name, $phone, call_user_func( $f, 'service', 'Service', 'text', array( 'show' => true ) ), call_user_func( $f, 'staff_id', 'Stylist', 'staff', array( 'col' => 'staff_id', 'show' => true ) ),
				call_user_func( $f, 'space_id', 'Chair or station', 'space', array( 'col' => 'space_id' ) ), call_user_func( $f, 'amount', 'Amount', 'money', array( 'col' => 'amount', 'show' => true ) ), call_user_func( $f, 'on_date', 'Date', 'date', array( 'col' => 'on_date', 'show' => true ) ),
				$st( array( 'waiting', 'in_service', 'done', 'left' ) ),
			) ),
			'commission_rule' => array( 'label' => 'Commission rule', 'plural' => 'Staff commissions', 'fields' => array(
				call_user_func( $f, 'staff_id', 'Staff member', 'staff', array( 'col' => 'staff_id', 'req' => true, 'show' => true ) ), call_user_func( $f, 'qty', 'Commission %', 'number', array( 'col' => 'qty', 'req' => true, 'show' => true ) ), $notes,
			), 'hook' => 'check_one_rule' ),
			'salon_package' => array( 'label' => 'Package', 'plural' => 'Packages', 'fields' => array(
				$name, call_user_func( $f, 'qty', 'Sessions', 'number', array( 'col' => 'qty', 'show' => true ) ), call_user_func( $f, 'amount', 'Price', 'money', array( 'col' => 'amount', 'show' => true ) ), call_user_func( $f, 'valid_days', 'Valid for (days)', 'number' ), $st( array( 'active', 'retired' ) ),
			) ),
			'package_sale' => array( 'label' => 'Prepaid package', 'plural' => 'Prepaid sessions', 'fields' => array(
				call_user_func( $f, 'customer_id', 'Client', 'customer', array( 'col' => 'customer_id', 'req' => true, 'show' => true ) ), call_user_func( $f, 'ref_id', 'Package', 'record', array( 'col' => 'ref_id', 'entity' => 'salon_package', 'req' => true, 'show' => true ) ),
				call_user_func( $f, 'qty', 'Sessions left', 'number', array( 'col' => 'qty', 'show' => true ) ), call_user_func( $f, 'end_date', 'Valid until', 'date', array( 'col' => 'end_date', 'show' => true ) ), $st( array( 'active', 'used', 'expired' ) ),
			), 'hook' => 'check_package_sale' ),
			'product'     => array( 'label' => 'Product', 'plural' => 'Products & stock', 'fields' => array(
				$name, call_user_func( $f, 'amount', 'Price', 'money', array( 'col' => 'amount', 'show' => true ) ), call_user_func( $f, 'qty', 'In stock', 'number', array( 'col' => 'qty', 'show' => true ) ), call_user_func( $f, 'reorder', 'Reorder level', 'number' ), call_user_func( $f, 'brand', 'Brand', 'text' ),
			) ),

			/* ---- Real estate ---- */
			'owner'       => array( 'label' => 'Owner or seller', 'plural' => 'Owners & sellers', 'person' => true, 'fields' => array( $name, $phone, $email, call_user_func( $f, 'address', 'Address', 'textarea' ), $notes ) ),
			'property'    => array( 'label' => 'Property', 'plural' => 'Property listings', 'fields' => array(
				call_user_func( $f, 'title', 'Title', 'text', array( 'col' => 'title', 'req' => true, 'show' => true ) ), call_user_func( $f, 'code', 'Listing ID', 'text', array( 'col' => 'code', 'auto' => 'PRP' ) ),
				call_user_func( $f, 'listing', 'For', 'select', array( 'opts' => array( 'sale', 'rent', 'lease' ), 'show' => true ) ), call_user_func( $f, 'ptype', 'Type', 'select', array( 'opts' => array( 'Flat', 'Apartment', 'Villa', 'Plot', 'Commercial', 'Office', 'Shop' ), 'list' => 'property_type', 'show' => true ) ),
				call_user_func( $f, 'amount', 'Price or rent', 'money', array( 'col' => 'amount', 'show' => true ) ), call_user_func( $f, 'location', 'Location', 'text', array( 'show' => true ) ), call_user_func( $f, 'area_sqft', 'Area (sq ft)', 'number' ),
				call_user_func( $f, 'bedrooms', 'Bedrooms', 'number' ), call_user_func( $f, 'specs', 'Specifications', 'textarea' ), call_user_func( $f, 'images', 'Image links', 'textarea' ), call_user_func( $f, 'video', 'Video link', 'url' ), call_user_func( $f, 'map', 'Map link', 'url' ),
				call_user_func( $f, 'ref_id', 'Owner', 'record', array( 'col' => 'ref_id', 'entity' => 'owner' ) ), call_user_func( $f, 'staff_id', 'Agent', 'staff', array( 'col' => 'staff_id' ) ),
				$st( array( 'available', 'on_hold', 'token_received', 'sold', 'rented', 'withdrawn' ) ),
			), 'hook' => 'check_inventory_status' ),
			'site_visit'  => array( 'label' => 'Site visit', 'plural' => 'Site visits', 'fields' => array(
				call_user_func( $f, 'parent_id', 'Property', 'record', array( 'col' => 'parent_id', 'entity' => 'property', 'req' => true, 'show' => true ) ), call_user_func( $f, 'customer_id', 'Buyer or tenant', 'customer', array( 'col' => 'customer_id', 'req' => true, 'show' => true ) ),
				call_user_func( $f, 'on_date', 'Date', 'date', array( 'col' => 'on_date', 'req' => true, 'show' => true ) ), call_user_func( $f, 'time', 'Time', 'time' ), call_user_func( $f, 'staff_id', 'Agent', 'staff', array( 'col' => 'staff_id', 'show' => true ) ),
				$st( array( 'scheduled', 'done', 'cancelled', 'no_show' ) ), call_user_func( $f, 'feedback', 'Feedback', 'textarea' ),
			) ),
			'offer_neg'   => array( 'label' => 'Offer', 'plural' => 'Offers & negotiations', 'fields' => array(
				call_user_func( $f, 'parent_id', 'Property', 'record', array( 'col' => 'parent_id', 'entity' => 'property', 'req' => true, 'show' => true ) ), call_user_func( $f, 'customer_id', 'Buyer or tenant', 'customer', array( 'col' => 'customer_id', 'req' => true, 'show' => true ) ),
				call_user_func( $f, 'amount', 'Offer amount', 'money', array( 'col' => 'amount', 'req' => true, 'show' => true ) ), call_user_func( $f, 'on_date', 'Date', 'date', array( 'col' => 'on_date' ) ), $st( array( 'made', 'countered', 'accepted', 'rejected', 'withdrawn' ) ), $notes,
			) ),
			'campaign'    => array( 'label' => 'Campaign', 'plural' => 'Lead sources & campaigns', 'fields' => array(
				$name, call_user_func( $f, 'channel', 'Channel', 'select', array( 'opts' => array( 'Website', 'Portal', 'Facebook', 'Instagram', 'Google', 'Hoarding', 'Referral', 'Walk-in' ), 'list' => 'campaign_channel', 'show' => true ) ),
				call_user_func( $f, 'amount', 'Spend', 'money', array( 'col' => 'amount', 'show' => true ) ), call_user_func( $f, 'on_date', 'From', 'date', array( 'col' => 'on_date' ) ), call_user_func( $f, 'end_date', 'To', 'date', array( 'col' => 'end_date' ) ),
			) ),
			'deal'        => array( 'label' => 'Deal', 'plural' => 'Tokens, bookings & agreements', 'fields' => array(
				call_user_func( $f, 'parent_id', 'Property', 'record', array( 'col' => 'parent_id', 'entity' => 'property', 'show' => true ) ), call_user_func( $f, 'ref_id', 'Unit (developer)', 'record', array( 'col' => 'ref_id', 'entity' => 'unit', 'show' => true ) ),
				call_user_func( $f, 'customer_id', 'Buyer or tenant', 'customer', array( 'col' => 'customer_id', 'req' => true, 'show' => true ) ), call_user_func( $f, 'amount', 'Token or booking amount', 'money', array( 'col' => 'amount', 'show' => true ) ),
				call_user_func( $f, 'deal_value', 'Deal value', 'money' ), call_user_func( $f, 'on_date', 'Token date', 'date', array( 'col' => 'on_date' ) ), call_user_func( $f, 'agreement', 'Agreement document link', 'url' ),
				call_user_func( $f, 'staff_id', 'Agent', 'staff', array( 'col' => 'staff_id' ) ), $st( array( 'token_received', 'agreement_signed', 'registered', 'cancelled' ) ),
			), 'hook' => 'check_deal' ),
			'instalment'  => array( 'label' => 'Instalment', 'plural' => 'Instalments', 'fields' => array(
				call_user_func( $f, 'parent_id', 'Deal', 'record', array( 'col' => 'parent_id', 'entity' => 'deal', 'req' => true, 'show' => true ) ), call_user_func( $f, 'title', 'Milestone', 'text', array( 'col' => 'title', 'show' => true ) ),
				call_user_func( $f, 'amount', 'Amount', 'money', array( 'col' => 'amount', 'req' => true, 'show' => true ) ), call_user_func( $f, 'end_date', 'Due on', 'date', array( 'col' => 'end_date', 'req' => true, 'show' => true ) ),
				call_user_func( $f, 'on_date', 'Received on', 'date', array( 'col' => 'on_date' ) ), $st( array( 'due', 'received', 'waived' ) ),
			), 'hook' => 'check_fee' ),
			'commission'  => array( 'label' => 'Commission', 'plural' => 'Brokerage & commissions', 'fields' => array(
				call_user_func( $f, 'parent_id', 'Deal', 'record', array( 'col' => 'parent_id', 'entity' => 'deal', 'req' => true, 'show' => true ) ), call_user_func( $f, 'staff_id', 'Agent', 'staff', array( 'col' => 'staff_id', 'show' => true ) ),
				call_user_func( $f, 'qty', 'Rate %', 'number', array( 'col' => 'qty' ) ), call_user_func( $f, 'amount', 'Amount', 'money', array( 'col' => 'amount', 'show' => true ) ), $st( array( 'pending', 'invoiced', 'received', 'paid_out' ) ),
			) ),
			'tower'       => array( 'label' => 'Tower', 'plural' => 'Projects & towers', 'fields' => array( $name, call_user_func( $f, 'qty', 'Floors', 'number', array( 'col' => 'qty', 'show' => true ) ), call_user_func( $f, 'location', 'Location', 'text', array( 'show' => true ) ), call_user_func( $f, 'rera', 'RERA number', 'text' ) ) ),
			'unit'        => array( 'label' => 'Unit', 'plural' => 'Units', 'fields' => array(
				call_user_func( $f, 'parent_id', 'Tower', 'record', array( 'col' => 'parent_id', 'entity' => 'tower', 'req' => true, 'show' => true ) ), call_user_func( $f, 'code', 'Unit number', 'text', array( 'col' => 'code', 'req' => true, 'show' => true ) ),
				call_user_func( $f, 'floor', 'Floor', 'number', array( 'show' => true ) ), call_user_func( $f, 'config', 'Configuration', 'select', array( 'opts' => array( '1 BHK', '2 BHK', '3 BHK', '4 BHK', 'Studio', 'Shop', 'Office' ), 'list' => 'unit_config', 'show' => true ) ),
				call_user_func( $f, 'area_sqft', 'Area (sq ft)', 'number' ), call_user_func( $f, 'amount', 'Price', 'money', array( 'col' => 'amount', 'show' => true ) ),
				$st( array( 'available', 'on_hold', 'token_received', 'sold', 'withdrawn' ) ),
			), 'hook' => 'check_inventory_status' ),

			/* ---- Other ---- */
			'custom_record' => array( 'label' => 'Record', 'plural' => 'Records', 'fields' => array(
				call_user_func( $f, 'title', 'Title', 'text', array( 'col' => 'title', 'req' => true, 'show' => true ) ), call_user_func( $f, 'kind', 'Type', 'select', array( 'opts' => array(), 'list' => 'record_type', 'show' => true ) ),
				call_user_func( $f, 'customer_id', 'Customer', 'customer', array( 'col' => 'customer_id', 'show' => true ) ), call_user_func( $f, 'amount', 'Amount', 'money', array( 'col' => 'amount' ) ), call_user_func( $f, 'on_date', 'Date', 'date', array( 'col' => 'on_date', 'show' => true ) ),
				call_user_func( $f, 'status', 'Stage', 'select', array( 'col' => 'status', 'opts' => array( 'open', 'in_progress', 'done' ), 'list' => 'record_stage', 'def' => 'open', 'show' => true ) ), $notes,
			) ),
		);
		foreach ( self::modules() as $mk => $m ) {
			foreach ( $m['entities'] as $ek ) {
				if ( isset( $e[ $ek ] ) && empty( $e[ $ek ]['module'] ) ) {
					$e[ $ek ]['module'] = $mk;
				}
			}
		}
		return apply_filters( 'nwc_business_entities', $e );
	}

	/* ============================ Configuration for one Workspace ============================ */

	public static function config( $w ) {
		$biz     = NWC_Bookings::business( $w->id );
		$cat     = $biz ? NWC_Catalog::category_key( $biz->category ) : 'other';
		$pk      = self::profile_key( $cat );
		$p       = self::profiles()[ $pk ];
		$saved   = $biz ? json_decode( (string) $biz->modules, true ) : array();
		$saved   = is_array( $saved ) ? $saved : array();
		$on      = array();
		foreach ( $p['common'] as $c ) {
			$on[ $c ] = ! in_array( $c, (array) ( $saved['off'] ?? array() ), true );
		}
		foreach ( $p['optional'] as $c ) {
			$on[ $c ] = in_array( $c, (array) ( $saved['on'] ?? array() ), true );
		}
		$mods = array();
		$all  = self::modules();
		foreach ( $p['modules'] as $mk ) {
			if ( ! isset( $all[ $mk ] ) ) {
				continue;
			}
			$m = $all[ $mk ];
			$enabled = ! in_array( $mk, (array) ( $saved['off'] ?? array() ), true );
			$mods[] = array( 'key' => $mk, 'label' => $m['label'], 'icon' => $m['icon'], 'view' => $m['view'], 'entities' => $m['entities'], 'enabled' => $enabled, 'read' => $m['read'], 'write' => $m['write'] );
		}
		$common = array();
		foreach ( array_merge( $p['common'], $p['optional'] ) as $c ) {
			$d        = self::COMMON[ $c ];
			$common[] = array( 'key' => $c, 'label' => $p['labels'][ $c ] ?? $d[0], 'path' => $d[1], 'feature' => $d[2], 'perm' => $d[3], 'enabled' => $on[ $c ], 'optional' => in_array( $c, $p['optional'], true ) );
		}
		return array(
			'category' => $cat, 'profile' => $pk, 'label' => 'other' === $pk && $biz && '' !== (string) $biz->custom_label ? $biz->custom_label : $p['label'],
			'description' => $p['description'], 'modules' => $mods, 'common' => $common, 'customer_fields' => self::customer_fields( $w->id, $pk ),
			'booking_kinds' => array_map( function ( $k ) {
				return array( 'key' => $k, 'label' => self::BOOKING_KINDS[ $k ] ?? $k );
			}, $p['booking_kinds'] ),
			'station' => $p['station'] ?? 'kitchen', 'custom_label' => $biz ? (string) $biz->custom_label : '',
		);
	}

	/** The category's fields plus the Workspace's own (saved as list "customer_field"). */
	public static function customer_fields( $workspace_id, $pk ) {
		$out = array();
		foreach ( self::profiles()[ $pk ]['customer_fields'] as $cf ) {
			$out[] = array_merge( array( 'key' => $cf[0], 'label' => $cf[1], 'type' => $cf[2] ), $cf[3] ?? array() );
		}
		foreach ( self::list_values( $workspace_id, 'customer_field' ) as $label ) {
			$out[] = array( 'key' => 'c_' . substr( md5( $label ), 0, 8 ), 'label' => $label, 'type' => 'text', 'own' => true );
		}
		return $out;
	}

	/** Entities a Workspace can use right now: those of its enabled category modules. */
	public static function active_entities( $w ) {
		$cfg = self::config( $w );
		$out = array();
		foreach ( $cfg['modules'] as $m ) {
			if ( $m['enabled'] ) {
				foreach ( $m['entities'] as $e ) {
					$out[ $e ] = $m;
				}
			}
		}
		return $out;
	}

	public static function module_enabled( $w, $module ) {
		foreach ( self::config( $w )['modules'] as $m ) {
			if ( $m['key'] === $module ) {
				return $m['enabled'];
			}
		}
		return false;
	}

	/** @return true|WP_Error */
	public static function guard_module( $module, $perm_kind = 'read' ) {
		$w = NWC_Tenancy::$current;
		if ( NWC_Personal::is_personal( $w ) || ! self::module_enabled( $w, $module ) ) {
			return NWC::err( 'This module is not part of this Workspace\'s business type.', 404, 'nwc_module_off' );
		}
		$m = self::modules()[ $module ];
		if ( ! NWC_Tenancy::can( $m[ $perm_kind ] ) ) {
			return NWC::err( 'Your role does not allow this.', 403, 'nwc_forbidden' );
		}
		return true;
	}

	/* ============================ Custom lists ============================ */

	public static function list_values( $workspace_id, $key ) {
		global $wpdb;
		return $wpdb->get_col( $wpdb->prepare( 'SELECT value FROM ' . NWC_DB::t( 'biz_lists' ) . ' WHERE workspace_id = %d AND list_key = %s ORDER BY value ASC LIMIT 300', $workspace_id, $key ) ); // phpcs:ignore
	}

	/** Saves a value for reuse. Idempotent: the unique key ignores a second copy. */
	public static function remember( $workspace_id, $key, $value ) {
		global $wpdb;
		$value = mb_substr( trim( sanitize_text_field( (string) $value ) ), 0, 120 );
		$key   = sanitize_key( $key );
		if ( '' === $value || '' === $key ) {
			return;
		}
		$wpdb->suppress_errors( true );
		$wpdb->query( $wpdb->prepare( 'INSERT IGNORE INTO ' . NWC_DB::t( 'biz_lists' ) . ' (workspace_id, list_key, value, created_by, created_at) VALUES (%d, %s, %s, %d, %s)', $workspace_id, $key, $value, get_current_user_id(), NWC::now() ) ); // phpcs:ignore
		$wpdb->suppress_errors( false );
	}

	/* ============================ REST ============================ */

	public static function routes() {
		NWC_REST::route( '/industry', 'GET', array( __CLASS__, 'rest_config' ) );
		NWC_REST::route( '/industry/modules', 'PUT', array( __CLASS__, 'rest_modules' ) );
		NWC_REST::route( '/industry/dashboard', 'GET', array( __CLASS__, 'rest_dashboard' ) );
		NWC_REST::route( '/lists', 'GET', array( __CLASS__, 'rest_lists' ) );
		NWC_REST::route( '/lists', 'POST', array( __CLASS__, 'rest_list_add' ) );
		NWC_REST::route( '/lists', 'DELETE', array( __CLASS__, 'rest_list_remove' ) );
		NWC_REST::route( '/records', 'GET', array( __CLASS__, 'rest_index' ) );
		NWC_REST::route( '/records', 'POST', array( __CLASS__, 'rest_create' ) );
		NWC_REST::route( '/records/(?P<id>\d+)', 'GET', array( __CLASS__, 'rest_show' ) );
		NWC_REST::route( '/records/(?P<id>\d+)', 'PUT,PATCH', array( __CLASS__, 'rest_update' ) );
		NWC_REST::route( '/records/(?P<id>\d+)', 'DELETE', array( __CLASS__, 'rest_delete' ) );
	}

	public static function rest_config() {
		$w = NWC_Tenancy::$current;
		if ( NWC_Personal::is_personal( $w ) ) {
			return array( 'category' => null, 'modules' => array(), 'common' => array() );
		}
		$cfg = self::config( $w );
		$ents = self::entities();
		$cfg['entities'] = array();
		foreach ( self::active_entities( $w ) as $ek => $m ) {
			$def = $ents[ $ek ];
			$cfg['entities'][ $ek ] = array( 'key' => $ek, 'label' => $def['label'], 'plural' => $def['plural'], 'module' => $m['key'], 'person' => ! empty( $def['person'] ), 'immutable' => ! empty( $def['immutable'] ), 'fields' => array_map( function ( $fd ) use ( $w ) {
				if ( ! empty( $fd['list'] ) ) {
					$fd['saved'] = self::list_values( $w->id, $fd['list'] );
				}
				return $fd;
			}, $def['fields'] ) );
		}
		$cfg['categories'] = array_values( array_filter( NWC_Catalog::for_app(), function ( $c ) {
			return ! $c['legacy'];
		} ) );
		return $cfg;
	}

	/** Owner or admin: switch optional modules on, or modules off. Data stays when a module is off. */
	public static function rest_modules( WP_REST_Request $r ) {
		global $wpdb;
		if ( ! NWC_Tenancy::can( 'settings.manage' ) ) {
			return NWC::err( 'Your role does not allow this.', 403, 'nwc_forbidden' );
		}
		$w   = NWC_Tenancy::$current;
		$b   = NWC_REST::body( $r );
		$pk  = self::config( $w )['profile'];
		$p   = self::profiles()[ $pk ];
		$known = array_merge( $p['modules'], $p['common'], $p['optional'] );
		$on  = array_values( array_intersect( $p['optional'], array_map( 'strval', (array) ( $b['on'] ?? array() ) ) ) );
		$off = array_values( array_intersect( array_merge( $p['modules'], $p['common'] ), array_map( 'strval', (array) ( $b['off'] ?? array() ) ) ) );
		$row = array( 'modules' => wp_json_encode( array( 'on' => $on, 'off' => $off ) ), 'updated_at' => NWC::now() );
		if ( 'other' === $pk && array_key_exists( 'custom_label', $b ) ) {
			$row['custom_label'] = mb_substr( sanitize_text_field( (string) $b['custom_label'] ), 0, 190 );
		}
		$wpdb->update( NWC_DB::t( 'business' ), $row, array( 'workspace_id' => $w->id ) );
		NWC::audit( 'modules_changed', array( 'kind' => 'admin', 'entity' => 'workspace', 'entity_id' => $w->id, 'details' => array( 'on' => $on, 'off' => $off, 'known' => $known ) ) );
		return self::rest_config();
	}

	public static function rest_lists( WP_REST_Request $r ) {
		return array( 'key' => sanitize_key( (string) $r->get_param( 'key' ) ), 'values' => self::list_values( NWC_Tenancy::$current->id, sanitize_key( (string) $r->get_param( 'key' ) ) ) );
	}

	public static function rest_list_add( WP_REST_Request $r ) {
		if ( ! NWC_Tenancy::can( 'crm.write' ) && ! NWC_Tenancy::can( 'bookings.manage' ) && ! NWC_Tenancy::can( 'settings.manage' ) ) {
			return NWC::err( 'Your role does not allow this.', 403, 'nwc_forbidden' );
		}
		$b = NWC_REST::body( $r );
		if ( '' === trim( (string) ( $b['value'] ?? '' ) ) ) {
			return NWC::err( 'Enter a value.' );
		}
		self::remember( NWC_Tenancy::$current->id, (string) ( $b['key'] ?? '' ), (string) $b['value'] );
		return array( 'values' => self::list_values( NWC_Tenancy::$current->id, sanitize_key( (string) ( $b['key'] ?? '' ) ) ) );
	}

	public static function rest_list_remove( WP_REST_Request $r ) {
		global $wpdb;
		if ( ! NWC_Tenancy::can( 'settings.manage' ) ) {
			return NWC::err( 'Your role does not allow this.', 403, 'nwc_forbidden' );
		}
		$b = NWC_REST::body( $r );
		$wpdb->delete( NWC_DB::t( 'biz_lists' ), array( 'workspace_id' => NWC_Tenancy::$current->id, 'list_key' => sanitize_key( (string) ( $b['key'] ?? '' ) ), 'value' => (string) ( $b['value'] ?? '' ) ) );
		return array( 'ok' => true );
	}

	/* ---------------------------- Records ---------------------------- */

	private static function entity_or_error( $ek, $kind ) {
		$ents = self::entities();
		$w    = NWC_Tenancy::$current;
		$act  = self::active_entities( $w );
		if ( ! isset( $ents[ $ek ] ) || ! isset( $act[ $ek ] ) ) {
			return NWC::err( 'This kind of record is not part of this Workspace\'s business type.', 404, 'nwc_module_off' );
		}
		$g = self::guard_module( $act[ $ek ]['key'], $kind );
		return is_wp_error( $g ) ? $g : $ents[ $ek ];
	}

	public static function row( $id, $ws = null ) {
		global $wpdb;
		$ws = $ws ? $ws : NWC_Tenancy::$current->id;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . NWC_DB::t( 'biz_records' ) . ' WHERE id = %d AND workspace_id = %d AND deleted_at IS NULL', $id, $ws ) ); // phpcs:ignore
	}

	public static function out( $r, $def = null ) {
		$def  = $def ? $def : ( self::entities()[ $r->entity ] ?? array( 'fields' => array() ) );
		$data = json_decode( (string) $r->data, true );
		$o    = array( 'id' => (int) $r->id, 'entity' => $r->entity, 'module' => $r->module, 'created_at' => $r->created_at, 'updated_at' => $r->updated_at );
		foreach ( self::COLS as $c ) {
			$o[ $c ] = in_array( $c, array( 'customer_id', 'parent_id', 'ref_id', 'staff_id', 'space_id' ), true ) ? ( null === $r->$c ? null : (int) $r->$c ) : ( in_array( $c, array( 'amount', 'qty' ), true ) ? (float) $r->$c : $r->$c );
		}
		foreach ( is_array( $data ) ? $data : array() as $k => $v ) {
			if ( ! in_array( $k, self::COLS, true ) ) {
				$o[ $k ] = $v;
			}
		}
		if ( 'membership' === $r->entity ) {
			$o['state'] = self::membership_state( $r );
		}
		if ( 'fee_due' === $r->entity || 'instalment' === $r->entity ) {
			$o['overdue'] = in_array( $r->status, array( 'due' ), true ) && $r->end_date && $r->end_date < NWC::today( NWC_Tenancy::$current ? NWC_Tenancy::$current->timezone : 'UTC' );
		}
		return $o;
	}

	public static function rest_index( WP_REST_Request $r ) {
		global $wpdb;
		$ek  = sanitize_key( (string) $r->get_param( 'entity' ) );
		$def = self::entity_or_error( $ek, 'read' );
		if ( is_wp_error( $def ) ) {
			return $def;
		}
		$vals = array( (int) NWC_Tenancy::$current->id, $ek );
		$sql  = 'workspace_id = %d AND entity = %s AND deleted_at IS NULL';
		foreach ( array( 'status', 'parent_id', 'ref_id', 'customer_id', 'staff_id', 'space_id', 'on_date' ) as $c ) {
			$v = $r->get_param( $c );
			if ( null !== $v && '' !== $v ) {
				$sql   .= " AND $c = %s";
				$vals[] = (string) $v;
			}
		}
		$q = trim( sanitize_text_field( (string) $r->get_param( 'q' ) ) );
		if ( '' !== $q ) {
			$like   = '%' . $wpdb->esc_like( $q ) . '%';
			$sql   .= ' AND (title LIKE %s OR code LIKE %s OR data LIKE %s)';
			$vals   = array_merge( $vals, array( $like, $like, $like ) );
		}
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . NWC_DB::t( 'biz_records' ) . " WHERE $sql ORDER BY id DESC LIMIT 500", $vals ) ); // phpcs:ignore
		return array( 'items' => array_map( function ( $x ) use ( $def ) {
			return self::out( $x, $def );
		}, $rows ) );
	}

	public static function rest_show( WP_REST_Request $r ) {
		$row = self::row( (int) $r['id'] );
		if ( ! $row ) {
			return NWC::err( 'Not found.', 404, 'nwc_not_found' );
		}
		$def = self::entity_or_error( $row->entity, 'read' );
		return is_wp_error( $def ) ? $def : self::out( $row, $def );
	}

	public static function rest_create( WP_REST_Request $r ) {
		$b = NWC_REST::body( $r );
		return self::save( sanitize_key( (string) ( $b['entity'] ?? '' ) ), 0, $b );
	}

	public static function rest_update( WP_REST_Request $r ) {
		$row = self::row( (int) $r['id'] );
		if ( ! $row ) {
			return NWC::err( 'Not found.', 404, 'nwc_not_found' );
		}
		return self::save( $row->entity, (int) $row->id, NWC_REST::body( $r ) );
	}

	public static function rest_delete( WP_REST_Request $r ) {
		global $wpdb;
		$row = self::row( (int) $r['id'] );
		if ( ! $row ) {
			return NWC::err( 'Not found.', 404, 'nwc_not_found' );
		}
		$def = self::entity_or_error( $row->entity, 'write' );
		if ( is_wp_error( $def ) ) {
			return $def;
		}
		if ( ! empty( $def['immutable'] ) ) {
			return NWC::err( 'Stock movements are kept as a record. Add a correcting entry instead.', 409, 'nwc_conflict' );
		}
		$in_use = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . NWC_DB::t( 'biz_records' ) . ' WHERE workspace_id = %d AND deleted_at IS NULL AND (parent_id = %d OR ref_id = %d) AND id <> %d', $row->workspace_id, $row->id, $row->id, $row->id ) ); // phpcs:ignore
		if ( $in_use && empty( NWC_REST::body( $r )['force'] ) ) {
			return NWC::err( 'Other records use this one (' . $in_use . '). Remove or move them first.', 409, 'nwc_in_use', array( 'count' => $in_use ) );
		}
		$wpdb->update( NWC_DB::t( 'biz_records' ), array( 'deleted_at' => NWC::now() ), array( 'id' => $row->id ) );
		NWC::audit( 'record_deleted', array( 'entity' => $row->entity, 'entity_id' => $row->id ) );
		return array( 'ok' => true );
	}

	/** Cleans one value by type. @return mixed|WP_Error */
	private static function clean_value( $fd, $v, $ws ) {
		global $wpdb;
		switch ( $fd['type'] ) {
			case 'number':
				return '' === $v || null === $v ? null : round( (float) $v, 3 );
			case 'money':
				return round( max( 0, (float) $v ), 2 );
			case 'bool':
				return empty( $v ) ? 0 : 1;
			case 'date':
				if ( '' === (string) $v || null === $v ) {
					return null;
				}
				return NWC::valid_date( $v ) ? $v : NWC::err( $fd['label'] . ' must be a date.' );
			case 'time':
				if ( '' === (string) $v ) {
					return '';
				}
				return preg_match( '/^([01]\d|2[0-3]):[0-5]\d$/', (string) $v ) ? (string) $v : NWC::err( $fd['label'] . ' must be a time like 13:30.' );
			case 'days':
				return array_values( array_intersect( NWC_Bookings::DAYS, array_map( 'strval', (array) $v ) ) );
			case 'email':
				if ( '' !== (string) $v && ! is_email( $v ) ) {
					return NWC::err( 'Enter a valid email address.' );
				}
				return sanitize_email( (string) $v );
			case 'url':
			case 'image':
				return esc_url_raw( (string) $v, array( 'http', 'https' ) );
			case 'textarea':
				return sanitize_textarea_field( (string) $v );
			case 'pairs':
				$out = array();
				foreach ( array_slice( (array) $v, 0, 40 ) as $p ) {
					if ( is_array( $p ) && '' !== trim( (string) ( $p['name'] ?? '' ) ) ) {
						$out[] = array( 'name' => mb_substr( sanitize_text_field( (string) $p['name'] ), 0, 120 ), 'price' => round( (float) ( $p['price'] ?? 0 ), 3 ) );
					}
				}
				return $out;
			case 'select':
				$s = mb_substr( sanitize_text_field( (string) $v ), 0, 120 );
				if ( '' === $s ) {
					return '';
				}
				$opts = (array) ( $fd['opts'] ?? array() );
				if ( empty( $fd['list'] ) && $opts && ! in_array( $s, $opts, true ) ) {
					return NWC::err( 'Choose a valid ' . strtolower( $fd['label'] ) . '.' );
				}
				if ( ! empty( $fd['list'] ) && ! in_array( $s, $opts, true ) ) {
					self::remember( $ws, $fd['list'], $s ); // "Other" typed in: kept for reuse.
				}
				return $s;
			case 'customer':
				$id = absint( $v );
				if ( $id && (int) $wpdb->get_var( $wpdb->prepare( 'SELECT workspace_id FROM ' . NWC_DB::t( 'customers' ) . ' WHERE id = %d AND deleted_at IS NULL', $id ) ) !== (int) $ws ) { // phpcs:ignore
					return NWC::err( 'That customer was not found.', 404, 'nwc_not_found' );
				}
				return $id ? $id : null;
			case 'staff':
				$id = absint( $v );
				if ( $id && ! NWC_Tenancy::member_row( $ws, $id ) ) {
					return NWC::err( 'That person is not a member of this Workspace.' );
				}
				return $id ? $id : null;
			case 'space':
				$id = absint( $v );
				if ( $id && (int) $wpdb->get_var( $wpdb->prepare( 'SELECT workspace_id FROM ' . NWC_DB::t( 'spaces' ) . ' WHERE id = %d AND deleted_at IS NULL', $id ) ) !== (int) $ws ) { // phpcs:ignore
					return NWC::err( 'That space was not found.', 404, 'nwc_not_found' );
				}
				return $id ? $id : null;
			case 'record':
				$id = absint( $v );
				if ( $id ) {
					$t = $wpdb->get_var( $wpdb->prepare( 'SELECT entity FROM ' . NWC_DB::t( 'biz_records' ) . ' WHERE id = %d AND workspace_id = %d AND deleted_at IS NULL', $id, $ws ) ); // phpcs:ignore
					if ( $t !== $fd['entity'] ) {
						return NWC::err( $fd['label'] . ' was not found.', 404, 'nwc_not_found' );
					}
				}
				return $id ? $id : null;
			default:
				return mb_substr( sanitize_text_field( (string) $v ), 0, 255 );
		}
	}

	/**
	 * Creates or updates one record under a per-Workspace lock, so status rules (a unit booked
	 * once, an instrument lent once, a table reserved once) hold under simultaneous requests.
	 */
	public static function save( $ek, $id, $in ) {
		global $wpdb;
		$def = self::entity_or_error( $ek, 'write' );
		if ( is_wp_error( $def ) ) {
			return $def;
		}
		$ws  = (int) NWC_Tenancy::$current->id;
		$old = $id ? self::row( $id ) : null;
		if ( $old && ! empty( $def['immutable'] ) ) {
			return NWC::err( 'Stock movements are kept as a record. Add a correcting entry instead.', 409, 'nwc_conflict' );
		}
		$cols = array();
		$data = $old ? (array) json_decode( (string) $old->data, true ) : array();
		foreach ( $def['fields'] as $fd ) {
			$k = $fd['key'];
			if ( ! array_key_exists( $k, $in ) ) {
				if ( ! $old && isset( $fd['def'] ) ) {
					$in[ $k ] = $fd['def'];
				} else {
					continue;
				}
			}
			if ( ! empty( $fd['readonly'] ) && $old ) {
				continue;
			}
			$v = self::clean_value( $fd, $in[ $k ], $ws );
			if ( is_wp_error( $v ) ) {
				return $v;
			}
			if ( ! empty( $fd['col'] ) ) {
				$cols[ $fd['col'] ] = $v;
			} else {
				$data[ $k ] = $v;
			}
		}
		foreach ( $def['fields'] as $fd ) {
			if ( empty( $fd['req'] ) ) {
				continue;
			}
			$cur = ! empty( $fd['col'] ) ? ( array_key_exists( $fd['col'], $cols ) ? $cols[ $fd['col'] ] : ( $old ? $old->{$fd['col']} : null ) ) : ( $data[ $fd['key'] ] ?? null );
			if ( null === $cur || '' === $cur ) {
				return NWC::err( $fd['label'] . ' is required.' );
			}
		}
		if ( ! NWC::lock( 'rec:' . $ws ) ) {
			return NWC::err( 'Busy right now. Please try again.', 503, 'nwc_busy' );
		}
		try {
			foreach ( $def['fields'] as $fd ) { // Generated codes: unique within the entity.
				if ( ! empty( $fd['auto'] ) && 'code' === ( $fd['col'] ?? '' ) && ! $old && empty( $cols['code'] ) ) {
					$cols['code'] = 'qr' === $fd['auto'] ? bin2hex( random_bytes( 6 ) ) : $fd['auto'] . '-' . str_pad( (string) ( 1 + (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . NWC_DB::t( 'biz_records' ) . ' WHERE workspace_id = %d AND entity = %s', $ws, $ek ) ) ), 4, '0', STR_PAD_LEFT ); // phpcs:ignore
				}
			}
			if ( isset( $cols['code'] ) && '' !== (string) $cols['code'] ) {
				$dup = $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . NWC_DB::t( 'biz_records' ) . ' WHERE workspace_id = %d AND entity = %s AND code = %s AND id <> %d', $ws, $ek, $cols['code'], $old ? $old->id : 0 ) ); // phpcs:ignore
				if ( $dup ) {
					return NWC::err( 'That number or code is already used.', 409, 'nwc_duplicate' );
				}
			} elseif ( isset( $cols['code'] ) ) {
				$cols['code'] = null;
			}
			if ( ! empty( $def['hook'] ) ) {
				$res = call_user_func( array( __CLASS__, $def['hook'] ), $cols, $data, $old, $in );
				if ( is_wp_error( $res ) ) {
					return $res;
				}
				list( $cols, $data ) = $res;
			}
			if ( ! empty( $def['person'] ) ) { // Basic identity lives in CRM as well.
				$nm = (string) ( $cols['title'] ?? ( $old ? $old->title : '' ) );
				if ( '' !== $nm && ( ! $old || ! $old->customer_id ) ) {
					$cols['customer_id'] = NWC_CRM::upsert_customer( $ws, array( 'name' => $nm, 'phone' => (string) ( $data['phone'] ?? '' ), 'email' => (string) ( $data['email'] ?? '' ), 'source' => $ek ) );
				}
			}
			$now = NWC::now();
			$row = array_merge( $cols, array( 'data' => wp_json_encode( $data ), 'updated_at' => $now ) );
			if ( isset( $row['title'] ) ) {
				$row['title'] = mb_substr( (string) $row['title'], 0, 255 );
			}
			if ( $old ) {
				$wpdb->update( NWC_DB::t( 'biz_records' ), $row, array( 'id' => $old->id, 'workspace_id' => $ws ) );
				$rid = (int) $old->id;
			} else {
				$row = array_merge( $row, array( 'workspace_id' => $ws, 'module' => self::active_entities( NWC_Tenancy::$current )[ $ek ]['key'], 'entity' => $ek, 'created_by' => get_current_user_id(), 'created_at' => $now ) );
				if ( ! isset( $row['title'] ) ) {
					$row['title'] = '';
				}
				if ( ! $wpdb->insert( NWC_DB::t( 'biz_records' ), $row ) ) {
					return NWC::err( 'Could not save this.', 500, 'nwc_error' );
				}
				$rid = (int) $wpdb->insert_id;
			}
		} finally {
			NWC::unlock( 'rec:' . $ws );
		}
		$fresh = self::row( $rid );
		self::after_save( $fresh, $old );
		NWC::audit( 'record_' . ( $old ? 'updated' : 'created' ), array( 'entity' => $ek, 'entity_id' => $rid ) );
		return self::out( $fresh, $def );
	}

	/* ---------------------------- Entity rules ---------------------------- */

	private static function rec_get( $id ) {
		return $id ? self::row( (int) $id ) : null;
	}

	private static function pick( $k, $cols, $old ) {
		return array_key_exists( $k, $cols ) ? $cols[ $k ] : ( $old ? $old->$k : null );
	}

	public static function check_batch( $cols, $data, $old, $in ) {
		$s = $data['start_time'] ?? '';
		$e = $data['end_time'] ?? '';
		if ( '' !== $s && '' !== $e && $e <= $s ) {
			return NWC::err( 'The batch must end after it starts.' );
		}
		$sd = self::pick( 'on_date', $cols, $old );
		$ed = self::pick( 'end_date', $cols, $old );
		if ( $sd && $ed && $ed < $sd ) {
			return NWC::err( 'The end date is before the start date.' );
		}
		// Same studio or same instructor at overlapping times on a shared day is a clash.
		global $wpdb;
		$space = self::pick( 'space_id', $cols, $old );
		$staff = self::pick( 'staff_id', $cols, $old );
		if ( '' !== $s && '' !== $e && ( $space || $staff ) ) {
			$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . NWC_DB::t( 'biz_records' ) . " WHERE workspace_id = %d AND entity = 'batch' AND status = 'active' AND deleted_at IS NULL AND id <> %d", NWC_Tenancy::$current->id, $old ? $old->id : 0 ) ); // phpcs:ignore
			foreach ( $rows as $o ) {
				$od = json_decode( (string) $o->data, true );
				$shared = array_intersect( (array) ( $data['weekdays'] ?? array() ), (array) ( $od['weekdays'] ?? array() ) );
				if ( ! $shared || '' === (string) ( $od['start_time'] ?? '' ) || ! ( $s < $od['end_time'] && $e > $od['start_time'] ) ) {
					continue;
				}
				if ( $o->end_date && $sd && $o->end_date < $sd || $ed && $o->on_date && $ed < $o->on_date ) {
					continue;
				}
				if ( ( $space && (int) $o->space_id === (int) $space ) || ( $staff && (int) $o->staff_id === (int) $staff ) ) {
					if ( empty( $in['override'] ) || ! NWC_Tenancy::is_manager() ) {
						return NWC::err( 'This clashes with batch "' . $o->title . '" (' . $od['start_time'] . '–' . $od['end_time'] . ') in the same ' . ( $space && (int) $o->space_id === (int) $space ? 'studio' : 'instructor\'s schedule' ) . '.', 409, 'nwc_conflict', array( 'conflict' => (int) $o->id ) );
					}
					NWC::audit( 'schedule_override', array( 'entity' => 'batch', 'entity_id' => $old ? $old->id : 0, 'details' => array( 'clash_with' => (int) $o->id, 'reason' => sanitize_text_field( (string) ( $in['override_reason'] ?? '' ) ) ) ) );
				}
			}
		}
		return array( $cols, $data );
	}

	/** One pay or commission rule per person. */
	public static function check_one_rule( $cols, $data, $old, $in ) {
		global $wpdb;
		$staff = self::pick( 'staff_id', $cols, $old );
		$ent   = $old ? $old->entity : ( isset( $in['entity'] ) ? sanitize_key( (string) $in['entity'] ) : '' );
		if ( $staff && $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . NWC_DB::t( 'biz_records' ) . ' WHERE workspace_id = %d AND entity = %s AND staff_id = %d AND deleted_at IS NULL AND id <> %d', NWC_Tenancy::$current->id, $ent, $staff, $old ? $old->id : 0 ) ) ) { // phpcs:ignore
			return NWC::err( 'This person already has a rule. Edit that one instead.', 409, 'nwc_duplicate' );
		}
		$pct = self::pick( 'qty', $cols, $old );
		if ( null !== $pct && ( (float) $pct < 0 || (float) $pct > 100 ) ) {
			return NWC::err( 'The percentage must be between 0 and 100.' );
		}
		return array( $cols, $data );
	}

	public static function check_substitution( $cols, $data, $old, $in ) {
		$b = self::rec_get( self::pick( 'parent_id', $cols, $old ) );
		$d = self::pick( 'on_date', $cols, $old );
		if ( $b && $d ) {
			$day = NWC_Bookings::DAYS[ (int) gmdate( 'N', strtotime( $d . ' 12:00:00 UTC' ) ) - 1 ];
			$bd  = json_decode( (string) $b->data, true );
			if ( ! in_array( $day, (array) ( $bd['weekdays'] ?? array() ), true ) ) {
				return NWC::err( 'This batch does not meet on that day.' );
			}
		}
		return array( $cols, $data );
	}

	public static function check_enrolment( $cols, $data, $old, $in ) {
		global $wpdb;
		$batch = self::rec_get( self::pick( 'ref_id', $cols, $old ) );
		$stu   = self::pick( 'parent_id', $cols, $old );
		$dup   = $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . NWC_DB::t( 'biz_records' ) . " WHERE workspace_id = %d AND entity = 'enrolment' AND deleted_at IS NULL AND parent_id = %d AND ref_id = %d AND status <> 'ended' AND id <> %d", NWC_Tenancy::$current->id, $stu, $batch ? $batch->id : 0, $old ? $old->id : 0 ) ); // phpcs:ignore
		if ( $dup ) {
			return NWC::err( 'This student is already in that batch.', 409, 'nwc_duplicate' );
		}
		$status = self::pick( 'status', $cols, $old );
		if ( $batch && (float) $batch->qty > 0 && 'active' === $status ) {
			$n = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . NWC_DB::t( 'biz_records' ) . " WHERE workspace_id = %d AND entity = 'enrolment' AND deleted_at IS NULL AND ref_id = %d AND status = 'active' AND id <> %d", NWC_Tenancy::$current->id, $batch->id, $old ? $old->id : 0 ) ); // phpcs:ignore
			if ( $n >= (int) $batch->qty ) {
				$cols['status'] = 'waitlisted'; // Full: joins the waitlist instead.
			}
		}
		return array( $cols, $data );
	}

	public static function check_registration( $cols, $data, $old, $in ) {
		global $wpdb;
		$ev = self::rec_get( self::pick( 'parent_id', $cols, $old ) );
		if ( $ev && (float) $ev->qty > 0 && 'registered' === self::pick( 'status', $cols, $old ) ) {
			$n = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . NWC_DB::t( 'biz_records' ) . " WHERE workspace_id = %d AND entity = 'recital_entry' AND deleted_at IS NULL AND parent_id = %d AND status = 'registered' AND id <> %d", NWC_Tenancy::$current->id, $ev->id, $old ? $old->id : 0 ) ); // phpcs:ignore
			if ( $n >= (int) $ev->qty ) {
				$cols['status'] = 'waitlisted';
			}
		}
		return array( $cols, $data );
	}

	public static function check_fee( $cols, $data, $old, $in ) {
		$st = self::pick( 'status', $cols, $old );
		if ( in_array( $st, array( 'paid', 'received' ), true ) && ! self::pick( 'on_date', $cols, $old ) ) {
			$cols['on_date'] = NWC::today( NWC_Tenancy::$current->timezone );
		}
		if ( ! $old && empty( $cols['amount'] ) && ! empty( $cols['ref_id'] ) ) { // Amount from the fee plan.
			$plan = self::rec_get( $cols['ref_id'] );
			if ( $plan ) {
				$cols['amount'] = (float) $plan->amount;
			}
		}
		return array( $cols, $data );
	}

	public static function check_loan( $cols, $data, $old, $in ) {
		$ins = self::rec_get( self::pick( 'parent_id', $cols, $old ) );
		$st  = self::pick( 'status', $cols, $old );
		if ( $ins && 'out' === $st && ( ! $old || 'out' !== $old->status ) && 'available' !== $ins->status ) {
			return NWC::err( 'This instrument is not available (it is ' . str_replace( '_', ' ', $ins->status ) . ').', 409, 'nwc_conflict' );
		}
		return array( $cols, $data );
	}

	public static function check_reservation( $cols, $data, $old, $in ) {
		global $wpdb;
		$table = self::pick( 'ref_id', $cols, $old );
		$date  = self::pick( 'on_date', $cols, $old );
		$st    = self::pick( 'status', $cols, $old );
		if ( ! $table || ! $date || ! in_array( $st, array( 'booked', 'seated' ), true ) ) {
			return array( $cols, $data );
		}
		$t   = self::rec_get( $table );
		$qty = (int) self::pick( 'qty', $cols, $old );
		if ( $t && (float) $t->qty > 0 && $qty > (int) $t->qty ) {
			return NWC::err( 'Table ' . $t->title . ' seats ' . (int) $t->qty . '.', 409, 'nwc_conflict' );
		}
		$from = strtotime( $date . ' ' . $data['time'] . ':00 UTC' );
		$to   = $from + max( 15, (int) ( $data['duration'] ?? 90 ) ) * 60;
		foreach ( $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . NWC_DB::t( 'biz_records' ) . " WHERE workspace_id = %d AND entity = 'table_reservation' AND deleted_at IS NULL AND ref_id = %d AND on_date = %s AND status IN ('booked','seated') AND id <> %d", NWC_Tenancy::$current->id, $table, $date, $old ? $old->id : 0 ) ) as $o ) { // phpcs:ignore
			$od = json_decode( (string) $o->data, true );
			$os = strtotime( $date . ' ' . ( $od['time'] ?? '00:00' ) . ':00 UTC' );
			$oe = $os + max( 15, (int) ( $od['duration'] ?? 90 ) ) * 60;
			if ( $from < $oe && $to > $os ) {
				return NWC::err( 'That table is already reserved at ' . $od['time'] . ' for ' . $o->title . '. Choose another table or add to the waitlist.', 409, 'nwc_conflict' );
			}
		}
		return array( $cols, $data );
	}

	public static function stock_in( $cols, $data, $old, $in ) {
		return self::stock_move( $cols, $data, +1 );
	}

	public static function stock_out( $cols, $data, $old, $in ) {
		return self::stock_move( $cols, $data, -1 );
	}

	private static function stock_move( $cols, $data, $sign ) {
		global $wpdb;
		$ing = self::rec_get( $cols['parent_id'] ?? 0 );
		$q   = (float) ( $cols['qty'] ?? 0 );
		if ( ! $ing || $q <= 0 ) {
			return NWC::err( 'Choose an ingredient and a quantity above zero.' );
		}
		if ( ! isset( $cols['on_date'] ) || ! $cols['on_date'] ) {
			$cols['on_date'] = NWC::today( NWC_Tenancy::$current->timezone );
		}
		$wpdb->query( $wpdb->prepare( 'UPDATE ' . NWC_DB::t( 'biz_records' ) . ' SET qty = qty + %f, updated_at = %s WHERE id = %d', $sign * $q, NWC::now(), $ing->id ) ); // phpcs:ignore
		return array( $cols, $data );
	}

	public static function after_housekeeping( $cols, $data, $old, $in ) {
		return array( $cols, $data );
	}

	public static function check_guest_request( $cols, $data, $old, $in ) {
		$sid = self::pick( 'ref_id', $cols, $old );
		if ( $sid && ! NWC_Stays::row( (int) $sid ) ) {
			return NWC::err( 'That stay was not found.', 404, 'nwc_not_found' );
		}
		return array( $cols, $data );
	}

	public static function check_membership( $cols, $data, $old, $in ) {
		$plan = self::rec_get( self::pick( 'ref_id', $cols, $old ) );
		$tz   = NWC_Tenancy::$current->timezone;
		if ( ! self::pick( 'on_date', $cols, $old ) ) {
			$cols['on_date'] = NWC::today( $tz );
		}
		$plan_changed = $plan && ( ! $old || (int) $old->ref_id !== (int) $plan->id || ( array_key_exists( 'on_date', $cols ) && $cols['on_date'] !== $old->on_date ) );
		if ( $plan && ( ! self::pick( 'end_date', $cols, $old ) || ( $plan_changed && ! array_key_exists( 'end_date', $in ) ) ) ) {
			$cols['end_date'] = gmdate( 'Y-m-d', strtotime( $cols['on_date'] ?? $old->on_date ) + max( 1, (int) $plan->qty ) * DAY_IN_SECONDS - DAY_IN_SECONDS );
		}
		if ( $plan && ! $old && ! array_key_exists( 'amount', $in ) ) {
			$cols['amount'] = (float) $plan->amount;
		}
		$ed = self::pick( 'end_date', $cols, $old );
		$sd = self::pick( 'on_date', $cols, $old );
		if ( $ed && $sd && $ed < $sd ) {
			return NWC::err( 'The membership ends before it starts.' );
		}
		return array( $cols, $data );
	}

	public static function membership_state( $r ) {
		if ( 'active' !== $r->status ) {
			return $r->status;
		}
		$today = NWC::today( NWC_Tenancy::$current ? NWC_Tenancy::$current->timezone : 'UTC' );
		if ( $r->end_date && $r->end_date < $today ) {
			return 'expired';
		}
		if ( $r->end_date && $r->end_date <= gmdate( 'Y-m-d', strtotime( $today ) + 7 * DAY_IN_SECONDS ) ) {
			return 'expiring';
		}
		return 'active';
	}

	public static function check_package_sale( $cols, $data, $old, $in ) {
		$pk = self::rec_get( self::pick( 'ref_id', $cols, $old ) );
		if ( $pk && ! $old ) {
			$pd = json_decode( (string) $pk->data, true );
			if ( ! array_key_exists( 'qty', $in ) ) {
				$cols['qty'] = (float) $pk->qty;
			}
			if ( ! array_key_exists( 'end_date', $in ) && ! empty( $pd['valid_days'] ) ) {
				$cols['end_date'] = gmdate( 'Y-m-d', strtotime( NWC::today( NWC_Tenancy::$current->timezone ) ) + (int) $pd['valid_days'] * DAY_IN_SECONDS );
			}
		}
		return array( $cols, $data );
	}

	/** A property or unit moves out of "available" once; a second token on it is refused. */
	public static function check_inventory_status( $cols, $data, $old, $in ) {
		if ( $old && array_key_exists( 'status', $cols ) && $cols['status'] !== $old->status ) {
			$taken = array( 'token_received', 'sold', 'rented' );
			if ( in_array( $cols['status'], $taken, true ) && in_array( $old->status, $taken, true ) ) {
				return NWC::err( 'This is already ' . str_replace( '_', ' ', $old->status ) . '.', 409, 'nwc_conflict' );
			}
		}
		return array( $cols, $data );
	}

	/** A deal takes its property or unit: only one that is available or on hold. */
	public static function check_deal( $cols, $data, $old, $in ) {
		global $wpdb;
		foreach ( array( 'parent_id', 'ref_id' ) as $c ) {
			$target = self::rec_get( self::pick( $c, $cols, $old ) );
			if ( ! $target || ( $old && (int) $old->$c === (int) $target->id ) ) {
				continue;
			}
			if ( ! in_array( $target->status, array( 'available', 'on_hold' ), true ) ) {
				return NWC::err( ( $target->code ? $target->code : $target->title ) . ' is not available (it is ' . str_replace( '_', ' ', $target->status ) . ').', 409, 'nwc_conflict' );
			}
			$wpdb->update( NWC_DB::t( 'biz_records' ), array( 'status' => 'token_received', 'updated_at' => NWC::now() ), array( 'id' => $target->id ) );
		}
		if ( ! self::pick( 'parent_id', $cols, $old ) && ! self::pick( 'ref_id', $cols, $old ) ) {
			return NWC::err( 'Choose the property or the unit.' );
		}
		return array( $cols, $data );
	}

	/** Side effects that follow a save: instrument status, deal cancellation, housekeeping. */
	private static function after_save( $r, $old ) {
		global $wpdb;
		$t = NWC_DB::t( 'biz_records' );
		if ( 'loan' === $r->entity ) {
			$map = array( 'out' => 'lent', 'returned' => 'available', 'lost' => 'retired' );
			if ( isset( $map[ $r->status ] ) ) {
				$wpdb->update( $t, array( 'status' => $map[ $r->status ], 'updated_at' => NWC::now() ), array( 'id' => $r->parent_id, 'workspace_id' => $r->workspace_id ) );
			}
		}
		if ( 'deal' === $r->entity && 'cancelled' === $r->status && ( ! $old || 'cancelled' !== $old->status ) ) {
			foreach ( array( $r->parent_id, $r->ref_id ) as $tid ) {
				if ( $tid ) {
					$wpdb->query( $wpdb->prepare( "UPDATE $t SET status = 'available', updated_at = %s WHERE id = %d AND workspace_id = %d AND status = 'token_received'", NWC::now(), $tid, $r->workspace_id ) ); // phpcs:ignore
				}
			}
		}
		if ( 'housekeeping' === $r->entity && 'done' === $r->status && ( ! $old || 'done' !== $old->status ) ) {
			$wpdb->query( $wpdb->prepare( "UPDATE $t SET status = 'clean', updated_at = %s WHERE id = %d AND workspace_id = %d AND status = 'dirty'", NWC::now(), $r->parent_id, $r->workspace_id ) ); // phpcs:ignore
		}
		if ( 'enrolment' === $r->entity && $old && 'active' === $old->status && 'ended' === $r->status ) { // A seat opened: first on the waitlist moves in.
			$next = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $t WHERE workspace_id = %d AND entity = 'enrolment' AND ref_id = %d AND status = 'waitlisted' AND deleted_at IS NULL ORDER BY id ASC LIMIT 1", $r->workspace_id, $r->ref_id ) ); // phpcs:ignore
			if ( $next ) {
				$wpdb->update( $t, array( 'status' => 'active', 'updated_at' => NWC::now() ), array( 'id' => $next->id ) );
				NWC_Notify::workspace_owners( (int) $r->workspace_id, 'operations', 'A waitlisted student got a seat', '', '#/m/students?entity=enrolment&open=' . (int) $next->id, 'enrol:' . $next->id );
			}
		}
	}

	/* ============================ Dashboards ============================ */

	public static function rest_dashboard() {
		$w = NWC_Tenancy::$current;
		if ( NWC_Personal::is_personal( $w ) ) {
			return array( 'kpis' => array() );
		}
		$cfg = self::config( $w );
		return array( 'profile' => $cfg['profile'], 'label' => $cfg['label'], 'kpis' => self::kpis( $w, self::profiles()[ $cfg['profile'] ]['dashboard'] ), 'lists' => self::dashboard_lists( $w, $cfg['profile'] ) );
	}

	private static function cnt( $sql, $vals ) {
		global $wpdb;
		return (float) $wpdb->get_var( $wpdb->prepare( $sql, $vals ) ); // phpcs:ignore
	}

	/** @return array [{key,label,value,hint,link,format}] */
	public static function kpis( $w, $keys ) {
		$ws    = (int) $w->id;
		$tz    = new DateTimeZone( $w->timezone );
		$today = NWC::today( $w->timezone );
		$d0    = ( new DateTimeImmutable( $today . ' 00:00:00', $tz ) )->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );
		$d1    = ( new DateTimeImmutable( $today . ' 23:59:59', $tz ) )->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );
		$R     = NWC_DB::t( 'biz_records' );
		$O     = NWC_DB::t( 'pos_orders' );
		$S     = NWC_DB::t( 'stays' );
		$B     = NWC_DB::t( 'bookings' );
		$day   = NWC_Bookings::DAYS[ (int) ( new DateTimeImmutable( $today . ' 12:00:00' ) )->format( 'N' ) - 1 ];
		$rec   = function ( $ent, $extra = '', $vals = array() ) use ( $R, $ws ) {
			return self::cnt( "SELECT COUNT(*) FROM $R WHERE workspace_id = %d AND entity = %s AND deleted_at IS NULL $extra", array_merge( array( $ws, $ent ), $vals ) );
		};
		$defs = array(
			'students_active'  => array( 'Active students', function () use ( $rec ) { return $rec( 'student', "AND status = 'active'" ); }, '#/m/students' ),
			'batches_active'   => array( 'Active batches', function () use ( $rec ) { return $rec( 'batch', "AND status = 'active'" ); }, '#/m/academy' ),
			'sessions_today'   => array( 'Classes today', function () use ( $R, $ws, $day ) { return self::cnt( "SELECT COUNT(*) FROM $R WHERE workspace_id = %d AND entity = 'batch' AND status = 'active' AND deleted_at IS NULL AND data LIKE %s", array( $ws, '%"' . $day . '"%' ) ); }, '#/m/attendance' ),
			'attendance_30d'   => array( 'Attendance, last 30 days', function () use ( $ws, $today ) { $a = NWC_DB::t( 'attendance' ); $from = gmdate( 'Y-m-d', strtotime( $today ) - 30 * DAY_IN_SECONDS ); $all = self::cnt( "SELECT COUNT(*) FROM $a WHERE workspace_id = %d AND session_date >= %s", array( $ws, $from ) ); return $all ? round( 100 * self::cnt( "SELECT COUNT(*) FROM $a WHERE workspace_id = %d AND session_date >= %s AND status IN ('present','late')", array( $ws, $from ) ) / $all ) : 0; }, '#/m/attendance', 'pct' ),
			'fees_due'         => array( 'Fees due', function () use ( $R, $ws ) { return self::cnt( "SELECT COALESCE(SUM(amount),0) FROM $R WHERE workspace_id = %d AND entity = 'fee_due' AND status = 'due' AND deleted_at IS NULL", array( $ws ) ); }, '#/m/fees', 'money' ),
			'trials_upcoming'  => array( 'Trials booked', function () use ( $rec ) { return $rec( 'admission', "AND status = 'trial_booked'" ); }, '#/m/students?entity=admission' ),
			'instruments_lent' => array( 'Instruments lent out', function () use ( $rec ) { return $rec( 'instrument', "AND status = 'lent'" ); }, '#/m/lending' ),
			'bookings_today'   => array( 'Bookings today', function () use ( $B, $ws, $d0, $d1 ) { return self::cnt( "SELECT COUNT(*) FROM $B WHERE workspace_id = %d AND deleted_at IS NULL AND status IN ('pending','confirmed','completed') AND starts_at BETWEEN %s AND %s", array( $ws, $d0, $d1 ) ); }, '#/bookings/list' ),
			'sales_today'      => array( 'Sales today', function () use ( $O, $ws, $d0, $d1 ) { return self::cnt( "SELECT COALESCE(SUM(total),0) FROM $O WHERE workspace_id = %d AND status = 'paid' AND closed_at BETWEEN %s AND %s", array( $ws, $d0, $d1 ) ); }, '#/m/pos', 'money' ),
			'orders_open'      => array( 'Open orders', function () use ( $O, $ws ) { return self::cnt( "SELECT COUNT(*) FROM $O WHERE workspace_id = %d AND status IN ('open','billed')", array( $ws ) ); }, '#/m/pos' ),
			'takeaway_today'   => array( 'Takeaway and delivery today', function () use ( $O, $ws, $d0, $d1 ) { return self::cnt( "SELECT COUNT(*) FROM $O WHERE workspace_id = %d AND channel IN ('takeaway','delivery') AND status NOT IN ('cancelled','merged') AND created_at BETWEEN %s AND %s", array( $ws, $d0, $d1 ) ); }, '#/m/pos' ),
			'tables_occupied'  => array( 'Tables occupied', function () use ( $ws ) { return count( NWC_POS::busy_tables( $ws ) ); }, '#/m/pos', '', function () use ( $rec ) { return 'of ' . (int) $rec( 'dining_table', "AND status = 'active'" ); } ),
			'kots_pending'     => array( 'Tickets in the kitchen', function () use ( $ws ) { return self::cnt( 'SELECT COUNT(*) FROM ' . NWC_DB::t( 'pos_kots' ) . " WHERE workspace_id = %d AND status IN ('new','preparing')", array( $ws ) ); }, '#/m/kds' ),
			'reservations_today' => array( 'Reservations today', function () use ( $rec, $today ) { return $rec( 'table_reservation', "AND on_date = %s AND status IN ('booked','seated')", array( $today ) ); }, '#/m/tables' ),
			'low_stock'        => array( 'Low on stock', function () use ( $R, $ws ) { $n = 0; foreach ( $GLOBALS['wpdb']->get_results( $GLOBALS['wpdb']->prepare( "SELECT qty, data FROM $R WHERE workspace_id = %d AND entity IN ('ingredient','product') AND deleted_at IS NULL", $ws ) ) as $x ) { $d = json_decode( (string) $x->data, true ); if ( isset( $d['reorder'] ) && null !== $d['reorder'] && (float) $x->qty <= (float) $d['reorder'] ) { $n++; } } return $n; }, '#/m/inventory' ), // phpcs:ignore
			'best_sellers'     => array( 'Items sold, 7 days', function () use ( $ws, $today ) { $I = NWC_DB::t( 'pos_items' ); $O = NWC_DB::t( 'pos_orders' ); return self::cnt( "SELECT COALESCE(SUM(i.qty),0) FROM $I i JOIN $O o ON o.id = i.order_id WHERE o.workspace_id = %d AND o.status = 'paid' AND i.status <> 'void' AND o.closed_at >= %s", array( $ws, gmdate( 'Y-m-d', strtotime( $today ) - 6 * DAY_IN_SECONDS ) ) ); }, '#/reports' ),
			'repeat_guests'    => array( 'Repeat guests, 30 days', function () use ( $O, $ws, $today ) { return self::cnt( "SELECT COUNT(*) FROM (SELECT customer_id FROM $O WHERE workspace_id = %d AND status = 'paid' AND customer_id IS NOT NULL AND closed_at >= %s GROUP BY customer_id HAVING COUNT(*) > 1) x", array( $ws, gmdate( 'Y-m-d', strtotime( $today ) - 30 * DAY_IN_SECONDS ) ) ); }, '#/crm' ),
			'occupancy_today'  => array( 'Occupancy tonight', function () use ( $S, $ws, $today, $rec ) { $rooms = $rec( 'room', "AND status <> 'out_of_order'" ); return $rooms ? round( 100 * self::cnt( "SELECT COUNT(DISTINCT room_id) FROM $S WHERE workspace_id = %d AND status IN ('reserved','checked_in') AND check_in <= %s AND check_out > %s", array( $ws, $today, $today ) ) / $rooms ) : 0; }, '#/m/stays', 'pct' ),
			'arrivals_today'   => array( 'Arrivals today', function () use ( $S, $ws, $today ) { return self::cnt( "SELECT COUNT(*) FROM $S WHERE workspace_id = %d AND status = 'reserved' AND check_in = %s", array( $ws, $today ) ); }, '#/m/stays' ),
			'departures_today' => array( 'Departures today', function () use ( $S, $ws, $today ) { return self::cnt( "SELECT COUNT(*) FROM $S WHERE workspace_id = %d AND status = 'checked_in' AND check_out = %s", array( $ws, $today ) ); }, '#/m/stays' ),
			'in_house'         => array( 'Guests in house', function () use ( $S, $ws ) { return self::cnt( "SELECT COALESCE(SUM(adults + children),0) FROM $S WHERE workspace_id = %d AND status = 'checked_in'", array( $ws ) ); }, '#/m/stays' ),
			'rooms_dirty'      => array( 'Rooms to clean', function () use ( $rec ) { return $rec( 'room', "AND status = 'dirty'" ); }, '#/m/rooms' ),
			'requests_open'    => array( 'Open guest requests', function () use ( $rec ) { return $rec( 'guest_request', "AND status <> 'resolved'" ); }, '#/m/guest_services' ),
			'room_revenue_month' => array( 'Room revenue this month', function () use ( $S, $ws, $today ) { return self::cnt( "SELECT COALESCE(SUM(total),0) FROM $S WHERE workspace_id = %d AND status = 'checked_out' AND check_out >= %s", array( $ws, substr( $today, 0, 7 ) . '-01' ) ); }, '#/m/stays', 'money' ),
			'members_active'   => array( 'Active members', function () use ( $rec, $today ) { return $rec( 'membership', "AND status = 'active' AND (end_date IS NULL OR end_date >= %s)", array( $today ) ); }, '#/m/members' ),
			'members_expiring' => array( 'Renewals due in 7 days', function () use ( $rec, $today ) { return $rec( 'membership', "AND status = 'active' AND end_date BETWEEN %s AND %s", array( $today, gmdate( 'Y-m-d', strtotime( $today ) + 7 * DAY_IN_SECONDS ) ) ); }, '#/m/members' ),
			'member_dues'      => array( 'Membership fees pending', function () use ( $R, $ws ) { $n = 0; foreach ( $GLOBALS['wpdb']->get_results( $GLOBALS['wpdb']->prepare( "SELECT amount, data FROM $R WHERE workspace_id = %d AND entity = 'membership' AND status = 'active' AND deleted_at IS NULL", $ws ) ) as $x ) { $d = json_decode( (string) $x->data, true ); $n += max( 0, (float) $x->amount - (float) ( $d['paid'] ?? 0 ) ); } return round( $n, 2 ); }, '#/m/members', 'money' ), // phpcs:ignore
			'live_occupancy'   => array( 'In the gym now', function () use ( $ws, $d0 ) { return self::cnt( 'SELECT COUNT(*) FROM ' . NWC_DB::t( 'checkins' ) . ' WHERE workspace_id = %d AND out_at IS NULL AND in_at >= %s', array( $ws, $d0 ) ); }, '#/m/checkin' ),
			'checkins_today'   => array( 'Check-ins today', function () use ( $ws, $d0 ) { return self::cnt( 'SELECT COUNT(*) FROM ' . NWC_DB::t( 'checkins' ) . ' WHERE workspace_id = %d AND in_at >= %s', array( $ws, $d0 ) ); }, '#/m/checkin' ),
			'equipment_due'    => array( 'Equipment service due', function () use ( $rec, $today ) { return $rec( 'equipment', "AND status <> 'retired' AND end_date <= %s", array( $today ) ); }, '#/m/equipment' ),
			'walkins_today'    => array( 'Walk-ins today', function () use ( $rec, $today ) { return $rec( 'walkin', 'AND on_date = %s', array( $today ) ); }, '#/m/salon_ops' ),
			'revenue_today'    => array( 'Received today', function () use ( $ws, $today ) { return self::cnt( 'SELECT COALESCE(SUM(amount),0) FROM ' . NWC_DB::t( 'biz_payments' ) . ' WHERE workspace_id = %d AND voided_at IS NULL AND paid_on = %s', array( $ws, $today ) ); }, '#/invoices', 'money' ),
			'top_staff'        => array( 'Appointments this week', function () use ( $B, $ws, $today ) { return self::cnt( "SELECT COUNT(*) FROM $B WHERE workspace_id = %d AND deleted_at IS NULL AND status IN ('confirmed','completed') AND starts_at >= %s", array( $ws, gmdate( 'Y-m-d', strtotime( $today ) - 6 * DAY_IN_SECONDS ) ) ); }, '#/bookings/list' ),
			'packages_active'  => array( 'Prepaid packages active', function () use ( $rec ) { return $rec( 'package_sale', "AND status = 'active'" ); }, '#/m/packages' ),
			'properties_available' => array( 'Properties available', function () use ( $rec ) { return $rec( 'property', "AND status = 'available'" ); }, '#/m/properties' ),
			'leads_new'        => array( 'New enquiries', function () use ( $ws ) { return self::cnt( 'SELECT COUNT(*) FROM ' . NWC_DB::t( 'leads' ) . " WHERE workspace_id = %d AND deleted_at IS NULL AND stage = 'new'", array( $ws ) ); }, '#/crm' ),
			'visits_upcoming'  => array( 'Site visits ahead', function () use ( $rec, $today ) { return $rec( 'site_visit', "AND status = 'scheduled' AND on_date >= %s", array( $today ) ); }, '#/m/pipeline' ),
			'deals_open'       => array( 'Offers in negotiation', function () use ( $rec ) { return $rec( 'offer_neg', "AND status IN ('made','countered')" ); }, '#/m/pipeline?entity=offer_neg' ),
			'tokens_month'     => array( 'Tokens this month', function () use ( $R, $ws, $today ) { return self::cnt( "SELECT COALESCE(SUM(amount),0) FROM $R WHERE workspace_id = %d AND entity = 'deal' AND status <> 'cancelled' AND deleted_at IS NULL AND on_date >= %s", array( $ws, substr( $today, 0, 7 ) . '-01' ) ); }, '#/m/deals', 'money' ),
			'instalments_due'  => array( 'Instalments due in 30 days', function () use ( $rec, $today ) { return $rec( 'instalment', "AND status = 'due' AND end_date <= %s", array( gmdate( 'Y-m-d', strtotime( $today ) + 30 * DAY_IN_SECONDS ) ) ); }, '#/m/deals?entity=instalment' ),
			'units_available'  => array( 'Units available', function () use ( $rec ) { return $rec( 'unit', "AND status = 'available'" ); }, '#/m/developer?entity=unit' ),
			'custom_open'      => array( 'Open records', function () use ( $rec ) { return $rec( 'custom_record', "AND status <> 'done'" ); }, '#/m/custom' ),
		);
		$out = array();
		foreach ( $keys as $k ) {
			if ( ! isset( $defs[ $k ] ) ) {
				continue;
			}
			$d     = $defs[ $k ];
			$out[] = array( 'key' => $k, 'label' => $d[0], 'value' => call_user_func( $d[1] ), 'link' => $d[2], 'format' => $d[3] ?? '', 'hint' => isset( $d[4] ) ? call_user_func( $d[4] ) : '' );
		}
		return $out;
	}

	/** A short "needs attention" list per business type. */
	private static function dashboard_lists( $w, $pk ) {
		global $wpdb;
		$ws    = (int) $w->id;
		$today = NWC::today( $w->timezone );
		$R     = NWC_DB::t( 'biz_records' );
		$pick  = function ( $sql, $vals ) use ( $wpdb ) {
			return $wpdb->get_results( $wpdb->prepare( $sql, $vals ) ); // phpcs:ignore
		};
		switch ( $pk ) {
			case 'restaurant':
			case 'cafe':
				return array( 'title' => 'Open orders', 'link' => '#/m/pos', 'items' => array_map( function ( $o ) {
					return array( 'title' => $o->order_no . ' · ' . ucwords( str_replace( '_', ' ', $o->channel ) ), 'sub' => NWC_Invoices::money( $o->total ) . ' · ' . $o->status, 'link' => '#/m/pos?order=' . (int) $o->id );
				}, $pick( 'SELECT * FROM ' . NWC_DB::t( 'pos_orders' ) . " WHERE workspace_id = %d AND status IN ('open','billed') ORDER BY id DESC LIMIT 8", array( $ws ) ) ) );
			case 'hotel':
				return array( 'title' => 'Arrivals and departures today', 'link' => '#/m/stays', 'items' => array_map( function ( $s ) use ( $today ) {
					return array( 'title' => $s->guest_name, 'sub' => ( $s->check_in === $today ? 'Arriving' : 'Leaving' ) . ' · ' . $s->status, 'link' => '#/m/stays?open=' . (int) $s->id );
				}, $pick( 'SELECT * FROM ' . NWC_DB::t( 'stays' ) . " WHERE workspace_id = %d AND ((status = 'reserved' AND check_in = %s) OR (status = 'checked_in' AND check_out = %s)) ORDER BY id ASC LIMIT 10", array( $ws, $today, $today ) ) ) );
			case 'gym':
				return array( 'title' => 'Renewals due', 'link' => '#/m/members', 'items' => array_map( function ( $m ) {
					return array( 'title' => $m->title, 'sub' => 'Ends ' . $m->end_date, 'link' => '#/m/members?open=' . (int) $m->id );
				}, $pick( "SELECT * FROM $R WHERE workspace_id = %d AND entity = 'membership' AND status = 'active' AND deleted_at IS NULL AND end_date <= %s ORDER BY end_date ASC LIMIT 8", array( $ws, gmdate( 'Y-m-d', strtotime( $today ) + 7 * DAY_IN_SECONDS ) ) ) ) );
			case 'dance_studio':
			case 'music_academy':
				return array( 'title' => 'Fees overdue', 'link' => '#/m/fees?entity=fee_due', 'items' => array_map( function ( $f ) {
					return array( 'title' => $f->title ? $f->title : 'Fee', 'sub' => NWC_Invoices::money( $f->amount ) . ' · due ' . $f->end_date, 'link' => '#/m/fees?entity=fee_due&open=' . (int) $f->id );
				}, $pick( "SELECT * FROM $R WHERE workspace_id = %d AND entity = 'fee_due' AND status = 'due' AND deleted_at IS NULL AND end_date < %s ORDER BY end_date ASC LIMIT 8", array( $ws, $today ) ) ) );
			case 'real_estate':
				return array( 'title' => 'Site visits ahead', 'link' => '#/m/pipeline', 'items' => array_map( function ( $v ) {
					return array( 'title' => 'Visit on ' . $v->on_date, 'sub' => (string) ( json_decode( (string) $v->data, true )['time'] ?? '' ), 'link' => '#/m/pipeline?open=' . (int) $v->id );
				}, $pick( "SELECT * FROM $R WHERE workspace_id = %d AND entity = 'site_visit' AND status = 'scheduled' AND deleted_at IS NULL AND on_date >= %s ORDER BY on_date ASC LIMIT 8", array( $ws, $today ) ) ) );
		}
		return array( 'title' => '', 'items' => array() );
	}

	/** Daily: membership and fee reminders, once per record and day. */
	public static function daily() {
		global $wpdb;
		$R = NWC_DB::t( 'biz_records' );
		foreach ( $wpdb->get_results( "SELECT id, workspace_id, title, end_date, entity FROM $R WHERE deleted_at IS NULL AND ((entity = 'membership' AND status = 'active') OR (entity IN ('fee_due','instalment') AND status = 'due')) AND end_date IS NOT NULL LIMIT 2000" ) as $r ) { // phpcs:ignore
			$w = NWC_Tenancy::workspace( $r->workspace_id );
			if ( ! $w || 'active' !== $w->status ) {
				continue;
			}
			$today = NWC::today( $w->timezone );
			$days  = (int) floor( ( strtotime( $r->end_date ) - strtotime( $today ) ) / DAY_IN_SECONDS );
			if ( 'membership' === $r->entity && in_array( $days, array( 7, 3, 0 ), true ) ) {
				NWC_Notify::workspace_owners( (int) $r->workspace_id, 'operations', 'Membership renewal due: ' . $r->title, 'Ends on ' . $r->end_date, '#/m/members?open=' . (int) $r->id, 'renew:' . $r->id . ':' . $days );
			} elseif ( 'membership' !== $r->entity && in_array( $days, array( 3, 0, -3 ), true ) ) {
				NWC_Notify::workspace_owners( (int) $r->workspace_id, 'operations', ( $days < 0 ? 'Overdue: ' : 'Due soon: ' ) . ( $r->title ? $r->title : 'payment' ), 'Due on ' . $r->end_date, '#/m/' . ( 'instalment' === $r->entity ? 'deals?entity=instalment' : 'fees?entity=fee_due' ) . '&open=' . (int) $r->id, 'due:' . $r->id . ':' . $days );
			}
		}
	}
}
