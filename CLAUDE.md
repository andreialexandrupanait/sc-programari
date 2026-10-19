# SC Programări Crăciun

Plugin WordPress pentru programările la ședințele foto de Crăciun ale studioului Ștefan Chelmu (stefanchelmu.ro, Galați). Sezonul 2026: 1 noiembrie – 24 decembrie. Landing page: `/sedinte-foto-de-craciun/`. Interfața, textele și comentariile din cod sunt în română, cu diacritice (ș, ț cu virgulă).

## Reguli

- **Netopia e live pe site.** Nu face plăți reale și nu porni fluxuri care încasează bani. Testează doar pe Local.
- Cheile și tokenurile stau doar în `wp-config.php`, niciodată în cod sau în repo: `SCP_SMSO_API_KEY`, `SCP_SMSO_SENDER`, `SCP_SMS_WEBHOOK_SECRET`, `SCP_GITHUB_TOKEN` (sau `SC_WFG_GITHUB_TOKEN`). Nu le afișa și nu le copia în răspunsuri.
- Fără cifre inventate în texte (prețuri, statistici); prețurile vin din setări (`/wp-json/scp/v1/config`).
- Orice versiune nouă: crește `Version:` din header **și** `SCP_VERSION` în `sc-programari.php`. Schimbările de tabele cresc `SCP_DB_VERSION` și trec prin `SCP_Install::maybe_upgrade()`.

## Securitate și robustețe (din 1.3.0)

- Rutele publice REST se redeschid (față de blocarea SimpleAD) doar în `SCP_Rest::allow_public_routes`, după ruta reală și doar pentru eroarea `rest_not_logged_in`. Nu verifica niciodată `REQUEST_URI` cu `str_contains`.
- Formularul public: limită pe IP (`SCP_Helpers::rate_key`, IPv6 pe /64; `CF-Connecting-IP` contează doar de la Cloudflare sau proxy local), cel mult 2 programări neplătite pe telefon/email, plafon global pe oră cu alertă, 20 de încercări de voucher pe oră. SMS-urile automate pleacă doar pe `+407…`.
- Plățile: `mark_paid_online` e atomic, reactivează o programare expirată dacă ora e liberă (altfel alertă, fără confirmare), `sweep()` nu anulează cât timp există o comandă începută în ultimele 30 de minute, rambursarea trece programarea pe neplătită și alertează. Hook-urile din IPN sunt în `try/catch (\Throwable)`.
- Emailurile (`scp_mail_job`) și webhook-urile (`scp_webhook_deliver`) pleacă prin Action Scheduler; filtrul `scp_async` le face sincrone (în teste).
- Cheia `webhook_secret` doar scrie în n8n; `/sheet` cere `sheet_key`. Textele clientului spre Sheet „user entered” și CSV trec prin `SCP_Webhook::cell_safe()`.
- Date personale: `SCP_Privacy` (export/ștergere din Unelte → Confidențialitate) și curățenia `scp_cleanup` după 31 ianuarie anul următor sezonului.
- Release-ul verifică `php -l`, versiunea și fișierele din `assets/`; update-ul se instalează doar din ZIP-ul release-ului.

## Structură

