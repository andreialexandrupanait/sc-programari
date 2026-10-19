<?php
defined( 'ABSPATH' ) || exit;

/**
 * Emailuri trimise prin wp_mail(), deci prin Postmark / FluentSMTP configurat pe site.
 */
class SCP_Mailer {

	const JOBS = array( 'on_created', 'on_rescheduled', 'on_cancelled', 'on_paid', 'on_studio', 'on_reminder', 'on_last_call' );

	public static function init(): void {
		add_action( 'scp_booking_created', static fn( $b ) => self::defer( 'on_created', $b ), 20 );
		add_action( 'scp_booking_rescheduled', static fn( $b, $before, $notify ) => self::defer( 'on_rescheduled', $b, array( (bool) $notify ) ), 20, 3 );
		add_action( 'scp_booking_cancelled', static fn( $b, $notify, $reason = '' ) => self::defer( 'on_cancelled', $b, array( (bool) $notify, (string) $reason ) ), 20, 3 );
		add_action( 'scp_booking_paid', static fn( $b ) => self::defer( 'on_paid', $b ), 20 );
		add_action( 'scp_booking_studio', static fn( $b ) => self::defer( 'on_studio', $b ), 20 );
		add_action( 'scp_payment_reminder', static fn( $b ) => self::defer( 'on_reminder', $b ), 20 );
		add_action( 'scp_mail_job', array( __CLASS__, 'run_job' ), 10, 3 );
	}

	/**
	 * Emailurile pleacă în fundal (Action Scheduler), ca formularul, plata (IPN Netopia) și adminul
	 * să nu aștepte serverul de email. Fără Action Scheduler se trimit pe loc.
	 */
	private static function defer( string $job, array $b, array $args = array() ): void {
		if ( empty( $b['id'] ) || ! function_exists( 'as_enqueue_async_action' ) || ! apply_filters( 'scp_async', true ) ) {
			self::call( $job, $b, $args );
			return;
		}
		as_enqueue_async_action( 'scp_mail_job', array( $job, (int) $b['id'], $args ), 'scp' );
	}

	public static function run_job( $job, $id, $args = array() ): void {
		$b = SCP_Bookings::get( (int) $id );
		if ( $b && in_array( $job, self::JOBS, true ) ) {
			self::call( (string) $job, $b, (array) $args );
		}
	}

	private static function call( string $job, array $b, array $args ): void {
		switch ( $job ) {
			case 'on_rescheduled':
				self::on_rescheduled( $b, $b, (bool) ( $args[0] ?? false ) );
				break;
			case 'on_cancelled':
				self::on_cancelled( $b, (bool) ( $args[0] ?? false ), (string) ( $args[1] ?? '' ) );
				break;
			default:
				self::$job( $b );
		}
	}

	private static function pay_button( array $b ): array {
		$url = SCP_Bookings::payment_url( $b );
		return $url ? array( 'Plătește online ' . SCP_Helpers::money( $b['price'] ) . ' lei', $url ) : array();
	}

	public static function on_created( array $b ): void {
		if ( $b['email'] ) {
			if ( 'pending' === $b['status'] ) {
				$sent = self::send_template( 'client_pending', $b, $b['email'], array( 'button' => self::pay_button( $b ) ) );
				if ( $sent ) {
					SCP_Bookings::mark_link_sent( $b['id'] );
				}
			} else {
				self::send_template( self::confirmation_key( $b ), $b, $b['email'], array( 'ics' => true ) );
			}
		}
		$admins = SCP_Settings::admin_emails();
		if ( $admins ) {
			self::send_template( 'admin_new', $b, $admins, array( 'admin' => true, 'reply_to' => $b['email'] ) );
		}
	}

	/** Confirmarea potrivită: cu plata la studio sau simplă. */
	private static function confirmation_key( array $b ): string {
		return SCP_Bookings::pays_at_studio( $b ) ? 'client_studio' : 'client_confirmation';
	}

	/** Programare trecută din „așteaptă plata online” pe plata la studio. */
	public static function on_studio( array $b ): void {
		if ( $b['email'] ) {
			self::send_template( 'client_studio', $b, $b['email'], array( 'ics' => true ) );
		}
	}

