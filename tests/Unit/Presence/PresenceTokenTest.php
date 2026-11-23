<?php

declare(strict_types=1);

namespace LockForm\FormConcurrency\Tests\Unit\Presence;

use LockForm\FormConcurrency\Presence\PresenceToken;
use LockForm\FormConcurrency\Presence\PresenceViewer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PresenceTokenTest extends TestCase
{
    public function testItCarriesTheRecordAndTheViewer(): void
    {
        $token = PresenceToken::verify((new PresenceToken('/products/42/edit', new PresenceViewer('alice', 'Alice Liddell')))->sign('secret'), 'secret');

        self::assertNotNull($token);
        self::assertSame('/products/42/edit', $token->key);
        self::assertSame('alice', $token->viewer->id);
        self::assertSame('Alice Liddell', $token->viewer->name);
    }

    public function testAViewerWithoutANameStaysAnonymous(): void
    {
        $token = PresenceToken::verify((new PresenceToken('product-42', new PresenceViewer('42')))->sign('secret'), 'secret');

        self::assertNull($token?->viewer->name);
    }

    public function testATokenSignedWithAnotherSecretIsRejected(): void
    {
        self::assertNull(PresenceToken::verify((new PresenceToken('product-42', new PresenceViewer('alice')))->sign('other'), 'secret'));
    }

    public function testATamperedTokenIsRejected(): void
    {
        $signed = (new PresenceToken('product-42', new PresenceViewer('alice', 'Alice')))->sign('secret');
        [, $signature] = explode('.', $signed);
        $forged = rtrim(strtr(base64_encode('{"k":"product-43","v":"alice","n":"Alice"}'), '+/', '-_'), '=');

        self::assertNull(PresenceToken::verify($forged.'.'.$signature, 'secret'));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function malformedTokens(): iterable
    {
        yield 'empty' => [''];
        yield 'no signature' => ['eyJrIjoiYSJ9'];
        yield 'too many parts' => ['a.b.c'];
        yield 'garbage' => ['%%%.%%%'];
    }

    #[DataProvider('malformedTokens')]
    public function testAMalformedTokenIsRejectedWithoutAnException(string $token): void
    {
        self::assertNull(PresenceToken::verify($token, 'secret'));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function signedPayloadsThatAreNotTokens(): iterable
    {
        yield 'not base64' => ['%%%'];
        yield 'not JSON' => [rtrim(strtr(base64_encode('nope'), '+/', '-_'), '=')];
        yield 'no viewer' => [rtrim(strtr(base64_encode('{"k":"a"}'), '+/', '-_'), '=')];
        yield 'empty viewer' => [rtrim(strtr(base64_encode('{"k":"a","v":""}'), '+/', '-_'), '=')];
    }

    #[DataProvider('signedPayloadsThatAreNotTokens')]
    public function testACorrectlySignedPayloadThatIsNotATokenIsRejected(string $payload): void
    {
        $signature = substr(hash_hmac('sha256', 'form_concurrency.presence.'.$payload, 'secret'), 0, 32);

        self::assertNull(PresenceToken::verify($payload.'.'.$signature, 'secret'));
    }
}
