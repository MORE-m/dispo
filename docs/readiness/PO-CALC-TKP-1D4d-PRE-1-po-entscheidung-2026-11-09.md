# PO-CALC-TKP-1D4d-PRE-1 – PO-Fachentscheidung Preisjahr, 2027.1, Targeting, OA-004

Stand: 9. November 2026  
Basis: `main` `8366b824071999ec51f5d1740346b0e45ccb36bf`  
Vorgänger: `docs/readiness/PO-CALC-TKP-1D4d-go-no-go-2026-11-09.md` (NO-GO)

Docs-only. Keine Registry-Mutation, keine DB-Mutation, keine
Preislisten-Aktivierung, keine OA-004-Implementierung, kein Deploy.

**D4d bleibt NO-GO.** Die Fachdatenblocker sind reduziert.
Registry `oa_tkp` × `tkp` bleibt `planned`.
OA-TKP ist nicht produktiv freigegeben.

Release-Ziel der späteren Freigabe: **Normal-TKP**. OA-003 / OA-Festpreis
bleibt außerhalb dieses ersten Releases.

---

## 1. Preisjahr 2026

**Freigegeben: JA.** Datum: 9. November 2026.

Für die operative Nutzung wird eine eigene OA-TKP-Preisliste **2026**
benötigt. Die Preiswerte sind fachlich identisch mit den freigegebenen
2027er Preisen.

- Quelle sind die bestehenden Draft-`2027.1`-Werte. Keine neuen Preise.
- 2026 und 2027 bleiben getrennte Jahresstände nach dem bestehenden
  OA-Preislistenvertrag (`year` je Liste, max. eine active Revision
  pro Jahr, Import nur `draft`, Aktivierung getrennt).
- In diesem Slice keine Erstellung und keine Aktivierung.

## 2. Preisliste 2027.1

**Freigegeben: JA.** Datum: 9. November 2026.

Die bestehende Draft-Liste ist die fachlich richtige Preisbasis für 2027:

| Feld | Wert |
|---|---|
| Jahr | 2027 |
| Version | `2027.1` |
| Revision | 1 |
| Items | 41 |
| Status in `dispo_mat_core` | weiterhin `draft` |

Die Werte dürfen als fachlich freigegebene Preisbasis behandelt werden.
Aktivierung ist nicht Teil dieses Slices. `published_at` bleibt leer,
bis ein späterer Datenlauf aktiviert.

## 3. Targetings

**Freigegeben: JA.** Datum: 9. November 2026.

Diese zwei Einträge sind der freigegebene V1-Zielgruppenkatalog.
Keine weiteren Targetings.

| code | Kategorie | TKP-Aufschlag | Mindest-TKP-Aufschlag |
|---|---|---|---|
| `dmp` | `dmp` | 25.0000 | 5.0000 |
| `geo` | technisch | 15.0000 | 3.0000 |

## 4. OA-004 / 15 EUR Adserver

**Zum Zeitpunkt PRE-1:** `Nicht entschieden.` Status war
`offen / fachlich zu klären`.

Die damalige Anforderungsformulierung im Repository lautete sinngemäß
„Verkaufs-TKP / Preisbestandteil“. Offen waren Herkunft, Geltung und
ob die 15 EUR im Midroll-Listen-TKP **80.0000** enthalten oder zusätzlich
gelten. Keine Annahme „80 + 15“.

**Nachtrag OA004-DEC, 9. November 2026:** fachlich geklärt.
15 EUR = interne Einkaufskosten / harte Kosten; **kein** Verkaufsaufschlag;
keine Preiswirkung auf Listen-/Final-/Mindest-TKP. Midroll bleibt
`80.0000` / `20.0000`. OA-004 ist **kein Release-Blocker** mehr für den
Normal-TKP-V1-Verkauf; kein Runtime-Slice vor D4d-REL.
Dok: `docs/readiness/PO-CALC-TKP-1D4d-OA004-po-entscheidung-2026-11-09.md`.

## 5. Konsequenz für D4d

Geklärt:

- 2026 wird benötigt
- 2026 nutzt dieselben Preise wie 2027
- `2027.1` ist fachlich freigegeben, technisch weiter Draft
- `dmp` und `geo` sind der freigegebene V1-Katalog

Weiter offen, deshalb weiterhin NO-GO:

- Aufbau der 2026-Preisliste
- Aktivierung 2026 und 2027
- OA-004 → **Nachtrag OA004-DEC:** geklärt, kein Verkaufs-Preisblocker
- D4d-WIRE (Wizard bleibt nach einem reinen Registry-Flip ohne Methodenoption)
- Registry-Release
- Release-E2E

OA-003 bleibt außerhalb des ersten Normal-TKP-Releases.

---

## Prompt für den nächsten Slice (nicht ausführen)

```text
# PO-CALC-TKP-1D4d-PRE-2 – OA-TKP Preislisten 2026/2027 Datenbereitstellung

Basis: main 8366b824071999ec51f5d1740346b0e45ccb36bf
PO: docs/readiness/PO-CALC-TKP-1D4d-PRE-1-po-entscheidung-2026-11-09.md
D4d bleibt NO-GO. Registry oa_tkp × tkp bleibt planned.

Ziel:
Aus der fachlich freigegebenen Draft-Liste 2027.1 (41 Items) eine
separate Jahresliste 2026 mit denselben 41 Preiswerten vorbereiten.
Beide Jahresstände validieren. Die Aktivierungsreihenfolge nur
vorbereiten.

Preise:
- Quelle sind die vorhandenen list_tkp und minimum_tkp der Draft-2027.1.
- Keine Neuberechnung, keine Rundung, keine neuen Paare.
- Midroll bleibt 80.0000 / minimum 20.0000.
- Keine Annahme 80 + 15. OA-004 nicht anfassen, nicht in den Listenpreis
  rechnen, oa_adserver_surcharges nicht befüllen.

Vertrag (bestehend, D4c-2):
- php artisan oa:import-price-list nur mit status=draft.
- Jahr 2026 ist eine eigene Liste, nicht eine Änderung der 2027.1.
- 2027.1 nicht überschreiben, nicht aktivieren.
- Activate (oa:activate-price-list) in diesem Slice nicht ausführen.
- Im Dokument die spätere Reihenfolge nennen: erst Dry-Run Activate je
  Jahr, dann Activate ohne --archive-previous, solange das Jahr keine
  active Revision hat. Max. eine active Revision pro Jahr.

Validierung vor jedem Schreiblauf:
- 41 Items, fünf Kernmedien, native_ad nicht dabei.
- Abdeckung = aktive Inventory-Medium-Rules der fünf Medien
  (10+10+10+10+1), keine Lücke, keine Zusatzzeile.
- minimum_tkp ≤ list_tkp, Werte > 0, vier Nachkommastellen.
- 2026-Werte zeilenweise gleich den 2027.1-Werten (medium_code +
  inventory_code).
- Targetings dmp/geo unverändert lassen.
- Registry-Assert: pair_status bleibt planned.

Datenbank dispo_mat_core: vorher Backup nach local-backups/.
Kein Deploy. Kein D4d-WIRE. Kein OA-003. Keine Registry-Freigabe.

Output: kurzer Auftrag in
docs/readiness/PO-CALC-TKP-1D4d-PRE-2-auftrag-YYYY-MM-DD.md
plus Statusnachzug fortschritt / backlog / blocker / README.
D4d darin weiterhin NO-GO nennen.

STOP vor der Aktivierung.
```
