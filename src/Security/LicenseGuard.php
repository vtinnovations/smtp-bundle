<?php

declare(strict_types=1);

namespace Vtinnovations\SmtpBundle\Security;

use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * Gate check + standard response helper. Paid-only product, single gate (isLicensed).
 */
final class LicenseGuard
{
    public function __construct(private readonly LicenseManager $licenseManager)
    {
    }

    public function isLicensed(): bool
    {
        return $this->licenseManager->isLicensed();
    }

    public function noLicenseResponsePaidOnly(): JsonResponse
    {
        return new JsonResponse([
            'success' => false,
            'reason' => 'no_license',
            'error' => 'This plugin requires a license. Get yours at v-t.one.',
            'error_de' => 'Dieses Plugin benötigt eine Lizenz. Erhältlich auf v-t.one.',
            'cta_url' => 'https://v-t.one',
        ]);
    }
}
