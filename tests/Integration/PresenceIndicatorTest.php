<?php

declare(strict_types=1);

namespace LockForm\FormConcurrency\Tests\Integration;

use LockForm\FormConcurrency\Presence\PresenceController;
use LockForm\FormConcurrency\Tests\Fixtures\Article;
use LockForm\FormConcurrency\Tests\Fixtures\ArticleType;
use LockForm\FormConcurrency\Tests\Fixtures\Author;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Form\Extension\Core\Type\FormType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;

final class PresenceIndicatorTest extends KernelTestCase
{
    private const string EDIT_PAGE = '/articles/42/edit';

    public function testItIsOffByDefault(): void
    {
        self::bootKernel();
        $this->onPage(self::EDIT_PAGE, 'Alice');

        $attr = $this->attributesOf();

        self::assertArrayNotHasKey('data-concurrency-presence', $attr);
        self::assertFalse(self::getContainer()->has(PresenceController::class));
    }

    public function testTheFormOptionsExistEvenWhenItIsOff(): void
    {
        self::bootKernel();
        $this->onPage(self::EDIT_PAGE, 'Alice');

        $attr = $this->attributesOf(['concurrency_presence' => true, 'concurrency_presence_key' => 'article-42']);

        self::assertArrayNotHasKey('data-concurrency-presence', $attr);
    }

    public function testWhenTurnedOnTheFormTagCarriesTheHeartbeat(): void
    {
        self::bootKernel(['environment' => 'presence']);
        $this->onPage(self::EDIT_PAGE, 'Alice');

        $attr = $this->attributesOf(['attr' => ['class' => 'article-form']]);

        self::assertIsString($attr['data-concurrency-presence'] ?? null);
        self::assertSame('/_form_concurrency/presence', $attr['data-concurrency-presence-url'] ?? null);
        self::assertSame('15', $attr['data-concurrency-presence-interval'] ?? null);
        self::assertSame('article-form', $attr['class'] ?? null, 'The attributes of the form itself are kept.');
    }

    public function testItCanBeTurnedOffForOneForm(): void
    {
        self::bootKernel(['environment' => 'presence']);
        $this->onPage(self::EDIT_PAGE, 'Alice');

        self::assertArrayNotHasKey('data-concurrency-presence', $this->attributesOf(['concurrency_presence' => false]));
    }

    public function testAGetFormHasNoIndicator(): void
    {
        self::bootKernel(['environment' => 'presence']);
        $this->onPage('/articles', 'Alice');

        $form = $this->formFactory()->createBuilder(FormType::class, null, ['method' => 'GET'])->add('q', TextType::class)->getForm();

        $attr = $form->createView()->vars['attr'];

        self::assertIsArray($attr);
        self::assertArrayNotHasKey('data-concurrency-presence', $attr);
    }

    public function testAnAnonymousVisitorIsNotTracked(): void
    {
        self::bootKernel(['environment' => 'presence']);
        $this->onPage(self::EDIT_PAGE, null);

        self::assertArrayNotHasKey('data-concurrency-presence', $this->attributesOf());
    }

    public function testTwoPeopleOnTheSameRecordAreToldAboutEachOther(): void
    {
        self::bootKernel(['environment' => 'presence']);
        $alice = $this->tokenFor(self::EDIT_PAGE, 'Alice');
        $bob = $this->tokenFor(self::EDIT_PAGE, 'Bob');

        self::assertSame(['others' => [], 'message' => null], $this->beat($alice, 'tab-alice'));
        self::assertSame(['others' => ['Alice'], 'message' => 'Alice modifie aussi cette fiche.'], $this->beat($bob, 'tab-bob'));
        self::assertSame(['others' => ['Bob'], 'message' => 'Bob modifie aussi cette fiche.'], $this->beat($alice, 'tab-alice'));

        $carol = $this->tokenFor(self::EDIT_PAGE, 'Carol');
        self::assertSame('Alice, Bob modifient aussi cette fiche.', $this->beat($carol, 'tab-carol')['message'] ?? null);
    }

