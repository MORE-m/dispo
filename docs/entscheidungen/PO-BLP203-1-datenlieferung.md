# PO-BLP203-1 Datenlieferung / lokale CRM-Einrichtung

Status: **A erledigt – B/C offen** (kein Deploy); Typmapping **D1** freigegeben (Code-Nachzug)  
Datum: 10. Oktober 2026  
Bezug: [`PO-BLP203-1`](PO-BLP203-1-crm-salesforce-meridian.md) (inkl. **D1**)  
Ziel-DB: lokale **`dispo_mat_core`**  
Code-Basis: `aed4b070…` + Feature `feat/bl-p2-03a-crm-record-type-mapping`

## 1. Freigaben

| Phase | Inhalt | Freigabe |
|---|---|---|
| **A** | Lesende Prüfung Ziel-DB + Exporte | **erledigt** |
| **D1** | Typmapping Gesellschafter/Sonstiges → Kunde + Herkunftserhalt | **akzeptiert** (Review/Merge ausstehend) |
| **B** | Sicherung → Migrationen → Admin-Import → Nachkontrolle | **nicht erteilt** |
| **C** | Browser-Abnahme synthetisch | **nicht erteilt** |
| Deploy | — | **nicht erteilt** |

## 2. Geprüfte Importgrundlage

| | |
|---|---|
| Datei | `Acc_Meridian_Dispotool-2026-10-10-14-14-34.csv` |
| SHA-256 | `0c424903fd45270245953e703900ed1158dd6732d37ac459940254482385e150` |
| Format | UTF-8, Semikolon, 5 Spalten, 3453 Datenzeilen |
| Nach D1 (In-Memory) | **3453 gültig**; intern Kunde **3097** / Agentur **356**; Fehler **0** |
| Roh-Typen | KUNDE 3068, GESELLSCHAFTER 7, SONSTIGE 22, AGENTUR 356 |

Filterung der Datei: **verworfen**. Dateiprüfung ≠ Apply-Freigabe.

## 3. Voraussetzungen für B (nach Merge D1)

1. Merge des Typmapping-Nachzugs inkl. Migration `2026_10_10_160000_add_salesforce_record_type_to_crm_account_versions`.  
2. Explizite Apply-Freigabe dieser Datei (Name + SHA-256).  
3. Sicherung → CRM-Basis-Migration + Record-Type-Migration → Admin-Upload → Vorschau → Apply.  
4. Keine Freitext-Auto-Zuordnung; Konflikte nicht still lösen.

## 4. Verworfene Option

„Typen GESELLSCHAFTER/SONSTIGE aus der Datei entfernen“ – **verworfen** zugunsten D1.
