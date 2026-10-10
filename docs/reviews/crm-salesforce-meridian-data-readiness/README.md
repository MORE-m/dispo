# Review: CRM Salesforce/Meridian – lokale Daten-Readiness (BL-P2-03a)

Stand: 10. Oktober 2026 (B/C lokal erledigt; Docs-Draft-PR)
Main-Basis: `65da411…` / PR #134 (Post-Merge-CI `38063164855` SUCCESS)
Doku-Worktree/Branch: `dispo-wt-docs-crm-salesforce-meridian-data-readiness` /
`docs/crm-salesforce-meridian-data-readiness` (noch nicht auf `main`)
Readiness: [`BL-P2-03a-crm-salesforce-meridian-data-2026-10-10`](../../readiness/BL-P2-03a-crm-salesforce-meridian-data-2026-10-10.md)

## Phasen

| Phase | Status |
|---|---|
| **A** Lesen | erledigt |
| **D1** Typmapping | auf `main` (PR #134) |
| **B** Sicherung/Migration/Import | lokal erledigt |
| **C** Browser-Abnahme Port 8061 | lokal erledigt (3/3) |
| Deploy | nicht freigegeben |

## Operativer Import

| | |
|---|---|
| Datei | `Acc_Meridian_Dispotool-2026-10-10-14-14-34.csv` |
| SHA-256 | `0c424903fd45270245953e703900ed1158dd6732d37ac459940254482385e150` |
| Import-ID | **2** (`applied`) |
| gültig / Fehler | 3453 / 0 |
| intern Kunde / Agentur | 3097 / 356 |
| Meridian mit/ohne | 1645 / 1808 |

Sicherung: `local-backups/bl-p2-03a-20261010-175832/` (+ `RESTORE.md`).
Baseline Calc 1–12 / Dispo 1–9: Fingerprints match; keine Account-FK-Auto-Links.

## Grenzen

- `BL-P2-03` nicht pauschal vollständig
- `CRM-004` offen
- Deploy / andere Umgebungen separat offen
- Keine realen Account-/E-Mail-Listen in Git-Docs

## Nächster Schritt

Deploy-Entscheidung separat; kein Folgeslice aus diesem Auftrag.
