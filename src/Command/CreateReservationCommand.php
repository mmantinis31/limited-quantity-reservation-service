<?php

declare(strict_types=1);

namespace App\Command;

use App\Application\CreateReservation;
use App\Domain\Exception\InsufficientStock;
use App\Domain\Exception\InvalidReservationQuantity;
use App\Domain\Exception\InvalidReservationUserId;
use App\Domain\Reservation;
use App\Repository\Exception\ProductNotFound;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:reservation:create',
    description: 'Create a pending product reservation.',
    usages: ['1 user-123 2'],
)]
final class CreateReservationCommand extends Command
{
    public function __construct(private readonly CreateReservation $createReservation)
    {
        parent::__construct();
    }

    #[\Override]
    protected function configure(): void
    {
        $this
            ->addArgument('product-id', InputArgument::REQUIRED, 'ID of the product to reserve.')
            ->addArgument('user-id', InputArgument::REQUIRED, 'External user identifier.')
            ->addArgument('quantity', InputArgument::REQUIRED, sprintf(
                'Quantity to reserve (%d-%d).',
                Reservation::MIN_QUANTITY,
                Reservation::MAX_QUANTITY,
            ));
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        try {
            $productId = CommandInput::integer($input->getArgument('product-id'), 'Product ID');

            if ($productId < 1) {
                throw new \InvalidArgumentException('Product ID must be a positive integer.');
            }

            $userId = CommandInput::string($input->getArgument('user-id'), 'User ID');
            $quantity = CommandInput::integer($input->getArgument('quantity'), 'Quantity');
            $reservation = $this->createReservation->execute($productId, $userId, $quantity);
        } catch (\InvalidArgumentException|InvalidReservationQuantity|InvalidReservationUserId $exception) {
            $io->error($exception->getMessage());

            return self::INVALID;
        } catch (ProductNotFound|InsufficientStock $exception) {
            $io->error($exception->getMessage());

            return self::FAILURE;
        }

        $reservationId = $reservation->id()
            ?? throw new \LogicException('A successfully created reservation must have an ID.');

        $io->success('Reservation created successfully.');
        $io->table(
            ['Field', 'Value'],
            [
                ['Reservation ID', (string) $reservationId],
                ['Product ID', (string) $productId],
                ['User ID', $reservation->userId()],
                ['Quantity', (string) $reservation->quantity()],
                ['Status', $reservation->status()->value],
                ['Expires at', $reservation->expiresAt()->format(DATE_ATOM)],
            ],
        );

        return self::SUCCESS;
    }
}
