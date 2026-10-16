<?php
defined( 'ABSPATH' ) || exit;

/**
 * Programările: creare, modificare, anulare, interogări și statistici.
 * Toate modificările trec prin această clasă, ca emailurile și webhook-urile să plece consecvent.
 */
class SCP_Bookings {

	const ACTIVE_STATUSES = array( 'pending', 'confirmed', 'completed', 'no_show' );

	private static function table(): string {
		return SCP_Install::table( 'bookings' );
	}

	public static function get( int $id ): ?array {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE id = %d', $id ), ARRAY_A );
		return $row ? self::hydrate( $row ) : null;
	}

	public static function get_by_token( string $token ): ?array {
		global $wpdb;
		if ( ! preg_match( '/^[a-f0-9]{32}$/', $token ) ) {
			return null;
		}
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE token = %s', $token ), ARRAY_A );
		return $row ? self::hydrate( $row ) : null;
	}

	private static function hydrate( array $row ): array {
		$row['id']       = (int) $row['id'];
		$row['price']    = (float) $row['price'];
		$row['list_price'] = (float) ( $row['list_price'] ?? 0 ) ?: $row['price'];
		$row['discount']   = (float) ( $row['discount'] ?? 0 );
		$row['order_id'] = $row['order_id'] ? (int) $row['order_id'] : null;
		$meta            = json_decode( (string) $row['meta'], true );
		$row['meta']     = is_array( $meta ) ? $meta : array();
		return $row;
	}

	/**
	 * Validează și curăță datele venite din formular sau din admin.
	 *
	 * @return array|WP_Error
	 */
	public static function validate( array $in, bool $admin = false ) {
		$errors   = array();
		$packages = SCP_Settings::packages();

		$data = array(
			'slot_date' => sanitize_text_field( $in['date'] ?? '' ),
			'slot_time' => sanitize_text_field( $in['time'] ?? '' ),
			'package'   => sanitize_key( $in['package'] ?? '' ),
			'name'      => trim( sanitize_text_field( $in['name'] ?? '' ) ),
			'email'     => trim( sanitize_email( $in['email'] ?? '' ) ),
			'phone'     => SCP_Helpers::normalize_phone( sanitize_text_field( $in['phone'] ?? '' ) ),
			'message'   => trim( sanitize_textarea_field( $in['message'] ?? '' ) ),
		);
		$voucher = strtoupper( trim( sanitize_text_field( $in['voucher'] ?? '' ) ) );

		if ( ! SCP_Helpers::is_date( $data['slot_date'] ) ) {
			$errors['date'] = 'Alege o zi din calendar.';
		}
		if ( ! SCP_Helpers::is_time( $data['slot_time'] ) ) {
			$errors['time'] = 'Alege o oră.';
		}
		if ( ! isset( $packages[ $data['package'] ] ) ) {
			$errors['package'] = 'Alege unul dintre pachete.';
		}
		if ( mb_strlen( $data['name'] ) < 3 ) {
			$errors['name'] = 'Scrie numele complet.';
		}
		if ( mb_strlen( $data['name'] ) > 190 ) {
			$data['name'] = mb_substr( $data['name'], 0, 190 );
		}
		if ( ! $admin || '' !== $data['email'] ) {
			if ( ! is_email( $data['email'] ) ) {
				$errors['email'] = 'Adresa de email nu pare corectă.';
			}
		}
		if ( ! $admin || '' !== $data['phone'] ) {
			if ( ! SCP_Helpers::phone_is_valid( $data['phone'] ) ) {
				$errors['phone'] = 'Numărul de telefon nu pare corect.';
			}
		}
		if ( $admin && '' === $data['email'] && '' === $data['phone'] ) {
			$errors['phone'] = 'Completează măcar telefonul sau emailul.';
		}
		if ( mb_strlen( $data['message'] ) > 2000 ) {
			$data['message'] = mb_substr( $data['message'], 0, 2000 );
		}
		if ( ! $admin && empty( $in['gdpr'] ) ) {
			$errors['gdpr'] = 'Pentru programare e nevoie de acordul tău cu termenii și condițiile.';
		}

		if ( $errors ) {
			return new WP_Error( 'scp_invalid', 'Verifică datele din formular.', array( 'status' => 422, 'fields' => $errors ) );
		}

		$q = SCP_Pricing::quote( $data['package'] );
		if ( '' !== $voucher ) {
			$chk = SCP_Vouchers::check( $voucher, $data['package'] );
			if ( ! $chk['ok'] ) {
				return new WP_Error( 'scp_invalid', 'Verifică voucherul.', array( 'status' => 422, 'fields' => array( 'voucher' => $chk['message'] ) ) );
			}
			// Voucherul cadou plătește integral ședința, la prețul întreg al pachetului.
			$data['voucher_id'] = (int) $chk['voucher']['id'];
			$q = array( 'list' => $q['list'], 'discount' => 0.0, 'price' => $q['list'], 'code' => $chk['voucher']['code'], 'source' => 'gift' );
		}
		$data['list_price']      = $q['list'];
		$data['discount']        = $q['discount'];
		$data['discount_code']   = $q['code'];
		$data['discount_source'] = $q['source'];
		$data['price']           = $q['price'];
		return $data;
	}