	public static function on_rescheduled( array $b, array $before, bool $notify ): void {
		if ( $notify && $b['email'] ) {
			self::send_template( 'rescheduled', $b, $b['email'], array( 'ics' => true ) );
		}
	}

	public static function on_cancelled( array $b, bool $notify, string $reason = '' ): void {
		if ( $notify && $b['email'] ) {
			self::send_template( 'expired' === $reason ? 'expired' : 'cancelled', $b, $b['email'] );
		}
	}

	public static function on_paid( array $b ): void {
		if ( $b['email'] && 'paid' === $b['payment_status'] && 'cancelled' !== $b['status'] ) {
			self::send_template( 'payment_received', $b, $b['email'], array( 'ics' => true ) );
		}
	}

	public static function on_reminder( array $b ): void {
		if ( $b['email'] ) {
			self::send_template( 'payment_reminder', $b, $b['email'], array( 'button' => self::pay_button( $b ) ) );
		}
	}

	/** Ultimul apel pe email: doar ca rezervă, când SMS-ul nu poate pleca sau nu ajunge (vezi SCP_SMS::on_last_call). */
	public static function on_last_call( array $b ): bool {
		if ( ! $b['email'] || 'pending' !== $b['status'] || SCP_Bookings::is_paid( $b ) || empty( $b['pay_by'] ) || SCP_Helpers::mysql_now() >= $b['pay_by'] ) {
			return false;
		}
		return (bool) self::send_template( 'payment_last_call', $b, $b['email'], array( 'button' => self::pay_button( $b ) ) );
	}

	public static function send_payment_link( array $b ): bool|WP_Error {
		if ( ! $b['email'] ) {
			return new WP_Error( 'scp_no_email', 'Programarea nu are o adresă de email.' );
		}
		$url = SCP_Bookings::payment_url( $b );
		if ( ! $url ) {
			return new WP_Error( 'scp_no_url', 'Completează în Setări adresa paginii de plată.' );
		}
		$ok = self::send_template( 'pending' === $b['status'] ? 'client_pending' : 'payment_link', $b, $b['email'], array( 'button' => self::pay_button( $b ), 'force' => true ) );
		return $ok ? true : new WP_Error( 'scp_mail', 'Emailul nu a putut fi trimis. Verifică setările de email ale site-ului.' );
	}

	/** Emailul cu codurile voucherelor, către cumpărător. */
	public static function send_vouchers( array $vouchers, string $to, string $first_name = '' ): bool {
		if ( ! $vouchers || ! is_email( $to ) ) {
			return false;
		}
		$tpl = SCP_Settings::get( 'emails' )['voucher_issued'] ?? null;
		if ( ! $tpl || empty( $tpl['enabled'] ) ) {
			return false;
		}
		$packages = SCP_Settings::packages();
		$vars     = array(
			'{nume}'             => $first_name ?: 'dragă client',
			'{studio}'           => (string) SCP_Settings::get( 'studio_name' ),
			'{adresa}'           => (string) SCP_Settings::get( 'studio_address' ),
			'{telefon_studio}'   => (string) SCP_Settings::get( 'studio_phone' ),
			'{link_programare}'  => home_url( '/sedinte-foto-de-craciun/' ),
			'{valabil_pana}'     => SCP_Helpers::date_ro( (string) ( $vouchers[0]['expires_on'] ?: SCP_Settings::get( 'end_date' ) ) ),
		);
		$subject = wp_strip_all_tags( strtr( (string) $tpl['subject'], $vars ) );
		$body    = '';
		foreach ( array_filter( array_map( 'trim', preg_split( "/\n\s*\n/", strtr( (string) $tpl['body'], $vars ) ) ) ) as $p ) {
			$body .= '<p style="margin:0 0 16px;font-size:16px;line-height:1.6;color:#3d3a35;">' . nl2br( esc_html( $p ) ) . '</p>';
		}
		$codes = '';
		foreach ( $vouchers as $v ) {
			$codes .= '<tr><td style="padding:14px 18px;border-bottom:1px solid #ece4d4;">'
				. '<div style="font-family:\'Courier New\',monospace;font-size:24px;font-weight:700;letter-spacing:.12em;color:#1f3a17;">' . esc_html( $v['code'] ) . '</div>'
				. ( ! empty( $v['recipient_name'] ) ? '<div style="margin-top:6px;font-size:16px;color:#3d3a35;">Pentru: <strong>' . esc_html( $v['recipient_name'] ) . '</strong></div>' : '' )
				. '<div style="margin-top:4px;font-size:14px;color:#8a8170;">' . esc_html( ( $packages[ $v['package'] ]['label'] ?? $v['package'] ) . ' · valabil până pe ' . SCP_Helpers::date_ro( (string) $v['expires_on'] ) ) . '</div></td></tr>';
		}
		$body_html = $body;
		$rows_html = $codes;
		$button    = '<table role="presentation" cellpadding="0" cellspacing="0" style="margin:8px 0 24px;"><tr><td style="background:#2d661b;border-radius:6px;"><a href="' . esc_url( $vars['{link_programare}'] ) . '" style="display:inline-block;padding:14px 28px;font-size:16px;font-weight:700;color:#ffffff;text-decoration:none;">Pagina de programare</a></td></tr></table>';
		$studio    = esc_html( $vars['{studio}'] );
		$footer    = esc_html( $vars['{adresa}'] ) . ' · ' . esc_html( $vars['{telefon_studio}'] );
		ob_start();
		include SCP_DIR . 'templates/email.php';
		$html = (string) ob_get_clean();
		return (bool) wp_mail( $to, $subject, $html, array( 'Content-Type: text/html; charset=UTF-8' ) );
	}

