# Dokumentationsindex

Dieses Verzeichnis ist die fachliche und technische Wissensbasis des Projekts.
Die Dokumente werden gemeinsam mit dem Code versioniert.

## Quellenhierarchie

| Rang | Quelle | Zweck |
|---:|---|---|
| 1 | `anforderungskatalog.md` | Verbindlicher Umfang und fachliche Anforderungen von V1 |
| 2 | Fachmodelle in diesem Verzeichnis | Präzisierung, Formeln, Zustände und Datenbeziehungen |
| 3 | Akzeptierte ADRs | Verbindliche technische Entscheidungen |
| 4 | Automatisierte Tests | Ausführbares Verhalten der aktuellen Implementierung |

Ein untergeordnetes Dokument darf einer höherrangigen Quelle nicht widersprechen.

## Dokumente

| Datei | Inhalt |
|---|---|
| [`anforderungskatalog.md`](anforderungskatalog.md) | Konsolidiertes Lastenheft mit stabilen Anforderungs-IDs (inkl. `BUD-*`, `STD-*`, Rolle Produktmanagement) |
| [`fachmodell.md`](fachmodell.md) | Begriffe, Aggregate und fachliche Invarianten |
| [`berechnungslogik.md`](berechnungslogik.md) | Formeln, Reihenfolgen und Rundung |
| [`workflows-und-berechtigungen.md`](workflows-und-berechtigungen.md) | Rollen, Freigaben, Status und Sperren |
| [`dynamisches-feldsystem.md`](dynamisches-feldsystem.md) | Felddefinitionen, Regeln, Versionen und Snapshots |
| [`datenmodell.md`](datenmodell.md) | Konzeptuelles Datenmodell und technische Leitplanken |
| [`ui-ux-konzept.md`](ui-ux-konzept.md) | Navigation, zentrale Ansichten und Interaktionsprinzipien |
| [`ux-ui-gate.md`](ux-ui-gate.md) | Gate-/PO-Freigaben (A/B freigegeben; C blockiert; D teilweise freigegeben) |
| [`test-und-abnahmekatalog.md`](test-und-abnahmekatalog.md) | Fachliche Mindestabnahme und Teststrategie |
| [`initialdaten.md`](initialdaten.md) | Startkataloge und noch bereitzustellende Daten |
| [`umsetzungsplan.md`](umsetzungsplan.md) | Phasen-/Reihenfolgeübersicht (kein konkurrierender Paketstatus) |
| [`backlog-v1.md`](backlog-v1.md) | Paket-/Slice-Status (`offen` / `erledigt` / `teilweise` / `blockiert`) |
| [`fortschritt.md`](fortschritt.md) | Aktueller Arbeits-/Umsetzungsstand |
| [`entwicklung-lokal.md`](entwicklung-lokal.md) | Lokales Setup, Prüfungen, Produktionshinweise |
| [`entwicklung-lokal-umgebung.md`](entwicklung-lokal-umgebung.md) | Kanonische lokale Workspace-Umgebung, Stilllegungen, Sicherungen |
| [`blocker-und-entscheidungslog.md`](blocker-und-entscheidungslog.md) | Blocker und technische Detailentscheidungen |
| [`entscheidungen/`](entscheidungen/) | Architecture Decision Records (ADR) |
| [`reviews/calendar-festpreis-readiness/`](reviews/calendar-festpreis-readiness/) | Bericht Calendar × Festpreis (PO-BLP403I-1 A1, auf `main` PR #126) |
| [`readiness/BL-P4-03i-calendar-festpreis-2026-10-06.md`](readiness/BL-P4-03i-calendar-festpreis-2026-10-06.md) | Readiness Calendar × Festpreis (READY / `main`) |
| [`entscheidungen/PO-BLP403I-1-calendar-festpreis.md`](entscheidungen/PO-BLP403I-1-calendar-festpreis.md) | PO Calendar × Festpreis (Akzeptiert A1) |
| [`reviews/calendar-festpreis-hauptspot-allonge-readiness/`](reviews/calendar-festpreis-hauptspot-allonge-readiness/) | Bericht Calendar × Festpreis × Allonge (PO-BLP403J-1 A1, auf `main` PR #127) |
| [`readiness/BL-P4-03j-calendar-festpreis-hauptspot-allonge-2026-10-06.md`](readiness/BL-P4-03j-calendar-festpreis-hauptspot-allonge-2026-10-06.md) | Readiness Calendar × Festpreis × Allonge (READY / `main`) |
| [`entscheidungen/PO-BLP403J-1-calendar-festpreis-hauptspot-allonge.md`](entscheidungen/PO-BLP403J-1-calendar-festpreis-hauptspot-allonge.md) | PO Calendar × Festpreis × Allonge (Akzeptiert A1, PR #127) |
| [`reviews/calendar-tandem-tridem-readiness/`](reviews/calendar-tandem-tridem-readiness/) | Bericht Calendar × Tandem/Tridem (PO-BLP403K-1 A1, auf `main` PR #128) |
| [`readiness/BL-P4-03k-calendar-tandem-tridem-2026-10-07.md`](readiness/BL-P4-03k-calendar-tandem-tridem-2026-10-07.md) | Readiness Calendar × Tandem/Tridem (READY / `main`) |
| [`entscheidungen/PO-BLP403K-1-calendar-tandem-tridem.md`](entscheidungen/PO-BLP403K-1-calendar-tandem-tridem.md) | PO Calendar × Tandem/Tridem (Akzeptiert A1, PR #128) |
| [`reviews/swf-trailer-average-readiness/`](reviews/swf-trailer-average-readiness/) | Bericht Trailer × Average (PO-BLP501A-1 A1 + B1; auf `main` PR #129) |
| [`readiness/BL-P5-01a-swf-trailer-average-2026-10-07.md`](readiness/BL-P5-01a-swf-trailer-average-2026-10-07.md) | Feature-Readiness Trailer × Average (READY mit Daten-Vorbedingungen) |
| [`reviews/swf-trailer-average-data-readiness/`](reviews/swf-trailer-average-data-readiness/) | Bericht operative Daten-Readiness nach PR #129 |
| [`readiness/BL-P5-01a-swf-trailer-average-data-2026-10-07.md`](readiness/BL-P5-01a-swf-trailer-average-data-2026-10-07.md) | Operative Daten-Readiness / lokale Einrichtung `dispo_mat_core` |
| [`entscheidungen/PO-BLP501A-1-swf-trailer-average.md`](entscheidungen/PO-BLP501A-1-swf-trailer-average.md) | PO Trailer × Average (**A1 + B1** akzeptiert; `main` PR #129) |
| [`entscheidungen/PO-BLP501A-1-datenlieferung.md`](entscheidungen/PO-BLP501A-1-datenlieferung.md) | PO-Datenlieferung Länge/Aufschlag (20 s / 30 % lokal; Deploy/andere Umgebungen offen) |
| [`reviews/production-readiness/`](reviews/production-readiness/) | Bericht Produktion `BL-P5-02a` (PO-BLP502-1 akzeptiert; auf `main` PR #131) |
| [`readiness/BL-P5-02-produktion-2026-10-08.md`](readiness/BL-P5-02-produktion-2026-10-08.md) | Feature-Readiness Produktion Teilscope READY (`BL-P5-02`/`AT-11` teilweise) |
| [`reviews/spot-production-data-readiness/`](reviews/spot-production-data-readiness/) | Bericht lokale Einrichtung + Browser-Abnahme Spotproduktion |
| [`readiness/BL-P5-02a-spot-production-data-2026-10-09.md`](readiness/BL-P5-02a-spot-production-data-2026-10-09.md) | Operative Daten-Readiness Spotproduktion (lokal eingerichtet; Deploy offen) |
| [`entscheidungen/PO-BLP502-1-produktion-sonstiges.md`](entscheidungen/PO-BLP502-1-produktion-sonstiges.md) | PO Produktion Erst-Slice Spotproduktion@Spot Classic (**Akzeptiert**; `main` PR #131) |
| [`entscheidungen/PO-BLP502-1-aufloesungsvertrag.md`](entscheidungen/PO-BLP502-1-aufloesungsvertrag.md) | Produktionspreis Jahr/Pin/Rebind |
| [`entscheidungen/PO-BLP502-1-datenlieferung.md`](entscheidungen/PO-BLP502-1-datenlieferung.md) | PO-Datenlieferung Spotproduktionspreise (lokal 600/400 €; Deploy offen) |
| [`reviews/crm-salesforce-meridian-readiness/`](reviews/crm-salesforce-meridian-readiness/) | Bericht CRM Salesforce/Meridian Readiness (`BL-P2-03a`; PO-BLP203-1 vorgeschlagen) |
| [`readiness/BL-P2-03-crm-salesforce-meridian-2026-10-09.md`](readiness/BL-P2-03-crm-salesforce-meridian-2026-10-09.md) | Feature-Readiness CRM Import/Zuordnung/Meridian-Nachtrag (READY MIT VORBEDINGUNGEN) |
| [`entscheidungen/PO-BLP203-1-crm-salesforce-meridian.md`](entscheidungen/PO-BLP203-1-crm-salesforce-meridian.md) | PO CRM Salesforce/Meridian Erst-Slice (**Vorgeschlagen**; R1–R7 bestätigt) |
| [`reviews/audit-nachzug-after-pr114/`](reviews/audit-nachzug-after-pr114/) | Audit-Nachzug nach PR #114 (Kalkulierbarkeit, Invalidierung, H–K) |
| [`readiness/audit-8-inventar-kalkulierbarkeit-smoke-2026-10-02.md`](readiness/audit-8-inventar-kalkulierbarkeit-smoke-2026-10-02.md) | Readiness empfohlener 8-Inventar-Smoke |
| [`readiness/BL-P9-02d-approval-mails-outbox-2026-10-04.md`](readiness/BL-P9-02d-approval-mails-outbox-2026-10-04.md) | Readiness Freigabe-Mails Outbox/SMTP (PO-APPROVAL-NOTIFY-1) |
| [`entscheidungen/PO-AUTH-RIGHTS-1-rechtekonflikte-entscheidungsvorlage.md`](entscheidungen/PO-AUTH-RIGHTS-1-rechtekonflikte-entscheidungsvorlage.md) | PO-Entscheidung Rechtekonflikte A–D |

## Pflegeprozess

1. Fachliche Änderung mit betroffenen Anforderungs-IDs beschreiben.
2. `anforderungskatalog.md` oder das präzisierende Fachdokument aktualisieren.
3. Bei Architekturfolgen ein ADR anlegen oder ergänzen.
4. Tests aktualisieren.
5. Erst danach die Implementierung als abgeschlossen markieren.

## Statusangaben

- **Verbindlich:** für V1 beschlossen.
- **Vorgeschlagen:** noch zu bestätigende technische Entscheidung.
- **Offen:** konkrete Initialdaten oder Detailausprägung fehlen; keine offene Grundsatzentscheidung.
- **V2:** ausdrücklich nicht Teil des V1-Umfangs.

