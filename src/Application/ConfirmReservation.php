<?php

declare(strict_types=1);

namespace App\Application;

use App\Domain\Reservation;
use App\Repository\ReservationRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\ClockInterface;

final readonly class ConfirmReservation
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private ReservationRepository $reservations,
        private ClockInterface $clock,
    ) {
    }

    public function execute(int $reservationId): Reservation
    {
        return $this->entityManager->wrapInTransaction(function () use ($reservationId): Reservation {
            $reservation = $this->reservations->getForUpdate($reservationId);
            $reservation->confirm($this->clock->now());

            return $reservation;
        });
    }
}
