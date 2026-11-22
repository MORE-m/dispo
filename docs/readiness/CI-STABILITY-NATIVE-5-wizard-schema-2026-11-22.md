# CI-STABILITY-NATIVE-5 – Wizard-Schema-Timeout

**Datum:** 2026-11-22  
**Betroffen:** `tests/e2e/po-free-position-native-5.spec.ts` (E2E-3 / E2E-6)  
**Artefakte:** CI-Runs `44924240447` (PR #261), `44942012073` Attempt 1 (PR #263)

## Befund

- Timeout 60s auf `[data-test="wizard-schema-loading"]` Count 1.
- UI-Text: „Positions-Feldschema wird noch geladen…“ (auch Fallback bei fehlendem Fingerprint ohne aktives Loading).
- Playwright-Trace: genau **ein** `POST /kalkulationen/feldschema` → **HTTP 200** mit gültigem `schema_fingerprint`.
- Danach kein weiterer Schema-Request; Speichern bleibt blockiert.

## Ursache (eingegrenzt, belastbar)

1. Erfolgreiche Schema-Antwort kann verworfen werden (`cancelled`-Abbruch vor `setPositions` bzw. Generation-Mismatch), während `finally` das Loading-Flag löscht.
2. Ohne Änderung an `positionsNeedingSchemaKey` startet der Effect nicht neu → dauerhaft `schemaFingerprintMissing` ohne Fetch.
3. Speichern triggert Retry erst per Klick; `waitForSchemaReady` wartet vorher und läuft in den 60s-Timeout.

## Fix (dieser Branch)

**Primär (verhindert den Stuck, ohne Retries):**
- Generation-sicheres Clear des Loading-Flags.
- Gültige HTTP-200-Antworten werden trotz Effect-Restart übernommen (`completePositionSchemaFetch`).
- Echter Unmount: `endSchemaFetchSession` + `schemaWizardMountedRef` → keine State-Updates; Effect-Restarts bleiben generationsbasiert.
- Extrahierte Orchestrierung in `position-schema-fetch.ts` inkl. Regressionstests.

**Sekundär (Sicherheitsnetz, nicht der eigentliche Fix):**
- Begrenzte Auto-Recovery (max. 3) **pro** `positionsNeedingSchemaKey` (`syncSchemaRecoveryScope`).

**CI-Infrastruktur (Run `45034649426` / e2e-spt008):**
- `ubuntu-latest` → Ubuntu **26.04 (resolute)**; `playwright install --with-deps` Exit 100 (u. a. `fonts-tlwg-loma-otf`, `fonts-freefont-ttf` nicht verfügbar).
- Jobs `ci` / `e2e-spt008` / `mysql` auf **`ubuntu-24.04`** gepinnt (kein Dependency-Upgrade).

## Nicht gemacht

- Kein Timeout-Erhöhen, kein Test-Disable, keine Abschwächung der Fach-Assertions.
- Keine Änderung an PR #263.
- Kein Merge, kein Deploy, keine DB-Änderungen.
