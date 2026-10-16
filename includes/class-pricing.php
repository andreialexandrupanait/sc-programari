<?php
defined( 'ABSPATH' ) || exit;

/**
 * Prețul unei programări: prețul pachetului, minus cea mai mare reducere aplicabilă.
 *
 * Reducerea de început de sezon: pentru pachetele alese, la rezervările făcute până la data din setări.
 * Voucherele cadou nu sunt reduceri: plătesc integral ședința (vezi SCP_Vouchers).
 *
 * Prețul calculat se salvează în programare, ca să rămână neschimbat chiar dacă regulile se modifică ulterior.
 */
class SCP_Pricing {

	/** ID-ul variației WooCommerce legate de un pachet (0 dacă nu e setat). */
	public static function variation_for( string $package ): int {
		$map = (array) SCP_Settings::get( 'wc_variations', array() );
		return (int) ( $map[ $package ] ?? 0 );
	}

	public static function early_applies( string $package, ?DateTimeImmutable $booked_at = null ): bool {
		if ( empty( SCP_Settings::get( 'early_enabled' ) ) ) {
			return false;
		}
		if ( ! in_array( $package, (array) SCP_Settings::get( 'early_packages', array() ), true ) ) {
			return false;
		}
		$until = (string) SCP_Settings::get( 'early_until' );
		if ( ! SCP_Helpers::is_date( $until ) ) {
			return false;
		}
		$booked_at ??= SCP_Helpers::now();
		return $booked_at->format( 'Y-m-d' ) <= $until;
	}

	/**
	 * Prețul de azi al unui pachet.
	 *
	 * @return array{list:float,discount:float,price:float,code:string,source:string,label:string}
	 */
	public static function quote( string $package, ?DateTimeImmutable $booked_at = null ): array {
		$packages = SCP_Settings::packages();
		$list     = (float) ( $packages[ $package ]['price'] ?? 0 );
		$out      = array(
			'list'     => $list,
			'discount' => 0.0,
			'price'    => $list,
			'code'     => '',
			'source'   => '',
			'label'    => '',
		);
		if ( self::early_applies( $package, $booked_at ) ) {
			$d = min( $list, max( 0, (float) SCP_Settings::get( 'early_amount' ) ) );
			if ( $d > 0 ) {
				$out['discount'] = $d;
				$out['price']    = $list - $d;
				$out['code']     = (string) SCP_Settings::get( 'early_coupon' );
				$out['source']   = 'early';
				$out['label']    = (string) SCP_Settings::get( 'early_label' );
			}
		}
		return $out;
	}

	/**
	 * Termenul de plată pentru o programare nouă.
	 * Dacă ședința are loc înainte de expirarea termenului, nu punem termen: programarea nu se anulează
	 * automat, iar plata se poate face online până atunci sau la studio.
	 */
	public static function pay_by( string $date, string $time, ?DateTimeImmutable $from = null ): ?string {
		$hours = (int) SCP_Settings::get( 'pay_hours', 0 );
		if ( $hours <= 0 ) {
			return null;
		}
		$from ??= SCP_Helpers::now();
		$by     = $from->modify( "+{$hours} hours" );
		if ( SCP_Helpers::dt( $date, $time ) <= $by ) {
			return null;
		}
		return $by->format( 'Y-m-d H:i:s' );
	}

	/** Creează sau actualizează cuponul WooCommerce folosit pentru reducerea de început de sezon. */
	public static function sync_early_coupon(): ?string {
		if ( ! class_exists( 'WC_Coupon' ) ) {
			return 'WooCommerce nu e activ.';
		}
		$code = wc_format_coupon_code( (string) SCP_Settings::get( 'early_coupon' ) );
		if ( '' === $code ) {
			return null;
		}
		$id     = wc_get_coupon_id_by_code( $code );
		$coupon = new WC_Coupon( $id ?: 0 );
		$coupon->set_code( $code );
		$coupon->set_discount_type( 'fixed_product' );
		$coupon->set_amount( (float) SCP_Settings::get( 'early_amount' ) );
		$coupon->set_individual_use( true );
		$coupon->set_date_expires( null );
		$coupon->set_usage_limit( 0 );
		$coupon->set_description( 'Gestionat de pluginul SC Programări. Se aplică automat doar programărilor care au dreptul la reducere; introdus manual nu e valabil.' );
		$ids = array();
		foreach ( (array) SCP_Settings::get( 'early_packages', array() ) as $pkg ) {
			if ( $v = self::variation_for( $pkg ) ) {
				$ids[] = $v;
			}
		}
		$coupon->set_product_ids( $ids );
		$coupon->save();
		return null;
	}
}
