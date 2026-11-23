<?php

declare(strict_types=1);

namespace LockForm\FormConcurrency\Tests\Integration\App;

use LockForm\FormConcurrency\ConflictDetector;
use LockForm\FormConcurrency\Fingerprint;
use Symfony\Component\DependencyInjection\Attribute\AsDecorator;
use Symfony\Component\DependencyInjection\Attribute\AutowireDecorated;
use Symfony\Component\Form\FormInterface;

/**
 * A decorator as an application would write it: a concurrent change to the title is not a conflict.
 */
#[AsDecorator(ConflictDetector::class)]
final class TitleIsLastWriteWins implements ConflictDetector
{
    public function __construct(#[AutowireDecorated] private readonly ConflictDetector $inner)
    {
    }

    public function changedPaths(FormInterface $form, ?Fingerprint $seen, Fingerprint $current): ?array
    {
        $changed = $this->inner->changedPaths($form, $seen, $current);

        return null === $changed ? null : array_values(array_diff($changed, ['title']));
    }
}
