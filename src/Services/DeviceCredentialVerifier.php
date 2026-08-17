<?php
declare(strict_types=1);

namespace Codify\Services;

use Codify\Core\HttpException;

final class DeviceCredentialVerifier
{
    public function normalizeJwk(array $jwk): string
    {
        if (($jwk['kty'] ?? '') !== 'EC' || ($jwk['crv'] ?? '') !== 'P-256') $this->invalidKey();
        $x = $this->base64UrlDecode((string) ($jwk['x'] ?? ''));
        $y = $this->base64UrlDecode((string) ($jwk['y'] ?? ''));
        if ($x === null || $y === null || strlen($x) !== 32 || strlen($y) !== 32) $this->invalidKey();
        return (string) json_encode(['kty' => 'EC', 'crv' => 'P-256', 'x' => $jwk['x'], 'y' => $jwk['y'], 'ext' => true], JSON_UNESCAPED_SLASHES);
    }

    public function verify(string $publicKeyJson, string $message, string $signature): bool
    {
        $jwk = json_decode($publicKeyJson, true);
        if (!is_array($jwk)) return false;
        $raw = $this->base64UrlDecode($signature);
        if ($raw === null) return false;
        $der = strlen($raw) === 64 ? $this->rawSignatureToDer($raw) : $raw;
        return openssl_verify($message, $der, $this->pem($jwk), OPENSSL_ALGO_SHA256) === 1;
    }

    public function message(string $challengeId, string $challenge, string $credentialId): string
    {
        return 'codify-device-v1' . chr(10) . $challengeId . chr(10) . $challenge . chr(10) . $credentialId;
    }

    private function pem(array $jwk): string
    {
        $x = $this->base64UrlDecode((string) ($jwk['x'] ?? ''));
        $y = $this->base64UrlDecode((string) ($jwk['y'] ?? ''));
        if ($x === null || $y === null || strlen($x) !== 32 || strlen($y) !== 32) $this->invalidKey();
        $der = hex2bin('3059301306072a8648ce3d020106082a8648ce3d03010703420004') . $x . $y;
        return '-----BEGIN PUBLIC KEY-----' . chr(10) . chunk_split(base64_encode($der), 64, chr(10)) . '-----END PUBLIC KEY-----' . chr(10);
    }

    private function rawSignatureToDer(string $signature): string
    {
        $sequence = $this->derInteger(substr($signature, 0, 32)) . $this->derInteger(substr($signature, 32, 32));
        return chr(0x30) . chr(strlen($sequence)) . $sequence;
    }

    private function derInteger(string $value): string
    {
        $value = ltrim($value, chr(0));
        if ($value === '') $value = chr(0);
        if ((ord($value[0]) & 0x80) !== 0) $value = chr(0) . $value;
        return chr(0x02) . chr(strlen($value)) . $value;
    }

    private function base64UrlDecode(string $value): ?string
    {
        if ($value === '' || preg_match('/^[A-Za-z0-9_-]+$/', $value) !== 1) return null;
        $padding = (4 - strlen($value) % 4) % 4;
        $decoded = base64_decode(strtr($value . str_repeat('=', $padding), '-_', '+/'), true);
        return $decoded === false ? null : $decoded;
    }

    private function invalidKey(): void
    {
        throw new HttpException(422, 'The browser security key is invalid.', ['public_key_jwk' => ['Create a new device key and try again.']]);
    }
}
