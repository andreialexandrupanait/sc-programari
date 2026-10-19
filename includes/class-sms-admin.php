<?php
defined( 'ABSPATH' ) || exit;

/**
 * Pagina „SMS” din admin: starea sistemului, testul de livrare pe rețele, setări și șabloane, jurnal.
 */
class SCP_SMS_Admin {

	/** Textele SMS trimise din Setări: fără diacritice, iar un text gol revine la cel implicit. */
	public static function clean_templates( array $in ): array {
		$out = array();
		foreach ( SCP_SMS::defaults()['templates'] as $k => $def ) {
			$body      = SCP_SMS::gsm( sanitize_textarea_field( (string) ( $in[ $k ]['body'] ?? $def['body'] ) ) );
			$out[ $k ] = array( 'enabled' => empty( $in[ $k ]['enabled'] ) ? 0 : 1, 'body' => '' !== trim( $body ) ? $body : $def['body'] );
		}
		return $out;
	}

	/**
	 * Numărătoarea de caractere și previzualizarea ca pe telefon, la fiecare text SMS, cu datele de exemplu.
	 * Imită SCP_SMS::render(): fără diacritice, iar peste 160 de caractere renunță la prenume și apoi scurtează.
	 */
	public static function counter_script(): void {
		?>
		<script>
		(function(){
			var vars=<?php echo wp_json_encode( SCP_SMS::vars( SCP_SMS::sample_booking() ) ); ?>;
			var map={'ă':'a','â':'a','î':'i','ș':'s','ş':'s','ț':'t','ţ':'t','Ă':'A','Â':'A','Î':'I','Ș':'S','Ş':'S','Ț':'T','Ţ':'T','–':'-','—':'-','„':'"','”':'"','“':'"','’':"'",'‘':"'",'…':'...','·':'-'};
			function gsm(s){ return s.replace(/[ăâîșşțţĂÂÎȘŞȚŢ–—„”“’‘…·]/g,function(c){ return map[c]; }).normalize('NFD').replace(/[\u0300-\u036f]/g,'').replace(/[^\x0A\x20-\x7E]/g,''); }
			function gsmlen(s){ return s.length + (s.match(/[\[\]{}\\^~|]/g)||[]).length; }
			function fill(t,v){ Object.keys(v).forEach(function(k){ t=t.split(k).join(v[k]); }); return t; }
			function render(tpl){
				var txt=gsm(fill(tpl,vars)), dropped=false, cut=false;
				if(gsmlen(txt)>160){
					var v=Object.assign({},vars,{'{prenume}':''});
					txt=gsm(fill(tpl,v)).replace(/^(Salut|Buna|Buna ziua),\s*!\s*/,'$1! ').replace(/^,\s*/,'');
					dropped=true;
				}
				while(gsmlen(txt)>160){ txt=txt.slice(0,-1).replace(/\s+$/,''); cut=true; }
				return {text:txt, raw:gsmlen(gsm(fill(tpl,vars))), dropped:dropped, cut:cut};
			}
			document.querySelectorAll('.scp-smsbox').forEach(function(box){
				var ta=box.querySelector('textarea'), c=box.querySelector('.scp-smscount'), note=box.querySelector('.scp-smsnote'), bubble=box.querySelector('.scp-phone-bubble');
				if(!ta||!c) return;
				function upd(){
					var r=render(ta.value);
					c.textContent=r.raw+' / 160'; c.classList.toggle('is-over',r.raw>160);
					var msg=[];
					if(/[^\x0A\x20-\x7E]/.test(ta.value)) msg.push('diacriticele și semnele speciale se înlocuiesc la trimitere');
					if(r.cut) msg.push('prea lung: se taie la 160 de caractere'); else if(r.dropped) msg.push('prea lung cu prenumele: pleacă fără prenume');
					if(note) note.textContent=msg.join(' · ');
					if(bubble){ bubble.textContent=r.text||'(gol: la salvare revine textul implicit)'; bubble.classList.toggle('is-empty',!r.text); }
				}
				ta.addEventListener('input',upd); upd();
			});
		})();
		</script>
		<?php
	}

