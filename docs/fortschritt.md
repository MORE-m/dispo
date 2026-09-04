# Fortschritt V1

Stand: 4. September 2026 (DF-2 abgeschlossen; kleine Nachpflege)

## Aktuelle Phase

Phase 3 (Versionen, dynamische Felder und Snapshots) – **DF-1 und DF-2 auf
`main`:** eigenständiger Dispo-Config-Snapshot, Übernahme der drei
Kalkulations-Dyn-Felder, Draft-Erfassung von `billing_special_features` /
`disposition_notes`.

Phase 8 (Dispoauftrag) bleibt mit Vier-Augen-Freigabe und Nummernableitung auf
`main`; UX-GATE-D ist weiterhin nicht vollständig abgeschlossen.

## Aktuelle Aufgabe

Feature-Branch `chore/df2-post-merge-cleanup` – kleine Nachpflege nach DF-2
(PHPDoc non-null, Fortschrittsdokument, Multi-Positions-Sync-Test,
Revision-ohne-Definition-Test). DF-3 / Admin-UI nicht begonnen.

## Zuletzt abgeschlossene Aufgabe

DF-2 Dispo-Config-Snapshot und Dispo-Hinweise – PR
[#16](https://github.com/MORE-m/dispo/pull/16) gemergt in `main`
(`ac0d533`, Post-Merge-CI Run `33851426061` Attempt 3 grün: `ci` + `mysql`,
inkl. npm audit und Playwright).

Davor: DF-1 dynamische Systemfelder Kalkulation – PR
[#15](https://github.com/MORE-m/dispo/pull/15) gemergt (`ed194b4`).

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

## Bewusst offen nach DF-2

- Admin-UI für Felddefinitionen/Feldsets (GATE-D / ADM-*/DF-3)
- volle Regelmatrix, Optionen, Custom Fields, Payfaktor als FieldDefinition
- operative Disposition, Material, Kommentare, Status ab `In Bearbeitung`

## Echte Blocker

| ID | Thema |
|---|---|
| BLK-005 | UX-GATE-C |
| BLK-006 | UX-GATE-D (Rest) |
| BLK-001 | Initialkataloge Kapitel 27 |
| BLK-002 | Speedit-Parameter vor Produktiv-Deploy |
