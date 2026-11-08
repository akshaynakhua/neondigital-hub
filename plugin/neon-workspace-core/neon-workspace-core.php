<?php
/**
 * Plugin Name:       Neon Workspace Core
 * Description:       Business logic and data for Neon Workspace: multi-tenant workspaces, calendars, projects, CRM, bookings, invoices, subscriptions and Agency Connect. All customer data lives here, not in the theme.
 * Version:           1.7.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            NeonDigital
 * License:           GPL-2.0-or-later
 * Text Domain:       nwc
 */

defined( 'ABSPATH' ) || exit;

define( 'NWC_VERSION', '1.7.0' );
define( 'NWC_FILE', __FILE__ );
define( 'NWC_DIR', plugin_dir_path( __FILE__ ) );
define( 'NWC_URL', plugin_dir_url( __FILE__ ) );

foreach ( array( 'db', 'core', 'resources', 'calendar', 'bookings', 'invoices', 'billing', 'workdesk', 'connect', 'rest', 'public', 'admin', 'signup', 'social', 'forms', 'daily', 'portal', 'finance', 'drive', 'retention', 'legal', 'personal', 'catalog', 'industry', 'pos', 'ops', 'convert', 'profile' ) as $nwc_mod ) {
	require_once NWC_DIR . 'includes/class-nwc-' . $nwc_mod . '.php';
}
require_once NWC_DIR . 'includes/lib/class-nwc-pdf.php';

/** Background work: every 5 minutes via WP-Cron, or a real server cron calling wp-cron.php. */
class NWC_Cron {

	const HOOK = 'nwc_tick';

	public static function init() {
		add_filter( 'cron_schedules', function ( $s ) {
			$s['nwc_5min'] = array( 'interval' => 300, 'display' => 'Every 5 minutes' );
			return $s;
		} );
		add_action( self::HOOK, array( __CLASS__, 'tick' ) );
		if ( ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_event( time() + 60, 'nwc_5min', self::HOOK );
		}
	}

	public static function tick() {
		global $wpdb;
		$today = gmdate( 'Y-m-d' );
		if ( get_option( 'nwc_daily_on' ) !== $today ) {
			update_option( 'nwc_daily_on', $today, false );
			NWC_Billing::daily( $today );
			NWC_Connect::daily( $today );
			NWC_Retention::daily();
			NWC_Daily::purge_old_activity();
			NWC_Industry::daily();
			NWC_Convert::purge_due();
			$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . NWC_DB::t( 'api_log' ) . ' WHERE created_at < %s', gmdate( 'Y-m-d H:i:s', time() - 90 * DAY_IN_SECONDS ) ) ); // phpcs:ignore
		}
		NWC_Notify::reminders();
		NWC_Bookings::expire_holds();
		NWC_Sync::process();
		NWC_Connect::push_catalogue();
		$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . NWC_DB::t( 'nonces' ) . ' WHERE expires_at < %d', time() ) ); // phpcs:ignore
	}
}

register_activation_hook( __FILE__, function () {
	NWC_DB::install();
	NWC_Public::rewrites();
	flush_rewrite_rules( false );
} );

// Deactivating only stops the schedule. Data is untouched.
register_deactivation_hook( __FILE__, function () {
	wp_clear_scheduled_hook( NWC_Cron::HOOK );
	flush_rewrite_rules( false );
} );

add_action( 'plugins_loaded', function () {
	NWC_DB::maybe_upgrade();
	NWC_REST::init();
	NWC_Social::init();
	NWC_Public::init();
	NWC_Admin::init();
	NWC_Cron::init();
	NWC_Legal::init();
} );
