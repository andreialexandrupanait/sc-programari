<?php
/**
 * Plugin Name:       SC Programări Crăciun
 * Description:       Programări online pentru ședințele foto de Crăciun: calendar cu sloturi, bază de date proprie, panou în admin, blocare intervale, emailuri și webhook pentru n8n.
 * Version:           1.2.1
 * Requires at least: 6.4
 * Requires PHP:      8.1
 * Author:            SimpleAD
 * Author URI:        https://simplead.ro
 * Text Domain:       sc-programari
 * License:           Proprietary
 */

defined( 'ABSPATH' ) || exit;

define( 'SCP_VERSION', '1.2.1' );
define( 'SCP_DB_VERSION', '5' );
define( 'SCP_FILE', __FILE__ );
define( 'SCP_DIR', plugin_dir_path( __FILE__ ) );
define( 'SCP_URL', plugin_dir_url( __FILE__ ) );

require_once SCP_DIR . 'includes/class-install.php';
require_once SCP_DIR . 'includes/class-settings.php';
require_once SCP_DIR . 'includes/class-helpers.php';
require_once SCP_DIR . 'includes/class-availability.php';
require_once SCP_DIR . 'includes/class-pricing.php';
require_once SCP_DIR . 'includes/class-vouchers.php';
require_once SCP_DIR . 'includes/class-bookings.php';
require_once SCP_DIR . 'includes/class-mailer.php';
require_once SCP_DIR . 'includes/class-webhook.php';
require_once SCP_DIR . 'includes/sms/interface-sms-provider.php';
require_once SCP_DIR . 'includes/sms/class-sms-provider-smso.php';
require_once SCP_DIR . 'includes/sms/class-sms-provider-simulated.php';
require_once SCP_DIR . 'includes/class-sms.php';
require_once SCP_DIR . 'includes/class-sms-admin.php';
require_once SCP_DIR . 'includes/class-rest.php';
require_once SCP_DIR . 'includes/class-frontend.php';
require_once SCP_DIR . 'includes/class-woocommerce.php';
require_once SCP_DIR . 'includes/class-admin.php';

register_activation_hook( __FILE__, array( 'SCP_Install', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'SCP_Install', 'deactivate' ) );

add_action(
	'plugins_loaded',
	static function () {
		SCP_Install::maybe_upgrade();
		SCP_Webhook::init();
		SCP_Rest::init();
		SCP_Frontend::init();
		SCP_WooCommerce::init();
		SCP_Vouchers::init();
		SCP_SMS::init();
		add_action( 'scp_webhook_retry', array( 'SCP_Bookings', 'sweep' ), 5 );
		// Rezervă pentru cazul în care cron-ul serverului întârzie: cel mult o dată la 5 minute, după răspuns.
		add_action(
			'shutdown',
			static function () {
				if ( wp_doing_cron() || get_transient( 'scp_sweep_lock' ) ) {
					return;
				}
				set_transient( 'scp_sweep_lock', 1, 300 );
				if ( function_exists( 'fastcgi_finish_request' ) && ! is_admin() ) {
					fastcgi_finish_request();
				}
				SCP_Bookings::sweep();
			},
			0
		);
		if ( is_admin() ) {
			SCP_Admin::init();
			SCP_SMS_Admin::init();
		}
	}
);
