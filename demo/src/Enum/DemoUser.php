<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * The two people of the demo. There is no authentication: who is editing comes from the URL.
 */
enum DemoUser: string
{
    case Alice = 'alice';
    case Bob = 'bob';

    public function labelKey(): string
    {
        return 'demo.user.'.$this->value;
    }
}
