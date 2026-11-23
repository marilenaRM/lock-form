<?php

declare(strict_types=1);

namespace LockForm\FormConcurrency\Presence;

/**
 * Who is looking at a form: a stable id, the same in all of this person's tabs, and the name shown to the others.
 */
final readonly class PresenceViewer
{
    /**
     * @param ?string $name null shows this person as "someone else"
     */
    public function __construct(
        public string $id,
        public ?string $name = null,
    ) {
        if ('' === $id) {
            throw new \InvalidArgumentException('A presence viewer needs a non-empty id.');
        }
    }
}
