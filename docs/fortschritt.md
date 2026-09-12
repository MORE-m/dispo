# Fortschritt V1

Stand: 11. September 2026 (DF-3-REST-C1 Choice-Wertmodell serverseitig
vollständig umgesetzt und geprüft – kein Abschluss von ADV-001 / DF-3;
sichtbare Choice-UI weiterhin offen)

## Aktuelle Phase

Phase 2/3 parallel: **DF-1, DF-2, DF-3.1, DF-3.2a, DF-3.2b, ADV-001a, DF-3.3-fs,
DF-3.3a1, DF-3.3a2α, DF-3.3a2β, DF-3.3b, ADV-001b, ADV-001c1, ADV-001c2,
ADV-001c3a, ADV-001c3b1, ADV-001c3b2, ADV-001c3c, ADV-001c4a, ADV-001c4b**
sowie **DF-3-REST-A (Options-Fundament)** über PR #38 auf `main`
(`7daee909e76e1fe8e47f36155e58b4059496b09d`) und **DF-3-REST-B
(Options-Admin-UI)** über PR #39 auf `main`
(`769d48554140cdd79d525b5cd4330db9699dd797`).
**DF-3-REST-C1** (Choice-Wertmodell/`value_json`, serverseitige Persistenz) ist
vollständig umgesetzt und geprüft. Noch keine sichtbare Calc-/Dispo-Choice-UI
(C2/C3). Gesamtziel DF-3 bleibt offen (Regelmatrix, Regel-Editor).
ADV-001 weitere Defaults und Legacy-Entfernung bleiben offen.

Phase 8 (Dispoauftrag) bleibt mit Vier-Augen-Freigabe und Nummernableitung auf
`main`; UX-GATE-D ist weiterhin nicht vollständig abgeschlossen, enthält aber
Teilfreigaben für Dispo/Vier-Augen, Dynamische-Felder-Admin inkl. Assignments
(PO-33b-2), Options-/Regel-Editor-Rahmen (PO-DF3-REST-1) und **Katalog-Admin
Oberkategorien/Werbemittel (PO-ADV001b-1)**. Inventar- und Preislisten-Admin
bleiben gesperrt.

## Aktuelle Aufgabe

Nächster geplanter Slice: **DF-3-REST-C2** (sichtbare Kalkulations-UI für
Select/Multi-Select). Danach **C3** (Dispo-UI, Calc→Dispo-Übernahme/Provenance
in der Oberfläche). Parallel Regel-Fundament/Editor und weitere ADV-001-Defaults.

## Zuletzt abgeschlossene Aufgabe

DF-3-REST-C1 Choice-Wertmodell und serverseitige Persistenz: additives
`value_json` auf allen vier dynamischen Wertetabellen; `ChoiceFieldValueContract`;
Calc-/Dispo-Writer Persistenz/Export/Copy/Completeness; Remapper; Schema-Props
mit `options_json`; keine sichtbare Choice-UI.

Davor auf `main`: DF-3-REST-B Options-Admin-UI (PR #39) und DF-3-REST-A
(PR #38).

## DF-3-REST-C1 – Choice-Wertmodell / Persistenz (September 2026)

Serverseitige Select-/Multi-Select-Runtime ohne sichtbare UI.

| Kriterium | Status |
|---|---|
| `value_json` an vier Wertetabellen (additiv, nullable) | **umgesetzt** |
| `ChoiceFieldValueContract` (Normalize, historisch inactive, XOR) | **umgesetzt** |
| Calc/Dispo Persistenz, Export, Copy, Completeness | **umgesetzt** |
| Schema-Props inkl. `options_json` | **umgesetzt** |
| Sichtbare Calc-UI (C2) | **nicht** |
| Sichtbare Dispo-UI / Playwright C2/C3 (C3) | **nicht** |
| Regel-Editor / volle Regelmatrix | **nicht** |

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
| Select-/Multi-Select-Runtime (`value_json`) | **C1 serverseitig umgesetzt; UI = C2/C3 offen** |
| Regel-Editor / volle Regelmatrix | **nicht** |

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
| Calc-Dispo-Runtime | **C1 serverseitig umgesetzt; UI offen** |
| Regel-Editor / volle Regelmatrix | **nicht** |


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

- Sichtbare Select-/Multi-Select-UI (C2/C3), volle Regelmatrix, Regel-Editor
  (DF-3-REST-A/B auf main; C1 serverseitig umgesetzt;
  Slice-Reihenfolge weiter: C2 → C3 → Regeln)
- ADV-001 Rest: Kategorie-Defaults
- Inventar-/Preislisten-/Kombinations-Admin
- ADV-001 Defaults parallel möglich

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

## Bewusst offen nach ADV-001c4b / DF-3-REST-A / REST-B / C1

- ADV-001c4a/c4b: Methodenoptions-/Freeze + sichtbare Wizard-Auswahl (umgesetzt)
- DF-3-REST-A: Options-Fundament (auf `main`, PR #38)
- DF-3-REST-B: Options-Admin-UI (auf `main`, PR #39)
- DF-3-REST-C1: Choice-Wertmodell serverseitig umgesetzt (UI = C2/C3 offen)
- Legacy-Felder (`kind`/`spot_method`) entfernen nach Dual-Write-Phase
- ADV-001 Rest: Kategorie-Defaults (Feldsets, Rabatt/AE/Preisdefaults) jenseits Methoden
- ADV-002 / SystemFieldSetting
- Sichtbare Choice-UI (C2/C3), Regelmatrix, Regel-Editor
- übrige UX-GATE-D-Adminmodule (Inventare, Preislisten, Kombinationstabelle)
- operative Disposition, Material, Kommentare, Status ab `In Bearbeitung`
- weitere Engines (SWF, OA, Social, Events, Barter) als eigene Fachslices
- technische Profil-Provisionierung für Medium-Overrides

## Echte Blocker

| ID | Thema |
|---|---|
| BLK-005 | UX-GATE-C |
| BLK-006 | UX-GATE-D (Rest; Dyn-Feld-Admin und Katalog Kat/Medien teilfreigegeben; Inventare/Preislisten gesperrt) |
| BLK-001 | Initialkataloge Kapitel 27 |
| BLK-002 | Speedit-Parameter vor Produktiv-Deploy |
