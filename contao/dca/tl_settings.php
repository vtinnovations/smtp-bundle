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

use Contao\CoreBundle\DataContainer\PaletteManipulator;
use Vtinnovations\SmtpBundle\EventListener\DataContainer\InstanceSettingsListener;

/*
 * Licence management for this package lives in Contao → Settings, as its own section.
 *
 * One field, rendered by the package itself rather than assembled from stock widgets. What belongs
 * on this screen is a state — which package, which host out of which signed set, since when, until
 * when, last confirmed when — plus the actions that change it, and no combination of text fields
 * and checkboxes says that as clearly. Rendering it directly also lets this section look like the
 * sibling V-T.ONE sections that share this screen.
 *
 * The name carries the package prefix on purpose: several V-T.ONE packages can be installed in one
 * Contao instance, and each needs its own field, its own section and its own state without
 * colliding with the others or with core settings.
 *
 * Nothing is written to localconfig. The field renders and never saves, so the key never lands in
 * system/config/localconfig.php and the status line is always the live one rather than a stale copy.
 *
 * The legend is prepended and shared: every V-T.ONE package adds its field to the same
 * "vtone_licence_legend" group, so all licence sections sit together in one fieldset at the top
 * of the Settings screen, above Contao's own legends, with the package name shown as the field's
 * own heading (rendered by InstanceSettingsListener::render()) instead of a separate legend.
 */
$GLOBALS['TL_DCA']['tl_settings']['fields']['vtinnovations_smtp_licence'] = [
    'label'                 => &$GLOBALS['TL_LANG']['tl_settings']['vtinnovations_smtp_licence'],
    'input_field_callback' => [InstanceSettingsListener::class, 'render'],
    'eval'                 => ['tl_class' => 'clr'],
];

PaletteManipulator::create()
    ->addLegend('vtone_licence_legend', null, PaletteManipulator::POSITION_PREPEND)
    ->addField('vtinnovations_smtp_licence', 'vtone_licence_legend', PaletteManipulator::POSITION_APPEND)
    ->applyToPalette('default', 'tl_settings')
;
