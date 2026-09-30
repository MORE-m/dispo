# PO-MAT-BOOKING-VIS-1 – Sichtbarkeit Buchungskennzeichen in der Kalkulation

Status: **Akzeptiert – Option A**  
Stand: 30. September 2026  
Basis: `origin/main` @ `363def9932e6473904cfe09a02fa4a8193804c68`  
Bezug: `MAT-001`–`MAT-003`, `docs/anforderungskatalog.md` § 6.2, `PO-BLP202A-1`

## Entscheidung

**Option A:** Das Buchungskennzeichen (und Einplanung/Hinweis) wird serverseitig aus der Kombination abgeleitet, in Calc-/Dispo-Positionen eingefroren und **nur im Dispoauftrag read-only** angezeigt.  
In der **Kalkulation keine Anzeige**. Niemals manuell in Calc oder Dispo änderbar.

## Folgen

- Anforderungskatalog § 6.2 („in der Kalkulation noch nicht sichtbar“) bleibt gültig.
- Dispo-Positionsanzeige zeigt Kennzeichen / Einplanung / Hinweis; Legacy ohne Freeze: „— (Legacy)“ ohne Backfill.
- Option B (Calc-Anzeige) bleibt späterer optionaler Slice mit eigener UX-GATE-D-Teilfreigabe.

## Nicht freigegeben

- Manuelle Überschreibung
- Calc-UI-Anzeige
- Matrix-Inhalte
- SWF/OA/Social/Events / Abbinder
