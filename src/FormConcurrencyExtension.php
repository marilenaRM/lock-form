<?php

declare(strict_types=1);

namespace LockForm\FormConcurrency;

use LockForm\FormConcurrency\Type\ConcurrencyTypeExtension;
use Symfony\Component\Form\AbstractExtension;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Registers the protection on a standalone form factory (Forms::createFormFactoryBuilder()->addExtension(...)).
 * In a Symfony application, enable FormConcurrencyBundle instead.
 */
final class FormConcurrencyExtension extends AbstractExtension
{
    public function __construct(
        #[\SensitiveParameter]
        private readonly string $secret,
        private readonly ?TranslatorInterface $translator = null,
        private readonly bool $defaultEnabled = true,
        private readonly ?EventDispatcherInterface $dispatcher = null,
        private readonly ConflictDetector $detector = new FingerprintConflictDetector(),
    ) {
    }

    protected function loadTypeExtensions(): array
    {
        return [new ConcurrencyTypeExtension($this->secret, $this->translator, $this->defaultEnabled, dispatcher: $this->dispatcher, detector: $this->detector)];
    }
}
