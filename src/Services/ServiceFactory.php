<?php

declare(strict_types=1);

namespace Farm\Services;

use Farm\Db\Connection;
use Farm\Jobs\GcJob;
use Farm\Jobs\MetricsJob;
use Farm\Jobs\ProfileMigrationJob;
use Farm\Jobs\ProxyHealthJob;
use Farm\Repositories\ProfileMigrationRepository;
use Farm\Services\ProfileMigration\LocalFsTransport;
use Farm\Services\ProfileMigration\ProfileTransport;
use Farm\Services\ProfileMigration\SshRsyncTransport;
use Farm\Repositories\AccountFingerprintRepository;
use Farm\Repositories\AccountRepository;
use Farm\Repositories\AccountSessionRepository;
use Farm\Repositories\AlertRepository;
use Farm\Repositories\ApiTokenRepository;
use Farm\Repositories\AuditLogRepository;
use Farm\Repositories\BlacklistRepository;
use Farm\Repositories\MetricRepository;
use Farm\Repositories\NodeRepository;
use Farm\Repositories\PermissionRepository;
use Farm\Repositories\PhoneRepository;
use Farm\Repositories\ProxyAssignmentRepository;
use Farm\Repositories\ProxyRepository;
use Farm\Repositories\RoleRepository;
use Farm\Repositories\ScenarioRepository;
use Farm\Repositories\ScenarioScheduleRepository;
use Farm\Repositories\SettingRepository;
use Farm\Repositories\TaskRepository;
use Farm\Repositories\UserRepository;
use Farm\Support\Crypto;
use Farm\Support\DistributedLock;
use Farm\Support\MemoryRedis;
use Farm\Support\RedisClient;
use Throwable;

/**
 * Lightweight wiring for CLI / jobs / HTTP (no full DI container).
 */
final class ServiceFactory
{
    private RedisClient|MemoryRedis|null $redis = null;
    private ?DistributedLock $locks = null;
    private ?Locks $accountLocks = null;
    private ?Crypto $crypto = null;
    private ?Governor $governor = null;
    private ?Admission $admission = null;
    private ?RetryPolicy $retryPolicy = null;
    private ?Scheduler $scheduler = null;
    private ?Auth $auth = null;
    private ?Rbac $rbac = null;
    private ?Webhooks $webhooks = null;
    private ?Telegram $telegram = null;
    private ?AlertEngine $alertEngine = null;
    private ?LiveView $liveView = null;
    private ?SmsProvider $smsProvider = null;
    private ?TaskQueueBuilder $taskQueueBuilder = null;
    private ?NodeRegistry $nodeRegistry = null;
    private ?SessionRouter $sessionRouter = null;

    /**
     * @param array<string, mixed> $config from app/config.php
     */
    public function __construct(
        private readonly Connection $db,
        private readonly array $config = [],
    ) {
    }

    public function db(): Connection
    {
        return $this->db;
    }

    /**
     * Dot-path config lookup, e.g. api.rate_limit or governor.max_chrome.
     */
    public function configValue(string $path, mixed $default = null): mixed
    {
        $parts = explode('.', $path);
        $cur = $this->config;
        foreach ($parts as $part) {
            if (!is_array($cur) || !array_key_exists($part, $cur)) {
                return $default;
            }
            $cur = $cur[$part];
        }

        return $cur;
    }

    public function rbac(): Rbac
    {
        return $this->rbac ??= new Rbac(
            new PermissionRepository($this->db),
            new RoleRepository($this->db),
            true,
        );
    }

    public function auth(): Auth
    {
        return $this->auth ??= new Auth(
            new UserRepository($this->db),
            new RoleRepository($this->db),
            new ApiTokenRepository($this->db),
            $this->settingRepository(),
            (string) ($this->config['app_key'] ?? $this->config['internal_token'] ?? 'farm-dev-insecure-key'),
            $this->rbac(),
        );
    }

    public function webhooks(): Webhooks
    {
        return $this->webhooks ??= new Webhooks(
            $this->settingRepository(),
            (string) ($this->config['app_key'] ?? ''),
            $this->safeRedis(),
        );
    }

