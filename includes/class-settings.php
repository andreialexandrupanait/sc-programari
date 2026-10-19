<?php
defined( 'ABSPATH' ) || exit;

/**
 * Setările pluginului, păstrate într-o singură opțiune.
 */
class SCP_Settings {

	const OPTION = 'scp_settings';

	private static ?array $cache = null;

	public static function defaults(): array {
		return array(
			'booking_open'       => 1,
			'start_date'         => '2026-11-01',
			'end_date'           => '2026-12-24',
			'weekdays'           => array( 1, 2, 3, 4, 5, 6, 7 ), // ISO: 1 = luni … 7 = duminică.
			'times'              => array( '09:00', '11:00', '13:00', '15:00', '17:00', '19:00' ),
			'session_minutes'    => 60,
			'min_notice_hours'   => 24,
			'target'             => 200,
			'closed_message'     => 'Programările online sunt momentan închise. Ne poți suna la 0749 270 232.',
			'packages'           => array(
				array(
					'key'   => 'foto',
					'label' => 'Pachet Foto',
					'price' => 550,
					'desc'  => "3 decoruri diferite\n40 fotografii editate",
				),
				array(
					'key'   => 'foto_plus',
					'label' => 'Pachet Foto Plus',
					'price' => 650,
					'desc'  => "3 decoruri diferite\n40 fotografii editate\nAnimator Moș Crăciun",
				),
			),
			'studio_name'        => 'Ștefan Chelmu',
			'studio_address'     => 'Strada Macedona, Nr. 5, Galați',
			'studio_phone'       => '0749 270 232',
			'terms_url'          => '/termeni-si-conditii',
			'admin_emails'       => get_option( 'admin_email' ),
			'from_name'          => '',
			'from_email'         => '',
			'reply_to'           => '',
			'payment_url'        => '/plata-craciun-2026/?scp={token}',
			'webhook_url'        => '',
			'webhook_secret'     => '',
			'sheet_key'          => '',
			'webhook_events'     => array( 'booking.created', 'booking.updated', 'booking.cancelled', 'booking.payment_link_sent', 'booking.paid', 'voucher.issued' ),
			'track_fbq'          => 0,
			'pay_hours'                => 48,
			'pay_before_session_hours' => 2,
			'pay_reminders'            => '24, 12, 3',
			'last_call_minutes'        => 30,
			'early_enabled'            => 1,
			'early_until'              => '2026-11-15',
			'early_packages'           => array( 'foto' ),
			'early_amount'             => 100,
			'early_coupon'             => 'craciun2026',
			'early_label'              => 'Reducere pentru rezervările până pe 15 noiembrie',
			'vouchers_enabled'         => 1,
			'wc_variations'            => array(),
			'voucher_products'         => array(),
			'voucher_url'              => '/voucher-craciun-2026/',
			'voucher_prefix'           => 'CHELMU',
			'emails'             => self::default_emails(),
		);
	}

