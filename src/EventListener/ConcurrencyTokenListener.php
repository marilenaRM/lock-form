<?php

declare(strict_types=1);

namespace LockForm\FormConcurrency\EventListener;

use LockForm\FormConcurrency\DisplayedValue;
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
    public const string FIELD_CHANGED_TO_MESSAGE = 'form_concurrency.field_changed_to';
    public const string FIELD_CLEARED_MESSAGE = 'form_concurrency.field_cleared';
    public const string ENTRIES_CHANGED_MESSAGE = 'form_concurrency.entries_changed';

    private ?Fingerprint $current = null;

    private bool $conflict = false;

    /**
     * What each changed field displays now, keyed by path; null when it cannot be shown.
     *
     * @var array<string, ?string>
     */
    private array $currentValues = [];

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

        $changedPaths = null === $seen ? [] : $seen->changedPaths($this->current);
        $this->conflict = null === $seen || [] !== $changedPaths;
        $this->currentValues = [] === $changedPaths ? [] : $this->displayedValues($form, $changedPaths);

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

        $reported = [];
        foreach ($this->currentValues as $path => $value) {
            [$field, $exact] = $this->closest($form, $path);
            if ($field->isRoot() || \in_array($field, $reported, true)) {
                continue;
            }
            $reported[] = $field;

            $field->addError(match (true) {
                // An entry added or removed meanwhile is not in the submitted collection, so the collection carries
                // the error. A collection bubbles its errors up by default, hence a message that names it.
                !$exact => $this->error(self::ENTRIES_CHANGED_MESSAGE, ['{{ field }}' => $this->labelOf($field)]),
                null === $value => $this->error(self::FIELD_CHANGED_MESSAGE),
                '' === $value => $this->error(self::FIELD_CLEARED_MESSAGE),
                default => $this->error(self::FIELD_CHANGED_TO_MESSAGE, ['{{ value }}' => $value]),
            });
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

    /**
     * Read before the fields are submitted, while the form still holds the stored state. Only runs on a
     * conflict: it renders the whole form, so that labels and translation domains resolve as on screen.
     *
     * @param list<string> $paths
     *
     * @return array<string, ?string>
     */
    private function displayedValues(FormInterface $form, array $paths): array
    {
        $view = $form->createView();

        $values = [];
        foreach ($paths as $path) {
            [$field, $exact] = $this->closest($form, $path);
            $fieldView = $view;
            foreach ($exact ? explode('.', $path) : [] as $name) {
                $fieldView = $fieldView->children[$name] ?? null;
                if (null === $fieldView) {
                    break;
                }
            }

            $values[$path] = $exact && null !== $fieldView ? DisplayedValue::of($field, $fieldView, $this->translator) : null;
        }

        return $values;
    }

    /**
     * @return array{FormInterface, bool} the field at $path, or its deepest existing ancestor, and whether it is exact
     */
    private function closest(FormInterface $form, string $path): array
    {
        foreach (explode('.', $path) as $name) {
            if (!$form->has($name)) {
                return [$form, false];
            }
            $form = $form->get($name);
        }

        return [$form, true];
    }

    private function labelOf(FormInterface $field): string
    {
        $config = $field->getConfig();
        $label = $config->getOption('label');
        if (!\is_string($label) || '' === $label) {
            // Same fallback as the label Twig renders (FormRenderer::humanize(), an instance method).
            return ucfirst(strtolower(trim((string) preg_replace(['/([A-Z])/', '/[_\s]+/'], ['_$1', ' '], $field->getName()))));
        }

        $domain = $config->getOption('translation_domain');

        return null === $this->translator || false === $domain ? $label : $this->translator->trans($label, [], \is_string($domain) ? $domain : null);
    }

    /**
     * @param array<string, string> $parameters
     */
    private function error(string $key, array $parameters = []): FormError
    {
        return new FormError($this->translator?->trans($key, $parameters, $this->translationDomain) ?? strtr($key, $parameters), $key, $parameters);
    }
}
