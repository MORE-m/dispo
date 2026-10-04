# Readiness: PO-CALC-DISPO-HINT-1 – Hinweis Isolation Calc ↔ Dispo

Status: **READY**  
Stand: 4. Oktober 2026  
Basis: `origin/main` @ `b36945f78b903d52c8faeadd70b3f3b805ee53b2`  
Entscheidung: `docs/entscheidungen/PO-CALC-DISPO-LIFECYCLE-1-calc-edit-nach-dispo-create.md` (**Akzeptiert**, Option A)

## 0. Gate

| Voraussetzung | Status |
|---|---|
| PO Option A bestätigt | **ja** |
| Neuer Create als Übernahmeweg (nicht Auto-Korrektur/Ersetzung) bestätigt | **ja** |
| Katalog `DSP-001`–`DSP-003` unverändert maßgeblich | ja |
| Kein Diff-/Sperr-/Sync-Auftrag in diesem Slice | festgeschrieben |
| Dev-DB / Port 8000 / Storage unberührt | Regel |

**Readiness-Urteil:** **READY** – Umsetzung freigegeben.

## 1. Fachvertrag

| Regel | Festlegung |
|---|---|
| Calc nach Dispo-Create | bleibt editierbar |
| Bestehende Dispoaufträge | unverändert bei Calc-Änderung |
| Neuer Create | übernimmt aktuellen Stand der ausgewählten Positionen |
| Auto-Ersatz / Auto-Storno | nein |
| Hinweis | erklärt Trennung; **kein** Nachweis einer konkreten Abweichung |

### Verbindliche Nutzertexte

**Create-Dialog (immer, sobald Positionen geladen):**

> Der neue Dispoauftrag übernimmt den aktuellen Stand der ausgewählten Positionen. Spätere Änderungen an der Kalkulation ändern diesen Auftrag nicht.

**Create-Dialog (zusätzlich, wenn bereits Aufträge zur Calc existieren – via `already_adopted`/`adoptions`):**

> Ein neuer Dispoauftrag ersetzt oder storniert bestehende Aufträge nicht automatisch.

**Dispo-Show (Quellkalkulation):**

> Dieser Dispoauftrag enthält den Kalkulationsstand bei seiner Erstellung. Spätere Änderungen an der Kalkulation werden nicht übernommen.

**Calc-Wizard:** entfällt in diesem Slice – bestehende Props erkennen verknüpfte Dispoaufträge nicht ohne neue Abfrage-/Diff-Infrastruktur.

## 2. Scope

### In Scope

- UI-Hinweise Create-Dialog + Dispo-Show.
- Docs: Entscheidung akzeptiert, Workflows/Fortschritt/Backlog im erledigten Umfang.
- Vitest + Erweiterung bestehender Dispo-E2E-Smoke; Isolation-Regression.

### Ausschlüsse (bewusst)

- Keine Calc-Sperre, keine neue Korrekturfunktion, kein Diff.
- Keine Auto-Invalidierung / Auto-Sync von Preisen/Konditionen.
- Keine Änderung an Freigabe-, Status-, Create-/Revision-/Storno-Logik.
- Keine Migration / Persistenzänderung.
- Kein Wizard-Hinweis ohne vorhandene Linked-Order-Props.
- Kein Anfassen von `syncCalculationDynamicFields`.

## 3. Codepfade / UI-Orte

| Ort | Datei(en) | Änderung |
|---|---|---|
| Create-Dialog | `resources/js/components/dispo-order-create-dialog.tsx` (+ Test) | Isolationshinweis; No-Replace bei bestehenden Aufträgen |
| Dispo-Show | `resources/js/pages/dispo-orders/show.tsx` | Hinweis bei Quellkalkulation |
| Calc-Wizard | — | bewusst nicht |
| Docs | Entscheidung, Readiness, Workflows, Fortschritt, Backlog | nachgezogen |

## 4. Rechte / Persistenz

- Keine Policy-Änderung.
- Snapshot-/Persistenzverhalten **unverändert**.
- Regression: `CreateDispoOrderFromCalculationTest::test_dispo_order_is_unchanged_after_calculation_update`,
  `DispoOrderRevisionTest::test_calculation_can_be_updated_during_revision`.

## 5. Tests

| Art | Fokus |
|---|---|
| Vitest | Create-Dialog Isolation- + No-Replace-Hinweis |
| E2E (`dispo-order.spec.ts`, isolierte SQLite/Port) | Create-Hinweis, Show-Hinweis, No-Replace bei bestehendem Auftrag, Submit ohne Extra-Bestätigung |
| Pest Regression | Isolationstests |

## 6. DoD

- [x] PO-A + Slice-Freigabe dokumentiert
- [x] Hinweis Create-Dialog + Dispo-Show
- [x] Formulierung ohne Abweichungsbehauptung
- [x] Keine Sperre/Diff/Sync/Korrekturfunktion
- [x] Schmale UI-Tests + Isolation-Regression geplant
- [x] Docs nachgezogen
- [ ] Review/Merge/Deploy gesondert
