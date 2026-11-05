# PO-CALC-TKP-1D4c-4j-2/3 – Admin-Matrix Plattform × Medium

Stand: 3. November 2026 – auf `main` (PR **#206**, Merge
`ad5161c0d6ff0958c6833aebbcc038d5557e2ca9`; Feature-HEAD
`b79601f83cba3210878210212cd0beb6a4a2f4ec`).

Base war: `607fd6096c8f26549d8aca36e58f931c44196b99` (PR #205)
Branch: `feat/po-calc-tkp-1d4c4j23-platform-medium-admin`

Kanonische Folge war: **4j-4** Fachdaten → **4j-5** Enforcement → **4j-6**
Wizard/E2E. **Nachtrag 05.11.2026 (4j-KORR):** 4j-5/4j-6 alter Zuschnitt für V1
**gestoppt**; Admin-Matrix bleibt historisch vorhanden, ist aber **nicht**
V1-Buchungsvoraussetzung. Weiter mit **KORR-2…4**. Siehe
`PO-CALC-TKP-1D4c4j-korr-po-vertrag-plattformmodell-v1-2026-11-05.md`.

## Ziel

Administratoren pflegen, welche der fünf OA-Kernmedien einer Plattform zugeordnet
sind. Die Pflege ist Stammdatenpflege. Sie erzwingt die Zuordnung in Kalkulation
und Wizard noch nicht.

## Umgesetzt

| Baustein | Status |
|---|---|
| Gate `manage-oa-tkp-master-data` (nur Admin) | ja |
| GET `/administration/online-audio/plattform-medien` (JSON oder Inertia) | ja |
| PUT Full Desired State (`cells` = jede Plattform × fünf Kernmedien) | ja |
| `revision_token` (SHA-256 über Plattform-IDs und Rule-Paare) | ja |
| 409 bei Token-Drift (`OaTkpAdminConflictException`) | ja |
| Soft-Deactivate über `is_active`; fehlende inaktive Paare legen keine Zeile an | ja |
| Audit `oa_platform_medium_rule.created` / `.updated` über bestehenden `AuditLogger` | ja |
| Historie der letzten 100 Rule-Audits | ja |
| DELETE → 405, kein Hard-Delete | ja |
| Hub-Karte „Plattform × Medium“ | ja |
| Dirty-State und Speichern in der Inertia-Matrix | ja |
| `native_ad` und Nicht-Kernmedien abgewiesen | ja |

## Ausdrücklich noch nicht

- 4j-4 fachliche Plattform- oder Rule-Befüllung
- 4j-5 Resolver-Enforcement (`OaTkpWriterPositionResolver` unverändert)
- 4j-6 Wizard-Filter, Mediumwechsel-Cleanup, E2E
- Registry-Release (`oa_tkp` / `tkp` bleibt `planned`)
- Migration auf `dispo_mat_core`
- Plattform-Seeds, keine Spotify-/YouTube-/Deezer-Annahmen
- Preiswirkung von Plattformen (`FD-OA-PLAT` separat)
- Änderung an Calc-/Dispo-Snapshots, Targetings oder Preislogik

Leere Plattformliste bleibt leer. Inaktive Plattformen dürfen Rules tragen;
operativ zählen sie erst bei aktiver Plattform und späterem Enforcement.

## Tests (lokal, 03.11.2026, nach Rebase auf PR #205)

- `PoCalcTkp1d4c4j2PlatformMediumRuleAdminTest` + `PoCalcTkp1d4c4dAdminUiTest`:
  SQLite 20/20, MySQL `dispo_test` 20/20
- Vitest `resources/js/lib/oa-tkp-admin.test.ts`: 8/8
- `npm run check`, `tsc --noEmit`, Pint, PHPStan (Repo-Pfade) grün

## Nachzug Merge / 4j-4

Merge und Admin-Matrix sind erledigt. **4j-4** ist fachlich freigegeben
(`PO-CALC-TKP-1D4c4j4-po-entscheidungsvorlage-2026-11-03.md`); Umsetzung erst nach
grüner Post-Merge-CI #206. Weiterhin: kein Enforcement, kein Deploy, keine
Registry-Freigabe; `dispo_mat_core`-Migrate nur im freigegebenen 4j-4a-Schritt.
