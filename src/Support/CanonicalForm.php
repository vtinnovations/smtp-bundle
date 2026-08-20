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

namespace Vtinnovations\SmtpBundle\Support;

/**
 * Rebuilds the exact bytes the remote side signed, so a detached signature can be checked here.
 *
 * This is the `vt-one/canonical-json-v1` form and it MUST stay byte-for-byte identical to the
 * signer's. If the two ever drift, every signature fails. The rules:
 *
 *   1. The top-level `signature` key is removed, so nothing ever signs itself.
 *   2. Object keys sorted ascending bytewise, recursively.
 *   3. List order preserved exactly — the order is meaningful (`license_domains` is sorted by the
 *      signer, and re-sorting it here would hide a list that arrived out of order).
 *   4. UTF-8, no pretty-printing, slashes and Unicode unescaped.
 *   5. Scalar types preserved: `false` is not `"false"`, `null` is not `0`.
 */
final class CanonicalForm
{
    /**
     * The bytes that were signed: everything except `signature`.
     *
     * @param array<string, mixed> $payload
     *
     * @throws \JsonException
     */
    public function of(array $payload): string
    {
        unset($payload['signature']);

        return $this->encode($payload);
    }

    /**
     * @param array<string, mixed> $data
     *
     * @throws \JsonException
     */
    public function encode(array $data): string
    {
        return json_encode(
            $this->normalize($data),
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        );
    }

    private function normalize(mixed $value): mixed
    {
        if (!\is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            return array_map(fn ($item): mixed => $this->normalize($item), $value);
        }

        ksort($value, SORT_STRING);

        return array_map(fn ($item): mixed => $this->normalize($item), $value);
    }
}
