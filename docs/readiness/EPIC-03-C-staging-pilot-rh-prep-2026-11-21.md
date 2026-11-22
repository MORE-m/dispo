# EPIC-03-C – Staging-Pilot Radio Hamburg – Readiness

**Datum:** 2026-11-22  
**Scope:** `inv_radio_hamburg` (kontrollierter Stammdaten-/Preislistenimport auf Staging)  
**Basis:** `main` `78e846b`; Transfer-Code erwartet aus PR #263 (HEAD `2289745`) nach Merge  
**STOP:** Kein Export, kein Staging-Import, kein Deployment, keine Preislisten-Aktivierung in diesem Slice

Verwandte Doku:

- `docs/readiness/EPIC-03-B-staging-pilot-runbook.md`
- `docs/entscheidungen/EPIC-03-B-master-data-transfer.md`

---

## 1. Staging-Preflight (read-only)

| Prüfpunkt | Ist | Bewertung |
|---|---|---|
| App-Pfad (Jail) | `/www/staging.dispo.more-marketing.de/` | OK (Host-Äquivalent KeyHelp: `/home/users/spn26jz7/www/staging.dispo.more-marketing.de/`) |
| Deploy-SHA | `32852af12b162d17ee7416dc3ec7042cf1a0ad4f` | **STOP für Import:** liegt hinter `main`/`#263`; Ziel-Commit = Post-Merge-`main` mit EPIC-03-B |
| Deploy-Status | `laravel_public_active_migrated` (Cutover 2026-11-20) | OK als Staging-Basis |
| PHP CLI | 8.4.28 | OK |
| Laravel | 13.42.0 | OK |
| Extensions | openssl, json, xml, bcmath, ctype, curl, mbstring, fileinfo, pdo_mysql, tokenizer, zip | OK |
| memory_limit | `-1` | OK |
| DB-Name | `spn26jz7_dispo_staging` | OK (`--confirm-database`) |
| Migrationen | 79 gelaufen | Schema-Basis OK; Preislisten-Tabellen heißen `price_lists` / `price_list_items` (nicht `spot_price_lists`) |
| Staging-Admin | 1 User, `role=admin`, aktiv | OK (keine Secrets/E-Mails in diesem Bericht) |
| Inventare / Medien / Regeln | 0 / 0 / 0 | erwartet leer vor Pilot |
| Kategorien / Methoden | 6 / 5 (inkl. `average`, `free_position`) | OK |
| `price_lists` / Produktion | 0 / 0 | erwartet leer vor Pilot |
| `organizations` | 0 | Import legt Organisation an |
| `storage/app` Schreibrecht | ja (`mdt-reports` anlegbar) | OK |
| Backups | `/files/dispo-staging/backups/` vorhanden, `BACKUP_GATE=PASS`, Restore-Skript vorhanden | OK; **frisch vor Import erneut sichern** |
| Restore-Hinweis | `restore_temp_db=FAIL_NO_CREATE_PRIV`; Alternative same-DB-Import verifiziert | Rollback-Pfad dokumentiert, Temp-DB nicht verfügbar |
| Scheduler | nicht angefasst (manuell bereits getestet) | OK – keine Änderung |

**Ziel-Commit für Staging-Code:** nach erfolgreichem Merge von #263 der dann aktuelle `origin/main` (nicht `32852af`).

---

## 2. Radio Hamburg – Soll/Ist (Quelle: lokales `dispo_mat_core`, read-only)

Pilot-Inventar: **`inv_radio_hamburg`**.

| Objekt | Soll (Pilot) | Ist Quelle | Status |
|---|---|---|---|
| Inventar `inv_radio_hamburg` | aktiv | aktiv, Name „Radio Hamburg“ | OK |
| Werbemittel `spot_classic` | aktiv | aktiv „Werbespot“ | OK |
| Methode `average` | aktiv | aktiv „Durchschnitt“ | OK |
| Kategorie-Methoden Spots | average/calendar/fixed_price | vorhanden | OK |
| Aktive Rule RH × spot_classic | ≥1 | 1 aktiv (`booking_code=Spots (L)`, `default_length_seconds=30`, discount/AE ja, Verantwortung `disposition`) | OK |
| Weitere aktive Rules auf RH | fachlich für SWF-Abhängigkeiten | 32 aktive Rules gesamt (OA-Medien inaktiv bzw. Export-Excludes greifen) | OK / Export filtert OA |
| Spotpreisliste 2026 active | 1 Liste, Items | year=2026 rev=1 active, **72** Items | OK |
| Spotpreisliste 2027 active | 1 Liste, Items | year=2027 rev=1 active, **72** Items | OK |
| Spotpreisliste 2027 archived | nicht exportieren | rev=2 archived, 72 Items | bewusst nicht im Export |
| Produktionspreis 2026 | vorhanden | `spot_production` 600,00 active | OK |
| Produktionspreis 2027 | wünschenswert | **fehlt** in Quelle | Hinweis, kein Export-Blocker (Exporter verlangt Spot-Coverage 2026/2027, nicht zwingend Prod je Jahr) |
| Organisation | more Marketing | 1 Datensatz | OK |

### Erwartete Paket-/Import-Größenordnung (RH-only)

