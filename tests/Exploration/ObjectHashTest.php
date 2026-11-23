<?php

declare(strict_types=1);

namespace LockForm\FormConcurrency\Tests\Exploration;

use LockForm\FormConcurrency\Fingerprint;
use LockForm\FormConcurrency\Tests\Fixtures\Article;
use LockForm\FormConcurrency\Tests\Fixtures\ArticleStore;
use LockForm\FormConcurrency\Tests\Fixtures\ArticleType;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Form\Forms;

/**
 * Rejected option: hashing the edited object itself rather than what the form displays.
 */
final class ObjectHashTest extends TestCase
{
    public function testHashingTheObjectReportsAChangeTheUserCouldNotHaveOverwritten(): void
    {
        $store = new ArticleStore(new Article(title: 'Optimistic locking', internalNotes: 'draft'));
        $before = $store->load();

        $store->changeConcurrently(static function (Article $article): void {
            $article->internalNotes = 'published';
        });
        $after = $store->load();

        self::assertNotSame(hash('xxh3', serialize($before)), hash('xxh3', serialize($after)), 'object hash: false conflict');

        $factory = Forms::createFormFactory();
        $seen = Fingerprint::ofForm($factory->create(ArticleType::class, $before), 'test-secret');
        $current = Fingerprint::ofForm($factory->create(ArticleType::class, $after), 'test-secret');

        self::assertSame([], $seen->changedPaths($current), 'form fingerprint: no conflict');
    }

    public function testHashingTheObjectDependsOnHowTheValuesAreHeldNotOnWhatTheyMean(): void
    {
        $utc = new Article(publishedAt: new \DateTimeImmutable('2026-11-20 00:00:00', new \DateTimeZone('UTC')));
        $sameDayElsewhere = new Article(publishedAt: new \DateTimeImmutable('2026-11-20 00:00:00.000000', new \DateTimeZone('UTC')));
        $sameDayElsewhere->publishedAt = $sameDayElsewhere->publishedAt?->setTimezone(new \DateTimeZone('Z'));

        self::assertNotSame(serialize($utc), serialize($sameDayElsewhere), 'object hash: "UTC" and "Z" differ');

        $factory = Forms::createFormFactory();
        self::assertSame(
            [],
            Fingerprint::ofForm($factory->create(ArticleType::class, $utc), 'test-secret')
                ->changedPaths(Fingerprint::ofForm($factory->create(ArticleType::class, $sameDayElsewhere), 'test-secret')),
            'form fingerprint: same displayed date',
        );
    }
}
