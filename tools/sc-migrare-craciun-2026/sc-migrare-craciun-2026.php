<?php
/**
 * Plugin Name: SC Migrare Crăciun 2026
 * Description: Mută pe site configurarea pregătită pe Local pentru Crăciun 2026: pozele, produsul voucher, setările pluginului de programări, checkouturile CartFlows 2026, landing page-ul și setările SMS. Se folosește o singură dată, apoi se dezactivează și se șterge.
 * Version: 1.0.1
 * Author: SimpleAD
 * Requires PHP: 8.1
 */

defined( 'ABSPATH' ) || exit;

final class SCM_Migrare {

	const SLUG    = 'scm-migrare-2026';
	const STATE   = 'scm_migrare_2026_state';
	const BACKUP  = 'scm_migrare_2026_backup';
	const MIN_SCP = '1.2.1';

	const STEPS = array(
		'media'    => 'Pozele (decoruri 2026, galerie, fundaluri, imaginea de distribuire)',
		'voucher'  => 'Produsul „Voucher ședință foto de Crăciun 2026”',
		'settings' => 'Setările pluginului de programări',
		'cartflows'=> 'Checkouturile CartFlows 2026 (plata ședințelor și vouchere)',
		'page'     => 'Landing page-ul /sedinte-foto-de-craciun/',
		'sms'      => 'Setările SMS (trimiterea rămâne oprită)',
		'cache'    => 'Golirea cache-ului (Elementor, CSS, cache de pagină)',
	);

	public static function init(): void {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_post_scm_run', array( __CLASS__, 'handle_run' ) );
		add_action( 'admin_post_scm_restore', array( __CLASS__, 'handle_restore' ) );
	}

	public static function menu(): void {
		add_management_page( 'Migrare Crăciun 2026', 'Migrare Crăciun 2026', 'manage_options', self::SLUG, array( __CLASS__, 'page' ) );
	}

	private static function data(): array {
		static $d = null;
		if ( null === $d ) {
			$d = json_decode( (string) file_get_contents( __DIR__ . '/data/export.json' ), true ) ?: array();
		}
		return $d;
	}

	private static function state(): array {
		return (array) get_option( self::STATE, array() );
	}

	private static function mark( string $step, bool $ok, array $log ): void {
		$s          = self::state();
		$s[ $step ] = array( 'ok' => $ok, 'at' => current_time( 'mysql' ), 'log' => array_slice( $log, -40 ) );
		update_option( self::STATE, $s, false );
	}

	private static function backup( string $key, $value ): void {
		$b = (array) get_option( self::BACKUP, array() );
		if ( ! array_key_exists( $key, $b ) ) { // păstrăm doar prima copie, cea dinaintea migrării
			$b[ $key ] = $value;
			update_option( self::BACKUP, $b, false );
		}
	}

	/* ---------------------------------------------------------------- verificări */

