<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\DemoUser;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * One successful save, with the values it changed. Without it a lost update is invisible, which is the whole problem.
 */
#[ORM\Entity]
class SaveLog
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /**
     * @param list<array{field: string, before: string, after: string}> $changes
     */
    public function __construct(
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private Product $product,
        #[ORM\Column(enumType: DemoUser::class)]
        private DemoUser $author,
        #[ORM\Column]
        private string $form,
        #[ORM\Column]
        private bool $protectionEnabled,
        #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
        private \DateTimeImmutable $savedAt,
        #[ORM\Column(type: Types::JSON)]
        private array $changes,
    ) {
    }

    public function getProduct(): Product
    {
        return $this->product;
    }

    public function getAuthor(): DemoUser
    {
        return $this->author;
    }

    public function getForm(): string
    {
        return $this->form;
    }

    public function isProtectionEnabled(): bool
    {
        return $this->protectionEnabled;
    }

    public function getSavedAt(): \DateTimeImmutable
    {
        return $this->savedAt;
    }

    /**
     * @return list<array{field: string, before: string, after: string}>
     */
    public function getChanges(): array
    {
        return $this->changes;
    }
}
