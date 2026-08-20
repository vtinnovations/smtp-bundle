# vtinnovations/smtp-bundle

Ein Contao-5-Bundle, das Administratoren einen Backend-Bildschirm zur Konfiguration des
ausgehenden Mailversands der Website gibt. Es baut eine SMTP-Verbindung auf, testet sie, speichert
sie als `MAILER_DSN` in `.env.local` und leert danach sicher den Anwendungs-Cache. Das Bundle ist
lizenzpflichtig; eine Aktivierung gegen den Lizenzdienst von V-T.ONE ist erforderlich, bevor seine
Funktionen verfügbar werden.

**[English version of this file](README.en.md)**

## Status

Dies ist die aktuelle, native Implementierung des Bundles — kein Platzhalter, kein Teil-Port und
keine geplante zukünftige Phase. Die gesamte unten beschriebene Funktionalität ist implementiert
und wird durch eine automatisierte PHPUnit-Testsuite abgedeckt. Über die Nutzung in
Produktivumgebungen wird hier keine Aussage getroffen, die über das im Repository selbst
Nachweisbare hinausgeht.

## Inhalt

- [Voraussetzungen](#voraussetzungen)
- [Installation](#installation)
- [Backend-Zugriff und Navigation](#backend-zugriff-und-navigation)
- [Lizenzierung](#lizenzierung)
- [SMTP-Konfiguration](#smtp-konfiguration)
- [Konsolenbefehl](#konsolenbefehl)
- [Funktionsstatus](#funktionsstatus)
- [Sicherheitsmodell](#sicherheitsmodell)
- [Laufzeitverzeichnisse](#laufzeitverzeichnisse)
- [Externe Kommunikation](#externe-kommunikation)
- [Logging](#logging)
- [Deployment](#deployment)
- [Cache leeren](#cache-leeren)
- [Tests](#tests)
- [Fehlerbehebung](#fehlerbehebung)
- [Bekannte Einschränkungen](#bekannte-einschränkungen)
- [Lizenz und Urheberrecht](#lizenz-und-urheberrecht)

## Voraussetzungen

| | |
|---|---|
| PHP | 8.2 oder neuer |
| Erforderliche Erweiterungen | `ext-json`, `ext-sodium` (Prüfung der Lizenzsignatur) |
| Empfohlene Erweiterung | `ext-intl` — ohne sie können internationalisierte (nicht-ASCII) Domainnamen nicht auf die Schreibweise normalisiert werden, für die eine Lizenz signiert ist; eine solche Domain lässt sich dann nicht aktivieren |
| CMS | Contao 5.3 oder neuer (`contao/core-bundle: ^5.3`). Die Continuous-Integration-Pipeline prüft das Bundle zusätzlich gegen Contao 5.6 und 5.7. |
| Framework-Komponenten | Symfony 6.4 oder 7.x (`symfony/dotenv`, `event-dispatcher`, `http-client`, `http-foundation`, `http-kernel`, `mailer`, `process`), `doctrine/dbal` ^3.6 oder ^4.0 |
| Manager-Plugin | `contao/manager-plugin` ^2.0 |

## Installation

```bash
composer require vtinnovations/smtp-bundle
```

Das Bundle registriert sich selbst beim Contao Manager
(`Vtinnovations\SmtpBundle\ContaoManager\Plugin`) und wird nach dem Contao-Core-Bundle geladen. Es
fügt keine Datenbanktabellen oder -spalten hinzu, sodass durch dieses Paket selbst keine
Schema-Migration nötig wird. Nach der Installation oder Aktualisierung den Anwendungs-Cache leeren,
damit der Container die neuen Services und Routen übernimmt:

```bash
bin/console cache:clear
```

`bin/console contao:migrate` danach auszuführen bleibt allgemein gute Praxis für eine
Contao-Installation, ist aber durch dieses Bundle allein nicht erforderlich.

## Backend-Zugriff und Navigation

Alles, was dieses Bundle bereitstellt, ist ausschließlich im Backend; es fügt kein Frontend-Modul,
kein Inhaltselement und keine Seite hinzu.

| Ort | Bildschirm | Wer ihn nutzen kann |
|---|---|---|
| Contao → **Einstellungen** | Abschnitt "SMTP Konfigurator Licence management" (aktueller Zustand, Feld für den Lizenzschlüssel sowie die Aktionen Aktivieren / Aktualisieren / Entfernen) | Benutzer mit Zugriff auf `tl_settings` (standardmäßig Administratoren, wie in Contao üblich); die Aktionen selbst sind zusätzlich auf Administratoren beschränkt |
| Contao → **System** → **SMTP-Konfiguration** | Backend-Modul für den Mailer selbst (Host, Port, Verschlüsselung, Zugangsdaten, Absender, Test-Empfänger) | Nur Backend-Administratoren — das Modul prüft ausdrücklich `BackendUser::isAdmin` und verweigert jedem anderen Benutzer den Zugriff |

Die Lizenzverwaltung liegt bewusst in den Einstellungen und nicht im SMTP-Modul selbst: Mehrere
gemeinsam installierte V-T.ONE-Pakete lassen sich so von einer Stelle aus verwalten, ähnlich wie
andere contao-weite Konfiguration.

## Lizenzierung

Eine aktivierte Lizenz ist erforderlich, bevor das SMTP-Konfigurationsmodul, der
Test-/Versandweg des Mailers und das Leeren des Caches nutzbar werden. Der Versuch, eine dieser
Aktionen ohne erteilte Berechtigung auszuführen, wird verweigert — die Prüfung erfolgt bei jeder
Aktion selbst erneut, nicht nur beim Rendern des Backend-Bildschirms.

### Lizenzmodell

Diese Version wird für dieses Produkt unter V-T.ONEs Modell **"Lifetime Free"** ausgeliefert: Ein
gültiger Schlüssel aktiviert das Paket dauerhaft, ohne Ablaufdatum und ohne kostenpflichtige Stufe,
auf die aufgerüstet werden könnte. Ein Schlüssel, der zwar echt signiert ist, aber zu einem anderen
Modell gehört — zeitlich begrenzt, Testversion oder kostenpflichtig — wird für dieses Produkt
abgelehnt, obwohl er authentisch ist; es gibt in diesem Fall keinen lokalen Rückfall auf eine
geringere Berechtigung. Die Benutzeroberfläche zeigt weiterhin eine Kennzeichnung "Free" / "Pro",
die aus Konsistenzgründen mit anderen V-T.ONE-Produkten mit kostenpflichtiger Stufe erhalten
bleibt; für dieses konkrete Produkt ist derzeit jedoch keine kostenpflichtige Lizenz ausstellbar.

### Domain-Bindung

Eine Lizenz ist an einen oder mehrere exakte Hostnamen gebunden. `example.com`,
`www.example.com` und `shop.example.com` sind drei unterschiedliche Identitäten; eine für eine
davon ausgestellte Lizenz deckt die anderen nicht ab, und es findet kein Abgleich über Suffixe,
übergeordnete Domains oder eine `www.`-Gleichsetzung statt. Mehrere Domains auf einer Installation
werden unterstützt — eine Übereinstimmung zwischen den konfigurierten Hostnamen der Installation
und den in der Lizenz gebundenen Hostnamen reicht zur Aktivierung.

Die Hostnamen, die diese Installation für sich beansprucht, stammen ausschließlich aus der
Konfiguration, in dieser Reihenfolge, niemals aus einer eingehenden Anfrage:

1. das Feld **DNS** der Startseiten der Website;
2. `vtinnovations_smtp.domains` in der Anwendungskonfiguration;
3. der im Router konfigurierte Standard-Host, als letzte Möglichkeit.

Um die Domains explizit zu setzen:

```yaml
# config/config.yaml
vtinnovations_smtp:
    domains:
        - 'example.com'
        - 'www.example.com'
```

### Lizenz aktivieren, aktualisieren und entfernen

In Contao → Einstellungen → "SMTP Konfigurator Licence management":

- **Schlüssel eingeben und "Lizenz prüfen und aktivieren" drücken**. Die Installation prüft ihn
  sofort gegen den Lizenzdienst.
- **"Lizenz aktualisieren" drücken**, um eine aktuelle Fassung der bereits hinterlegten Lizenz
  abzurufen, ohne auf die automatische tägliche Prüfung zu warten — nützlich direkt nach einer
  Verlängerung oder einer Änderung der konfigurierten Domains. Der Schlüssel muss dafür nicht
  erneut eingegeben werden.
- **"Lizenz entfernen" drücken** (und bestätigen), um die gespeicherte Lizenz zu löschen und die
  Installation sofort in den unlizenzierten Zustand zurückzuversetzen.

Aktualisieren und Entfernen erscheinen nur, solange tatsächlich eine Lizenz aktiv ist. Das
Schlüsselfeld wird stets leer dargestellt: Der gespeicherte Schlüssel wird nie in die Seite
zurückgeschrieben.

Der Lizenzschlüssel wird niemals in Contaos regulärer Konfigurationsablage (`localconfig.php`)
gespeichert; er liegt im eigenen, privaten Zustand des Bundles unter `var/` (siehe
[Laufzeitverzeichnisse](#laufzeitverzeichnisse)).

### Lizenzstatus

Die Überschrift am Anfang des Abschnitts zeigt den aktuellen, frisch ausgewerteten Zustand der
Installation. Bei aktiver Lizenz führt die Zeile darunter Paket, zugeordnete Domain, alle
lizenzierten Domains, das Domain-Kontingent, die Gültigkeitsdaten, den Zeitpunkt der letzten
Bestätigung gegen den Lizenzdienst sowie die Revision des Datensatzes auf. Der Lizenzschlüssel
selbst ist bewusst nicht Teil dieser Zeile.

Die möglichen Zustände, in der eigenen Terminologie des Projekts:

| Angezeigter Status | Bedeutung |
|---|---|
| Lizenz aktiv. Alle Funktionen freigeschaltet. | Lizenziert und nutzbar. |
| Keine Lizenz. Schlüssel unter v-t.one anfordern und unten eintragen. | Es wurde noch nie ein Schlüssel eingegeben, oder es ist aktuell keiner gespeichert. |
| Dieser Lizenzschlüssel ist für den Free-Plan dieses Produkts nicht gültig. | Der Schlüssel ist echt, gehört aber zu einem anderen Lizenzmodell (zeitlich begrenzt, Testversion oder kostenpflichtig) als diesem Produkt zulässig ist. |
| Diese Lizenz ist für diese Installation nicht mehr aktiv. | Der Lizenzdienst hat diesen Schlüssel ausdrücklich zurückgezogen. Der Datensatz wird aufbewahrt, nicht gelöscht, damit eine spätere Reaktivierung nichts bereits Vorhandenes erneut abfragen muss. |
| Diese Lizenz ist für keine der auf dieser Installation konfigurierten Domains ausgestellt. | Keiner der konfigurierten Hostnamen dieser Installation erscheint in der Lizenz. |
| Die gespeicherte Lizenz konnte nicht geprüft werden. Bitte den Lizenzschlüssel erneut eintragen. | Der gespeicherte Datensatz hat seine Integritätsprüfung nicht bestanden (z. B. wurde er verändert, oder die zugehörigen Daten stimmen nicht mehr überein). |
| Diese Lizenz stammt aus einem älteren Format. Schlüssel erneut eintragen, um eine aktualisierte Fassung abzurufen. | Der gespeicherte Datensatz verwendet ein älteres Dokumentformat; erneutes Aktivieren des Schlüssels holt eine aktuelle Fassung. |
| Diese Installation kann Lizenzen nicht prüfen: Es ist kein Prüfschlüssel vorhanden. | Ein Problem der Installation selbst, kein Lizenzproblem — aus einem offiziellen Release neu installieren. |
| Für diese Installation ist keine Domain konfiguriert. | Vor der Aktivierung das DNS-Feld einer Startseite oder `vtinnovations_smtp.domains` setzen. |

Solange ein Schlüssel hinterlegt ist, läuft höchstens einmal täglich eine automatische
Hintergrundprüfung, sodass eine Verlängerung oder eine Domain-Änderung auf Seiten des Lizenzdienstes
in der Regel ohne Eingreifen eines Administrators übernommen wird.

Weitere Details zur Bewertung und Kommunikation der Berechtigung finden sich in
[`Documentation/Licensing.md`](Documentation/Licensing.md).

## SMTP-Konfiguration

Contao → System → **SMTP-Konfiguration**:

1. SMTP-Host, Port und Verschlüsselung (keine, STARTTLS oder SSL/TLS) eingeben, optional
   Zugangsdaten.
2. Eine Absenderadresse und eine Test-Empfängeradresse eingeben.
3. Speichern. Das Bundle sendet **vor** dem Speichern eine echte Test-E-Mail über die eingegebenen
   Einstellungen; erst wenn dieser Test erfolgreich ist, wird die Konfiguration in `.env.local`
   geschrieben und der Cache geleert.

Wird das Passwortfeld bei einer Aktualisierung leer gelassen, wird das zuvor gespeicherte Passwort
weiterverwendet statt gelöscht — nur die übrigen Felder müssen erneut eingegeben werden, um Host,
Port oder Verschlüsselung zu ändern.

Absenderadresse und Test-Empfängeradresse werden nur zum Versand der Test-E-Mail verwendet; sie
werden **nicht** gespeichert und beim nächsten Aufruf des Bildschirms nicht vorausgefüllt (siehe
[Bekannte Einschränkungen](#bekannte-einschränkungen)).

Dieser Bildschirm unterstützt ausschließlich SMTP- und SMTPS-Verbindungen (`smtp://` ohne
Verschlüsselung oder mit STARTTLS, `smtps://` für implizites TLS). Es gibt hier keine
Benutzeroberfläche für API-basierte Mailversanddienste.

Ist die Installation nicht lizenziert, zeigt dieser Bildschirm diesen Zustand an und verweist auf
die Einstellungen statt auf das Konfigurationsformular.

## Konsolenbefehl

```bash
bin/console vtinnovations:smtp:disable [--clear-cache]
```

Entfernt `MAILER_DSN` aus `.env.local`, sodass Contao auf seinen eigenen Standard-Mailer
zurückfällt. Wie jede andere geschützte Aktion dieses Bundles erfordert dieser Befehl eine
lizenzierte Installation und verweigert sonst die Ausführung. Ohne `--clear-cache` anschließend
`bin/console cache:clear` ausführen, um die Änderung anzuwenden.

## Funktionsstatus

| Funktion | Status | Anmerkungen |
|---|---|---|
| SMTP-/SMTPS-Mailer-Konfiguration | Verfügbar | Host, Port, STARTTLS/SSL, optionale Zugangsdaten. |
| Verbindungstest vor dem Speichern erforderlich | Verfügbar | Eine echte Test-E-Mail muss zuerst erfolgreich sein. |
| Passwort-Wiederverwendung bei Aktualisierung | Verfügbar | Passwortfeld leer lassen, um das bestehende zu behalten. |
| Automatisches Leeren und Aufwärmen des Caches beim Speichern | Verfügbar | Hinter einer Wartungsseite, die danach immer entfernt wird. |
| Mailer über Konsole deaktivieren | Verfügbar | `vtinnovations:smtp:disable`. |
| Lizenz aktivieren, aktualisieren, entfernen | Verfügbar | Über Contao → Einstellungen. |
| Bindung an mehrere Domains | Verfügbar | Eine übereinstimmende konfigurierte Domain reicht. |
| Automatische tägliche Lizenzprüfung | Verfügbar | Im Hintergrund; bei Fehlschlag bleibt der bestehende Zustand unverändert. |
| Vom Lizenzdienst ausgelöste Lizenzaktualisierungen | Verfügbar | Der Lizenzdienst kann eine Änderung an diese Installation übermitteln. |
| Free-/Pro-Kennzeichnung in der Oberfläche | Bedingt | Die Kennzeichnung existiert aus Konsistenzgründen mit der Produktfamilie; für dieses Produkt ist derzeit keine kostenpflichtige Lizenz ausstellbar. |
| Testversionen | Nicht zutreffend | Das Lizenzmodell dieses Produkts kennt keinen Testversions-Zustand. |
| Rückfall auf Free bei abgelaufener oder inkompatibler Lizenz | Nicht zutreffend | Es gibt keinen solchen Rückfall; eine inkompatible oder nicht passende Lizenz wird grundsätzlich abgelehnt. |
| API-basierte Mailer-Transporte (z. B. E-Mail-Versanddienste über eine API) | Nicht verfügbar | Der Konfigurationsbildschirm erstellt ausschließlich SMTP-/SMTPS-Verbindungen. |
| Frontend- oder Inhaltselement-Integration | Nicht zutreffend | Das Bundle ist ausschließlich für das Backend. |
| Gespeicherte Absender-/Test-Empfängeradresse | Eingeschränkt | Nur für den Versand der Test-E-Mail verwendet; nicht zwischen Aufrufen gespeichert. |

## Sicherheitsmodell

Dies ist eine Beschreibung der tatsächlich implementierten Kontrollen auf hoher Ebene; sie
beschreibt absichtlich nicht, wie diese intern umgesetzt sind.

**Durch den Code garantiert:**

- Jede geschützte Aktion — Anzeigen oder Speichern der SMTP-Konfiguration, Versand der Test-E-Mail,
  Leeren des Caches und der Deaktivierungsbefehl der Konsole — prüft die Berechtigung der
  Installation jeweils selbstständig, bevor irgendetwas ausgeführt wird, statt sich auf ein
  einziges vorgeschaltetes Gate zu verlassen.
- Das SMTP-Konfigurationsmodul ist ausschließlich Backend-Administratoren vorbehalten.
- Zugangsdaten des Mailers werden ausschließlich in `.env.local` geschrieben, niemals an den
  Browser oder in die reguläre Contao-Konfigurationsablage.
- Lizenzbezogene Netzwerkverbindungen verwenden TLS mit Zertifikats- und Hostnamensprüfung, folgen
  keinen Weiterleitungen und sind durch kurze Zeitlimits begrenzt.
- Ein nicht erreichbarer oder fehlerhaft antwortender Lizenzdienst belässt die bestehende
  Berechtigung der Installation exakt so, wie sie war; nur eine ausdrückliche Ablehnung durch den
  Dienst entzieht die Berechtigung, und selbst dann wird der gespeicherte Lizenzdatensatz selbst
  nicht gelöscht.
- Die Authentizität des gespeicherten Lizenzdatensatzes wird bei jedem Lesevorgang erneut geprüft,
  nicht nur beim erstmaligen Empfang, sodass eine auf der Festplatte veränderte oder ausgetauschte
  Datei erkannt wird.
- Betriebs-Logs erfassen nur Ergebnis, Zeitmessung und eine interne Kategorie. Lizenzschlüssel,
  Signaturen sowie Anfrage- und Antwortinhalte werden niemals protokolliert — dies wird durch eine
  automatisierte Testsuite abgesichert.

**Abhängig von der Umgebung:**

- Die Signaturprüfung setzt die Verfügbarkeit von `ext-sodium` voraus; ohne sie behandelt das
  Bundle keine Lizenz als gültig.
- Die automatische Normalisierung nicht-ASCII-Domainnamen setzt `ext-intl` voraus.

**Best-Effort / nur für den genannten Umfang geprüft:**

- Das Schreiben eines aktualisierten Lizenzdatensatzes wird nach dem Schreibvorgang verifiziert und
  automatisch zurückgerollt, wenn das Ergebnis nicht stimmt; diese Zusicherung gilt für den
  Lizenzdatensatz selbst, nicht für beliebige andere Dateisystemvorgänge auf dem Server.
- Beim Leeren des Caches wird die Website für die Dauer der Aktion hinter eine Wartungsseite
  gestellt, die danach immer entfernt wird, auch im Fehlerfall.

**Einschränkungen:**

- Kein Sicherheits- oder Lizenzmechanismus wird als unmöglich zu umgehen dargestellt; die obigen
  Aussagen beschreiben die vorhandenen Kontrollen, keine absolute Garantie.
- Dieses Dokument nennt bewusst keine genauen Anfrageformate, kryptografischen Konstruktionen oder
  die Reihenfolge der Prüfungen.

Eine ausführlichere Beschreibung dieses Modells — weiterhin auf Administrator-Ebene — findet sich
in [`Documentation/Security.md`](Documentation/Security.md).

## Laufzeitverzeichnisse

| Pfad | Zweck |
|---|---|
| `.env.local` | Enthält `MAILER_DSN`, sobald der Mailer konfiguriert ist. |
| `var/vtinnovations-smtp/` | Der private Zustand des Bundles: der gespeicherte Lizenzdatensatz mit seinen Integritätsdaten, Buchführung gegen doppelte Verarbeitung sowie der gemerkte Lizenzschlüssel und Aktivierungsstatus. Nicht Teil des öffentlichen Web-Roots. |
| `var/maintenance.html` | Wird nur für die Dauer eines ausgelösten Cache-Leerens angelegt und danach wieder entfernt. |

## Externe Kommunikation

Bei Lizenzaktivierung, -aktualisierung, der täglichen Hintergrundprüfung sowie wenn der
Lizenzdienst eine Änderung an diese Installation übermittelt, tauscht das Bundle kryptografisch
signierte, authentifizierte Daten mit einem vertrauenswürdigen HTTPS-Dienst von V-T.ONE aus.
Getrennt davon werden minimale Nutzungssignale (welches Produkt und welche Domain verwendet
werden) an denselben Betreiber gesendet; diese beeinflussen die Berechtigung nicht, und ein
Fehlschlag beim Senden wirkt sich niemals auf etwas aus, das ein Administrator sieht. Kein
lizenzbezogenes Geheimnis wird jemals an den Browser gesendet oder von ihm offengelegt, und keiner
dieser Datenverkehre wird im Anwendungs-Log erfasst.

## Logging

Die Betriebsprotokollierung für Lizenzabfragen und Lizenzübermittlungen erfasst das Ergebnis, die
Art der Aktion, Zeitmessung und einen internen Kategoriecode — niemals einen Lizenzschlüssel, eine
Signatur, einen rohen Anfrage- oder Antworttext oder etwas, das daraus abgeleitet ist. Dies wird
durch eine automatisierte Testsuite abgesichert, die sowohl das Laufzeitverhalten als auch den
Quellcode selbst prüft.

## Deployment

```bash
composer require vtinnovations/smtp-bundle
bin/console cache:clear
```

Über den oben genannten Standard-Contao-Cache-Leer-Schritt hinaus gibt es keinen zusätzlichen,
für dieses Bundle spezifischen Deployment-Schritt.

## Cache leeren

Der SMTP-Konfigurationsbildschirm leert und wärmt den Cache nach einem erfolgreichen Speichern
automatisch auf, mit:

```bash
bin/console cache:clear --no-warmup --env=prod --no-interaction
bin/console cache:warmup --env=prod --no-interaction
```

Dieselbe Aktion ist über die Konsole verfügbar:

```bash
bin/console vtinnovations:smtp:disable --clear-cache
```

Kann die PHP-CLI-Binärdatei auf dem Server nicht automatisch gefunden werden, sie explizit
angeben:

```yaml
# config/config.yaml
vtinnovations_smtp:
    php_binary: '/pfad/zu/php'
    process_timeout: 120
```

## Tests

```bash
vendor/bin/phpunit
```

Die Testsuite (`tests/Unit`) deckt den Konfigurationsweg des Mailers, Lizenzaktivierung,
-aktualisierung und -entfernung, die Prüfung signierter Datensätze, den öffentlichen
Aktualisierungs-Endpunkt, das Leeren des Caches und die Schwärzung von Log-Inhalten ab. Kein Test
kontaktiert den echten Lizenzdienst; jeder externe Aufruf wird simuliert. Diese Testsuite wurde bei
der Erstellung dieser Dokumentation nicht ausgeführt, da in der für die Prüfung des Repositorys
verwendeten Umgebung keine PHP-/Composer-Umgebung verfügbar war; die obigen Befehle entsprechen
genau dem, was die eigene CI-Konfiguration des Projekts ausführt.

## Fehlerbehebung

Siehe [`Documentation/Troubleshooting.md`](Documentation/Troubleshooting.md) für Hinweise zu
den Lizenzstatus-Meldungen, Fehlern beim Mailer-Test und Problemen bei der Erkennung der
PHP-Binärdatei.

## Bekannte Einschränkungen

- Die im SMTP-Konfigurationsbildschirm eingegebene Absender- und Test-Empfängeradresse werden nur
  zum Versand der Test-E-Mail verwendet und nicht gespeichert; sie müssen bei jedem Speichern des
  Formulars erneut eingegeben werden.
- Der Konfigurationsbildschirm unterstützt ausschließlich SMTP-/SMTPS-Verbindungen; es gibt keine
  integrierte Oberfläche für API-basierte Transaktions-E-Mail-Dienste, auch wenn solche Dienste
  über eine manuelle Konfiguration der zugrunde liegenden Mailer-Komponente von Contao nutzbar
  wären.
- Ohne `ext-intl` kann ein Hostname mit nicht-ASCII-Zeichen nicht auf die Schreibweise
  normalisiert werden, für die eine Lizenz signiert ist; eine solche Domain lässt sich dann nicht
  aktivieren.
- Das SMTP-Konfigurationsmodul steht ausschließlich Backend-Administratoren zur Verfügung; es
  gibt keine feinere Berechtigung für Backend-Benutzer ohne Administratorrechte.
- Das Lizenzmodell dieser Version unterstützt für dieses Produkt eine Stufe (Lifetime Free); die
  in der Oberfläche vorhandene Kennzeichnung "Pro" ist für dieses Produkt derzeit nicht erreichbar.

## Lizenz und Urheberrecht

Copyright © 2026 VT Innovations Team. Lizenziert unter der GNU Lesser General Public License
v3.0 oder später (LGPL-3.0-or-later). Vollständigen Text siehe [`LICENSE`](LICENSE).

---

**[English version of this file](README.en.md)** · [Lizenzierung](Documentation/Licensing.md) ·
[Sicherheit](Documentation/Security.md) ·
[Fehlerbehebung](Documentation/Troubleshooting.md)
