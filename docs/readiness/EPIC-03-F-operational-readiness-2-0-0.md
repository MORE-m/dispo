# EPIC-03-F – Operative Readiness 2.0.0

**Status:** Dokumentation vorbereitet. **STOP** vor Export, Dry-Run, Apply, Status-Phase und jedem Schreibvorgang.
**Datum:** 2026-11-25
**Schema:** `2.0.0`
**EPIC:** `EPIC-03-F`
**Quelle:** `dispo_mat_core`
**Staging:** `spn26jz7_dispo_staging`
**Vertrag:** [`../entscheidungen/EPIC-03-F-master-data-transfer-2-0-0.md`](../entscheidungen/EPIC-03-F-master-data-transfer-2-0-0.md)
**Fachliche Readiness (historisch 23.11.2026):** [`EPIC-03-F-full-master-data-readiness-2026-11-23.md`](EPIC-03-F-full-master-data-readiness-2026-11-23.md)

## Zweck

Dieses Paket bereitet den späteren operativen Gesamttransfer vor. Es enthält kein GO für Datenläufe.

## Dokumente

| Teil | Dokument |
|---|---|
| A – Runbook | [`EPIC-03-F-operational-runbook-2-0-0.md`](EPIC-03-F-operational-runbook-2-0-0.md) |
| B – Datenabgleich | [`EPIC-03-F-data-reconciliation-matrix-2-0-0.md`](EPIC-03-F-data-reconciliation-matrix-2-0-0.md) |
| C – Schutz/Abnahme | [`EPIC-03-F-protection-acceptance-matrix-2-0-0.md`](EPIC-03-F-protection-acceptance-matrix-2-0-0.md) |
| D – Risiken/Recovery | [`EPIC-03-F-risks-recovery-2-0-0.md`](EPIC-03-F-risks-recovery-2-0-0.md) |
| E – Slice-6-CLI | [`../entscheidungen/EPIC-03-F-slice6-operational-cli-requirements.md`](../entscheidungen/EPIC-03-F-slice6-operational-cli-requirements.md) |

## Dokumentationsbasis

| Punkt | Wert |
|---|---|
| Angefragte Main-Basis im Auftrag | `948b8f475e04bc8276dabdc540a1609cb4f85708` (Merge PR #274 / Slice 4) |
| Tatsächlicher Dokumentationsstand | `8266b2e06746c10d6ed352030f73ceab3458fa29` (`origin/main`, inkl. Merge PR #275 / Slice 5) |
| Begründung | PR #275 war zum Start dieser Readiness bereits gemerged; Doku bildet den aktuellen Main ab |

Nach jedem weiteren Merge, der den Transfer-Code ändert, diese Doku erneut gegen `main` abgleichen.

## Harte Grenzen (verbindlich)

- Keine Exporte, Dry-Runs, Applies oder Status-Läufe ohne separates PO-GO je Schritt
- Keine Verbindungen zu Quelle, Staging oder Produktion im Rahmen dieser Dokumentation
- Keine automatische Datenkorrektur
- Fehlende Produktionspreislisten 2027 außer MORE Hamburg-Kombi nicht erfinden
- Preisstatus-Phase nur nach eigenem PO-GO und nur gegen dasselbe SHA256-Paket

## Operative Checkliste (Kurzform)

Siehe Runbook Abschnitt „Operative Checkliste“. Die zwölf Schritte sind:

1. Technischen Stand und CI-Gates prüfen
2. Quell- und Staging-DB eindeutig identifizieren
3. Ausgangsbestand beider DBs dokumentieren
4. Staging-Sicherung und Wiederherstellbarkeit prüfen
5. Separates PO-GO für Export
6. Gesamtexport Schema 2.0.0
7. Manifest, Pflichtdateien, SHA256 prüfen
8. Separates PO-GO für genau einen Staging-Dry-Run
9. Dry-Run-Bericht und Konflikte auswerten
10. Separates PO-GO für Apply
11. Apply und Nachkontrolle
12. Idempotenzprüfung
13. Preisstatus-Phase nur nach eigenem GO (separater Schritt)

## Was für den ersten kontrollierten Staging-Dry-Run noch fehlt

Kurzfassung; Details in Teil E und im Abschlussbericht des PRs:

- Operative CLI-Freigabe für Schema `2.0.0` (aktuell CLI blockiert `2.0.0`-Import)
- Slice-6-Funktionen: DB-Allowlist, Backup-Nachweis, Paket-SHA256-Bindung, Sicherheitsbericht, Status-Phase-Trennung
- Frische, verifizierte Staging-Sicherung zum Ausführungszeitpunkt
- Aktuelle Ausgangszählung (Readiness-Zahlen vom 23.11. sind historische Referenz)
- Separates PO-GO für Export und danach für genau einen Dry-Run
