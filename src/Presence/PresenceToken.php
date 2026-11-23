<?php

declare(strict_types=1);

namespace LockForm\FormConcurrency\Presence;

/**
 * What the page sends back with every heartbeat: the record and the viewer, signed with the application secret.
 * Signing keeps the heartbeat from being used to ask who edits an arbitrary record, or to pose as someone else.
 */
final readonly class PresenceToken
{
    private const int SIGNATURE_LENGTH = 32;

    public function __construct(
        public string $key,
        public PresenceViewer $viewer,
    ) {
    }

    public function sign(#[\SensitiveParameter] string $secret): string
    {
        $payload = self::base64UrlEncode(json_encode([
            'k' => $this->key,
            'v' => $this->viewer->id,
            'n' => $this->viewer->name,
        ], \JSON_THROW_ON_ERROR | \JSON_INVALID_UTF8_SUBSTITUTE));

        return $payload.'.'.self::signature($payload, $secret);
    }

    /**
     * @return ?self null when the token is malformed or not signed with this secret
     */
    public static function verify(string $token, #[\SensitiveParameter] string $secret): ?self
    {
        $parts = explode('.', $token);
        if (2 !== \count($parts) || !hash_equals(self::signature($parts[0], $secret), $parts[1])) {
            return null;
        }

        $json = base64_decode(strtr($parts[0], '-_', '+/'), true);
        $data = false === $json ? null : json_decode($json, true);
        if (!\is_array($data) || !\is_string($data['k'] ?? null) || !\is_string($data['v'] ?? null) || '' === $data['v']) {
            return null;
        }

        $name = $data['n'] ?? null;

        return new self($data['k'], new PresenceViewer($data['v'], \is_string($name) ? $name : null));
    }

    private static function signature(string $payload, #[\SensitiveParameter] string $secret): string
    {
        return substr(hash_hmac('sha256', 'form_concurrency.presence.'.$payload, $secret), 0, self::SIGNATURE_LENGTH);
    }

    private static function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}
