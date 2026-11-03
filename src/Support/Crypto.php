<?php

declare(strict_types=1);

namespace Farm\Support;

use RuntimeException;

/**
 * Reversible AES-256-GCM for password_enc columns.
 * Key = SHA-256(APP_KEY or INTERNAL_TOKEN).
 */
final class Crypto
{
    private string $key;

    public function __construct(?string $secret = null)
    {
        $secret = $secret
            ?? ($_ENV['APP_KEY'] ?? null)
            ?? ($_SERVER['APP_KEY'] ?? null)
            ?? getenv('APP_KEY')
            ?: ($_ENV['INTERNAL_TOKEN'] ?? null)
            ?? ($_SERVER['INTERNAL_TOKEN'] ?? null)
            ?? getenv('INTERNAL_TOKEN')
            ?: 'farm-dev-insecure-key';

        if ($secret === false || $secret === '') {
            $secret = 'farm-dev-insecure-key';
        }

        $this->key = hash('sha256', (string) $secret, true);
    }

    public function encrypt(string $plaintext): string
    {
        $iv = random_bytes(12);
        $tag = '';
        $cipher = openssl_encrypt($plaintext, 'aes-256-gcm', $this->key, OPENSSL_RAW_DATA, $iv, $tag, '', 16);
        if ($cipher === false) {
            throw new RuntimeException('Encryption failed');
        }

        return base64_encode($iv . $tag . $cipher);
    }

    public function decrypt(string $encoded): string
    {
        $raw = base64_decode($encoded, true);
        if ($raw === false || strlen($raw) < 29) {
            // Legacy / seed values stored as plain base64 of plaintext
            $legacy = base64_decode($encoded, true);
            if ($legacy !== false && $legacy !== '' && !str_contains($encoded, ':')) {
                return $legacy;
            }

            throw new RuntimeException('Invalid ciphertext');
        }

        $iv = substr($raw, 0, 12);
        $tag = substr($raw, 12, 16);
        $cipher = substr($raw, 28);
        $plain = openssl_decrypt($cipher, 'aes-256-gcm', $this->key, OPENSSL_RAW_DATA, $iv, $tag);
        if ($plain === false) {
            throw new RuntimeException('Decryption failed');
        }

        return $plain;
    }

    /**
     * Decrypt if looks like our ciphertext; otherwise treat as plaintext or legacy base64.
     */
    public function decryptOrPlain(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return $value;
        }

        try {
            return $this->decrypt($value);
        } catch (RuntimeException) {
            return $value;
        }
    }
}
