<?php
/**
 * La ștergerea pluginului datele rămân în baza de date, ca o ștergere din greșeală
 * să nu piardă programările. Pentru ștergere completă, adaugă în wp-config.php:
 * define( 'SCP_DELETE_DATA', true );
 */
defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

wp_clear_scheduled_hook( 'scp_webhook_retry' );

if ( defined( 'SCP_DELETE_DATA' ) && SCP_DELETE_DATA ) {
	global $wpdb;
	foreach ( array( 'bookings', 'overrides', 'webhook_log' ) as $t ) {
		$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}scp_{$t}" ); // phpcs:ignore
	}
	delete_option( 'scp_settings' );
	delete_option( 'scp_db_version' );
}
