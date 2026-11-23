<?php

declare(strict_types=1);

namespace LockForm\FormConcurrency\Tests\Unit\Type;

use LockForm\FormConcurrency\EventListener\ConcurrencyTokenListener;
use LockForm\FormConcurrency\FormConcurrencyExtension;
use LockForm\FormConcurrency\Tests\Fixtures\Article;
use LockForm\FormConcurrency\Tests\Fixtures\ArticleStore;
use LockForm\FormConcurrency\Tests\Fixtures\ArticleType;
use LockForm\FormConcurrency\Tests\Fixtures\Author;
use LockForm\FormConcurrency\Tests\Fixtures\Category;
use LockForm\FormConcurrency\Tests\Fixtures\FormRoundTrip;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\FormType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\Form\FormInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\Forms;

final class ConcurrencyTypeExtensionTest extends TestCase
{
    private FormFactoryInterface $factory;

    private ArticleStore $store;

    protected function setUp(): void
    {
        $this->factory = Forms::createFormFactoryBuilder()
            ->addExtension(new FormConcurrencyExtension('test-secret'))
            ->getFormFactory();
        $this->store = new ArticleStore(new Article(
            title: 'Optimistic locking',
            publishedAt: new \DateTimeImmutable('2026-11-20 00:00:00'),
            price: 12.5,
            category: new Category(1, 'News'),
            author: new Author('Ada', 'ada@example.com'),
            tags: ['symfony', 'forms'],
            reviewedBy: 'Grace',
            internalNotes: 'draft',
            accessCode: 'swordfish',
        ));
    }

    public function testItAddsTheTokenToTheRootFormOnly(): void
    {
        $view = $this->openForm()->createView();

        self::assertArrayHasKey('_concurrency_token', $view->children);
        self::assertArrayNotHasKey('_concurrency_token', $view->children['author']->children);
    }

    public function testASubmissionOnAnUnchangedRecordIsAccepted(): void
    {
        $payload = FormRoundTrip::payloadOf($this->openForm());
        $payload['title'] = 'Optimistic locking, revisited';

        $form = $this->submitOnFreshLoad($payload);

        self::assertTrue($form->isValid());
        self::assertSame('Optimistic locking, revisited', $this->article($form)->title);
    }

    public function testAConcurrentChangeToAFieldOfTheFormIsReportedOnTheFormAndOnThatField(): void
    {
        $payload = FormRoundTrip::payloadOf($this->openForm());
        $payload['title'] = 'Mine';
        $this->store->changeConcurrently(static function (Article $article): void {
            $article->price = 15.0;
        });

        $form = $this->submitOnFreshLoad($payload);

        self::assertFalse($form->isValid());
        self::assertSame([ConcurrencyTokenListener::CONFLICT_MESSAGE], $this->messagesOf($form));
        self::assertSame([ConcurrencyTokenListener::FIELD_CHANGED_TO_MESSAGE], $this->messagesOf($form->get('price')));
        self::assertSame(['15.00'], $this->currentValuesOf($form->get('price')));
        self::assertSame([], $this->messagesOf($form->get('title')));
    }

    public function testAConcurrentChangeInsideAnEmbeddedFormIsReportedOnTheNestedField(): void
    {
        $payload = FormRoundTrip::payloadOf($this->openForm());
        $this->store->changeConcurrently(static function (Article $article): void {
            $article->author->email = 'lovelace@example.com';
        });

        $form = $this->submitOnFreshLoad($payload);

        self::assertFalse($form->isValid());
        self::assertSame(['lovelace@example.com'], $this->currentValuesOf($form->get('author')->get('email')));
    }

    public function testAFieldEmptiedConcurrentlyIsReportedAsCleared(): void
    {
        $payload = FormRoundTrip::payloadOf($this->openForm());
        $this->store->changeConcurrently(static function (Article $article): void {
            $article->author->name = '';
        });

        $form = $this->submitOnFreshLoad($payload);

        self::assertSame([ConcurrencyTokenListener::FIELD_CLEARED_MESSAGE], $this->messagesOf($form->get('author')->get('name')));
    }

    public function testACheckboxToggledConcurrentlyIsReportedWithoutAValue(): void
    {
        $payload = FormRoundTrip::payloadOf($this->openForm());
        $this->store->changeConcurrently(static function (Article $article): void {
            $article->featured = true;
        });

        $form = $this->submitOnFreshLoad($payload);

        self::assertSame([ConcurrencyTokenListener::FIELD_CHANGED_MESSAGE], $this->messagesOf($form->get('featured')));
    }

