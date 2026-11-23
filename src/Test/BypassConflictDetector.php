<?php

declare(strict_types=1);

namespace LockForm\FormConcurrency\Test;

use LockForm\FormConcurrency\ConflictDetector;
use LockForm\FormConcurrency\Fingerprint;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Accepts every submission of a request that carries the FORM_CONCURRENCY_BYPASS server parameter, for functional
 * tests that post a raw array without the token. Registered by form_concurrency.test_bypass, for the test
 * environment only.
 *
 * The switch lives on the request rather than on a service: KernelBrowser builds a new container for every request
 * unless reboot is disabled, which would lose a flag set on a service, while server parameters stay on the client.
 * A new client per test starts without it, so nothing leaks from one test to the next.
 */
final class BypassConflictDetector implements ConflictDetector
{
    public const string SERVER_PARAMETER = 'FORM_CONCURRENCY_BYPASS';

    public function __construct(
        private readonly ConflictDetector $inner,
        private readonly RequestStack $requestStack,
    ) {
    }

    public function changedPaths(FormInterface $form, ?Fingerprint $seen, Fingerprint $current): ?array
    {
        if (true === $this->requestStack->getMainRequest()?->server->getBoolean(self::SERVER_PARAMETER)) {
            return [];
        }

        return $this->inner->changedPaths($form, $seen, $current);
    }
}
