<?php
defined( 'ABSPATH' ) || exit;

/**
 * Vouchere cadou: cineva cumpără online un voucher pentru un pachet, primește un cod pe email și îl oferă altcuiva.
 * Cine primește codul îl introduce în formular și își alege ziua și ora; programarea e confirmată și plătită direct.
 *
 * Reguli: voucherul e valabil doar pentru pachetul cumpărat, doar în sezonul pentru care a fost emis, o singură dată.
 * Dacă o programare plătită cu voucher se anulează, voucherul redevine valabil.
 */
class SCP_Vouchers {

	const STATUSES = array(
		'active'    => 'Valabil',
		'used'      => 'Folosit',
		'cancelled' => 'Anulat',
	);

	public static function init(): void {
		if ( class_exists( 'WooCommerce' ) ) {
			add_action( 'woocommerce_order_status_processing', array( __CLASS__, 'issue_for_order' ), 30 );
			add_action( 'woocommerce_order_status_completed', array( __CLASS__, 'issue_for_order' ), 30 );
			add_action( 'woocommerce_payment_complete', array( __CLASS__, 'issue_for_order' ), 30 );
			add_action( 'woocommerce_order_status_refunded', array( __CLASS__, 'cancel_for_order' ), 30 );
			add_action( 'woocommerce_order_status_cancelled', array( __CLASS__, 'cancel_for_order' ), 30 );
		}
		add_action( 'scp_booking_cancelled', array( __CLASS__, 'release_for_booking' ), 5 );
	}

	private static function table(): string {
		return SCP_Install::table( 'vouchers' );
	}

	public static function normalize( string $code ): string {
		return strtoupper( preg_replace( '/[^A-Za-z0-9]/', '', $code ) );
	}

	/**
	 * „CHELMU-BRAD-K7MP9Q”: numele studioului, un cuvânt de Crăciun și 6 caractere aleatorii, fără caractere
	 * ușor de confundat (0/O, 1/I/L). Partea aleatorie are 31^6 ≈ 887 milioane de combinații pe fiecare cuvânt,
	 * iar verificarea codurilor din formular e limitată la 20 de încercări pe oră.
	 */
	/** Câmpurile opționale din checkout-ul voucherului: pentru cine e cadoul. */
	public const GIFT_FIELDS = array(
		'recipient_name'  => 'Cadou pentru: nume',
		'recipient_email' => 'Cadou pentru: email',
		'recipient_phone' => 'Cadou pentru: telefon',
	);

	public const CODE_WORDS = array( 'BRAD', 'STEA', 'FULG', 'SANIE', 'RENI', 'CADOU', 'COLIND', 'GLOB', 'NEA', 'ELF' );

	private static function new_code(): string {
		$prefix = strtoupper( preg_replace( '/[^A-Za-z0-9]/', '', (string) SCP_Settings::get( 'voucher_prefix', 'CHELMU' ) ) ) ?: 'CHELMU';
		$word   = self::CODE_WORDS[ random_int( 0, count( self::CODE_WORDS ) - 1 ) ];
		return substr( $prefix, 0, 10 ) . '-' . $word . '-' . SCP_Helpers::random_code( 6 );
	}

	public static function get_by_code( string $code ): ?array {
		global $wpdb;
		$norm = self::normalize( $code );
		if ( strlen( $norm ) < 6 ) {
			return null;
		}
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE code_norm = %s', $norm ), ARRAY_A );
		return $row ?: null;
	}

	public static function get( int $id ): ?array {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE id = %d', $id ), ARRAY_A );
		return $row ?: null;
	}