	public static function default_emails(): array {
		return array(
			'client_confirmation' => array(
				'enabled' => 1,
				'subject' => 'Ședința foto de Crăciun e programată: {ziua}, {data}, ora {ora}',
				'body'    => "Bună, {nume}!\n\nTe așteptăm în studio {ziua}, {data}, la ora {ora}, pentru ședința foto de Crăciun ({pachet}).\n\nȘedința durează o oră, ca cei mici să aibă timp să se acomodeze cu decorurile și cu aparatul foto. Îți recomandăm să vină odihniți și hrăniți. Pentru ținute, alege culori deschise, uni; nu e nevoie să fie neapărat în tematica Crăciunului.\n\nDacă trebuie să muți sau să anulezi programarea, sună-ne la {telefon_studio}.\n\nCu drag,\n{studio}",
			),
			'client_studio'       => array(
				'enabled' => 1,
				'subject' => 'Ședința foto de Crăciun e programată: {ziua}, {data}, ora {ora}',
				'body'    => "Bună, {nume}!\n\nTe așteptăm în studio {ziua}, {data}, la ora {ora}, pentru ședința foto de Crăciun ({pachet}).\n\nPlata ({pret} lei) se face la studio, cash sau cu cardul.\n\nȘedința durează o oră, ca cei mici să aibă timp să se acomodeze cu decorurile și cu aparatul foto. Îți recomandăm să vină odihniți și hrăniți. Pentru ținute, alege culori deschise, uni; nu e nevoie să fie neapărat în tematica Crăciunului.\n\nDacă trebuie să muți sau să anulezi programarea, sună-ne la {telefon_studio}.\n\nCu drag,\n{studio}",
			),
			'client_pending'      => array(
				'enabled' => 1,
				'subject' => 'Rezervarea ta: {ziua}, {data}, ora {ora} – mai ai un pas, plata',
				'body'    => "Bună, {nume}!\n\nȚi-am rezervat ora {ora} {ziua}, {data}, pentru ședința foto de Crăciun ({pachet}, {pret} lei).\n\nCa programarea să rămână a ta, te rugăm să faci plata online până {termen_plata}. Dacă plata nu ajunge până atunci, rezervarea se anulează automat și ora devine din nou liberă.\n\nDacă ai întrebări, ne găsești la {telefon_studio}.\n\nCu drag,\n{studio}",
			),
			'payment_received'    => array(
				'enabled' => 1,
				'subject' => 'Plata a ajuns. Te așteptăm {ziua}, {data}, la ora {ora}',
				'body'    => "Bună, {nume}!\n\nAm primit plata, iar programarea ta e confirmată. Te așteptăm în studio {ziua}, {data}, la ora {ora}.\n\nȘedința durează o oră, ca cei mici să aibă timp să se acomodeze cu decorurile și cu aparatul foto. Îți recomandăm să vină odihniți și hrăniți. Pentru ținute, alege culori deschise, uni; nu e nevoie să fie neapărat în tematica Crăciunului.\n\nDacă trebuie să muți programarea, sună-ne la {telefon_studio}.\n\nCu drag,\n{studio}",
			),
			'session_reminder_day'  => array(
				'enabled' => 1,
				'subject' => 'Te așteptăm mâine, {ziua}, la ora {ora}',
				'body'    => "Bună, {nume}!\n\nÎți amintim că mâine, {ziua}, {data}, la ora {ora}, ai ședința foto de Crăciun la noi în studio, pe {adresa}.\n\nVeniți cu cei mici odihniți și hrăniți, iar dacă intervine ceva, sună-ne la {telefon_studio}.\n\nCu drag,\n{studio}",
			),
			'session_reminder_hour' => array(
				'enabled' => 1,
				'subject' => 'Ne vedem într-o oră, la {ora}',
				'body'    => "Bună, {nume}!\n\nNe vedem într-o oră, la ora {ora}, în studio, pe {adresa}. Dacă întârzii, sună-ne la {telefon_studio}.\n\nCu drag,\n{studio}",
			),
			'payment_reminder'    => array(
				'enabled' => 1,
				'subject' => 'Rezervarea din {data} expiră curând',
				'body'    => "Bună, {nume}!\n\nRezervarea ta pentru {ziua}, {data}, ora {ora}, încă așteaptă plata. Dacă plata nu ajunge până {termen_plata}, rezervarea se anulează automat.\n\nDacă te-ai răzgândit sau ai nevoie de altă oră, ne găsești la {telefon_studio}.\n\nCu drag,\n{studio}",
			),
			'payment_last_call'   => array(
				'enabled' => 1,
				'subject' => 'Mai ai puțin timp să plătești ora din {data}',
				'body'    => "Bună, {nume}!

Rezervarea ta pentru {ziua}, {data}, ora {ora}, se anulează automat dacă plata nu ajunge până {termen_plata}. Plătește acum, ca ora aleasă de tine să nu ajungă la altă familie.

Dacă ai nevoie de ajutor, ne găsești la {telefon_studio}.

Cu drag,
{studio}",
			),
			'expired'             => array(
				'enabled' => 1,
				'subject' => 'Rezervarea din {data} a fost anulată',
				'body'    => "Bună, {nume}!\n\nRezervarea ta pentru {ziua}, {data}, ora {ora}, a fost anulată automat, pentru că plata nu a ajuns în termen.\n\nDacă vrei totuși să vii, poți alege din nou o oră liberă pe site sau ne poți suna la {telefon_studio}.\n\nCu drag,\n{studio}",
			),
			'voucher_issued'      => array(
				'enabled' => 1,
				'subject' => 'Voucherul tău pentru ședința foto de Crăciun',
				'body'    => "Bună, {nume}!\n\nMulțumim că ai ales să dăruiești o ședință foto de Crăciun. Mai jos găsești codul voucherului, pe care îl poți trimite persoanei căreia vrei să i-l oferi.\n\nPentru programare, se intră pe {link_programare}, se alege ziua și ora, iar la final se introduce codul voucherului. Programarea e confirmată imediat, fără altă plată.\n\nVoucherul e valabil pentru ședințele de Crăciun din acest sezon, până pe {valabil_pana}, o singură dată, pentru pachetul de mai jos.\n\nCu drag,\n{studio}",
			),
			'admin_new'           => array(
				'enabled' => 1,
				'subject' => 'Programare nouă: {data}, ora {ora} – {nume} ({pachet})',
				'body'    => "A intrat o programare nouă de pe site.\n\nMesaj de la client: {mesaj}",
			),
			'payment_link'        => array(
				'enabled' => 1,
				'subject' => 'Link de plată pentru ședința foto din {data}',
				'body'    => "Bună, {nume}!\n\nPentru ședința foto de Crăciun din {ziua}, {data}, ora {ora} ({pachet}, {pret} lei), poți face plata online folosind butonul de mai jos.\n\nDacă ai întrebări, ne găsești la {telefon_studio}.\n\nCu drag,\n{studio}",
			),
			'rescheduled'         => array(
				'enabled' => 1,
				'subject' => 'Programarea ta a fost mutată: {ziua}, {data}, ora {ora}',
				'body'    => "Bună, {nume}!\n\nAm mutat programarea ta pentru ședința foto de Crăciun. Noua dată este {ziua}, {data}, la ora {ora}.\n\nDacă nu se potrivește, sună-ne la {telefon_studio}.\n\nCu drag,\n{studio}",
			),
			'cancelled'           => array(
				'enabled' => 1,
				'subject' => 'Programarea din {data} a fost anulată',
				'body'    => "Bună, {nume}!\n\nProgramarea ta pentru ședința foto de Crăciun din {ziua}, {data}, ora {ora}, a fost anulată.\n\nDacă vrei să alegi altă zi, ne găsești pe site sau la {telefon_studio}.\n\nCu drag,\n{studio}",
			),
		);
	}

