<?php

declare(strict_types=1);

namespace LockForm\FormConcurrency\Presence;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The heartbeat of presence.js: "this tab still shows the record, who else does?", or "this tab left it".
 */
final readonly class PresenceController
{
    public const string SOMEONE_MESSAGE = 'form_concurrency.presence.someone';
    public const string ONE_MESSAGE = 'form_concurrency.presence.one';
    public const string MANY_MESSAGE = 'form_concurrency.presence.many';

    public function __construct(
        private PresenceStore $store,
        #[\SensitiveParameter]
        private string $secret,
        private ?TranslatorInterface $translator = null,
    ) {
    }

    public function __invoke(Request $request): Response
    {
        $payload = $request->getPayload();
        $token = PresenceToken::verify($payload->getString('token'), $this->secret);
        $tab = $payload->getString('tab');
        if (null === $token || 1 !== preg_match('/^[A-Za-z0-9_-]{1,64}$/', $tab)) {
            return new JsonResponse(['error' => 'Invalid presence token or tab.'], Response::HTTP_BAD_REQUEST);
        }

        if ($payload->getBoolean('leave')) {
            $this->store->leave($token->key, $tab);

            return new Response(status: Response::HTTP_NO_CONTENT);
        }

        $others = $this->store->heartbeat($token->key, $token->viewer, $tab);

        $response = new JsonResponse([
            'others' => array_map(static fn (PresenceViewer $viewer): ?string => $viewer->name, $others),
            'message' => $this->message($others),
        ]);
        $response->setPrivate();
        $response->headers->addCacheControlDirective('no-store');

        return $response;
    }

    /**
     * @param list<PresenceViewer> $others
     */
    private function message(array $others): ?string
    {
        if ([] === $others) {
            return null;
        }

        // Anonymous viewers are only named when nobody else is: "Alice" rather than "Alice, someone else".
        $names = [];
        foreach ($others as $viewer) {
            if (null !== $viewer->name && '' !== $viewer->name) {
                $names[] = $viewer->name;
            }
        }

        [$key, $parameters] = match (\count($names)) {
            0 => [self::SOMEONE_MESSAGE, []],
            1 => [self::ONE_MESSAGE, ['{{ name }}' => $names[0]]],
            default => [self::MANY_MESSAGE, ['{{ names }}' => implode(', ', $names)]],
        };

        return $this->translator?->trans($key, $parameters, 'form_concurrency') ?? strtr($key, $parameters);
    }
}