Nach `dispo:export-master-data --inventory=inv_radio_hamburg` (nach Merge #263):

| Datei / Entität | Erwartung (Größenordnung) |
|---|---|
| `inventories` | 1 (`inv_radio_hamburg`) |
| `advertising_media` | Spot + SWF-Medien aus Rules (ohne OA/`native_ad`-Excludes) |
| `inventory_medium_rules` | aktive Rules für RH im Scope (Quelle ~32 aktiv; Export filtert Excludes) |
| `price_lists` | **2** Drafts (2026 + 2027), je **72** Items → Import-Status `draft` |
| `production_price_lists` | ≥1 (2026 spot_production 600,00 als Draft) |
| `calculation_methods` | Abgleich inkl. `average` |
| `advertising_categories` | `spots` + `special_advertising_formats` |

### Abnahme-Soll nach Import (nur Draft, keine Activate)

1. Inventar `inv_radio_hamburg` sichtbar/aktiv wie Quelle.  
2. `spot_classic` wählbar; Methode `average` an Kategorie/Medium angebunden.  
3. Mindestens die RH×`spot_classic`-Rule aktiv mit denselben Kernfeldern (Länge 30, Booking-Code, Flags).  
4. Zwei Spotpreislisten 2026/2027 als **`draft`**, je 72 Positionen; SHA/Manifest deckungsgleich.  
5. Produktionspreis 2026 als **`draft`**, 600,00.  
6. Keine Auto-Aktivierung; OA nicht im Paket.  
7. Staging-Admin unverändert nutzbar.

---

## 3. Deployment- und Importplan (sequenziell)

**Global-STOP:** Nur nach Merge #263 + grüner Post-Merge-CI + ausdrücklichem **PO-GO**.

| # | Schritt | Kommando / Aktion | Erwartung | STOP wenn |
|---|---|---|---|---|
| 1 | Main-Stand | `git fetch origin && git rev-parse origin/main` | SHA enthält EPIC-03-B | #263 nicht gemergt / CI rot |
| 2 | Deployment-Readiness | Staging Preflight (Abschnitt 1) wiederholen | Admin, Schreibrechte, Backup-Pfad OK | DB/Storage/PHP unpassend |
| 3 | Staging-Code | Deploy **Ziel-SHA** nach `/www/staging...` (PO-GO) | `RELEASE_SHA.txt` = Ziel-SHA; `php artisan migrate --force` nur bei pending | Deploy ohne PO-GO; Migration fail |
| 4 | DB-Backup | Dump nach `/files/dispo-staging/backups/` mode 600 | Datei + SHA256 notiert | Backup fehlgeschlagen |
| 5 | Restore-Check | Stichprobe Restore-Pfad / Skript lesbar; Gate-Doku | Rollback-Pfad klar | Kein Restore-Pfad |
| 6 | Export lokal | `php artisan dispo:export-master-data --path=./storage/app/mdt-export-rh --inventory=inv_radio_hamburg --git-sha=$(git rev-parse HEAD)` gegen **read-only** `dispo_mat_core` | Paket + Manifest | Coverage-Fehler (fehlende active 2026/2027) |
| 7 | Integrität | `sha256sum -c checksums.sha256`; Manifest-Scope prüfen | OK, nur RH, `price_lists_export_as_draft=true` | Checksum/Scope abweichend |
| 8 | Transfer | Paket **außerhalb** Document Root nach Staging | Dateien mode restriktiv | Paket im Webroot |
| 9 | Dry-Run | `php artisan dispo:import-master-data /pfad/paket --dry-run --confirm-database=spn26jz7_dispo_staging` | Report creates/unchanged/conflicts | conflicts>0 oder Guard-Fail |
| 10 | Fach-Abnahme Report | Dry-Run-Zahlen vs. Abschnitt 2 | PO/Fach OK | Abweichung Items/Rules |
| 11 | Apply | **nur mit gesondertem PO-GO** `--apply --confirm-database=spn26jz7_dispo_staging` | Draft-Listen geschrieben | Apply ohne GO |
| 12 | Draft-Prüfung | Admin UI / SQL: Status ausschließlich `draft` | keine `active` aus Import | irgendeine Auto-Activate |

Scheduler-Crontab: **nicht ändern**.

---

## 4. GO / STOP

| Entscheidung | Status |
|---|---|
| Readiness-Vorbereitung (Doku, Preflight, Soll/Ist, Plan) | **GO** |
| Staging-Code-Update auf Transfer-SHA | **STOP** bis Merge #263 + PO-GO |
| Export aus `dispo_mat_core` | **STOP** bis PO-GO (Vorbereitung OK) |
| Staging-Import Dry-Run/Apply | **STOP** bis Deploy + Backup + PO-GO |
| Preislisten-Aktivierung | **STOP** (eigener Auftrag) |

### Offene Blocker / Hinweise

1. **Staging-Code `32852af` ≠ Post-#263-Main** – Transfer-CLI fehlt bis Deploy.  
2. Staging-Katalog leer – erwartet; Import füllt RH.  
3. Produktionspreis **2027** fehlt in Quelle – dokumentieren; Spot 2026/2027 OK.  
4. Restore nur same-DB (kein CREATE-Priv für Temp-DB).  
5. PR #263 CI muss grün sein, bevor Pilot-GO.

### Bewusst nicht gemacht

- Kein Export/Import, kein Deploy, keine SMTP-/Scheduler-Änderung, keine Activate, keine Änderung an `dispo_mat_core` oder PR #263.