	/**
	 * Creează o programare.
	 *
	 * @param array  $in     Date brute.
	 * @param string $source online | phone | admin
	 * @return array|WP_Error Programarea salvată.
	 */
	public static function create( array $in, string $source = 'online', array $extra_meta = array() ) {
		global $wpdb;
		$admin = 'online' !== $source;
		$data  = self::validate( $in, $admin );
		if ( is_wp_error( $data ) ) {
			return $data;
		}

		if ( ! $admin ) {
			if ( ! SCP_Settings::get( 'booking_open' ) ) {
				return new WP_Error( 'scp_closed', (string) SCP_Settings::get( 'closed_message' ), array( 'status' => 403 ) );
			}
			if ( ! SCP_Availability::is_bookable( $data['slot_date'], $data['slot_time'] ) ) {
				return new WP_Error( 'scp_slot_taken', 'Ne pare rău, ora aleasă tocmai s-a ocupat. Alege te rog altă oră.', array( 'status' => 409 ) );
			}
		}

		$voucher_id = (int) ( $data['voucher_id'] ?? 0 );
		unset( $data['voucher_id'] );
		if ( $voucher_id && ! SCP_Vouchers::reserve( $voucher_id ) ) {
			return new WP_Error( 'scp_invalid', 'Verifică voucherul.', array( 'status' => 422, 'fields' => array( 'voucher' => 'Voucherul tocmai a fost folosit pentru altă programare.' ) ) );
		}
		$needs_pay = ! $voucher_id && (int) SCP_Settings::get( 'pay_hours', 0 ) > 0 && ( ! $admin || ! empty( $in['require_payment'] ) );
		if ( $admin && isset( $in['payment_status'] ) && in_array( $in['payment_status'], array( 'paid', 'cash', 'voucher' ), true ) ) {
			$needs_pay = false;
		}
		$now  = SCP_Helpers::mysql_now();
		$row  = array_merge(
			$data,
			array(
				'active_slot'    => $data['slot_date'] . ' ' . $data['slot_time'],
				'status'         => $needs_pay ? 'pending' : 'confirmed',
				'pay_by'         => $needs_pay ? SCP_Pricing::pay_by( $data['slot_date'], $data['slot_time'] ) : null,
				'payment_status' => $voucher_id ? 'voucher' : 'unpaid',
				'paid_at'        => $voucher_id ? $now : null,
				'source'         => array_key_exists( $source, SCP_Helpers::SOURCES ) ? $source : 'admin',
				'notes'          => $admin ? trim( sanitize_textarea_field( $in['notes'] ?? '' ) ) : '',
				'gdpr_at'        => $admin ? null : $now,
				'meta'           => wp_json_encode( $extra_meta ),
				'created_at'     => $now,
				'updated_at'     => $now,
			)
		);

		// Codul și tokenul sunt unice; reîncercăm în cazul (foarte rar) în care se repetă.
		for ( $i = 0; $i < 5; $i++ ) {
			$row['code']  = SCP_Helpers::random_code( 6 );
			$row['token'] = bin2hex( random_bytes( 16 ) );

			$wpdb->suppress_errors( true );
			$ok  = $wpdb->insert( self::table(), $row );
			$err = $wpdb->last_error;
			$wpdb->suppress_errors( false );

			if ( $ok ) {
				$booking = self::get( (int) $wpdb->insert_id );
				if ( $voucher_id ) {
					SCP_Vouchers::attach_booking( $voucher_id, $booking['id'] );
				}
				do_action( 'scp_booking_created', $booking );
				return $booking;
			}
			if ( ! str_contains( $err, 'Duplicate' ) || str_contains( $err, 'active_slot' ) ) {
				if ( $voucher_id ) {
					SCP_Vouchers::release( $voucher_id );
				}
			}
			if ( str_contains( $err, 'active_slot' ) ) {
				return new WP_Error( 'scp_slot_taken', 'Ne pare rău, ora aleasă tocmai s-a ocupat. Alege te rog altă oră.', array( 'status' => 409 ) );
			}
			if ( ! str_contains( $err, 'Duplicate' ) ) {
				return new WP_Error( 'scp_db', 'Programarea nu a putut fi salvată. Te rugăm să ne suni.', array( 'status' => 500, 'db' => $err ) );
			}
		}
		return new WP_Error( 'scp_db', 'Programarea nu a putut fi salvată. Te rugăm să ne suni.', array( 'status' => 500 ) );
	}

