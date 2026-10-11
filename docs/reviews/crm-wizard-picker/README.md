# Review: CRM-Wizard-Picker (PO-BLP203-1 I1)

Stand: 11. Oktober 2026  
Basis: `origin/main` @ `d4ff19607ebcb4a45f6381c40d9a568ed8257e27`  
Branch: `feat/crm-wizard-picker`  
Draft-PR: [#136](https://github.com/MORE-m/dispo/pull/136)

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

## Review-Nachzug (Befunde P1/P2)

| Befund | Fix |
|---|---|
| P1 Picker-State nach Save | `onSuccess` synchronisiert Picker/RE/Meridian aus Server-Props trotz `preserveState` |
| P1 Nicht mitgesendete Seite | Binder erhält unberührte Kunden-/Agenturseite vollständig |
| P2 Neuanlage ohne Account-Key | Name ohne `*_account_id` erzeugt Provisional; Historie nur am gespeicherten Zustand |
| P2 Veraltete Suche | Tippen/Clear/Auswahl invalidiert Seq, Treffer und Loading |

## Payload-Vertrag

| Payload | Bedeutung |
|---|---|
| Seite nicht mitgesendet | bestehende Bindung / historischer Freitext erhalten |
| fehlender `*_account_id` + Name | Neuanlage → Provisional; Historie → unlinked; gebunden → Identität erhalten |
| `*_account_id` = int | explizite Account-Auswahl |
| `*_account_id` = null + Name (+ ggf. `*_create_provisional`) | Freitext; neu/Wechsel → Provisional; historisch ohne Create-Flag bleibt unlinked |
| `*_account_id` = null + leerer Name | Seite leeren (Agentur) |

## Nicht in diesem Slice

Calc-Isolationsbanner, Stammdaten-Textsuche außerhalb Picker, `CRM-004`,
weitere Methoden/SWF, Deploy, operative DB-Mutation.

## Tests

- Pest: `CrmWizardPickerBindingTest` (inkl. Untouch-Seiten, Rollback, Rechte, Neuanlage ohne Key)
- E2E: `npm run test:e2e:crm-wizard-picker` (`--retries=0`; Idempotenz ohne Reload, stale search)
- Regression: angepasste BL-P2-03a / Spot-/Trailer-/Produktions-E2E-Selektoren
