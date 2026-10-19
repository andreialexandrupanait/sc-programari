<?php
defined( 'ABSPATH' ) || exit;

/**
 * SMS-urile automate către clienți: rezervare (cu link scurt de plată), plată, reamintire cu o zi înainte
 * și cu o oră înainte. Programarea și reîncercările merg prin Action Scheduler (vine cu WooCommerce),
 * care rulează din cronul real al serverului (DISABLE_WP_CRON + crontab).
 *
 * Reguli:
 *  - reamintirile se programează doar când programarea e sigură: plătită (online, cash, card, voucher)
 *    sau confirmată cu plata la studio;
 *  - la anulare se șterg, la mutarea slotului se reprogramează;
 *  - fiecare job verifică la rulare dacă programarea mai e valabilă, plătită și pe același slot;
 *  - fiecare mesaj are un singur rând în jurnal (index unic rezervare + tip), deci nu pleacă de două ori;
 *  - joburile ratate (cron oprit) se recuperează cât timp ședința n-a trecut.
 */
class SCP_SMS {

	const OPTION   = 'scp_sms_settings';
	const GROUP    = 'scp-sms';
	const TYPES    = array( 'created', 'pay_reminder', 'paid', 'moved', 'remind_day', 'remind_hour' );
	const BACKOFF  = array( 2, 10, 30, 60, 120 ); // minute între încercări
	const MAX_TRY  = 5;

	private static ?SCP_SMS_Provider $provider = null;

	/* ---------------------------------------------------------------- setări */

	public static function defaults(): array {
		return array(
			'enabled'          => 0,
			'test_mode'        => 0,  // pornit + test_mode = SMS-uri doar către test_numbers
			'test_numbers'     => '',
			'sender'           => '',
			'address'          => 'Str. Macedona 5, Galati',
			'remind_day_time'  => '18:00',
			'remind_hour_mins' => 60,
			'report_enabled'   => 1,
			'report_time'      => '18:00',
			'backup_email'     => 'fallback', // fallback = emailul pleacă doar dacă SMS-ul nu ajunge; always = mereu
			'alert_emails'     => '',
			'templates'        => array(
				'created'     => array( 'enabled' => 1, 'body' => 'Salut, {prenume}! Ti-am rezervat sedinta foto {ziua} {data}, ora {ora}. Plateste {termen} ca sa pastrezi ora: {link}' ),
				'pay_reminder' => array( 'enabled' => 1, 'body' => '{prenume}, plateste {termen}, ca ora ta de {ziua} {data}, {ora} sa nu ajunga la alta familie: {link} - Stefan Chelmu' ),
				'paid'        => array( 'enabled' => 1, 'body' => 'Salut, {prenume}! Plata a ajuns, sedinta foto de Craciun e confirmata: {ziua} {data}, ora {ora}, {adresa}. Te asteptam!' ),
				'voucher'     => array( 'enabled' => 1, 'body' => 'Salut, {prenume}! Sedinta foto de Craciun e confirmata cu voucherul tau: {ziua} {data}, ora {ora}, {adresa}. Te asteptam!' ),
				'studio'      => array( 'enabled' => 1, 'body' => 'Salut, {prenume}! Sedinta foto de Craciun e confirmata: {ziua} {data}, ora {ora}, {adresa}. Plata la studio, cash sau card.' ),
				'moved'       => array( 'enabled' => 1, 'body' => 'Salut, {prenume}! Sedinta foto de Craciun s-a mutat: {ziua} {data}, ora {ora}, {adresa}. Te asteptam!' ),
				'remind_day'  => array( 'enabled' => 1, 'body' => 'Salut, {prenume}! Te asteptam maine, {ziua} {data}, la ora {ora}, la sedinta foto de Craciun. {adresa}. Info: {telefon_studio}' ),
				'remind_hour' => array( 'enabled' => 1, 'body' => 'Ne vedem intr-o ora, la {ora}, la sedinta foto de Craciun. {adresa}. Daca intarzii, suna-ne la {telefon_studio}.' ),
			),
		);
	}

	private static ?array $settings = null;

	public static function settings(): array {
		if ( null !== self::$settings ) {
			return self::$settings;
		}
		$saved = get_option( self::OPTION, array() );
		$saved = is_array( $saved ) ? $saved : array();
		$def   = self::defaults();
		$out   = array_merge( $def, $saved );
		$out['templates'] = array_replace_recursive( $def['templates'], (array) ( $saved['templates'] ?? array() ) );
		self::$settings   = $out;
		return $out;
	}

	/** Numerele din lista de test (Programări → SMS), în format E.164. */
	public static function test_numbers(): array {
		$out = array();
		foreach ( preg_split( '/[,;\n]+/', (string) self::get( 'test_numbers' ) ) as $n ) {
			$e = self::e164( (string) $n );
			if ( strlen( $e ) >= 11 ) {
				$out[] = $e;
			}
		}
		return array_values( array_unique( $out ) );
	}

	public static function is_test_number( string $phone ): bool {
		return in_array( self::e164( $phone ), self::test_numbers(), true );
	}

	public static function get( string $key ) {
		return self::settings()[ $key ] ?? null;
	}

	public static function save( array $values ): void {
		update_option( self::OPTION, array_merge( self::settings(), $values ), true );
		self::$settings = null;
	}

	public static function provider(): SCP_SMS_Provider {
		if ( ! self::$provider ) {
			$p = new SCP_SMS_Provider_SMSO();
			self::$provider = $p->is_configured() ? $p : new SCP_SMS_Provider_Simulated();
		}
		return self::$provider;
	}

	public static function sender(): string {
		return defined( 'SCP_SMSO_SENDER' ) ? (string) SCP_SMSO_SENDER : (string) self::get( 'sender' );
	}

	public static function has_scheduler(): bool {
		return function_exists( 'as_schedule_single_action' );
	}

	/* ---------------------------------------------------------------- pornire */

	public static function init(): void {
		add_action( 'scp_booking_created', array( __CLASS__, 'on_created' ), 40 );
		add_action( 'scp_booking_paid', array( __CLASS__, 'on_paid' ), 40 );
		add_action( 'scp_booking_studio', array( __CLASS__, 'on_paid' ), 40 );
		add_action( 'scp_booking_cancelled', array( __CLASS__, 'on_cancelled' ), 40, 1 );
		add_action( 'scp_booking_rescheduled', array( __CLASS__, 'on_rescheduled' ), 40, 3 );
		add_action( 'scp_payment_last_call', array( __CLASS__, 'on_last_call' ), 40 );

		add_action( 'scp_sms_send', array( __CLASS__, 'job_send' ), 10, 3 );
		add_action( 'scp_sms_check', array( __CLASS__, 'job_check' ), 10, 1 );
		add_action( 'scp_sms_retry', array( __CLASS__, 'job_retry' ), 10, 1 );
		add_action( 'scp_sms_recover', array( __CLASS__, 'job_recover' ) );
		add_action( 'scp_sms_report', array( __CLASS__, 'job_report' ) );
		add_action( 'action_scheduler_init', array( __CLASS__, 'ensure_recurring' ) );

		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
		add_action( 'init', array( __CLASS__, 'short_link' ), 1 );
	}

