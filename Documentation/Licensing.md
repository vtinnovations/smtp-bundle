# Lizenzierung

Dieses Dokument beschreibt auf Administrator-Ebene, wie die Lizenzierung für
`vtinnovations/smtp-bundle` funktioniert. Es beschreibt absichtlich keine Anfrageformate,
kryptografischen Konstruktionen, Speicherschemata oder die Reihenfolge von Prüfungen — siehe den
Hinweis am Ende dieses Dokuments.

## Warum eine Lizenz erforderlich ist

Jeder funktionale Teil dieses Bundles — der SMTP-Konfigurationsbildschirm, der Versand der
verpflichtenden Test-E-Mail, das Schreiben und Leeren der Mailer-Konfiguration sowie der
Konsolenbefehl, der sie entfernt — ist eine geschützte Aktion. Jede davon prüft die Berechtigung
der Installation eigenständig, im Moment der Ausführung, statt sich auf eine einzige vorgeschaltete
Prüfung zu verlassen. Ohne erteilte Lizenz führt keine dieser Aktionen etwas aus, außer zu melden,
dass die Installation nicht lizenziert ist.

## Das Lizenzmodell für dieses Produkt

Diese Version akzeptiert für dieses Produkt ein Lizenzmodell: **Lifetime Free**. Eine Lizenz, die
für dieses Produkt erfolgreich geprüft wird, ist konstruktionsbedingt dauerhaft und kostenlos — es
gibt kein Ablaufdatum zu verfolgen und keine kostenpflichtige Stufe, auf die für dieses konkrete
Produkt aufgerüstet werden könnte.

Ein Schlüssel, der zwar echt und korrekt signiert ist, aber zu einem anderen Modell gehört — etwa
eine zeitlich begrenzte kostenlose Lizenz, eine Testversion oder eine kostenpflichtige Lizenz —
wird für dieses Produkt genauso abgelehnt wie eine Lizenz für das falsche Produkt oder die falsche
Domain. Für einen solchen Schlüssel wird kein lokaler Rückfall oder eine geringere Stufe berechnet;
er aktiviert dieses Produkt schlicht nicht.

Die Backend-Oberfläche enthält weiterhin eine Kennzeichnung "Free" / "Pro". Das liegt daran, dass
derselbe Lizenzierungsmechanismus über mehrere V-T.ONE-Produkte hinweg gemeinsam genutzt wird,
von denen manche eine kostenpflichtige Stufe anbieten. Für dieses Produkt ist derzeit keine
kostenpflichtige Lizenz ausstellbar, sodass ein Administrator dieses Bundles der Kennzeichnung
"Pro" in der Praxis nicht begegnen wird.

## Domain-Bindung

Eine Lizenz berechtigt einen oder mehrere exakte Hostnamen. Der Abgleich ist stets exakt:

- `example.com` deckt nicht `www.example.com` ab, und keiner der beiden deckt eine Subdomain ab.
- Es findet an keiner Stelle dieses Vorgangs ein Abgleich über Suffixe, übergeordnete Domains
  oder eine `www.`-Gleichsetzung statt.
- Eine Lizenz kann mehr als einen Hostnamen berechtigen; ist das der Fall, reicht die Aktivierung
  auf einem einzigen davon aus.

Die Hostnamen, für die sich diese Installation ausgibt, werden ausschließlich aus der
Konfiguration gelesen, die der Betreiber der Website kontrolliert — das DNS-Feld der
Contao-Startseiten, die Einstellung `vtinnovations_smtp.domains`, oder, nur als letzte
Möglichkeit, der im Framework konfigurierte Standard-Host. Sie werden niemals aus dem
`Host`-Header einer eingehenden Anfrage übernommen, da dieser Wert vom Browser eines Besuchers
stammt und nicht vom Betreiber der Installation bestätigt wurde.

```yaml
# config/config.yaml
vtinnovations_smtp:
    domains:
        - 'example.com'
        - 'www.example.com'
```

## Aktivieren, Aktualisieren und Entfernen

Alle drei Aktionen erfolgen über Contao → Einstellungen → "SMTP Konfigurator Licence management":

| Aktion | Vorgehen | Wirkung |
|---|---|---|
| Aktivieren | Lizenzschlüssel eingeben, dann **Lizenz prüfen und aktivieren** drücken | Der Schlüssel wird sofort gegen den Lizenzdienst geprüft. Bei Erfolg ist die Installation ab diesem Zeitpunkt lizenziert. |
| Aktualisieren | **Lizenz aktualisieren** drücken | Holt eine aktuelle Fassung der bereits hinterlegten Lizenz, ohne auf die automatische tägliche Prüfung zu warten und ohne erneute Eingabe des Schlüssels. Nützlich direkt nach einer Verlängerung oder nach einer Änderung der von dieser Installation bedienten Domains. |
| Entfernen | **Lizenz entfernen** drücken und bestätigen | Löscht die gespeicherte Lizenz und ihre Buchführung. Die Installation kehrt sofort in den unlizenzierten Zustand zurück. |

Aktualisieren und Entfernen werden nur angeboten, solange eine Lizenz aktiv ist. Alle drei senden an
die paketeigene, auf das Backend beschränkte Aktionsroute, die unabhängig davon einen angemeldeten
Contao-**Administrator** und ein gültiges Request-Token verlangt, bevor überhaupt etwas geschieht,
und anschließend zurück zu den Einstellungen leitet, wo der Abschnitt aus frisch ausgewertetem
Zustand neu gerendert wird.

Solange ein Schlüssel hinterlegt ist, läuft zusätzlich automatisch eine Hintergrundprüfung,
höchstens einmal täglich. Sie erfolgt lautlos: Kann der Lizenzdienst nicht erreicht werden oder
antwortet er nicht, bleibt die bestehende Berechtigung der Installation exakt so, wie sie war.

