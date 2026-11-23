<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\ProductStatus;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Edited directly through the forms on purpose: binding a Doctrine entity, with an EntityType relation,
 * is the common back-office setup the protection has to work with.
 */
#[ORM\Entity]
class Product
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /**
     * @param list<string> $tags
     */
    public function __construct(
        #[ORM\Column(type: Types::STRING, length: 150)]
        private string $name,
        #[ORM\Column]
        private int $priceInCents,
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: false)]
        private Category $category,
        #[ORM\Column(enumType: ProductStatus::class)]
        private ProductStatus $status = ProductStatus::Draft,
        #[ORM\Column(type: Types::JSON)]
        private array $tags = [],
        #[ORM\Column]
        private int $stock = 0,
    ) {
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(?string $name): void
    {
        $this->name = (string) $name;
    }

    public function getPriceInCents(): int
    {
        return $this->priceInCents;
    }

    public function setPriceInCents(?int $priceInCents): void
    {
        $this->priceInCents = $priceInCents ?? 0;
    }

    public function getCategory(): Category
    {
        return $this->category;
    }

    public function setCategory(Category $category): void
    {
        $this->category = $category;
    }

    public function getStatus(): ProductStatus
    {
        return $this->status;
    }

    public function setStatus(ProductStatus $status): void
    {
        $this->status = $status;
    }

    /**
     * @return list<string>
     */
    public function getTags(): array
    {
        return $this->tags;
    }

    /**
     * @param array<array-key, ?string> $tags
     */
    public function setTags(array $tags): void
    {
        $this->tags = array_values(array_filter($tags, static fn (?string $tag): bool => null !== $tag && '' !== $tag));
    }

    public function getStock(): int
    {
        return $this->stock;
    }

    public function setStock(int $stock): void
    {
        $this->stock = $stock;
    }
}
