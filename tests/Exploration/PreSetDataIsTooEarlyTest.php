<?php

declare(strict_types=1);

namespace LockForm\FormConcurrency\Tests\Exploration;

use LockForm\FormConcurrency\Tests\Fixtures\Article;
use LockForm\FormConcurrency\Tests\Fixtures\ArticleType;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\Form\Forms;

/**
 * Rejected option: computing the fingerprint in PRE_SET_DATA of the root form.
 * Documents why the listener uses POST_SET_DATA instead.
 */
final class PreSetDataIsTooEarlyTest extends TestCase
{
    public function testTheFieldsAreStillEmptyWhenTheRootFormReceivesPreSetData(): void
    {
        $seenInPreSetData = null;
        $seenInPostSetData = null;

        Forms::createFormFactory()
            ->createBuilder(ArticleType::class, new Article(title: 'Optimistic locking'))
            ->addEventListener(FormEvents::PRE_SET_DATA, static function (FormEvent $event) use (&$seenInPreSetData): void {
                $seenInPreSetData = $event->getForm()->get('title')->getViewData();
            })
            ->addEventListener(FormEvents::POST_SET_DATA, static function (FormEvent $event) use (&$seenInPostSetData): void {
                $seenInPostSetData = $event->getForm()->get('title')->getViewData();
            })
            ->getForm();

        self::assertSame('', $seenInPreSetData);
        self::assertSame('Optimistic locking', $seenInPostSetData);
    }
}
