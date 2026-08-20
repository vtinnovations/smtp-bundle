# Sicherheitsmodell

Dieses Dokument beschreibt auf einer hohen Vertrauensebene die sicherheitsrelevanten Kontrollen,
die `vtinnovations/smtp-bundle` tatsächlich implementiert. Es erklärt bewusst nicht, wie diese
Kontrollen umgesetzt sind — interne Klassen- oder Methodennamen, Anfrage-/Antwortformate,
kryptografische Konstruktionen, Schlüsselmaterial, Speicherschemata und die Reihenfolge von
Prüfungen liegen alle außerhalb des Umfangs der öffentlichen Dokumentation, unabhängig davon, ob
ein Teil dieses Materials technisch auf der Leitung beobachtbar ist. Die Veröffentlichung eines
solchen Detailgrads würde vor allem als Wegweiser zur Umgehung des Mechanismus dienen, nicht als
Information, die ein Administrator zum Betrieb benötigt.

Die folgenden Kontrollen sind danach gruppiert, wie belastbar die jeweilige Aussage ehrlich
getroffen werden kann.

## Durch den Code garantiert

- **Zugriffskontrolle.** Das SMTP-Konfigurations-Backend-Modul ist auf Backend-Administratoren
  beschränkt; jeder andere Backend-Benutzer wird mit einer ausdrücklichen
  Zugriff-verweigert-Meldung abgewiesen. Die Lizenzverwaltung wird ausschließlich auf Contaos
  `tl_settings`-Bildschirm angezeigt, den Contao selbst standardmäßig auf Administratoren
  beschränkt. Die dahinterliegenden Aktionen Aktivieren, Aktualisieren und Entfernen sind eine
  eigene, auf das Backend beschränkte Route, die sich darauf nicht verlässt: Sie prüft erneut, dass
  der Aufrufer ein angemeldeter Contao-Administrator ist und dass der POST ein gültiges
  Contao-Request-Token trägt, und weist andernfalls mit 403 ab.
- **Eigenständige Berechtigungsprüfung je Aktion.** Das Anzeigen des Konfigurationsbildschirms,
  der Versand der verpflichtenden Test-E-Mail, das Speichern der Konfiguration, das Leeren des
  Caches und der Konsolenbefehl, der die Konfiguration entfernt, prüfen jeweils eigenständig die
  Berechtigung der Installation, zum Zeitpunkt ihrer Ausführung. Keine dieser Aktionen setzt
  voraus, dass eine frühere Prüfung an anderer Stelle sie bereits abgedeckt hat; das Entfernen oder
  Umgehen einer einzelnen Prüfung öffnet die anderen nicht.
- **Serverseitige Durchsetzung der Berechtigung.** Die Berechtigung wird vollständig auf dem
  Server entschieden, anhand von Daten, die außerhalb der Reichweite des Browsers gespeichert
  sind. Kein clientseitig übermittelter Wert beeinflusst, ob eine Aktion zulässig ist.
- **Authentifizierte und integritätsgeprüfte Lizenzdaten.** Vom Lizenzdienst empfangene
  Lizenzdaten — bei Aktivierung, Aktualisierung oder einer vom Dienst ausgelösten Aktualisierung —
  sind kryptografisch signiert und werden auf Authentizität und Integrität geprüft, bevor
  irgendein Inhalt daraus zur Entscheidung über irgendetwas verwendet wird. Dieselbe Prüfung
  erfolgt bei jedem Lesevorgang der gespeicherten Lizenz erneut, nicht nur beim erstmaligen
  Empfang, sodass eine lokal veränderte oder ausgetauschte Datei erkannt statt vertraut wird.
- **Private Speicherung.** Die gespeicherte Lizenz, ihre unterstützenden Integritätsdaten und die
  übrige Buchführung des Bundles liegen unter `var/`, außerhalb des öffentlichen Web-Roots.
  Zugangsdaten des Mailers werden ausschließlich in `.env.local` geschrieben, an derselben Stelle,
  an der Contao selbst Anwendungsgeheimnisse erwartet — niemals an den Browser und niemals in
  Contaos eigene reguläre Konfigurationsdatei.
- **Vertrauenswürdige HTTPS-Kommunikation.** Alle ausgehenden, lizenzbezogenen Netzwerkaufrufe
  verwenden TLS mit aktivierter Zertifikats- und Hostnamensprüfung, folgen keinen
  HTTP-Weiterleitungen und sind durch kurze Verbindungs- und Gesamtzeitlimits begrenzt, damit ein
  langsamer oder fehlerhaft reagierender Endpunkt eine Backend-Anfrage nicht unbegrenzt blockieren
  kann.
