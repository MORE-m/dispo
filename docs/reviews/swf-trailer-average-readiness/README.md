# Bericht: SWF Trailer × Durchschnitt – BL-P5-01a Readiness

Stand: 7. Oktober 2026
Arbeitsbasis: `origin/main` @ `0ff11aaeb8df4ccddd0688cdeb86a551e24b9614`
(PR #128; Post-Merge-CI [37611870573](https://github.com/MORE-m/dispo/actions/runs/37611870573) SUCCESS; **kein** Deploy)
Worktree: `dispo-wt-docs-swf-trailer-average-readiness`
Branch: `docs/swf-trailer-average-readiness`
Readiness-Stand ursprünglich Docs-only; nach A1/B1 ergänzt um Umsetzungsstand (Draft-PR `feat/bl-p5-01a-swf-trailer-average`). **Kein** Merge/Deploy.

## Artefakte

| Datei | Rolle |
|---|---|
| [`docs/entscheidungen/PO-BLP501A-1-swf-trailer-average.md`](../../entscheidungen/PO-BLP501A-1-swf-trailer-average.md) | **A1 + B1 akzeptiert** |
| [`docs/readiness/BL-P5-01a-swf-trailer-average-2026-10-07.md`](../../readiness/BL-P5-01a-swf-trailer-average-2026-10-07.md) | Readiness **READY MIT DATEN-VORBEDINGUNGEN JE INVENTAR** + Umsetzungsstand |

## Scope-Empfehlung

- **Funktionsumfang:** Trailer × Average, inventarübergreifend für alle
  matrix-zulässigen Inventare (4: RH, ROCK, OLDIE, CARAVAN), Preview→Save→Reload→Dispo
- **Preisbasis (B1):** bestehende Spot-Sekunden-Grundpreise je Inventar/Jahr +
  inventarspezifischer Trailer-Aufschlag; **keine** eigenen SWF-Listen
- **Formel:** `Anzahl × Ø-Sekunden-Grundpreis × Länge × (1 + Aufschlag/100)`; kein Spotindex;
  0 % Aufschlag nur wenn explizit; fehlende Konfiguration fail-closed
- **Operativ zuerst:** Radio Hamburg (RHH-Länge/Aufschlag-Referenz)
- **Außerhalb:** Calendar, Festpreis, CityLife, weitere SWF, Produktion, CRM, OA, Standardangebote/Budget
- Elternpaket `BL-P5-01` danach nur **teilweise**, nicht erledigt

## PO-Entscheidungen

**A1** (UX-GATE-C Teilfreigabe Trailer × Durchschnitt) und **B1** (Preisbasis) akzeptiert; keine offene PO-Frage.

## Stopp

Draft-PR nach grüner CI; Merge/Deploy separat. Operative Freischaltung je Inventar erst nach
Datenlieferung (Länge/Aufschlag, Spot-Preisliste).
