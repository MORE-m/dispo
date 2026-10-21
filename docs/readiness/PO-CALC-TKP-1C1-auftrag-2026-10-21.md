# PO-CALC-TKP-1C1 – Under-Min-Sonderfreigabe (Auftrag)

Stand: 21. Oktober 2026  
Base: `7e81d1f30f9ac03ef12349211a843dbf6f9bb300` (`origin/main`, Post-Merge CI `39564182672`)  
Branch: `feat/po-calc-tkp-1c1-under-min-approval`

## Ziel

Fachvertrag Normal-TKP:

```text
basis_tkp < minimum_tkp_snapshot → Sonderfreigabe
```

additiv in die bestehende Special-Approval-Architektur.

## Umgesetzt

- `SpecialApprovalReasonCode::OaTkpBasisBelowMinimum` (`oa_tkp_basis_below_minimum`)
- `SpecialApprovalAssessor::oaTkpBelowMinimumReasonsFromStoredPosition()`
  - Identity: `engine_profile_key=oa_tkp` + `calculation_method_key=tkp`
  - SoT: Decimal-Vergleich Snapshot-Felder (nicht `below_minimum_tkp` allein)
  - Fail-closed bei fehlendem Snapshot (`ValidationException`)
  - Known-Code in `inheritedUnattributableReasons()` (kein `unattributable` bei Teilselektion)
- `OaTkpCalculationPersister`: nach Persistenz `assessFromCalculation`, setzt
  `requires_special_approval` / `special_approval_reasons` / `personal_discount_limit_percent`
  in derselben Transaktion
- Tests: Decimal-Grenzen, Targeting heilt nicht, Boolean-Drift, Partial Selection,
  Mixed Spot+OA, Persister, Snapshot-Isolation, Dispo-Header-Mapper, Registry planned

## Bewusst offen (nicht C1)

- Nutzerbegründung beim Special-Submit (**MITTEL**-Follow-up, OA-002)
- Product Writer / ConfigurationSnapshot / Multi-Position HTTP
- OA Dispo-Vollsnapshot (Impressions/TKP/Platform/Targeting auf DispoOrderPosition)
- Wizard, Bookability-Release, Registry Release
- OA-Festpreis, Adserver, Native
- Preisjahr / aeEnabled Product-Default / Admin / Echtdaten

## Statuszeile

> Under-Min-Sonderfreigabe technisch integriert; TKP weiterhin planned und nicht Nutzer-operational.
