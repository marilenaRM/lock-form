<?php

declare(strict_types=1);

namespace LockForm\FormConcurrency\Tests\Fixtures;

use Symfony\Component\Form\FormInterface;
use Symfony\Component\Form\FormView;

/**
 * What a browser does with a rendered form: send back every rendered value, hidden token included.
 */
final class FormRoundTrip
{
    /**
     * @param FormInterface $form
     *
     * @return array<string, mixed>
     */
    public static function payloadOf(FormInterface $form): array
    {
        $payload = self::valuesOf($form->createView());
        \assert(\is_array($payload));

        /** @var array<string, mixed> $payload */
        return $payload;
    }

    /**
     * @param FormInterface $form
     */
    public static function tokenOf(FormInterface $form, string $fieldName = '_concurrency_token'): string
    {
        $token = $form->createView()->children[$fieldName]->vars['value'] ?? null;
        \assert(\is_string($token));

        return $token;
    }

    private static function valuesOf(FormView $view): mixed
    {
        if ($view->vars['disabled'] ?? false) {
            return null;
        }

        if ([] === $view->children && !($view->vars['compound'] ?? false)) {
            return $view->vars['value'];
        }

        $values = [];
        foreach ($view->children as $name => $child) {
            if (!($child->vars['disabled'] ?? false)) {
                $values[$name] = self::valuesOf($child);
            }
        }

        return $values;
    }
}