	/**
	 * Verifică un cod pentru un pachet (sau, fără pachet, spune pentru ce pachet e).
	 *
	 * „message” e generic (nu spune dacă un cod există), ca să nu ajute la ghicit; „detail” e pentru admin.
	 *
	 * @return array{ok:bool,message:string,detail:string,voucher:?array}
	 */
	public static function check( string $code, string $package = '', int $allow_booking = 0 ): array {
		$generic = 'Codul nu e valid sau nu mai poate fi folosit. Verifică-l sau sună-ne.';
		$fail    = static fn( string $m, ?array $v = null ) => array( 'ok' => false, 'message' => $v && 'package' === ( $v['_why'] ?? '' ) ? $m : $generic, 'detail' => $m, 'voucher' => $v );
		$v    = self::get_by_code( $code );
		if ( ! $v ) {
			return $fail( 'Nu găsim acest voucher. Verifică dacă l-ai scris corect.' );
		}
		if ( 'cancelled' === $v['status'] ) {
			return $fail( 'Voucherul a fost anulat.', $v );
		}
		if ( 'used' === $v['status'] && (int) $v['booking_id'] !== $allow_booking ) {
			return $fail( 'Voucherul a fost deja folosit pentru o programare.', $v );
		}
		if ( $v['expires_on'] && SCP_Helpers::now()->format( 'Y-m-d' ) > $v['expires_on'] ) {
			return $fail( 'Voucherul a expirat.', $v );
		}
		if ( '' !== $package && $package !== $v['package'] ) {
			$label = SCP_Settings::packages()[ $v['package'] ]['label'] ?? $v['package'];
			return $fail( 'Voucherul este pentru ' . $label . ' și se poate folosi doar pentru acest pachet.', $v + array( '_why' => 'package' ) );
		}
		return array( 'ok' => true, 'message' => '', 'detail' => '', 'voucher' => $v );
	}

	/** Marchează voucherul ca folosit, atomic: două programări nu pot folosi același cod. */
	public static function reserve( int $voucher_id, int $booking_id = 0 ): bool {
		global $wpdb;
		$n = $wpdb->query(
			$wpdb->prepare(
				'UPDATE ' . self::table() . " SET status = 'used', booking_id = %d, used_at = %s WHERE id = %d AND status = 'active'",
				$booking_id,
				SCP_Helpers::mysql_now(),
				$voucher_id
			)
		);
		return 1 === (int) $n;
	}

	public static function attach_booking( int $voucher_id, int $booking_id ): void {
		global $wpdb;
		$wpdb->update( self::table(), array( 'booking_id' => $booking_id ), array( 'id' => $voucher_id ) );
	}

