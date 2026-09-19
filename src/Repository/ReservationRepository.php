<?php

declare(strict_types=1);

namespace App\Repository;

use App\Domain\Reservation;
use App\Domain\ReservationStatus;
use App\Repository\Exception\ReservationNotFound;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\LockMode;
use Doctrine\DBAL\Query\ForUpdate\ConflictResolutionMode;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Query;
use Doctrine\ORM\TransactionRequiredException;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Reservation>
 */
final class ReservationRepository extends ServiceEntityRepository
{
    public const int MAX_EXPIRATION_BATCH_SIZE = 1_000;

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Reservation::class);
    }

    public function getForUpdate(int $reservationId): Reservation
    {
        $this->assertTransactionActive();

        $reservation = $this->find($reservationId, LockMode::PESSIMISTIC_WRITE);

        if (null === $reservation) {
            throw new ReservationNotFound($reservationId);
        }

        return $reservation;
    }

    /**
     * Locks and returns the oldest pending reservations whose confirmation deadline has passed.
     *
     * Locked rows are skipped so multiple expiration workers can process disjoint batches.
     * The locks remain held until the caller commits or rolls back the current transaction.
     *
     * @return list<Reservation>
     */
    public function findExpiredPendingForUpdate(
        \DateTimeImmutable $now,
        int $batchSize,
    ): array {
        self::assertValidBatchSize($batchSize);
        $this->assertTransactionActive();

        $connection = $this->getEntityManager()->getConnection();
        $expiresAt = self::normalizeToUtcSecondPrecision($now);

        /** @var list<int|string> $rawIds */
        $rawIds = $connection->createQueryBuilder()
            ->select('id')
            ->from('reservations')
            ->where('status = :status')
            ->andWhere('expires_at <= :expiresAt')
            ->setParameter('status', ReservationStatus::PENDING->value)
            ->setParameter('expiresAt', $expiresAt, Types::DATETIME_IMMUTABLE)
            ->orderBy('expires_at', 'ASC')
            ->addOrderBy('id', 'ASC')
            ->setMaxResults($batchSize)
            ->forUpdate(ConflictResolutionMode::SKIP_LOCKED)
            ->executeQuery()
            ->fetchFirstColumn();

        if ([] === $rawIds) {
            return [];
        }

        $ids = array_map(
            static fn (int|string $id): int => (int) $id,
            $rawIds,
        );

        $query = $this->createQueryBuilder('reservation')
            ->where('reservation.id IN (:ids)')
            ->setParameter('ids', $ids, ArrayParameterType::INTEGER)
            ->orderBy('reservation.expiresAt', 'ASC')
            ->addOrderBy('reservation.id', 'ASC')
            ->getQuery();

        $query->setHint(Query::HINT_REFRESH, true);

        /** @var list<Reservation> $reservations */
        $reservations = $query->getResult();

        return $reservations;
    }

    private static function assertValidBatchSize(int $batchSize): void
    {
        if ($batchSize < 1 || $batchSize > self::MAX_EXPIRATION_BATCH_SIZE) {
            throw new \InvalidArgumentException(sprintf('Expiration batch size must be between 1 and %d.', self::MAX_EXPIRATION_BATCH_SIZE));
        }
    }

    private function assertTransactionActive(): void
    {
        if (!$this->getEntityManager()->getConnection()->isTransactionActive()) {
            throw TransactionRequiredException::transactionRequired();
        }
    }

    private static function normalizeToUtcSecondPrecision(\DateTimeImmutable $dateTime): \DateTimeImmutable
    {
        $dateTime = $dateTime->setTimezone(new \DateTimeZone('UTC'));

        return $dateTime->setTime(
            (int) $dateTime->format('H'),
            (int) $dateTime->format('i'),
            (int) $dateTime->format('s'),
        );
    }
}