	/**
	 * Actualizează o programare din admin. Gestionează mutarea pe alt slot și anularea.
	 *
	 * @return array|WP_Error
	 */
	public static function update( int $id, array $changes ) {
		global $wpdb;
		$before = self::get( $id );
		if ( ! $before ) {
			return new WP_Error( 'scp_not_found', 'Programarea nu există.', array( 'status' => 404 ) );
		}

		$set      = array();
		$packages = SCP_Settings::packages();

		foreach ( array( 'name', 'phone', 'email' ) as $f ) {
			if ( array_key_exists( $f, $changes ) ) {
				$v = sanitize_text_field( (string) $changes[ $f ] );
				if ( 'email' === $f ) {
					$v = sanitize_email( $v );
					if ( '' !== $v && ! is_email( $v ) ) {
						return new WP_Error( 'scp_invalid', 'Adresa de email nu pare corectă.', array( 'status' => 422 ) );
					}
				}
				if ( 'phone' === $f ) {
					$v = SCP_Helpers::normalize_phone( $v );
				}
				if ( 'name' === $f && mb_strlen( trim( $v ) ) < 2 ) {
					return new WP_Error( 'scp_invalid', 'Numele este obligatoriu.', array( 'status' => 422 ) );
				}
				$set[ $f ] = trim( $v );
			}
		}
		foreach ( array( 'message', 'notes' ) as $f ) {
			if ( array_key_exists( $f, $changes ) ) {
				$set[ $f ] = trim( sanitize_textarea_field( (string) $changes[ $f ] ) );
			}
		}
		// Pachet și voucher cadou. Prețul se recalculează după regulile de la momentul rezervării.
		$pkg        = $before['package'];
		$old_gift   = 'gift' === $before['discount_source'] ? $before['discount_code'] : '';
		$gift       = $old_gift;
		$reprice    = false;
		$gift_event = '';
		if ( isset( $changes['package'] ) && $changes['package'] !== $before['package'] ) {
			$pkg = sanitize_key( $changes['package'] );
			if ( ! isset( $packages[ $pkg ] ) ) {
				return new WP_Error( 'scp_invalid', 'Pachet necunoscut.', array( 'status' => 422 ) );
			}
			$set['package'] = $pkg;
			$reprice        = true;
		}
		if ( array_key_exists( 'voucher', $changes ) ) {
			$gift = trim( sanitize_text_field( (string) $changes['voucher'] ) );
		}
		if ( SCP_Vouchers::normalize( $gift ) !== SCP_Vouchers::normalize( $old_gift ) || ( $gift && $reprice ) ) {
			if ( '' !== $gift ) {
				$chk = SCP_Vouchers::check( $gift, $pkg, $id );
				if ( ! $chk['ok'] ) {
					return new WP_Error( 'scp_invalid', 'Voucher: ' . $chk['message'], array( 'status' => 422 ) );
				}
				if ( (int) $chk['voucher']['booking_id'] !== $id && ! SCP_Vouchers::reserve( (int) $chk['voucher']['id'], $id ) ) {
					return new WP_Error( 'scp_invalid', 'Voucherul tocmai a fost folosit pentru altă programare.', array( 'status' => 422 ) );
				}
				if ( $old_gift && ( $old = SCP_Vouchers::get_by_code( $old_gift ) ) && (int) $old['id'] !== (int) $chk['voucher']['id'] ) {
					SCP_Vouchers::release( (int) $old['id'] );
				}
				$set['list_price']      = (float) $packages[ $pkg ]['price'];
				$set['discount']        = 0;
				$set['discount_code']   = $chk['voucher']['code'];
				$set['discount_source'] = 'gift';
				$set['price']           = (float) $packages[ $pkg ]['price'];
				$changes['payment_status'] = 'voucher';
				$reprice = false;
			} else {
				if ( $old_gift && ( $old = SCP_Vouchers::get_by_code( $old_gift ) ) ) {
					SCP_Vouchers::release( (int) $old['id'] );
				}
				$reprice = true;
				if ( 'voucher' === $before['payment_status'] && ( $changes['payment_status'] ?? 'voucher' ) === 'voucher' ) {
					$changes['payment_status'] = 'unpaid';
				}
			}
		}
		if ( $reprice ) {
			$created = new DateTimeImmutable( $before['created_at'], SCP_Helpers::tz() );
			$q       = SCP_Pricing::quote( $pkg, $created );
			$set['list_price']      = $q['list'];
			$set['discount']        = $q['discount'];
			$set['discount_code']   = $q['code'];
			$set['discount_source'] = $q['source'];
			$set['price']           = $q['price'];
		}
		// Preț scris manual, diferit de cel calculat: îl păstrăm ca atare.
		if ( isset( $changes['price'] ) && '' !== $changes['price'] ) {
			$manual   = max( 0, (float) str_replace( ',', '.', (string) $changes['price'] ) );
			$computed = (float) ( $set['price'] ?? $before['price'] );
			if ( abs( $manual - $computed ) > 0.009 && ( ! $reprice || abs( $manual - (float) $before['price'] ) > 0.009 ) ) {
				$set['price']           = $manual;
				$set['list_price']      = $set['list_price'] ?? $before['list_price'];
				$set['discount']        = max( 0, (float) $set['list_price'] - $manual );
				$set['discount_code']   = '';
				$set['discount_source'] = 'manual';
			}
		}

		$status = $before['status'];
		if ( isset( $changes['status'] ) && array_key_exists( $changes['status'], SCP_Helpers::STATUSES ) ) {
			$status = $changes['status'];
		}

		if ( isset( $changes['payment_status'] ) && array_key_exists( $changes['payment_status'], SCP_Helpers::PAYMENT_STATUSES ) ) {
			$set['payment_status'] = $changes['payment_status'];
			if ( in_array( $changes['payment_status'], array( 'paid', 'cash', 'voucher' ), true ) ) {
				if ( ! $before['paid_at'] ) {
					$set['paid_at'] = SCP_Helpers::mysql_now();
				}
				// O programare plătită nu mai poate expira.
				if ( 'pending' === $status ) {
					$status = 'confirmed';
				}
				$set['pay_by'] = null;
			}
		}
		if ( 'pending' === $status && ! $before['pay_by'] && ! array_key_exists( 'pay_by', $set ) ) {
			$set['pay_by'] = SCP_Pricing::pay_by( $before['slot_date'], $before['slot_time'] );
		}
		if ( in_array( $status, array( 'confirmed', 'completed', 'no_show' ), true ) && 'pending' === $before['status'] ) {
			$set['pay_by'] = null;
		}
		if ( array_key_exists( 'pay_by', $changes ) ) {
			$set['pay_by'] = $changes['pay_by'];
			$set['reminder_sent_at'] = null;
		}

		$date = $before['slot_date'];
		$time = $before['slot_time'];
		if ( isset( $changes['date'] ) || isset( $changes['time'] ) ) {
			$date = sanitize_text_field( $changes['date'] ?? $date );
			$time = sanitize_text_field( $changes['time'] ?? $time );
			if ( ! SCP_Helpers::is_date( $date ) || ! SCP_Helpers::is_time( $time ) ) {
				return new WP_Error( 'scp_invalid', 'Data sau ora nu sunt valide.', array( 'status' => 422 ) );
			}
		}

		$set['slot_date'] = $date;
		$set['slot_time'] = $time;
		if ( 'cancelled' === $status ) {
			$set['active_slot'] = null;
			$set['status']      = 'cancelled';
			$set['pay_by']      = null;
			if ( 'cancelled' !== $before['status'] ) {
				$set['cancelled_at']  = SCP_Helpers::mysql_now();
				$set['cancel_reason'] = sanitize_key( (string) ( $changes['cancel_reason'] ?? 'admin' ) );
			}
		} else {
			$set['active_slot'] = $date . ' ' . $time;
			$set['status']        = $status;
			$set['cancelled_at']  = null;
			$set['cancel_reason'] = '';
		}
		$set['updated_at'] = SCP_Helpers::mysql_now();

		$wpdb->suppress_errors( true );
		$ok  = $wpdb->update( self::table(), $set, array( 'id' => $id ) );
		$err = $wpdb->last_error;
		$wpdb->suppress_errors( false );

		if ( false === $ok ) {
			if ( str_contains( $err, 'active_slot' ) ) {
				return new WP_Error( 'scp_slot_taken', 'Pe acest slot există deja o altă programare activă.', array( 'status' => 409 ) );
			}
			return new WP_Error( 'scp_db', 'Modificarea nu a putut fi salvată.', array( 'status' => 500, 'db' => $err ) );
		}

		$after = self::get( $id );
		self::emit_change( $before, $after, $changes );
		return $after;
	}

