<?php
defined( 'ABSPATH' ) || exit;

/**
 * Date personale: curățenia de după sezon și uneltele WordPress de export / ștergere (Unelte → Confidențialitate).
 *
 * După 31 ianuarie din anul de după sezon:
 *  - jurnalul webhook (conține datele clienților trimise spre n8n) se șterge;
 *  - din jurnalul SMS rămân doar statusurile (fără număr și text);
 *  - programările anulate și neplătite se anonimizează.
 * Programările plătite și voucherele rămân: sunt evidența contabilă a sezonului.
 */
class SCP_Privacy {

	public static function init(): void {
		add_action( 'scp_cleanup', array( __CLASS__, 'cleanup' ) );
		add_filter( 'wp_privacy_personal_data_exporters', array( __CLASS__, 'register_exporter' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( __CLASS__, 'register_eraser' ) );
	}

	/** Ziua de după care datele sezonului se curăță: 31 ianuarie, anul următor sfârșitului de sezon. */
	public static function cutoff(): string {
		$end  = (string) SCP_Settings::get( 'end_date' );
		$year = SCP_Helpers::is_date( $end ) ? (int) substr( $end, 0, 4 ) : (int) SCP_Helpers::now()->format( 'Y' );
		return ( $year + 1 ) . '-01-31';
	}

	public static function cleanup(): void {
		if ( SCP_Helpers::now()->format( 'Y-m-d' ) <= self::cutoff() ) {
			return;
		}
		global $wpdb;
		$season_end = (string) SCP_Settings::get( 'end_date' );
		$wpdb->query( 'DELETE FROM ' . SCP_Install::table( 'webhook_log' ) . " WHERE status IN ('sent','failed')" ); // phpcs:ignore
		$wpdb->query( 'UPDATE ' . SCP_Install::table( 'sms_log' ) . " SET phone = '', body = NULL, history = NULL WHERE phone <> '' OR body IS NOT NULL" ); // phpcs:ignore
		$ids = $wpdb->get_col( $wpdb->prepare( 'SELECT id FROM ' . SCP_Install::table( 'bookings' ) . " WHERE status = 'cancelled' AND payment_status NOT IN (" . SCP_Helpers::paid_sql() . ") AND slot_date <= %s AND name <> 'Anonimizat'", $season_end ) ); // phpcs:ignore
		foreach ( $ids as $id ) {
			self::anonymize( (int) $id );
		}
	}

	private static function anonymize( int $id ): void {
		global $wpdb;
		$wpdb->update(
			SCP_Install::table( 'bookings' ),
			array( 'name' => 'Anonimizat', 'email' => '', 'phone' => '', 'message' => '', 'notes' => '', 'meta' => '{}' ),
			array( 'id' => $id )
		);
		$wpdb->update( SCP_Install::table( 'sms_log' ), array( 'phone' => '', 'body' => null, 'history' => null ), array( 'booking_id' => $id ) );
		$wpdb->delete( SCP_Install::table( 'webhook_log' ), array( 'booking_id' => $id ) );
	}

	public static function register_exporter( array $exporters ): array {
		$exporters['sc-programari'] = array( 'exporter_friendly_name' => 'Programări ședințe foto de Crăciun', 'callback' => array( __CLASS__, 'export' ) );
		return $exporters;
	}

	public static function register_eraser( array $erasers ): array {
		$erasers['sc-programari'] = array( 'eraser_friendly_name' => 'Programări ședințe foto de Crăciun', 'callback' => array( __CLASS__, 'erase' ) );
		return $erasers;
	}

	private static function by_email( string $email ): array {
		global $wpdb;
		if ( ! is_email( $email ) ) {
			return array();
		}
		return (array) $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . SCP_Install::table( 'bookings' ) . ' WHERE email = %s ORDER BY id', $email ), ARRAY_A );
	}

	public static function export( string $email, int $page = 1 ): array {
		$items = array();
		foreach ( self::by_email( $email ) as $b ) {
			$b['meta'] = json_decode( (string) $b['meta'], true ) ?: array();
			$e         = SCP_Bookings::export( $b );
			$data      = array();
			foreach ( array( 'code' => 'Cod', 'name' => 'Nume', 'email' => 'Email', 'phone' => 'Telefon', 'date_ro' => 'Data ședinței', 'time' => 'Ora', 'package_label' => 'Pachet', 'price' => 'Preț', 'status_label' => 'Status', 'message' => 'Mesaj', 'created_at' => 'Înregistrată la' ) as $k => $label ) {
				$data[] = array( 'name' => $label, 'value' => (string) ( $e[ $k ] ?? '' ) );
			}
			$items[] = array( 'group_id' => 'sc-programari', 'group_label' => 'Programări ședințe foto', 'item_id' => 'scp-booking-' . $b['id'], 'data' => $data );
		}
		return array( 'data' => $items, 'done' => true );
	}

	/** Programările viitoare și active rămân (sunt contractul cu clientul); restul se anonimizează. */
	public static function erase( string $email, int $page = 1 ): array {
		$removed  = false;
		$retained = false;
		$messages = array();
		$today    = SCP_Helpers::now()->format( 'Y-m-d' );
		foreach ( self::by_email( $email ) as $b ) {
			if ( 'cancelled' !== $b['status'] && $b['slot_date'] >= $today ) {
				$retained   = true;
				$messages[] = 'Programarea ' . $b['code'] . ' din ' . SCP_Helpers::date_ro( $b['slot_date'] ) . ' e încă activă și nu a fost ștearsă. Anuleaz-o întâi din Programări.';
				continue;
			}
			self::anonymize( (int) $b['id'] );
			$removed = true;
		}
		return array( 'items_removed' => $removed, 'items_retained' => $retained, 'messages' => $messages, 'done' => true );
	}
}
