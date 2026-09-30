# PO-MAT-CORE-CATALOG-1 – Initialkatalog Inventare/Werbemittel (BL-P2-02c)

Status: **Akzeptiert**  
Stand: 30. September 2026  
Basis: PR #109 HEAD `dc8eaa9416de71fe9e5062bd74498fb65e3de374`  
IDs: `BL-P2-02c`, `ORG-001`–`ORG-003`, `ADV-001`, `MAT-001`–`MAT-003`, `PO-MAT-CORE-MATRIX-1`

## Entscheidung

Kontrollierter Bootstrap der **14 Inventare** und **42 Werbemittel** mit freigegebenen Codes, Typen und Oberkategorien, damit der Matrix-Import (#109) exakte Namen matchen kann.

- Werbemittel-Namen folgen der **Excel-Matrix** (u. a. `Sondersendung (4x90Sek)`, `Mid-Roll Spotify / Deezer / Youtube Musikumfeld`).
- Inventare mit „Kombi“ im Namen: `type=kombi`, sonst `sender`.
- Organisation: bestehende Singleton-Org bzw. `more Marketing`.
- `kind=spot_classic` nur für `spot_classic`, `spot_tandem`, `spot_tridem`; alle übrigen neuen Medien `kind=null`.
- `calculation_method_mode=inherit`, keine Methoden-Zuordnungen.
- Social (Facebook/Instagram/Instagram Influencer/TikTok): nicht rabattier-/AE-fähig.
- `component_profile` nur Tandem/Tridem.
- Idempotent; Identitätskonflikte fail-closed ohne Überschreiben.
- **Keine** Kombinationstabellen-Regeln in diesem Slice.

Vollständige Mapping-Tabellen: Auftrag BL-P2-02c / `App\Support\InventoryMediumRule\Catalog\InitialCatalogDefinitions`.

## Aufruf (isoliert)

```bash
php artisan db:seed --class=InitialCatalogMatCoreSeeder
php artisan db:seed --class=CombinationMatrixMatCoreSeeder
```

Nicht in `DatabaseSeeder` eingehängt. Nicht auf shared/Staging/Prod ausführen.

## Nicht freigegeben

- Merge/Deploy
- Überschreiben vorhandener abweichender Stammdaten
- Matrix-Seed (#109) in diesem Slice
- MAT-004 Admin-Matrixwerkzeuge
