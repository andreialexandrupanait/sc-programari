<?php
defined( 'ABSPATH' ) || exit;

/**
 * Tabele, capabilități și cron.
 */
class SCP_Install {

	const CAP = 'scp_manage';

	public static function table( string $name ): string {
		global $wpdb;
		return $wpdb->prefix . 'scp_' . $name;
	}

	public static function activate(): void {
		self::create_tables();
		self::add_caps();
		if ( false === get_option( SCP_Settings::OPTION ) ) {
			add_option( SCP_Settings::OPTION, SCP_Settings::defaults(), '', false );
		}
		if ( ! wp_next_scheduled( 'scp_webhook_retry' ) ) {
			wp_schedule_event( time() + 300, 'scp_five_minutes', 'scp_webhook_retry' );
		}
		update_option( 'scp_db_version', SCP_DB_VERSION, false );
	}

	public static function deactivate(): void {
		wp_clear_scheduled_hook( 'scp_webhook_retry' );
	}

	public static function maybe_upgrade(): void {
		$from = (string) get_option( 'scp_db_version' );
		if ( $from === SCP_DB_VERSION ) {
			return;
		}
		self::activate();
		$saved = get_option( SCP_Settings::OPTION );
		if ( is_array( $saved ) && $from && version_compare( $from, '3', '<' ) ) {
			$events = (array) ( $saved['webhook_events'] ?? array() );
			if ( ! in_array( 'voucher.issued', $events, true ) ) {
				$events[]                = 'voucher.issued';
				$saved['webhook_events'] = $events;
				update_option( SCP_Settings::OPTION, $saved, false );
				SCP_Settings::flush();
			}
		}
	}

	public static function add_caps(): void {
		foreach ( array( 'administrator', 'shop_manager', 'editor' ) as $role_name ) {
			$role = get_role( $role_name );
			if ( $role && ! $role->has_cap( self::CAP ) ) {
				$role->add_cap( self::CAP );
			}
		}
	}

	public static function create_tables(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$charset = $wpdb->get_charset_collate();

		$bookings  = self::table( 'bookings' );
		$overrides = self::table( 'overrides' );
		$hooks     = self::table( 'webhook_log' );

		// active_slot = "YYYY-MM-DD HH:MM" pentru programările active, NULL pentru cele anulate.
		// Indexul UNIQUE pe această coloană garantează la nivel de bază de date că nu pot exista
		// două programări active pe același slot, chiar dacă două cereri ajung în aceeași secundă.
		dbDelta(
			"CREATE TABLE {$bookings} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  code varchar(12) NOT NULL,
  token char(32) NOT NULL,
  slot_date date NOT NULL,
  slot_time char(5) NOT NULL,
  active_slot varchar(16) DEFAULT NULL,
  package varchar(32) NOT NULL,
  price decimal(10,2) NOT NULL DEFAULT 0,
  list_price decimal(10,2) NOT NULL DEFAULT 0,
  discount decimal(10,2) NOT NULL DEFAULT 0,
  discount_code varchar(60) NOT NULL DEFAULT '',
  discount_source varchar(20) NOT NULL DEFAULT '',
  name varchar(190) NOT NULL,
  email varchar(190) NOT NULL DEFAULT '',
  phone varchar(40) NOT NULL DEFAULT '',
  message text NULL,
  status varchar(20) NOT NULL DEFAULT 'confirmed',
  payment_status varchar(20) NOT NULL DEFAULT 'unpaid',
  payment_link_sent_at datetime NULL,
  pay_by datetime NULL,
  reminder_sent_at datetime NULL,
  cancel_reason varchar(20) NOT NULL DEFAULT '',
  paid_at datetime NULL,
  order_id bigint(20) unsigned NULL,
  source varchar(20) NOT NULL DEFAULT 'online',
  notes text NULL,
  gdpr_at datetime NULL,
  meta longtext NULL,
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  cancelled_at datetime NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY code (code),
  UNIQUE KEY token (token),
  UNIQUE KEY active_slot (active_slot),
  KEY slot_date (slot_date),
  KEY status (status),
  KEY pay_by (pay_by),
  KEY email (email)
) {$charset};"
		);

		// otime = '' înseamnă toată ziua (doar pentru type = block).
		dbDelta(
			"CREATE TABLE {$overrides} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  odate date NOT NULL,
  otime varchar(5) NOT NULL DEFAULT '',
  type varchar(10) NOT NULL DEFAULT 'block',
  reason varchar(190) NOT NULL DEFAULT '',
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY odate_otime (odate,otime),
  KEY odate (odate)
) {$charset};"
		);

		$vouchers = self::table( 'vouchers' );
		dbDelta(
			"CREATE TABLE {$vouchers} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  code varchar(32) NOT NULL,
  package varchar(32) NOT NULL,
  value decimal(10,2) NOT NULL DEFAULT 0,
  status varchar(12) NOT NULL DEFAULT 'active',
  order_id bigint(20) unsigned NULL,
  order_item_id bigint(20) unsigned NULL,
  buyer_name varchar(190) NOT NULL DEFAULT '',
  buyer_email varchar(190) NOT NULL DEFAULT '',
  note varchar(190) NOT NULL DEFAULT '',
  booking_id bigint(20) unsigned NULL,
  expires_on date NULL,
  created_at datetime NOT NULL,
  used_at datetime NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY code (code),
  KEY status (status),
  KEY order_id (order_id),
  KEY booking_id (booking_id)
) {$charset};"
		);

		$sms = self::table( 'sms_log' );
		dbDelta(
			"CREATE TABLE {$sms} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  booking_id bigint(20) unsigned NOT NULL DEFAULT 0,
  type varchar(40) NOT NULL,
  phone varchar(20) NOT NULL DEFAULT '',
  body text NULL,
  status varchar(20) NOT NULL DEFAULT 'scheduled',
  provider varchar(20) NOT NULL DEFAULT '',
  provider_id varchar(64) NOT NULL DEFAULT '',
  cost decimal(8,3) NULL,
  attempts smallint(5) unsigned NOT NULL DEFAULT 0,
  http_code smallint(5) unsigned NULL,
  last_error varchar(255) NOT NULL DEFAULT '',
  slot varchar(16) NOT NULL DEFAULT '',
  scheduled_for datetime NULL,
  sent_at datetime NULL,
  delivered_at datetime NULL,
  mcc varchar(4) NOT NULL DEFAULT '',
  mnc varchar(4) NOT NULL DEFAULT '',
  backup_email_at datetime NULL,
  history text NULL,
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY booking_type (booking_id,type),
  KEY provider_id (provider_id),
  KEY status (status)
) {$charset};"
		);

		dbDelta(
			"CREATE TABLE {$hooks} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  delivery_id char(36) NOT NULL,
  event varchar(40) NOT NULL,
  booking_id bigint(20) unsigned NULL,
  url varchar(500) NOT NULL DEFAULT '',
  payload longtext NOT NULL,
  status varchar(12) NOT NULL DEFAULT 'pending',
  attempts tinyint(3) unsigned NOT NULL DEFAULT 0,
  response_code smallint(5) unsigned NULL,
  response_body text NULL,
  last_error text NULL,
  created_at datetime NOT NULL,
  next_attempt_at datetime NULL,
  delivered_at datetime NULL,
  PRIMARY KEY  (id),
  KEY status (status),
  KEY booking_id (booking_id)
) {$charset};"
		);
	}
}

add_filter(
	'cron_schedules',
	static function ( $schedules ) {
		$schedules['scp_five_minutes'] = array(
			'interval' => 300,
			'display'  => 'La 5 minute (SC Programări)',
		);
		return $schedules;
	}
);
