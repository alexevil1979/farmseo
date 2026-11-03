<?php

declare(strict_types=1);

namespace Farm\Services;

use Farm\Repositories\AccountRepository;
use Farm\Repositories\PhoneRepository;
use InvalidArgumentException;
use RuntimeException;

/**
 * SMS number rental: getNumber / waitCode / release / cancel.
 *
 * Provider selected via SMS_PROVIDER (.env): sms-activate | simbank
 * Key: SMS_KEY. Optional SMS_API_URL overrides base endpoint.
 */
final class SmsProvider
{
    public const PROVIDER_SMS_ACTIVATE = 'sms-activate';
    public const PROVIDER_SIMBANK = 'simbank';

    public const DEFAULT_SERVICE = 'ya';
    public const DEFAULT_COUNTRY = '0';
    public const DEFAULT_RENT_MINUTES = 20;

    /** @var callable fn(string $method, string $url, array $query = [], ?array $body = null): array{ok:bool,status:int,body:string,json:?array} */
    private $httpClient;

    /** @var callable(int): void */
    private $sleeper;

    /**
     * @param array{
     *     provider?: string,
     *     api_key?: string,
     *     api_url?: string,
     *     service?: string,
     *     country?: string,
     *     rent_minutes?: int,
     *     poll_interval_sec?: int,
     *     wait_timeout_sec?: int
     * } $config
     * @param callable|null $httpClient
     * @param callable|null $sleeper fn(int $seconds): void
     */
    public function __construct(
        private readonly PhoneRepository $phones,
        private readonly AccountRepository $accounts,
        private readonly array $config = [],
        ?callable $httpClient = null,
        ?callable $sleeper = null,
    ) {
        $this->httpClient = $httpClient ?? [$this, 'defaultHttp'];
        $this->sleeper = $sleeper ?? static function (int $seconds): void {
            if ($seconds > 0) {
                sleep($seconds);
            }
        };
    }

    public function providerName(): string
    {
        $raw = strtolower(trim((string) ($this->config['provider'] ?? 'sms-activate')));
        $aliases = [
            'smsactivate' => self::PROVIDER_SMS_ACTIVATE,
            'sms_activate' => self::PROVIDER_SMS_ACTIVATE,
            'activate' => self::PROVIDER_SMS_ACTIVATE,
            'sim-bank' => self::PROVIDER_SIMBANK,
            'sim_bank' => self::PROVIDER_SIMBANK,
            'sim' => self::PROVIDER_SIMBANK,
        ];

        return $aliases[$raw] ?? $raw;
    }

    public function apiKey(): string
    {
        return (string) ($this->config['api_key'] ?? '');
    }

    /**
     * Rent a number and optionally bind to account.
     *
     * @param array{service?: string, country?: string, account_id?: int|null, rent_minutes?: int} $opts
     * @return array{phone_id: int, number: string, external_id: string, provider: string, expires_at: ?string, account_id: ?int}
     */
    public function getNumber(array $opts = []): array
    {
        $key = $this->requireApiKey();
        $service = (string) ($opts['service'] ?? $this->config['service'] ?? self::DEFAULT_SERVICE);
        $country = (string) ($opts['country'] ?? $this->config['country'] ?? self::DEFAULT_COUNTRY);
        $rentMinutes = (int) ($opts['rent_minutes'] ?? $this->config['rent_minutes'] ?? self::DEFAULT_RENT_MINUTES);
        $accountId = isset($opts['account_id']) ? (int) $opts['account_id'] : null;

        if ($accountId !== null && $accountId > 0) {
            $acc = $this->accounts->findById($accountId);
            if ($acc === null) {
                throw new InvalidArgumentException("Account #{$accountId} not found");
            }
        } else {
            $accountId = null;
        }

        $result = match ($this->providerName()) {
            self::PROVIDER_SMS_ACTIVATE => $this->smsActivateGetNumber($key, $service, $country),
            self::PROVIDER_SIMBANK => $this->simBankGetNumber($key, $service, $country),
            default => throw new InvalidArgumentException(
                'Unknown SMS_PROVIDER "' . $this->providerName() . '". Use: sms-activate | simbank'
            ),
        };

        $dt = new \DateTimeImmutable('now');
        $now = $dt->format('Y-m-d H:i:s.v');
        $expiresAt = $rentMinutes > 0
            ? $dt->modify('+' . $rentMinutes . ' minutes')->format('Y-m-d H:i:s.v')
            : null;

        $phoneId = $this->phones->create([
            'number' => $result['number'],
            'provider' => $this->providerName(),
            'status' => 'rented',
            'account_id' => $accountId,
            'external_id' => $result['external_id'],
            'service' => $service,
            'country' => $country,
            'rented_at' => $now,
            'expires_at' => $expiresAt,
            'meta' => $result['meta'] ?? null,
        ]);

        if ($accountId !== null) {
            $this->accounts->update($accountId, ['phone' => $result['number']]);
        }

        return [
            'phone_id' => $phoneId,
            'number' => $result['number'],
            'external_id' => $result['external_id'],
            'provider' => $this->providerName(),
            'expires_at' => $expiresAt,
            'account_id' => $accountId,
        ];
    }

