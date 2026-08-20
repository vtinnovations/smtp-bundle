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
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Vtinnovations\SmtpBundle\Config\HostInventory;
use Vtinnovations\SmtpBundle\Service\Entitlement;
use Vtinnovations\SmtpBundle\Service\RecordInspector;
use Vtinnovations\SmtpBundle\Support\CanonicalForm;
use Vtinnovations\SmtpBundle\Support\DetachedSignature;
use Vtinnovations\SmtpBundle\Support\TrustedKeys;
use Vtinnovations\SmtpBundle\Tests\Fixture\RecordFactory;

/**
 * Everything the inspector refuses is something an installation could otherwise have talked itself
 * into: a record edited on disk, a record belonging to another product, a record for a hostname
 * this installation does not serve, an expired record, or a signed host list read more generously
 * than it was written.
 */
final class RecordInspectorTest extends TestCase
{
    private RecordFactory $factory;

    protected function setUp(): void
    {
        $this->factory = new RecordFactory();
    }

    public function testAGenuineRecordGrantsEntitlement(): void
    {
        $state = $this->inspect();

        self::assertTrue($state->granted);
        self::assertSame(Entitlement::OK, $state->reason);
        self::assertSame('free', $state->package);
        self::assertSame('free', $state->tier);
        self::assertTrue($state->isFree());
        self::assertNull($state->expiresAt);
        self::assertSame(7, $state->version);
        self::assertSame('example.com', $state->matchedHost);
        self::assertSame(['example.com', 'staging.example.com'], $state->boundHosts);
        self::assertSame(3, $state->maxHosts);
    }

    // --- signature and identity -------------------------------------------------------------

    public function testAnEditedRecordFailsItsSignature(): void
    {
        $bytes    = $this->factory->bytes();
        $tampered = str_replace('"free"', '"paid"', $bytes);

        self::assertSame(Entitlement::BAD_SIGNATURE, $this->inspector()->inspect($tampered)->reason);
    }

    public function testExtendingTheExpiryOnDiskFails(): void
    {
        // The most obvious edit anyone would try, and the one the signature exists for.
        $document = $this->factory->signed($this->factory->document());
        $document['license_expires_at'] = time() + 999999999;

        $forged = (string) json_encode($document, JSON_UNESCAPED_SLASHES);

        self::assertSame(Entitlement::BAD_SIGNATURE, $this->inspector()->inspect($forged)->reason);
    }

    public function testAddingAHostOnDiskFails(): void
    {
        $document = $this->factory->signed($this->factory->document());
        $document['license_domains'][] = 'attacker.example.net';

        $forged = (string) json_encode($document, JSON_UNESCAPED_SLASHES);

        self::assertSame(Entitlement::BAD_SIGNATURE, $this->inspector()->inspect($forged)->reason);
    }

    public function testAnUnsignedRecordIsMalformed(): void
    {
        $bytes = (string) json_encode($this->factory->document(), JSON_UNESCAPED_SLASHES);

        self::assertSame(Entitlement::MALFORMED, $this->inspector()->inspect($bytes)->reason);
        self::assertSame(Entitlement::MALFORMED, $this->inspector()->inspect('not json at all')->reason);
    }

    public function testARecordSignedByAnotherIssuerIsRefused(): void
    {
        $foreign = new RecordFactory();

        self::assertSame(
            Entitlement::BAD_SIGNATURE,
            $this->inspector()->inspect($foreign->bytes())->reason,
        );
    }

    public function testAnEmptyKeyRingFailsClosedWithItsOwnReason(): void
    {
        $inspector = new RecordInspector(
            new CanonicalForm(),
            TrustedKeys::withoutKeys(),
            new DetachedSignature(),
            $this->hosts(),
        );

        self::assertSame(Entitlement::KEY_STORE_EMPTY, $inspector->inspect($this->factory->bytes())->reason);
    }

