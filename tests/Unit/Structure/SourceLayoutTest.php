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

namespace Vtinnovations\SmtpBundle\Tests\Unit\Structure;

use PHPUnit\Framework\TestCase;
use Vtinnovations\SmtpBundle\Config\DeploymentProfile;

/**
 * The concealment audit, kept as a test so it cannot quietly stop being true.
 *
 * None of this makes the code impossible to read — distributed source never is, and claiming
 * otherwise would be dishonest. What it does remove is the shortcut: no single directory listing
 * explains the flow, no single grep finds the endpoint or the key, and no single registration can
 * be deleted to make every protected operation succeed. The actual security is the signature; this
 * is what stops the signature being trivially routed around.
 */
final class SourceLayoutTest extends TestCase
{
    private const SRC = __DIR__.'/../../../src';

    /**
     * @dataProvider revealingDirectoryNames
     */
    public function testNoDirectoryAdvertisesTheSubsystem(string $name): void
    {
        self::assertDirectoryDoesNotExist(self::SRC.'/'.$name);
    }

    /** @return iterable<string, array{string}> */
    public static function revealingDirectoryNames(): iterable
    {
        foreach (['Licensing', 'License', 'Licence', 'Protection', 'Integrity', 'AntiTamper', 'DRM', 'VtOne', 'VTone', 'Security/License'] as $name) {
            yield $name => [$name];
        }
    }

    /**
     * @dataProvider revealingClassNames
     */
    public function testNoClassAdvertisesTheSubsystem(string $pattern): void
    {
        $offenders = [];

        foreach ($this->sourceFiles() as $file) {
            if (preg_match('/'.$pattern.'/', basename($file, '.php'))) {
                $offenders[] = basename($file);
            }
        }

        self::assertSame([], $offenders, 'Revealing class name(s): '.implode(', ', $offenders));
    }

    /** @return iterable<string, array{string}> */
    public static function revealingClassNames(): iterable
    {
        yield 'manager/validator/service/repository' => ['^(License|Licence)(Manager|Validator|Service|Repository|StateStore|IntegrityService|Guard|UpdaterController)$'];
        yield 'tamper vocabulary'                    => ['^(TamperDetector|AntiTamper|ExpectedMd5|ChecksumGuard|VtoneLogger|VtOneClient|PublicKeyRing)$'];
    }

    public function testNoSingleDirectoryHoldsTheWholeFlow(): void
    {
        // Each responsibility, and the directory it lives in. The point of the assertion is the
        // shape of the result: nothing is concentrated, and no two of the sensitive halves share a
        // home.
        $responsibilities = [
            'fixed endpoints'      => 'Config',
            'domain policy'        => 'Config',
            'mutable bookkeeping'  => 'Config',
            'canonical form'       => 'Support',
            'pinned keys'          => 'Support',
            'signature check'      => 'Support',
            'envelope + checksum'  => 'Support',
            'atomic persistence'   => 'Storage',
            'replay journal'       => 'Storage',
            'exchange transport'   => 'Http',
            'inbound auth'         => 'Http',
            'signal transport'     => 'Http',
            'entitlement result'   => 'Service',
            'record inspection'    => 'Service',
            'public route'         => 'Controller',
            'request lifecycle'    => 'EventListener',
            'settings screen'      => 'EventListener',
        ];

        $directories = array_unique(array_values($responsibilities));

        self::assertGreaterThanOrEqual(4, \count($directories), 'The flow must be spread across existing seams.');

        foreach ($directories as $directory) {
            self::assertDirectoryExists(self::SRC.'/'.$directory);
        }
    }

    public function testNoSingleFileHoldsEndpointsKeysChecksumsAndPersistence(): void
    {
        $markers = [
            'endpoint'    => '/v-t|log-envoke|api\/v1\/verify/',
            'key ring'    => '/SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES/',
            'checksum'    => '/\bmd5\(/',
            'signature'   => '/sodium_crypto_sign_verify_detached/',
            'persistence' => '/@rename\(|fwrite\(/',
        ];

        foreach ($this->sourceFiles() as $file) {
            $contents = (string) file_get_contents($file);
            $hits     = [];

            foreach ($markers as $label => $pattern) {
                if (preg_match($pattern, $contents)) {
                    $hits[] = $label;
                }
            }

            self::assertLessThanOrEqual(
                2,
                \count($hits),
                sprintf('%s concentrates: %s', basename($file), implode(' + ', $hits)),
            );
        }
    }

