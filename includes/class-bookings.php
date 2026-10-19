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

	/** Plătită (online, cash, card sau voucher). */
	public static function is_paid( array $b ): bool {
		return in_array( $b['payment_status'], SCP_Helpers::PAID, true );
	}

	/** Confirmată cu plata la studio (cash sau card), încă neîncasată. */
	public static function pays_at_studio( array $b ): bool {
		$meta = is_array( $b['meta'] ?? null ) ? $b['meta'] : (array) json_decode( (string) ( $b['meta'] ?? '' ), true );
		return 'studio' === ( $meta['pay_at'] ?? '' ) && 'confirmed' === $b['status'] && ! self::is_paid( $b );
	}

	/** Ședința e sigură: plătită sau confirmată cu plata la studio. Primește confirmarea și reamintirile. */
	public static function is_secured( array $b ): bool {
		return 'cancelled' !== $b['status'] && ( self::is_paid( $b ) || self::pays_at_studio( $b ) );
	}

	/**
	 * Starea plății pe înțelesul tuturor, folosită peste tot în admin și în Sheet.
	 *
	 * @return array{key:string,label:string,short:string}
	 */
	public static function pay_state( array $b ): array {
		$map = array(
			'paid'    => array( 'paid', 'Plătită online', 'Plătită online' ),
			'cash'    => array( 'paid', 'Plătită cash la studio', 'Plătită cash' ),
			'card'    => array( 'paid', 'Plătită cu cardul la studio', 'Plătită card' ),
			'voucher' => array( 'paid', 'Plătită cu voucher', 'Voucher' ),
		);
		if ( isset( $map[ $b['payment_status'] ] ) ) {
			[ $key, $label, $short ] = $map[ $b['payment_status'] ];
		} elseif ( 'cancelled' === $b['status'] ) {
			[ $key, $label, $short ] = array( 'none', 'Neplătită', 'Neplătită' );
		} elseif ( 'pending' === $b['status'] ) {
			$until = $b['pay_by'] ? ( new DateTimeImmutable( $b['pay_by'], SCP_Helpers::tz() ) )->format( 'd.m H:i' ) : '';
			[ $key, $label, $short ] = array( 'awaiting', 'Așteaptă plata online' . ( $until ? ' până pe ' . $until : '' ), 'Așteaptă plata' . ( $until ? ' · ' . $until : '' ) );
		} elseif ( self::pays_at_studio( $b ) ) {
			[ $key, $label, $short ] = array( 'studio', 'Plată la studio (cash sau card)', 'Plată la studio' );
		} else {
			[ $key, $label, $short ] = array( 'unpaid', 'Neplătită', 'Neplătită' );
		}
		return array( 'key' => $key, 'label' => $label, 'short' => $short );
	}

	/**
	 * Trece o programare care așteaptă plata online pe plata la studio:
	 * se confirmă, termenul dispare și nu mai expiră.
	 *
	 * @return array|WP_Error
	 */
	public static function move_to_studio( int $id ) {
		$b = self::get( $id );
		if ( ! $b ) {
			return new WP_Error( 'scp_not_found', 'Programarea nu există.', array( 'status' => 404 ) );
		}
		if ( 'cancelled' === $b['status'] || self::is_paid( $b ) ) {
			return new WP_Error( 'scp_invalid', 'Programarea e anulată sau deja plătită.', array( 'status' => 422 ) );
		}
		$after = self::update(
			$id,
			array(
				'status'  => 'confirmed',
				'pay_by'  => null,
				'meta'    => array( 'pay_at' => 'studio' ),
			)
		);
		if ( ! is_wp_error( $after ) ) {
			do_action( 'scp_booking_studio', $after );
		}
		return $after;
	}

	/**
	 * Încasare la studio, cash sau cu cardul.
	 *
	 * @return array|WP_Error
	 */
	public static function collect( int $id, string $method ) {
		if ( ! in_array( $method, array( 'cash', 'card' ), true ) ) {
			return new WP_Error( 'scp_invalid', 'Metodă de plată necunoscută.', array( 'status' => 422 ) );
		}
		$b = self::get( $id );
		if ( ! $b || 'cancelled' === $b['status'] ) {
			return new WP_Error( 'scp_not_found', 'Programarea nu există sau e anulată.', array( 'status' => 404 ) );
		}
		if ( self::is_paid( $b ) ) {
			return new WP_Error( 'scp_invalid', 'Programarea e deja plătită.', array( 'status' => 422 ) );
		}
		return self::update( $id, array( 'payment_status' => $method ) );
	}

	/**
	 * Validează și curăță datele venite din formular sau din admin.
	 *
	 * @return array|WP_Error
	 */
	public static function validate( array $in, bool $admin = false ) {
		$errors   = array();
		$packages = SCP_Settings::packages();
		foreach ( array( 'date', 'time', 'package', 'name', 'email', 'phone', 'message', 'voucher', 'notes' ) as $k ) {
			$in[ $k ] = isset( $in[ $k ] ) && is_scalar( $in[ $k ] ) ? (string) $in[ $k ] : '';
		}

		$data = array(
			'slot_date' => sanitize_text_field( $in['date'] ),
			'slot_time' => sanitize_text_field( $in['time'] ),
			'package'   => sanitize_key( $in['package'] ),
			'name'      => trim( sanitize_text_field( $in['name'] ) ),
			'email'     => trim( sanitize_email( $in['email'] ) ),
			'phone'     => SCP_Helpers::normalize_phone( sanitize_text_field( $in['phone'] ) ),
			'message'   => trim( sanitize_textarea_field( $in['message'] ) ),
		);
		$voucher = strtoupper( trim( sanitize_text_field( $in['voucher'] ) ) );

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
				return new WP_Error( 'scp_invalid', 'Verifică voucherul.', array( 'status' => 422, 'fields' => array( 'voucher' => $admin ? $chk['detail'] : $chk['message'] ) ) );
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
		if ( $admin && isset( $in['payment_status'] ) && in_array( $in['payment_status'], SCP_Helpers::PAID, true ) ) {
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
		if ( $voucher_id ) {
			SCP_Vouchers::release( $voucher_id );
		}
		return new WP_Error( 'scp_db', 'Programarea nu a putut fi salvată. Te rugăm să ne suni.', array( 'status' => 500 ) );
	}

	/** Câte programări neplătite, încă active, are același telefon sau email (pentru limita din formularul public). */
	public static function open_unpaid_count( string $phone, string $email ): int {
		global $wpdb;
		if ( '' === $phone && '' === $email ) {
			return 0;
		}
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM ' . self::table() . " WHERE status = 'pending' AND ( ( %s <> '' AND phone = %s ) OR ( %s <> '' AND email = %s ) )",
				$phone,
				$phone,
				$email,
				$email
			)
		);
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
		// Chei noi în meta, îmbinate cu ce există (utm etc. rămân).
		if ( ! empty( $changes['meta'] ) && is_array( $changes['meta'] ) ) {
			$set['meta'] = wp_json_encode( array_merge( $before['meta'], $changes['meta'] ) );
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
					return new WP_Error( 'scp_invalid', 'Voucher: ' . $chk['detail'], array( 'status' => 422 ) );
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
			if ( in_array( $changes['payment_status'], SCP_Helpers::PAID, true ) ) {
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
			// termen nou: reamintirile și ultimul apel pleacă din nou, după noul termen
			if ( ! empty( $before['meta']['last_call_at'] ) || ! empty( $before['meta']['reminded'] ) ) {
				$meta = json_decode( (string) ( $set['meta'] ?? wp_json_encode( $before['meta'] ) ), true );
				unset( $meta['last_call_at'], $meta['reminded'] );
				$set['meta'] = wp_json_encode( $meta ?: new stdClass() );
			}
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
		$paid       = in_array( $after['payment_status'], SCP_Helpers::PAID, true ) && ! in_array( $before['payment_status'], SCP_Helpers::PAID, true );
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
		if ( self::is_paid( $b ) ) {
			// Plătită deja la studio sau cu voucher, iar acum a venit și plata online: Ștefan decide rambursarea.
			SCP_Rest::alert_once( 'scp_alert_dbl_' . $id, 'Programare plătită de două ori', 'Programarea ' . $b['code'] . ' (' . $b['name'] . ', ' . SCP_Helpers::date_ro( $b['slot_date'] ) . ' ' . $b['slot_time'] . ') era deja „' . ( SCP_Helpers::PAYMENT_STATUSES[ $b['payment_status'] ] ?? $b['payment_status'] ) . '”, iar acum a venit și plata online (comanda #' . $order_id . '). Verifică și rambursează una dintre plăți.' );
			return $b;
		}
		$now = SCP_Helpers::mysql_now();
		$set = array(
			'payment_status' => 'paid',
			'status'         => 'pending' === $b['status'] ? 'confirmed' : $b['status'],
			'pay_by'         => null,
			'paid_at'        => $now,
			'order_id'       => $order_id,
			'updated_at'     => $now,
		);
		// Plata a ajuns după ce programarea a expirat: o readucem pe ora ei, dacă e încă liberă.
		$late = 'cancelled' === $b['status'];
		if ( $late ) {
			$set = array_merge( $set, array( 'status' => 'confirmed', 'active_slot' => $b['slot_date'] . ' ' . $b['slot_time'], 'cancelled_at' => null, 'cancel_reason' => '' ) );
		}
		// Condiția pe payment_status face actualizarea atomică: două notificări de plată simultane nu confirmă de două ori.
		$wpdb->suppress_errors( true );
		$n   = $wpdb->update( self::table(), $set, array( 'id' => $id, 'payment_status' => $b['payment_status'] ) );
		$err = $wpdb->last_error;
		$wpdb->suppress_errors( false );

		if ( false === $n && $late && str_contains( $err, 'active_slot' ) ) {
			// Ora a fost luată între timp: rămâne anulată, dar plătită. Clientul nu primește confirmare; Ștefan e anunțat.
			unset( $set['status'], $set['active_slot'], $set['cancelled_at'], $set['cancel_reason'] );
			$n = $wpdb->update( self::table(), $set, array( 'id' => $id, 'payment_status' => $b['payment_status'] ) );
			if ( $n ) {
				$after = self::get( $id );
				SCP_Rest::alert_once( 'scp_alert_late_' . $id, 'Plată primită pentru o oră deja ocupată', 'Programarea ' . $b['code'] . ' (' . $b['name'] . ', ' . $b['phone'] . ') expirase, iar ora ' . SCP_Helpers::date_ro( $b['slot_date'] ) . ' ' . $b['slot_time'] . ' a fost luată de altcineva. Plata a venit totuși (comanda #' . $order_id . '). Sună clientul: mută-l pe altă oră din admin sau rambursează plata.' );
				do_action( 'scp_booking_updated', $after, $b );
				return $after;
			}
		}
		if ( ! $n ) {
			return self::get( $id );
		}
		$after = self::get( $id );
		if ( $late ) {
			SCP_Rest::alert_once( 'scp_alert_late_' . $id, 'Plată întârziată: programare reactivată', 'Plata pentru ' . $b['code'] . ' (' . $b['name'] . ') a venit după termen. Ora ' . SCP_Helpers::date_ro( $b['slot_date'] ) . ' ' . $b['slot_time'] . ' era încă liberă, așa că programarea a fost reactivată și confirmată.' );
		}
		do_action( 'scp_booking_paid', $after );
		do_action( 'scp_booking_updated', $after, $b );
		return $after;
	}

	/** Plata online a fost rambursată sau comanda anulată: programarea rămâne, dar neplătită; Ștefan decide. */
	public static function mark_refunded( int $id, int $order_id, string $why ): void {
		$b = self::get( $id );
		if ( ! $b || 'paid' !== $b['payment_status'] || (int) $b['order_id'] !== $order_id ) {
			return;
		}
		$after = self::update( $id, array( 'payment_status' => 'unpaid' ) );
		if ( is_wp_error( $after ) ) {
			return;
		}
		SCP_Rest::alert_once( 'scp_alert_ref_' . $id, 'Plată ' . $why . ' pentru o programare', 'Comanda #' . $order_id . ' a programării ' . $b['code'] . ' (' . $b['name'] . ', ' . SCP_Helpers::date_ro( $b['slot_date'] ) . ' ' . $b['slot_time'] . ') a fost ' . $why . '. Programarea e acum „Neplătită”, dar încă activă. Anuleaz-o din admin dacă e cazul: ' . admin_url( 'admin.php?page=scp-bookings&booking=' . $id ) );
	}

	/** O comandă începută recent pentru programare: nu o anulăm cât timp plata poate fi în curs (Netopia confirmă cu întârziere). */
	private static function payment_in_progress( int $id ): bool {
		if ( ! function_exists( 'wc_get_orders' ) ) {
			return false;
		}
		$ids = wc_get_orders(
			array(
				'limit'        => 1,
				'return'       => 'ids',
				'status'       => array( 'pending', 'on-hold' ),
				'meta_key'     => '_scp_booking_id', // phpcs:ignore
				'meta_value'   => $id, // phpcs:ignore
				'date_created' => '>' . ( time() - 30 * MINUTE_IN_SECONDS ),
			)
		);
		return ! empty( $ids );
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

		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$t} WHERE status = 'pending' AND pay_by IS NOT NULL AND pay_by <= %s AND payment_status NOT IN (" . SCP_Helpers::paid_sql() . ") LIMIT 50", $now ) );
		foreach ( $ids as $id ) {
			if ( self::payment_in_progress( (int) $id ) ) {
				continue;
			}
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

		$done['reminded']  = self::nudges( 'reminder' );
		$done['last_call'] = self::nudges( 'last_call' );
		return $done;
	}

	/** Orele dinaintea expirării la care pleacă emailurile de reamintire (Setări → Reamintiri), descrescător. */
	public static function reminder_hours(): array {
		$out = array();
		foreach ( preg_split( '/[^0-9]+/', (string) SCP_Settings::get( 'pay_reminders', '' ) ) as $h ) {
			if ( (int) $h > 0 && (int) $h <= 336 ) {
				$out[] = (int) $h;
			}
		}
		$out = array_values( array_unique( $out ) );
		rsort( $out );
		return array_slice( $out, 0, 6 );
	}

	/**
	 * Reamintirile pentru programările neplătite, la momentele din nudge_due():
	 * - reminder: emailuri la fiecare interval din reminder_hours(), marcate în meta.reminded;
	 * - last_call: ultimul apel („plătește până la …, ca ora să nu ajungă la altă familie”), pe SMS, cu emailul doar ca rezervă; marcat în meta.last_call_at.
	 * Marcajele se golesc la prelungirea termenului, ca reamintirile să pornească din nou.
	 */
	private static function nudges( string $kind ): int {
		global $wpdb;
		$steps = 'reminder' === $kind ? array_map( static fn( $h ) => $h * 60, self::reminder_hours() ) : array_filter( array( (int) SCP_Settings::get( 'last_call_minutes', 0 ) ) );
		if ( ! $steps ) {
			return 0;
		}
		$t   = self::table();
		$now = SCP_Helpers::now();
		// Mutarea peste noapte aduce un moment cel mult cu ~11 ore mai devreme; 12 ore în plus acoperă tot.
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT id FROM {$t} WHERE status = 'pending' AND pay_by IS NOT NULL AND pay_by > %s AND pay_by <= %s AND payment_status NOT IN (" . SCP_Helpers::paid_sql() . ') ORDER BY pay_by LIMIT 100',
				$now->format( 'Y-m-d H:i:s' ),
				$now->modify( '+' . ( max( $steps ) + 12 * 60 ) . ' minutes' )->format( 'Y-m-d H:i:s' )
			)
		);
		$n = 0;
		foreach ( $ids as $id ) {
			$b = self::get( (int) $id );
			if ( ! $b || ( 'last_call' === $kind && ! empty( $b['meta']['last_call_at'] ) ) ) {
				continue;
			}
			$sent    = array_map( 'intval', (array) ( $b['meta']['reminded'] ?? array() ) );
			$created = new DateTimeImmutable( (string) $b['created_at'], SCP_Helpers::tz() );
			if ( 'reminder' === $kind && ! isset( $b['meta']['reminded'] ) && ! empty( $b['reminder_sent_at'] ) ) {
				// programări de dinainte de 1.3.11: reamintirea deja trimisă acoperă momentele de până atunci
				$at   = new DateTimeImmutable( (string) $b['reminder_sent_at'], SCP_Helpers::tz() );
				$sent = array_values( array_filter( $steps, fn( $m ) => self::nudge_due( $b, $m ) <= $at ) );
			}
			$fire    = false;
			$mark    = array();
			foreach ( $steps as $m ) {
				if ( in_array( $m, $sent, true ) ) {
					continue;
				}
				$due = self::nudge_due( $b, $m );
				if ( $now < $due ) {
					continue;
				}
				// toate momentele trecute se marchează (fără rafale), dar pleacă un singur mesaj și doar dacă nu e chiar lângă rezervare
				$mark[] = $m;
				$fire   = $fire || $due >= $created->modify( '+30 minutes' );
			}
			if ( ! $mark ) {
				continue;
			}
			if ( $fire && self::payment_in_progress( (int) $id ) ) {
				continue;
			}
			$meta = $b['meta'];
			$set  = array();
			if ( 'reminder' === $kind ) {
				$meta['reminded'] = array_values( array_unique( array_merge( $sent, $mark ) ) );
				if ( $fire ) {
					$set['reminder_sent_at'] = SCP_Helpers::mysql_now();
				}
			} else {
				$meta['last_call_at'] = SCP_Helpers::mysql_now();
			}
			$set['meta'] = wp_json_encode( $meta );
			$wpdb->update( $t, $set, array( 'id' => (int) $id ) );
			if ( $fire ) {
				$b['meta'] = $meta;
				do_action( 'reminder' === $kind ? 'scp_payment_reminder' : 'scp_payment_last_call', $b );
				++$n;
			}
		}
		return $n;
	}

	/** Momentul unei reamintiri: cu $minutes înainte de termen, dar nu noaptea (22–08): atunci la 08:00, dacă mai e timp, sau la 21:30 cu o seară înainte. */
	public static function nudge_due( array $b, int $minutes ): DateTimeImmutable {
		$pay_by = new DateTimeImmutable( (string) $b['pay_by'], SCP_Helpers::tz() );
		$due    = $pay_by->modify( '-' . max( 1, $minutes ) . ' minutes' );
		$h      = (int) $due->format( 'G' );
		if ( $h >= 22 ) {
			$due = $due->setTime( 21, 30 );
		} elseif ( $h < 8 ) {
			$morning = $due->setTime( 8, 0 );
			$due     = $morning <= $pay_by->modify( '-10 minutes' ) ? $morning : $due->modify( '-1 day' )->setTime( 21, 30 );
		}
		return $due;
	}

	public static function last_call_due( array $b ): DateTimeImmutable {
		return self::nudge_due( $b, (int) SCP_Settings::get( 'last_call_minutes', 30 ) );
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
		$today = SCP_Helpers::now()->format( 'Y-m-d' );

		// Vederile simple din lista de programări.
		switch ( $args['view'] ?? '' ) {
			case 'upcoming':
				$where[] = 'active_slot IS NOT NULL AND slot_date >= %s';
				$vals[]  = $today;
				break;
			case 'awaiting':
				$where[] = "status = 'pending'";
				break;
			case 'studio':
				$where[] = "status = 'confirmed' AND payment_status NOT IN (" . SCP_Helpers::paid_sql() . ')';
				break;
			case 'past':
				$where[] = "status <> 'cancelled' AND slot_date < %s";
				$vals[]  = $today;
				break;
			case 'cancelled':
				$where[] = "status = 'cancelled'";
				break;
		}

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

		$tomorrow   = SCP_Helpers::now()->modify( '+1 day' )->format( 'Y-m-d' );
		$today_list = self::query(
			array(
				'status'   => 'active',
				'from'     => $today,
				'to'       => $tomorrow,
				'per_page' => 50,
			)
		)['items'];

		$upcoming = self::query(
			array(
				'status'   => 'active',
				'from'     => SCP_Helpers::now()->modify( '+2 days' )->format( 'Y-m-d' ),
				'per_page' => 8,
			)
		)['items'];

		// Cine trebuie să plătească online, cel mai urgent termen primul.
		$awaiting = array_map(
			array( __CLASS__, 'hydrate' ),
			$wpdb->get_results( "SELECT * FROM {$t} WHERE status = 'pending' ORDER BY pay_by IS NULL, pay_by, slot_date LIMIT 10", ARRAY_A )
		);

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
			'awaiting'   => $awaiting,
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
