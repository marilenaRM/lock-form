<?php

declare(strict_types=1);

namespace LockForm\FormConcurrency\Tests\Integration;

use LockForm\FormConcurrency\ConcurrencyConflictError;
use LockForm\FormConcurrency\Event\ConcurrencyConflictEvent;
use LockForm\FormConcurrency\Test\BypassConflictDetector;
use LockForm\FormConcurrency\Tests\Fixtures\Article;
use LockForm\FormConcurrency\Tests\Fixtures\ArticleType;
use LockForm\FormConcurrency\Tests\Fixtures\Author;
use LockForm\FormConcurrency\Tests\Fixtures\FormRoundTrip;
use PHPUnit\Framework\Attributes\TestWith;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

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
        self::assertInstanceOf(ConcurrencyConflictError::class, $form->getErrors()[0]);
    }

    public function testTheConflictDetectorCanBeDecorated(): void
    {
        self::bootKernel(['environment' => 'decorated']);
        $factory = $this->formFactory();
        $payload = FormRoundTrip::payloadOf($factory->create(ArticleType::class, self::article()));

        $titleChanged = self::article();
        $titleChanged->title = 'Saved by someone else';
        $form = $factory->create(ArticleType::class, $titleChanged);
        $form->submit($payload);
        self::assertTrue($form->isValid(), 'the decorator makes the title last write wins');

        $emailChanged = self::article();
        $emailChanged->author->email = 'lovelace@example.com';
        $form = $factory->create(ArticleType::class, $emailChanged);
        $form->submit($payload);
        self::assertFalse($form->isValid(), 'the decorated detector still reports the other fields');
    }

    /**
     * What a functional test does when it posts an array: no token, so a conflict unless the bypass is on.
     *
     * @param array<string, string> $server
     */
    #[TestWith(['test', ['FORM_CONCURRENCY_BYPASS' => '1'], Response::HTTP_UNPROCESSABLE_ENTITY], 'parameter ignored when test_bypass is off')]
    #[TestWith(['test_bypass', [], Response::HTTP_UNPROCESSABLE_ENTITY], 'test_bypass on, request without the parameter')]
    #[TestWith(['test_bypass', ['FORM_CONCURRENCY_BYPASS' => '0'], Response::HTTP_UNPROCESSABLE_ENTITY], 'parameter set to false')]
    #[TestWith(['test_bypass', ['FORM_CONCURRENCY_BYPASS' => '1'], Response::HTTP_OK], 'test_bypass on, request with the parameter')]
    public function testAFunctionalTestCanPostARawArrayWithTheBypass(string $environment, array $server, int $expected): void
    {
        $kernel = self::bootKernel(['environment' => $environment]);

        $response = $kernel->handle(self::rawPost($server));

        self::assertSame($expected, $response->getStatusCode(), (string) $response->getContent());
    }

    public function testTheBypassOnlyCoversTheRequestThatCarriesIt(): void
    {
        $kernel = self::bootKernel(['environment' => 'test_bypass']);

        self::assertSame(Response::HTTP_OK, $kernel->handle(self::rawPost([BypassConflictDetector::SERVER_PARAMETER => '1']))->getStatusCode());
        self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $kernel->handle(self::rawPost([]))->getStatusCode(), 'the same kernel, the next request');
    }

    /**
     * @param array<string, string> $server
     */
    private static function rawPost(array $server): Request
    {
        return Request::create('/article/edit', 'POST', ['article' => [
            'title' => 'Posted as an array',
            'author' => ['name' => 'Ada', 'email' => 'ada@example.com'],
        ]], server: $server);
    }

    #[TestWith(['test', 0])]
    #[TestWith(['conflict_event', 1])]
    public function testTheConflictEventIsOnlyDispatchedWhenTurnedOn(string $environment, int $expected): void
    {
        self::bootKernel(['environment' => $environment]);
        $dispatcher = self::getContainer()->get('test.event_dispatcher');
        \assert($dispatcher instanceof EventDispatcherInterface);
        $dispatched = 0;
        $dispatcher->addListener(ConcurrencyConflictEvent::class, static function () use (&$dispatched): void {
            ++$dispatched;
        });

        $payload = FormRoundTrip::payloadOf($this->formFactory()->create(ArticleType::class, self::article()));
        unset($payload['_concurrency_token']);
        $form = $this->formFactory()->create(ArticleType::class, self::article());
        $form->submit($payload);

        self::assertFalse($form->isValid());
        self::assertSame($expected, $dispatched);
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