	private static function emit_change( array $before, array $after, array $changes ): void {
		$moved      = $before['slot_date'] !== $after['slot_date'] || $before['slot_time'] !== $after['slot_time'];
		$cancelled  = 'cancelled' === $after['status'] && 'cancelled' !== $before['status'];
		$paid       = in_array( $after['payment_status'], array( 'paid', 'cash', 'voucher' ), true ) && ! in_array( $before['payment_status'], array( 'paid', 'cash', 'voucher' ), true );
		$notify     = ! empty( $changes['notify_client'] );

		if ( $cancelled ) {
			do_action( 'scp_booking_cancelled', $after, $notify, $after['cancel_reason'] );
		} elseif ( $moved && 'cancelled' !== $after['status'] ) {
			do_action( 'scp_booking_rescheduled', $after, $before, $notify );
		}
		if ( $paid ) {
			do_action( 'scp_booking_paid', $after );
		}
		if ( ! $cancelled ) {
			do_action( 'scp_booking_updated', $after, $before );
		}
	}

	/** Marchează trimiterea linkului de plată. */
	public static function mark_link_sent( int $id ): ?array {
		global $wpdb;
		$b = self::get( $id );
		if ( ! $b ) {
			return null;
		}
		$set = array(
			'payment_link_sent_at' => SCP_Helpers::mysql_now(),
			'updated_at'           => SCP_Helpers::mysql_now(),
		);
		if ( 'unpaid' === $b['payment_status'] ) {
			$set['payment_status'] = 'link_sent';
		}
		$wpdb->update( self::table(), $set, array( 'id' => $id ) );
		return self::get( $id );
	}

