<?php

declare(strict_types=1);

namespace LockForm\FormConcurrency\Presence;

use LockForm\FormConcurrency\EventListener\ConcurrencyTokenListener;
use Symfony\Component\Form\AbstractTypeExtension;
use Symfony\Component\Form\Extension\Core\Type\FormType;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Form\FormView;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Registered whether the indicator is on or not, so that the form options exist either way: with it off
 * ($markup null), a form that sets them renders exactly as before.
 */
final class PresenceTypeExtension extends AbstractTypeExtension
{
    public function __construct(
        private readonly ?PresenceMarkup $markup = null,
    ) {
    }

    public function finishView(FormView $view, FormInterface $form, array $options): void
    {
        if (null === $this->markup || true !== $options['concurrency_presence']
            || !ConcurrencyTokenListener::appliesTo(null === $view->parent, true === $options['compound'], $form->getConfig()->getMethod())) {
            return;
        }

        $key = $options['concurrency_presence_key'];
        $attributes = $this->markup->attributes(\is_string($key) ? $key : null);
        if ([] === $attributes) {
            return;
        }

        $attr = \is_array($view->vars['attr'] ?? null) ? $view->vars['attr'] : [];
        $view->vars['attr'] = $attributes + $attr;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'concurrency_presence' => null !== $this->markup,
            'concurrency_presence_key' => null,
        ]);

        $resolver->setAllowedTypes('concurrency_presence', 'bool');
        $resolver->setAllowedTypes('concurrency_presence_key', ['null', 'string']);
    }

    public static function getExtendedTypes(): iterable
    {
        return [FormType::class];
    }
}
