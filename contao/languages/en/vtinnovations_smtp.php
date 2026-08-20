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

$GLOBALS['TL_LANG']['vtinnovations_smtp']['back']                  = 'Back';
$GLOBALS['TL_LANG']['vtinnovations_smtp']['headline']              = 'SMTP Configuration';
$GLOBALS['TL_LANG']['vtinnovations_smtp']['access_denied']         = 'Access denied. Administrators only.';
$GLOBALS['TL_LANG']['vtinnovations_smtp']['active']                = 'Active';
$GLOBALS['TL_LANG']['vtinnovations_smtp']['not_configured']        = 'Not configured';
$GLOBALS['TL_LANG']['vtinnovations_smtp']['server_section']        = 'Server';
$GLOBALS['TL_LANG']['vtinnovations_smtp']['smtp_host_label']       = 'SMTP host';
$GLOBALS['TL_LANG']['vtinnovations_smtp']['port_label']            = 'Port';
$GLOBALS['TL_LANG']['vtinnovations_smtp']['encryption_label']      = 'Encryption';
$GLOBALS['TL_LANG']['vtinnovations_smtp']['encryption_none']       = 'None';
$GLOBALS['TL_LANG']['vtinnovations_smtp']['encryption_starttls']   = 'STARTTLS (Port 587)';
$GLOBALS['TL_LANG']['vtinnovations_smtp']['encryption_ssl']        = 'SSL/TLS (Port 465)';
$GLOBALS['TL_LANG']['vtinnovations_smtp']['username_label']        = 'Username';
$GLOBALS['TL_LANG']['vtinnovations_smtp']['password_label']        = 'Password';
$GLOBALS['TL_LANG']['vtinnovations_smtp']['password_help']         = 'Leave blank to keep the existing password.';
$GLOBALS['TL_LANG']['vtinnovations_smtp']['email_section']         = 'E-mail';
$GLOBALS['TL_LANG']['vtinnovations_smtp']['from_email_label']      = 'Sender e-mail';
$GLOBALS['TL_LANG']['vtinnovations_smtp']['test_recipient_label']  = 'Test recipient';
$GLOBALS['TL_LANG']['vtinnovations_smtp']['test_recipient_help']   = 'A test e-mail will be sent to this address before saving.';
$GLOBALS['TL_LANG']['vtinnovations_smtp']['save_btn']              = 'Test & Save';
$GLOBALS['TL_LANG']['vtinnovations_smtp']['license_where']        = 'Licence management for this package is in Contao → Settings, under "SMTP Konfigurator Licence management".';