	/** Marchează plata online (din comanda WooCommerce). */
	public static function mark_paid_online( int $id, int $order_id ): ?array {
		global $wpdb;
		$b = self::get( $id );
		if ( ! $b || 'paid' === $b['payment_status'] ) {
			return $b;
		}
		$wpdb->update(
			self::table(),
			array(
				'payment_status' => 'paid',
				'status'         => 'pending' === $b['status'] ? 'confirmed' : $b['status'],
				'pay_by'         => null,
				'paid_at'        => SCP_Helpers::mysql_now(),
				'order_id'       => $order_id,
				'updated_at'     => SCP_Helpers::mysql_now(),
			),
			array( 'id' => $id )
		);
		$after = self::get( $id );
		do_action( 'scp_booking_paid', $after );
		do_action( 'scp_booking_updated', $after, $b );
		return $after;
	}

	/**
	 * Reamintiri și anulări automate pentru programările neplătite.
	 * Rulează din cron și, ca rezervă, cel mult o dată la 5 minute la încărcarea site-ului.
	 */
	public static function sweep(): array {
		global $wpdb;
		$t    = self::table();
		$now  = SCP_Helpers::mysql_now();
		$done = array( 'expired' => 0, 'reminded' => 0 );

		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$t} WHERE status = 'pending' AND pay_by IS NOT NULL AND pay_by <= %s AND payment_status NOT IN ('paid','cash','voucher') LIMIT 50", $now ) );
		foreach ( $ids as $id ) {
			$r = self::update(
				(int) $id,
				array(
					'status'        => 'cancelled',
					'cancel_reason' => 'expired',
					'notify_client' => true,
				)
			);
			if ( ! is_wp_error( $r ) ) {
				++$done['expired'];
			}
		}

