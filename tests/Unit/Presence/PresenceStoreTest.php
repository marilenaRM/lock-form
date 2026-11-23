<?php

declare(strict_types=1);

namespace LockForm\FormConcurrency\Tests\Unit\Presence;

use LockForm\FormConcurrency\Presence\PresenceStore;
use LockForm\FormConcurrency\Presence\PresenceViewer;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

final class PresenceStoreTest extends TestCase
{
    private const string RECORD = '/products/42/edit';

    private PresenceStore $store;

    private PresenceViewer $alice;

    private PresenceViewer $bob;

    protected function setUp(): void
    {
        $this->store = new PresenceStore(new ArrayAdapter(), ttl: 30);
        $this->alice = new PresenceViewer('alice', 'Alice');
        $this->bob = new PresenceViewer('bob', 'Bob');
    }

    public function testSomeoneAloneOnARecordSeesNobody(): void
    {
        self::assertSame([], $this->store->heartbeat(self::RECORD, $this->alice, 'tab-a', 1000));
    }

    public function testTwoPeopleOnTheSameRecordSeeEachOther(): void
    {
        $this->store->heartbeat(self::RECORD, $this->alice, 'tab-a', 1000);

        self::assertSame(['Alice'], self::names($this->store->heartbeat(self::RECORD, $this->bob, 'tab-b', 1005)));
        self::assertSame(['Bob'], self::names($this->store->heartbeat(self::RECORD, $this->alice, 'tab-a', 1010)));
    }

    public function testAnotherRecordIsNotMixedUp(): void
    {
        $this->store->heartbeat(self::RECORD, $this->alice, 'tab-a', 1000);

        self::assertSame([], $this->store->heartbeat('/products/43/edit', $this->bob, 'tab-b', 1005));
    }

    public function testYourOwnOtherTabsAreNotShownAndSomeoneWithTwoTabsCountsOnce(): void
    {
        $this->store->heartbeat(self::RECORD, $this->alice, 'tab-a1', 1000);
        $this->store->heartbeat(self::RECORD, $this->alice, 'tab-a2', 1000);

        self::assertSame([], $this->store->heartbeat(self::RECORD, $this->alice, 'tab-a1', 1001));
        self::assertSame(['Alice'], self::names($this->store->heartbeat(self::RECORD, $this->bob, 'tab-b', 1002)));
    }

    public function testATabWithoutAHeartbeatForLongerThanTheTtlIsGone(): void
    {
        $this->store->heartbeat(self::RECORD, $this->alice, 'tab-a', 1000);

        self::assertSame(['Alice'], self::names($this->store->heartbeat(self::RECORD, $this->bob, 'tab-b', 1029)));
        self::assertSame([], $this->store->heartbeat(self::RECORD, $this->bob, 'tab-b', 1031));
    }

    public function testATabThatLeavesIsGoneAtOnce(): void
    {
        $this->store->heartbeat(self::RECORD, $this->alice, 'tab-a1', 1000);
        $this->store->heartbeat(self::RECORD, $this->alice, 'tab-a2', 1000);
        $this->store->leave(self::RECORD, 'tab-a1', 1001);

        self::assertSame(['Alice'], self::names($this->store->heartbeat(self::RECORD, $this->bob, 'tab-b', 1002)), 'Alice still has another tab open.');

        $this->store->leave(self::RECORD, 'tab-a2', 1003);

        self::assertSame([], $this->store->heartbeat(self::RECORD, $this->bob, 'tab-b', 1004));
    }

    public function testLeavingARecordNobodyHasOpenDoesNothing(): void
    {
        $this->store->leave(self::RECORD, 'tab-a', 1000);

        self::assertSame([], $this->store->heartbeat(self::RECORD, $this->alice, 'tab-a', 1000));
    }

    public function testAnAnonymousViewerIsReportedWithoutAName(): void
    {
        $this->store->heartbeat(self::RECORD, new PresenceViewer('session-1'), 'tab-a', 1000);

        self::assertSame([null], self::names($this->store->heartbeat(self::RECORD, $this->bob, 'tab-b', 1001)));
    }

    /**
     * @param list<PresenceViewer> $viewers
     *
     * @return list<?string>
     */
    private static function names(array $viewers): array
    {
        return array_map(static fn (PresenceViewer $viewer): ?string => $viewer->name, $viewers);
    }
}
