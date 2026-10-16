<?php
/**
 * La ștergerea pluginului datele rămân în baza de date, ca o ștergere din greșeală
 * să nu piardă programările. Pentru ștergere completă, adaugă în wp-config.php:
 * define( 'SCP_DELETE_DATA', true );
 */
defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

wp_clear_scheduled_hook( 'scp_webhook_retry' );
wp_clear_scheduled_hook( 'scp_cleanup' );
if ( function_exists( 'as_unschedule_all_actions' ) ) {
	as_unschedule_all_actions( '', array(), 'scp-sms' );
	as_unschedule_all_actions( '', array(), 'scp' );
}

if ( defined( 'SCP_DELETE_DATA' ) && SCP_DELETE_DATA ) {
	global $wpdb;
	foreach ( array( 'bookings', 'overrides', 'vouchers', 'sms_log', 'webhook_log' ) as $t ) {
		$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}scp_{$t}" ); // phpcs:ignore
	}
	foreach ( array( 'scp_settings', 'scp_db_version', 'scp_sms_settings', 'scp_sms_heartbeat', 'scp_sms_last_test', 'scp_sms_recurring_at', 'scp_webhook_lock', 'scp_upgrade_lock' ) as $opt ) {
		delete_option( $opt );
	}
	foreach ( wp_roles()->roles as $name => $role ) {
		$r = get_role( $name );
		if ( $r ) {
			$r->remove_cap( 'scp_manage' );
		}
	}
}