	/** Recuperarea joburilor la 15 minute și raportul zilnic există mereu. */
	public static function ensure_recurring(): void {
		// Verificarea costă două interogări în tabela Action Scheduler: o facem cel mult o dată pe oră.
		if ( ! self::has_scheduler() || (int) get_option( 'scp_sms_recurring_at' ) > time() - HOUR_IN_SECONDS ) {
			return;
		}
		update_option( 'scp_sms_recurring_at', time(), true );
		if ( ! as_has_scheduled_action( 'scp_sms_recover', array(), self::GROUP ) ) {
			as_schedule_recurring_action( time() + 60, 15 * MINUTE_IN_SECONDS, 'scp_sms_recover', array(), self::GROUP );
		}
		self::schedule_report();
	}

	public static function schedule_report(): void {
		if ( ! self::has_scheduler() || ! self::get( 'report_enabled' ) ) {
			return;
		}
		if ( as_has_scheduled_action( 'scp_sms_report', array(), self::GROUP ) ) {
			return;
		}
		[ $h, $m ] = array_map( 'intval', explode( ':', (string) self::get( 'report_time' ) . ':0' ) );
		$next      = SCP_Helpers::now()->setTime( $h, $m );
		if ( $next <= SCP_Helpers::now() ) {
			$next = $next->modify( '+1 day' );
		}
		as_schedule_single_action( $next->getTimestamp(), 'scp_sms_report', array(), self::GROUP );
	}

	/* ---------------------------------------------------------------- evenimente din programări */

	private static function is_paid( array $b ): bool {
		return SCP_Bookings::is_paid( $b );
	}

	/** Plătită sau confirmată cu plata la studio: primește confirmarea și reamintirile. */
	private static function is_secured( array $b ): bool {
		return SCP_Bookings::is_secured( $b );
	}

	public static function on_created( array $b ): void {
		if ( 'cancelled' === $b['status'] ) {
			return;
		}
		if ( self::is_secured( $b ) ) {
			// voucher, plătită din admin sau cu plata la studio: direct confirmarea și reamintirile
			self::on_paid( $b );
			return;
		}
		if ( 'pending' === $b['status'] ) {
			self::queue( $b, 'created' );
		}
	}

	/** Ultimul apel la plată (din SCP_Bookings::sweep). După o prelungire a termenului, rândul din jurnal se refolosește. */
	public static function on_last_call( array $b ): void {
		if ( 'pending' !== $b['status'] || self::is_paid( $b ) ) {
			return;
		}
		// SMS-ul nu poate pleca deloc: trimitem direct emailul de rezervă
		$why = '';
		if ( ! self::template_on( 'pay_reminder' ) ) {
			$why = 'SMS-ul de ultim apel e oprit';
		} elseif ( self::get( 'test_mode' ) && ! self::is_test_number( (string) $b['phone'] ) ) {
			$why = 'Mod test';
		} elseif ( ! SCP_Helpers::is_ro_mobile( (string) $b['phone'] ) ) {
			$why = 'Număr din afara României';
		}
		if ( $why ) {
			SCP_Mailer::on_last_call( $b );
			return;
		}
		$row = self::row( (int) $b['id'], 'pay_reminder' );
		if ( $row && ! in_array( $row['status'], array( 'scheduled', 'queued', 'retry' ), true ) ) {
			self::reset_row( $row, 'termen prelungit' );
		}
		self::queue( $b, 'pay_reminder' );
	}

	public static function on_paid( array $b ): void {
		if ( 'cancelled' === $b['status'] ) {
			return;
		}
		self::queue( $b, 'paid' );
		self::schedule_reminders( $b );
	}

	public static function on_cancelled( array $b ): void {
		self::unschedule_reminders( $b, self::slot_key( $b ) );
		global $wpdb;
		if ( self::has_scheduler() ) {
			foreach ( (array) $wpdb->get_col( $wpdb->prepare( 'SELECT id FROM ' . self::table() . ' WHERE booking_id=%d', $b['id'] ) ) as $lid ) { // phpcs:ignore
				as_unschedule_all_actions( 'scp_sms_retry', array( (int) $lid ), self::GROUP );
			}
		}
		$wpdb->query( $wpdb->prepare( 'UPDATE ' . self::table() . " SET status='cancelled', updated_at=%s WHERE booking_id=%d AND status IN ('scheduled','queued','retry')", SCP_Helpers::mysql_now(), $b['id'] ) ); // phpcs:ignore
	}

	public static function on_rescheduled( array $b, array $before, bool $notify = false ): void {
		self::unschedule_reminders( $b, self::slot_key( $before ) );
		// mutată din admin cu „Anunță clientul”: SMS cu noua oră, de fiecare dată când se mută
		if ( $notify ) {
			$row = self::row( (int) $b['id'], 'moved' );
			if ( $row && $row['slot'] !== self::slot_key( $b ) && ! in_array( $row['status'], array( 'scheduled', 'queued', 'retry' ), true ) ) {
				self::reset_row( $row, 'mutată din nou' );
			}
			self::queue( $b, 'moved' );
		}
		if ( ! self::is_secured( $b ) ) {
			return;
		}
		// reamintirile pornesc din nou pentru noul slot; istoricul rămâne în rândul din jurnal
		foreach ( array( 'remind_day', 'remind_hour' ) as $type ) {
			$row = self::row( $b['id'], $type );
			if ( $row ) {
				self::reset_row( $row, 'mutată de pe ' . $row['slot'] );
			}
		}
		self::schedule_reminders( $b );
	}

	/* ---------------------------------------------------------------- programare joburi */

	public static function slot_key( array $b ): string {
		return $b['slot_date'] . ' ' . $b['slot_time'];
	}

	/** Momentul la care pleacă o reamintire, sau null dacă nu mai are sens. */
	public static function due( array $b, string $type ): ?DateTimeImmutable {
		$start = SCP_Helpers::dt( $b['slot_date'], $b['slot_time'] );
		if ( 'remind_day' === $type ) {
			[ $h, $m ] = array_map( 'intval', explode( ':', (string) self::get( 'remind_day_time' ) . ':0' ) );
			return SCP_Helpers::dt( $b['slot_date'] )->modify( '-1 day' )->setTime( $h, $m );
		}
		if ( 'remind_hour' === $type ) {
			return $start->modify( '-' . max( 15, (int) self::get( 'remind_hour_mins' ) ) . ' minutes' );
		}
		return null;
	}

	/** Până când mai are sens o reamintire: cea de „mâine” doar până la miezul nopții dinaintea ședinței. */
	public static function still_useful( array $b, string $type ): bool {
		$now   = SCP_Helpers::now();
		$start = SCP_Helpers::dt( $b['slot_date'], $b['slot_time'] );
		if ( 'remind_day' === $type ) {
			return $now < SCP_Helpers::dt( $b['slot_date'] );
		}
		return $now < $start;
	}

