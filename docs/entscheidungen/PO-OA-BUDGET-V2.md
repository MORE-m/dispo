# PO-OA-BUDGET-V2 – OA-Budgetplanung vollständig V2

Status: **Akzeptiert / verbindlich** (15. November 2026)  
Stand: 15. November 2026  
Basis: `origin/main` @ `d15e48c6993b303eb5537cd140b79044ada709ac`  
(nach NATIVE-5 operativ / Merge #245)  
IDs: `PO-OA-BUDGET-V2`, `BUD-001`–`BUD-009` (V1-Bezug Spot-only)  
Parallele: `PO-STD-V2` (Standardangebote vollständig V2)

---

## 1. Entscheidung

Online Audio im Planungsweg „Mit Budget planen“ gehört **vollständig zu V2**.

Damit ist OA-Budget:

- **kein Bestandteil des V1-Scopes**
- **kein V1-Release-Blocker**

Keine Teilimplementierung mehr in V1.

---

## 2. V1-Grenze

```text
Spot-Budgetplanung              = IN  (V1, unverändert)
OA-TKP Budgetplanung            = OUT (V2)
Free Position / Native-Ad Budget = OUT (V2)
Mixed Spot + OA Budget          = OUT (V2)
```

Der bestehende Spot-Budgetpfad bleibt unverändert.  
`BUD-001`–`BUD-009` beziehen sich in V1 auf diesen Spot-Budgetpfad.

OA Normal-TKP und OA Festpreis bleiben davon unberührt.

---

## 3. Bestehende Guards (fail-closed, unverändert)

Nicht entfernen und nicht aufweichen:

- `OaTkpHttpReleaseGuard::assertBudgetNotTkp()`
- weitere bestehende Budget-Grenzen gegen OA-TKP
- `FreePositionHttpReleaseGuard::assertBudgetNotFreePosition()`
- `CalculationWriter::applyBudgetProposal()` behandelt OA-TKP weiterhin fail-closed

Negativtests (Propose/Apply mit OA-TKP bzw. Free Position → fail-closed) bleiben
bestehen und sind der gewünschte **V1-Vertrag**, keine temporären Blocker-Tests.

---

## 4. Technisches Fundament

Analog zu `PO-STD-V2`:

- vorhandenes Spot-Budget-Fundament bleibt
- keine Rückmigration
- keine Löschung
- keine Datenbereinigung
- Guards bleiben
- Produktisierung von OA-/Free-/Mixed-Budget erst in V2

Keine Runtime-Änderung in diesem Slice. Keine Migration. Kein DB-Write.
Kein Deploy. `dispo_mat_core` unberührt.

---

## 5. V2-Themen (vollständig verschoben)

- OA-Budgetvorschläge
- automatische Impressionsermittlung aus Zielbudget
- Budgetverteilung über mehrere OA-Werbeelemente
- Mixed Spot + OA Budget
- OA-Budget + Targetings
- OA-Budget + Positionsrabatte
- OA-Budget + Auftragsrabatte
- OA-Budget + AE
- OA-Budget-Fingerprint
- OA-Budget-Stale-Vertrag
- OA-Budget-Apply
- OA-Budget-Concurrency
- OA-Budget-E2E
- OA-Budget-UI

Nicht mit `OA-001`–`OA-004` vermischen.

---

## 6. Kein V1-Blocker

OA-Budget darf nicht mehr als „offen für V1“ geführt werden, sondern als **V2**.

V2 erhält später eigenen Readiness-/PO-Slice. Analyse-/Vertragsdokumente dürfen
als Grundlage erhalten bleiben und sind dann mit
`Status: V2 / nicht Bestandteil V1` zu führen.

---

## 7. Docs dieses Slices

- `docs/anforderungskatalog.md` (§3, §8.4, §11)
- `docs/backlog-v1.md`
- `docs/blocker-und-entscheidungslog.md`
- `docs/fortschritt.md`
- `docs/test-und-abnahmekatalog.md` (Scope-Klarstellung)
- `docs/ui-ux-konzept.md` / `docs/ux-ui-gate.md` (V1 Spot-only Hinweis)

Kein Branch für OA-Budget-Runtime. Kein Guard öffnen. Keine neue BudgetStrategy.
Kein OA-Budget-Payload. Keine UI. Keine Migration.
