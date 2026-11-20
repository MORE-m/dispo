# Staging-Mail-Empfänger-Guard (EPIC-01-MAIL-1)

Zentrale serverseitige Empfängerbeschränkung für SpeedIt-Staging.

## Konfiguration

| Variable | Werte | Default |
|---|---|---|
| `DISPO_MAIL_RECIPIENT_GUARD` | `off` \| `allowlist` (alles andere = invalid) | `off` |
| `DISPO_MAIL_RECIPIENT_ALLOWLIST` | Komma-getrennte **exakte** E-Mail-Adressen | leer |

Config-Spiegel: `config/dispo.php` → `mail_recipient_guard`, `mail_recipient_allowlist`.

### Allowlist-Syntax

- Nur vollständige Adressen (`name@domain.tld`).
- Normalisierung: Trim + Lowercase.
- **Keine** Domain-Wildcards (`*@firma.de` ist ungültig und fail-closed).
- Leere Liste bei Mode `allowlist` = **gesamter Versand gesperrt**.
- Ein ungültiger Eintrag in der Liste = gesamte Allowlist ungültig = gesperrt.

### Aktivierung

- **Produktion:** Guard `off` lassen – kein Versandblock.
- **Staging mit Test-SMTP:** `APP_ENV=staging`, Guard **`allowlist`** und Allowlist setzen **bevor** `MAIL_MAILER=smtp`.
- Staging + Live-Mailer (`MAIL_MAILER` nicht `log`/`array`) **ohne** gültiges `allowlist` = Versand gesperrt (auch bei `off`, leerem Wert oder Tippfehler).
- Empfohlenes Staging-Minimum:

```env
APP_ENV=staging
MAIL_MAILER=smtp
MAIL_FROM_ADDRESS=no-reply@dispo.more-marketing.de
DISPO_MAIL_RECIPIENT_GUARD=allowlist
DISPO_MAIL_RECIPIENT_ALLOWLIST=niklas@example.test,po@example.test
```

## Technik

- Listener `EnforceStagingMailRecipientAllowlist` auf `Illuminate\Mail\Events\MessageSending`.
- Greift alle Laravel-Mail-Pfade: Mailable, Notification-MailChannel (Passwort-Reset), Outbox → `Mail::send`.
- Prüft To, Cc und Bcc; bei Verstoß Exception (nicht `return false`), damit Aufrufer keinen Erfolg annehmen.
- Outbox: `StagingMailRecipientBlockedException` → Status `failed` **ohne** Retry (konfigurativ, nicht transient).
- Logs: Warning ohne Betreff/Body; nur Zähler und Mode.

## Abgedeckte Pfade

| Pfad | Abgedeckt |
|---|---|
| `SalesInquiryOutboxMail` / Outbox-Delivery / Queue-Job | ja |
| Fortify Passwort-Reset (`ResetPassword` Notification) | ja |
| Zukünftige `Mail::` / Mailables über Laravel Mailer | ja |
| Direkte Symfony-Transport-Aufrufe außerhalb Laravel | nicht vorhanden; würden Guard umgehen |

## Fail-closed / Fehlverhalten

| Situation | Ergebnis |
|---|---|
| Produktion, Guard `off` | Kein Eingriff |
| Staging + `log`/`array`, Guard `off` | Kein Eingriff (kein Live-Transport) |
| Staging + Live-Mailer, Guard `off` / fehlend / invalid | Exception, kein Transport |
| Guard `allowlist`, leere Liste | Exception, kein Transport |
| Guard `allowlist`, ungültige Config | Exception |
| Gemischte Empfänger / Cc / Bcc nicht erlaubt | Gesamte Nachricht blockiert |
| Outbox blockiert | `failed`, kein `sent` |
| Passwort-Reset an Nicht-Allowlist | Exception (HTTP-Fehler) – Staging-User müssen Allowlist-Adressen nutzen |

## Testverfahren (CI)

Pest: `tests/Feature/Mail/StagingMailRecipientGuardTest.php` mit Test-Transport `array` (kein Netzwerk). Staging+SMTP-Fälle setzen `mail.default=smtp` nur zur Erkennung; der Versand läuft über den `array`-Mailer.

## Staging-SMTP-Smoke (nach Deploy, manuell)

1. Guard `allowlist` + bekannte Tester-Adresse.
2. Passwort-Reset an Allowlist-Adresse → Mail kommt an.
3. Outbox-Test an Allowlist → `sent`.
4. Kurz Allowlist ohne Zieladresse → Versand `failed` / Exception; **kein** Versand an Dritte.
5. Logs prüfen: keine Mail-Bodies.

## Rollback

Bei Guard-Problemen **zuerst** den Live-Versand stoppen:

1. **`MAIL_MAILER=log`** setzen und Config-Cache neu bauen (`php artisan config:clear` / `config:cache`).
2. Guard und Allowlist korrigieren (`DISPO_MAIL_RECIPIENT_GUARD=allowlist` + gültige Adressen).
3. Erst danach wieder `MAIL_MAILER=smtp`.

**Nicht:** bei weiterhin aktivem SMTP nur den Guard auf `off` stellen – Staging sperrt das fail-closed, und es wäre unsicher.

Kein DB-Rollback nötig (keine Migration).