    public function testTheKeyOptionPutsTwoPagesOfTheSameRecordTogether(): void
    {
        self::bootKernel(['environment' => 'presence']);
        $classic = $this->tokenFor(self::EDIT_PAGE, 'Alice', 'article-42');
        $live = $this->tokenFor('/articles/42/live', 'Bob', 'article-42');
        $otherPage = $this->tokenFor('/articles/42/live', 'Carol');

        $this->beat($classic, 'tab-alice');

        self::assertSame(['Alice'], $this->beat($live, 'tab-bob')['others'] ?? null);
        self::assertSame([], $this->beat($otherPage, 'tab-carol')['others'] ?? null);
    }

    public function testLeavingTellsTheOthersAtOnce(): void
    {
        self::bootKernel(['environment' => 'presence']);
        $alice = $this->tokenFor(self::EDIT_PAGE, 'Alice');
        $bob = $this->tokenFor(self::EDIT_PAGE, 'Bob');
        $this->beat($alice, 'tab-alice');

        $response = $this->post(['token' => $alice, 'tab' => 'tab-alice', 'leave' => '1']);

        self::assertSame(Response::HTTP_NO_CONTENT, $response->getStatusCode());
        self::assertSame([], $this->beat($bob, 'tab-bob')['others'] ?? null);
    }

    public function testAForgedTokenOrTabIsRefused(): void
    {
        self::bootKernel(['environment' => 'presence']);
        $alice = $this->tokenFor(self::EDIT_PAGE, 'Alice');

        self::assertSame(Response::HTTP_BAD_REQUEST, $this->post(['token' => 'forged.token', 'tab' => 'tab-alice'])->getStatusCode());
        self::assertSame(Response::HTTP_BAD_REQUEST, $this->post(['token' => $alice, 'tab' => '../../etc'])->getStatusCode());
        self::assertSame(Response::HTTP_BAD_REQUEST, $this->post(['tab' => 'tab-alice'])->getStatusCode());
    }

    public function testTheHeartbeatIsNotCached(): void
    {
        self::bootKernel(['environment' => 'presence']);

        $response = $this->post(['token' => $this->tokenFor(self::EDIT_PAGE, 'Alice'), 'tab' => 'tab-alice']);

        self::assertTrue($response->headers->hasCacheControlDirective('no-store'));
        self::assertTrue($response->headers->hasCacheControlDirective('private'));
    }

    /**
     * @param array<string, mixed> $options
     *
     * @return array<array-key, mixed>
     */
    private function attributesOf(array $options = []): array
    {
        $attr = $this->formFactory()->create(ArticleType::class, self::article(), $options)->createView()->vars['attr'];

        return \is_array($attr) ? $attr : [];
    }

    private function tokenFor(string $page, string $viewer, ?string $key = null): string
    {
        $this->onPage($page, $viewer);
        $token = $this->attributesOf(['concurrency_presence_key' => $key])['data-concurrency-presence'] ?? null;
        self::assertIsString($token);
        $this->requestStack()->pop();

        return $token;
    }

    /**
     * @return array<array-key, mixed>
     */
    private function beat(string $token, string $tab): array
    {
        $response = $this->post(['token' => $token, 'tab' => $tab]);
        self::assertSame(Response::HTTP_OK, $response->getStatusCode(), (string) $response->getContent());

        $data = json_decode((string) $response->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($data);

        return $data;
    }

    /**
     * @param array<string, string> $parameters
     */
    private function post(array $parameters): Response
    {
        $kernel = self::$kernel;
        self::assertNotNull($kernel);

        return $kernel->handle(Request::create('/_form_concurrency/presence', 'POST', $parameters));
    }

    private function onPage(string $path, ?string $viewer): void
    {
        $request = Request::create($path);
        if (null !== $viewer) {
            $request->headers->set('X-Viewer', $viewer);
        }
        $this->requestStack()->push($request);
    }

    private function requestStack(): RequestStack
    {
        $stack = self::getContainer()->get('request_stack');
        \assert($stack instanceof RequestStack);

        return $stack;
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
