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

use Vtinnovations\SmtpBundle\Controller\Backend\SmtpConfigModule;

$GLOBALS['BE_MOD']['system']['vtinnovations_smtp'] = [
    'callback' => SmtpConfigModule::class,
];
