# Smoke BL-P4-03b – Kalkulation als Standardangebot speichern

**Datum:** 2026-09-27 (vollständiger manueller Browser-Smoke)  
**Port:** `http://127.0.0.1:8046`  
**Worktree:** `dispo-wt-bl-p4-03b` (isoliert, SQLite `database/smoke-bl-p4-03b.sqlite`)  
**Testdaten:** `E2ESpotDistributionExportSeeder` + Kampagne/Briefing auf `K-2026-00004`  
**Getesteter Produkt-HEAD vor Fix:** `f58b2d66976a30dbc44c4a26feba0152855d5569`  
**Smoke-Abschluss-HEAD:** `e5e02b1d282ab0cc2889e76c1439c49263340291`

## Rollen / Konten

| Rolle | Login | Sichtbar u. a. |
|------|-------|----------------|
| Vertrieb | `sales@example.com` / `password` (`E2E Vertrieb`) | Kalkulationen, Propose, Adopt |
| PM | `pm@example.com` / `password` (`E2E PM`) | Standardangebote; **keine** Kalkulationen-Nav; Quelle `K-2026-00004` → **403** |

## Ablauf (Browser)

| Schritt | Rolle | Sichtbare Werte | Ergebnis | Screenshot |
|--------|-------|-----------------|----------|------------|
| Calendar `K-2026-00001` Propose | Vertrieb | Fehlerbanner: „Position 1: Methode „calendar“ wird nicht unterstützt (nur Spot Classic Average).“ | Ablehnung verständlich | `smoke-sales-calendar-rejected.png` |
| Average `K-2026-00004` öffnen | Vertrieb | Kunde `SPT008 Average GmbH`; Kampagne `Smoke Kampagne Kunde XYZ`; Briefing `Briefing mit Kundennamen ABC` | OK | `smoke-sales-average-before-propose.png` |
| Als Standardangebot speichern | Vertrieb | Flash: „Vorschlag SA-2026-00001 als Standardangebot-Entwurf angelegt…“ | Draft `SA-2026-00001`, Titel „Vorschlag aus K-2026-00004“ | `smoke-sales-propose-success.png` |
| PM Übersicht | PM | „Keine Kalkulationen“ | Kein Calc-Zugang | `smoke-pm-no-calculations.png` |
| SA-Liste | PM | `SA-2026-00001` · Entwurf ja · Veröffentlicht – | OK | `smoke-pm-list.png` |
| Draft öffnen | PM | Prüfstufe: Feldnamen `campaign`, `briefing`; Aufforderung manuell kundenfrei ergänzen; **keine** Quelltexte | OK | `smoke-pm-proposal-review.png` |
| Quellkalkulation öffnen | PM | `403` / unauthorized | OK | `smoke-pm-source-calc-403.png` |
| Speichern ohne Bestätigung | PM | Titel `Smoke Vorlage Average`, Kampagne `Kundenfreie Vorlagenkampagne`; Prüfstufe bleibt (`ack=null`, `review_required=true`) | Prüfpflicht bleibt | `smoke-pm-save-still-review.png` |
| Veröffentlichen ohne Bestätigung | PM | Fehler: „Die Freitext-Prüfung muss vor der Veröffentlichung ausdrücklich bestätigt werden.“; Status weiter draft | Abgelehnt | `smoke-pm-publish-without-ack.png` |
| Freitext-Prüfung bestätigt | PM | Flash: „Freitext-Prüfung bestätigt. Vorlage kann veröffentlicht werden.“ | OK | `smoke-pm-ack-done.png` |
| Veröffentlichen | PM | Detail: `SA-2026-00001 · v1 · Veröffentlicht`; Freeze 1 Position, N/N 600.00; kein Quell-Freitext-Leak | OK | `smoke-pm-published.png` |
| Übernehmen | Vertrieb | Formular Kunde Pflicht; Kunde `Adopt Smoke GmbH`, Kampagne `Adopt Kampagne` | Neue Calc `K-2026-00007` | `smoke-sales-adopt-form.png` |
| Adoptierte Calc bearbeiten | Vertrieb | Wizard editierbar; Kampagne → `Adopt Kampagne bearbeitet`; Speichern OK; Quelle `K-2026-00004` unverändert | OK | `smoke-sales-adopted-calc.png` |

## Kundendatenfreiheit (geprüft)

| Prüfpunkt | Ergebnis |
|-----------|----------|
| Quell-Freitexte nicht in `proposal_review` / Draft / Frozen | OK (nur Feldnamen; Leak der Quellstrings = nein) |
| PM sieht Quellinhalte nicht | OK |
| Speichern bestätigt Prüfung nicht | OK (Browser + DB) |
| Publish ohne ausdrückliche Bestätigung | OK abgelehnt (sichtbarer Fehler) |

## Fix während Smoke

Calendar-Ablehnung war serverseitig korrekt, im Wizard aber zunächst unsichtbar (Validation-Fehler ohne Banner/`onError`).  
Behoben: Propose-`onError` + Anzeige der Page-Validation im `ErrorState`. HTTP-Assert in `StandardOfferBlP403bTest` ergänzt.

## Grenzen / Folge

- Gemeinsamer Adopt-Hydrate-Pfad: Folgearbeit (Freeze zentral, Adopt-Listen separat)
- Calendar/Festpreis/Tandem: weiter abgelehnt
