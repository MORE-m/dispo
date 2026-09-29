# UX-GATE-D Anfrage: BL-P2-02a Kombinationstabellen-Admin (MAT-CORE)

Status: **Vorgeschlagen / nicht freigegeben**  
Stand: 29. September 2026  
Basis: `origin/main` @ `363def9932e6473904cfe09a02fa4a8193804c68`  
IDs: `BL-P2-02a`, `MAT-001`, `MAT-002`, `MAT-003`, `BLK-006`  
Readiness: `docs/readiness/BL-P2-02-mat-kombinationstabelle-operativ-2026-09-29.md`

## Beantragter Scope (nur bei PO-Freigabe umsetzen)

- Admin-UI Liste/Detail/Create/Edit für `inventory_medium_rules`
- Felder: Aktivstatus, Buchungskennzeichen, Einplanung durch, Hinweis, Sortierung (sowie bestehende Längen-/Aufschlag-/Komponentenfelder soweit schon vorhanden)
- Filter nach Inventar, Werbemittel, Oberkategorie, Einplanung, Kennzeichen
- Serverseitige Ableitung in Kalkulation → Freeze → Dispoauftrag (read-only)
- Optimistic Locking, Audit, Impact-/Leer-/Fehlerzustände analog Katalog/Inventar
- Testfactories; **kein** Erfinden der Produktiv-Matrix

## Ausdrücklich nicht beantragt

- MAT-004 Massenimport / Matrixwerkzeuge
- Sender-Mitgliedschaften für Kombis (`PO-BL-P2-01-KOMBI`)
- Calc-Anzeige Buchungskennzeichen (eigene Entscheidung `PO-MAT-BOOKING-VIS-1`)
- UX-GATE-C / SWF / Online Audio / Social / Events / Abbinder
- Hard Delete; Snapshot-Mutation historischer Vorgänge

## Abhängigkeit Daten

Vollständige Matrix-Lieferung laut `docs/initialdaten.md` ist für Abnahme „vollständige Startdaten“ nötig. Ohne Lieferung darf nur Admin-Gerüst + Testfactories gebaut werden – nicht als „Startdaten vollständig“ abgenommen werden.

## Freigabezeile (PO)

- [ ] Freigegeben als UX-GATE-D Teilfreigabe **PO-BLP202A-1** (Arbeitstitel)
- [ ] Abgelehnt / vertagt – Grund: _______________
