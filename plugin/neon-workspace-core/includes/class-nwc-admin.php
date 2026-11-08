<?php
/**
 * Platform administrator screens in wp-admin: settings, plans and add-ons, workspaces (manual
 * extensions, offline payments). Every save needs manage_options and a nonce.
 * Secrets are write-only here: stored sealed and never printed back.
 */

defined( 'ABSPATH' ) || exit;

class NWC_Admin {

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_post_nwc_settings', array( __CLASS__, 'save_settings' ) );
		add_action( 'admin_post_nwc_plan', array( __CLASS__, 'save_plan' ) );
		add_action( 'admin_post_nwc_addon', array( __CLASS__, 'save_addon' ) );
		add_action( 'admin_post_nwc_extend', array( __CLASS__, 'extend' ) );
		add_action( 'admin_post_nwc_record', array( __CLASS__, 'record' ) );
		add_action( 'admin_post_nwc_grant', array( __CLASS__, 'grant' ) );
		add_action( 'admin_post_nwc_social', array( __CLASS__, 'save_social' ) );
		add_action( 'admin_post_nwc_social_test', array( __CLASS__, 'test_social' ) );
		add_action( 'admin_post_nwc_templates', array( __CLASS__, 'save_templates' ) );
		add_action( 'admin_post_nwc_rz_test', array( __CLASS__, 'test_razorpay' ) );
		add_action( 'admin_post_nwc_refund', array( __CLASS__, 'refund' ) );
		add_action( 'admin_post_nwc_archive', array( __CLASS__, 'download_archive' ) );
	}

	public static function menu() {
		add_menu_page( 'Neon Workspace', 'Neon Workspace', 'manage_options', 'nwc', array( __CLASS__, 'page_settings' ), 'dashicons-screenoptions', 3 );
		add_submenu_page( 'nwc', 'Settings', 'Settings', 'manage_options', 'nwc', array( __CLASS__, 'page_settings' ) );
		add_submenu_page( 'nwc', 'Plans & Add-ons', 'Plans & Add-ons', 'manage_options', 'nwc-plans', array( __CLASS__, 'page_plans' ) );
		add_submenu_page( 'nwc', 'Workspaces', 'Workspaces', 'manage_options', 'nwc-workspaces', array( __CLASS__, 'page_workspaces' ) );
		add_submenu_page( 'nwc', 'Authentication & Security', 'Authentication & Security', 'manage_options', 'nwc-auth', array( __CLASS__, 'page_auth' ) );
		add_submenu_page( 'nwc', 'Payments', 'Payments', 'manage_options', 'nwc-payments', array( __CLASS__, 'page_payments' ) );
		add_submenu_page( 'nwc', 'Email templates', 'Email templates', 'manage_options', 'nwc-templates', array( __CLASS__, 'page_templates' ) );
		add_submenu_page( 'nwc', 'Connection diagnostics', 'Connection diagnostics', 'manage_options', 'nwc-diagnostics', array( __CLASS__, 'page_diagnostics' ) );
		add_submenu_page( 'nwc', 'Deleted Workspaces', 'Deleted Workspaces', 'manage_options', 'nwc-deletions', array( __CLASS__, 'page_deletions' ) );
	}

	private static function check( $action ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Not allowed.', 403 );
		}
		check_admin_referer( $action );
	}

	private static function back( $page, $msg ) {
		wp_safe_redirect( add_query_arg( array( 'page' => $page, 'nwc_msg' => rawurlencode( $msg ) ), admin_url( 'admin.php' ) ) );
		exit;
	}

	private static function notice() {
		if ( isset( $_GET['nwc_msg'] ) ) { // phpcs:ignore
			echo '<div class="notice notice-info"><p>' . esc_html( sanitize_text_field( wp_unslash( $_GET['nwc_msg'] ) ) ) . '</p></div>'; // phpcs:ignore
		}
	}

	private static function post( $k, $d = '' ) {
		return isset( $_POST[ $k ] ) ? sanitize_text_field( wp_unslash( $_POST[ $k ] ) ) : $d; // phpcs:ignore
	}

	/** Failed calls to and from WorkDesk. Holds routes, statuses and messages, never keys or secrets. */
	/** Runs one read-only check of each thing NeonDigital Connect depends on. */
	private static function run_connection_test() {
		$rows = NWC_WorkDesk::diagnose();
		echo '<h2>Connection test</h2><table class="widefat striped" style="margin-bottom:18px"><thead><tr><th>Check</th><th>Result</th><th>Detail</th></tr></thead><tbody>';
		foreach ( $rows as $r ) {
			echo '<tr><td>' . esc_html( $r['check'] ) . '</td><td>' . ( $r['ok'] ? 'OK' : '<b>Problem</b>' ) . '</td><td>' . esc_html( $r['detail'] ) . '</td></tr>';
		}
		echo '</tbody></table>';
	}

	/**
	 * Phase 5: deletion records and the restricted financial archives. Platform administrators only;
	 * opening an archive is audited. Archives are destroyed automatically after their retention date.
	 */
	public static function page_deletions() {
		global $wpdb;
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Not allowed.' );
		}
		$rows = $wpdb->get_results( 'SELECT * FROM ' . NWC_DB::t( 'deletions' ) . ' ORDER BY id DESC LIMIT 200' ); // phpcs:ignore
		echo '<div class="wrap"><h1>Deleted Workspaces</h1><p>Each row is a Workspace that was really deleted: its operational data and files are gone. Financial and tax records were moved into an encrypted restricted archive, kept until the date shown and then destroyed automatically. Open an archive only for a legal, tax or audit request; every opening is recorded.</p>';
		echo '<table class="widefat striped"><thead><tr><th>Deleted</th><th>Workspace</th><th>Type</th><th>By</th><th>Removed</th><th>Archive kept until</th><th></th></tr></thead><tbody>';
		foreach ( $rows as $d ) {
			$c   = json_decode( (string) $d->counts, true );
			$sum = is_array( $c ) ? array_sum( (array) ( $c['deleted'] ?? array() ) ) : 0;
			echo '<tr><td>' . esc_html( $d->created_at ) . ' UTC</td><td><b>' . esc_html( $d->workspace_name ) . '</b><br><code>' . esc_html( $d->workspace_uid ) . '</code></td><td>' . esc_html( $d->account_type ) . '</td><td>' . esc_html( 'workdesk' === $d->via ? 'WorkDesk: ' . $d->actor_name : ( 'owner_window' === $d->via ? 'Owner (after 14-day window)' : $d->via ) ) . '</td><td>' . (int) $sum . ' records, ' . (int) ( $c['files'] ?? 0 ) . ' files, ' . (int) ( $c['members'] ?? 0 ) . ' members</td><td>' . esc_html( $d->archive_file ? ( $d->archive_purged_at ? 'destroyed ' . $d->archive_purged_at : (string) $d->retain_until ) : 'no financial records' ) . '</td><td>';
			if ( $d->archive_file && ! $d->archive_purged_at ) {
				echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="nwc_archive"><input type="hidden" name="id" value="' . (int) $d->id . '">';
				wp_nonce_field( 'nwc_archive_' . (int) $d->id );
				echo '<button class="button">Download archive (audited)</button></form>';
			}
			echo '</td></tr>';
		}
		if ( ! $rows ) {
			echo '<tr><td colspan="7">No Workspace has been deleted.</td></tr>';
		}
		echo '</tbody></table></div>';
	}

	public static function download_archive() {
		$id = absint( $_POST['id'] ?? 0 ); // phpcs:ignore
		if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'nwc_archive_' . $id ) ) {
			wp_die( 'Not allowed.' );
		}
		$json = NWC_Convert::read_archive( $id );
		if ( null === $json ) {
			wp_die( 'This archive is not available (destroyed, missing or failed its integrity check).' );
		}
		nocache_headers();
		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="workspace-archive-' . $id . '.json"' );
		echo $json; // phpcs:ignore
		exit;
	}

	public static function page_diagnostics() {
		global $wpdb;
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Not allowed.', 403 );
		}
		$rows = $wpdb->get_results( 'SELECT workspace_id, direction, method, route, status, error, created_at FROM ' . NWC_DB::t( 'api_log' ) . " WHERE status = 0 OR status >= 400 OR error <> '' ORDER BY id DESC LIMIT 100" ); // phpcs:ignore
		echo '<div class="wrap"><h1>Connection diagnostics</h1><p>The latest failed calls between Workspace and WorkDesk. Customers only see that NeonDigital Connect is temporarily unavailable. Times are UTC, and records are kept for 90 days.</p>';
		echo '<form method="post" style="margin:0 0 14px">';
		wp_nonce_field( 'nwc_test_connection' );
		echo '<button class="button button-primary" name="nwc_test" value="1">Test Connection</button></form>';
		if ( ! empty( $_POST['nwc_test'] ) && check_admin_referer( 'nwc_test_connection' ) ) { // phpcs:ignore
			self::run_connection_test();
		}
		echo '<table class="widefat striped"><thead><tr><th>When</th><th>Workspace</th><th>Direction</th><th>Call</th><th>Status</th><th>Detail</th></tr></thead><tbody>';
		foreach ( $rows as $x ) {
			echo '<tr><td>' . esc_html( $x->created_at ) . '</td><td>' . esc_html( (string) $x->workspace_id ) . '</td><td>' . esc_html( $x->direction ) . '</td><td>' . esc_html( $x->method . ' ' . $x->route ) . '</td><td>' . esc_html( (string) $x->status ) . '</td><td>' . esc_html( $x->error ) . '</td></tr>';
		}
		if ( ! $rows ) {
			echo '<tr><td colspan="6">No failed calls recorded.</td></tr>';
		}
		echo '</tbody></table></div>';
	}

	public static function page_settings() {
		$s = NWC::settings();
		echo '<div class="wrap"><h1>Neon Workspace settings</h1>';
		self::notice();
		list( $wd_key, $wd_secret ) = NWC_WorkDesk::site_credentials();
		$secret_state = function ( $v ) {
			return '' !== (string) $v ? 'Saved. Leave empty to keep it.' : 'Not set.';
		};
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="nwc_settings"><?php wp_nonce_field( 'nwc_settings' ); ?>
			<h2>WorkDesk connection</h2>
			<p>Create the site key in WorkDesk: Workspace Management → Agency Connect. Prefer the wp-config.php constants in the setup guide.</p>
			<table class="form-table">
				<tr><th>WorkDesk address</th><td><input class="regular-text" type="url" name="workdesk_url" value="<?php echo esc_attr( NWC_WorkDesk::base_url() ); ?>" <?php disabled( defined( 'NWC_WORKDESK_URL' ) ); ?>></td></tr>
				<tr><th>Site API key</th><td><input class="regular-text" name="workdesk_key" value="<?php echo esc_attr( $wd_key ); ?>" <?php disabled( defined( 'NWC_WORKDESK_KEY' ) ); ?>></td></tr>
				<tr><th>Site API secret</th><td><input class="regular-text" type="password" autocomplete="new-password" name="workdesk_secret" <?php disabled( defined( 'NWC_WORKDESK_SECRET' ) ); ?>><p class="description"><?php echo esc_html( $secret_state( $wd_secret ) ); ?></p></td></tr>
			</table>
			<h2>Subscriptions</h2>
			<table class="form-table">
				<tr><th>Default trial length (days)</th><td><input type="number" min="0" name="trial_days" value="<?php echo esc_attr( $s['trial_days'] ); ?>"><p class="description">Used by a trial plan with no length of its own.</p></td></tr>
				<tr><th>Trial limit</th><td><label><input type="checkbox" name="trial_once_per_user" <?php checked( $s['trial_once_per_user'] ); ?>> One free trial per person</label></td></tr>
				<tr><th>Terms and Privacy</th><td><a href="<?php echo esc_url( admin_url( 'admin.php?page=nwc-legal' ) ); ?>">Edit them in Legal Pages</a>. Versions are recorded with each acceptance.</td></tr>
				<tr><th>Activity history kept (days)</th><td><input type="number" min="30" name="activity_retention_days" value="<?php echo esc_attr( $s['activity_retention_days'] ); ?>"><p class="description">User-facing activity only. Security, billing and admin records are not removed by this.</p></td></tr>
				<tr><th>Default grace period (days)</th><td><input type="number" min="0" name="default_grace_days" value="<?php echo esc_attr( $s['default_grace_days'] ); ?>"></td></tr>
				<tr><th>Renewal notice (days before expiry)</th><td><input type="number" min="1" name="renewal_notice_days" value="<?php echo esc_attr( $s['renewal_notice_days'] ); ?>"></td></tr>
				<tr><th>Upload limit (MB)</th><td><input type="number" min="1" max="100" name="upload_max_mb" value="<?php echo esc_attr( $s['upload_max_mb'] ); ?>"></td></tr>
				<tr><th>Send email</th><td><label><input type="checkbox" name="email_enabled" <?php checked( $s['email_enabled'] ); ?>> On. Configure outgoing mail for this site first. While off, no email leaves this site.</label></td></tr>
			</table>
			<h2>Payments</h2>
			<p>Manual payments work without Razorpay. Enter keys from your Razorpay dashboard for test mode, then use <a href="<?php echo esc_url( admin_url( 'admin.php?page=nwc-payments' ) ); ?>">Payments</a> to test the connection. Webhook URL: <code><?php echo esc_html( rest_url( NWC_REST::NS . '/webhooks/razorpay' ) ); ?></code></p>
			<table class="form-table">
				<tr><th>Mode</th><td><select name="gateway_mode">
					<option value="manual" <?php selected( $s['gateway_mode'], 'manual' ); ?>>Manual / offline</option>
					<option value="simulated" <?php selected( $s['gateway_mode'], 'simulated' ); ?>>Simulated (no money moves, never counted as revenue)</option>
					<option value="test" <?php selected( $s['gateway_mode'], 'test' ); ?>>Razorpay test mode</option>
					<option value="live" <?php selected( $s['gateway_mode'], 'live' ); ?>>Razorpay live mode</option>
				</select></td></tr>
				<tr><th>Test Key ID</th><td><input class="regular-text" name="rz_test_key" value="<?php echo esc_attr( $s['rz_test_key'] ); ?>"></td></tr>
				<tr><th>Test Key Secret</th><td><input class="regular-text" type="password" autocomplete="new-password" name="rz_test_secret"><p class="description"><?php echo esc_html( $secret_state( $s['rz_test_secret'] ) ); ?></p></td></tr>
				<tr><th>Live Key ID</th><td><input class="regular-text" name="rz_live_key" value="<?php echo esc_attr( $s['rz_live_key'] ); ?>"></td></tr>
				<tr><th>Live Key Secret</th><td><input class="regular-text" type="password" autocomplete="new-password" name="rz_live_secret"><p class="description"><?php echo esc_html( $secret_state( $s['rz_live_secret'] ) ); ?></p></td></tr>
				<tr><th>Webhook secret</th><td><input class="regular-text" type="password" autocomplete="new-password" name="rz_webhook_secret"><p class="description"><?php echo esc_html( $secret_state( $s['rz_webhook_secret'] ) ); ?> Webhook address: <code><?php echo esc_html( rest_url( NWC_REST::NS . '/webhooks/razorpay' ) ); ?></code></p></td></tr>
				<tr><th>Live mode</th><td><label><input type="checkbox" name="live_confirmed" <?php checked( $s['live_confirmed'] ); ?>> I confirm real customers can be charged. Live mode stays off without this.</label></td></tr>
			</table>
			<?php submit_button(); ?>
		</form></div>
		<?php
	}

	public static function save_settings() {
		self::check( 'nwc_settings' );
		$s = NWC::settings();
		$s['workdesk_url'] = untrailingslashit( esc_url_raw( self::post( 'workdesk_url', $s['workdesk_url'] ), array( 'http', 'https' ) ) );
		$s['workdesk_key'] = self::post( 'workdesk_key', $s['workdesk_key'] );
		foreach ( array( 'trial_days', 'default_grace_days', 'renewal_notice_days', 'upload_max_mb' ) as $k ) {
			$s[ $k ] = absint( self::post( $k, $s[ $k ] ) );
		}
		$s['activity_retention_days'] = max( 30, $s['activity_retention_days'] ?? 365 );
		$s['activity_retention_days'] = max( 30, absint( self::post( 'activity_retention_days', $s['activity_retention_days'] ) ) );
		$s['trial_once_per_user'] = isset( $_POST['trial_once_per_user'] ); // phpcs:ignore
		$s['terms_url']     = esc_url_raw( self::post( 'terms_url' ), array( 'https', 'http' ) );
		$s['privacy_url']   = esc_url_raw( self::post( 'privacy_url' ), array( 'https', 'http' ) );
		$s['terms_version'] = mb_substr( self::post( 'terms_version', '1' ), 0, 20 );
		$s['email_enabled']  = isset( $_POST['email_enabled'] ); // phpcs:ignore
		$s['live_confirmed'] = isset( $_POST['live_confirmed'] ); // phpcs:ignore
		$mode = self::post( 'gateway_mode', 'manual' );
		$s['gateway_mode'] = in_array( $mode, array( 'manual', 'simulated', 'test', 'live' ), true ) ? $mode : 'manual';
		$s['rz_test_key']  = self::post( 'rz_test_key' );
		$s['rz_live_key']  = self::post( 'rz_live_key' );
		foreach ( array( 'workdesk_secret', 'rz_test_secret', 'rz_live_secret', 'rz_webhook_secret' ) as $k ) {
			$v = isset( $_POST[ $k ] ) ? trim( (string) wp_unslash( $_POST[ $k ] ) ) : ''; // phpcs:ignore
			if ( '' !== $v ) {
				$s[ $k ] = NWC::seal( $v );
			}
		}
		$old = NWC::settings();
		NWC::save_settings( $s );
		NWC::audit( 'platform_settings_saved', array( 'workspace_id' => null, 'kind' => 'admin', 'details' => array( 'gateway_mode' => $s['gateway_mode'], 'from' => $old['gateway_mode'] ) ) );
		self::back( 'nwc', 'Saved.' );
	}

	public static function page_plans() {
		global $wpdb;
		echo '<div class="wrap"><h1>Plans &amp; Add-ons</h1>';
		self::notice();
		echo '<p>Prices are yours to set. A plan without a price cannot be bought. Features come from this list, not from the code.</p>';
		$plans = $wpdb->get_results( 'SELECT * FROM ' . NWC_DB::t( 'plans' ) . ' ORDER BY sort ASC, id ASC' ); // phpcs:ignore
		$plans[] = (object) array( 'id' => 0, 'code' => '', 'name' => '', 'description' => '', 'price' => null, 'billing_period' => 'month', 'features' => '{}', 'grace_days' => NWC::settings()['default_grace_days'], 'is_public' => 1, 'is_active' => 1, 'sort' => 0 );
		foreach ( $plans as $p ) {
			$feat = (array) json_decode( (string) $p->features, true );
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="background:#fff;padding:12px 16px;margin:12px 0;border:1px solid #ddd"><h2 style="margin-top:0">' . ( $p->id ? esc_html( $p->name ) : 'Add a plan' ) . '</h2><input type="hidden" name="action" value="nwc_plan"><input type="hidden" name="id" value="' . (int) $p->id . '">';
			wp_nonce_field( 'nwc_plan' );
			echo '<p><label><input type="checkbox" name="is_trial" ' . checked( ! empty( $p->is_trial ), true, false ) . '> This is a free trial plan</label> <label>Trial days <input type="number" min="0" style="width:70px" name="trial_days" value="' . esc_attr( $p->trial_days ?? '' ) . '"></label> <label>Annual price <input type="number" step="0.01" min="0" style="width:110px" name="annual_price" value="' . esc_attr( $p->annual_price ?? '' ) . '"></label> <label>User limit <input type="number" min="1" style="width:70px" name="user_limit" value="' . esc_attr( $p->user_limit ?? '' ) . '"></label> <label>Renewal reminder (days before) <input type="number" min="1" style="width:70px" name="renewal_notice_days" value="' . esc_attr( $p->renewal_notice_days ?? '' ) . '"></label> <label>Add-ons (codes, comma separated) <input name="addon_codes" value="' . esc_attr( implode( ',', (array) json_decode( (string) ( $p->addon_codes ?? '[]' ), true ) ) ) . '"></label></p>';
			echo '<p><label>Code <input name="code" value="' . esc_attr( $p->code ) . '" ' . ( $p->id ? 'readonly' : '' ) . '></label> <label>Name <input name="name" value="' . esc_attr( $p->name ) . '"></label> <label>Price <input type="number" step="0.01" min="0" name="price" value="' . esc_attr( null === $p->price ? '' : $p->price ) . '" placeholder="not set"></label> <label>Billing <select name="billing_period"><option value="month"' . selected( $p->billing_period, 'month', false ) . '>Monthly</option><option value="year"' . selected( $p->billing_period, 'year', false ) . '>Yearly</option></select></label> <label>Grace days <input type="number" min="0" name="grace_days" value="' . esc_attr( $p->grace_days ) . '" style="width:70px"></label> <label><input type="checkbox" name="is_public"' . checked( $p->is_public, 1, false ) . '> Public</label> <label><input type="checkbox" name="is_active"' . checked( $p->is_active, 1, false ) . '> Active</label></p><p><label>Description<br><textarea name="description" rows="2" cols="80">' . esc_textarea( (string) $p->description ) . '</textarea></label></p><table class="widefat striped" style="max-width:640px"><thead><tr><th>Feature</th><th>Included</th><th>Usage limit</th></tr></thead><tbody>';
			foreach ( NWC_Features::CATALOG as $code => $label ) {
				if ( in_array( $code, NWC_Features::AGENCY_ONLY, true ) ) {
					continue;
				}
				$soon = in_array( $code, NWC_Features::COMING_SOON, true );
				echo '<tr><td>' . esc_html( $label ) . ( $soon ? ' <em>(coming soon)</em>' : '' ) . '</td><td><input type="checkbox" name="f[' . esc_attr( $code ) . ']"' . checked( isset( $feat[ $code ] ), true, false ) . '></td><td><input type="number" min="0" name="l[' . esc_attr( $code ) . ']" value="' . esc_attr( $feat[ $code ]['limit'] ?? '' ) . '" placeholder="unlimited" style="width:100px"></td></tr>';
			}
			echo '</tbody></table>';
			submit_button( $p->id ? 'Save plan' : 'Add plan', 'primary', 'submit', false );
			echo '</form>';
		}
		echo '<h2>Add-ons</h2>';
		$addons   = $wpdb->get_results( 'SELECT * FROM ' . NWC_DB::t( 'addons' ) ); // phpcs:ignore
		$addons[] = (object) array( 'id' => 0, 'code' => '', 'name' => '', 'price' => null, 'feature_code' => 'crm', 'usage_limit' => null, 'duration_days' => 30, 'is_active' => 1 );
		foreach ( $addons as $a ) {
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="margin:6px 0"><input type="hidden" name="action" value="nwc_addon"><input type="hidden" name="id" value="' . (int) $a->id . '">';
			wp_nonce_field( 'nwc_addon' );
			echo '<input name="code" placeholder="code" value="' . esc_attr( $a->code ) . '" ' . ( $a->id ? 'readonly' : '' ) . '> <input name="name" placeholder="name" value="' . esc_attr( $a->name ) . '"> <input type="number" step="0.01" min="0" name="price" placeholder="price not set" value="' . esc_attr( null === $a->price ? '' : $a->price ) . '"> <select name="feature_code">';
			foreach ( array_merge( NWC_Features::CATALOG, NWC_Features::ADDON_ONLY ) as $code => $label ) {
				if ( ! in_array( $code, NWC_Features::AGENCY_ONLY, true ) ) {
					echo '<option value="' . esc_attr( $code ) . '"' . selected( $a->feature_code, $code, false ) . '>' . esc_html( $label ) . '</option>';
				}
			}
			echo '</select> <input type="number" min="0" name="usage_limit" placeholder="limit" value="' . esc_attr( $a->usage_limit ) . '" style="width:80px"> <input type="number" min="1" name="duration_days" value="' . esc_attr( $a->duration_days ) . '" style="width:70px"> days <label><input type="checkbox" name="is_active"' . checked( $a->is_active, 1, false ) . '> Active</label> ';
			submit_button( $a->id ? 'Save' : 'Add', 'secondary', 'submit', false );
			echo '</form>';
		}
		echo '</div>';
	}

	public static function save_plan() {
		global $wpdb;
		self::check( 'nwc_plan' );
		$extra = array(
			'is_trial'            => isset( $_POST['is_trial'] ) ? 1 : 0, // phpcs:ignore
			'trial_days'          => '' === self::post( 'trial_days' ) ? null : absint( self::post( 'trial_days' ) ),
			'annual_price'        => '' === self::post( 'annual_price' ) ? null : round( (float) self::post( 'annual_price' ), 2 ),
			'user_limit'          => '' === self::post( 'user_limit' ) ? null : absint( self::post( 'user_limit' ) ),
			'renewal_notice_days' => '' === self::post( 'renewal_notice_days' ) ? null : absint( self::post( 'renewal_notice_days' ) ),
			'addon_codes'         => wp_json_encode( array_values( array_filter( array_map( 'sanitize_key', explode( ',', self::post( 'addon_codes' ) ) ) ) ) ),
		);
		$id   = absint( self::post( 'id', '0' ) );
		$code = sanitize_key( self::post( 'code' ) );
		if ( '' === $code || '' === self::post( 'name' ) ) {
			self::back( 'nwc-plans', 'Enter a code and a name.' );
		}
		$features = array();
		foreach ( array_keys( NWC_Features::CATALOG ) as $f ) {
			if ( isset( $_POST['f'][ $f ] ) && ! in_array( $f, NWC_Features::AGENCY_ONLY, true ) ) { // phpcs:ignore
				$lim = isset( $_POST['l'][ $f ] ) ? sanitize_text_field( wp_unslash( $_POST['l'][ $f ] ) ) : ''; // phpcs:ignore
				$features[ $f ] = '' !== $lim ? array( 'limit' => absint( $lim ) ) : array();
			}
		}
		$price = self::post( 'price' );
		$row   = array( 'name' => mb_substr( self::post( 'name' ), 0, 120 ), 'description' => sanitize_textarea_field( wp_unslash( $_POST['description'] ?? '' ) ), 'price' => '' === $price ? null : round( (float) $price, 2 ), 'billing_period' => 'year' === self::post( 'billing_period' ) ? 'year' : 'month', 'grace_days' => absint( self::post( 'grace_days', '7' ) ), 'features' => wp_json_encode( (object) $features ), 'is_public' => isset( $_POST['is_public'] ) ? 1 : 0, 'is_active' => isset( $_POST['is_active'] ) ? 1 : 0, 'updated_at' => NWC::now() ); // phpcs:ignore
		$row = array_merge( $row, $extra );
		if ( $id ) {
			$wpdb->update( NWC_DB::t( 'plans' ), $row, array( 'id' => $id ) );
		} else {
			$wpdb->insert( NWC_DB::t( 'plans' ), array_merge( $row, array( 'code' => $code, 'created_at' => NWC::now() ) ) );
		}
		NWC_Features::flush();
		NWC::audit( 'plan_saved', array( 'workspace_id' => null, 'kind' => 'admin', 'details' => array( 'code' => $code, 'price' => $row['price'] ) ) );
		NWC_Connect::push_catalogue();
		self::back( 'nwc-plans', 'Plan saved.' );
	}

	public static function save_addon() {
		global $wpdb;
		self::check( 'nwc_addon' );
		$id    = absint( self::post( 'id', '0' ) );
		$code  = sanitize_key( self::post( 'code' ) );
		$feat  = self::post( 'feature_code' );
		$price = self::post( 'price' );
		$lim   = self::post( 'usage_limit' );
		if ( '' === $code || '' === self::post( 'name' ) || ( ! isset( NWC_Features::CATALOG[ $feat ] ) && ! isset( NWC_Features::ADDON_ONLY[ $feat ] ) ) || in_array( $feat, NWC_Features::AGENCY_ONLY, true ) ) {
			self::back( 'nwc-plans', 'Check the add-on details.' );
		}
		$row = array( 'name' => mb_substr( self::post( 'name' ), 0, 120 ), 'price' => '' === $price ? null : round( (float) $price, 2 ), 'feature_code' => $feat, 'usage_limit' => '' === $lim ? null : absint( $lim ), 'duration_days' => max( 1, absint( self::post( 'duration_days', '30' ) ) ), 'is_active' => isset( $_POST['is_active'] ) ? 1 : 0, 'updated_at' => NWC::now() ); // phpcs:ignore
		if ( $id ) {
			$wpdb->update( NWC_DB::t( 'addons' ), $row, array( 'id' => $id ) );
		} else {
			$wpdb->insert( NWC_DB::t( 'addons' ), array_merge( $row, array( 'code' => $code, 'created_at' => NWC::now() ) ) );
		}
		NWC_Connect::push_catalogue();
		self::back( 'nwc-plans', 'Add-on saved.' );
	}

	public static function page_workspaces() {
		global $wpdb;
		echo '<div class="wrap"><h1>Workspaces</h1>';
		self::notice();
		$rows = $wpdb->get_results( 'SELECT w.*, s.plan_code, s.status AS sub_status, s.period_end, s.extended_until FROM ' . NWC_DB::t( 'workspaces' ) . ' w LEFT JOIN ' . NWC_DB::t( 'subscriptions' ) . ' s ON s.workspace_id = w.id ORDER BY w.id DESC LIMIT 200' ); // phpcs:ignore
		echo '<table class="widefat striped"><thead><tr><th>Workspace</th><th>Type</th><th>Plan</th><th>Status</th><th>Ends</th><th>Manual extension</th></tr></thead><tbody>';
		foreach ( $rows as $w ) {
			echo '<tr><td><b>' . esc_html( $w->name ) . '</b><br><code>' . esc_html( $w->uid ) . '</code></td><td>' . esc_html( $w->account_type ) . '</td><td>' . esc_html( $w->plan_code ) . '</td><td>' . esc_html( 'agency' === $w->account_type ? 'managed in WorkDesk' : $w->sub_status ) . '</td><td>' . esc_html( $w->extended_until ? $w->extended_until . ' (extended)' : $w->period_end ) . '</td><td>';
			if ( 'agency' !== $w->account_type ) {
				echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="nwc_extend"><input type="hidden" name="id" value="' . (int) $w->id . '">';
				wp_nonce_field( 'nwc_extend' );
				echo '<input type="date" name="until" value="' . esc_attr( $w->extended_until ) . '"> <button class="button">Save</button></form>';
			}
			echo '</td></tr>';
		}
		echo '</tbody></table><h2>Open subscription invoices</h2>';
		$inv = $wpdb->get_results( 'SELECT i.*, w.name FROM ' . NWC_DB::t( 'sub_invoices' ) . ' i JOIN ' . NWC_DB::t( 'workspaces' ) . " w ON w.id = i.workspace_id WHERE i.status = 'issued' ORDER BY i.id DESC LIMIT 200" ); // phpcs:ignore
		echo '<table class="widefat striped"><thead><tr><th>No.</th><th>Workspace</th><th>Purpose</th><th>Amount</th><th>Record offline payment</th></tr></thead><tbody>';
		foreach ( $inv as $v ) {
			echo '<tr><td>' . esc_html( $v->doc_no ) . '</td><td>' . esc_html( $v->name ) . '</td><td>' . esc_html( $v->purpose ) . '</td><td>' . esc_html( $v->currency . ' ' . $v->amount ) . '</td><td><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="nwc_record"><input type="hidden" name="id" value="' . (int) $v->id . '">';
			wp_nonce_field( 'nwc_record' );
			echo '<input name="reference" placeholder="Bank reference" required> <button class="button button-primary">Mark paid</button></form></td></tr>';
		}
		echo '</tbody></table></div>';
	}

	public static function extend() {
		global $wpdb;
		self::check( 'nwc_extend' );
		$id    = absint( self::post( 'id', '0' ) );
		$until = self::post( 'until' );
		$wpdb->update( NWC_DB::t( 'subscriptions' ), array( 'extended_until' => NWC::valid_date( $until ) ? $until : null, 'updated_at' => NWC::now() ), array( 'workspace_id' => $id ) );
		NWC_Features::flush();
		NWC_Billing::daily();
		NWC::audit( 'subscription_extended', array( 'workspace_id' => $id, 'kind' => 'admin', 'details' => array( 'until' => $until ) ) );
		self::back( 'nwc-workspaces', 'Saved.' );
	}

	public static function record() {
		self::check( 'nwc_record' );
		$res = NWC_Billing::admin_record_payment( absint( self::post( 'id', '0' ) ), self::post( 'reference' ), get_current_user_id() );
		self::back( 'nwc-workspaces', is_wp_error( $res ) ? $res->get_error_message() : 'Payment recorded.' );
	}

	public static function grant() {
		self::check( 'nwc_grant' );
		NWC_Features::set_grant( absint( self::post( 'id', '0' ) ), sanitize_key( self::post( 'feature' ) ), 'manual', array( 'enabled' => isset( $_POST['enabled'] ), 'expires_on' => self::post( 'expires_on' ) ), get_current_user_id() ); // phpcs:ignore
		self::back( 'nwc-workspaces', 'Saved.' );
	}

	/* ---------------------------- Authentication & Security ---------------------------- */

	public static function page_auth() {
		$s = NWC::settings();
		echo '<div class="wrap"><h1>Authentication &amp; Security</h1>';
		self::notice();
		$state = function ( $p ) {
			$on = in_array( $p, NWC_Social::enabled_providers(), true );
			return $on ? 'Switched on with saved credentials. Not proven until a real sign-in has been tried.' : 'Off, or missing credentials. No button is shown.';
		};
		$secret = function ( $v ) {
			return '' !== (string) $v ? 'Saved. Leave empty to keep it.' : 'Not set.';
		};
		?>
		<h2>Social Login</h2>
		<p>Google and Facebook are the only social providers. Email and password always works. Register these callback URLs with each provider. HTTPS is required on a live site.</p>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="nwc_social"><?php wp_nonce_field( 'nwc_social' ); ?>
			<h3>Google</h3>
			<p class="description"><?php echo esc_html( $state( 'google' ) ); ?></p>
			<table class="form-table">
				<tr><th>Enabled</th><td><label><input type="checkbox" name="google_enabled" <?php checked( $s['google_enabled'] ); ?>> Show Continue with Google</label></td></tr>
				<tr><th>Client ID</th><td><input class="regular-text" name="google_client_id" value="<?php echo esc_attr( $s['google_client_id'] ); ?>"></td></tr>
				<tr><th>Client Secret</th><td><input class="regular-text" type="password" autocomplete="new-password" name="google_client_secret"><p class="description"><?php echo esc_html( $secret( $s['google_client_secret'] ) ); ?></p></td></tr>
				<tr><th>Callback URLs</th><td><code><?php echo esc_html( NWC_Social::callback_url( 'google' ) ); ?></code><br><code><?php echo esc_html( NWC_Drive::callback_url() ); ?></code> <span class="description">(Google Drive, a separate permission)</span></td></tr>
			</table>
			<div class="notice notice-info inline"><p><b>Google Drive for Workspaces: what to set up (administrators only)</b></p><ol>
				<li>In Google Cloud Console, create an OAuth client of type <i>Web application</i> and enter its Client ID and Client Secret above. The secret is stored encrypted and never shown to Workspace users.</li>
				<li>Turn on the <b>Google Drive API</b> for that Cloud project.</li>
				<li>Add this authorised redirect URI: <code><?php echo esc_html( NWC_Drive::callback_url() ); ?></code></li>
				<li>On the OAuth consent screen, add the scope <code>https://www.googleapis.com/auth/drive.file</code> (Neon Workspace sees only files and folders it creates, never the rest of a user's Drive) and <code>email</code>.</li>
				<li>Check with Google whether your app needs verification, and any limits or charges that apply to your use. These are Google's terms and can change.</li>
			</ol><p>Status: <?php echo NWC_Drive::ready() ? 'Configured. Workspace owners can now connect their Drive from Files &amp; Google Drive.' : 'Not configured. Workspace users see "Google Drive is not available yet" and no technical details.'; ?></p></div>
			<h3>Facebook</h3>
			<p class="description"><?php echo esc_html( $state( 'facebook' ) ); ?></p>
			<table class="form-table">
				<tr><th>Enabled</th><td><label><input type="checkbox" name="facebook_enabled" <?php checked( $s['facebook_enabled'] ); ?>> Show Continue with Facebook</label></td></tr>
				<tr><th>App ID</th><td><input class="regular-text" name="facebook_app_id" value="<?php echo esc_attr( $s['facebook_app_id'] ); ?>"></td></tr>
				<tr><th>App Secret</th><td><input class="regular-text" type="password" autocomplete="new-password" name="facebook_app_secret"><p class="description"><?php echo esc_html( $secret( $s['facebook_app_secret'] ) ); ?></p></td></tr>
				<tr><th>Graph API version</th><td><input name="facebook_api_version" value="<?php echo esc_attr( $s['facebook_api_version'] ); ?>"></td></tr>
				<tr><th>Callback URL</th><td><code><?php echo esc_html( NWC_Social::callback_url( 'facebook' ) ); ?></code></td></tr>
			</table>
			<?php submit_button( 'Save' ); ?>
		</form>
		<h2>Configuration test</h2>
		<p>Asks the provider whether these credentials are recognised. It does not prove that a person can sign in.</p>
		<?php
		foreach ( NWC_Social::PROVIDERS as $p ) {
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline-block;margin-right:8px"><input type="hidden" name="action" value="nwc_social_test"><input type="hidden" name="provider" value="' . esc_attr( $p ) . '">';
			wp_nonce_field( 'nwc_social_test' );
			echo '<button class="button">Test ' . esc_html( ucfirst( $p ) ) . '</button></form>';
		}
		global $wpdb;
		$rows = $wpdb->get_results( 'SELECT login, method, success, ip, created_at FROM ' . NWC_DB::t( 'logins' ) . " WHERE method <> 'password' AND success = 0 ORDER BY id DESC LIMIT 20" ); // phpcs:ignore
		echo '<h2>Recent social sign-in problems</h2>';
		if ( ! $rows ) {
			echo '<p>None recorded.</p>';
		} else {
			echo '<table class="widefat striped"><thead><tr><th>When (UTC)</th><th>Provider and reason</th><th>IP</th></tr></thead><tbody>';
			foreach ( $rows as $r ) {
				echo '<tr><td>' . esc_html( $r->created_at ) . '</td><td>' . esc_html( $r->login ) . '</td><td>' . esc_html( $r->ip ) . '</td></tr>';
			}
			echo '</tbody></table>';
		}
		echo '</div>';
	}

	public static function save_social() {
		self::check( 'nwc_social' );
		$s = NWC::settings();
		$s['google_enabled']   = isset( $_POST['google_enabled'] ); // phpcs:ignore
		$s['facebook_enabled'] = isset( $_POST['facebook_enabled'] ); // phpcs:ignore
		$s['google_client_id'] = self::post( 'google_client_id' );
		$s['facebook_app_id']  = self::post( 'facebook_app_id' );
		$v = self::post( 'facebook_api_version', 'v21.0' );
		$s['facebook_api_version'] = preg_match( '/^v\d+\.\d+$/', $v ) ? $v : 'v21.0';
		foreach ( array( 'google_client_secret', 'facebook_app_secret' ) as $k ) {
			$v = isset( $_POST[ $k ] ) ? trim( (string) wp_unslash( $_POST[ $k ] ) ) : ''; // phpcs:ignore
			if ( '' !== $v ) {
				$s[ $k ] = NWC::seal( $v );
			}
		}
		NWC::save_settings( $s );
		NWC::audit( 'social_login_settings_saved', array( 'workspace_id' => null, 'kind' => 'admin', 'details' => array( 'google' => $s['google_enabled'], 'facebook' => $s['facebook_enabled'] ) ) );
		self::back( 'nwc-auth', 'Saved.' );
	}

	public static function test_social() {
		self::check( 'nwc_social_test' );
		$p = self::post( 'provider' );
		if ( ! in_array( $p, NWC_Social::PROVIDERS, true ) ) {
			self::back( 'nwc-auth', 'Unknown provider.' );
		}
		$r = NWC_Social::test_config( $p );
		self::back( 'nwc-auth', ucfirst( $p ) . ': ' . $r['message'] );
	}

	/* ---------------------------- Payments ---------------------------- */

	public static function page_payments() {
		global $wpdb;
		echo '<div class="wrap"><h1>Payments</h1>';
		self::notice();
		$gw = NWC_Billing::gateway();
		echo '<p>Mode now: <b>' . esc_html( $gw->id() . ' / ' . $gw->mode() ) . '</b>. Change it in Settings. Test and simulated payments are never counted as real money.</p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="nwc_rz_test">';
		wp_nonce_field( 'nwc_rz_test' );
		echo '<button class="button">Test Razorpay connection</button></form>';
		$tx = $wpdb->get_results( 'SELECT t.*, w.name AS ws FROM ' . NWC_DB::t( 'transactions' ) . ' t LEFT JOIN ' . NWC_DB::t( 'workspaces' ) . " w ON w.id = t.workspace_id WHERE t.category = 'subscription' ORDER BY t.id DESC LIMIT 100" ); // phpcs:ignore
		echo '<h2>Subscription payments</h2><table class="widefat striped"><thead><tr><th>When (UTC)</th><th>Workspace</th><th>Gateway</th><th>Amount</th><th>Status</th><th>Order</th><th>Payment</th><th>Refund</th></tr></thead><tbody>';
		foreach ( $tx as $t ) {
			echo '<tr><td>' . esc_html( $t->created_at ) . '</td><td>' . esc_html( (string) $t->ws ) . '</td><td>' . esc_html( $t->gateway . ' / ' . $t->mode . ( (int) $t->is_real ? '' : ' (not real money)' ) ) . '</td><td>' . esc_html( $t->currency . ' ' . $t->amount ) . '</td><td>' . esc_html( $t->status ) . '</td><td><code>' . esc_html( (string) $t->gateway_order_id ) . '</code></td><td><code>' . esc_html( (string) $t->gateway_payment_id ) . '</code></td><td>';
			if ( 'captured' === $t->status && 'razorpay' === $t->gateway ) {
				echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" onsubmit="return confirm(\'Refund this payment through Razorpay?\')"><input type="hidden" name="action" value="nwc_refund"><input type="hidden" name="id" value="' . (int) $t->id . '">';
				wp_nonce_field( 'nwc_refund' );
				echo '<button class="button">Refund</button></form>';
			}
			echo '</td></tr>';
		}
		echo '</tbody></table>';
		$ev = $wpdb->get_results( 'SELECT gateway, event_id, event, processed_at FROM ' . NWC_DB::t( 'gateway_events' ) . ' ORDER BY id DESC LIMIT 100' ); // phpcs:ignore
		echo '<h2>Gateway events received</h2><table class="widefat striped"><thead><tr><th>When (UTC)</th><th>Source</th><th>Event</th><th>Event id</th></tr></thead><tbody>';
		foreach ( $ev as $e ) {
			echo '<tr><td>' . esc_html( $e->processed_at ) . '</td><td>' . esc_html( $e->gateway ) . '</td><td>' . esc_html( $e->event ) . '</td><td><code>' . esc_html( $e->event_id ) . '</code></td></tr>';
		}
		echo '</tbody></table></div>';
	}

	public static function test_razorpay() {
		self::check( 'nwc_rz_test' );
		$gw = NWC_Billing::gateway();
		$r  = 'razorpay' === $gw->id() ? $gw->test_connection() : array( 'message' => 'Choose Razorpay test or live mode and enter the keys in Settings first.' );
		self::back( 'nwc-payments', $r['message'] );
	}

	public static function refund() {
		global $wpdb;
		self::check( 'nwc_refund' );
		$t  = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . NWC_DB::t( 'transactions' ) . " WHERE id = %d AND category = 'subscription' AND status = 'captured' AND gateway = 'razorpay'", absint( self::post( 'id', '0' ) ) ) ); // phpcs:ignore
		$gw = NWC_Billing::gateway();
		if ( ! $t || 'razorpay' !== $gw->id() || $gw->mode() !== $t->mode ) {
			self::back( 'nwc-payments', 'This payment cannot be refunded from here in the current mode.' );
		}
		$res = $gw->refund( $t->gateway_payment_id, (float) $t->amount );
		if ( is_wp_error( $res ) ) {
			self::back( 'nwc-payments', 'Razorpay refused the refund.' );
		}
		NWC_Billing::mark_refunded( $t->gateway_payment_id, (int) round( (float) $t->amount * 100 ) );
		NWC::audit( 'refund_requested', array( 'workspace_id' => $t->workspace_id, 'kind' => 'billing', 'entity' => 'transaction', 'entity_id' => $t->id ) );
		self::back( 'nwc-payments', 'Refund requested.' );
	}

	/* ---------------------------- Email templates ---------------------------- */

	public static function page_templates() {
		echo '<div class="wrap"><h1>Subscription email templates</h1>';
		self::notice();
		if ( ! NWC_Notify::enabled() ) {
			echo '<div class="notice notice-warning"><p>Sending email is off in Settings, so none of these leave this site.</p></div>';
		}
		echo '<p>Placeholders: <code>{name}</code> <code>{plan}</code> <code>{end_date}</code> <code>{invoice}</code> <code>{app_url}</code></p><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="nwc_templates">';
		wp_nonce_field( 'nwc_templates' );
		foreach ( NWC_Signup::templates() as $k => $t ) {
			echo '<h2>' . esc_html( ucfirst( str_replace( '_', ' ', $k ) ) ) . '</h2><p><input class="large-text" name="t[' . esc_attr( $k ) . '][0]" value="' . esc_attr( $t[0] ) . '"></p><p><textarea class="large-text" rows="3" name="t[' . esc_attr( $k ) . '][1]">' . esc_textarea( $t[1] ) . '</textarea></p>';
		}
		submit_button();
		echo '</form></div>';
	}

	public static function save_templates() {
		self::check( 'nwc_templates' );
		$in  = isset( $_POST['t'] ) ? wp_unslash( $_POST['t'] ) : array(); // phpcs:ignore
		$out = array();
		foreach ( array_keys( NWC_Signup::default_templates() ) as $k ) {
			if ( isset( $in[ $k ][0], $in[ $k ][1] ) ) {
				$out[ $k ] = array( sanitize_text_field( (string) $in[ $k ][0] ), wp_kses_post( (string) $in[ $k ][1] ) );
			}
		}
		update_option( 'nwc_email_templates', $out, false );
		self::back( 'nwc-templates', 'Saved.' );
	}
}
