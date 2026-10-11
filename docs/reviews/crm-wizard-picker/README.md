# Review: CRM-Wizard-Picker (PO-BLP203-1 I1)

Stand: 11. Oktober 2026  
Basis: `origin/main` @ `d4ff19607ebcb4a45f6381c40d9a568ed8257e27`  
Branch: `feat/crm-wizard-picker`

## Scope (erledigt)

- Verständliche Kunden-/Agenturauswahl (Suche → Auswahl; Freitext übernehmen)
- Freitext beim Speichern → vorläufiger CRM-Account (keine Checkbox/Pflichtdialog)
- Optional Domain/E-Mail; kein Pflicht-Salesforce/Meridian
- Clear/Wechsel; Agentur entfernen; RE konsistent
- Bestandsschutz historischer Freitext-Calcs (serverseitig am gespeicherten Zustand)
- Preview legt keine Accounts an; wiederholter Save ohne Duplikat-Provisional
- Standardangebot→Calc: Freitext-Übernahme erzeugt Provisional (kein SA-UI-Ausbau)
- E2E `playwright.crm-wizard-picker.config.ts` Port **8064**, CI in `tests.yml`
- BL-P2-03a E2E an Picker angepasst

## Payload-Vertrag

| Payload | Bedeutung |
|---|---|
| fehlender `*_account_id` | Legacy-/Teilpfad: Name ohne Auto-Provisional |
| `*_account_id` = int | explizite Account-Auswahl |
| `*_account_id` = null + Name (+ ggf. `*_create_provisional`) | Freitext; neu/Wechsel → Provisional; historischer Freitext ohne Create-Flag bleibt unlinked |
| `*_account_id` = null + leerer Name | Seite leeren (Agentur) |

## Nicht in diesem Slice

Calc-Isolationsbanner, Stammdaten-Textsuche außerhalb Picker, `CRM-004`,
weitere Methoden/SWF, Deploy, operative DB-Mutation.

## Tests

- Pest: `CrmWizardPickerBindingTest`
- E2E: `npm run test:e2e:crm-wizard-picker` (`--retries=0`)
- Regression: angepasste BL-P2-03a / Spot-/Trailer-/Produktions-E2E-Selektoren