	private static function schedule_reminders( array $b ): void {
		foreach ( array( 'remind_day', 'remind_hour' ) as $type ) {
			if ( ! self::template_on( $type ) || ! self::still_useful( $b, $type ) ) {
				continue;
			}
			$due = self::due( $b, $type );
			$row = self::ensure_row( $b, $type, $due );
			if ( in_array( $row['status'], array( 'sent', 'dispatched', 'delivered' ), true ) ) {
				continue;
			}
			// reamintirea de „mâine” rezervată sub o zi înainte (după ora de trimitere) nu mai pleacă: confirmarea ține loc
			if ( 'remind_day' === $type && $due <= SCP_Helpers::now() ) {
				self::set_status( (int) $row['id'], 'skipped', 'Rezervată după ora reamintirii de cu o zi înainte' );
				continue;
			}
			$args = array( (int) $b['id'], $type, self::slot_key( $b ) );
			if ( self::has_scheduler() && ! as_has_scheduled_action( 'scp_sms_send', $args, self::GROUP ) ) {
				as_schedule_single_action( max( time() + 30, $due->getTimestamp() ), 'scp_sms_send', $args, self::GROUP );
			}
		}
	}

	private static function unschedule_reminders( array $b, string $slot ): void {
		if ( ! self::has_scheduler() ) {
			return;
		}
		foreach ( array( 'remind_day', 'remind_hour' ) as $type ) {
			as_unschedule_all_actions( 'scp_sms_send', array( (int) $b['id'], $type, $slot ), self::GROUP );
		}
	}

	/** Trimiterile imediate (rezervare, plată) pleacă în fundal, ca să nu încetinească pagina. */
	private static function queue( array $b, string $type ): void {
		if ( ! self::template_on( $type ) ) {
			return;
		}
		$row = self::ensure_row( $b, $type, SCP_Helpers::now() );
		if ( ! in_array( $row['status'], array( 'scheduled', 'queued', 'retry' ), true ) ) {
			return;
		}
		$args = array( (int) $b['id'], $type, self::slot_key( $b ) );
		if ( self::has_scheduler() ) {
			if ( ! as_has_scheduled_action( 'scp_sms_send', $args, self::GROUP ) ) {
				as_enqueue_async_action( 'scp_sms_send', $args, self::GROUP );
			}
		} else {
			self::job_send( (int) $b['id'], $type, self::slot_key( $b ) );
		}
	}

	private static function template_on( string $type ): bool {
		if ( ! self::get( 'enabled' ) ) {
			return false;
		}
		$t = self::get( 'templates' );
		if ( 'paid' === $type ) {
			return ! empty( $t['paid']['enabled'] ) || ! empty( $t['voucher']['enabled'] ) || ! empty( $t['studio']['enabled'] );
		}
		return ! empty( $t[ $type ]['enabled'] );
	}

	/* ---------------------------------------------------------------- jurnal */

	public static function table(): string {
		return SCP_Install::table( 'sms_log' );
	}

	public static function row( int $booking_id, string $type ): ?array {
		global $wpdb;
		$r = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE booking_id=%d AND type=%s', $booking_id, $type ), ARRAY_A ); // phpcs:ignore
		return $r ?: null;
	}

	public static function row_by_id( int $id ): ?array {
		global $wpdb;
		$r = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE id=%d', $id ), ARRAY_A ); // phpcs:ignore
		return $r ?: null;
	}

	private static function ensure_row( array $b, string $type, ?DateTimeImmutable $when ): array {
		global $wpdb;
		$row = self::row( (int) $b['id'], $type );
		if ( $row ) {
			if ( $row['slot'] !== self::slot_key( $b ) && in_array( $row['status'], array( 'scheduled', 'queued', 'retry', 'cancelled', 'skipped' ), true ) ) {
				self::reset_row( $row, 'slot nou' );
				$row = self::row( (int) $b['id'], $type );
			}
			if ( 'cancelled' === $row['status'] ) {
				self::reset_row( $row, 'reactivată' );
				$row = self::row( (int) $b['id'], $type );
			}
			$wpdb->update( self::table(), array( 'slot' => self::slot_key( $b ), 'scheduled_for' => $when ? $when->format( 'Y-m-d H:i:s' ) : null, 'phone' => self::e164( (string) $b['phone'] ) ), array( 'id' => $row['id'] ) );
			return self::row_by_id( (int) $row['id'] );
		}
		$now = SCP_Helpers::mysql_now();
		$wpdb->suppress_errors( true );
		$wpdb->insert(
			self::table(),
			array(
				'booking_id'    => (int) $b['id'],
				'type'          => $type,
				'phone'         => self::e164( (string) $b['phone'] ),
				'status'        => 'scheduled',
				'provider'      => self::provider()->id(),
				'slot'          => self::slot_key( $b ),
				'scheduled_for' => $when ? $when->format( 'Y-m-d H:i:s' ) : null,
				'created_at'    => $now,
				'updated_at'    => $now,
			)
		);
		$wpdb->suppress_errors( false );
		return self::row( (int) $b['id'], $type );
	}

	private static function reset_row( array $row, string $why ): void {
		global $wpdb;
		$hist   = json_decode( (string) $row['history'], true ) ?: array();
		$hist[] = array( 'at' => SCP_Helpers::mysql_now(), 'why' => $why, 'status' => $row['status'], 'slot' => $row['slot'], 'provider_id' => $row['provider_id'], 'sent_at' => $row['sent_at'] );
		$wpdb->update(
			self::table(),
			array( 'status' => 'scheduled', 'provider_id' => '', 'attempts' => 0, 'http_code' => null, 'last_error' => '', 'sent_at' => null, 'delivered_at' => null, 'mcc' => '', 'mnc' => '', 'backup_email_at' => null, 'history' => wp_json_encode( array_slice( $hist, -10 ) ), 'updated_at' => SCP_Helpers::mysql_now() ),
			array( 'id' => $row['id'] )
		);
	}

	private static function set_status( int $id, string $status, string $error = '', array $extra = array() ): void {
		global $wpdb;
		$wpdb->update( self::table(), array_merge( array( 'status' => $status, 'last_error' => mb_substr( $error, 0, 250 ), 'updated_at' => SCP_Helpers::mysql_now() ), $extra ), array( 'id' => $id ) );
	}

	/* ---------------------------------------------------------------- texte */

	/** Telefon în format E.164 (+407xxxxxxxx). */
	public static function e164( string $raw ): string {
		$d = preg_replace( '/\D+/', '', $raw );
		if ( str_starts_with( $d, '0040' ) ) {
			$d = substr( $d, 2 );
		} elseif ( str_starts_with( $d, '00' ) ) {
			$d = substr( $d, 2 );
		} elseif ( str_starts_with( $d, '0' ) && 10 === strlen( $d ) ) {
			$d = '40' . substr( $d, 1 );
		}
		return $d ? '+' . $d : '';
	}

