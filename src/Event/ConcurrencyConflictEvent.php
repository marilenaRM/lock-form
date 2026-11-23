<?php

declare(strict_types=1);

namespace LockForm\FormConcurrency\Event;

use Symfony\Component\Form\FormInterface;
use Symfony\Contracts\EventDispatcher\Event;

/**
 * Dispatched each time a submission is refused because the record changed since the form was displayed,
 * once the errors are on the form. It is there to count conflicts, not to settle them.
 *
 * The same edit can be refused several times: sent again without acknowledging the conflict, or re-rendered by a
 * LiveComponent, which submits the form on every change. $seenToken stays the same for all of them. It is a
 * fingerprint of the state the user saw, shared by everyone who saw that state: combine it with the session
 * and the record to tell conflicts apart.
 */
final class ConcurrencyConflictEvent extends Event
{
    /**
     * @param list<string> $changedPaths property paths changed meanwhile ("price", "author.email", "tags.2");
     *                                   empty when the submission carried no usable token
     * @param ?string      $seenToken    the token the user started from, null when the submission carried none
     */
    public function __construct(
        public readonly FormInterface $form,
        public readonly array $changedPaths,
        public readonly ?string $seenToken,
    ) {
    }
}
