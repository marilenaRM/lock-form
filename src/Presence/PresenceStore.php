<?php

declare(strict_types=1);

namespace LockForm\FormConcurrency\Presence;

use Psr\Cache\CacheItemPoolInterface;

/**
 * Who has which record open, kept in a cache pool. Each record is one cache item listing its open tabs.
 *
 * Read, change, write: two heartbeats on the same record at the same instant can lose one of the two tabs, until
 * its next heartbeat puts it back. That is good enough for an indicator, and would be worthless for a lock.
 */
final readonly class PresenceStore
{
    /**
     * @param int $ttl seconds after its last heartbeat before a tab is considered closed
     */
    public function __construct(
        private CacheItemPoolInterface $pool,
        private int $ttl = 90,
    ) {
    }

    /**
     * Records that this tab still shows the record.
     *
     * @return list<PresenceViewer> the other people who have it open now, one entry per person
     */
    public function heartbeat(string $key, PresenceViewer $viewer, string $tab, ?int $now = null): array
    {
        $now ??= time();
        $item = $this->pool->getItem(self::itemKey($key));
        $tabs = self::alive($item->isHit() ? $item->get() : null, $now);
        $tabs[$tab] = ['viewer' => $viewer->id, 'name' => $viewer->name, 'expires' => $now + $this->ttl];

        $this->pool->save($item->set($tabs)->expiresAfter($this->ttl));

        $others = [];
        foreach ($tabs as $entry) {
            if ($entry['viewer'] !== $viewer->id) {
                $others[$entry['viewer']] ??= new PresenceViewer($entry['viewer'], $entry['name']);
            }
        }

        return array_values($others);
    }

    /**
     * Records that this tab left the record, so the others stop seeing it without waiting for the TTL.
     */
    public function leave(string $key, string $tab, ?int $now = null): void
    {
        $item = $this->pool->getItem(self::itemKey($key));
        if (!$item->isHit()) {
            return;
        }

        $tabs = self::alive($item->get(), $now ?? time());
        unset($tabs[$tab]);

        if ([] === $tabs) {
            $this->pool->deleteItem(self::itemKey($key));
        } else {
            $this->pool->save($item->set($tabs)->expiresAfter($this->ttl));
        }
    }

    /**
     * @return array<string, array{viewer: string, name: ?string, expires: int}>
     */
    private static function alive(mixed $tabs, int $now): array
    {
        $alive = [];
        foreach (\is_array($tabs) ? $tabs : [] as $tab => $entry) {
            if (\is_array($entry) && \is_string($entry['viewer'] ?? null) && \is_int($entry['expires'] ?? null) && $entry['expires'] > $now) {
                $name = $entry['name'] ?? null;
                $alive[(string) $tab] = ['viewer' => $entry['viewer'], 'name' => \is_string($name) ? $name : null, 'expires' => $entry['expires']];
            }
        }

        return $alive;
    }

    /**
     * Cache keys cannot hold the characters of a URL ("{}()/\@:").
     */
    private static function itemKey(string $key): string
    {
        return 'form_concurrency.presence.'.hash('xxh128', $key);
    }
}
