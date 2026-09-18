<?php

declare(strict_types=1);

namespace App\Tests\Functional\Http;

use PHPUnit\Framework\Attributes\Test;

final class ApiDocumentationTest extends FunctionalDatabaseTestCase
{
    #[Test]
    public function itExposesSwaggerUi(): void
    {
        $this->client->request('GET', '/api/doc');

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'text/html; charset=UTF-8');
        $content = $this->client->getResponse()->getContent();
        self::assertNotFalse($content);
        self::assertStringContainsString('swagger-ui', $content);
    }

    #[Test]
    public function itDocumentsBothReservationEndpointsAndTheirSchemas(): void
    {
        $this->client->request('GET', '/api/doc.json');

        self::assertResponseIsSuccessful();
        $documentation = $this->responseData();

        self::assertArrayHasKey('/api/reservations', self::paths($documentation));
        self::assertArrayHasKey('/api/reservations/{id}/confirm', self::paths($documentation));

        $schemas = self::schemas($documentation);
        self::assertArrayHasKey('CreateReservationRequest', $schemas);
        self::assertArrayHasKey('ReservationResponse', $schemas);
        self::assertArrayHasKey('ProblemResponse', $schemas);

        $requestSchema = self::associativeArray($schemas['CreateReservationRequest']);
        self::assertSame(
            ['productId', 'userId', 'quantity'],
            array_keys(self::schemaProperties($requestSchema)),
        );

        $createOperation = self::associativeArray(
            self::paths($documentation)['/api/reservations'],
        );
        self::assertArrayHasKey('post', $createOperation);
        self::assertSame(
            '#/components/schemas/ProblemResponse',
            self::responseSchemaReference($createOperation, '422', 'application/problem+json'),
        );
        self::assertSame(
            '#/components/schemas/CreateReservationRequest',
            self::requestSchemaReference($createOperation),
        );

        $confirmOperation = self::associativeArray(
            self::paths($documentation)['/api/reservations/{id}/confirm'],
        );
        self::assertArrayHasKey('post', $confirmOperation);
        self::assertSame(
            '#/components/schemas/ReservationResponse',
            self::responseSchemaReference($confirmOperation, '200', 'application/json'),
        );

        foreach (['404', '409'] as $status) {
            self::assertSame(
                '#/components/schemas/ProblemResponse',
                self::responseSchemaReference($confirmOperation, $status, 'application/problem+json'),
            );
        }
    }

    /**
     * @param array<string, mixed> $operation
     */
    private static function requestSchemaReference(array $operation): string
    {
        $post = self::associativeArray($operation['post'] ?? null);
        $requestBody = self::associativeArray($post['requestBody'] ?? null);
        $content = self::associativeArray($requestBody['content'] ?? null);
        $jsonContent = self::associativeArray($content['application/json'] ?? null);
        $schema = self::associativeArray($jsonContent['schema'] ?? null);
        $reference = $schema['$ref'] ?? null;
        self::assertIsString($reference);

        return $reference;
    }

    /**
     * @param array<string, mixed> $operation
     */
    private static function responseSchemaReference(
        array $operation,
        string $status,
        string $mediaType,
    ): string {
        $post = self::associativeArray($operation['post'] ?? null);
        $responses = $post['responses'] ?? null;
        self::assertIsArray($responses);
        $response = self::associativeArray($responses[$status] ?? null);
        $content = self::associativeArray($response['content'] ?? null);
        $mediaTypeContent = self::associativeArray($content[$mediaType] ?? null);
        $schema = self::associativeArray($mediaTypeContent['schema'] ?? null);
        $reference = $schema['$ref'] ?? null;
        self::assertIsString($reference);

        return $reference;
    }

    /**
     * @param array<string, mixed> $documentation
     *
     * @return array<string, mixed>
     */
    private static function paths(array $documentation): array
    {
        $paths = $documentation['paths'] ?? null;

        return self::associativeArray($paths);
    }

    /**
     * @param array<string, mixed> $documentation
     *
     * @return array<string, mixed>
     */
    private static function schemas(array $documentation): array
    {
        $components = $documentation['components'] ?? null;
        $components = self::associativeArray($components);
        $schemas = $components['schemas'] ?? null;

        return self::associativeArray($schemas);
    }

    /**
     * @param array<string, mixed> $schema
     *
     * @return array<string, mixed>
     */
    private static function schemaProperties(array $schema): array
    {
        $properties = $schema['properties'] ?? null;

        return self::associativeArray($properties);
    }
}
