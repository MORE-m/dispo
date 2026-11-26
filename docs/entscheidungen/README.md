# Architecture Decision Records

Technische Entscheidungen werden als ADR dokumentiert. Nummern werden fortlaufend
vergeben und nicht wiederverwendet.

Statuswerte:

- `Vorgeschlagen`
- `Akzeptiert`
- `Ersetzt durch ADR-…`
- `Verworfen`

Eine akzeptierte Entscheidung wird nicht rückwirkend umgeschrieben. Bei einer
Änderung entsteht ein neues ADR, das die alte Entscheidung ersetzt.

Offene Punkte desselben ADR dürfen mit Änderungsprotokoll konkretisiert werden,
ohne die Entscheidung zu ersetzen.

| ADR | Thema | Status |
|---|---|---|
| [ADR-001](ADR-001-systemarchitektur.md) | Modularer Laravel-Monolith | Akzeptiert |
| [ADR-002](ADR-002-technologie-stack.md) | Technologie-Stack | Akzeptiert |
| [ADR-003](ADR-003-speedit-betrieb-und-deployment.md) | Speedit-Betrieb und Deployment | Akzeptiert |

## Fach-/Gate-Entscheidungen (PO)

| Dokument | Thema | Status |
|---|---|---|
| [AT-13 CC-Invalidierung](AT-13-freigabeinvalidierung-kundenbestaetigung.md) | Freigabeinvalidierung Kundenbestätigung (PO-AT13-CC-1) | Akzeptiert |
| [PO-BLP202A-1](PO-BLP202A-1-kombinationstabelle-admin-gate-anfrage.md) | UX-GATE-D Teilfreigabe Kombinationstabellen-Admin | Akzeptiert |
| [PO-MAT-BOOKING-VIS-1](PO-MAT-BOOKING-VIS-1-buchungskennzeichen-sichtbarkeit.md) | Buchungskennzeichen nur Dispo (Option A) | Akzeptiert |
| [PO-MAT-CORE-MATRIX-1](PO-MAT-CORE-MATRIX-1-produktivmatrix-importvertrag.md) | Produktivmatrix Importvertrag (BL-P2-02b) | Akzeptiert |
| [PO-MAT-CORE-CATALOG-1](PO-MAT-CORE-CATALOG-1-initialkatalog.md) | Initialkatalog 14 Inventare / 42 Werbemittel | Akzeptiert |
| [EPIC-03-B](EPIC-03-B-master-data-transfer.md) | Selektiver Stammdaten-/Preislisten-Transfer | Historisch Schema `1.0.0` auf `main` (PR #263); RH-Pilot abgenommen |
| [EPIC-03-C/D](../readiness/EPIC-03-CD-staging-pilot-abschluss-2026-11-23.md) | Radio-Hamburg Staging-Pilot bis Dispo-Entwurf | **PASS** bis Entwurf; Status nach Entwurf nicht abgenommen |
| [EPIC-03-F Readiness](../readiness/EPIC-03-F-full-master-data-readiness-2026-11-23.md) | Vollständiger Fachdatenbestand nach Staging | Fachumfang genehmigt; Transfer **STOP** |
| [EPIC-03-F Vertrag 2.0.0](EPIC-03-F-master-data-transfer-2-0-0.md) | Technischer Importvertrag Gesamtbestand | Technisch abgenommen; Export/Dry-Run/Apply-Code (6a/6b) auf Feature-Pfad; operativer Staging-Apply weiter **STOP** bis PO-GO |
| [EPIC-03-F Slice 6 CLI](EPIC-03-F-slice6-operational-cli-requirements.md) | Operative CLI 6a/6b/7 | **6a+6b implementiert**; Slice 7 offen |
| [PO-AUTH-RIGHTS-1](PO-AUTH-RIGHTS-1-rechtekonflikte-entscheidungsvorlage.md) | Rechtekonflikte Sonderfreigabe / PM / Draft / Force-Complete | **Akzeptiert** |
| [PO-APPROVAL-NOTIFY-1](PO-APPROVAL-NOTIFY-1-freigabe-entscheidungsmail.md) | Freigabe erteilt/abgelehnt per Outbox/SMTP | **Akzeptiert** |
| [PO-NOT002-ADMIN-1](PO-NOT002-ADMIN-1-admin-outbox-sicht.md) | Admin-Outbox-Sicht lesend | **Akzeptiert** (`main` PR #122) |
| [PO-BLP403K-1](PO-BLP403K-1-calendar-tandem-tridem.md) | Calendar × Tandem/Tridem Standardangebote | **Akzeptiert** (`main` PR #128) |
| [PO-BLP501A-1](PO-BLP501A-1-swf-trailer-average.md) | Trailer × Average (A1 Gate-Teilfreigabe + B1 Preisbasis) | **Akzeptiert** (`main` PR #129; lokal eingerichtet; Deploy offen) |
| [PO-ALLONGE-1](PO-ALLONGE-1-kombinationspflege.md) | Allonge × Durchschnitt, Bestätigung per Detail-Speichern | **Akzeptiert** (dieser Branch; operativ nicht eingerichtet; nicht gemergt) |
| [PO-BLP501A-1-datenlieferung](PO-BLP501A-1-datenlieferung.md) | Operative Trailer-Länge/Aufschlag + lokale Einrichtung | **Lokal umgesetzt** (`dispo_mat_core`; Deploy offen) |
| [PO-BLP502-1](PO-BLP502-1-produktion-sonstiges.md) | Produktion/Sonstiges Erst-Slice (`BL-P5-02a`) | **Akzeptiert** (A1…H1a; `main` PR #131) |
| [PO-BLP502-1-auflösung](PO-BLP502-1-aufloesungsvertrag.md) | Produktionspreis Jahr/Pin/Rebind | **Akzeptiert** (Ableitung PO-PRI-YEAR-1 + E1/H1a) |
| [PO-BLP502-1-datenlieferung](PO-BLP502-1-datenlieferung.md) | Operative Spotproduktionspreise je Inventar | **Lokal umgesetzt** (`dispo_mat_core`; Deploy offen) |
| [PO-BLP203-1](PO-BLP203-1-crm-salesforce-meridian.md) | CRM Salesforce/Meridian Erst-Slice (`BL-P2-03a`) | **Akzeptiert** (A1…H1 + **D1**; `main` PR #133/#134) |
| [PO-BLP203-1-datenlieferung](PO-BLP203-1-datenlieferung.md) | Lokale CRM-Einrichtung (Export/Migration/Import) | **A/B/C lokal erledigt** (`dispo_mat_core`; Deploy offen) |
| [GO-LIVE-MASTERPLAN](../roadmap/GO-LIVE-MASTERPLAN.md) | Zentraler Go-live-Fahrplan + PO-01…PO-07 (PO-MASTER-AUDIT) | **Verbindlich ab 26.11.2026**; führend für Readiness/Implementierung/Review/Deploy |
| [EPIC-03-F Tabellenklassifikation](../readiness/EPIC-03-F-table-classification-2-0-0.md) | Alle DB-Tabellen: Klasse, Transferfähigkeit, Schutz, Arbeitspaket | Slice 6b; CRM = PO-01 separat |
| [PO-GOLIVE-CUT-1-DEC](PO-GOLIVE-CUT-1-DEC-operative-v1.md) | Operative V1 Medien-/Stufenpriorisierung | **Historisch**; widersprechende Go-live-Abgrenzungen abgelöst durch GO-LIVE-MASTERPLAN |
| [PO-OA-BUDGET-V2](PO-OA-BUDGET-V2.md) | OA-/Free-/Mixed-Budget vollständig V2; V1 Spot-only | **Akzeptiert / verbindlich** (15.11.2026; Docs-only; gilt bis neue PO-Entscheidung) |
| [PO-V1-CLOSURE-1](PO-V1-CLOSURE-1-backlog-status.md) | V1-Backlogstatus gegen released Capabilities | **Historisch** (15.11.2026); Scope-Abgrenzungen zu Social/Events/Contest abgelöst durch GO-LIVE-MASTERPLAN |
| [PO-CALC-DISPO-UX-1](PO-CALC-DISPO-UX-1.md) | Calc→Dispo UX: operative Felder + Wizard-Werbeelemente | **Akzeptiert** (17.11.2026; PR1 Wizard-UX) |
| [SEC-NPM-AUDIT-PICOMATCH](SEC-NPM-AUDIT-PICOMATCH-GHSA-fc5q-39xc-vjpf.md) | Befristete Ausnahme GHSA-fc5q-39xc-vjpf (picomatch ReDoS) | **Draft zur PO-/Security-Freigabe** (Ablauf 2026-12-18) |
| [PO-SPOT-SURCHARGE-1 Auftrag](../readiness/PO-SPOT-SURCHARGE-1-auftrag-2026-10-18.md) | Erst/Letzt auf `main` (PR #160) | umgesetzt |
| [PO-SPOT-SINGLE-1 Auftrag](../readiness/PO-SPOT-SINGLE-1-auftrag-2026-10-18.md) | Single-Spot +50 % / MAT-003 | auf `main` (PR #161) |
