<?php
defined( 'ABSPATH' ) || exit;

/**
 * Webhook către n8n.
 *
 * Fiecare eveniment se salvează întâi în jurnal (status pending), apoi se trimite la finalul
 * cererii, după ce răspunsul a plecat deja la vizitator. Dacă n8n nu răspunde cu 2xx,
 * cron-ul reîncearcă la 5, 15, 60 și 180 de minute. Din admin se poate retrimite manual.
 *
 * Semnătură: X-SCP-Signature = hex( HMAC-SHA256( secret, X-SCP-Timestamp + "." + body ) )
 */
class SCP_Webhook {

	const MAX_ATTEMPTS = 5;
	const BACKOFF      = array( 5, 15, 60, 180 );

	private static array $queue = array();

	public static function init(): void {
		add_action( 'scp_booking_created', fn( $b ) => self::emit( 'booking.created', $b ), 30 );
		add_action( 'scp_booking_updated', fn( $b ) => self::emit( 'booking.updated', $b ), 30 );
		add_action( 'scp_booking_cancelled', fn( $b ) => self::emit( 'booking.cancelled', $b ), 30 );
		add_action( 'scp_booking_paid', fn( $b ) => self::emit( 'booking.paid', $b ), 30 );
		add_action( 'scp_payment_link_sent', fn( $b ) => self::emit( 'booking.payment_link_sent', $b ), 30 );
		add_action( 'scp_voucher_issued', fn( $v ) => self::emit_voucher( 'voucher.issued', $v ), 30 );
		add_action( 'scp_webhook_retry', array( __CLASS__, 'retry_due' ) );
		add_action( 'shutdown', array( __CLASS__, 'flush' ), 1 );
	}

	public static function enabled_for( string $event ): bool {
		$url = trim( (string) SCP_Settings::get( 'webhook_url' ) );
		return '' !== $url && in_array( $event, (array) SCP_Settings::get( 'webhook_events', array() ), true );
	}

	public static function payload( string $event, array $b, string $delivery_id ): array {
		$e = SCP_Bookings::export( $b );
		return array(
			'event'       => $event,
			'delivery_id' => $delivery_id,
			'sent_at'     => SCP_Helpers::now()->format( DATE_ATOM ),
			'site'        => home_url(),
			'booking'     => $e,
			// Rând gata de pus în Google Sheets (n8n: Append or Update, coloana de potrivire „Cod”).
			'sheet'       => array(
				'Cod'               => $e['code'],
				'Ora'               => $e['created_time_ro'],
				'Data'              => $e['created_date_ro'],
				'Nume'              => $e['name'],
				'Email'             => $e['email'],
				'Telefon'           => $e['phone'],
				'Pachet'            => $e['package_label'],
				'Pret'              => $e['price'],
				'Mesaj'             => $e['message'],
				'Data programarii'  => $e['date_ro'],
				'Ziua'              => $e['weekday_ro'],
				'Ora programarii'   => $e['time'],
				'Status programare' => $e['status_label'],
				'Status plata'      => $e['payment_status_label'],
				'Observatii'        => $e['notes'],
				'Link plata'        => $e['payment_link'],
				'Pret intreg'       => $e['list_price'],
				'Reducere'          => $e['discount'],
				'Voucher'           => 'gift' === $e['discount_source'] ? $e['discount_code'] : '',
				'Termen plata'      => $e['pay_by_ro'],
				'Sursa'             => $e['source_label'],
			),
		);
	}

	public static function emit( string $event, array $b ): void {
		if ( ! self::enabled_for( $event ) ) {
			return;
		}
		global $wpdb;
		$delivery = SCP_Helpers::uuid();
		$payload  = self::payload( $event, $b, $delivery );
		$wpdb->insert(
			SCP_Install::table( 'webhook_log' ),
			array(
				'delivery_id'     => $delivery,
				'event'           => $event,
				'booking_id'      => $b['id'],
				'url'             => (string) SCP_Settings::get( 'webhook_url' ),
				'payload'         => wp_json_encode( $payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ),
				'status'          => 'pending',
				'attempts'        => 0,
				'created_at'      => SCP_Helpers::mysql_now(),
				'next_attempt_at' => SCP_Helpers::mysql_now(),
			)
		);
		self::$queue[] = (int) $wpdb->insert_id;
	}

