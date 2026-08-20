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

namespace Vtinnovations\SmtpBundle\Config;

use Doctrine\DBAL\Connection;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Which hostnames this installation is actually configured to be, and which one to present when
 * asking the remote side about them.
 *
 * The list comes from configuration the site owner deliberately set — root-page DNS entries, or an
 * explicit bundle setting — never from a request header. A `Host:` header is attacker-supplied on
 * any installation that does not pin trusted hosts, so accepting it here would let anyone pick
 * which identity the installation claims.
 *
 * Matching is exact throughout. `example.com`, `www.example.com`, `shop.example.com` and
 * `admin.shop.example.com` are four different identities, and nothing here collapses them: no
 * suffix comparison, no registrable-domain reduction, no `www.` stripping, no alias following.
 * Normalization only changes representation — case, one trailing dot, a port, IDN spelling — and
 * never the labels themselves.
 */
final class HostInventory
{
    /**
     * @param list<string> $configuredDomains explicit instance domains from bundle configuration,
     *                                        used when root pages carry no DNS entry (a fresh or
     *                                        single-domain installation)
     */
    public function __construct(
        private readonly RequestStack $requestStack,
        private readonly ?Connection $connection = null,
        private readonly array $configuredDomains = [],
        private readonly string $fallbackHost = '',
    ) {
    }

    /**
     * Representation-only normalization.
     *
     * Returns null for anything that is not a hostname this product can be bound to: an empty
     * value, a bare IP address (records bind to names, and an IP is not one), a wildcard, or a
     * string that survives none of the above as a valid host.
     */
    public function normalize(?string $value): ?string
    {
        $value = trim((string) $value);

        if ('' === $value) {
            return null;
        }

        // Accept a full URL as readily as a bare host, so a configured `https://example.com/` and a
        // configured `example.com` end up at the same place.
        if (!preg_match('~^[a-z][a-z0-9+.-]*://~i', $value)) {
            $value = 'http://'.$value;
        }

        $host = parse_url($value, PHP_URL_HOST);

        if (!\is_string($host) || '' === $host) {
            return null;
        }

        // One trailing dot only: `example.com.` and `example.com` are the same name written twice.
        $host = strtolower($host);

        if (str_ends_with($host, '.')) {
            $host = substr($host, 0, -1);
        }

        if ('' === $host) {
            return null;
        }

        // A wildcard is not a hostname. It is refused rather than interpreted, because interpreting
        // it is exactly the scope broadening this whole class exists to prevent.
        if (str_contains($host, '*')) {
            return null;
        }

        // parse_url leaves an IPv6 literal in brackets; either way an address is not a name.
        $bare = trim($host, '[]');

        if (false !== filter_var($bare, FILTER_VALIDATE_IP)) {
            return null;
        }

        if (preg_match('/[^\x20-\x7e]/', $host)) {
            if (!\function_exists('idn_to_ascii')) {
                // Without intl there is no way to reach the same spelling the remote side signed,
                // and guessing would either widen or narrow the match. Refuse instead.
                return null;
            }

            $ascii = idn_to_ascii($host, IDNA_NONTRANSITIONAL_TO_ASCII, INTL_IDNA_VARIANT_UTS46);

            if (!\is_string($ascii) || '' === $ascii) {
                return null;
            }

            $host = strtolower($ascii);
        }

        if (!filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME)) {
            return null;
        }

        // FILTER_FLAG_HOSTNAME accepts a single label; a licensable host has at least two.
        if (!str_contains($host, '.')) {
            return null;
        }

        return $host;
    }

    /**
     * Every hostname this instance is configured to answer to, normalized, unique and sorted.
     *
     * @return list<string>
     */
    public function configuredHosts(): array
    {
        $hosts = [];

        foreach ($this->rootPageDomains() as $candidate) {
            $host = $this->normalize($candidate);

            if (null !== $host) {
                $hosts[$host] = true;
            }
        }

        foreach ($this->configuredDomains as $candidate) {
            $host = $this->normalize(\is_string($candidate) ? $candidate : null);

            if (null !== $host) {
                $hosts[$host] = true;
            }
        }

        // Last resort only: the router's configured default host. Still configuration — it is what
        // console commands generate URLs with — but it is a poor identity, so it is used only when
        // nothing better was set up.
        if ([] === $hosts) {
            $host = $this->normalize($this->fallbackHost);

            if (null !== $host && 'localhost' !== $host) {
                $hosts[$host] = true;
            }
        }

        $hosts = array_keys($hosts);
        sort($hosts, SORT_STRING);

        return $hosts;
    }

    /**
     * The host this request arrived on, as the framework resolved it.
     *
     * Symfony applies the application's trusted-proxy and trusted-host settings here, so a spoofed
     * `X-Forwarded-Host` on an installation that pins its proxies does not reach this. It is still
     * only ever used after {@see configuredHosts()} has vouched for it.
     */
    public function trustedCurrentHost(): ?string
    {
        $request = $this->requestStack->getMainRequest();

        if (null === $request) {
            return null;
        }

        return $this->normalize($request->getHost());
    }

    /**
     * The host to name in an activation or refresh, chosen the same way every time.
     *
     * Current host when it is one of ours, otherwise the first configured one. Deterministic on
     * purpose: background work and the session signal must reach the same answer as the request
     * that activated, without depending on whatever `Host` happens to be in play.
     */
    public function verificationHost(): ?string
    {
        $configured = $this->configuredHosts();

        if ([] === $configured) {
            return null;
        }

        $current = $this->trustedCurrentHost();

        if (null !== $current && \in_array($current, $configured, true)) {
            return $current;
        }

        return $configured[0];
    }

    /**
     * The host both sides agree on: configured here, and signed over there.
     *
     * @param list<string> $signedHosts
     */
    public function matchedHost(array $signedHosts): ?string
    {
        $intersection = array_values(array_intersect($this->configuredHosts(), $signedHosts));

        if ([] === $intersection) {
            return null;
        }

        $current = $this->trustedCurrentHost();

        if (null !== $current && \in_array($current, $intersection, true)) {
            return $current;
        }

        return $intersection[0];
    }

    /**
     * Root-page DNS entries, which is where a Contao installation records the hostnames it serves.
     *
     * Swallows database errors on purpose: during installation, or before the first migration, the
     * table simply is not there yet, and that is not a licensing failure.
     *
     * @return list<string>
     */
    private function rootPageDomains(): array
    {
        if (null === $this->connection) {
            return [];
        }

        try {
            $rows = $this->connection->fetchFirstColumn(
                "SELECT DISTINCT dns FROM tl_page WHERE type = 'root' AND dns != ''",
            );
        } catch (\Throwable) {
            return [];
        }

        return array_values(array_filter($rows, static fn ($v): bool => \is_string($v) && '' !== $v));
    }
}