    /**
     * Poll until SMS code arrives (or timeout).
     *
     * @return array{code: string, phone_id: int, number: string, account_id: ?int}
     */
    public function waitCode(int $phoneId, ?int $timeoutSec = null, ?int $pollIntervalSec = null): array
    {
        $phone = $this->requirePhone($phoneId);
        if (in_array((string) $phone['status'], ['released', 'cancelled', 'expired'], true)) {
            throw new RuntimeException("Phone #{$phoneId} is {$phone['status']}");
        }

        $timeout = $timeoutSec ?? (int) ($this->config['wait_timeout_sec'] ?? 180);
        $poll = $pollIntervalSec ?? (int) ($this->config['poll_interval_sec'] ?? 5);
        $timeout = max(1, $timeout);
        $poll = max(0, $poll);
        $provider = $this->providerForPhone($phone);

        $this->phones->update($phoneId, ['status' => 'waiting']);

        $deadline = time() + $timeout;
        $key = $this->requireApiKey();
        $externalId = (string) ($phone['external_id'] ?? '');
        if ($externalId === '') {
            throw new RuntimeException("Phone #{$phoneId} has no external_id");
        }

        while (time() <= $deadline) {
            $code = match ($provider) {
                self::PROVIDER_SMS_ACTIVATE => $this->smsActivateGetStatus($key, $externalId),
                self::PROVIDER_SIMBANK => $this->simBankGetStatus($key, $externalId),
                default => throw new InvalidArgumentException('Unknown SMS provider: ' . $provider),
            };

            if ($code !== null && $code !== '') {
                $this->phones->update($phoneId, [
                    'status' => 'used',
                    'last_code' => $code,
                ]);
                $accountId = isset($phone['account_id']) && $phone['account_id'] !== null
                    ? (int) $phone['account_id']
                    : null;
                if ($accountId !== null) {
                    $this->accounts->update($accountId, ['phone' => (string) $phone['number']]);
                }

                return [
                    'code' => $code,
                    'phone_id' => $phoneId,
                    'number' => (string) $phone['number'],
                    'account_id' => $accountId,
                ];
            }

            if (time() + $poll > $deadline) {
                break;
            }
            $this->sleep($poll);
        }

        throw new RuntimeException("Timed out waiting for SMS code on phone #{$phoneId}");
    }

    /**
     * Finish activation and free the number at the provider.
     *
     * @return array{ok: bool, phone_id: int, status: string}
     */
    public function release(int $phoneId): array
    {
        return $this->finish($phoneId, 'release');
    }

    /**
     * Cancel activation (number unused / bad).
     *
     * @return array{ok: bool, phone_id: int, status: string}
     */
    public function cancel(int $phoneId): array
    {
        return $this->finish($phoneId, 'cancel');
    }