	public static function emit_voucher( string $event, ?array $v ): void {
		if ( ! $v || ! self::enabled_for( $event ) ) {
			return;
		}
		global $wpdb;
		$delivery = SCP_Helpers::uuid();
		$e        = SCP_Vouchers::export( $v );
		$payload  = array(
			'event'       => $event,
			'delivery_id' => $delivery,
			'sent_at'     => SCP_Helpers::now()->format( DATE_ATOM ),
			'site'        => home_url(),
			'voucher'     => $e,
			'sheet'       => array(
				'Cod voucher'  => $e['code'],
				'Pachet'       => $e['package_label'],
				'Valoare'      => $e['value'],
				'Cumparator'   => $e['buyer_name'],
				'Email'        => $e['buyer_email'],
				'Comanda'      => $e['order_id'],
				'Valabil pana' => $e['expires_on'] ? SCP_Helpers::date_ro( $e['expires_on'] ) : '',
				'Status'       => $e['status_label'],
			),
		);
		$wpdb->insert(
			SCP_Install::table( 'webhook_log' ),
			array(
				'delivery_id'     => $delivery,
				'event'           => $event,
				'booking_id'      => null,
				'url'             => (string) SCP_Settings::get( 'webhook_url' ),
				'payload'         => wp_json_encode( $payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ),
				'status'          => 'pending',
				'attempts'        => 0,
				'created_at'      => SCP_Helpers::mysql_now(),
				'next_attempt_at' => SCP_Helpers::mysql_now(),
			)
		);
		self::$queue[] = (int) $wpdb->insert_id;
	}

	/** Trimite evenimentele din această cerere, după ce vizitatorul a primit răspunsul. */
	public static function flush(): void {
		if ( ! self::$queue ) {
			return;
		}
		if ( function_exists( 'fastcgi_finish_request' ) && ! is_admin() && ! wp_doing_cron() ) {
			fastcgi_finish_request();
		}
		foreach ( array_unique( self::$queue ) as $id ) {
			self::deliver( $id );
		}
		self::$queue = array();
	}