    public function auditLogRepository(): AuditLogRepository
    {
        return new AuditLogRepository($this->db);
    }

    public function userRepository(): UserRepository
    {
        return new UserRepository($this->db);
    }

    public function apiTokenRepository(): ApiTokenRepository
    {
        return new ApiTokenRepository($this->db);
    }

    private function safeRedis(): RedisClient|MemoryRedis|null
    {
        try {
            return $this->redis();
        } catch (\Throwable) {
            return null;
        }
    }

    public function crypto(): Crypto
    {
        return $this->crypto ??= new Crypto(
            isset($this->config['app_key']) ? (string) $this->config['app_key'] : (
                isset($this->config['internal_token']) ? (string) $this->config['internal_token'] : null
            )
        );
    }

    public function redis(): RedisClient|MemoryRedis
    {
        if ($this->redis === null) {
            if (!empty($this->config['redis_memory']) || (($this->config['redis']['driver'] ?? null) === 'memory')) {
                $this->redis = new MemoryRedis();
            } else {
                $r = $this->config['redis'] ?? [];
                $this->redis = new RedisClient([
                    'host' => $r['host'] ?? '127.0.0.1',
                    'port' => (int) ($r['port'] ?? 6379),
                    'password' => ($r['password'] ?? null) !== '' ? ($r['password'] ?? null) : null,
                    'database' => (int) ($r['database'] ?? 0),
                    'timeout' => (float) ($r['timeout'] ?? 2.0),
                ]);
            }
        }

        return $this->redis;
    }

    /** Test / offline: inject MemoryRedis before first redis() call. */
    public function useMemoryRedis(?MemoryRedis $redis = null): void
    {
        $this->redis = $redis ?? new MemoryRedis();
        $this->locks = null;
        $this->accountLocks = null;
        $this->governor = null;
        $this->admission = null;
        $this->scheduler = null;
        $this->taskQueueBuilder = null;
        $this->nodeRegistry = null;
        $this->sessionRouter = null;
        $this->liveView = null;
        $this->alertEngine = null;
    }

    public function locks(): DistributedLock
    {
        return $this->locks ??= new DistributedLock($this->redis());
    }

    public function accountLocks(): Locks
    {
        if ($this->accountLocks === null) {
            $g = $this->config['governor'] ?? [];
            $this->accountLocks = new Locks(
                $this->redis(),
                $this->locks(),
                (int) ($g['account_lock_ttl'] ?? Locks::DEFAULT_ACCOUNT_TTL),
                (int) ($g['proxy_lock_ttl'] ?? Locks::DEFAULT_PROXY_TTL),
            );
        }

        return $this->accountLocks;
    }

    public function taskRepository(): TaskRepository
    {
        return new TaskRepository($this->db);
    }

    public function settingRepository(): SettingRepository
    {
        $repo = new SettingRepository($this->db);
        try {
            $repo->setRedis($this->redis());
        } catch (Throwable) {
            // Redis optional at boot
        }

        return $repo;
    }

    public function scenarioScheduleRepository(): ScenarioScheduleRepository
    {
        return new ScenarioScheduleRepository($this->db);
    }

    public function scenarioRepository(): ScenarioRepository
    {
        return new ScenarioRepository($this->db);
    }

    public function nightWindow(): NightWindow
    {
        $g = $this->config['governor'] ?? [];

        return new NightWindow(
            $this->settingRepository(),
            (string) ($g['night_start'] ?? NightWindow::DEFAULT_START),
            (string) ($g['night_end'] ?? NightWindow::DEFAULT_END),
        );
    }

    public function taskQueueBuilder(): TaskQueueBuilder
    {
        return $this->taskQueueBuilder ??= new TaskQueueBuilder(
            $this->accountRepository(),
            $this->proxyRepository(),
            $this->accountFingerprintRepository(),
            $this->crypto(),
            $this->fingerprintGenerator(),
            $this->admission(),
            $this->taskRepository(),
            $this->redis(),
        );
    }

