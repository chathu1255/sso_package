<?php

namespace Usjnet\Sso\Models;

class SsoToken
{
    public function __construct(
        public readonly string $accessToken,
        public readonly ?string $refreshToken,
        public readonly ?int $expiresIn,
        public readonly ?string $tokenType,
        public readonly array $raw = [],
    ) {
    }

    public static function fromArray(array $payload): self
    {
        return new self(
            accessToken: (string) ($payload['access_token'] ?? ''),
            refreshToken: isset($payload['refresh_token']) ? (string) $payload['refresh_token'] : null,
            expiresIn: isset($payload['expires_in']) ? (int) $payload['expires_in'] : null,
            tokenType: isset($payload['token_type']) ? (string) $payload['token_type'] : null,
            raw: $payload,
        );
    }

    public function toFrontendPayload(): array
    {
        return [
            'access_token' => $this->accessToken,
            'refresh_token' => $this->refreshToken,
            'expires_in' => $this->expiresIn,
            'token_type' => $this->tokenType,
        ];
    }
}
