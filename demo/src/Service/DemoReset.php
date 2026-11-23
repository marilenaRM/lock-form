<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Category;
use App\Entity\Product;
use App\Enum\ProductStatus;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;

/**
 * Puts the demo back to its starting point. The database is a throwaway SQLite file, so the schema is
 * rebuilt from the mapping rather than migrated.
 */
final readonly class DemoReset
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function __invoke(): Product
    {
        $schemaTool = new SchemaTool($this->entityManager);
        $metadata = $this->entityManager->getMetadataFactory()->getAllMetadata();
        $schemaTool->dropSchema($metadata);
        $schemaTool->createSchema($metadata);

        $accessories = new Category('Accessoires');
        $peripherals = new Category('Périphériques');
        $product = new Product(
            name: 'Clavier mécanique',
            priceInCents: 8990,
            category: $accessories,
            status: ProductStatus::Active,
            tags: ['usb-c', 'rétroéclairé'],
            stock: 12,
        );

        $this->entityManager->persist($accessories);
        $this->entityManager->persist($peripherals);
        $this->entityManager->persist($product);
        $this->entityManager->flush();
        $this->entityManager->clear();

        return $product;
    }
}
