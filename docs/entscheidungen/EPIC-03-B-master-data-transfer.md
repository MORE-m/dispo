# EPIC-03-B – Selektiver Stammdaten-/Preislisten-Transfer

- **Status:** Auf `main` (PR #263). Radio-Hamburg-Pilot auf Staging abgenommen bis Dispo-Entwurf, siehe `docs/readiness/EPIC-03-CD-staging-pilot-abschluss-2026-11-23.md`. Nächster Inventar-Rollout: **STOP** bis neues PO-GO.
- **Datum:** 2026-11-20
- **Basis:** `32852af` + EPIC-03-A Readiness
- **PO-Scope:** 8 Spot-Inventare, Produktionspreise, Draft-Import, kein Online Audio

## Vertrag

| Punkt | Festlegung |
|---|---|
| Format | `dispo-master-data-transfer` / Schema `1.0.0` |
| Schlüssel | `inventories.code`, `advertising_media.code`, `advertising_categories.key`, `calculation_methods.key`, Preise `(inventory_code, year, revision)` |
| Preisstatus | Export speichert `import_status=draft`; Import erzeugt nur Drafts; keine Auto-Aktivierung |
| OA | ausgeschlossen (`includes_online_audio=false`, Engine `oa_tkp` nicht importiert) |
| Inventare | nur `SPOT_INVENTORY_CODES` (8 Codes); Export `--inventory` und Import-Guard fail-closed |
| Preisjahre | nur `2026`/`2027` für Spot- und Produktionspreislisten (Export + Import) |
| Kategorien | nur `spots` + `special_advertising_formats` (Social/Events/Barter nicht im PO-Scope) |
| Bewegungsdaten | Users, CRM, Calcs, Dispo, Jobs, Outbox, Imports – nicht im Paket |
| Guard | `--confirm-database`; Apply gegen `dispo_mat_core` / `dispo` blockiert; Manifest↔Daten konsistent |

## CLI

```bash
php artisan dispo:export-master-data --path=/tmp/pkg --git-sha=$(git rev-parse HEAD)
php artisan dispo:import-master-data /tmp/pkg --dry-run --confirm-database="$DB_DATABASE"
php artisan dispo:import-master-data /tmp/pkg --apply --confirm-database="$DB_DATABASE"
```

## Importreihenfolge

Organisation → Berechnungsmethoden (nur Abgleich) → Kategorien → Inventare → Medien → Kategorie-/Medium-Methoden → Regeln → Spotpreis-Drafts → Produktionspreis-Drafts

## Aktivierung (separater PO-Schritt)

Nicht Teil dieses Slices. Nach Staging-Pilot und RH-Fachprüfung gesondert freigeben.
