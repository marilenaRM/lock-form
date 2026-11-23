<?php

declare(strict_types=1);

namespace LockForm\FormConcurrency\Presence;

use Symfony\Component\HttpFoundation\Request;

/**
 * Tells who is displaying a form. Called when the form is rendered, never by the heartbeat: the result is signed
 * into the presence token, so the heartbeat needs neither the session nor the security layer.
 */
interface PresenceIdentity
{
    /**
     * @return ?PresenceViewer null when this person must not be tracked (anonymous visitor, API client…)
     */
    public function viewer(Request $request): ?PresenceViewer;
}
