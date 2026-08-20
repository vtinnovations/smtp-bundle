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

namespace Vtinnovations\SmtpBundle\Tests\Unit\Tools;

use PHPUnit\Framework\TestCase;
use Vtinnovations\SmtpBundle\Tools\ReleasePackager;

/**
 * The release step is only worth having if it cannot silently break the package it produces, so the
 * transformations are tested for the property that matters: the reassembled values are unchanged
 * and the runtime metadata survives.
 */
final class ReleasePackagerTest extends TestCase
{
    public function testCommentsAreStrippedAndAttributesSurvive(): void
    {
        $source = <<<'PHP'
            <?php
            /** A docblock. */
            #[Route('/rest/api/v1/smtp-license-updater', name: 'x')]
            class A { // trailing comment
                # hash comment
                public function b(): string { return 'kept'; }
            }
            PHP;

        $stripped = ReleasePackager::stripComments($source);

        self::assertStringNotContainsString('A docblock', $stripped);
        self::assertStringNotContainsString('trailing comment', $stripped);
        self::assertStringNotContainsString('hash comment', $stripped);

        // Attributes are tokens, not comments — routing, DI and DCA callbacks depend on them.
        self::assertStringContainsString("#[Route('/rest/api/v1/smtp-license-updater', name: 'x')]", $stripped);
        self::assertStringContainsString("return 'kept';", $stripped);
    }

    public function testStrippingPreservesLineNumbers(): void
    {
        $source = "<?php\n/*\n * two\n * lines\n */\n\$x = 1;\n";

        self::assertSame(
            substr_count($source, "\n"),
            substr_count(ReleasePackager::stripComments($source), "\n"),
            'Stack traces should still point at the right lines.',
        );
    }

    public function testFragmentsAreRecutWithoutChangingTheValue(): void
    {
        $source = "<?php\nprivate const REMOTE_HOST_PARTS = ['www', '.', 'v-t', '.one'];\n";

        // A fixed chunker, so "the seams moved" is an assertion rather than a coin flip.
        $rebuilt = ReleasePackager::resplitFragments(
            $source,
            ['REMOTE_HOST_PARTS'],
            static fn (string $v): array => [substr($v, 0, 5), substr($v, 5)],
        );

        self::assertNotSame($source, $rebuilt, 'The seams should have moved.');
        self::assertSame('www.v-t.one', $this->joinFragments($rebuilt, 'REMOTE_HOST_PARTS'));
    }

    public function testTheKeyFragmentsSurviveARecut(): void
    {
        $key    = 'qllgm+66FUVBFJ3O'.'68ICFG8b37dR+9jM'.'fr1+4/pSygE=';
        $source = "<?php\nprivate const CURRENT_PARTS = ['qllgm+66FUVBFJ3O', '68ICFG8b37dR+9jM', 'fr1+4/pSygE='];\n";

        $rebuilt = ReleasePackager::resplitFragments($source, ['CURRENT_PARTS']);
        $joined  = $this->joinFragments($rebuilt, 'CURRENT_PARTS');

        self::assertSame($key, $joined);
        self::assertSame(32, \strlen((string) base64_decode($joined, true)), 'Still a valid 32-byte key.');
    }

    public function testAnUnrelatedConstantIsLeftAlone(): void
    {
        $source = "<?php\nprivate const OTHER = ['a', 'b'];\n";

        self::assertSame($source, ReleasePackager::resplitFragments($source, ['CURRENT_PARTS']));
    }

    public function testANonListConstantIsLeftAloneRatherThanCorrupted(): void
    {
        $source = "<?php\nprivate const CURRENT_PARTS = [SOME_CONST, OTHER_CONST];\n";

        self::assertSame($source, ReleasePackager::resplitFragments($source, ['CURRENT_PARTS']));
    }

    public function testEscapedCharactersInFragmentsRoundTrip(): void
    {
        $source = "<?php\nprivate const CURRENT_PARTS = ['a\\'b', 'c\\\\d'];\n";

        $rebuilt = ReleasePackager::resplitFragments($source, ['CURRENT_PARTS']);

        self::assertSame("a'bc\\d", $this->joinFragments($rebuilt, 'CURRENT_PARTS'));
    }

    public function testChunkingAlwaysReassemblesToTheOriginal(): void
    {
        foreach (['a', 'ab', 'short', str_repeat('x', 44), 'www.v-t.one'] as $value) {
            self::assertSame($value, implode('', ReleasePackager::randomChunks($value)), $value);
        }
    }

    /**
     * @dataProvider excludedPaths
     */
    public function testDevelopmentOnlyContentIsNotShipped(string $path): void
    {
        self::assertTrue(ReleasePackager::isExcluded($path), $path.' should be excluded');
    }

    /** @return iterable<string, array{string}> */
    public static function excludedPaths(): iterable
    {
        yield 'tests'      => ['tests/Unit/Support/TrustedKeysTest.php'];
        yield 'fixtures'   => ['tests/Fixture/RecordFactory.php'];
        yield 'tools'      => ['tools/release-build.php'];
        yield 'ci'         => ['.github/workflows/ci.yml'];
        yield 'vendor'     => ['vendor/autoload.php'];
        yield 'state'      => ['var/vtinnovations-smtp/state/record.json'];
        yield 'phpunit'    => ['phpunit.xml.dist'];
        yield 'gitignore'  => ['.gitignore'];
        yield 'lock'       => ['composer.lock'];
    }

    /**
     * @dataProvider shippedPaths
     */
    public function testProductContentIsShipped(string $path): void
    {
        self::assertFalse(ReleasePackager::isExcluded($path), $path.' should be shipped');
    }

    /** @return iterable<string, array{string}> */
    public static function shippedPaths(): iterable
    {
        yield 'source'       => ['src/Support/TrustedKeys.php'];
        yield 'services'     => ['config/services.yaml'];
        yield 'routes'       => ['config/routes.yaml'];
        yield 'dca'          => ['contao/dca/tl_settings.php'];
        yield 'languages'    => ['contao/languages/en/tl_settings.php'];
        yield 'translations' => ['translations/vtinnovations_smtp.en.php'];
        yield 'composer'     => ['composer.json'];
    }

    private function joinFragments(string $php, string $constant): string
    {
        preg_match('/const\s+'.preg_quote($constant, '/').'\s*=\s*\[([^\]]*)\]/', $php, $m);
        preg_match_all("/'((?:[^'\\\\]|\\\\.)*)'/", $m[1] ?? '', $found);

        return implode('', array_map(static fn (string $p): string => stripcslashes($p), $found[1]));
    }
}
