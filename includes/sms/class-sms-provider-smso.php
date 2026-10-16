<?php
defined( 'ABSPATH' ) || exit;

/**
 * SMSO (https://api-docs.smso.ro). Singura clasă din plugin care știe cum arată API-ul SMSO.
 *
 * Configurare în wp-config.php:
 *   define( 'SCP_SMSO_API_KEY', '...' );   // obligatoriu pentru trimitere reală; fără ea pluginul doar simulează
 *   define( 'SCP_SMSO_SENDER', '...' );    // opțional; altfel expeditorul ales în pagina SMS
 */
class SCP_SMS_Provider_SMSO implements SCP_SMS_Provider {

	const BASE = 'https://app.smso.ro/api/v1/';

	public function id(): string {
		return 'smso';
	}

	private function key(): string {
		return defined( 'SCP_SMSO_API_KEY' ) ? trim( (string) SCP_SMSO_API_KEY ) : '';
	}

	public function is_configured(): bool {
		return '' !== $this->key();
	}

	private function request( string $method, string $path, array $params = array(), bool $auth = true ): array {
		$args = array(
			'method'  => $method,
			'timeout' => 15,
			'headers' => array(
				'Accept'       => 'application/json',
				'X-Source-App' => 'SC-Programari/' . ( defined( 'SCP_VERSION' ) ? SCP_VERSION : '1' ),
			),
		);
		if ( $auth ) {
			$args['headers']['X-Authorization'] = $this->key();
		}
		$url = self::BASE . ltrim( $path, '/' );
		if ( 'GET' === $method ) {
			$url = add_query_arg( array_map( 'rawurlencode', $params ), $url );
		} else {
			$args['body'] = $params;
		}
		$res = wp_remote_request( $url, $args );
		if ( is_wp_error( $res ) ) {
			return array( 'code' => 0, 'json' => null, 'error' => $res->get_error_message() );
		}
		$code = (int) wp_remote_retrieve_response_code( $res );
		$raw  = (string) wp_remote_retrieve_body( $res );
		$json = json_decode( $raw, true );
		// Fără răspunsul exact, un „400” nu spune ce parametru nu i-a plăcut SMSO; îl păstrăm în jurnal.
		return array( 'code' => $code, 'json' => is_array( $json ) ? $json : null, 'error' => '', 'raw' => mb_substr( wp_strip_all_tags( $raw ), 0, 300 ) );
	}

	private static function error_text( int $code, ?array $json, string $fallback = '', string $raw = '' ): string {
		$known = array(
			400 => 'Cerere invalidă',
			401 => 'Cheia API SMSO nu e validă',
			402 => 'Credit SMSO insuficient',
			403 => 'Mesajul conține cuvinte blocate',
			405 => 'Destinatarul s-a dezabonat',
			409 => 'Limita de mesaje pe minut a fost depășită',
			422 => 'Destinatarul nu poate primi mesaje internaționale',
		);
		$detail = '';
		if ( $json ) {
			foreach ( array( 'message', 'error', 'errors' ) as $k ) {
				if ( ! empty( $json[ $k ] ) ) {
					$detail = is_array( $json[ $k ] ) ? wp_json_encode( $json[ $k ], JSON_UNESCAPED_UNICODE ) : (string) $json[ $k ];
					break;
				}
			}
		}
		if ( '' === $detail && $json ) {
			$rest   = array_diff_key( $json, array( 'status' => 1 ) );
			$detail = $rest ? (string) wp_json_encode( $rest, JSON_UNESCAPED_UNICODE ) : '';
		}
		if ( '' === $detail && '' !== $raw ) {
			$detail = $raw;
		}
		$text = $known[ $code ] ?? ( $code ? 'Eroare SMSO ' . $code : ( $fallback ?: 'Fără răspuns de la SMSO' ) );
		return mb_substr( $text . ( $detail ? ': ' . $detail : '' ), 0, 250 );
	}

