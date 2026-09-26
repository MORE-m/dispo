# Dark-Mode-Inventar (Feature-Branch)

Stand: nach Kontrast-Nachzug Primary `oklch(0.55 0.17 42)`.

## Echte Fehler (behoben in diesem PR)

| Fundstelle | Auswirkung | Entscheidung |
| --- | --- | --- |
| `ui/input.tsx` `bg-white` + `text-foreground` | Hellter Text auf weißem Feld (Login u. a.) | `dark:bg-input/30` |
| `form-field.tsx` Select/Textarea `bg-white` | gleich | analog |
| Dark-Tokens Primary früher weiß / Sidebar-FG = Primary | Marke verloren / Kontrast 0 | Marken-Primary, FG weiß |
| Dark-Primary `oklch(0.7 0.19 42)` + weiße Label | ~2.9:1, unter UI-/Textkontrast | abdunkeln auf `oklch(0.55 0.17 42)` (~5.2:1) |
| Autofill ohne Override | helles UA-Feld, ggf. heller Text | Base-Styles in `app.css` |
| `dialog.tsx` `bg-background` | Dialog verschwindet gegen Seite | `bg-card` |
| `auth-split-layout.tsx` Logo `text-black` ohne Dark | schwarzes Logo auf dunklem BG (Mobile) | `dark:text-white` |

## Bewusst hell / kein Fix (lesbar als Chip oder bereits mit `dark:`)

| Fundstelle | Auswirkung | Entscheidung |
| --- | --- | --- |
| `input.tsx` / `form-field` weiterhin `bg-white` im Light Mode | Hellmodus-Kontrast gegen grauen Page-BG | belassen |
| `appearance-tabs.tsx` aktiver Tab `bg-white` + `dark:bg-neutral-700` | korrekt | belassen |
| `welcome.tsx` feste Farben + `dark:`-Paare | Marketing-Landing | belassen |
| `app-header.tsx` Active-Underline `bg-black dark:bg-white` | korrekt | belassen |
| `dispo-order-status-badge.tsx` Pastell-Chips ohne `dark:` | dunkler Text auf hellem Chip; auf dunkler Seite auffällig, aber lesbar | belassen (Chip-Muster) |
| `price-lists/import.tsx` `bg-red-50` / `bg-green-50` ohne `dark:` | Alert-Boxen hell, Text dunkel auf Box | belassen |
| `field-set-rules-editor.tsx` Amber-Hinweis ohne `dark:` | analog Chip/Alert | belassen |
| Katalog/Assignments Amber/Red mit `dark:` | bereits abgesichert | belassen |

## Keine Inline-`style={{ background… }}` in `resources/js` gefunden.
