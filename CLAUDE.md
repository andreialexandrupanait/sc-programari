# SC Programări Crăciun

Plugin WordPress pentru programările la ședințele foto de Crăciun ale studioului Ștefan Chelmu (stefanchelmu.ro, Galați). Sezonul 2026: 1 noiembrie – 24 decembrie. Landing page: `/sedinte-foto-de-craciun/`. Interfața, textele și comentariile din cod sunt în română, cu diacritice (ș, ț cu virgulă).

## Reguli

- **Netopia e live pe site.** Nu face plăți reale și nu porni fluxuri care încasează bani. Testează doar pe Local.
- Cheile și tokenurile stau doar în `wp-config.php`, niciodată în cod sau în repo: `SCP_SMSO_API_KEY`, `SCP_SMSO_SENDER`, `SCP_SMS_WEBHOOK_SECRET`, `SCP_GITHUB_TOKEN` (sau `SC_WFG_GITHUB_TOKEN`). Nu le afișa și nu le copia în răspunsuri.
- Fără cifre inventate în texte (prețuri, statistici); prețurile vin din setări (`/wp-json/scp/v1/config`).
- Orice versiune nouă: crește `Version:` din header **și** `SCP_VERSION` în `sc-programari.php`. Schimbările de tabele cresc `SCP_DB_VERSION` și trec prin `SCP_Install::maybe_upgrade()`.

## Structură

- `sc-programari.php` – bootstrap, constante, Plugin Update Checker.
- `includes/`
  - `class-install.php` – tabelele (prefix `scp_`): `bookings` (index UNIQUE pe `active_slot`, ca să nu existe două programări active pe același slot), `overrides`, `vouchers`, `sms_log` (UNIQUE pe `booking_id, type`), `webhook_log`.
  - `class-settings.php` – opțiunea `scp_settings` (prețuri, reducerea până pe 15 noiembrie, `payment_url`, `voucher_url`, `voucher_prefix` CHELMU, adresa studioului, șabloanele de email).
  - `class-availability.php`, `class-pricing.php`, `class-bookings.php` (inclusiv `sweep()` pentru rezervările neplătite în 48 h), `class-vouchers.php` (coduri `CHELMU-{CUVÂNT}-XXXXXX`).
  - `class-woocommerce.php` – legătura cu CartFlows Pro: pagina de plată `/plata-craciun-2026/` (cart setat cu `cartflows_skip_configure_cart`) și voucherele direct în checkout `/voucher-craciun-2026/?pachet=`.
  - `class-mailer.php` + `templates/email.php`, `class-webhook.php` (n8n), `class-rest.php` (namespace `scp/v1`), `class-frontend.php` (shortcode-uri `[sc_programare]`, `[sc_pret]`, `[sc_oferta]`), `class-admin.php` (meniul Programări).
  - `class-sms.php`, `class-sms-admin.php`, `sms/` – SMS-urile (vezi mai jos).
- `assets/` – CSS/JS pentru front și admin; fontul „Chelmu Rounded” (Fredoka One completat cu ă, ș, ț; licența OFL cere numele schimbat).
- `lib/plugin-update-checker/` – bibliotecă externă, nu se modifică.
- `dev/` și `tools/` **nu intră în ZIP**:
  - `dev/landing-page/` – cele patru părți ale landing page-ului și `build.py` (produce `build/lp-complet.html` pentru un singur widget Elementor de tip **Shortcode**, nu HTML, și `build/lp-elementor.json`).
  - `dev/cartflows/setup.php` – creează flow-urile CartFlows 2026 clonând flow-ul din 2025.
  - `tools/sc-migrare-craciun-2026/` – plugin de unică folosință care a mutat configurarea de pe Local pe live.

## SMS (SMSO)

- Furnizorul e izolat în `includes/sms/class-sms-provider-smso.php`, în spatele interfeței `SCP_SMS_Provider`. Un alt furnizor = o clasă nouă, nimic altceva. Fără cheie API rulează `SCP_SMS_Provider_Simulated` (numerele care se termină în 0000 simulează eroare de rețea, cele în 9999 un mesaj nelivrat).
- Patru mesaje: la înscriere (cu link scurt `/p/{token}` și termenul de 48 h), la plată, cu o zi înainte (ora din setări, implicit 18:00) și cu o oră înainte. Rezervările cu voucher primesc direct confirmarea și reminderele.
- Programare cu Action Scheduler: `scp_sms_send`, `scp_sms_check`, `scp_sms_retry` (hook separat, fiindcă acțiunea în curs contează ca „programată”), `scp_sms_recover` (la 15 minute) și `scp_sms_report` (raportul zilnic). Anularea șterge acțiunile, mutarea slotului le reprogramează.
- Șabloanele sunt fără diacritice, sub 160 de caractere GSM-7, cu „tu”, `type=transactional`; se editează în Programări → SMS (opțiunea `scp_sms_settings`).
- Webhook de status: `/wp-json/scp/v1/sms/status?m=&t=`, cu token HMAC per mesaj.
- Pe live, cronul real rulează din panoul de hosting în fiecare minut (`wp-cli cron event run --due-now`), cu `DISABLE_WP_CRON` activ.

## Testare

- Pe Local (stefanchelmu.local), cu un clone al acestui repo în `wp-content/plugins/sc-programari`.
- Verifică landing page-ul pe desktop (1440 și 1920) și pe telefon (390): formularul, calendarul, prețul de 450/550 și bara de jos.
- Fluxul SMS se testează în simulare: înscriere, plată, mutare, anulare, plată cash, recuperare, webhook (403 fără token, 200 cu token), reîncercări, email de backup, raport.
- `php -l` pe fișierele modificate înainte de commit.

## Release

Push pe `main` cu versiunea crescută → `.github/workflows/release.yml` face release-ul `vX.Y.Z` cu `sc-programari.zip` → WordPress oferă update-ul în pagina de pluginuri. Fără versiune crescută nu se face release.
