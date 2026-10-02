# Lokale Umgebung – Konsolidierungsstand

Stand: 2026-10-02. Status: **akzeptiert für den lokalen Workspace** (kein Merge/Deploy).

Diese Notiz beschreibt die maßgebliche manuelle Entwicklungsumgebung **einer
lokalen Einrichtung** nach der Bestandsaufnahme. Absolute Pfade und
Sicherungsverzeichnisse gelten nur für diesen Workspace, nicht als allgemeine
Projektvorgabe für andere Maschinen. Fachliche Produktregeln liegen weiterhin
in den übrigen Docs.

## Kanonisch (lokale Einrichtung)

| Rolle | Festlegung |
|---|---|
| Checkout | Workspace-Checkout `dispo-main` auf Branch `main` (tracking `origin/main`) |
| Port / URL | `8000` → `http://127.0.0.1:8000` (bzw. `http://localhost:8000`) |
| Entwicklungs-DB | MySQL **`dispo_mat_core`** |
| Storage | `dispo-main/storage/app` (u. a. `private/price-list-imports`) |
| Start | `./scripts/start-dev.sh` bzw. `Dispo starten.command` (Wrapper) oder `php artisan serve --host=127.0.0.1 --port=8000` |
| Test-MySQL | ausschließlich **`dispo_test`** |
| Pest/PHPUnit | SQLite `:memory:` (`phpunit.xml`) |
| Playwright/E2E | eigene SQLite-Dateien und Ports – niemals die Dev-DB |

Beispielpfad dieser Maschine (nur lokal):  
`…/htdocs/dispo-tool/dispo-main`.

### Warum `dispo_mat_core` (nicht die ältere DB `dispo`)

Beide DBs hatten denselben Migrationsstand (56 Migrationen, Schema deckungsgleich).
Unterschiede lagen im **Inhalt** und den **Primärschlüsseln**:

| | DB `dispo` | DB `dispo_mat_core` |
|---|---|---|
| Inventare | 3 (`RH`/`RAH`/`OAH`) | 14 (`inv_*`, MAT-/Initialkatalog) |
| Werbemittel | 6 (teils Demo-/E2E-Codes) | 42 |
| `inventory_medium_rules` | 6 | 201 |
| Aktive Preislisten / Items | 5 / 360 | 8 / 501 |
| Kalkulationen / Dispoaufträge | 7 / 8 | 1 / 1 |
| Freigaben / Status / Kommentare | vorhanden | leer bzw. minimal |

Inventar-ID `1` meint in `dispo` „Radio Hamburg (`RH`)“, in `dispo_mat_core`
„MORE Hamburg-Kombi“. Fremdschlüssel, Frozen Snapshots und Preislisten-Pins sind
daher **nicht tabellenweise zusammenführbar**, ohne IDs und Snapshots
umzuschreiben. Es gab **keine Tabellenzusammenführung**.

Gewählt wurde der Weg mit geringstem Verlustrisiko und Aufwand:

1. Maßgebliche Dev-DB = `dispo_mat_core` (voller Katalog + Preise).
2. DB `dispo` stilllegen und behalten (nicht löschen).
3. Kein Tabellen-Merge, kein Seeding über bestehende Daten.

### Alte Arbeitsdaten: erhalten, aber in der neuen Dev-DB nicht sichtbar

Die folgenden Bestände liegen **nur** in der stillgelegten DB `dispo` (und im
Backup). Sie wurden **nicht** nach `dispo_mat_core` übernommen und sind dort in
der UI **nicht** sichtbar:

- Kalkulationen `K-2026-00001` … `K-2026-00007`
- Dispoaufträge inkl. Status `disposed` / `awaiting_sales_approval`, Statushistorie,
  Freigabeanfragen, Kommentare
- ein Standardangebot
- zusätzlicher User `brand.franzjosef@example.com` (Produktmanagement)
- Orphan-Dateien unter Checkout `dispo/storage/app/private/dispo-orders/`
  (DB-Upload-Tabelle war leer)

Eine spätere Übernahme bräuchte einen eigenen ID-Remap-/Snapshot-Plan und ist
hier **nicht** ausgeführt.

## Stillgelegte Altumgebungen