    public function testARecordForAnotherProductIsRefused(): void
    {
        self::assertSame(Entitlement::WRONG_PRODUCT, $this->inspect(['project_slug' => 'brickie'])->reason);
        self::assertSame(Entitlement::WRONG_PRODUCT, $this->inspect(['project' => 'Brickie'])->reason);
    }

    public function testAnUnknownSchemaIsRefusedRatherThanGuessed(): void
    {
        self::assertSame(Entitlement::SCHEMA_UNSUPPORTED, $this->inspect(['schema_version' => 3])->reason);
        self::assertSame(Entitlement::SCHEMA_UNSUPPORTED, $this->inspect(['schema_version' => '2'])->reason);
    }

    public function testANonValidStatusIsRefused(): void
    {
        self::assertSame(Entitlement::MALFORMED, $this->inspect(['validation_status' => 'revoked'])->reason);
    }

    // --- the signed host set ----------------------------------------------------------------

    public function testALegacyRecordWithoutTheHostFieldsRequiresARefresh(): void
    {
        // Inventing the missing fields locally would be exactly the forgery the signature prevents,
        // so the record is kept as rollback material and a refresh is required instead.
        $state = $this->inspect(['license_domains' => '__unset__', 'license_max_domains' => '__unset__']);

        self::assertFalse($state->granted);
        self::assertSame(Entitlement::REFRESH_REQUIRED, $state->reason);
        self::assertSame(7, $state->version, 'The version must survive so rollback protection still works.');
    }

    /**
     * @dataProvider malformedHostSets
     *
     * @param mixed $domains
     */
    public function testAHostSetThatIsNotAlreadyCanonicalIsRefused($domains): void
    {
        self::assertSame(Entitlement::MALFORMED, $this->inspect(['license_domains' => $domains])->reason);
    }

    /** @return iterable<string, array{mixed}> */
    public static function malformedHostSets(): iterable
    {
        yield 'empty'      => [[]];
        yield 'unsorted'   => [['staging.example.com', 'example.com']];
        yield 'duplicate'  => [['example.com', 'example.com']];
        yield 'wildcard'   => [['*.example.com', 'example.com']];
        yield 'uppercase'  => [['EXAMPLE.COM']];
        yield 'trailing .' => [['example.com.']];
        yield 'with port'  => [['example.com:443']];
        yield 'not a list' => [['a' => 'example.com']];
        yield 'not strings'=> [[123]];
        yield 'not array'  => ['example.com'];
    }

    /**
     * @dataProvider invalidAllowances
     */
    public function testTheAllowanceMustBeAPositiveInteger(mixed $value): void
    {
        self::assertSame(Entitlement::MALFORMED, $this->inspect(['license_max_domains' => $value])->reason);
    }

    /**
     * A whole-number float such as 3.0 is deliberately not a case here: it cannot survive this
     * fixture's signing round trip as a float — PHP's json_encode drops the zero fraction, so it
     * decodes back as int 3 and the case would not exercise what its name claims. Forcing the
     * fraction to survive would mean adding JSON_PRESERVE_ZERO_FRACTION to {@see CanonicalForm},
     * which is the pinned cross-repo canonicalisation the signer and this class must agree on
     * byte-for-byte — not something to change for one test case. 'string' already covers the wrong-
     * type class this case would have.
     *
     * @return iterable<string, array{mixed}>
     */
    public static function invalidAllowances(): iterable
    {
        yield 'zero'     => [0];
        yield 'negative' => [-1];
        yield 'string'   => ['3'];
    }

    public function testInstanceBoundAllowanceIsNotAWildcard(): void
    {
        // 9999 reports an allowance. It authorises nothing on its own: only exact members of the
        // signed set do.
        $state = $this->inspect(
            ['license_max_domains' => 9999, 'license_domains' => ['other.example.net'], 'license_domain' => 'other.example.net'],
            configured: ['example.com'],
        );

        self::assertSame(Entitlement::DOMAIN_MISMATCH, $state->reason);
    }

