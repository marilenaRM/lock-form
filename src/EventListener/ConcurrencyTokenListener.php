<?php

declare(strict_types=1);

namespace LockForm\FormConcurrency\EventListener;

use LockForm\FormConcurrency\Fingerprint;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\Form\FormInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * One instance per built form: it carries the fingerprint between the set-data and submit phases of that form only.
 */
final class ConcurrencyTokenListener implements EventSubscriberInterface
{
    public const string CONFLICT_MESSAGE = 'form_concurrency.conflict';
    public const string FIELD_CHANGED_MESSAGE = 'form_concurrency.field_changed';

    private ?Fingerprint $current = null;

    private bool $conflict = false;

    /** @var list<string> */
    private array $changedPaths = [];

    public function __construct(
        private readonly string $fieldName,
        #[\SensitiveParameter]
        private readonly string $secret,
        private readonly ?TranslatorInterface $translator = null,
        private readonly ?string $translationDomain = null,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            // Last, so that fields added by the form's own POST_SET_DATA listeners are part of the fingerprint.
            FormEvents::POST_SET_DATA => ['onPostSetData', -1024],
            // First, before the form's own PRE_SUBMIT listeners reshape children from the submitted values.
            FormEvents::PRE_SUBMIT => ['onPreSubmit', 1024],
            // Children clear their errors when they are submitted, so field errors can only be attached once they are.
            FormEvents::POST_SUBMIT => ['onPostSubmit', 0],
        ];
    }

    public function onPostSetData(FormEvent $event): void
    {
        if ($this->isProtected($event->getForm())) {
            $this->current = Fingerprint::ofForm($event->getForm(), $this->secret);
        }
    }

    public function onPreSubmit(FormEvent $event): void
    {
        $form = $event->getForm();
        if (!$this->isProtected($form)) {
            return;
        }

        // At this point the children still hold the data the form was created with,
        // which is the state freshly loaded by the controller for this request.
        $this->current = Fingerprint::ofForm($form, $this->secret);

        $data = $event->getData();
        $token = \is_array($data) && \is_string($data[$this->fieldName] ?? null) ? $data[$this->fieldName] : null;
        $seen = null === $token ? null : Fingerprint::fromToken($token);

        $this->changedPaths = null === $seen ? [] : $seen->changedPaths($this->current);
        $this->conflict = null === $seen || [] !== $this->changedPaths;

        if (\is_array($data)) {
            unset($data[$this->fieldName]);
            $event->setData($data);
        }
    }

    public function onPostSubmit(FormEvent $event): void
    {
        $form = $event->getForm();
        if (!$this->conflict || !$this->isProtected($form)) {
            return;
        }

        $form->addError($this->error(self::CONFLICT_MESSAGE));

        foreach ($this->changedPaths as $path) {
            $this->find($form, $path)?->addError($this->error(self::FIELD_CHANGED_MESSAGE));
        }
    }

    /**
     * The token to render: what the form showed when it was last loaded from fresh data.
     * After a conflict this is the current stored state, so resubmitting means "I reviewed it, overwrite".
     */
    public function token(): string
    {
        return ($this->current ?? throw new \LogicException('The form data has not been set yet.'))->toToken();
    }

    /**
     * GET forms are searches and filters: they edit nothing, and are reached through links that carry no token.
     */
    public static function appliesTo(bool $isRoot, bool $compound, string $method): bool
    {
        return $isRoot && $compound && 'GET' !== strtoupper($method);
    }

    private function isProtected(FormInterface $form): bool
    {
        return self::appliesTo($form->isRoot(), $form->getConfig()->getCompound(), $form->getConfig()->getMethod());
    }

    private function find(FormInterface $form, string $path): ?FormInterface
    {
        foreach (explode('.', $path) as $name) {
            if (!$form->has($name)) {
                return null;
            }
            $form = $form->get($name);
        }

        return $form;
    }

    private function error(string $key): FormError
    {
        return new FormError($this->translator?->trans($key, [], $this->translationDomain) ?? $key, $key);
    }
}
