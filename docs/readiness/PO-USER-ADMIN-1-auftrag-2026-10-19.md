# PO-USER-ADMIN-1 – Minimale Benutzer-Admin-UI

Stand: 19. Oktober 2026 · Feature-Branch · Draft-PR · **kein** Merge · **kein** Deploy

## Ziel

Operative minimale Benutzerverwaltung für Go-Live Stufe 2 (Ausschnitt):

- Liste / Anlegen / Bearbeiten
- Rolle, Rabattgrenze (`discount_limit_percent`), Sonderfreigabe (`can_special_approve`), Aktivstatus (`is_active`)
- ausschließlich **Admin** (serverseitig)
- kein Hard Delete / Soft Delete / IAM-Ausbau

## Vertrag

- Rollen: bestehende Enum-Werte (`admin`, `sales`, `disposition`, `management`, `product_management`)
- Sonderfreigabe nur über bestehenden `SpecialApproveRightAdminWriter` (nur Vertrieb; kein Self-Change)
- Passwort: Laravel-Hash-Cast + `Password::defaults()` bei Create; Edit ändert Passwort nicht
- `null` bei Rabattgrenze = keine persönliche Grenze (unverändert)
- Inaktiv: Login blockiert + `EnsureUserIsActive` beendet bestehende Sessions

## Selbst-/Lockout-Hinweis

Keine neue Businessregel für „letzter Admin“ oder Selbst-Deaktivierung. Admin kann eigene Rolle/Aktivstatus ändern – Lockout-Risiko bewusst dokumentiert, nicht automatisiert abgefangen.

## Tests

- Feature `UserAdminTest`, MySQL `UserAdminMysqlTest`
- Vitest Form-Smoke
- E2E `playwright.po-user-admin-1.config.ts` (Port 8028)

## Scope OUT

Delete, Invite-Mail, SSO, Teams, Contest, Event-Tipp, Deploy, Merge.
