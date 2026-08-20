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

namespace Vtinnovations\SmtpBundle\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Vtinnovations\SmtpBundle\Dotenv\DotenvWriter;
use Vtinnovations\SmtpBundle\Exception\CacheClearException;
use Vtinnovations\SmtpBundle\Cache\CacheClearService;
use Vtinnovations\SmtpBundle\Service\EntitlementReader;

#[AsCommand(
    name: 'vtinnovations:smtp:disable',
    description: 'Remove MAILER_DSN from .env.local so Contao falls back to default mailer.',
)]
final class DisableSmtpCommand extends Command
{
    public function __construct(
        private readonly DotenvWriter $dotenvWriter,
        private readonly CacheClearService $cacheClearService,
        private readonly EntitlementReader $entitlement,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption(
            'clear-cache',
            null,
            InputOption::VALUE_NONE,
            'Also clear and warmup the Symfony cache after disabling.',
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        // The console is a server-side entry point like any other. It resolves entitlement from the
        // persisted verified host rather than skipping the check because there is no request.
        if (!$this->entitlement->isGranted()) {
            $io->error('This command is not available for this installation.');

            return Command::FAILURE;
        }

        $existing = $this->dotenvWriter->read('MAILER_DSN');

        if ($existing === null) {
            $io->note('MAILER_DSN not set in .env.local — nothing to remove.');

            return Command::SUCCESS;
        }

        $this->dotenvWriter->remove('MAILER_DSN');
        $io->success('MAILER_DSN removed from .env.local. Contao will use default mailer.');

        if ($input->getOption('clear-cache')) {
            $io->text('Clearing cache...');

            try {
                $this->cacheClearService->clearAndWarmup();
                $io->success('Cache cleared and warmed up.');
            } catch (CacheClearException $e) {
                $io->error('Cache clear failed: ' . $e->getMessage());
                $io->text('Run manually: bin/console cache:clear');

                return Command::FAILURE;
            }
        } else {
            $io->note('Run "bin/console cache:clear" to apply the change.');
        }

        return Command::SUCCESS;
    }
}
