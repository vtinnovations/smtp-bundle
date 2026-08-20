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

namespace Vtinnovations\SmtpBundle\Tests\Unit\Http;

use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Vtinnovations\SmtpBundle\Config\DeploymentProfile;
use Vtinnovations\SmtpBundle\Http\SignalDispatcher;

/**
 * Two separate event shapes, never merged. The per-invocation one must never carry the key; the
 * module-entry one is the single exception that may.
 */
final class SignalDispatcherTest extends TestCase
{
    /** @var list<array{url: string, options: array<string, mixed>}> */
    private array $sent = [];

    public function testTheInvocationSignalSendsExactlyProjectAndDomain(): void
    {
        $dispatcher = $this->dispatcher();
        $dispatcher->queueInvocation('example.com');
        $dispatcher->flush();

        self::assertCount(1, $this->sent);
        self::assertSame('https://www.v-t.one/rest/api/v1/log-envoke', $this->sent[0]['url']);
        self::assertSame(DeploymentProfile::signalEndpoint(), $this->sent[0]['url']);
        self::assertSame(['project' => DeploymentProfile::PROJECT, 'domain' => 'example.com'], $this->bodyOf(0));
    }

    public function testTheInvocationSignalNeverCarriesAKey(): void
    {
        $dispatcher = $this->dispatcher();
        $dispatcher->queueInvocation('example.com');
        $dispatcher->flush();

        self::assertArrayNotHasKey('key', $this->bodyOf(0));
    }

    public function testTheModuleEntrySignalSendsExactlyDomainAndKey(): void
    {
        $dispatcher = $this->dispatcher();
        $dispatcher->queueModuleEntry('example.com', 'AAAAA-BBBBB');
        $dispatcher->flush();

        self::assertCount(1, $this->sent);
        self::assertSame(['domain' => 'example.com', 'key' => 'AAAAA-BBBBB'], $this->bodyOf(0));
        self::assertArrayNotHasKey('project', $this->bodyOf(0));
    }

    public function testTheInvocationSignalIsRaisedAtMostOncePerProcess(): void
    {
        $dispatcher = $this->dispatcher();
        $dispatcher->queueInvocation('example.com');
        $dispatcher->queueInvocation('example.com');
        $dispatcher->flush();
        $dispatcher->queueInvocation('example.com');
        $dispatcher->flush();

        self::assertCount(1, $this->sent);
    }

    public function testTheTransportControlsAreExplicit(): void
    {
        $dispatcher = $this->dispatcher();
        $dispatcher->queueInvocation('example.com');
        $dispatcher->flush();

        $options = $this->sent[0]['options'];

        self::assertSame(0, $options['max_redirects']);
        self::assertTrue($options['verify_peer']);
        self::assertTrue($options['verify_host']);
        // Symfony's HttpClient option resolver normalises timeout/max_duration to float
        // regardless of the int type passed in, so the comparison must match that, not this
        // client's own constants' declared type.
        self::assertSame((float) DeploymentProfile::SIGNAL_TIMEOUT_SECONDS, $options['timeout']);
        self::assertSame((float) DeploymentProfile::SIGNAL_TIMEOUT_SECONDS, $options['max_duration']);
    }

    public function testNothingIsSentWithoutADomain(): void
    {
        $dispatcher = $this->dispatcher();
        $dispatcher->queueInvocation('');
        $dispatcher->queueModuleEntry('', 'KEY');
        $dispatcher->queueModuleEntry('example.com', '');
        $dispatcher->flush();

        self::assertSame([], $this->sent);
    }

    public function testAFailureIsSilentAndNotRetried(): void
    {
        $attempts = 0;

        $client = new MockHttpClient(static function () use (&$attempts): MockResponse {
            ++$attempts;

            throw new TransportException('unreachable');
        });

        $dispatcher = new SignalDispatcher($client);
        $dispatcher->queueModuleEntry('example.com', 'KEY');
        $dispatcher->flush();
        $dispatcher->flush();

        self::assertSame(1, $attempts, 'The queue is cleared whatever happened.');
        self::assertFalse($dispatcher->hasQueued());
    }

    public function testTheQueueIsClearedAfterFlushing(): void
    {
        $dispatcher = $this->dispatcher();

        self::assertFalse($dispatcher->hasQueued());

        $dispatcher->queueModuleEntry('example.com', 'KEY');
        self::assertTrue($dispatcher->hasQueued());

        $dispatcher->flush();
        self::assertFalse($dispatcher->hasQueued());

        $dispatcher->flush();
        self::assertCount(1, $this->sent);
    }

    private function dispatcher(): SignalDispatcher
    {
        $client = new MockHttpClient(function (string $method, string $url, array $options): MockResponse {
            $this->sent[] = ['url' => $url, 'options' => $options];

            return new MockResponse('irrelevant');
        });

        return new SignalDispatcher($client);
    }

    /** @return array<string, mixed> */
    private function bodyOf(int $index): array
    {
        return (array) json_decode((string) ($this->sent[$index]['options']['body'] ?? '{}'), true);
    }
}
