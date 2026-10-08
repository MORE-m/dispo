# Bericht: Produktion/Sonstiges – BL-P5-02 Readiness

Stand: 8. Oktober 2026
Arbeitsbasis: `origin/main` @ `7583a3edfbb422b7b9580b4713747201d876b8d1`
(PR #130; **kein** Deploy)
Worktree: `dispo-wt-docs-production-readiness`
Branch: `docs/production-readiness`
**Keine** Implementierung, **kein** Commit/PR/Merge/Deploy in diesem Auftrag.
Dev-DB / Port 8000 / `.env` unberührt.

## Artefakte

| Datei | Rolle |
|---|---|
| [`docs/entscheidungen/PO-BLP502-1-produktion-sonstiges.md`](../../entscheidungen/PO-BLP502-1-produktion-sonstiges.md) | PO-Entwurf **Vorgeschlagen** (A–H) |
| [`docs/readiness/BL-P5-02-produktion-2026-10-08.md`](../../readiness/BL-P5-02-produktion-2026-10-08.md) | Readiness **READY MIT VORBEDINGUNGEN** |

## Scope-Empfehlung

- **Teilscope `BL-P5-02a`:** Typ Spotproduktion als gebundene Zusatzzeile an
  Spot Classic × Average; Admin-Preis inventar+typ; Preview→Save→Reload→Dispo
- **Formel:** `Menge × Einzelpreis`; Menge Default 0; initial kein Rabatt/AE
- **Kennzeichen:** Dispo-eigene Zeile mit **S** (`PRO-006`)
- **Außerhalb:** Sonstiges-FreiPreis, volle Überschreibungs-Sonderfreigabe (optional F2),
  Influencer/Social/Fremdkosten, Trailer-Pflichtträger, CRM/OA/Standardangebote/Budget
- Elternpaket `BL-P5-02` danach nur **teilweise**; `BL-P5-01` bleibt unabhängig teilweise

## Ist-Abdeckung (Kurzbelege)

| Thema | Code/Test | Anforderung |
|---|---|---|
| Zusatzzeile / `PriceComponent` | fehlt (nur `docs/datenmodell.md`) | `ADV-003`, `PRO-001` |
| Produktionspreisliste Admin | fehlt; Spot-`PriceList` ≠ Produktion | `PRO-004`, Katalog §20.1 |
| Engine-Beitrag Zusatzzeile | fehlt | `berechnungslogik.md` Produktion |
| Kennzeichen S Pfad | Träger-Freeze ja; Produktionszeile nein | `PRO-006`, `PO-MAT-BOOKING-VIS-1` |
| Sonderfreigabe Überschreibung | Reason-Code fehlt; Workflow-Doku „noch nicht implementiert“ | `PRO-005`, AUTH-Sonderfreigabe |
| Dispo-Snapshot Zusatzzeile | `DispoOrderSnapshotMapper` ohne price_components | `PRO-006`, `DSP-*` |
| `AT-11` / `PRO-*` Tests | keine | `test-und-abnahmekatalog.md` |

## BL-P5-01-Abhängigkeit

| Ebene | Bewertung |
|---|---|
| Technisch für Spotproduktion@Spot Classic | Trailer-Average **nicht** erforderlich |
| Formal (Backlog / PO-BLP501A-1) | Abhängigkeit / keine Freigabe durch 01a – **PO-Frage B** |
| UX | Produktionspreislisten brauchen **eigene** UX-GATE-D-Teilfreigabe |

## Offene PO-Fragen

1. **A** Scope/Gate (Empfehlung A1)
2. **B** Formale BL-P5-01-Abhängigkeit (Empfehlung B1 entkoppeln)
3. **C** Bindung Calc vs. Dispo-Zeile (Empfehlung C1)
4. **D** Menge/Einheit (Empfehlung D1)
5. **E** Preisquellenmodell (Empfehlung E1; Datenlieferung offen)
6. **F** Rabatt/AE/Überschreibung im Erst-Slice (Empfehlung F1)
7. **G** Kennzeichen-S-Darstellung (Empfehlung G1)
8. **H** Inventarwechsel/Entfernen Träger (H1 + H1a/H1b)

## Datenbedarf

Operative Produktionspreise je Inventar/Typ: **nicht** im Repo belegt
(`docs/initialdaten.md`). Nur synthetische Fixtures für Tests; keine erfundenen
Beträge. Lokale DB ungeprüft.

## Urteil

**READY MIT VORBEDINGUNGEN** – Slice definierbar; Implementierung blockiert durch
PO-Akzeptanz, UX-GATE-D-Teilfreigabe und Preisdaten (bzw. Fixture-only-Abnahmeklausel).

## Stopp

Zur Scope-/Gate-Entscheidung. Keine Implementierung ohne akzeptiertes A (und geklärte
Kernfragen B/C/E/G/H sowie Datenlage).