	public static function resend_confirmation( array $b ): bool {
		if ( ! $b['email'] ) {
			return false;
		}
		if ( 'pending' === $b['status'] ) {
			return self::send_template( 'client_pending', $b, $b['email'], array( 'button' => self::pay_button( $b ), 'force' => true ) );
		}
		$key = 'paid' === $b['payment_status'] ? 'payment_received' : self::confirmation_key( $b );
		return self::send_template( $key, $b, $b['email'], array( 'ics' => true, 'force' => true ) );
	}

	/* ---------- Construire ---------- */

	public static function vars( array $b ): array {
		$packages = SCP_Settings::packages();
		return array(
			'{nume}'           => $b['name'],
			'{email}'          => $b['email'],
			'{telefon}'        => $b['phone'],
			'{data}'           => SCP_Helpers::date_ro( $b['slot_date'] ),
			'{data_lunga}'     => SCP_Helpers::date_long( $b['slot_date'] ),
			'{ziua}'           => SCP_Helpers::WEEKDAYS[ SCP_Helpers::weekday( $b['slot_date'] ) ],
			'{ora}'            => $b['slot_time'],
			'{pachet}'         => $packages[ $b['package'] ]['label'] ?? $b['package'],
			'{pret}'           => SCP_Helpers::money( $b['price'] ),
			'{pret_intreg}'    => SCP_Helpers::money( $b['list_price'] ),
			'{reducere}'       => SCP_Helpers::money( $b['discount'] ),
			'{termen_plata}'   => $b['pay_by'] ? SCP_Helpers::datetime_long( $b['pay_by'] ) : '',
			'{cod}'            => $b['code'],
			'{mesaj}'          => $b['message'] ? $b['message'] : '–',
			'{link_plata}'     => SCP_Bookings::payment_url( $b ),
			'{studio}'         => (string) SCP_Settings::get( 'studio_name' ),
			'{adresa}'         => (string) SCP_Settings::get( 'studio_address' ),
			'{telefon_studio}' => (string) SCP_Settings::get( 'studio_phone' ),
		);
	}

