<?php

declare(strict_types=1);

namespace LockForm\FormConcurrency\Tests\Exploration;

use LockForm\FormConcurrency\FormConcurrencyExtension;
use LockForm\FormConcurrency\Tests\Fixtures\Article;
use LockForm\FormConcurrency\Tests\Fixtures\ArticleStore;
use LockForm\FormConcurrency\Tests\Fixtures\ArticleType;
use LockForm\FormConcurrency\Tests\Fixtures\Author;
use LockForm\FormConcurrency\Tests\Fixtures\FormRoundTrip;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Form\Forms;

/**
 * Accepted limit: the check happens in PHP, before the write. Two submissions handled at the same
 * instant both pass, because neither has saved yet when the other one checks. Only the storage can
 * make check-and-write atomic (e.g. Doctrine's #[ORM\Version] turns it into UPDATE ... WHERE version = ?).
 */
final class CheckThenWriteRaceTest extends TestCase
{
    public function testTwoSubmissionsCheckedBeforeEitherIsSavedBothPass(): void
    {
        $factory = Forms::createFormFactoryBuilder()->addExtension(new FormConcurrencyExtension('test-secret'))->getFormFactory();
        $store = new ArticleStore(new Article(title: 'Optimistic locking', author: new Author('Ada', 'ada@example.com')));

        $alice = FormRoundTrip::payloadOf($factory->create(ArticleType::class, $store->load()));
        $bob = $alice;
        $alice['title'] = 'Alice';
        $bob['title'] = 'Bob';

        $aliceForm = $factory->create(ArticleType::class, $store->load());
        $bobForm = $factory->create(ArticleType::class, $store->load());
        $aliceForm->submit($alice);
        $bobForm->submit($bob);

        self::assertTrue($aliceForm->isValid());
        self::assertTrue($bobForm->isValid());
    }
}
