<?php
defined( 'ABSPATH' ) || exit;

/**
 * Calculează sloturile pentru o perioadă: programul standard din setări,
 * plus excepțiile din admin (ore blocate, zile blocate, ore suplimentare),
 * minus programările active.
 */
class SCP_Availability {

	/** Excepțiile pentru un interval, grupate pe zi: [date => ['day' => row|null, 'times' => [time => row]]] */
	public static function overrides( string $from, string $to ): array {
		global $wpdb;
		$table = SCP_Install::table( 'overrides' );
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE odate BETWEEN %s AND %s", $from, $to ), ARRAY_A );
		$out   = array();
		foreach ( $rows as $r ) {
			$d = $r['odate'];
			$out[ $d ] ??= array( 'day' => null, 'times' => array() );
			if ( '' === $r['otime'] ) {
				$out[ $d ]['day'] = $r;
			} else {
				$out[ $d ]['times'][ $r['otime'] ] = $r;
			}
		}
		return $out;
	}

	/** Programările active pe interval: [date => [time => booking row]] */
	public static function booked( string $from, string $to ): array {
		global $wpdb;
		$table = SCP_Install::table( 'bookings' );
		$rows  = $wpdb->get_results(
			$wpdb->prepare( "SELECT id, code, slot_date, slot_time, name, phone, package, payment_status, status, source FROM {$table} WHERE active_slot IS NOT NULL AND slot_date BETWEEN %s AND %s", $from, $to ),
			ARRAY_A
		);
		$out = array();
		foreach ( $rows as $r ) {
			$out[ $r['slot_date'] ][ $r['slot_time'] ] = $r;
		}
		return $out;
	}

	public static function in_season( string $date ): bool {
		return $date >= SCP_Settings::get( 'start_date' ) && $date <= SCP_Settings::get( 'end_date' );
	}

	/**
	 * Starea fiecărui slot dintr-o zi.
	 *
	 * Stări: free, booked, blocked, past (prea aproape / în trecut pentru clienți).
	 * Fiecare slot are și 'kind': standard sau extra (adăugat manual din admin).
	 */
	public static function day( string $date, ?array $ov = null, ?array $bk = null, bool $for_admin = false ): array {
		$ov ??= self::overrides( $date, $date )[ $date ] ?? null;
		$bk ??= self::booked( $date, $date )[ $date ] ?? array();

		$in_season   = self::in_season( $date );
		$weekday_on  = in_array( SCP_Helpers::weekday( $date ), array_map( 'intval', (array) SCP_Settings::get( 'weekdays', array() ) ), true );
		$day_blocked = ! empty( $ov['day'] );

		$times = array();
		if ( $in_season && $weekday_on ) {
			foreach ( SCP_Settings::times() as $t ) {
				$times[ $t ] = 'standard';
			}
		}
		foreach ( ( $ov['times'] ?? array() ) as $t => $row ) {
			if ( 'open' === $row['type'] ) {
				$times[ $t ] = 'extra';
			}
		}
		// O programare activă rămâne vizibilă chiar dacă ora a fost scoasă între timp din program.
		foreach ( array_keys( $bk ) as $t ) {
			$times[ $t ] ??= 'extra';
		}
		ksort( $times );

		$cutoff = SCP_Helpers::now()->modify( '+' . max( 0, (int) SCP_Settings::get( 'min_notice_hours', 0 ) ) . ' hours' );
		$now    = SCP_Helpers::now();
		$slots  = array();
		foreach ( $times as $t => $kind ) {
			$at    = SCP_Helpers::dt( $date, $t );
			$state = 'free';
			if ( isset( $bk[ $t ] ) ) {
				$state = 'booked';
			} elseif ( $day_blocked || ( isset( $ov['times'][ $t ] ) && 'block' === $ov['times'][ $t ]['type'] ) ) {
				$state = 'blocked';
			} elseif ( $for_admin ? $at < $now : $at < $cutoff ) {
				$state = 'past';
			}
			$slot = array(
				'time'  => $t,
				'state' => $state,
				'kind'  => $kind,
			);
			if ( $for_admin ) {
				$slot['booking'] = $bk[ $t ] ?? null;
				$slot['reason']  = $day_blocked ? (string) $ov['day']['reason'] : (string) ( $ov['times'][ $t ]['reason'] ?? '' );
			}
			$slots[] = $slot;
		}

		return array(
			'date'        => $date,
			'in_season'   => $in_season,
			'weekday_on'  => $weekday_on,
			'day_blocked' => $day_blocked,
			'day_reason'  => $day_blocked ? (string) $ov['day']['reason'] : '',
			'slots'       => $slots,
		);
	}

