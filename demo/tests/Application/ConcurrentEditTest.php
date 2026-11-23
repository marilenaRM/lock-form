<?php

declare(strict_types=1);

namespace App\Tests\Application;

use App\Entity\Product;
use App\Service\DemoReset;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Field\ChoiceFormField;
use Symfony\Component\DomCrawler\Form;

/**
 * Alice and Bob are two browsers with their own cookies and session, sharing the application and its database.
 */
final class ConcurrentEditTest extends WebTestCase
{
    private KernelBrowser $alice;

    private KernelBrowser $bob;

    protected function setUp(): void
    {
        // Both speak French; BrowserKit would otherwise send an English Accept-Language header.
        $this->alice = self::createClient(server: ['HTTP_ACCEPT_LANGUAGE' => 'fr']);
        $this->bob = new KernelBrowser($this->alice->getKernel(), ['HTTP_ACCEPT_LANGUAGE' => 'fr']);

        $reset = self::getContainer()->get(DemoReset::class);
        \assert($reset instanceof DemoReset);
        $reset();
    }

    public function testWithTheProtectionAliceIsToldWhatBobChangedAndKeepsHerInput(): void
    {
        $aliceForm = $this->openProductForm($this->alice, 'alice', protection: true);

        $bobForm = $this->openProductForm($this->bob, 'bob', protection: true);
        $bobForm['product[priceInCents]'] = '99,00';
        $this->bob->submit($bobForm);
        self::assertSaved($this->bob);

        $aliceForm['product[name]'] = 'Clavier mécanique sans fil';
        $this->alice->submit($aliceForm);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('form', 'Quelqu’un d’autre a enregistré cette fiche');
        self::assertSelectorTextContains('form', 'Valeur actuelle : 99,00');
        self::assertInputValueSame('product[name]', 'Clavier mécanique sans fil');
        self::assertSame(['Clavier mécanique', '99.00'], $this->storedNameAndPrice());
    }

    public function testResubmittingAfterTheConflictSavesAliceValuesKnowingly(): void
    {
        $aliceForm = $this->openProductForm($this->alice, 'alice', protection: true);
        $bobForm = $this->openProductForm($this->bob, 'bob', protection: true);
        $bobForm['product[priceInCents]'] = '99,00';
        $this->bob->submit($bobForm);

        $aliceForm['product[name]'] = 'Clavier mécanique sans fil';
        $aliceForm['product[priceInCents]'] = '95,00';
        $this->alice->submit($aliceForm);
        self::assertResponseStatusCodeSame(422);

        // Submitting the same form again is not enough: the conflict must be acknowledged.
        $retry = $this->alice->getCrawler()->selectButton('Enregistrer')->form();
        $this->alice->submit($retry);
        self::assertResponseStatusCodeSame(422);

        $acknowledged = $this->alice->getCrawler()->selectButton('Enregistrer')->form();
        $checkbox = $acknowledged['product[_concurrency_token_acknowledged]'];
        \assert($checkbox instanceof ChoiceFormField);
        $checkbox->tick();
        $this->alice->submit($acknowledged);

        self::assertResponseRedirects();
        self::assertSame(['Clavier mécanique sans fil', '95.00'], $this->storedNameAndPrice());
    }

    public function testAliceCanSaveWithoutAcknowledgingOnceSheTakesBobPrice(): void
    {
        $aliceForm = $this->openProductForm($this->alice, 'alice', protection: true);
        $bobForm = $this->openProductForm($this->bob, 'bob', protection: true);
        $bobForm['product[priceInCents]'] = '99,00';
        $this->bob->submit($bobForm);

        $aliceForm['product[name]'] = 'Clavier mécanique sans fil';
        $aliceForm['product[priceInCents]'] = '95,00';
        $this->alice->submit($aliceForm);
        self::assertResponseStatusCodeSame(422);

        // She reads Bob's price in the error and types it in: her save overwrites nothing, no box to tick.
        $retry = $this->alice->getCrawler()->selectButton('Enregistrer')->form();
        $retry['product[priceInCents]'] = '99,00';
        $this->alice->submit($retry);

        self::assertResponseRedirects();
        self::assertSame(['Clavier mécanique sans fil', '99.00'], $this->storedNameAndPrice());
    }

    public function testWithoutTheProtectionAliceSilentlyPutsTheOldPriceBack(): void
    {
        $aliceForm = $this->openProductForm($this->alice, 'alice', protection: false);

        $bobForm = $this->openProductForm($this->bob, 'bob', protection: false);
        $bobForm['product[priceInCents]'] = '99,00';
        $this->bob->submit($bobForm);

        $aliceForm['product[name]'] = 'Clavier mécanique sans fil';
        $this->alice->submit($aliceForm);

        self::assertResponseRedirects();
        self::assertSame(['Clavier mécanique sans fil', '89.90'], $this->storedNameAndPrice(), 'Bob’s price is lost');

        $this->alice->request('GET', '/products/1/history');
        self::assertSelectorTextContains('tbody tr:first-child', 'Alice');
        self::assertSelectorTextContains('tbody tr:first-child', '99.00 → 89.90');
    }

