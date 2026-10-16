# Programările 2026 în Google Sheet (n8n)

Sheet-ul e o copie de siguranță. Sursa adevărului rămâne pluginul: programările se fac și se modifică doar din site sau din admin, nu din Sheet.

## Ce trimite pluginul

La fiecare schimbare, pluginul face un `POST` spre adresa din **Setări → Webhook** (doar `https://`), cu antetele `X-SCP-Event`, `X-SCP-Timestamp`, `X-SCP-Signature` și `X-SCP-Key` (cheia secretă, pentru Header Auth). Semnătura e `hex(HMAC-SHA256(cheie, timestamp + "." + body))`. Trimiterea se face în fundal (Action Scheduler), în ordinea evenimentelor; rezerva din cron preia ce n-a plecat în 2 minute.

În tab-ul **Programari** (scris „user entered”), textele clientului care încep cu `= + - @` primesc un apostrof în față, ca să nu devină formule. **Leads** e scris RAW, deci nu are nevoie.

| Ce se întâmplă | Eveniment |
|---|---|
| Programare nouă (site, telefon, admin) | `booking.created` |
| Orice modificare: mutare (din fișă sau din panoul calendarului), date de contact, pachet, preț, observații, trecere pe plata la studio | `booking.updated` |
| Plată online, cash, card | `booking.paid` + `booking.updated` |
| Anulare din admin sau expirare (neplătită în 48 h) | `booking.cancelled` |
| Link de plată retrimis | `booking.payment_link_sent` |
| „Trimite toate programările în Sheet” | `booking.sync` (câte una pentru fiecare programare) |
| Voucher cadou emis | `voucher.issued` |

Body-ul are `tab` (`Programari` sau `Vouchere`), `sheet` (rândul pentru **Leads**) și `sc` (rândul pentru lista lui Ștefan, **Programari**).

Coloanele din Leads sunt: Inregistrata la, Cod, Nume, Telefon, Email, Pachet, Pret, Sedinta (data si ora), Status, Plata, Sursa, Mesaj client, Observatii, Voucher, Termen plata, Platita la, Link plata, Modificata la, Slot (ascunsă).

Dacă n8n nu răspunde cu 2xx, pluginul reîncearcă la 5, 15, 60 și 180 de minute. Din **Programări → Sincronizare Sheet** se vede jurnalul și se poate retrimite tot.

## Unde rulează

- n8n: `https://automatizari.simplead.ro`. Workflow-urile se numesc „SC Programări 2026 → Google Sheet (în timp real)” și „… (verificare de noapte)”.
- Adresa webhook pentru Setări → Webhook: `https://automatizari.simplead.ro/webhook/scp-craciun-2026`.
- Pe Local, adresa webhook rămâne goală, ca testele să nu ajungă în Sheet-ul real.

## Sheet-ul „[Stefan Chelmu] Craciun 2026”

Id-ul fișierului: `1UpZ28mYV0xz6TS1Z01Lt4O48FGBz_WBkMDddG7c9UZY`. Tab-urile au aceeași structură ca în 2025:

| Tab | Ce e | Cine scrie |
|---|---|---|
| **Leads** 🔒 | Tot ce intră din site, inclusiv programările anulate. Prima coloană e momentul completării formularului. | n8n (nodul „Scrie în Leads”); noaptea e rescris complet |
| **Noiembrie / Decembrie** | Agenda pe zile: zi → 09, 11, 13, 15, 17, 19 → un rând liber. | B–G (Nume, Telefon apelabil, Mail, Pachet, Preț, Plată) vin automat din Leads, după Slot, și sunt blocate. Apar doar programările confirmate (plătite sau cu plata la studio); rândul se golește la anulare, iar cele care așteaptă plata online nu apar. H–J (Observații, Nr. bon/factură, Link galerie) sunt ale lui Ștefan. K–L sunt ascunse (data și cheia slotului). |
| **Programari** | Copia lui Leads în care Ștefan poate scrie: aceleași coloane, liste derulante la Pachet, Status, Plata, Sursa, plus coloanele lui Etapa (Programat / Editat / Livrat), Nr. bon/factura, Link galerie. | n8n (nodul „Scrie în Programari (Stefan)”, potrivire pe Cod) + Ștefan |
| **Anulari** 🔒 | QUERY peste Leads: doar anulatele. | automat |
| **Vouchere** (ascuns) 🔒 | Voucherele emise: Cod voucher, Pachet, Valoare, Cumparator, Email, Telefon, Pentru, Email destinatar, Telefon destinatar, Comanda, Valabil pana, Status. Destinatarul vine din câmpurile opționale „Cadou pentru” din checkout. | n8n |

