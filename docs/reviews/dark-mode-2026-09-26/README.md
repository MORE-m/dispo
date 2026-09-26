# Dark-Mode-Abnahme (2026-09-26)

Base: `3d0789be34a5a6c357f2e9c8822ceb629611ee05` (`origin/main`, nach Merge PR #89)
Branch: `fix/dark-mode-form-contrast` (rebase auf aktuelles `main`)
Worktree: `dispo-wt-dark-mode` @ Port 8040

Siehe auch `INVENTAR.md` (helle Festfarben: Fehler vs. bewusst).

## Ursache Login

`Input` hatte hartes `bg-white` und `text-foreground`. Im Dark Mode ist `--foreground` hell → heller Text auf weißem Feld.

## Kontrast (gemessen im Browser nach Token-Nachzug)

| Paar | Verhältnis | Ziel |
| --- | --- | --- |
| Weiß auf Dark-Primary `oklch(0.55 0.17 42)` | **~5.23:1** | ≥ 4.5:1 |
| Weiß auf Destructive `oklch(0.55 0.2 25)` | **~5.4:1** (rechnerisch) | ≥ 4.5:1 |
| Foreground auf Autofill-Mix (Dark) | **~17:1** (rechnerisch) | OK |
| Vorher Dark-Primary `oklch(0.7 0.19 42)` | ~2.9:1 | unzureichend → angepasst |

Light-Mode-Primary (`oklch(0.62 0.21 42)` + Weiß ≈ 4.0:1) unverändert belassen (kein Teil des neuen Dark-Orange).

## Testmatrix

| Zustand | Ergebnis |
| --- | --- |
| Login Dark, Text in E-Mail/Passwort | OK |
| Login Light | OK |
| Forgot Password Dark, E-Mail-Feld + Primary-Button | OK (separat geöffnet) |
| Profile-Form Dark | OK |
| Kalkulationen-Tabelle Dark | OK |
| Kalkulations-Wizard Grunddaten (Inputs/Textarea) Dark | OK |
| Wizard Werbeelemente Selects (Preisjahr u. a.) Dark | OK |
| Dispoauftrag-Detail + Textareas + Dialog Dark | OK |
| Appearance Light/Dark/System | OK |
| Autofill Dark | CSS hinterlegt; echtes Browser-Autofill manuell nachziehen |
| Status-Badges (helle Pastell-Chips) | bewusst hell, Text auf Chip lesbar |
| Reset-Password mit Token | nicht geöffnet (nur Forgot Password) |

## Lokale Checks

- `npm run types:check`
- `npm run check:fix`
