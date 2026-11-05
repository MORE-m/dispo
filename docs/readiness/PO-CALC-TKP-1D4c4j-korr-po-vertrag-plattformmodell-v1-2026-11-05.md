# PO-CALC-TKP-1D4c-4j-KORR – PO-Vertrag Plattformmodell V1

Stand: 5. November 2026  
Status: **fachlich freigegeben (PO 05.11.2026) – Docs-/Vertragskorrektur**  
Base: `main` @ `fc6bcdad981ec09ee505fbd576c617820caf6b75` (nach PR #211)

**Keine** Runtime-, DB-, Rule-, Plattform-, Wizard-, Resolver- oder Registry-Änderung
in diesem Slice.

---

## PO-Entscheidung (operativ V1)

### Buchbare Werbemittel

1. `pre_stream`
2. `in_stream`
3. `pre_stream_influencer`
4. `in_stream_influencer`
5. `midroll_music_environment`

`native_ad` bleibt OUT. Registry `oa_tkp` × `tkp` bleibt `planned`.

### Plattformauswahl V1

Für **keines** der fünf Werbemittel gibt es in V1 eine zusätzliche Plattformauswahl.

| Medium | Plattformauswahl V1 |
|---|---|
| `pre_stream` | nein |
| `in_stream` | nein |
| `pre_stream_influencer` | nein |
| `in_stream_influencer` | nein |
| `midroll_music_environment` | nein |

- Pre-/In-Stream und Influencer: Advertising Medium = buchbare Einheit; keine
  Plattformverteilung.
- Midroll: steht für **Spotify / YouTube / Deezer gemeinsam** (Gesamtpaket).
  Keine Einzelwahl, keine Teilkombinationen.
- UI-Anzeigename Midroll (z. B. „Mid-Roll Spotify / Deezer / Youtube Musikumfeld“)
  darf bleiben – **nicht** als drei Checkboxen/Plattformen.

Spotify / YouTube / Deezer sind in V1 **keine** einzeln auswählbaren Buchungsplattformen.

---

## Historisch umgesetzt vs. V1-relevant

### Historisch umgesetzt (Fakten bleiben)

- Tabelle/`oa_platforms`, Import, Admin-CRUD
- `oa_platform_medium_rules` + Admin-Matrix (4j-1 … 4j-3)
- lokale Stammdaten: Spotify / YouTube / Deezer aktiv
- drei aktive Midroll-Rules (4j-4a/4j-4b)
- Writer/Wizard verlangen heute technisch noch `oa_platform_allocations` (≥1)

### Neue PO-Bewertung

Diese technische Plattformstruktur ist **nicht buchungsrelevant für V1**.  
Sie ist **kein** Vertrag für Endnutzer-Plattformauswahl und **kein** Speicher-Gate
über Platform×Medium-Rules.

Bestehende lokale Plattform-/Rule-Daten: zunächst **technisch belassen**, aber für
den operativen V1-Buchungsprozess **nicht maßgeblich**. Optionen (deaktivieren,
Future behalten, Matrix Future-only, Neu-Modellierung) bleiben **offen** – nicht
jetzt entscheiden oder mutieren.

---

## OA-005 / OA-006 / OA-007

| ID | Einordnung V1 |
|---|---|
| **OA-005** / **OA-006** | Geplantes / zukünftiges Plattform-/Allokationsmodell. **Future – nicht für operative V1 freigegeben.** Dürfen nicht so gelesen werden, dass Spotify/YouTube/Deezer einzeln wählbar sind, Pre/In eine Plattformwahl brauchen oder jedes Medium ≥1 Platform×Medium-Rule braucht. |
| **OA-007** | Pre-/In-Zuordnung der AIs über die getrennten Advertising-Media-Codes in V1 bereits abgebildet. |
| **OA-004** | Eigener Vertrag; Midroll-Bundle kann fachlich Adserver-Kontext tragen; **keine** Einzelplattform-Auswahl daraus. Preislogik unverändert. |

---

## 4j-5 / 4j-6

### 4j-5 (alter Zuschnitt)

Vertrag „fail-closed Platform×Medium Enforcement für alle fünf Kernmedien“ ist für
V1 **fachlich verworfen**. In dieser Form **nicht implementieren**.

Kennzeichnung: **gestoppt / neu zu schneiden**. Kein Requirement „jedes Medium
braucht aktive Platform-Rules“. Bestehende Rules sind kein V1-Speicher-Gate.

### 4j-6 (alter Zuschnitt)

Wizard-/E2E-Slice mit Plattformfilter/-pflicht **neu schneiden**. V1-Wizard darf
keine Plattformauswahl verlangen (Tabelle oben).

---

## Neuer Zielpfad (KORR)

| Slice | Inhalt | Status |
|---|---|---|
| **KORR-1** | PO-/Docs-Vertrag korrigieren | **dieser Slice** |
| **KORR-2** | Readiness Wizard/Resolver ohne Plattformpflicht | nächster Auftrag |
| **KORR-3** | minimaler Runtime-Slice für V1 | nach KORR-2 |
| **KORR-4** | E2E / Abnahme fünf Werbemittel ohne Plattformauswahl | nach KORR-3 |
| **Future** | OA-005/006 echte Plattformverteilung neu spezifizieren | später |

Alter Pfad `4j-4 → 4j-5 → 4j-6` (Enforcement/Wizard-Filter) entfällt bzw. wird
durch KORR-2…4 ersetzt.

---

## Harte Grenzen dieses Slices

Keine DB-Mutation, Plattform-/Rule-Änderung, Wizard-/Resolver-Code, Migration,
Registry-Freigabe, Preislogik, OA-004-Implementierung.

---

## Anhang: Prompt für nächsten Readiness-Auftrag (noch nicht ausführen)

```text
# PO-CALC-TKP-1D4c-4j-KORR-2 – Wizard-/Resolver-Readiness
## OA-TKP V1 ohne Plattformauswahl

Basis: PO-CALC-TKP-1D4c-4j-KORR (Vertrag 05.11.2026).
V1: fünf Werbemittel, keine Plattformauswahl; Midroll = Spotify/YouTube/Deezer-Bundle.
Registry bleibt planned. Keine Code-/DB-Mutation in diesem Readiness-Slice.

### Ziel
Klären, welche minimalen Runtime-Änderungen nötig sind, damit OA-TKP-Positionen
in V1 ohne Plattformauswahl speicherbar/validierbar sind – erst Analyse.

### Prüfen (read-only)
1. Wizard: wo `oa_platform_allocations` Pflicht; UI „Platform-Verteilung“
2. Resolver/Writer: ValidationException „mindestens eine Allocation“
3. Engine: assertPlatformAllocations
4. Persistenz/Snapshots: calculation_position_oa_platform_allocations;
   Dispo `oa_platform_allocations_snapshot` – nullable/leer erlaubt?
5. Catalog: oa_platforms an Wizard; Medium-Filter fehlt
6. Tests/E2E/Fixtures, die ≥1 Plattform erzwingen
7. Bestehende Calcs mit/ohne Allocations
8. Wechselwirkung mit aktiven oa_platforms + Midroll-Rules (nicht mutieren)

### Ergebnis
- Gap-Liste Ist vs. V1-Vertrag
- betroffene Dateien/Verträge
- Optionen für KORR-3 (minimaler Slice), Empfehlung
- was bewusst OUT bleibt (OA-005/006 Future, OA-004, Registry, 4j-5 alt)

STOP nach Readiness. Kein Runtime-Code in KORR-2.
```
