# Fortschritt V1

Stand: 9. September 2026 (DF-3.3a2β auf Feature-Branch – kein Abschluss von DF-3)

## Aktuelle Phase

Phase 2/3 parallel: **DF-1, DF-2, DF-3.1, DF-3.2a, DF-3.2b, ADV-001a, DF-3.3-fs,
DF-3.3a1 und DF-3.3a2α auf `main`** (PR #15–#24). **DF-3.3a2β** (Generation-3-
Freeze, VER-003 Positions-Effektivs, Kat/Medium-Runtime) auf Feature-Branch
`feat/df3-3a2b-contextual-snapshot-freeze`. Gesamtziel DF-3 bleibt offen
(`DF-3.3b`, Optionen, Regel-Editor).

Phase 8 (Dispoauftrag) bleibt mit Vier-Augen-Freigabe und Nummernableitung auf
`main`; UX-GATE-D ist weiterhin nicht vollständig abgeschlossen, enthält aber
Teilfreigaben für Dispo/Vier-Augen und Dynamische-Felder-Admin. Katalog-Admin
(Inventare, Werbemittel, Oberkategorien, …) bleibt gesperrt.

## Aktuelle Aufgabe

Feature-Branch `feat/df3-3a2b-contextual-snapshot-freeze` – **DF-3.3a2β**:
Generation 3 mit Positions-Effektiv-Snapshots (VER-003), historischem
Werbemittel-/Kategoriekontext, Unique-1:1-Ownership und produktiver
Kat-/Medium-Runtime. **Kein** Start von `DF-3.3b`.

## Zuletzt abgeschlossene Aufgabe