    public function admission(): Admission
    {
        if ($this->admission === null) {
            $g = $this->config['governor'] ?? [];
            $this->admission = new Admission(
                $this->governor(),
                $this->taskRepository(),
                $this->accountRepository(),
                $this->accountLocks(),
                $this->redis(),
                (int) ($g['account_limit'] ?? Admission::DEFAULT_ACCOUNT_LIMIT),
                (int) ($g['proxy_limit'] ?? Admission::DEFAULT_PROXY_LIMIT),
                (int) ($g['admit_backoff_sec'] ?? Admission::DEFAULT_BACKOFF_SEC),
            );
        }

        return $this->admission;
    }

    public function retryPolicy(): RetryPolicy
    {
        if ($this->retryPolicy === null) {
            $g = $this->config['governor'] ?? [];
            $this->retryPolicy = new RetryPolicy(
                $this->taskRepository(),
                (int) ($g['retry_base_sec'] ?? 30),
                (int) ($g['retry_max_sec'] ?? 900),
            );
        }

        return $this->retryPolicy;
    }

    public function governor(): Governor
    {
        if ($this->governor === null) {
            $g = $this->config['governor'] ?? [];
            $this->governor = new Governor(
                $this->redis(),
                $this->taskRepository(),
                $this->settingRepository(),
                (int) ($g['max_chrome'] ?? Governor::DEFAULT_MAX_CHROME),
                (float) ($g['cpu_threshold'] ?? Governor::DEFAULT_CPU_PCT),
                (float) ($g['ram_threshold'] ?? Governor::DEFAULT_RAM_PCT),
            );
        }

        return $this->governor;
    }

    public function scheduler(): Scheduler
    {
        if ($this->scheduler === null) {
            $this->scheduler = new Scheduler(
                $this->scenarioScheduleRepository(),
                $this->scenarioRepository(),
                $this->accountRepository(),
                $this->accountFingerprintRepository(),
                $this->taskRepository(),
                $this->redis(),
                $this->governor(),
                $this->accountLocks(),
                $this->retryPolicy(),
                $this->nightWindow(),
                (int) (($this->config['governor']['enqueue_batch'] ?? 50)),
                $this->taskQueueBuilder(),
                $this->admission(),
                $this->nodeRegistry(),
            );
        }

        return $this->scheduler;
    }

    public function nodeRepository(): NodeRepository
    {
        return new NodeRepository($this->db);
    }

    public function nodeRegistry(): NodeRegistry
    {
        if ($this->nodeRegistry === null) {
            $this->nodeRegistry = new NodeRegistry(
                $this->nodeRepository(),
                $this->accountRepository(),
                $this->safeRedis(),
                $this->alertRepository(),
                (string) ($this->config['worker_url'] ?? 'http://127.0.0.1:3000'),
            );
        }

        return $this->nodeRegistry;
    }

    public function sessionRouter(): SessionRouter
    {
        return $this->sessionRouter ??= new SessionRouter(
            $this->nodeRegistry(),
            (string) ($this->config['worker_url'] ?? 'http://127.0.0.1:3000'),
        );
    }

    public function gcJob(): GcJob
    {
        $root = (string) ($this->config['root'] ?? dirname(__DIR__, 2));
        $profiles = $root . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'profiles';
        $g = $this->config['governor'] ?? [];

        return new GcJob(
            $this->taskRepository(),
            $this->retryPolicy(),
            $this->accountLocks(),
            $this->redis(),
            $profiles,
            (int) ($g['stale_grace_sec'] ?? 60),
            (bool) ($g['kill_orphan_chrome'] ?? true),
        );
    }

    public function profileMigrationRepository(): ProfileMigrationRepository
    {
        return new ProfileMigrationRepository($this->db);
    }

