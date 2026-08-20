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

use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Vtinnovations\SmtpBundle\Http\SignalDispatcher;
use Vtinnovations\SmtpBundle\Service\ModuleEntrySignal;
use Vtinnovations\SmtpBundle\Tests\Fixture\Installation;

/**
 * Once per authenticated session — not once per process, not once ever, and not once per page load.
 * A reload, an AJAX call or a second tab must all find the slot already claimed.
 */
final class ModuleEntrySignalTest extends TestCase
{
    private Installation $install;
    private Session $session;
    private RequestStack $requestStack;

    /** @var list<array<string, mixed>> */
    private array $sent = [];

    protected function setUp(): void
    {
        $this->install      = new Installation();
        $this->session      = new Session(new MockArraySessionStorage());
        $this->requestStack = new RequestStack();

        $request = Request::create('https://example.com/contao');
        $request->setSession($this->session);
        $this->requestStack->push($request);
    }

    protected function tearDown(): void
    {
        $this->install->cleanUp();
    }

    public function testTheFirstEntryInASessionSendsOneEvent(): void
    {
        $this->install->install();

        $dispatcher = $this->dispatcher();
        $this->signal($dispatcher)->onModuleEntry();
        $dispatcher->flush();

        self::assertCount(1, $this->sent);
        self::assertSame(['domain' => 'example.com', 'key' => 'AAAAA-BBBBB-CCCCC-DDDDD'], $this->sent[0]);
    }

    public function testReloadsAndAjaxCallsInTheSameSessionSendNothingFurther(): void
    {
        $this->install->install();

        $dispatcher = $this->dispatcher();
        $signal     = $this->signal($dispatcher);

        $signal->onModuleEntry();
        $signal->onModuleEntry();
        $signal->onModuleEntry();
        $dispatcher->flush();

        self::assertCount(1, $this->sent);
    }

    public function testAParallelTabFindsTheSlotAlreadyClaimed(): void
    {
        // Two service instances, one session — which is what parallel requests look like. PHP's
        // session lock is what makes the read-claim-write sequence indivisible in practice.
        $this->install->install();

        $dispatcher = $this->dispatcher();

        $this->signal($dispatcher)->onModuleEntry();
        $this->signal($dispatcher)->onModuleEntry();
        $dispatcher->flush();

        self::assertCount(1, $this->sent);
    }

    public function testANewSessionMayClaimAgain(): void
    {
        $this->install->install();

        $dispatcher = $this->dispatcher();
        $this->signal($dispatcher)->onModuleEntry();

        // A fresh login is a fresh session, and the claim does not survive it.
        $this->session->clear();

        $this->signal($dispatcher)->onModuleEntry();
        $dispatcher->flush();

        self::assertCount(2, $this->sent);
    }

    public function testNothingIsClaimedOrSentWithoutAnAuthenticRecord(): void
    {
        // No record at all.
        $dispatcher = $this->dispatcher();
        $this->signal($dispatcher)->onModuleEntry();
        $dispatcher->flush();

        self::assertSame([], $this->sent);
        self::assertSame([], $this->session->get('_vtinnovations_smtp_entry', []));
    }

    public function testATamperedRecordYieldsNothing(): void
    {
        $this->install->install();

        $bytes = (string) file_get_contents($this->install->statePath('record.json'));
        file_put_contents($this->install->statePath('record.json'), str_replace('AAAAA', 'ZZZZZ', $bytes));
        $this->install->reader->reset();

        $dispatcher = $this->dispatcher();
        $this->signal($dispatcher)->onModuleEntry();
        $dispatcher->flush();

        self::assertSame([], $this->sent);
    }

    public function testAnAuthenticButWithheldRecordStillSends(): void
    {
        // An expired record is still a genuine one. The event is defined on the record, not on the
        // entitlement it happens to grant today.
        $this->install->install(['license_expires_at' => time() - 10, 'free_available' => false]);

        self::assertFalse($this->install->reader->isGranted());

        $dispatcher = $this->dispatcher();
        $this->signal($dispatcher)->onModuleEntry();
        $dispatcher->flush();

        self::assertCount(1, $this->sent);
    }

    public function testTheClaimMarkerHoldsNoSecrets(): void
    {
        $this->install->install();

        $dispatcher = $this->dispatcher();
        $this->signal($dispatcher)->onModuleEntry();

        $marker = json_encode($this->session->all());

        self::assertStringNotContainsString('AAAAA-BBBBB-CCCCC-DDDDD', (string) $marker);
        self::assertStringNotContainsString('example.com', (string) $marker);
        self::assertSame(['smtp'], $this->session->get('_vtinnovations_smtp_entry'));
    }

    public function testAFailedDeliveryIsNotRetriedInTheSameSession(): void
    {
        $this->install->install();

        $attempts = 0;
        $client   = new MockHttpClient(static function () use (&$attempts): MockResponse {
            ++$attempts;

            throw new \Symfony\Component\HttpClient\Exception\TransportException('unreachable');
        });

        $dispatcher = new SignalDispatcher($client);
        $signal     = $this->signal($dispatcher);

        $signal->onModuleEntry();
        $dispatcher->flush();
        $signal->onModuleEntry();
        $dispatcher->flush();

        self::assertSame(1, $attempts, 'The slot was claimed before delivery, so a failure ends it.');
    }

    public function testNothingHappensWithoutASession(): void
    {
        $this->install->install();

        $stack = new RequestStack();
        $stack->push(Request::create('https://example.com/'));

        $dispatcher = $this->dispatcher();
        (new ModuleEntrySignal($stack, $this->install->reader, $dispatcher))->onModuleEntry();
        $dispatcher->flush();

        self::assertSame([], $this->sent);
    }

    private function signal(SignalDispatcher $dispatcher): ModuleEntrySignal
    {
        return new ModuleEntrySignal($this->requestStack, $this->install->reader, $dispatcher);
    }

    private function dispatcher(): SignalDispatcher
    {
        $client = new MockHttpClient(function (string $method, string $url, array $options): MockResponse {
            $this->sent[] = (array) json_decode((string) ($options['body'] ?? '{}'), true);

            return new MockResponse('irrelevant');
        });

        return new SignalDispatcher($client);
    }
}
