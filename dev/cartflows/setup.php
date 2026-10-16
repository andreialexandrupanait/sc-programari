<?php
/**
 * Pregătește în CartFlows: checkoutul 2026 pentru plata ședințelor și checkoutul pentru vouchere.
 * Clonează fluxul din 2025 (design, câmpuri de facturare, setări) și schimbă doar ce ține de 2026.
 * Idempotent: dacă un pas cu slugul respectiv există deja, nu-l mai creează.
 */
function scp_cf2026_setup( int $source_flow = 10939 ): array {
	global $wpdb;
	$log = array();

	$clone = static function ( int $flow_id, string $flow_title, array $steps_cfg ) use ( $wpdb, &$log ) {
		$post = get_post( $flow_id );
		if ( ! $post ) {
			$log[] = "flux sursa $flow_id lipsă";
			return 0;
		}
		$new_flow = wp_insert_post(
			array(
				'post_type'   => $post->post_type,
				'post_status' => 'publish',
				'post_title'  => $flow_title,
				'post_name'   => sanitize_title( $flow_title ),
				'post_author' => get_current_user_id() ?: $post->post_author,
			)
		);
		foreach ( $wpdb->get_results( $wpdb->prepare( "SELECT meta_key, meta_value FROM $wpdb->postmeta WHERE post_id=%d", $flow_id ) ) as $m ) {
			if ( in_array( $m->meta_key, array( '_wp_old_slug', 'wcf-steps', '_edit_lock', '_edit_last' ), true ) ) {
				continue;
			}
			$wpdb->insert( $wpdb->postmeta, array( 'post_id' => $new_flow, 'meta_key' => $m->meta_key, 'meta_value' => $m->meta_value ) );
		}
		$new_steps = array();
		foreach ( (array) get_post_meta( $flow_id, 'wcf-steps', true ) as $st ) {
			$src  = get_post( (int) $st['id'] );
			$type = get_post_meta( $src->ID, 'wcf-step-type', true );
			$cfg  = $steps_cfg[ $type ] ?? null;
			if ( ! $src || ! $cfg ) {
				continue;
			}
			$new = wp_insert_post(
				array(
					'post_type'    => $src->post_type,
					'post_status'  => 'publish',
					'post_title'   => $cfg['title'],
					'post_name'    => $cfg['slug'],
					'post_content' => '',
					'post_author'  => get_current_user_id() ?: $src->post_author,
				)
			);
			foreach ( $wpdb->get_results( $wpdb->prepare( "SELECT meta_key, meta_value FROM $wpdb->postmeta WHERE post_id=%d", $src->ID ) ) as $m ) {
				if ( in_array( $m->meta_key, array( '_wp_old_slug', '_edit_lock', '_edit_last', '_elementor_css', '_elementor_element_cache', '_elementor_page_assets', 'rank_math_seo_score' ), true ) ) {
					continue;
				}
				$wpdb->insert( $wpdb->postmeta, array( 'post_id' => $new, 'meta_key' => $m->meta_key, 'meta_value' => $m->meta_value ) );
			}
			update_post_meta( $new, 'wcf-flow-id', $new_flow );
			update_post_meta( $new, 'wcf-step-type', $type );
			wp_set_object_terms( $new, $type, 'cartflows_step_type' );
			wp_set_object_terms( $new, 'flow-' . $new_flow, 'cartflows_step_flow' );

			// Texte în Elementor.
			$data = get_post_meta( $new, '_elementor_data', true );
			if ( is_string( $data ) && $data ) {
				$tree = json_decode( $data, true );
				$walk = static function ( &$els ) use ( &$walk, $cfg ) {
					foreach ( $els as &$e ) {
						if ( 'call-to-action' === ( $e['widgetType'] ?? '' ) ) {
							$e['settings']['title']       = $cfg['heading'];
							$e['settings']['description'] = $cfg['text'];
						}
						foreach ( array( 'title', 'editor', 'text' ) as $k ) {
							if ( isset( $e['settings'][ $k ] ) && is_string( $e['settings'][ $k ] ) ) {
								$e['settings'][ $k ] = str_replace( '© 2025', '© 2026', $e['settings'][ $k ] );
							}
						}
						if ( ! empty( $e['elements'] ) ) {
							$walk( $e['elements'] );
						}
					}
				};
				$walk( $tree );
				update_post_meta( $new, '_elementor_data', wp_slash( wp_json_encode( $tree ) ) );
			}

			// Produsele pasului de checkout.
			if ( 'checkout' === $type && ! empty( $cfg['products'] ) ) {
				$products = array();
				$options  = array();
				foreach ( $cfg['products'] as $pid => $label ) {
					$uid        = strtolower( wp_generate_password( 8, false, false ) );
					$products[] = array( 'quantity' => '1', 'discount_type' => '', 'discount_value' => '', 'product' => (string) $pid, 'unique_id' => $uid, 'add_to_cart' => 'yes' );
					$options[ $uid ] = array( 'product_name' => $label, 'product_subtext' => '', 'enable_highlight' => 'no', 'highlight_text' => '', 'unique_id' => $uid );
				}
				update_post_meta( $new, 'wcf-checkout-products', $products );
				update_post_meta( $new, 'wcf-product-options-data', $options );
				update_post_meta( $new, 'wcf-checkout-place-order-button-text', $cfg['button'] );
			}
			$new_steps[] = array( 'id' => (int) $new, 'title' => $cfg['title'], 'type' => $type );
			$log[]       = "pas $type: $new /" . get_post_field( 'post_name', $new ) . '/';
		}
		update_post_meta( $new_flow, 'wcf-steps', $new_steps );
		$log[] = "flux: $new_flow „{$flow_title}”";
		return $new_flow;
	};

	$exists = static fn( string $slug ) => (int) $wpdb->get_var( $wpdb->prepare( "SELECT ID FROM $wpdb->posts WHERE post_type='cartflows_step' AND post_status='publish' AND post_name=%s", $slug ) );

	$map = (array) get_option( 'scp_settings', array() );
	$wcv = (array) ( $map['wc_variations'] ?? array() );
	$vpv = (array) ( $map['voucher_products'] ?? array() );

	if ( ! $exists( 'plata-craciun-2026' ) ) {
		$clone(
			$source_flow,
			'[2026] Crăciun 2026 – plata ședințelor',
			array(
				'checkout' => array(
					'title'    => 'Plata ședinței foto de Crăciun 2026',
					'slug'     => 'plata-craciun-2026',
					'heading'  => 'Plata ședinței foto de Crăciun',
					'text'     => 'Completează datele de facturare și plătește cu cardul. Confirmarea programării îți vine pe email imediat după plată.',
					'button'   => 'Plătește ședința',
					'products' => array_filter(
						array(
							(int) ( $wcv['foto'] ?? 8121 )      => 'Pachet Foto',
							(int) ( $wcv['foto_plus'] ?? 8122 ) => 'Pachet Foto Plus, cu Moș Crăciun',
						)
					),
				),
				'thankyou' => array(
					'title'   => 'Mulțumim – plata ședinței de Crăciun 2026',
					'slug'    => 'multumim-craciun-2026',
					'heading' => 'Mulțumim! Ședința ta e confirmată',
					'text'    => 'Am primit plata. Pe email ai confirmarea, cu ziua, ora și adresa studioului. Ne vedem curând!',
				),
			)
		);
	} else {
		$log[] = 'plata-craciun-2026 există deja';
	}

	if ( ! $exists( 'voucher-craciun-2026' ) ) {
		$clone(
			$source_flow,
			'[2026] Crăciun 2026 – vouchere cadou',
			array(
				'checkout' => array(
					'title'    => 'Voucher cadou – ședință foto de Crăciun 2026',
					'slug'     => 'voucher-craciun-2026',
					'heading'  => 'Voucher cadou pentru o ședință foto de Crăciun',
					'text'     => 'Alege pachetul și plătește cu cardul. Codul voucherului îți vine pe email imediat după plată. Cine îl primește își face singur programarea pe site, cu acest cod, până pe 24 decembrie 2026.',
					'button'   => 'Cumpără voucherul',
					'products' => array_filter(
						array(
							(int) ( $vpv['foto'] ?? 0 )      => 'Voucher Pachet Foto',
							(int) ( $vpv['foto_plus'] ?? 0 ) => 'Voucher Pachet Foto Plus, cu Moș Crăciun',
						)
					),
				),
				'thankyou' => array(
					'title'   => 'Mulțumim – voucher Crăciun 2026',
					'slug'    => 'multumim-voucher-craciun-2026',
					'heading' => 'Mulțumim! Voucherul e pe drum',
					'text'    => 'Codul voucherului ți-a fost trimis pe email. Persoana care îl primește intră pe stefanchelmu.ro/sedinte-foto-de-craciun/, alege ziua și ora și scrie codul în formular. Programarea e confirmată imediat, fără altă plată.',
				),
			)
		);
	} else {
		$log[] = 'voucher-craciun-2026 există deja';
	}

	if ( class_exists( 'SCP_Settings' ) ) {
		SCP_Settings::update(
			array(
				'payment_url' => '/plata-craciun-2026/?scp={token}',
				'voucher_url' => '/voucher-craciun-2026/',
			)
		);
		$log[] = 'setări plugin: payment_url și voucher_url actualizate';
	}
	if ( class_exists( '\CartflowsAdmin\AdminCore\Inc\AdminHelper' ) ) {
		\CartflowsAdmin\AdminCore\Inc\AdminHelper::clear_cache();
	}
	if ( class_exists( '\Elementor\Plugin' ) ) {
		\Elementor\Plugin::$instance->files_manager->clear_cache();
	}
	flush_rewrite_rules( false );
	return $log;
}
