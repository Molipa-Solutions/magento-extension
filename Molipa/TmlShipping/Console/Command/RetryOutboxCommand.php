<?php
declare(strict_types=1);

namespace Molipa\TmlShipping\Console\Command;

use Molipa\TmlShipping\Cron\RetryOutbox;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class RetryOutboxCommand extends Command
{
    private const OPTION_LIMIT = 'limit';

    public function __construct(
        private readonly RetryOutbox $retryOutbox
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setName('tmlshipping:retry-outbox')
            ->setDescription('Retry pending/failed TML Shipping webhook events')
            ->addOption(
                self::OPTION_LIMIT,
                null,
                InputOption::VALUE_OPTIONAL,
                'Max events to process',
                50
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $limit = max(1, min(500, (int)$input->getOption(self::OPTION_LIMIT)));

        $output->writeln('<info>[TML] Retrying outbox events…</info>');

        try {
            $report = null;
            $processed = $this->retryOutbox->run($limit, $report);

            $output->writeln(sprintf(
                '<info>[TML] Done. Sent=%d Failed=%d Candidates=%d</info>',
                (int)($report['sent'] ?? $processed),
                (int)($report['failed'] ?? 0),
                (int)($report['candidates'] ?? 0),
            ));

            if ((int)($report['candidates'] ?? 0) === 0) {
                $output->writeln('<comment>[TML] No events due for retry.</comment>');
            }

            return Command::SUCCESS;
        } catch (\Throwable $e) {
            $output->writeln('<error>[TML] Command failed: ' . $e->getMessage() . '</error>');
            return Command::FAILURE;
        }
    }
}
