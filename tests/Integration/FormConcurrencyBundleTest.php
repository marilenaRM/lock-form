<?php

declare(strict_types=1);

namespace LockForm\FormConcurrency\Tests\Integration;

use LockForm\FormConcurrency\Tests\Fixtures\Article;
use LockForm\FormConcurrency\Tests\Fixtures\ArticleType;
use LockForm\FormConcurrency\Tests\Fixtures\Author;
use LockForm\FormConcurrency\Tests\Fixtures\FormRoundTrip;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormInterface;

final class FormConcurrencyBundleTest extends KernelTestCase
{
    public function testEveryFormOfTheApplicationIsProtectedWithoutAnyChangeToItsType(): void
    {
        $form = $this->formFactory()->create(ArticleType::class, self::article());

        self::assertArrayHasKey('_concurrency_token', $form->createView()->children);
    }

    public function testTheConflictIsReportedInTheUserLocale(): void
    {
        $factory = $this->formFactory();
        $payload = FormRoundTrip::payloadOf($factory->create(ArticleType::class, self::article()));

        $changedMeanwhile = self::article();
        $changedMeanwhile->title = 'Saved by someone else';
        $form = $factory->create(ArticleType::class, $changedMeanwhile);
        $form->submit($payload);

        self::assertFalse($form->isValid());
        self::assertStringStartsWith('Quelqu’un d’autre a enregistré cette fiche', self::messagesOf($form)[0] ?? '');
        self::assertSame(['Modifié par quelqu’un d’autre depuis l’ouverture du formulaire. Valeur actuelle : Saved by someone else'], self::messagesOf($form->get('title')));
    }

    /**
     * @return list<string>
     */
    private static function messagesOf(FormInterface $form): array
    {
        $messages = [];
        foreach ($form->getErrors() as $error) {
            if ($error instanceof FormError) {
                $messages[] = $error->getMessage();
            }
        }

        return $messages;
    }

    private function formFactory(): FormFactoryInterface
    {
        $factory = self::getContainer()->get('test.form.factory');
        \assert($factory instanceof FormFactoryInterface);

        return $factory;
    }

    private static function article(): Article
    {
        return new Article(title: 'Optimistic locking', author: new Author('Ada', 'ada@example.com'));
    }
}