Der Lizenzschlüssel selbst wird niemals in Contaos regulärer Konfigurationsdatei
(`system/config/localconfig.php` bzw. deren Symfony-basiertem Äquivalent) gespeichert. Er liegt im
eigenen, privaten Zustand dieses Bundles unter `var/`, außerhalb des öffentlichen Web-Roots.

## Für Administratoren sichtbare Zustände

Die Überschrift des Lizenzabschnitts meldet einen der folgenden Zustände, in der Formulierung, die
die Oberfläche selbst verwendet. Bei erteilter Lizenz ergänzt die Detailzeile darunter Paket,
zugeordnete Domain, lizenzierte Domains, Domain-Kontingent, Gültigkeitsdaten, letzte Bestätigung und
Revision des Datensatzes — niemals den Lizenzschlüssel.

| Zustand | Bedeutung für den Administrator |
|---|---|
| **Lizenz aktiv. Alle Funktionen freigeschaltet.** | Vollständig lizenziert. Alle geschützten Funktionen sind verfügbar. |
| Kein Schlüssel eingegeben | Es wurde noch nichts aktiviert, oder eine zuvor gespeicherte Lizenz wurde entfernt. |
| Lizenzschlüssel für den Free-Plan dieses Produkts nicht gültig | Der Schlüssel ist authentisch, gehört aber zu einem Lizenzmodell, das dieses Produkt nicht akzeptiert (siehe [oben](#das-lizenzmodell-für-dieses-produkt)). |
| Lizenz für diese Installation nicht mehr aktiv | Der Lizenzdienst hat diesen Schlüssel ausdrücklich zurückgezogen. Der zugrunde liegende Datensatz wird aufbewahrt statt gelöscht, damit eine spätere Reaktivierung nicht bei null beginnt. |
| Lizenz für keine konfigurierte Domain ausgestellt | Keiner der konfigurierten Hostnamen dieser Installation erscheint unter den von der Lizenz berechtigten Hostnamen. |
| Gespeicherte Lizenz konnte nicht geprüft werden | Die lokal gespeicherte Lizenz hat eine Integritätsprüfung nicht bestanden — etwa weil die Datei verändert wurde oder zugehörige Daten auf der Festplatte nicht mehr übereinstimmen. Erneutes Eintragen des Schlüssels stellt sie wieder her. |
| Lizenz stammt aus einem älteren Format | Der gespeicherte Datensatz verwendet ein älteres internes Dokumentformat. Erneutes Aktivieren des Schlüssels holt eine aktuelle Fassung; nichts geht verloren. |
| Kein Prüfschlüssel vorhanden | Ein Problem der installierten Version selbst, kein Lizenzproblem. Eine Neuinstallation aus einem offiziellen Release löst es. |
| Keine Domain konfiguriert | Der Installation fehlt noch ein Hostname, gegen den aktiviert werden könnte. DNS-Feld der Startseite oder `vtinnovations_smtp.domains` setzen. |

Dies sind die tatsächlichen Zustände, die die Lizenzbewertung dieses Produkts melden kann.
Zustände, die in dieser Liste nicht vorkommen — etwa eine Testphase oder ein Rückfall einer
abgelaufenen kostenpflichtigen Lizenz auf eine kostenlose Stufe — existieren für dieses Produkt
nicht: Es gibt keinen Testversions-Zustand und keinen solchen Rückfall.

## Auswirkungen auf die Berechtigung

| Bei bestehender Lizenz | Ohne Lizenz |
|---|---|
| Der SMTP-Konfigurationsbildschirm zeigt das Konfigurationsformular | Der SMTP-Konfigurationsbildschirm zeigt einen Hinweis mit Verweis auf die Einstellungen |
| Die Test-E-Mail kann gesendet und die Konfiguration gespeichert werden | Das Speichern wird verweigert, unabhängig vom gewählten Weg |
| Der auf ein erfolgreiches Speichern folgende Cache-Leer-Schritt wird ausgeführt | Wird nicht erreicht |
| `bin/console vtinnovations:smtp:disable` wird ausgeführt | Der Befehl verweigert die Ausführung und meldet, dass die Installation nicht lizenziert ist |

## Was auf hoher Ebene authentifiziert wird

Mit dem Lizenzdienst ausgetauschte Lizenzdaten — bei Aktivierung, bei Aktualisierung und wenn der
Dienst eine Aktualisierung übermittelt — werden kryptografisch signiert und geprüft, bevor
irgendetwas daraus als vertrauenswürdig behandelt wird. Eine Antwort, die sich nicht verifizieren
lässt, unvollständig ist oder nicht zur Identität dieser Installation passt, wird wie ein
Netzwerkfehler behandelt: Es ändert sich nichts. Nur eine Antwort, die einen Schlüssel positiv und
nachweisbar ablehnt, entzieht die Berechtigung, und selbst das löscht den zugrunde liegenden
Datensatz nicht. Dieses Dokument beschreibt weder das Signaturverfahren noch die genauen
ausgetauschten Felder noch die Reihenfolge der Prüfungen — dieses Detail wird bewusst aus der
öffentlichen Dokumentation herausgehalten, da eine Veröffentlichung vor allem jemandem helfen
würde, der versucht, den Mechanismus zu umgehen, statt einem Administrator, der ihn betreibt.

## Verwandte Dokumente

- [`README.md`](../README.md) — Installation, Konfiguration und Funktionsüberblick.
- [`Security.md`](Security.md) — das übergreifende Sicherheits-Assurance-Modell.
- [`Troubleshooting.md`](Troubleshooting.md) — Vorgehen zu jeder Statusmeldung.
