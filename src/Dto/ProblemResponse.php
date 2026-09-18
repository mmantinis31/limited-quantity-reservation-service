<?php

declare(strict_types=1);

namespace App\Dto;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'ProblemResponse',
    required: ['type', 'title', 'status', 'detail', 'code', 'instance'],
)]
final readonly class ProblemResponse
{
    /**
     * @param list<array{propertyPath: string, message: string}> $violations
     */
    public function __construct(
        #[OA\Property(type: 'string', format: 'uri', example: 'urn:problem:insufficient-stock')]
        public string $type,
        #[OA\Property(type: 'string', example: 'Insufficient stock')]
        public string $title,
        #[OA\Property(type: 'integer', example: 409)]
        public int $status,
        #[OA\Property(type: 'string', example: 'Cannot reserve 2 unit(s); only 1 unit(s) are available.')]
        public string $detail,
        #[OA\Property(type: 'string', example: 'insufficient_stock')]
        public string $code,
        #[OA\Property(type: 'string', example: '/api/reservations')]
        public string $instance,
        #[OA\Property(
            type: 'array',
            items: new OA\Items(
                type: 'object',
                required: ['propertyPath', 'message'],
                properties: [
                    new OA\Property(property: 'propertyPath', type: 'string', example: 'quantity'),
                    new OA\Property(property: 'message', type: 'string', example: 'Quantity must be between 1 and 10.'),
                ],
            ),
        )]
        public array $violations = [],
    ) {
    }

    /**
     * @return array{
     *     type: string,
     *     title: string,
     *     status: int,
     *     detail: string,
     *     code: string,
     *     instance: string,
     *     violations?: list<array{propertyPath: string, message: string}>
     * }
     */
    public function toArray(): array
    {
        $problem = [
            'type' => $this->type,
            'title' => $this->title,
            'status' => $this->status,
            'detail' => $this->detail,
            'code' => $this->code,
            'instance' => $this->instance,
        ];

        if ([] !== $this->violations) {
            $problem['violations'] = $this->violations;
        }

        return $problem;
    }
}
