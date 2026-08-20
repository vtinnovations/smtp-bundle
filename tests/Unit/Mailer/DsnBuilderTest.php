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

namespace Vtinnovations\SmtpBundle\Tests\Unit\Mailer;

use PHPUnit\Framework\TestCase;
use Vtinnovations\SmtpBundle\Exception\InvalidDsnException;
use Vtinnovations\SmtpBundle\Mailer\DsnBuilder;

final class DsnBuilderTest extends TestCase
{
    private DsnBuilder $builder;

    protected function setUp(): void
    {
        $this->builder = new DsnBuilder();
    }

    public function testSmtpNoEncryption(): void
    {
        $dsn = $this->builder->build('mail.example.com', 587, 'user', 'pass', 'none');

        self::assertSame('smtp://user:pass@mail.example.com:587', $dsn);
    }

    public function testSmtpStarttls(): void
    {
        $dsn = $this->builder->build('mail.example.com', 587, 'user', 'pass', 'tls');

        self::assertSame('smtp://user:pass@mail.example.com:587?encryption=tls', $dsn);
    }

    public function testSmtpSsl(): void
    {
        $dsn = $this->builder->build('mail.example.com', 465, 'user', 'pass', 'ssl');

        self::assertSame('smtps://user:pass@mail.example.com:465', $dsn);
    }

    public function testSpecialCharsInCredentialsAreUrlEncoded(): void
    {
        $dsn = $this->builder->build('mail.example.com', 587, 'user@domain.com', 'p@ss#word!', 'none');

        self::assertStringContainsString('user%40domain.com', $dsn);
        self::assertStringContainsString('p%40ss%23word%21', $dsn);
    }

    public function testNoCredentials(): void
    {
        $dsn = $this->builder->build('mail.example.com', 25, '', '', 'none');

        self::assertSame('smtp://mail.example.com:25', $dsn);
    }

    public function testEmptyHostThrows(): void
    {
        $this->expectException(InvalidDsnException::class);

        $this->builder->build('', 587, 'user', 'pass', 'none');
    }

    public function testInvalidPortThrows(): void
    {
        $this->expectException(InvalidDsnException::class);

        $this->builder->build('mail.example.com', 99999, 'user', 'pass', 'none');
    }

    public function testInvalidEncryptionThrows(): void
    {
        $this->expectException(InvalidDsnException::class);

        $this->builder->build('mail.example.com', 587, 'user', 'pass', 'starttls');
    }
}