	/** Fără diacritice și fără caractere care ar trece mesajul pe Unicode (70 de caractere în loc de 160). */
	public static function gsm( string $s ): string {
		$s = strtr(
			$s,
			array(
				'ă' => 'a', 'â' => 'a', 'î' => 'i', 'ș' => 's', 'ş' => 's', 'ț' => 't', 'ţ' => 't',
				'Ă' => 'A', 'Â' => 'A', 'Î' => 'I', 'Ș' => 'S', 'Ş' => 'S', 'Ț' => 'T', 'Ţ' => 'T',
				'–' => '-', '—' => '-', '„' => '"', '”' => '"', '“' => '"', '’' => "'", '‘' => "'", '…' => '...', '·' => '-', ' ' => ' ',
			)
		);
		if ( function_exists( 'iconv' ) ) {
			$t = @iconv( 'UTF-8', 'ASCII//TRANSLIT//IGNORE', $s ); // phpcs:ignore
			if ( false !== $t ) {
				$s = $t;
			}
		}
		$s = preg_replace( '/[^\x20-\x7E\n]/', '', $s );
		return trim( preg_replace( '/[ \t]+/', ' ', $s ) );
	}

	/** Lungimea în caractere GSM-7 (cele extinse contează dublu). */
	public static function length( string $s ): int {
		return strlen( $s ) + preg_match_all( '/[\[\]{}\\\\^~|]/', $s );
	}

	public static function short_url( array $b ): string {
		$host = (string) wp_parse_url( home_url(), PHP_URL_HOST );
		return preg_replace( '/^www\./', '', $host ) . '/p/' . substr( (string) $b['token'], 0, 10 );
	}

	public static function vars( array $b ): array {
		$days  = array( 1 => 'luni', 'marti', 'miercuri', 'joi', 'vineri', 'sambata', 'duminica' );
		$first = trim( (string) ( preg_split( '/\s+/', trim( (string) $b['name'] ) )[1] ?? '' ) );
		if ( '' === $first ) {
			$first = trim( (string) ( preg_split( '/\s+/', trim( (string) $b['name'] ) )[0] ?? '' ) );
		}
		$termen = 'inainte de sedinta';
		if ( ! empty( $b['pay_by'] ) ) {
			$pb     = new DateTimeImmutable( $b['pay_by'], SCP_Helpers::tz() );
			$termen = 'pana ' . $days[ (int) $pb->format( 'N' ) ] . ' ' . $pb->format( 'd.m' ) . ', ora ' . $pb->format( 'H:i' );
		}
		$d = SCP_Helpers::dt( $b['slot_date'] );
		return array(
			'{prenume}'        => $first,
			'{ziua}'           => $days[ (int) $d->format( 'N' ) ],
			'{data}'           => $d->format( 'd.m' ),
			'{ora}'            => $b['slot_time'],
			'{termen}'         => $termen,
			'{link}'           => self::short_url( $b ),
			'{adresa}'         => (string) self::get( 'address' ),
			'{telefon_studio}' => str_replace( ' ', '', (string) SCP_Settings::get( 'studio_phone' ) ),
			'{cod}'            => $b['code'],
		);
	}

	public static function template_for( array $b, string $type ): string {
		$t   = self::get( 'templates' );
		$key = $type;
		if ( 'paid' === $type && 'voucher' === $b['payment_status'] ) {
			$key = 'voucher';
		} elseif ( 'paid' === $type && ! empty( $b['status'] ) && SCP_Bookings::pays_at_studio( $b ) ) {
			$key = 'studio';
		}
		return (string) ( $t[ $key ]['body'] ?? '' );
	}

	/** Textul final, mereu sub 160 de caractere: întâi se renunță la prenume, apoi se taie. */
	public static function render( string $tpl, array $b ): string {
		$vars = self::vars( $b );
		$txt  = self::gsm( strtr( $tpl, $vars ) );
		if ( self::length( $txt ) > 160 ) {
			$vars['{prenume}'] = '';
			$txt               = self::gsm( preg_replace( array( '/^(Salut|Buna|Bun[aă] ziua),\s*!\s*/u', '/^,\s*/' ), array( '$1! ', '' ), strtr( $tpl, $vars ) ) );
		}
		while ( self::length( $txt ) > 160 ) {
			$txt = rtrim( mb_substr( $txt, 0, -1 ) );
		}
		return $txt;
	}

	/** Exemplu pentru pagina de setări: cele mai lungi valori plauzibile. */
	public static function sample_booking(): array {
		return array(
			'id' => 0, 'name' => 'Popescu Alexandra', 'phone' => '0722 123 456', 'slot_date' => '2026-11-25', 'slot_time' => '17:00',
			'pay_by' => '2026-11-23 15:14:00', 'token' => '26af5aa0f86e5a9d2795511d0e423a4d', 'code' => '5V9HTD', 'payment_status' => 'paid',
		);
	}

	/* ---------------------------------------------------------------- trimitere */

	public static function webhook_url( int $log_id ): string {
		return add_query_arg( array( 'm' => $log_id, 't' => self::token( $log_id ) ), rest_url( SCP_Rest::NS . '/sms/status' ) );
	}

	private static function secret(): string {
		return defined( 'SCP_SMS_WEBHOOK_SECRET' ) ? (string) SCP_SMS_WEBHOOK_SECRET : wp_salt( 'auth' ) . '|scp-sms';
	}

	public static function token( int $log_id ): string {
		return substr( hash_hmac( 'sha256', 'sms-status|' . $log_id, self::secret() ), 0, 32 );
	}

