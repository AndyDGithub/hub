<?php

namespace App\Services;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class JwtService
{
    public function issueToken(User $user): string
    {
        $now = CarbonImmutable::now();
        $ttlMinutes = (int) env('JWT_TTL', 60);
        $refreshTtlMinutes = (int) env('JWT_REFRESH_TTL', 10080);

        $payload = [
            'iss' => config('app.url'),
            'sub' => $user->id,
            'iat' => $now->timestamp,
            'exp' => $now->addMinutes($ttlMinutes)->timestamp,
            'nbf' => $now->timestamp,
            'jti' => bin2hex(random_bytes(16)),
            'r_exp' => $now->addMinutes($refreshTtlMinutes)->timestamp,
            'roles' => $user->getRoleNames()->values()->all(),
        ];

        return $this->encode($payload);
    }

    public function decode(string $token, bool $allowExpired = false): ?array
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            return null;
        }

        [$header, $payload, $signature] = $parts;
        $validSignature = $this->base64UrlEncode(hash_hmac('sha256', $header.'.'.$payload, $this->secret(), true));
        if (! hash_equals($validSignature, $signature)) {
            return null;
        }

        $decoded = json_decode($this->base64UrlDecode($payload), true);
        if (! is_array($decoded)) {
            return null;
        }

        $now = CarbonImmutable::now()->timestamp;
        if (($decoded['nbf'] ?? 0) > $now) {
            return null;
        }

        if (! $allowExpired && ($decoded['exp'] ?? 0) < $now) {
            return null;
        }

        return $decoded;
    }

    public function isBlacklisted(string $jti): bool
    {
        return DB::table('jwt_blacklisted_tokens')->where('jti', $jti)->exists();
    }

    public function blacklist(string $jti, int $expiresAt): void
    {
        DB::table('jwt_blacklisted_tokens')->updateOrInsert(
            ['jti' => $jti],
            ['expires_at' => CarbonImmutable::createFromTimestamp($expiresAt), 'created_at' => now(), 'updated_at' => now()]
        );
    }

    private function encode(array $payload): string
    {
        $header = ['alg' => 'HS256', 'typ' => 'JWT'];
        $encodedHeader = $this->base64UrlEncode(json_encode($header, JSON_UNESCAPED_SLASHES));
        $encodedPayload = $this->base64UrlEncode(json_encode($payload, JSON_UNESCAPED_SLASHES));
        $signature = $this->base64UrlEncode(hash_hmac('sha256', $encodedHeader.'.'.$encodedPayload, $this->secret(), true));

        return $encodedHeader.'.'.$encodedPayload.'.'.$signature;
    }

    private function secret(): string
    {
        return (string) env('JWT_SECRET', config('app.key'));
    }

    private function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private function base64UrlDecode(string $data): string
    {
        $remainder = strlen($data) % 4;
        if ($remainder > 0) {
            $data .= str_repeat('=', 4 - $remainder);
        }

        return base64_decode(strtr($data, '-_', '+/'));
    }
}
