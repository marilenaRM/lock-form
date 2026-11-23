<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Entity\Product;
use App\Enum\DemoUser;
use App\Service\DemoReset;
use App\Twig\Components\ProductLiveForm;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\UX\LiveComponent\Test\InteractsWithLiveComponents;
use Symfony\UX\LiveComponent\Test\TestLiveComponent;

/**
 * A LiveComponent submits its form on every re-render, then reads the form values back from the rendered view,
 * the concurrency token included. Re-rendering must not make a conflict go away on its own.
 */
final class LiveComponentConflictTest extends WebTestCase
{
    use InteractsWithLiveComponents;

    private KernelBrowser $alice;

    private KernelBrowser $bob;

    protected function setUp(): void
    {
        $this->alice = self::createClient();
        $this->bob = new KernelBrowser($this->alice->getKernel());

        $reset = self::getContainer()->get(DemoReset::class);
        \assert($reset instanceof DemoReset);
        $reset();
    }

    public function testAConflictDetectedWhileTypingIsStillReportedOnSave(): void
    {
        $alice = $this->openLiveForm($this->alice, DemoUser::Alice);

        $bob = $this->openLiveForm($this->bob, DemoUser::Bob);
        $bob->set('product.priceInCents', '99,00');
        $bob->call('save');
        self::assertTrue($bob->response()->isRedirection(), 'Bob saves');

        // Typing re-renders the component, which submits the form against the stored state.
        $alice->set('product.name', 'Clavier mécanique sans fil');

        $this->assertSaveIsRefused($alice);
        self::assertSame(['Clavier mécanique', 9900], $this->storedNameAndPrice(), 'Bob’s price is kept');
    }

    public function testAConflictIsReportedWhenSavingWithoutTypingFirst(): void
    {
        $alice = $this->openLiveForm($this->alice, DemoUser::Alice);

        $bob = $this->openLiveForm($this->bob, DemoUser::Bob);
        $bob->set('product.priceInCents', '99,00');
        $bob->call('save');

        $this->assertSaveIsRefused($alice);
        self::assertSame(['Clavier mécanique', 9900], $this->storedNameAndPrice());
    }

    public function testAcknowledgingTheConflictInTheComponentSavesAliceValues(): void
    {
        $alice = $this->openLiveForm($this->alice, DemoUser::Alice);
        $bob = $this->openLiveForm($this->bob, DemoUser::Bob);
        $bob->set('product.priceInCents', '99,00');
        $bob->call('save');

        $alice->set('product.name', 'Clavier mécanique sans fil');
        $acknowledgement = $alice->render()->crawler()->filter('input[name="product[_concurrency_token_acknowledged]"]');
        self::assertCount(1, $acknowledgement, 'the re-render already offers to acknowledge the conflict');

        $alice->set('product._concurrency_token_acknowledged', $acknowledgement->attr('value'));
        $alice->call('save');

        self::assertTrue($alice->response()->isRedirection());
        self::assertSame(['Clavier mécanique sans fil', 8990], $this->storedNameAndPrice());
    }

    /**
     * TestLiveComponent lets exceptions through, so the 422 of an invalid form arrives as one.
     */
    private function assertSaveIsRefused(TestLiveComponent $component): void
    {
        try {
            $component->call('save');
        } catch (UnprocessableEntityHttpException) {
            $this->addToAssertionCount(1);

            return;
        }

        self::fail(\sprintf('The save went through (HTTP %d) although the record changed meanwhile.', $component->response()->getStatusCode()));
    }

    private function openLiveForm(KernelBrowser $browser, DemoUser $as): TestLiveComponent
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        \assert($entityManager instanceof EntityManagerInterface);

        $component = $this->createLiveComponent(ProductLiveForm::class, [
            'record' => $entityManager->find(Product::class, 1),
            'as' => $as,
        ], $browser);

        // The first render is lazy; force it now, as the person sees the form before anyone else saves.
        $component->render();

        return $component;
    }

    /**
     * @return array{string, int}
     */
    private function storedNameAndPrice(): array
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        \assert($entityManager instanceof EntityManagerInterface);
        $entityManager->clear();
        $product = $entityManager->find(Product::class, 1);
        \assert($product instanceof Product);

        return [$product->getName(), $product->getPriceInCents()];
    }
}
