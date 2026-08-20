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
    'error.host_required'          => 'SMTP host is required.',
    'error.from_email_invalid'     => 'A valid sender e-mail address is required.',
    'error.test_recipient_invalid' => 'A valid test recipient address is required.',
    'error.invalid_config'         => 'Invalid configuration: %error%',
    'error.test_mail_failed'       => 'Test mail failed (%duration%s): %error%',
    'error.save_failed'            => 'Error saving: %error%',
    'error.cache_clear_failed'     => 'Configuration saved, but cache clear failed. Please clear manually. Error: %error%',
    'success.config_saved'         => 'Test mail sent successfully (%duration%s). Configuration saved, cache cleared.',

    // Deliberately coarse. An admin needs to know what to do next; spelling out which check failed
    // would mostly help someone probing the validation.
    'error.refused'                => 'This licence key was not accepted for this installation.',
    'error.unavailable'            => 'The licence service could not be reached. Nothing was changed — please try again later.',
    'error.rollback'               => 'The delivered licence is older than the one already installed and was not applied.',
    'error.store_failed'           => 'The licence could not be stored. Check that var/ is writable.',

    'status.active'                => 'Active',
    'status.until'                 => 'valid until %date%',
    'status.perpetual'             => 'no expiry',
    'status.inactive'              => 'No licence. Get one at v-t.one and enter the key below.',
    'status.model_incompatible'    => 'This licence key is not valid for this product\'s Free plan. Contact v-t.one if you believe this is wrong.',
    'status.revoked'               => 'This licence is no longer active for this installation.',
    'status.wrong_domain'          => 'This licence is not issued for any domain configured on this installation.',
    'status.unverifiable'          => 'The stored licence could not be verified. Re-enter your licence key to restore it.',
    'status.refresh_required'      => 'This licence predates the current format. Enter your key again to fetch an updated copy.',
    'status.no_key_material'       => 'This build cannot verify licences: no verification key is present. Please reinstall from an official release.',
    'status.no_configured_domain'  => 'No domain is configured for this installation. Set the DNS field on a root page, or vtinnovations_smtp.domains in config.',

    'plan.free'                    => 'Free',
    'plan.paid'                    => 'Pro',

    // The settings section (Contao > Settings > SMTP Konfigurator Licence management).
    'panel.headline_active'        => 'Licence active. All features unlocked.',
    'panel.headline_unlicensed'    => 'Not licensed. No protected feature runs.',
    'panel.key'                    => 'Key:',
    'panel.package'                => 'Package:',
    'panel.starts'                 => 'Valid from:',
    'panel.expires'                => 'Valid until:',
    'panel.unlimited'              => 'unlimited',
    'panel.checked'                => 'Last verified:',
    'panel.key_label'              => 'Licence key',
    'panel.key_help'               => 'Enter your key and activate. Already licensed? Use "Update Licence" after a renewal or a domain change — no need to type the key again.',
    'panel.activate_button'        => 'Verify and Activate Licence',
    'panel.refresh_button'         => 'Update Licence',
    'panel.remove_button'          => 'Remove Licence',
    'panel.remove_confirm'         => 'Remove the activated licence? This installation returns to its unlicensed default behaviour immediately.',

    'panel.msg_activated'          => 'Licence activated.',
    'panel.msg_refreshed'          => 'Licence updated.',
    'panel.msg_removed'            => 'Licence removed. SMTP Konfigurator has returned to its unlicensed default behaviour.',
    'panel.msg_no_key'             => 'No licence key was entered.',
    'panel.forbidden'              => 'Forbidden',
    'panel.invalid_token'          => 'Invalid security token',
];
