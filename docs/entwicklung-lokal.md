# Lokale Entwicklung

Voraussetzungen: PHP 8.3 oder neuer (lokal darf 8.4/8.5 sein), Composer 2,
Node.js 22+, MySQL 8 mit InnoDB/utf8mb4 **oder** SQLite für den Schnellstart.

## Erstes Setup (vollständige Befehle)

```bash
composer install
cp .env.example .env
php artisan key:generate
```

### Variante A: MySQL (V1-Standard, ADR-002)

Datenbank anlegen, danach in `.env` setzen (keine produktiven Passwörter):

```env
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=dispo
DB_USERNAME=root
DB_PASSWORD=
```

```bash
php artisan migrate
```

### Variante B: SQLite (nur lokal)

In `.env`:

```env
DB_CONNECTION=sqlite
DB_DATABASE=database/database.sqlite
```

```bash
mkdir -p database
touch database/database.sqlite
php artisan migrate
```

### Frontend, Build, Browser

```bash
npm ci
npm run build
npx playwright install chromium
```

Entwicklungsserver: `php artisan serve` oder `composer run dev`.
Anwendung: http://localhost:8000 – Health: `/health` und `/up`.

Öffentliche Registrierung ist deaktiviert. Benutzer werden administrativ angelegt.
Passwort-Reset nutzt lokal `MAIL_MAILER=log` (`storage/logs`).

Node.js ist kein Produktionsprozess, nur Asset-Build und CI.

## Alle Prüfungen

Kanonische Frontendformat-/Lintprüfung ist **`npm run check`** (Vite Plus).
Markdown und `.prettierrc.json` sind von der Formatprüfung ausgenommen, damit
Fachdokumente nicht gegen Frontend-Prettier laufen.

```bash
vendor/bin/pint --test
vendor/bin/phpstan analyse --memory-limit=1G
php artisan test
npm run check
npm run types:check
npm run test:unit
npm run build
npx playwright test
composer audit
npm audit --omit=dev
```

Pest nutzt standardmäßig SQLite in Memory (`phpunit.xml`).
MySQL-Integrationssuite: `vendor/bin/pest --configuration=phpunit.mysql.xml`
(benötigt eine Datenbank `dispo_test` und die Zugangsdaten aus `phpunit.mysql.xml`).

## Produktion (nicht lokal)

- `APP_DEBUG=false`
- `APP_URL` mit `https://`
- `SESSION_SECURE_COOKIE=true`
- Queue: `retry_after` (90) größer als `queue:work --timeout` (45); siehe ADR-003
