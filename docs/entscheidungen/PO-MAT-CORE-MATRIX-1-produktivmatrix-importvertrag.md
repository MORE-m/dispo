# PO-MAT-CORE-MATRIX-1 – Produktivmatrix Importvertrag (BL-P2-02a)

Status: **Akzeptiert**  
Stand: 30. September 2026  
Basis-Branch: `feat/bl-p2-02a-mat-core-combination-table` @ `dbff78bac6365a557397c0861850c84f8680fc4f`  
Quelle (Repo): `database/data/Dispositionsauftrag_Spots_und_SWF_Alle_Sender_2026.xlsx`  
(ursprünglich: `Dispositionsauftrag_Spots und SWF_Alle Sender_2026.xlsx`)  
Blätter: `Buchungskenn durch Kombitabelle`, `Einplanung durch Kombitabelle`  
Bezug: `MAT-001`–`MAT-003`, `PO-BLP202A-1`, `docs/initialdaten.md`, `docs/anforderungskatalog.md` § 6.2–6.3

## Entscheidung

Für den kontrollierten Import der Kombinationstabelle gilt:

1. **Leere Buchungszelle** → die Kombination **existiert nicht** (keine `inventory_medium_rules`-Zeile).
2. **`darf nicht geplant werden`** → ebenfalls **keine** Kombination (wie leere Buchungszelle); kein Anlegen einer aktiven Regel mit `must_not_plan` aus dieser Matrix.
3. **34 Einplanungszeilen** mit planbarem Wert ohne Buchungskennzeichen → **verwerfen**.
4. **`Pre-/In-Stream`** und **`Pre-/In-Stream Influencer`** → entfallen weiterhin; nicht importieren (auch wenn befüllt).
5. **Buchungszellenwerte** der Matrix **sind** die `booking_code`-Werte (z. B. `Spots (L)`, `UC`, `U (Online SWF)`); nicht still auf Kurzcode kürzen.
6. Inventarname **`MORE Hamburg-Kombi+`** ist kanonisch (Excel), nicht `Hamburg-Kombi+`.
7. **`hint_text`** kommt **nicht** aus den beiden Kombitabelle-Blättern. Hinweise liegen verstreut im Blatt `Dispoauftrag` und werden über Regeln sichtbar; Matrix-Import ohne Hinweistexte.
8. **`MORE Hamburg-Kombi+` / `Single-Spot`** mit Matrix `Spots (L)` + `Disposition` ist ein **Quellfehler**. Single-Spot für Kombi+ ist **nicht** planbar (`MAT-003` / `initialdaten.md`); **keine** Regel anlegen. Entsprechend Single-Spot-Ausschluss für `RADIO BOLLERWAGEN DAB+ Hamburg` und `ffn Hamburg Plus`.

Doppelte `Pre-Stream`-/`In-Stream`-Zeilen in der Buchungsmatrix (identische Werte) werden beim Import auf einen fachlichen Schlüssel verdichtet.

## Folgen

- Admin-Fähigkeit `must_not_plan` in PR #108 bleibt als Stammdatum/Runtime-Vertrag bestehen; dieser Importvertrag legt dafür **keine** Matrix-Zeilen an.
- Implementierung: Slice **BL-P2-02b** (`CombinationMatrixWorkbookParser` / `CombinationMatrixImporter` / `CombinationMatrixMatCoreSeeder`), eigener Draft-PR, Basis PR-#108-HEAD.
- Aufruf: `php artisan db:seed --class=CombinationMatrixMatCoreSeeder` (nicht in `DatabaseSeeder`).
- Inventare/Werbemittel müssen zuvor mit **exakten Excel-Namen** aktiv existieren (fail-closed).
- `docs/initialdaten.md` Inventarliste: Eintrag 2 auf `MORE Hamburg-Kombi+` angeglichen.

## Nicht freigegeben

- MAT-004 Admin-Massenimport / Matrixwerkzeuge
- Erfinden fehlender Hinweistexte
- Übernahme der kombinierten Pre-/In-Stream-Zeilen
- Planbarkeit von Single-Spot auf Kombi+ / Bollerwagen / ffn entgegen MAT-003
- Automatisches Löschen fremder/admin-angelegter Regeln außerhalb des Desired-State
