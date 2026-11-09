# PO-CALC-TKP-1D4d-OA004-DEC – Adserver-TKP als interne Kosten

Stand: 9. November 2026  
Basis: `main` `c511834b898b1e4accf06dc8cfdbe3642b8d01b4`  
Vorgänger: `docs/readiness/PO-CALC-TKP-1D4d-PRE-1-po-entscheidung-2026-11-09.md`,
`docs/readiness/PO-CALC-TKP-1D4d-go-no-go-2026-11-09.md`

Docs-only. Keine Runtime-, Registry-, DB-, Preislisten- oder Deploy-Änderung.

---

## Fachentscheidung

Die bisher dokumentierten **15 EUR Adserver-TKP** für Spotify / Deezer / YouTube
bzw. das heutige Midroll-Bundle (`midroll_music_environment`) sind:

- **interne Einkaufskosten / harte Kosten** einer extern eingekauften Leistung
- wirtschaftlich relevant für interne Betrachtung / Marge

Sie sind **nicht**:

- Verkaufs-TKP
- Verkaufsaufschlag
- Bestandteil des finalen Kunden-TKP
- Bestandteil des Mindest-TKP
- zusätzlicher Preislistenaufschlag
- Sonderfreigabe-Auslöser

Bestätigter Midroll-Listenpreis:

```text
list_tkp = 80.0000
minimum_tkp = 20.0000
```

Ausdrücklich **nicht**:

```text
80 + 15 = 95
```

Die 15 EUR werden im V1-Verkaufspreis nicht zusätzlich berechnet.

---

## Historie

- Der Wert **15 EUR** war seit Projektstart als Adserver-Wert fachlich bekannt
  und in Katalog/Initialdaten dokumentiert.
- Die frühere Einordnung als „Verkaufs-TKP / Preisbestandteil“ war **zu weitgehend**.
- PO-Klarstellung **09.11.2026**: interner Einkaufspreis, nicht Verkaufspreis.
- Der Wert war weder erfunden noch fachlich „falsch“ – nur die Verkaufseinordnung
  wird korrigiert.

---

## Preiswirkung V1

| Größe | Wirkung der 15 EUR |
|---|---|
| `list_tkp` Midroll | keine (bleibt 80.0000) |
| `minimum_tkp` Midroll | keine (bleibt 20.0000) |
| `final_tkp` | keine Addition |
| OA-001 / OA-002 | unverändert |
| Targeting-Aufschläge | unverändert |

---

## Technische Konsequenz

- Tabelle `oa_adserver_surcharges` **nicht** mit 15 EUR befüllen, solange sie
  semantisch einen Verkaufsaufschlag repräsentiert.
- Ist-Zustand (0 Zeilen, keine Engine-Anbindung, keine Preiswirkung) ist für V1
  **korrekt**.
- Kein OA-004-Runtime-Slice vor D4d-REL erforderlich.
- OA-004 ist **fachlich geklärt** und **kein Release-Blocker** für den
  Normal-TKP-V1-Verkauf.

Nicht behauptet:

- interne Kostenrechnung sei im Tool umgesetzt
- Marge/Deckungsbeitrag sei umgesetzt

---

## Future (optional, nicht geschnitten)

Separater Slice, falls interne Kosten im Tool benötigt werden, z. B.:

- internes `cost_tkp`
- Deckungsbeitrag / Marge
- Einkaufskostenmodell

Kein Datenmodell in diesem Slice.

---

## Releasepfad (nach dieser Klarstellung)

1. PRE-2 abschließen  
2. PRE-3 Preislisten aktivieren  
3. D4d-WIRE abschließen  
4. D4d-REL Registry-Release  
5. D4d-E2E Real-Release-Smoke  

OA-004-Code ist **nicht** Voraussetzung für D4d-REL.  
OA-003 bleibt außerhalb des ersten Normal-TKP-Releases.

---

## STOP

Keine Runtime. Kein Activate. Kein Registry-Flip. Kein Deploy.
