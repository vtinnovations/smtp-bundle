<?php

/*
 * SMTP Konfigurator
 *
 * Package: vtinnovations/smtp-bundle
 * Copyright: VT Innovations Team
 * Licence: LGPL-3.0-or-later
 * Website: https://github.com/vtinnovations/smtp-bundle
 */

declare(strict_types=1);

return [
    'error.host_required'          => 'SMTP-Host ist erforderlich.',
    'error.from_email_invalid'     => 'Gültige Absender-E-Mail-Adresse ist erforderlich.',
    'error.test_recipient_invalid' => 'Gültige Test-Empfänger-Adresse ist erforderlich.',
    'error.invalid_config'         => 'Ungültige Konfiguration: %error%',
    'error.test_mail_failed'       => 'Test-Mail fehlgeschlagen (%duration%s): %error%',
    'error.save_failed'            => 'Fehler beim Speichern: %error%',
    'error.cache_clear_failed'     => 'Konfiguration gespeichert, aber Cache-Clear fehlgeschlagen. Bitte manuell leeren. Fehler: %error%',
    'success.config_saved'         => 'Test-Mail erfolgreich gesendet (%duration%s). Konfiguration gespeichert, Cache geleert.',

    'error.refused'                => 'Dieser Lizenzschlüssel wurde für diese Installation nicht akzeptiert.',
    'error.unavailable'            => 'Der Lizenzdienst war nicht erreichbar. Es wurde nichts geändert — bitte später erneut versuchen.',
    'error.rollback'               => 'Die gelieferte Lizenz ist älter als die bereits installierte und wurde nicht übernommen.',
    'error.store_failed'           => 'Die Lizenz konnte nicht gespeichert werden. Bitte prüfen, ob var/ beschreibbar ist.',

    'status.active'                => 'Aktiv',
    'status.until'                 => 'gültig bis %date%',
    'status.perpetual'             => 'ohne Ablauf',
    'status.inactive'              => 'Keine Lizenz. Schlüssel unter v-t.one anfordern und unten eintragen.',
    'status.model_incompatible'    => 'Dieser Lizenzschlüssel ist für den Free-Plan dieses Produkts nicht gültig. Bitte kontaktieren Sie v-t.one, falls dies unerwartet ist.',
    'status.revoked'               => 'Diese Lizenz ist für diese Installation nicht mehr aktiv.',
    'status.wrong_domain'          => 'Diese Lizenz ist für keine der auf dieser Installation konfigurierten Domains ausgestellt.',
    'status.unverifiable'          => 'Die gespeicherte Lizenz konnte nicht geprüft werden. Bitte den Lizenzschlüssel erneut eintragen.',
    'status.refresh_required'      => 'Diese Lizenz stammt aus einem älteren Format. Schlüssel erneut eintragen, um eine aktualisierte Fassung abzurufen.',
    'status.no_key_material'       => 'Diese Installation kann Lizenzen nicht prüfen: Es ist kein Prüfschlüssel vorhanden. Bitte aus einem offiziellen Release neu installieren.',
    'status.no_configured_domain'  => 'Für diese Installation ist keine Domain konfiguriert. Bitte das DNS-Feld einer Startseite setzen oder vtinnovations_smtp.domains in der Konfiguration.',

    'plan.free'                    => 'Free',
    'plan.paid'                    => 'Pro',

    // Der Abschnitt in den Einstellungen (Contao > Einstellungen > SMTP Konfigurator Licence management).
    'panel.headline_active'        => 'Lizenz aktiv. Alle Funktionen freigeschaltet.',
    'panel.headline_unlicensed'    => 'Nicht lizenziert. Keine geschützte Funktion läuft.',
    'panel.key'                    => 'Schlüssel:',
    'panel.package'                => 'Paket:',
    'panel.starts'                 => 'Gültig ab:',
    'panel.expires'                => 'Gültig bis:',
    'panel.unlimited'              => 'unbegrenzt',
    'panel.checked'                => 'Zuletzt geprüft:',
    'panel.key_label'              => 'Lizenzschlüssel',
    'panel.key_help'               => 'Schlüssel eintragen und aktivieren. Bereits lizenziert? Nach einer Verlängerung oder Domain-Änderung genügt "Lizenz aktualisieren" — der Schlüssel muss nicht erneut eingegeben werden.',
    'panel.activate_button'        => 'Lizenz prüfen und aktivieren',
    'panel.refresh_button'         => 'Lizenz aktualisieren',
    'panel.remove_button'          => 'Lizenz entfernen',
    'panel.remove_confirm'         => 'Aktivierte Lizenz entfernen? Diese Installation kehrt sofort zu ihrem unlizenzierten Standardverhalten zurück.',

    'panel.msg_activated'          => 'Lizenz aktiviert.',
    'panel.msg_refreshed'          => 'Lizenz aktualisiert.',
    'panel.msg_removed'            => 'Lizenz entfernt. SMTP Konfigurator verhält sich wieder wie im unlizenzierten Standard.',
    'panel.msg_no_key'             => 'Es wurde kein Lizenzschlüssel eingegeben.',
    'panel.forbidden'              => 'Zugriff verweigert',
    'panel.invalid_token'          => 'Ungültiges Sicherheitstoken',
];