    public function testTheCurrentValueOfAFieldThatNeverRendersItIsNotDisclosed(): void
    {
        $payload = FormRoundTrip::payloadOf($this->openForm());
        $this->store->changeConcurrently(static function (Article $article): void {
            $article->accessCode = 'hunter2';
        });

        $form = $this->submitOnFreshLoad($payload);

        self::assertSame([ConcurrencyTokenListener::FIELD_CHANGED_MESSAGE], $this->messagesOf($form->get('accessCode')));
        self::assertStringNotContainsString('hunter2', (string) $form->getErrors(true));
    }

    public function testAConcurrentChangeToARelatedObjectIsReported(): void
    {
        $payload = FormRoundTrip::payloadOf($this->openForm());
        $this->store->changeConcurrently(static function (Article $article): void {
            $article->category = new Category(2, 'Tutorial');
        });

        $form = $this->submitOnFreshLoad($payload);

        self::assertSame(['Tutorial'], $this->currentValuesOf($form->get('category')), 'the label is shown, not the id');
    }

    /**
     * @param \Closure(Article): void $change
     */
    #[DataProvider('changesThatCannotBeOverwritten')]
    public function testAConcurrentChangeTheUserCannotOverwriteIsNotAConflict(\Closure $change): void
    {
        $payload = FormRoundTrip::payloadOf($this->openForm());
        $this->store->changeConcurrently($change);

        self::assertTrue($this->submitOnFreshLoad($payload)->isValid());
    }

    /**
     * @return iterable<string, array{\Closure(Article): void}>
     */
    public static function changesThatCannotBeOverwritten(): iterable
    {
        yield 'property absent from the form' => [static function (Article $article): void {
            $article->internalNotes = 'published';
        }];
        yield 'disabled field' => [static function (Article $article): void {
            $article->reviewedBy = 'Linus';
        }];
        yield 'same value saved again' => [static function (Article $article): void {
            $article->price = 12.50;
            $article->publishedAt = new \DateTimeImmutable('2026-11-20 00:00:00');
            $article->category = new Category(1, 'News');
        }];
    }

    public function testEntriesAddedOrRemovedByTheUserInACollectionAreNotAConflict(): void
    {
        $payload = FormRoundTrip::payloadOf($this->openForm());
        $payload['tags'] = ['1' => 'forms', '2' => 'concurrency', '3' => 'php'];

        $form = $this->submitOnFreshLoad($payload);

        self::assertTrue($form->isValid());
        self::assertSame(['forms', 'concurrency', 'php'], array_values($this->article($form)->tags));
    }

    public function testAnEntryAddedConcurrentlyToACollectionIsAConflict(): void
    {
        $payload = FormRoundTrip::payloadOf($this->openForm());
        $this->store->changeConcurrently(static function (Article $article): void {
            $article->tags[] = 'doctrine';
        });

        $form = $this->submitOnFreshLoad($payload);

        self::assertFalse($form->isValid());
        $errorsByOrigin = [];
        foreach ($form->getErrors(true) as $error) {
            \assert($error instanceof FormError);
            $errorsByOrigin[] = [$error->getOrigin()?->getName(), $error->getMessageTemplate()];
        }

        // The new entry is not in the submitted collection, so the collection carries the error (and bubbles it up, as CollectionType does by default).
        self::assertSame([
            ['article', ConcurrencyTokenListener::CONFLICT_MESSAGE],
            ['tags', ConcurrencyTokenListener::ENTRIES_CHANGED_MESSAGE],
        ], $errorsByOrigin);
        self::assertSame('Tags', $this->collectionErrorOf($form)->getMessageParameters()['{{ field }}'] ?? null);
    }

    public function testResubmittingTheFormRenderedAfterAConflictKeepsTheUserValues(): void
    {
        $payload = FormRoundTrip::payloadOf($this->openForm());
        $payload['price'] = '20.00';
        $this->store->changeConcurrently(static function (Article $article): void {
            $article->price = 15.0;
        });

        $conflicting = $this->submitOnFreshLoad($payload);
        self::assertFalse($conflicting->isValid());

        $payload['_concurrency_token'] = FormRoundTrip::tokenOf($conflicting);
        $form = $this->submitOnFreshLoad($payload);

        self::assertTrue($form->isValid());
        self::assertSame(20.0, $this->article($form)->price);
    }

    #[DataProvider('unusableTokens')]
    public function testASubmissionWithoutAUsableTokenIsRejected(?string $token): void
    {
        $payload = FormRoundTrip::payloadOf($this->openForm());
        unset($payload['_concurrency_token']);
        if (null !== $token) {
            $payload['_concurrency_token'] = $token;
        }

        $form = $this->submitOnFreshLoad($payload);

        self::assertFalse($form->isValid());
        self::assertSame([ConcurrencyTokenListener::CONFLICT_MESSAGE], $this->messagesOf($form));
    }

    /**
     * @return iterable<string, array{?string}>
     */
    public static function unusableTokens(): iterable
    {
        yield 'missing' => [null];
        yield 'empty' => [''];
        yield 'not base64' => ['%%%'];
        yield 'not json' => [base64_encode('not json')];
        yield 'json without string hashes' => [base64_encode('{"title":1}')];
    }

