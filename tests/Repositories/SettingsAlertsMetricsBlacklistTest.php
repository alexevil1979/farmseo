<?php

declare(strict_types=1);

namespace Farm\Tests\Repositories;

use Farm\Repositories\AlertRepository;
use Farm\Repositories\ApiTokenRepository;
use Farm\Repositories\AuditLogRepository;
use Farm\Repositories\BlacklistRepository;
use Farm\Repositories\MetricRepository;
use Farm\Repositories\RoleRepository;
use Farm\Repositories\SettingRepository;
use Farm\Repositories\UserRepository;
use Farm\Tests\TestCase;

final class SettingsAlertsMetricsBlacklistTest extends TestCase
{
    public function testSettingsUpsertAuditTokens(): void
    {
        $roles = new RoleRepository($this->db);
        $users = new UserRepository($this->db);
        $settings = new SettingRepository($this->db);
        $audit = new AuditLogRepository($this->db);
        $tokens = new ApiTokenRepository($this->db);

        $roleId = $roles->ensure('admin');
        $userId = $users->create([
            'role_id' => $roleId,
            'email' => 'ops@farm.test',
            'password_hash' => 'x',
            'name' => 'Ops',
        ]);

        $id1 = $settings->set('max_chrome', ['value' => 4], $userId);
        $id2 = $settings->set('max_chrome', ['value' => 8], $userId);
        self::assertSame($id1, $id2);
        $row = $settings->getByName('max_chrome');
        $decoded = is_string($row['value']) ? json_decode($row['value'], true) : $row['value'];
        self::assertSame(8, $decoded['value']);

        $aid = $audit->log($userId, 'settings.update', 'settings', $id1, '127.0.0.1', ['k' => 'max_chrome']);
        self::assertGreaterThan(0, $aid);

        $tid = $tokens->create([
            'user_id' => $userId,
            'name' => 'ci',
            'token_hash' => hash('sha256', 'secret-token'),
            'abilities' => ['tasks:read'],
        ]);
        self::assertNotNull($tokens->findByTokenHash(hash('sha256', 'secret-token')));
        self::assertTrue($tokens->revoke($tid));
        self::assertNotNull($tokens->findById($tid)['revoked_at']);
    }

    public function testMetricsAlertsBlacklist(): void
    {
        $roles = new RoleRepository($this->db);
        $users = new UserRepository($this->db);
        $metrics = new MetricRepository($this->db);
        $alerts = new AlertRepository($this->db);
        $bl = new BlacklistRepository($this->db);

        $roleId = $roles->ensure('admin');
        $userId = $users->create([
            'role_id' => $roleId,
            'email' => 'alert@farm.test',
            'password_hash' => 'x',
            'name' => 'A',
        ]);

        $mid = $metrics->record('account', 1, 'trust_score', '42.500000', 'score');
        self::assertGreaterThan(0, $mid);
        self::assertCount(1, $metrics->findByEntity('account', 1));

        $alertId = $alerts->create([
            'severity' => 'critical',
            'source' => 'proxy',
            'message' => 'proxy dead',
            'entity_type' => 'proxy',
            'entity_id' => 9,
        ]);
        self::assertCount(1, $alerts->findUnacked());
        self::assertTrue($alerts->ack($alertId, $userId));
        self::assertCount(0, $alerts->findUnacked());

        $bl->create([
            'kind' => 'domain',
            'value' => 'bad.example',
            'reason' => 'abuse',
            'is_active' => 1,
        ]);
        self::assertTrue($bl->isBlocked('domain', 'bad.example'));
        self::assertFalse($bl->isBlocked('domain', 'good.example'));
        self::assertNotNull($bl->findByKindValue('domain', 'bad.example'));
    }
}
