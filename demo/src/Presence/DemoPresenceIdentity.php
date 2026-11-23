<?php

declare(strict_types=1);

namespace App\Presence;

use App\Enum\DemoUser;
use LockForm\FormConcurrency\Presence\PresenceIdentity;
use LockForm\FormConcurrency\Presence\PresenceViewer;
use Symfony\Component\HttpFoundation\Request;

/**
 * The demo has no login: who is editing comes from the "as" parameter of the page, as everywhere else in the demo.
 * A real application keeps the default identity (the logged-in user), or names its users here.
 */
final class DemoPresenceIdentity implements PresenceIdentity
{
    public function viewer(Request $request): ?PresenceViewer
    {
        $user = DemoUser::tryFrom($request->query->getString('as'));

        return null === $user ? null : new PresenceViewer($user->value, ucfirst($user->value));
    }
}
