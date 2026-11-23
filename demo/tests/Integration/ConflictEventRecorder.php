<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use LockForm\FormConcurrency\Event\ConcurrencyConflictEvent;

/**
 * Static, because the test clients reboot the kernel, hence the container, between requests.
 */
final class ConflictEventRecorder
{
    /** @var list<?string> */
    private static array $seenTokens = [];

    public function __invoke(ConcurrencyConflictEvent $event): void
    {
        self::$seenTokens[] = $event->seenToken;
    }

    /**
     * @return list<?string> the seen token of each event dispatched since the last reset
     */
    public static function seenTokens(): array
    {
        return self::$seenTokens;
    }

    public static function reset(): void
    {
        self::$seenTokens = [];
    }
}
