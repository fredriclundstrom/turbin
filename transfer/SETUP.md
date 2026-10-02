# Turbin Transfer – installation (enkel version)

Fyra steg. Personalen loggar in med sina vanliga Google-konton på turbin.se. Mejl via Gmail kan läggas till senare (se längst ner).

## ✅ 1. Transfer-mappen i Drive
Klart. Spara mappens ID, alltså sista delen av adressen när mappen är öppen:
`drive.google.com/drive/folders/`**`1AbC…xyz`**

## 2. Ett "robotkonto" i Google (servicekonto)
Ett servicekonto är ett konto som bara sajten använder för att lägga filer i Transfer-mappen.

1. Gå till https://console.cloud.google.com och skapa ett projekt som heter **Turbin Transfer**.
2. Aktivera **Google Drive API** i projektet.
3. Skapa ett servicekonto som heter **turbin-transfer**. Det får en adress i stil med
   `turbin-transfer@turbin-transfer.iam.gserviceaccount.com`.
4. Skapa en **nyckel (JSON)** för kontot. En fil laddas ner. Den är lösenordet till kontot, så maila den inte till någon.
5. Inloggningen för personalen: välj **Internal** under *OAuth consent screen* och skapa ett **OAuth-klient-ID** av typen *Web application*
   med tillåtna adresser `https://nytransfer.turbin.se` och `https://transfer.turbin.se`. Klient-ID:t ska in som `google_client_id`.
   Eftersom alla redan är inloggade på Google räcker det med ett klick på "Logga in med Google".

## 3. Dela Transfer-mappen med robotkontot
Högerklicka på **Transfer** i Drive → *Dela* → klistra in robotkontots adress → roll **Innehållsansvarig** → *Dela*.
(Säger Drive nej för att adressen ligger utanför turbin.se behöver extern delning tillåtas i Admin console.)

## 4. Lägga upp på Loopia
1. Skapa underdomänen **nytransfer.turbin.se** i Loopias kundzon. Den gamla transfersidan körs vidare under tiden.
2. Ladda upp `index.html` och mappen `api/` dit.
3. Skapa `api/config.php` (kopia av `config.sample.php`) och fyll i mapp-ID, klient-ID och adressen.
4. Lägg nyckelfilen som `api/private/service-account.json`.

Testa: skicka några filer, kontrollera att de dyker upp i Drive och testa personalläget.
När allt fungerar pekar vi om **transfer.turbin.se** dit och säger upp den gamla servern hos Glesys.

---

### Senare, om ni vill
- **Gemensamt personallösenord** i stället för Google-inloggning: lämna `google_client_id` tomt och fyll i `crew_password`.
- **Mejl via Gmail** i stället för via Loopia: domänomfattande delegering med behörigheten `gmail.send` i Admin console
  (`mail_via => 'gmail'`). Fram till dess skickas mejlen via Loopia. Lägg då till Loopia i SPF-posten för turbin.se
  så att mejlen inte hamnar i skräpposten.
- **Spärrar:** högst 10 sändningar per timme och IP-adress, 15 GB och 100 filer per sändning (fler filer: be om en zip). Ändras i config.php.
