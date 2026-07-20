<?php

declare(strict_types=1);

namespace Vtinnovations\SmtpBundle\Service;

use Contao\CoreBundle\Csrf\ContaoCsrfTokenManager;
use Vtinnovations\SmtpBundle\Cache\CacheClearService;
use Vtinnovations\SmtpBundle\Dotenv\DotenvWriter;
use Vtinnovations\SmtpBundle\Exception\CacheClearException;
use Vtinnovations\SmtpBundle\Exception\DotenvWriteException;
use Vtinnovations\SmtpBundle\Exception\InvalidDsnException;
use Vtinnovations\SmtpBundle\Mailer\DsnBuilder;
use Vtinnovations\SmtpBundle\Mailer\TestMailService;

final class SmtpConfigHandler
{
    public function __construct(
        private readonly DsnBuilder $dsnBuilder,
        private readonly TestMailService $testMailService,
        private readonly DotenvWriter $dotenvWriter,
        private readonly CacheClearService $cacheClearService,
        private readonly ContaoCsrfTokenManager $csrfTokenManager,
        private readonly string $csrfTokenName,
    ) {
    }

    /**
     * Returns the value for the REQUEST_TOKEN hidden field Contao expects in every backend POST.
     */
    public function getRequestTokenValue(): string
    {
        return $this->csrfTokenManager->getToken($this->csrfTokenName)->getValue();
    }

    /**
     * @param array{host: string, port: int, encryption: string, username: string, password: string, from_email: string, test_recipient: string} $data
     */
    public function handle(array $data): HandleResult
    {
        $host = trim($data['host'] ?? '');
        $port = (int) ($data['port'] ?? 587);
        $encryption = $data['encryption'] ?? 'tls';
        $username = trim($data['username'] ?? '');
        $password = $data['password'] ?? '';
        $fromEmail = trim($data['from_email'] ?? '');
        $testRecipient = trim($data['test_recipient'] ?? '');

        // Basic validation
        if ($host === '') {
            return new HandleResult(false, 'SMTP-Host ist erforderlich.');
        }

        if ($fromEmail === '' || !filter_var($fromEmail, \FILTER_VALIDATE_EMAIL)) {
            return new HandleResult(false, 'Gültige Absender-E-Mail-Adresse ist erforderlich.');
        }

        if ($testRecipient === '' || !filter_var($testRecipient, \FILTER_VALIDATE_EMAIL)) {
            return new HandleResult(false, 'Gültige Test-Empfänger-Adresse ist erforderlich.');
        }

        // If password left blank, try to reuse existing DSN password
        if ($password === '') {
            $password = $this->extractExistingPassword($username);
        }

        // Build DSN
        try {
            $dsn = $this->dsnBuilder->build($host, $port, $username, $password, $encryption);
        } catch (InvalidDsnException $e) {
            return new HandleResult(false, 'Ungültige Konfiguration: ' . $e->getMessage());
        }

        // Test mail — must succeed before persisting
        $testResult = $this->testMailService->sendTest($dsn, $fromEmail, $testRecipient);

        if (!$testResult->success) {
            return new HandleResult(
                false,
                \sprintf(
                    'Test-Mail fehlgeschlagen (%.2fs): %s',
                    $testResult->duration,
                    $testResult->error,
                ),
            );
        }

        // Persist
        try {
            $this->dotenvWriter->write('MAILER_DSN', $dsn);
        } catch (DotenvWriteException $e) {
            return new HandleResult(false, 'Fehler beim Speichern: ' . $e->getMessage());
        }

        // Clear cache
        try {
            $this->cacheClearService->clearAndWarmup();
        } catch (CacheClearException $e) {
            return new HandleResult(
                false,
                'Konfiguration gespeichert, aber Cache-Clear fehlgeschlagen. Bitte manuell leeren. Fehler: ' . $e->getMessage(),
            );
        }

        return new HandleResult(
            true,
            \sprintf('Test-Mail erfolgreich gesendet (%.2fs). Konfiguration gespeichert, Cache geleert.', $testResult->duration),
        );
    }

    public function isConfigured(): bool
    {
        return $this->dotenvWriter->read('MAILER_DSN') !== null;
    }

    /**
     * Parse the existing MAILER_DSN back into form fields for pre-population.
     * Password is intentionally omitted (blank = reuse existing).
     *
     * @return array{host: string, port: int, encryption: string, username: string, password: string, from_email: string, test_recipient: string}
     */
    public function getCurrentConfig(): array
    {
        $defaults = [
            'host'           => '',
            'port'           => 587,
            'encryption'     => 'tls',
            'username'       => '',
            'password'       => '',
            'from_email'     => '',
            'test_recipient' => '',
        ];

        $dsn = $this->dotenvWriter->read('MAILER_DSN');

        if ($dsn === null) {
            return $defaults;
        }

        $parsed = parse_url($dsn);

        if ($parsed === false) {
            return $defaults;
        }

        $scheme = $parsed['scheme'] ?? 'smtp';
        $query  = [];
        parse_str($parsed['query'] ?? '', $query);

        if ($scheme === 'smtps') {
            $encryption = 'ssl';
        } elseif (($query['encryption'] ?? '') === 'tls') {
            $encryption = 'tls';
        } else {
            $encryption = 'none';
        }

        return [
            'host'           => rawurldecode($parsed['host'] ?? ''),
            'port'           => isset($parsed['port']) ? (int) $parsed['port'] : $defaults['port'],
            'encryption'     => $encryption,
            'username'       => rawurldecode($parsed['user'] ?? ''),
            'password'       => '',   // never pre-fill; blank = reuse existing
            'from_email'     => '',
            'test_recipient' => '',
        ];
    }

    /**
     * Try to extract password from existing MAILER_DSN to allow updating
     * host/port/encryption without re-entering the password.
     */
    private function extractExistingPassword(string $username): string
    {
        $existing = $this->dotenvWriter->read('MAILER_DSN');

        if ($existing === null || $username === '') {
            return '';
        }

        $parsed = parse_url($existing);

        if (!isset($parsed['pass'])) {
            return '';
        }

        return rawurldecode($parsed['pass']);
    }
}
