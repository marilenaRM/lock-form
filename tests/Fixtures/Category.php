<?php

declare(strict_types=1);

namespace LockForm\FormConcurrency\Tests\Fixtures;

final readonly class Category
{
    public function __construct(
        public int $id,
        public string $label,
    ) {
    }
}