    /**
     * Bind an already-rented phone row to an account (updates accounts.phone).
     */
    public function bindAccount(int $phoneId, int $accountId): array
    {
        $phone = $this->requirePhone($phoneId);
        $acc = $this->accounts->findById($accountId);
        if ($acc === null) {
            throw new InvalidArgumentException("Account #{$accountId} not found");
        }

        $this->phones->update($phoneId, ['account_id' => $accountId]);
        $this->accounts->update($accountId, ['phone' => (string) $phone['number']]);

        return [
            'phone_id' => $phoneId,
            'account_id' => $accountId,
            'number' => (string) $phone['number'],
        ];
    }

    /**
     * @return array{ok: bool, phone_id: int, status: string}
     */
    private function finish(int $phoneId, string $action): array
    {
        $phone = $this->requirePhone($phoneId);
        $key = $this->requireApiKey();
        $externalId = (string) ($phone['external_id'] ?? '');
        $statusCode = $action === 'cancel' ? 8 : 6; // sms-activate: 6=done, 8=cancel
        $provider = $this->providerForPhone($phone);

        if ($externalId !== '') {
            match ($provider) {
                self::PROVIDER_SMS_ACTIVATE => $this->smsActivateSetStatus($key, $externalId, $statusCode),
                self::PROVIDER_SIMBANK => $this->simBankSetStatus($key, $externalId, $action),
                default => null,
            };
        }

        $newStatus = $action === 'cancel' ? 'cancelled' : 'released';
        $dt = new \DateTimeImmutable('now');
        $this->phones->update($phoneId, [
            'status' => $newStatus,
            'released_at' => $dt->format('Y-m-d H:i:s.v'),
        ]);

        return ['ok' => true, 'phone_id' => $phoneId, 'status' => $newStatus];
    }

    // ─── sms-activate adapter ───────────────────────────────────────────

    /**
     * @return array{number: string, external_id: string, meta?: array}
     */
    private function smsActivateGetNumber(string $key, string $service, string $country): array
    {
        $body = $this->smsActivateRequest([
            'api_key' => $key,
            'action' => 'getNumber',
            'service' => $service,
            'country' => $country,
        ]);

        // ACCESS_NUMBER:id:phone
        if (preg_match('/^ACCESS_NUMBER:([^:]+):(\d+)$/', $body, $m)) {
            return [
                'external_id' => $m[1],
                'number' => $m[2],
                'meta' => ['raw' => $body],
            ];
        }

        throw new RuntimeException('sms-activate getNumber failed: ' . $body);
    }

    private function smsActivateGetStatus(string $key, string $id): ?string
    {
        $body = $this->smsActivateRequest([
            'api_key' => $key,
            'action' => 'getStatus',
            'id' => $id,
        ]);

        if (str_starts_with($body, 'STATUS_OK:')) {
            return substr($body, strlen('STATUS_OK:'));
        }
        if ($body === 'STATUS_WAIT_CODE' || str_starts_with($body, 'STATUS_WAIT_RETRY')) {
            return null;
        }
        if ($body === 'STATUS_CANCEL') {
            throw new RuntimeException('sms-activate activation cancelled by provider');
        }

        // Unknown — treat as still waiting unless clearly fatal
        if (str_contains($body, 'NO_ACTIVATION') || str_starts_with($body, 'BAD_')) {
            throw new RuntimeException('sms-activate getStatus: ' . $body);
        }

        return null;
    }

    private function smsActivateSetStatus(string $key, string $id, int $status): void
    {
        $body = $this->smsActivateRequest([
            'api_key' => $key,
            'action' => 'setStatus',
            'id' => $id,
            'status' => (string) $status,
        ]);
        if (
            !str_starts_with($body, 'ACCESS_')
            && $body !== 'EARLY_CANCEL_DENIED'
        ) {
            // Non-fatal for already-finished activations
            if (!str_contains($body, 'NO_ACTIVATION')) {
                throw new RuntimeException('sms-activate setStatus failed: ' . $body);
            }
        }
    }

    /**
     * @param array<string, string> $query
     */
    private function smsActivateRequest(array $query): string
    {
        $base = (string) ($this->config['api_url'] ?? '');
        if ($base === '') {
            $base = 'https://api.sms-activate.org/stubs/handler_api.php';
        }
        $res = ($this->httpClient)('GET', $base, $query, null);
        if (!$res['ok'] && ($res['body'] ?? '') === '') {
            throw new RuntimeException('sms-activate HTTP ' . ($res['status'] ?? 0));
        }

        return trim((string) ($res['body'] ?? ''));
    }

