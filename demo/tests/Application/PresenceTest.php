<?php

declare(strict_types=1);

namespace App\Tests\Application;

use App\Service\DemoReset;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The presence indicator, as presence.js drives it: one heartbeat per tab, answered with who else is there.
 */
final class PresenceTest extends WebTestCase
{
    private KernelBrowser $alice;

    private KernelBrowser $bob;

    protected function setUp(): void
    {
        $this->alice = self::createClient(server: ['HTTP_ACCEPT_LANGUAGE' => 'fr']);
        $this->bob = new KernelBrowser($this->alice->getKernel(), ['HTTP_ACCEPT_LANGUAGE' => 'fr']);

        $reset = self::getContainer()->get(DemoReset::class);
        \assert($reset instanceof DemoReset);
        $reset();
    }

    public function testBobIsToldThatAliceIsEditingTheSameProductAndNoLongerOnceSheLeaves(): void
    {
        $aliceToken = $this->tokenOf($this->alice, '/products/1/edit?as=alice');
        $bobToken = $this->tokenOf($this->bob, '/products/1/edit?as=bob');

        $this->beat($this->alice, $aliceToken, 'tab-alice');
        self::assertSame('Alice modifie aussi cette fiche.', $this->beat($this->bob, $bobToken, 'tab-bob')['message'] ?? null);

        $this->alice->request('POST', '/_form_concurrency/presence', ['token' => $aliceToken, 'tab' => 'tab-alice', 'leave' => '1']);
        self::assertResponseStatusCodeSame(204);

        self::assertNull($this->beat($this->bob, $bobToken, 'tab-bob')['message'] ?? null);
    }

    public function testTheLiveFormNamesTheProductItself(): void
    {
        $aliceLive = $this->tokenOf($this->alice, '/products/1/live?as=alice');
        $bobLive = $this->tokenOf($this->bob, '/products/1/live?as=bob');

        $this->beat($this->alice, $aliceLive, 'tab-alice-live');
        self::assertSame(['Alice'], $this->beat($this->bob, $bobLive, 'tab-bob-live')['others'] ?? null);

        $this->alice->request('POST', '/_form_concurrency/presence', ['token' => $aliceLive, 'tab' => 'tab-alice-live', 'leave' => '1']);
    }

    private function tokenOf(KernelBrowser $browser, string $page): string
    {
        $crawler = $browser->request('GET', $page);
        self::assertTrue($browser->getResponse()->isSuccessful());

        $token = $crawler->filter('form[data-concurrency-presence]')->attr('data-concurrency-presence');
        self::assertIsString($token);

        return $token;
    }

    /**
     * @return array<array-key, mixed>
     */
    private function beat(KernelBrowser $browser, string $token, string $tab): array
    {
        $browser->request('POST', '/_form_concurrency/presence', ['token' => $token, 'tab' => $tab]);
        self::assertResponseIsSuccessful();

        $data = json_decode((string) $browser->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($data);

        return $data;
    }
}
