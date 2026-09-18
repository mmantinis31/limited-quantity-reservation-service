<?php

declare(strict_types=1);

namespace App\Tests\Integration\Application;

use App\Application\ConfirmReservation;
use App\Domain\Exception\ReservationCannotBeConfirmed;
use App\Domain\Product;
use App\Domain\Reservation;
use App\Domain\ReservationStatus;
use App\Repository\ReservationRepository;
use App\Tests\Integration\DatabaseTestCase;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Clock\MockClock;

final class ConfirmReservationTest extends DatabaseTestCase
{
    private ReservationRepository $reservations;
    private Product $product;

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        $reservations = self::getContainer()->get(ReservationRepository::class);
        self::assertInstanceOf(ReservationRepository::class, $reservations);
        $this->reservations = $reservations;

        $this->product = new Product('Concert ticket', 10);
        $this->entityManager->persist($this->product);
        $this->entityManager->flush();
    }

    #[Test]
    public function itConfirmsAPendingReservationWithoutChangingStock(): void
    {
        $reservation = $this->persistPendingReservation();
        $clock = new MockClock('2026-01-15 10:05:00', 'UTC');

        $confirmed = $this->useCase($clock)->execute(self::reservationId($reservation));

        self::assertSame(ReservationStatus::CONFIRMED, $confirmed->status());
        self::assertSame('2026-01-15T10:05:00+00:00', $confirmed->confirmedAt()?->format(DATE_ATOM));
        self::assertSame(8, $this->availableStock());
        self::assertSame('confirmed', $this->persistedStatus($reservation));
    }

    #[Test]
    public function confirmingAnAlreadyConfirmedReservationIsIdempotent(): void
    {
        $reservation = $this->persistPendingReservation();
        $clock = new MockClock('2026-01-15 10:05:00', 'UTC');
        $useCase = $this->useCase($clock);

        $firstResult = $useCase->execute(self::reservationId($reservation));
        $firstConfirmedAt = $firstResult->confirmedAt();
        $clock->modify('+5 minutes');

        $secondResult = $useCase->execute(self::reservationId($reservation));

        self::assertSame(ReservationStatus::CONFIRMED, $secondResult->status());
        self::assertEquals($firstConfirmedAt, $secondResult->confirmedAt());
        self::assertSame(8, $this->availableStock());
    }

    #[Test]
    public function itRejectsConfirmationAtTheExpirationBoundary(): void
    {
        $reservation = $this->persistPendingReservation();

        try {
            $this->useCase(new MockClock('2026-01-15 10:15:00', 'UTC'))
                ->execute(self::reservationId($reservation));
            self::fail('A reservation must not be confirmed at its expiration time.');
        } catch (ReservationCannotBeConfirmed) {
            self::assertSame('pending', $this->persistedStatus($reservation));
            self::assertSame(8, $this->availableStock());
        }
    }

    private function useCase(MockClock $clock): ConfirmReservation
    {
        return new ConfirmReservation($this->entityManager, $this->reservations, $clock);
    }

    private function persistPendingReservation(): Reservation
    {
        $this->product->reserve(2);
        $reservation = Reservation::create(
            $this->product,
            'user-123',
            2,
            self::utc('2026-01-15 10:00:00'),
        );

        $this->entityManager->persist($reservation);
        $this->entityManager->flush();

        return $reservation;
    }

    private function availableStock(): int
    {
        return self::databaseInteger($this->connection->fetchOne(
            'SELECT available_stock FROM products WHERE id = ?',
            [self::productId($this->product)],
        ));
    }

    private function persistedStatus(Reservation $reservation): string
    {
        return self::databaseString($this->connection->fetchOne(
            'SELECT status FROM reservations WHERE id = ?',
            [self::reservationId($reservation)],
        ));
    }

    private static function productId(Product $product): int
    {
        $productId = $product->id();
        self::assertNotNull($productId);

        return $productId;
    }

    private static function reservationId(Reservation $reservation): int
    {
        $reservationId = $reservation->id();
        self::assertNotNull($reservationId);

        return $reservationId;
    }

    private static function utc(string $dateTime): \DateTimeImmutable
    {
        return new \DateTimeImmutable($dateTime, new \DateTimeZone('UTC'));
    }
}
