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

namespace Vtinnovations\SmtpBundle\Tests\Unit\Storage;

use PHPUnit\Framework\TestCase;
use Vtinnovations\SmtpBundle\Storage\RecordStore;

/**
 * The pair is the invariant: record bytes and the envelope describing them are installed together
 * or not at all. A store that can leave the two disagreeing either refuses a genuine record or,
 * worse, accepts a substituted one.
 */
final class RecordStoreTest extends TestCase
{
    private string $projectDir;
    private RecordStore $store;

    protected function setUp(): void
    {
        $this->projectDir = sys_get_temp_dir().'/vt-smtp-store-'.bin2hex(random_bytes(6));
        mkdir($this->projectDir, 0775, true);

        $this->store = new RecordStore($this->projectDir);
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->projectDir);
    }

    public function testNothingIsStoredInitially(): void
    {
        self::assertNull($this->store->read());
    }

    public function testACommittedPairIsReadBackVerbatim(): void
    {
        $bytes    = '{"license_version":7,"signature":"x"}';
        $envelope = ['license_version' => 7, 'license_md5' => md5($bytes)];

        self::assertTrue($this->store->commit($bytes, $envelope, static fn (): bool => true));

        $stored = $this->store->read();

        self::assertNotNull($stored);
        self::assertSame($bytes, $stored['bytes'], 'The bytes must not be re-encoded.');
        self::assertSame($envelope, $stored['envelope']);
    }

    public function testAFailedPostCheckRollsBackToThePreviousPair(): void
    {
        $this->store->commit('first', ['v' => 1], static fn (): bool => true);

        self::assertFalse($this->store->commit('second', ['v' => 2], static fn (): bool => false));

        $stored = $this->store->read();

        self::assertNotNull($stored);
        self::assertSame('first', $stored['bytes'], 'A working record must survive a failed swap.');
        self::assertSame(['v' => 1], $stored['envelope']);
    }

    public function testAFailedFirstCommitLeavesNothingBehind(): void
    {
        self::assertFalse($this->store->commit('first', ['v' => 1], static fn (): bool => false));

        self::assertNull($this->store->read());
    }

    public function testThePostCheckSeesWhatActuallyLandedOnDisk(): void
    {
        $seen = null;

        $this->store->commit('bytes-on-disk', ['v' => 9], static function (string $bytes, array $envelope) use (&$seen): bool {
            $seen = [$bytes, $envelope];

            return true;
        });

        self::assertSame(['bytes-on-disk', ['v' => 9]], $seen);
    }

    public function testNoTemporaryOrBackupFilesSurviveASuccessfulSwap(): void
    {
        $this->store->commit('first', ['v' => 1], static fn (): bool => true);
        $this->store->commit('second', ['v' => 2], static fn (): bool => true);

        foreach (glob($this->projectDir.'/var/vtinnovations-smtp/state/*') ?: [] as $path) {
            self::assertDoesNotMatchRegularExpression('/\.(new|bak)$/', $path, 'Left over: '.$path);
        }
    }

    public function testAHalfPresentPairIsTreatedAsNoState(): void
    {
        $this->store->commit('first', ['v' => 1], static fn (): bool => true);

        unlink($this->projectDir.'/var/vtinnovations-smtp/state/record.seal.json');

        // A record with no envelope is unverifiable, so it is not state at all.
        self::assertNull($this->store->read());
    }

    public function testACorruptEnvelopeFileIsTreatedAsNoState(): void
    {
        $this->store->commit('first', ['v' => 1], static fn (): bool => true);

        file_put_contents($this->projectDir.'/var/vtinnovations-smtp/state/record.seal.json', 'not json');

        self::assertNull($this->store->read());
    }

    public function testDiscardRemovesBothHalves(): void
    {
        $this->store->commit('first', ['v' => 1], static fn (): bool => true);
        $this->store->discard();

        self::assertNull($this->store->read());
    }

    public function testStateLivesOutsideThePublicDirectory(): void
    {
        $this->store->commit('first', ['v' => 1], static fn (): bool => true);

        self::assertFileExists($this->projectDir.'/var/vtinnovations-smtp/state/record.json');
        self::assertDirectoryDoesNotExist($this->projectDir.'/public');
    }

    private function removeTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($items as $item) {
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }

        @rmdir($dir);
    }
}
