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

namespace Vtinnovations\SmtpBundle\Tools;

use Vtinnovations\SmtpBundle\Support\TrustedKeys;

/**
 * Builds the distributable artefact from the readable development tree.
 *
 * What it actually does, and what it deliberately does not, matters more than the label "release
 * build", so both are stated plainly.
 *
 * It does:
 *
 *   - refuse to build at all unless a real verification key is pinned. A package that could never
 *     verify a genuine response is worse than no package.
 *   - leave development-only content out: tests, fixtures, CI, tooling, editor and VCS files.
 *   - strip comments and docblocks from shipped PHP. Attributes survive, because routing, DI and
 *     Contao callbacks are declared with them.
 *   - re-split the fragmented endpoint and key literals at fresh boundaries, so the byte sequences
 *     someone would grep for differ between builds while the reassembled values do not.
 *   - refuse to ship a file containing a complete endpoint or public key as one contiguous literal,
 *     which is what keeps the fragmenting honest as the code changes.
 *   - emit a SHA-256 manifest of everything shipped.
 *
 * It does not rename private symbols. Class and namespace names are bound by PSR-4 autoloading, DI
 * service ids, route attributes and Contao's DCA callback attributes, so renaming them requires a
 * coordinated rewrite of all four; and a renamer that cannot be exercised against a booted
 * container is more likely to produce a broken package than a hardened one. Structural distribution
 * and cryptographic verification carry that weight instead. This is a real limitation and is
 * documented rather than papered over.
 *
 * The manifest is not signed. Nothing in this product verifies its own code at runtime, so a
 * signature over the manifest would be decoration; the manifest exists so a deployment can be
 * compared against the published artefact out of band.
 */
final class ReleasePackager
{
    /** @var list<string> */
    private const EXCLUDED_PREFIXES = [
        'tests/',
        'tools/',
        'vendor/',
        '.github/',
        '.git/',
        'var/',
        'build/',
    ];

    /** @var list<string> */
    private const EXCLUDED_FILES = [
        'phpunit.xml.dist',
        '.gitignore',
        '.php-cs-fixer.dist.php',
        '.php-cs-fixer.cache',
        'composer.lock',
    ];

    /**
     * Constants whose fragments are re-split on every build. The reassembled value is unchanged;
     * only where the seams fall differs.
     *
     * @var list<string>
     */
    private const FRAGMENTED_CONSTANTS = ['REMOTE_HOST_PARTS', 'CURRENT_PARTS'];

    public function __construct(
        private readonly string $sourceDir,
        private readonly string $targetDir,
    ) {
    }

    /**
     * @return array{files: int, manifest: string}
     */
    public function build(): array
    {
        // Readiness first, so a build that could never work fails before it produces anything.
        (new TrustedKeys())->assertProductionReady();

        if (is_dir($this->targetDir)) {
            $this->removeTree($this->targetDir);
        }

        $shipped = [];

        foreach ($this->collect() as $relative) {
            $contents = (string) file_get_contents($this->sourceDir.'/'.$relative);

            if (str_ends_with($relative, '.php')) {
                $contents = self::stripComments($contents);
                $contents = self::resplitFragments($contents, self::FRAGMENTED_CONSTANTS);
            }

            $this->assertNoWholeSecretLiteral($relative, $contents);

            $destination = $this->targetDir.'/'.$relative;
            $directory   = \dirname($destination);

            if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
                throw new \RuntimeException(sprintf('Could not create "%s".', $directory));
            }

            file_put_contents($destination, $contents);
            $shipped[$relative] = hash('sha256', $contents);
        }

        ksort($shipped);

        $manifest = '';

        foreach ($shipped as $path => $digest) {
            $manifest .= $digest.'  '.$path."\n";
        }

        file_put_contents($this->targetDir.'/MANIFEST.sha256', $manifest);

