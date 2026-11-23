<?php

declare(strict_types=1);

namespace LockForm\FormConcurrency\Tests\Integration\App;

use LockForm\FormConcurrency\Presence\PresenceIdentity;
use LockForm\FormConcurrency\Presence\PresenceViewer;
use Symfony\Component\HttpFoundation\Request;

/**
 * Stands in for a login: the viewer is the X-Viewer header, absent for an anonymous visitor.
 */
final class HeaderPresenceIdentity implements PresenceIdentity
{
    public function viewer(Request $request): ?PresenceViewer
    {
        $name = $request->headers->get('X-Viewer');

        return null === $name || '' === $name ? null : new PresenceViewer(strtolower($name), $name);
    }
}
