# Fortschritt V1

Stand: 9. September 2026 (ADV-001c1 Schema-Fundament – kein Abschluss von ADV-001 / DF-3)

## Aktuelle Phase

Phase 2/3 parallel: **DF-1, DF-2, DF-3.1, DF-3.2a, DF-3.2b, ADV-001a, DF-3.3-fs,
DF-3.3a1, DF-3.3a2α, DF-3.3a2β, DF-3.3b, ADV-001b und ADV-001c1 auf Feature-Branch**
(bzw. `main`). Gesamtziel DF-3 bleibt offen (Optionen, Regel-Editor). ADV-001
Defaults und weitere CALC-KIND-Slices bleiben offen.

Phase 8 (Dispoauftrag) bleibt mit Vier-Augen-Freigabe und Nummernableitung auf
`main`; UX-GATE-D ist weiterhin nicht vollständig abgeschlossen, enthält aber
Teilfreigaben für Dispo/Vier-Augen, Dynamische-Felder-Admin inkl. Assignments
(PO-33b-2) und **Katalog-Admin Oberkategorien/Werbemittel (PO-ADV001b-1)**.
Inventar- und Preislisten-Admin bleiben gesperrt.

## Aktuelle Aufgabe

ADV-001c1 Schema-Fundament (Katalog ↔ Berechnungsmethoden ↔ Engine-Profile).
Nächste sinnvolle Schritte nach Merge: ADV-001c2 Backfill + Dual-Read/Write;
danach Katalog-Methoden-Admin (c3), Positionsauswahl (c4); parallel Optionen /
Regel-Editor; Inventar-/Preislisten-Admin.

## Zuletzt abgeschlossene Aufgabe

ADV-001c1 Schema-Fundament auf Branch `feat/adv001c1-calc-kind-foundation`
(Basis `main` nach PR #28).

## ADV-001c1 – CALC-KIND-FOUNDATION Schema (September 2026)

Variante B: fachliche Methode ≠ Engine-Profil ≠ Algorithmusversion. Expliziter
Modus `inherit`/`override` am Werbemittel (keine Vererbung über Zeilenanzahl).
Default über nullable FK `default_calculation_method_id` (nicht `is_default`).
`engine_profile_key` technisch geschützt/nullable. Code-Registry
`EngineProfileRegistry` mit Dispatch-Vertrag
`(engine_profile_key, calculation_method_key, algorithm_version)` – **noch nicht**
an Runtime angebunden.

| Kriterium | Status |
|---|---|
| Tabelle `calculation_methods` + literaler Seed (5 Keys) | umgesetzt |
| Zuordnungstabellen Kat/Medium, Unique (Ziel, Methode) | umgesetzt |
| `calculation_method_mode` + Default-FKs | umgesetzt |
| `EngineProfileRegistry` (spot_classic/average/v1 released) | umgesetzt |
| Runtime CatalogResolver/Writer/Engine | **unverändert** |
| Dual-Read/Write, Positions-Freeze, `kind` nullable | **Slice 2** |
| Katalog-/Methoden-Admin, Default-Mitgliedschaft Writer | **Slice 3** |
| Positions-Methodenwahl / Selectability | **Slice 4** |
| Systemfelder / Kern-Feldsets / ADV-002 | **unverändert / offen** |
| Kategorie-/Medium-Methoden-Backfill | **nicht** in c1 |

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
| Optionen / Regel-Editor / ADV-002 | **nicht** |

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
| Optionen / Regel-Editor | **nicht** |

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

- Optionen / Auswahlfelder, volle Regelmatrix, Regel-Editor
- ADV-001 Rest: Kategorie-Defaults
- Inventar-/Preislisten-/Kombinations-Admin
- Slice-Reihenfolge ab hier: Optionen/Regel-Editor; ADV-001 Defaults

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
| Custom-Definitionen `short_text` / `long_text`, Scope fest `position` | umgesetzt |
| `applies_to` calculation / dispo_order / both (wie DF-3.2a) | umgesetzt |
| Pflicht-Vollständigkeit nur bei Dispo-Create/Revision aus Calc (PO-32b-1) | umgesetzt |
| Identity Calc: `id`/`client_key`; Dispo-Position: `calculation_position_id` | umgesetzt |
| Provenance Calc-Origin über Source-Snapshot (kein neues Flag) | umgesetzt |
| Atomarer Partial-Save nativer Positions-Customs (PO-32b-2) | umgesetzt |
| E2E isoliert: `playwright.df32b.config.ts` (eigene DB/Port) | umgesetzt |
| Assignments / Optionen / Regel-Editor | Assignments in DF-3.3b; Optionen/Regeln offen |

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
| Assignments / Optionen / Regel-Editor | Assignments in DF-3.3b; Optionen/Regeln offen |

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

## Bewusst offen nach ADV-001c1

- ADV-001c2: Backfill + Dual-Read/Write + `kind` nullable + Snapshot-Entkopplung
- ADV-001c3: Katalog-/Methoden-Admin (Mode/Defaults/Zuordnungen)
- ADV-001c4: Positionsauswahl und Selectability
- ADV-001 Rest: Kategorie-Defaults (Feldsets, Rabatt/AE/Preisdefaults) jenseits Methoden-Fundament
- ADV-002 / SystemFieldSetting
- Optionen, Regelmatrix, Regel-Editor
- übrige UX-GATE-D-Adminmodule (Inventare, Preislisten, Kombinationstabelle)
- operative Disposition, Material, Kommentare, Status ab `In Bearbeitung`
- weitere Engines (SWF, OA, Social, Events, Barter) als eigene Fachslices

## Echte Blocker

| ID | Thema |
|---|---|
| BLK-005 | UX-GATE-C |
| BLK-006 | UX-GATE-D (Rest; Dyn-Feld-Admin und Katalog Kat/Medien teilfreigegeben; Inventare/Preislisten gesperrt) |
| BLK-001 | Initialkataloge Kapitel 27 |
| BLK-002 | Speedit-Parameter vor Produktiv-Deploy |