DF-3.3a2α globaler Snapshot-Freeze – PR [#24](https://github.com/MORE-m/dispo/pull/24)
gemergt in `main` (`8edcbd7`).

Davor: DF-3.3a1 – PR [#23](https://github.com/MORE-m/dispo/pull/23) (`c928169`).

## DF-3.3a2β – Contextual Freeze / VER-003 (September 2026)

Feature-Branch, **kein** Abschluss von DF-3.

| Kriterium | Status |
|---|---|
| `format_version = 3` (`FORMAT_VERSION_CONTEXTUAL_FREEZE`) | umgesetzt |
| Basis = Header (Core→global); Effektiv = Position (Core→global→Kat→Medium) | umgesetzt |
| `parent_configuration_snapshot_id` + sechs Kontextspalten (FK `restrictOnDelete`) | umgesetzt |
| `effective_configuration_snapshot_id` nullable Unique je Positionstabelle | umgesetzt |
| Cross-Table-Ownership + Source↔Owner fail-closed | umgesetzt |
| Calc→Dispo übernimmt historischen Calc-Effektiv-Kontext | umgesetzt |
| Remap nur per `field_definition_id`; PO-32b-1 | umgesetzt |
| Review-Härtung: Teilselektion-Dispo, fail-closed Effektiv-Lifecycle, Gen-3-Integrity, Fingerprint-Vertrag, PO-32b-1 Header | umgesetzt |
| Schema-API mit Medium; Wizard Positions-Fingerprints | umgesetzt |
| E2E isoliert: `playwright.df33a2b.config.ts` (Port 8005) | umgesetzt |
| Assignment-Admin-UI | **nicht** (DF-3.3b) |

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
| Kategorie-/Werbemittel-Assignments in der Runtime | in DF-3.3a2β nachgezogen |
| VER-003 Positions-Effektiv-Snapshot | in DF-3.3a2β nachgezogen |
| Assignment-Admin-UI | **nicht** (DF-3.3b) |

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
| Assignment-Admin-UI | **nicht** (DF-3.3b) |

## DF-3.3-fs – Freie Feldsets (September 2026)

| Kriterium | Status |
|---|---|
| `field_sets.is_system` / `applies_to` / `is_assignable` + Core-Backfill fail-closed | umgesetzt |
| Freies Feldset atomar anlegen (leerer Draft v1) | umgesetzt |
| Key-Lifecycle analog Felddefinitionen (`system_` reserviert, nach Save immutable) | umgesetzt |
| Membership/`applies_to`-Guards inkl. `both` mischt Calc/Dispo/both | umgesetzt |
| Activate: leer abgelehnt; erste Activate setzt `is_assignable`; spätere respektiert Deakt. | umgesetzt |
| Deaktivieren / explizites Reaktivieren; keine physische Löschung | umgesetzt |
| Kern-Feldsets geschützt, dauerhaft nicht assignierbar | umgesetzt |
| Admin-Liste/Create/Detail/Editor/Vorschau; Runtime-Hinweis | umgesetzt |
| E2E isoliert: `playwright.df33fs.config.ts` | umgesetzt |
| Assignments / Merge / Preview | umgesetzt (DF-3.3a1); Runtime global ab DF-3.3a2α |

## Bestätigte Folgeplanung (noch nicht implementiert)

- `DF-3.3b`: Assignment-Admin-UI, Herkunft/Konflikte UX – **nicht begonnen**
- Optionen / Auswahlfelder, volle Regelmatrix, Regel-Editor
- Slice-Reihenfolge ab hier: **DF-3.3a2β** → DF-3.3b

## ADV-001a – Oberkategorie-Datenbasis (September 2026)

| Kriterium | Status |
|---|---|
| Tabelle `advertising_categories` (key, name, is_active, sort) | umgesetzt |
| Sechs kanonische Keys laut Initialdaten | umgesetzt |
| `advertising_media.category_id` NOT NULL, `restrictOnDelete` | umgesetzt |
| Explizite Bestands-Map, fail-closed ohne Default | umgesetzt |
| Models/Relations/Factories/Tests | umgesetzt |
| Katalog-Admin-UI | **nicht** (UX-GATE-D) |
| Kategorie-Defaults, Snapshot-Provenance | **nicht** (spätere Slices) |

## DF-3.2b – Custom Position-Textfelder (September 2026)

| Kriterium | Status |
|---|---|
| Custom-Definitionen `short_text` / `long_text`, Scope fest `position` | umgesetzt |
| `applies_to` calculation / dispo_order / both (wie DF-3.2a) | umgesetzt |
| Pflicht-Vollständigkeit nur bei Dispo-Create/Revision aus Calc (PO-32b-1) | umgesetzt |
| Identity Calc: `id`/`client_key`; Dispo-Position: `calculation_position_id` | umgesetzt |
| Provenance Calc-Origin über Source-Snapshot (kein neues Flag) | umgesetzt |
| Atomarer Partial-Save nativer Positions-Customs (PO-32b-2) | umgesetzt |
| E2E isoliert: `playwright.df32b.config.ts` (eigene DB/Port) | umgesetzt |
| Assignments / Optionen / Regel-Editor | **nicht** in DF-3.2b |

## DF-3.2a – Custom Header-Textfelder (September 2026)

| Kriterium | Status |
|---|---|
| Custom-Definitionen `short_text` / `long_text`, Scope fest `header` | umgesetzt |
| `applies_to` calculation / dispo_order / both; Key aus Label (editierbar vor Save) | umgesetzt |
| `max_length` bis 255 bzw. 20000 (`MEDIUMTEXT` / Dispo-String) | umgesetzt |
| Admin: Index System vs. Eigene, Anlegen, Show (strukturell/Revision/Lifecycle) | umgesetzt |
| Feldset-Draft: Custom-Membership hinzufügen/entfernen; Position abgelehnt | umgesetzt (Position in DF-3.2b) |
| Runtime: Wizard „Weitere Angaben“, Dispo editierbar + Calc-origin read-only | umgesetzt |
| DF-3.2b Position-Custom-Felder | **umgesetzt** (auf `main`) |
| Assignments / Optionen / Regel-Editor | **nicht** in DF-3.2a |

## DF-3.1 – Admin Systemfelder / Kern-Feldsets (September 2026)

| Kriterium | Status |
|---|---|
| UX-GATE-D Teilfreigabe „Administration dynamischer Felder“ | freigegeben |
| Revision geschützter Systemfelder (Label/Hilfe/Gruppe/Sort/reportable) | umgesetzt |
| Draft/Activate/Copy-as-template für zwei Kern-Feldsets | umgesetzt |
| Statische Vorschau mit Beispielwerten; Regeln nur lesbar | umgesetzt |
| Audit + `lock_version` + AT-14 (Historie unverändert) | umgesetzt |
| Custom Fields / Optionen / Assignments / Regel-Editor | **nicht** in DF-3.1 (teilweise DF-3.2a/b) |

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

## Bewusst offen nach DF-3.3a2β

- DF-3.3b: Assignment-Admin-UI, Herkunft/Konflikte – **nicht begonnen**
- ADV-001 Rest: Kategorie-Defaults, Admin
- ADV-002 / SystemFieldSetting
- Optionen, Regelmatrix, Regel-Editor
- übrige UX-GATE-D-Adminmodule (Inventare, Kataloge, Preislisten, …)
- operative Disposition, Material, Kommentare, Status ab `In Bearbeitung`

## Echte Blocker

| ID | Thema |
|---|---|
| BLK-005 | UX-GATE-C |
| BLK-006 | UX-GATE-D (Rest; Dyn-Feld-Admin teilfreigegeben; Katalog-Admin gesperrt) |
| BLK-001 | Initialkataloge Kapitel 27 |
| BLK-002 | Speedit-Parameter vor Produktiv-Deploy |