    /**
     * @param ProfileTransport|null $transport inject LocalFsTransport in tests
     */
    public function profileMigrationJob(?ProfileTransport $transport = null): ProfileMigrationJob
    {
        $root = (string) ($this->config['root'] ?? dirname(__DIR__, 2));
        $pm = $this->config['profile_migration'] ?? [];
        $staging = (string) ($pm['staging_dir'] ?? ($root . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'migrate'));
        $max = (int) ($pm['max_concurrent'] ?? ProfileMigrationJob::DEFAULT_MAX_CONCURRENT);

        if ($transport === null) {
            $sshKey = (string) ($pm['ssh_key'] ?? '');
            if ($sshKey !== '') {
                $transport = new SshRsyncTransport(
                    $sshKey,
                    (string) ($pm['ssh_user'] ?? 'farm'),
                    (string) ($pm['remote_profiles_root'] ?? '/opt/farm/storage/profiles'),
                    (int) ($pm['ssh_port'] ?? 22),
                );
            } else {
                // Without PROFILE_SSH_KEY require inject LocalFsTransport or nodes.meta.profiles_path.
                $transport = new LocalFsTransport([], $staging);
            }
        }

        return new ProfileMigrationJob(
            $this->accountRepository(),
            $this->nodeRepository(),
            $this->profileMigrationRepository(),
            $this->accountLocks(),
            $this->nodeRegistry(),
            $transport,
            $staging,
            max(1, $max),
            (int) ($this->config['governor']['account_lock_ttl'] ?? 600),
        );
    }

    public function proxyRepository(): ProxyRepository
    {
        return new ProxyRepository($this->db);
    }

    public function proxyAssignmentRepository(): ProxyAssignmentRepository
    {
        return new ProxyAssignmentRepository($this->db);
    }

    public function accountRepository(): AccountRepository
    {
        return new AccountRepository($this->db);
    }

    public function geoMapper(): GeoMapper
    {
        return new GeoMapper();
    }

    public function accountFingerprintRepository(): AccountFingerprintRepository
    {
        return new AccountFingerprintRepository($this->db);
    }

    public function fingerprintGenerator(): FingerprintGenerator
    {
        return new FingerprintGenerator($this->geoMapper());
    }

    public function fingerprintValidator(): FingerprintValidator
    {
        return new FingerprintValidator($this->geoMapper());
    }

    public function proxyChecker(): ProxyChecker
    {
        return new ProxyChecker($this->proxyRepository(), $this->crypto());
    }

    public function proxyPool(): ProxyPool
    {
        return new ProxyPool(
            $this->db,
            $this->proxyRepository(),
            $this->proxyAssignmentRepository(),
            $this->accountRepository(),
            $this->locks(),
            $this->geoMapper(),
            new AccountFingerprintRepository($this->db),
        );
    }

    public function proxyHealthJob(bool $autoRotate = true): ProxyHealthJob
    {
        return new ProxyHealthJob(
            $this->proxyChecker(),
            $this->proxyRepository(),
            new BlacklistRepository($this->db),
            $autoRotate ? $this->proxyPool() : null,
            null,
            $autoRotate,
        );
    }

    public function metricRepository(): MetricRepository
    {
        return new MetricRepository($this->db);
    }

    public function alertRepository(): AlertRepository
    {
        return new AlertRepository($this->db);
    }

    public function telegram(): Telegram
    {
        if ($this->telegram === null) {
            $tg = $this->config['telegram'] ?? [];
            $this->telegram = new Telegram(
                $this->settingRepository(),
                $this->safeRedis(),
                (string) ($tg['token'] ?? ''),
                (string) ($tg['chat_id'] ?? ''),
                (int) ($tg['dedupe_sec'] ?? 300),
            );
        }

        return $this->telegram;
    }

    public function alertEngine(): AlertEngine
    {
        if ($this->alertEngine === null) {
            $m = $this->config['monitor'] ?? [];
            $g = $this->config['governor'] ?? [];
            $this->alertEngine = new AlertEngine(
                $this->alertRepository(),
                $this->telegram(),
                $this->proxyRepository(),
                $this->governor(),
                $this->redis(),
                $this->db,
                [
                    'proxy_dead_pct' => (float) ($m['proxy_dead_pct'] ?? 20),
                    'captcha_window_sec' => (int) ($m['captcha_window_sec'] ?? 300),
                    'captcha_threshold' => (int) ($m['captcha_threshold'] ?? 5),
                    'cpu_threshold' => (float) ($m['cpu_threshold'] ?? $g['cpu_threshold'] ?? 80),
                    'ram_threshold' => (float) ($m['ram_threshold'] ?? $g['ram_threshold'] ?? 90),
                    'disk_threshold' => (float) ($m['disk_threshold'] ?? 95),
                ],
            );
        }

        return $this->alertEngine;
    }