    public function testTheEndpointsAreNotWrittenAsOneLiteral(): void
    {
        // Fragmented in source, and re-split again at build time. Not a secret — anyone can watch
        // the traffic — but it removes the single grep that finds every place worth patching.
        $whole = 'www.'.'v-t'.'.one';

        foreach ($this->sourceFiles() as $file) {
            self::assertStringNotContainsString(
                "'".$whole."'",
                (string) file_get_contents($file),
                basename($file).' contains the whole host as one literal',
            );
        }

        // And the assembled value is still exactly right.
        self::assertSame('https://www.v-t.one/api/v1/verify', DeploymentProfile::exchangeEndpoint());
        self::assertSame('https://www.v-t.one/rest/api/v1/log-envoke', DeploymentProfile::signalEndpoint());
    }

    public function testThePublicKeyIsNotWrittenAsOneLiteral(): void
    {
        $whole = 'qllgm+66FUVBFJ3O'.'68ICFG8b37dR+9jM'.'fr1+4/pSygE=';

        foreach ($this->sourceFiles() as $file) {
            self::assertStringNotContainsString(
                "'".$whole."'",
                (string) file_get_contents($file),
                basename($file).' contains the whole key as one literal',
            );
        }
    }

    public function testTheUpdaterHandlerIsThin(): void
    {
        $controller = (string) file_get_contents(self::SRC.'/Controller/RemoteStateController.php');

        // It may enforce shape. It may not verify, decide, or persist.
        foreach ([
            'sodium_crypto_sign_verify_detached',
            'md5(',
            'file_put_contents',
            'fopen',
            'base64_decode',
        ] as $forbidden) {
            self::assertStringNotContainsString($forbidden, $controller, 'The public handler must delegate: '.$forbidden);
        }

        self::assertLessThan(120, substr_count($controller, "\n"), 'The public handler should stay small.');
    }

    public function testTheUpdaterHandlerCannotNameAFile(): void
    {
        $controller = (string) file_get_contents(self::SRC.'/Controller/RemoteStateController.php');

        foreach (['dirname(', 'realpath(', '__DIR__', 'projectDir', 'unlink('] as $forbidden) {
            self::assertStringNotContainsString($forbidden, $controller);
        }
    }

    public function testNoObsoleteReferencesToTheOldSubsystemRemain(): void
    {
        foreach ([...$this->sourceFiles(), ...$this->configFiles()] as $file) {
            $contents = (string) file_get_contents($file);

            self::assertStringNotContainsString('SmtpBundle\\License\\', $contents, basename($file));
            self::assertStringNotContainsString('LicenseUpdaterController', $contents, basename($file));
        }
    }

    public function testTheRequiredPublicRouteIsUnchanged(): void
    {
        // The path is a contract with the issuer and is deliberately not obscured.
        self::assertSame('/rest/api/v1/smtp-license-updater', DeploymentProfile::updaterPath());
    }

    /** @return list<string> */
    private function sourceFiles(): array
    {
        $files    = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(self::SRC, \FilesystemIterator::SKIP_DOTS));

        foreach ($iterator as $file) {
            if ($file->isFile() && 'php' === $file->getExtension()) {
                $files[] = $file->getPathname();
            }
        }

        sort($files);

        return $files;
    }

    /** @return list<string> */
    private function configFiles(): array
    {
        return array_values(array_filter([
            ...glob(\dirname(self::SRC).'/config/*.yaml') ?: [],
            ...glob(\dirname(self::SRC).'/contao/config/*.php') ?: [],
            ...glob(\dirname(self::SRC).'/contao/dca/*.php') ?: [],
        ]));
    }
}
