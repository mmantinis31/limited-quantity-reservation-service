<?php

declare(strict_types=1);

namespace App\DataFixtures;

use App\Domain\Product;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;

final class ProductFixtures extends Fixture
{
    private const array PRODUCTS = [
        [
            'name' => 'Concert Ticket',
            'availableStock' => 100,
        ],
        [
            'name' => 'Limited Edition Vinyl',
            'availableStock' => 25,
        ],
        [
            'name' => 'Collector Box',
            'availableStock' => 10,
        ],
    ];

    #[\Override]
    public function load(ObjectManager $manager): void
    {
        foreach (self::PRODUCTS as $productData) {
            $manager->persist(new Product(
                $productData['name'],
                $productData['availableStock'],
            ));
        }

        $manager->flush();
    }
}
