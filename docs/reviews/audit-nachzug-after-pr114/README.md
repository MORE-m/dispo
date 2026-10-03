# Audit-Nachzug nach PR #114

Stand: 3. Oktober 2026
Auditbasis: Merge-Commit **`d7896aaf8e633f1e977a03e79af43290ac7c6ec3`**
(`Merge pull request #114 from MORE-m/docs/local-env-consolidation`)
Lokaler Main: `dispo-main` @ dieselbe SHA, Port **8000**, Dev-DB **`dispo_mat_core`**
Docs-Worktree: `dispo-wt-docs-status-after-pr114` / Branch `docs/status-after-pr114`
Test-Smoke (separater Draft): [#115](https://github.com/MORE-m/dispo/pull/115) HEAD
[`e6d4a8123d89751144c962ce3dc074c292458bd6`](https://github.com/MORE-m/dispo/commit/e6d4a8123d89751144c962ce3dc074c292458bd6)
— **nicht** auf `main`.
Keine Feature-Implementierung, keine DB-Mutation (nur SELECT / Code-Read).

Historische Berichte werden **nicht** rückwirkend umgeschrieben; dieses Dokument
ist die korrigierte Neubewertung.

### Belegarten (getrennt)

| Beleg | Was er zeigt | Was er nicht zeigt |
|---|---|---|
| Read-only Dev-DB (`dispo_mat_core`) | aktive Listen 2026, Item-Anzahl, Stundenabdeckung je Inventar | keine Berechnung, kein Preview/Store |
| Synthetische Fixture (#115) | Berechnung Preview/Store/Reload + Negativfälle mit Fixture-Preisen | keine realen Workbook-€-Werte; kein Browser-Klick |
| Wizard-Props (Backend) | Auswahlmöglichkeiten (`catalog` / Regeln) | kein UI-Klickpfad |
| Importer-Tests (bestehend) | Parser/Import-Logik | nicht durch 501/75-Fixture „validiert“ |

## A. PR #114 (Kurz)

| Punkt | Stand |
|---|---|
| PR | [#114](https://github.com/MORE-m/dispo/pull/114) **MERGED** |
| Merge-Commit | `d7896aaf8e633f1e977a03e79af43290ac7c6ec3` |
| Lokaler Fast-Forward | `dispo-main` = `origin/main` @ Merge-SHA |
| Pre-Merge-CI (PR-HEAD) | Run [37044410636](https://github.com/MORE-m/dispo/actions/runs/37044410636) SUCCESS |
| Post-Merge-CI | Run [37050401271](https://github.com/MORE-m/dispo/actions/runs/37050401271) **SUCCESS** (`ci` / `mysql` / `e2e-spt008`) |

Inhalt PR #114: nur Docs zur kanonischen lokalen Dev-Umgebung (Checkout,
`dispo_mat_core`, Startweg/Port, Stilllegung Alt-`dispo`, Sicherungsgrenzen).

## B.1 Kalkulierbarkeit – acht Spot-Inventare

Definition der Acht: MORE-Workbook `Spotkalkulation_2026.xlsx` /
`MoreSpotkalkulationWorkbookParser` (nicht alle 14 Katalog-Inventare).

### Stunden-/Day-Group-Raster (präzisiert)

| Begriff | Bedeutung |
|---|---|
| Import-Raster MORE | Zeilen 13–36 = Stunden **0–23**, Spalten B/D/F = **mo_fr / sa / so** → max. **72** Zellen je Inventar; 8×72 = **576** |
| Ist Dev-DB (read-only) | **501** befüllte Zellen; **75** leere Basiszellen (bewusst nicht importiert, **PO-PRI-HOURS-1** fail-closed, keine Nullpreise) |
| Synthetische Fixture (#115) | **nachgebildetes** Muster 501/75 (72/42/57) — **keine** unabhängige Import-Validierung |
| Wizard / Validator | Stunden **0–23** wählbar (`TimeRangeValidator`); kein hart codierter Produktfilter „nur 10–23“ |
| „Voll“ in diesem Audit | **vollständiges Import-Raster 0–23×3** für das Inventar (72/72), **nicht** ein separater fachlicher 10–23-Buchungsfenster-Nachweis |
| Hinweis 10–23 | In Code/Validator **nicht** als Pflichtband durchgesetzt. Innerhalb 10–18 sind alle acht Inventare befüllt; bei Kombi+/ffn fehlen im Import **19–23** (und 0–4) |

Methoden: Kategorie `spots` → Inherit für `spot_classic`; **average** + **calendar** Released.
`fixed_price` Method-Key weiter `Planned`; N/N-Festpreis = Settlement-Pfad BL-P4-02d.

Isolierter Feature-Smoke (SQLite `:memory:`, Fixture, **ohne** `dispo_mat_core`/`dispo`):
[#115](https://github.com/MORE-m/dispo/pull/115) /
`EightSpotInventoryCalculabilitySmokeTest` @ `e6d4a8123d89751144c962ce3dc074c292458bd6`
(Draft-Branch, **nicht** auf `main`). Fixture-Ergebnisbeträge (300/330 € …) sind
ausschließlich synthetische Erwartungen. Import-Belege separat:
`MoreSpotkalkulationMatCoreImportTest`, `MoreSpotkalkulationWorkbookParserTest`.

| Inventar | Jahr | MAT / spot_classic | aktive Preisliste | Preis-Einschränkung (Import / Muster) | MAT-003 getrennt | Kalkulierbar (Import-Raster) | Beleg |
|---|---|---|---|---|---|---|---|
| Radio Hamburg | 2026 | average/calendar | ja, 72 | keine | Single erlaubt | **voll** (0–23×3) | Dev-DB SELECT; Fixture-Smoke |
| ROCK ANTENNE Hamburg | 2026 | average/calendar | ja, 72 | keine | Single erlaubt | **voll** | Dev-DB SELECT; Fixture-Smoke |
| 80er 90er OLDIE ANTENNE Hamburg | 2026 | average/calendar | ja, 72 | keine | Single erlaubt | **voll** | Dev-DB SELECT; Fixture-Smoke |
| CARAVAN.fm | 2026 | average/calendar | ja, 72 | Booking-Code `UC` | Single erlaubt | **voll** (Preis) | Dev-DB SELECT; Fixture-Smoke |
| MORE Hamburg-Kombi | 2026 | average/calendar | ja, 72 | keine | Single erlaubt | **voll** | Dev-DB SELECT; Fixture-Smoke |
| MORE Hamburg-Kombi+ | 2026 | average/calendar | ja, 42 | nur Std. **5–18**; fehlend 0–4 & 19–23 ×3 (=30) | **kein Single** | **eingeschränkt** | Dev-DB SELECT; Fixture + MAT-Negativ |
| ffn Hamburg Plus | 2026 | average/calendar | ja, 42 | nur Std. **5–18**; fehlend 0–4 & 19–23 ×3 (=30) | **kein Single** (auch kein Tandem in Matrix) | **eingeschränkt** | Dev-DB SELECT; Fixture-Smoke |
| RADIO BOLLERWAGEN DAB+ Hamburg | 2026 | average/calendar | ja, 57 | nur Std. **5–23**; fehlend 0–4 ×3 (=15) | **kein Single** (auch kein Tandem) | **eingeschränkt** | Dev-DB SELECT; Fixture-Smoke |

Kurzfazit: **5 voll** bzgl. Import-Raster 0–23, **3 eingeschränkt** durch fehlende Preiszellen (fail-closed);
MAT-003-Single-Ausschluss ist **orthogonal** zu Preis-Lücken. Fixture-Smoke deckt
Preview/Store/Reload + Negativfälle ab — **ohne** Browser-Klick und **ohne** Nachweis
realer Workbook-Preiswerte.

## B.2 Reale Änderungspfade nach Freigabe

Einzige Freigabeinvalidierung: **PO-AT13-CC-1** (Admin archiviert aktive CC bei
`at_disposition` → `draft`). Unverändert gelassen.

| Fall | Nach Submit/Freigabe | Pfad / Rollen | Betrifft | Frozen? | Kategorie |
|---|---|---|---|---|---|
| Preis | Dispo **nein**; Calc **ja, ohne Dispo-Sync** | kein Dispo-Update; Calc: Sales/Admin/GF | Calc live / Dispo-Snapshot unverändert | Dispo-Snapshot **unverändert** | Dispo **1**; Calc-Edit = **offene Lifecycle-Entscheidung** (kein nachgewiesener Snapshot-/Verarbeitungsfehler) |
| Rabatt | Dispo **nein** | Snapshot `order_discount_*` / Positionsrabatte | genehmigter Snapshot | ja | **1** |
| AE | Dispo **nein** | Snapshot AE-Felder | Snapshot | ja | **1** |
| Payfaktor | Dispo **nein** | nur Engine → Snapshot-Kopie | Snapshot | ja | **1** |
| Festpreis | Dispo **nein** | Snapshot `fixed_price_nn` | Snapshot | ja | **1** |
| Positionen | **nein** (nach Approve) | Create einmalig; Nachbesserung = neuer Draft nach Reject | Snapshot-Positionen | ja | **1** |
| Pflichtfelder / Dyn-Text | **nein** | `DispoOrderPolicy::update` nur `draft` | Draft-Dyn | n/a nach Submit | **1** |
| Dyn-Datei | **teilweise** | wie Material (AD/IP/…); **ohne** Invalidierung | operative Zusatzdaten | Freigabe-Snapshot ja | **2** |
| Kundenbestätigung | Upload nur Draft; Archiv Admin → Inv. nur AT13 | `DispoOrderUploadService` + `DispoOrderApprovalInvalidationService` | Status/Zyklus | Approval-Historie append-only | Upload **1**; Archiv-Inv. = AT13 |
| Material | **ja** in AD/IP/Material/SalesInquiry; **nein** ASA/disposed/completed | Sales/Disposition/Admin/GF | operative Uploads | Freigabe ja | **2** |
| Invoice-End | **ja** AD/IP/Material_* | Disposition/Admin/GF | operativ | Freigabe ja | **2** (künftige kaufm. Inv. = **4**) |

Anzeige/Weiterverarbeitung: Dispo-Show und Completion nutzen den **genehmigten
Snapshot** (`DispoOrderSnapshotMapper`); Calc-Edit propagiert nicht
(`CreateDispoOrderFromCalculationTest::test_dispo_order_is_unchanged_after_calculation_update`).

Guards/Tests u. a.: `DispoOrderCustomerConfirmationApprovalInvalidationTest`,
Concurrency, `DispoOrderMaterialUploadTest`, `DispoOrderGeneralCommentTest`,
Statuskante AD→Draft.

**Keine** neuen Editierfunktionen vorausgesetzt, nur um Invalidierung einzubauen.
Weitere AT-13-Auslöser für Preis etc. wären **Kat. 4** (zuerst Edit-Pfad), solange
Dispo-Kaufmännisch bereits Kat. 1 gesperrt ist.

## B.3 Hauptaudit H–K – korrigierte Bewertung

### Scope Gesamtprojekt vs. Spot-Slice

Nicht-Spot, CRM und Reports/PDF sind **V1-verbindlich** (`anforderungskatalog.md`),
phasenverschoben (CRM Phase 2, Nicht-Spot 5–6, REP Phase 10) – **nicht** „optional“.
Spot-Slice ist Implementierungsfokus, keine Scope-Streichung.

### `can_special_approve`, Force-Complete, PM-Freigabe, Disposition-Draft

| Thema | Soll | Ist | Urteil |
|---|---|---|---|
| `can_special_approve` | BL-P1-03 / Matrix: Vertrieb mit Flag | Feld existiert; **nirgends** in Policy gelesen; `approveSpecial` = Admin/Management | **Konflikt** |
| Force-Complete | Matrix: Admin **und** GF; `STA-006`: **Admin** | Policy nur Admin; Tests lehnen Management ab | Matrix vs. STA-006/Code; Code↔STA-006 **konsistent** |
| PM-Freigabe | Matrix „mit Extra-Recht“ | Slice: PM entscheidet nicht; Extra-Recht nur View | **Konflikt** Matrix vs. Slice |
| Disposition-Draft | Matrix „operativ“ | `canManageDispoOrders` = Admin/Sales/Management; Disposition **ohne** Draft-Create/Update | **Konflikt** Draft; operativ ab Statuskern **ja** |

### ADV-002

Medium-**Field-Set-Assignments** und Kategorie-Methoden-Inherit sind vorhanden
(alle 42 Medien `inherit`; 0 Medium-Method-Overrides in `dispo_mat_core`).
**SystemFieldSetting** / kanonische Systemfeld-Overrides fehlen → ADV-002 bleibt
**offen**; Assignments nicht als ADV-002-Erledigt zählen.

### Feldtypen und Datei-Pflicht

Implementiert: `period`, `boolean`, `short_text`, `long_text`, `select`,
`multi_select`, `file`.
Fehlend ggü. Katalog §19.2: Zahl/Geld/Prozent, Datum/Uhrzeit/Monat, URL/E-Mail/Telefon,
Referenztypen.
Datei-Pflicht: Slice `PO-BLP901C-1` bewusst **kein** `require_field` – Katalog
„dynamisch verpflichtend“ darüber hinaus **offen**.

### DSP-DCP-001 / PR #69

Technisch gemergt + automatisiert (Unit/Feature/MySQL/Vitest/E2E 8034).
**Manuelle Abnahme: Abnahmebeleg ungeklärt.** PR-Body zum Mergezeitpunkt „Noch offen“;
Repo-Doku führt weiter „offen“. Ein früherer Abschlussbericht nennt die Abnahme als
bestätigt – ohne hier vorliegenden konkreten Abnahmebeleg (Protokoll A–F / Timeline)
wird **weder** „offen als Gegenbeleg nur wegen Checkboxen“ **noch** „abgenommen“ gesetzt.
Ungecheckte Checkboxen allein sind kein Gegenbeleg.

### BL-P7-01/03 und MAT-003

| Paket | Code | Fachliche Vollabnahme |
|---|---|---|
| BL-P7-01 | Engine/Rabatt/AE/Festpreis-Settlement/Tests vorhanden | Backlog **offen**; AT-06/07-Paket offen |
| BL-P7-03 | Budget-Services + Wizard Spot Classic | Backlog **offen**; AT-25–27 Paket / Rest-UI offen |
| MAT-003 | Import-Blocklisten + DB ohne Single auf Kombi+/ffn/Bollerwagen | **Vollabnahme offen** (Hinweistexte bewusst nicht) |

## B.4 Nächste vier Schritte

### 1) PO-Klärung Rechte-Soll vs. Ist

- **Ziel:** Kanonische Quelle für Sonderfreigabe-Flag, Force-Complete-Rollen,
  PM-Freigabe und Disposition-Draft festlegen; Docs/Policy angleichen.
- **Scope:** nur Entscheidung + Docs/Policy-Nachzug; keine neuen Features.
- **Ausschlüsse:** AUTH-005 Rückzug; weitere AT-13-Auslöser.
- **Abhängigkeiten:** keine Code-Blocker.
- **Entscheidungen:** Option A Flag+Vertrieb laut Matrix; Option B Rollenmodell
  wie aktueller Slice/`STA-006` (Empfehlung: **B** für Force-Complete Admin-only
  und Sonderfreigabe Admin/GF; Flag deprecaten oder verdrahten in eigenem Slice;
  Disposition-Draft bewusst Sales-geführt belassen oder Matrix korrigieren).
- **DoD:** Entscheidungsnotiz; widersprüchliche Matrixzeilen markiert/ersetzt;
  Policy/Tests unverändert oder bewusst angepasst.
- **Tests:** bestehende Approval-/Completion-Matrix-Tests als Regression.

### 2) DSP-DCP-001 manuelle Abnahme A–F

- **Ziel:** Manuelle Abnahme dokumentieren oder PO-Verzicht beschließen.
- **Scope:** isolierte Umgebung Port 8034; Protokoll complete/partial/open/conflict/identical/legacy.
- **Ausschlüsse:** Abbinder, Rechnungsautomatik, Calc-Export.
- **Abhängigkeiten:** E2E/Seed vorhanden.
- **Entscheidungen:** Abnahme vs. formeller Verzicht.
- **DoD:** Eintrag in Fortschritt/Blocker-Log; PR-Nachzug oder neuer Docs-Eintrag.
- **Tests:** vorhandene automatisierte Suite bleibt grün.

### 3) Isolierter 8-Inventar-Kalkulierbarkeits-Smoke

- **Status:** umgesetzt auf offenem Draft-PR [#115](https://github.com/MORE-m/dispo/pull/115)
  HEAD `e6d4a8123d89751144c962ce3dc074c292458bd6`
  (`EightSpotInventoryCalculabilitySmokeTest` + Fixture-Concern); **kein** Produktcode;
  **noch nicht** auf `main`.
- **DoD lokal:** 13 Tests grün unter SQLite `:memory:` (Guard blockiert `dispo_mat_core`/`dispo`).
- **CI (Draft-HEAD):** Run [37110685244](https://github.com/MORE-m/dispo/actions/runs/37110685244)
  SUCCESS (`ci` / `mysql` / `e2e-spt008`) — **nicht** Post-Merge/`main`.
- **Readiness:** `docs/readiness/audit-8-inventar-kalkulierbarkeit-smoke-2026-10-02.md` →
  **READY** = Vorbedingungen erfüllt; getrennt von „Smoke getestet / auf Main integriert“.

### 4) Calc-Edit nach Dispo-Create: Freeze vs. bewusste Isolation

- **Ziel:** Offene **fachliche Lifecycle-Entscheidung** schließen – nicht als
  nachgewiesenen Snapshot-Bug führen.
- **Befund:** Calc bleibt editierbar; Dispo-Snapshot/Derived ändern sich nicht
  (Isolationstests vorhanden). Kein Verarbeitungsfehler nachgewiesen.
- **Optionen:** (Empfehlung) Isolation belassen + UI-Hinweis; **oder** Calc nach
  erstem Dispo-Create read-only.
- **DoD:** Entscheidungsnotiz; bei Freeze: Policy/UI/Tests.

## Bewusst nicht

- Keine weiteren PRs/Merges/Deploys aus diesem Auftrag.
- Keine Feature-Implementierung, keine Datenübernahme, keine Alt-Konsolidierung.
- PO-AT13-CC-1 unverändert.
- Historische Docs nicht umgeschrieben.

## Dateien dieses Berichts

- `docs/reviews/audit-nachzug-after-pr114/README.md` (dieses Dokument)
- `docs/readiness/audit-8-inventar-kalkulierbarkeit-smoke-2026-10-02.md`