    public function testABoundCountAboveTheAllowanceIsStillValid(): void
    {
        // The issuer deliberately lets existing bindings survive a lowered allowance. Adding a
        // client-side count check would take working installations dark.
        $state = $this->inspect([
            'license_max_domains' => 1,
            'license_domains'     => ['a.example.com', 'example.com', 'staging.example.com'],
        ]);

        self::assertTrue($state->granted);
    }

    public function testTheOperationHostMustBelongToTheSignedSet(): void
    {
        $state = $this->inspect(
            ['license_domain' => 'other.example.com'],
            configured: ['example.com', 'other.example.com'],
        );

        self::assertSame(Entitlement::DOMAIN_MISMATCH, $state->reason);
    }

    public function testTheOperationHostMustMatchTheOneAskedAbout(): void
    {
        $state = $this->inspector()->inspect($this->factory->bytes(), 'staging.example.com');

        self::assertSame(Entitlement::DOMAIN_MISMATCH, $state->reason);
    }

    public function testAnExpectedHostThatMatchesIsAccepted(): void
    {
        self::assertTrue($this->inspector()->inspect($this->factory->bytes(), 'example.com')->granted);
    }

    /**
     * @dataProvider nonMatchingHosts
     *
     * @param list<string> $configured
     */
    public function testHostsOutsideTheSignedSetDoNotActivate(array $configured): void
    {
        self::assertSame(Entitlement::DOMAIN_MISMATCH, $this->inspect(configured: $configured)->reason);
    }

    /** @return iterable<string, array{list<string>}> */
    public static function nonMatchingHosts(): iterable
    {
        yield 'www counterpart'  => [['www.example.com']];
        yield 'child'            => [['deep.example.com']];
        yield 'sibling'          => [['other.example.com']];
        yield 'nested staging'   => [['deep.staging.example.com']];
        yield 'lookalike'        => [['malicious-example.com']];
        yield 'suffix trap'      => [['notexample.com']];
    }

    public function testAParentDomainDoesNotCoverASignedSubdomain(): void
    {
        $state = $this->inspect(
            ['license_domain' => 'shop.example.com', 'license_domains' => ['shop.example.com']],
            configured: ['example.com'],
        );

        self::assertSame(Entitlement::DOMAIN_MISMATCH, $state->reason);
    }

    public function testAnyOneConfiguredHostIsEnough(): void
    {
        // Several domains on one instance: matching one of them activates the package.
        $state = $this->inspect(configured: ['unrelated.example.net', 'staging.example.com']);

        self::assertTrue($state->granted);
        self::assertSame('staging.example.com', $state->matchedHost);
    }

    public function testCopyingTheRecordToAnotherInstallationFails(): void
    {
        $state = $this->inspect(configured: ['someone-elses-site.example.net']);

        self::assertFalse($state->granted);
        self::assertSame(Entitlement::DOMAIN_MISMATCH, $state->reason);
    }

    public function testAnInstallationWithNoConfiguredHostSaysSo(): void
    {
        self::assertSame(Entitlement::NO_CONFIGURED_DOMAIN, $this->inspect(configured: [])->reason);
    }

    public function testTheCurrentHostIsPreferredWithinTheIntersection(): void
    {
        $state = $this->inspect(
            ['license_domain' => 'staging.example.com'],
            configured: ['example.com', 'staging.example.com'],
            currentHost: 'staging.example.com',
        );

        self::assertSame('staging.example.com', $state->matchedHost);
    }

    public function testASpoofedHostCannotSelectTheIdentity(): void
    {
        // The request host is only ever consulted after the configured inventory has vouched for
        // it, so a header naming an unrelated host changes nothing.
        $state = $this->inspect(configured: ['example.com'], currentHost: 'attacker.example.net');

        self::assertTrue($state->granted);
        self::assertSame('example.com', $state->matchedHost);
    }

    // --- licence model (Lifetime Free) --------------------------------------------------------

