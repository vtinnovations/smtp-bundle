# vtinnovations/smtp-bundle

Contao-5-Bundle zur SMTP-Konfiguration direkt im Backend. Testet die Verbindung vor dem Speichern, schreibt die `MAILER_DSN` in `.env.local` und leert danach sicher den Cache.

---

## Funktionsumfang

- **Backend-Modul** unter *System → SMTP-Konfiguration*
- Felder: Host, Port, Verschlüsselung (Keine / STARTTLS / SSL-TLS), Benutzername, Passwort, Absender-E-Mail, Test-Empfänger
- Sendet eine Test-Mail und schreibt die `MAILER_DSN` erst bei Erfolg
- Cache-Leerung über Maintenance-Modus (separater PHP-Subprozess, kein HTTP-Timeout)
- **Lizenzpflichtiges Plugin** – Aktivierung über [v-t.one](https://v-t.one)

---

## Voraussetzungen

| Komponente | Version |
|------------|---------|
| PHP | 8.2+ |
| Contao | 5.3+ |
| Symfony | 7.x |

---

## Installation

```bash
composer require vtinnovations/smtp-bundle
```

Danach Migration und Cache leeren:

```bash
bin/console contao:migrate
bin/console cache:clear
```

---

## Lizenz aktivieren

Das Bundle benötigt eine gültige Lizenz von [v-t.one](https://v-t.one) (Produkt: `vt-smtp`).

1. Backend öffnen → *System → SMTP-Konfiguration*
2. Lizenzschlüssel im Format `XXXXX-XXXXX-XXXXX-XXXXX` eingeben
3. **„Lizenz aktivieren"** klicken

Der Schlüssel wird gegen den Lizenzserver geprüft und mit der aktuellen Domain verknüpft. Das Ergebnis wird in `var/smtp-bundle/license.json` gespeichert (7-Tage-Grace-Periode, tägliche Hintergrund-Überprüfung).

### Lizenz deaktivieren

Lizenzdatei löschen:

```bash
rm var/smtp-bundle/license.json
```

Danach zeigt das Backend-Modul wieder das Lizenzformular.

---

## Konfiguration (optional)

```yaml
# config/packages/vtinnovations_smtp.yaml
vtinnovations_smtp:
    php_binary: 'php'        # PHP-Interpreter für den Cache-Subprozess (Standard: php)
    process_timeout: 120     # Timeout in Sekunden (Standard: 120)
```

Diese Optionen sind nur relevant, wenn PHP nicht im `PATH` liegt oder der Cache-Befehl länger dauert (z. B. bei großen Installationen).

---

## Nutzung im Backend

1. *System → SMTP-Konfiguration* öffnen (nur Administratoren)
2. SMTP-Zugangsdaten eintragen
3. Test-Empfänger-Adresse angeben
4. **„Testen & Speichern"** klicken

Das Bundle sendet eine Test-Mail an den angegebenen Empfänger. Schlägt der Versand fehl, wird die bestehende Konfiguration **nicht** überschrieben. Bei Erfolg wird `MAILER_DSN` in `.env.local` geschrieben und der Cache automatisch geleert.

**Passwort:** Das Passwortfeld leer lassen, um das bestehende Passwort beizubehalten.

---

## CLI-Befehl

```bash
# SMTP-Konfiguration deaktivieren (setzt MAILER_DSN zurück auf null://)
bin/console vtinnovations:smtp:disable
```

---

## Sicherheit

- Nur Contao-Administratoren haben Zugriff auf das Backend-Modul
- Lizenzprüfung via signiertem API-Aufruf gegen `https://www.v-t.one/api/v1/verify`
- Lizenzschlüssel werden lokal gecacht, nie im Browser exponiert

---

## Support & Lizenzerwerb

**V&T Innovations** – [v-t.one](https://www.v-t.one)  
E-Mail: info@v-t.one