| Artefakt | Status | Hinweis |
|---|---|---|
| Checkout `dispo` | stillgelegt | älterer Stand, stark hinter `main` |
| MySQL `dispo` | stillgelegt | alter Arbeits-/Demo-Bestand, inkompatible IDs |
| MySQL `dispo_df32a_mig` | Hilfs-/Migrations-DB | nicht für manuelle App-Nutzung |
| MySQL `dispo_test` | **Testisolation** | behalten; von Guard geschützt |
| Viele `dispo-wt-*` Worktrees | lokal vorhanden | **nicht pauschal löschen** (siehe unten) |

### Verbliebene Worktrees sind nicht automatisch gefahrlos löschbar

Lokale Git-Worktrees können unintegrierte Commits, Dirty-Dateien oder nur lokal
relevante Smoke-/Docs-Stände enthalten. Eine automatische Massenbereinigung ist
**nicht** gefahrlos. Mindestens prüfen bzw. bewusst aufbewahren:

- Worktrees mit noch nicht (vollständig) in `main` integrierten bzw. lokalen
  Eigenständen (u. a. Docs-/Feature-Reststände)
- Worktrees mit Dirty-Dateien (u. a. `dispo-wt-bl-p2-02a`, `dispo-wt-bl-p4-03a-smoke`)

Gemergte Worktrees sind für den täglichen App-Start nicht maßgeblich; Löschen
bleibt eine gesonderte, manuelle Entscheidung.

## Startweg (geprüft)

- `scripts/start-dev.sh` existiert im kanonischen Checkout und ist ausführbar.
- `Dispo starten.command` ruft ausschließlich dieses Skript auf.
- Default-Port: `DISPO_PORT` bzw. **8000**; Host default `127.0.0.1`.
- Die MySQL-Datenbank kommt aus der Checkout-`.env` (`DB_DATABASE`). Mit der
  konsolidierten `.env` startet das Skript gegen **`dispo_mat_core`**, führt
  `php artisan migrate --force` aus (No-op bei aktuellem Stand) und startet
  `php artisan serve` auf dem Port.
- Fallback-Text im Skript (`${DB_DATABASE:-dispo}`) greift nur, wenn in `.env`
  kein `DB_DATABASE` gesetzt ist – das ist lokal nicht der Fall.
- Keine automatischen Seeder gegen die Dev-DB.

### Konfigurationshinweise außerhalb der laufenden `.env` (offen, nicht geändert)

| Stelle | Befund |
|---|---|
| `.env.example` | setzt weiterhin `DB_DATABASE=dispo` (Greenfield-Vorlage) |
| `scripts/start-dev.sh` | Fallback-Name `dispo`, wenn Env-Key fehlt |
| `phpunit.mysql.xml` / Guard | erzwingen `dispo_test`; erwähnen `dispo` nur als Beispiel „nicht treffen“ |
| Queue / Scheduler | zum Prüfzeitpunkt **keine** laufenden `queue:work`-/`schedule:*`-Prozesse; keine abweichende Prozesskonfiguration festgestellt |

Diese Stellen wurden in diesem Slice **nicht** umgebaut.

## Sicherungen und Rückkehr (lokale Einrichtung)

Sicherungen liegen **außerhalb** des Git-Repos (Beispielpfad dieser Maschine):

`~/dispo-local-backups/env-consolidation-20261002-194636/`

Rechte restriktiv (`700` auf dem Backup-Root, `600` auf Dateien).  
**Keine** Sicherungsdateien und **keine** `.env`-Kopien committen.

### Was gesichert wurde

- **MySQL-Tabellenstruktur und Tabellendaten** der DBs `dispo`, `dispo_mat_core`,
  `dispo_test`, `dispo_df32a_mig` als `mysqldump --databases` + gzip
  (`--skip-routines --skip-triggers --skip-events`)
- **Storage-Archive** der Checkouts `dispo` und `dispo-main` (`storage/app`)
- **Lokale `.env`-Kopien** nur im Backup-Verzeichnis (nicht im Repo)

### Grenzen / nicht behauptete Vollständigkeit

