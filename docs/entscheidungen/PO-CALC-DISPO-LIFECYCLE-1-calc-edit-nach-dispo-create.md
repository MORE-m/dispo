# PO-CALC-DISPO-LIFECYCLE-1 – Calc-Edit nach Dispo-Create

Status: **Akzeptiert** – Option A (+ Hinweis-Slice `PO-CALC-DISPO-HINT-1`)  
Stand: 4. Oktober 2026  
Basis bei Analyse: `origin/main` @ `b36945f78b903d52c8faeadd70b3f3b805ee53b2` (nach PR #119)  
IDs: `DSP-001`–`DSP-004`, `STA-001`–`STA-005`, `CAL-*` (Edit-Rechte), Audit-Nachzug PR #114  
Umsetzungshinweis: `docs/readiness/PO-CALC-DISPO-HINT-1-snapshot-isolation-hinweis-2026-10-04.md`

## PO-Entscheidung

**Option A ist verbindlich:**

- Kalkulationen bleiben bearbeitbar.
- Bestehende Dispoaufträge behalten ihren eingefrorenen Stand.
- Änderungen an der Kalkulation ändern bestehende Dispoaufträge nicht (`DSP-003`).
- Ein neuer Dispoauftrag übernimmt den aktuellen Kalkulationsstand der ausgewählten
  Positionen (`DSP-002`).
- Er ersetzt oder storniert bestehende Aufträge **nicht automatisch**.
- Teil- und Mehrfachübernahmen bleiben unverändert möglich (`DSP-001`, `DSP-004`).

Neuer Create ist der bestätigte **Übernahmeweg** für geänderte Konditionen – nicht
eine automatische Korrektur oder Ersetzung eines Altauftrags.

## Korrektur- und Folgewege (nur Ist)

| Situation | Vorhandener Ablauf |
|---|---|
| Nach Ablehnung (`approval_rejected`) | Bestehender Revision-/Nachbesserungs-Pfad: Calc anpassen → neuer Draft mit `revises_dispo_order_id`; Vorgänger bleibt unverändert |
| Geänderte Konditionen sonst | Neuer Create aus aktuellem Calc-Stand → eigenständiger Auftrag mit neuer Nummer |
| Bereits eingereichte / freigegebene / operative Altaufträge | Bei Bedarf über die **bestehenden zulässigen** Status-/Storno-/Reopen-Abläufe; kein Auto-Ersatz |

**Nicht vorhanden und nicht Teil dieser Entscheidung:**

- automatische Ersetzung, Stornierung oder Synchronisierung bei Calc-Edit
- Freigabeinvalidierung allein wegen Calc-Änderung
- In-Place-Auffrischung kaufmännischer Dispo-Daten aus der Calc

## Kurzfazit (nach Akzeptanz)

| Thema | Status |
|---|---|
| Snapshot-Isolation Calc ↔ Dispo | bestätigt (`DSP-003`, Isolationstests) |
| Calc editierbar nach Dispo-Create | bestätigt (Option A) |
| UI erklärt die Trennung | `PO-CALC-DISPO-HINT-1` |
| Auto-Sync / Auto-Invalidierung bei Calc-Edit | ausgeschlossen |

---

## 1. Ist-Verhalten (mit Belegen)

### 1.1 Übernahme: welche Positionen, wohin?

- Create wählt explizit `position_ids` (mind. eine); nur ausgewählte Positionen werden
  als Dispo-Positionen materialisiert.
  - Request: `CreateDispoOrderFromCalculationRequest`
  - Writer: `DispoOrderWriter::createWithinTransaction`
  - Test: `CreateDispoOrderFromCalculationTest::test_only_selected_positions_are_copied`
- Header-/Positionsdaten und Summen werden zum Create-Zeitpunkt als unabhängiger
  Stand persistiert (`source_calculation_number`,
  `source_calculation_totals_snapshot`, Positionsdaten).
  - Mapper: `DispoOrderSnapshotMapper`
  - Test: `…::test_header_and_position_snapshots_are_persisted`
- UI: Dialog „Dispoauftrag anlegen“ lädt wählbare Positionen inkl.
  `already_adopted` / `adoptions`.
  - Service: `DispoOrderPositionAdoptionService::selectablePositions`
  - UI: `resources/js/components/dispo-order-create-dialog.tsx`
  - Test: `…::test_adopted_positions_are_reported`

### 1.2 Mehrere Aufträge / Teilübernahmen

- **Teilübernahme:** ja (Subset der Calc-Positionen).
- **Mehrere Dispoaufträge aus derselben Calc:** ja; Suffix `-01`, `-02`, …
  (`DSP-004` / `TEC-002`; auch `CAL-004` / `AT-15`).
  - Test: `…::test_multiple_orders_from_same_calculation_are_allowed`
- **Bereits übernommene Positionen erneut wählbar:** ja (`DSP-001`); Kennzeichnung
  „Bereits übernommen“, **keine** Sperre.
- **UI-Default:** Create-Dialog wählt vorzugsweise noch nicht übernommene Positionen
  (`DispoOrderCreateDialog` / `defaultSelectedIds`); „Alle“ bleibt möglich.
- **Kein Guard** gegen parallele/überlappende aktive Aufträge derselben Position.
  Doppelübernahme ist fachlich erlaubt, nicht technisch blockiert.

### 1.3 Calc-Änderung vs. Dispo-Status

| Dispo-Status | Wirkung einer Calc-Änderung auf den bestehenden Auftrag |
|---|---|
| `draft` | Stand bleibt unverändert. Draft erlaubt nur Dispo-eigene Updates (`DispoOrderPolicy::update` → Status `draft`), kein kaufmännisches Nachziehen aus Calc. Storno aus Draft **nicht** vorgesehen. |
| `awaiting_sales_approval` | Stand unverändert; kein Revision-Pfad; **kein** Withdraw offener Freigabe (`APR-003` katalogisiert, laut Workflows/Code nicht umgesetzt). Paralleler neuer Create aus Calc trotzdem möglich. |
| `approval_rejected` | Stand unverändert (terminal). Ersteller kann Calc ändern und **neuen** verknüpften Draft anlegen (Nachbesserung). |
| `at_disposition` / operativ / `disposed` / `completed` / `cancelled` | Stand unverändert; kein Auto-Sync, keine Auto-Invalidierung allein wegen Calc-Edit. Storno nur aus erlaubten operativen Quellen (nicht Draft/Awaiting/Rejected). |

Belege Isolation:

- `CreateDispoOrderFromCalculationTest::test_dispo_order_is_unchanged_after_calculation_update`
- `…::test_dispo_order_is_unchanged_after_master_data_update`
- `DispoOrderRevisionTest::test_calculation_can_be_updated_during_revision`
- Audit-Nachzug: `docs/reviews/audit-nachzug-after-pr114/README.md` § B.4.4
- Katalog: **`DSP-003`**

Calc-Edit-Recht hängt **nicht** am Dispo-Status:

- `CalculationPolicy::update` → nur `canManageCalculations()`
- `CalculationWriter` ohne Dispo-Status-Guard

### 1.4 Create- / Revisions- / Korrekturpfade (tatsächlich)

| Pfad | Vorhanden? | Wann | Ergebnis |
|---|---|---|---|
| Neuer Dispo aus aktuellem Calc-Stand | **ja** | jederzeit bei Create-Recht | frischer Stand (`DSP-002`), neue Nummer `-SS` |
| Nachbesserung nach Ablehnung | **ja** | nur `approval_rejected`, nur Ersteller, max. ein Nachfolger | neuer Draft mit `revises_dispo_order_id`; Vorgänger unverändert |
| Kaufmännische Felder eines bestehenden Dispo aus Calc neu befüllen | **nein** | — | nicht implementiert |
| Auto-Sync Preise/Konditionen Calc → bestehender Dispo | **nein** | — | widerspräche `DSP-003` |
| Withdraw offener Freigabe | **nein** | `awaiting_sales_approval` | `APR-003` nicht umgesetzt |
| Draft-Sync fehlender Calc-Origin-Dyn-Felder | **ja, eng** | Draft, nur *fehlende* Capture-Keys | `syncCalculationDynamicFields` – **kein** Konditionen-/Preis-Refresh |

Nachbesserungsregeln:

- `DispoOrderRevisionRules::predecessorStatusAllowed` → nur `ApprovalRejected`
- Policy: `DispoOrderPolicy::revise` / `createRevision`
- UI-Banner: `DispoOrderRevisionBanner`
- Tests: `DispoOrderRevisionTest`, `DispoOrderRevisionRulesTest`

### 1.5 Guards gegen Doppel-/Widerspruch

Vorhanden:

- Position muss zur Calc gehören; mind. eine Position.
- Revision: Status, gleiche Calc, Ersteller, Unique-Nachfolger.
- Create serialisiert gegen Calc-Update (`lockForUpdate` auf Calc-Zeile).

Nicht vorhanden (bewusst, Katalog):

- Sperre bereits übernommener Positionen.
- Sperre paralleler aktiver Aufträge.
- Calc-Lock nach erstem Dispo-Create.
- Abweichungserkennung Calc-aktuell vs. Dispo-Stand.

### 1.6 UI-Hinweise (`PO-CALC-DISPO-HINT-1`)

| Ort | Hinweis |
|---|---|
| Create-Dialog | immer: Übernahme aktueller Stand + keine nachträgliche Sync; bei bestehenden Aufträgen zusätzlich: kein automatisches Ersetzen/Stornieren |
| Dispo-Show (Quellkalkulation) | Stand bei Erstellung; spätere Calc-Änderungen werden nicht übernommen |
| Calc-Wizard | **nicht** in diesem Slice (Props erkennen verknüpfte Aufträge nicht ohne neue Abfrage) |

Hinweise behaupten **keine** konkrete Abweichung und führen keine Diff-Prüfung durch.

---

## 2. Abgleich mit Anforderungen

| Quelle | Aussage | Entscheidung |
|---|---|---|
| `DSP-001` | Übernommene Positionen gekennzeichnet, erneut wählbar | bestätigt |
| `DSP-002` | Jeder neue Auftrag frisch aus aktuellem Calc-Stand | bestätigt |
| `DSP-003` | Unabhängiger Stand, keine Sync | bestätigt (Option A) |
| `DSP-004` | Teilaufträge/Korrekturen über Suffix | bestätigt |
| Freigabe-Workflow | Nach Ablehnung: Calc nachbessern → neuer verknüpfter Draft | bestätigt |
| PO-AT13-CC-1 | Invalidierung nur spezifizierter Auslöser | Calc-Edit bleibt kein Invalidierungsgrund |

---

## 3. Verworfene Alternative

**Option B** (Calc nach Übernahme sperren) wurde **nicht** gewählt. Sie widerspräche
`DSP-001`/`DSP-002` ohne Katalogänderung und kollidierte mit Nachbesserungs- und
Mehrfach-Create-Pfaden.

---

## Änderungsprotokoll

| Datum | Änderung |
|---|---|
| 4. Oktober 2026 | Erstfassung als Entscheidungsvorlage (Vorgeschlagen) |
| 4. Oktober 2026 | **Akzeptiert** Option A; Korrekturwege präzisiert; Hinweis-Slice `PO-CALC-DISPO-HINT-1` freigegeben |
