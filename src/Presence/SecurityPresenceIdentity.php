<?php

declare(strict_types=1);

namespace LockForm\FormConcurrency\Presence;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

/**
 * The default identity: the logged-in user, named by their user identifier. Nobody is tracked without
 * symfony/security-bundle or without a logged-in user; implement PresenceIdentity to show a display name instead.
 */
final readonly class SecurityPresenceIdentity implements PresenceIdentity
{
    public function __construct(
        private ?TokenStorageInterface $tokenStorage = null,
    ) {
    }

    public function viewer(Request $request): ?PresenceViewer
    {
        $identifier = $this->tokenStorage?->getToken()?->getUserIdentifier();

        return null === $identifier || '' === $identifier ? null : new PresenceViewer($identifier, $identifier);
    }
}
