<?php

declare(strict_types=1);

namespace LockForm\FormConcurrency;

use Symfony\Component\Form\FormInterface;

/**
 * The default detector: every field whose fingerprint differs, and a refusal without a usable token, as for CSRF.
 */
final class FingerprintConflictDetector implements ConflictDetector
{
    public function changedPaths(FormInterface $form, ?Fingerprint $seen, Fingerprint $current): ?array
    {
        return $seen?->changedPaths($current);
    }
}