	/** Mesajele SMS, în ordinea în care le primește un client (pagina SMS și rezumatul din Setări). */
	public static function labels(): array {
		return array(
			'created'      => 'La rezervare (cu link de plată)',
			'pay_reminder' => 'Ultimul apel la plată (cu puțin înainte de expirare; noaptea, la 21:30 sau la 08:00)',
			'paid'         => 'La plată',
			'voucher'      => 'La rezervarea cu voucher',
			'studio'       => 'La rezervarea cu plata la studio',
			'free'         => 'La ședința oferită gratuit',
			'moved'        => 'La mutarea ședinței (din admin, cu „Anunță clientul”)',
			'remind_day'   => 'Cu o zi înainte',
			'remind_hour'  => 'Cu o oră înainte',
		);
	}

	const SLUG     = 'scp-sms';
	const NETWORKS = array( 'orange' => 'Orange', 'vodafone' => 'Vodafone', 'telekom' => 'Telekom', 'digi' => 'Digi' );

	public static function init(): void {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 20 );
		foreach ( array( 'save', 'test', 'check', 'resend', 'samples' ) as $a ) {
			add_action( 'admin_post_scp_sms_' . $a, array( __CLASS__, 'handle_' . $a ) );
		}
	}

	public static function menu(): void {
		add_submenu_page( SCP_Admin::SLUG, 'SMS', 'SMS', 'manage_options', self::SLUG, array( __CLASS__, 'page' ) );
	}

	private static function back( string $msg = '', string $anchor = '' ): void {
		wp_safe_redirect( add_query_arg( array( 'page' => self::SLUG, 'scp_msg' => rawurlencode( $msg ) ), admin_url( 'admin.php' ) ) . $anchor );
		exit;
	}

	private static function guard( string $action ): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Nu ai acces.' );
		}
		check_admin_referer( $action );
	}

	/* ---------------------------------------------------------------- acțiuni */

	public static function handle_save(): void {
		self::guard( 'scp_sms_save' );
		$p = wp_unslash( $_POST ); // phpcs:ignore
		SCP_SMS::save( array( 'sender' => sanitize_text_field( $p['sender'] ?? '' ) ) );
		self::back( 'Expeditorul a fost salvat.', '#setari' );
	}

	/** Setările SMS trimise din Setări → SMS (fără expeditor, care ține de integrare). */
	public static function clean_settings( array $p ): array {
		$time = static fn( $v, $d ) => preg_match( '/^([01]\d|2[0-3]):[0-5]\d$/', (string) $v ) ? (string) $v : $d;
		return array(
			'enabled'          => in_array( $p['mode'] ?? '', array( 'test', 'on' ), true ) ? 1 : 0,
			'test_mode'        => 'test' === ( $p['mode'] ?? '' ) ? 1 : 0,
			'test_numbers'     => implode( ', ', array_filter( array_map( static fn( $n ) => SCP_Helpers::normalize_phone( trim( $n ) ), preg_split( '/[,;\n]+/', (string) ( $p['test_numbers'] ?? '' ) ) ) ) ),
			'address'          => SCP_SMS::gsm( sanitize_text_field( $p['address'] ?? '' ) ),
			'remind_day_time'  => $time( $p['remind_day_time'] ?? '', '18:00' ),
			'remind_hour_mins' => max( 15, min( 240, (int) ( $p['remind_hour_mins'] ?? 60 ) ) ),
			'report_enabled'   => empty( $p['report_enabled'] ) ? 0 : 1,
			'report_time'      => $time( $p['report_time'] ?? '', '18:00' ),
			'backup_email'     => 'always' === ( $p['backup_email'] ?? '' ) ? 'always' : 'fallback',
			'alert_emails'     => implode( ', ', array_filter( array_map( 'sanitize_email', preg_split( '/[\s,;]+/', (string) ( $p['alert_emails'] ?? '' ) ) ), 'is_email' ) ),
		);
	}

	/** Salvează setările și textele SMS venite din Setări; raportul zilnic se reprogramează dacă i s-a schimbat ora. */
	public static function save_from_settings( array $settings, array $templates ): void {
		$old    = SCP_SMS::settings();
		$values = self::clean_settings( $settings );
		$values['templates'] = self::clean_templates( $templates );
		SCP_SMS::save( $values );
		if ( SCP_SMS::has_scheduler() && ( $old['report_time'] !== $values['report_time'] || (int) $old['report_enabled'] !== $values['report_enabled'] ) ) {
			as_unschedule_all_actions( 'scp_sms_report', array(), SCP_SMS::GROUP );
			SCP_SMS::schedule_report();
		}
	}

	/** Rândurile cu setările SMS pentru pagina Setări (câmpurile au prefixul sms[...]). */
	public static function settings_rows(): void {
		$s    = SCP_SMS::settings();
		$mode = ! $s['enabled'] ? 'off' : ( $s['test_mode'] ? 'test' : 'on' );
		$test = admin_url( 'admin.php?page=' . self::SLUG );
		?>
		<table class="form-table" role="presentation">
			<tr><th>Trimitere automată</th><td>
				<label><input type="radio" name="sms[mode]" value="off" <?php checked( $mode, 'off' ); ?>> Oprită</label><br>
				<label><input type="radio" name="sms[mode]" value="test" <?php checked( $mode, 'test' ); ?>> <strong>Mod test:</strong> doar către numerele de mai jos (clienții nu primesc nimic)</label><br>
				<label><input type="radio" name="sms[mode]" value="on" <?php checked( $mode, 'on' ); ?>> Către toți clienții</label>
				<p class="description">Pași: întâi testul de livrare pe rețele (pe <a href="<?php echo esc_url( $test . '#test' ); ?>">pagina SMS</a>), apoi „Mod test” și o programare de probă pe numărul tău, apoi „Către toți clienții”.</p>
			</td></tr>
			<tr><th><label for="sms-test-numbers">Numere de test</label></th><td><input type="text" class="large-text" name="sms[test_numbers]" id="sms-test-numbers" value="<?php echo esc_attr( (string) $s['test_numbers'] ); ?>" placeholder="ex. 0755 215 135, 0749 270 232"><p class="description">Separate prin virgulă. În „Mod test”, doar programările cu aceste numere primesc SMS.</p></td></tr>
			<tr><th><label for="sms-address">Adresa în SMS</label></th><td><input type="text" class="regular-text" name="sms[address]" id="sms-address" value="<?php echo esc_attr( $s['address'] ); ?>"><p class="description">Variantă scurtă, fără diacritice (variabila {adresa}).</p></td></tr>
			<tr><th><label for="sms-remind-day">Reamintirea de cu o zi înainte</label></th><td>la ora <input type="time" name="sms[remind_day_time]" id="sms-remind-day" value="<?php echo esc_attr( $s['remind_day_time'] ); ?>"> în ziua dinaintea ședinței</td></tr>
			<tr><th><label for="sms-remind-hour">Reamintirea scurtă</label></th><td><input type="number" min="15" max="240" class="small-text" name="sms[remind_hour_mins]" id="sms-remind-hour" value="<?php echo esc_attr( (string) $s['remind_hour_mins'] ); ?>"> minute înainte de ședință</td></tr>
			<tr><th><?php echo SCP_Admin::channel( 'email', true ); // phpcs:ignore ?>Email de rezervă</th><td>
				<label><input type="radio" name="sms[backup_email]" value="fallback" <?php checked( $s['backup_email'], 'fallback' ); ?>> Doar dacă SMS-ul de reamintire nu ajunge</label><br>
				<label><input type="radio" name="sms[backup_email]" value="always" <?php checked( $s['backup_email'], 'always' ); ?>> La fiecare reamintire, odată cu SMS-ul</label>
			</td></tr>
			<tr><th>Raport zilnic</th><td><label><input type="checkbox" name="sms[report_enabled]" value="1" <?php checked( $s['report_enabled'] ); ?>> Trimite la ora</label> <input type="time" name="sms[report_time]" value="<?php echo esc_attr( $s['report_time'] ); ?>"> lista ședințelor de mâine și starea SMS-urilor</td></tr>
			<tr><th><label for="sms-alerts">Rapoarte și alerte către</label></th><td><input type="text" class="large-text" name="sms[alert_emails]" id="sms-alerts" value="<?php echo esc_attr( $s['alert_emails'] ); ?>" placeholder="<?php echo esc_attr( (string) SCP_Settings::get( 'admin_emails' ) ); ?>"><p class="description">Gol = adresele de la notificări. Alertele pleacă la cheie invalidă, credit terminat și la cel puțin trei eșecuri într-o oră.</p></td></tr>
		</table>
		<input type="hidden" name="sms_form" value="1">
		<?php
	}

	public static function handle_test(): void {
		self::guard( 'scp_sms_test' );
		$p    = wp_unslash( $_POST ); // phpcs:ignore
		$nums = array();
		foreach ( self::NETWORKS as $k => $label ) {
			$v = trim( (string) ( $p['num'][ $k ] ?? '' ) );
			if ( '' !== $v ) {
				$nums[ $k ] = $v;
			}
		}
		if ( ! $nums ) {
			self::back( 'Completează cel puțin un număr.', '#test' );
		}
		$ids = SCP_SMS::send_test( $nums, (string) ( $p['text'] ?? '' ) );
		self::back( count( $ids ) . ' mesaje de test trimise. Statusul se actualizează când răspunde operatorul (de obicei în câteva secunde, uneori câteva minute).', '#test' );
	}

	public static function handle_samples(): void {
		self::guard( 'scp_sms_samples' );
		$p   = wp_unslash( $_POST ); // phpcs:ignore
		$num = trim( (string) ( $p['number'] ?? '' ) );
		if ( '' === $num ) {
			self::back( 'Completează numărul pe care vrei să primești mesajele.', '#probe' );
		}
		$ids = SCP_SMS::send_samples( $num );
		self::back( $ids ? count( $ids ) . ' mesaje de probă trimise pe ' . $num . '. Le vezi mai jos, în jurnal, ca „Probă: …”.' : 'Numărul nu pare corect.', '#jurnal' );
	}

	public static function handle_check(): void {
		self::guard( 'scp_sms_check' );
		global $wpdb;
		$batch = (string) get_option( 'scp_sms_last_test' );
		$ids   = $batch ? $wpdb->get_col( $wpdb->prepare( 'SELECT id FROM ' . SCP_SMS::table() . ' WHERE booking_id=0 AND type LIKE %s', 'test-' . $wpdb->esc_like( $batch ) . '-%' ) ) : array(); // phpcs:ignore
		foreach ( (array) $ids as $id ) {
			SCP_SMS::job_check( (int) $id );
		}
		self::back( 'Statusurile au fost verificate la furnizor.', '#test' );
	}

	public static function handle_resend(): void {
		self::guard( 'scp_sms_resend' );
		$id  = (int) ( $_POST['id'] ?? 0 ); // phpcs:ignore
		$row = SCP_SMS::row_by_id( $id );
		if ( ! $row || ! $row['booking_id'] ) {
			self::back( 'Mesajul nu a fost găsit.', '#jurnal' );
		}
		global $wpdb;
		$wpdb->update( SCP_SMS::table(), array( 'status' => 'retry', 'attempts' => 0, 'updated_at' => SCP_Helpers::mysql_now() ), array( 'id' => $id ) );
		SCP_SMS::job_send( (int) $row['booking_id'], (string) $row['type'], (string) $row['slot'] );
		$row = SCP_SMS::row_by_id( $id );
		self::back( 'Retrimis: ' . SCP_SMS::status_label( (string) $row['status'] ) . ( $row['last_error'] ? ' (' . $row['last_error'] . ')' : '' ), '#jurnal' );
	}

	/* ---------------------------------------------------------------- pagina */

	private static function cron_state(): array {
		$hb    = (int) get_option( 'scp_sms_heartbeat' );
		$age   = $hb ? time() - $hb : null;
		$count = 0;
		$next  = null;
		if ( SCP_SMS::has_scheduler() ) {
			$count = count( as_get_scheduled_actions( array( 'group' => SCP_SMS::GROUP, 'status' => ActionScheduler_Store::STATUS_PENDING, 'per_page' => 500 ), 'ids' ) );
			$next  = as_next_scheduled_action( 'scp_sms_report', array(), SCP_SMS::GROUP );
		}
		return array( 'heartbeat_age' => $age, 'pending' => $count, 'next_report' => $next );
	}

	public static function page(): void {
		global $wpdb;
		$s        = SCP_SMS::settings();
		$p        = SCP_SMS::provider();
		$live     = $p->is_configured();
		$credit   = $live ? $p->credit() : null;
		$senders  = $live ? $p->senders() : array();
		$cron     = self::cron_state();
		$msg      = isset( $_GET['scp_msg'] ) ? sanitize_text_field( wp_unslash( $_GET['scp_msg'] ) ) : ''; // phpcs:ignore
		$batch    = (string) get_option( 'scp_sms_last_test' );
		$tests    = $batch ? $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . SCP_SMS::table() . ' WHERE booking_id=0 AND type LIKE %s ORDER BY id', 'test-' . $wpdb->esc_like( $batch ) . '-%' ), ARRAY_A ) : array(); // phpcs:ignore
		$f_type   = isset( $_GET['t'] ) ? sanitize_key( $_GET['t'] ) : ''; // phpcs:ignore
		$f_status = isset( $_GET['s'] ) ? sanitize_key( $_GET['s'] ) : ''; // phpcs:ignore
		$where    = 'WHERE 1=1';
		if ( $f_type ) {
			$where .= $wpdb->prepare( ' AND l.type=%s', $f_type );
		}
		if ( $f_status ) {
			$where .= $wpdb->prepare( ' AND l.status=%s', $f_status );
		}
		$log = $wpdb->get_results( 'SELECT l.*, b.code, b.name FROM ' . SCP_SMS::table() . ' l LEFT JOIN ' . SCP_Install::table( 'bookings' ) . " b ON b.id=l.booking_id {$where} ORDER BY l.updated_at DESC LIMIT 150", ARRAY_A ); // phpcs:ignore
		$sample   = SCP_SMS::sample_booking();
		$wp_cron_off = defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON;
		$pill = static fn( string $text, string $kind ) => '<span class="scp-pill scp-pill--' . esc_attr( $kind ) . '">' . esc_html( $text ) . '</span>';
		?>
		<div class="wrap scp-admin scp-sms">
			<h1>SMS către clienți</h1>
			<?php if ( $msg ) : ?><div class="notice notice-info is-dismissible"><p><?php echo esc_html( $msg ); ?></p></div><?php endif; ?>

			<section class="scp-panel">
				<h2>Starea sistemului</h2>
				<table class="widefat striped scp-kv">
					<tr><th>Furnizor</th><td>SMSO · <?php echo $live ? $pill( 'Trimitere reală', 'ok' ) : $pill( 'Simulare: lipsește SCP_SMSO_API_KEY din wp-config.php', 'warn' ); // phpcs:ignore ?></td></tr>
					<?php if ( $live ) : ?>
						<tr><th>Credit</th><td><?php echo ! empty( $credit['ok'] ) ? esc_html( number_format_i18n( (float) $credit['value'], 2 ) ) : $pill( (string) ( $credit['error'] ?? 'Nu s-a putut citi' ), 'bad' ); // phpcs:ignore ?></td></tr>
					<?php endif; ?>
					<tr><th>Expeditor</th><td><?php echo defined( 'SCP_SMSO_SENDER' ) ? esc_html( SCP_SMSO_SENDER ) . ' (din wp-config.php)' : ( SCP_SMS::sender() ? esc_html( SCP_SMS::sender() ) : $pill( 'neales', 'warn' ) ); // phpcs:ignore ?></td></tr>
					<tr><th>Trimitere automată</th><td><?php echo ! $s['enabled'] ? $pill( 'Oprită', 'warn' ) : ( $s['test_mode'] ? $pill( 'Mod test: doar către ' . count( SCP_SMS::test_numbers() ) . ' numere de test', 'warn' ) : $pill( 'Pornită pentru toți clienții', 'ok' ) ); // phpcs:ignore ?></td></tr>
					<tr><th>Action Scheduler</th><td><?php echo SCP_SMS::has_scheduler() ? $pill( 'Disponibil', 'ok' ) . ' · ' . (int) $cron['pending'] . ' joburi SMS în așteptare' : $pill( 'Lipsește (vine cu WooCommerce)', 'bad' ); // phpcs:ignore ?></td></tr>
					<tr><th>Cron</th><td>
						<?php echo $wp_cron_off ? $pill( 'DISABLE_WP_CRON activ, cron real pe server', 'ok' ) : $pill( 'WP-Cron pornit de vizite (de mutat pe cron real)', 'warn' ); // phpcs:ignore ?>
						· ultima rulare a verificării: <?php echo null === $cron['heartbeat_age'] ? 'niciodată' : esc_html( human_time_diff( time() - $cron['heartbeat_age'] ) ) . ' în urmă'; ?>
						<?php if ( null !== $cron['heartbeat_age'] && $cron['heartbeat_age'] > 30 * MINUTE_IN_SECONDS ) : ?><?php echo ' ' . $pill( 'cronul pare oprit', 'bad' ); // phpcs:ignore ?><?php endif; ?>
					</td></tr>
					<tr><th>Raport zilnic</th><td><?php echo $s['report_enabled'] ? 'la ' . esc_html( $s['report_time'] ) . ( $cron['next_report'] ? ', următorul ' . esc_html( wp_date( 'j M, H:i', (int) $cron['next_report'] ) ) : '' ) : 'oprit'; ?> · către <?php echo esc_html( implode( ', ', SCP_SMS::alert_recipients() ) ?: 'nimeni' ); ?></td></tr>
				</table>
			</section>

			<section class="scp-panel" id="probe">
				<h2>Toate mesajele, acum, pe numărul tău</h2>
				<p class="description">Trimite imediat câte un exemplu din fiecare mesaj automat (<?php echo esc_html( implode( ', ', SCP_SMS::SAMPLES ) ); ?>), cu textele din Setări → Emailuri și SMS și o programare de exemplu (Alexandra, 25.11, ora 17:00). Nu ține cont de „Trimitere automată”, nu atinge programările și consumă câte un SMS pentru fiecare mesaj (<?php echo (int) count( SCP_SMS::SAMPLES ); ?> în total). Linkul de plată din primul mesaj e de exemplu și nu duce la o programare.</p>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="scp-inline-form" onsubmit="return confirm('Trimit <?php echo (int) count( SCP_SMS::SAMPLES ); ?> SMS-uri de probă pe acest număr?');">
					<?php wp_nonce_field( 'scp_sms_samples' ); ?><input type="hidden" name="action" value="scp_sms_samples">
					<?php $first = SCP_SMS::test_numbers()[0] ?? ''; ?>
					<p><label for="sample-number"><b>Numărul tău</b></label><br><input type="tel" class="regular-text" id="sample-number" name="number" value="<?php echo esc_attr( $first ? SCP_Helpers::normalize_phone( $first ) : '' ); ?>" placeholder="07xx xxx xxx" required>
					<button class="button button-primary">Trimite toate mesajele</button></p>
				</form>
			</section>

			<section class="scp-panel" id="test">
				<h2>Test de livrare pe rețele</h2>
				<p class="description">Înainte de pornire, trimite câte un mesaj pe un număr din fiecare rețea. Rețeaua reală o raportează operatorul la livrare (coloana „Rețea detectată”), așa că poți verifica și dacă numărul a fost portat.</p>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<?php wp_nonce_field( 'scp_sms_test' ); ?><input type="hidden" name="action" value="scp_sms_test">
					<div class="scp-grid4">
						<?php foreach ( self::NETWORKS as $k => $label ) : ?>
							<p><label for="num-<?php echo esc_attr( $k ); ?>"><b><?php echo esc_html( $label ); ?></b></label><br><input type="tel" class="regular-text" id="num-<?php echo esc_attr( $k ); ?>" name="num[<?php echo esc_attr( $k ); ?>]" placeholder="07xx xxx xxx"></p>
						<?php endforeach; ?>
					</div>
					<p><label for="test-text">Text (opțional)</label><br><input type="text" class="large-text" id="test-text" name="text" maxlength="160" placeholder="Test SMS Stefan Chelmu: daca ai primit mesajul, livrarea pe reteaua ta functioneaza."></p>
					<p><button class="button button-primary">Trimite testul</button></p>
				</form>
				<?php if ( $tests ) : ?>
					<?php
					$ok_nets = array();
					foreach ( $tests as $t ) {
						if ( 'delivered' === $t['status'] ) {
							$ok_nets[ $t['slot'] ] = true;
						}
					}
					?>
					<h3>Ultimul test <?php echo count( $ok_nets ) === count( self::NETWORKS ) ? $pill( 'Livrat pe toate cele patru rețele', 'ok' ) : $pill( count( $ok_nets ) . ' din ' . count( self::NETWORKS ) . ' rețele confirmate', 'warn' ); // phpcs:ignore ?></h3>
					<table class="widefat striped">
						<thead><tr><th>Rețea</th><th>Număr</th><th>Status</th><th>Rețea detectată</th><th>Cost</th><th>Trimis</th><th>Livrat</th><th>Eroare</th></tr></thead>
						<?php foreach ( $tests as $t ) : ?>
							<tr><td><?php echo esc_html( self::NETWORKS[ $t['slot'] ] ?? $t['slot'] ); ?></td><td><?php echo esc_html( $t['phone'] ); ?></td><td><?php echo esc_html( SCP_SMS::status_label( $t['status'] ) ); ?></td><td><?php echo esc_html( SCP_SMS::network( $t['mcc'], $t['mnc'] ) ?: '–' ); ?></td><td><?php echo null !== $t['cost'] ? esc_html( (string) (float) $t['cost'] ) : '–'; ?></td><td><?php echo esc_html( (string) $t['sent_at'] ); ?></td><td><?php echo esc_html( (string) $t['delivered_at'] ); ?></td><td><?php echo esc_html( $t['last_error'] ); ?></td></tr>
						<?php endforeach; ?>
					</table>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top:10px"><?php wp_nonce_field( 'scp_sms_check' ); ?><input type="hidden" name="action" value="scp_sms_check"><button class="button">Verifică statusul acum</button></form>
				<?php endif; ?>
			</section>

			<section class="scp-panel" id="setari">
				<h2>Integrare SMSO</h2>
				<p class="description">Trimiterea automată, numerele de test, reamintirile și textele SMS sunt în <a href="<?php echo esc_url( admin_url( 'admin.php?page=scp-settings#sms' ) ); ?>">Setări → SMS</a> și <a href="<?php echo esc_url( admin_url( 'admin.php?page=scp-settings#emailuri' ) ); ?>">Setări → Emailuri și SMS</a>. Aici rămân doar legătura cu SMSO, testele și jurnalul.</p>
				<?php if ( defined( 'SCP_SMSO_SENDER' ) ) : ?>
					<p>Expeditorul e setat în wp-config.php: <b><?php echo esc_html( (string) SCP_SMSO_SENDER ); ?></b>.</p>
				<?php else : ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<?php wp_nonce_field( 'scp_sms_save' ); ?><input type="hidden" name="action" value="scp_sms_save">
					<table class="form-table" role="presentation">
						<tr><th><label for="sender">Expeditor</label></th><td>
							<?php if ( $senders ) : ?>
								<select name="sender" id="sender"><option value="">— alege —</option><?php foreach ( $senders as $sd ) : ?><option value="<?php echo esc_attr( $sd['id'] ); ?>" <?php selected( $s['sender'], $sd['id'] ); ?>><?php echo esc_html( $sd['name'] . ( null !== $sd['price'] ? ' · ' . $sd['price'] . ' / mesaj' : '' ) ); ?></option><?php endforeach; ?></select>
							<?php else : ?>
								<input type="text" class="regular-text" name="sender" id="sender" value="<?php echo esc_attr( $s['sender'] ); ?>"><p class="description">Lista expeditorilor apare aici după ce cheia SMSO e în wp-config.php.</p>
							<?php endif; ?>
						</td></tr>
					</table>
					<p><button class="button button-primary">Salvează expeditorul</button></p>
				</form>
				<?php endif; ?>
			</section>

			<section class="scp-panel" id="jurnal">
				<h2>Jurnal</h2>
				<form method="get" class="scp-filters"><input type="hidden" name="page" value="<?php echo esc_attr( self::SLUG ); ?>">
					<select name="t"><option value="">Toate tipurile</option><?php foreach ( SCP_SMS::TYPES as $t ) : ?><option value="<?php echo esc_attr( $t ); ?>" <?php selected( $f_type, $t ); ?>><?php echo esc_html( SCP_SMS::type_label( $t ) ); ?></option><?php endforeach; ?></select>
					<select name="s"><option value="">Toate statusurile</option><?php foreach ( array( 'scheduled', 'retry', 'sent', 'dispatched', 'delivered', 'undelivered', 'expired', 'error', 'failed', 'skipped', 'cancelled' ) as $st ) : ?><option value="<?php echo esc_attr( $st ); ?>" <?php selected( $f_status, $st ); ?>><?php echo esc_html( SCP_SMS::status_label( $st ) ); ?></option><?php endforeach; ?></select>
					<button class="button">Filtrează</button>
				</form>
				<table class="widefat striped scp-smslog">
					<thead><tr><th>Actualizat</th><th>Programare</th><th>Tip</th><th>Telefon</th><th>Status</th><th>Programat pentru</th><th>Încercări</th><th>Rețea</th><th>Detalii</th><th></th></tr></thead>
					<?php if ( ! $log ) : ?><tr><td colspan="10">Niciun mesaj încă.</td></tr><?php endif; ?>
					<?php foreach ( $log as $r ) : ?>
						<tr>
							<td><?php echo esc_html( $r['updated_at'] ); ?></td>
							<td><?php echo $r['booking_id'] ? '<a href="' . esc_url( admin_url( 'admin.php?page=scp-bookings&booking=' . (int) $r['booking_id'] ) ) . '">' . esc_html( (string) $r['code'] ) . '</a> ' . esc_html( (string) $r['name'] ) : '–'; ?></td>
							<td><?php echo esc_html( SCP_SMS::type_label( $r['type'] ) ); ?></td>
							<td><?php echo esc_html( $r['phone'] ); ?></td>
							<td><?php echo esc_html( SCP_SMS::status_label( $r['status'] ) ); ?><?php echo $r['backup_email_at'] ? ' · email trimis' : ''; ?></td>
							<td><?php echo esc_html( (string) $r['scheduled_for'] ); ?></td>
							<td><?php echo (int) $r['attempts']; ?></td>
							<td><?php echo esc_html( SCP_SMS::network( $r['mcc'], $r['mnc'] ) ); ?></td>
							<td><?php echo esc_html( $r['last_error'] ); ?><?php if ( $r['body'] ) : ?><details><summary>text</summary><code><?php echo esc_html( $r['body'] ); ?></code></details><?php endif; ?></td>
							<td><?php if ( $r['booking_id'] && in_array( $r['status'], array( 'failed', 'undelivered', 'expired', 'error', 'skipped' ), true ) ) : ?>
								<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><?php wp_nonce_field( 'scp_sms_resend' ); ?><input type="hidden" name="action" value="scp_sms_resend"><input type="hidden" name="id" value="<?php echo (int) $r['id']; ?>"><button class="button button-small">Retrimite</button></form>
							<?php endif; ?></td>
						</tr>
					<?php endforeach; ?>
				</table>
			</section>

			<section class="scp-panel">
				<h2>Configurare pe server</h2>
				<p>În <code>wp-config.php</code>, deasupra rândului „That's all, stop editing”:</p>
				<pre class="scp-pre">define( 'SCP_SMSO_API_KEY', 'cheia-din-contul-SMSO' );
