<?php

declare(strict_types=1);

namespace LockForm\FormConcurrency\Presence;

use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Routing\Exception\RouteNotFoundException;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * The attributes put on the <form> tag of a form that shows the presence indicator, read by presence.js.
 */
final readonly class PresenceMarkup
{
    public const string ROUTE = 'form_concurrency_presence';

    public function __construct(
        private RequestStack $requestStack,
        private UrlGeneratorInterface $urlGenerator,
        private PresenceIdentity $identity,
        #[\SensitiveParameter]
        private string $secret,
        private int $interval,
    ) {
    }

    /**
     * @param ?string $key the record the form edits; null for the path of the page, which fits one edit page per record
     *
     * @return array<string, string> empty outside a request, or when the viewer must not be tracked
     */
    public function attributes(?string $key): array
    {
        $request = $this->requestStack->getMainRequest();
        $viewer = null === $request ? null : $this->identity->viewer($request);
        if (null === $request || null === $viewer) {
            return [];
        }

        try {
            $url = $this->urlGenerator->generate(self::ROUTE);
        } catch (RouteNotFoundException $exception) {
            throw new \LogicException('The presence indicator needs its route: import "@FormConcurrencyBundle/config/routes.php" in config/routes.', previous: $exception);
        }

        return [
            'data-concurrency-presence' => (new PresenceToken($key ?? $request->getPathInfo(), $viewer))->sign($this->secret),
            'data-concurrency-presence-url' => $url,
            'data-concurrency-presence-interval' => (string) $this->interval,
        ];
    }
}
