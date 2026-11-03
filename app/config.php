<?php

declare(strict_types=1);

/**
 * Load configuration from environment / .env file.
 *
 * @return array{
 *     db: array{
 *         host: string,
 *         port: int,
 *         database: string,
 *         username: string,
 *         password: string,
 *         charset: string,
 *         test_database: string
 *     }
 * }
 */
return (static function (): array {
    $root = dirname(__DIR__);
    $envFile = $root . DIRECTORY_SEPARATOR . '.env';

    if (is_readable($envFile)) {
        foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            if (!str_contains($line, '=')) {
                continue;
            }
            [$key, $value] = explode('=', $line, 2);
            $key = trim($key);
            $value = trim($value);
            if (
                (str_starts_with($value, '"') && str_ends_with($value, '"'))
                || (str_starts_with($value, "'") && str_ends_with($value, "'"))
            ) {
                $value = substr($value, 1, -1);
            }
            if (getenv($key) === false && !array_key_exists($key, $_ENV)) {
                putenv("{$key}={$value}");
                $_ENV[$key] = $value;
                $_SERVER[$key] = $value;
            }
        }
    }

    $env = static function (string $key, ?string $default = null): ?string {
        $v = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);
        if ($v === false || $v === null || $v === '') {
            return $default;
        }

        return (string) $v;
    };

    return [
        'db' => [
            'host' => $env('DB_HOST', '127.0.0.1'),
            'port' => (int) $env('DB_PORT', '3306'),
            'database' => $env('DB_DATABASE', 'farm'),
            'username' => $env('DB_USERNAME', 'root'),
            'password' => $env('DB_PASSWORD', ''),
            'charset' => 'utf8mb4',
            'test_database' => $env('DB_TEST_DATABASE', 'farm_test'),
        ],
        'redis' => [
            'host' => $env('REDIS_HOST', '127.0.0.1'),
            'port' => (int) $env('REDIS_PORT', '6379'),
            'password' => $env('REDIS_PASSWORD', ''),
            'database' => (int) $env('REDIS_DB', '0'),
            'timeout' => (float) $env('REDIS_TIMEOUT', '2.0'),
        ],
        'app_key' => $env('APP_KEY', $env('INTERNAL_TOKEN', 'farm-dev-insecure-key')),
        'internal_token' => $env('INTERNAL_TOKEN', 'change-me'),
        'worker_url' => $env('WORKER_URL', 'http://127.0.0.1:3000'),
        'vnc_base_port' => (int) $env('VNC_BASE_PORT', '5900'),
        'live_view_idle_sec' => (int) $env('LIVE_VIEW_IDLE_SEC', '600'),
        'api' => [
            'rate_limit' => (int) $env('API_RATE_LIMIT', '120'),
            'rate_window' => (int) $env('API_RATE_WINDOW', '60'),
            'debug' => in_array(
                strtolower((string) $env('API_DEBUG', '0')),
                ['1', 'true', 'yes', 'on'],
                true
            ),
        ],
        'proxy' => [
            'slow_ms' => (int) $env('PROXY_SLOW_MS', '2000'),
            'check_timeout' => (int) $env('PROXY_CHECK_TIMEOUT', '12'),
            'bad_asn' => $env('PROXY_BAD_ASN', ''),
        ],
        'root' => $root,
        'governor' => [
            'max_chrome' => (int) $env('MAX_CHROME', '40'),
            'cpu_threshold' => (float) $env('GOVERNOR_CPU_PCT', '80'),
            'ram_threshold' => (float) $env('GOVERNOR_RAM_PCT', '90'),
            'account_lock_ttl' => (int) $env('ACCOUNT_LOCK_TTL', '600'),
            'proxy_lock_ttl' => (int) $env('PROXY_LOCK_TTL', '120'),
            'night_start' => $env('NIGHT_WINDOW_START', '23:00'),
            'night_end' => $env('NIGHT_WINDOW_END', '07:00'),
            'retry_base_sec' => (int) $env('RETRY_BASE_SEC', '30'),
            'retry_max_sec' => (int) $env('RETRY_MAX_SEC', '900'),
            'enqueue_batch' => (int) $env('SCHED_ENQUEUE_BATCH', '50'),
            'account_limit' => (int) $env('ADMIT_ACCOUNT_LIMIT', '1'),
            'proxy_limit' => (int) $env('ADMIT_PROXY_LIMIT', '1'),
            'admit_backoff_sec' => (int) $env('ADMIT_BACKOFF_SEC', '15'),
            'stale_grace_sec' => (int) $env('GC_STALE_GRACE_SEC', '60'),
            'kill_orphan_chrome' => in_array(
                strtolower((string) $env('GC_KILL_ORPHAN_CHROME', '1')),
                ['1', 'true', 'yes', 'on'],
                true
            ),
        ],
        'telegram' => [
            'token' => $env('TG_TOKEN', ''),
            'chat_id' => $env('TG_CHAT_ID', ''),
            'dedupe_sec' => (int) $env('TG_DEDUPE_SEC', '300'),
        ],
        'monitor' => [
            'interval_sec' => (int) $env('MONITOR_INTERVAL_SEC', '15'),
            'proxy_dead_pct' => (float) $env('MONITOR_PROXY_DEAD_PCT', '20'),
            'captcha_window_sec' => (int) $env('MONITOR_CAPTCHA_WINDOW_SEC', '300'),
            'captcha_threshold' => (int) $env('MONITOR_CAPTCHA_THRESHOLD', '5'),
            'cpu_threshold' => (float) $env('MONITOR_CPU_PCT', $env('GOVERNOR_CPU_PCT', '80')),
            'ram_threshold' => (float) $env('MONITOR_RAM_PCT', $env('GOVERNOR_RAM_PCT', '90')),
            'disk_threshold' => (float) $env('MONITOR_DISK_PCT', '95'),
        ],
        'captcha' => [
            'provider' => $env('CAPTCHA_PROVIDER', '2captcha'),
            'api_key' => $env('CAPTCHA_KEY', ''),
            'token_url' => $env('CAPTCHA_TOKEN_URL', ''),
        ],
        'sms' => [
            'provider' => $env('SMS_PROVIDER', 'sms-activate'),
            'api_key' => $env('SMS_KEY', ''),
            'api_url' => $env('SMS_API_URL', ''),
            'service' => $env('SMS_SERVICE', 'ya'),
            'country' => $env('SMS_COUNTRY', '0'),
            'rent_minutes' => (int) $env('SMS_RENT_MINUTES', '20'),
            'poll_interval_sec' => (int) $env('SMS_POLL_INTERVAL_SEC', '5'),
            'wait_timeout_sec' => (int) $env('SMS_WAIT_TIMEOUT_SEC', '180'),
        ],
        'profile_migration' => [
            'ssh_key' => $env('PROFILE_SSH_KEY', ''),
            'ssh_user' => $env('PROFILE_SSH_USER', 'farm'),
            'ssh_port' => (int) $env('PROFILE_SSH_PORT', '22'),
            'remote_profiles_root' => $env('PROFILE_REMOTE_ROOT', '/opt/farm/storage/profiles'),
            'max_concurrent' => (int) $env('PROFILE_MIGRATE_MAX', '2'),
            'staging_dir' => $env(
                'PROFILE_MIGRATE_STAGING',
                $root . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'migrate'
            ),
        ],
    ];
})();
