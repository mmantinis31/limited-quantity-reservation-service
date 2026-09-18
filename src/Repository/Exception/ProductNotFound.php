<?php

declare(strict_types=1);

namespace App\Repository\Exception;

final class ProductNotFound extends \RuntimeException
{
    public function __construct(public readonly int $productId)
    {
        parent::__construct(sprintf('Product with ID %d was not found.', $productId));
    }
}
