# Fehlerbehebung

## Lizenzstatus-Meldungen

Diese werden auf dem SMTP-Konfigurationsbildschirm und auf dem Lizenzbildschirm unter Contao →
Einstellungen angezeigt. Was jeder Zustand bedeutet, steht in [`Licensing.md`](Licensing.md);
dieser Abschnitt konzentriert sich darauf, was zu tun ist.

| Meldung | Vorgehen |
|---|---|
| Keine Lizenz. Schlüssel unter v-t.one anfordern und unten eintragen. | Lizenzschlüssel im Einstellungen-Abschnitt eingeben und "Lizenz prüfen und aktivieren" drücken. |
| Dieser Lizenzschlüssel ist für den Free-Plan dieses Produkts nicht gültig. | Prüfen, ob der Schlüssel für dieses Produkt vorgesehen ist. Bei V-T.ONE nachfragen, falls dies unerwartet ist. |
| Diese Lizenz ist für diese Installation nicht mehr aktiv. | Bei V-T.ONE den Status dieser Lizenz erfragen. Nach Klärung holt "Lizenz aktualisieren" den korrigierten Zustand. |
| Diese Lizenz ist für keine der auf dieser Installation konfigurierten Domains ausgestellt. | Das DNS-Feld der Startseiten und `vtinnovations_smtp.domains` mit der/den Domain(s) abgleichen, für die die Lizenz ausgestellt wurde. Das Fehlerhafte korrigieren, dann "Lizenz aktualisieren" drücken. |
| Die gespeicherte Lizenz konnte nicht geprüft werden. Bitte den Lizenzschlüssel erneut eintragen. | Lizenzschlüssel erneut eingeben und aktivieren. Dadurch wird die lokal gespeicherte Kopie durch eine frisch geprüfte ersetzt. |
| Diese Lizenz stammt aus einem älteren Format. Schlüssel erneut eintragen, um eine aktualisierte Fassung abzurufen. | Denselben Schlüssel erneut eingeben und aktivieren; weitere Schritte sind nicht nötig. |
| Diese Installation kann Lizenzen nicht prüfen: Es ist kein Prüfschlüssel vorhanden. | Das Bundle aus einem offiziellen Release neu installieren. Dies ist ein Problem der installierten Version, kein Problem, das ein Lizenzschlüssel lösen kann. |
| Für diese Installation ist keine Domain konfiguriert. | Das DNS-Feld einer Startseite setzen oder Hostnamen unter `vtinnovations_smtp.domains` in `config/config.yaml` ergänzen, dann aktivieren. |

## Der SMTP-Konfigurationsbildschirm zeigt "nicht lizenziert"

Das Modul verweist auf Contao → Einstellungen. Der obigen Aktivierungsanleitung folgen; das Modul
wird nutzbar, sobald die Installation einen lizenzierten Zustand meldet.

## Die Test-E-Mail schlägt beim Speichern fehl

Die Konfiguration wird nur gespeichert, wenn der Test erfolgreich ist. Bei einem Fehlschlag:

- Prüfen, ob Host und Port für den E-Mail-Anbieter korrekt sind und ob die gewählte
  Verschlüsselung (keine / STARTTLS / SSL/TLS) zu dem erwarteten Port passt. STARTTLS läuft
  typischerweise über Port 587, implizites SSL/TLS typischerweise über Port 465.
- Prüfen, ob der Server, auf dem diese Contao-Installation läuft, den SMTP-Host über diesen Port
  erreichen kann — ausgehende Verbindungen auf Mail-Ports werden von manchen Hosting-Firewalls
  blockiert.
- Falls Zugangsdaten erforderlich sind, prüfen, ob Benutzername und Passwort für dieses
  E-Mail-Konto korrekt sind. Das leer gelassene Passwortfeld behält das zuvor gespeicherte Passwort
  bei; es löscht es nicht.
- Die genaue, vom Mail-Transport zurückgegebene Fehlermeldung wird im Fehlerhinweis angezeigt und
  ist normalerweise der direkteste Weg, das Problem einzugrenzen (Authentifizierung abgelehnt,
  Verbindung verweigert, Zertifikatsproblem und Ähnliches).

## Cache-Leeren schlägt nach erfolgreicher Test-E-Mail fehl

Die Konfiguration selbst ist zu diesem Zeitpunkt bereits in `.env.local` gespeichert; nur der
Cache-Leer-Schritt ist fehlgeschlagen. Der Fehlerhinweis enthält die zugrunde liegende Meldung.
Cache manuell leeren:

```bash
bin/console cache:clear
```

Wenn die Meldung besagt, dass die PHP-CLI-Binärdatei nicht gefunden werden konnte, diese explizit
angeben:

```yaml
# config/config.yaml
vtinnovations_smtp:
    php_binary: '/pfad/zu/php'
    process_timeout: 120
```

Der korrekte Pfad ist die PHP-Kommandozeilen-Binärdatei auf dem Server (nicht die PHP-FPM- oder
Webserver-SAPI-Binärdatei). Übliche Orte sind `/usr/bin/php`, ein versionsspezifischer Pfad wie
`/usr/bin/php8.3` oder ein kontrollpanel-spezifischer Pfad unter `/opt/plesk/php/…` oder
`/opt/cpanel/ea-php…/root/usr/bin/php`. Falls keiner davon zutrifft, beim Hosting-Anbieter
nachfragen.

## Eine Domain mit nicht-ASCII-Zeichen lässt sich nicht aktivieren

`ext-intl` installieren oder aktivieren. Ohne diese Erweiterung kann ein solcher Hostname nicht auf
die exakte Schreibweise normalisiert werden, für die eine Lizenz signiert ist; die Aktivierung für
diesen Hostnamen wird abgelehnt statt erraten.

## Absender- oder Test-Empfängeradresse ist beim erneuten Aufruf des Bildschirms leer

Das ist erwartetes Verhalten: Diese beiden Felder werden nur zum Versand der Test-E-Mail verwendet
und nicht gespeichert. Sie müssen bei jedem Speichern des Formulars erneut eingegeben werden. Siehe
[Bekannte Einschränkungen](../README.md#bekannte-einschränkungen) im Haupt-README.

## `bin/console vtinnovations:smtp:disable` meldet, die Installation sei nicht verfügbar

Der Befehl erfordert eine lizenzierte Installation, genau wie jede andere Aktion in diesem Bundle.
Zunächst den Lizenzstatus klären (siehe Tabelle oben), dann den Befehl erneut ausführen.

## Verwandte Dokumente

- [`README.md`](../README.md) — Installation, Konfiguration und Funktionsüberblick.
- [`Licensing.md`](Licensing.md) — Lizenzzustände und Lebenszyklus im Detail.
- [`Security.md`](Security.md) — das Sicherheits-Assurance-Modell.
