<?php
defined( 'ABSPATH' ) || exit;

/**
 * Legătura cu pagina de plată CartFlows / WooCommerce.
 *
 * Linkul de plată conține doar tokenul programării (?scp=…), niciodată date personale. Când clientul îl deschide:
 *  - tokenul se păstrează în sesiunea WooCommerce;
 *  - CartFlows nu mai configurează coșul; îl configurăm noi: exact variația pachetului din programare;
 *  - reducerea din programare se aplică automat ca cupon (reducerea de sezon sau voucherul), iar un preț
 *    stabilit manual în admin se aplică direct pe produs;
 *  - pachetul nu se mai poate schimba, iar câmpurile de facturare se precompletează.
 * La plata comenzii, programarea devine „Plătită online” și „Confirmată”.
 */
class SCP_WooCommerce {

	private static ?array $booking = null;
	private static bool $loaded    = false;

	public static function init(): void {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return;
		}
		add_action( 'wp', array( __CLASS__, 'capture_token' ), 0 );
		add_filter( 'cartflows_skip_configure_cart', array( __CLASS__, 'skip_cartflows_cart' ), 99, 2 );
		add_action( 'wp', array( __CLASS__, 'configure_cart' ), 5 );
		add_action( 'wp', array( __CLASS__, 'configure_voucher_cart' ), 5 );
		add_action( 'woocommerce_before_calculate_totals', array( __CLASS__, 'manual_price' ), 50 );
		add_filter( 'woocommerce_coupon_is_valid', array( __CLASS__, 'guard_early_coupon' ), 20, 2 );
		add_filter( 'woocommerce_checkout_get_value', array( __CLASS__, 'prefill' ), 20, 2 );
		add_action( 'woocommerce_checkout_process', array( __CLASS__, 'validate_checkout' ) );
		add_action( 'woocommerce_checkout_create_order', array( __CLASS__, 'attach_to_order' ), 20, 1 );
		add_action( 'woocommerce_before_checkout_form', array( __CLASS__, 'notice' ), 5 );
		add_action( 'cartflows_checkout_form_before', array( __CLASS__, 'notice' ), 5 ); // apare și când coșul e gol
		add_action( 'wp_head', array( __CLASS__, 'lock_css' ) );
		add_action( 'woocommerce_payment_complete', array( __CLASS__, 'on_paid' ), 20, 1 );
		add_action( 'woocommerce_order_status_processing', array( __CLASS__, 'on_paid' ), 20, 1 );
		add_action( 'woocommerce_order_status_completed', array( __CLASS__, 'on_paid' ), 20, 1 );
	}

	/** Programarea din sesiunea curentă, dacă se mai poate plăti. */
	public static function booking(): ?array {
		if ( self::$loaded ) {
			return self::$booking;
		}
		self::$loaded = true;
		if ( ! function_exists( 'WC' ) || ! WC()->session ) {
			return null;
		}
		$token = (string) WC()->session->get( 'scp_token' );
		$b     = $token ? SCP_Bookings::get_by_token( $token ) : null;
		self::$booking = $b && self::payable( $b ) ? $b : null;
		return self::$booking;
	}

	private static function payable( array $b ): bool {
		return 'cancelled' !== $b['status'] && ! in_array( $b['payment_status'], array( 'paid', 'cash', 'voucher' ), true );
	}

	public static function capture_token(): void {
		if ( empty( $_GET['scp'] ) || ! function_exists( 'WC' ) || ! WC()->session ) { // phpcs:ignore WordPress.Security.NonceVerification
			return;
		}
		$token = sanitize_key( wp_unslash( $_GET['scp'] ) ); // phpcs:ignore WordPress.Security.NonceVerification
		$b     = SCP_Bookings::get_by_token( $token );
		if ( ! $b ) {
			return;
		}
		if ( ! WC()->session->has_session() ) {
			WC()->session->set_customer_session_cookie( true );
		}
		WC()->session->set( 'scp_token', $token );
		WC()->session->set( 'scp_state', self::payable( $b ) ? 'ok' : ( in_array( $b['payment_status'], array( 'paid', 'cash', 'voucher' ), true ) ? 'paid' : 'cancelled' ) );
		self::$loaded = false;
	}

	/** Suntem pe pagina de plată din setări? (Ca restul magazinului, de ex. albumele, să nu fie afectat.) */
	public static function on_payment_page(): bool {
		return self::on_page( (string) SCP_Settings::get( 'payment_url' ) );
	}

	/** Suntem pe pagina de cumpărare a voucherelor? */
	public static function on_voucher_page(): bool {
		return self::on_page( (string) SCP_Settings::get( 'voucher_url' ) );
	}

	private static function on_page( string $url ): bool {
		$path = (string) wp_parse_url( SCP_Helpers::absolute_url( strtok( $url, '?' ) ?: '' ), PHP_URL_PATH );
		$req  = (string) wp_parse_url( (string) ( $_SERVER['REQUEST_URI'] ?? '' ), PHP_URL_PATH ); // phpcs:ignore
		return '' !== $path && '/' !== $path && trailingslashit( $req ) === trailingslashit( $path );
	}

	private static function cart_has_booking_item(): bool {
		if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
			return false;
		}
		foreach ( WC()->cart->get_cart() as $item ) {
			if ( ! empty( $item['scp_booking'] ) ) {
				return true;
			}
		}
		return false;
	}

	private static function item_product_id( ?array $item ): int {
		if ( ! $item ) {
			return 0;
		}
		return (int) ( ! empty( $item['variation_id'] ) ? $item['variation_id'] : $item['product_id'] );
	}

	public static function skip_cartflows_cart( $skip, $checkout_id ) {
		if ( self::on_voucher_page() && self::wanted_voucher() ) {
			return true;
		}
		if ( self::on_payment_page() ) {
			if ( ! self::booking() && function_exists( 'WC' ) && WC()->cart ) {
				WC()->cart->empty_cart();
			}
			return true;
		}
		return $skip;
	}

	/** Pachetul cerut în linkul de cumpărare a voucherului (?pachet=foto|foto_plus) și produsul lui. */
	private static function wanted_voucher(): int {
		$pkg = isset( $_GET['pachet'] ) ? sanitize_key( wp_unslash( $_GET['pachet'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		if ( '' === $pkg ) {
			return 0;
		}
		$map = (array) SCP_Settings::get( 'voucher_products', array() );
		return (int) ( $map[ $pkg ] ?? 0 );
	}

	/**
	 * Pagina de vouchere: coșul conține voucherul pachetului din link. Fără ?pachet, CartFlows pune produsul
	 * implicit al pasului, iar cumpărătorul poate schimba pachetul din opțiunile de pe pagină.
	 */
	public static function configure_voucher_cart(): void {
		if ( is_admin() || wp_doing_ajax() || ! function_exists( 'WC' ) || ! WC()->cart || ! self::on_voucher_page() ) {
			return;
		}
		$pid = self::wanted_voucher();
		$product = $pid ? wc_get_product( $pid ) : null;
		if ( ! $product || ! $product->is_purchasable() ) {
			return;
		}
		$items = WC()->cart->get_cart();
		$first = $items ? reset( $items ) : null;
		if ( 1 === count( $items ) && self::item_product_id( $first ) === $pid ) {
			return;
		}
		// Datele de care au nevoie opțiunile de produs din CartFlows ca să arate pachetul ca ales.
		$extra = array();
		$index = null;
		$step  = (int) get_queried_object_id();
		if ( $step && function_exists( 'wcf' ) ) {
			foreach ( (array) get_post_meta( $step, 'wcf-checkout-products', true ) as $i => $row ) {
				if ( isset( $row['product'] ) && (int) $row['product'] === $pid ) {
					$index                     = $i;
					$extra['wcf_product_data'] = array(
						'unique_id'       => $row['unique_id'] ?? '',
						'wcf_checkout_id' => $step,
						'wcf_flow_id'     => (int) get_post_meta( $step, 'wcf-flow-id', true ),
					);
				}
			}
		}
		if ( ! WC()->session->has_session() ) {
			WC()->session->set_customer_session_cookie( true );
		}
		WC()->cart->empty_cart();
		if ( $product->is_type( 'variation' ) ) {
			$key = WC()->cart->add_to_cart( $product->get_parent_id(), 1, $pid, $product->get_variation_attributes(), $extra );
		} else {
			$key = WC()->cart->add_to_cart( $pid, 1, 0, array(), $extra );
		}
		// Ca opțiunea pachetului să apară bifată în lista de produse a pasului.
		if ( $key && null !== $index && function_exists( 'wcf' ) && isset( wcf()->utils ) && method_exists( wcf()->utils, 'set_selcted_checkout_products' ) ) {
			wcf()->utils->set_selcted_checkout_products( $step, array( $index => array( 'cart_item_key' => $key ) ) );
		}
	}

	/** Coșul: doar variația din programare, plus cuponul reducerii. */
	public static function configure_cart(): void {
		$b = self::booking();
		if ( ! $b || is_admin() || wp_doing_ajax() || ! WC()->cart ) {
			return;
		}
		if ( ! self::on_payment_page() ) {
			return;
		}
		$variation_id = SCP_Pricing::variation_for( $b['package'] );
		if ( ! $variation_id ) {
			wc_add_notice( 'Pagina de plată nu e legată încă de pachetul ales. Te rugăm să ne suni pentru plată.', 'error' );
			return;
		}
		$variation = wc_get_product( $variation_id );
		if ( ! $variation ) {
			return;
		}

		// Dacă în coș e deja exact ce trebuie, nu-l refacem (păstrăm datele introduse de client).
		$items = WC()->cart->get_cart();
		$first = $items ? reset( $items ) : null;
		$ok    = 1 === count( $items ) && self::item_product_id( $first ) === $variation_id && (int) $first['quantity'] === 1 && ! empty( $first['scp_booking'] );
		if ( ! $ok ) {
			WC()->cart->empty_cart();
			if ( $variation->is_type( 'variation' ) ) {
				WC()->cart->add_to_cart( $variation->get_parent_id(), 1, $variation_id, $variation->get_variation_attributes(), array( 'scp_booking' => $b['id'] ) );
			} else {
				WC()->cart->add_to_cart( $variation_id, 1, 0, array(), array( 'scp_booking' => $b['id'] ) );
			}
		}

		$want = in_array( $b['discount_source'], array( 'early', 'voucher' ), true ) && $b['discount_code'] ? wc_format_coupon_code( $b['discount_code'] ) : '';
		foreach ( WC()->cart->get_applied_coupons() as $code ) {
			if ( $code !== $want ) {
				WC()->cart->remove_coupon( $code );
			}
		}
		if ( $want && ! WC()->cart->has_discount( $want ) ) {
			WC()->cart->apply_coupon( $want );
			wc_clear_notices();
		}
	}

	/** Prețul stabilit manual în admin se aplică direct pe produs. */
	public static function manual_price( $cart ): void {
		$b = self::booking();
		if ( ! $b || 'manual' !== $b['discount_source'] ) {
			return;
		}
		foreach ( $cart->get_cart() as $item ) {
			if ( ! empty( $item['scp_booking'] ) && isset( $item['data'] ) && self::item_product_id( $item ) === SCP_Pricing::variation_for( $b['package'] ) ) {
				$item['data']->set_price( (float) $b['price'] );
			}
		}
	}

	/** Cuponul reducerii de sezon e valabil doar când îl aplică pluginul, pentru o programare îndreptățită. */
	public static function guard_early_coupon( $valid, $coupon ) {
		$early = wc_format_coupon_code( (string) SCP_Settings::get( 'early_coupon' ) );
		if ( ! $early || $coupon->get_code() !== $early ) {
			return $valid;
		}
		$b = self::booking();
		if ( ! $b || 'early' !== $b['discount_source'] ) {
			throw new Exception( 'Reducerea aceasta se aplică automat doar rezervărilor care au dreptul la ea.', 100 ); // phpcs:ignore
		}
		return $valid;
	}

	public static function prefill( $value, $input ) {
		if ( null !== $value && '' !== $value ) {
			return $value;
		}
		$b = self::booking();
		if ( ! $b ) {
			return $value;
		}
		$parts = preg_split( '/\s+/', trim( $b['name'] ), 2 );
		switch ( $input ) {
			case 'billing_email':
				return $b['email'] ?: $value;
			case 'billing_phone':
				return $b['phone'] ?: $value;
			case 'billing_last_name':
				return $parts[0] ?? $value;
			case 'billing_first_name':
				return $parts[1] ?? $value;
		}
		return $value;
	}

	public static function validate_checkout(): void {
		if ( ! self::cart_has_booking_item() ) {
			return;
		}
		if ( ! self::booking() ) {
			wc_add_notice( 'Rezervarea nu mai poate fi plătită: a expirat, a fost anulată sau e deja plătită. Te rugăm să ne suni.', 'error' );
			return;
		}
		$b = self::booking();
		if ( ! $b ) {
			return;
		}
		$variation_id = SCP_Pricing::variation_for( $b['package'] );
		foreach ( WC()->cart->get_cart() as $item ) {
			if ( self::item_product_id( $item ) !== $variation_id ) {
				wc_add_notice( 'Coșul nu corespunde programării. Reîncarcă pagina de plată din linkul primit pe email.', 'error' );
				return;
			}
		}
		$total = (float) WC()->cart->get_total( 'edit' );
		if ( abs( $total - (float) $b['price'] ) > 0.01 ) {
			wc_add_notice( 'Suma de plată nu corespunde programării (' . SCP_Helpers::money( $b['price'] ) . ' lei). Reîncarcă pagina din linkul primit pe email.', 'error' );
		}
	}

	public static function attach_to_order( $order ): void {
		$b = self::booking();
		if ( $b && $order instanceof WC_Order && self::cart_has_booking_item() ) {
			$order->update_meta_data( '_scp_booking_id', $b['id'] );
			$order->update_meta_data( '_scp_booking_code', $b['code'] );
			$order->add_order_note( 'Plată pentru programarea ' . $b['code'] . ' (' . SCP_Helpers::date_ro( $b['slot_date'] ) . ', ' . $b['slot_time'] . ').' );
		}
	}

	public static function notice(): void {
		static $done = false;
		if ( $done ) {
			return;
		}
		if ( ! self::on_payment_page() || ! function_exists( 'WC' ) || ! WC()->session ) {
			return;
		}
		$done = true;
		$b    = self::booking();
		if ( ! $b ) {
			$state = WC()->session->get( 'scp_token' ) ? WC()->session->get( 'scp_state' ) : 'none';
			if ( 'paid' === $state ) {
				$msg = 'Programarea aceasta e deja plătită. Mulțumim!';
			} elseif ( 'none' === $state ) {
				$msg = 'Plata se face din linkul primit pe email după programare. Dacă nu ai făcut încă programarea, alege ziua și ora pe site, apoi revii aici din link.';
			} else {
				$msg = 'Rezervarea aceasta a expirat sau a fost anulată, așa că nu mai poate fi plătită. Poți alege din nou o oră pe site sau ne poți suna la ' . SCP_Settings::get( 'studio_phone' ) . '.';
			}
			$lp = (string) SCP_Settings::get( 'booking_page_url', '/sedinte-foto-de-craciun/' );
			echo '<div class="scp-checkout-note scp-checkout-note--bad">' . esc_html( $msg ) . ( 'paid' === $state ? '' : ' <a href="' . esc_url( SCP_Helpers::absolute_url( $lp ) . '#programare' ) . '">Programează ședința</a>' ) . '</div>';
			return;
		}
		$pk  = SCP_Settings::packages()[ $b['package'] ]['label'] ?? $b['package'];
		$txt = 'Plătești ședința foto din ' . SCP_Helpers::date_long( $b['slot_date'] ) . ', ora ' . $b['slot_time'] . ' (' . $pk . ', cod ' . $b['code'] . ').';
		if ( 'pending' === $b['status'] && $b['pay_by'] ) {
			$txt .= ' Rezervarea e păstrată până ' . SCP_Helpers::datetime_long( $b['pay_by'] ) . '.';
		}
		echo '<div class="scp-checkout-note">' . esc_html( $txt ) . '</div>';
	}

	/** Pe pagina de plată pentru o programare, alegerea pachetului și câmpul de cupon dispar. */
	public static function lock_css(): void {
		if ( ! self::on_payment_page() ) {
			return;
		}
		if ( ! self::booking() ) {
			// Fără o programare care se poate plăti, pagina arată doar mesajul: nu se poate plăti o ședință fără oră rezervată.
			echo '<style>.scp-checkout-note{margin:0 0 18px;padding:14px 18px;border-radius:8px;background:#f8e6e1;color:#7c2414;font-weight:600;line-height:1.5}.scp-checkout-note a{color:inherit;text-decoration:underline}
form.woocommerce-checkout,.wcf-embed-checkout-form form.checkout,.wcf-product-option-wrap,.woocommerce-form-coupon-toggle,.woocommerce-form-login-toggle,.wcf-order-wrap{display:none!important}</style>';
			return;
		}
		echo '<style>
.wcf-product-option-wrap,.wcf-product-option-wrap *,.wcf-qty-options,.wcf-embed-checkout-form .woocommerce-form-coupon-toggle,.woocommerce-form-coupon-toggle,.checkout_coupon,.wcf-custom-coupon-field,.woocommerce-remove-coupon{display:none!important}
.scp-checkout-note{margin:0 0 18px;padding:14px 18px;border-radius:8px;background:#e7eee3;color:#1f3a17;font-weight:600;line-height:1.5}
</style>';
	}

	public static function on_paid( $order_id ): void {
		$order = wc_get_order( $order_id );
		if ( ! $order || ! $order->is_paid() ) {
			return;
		}
		$id = (int) $order->get_meta( '_scp_booking_id' );
		if ( ! $id ) {
			return;
		}
		SCP_Bookings::mark_paid_online( $id, (int) $order->get_id() );
		if ( function_exists( 'WC' ) && WC()->session ) {
			WC()->session->__unset( 'scp_token' );
			WC()->session->__unset( 'scp_state' );
		}
	}
}
