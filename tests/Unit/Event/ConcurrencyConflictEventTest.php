<?php

declare(strict_types=1);

namespace LockForm\FormConcurrency\Tests\Unit\Event;

use LockForm\FormConcurrency\Event\ConcurrencyConflictEvent;
use LockForm\FormConcurrency\EventListener\ConcurrencyTokenListener;
use LockForm\FormConcurrency\FormConcurrencyExtension;
use LockForm\FormConcurrency\Tests\Fixtures\Article;
use LockForm\FormConcurrency\Tests\Fixtures\ArticleStore;
use LockForm\FormConcurrency\Tests\Fixtures\ArticleType;
use LockForm\FormConcurrency\Tests\Fixtures\Author;
use LockForm\FormConcurrency\Tests\Fixtures\FormRoundTrip;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Form\Forms;

final class ConcurrencyConflictEventTest extends TestCase
{
    private FormFactoryInterface $factory;

    private ArticleStore $store;

    /** @var list<array{ConcurrencyConflictEvent, list<string>}> each event, with the error templates on its form when it was dispatched */
    private array $dispatched = [];

    protected function setUp(): void
    {
        $dispatcher = new EventDispatcher();
        $dispatcher->addListener(ConcurrencyConflictEvent::class, function (ConcurrencyConflictEvent $event): void {
            $this->dispatched[] = [$event, self::errorsOf($event->form)];
        });

        $this->factory = Forms::createFormFactoryBuilder()
            ->addExtension(new FormConcurrencyExtension('test-secret', dispatcher: $dispatcher))
            ->getFormFactory();
        $this->store = new ArticleStore(new Article(title: 'Optimistic locking', price: 12.5, author: new Author('Ada', 'ada@example.com')));
    }

    public function testAConflictIsDispatchedWithTheChangedPathsOnceTheErrorsAreOnTheForm(): void
    {
        $payload = FormRoundTrip::payloadOf($this->openForm());
        $this->store->changeConcurrently(static function (Article $article): void {
            $article->price = 15.0;
            $article->author->email = 'grace@example.com';
        });

        $form = $this->submitOnFreshLoad($payload);

        self::assertCount(1, $this->dispatched);
        [$event, $errors] = $this->dispatched[0];
        self::assertSame($form, $event->form);
        self::assertSame(['author.email', 'price'], $event->changedPaths);
        self::assertContains(ConcurrencyTokenListener::CONFLICT_MESSAGE, $errors);
        self::assertContains(ConcurrencyTokenListener::FIELD_CHANGED_TO_MESSAGE, $errors);
    }

    public function testASubmissionWithoutAUsableTokenIsDispatchedWithoutPaths(): void
    {
        $payload = FormRoundTrip::payloadOf($this->openForm());
        unset($payload['_concurrency_token']);

        $this->submitOnFreshLoad($payload);

        self::assertCount(1, $this->dispatched);
        self::assertSame([], $this->dispatched[0][0]->changedPaths);
        self::assertNull($this->dispatched[0][0]->seenToken);
    }

    public function testNothingIsDispatchedWithoutAConflict(): void
    {
        $this->submitOnFreshLoad(FormRoundTrip::payloadOf($this->openForm()));

        self::assertSame([], $this->dispatched);
    }

    public function testEachRefusedSubmissionOfTheSameEditIsDispatchedWithTheSameSeenTokenAndAnAcknowledgedOneIsNot(): void
    {
        $payload = FormRoundTrip::payloadOf($this->openForm());
        $this->store->changeConcurrently(static function (Article $article): void {
            $article->price = 15.0;
        });
        $conflicting = $this->submitOnFreshLoad($payload);
        $this->submitOnFreshLoad(FormRoundTrip::payloadOf($conflicting));

        $acknowledged = FormRoundTrip::payloadOf($conflicting);
        $acknowledged['_concurrency_token_acknowledged'] = $conflicting->createView()->children['_concurrency_token_acknowledged']->vars['value'];
        self::assertTrue($this->submitOnFreshLoad($acknowledged)->isValid());

        self::assertCount(2, $this->dispatched);
        self::assertSame($payload['_concurrency_token'], $this->dispatched[0][0]->seenToken, 'the token of the form the user opened');
        self::assertSame($this->dispatched[0][0]->seenToken, $this->dispatched[1][0]->seenToken);
    }

    public function testOnlyTheFieldsTheUserDidNotSetToTheirCurrentValueAreDispatched(): void
    {
        $payload = FormRoundTrip::payloadOf($this->openForm());
        $payload['price'] = '15.00';
        $this->store->changeConcurrently(static function (Article $article): void {
            $article->price = 15.0;
            $article->author->email = 'grace@example.com';
        });

        $this->submitOnFreshLoad($payload);

        self::assertCount(1, $this->dispatched);
        self::assertSame(['author.email'], $this->dispatched[0][0]->changedPaths);
    }

    public function testNothingIsDispatchedWhenTheUserSubmitsTheCurrentValues(): void
    {
        $payload = FormRoundTrip::payloadOf($this->openForm());
        $payload['price'] = '15.00';
        $this->store->changeConcurrently(static function (Article $article): void {
            $article->price = 15.0;
        });

        self::assertTrue($this->submitOnFreshLoad($payload)->isValid());
        self::assertSame([], $this->dispatched);
    }

    private function openForm(): FormInterface
    {
        return $this->factory->create(ArticleType::class, $this->store->load());
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function submitOnFreshLoad(array $payload): FormInterface
    {
        $form = $this->openForm();
        $form->submit($payload);

        return $form;
    }

    /**
     * @return list<string>
     */
    private static function errorsOf(FormInterface $form): array
    {
        $templates = [];
        foreach ($form->getErrors(true) as $error) {
            \assert($error instanceof FormError);
            $templates[] = $error->getMessageTemplate();
        }

        return $templates;
    }
}
