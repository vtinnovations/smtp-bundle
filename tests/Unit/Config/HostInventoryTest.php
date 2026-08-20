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

namespace Vtinnovations\SmtpBundle\Tests\Unit\Config;

use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Vtinnovations\SmtpBundle\Config\HostInventory;

/**
 * Normalisation may change how a hostname is written. It may never change which hostname it is.
 * Every test here is one of those two statements.
 */
final class HostInventoryTest extends TestCase
{
    /**
     * @dataProvider equivalentSpellings
     */
    public function testRepresentationIsNormalisedWithoutChangingScope(string $input, string $expected): void
    {
        self::assertSame($expected, $this->inventory()->normalize($input));
    }

    /** @return iterable<string, array{string, string}> */
    public static function equivalentSpellings(): iterable
    {
        yield 'case'          => ['EXAMPLE.COM', 'example.com'];
        yield 'trailing dot'  => ['example.com.', 'example.com'];
        yield 'port'          => ['example.com:8443', 'example.com'];
        yield 'scheme'        => ['https://example.com', 'example.com'];
        yield 'full url'      => ['https://example.com/backend?x=1', 'example.com'];
        yield 'whitespace'    => ['  example.com  ', 'example.com'];
        yield 'subdomain'     => ['Shop.Example.COM', 'shop.example.com'];
    }

    public function testWwwIsNeverStripped(): void
    {
        // These are two different identities and the issuer signs them separately. Collapsing them
        // would hand every apex licence its www host for free, and vice versa.
        self::assertSame('www.example.com', $this->inventory()->normalize('www.example.com'));
        self::assertNotSame(
            $this->inventory()->normalize('www.example.com'),
            $this->inventory()->normalize('example.com'),
        );
    }

    /**
     * @dataProvider unusableHosts
     */
    public function testValuesThatAreNotLicensableHostsAreRefused(?string $input): void
    {
        self::assertNull($this->inventory()->normalize($input));
    }

    /** @return iterable<string, array{string|null}> */
    public static function unusableHosts(): iterable
    {
        yield 'null'          => [null];
        yield 'empty'         => [''];
        yield 'spaces only'   => ['   '];
        yield 'wildcard'      => ['*.example.com'];
        yield 'bare wildcard' => ['*'];
        yield 'ipv4'          => ['192.0.2.10'];
        yield 'ipv4 with port'=> ['192.0.2.10:8080'];
        yield 'ipv6'          => ['[2001:db8::1]'];
        yield 'single label'  => ['localhost'];
        yield 'underscore'    => ['bad_host.example.com'];
    }

    public function testIdnIsFoldedToPunycodeConsistently(): void
    {
        if (!\function_exists('idn_to_ascii')) {
            self::markTestSkipped('ext-intl is not available.');
        }

        $inventory = $this->inventory();

        self::assertSame('xn--bcher-kva.example.com', $inventory->normalize('bücher.example.com'));
        // And the already-encoded spelling maps to the same name, so the two are one identity.
        self::assertSame(
            $inventory->normalize('bücher.example.com'),
            $inventory->normalize('XN--BCHER-KVA.example.com'),
        );
    }

    public function testConfiguredHostsAreNormalisedUniqueAndSorted(): void
    {
        $inventory = $this->inventory(configured: ['B.example.com', 'a.example.com', 'a.example.com.', 'not a host']);

        self::assertSame(['a.example.com', 'b.example.com'], $inventory->configuredHosts());
    }

    public function testTheRouterHostIsOnlyAFallback(): void
    {
        self::assertSame(['fallback.example.com'], $this->inventory(fallback: 'fallback.example.com')->configuredHosts());

        // Once real configuration exists, the fallback is not consulted at all.
        self::assertSame(
            ['configured.example.com'],
            $this->inventory(configured: ['configured.example.com'], fallback: 'fallback.example.com')->configuredHosts(),
        );

        self::assertSame([], $this->inventory(fallback: 'localhost')->configuredHosts());
    }

    public function testVerificationHostPrefersTheCurrentHostWhenItIsOneOfOurs(): void
    {
        $inventory = $this->inventory(
            configured: ['a.example.com', 'b.example.com'],
            currentHost: 'b.example.com',
        );

        self::assertSame('b.example.com', $inventory->verificationHost());
    }

    public function testVerificationHostIsDeterministicWhenTheCurrentHostIsNotOurs(): void
    {
        // A backend served on a host that is not in the inventory must still produce the same
        // answer every time, or background work and the request path would disagree.
        $inventory = $this->inventory(
            configured: ['b.example.com', 'a.example.com'],
            currentHost: 'intranet.example.net',
        );

        self::assertSame('a.example.com', $inventory->verificationHost());
    }

    public function testVerificationHostIsNullWithoutAnyConfiguration(): void
    {
        self::assertNull($this->inventory(currentHost: 'attacker.example.net')->verificationHost());
    }

    public function testMatchedHostIsAnExactIntersection(): void
    {
        $inventory = $this->inventory(configured: ['a.example.com', 'b.example.com']);

        self::assertSame('a.example.com', $inventory->matchedHost(['a.example.com', 'z.example.com']));
        self::assertNull($inventory->matchedHost(['z.example.com']));
        // No suffix logic anywhere: a parent does not cover a child, or the other way round.
        self::assertNull($inventory->matchedHost(['example.com']));
        self::assertNull($inventory->matchedHost(['deep.a.example.com']));
        self::assertNull($inventory->matchedHost([]));
    }

    public function testMatchedHostPrefersTheCurrentHostWithinTheIntersection(): void
    {
        $inventory = $this->inventory(
            configured: ['a.example.com', 'b.example.com'],
            currentHost: 'b.example.com',
        );

        self::assertSame('b.example.com', $inventory->matchedHost(['a.example.com', 'b.example.com']));
    }

    /**
     * @param list<string> $configured
     */
    private function inventory(array $configured = [], string $fallback = '', ?string $currentHost = null): HostInventory
    {
        $stack = new RequestStack();

        if (null !== $currentHost) {
            $stack->push(Request::create('https://'.$currentHost.'/'));
        }

        return new HostInventory($stack, null, $configured, $fallback);
    }
}
