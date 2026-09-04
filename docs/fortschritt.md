# Fortschritt V1

Stand: 4. September 2026 (DF-1 dynamische Systemfelder in der Kalkulation)

## Aktuelle Phase

Phase 3 (Versionen, dynamische Felder und Snapshots) – **Teilslice DF-1
umgesetzt:** geschützte Systemfelddefinitionen, Konfigurationssnapshots für
Kalkulationen, dynamische Kopf-/Positionswerte, Erfassung im Kalkulationswizard
sowie snapshot-basierte serverseitige Regelvalidierung.

Phase 8 (Dispoauftrag) bleibt mit Vier-Augen-Freigabe und Nummernableitung auf
`main`; UX-GATE-D ist weiterhin nicht vollständig abgeschlossen.

## Aktuelle Aufgabe

PR [#15](https://github.com/MORE-m/dispo/pull/15) (`feat/dynamic-fields-calculation-df1`)
ist geöffnet; Korrekturen nach Review und CI-Prüfung auf dem Feature-Branch.
Noch **nicht** gemergt.

## Zuletzt abgeschlossene Aufgabe

Dispo-Nummer aus Kalkulationsnummer (PR #14) auf `main` (`180572d`).

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
| Admin-Feld-UI / Custom Fields / Dispo-Werte | **nicht** in DF-1 |
| `billing_special_features` / `disposition_notes` | **nicht** in DF-1 (DF-2) |

### Verbindliche PO-Entscheidungen in DF-1

- **PO-A (A1):** `period_open` nach Persistenz immer gesetzt; Default/Backfill
  `true`; UI-Toggle; bei `false` wird `position_flight_period` verpflichtend;
  kein `null`; keine feste Spalte an der Position.
- **PO-B (B2):** Rechnungsbesonderheiten und Dispositionshinweise gehören in den
  Dispoauftrag-Entwurf (DF-2), nicht in die Kalkulation.

## Nummernableitung K→DA (September 2026)

| Kriterium | Status |
|---|---|
| Neue Familie: Stamm aus `calculation.number_year` / `number_seq` | umgesetzt |
| Padding wie Kalkulation (`NNNNN`, nicht `NNNNNN`) | umgesetzt |
| Folgeaufträge / Nachbesserung: nur Suffix | umgesetzt |
| Legacy-Familien behalten Stamm inkl. Padding | umgesetzt |
| Keine Umnummerierung historischer Nummern | umgesetzt |
| `dispo_order_number_sequences` nur noch Legacy | umgesetzt |
| MySQL-Concurrency | umgesetzt |

## Vier-Augen-Freigabe (September 2026)

| Kriterium | Status |
|---|---|
| Jeder Auftrag benötigt Freigabe (kein `Entwurf → Disposition`) | umgesetzt |
| Statusübergänge: Entwurf → Wartet → Disposition / Abgelehnt | umgesetzt |
| Regulär: anderer Vertrieb / Admin / GF | umgesetzt |
| Sonderfreigabe: nur Admin / GF | umgesetzt |
| Ersteller-Ausschluss auch bei Admin/GF | umgesetzt |
| Persistente Freigabeanforderung + Historie | umgesetzt |
| Teilübernahme umgeht Sonderfreigabe nicht | umgesetzt |
| Concurrency / `lock_version` / 409 | umgesetzt |
| Audit `submitted_for_approval` / `approved` / `rejected` | umgesetzt |
| Listenstatus nach Mutation ohne Browser-Reload | umgesetzt |
| Nachbesserung abgelehnter Aufträge als neuer Entwurf | umgesetzt |
| UX-GATE-D gesamt | **nicht** abgeschlossen |

## Nachbesserung abgelehnter Aufträge

Der abgelehnte Dispoauftrag bleibt als unveränderbarer, terminaler Snapshot erhalten.
Der Ersteller kann die zugrunde liegende Kalkulation nachbessern und daraus einen
neuen, verknüpften Dispoauftrag im Status Entwurf erzeugen (`revises_dispo_order_id`).

## Bewusst offen nach DF-1

- Admin-UI für Felddefinitionen/Feldsets (GATE-D / ADM-*)
- Dispo-Snapshot inkl. `billing_special_features` / `disposition_notes` (DF-2)
- volle Regelmatrix, Optionen, Custom Fields, Payfaktor als FieldDefinition
- operative Disposition, Material, Kommentare, Status ab `In Bearbeitung`

## Echte Blocker

| ID | Thema |
|---|---|
| BLK-005 | UX-GATE-C |
| BLK-006 | UX-GATE-D (Rest) |
| BLK-001 | Initialkataloge Kapitel 27 |
| BLK-002 | Speedit-Parameter vor Produktiv-Deploy |
