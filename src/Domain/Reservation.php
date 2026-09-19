<?php

declare(strict_types=1);

namespace App\Domain;

use App\Domain\Exception\InvalidReservationQuantity;
use App\Domain\Exception\InvalidReservationUserId;
use App\Domain\Exception\ReservationCannotBeConfirmed;
use App\Domain\Exception\ReservationCannotBeExpired;
use App\Repository\ReservationRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ReservationRepository::class)]
#[ORM\Table(name: 'reservations')]
#[ORM\Index(name: 'idx_reservations_expiration', columns: ['status', 'expires_at', 'id'])]
#[ORM\Index(name: 'idx_reservations_product', columns: ['product_id'])]
class Reservation
{
    public const int MIN_QUANTITY = 1;
    public const int MAX_QUANTITY = 10;
    public const int USER_ID_MAX_LENGTH = 128;
    public const int LIFETIME_MINUTES = 15;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'product_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT')]
    private Product $product;

    #[ORM\Column(name: 'user_id', type: Types::STRING, length: self::USER_ID_MAX_LENGTH)]
    private string $userId;

    #[ORM\Column(type: Types::SMALLINT)]
    private int $quantity;

    #[ORM\Column(type: Types::STRING, length: 16, enumType: ReservationStatus::class)]
    private ReservationStatus $status;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'expires_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $expiresAt;

    #[ORM\Column(name: 'confirmed_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $confirmedAt = null;

    #[ORM\Column(name: 'expired_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $expiredAt = null;

    private function __construct(
        Product $product,
        string $userId,
        int $quantity,
        \DateTimeImmutable $createdAt,
    ) {
        $this->product = $product;
        $this->userId = $userId;
        $this->quantity = $quantity;
        $this->status = ReservationStatus::PENDING;
        $this->createdAt = $createdAt;
        $this->expiresAt = $createdAt->add(new \DateInterval(sprintf('PT%dM', self::LIFETIME_MINUTES)));
    }

    public static function create(
        Product $product,
        string $userId,
        int $quantity,
        \DateTimeImmutable $createdAt,
    ): self {
        if ($quantity < self::MIN_QUANTITY || $quantity > self::MAX_QUANTITY) {
            throw new InvalidReservationQuantity($quantity, self::MIN_QUANTITY, self::MAX_QUANTITY);
        }

        if ('' === trim($userId) || mb_strlen($userId) > self::USER_ID_MAX_LENGTH) {
            throw new InvalidReservationUserId(self::USER_ID_MAX_LENGTH);
        }

        return new self($product, $userId, $quantity, self::normalizeToUtcSecondPrecision($createdAt));
    }

    public function id(): ?int
    {
        return $this->id;
    }

    public function product(): Product
    {
        return $this->product;
    }

    public function userId(): string
    {
        return $this->userId;
    }

    public function quantity(): int
    {
        return $this->quantity;
    }

    public function status(): ReservationStatus
    {
        return $this->status;
    }

    public function createdAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function expiresAt(): \DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function confirmedAt(): ?\DateTimeImmutable
    {
        return $this->confirmedAt;
    }

    public function expiredAt(): ?\DateTimeImmutable
    {
        return $this->expiredAt;
    }

    public function confirm(\DateTimeImmutable $now): bool
    {
        if (ReservationStatus::CONFIRMED === $this->status) {
            return false;
        }

        if (ReservationStatus::PENDING !== $this->status) {
            throw ReservationCannotBeConfirmed::fromStatus($this->status);
        }

        $now = self::normalizeToUtcSecondPrecision($now);

        if ($now >= $this->expiresAt) {
            throw ReservationCannotBeConfirmed::becauseExpired($this->expiresAt);
        }

        $this->status = ReservationStatus::CONFIRMED;
        $this->confirmedAt = $now;

        return true;
    }

    public function expire(\DateTimeImmutable $now): bool
    {
        if (ReservationStatus::EXPIRED === $this->status) {
            return false;
        }

        if (ReservationStatus::PENDING !== $this->status) {
            throw ReservationCannotBeExpired::fromStatus($this->status);
        }

        $now = self::normalizeToUtcSecondPrecision($now);

        if ($now < $this->expiresAt) {
            throw ReservationCannotBeExpired::beforeDeadline($this->expiresAt);
        }

        $this->status = ReservationStatus::EXPIRED;
        $this->expiredAt = $now;

        return true;
    }

    public function isExpiredAt(\DateTimeImmutable $now): bool
    {
        if (ReservationStatus::EXPIRED === $this->status) {
            return true;
        }

        return ReservationStatus::PENDING === $this->status
            && self::normalizeToUtcSecondPrecision($now) >= $this->expiresAt;
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