    public function metricsJob(): MetricsJob
    {
        $root = (string) ($this->config['root'] ?? dirname(__DIR__, 2));
        $diskPath = PHP_OS_FAMILY === 'Windows'
            ? (getenv('SystemDrive') ?: 'C:') . '\\'
            : '/';

        return new MetricsJob(
            $this->metricRepository(),
            $this->governor(),
            $this->safeRedis(),
            is_dir($root) ? $root : $diskPath,
        );
    }

    public function accountSessionRepository(): AccountSessionRepository
    {
        return new AccountSessionRepository($this->db);
    }

    public function liveView(): LiveView
    {
        if ($this->liveView === null) {
            $idle = (int) ($this->config['live_view_idle_sec'] ?? LiveView::DEFAULT_IDLE_SEC);
            $redis = $this->safeRedis();
            $this->liveView = new LiveView(
                $this->accountRepository(),
                $this->accountSessionRepository(),
                $redis instanceof RedisClient ? $redis : null,
                (string) ($this->config['worker_url'] ?? 'http://127.0.0.1:3000'),
                (string) ($this->config['internal_token'] ?? ''),
                (int) ($this->config['vnc_base_port'] ?? 5900),
                max(60, $idle),
                30,
                $this->sessionRouter(),
            );
        }

        return $this->liveView;
    }

    public function phoneRepository(): PhoneRepository
    {
        return new PhoneRepository($this->db);
    }

    public function smsProvider(?callable $httpClient = null): SmsProvider
    {
        if ($httpClient !== null) {
            $sms = $this->config['sms'] ?? [];

            return new SmsProvider(
                $this->phoneRepository(),
                $this->accountRepository(),
                [
                    'provider' => (string) ($sms['provider'] ?? 'sms-activate'),
                    'api_key' => (string) ($sms['api_key'] ?? ''),
                    'api_url' => (string) ($sms['api_url'] ?? ''),
                    'service' => (string) ($sms['service'] ?? SmsProvider::DEFAULT_SERVICE),
                    'country' => (string) ($sms['country'] ?? SmsProvider::DEFAULT_COUNTRY),
                    'rent_minutes' => (int) ($sms['rent_minutes'] ?? SmsProvider::DEFAULT_RENT_MINUTES),
                    'poll_interval_sec' => (int) ($sms['poll_interval_sec'] ?? 5),
                    'wait_timeout_sec' => (int) ($sms['wait_timeout_sec'] ?? 180),
                ],
                $httpClient,
            );
        }

        if ($this->smsProvider === null) {
            $sms = $this->config['sms'] ?? [];
            $this->smsProvider = new SmsProvider(
                $this->phoneRepository(),
                $this->accountRepository(),
                [
                    'provider' => (string) ($sms['provider'] ?? 'sms-activate'),
                    'api_key' => (string) ($sms['api_key'] ?? ''),
                    'api_url' => (string) ($sms['api_url'] ?? ''),
                    'service' => (string) ($sms['service'] ?? SmsProvider::DEFAULT_SERVICE),
                    'country' => (string) ($sms['country'] ?? SmsProvider::DEFAULT_COUNTRY),
                    'rent_minutes' => (int) ($sms['rent_minutes'] ?? SmsProvider::DEFAULT_RENT_MINUTES),
                    'poll_interval_sec' => (int) ($sms['poll_interval_sec'] ?? 5),
                    'wait_timeout_sec' => (int) ($sms['wait_timeout_sec'] ?? 180),
                ],
            );
        }

        return $this->smsProvider;
    }
}