	/** Jobul de trimitere: verifică totul din nou, pentru că între programare și rulare se pot schimba multe. */
	public static function job_send( $booking_id, $type, $slot = '' ): void {
		$booking_id = (int) $booking_id;
		$b          = SCP_Bookings::get( $booking_id );
		$row        = self::row( $booking_id, (string) $type );
		if ( ! $b || ! $row ) {
			return;
		}
		if ( in_array( $row['status'], array( 'sent', 'dispatched', 'delivered', 'cancelled' ), true ) ) {
			return;
		}
		$skip = '';
		if ( ! self::get( 'enabled' ) ) {
			$skip = 'SMS-urile sunt oprite';
		} elseif ( 'cancelled' === $b['status'] ) {
			$skip = 'Programarea e anulată';
		} elseif ( self::get( 'test_mode' ) && ! self::is_test_number( (string) $b['phone'] ) ) {
			$skip = 'Mod test: SMS-urile pleacă doar către numerele de test';
		} elseif ( ! SCP_Helpers::is_ro_mobile( (string) $b['phone'] ) ) {
			$skip = 'Număr din afara României: SMS-urile automate pleacă doar pe +407…';
		} elseif ( $slot && $slot !== self::slot_key( $b ) ) {
			$skip = 'Programarea a fost mutată';
		} elseif ( 'created' === $type && ( 'pending' !== $b['status'] || self::is_paid( $b ) ) ) {
			$skip = 'Programarea nu mai așteaptă plata';
		} elseif ( 'pay_reminder' === $type && ( 'pending' !== $b['status'] || self::is_paid( $b ) || empty( $b['pay_by'] ) || SCP_Helpers::mysql_now() >= $b['pay_by'] ) ) {
			$skip = 'Programarea nu mai așteaptă plata sau termenul a trecut';
		} elseif ( in_array( $type, array( 'paid', 'remind_day', 'remind_hour' ), true ) && ! self::is_secured( $b ) ) {
			$skip = 'Programarea nu e plătită și nici confirmată cu plata la studio';
		} elseif ( in_array( $type, array( 'remind_day', 'remind_hour' ), true ) && ! self::still_useful( $b, $type ) ) {
			$skip = 'Ședința a trecut sau e prea aproape';
		} elseif ( in_array( $type, array( 'paid', 'moved' ), true ) && SCP_Helpers::now() >= SCP_Helpers::dt( $b['slot_date'], $b['slot_time'] ) ) {
			$skip = 'Ședința a trecut';
		}
		if ( $skip ) {
			self::set_status( (int) $row['id'], 'cancelled' === $b['status'] ? 'cancelled' : 'skipped', $skip );
			return;
		}

		$tpl  = self::template_for( $b, (string) $type );
		$body = self::render( $tpl, $b );
		$to   = self::e164( (string) $b['phone'] );
		if ( strlen( $to ) < 11 ) {
			self::set_status( (int) $row['id'], 'failed', 'Număr de telefon invalid', array( 'body' => $body ) );
			self::after_failure( $b, $row );
			return;
		}

		if ( in_array( $type, array( 'remind_day', 'remind_hour' ), true ) && 'always' === self::get( 'backup_email' ) ) {
			self::backup_email( $b, (string) $type, (int) $row['id'] );
		}
		self::deliver( (int) $row['id'], $to, $body, $b );
	}

	private static function deliver( int $log_id, string $to, string $body, ?array $b ): void {
		global $wpdb;
		$row      = self::row_by_id( $log_id );
		$attempts = (int) $row['attempts'] + 1;
		$p        = self::provider();
		$res      = $p->send( $to, $body, array( 'sender' => self::sender(), 'webhook' => self::webhook_url( $log_id ) ) );
		$common   = array( 'phone' => $to, 'body' => $body, 'attempts' => $attempts, 'http_code' => $res['code'] ?: null, 'provider' => $p->id(), 'updated_at' => SCP_Helpers::mysql_now() );

		if ( $res['ok'] ) {
			$wpdb->update( self::table(), array_merge( $common, array( 'status' => 'sent', 'provider_id' => $res['id'], 'cost' => $res['cost'], 'last_error' => '', 'sent_at' => SCP_Helpers::mysql_now() ) ), array( 'id' => $log_id ) );
			if ( self::has_scheduler() ) {
				as_schedule_single_action( time() + 10 * MINUTE_IN_SECONDS, 'scp_sms_check', array( $log_id ), self::GROUP );
			}
			return;
		}

		if ( $res['alert'] ) {
			self::alert(
				$res['alert'],
				'auth' === $res['alert'] ? 'SMS-urile nu pleacă: cheia SMSO nu e validă' : 'SMS-urile nu pleacă: credit SMSO insuficient',
				'auth' === $res['alert']
					? 'SMSO a respins cheia API (401). Verifică SCP_SMSO_API_KEY în wp-config.php. Mesajele rămân în așteptare și pleacă după ce cheia e corectă.'
					: 'SMSO a răspuns că nu mai e credit (402). Reîncarcă contul SMSO; mesajele în așteptare se reîncearcă automat.'
			);
		}

		$retry = $res['retry'] && $attempts < self::MAX_TRY;
		if ( 'auth' === $res['alert'] ) {
			$retry = $attempts < 3; // poate fi o cheie schimbată chiar acum
		}
		$wpdb->update( self::table(), array_merge( $common, array( 'status' => $retry ? 'retry' : 'failed', 'last_error' => $res['error'] ) ), array( 'id' => $log_id ) );

		if ( $retry && self::has_scheduler() && $row['booking_id'] ) {
			$delay = self::BACKOFF[ min( $attempts - 1, count( self::BACKOFF ) - 1 ) ];
			if ( 'credit' === $res['alert'] ) {
				$delay = 60;
			}
			// jobul curent e încă „în lucru”, așa că reîncercarea are propriul hook, legat de rândul din jurnal
			as_unschedule_all_actions( 'scp_sms_retry', array( $log_id ), self::GROUP );
			as_schedule_single_action( time() + $delay * MINUTE_IN_SECONDS, 'scp_sms_retry', array( $log_id ), self::GROUP );
			return;
		}
		if ( ! $retry ) {
			if ( $b ) {
				self::after_failure( $b, self::row_by_id( $log_id ) );
			}
			self::check_failure_rate();
		}
	}

	public static function job_retry( $log_id ): void {
		$row = self::row_by_id( (int) $log_id );
		if ( $row && 'retry' === $row['status'] && $row['booking_id'] ) {
			self::job_send( (int) $row['booking_id'], (string) $row['type'], (string) $row['slot'] );
		}
	}

	/** Mesajul nu a ajuns: pentru reamintiri pleacă emailul de rezervă. */
	private static function after_failure( array $b, array $row ): void {
		if ( in_array( $row['type'], array( 'remind_day', 'remind_hour' ), true ) && empty( $row['backup_email_at'] ) && self::still_useful( $b, $row['type'] ) ) {
			self::backup_email( $b, $row['type'], (int) $row['id'] );
		}
		// ultimul apel nu a ajuns: emailul de rezervă (doar cât programarea încă așteaptă plata)
		if ( 'pay_reminder' === $row['type'] && empty( $row['backup_email_at'] ) && SCP_Mailer::on_last_call( $b ) ) {
			global $wpdb;
			$wpdb->update( self::table(), array( 'backup_email_at' => SCP_Helpers::mysql_now() ), array( 'id' => (int) $row['id'] ) );
		}
	}

	private static function backup_email( array $b, string $type, int $log_id ): void {
		$row = self::row_by_id( $log_id );
		if ( ! $row || ! empty( $row['backup_email_at'] ) || ! is_email( $b['email'] ) ) {
			return;
		}
		$ok = SCP_Mailer::send_template( 'remind_day' === $type ? 'session_reminder_day' : 'session_reminder_hour', $b, $b['email'], array( 'force' => true ) );
		if ( $ok ) {
			global $wpdb;
			$wpdb->update( self::table(), array( 'backup_email_at' => SCP_Helpers::mysql_now() ), array( 'id' => $log_id ) );
		}
	}

	/* ---------------------------------------------------------------- statusuri */

