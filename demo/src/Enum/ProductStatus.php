<?php

declare(strict_types=1);

namespace App\Enum;

enum ProductStatus: string
{
    case Draft = 'draft';
    case Active = 'active';
    case Archived = 'archived';

    public function labelKey(): string
    {
        return 'product.status.'.$this->value;
    }
}
