# PO-CALC-TKP-1D2a – Data Contract / Metadata / Preview

Stand: 22. Oktober 2026 (Feature-Branch)

## Ziel

Serverseitiger UI-Datenvertrag für Normal-TKP im Kalkulationswizard – ohne React-Editor, ohne HTTP Store/Update, ohne Registry-Release.

## Capability

- Config: `dispo.oa_tkp_wizard_capability` / Env `OA_TKP_WIZARD_CAPABILITY` (Default **false**)
- Klasse: `OaTkpWizardCapability`
- Soft-Open: Method Options (nur Kalkulationswizard), HTTP Preview, Pricing Metadata
- **Nicht** Soft-Open: Store, Update, Budget, Standard Offers, Bookability-Release, Dispo
- Kein Environment-/NODE_ENV-Hack; Tests setzen Config explizit

## Preview

Additive Position-Felder:

```json
"method_result": {
  "method": "tkp",
  "tkp": {
    "basis_tkp": "10.0000",
    "list_tkp": "12.0000",
    "minimum_tkp": "8.0000",
    "targeting_surcharge_total": "2.0000",
    "final_tkp": "12.0000",
    "below_minimum_tkp": false,
    "impressions": 100000,
    "price_year": 2026
  }
}
```

Commercial-Felder bleiben generisch. Spot/SWF: `method_result = null`.

## Metadata

`GET /kalkulationen/oa-tkp/pricing-metadata`

Input: `advertising_medium_id`, `inventory_id`, optional `price_year`

Output: `available_price_years`, `expected_oa_tkp_price_list_id` (aktuelle Active-ID), `list_tkp`, `minimum_tkp`, …

**Unterscheidung:** Edit Props = historischer Pin; Metadata = aktueller Rebind-Kandidat.

## Wizard Props

Unter Capability: `catalog.oa_platforms`, `catalog.oa_targetings`, Soft-Open `tkp` in Method Options, OA-Reload-Felder aus `payloadFromCalculation()`.

## Release Protection

```text
oa_tkp / tkp: pair_status=planned, current_released_version=null, v1=implemented
```

## OUT / Folge

- D2b: React TKP Editor
- D3: OA Dispo Snapshot
- D4: Registry Release + HTTP Store/Update Nutzerfreigabe