	private static function apply_status( array $row, array $st ): void {
		global $wpdb;
		if ( ! $st['ok'] || '' === $st['status'] ) {
			return;
		}
		$map    = array( 'dispatched' => 'dispatched', 'sent' => 'dispatched', 'delivered' => 'delivered', 'undelivered' => 'undelivered', 'expired' => 'expired', 'error' => 'error' );
		$status = $map[ $st['status'] ] ?? $row['status'];
		$set    = array( 'status' => $status, 'updated_at' => SCP_Helpers::mysql_now() );
		if ( ! empty( $st['delivered_at'] ) ) {
			$set['delivered_at'] = get_date_from_gmt( $st['delivered_at'] );
		}
		if ( ! empty( $st['mcc'] ) ) {
			$set['mcc'] = substr( (string) $st['mcc'], 0, 4 );
		}
		if ( ! empty( $st['mnc'] ) ) {
			$set['mnc'] = substr( (string) $st['mnc'], 0, 4 );
		}
		$wpdb->update( self::table(), $set, array( 'id' => $row['id'] ) );

		if ( in_array( $status, array( 'undelivered', 'expired', 'error' ), true ) && $row['booking_id'] ) {
			$b = SCP_Bookings::get( (int) $row['booking_id'] );
			if ( $b ) {
				self::after_failure( $b, self::row_by_id( (int) $row['id'] ) );
			}
			self::check_failure_rate();
		}
	}

	/** Verificare de rezervă, pentru cazul în care webhookul nu ajunge (de exemplu pe Local). */
	public static function job_check( $log_id ): void {
		$row = self::row_by_id( (int) $log_id );
		if ( ! $row || ! $row['provider_id'] || in_array( $row['status'], array( 'delivered', 'undelivered', 'expired', 'error' ), true ) ) {
			return;
		}
		$st = self::provider()->status( (string) $row['provider_id'] );
		self::apply_status( $row, $st );
		$row = self::row_by_id( (int) $log_id );
		$age = $row['sent_at'] ? time() - ( new DateTimeImmutable( (string) $row['sent_at'], SCP_Helpers::tz() ) )->getTimestamp() : 0;
		if ( ! in_array( $row['status'], array( 'delivered', 'undelivered', 'expired', 'error' ), true ) && $age < 3 * HOUR_IN_SECONDS && self::has_scheduler() ) {
			as_schedule_single_action( time() + 30 * MINUTE_IN_SECONDS, 'scp_sms_check', array( (int) $log_id ), self::GROUP );
		}
	}

	public static function routes(): void {
		register_rest_route(
			SCP_Rest::NS,
			'/sms/status',
			array(
				'methods'             => array( 'POST', 'GET' ),
				'callback'            => array( __CLASS__, 'webhook' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	public static function webhook( WP_REST_Request $request ) {
		$id  = (int) $request->get_param( 'm' );
		$tok = (string) $request->get_param( 't' );
		if ( ! $id || ! hash_equals( self::token( $id ), $tok ) ) {
			return new WP_REST_Response( array( 'ok' => false ), 403 );
		}
		$row = self::row_by_id( $id );
		if ( ! $row ) {
			return new WP_REST_Response( array( 'ok' => false ), 404 );
		}
		$st = self::provider()->parse_webhook( $request );
		if ( $st['id'] && $row['provider_id'] && ! hash_equals( (string) $row['provider_id'], (string) $st['id'] ) ) {
			return new WP_REST_Response( array( 'ok' => false, 'error' => 'uuid' ), 409 );
		}
		self::apply_status( $row, $st );
		return new WP_REST_Response( array( 'ok' => true ), 200 );
	}

	/* ---------------------------------------------------------------- recuperare, raport, alerte */

	/** La 15 minute: refacem joburile lipsă și trimitem ce a fost ratat, cât timp ședința n-a trecut. */
	public static function job_recover(): void {
		update_option( 'scp_sms_heartbeat', time(), false );
		if ( ! self::get( 'enabled' ) ) {
			return;
		}
		global $wpdb;
		$from = SCP_Helpers::now()->format( 'Y-m-d' );
		$to   = SCP_Helpers::now()->modify( '+3 days' )->format( 'Y-m-d' );
		$list = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . SCP_Install::table( 'bookings' ) . " WHERE status <> 'cancelled' AND slot_date BETWEEN %s AND %s", $from, $to ), ARRAY_A ); // phpcs:ignore
		foreach ( (array) $list as $b ) {
			if ( ! self::is_secured( $b ) ) {
				continue;
			}
			foreach ( array( 'remind_day', 'remind_hour' ) as $type ) {
				if ( ! self::template_on( $type ) || ! self::still_useful( $b, $type ) ) {
					continue;
				}
				$row = self::row( (int) $b['id'], $type );
				if ( $row && in_array( $row['status'], array( 'sent', 'dispatched', 'delivered', 'undelivered', 'expired', 'error', 'failed', 'skipped', 'cancelled' ), true ) ) {
					continue;
				}
				$args = array( (int) $b['id'], $type, self::slot_key( $b ) );
				if ( as_has_scheduled_action( 'scp_sms_send', $args, self::GROUP ) ) {
					continue;
				}
				$due = self::due( $b, $type );
				if ( ! $row ) {
					if ( 'remind_day' === $type && $due <= SCP_Helpers::now() ) {
						continue; // n-a fost niciodată programată, pentru că plata a venit după ora reamintirii
					}
					self::ensure_row( $b, $type, $due );
				}
				as_schedule_single_action( max( time() + 30, $due->getTimestamp() ), 'scp_sms_send', $args, self::GROUP );
			}
		}
		// reîncercări rămase fără job (de exemplu după o oprire a cronului)
		foreach ( (array) $wpdb->get_col( 'SELECT id FROM ' . self::table() . " WHERE status='retry' AND booking_id > 0" ) as $lid ) { // phpcs:ignore
			if ( ! as_has_scheduled_action( 'scp_sms_retry', array( (int) $lid ), self::GROUP ) ) {
				as_schedule_single_action( time() + 60, 'scp_sms_retry', array( (int) $lid ), self::GROUP );
			}
		}
		// mesaje trimise de peste 15 minute fără status final: întrebăm furnizorul
		$stale = $wpdb->get_col( $wpdb->prepare( 'SELECT id FROM ' . self::table() . " WHERE status IN ('sent','dispatched') AND provider_id <> '' AND sent_at < %s AND sent_at > %s", SCP_Helpers::now()->modify( '-15 minutes' )->format( 'Y-m-d H:i:s' ), SCP_Helpers::now()->modify( '-1 day' )->format( 'Y-m-d H:i:s' ) ) ); // phpcs:ignore
		foreach ( (array) $stale as $id ) {
			if ( ! as_has_scheduled_action( 'scp_sms_check', array( (int) $id ), self::GROUP ) ) {
				as_enqueue_async_action( 'scp_sms_check', array( (int) $id ), self::GROUP );
			}
		}
		self::schedule_report();
	}

	public static function alert_recipients(): array {
		$list = (string) self::get( 'alert_emails' );
		if ( '' === trim( $list ) ) {
			$list = (string) SCP_Settings::get( 'admin_emails' );
		}
		return array_values( array_filter( array_map( 'trim', preg_split( '/[\s,;]+/', $list ) ), 'is_email' ) );
	}

	private static function mail( string $subject, string $html ): bool {
		$to = self::alert_recipients();
		if ( ! $to ) {
			return false;
		}
		$headers = array( 'Content-Type: text/html; charset=UTF-8' );
		$from    = sanitize_email( (string) SCP_Settings::get( 'from_email' ) );
		if ( $from ) {
			$headers[] = 'From: "' . str_replace( '"', '', (string) SCP_Settings::get( 'from_name' ) ?: 'Programări' ) . '" <' . $from . '>';
		}
		$wrap = '<div style="font-family:Arial,sans-serif;font-size:15px;line-height:1.5;color:#2b2925;max-width:720px">' . $html . '</div>';
		return (bool) wp_mail( $to, $subject, $wrap, $headers );
	}

	/** O alertă de același tip cel mult o dată pe oră. */
	public static function alert( string $key, string $subject, string $text ): void {
		if ( get_transient( 'scp_sms_alert_' . $key ) ) {
			return;
		}
		set_transient( 'scp_sms_alert_' . $key, 1, HOUR_IN_SECONDS );
		self::mail( '[SMS] ' . $subject, '<p>' . esc_html( $text ) . '</p><p><a href="' . esc_url( admin_url( 'admin.php?page=scp-sms' ) ) . '">Deschide pagina SMS</a></p>' );
	}

	/** Trei sau mai multe eșecuri în ultima oră: anunțăm. */
	private static function check_failure_rate(): void {
		global $wpdb;
		$n = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . self::table() . " WHERE status IN ('failed','undelivered','expired','error') AND booking_id > 0 AND updated_at > %s", SCP_Helpers::now()->modify( '-1 hour' )->format( 'Y-m-d H:i:s' ) ) ); // phpcs:ignore
		if ( $n >= 3 ) {
			self::alert( 'repeated', $n . ' SMS-uri au eșuat în ultima oră', 'În ultima oră, ' . $n . ' mesaje nu au putut fi trimise sau nu au ajuns la clienți. Pentru reamintiri a plecat emailul de rezervă. Verifică jurnalul SMS.' );
		}
	}

