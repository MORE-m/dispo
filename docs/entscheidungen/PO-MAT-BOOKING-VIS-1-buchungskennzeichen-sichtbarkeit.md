# PO-MAT-BOOKING-VIS-1 – Sichtbarkeit Buchungskennzeichen in der Kalkulation

Status: **Vorgeschlagen**  
Stand: 29. September 2026  
Basis: `origin/main` @ `363def9932e6473904cfe09a02fa4a8193804c68`  
Bezug: `MAT-001`–`MAT-003`, `docs/anforderungskatalog.md` § 6.2, Readiness `docs/readiness/BL-P2-02-mat-kombinationstabelle-operativ-2026-09-29.md`  
UX-Gate: bei Option B zusätzlich UX-GATE-D Teilfreigabe nötig

## Ist-Vertrag (kanonisch)

Aus `docs/anforderungskatalog.md` § 6.2:

- Buchungskennzeichen: automatisch, Pflicht im **Dispoauftrag**, gesperrt, nicht preisrelevant, in der **Kalkulation noch nicht sichtbar**.
- `Einplanung durch`: automatisch, Pflicht, gesperrt; Dispo korrigiert nicht.
- Hinweistext: im Dispoauftrag sichtbar; admin-pflegbar und versioniert.

## Entscheidungsbedarf

Soll das Buchungskennzeichen (und ggf. `Einplanung durch`) in der **operativen Kalkulation** sichtbar werden, obwohl der kanonische Text derzeit „noch nicht sichtbar“ fordert?

Unabhängig davon bleibt: **niemals manuell änderbar** in Calc oder Dispo.

## Optionen

| Option | Inhalt | Folgen |
|--------|--------|--------|
| **A (Empfehlung)** | Calc: **keine** Anzeige. Server leitet aus Kombination ab, friert ein, zeigt im **Dispo** read-only. | Keine Calc-UI-Änderung; kanonische Doku bleibt gültig; UX-GATE-D nur für Kombi-Admin nötig. Schneller operativer Nutzen. |
| **B** | Calc: **read-only** Anzeige (Wizard und/oder Zusammenfassung) zusätzlich zu Dispo. | Doku-Anpassung § 6.2; UX-GATE-D Teilfreigabe Calc-UI; Freeze-Felder auch für Calc-Anzeige; Leerzustand Legacy. |
| **C** | Entscheidung vertagen; Slice nur Admin-Whitelist ohne Kennzeichen-Persistenz. | **Nicht empfohlen** – erfüllt Dispo-Pflichtfelder nicht. |

## Empfehlung

**Option A.** Operativer Kernnutzen (Dispo-Kennzeichen + Freeze + Whitelist) entsteht ohne Konflikt mit der kanonischen Calc-Sichtbarkeitsregel. Option B als optionaler Folgeslice nach expliziter Freigabe.

## Was diese Entscheidung nicht freigibt

- Kombinationstabellen-Admin (eigene UX-GATE-D Teilfreigabe `BL-P2-02a`)
- Matrix-Inhalte (Lieferdaten)
- Manuelle Überschreibung
- SWF/OA/Social/Events-Engines
- Abbinder/SPT-013

## Nach PO-Beschluss

1. Status hier auf **Akzeptiert** setzen und gewählte Option fixieren.
2. Bei B: Anforderungskatalog § 6.2 und UX-GATE-D Teilfreigabe nachziehen.
3. Erst dann Feature-Code für den gewählten Sichtbarkeitspfad.
