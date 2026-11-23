<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Category;
use App\Entity\Product;
use App\Enum\ProductStatus;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Puts the demo back to its starting point. The database is a throwaway SQLite file, so the schema is
 * rebuilt from the mapping rather than migrated. The starting product is written in the current language
 * (or the one given), so that the English demo does not show a French product.
 */
final readonly class DemoReset
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private TranslatorInterface $translator,
    ) {
    }

    public function __invoke(?string $locale = null): Product
    {
        // The caller may have loaded the current product: drop it, or the new rows (same IDs) would collide with it.
        $this->entityManager->clear();

        $schemaTool = new SchemaTool($this->entityManager);
        $metadata = $this->entityManager->getMetadataFactory()->getAllMetadata();
        $schemaTool->dropSchema($metadata);
        $schemaTool->createSchema($metadata);

        $accessories = new Category($this->seed($locale, 'category.accessories'));
        $peripherals = new Category($this->seed($locale, 'category.peripherals'));
        $product = new Product(
            name: $this->seed($locale, 'product.name'),
            priceInCents: 8990,
            category: $accessories,
            status: ProductStatus::Active,
            tags: ['usb-c', $this->seed($locale, 'tag.backlit')],
            stock: 12,
        );

        $this->entityManager->persist($accessories);
        $this->entityManager->persist($peripherals);
        $this->entityManager->persist($product);
        $this->entityManager->flush();
        $this->entityManager->clear();

        return $product;
    }

    /**
     * Whether the product, as long as nobody has changed it, was written in the given language.
     */
    public function isStartingProductIn(Product $product, string $locale): bool
    {
        return $product->getName() === $this->seed($locale, 'product.name');
    }

    private function seed(?string $locale, string $key): string
    {
        return $this->translator->trans('seed.'.$key, [], 'messages', $locale);
    }
}