	/** Toate zilele dintr-un interval [from, to]. */
	public static function range( string $from, string $to, bool $for_admin = false ): array {
		$ov   = self::overrides( $from, $to );
		$bk   = self::booked( $from, $to );
		$out  = array();
		$cur  = SCP_Helpers::dt( $from );
		$end  = SCP_Helpers::dt( $to );
		while ( $cur <= $end ) {
			$d         = $cur->format( 'Y-m-d' );
			$out[ $d ] = self::day( $d, $ov[ $d ] ?? array( 'day' => null, 'times' => array() ), $bk[ $d ] ?? array(), $for_admin );
			$cur       = $cur->modify( '+1 day' );
		}
		return $out;
	}

	/** Este slotul disponibil pentru o programare online? */
	public static function is_bookable( string $date, string $time ): bool {
		if ( ! SCP_Helpers::is_date( $date ) || ! SCP_Helpers::is_time( $time ) ) {
			return false;
		}
		foreach ( self::day( $date )['slots'] as $s ) {
			if ( $s['time'] === $time ) {
				return 'free' === $s['state'];
			}
		}
		return false;
	}

	/** Intervalul de luni afișat în calendarul public. */
	public static function public_months(): array {
		$start = SCP_Settings::get( 'start_date' );
		$end   = SCP_Settings::get( 'end_date' );
		$today = SCP_Helpers::now()->format( 'Y-m-d' );
		if ( $start < $today ) {
			$start = $today;
		}
		if ( $start > $end ) {
			return array();
		}
		$months = array();
		$cur    = SCP_Helpers::dt( substr( $start, 0, 7 ) . '-01' );
		$last   = SCP_Helpers::dt( substr( $end, 0, 7 ) . '-01' );
		while ( $cur <= $last ) {
			$months[] = $cur->format( 'Y-m' );
			$cur      = $cur->modify( '+1 month' );
		}
		return $months;
	}

	/* ---------- Excepții (admin) ---------- */

	public static function set_override( string $date, string $time, string $type, string $reason = '' ): bool {
		global $wpdb;
		$table = SCP_Install::table( 'overrides' );
		$res   = $wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$table} (odate, otime, type, reason, created_at) VALUES (%s, %s, %s, %s, %s)
				 ON DUPLICATE KEY UPDATE type = VALUES(type), reason = VALUES(reason)",
				$date,
				$time,
				$type,
				$reason,
				SCP_Helpers::mysql_now()
			)
		);
		return false !== $res;
	}

	public static function clear_override( string $date, string $time ): bool {
		global $wpdb;
		return false !== $wpdb->delete( SCP_Install::table( 'overrides' ), array( 'odate' => $date, 'otime' => $time ) );
	}

	/** Comută un slot între liber și blocat. Pentru orele suplimentare, „deblocarea” le elimină cu totul. */
	public static function toggle_block( string $date, string $time, string $reason = '' ): string {
		$day = self::day( $date, null, null, true );
		foreach ( $day['slots'] as $s ) {
			if ( $s['time'] !== $time ) {
				continue;
			}
			if ( 'booked' === $s['state'] ) {
				return 'booked';
			}
			if ( 'blocked' === $s['state'] && ! $day['day_blocked'] ) {
				self::clear_override( $date, $time );
				return 'free';
			}
			if ( 'extra' === $s['kind'] ) {
				// Ora suplimentară liberă: o eliminăm.
				self::clear_override( $date, $time );
				return 'removed';
			}
			self::set_override( $date, $time, 'block', $reason );
			return 'blocked';
		}
		return 'missing';
	}
}
