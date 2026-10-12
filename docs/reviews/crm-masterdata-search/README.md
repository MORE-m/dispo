# Review: CRM Stammdaten-Textsuche + Prüflisten-Labels

Stand: 12. Oktober 2026  
Basis: `origin/main` @ `50b9d464e7d1015987c1e290637b83ba579997f5`  
Branch: `feat/crm-masterdata-search`  
IDs: `CRM-001` (Stammdatenauffindbarkeit), PO-BLP203-1 (Matching unverändert), Audit P3

## Scope (erledigt)

- Serverseitige Textsuche in `/crm/accounts` über Firmierung, Meridian, Salesforce-ID, Domain
- Kombination mit Typ-/Vorläufig-Filter; URL-Parameter `q`/`type`/`provisional`/`page`
- Pagination (50), stabile Sortierung `id` desc; keine Voll-Liste im Browser
- Prüfliste/Konflikte: Firmierung primär, Typ/vorläufig, Domain, Meridian bzw. „Meridian-Nummer folgt“, Record-Type; SF-ID sekundär
- Gleichnamige Accounts über Metadaten unterscheidbar; manuelle SF-Suche ohne Domain erhalten
- Veraltete manuelle Suchantworten invalidiert (Seq)

## Nicht in diesem Slice

`CRM-004`, Auto-Matching nach Name/Adresse, Import-/API-/E-Mail-Vertrag, Wizard-Picker-Änderungen, Deploy.

## Tests

- Pest: `CrmMasterdataSearchTest` (+ bestehende CRM-Regression)
- E2E: Port **8066**, `playwright.crm-masterdata-search.config.ts` (`--retries=0`)
