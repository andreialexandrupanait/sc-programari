<?php
defined( 'ABSPATH' ) || exit;

/**
 * Webhook către n8n.
 *
 * Fiecare eveniment se salvează întâi în jurnal (status pending), apoi pleacă în fundal (Action Scheduler),
 * în ordinea în care s-a produs, fără să țină pe loc pagina sau plata. Dacă n8n nu răspunde cu 2xx,
 * cron-ul reîncearcă la 5, 15, 60 și 180 de minute. Din admin se poate retrimite manual.
 * Un rând e „sending” cât timp e trimis, ca două procese să nu-l trimită de două ori.
 *
 * Trimiterile pleacă strict una câte una (pump(), cu lock): n8n scrie în Sheet cu „Append or Update”,
 * iar execuții în paralel își suprascriu rândurile. Webhookul din n8n răspunde după ultimul nod,
 * deci următoarea trimitere pleacă abia după ce rândul anterior e scris.
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
		add_action( 'scp_webhook_deliver', array( __CLASS__, 'pump' ) );
		add_action( 'shutdown', array( __CLASS__, 'flush' ), 1 );
	}

	public static function enabled_for( string $event ): bool {
		$url = trim( (string) SCP_Settings::get( 'webhook_url' ) );
		return '' !== $url && in_array( $event, (array) SCP_Settings::get( 'webhook_events', array() ), true );
	}

	/** Coloanele din Google Sheet (tab-ul „Programari”), în ordine. Fără diacritice, ca în 2025. */
	/** Coloanele din tab-ul „Leads”, în ordine. Fără diacritice, ca în 2025. „Slot” e ascunsă (o folosesc formulele). */
	public static function sheet_columns(): array {
		return array( 'Inregistrata la', 'Cod', 'Nume', 'Telefon', 'Email', 'Pachet', 'Pret', 'Sedinta (data si ora)', 'Status', 'Plata', 'Sursa', 'Mesaj client', 'Observatii', 'Voucher', 'Termen plata', 'Platita la', 'Link plata', 'Modificata la', 'Slot' );
	}

	/**
	 * Rândul unei programări pentru Sheet (și pentru exportul CSV).
	 * Cheia de potrivire e „Cod”: fiecare programare are rândul ei, actualizat la orice schimbare.
	 * O programare anulată rămâne în Sheet cu Status „Anulată”; cea nouă de pe aceeași oră are alt cod, deci alt rând.
	 */
	public static function sheet_row( array $b ): array {
		$e      = SCP_Bookings::export( $b );
		$status = $e['status_label'];
		if ( 'cancelled' === $b['status'] ) {
			$status .= 'expired' === $b['cancel_reason'] ? ' (neplătită în termen)' : ' (din admin)';
		}
		$fmt = static fn( $v ) => $v ? ( new DateTimeImmutable( (string) $v, SCP_Helpers::tz() ) )->format( 'd.m.Y H:i' ) : '';
		$row = array(
			$fmt( $b['created_at'] ),
			$e['code'],
			$e['name'],
			$e['phone'],
			$e['email'],
			$e['package_label'],
			$e['price'],
			$e['date_ro'] . ' ' . $e['time'],
			$status,
			self::sheet_pay_label( $b ),
			$e['source_label'],
			$e['message'],
			$e['notes'],
			'gift' === $e['discount_source'] ? $e['discount_code'] : '',
			'pending' === $b['status'] ? $e['pay_by_ro'] : '',
			$fmt( $b['paid_at'] ?? '' ),
			'pending' === $b['status'] ? $e['payment_link'] : '',
			$fmt( $b['updated_at'] ),
			$b['slot_date'] . ' ' . $b['slot_time'],
		);
		return array_combine( self::sheet_columns(), $row );
	}

	/**
	 * Valorile fixe din coloana „Plata” (sunt și opțiunile din lista derulantă din Sheet).
	 * Termenul de plată are coloana lui, deci nu intră în text.
	 */
	const SHEET_PAY = array(
		'paid'     => 'Plătită online',
		'cash'     => 'Plătită cash la studio',
		'card'     => 'Plătită cu cardul la studio',
		'voucher'  => 'Plătită cu voucher',
		'awaiting' => 'Așteaptă plata online',
		'studio'   => 'Plată la studio',
		'unpaid'   => 'Neplătită',
	);

	private static function sheet_pay_label( array $b ): string {
		if ( isset( self::SHEET_PAY[ $b['payment_status'] ] ) && SCP_Bookings::is_paid( $b ) ) {
			return self::SHEET_PAY[ $b['payment_status'] ];
		}
		$key = SCP_Bookings::pay_state( $b )['key'];
		return self::SHEET_PAY[ $key ] ?? self::SHEET_PAY['unpaid'];
	}

	/**
	 * Rândul pentru tab-ul „Programari”: copia lui Leads în care Ștefan poate scrie.
	 * Aceleași coloane ca Leads, cu telefonul apelabil. Coloanele lui (Etapa, Nr. bon/factura,
	 * Link galerie) nu sunt în rând, deci n8n nu le atinge niciodată.
	 */
	public static function sc_row( array $b ): array {
		$row    = array_map( array( __CLASS__, 'cell_safe' ), self::sheet_row( $b ) );
		$digits = preg_replace( '/\D+/', '', (string) $row['Telefon'] );
		if ( '' !== $digits ) {
			// Fișierul are setări regionale ro_RO, deci separatorul din formulă e „;”.
			$tel            = str_starts_with( $digits, '0' ) ? '+4' . $digits : '+' . $digits;
			$row['Telefon'] = '=HYPERLINK("tel:' . $tel . '"; "' . str_replace( '"', '', $row['Telefon'] ) . '")';
		}
		return $row;
	}

	/**
	 * Un text scris de client care începe cu = + - @ ar fi interpretat ca formulă în Sheet sau Excel
	 * (de exemplu IMPORTXML, care ar trimite datele altor clienți în afară). Apostroful îl face text.
	 */
	public static function cell_safe( $v ) {
		return is_string( $v ) && '' !== $v && false !== strpos( "=+-@\t\r", $v[0] ) ? "'" . $v : $v;
	}

	public static function payload( string $event, array $b, string $delivery_id ): array {
		return array(
			'event'       => $event,
			'delivery_id' => $delivery_id,
			'sent_at'     => SCP_Helpers::now()->format( DATE_ATOM ),
			'site'        => home_url(),
			'booking'     => SCP_Bookings::export( $b ),
			// Rând gata de pus în Google Sheets (n8n: Append or Update Row, coloana de potrivire „Cod”).
			'tab'         => 'Programari',
			'sheet'       => self::sheet_row( $b ),
			// Rândul pentru „SC - Programari” (aceeași potrivire pe „Cod”).
			'sc'          => self::sc_row( $b ),
		);
	}

	public static function emit( string $event, array $b ): void {
		if ( ! self::enabled_for( $event ) ) {
			return;
		}
		// Starea din baza de date în acest moment (de ex. „link trimis”, marcat chiar înainte de emailul clientului).
		if ( ! empty( $b['id'] ) ) {
			$b = SCP_Bookings::get( (int) $b['id'] ) ?? $b;
		}
		self::log_event( $event, $b, true );
	}

	/**
	 * Pune evenimentul în jurnal. $now = true îl trimite la finalul cererii; altfel pleacă din cron,
	 * câte 25 pe rulare (resincronizarea completă nu blochează pagina și nu depășește limitele Google).
	 */
	private static function log_event( string $event, array $b, bool $now ): void {
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
		if ( $now ) {
			self::$queue[] = (int) $wpdb->insert_id;
		}
	}

	/**
	 * Retrimite toate programările (toate statusurile) către Sheet: umplerea inițială sau reparat Sheet-ul.
	 * Fiecare rând se potrivește pe „Cod”, deci nu se dublează nimic.
	 */
	public static function resync_all(): int {
		if ( '' === trim( (string) SCP_Settings::get( 'webhook_url' ) ) ) {
			return 0;
		}
		global $wpdb;
		$table = SCP_Install::table( 'webhook_log' );
		// O resincronizare anterioară încă în curs se înlocuiește, ca să nu trimitem de două ori.
		$wpdb->query( "UPDATE {$table} SET status = 'failed', last_error = 'Înlocuit de o resincronizare nouă', next_attempt_at = NULL WHERE event = 'booking.sync' AND status = 'pending'" ); // phpcs:ignore
		$n = 0;
		foreach ( self::all_bookings() as $b ) {
			self::log_event( 'booking.sync', $b, false );
			++$n;
		}
		// Pornește trimiterea în fundal acum, fără să aștepte cronul.
		if ( $n && function_exists( 'as_enqueue_async_action' ) && ! as_has_scheduled_action( 'scp_webhook_deliver', array(), 'scp' ) ) {
			as_enqueue_async_action( 'scp_webhook_deliver', array(), 'scp' );
		}
		return $n;
	}

	/** Toate rândurile, pentru verificarea de noapte din n8n (rescrie tab-ul cu starea reală). */
	public static function sheet_snapshot(): array {
		return array_map( array( __CLASS__, 'sheet_row' ), self::all_bookings() );
	}

	private static function all_bookings(): array {
		$all  = array();
		$page = 1;
		do {
			$q   = SCP_Bookings::query( array( 'view' => 'all', 'per_page' => 200, 'page' => $page, 'orderby' => 'slot' ) );
			$all = array_merge( $all, $q['items'] );
			++$page;
		} while ( count( $q['items'] ) === 200 );
		return $all;
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
			'tab'         => 'Vouchere',
			'sheet'       => array(
				'Cod voucher'  => $e['code'],
				'Pachet'       => $e['package_label'],
				'Valoare'      => $e['value'],
				'Cumparator'   => self::cell_safe( $e['buyer_name'] ),
				'Email'        => self::cell_safe( $e['buyer_email'] ),
				'Telefon'      => self::cell_safe( $e['buyer_phone'] ),
				'Pentru'       => self::cell_safe( $e['recipient_name'] ),
				'Email destinatar'   => self::cell_safe( $e['recipient_email'] ),
				'Telefon destinatar' => self::cell_safe( $e['recipient_phone'] ),
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

	/**
	 * Evenimentele din această cerere pleacă într-un singur job în fundal, în ordine.
	 * Fără Action Scheduler (WooCommerce oprit) se trimit pe loc, ca înainte.
	 */
	public static function flush(): void {
		if ( ! self::$queue ) {
			return;
		}
		self::$queue = array();
		if ( function_exists( 'as_enqueue_async_action' ) && apply_filters( 'scp_async', true ) ) {
			if ( ! as_has_scheduled_action( 'scp_webhook_deliver', array(), 'scp' ) ) {
				as_enqueue_async_action( 'scp_webhook_deliver', array(), 'scp' );
			}
			return;
		}
		self::pump();
	}

	/**
	 * Trimite, în ordine și una câte una, tot ce e de trimis (evenimente noi, reîncercări, resincronizare).
	 * Un singur proces o dată (lock); cel mult ~45 de secunde pe rulare; se oprește după 3 eșecuri la rând.
	 * Dacă mai rămân rânduri, programează încă o rulare în fundal.
	 */
	public static function pump(): void {
		global $wpdb;
		if ( ! add_option( 'scp_webhook_lock', time(), '', false ) ) {
			if ( (int) get_option( 'scp_webhook_lock' ) > time() - 120 ) {
				return; // trimite deja alt proces; el preia și rândurile noi
			}
			update_option( 'scp_webhook_lock', time(), false ); // lock rămas de la un proces căzut
		}
		$table = SCP_Install::table( 'webhook_log' );
		$left  = false;
		try {
			$start = microtime( true );
			$fails = 0;
			while ( microtime( true ) - $start < 45 && $fails < 3 ) {
				$id = (int) $wpdb->get_var(
					$wpdb->prepare( "SELECT id FROM {$table} WHERE status IN ('pending','sending') AND next_attempt_at <= %s ORDER BY id LIMIT 1", SCP_Helpers::mysql_now() )
				);
				if ( ! $id ) {
					break;
				}
				update_option( 'scp_webhook_lock', time(), false );
				$r     = self::deliver( $id );
				$fails = $r['ok'] ? 0 : $fails + 1;
				if ( null === $r['code'] && 'Se trimite deja.' === $r['error'] ) {
					break;
				}
			}
			$left = $fails < 3 && (bool) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE status IN ('pending','sending') AND next_attempt_at <= %s", SCP_Helpers::mysql_now() ) );
		} finally {
			delete_option( 'scp_webhook_lock' );
		}
		if ( $left && function_exists( 'as_enqueue_async_action' ) && apply_filters( 'scp_async', true ) && ! as_has_scheduled_action( 'scp_webhook_deliver', array(), 'scp' ) ) {
			as_enqueue_async_action( 'scp_webhook_deliver', array(), 'scp' );
		}
	}

	/** Compatibilitate: joburile programate de 1.3.0–1.3.4 aveau lista de id-uri ca argument. */
	public static function deliver_ids( $ids = array() ): void {
		self::pump();
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
		// Preluare atomică: un rând deja în curs de trimitere (de alt proces) nu se trimite încă o dată.
		$claimed = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET status = 'sending', next_attempt_at = %s WHERE id = %d AND ( status <> 'sending' OR next_attempt_at <= %s )",
				SCP_Helpers::now()->modify( '+10 minutes' )->format( 'Y-m-d H:i:s' ),
				$log_id,
				SCP_Helpers::mysql_now()
			)
		);
		if ( 1 !== (int) $claimed ) {
			return array( 'ok' => false, 'code' => null, 'error' => 'Se trimite deja.' );
		}

		$body = (string) $row['payload'];
		$ts   = (string) time();
		$sig  = hash_hmac( 'sha256', $ts . '.' . $body, (string) SCP_Settings::get( 'webhook_secret' ) );

		$res = wp_remote_post(
			$url,
			array(
				'timeout'   => 30, // n8n răspunde după ce a scris în Sheet
				'blocking'  => true,
				'headers'   => array(
					'Content-Type'    => 'application/json; charset=utf-8',
					'User-Agent'      => 'SC-Programari/' . SCP_VERSION . '; ' . home_url(),
					'X-SCP-Event'     => $row['event'],
					'X-SCP-Delivery'  => $row['delivery_id'],
					'X-SCP-Timestamp' => $ts,
					'X-SCP-Signature' => $sig,
					// Pentru Header Auth în n8n. Cheia aceasta doar scrie în Sheet; citirea (/sheet) are cheia ei.
					'X-SCP-Key'       => (string) SCP_Settings::get( 'webhook_secret' ),
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

	/**
	 * Reîncercările și resincronizarea. Cel mult ~40 de secunde pe rulare (cronul rulează în fiecare minut,
	 * iar rulările nu au voie să se suprapună); se oprește după 3 eșecuri la rând, când n8n e căzut.
	 */
	public static function retry_due(): void {
		self::pump();
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
			'list_price'           => $packages[ $pkg ]['price'] ?? 0,
			'discount'             => 0,
			'discount_code'        => '',
			'discount_source'      => '',
			'pay_by'               => null,
			'cancel_reason'        => '',
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
