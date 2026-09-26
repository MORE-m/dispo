# Dark-Mode-Abnahme (2026-09-26)

Base: `da9db1fd126215a3a4cecb98c93d5cf47b416727` (`origin/main`)
Branch: `fix/dark-mode-form-contrast`
Worktree: `dispo-wt-dark-mode` @ Port 8040

## Ursache Login

`Input` hatte hartes `bg-white` und `text-foreground`. Im Dark Mode ist `--foreground` hell → heller Text auf weißem Feld. Der frühere `dark:bg-input`-Versuch war nie auf dem laufenden `main`/Port 8000.

Zusätzlich: Dark-Tokens invertierten Primary (weiß) und setzten Sidebar-Primary-Foreground identisch zum Hintergrund (Kontrast 0).

## Testmatrix

| Zustand | Ergebnis |
| --- | --- |
| Login Dark, Text in E-Mail/Passwort | OK (hell auf `dark:bg-input/30`) |
| Login Light, Text in E-Mail | OK (dunkel auf weiß) |
| Profile-Form Dark | OK |
| Dispoauftrag-Detail inkl. Textareas Dark | OK |
| Bestätigungsdialog Dark | OK (Primary Markenorange) |
| Appearance Light/Dark/System | OK umschaltbar |
| Autofill Dark | CSS-Fix hinterlegt; manuell mit Browser-Autofill nachziehen |
| Passwort-Reset-Seite | nicht separat im Browser geöffnet (gleiche Input-Komponente) |

## Lokale Checks

- `npm run types:check` grün
- `npm run check:fix` grün
- Keine volle PHPUnit/Playwright/CI-Suite (kein neues Regressionsrisiko außerhalb UI-Tokens)
