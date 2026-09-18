<?php

declare(strict_types=1);

namespace App\Controller;

use App\Application\ConfirmReservation;
use App\Application\CreateReservation;
use App\Dto\CreateReservationRequest;
use App\Dto\ProblemResponse;
use App\Dto\ReservationResponse;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response as HttpResponse;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Serializer\Normalizer\AbstractNormalizer;

#[Route('/api/reservations', name: 'api.reservations.')]
final readonly class ReservationController
{
    public function __construct(
        private CreateReservation $createReservation,
        private ConfirmReservation $confirmReservation,
    ) {
    }

    #[Route('', name: 'create', methods: ['POST'], format: 'json')]
    #[OA\Post(
        operationId: 'createReservation',
        description: 'Atomically reserves available product stock for 15 minutes.',
        summary: 'Create a pending reservation',
        requestBody: new OA\RequestBody(description: 'Reservation details', required: true),
        tags: ['Reservations'],
        responses: [
            new OA\Response(
                response: HttpResponse::HTTP_CREATED,
                description: 'Reservation created',
                content: new Model(type: ReservationResponse::class),
            ),
            new OA\Response(
                response: HttpResponse::HTTP_BAD_REQUEST,
                description: 'Malformed JSON or an unknown request property',
                content: new OA\MediaType(
                    mediaType: 'application/problem+json',
                    schema: new OA\Schema(ref: new Model(type: ProblemResponse::class)),
                ),
            ),
            new OA\Response(
                response: HttpResponse::HTTP_NOT_FOUND,
                description: 'Product not found',
                content: new OA\MediaType(
                    mediaType: 'application/problem+json',
                    schema: new OA\Schema(ref: new Model(type: ProblemResponse::class)),
                ),
            ),
            new OA\Response(
                response: HttpResponse::HTTP_CONFLICT,
                description: 'Insufficient available stock',
                content: new OA\MediaType(
                    mediaType: 'application/problem+json',
                    schema: new OA\Schema(ref: new Model(type: ProblemResponse::class)),
                ),
            ),
            new OA\Response(
                response: HttpResponse::HTTP_UNPROCESSABLE_ENTITY,
                description: 'Request validation failed',
                content: new OA\MediaType(
                    mediaType: 'application/problem+json',
                    schema: new OA\Schema(ref: new Model(type: ProblemResponse::class)),
                ),
            ),
            new OA\Response(
                response: HttpResponse::HTTP_UNSUPPORTED_MEDIA_TYPE,
                description: 'Content-Type must be application/json',
                content: new OA\MediaType(
                    mediaType: 'application/problem+json',
                    schema: new OA\Schema(ref: new Model(type: ProblemResponse::class)),
                ),
            ),
        ],
    )]
    public function create(
        #[MapRequestPayload(
            acceptFormat: 'json',
            serializationContext: [AbstractNormalizer::ALLOW_EXTRA_ATTRIBUTES => false],
        )]
        CreateReservationRequest $request,
    ): JsonResponse {
        $reservation = $this->createReservation->execute(
            $request->productId(),
            $request->userId(),
            $request->quantity(),
        );

        return new JsonResponse(
            ReservationResponse::fromReservation($reservation)->toArray(),
            HttpResponse::HTTP_CREATED,
        );
    }

    #[Route('/{id}/confirm', name: 'confirm', requirements: ['id' => '[1-9]\d*'], methods: ['POST'], format: 'json')]
    #[OA\Post(
        operationId: 'confirmReservation',
        description: 'Idempotently confirms a pending, unexpired reservation after successful payment.',
        summary: 'Confirm a reservation',
        tags: ['Reservations'],
        parameters: [
            new OA\Parameter(
                name: 'id',
                description: 'Reservation ID',
                in: 'path',
                required: true,
                schema: new OA\Schema(type: 'integer', minimum: 1),
                example: 42,
            ),
        ],
        responses: [
            new OA\Response(
                response: HttpResponse::HTTP_OK,
                description: 'Reservation confirmed, or already confirmed',
                content: new Model(type: ReservationResponse::class),
            ),
            new OA\Response(
                response: HttpResponse::HTTP_NOT_FOUND,
                description: 'Reservation not found',
                content: new OA\MediaType(
                    mediaType: 'application/problem+json',
                    schema: new OA\Schema(ref: new Model(type: ProblemResponse::class)),
                ),
            ),
            new OA\Response(
                response: HttpResponse::HTTP_CONFLICT,
                description: 'Reservation is expired or in an invalid state',
                content: new OA\MediaType(
                    mediaType: 'application/problem+json',
                    schema: new OA\Schema(ref: new Model(type: ProblemResponse::class)),
                ),
            ),
        ],
    )]
    public function confirm(int $id): JsonResponse
    {
        $reservation = $this->confirmReservation->execute($id);

        return new JsonResponse(
            ReservationResponse::fromReservation($reservation)->toArray(),
            HttpResponse::HTTP_OK,
        );
    }
}
