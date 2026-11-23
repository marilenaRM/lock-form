<?php

declare(strict_types=1);

namespace LockForm\FormConcurrency\Tests\Unit;

use LockForm\FormConcurrency\Fingerprint;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Form\Extension\Core\Type\FormType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Form\Forms;

final class FingerprintTest extends TestCase
{
    private const array ALPHABET = ['a', 'Z', '0', ' ', '"', "'", '<', '/', '\\', '.', "\u{e9}", "\u{1f512}", "\xB1"];

    /**
     * @param array<int|string, string> $values
     */
    #[DataProvider('generatedForms')]
    public function testATokenReadsBackAsTheFingerprintItWasMadeFrom(array $values, string $_changedField): void
    {
        $fingerprint = Fingerprint::ofForm(self::formOf($values), 'test-secret');

        $readBack = Fingerprint::fromToken($fingerprint->toToken());

        self::assertNotNull($readBack);
        self::assertSame([], $readBack->changedPaths($fingerprint));
    }

    /**
     * @param array<int|string, string> $values
     */
    #[DataProvider('generatedForms')]
    public function testChangingOneFieldReportsThatFieldOnly(array $values, string $changedField): void
    {
        $seen = Fingerprint::ofForm(self::formOf($values), 'test-secret');

        $values[$changedField] .= '!';
        $current = Fingerprint::ofForm(self::formOf($values), 'test-secret');

        self::assertSame([$changedField], $seen->changedPaths($current));
        self::assertSame([$changedField], $current->changedPaths($seen));
    }

    /**
     * Generated from a fixed seed so that a failure replays: the case name carries the seed and index.
     *
     * @return iterable<string, array{array<int|string, string>, string}>
     */
    public static function generatedForms(): iterable
    {
        $seed = 20261123;
        $random = new \Random\Randomizer(new \Random\Engine\Mt19937($seed));

        for ($i = 0; $i < 50; ++$i) {
            $values = [];
            $fieldCount = $random->getInt(1, 12);
            for ($f = 0; $f < $fieldCount; ++$f) {
                // Field names may be numeric, like collection entries, which PHP turns into integer keys.
                $name = 0 === $random->getInt(0, 3) ? (string) $f : 'field_'.$f;
                $value = '';
                for ($c = $random->getInt(0, 20); $c > 0; --$c) {
                    $value .= self::ALPHABET[$random->getInt(0, \count(self::ALPHABET) - 1)];
                }
                $values[$name] = $value;
            }

            yield \sprintf('seed %d #%d', $seed, $i) => [$values, (string) array_rand($values)];
        }
    }

    public function testTheTokenCannotBeMatchedWithoutTheSecret(): void
    {
        $form = self::formOf(['apiKey' => 'hunter2']);

        $seen = Fingerprint::fromToken(Fingerprint::ofForm($form, 'test-secret')->toToken());

        self::assertSame(['apiKey'], $seen?->changedPaths(Fingerprint::ofForm($form, 'another-secret')));
    }

    public function testAnEmptyFormHasAFingerprintToo(): void
    {
        $fingerprint = Fingerprint::ofForm(self::formOf([]), 'test-secret');

        self::assertSame([], Fingerprint::fromToken($fingerprint->toToken())?->changedPaths($fingerprint));
    }

    /**
     * @param array<int|string, string> $values
     *
     * @return FormInterface
     */
    private static function formOf(array $values): FormInterface
    {
        $builder = Forms::createFormFactory()->createBuilder(FormType::class, $values);
        foreach (array_keys($values) as $name) {
            $builder->add((string) $name, TextType::class);
        }

        return $builder->getForm();
    }
}
