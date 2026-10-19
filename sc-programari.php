<?php
/**
 * Plugin Name:       SC Programări Crăciun
 * Description:       Programări online pentru ședințele foto de Crăciun: calendar cu sloturi, bază de date proprie, panou în admin, blocare intervale, emailuri și webhook pentru n8n.
 * Version:           1.3.16
 * Requires at least: 6.4
 * Requires PHP:      8.1
 * Author:            SimpleAD
 * Author URI:        https://simplead.ro
 * Text Domain:       sc-programari
 * License:           Proprietary
 */

defined( 'ABSPATH' ) || exit;

// O a doua copie a pluginului (alt folder) nu se mai încarcă: altfel clasele s-ar declara de două ori.
if ( defined( 'SCP_VERSION' ) ) {
	add_action(
		'admin_notices',
		static function () {
			echo '<div class="notice notice-error"><p><strong>SC Programări Crăciun</strong> este instalat de două ori. Păstrează doar folderul <code>sc-programari</code> și șterge copia în plus din pagina de module.</p></div>';
		}
	);
	return;
}

define( 'SCP_VERSION', '1.3.16' );
define( 'SCP_DB_VERSION', '7' );
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
require_once SCP_DIR . 'includes/class-privacy.php';

register_activation_hook( __FILE__, array( 'SCP_Install', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'SCP_Install', 'deactivate' ) );

// Compatibil cu tabelele de comenzi WooCommerce (HPOS): folosim doar API-ul de comenzi, nu postmeta direct.
add_action(
	'before_woocommerce_init',
	static function () {
		if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', SCP_FILE, true );
		}
	}
);

/*
 * Mu-plugin-ul de securitate SimpleAD scoate „?ver=” din toate fișierele CSS/JS (ca să ascundă versiunile).
 * Fără versiune în URL, după un update browserul și Cloudflare servesc fișierele vechi ale pluginului.
 * Pentru fișierele noastre punem înapoi un parametru propriu, după filtrul lor (prioritatea 10).
 * Folderul pluginului poate avea alt nume decât „sc-programari” (pe live e „sc-programari-1.2.3”).
 */
$scp_bust = static function ( $src ) {
	$needle = '/plugins/' . basename( untrailingslashit( SCP_DIR ) ) . '/assets/';
	if ( ! is_string( $src ) || ! str_contains( $src, $needle ) ) {
		return $src;
	}
	$path = SCP_DIR . 'assets/' . explode( $needle, strtok( $src, '?' ), 2 )[1];
	$v    = SCP_VERSION . ( is_file( $path ) ? '.' . filemtime( $path ) : '' );
	return add_query_arg( 'scpv', $v, $src );
};
add_filter( 'style_loader_src', $scp_bust, 99 );
add_filter( 'script_loader_src', $scp_bust, 99 );
unset( $scp_bust );

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
		SCP_Privacy::init();
		// „+ Programare” în bara de sus, și pe site (Ștefan e logat când răspunde la telefon).
		add_action( 'admin_bar_menu', array( 'SCP_Admin', 'admin_bar' ), 70 );
		add_action( 'wp_head', array( 'SCP_Admin', 'admin_bar_css' ) );
		add_action( 'admin_head', array( 'SCP_Admin', 'admin_bar_css' ) );
		add_action( 'scp_webhook_retry', array( 'SCP_Bookings', 'sweep' ), 5 );
		// Expirările și trimiterile spre Sheet rulează din cron (pe live, cronul serverului, în fiecare minut).
		// Dacă cronul a rămas în urmă cu peste 10 minute (sau nu rulează deloc), le rulează un vizitator,
		// după ce a primit pagina, cel mult o dată la 5 minute.
		add_action(
			'shutdown',
			static function () {
				$next = wp_next_scheduled( 'scp_webhook_retry' );
				if ( wp_doing_cron() || ! $next || $next > time() - 600 || get_transient( 'scp_sweep_lock' ) ) {
					return;
				}
				set_transient( 'scp_sweep_lock', 1, 300 );
				if ( function_exists( 'fastcgi_finish_request' ) && ! is_admin() ) {
					fastcgi_finish_request();
				}
				SCP_Bookings::sweep();
				SCP_Webhook::pump(); // și trimiterile către Sheet, care altfel ar aștepta cronul
			},
			20
		);
		if ( is_admin() ) {
			SCP_Admin::init();
			SCP_SMS_Admin::init();
		}
	}
);

/*
 * Update din GitHub (Plugin Update Checker). La fiecare versiune nouă din header, GitHub Action-ul
 * din .github/workflows/release.yml face un release cu sc-programari.zip, iar WordPress îl oferă
 * ca update în pagina de pluginuri. Repo-ul e privat, deci descărcarea are nevoie de un token GitHub
 * din wp-config.php. Se folosește SCP_GITHUB_TOKEN dacă există, altfel tokenul deja definit pentru
 * pluginul SC Wfolio Gate (SC_WFG_GITHUB_TOKEN), cu condiția să aibă acces și la acest repo.
 */
// Pe o copie de lucru clonată cu git (Local) nu se caută update-uri: un update din WordPress ar înlocui folderul și ar șterge .git.
// Și pe admin-ajax: butonul „Actualizează acum” din pagina de module rulează acolo (fără el, WordPress spune „are deja ultima versiune”).
if ( ( is_admin() || wp_doing_cron() || ( defined( 'WP_CLI' ) && WP_CLI ) ) && ! is_dir( SCP_DIR . '.git' ) ) {
	require_once SCP_DIR . 'lib/plugin-update-checker/plugin-update-checker.php';
	if ( class_exists( '\YahnisElsts\PluginUpdateChecker\v5\PucFactory' ) ) {
		$scp_update_checker = \YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker(
			'https://github.com/andreialexandrupanait/sc-programari/',
			SCP_FILE,
			'sc-programari'
		);
		$scp_github_token = defined( 'SCP_GITHUB_TOKEN' ) ? SCP_GITHUB_TOKEN : ( defined( 'SC_WFG_GITHUB_TOKEN' ) ? SC_WFG_GITHUB_TOKEN : '' );
		if ( $scp_github_token ) {
			$scp_update_checker->setAuthentication( $scp_github_token );
		}
		$scp_vcs_api = $scp_update_checker->getVcsApi();
		if ( method_exists( $scp_vcs_api, 'enableReleaseAssets' ) ) {
			// Doar ZIP-ul din release (fără dev/, tools/): niciodată arhiva întregului repo.
			$scp_vcs_api->enableReleaseAssets( '/sc-programari\.zip$/i', 2 /* Api::REQUIRE_RELEASE_ASSETS */ );
		}
		unset( $scp_github_token, $scp_vcs_api );
	}
}
