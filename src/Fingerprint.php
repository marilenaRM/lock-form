<?php

declare(strict_types=1);

namespace LockForm\FormConcurrency;

use Symfony\Component\Form\Exception\TransformationFailedException;
use Symfony\Component\Form\FormInterface;

/**
 * Hash of what each editable field of a form displays, keyed by property path ("address.city", "tags.1").
 *
 * View data is used on purpose: the form's own transformers have already turned dates, numbers,
 * enums and related objects into strings, so two loads of the same state always hash the same way
 * without knowing anything about the storage behind the object.
 *
 * The token reaches the browser, and some values are never displayed (a PasswordType with always_empty):
 * a keyed hash keeps them from being brute-forced out of it.
 */
final readonly class Fingerprint
{
    /**
     * @param array<string, string> $hashes
     */
    private function __construct(private array $hashes)
    {
    }

    public static function ofForm(FormInterface $form, #[\SensitiveParameter] string $secret): self
    {
        $hashes = [];
        self::collect($form, '', $secret, $hashes, static fn (FormInterface $field): mixed => $field->getViewData());
        ksort($hashes);

        return new self($hashes);
    }

    /**
     * What a submitted form would display if it were saved, for comparison with what is stored now.
     *
     * The view data of a submitted field is the raw submitted value ("15", " Ada"), so each field's normalized
     * data is turned back into view data with its own transformers ("15.00", "Ada"), as a fresh load of the saved
     * state would display it. A field whose value could not be transformed is left out: it never matches.
     */
    public static function ofSubmittedForm(FormInterface $form, #[\SensitiveParameter] string $secret): self
    {
        $hashes = [];
        self::collect($form, '', $secret, $hashes, self::normalizedViewData(...));
        ksort($hashes);

        return new self($hashes);
    }

    public static function fromToken(string $token): ?self
    {
        $json = base64_decode(strtr($token, '-_', '+/'), true);
        if (false === $json) {
            return null;
        }

        try {
            $hashes = json_decode($json, true, 2, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        if (!\is_array($hashes)) {
            return null;
        }

        foreach ($hashes as $hash) {
            if (!\is_string($hash)) {
                return null;
            }
        }

        /** @var array<string, string> $hashes */
        return new self($hashes);
    }

    public function toToken(): string
    {
        return rtrim(strtr(base64_encode(json_encode($this->hashes, \JSON_THROW_ON_ERROR | \JSON_FORCE_OBJECT)), '+/', '-_'), '=');
    }

    /**
     * Paths whose value differs between this fingerprint (what the user saw) and $current
     * (what is stored now), including fields that appeared or disappeared, such as collection entries.
     *
     * @return list<string>
     */
    public function changedPaths(self $current): array
    {
        $changed = [];
        foreach ($this->hashes + $current->hashes as $path => $_) {
            if (($this->hashes[$path] ?? null) !== ($current->hashes[$path] ?? null)) {
                $changed[] = (string) $path;
            }
        }

        return $changed;
    }

    /**
     * @param array<string, string>          $hashes
     * @param \Closure(FormInterface): mixed $valueOf the value to hash for a leaf field; an object is left out
     */
    private static function collect(FormInterface $form, string $prefix, string $secret, array &$hashes, \Closure $valueOf): void
    {
        foreach ($form as $name => $child) {
            // A disabled field is never submitted, so a concurrent change to it cannot be overwritten.
            // An unmapped field does not reflect the edited object at all.
            if ($child->isDisabled() || !$child->getConfig()->getMapped()) {
                continue;
            }

            $path = '' === $prefix ? (string) $name : $prefix.'.'.$name;

            if ($child->getConfig()->getCompound()) {
                self::collect($child, $path, $secret, $hashes, $valueOf);

                continue;
            }

            $value = $valueOf($child);
            if (\is_object($value)) {
                continue;
            }

            // serialize() rather than json_encode(): stored strings are not guaranteed to be valid UTF-8.
            $hashes[$path] = substr(hash_hmac('sha256', serialize($value), $secret), 0, 16);
        }
    }

    /**
     * Same as Form::normToView(), which is private.
     *
     * @return mixed an object when the value cannot be compared, so that it is left out
     */
    private static function normalizedViewData(FormInterface $field): mixed
    {
        if (!$field->isSynchronized()) {
            return new \stdClass();
        }

        $value = $field->getNormData();
        $transformers = $field->getConfig()->getViewTransformers();
        if ([] === $transformers) {
            return null === $value || \is_scalar($value) ? (string) $value : $value;
        }

        try {
            foreach ($transformers as $transformer) {
                $value = $transformer->transform($value);
            }
        } catch (TransformationFailedException) {
            return new \stdClass();
        }

        return $value;
    }
}
