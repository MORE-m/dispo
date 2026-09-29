# UX-GATE-D Teilfreigabe: BL-P2-02a Kombinationstabellen-Admin (MAT-CORE)

Status: **Akzeptiert** (UX-GATE-D Teilfreigabe **PO-BLP202A-1**)  
Stand: 30. September 2026  
Basis: `origin/main` @ `363def9932e6473904cfe09a02fa4a8193804c68`  
IDs: `BL-P2-02a`, `MAT-001`, `MAT-002`, `MAT-003`, `PO-BLP202A-1`, `BLK-006`

## Freigegebener Scope

- Admin-UI Liste/Detail/Create/Edit/Deaktivieren/Reaktivieren für `inventory_medium_rules`
- Felder: Aktivstatus, Buchungskennzeichen, Einplanung durch, Hinweis, Sortierung sowie bestehende Längen-/Aufschlag-/Komponentenfelder
- Filter nach Inventar, Werbemittel, Oberkategorie, Einplanung, Kennzeichen
- Serverseitige Ableitung in Kalkulation → Freeze → Dispoauftrag (read-only)
- Optimistic Locking, Audit
- Testfactories klar als Fixtures; **kein** Erfinden der Produktiv-Matrix

## Explizit nicht freigegeben

- MAT-004 Massenimport / Matrixwerkzeuge
- Sender-Mitgliedschaften für Kombis (`PO-BL-P2-01-KOMBI`)
- Calc-Anzeige Buchungskennzeichen (siehe `PO-MAT-BOOKING-VIS-1` Option A)
- UX-GATE-C / SWF / Online Audio / Social / Events / Abbinder
- Hard Delete; Snapshot-Mutation historischer Vorgänge
- Vollständige Produktiv-Startdaten / Matrix-Seed

## Datenlage

Admin-/Verarbeitungslogik kann ohne vollständige Matrix umgesetzt und mit Fixtures geprüft werden.  
**Vollständige operative Abnahme** bleibt bis zur Matrix-Lieferung laut `docs/initialdaten.md` ausstehend.
