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
		'cash'      => 'Plătită cash la studio',
		'card'      => 'Plătită cu cardul la studio',
		'voucher'   => 'Plătită cu voucher',
		'free'      => 'Gratuită (oferită)',
	);

	/** Statusurile de plată care înseamnă că ședința e achitată. */
	const PAID = array( 'paid', 'cash', 'card', 'voucher', 'free' );

	/** Pentru SQL: 'paid','cash','card','voucher','free' (valori fixe, nu vin de la utilizator). */
	public static function paid_sql(): string {
		return "'" . implode( "','", self::PAID ) . "'";
	}

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

	/** Rețelele Cloudflare (https://www.cloudflare.com/ips/). Doar de la ele acceptăm antetul CF-Connecting-IP. */
	const CLOUDFLARE = array(
		'173.245.48.0/20', '103.21.244.0/22', '103.22.200.0/22', '103.31.4.0/22', '141.101.64.0/18', '108.162.192.0/18',
		'190.93.240.0/20', '188.114.96.0/20', '197.234.240.0/22', '198.41.128.0/17', '162.158.0.0/15', '104.16.0.0/13',
		'104.24.0.0/14', '172.64.0.0/13', '131.0.72.0/22',
		'2400:cb00::/32', '2606:4700::/32', '2803:f800::/32', '2405:b500::/32', '2405:8100::/32', '2a06:98c0::/29', '2c0f:f248::/32',
	);

	/**
	 * IP-ul vizitatorului. CF-Connecting-IP contează doar când cererea vine de la Cloudflare sau de la un proxy
	 * local al serverului (IP privat); altfel oricine l-ar putea trimite ca să ocolească limitele.
	 */
	public static function client_ip(): string {
		$remote = trim( (string) ( $_SERVER['REMOTE_ADDR'] ?? '' ) ); // phpcs:ignore
		if ( ! filter_var( $remote, FILTER_VALIDATE_IP ) ) {
			$remote = '0.0.0.0';
		}
		$cf = isset( $_SERVER['HTTP_CF_CONNECTING_IP'] ) ? trim( explode( ',', sanitize_text_field( wp_unslash( $_SERVER['HTTP_CF_CONNECTING_IP'] ) ) )[0] ) : ''; // phpcs:ignore
		if ( $cf && filter_var( $cf, FILTER_VALIDATE_IP ) && self::trusted_proxy( $remote ) ) {
			return $cf;
		}
		return $remote;
	}

	public static function trusted_proxy( string $ip ): bool {
		// IP privat sau local: un proxy al gazdei în fața PHP-ului.
		if ( filter_var( $ip, FILTER_VALIDATE_IP ) && ! filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) ) {
			return true;
		}
		foreach ( (array) apply_filters( 'scp_trusted_proxies', self::CLOUDFLARE ) as $cidr ) {
			if ( self::ip_in_range( $ip, (string) $cidr ) ) {
				return true;
			}
		}
		return false;
	}

	public static function ip_in_range( string $ip, string $cidr ): bool {
		[ $net, $bits ] = array_pad( explode( '/', $cidr, 2 ), 2, null );
		$a = @inet_pton( $ip ); // phpcs:ignore
		$b = @inet_pton( (string) $net ); // phpcs:ignore
		if ( false === $a || false === $b || strlen( $a ) !== strlen( $b ) ) {
			return false;
		}
		$bits  = null === $bits ? strlen( $a ) * 8 : (int) $bits;
		$bytes = intdiv( $bits, 8 );
		if ( substr( $a, 0, $bytes ) !== substr( $b, 0, $bytes ) ) {
			return false;
		}
		$rest = $bits % 8;
		if ( ! $rest ) {
			return true;
		}
		$mask = chr( ( 0xff << ( 8 - $rest ) ) & 0xff );
		return ( $a[ $bytes ] & $mask ) === ( $b[ $bytes ] & $mask );
	}

	/** Cheia pentru limitele pe conexiune: IPv4 întreg, IPv6 pe /64 (o conexiune are de obicei tot blocul). */
	public static function rate_key( string $prefix ): string {
		$ip = self::client_ip();
		if ( str_contains( $ip, ':' ) ) {
			$bin = @inet_pton( $ip ); // phpcs:ignore
			$ip  = false !== $bin ? bin2hex( substr( $bin, 0, 8 ) ) . '::/64' : $ip;
		}
		return $prefix . md5( $ip . wp_salt( 'nonce' ) );
	}

	/** Mobil românesc (+407xxxxxxxx): doar pe acestea pleacă SMS-uri automate. */
	public static function is_ro_mobile( string $raw ): bool {
		return (bool) preg_match( '/^\+407\d{8}$/', SCP_SMS::e164( $raw ) );
	}
}
