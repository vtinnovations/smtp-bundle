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

namespace Vtinnovations\SmtpBundle\Tests\Unit\Service;

use Contao\CoreBundle\Csrf\ContaoCsrfTokenManager;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Contracts\Translation\TranslatorInterface;
use Vtinnovations\SmtpBundle\Cache\CacheClearService;
use Vtinnovations\SmtpBundle\Command\DisableSmtpCommand;
use Vtinnovations\SmtpBundle\Dotenv\DotenvWriter;
use Vtinnovations\SmtpBundle\Exception\NotEntitledException;
use Vtinnovations\SmtpBundle\Mailer\DsnBuilder;
use Vtinnovations\SmtpBundle\Mailer\TestMailService;
use Vtinnovations\SmtpBundle\Service\SmtpConfigHandler;
use Vtinnovations\SmtpBundle\Tests\Fixture\Installation;

/**
 * Every protected operation asks on its own behalf.
 *
 * That is the property being pinned down here: there is no single gate whose removal opens the
 * rest, and no path — screen, service call or console command — that reaches the behaviour without
 * being asked. Hiding a form is not authorisation.
 */
final class EntitlementGatesTest extends TestCase
{
    private Installation $install;

    protected function setUp(): void
    {
        $this->install = new Installation();
    }

    protected function tearDown(): void
    {
        $this->install->cleanUp();
    }

    public function testConfiguringSmtpIsRefusedWithoutEntitlement(): void
    {
        // Reached directly as a service call, with no screen involved.
        $this->expectException(NotEntitledException::class);

        $this->handler()->handle([
            'host'           => 'mail.example.com',
            'port'           => 587,
            'encryption'     => 'tls',
            'username'       => 'u',
            'password'       => 'p',
            'from_email'     => 'from@example.com',
            'test_recipient' => 'to@example.com',
        ]);
    }

    public function testSendingATestMailIsRefusedWithoutEntitlement(): void
    {
        $this->expectException(NotEntitledException::class);

        (new TestMailService($this->install->reader))->sendTest('smtp://example.com', 'a@example.com', 'b@example.com');
    }

    public function testClearingTheCacheIsRefusedWithoutEntitlement(): void
    {
        $this->expectException(NotEntitledException::class);

        (new CacheClearService($this->install->projectDir, 'true', 10, $this->install->reader))->clearAndWarmup();
    }

    public function testTheConsoleCommandIsRefusedWithoutEntitlement(): void
    {
        $tester = new CommandTester($this->command());

        self::assertSame(Command::FAILURE, $tester->execute([]));
        self::assertStringContainsString('not available', $tester->getDisplay());
    }

    public function testTheConsoleCommandRunsForAnEntitledInstallation(): void
    {
        $this->install->install();

        $tester = new CommandTester($this->command());

        self::assertSame(Command::SUCCESS, $tester->execute([]));
    }

    public function testTheGateNamesTheReasonInternallyOnly(): void
    {
        try {
            (new TestMailService($this->install->reader))->sendTest('smtp://example.com', 'a@example.com', 'b@example.com');

            self::fail('Expected a refusal.');
        } catch (NotEntitledException $e) {
            self::assertSame('no_state', $e->reason);
            self::assertStringContainsString('smtp.test_mail', $e->getMessage());
        }
    }

    public function testEachGateIsIndependent(): void
    {
        // Four separate services, four separate refusals. Removing any one of them leaves the other
        // three refusing, which is the whole point of not having a single choke point.
        $refused = 0;

        foreach ([
            fn () => $this->handler()->handle(['host' => 'mail.example.com', 'from_email' => 'a@example.com', 'test_recipient' => 'b@example.com']),
            fn () => (new TestMailService($this->install->reader))->sendTest('smtp://example.com', 'a@example.com', 'b@example.com'),
            fn () => (new CacheClearService($this->install->projectDir, 'true', 10, $this->install->reader))->clearAndWarmup(),
        ] as $operation) {
            try {
                $operation();
            } catch (NotEntitledException) {
                ++$refused;
            }
        }

        self::assertSame(3, $refused);
        self::assertSame(Command::FAILURE, (new CommandTester($this->command()))->execute([]));
    }

    private function handler(): SmtpConfigHandler
    {
        $translator = $this->createMock(TranslatorInterface::class);
        $translator->method('trans')->willReturnArgument(0);

        return new SmtpConfigHandler(
            new DsnBuilder(),
            new TestMailService($this->install->reader),
            new DotenvWriter($this->install->projectDir),
            new CacheClearService($this->install->projectDir, 'true', 10, $this->install->reader),
            $this->createStub(ContaoCsrfTokenManager::class),
            $translator,
            $this->install->reader,
            'csrf_token',
        );
    }

    private function command(): DisableSmtpCommand
    {
        return new DisableSmtpCommand(
            new DotenvWriter($this->install->projectDir),
            new CacheClearService($this->install->projectDir, 'true', 10, $this->install->reader),
            $this->install->reader,
        );
    }
}
