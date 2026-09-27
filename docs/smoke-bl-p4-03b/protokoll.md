# Smoke BL-P4-03b – Kalkulation als Standardangebot speichern

**Datum:** 2026-09-27 (Nachzug Kundendaten-/Prüfstrecke)  
**Port:** `http://127.0.0.1:8046`  
**Worktree:** `dispo-wt-bl-p4-03b` (isoliert, SQLite `database/smoke-bl-p4-03b.sqlite`)  
**Testdaten:** `E2ESpotDistributionExportSeeder`  
**HEAD:** Feature-Branch nach Sanitize-/Ack-Nachzug

## Ablauf (Artisan + Browser)

| Schritt | Ergebnis | Nachweis |
|--------|----------|----------|
| Vertrieb Button an Calc | OK | Screenshot `smoke-sales-button-calendar.png` (früher) |
| Propose Average `K-2026-00004` mit Kampagne/Briefing | OK | `field_keys_requiring_review=["campaign","briefing"]`; `free_text` **fehlt**; Draft-Kampagne `null` |
| Publish ohne Ack | **blockiert** | Validation `proposal_review` |
| `pruefung-bestaetigen` dann Publish | OK | Status published |
| Adopt | OK | `K-2026-00007`, Quelle unverändert |

## Kundendatenfreiheit

| Prüfpunkt | Ergebnis |
|-----------|----------|
| Quell-Freitexte nicht in `proposal_review` / Draft / Frozen | OK |
| Nur Feldnamen in PM-Prüfstufe | OK |
| `source_calculation_id` nur technische FK | OK |
| Save bestätigt Prüfung **nicht** | Pest |
| Publish mit Ack-Flag im Publish-Body **nicht** ausreichend | Pest |

## Grenzen / Folge

- Gemeinsamer Adopt-Hydrate-Pfad: bewusst Folgearbeit (Freeze zentral, Adopt-Listen noch separat)
- Calendar/Festpreis/Tandem: weiter abgelehnt
