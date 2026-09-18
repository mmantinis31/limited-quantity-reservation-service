<?php

declare(strict_types=1);

namespace App\Command;

use App\Application\ExpireReservations;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:reservation:expire',
    description: 'Expire stale reservations and release their reserved stock.',
    usages: ['--batch-size=100'],
)]
final class ExpireReservationsCommand extends Command
{
    public function __construct(private readonly ExpireReservations $expireReservations)
    {
        parent::__construct();
    }

    #[\Override]
    protected function configure(): void
    {
        $this->addOption(
            'batch-size',
            'b',
            InputOption::VALUE_REQUIRED,
            'Maximum number of reservations processed per transaction.',
            (string) ExpireReservations::DEFAULT_BATCH_SIZE,
        );
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        try {
            $batchSize = CommandInput::integer($input->getOption('batch-size'), 'Batch size');
            $result = $this->expireReservations->execute($batchSize);
        } catch (\InvalidArgumentException $exception) {
            $io->error($exception->getMessage());

            return self::INVALID;
        }

        $io->success('Expired reservation processing completed.');
        $io->table(
            ['Metric', 'Value'],
            [
                ['Expired reservations', (string) $result->expiredReservations],
                ['Released units', (string) $result->releasedUnits],
                ['Processed batches', (string) $result->processedBatches],
            ],
        );

        return self::SUCCESS;
    }
}
