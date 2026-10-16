<?php
defined( 'ABSPATH' ) || exit;

/**
 * Furnizor de probă: folosit cât timp nu există cheie API (de exemplu pe Local). Nu trimite nimic,
 * dar parcurge exact același drum (jurnal, statusuri, reîncercări), ca fluxul să poată fi testat cap-coadă.
 * Un număr care se termină în 0000 simulează o eroare de rețea, iar unul în 9999, un mesaj nelivrat.
 */
class SCP_SMS_Provider_Simulated implements SCP_SMS_Provider {

	public function id(): string {
		return 'simulat';
	}

	public function is_configured(): bool {
		return false;
	}

	public function send( string $to, string $body, array $opts = array() ): array {
		if ( str_ends_with( $to, '0000' ) ) {
			return array( 'ok' => false, 'id' => '', 'cost' => null, 'code' => 0, 'error' => 'Simulare: eroare de rețea', 'retry' => true, 'alert' => '' );
		}
		$id = 'sim-' . wp_generate_uuid4();
		set_transient( 'scp_sim_' . md5( $id ), str_ends_with( $to, '9999' ) ? 'undelivered' : 'delivered', DAY_IN_SECONDS );
		return array( 'ok' => true, 'id' => $id, 'cost' => 0.0, 'code' => 200, 'error' => '', 'retry' => false, 'alert' => '' );
	}

	public function status( string $id ): array {
		$st = (string) get_transient( 'scp_sim_' . md5( $id ) );
		return array(
			'ok'           => '' !== $st,
			'id'           => $id,
			'status'       => $st ?: 'sent',
			'final'        => '' !== $st,
			'sent_at'      => gmdate( 'Y-m-d H:i:s' ),
			'delivered_at' => 'delivered' === $st ? gmdate( 'Y-m-d H:i:s' ) : '',
			'mcc'          => '226',
			'mnc'          => '',
		);
	}

	public function parse_webhook( WP_REST_Request $request ): array {
		return ( new SCP_SMS_Provider_SMSO() )->parse_webhook( $request );
	}

	public function credit(): array {
		return array( 'ok' => true, 'value' => null, 'error' => '' );
	}

	public function senders(): array {
		return array();
	}
}
