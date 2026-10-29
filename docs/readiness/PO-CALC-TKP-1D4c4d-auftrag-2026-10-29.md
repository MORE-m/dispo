# PO-CALC-TKP-1D4c-4d – Online-Audio-TKP-Adminoberfläche

Stand: 29. Oktober 2026 (Feature-Branch, Draft-PR)

## Ziel

Administratoren pflegen OA-TKP-Preislisten, Targetings und Plattformen im Browser
(Inertia), ohne CLI/CSV/JSON. Backend-Vertrag aus **D4c-4c** bleibt maßgeblich –
keine neue Preis- oder Aktivierungslogik.

## Basis

- `origin/main` Merge-Base: `db470f2fa7b33a5129396a838d0d0f1e9a0b95bc` (PR #199)
- Branch: `feat/po-calc-tkp-1d4c4d-admin-ui`
- Worktree: `dispo-wt-po-calc-tkp-1d4c4d`

## Navigation und Rechte

| Bereich | Route | Gate |
|---|---|---|
| Hub Online Audio | `/administration/online-audio` | `manage-oa-tkp-master-data` (Admin-only) |
| TKP-Preislisten | `/administration/online-audio/preislisten` (+ `/neu`, `/{id}`) | dasselbe |
| Targetings | `/administration/online-audio/targetings` | dasselbe |
| Plattformen | `/administration/online-audio/plattformen` | dasselbe |

Administrations-Hub zeigt Modul **Online Audio** nur wenn `available` (Admin).
Management und andere Rollen: Modul nicht nutzbar (`available=false`, `href=null`);
direkte Routes 403. Serverseitige Prüfung unverändert aus D4c-4c.

## Preislisten-Workflow

1. Jahr/Status filtern, Revision wählen
2. Draft anlegen (`/preislisten/neu`) – Matrix aller zulässigen Medium×Inventar-Paare
3. Listen-/Mindest-TKP eingeben, speichern (PUT JSON, `lock_version`)
4. Vollständigkeit prüfen (`POST …/pruefen`)
5. Aktivieren: Dry-Run → Bestätigung (Archivierung Vorgänger transparent) → Activate
6. Historie/Audit on-demand laden

Active/Archived: schreibgeschützt (keine Eingabefelder). Keine clientseitige
Rundung; Komma→Punkt nur beim Submit. Keine Auto-Befüllung produktiver Daten;
optionale UI-Testdaten-Checkbox nur für isolierte Abnahmewerte.

## Targeting / Plattformen

- Targetings: Anlegen + neue Revision (`POST …/targetings/{code}/revisionen`);
  Kategorien aus Enum; Geo unter `technisch`.
- Plattformen: Anlegen, Update, Aktivieren/Deaktivieren; keine Preise, kein Auto-Seed
  für Spotify/YouTube/Deezer.

## API-Anbindung

GET Browser → Inertia; GET/POST/PUT mit `Accept: application/json` → bestehender
JSON-Vertrag (4c-Tests unverändert). Mutationen im UI per `fetch` + CSRF.

## Tests

- Feature: `PoCalcTkp1d4c4dAdminUiTest`
- Vitest: `resources/js/lib/oa-tkp-admin.test.ts`
- Regression: `PoCalcTkp1d4c4cAdminBackendTest`

## Bewusst nicht

- Kein Merge/Deploy, kein produktiver Import, keine Registry-Freigabe (`planned`)
- Keine automatische 2027-Aktivierung
- Keine neue Backend-Preislogik
- Kein Hard-Delete

## Offene Risiken

- Ohne operative `inventory_medium_rules` bleibt Aktivierung fail-closed (wie 4c).
- Create erfordert ≥1 Item (API); leere Drafts ohne Preiszeile sind nicht vorgesehen.
- Browser-E2E für Admin-UI optional nachgezogen (Vitest + Feature abdecken Kernpfade).
