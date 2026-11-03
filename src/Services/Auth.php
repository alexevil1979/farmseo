<?php

declare(strict_types=1);

namespace Farm\Services;

use Farm\Http\Request;
use Farm\Repositories\ApiTokenRepository;
use Farm\Repositories\RoleRepository;
use Farm\Repositories\SettingRepository;
use Farm\Repositories\UserRepository;
use InvalidArgumentException;
use RuntimeException;

/**
 * Login, bcrypt passwords, JWT/session, TOTP 2FA, api_tokens.
 */
final class Auth
{
    public const SESSION_COOKIE = 'farm_session';
    public const CSRF_COOKIE = 'farm_csrf';
    public const SESSION_TTL = 86400;
    public const JWT_TTL = 3600;

    public function __construct(
        private readonly UserRepository $users,
        private readonly RoleRepository $roles,
        private readonly ApiTokenRepository $tokens,
        private readonly SettingRepository $settings,
        private readonly string $appKey,
        private readonly Rbac $rbac,
    ) {
    }

    /**
     * @return array{
     *     user: array<string, mixed>,
     *     role: string,
     *     token: string,
     *     token_type: string,
     *     csrf: string,
     *     expires_in: int,
     *     totp_required?: bool
     * }
     */
    public function login(string $email, string $password, ?string $totpCode = null): array
    {
        $user = $this->users->findByEmail(strtolower(trim($email)));
        if ($user === null || !(int) ($user['is_active'] ?? 0)) {
            throw new RuntimeException('Invalid credentials');
        }
        if (!$this->verifyPassword($password, (string) $user['password_hash'])) {
            throw new RuntimeException('Invalid credentials');
        }

        if ($this->isTotpEnabled((int) $user['id'])) {
            if ($totpCode === null || $totpCode === '') {
                return [
                    'user' => $this->publicUser($user),
                    'role' => $this->roleName((int) $user['role_id']),
                    'token' => '',
                    'token_type' => 'totp_required',
                    'csrf' => '',
                    'expires_in' => 0,
                    'totp_required' => true,
                ];
            }
            if (!$this->verifyTotp((int) $user['id'], $totpCode)) {
                throw new RuntimeException('Invalid 2FA code');
            }
        }

        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s.v');
        $this->users->update((int) $user['id'], ['last_login_at' => $now]);

        $jwt = $this->issueJwt((int) $user['id'], (int) $user['role_id']);
        $csrf = $this->issueCsrfToken();
        $this->startSession((int) $user['id'], $csrf);

        return [
            'user' => $this->publicUser($user),
            'role' => $this->roleName((int) $user['role_id']),
            'token' => $jwt,
            'token_type' => 'Bearer',
            'csrf' => $csrf,
            'expires_in' => self::JWT_TTL,
        ];
    }