	/**
	 * @param string|string[] $to
	 */
	public static function send_template( string $key, array $b, $to, array $opts = array() ): bool {
		$tpl = SCP_Settings::get( 'emails' )[ $key ] ?? null;
		if ( ! $tpl || ( empty( $tpl['enabled'] ) && empty( $opts['force'] ) ) ) {
			return false;
		}
		$vars    = self::vars( $b );
		$subject = wp_strip_all_tags( strtr( (string) $tpl['subject'], $vars ) );
		$body    = strtr( (string) $tpl['body'], $vars );
		$html    = self::render( $subject, $body, $b, $opts );

		$headers = array( 'Content-Type: text/html; charset=UTF-8' );
		$from_e  = sanitize_email( (string) SCP_Settings::get( 'from_email' ) );
		$from_n  = (string) SCP_Settings::get( 'from_name' );
		if ( $from_e ) {
			$headers[] = 'From: ' . ( $from_n ? '"' . str_replace( '"', '', $from_n ) . '" ' : '' ) . '<' . $from_e . '>';
		}
		$reply = $opts['reply_to'] ?? sanitize_email( (string) SCP_Settings::get( 'reply_to' ) );
		if ( $reply && is_email( $reply ) ) {
			$headers[] = 'Reply-To: ' . $reply;
		}

		$attachments = array();
		$ics_file    = null;
		if ( ! empty( $opts['ics'] ) ) {
			$ics_file = self::write_ics( $b );
			if ( $ics_file ) {
				$attachments[] = $ics_file;
			}
		}

		$ok = wp_mail( $to, $subject, $html, $headers, $attachments );

		if ( $ics_file && file_exists( $ics_file ) ) {
			wp_delete_file( $ics_file );
			@rmdir( dirname( $ics_file ) ); // phpcs:ignore
		}
		return (bool) $ok;
	}

	private static function render( string $subject, string $body, array $b, array $opts ): string {
		$packages = SCP_Settings::packages();
		$admin    = ! empty( $opts['admin'] );

		$paragraphs = array_filter( array_map( 'trim', preg_split( "/\n\s*\n/", $body ) ) );
		$body_html  = '';
		foreach ( $paragraphs as $p ) {
			$body_html .= '<p style="margin:0 0 16px;font-size:16px;line-height:1.6;color:#3d3a35;">' . nl2br( esc_html( $p ) ) . '</p>';
		}

		$rows = array(
			'Data'   => SCP_Helpers::date_long( $b['slot_date'], true ),
			'Ora'    => $b['slot_time'] . ' – ' . SCP_Helpers::dt( $b['slot_date'], $b['slot_time'] )->modify( '+' . (int) SCP_Settings::get( 'session_minutes', 60 ) . ' minutes' )->format( 'H:i' ),
			'Pachet' => ( $packages[ $b['package'] ]['label'] ?? $b['package'] ) . ' · ' . ( 'free' === $b['payment_status'] ? 'oferit gratuit' : SCP_Helpers::money( $b['price'] ) . ' lei' . ( $b['discount'] > 0 ? ' (în loc de ' . SCP_Helpers::money( $b['list_price'] ) . ')' : '' ) ),
			'Plată până' => 'pending' === $b['status'] && $b['pay_by'] ? SCP_Helpers::datetime_long( $b['pay_by'] ) : '',
			'Unde'   => (string) SCP_Settings::get( 'studio_address' ),
			'Cod'    => $b['code'],
		);
		if ( $admin ) {
			$rows = array_merge(
				array(
					'Client'  => $b['name'],
					'Telefon' => $b['phone'],
					'Email'   => $b['email'],
				),
				$rows
			);
			unset( $rows['Unde'] );
		}

		$rows_html = '';
		foreach ( $rows as $label => $value ) {
			if ( '' === (string) $value ) {
				continue;
			}
			$rows_html .= '<tr><td style="padding:6px 0;width:90px;font-size:13px;letter-spacing:.04em;text-transform:uppercase;color:#8a8170;vertical-align:top;">' . esc_html( $label ) . '</td><td style="padding:6px 0;font-size:15px;color:#2b2925;font-weight:600;">' . esc_html( $value ) . '</td></tr>';
		}

		$button = '';
		if ( ! empty( $opts['button'] ) && count( $opts['button'] ) === 2 ) {
			[ $label, $url ] = $opts['button'];
			$button = '<table role="presentation" cellpadding="0" cellspacing="0" style="margin:8px 0 24px;"><tr><td style="background:#2d661b;border-radius:6px;"><a href="' . esc_url( $url ) . '" style="display:inline-block;padding:14px 28px;font-size:16px;font-weight:700;color:#ffffff;text-decoration:none;">' . esc_html( $label ) . '</a></td></tr></table>'
				. '<p style="margin:0 0 20px;font-size:13px;color:#8a8170;">Dacă butonul nu merge, copiază adresa: <br><a href="' . esc_url( $url ) . '" style="color:#2d661b;word-break:break-all;">' . esc_html( $url ) . '</a></p>';
		}
		if ( $admin ) {
			$button = '<table role="presentation" cellpadding="0" cellspacing="0" style="margin:8px 0 8px;"><tr><td style="background:#2d661b;border-radius:6px;"><a href="' . esc_url( admin_url( 'admin.php?page=scp-bookings&booking=' . $b['id'] ) ) . '" style="display:inline-block;padding:12px 22px;font-size:15px;font-weight:700;color:#ffffff;text-decoration:none;">Deschide programarea</a></td></tr></table>';
		}

		$studio = esc_html( (string) SCP_Settings::get( 'studio_name' ) );
		$footer = esc_html( (string) SCP_Settings::get( 'studio_address' ) ) . ' · ' . esc_html( (string) SCP_Settings::get( 'studio_phone' ) );

		ob_start();
		include SCP_DIR . 'templates/email.php';
		return (string) ob_get_clean();
	}

