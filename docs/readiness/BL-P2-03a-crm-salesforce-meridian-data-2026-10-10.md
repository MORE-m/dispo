# Daten-Readiness: BL-P2-03a – Salesforce/Meridian (lokal)

Status: **A/B/C lokal erledigt**; Docs-Draft-PR (noch nicht auf `main`); Deploy **nicht** freigegeben
Urteil Export: **IMPORTIERT** (operativ Apply)
Stand: 10. Oktober 2026
Code-Basis Main: `65da41121206b0b66327831ff9586996f2c3c331` (Merge PR #134; Post-Merge-CI `38063164855` SUCCESS)
Ziel-DB: **`dispo_mat_core`** @ `127.0.0.1:3306`
PO: [`PO-BLP203-1`](../entscheidungen/PO-BLP203-1-crm-salesforce-meridian.md) + **D1**
PO Daten: [`PO-BLP203-1-datenlieferung`](../entscheidungen/PO-BLP203-1-datenlieferung.md)
Review: [`reviews/crm-salesforce-meridian-data-readiness/`](../reviews/crm-salesforce-meridian-data-readiness/)
Worktree-Doku: `dispo-wt-docs-crm-salesforce-meridian-data-readiness`

> Exportdatei, Dumps, Baseline und Zugangsdaten **nicht** in Git.
> `.env` / Port **8000** / `dispo-main`-Code unberührt. Separatserver Port **8061** nach Abnahme beendet.
> `BL-P2-03` nicht pauschal vollständig; `CRM-004` offen; Deploy/andere Umgebungen separat offen.

## 0. Urteil

| Ebene | Status |
|---|---|
| Code auf `main` | ja (`65da411…` / PR #134) |
| D1 Typmapping | auf `main` |
| CRM-Migrationen lokal | **angewendet** (beide) |
| Operativer Admin-Import | **Apply** Import-ID **2** |
| **A** | erledigt |
| **B** | lokal erledigt |
| **C** | lokal erledigt (Browser Port 8061) |
| Deploy | nein |

---

## 1. Sicherung (B1)

Pfad (außerhalb Git):
`local-backups/bl-p2-03a-20261010-175832/`

| Artefakt | Inhalt |
|---|---|
| `dispo_mat_core-full.sql` | Full-Dump (Daten/Struktur/Trigger; routines/events lokal nicht dumpbar) |
| `baseline-before-crm.json` | Calc/Dispo-IDs, Status, Money-Fingerprints vor CRM |
| `RESTORE.md` | Wiederherstellungsanleitung |
| Restore-Check | temp. DB `dispo_mat_core_restore_check` erfolgreich, danach gedroppt |

Code-Head zum Backup-Zeitpunkt: `65da411…` (identisch zur Abnahme).

---

## 2. Migrationen (B2)

Gezielt angewendet (keine fremden pending mitgezogen):

1. `2026_10_09_180000_create_crm_salesforce_meridian_tables`
2. `2026_10_10_160000_add_salesforce_record_type_to_crm_account_versions`

Nachkontrolle: `crm_*`-Tabellen vorhanden; optionale Calc-/Dispo-CRM-Felder; Spalte `salesforce_record_type` an Versions.

---

## 3. Operativer Import (B3)

| | |
|---|---|
| Datei | `Acc_Meridian_Dispotool-2026-10-10-14-14-34.csv` |
| SHA-256 (`crm_imports.checksum_sha256`) | `0c424903fd45270245953e703900ed1158dd6732d37ac459940254482385e150` |
| Größe | 265 407 Bytes |
| Import-ID | **2** |
| Status | `applied` (2026-10-10 16:01:46) |
| Zeilen / gültig / Fehler / Warnungen | 3453 / 3453 / **0** / 0 |

D1-Mapping unverändert: KUNDE/GESELLSCHAFTER/SONSTIGE → customer; AGENTUR → agency; Originaltyp je Version.

### Operative Aggregat-Nachkontrolle (nach Apply; ohne spätere synthetische Abnahme)

| Kennzahl | Erwartet | Belegt |
|---|---:|---:|
| gültige Datensätze | 3453 | 3453 |
| intern Kunde / Agentur | 3097 / 356 | 3097 / 356 (operativ; DB-Gesamt später + synthetisch) |
| Meridian mit / ohne | 1645 / 1808 | 1645 / 1808 (operativ Apply) |
| blockierende Fehler | 0 | 0 |
| SF-Duplikate | 0 | 0 |

Originaltypen (operative SF-Accounts): KUNDE 3068 · GESELLSCHAFTER 7 · SONSTIGE 22 · AGENTUR 356.

Audit (kumulativ inkl. späterer Abnahme-Imports): u. a. `crm_import.uploaded/validated/applied`, `crm_account.imported`, Meridian-/SF-Supplements.

---

## 4. Baseline-Vergleich (Calc/Dispo vor CRM)

IDs **Calc 1–12** / **Dispo 1–9** unverändert freitextgebunden:

| Check | Ergebnis |
|---|---|
| Money-Fingerprint Calc | **Match** (`73cd26ba…2747`) |
| Money-Fingerprint Dispo | **Match** (`215fd48f…890e`) |
| `customer_account_id` auf Baseline-Zeilen | **0** (keine Auto-Verknüpfung) |
| Status Dispo 1–9 | weiterhin `draft` |

Neue synthetische Calc/Dispo nur ab ID 13 bzw. 10 (Abnahme C).

---

## 5. Browser-Abnahme C (Port 8061)

Playwright gegen `http://127.0.0.1:8061` (Separatserver; nach Abnahme beendet).
Suite: 3/3 passed. Präfix „BL-P2-03a lokale Abnahme“; synthetische Domains/`001BLP203*` SF-IDs.

### Belegte Fälle

| Fall | Beleg |
|---|---|
| Lesend vier Originaltypen | Katalogsuche Account KUNDE / GESELLSCHAFTER / SONSTIGE / AGENTUR |
| Vertikal Provisional→Calc→Dispo→Import→Meridian→Re-Import | Calc **22**, Dispo **17**; SF-Accounts **3474**/ **3475**; Meridian `M-BLP203A-K671479` / `M-BLP203A-A671479`; Firmierung/Preise/Status erhalten; idempotenter Re-Import |
| Manueller Link ohne Domain | Calc **23**, Dispo **18**; Ziel **3476**, provisional merged **3477** → Meridian `M-BLP203A-M679631` |

### Synthetische Inventarliste (Kennzeichnung; keine Löschung)

Offene Rest-Provisionals von Zwischenläufen (bewusst belassen): u. a. Account-IDs **3460**, **3461**, **3463**, **3465**.
Erfolgreiche Vertikal-/Manuell-Läufe: Accounts **3472–3477**, Calc **22–23**, Dispo **17–18** (IDs in `/tmp/blp203a-acceptance-ids.json`, `/tmp/blp203a-acceptance/manual-link-ids.json` – nicht in Git).

Hinweise: Frontend-Manifest nach CRM-UI lokal neu gebaut (`npm run build`), damit `crm/accounts/show` im Vite-Manifest liegt. `max_allowed_packet` für große Preview-JSON lokal auf 64M gesetzt (Session/Global Dev).

---

## 6. Freigaben / Grenzen

| Schritt | Status |
|---|---|
| **A** | erledigt |
| **D1** | auf `main` (PR #134) |
| **B** / **C** | lokal erledigt |
| Deploy / andere Umgebungen | **offen** |
| `CRM-004` Kontakte | **offen** |
| `BL-P2-03` Gesamt | **nicht** pauschal vollständig |