	public static function status_label( string $s ): string {
		$l = array(
			'scheduled' => 'Programat', 'queued' => 'În coadă', 'retry' => 'Se reîncearcă', 'sent' => 'Trimis', 'dispatched' => 'În drum',
			'delivered' => 'Livrat', 'undelivered' => 'Nelivrat', 'expired' => 'Expirat', 'error' => 'Eroare la operator', 'failed' => 'Eșuat',
			'skipped' => 'Nu a mai fost nevoie', 'cancelled' => 'Anulat',
		);
		return $l[ $s ] ?? $s;
	}

	public static function type_label( string $t ): string {
		$l = array( 'created' => 'Rezervare', 'pay_reminder' => 'Ultimul apel la plată', 'paid' => 'Confirmare', 'moved' => 'Mutare', 'remind_day' => 'Cu o zi înainte', 'remind_hour' => 'Cu o oră înainte' );
		if ( preg_match( '/^proba-\d+-(.+)$/', $t, $m ) ) {
			return 'Probă: ' . ( self::SAMPLES[ $m[1] ] ?? $m[1] );
		}
		return $l[ $t ] ?? ( str_starts_with( $t, 'test-' ) ? 'Test livrare' : $t );
	}

	/** Raportul zilnic: ședințele de mâine și starea SMS-urilor. */
	public static function job_report(): void {
		self::schedule_report_next();
		global $wpdb;
		$tomorrow = SCP_Helpers::now()->modify( '+1 day' )->format( 'Y-m-d' );
		$list     = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . SCP_Install::table( 'bookings' ) . " WHERE slot_date=%s AND status <> 'cancelled' ORDER BY slot_time", $tomorrow ), ARRAY_A ); // phpcs:ignore
		$pk       = SCP_Settings::packages();
		$rows     = '';
		foreach ( (array) $list as $b ) {
			$cells = array();
			foreach ( array( 'paid', 'remind_day', 'remind_hour' ) as $t ) {
				$r       = self::row( (int) $b['id'], $t );
				$cells[] = $r ? self::status_label( $r['status'] ) . ( $r['backup_email_at'] ? ' + email' : '' ) : '–';
			}
			$pay   = SCP_Bookings::pays_at_studio( $b ) ? 'De încasat la studio' : ( SCP_Helpers::PAYMENT_STATUSES[ $b['payment_status'] ] ?? $b['payment_status'] );
			$rows .= '<tr><td style="padding:6px 8px;border-bottom:1px solid #eee"><b>' . esc_html( $b['slot_time'] ) . '</b></td><td style="padding:6px 8px;border-bottom:1px solid #eee">' . esc_html( $b['name'] ) . '<br><small>' . esc_html( $b['phone'] ) . '</small></td><td style="padding:6px 8px;border-bottom:1px solid #eee">' . esc_html( $pk[ $b['package'] ]['label'] ?? $b['package'] ) . '</td><td style="padding:6px 8px;border-bottom:1px solid #eee">' . esc_html( is_array( $pay ) ? ( $pay['label'] ?? '' ) : $pay ) . '</td><td style="padding:6px 8px;border-bottom:1px solid #eee">' . implode( '</td><td style="padding:6px 8px;border-bottom:1px solid #eee">', array_map( 'esc_html', $cells ) ) . '</td></tr>';
		}
		$failed = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . self::table() . " WHERE status IN ('failed','undelivered','expired','error') AND booking_id > 0 AND updated_at > %s", SCP_Helpers::now()->modify( '-1 day' )->format( 'Y-m-d H:i:s' ) ) ); // phpcs:ignore
		$credit = self::provider()->is_configured() ? self::provider()->credit() : array( 'ok' => false );
		$date   = SCP_Helpers::date_long( $tomorrow, true );
		$html   = '<h2 style="font-size:20px;margin:0 0 12px">Ședințele de mâine, ' . esc_html( $date ) . '</h2>';
		$html  .= $list
			? '<table style="border-collapse:collapse;width:100%;font-size:14px"><tr style="text-align:left;color:#8a8170"><th style="padding:6px 8px">Ora</th><th style="padding:6px 8px">Client</th><th style="padding:6px 8px">Pachet</th><th style="padding:6px 8px">Plată</th><th style="padding:6px 8px">SMS confirmare</th><th style="padding:6px 8px">SMS cu o zi înainte</th><th style="padding:6px 8px">SMS cu o oră înainte</th></tr>' . $rows . '</table>'
			: '<p>Nu sunt ședințe programate mâine.</p>';
		$html  .= '<p style="margin-top:18px">SMS-uri eșuate în ultimele 24 de ore: <b>' . $failed . '</b>' . ( ! empty( $credit['ok'] ) && null !== $credit['value'] ? ' · Credit SMSO: <b>' . esc_html( number_format_i18n( (float) $credit['value'], 2 ) ) . '</b>' : '' ) . '</p>';
		$html  .= '<p><a href="' . esc_url( admin_url( 'admin.php?page=scp-sms' ) ) . '">Jurnal SMS</a> · <a href="' . esc_url( admin_url( 'admin.php?page=scp-bookings' ) ) . '">Programări</a></p>';
		self::mail( 'Ședințele de mâine (' . count( (array) $list ) . '): ' . $date, $html );
	}

	private static function schedule_report_next(): void {
		if ( ! self::has_scheduler() ) {
			return;
		}
		as_unschedule_all_actions( 'scp_sms_report', array(), self::GROUP );
		self::schedule_report();
	}

	/* ---------------------------------------------------------------- link scurt de plată */

	/** stefanchelmu.ro/p/{primele 10 caractere din token} → pagina de plată a programării. */
	public static function short_link(): void {
		$path = (string) wp_parse_url( (string) ( $_SERVER['REQUEST_URI'] ?? '' ), PHP_URL_PATH ); // phpcs:ignore
		if ( ! preg_match( '#^/p/([a-f0-9]{10})/?$#', $path, $m ) ) {
			return;
		}
		// Ghicitul linkurilor scurte: cel mult 30 pe oră de pe aceeași conexiune.
		$rl = SCP_Helpers::rate_key( 'scp_pl_' );
		$n  = (int) get_transient( $rl );
		if ( $n >= 30 ) {
			nocache_headers();
			wp_safe_redirect( home_url( '/sedinte-foto-de-craciun/' ), 302, 'SC Programari' );
			exit;
		}
		set_transient( $rl, $n + 1, HOUR_IN_SECONDS );
		global $wpdb;
		$token = $wpdb->get_var( $wpdb->prepare( 'SELECT token FROM ' . SCP_Install::table( 'bookings' ) . ' WHERE token LIKE %s LIMIT 2', $wpdb->esc_like( $m[1] ) . '%' ) ); // phpcs:ignore
		$b     = $token ? SCP_Bookings::get_by_token( (string) $token ) : null;
		$url   = $b ? SCP_Bookings::payment_url( $b ) : home_url( '/sedinte-foto-de-craciun/' );
		nocache_headers();
		wp_safe_redirect( $url, 302, 'SC Programari' );
		exit;
	}

	/* ---------------------------------------------------------------- test de livrare */

	/**
	 * Trimite câte un mesaj de test la fiecare număr. Rândurile au booking_id 0 și tipul test-{lot}-{rețea}.
	 *
	 * @param array $numbers rețea => număr
	 */
	public static function send_test( array $numbers, string $text = '' ): array {
		global $wpdb;
		$batch = gmdate( 'ymdHis' );
		$text  = self::gsm( $text ?: 'Test SMS Stefan Chelmu: daca ai primit mesajul, livrarea pe reteaua ta functioneaza. Nu trebuie sa raspunzi.' );
		$out   = array();
		foreach ( $numbers as $net => $num ) {
			$to = self::e164( (string) $num );
			if ( strlen( $to ) < 11 ) {
				continue;
			}
			$now = SCP_Helpers::mysql_now();
			$wpdb->insert( self::table(), array( 'booking_id' => 0, 'type' => 'test-' . $batch . '-' . sanitize_key( $net ), 'phone' => $to, 'body' => $text, 'status' => 'queued', 'provider' => self::provider()->id(), 'slot' => sanitize_key( $net ), 'created_at' => $now, 'updated_at' => $now ) );
			$id = (int) $wpdb->insert_id;
			self::deliver( $id, $to, $text, null );
			if ( ! self::provider()->is_configured() ) {
				self::job_check( $id );
			}
			$out[] = $id;
		}
		update_option( 'scp_sms_last_test', $batch, false );
		return $out;
	}

	/** Mesajele automate, în ordinea în care le primește un client, cu eticheta lor. */
	const SAMPLES = array(
		'created'     => 'Rezervare cu link de plată',
		'pay_reminder' => 'Ultimul apel la plată',
		'paid'        => 'Plată primită',
		'voucher'     => 'Rezervare cu voucher',
		'studio'      => 'Plata la studio',
		'moved'       => 'Mutare',
		'remind_day'  => 'Cu o zi înainte',
		'remind_hour' => 'Cu o oră înainte',
	);

	/**
	 * Trimite acum, pe un număr, câte un exemplu din fiecare mesaj automat, cu textele din setări și date de exemplu.
	 * Nu ține cont de „Trimitere automată” și nu atinge programările; rândurile au booking_id 0 și tipul proba-{lot}-{mesaj}.
	 */
	public static function send_samples( string $number ): array {
		global $wpdb;
		$to = self::e164( $number );
		if ( strlen( $to ) < 11 ) {
			return array();
		}
		$batch = gmdate( 'ymdHis' );
		$tpls  = self::get( 'templates' );
		$out   = array();
		foreach ( self::SAMPLES as $key => $label ) {
			$b    = array_merge( self::sample_booking(), array( 'phone' => $to ), 'voucher' === $key ? array( 'payment_status' => 'voucher' ) : array() );
			$text = self::render( (string) ( $tpls[ $key ]['body'] ?? '' ), $b );
			if ( '' === $text ) {
				continue;
			}
			$now = SCP_Helpers::mysql_now();
			$wpdb->insert( self::table(), array( 'booking_id' => 0, 'type' => 'proba-' . $batch . '-' . $key, 'phone' => $to, 'body' => $text, 'status' => 'queued', 'provider' => self::provider()->id(), 'slot' => $key, 'created_at' => $now, 'updated_at' => $now ) );
			$id = (int) $wpdb->insert_id;
			self::deliver( $id, $to, $text, null );
			if ( ! self::provider()->is_configured() ) {
				self::job_check( $id );
			}
			$out[] = $id;
		}
		return $out;
	}

	/** Rețeaua după MNC (MCC 226 = România). */
	public static function network( ?string $mcc, ?string $mnc ): string {
		$mcc = (string) $mcc;
		$mnc = (string) $mnc;
		if ( '' === $mnc ) {
			return '';
		}
		if ( '226' !== $mcc ) {
			return $mcc ? 'MCC ' . $mcc . '/' . $mnc : '';
		}
		$map = array( '01' => 'Vodafone', '03' => 'Telekom', '06' => 'Telekom', '05' => 'Digi', '10' => 'Orange' );
		$mnc = str_pad( ltrim( $mnc, '0' ), 2, '0', STR_PAD_LEFT );
		return $map[ $mnc ] ?? 'MNC ' . $mnc;
	}
}