    /**
     * This build is issued under the Lifetime Free model only. A record that is genuinely signed
     * but belongs to a different model — a paid tier, a trial, or a time-limited free package — is
     * refused exactly like a wrong host or a wrong product, with no local fallback computed for it.
     *
     * @dataProvider modelIncompatibleOverrides
     *
     * @param array<string, mixed> $overrides
     */
    public function testARecordFromAnotherLicenceModelIsRefused(array $overrides): void
    {
        $state = $this->inspect($overrides);

        self::assertFalse($state->granted);
        self::assertSame(Entitlement::MODEL_INCOMPATIBLE, $state->reason);
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function modelIncompatibleOverrides(): iterable
    {
        yield 'paid package, still perpetual' => [['license_package' => 'pro']];
        yield 'trial package'                 => [['license_package' => 'trial']];
        yield 'time-limited free package'     => [
            ['license_lifetime' => false, 'license_expires_at' => time() + 86400],
        ];
        yield 'paid package with an expiry'   => [
            ['license_package' => 'pro', 'license_lifetime' => false, 'license_expires_at' => time() + 86400],
        ];
    }

    public function testAnExpiredNonLifetimeRecordGetsNoLocalFreeFallback(): void
    {
        // There is nothing left of the old cross-model fallback: an expired non-lifetime record is
        // refused as model-incompatible, never quietly downgraded to this build's Free package.
        $state = $this->inspect([
            'license_package'    => 'pro',
            'license_lifetime'   => false,
            'license_expires_at' => time() - 10,
            'free_available'     => true,
        ]);

        self::assertFalse($state->granted);
        self::assertSame(Entitlement::MODEL_INCOMPATIBLE, $state->reason);
    }

    public function testAMissingExpiryOnlyMeansPerpetualWhenTheRecordSaysSo(): void
    {
        // Otherwise stripping the expiry would read as "never expires".
        self::assertSame(
            Entitlement::MALFORMED,
            $this->inspect(['license_expires_at' => null, 'license_lifetime' => false])->reason,
        );

        $state = $this->inspect(['license_expires_at' => null, 'license_lifetime' => true]);

        self::assertTrue($state->granted);
        self::assertNull($state->expiresAt);
    }

    public function testARecordThatHasNotStartedYetIsRefused(): void
    {
        self::assertSame(
            Entitlement::NOT_STARTED,
            $this->inspect(['license_starts_at' => time() + 3600])->reason,
        );
    }

    public function testFeaturesAreReadFromTheSignedList(): void
    {
        $state = $this->inspect(['license_features' => ['bulk_send', 42, 'api']]);

        self::assertSame(['bulk_send', 'api'], $state->features);
        self::assertTrue($state->hasFeature('api'));
        self::assertFalse($state->hasFeature('nope'));
    }

    public function testAWithheldEntitlementGrantsNoFeatures(): void
    {
        self::assertFalse(Entitlement::withheld(Entitlement::MODEL_INCOMPATIBLE)->hasFeature('api'));
    }

    // --- helpers -----------------------------------------------------------------------------

    /**
     * @param array<string, mixed> $overrides
     * @param list<string>         $configured
     */
    private function inspect(array $overrides = [], array $configured = ['example.com'], ?string $currentHost = null): Entitlement
    {
        return $this->inspector($configured, $currentHost)->inspect($this->factory->bytes($overrides));
    }

    /** @param list<string> $configured */
    private function inspector(array $configured = ['example.com'], ?string $currentHost = null): RecordInspector
    {
        return new RecordInspector(
            new CanonicalForm(),
            $this->factory->keys(),
            new DetachedSignature(),
            $this->hosts($configured, $currentHost),
        );
    }

    /** @param list<string> $configured */
    private function hosts(array $configured = ['example.com'], ?string $currentHost = null): HostInventory
    {
        $stack = new RequestStack();

        if (null !== $currentHost) {
            $stack->push(Request::create('https://'.$currentHost.'/'));
        }

        return new HostInventory($stack, null, $configured, '');
    }
}
