<?php

namespace App\Mercure;

use Symfony\Component\Mercure\Jwt\TokenProviderInterface;

class StaticJwtProvider implements TokenProviderInterface
{
    public function __construct(private string $secret) {}

    public function getJwt(): string
    {
        $header = rtrim(strtr(base64_encode(json_encode(['alg' => 'HS256', 'typ' => 'JWT'])), '+/', '-_'), '=');
        $payload = rtrim(strtr(base64_encode(json_encode(['mercure' => ['publish' => ['*']]])), '+/', '-_'), '=');
        $signature = rtrim(strtr(base64_encode(hash_hmac('sha256', "$header.$payload", $this->secret, true)), '+/', '-_'), '=');
        return "$header.$payload.$signature";
    }
}