| Bestand | Prüfung | Dump |
|---|---|---|
| Tabellen + Daten | vorhanden, Restore-Probe | enthalten |
| Trigger | `information_schema.triggers`: **0** in `dispo` und `dispo_mat_core` | bewusst übersprungen (leer) |
| Routinen (PROCEDURE/FUNCTION) | Abfrage über `information_schema.routines` / `SHOW … STATUS` **fehlgeschlagen** (`mysql.proc`-Spaltenzahl-Mismatch MariaDB) | bewusst übersprungen; Vorhandensein **nicht** abschließend inventarisierbar |
| Events | Abfrage fehlgeschlagen (Event-Scheduler deaktiviert, Fehler 1577) | bewusst übersprungen; Vorhandensein **nicht** abschließend inventarisierbar |

Es handelt sich um eine **Tabellen-/Daten-Sicherung**, keine vollständige
Instanzsicherung inklusive ungeprüftem Routine-/Event-Bestand. Keine
MySQL-Systemreparatur (`mysql_upgrade` o. Ä.) in diesem Slice.

### Restore-Probe (isoliert)

In eine temporäre DB `dispo_backup_verify_tmp` (danach gedroppt), **nicht** über
bestehende Entwicklungs-DBs:

- `dispo`: Inventare 3/3, Medien 6/6, Preislisten-Items 360/360, Kalkulationen 7/7,
  Dispoaufträge 8/8, Migrationen 56/56
- `dispo_mat_core`: Inventare 14/14, Medien 42/42, Preislisten-Items 501/501,
  Kalkulationen 1/1, Dispoaufträge 1/1, Migrationen 56/56

Die Probe validiert damit **Tabellenzeilen** der genannten Domänen, nicht
Routinen/Events/Trigger und nicht den vollständigen Dateiinhalt jedes Storage-
Objekts jenseits erfolgreicher Tar-Lesbarkeit.

### Rückkehr zur alten DB `dispo` (nur bei Bedarf)

1. App-Server stoppen.
2. In `dispo-main/.env` `DB_DATABASE=dispo` setzen.
3. `php artisan config:clear`.
4. Server neu starten.
5. Hinweis: Katalog/Preise dann wieder der alte 3-Inventar-Bestand; die MAT-Dev-DB
   bleibt unberührt.

### Rückkehr aus Dump (sicheres Vorgehen, kein Copy-Paste-Skript)

Restore **ausschließlich** in eine eindeutig neue, isolierte Datenbank – niemals
in `dispo_mat_core`, `dispo`, `dispo_test` oder eine andere bestehende
Entwicklungsdatenbank.

1. Zielnamen wählen, der lokal noch **nicht** existiert; vor dem Import prüfen,
   dass diese Datenbank fehlt.
2. Dump-Struktur lesen (Header/`USE`/Objekte) und einen dazu passenden Importweg
   wählen. **Keine** globale Textersetzung über den Dump, die auch SQL-Dateninhalte
   verändern kann.
3. Import ausführen; bei Fehlern abbrechen und den ggf. teilweise
   wiederhergestellten Bestand nicht als gültig verwenden.
4. Vollständigkeit prüfen (u. a. erwartete Tabellen und zentrale Zeilenzahlen),
   bevor die wiederhergestellte DB genutzt wird.

Die oben dokumentierte Restore-Probe bleibt nur historischer Prüfbeleg der
Tabellen-/Daten-Sicherung (mit den dort genannten Grenzen), kein laufendes
Restore-Rezept.

## Testisolation (unverändert maßgeblich)

- Pest Standard: SQLite `:memory:`
- MySQL: nur `dispo_test` (`phpunit.mysql.xml` `force="true"` + `MysqlTestDatabaseGuard`)
- Playwright: eigene SQLite-Dateien und Ports (nie `dispo_mat_core` / `dispo`)
- Automatisierte Tests dürfen die maßgebliche Dev-DB weder zurücksetzen noch verändern

## Bewusst nicht gemacht

- kein Löschen von DBs, Checkouts, Branches, Uploads oder Worktrees
- kein `migrate:fresh` / Seeding auf Dev-Daten
- kein Merge der Arbeitsdaten aus `dispo` nach `dispo_mat_core`
- keine Änderung an `.env.example` / Startskript-Fallback
- keine MySQL-Systemreparatur
- kein Audit-Nachzug, keine Feature-Arbeit, kein SMTP-Versand