	/* ---------- Calendar (.ics) ---------- */

	public static function ics( array $b ): string {
		$start = SCP_Helpers::dt( $b['slot_date'], $b['slot_time'] )->setTimezone( new DateTimeZone( 'UTC' ) );
		$end   = $start->modify( '+' . (int) SCP_Settings::get( 'session_minutes', 60 ) . ' minutes' );
		$pk    = SCP_Settings::packages()[ $b['package'] ]['label'] ?? $b['package'];
		$esc   = static fn( string $s ): string => addcslashes( str_replace( array( "\r\n", "\n" ), '\\n', $s ), ',;\\' );
		$host  = wp_parse_url( home_url(), PHP_URL_HOST ) ?: 'localhost';

		$lines = array(
			'BEGIN:VCALENDAR',
			'VERSION:2.0',
			'PRODID:-//SimpleAD//SC Programari//RO',
			'CALSCALE:GREGORIAN',
			'METHOD:PUBLISH',
			'BEGIN:VEVENT',
			'UID:scp-' . $b['token'] . '@' . $host,
			'DTSTAMP:' . gmdate( 'Ymd\THis\Z' ),
			'DTSTART:' . $start->format( 'Ymd\THis\Z' ),
			'DTEND:' . $end->format( 'Ymd\THis\Z' ),
			'SUMMARY:' . $esc( 'Ședință foto de Crăciun – ' . SCP_Settings::get( 'studio_name' ) ),
			'LOCATION:' . $esc( (string) SCP_Settings::get( 'studio_address' ) ),
			'DESCRIPTION:' . $esc( $pk . ' · cod ' . $b['code'] . ' · ' . SCP_Settings::get( 'studio_phone' ) ),
			'END:VEVENT',
			'END:VCALENDAR',
		);
		return implode( "\r\n", $lines ) . "\r\n";
	}

	private static function write_ics( array $b ): ?string {
		$dir = trailingslashit( get_temp_dir() ) . 'scp-' . wp_generate_password( 8, false, false );
		if ( ! wp_mkdir_p( $dir ) ) {
			return null;
		}
		$file = $dir . '/sedinta-foto-craciun.ics';
		return false !== file_put_contents( $file, self::ics( $b ) ) ? $file : null;
	}

	public static function google_calendar_url( array $b ): string {
		$start = SCP_Helpers::dt( $b['slot_date'], $b['slot_time'] )->setTimezone( new DateTimeZone( 'UTC' ) );
		$end   = $start->modify( '+' . (int) SCP_Settings::get( 'session_minutes', 60 ) . ' minutes' );
		return add_query_arg(
			array(
				'action'   => 'TEMPLATE',
				'text'     => rawurlencode( 'Ședință foto de Crăciun – ' . SCP_Settings::get( 'studio_name' ) ),
				'dates'    => $start->format( 'Ymd\THis\Z' ) . '/' . $end->format( 'Ymd\THis\Z' ),
				'location' => rawurlencode( (string) SCP_Settings::get( 'studio_address' ) ),
				'details'  => rawurlencode( 'Cod programare: ' . $b['code'] ),
			),
			'https://calendar.google.com/calendar/render'
		);
	}
}

SCP_Mailer::init();
