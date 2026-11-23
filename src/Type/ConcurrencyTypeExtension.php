<?php

declare(strict_types=1);

namespace LockForm\FormConcurrency\Type;

use LockForm\FormConcurrency\ConflictDetector;
use LockForm\FormConcurrency\EventListener\ConcurrencyTokenListener;
use LockForm\FormConcurrency\FingerprintConflictDetector;
use Symfony\Component\Form\AbstractTypeExtension;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\FormType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Form\FormView;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Symfony\Contracts\Translation\TranslatableInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

final class ConcurrencyTypeExtension extends AbstractTypeExtension
{
    public const string TRANSLATION_DOMAIN = 'form_concurrency';

    private const string LISTENER_ATTRIBUTE = 'concurrency_token_listener';

    public function __construct(
        #[\SensitiveParameter]
        private readonly string $secret,
        private readonly ?TranslatorInterface $translator = null,
        private readonly bool $defaultEnabled = true,
        private readonly string $defaultFieldName = '_concurrency_token',
        private readonly ?EventDispatcherInterface $dispatcher = null,
        private readonly ConflictDetector $detector = new FingerprintConflictDetector(),
    ) {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        if (!$options['concurrency_protection']) {
            return;
        }

        $message = $options['concurrency_message'];
        \assert(\is_string($message) || $message instanceof TranslatableInterface);

        $listener = new ConcurrencyTokenListener(
            self::fieldName($options),
            self::fieldName($options).'_acknowledged',
            $this->secret,
            $this->translator,
            self::TRANSLATION_DOMAIN,
            $this->dispatcher,
            $this->detector,
            $message,
        );
        $builder->setAttribute(self::LISTENER_ATTRIBUTE, $listener);
        $builder->addEventSubscriber($listener);
    }

    public function finishView(FormView $view, FormInterface $form, array $options): void
    {
        if (!$options['concurrency_protection'] || !ConcurrencyTokenListener::appliesTo(null === $view->parent, true === $options['compound'], $form->getConfig()->getMethod())) {
            return;
        }

        $listener = $form->getConfig()->getAttribute(self::LISTENER_ATTRIBUTE);
        \assert($listener instanceof ConcurrencyTokenListener);

        $fieldName = self::fieldName($options);
        $field = $form->getConfig()->getFormFactory()->createNamed($fieldName, HiddenType::class, $listener->token(), [
            'block_prefix' => 'concurrency_token',
            'mapped' => false,
        ]);

        $view->children[$fieldName] = $field->createView($view);

        $acknowledgement = $listener->acknowledgementToken();
        if (null !== $acknowledgement) {
            $acknowledgementName = $fieldName.'_acknowledged';
            $checkbox = $form->getConfig()->getFormFactory()->createNamed($acknowledgementName, CheckboxType::class, false, [
                'block_prefix' => 'concurrency_acknowledgement',
                'mapped' => false,
                'required' => false,
                'value' => $acknowledgement,
                'label' => ConcurrencyTokenListener::ACKNOWLEDGEMENT_LABEL,
                'translation_domain' => self::TRANSLATION_DOMAIN,
            ]);

            $view->children[$acknowledgementName] = $checkbox->createView($view);
        }
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'concurrency_protection' => $this->defaultEnabled,
            'concurrency_field_name' => $this->defaultFieldName,
            'concurrency_message' => ConcurrencyTokenListener::CONFLICT_MESSAGE,
        ]);

        $resolver->setAllowedTypes('concurrency_protection', 'bool');
        $resolver->setAllowedTypes('concurrency_field_name', 'string');
        $resolver->setAllowedTypes('concurrency_message', ['string', TranslatableInterface::class]);
        $resolver->setInfo('concurrency_message', 'The global error of a conflict: a key of the form_concurrency translation domain, or a TranslatableInterface.');
    }

    public static function getExtendedTypes(): iterable
    {
        return [FormType::class];
    }

    /**
     * @param array<string, mixed> $options
     */
    private static function fieldName(array $options): string
    {
        return \is_string($options['concurrency_field_name']) ? $options['concurrency_field_name'] : throw new \LogicException('The "concurrency_field_name" option must be a string.');
    }
}
