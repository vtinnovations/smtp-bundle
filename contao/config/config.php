<?php

declare(strict_types=1);

use Vtinnovations\SmtpBundle\Controller\Backend\SmtpConfigModule;

$GLOBALS['BE_MOD']['system']['vtinnovations_smtp'] = [
    'callback' => SmtpConfigModule::class,
];