În **Programari**, site-ul rescrie coloanele din Leads la fiecare schimbare (deci o modificare făcută acolo de Ștefan se pierde la următoarea schimbare din site). Etapa, Nr. bon/factura și Link galerie nu sunt atinse niciodată.

Ce rămâne de știut:

- **Notițele de pe Noiembrie / Decembrie țin de rând, nu de client.** Dacă o programare se mută sau se anulează, notițele lui Ștefan rămân pe ora veche.
- **Sheet-ul nu blochează ore.** Programările se fac doar din admin.
- **Protecții:** pot scrie în zonele blocate doar proprietarul fișierului și andrei.panait@simplead.ro, contul credențialei Google din n8n. Dacă n8n trece pe alt cont, contul nou trebuie adăugat la protecții.
- **Fișierul „[DEMO] [Stefan Chelmu] Craciun 2026”** e doar pentru prezentare și se poate șterge.

## Fișierele de import

Atenție: fișierele JSON de aici sunt prima variantă. Versiunea bună e cea din n8n, care are în plus:
- nodurile „Scrie în Leads”, „Rând SC” și „Scrie în Programari (Stefan)”;
- tab-ul „Leads” în verificarea de noapte.

Pentru o copie la zi, exportă workflow-urile din n8n.

- `scp-sheet-live.json`: workflow-ul 1.
- `scp-sheet-noapte.json`: workflow-ul 2.

Ambele cer două credențiale în n8n:

- **Google Sheets (OAuth2):** contul pe care stă Sheet-ul.
- **Header Auth „SCP cheie (X-SCP-Key)”:** numele antetului `X-SCP-Key`, valoarea = **Cheie secretă** din Setări → Webhook. Doar pentru workflow-ul în timp real (site → n8n).
- **Header Auth „SCP citire Sheet (X-SCP-Key)”:** același antet, valoarea = **Cheie de citire** din Setări → Webhook (din 1.3.0, alta decât cheia secretă). Doar pentru nodul HTTP din verificarea de noapte; `/scp/v1/sheet` nu mai acceptă cheia secretă.

## Workflow 1: în timp real

1. **Webhook**, metoda POST, cu „Respond: Immediately”.
2. Opțional, **Code**: verifici semnătura cu cheia secretă din Setări.
3. **Switch** după `{{$json.body.tab}}`:
   - `Programari` → **Google Sheets → Append or Update Row** pe tab-ul „Leads” (datele din `sheet`), apoi un nod Code care scoate `sc` și încă un Append or Update Row pe „Programari”. Coloana de potrivire e `Cod`, iar valorile vin din `{{$json.body.sheet}}`, cu mapare automată după numele coloanelor.
   - `Vouchere` → la fel, pe tab-ul „Vouchere”, cu coloana de potrivire `Cod voucher`. După ce se schimbă coloanele tab-ului, nodul trebuie reîmprospătat în n8n (mapare Manual → Automatic), altfel dă „Column names were updated”.

Rândul unei programări se găsește după Cod, deci orice schimbare îl actualizează, nu adaugă altul. O programare anulată rămâne cu Status „Anulată (…)”. Cine ia ora eliberată are alt cod, deci apare pe alt rând.

## Workflow 2: verificarea de noapte

Prinde și ce n-a ajuns din workflow-ul 1, de exemplu dacă n8n a fost oprit mai mult de 4 ore.

1. **Schedule**, zilnic la 03:00.
2. **HTTP Request**: `GET https://stefanchelmu.ro/wp-json/scp/v1/sheet`, cu credențiala „SCP citire Sheet” (antetul `X-SCP-Key: <cheia de citire>`). Răspunsul are `rows`, cu toate programările din toate statusurile.
3. **Google Sheets → Clear** pe tab-ul „Leads” (fără rândul 1), apoi **Append** cu `rows`.

Tab-ul „Leads” se rescrie în fiecare noapte, deci nu se scrie de mână în el (e blocat oricum). Lista lui Ștefan, „Programari”, nu e atinsă de verificarea de noapte.

## Pornire

1. Creezi fișierul „[Stefan Chelmu] Craciun 2026”, cu tab-urile din tabelul de mai sus (deja făcut).
2. Construiești cele două workflow-uri și le activezi.
3. În **Setări → Webhook**: pui adresa webhook-ului, păstrezi bifate evenimentele și apeși „Trimite un test”.
4. În **Programări → Sincronizare Sheet**: „Trimite toate programările în Sheet”. Pleacă în fundal, câte 60 la 5 minute.
