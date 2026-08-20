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

namespace Vtinnovations\SmtpBundle\Tests\Fixture;

use Psr\Log\AbstractLogger;
/**
 * Keeps everything a logger was handed, so a test can look at all of it rather than at whichever
 * fields it thought to assert on.
 */
final class CapturingLogger extends AbstractLogger
{
    /** @var list<array{level: mixed, message: string, context: array<string, mixed>}> */
    public array $records = [];

    public function log($level, $message, array $context = []): void
    {
        $this->records[] = ['level' => $level, 'message' => (string) $message, 'context' => $context];
    }

    /** Everything that was logged, flattened, so a substring search covers all of it. */
    public function flatten(): string
    {
        $out = '';

        foreach ($this->records as $record) {
            $out .= $record['message'].' '.var_export($record['context'], true)."\n";
        }

        return $out;
    }
}
