# PO-CALC-TKP-1D4c-4j-4 – PO-Entscheidung (Plattform-Fachdaten)

Stand: 3. November 2026  
Basis: `main` Merge #206 `ad5161c0d6ff0958c6833aebbcc038d5557e2ca9`  
Post-Merge-CI: [`41666294818`](https://github.com/MORE-m/dispo/actions/runs/41666294818)

**Status: fachlich freigegeben (PO 03.11.2026).**  
Umsetzung von **4j-4a** erst nach vollständig grüner Post-Merge-CI
(`ci` / `mysql` / `e2e-spt008`). Bis dahin: Doku nur; keine Migration,
keine Datenänderung, kein Import.

**Nachtrag 05.11.2026 (4j-KORR):** Die drei `oa_platforms` Spotify/YouTube/Deezer
und Midroll-Rules bleiben **historisch umgesetzt**, sind aber für operative
V1-Buchung **nicht** als einzeln wählbare Plattformen freigegeben. Midroll =
gemeinsames Werbemittel `midroll_music_environment`. 4j-5 alter Zuschnitt
**verworfen**. Siehe
`PO-CALC-TKP-1D4c4j-korr-po-vertrag-plattformmodell-v1-2026-11-05.md`.

Kernmedien unverändert: `pre_stream`, `in_stream`, `pre_stream_influencer`,
`in_stream_influencer`, `midroll_music_environment`. `native_ad` OUT.

---

## A. Quellenlage (unverändert)

| Aussage | Quelle |
|---|---|
| Drei Namen im Midroll-Kontext: Spotify, Deezer, YouTube/Youtube | Katalog-Mediumname, Discovery 4j, OA-004 / `initialdaten.md` |
| Midroll-Namen fachlich bestätigt; Codes/Sort/Flags/Stream waren offen | `PO-CALC-TKP-1D4c4j-discovery-2026-10-30.md` |
| Spotify/YouTube/Deezer = fachlicher Kontext / spätere Plattform-Stammdaten | `PO-CALC-TKP-1D4c4-discovery-2026-10-28.md` |
| Operative `oa_platforms` = 0; Importvorlage leer | D4c-1 / D4c-3 / Live `dispo_mat_core` |
| Admin-Matrix ohne Enforcement | PR #206 |
| `is_adserver_relevant` Default `false`; Runtime wertet Feld nicht aus | Schema 1A, Importer, Engine |
| Adserver-TKP 15 EUR Spotify/Deezer/YouTube | OA-004 – **eigener Vertrag** |

Nicht als Fachquelle: E2E-Seeder, Fixtures, synthetische `plat_*`.

Schreibweise Plattform: **YouTube**. Katalog-Mediumname „Youtube“ bleibt unberührt.

---

## B. Freigegebene Entscheidung

### Plattform-Grundbestand

| Anzeigename | Code | Sort | is_adserver_relevant | is_active in 4j-4a |
|---|---|---:|---:|---:|
| Spotify | `spotify` | 10 | `false` | `false` |
| YouTube | `youtube` | 20 | `false` | `false` |
| Deezer | `deezer` | 30 | `false` | `false` |

Drei eigenständige Datensätze in `oa_platforms`. Kein Auto-Seeder.

### Initiale Rule-Matrix (4j-4b)

| Plattform | Midroll | Pre / In / Pre Influencer / In Influencer |
|---|---|---|
| Spotify | erlaubt | **keine Rule** (bewusst offen) |
| YouTube | erlaubt | keine Rule (bewusst offen) |
| Deezer | erlaubt | keine Rule (bewusst offen) |

Offen ≠ endgültige Ablehnung.

### Aktivierung

Nach Anlage und erfolgreicher Prüfung der drei Midroll-Rules dürfen die drei
Plattformen auf `is_active=true` gesetzt werden. Das ist Teil des Datenrollouts
und **keine** OA-TKP-Produktivfreigabe. 4j-5 bleibt gesperrt.

### `is_adserver_relevant` / OA-004

Wert `false` ist nur der aktuelle Stammdatenwert. Keine Entscheidung über OA-004
oder den 15-EUR-Adserver-Aufschlag. OA-004 bleibt eigener späterer Vertrag.

### Datenstrategie

- Plattformen: versionierte JSON-Lieferung über `oa:import-platforms`
- Rules: ausschließlich Admin-Matrix (PR #206)
- kein automatischer Seeder, kein Rule-Importer, keine E2E-/Testdaten

---

## C. Verbleibend offen nach dieser Freigabe

1. Plattformen / Rules für **Pre-Stream**
2. Plattformen / Rules für **In-Stream**
3. Plattformen / Rules für **Pre-Stream Influencer**
4. Plattformen / Rules für **In-Stream Influencer**
5. OA-004 / Adserver-Aufschlag (separat)

---

## D. Freigegebener 4j-4-Schnitt

### 4j-4a – Schema + Plattformgrundbestand

Voraussetzung: Post-Merge-CI #206 vollständig grün.

1. 4j-1-Migration nur auf ausdrücklich freigegebener DB
2. Drei Plattformen inaktiv importieren (`is_active=false`)
3. Noch **keine** Rules

### 4j-4b – Midroll-Rules + Aktivierung

1. Spotify / YouTube / Deezer → nur `midroll_music_environment`
2. Vier Stream-/Influencer-Medien bewusst ohne Rule
3. Admin-Matrix read-only gegen Entscheidung prüfen
4. Danach die drei Plattformen aktivieren

---

## E. Gate für 4j-5 (weiterhin gesperrt)

- CI #206 grün; Schema + Midroll-Plattformen + Midroll-Rules abgenommen
- Alle fünf Kernmedien fachlich bewertet (inkl. bewusster Leerzustände)
- `native_ad` ohne Rule; Registry `planned`
- Keine Ausnahme „leer = offen“; kein Enforcement vor Stream-/Influencer-Bewertung

### Harte Grenzen (auch für 4j-4a/4j-4b)

Kein Resolver-Enforcement, kein Wizard-Filter, kein Mediumwechsel-Cleanup,
keine Preiswirkung, kein `FD-OA-PLAT`, kein OA-004, kein Registry-Release,
keine Änderung historischer Snapshots.

---

## F. PO-Freigabetext (erteilt 03.11.2026)

> Für 4j-4 werden Spotify, YouTube und Deezer als eigenständige OA-Plattformen
> (`spotify` / `youtube` / `deezer`, Sort 10 / 20 / 30, `is_adserver_relevant=false`)
> angelegt. In 4j-4a zunächst `is_active=false`. Im Rule-Slice nur
> `midroll_music_environment`. Pre-Stream, In-Stream und beide Influencer-Medien
> bleiben ohne Rule bewusst offen; 4j-5 bleibt gesperrt. Nach Midroll-Rule-Prüfung
> dürfen die drei Plattformen aktiviert werden (keine Produktivfreigabe).
> Plattformen via JSON/`oa:import-platforms`, Rules via Admin-Matrix. OA-004 bleibt
> eigener Vertrag. Umsetzung 4j-4a erst nach grüner Post-Merge-CI #206; danach 4j-4b.

**Status: freigegeben – Umsetzung abhängig vom technischen CI-Gate.**
