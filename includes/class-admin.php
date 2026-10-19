<?php
defined( 'ABSPATH' ) || exit;

/**
 * Zona de administrare: panou, programări, calendar, setări, jurnal webhook.
 */
class SCP_Admin {

	const SLUG = 'scp';

	public static function init(): void {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'admin_post_scp_save_booking', array( __CLASS__, 'save_booking' ) );
		add_action( 'admin_post_scp_booking_action', array( __CLASS__, 'booking_action' ) );
		add_action( 'admin_post_scp_save_settings', array( __CLASS__, 'save_settings' ) );
		add_action( 'admin_post_scp_export', array( __CLASS__, 'export_csv' ) );
		add_action( 'admin_post_scp_voucher', array( __CLASS__, 'voucher_action' ) );
		add_action( 'admin_post_scp_resync', array( __CLASS__, 'resync_action' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( SCP_FILE ), array( __CLASS__, 'action_links' ) );
		add_action( 'in_admin_header', array( __CLASS__, 'quiet_notices' ), 1000 );
		add_action( 'wp_dashboard_setup', array( __CLASS__, 'dashboard_setup' ) );
	}

	/** Widgetul din Panoul de control WordPress, primul din coloana stângă. */
	public static function dashboard_setup(): void {
		if ( ! current_user_can( SCP_Install::CAP ) ) {
			return;
		}
		wp_add_dashboard_widget( 'scp_dashboard', 'Programări Crăciun', array( __CLASS__, 'dashboard_widget' ) );
		global $wp_meta_boxes;
		$core = &$wp_meta_boxes['dashboard']['normal']['core'];
		if ( isset( $core['scp_dashboard'] ) ) {
			$core = array( 'scp_dashboard' => $core['scp_dashboard'] ) + $core;
		}
	}

	/** „se anulează luni 21.10, 14:00 · în 5 ore” pentru o programare care așteaptă plata. */
	private static function expiry_label( array $b, bool $short = false ): string {
		if ( empty( $b['pay_by'] ) ) {
			return 'nu se anulează (ședința e aproape)';
		}
		$at   = new DateTimeImmutable( (string) $b['pay_by'], SCP_Helpers::tz() );
		$now  = SCP_Helpers::now();
		$when = $short ? $at->format( 'd.m H:i' ) : SCP_Helpers::datetime_long( (string) $b['pay_by'] );
		return $at <= $now ? 'termenul a trecut, se anulează acum' : $when . ' · în ' . human_time_diff( $now->getTimestamp(), $at->getTimestamp() );
	}

