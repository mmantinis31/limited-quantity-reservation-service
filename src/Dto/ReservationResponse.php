<?php

declare(strict_types=1);

namespace App\Dto;

use App\Domain\Reservation;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'ReservationResponse',
    required: [
        'id',
        'productId',
        'userId',
        'quantity',
        'status',
        'createdAt',
        'expiresAt',
        'confirmedAt',
        'expiredAt',
    ],
)]
final readonly class ReservationResponse
{
    public function __construct(
        #[OA\Property(type: 'integer', example: 42)]
        public int $id,
        #[OA\Property(type: 'integer', example: 1)]
        public int $productId,
        #[OA\Property(type: 'string', example: 'user-123')]
        public string $userId,
        #[OA\Property(type: 'integer', minimum: 1, maximum: Reservation::MAX_QUANTITY, example: 2)]
        public int $quantity,
        #[OA\Property(type: 'string', enum: ['pending', 'confirmed', 'expired'], example: 'pending')]
        public string $status,
        #[OA\Property(type: 'string', format: 'date-time', example: '2026-01-15T10:00:00+00:00')]
        public string $createdAt,
        #[OA\Property(type: 'string', format: 'date-time', example: '2026-01-15T10:15:00+00:00')]
        public string $expiresAt,
        #[OA\Property(type: 'string', format: 'date-time', example: null, nullable: true)]
        public ?string $confirmedAt,
        #[OA\Property(type: 'string', format: 'date-time', example: null, nullable: true)]
        public ?string $expiredAt,
    ) {
    }

    public static function fromReservation(Reservation $reservation): self
    {
        return new self(
            self::reservationId($reservation),
            self::productId($reservation),
            $reservation->userId(),
            $reservation->quantity(),
            $reservation->status()->value,
            $reservation->createdAt()->format(DATE_ATOM),
            $reservation->expiresAt()->format(DATE_ATOM),
            $reservation->confirmedAt()?->format(DATE_ATOM),
            $reservation->expiredAt()?->format(DATE_ATOM),
        );
    }

    /**
     * @return array{
     *     id: int,
     *     productId: int,
     *     userId: string,
     *     quantity: int,
     *     status: string,
     *     createdAt: string,
     *     expiresAt: string,
     *     confirmedAt: string|null,
     *     expiredAt: string|null
     * }
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'productId' => $this->productId,
            'userId' => $this->userId,
            'quantity' => $this->quantity,
            'status' => $this->status,
            'createdAt' => $this->createdAt,
            'expiresAt' => $this->expiresAt,
            'confirmedAt' => $this->confirmedAt,
            'expiredAt' => $this->expiredAt,
        ];
    }

    private static function reservationId(Reservation $reservation): int
    {
        return $reservation->id()
            ?? throw new \LogicException('A reservation response can only be created for a persisted reservation.');
    }

    private static function productId(Reservation $reservation): int
    {
        return $reservation->product()->id()
            ?? throw new \LogicException('A reservation response can only reference a persisted product.');
    }
}
