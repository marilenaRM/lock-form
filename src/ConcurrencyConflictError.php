<?php

declare(strict_types=1);

namespace LockForm\FormConcurrency;

use Symfony\Component\Form\FormError;

/**
 * The global error put on a form refused for a concurrent edit, so that an application can tell it apart from its
 * own errors: `$error instanceof ConcurrencyConflictError` to show it as an alert or a flash message.
 *
 * The errors on each changed field stay plain FormError instances: they belong next to their field, like any
 * validation error, and `getErrors(true)` would otherwise return several of these for one conflict.
 */
final class ConcurrencyConflictError extends FormError
{
}
