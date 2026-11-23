<?php

declare(strict_types=1);

namespace LockForm\FormConcurrency;

use Symfony\Component\Form\ChoiceList\View\ChoiceGroupView;
use Symfony\Component\Form\ChoiceList\View\ChoiceView;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Form\FormView;
use Symfony\Contracts\Translation\TranslatableInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The value a field shows on screen, as text, so that a conflict can tell the user what is stored now.
 */
final class DisplayedValue
{
    /**
     * @return string|null null when the field deliberately hides its value (a PasswordType never renders it),
     *                     or when its value is a state rather than text (a checkbox or radio is checked or not)
     */
    public static function of(FormInterface $field, FormView $view, ?TranslatorInterface $translator): ?string
    {
        if (\array_key_exists('checked', $view->vars)) {
            return null;
        }

        $rendered = $view->vars['value'] ?? null;

        if (self::isEmpty($rendered)) {
            return self::isEmpty($field->getViewData()) ? '' : null;
        }

        $values = array_map(self::scalarToString(...), \is_array($rendered) ? array_values($rendered) : [$rendered]);

        if (\is_array($view->vars['choices'] ?? null)) {
            $domain = $view->vars['choice_translation_domain'] ?? null;
            $labels = self::choiceLabels($view->vars['choices'], $values, $translator, false === $domain ? false : (\is_string($domain) ? $domain : null));
            $values = array_map(static fn (string $value): string => $labels[$value] ?? $value, $values);
        }

        return implode(', ', $values);
    }

    /**
     * @param array<mixed>    $choices
     * @param list<string>    $values
     * @param string|false|null $domain false when choice labels are not translated
     *
     * @return array<string, string>
     */
    private static function choiceLabels(array $choices, array $values, ?TranslatorInterface $translator, string|false|null $domain): array
    {
        $labels = [];
        foreach ($choices as $choice) {
            if ($choice instanceof ChoiceGroupView) {
                $labels += self::choiceLabels($choice->choices, $values, $translator, $domain);

                continue;
            }

            if (!$choice instanceof ChoiceView || !\in_array($choice->value, $values, true)) {
                continue;
            }

            $labels[$choice->value] = match (true) {
                false === $choice->label => $choice->value,
                $choice->label instanceof TranslatableInterface => null === $translator ? $choice->value : $choice->label->trans($translator),
                null !== $translator && false !== $domain => $translator->trans($choice->label, $choice->labelTranslationParameters, $domain),
                default => $choice->label,
            };
        }

        return $labels;
    }

    private static function isEmpty(mixed $value): bool
    {
        return null === $value || '' === $value || [] === $value;
    }

    private static function scalarToString(mixed $value): string
    {
        return \is_scalar($value) ? (string) $value : '';
    }
}