	private static function source_flow(): int {
		global $wpdb;
		$step = (int) $wpdb->get_var( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type='cartflows_step' AND post_name=%s AND post_status='publish' LIMIT 1", self::data()['cf_source']['step_slug'] ?? 'plata-craciun-2025' ) );
		return $step ? (int) get_post_meta( $step, 'wcf-flow-id', true ) : 0;
	}

	public static function checks(): array {
		$c   = array();
		$scp = defined( 'SCP_VERSION' ) ? SCP_VERSION : '';
		$c[] = array( 'Pluginul SC Programări Crăciun', $scp && version_compare( $scp, self::MIN_SCP, '>=' ), $scp ? 'versiunea ' . $scp . ( version_compare( $scp, self::MIN_SCP, '>=' ) ? '' : ', e nevoie de ' . self::MIN_SCP ) : 'nu e instalat sau nu e activ' );
		$c[] = array( 'WooCommerce', class_exists( 'WooCommerce' ), class_exists( 'WooCommerce' ) ? 'activ' : 'inactiv' );
		$c[] = array( 'CartFlows și CartFlows Pro', defined( 'CARTFLOWS_VER' ) && defined( 'CARTFLOWS_PRO_VER' ), ( defined( 'CARTFLOWS_VER' ) ? 'CartFlows ' . CARTFLOWS_VER : 'CartFlows lipsește' ) . ' · ' . ( defined( 'CARTFLOWS_PRO_VER' ) ? 'Pro ' . CARTFLOWS_PRO_VER : 'Pro lipsește' ) );
		$c[] = array( 'Elementor', defined( 'ELEMENTOR_VERSION' ), defined( 'ELEMENTOR_VERSION' ) ? ELEMENTOR_VERSION : 'inactiv' );
		$flow = self::source_flow();
		$c[] = array( 'Fluxul CartFlows din 2025 (sursa clonării)', $flow > 0, $flow ? 'flux #' . $flow . ', pasul „' . ( self::data()['cf_source']['step_slug'] ?? '' ) . '”' : 'nu a fost găsit pasul „plata-craciun-2025”' );
		$page = get_page_by_path( 'sedinte-foto-de-craciun' );
		$c[] = array( 'Pagina /sedinte-foto-de-craciun/', (bool) $page, $page ? '#' . $page->ID . ' „' . $page->post_title . '”' : 'nu există; va fi creată' );
		$ok_v = true;
		$det  = array();
		foreach ( (array) ( self::data()['wc_variations'] ?? array() ) as $k => $v ) {
			$p     = $v ? wc_get_product( (int) $v['id'] ) : null;
			$same  = $p && $p->get_name() === $v['name'];
			$ok_v  = $ok_v && $same;
			$det[] = $k . ': #' . ( $v['id'] ?? '?' ) . ( $same ? ' ok' : ' diferit (' . ( $p ? $p->get_name() : 'lipsește' ) . ')' );
		}
		$c[] = array( 'Produsele ședințelor (variațiile 2025)', $ok_v, implode( ' · ', $det ) );
		$up  = wp_upload_dir();
		$c[] = array( 'Folderul de uploads', wp_is_writable( $up['basedir'] ), $up['basedir'] );
		$c[] = array( 'Utilizator cu drept de cod nefiltrat', current_user_can( 'unfiltered_html' ), current_user_can( 'unfiltered_html' ) ? 'da (scripturile paginii rămân)' : 'nu: rulează ca administrator' );
		return $c;
	}

	/* ---------------------------------------------------------------- pași */

	private static function step_media(): array {
		$log  = array();
		$up   = wp_upload_dir();
		$base = trailingslashit( $up['basedir'] );
		$url  = trailingslashit( $up['baseurl'] );
		require_once ABSPATH . 'wp-admin/includes/image.php';
		$new = 0;
		$reg = 0;
		foreach ( (array) self::data()['files'] as $rel ) {
			$src = __DIR__ . '/files/' . $rel;
			$dst = $base . $rel;
			if ( ! file_exists( $src ) ) {
				$log[] = 'lipsește din pachet: ' . $rel;
				continue;
			}
			wp_mkdir_p( dirname( $dst ) );
			if ( ! file_exists( $dst ) || filesize( $dst ) !== filesize( $src ) ) {
				if ( ! copy( $src, $dst ) ) {
					throw new RuntimeException( 'Nu am putut copia ' . $rel );
				}
				++$new;
			}
			if ( ! attachment_url_to_postid( $url . $rel ) ) {
				$type = wp_check_filetype( $dst )['type'] ?: 'image/webp';
				$id   = wp_insert_attachment( array( 'post_mime_type' => $type, 'post_title' => preg_replace( '/[-_]+/', ' ', pathinfo( $dst, PATHINFO_FILENAME ) ), 'post_status' => 'inherit' ), $dst );
				$sz   = wp_getimagesize( $dst );
				wp_update_attachment_metadata( $id, array( 'width' => $sz[0] ?? 0, 'height' => $sz[1] ?? 0, 'file' => $rel, 'sizes' => array() ) );
				if ( preg_match( '#craciun-(\d{4})-galerie#', $rel, $m ) ) {
					update_post_meta( $id, '_wp_attachment_image_alt', 'Decor de Crăciun din studioul Ștefan Chelmu, ' . $m[1] );
				}
				++$reg;
			}
		}
		$log[] = count( (array) self::data()['files'] ) . ' fișiere în pachet, ' . $new . ' copiate, ' . $reg . ' adăugate în Media (fără miniaturi regenerate, ca să rămână optimizate).';
		return $log;
	}

	private static function voucher_ids(): array {
		$parent = get_page_by_path( 'voucher-sedinta-foto-de-craciun-2026', OBJECT, 'product' );
		if ( ! $parent ) {
			return array();
		}
		$map = array();
		foreach ( (array) self::data()['voucher'] as $k => $v ) {
			foreach ( wc_get_product( $parent->ID )->get_children() as $cid ) {
				$c = wc_get_product( $cid );
				if ( $c && ( $c->get_attributes()['pachet'] ?? '' ) === ( $v['attrs']['pachet'] ?? '' ) ) {
					$map[ $k ] = (int) $cid;
				}
			}
		}
		return $map;
	}

	private static function step_voucher(): array {
		$map = self::voucher_ids();
		if ( count( $map ) >= 2 ) {
			SCP_Settings::update( array( 'voucher_products' => $map ) );
			return array( 'Produsul voucher există deja: ' . wp_json_encode( $map ) . '.' );
		}
		$pid = SCP_Admin::create_voucher_product();
		if ( ! is_int( $pid ) ) {
			throw new RuntimeException( 'Produsul voucher nu a putut fi creat: ' . $pid );
		}
		wp_update_post( array( 'ID' => $pid, 'post_name' => 'voucher-sedinta-foto-de-craciun-2026' ) );
		return array( 'Produs voucher creat (#' . $pid . '), variații: ' . wp_json_encode( SCP_Settings::get( 'voucher_products' ) ) . '.' );
	}

	private static function step_settings( array $form ): array {
		$cur = (array) get_option( SCP_Settings::OPTION, array() );
		self::backup( 'scp_settings', $cur );
		$new = (array) self::data()['settings'];
		// ce ține de site-ul live rămâne al lui
		foreach ( array( 'webhook_url', 'webhook_secret', 'webhook_events' ) as $k ) {
			if ( ! empty( $cur[ $k ] ) ) {
				$new[ $k ] = $cur[ $k ];
			}
		}
		$new['admin_emails']     = $form['admin_emails'];
		$new['from_email']       = $form['from_email'];
		$new['from_name']        = $form['from_name'];
		$new['reply_to']         = $form['reply_to'];
		$new['voucher_products'] = self::voucher_ids() ?: (array) SCP_Settings::get( 'voucher_products' );
		update_option( SCP_Settings::OPTION, $new, false );
		if ( method_exists( 'SCP_Settings', 'flush' ) ) {
			SCP_Settings::flush();
		}
		$err = SCP_Pricing::sync_early_coupon();
		$log = array( 'Setările au fost importate (copia celor vechi e păstrată pentru restaurare).', 'Notificări: ' . $new['admin_emails'] . ' · expeditor: ' . ( $new['from_email'] ?: 'implicit' ) );
		$log[] = $err ? 'Cuponul ' . $new['early_coupon'] . ' NU a putut fi actualizat: ' . $err : 'Cuponul ' . $new['early_coupon'] . ' sincronizat.';
		return $log;
	}

	private static function step_cartflows(): array {
		require_once __DIR__ . '/data/cf2026.php';
		$flow = self::source_flow();
		if ( ! $flow ) {
			throw new RuntimeException( 'Nu am găsit fluxul CartFlows din 2025.' );
		}
		return scp_cf2026_setup( $flow );
	}

	private static function step_page(): array {
		$p    = (array) self::data()['page'];
		$page = get_page_by_path( 'sedinte-foto-de-craciun' );
		if ( ! $page ) {
			$id = wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => $p['title'], 'post_name' => 'sedinte-foto-de-craciun' ) );
		} else {
			$id = $page->ID;
			self::backup( 'page_' . $id, array( '_elementor_data' => get_post_meta( $id, '_elementor_data', true ), '_wp_page_template' => get_post_meta( $id, '_wp_page_template', true ), '_elementor_page_settings' => get_post_meta( $id, '_elementor_page_settings', true ), 'post_title' => $page->post_title, 'rank_math_title' => get_post_meta( $id, 'rank_math_title', true ), 'rank_math_description' => get_post_meta( $id, 'rank_math_description', true ), 'rank_math_facebook_image' => get_post_meta( $id, 'rank_math_facebook_image', true ), 'rank_math_facebook_image_id' => get_post_meta( $id, 'rank_math_facebook_image_id', true ) ) );
			if ( ! metadata_exists( 'post', $id, '_elementor_data_backup_inainte_de_2026' ) ) {
				update_post_meta( $id, '_elementor_data_backup_inainte_de_2026', wp_slash( get_post_meta( $id, '_elementor_data', true ) ) );
			}
		}
		$m = (array) $p['meta'];
		update_post_meta( $id, '_elementor_data', wp_slash( (string) $m['_elementor_data'] ) );
		update_post_meta( $id, '_elementor_edit_mode', 'builder' );
		update_post_meta( $id, '_wp_page_template', $m['_wp_page_template'] ?: 'elementor_canvas' );
		update_post_meta( $id, '_elementor_template_type', $m['_elementor_template_type'] ?: 'wp-page' );
		if ( defined( 'ELEMENTOR_VERSION' ) ) {
			update_post_meta( $id, '_elementor_version', ELEMENTOR_VERSION );
		}
		if ( ! empty( $m['_elementor_page_settings'] ) ) {
			update_post_meta( $id, '_elementor_page_settings', $m['_elementor_page_settings'] );
		}
		delete_post_meta( $id, '_elementor_element_cache' );
		delete_post_meta( $id, '_elementor_css' );
		wp_update_post( array( 'ID' => $id, 'post_title' => $p['title'] ) );
		$seo = (array) ( self::data()['seo'] ?? array() );
		foreach ( array( 'rank_math_title', 'rank_math_description', 'rank_math_focus_keyword' ) as $k ) {
			if ( ! empty( $seo[ $k ] ) ) {
				update_post_meta( $id, $k, $seo[ $k ] );
			}
		}
		if ( ! empty( $seo['og_image'] ) ) {
			$up  = wp_upload_dir();
			$url = trailingslashit( $up['baseurl'] ) . $seo['og_image'];
			$aid = attachment_url_to_postid( $url );
			if ( $aid ) {
				update_post_meta( $id, 'rank_math_facebook_image', $url );
				update_post_meta( $id, 'rank_math_facebook_image_id', $aid );
				update_post_meta( $id, 'rank_math_twitter_use_facebook', 'on' );
			}
		}
		return array( 'Pagina #' . $id . ' actualizată (varianta anterioară e salvată în câmpul _elementor_data_backup_inainte_de_2026 și în copia de restaurare).' );
	}

	private static function step_sms(): array {
		$sms = (array) ( self::data()['sms'] ?: array() );
		self::backup( 'scp_sms_settings', get_option( 'scp_sms_settings', array() ) );
		$cur            = class_exists( 'SCP_SMS' ) ? SCP_SMS::settings() : array();
		$new            = array_merge( $cur, $sms );
		$new['enabled'] = 0; // se pornește manual, după testul de livrare
		update_option( 'scp_sms_settings', $new, false );
		return array( $sms ? 'Setările SMS au fost importate; trimiterea automată e oprită până după testul de livrare.' : 'Pe Local nu existau setări SMS salvate; rămân cele implicite, cu trimiterea oprită.' );
	}

	private static function step_cache(): array {
		$log = array();
		if ( class_exists( '\Elementor\Plugin' ) ) {
			\Elementor\Plugin::$instance->files_manager->clear_cache();
			$log[] = 'Cache Elementor golit.';
		}
		if ( class_exists( '\CartflowsAdmin\AdminCore\Inc\AdminHelper' ) ) {
			\CartflowsAdmin\AdminCore\Inc\AdminHelper::clear_cache();
			$log[] = 'Cache CartFlows golit.';
		}
		flush_rewrite_rules( false );
		do_action( 'swcfpc_purge_cache' ); // Super Page Cache for Cloudflare
		if ( function_exists( 'rocket_clean_domain' ) ) {
			rocket_clean_domain();
		}
		if ( function_exists( 'litespeed_purge_all' ) ) {
			litespeed_purge_all();
		}
		do_action( 'litespeed_purge_all' );
		wp_cache_flush();
		$log[] = 'Cache de pagină și obiecte golit (unde pluginul de cache permite). Dacă folosești Cloudflare separat, golește-l și din panoul Cloudflare.';
		return $log;
	}

	/* ---------------------------------------------------------------- acțiuni */

	public static function handle_run(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Nu ai acces.' );
		}
		check_admin_referer( 'scm_run' );
		@set_time_limit( 300 ); // phpcs:ignore
		$p    = wp_unslash( $_POST ); // phpcs:ignore
		$form = array(
			'admin_emails' => implode( ', ', array_filter( array_map( 'sanitize_email', preg_split( '/[\s,;]+/', (string) ( $p['admin_emails'] ?? '' ) ) ), 'is_email' ) ),
			'from_email'   => sanitize_email( $p['from_email'] ?? '' ),
			'from_name'    => sanitize_text_field( $p['from_name'] ?? '' ),
			'reply_to'     => sanitize_email( $p['reply_to'] ?? '' ),
		);
		$only  = sanitize_key( $p['only'] ?? '' );
		$steps = $only && isset( self::STEPS[ $only ] ) ? array( $only ) : array_keys( self::STEPS );
		foreach ( self::checks() as $c ) {
			if ( ! $c[1] && ! str_starts_with( $c[0], 'Pagina' ) ) {
				wp_safe_redirect( add_query_arg( array( 'page' => self::SLUG, 'err' => rawurlencode( 'Verificarea nu a trecut: ' . $c[0] . ' (' . $c[2] . ')' ) ), admin_url( 'tools.php' ) ) );
				exit;
			}
		}
		foreach ( $steps as $step ) {
			try {
				$log = 'settings' === $step ? self::step_settings( $form ) : call_user_func( array( __CLASS__, 'step_' . $step ) );
				self::mark( $step, true, $log );
			} catch ( Throwable $e ) {
				self::mark( $step, false, array( $e->getMessage() ) );
				break;
			}
		}
		wp_safe_redirect( add_query_arg( array( 'page' => self::SLUG, 'done' => 1 ), admin_url( 'tools.php' ) ) . '#rezultat' );
		exit;
	}

	public static function handle_restore(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Nu ai acces.' );
		}
		check_admin_referer( 'scm_restore' );
		$b   = (array) get_option( self::BACKUP, array() );
		$log = array();
		foreach ( $b as $k => $v ) {
			if ( 'scp_settings' === $k || 'scp_sms_settings' === $k ) {
				update_option( $k, $v, false );
				$log[] = 'Restaurat: ' . $k;
			} elseif ( str_starts_with( $k, 'page_' ) ) {
				$id = (int) substr( $k, 5 );
				update_post_meta( $id, '_elementor_data', wp_slash( (string) $v['_elementor_data'] ) );
				update_post_meta( $id, '_wp_page_template', $v['_wp_page_template'] );
				update_post_meta( $id, '_elementor_page_settings', $v['_elementor_page_settings'] );
				wp_update_post( array( 'ID' => $id, 'post_title' => $v['post_title'] ) );
				foreach ( array( 'rank_math_title', 'rank_math_description', 'rank_math_facebook_image', 'rank_math_facebook_image_id' ) as $mk ) {
					if ( isset( $v[ $mk ] ) ) {
						update_post_meta( $id, $mk, $v[ $mk ] );
					}
				}
				delete_post_meta( $id, '_elementor_element_cache' );
				$log[] = 'Restaurată pagina #' . $id;
			}
		}
		$log[] = 'Checkouturile CartFlows 2026 și produsul voucher nu se șterg automat; dacă vrei, le muți la gunoi din CartFlows și WooCommerce.';
		self::step_cache();
		self::mark( 'restore', true, $log );
		wp_safe_redirect( add_query_arg( array( 'page' => self::SLUG, 'done' => 1 ), admin_url( 'tools.php' ) ) . '#rezultat' );
		exit;
	}

	/* ---------------------------------------------------------------- pagina */

	public static function page(): void {
		$checks = self::checks();
		$ok_all = ! array_filter( $checks, fn( $c ) => ! $c[1] && ! str_starts_with( $c[0], 'Pagina' ) );
		$state  = self::state();
		$cur    = class_exists( 'SCP_Settings' ) ? (array) get_option( SCP_Settings::OPTION, array() ) : array();
		$d      = self::data();
		$err    = isset( $_GET['err'] ) ? sanitize_text_field( wp_unslash( $_GET['err'] ) ) : ''; // phpcs:ignore
		$host   = (string) wp_parse_url( home_url(), PHP_URL_HOST );
		?>
		<div class="wrap" style="max-width:980px">
			<h1>Migrare Crăciun 2026</h1>
			<p>Pachet exportat de pe Local la <?php echo esc_html( (string) ( $d['exported_at'] ?? '?' ) ); ?>. Fiecare pas se poate rula de mai multe ori fără să dubleze nimic. Înainte de rulare, fă un backup al site-ului.</p>
			<?php if ( $err ) : ?><div class="notice notice-error"><p><?php echo esc_html( $err ); ?></p></div><?php endif; ?>

			<h2>1. Verificare</h2>
			<table class="widefat striped"><tbody>
				<?php foreach ( $checks as $c ) : ?>
					<tr><td style="width:32px"><?php echo $c[1] ? '<span style="color:#1f7a1f;font-size:18px">✓</span>' : ( str_starts_with( $c[0], 'Pagina' ) ? '•' : '<span style="color:#b32d2e;font-size:18px">✕</span>' ); ?></td><td><b><?php echo esc_html( $c[0] ); ?></b></td><td><?php echo esc_html( $c[2] ); ?></td></tr>
				<?php endforeach; ?>
			</tbody></table>

			<h2>2. Rulează</h2>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'scm_run' ); ?><input type="hidden" name="action" value="scm_run">
				<table class="form-table" role="presentation">
					<tr><th><label for="admin_emails">Notificări programări noi</label></th><td><input type="text" class="regular-text" id="admin_emails" name="admin_emails" value="<?php echo esc_attr( (string) ( $cur['admin_emails'] ?? get_option( 'admin_email' ) ) ); ?>"><p class="description">Pe Local era <?php echo esc_html( (string) ( $d['settings']['admin_emails'] ?? '' ) ); ?>. Primesc și raportul SMS zilnic, dacă în pagina SMS nu e setată altă adresă.</p></td></tr>
					<tr><th><label for="from_email">Email expeditor</label></th><td><input type="email" class="regular-text" id="from_email" name="from_email" value="<?php echo esc_attr( (string) ( $cur['from_email'] ?? '' ) ?: 'contact@stefanchelmu.ro' ); ?>"><p class="description">Trebuie să fie pe un domeniu verificat în Postmark.</p></td></tr>
					<tr><th><label for="from_name">Nume expeditor</label></th><td><input type="text" class="regular-text" id="from_name" name="from_name" value="<?php echo esc_attr( (string) ( $cur['from_name'] ?? '' ) ?: 'Ștefan Chelmu' ); ?>"></td></tr>
					<tr><th><label for="reply_to">Răspunsurile clienților la</label></th><td><input type="email" class="regular-text" id="reply_to" name="reply_to" value="<?php echo esc_attr( (string) ( $cur['reply_to'] ?? '' ) ?: 'contact@stefanchelmu.ro' ); ?>"></td></tr>
					<tr><th>Pași</th><td>
						<?php foreach ( self::STEPS as $k => $label ) : $st = $state[ $k ] ?? null; ?>
							<p><?php echo $st ? ( $st['ok'] ? '✓ ' : '✕ ' ) : '○ '; ?><?php echo esc_html( $label ); ?><?php echo $st ? ' <small>(' . esc_html( $st['at'] ) . ')</small>' : ''; ?></p>
						<?php endforeach; ?>
						<p><label>Doar un pas: <select name="only"><option value="">toți pașii, în ordine</option><?php foreach ( self::STEPS as $k => $label ) : ?><option value="<?php echo esc_attr( $k ); ?>"><?php echo esc_html( $label ); ?></option><?php endforeach; ?></select></label></p>
					</td></tr>
				</table>
				<p><button class="button button-primary button-hero" <?php disabled( ! $ok_all ); ?>>Rulează migrarea</button></p>
			</form>

			<?php if ( $state ) : ?>
				<h2 id="rezultat">3. Rezultat</h2>
				<?php foreach ( $state as $k => $st ) : ?>
					<div style="margin:0 0 12px;padding:10px 14px;background:#fff;border-left:4px solid <?php echo $st['ok'] ? '#1f7a1f' : '#b32d2e'; ?>">
						<b><?php echo esc_html( self::STEPS[ $k ] ?? 'Restaurare' ); ?></b> · <?php echo esc_html( $st['at'] ); ?>
						<ul style="margin:6px 0 0 18px;list-style:disc"><?php foreach ( (array) $st['log'] as $l ) : ?><li><?php echo esc_html( (string) $l ); ?></li><?php endforeach; ?></ul>
					</div>
				<?php endforeach; ?>

				<h2>4. Verifică pe site</h2>
				<ul style="list-style:disc;margin-left:18px">
					<li><a href="<?php echo esc_url( home_url( '/sedinte-foto-de-craciun/' ) ); ?>" target="_blank" rel="noopener">Landing page-ul</a>, pe desktop și pe telefon, într-o fereastră privată (fără admin bar și fără cache de admin).</li>
					<li><a href="<?php echo esc_url( home_url( '/voucher-craciun-2026/?pachet=foto' ) ); ?>" target="_blank" rel="noopener">Checkoutul de vouchere</a> și <a href="<?php echo esc_url( home_url( '/plata-craciun-2026/' ) ); ?>" target="_blank" rel="noopener">pagina de plată</a> (fără link valid arată doar mesajul).</li>
					<li>O programare de test din formular, apoi linkul de plată din email; anulează programarea din admin după test.</li>
					<li><a href="<?php echo esc_url( admin_url( 'admin.php?page=scp-sms' ) ); ?>">Pagina SMS</a>: cheia în wp-config.php, expeditorul, testul pe cele patru rețele, apoi pornirea trimiterii.</li>
				</ul>

				<h2>5. Restaurare</h2>
				<p>Pune înapoi pagina și setările de dinaintea migrării. Pozele, produsul voucher și checkouturile 2026 rămân.</p>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><?php wp_nonce_field( 'scm_restore' ); ?><input type="hidden" name="action" value="scm_restore"><button class="button">Restaurează varianta dinainte</button></form>
			<?php endif; ?>
			<p style="margin-top:28px;color:#646970">După ce totul e verificat, dezactivează și șterge pluginul „SC Migrare Crăciun 2026”. Copia de restaurare rămâne în baza de date (opțiunea <?php echo esc_html( self::BACKUP ); ?>) până o ștergi.</p>
			<?php if ( str_ends_with( $host, '.local' ) ) : ?><p><b>Atenție:</b> rulezi pe un site .local.</p><?php endif; ?>
		</div>
		<?php
	}
}
SCM_Migrare::init();
