<?php

declare(strict_types=1);

namespace App\Tests\Application;

use App\Entity\Product;
use App\Service\DemoReset;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class LanguageTest extends WebTestCase
{
    private KernelBrowser $browser;

    protected function setUp(): void
    {
        // No language preference at all: BrowserKit would otherwise send an English Accept-Language header.
        $this->browser = self::createClient(server: ['HTTP_ACCEPT_LANGUAGE' => '']);

        $reset = self::getContainer()->get(DemoReset::class);
        \assert($reset instanceof DemoReset);
        $reset();
    }

    public function testTheDemoIsInFrenchByDefault(): void
    {
        $this->browser->request('GET', '/');

        self::assertSelectorTextContains('h1', 'Deux personnes, une même fiche');
        self::assertSelectorExists('html[lang="fr"]');
    }

    public function testTheBrowserLanguageIsFollowedWhenNothingWasChosen(): void
    {
        $this->browser->request('GET', '/', server: ['HTTP_ACCEPT_LANGUAGE' => 'en-GB,en;q=0.9']);

        self::assertSelectorTextContains('h1', 'Two people, one record');
    }

    public function testTheChosenLanguageIsRememberedByTheEditorsAndTheStartingProductFollows(): void
    {
        $this->browser->request('GET', '/?lang=en');
        self::assertSelectorTextContains('h1', 'Two people, one record');
        self::assertSame('Mechanical keyboard', $this->storedProduct()->getName());

        // The editors are loaded in iframes without the parameter: the session carries the choice.
        $crawler = $this->browser->request('GET', '/products/1/edit?as=alice');
        self::assertCount(1, $crawler->selectButton('Save'));
        self::assertInputValueSame('product[name]', 'Mechanical keyboard');

        $this->browser->request('GET', '/?lang=fr');
        self::assertSelectorTextContains('h1', 'Deux personnes, une même fiche');
        self::assertSame('Clavier mécanique', $this->storedProduct()->getName());
    }

    public function testOnceSomeoneSavedSwitchingTheLanguageKeepsTheProductAsItIs(): void
    {
        $form = $this->browser->request('GET', '/products/1/stock?as=bob')->selectButton('Enregistrer')->form();
        $form['stock[stock]'] = '3';
        $this->browser->submit($form);

        $this->browser->request('GET', '/?lang=en');

        self::assertSame('Clavier mécanique', $this->storedProduct()->getName());
        self::assertSame(3, $this->storedProduct()->getStock());
    }

    private function storedProduct(): Product
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        \assert($entityManager instanceof EntityManagerInterface);
        $entityManager->clear();
        $product = $entityManager->find(Product::class, 1);
        \assert($product instanceof Product);

        return $product;
    }
}