    public function testTheTokenFieldIsNotReportedAsExtraData(): void
    {
        $form = $this->submitOnFreshLoad(FormRoundTrip::payloadOf($this->openForm()));

        self::assertSame([], $form->getExtraData());
    }

    public function testTheProtectionCanBeTurnedOffForOneForm(): void
    {
        $form = $this->factory->create(ArticleType::class, $this->store->load(), ['concurrency_protection' => false]);
        self::assertArrayNotHasKey('_concurrency_token', $form->createView()->children);

        $form = $this->factory->create(ArticleType::class, $this->store->load(), ['concurrency_protection' => false]);
        $form->submit(['title' => 'No token at all'], false);

        self::assertTrue($form->isValid());
    }

    public function testAGetFormIsNotProtected(): void
    {
        $filters = fn (): FormInterface => $this->factory->createBuilder(FormType::class, ['status' => 'open'], ['method' => 'GET'])
            ->add('status', TextType::class)
            ->getForm();
        self::assertArrayNotHasKey('_concurrency_token', $filters()->createView()->children);

        $form = $filters();
        $form->submit(['status' => 'closed']);

        self::assertTrue($form->isValid());
    }

    public function testAFieldRebuiltFromTheSubmittedValuesIsNotAConflict(): void
    {
        $payload = FormRoundTrip::payloadOf($this->openDependentForm());
        $payload['category'] = '2';
        $payload['subcategory'] = 'symfony-ux';

        $form = $this->openDependentForm();
        $form->submit($payload);

        self::assertTrue($form->isValid(), (string) $form->getErrors(true));
    }

    /**
     * @return FormInterface
     */
    private function openForm(): FormInterface
    {
        return $this->factory->create(ArticleType::class, $this->store->load());
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return FormInterface
     */
    private function submitOnFreshLoad(array $payload): FormInterface
    {
        $form = $this->openForm();
        $form->submit($payload);

        return $form;
    }

    /**
     * The "dependent field" pattern of the Symfony documentation: the choices of a field depend on another
     * field, so the field is rebuilt from the submitted values in a PRE_SUBMIT listener of the form.
     *
     * @return FormInterface
     */
    private function openDependentForm(): FormInterface
    {
        $subcategories = [1 => ['politics', 'economy'], 2 => ['symfony-ux', 'doctrine']];
        $addSubcategory = static function (FormInterface $form, ?string $category) use ($subcategories): void {
            $choices = $subcategories[(int) $category] ?? [];
            $form->add('subcategory', ChoiceType::class, ['choices' => array_combine($choices, $choices)]);
        };

        return $this->factory->createBuilder(FormType::class, ['category' => '1', 'subcategory' => 'economy'])
            ->add('category', ChoiceType::class, ['choices' => ['News' => '1', 'Tutorial' => '2']])
            ->add('note', TextType::class, ['required' => false])
            ->addEventListener(FormEvents::PRE_SET_DATA, static function (FormEvent $event) use ($addSubcategory): void {
                $data = $event->getData();
                \assert(\is_array($data) && \is_string($data['category']));
                $addSubcategory($event->getForm(), $data['category']);
            })
            ->addEventListener(FormEvents::PRE_SUBMIT, static function (FormEvent $event) use ($addSubcategory): void {
                $data = $event->getData();
                \assert(\is_array($data) && \is_string($data['category']));
                $addSubcategory($event->getForm(), $data['category']);
            })
            ->getForm();
    }

    /**
     * @param FormInterface $form
     */
    private function article(FormInterface $form): Article
    {
        $article = $form->getData();
        \assert($article instanceof Article);

        return $article;
    }

    private function collectionErrorOf(FormInterface $form): FormError
    {
        foreach ($form->getErrors(true) as $error) {
            if ($error instanceof FormError && ConcurrencyTokenListener::ENTRIES_CHANGED_MESSAGE === $error->getMessageTemplate()) {
                return $error;
            }
        }

        self::fail('No error was reported on the collection.');
    }

    /**
     * @return list<string>
     */
    private function currentValuesOf(FormInterface $field): array
    {
        $values = [];
        foreach ($field->getErrors() as $error) {
            \assert($error instanceof FormError);
            $value = $error->getMessageParameters()['{{ value }}'] ?? null;
            if (\is_string($value)) {
                $values[] = $value;
            }
        }

        return $values;
    }

    /**
     * @param FormInterface $form
     *
     * @return list<string>
     */
    private function messagesOf(FormInterface $form): array
    {
        $messages = [];
        foreach ($form->getErrors() as $error) {
            \assert($error instanceof FormError);
            $messages[] = $error->getMessageTemplate();
        }

        return $messages;
    }
}
