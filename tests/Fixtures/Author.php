<?php

declare(strict_types=1);

namespace LockForm\FormConcurrency\Tests\Fixtures;

final class Author
{
    public function __construct(
        public string $name = '',
        public string $email = '',
    ) {
    }
}
