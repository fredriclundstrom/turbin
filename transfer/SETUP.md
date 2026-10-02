# Turbin Transfer – installation (enkel version)

Fyra steg. Google-inloggning för personalen och mejl via Gmail kan läggas till senare (se längst ner).

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

## 3. Dela Transfer-mappen med robotkontot
Högerklicka på **Transfer** i Drive → *Dela* → klistra in robotkontots adress → roll **Innehållsansvarig** → *Dela*.
(Säger Drive nej för att adressen ligger utanför turbin.se behöver extern delning tillåtas i Admin console.)

## 4. Lägga upp på Loopia
1. Skapa underdomänen **nytransfer.turbin.se** i Loopias kundzon. Den gamla transfersidan körs vidare under tiden.
2. Ladda upp `index.html` och mappen `api/` dit.
3. Skapa `api/config.php` (kopia av `config.sample.php`) och fyll i mapp-ID, personallösenord och adressen.
4. Lägg nyckelfilen som `api/private/service-account.json`.

Testa: skicka några filer, kontrollera att de dyker upp i Drive och testa personalläget.
När allt fungerar pekar vi om **transfer.turbin.se** dit och säger upp den gamla servern hos Glesys.

---

### Senare, om ni vill
- **Inloggning med Google-konto** i stället för gemensamt personallösenord: ett OAuth-klient-ID i Google Cloud
  (`google_client_id` i config.php).
- **Mejl via Gmail** i stället för via Loopia: domänomfattande delegering med behörigheten `gmail.send` i Admin console
  (`mail_via => 'gmail'`). Fram till dess skickas mejlen via Loopia. Lägg då till Loopia i SPF-posten för turbin.se
  så att mejlen inte hamnar i skräpposten.
- **Spärrar:** högst 10 sändningar per timme och IP-adress, 500 GB och 5 000 filer per sändning. Ändras i config.php.
