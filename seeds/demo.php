<?php

declare(strict_types=1);

use Farm\Db\Connection;
use Farm\Repositories\AccountRepository;
use Farm\Repositories\PermissionRepository;
use Farm\Repositories\ProxyRepository;
use Farm\Repositories\RoleRepository;
use Farm\Repositories\ScenarioRepository;
use Farm\Repositories\ScenarioStepRepository;
use Farm\Repositories\TaskRepository;
use Farm\Repositories\UserRepository;

/**
 * Idempotent demo seeder.
 *
 * @param array{
 *     accounts?: int,
 *     proxies?: int,
 *     scenarios?: int,
 *     tasks?: int
 * } $options
 * @return array<string, int|string>
 */
return static function (Connection $db, array $options = []): array {
    $accountCount = (int) ($options['accounts'] ?? 100);
    $proxyCount = (int) ($options['proxies'] ?? 20);
    $scenarioCount = (int) ($options['scenarios'] ?? 3);
    $taskCount = (int) ($options['tasks'] ?? 50);

    $roles = new RoleRepository($db);
    $permissions = new PermissionRepository($db);
    $users = new UserRepository($db);
    $proxies = new ProxyRepository($db);
    $accounts = new AccountRepository($db);
    $scenarios = new ScenarioRepository($db);
    $steps = new ScenarioStepRepository($db);
    $tasks = new TaskRepository($db);

    $created = [
        'roles' => 0,
        'permissions' => 0,
        'users' => 0,
        'proxies' => 0,
        'accounts' => 0,
        'scenarios' => 0,
        'scenario_steps' => 0,
        'tasks' => 0,
    ];

    // --- roles ---
    $roleDefs = [
        'admin' => 'Full system access',
        'manager' => 'Operational management',
        'viewer' => 'Read-only access',
    ];
    $roleIds = [];
    foreach ($roleDefs as $name => $desc) {
        $before = $roles->findByName($name);
        $roleIds[$name] = $roles->ensure($name, $desc);
        if ($before === null) {
            $created['roles']++;
        }
    }

    // --- permissions for admin ---
    $resources = [
        'accounts', 'proxies', 'scenarios', 'tasks', 'campaigns',
        'keywords', 'targets', 'users', 'roles', 'settings',
    ];
    $actions = ['read', 'write', 'delete', 'run'];
    foreach ($resources as $resource) {
        foreach ($actions as $action) {
            $existing = $permissions->findBy([
                'role_id' => $roleIds['admin'],
                'resource' => $resource,
                'action' => $action,
            ]);
            if ($existing === null) {
                $permissions->grant($roleIds['admin'], $resource, $action);
                $created['permissions']++;
            }
        }
    }
    // manager: read/write/run; viewer: read
    foreach ($resources as $resource) {
        foreach (['read', 'write', 'run'] as $action) {
            $existing = $permissions->findBy([
                'role_id' => $roleIds['manager'],
                'resource' => $resource,
                'action' => $action,
            ]);
            if ($existing === null) {
                $permissions->grant($roleIds['manager'], $resource, $action);
                $created['permissions']++;
            }
        }
        $existing = $permissions->findBy([
            'role_id' => $roleIds['viewer'],
            'resource' => $resource,
            'action' => 'read',
        ]);
        if ($existing === null) {
            $permissions->grant($roleIds['viewer'], $resource, 'read');
            $created['permissions']++;
        }
    }

    // --- admin user (exactly 1) ---
    $adminEmail = 'admin@farm.local';
    if ($users->findByEmail($adminEmail) === null) {
        $users->create([
            'role_id' => $roleIds['admin'],
            'email' => $adminEmail,
            'password_hash' => password_hash('admin123!', PASSWORD_DEFAULT),
            'name' => 'Farm Admin',
            'is_active' => 1,
        ]);
        $created['users']++;
    }

    // --- proxies ---
    $countries = ['RU', 'DE', 'NL', 'US', 'KZ', 'UA', 'PL', 'TR'];
    $protocols = ['http', 'socks5'];
    $healths = ['ok', 'slow', 'unknown'];
    $proxyIds = [];
    for ($i = 1; $i <= $proxyCount; $i++) {
        $host = sprintf('proxy%03d.demo.farm', $i);
        $port = 10000 + $i;
        $protocol = $protocols[$i % 2];
        $existing = $proxies->findByHostPortProtocol($host, $port, $protocol);
        if ($existing !== null) {
            $proxyIds[] = (int) $existing['id'];
            continue;
        }
        $id = $proxies->create([
            'host' => $host,
            'port' => $port,
            'protocol' => $protocol,
            'username' => 'user' . $i,
            'password_enc' => base64_encode('proxy-secret-' . $i),
            'country' => $countries[$i % count($countries)],
            'health' => $healths[$i % count($healths)],
            'latency_ms' => 50 + ($i * 7) % 400,
            'is_active' => 1,
            'meta' => ['seed' => true, 'index' => $i],
        ]);
        $proxyIds[] = $id;
        $created['proxies']++;
    }

    // --- accounts ---
    $statuses = ['new', 'warming', 'ready', 'active', 'paused', 'banned', 'error'];
    $accountIds = [];
    for ($i = 1; $i <= $accountCount; $i++) {
        $login = sprintf('account%03d@demo.farm', $i);
        $existing = $accounts->findByLogin($login);
        if ($existing !== null) {
            $accountIds[] = (int) $existing['id'];
            continue;
        }
        $proxyId = $proxyIds[($i - 1) % count($proxyIds)] ?? null;
        $id = $accounts->create([
            'login' => $login,
            'password_enc' => base64_encode('acc-password-' . $i),
            'status' => $statuses[($i - 1) % count($statuses)],
            'trust_score' => number_format(($i % 100) + ($i % 10) / 10, 2, '.', ''),
            'phone' => $i % 3 === 0 ? null : sprintf('+7900%07d', $i),
            'group_id' => (int) ceil($i / 10),
            'tags' => ['seed', 'group-' . (int) ceil($i / 10)],
            'notes' => 'Demo account #' . $i,
            'proxy_id' => $proxyId,
            'profile_path' => 'storage/profiles/account_' . $i,
            'last_activity' => null,
        ]);
        $accountIds[] = $id;
        $created['accounts']++;
    }

    // --- scenarios ---
    $scenarioDefs = [
        ['name' => 'Warmup Basic', 'type' => 'warmup', 'version' => 1],
        ['name' => 'PF Desktop', 'type' => 'pf', 'version' => 1],
        ['name' => 'Register Flow', 'type' => 'register', 'version' => 1],
        ['name' => 'Service Check', 'type' => 'service', 'version' => 1],
    ];
    $scenarioDefs = array_slice($scenarioDefs, 0, max(1, $scenarioCount));
    $scenarioIds = [];
    foreach ($scenarioDefs as $idx => $def) {
        $existing = $scenarios->findByNameVersion($def['name'], $def['version']);
        if ($existing !== null) {
            $scenarioIds[] = (int) $existing['id'];
            continue;
        }
        $id = $scenarios->create([
            'name' => $def['name'],
            'type' => $def['type'],
            'description' => 'Seed scenario: ' . $def['name'],
            'version' => $def['version'],
            'is_active' => 1,
            'defaults' => [
                'device' => 'desktop',
                'limits' => [
                    'max_duration_sec' => 300,
                    'max_pages' => 5,
                ],
            ],
        ]);
        $scenarioIds[] = $id;
        $created['scenarios']++;

        $stepDefs = [
            ['step_order' => 1, 'action' => 'navigate', 'params' => ['url' => 'https://example.com']],
            ['step_order' => 2, 'action' => 'wait', 'params' => ['sec' => 2]],
            ['step_order' => 3, 'action' => 'scroll', 'params' => ['times' => 3]],
        ];
        foreach ($stepDefs as $step) {
            $steps->create([
                'scenario_id' => $id,
                'step_order' => $step['step_order'],
                'action' => $step['action'],
                'params' => $step['params'],
                'timeout_sec' => 60,
                'on_fail' => 'abort',
            ]);
            $created['scenario_steps']++;
        }
        unset($idx);
    }

    // --- tasks (idempotent by counting existing seed tasks via payload marker) ---
    $existingSeedTasks = $db->fetchOne(
        "SELECT COUNT(*) AS `c` FROM `tasks` WHERE JSON_CONTAINS(`payload`, 'true', '$.seed')"
    );
    $have = (int) ($existingSeedTasks['c'] ?? 0);
    $need = max(0, $taskCount - $have);
    $taskTypes = ['register', 'warmup', 'pf', 'service', 'check'];
    $taskStatuses = ['pending', 'queued', 'running', 'done', 'failed', 'retrying', 'cancelled'];

    for ($i = 0; $i < $need; $i++) {
        $n = $have + $i + 1;
        $accountId = $accountIds[($n - 1) % count($accountIds)];
        $scenarioId = $scenarioIds[($n - 1) % count($scenarioIds)];
        $type = $taskTypes[($n - 1) % count($taskTypes)];
        $payload = [
            'type' => $type,
            'account_id' => $accountId,
            'scenario_id' => $scenarioId,
            'target_url' => 'https://example.com/page/' . $n,
            'keyword' => 'demo query ' . $n,
            'geo' => 'ru',
            'device' => 'desktop',
            'limits' => ['max_duration_sec' => 180],
            'seed' => true,
            'seed_index' => $n,
        ];
        $tasks->create([
            'type' => $type,
            'priority' => $n % 10,
            'status' => $taskStatuses[($n - 1) % count($taskStatuses)],
            'account_id' => $accountId,
            'scenario_id' => $scenarioId,
            'payload' => $payload,
            'attempts' => 0,
            'max_attempts' => 3,
            'scheduled_at' => null,
            'timeout' => 300,
        ]);
        $created['tasks']++;
    }

    return [
        'roles_created' => $created['roles'],
        'permissions_created' => $created['permissions'],
        'users_created' => $created['users'],
        'proxies_created' => $created['proxies'],
        'proxies_total' => $proxies->count(),
        'accounts_created' => $created['accounts'],
        'accounts_total' => $accounts->count(),
        'scenarios_created' => $created['scenarios'],
        'scenarios_total' => $scenarios->count(),
        'scenario_steps_created' => $created['scenario_steps'],
        'tasks_created' => $created['tasks'],
        'tasks_total' => $tasks->count(),
        'admin_email' => $adminEmail,
    ];
};