    public function testAStockChangeFromAnotherFormIsNotAConflict(): void
    {
        $aliceForm = $this->openProductForm($this->alice, 'alice', protection: true);

        $stockForm = $this->bob->request('GET', '/products/1/stock?as=bob')->selectButton('Enregistrer')->form();
        $stockForm['stock[stock]'] = '3';
        $this->bob->submit($stockForm);
        self::assertSaved($this->bob);

        $aliceForm['product[name]'] = 'Clavier mécanique sans fil';
        $this->alice->submit($aliceForm);

        self::assertResponseRedirects();
    }

    public function testASaveThatChangesNothingIsNotAConflict(): void
    {
        $aliceForm = $this->openProductForm($this->alice, 'alice', protection: true);

        $this->bob->submit($this->openProductForm($this->bob, 'bob', protection: true));
        self::assertSaved($this->bob);

        $aliceForm['product[name]'] = 'Clavier mécanique sans fil';
        $this->alice->submit($aliceForm);

        self::assertResponseRedirects();
    }

    public function testATagAddedByBobIsReportedOnTheTagList(): void
    {
        $aliceForm = $this->openProductForm($this->alice, 'alice', protection: true);

        $bobForm = $this->openProductForm($this->bob, 'bob', protection: true);
        $values = $bobForm->getPhpValues();
        \assert(\is_array($values['product']) && \is_array($values['product']['tags']));
        $values['product']['tags'][] = 'sans-fil';
        $this->bob->request('POST', $bobForm->getUri(), $values);
        self::assertSaved($this->bob);

        $this->alice->submit($aliceForm);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('form', 'Tags : des éléments ont été ajoutés ou supprimés par quelqu’un d’autre');
    }

    public function testTheGetFilterWorksFromAPlainLinkWithoutAnyToken(): void
    {
        $this->alice->request('GET', '/products?filter[status]=active');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('tbody', 'Clavier mécanique');

        $this->alice->request('GET', '/products?filter[status]=archived');
        self::assertSelectorTextContains('tbody', 'Aucun produit ne correspond.');
    }

    #[DataProvider('pages')]
    public function testEveryPageOfTheDemoResponds(string $url): void
    {
        $this->alice->request('GET', $url);

        self::assertResponseIsSuccessful();
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function pages(): iterable
    {
        yield 'demo, protection on' => ['/'];
        yield 'demo, protection off' => ['/?protection=0'];
        yield 'product list' => ['/products'];
        yield 'product form as Alice' => ['/products/1/edit?as=alice'];
        yield 'product form as Bob, unprotected' => ['/products/1/edit?as=bob&protection=0'];
        yield 'stock form' => ['/products/1/stock?as=bob'];
        yield 'save history' => ['/products/1/history'];
        yield 'demo with LiveComponent forms' => ['/?live=1'];
        yield 'LiveComponent form as Bob' => ['/products/1/live?as=bob'];
        yield 'LiveComponent form, unprotected' => ['/products/1/live?as=alice&protection=0'];
    }

    public function testTheResetButtonPutsTheDemoBackToItsStartingPoint(): void
    {
        $bobForm = $this->openProductForm($this->bob, 'bob', protection: true);
        $bobForm['product[priceInCents]'] = '99,00';
        $this->bob->submit($bobForm);

        $this->alice->request('GET', '/');
        $this->alice->submit($this->alice->getCrawler()->selectButton('Réinitialiser la démo')->form());

        self::assertResponseRedirects('/?protection=1&live=0');
        self::assertSame(['Clavier mécanique', '89.90'], $this->storedNameAndPrice());
    }

    /**
     * The BrowserKit assertions only look at the client created by createClient(), which is Alice.
     */
    private static function assertSaved(KernelBrowser $browser): void
    {
        self::assertTrue($browser->getResponse()->isRedirection(), \sprintf('Expected a redirect after saving, got %d.', $browser->getResponse()->getStatusCode()));
    }

    private function openProductForm(KernelBrowser $browser, string $as, bool $protection): Form
    {
        $crawler = $browser->request('GET', \sprintf('/products/1/edit?as=%s&protection=%d', $as, $protection ? 1 : 0));
        self::assertTrue($browser->getResponse()->isSuccessful());

        return $crawler->selectButton('Enregistrer')->form();
    }

    /**
     * @return array{string, string}
     */
    private function storedNameAndPrice(): array
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        \assert($entityManager instanceof EntityManagerInterface);
        $entityManager->clear();
        $product = $entityManager->find(Product::class, 1);
        \assert($product instanceof Product);

        return [$product->getName(), \sprintf('%d.%02d', intdiv($product->getPriceInCents(), 100), $product->getPriceInCents() % 100)];
    }
}
