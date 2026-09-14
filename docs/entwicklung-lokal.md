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
`phpunit.mysql.xml` erzwingt `DB_DATABASE=dispo_test` per `force="true"` – eine äußere
Shell-Variable wie `DB_DATABASE=dispo` darf die Entwicklungsdatenbank nicht treffen.
Zusätzlich bricht `Tests\Support\MysqlTestDatabaseGuard` vor `RefreshDatabase` /
`DatabaseMigrations` und in MySQL-Parallelworkern ab, wenn nicht exakt `dispo_test`
aktiv ist.

## BL-P4-01a – lokale Inbetriebnahme der Preislisten-Migration

Die Migration `2026_09_13_220000_add_year_lock_and_revision_to_price_lists`
erweitert bestehende `price_lists`:

- `year` aus eindeutigem `valid_from` (kein Fallback auf 2026 oder das
  Ausführungsjahr)
- `revision_number` nach Insert-Reihenfolge je Inventar/Jahr (historische
  `version`-Strings bleiben)
- `lock_version` = 1
- Unique Active je Inventar/Jahr

Vorab: `php artisan migrate:status`. Backup der lokalen MySQL-Datenbank
`dispo` erstellen. **Kein** `migrate:fresh` / `refresh` / `reset` / `db:wipe`
gegen `dispo`. `up()` prüft Treiber, `valid_from` und erkennbare
Constraint-Kollisionen **bevor** Schema oder Daten geändert werden; bei
Ablehnung bleiben Altbestand und Schema unverändert. Rollback stellt die
alte Unique `(inventory_id, version)` wieder her, **nachdem** geprüft wurde,
ob sie wiederherstellbar ist und ob der Jahresbezug verlustfrei aus
`valid_from` rekonstruierbar ist (`valid_from` belegt, Jahr 1990–2100,
stimmt mit gespeichertem `year` überein). Neu angelegte Entwürfe mit
`year` und `valid_from = null` verweigern den Rollback kontrolliert –
ohne Active-Indizes, Generated Columns oder Jahres-/Lock-Spalten zu
entfernen und ohne `valid_from` nachträglich zu erfinden. Dasselbe gilt,
wenn dieselbe Versionskennung in zwei Jahren desselben Inventars existiert.
Auf MySQL muss der alte Unique-Index `(inventory_id, version)` erst nach
den neuen Inventar-Indizes entfallen, weil er den FK `inventory_id` stützt.
MySQL-DDL gilt nicht als vollständig durch `DB::transaction` rückrollbar.
Daten werden dabei nicht gelöscht oder umnummeriert.

Die UI darf erst gegen eine migrierte Datenbank als abgenommen gelten.

## BL-P4-01b – Excel-Import

Migration `2026_09_14_120000_create_price_list_imports_table`. Dependency:
`phpoffice/phpspreadsheet` (MIT). Formate: XLSX und XLS; CSV nicht.

Kanonischer Vertrag (kein erfundenes MORE-Layout):

- Spalten `inventory_code`/`inventory`, `hour`, `day_group`, `second_price`, optional `year`
- oder Blattname = Inventar + Spalten ohne Inventarspalte
- Jahr in der UI; Workbook-Jahr falls vorhanden muss übereinstimmen
- Aliase nur dokumentiert: `radio ffn`, `BOLLERWAGEN`
- Import erzeugt Drafts; Aktivierung über bestehenden Lifecycle

E2E: `npx playwright test -c playwright.blp401b.config.ts` (Port 8018).

## Produktion (nicht lokal)

- `APP_DEBUG=false`
- `APP_URL` mit `https://`
- `SESSION_SECURE_COOKIE=true`
- Queue: `retry_after` (90) größer als `queue:work --timeout` (45); siehe ADR-003