	/** @return array{ok:bool,code:int|null,error:string} */
	public static function deliver( int $log_id ): array {
		global $wpdb;
		$table = SCP_Install::table( 'webhook_log' );
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $log_id ), ARRAY_A );
		if ( ! $row ) {
			return array( 'ok' => false, 'code' => null, 'error' => 'Înregistrare inexistentă.' );
		}
		$url = trim( (string) SCP_Settings::get( 'webhook_url' ) ) ?: $row['url'];
		if ( ! $url ) {
			return array( 'ok' => false, 'code' => null, 'error' => 'Nu există adresă de webhook în setări.' );
		}

		$body = (string) $row['payload'];
		$ts   = (string) time();
		$sig  = hash_hmac( 'sha256', $ts . '.' . $body, (string) SCP_Settings::get( 'webhook_secret' ) );

		$res = wp_remote_post(
			$url,
			array(
				'timeout'   => 8,
				'blocking'  => true,
				'headers'   => array(
					'Content-Type'    => 'application/json; charset=utf-8',
					'User-Agent'      => 'SC-Programari/' . SCP_VERSION . '; ' . home_url(),
					'X-SCP-Event'     => $row['event'],
					'X-SCP-Delivery'  => $row['delivery_id'],
					'X-SCP-Timestamp' => $ts,
					'X-SCP-Signature' => $sig,
				),
				'body'      => $body,
			)
		);

		$attempts = (int) $row['attempts'] + 1;
		$code     = is_wp_error( $res ) ? null : (int) wp_remote_retrieve_response_code( $res );
		$ok       = $code && $code >= 200 && $code < 300;
		$error    = is_wp_error( $res ) ? $res->get_error_message() : ( $ok ? '' : 'HTTP ' . $code );

		$next = null;
		if ( ! $ok && $attempts < self::MAX_ATTEMPTS ) {
			$wait = self::BACKOFF[ min( $attempts - 1, count( self::BACKOFF ) - 1 ) ];
			$next = SCP_Helpers::now()->modify( "+{$wait} minutes" )->format( 'Y-m-d H:i:s' );
		}

		$wpdb->update(
			$table,
			array(
				'url'             => $url,
				'status'          => $ok ? 'sent' : ( $next ? 'pending' : 'failed' ),
				'attempts'        => $attempts,
				'response_code'   => $code,
				'response_body'   => is_wp_error( $res ) ? '' : mb_substr( (string) wp_remote_retrieve_body( $res ), 0, 2000 ),
				'last_error'      => $error,
				'next_attempt_at' => $next,
				'delivered_at'    => $ok ? SCP_Helpers::mysql_now() : null,
			),
			array( 'id' => $log_id )
		);

		return array( 'ok' => $ok, 'code' => $code, 'error' => $error );
	}

	public static function retry_due(): void {
		global $wpdb;
		$table = SCP_Install::table( 'webhook_log' );
		$ids   = $wpdb->get_col(
			$wpdb->prepare( "SELECT id FROM {$table} WHERE status = 'pending' AND next_attempt_at <= %s ORDER BY id LIMIT 25", SCP_Helpers::mysql_now() )
		);
		foreach ( $ids as $id ) {
			self::deliver( (int) $id );
		}
	}

	/** Trimite un eveniment de test, cu o programare fictivă marcată clar ca test. */
	public static function send_test(): array {
		$packages = SCP_Settings::packages();
		$pkg      = array_key_first( $packages ) ?: 'foto';
		$date     = SCP_Helpers::now()->modify( '+7 days' )->format( 'Y-m-d' );
		$fake     = array(
			'id'                   => 0,
			'code'                 => 'TEST00',
			'token'                => str_repeat( '0', 32 ),
			'slot_date'            => $date,
			'slot_time'            => SCP_Settings::times()[0] ?? '11:00',
			'package'              => $pkg,
			'price'                => $packages[ $pkg ]['price'] ?? 0,
			'name'                 => 'Test Webhook',
			'email'                => 'test@example.com',
			'phone'                => '0700 000 000',
			'message'              => 'Eveniment de test trimis din setările pluginului.',
			'status'               => 'confirmed',
			'payment_status'       => 'unpaid',
			'payment_link_sent_at' => null,
			'paid_at'              => null,
			'order_id'             => null,
			'source'               => 'admin',
			'notes'                => '',
			'meta'                 => array(),
			'created_at'           => SCP_Helpers::mysql_now(),
			'updated_at'           => SCP_Helpers::mysql_now(),
		);
		global $wpdb;
		$delivery = SCP_Helpers::uuid();
		$wpdb->insert(
			SCP_Install::table( 'webhook_log' ),
			array(
				'delivery_id'     => $delivery,
				'event'           => 'test',
				'booking_id'      => null,
				'url'             => (string) SCP_Settings::get( 'webhook_url' ),
				'payload'         => wp_json_encode( self::payload( 'test', $fake, $delivery ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ),
				'status'          => 'pending',
				'attempts'        => self::MAX_ATTEMPTS - 1, // un test nu se reîncearcă automat
				'created_at'      => SCP_Helpers::mysql_now(),
				'next_attempt_at' => SCP_Helpers::mysql_now(),
			)
		);
		return self::deliver( (int) $wpdb->insert_id );
	}

	public static function log( int $limit = 100, int $booking_id = 0 ): array {
		global $wpdb;
		$table = SCP_Install::table( 'webhook_log' );
		if ( $booking_id ) {
			return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE booking_id = %d ORDER BY id DESC LIMIT %d", $booking_id, $limit ), ARRAY_A );
		}
		return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} ORDER BY id DESC LIMIT %d", $limit ), ARRAY_A );
	}
}
