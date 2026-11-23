<?php

declare(strict_types=1);

namespace LockForm\FormConcurrency\Tests\Fixtures;

/**
 * Plain mutable object standing in for an edited record: the protection must not depend on Doctrine.
 */
final class Article
{
    /**
     * @param array<int, string> $tags
     */
    public function __construct(
        public string $title = '',
        public ?\DateTimeImmutable $publishedAt = null,
        public ?float $price = null,
        public ?Category $category = null,
        public Author $author = new Author(),
        public array $tags = [],
        public string $reviewedBy = '',
        public string $internalNotes = '',
    ) {
    }

    public function __clone()
    {
        $this->author = clone $this->author;
        if (null !== $this->category) {
            $this->category = new Category($this->category->id, $this->category->label);
        }
    }
}
