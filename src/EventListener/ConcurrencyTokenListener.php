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
    public const string ACKNOWLEDGEMENT_LABEL = 'form_concurrency.acknowledge';

    /** What is stored now: set when the form receives its data, then again from the stored state on submit. */
    private ?Fingerprint $current = null;

    private bool $conflict = false;

    /**
     * Whether the submission carries, for every field changed meanwhile, the value it holds now: saving it overwrites
     * nothing, so the conflict is not reported. It only holds for that submission, see token().
     */
    private bool $overwritesNothing = false;

    /** The token the submission was based on, sent back as is while the conflict is not acknowledged. */
    private ?string $seenToken = null;

    /**
     * What each changed field displays now, keyed by path; null when it cannot be shown.
     *
     * @var array<string, ?string>
     */
    private array $currentValues = [];

    public function __construct(
        private readonly string $fieldName,
        private readonly string $acknowledgementFieldName,
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
        $this->seenToken = self::stringAt($data, $this->fieldName);
        $seen = null === $this->seenToken ? null : Fingerprint::fromToken($this->seenToken);

        // Acknowledging is ticking the box rendered with a conflict, whose value is the state shown at that moment.
        // It only counts for that exact state: a change made since then is a new conflict.
        $acknowledgement = self::stringAt($data, $this->acknowledgementFieldName);
        $acknowledged = null !== $acknowledgement && hash_equals($this->current->toToken(), $acknowledgement);

        $changedPaths = null === $seen ? [] : $seen->changedPaths($this->current);
        $this->overwritesNothing = false;
        $this->conflict = !$acknowledged && (null === $seen || [] !== $changedPaths);
        $this->currentValues = $this->conflict && [] !== $changedPaths ? $this->displayedValues($form, $changedPaths) : [];

        if (\is_array($data)) {
            unset($data[$this->fieldName], $data[$this->acknowledgementFieldName]);
            $event->setData($data);
        }
    }

    public function onPostSubmit(FormEvent $event): void
    {
        $form = $event->getForm();
        if (!$this->conflict || !$this->isProtected($form) || null === $this->current) {
            return;
        }

        // A field changed meanwhile is not a conflict when the user submits the value it holds now: saving it
        // overwrites nothing. Without a usable token there are no changed fields to compare, so it stays a conflict.
        if ([] !== $this->currentValues) {
            $differing = Fingerprint::ofSubmittedForm($form, $this->secret)->changedPaths($this->current);
            $this->currentValues = array_intersect_key($this->currentValues, array_flip($differing));

            if ([] === $this->currentValues) {
                $this->overwritesNothing = true;

                return;
            }
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
     * The token to render. Without a conflict, it is the state the form shows. After a conflict, it stays the token
     * the submission was based on: rendering the form again, as a LiveComponent does on every change and reads the
     * values back, must not settle the conflict. Only the acknowledgement does.
     *
     * A submission that overwrites nothing keeps that token too: the next one is compared again with the stored
     * state, so that a value typed after it, in a LiveComponent that submits on every change, is still a conflict.
     */
    public function token(): string
    {
        if ($this->conflict) {
            return $this->seenToken ?? '';
        }

        return ($this->current ?? throw new \LogicException('The form data has not been set yet.'))->toToken();
    }

    /**
     * The value of the acknowledgement box to render with a conflict, or null when there is none to acknowledge.
     */
    public function acknowledgementToken(): ?string
    {
        return $this->conflict && !$this->overwritesNothing ? $this->current?->toToken() : null;
    }

    /**
     * GET forms are searches and filters: they edit nothing, and are reached through links that carry no token.
     */
    public static function appliesTo(bool $isRoot, bool $compound, string $method): bool
    {
        return $isRoot && $compound && 'GET' !== strtoupper($method);
    }

    private static function stringAt(mixed $data, string $key): ?string
    {
        return \is_array($data) && \is_string($data[$key] ?? null) ? $data[$key] : null;
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
