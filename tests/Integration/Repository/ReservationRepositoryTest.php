<?php

declare(strict_types=1);

namespace App\Tests\Integration\Repository;

use App\Domain\Product;
use App\Domain\Reservation;
use App\Repository\Exception\ReservationNotFound;
use App\Repository\ReservationRepository;
use App\Tests\Integration\DatabaseTestCase;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Query\ForUpdate\ConflictResolutionMode;
use Doctrine\ORM\TransactionRequiredException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

final class ReservationRepositoryTest extends DatabaseTestCase
{
    private const string USER_ID = 'user-123';

    private ReservationRepository $repository;
    private Product $product;

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        $repository = self::getContainer()->get(ReservationRepository::class);
        self::assertInstanceOf(ReservationRepository::class, $repository);
        $this->repository = $repository;

        $this->product = new Product('Concert ticket', 100);
        $this->entityManager->persist($this->product);
        $this->entityManager->flush();
    }

    #[Test]
    public function getForUpdateRequiresAnActiveTransaction(): void
    {
        $this->expectException(TransactionRequiredException::class);

        $this->repository->getForUpdate(1);
    }

    #[Test]
    public function itReturnsAReservationAndHoldsAnExclusiveDatabaseLock(): void
    {
        $reservation = $this->persistPendingReservation(self::utc('2026-01-15 10:00:00'));
        $reservationId = self::reservationId($reservation);
        $this->entityManager->clear();
        $this->connection->beginTransaction();

        $lockedReservation = $this->repository->getForUpdate($reservationId);

        self::assertSame($reservationId, $lockedReservation->id());

        $competingConnection = $this->createCompetingConnection();
        $competingConnection->beginTransaction();

        try {
            $competingResult = $competingConnection->createQueryBuilder()
                ->select('id')
                ->from('reservations')
                ->where('id = :id')
                ->setParameter('id', $reservationId, ParameterType::INTEGER)
                ->forUpdate(ConflictResolutionMode::SKIP_LOCKED)
                ->executeQuery()
                ->fetchOne();

            self::assertFalse($competingResult);
        } finally {
            $competingConnection->rollBack();
            $competingConnection->close();
            $this->connection->rollBack();
        }
    }

    #[Test]
    public function getForUpdateReportsAMissingReservation(): void
    {
        $this->connection->beginTransaction();

        $this->expectException(ReservationNotFound::class);
        $this->expectExceptionMessage('Reservation with ID 999 was not found.');

        $this->repository->getForUpdate(999);
    }

    #[Test]
    public function expiredBatchSelectionRequiresAnActiveTransaction(): void
    {
        $this->expectException(TransactionRequiredException::class);

        $this->repository->findExpiredPendingForUpdate(self::utc('2026-01-15 12:00:00'), 100);
    }

    #[Test]
    #[DataProvider('invalidBatchSizes')]
    public function itRejectsAnInvalidExpirationBatchSize(int $batchSize): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Expiration batch size must be between 1 and 1000.');

        $this->repository->findExpiredPendingForUpdate(self::utc('2026-01-15 12:00:00'), $batchSize);
    }

    #[Test]
    public function itSelectsOnlyTheOldestOverduePendingReservationsUpToTheBatchLimit(): void
    {
        $oldest = $this->persistPendingReservation(self::utc('2026-01-15 10:00:00'));
        $next = $this->persistPendingReservation(self::utc('2026-01-15 10:05:00'));
        $future = $this->persistPendingReservation(self::utc('2026-01-15 11:50:00'));

        $confirmed = $this->persistPendingReservation(self::utc('2026-01-15 10:10:00'));
        $confirmed->confirm(self::utc('2026-01-15 10:11:00'));

        $alreadyExpired = $this->persistPendingReservation(self::utc('2026-01-15 10:20:00'));
        $alreadyExpired->expire(self::utc('2026-01-15 10:35:00'));

        $this->entityManager->flush();
        $this->entityManager->clear();
        $this->connection->beginTransaction();

        $batch = $this->repository->findExpiredPendingForUpdate(
            self::utc('2026-01-15 12:00:00'),
            2,
        );

        self::assertSame(
            [self::reservationId($oldest), self::reservationId($next)],
            array_map(static fn (Reservation $reservation): ?int => $reservation->id(), $batch),
        );
        self::assertNotContains(
            self::reservationId($future),
            array_map(static fn (Reservation $reservation): ?int => $reservation->id(), $batch),
        );
    }

    #[Test]
    public function itSkipsRowsLockedByAnotherExpirationWorker(): void
    {
        $lockedByOtherWorker = $this->persistPendingReservation(self::utc('2026-01-15 10:00:00'));
        $availableToThisWorker = $this->persistPendingReservation(self::utc('2026-01-15 10:05:00'));
        $this->entityManager->clear();

        $competingConnection = $this->createCompetingConnection();
        $competingConnection->beginTransaction();

        try {
            $competingConnection->createQueryBuilder()
                ->select('id')
                ->from('reservations')
                ->where('id = :id')
                ->setParameter('id', self::reservationId($lockedByOtherWorker), ParameterType::INTEGER)
                ->forUpdate()
                ->executeQuery()
                ->fetchOne();

            $this->connection->beginTransaction();

            $batch = $this->repository->findExpiredPendingForUpdate(
                self::utc('2026-01-15 12:00:00'),
                10,
            );

            self::assertSame(
                [self::reservationId($availableToThisWorker)],
                array_map(static fn (Reservation $reservation): ?int => $reservation->id(), $batch),
            );
        } finally {
            if ($this->connection->isTransactionActive()) {
                $this->connection->rollBack();
            }

            $competingConnection->rollBack();
            $competingConnection->close();
        }
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function invalidBatchSizes(): iterable
    {
        yield 'zero' => [0];
        yield 'negative' => [-1];
        yield 'above maximum' => [ReservationRepository::MAX_EXPIRATION_BATCH_SIZE + 1];
    }

    private function persistPendingReservation(\DateTimeImmutable $createdAt): Reservation
    {
        $reservation = Reservation::create($this->product, self::USER_ID, 1, $createdAt);
        $this->entityManager->persist($reservation);
        $this->entityManager->flush();

        return $reservation;
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