	public function send( string $to, string $body, array $opts = array() ): array {
		$params = array(
			'to'                   => $to,
			'sender'               => (string) ( $opts['sender'] ?? '' ),
			'body'                 => $body,
			'type'                 => 'transactional',
			'remove_special_chars' => 1,
		);
		if ( ! empty( $opts['webhook'] ) ) {
			$params['webhook_status'] = (string) $opts['webhook'];
		}
		$r    = $this->request( 'POST', 'send', $params );
		$code = $r['code'];
		$json = $r['json'];
		if ( 200 === $code && ! empty( $json['responseToken'] ) ) {
			return array(
				'ok'    => true,
				'id'    => (string) $json['responseToken'],
				'cost'  => isset( $json['transaction_cost'] ) ? (float) $json['transaction_cost'] : null,
				'code'  => $code,
				'error' => '',
				'retry' => false,
				'alert' => '',
			);
		}
		return array(
			'ok'    => false,
			'id'    => '',
			'cost'  => null,
			'code'  => $code,
			'error' => self::error_text( $code, $json, $r['error'], (string) ( $r['raw'] ?? '' ) ),
			// rețea, 409 (limită pe minut), 5xx și 402 (creditul se poate reîncărca) merită reîncercate
			'retry' => 0 === $code || 409 === $code || 429 === $code || $code >= 500 || 402 === $code,
			'alert' => 401 === $code ? 'auth' : ( 402 === $code ? 'credit' : '' ),
		);
	}

	private static function normalize_status( array $d ): array {
		$status = strtolower( (string) ( $d['status'] ?? '' ) );
		$recv   = (array) ( $d['receiver'] ?? array() );
		return array(
			'ok'           => '' !== $status,
			'id'           => (string) ( $d['uuid'] ?? $d['responseToken'] ?? '' ),
			'status'       => $status,
			'final'        => in_array( $status, array( 'delivered', 'undelivered', 'expired', 'error' ), true ),
			'sent_at'      => (string) ( $d['sent_at'] ?? '' ),
			'delivered_at' => (string) ( $d['delivered_at'] ?? '' ),
			'mcc'          => (string) ( $recv['mcc'] ?? '' ),
			'mnc'          => (string) ( $recv['mnc'] ?? '' ),
		);
	}

	public function status( string $id ): array {
		$r = $this->request( 'GET', 'status', array( 'responseToken' => $id ), false );
		if ( 200 !== $r['code'] || empty( $r['json']['data'] ) ) {
			return array( 'ok' => false, 'id' => $id, 'status' => '', 'final' => false, 'error' => self::error_text( $r['code'], $r['json'], $r['error'] ) );
		}
		$out       = self::normalize_status( (array) $r['json']['data'] );
		$out['id'] = $id;
		return $out;
	}

	public function parse_webhook( WP_REST_Request $request ): array {
		$p = $request->get_params();
		if ( empty( $p['receiver'] ) && ( isset( $p['receiver[number]'] ) || isset( $p['receiver_mcc'] ) ) ) {
			$p['receiver'] = array( 'mcc' => $p['receiver[mcc]'] ?? $p['receiver_mcc'] ?? '', 'mnc' => $p['receiver[mnc]'] ?? $p['receiver_mnc'] ?? '' );
		}
		return self::normalize_status( $p );
	}

	public function credit(): array {
		$r = $this->request( 'GET', 'credit-check' );
		if ( 200 === $r['code'] && isset( $r['json']['credit_value'] ) ) {
			return array( 'ok' => true, 'value' => (float) $r['json']['credit_value'], 'error' => '' );
		}
		return array( 'ok' => false, 'value' => null, 'error' => self::error_text( $r['code'], $r['json'], $r['error'] ), 'code' => $r['code'] );
	}

	public function senders(): array {
		$r = $this->request( 'GET', 'senders' );
		if ( 200 !== $r['code'] || ! is_array( $r['json'] ) ) {
			return array();
		}
		$list = isset( $r['json']['data'] ) && is_array( $r['json']['data'] ) ? $r['json']['data'] : $r['json'];
		$out  = array();
		foreach ( $list as $s ) {
			if ( is_array( $s ) && isset( $s['id'] ) ) {
				$out[] = array( 'id' => (string) $s['id'], 'name' => (string) ( $s['name'] ?? $s['id'] ), 'price' => isset( $s['pricePerMessage'] ) ? (float) $s['pricePerMessage'] : null );
			}
		}
		return $out;
	}
}