    // ─── sim-bank adapter (sms-activate-compatible + JSON fallback) ─────

    /**
     * @return array{number: string, external_id: string, meta?: array}
     */
    private function simBankGetNumber(string $key, string $service, string $country): array
    {
        $base = $this->simBankBaseUrl();
        // Prefer sms-activate-compatible query API (common for sim-bank software)
        $res = ($this->httpClient)('GET', $base, [
            'api_key' => $key,
            'key' => $key,
            'action' => 'getNumber',
            'service' => $service,
            'country' => $country,
        ], null);

        $body = trim((string) ($res['body'] ?? ''));
        if (preg_match('/^ACCESS_NUMBER:([^:]+):(\d+)$/', $body, $m)) {
            return [
                'external_id' => $m[1],
                'number' => $m[2],
                'meta' => ['raw' => $body, 'mode' => 'activate_compat'],
            ];
        }

        $json = $res['json'] ?? null;
        if (is_array($json)) {
            $number = (string) ($json['number'] ?? $json['phone'] ?? $json['data']['number'] ?? '');
            $ext = (string) ($json['id'] ?? $json['activation_id'] ?? $json['external_id'] ?? $json['data']['id'] ?? '');
            if ($number !== '' && $ext !== '') {
                return [
                    'external_id' => $ext,
                    'number' => preg_replace('/\D+/', '', $number) ?: $number,
                    'meta' => ['raw' => $json, 'mode' => 'json'],
                ];
            }
            $err = (string) ($json['error'] ?? $json['message'] ?? 'unknown');
            throw new RuntimeException('simbank getNumber failed: ' . $err);
        }

        throw new RuntimeException('simbank getNumber failed: ' . ($body !== '' ? $body : 'empty response'));
    }

    private function simBankGetStatus(string $key, string $id): ?string
    {
        $base = $this->simBankBaseUrl();
        $res = ($this->httpClient)('GET', $base, [
            'api_key' => $key,
            'key' => $key,
            'action' => 'getStatus',
            'id' => $id,
        ], null);

        $body = trim((string) ($res['body'] ?? ''));
        if (str_starts_with($body, 'STATUS_OK:')) {
            return substr($body, strlen('STATUS_OK:'));
        }
        if ($body === 'STATUS_WAIT_CODE' || str_starts_with($body, 'STATUS_WAIT_RETRY')) {
            return null;
        }

        $json = $res['json'] ?? null;
        if (is_array($json)) {
            $code = $json['code'] ?? $json['sms'] ?? $json['data']['code'] ?? null;
            if ($code !== null && $code !== '') {
                return (string) $code;
            }
            $status = strtolower((string) ($json['status'] ?? ''));
            if (in_array($status, ['wait', 'waiting', 'pending', 'STATUS_WAIT_CODE'], true)) {
                return null;
            }
        }

        if (str_contains($body, 'NO_ACTIVATION') || str_starts_with($body, 'BAD_')) {
            throw new RuntimeException('simbank getStatus: ' . $body);
        }

        return null;
    }

    private function simBankSetStatus(string $key, string $id, string $action): void
    {
        $base = $this->simBankBaseUrl();
        $status = $action === 'cancel' ? '8' : '6';
        $res = ($this->httpClient)('GET', $base, [
            'api_key' => $key,
            'key' => $key,
            'action' => 'setStatus',
            'id' => $id,
            'status' => $status,
        ], null);

        $body = trim((string) ($res['body'] ?? ''));
        $json = $res['json'] ?? null;
        if (is_array($json) && isset($json['ok']) && $json['ok'] === false) {
            throw new RuntimeException('simbank setStatus failed: ' . (string) ($json['error'] ?? 'error'));
        }
        if (
            $body !== ''
            && !str_starts_with($body, 'ACCESS_')
            && !str_contains($body, 'NO_ACTIVATION')
            && ($json === null)
        ) {
            // soft-fail already closed
            if (!str_contains(strtolower($body), 'ok')) {
                throw new RuntimeException('simbank setStatus failed: ' . $body);
            }
        }
    }

