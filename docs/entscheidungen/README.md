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
| [PO-AUTH-RIGHTS-1](PO-AUTH-RIGHTS-1-rechtekonflikte-entscheidungsvorlage.md) | Rechtekonflikte Sonderfreigabe / PM / Draft / Force-Complete | **Akzeptiert** |
| [PO-APPROVAL-NOTIFY-1](PO-APPROVAL-NOTIFY-1-freigabe-entscheidungsmail.md) | Freigabe erteilt/abgelehnt per Outbox/SMTP | **Akzeptiert** |
| [PO-NOT002-ADMIN-1](PO-NOT002-ADMIN-1-admin-outbox-sicht.md) | Admin-Outbox-Sicht lesend | **Akzeptiert** (`main` PR #122) |
| [PO-BLP403K-1](PO-BLP403K-1-calendar-tandem-tridem.md) | Calendar × Tandem/Tridem Standardangebote | **Akzeptiert** (`main` PR #128) |
| [PO-BLP501A-1](PO-BLP501A-1-swf-trailer-average.md) | Trailer × Average (A1 Gate-Teilfreigabe + B1 Preisbasis) | **Akzeptiert** (`main` PR #129; lokal eingerichtet; Deploy offen) |
| [PO-BLP501A-1-datenlieferung](PO-BLP501A-1-datenlieferung.md) | Operative Trailer-Länge/Aufschlag + lokale Einrichtung | **Lokal umgesetzt** (`dispo_mat_core`; Deploy offen) |
| [PO-BLP502-1](PO-BLP502-1-produktion-sonstiges.md) | Produktion/Sonstiges Erst-Slice (`BL-P5-02a`) | **Akzeptiert** (A1…H1a; Feature-Branch) |
| [PO-BLP502-1-auflösung](PO-BLP502-1-aufloesungsvertrag.md) | Produktionspreis Jahr/Pin/Rebind | **Akzeptiert** (Ableitung PO-PRI-YEAR-1 + E1/H1a) |
