# Review: CRM Stammdaten-Textsuche + Prüflisten-Labels + Konfliktzusammenfassung

Stand: 12. Oktober 2026  
Basis: `origin/main` @ `50b9d464e7d1015987c1e290637b83ba579997f5`  
Branch: `feat/crm-masterdata-search`  
IDs: `CRM-001` (Stammdatenauffindbarkeit), PO-BLP203-1 (Matching unverändert), Audit P3 + **P2 Konfliktanzeige**

## Scope (erledigt)

- Serverseitige Textsuche in `/crm/accounts` über Firmierung, Meridian, Salesforce-ID, Domain
- Kombination mit Typ-/Vorläufig-Filter; URL-Parameter `q`/`type`/`provisional`/`page`
- Pagination (50), stabile Sortierung `id` desc; keine Voll-Liste im Browser
- Prüfliste/Konflikte: Firmierung primär, Typ/vorläufig, Domain, Meridian bzw. „Meridian-Nummer folgt“, Record-Type; SF-ID sekundär
- Gleichnamige Accounts über Metadaten unterscheidbar; manuelle SF-Suche ohne Domain erhalten
- Veraltete manuelle Suchantworten invalidiert (Seq)
- **P2 Nachzug:** Fachliche `summary_lines` je bestehendem `CrmConflictType` inkl. Auftrags-Meridian (Vorgangsnummer, Seite Kunde/Agentur, bestehende/eingehende Nummer, gespeicherter Status), mehrdeutige Domain (Kandidaten aufgelöst), unterschiedliche Domains (Import ohne Account-FK), Typwechsel; Batch-Laden; Rechte für Auftrags-Links; einklappbare technische Zusatzdetails nur für nicht konsumierte Schlüssel; gespeicherte `details` unverändert

## Nicht in diesem Slice

`CRM-004`, Auto-Matching nach Name/Adresse, Import-/API-/E-Mail-Vertrag, Wizard-Picker-Änderungen, Deploy, operative Datenpflege, Konfliktauflösung/Matching-Änderungen.

## Darstellung je Konfliktart (Prüfliste)

| Typ | Zusammenfassung |
|---|---|
| `order_meridian_mismatch` | Vorgang (Kalkulation/Dispo + Nummer), Seite, bestehende/eingehende Meridian; optional gespeicherter Auftragsstatus; Detail-Link nur mit Ansichtsrecht |
| `meridian_mismatch` | Bestehende/eingehende Meridian; Salesforce-Identität wenn vorhanden |
| `link_conflict` | Message; bestehendes/angefragtes Ziel als Account-OneLine bzw. Fallback |
| `ambiguous_domain` | Konfliktdomain; Kandidaten mit Firmierung/Typ/Domain/Meridian/SF |
| `divergent_domains` | Explizit Importkonflikt ohne Account-Zuordnung; Domains; Exportzeile; SF |
| `type_change` | Message; bestehender/eingehender Typ als Kunde/Agentur; SF wenn vorhanden |
| `invalid_salesforce_id` / `duplicate_contradictory_row` | Message/Fallback; Rest in technischen Zusatzdetails |

## Tests

- Pest: `CrmMasterdataSearchTest`, `CrmConflictSummaryPresentationTest` (+ CRM-Regression)
- E2E: Port **8066**, `playwright.crm-masterdata-search.config.ts` (`--retries=0`); inkl. Auftrags-Meridian-Konflikt in der Prüfliste
