# PO-BLP203-1 Datenlieferung / lokale CRM-Einrichtung

Status: **A/B/C lokal erledigt** (kein Deploy; Docs-Draft-PR noch nicht auf `main`)
Datum: 10. Oktober 2026
Bezug: [`PO-BLP203-1`](PO-BLP203-1-crm-salesforce-meridian.md) (inkl. **D1**)
Daten-Readiness: [`BL-P2-03a-crm-salesforce-meridian-data-2026-10-10`](../readiness/BL-P2-03a-crm-salesforce-meridian-data-2026-10-10.md)
Ziel-DB: lokale **`dispo_mat_core`**
Code: `65da411…` (PR #134)

## 1. Entscheidungsumfang

| Phase | Inhalt | Freigabe |
|---|---|---|
| **A** | Lesende Prüfung Ziel-DB + Exporte | **erledigt** |
| **D1** | Gesellschafter/Sonstiges → Kunde; Originaltyp erhalten; keine Dateifilterung | **auf `main`** |
| **B** | Sicherung → Migrationen → Admin-Import → Nachkontrolle | **lokal erledigt** |
| **C** | Browser-Abnahme synthetisch (Port 8061) | **lokal erledigt** |
| Deploy | — | **nicht erteilt** |

## 2. Operative Importgrundlage (angewendet)

- Datei: `Acc_Meridian_Dispotool-2026-10-10-14-14-34.csv`
- SHA-256: `0c424903fd45270245953e703900ed1158dd6732d37ac459940254482385e150`
- Import-ID **2**, Status `applied`
- 3453 gültig, intern Kunde **3097** / Agentur **356**, Fehler **0**
- Exakte Typstrings: `Account KUNDE`, `Account GESELLSCHAFTER`, `Account SONSTIGE`, `Account AGENTUR`

**Verworfen:** Filterung der Datei. Keine Freitext-Auto-Zuordnung; Konflikte nicht still gelöst.

## 3. Lokal umgesetzt (B/C)

1. Sicherung `local-backups/bl-p2-03a-20261010-175832/` + Restore-Check.
2. Migrationen `2026_10_09_180000_…` + `2026_10_10_160000_…`.
3. Admin Upload → Vorschau → Apply der freigegebenen Datei.
4. Baseline Calc/Dispo unverändert (Fingerprints match).
5. Browser-Abnahme synthetisch Port **8061** (3/3); Server beendet.

## 4. Offen

Deploy / andere Umgebungen; `CRM-004`; Rest-`BL-P2-03`. Kein Folgeslice aus diesem Auftrag.
