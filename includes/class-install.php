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
			add_option( SCP_Settings::OPTION, SCP_Settings::defaults(), '', true );
		}
		self::ensure_keys();
		if ( ! wp_next_scheduled( 'scp_cleanup' ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'scp_cleanup' );
		}
		if ( ! wp_next_scheduled( 'scp_webhook_retry' ) ) {
			wp_schedule_event( time() + 300, 'scp_five_minutes', 'scp_webhook_retry' );
		}
		if ( self::schema_ok() ) {
			// Autoload: se citește la fiecare cerere, deci fără interogare separată.
			update_option( 'scp_db_version', SCP_DB_VERSION, true );
		}
	}

	/** Cheile webhook se generează o singură dată (înainte, valoarea implicită se regenera la fiecare cerere). */
	private static function ensure_keys(): void {
		$s   = SCP_Settings::all();
		$set = array();
		if ( strlen( (string) ( $s['webhook_secret'] ?? '' ) ) < 16 ) {
			$set['webhook_secret'] = wp_generate_password( 40, false, false );
		}
		if ( strlen( (string) ( $s['sheet_key'] ?? '' ) ) < 16 || ( $s['sheet_key'] ?? '' ) === ( $set['webhook_secret'] ?? $s['webhook_secret'] ) ) {
			$set['sheet_key'] = wp_generate_password( 40, false, false );
		}
		if ( $set ) {
			SCP_Settings::update( $set );
		}
	}

	/** Coloanele adăugate în versiunile recente există? Altfel versiunea bazei de date nu crește și upgrade-ul se reia. */
	private static function schema_ok(): bool {
		global $wpdb;
		$v = (array) $wpdb->get_col( 'SHOW COLUMNS FROM ' . self::table( 'vouchers' ) ); // phpcs:ignore
		$b = (array) $wpdb->get_col( 'SHOW COLUMNS FROM ' . self::table( 'bookings' ) ); // phpcs:ignore
		return ! array_diff( array( 'code_norm', 'recipient_name', 'buyer_phone' ), $v ) && in_array( 'meta', $b, true );
	}

	public static function deactivate(): void {
		wp_clear_scheduled_hook( 'scp_webhook_retry' );
		wp_clear_scheduled_hook( 'scp_cleanup' );
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( '', array(), 'scp-sms' );
			as_unschedule_all_actions( '', array(), 'scp' );
		}
		delete_option( 'scp_sms_recurring_at' );
	}

	public static function maybe_upgrade(): void {
		$from = (string) get_option( 'scp_db_version' );
		if ( $from === SCP_DB_VERSION ) {
			return;
		}
		// Primele cereri după un update ar rula toate dbDelta deodată: doar una o face (add_option e atomic).
		if ( ! add_option( 'scp_upgrade_lock', time(), '', false ) ) {
			if ( (int) get_option( 'scp_upgrade_lock' ) > time() - 60 ) {
				return;
			}
			update_option( 'scp_upgrade_lock', time(), false );
		}
		self::activate();
		self::upgrade_data( $from );
		delete_option( 'scp_upgrade_lock' );
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

	private static function upgrade_data( string $from ): void {
		global $wpdb;
		if ( $from && version_compare( $from, '7', '<' ) ) {
			// Căutarea voucherelor folosește coloana normalizată (cu index), nu REPLACE() pe toată tabela.
			$wpdb->query( 'UPDATE ' . self::table( 'vouchers' ) . " SET code_norm = UPPER(REPLACE(code, '-', '')) WHERE code_norm = ''" ); // phpcs:ignore
			// Setările se citesc pe aproape orice pagină: autoload.
			foreach ( array( SCP_Settings::OPTION, 'scp_sms_settings' ) as $opt ) {
				if ( function_exists( 'wp_set_option_autoload' ) && false !== get_option( $opt ) ) {
					wp_set_option_autoload( $opt, true );
				}
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
  buyer_phone varchar(40) NOT NULL DEFAULT '',
  recipient_name varchar(190) NOT NULL DEFAULT '',
  recipient_email varchar(190) NOT NULL DEFAULT '',
  recipient_phone varchar(40) NOT NULL DEFAULT '',
  code_norm varchar(32) NOT NULL DEFAULT '',
  note varchar(190) NOT NULL DEFAULT '',
  booking_id bigint(20) unsigned NULL,
  expires_on date NULL,
  created_at datetime NOT NULL,
  used_at datetime NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY code (code),
  KEY status (status),
  KEY order_id (order_id),
  KEY booking_id (booking_id),
  KEY code_norm (code_norm)
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
  KEY status (status),
  KEY updated_at (updated_at)
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
  KEY status_next (status,next_attempt_at),
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