- **Sicheres Fehlerverhalten.** Ein nicht erreichbarer Lizenzdienst, ein Zeitüberschreitung oder
  eine nicht lesbare Antwort belässt die bestehende Berechtigung der Installation unverändert —
  keine dieser Situationen wird als Urteil über die Lizenz gewertet. Nur eine Antwort, die einen
  Schlüssel positiv und nachweisbar ablehnt, entzieht die Berechtigung, und selbst dann wird der
  zuvor gespeicherte, authentifizierte Lizenzdatensatz nicht gelöscht, sondern lediglich seine
  Wirkung entzogen.
- **Geschwärztes Logging.** Wo dieses Bundle etwas über eine Lizenzabfrage protokolliert, enthält
  der Log-Eintrag Ergebnis, eine interne Kategorie und Zeitmessung — niemals einen
  Lizenzschlüssel, eine Signatur, eine Nonce, einen rohen Anfrage- oder Antworttext oder etwas,
  das daraus abgeleitet ist. Dies wird durch eine automatisierte Testsuite sowohl gegen die
  tatsächliche protokollierte Ausgabe als auch gegen den Quellcode selbst geprüft, sodass eine
  zukünftige Änderung, die sensible Log-Inhalte wieder einführt, erkannt statt ausgeliefert wird.
- **Umgang mit Geheimnissen.** Zugangsdaten des Mailers erscheinen nach dem Speichern nie im
  Browser (das Passwortfeld wird stets leer angezeigt), und der Lizenzschlüssel wird in keiner von
  diesem Bundle erzeugten HTML-Seite ausgegeben.

## Abhängig von der Umgebung

- Die Signaturprüfung für Lizenzdaten setzt `ext-sodium` voraus. Ohne diese Erweiterung behandelt
  dieses Bundle keine Lizenz als gültig — eine fehlende Prüfmöglichkeit führt zu einer Ablehnung,
  nicht zu einem Durchlassen.
- Die Normalisierung eines nicht-ASCII- (internationalisierten) Hostnamens auf die exakte
  Schreibweise, für die eine Lizenz signiert ist, setzt `ext-intl` voraus. Ohne diese Erweiterung
  lässt sich gegen einen solchen Hostnamen nicht aktivieren.

## Best-Effort / nur für den genannten Umfang geprüft

- **Transaktionale Persistenz des Lizenzdatensatzes (nur Lizenzspeicherung).** Wird der
  installierte Lizenzdatensatz ersetzt — nach Aktivierung, einer Aktualisierung oder einer vom
  Dienst ausgelösten Aktualisierung —, wird der neue Datensatz nach dem Schreiben auf die
  Festplatte verifiziert und automatisch auf den vorherigen Datensatz zurückgerollt, falls diese
  Verifizierung nicht erfolgreich ist. Diese Zusicherung bezieht sich speziell auf den
  Lizenzdatensatz und seine Integritätsdaten; sie ist keine allgemeine
  Dateisystem-Transaktionsfunktion für den Rest des Servers.
- **Wartungsmodus beim Leeren des Caches.** Während der eigene Cache-Leer-Vorgang des Bundles
  läuft, wird die Website hinter eine Wartungsseite gestellt, die danach immer entfernt wird —
  auch wenn der zugrunde liegende Cache-Leer-Prozess fehlschlägt.
- **Prüfung nach der Aktion.** Nach dem Speichern einer Mailer-Konfiguration oder nach der
  Installation eines aktualisierten Lizenzdatensatzes wird das Ergebnis zurückgelesen und
  bestätigt, bevor die Aktion als erfolgreich gemeldet wird.

## Einschränkungen

- Keine Aussage in diesem Dokument ist als Behauptung zu verstehen, dass irgendein Teil dieses
  Mechanismus unmöglich zu umgehen sei. Es beschreibt die vorhandenen Kontrollen in zurückhaltenden
  und sachlichen Worten, keine absolute Garantie.
- Das oben beschriebene Transaktions- und Rollback-Verhalten gilt für die eigene Lizenzspeicherung
  dieses Bundles; es erstreckt sich nicht auf beliebige Dateien oder auf die weitere
  Hosting-Umgebung.
- Dieses Dokument beschreibt und wird nicht die genauen Anfrage- oder Antwortformate für
  Lizenzierungsvorgänge, die beteiligten Algorithmen oder das Schlüsselmaterial, die Reihenfolge
  der Prüfungen oder andere Details beschreiben, die vor allem als Anleitung zur Umgehung des
  Mechanismus dienen würden statt zu dessen Betrieb.

## Verwandte Dokumente

- [`README.md`](../README.md) — Installation, Konfiguration und Funktionsüberblick.
- [`Licensing.md`](Licensing.md) — Lizenzverhalten und Zustände auf Administrator-Ebene.
- [`Troubleshooting.md`](Troubleshooting.md) — praktische Schritte zu häufigen
  Statusmeldungen und Fehlern.
