<?php

declare(strict_types=1);

namespace App\Dto;

use App\Domain\Reservation;
use OpenApi\Attributes as OA;
use Symfony\Component\Validator\Constraints as Assert;

#[OA\Schema(
    schema: 'CreateReservationRequest',
    required: ['productId', 'userId', 'quantity'],
    additionalProperties: false,
)]
final readonly class CreateReservationRequest
{
    public function __construct(
        #[Assert\NotNull(message: 'Product ID is required.')]
        #[Assert\Positive(message: 'Product ID must be a positive integer.')]
        #[OA\Property(type: 'integer', minimum: 1, example: 1, nullable: false)]
        public ?int $productId = null,
        #[Assert\NotNull(message: 'User ID is required.')]
        #[Assert\NotBlank(message: 'User ID must not be blank.', allowNull: true, normalizer: 'trim')]
        #[Assert\Length(
            max: Reservation::USER_ID_MAX_LENGTH,
            maxMessage: 'User ID must not exceed {{ limit }} characters.',
        )]
        #[OA\Property(type: 'string', maxLength: Reservation::USER_ID_MAX_LENGTH, example: 'user-123', nullable: false)]
        public ?string $userId = null,
        #[Assert\NotNull(message: 'Quantity is required.')]
        #[Assert\Range(
            notInRangeMessage: 'Quantity must be between {{ min }} and {{ max }}.',
            min: Reservation::MIN_QUANTITY,
            max: Reservation::MAX_QUANTITY,
        )]
        #[OA\Property(
            type: 'integer',
            example: 2,
            nullable: false,
            maximum: Reservation::MAX_QUANTITY,
            minimum: Reservation::MIN_QUANTITY,
        )]
        public ?int $quantity = null,
    ) {
    }

    public function productId(): int
    {
        return $this->productId
            ?? throw new \LogicException('The request must be validated before accessing its product ID.');
    }

    public function userId(): string
    {
        return $this->userId
            ?? throw new \LogicException('The request must be validated before accessing its user ID.');
    }

    public function quantity(): int
    {
        return $this->quantity
            ?? throw new \LogicException('The request must be validated before accessing its quantity.');
    }
}