		$hours = (int) SCP_Settings::get( 'pay_reminder_hours', 0 );
		if ( $hours > 0 ) {
			$limit = SCP_Helpers::now()->modify( "+{$hours} hours" )->format( 'Y-m-d H:i:s' );
			// Doar dacă termenul total a fost mai lung decât fereastra de reamintire (altfel ar pleca imediat după rezervare).
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT id FROM {$t} WHERE status = 'pending' AND pay_by IS NOT NULL AND pay_by > %s AND pay_by <= %s AND reminder_sent_at IS NULL
					 AND TIMESTAMPDIFF(MINUTE, created_at, pay_by) > %d AND payment_status NOT IN ('paid','cash','voucher') LIMIT 50",
					$now,
					$limit,
					( $hours + 1 ) * 60
				),
				ARRAY_A
			);
			foreach ( $rows as $r ) {
				$wpdb->update( $t, array( 'reminder_sent_at' => $now ), array( 'id' => (int) $r['id'] ) );
				$b = self::get( (int) $r['id'] );
				if ( $b ) {
					do_action( 'scp_payment_reminder', $b );
					++$done['reminded'];
				}
			}
		}
		return $done;
	}

	public static function payment_url( array $b ): string {
		$tpl = (string) SCP_Settings::get( 'payment_url', '' );
		if ( '' === trim( $tpl ) ) {
			return '';
		}
		$url = strtr(
			$tpl,
			array(
				'{token}'   => $b['token'],
				'{cod}'     => rawurlencode( $b['code'] ),
				'{pachet}'  => rawurlencode( $b['package'] ),
			)
		);
		return SCP_Helpers::absolute_url( $url );
	}

	/* ---------- Interogări ---------- */

	/**
	 * @param array $args status, payment_status, package, source, from, to, search, orderby, order, page, per_page
	 * @return array{items:array,total:int}
	 */
	public static function query( array $args = array() ): array {
		global $wpdb;
		$where = array( '1=1' );
		$vals  = array();

		if ( ! empty( $args['status'] ) ) {
			if ( 'active' === $args['status'] ) {
				$where[] = 'active_slot IS NOT NULL';
			} else {
				$where[] = 'status = %s';
				$vals[]  = $args['status'];
			}
		}
		foreach ( array( 'payment_status', 'package', 'source' ) as $f ) {
			if ( ! empty( $args[ $f ] ) ) {
				$where[] = "{$f} = %s";
				$vals[]  = $args[ $f ];
			}
		}
		if ( ! empty( $args['from'] ) && SCP_Helpers::is_date( $args['from'] ) ) {
			$where[] = 'slot_date >= %s';
			$vals[]  = $args['from'];
		}
		if ( ! empty( $args['to'] ) && SCP_Helpers::is_date( $args['to'] ) ) {
			$where[] = 'slot_date <= %s';
			$vals[]  = $args['to'];
		}
		if ( ! empty( $args['search'] ) ) {
			$like    = '%' . $wpdb->esc_like( $args['search'] ) . '%';
			$digits  = preg_replace( '/\D+/', '', $args['search'] );
			$where[] = '(name LIKE %s OR email LIKE %s OR phone LIKE %s OR code LIKE %s OR REPLACE(phone, \' \', \'\') LIKE %s)';
			array_push( $vals, $like, $like, $like, $like, '%' . ( $digits ?: '__none__' ) . '%' );
		}

		$orderby_map = array(
			'slot'    => 'slot_date %s, slot_time %s',
			'created' => 'created_at %s',
			'name'    => 'name %s',
		);
		$orderby = $orderby_map[ $args['orderby'] ?? 'slot' ] ?? $orderby_map['slot'];
		$order   = 'desc' === strtolower( $args['order'] ?? 'asc' ) ? 'DESC' : 'ASC';
		$orderby = str_replace( '%s', $order, $orderby );

		$per_page = max( 1, min( 1000, (int) ( $args['per_page'] ?? 50 ) ) );
		$page     = max( 1, (int) ( $args['page'] ?? 1 ) );
		$offset   = ( $page - 1 ) * $per_page;

		$sql_where = implode( ' AND ', $where );
		$table     = self::table();
		$count_sql = "SELECT COUNT(*) FROM {$table} WHERE {$sql_where}";
		$list_sql  = "SELECT * FROM {$table} WHERE {$sql_where} ORDER BY {$orderby} LIMIT {$per_page} OFFSET {$offset}";

		$total = (int) ( $vals ? $wpdb->get_var( $wpdb->prepare( $count_sql, $vals ) ) : $wpdb->get_var( $count_sql ) );
		$rows  = $vals ? $wpdb->get_results( $wpdb->prepare( $list_sql, $vals ), ARRAY_A ) : $wpdb->get_results( $list_sql, ARRAY_A );

		return array(
			'items' => array_map( array( __CLASS__, 'hydrate' ), $rows ),
			'total' => $total,
		);
	}

	/** Cifrele pentru panou. Toate sunt calculate din programările salvate. */
	public static function stats(): array {
		global $wpdb;
		$t     = self::table();
		$today = SCP_Helpers::now()->format( 'Y-m-d' );
		$week  = SCP_Helpers::now()->modify( '-6 days' )->format( 'Y-m-d 00:00:00' );

		$active = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$t} WHERE active_slot IS NOT NULL" );

		$by_package = $wpdb->get_results( "SELECT package, COUNT(*) n, SUM(price) v FROM {$t} WHERE active_slot IS NOT NULL GROUP BY package", ARRAY_A );
		$by_pay     = $wpdb->get_results( "SELECT payment_status, COUNT(*) n, SUM(price) v FROM {$t} WHERE active_slot IS NOT NULL GROUP BY payment_status", ARRAY_A );
		$by_source  = $wpdb->get_results( "SELECT source, COUNT(*) n FROM {$t} WHERE active_slot IS NOT NULL GROUP BY source", ARRAY_A );

		$last7     = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$t} WHERE created_at >= %s", $week ) );
		$cancelled = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$t} WHERE status = 'cancelled'" );
		$value     = (float) $wpdb->get_var( "SELECT COALESCE(SUM(price),0) FROM {$t} WHERE active_slot IS NOT NULL" );

		$per_day = $wpdb->get_results(
			$wpdb->prepare( "SELECT DATE(created_at) d, COUNT(*) n FROM {$t} WHERE created_at >= %s GROUP BY DATE(created_at) ORDER BY d", SCP_Helpers::now()->modify( '-29 days' )->format( 'Y-m-d 00:00:00' ) ),
			ARRAY_A
		);

		// Gradul de ocupare din sezon: sloturi ocupate / (ocupate + libere), de azi până la final.
		$from     = max( $today, (string) SCP_Settings::get( 'start_date' ) );
		$to       = (string) SCP_Settings::get( 'end_date' );
		$free     = 0;
		$booked   = 0;
		$blocked  = 0;
		if ( $from <= $to ) {
			foreach ( SCP_Availability::range( $from, $to, true ) as $day ) {
				foreach ( $day['slots'] as $s ) {
					if ( 'free' === $s['state'] ) {
						++$free;
					} elseif ( 'booked' === $s['state'] ) {
						++$booked;
					} elseif ( 'blocked' === $s['state'] ) {
						++$blocked;
					}
				}
			}
		}

		$today_list = self::query(
			array(
				'status'   => 'active',
				'from'     => $today,
				'to'       => $today,
				'per_page' => 50,
			)
		)['items'];

		$upcoming = self::query(
			array(
				'status'   => 'active',
				'from'     => SCP_Helpers::now()->modify( '+1 day' )->format( 'Y-m-d' ),
				'per_page' => 8,
			)
		)['items'];

		$recent = self::query(
			array(
				'orderby'  => 'created',
				'order'    => 'desc',
				'per_page' => 8,
			)
		)['items'];

		return array(
			'active'     => $active,
			'target'     => (int) SCP_Settings::get( 'target', 0 ),
			'last7'      => $last7,
			'cancelled'  => $cancelled,
			'value'      => $value,
			'by_package' => $by_package,
			'by_pay'     => $by_pay,
			'by_source'  => $by_source,
			'per_day'    => $per_day,
			'free'       => $free,
			'booked'     => $booked,
			'blocked'    => $blocked,
			'today'      => $today_list,
			'upcoming'   => $upcoming,
			'recent'     => $recent,
		);
	}

	/** Reprezentarea publică/externă a unei programări (folosită în webhook și export). */
	public static function export( array $b ): array {
		$packages = SCP_Settings::packages();
		$created  = new DateTimeImmutable( $b['created_at'], SCP_Helpers::tz() );
		return array(
			'id'                   => $b['id'],
			'code'                 => $b['code'],
			'status'               => $b['status'],
			'status_label'         => SCP_Helpers::STATUSES[ $b['status'] ] ?? $b['status'],
			'payment_status'       => $b['payment_status'],
			'payment_status_label' => SCP_Helpers::PAYMENT_STATUSES[ $b['payment_status'] ] ?? $b['payment_status'],
			'package'              => $b['package'],
			'package_label'        => $packages[ $b['package'] ]['label'] ?? $b['package'],
			'price'                => (float) $b['price'],
			'list_price'           => (float) $b['list_price'],
			'discount'             => (float) $b['discount'],
			'discount_code'        => (string) $b['discount_code'],
			'discount_source'      => (string) $b['discount_source'],
			'pay_by'               => $b['pay_by'] ? ( new DateTimeImmutable( $b['pay_by'], SCP_Helpers::tz() ) )->format( DATE_ATOM ) : null,
			'pay_by_ro'            => $b['pay_by'] ? ( new DateTimeImmutable( $b['pay_by'], SCP_Helpers::tz() ) )->format( 'd.m.Y H:i' ) : '',
			'cancel_reason'        => (string) $b['cancel_reason'],
			'currency'             => 'RON',
			'date'                 => $b['slot_date'],
			'time'                 => $b['slot_time'],
			'date_ro'              => SCP_Helpers::date_ro( $b['slot_date'] ),
			'weekday_ro'           => SCP_Helpers::WEEKDAYS[ SCP_Helpers::weekday( $b['slot_date'] ) ],
			'starts_at'            => SCP_Helpers::dt( $b['slot_date'], $b['slot_time'] )->format( DATE_ATOM ),
			'name'                 => $b['name'],
			'email'                => $b['email'],
			'phone'                => $b['phone'],
			'message'              => (string) $b['message'],
			'notes'                => (string) $b['notes'],
			'source'               => $b['source'],
			'source_label'         => SCP_Helpers::SOURCES[ $b['source'] ] ?? $b['source'],
			'payment_link'         => self::payment_url( $b ),
			'payment_link_sent_at' => $b['payment_link_sent_at'] ? ( new DateTimeImmutable( $b['payment_link_sent_at'], SCP_Helpers::tz() ) )->format( DATE_ATOM ) : null,
			'paid_at'              => $b['paid_at'] ? ( new DateTimeImmutable( $b['paid_at'], SCP_Helpers::tz() ) )->format( DATE_ATOM ) : null,
			'order_id'             => $b['order_id'],
			'created_at'           => $created->format( DATE_ATOM ),
			'created_date_ro'      => $created->format( 'd/m/Y' ),
			'created_time_ro'      => $created->format( 'H:i' ),
			'updated_at'           => ( new DateTimeImmutable( $b['updated_at'], SCP_Helpers::tz() ) )->format( DATE_ATOM ),
			'utm'                  => $b['meta']['utm'] ?? (object) array(),
			'admin_url'            => admin_url( 'admin.php?page=scp-bookings&booking=' . $b['id'] ),
		);
	}
}
