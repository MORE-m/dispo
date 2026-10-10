# PO-BLP203-1 – CRM Salesforce / Meridian (BL-P2-03a)

Status: **Akzeptiert** (A1, B1, C1, D + **D1**, E1, F1, G1, H1)
Stand: 10. Oktober 2026
Code auf `main`: `65da41121206b0b66327831ff9586996f2c3c331`
(Merge PR [#133](https://github.com/MORE-m/dispo/pull/133) + D1 PR [#134](https://github.com/MORE-m/dispo/pull/134);
Post-Merge-CI [`38063164855`](https://github.com/MORE-m/dispo/actions/runs/38063164855) SUCCESS).
Lokale Daten A/B/C in `dispo_mat_core` erledigt (siehe Datenlieferung); **kein** Deploy.
IDs: `CRM-001`–`CRM-003` (Slice), `CRM-004` (Folgeslice), `DSP-*`, `APR-004` (Abgrenzung E1),
`VER-*`, `AUD-*`, `ADM-001`, `AUTH-001`–`AUTH-007`, UX-GATE-D
Slice-Kennung: **`BL-P2-03a`** (Teilscope von `BL-P2-03`, nicht vollständig)
Readiness: [`docs/readiness/BL-P2-03-crm-salesforce-meridian-2026-10-09.md`](../readiness/BL-P2-03-crm-salesforce-meridian-2026-10-09.md)
Daten: [`PO-BLP203-1-datenlieferung`](PO-BLP203-1-datenlieferung.md) ·
[`BL-P2-03a-crm-salesforce-meridian-data-2026-10-10`](../readiness/BL-P2-03a-crm-salesforce-meridian-data-2026-10-10.md)
Review Feature: [`docs/reviews/crm-salesforce-meridian-readiness/`](../reviews/crm-salesforce-meridian-readiness/)
Review Daten: [`docs/reviews/crm-salesforce-meridian-data-readiness/`](../reviews/crm-salesforce-meridian-data-readiness/)

> Bestätigte Fachregeln R1–R7 bleiben verbindlich und werden nicht erneut zur Wahl gestellt.
> Mit dem Implementierungsauftrag sind A1/B1/C1/E1/F1/G1/H1 sowie das CSV-Format (D) akzeptiert.
> **D1** ergänzt das Typmapping um die gelieferten Salesforce-Typen Gesellschafter/Sonstiges.

## Akzeptierte Entscheidungen

| ID | Entscheidung |
|---|---|
| **A1** | Vollständiger vertikaler Slice: Import → Stammdaten → vorläufige Accounts → Zuordnung → Calc/Dispo → Meridian-Nachtrag. Kontakte/`CRM-004` und CRM-Vollausbau außerhalb |
| **B1** | UX-GATE-D **Teilfreigabe nur** für die erforderlichen CRM-Oberflächen dieses Slices |
| **C1** | Import / manuelle Zuordnung / Konflikte: Admin + Management. Vorläufige Accounts: Sales + Admin + Management. Disposition: lesend. Bestehende Calc-/Dispo-Rechte bleiben zusätzlich maßgeblich. Produktmanagement: keine neuen CRM-Rechte |
| **D** | Erster Slice: manueller **CSV**-Import (UTF-8, Semikolon). Spalten: Accountname, Meridian-ID, Account-ID, Rechnungs-E-Mail, Account-Datensatztyp. Kein XLSX, keine API, kein E-Mail-Ingest. Mehrere unterschiedliche Domains in einer Zelle → Prüfliste (nicht erste Adresse wählen) |
| **D1** | Explizites Typmapping der gelieferten Strings: `Account KUNDE` / `Account GESELLSCHAFTER` / `Account SONSTIGE` → intern **Kunde** (`customer`); `Account AGENTUR` → **Agentur** (`agency`). Originaler Salesforce-Datensatztyp wird je Stammdatenversion gespeichert und in der Account-Detailansicht angezeigt (vorläufige/historische Versionen dürfen `NULL` haben). Wechsel Kunde↔Gesellschafter↔Sonstiges bei gleicher SF-ID = neue Version, interner Typ bleibt Kunde. Wechsel intern Kunde↔Agentur = Typkonflikt. Domain+Typ-Matching nutzt den internen Typ (Kundengruppe gemeinsam; Eindeutigkeit über die ganze Gruppe). Andere unbekannte Typen bleiben blockierende Fehler. **Keine** Filterung der Exportdatei; der frühere Filtervorschlag (Typen entfernen) ist **verworfen**. |
| **E1** | Reine Salesforce-Verknüpfung bzw. Meridian-Ergänzung invalidiert **keine** Freigabe und ändert keinen Status |
| **F1** | Rechnungsempfänger explizit `customer` \| `agency`; Agentur nur bei gesetzter Agentur. Bei bestehenden Freitext-Aufträgen keine historische Zuordnung erfinden |
| **G1** | Kontakte / `CRM-004` bleiben Folgeslice |
| **H1** | Salesforce-„Projektmanagement“ ist keine Tool-Rolle und gibt Produktmanagement keine Rechte |

## Bestätigte Fachregeln (unverändert verbindlich)

| ID | Regel |
|---|---|
| **R1** | Salesforce führende Quelle; Meridian-Abgleich außerhalb; danach Meridian-Nummer am SF-Account |
| **R2** | Täglicher Export → Tool-Import inkl. Accounts ohne Meridian; manueller Dateiimport |
| **R3** | Bekannte Spalten; Numbers nicht als Parser-Format |
| **R4** | SF-Account-ID = Identität; Firmierungsänderung → Version; Re-Import unverändert → keine Version; Aufträge behalten Snapshots |
| **R5** | „Meridian-Nummer folgt“ in jedem Status; Nachtrag ohne Mutation eingefrorener Auftragsdaten; Audit; Abweichung = Konflikt |
| **R6** | Vorläufige Kunden/Agenturen nutzbar; Firmierung, Typ, Domain/E-Mail für Erstabgleich; Adresse nicht nötig |
| **R7** | Erstzuordnung nur Domain + Typ; Auto nur bei genau einem Treffer; nach Link nur SF-ID |

## Salesforce-ID (Implementierungsvertrag zu D/R4)

- IDs und Meridian-Nummern als **Text**; führende Nullen erhalten.
- 15-stellige IDs sind **case-sensitive**. Kein pauschales Lowercasing / case-insensitiver Vergleich.
- Kanonischer Schlüssel führt gültige 15- und 18-stellige Formen derselben Identität zusammen (Checksum-Verfahren). Originalwert bleibt erhalten.
- Ungültige oder widersprüchliche IDs erscheinen im Importbericht.

## Datenabdeckung CSV

Die gelieferte CSV enthält **keine Adressspalten**. Es werden keine Adressen erfunden.
Nicht gelieferte Felder leeren bestehende Adressattribute nicht (falls später ergänzt).

## Nicht-Ziele

- `CRM-004` Kontakte, Salesforce-/Meridian-API, E-Mail-Ingest, XLSX/Numbers
- Pauschale Erledigung von `BL-P2-03`
- Deploy / operative DB-Pflege

## Folge

`BL-P2-03` und `CRM-001`–`CRM-003` nach Abnahme dieses Slices **teilweise**;
`CRM-004` offen. Operative Datenabnahme und Deploy getrennt.
