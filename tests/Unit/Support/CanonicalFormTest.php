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

namespace Vtinnovations\SmtpBundle\Tests\Unit\Support;

use PHPUnit\Framework\TestCase;
use Vtinnovations\SmtpBundle\Support\CanonicalForm;

/**
 * The canonical form is a contract with the issuer. If the two implementations ever drift, every
 * signature fails — so these assert exact bytes, not "equivalent JSON".
 */
final class CanonicalFormTest extends TestCase
{
    private CanonicalForm $form;

    protected function setUp(): void
    {
        $this->form = new CanonicalForm();
    }

    public function testObjectKeysAreSortedRecursively(): void
    {
        self::assertSame(
            '{"a":1,"b":{"x":2,"y":3},"c":4}',
            $this->form->encode(['c' => 4, 'a' => 1, 'b' => ['y' => 3, 'x' => 2]]),
        );
    }

    public function testListOrderIsPreserved(): void
    {
        // Sorting lists here would hide a domain list that arrived in an order the issuer would
        // never have signed.
        self::assertSame(
            '{"license_domains":["z.example.com","a.example.com"]}',
            $this->form->encode(['license_domains' => ['z.example.com', 'a.example.com']]),
        );
    }

    public function testSlashesAndUnicodeAreNotEscaped(): void
    {
        self::assertSame(
            '{"host":"https://example.com/x","name":"Ünïcode"}',
            $this->form->encode(['host' => 'https://example.com/x', 'name' => 'Ünïcode']),
        );
    }

    public function testScalarTypesArePreserved(): void
    {
        self::assertSame(
            '{"a":false,"b":null,"c":0,"d":"0"}',
            $this->form->encode(['a' => false, 'b' => null, 'c' => 0, 'd' => '0']),
        );
    }

    public function testSignatureIsRemovedBeforeSigning(): void
    {
        // Otherwise the signature would have to sign itself, which cannot be satisfied.
        self::assertSame(
            '{"a":1}',
            $this->form->of(['a' => 1, 'signature' => 'anything']),
        );
    }

    public function testNestedSignatureFieldsAreLeftAlone(): void
    {
        // Only the top-level one is excluded; a field that happens to be called "signature" deeper
        // in the document is ordinary signed data.
        self::assertSame(
            '{"inner":{"signature":"kept"}}',
            $this->form->of(['inner' => ['signature' => 'kept'], 'signature' => 'dropped']),
        );
    }

    public function testFixedVector(): void
    {
        // A frozen expectation, so a refactor that changes the byte output is caught even if every
        // rule above still individually holds.
        $document = [
            'schema_version'      => 2,
            'project'             => 'SMTP',
            'project_slug'        => 'smtp',
            'license_domain'      => 'example.com',
            'license_domains'     => ['example.com', 'staging.example.com'],
            'license_max_domains' => 3,
            'license_lifetime'    => false,
            'license_expires_at'  => null,
            'signature'           => 'ignored',
        ];

        self::assertSame(
            '{"license_domain":"example.com","license_domains":["example.com","staging.example.com"]'
            .',"license_expires_at":null,"license_lifetime":false,"license_max_domains":3'
            .',"project":"SMTP","project_slug":"smtp","schema_version":2}',
            $this->form->of($document),
        );
    }
}
