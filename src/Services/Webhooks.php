<?php

declare(strict_types=1);

namespace Farm\Services;

use Farm\Repositories\SettingRepository;
use Farm\Support\RedisClient;
use Throwable;

/**
 * Fire webhook on terminal task status: done | failed | cancelled.
 */
final class Webhooks
{
    private const TERMINAL = ['done', 'failed', 'cancelled'];

    public function __construct(
        private readonly SettingRepository $settings,
        private readonly string $signingKey = '',
        private readonly ?RedisClient $redis = null,
        private readonly int $timeoutSec = 5,
    ) {
    }

    /**
     * @param array<string, mixed> $task tasks row
     * @return array{sent: bool, status_code?: int, error?: string}
     */
    public function onTaskFinished(array $task): array
    {
        $status = (string) ($task['status'] ?? '');
        if (!in_array($status, self::TERMINAL, true)) {
            return ['sent' => false, 'error' => 'not_terminal'];
        }

        $url = trim((string) ($task['webhook_url'] ?? ''));
        if ($url === '') {
            $url = $this->defaultWebhookUrl();
        }
        if ($url === '') {
            return ['sent' => false, 'error' => 'no_url'];
        }

        $payload = [
            'task_id' => (int) $task['id'],
            'status' => $status,
            'error' => $task['error'] ?? null,
            'finished_at' => $task['finished_at'] ?? (new \DateTimeImmutable())->format('Y-m-d H:i:s.v'),
        ];

        return $this->post($url, $payload);
    }

    /**
     * @param array<string, mixed> $payload
     * @return array{sent: bool, status_code?: int, error?: string}
     */
    public function post(string $url, array $payload): array
    {
        $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $headers = [
            'Content-Type: application/json',
            'User-Agent: Farm-Webhooks/1.0',
        ];
        $secret = $this->webhookSecret();
        if ($secret !== '') {
            $sig = hash_hmac('sha256', $body, $secret);
            $headers[] = 'X-Farm-Signature: sha256=' . $sig;
            $headers[] = 'X-Webhook-Token: ' . $secret;
        }

        try {
            $status = $this->httpPost($url, $body, $headers);
            if ($this->redis !== null) {
                try {
                    $this->redis->lpush(
                        'farm:evt:webhooks',
                        json_encode([
                            'url' => $url,
                            'task_id' => $payload['task_id'] ?? null,
                            'status_code' => $status,
                            'at' => time(),
                        ], JSON_THROW_ON_ERROR)
                    );
                } catch (Throwable) {
                    // ignore event bus failures
                }
            }

            return ['sent' => $status >= 200 && $status < 300, 'status_code' => $status];
        } catch (Throwable $e) {
            return ['sent' => false, 'error' => $e->getMessage()];
        }
    }

    private function defaultWebhookUrl(): string
    {
        $row = $this->settings->getByName('webhook_url');
        if ($row === null) {
            return '';
        }
        $value = $row['value'] ?? null;
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            if (is_string($decoded)) {
                return $decoded;
            }
            if (is_array($decoded) && isset($decoded['url'])) {
                return (string) $decoded['url'];
            }

            return trim($value, "\" \t\n\r");
        }
        if (is_array($value)) {
            return (string) ($value['url'] ?? $value[0] ?? '');
        }

        return '';
    }

    private function webhookSecret(): string
    {
        if ($this->signingKey !== '') {
            return $this->signingKey;
        }
        $row = $this->settings->getByName('webhook_secret');
        if ($row === null) {
            return '';
        }
        $value = $row['value'] ?? '';
        if (is_string($value)) {
            $decoded = json_decode($value, true);

            return is_string($decoded) ? $decoded : trim($value, "\" \t\n\r");
        }
        if (is_array($value)) {
            return (string) ($value['secret'] ?? $value[0] ?? '');
        }

        return '';
    }

    /**
     * @param list<string> $headers
     */
    private function httpPost(string $url, string $body, array $headers): int
    {
        if (!filter_var($url, FILTER_VALIDATE_URL) || !preg_match('#^https?://#i', $url)) {
            throw new \InvalidArgumentException('Invalid webhook URL');
        }

        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            if ($ch === false) {
                throw new \RuntimeException('curl_init failed');
            }
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $body,
                CURLOPT_HTTPHEADER => $headers,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => $this->timeoutSec,
                CURLOPT_FOLLOWLOCATION => false,
            ]);
            curl_exec($ch);
            $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $err = curl_error($ch);
            curl_close($ch);
            if ($err !== '') {
                throw new \RuntimeException('Webhook HTTP error: ' . $err);
            }

            return $status > 0 ? $status : 0;
        }

        $headerStr = implode("\r\n", $headers);
        $ctx = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => $headerStr,
                'content' => $body,
                'timeout' => $this->timeoutSec,
                'ignore_errors' => true,
            ],
        ]);
        $result = @file_get_contents($url, false, $ctx);
        if ($result === false && empty($http_response_header)) {
            throw new \RuntimeException('Webhook request failed');
        }
        $status = 0;
        if (!empty($http_response_header[0]) && preg_match('/\s(\d{3})\s/', $http_response_header[0], $m)) {
            $status = (int) $m[1];
        }

        return $status;
    }
}
