<?php

declare(strict_types=1);

namespace Farm\Services;

use Farm\Repositories\SettingRepository;
use Farm\Support\RedisClient;

/**
 * Telegram alert bot: severity formatting + Redis dedupe.
 *
 * Env: TG_TOKEN, TG_CHAT_ID (or settings telegram_chat_id / telegram.token).
 */
final class Telegram
{
    public const SEVERITIES = ['info', 'warn', 'critical'];

    private const DEDUPE_PREFIX = 'farm:alert:dedupe:';

    /** @var callable|null fn(string $method, array $params): array{ok: bool, ...} */
    private $httpClient;

    public function __construct(
        private readonly ?SettingRepository $settings = null,
        private readonly ?RedisClient $redis = null,
        private readonly string $token = '',
        private readonly string $chatId = '',
        private readonly int $defaultDedupeSec = 300,
        ?callable $httpClient = null,
    ) {
        $this->httpClient = $httpClient;
    }

    public function isConfigured(): bool
    {
        return $this->resolveToken() !== '' && $this->resolveChatId() !== '';
    }

    /**
     * Send alert if not deduped. Returns send outcome.
     *
     * @param array<string, mixed> $meta
     * @return array{sent: bool, deduped?: bool, skipped?: bool, error?: string, message_id?: int|null}
     */
    public function sendAlert(
        string $severity,
        string $source,
        string $message,
        array $meta = [],
        ?string $dedupeKey = null,
        ?int $dedupeTtlSec = null,
    ): array {
        $severity = $this->normalizeSeverity($severity);
        if (!$this->isConfigured()) {
            return ['sent' => false, 'skipped' => true, 'error' => 'telegram_not_configured'];
        }

        $fingerprint = $dedupeKey ?? $this->fingerprint($severity, $source, $message, $meta);
        $ttl = $dedupeTtlSec ?? $this->dedupeTtlFor($severity);
        if ($this->isDeduped($fingerprint)) {
            return ['sent' => false, 'deduped' => true];
        }

        $text = $this->formatMessage($severity, $source, $message, $meta);
        $result = $this->sendMessage($text);
        if ($result['ok']) {
            $this->markDeduped($fingerprint, $ttl);
        }

        return [
            'sent' => $result['ok'],
            'error' => $result['ok'] ? null : ($result['error'] ?? 'send_failed'),
            'message_id' => $result['message_id'] ?? null,
        ];
    }

    /**
     * Force-send a test message (bypasses dedupe).
     *
     * @return array{sent: bool, error?: string, message_id?: int|null}
     */
    public function sendTest(string $message = 'Farm monitor test alert'): array
    {
        if (!$this->isConfigured()) {
            return ['sent' => false, 'error' => 'telegram_not_configured'];
        }

        $text = $this->formatMessage('info', 'monitor', $message, ['test' => true]);
        $result = $this->sendMessage($text);

        return [
            'sent' => $result['ok'],
            'error' => $result['ok'] ? null : ($result['error'] ?? 'send_failed'),
            'message_id' => $result['message_id'] ?? null,
        ];
    }

    /**
     * @param array<string, mixed> $meta
     */
    public function fingerprint(string $severity, string $source, string $message, array $meta = []): string
    {
        $entity = ($meta['entity_type'] ?? '') . ':' . ($meta['entity_id'] ?? '');
        $rule = (string) ($meta['rule'] ?? '');

        return hash('sha256', $severity . '|' . $source . '|' . $rule . '|' . $entity . '|' . $message);
    }

    public function normalizeSeverity(string $severity): string
    {
        $s = strtolower(trim($severity));
        if ($s === 'warning') {
            $s = 'warn';
        }
        if ($s === 'error' || $s === 'crit') {
            $s = 'critical';
        }

        return in_array($s, self::SEVERITIES, true) ? $s : 'warn';
    }

    /**
     * @param array<string, mixed> $meta
     */
    public function formatMessage(string $severity, string $source, string $message, array $meta = []): string
    {
        $severity = $this->normalizeSeverity($severity);
        $icon = match ($severity) {
            'critical' => "\xF0\x9F\x94\xB4", // 🔴
            'warn' => "\xF0\x9F\x9F\xA1", // 🟡
            default => "\xF0\x9F\x94\xB5", // 🔵
        };
        $lines = [
            $icon . ' *[' . strtoupper($severity) . ']* `' . $this->escapeMarkdown($source) . '`',
            $this->escapeMarkdown($message),
        ];
        if ($meta !== []) {
            $bits = [];
            foreach ($meta as $k => $v) {
                if (in_array($k, ['rule', 'entity_type', 'entity_id', 'dead_pct', 'cpu', 'ram', 'worker_id', 'count'], true)) {
                    $bits[] = $k . '=' . (is_scalar($v) ? (string) $v : json_encode($v));
                }
            }
            if ($bits !== []) {
                $lines[] = '`' . $this->escapeMarkdown(implode(' ', $bits)) . '`';
            }
        }
        $lines[] = '_' . $this->escapeMarkdown((new \DateTimeImmutable())->format('Y-m-d H:i:s')) . ' UTC_';

        return implode("\n", $lines);
    }

