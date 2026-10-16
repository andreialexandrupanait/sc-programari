<?php
defined( 'ABSPATH' ) || exit;

/**
 * REST API.
 *
 * Rutele publice nu folosesc nonce, pentru că pagina e servită din cache (Cloudflare),
 * iar un nonce din HTML-ul cache-uit ar expira. Protecția vine din: câmp-capcană,
 * timp minim de completare, limită de cereri pe IP și validarea strictă a slotului.
 * Răspunsurile au antete no-store, ca disponibilitatea să nu fie cache-uită.
 */
class SCP_Rest {

	const NS = 'scp/v1';

	public static function init(): void {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
		add_filter( 'rest_authentication_errors', array( __CLASS__, 'allow_public_routes' ), 999 );
	}

	/**
	 * Pe site, mu-plugin-ul de securitate SimpleAD închide REST API-ul pentru vizitatorii nelogați.
	 * Redeschidem doar rutele publice ale formularului (scp/v1/config|month|day|bookings|ics),
	 * nu și pe cele de admin, care cer în continuare utilizator logat cu drept de acces.
	 */
	public static function allow_public_routes( $result ) {
		if ( ! is_wp_error( $result ) || 'rest_not_logged_in' !== $result->get_error_code() ) {
			return $result;
		}
		$route = '';
		if ( isset( $GLOBALS['wp']->query_vars['rest_route'] ) ) {
			$route = (string) $GLOBALS['wp']->query_vars['rest_route'];
		} elseif ( isset( $_GET['rest_route'] ) ) { // phpcs:ignore
			$route = sanitize_text_field( wp_unslash( $_GET['rest_route'] ) ); // phpcs:ignore
		}
		$route = '/' . ltrim( $route, '/' );
		if ( preg_match( '#^/' . preg_quote( self::NS, '#' ) . '/(config|month|day|voucher|bookings|ics/[a-f0-9]{32})/?$#', $route ) ) {
			return null;
		}
		return $result;
	}