define( 'SCP_SMSO_SENDER', 'ID-ul expeditorului' ); // opțional, altfel se alege mai sus
define( 'DISABLE_WP_CRON', true );</pre>
				<p>Cron real, în crontab-ul utilizatorului site-ului (rulează la fiecare minut joburile WordPress și Action Scheduler):</p>
				<pre class="scp-pre">* * * * * cd <?php echo esc_html( untrailingslashit( ABSPATH ) ); ?> &amp;&amp; wp cron event run --due-now --quiet &gt;/dev/null 2&gt;&amp;1</pre>
				<p class="description">Fără WP-CLI: <code>* * * * * curl -s <?php echo esc_html( site_url( 'wp-cron.php?doing_wp_cron' ) ); ?> &gt;/dev/null</code>. Statusurile de livrare vin la <code><?php echo esc_html( rest_url( SCP_Rest::NS . '/sms/status' ) ); ?></code>, cu un token secret diferit pentru fiecare mesaj.</p>
			</section>
		</div>
		<style>
			.scp-sms .scp-kv th{width:200px}
			.scp-pill{display:inline-block;padding:2px 10px;border-radius:999px;font-size:12px;font-weight:600}
			.scp-pill--ok{background:#e3f1df;color:#1f5d14}.scp-pill--warn{background:#fbf1d9;color:#7a5600}.scp-pill--bad{background:#f8e1dc;color:#8a2412}
			.scp-grid4{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:0 16px}
			@media (max-width:960px){.scp-grid4{grid-template-columns:1fr 1fr}}
			.scp-smsbox{margin:0 0 18px;max-width:820px}
			.scp-smsbox textarea{margin-top:6px;font-family:Menlo,Consolas,monospace}
			.scp-smsprev{margin:4px 0 0;color:#646970}
			.scp-smscount{font-weight:700;margin-right:8px}.scp-smscount.is-over{color:#b32d2e}
			.scp-pre{background:#f6f7f7;padding:12px 14px;border:1px solid #dcdcde;overflow:auto}
			.scp-smslog details code{display:block;max-width:340px;white-space:normal}
		</style>
		<script>
		(function(){
			var vars=<?php echo wp_json_encode( SCP_SMS::vars( $sample ) ); ?>;
			function gsmlen(s){ return s.length + (s.match(/[\[\]{}\\^~|]/g)||[]).length; }
			document.querySelectorAll('.scp-smsbox').forEach(function(box){
				var ta=box.querySelector('textarea'), c=box.querySelector('.scp-smscount');
				function upd(){ var t=ta.value; Object.keys(vars).forEach(function(k){ t=t.split(k).join(vars[k]); }); var n=gsmlen(t); c.textContent=n+' / 160'; c.classList.toggle('is-over',n>160); if(/[^\x20-\x7E\n]/.test(ta.value)) c.textContent+=' · are caractere speciale, se înlocuiesc la salvare'; }
				ta.addEventListener('input',upd); upd();
			});
		})();
		</script>
		<?php
	}
}
