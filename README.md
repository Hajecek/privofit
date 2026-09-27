# PRIVOFIT

Soukromé samoobslužné fitness studio. Zákazník si rezervuje termín, zaplatí nebo využije členství a v čase rezervace otevře dveře přes aplikaci.

Aplikace je připravená na PHP 8.3–8.5 a běžný webhosting Wedos (MariaDB, HTTPS, cron). Lokálně ověřeno s PHP 8.3.1. Na Wedosu doporučujeme PHP 8.5.

## Co už funguje

- Veřejný web, registrace, přihlášení, ověření e-mailu, reset hesla
- Zákaznický dashboard, rezervace, členství, profil, avatar, relace, MFA
- Administrace a provozní sekce podle rolí `user`, `staff`, `admin`, `owner`
- REST API `/api/v1` se sdílenou business logikou
- AccessControl s `MockDoorProvider` a připraveným `NukiDoorProvider`
- SQL schéma, migrace, seedery, instalace OWNER účtu, cron

## Instalace (XAMPP / lokálně)

```bash
cd /Applications/XAMPP/xamppfiles/htdocs/privofit
composer install
cp .env.example .env
php bin/install.php --generate-key   # vložte výsledek do APP_KEY, pokud ještě není
```

V `.env` nastavte databázi. Poté:

```bash
php bin/install.php --email=vas@email.cz --username=majitel --password='dlouhe-heslo-12+' --first=Jmeno --last=Prijmeni
```

V XAMPP otevřete přímo:

`http://localhost/privofit/`

Úvodní stránka běží z kořenového `index.php`. Složky jako `app/`, `config/`, `storage/` a soubor `.env` nejsou z webu dostupné.

## Instalace na Wedos

1. V zákaznické administraci nastavte PHP 8.5 (případně 8.4/8.3).
2. Vytvořte MySQL databázi. Pokud nelze spustit `CREATE DATABASE`, importujte `database/privofit_existing_db.sql`.
3. Nahrajte soubory do kořene webu. Vstupní bod je `index.php`.
4. Na serveru spusťte `composer install --no-dev --optimize-autoloader`.
5. Zkopírujte `.env.example` na `.env`, vyplňte DB, `APP_URL`, `APP_KEY`, SMTP.
6. Nastavte `APP_ENV=production`, `APP_DEBUG=false`, `SESSION_SECURE=true`.
7. Vytvořte OWNER účet příkazem `php bin/install.php ...`.
8. Naplánujte cron: `php /cesta/bin/cron.php` každých 5 minut.
9. Zapněte HTTPS. HSTS se přidá automaticky.

Po instalaci vznikne `storage/app/installed.lock` a `INSTALL_ENABLED=false`.

## Nuki

Výchozí režim je `DOOR_PROVIDER=mock`. Aplikace nikdy netvrdí, že fyzicky otevřela dveře, dokud není připojený a otestovaný zámek.

Pro ostrý provoz:

- Smart Lock Pro s Nuki Web (Wi-Fi, Bridge není nutný)
- Bearer token z Nuki Web, token jen v `.env` (`NUKI_API_TOKEN`)
- `DOOR_PROVIDER=nuki` a `NUKI_SMARTLOCK_ID`
- Počítejte s HTTP 204 = přijatý příkaz, nikoli jistota fyzického otevření
- Komerční provoz může vyžadovat Nuki Smart Hosting / Advanced API

Dokumentace, ze které integrace vychází: Nuki Web API v1.5.3, `https://api.nuki.io`.

## Cron a e-maily

`bin/cron.php` se spouští každých 5 minut. Zámek v databázi zabrání souběhu. Úlohy po jedné expirují nezaplacené rezervace a členství, připomínají termíny a končící členství, hlásí neúspěšné platby a nové zprávy z webu, a teprve potom odešlou frontu. Zákaznické připomínky ctí nastavení oznámení. Provozní zprávy jdou jen aktivním administrátorům. Staré logy, audit, cron události a odeslané notifikace se mažou podle retence.

V `.env` nechte `MAIL_MAILER=log` do doby, než bude SMTP. E-maily se zapisují do `storage/logs/mail-*.log`.

## Testy

```bash
composer test
```

Žádný seedovaný účet nemá veřejné administrátorské heslo.

## Retence údajů

- access logy: 365 dní (cron)
- audit logy: 730 dní (nastavení v `config/app.php`)
- export a žádost o výmaz jsou v uživatelském profilu
