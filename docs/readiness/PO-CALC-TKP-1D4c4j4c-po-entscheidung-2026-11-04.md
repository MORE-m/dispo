# PO-CALC-TKP-1D4c-4j-4c – PO-Entscheidung (Stream-/Influencer-Plattformen)

Stand: 4. November 2026
Bezug: Entscheidungsvorlage 4j-4c (Readiness-Analyse); Midroll-Freigabe 4j-4 /
`PO-CALC-TKP-1D4c4j4-po-entscheidungsvorlage-2026-11-03.md`

**Status: fachlich freigegeben (PO 04.11.2026) – Strukturentscheidung.**
Konkrete Stream-Plattformnamen bleiben offen. Bis zu deren Freigabe:
**keine** neuen Plattformen, **keine** zusätzlichen Rules, **kein** 4j-5.

Kernmedien unverändert: `pre_stream`, `in_stream`, `pre_stream_influencer`,
`in_stream_influencer`, `midroll_music_environment`. `native_ad` OUT.
Registry `oa_tkp` / `tkp` bleibt `planned`. OA-004 eigener Vertrag.

---

## Freigegebene Struktur

### 1. Pre-/In-Stream

`pre_stream` und `in_stream` verwenden **dieselbe** Plattformmenge.

Für getrennte Mengen liegt kein belastbarer fachlicher Grund vor.

Die konkrete Stream-Plattformliste ist **nicht belegt** und wird **nicht** aus
bestehenden Midroll-Plattformen abgeleitet. Spotify, YouTube und Deezer sind
**nicht** automatisch für Pre-/In-Stream freigegeben.

### 2. Influencer – Modell A

- `pre_stream_influencer` = Plattformen von `pre_stream`
- `in_stream_influencer` = Plattformen von `in_stream`

Separate Influencer-Plattformmengen: kein Fachbeleg.

### 3. Keine dauerhaften Leerzustände

Vor 4j-5 müssen alle vier Medien durch die freigegebene Stream-Plattformmenge
abgedeckt sein:

- `pre_stream`
- `in_stream`
- `pre_stream_influencer`
- `in_stream_influencer`

### 4. Midroll unverändert (separat)

| Plattform | Medium |
|---|---|
| Spotify | `midroll_music_environment` |
| YouTube | `midroll_music_environment` |
| Deezer | `midroll_music_environment` |

Midroll-Zuordnung ist **kein** Beleg für Stream-Zuordnung.

---

## Noch offen (eine fachliche Entscheidung)

> Welche konkreten Plattformen bilden die gemeinsame Stream-Plattformmenge
> für Pre-Stream und In-Stream?

Benötigt: **fachliche Plattformnamen**. Technische Codes erst danach nach
Stammdatenkonvention.

Keine generische Plattform (`online_audio`, `more_oa` o. Ä.) ohne ausdrückliche
PO-Freigabe.

---

## Konsequenz / nächster Datenslice

Bis die Stream-Plattformliste vorliegt:

- keine neuen `oa_platforms`
- keine zusätzlichen `oa_platform_medium_rules`
- kein 4j-5

Nach Lieferung der Liste:

| Fall | Slice |
|---|---|
| Plattformen bereits vorhanden | kleiner Rule-Slice (Pre/In + beide Influencer = Modell A) |
| neue Plattformen nötig | zuerst Grundbestand analog 4j-4a, danach Rules |

---

## Gate 4j-5 (weiterhin gesperrt)

4j-5 erst, wenn:

- konkrete Stream-Plattformliste freigegeben
- Stammdaten vollständig
- Pre/In + beide Influencer über diese Menge abgedeckt
- Midroll-Dreiheit unverändert vorhanden
- Admin-Matrix vollständig geprüft
- keine unbeabsichtigten Leerzustände
- `native_ad` OUT
- Registry weiterhin separat `planned`
- OA-004 unverändert eigener Vertrag

### Harte Grenzen dieser Freigabe

Keine Datenmutation aus diesem Dokument allein. Kein Resolver-Enforcement,
kein Wizard-Filter, keine Preiswirkung, kein `FD-OA-PLAT`, kein Registry-Release.

---

## PO-Freigabetext (erteilt 04.11.2026)

> Pre-Stream und In-Stream teilen dieselbe Plattformmenge; die konkreten Namen
> bleiben offen und werden nicht aus Spotify/YouTube/Deezer (Midroll) abgeleitet.
> Pre-Stream Influencer und In-Stream Influencer übernehmen jeweils die
> Standard-Plattformen (Modell A). Keines der vier Medien bleibt dauerhaft ohne
> Plattform. Midroll-Dreiheit unverändert und separat. Bis zur Stream-Liste:
> keine neuen Plattformen, keine zusätzlichen Rules, kein 4j-5. Kein Generikum
> ohne ausdrückliche Freigabe. OA-004 und Registry bleiben eigene Verträge.

**Status: freigegeben (Struktur) – Umsetzung blockiert bis Stream-Plattformnamen.**
