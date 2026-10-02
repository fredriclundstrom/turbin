# Turbin Transfer – installation

Sidan (`index.html`) och backenden (`api/`) ska ligga på Loopia. På GitHub Pages körs sidan som förhandsvisning,
eftersom PHP inte finns där.

Så här fungerar det:

- **Kund → Turbin:** Kunden släpper filer på sidan. PHP skapar mappen `Turbin/Transfer/Inkommande/<datum> <namn> (<företag>) – <projekt>`
  i Drive och öppnar en uppladdning per fil. Webbläsaren skickar filerna direkt till Google i delar om 8 MB.
  Den valda personen på Turbin får ett mejl med länk till mappen, och kunden får ett kvitto.
- **Turbin → kund:** Personalen loggar in med sitt Google-konto, laddar upp till `Turbin/Transfer/Utgående/…` och får
  länken `transfer.turbin.se/?d=<id>`. Kunden hämtar via PHP, och länken slutar gälla efter 7, 14 eller 30 dagar.
- Inga filer i Drive delas publikt. Servicekontot kommer bara åt mappen `Turbin/Transfer`.

---

## 1. Drive

1. Öppna den delade enheten, gå till mappen **Turbin** och skapa mappen **Transfer** (Ny → Mapp).
2. Öppna mappen. Mappens ID är sista delen av adressen:
   `drive.google.com/drive/folders/`**`1AbC…xyz`**. Spara ID:t, det ska in i inställningarna.
   (`Inkommande` och `Utgående` skapas automatiskt första gången.)

## 2. Google Cloud (som Workspace-admin)

Gå till https://console.cloud.google.com.

1. **Nytt projekt:** Klicka på projektväljaren högst upp → *New project* → namn `Turbin Transfer`.
2. **Aktivera API:er:** *APIs & Services → Library* → sök fram och aktivera **Google Drive API** och **Gmail API**.
3. **Servicekonto:** *IAM & Admin → Service accounts → Create service account*.
   - Namn: `turbin-transfer`. Hoppa över rollerna (inga behövs) → *Done*.
   - Öppna kontot och spara två saker: **e-postadressen** (`turbin-transfer@….iam.gserviceaccount.com`)
     och **Unique ID**, en lång sifferkod.
   - Fliken *Keys* → *Add key → Create new key → JSON*. En fil laddas ner. **Den är en nyckel: maila den inte och
     lägg den inte i git.**
4. **Inloggningsskärm:** *APIs & Services → OAuth consent screen* → User type **Internal** → appnamn `Turbin Transfer`,
   supportadress, klart. "Internal" betyder att bara konton på turbin.se kan logga in.
5. **Inloggning för personalen:** *APIs & Services → Credentials → Create credentials → OAuth client ID*
   - Application type: **Web application**, namn `Turbin Transfer`.
   - *Authorized JavaScript origins*: `https://transfer.turbin.se` och testadressen från steg 5 nedan,
     till exempel `https://nytransfer.turbin.se`.
   - Spara **Client ID**, det som slutar på `.apps.googleusercontent.com`.

## 3. Dela Transfer-mappen med servicekontot

1. Högerklicka på **Turbin/Transfer** i Drive → *Dela* → klistra in servicekontots e-postadress
   → roll **Innehållsansvarig** (Content manager) → avmarkera "Meddela" → *Dela*.
2. Går det inte för att adressen ligger utanför turbin.se behöver extern delning tillåtas för den delade enheten:
   - Admin console (admin.google.com) → *Apps → Google Workspace → Drive och Dokument → Delningsinställningar*:
     tillåt delning utanför organisationen, eller vitlista domänen `gserviceaccount.com` om ni har tillåtelselistor.
   - I den delade enheten: *Inställningar för delad enhet* → tillåt åtkomst för personer utanför Turbin.

## 4. Mejl via Gmail (domänomfattande delegering)

Mejlen skickas som en riktig adress på turbin.se, så de inte hamnar i skräpposten.

1. admin.google.com → *Säkerhet → Åtkomst- och datakontroll → API-kontroller → Hantera domänomfattande delegering*
   → *Lägg till ny*.
2. Klient-ID: servicekontots **Unique ID** (sifferkoden från steg 2.3).
   Scope: `https://www.googleapis.com/auth/gmail.send` → *Auktorisera*.
3. Avsändaren (`mail_as` i inställningarna) måste vara en riktig användare eller ett alias till en användare, till exempel
   `info@turbin.se`.

Delegeringen ger bara rätt att **skicka** mejl, inte att läsa dem.

## 5. Loopia

1. **Testadress först.** Den gamla sidan på transfer.turbin.se körs vidare under tiden.
   Kundzonen → *Lägg till → Subdomän* → `nytransfer.turbin.se` under turbin.se. Wildcard-certifikatet täcker den.
2. Ladda upp via FTP eller Loopias filhanterare till subdomänens webbmapp:
   ```
   index.html
   api/index.php
   api/lib.php
   api/.htaccess
   api/private/.htaccess
   ```
3. Kopiera `api/config.sample.php` till `api/config.php` på servern och fyll i:
   - `transfer_folder_id`: mapp-ID från steg 1
   - `google_client_id`: Client ID från steg 2.5
   - `mail_as`: avsändaradressen
   - `crew`: kontrollera namn och e-postadresser
   - `origins` och `site_url`: testadressen, till exempel `https://nytransfer.turbin.se`
4. Lägg JSON-nyckeln som `api/private/service-account.json`. Mappen `private/` är spärrad för webben av `.htaccess`.
5. Kontrollera spärren: öppna `https://nytransfer.turbin.se/api/config.php` och
   `…/api/private/service-account.json` i webbläsaren. Båda ska ge **403 Forbidden**.

## 6. Testa

- [ ] Sidan visar ingen gul etikett om förhandsvisning (då når den PHP-backenden)
- [ ] Kund: skicka en liten fil och en mapp → mappen dukar upp i `Transfer/Inkommande`, mejl till mottagaren och kvitto till avsändaren
- [ ] Kund: skicka en stor fil (några GB). Slå av wifi en stund mitt i: uppladdningen ska fortsätta när nätet är tillbaka
- [ ] Personal: *Turbin crew* → logga in med Google → leverera → länken fungerar → filerna hämtas → historiken visar "Yes" under Downloaded
- [ ] Hämta en stor fil (2–3 GB) från leveranssidan och kontrollera att den inte avbryts. Det är det största frågetecknet för Loopia.

## 7. Byta till transfer.turbin.se

1. Lägg upp samma filer på subdomänen `transfer.turbin.se` i Loopia, med samma `config.php` och nyckel.
2. Ändra `origins` och `site_url` till `https://transfer.turbin.se` (behåll gärna testadressen i `origins`).
3. Loopia DNS för turbin.se: ändra A-posten för `transfer` från `46.246.119.194` (Glesys) till Loopias adress.
   Det görs enklast genom att koppla subdomänen till webbhotellet i kundzonen.
4. Låt Glesys-servern stå kvar en vecka och säg upp den när allt fungerar.

---

### Bra att veta
- **Spärrar mot missbruk:** högst 10 sändningar per timme och IP-adress, 500 GB och 5 000 filer per sändning. Värdena ändras i `config.php`.
  Cloudflare Turnstile (osynlig bottkontroll) slås på genom att fylla i `turnstile_site_key` och `turnstile_secret`.
- **Utgångna leveranser** blir kvar i Drive. Det är bara länken som slutar fungera.
- **Felsökning:** PHP-fel loggas med prefixet `Turbin Transfer:` i Loopias felogg.