- `sc-programari.php` – bootstrap, constante, Plugin Update Checker.
- `includes/`
  - `class-install.php` – tabelele (prefix `scp_`): `bookings` (index UNIQUE pe `active_slot`, ca să nu existe două programări active pe același slot), `overrides`, `vouchers`, `sms_log` (UNIQUE pe `booking_id, type`), `webhook_log`.
  - `class-settings.php` – opțiunea `scp_settings` (prețuri, reducerea până pe 15 noiembrie, `payment_url`, `voucher_url`, `voucher_prefix` CHELMU, adresa studioului, șabloanele de email).
  - `class-availability.php`, `class-pricing.php`, `class-bookings.php` (inclusiv `sweep()` pentru rezervările neplătite în 48 h), `class-vouchers.php` (coduri `CHELMU-{CUVÂNT}-XXXXXX`).
  - `class-woocommerce.php` – legătura cu CartFlows Pro: pagina de plată `/plata-craciun-2026/` (cart setat cu `cartflows_skip_configure_cart`) și voucherele direct în checkout `/voucher-craciun-2026/?pachet=`. În checkout-ul voucherului apar câmpurile opționale „Cadou pentru” (nume, email, telefon), salvate în comandă ca `_scp_recipient_*` și copiate în voucher (`recipient_*`, plus `buyer_phone`).
  - `class-mailer.php` + `templates/email.php`, `class-webhook.php` (n8n și rândul pentru Google Sheet, `sheet_row()`), `class-rest.php` (namespace `scp/v1`; `/sheet` cere antetul `X-SCP-Key`), `class-frontend.php` (shortcode-uri `[sc_programare]`, `[sc_pret]`, `[sc_oferta]`), `class-admin.php` (meniul Programări: Panou, Programare rapidă pentru telefon, listă, calendar, Sincronizare Sheet). Calendarul: grila în stânga, iar la click pe o oră panoul din dreapta (detalii, mutare pe alt slot liber, încasare cash/card, plata la studio, anulare); pe telefon panoul e un sertar. Rutele: `GET admin/booking/{id}`, `POST admin/booking/{id}/move|cancel|pay|studio`.
  - Ședințe gratuite: `payment_status = free` („Gratuită (oferită)”, doar din admin: Programare rapidă sau fișă). E în `SCP_Helpers::PAID` (nu expiră, primește confirmarea pe SMS cu șablonul `free` și reamintirile), are prețul 0, iar valoarea pachetului rămâne în `list_price`/`discount` cu `discount_source = free`. În calendar e lila; în Panou apare separat („Gratuite: … în valoare de …”) și nu intră în „Încasat”.
  - Cifrele principale vin din `SCP_Bookings::summary()` (încasat, de încasat la studio, așteaptă plata, se anulează în 24 h, anulate, gratuite, azi/mâine). Le folosesc widgetul „Programări Crăciun” din Panoul de control WordPress (`SCP_Admin::dashboard_widget()`, primul în coloană) și Panoul pluginului, care ocupă tot ecranul (`scp-admin--full`). În listă, vederea „Se anulează automat” (`view=awaiting`) e ordonată după `pay_by`.
  - Calendarul din admin are coloane doar pentru programul standard; orele adăugate într-o singură zi stau în coloana „Ore extra”.
  - Plata la studio: programările confirmate fără plată online au `meta.pay_at = studio`, primesc SMS-urile ca cele plătite și se încasează cash sau card (`SCP_Bookings::collect()`). Starea afișată vine din `SCP_Bookings::pay_state()`.
  - `class-sms.php`, `class-sms-admin.php`, `sms/` – SMS-urile (vezi mai jos).
  - `class-privacy.php` – exportul și ștergerea datelor personale, curățenia de după sezon.
- `assets/` – CSS/JS pentru front și admin; fontul „Chelmu Rounded” (Fredoka One completat cu ă, ș, ț; licența OFL cere numele schimbat).
- `lib/plugin-update-checker/` – bibliotecă externă, nu se modifică.
- `dev/` și `tools/` **nu intră în ZIP**:
  - `dev/landing-page/` – cele patru părți ale landing page-ului și `build.py` (produce `build/lp-complet.html` pentru un singur widget Elementor de tip **Shortcode**, nu HTML, și `build/lp-elementor.json`).
  - `dev/cartflows/setup.php` – creează flow-urile CartFlows 2026 clonând flow-ul din 2025.
  - `dev/n8n/README.md` – cum ajung programările în Google Sheet-ul 2026 (webhook în timp real + verificarea de noapte).
  - `tools/sc-migrare-craciun-2026/` – plugin de unică folosință care a mutat configurarea de pe Local pe live.