	public static function all(): array {
		if ( null === self::$cache ) {
			$saved       = get_option( self::OPTION, array() );
			$saved       = is_array( $saved ) ? $saved : array();
			$defaults    = self::defaults();
			$merged      = array_merge( $defaults, $saved );
			$merged['emails'] = array_replace_recursive( self::default_emails(), is_array( $saved['emails'] ?? null ) ? $saved['emails'] : array() );
			self::$cache = $merged;
		}
		return self::$cache;
	}

	public static function get( string $key, $fallback = null ) {
		$all = self::all();
		return $all[ $key ] ?? $fallback;
	}

	public static function update( array $values ): void {
		$current = self::all();
		$new     = array_merge( $current, $values );
		update_option( self::OPTION, $new, true ); // citită pe aproape orice pagină: autoload, fără interogare separată
		self::$cache = null;
	}

	public static function flush(): void {
		self::$cache = null;
	}

	/** @return array<string,array{key:string,label:string,price:float,desc:string}> */
	public static function packages(): array {
		$out = array();
		foreach ( (array) self::get( 'packages', array() ) as $p ) {
			if ( empty( $p['key'] ) ) {
				continue;
			}
			$out[ $p['key'] ] = array(
				'key'   => (string) $p['key'],
				'label' => (string) $p['label'],
				'price' => (float) $p['price'],
				'desc'  => (string) ( $p['desc'] ?? '' ),
			);
		}
		return $out;
	}

	/** Orele standard, sortate și validate. */
	public static function times(): array {
		$times = array_filter( (array) self::get( 'times', array() ), array( 'SCP_Helpers', 'is_time' ) );
		$times = array_values( array_unique( $times ) );
		sort( $times );
		return $times;
	}

	public static function admin_emails(): array {
		$raw = preg_split( '/[\s,;]+/', (string) self::get( 'admin_emails', '' ) );
		return array_values( array_filter( array_map( 'sanitize_email', (array) $raw ), 'is_email' ) );
	}
}
