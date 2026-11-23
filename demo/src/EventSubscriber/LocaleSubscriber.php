<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Picks the language of the demo: `?lang=fr|en` chooses it and keeps it in the session, so that the editors in the
 * iframes and the LiveComponent re-renders follow; without a choice, the browser's preferred language wins.
 */
final readonly class LocaleSubscriber implements EventSubscriberInterface
{
    public const QUERY_PARAMETER = 'lang';
    private const SESSION_KEY = '_locale';

    /**
     * @param list<string> $enabledLocales
     */
    public function __construct(
        #[Autowire('%kernel.enabled_locales%')]
        private array $enabledLocales,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        // After the session is started, before Symfony's LocaleListener (priority 16).
        return [KernelEvents::REQUEST => ['onKernelRequest', 20]];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        $request = $event->getRequest();
        $session = $request->hasSession() ? $request->getSession() : null;

        $chosen = $request->query->getString(self::QUERY_PARAMETER);
        if (\in_array($chosen, $this->enabledLocales, true)) {
            $session?->set(self::SESSION_KEY, $chosen);
            $request->setLocale($chosen);

            return;
        }

        $stored = $session?->get(self::SESSION_KEY);
        if (\is_string($stored) && \in_array($stored, $this->enabledLocales, true)) {
            $request->setLocale($stored);

            return;
        }

        // With no Accept-Language header, this is the first enabled locale: French.
        $request->setLocale($request->getPreferredLanguage($this->enabledLocales) ?? $request->getDefaultLocale());
    }
}