## SMS (SMSO)

- Furnizorul e izolat în `includes/sms/class-sms-provider-smso.php`, în spatele interfeței `SCP_SMS_Provider`. Un alt furnizor = o clasă nouă, nimic altceva. Fără cheie API rulează `SCP_SMS_Provider_Simulated` (numerele care se termină în 0000 simulează eroare de rețea, cele în 9999 un mesaj nelivrat).
- Mesaje: la înscriere (cu link scurt `/p/{token}` și termenul de 48 h), ultimul apel la plată (`pay_reminder`, cu `last_call_minutes` înainte de expirare; emailul „Ultimul apel” pleacă doar ca rezervă), la plată (sau confirmarea cu voucher / plata la studio), la mutarea din admin cu „Anunță clientul” (tipul `moved`, se retrimite la fiecare mutare), cu o zi înainte (ora din setări, implicit 18:00) și cu o oră înainte. Rezervările cu voucher primesc direct confirmarea și reminderele.
- Reamintirile neplătite vin din `SCP_Bookings::sweep()`: emailuri la orele din `pay_reminders` (implicit „24, 12, 3”, marcate în `meta.reminded`) și ultimul apel (`meta.last_call_at`). Momentul îl dă `nudge_due()`: noaptea (22–08) se mută la 08:00 sau la 21:30, cu o seară înainte. Prelungirea termenului golește marcajele.
- Programare cu Action Scheduler: `scp_sms_send`, `scp_sms_check`, `scp_sms_retry` (hook separat, fiindcă acțiunea în curs contează ca „programată”), `scp_sms_recover` (la 15 minute) și `scp_sms_report` (raportul zilnic). Anularea șterge acțiunile, mutarea slotului le reprogramează.
- Șabloanele sunt fără diacritice, sub 160 de caractere GSM-7, cu „tu”, `type=transactional`; se editează în Setări → „Emailuri și SMS”, pe perioade, lângă emailuri (opțiunea `scp_sms_settings`, salvată prin `SCP_SMS_Admin::clean_templates()`); setările SMS (pornire/Mod test, numere de test, adresă, reamintiri, email de rezervă, raport) sunt în Setări → SMS (`SCP_SMS_Admin::settings_rows()` / `save_from_settings()`). Pagina Programări → SMS rămâne doar pentru integrare: starea SMSO, expeditorul, testele și jurnalul.
- Webhook de status: `/wp-json/scp/v1/sms/status?m=&t=`, cu token HMAC per mesaj.
- Pe live, cronul real rulează din panoul de hosting în fiecare minut (`wp-cli cron event run --due-now`), cu `DISABLE_WP_CRON` activ.

## Testare

- Pe Local (stefanchelmu.local), cu un clone al acestui repo în `wp-content/plugins/sc-programari`.
- Verifică landing page-ul pe desktop (1440 și 1920) și pe telefon (390): formularul, calendarul, prețul de 450/550 și bara de jos.
- Fluxul SMS se testează în simulare: înscriere, plată, mutare, anulare, plată cash, recuperare, webhook (403 fără token, 200 cu token), reîncercări, email de backup, raport.
- `php -l` pe fișierele modificate înainte de commit.

## Lucru pe Local

Repo-ul e clonat direct în `wp-content/plugins/sc-programari` al site-ului din Local (stefanchelmu.local). Pe o copie cu `.git`, Plugin Update Checker nu pornește, ca un update din WordPress să nu înlocuiască folderul. Înainte de lucru: `git pull`. Pe Local nu există cheie SMSO, deci SMS-urile rulează în simulare.

## Release

Push pe `main` cu versiunea crescută → `.github/workflows/release.yml` face release-ul `vX.Y.Z` cu `sc-programari.zip` → WordPress oferă update-ul în pagina de pluginuri. Fără versiune crescută nu se face release.
