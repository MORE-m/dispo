# PO-CALC-TKP-1D4c-2 – Sichere OA-TKP-Stammdaten-Import-CLI

Stand: 27. Oktober 2026 (**Spezifikation – keine Implementierung in diesem Slice**)  
Voraussetzung: D4c-1 Doku/Vorlagen; Base erwartet nach Merge von D4c-1 bzw. `2364085…` + D4c-1-Docs  
**Kein produktiver Import, kein Deploy, keine Registry-Freigabe, kein D4d.**

## Ziel

Kontrollierten Artisan-Import über die **bestehenden** Domain-Importer bereitstellen:

| Command | Service |
|---|---|
| `oa:import-platforms {file}` | `OaPlatformImporter` |
| `oa:import-targetings {file}` | `OaTargetingDefinitionImporter` |
| `oa:import-price-list {file}` | `OaTkpPriceListImporter` |
| `oa:activate-price-list` | **neuer** `OaTkpPriceListActivationService` (kein zweiter Item-Importer) |

Keine zweite Importlogik. Keine HTTP-Routen. Keine Deploy-Hooks. Keine Fachdaten im Slice.

## Architektur

```
app/Console/Commands/OnlineAudio/
  ImportOaPlatformsCommand.php
  ImportOaTargetingsCommand.php
  ImportOaTkpPriceListCommand.php
  ActivateOaTkpPriceListCommand.php

app/Services/OnlineAudio/Import/
  OaPlatformImporter.php          (bestehend, unverändert sofern möglich)
  OaTargetingDefinitionImporter.php
  OaTkpPriceListImporter.php
  OaTkpPriceListActivationService.php   (NEU: Archive+Activate atomar)
  OaTkpImportJsonReader.php             (NEU: Datei lesen, JSON decode, Fehler)
```

### Command-Verträge

**Import (Platforms / Targetings / Price-List)**

```text
php artisan oa:import-platforms path.json [--dry-run]
php artisan oa:import-targetings path.json [--dry-run]
php artisan oa:import-price-list path.json [--dry-run]
```

- JSON gegen bestehendes Importer-Schema (Array bzw. Preislisten-Objekt)
- `--dry-run` → `import(..., dryRun: true)`; Exit 0 bei gültig, **keine** DB-Mutation
- Ohne Flag → Write in Importer-TX
- Bei `OaTkpStammdatenImportException`: alle Fehlerzeilen auf STDERR/JSON-Report, Exit ≠ 0
- **Price-List-Command:** erzwingt bzw. akzeptiert nur `status=draft` (oder überschreibt Payload-Status auf `draft`). Kein Activate über Import-Command
- Re-Import derselben `year`+`revision_number`: nur erlaubt, wenn bestehende Liste `draft` ist; `active`/`archived` → fail-closed („kein stilles Überschreiben historischer Revisionen“)

**Activate**

```text
php artisan oa:activate-price-list --year=2026 --revision=1 [--dry-run]
```

- Explizite `--year` und `--revision` Pflicht
- Zielrevision muss existieren und `draft` sein (oder bereits die einzige `active` → Idempotenz klar definieren: no-op OK)
- In **einer** DB-Transaktion:
  1. `lockForUpdate` auf alle `oa_tkp_price_lists` des Jahres (oder äquivalente Jahr-Sperre)
  2. Alle anderen `active` desselben Jahres → `archived` + `archived_at`
  3. Zielrevision → `active` + `published_at` (falls leer)
  4. Assert: genau eine `active` für das Jahr
- Bei Fehler: vollständiger Rollback
- Kein Löschen von Listen/Items; keine Änderung an Item-Preisen beim Activate
- Parallele Aktivierung: DB-Lock + Unique-Verhalten; zweiter Prozess wartet oder scheitert fail-closed nach Recheck
- MySQL: Transaktionsisolation prüfen (Feature-Test mit `RefreshDatabase` + gezielt MySQL)

### Zusammenspiel mit vorhandenem Importer

| Aspekt | Ist heute | D4c-2 |
|---|---|---|
| Activate via Import `status=active` | möglich, archiviert Vorgänger **nicht** | CLI-Import blockt `active`; Activate nur über ActivationService |
| `firstOrNew(year, revision)` + Item-Delete | überschreibt bestehende Revision | CLI blockt Write auf non-draft |
| App-Check „andere active“ | nur bei Import active | ActivationService archived atomar + Recheck |
| DB Unique `(year, revision)` | ja | bleibt; schützt nicht „eine active/Jahr“ |
| Historische Pins in Calcs | Snapshots | unangetastet; Activate ändert keine Calc-Daten |

