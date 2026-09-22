# Fortschritt V1

Stand: 22. September 2026 (Feature **DSP-DCP-001** abgeleiteter Dispo-Kampagnenzeitraum,
Branch `feat/derived-dispo-campaign-period`, Base `4d245a6a…` = PR #68).
**`BL-P4-02a`–`02e`** und **SPT-008 Dateiexport** auf **`main`**. Abbinder (SPT-013)
bewusst zurückgestellt. Kundenexport aus Kalkulation bleibt Folgeauftrag.

## Aktuelle Phase

Phase 4 / Dispo-Erweiterungen: **`BL-P4-02` bleibt offen** (Abbinder/SPT-013,
operative Blockplanung). **SPT-008** manuelle Abnahme offen.
**DSP-DCP-001** (abgeleiteter Kampagnenzeitraum am Dispoauftrag) in Umsetzung /
Abnahme.

## Aktuelle Aufgabe

**DSP-DCP-001** automatisch abgeleiteter globaler Kampagnenzeitraum am Dispoauftrag:
additiv getrennt von Calc-Origin-`campaign_period`; Freeze bei Create/Revision;
Status `complete|partial|open|legacy`; keine Live-Neuberechnung; kein Backfill;
Rechnungslogik nur vorbereitet (`complete`); keine Rechnungsautomatik.
E2E Port **8034** (`test:e2e:dspdcp001`).

## Zuletzt abgeschlossene Aufgabe (Umsetzung)

**DSP-DCP-001** (Branch `feat/derived-dispo-campaign-period`): serverseitige Ableitung
aus Frozen Dispo-Positionen (`planner_entries_snapshot` / `position_flight_period`),
additive Spalten an `dispo_orders`, UI mit Konflikt-Hinweis, Unit/Feature/MySQL/Vitest/
Playwright.

Zuvor **SPT-008 Dateiexport** (PR **#68**, Merge `4d245a6a…`): interner XLSX-Export;
manuelle Abnahme offen.

## DSP-DCP-001 – Abgeleiteter Dispo-Kampagnenzeitraum (September 2026)

| Teil | Status |
|------|--------|
| Calc-Origin-`campaign_period` unverändert (read-only, kopiert) | **umgesetzt** |
| Additives Derived-Modell an `dispo_orders` | **umgesetzt** |
| Quellen: Calendar=`planner_entries_snapshot`, Average=`position_flight_period` | **umgesetzt** |
| Status `complete`/`partial`/`open`/`legacy` + Provenienz-JSON | **umgesetzt** |
| Freeze in Create-TX nach Snapshots + Dyn-Feld-Capture | **umgesetzt** |
| Kein Backfill / Legacy ohne Datumswerte | **umgesetzt** |
| UI getrennt + Konflikt-Hinweis (nicht blockierend) | **umgesetzt** |
| Rechnung nur bei `complete` vorbereitet, Automatik **nicht** | **dokumentiert** |
| Abbinder / Kunden-Calc-Export | **bewusst nicht** |
| Manuelle Abnahme | **offen** |

## BL-P4-02e – Tandem / Tridem / SPT-012 (September 2026)

| Teil | Status |
|------|--------|
| Werbemittel-Feld `component_profile` (`tandem`\|`tridem`; `null` = optional Hauptspot/Allonge) | **umgesetzt** (`main`, PR #61) |
| Positions-Freeze `component_profile` (Calc + Dispo) | **umgesetzt** |
| Tandem: 1× Hauptspot + 1× Reminder; Tridem: 1× Hauptspot + 2× Reminder (`sort` 1–3) | **umgesetzt** |
| Strategie verbindlich **`shared_total_length`**; **`individual`** fail-closed | **umgesetzt** |
| Rechenweg wie 02c über Gesamtlänge; **keine** ×2/×3 auf Spotanzahl | **umgesetzt** |
| Tandem-/Tridem-**Einheiten** vs. abgeleitete Ausstrahlungen (×2/×3 nur Anzeige) | **umgesetzt** |
| Average + Calendar + Festpreis-Abschluss (02d) kompatibel | **umgesetzt** |
| Dispo-Snapshot `component_profile` + Komponenten | **umgesetzt** |
| Manuelle UX-Abnahme | **erfolgreich** |
| **SPT-012** | **erledigt** |
| **SPT-013** Abbinder | **offen** (bewusst zurückgestellt; Hauptspot/Allonge + Reminder für Tandem/Tridem **teilweise**) |
| SPT-008 Dateiexport, Registry `fixed_price` | **Export umgesetzt** (manuelle Abnahme offen) bzw. **`planned`** |
| `BL-P4-02` insgesamt | **offen** |

## BL-P4-02d – Preisabschluss Festpreis / N/N (September 2026)

| Teil | Status |
|------|--------|
| Positionsfeld `pricing_settlement_mode` `normal`\|`fixed_price` | **umgesetzt** (`main`, PR #60) |
| Berechnungsbasis bleibt `average`\|`calendar` (kein Registry-Wechsel auf `fixed_price`) | **umgesetzt** |
| Registry `calculation_methods.fixed_price` | weiter **`planned`** / **nicht released** |
| N/N-Festpreis; Mediabrutto aus gewählter Basis; N/N-Endinvest unverändert | **umgesetzt** |
| Keine Forward-Rabatte auf Festpreis-N/N; AE rückwärts ausweisen; Payfaktor/Gesamtabschlag | **umgesetzt** |
| Pin-Vertrag 02a bei Abschluss-/Basiswechsel | **umgesetzt** |
| Dispo-Snapshot `pricing_settlement_mode` + `fixed_price_nn` | **umgesetzt** |
| Budget-Übernahme setzt Abschluss zurück auf `normal` | **umgesetzt** |
| E2E isoliert: `playwright.blp402d.config.ts` (Port **8026**) | **umgesetzt** |
| Manuelle UX-Abnahme | **erfolgreich** |
| SPT-008 Dateiexport, Abbinder (SPT-013) | **Export umgesetzt** (manuelle Abnahme offen); Abbinder **offen** (Tandem/Tridem siehe **BL-P4-02e**) |
| `BL-P4-02` insgesamt | **offen** |

**Hinweis:** Live wählbar ist **`pricing_settlement_mode=fixed_price`** zusammen mit
Basis **`average`** oder **`calendar`**. Der Registry-Eintrag **`fixed_price`** ist
**nicht** der produktive Methodenwechsel (Phase 7 / andere Profile bleiben getrennt).

## BL-P4-02c – Spot-Komponenten / Hauptspot + Allonge / AT-04 (September 2026)

| Teil | Status |
|------|--------|
| Hauptspot + Allonge sichtbar/editierbar/speicherbar | **umgesetzt** (PR #59) |
| Strategien `shared_total_length` / `individual` | **umgesetzt** (PR #59) |
| Admin-Strategie je Inventar-/Werbemedium-Regel + Positions-Freeze | **umgesetzt** (PR #59) |
| Average + Calendar unterstützen beide Strategien | **umgesetzt** (PR #59) |
| Dispo-Snapshot `components_snapshot` + Anzeige | **umgesetzt** (PR #59) |
| Spotgewichteter `average_second_price` (kein Längen×Index-Rückrechnen) | **umgesetzt** (PR #59) |
| Komponenten-Payload absent/`null`/`[]` + Strategie fail-closed | **umgesetzt** (PR #59) |
| Kanonische Labels + Admin-`lock_version` | **umgesetzt** (PR #59) |
| Calendar-Einträge bleiben beim kompatiblen Inventar-/Strategiewechsel erhalten | **umgesetzt** (PR #59) |
| Manuelle UX-Abnahme AT-04 | **erfolgreich** |
| Tandem / Tridem (**SPT-012**) | **umgesetzt** (**BL-P4-02e**, `main`, PR #61) |
| Abbinder (**SPT-013**) | **offen** (bewusst zurückgestellt; Hauptspot/Allonge + Reminder teilweise) |
| SPT-014 Hauptspot/Allonge | **umgesetzt** (PR #59) |
| Festpreis-Abschluss (`BL-P4-02d`) | **umgesetzt** (`main`, PR #60; Registry `fixed_price` weiter planned) |
| SPT-008 Dateiexport | **umgesetzt** (automatisiert getestet; manuelle Abnahme offen; Average-Export bewusst nicht) |
| `BL-P4-02` insgesamt | **offen** |

## BL-P4-02b – Kalenderplaner / AT-02 (September 2026)

| Teil | Status |
|------|--------|
| Stunden-/datumsbezogene Planerzellen (`SPT-005`–`SPT-007`, Abnahme AT-02) | **umgesetzt** (PR #58) |
| Echte Wochenmatrix (Mo–So × Preisstunden, Spotanzahl direkt je Datum-/Stundenzelle) | **umgesetzt** (PR #58) |
| Jahresvertrag Kalender: eine Position = ein Preisjahr; jahresübergreifend getrennte Positionen | **umgesetzt** (PR #58) |
| Wochen-/Monatsnavigation + „Aktuelle Woche“; Einträge außerhalb sichtbarer Woche bleiben im State | **umgesetzt** (PR #58) |
| Persistenz `calculation_position_planner_entries` + Payload/Roundtrip | **umgesetzt** (PR #58) |
| Dispo-Positions-Snapshot `planner_entries_snapshot` + lesbare Anzeige (`SPT-008` Anzeige) | **umgesetzt** (PR #58) |
| Dispo-Export Spot-Verteilung (`SPT-008` Export) | **umgesetzt** (XLSX; automatisiert getestet; manuelle Abnahme offen) |
| Registry `spot_classic`/`calendar` | **released / v1** (PR #58) |
| Wizard-Kalender-UI + Validierung (keine Average-Zeiträume parallel) | **umgesetzt** (PR #58) |
| E2E isoliert: `playwright.blp402b.config.ts` (Port 8022) | **umgesetzt** (PR #58) |
| Komponenten / AT-04 | **umgesetzt** (PR #59); Festpreis-Abschluss siehe **BL-P4-02d** |
| `BL-P4-02` insgesamt | **offen** |

## BL-P4-02a – Average-Abnahme + Preislisten-Pin (September 2026)

| Teil | Status |
|------|--------|
| Methodenwechsel bei gleichem Inventar/Jahr behält `price_list_id`/`price_list_version` | **umgesetzt** (`main`, PR #56) |
| Kein stilles Live-Rebind über `resolveActivePosition` bei reinem Methodenwechsel | **umgesetzt** (`main`, PR #56) |
| Neubindung nur neu / Inventarwechsel / expliziter Jahrwechsel (01c unverändert) | **unverändert gültig** |
| AT-01/03/23/24 gezielte Härtung (ohne Kalender-/Komponenten-Scope von 02b) | **gehärtet** (`main`, PR #56) |
| `BL-P4-02` insgesamt | **offen** (02a–02e auf `main`; SPT-008 Export umgesetzt/Abnahme offen; Abbinder offen) |

## BL-P4-01c – Wizard-Preisjahrwahl (September 2026)

| Teil | Status |
|------|--------|
| UX-GATE-D Teilfreigabe Wizard-Jahreswahl (PO-PRI-YEAR-1) | **freigegeben / umgesetzt** |
| Pro Position: aktuelles Jahr Default, Folgejahr nur bei Active | **umgesetzt** |
| Rebind nur bei tatsächlichem Jahrwechsel + bewusstem Speichern | **umgesetzt** |
| Historische Pins stabil (auch archiviert / neuere Active) | **umgesetzt** |
| `expected_price_list_id` → HTTP 409 bei Active-Wechsel | **umgesetzt** |
| Budget denselben Jahresvertrag | **umgesetzt** |
| E2E isoliert: `playwright.blp401c.config.ts` (Port 8019) | **umgesetzt** |
| MORE-Produktiv-Workbook-Mapping | **offen** (Lieferdaten/Adapter; macht 01c nicht unvollständig) |
| `BL-P4-01` insgesamt | **erledigt** |

## BL-P4-01b – Preislisten-Excel-Import (September 2026)

| Teil | Status |
|------|--------|
| UX-GATE-D Teilfreigabe Excel-Import (ohne Auto-Aktivierung) | **freigegeben / umgesetzt** |
| `price_list_imports` + privater Storage + Report JSON | **umgesetzt** |
| PhpSpreadsheet Parser (XLSX/XLS), kanonischer Spaltenvertrag | **umgesetzt** |
| Inventarauflösung Code/Name + dokumentierte Aliase | **umgesetzt** |
| Preview mit Fingerprint, Confirm atomar je Inventar/Jahr | **umgesetzt** |
| Formeln fail-closed + Workbook-Preflight vor Materialisierung | **umgesetzt** |
| Upload temp→Archiv, Confirm-Audit in derselben Transaktion | **umgesetzt** |
| PO-PRI-HOURS-1 sparse Stunden im Import | **umgesetzt** |
| E2E isoliert: `playwright.blp401b.config.ts` (Port 8018) | **umgesetzt** |
| MORE-Produktiv-Workbook-Mapping | **offen** (Beispieldatei) |
| Wizard-Jahreswahl | **BL-P4-01c** |
| `BL-P4-01` insgesamt | **erledigt** (nach 01c) |

## BL-P4-01a – Preislisten-Admin-Lifecycle (September 2026)

| Teil | Status |
|------|--------|
| UX-GATE-D Teilfreigabe Preislisten-Admin (ohne Excel-Import) | **freigegeben / umgesetzt** |
| `year`, `revision_number`, `lock_version` an `price_lists` | **umgesetzt** |
| Höchstens eine Active je Inventar/Jahr (SQLite Partial-Index, MySQL Generated Columns) | **umgesetzt** |
| Draft anlegen, kopieren, Basispreise, Activate/Archive | **umgesetzt** |
| Jahresdefault = aktive Liste des aktuellen Kalenderjahres (Europe/Berlin) | **umgesetzt** |
| Budget-Fingerprint inkl. `price_list_id` + Jahr + Version | **umgesetzt** |
| Historische Preislisten-IDs und Versionsstrings unverändert | **umgesetzt** |
| **PO-PRI-HOURS-1** buchbare Stunden je Tagesgruppe unabhängig | **umgesetzt** |
| E2E isoliert: `playwright.blp401a.config.ts` (Port 8017) | **umgesetzt** |
| Excel-Import | **BL-P4-01b** |
| Wizard-Jahreswahl | **BL-P4-01c** |
| `BL-P4-01` insgesamt | **erledigt** (nach 01c) |

## BL-P2-01a – Inventory Admin Lifecycle (September 2026)

| Teil | Status |
|------|--------|
| UX-GATE-D Teilfreigabe Inventar-Admin-Lifecycle | **freigegeben / umgesetzt** |
| Hub-Karte Inventare / Kombis | **umgesetzt** |
| Liste, Detail, Anlegen, Metadaten, Sortierung | **umgesetzt** |
| Aktivieren/Deaktivieren ohne Hard Delete | **umgesetzt** |
| Impact-Preview (Calc/Dispo/Preislisten/Regeln) | **umgesetzt** |
| `lock_version` + HTTP 409 | **umgesetzt** |
| Audit `inventory.created/updated/deactivated/reactivated` | **umgesetzt** |
| Historischer Freeze `calculation_positions.inventory_name/code` | **umgesetzt** |
| E2E isoliert: `playwright.blp201a.config.ts` (Port 8016); Hauptsuite `testIgnore` | **umgesetzt** |
| Kombi-Mitgliedschaften (`BL-P2-01b`) | **entfallen** (PO-BL-P2-01-KOMBI; kein V1-Slice) |
| `BL-P2-01` insgesamt | **erledigt** |
| Preislisten-Admin / Kombinationstabellen / 14-Inventar-Seeder | **nicht in diesem Slice** |

## DF-3-RULE-C – Administrativer Regel-Editor (September 2026)

| Teil | Status |
|------|--------|
| Desired-State Preview/Apply auf Draft-`field_rules` | **umgesetzt** |
| `FieldSet.lock_version` + atomarer Replace + No-op | **umgesetzt** |
| RULE-A-V1-Vertrag unverändert (keine neuen Ops) | **umgesetzt** |
| System-Seed-Schutz `system_calculation_core` (content-basiert) | **umgesetzt** |
| Sort serverseitig; UI Nach oben/unten; lokales Duplizieren | **umgesetzt** |
| Admin-UI auf Feldset-Versionsseite | **umgesetzt** |
| Audit `field_set.rules_replaced` | **umgesetzt** |
| Keine Migration; Snapshots unverändert | **umgesetzt** |
| Preview-Basis aus Membership-`visible`/`required_override` | **umgesetzt** |
| Calc-Origin `action_target_readonly` im Admin-Kernkontext | **umgesetzt** |
| Editierbare Header-/Positions-Beispielwerte in der UI | **umgesetzt** |
| Freier-Draft-E2E inkl. CRUD/Duplikat/Read-only Active | **umgesetzt** |
| SystemFieldSetting (ADV-002) | **außerhalb DF-3** |

**PO-Festlegungen:** Desired-State; Seed nur in `system_calculation_core` via
`SEED_RULE_DEDUPE_SHA256`; leere Regeln nur freie Sets; require+unsichtbar
erlaubt mit Warnung; eine Beispielposition in Preview.

## DF-3-RULE-B – Runtime Visible/Required (September 2026)

| Teil | Status |
|------|--------|
| Gemeinsame Client-Auswertung (`snapshot-field-runtime.ts`) | **umgesetzt** |
| Calc-/Dispo-UI Effective Visible/Required | **umgesetzt** |
| Keep-on-missing (Calc Text/Period/Boolean + Choice) | **umgesetzt** |
| DYN-005 Writer/Submit/Create/Partial-Choice | **umgesetzt** |
| Schema-Props inkl. basis `visible=false`, `action_target_readonly` | **umgesetzt** |
| Kontexttreue Positions-Rules (kein Merge für Runtime) | **umgesetzt** |
| Regel-Editor (RULE-C) | **umgesetzt** |
| SystemFieldSetting (ADV-002) | **außerhalb DF-3** |

**PO-Festlegungen:** Pending-Wert bei Unsichtbarwerden persistieren (Option A);
fehlender Payload-Key = Keep; Calc-Draft ohne neue statische Required-Sperre;
Dispo-Partial ohne neue rule-required-Sperre; volle Pflicht nur Create/Copy/Sync/Submit.

## DF-3-RULE-A – Regelvertrag / Evaluatoren (September 2026)

| Teil | Status |
|------|--------|
| `FieldRuleContract` Allowlist + flaches all/any + Typmatrix | **umgesetzt** |
| Cross-Scope (Header↔Position), Position→Header block | **umgesetzt** |
| `set_visible` / `require_field` + DYN-005 Contract | **umgesetzt** |
| Calc-Origin via Provenance (`action_target_readonly`); exakte Seed-Ausnahme | **umgesetzt** |
| Selbstreferenz-`set_visible` block; `require_field` Selbstreferenz erlaubt | **umgesetzt** |
| Dedupe-Kanon (Seed-Hash stabil) | **umgesetzt** |
| Integrity + Preview/Activate/Freeze/Merge fail-closed am Contract | **umgesetzt** |
| TS-Parity fail-closed + Legacy-Seed-Adapter | **umgesetzt** |
| Calc-/Dispo-UI-Verdrahtung inkl. DYN-005 für statisch required (RULE-B) | **umgesetzt (RULE-B)** |
| Regel-Editor (RULE-C) | **umgesetzt** |
| SystemFieldSetting (ADV-002) | **außerhalb DF-3** |

**RULE-A-/RULE-B-Grenze:** RULE-A liefert Vertrag und Effective-State. `validate()`
prüft nur regelbasiertes Required; statisches Required bleibt in Writern mit
Effective Visible (RULE-B/DYN-005). Partial-Save unverändert außer DYN-005.

## DF-3-REST-C3 – Dispo Choice-UI (September 2026)


Sichtbare Select-/Multi-Select-Felder im Dispoauftrag (Header und Positionen)
gegen eingefrorenes `options_json`, inkl. Calc-Origin-Provenance. Positions-
Schema je Dispoposition (kein First-wins über Effektiv-Snapshots).

| Teil | Status |
|------|--------|
| `position_field_schemas` je Position | **umgesetzt** |
| Native Select/Multi Header+Position | **umgesetzt** |
| Touched-only Partial-Save | **umgesetzt** |
| Calc-Origin read-only + Provenance | **umgesetzt** |
| REST-C E2E Port 8014 erweitert | **umgesetzt** |
| Regelmatrix / Regel-Editor | **umgesetzt (RULE-C)** |

## DF-3-REST-C2 – Calc Choice-UI (September 2026)

Sichtbare Select-/Multi-Select-Felder im Kalkulationswizard (Header und
Positionen) ausschließlich gegen Freeze-`options_json` und gespeicherte
C1-Werte. Keine Live-Options.

| Kriterium | Status |
|---|---|
| Header-/Positions-Select (Radix) inkl. „Keine Auswahl“ | **umgesetzt** |
| Multi als zugängliche Checkbox-Liste + lokale Suche | **umgesetzt** |
| Historisch inactive anzeigen / ersetzen bzw. entfernen | **umgesetzt** |
| Required-Markierung ohne Draft-Block; Visible erhält State | **umgesetzt** |
| Partial Save: fehlender Key = Keep; `null`/`[]` = bewusst leer | **umgesetzt** |
| Vitest + Featuretests Props/Payload | **umgesetzt** |
| Playwright isoliert Port 8014 (`playwright.df3restc.config.ts`) | **umgesetzt** |
| CI: isolierte REST-B- und REST-C-Suites in `tests.yml` | **umgesetzt** |
| Dispo-Choice-UI (C3) | **umgesetzt (C3)** |
| Regel-Editor / volle Regelmatrix | **umgesetzt (RULE-C)** |

## DF-3-REST-C1 – Choice-Wertmodell / Persistenz (September 2026)

Serverseitige Select-/Multi-Select-Runtime. **Auf `main` gemergt (PR #40).**

| Kriterium | Status |
|---|---|
| `value_json` an vier Wertetabellen (additiv, nullable) | **umgesetzt** |
| `ChoiceFieldValueContract` (Normalize, historisch inactive, XOR) | **umgesetzt** |
| Calc/Dispo Persistenz, Export, Copy, Completeness | **umgesetzt** |
| Schema-Props inkl. `options_json` | **umgesetzt** |
| Sichtbare Calc-UI (C2) | **umgesetzt (C2)** |
| Sichtbare Dispo-UI (C3) | **umgesetzt (C3)** |
| Regel-Editor / volle Regelmatrix | **umgesetzt (RULE-C)** |

Leer-/XOR-Vertrag: Select leer = `null`; Multi leer = `[]`; Choice nur in
`value_json`; skalare Kanäle bei Choice genullt (App-Layer; kein DB-CHECK wegen
SQLite/MySQL-Symmetrie). Spaltentyp Laravel `json()` (MySQL `json`, MariaDB
wie bestehende `options_json` oft `longtext` ohne JSON-Validierungs-CHECK).
Historisch inaktive Keys nur gegen DB-Vorzustand. Required: Calc-Draft wie Text
ohne statisches required; Pflicht bei Dispo-Create und Dispo-Submit/Partial-Gates.
Freeze/`options_json` einzige Optionsquelle. Dispo-Create sperrt die
Kalkulationszeile vor Value-Copy und serialisiert damit gegen parallele
Calc-Updates (kein gemischter Calc-Zustand im neu erstellten Auftrag).

## DF-3-REST-B – Options-Admin-UI (September 2026)

Admin-UI für versionierte Auswahloptionen eigener `select`/`multi_select`-
Felddefinitionen. Desired-State Preview/Apply ausschließlich über
`FieldDefinitionOptionsWriter` / `FieldDefinitionOptionContract`. Minimale
read-only Preview-DTO-Erweiterung am Writer. Keine Migration. Keine Runtime.
**Vollständig umgesetzt und in PR #39 geprüft.**

| Kriterium | Status |
|---|---|
| Choice-Typen anlegen/ändern (vor Nutzung) | **umgesetzt (PR #39)** |
| Options-Sektion auf Definitions-Detail | **umgesetzt (PR #39)** |
| Preview/Apply + 409/422 DE + No-op | **umgesetzt (PR #39)** |
| Select-/Multi-Select-Runtime (`value_json`) | **C1–C3 umgesetzt (Calc- und Dispo-UI)** |
| Regel-Editor / volle Regelmatrix | **umgesetzt (RULE-C)** |

## DF-3-REST-A – Options-Fundament (September 2026)

Versioniertes Optionsmodell an `FieldDefinitionRevision`, Enum `select` /
`multi_select`, atomarer Desired-State-Writer, additives Gen3-Freeze
(`options_json`), Integrity fail-closed. Keine Admin-/Runtime-UI in diesem
Slice. **Auf `main` gemergt (PR #38).**

| Kriterium | Status |
|---|---|
| `FieldType` select / multi_select | **umgesetzt** |
| Relationale Optionszeilen pro Revision | **umgesetzt** |
| Desired-State-Writer inkl. No-op / Deaktivierung statt Delete | **umgesetzt** |
| Gen3 additives Options-Freeze + Fingerprint | **umgesetzt** |
| Options-Admin-UI | **umgesetzt in PR #39 (REST-B)** |
| Calc-Dispo-Runtime | **C1–C3 umgesetzt (Calc- und Dispo-Choice-UI)** |
| Regel-Editor / volle Regelmatrix | **umgesetzt (RULE-C)** |


## ADV-001c4b – Sichtbare Wizard-Methodenauswahl (September 2026)

Sichtbare Berechnungsmethoden im Kalkulationswizard über die c4a-Props
`calculation_method_options` sowie Freeze-Read-Felder. Allgemeiner Medienfilter
ohne `code === spot_classic`-Hardcode (aktiv + buchbar + Inventarregel).
0-/1-/n-Optionen-UX; historische eingefrorene Methoden mit Hinweis, ohne
automatischen Wechsel. Medienwechsel setzt Methode zurück; reiner Inventarwechsel
erhält Key. Moderner Wizard sendet `calculation_method_key` (kein konkurrierendes
`spot_method`). Budget bleibt average ohne Auswahl. Keine Migration, keine neue
Engine, `calendar`/`fixed_price` weiter planned. Mehrmethoden-UX in Vitest mit
synthetischen Props; isolierte Playwright-Suite `playwright.adv001c4b.config.ts`.
**Auf `main` gemergt (PR #37).**

| Kriterium | Status |
|---|---|
| Sichtbare Wizard-Methodenauswahl | **umgesetzt (c4b)** |
| Medienfilter ohne Spot-Classic-Code-Hardcode | **umgesetzt** |
| Historische Methodendarstellung ohne Auto-Wechsel | **umgesetzt** |
| Budget ohne Methodenauswahl | **unverändert average** |
| Neue Engine / Registry-Mutation | **nicht** |

## ADV-001c4a – Methodenoptions-Resolver und Freeze-Edit-Semantik (September 2026)

Serverseitige Auflösung auswählbarer Berechnungsmethoden über
`AdvertisingMediumCalculationMethodOptionsResolver` (Selectability ausschließlich
über `AdvertisingMediumLiveBookability`). Request-Feld `calculation_method_key`
presence-aware; `spot_method` als Legacy-Alias (Konflikt → 422). Freeze bytegenau
bei unverändertem Medium+Key inkl. reinem Inventarwechsel; Re-Freeze nur bei neuer
Position, Mediumwechsel oder tatsächlichem Methodenwechsel. Wizard-Props additiv
mit `calculation_method_options` und Freeze-Read-Feldern; sichtbare Methoden-UX
in **c4b**. Budget bleibt average/v1. Keine Migration.

| Kriterium | Status |
|---|---|
| MethodOptionsResolver + Wizard-Props | umgesetzt |
| Request-/Legacy-Alias + presence-aware Semantik | umgesetzt |
| Freeze-Edit-Matrix inkl. Inventar-only | umgesetzt |
| Sichtbare Wizard-Methodenauswahl | **umgesetzt (c4b)** |

## ADV-001c3c – Medium-Overrides / Mode / Medium-Default (September 2026)

Atomare Verwaltung von `calculation_method_mode` (inherit/override), gespeicherten
Medium-Methodenzuordnungen und Medium-Default als Desired State (Preview + Apply).
Bei inherit bleibt nur die Kategorie wirksam; gespeicherte Overrides bleiben
erhalten und unwirksam. Bei override kein Fallback auf die Kategorie.
Kein Hard Delete; fehlende Payload-Zeilen deaktivieren Assignments.
`engine_profile_key` nie aus Admin; neu `null`, bestehende unverändert.
Override-Default nur Released+Profil+aktive Zuordnung; gespeicherter Default bei
inherit nur Mitgliedschaftspflicht. Bestandsschutz bisher buchbarer Medien via
`AdvertisingMediumLiveBookability` mit `MediumMethodCatalogSnapshot`.
Lock: Medium → Kategorie → Methoden ASC → Cat-Assignments ASC → Med-Assignments ASC.
c3b1: aktive gespeicherte Medium-Assignments blockieren Methodendeaktivierung
auch bei inherit. No-op ohne Mutation/Audit. Keine Migration. c4a/c4b umgesetzt.

| Kriterium | Status |
|---|---|
| Preview/Apply Desired State + Mode + Fingerprint/`lock_version` | umgesetzt |
| inherit/override Semantik (gespeichert vs. wirksam) | umgesetzt |
| Default-/Profilvertrag + Bookability-Schutz | umgesetzt |
| Werbemittel-Detail Methodenabschnitt | umgesetzt |
| Positions-Methodenwahl (Backend c4a / UX c4b) | **c4a/c4b umgesetzt** |

## ADV-001c3b2 – Kategorie-Desired-State / Default (September 2026)

Atomare Verwaltung der Kategorie-Methodenzuordnungen und des Kategorie-Defaults
als Desired State (Preview + Apply). Kein Hard Delete; fehlende Payload-Zeilen
deaktivieren vorhandene Assignments. `engine_profile_key` nie aus Admin;
neue Zeilen `null`, bestehende Werte unverändert. Strenger Default-Vertrag
(Released + Profil + aktive Zuordnung). Bestandsschutz bisher buchbarer
Inherit-Medien via `AdvertisingMediumLiveBookability`-Simulation
(`CategoryMethodCatalogSnapshot`). Lock: Kategorie → Methoden ID ASC →
Kategorie-Assignments ID ASC. Identischer State = No-op ohne Mutation/Audit.
Keine Migration. c3c/c4a/c4b umgesetzt.

| Kriterium | Status |
|---|---|
| Preview/Apply Desired State + Fingerprint/`lock_version` | umgesetzt |
| Default-Vertrag Released/ausführbar | umgesetzt |
| Bookability-Simulation + Inherit-Schutz | umgesetzt |
| No-op ohne Versionssprung/Audit | umgesetzt |
| Kategorie-Detail UI Methodenabschnitt | umgesetzt |
| Medium-Overrides / Mode | **c3c** |
| Positions-Methodenwahl (Backend c4a / UX c4b) | **c4a/c4b umgesetzt** |

## ADV-001c3b1 – Methodenstammdaten und Lifecycle (September 2026)

Systemdefinierte Berechnungsmethoden im Katalog-Admin. Keys unveränderlich;
kein Create/Delete. Metadaten: Name, Hilfetext, Sortierung. `is_active` nur über
Preview/Deaktivieren/Reaktivieren. Deaktivierung blockiert bei aktiven
Kategorie- oder Mediumzuordnungen (kein Force, keine Kaskade). Registry-Paare
read-only (0..n Profile je Methode). Keine `engine_profile_key`-Ableitung.
Assignment-Aktivierung: Method-Lock + Aktivitätsprüfung
(`CalculationMethodAssignmentActivationGuard`). Keine Migration.

| Kriterium | Status |
|---|---|
| Hub-Kachel + Index/Detail | umgesetzt |
| Metadaten-PUT ohne is_active/key/Profil | umgesetzt |
| Deaktivierung nur ohne aktive Assignments | umgesetzt |
| Preview/Fingerprint/lock_version/Audit | umgesetzt |
| Registry-Paare deterministisch read-only | umgesetzt |
| Kategorie-Desired-State / Default | **c3b2** |
| Medium-Overrides / Mode | **c3c** |
| Positions-Methodenwahl (Backend c4a / UX c4b) | **c4a/c4b umgesetzt** |

## ADV-001c3a – Engine-unabhängige Medienpflege (September 2026)

Neue Werbemittel erhalten `kind=null`. Legacy-`kind` ist kein Adminfeld und
bleibt bei Spot Classic unverändert. Katalogaktivität und technische
Buchbarkeit für neue Kalkulationen sind getrennt. Zentrale Auswertung über
`AdvertisingMediumLiveBookability` (gleicher Vertrag für Admin-Status,
Wizard-Props und Live-Pfad im FreezeResolver/CatalogResolver). Wizard lässt
für neue Positionen nur `is_bookable_for_new_positions=true` zu. Keine
Methodenkarte; Methoden-Admin folgt in c3b/c3c; Positionsauswahl in c4.

| Kriterium | Status |
|---|---|
| Create mit `kind=null`, `kind` prohibited | umgesetzt |
| Null-kind Update/Category/Deakt./Reakt. | umgesetzt |
| Legacy Compatibility nur bei gesetztem kind | umgesetzt |
| Admin-Status Katalog vs. Buchbarkeit | umgesetzt |
| Zentrale Live-Buchbarkeit + Wizard-Filter | umgesetzt |
| Methoden-Admin / Defaults / Overrides | **c3b1/c3b2/c3c** |
| Positions-Methodenwahl (Backend c4a / UX c4b) | **c4a/c4b umgesetzt** |

## ADV-001c2 – Dual-Read/Write + Positions-Freeze (September 2026)

Vierteiliger Freeze-Vertrag an `calculation_positions` und
`dispo_order_positions`: `engine_profile_key`, `calculation_method_key`,
`calculation_method_name`, `algorithm_version` (alle vier gesetzt oder alle
`NULL`; partiell = fail-closed). Dual-Write schreibt Legacy (`kind`,
`spot_method`) und Freeze gemeinsam. Zentrale Auflösung über
`CalculationMethodFreezeResolver`. Legacy-Fallback nur
`spot_classic`/`average` → `spot_classic/average/Durchschnitt/v1`. Historische
unveränderte Positionen nutzen den Freeze (nicht Live-Default/Registry/Kind).
`advertising_media.kind` nullable; Mutation/Reaktivierung bei `kind=NULL` mit
deutschem 422 blockiert. Spot-Kategorie erhält Zuordnungen average/calendar/
fixed_price (`engine_profile_key=spot_classic`), Default `average`; Medien
bleiben `inherit`. Keine neue Engine; Gen-3-Snapshots unverändert.

| Kriterium | Status |
|---|---|
| Positions-Freeze-Spalten + Backfill Spot-Classic-Average | umgesetzt |
| Spot-Kategorie-Methodenzuordnungen + Default average | umgesetzt |
| Dual-Read/Write Kalkulation + Dispo-Übernahme | umgesetzt |
| `CalculationMethodFreezeResolver` + Registry-Anbindung | umgesetzt |
| Historische Stabilität (unveränderte Kombination) | umgesetzt |
| `advertising_media.kind` nullable + Null-Guards (bis c3a) | umgesetzt / **c3a ersetzt Guards** |
| Katalog-/Methoden-Admin | **c3a Medien; c3b/c3c Methoden** |
| Positions-Methodenwahl / Selectability | **c4a/c4b umgesetzt** |
| Legacy-Felder entfernen / Gen-4 | **nicht** |

## ADV-001c1 – CALC-KIND-FOUNDATION Schema (September 2026)

Variante B: fachliche Methode ≠ Engine-Profil ≠ Algorithmusversion. Expliziter
Modus `inherit`/`override` am Werbemittel (keine Vererbung über Zeilenanzahl).
Default über nullable FK `default_calculation_method_id` (nicht `is_default`).
`engine_profile_key` technisch geschützt/nullable. Code-Registry
`EngineProfileRegistry` mit Dispatch-Vertrag
`(engine_profile_key, calculation_method_key, algorithm_version)`.

| Kriterium | Status |
|---|---|
| Tabelle `calculation_methods` + literaler Seed (5 Keys) | umgesetzt |
| Zuordnungstabellen Kat/Medium, Unique (Ziel, Methode) | umgesetzt |
| `calculation_method_mode` + Default-FKs | umgesetzt |
| `EngineProfileRegistry` (spot_classic/average/v1 released) | umgesetzt |
| Runtime CatalogResolver/Writer Dual-R/W + Freeze | **c2** |
| Katalog-/Methoden-Admin, Default-Mitgliedschaft Writer | **Slice 3** |
| Positions-Methodenwahl / Selectability | **c4a/c4b umgesetzt** |
| Systemfelder / Kern-Feldsets / ADV-002 | **unverändert / offen** |

## ADV-001b – Katalog-Admin (September 2026)

Inertia-Admin unter UX-GATE-D Teilfreigabe (PO-ADV001b-1). **Kein** Hard Delete.
**Keine** neuen `CalculationKind`-Fälle. Systemfelder/Kern-Feldsets unverändert.

| Kriterium | Status |
|---|---|
| Migration `lock_version` (Kat/Medium) + `advertising_media.sort` | umgesetzt |
| Admin-CRUD Oberkategorien: anlegen, Name/Sort, Deakt./Reakt. | umgesetzt |
| Admin-CRUD Werbemittel: anlegen, Metadaten, Kategoriewechsel, Deakt./Reakt. | umgesetzt |
| Key/Code nach Save unveränderlich (PO-ADV001b-2) | umgesetzt |
| Kategorie-Deaktivierung blockiert bei aktiven Medien (PO-ADV001b-3) | umgesetzt |
| Assignments bleiben lesbar; Wiederwirkungs-Warnung (PO-ADV001b-4) | umgesetzt |
| Kategoriewechsel mit Impact-Fingerprint/`lock_version` (PO-ADV001b-5) | umgesetzt |
| `spot_classic` nur Kategorie `spots` (PO-ADV001b-8) | umgesetzt |
| Impact-Preview ohne zweite Snapshot-/Assignment-Auflösung | umgesetzt |
| Lifecycle-Locking + MySQL C-RACE-01..04 (Kat-Serialisierung, Cross-Coordinator) | umgesetzt |
| Audit Alt/Neu via `AuditLogger` | umgesetzt |
| E2E isoliert: `playwright.adv001b.config.ts` (Port 8007) | umgesetzt |
| Inventar-/Preislisten-Admin | **weiterhin gesperrt** |
| Optionen / Regel-Editor / ADV-002 | Optionen+Regeln umgesetzt; ADV-002 **nicht** |

## DF-3.3b – Assignment-Admin-UI (September 2026)

Inertia-Admin unter Dyn-Feld-Teilfreigabe (PO-33b-2). **Kein** Abschluss von DF-3.

| Kriterium | Status |
|---|---|
| Hub-Link Assignments + Index/Create/Show | umgesetzt |
| Anlegen inaktiv; Bearbeiten nur inaktiv; Activate/Deactivate | umgesetzt |
| Kontext- und Aktivierungsvorschau mit Herkunft/Konflikten | umgesetzt (Server-Preview a1) |
| Fingerprint/`lock_version`/409 in der UI | umgesetzt |
| PO-33b-1: aktive Kat/Medien nur read-only auswählbar | umgesetzt |
| Historische/deaktivierte Ziele weiterhin lesbar | umgesetzt |
| E2E isoliert: `playwright.df33b.config.ts` (Port 8006) | umgesetzt |
| Snapshot-/Runtime-Verträge | **unverändert** |
| Katalog-Admin Oberkategorien/Werbemittel | **umgesetzt** (ADV-001b) |
| Optionen / Regel-Editor | **umgesetzt** (REST-B / RULE-C) |

## DF-3.3a2β – Contextual Freeze / VER-003 (September 2026, `main`)

PR #25 / `100c79a` (Feature-HEAD `81d75b7`). Generation 3 auf `main`.

| Kriterium | Status |
|---|---|
| `format_version = 3` (`FORMAT_VERSION_CONTEXTUAL_FREEZE`) | umgesetzt |
| Basis = Header (Core→global); Effektiv = Position (Core→global→Kat→Medium) | umgesetzt |
| `parent_configuration_snapshot_id` + sechs Kontextspalten (FK `restrictOnDelete`) | umgesetzt |
| `effective_configuration_snapshot_id` nullable Unique je Positionstabelle | umgesetzt |
| Cross-Table-Ownership + Source↔Owner fail-closed | umgesetzt |
| Calc→Dispo übernimmt historischen Calc-Effektiv-Kontext | umgesetzt |
| Remap nur per `field_definition_id`; PO-32b-1 | umgesetzt |
| Historische Dispo-Origin behält Calc-Effektiv nach Owner-Wegfall (Hotfix) | **umgesetzt** (PR #57 / `main`) |
| E2E isoliert: `playwright.df33a2b.config.ts` (Port 8005) | umgesetzt |
| Assignment-Admin-UI | umgesetzt in DF-3.3b |

### Snapshot-Generationen (Matrix)

| Generation | `format_version` | Quellen | Entsteht bei |
|---|---|---|---|
| Generation 1 (Legacy) | `FORMAT_VERSION_LEGACY` | nur Kern-Feldset, kein Quellengraph | Altbestand, Legacy-Backfill, `materializeFromActiveSet()` |
| Generation 2 (α) | `FORMAT_VERSION_GLOBAL_FREEZE` | Core + globale Assignments (+ Calc-Origin bei Dispo) | Altbestand Gen2; produktiver Create seit β → Gen3 |
| Generation 3 (β) | `FORMAT_VERSION_CONTEXTUAL_FREEZE` | Basis Header + Universum; Effektiv Position inkl. Kat/Medium | neue Kalkulation/Dispo, Revision (Klon) |

Lesepfade sind fail-closed: unbekannte `format_version` wird abgewiesen, statt
still auf einen Default zurückzufallen. Bestehende Generation-1/2-Snapshots werden
**nicht** migriert und beim Update unverändert weitergeführt.

## DF-3.3a2α – Globaler Snapshot-Freeze (September 2026, `main`)

PR #24 / `8edcbd7`. Generation 2 bleibt unverändert; neue Vorgänge frieren seit
β als Generation 3 ein.

| Kriterium | Status |
|---|---|
| `configuration_snapshots.format_version` NOT NULL + `schema_fingerprint` | umgesetzt |
| Quellengraph `configuration_snapshot_sources` (+ `_source_fields`, `_source_rules`) | umgesetzt |
| Property-Provenance je Snapshot-Definition | umgesetzt |
| Freeze Gen2: Core + aktive globale Assignments | umgesetzt (Bestand) |
| Kategorie-/Werbemittel-Assignments in der Runtime | in DF-3.3a2β |
| VER-003 Positions-Effektiv-Snapshot | in DF-3.3a2β |
| Assignment-Admin-UI | in DF-3.3b |

## DF-3.3a1 – Field-Set-Assignments (September 2026, `main`)

| Kriterium | Status |
|---|---|
| Tabelle `field_set_assignments` + Model/Factory/XOR/`target_identity`-Unique | umgesetzt |
| CRUD inaktiv anlegen / Metadaten / Deaktivieren / Activate mit Fingerprint | umgesetzt |
| Deterministischer Resolver (Core→global→Kat→Medium) | umgesetzt |
| Kontext-Preview JSON-API unter `access-administration` | umgesetzt |
| Audit + `lock_version` + 409 | umgesetzt |
| SQLite + MySQL Migration-/Unique-/Restrict-Tests | umgesetzt |
| Produktive Runtime-Wirkung freier Sets | global ab DF-3.3a2α; Kat/Medium ab DF-3.3a2β |
| VER-002 Quellengraph | in DF-3.3a2α umgesetzt |
| VER-003 Positions-Effektiv | in DF-3.3a2β umgesetzt |
| Assignment-Admin-UI | in DF-3.3b umgesetzt |

## DF-3.3-fs – Freie Feldsets (September 2026)

| Kriterium | Status |
|---|---|
| `field_sets.is_system` / `applies_to` / `is_assignable` + Core-Backfill fail-closed | umgesetzt |
| Freies Feldset atomar anlegen (leerer Draft v1) | umgesetzt |
| Key-Lifecycle analog Felddefinitionen (`system_` reserviert, nach Save immutable) | umgesetzt |
| Membership/`applies_to`-Guards inkl. `both` mischt Calc/Dispo/both | umgesetzt |
| Activate: leer abgelehnt; erste Activate setzt `is_assignable`; spätere respektiert Deakt. | umgesetzt |
| Deaktivieren / explizites Reaktivieren; keine physische Löschung | umgesetzt |
| **DF-3.3-fs-HF1:** Deaktivierung bei aktiven Assignments blockiert (keine Kaskade; Runtime fail-closed) | umgesetzt |
| Kern-Feldsets geschützt, dauerhaft nicht assignierbar | umgesetzt |
| Admin-Liste/Create/Detail/Editor/Vorschau; Runtime-Hinweis | umgesetzt |
| E2E isoliert: `playwright.df33fs.config.ts` | umgesetzt |
| Assignments / Merge / Preview | umgesetzt (DF-3.3a1); Runtime global ab DF-3.3a2α |

## Bestätigte Folgeplanung (noch nicht implementiert)

- ADV-001 Rest: Kategorie-Defaults
- Inventar-/Preislisten-/Kombinations-Admin
- ADV-002 / SystemFieldSetting

## ADV-001a – Oberkategorie-Datenbasis (September 2026)

| Kriterium | Status |
|---|---|
| Tabelle `advertising_categories` (key, name, is_active, sort) | umgesetzt |
| Sechs kanonische Keys laut Initialdaten | umgesetzt |
| `advertising_media.category_id` NOT NULL, `restrictOnDelete` | umgesetzt |
| Explizite Bestands-Map, fail-closed ohne Default | umgesetzt |
| Models/Relations/Factories/Tests | umgesetzt |
| Katalog-Admin-UI | **umgesetzt** (ADV-001b) |
| Kategorie-Defaults | **nicht** (spätere Slices) |

## DF-3.2b – Custom Position-Textfelder (September 2026)

| Kriterium | Status |
|---|---|
| Custom-Definitionen `short_text` / `long_text`, Scope `position` (stabil; vor Nutzung editierbar) | umgesetzt |
| `applies_to` calculation / dispo_order / both (wie DF-3.2a) | umgesetzt |
| Pflicht-Vollständigkeit nur bei Dispo-Create/Revision aus Calc (PO-32b-1) | umgesetzt |
| Identity Calc: `id`/`client_key`; Dispo-Position: `calculation_position_id` | umgesetzt |
| Provenance Calc-Origin über Source-Snapshot (kein neues Flag) | umgesetzt |
| Atomarer Partial-Save nativer Positions-Customs (PO-32b-2) | umgesetzt |
| E2E isoliert: `playwright.df32b.config.ts` (eigene DB/Port) | umgesetzt |
| Assignments / Optionen / Regel-Editor | Assignments DF-3.3b; Optionen REST-B; Regeln RULE-C |

## DF-3.2a – Custom Header-Textfelder (September 2026)

| Kriterium | Status |
|---|---|
| Custom-Definitionen `short_text` / `long_text`, Scope `header` (stabil; vor Nutzung editierbar) | umgesetzt |
| `applies_to` calculation / dispo_order / both; Key aus Label (editierbar vor Save) | umgesetzt |
| `max_length` bis 255 bzw. 20000 (`MEDIUMTEXT` / Dispo-String) | umgesetzt |
| Admin: Index System vs. Eigene, Anlegen, Show (strukturell inkl. Bereich/Revision/Lifecycle) | umgesetzt |
| Feldset-Draft: Custom-Membership hinzufügen/entfernen; Position abgelehnt | umgesetzt (Position in DF-3.2b) |
| Runtime: Wizard „Weitere Angaben“, Dispo editierbar + Calc-origin read-only | umgesetzt |
| DF-3.2b Position-Custom-Felder | **umgesetzt** (auf `main`) |
| Assignments / Optionen / Regel-Editor | Assignments DF-3.3b; Optionen REST-B; Regeln RULE-C |

## DF-3.1 – Admin Systemfelder / Kern-Feldsets (September 2026)

| Kriterium | Status |
|---|---|
| UX-GATE-D Teilfreigabe „Administration dynamischer Felder“ | freigegeben |
| Revision geschützter Systemfelder (Label/Hilfe/Gruppe/Sort/reportable) | umgesetzt |
| Draft/Activate/Copy-as-template für zwei Kern-Feldsets | umgesetzt |
| Statische Vorschau mit Beispielwerten; Regeln nur lesbar | umgesetzt |
| Audit + `lock_version` + AT-14 (Historie unverändert) | umgesetzt |
| Custom Fields / Optionen / Assignments / Regel-Editor | teilweise später (Custom DF-3.2a/b; Assignments DF-3.3b) |

## DF-2 – Dispo-Config-Snapshot und Hinweise (September 2026)

| Kriterium | Status |
|---|---|
| Compose-Snapshot aus Calc-Snapshot + `system_dispo_order_core` | umgesetzt |
| `source_configuration_snapshot_id` (Herkunft, kein Laufzeit-Fallback) | umgesetzt |
| Felder `billing_special_features`, `disposition_notes` (Draft editierbar) | umgesetzt |
| Calc-origin `campaign_period` / `period_open` / `position_flight_period` read-only | umgesetzt |
| Revision: Texte aus Vorgänger (PO-DF2-1), Calc-Werte frisch | umgesetzt |
| Legacy-Backfill ohne erfundene Dyn-Werte | umgesetzt |
| Draft-Sync fehlender Calc-Dyn-Werte | umgesetzt |
| Admin-UI / Custom Fields | **nicht** in DF-2 |

### Verbindliche PO-Entscheidungen

- **PO-A1:** unverändert (Kalkulation).
- **PO-B2:** Hinweise nur im Dispo-Entwurf.
- **PO-DF2-1:** Nachfolge-Draft kopiert beide Texte per Schlüssel aus dem
  abgelehnten Vorgänger; neuer Snapshot; Vorgänger unverändert.
- **PO-DF3.1:** UX-GATE-D Dyn-Feld-Admin; Vorschau statisch mit Beispielwerten;
  Regeln in DF-3.1 nur lesbar.
- **PO-32b-1…4:** siehe Entscheidungslog (Pflicht erst Dispo-Create; Partial-Save;
  Snapshot-Provenance; both wie 3.2a).
- **PO-33b-1:** In DF-3.3b nur bestehende aktive Oberkategorien/Werbemittel als
  Assignment-Ziele auswählen; Katalog-Admin war verbindlicher Folgeslice → ADV-001b.
- **PO-33b-2:** Assignment-Admin-UI gehört zur Teilfreigabe „Administration
  dynamischer Felder“; keine zusätzliche UX-GATE-D-Freigabe für Katalog damals.
- **PO-ADV001b-1…9:** Katalog-Admin UX-GATE-D Teilfreigabe; Key/Code immutable;
  Kategorie-Deakt. ohne aktive Medien; Assignments lesbar mit Wiederwirkungs-Warnung;
  Kategoriewechsel mit Impact-Fingerprint; kein Hard Delete; Medium-`sort`;
  `spot_classic` nur `spots`; Systemfelder/Kern-Feldsets unverändert.

## DF-1 – Dynamische Systemfelder Kalkulation (September 2026)

| Kriterium | Status |
|---|---|
| Systemfelder `campaign_period`, `period_open`, `position_flight_period` | umgesetzt |
| Feldset `system_calculation_core` v1 + aktive Version | umgesetzt |
| Revision über `current_revision_id` / Pin in Set-Version | umgesetzt |
| Unveränderlicher Config-Snapshot bei neuer Kalkulation | umgesetzt |
| Legacy-Backfill: Snapshot + `period_open = true` | umgesetzt |
| Dynamische Kopf-/Positionswerte (eigene Value-Tabellen) | umgesetzt |
| Wizard: Kampagnenzeitraum, Toggle „Zeitraum offen“, Flight-Period | umgesetzt |
| Snapshot-Regel `period_open=false → require position_flight_period` | umgesetzt |
| Admin-Feld-UI / Custom Fields / Dispo-Werte | **nicht** in DF-1 (DF-2+) |

## Bewusst offen nach DF-3-RULE-C

- DF-3 Dyn-Feld-Pfad (REST-A–C3, RULE-A–C) **abgeschlossen**
- Legacy-Felder (`kind`/`spot_method`) entfernen nach Dual-Write-Phase
- ADV-001 Rest: Kategorie-Defaults (Feldsets, Rabatt/AE/Preisdefaults) jenseits Methoden
- ADV-002 / SystemFieldSetting
- übrige UX-GATE-D-Adminmodule (Preislisten, Kombinationstabelle)
- operative Disposition, Material, Kommentare, Status ab `In Bearbeitung`
- weitere Engines (SWF, OA, Social, Events, Barter) als eigene Fachslices
- technische Profil-Provisionierung für Medium-Overrides

## Echte Blocker

| ID | Thema |
|---|---|
| BLK-005 | UX-GATE-C |
| BLK-006 | UX-GATE-D (Rest; Dyn-Feld-Admin, Katalog Kat/Medien, Inventar-Admin-Lifecycle, Preislisten-Lifecycle, Excel-Import und Wizard-Jahreswahl teilfreigegeben; Kombinationstabelle gesperrt; Kombi-Mitgliedschaften entfallen) |
| BLK-001 | Initialkataloge Kapitel 27 |
| BLK-002 | Speedit-Parameter vor Produktiv-Deploy |
