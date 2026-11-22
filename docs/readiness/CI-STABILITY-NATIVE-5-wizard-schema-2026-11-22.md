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

- Generation-sicheres Clear des Loading-Flags.
- Kein hartes Verwerfen valider Applies nur wegen Effect-Cleanup; Guard bleibt `shouldApplyPositionSchemaResponse`.
- Begrenzte Auto-Recovery (max. 3), wenn Fingerprint fehlt und kein Fetch läuft.
- Leere/ungültige 200-Antworten setzen expliziten Fehler statt stillem Continue.
- Unit-Tests für Generation-Clear.

## Nicht gemacht

- Kein Timeout-Erhöhen, kein Test-Disable, keine Abschwächung der Fach-Assertions.
- Keine Änderung an PR #263.
