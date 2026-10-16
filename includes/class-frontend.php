<?php
defined( 'ABSPATH' ) || exit;

/**
 * Formularul public: [sc_programare]
 */
class SCP_Frontend {

	public static function init(): void {
		add_shortcode( 'sc_programare', array( __CLASS__, 'shortcode' ) );
		add_shortcode( 'sc_pret', array( __CLASS__, 'price_shortcode' ) );
		add_shortcode( 'sc_oferta', array( __CLASS__, 'offer_shortcode' ) );
		add_action( 'wp_head', array( __CLASS__, 'price_table_css' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'register' ) );
	}

	public static function register(): void {
		wp_register_style( 'scp-front', SCP_URL . 'assets/css/front.css', array(), SCP_VERSION );
		wp_register_script( 'scp-front', SCP_URL . 'assets/js/front.js', array(), SCP_VERSION, array( 'in_footer' => true, 'strategy' => 'defer' ) );
	}

	/**
	 * Prețul unui pachet, ca text, pentru Elementor (tag dinamic „Shortcode” sau widget text).
	 * [sc_pret pachet="foto"]                      → prețul de azi („450 Lei”)
	 * [sc_pret pachet="foto" afisare="intreg"]     → prețul întreg doar dacă azi există reducere, altfel gol
	 * [sc_pret pachet="foto" afisare="eticheta"]   → „−100 lei” cât timp reducerea e activă, altfel gol
	 * Atributul sufix="" scoate „ Lei”.
	 */
	public static function price_shortcode( $atts = array() ): string {
		$atts = shortcode_atts( array( 'pachet' => 'foto', 'afisare' => 'pret', 'sufix' => ' Lei' ), $atts, 'sc_pret' );
		$q    = SCP_Pricing::quote( sanitize_key( $atts['pachet'] ) );
		switch ( $atts['afisare'] ) {
			case 'intreg':
				return $q['discount'] > 0 ? SCP_Helpers::money( $q['list'] ) : '';
			case 'eticheta':
				if ( $q['discount'] <= 0 ) {
					return '';
				}
				return '−' . SCP_Helpers::money( $q['discount'] ) . ' lei';
			default:
				return SCP_Helpers::money( $q['price'] ) . $atts['sufix'];
		}
	}

	/** Un paragraf despre reducerea de început de sezon, doar cât timp e activă. */
	public static function offer_shortcode( $atts = array() ): string {
		$pk = SCP_Settings::packages();
		$items = array();
		foreach ( (array) SCP_Settings::get( 'early_packages', array() ) as $key ) {
			$q = SCP_Pricing::quote( $key );
			if ( $q['discount'] > 0 && isset( $pk[ $key ] ) ) {
				$items[] = $pk[ $key ]['label'] . ' costă ' . SCP_Helpers::money( $q['price'] ) . ' lei în loc de ' . SCP_Helpers::money( $q['list'] );
			}
		}
		if ( ! $items ) {
			return '';
		}
		$until = (string) SCP_Settings::get( 'early_until' );
		$date  = (int) substr( $until, 8, 2 ) . ' ' . SCP_Helpers::MONTHS[ (int) substr( $until, 5, 2 ) ];
		$hours = (int) SCP_Settings::get( 'pay_hours' );
		return '<strong>Pentru rezervările făcute până pe ' . esc_html( $date ) . ', ' . esc_html( implode( ', ', $items ) ) . '</strong>' . ( $hours ? esc_html( ', cu plata online în ' . $hours . ' de ore.' ) : '.' );
	}

	/** Ascunde panglica tabelului de prețuri Elementor când e goală (după încheierea reducerii). */
	public static function price_table_css(): void {
		echo '<style id="scp-price-table">.elementor-price-table__ribbon:has(.elementor-price-table__ribbon-inner:empty){display:none}</style>' . "\n";
	}

	public static function shortcode( $atts = array() ): string {
		if ( ! wp_style_is( 'scp-front', 'registered' ) ) {
			self::register();
		}
		wp_enqueue_style( 'scp-front' );
		wp_enqueue_script( 'scp-front' );

		$atts = shortcode_atts( array( 'titlu' => '' ), $atts, 'sc_programare' );

		$config = array(
			'api'      => esc_url_raw( rest_url( SCP_Rest::NS ) ),
			'fbq'      => (bool) SCP_Settings::get( 'track_fbq' ),
			'currency' => 'RON',
		);

		ob_start();
		?>
		<div class="scp" data-scp='<?php echo esc_attr( wp_json_encode( $config ) ); ?>'>
			<?php if ( $atts['titlu'] ) : ?>
				<h2 class="scp-title"><?php echo esc_html( $atts['titlu'] ); ?></h2>
			<?php endif; ?>
			<div class="scp-card" aria-live="polite">
				<div class="scp-loading" role="status">
					<span class="scp-spinner" aria-hidden="true"></span>
					<span>Se încarcă calendarul…</span>
				</div>
				<noscript><p class="scp-note">Pentru programare online e nevoie de JavaScript. Ne poți suna la <?php echo esc_html( (string) SCP_Settings::get( 'studio_phone' ) ); ?>.</p></noscript>
			</div>
		</div>
		<?php
		return (string) ob_get_clean();
	}
}