    private function simBankBaseUrl(): string
    {
        $base = trim((string) ($this->config['api_url'] ?? ''));
        if ($base === '') {
            // Local/hardware sim-bank software often listens here; override via SMS_API_URL
            $base = 'http://127.0.0.1:7920/';
        }

        return $base;
    }

    // ─── helpers ────────────────────────────────────────────────────────

    private function requireApiKey(): string
    {
        $key = $this->apiKey();
        if ($key === '') {
            throw new RuntimeException('SMS_KEY is empty');
        }

        return $key;
    }

    /**
     * @return array<string, mixed>
     */
    private function requirePhone(int $phoneId): array
    {
        $phone = $this->phones->findById($phoneId);
        if ($phone === null) {
            throw new InvalidArgumentException("Phone #{$phoneId} not found");
        }

        return $phone;
    }

    /**
     * @param array<string, mixed> $phone
     */
    private function providerForPhone(array $phone): string
    {
        $raw = strtolower(trim((string) ($phone['provider'] ?? '')));
        if ($raw === '') {
            return $this->providerName();
        }
        $aliases = [
            'smsactivate' => self::PROVIDER_SMS_ACTIVATE,
            'sms_activate' => self::PROVIDER_SMS_ACTIVATE,
            'sim-bank' => self::PROVIDER_SIMBANK,
            'sim_bank' => self::PROVIDER_SIMBANK,
        ];

        return $aliases[$raw] ?? $raw;
    }

    private function sleep(int $seconds): void
    {
        ($this->sleeper)(max(0, $seconds));
    }

    /**
     * @param array<string, scalar|null> $query
     * @param array<string, mixed>|null $body
     * @return array{ok: bool, status: int, body: string, json: ?array}
     */
    private function defaultHttp(string $method, string $url, array $query = [], ?array $body = null): array
    {
        if ($query !== []) {
            $sep = str_contains($url, '?') ? '&' : '?';
            $url .= $sep . http_build_query($query);
        }

        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            if ($ch === false) {
                throw new RuntimeException('curl_init failed');
            }
            $headers = ['Accept: application/json, text/plain, */*'];
            $opts = [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CUSTOMREQUEST => strtoupper($method),
                CURLOPT_TIMEOUT => 30,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_HTTPHEADER => $headers,
            ];
            if ($body !== null) {
                $payload = json_encode($body, JSON_UNESCAPED_UNICODE);
                $opts[CURLOPT_POSTFIELDS] = $payload;
                $headers[] = 'Content-Type: application/json';
                $opts[CURLOPT_HTTPHEADER] = $headers;
            }
            curl_setopt_array($ch, $opts);
            $raw = curl_exec($ch);
            $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $err = curl_error($ch);
            curl_close($ch);
            if ($raw === false) {
                throw new RuntimeException('SMS HTTP error: ' . $err);
            }
            $text = (string) $raw;
            $json = json_decode($text, true);

            return [
                'ok' => $status >= 200 && $status < 300,
                'status' => $status,
                'body' => $text,
                'json' => is_array($json) ? $json : null,
            ];
        }

        $ctx = stream_context_create([
            'http' => [
                'method' => strtoupper($method),
                'timeout' => 30,
                'header' => "Accept: application/json, text/plain\r\n"
                    . ($body !== null ? "Content-Type: application/json\r\n" : ''),
                'content' => $body !== null ? json_encode($body, JSON_UNESCAPED_UNICODE) : null,
                'ignore_errors' => true,
            ],
        ]);
        $raw = @file_get_contents($url, false, $ctx);
        $text = $raw === false ? '' : (string) $raw;
        $status = 0;
        if (isset($http_response_header[0]) && preg_match('/\s(\d{3})\s/', $http_response_header[0], $m)) {
            $status = (int) $m[1];
        }
        $json = json_decode($text, true);

        return [
            'ok' => $status >= 200 && $status < 300,
            'status' => $status,
            'body' => $text,
            'json' => is_array($json) ? $json : null,
        ];
    }
}
