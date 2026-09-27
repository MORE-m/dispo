# Smoke BL-P4-03d – Frozen Persistenzvertrag (Publish → Adopt → Bearbeiten)

**Datum:** 2026-09-27  
**Port:** `http://127.0.0.1:8047`  
**Worktree:** `dispo-wt-bl-p4-03d` (isoliert, SQLite `database/smoke-bl-p4-03d.sqlite`)  
**Testdaten:** `E2ESpotDistributionExportSeeder`  
**Feature-HEAD (Smoke):** `3d8143f6ff55b17c2aa48ca1ef91860e82871dea`

## Ablauf

| Schritt | Rolle | Sichtbare Werte | Ergebnis |
|--------|-------|-----------------|----------|
| Publish (Writer + UI-Detail) | PM | `SA-2026-00002` · v1 · Veröffentlicht; Freeze 1 Position, N/N **600.00** | OK |
| Liste / Detail published | PM | Titel „Smoke 03d Publish-Adopt“ | OK |
| Übernehmen | Vertrieb | Kunde `Smoke 03d Adopt GmbH`, Kampagne `Adopt Kampagne 03d` → `K-2026-00007` | OK |
| Bearbeiten + Speichern | Vertrieb | Kampagne → `Adopt Kampagne 03d bearbeitet`; Wizard editierbar; Position RH 10 Spots / 600,00 € | OK (`smoke-sales-adopted-edit.png`) |
| Vorlage nach Edit | DB | Published bleibt; Frozen N/N 600.00; Calc `lock_version=2`, `origin=2` | OK |

## Verifiziert

- Adopt über `FrozenCalculationPersistenceContract` (kein Live-`create()`)
- Übernommene Kalkulation normal speicherbar (STD-006)
- Vorlage unverändert (STD-005)

## Grenzen

- UI-Wizard-Publish-Klick nicht separat durchgespielt (Publish über denselben Writer-Pfad wie die UI; Detail-Ansicht published im Browser bestätigt)
- Calendar/Festpreis/Tandem weiter außerhalb Scope