    public function logout(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            $_SESSION = [];
            session_destroy();
        }
        if (!headers_sent()) {
            setcookie(self::SESSION_COOKIE, '', [
                'expires' => time() - 3600,
                'path' => '/',
                'httponly' => true,
                'samesite' => 'Lax',
            ]);
            setcookie(self::CSRF_COOKIE, '', [
                'expires' => time() - 3600,
                'path' => '/',
                'httponly' => false,
                'samesite' => 'Lax',
            ]);
        }
    }

    public function hashPassword(string $password): string
    {
        return password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
    }

    public function verifyPassword(string $password, string $hash): bool
    {
        return password_verify($password, $hash);
    }

    /**
     * Create api_tokens row; returns plaintext token once.
     *
     * @param list<string>|null $abilities
     */
    public function createApiToken(int $userId, string $name, ?array $abilities = null, ?\DateTimeInterface $expiresAt = null): string
    {
        $plain = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $hash = hash('sha256', $plain);
        $this->tokens->create([
            'user_id' => $userId,
            'name' => $name,
            'token_hash' => $hash,
            'abilities' => $abilities,
            'expires_at' => $expiresAt?->format('Y-m-d H:i:s.v'),
        ]);

        return $plain;
    }

    public function revokeApiToken(int $tokenId): bool
    {
        return $this->tokens->revoke($tokenId);
    }

    public function revokeApiTokenByPlain(string $plain): bool
    {
        $row = $this->tokens->findByTokenHash(hash('sha256', $plain));
        if ($row === null) {
            return false;
        }

        return $this->tokens->revoke((int) $row['id']);
    }

    /**
     * @return array{
     *     user: array<string, mixed>,
     *     role: string,
     *     via: string,
     *     token_id?: int|null
     * }|null
     */
    public function authenticateRequest(Request $request): ?array
    {
        $bearer = $request->bearerToken();
        if ($bearer !== null && $bearer !== '') {
            $ctx = $this->authenticateBearer($bearer);
            if ($ctx !== null) {
                return $ctx;
            }
            $ctx = $this->authenticateJwt($bearer);
            if ($ctx !== null) {
                return $ctx;
            }
        }

        return $this->authenticateSession($request);
    }

    /**
     * @return array{user: array<string, mixed>, role: string, via: string, token_id: int}|null
     */
    public function authenticateBearer(string $plain): ?array
    {
        $row = $this->tokens->findByTokenHash(hash('sha256', $plain));
        if ($row === null) {
            return null;
        }
        if ($row['revoked_at'] !== null) {
            return null;
        }
        if ($row['expires_at'] !== null && strtotime((string) $row['expires_at']) < time()) {
            return null;
        }

        $user = $this->users->findById((int) $row['user_id']);
        if ($user === null || !(int) ($user['is_active'] ?? 0)) {
            return null;
        }

        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s.v');
        $this->tokens->update((int) $row['id'], ['last_used_at' => $now]);

        return [
            'user' => $user,
            'role' => $this->roleName((int) $user['role_id']),
            'via' => 'token',
            'token_id' => (int) $row['id'],
        ];
    }

    /**
     * @return array{user: array<string, mixed>, role: string, via: string, token_id: null}|null
     */
    public function authenticateJwt(string $jwt): ?array
    {
        $payload = $this->parseJwt($jwt);
        if ($payload === null) {
            return null;
        }
        $userId = (int) ($payload['sub'] ?? 0);
        if ($userId <= 0) {
            return null;
        }
        $user = $this->users->findById($userId);
        if ($user === null || !(int) ($user['is_active'] ?? 0)) {
            return null;
        }

        return [
            'user' => $user,
            'role' => $this->roleName((int) $user['role_id']),
            'via' => 'jwt',
            'token_id' => null,
        ];
    }

    /**
     * @return array{user: array<string, mixed>, role: string, via: string, token_id: null}|null
     */
    public function authenticateSession(Request $request): ?array
    {
        $this->ensureSession();
        $userId = (int) ($_SESSION['user_id'] ?? 0);
        if ($userId <= 0) {
            $cookie = $request->cookie(self::SESSION_COOKIE);
            if ($cookie === null || $cookie === '') {
                return null;
            }
            $payload = $this->parseJwt($cookie);
            if ($payload === null) {
                return null;
            }
            $userId = (int) ($payload['sub'] ?? 0);
        }
        if ($userId <= 0) {
            return null;
        }
        $user = $this->users->findById($userId);
        if ($user === null || !(int) ($user['is_active'] ?? 0)) {
            return null;
        }

        return [
            'user' => $user,
            'role' => $this->roleName((int) $user['role_id']),
            'via' => 'session',
            'token_id' => null,
        ];
    }

    public function issueJwt(int $userId, int $roleId, int $ttl = self::JWT_TTL): string
    {
        $now = time();
        $header = $this->b64(['alg' => 'HS256', 'typ' => 'JWT']);
        $payload = $this->b64([
            'sub' => $userId,
            'role_id' => $roleId,
            'iat' => $now,
            'exp' => $now + $ttl,
        ]);
        $sig = $this->b64Raw(hash_hmac('sha256', $header . '.' . $payload, $this->appKey, true));

        return $header . '.' . $payload . '.' . $sig;
    }

    /** @return array<string, mixed>|null */
    public function parseJwt(string $jwt): ?array
    {
        $parts = explode('.', $jwt);
        if (count($parts) !== 3) {
            return null;
        }
        [$h, $p, $s] = $parts;
        $expected = $this->b64Raw(hash_hmac('sha256', $h . '.' . $p, $this->appKey, true));
        if (!hash_equals($expected, $s)) {
            return null;
        }
        $json = $this->b64Decode($p);
        if ($json === null) {
            return null;
        }
        $data = json_decode($json, true);
        if (!is_array($data)) {
            return null;
        }
        if (isset($data['exp']) && (int) $data['exp'] < time()) {
            return null;
        }

        return $data;
    }

    public function issueCsrfToken(): string
    {
        $token = bin2hex(random_bytes(32));
        $this->ensureSession();
        $_SESSION['csrf'] = $token;
        if (!headers_sent()) {
            setcookie(self::CSRF_COOKIE, $token, [
                'expires' => time() + self::SESSION_TTL,
                'path' => '/',
                'httponly' => false,
                'samesite' => 'Lax',
            ]);
        }

        return $token;
    }

    public function validateCsrfToken(string $token): bool
    {
        $this->ensureSession();
        $session = (string) ($_SESSION['csrf'] ?? '');
        if ($session !== '' && hash_equals($session, $token)) {
            return true;
        }

        return false;
    }

    public function startSession(int $userId, string $csrf): void
    {
        $this->ensureSession();
        $_SESSION['user_id'] = $userId;
        $_SESSION['csrf'] = $csrf;

        $user = $this->users->findById($userId);
        $roleId = (int) ($user['role_id'] ?? 0);
        $cookieJwt = $this->issueJwt($userId, $roleId, self::SESSION_TTL);
        if (!headers_sent()) {
            setcookie(self::SESSION_COOKIE, $cookieJwt, [
                'expires' => time() + self::SESSION_TTL,
                'path' => '/',
                'httponly' => true,
                'samesite' => 'Lax',
            ]);
        }
    }

    /**
     * Enable TOTP; secret stored in settings (schema has no totp column).
     *
     * @return array{secret: string, otpauth_url: string}
     */
    public function enableTotp(int $userId, ?string $label = null): array
    {
        $secret = $this->generateTotpSecret();
        $this->settings->set('totp.user.' . $userId, [
            'secret' => $secret,
            'enabled' => false,
            'pending' => true,
        ], $userId);

        $email = $label ?? ('user-' . $userId);
        $user = $this->users->findById($userId);
        if ($user !== null) {
            $email = (string) $user['email'];
        }
        $url = sprintf(
            'otpauth://totp/%s?secret=%s&issuer=%s&digits=6&period=30',
            rawurlencode('Farm:' . $email),
            $secret,
            rawurlencode('Farm')
        );

        return ['secret' => $secret, 'otpauth_url' => $url];
    }

    public function confirmTotp(int $userId, string $code): bool
    {
        if (!$this->verifyTotp($userId, $code, true)) {
            return false;
        }
        $row = $this->settings->getByName('totp.user.' . $userId);
        $value = $this->decodeSetting($row);
        $value['enabled'] = true;
        $value['pending'] = false;
        $this->settings->set('totp.user.' . $userId, $value, $userId);

        return true;
    }

    public function disableTotp(int $userId): void
    {
        $row = $this->settings->getByName('totp.user.' . $userId);
        if ($row !== null) {
            $this->settings->set('totp.user.' . $userId, ['enabled' => false, 'secret' => null], $userId);
        }
    }

    public function isTotpEnabled(int $userId): bool
    {
        $row = $this->settings->getByName('totp.user.' . $userId);
        $value = $this->decodeSetting($row);

        return !empty($value['enabled']) && !empty($value['secret']);
    }

    public function verifyTotp(int $userId, string $code, bool $allowPending = false): bool
    {
        $row = $this->settings->getByName('totp.user.' . $userId);
        $value = $this->decodeSetting($row);
        $secret = (string) ($value['secret'] ?? '');
        if ($secret === '') {
            return false;
        }
        if (!$allowPending && empty($value['enabled'])) {
            return false;
        }
        $code = preg_replace('/\s+/', '', $code) ?? '';
        if (!preg_match('/^\d{6}$/', $code)) {
            return false;
        }
        $time = time();
        for ($drift = -1; $drift <= 1; $drift++) {
            if (hash_equals($this->totpCode($secret, $time + ($drift * 30)), $code)) {
                return true;
            }
        }

        return false;
    }

    public function generateTotpSecret(int $bytes = 20): string
    {
        return $this->base32Encode(random_bytes($bytes));
    }

    public function totpCode(string $secret, ?int $timestamp = null): string
    {
        $timestamp ??= time();
        $counter = intdiv($timestamp, 30);
        $key = $this->base32Decode($secret);
        if ($key === '') {
            throw new InvalidArgumentException('Invalid TOTP secret');
        }
        $bin = pack('N*', 0, $counter);
        $hash = hash_hmac('sha1', $bin, $key, true);
        $offset = ord($hash[19]) & 0x0f;
        $truncated = (
            ((ord($hash[$offset]) & 0x7f) << 24)
            | ((ord($hash[$offset + 1]) & 0xff) << 16)
            | ((ord($hash[$offset + 2]) & 0xff) << 8)
            | (ord($hash[$offset + 3]) & 0xff)
        ) % 1_000_000;

        return str_pad((string) $truncated, 6, '0', STR_PAD_LEFT);
    }

    public function roleName(int $roleId): string
    {
        $name = $this->rbac->resolveRoleName($roleId);
        if ($name !== null) {
            return $name;
        }
        $row = $this->roles->findById($roleId);

        return $row !== null ? (string) $row['name'] : 'viewer';
    }

    /** @param array<string, mixed> $user */
    public function publicUser(array $user): array
    {
        return [
            'id' => (int) $user['id'],
            'email' => (string) $user['email'],
            'name' => (string) $user['name'],
            'role_id' => (int) $user['role_id'],
            'is_active' => (int) ($user['is_active'] ?? 0),
            'last_login_at' => $user['last_login_at'] ?? null,
        ];
    }

    private function ensureSession(): void
    {
        if (session_status() === PHP_SESSION_NONE && !headers_sent()) {
            session_name(self::SESSION_COOKIE . '_sid');
            session_set_cookie_params([
                'lifetime' => self::SESSION_TTL,
                'path' => '/',
                'httponly' => true,
                'samesite' => 'Lax',
            ]);
            session_start();
        } elseif (session_status() === PHP_SESSION_NONE) {
            // CLI / tests
            if (!isset($_SESSION)) {
                $_SESSION = [];
            }
        }
    }

    /** @param array<string, mixed>|null $row */
    private function decodeSetting(?array $row): array
    {
        if ($row === null) {
            return [];
        }
        $value = $row['value'] ?? [];
        if (is_string($value)) {
            $decoded = json_decode($value, true);

            return is_array($decoded) ? $decoded : [];
        }

        return is_array($value) ? $value : [];
    }

    /** @param array<string, mixed> $data */
    private function b64(array $data): string
    {
        return $this->b64Raw(json_encode($data, JSON_THROW_ON_ERROR));
    }

    private function b64Raw(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    private function b64Decode(string $data): ?string
    {
        $remainder = strlen($data) % 4;
        if ($remainder > 0) {
            $data .= str_repeat('=', 4 - $remainder);
        }
        $raw = base64_decode(strtr($data, '-_', '+/'), true);

        return $raw === false ? null : $raw;
    }

    private function base32Encode(string $data): string
    {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $bits = '';
        foreach (str_split($data) as $char) {
            $bits .= str_pad(decbin(ord($char)), 8, '0', STR_PAD_LEFT);
        }
        $out = '';
        foreach (str_split($bits, 5) as $chunk) {
            if (strlen($chunk) < 5) {
                $chunk = str_pad($chunk, 5, '0', STR_PAD_RIGHT);
            }
            $out .= $alphabet[bindec($chunk)];
        }

        return $out;
    }

    private function base32Decode(string $b32): string
    {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $b32 = strtoupper(preg_replace('/[\s=]+/', '', $b32) ?? '');
        $bits = '';
        for ($i = 0, $len = strlen($b32); $i < $len; $i++) {
            $val = strpos($alphabet, $b32[$i]);
            if ($val === false) {
                return '';
            }
            $bits .= str_pad(decbin($val), 5, '0', STR_PAD_LEFT);
        }
        $out = '';
        foreach (str_split($bits, 8) as $chunk) {
            if (strlen($chunk) === 8) {
                $out .= chr(bindec($chunk));
            }
        }

        return $out;
    }
}
