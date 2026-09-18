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

## DF-3.3a2β – historische Dispo-Origin-Retention

Kein Schema-Migrationsschritt. Beim Cleanup eines Calc-Effektiv-Snapshots bleiben
legitime Dispo-Origins (`source_configuration_snapshot_id`) erhalten; der Snapshot
wird nicht gelöscht und blockiert Calc-Updates nicht. Tests:

- `ConfigurationSnapshotHistoricalOriginRetentionTest`
- MySQL: `ConfigurationSnapshotHistoricalOriginRetentionMysqlTest`
  (`vendor/bin/pest --configuration=phpunit.mysql.xml --filter=ConfigurationSnapshotHistoricalOriginRetentionMysqlTest`)

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
- Formeln werden nicht ausgewertet (`formula_not_allowed`)
- Preflight vor Materialisierung: max. 50 MB, 20 Blätter, 5 000 Zeilen/Blatt,
  32 Spalten, 10 000 Datenzeilen gesamt
- Upload zuerst unter `temporary/price-list-imports/`, danach privater Archivpfad

E2E: `npx playwright test -c playwright.blp401b.config.ts` (Port 8018).

## BL-P4-01c – Wizard-Preisjahrwahl

Kein Schema-Migrationsschritt. Payload-Felder:

- `positions.*.price_year` (int)
- `positions.*.expected_price_list_id` (int, Expected-Active zum Renderzeitpunkt)
- Budget: `price_year`, optional `expected_price_list_ids`

`positions.*.price_list_id` ist im HTTP-Payload prohibited (Serverautorität).

**Expected-Token verpflichtend**, wenn der Client ausdrücklich `price_year` für
eine Live-Bindung bzw. einen bewussten Rebind sendet und für Inventar/Jahr eine
aktive Liste existiert. Fehlender Token → 422. Falscher/veralteter Token → 409
(`PriceListSelectionConflictException`). Legacy-Payloads ohne `price_year`
binden weiterhin das aktuelle Jahr ohne Expected-Pflicht. Unveränderte
historische Pins (kein Jahrwechsel) brauchen keinen Live-Expected-Abgleich.

Live-Bindung / Aktivierung – Lock-Reihenfolge (interleaved je Inventar):

```text
Calculation (Calc-Update, lockForUpdate) → je Inventar in aufsteigender ID:
  Inventory → zugehörige PriceLists (Jahre ASC, Listen-IDs ASC)
→ erst danach nicht-lockende Relationen-/Katalog-Reads (MySQL REPEATABLE READ)
```

Keine globale „erst alle Inventare, dann alle Preislisten“-Garantie.
Budget-Propose: Active-Auflösung vor Expected-Map; Missing-Active vor Token-422.

Folgerisiko `BL-P4-02`: Methodenwechsel bei gleichem Inventar/Jahr darf den
historischen Pin nicht über Live-Aktivierung ersetzen. **`BL-P4-02a` (`main`):**
`CatalogResolver::resolveMethodChangeKeepingPriceListPin`; Live-Bind nur neu /
Inventarwechsel / expliziter Jahrwechsel. **`BL-P4-02b` umgesetzt (PR #58):**
Registry `calendar` **released/v1**; echte Wochenmatrix; `fixed_price` weiter **`planned`**.

**Git-Worktree:** Liegt `vendor` per Symlink im Hauptprojekt, setzt
`tests/bootstrap.php` `APP_BASE_PATH` auf das Worktree-Root – sonst fehlen
worktree-spezifische Migrationen (z. B. BL-P4-02b) in Pest/Feature-Tests.

E2E: `npx playwright test -c playwright.blp401c.config.ts` (Port 8019,
DB `database/e2e-bl-p4-01c.sqlite` – niemals Dev-DB `dispo`).

## BL-P4-02a – Average-Pin-Härtung

Kein Schema-Migrationsschritt. Resolver-Pfad:

- reiner Methodenwechsel + gleiches Inventar + gleiches Preisjahr → historischer Pin
- Tests: `PriceListPinOnMethodChangeTest`, `PriceListPinOnMethodChangeMysqlTest`,
  `SpotClassicAverageAcceptanceHardeningTest`
- MySQL: `vendor/bin/pest --configuration=phpunit.mysql.xml --filter=PriceListPinOnMethodChangeMysqlTest`

## BL-P4-02c – Spot-Komponenten

Isolierte Playwright-Suite: `npm run test:e2e:blp402c` (Port **8025**, DB
`database/e2e-bl-p4-02c.sqlite`, Seeder `E2ESpotComponentsSeeder`). Keine Dev-DB
`dispo` migrieren; Worktree-Migrationen nur in Pest/Feature-Tests.

## BL-P4-02e – Tandem / Tridem

Migration `component_profile` auf `advertising_media`, `calculation_positions` und
`dispo_order_positions`. Tests: `TandemTridemCalculationTest`,
`ComponentProfileValidatorTest`, `SpotClassicTandemTridemTest` (+ MySQL); Vitest
`resources/js/lib/spot-components.test.ts`.

E2E: `npm run test:e2e:blp402e` bzw.
`npx playwright test -c playwright.blp402e.config.ts` (Port **8028**, DB
`database/e2e-bl-p4-02e.sqlite`, Seeder `E2ETandemTridemSeeder` – niemals
Dev-DB `dispo`).

## BL-P4-02d – Preisabschluss Festpreis

Migration `pricing_settlement_mode` + `fixed_price_nn` auf `calculation_positions`
und `dispo_order_positions`. Live-Abschluss **`fixed_price`** nur über
`pricing_settlement_mode` (Basis weiter `average`|`calendar`); Registry-Methode
**`fixed_price`** bleibt **`planned`**.

Tests: `SpotClassicFixedPriceSettlementTest` (+ MySQL); Vitest
`resources/js/lib/pricing-settlement.test.ts`,
`resources/js/components/pricing-settlement-section.test.tsx`.

E2E: `npm run test:e2e:blp402d` bzw.
`npx playwright test -c playwright.blp402d.config.ts` (Port **8026**, DB
`database/e2e-bl-p4-02d.sqlite`, Seeder `E2ESpotComponentsSeeder` – niemals
Dev-DB `dispo`).

## BL-P4-02b – Kalenderplaner

Migration `calculation_position_planner_entries` + `planner_entries_snapshot` auf
`dispo_order_positions`. Jahresvertrag im `CatalogResolver` (Kalenderdaten ↔
Preisjahr der Position). Wizard-Katalog `price_list_hours_by_id` (Basisgruppen
`mo_fr`/`sa`/`so`) für die Wochenmatrix. Tests: `CalendarCalculationTest`,
`SpotClassicCalendarCalculationTest`, `SpotClassicCalendarYearContractMysqlTest`,
`BlP402bWithOriginRetentionCompatTest`; Vitest `spot-calendar-planner.test.tsx`,
`pricing-calendar.test.ts`, `dispo-planner-display.test.ts`.

E2E: `npx playwright test -c playwright.blp402b.config.ts` (Port **8022**,
DB `database/e2e-bl-p4-02b.sqlite`, Seeder `E2ECalendarPlannerSeeder` – niemals
Dev-DB `dispo`).

## Produktion (nicht lokal)

- `APP_DEBUG=false`
- `APP_URL` mit `https://`
- `SESSION_SECURE_COOKIE=true`
- Queue: `retry_after` (90) größer als `queue:work --timeout` (45); siehe ADR-003
