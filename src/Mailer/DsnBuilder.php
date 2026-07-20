<?php

declare(strict_types=1);

namespace Vtinnovations\SmtpBundle\Mailer;

use Vtinnovations\SmtpBundle\Exception\InvalidDsnException;

final class DsnBuilder
{
    private const ALLOWED_ENCRYPTIONS = ['none', 'tls', 'ssl'];

    public function build(
        string $host,
        int $port,
        string $username,
        string $password,
        string $encryption,
    ): string {
        if (!\in_array($encryption, self::ALLOWED_ENCRYPTIONS, true)) {
            throw new InvalidDsnException(\sprintf(
                'Invalid encryption "%s". Allowed: %s',
                $encryption,
                implode(', ', self::ALLOWED_ENCRYPTIONS),
            ));
        }

        if ($host === '') {
            throw new InvalidDsnException('Host must not be empty.');
        }

        // Allow: hostnames (a-z, 0-9, hyphens, dots) and IPv6 ([::1])
        // Reject: @, ?, #, %, /, spaces and any other URL-special chars that could break DSN parsing
        if (!preg_match('/^(\[[\da-fA-F:]+\]|[a-zA-Z0-9][a-zA-Z0-9.\-]*)$/', $host)) {
            throw new InvalidDsnException('Invalid host. Only hostnames and IP addresses are allowed.');
        }

        if ($port < 1 || $port > 65535) {
            throw new InvalidDsnException(\sprintf('Invalid port %d.', $port));
        }

        // ssl (port 465) uses smtps://, everything else uses smtp://
        $scheme = $encryption === 'ssl' ? 'smtps' : 'smtp';

        $userPart = '';
        if ($username !== '') {
            $userPart = rawurlencode($username);
            if ($password !== '') {
                $userPart .= ':' . rawurlencode($password);
            }
            $userPart .= '@';
        }

        $dsn = $scheme . '://' . $userPart . $host . ':' . $port;

        // STARTTLS
        if ($encryption === 'tls') {
            $dsn .= '?encryption=tls';
        }

        return $dsn;
    }
}
