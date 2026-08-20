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

/*
 * Builds the distributable artefact.
 *
 *     php tools/release-build.php [target-dir]
 *
 * Refuses to produce anything unless a real verification key is pinned, so an unusable package
 * cannot be published by accident. See ReleasePackager for exactly what is and is not hardened.
 */

require_once __DIR__.'/../vendor/autoload.php';
require_once __DIR__.'/ReleasePackager.php';

use Vtinnovations\SmtpBundle\Tools\ReleasePackager;

$source = \dirname(__DIR__);
$target = $argv[1] ?? $source.'/build/release';

try {
    $result = (new ReleasePackager($source, $target))->build();
} catch (\Throwable $e) {
    fwrite(STDERR, 'Release build failed: '.$e->getMessage().PHP_EOL);

    exit(1);
}

fwrite(STDOUT, sprintf('Packaged %d files into %s%s', $result['files'], $target, PHP_EOL));
fwrite(STDOUT, 'Manifest: '.$target.'/MANIFEST.sha256'.PHP_EOL);

exit(0);
