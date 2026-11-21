# EPIC-03-B – Staging-Pilot Runbook

**STOP:** Nur nach Merge + Post-Merge-CI + ausdrücklicher PO-Freigabe für Phase 3 ausführen.

## Voraussetzungen

- Staging erreichbar, Backup frisch (`/files/dispo-staging/backups/`)
- Staging-Admin bleibt erhalten
- Export von lokalem `dispo_mat_core` (read-only)

## Ablauf

1. **Backup Staging-DB** nach `/files/dispo-staging/backups/` (mode 600, außerhalb Webroot).
2. **Export lokal:**
   ```bash
   php artisan dispo:export-master-data --path=./storage/app/mdt-export --git-sha=$(git rev-parse HEAD)
   ```
3. SHA256/`manifest.json` prüfen; Paket ohne Secrets.
4. Paket sicher nach Staging übertragen (nicht ins Document Root).
5. **Dry-Run:**
   ```bash
   php artisan dispo:import-master-data /pfad/paket --dry-run --confirm-database=spn26jz7_dispo_staging
   ```
6. Report prüfen (creates/unchanged/conflicts).
7. **Apply** nur bei conflict=0 und PO-GO.
8. RH-Fachprüfung Draft-Preise (keine Activate).
9. Erst nach PO: Aktivierung (eigener Auftrag), dann Calc→Dispo Smoke.

## Rollback

- DB-Restore aus Pre-Import-Dump
- Kein Full-Dump als Importweg

## Abnahme RH (nach Activate, nicht in diesem Slice)

Siehe EPIC-03-A/B Auftrag: Inventar, Werbespot, average, Preise 2026/2027, speichern, Dispoauftrag, Preisbindung.
