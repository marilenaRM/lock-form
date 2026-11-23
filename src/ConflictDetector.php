<?php

declare(strict_types=1);

namespace LockForm\FormConcurrency;

use Symfony\Component\Form\FormInterface;

/**
 * Decides, on submit, which fields changed since the user opened the form.
 *
 * One stateless service shared by every form; what a form carries from one of its events to the next stays in its
 * own listener. In a Symfony application, decorate the ConflictDetector service to change what counts as a
 * conflict, for instance with #[AsDecorator(ConflictDetector::class)].
 */
interface ConflictDetector
{
    /**
     * The property paths changed between what the user saw and what is stored now. An empty list accepts the
     * submission; null refuses it without any field to report, as for a missing or unreadable token.
     *
     * @param FormInterface $form    the root form, before the fields receive the submitted values
     * @param ?Fingerprint  $seen    what the user saw, from the submitted token; null when there was no usable one
     * @param Fingerprint   $current what the form displays from the stored state
     *
     * @return list<string>|null
     */
    public function changedPaths(FormInterface $form, ?Fingerprint $seen, Fingerprint $current): ?array;
}