    /**
     * @return array{ok: bool, error?: string, message_id?: int|null, raw?: mixed}
     */
    public function sendMessage(string $text, ?string $chatId = null): array
    {
        $token = $this->resolveToken();
        $chat = $chatId ?? $this->resolveChatId();
        if ($token === '' || $chat === '') {
            return ['ok' => false, 'error' => 'telegram_not_configured'];
        }

        $params = [
            'chat_id' => $chat,
            'text' => $text,
            'parse_mode' => 'Markdown',
            'disable_web_page_preview' => true,
        ];

        try {
            $resp = $this->apiCall('sendMessage', $params);
            if (!($resp['ok'] ?? false)) {
                // Retry plain text if Markdown rejected
                $params['parse_mode'] = null;
                unset($params['parse_mode']);
                $params['text'] = strip_tags(str_replace(['*', '_', '`'], '', $text));
                $resp = $this->apiCall('sendMessage', $params);
            }
            if (!($resp['ok'] ?? false)) {
                return [
                    'ok' => false,
                    'error' => (string) ($resp['description'] ?? 'telegram_api_error'),
                    'raw' => $resp,
                ];
            }

            return [
                'ok' => true,
                'message_id' => isset($resp['result']['message_id']) ? (int) $resp['result']['message_id'] : null,
                'raw' => $resp,
            ];
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    private function dedupeTtlFor(string $severity): int
    {
        return match ($severity) {
            'critical' => max(60, (int) floor($this->defaultDedupeSec / 2)),
            'info' => $this->defaultDedupeSec * 2,
            default => $this->defaultDedupeSec,
        };
    }

    private function isDeduped(string $fingerprint): bool
    {
        if ($this->redis === null) {
            return false;
        }
        try {
            return $this->redis->exists(self::DEDUPE_PREFIX . $fingerprint);
        } catch (\Throwable) {
            return false;
        }
    }

    private function markDeduped(string $fingerprint, int $ttl): void
    {
        if ($this->redis === null) {
            return;
        }
        try {
            $this->redis->set(self::DEDUPE_PREFIX . $fingerprint, (string) time(), max(1, $ttl));
        } catch (\Throwable) {
            // ignore
        }
    }

    /**
     * Clear a dedupe key (tests / mon:test-alert).
     */
    public function clearDedupe(string $fingerprint): void
    {
        if ($this->redis === null) {
            return;
        }
        try {
            $this->redis->del(self::DEDUPE_PREFIX . $fingerprint);
        } catch (\Throwable) {
            // ignore
        }
    }

    private function resolveToken(): string
    {
        if ($this->token !== '') {
            return $this->token;
        }
        $env = (string) ($_ENV['TG_TOKEN'] ?? $_SERVER['TG_TOKEN'] ?? getenv('TG_TOKEN') ?: '');
        if ($env !== '') {
            return $env;
        }

        return $this->settingString('telegram_token')
            ?: $this->settingString('telegram.token');
    }

    private function resolveChatId(): string
    {
        if ($this->chatId !== '') {
            return $this->chatId;
        }
        $env = (string) ($_ENV['TG_CHAT_ID'] ?? $_SERVER['TG_CHAT_ID'] ?? getenv('TG_CHAT_ID') ?: '');
        if ($env !== '') {
            return $env;
        }

        return $this->settingString('telegram_chat_id')
            ?: $this->settingString('telegram.chat_id');
    }

    private function settingString(string $name): string
    {
        if ($this->settings === null) {
            return '';
        }
        $row = $this->settings->getByName($name);
        if ($row === null) {
            return '';
        }
        $value = $row['value'] ?? '';
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            if (is_string($decoded)) {
                return trim($decoded);
            }
            if (is_array($decoded)) {
                return (string) ($decoded['value'] ?? $decoded['chat_id'] ?? $decoded['token'] ?? '');
            }

            return trim($value, "\" \t\n\r");
        }
        if (is_array($value)) {
            return (string) ($value['value'] ?? $value['chat_id'] ?? $value['token'] ?? $value[0] ?? '');
        }

        return '';
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function apiCall(string $method, array $params): array
    {
        if ($this->httpClient !== null) {
            /** @var array<string, mixed> $out */
            $out = ($this->httpClient)($method, $params);

            return $out;
        }

        $token = $this->resolveToken();
        $url = 'https://api.telegram.org/bot' . $token . '/' . $method;
        $body = json_encode($params, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            if ($ch === false) {
                throw new \RuntimeException('curl_init failed');
            }
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $body,
                CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 10,
            ]);
            $raw = curl_exec($ch);
            $err = curl_error($ch);
            curl_close($ch);
            if ($raw === false) {
                throw new \RuntimeException('Telegram HTTP error: ' . $err);
            }
            $decoded = json_decode((string) $raw, true);

            return is_array($decoded) ? $decoded : ['ok' => false, 'description' => 'invalid_json'];
        }

        $ctx = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => "Content-Type: application/json\r\n",
                'content' => $body,
                'timeout' => 10,
                'ignore_errors' => true,
            ],
        ]);
        $raw = @file_get_contents($url, false, $ctx);
        if ($raw === false) {
            throw new \RuntimeException('Telegram request failed');
        }
        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : ['ok' => false, 'description' => 'invalid_json'];
    }

    private function escapeMarkdown(string $text): string
    {
        return str_replace(['_', '*', '`', '['], ['\_', '\*', '\`', '\['], $text);
    }
}