	public static function dashboard_widget(): void {
		$s    = SCP_Bookings::summary();
		$link = static fn( string $q ) => esc_url( admin_url( 'admin.php?' . $q ) );
		$tile = static function ( string $label, string $value, string $sub = '', string $href = '', string $tone = '' ) {
			echo '<a class="scpw-tile' . ( $tone ? ' scpw-tile--' . esc_attr( $tone ) : '' ) . '" href="' . esc_url( $href ) . '"><span class="scpw-label">' . esc_html( $label ) . '</span><b class="scpw-value">' . esc_html( $value ) . '</b>' . ( $sub ? '<span class="scpw-sub">' . esc_html( $sub ) . '</span>' : '' ) . '</a>';
		};
		?>
		<style>
			#scp_dashboard .inside { margin: 0; padding: 12px; }
			.scpw-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 8px; }
			.scpw-tile { display: block; padding: 10px 12px; border: 1px solid #e2ddd2; border-radius: 8px; background: #fbfaf7; color: #23211d; text-decoration: none; }
			.scpw-tile:hover { border-color: #2d661b; color: #23211d; }
			.scpw-label { display: block; font-size: 11.5px; color: #6f685c; }
			.scpw-value { display: block; font-size: 22px; line-height: 1.25; }
			.scpw-sub { display: block; font-size: 11.5px; color: #6f685c; }
			.scpw-tile--warn { background: #fdf7e8; border-color: #e6cf98; }
			.scpw-tile--ok { background: #eef4ea; border-color: #c4d8b8; }
			.scpw-tile--free { background: #f3effa; border-color: #d5c9ee; }
			.scpw-h { margin: 14px 0 6px !important; font-size: 13px !important; font-weight: 600 !important; }
			.scpw-list { margin: 0; }
			.scpw-list li { display: flex; justify-content: space-between; gap: 10px; margin: 0; padding: 6px 0; border-top: 1px solid #f0ece4; font-size: 12.5px; }
			.scpw-list small { color: #8a6a22; white-space: nowrap; }
			.scpw-actions { display: flex; flex-wrap: wrap; gap: 6px; margin-top: 12px; }
		</style>
		<div class="scpw-grid">
			<?php
			$tile( 'Programări active', (string) $s['active'], $s['target'] ? 'din ' . $s['target'] . ' (obiectiv)' : $s['last7'] . ' intrate în 7 zile', $link( 'page=scp-bookings&view=upcoming' ), '' );
			$tile( 'Încasat', SCP_Helpers::money( $s['paid_v'] ) . ' lei', $s['paid_n'] . ( 1 === $s['paid_n'] ? ' programare plătită' : ' programări plătite' ), $link( 'page=scp-bookings&view=upcoming' ), 'ok' );
			$tile( 'Așteaptă plata online', (string) $s['awaiting'], $s['expiring'] ? $s['expiring'] . ' se anulează în 24 de ore' : 'niciuna nu expiră în 24 de ore', $link( 'page=scp-bookings&view=awaiting' ), $s['expiring'] ? 'warn' : '' );
			$tile( 'De încasat la studio', (string) $s['studio_n'], SCP_Helpers::money( $s['studio_v'] ) . ' lei, cash sau card', $link( 'page=scp-bookings&view=studio' ), '' );
			$tile( 'Azi / mâine', $s['today'] . ' / ' . $s['tomorrow'], 'ședințe', $link( 'page=scp' ), '' );
			$tile( 'Anulate', (string) $s['cancelled'], $s['expired'] . ' neplătite în termen', $link( 'page=scp-bookings&view=cancelled' ), '' );
			if ( $s['free_n'] ) {
				$tile( 'Gratuite (oferite)', (string) $s['free_n'], 'în valoare de ' . SCP_Helpers::money( $s['free_v'] ) . ' lei', $link( 'page=scp-bookings&view=all&payment_status=free' ), 'free' );
			}
			?>
		</div>
		<?php if ( $s['next'] ) : ?>
			<h3 class="scpw-h">Se anulează automat, în ordine</h3>
			<ul class="scpw-list">
				<?php foreach ( $s['next'] as $b ) : ?>
					<li><a href="<?php echo $link( 'page=scp-bookings&booking=' . (int) $b['id'] ); // phpcs:ignore ?>"><?php echo esc_html( $b['name'] . ' · ' . SCP_Helpers::date_ro( $b['slot_date'] ) . ' ' . $b['slot_time'] ); ?></a><small><?php echo esc_html( self::expiry_label( $b, true ) ); ?></small></li>
				<?php endforeach; ?>
			</ul>
		<?php endif; ?>
		<div class="scpw-actions">
			<a class="button button-primary" href="<?php echo $link( 'page=scp-quick' ); // phpcs:ignore ?>">+ Programare</a>
			<a class="button" href="<?php echo $link( 'page=scp' ); // phpcs:ignore ?>">Panou programări</a>
			<a class="button" href="<?php echo $link( 'page=scp-calendar' ); // phpcs:ignore ?>">Calendar</a>
		</div>
		<?php
	}

	/** „+ Programare” în bara de sus, și pe site, și în admin (vizibil și pe telefon). */
	public static function admin_bar( WP_Admin_Bar $bar ): void {
		if ( ! current_user_can( SCP_Install::CAP ) ) {
			return;
		}
		$bar->add_node(
			array(
				'id'    => 'scp-quick',
				'title' => '<span class="ab-icon dashicons dashicons-calendar-alt" aria-hidden="true"></span><span class="ab-label">Programare</span>',
				'href'  => admin_url( 'admin.php?page=scp-quick' ),
				'meta'  => array( 'class' => 'scp-ab-quick', 'title' => 'Programare rapidă (telefon / mesaje)' ),
			)
		);
	}

	/** Pe telefon, bara WordPress ascunde nodurile proprii; îl păstrăm vizibil pe al nostru. */
	public static function admin_bar_css(): void {
		if ( ! is_admin_bar_showing() || ! current_user_can( SCP_Install::CAP ) ) {
			return;
		}
		echo '<style id="scp-ab">@media screen and (max-width:782px){#wpadminbar li#wp-admin-bar-scp-quick{display:block}#wpadminbar li#wp-admin-bar-scp-quick .ab-item{width:auto;padding:0 10px}#wpadminbar li#wp-admin-bar-scp-quick .ab-icon:before{top:3px}#wpadminbar li#wp-admin-bar-scp-quick .ab-label{display:none}}#wpadminbar li#wp-admin-bar-scp-quick .ab-icon:before{content:"\f508";top:2px}</style>' . "\n";
	}

	/** Pe paginile pluginului ascundem notificările altor pluginuri (reclame, sfaturi), ca să rămână doar ce contează. */
	public static function quiet_notices(): void {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( $screen && str_contains( (string) $screen->id, 'scp' ) ) {
			remove_all_actions( 'admin_notices' );
			remove_all_actions( 'all_admin_notices' );
		}
	}

	public static function action_links( array $links ): array {
		array_unshift( $links, '<a href="' . esc_url( admin_url( 'admin.php?page=scp-settings' ) ) . '">Setări</a>' );
		return $links;
	}

	public static function menu(): void {
		$cap   = SCP_Install::CAP;
		$count = self::today_count();
		$badge = $count ? ' <span class="awaiting-mod count-' . $count . '"><span class="pending-count">' . $count . '</span></span>' : '';

		add_menu_page( 'Programări Crăciun', 'Programări' . $badge, $cap, self::SLUG, array( __CLASS__, 'page_dashboard' ), 'dashicons-calendar-alt', 26 );
		add_submenu_page( self::SLUG, 'Panou', 'Panou', $cap, self::SLUG, array( __CLASS__, 'page_dashboard' ) );
		add_submenu_page( self::SLUG, 'Programare rapidă', 'Programare rapidă', $cap, 'scp-quick', array( __CLASS__, 'page_quick' ) );
		add_submenu_page( self::SLUG, 'Programări', 'Toate programările', $cap, 'scp-bookings', array( __CLASS__, 'page_bookings' ) );
		add_submenu_page( self::SLUG, 'Calendar', 'Calendar și blocări', $cap, 'scp-calendar', array( __CLASS__, 'page_calendar' ) );
		add_submenu_page( self::SLUG, 'Vouchere', 'Vouchere cadou', $cap, 'scp-vouchers', array( __CLASS__, 'page_vouchers' ) );
		add_submenu_page( self::SLUG, 'Setări', 'Setări', 'manage_options', 'scp-settings', array( __CLASS__, 'page_settings' ) );
		add_submenu_page( self::SLUG, 'Sincronizare Sheet', 'Sincronizare Sheet', 'manage_options', 'scp-webhook', array( __CLASS__, 'page_webhook' ) );
	}

	private static function today_count(): int {
		global $wpdb;
		$t = SCP_Install::table( 'bookings' );
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$t} WHERE active_slot IS NOT NULL AND slot_date = %s", SCP_Helpers::now()->format( 'Y-m-d' ) ) );
	}

	public static function assets( string $hook ): void {
		if ( ! str_contains( $hook, 'scp' ) ) {
			return;
		}
		wp_enqueue_style( 'scp-admin', SCP_URL . 'assets/css/admin.css', array(), SCP_VERSION );
		wp_enqueue_script( 'scp-admin', SCP_URL . 'assets/js/admin.js', array(), SCP_VERSION, true );
		wp_localize_script(
			'scp-admin',
			'SCP_ADMIN',
			array(
				'api'        => esc_url_raw( rest_url( SCP_Rest::NS . '/admin' ) ),
				'nonce'      => wp_create_nonce( 'wp_rest' ),
				'newUrl'     => admin_url( 'admin.php?page=scp-bookings&new=1' ),
				'quickUrl'   => admin_url( 'admin.php?page=scp-quick' ),
				'bookingUrl' => admin_url( 'admin.php?page=scp-bookings&booking=' ),
				'start'      => SCP_Settings::get( 'start_date' ),
				'end'        => SCP_Settings::get( 'end_date' ),
				'today'      => SCP_Helpers::now()->format( 'Y-m-d' ),
			)
		);
		if ( str_contains( $hook, 'scp-quick' ) ) {
			wp_enqueue_style( 'scp-quick', SCP_URL . 'assets/css/quick.css', array( 'scp-admin' ), SCP_VERSION );
			wp_enqueue_script( 'scp-quick', SCP_URL . 'assets/js/quick.js', array(), SCP_VERSION, true );
			$quotes = array();
			foreach ( SCP_Settings::packages() as $k => $p ) {
				$q        = SCP_Pricing::quote( $k );
				$quotes[] = array(
					'key'   => $k,
					'label' => $p['label'],
					'price' => SCP_Helpers::money( $q['price'] ),
					'list'  => $q['discount'] > 0 ? SCP_Helpers::money( $q['list'] ) : '',
				);
			}
			$start = max( (string) SCP_Settings::get( 'start_date' ), SCP_Helpers::now()->format( 'Y-m-d' ) );
			wp_localize_script(
				'scp-quick',
				'SCP_QUICK',
				array(
					'api'        => esc_url_raw( rest_url( SCP_Rest::NS . '/admin' ) ),
					'nonce'      => wp_create_nonce( 'wp_rest' ),
					'start'      => $start,
					'end'        => (string) SCP_Settings::get( 'end_date' ),
					'now'        => SCP_Helpers::now()->format( 'Y-m-d\TH:i' ),
					'packages'   => $quotes,
					'payHours'   => (int) SCP_Settings::get( 'pay_hours' ),
					'vouchers'   => (bool) SCP_Settings::get( 'vouchers_enabled' ),
					'bookingUrl' => admin_url( 'admin.php?page=scp-bookings&booking=' ),
				)
			);
		}
	}

	/* =========================================================
	 * Utilitare de afișare
	 * ======================================================= */

	private static function notice(): void {
		$map = array(
			'saved'      => array( 'success', 'Modificările au fost salvate.' ),
			'created'    => array( 'success', 'Programarea a fost adăugată.' ),
			'link_sent'  => array( 'success', 'Linkul de plată a fost trimis pe email.' ),
			'resent'     => array( 'success', 'Confirmarea a fost retrimisă.' ),
			'cancelled'  => array( 'success', 'Programarea a fost anulată, iar ora a redevenit liberă.' ),
			'settings'   => array( 'success', 'Setările au fost salvate.' ),
		);
		$key = isset( $_GET['scp_msg'] ) ? sanitize_key( wp_unslash( $_GET['scp_msg'] ) ) : ''; // phpcs:ignore
		if ( isset( $map[ $key ] ) ) {
			printf( '<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>', esc_attr( $map[ $key ][0] ), esc_html( $map[ $key ][1] ) );
		}
		$err = get_transient( 'scp_admin_error_' . get_current_user_id() );
		if ( $err ) {
			delete_transient( 'scp_admin_error_' . get_current_user_id() );
			printf( '<div class="notice notice-error is-dismissible"><p>%s</p></div>', esc_html( $err ) );
		}
	}

	private static function fail( string $msg, string $url ): void {
		set_transient( 'scp_admin_error_' . get_current_user_id(), $msg, 60 );
		wp_safe_redirect( $url );
		exit;
	}

	private static function badge( string $type, string $value ): string {
		$labels = 'pay' === $type ? SCP_Helpers::PAYMENT_STATUSES : ( 'source' === $type ? SCP_Helpers::SOURCES : SCP_Helpers::STATUSES );
		return '<span class="scp-badge scp-badge--' . esc_attr( $type . '-' . $value ) . '">' . esc_html( $labels[ $value ] ?? $value ) . '</span>';
	}

	private static function pkg_label( string $key ): string {
		return SCP_Settings::packages()[ $key ]['label'] ?? $key;
	}

	private static function header( string $title, string $actions = '', string $class = '' ): void {
		echo '<div class="wrap scp-admin' . ( $class ? ' ' . esc_attr( $class ) : '' ) . '">';
		echo '<div class="scp-admin-head"><h1>' . esc_html( $title ) . '</h1><div class="scp-admin-actions">' . $actions . '</div></div>'; // phpcs:ignore
		echo '<hr class="wp-header-end">';
		self::notice();
	}

	private static function footer(): void {
		echo '</div>';
	}

	private static function slot_label( array $b ): string {
		return ucfirst( SCP_Helpers::date_long( $b['slot_date'] ) ) . ', ' . $b['slot_time'];
	}

	/* =========================================================
	 * Panou
	 * ======================================================= */

	public static function page_dashboard(): void {
		$s        = SCP_Bookings::stats();
		$target   = max( 1, $s['target'] );
		$pct      = min( 100, round( $s['active'] / $target * 100 ) );
		$capacity = $s['booked'] + $s['free'];
		$occ      = $capacity ? round( $s['booked'] / $capacity * 100 ) : 0;
		$paid     = 0;
		$paid_n   = 0;
		foreach ( $s['by_pay'] as $r ) {
			if ( in_array( $r['payment_status'], SCP_Helpers::PAID, true ) && 'free' !== $r['payment_status'] ) {
				$paid   += (float) $r['v'];
				$paid_n += (int) $r['n'];
			}
		}
		$open = (bool) SCP_Settings::get( 'booking_open' );

		$actions = '<a class="button button-primary" href="' . esc_url( admin_url( 'admin.php?page=scp-quick' ) ) . '">+ Programare</a> <a class="button" href="' . esc_url( admin_url( 'admin.php?page=scp-calendar' ) ) . '">Calendar</a>';
		self::header( 'Programări Crăciun', $actions, 'scp-admin--full' );

		if ( ! $open ) {
			echo '<div class="notice notice-warning inline"><p>Programările online sunt <strong>închise</strong>. Clienții văd mesajul din Setări. <a href="' . esc_url( admin_url( 'admin.php?page=scp-settings' ) ) . '">Deschide-le din Setări</a>.</p></div>';
		}
		if ( current_user_can( 'manage_options' ) && '' === trim( (string) SCP_Settings::get( 'webhook_url' ) ) ) {
			echo '<div class="notice notice-info inline"><p>Copia programărilor în Google Sheet nu e configurată încă. <a href="' . esc_url( admin_url( 'admin.php?page=scp-webhook' ) ) . '">Vezi Sincronizare</a>.</p></div>';
		}

		$today    = SCP_Helpers::now()->format( 'Y-m-d' );
		$tomorrow = SCP_Helpers::now()->modify( '+1 day' )->format( 'Y-m-d' );
		$agenda   = array(
			'Azi'   => array( $today, array() ),
			'Mâine' => array( $tomorrow, array() ),
		);
		foreach ( $s['today'] as $b ) {
			$agenda[ $b['slot_date'] === $today ? 'Azi' : 'Mâine' ][1][] = $b;
		}
		$sum = $s['summary'];
		?>
		<div class="scp-kpis scp-kpis--6">
			<div class="scp-kpi scp-kpi--target">
				<div class="scp-kpi-label">Programări active</div>
				<div class="scp-kpi-value"><?php echo esc_html( (string) $s['active'] ); ?><span> din <?php echo esc_html( (string) $s['target'] ); ?></span></div>
				<div class="scp-progress" role="img" aria-label="<?php echo esc_attr( $pct . '% din obiectiv' ); ?>"><span style="width:<?php echo esc_attr( (string) $pct ); ?>%"></span></div>
				<div class="scp-kpi-sub"><?php echo esc_html( $pct . '% din obiectiv · ' . $s['last7'] . ' intrate în 7 zile' ); ?></div>
			</div>
			<a class="scp-kpi scp-kpi--ok" href="<?php echo esc_url( admin_url( 'admin.php?page=scp-bookings&view=upcoming' ) ); ?>">
				<div class="scp-kpi-label">Încasat</div>
				<div class="scp-kpi-value"><?php echo esc_html( SCP_Helpers::money( $sum['paid_v'] ) ); ?><span> lei</span></div>
				<div class="scp-kpi-sub"><?php echo esc_html( $sum['paid_n'] . ' programări plătite · valoare totală ' . SCP_Helpers::money( $s['value'] ) . ' lei' ); ?></div>
			</a>
			<a class="scp-kpi<?php echo $sum['expiring'] ? ' scp-kpi--warn' : ''; ?>" href="<?php echo esc_url( admin_url( 'admin.php?page=scp-bookings&view=awaiting' ) ); ?>">
				<div class="scp-kpi-label">Așteaptă plata online</div>
				<div class="scp-kpi-value"><?php echo esc_html( (string) $sum['awaiting'] ); ?></div>
				<div class="scp-kpi-sub"><?php echo esc_html( ( $sum['expiring'] ? $sum['expiring'] . ' se anulează în 24 de ore' : 'niciuna nu expiră în 24 de ore' ) . ' · ' . SCP_Helpers::money( $sum['awaiting_v'] ) . ' lei' ); ?></div>
			</a>
			<a class="scp-kpi" href="<?php echo esc_url( admin_url( 'admin.php?page=scp-bookings&view=studio' ) ); ?>">
				<div class="scp-kpi-label">De încasat la studio</div>
				<div class="scp-kpi-value"><?php echo esc_html( (string) $sum['studio_n'] ); ?></div>
				<div class="scp-kpi-sub"><?php echo esc_html( SCP_Helpers::money( $sum['studio_v'] ) . ' lei, cash sau card' ); ?></div>
			</a>
			<a class="scp-kpi" href="<?php echo esc_url( admin_url( 'admin.php?page=scp-bookings&view=cancelled' ) ); ?>">
				<div class="scp-kpi-label">Anulate</div>
				<div class="scp-kpi-value"><?php echo esc_html( (string) $sum['cancelled'] ); ?></div>
				<div class="scp-kpi-sub"><?php echo esc_html( $sum['expired'] . ' neplătite în termen · ' . ( $sum['cancelled'] - $sum['expired'] ) . ' din admin' ); ?></div>
			</a>
			<div class="scp-kpi">
				<div class="scp-kpi-label">Ocupare de azi până la final</div>
				<div class="scp-kpi-value"><?php echo esc_html( $occ . '%' ); ?></div>
				<div class="scp-kpi-sub"><?php echo esc_html( $s['booked'] . ' ocupate · ' . $s['free'] . ' libere · ' . $s['blocked'] . ' blocate' ); ?></div>
				<?php if ( $s['offered_n'] ) : ?>
					<div class="scp-kpi-sub scp-kpi-sub--free"><?php echo esc_html( 'Gratuite: ' . $s['offered_n'] . ', în valoare de ' . SCP_Helpers::money( $s['offered_v'] ) . ' lei' ); ?></div>
				<?php endif; ?>
			</div>
		</div>

		<div class="scp-dash-row">
			<div class="scp-panel">
				<h2>Azi și mâine</h2>
				<?php foreach ( $agenda as $label => $day ) : ?>
					<h3 class="scp-agenda-day"><?php echo esc_html( $label . ', ' . SCP_Helpers::date_long( $day[0] ) ); ?></h3>
					<?php self::mini_list( $day[1], 'Nicio ședință.', false, true ); ?>
				<?php endforeach; ?>
			</div>
			<div class="scp-panel">
				<h2>Se anulează automat <a class="scp-h2-link" href="<?php echo esc_url( admin_url( 'admin.php?page=scp-bookings&view=awaiting' ) ); ?>">vezi toate</a></h2>
				<?php if ( ! $s['awaiting'] ) : ?>
					<p class="scp-empty">Nimeni nu are de plătit acum.</p>
				<?php else : ?>
					<ul class="scp-mini">
						<?php foreach ( $s['awaiting'] as $b ) : ?>
							<li>
								<a href="<?php echo esc_url( admin_url( 'admin.php?page=scp-bookings&booking=' . $b['id'] ) ); ?>">
									<span class="scp-mini-main"><strong><?php echo esc_html( $b['name'] ); ?></strong><span><?php echo esc_html( ucfirst( SCP_Helpers::date_long( $b['slot_date'] ) ) . ', ' . $b['slot_time'] . ' · ' . SCP_Helpers::money( $b['price'] ) . ' lei' ); ?></span><span class="scp-mini-expiry"><?php echo esc_html( 'Se anulează ' . self::expiry_label( $b ) ); ?></span></span>
								</a>
								<?php if ( $b['phone'] ) : ?>
									<a class="scp-mini-call" href="<?php echo esc_url( 'tel:' . preg_replace( '/[^0-9+]/', '', $b['phone'] ) ); ?>" aria-label="<?php echo esc_attr( 'Sună pe ' . $b['name'] ); ?>"><?php echo esc_html( $b['phone'] ); ?></a>
								<?php endif; ?>
							</li>
						<?php endforeach; ?>
					</ul>
				<?php endif; ?>
			</div>
			<div class="scp-panel">
				<h2>Următoarele ședințe <a class="scp-h2-link" href="<?php echo esc_url( admin_url( 'admin.php?page=scp-bookings' ) ); ?>">vezi toate</a></h2>
				<?php self::mini_list( $s['upcoming'], 'Nu există alte ședințe viitoare.', true ); ?>
			</div>
			<div class="scp-panel">
				<h2>Ultimele intrate</h2>
				<?php self::mini_list( $s['recent'], 'Încă nu există programări.', true ); ?>
			</div>
		</div>

		<div class="scp-dash-stats">
			<div class="scp-panel">
				<h2>Programări noi pe zile <small>ultimele 30 de zile</small></h2>
				<?php self::bars( $s['per_day'] ); ?>
			</div>
			<div class="scp-panel">
				<?php self::split( 'Plată', $s['by_pay'], 'payment_status', fn( $k ) => SCP_Helpers::PAYMENT_STATUSES[ $k ] ?? $k, $s['active'] ); ?>
			</div>
			<div class="scp-panel">
				<?php self::split( 'Pachet', $s['by_package'], 'package', fn( $k ) => self::pkg_label( $k ), $s['active'] ); ?>
				<?php self::split( 'Sursă', $s['by_source'], 'source', fn( $k ) => SCP_Helpers::SOURCES[ $k ] ?? $k, $s['active'] ); ?>
			</div>
		</div>
		<?php
		self::footer();
	}

	/** Eticheta canalului (Email / SMS), cu iconiță; $compact = doar iconița, cu textul pentru cititoarele de ecran. */
	public static function channel( string $type, bool $compact = false ): string {
		$sms  = 'sms' === $type;
		$text = $sms ? 'SMS' : 'Email';
		return '<span class="scp-ch scp-ch--' . ( $sms ? 'sms' : 'mail' ) . ( $compact ? ' scp-ch--icon' : '' ) . '" title="' . $text . '"><span class="dashicons dashicons-' . ( $sms ? 'smartphone' : 'email-alt' ) . '" aria-hidden="true"></span>' . ( $compact ? '<span class="screen-reader-text">' . $text . '</span>' : $text ) . '</span>';
	}

	/** Eticheta de plată pe înțelesul tuturor (vezi SCP_Bookings::pay_state). */
	private static function pay_badge( array $b ): string {
		$st = SCP_Bookings::pay_state( $b );
		return '<span class="scp-badge scp-badge--ps-' . esc_attr( $st['key'] ) . '" title="' . esc_attr( $st['label'] ) . '">' . esc_html( $st['short'] ) . '</span>';
	}

	private static function mini_list( array $items, string $empty, bool $with_date = false, bool $collect = false ): void {
		if ( ! $items ) {
			echo '<p class="scp-empty">' . esc_html( $empty ) . '</p>';
			return;
		}
		echo '<ul class="scp-mini">';
		foreach ( $items as $b ) {
			$url = admin_url( 'admin.php?page=scp-bookings&booking=' . $b['id'] );
			echo '<li data-id="' . esc_attr( (string) $b['id'] ) . '"><a href="' . esc_url( $url ) . '">';
			echo '<span class="scp-mini-time">' . esc_html( $b['slot_time'] ) . '</span>';
			echo '<span class="scp-mini-main"><strong>' . esc_html( $b['name'] ) . '</strong><span>' . esc_html( ( $with_date ? ucfirst( SCP_Helpers::date_long( $b['slot_date'] ) ) . ' · ' : '' ) . self::pkg_label( $b['package'] ) . ' · ' . ( 'free' === $b['payment_status'] ? 'gratuit' : SCP_Helpers::money( $b['price'] ) . ' lei' ) . ( $b['phone'] ? ' · ' . $b['phone'] : '' ) ) . '</span></span>';
			echo self::pay_badge( $b ); // phpcs:ignore
			echo '</a>';
			if ( $collect && ! SCP_Bookings::is_paid( $b ) && 'cancelled' !== $b['status'] ) {
				echo '<span class="scp-collect-btns"><button type="button" class="button" data-collect="cash">Cash</button><button type="button" class="button" data-collect="card">Card</button></span>';
			}
			echo '</li>';
		}
		echo '</ul>';
	}

	private static function bars( array $rows ): void {
		$map = array();
		foreach ( $rows as $r ) {
			$map[ $r['d'] ] = (int) $r['n'];
		}
		$max = max( $map ? max( $map ) : 1, 1 );
		echo '<div class="scp-bars">';
		for ( $i = 29; $i >= 0; $i-- ) {
			$d = SCP_Helpers::now()->modify( "-{$i} days" )->format( 'Y-m-d' );
			$n = $map[ $d ] ?? 0;
			$h = $n ? max( 6, round( $n / $max * 100 ) ) : 2;
			printf(
				'<span class="scp-bar%4$s" title="%1$s: %2$d" style="height:%3$d%%"><em>%5$s</em></span>',
				esc_attr( SCP_Helpers::date_ro( $d ) ),
				(int) $n,
				(int) $h,
				$n ? '' : ' is-zero',
				$n ? esc_html( (string) $n ) : ''
			);
		}
		echo '</div><div class="scp-bars-axis"><span>' . esc_html( SCP_Helpers::now()->modify( '-29 days' )->format( 'd.m' ) ) . '</span><span>azi</span></div>';
	}

	private static function split( string $title, array $rows, string $key, callable $label, int $total ): void {
		echo '<div class="scp-split"><h3>' . esc_html( $title ) . '</h3>';
		if ( ! $rows ) {
			echo '<p class="scp-empty">Încă nu sunt date.</p></div>';
			return;
		}
		foreach ( $rows as $r ) {
			$pct = $total ? round( (int) $r['n'] / $total * 100 ) : 0;
			echo '<div class="scp-split-row"><span class="scp-split-label">' . esc_html( $label( $r[ $key ] ) ) . '</span><span class="scp-split-track"><span style="width:' . esc_attr( (string) $pct ) . '%"></span></span><span class="scp-split-n">' . esc_html( (string) $r['n'] ) . '</span></div>';
		}
		echo '</div>';
	}

	/* =========================================================
	 * Programare rapidă (telefon / mesaje)
	 * ======================================================= */

	public static function page_quick(): void {
		$back = '<a class="button" href="' . esc_url( admin_url( 'admin.php?page=scp-calendar' ) ) . '">Calendar</a> <a class="button" href="' . esc_url( admin_url( 'admin.php?page=scp-bookings' ) ) . '">Toate programările</a>';
		self::header( 'Programare rapidă', $back );
		?>
		<div class="scpq" id="scpq">
			<form class="scpq-form scp-panel" novalidate>
				<section class="scpq-step" data-step="day">
					<h2><span class="scpq-n">1</span> Ziua</h2>
					<div class="scpq-days" data-days><p class="scp-empty">Se încarcă zilele libere…</p></div>
				</section>

				<section class="scpq-step" data-step="time" hidden>
					<h2><span class="scpq-n">2</span> Ora <small data-day-label></small></h2>
					<div class="scpq-chips" data-times></div>
				</section>

				<section class="scpq-step" data-step="package" hidden>
					<h2><span class="scpq-n">3</span> Pachetul</h2>
					<div class="scpq-chips scpq-chips--wide" data-packages></div>
				</section>

				<section class="scpq-step" data-step="client" hidden>
					<h2><span class="scpq-n">4</span> Clientul</h2>
					<div class="scpq-fields">
						<label><span>Nume și prenume</span><input type="text" name="name" autocomplete="off" autocapitalize="words" required></label>
						<label><span>Telefon</span><input type="tel" name="phone" inputmode="tel" autocomplete="off" placeholder="07xx xxx xxx" required><em>Pe el pleacă SMS-urile.</em></label>
						<label><span>Email <small>(opțional, dar cere-l)</small></span><input type="email" name="email" inputmode="email" autocomplete="off" placeholder="pentru confirmare și linkul de plată"></label>
						<details class="scpq-more">
							<summary>Mai multe</summary>
							<label><span>Mesajul clientului</span><textarea name="message" rows="2" placeholder="ex. bebeluș de 8 luni, vin cu bunicii"></textarea></label>
							<label><span>Observații interne</span><textarea name="notes" rows="2" placeholder="Nu le vede clientul"></textarea></label>
						</details>
					</div>
				</section>

				<section class="scpq-step" data-step="pay" hidden>
					<h2><span class="scpq-n">5</span> Cum plătește</h2>
					<div class="scpq-pay" role="radiogroup">
						<label class="scpq-opt"><input type="radio" name="pay" value="online" checked><span><b>Online, cu link</b><em data-online-hint>Primește linkul pe SMS și email. Dacă nu plătește în termen, programarea se anulează și ora se eliberează.</em></span></label>
						<label class="scpq-opt"><input type="radio" name="pay" value="studio"><span><b>La studio, cash sau card</b><em>Confirmată direct, fără termen. Încasezi când vine.</em></span></label>
						<?php if ( SCP_Settings::get( 'vouchers_enabled' ) ) : ?>
							<label class="scpq-opt"><input type="radio" name="pay" value="voucher"><span><b>Voucher cadou</b><em>Plătită integral cu voucherul.</em></span></label>
						<?php endif; ?>
						<label class="scpq-opt"><input type="radio" name="pay" value="free"><span><b>Gratuită, oferită de noi</b><em>Confirmată direct, fără plată. Apare separat în calendar și în statistici.</em></span></label>
					</div>
					<label class="scpq-voucher" hidden><span>Cod voucher</span><input type="text" name="voucher" class="scp-mono" autocomplete="off" autocapitalize="characters" placeholder="CHELMU-…"></label>
					<p class="scpq-warn" data-lastminute hidden></p>
				</section>

				<div class="scpq-submit" hidden>
					<p class="scpq-error" role="alert" hidden></p>
					<button type="submit" class="button button-primary button-hero">Salvează programarea</button>
				</div>
			</form>

			<div class="scpq-done scp-panel" hidden aria-live="polite"></div>

		</div>
		<?php
		self::footer();
	}

	/* =========================================================
	 * Lista de programări
	 * ======================================================= */

	const VIEWS = array(
		'upcoming'  => 'Următoare',
		'awaiting'  => 'Se anulează automat',
		'studio'    => 'De încasat la studio',
		'past'      => 'Trecute',
		'cancelled' => 'Anulate',
		'all'       => 'Toate',
	);

	private static function filters_from_request(): array {
		$g    = wp_unslash( $_GET ); // phpcs:ignore
		$view = isset( $g['view'] ) ? sanitize_key( $g['view'] ) : '';
		$adv  = ! empty( $g['payment_status'] ) || ! empty( $g['package'] ) || ! empty( $g['source'] ) || ! empty( $g['from'] ) || ! empty( $g['to'] );
		if ( ! isset( self::VIEWS[ $view ] ) ) {
			// Cu filtre avansate sau căutare, căutăm în toate; altfel, ședințele care urmează.
			$view = ( $adv || ! empty( $g['s'] ) ) ? 'all' : 'upcoming';
		}
		return array(
			'view'           => $view,
			'payment_status' => isset( $g['payment_status'] ) ? sanitize_key( $g['payment_status'] ) : '',
			'package'        => isset( $g['package'] ) ? sanitize_key( $g['package'] ) : '',
			'source'         => isset( $g['source'] ) ? sanitize_key( $g['source'] ) : '',
			'from'           => isset( $g['from'] ) ? sanitize_text_field( $g['from'] ) : '',
			'to'             => isset( $g['to'] ) ? sanitize_text_field( $g['to'] ) : '',
			'search'         => isset( $g['s'] ) ? sanitize_text_field( $g['s'] ) : '',
			'orderby'        => isset( $g['orderby'] ) ? sanitize_key( $g['orderby'] ) : ( 'awaiting' === $view ? 'pay_by' : 'slot' ),
			'order'          => isset( $g['order'] ) ? sanitize_key( $g['order'] ) : ( in_array( $view, array( 'past', 'cancelled' ), true ) ? 'desc' : 'asc' ),
			'page'           => isset( $g['paged'] ) ? max( 1, (int) $g['paged'] ) : 1,
			'per_page'       => 50,
			'advanced'       => $adv,
		);
	}

	public static function page_bookings(): void {
		if ( isset( $_GET['booking'] ) || isset( $_GET['new'] ) ) { // phpcs:ignore
			self::page_booking_edit();
			return;
		}
		$f    = self::filters_from_request();
		$q    = SCP_Bookings::query( $f );
		$base = admin_url( 'admin.php?page=scp-bookings' );
		$exp  = wp_nonce_url( add_query_arg( array_merge( array( 'action' => 'scp_export' ), array_filter( array_diff_key( $f, array_flip( array( 'page', 'per_page', 'advanced', 'order', 'orderby' ) ) ) ) ), admin_url( 'admin-post.php' ) ), 'scp_export' );

		$actions = '<a class="button button-primary" href="' . esc_url( admin_url( 'admin.php?page=scp-quick' ) ) . '">+ Programare</a> <a class="button" href="' . esc_url( $exp ) . '">Export CSV</a>';
		self::header( 'Programări', $actions );
		?>
		<nav class="scp-views" aria-label="Vederi">
			<?php foreach ( self::VIEWS as $k => $l ) : ?>
				<a href="<?php echo esc_url( add_query_arg( 'view', $k, $base ) ); ?>" class="<?php echo $k === $f['view'] ? 'is-on' : ''; ?>" <?php echo $k === $f['view'] ? 'aria-current="page"' : ''; ?>><?php echo esc_html( $l ); ?></a>
			<?php endforeach; ?>
		</nav>
		<form class="scp-filters" method="get">
			<input type="hidden" name="page" value="scp-bookings">
			<input type="hidden" name="view" value="<?php echo esc_attr( $f['view'] ); ?>">
			<label class="scp-filter-search"><span class="screen-reader-text">Caută</span><input type="search" name="s" value="<?php echo esc_attr( $f['search'] ); ?>" placeholder="Caută după nume, telefon, email sau cod"></label>
			<button class="button">Caută</button>
			<details class="scp-more-filters" <?php echo $f['advanced'] ? 'open' : ''; ?>>
				<summary>Mai multe filtre</summary>
				<div class="scp-more-grid">
					<label><span>Plată</span>
						<select name="payment_status">
							<option value="">Oricare</option>
							<?php foreach ( SCP_Helpers::PAYMENT_STATUSES as $k => $l ) : ?>
								<option value="<?php echo esc_attr( $k ); ?>" <?php selected( $f['payment_status'], $k ); ?>><?php echo esc_html( $l ); ?></option>
							<?php endforeach; ?>
						</select>
					</label>
					<label><span>Pachet</span>
						<select name="package">
							<option value="">Oricare</option>
							<?php foreach ( SCP_Settings::packages() as $k => $p ) : ?>
								<option value="<?php echo esc_attr( $k ); ?>" <?php selected( $f['package'], $k ); ?>><?php echo esc_html( $p['label'] ); ?></option>
							<?php endforeach; ?>
						</select>
					</label>
					<label><span>Sursă</span>
						<select name="source">
							<option value="">Oricare</option>
							<?php foreach ( SCP_Helpers::SOURCES as $k => $l ) : ?>
								<option value="<?php echo esc_attr( $k ); ?>" <?php selected( $f['source'], $k ); ?>><?php echo esc_html( $l ); ?></option>
							<?php endforeach; ?>
						</select>
					</label>
					<label><span>De la</span><input type="date" name="from" value="<?php echo esc_attr( $f['from'] ); ?>"></label>
					<label><span>Până la</span><input type="date" name="to" value="<?php echo esc_attr( $f['to'] ); ?>"></label>
					<label><span>Ordine</span>
						<select name="orderby">
							<option value="slot" <?php selected( $f['orderby'], 'slot' ); ?>>După data ședinței</option>
							<option value="created" <?php selected( $f['orderby'], 'created' ); ?>>Ultimele intrate întâi</option>
							<option value="pay_by" <?php selected( $f['orderby'], 'pay_by' ); ?>>După termenul de plată (cine se anulează primul)</option>
						</select>
					</label>
					<div class="scp-more-actions"><button class="button">Aplică</button> <a href="<?php echo esc_url( add_query_arg( 'view', $f['view'], $base ) ); ?>">Resetează</a></div>
				</div>
			</details>
			<?php if ( 'created' === $f['orderby'] ) : ?>
				<input type="hidden" name="order" value="desc">
			<?php endif; ?>
		</form>
		<p class="scp-count"><?php echo esc_html( $q['total'] . ( 1 === $q['total'] ? ' programare' : ' programări' ) ); ?><?php echo 'awaiting' === $f['view'] ? esc_html( ' care așteaptă plata online, în ordinea în care se anulează automat dacă nu plătesc.' ) : ''; ?></p>
		<div class="scp-panel scp-panel--flush">
			<?php self::table( $q['items'], false, 'awaiting' === $f['view'] ); ?>
		</div>
		<?php
		$pages = (int) ceil( $q['total'] / $f['per_page'] );
		if ( $pages > 1 ) {
			echo '<div class="scp-pages">';
			echo paginate_links( // phpcs:ignore
				array(
					'base'    => add_query_arg( 'paged', '%#%' ),
					'format'  => '',
					'current' => $f['page'],
					'total'   => $pages,
				)
			);
			echo '</div>';
		}
		self::footer();
	}

	private static function table( array $items, bool $compact = false, bool $expiry = false ): void {
		if ( ! $items ) {
			echo '<p class="scp-empty scp-empty--pad">Nicio programare aici.</p>';
			return;
		}
		echo '<table class="scp-table"><thead><tr>' . ( $expiry ? '<th>Se anulează</th>' : '' ) . '<th>Ședința</th><th>Client</th><th>Pachet</th><th>Stare</th>';
		if ( ! $compact ) {
			echo '<th>Sursă</th>';
		}
		echo '<th>Intrată</th></tr></thead><tbody>';
		foreach ( $items as $b ) {
			$url = admin_url( 'admin.php?page=scp-bookings&booking=' . $b['id'] );
			$cls = 'cancelled' === $b['status'] ? ' class="is-cancelled"' : '';
			echo '<tr' . $cls . ' data-href="' . esc_url( $url ) . '">'; // phpcs:ignore
			if ( $expiry ) {
				$soon = ! empty( $b['pay_by'] ) && strtotime( (string) $b['pay_by'] ) - SCP_Helpers::now()->getTimestamp() < DAY_IN_SECONDS;
				echo '<td class="scp-td-expiry' . ( $soon ? ' is-soon' : '' ) . '">' . esc_html( self::expiry_label( $b, true ) ) . '</td>';
			}
			echo '<td class="scp-td-slot"><a href="' . esc_url( $url ) . '"><strong>' . esc_html( SCP_Helpers::date_ro( $b['slot_date'] ) ) . '</strong> <span>' . esc_html( $b['slot_time'] ) . '</span><small>' . esc_html( SCP_Helpers::WEEKDAYS[ SCP_Helpers::weekday( $b['slot_date'] ) ] ) . '</small></a></td>';
			echo '<td><strong>' . esc_html( $b['name'] ) . '</strong><small>' . esc_html( trim( $b['phone'] . ( $b['email'] ? ' · ' . $b['email'] : '' ), ' ·' ) ) . '</small></td>';
			echo '<td>' . esc_html( self::pkg_label( $b['package'] ) ) . '<small>' . esc_html( SCP_Helpers::money( $b['price'] ) ) . ' lei</small></td>';
			// O singură coloană: anulată / finalizată / starea plății.
			if ( in_array( $b['status'], array( 'cancelled', 'completed', 'no_show' ), true ) ) {
				echo '<td>' . self::badge( 'status', $b['status'] ) . ( 'cancelled' === $b['status'] && 'expired' === $b['cancel_reason'] ? '<small>neplătită în termen</small>' : '' ) . '</td>'; // phpcs:ignore
			} else {
				echo '<td>' . self::pay_badge( $b ) . '</td>'; // phpcs:ignore
			}
			if ( ! $compact ) {
				echo '<td>' . esc_html( SCP_Helpers::SOURCES[ $b['source'] ] ?? $b['source'] ) . '</td>';
			}
			echo '<td><small class="scp-nowrap">' . esc_html( ( new DateTimeImmutable( $b['created_at'], SCP_Helpers::tz() ) )->format( 'd.m H:i' ) ) . '</small></td>';
			echo '</tr>';
		}
		echo '</tbody></table>';
	}

	/* =========================================================
	 * Fișa programării (adăugare / modificare)
	 * ======================================================= */

	private static function page_booking_edit(): void {
		$id  = isset( $_GET['booking'] ) ? (int) $_GET['booking'] : 0; // phpcs:ignore
		$b   = $id ? SCP_Bookings::get( $id ) : null;
		$new = ! $b;
		if ( $id && ! $b ) {
			self::header( 'Programare' );
			echo '<p>Programarea nu există. <a href="' . esc_url( admin_url( 'admin.php?page=scp-bookings' ) ) . '">Înapoi la listă</a></p>';
			self::footer();
			return;
		}
		$packages = SCP_Settings::packages();
		$g        = wp_unslash( $_GET ); // phpcs:ignore
		$v        = $b ?: array(
			'slot_date'      => isset( $g['date'] ) && SCP_Helpers::is_date( $g['date'] ) ? $g['date'] : '',
			'slot_time'      => isset( $g['time'] ) && SCP_Helpers::is_time( $g['time'] ) ? $g['time'] : '',
			'package'        => (string) array_key_first( $packages ),
			'price'          => '',
			'name'           => '',
			'email'          => '',
			'phone'          => '',
			'message'        => '',
			'notes'          => '',
			'status'         => 'confirmed',
			'payment_status' => 'unpaid',
			'source'         => 'phone',
			'discount_source'=> '',
			'discount_code'  => '',
		);

		$back = '<a class="button" href="' . esc_url( admin_url( 'admin.php?page=scp-bookings' ) ) . '">Înapoi la listă</a>';
		self::header( $new ? 'Programare nouă' : 'Programarea ' . $b['code'], $back );
		?>
		<div class="scp-edit">
			<form class="scp-panel scp-edit-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'scp_save_booking' ); ?>
				<input type="hidden" name="action" value="scp_save_booking">
				<input type="hidden" name="id" value="<?php echo esc_attr( (string) ( $b['id'] ?? 0 ) ); ?>">

				<h2>Ședința</h2>
				<div class="scp-form-grid">
					<label><span>Data</span><input type="date" name="date" id="scp-date" required value="<?php echo esc_attr( $v['slot_date'] ); ?>"></label>
					<label><span>Ora</span>
						<select name="time" id="scp-time" data-current="<?php echo esc_attr( $v['slot_time'] ); ?>" data-booking="<?php echo esc_attr( (string) ( $b['id'] ?? 0 ) ); ?>" required>
							<?php if ( $v['slot_time'] ) : ?>
								<option value="<?php echo esc_attr( $v['slot_time'] ); ?>" selected><?php echo esc_html( $v['slot_time'] ); ?></option>
							<?php else : ?>
								<option value="">Alege data mai întâi</option>
							<?php endif; ?>
						</select>
					</label>
					<label class="scp-custom-time"><span>Altă oră</span><input type="time" name="custom_time" step="300" placeholder="hh:mm"><em>Doar dacă ora nu e în listă.</em></label>
					<label><span>Pachet</span>
						<select name="package">
							<?php foreach ( $packages as $k => $p ) : ?>
								<option value="<?php echo esc_attr( $k ); ?>" data-price="<?php echo esc_attr( (string) $p['price'] ); ?>" <?php selected( $v['package'], $k ); ?>><?php echo esc_html( $p['label'] . ' – ' . SCP_Helpers::money( $p['price'] ) . ' lei' ); ?></option>
							<?php endforeach; ?>
						</select>
					</label>
					<label><span>Voucher cadou</span><input type="text" name="voucher" class="scp-mono" value="<?php echo esc_attr( 'gift' === ( $v['discount_source'] ?? '' ) ? $v['discount_code'] : '' ); ?>" placeholder="opțional"><em>Cu voucher, programarea e plătită integral.</em></label>
					<label><span>Preț de plată (lei)</span><input type="text" inputmode="decimal" name="price" value="<?php echo esc_attr( '' === $v['price'] ? '' : SCP_Helpers::money( $v['price'] ) ); ?>" placeholder="calculat automat"><em>Scrie altă sumă doar pentru un preț special.</em></label>
				</div>
				<?php if ( ! $new && $b['discount'] > 0 ) : ?>
					<p class="scp-price-note">
						<?php
						$src = array(
							'early'   => (string) SCP_Settings::get( 'early_label' ),
							'gift'    => 'Voucher ' . $b['discount_code'],
							'manual'  => 'Preț stabilit manual',
							'free'    => 'Ședință oferită gratuit',
						)[ $b['discount_source'] ] ?? '';
						echo esc_html( SCP_Helpers::money( $b['list_price'] ) . ' lei − ' . SCP_Helpers::money( $b['discount'] ) . ' lei' . ( $src ? ' (' . $src . ')' : '' ) . ' = ' . SCP_Helpers::money( $b['price'] ) . ' lei' );
						?>
					</p>
				<?php endif; ?>
				<p class="scp-slot-hint" id="scp-slot-hint" aria-live="polite"></p>

				<h2>Client</h2>
				<div class="scp-form-grid">
					<label class="scp-wide"><span>Nume și prenume</span><input type="text" name="name" required value="<?php echo esc_attr( $v['name'] ); ?>"></label>
					<label><span>Telefon</span><input type="tel" name="phone" value="<?php echo esc_attr( $v['phone'] ); ?>"></label>
					<label><span>Email</span><input type="email" name="email" value="<?php echo esc_attr( $v['email'] ); ?>"></label>
					<label class="scp-wide"><span>Mesajul clientului</span><textarea name="message" rows="2"><?php echo esc_textarea( (string) $v['message'] ); ?></textarea></label>
					<label class="scp-wide"><span>Observații interne</span><textarea name="notes" rows="2" placeholder="Nu le vede clientul"><?php echo esc_textarea( (string) $v['notes'] ); ?></textarea></label>
				</div>

				<h2>Status</h2>
				<div class="scp-form-grid">
					<label><span>Programare</span>
						<select name="status">
							<?php foreach ( SCP_Helpers::STATUSES as $k => $l ) : ?>
								<option value="<?php echo esc_attr( $k ); ?>" <?php selected( $v['status'], $k ); ?>><?php echo esc_html( $l ); ?></option>
							<?php endforeach; ?>
						</select>
					</label>
					<label><span>Plată</span>
						<select name="payment_status">
							<?php foreach ( SCP_Helpers::PAYMENT_STATUSES as $k => $l ) : ?>
								<option value="<?php echo esc_attr( $k ); ?>" <?php selected( $v['payment_status'], $k ); ?>><?php echo esc_html( $l ); ?></option>
							<?php endforeach; ?>
						</select>
					</label>
					<?php if ( $new ) : ?>
						<label><span>Sursă</span>
							<select name="source">
								<option value="phone" selected>Telefon</option>
								<option value="admin">Admin</option>
							</select>
						</label>
					<?php endif; ?>
				</div>

				<div class="scp-checks">
					<?php if ( $new ) : ?>
						<?php if ( (int) SCP_Settings::get( 'pay_hours' ) > 0 ) : ?>
							<label><input type="checkbox" name="require_payment" value="1" checked> Cere plata online în <?php echo esc_html( (string) SCP_Settings::get( 'pay_hours' ) ); ?> de ore (altfel programarea e confirmată direct, cu plata la studio)</label>
						<?php endif; ?>
						<label><input type="checkbox" name="send_confirmation" value="1" checked> Trimite emailul clientului (dacă are email)</label>
					<?php else : ?>
						<label><input type="checkbox" name="notify_client" value="1" checked> Anunță clientul pe email dacă mut sau anulez programarea</label>
					<?php endif; ?>
				</div>

				<div class="scp-edit-submit">
					<button class="button button-primary button-large"><?php echo $new ? 'Salvează programarea' : 'Salvează modificările'; ?></button>
				</div>
			</form>

			<?php if ( ! $new ) : ?>
				<aside class="scp-edit-side">
					<div class="scp-panel scp-summary-card">
						<div class="scp-summary-when"><?php echo esc_html( self::slot_label( $b ) ); ?></div>
						<div class="scp-summary-badges"><?php
						if ( in_array( $b['status'], array( 'cancelled', 'completed', 'no_show' ), true ) ) {
							echo self::badge( 'status', $b['status'] ); // phpcs:ignore
						} else {
							echo ( 'pending' === $b['status'] ? '' : self::badge( 'status', $b['status'] ) . ' ' ) . self::pay_badge( $b ); // phpcs:ignore
						}
						?></div>
						<dl>
							<dt>Cod</dt><dd class="scp-mono"><?php echo esc_html( $b['code'] ); ?></dd>
							<dt>Sursă</dt><dd><?php echo esc_html( SCP_Helpers::SOURCES[ $b['source'] ] ?? $b['source'] ); ?></dd>
							<dt>Intrată</dt><dd><?php echo esc_html( ( new DateTimeImmutable( $b['created_at'], SCP_Helpers::tz() ) )->format( 'd.m.Y H:i' ) ); ?></dd>
							<dt>Modificată</dt><dd><?php echo esc_html( ( new DateTimeImmutable( $b['updated_at'], SCP_Helpers::tz() ) )->format( 'd.m.Y H:i' ) ); ?></dd>
							<?php if ( 'pending' === $b['status'] && $b['pay_by'] ) : ?>
								<dt>Plată până</dt><dd><strong><?php echo esc_html( ( new DateTimeImmutable( $b['pay_by'], SCP_Helpers::tz() ) )->format( 'd.m.Y H:i' ) ); ?></strong></dd>
							<?php endif; ?>
							<?php if ( $b['cancel_reason'] ) : ?>
								<dt>Motiv anulare</dt><dd><?php echo esc_html( 'expired' === $b['cancel_reason'] ? 'Neplătită în termen' : 'Anulată din admin' ); ?></dd>
							<?php endif; ?>
							<?php if ( $b['payment_link_sent_at'] ) : ?>
								<dt>Link plată trimis</dt><dd><?php echo esc_html( ( new DateTimeImmutable( $b['payment_link_sent_at'], SCP_Helpers::tz() ) )->format( 'd.m.Y H:i' ) ); ?></dd>
							<?php endif; ?>
							<?php if ( $b['paid_at'] ) : ?>
								<dt>Plătită</dt><dd><?php echo esc_html( ( new DateTimeImmutable( $b['paid_at'], SCP_Helpers::tz() ) )->format( 'd.m.Y H:i' ) ); ?></dd>
							<?php endif; ?>
							<?php if ( $b['order_id'] ) : ?>
								<dt>Comandă</dt><dd><a href="<?php echo esc_url( admin_url( 'post.php?post=' . $b['order_id'] . '&action=edit' ) ); ?>">#<?php echo esc_html( (string) $b['order_id'] ); ?></a></dd>
							<?php endif; ?>
							<?php if ( ! empty( $b['meta']['utm']['utm_source'] ) || ! empty( $b['meta']['utm']['referrer'] ) ) : ?>
								<dt>Venit din</dt><dd><?php echo esc_html( $b['meta']['utm']['utm_source'] ?? wp_parse_url( $b['meta']['utm']['referrer'], PHP_URL_HOST ) ); ?></dd>
							<?php endif; ?>
						</dl>
					</div>

					<?php if ( 'cancelled' !== $b['status'] ) : ?>
						<?php if ( ! SCP_Bookings::is_paid( $b ) ) : ?>
						<div class="scp-panel">
							<h2>Plată online</h2>
							<?php $pay = SCP_Bookings::payment_url( $b ); ?>
							<?php if ( $pay ) : ?>
								<div class="scp-copy">
									<input type="text" readonly value="<?php echo esc_attr( $pay ); ?>" id="scp-pay-url">
									<button type="button" class="button" data-copy="#scp-pay-url">Copiază</button>
								</div>
								<?php self::action_button( $b['id'], 'payment_link', $b['payment_link_sent_at'] ? 'Retrimite linkul pe email' : 'Trimite linkul pe email', 'button-primary', ! $b['email'] ); ?>
								<?php if ( ! $b['email'] ) : ?>
									<p class="description">Programarea nu are email. Copiază linkul și trimite-l pe WhatsApp sau SMS.</p>
								<?php else : ?>
									<p class="description">Linkul conține doar un cod al programării, fără date personale. La plată, statusul devine automat „Plătită online”.</p>
								<?php endif; ?>
							<?php else : ?>
								<p>Completează în <a href="<?php echo esc_url( admin_url( 'admin.php?page=scp-settings#plata' ) ); ?>">Setări</a> adresa paginii de plată.</p>
							<?php endif; ?>
						</div>

						<?php endif; ?>

						<div class="scp-panel">
							<h2>Alte acțiuni</h2>
							<?php if ( ! SCP_Bookings::is_paid( $b ) ) : ?>
								<div class="scp-collect">
									<span>Încasat la studio:</span>
									<?php self::action_button( $b['id'], 'cash', 'Cash' ); ?>
									<?php self::action_button( $b['id'], 'card', 'Card' ); ?>
								</div>
							<?php endif; ?>
							<?php if ( 'pending' === $b['status'] ) : ?>
								<?php self::action_button( $b['id'], 'studio', 'Trece pe plată la studio', '', false, 'Programarea se confirmă și nu mai expiră. Clientul primește confirmarea pe SMS și email.' ); ?>
								<?php self::action_button( $b['id'], 'extend', 'Prelungește termenul cu 24 de ore' ); ?>
							<?php endif; ?>
							<?php if ( 'pending' !== $b['status'] ) : ?>
								<?php self::action_button( $b['id'], 'resend', 'Retrimite confirmarea pe email', '', ! $b['email'] ); ?>
							<?php endif; ?>
							<?php self::action_button( $b['id'], 'cancel', 'Anulează programarea', 'scp-danger', false, 'Sigur anulezi programarea? Ora redevine liberă pentru alți clienți.' ); ?>
						</div>
					<?php endif; ?>

					<?php $log = SCP_Webhook::log( 10, $b['id'] ); ?>
					<?php if ( $log ) : ?>
						<div class="scp-panel">
							<h2>Trimiteri către n8n</h2>
							<ul class="scp-hooklist">
								<?php foreach ( $log as $l ) : ?>
									<li><span class="scp-dot scp-dot--<?php echo esc_attr( $l['status'] ); ?>"></span><?php echo esc_html( $l['event'] ); ?><small><?php echo esc_html( ( new DateTimeImmutable( $l['created_at'], SCP_Helpers::tz() ) )->format( 'd.m H:i' ) . ( $l['response_code'] ? ' · HTTP ' . $l['response_code'] : '' ) ); ?></small></li>
								<?php endforeach; ?>
							</ul>
						</div>
					<?php endif; ?>
				</aside>
			<?php endif; ?>
		</div>
		<?php
		self::footer();
	}

	private static function action_button( int $id, string $do, string $label, string $cls = '', bool $disabled = false, string $confirm = '' ): void {
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="scp-inline-form" <?php echo $confirm ? 'data-confirm="' . esc_attr( $confirm ) . '"' : ''; ?>>
			<?php wp_nonce_field( 'scp_booking_action_' . $id ); ?>
			<input type="hidden" name="action" value="scp_booking_action">
			<input type="hidden" name="id" value="<?php echo esc_attr( (string) $id ); ?>">
			<input type="hidden" name="do" value="<?php echo esc_attr( $do ); ?>">
			<?php if ( 'cancel' === $do ) : ?>
				<label class="scp-inline-check"><input type="checkbox" name="notify_client" value="1" checked> anunță clientul</label>
			<?php endif; ?>
			<button class="button <?php echo esc_attr( $cls ); ?>" <?php disabled( $disabled ); ?>><?php echo esc_html( $label ); ?></button>
		</form>
		<?php
	}

	public static function save_booking(): void {
		if ( ! current_user_can( SCP_Install::CAP ) ) {
			wp_die( 'Nu ai acces.' );
		}
		check_admin_referer( 'scp_save_booking' );
		$p  = wp_unslash( $_POST );
		$id = (int) ( $p['id'] ?? 0 );

		$time = ! empty( $p['custom_time'] ) && SCP_Helpers::is_time( $p['custom_time'] ) ? $p['custom_time'] : ( $p['time'] ?? '' );
		$data = array(
			'date'           => $p['date'] ?? '',
			'time'           => $time,
			'package'        => $p['package'] ?? '',
			'name'           => $p['name'] ?? '',
			'email'          => $p['email'] ?? '',
			'phone'          => $p['phone'] ?? '',
			'message'        => $p['message'] ?? '',
			'notes'          => $p['notes'] ?? '',
			'status'         => $p['status'] ?? 'confirmed',
			'payment_status' => $p['payment_status'] ?? 'unpaid',
			'notify_client'  => ! empty( $p['notify_client'] ),
			'voucher'        => $p['voucher'] ?? '',
			'require_payment'=> ! empty( $p['require_payment'] ),
		);
		if ( isset( $p['price'] ) && '' !== trim( (string) $p['price'] ) ) {
			$data['price'] = str_replace( array( '.', ',' ), array( '', '.' ), (string) $p['price'] );
		}

		if ( ! $id ) {
			$back = admin_url( 'admin.php?page=scp-bookings&new=1&date=' . rawurlencode( (string) $data['date'] ) . '&time=' . rawurlencode( (string) $time ) );
			if ( empty( $p['send_confirmation'] ) ) {
				remove_action( 'scp_booking_created', array( 'SCP_Mailer', 'on_created' ), 20 );
				// Ștefan primește totuși alerta internă.
				add_action(
					'scp_booking_created',
					static function ( $b ) {
						$admins = SCP_Settings::admin_emails();
						if ( $admins ) {
							SCP_Mailer::send_template( 'admin_new', $b, $admins, array( 'admin' => true ) );
						}
					},
					20
				);
			}
			$source = in_array( $p['source'] ?? '', array( 'phone', 'admin' ), true ) ? $p['source'] : 'phone';
			// Fără plată online și fără plată deja trecută: confirmată cu plata la studio (primește SMS-urile).
			$studio = empty( $data['require_payment'] ) && in_array( $data['payment_status'], array( 'unpaid', 'link_sent' ), true ) && '' === trim( (string) $data['voucher'] );
			$b      = SCP_Bookings::create( $data, $source, $studio ? array( 'pay_at' => 'studio' ) : array() );
			if ( is_wp_error( $b ) ) {
				$fields = (array) ( $b->get_error_data()['fields'] ?? array() );
				self::fail( $b->get_error_message() . ( $fields ? ' ' . implode( ' ', $fields ) : '' ), $back );
			}
			// Statusurile alese la creare (de ex. plătită la studio).
			if ( 'unpaid' !== $data['payment_status'] || 'confirmed' !== $data['status'] || isset( $data['price'] ) ) {
				SCP_Bookings::update(
					$b['id'],
					array_intersect_key( $data, array_flip( array( 'payment_status', 'status', 'price' ) ) )
				);
			}
			wp_safe_redirect( admin_url( 'admin.php?page=scp-bookings&booking=' . $b['id'] . '&scp_msg=created' ) );
			exit;
		}

		$b = SCP_Bookings::update( $id, $data );
		if ( is_wp_error( $b ) ) {
			self::fail( $b->get_error_message(), admin_url( 'admin.php?page=scp-bookings&booking=' . $id ) );
		}
		wp_safe_redirect( admin_url( 'admin.php?page=scp-bookings&booking=' . $id . '&scp_msg=saved' ) );
		exit;
	}

	public static function booking_action(): void {
		if ( ! current_user_can( SCP_Install::CAP ) ) {
			wp_die( 'Nu ai acces.' );
		}
		$id = (int) ( $_POST['id'] ?? 0 ); // phpcs:ignore
		check_admin_referer( 'scp_booking_action_' . $id );
		$do   = sanitize_key( wp_unslash( $_POST['do'] ?? '' ) );
		$back = admin_url( 'admin.php?page=scp-bookings&booking=' . $id );
		$b    = SCP_Bookings::get( $id );
		if ( ! $b ) {
			self::fail( 'Programarea nu există.', admin_url( 'admin.php?page=scp-bookings' ) );
		}

		switch ( $do ) {
			case 'payment_link':
				$res = SCP_Mailer::send_payment_link( $b );
				if ( is_wp_error( $res ) ) {
					self::fail( $res->get_error_message(), $back );
				}
				$after = SCP_Bookings::mark_link_sent( $id );
				do_action( 'scp_payment_link_sent', $after );
				$msg = 'link_sent';
				break;
			case 'extend':
				$base = $b['pay_by'] && $b['pay_by'] > SCP_Helpers::mysql_now() ? new DateTimeImmutable( $b['pay_by'], SCP_Helpers::tz() ) : SCP_Helpers::now();
				$res  = SCP_Bookings::update( $id, array( 'pay_by' => $base->modify( '+24 hours' )->format( 'Y-m-d H:i:s' ) ) );
				if ( is_wp_error( $res ) ) {
					self::fail( $res->get_error_message(), $back );
				}
				$msg = 'saved';
				break;
			case 'cash':
			case 'card':
				$res = SCP_Bookings::collect( $id, $do );
				if ( is_wp_error( $res ) ) {
					self::fail( $res->get_error_message(), $back );
				}
				$msg = 'saved';
				break;
			case 'studio':
				$res = SCP_Bookings::move_to_studio( $id );
				if ( is_wp_error( $res ) ) {
					self::fail( $res->get_error_message(), $back );
				}
				$msg = 'saved';
				break;
			case 'resend':
				if ( ! SCP_Mailer::resend_confirmation( $b ) ) {
					self::fail( 'Emailul nu a putut fi trimis.', $back );
				}
				$msg = 'resent';
				break;
			case 'cancel':
				$res = SCP_Bookings::update(
					$id,
					array(
						'status'        => 'cancelled',
						'notify_client' => ! empty( $_POST['notify_client'] ), // phpcs:ignore
					)
				);
				if ( is_wp_error( $res ) ) {
					self::fail( $res->get_error_message(), $back );
				}
				$msg = 'cancelled';
				break;
			default:
				$msg = '';
		}
		wp_safe_redirect( add_query_arg( 'scp_msg', $msg, $back ) );
		exit;
	}

	public static function export_csv(): void {
		if ( ! current_user_can( SCP_Install::CAP ) ) {
			wp_die( 'Nu ai acces.' );
		}
		check_admin_referer( 'scp_export' );
		$f             = self::filters_from_request();
		$f['per_page'] = 1000;
		$f['page']     = 1;

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="programari-craciun-' . SCP_Helpers::now()->format( 'Y-m-d-His' ) . '.csv"' );
		$out = fopen( 'php://output', 'w' );
		fwrite( $out, "\xEF\xBB\xBF" ); // BOM, ca Excel să citească diacriticele.
		// Aceleași coloane ca în Google Sheet.
		fputcsv( $out, SCP_Webhook::sheet_columns(), ';' );
		do {
			$q = SCP_Bookings::query( $f );
			foreach ( $q['items'] as $b ) {
				fputcsv( $out, array_map( array( 'SCP_Webhook', 'cell_safe' ), array_values( SCP_Webhook::sheet_row( $b ) ) ), ';' );
			}
			++$f['page'];
		} while ( count( $q['items'] ) === $f['per_page'] );
		fclose( $out );
		exit;
	}

	/* =========================================================
	 * Calendar
	 * ======================================================= */

	public static function page_calendar(): void {
		$actions = '<a class="button" href="' . esc_url( admin_url( 'admin.php?page=scp-settings#program' ) ) . '">Program standard</a>';
		self::header( 'Calendar și blocări', $actions, 'scp-admin--wide' );
		?>
		<p class="scp-lead">Apasă pe o oră: în dreapta vezi detaliile și ce poți face (mutare, încasare, blocare, programare nouă). Programul standard (zile și ore) se schimbă din Setări.</p>
		<div id="scp-calendar" class="scp-cal-admin">
			<div class="scp-cal-toolbar">
				<button type="button" class="button" data-nav="-1" aria-label="Luna anterioară">‹</button>
				<strong class="scp-cal-title"></strong>
				<button type="button" class="button" data-nav="1" aria-label="Luna următoare">›</button>
				<label class="scp-cal-toggle"><input type="checkbox" data-hide-closed checked> ascunde zilele fără program</label>
				<span class="scp-cal-legend">
					<span class="scp-lg scp-lg--free">liber</span>
					<span class="scp-lg scp-lg--booked">plătit</span>
					<span class="scp-lg scp-lg--studio">plată la studio</span>
					<span class="scp-lg scp-lg--awaiting">așteaptă plata</span>
					<span class="scp-lg scp-lg--gift">gratuit</span>
					<span class="scp-lg scp-lg--blocked">blocat</span>
					<span class="scp-lg scp-lg--past">trecut</span>
				</span>
			</div>
			<div class="scp-cal-layout">
				<div class="scp-cal-body"><p class="scp-empty scp-empty--pad">Se încarcă…</p></div>
				<aside class="scp-cal-side" aria-live="polite"></aside>
				<div class="scp-cal-backdrop"></div>
			</div>
		</div>
		<?php
		self::footer();
	}

	/* =========================================================
	 * Setări
	 * ======================================================= */

	public static function page_settings(): void {
		$s        = SCP_Settings::all();
		$packages = array_values( SCP_Settings::packages() );
		$times    = SCP_Settings::times();
		$grid     = array();
		for ( $h = 7; $h <= 21; $h++ ) {
			$grid[] = sprintf( '%02d:00', $h );
		}
		$other_times = array_diff( $times, $grid );

		self::header( 'Setări programări' );
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="scp-settings">
			<?php wp_nonce_field( 'scp_save_settings' ); ?>
			<input type="hidden" name="action" value="scp_save_settings">

			<nav class="scp-tabs" aria-label="Secțiuni setări">
				<a href="#program">Program</a><a href="#pachete">Pachete</a><a href="#studio">Studio</a><a href="#sms">SMS</a><a href="#emailuri">Emailuri și SMS</a><a href="#plata">Plată și vouchere</a><a href="#webhook">Webhook n8n</a>
			</nav>

			<section class="scp-panel" id="program">
				<h2>Program</h2>
				<table class="form-table" role="presentation">
					<tr><th>Programări online</th><td>
						<label class="scp-switch"><input type="checkbox" name="booking_open" value="1" <?php checked( ! empty( $s['booking_open'] ) ); ?>> <span>Deschise pe site</span></label>
						<p class="description">Când sunt închise, clienții văd mesajul de mai jos în locul calendarului. Din admin poți adăuga programări oricând.</p>
					</td></tr>
					<tr><th><label for="closed_message">Mesaj când sunt închise</label></th><td><input type="text" class="large-text" name="closed_message" id="closed_message" value="<?php echo esc_attr( $s['closed_message'] ); ?>"></td></tr>
					<tr><th>Perioada ședințelor</th><td>
						<label>de la <input type="date" name="start_date" value="<?php echo esc_attr( $s['start_date'] ); ?>"></label>
						<label>până la <input type="date" name="end_date" value="<?php echo esc_attr( $s['end_date'] ); ?>"></label>
					</td></tr>
					<tr><th>Zile lucrătoare</th><td class="scp-checkrow">
						<?php foreach ( SCP_Helpers::WEEKDAYS as $n => $label ) : ?>
							<label><input type="checkbox" name="weekdays[]" value="<?php echo esc_attr( (string) $n ); ?>" <?php checked( in_array( $n, array_map( 'intval', (array) $s['weekdays'] ), true ) ); ?>> <?php echo esc_html( $label ); ?></label>
						<?php endforeach; ?>
					</td></tr>
					<tr><th>Ore de început</th><td>
						<div class="scp-hours">
							<?php foreach ( $grid as $t ) : ?>
								<label><input type="checkbox" name="times[]" value="<?php echo esc_attr( $t ); ?>" <?php checked( in_array( $t, $times, true ) ); ?>><span><?php echo esc_html( $t ); ?></span></label>
							<?php endforeach; ?>
						</div>
						<p><label>Alte ore <input type="text" name="times_other" value="<?php echo esc_attr( implode( ', ', $other_times ) ); ?>" placeholder="ex. 10:30, 16:30" class="regular-text"></label></p>
						<p class="description">Acestea sunt orele din fiecare zi lucrătoare. Pentru o singură zi, blochezi sau adaugi ore din <a href="<?php echo esc_url( admin_url( 'admin.php?page=scp-calendar' ) ); ?>">Calendar</a>.</p>
					</td></tr>
					<tr><th><label for="session_minutes">Durata ședinței</label></th><td><input type="number" min="15" max="240" step="5" name="session_minutes" id="session_minutes" value="<?php echo esc_attr( (string) $s['session_minutes'] ); ?>" class="small-text"> minute <p class="description">Apare în confirmare și în calendarul clientului. Nu schimbă orele de început.</p></td></tr>
					<tr><th><label for="min_notice_hours">Programare cu cel puțin</label></th><td><input type="number" min="0" max="336" name="min_notice_hours" id="min_notice_hours" value="<?php echo esc_attr( (string) $s['min_notice_hours'] ); ?>" class="small-text"> ore înainte <p class="description">0 = se poate rezerva până în ultimul moment. Nu se aplică programărilor adăugate din admin.</p></td></tr>
					<tr><th><label for="target">Obiectiv sezon</label></th><td><input type="number" min="0" name="target" id="target" value="<?php echo esc_attr( (string) $s['target'] ); ?>" class="small-text"> programări</td></tr>
				</table>
			</section>

			<section class="scp-panel" id="pachete">
				<h2>Pachete</h2>
				<p class="description">Câte o caracteristică pe rând. Prețul se copiază în programare în momentul rezervării; dacă îl schimbi aici, programările existente rămân cu prețul lor.</p>
				<div class="scp-packages-edit">
					<?php foreach ( array_merge( $packages, array( array( 'key' => '', 'label' => '', 'price' => '', 'desc' => '' ) ) ) as $i => $p ) : ?>
						<?php if ( '' === $p['key'] ) : ?>
							<button type="button" class="scp-pkg-add" data-pkg-add>+ Adaugă un pachet</button>
						<?php endif; ?>
						<div class="scp-pkg-edit<?php echo '' === $p['key'] ? ' is-new' : ''; ?>"<?php echo '' === $p['key'] ? ' hidden' : ''; ?>>
							<label><span>Nume</span><input type="text" name="packages[<?php echo (int) $i; ?>][label]" value="<?php echo esc_attr( $p['label'] ); ?>" placeholder="<?php echo '' === $p['key'] ? 'Pachet nou (opțional)' : ''; ?>"></label>
							<label><span>Preț (lei)</span><input type="number" min="0" step="1" name="packages[<?php echo (int) $i; ?>][price]" value="<?php echo esc_attr( (string) $p['price'] ); ?>"></label>
							<label class="scp-wide"><span>Ce include</span><textarea rows="3" name="packages[<?php echo (int) $i; ?>][desc]"><?php echo esc_textarea( $p['desc'] ); ?></textarea></label>
							<input type="hidden" name="packages[<?php echo (int) $i; ?>][key]" value="<?php echo esc_attr( $p['key'] ); ?>">
							<?php if ( '' !== $p['key'] ) : ?>
								<label class="scp-inline-check"><input type="checkbox" name="packages[<?php echo (int) $i; ?>][remove]" value="1"> scoate pachetul din formular</label>
							<?php endif; ?>
						</div>
					<?php endforeach; ?>
				</div>
			</section>

			<section class="scp-panel" id="studio">
				<h2>Studio</h2>
				<table class="form-table" role="presentation">
					<tr><th><label for="studio_name">Nume</label></th><td><input type="text" class="regular-text" name="studio_name" id="studio_name" value="<?php echo esc_attr( $s['studio_name'] ); ?>"></td></tr>
					<tr><th><label for="studio_address">Adresă</label></th><td><input type="text" class="large-text" name="studio_address" id="studio_address" value="<?php echo esc_attr( $s['studio_address'] ); ?>"></td></tr>
					<tr><th><label for="studio_phone">Telefon</label></th><td><input type="text" class="regular-text" name="studio_phone" id="studio_phone" value="<?php echo esc_attr( $s['studio_phone'] ); ?>"></td></tr>
					<tr><th><label for="terms_url">Pagina de termeni</label></th><td><input type="text" class="regular-text" name="terms_url" id="terms_url" value="<?php echo esc_attr( $s['terms_url'] ); ?>"></td></tr>
				</table>
			</section>

			<section class="scp-panel" id="sms">
				<h2><?php echo self::channel( 'sms', true ); // phpcs:ignore ?>SMS: trimitere și reamintiri</h2>
				<?php SCP_SMS_Admin::settings_rows(); ?>
				<p class="description">Textele SMS sunt mai jos, la „Emailuri și SMS”. Legătura cu SMSO (expeditorul), testele și jurnalul sunt pe <a href="<?php echo esc_url( admin_url( 'admin.php?page=' . SCP_SMS_Admin::SLUG ) ); ?>">pagina SMS</a>.</p>
			</section>

			<section class="scp-panel" id="emailuri">
				<h2>Emailuri și SMS</h2>
				<p class="description">Emailurile pleacă prin funcția de email a site-ului, deci prin Postmark, așa cum e configurat în FluentSMTP. Lasă expeditorul gol ca să folosești setarea din FluentSMTP.</p>
				<table class="form-table" role="presentation">
					<tr><th><label for="admin_emails">Notificări programări noi</label></th><td><input type="text" class="large-text" name="admin_emails" id="admin_emails" value="<?php echo esc_attr( $s['admin_emails'] ); ?>"><p class="description">Una sau mai multe adrese, separate prin virgulă.</p></td></tr>
					<tr><th><label for="from_name">Nume expeditor</label></th><td><input type="text" class="regular-text" name="from_name" id="from_name" value="<?php echo esc_attr( $s['from_name'] ); ?>"></td></tr>
					<tr><th><label for="from_email">Email expeditor</label></th><td><input type="email" class="regular-text" name="from_email" id="from_email" value="<?php echo esc_attr( $s['from_email'] ); ?>"><p class="description">Trebuie să fie pe un domeniu verificat în Postmark.</p></td></tr>
					<tr><th><label for="reply_to">Răspunsurile clienților la</label></th><td><input type="email" class="regular-text" name="reply_to" id="reply_to" value="<?php echo esc_attr( $s['reply_to'] ); ?>"></td></tr>
				</table>
				<p class="scp-placeholders">În subiect și text poți folosi: <code>{nume}</code> <code>{data}</code> <code>{ziua}</code> <code>{data_lunga}</code> <code>{ora}</code> <code>{pachet}</code> <code>{pret}</code> <code>{cod}</code> <code>{telefon}</code> <code>{email}</code> <code>{mesaj}</code> <code>{studio}</code> <code>{adresa}</code> <code>{telefon_studio}</code> <code>{link_plata}</code> <code>{termen_plata}</code> <code>{pret_intreg}</code> <code>{reducere}</code>. Detaliile ședinței (dată, oră, pachet, adresă, cod) apar automat sub text.</p>
				<?php
				$sms_on   = (bool) SCP_SMS::get( 'enabled' );
				$sms_test = $sms_on && SCP_SMS::get( 'test_mode' );
				$sms_tpls = (array) SCP_SMS::get( 'templates' );
				$sms_lbl  = SCP_SMS_Admin::labels();
				$sample   = SCP_SMS::sample_booking();
				// Mesajele pe perioade, în ordinea în care le primește un client.
				// e = email, s = SMS, b = email de rezervă (pleacă doar dacă SMS-ul nu poate pleca sau nu ajunge).
				$stages = array(
					'La rezervare'       => array(
						array( 'e', 'client_pending', 'Rezervare de pe site, cu link de plată' ),
						array( 's', 'created' ),
						array( 'e', 'client_confirmation', 'Confirmare fără plată online (programări din admin)' ),
						array( 'e', 'client_studio', 'Confirmare cu plata la studio, cash sau card' ),
						array( 's', 'studio' ),
						array( 's', 'voucher' ),
						array( 's', 'free' ),
					),
					'Până la plată'      => array(
						array( 'e', 'payment_reminder', 'Reamintire plată (la orele din „Plată” → Reamintiri pe email)' ),
						array( 's', 'pay_reminder' ),
						array( 'b', 'payment_last_call', 'Ultimul apel la plată' ),
						array( 'e', 'payment_link', 'Link de plată trimis manual (din fișa programării)' ),
						array( 'e', 'expired', 'Rezervare anulată automat (neplătită)' ),
					),
					'La plată'           => array(
						array( 'e', 'payment_received', 'Plată primită, programare confirmată' ),
						array( 's', 'paid' ),
					),
					'Mutare sau anulare' => array(
						array( 'e', 'rescheduled', 'Programare mutată' ),
						array( 's', 'moved' ),
						array( 'e', 'cancelled', 'Programare anulată' ),
					),
					'Înainte de ședință' => array(
						array( 's', 'remind_day' ),
						array( 'b', 'session_reminder_day', 'Reamintire ședință cu o zi înainte' ),
						array( 's', 'remind_hour' ),
						array( 'b', 'session_reminder_hour', 'Reamintire ședință cu o oră înainte' ),
					),
					'Vouchere cadou'     => array(
						array( 'e', 'voucher_issued', 'Voucher cumpărat: codurile către cumpărător' ),
					),
					'Către voi'          => array(
						array( 'e', 'admin_new', 'Alertă programare nouă (la adresele de la „Notificări programări noi”)' ),
					),
				);
				?>
				<div class="scp-ch-legend">
					<span><?php echo self::channel( 'email' ); // phpcs:ignore ?>pleacă pe email</span>
					<span><?php echo self::channel( 'sms' ); // phpcs:ignore ?>pleacă pe SMS</span>
					<span><?php echo self::channel( 'email' ); // phpcs:ignore ?><span class="scp-ch-backup">rezervă</span>email doar dacă SMS-ul nu ajunge</span>
				</div>
				<p class="description scp-ch-note">
					<?php echo esc_html( $sms_on ? ( $sms_test ? 'SMS-urile sunt în Mod test: pleacă doar către numerele de test.' : 'SMS-urile automate sunt pornite.' ) : 'SMS-urile automate sunt oprite.' ); ?>
					Pornirea și reamintirile sunt mai sus, la <a href="#sms">SMS</a>.
					SMS-urile se scriu fără diacritice, sub 160 de caractere, cu variabilele <code>{prenume}</code> <code>{ziua}</code> <code>{data}</code> <code>{ora}</code> <code>{termen}</code> <code>{link}</code> <code>{adresa}</code> <code>{telefon_studio}</code> <code>{cod}</code>.
				</p>
				<input type="hidden" name="sms_tpl_form" value="1">
				<?php foreach ( $stages as $stage => $items ) : ?>
					<div class="scp-ch-divider"><span><?php echo esc_html( $stage ); ?></span></div>
					<?php
					foreach ( $items as $it ) :
						if ( 's' === $it[0] ) :
							$k       = $it[1];
							$tpl     = $sms_tpls[ $k ] ?? array( 'enabled' => 0, 'body' => '' );
							$preview = SCP_SMS::render( (string) $tpl['body'], array_merge( $sample, in_array( $k, array( 'voucher', 'free' ), true ) ? array( 'payment_status' => $k ) : array() ) );
							?>
					<details class="scp-mail scp-mail--sms">
						<summary><?php echo self::channel( 'sms', true ); // phpcs:ignore ?><strong><?php echo esc_html( $sms_lbl[ $k ] ?? $k ); ?></strong> <?php echo empty( $tpl['enabled'] ) ? '<span class="scp-badge">oprit</span>' : ''; ?></summary>
						<div class="scp-smsbox scp-smsbox--phone" data-sms="<?php echo esc_attr( $k ); ?>">
							<div class="scp-smsedit">
								<label class="scp-inline-check"><input type="checkbox" name="sms_tpl[<?php echo esc_attr( $k ); ?>][enabled]" value="1" <?php checked( ! empty( $tpl['enabled'] ) ); ?>> Trimite acest SMS</label>
								<label><span>Text SMS</span><textarea class="large-text scp-smstpl" rows="4" name="sms_tpl[<?php echo esc_attr( $k ); ?>][body]"><?php echo esc_textarea( (string) $tpl['body'] ); ?></textarea></label>
								<p class="scp-smsprev"><span class="scp-smscount"></span> <span class="scp-smsnote"></span></p>
							</div>
							<div class="scp-phone" aria-label="Previzualizare SMS">
								<div class="scp-phone-head"><span class="scp-phone-avatar" aria-hidden="true"><span class="dashicons dashicons-smartphone"></span></span><span><b>SMS de la studio</b><small>Mesaj text · acum</small></span></div>
								<div class="scp-phone-body"><div class="scp-phone-bubble"><?php echo esc_html( $preview ); ?></div></div>
								<p class="scp-phone-foot">Exemplu cu Alexandra, 25.11, ora 17:00</p>
							</div>
						</div>
					</details>
							<?php
						else :
							$key = $it[1];
							$e   = $s['emails'][ $key ];
							?>
					<details class="scp-mail" <?php echo 'client_pending' === $key ? 'open' : ''; ?>>
						<summary><?php echo self::channel( 'email', true ); // phpcs:ignore ?><?php echo 'b' === $it[0] ? '<span class="scp-ch-backup" title="Pleacă doar dacă SMS-ul nu poate pleca sau nu ajunge">rezervă SMS</span>' : ''; ?><strong><?php echo esc_html( $it[2] ); ?></strong> <?php echo empty( $e['enabled'] ) ? '<span class="scp-badge">oprit</span>' : ''; ?></summary>
						<label class="scp-inline-check"><input type="checkbox" name="emails[<?php echo esc_attr( $key ); ?>][enabled]" value="1" <?php checked( ! empty( $e['enabled'] ) ); ?>> Trimite acest email<?php echo 'payment_link' === $key ? ' (se trimite doar când apeși butonul din fișa programării)' : ''; ?></label>
						<label><span>Subiect</span><input type="text" class="large-text" name="emails[<?php echo esc_attr( $key ); ?>][subject]" value="<?php echo esc_attr( $e['subject'] ); ?>"></label>
						<label><span>Text</span><textarea class="large-text" rows="9" name="emails[<?php echo esc_attr( $key ); ?>][body]"><?php echo esc_textarea( $e['body'] ); ?></textarea></label>
					</details>
							<?php
						endif;
					endforeach;
					?>
				<?php endforeach; ?>
				<?php SCP_SMS_Admin::counter_script(); ?>
			</section>

			<section class="scp-panel" id="plata">
				<h2>Plată, reduceri și vouchere</h2>
				<?php
				$variations = self::wc_variation_options();
				$map        = (array) $s['wc_variations'];
				?>
				<table class="form-table" role="presentation">
					<tr><th><label for="payment_url">Pagina de plată</label></th><td>
						<input type="text" class="large-text" name="payment_url" id="payment_url" value="<?php echo esc_attr( $s['payment_url'] ); ?>">
						<p class="description">Adresa pasului de checkout din CartFlows. Păstrează <code>?scp={token}</code> la final: așa pagina de plată recunoaște programarea, pune în coș exact pachetul și reducerea ei și precompletează datele clientului. După plată, programarea devine „Confirmată” și „Plătită online”.</p>
					</td></tr>
					<tr><th>Produsul pentru fiecare pachet</th><td>
						<?php if ( ! $variations ) : ?>
							<p>WooCommerce nu e activ sau nu există produse.</p>
						<?php else : ?>
							<?php foreach ( SCP_Settings::packages() as $k => $p ) : ?>
								<p><label><span class="scp-map-label"><?php echo esc_html( $p['label'] ); ?></span>
									<select name="wc_variations[<?php echo esc_attr( $k ); ?>]">
										<option value="0">— alege produsul —</option>
										<?php foreach ( $variations as $vid => $vlabel ) : ?>
											<option value="<?php echo esc_attr( (string) $vid ); ?>" <?php selected( (int) ( $map[ $k ] ?? 0 ), $vid ); ?>><?php echo esc_html( $vlabel ); ?></option>
										<?php endforeach; ?>
									</select></label></p>
							<?php endforeach; ?>
							<p class="description">Prețul din WooCommerce trebuie să fie același cu cel al pachetului de aici; reducerile se aplică separat, ca reducere pe comandă.</p>
						<?php endif; ?>
					</td></tr>
					<tr><th><label for="pay_hours">Termen de plată</label></th><td>
						<input type="number" min="0" max="336" name="pay_hours" id="pay_hours" value="<?php echo esc_attr( (string) $s['pay_hours'] ); ?>" class="small-text"> ore de la rezervare
						<p class="description">Rezervările de pe site au statusul „Așteaptă plata” și se anulează automat dacă plata nu ajunge în acest termen. 0 = fără termen (rezervările sunt confirmate direct). Dacă ședința are loc înainte de expirarea termenului, rezervarea nu se mai anulează automat: clientul primește linkul de plată și poate plăti online sau la studio.</p>
					</td></tr>
					<tr><th><label for="pay_reminders"><?php echo self::channel( 'email', true ); // phpcs:ignore ?>Reamintiri pe email</label></th><td><input type="text" name="pay_reminders" id="pay_reminders" value="<?php echo esc_attr( (string) $s['pay_reminders'] ); ?>" class="regular-text" placeholder="24, 12, 3"> ore înainte de expirare <p class="description">Câte un email la fiecare interval, de exemplu „24, 12, 3”. Noaptea (22:00–08:00) nu pleacă: se trimite la 08:00 sau la 21:30, cu o seară înainte. Gol = fără reamintiri.</p></td></tr>
					<tr><th><label for="last_call_minutes"><?php echo self::channel( 'sms', true ); // phpcs:ignore ?>Ultimul apel pe SMS</label></th><td><input type="number" min="0" max="180" name="last_call_minutes" id="last_call_minutes" value="<?php echo esc_attr( (string) $s['last_call_minutes'] ); ?>" class="small-text"> minute înainte de expirare <p class="description">SMS: „plătește până la …, ca ora să nu ajungă la altă familie”. Emailul „Ultimul apel” pleacă doar dacă SMS-ul nu poate pleca sau nu ajunge. Noaptea (22:00–08:00) nu pleacă: se trimite la 08:00 sau la 21:30, cu o seară înainte. 0 = fără.</p></td></tr>
					<tr><th>Reducere de început de sezon</th><td>
						<p><label><input type="checkbox" name="early_enabled" value="1" <?php checked( ! empty( $s['early_enabled'] ) ); ?>> Activă</label></p>
						<p><label><input type="number" min="0" step="1" name="early_amount" value="<?php echo esc_attr( (string) $s['early_amount'] ); ?>" class="small-text"> lei reducere</label>
						pentru rezervările făcute până pe <input type="date" name="early_until" value="<?php echo esc_attr( $s['early_until'] ); ?>"> inclusiv, la pachetele:</p>
						<p class="scp-checkrow">
							<?php foreach ( SCP_Settings::packages() as $k => $p ) : ?>
								<label><input type="checkbox" name="early_packages[]" value="<?php echo esc_attr( $k ); ?>" <?php checked( in_array( $k, (array) $s['early_packages'], true ) ); ?>> <?php echo esc_html( $p['label'] ); ?></label>
							<?php endforeach; ?>
						</p>
						<p><label>Textul afișat clienților <input type="text" class="large-text" name="early_label" value="<?php echo esc_attr( $s['early_label'] ); ?>"></label></p>
						<p><label>Cuponul WooCommerce folosit <input type="text" class="regular-text scp-mono" name="early_coupon" value="<?php echo esc_attr( $s['early_coupon'] ); ?>"></label></p>
						<p class="description">La salvare, pluginul creează sau actualizează cuponul cu suma și pachetele de mai sus. Cuponul se aplică automat doar programărilor care au dreptul la reducere; dacă cineva îl scrie manual pe pagina de plată, nu e acceptat. Prețul redus rămâne valabil cât timp programarea e plătită în termen, chiar dacă plata vine după data limită.</p>
					</td></tr>
					<tr><th>Vouchere cadou</th><td>
						<label><input type="checkbox" name="vouchers_enabled" value="1" <?php checked( ! empty( $s['vouchers_enabled'] ) ); ?>> Clienții pot folosi un voucher cadou în formular</label>
						<p class="description">Voucherul se cumpără ca produs WooCommerce. După plata comenzii, pluginul generează câte un cod pentru fiecare voucher cumpărat și îl trimite cumpărătorului pe email. Codul acoperă integral ședința, doar pentru pachetul cumpărat, o singură dată, până la finalul sezonului.</p>
						<?php if ( $variations ) : ?>
							<?php $vmap = (array) $s['voucher_products']; ?>
							<?php foreach ( SCP_Settings::packages() as $k => $p ) : ?>
								<p><label><span class="scp-map-label">Voucher <?php echo esc_html( $p['label'] ); ?></span>
									<select name="voucher_products[<?php echo esc_attr( $k ); ?>]">
										<option value="0">— alege produsul voucher —</option>
										<?php foreach ( $variations as $vid => $vlabel ) : ?>
											<option value="<?php echo esc_attr( (string) $vid ); ?>" <?php selected( (int) ( $vmap[ $k ] ?? 0 ), $vid ); ?>><?php echo esc_html( $vlabel ); ?></option>
										<?php endforeach; ?>
									</select></label></p>
							<?php endforeach; ?>
							<p><label><span class="scp-map-label">Pagina de cumpărare</span> <input type="text" class="regular-text" name="voucher_url" value="<?php echo esc_attr( (string) ( $s['voucher_url'] ?? '' ) ); ?>"></label></p>
							<p class="description">Pasul de checkout CartFlows pentru vouchere. Linkurile <code><?php echo esc_html( strtok( (string) ( $s['voucher_url'] ?? '' ), '?' ) ); ?>?pachet=foto</code> și <code>?pachet=foto_plus</code> pun direct în coș voucherul pachetului respectiv, fără să mai treacă prin pagina produsului.</p>
							<p><label><span class="scp-map-label">Începutul codului</span> <input type="text" class="small-text scp-mono" style="width:9em" maxlength="10" name="voucher_prefix" value="<?php echo esc_attr( (string) ( $s['voucher_prefix'] ?? 'CHELMU' ) ); ?>"></label></p>
							<p class="description">Codurile arată așa: <code><?php echo esc_html( ( $s['voucher_prefix'] ?? 'CHELMU' ) ?: 'CHELMU' ); ?>-BRAD-K7MP9Q</code>, cu un cuvânt de Crăciun ales la întâmplare (<?php echo esc_html( strtolower( implode( ', ', SCP_Vouchers::CODE_WORDS ) ) ); ?>) și 6 caractere unice. În formular se pot scrie și cu litere mici sau fără cratime.</p>
							<?php if ( ! array_filter( array_map( 'intval', $vmap ) ) ) : ?>
								<p><label><input type="checkbox" name="create_voucher_product" value="1"> Nu ai încă un produs voucher? Bifează și salvează: pluginul creează produsul „Voucher ședință foto de Crăciun”, cu câte o variantă pentru fiecare pachet, la prețul întreg, și îl leagă aici.</label></p>
							<?php endif; ?>
						<?php endif; ?>
					</td></tr>
				</table>
			</section>

			<section class="scp-panel" id="webhook">
				<h2>Webhook n8n</h2>
				<table class="form-table" role="presentation">
					<tr><th><label for="webhook_url">Adresa webhook</label></th><td><input type="url" class="large-text" name="webhook_url" id="webhook_url" value="<?php echo esc_attr( $s['webhook_url'] ); ?>" placeholder="https://n8n.exemplu.ro/webhook/..."></td></tr>
					<tr><th><label for="webhook_secret">Cheie secretă</label></th><td>
						<div class="scp-copy"><input type="password" autocomplete="off" name="webhook_secret" id="webhook_secret" value="<?php echo esc_attr( $s['webhook_secret'] ); ?>" class="regular-text scp-mono"><button type="button" class="button" data-reveal="#webhook_secret">Arată</button><button type="button" class="button" data-copy="#webhook_secret">Copiază</button><button type="button" class="button" data-regenerate="#webhook_secret">Generează alta</button></div>
						<p class="description">Cu ea site-ul scrie în n8n (antetul <code>X-SCP-Key</code>, pentru Header Auth). Fiecare trimitere are și <code>X-SCP-Signature</code> = HMAC-SHA256(cheie, <code>X-SCP-Timestamp</code> + "." + corpul cererii).</p>
					</td></tr>
					<tr><th><label for="sheet_key">Cheie de citire</label></th><td>
						<div class="scp-copy"><input type="password" autocomplete="off" name="sheet_key" id="sheet_key" value="<?php echo esc_attr( $s['sheet_key'] ); ?>" class="regular-text scp-mono"><button type="button" class="button" data-reveal="#sheet_key">Arată</button><button type="button" class="button" data-copy="#sheet_key">Copiază</button><button type="button" class="button" data-regenerate="#sheet_key">Generează alta</button></div>
						<p class="description">Doar pentru verificarea de noapte din n8n: <code>GET /wp-json/scp/v1/sheet</code> cu antetul <code>X-SCP-Key</code> = această cheie întoarce toate programările. Ține-o doar în credențiala acelui nod.</p>
					</td></tr>
					<tr><th>Evenimente</th><td class="scp-checkcol">
						<?php
						$events = array(
							'booking.created'           => 'Programare nouă',
							'booking.updated'           => 'Programare modificată (dată, status, plată, observații)',
							'booking.cancelled'         => 'Programare anulată',
							'booking.payment_link_sent' => 'Link de plată trimis',
							'booking.paid'              => 'Plată înregistrată',
							'voucher.issued'            => 'Voucher cadou emis (după plata comenzii)',
						);
						foreach ( $events as $k => $l ) :
							?>
							<label><input type="checkbox" name="webhook_events[]" value="<?php echo esc_attr( $k ); ?>" <?php checked( in_array( $k, (array) $s['webhook_events'], true ) ); ?>> <?php echo esc_html( $l ); ?> <code><?php echo esc_html( $k ); ?></code></label>
						<?php endforeach; ?>
					</td></tr>
					<tr><th>Test</th><td><button type="button" class="button" id="scp-webhook-test">Trimite un eveniment de test</button> <span id="scp-webhook-result" aria-live="polite"></span><p class="description">Salvează setările înainte de test. Evenimentul de test are <code>"event": "test"</code> și codul <code>TEST00</code>, ca să-l poți filtra în n8n.</p></td></tr>
					<tr><th>Măsurare</th><td><label><input type="checkbox" name="track_fbq" value="1" <?php checked( ! empty( $s['track_fbq'] ) ); ?>> Trimite și evenimentul Meta Pixel <code>Schedule</code> la programare</label><p class="description">Evenimentul <code>scp_booking</code> ajunge mereu în dataLayer, pentru GTM. Bifează doar dacă nu trimiți deja conversia către Meta din GTM, ca să nu se numere de două ori.</p></td></tr>
				</table>
			</section>

			<div class="scp-sticky-submit"><button class="button button-primary button-large">Salvează setările</button><span class="scp-dirty" hidden>Ai modificări nesalvate</span></div>
		</form>
		<?php
		self::footer();
	}

	public static function save_settings(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Nu ai acces.' );
		}
		check_admin_referer( 'scp_save_settings' );
		$p    = wp_unslash( $_POST );
		$back = admin_url( 'admin.php?page=scp-settings' );

		$start = sanitize_text_field( $p['start_date'] ?? '' );
		$end   = sanitize_text_field( $p['end_date'] ?? '' );
		if ( ! SCP_Helpers::is_date( $start ) || ! SCP_Helpers::is_date( $end ) || $end < $start ) {
			self::fail( 'Perioada nu e validă: data de final trebuie să fie după data de început.', $back );
		}

		$times = array_filter( (array) ( $p['times'] ?? array() ), array( 'SCP_Helpers', 'is_time' ) );
		foreach ( preg_split( '/[\s,;]+/', (string) ( $p['times_other'] ?? '' ) ) as $t ) {
			$t = trim( $t );
			if ( preg_match( '/^(\d{1,2})[:.](\d{2})$/', $t, $m ) ) {
				$t = sprintf( '%02d:%02d', (int) $m[1], (int) $m[2] );
			}
			if ( SCP_Helpers::is_time( $t ) ) {
				$times[] = $t;
			}
		}
		$times = array_values( array_unique( $times ) );
		sort( $times );

		$packages = array();
		$used     = array();
		foreach ( (array) ( $p['packages'] ?? array() ) as $row ) {
			$label = sanitize_text_field( $row['label'] ?? '' );
			if ( '' === $label || ! empty( $row['remove'] ) ) {
				continue;
			}
			$key = sanitize_key( $row['key'] ?? '' );
			if ( '' === $key ) {
				$key = sanitize_key( str_replace( '-', '_', sanitize_title( remove_accents( $label ) ) ) ) ?: 'pachet';
			}
			$base = $key;
			$n    = 2;
			while ( isset( $used[ $key ] ) ) {
				$key = $base . '_' . $n++;
			}
			$used[ $key ] = true;
			$packages[]   = array(
				'key'   => $key,
				'label' => $label,
				'price' => max( 0, (float) ( $row['price'] ?? 0 ) ),
				'desc'  => sanitize_textarea_field( $row['desc'] ?? '' ),
			);
		}
		if ( ! $packages ) {
			self::fail( 'Formularul are nevoie de cel puțin un pachet.', $back . '#pachete' );
		}

		$emails = array();
		$old_emails = (array) SCP_Settings::get( 'emails', array() );
		foreach ( SCP_Settings::default_emails() as $key => $def ) {
			if ( ! isset( $p['emails'][ $key ] ) ) {
				// Nu e în formular: rămâne cum era (altfel s-ar opri fără să știe nimeni).
				$emails[ $key ] = $old_emails[ $key ] ?? $def;
				continue;
			}
			$in             = (array) $p['emails'][ $key ];
			$emails[ $key ] = array(
				'enabled' => empty( $in['enabled'] ) ? 0 : 1,
				'subject' => sanitize_text_field( $in['subject'] ?? $def['subject'] ),
				'body'    => sanitize_textarea_field( $in['body'] ?? $def['body'] ),
			);
		}

		$allowed_events = array( 'booking.created', 'booking.updated', 'booking.cancelled', 'booking.payment_link_sent', 'booking.paid', 'voucher.issued' );
		$secret         = sanitize_text_field( $p['webhook_secret'] ?? '' );
		$sheet_key      = sanitize_text_field( $p['sheet_key'] ?? '' );
		$hook_url       = esc_url_raw( trim( (string) ( $p['webhook_url'] ?? '' ) ), array( 'https' ) );

		SCP_Settings::update(
			array(
				'booking_open'     => empty( $p['booking_open'] ) ? 0 : 1,
				'closed_message'   => sanitize_text_field( $p['closed_message'] ?? '' ),
				'start_date'       => $start,
				'end_date'         => $end,
				'weekdays'         => array_values( array_intersect( array( 1, 2, 3, 4, 5, 6, 7 ), array_map( 'intval', (array) ( $p['weekdays'] ?? array() ) ) ) ),
				'times'            => $times,
				'session_minutes'  => max( 15, min( 240, (int) ( $p['session_minutes'] ?? 60 ) ) ),
				'min_notice_hours' => max( 0, min( 336, (int) ( $p['min_notice_hours'] ?? 0 ) ) ),
				'target'           => max( 0, (int) ( $p['target'] ?? 0 ) ),
				'packages'         => $packages,
				'studio_name'      => sanitize_text_field( $p['studio_name'] ?? '' ),
				'studio_address'   => sanitize_text_field( $p['studio_address'] ?? '' ),
				'studio_phone'     => sanitize_text_field( $p['studio_phone'] ?? '' ),
				'terms_url'        => sanitize_text_field( $p['terms_url'] ?? '' ),
				'admin_emails'     => implode( ', ', array_filter( array_map( 'sanitize_email', preg_split( '/[\s,;]+/', (string) ( $p['admin_emails'] ?? '' ) ) ), 'is_email' ) ),
				'from_name'        => sanitize_text_field( $p['from_name'] ?? '' ),
				'from_email'       => sanitize_email( $p['from_email'] ?? '' ),
				'reply_to'         => sanitize_email( $p['reply_to'] ?? '' ),
				'payment_url'      => sanitize_text_field( $p['payment_url'] ?? '' ),
				'webhook_url'      => $hook_url, // doar https: cheia circulă în antet
				'webhook_secret'   => strlen( $secret ) >= 16 ? $secret : wp_generate_password( 40, false, false ),
				'sheet_key'        => strlen( $sheet_key ) >= 16 && $sheet_key !== $secret ? $sheet_key : wp_generate_password( 40, false, false ),
				'webhook_events'   => array_values( array_intersect( $allowed_events, (array) ( $p['webhook_events'] ?? array() ) ) ),
				'track_fbq'        => empty( $p['track_fbq'] ) ? 0 : 1,
				'pay_hours'                => max( 0, min( 336, (int) ( $p['pay_hours'] ?? 48 ) ) ),
				'pay_reminders'            => implode( ', ', array_slice( array_values( array_unique( array_filter( array_map( 'intval', preg_split( '/[^0-9]+/', (string) ( $p['pay_reminders'] ?? '' ) ) ), static fn( $h ) => $h > 0 && $h <= 336 ) ) ), 0, 6 ) ),
				'last_call_minutes'        => max( 0, min( 180, (int) ( $p['last_call_minutes'] ?? 30 ) ) ),
				'early_enabled'            => empty( $p['early_enabled'] ) ? 0 : 1,
				'early_until'              => SCP_Helpers::is_date( (string) ( $p['early_until'] ?? '' ) ) ? $p['early_until'] : '',
				'early_amount'             => max( 0, (float) ( $p['early_amount'] ?? 0 ) ),
				'early_packages'           => array_values( array_intersect( array_column( $packages, 'key' ), (array) ( $p['early_packages'] ?? array() ) ) ),
				'early_label'              => sanitize_text_field( $p['early_label'] ?? '' ),
				'early_coupon'             => sanitize_text_field( $p['early_coupon'] ?? '' ),
				'vouchers_enabled'         => empty( $p['vouchers_enabled'] ) ? 0 : 1,
				'voucher_url'              => sanitize_text_field( $p['voucher_url'] ?? '' ),
				'voucher_prefix'           => substr( strtoupper( preg_replace( '/[^A-Za-z0-9]/', '', (string) ( $p['voucher_prefix'] ?? '' ) ) ), 0, 10 ) ?: 'CHELMU',
				'voucher_products'         => array_map( 'intval', array_intersect_key( (array) ( $p['voucher_products'] ?? array() ), array_flip( array_column( $packages, 'key' ) ) ) ),
				'wc_variations'            => array_map( 'intval', array_intersect_key( (array) ( $p['wc_variations'] ?? array() ), array_flip( array_column( $packages, 'key' ) ) ) ),
				'emails'           => $emails,
			)
		);

		// Textele SMS se editează tot aici, în „Emailuri și SMS”; restul setărilor SMS rămân pe pagina SMS.
		if ( ! empty( $p['sms_tpl_form'] ) && ! empty( $p['sms_form'] ) ) {
			SCP_SMS_Admin::save_from_settings( (array) ( $p['sms'] ?? array() ), (array) ( $p['sms_tpl'] ?? array() ) );
		}

		if ( ! empty( $p['create_voucher_product'] ) ) {
			$made = self::create_voucher_product();
			if ( is_string( $made ) ) {
				self::fail( 'Setările au fost salvate, dar produsul voucher nu a putut fi creat: ' . $made, $back . '#plata' );
			}
		}
		$err = SCP_Pricing::sync_early_coupon();
		if ( $err ) {
			self::fail( 'Setările au fost salvate, dar cuponul de reducere nu a putut fi actualizat: ' . $err, $back . '#plata' );
		}
		wp_safe_redirect( add_query_arg( 'scp_msg', 'settings', $back ) );
		exit;
	}

	/** Creează produsul variabil pentru vouchere și îl leagă de pachete. */
	public static function create_voucher_product() {
		if ( ! class_exists( 'WC_Product_Variable' ) ) {
			return 'WooCommerce nu e activ.';
		}
		$packages = SCP_Settings::packages();
		$attr     = new WC_Product_Attribute();
		$attr->set_name( 'Pachet' );
		$attr->set_options( array_values( array_column( $packages, 'label' ) ) );
		$attr->set_visible( true );
		$attr->set_variation( true );

		$product = new WC_Product_Variable();
		$product->set_name( 'Voucher ședință foto de Crăciun ' . substr( (string) SCP_Settings::get( 'end_date' ), 0, 4 ) );
		$product->set_status( 'publish' );
		$product->set_catalog_visibility( 'visible' );
		$product->set_virtual( true );
		$product->set_sold_individually( false );
		$product->set_short_description( 'Un cadou pentru cei dragi: o ședință foto de Crăciun în studio. După plată primești pe email codul voucherului, pe care îl oferi mai departe; cel care îl primește își alege singur ziua și ora.' );
		$product->set_attributes( array( $attr ) );
		$pid = $product->save();

		$map = array();
		foreach ( $packages as $k => $p ) {
			$v = new WC_Product_Variation();
			$v->set_parent_id( $pid );
			$v->set_attributes( array( 'pachet' => $p['label'] ) );
			$v->set_regular_price( (string) $p['price'] );
			$v->set_virtual( true );
			$v->set_status( 'publish' );
			$map[ $k ] = $v->save();
		}
		WC_Product_Variable::sync( $pid );
		SCP_Settings::update( array( 'voucher_products' => $map ) );
		return $pid;
	}

	/** Variațiile și produsele simple din WooCommerce, pentru legarea pachetelor. */
	private static function wc_variation_options(): array {
		if ( ! function_exists( 'wc_get_products' ) ) {
			return array();
		}
		$out = array();
		foreach ( wc_get_products( array( 'limit' => 200, 'status' => 'publish', 'type' => array( 'variable', 'simple' ), 'orderby' => 'date', 'order' => 'DESC' ) ) as $prod ) {
			if ( $prod->is_type( 'variable' ) ) {
				foreach ( $prod->get_children() as $cid ) {
					$v = wc_get_product( $cid );
					if ( $v ) {
						$out[ $cid ] = $v->get_name() . ' – ' . wp_strip_all_tags( wc_price( (float) $v->get_regular_price() ) ) . ' (#' . $cid . ')';
					}
				}
			} elseif ( $prod->is_virtual() ) {
				$out[ $prod->get_id() ] = $prod->get_name() . ' – ' . wp_strip_all_tags( wc_price( (float) $prod->get_regular_price() ) ) . ' (#' . $prod->get_id() . ')';
			}
		}
		return $out;
	}

	/* =========================================================
	 * Vouchere
	 * ======================================================= */

	public static function page_vouchers(): void {
		$g      = wp_unslash( $_GET ); // phpcs:ignore
		$status = isset( $g['status'] ) ? sanitize_key( $g['status'] ) : '';
		$search = isset( $g['s'] ) ? sanitize_text_field( $g['s'] ) : '';
		$rows   = SCP_Vouchers::query( array( 'status' => $status, 'search' => $search ) );
		$counts = SCP_Vouchers::counts();
		$pk     = SCP_Settings::packages();
		self::header( 'Vouchere cadou' );
		if ( ! array_filter( (array) SCP_Settings::get( 'voucher_products' ) ) ) {
			echo '<div class="notice notice-info inline"><p>Produsul voucher nu e legat încă. <a href="' . esc_url( admin_url( 'admin.php?page=scp-settings#plata' ) ) . '">Leagă-l sau creează-l din Setări</a>.</p></div>';
		}
		?>
		<div class="scp-kpis scp-kpis--3">
			<div class="scp-kpi"><div class="scp-kpi-label">Valabile</div><div class="scp-kpi-value"><?php echo esc_html( (string) $counts['active'] ); ?></div></div>
			<div class="scp-kpi"><div class="scp-kpi-label">Folosite</div><div class="scp-kpi-value"><?php echo esc_html( (string) $counts['used'] ); ?></div></div>
			<div class="scp-kpi"><div class="scp-kpi-label">Anulate</div><div class="scp-kpi-value"><?php echo esc_html( (string) $counts['cancelled'] ); ?></div></div>
		</div>
		<div class="scp-grid-2 scp-grid-2--wide">
			<div>
				<form class="scp-filters" method="get">
					<input type="hidden" name="page" value="scp-vouchers">
					<label><span>Caută</span><input type="search" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="cod, nume, email, telefon"></label>
					<label><span>Status</span><select name="status"><option value="">Toate</option>
						<?php foreach ( SCP_Vouchers::STATUSES as $k => $l ) : ?>
							<option value="<?php echo esc_attr( $k ); ?>" <?php selected( $status, $k ); ?>><?php echo esc_html( $l ); ?></option>
						<?php endforeach; ?></select></label>
					<button class="button">Filtrează</button>
				</form>
				<div class="scp-panel scp-panel--flush">
					<?php if ( ! $rows ) : ?>
						<p class="scp-empty scp-empty--pad">Niciun voucher încă.</p>
					<?php else : ?>
						<table class="scp-table"><thead><tr><th>Cod</th><th>Pachet</th><th>Cumpărător</th><th>Pentru</th><th>Status</th><th>Valabil până</th><th></th></tr></thead><tbody>
						<?php foreach ( $rows as $v ) : ?>
							<tr>
								<td><strong class="scp-mono"><?php echo esc_html( $v['code'] ); ?></strong><small><?php echo esc_html( ( new DateTimeImmutable( $v['created_at'], SCP_Helpers::tz() ) )->format( 'd.m.Y' ) . ( $v['note'] ? ' · ' . $v['note'] : '' ) ); ?></small></td>
								<td><?php echo esc_html( $pk[ $v['package'] ]['label'] ?? $v['package'] ); ?><small><?php echo esc_html( SCP_Helpers::money( $v['value'] ) ); ?> lei</small></td>
								<td><?php echo esc_html( $v['buyer_name'] ?: '–' ); ?><small><?php echo esc_html( $v['buyer_email'] ); ?><?php if ( $v['buyer_phone'] ) : ?> · <a href="tel:<?php echo esc_attr( preg_replace( '/[^0-9+]/', '', $v['buyer_phone'] ) ); ?>"><?php echo esc_html( $v['buyer_phone'] ); ?></a><?php endif; ?><?php if ( $v['order_id'] ) : ?> · <a href="<?php echo esc_url( admin_url( 'post.php?post=' . (int) $v['order_id'] . '&action=edit' ) ); ?>">comanda #<?php echo (int) $v['order_id']; ?></a><?php endif; ?></small></td>
								<td><?php echo esc_html( $v['recipient_name'] ?: '–' ); ?><?php if ( $v['recipient_email'] || $v['recipient_phone'] ) : ?><small><?php echo esc_html( $v['recipient_email'] ); ?><?php if ( $v['recipient_email'] && $v['recipient_phone'] ) : ?> · <?php endif; ?><?php if ( $v['recipient_phone'] ) : ?><a href="tel:<?php echo esc_attr( preg_replace( '/[^0-9+]/', '', $v['recipient_phone'] ) ); ?>"><?php echo esc_html( $v['recipient_phone'] ); ?></a><?php endif; ?></small><?php endif; ?></td>
								<td><span class="scp-badge scp-badge--voucher-<?php echo esc_attr( $v['status'] ); ?>"><?php echo esc_html( SCP_Vouchers::STATUSES[ $v['status'] ] ?? $v['status'] ); ?></span>
									<?php if ( $v['booking_id'] ) : ?><small><a href="<?php echo esc_url( admin_url( 'admin.php?page=scp-bookings&booking=' . (int) $v['booking_id'] ) ); ?>">vezi programarea</a></small><?php endif; ?></td>
								<td><?php echo esc_html( $v['expires_on'] ? SCP_Helpers::date_ro( $v['expires_on'] ) : '–' ); ?></td>
								<td class="scp-nowrap">
									<?php if ( 'used' !== $v['status'] ) : ?>
										<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="scp-inline-form">
											<?php wp_nonce_field( 'scp_voucher' ); ?>
											<input type="hidden" name="action" value="scp_voucher"><input type="hidden" name="id" value="<?php echo (int) $v['id']; ?>">
											<input type="hidden" name="do" value="<?php echo 'active' === $v['status'] ? 'cancel' : 'activate'; ?>">
											<?php if ( 'active' === $v['status'] && $v['buyer_email'] ) : ?>
												<button class="button button-small" name="do" value="resend">Retrimite emailul</button>
											<?php endif; ?>
											<button class="button button-small"><?php echo 'active' === $v['status'] ? 'Anulează' : 'Reactivează'; ?></button>
										</form>
									<?php endif; ?>
								</td>
							</tr>
						<?php endforeach; ?>
						</tbody></table>
					<?php endif; ?>
				</div>
			</div>
			<form class="scp-panel" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'scp_voucher' ); ?>
				<input type="hidden" name="action" value="scp_voucher"><input type="hidden" name="do" value="create">
				<h2>Voucher emis manual</h2>
				<p class="description">Pentru vouchere vândute la studio sau oferite de voi. Cele cumpărate online se emit singure.</p>
				<div class="scp-form-grid scp-form-grid--1">
					<label><span>Pachet</span><select name="package"><?php foreach ( $pk as $k => $p ) : ?><option value="<?php echo esc_attr( $k ); ?>"><?php echo esc_html( $p['label'] . ' – ' . SCP_Helpers::money( $p['price'] ) . ' lei' ); ?></option><?php endforeach; ?></select></label>
					<label><span>Cumpărător</span><input type="text" name="buyer_name"></label>
					<label><span>Email cumpărător</span><input type="email" name="buyer_email"><em>Dacă îl completezi, primește codul pe email.</em></label>
					<label><span>Telefon cumpărător</span><input type="tel" name="buyer_phone"></label>
					<label><span>Pentru cine e (opțional)</span><input type="text" name="recipient_name"><em>Numele apare pe voucher, în email.</em></label>
					<label><span>Email destinatar (opțional)</span><input type="email" name="recipient_email"></label>
					<label><span>Telefon destinatar (opțional)</span><input type="tel" name="recipient_phone"></label>
					<label><span>Notă internă</span><input type="text" name="note" placeholder="ex. plătit cash la studio"></label>
					<label><span>Valabil până</span><input type="date" name="expires_on" value="<?php echo esc_attr( (string) SCP_Settings::get( 'end_date' ) ); ?>"></label>
				</div>
				<p><button class="button button-primary">Emite voucherul</button></p>
			</form>
		</div>
		<?php
		self::footer();
	}

	public static function voucher_action(): void {
		if ( ! current_user_can( SCP_Install::CAP ) ) {
			wp_die( 'Nu ai acces.' );
		}
		check_admin_referer( 'scp_voucher' );
		$p    = wp_unslash( $_POST );
		$back = admin_url( 'admin.php?page=scp-vouchers' );
		$do   = sanitize_key( $p['do'] ?? '' );
		if ( 'create' === $do ) {
			$v = SCP_Vouchers::create( array_intersect_key( $p, array_flip( array( 'package', 'buyer_name', 'buyer_email', 'buyer_phone', 'recipient_name', 'recipient_email', 'recipient_phone', 'note', 'expires_on' ) ) ) );
			if ( ! $v ) {
				self::fail( 'Voucherul nu a putut fi creat.', $back );
			}
			if ( $v['buyer_email'] ) {
				SCP_Mailer::send_vouchers( array( $v ), $v['buyer_email'], $v['buyer_name'] );
			}
			wp_safe_redirect( add_query_arg( array( 'scp_msg' => 'saved', 's' => $v['code'] ), $back ) );
			exit;
		}
		$id = (int) ( $p['id'] ?? 0 );
		$v  = SCP_Vouchers::get( $id );
		if ( ! $v ) {
			self::fail( 'Voucherul nu există.', $back );
		}
		if ( 'resend' === $do ) {
			SCP_Mailer::send_vouchers( array( $v ), $v['buyer_email'], $v['buyer_name'] );
		} elseif ( 'cancel' === $do || 'activate' === $do ) {
			SCP_Vouchers::set_status( $id, 'cancel' === $do ? 'cancelled' : 'active' );
		}
		wp_safe_redirect( add_query_arg( 'scp_msg', 'saved', $back ) );
		exit;
	}

	/* =========================================================
	 * Jurnal webhook
	 * ======================================================= */

	public static function resync_action(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Nu ai acces.' );
		}
		check_admin_referer( 'scp_resync' );
		$n = SCP_Webhook::resync_all();
		set_transient( 'scp_resync_msg_' . get_current_user_id(), $n, 120 );
		wp_safe_redirect( admin_url( 'admin.php?page=scp-webhook' ) );
		exit;
	}

	public static function page_webhook(): void {
		self::header( 'Sincronizare Google Sheet' );
		global $wpdb;
		$t      = SCP_Install::table( 'webhook_log' );
		$url    = (string) SCP_Settings::get( 'webhook_url' );
		$needed = array( 'booking.created', 'booking.updated', 'booking.cancelled', 'booking.paid' );
		$off    = array_diff( $needed, (array) SCP_Settings::get( 'webhook_events', array() ) );
		$last   = $wpdb->get_var( "SELECT MAX(delivered_at) FROM {$t} WHERE status = 'sent'" ); // phpcs:ignore
		$queued = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$t} WHERE status = 'pending'" ); // phpcs:ignore
		$failed = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$t} WHERE status = 'failed' AND last_error NOT LIKE %s AND created_at > %s", 'Înlocuit%', SCP_Helpers::now()->modify( '-7 days' )->format( 'Y-m-d H:i:s' ) ) ); // phpcs:ignore
		$msg    = get_transient( 'scp_resync_msg_' . get_current_user_id() );
		if ( false !== $msg ) {
			delete_transient( 'scp_resync_msg_' . get_current_user_id() );
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( (int) $msg . ' programări puse la coadă. Pleacă în fundal, una câte una (câteva secunde fiecare); reîncarcă pagina ca să vezi progresul.' ) . '</p></div>';
		}
		?>
		<p class="scp-lead">Fiecare programare are un rând în Sheet, găsit după <strong>Cod</strong>. Rândul se actualizează la orice schimbare: plată, mutare, anulare, expirare, date de contact, observații. O programare anulată rămâne în Sheet cu statusul „Anulată”, iar cea nouă de pe aceeași oră apare pe alt rând, cu alt cod.</p>

		<div class="scp-sync-grid">
			<div class="scp-panel">
				<h2>Stare</h2>
				<dl class="scp-sync-dl">
					<dt>Adresa n8n</dt><dd><?php echo $url ? '<code>' . esc_html( $url ) . '</code>' : '<strong class="scp-err">neconfigurată</strong> · <a href="' . esc_url( admin_url( 'admin.php?page=scp-settings#webhook' ) ) . '">Setări → Webhook</a>'; // phpcs:ignore ?></dd>
					<dt>Ultima trimitere reușită</dt><dd><?php echo esc_html( $last ? ( new DateTimeImmutable( $last, SCP_Helpers::tz() ) )->format( 'd.m.Y H:i' ) : '–' ); ?></dd>
					<dt>În așteptare</dt><dd><?php echo esc_html( (string) $queued ); ?></dd>
					<dt>Eșuate (7 zile)</dt><dd><?php echo $failed ? '<strong class="scp-err">' . esc_html( (string) $failed ) . '</strong> · repară cu butonul de alături' : '0'; // phpcs:ignore ?></dd>
				</dl>
				<?php if ( $off ) : ?>
					<p class="scp-err">Evenimente oprite din Setări: <code><?php echo esc_html( implode( ', ', $off ) ); ?></code>. Fără ele, Sheet-ul nu mai prinde toate schimbările.</p>
				<?php endif; ?>
			</div>
			<div class="scp-panel">
				<h2>Retrimite tot</h2>
				<p>Trimite din nou toate programările, inclusiv cele anulate. Folosește-l o dată, la pornire, ca să umpli Sheet-ul, și oricând crezi că ceva lipsește. Nu dublează rânduri.</p>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<?php wp_nonce_field( 'scp_resync' ); ?>
					<input type="hidden" name="action" value="scp_resync">
					<button class="button button-primary" <?php disabled( ! $url ); ?>>Trimite toate programările în Sheet</button>
				</form>
				<details class="scp-sync-more">
					<summary>Verificarea de noapte (n8n)</summary>
					<p>Ca plasă de siguranță, n8n poate citi o dată pe noapte lista completă și rescrie tab-ul:</p>
					<p><code>GET <?php echo esc_html( rest_url( SCP_Rest::NS . '/sheet' ) ); ?></code><br>cu antetul <code>X-SCP-Key</code> = cheia secretă din Setări → Webhook.</p>
				</details>
			</div>
		</div>
		<?php
		$rows = SCP_Webhook::log( 100 );
		echo '<h2 class="scp-h2">Jurnal <small>ultimele 100 de trimiteri</small></h2>';
		if ( ! $rows ) {
			echo '<div class="scp-panel"><p class="scp-empty">Nicio trimitere încă.</p></div>';
			self::footer();
			return;
		}
		echo '<div class="scp-panel scp-panel--flush"><table class="scp-table scp-table--log"><thead><tr><th>Când</th><th>Eveniment</th><th>Programare</th><th>Rezultat</th><th>Încercări</th><th></th></tr></thead><tbody>';
		$events = array(
			'booking.created'           => 'Programare nouă',
			'booking.updated'           => 'Modificare',
			'booking.cancelled'         => 'Anulare',
			'booking.paid'              => 'Plată',
			'booking.payment_link_sent' => 'Link de plată trimis',
			'booking.sync'              => 'Retrimitere completă',
			'voucher.issued'            => 'Voucher emis',
			'test'                      => 'Test',
		);
		foreach ( $rows as $r ) {
			$payload = json_decode( $r['payload'], true );
			$code    = $payload['booking']['code'] ?? ( $payload['voucher']['code'] ?? '' );
			echo '<tr>';
			echo '<td class="scp-nowrap">' . esc_html( ( new DateTimeImmutable( $r['created_at'], SCP_Helpers::tz() ) )->format( 'd.m.Y H:i:s' ) ) . '</td>';
			echo '<td>' . esc_html( $events[ $r['event'] ] ?? $r['event'] ) . '</td>';
			echo '<td>' . ( $r['booking_id'] ? '<a href="' . esc_url( admin_url( 'admin.php?page=scp-bookings&booking=' . (int) $r['booking_id'] ) ) . '">' . esc_html( $code ) . '</a>' : esc_html( $code ) ) . '</td>';
			$label = array(
				'sent'    => 'Trimis',
				'pending' => 'În așteptare',
				'sending' => 'Se trimite',
				'failed'  => 'Eșuat',
			)[ $r['status'] ] ?? $r['status'];
			echo '<td><span class="scp-dot scp-dot--' . esc_attr( $r['status'] ) . '"></span>' . esc_html( $label ) . ( $r['response_code'] ? ' <small>HTTP ' . esc_html( (string) $r['response_code'] ) . '</small>' : '' ) . ( $r['last_error'] && 'sent' !== $r['status'] ? '<small class="scp-err">' . esc_html( $r['last_error'] ) . '</small>' : '' ) . '</td>';
			echo '<td>' . esc_html( (string) $r['attempts'] ) . '</td>';
			echo '<td class="scp-nowrap"><details class="scp-payload"><summary>Date trimise</summary><pre>' . esc_html( (string) wp_json_encode( $payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) ) . '</pre></details>';
			if ( 'sent' !== $r['status'] ) {
				echo ' <button type="button" class="button button-small" data-retry="' . esc_attr( (string) $r['id'] ) . '">Retrimite</button>';
			}
			echo '</td></tr>';
		}
		echo '</tbody></table></div>';
		self::footer();
	}
}
