<?php

declare(strict_types=1);

namespace Vtinnovations\SmtpBundle\Mailer;

use Symfony\Component\Mailer\Mailer;
use Symfony\Component\Mailer\Transport;
use Symfony\Component\Mime\Email;

final class TestMailService
{
    public function sendTest(string $dsn, string $fromEmail, string $toEmail): TestResult
    {
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
