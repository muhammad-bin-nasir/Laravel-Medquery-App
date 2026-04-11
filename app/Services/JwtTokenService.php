<?php

namespace App\Services;

use App\Models\User;
use RuntimeException;

class JwtTokenService
{
    public function createForUser(User $user): array
    {
        $secret = (string) env('JWT_SECRET_KEY', '');
        if ($secret === '') {
            throw new RuntimeException('JWT secret is not configured');
        }

        $ttlMinutes = (int) env('JWT_ACCESS_TOKEN_EXPIRE_MINUTES', 60);
        $expiresAt = time() + ($ttlMinutes * 60);

        $header = ['alg' => 'HS256', 'typ' => 'JWT'];
        $payload = [
            'sub' => (string) $user->id,
            'business_id' => $user->business_id,
            'role' => $user->role,
            'exp' => $expiresAt,
        ];

        $headerB64 = $this->base64UrlEncode(json_encode($header, JSON_UNESCAPED_SLASHES));
        $payloadB64 = $this->base64UrlEncode(json_encode($payload, JSON_UNESCAPED_SLASHES));
        $signature = hash_hmac('sha256', $headerB64.'.'.$payloadB64, $secret, true);
        $signatureB64 = rtrim(strtr(base64_encode($signature), '+/', '-_'), '=');

        return [
            'access_token' => $headerB64.'.'.$payloadB64.'.'.$signatureB64,
            'token_type' => 'bearer',
            'expires_in' => $ttlMinutes * 60,
            'expires_at' => $expiresAt,
        ];
    }

    public function decodeAndValidate(string $token): array
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            throw new RuntimeException('Invalid token format');
        }

        [$headerB64, $payloadB64, $signatureB64] = $parts;

        $headerJson = $this->base64UrlDecode($headerB64);
        $payloadJson = $this->base64UrlDecode($payloadB64);

        $header = json_decode($headerJson, true);
        $payload = json_decode($payloadJson, true);

        if (!is_array($header) || !is_array($payload)) {
            throw new RuntimeException('Invalid token payload');
        }

        if (($header['alg'] ?? null) !== 'HS256') {
            throw new RuntimeException('Unsupported token algorithm');
        }

        $secret = (string) env('JWT_SECRET_KEY', '');
        if ($secret === '') {
            throw new RuntimeException('JWT secret is not configured');
        }

        $expected = rtrim(strtr(base64_encode(hash_hmac('sha256', $headerB64.'.'.$payloadB64, $secret, true)), '+/', '-_'), '=');
        if (!hash_equals($expected, $signatureB64)) {
            throw new RuntimeException('Invalid token signature');
        }

        $exp = (int) ($payload['exp'] ?? 0);
        if ($exp > 0 && $exp < time()) {
            throw new RuntimeException('Token expired');
        }

        if (!isset($payload['sub'])) {
            throw new RuntimeException('Token subject is missing');
        }

        return [
            'sub' => (string) $payload['sub'],
            'business_id' => isset($payload['business_id']) ? (string) $payload['business_id'] : null,
            'role' => isset($payload['role']) ? (string) $payload['role'] : null,
            'exp' => $exp,
        ];
    }

    private function base64UrlDecode(string $value): string
    {
        $remainder = strlen($value) % 4;
        if ($remainder) {
            $value .= str_repeat('=', 4 - $remainder);
        }

        $decoded = base64_decode(strtr($value, '-_', '+/'), true);
        if ($decoded === false) {
            throw new RuntimeException('Invalid base64url value');
        }

        return $decoded;
    }

    private function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
