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

namespace Vtinnovations\SmtpBundle\Mailer;

use Symfony\Component\Mailer\Mailer;
use Symfony\Component\Mailer\Transport;
use Symfony\Component\Mime\Email;
use Vtinnovations\SmtpBundle\Exception\NotEntitledException;
use Vtinnovations\SmtpBundle\Service\EntitlementReader;

final class TestMailService
{
    public function __construct(private readonly EntitlementReader $entitlement)
    {
    }

    public function sendTest(string $dsn, string $fromEmail, string $toEmail): TestResult
    {
        // Sending mail through operator-supplied credentials is a protected operation in its own
        // right, whichever caller arrived at it.
        if (!$this->entitlement->isGranted()) {
            throw new NotEntitledException('smtp.test_mail', $this->entitlement->current()->reason);
        }

        $start = microtime(true);

        try {
            $transport = Transport::fromDsn($dsn);
            $mailer = new Mailer($transport);

            $email = (new Email())
                ->from($fromEmail)
                ->to($toEmail)
                ->subject('SMTP Test — vtinnovations/smtp-bundle')
                ->text('Test email sent by vtinnovations/smtp-bundle to verify SMTP configuration.');

            $mailer->send($email);

            return new TestResult(true, null, microtime(true) - $start);
        } catch (\Throwable $e) {
            return new TestResult(false, $e->getMessage(), microtime(true) - $start);
        }
    }
}