	public static function release( int $voucher_id ): void {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( 'UPDATE ' . self::table() . " SET status = 'active', booking_id = NULL, used_at = NULL WHERE id = %d AND status = 'used'", $voucher_id ) );
	}

	public static function release_for_booking( array $b ): void {
		global $wpdb;
		$id = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . self::table() . " WHERE booking_id = %d AND status = 'used'", $b['id'] ) );
		if ( $id ) {
			self::release( $id );
			do_action( 'scp_voucher_released', self::get( $id ), $b );
		}
	}

	/** Creează un voucher (din comandă sau manual din admin). */
	public static function create( array $data ): ?array {
		global $wpdb;
		$packages = SCP_Settings::packages();
		$pkg      = sanitize_key( $data['package'] ?? '' );
		if ( ! isset( $packages[ $pkg ] ) ) {
			return null;
		}
		for ( $i = 0; $i < 5; $i++ ) {
			$code = self::new_code();
			$wpdb->suppress_errors( true );
			$ok = $wpdb->insert(
				self::table(),
				array(
					'code'          => $code,
					'code_norm'     => self::normalize( $code ),
					'package'       => $pkg,
					'value'         => isset( $data['value'] ) ? (float) $data['value'] : $packages[ $pkg ]['price'],
					'status'        => 'active',
					'order_id'      => $data['order_id'] ?? null,
					'order_item_id' => $data['order_item_id'] ?? null,
					'buyer_name'    => sanitize_text_field( $data['buyer_name'] ?? '' ),
					'buyer_email'   => sanitize_email( $data['buyer_email'] ?? '' ),
					'buyer_phone'   => sanitize_text_field( $data['buyer_phone'] ?? '' ),
					'recipient_name'  => sanitize_text_field( $data['recipient_name'] ?? '' ),
					'recipient_email' => sanitize_email( $data['recipient_email'] ?? '' ),
					'recipient_phone' => sanitize_text_field( $data['recipient_phone'] ?? '' ),
					'note'          => sanitize_text_field( $data['note'] ?? '' ),
					'expires_on'    => SCP_Helpers::is_date( $data['expires_on'] ?? '' ) ? $data['expires_on'] : (string) SCP_Settings::get( 'end_date' ),
					'created_at'    => SCP_Helpers::mysql_now(),
				)
			);
			$wpdb->suppress_errors( false );
			if ( $ok ) {
				$v = self::get( (int) $wpdb->insert_id );
				do_action( 'scp_voucher_issued', $v );
				return $v;
			}
		}
		return null;
	}

	/** Pachetul corespunzător unui produs voucher din comandă. */
	private static function package_for_product( int $product_id, int $variation_id ): string {
		foreach ( (array) SCP_Settings::get( 'voucher_products', array() ) as $pkg => $pid ) {
			$pid = (int) $pid;
			if ( $pid && ( $pid === $variation_id || ( ! $variation_id && $pid === $product_id ) ) ) {
				return (string) $pkg;
			}
		}
		return '';
	}

	public static function issue_for_order( $order_id ): void {
		try {
			self::issue_for_order_inner( $order_id );
		} catch ( \Throwable $e ) {
			// O eroare aici nu are voie să strice confirmarea plății (IPN Netopia).
			if ( function_exists( 'wc_get_logger' ) ) {
				wc_get_logger()->error( 'Vouchere comanda #' . $order_id . ': ' . $e->getMessage(), array( 'source' => 'sc-programari' ) );
			}
		}
	}

	private static function issue_for_order_inner( $order_id ): void {
		$order = wc_get_order( $order_id );
		if ( ! $order || ! $order->is_paid() || $order->get_meta( '_scp_vouchers_issued' ) ) {
			return;
		}
		// add_option e atomic (cheie unică): doar primul proces trece.
		$lock = 'scp_vlock_' . (int) $order->get_id();
		if ( ! add_option( $lock, time(), '', false ) ) {
			if ( (int) get_option( $lock ) > time() - 300 ) {
				return;
			}
			update_option( $lock, time(), false ); // lock rămas de la un proces căzut
		}
		$issued = array();
		$gift   = array();
		foreach ( self::GIFT_FIELDS as $key => $label ) {
			$gift[ $key ] = (string) $order->get_meta( '_scp_' . $key );
		}
		foreach ( $order->get_items() as $item_id => $item ) {
			$pkg = self::package_for_product( (int) $item->get_product_id(), (int) $item->get_variation_id() );
			if ( ! $pkg ) {
				continue;
			}
			$qty   = max( 1, (int) $item->get_quantity() );
			$codes = array();
			for ( $i = 0; $i < $qty; $i++ ) {
				$v = self::create(
					array(
						'package'       => $pkg,
						'value'         => (float) $item->get_total() / $qty,
						'order_id'      => $order->get_id(),
						'order_item_id' => $item_id,
						'buyer_name'    => trim( $order->get_billing_last_name() . ' ' . $order->get_billing_first_name() ),
						'buyer_email'   => $order->get_billing_email(),
						'buyer_phone'   => $order->get_billing_phone(),
					) + $gift
				);
				if ( $v ) {
					$codes[]  = $v['code'];
					$issued[] = $v;
				}
			}
			if ( $codes ) {
				wc_update_order_item_meta( $item_id, 'Cod voucher', implode( ', ', $codes ) );
			}
		}
		if ( ! $issued ) {
			delete_option( $lock );
			return;
		}
		$order->update_meta_data( '_scp_vouchers_issued', 1 );
		delete_option( $lock );
		$order->add_order_note( 'Vouchere emise: ' . implode( ', ', array_column( $issued, 'code' ) ) );
		$order->save();
		SCP_Mailer::send_vouchers( $issued, $order->get_billing_email(), trim( $order->get_billing_first_name() ) );
	}

	public static function cancel_for_order( $order_id ): void {
		global $wpdb;
		$used = $wpdb->get_results( $wpdb->prepare( 'SELECT code, booking_id FROM ' . self::table() . " WHERE order_id = %d AND status = 'used'", (int) $order_id ), ARRAY_A );
		if ( $used ) {
			$lines = array_map( static fn( $r ) => $r['code'] . ' (programarea #' . $r['booking_id'] . ')', $used );
			SCP_Rest::alert_once( 'scp_alert_vref_' . (int) $order_id, 'Voucher rambursat, dar deja folosit', 'Comanda #' . (int) $order_id . ' a fost rambursată sau anulată, dar aceste vouchere au fost deja folosite: ' . implode( ', ', $lines ) . '. Programările au rămas confirmate; decide tu dacă le anulezi.' );
		}
		$wpdb->query( $wpdb->prepare( 'UPDATE ' . self::table() . " SET status = 'cancelled' WHERE order_id = %d AND status = 'active'", (int) $order_id ) );
	}

	public static function set_status( int $id, string $status ): bool {
		global $wpdb;
		if ( ! in_array( $status, array( 'active', 'cancelled' ), true ) ) {
			return false;
		}
		$v = self::get( $id );
		if ( ! $v || 'used' === $v['status'] ) {
			return false;
		}
		return false !== $wpdb->update( self::table(), array( 'status' => $status ), array( 'id' => $id ) );
	}

	public static function query( array $args = array() ): array {
		global $wpdb;
		$where = array( '1=1' );
		$vals  = array();
		if ( ! empty( $args['status'] ) ) {
			$where[] = 'status = %s';
			$vals[]  = $args['status'];
		}
		if ( ! empty( $args['search'] ) ) {
			$like    = '%' . $wpdb->esc_like( $args['search'] ) . '%';
			$where[] = '(code LIKE %s OR buyer_name LIKE %s OR buyer_email LIKE %s OR buyer_phone LIKE %s OR recipient_name LIKE %s OR recipient_email LIKE %s OR recipient_phone LIKE %s)';
			array_push( $vals, $like, $like, $like, $like, $like, $like, $like );
		}
		$sql = 'SELECT * FROM ' . self::table() . ' WHERE ' . implode( ' AND ', $where ) . ' ORDER BY id DESC LIMIT 500';
		return $vals ? $wpdb->get_results( $wpdb->prepare( $sql, $vals ), ARRAY_A ) : $wpdb->get_results( $sql, ARRAY_A );
	}

	public static function counts(): array {
		global $wpdb;
		$out = array( 'active' => 0, 'used' => 0, 'cancelled' => 0 );
		foreach ( $wpdb->get_results( 'SELECT status, COUNT(*) n FROM ' . self::table() . ' GROUP BY status', ARRAY_A ) as $r ) {
			$out[ $r['status'] ] = (int) $r['n'];
		}
		return $out;
	}

	public static function export( array $v ): array {
		$packages = SCP_Settings::packages();
		return array(
			'id'            => (int) $v['id'],
			'code'          => $v['code'],
			'package'       => $v['package'],
			'package_label' => $packages[ $v['package'] ]['label'] ?? $v['package'],
			'value'         => (float) $v['value'],
			'status'        => $v['status'],
			'status_label'  => self::STATUSES[ $v['status'] ] ?? $v['status'],
			'order_id'      => $v['order_id'] ? (int) $v['order_id'] : null,
			'buyer_name'    => $v['buyer_name'],
			'buyer_email'   => $v['buyer_email'],
			'buyer_phone'   => $v['buyer_phone'] ?? '',
			'recipient_name'  => $v['recipient_name'] ?? '',
			'recipient_email' => $v['recipient_email'] ?? '',
			'recipient_phone' => $v['recipient_phone'] ?? '',
			'booking_id'    => $v['booking_id'] ? (int) $v['booking_id'] : null,
			'expires_on'    => $v['expires_on'],
			'created_at'    => ( new DateTimeImmutable( $v['created_at'], SCP_Helpers::tz() ) )->format( DATE_ATOM ),
		);
	}
}
