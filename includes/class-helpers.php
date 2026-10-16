<?php
defined( 'ABSPATH' ) || exit;

class SCP_Helpers {

	const WEEKDAYS = array( 1 => 'luni', 2 => 'marți', 3 => 'miercuri', 4 => 'joi', 5 => 'vineri', 6 => 'sâmbătă', 7 => 'duminică' );
	const MONTHS   = array( 1 => 'ianuarie', 2 => 'februarie', 3 => 'martie', 4 => 'aprilie', 5 => 'mai', 6 => 'iunie', 7 => 'iulie', 8 => 'august', 9 => 'septembrie', 10 => 'octombrie', 11 => 'noiembrie', 12 => 'decembrie' );

	const STATUSES = array(
		'pending'   => 'Așteaptă plata',
		'confirmed' => 'Confirmată',
		'completed' => 'Finalizată',
		'no_show'   => 'Neprezentat',
		'cancelled' => 'Anulată',
	);

	const PAYMENT_STATUSES = array(
		'unpaid'    => 'Neplătită',
		'link_sent' => 'Link trimis',
		'paid'      => 'Plătită online',
		'cash'      => 'Plătită la studio',
		'voucher'   => 'Plătită cu voucher',
	);

	const SOURCES = array(
		'online' => 'Site',
		'phone'  => 'Telefon',
		'admin'  => 'Admin',
	);

	public static function is_time( $t ): bool {
		return is_string( $t ) && (bool) preg_match( '/^([01]\d|2[0-3]):[0-5]\d$/', $t );
	}

	public static function is_date( $d ): bool {
		if ( ! is_string( $d ) || ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $d ) ) {
			return false;
		}
		[ $y, $m, $day ] = array_map( 'intval', explode( '-', $d ) );
		return checkdate( $m, $day, $y );
	}

	public static function tz(): DateTimeZone {
		return wp_timezone();
	}

	public static function now(): DateTimeImmutable {
		return new DateTimeImmutable( 'now', self::tz() );
	}

	public static function dt( string $date, string $time = '00:00' ): DateTimeImmutable {
		return new DateTimeImmutable( $date . ' ' . $time . ':00', self::tz() );
	}

	public static function mysql_now(): string {
		return self::now()->format( 'Y-m-d H:i:s' );
	}

	public static function weekday( string $date ): int {
		return (int) self::dt( $date )->format( 'N' );
	}

	/** „14.11.2026” */
	public static function date_ro( string $date ): string {
		return self::dt( $date )->format( 'd.m.Y' );
	}

	/** „sâmbătă, 14 noiembrie” */
	public static function date_long( string $date, bool $with_year = false ): string {
		$d   = self::dt( $date );
		$out = self::WEEKDAYS[ (int) $d->format( 'N' ) ] . ', ' . (int) $d->format( 'j' ) . ' ' . self::MONTHS[ (int) $d->format( 'n' ) ];
		return $with_year ? $out . ' ' . $d->format( 'Y' ) : $out;
	}

	/** „sâmbătă, 17 octombrie, ora 14:30” pentru un datetime MySQL local. */
	public static function datetime_long( string $mysql ): string {
		$d = new DateTimeImmutable( $mysql, self::tz() );
		return self::date_long( $d->format( 'Y-m-d' ) ) . ', ora ' . $d->format( 'H:i' );
	}

	public static function money( $v ): string {
		$v = (float) $v;
		return number_format( $v, floor( $v ) == $v ? 0 : 2, ',', '.' );
	}

	public static function random_code( int $len = 6 ): string {
		$alphabet = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';
		$out      = '';
		for ( $i = 0; $i < $len; $i++ ) {
			$out .= $alphabet[ random_int( 0, strlen( $alphabet ) - 1 ) ];
		}
		return $out;
	}

	/** Normalizează un număr de telefon românesc pentru afișare: 0749 270 232. */
	public static function normalize_phone( string $raw ): string {
		$digits = preg_replace( '/\D+/', '', $raw );
		if ( str_starts_with( $digits, '0040' ) ) {
			$digits = '0' . substr( $digits, 4 );
		} elseif ( str_starts_with( $digits, '40' ) && strlen( $digits ) === 11 ) {
			$digits = '0' . substr( $digits, 2 );
		}
		if ( strlen( $digits ) === 10 && str_starts_with( $digits, '0' ) ) {
			return substr( $digits, 0, 4 ) . ' ' . substr( $digits, 4, 3 ) . ' ' . substr( $digits, 7 );
		}
		return trim( preg_replace( '/[^\d+ ]/', '', $raw ) );
	}

	public static function phone_is_valid( string $raw ): bool {
		$digits = preg_replace( '/\D+/', '', $raw );
		return strlen( $digits ) >= 9 && strlen( $digits ) <= 15;
	}

	public static function absolute_url( string $url ): string {
		if ( '' === $url ) {
			return '';
		}
		if ( preg_match( '#^https?://#i', $url ) ) {
			return $url;
		}
		return home_url( '/' . ltrim( $url, '/' ) );
	}

	public static function uuid(): string {
		return wp_generate_uuid4();
	}

	public static function client_ip(): string {
		foreach ( array( 'HTTP_CF_CONNECTING_IP', 'REMOTE_ADDR' ) as $k ) {
			if ( ! empty( $_SERVER[ $k ] ) ) {
				$ip = trim( explode( ',', sanitize_text_field( wp_unslash( $_SERVER[ $k ] ) ) )[0] );
				if ( filter_var( $ip, FILTER_VALIDATE_IP ) ) {
					return $ip;
				}
			}
		}
		return '0.0.0.0';
	}
}