### Sicherheit

- Nur Artisan (Console-Kernel); kein HTTP, kein Scheduler-Default
- Keine Seed-Demodaten, keine Registry-/HTTP-Gate-Änderungen
- Tests nur isolierte Test-DB (`RefreshDatabase` / MySQL-Job)
- Operativer Rollback (Doku): Activate rückgängig = neue Draft-Revision importieren + Activate der Korrektur-Revision; alte Revisionen bleiben archived/historisch; **kein** Delete

## Pflichtanforderungen (Checkliste Implementierung)

1. JSON-Validierung über bestehende Importer
2. Dry-Run ohne Mutation
3. Fehlerhafte Daten vollständig zurückweisen (Exception mit Fehlerliste)
4. Aussagekräftige Reports + Exit-Codes
5. Kein Auto-Activate beim Draft-Import
6. Activate nur mit explizitem Jahr + Revision
7. Archivierung + Aktivierung atomar
8. Genau eine active Revision / Jahr
9. Schutz paralleler Aktivierungen (`lockForUpdate`)
10. Kein Löschen historischer Preislisten
11. Kein stilles Überschreiben von `active`/`archived` Revisionen
12. Keine Demo-Fachdaten
13. Keine Registry-/HTTP-Gate-Änderungen
14. Rollback-Prozess dokumentiert (`docs/readiness` + ggf. `fortschritt`)

## Testkonzept

| Fall | Art |
|---|---|
| Dry-Run Platforms/Targetings/PriceList ohne Mutation | Feature/Unit |
| Gültiger Import (isolierte Fixtures) | Feature |
| Ungültige Dezimal-/Schema-Daten → Exit ≠ 0, 0 Writes | Feature |
| Batch-Duplikate / doppeltes Medium×Inventar | Feature |
| Unbekanntes Inventory / `native_ad` | Feature |
| Import mit `status=active` abgelehnt | Feature |
| Re-Import `draft` erlaubt; `active`/`archived` abgelehnt | Feature |
| Activate: Vorgänger archived + Ziel active atomar | Feature + MySQL |
| Activate-Fehler → kein Teilstand | Feature |
| Zwei parallele Activate-Versuche (MySQL) | MySQL Feature |
| Calc mit gepinnten Snapshots unverändert nach Activate neuer Revision | Feature |
| Regression: D4b-2 Planned-422, D4b-1 Gate, Writer/Resolver Smoke | gezielt |
| Formatter / Pint / PHPStan | CI |

Keine produktiven Daten; keine E2E-Registry-Freigabe.

## Betroffene Dateien (erwartet)

- Neu: 4 Commands, `OaTkpPriceListActivationService`, optional JsonReader
- Neu: Feature-/MySQL-Tests unter `tests/Feature/OnlineAudio/` bzw. `tests/Feature/...Mysql*`
- Doku: dieser Auftrag + Update `fortschritt.md` / Abnahmekatalog nach Implementierung
- Bestehende Importer: nur bei nachgewiesenem Integrationsbedarf anfassen (z. B. Status-Guard eher in CLI/ActivationService)

## Technische Risiken

1. **Importer überschreibt Revisionen** (`firstOrNew` + Item-Delete) → CLI-Guard zwingend
2. **Keine DB-Constraint „eine active/Jahr“** → Race ohne `lockForUpdate` möglich
3. **Activate via altem Import-Pfad** bleibt im Service API möglich → dokumentieren; CLI darf ihn nicht nutzen; optional später Service-API härten (separater Mini-Slice falls nötig)
4. **Dry-Run Would-Counts** bei Platforms aktuell 0/0 → ggf. nur „valid“ melden oder Zählung in CLI vorbereiten ohne Importer-Umbau
5. **SQLite vs MySQL Locks** → parallele Activate-Tests primär MySQL

## Umsetzungsreihenfolge (kleinste sinnvolle Schritte)

1. JsonReader + `oa:import-platforms` (Dry-Run + Write) + Tests  
2. `oa:import-targetings` analog  
3. `oa:import-price-list` mit Draft-Zwang + Non-Draft-Guard + Tests  
4. `OaTkpPriceListActivationService` + `oa:activate-price-list` + atomare Tests  
5. MySQL Parallel-/Lock-Tests  
6. Pin-Regression + Doku Rollback + CI  

**STOP:** Keine Implementierung ohne gesonderte PO-Freigabe dieses Auftrags.

## OUT

- Produktiver Datenimport
- Registry-Release / D4d
- HTTP/Admin-UI-Import
- Deploy-Automatisierung
- Adserver-Surcharge-Importer
- Reporting
