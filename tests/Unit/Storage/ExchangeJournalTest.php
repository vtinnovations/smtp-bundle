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
use Vtinnovations\SmtpBundle\Storage\ExchangeJournal;

final class ExchangeJournalTest extends TestCase
{
    private string $projectDir;
    private ExchangeJournal $journal;

    protected function setUp(): void
    {
        $this->projectDir = sys_get_temp_dir().'/vt-smtp-journal-'.bin2hex(random_bytes(6));
        mkdir($this->projectDir, 0775, true);

        $this->journal = new ExchangeJournal($this->projectDir);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->projectDir.'/var/vtinnovations-smtp/state/*') ?: [] as $file) {
            @unlink($file);
        }

        @rmdir($this->projectDir.'/var/vtinnovations-smtp/state');
        @rmdir($this->projectDir.'/var/vtinnovations-smtp');
        @rmdir($this->projectDir.'/var');
        @rmdir($this->projectDir);
    }

    public function testAnUnseenRequestIsUnknown(): void
    {
        self::assertNull($this->journal->find('req-1'));
        self::assertFalse($this->journal->nonceSeen('nonce-1'));
    }

    public function testARecordedRequestIsFoundWithItsFingerprintAndVersion(): void
    {
        $this->journal->record('req-1', 'nonce-1', 'fingerprint-a', 9, 'updated');

        $entry = $this->journal->find('req-1');

        self::assertNotNull($entry);
        self::assertSame('fingerprint-a', $entry['fingerprint']);
        self::assertSame(9, $entry['version']);
        self::assertSame('updated', $entry['result']);
    }

    public function testNoncesAreRememberedAcrossRequestIds(): void
    {
        $this->journal->record('req-1', 'nonce-1', 'fingerprint-a', 9, 'updated');

        self::assertTrue($this->journal->nonceSeen('nonce-1'));
        self::assertFalse($this->journal->nonceSeen('nonce-2'));
    }

    public function testNoncesAreStoredOnlyAsDigests(): void
    {
        // A file full of authentication material is worth stealing. Digests are not.
        $this->journal->record('req-1', 'the-secret-nonce', 'fingerprint-a', 9, 'updated');

        $contents = (string) file_get_contents($this->projectDir.'/var/vtinnovations-smtp/state/exchanges.json');

        self::assertStringNotContainsString('the-secret-nonce', $contents);
        self::assertStringContainsString(ExchangeJournal::digest('the-secret-nonce'), $contents);
    }

    public function testStateSurvivesANewInstance(): void
    {
        $this->journal->record('req-1', 'nonce-1', 'fingerprint-a', 9, 'updated');

        $reopened = new ExchangeJournal($this->projectDir);

        self::assertNotNull($reopened->find('req-1'));
        self::assertTrue($reopened->nonceSeen('nonce-1'));
    }

    public function testTheJournalIsBounded(): void
    {
        for ($i = 0; $i < 520; ++$i) {
            $this->journal->record('req-'.$i, 'nonce-'.$i, 'fp-'.$i, $i, 'updated');
        }

        $data = json_decode((string) file_get_contents($this->projectDir.'/var/vtinnovations-smtp/state/exchanges.json'), true);

        self::assertLessThanOrEqual(500, \count($data['requests']));
        // The newest survive, because those are the ones a retry could still reference.
        self::assertNotNull($this->journal->find('req-519'));
    }

    public function testACorruptFileIsTreatedAsEmptyRatherThanFatal(): void
    {
        $this->journal->record('req-1', 'nonce-1', 'fingerprint-a', 9, 'updated');

        file_put_contents($this->projectDir.'/var/vtinnovations-smtp/state/exchanges.json', 'not json');

        $reopened = new ExchangeJournal($this->projectDir);

        self::assertNull($reopened->find('req-1'));
    }
}