        return ['files' => \count($shipped), 'manifest' => $manifest];
    }

    /**
     * Removes comments and docblocks while leaving everything the runtime reads intact.
     *
     * Attributes are tokens, not comments, so they survive — which is what keeps routes, DI and DCA
     * callbacks working. A single space replaces each comment so tokens that were only separated by
     * one do not fuse.
     */
    public static function stripComments(string $php): string
    {
        $out = '';

        foreach (token_get_all($php) as $token) {
            if (\is_array($token)) {
                if (\T_COMMENT === $token[0] || \T_DOC_COMMENT === $token[0]) {
                    // Keep the line structure so reported line numbers stay close to the original.
                    $out .= str_repeat("\n", substr_count($token[1], "\n"));

                    continue;
                }

                $out .= $token[1];

                continue;
            }

            $out .= $token;
        }

        return $out;
    }

    /**
     * Re-splits a fragmented string constant at fresh boundaries.
     *
     * The concatenation is identical, so behaviour cannot change; only the substrings someone would
     * search for do. Returns the input untouched when the constant is not present or is not a plain
     * list of single-quoted strings, so an unrelated edit cannot silently corrupt a value.
     *
     * @param list<string> $constantNames
     */
    public static function resplitFragments(string $php, array $constantNames, ?\Closure $chunker = null): string
    {
        $chunker ??= static fn (string $value): array => self::randomChunks($value);

        foreach ($constantNames as $name) {
            $pattern = '/(const\s+'.preg_quote($name, '/').'\s*=\s*\[)([^\]]*)(\])/';

            $php = preg_replace_callback(
                $pattern,
                static function (array $m) use ($chunker): string {
                    if (!preg_match_all("/'((?:[^'\\\\]|\\\\.)*)'/", $m[2], $found)) {
                        return $m[0];
                    }

                    $joined = implode('', array_map(
                        static fn (string $piece): string => stripcslashes($piece),
                        $found[1],
                    ));

                    if ('' === $joined) {
                        return $m[0];
                    }

                    $parts = array_map(
                        static fn (string $chunk): string => "'".addcslashes($chunk, "'\\")."'",
                        $chunker($joined),
                    );

                    return $m[1].implode(', ', $parts).$m[3];
                },
                $php,
            );
        }

        return $php;
    }

    /**
     * @return list<string>
     */
    public static function randomChunks(string $value, int $minChunks = 3, int $maxChunks = 6): array
    {
        $length = \strlen($value);
        $chunks = min(random_int(max($minChunks, 2), max($maxChunks, 2)), $length);

        if ($chunks < 2) {
            return [$value];
        }

        $cuts = [];

        while (\count($cuts) < $chunks - 1) {
            $cuts[random_int(1, $length - 1)] = true;
        }

        $cuts = array_keys($cuts);
        sort($cuts);

        $parts    = [];
        $previous = 0;

        foreach ($cuts as $cut) {
            $parts[]  = substr($value, $previous, $cut - $previous);
            $previous = $cut;
        }

        $parts[] = substr($value, $previous);

        return array_values(array_filter($parts, static fn (string $p): bool => '' !== $p));
    }

    /**
     * The check that keeps the fragmenting from quietly decaying: if a later edit reintroduces the
     * whole host or the whole key as one literal, the build stops.
     */
    private function assertNoWholeSecretLiteral(string $relative, string $contents): void
    {
        if (!str_ends_with($relative, '.php')) {
            return;
        }

        $whole = [
            "'".implode('', ['www', '.', 'v-t', '.one'])."'",
            "'".implode('', ['qllgm+66FUVBFJ3O', '68ICFG8b37dR+9jM', 'fr1+4/pSygE='])."'",
        ];

        foreach ($whole as $literal) {
            if (str_contains($contents, $literal)) {
                throw new \RuntimeException(sprintf(
                    'File "%s" contains a complete endpoint or key literal; keep it fragmented.',
                    $relative,
                ));
            }
        }
    }

    /**
     * @return list<string>
     */
    public function collect(): array
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->sourceDir, \FilesystemIterator::SKIP_DOTS),
        );

        $files = [];

        /** @var \SplFileInfo $file */
        foreach ($iterator as $file) {
            if (!$file->isFile()) {
                continue;
            }

            $relative = str_replace('\\', '/', substr($file->getPathname(), \strlen($this->sourceDir) + 1));

            if (self::isExcluded($relative)) {
                continue;
            }

            $files[] = $relative;
        }

        sort($files);

        return $files;
    }

    public static function isExcluded(string $relative): bool
    {
        foreach (self::EXCLUDED_PREFIXES as $prefix) {
            if (str_starts_with($relative, $prefix)) {
                return true;
            }
        }

        return \in_array($relative, self::EXCLUDED_FILES, true);
    }

    private function removeTree(string $dir): void
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($iterator as $item) {
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }

        @rmdir($dir);
    }
}