	public static function routes(): void {
		$public = '__return_true';
		$admin  = static fn() => current_user_can( SCP_Install::CAP );

		register_rest_route( self::NS, '/config', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'config' ), 'permission_callback' => $public ) );
		register_rest_route( self::NS, '/month', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'month' ), 'permission_callback' => $public ) );
		register_rest_route( self::NS, '/day', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'day' ), 'permission_callback' => $public ) );
		register_rest_route( self::NS, '/voucher', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'voucher' ), 'permission_callback' => $public ) );
		register_rest_route( self::NS, '/bookings', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'create' ), 'permission_callback' => $public ) );
		register_rest_route( self::NS, '/ics/(?P<token>[a-f0-9]{32})', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'ics' ), 'permission_callback' => $public ) );

		register_rest_route( self::NS, '/admin/calendar', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'admin_calendar' ), 'permission_callback' => $admin ) );
		register_rest_route( self::NS, '/admin/slot', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'admin_slot' ), 'permission_callback' => $admin ) );
		register_rest_route( self::NS, '/admin/day', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'admin_day' ), 'permission_callback' => $admin ) );
		register_rest_route( self::NS, '/admin/webhook/test', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'admin_webhook_test' ), 'permission_callback' => $admin ) );
		register_rest_route( self::NS, '/admin/webhook/(?P<id>\d+)/retry', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'admin_webhook_retry' ), 'permission_callback' => $admin ) );
	}

	private static function nocache( WP_REST_Response $r ): WP_REST_Response {
		$r->header( 'Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0' );
		$r->header( 'CDN-Cache-Control', 'no-store' );
		return $r;
	}

	private static function error( WP_Error $e ): WP_REST_Response {
		$data = (array) $e->get_error_data();
		unset( $data['db'] );
		return self::nocache(
			new WP_REST_Response(
				array(
					'code'    => $e->get_error_code(),
					'message' => $e->get_error_message(),
					'fields'  => $data['fields'] ?? (object) array(),
				),
				(int) ( $data['status'] ?? 400 )
			)
		);
	}

	/* ---------- Public ---------- */

	public static function config(): WP_REST_Response {
		$packages = array_values(
			array_map(
				static fn( $p ) => array(
					'key'      => $p['key'],
					'label'    => $p['label'],
					'price'    => $p['price'],
					'features' => array_values( array_filter( array_map( 'trim', explode( "\n", $p['desc'] ) ) ) ),
				),
				SCP_Settings::packages()
			)
		);
		return self::nocache(
			new WP_REST_Response(
				array(
					'open'            => (bool) SCP_Settings::get( 'booking_open' ),
					'closed_message'  => (string) SCP_Settings::get( 'closed_message' ),
					'packages'        => $packages,
					'months'          => SCP_Availability::public_months(),
					'start'           => SCP_Settings::get( 'start_date' ),
					'end'             => SCP_Settings::get( 'end_date' ),
					'today'           => SCP_Helpers::now()->format( 'Y-m-d' ),
					'session_minutes' => (int) SCP_Settings::get( 'session_minutes' ),
					'terms_url'       => SCP_Helpers::absolute_url( (string) SCP_Settings::get( 'terms_url' ) ),
					'studio_phone'    => (string) SCP_Settings::get( 'studio_phone' ),
					'studio_address'  => (string) SCP_Settings::get( 'studio_address' ),
					'vouchers'        => (bool) SCP_Settings::get( 'vouchers_enabled' ),
					'pay_hours'       => (int) SCP_Settings::get( 'pay_hours' ),
					'quotes'          => self::quotes(),
				)
			)
		);
	}

	/** Prețul de azi pentru fiecare pachet (cu reducerea de început de sezon, dacă e cazul). */
	private static function quotes(): array {
		$out = array();
		foreach ( array_keys( SCP_Settings::packages() ) as $key ) {
			$q           = SCP_Pricing::quote( $key );
			$out[ $key ] = array(
				'list'     => $q['list'],
				'price'    => $q['price'],
				'discount' => $q['discount'],
				'source'   => $q['source'],
				'label'    => $q['label'],
			);
		}
		return $out;
	}

	/** Verifică un voucher cadou și spune pentru ce pachet e. */
	public static function voucher( WP_REST_Request $req ): WP_REST_Response {
		$key   = 'scp_vq_' . md5( SCP_Helpers::client_ip() . wp_salt( 'nonce' ) );
		$count = (int) get_transient( $key );
		if ( $count >= 20 ) {
			return self::error( new WP_Error( 'scp_rate', 'Prea multe încercări. Încearcă din nou peste o oră sau sună-ne.', array( 'status' => 429 ) ) );
		}
		set_transient( $key, $count + 1, HOUR_IN_SECONDS );
		$chk = SCP_Vouchers::check( (string) $req->get_param( 'code' ) );
		if ( ! $chk['ok'] ) {
			return self::nocache( new WP_REST_Response( array( 'ok' => false, 'message' => $chk['message'] ) ) );
		}
		$v = $chk['voucher'];
		return self::nocache(
			new WP_REST_Response(
				array(
					'ok'            => true,
					'code'          => $v['code'],
					'package'       => $v['package'],
					'package_label' => SCP_Settings::packages()[ $v['package'] ]['label'] ?? $v['package'],
					'expires_on'    => $v['expires_on'] ? SCP_Helpers::date_ro( $v['expires_on'] ) : '',
				)
			)
		);
	}

	public static function month( WP_REST_Request $req ): WP_REST_Response {
		$month = (string) $req->get_param( 'month' );
		if ( ! preg_match( '/^\d{4}-\d{2}$/', $month ) ) {
			return self::error( new WP_Error( 'scp_invalid', 'Lună invalidă.', array( 'status' => 400 ) ) );
		}
		$from = $month . '-01';
		$to   = SCP_Helpers::dt( $from )->format( 'Y-m-t' );
		$days = array();
		$open = (bool) SCP_Settings::get( 'booking_open' );
		foreach ( SCP_Availability::range( $from, $to ) as $d => $day ) {
			$free  = count( array_filter( $day['slots'], static fn( $s ) => 'free' === $s['state'] ) );
			$total = count( $day['slots'] );
			if ( ! $open || 0 === $total || $day['day_blocked'] ) {
				$state = 'closed';
			} elseif ( $free > 0 ) {
				$state = 'available';
			} else {
				$past  = count( array_filter( $day['slots'], static fn( $s ) => 'past' === $s['state'] ) );
				$state = $past === $total ? 'closed' : 'full';
			}
			$days[ $d ] = array(
				'state' => $state,
				'free'  => $open ? $free : 0,
			);
		}
		return self::nocache( new WP_REST_Response( array( 'month' => $month, 'days' => $days ) ) );
	}

	public static function day( WP_REST_Request $req ): WP_REST_Response {
		$date = (string) $req->get_param( 'date' );
		if ( ! SCP_Helpers::is_date( $date ) ) {
			return self::error( new WP_Error( 'scp_invalid', 'Dată invalidă.', array( 'status' => 400 ) ) );
		}
		$open  = (bool) SCP_Settings::get( 'booking_open' );
		$day   = SCP_Availability::day( $date );
		$slots = array();
		foreach ( $day['slots'] as $s ) {
			// Clientul vede doar orele care există în program, nu și motivul pentru care una nu e liberă.
			if ( 'blocked' === $s['state'] && 'extra' === $s['kind'] ) {
				continue;
			}
			$slots[] = array(
				'time'      => $s['time'],
				'available' => $open && 'free' === $s['state'],
			);
		}
		if ( $day['day_blocked'] ) {
			$slots = array();
		}
		return self::nocache(
			new WP_REST_Response(
				array(
					'date'  => $date,
					'label' => SCP_Helpers::date_long( $date ),
					'slots' => $slots,
				)
			)
		);
	}

	public static function create( WP_REST_Request $req ): WP_REST_Response {
		$p = $req->get_json_params() ?: $req->get_body_params();

		// Câmp-capcană: oamenii nu îl văd, roboții îl completează.
		if ( ! empty( $p['website'] ) ) {
			return self::nocache( new WP_REST_Response( array( 'ok' => true ), 200 ) );
		}
		$elapsed = isset( $p['elapsed'] ) ? (int) $p['elapsed'] : 0;
		if ( $elapsed < 3000 ) {
			return self::error( new WP_Error( 'scp_too_fast', 'Te rugăm să verifici datele și să încerci din nou.', array( 'status' => 429 ) ) );
		}

		$ip_key = 'scp_rl_' . md5( SCP_Helpers::client_ip() . wp_salt( 'nonce' ) );
		$count  = (int) get_transient( $ip_key );
		if ( $count >= (int) apply_filters( 'scp_rate_limit', 6 ) ) {
			return self::error( new WP_Error( 'scp_rate', 'Prea multe încercări de pe această conexiune. Te rugăm să ne suni pentru programare.', array( 'status' => 429 ) ) );
		}

		$utm = array();
		foreach ( (array) ( $p['utm'] ?? array() ) as $k => $v ) {
			if ( preg_match( '/^(utm_[a-z]+|gclid|fbclid|referrer|landing)$/', (string) $k ) ) {
				$utm[ $k ] = mb_substr( sanitize_text_field( (string) $v ), 0, 300 );
			}
		}

		$b = SCP_Bookings::create(
			(array) $p,
			'online',
			array(
				'utm'     => $utm,
				'ua'      => mb_substr( sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ?? '' ) ), 0, 300 ),
				'ip_hash' => substr( hash( 'sha256', SCP_Helpers::client_ip() . wp_salt( 'auth' ) ), 0, 16 ),
			)
		);

		if ( is_wp_error( $b ) ) {
			return self::error( $b );
		}
		// Numărăm doar programările reușite, ca un părinte care greșește un câmp să nu fie blocat.
		set_transient( $ip_key, $count + 1, HOUR_IN_SECONDS );

		$packages = SCP_Settings::packages();
		return self::nocache(
			new WP_REST_Response(
				array(
					'ok'      => true,
					'booking' => array(
						'code'          => $b['code'],
						'date'          => $b['slot_date'],
						'time'          => $b['slot_time'],
						'date_label'    => SCP_Helpers::date_long( $b['slot_date'] ),
						'package'       => $b['package'],
						'package_label' => $packages[ $b['package'] ]['label'] ?? $b['package'],
						'price'         => $b['price'],
						'list_price'    => $b['list_price'],
						'discount'      => $b['discount'],
						'discount_label'=> 'early' === $b['discount_source'] ? (string) SCP_Settings::get( 'early_label' ) : '',
						'voucher_code'  => 'gift' === $b['discount_source'] ? $b['discount_code'] : '',
						'status'        => $b['status'],
						'pay_by_label'  => $b['pay_by'] ? SCP_Helpers::datetime_long( $b['pay_by'] ) : '',
						'payment_url'   => 'pending' === $b['status'] ? SCP_Bookings::payment_url( $b ) : '',
						'email'         => $b['email'],
						'ics_url'       => rest_url( self::NS . '/ics/' . $b['token'] ),
						'gcal_url'      => SCP_Mailer::google_calendar_url( $b ),
					),
				),
				201
			)
		);
	}

	public static function ics( WP_REST_Request $req ) {
		$b = SCP_Bookings::get_by_token( (string) $req['token'] );
		if ( ! $b || 'cancelled' === $b['status'] ) {
			return self::error( new WP_Error( 'scp_not_found', 'Programarea nu există.', array( 'status' => 404 ) ) );
		}
		nocache_headers();
		header( 'Content-Type: text/calendar; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="sedinta-foto-craciun.ics"' );
		echo SCP_Mailer::ics( $b ); // phpcs:ignore
		exit;
	}

	/* ---------- Admin ---------- */

	public static function admin_calendar( WP_REST_Request $req ): WP_REST_Response {
		$from = (string) $req->get_param( 'from' );
		$to   = (string) $req->get_param( 'to' );
		if ( ! SCP_Helpers::is_date( $from ) || ! SCP_Helpers::is_date( $to ) || $to < $from ) {
			return self::error( new WP_Error( 'scp_invalid', 'Interval invalid.', array( 'status' => 400 ) ) );
		}
		if ( SCP_Helpers::dt( $from )->diff( SCP_Helpers::dt( $to ) )->days > 62 ) {
			return self::error( new WP_Error( 'scp_invalid', 'Intervalul poate avea cel mult două luni.', array( 'status' => 400 ) ) );
		}
		$packages = SCP_Settings::packages();
		$days     = array();
		foreach ( SCP_Availability::range( $from, $to, true ) as $d => $day ) {
			foreach ( $day['slots'] as &$s ) {
				if ( $s['booking'] ) {
					$s['booking'] = array(
						'id'             => (int) $s['booking']['id'],
						'code'           => $s['booking']['code'],
						'name'           => $s['booking']['name'],
						'phone'          => $s['booking']['phone'],
						'package'        => $packages[ $s['booking']['package'] ]['label'] ?? $s['booking']['package'],
						'plus'           => 'foto' !== $s['booking']['package'],
						'payment_status' => $s['booking']['payment_status'],
						'status'         => $s['booking']['status'],
						'source'         => $s['booking']['source'],
					);
				}
			}
			unset( $s );
			$day['label']   = SCP_Helpers::date_long( $d );
			$day['weekday'] = SCP_Helpers::weekday( $d );
			$days[]         = $day;
		}
		return self::nocache( new WP_REST_Response( array( 'days' => $days, 'times' => SCP_Settings::times() ) ) );
	}

	/** action: toggle | add | remove */
	public static function admin_slot( WP_REST_Request $req ): WP_REST_Response {
		$date   = (string) $req->get_param( 'date' );
		$time   = (string) $req->get_param( 'time' );
		$action = (string) $req->get_param( 'action' );
		$reason = sanitize_text_field( (string) $req->get_param( 'reason' ) );
		if ( ! SCP_Helpers::is_date( $date ) || ! SCP_Helpers::is_time( $time ) ) {
			return self::error( new WP_Error( 'scp_invalid', 'Dată sau oră invalidă.', array( 'status' => 400 ) ) );
		}
		switch ( $action ) {
			case 'add':
				$exists = array_filter( SCP_Availability::day( $date, null, null, true )['slots'], static fn( $s ) => $s['time'] === $time );
				if ( $exists ) {
					return self::error( new WP_Error( 'scp_exists', 'Ora există deja în această zi.', array( 'status' => 409 ) ) );
				}
				SCP_Availability::set_override( $date, $time, 'open' );
				$result = 'added';
				break;
			default:
				$result = SCP_Availability::toggle_block( $date, $time, $reason );
				if ( 'booked' === $result ) {
					return self::error( new WP_Error( 'scp_booked', 'Slotul are o programare. Anulează sau mută programarea mai întâi.', array( 'status' => 409 ) ) );
				}
		}
		return self::nocache( new WP_REST_Response( array( 'result' => $result, 'day' => SCP_Availability::day( $date, null, null, true ) ) ) );
	}

	public static function admin_day( WP_REST_Request $req ): WP_REST_Response {
		$date   = (string) $req->get_param( 'date' );
		$block  = (bool) $req->get_param( 'block' );
		$reason = sanitize_text_field( (string) $req->get_param( 'reason' ) );
		if ( ! SCP_Helpers::is_date( $date ) ) {
			return self::error( new WP_Error( 'scp_invalid', 'Dată invalidă.', array( 'status' => 400 ) ) );
		}
		if ( $block ) {
			SCP_Availability::set_override( $date, '', 'block', $reason );
		} else {
			SCP_Availability::clear_override( $date, '' );
		}
		return self::nocache( new WP_REST_Response( array( 'day' => SCP_Availability::day( $date, null, null, true ) ) ) );
	}

	public static function admin_webhook_test(): WP_REST_Response {
		if ( '' === trim( (string) SCP_Settings::get( 'webhook_url' ) ) ) {
			return self::error( new WP_Error( 'scp_no_url', 'Salvează mai întâi adresa webhook-ului.', array( 'status' => 400 ) ) );
		}
		return self::nocache( new WP_REST_Response( SCP_Webhook::send_test() ) );
	}

	public static function admin_webhook_retry( WP_REST_Request $req ): WP_REST_Response {
		return self::nocache( new WP_REST_Response( SCP_Webhook::deliver( (int) $req['id'] ) ) );
	}
}
