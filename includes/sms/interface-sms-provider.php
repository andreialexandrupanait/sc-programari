<?php
defined( 'ABSPATH' ) || exit;

/**
 * Contractul unui furnizor de SMS. Tot ce e specific unui furnizor (URL-uri, autentificare, coduri de eroare,
 * formatul webhookului) stă în clasa care îl implementează; restul pluginului vorbește doar cu aceste metode.
 *
 * Rezultatul unei trimiteri:
 *   ok (bool), id (string, ID-ul mesajului la furnizor), cost (float|null, în bani/eurocenți, cum raportează furnizorul),
 *   code (int, codul HTTP), error (string), retry (bool, merită reîncercat), alert (string, „auth” sau „credit” dacă trebuie anunțat cineva)
 *
 * Rezultatul unei verificări de status sau al unui webhook:
 *   ok, id, status (dispatched|sent|delivered|undelivered|expired|error), final (bool), sent_at, delivered_at (Y-m-d H:i:s, UTC), mcc, mnc
 */
interface SCP_SMS_Provider {

	public function id(): string;

	/** Are cheia API configurată (altfel pluginul rulează în modul simulare). */
	public function is_configured(): bool;

	/** @param array $opts sender, webhook (URL pentru statusul acestui mesaj) */
	public function send( string $to, string $body, array $opts = array() ): array;

	public function status( string $id ): array;

	/** Interpretează notificarea de status trimisă de furnizor. */
	public function parse_webhook( WP_REST_Request $request ): array;

	/** @return array ok, value (float), error */
	public function credit(): array;

	/** @return array listă de expeditori: id, name, price */
	public function senders(): array;
}
