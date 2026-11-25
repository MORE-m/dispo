# EPIC-03-F – Operative CLI-Anforderungen (Slice 6a / 6b / 7)

**Status:** Nur Anforderungen. **Keine Implementierung in diesem Dokument/PR.**
**Datum:** 2026-11-25
**Vertrag:** [`EPIC-03-F-master-data-transfer-2-0-0.md`](EPIC-03-F-master-data-transfer-2-0-0.md)
**Ist-Code (Main nach PR #275):** Export V2 CLI vorhanden; Import V2 inhaltlich A–C in Tests; **operativer CLI-Import 2.0.0 gesperrt**

## 1. Ist-Zustand (verbindlich ablesen)

### Export – VORHANDEN

```text
php artisan dispo:export-master-data
  --path=
  --schema=1.0.0|2.0.0   (Default 1.0.0)
  --inventory=*            (nur 1.0.0; bei 2.0.0 verboten)
  --git-sha=
```

### Import – VORHANDEN für 1.0.0, GESPERRT für 2.0.0

```text
php artisan dispo:import-master-data {path}
  --dry-run | --apply      (genau eine Option)
  --confirm-database=
```

Aktuelles Verhalten bei `schema_version=2.0.0`:

- Command und `MasterDataImporterV2::import()` werfen:
  `Import für schema_version=2.0.0 ist operativ nicht freigegeben (Status-Phase und Staging-GO fehlen).`
- Phasen A–C laufen nur über isolierte Test-Hooks (`executePhasesABCForIsolatedTests` u. a.).

### Bereits im Service (nicht operativ verdrahtet)

- Schreib-Whitelist fail-closed
- Schutz-Snapshots und Diff
- Preislisten-Pin-Guard
- Security-Block im Report (`package_integrity`, `protection_snapshots`, `price_list_pins`, `whitelist`, `referential_integrity`, `result`)
- Forbidden-Test-DB-Namen für isolierte Ausführung
- Draft-only beim Inhalts-Apply

---

## 2. Phasenüberblick (verbindlich)

Operative CLI-Freigabe wird **nach Phasen** getrennt. Die Preisstatus-Implementierung ist **keine** Voraussetzung für den ersten schreibfreien Dry-Run.

| Phase | Ziel | Schreibt Staging? | Operative Voraussetzung für … |
|---|---|---|---|
| **Slice 6a** | Schreibfreier V2-Dry-Run über CLI | nein | ersten kontrollierten Staging-Dry-Run |
| **Slice 6b** | Inhalts-Apply mit verifiziertem Backup | ja (Whitelist, Draft-Preise) | Apply nach Dry-Run-PASS und PO-GO |
| **Slice 7** | Preisstatus-Phase mit eigenem GO | ja (nur Statusfelder) | spätere Aktivierung/Archivierung; **nicht** für Dry-Run oder Inhalts-Apply |

Slice 6a und 6b bilden zusammen die frühere „Slice-6“-CLI-Linie ohne Status. Slice 7 bleibt ein eigener Implementierungs- und GO-Schritt.

---

## 3. Anforderungsliste nach Phase

### 3.1 Gemeinsam / Routing (6a + 6b + später 7)

| ID | Anforderung | Phase | Priorität |
|---|---|---|---|
| CLI-01 | `--confirm-database` bleibt Pflicht für jede Aktion | 6a/6b/7 | muss |
| CLI-02 | Wert muss **exakt** dem aktiven `DB_DATABASE` entsprechen | 6a/6b/7 | muss |
| CLI-10 | Genau eine Aktionsoption: `--dry-run` **oder** `--apply` **oder** Status-Aktion | 6a/6b/7 | muss |
| CLI-14 | Schema-Erkennung aus Manifest; `2.0.0` → Importer V2; `1.0.0` → unverändert V1 | 6a/6b/7 | muss |
| CLI-15 | V1-Verhalten und Tests bleiben grün | 6a/6b/7 | muss |
| CLI-30 | Vor jedem Dry-Run/Apply/Status: Manifest + `checksums.sha256` validieren | 6a/6b/7 | muss |
| CLI-33 | Abweichung SHA/Manifest → STOP, keine Teilverarbeitung | 6a/6b/7 | muss |
| CLI-40 | JSON-Report persistieren (Pfad ausgeben) | 6a/6b/7 | muss |
| CLI-41 | Security-Block mit: package_integrity, protection_snapshots, protected_table_counts, price_list_pins, whitelist, referential_integrity, result PASS/STOP | 6a/6b/7 | muss |
| CLI-42 | Exit-Code ≠ 0 bei STOP/Konflikten | 6a/6b/7 | muss |
| CLI-43 | Keine PII/Secrets im Report | 6a/6b/7 | muss |
| CLI-44 | Zusammenfassung creates/unchanged/updates/conflicts auf STDOUT | 6a/6b/7 | muss |

### 3.2 Slice 6a – schreibfreier V2-Dry-Run

| ID | Anforderung | Priorität |
|---|---|---|
| CLI-05 | Dry-Run gegen Staging-Allowlist; schreibfrei verifiziert | muss |
| CLI-50 | Dry-Run plant Phasen A–C schreibfrei inkl. virtueller Referenzauflösung | muss |
| CLI-51 | Konflikte → kein Schreiben | muss |
| CLI-31a | `--require-package-sha256=<hex>` für Dry-Run empfohlen (soll); für spätere Apply-Bindung vorbereiten | soll |
| CLI-23 | Backup-Nachweis beim Dry-Run optional; Report darf warnen wenn fehlend | soll |

**Abbruch 6a (Auszug):** Schema/Manifest ungültig; Checksum-Fehler; Confirm/Allowlist-Fail; Fachkonflikte; Schutz-/Pin-STOP im Plan; Paket enthält Ausgeschlossenes.

### 3.3 Slice 6b – Inhalts-Apply mit verifiziertem Backup

| ID | Anforderung | Priorität |
|---|---|---|
| CLI-03 | Schreibende Läufe nur gegen Allowlist `spn26jz7_dispo_staging` | muss |
| CLI-04 | Apply gegen `dispo_mat_core`, `dispo`, Produktion und Aliase → harter Abbruch | muss |
| CLI-11 | `--apply` = nur Inhaltsphasen A–C (Schutz), Preise nur `draft`; **kein** Statuswechsel | muss |
| CLI-13 | Status-Phase nie implizit nach Apply | muss |
| CLI-20b | `--apply` verlangt Nachweis einer frischen Staging-Sicherung | muss |
| CLI-21 | Form offen, z. B. `--require-backup-proof=<path-or-token>` prüft Gate-PASS + Dump-Metadaten | muss |
| CLI-22 | Ohne gültigen Nachweis → STOP vor Schreibbeginn | muss |
| CLI-31b | `--require-package-sha256=<hex>` Pflicht für Apply | muss |
| CLI-52 | Apply in einer Transaktion; Rollback bei Fehler | muss |
| CLI-53 | Idempotenz in synthetischen Tests: zweiter Lauf desselben Pakets ohne zusätzliche Creates/Updates | muss |
| CLI-54 | Whitelist-Guard vor und während Apply | muss |
| CLI-55 | Pin- und Schutz-Snapshot-Prüfung vor Schreiben und nach Plan/Apply | muss |

Operativer Idempotenz-Recheck auf Staging erfordert weiterhin ein separates PO-GO (siehe Runbook Schritt 12); Tests sind davon unabhängig.

### 3.4 Slice 7 – Preisstatus-Phase (eigenes GO)

| ID | Anforderung | Priorität |
|---|---|---|
| CLI-12 | Status-Phase als eigene Option, z. B. `--apply-price-status` | muss |
| CLI-20s | Status-Phase verlangt verifizierten Backup-Nachweis | muss |
| CLI-31s | `--require-package-sha256=<hex>` Pflicht; nur dasselbe Paket wie freigegebener Apply | muss |
| CLI-32 | Status-Phase nur gegen dasselbe SHA256-Paket | muss |
| CLI-70 | Erlaubt nur `draft→active` / `draft→archived` gemäß `source_status` und unverändertem Fingerabdruck/Metadaten | muss |
| CLI-71 | Kein Statuswechsel an gepinnten oder bereits active/archived Listen | muss |
| CLI-72 | Keine Inhaltsänderungen in der Status-Phase | muss |

**Slice 7 ist nicht Voraussetzung für Slice 6a oder 6b.**

---

## 4. Vorgeschlagene CLI-Oberfläche (nur Entwurf)

```text
# Slice 6a – Dry-Run Staging (schreibfrei)
php artisan dispo:import-master-data <PAKET> \
  --dry-run \
  --confirm-database=spn26jz7_dispo_staging \
  --require-package-sha256=<HEX>

# Slice 6b – Inhalts-Apply
php artisan dispo:import-master-data <PAKET> \
  --apply \
  --confirm-database=spn26jz7_dispo_staging \
  --require-package-sha256=<HEX> \
  --require-backup-proof=<PROOF>

# Slice 7 – Status-Phase (separates GO; eigene Implementierung)
php artisan dispo:import-master-data <PAKET> \
  --apply-price-status \
  --confirm-database=spn26jz7_dispo_staging \
  --require-package-sha256=<HEX> \
  --require-backup-proof=<PROOF>
```

Parameternamen sind Vorschläge; verbindlich ist die Semantik.

---

## 5. Explizit nicht Teil von Slice 6a/6b

- Operativer Export-/Import-Lauf gegen echte DBs im Implementierungs-PR
- Automatische Konflikt-Reparatur
- Dateitransfer von Inventar-Logos
- CRM-/User-Import
- Preisstatus-Implementierung (das ist **Slice 7**)
- Änderung am Vertrag 1.0.0-Verhalten außer Schema-Routing
- UI für den Transfer

---

## 6. Abnahmekriterien (Code)

### Slice 6a

1. Feature-Tests: CLI Dry-Run 2.0.0 schreibfrei gegen isolierte Test-DB.
2. Negativtests: Confirm-Mismatch, Checksum-Mismatch, Fachkonflikt → Exit ≠ 0, keine Writes.
3. V1-Suite unverändert grün.
4. Docs/Runbook: Dry-Run-Befehl von „OFFEN“ auf „VORHANDEN“ (6a).

### Slice 6b

1. Feature-Tests: Apply 2.0.0 mit Allowlist/Confirm/Backup/SHA Gates.
2. Negativtests: Quelle/Prod Apply, fehlendes Backup, Whitelist-Verstoß, Pin-Konflikt.
3. Idempotenz in synthetischen Tests.
4. Docs/Runbook: Apply-Befehl von „OFFEN“ auf „VORHANDEN“ (6b).

### Slice 7

1. Feature-Tests: Status-Phase getrennt; greift keine Pins; nur draft→active/archived nach Regeln.
2. Negativtests: anderes Paket-SHA, Status ohne Backup-Nachweis, Status an gepinnter Liste.
3. Docs/Runbook: Status-Befehl von „OFFEN“ auf „VORHANDEN“ (7).

---

## 7. Abhängigkeit zum ersten operativen Dry-Run

Der erste kontrollierte Staging-Dry-Run für Schema 2.0.0 ist freigabefähig, sobald **Slice 6a** (muss-Zeilen der Dry-Run-Linie) erfüllt ist und ein separates Dry-Run-PO-GO vorliegt.

**Nicht** blockierend für diesen ersten Dry-Run:

- Slice 6b (Backup-Nachweis, Apply-Freigabe)
- Slice 7 (Preisstatus)
