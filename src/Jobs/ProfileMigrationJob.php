<?php

declare(strict_types=1);

namespace Farm\Jobs;

use Farm\Repositories\AccountRepository;
use Farm\Repositories\NodeRepository;
use Farm\Repositories\ProfileMigrationRepository;
use Farm\Services\Locks;
use Farm\Services\NodeRegistry;
use Farm\Services\ProfileMigration\ProfileTransport;
use RuntimeException;
use Throwable;

/**
 * Move a Chrome profile between nodes without NFS and without losing session trust.
 *
 * Flow: farm:lock:acc → tar.gz snapshot → rsync/push → unpack → drop Singleton* →
 * rebind assigned_node_id → unlock. Marker in profile_migrations makes re-runs safe.
 */
final class ProfileMigrationJob
{
    public const DEFAULT_MAX_CONCURRENT = 2;

    public const SINGLETON_FILES = [
        'SingletonLock',
        'SingletonCookie',
        'SingletonSocket',
        'lockfile',
        'DevToolsActivePort',
    ];

    public function __construct(
        private readonly AccountRepository $accounts,
        private readonly NodeRepository $nodes,
        private readonly ProfileMigrationRepository $migrations,
        private readonly Locks $locks,
        private readonly NodeRegistry $registry,
        private readonly ProfileTransport $transport,
        private readonly string $stagingDir,
        private readonly int $maxConcurrent = self::DEFAULT_MAX_CONCURRENT,
        private readonly int $accountLockTtl = 900,
    ) {
        if (!is_dir($this->stagingDir) && !@mkdir($this->stagingDir, 0775, true) && !is_dir($this->stagingDir)) {
            throw new RuntimeException('Cannot create profile migration staging: ' . $this->stagingDir);
        }
    }

    /**
     * Drain offline-node accounts (trust_score DESC), at most M concurrent.
     *
     * @return array{
     *     migrated: int,
     *     skipped: int,
     *     failed: int,
     *     running_slots: int,
     *     results: list<array<string, mixed>>
     * }
     */
    public function run(?int $limit = null): array
    {
        $running = $this->migrations->countByStatus(ProfileMigrationRepository::STATUS_RUNNING);
        $slots = max(0, $this->maxConcurrent - $running);
        if ($limit !== null) {
            $slots = min($slots, max(0, $limit));
        }

        $results = [];
        $migrated = 0;
        $skipped = 0;
        $failed = 0;

        if ($slots === 0) {
            return [
                'migrated' => 0,
                'skipped' => 0,
                'failed' => 0,
                'running_slots' => $running,
                'results' => [],
            ];
        }

        $candidates = $this->migrations->findMigrationCandidates($slots);
        foreach ($candidates as $account) {
            $accountId = (int) $account['id'];
            $target = $this->registry->pickLeastLoaded();
            if ($target === null) {
                $results[] = [
                    'account_id' => $accountId,
                    'status' => 'skipped',
                    'reason' => 'no_alive_target',
                ];
                $skipped++;
                continue;
            }
            $toNodeId = (int) $target['id'];
            $fromNodeId = isset($account['assigned_node_id']) ? (int) $account['assigned_node_id'] : 0;
            if ($toNodeId === $fromNodeId) {
                $results[] = [
                    'account_id' => $accountId,
                    'status' => 'skipped',
                    'reason' => 'same_node',
                ];
                $skipped++;
                continue;
            }

            $one = $this->migrateAccount($accountId, $toNodeId);
            $results[] = $one;
            match ($one['status'] ?? '') {
                'done' => $migrated++,
                'skipped' => $skipped++,
                default => $failed++,
            };
        }

        return [
            'migrated' => $migrated,
            'skipped' => $skipped,
            'failed' => $failed,
            'running_slots' => $this->migrations->countByStatus(ProfileMigrationRepository::STATUS_RUNNING),
            'results' => $results,
        ];
    }

    /**
     * Migrate one account profile onto $toNodeId. Idempotent when already bound + done marker.
     *
     * @return array{
     *     account_id: int,
     *     status: string,
     *     migration_id?: int,
     *     from_node_id?: int|null,
     *     to_node_id?: int,
     *     checksum?: string|null,
     *     singletons_removed?: int,
     *     reason?: string,
     *     error?: string
     * }
     */
    public function migrateAccount(int $accountId, int $toNodeId): array
    {
        $account = $this->accounts->findById($accountId);
        if ($account === null) {
            return ['account_id' => $accountId, 'status' => 'failed', 'error' => 'account_not_found'];
        }

        $toNode = $this->nodes->findById($toNodeId);
        if ($toNode === null) {
            return ['account_id' => $accountId, 'status' => 'failed', 'error' => 'target_node_not_found'];
        }

        $fromNodeId = isset($account['assigned_node_id']) ? (int) $account['assigned_node_id'] : null;
        if ($fromNodeId !== null && $fromNodeId === $toNodeId) {
            $done = $this->migrations->findDoneForAccountNode($accountId, $toNodeId);
            if ($done !== null) {
                return [
                    'account_id' => $accountId,
                    'status' => 'skipped',
                    'reason' => 'already_migrated',
                    'migration_id' => (int) $done['id'],
                    'to_node_id' => $toNodeId,
                ];
            }
            // Same node but no done marker — treat as ensure-clean (singletons only) no-op skip
            // unless source profile must be re-synced; prefer skip to avoid clobber.
            return [
                'account_id' => $accountId,
                'status' => 'skipped',
                'reason' => 'already_on_target',
                'to_node_id' => $toNodeId,
            ];
        }

        if ($fromNodeId === null || $fromNodeId <= 0) {
            return ['account_id' => $accountId, 'status' => 'failed', 'error' => 'no_source_node'];
        }

        $fromNode = $this->nodes->findById($fromNodeId);
        if ($fromNode === null) {
            return ['account_id' => $accountId, 'status' => 'failed', 'error' => 'source_node_not_found'];
        }

        $running = $this->migrations->countByStatus(ProfileMigrationRepository::STATUS_RUNNING);
        $latest = $this->migrations->findLatestForAccount($accountId);
        $resumeId = null;
        if ($latest !== null && ($latest['status'] ?? '') === ProfileMigrationRepository::STATUS_RUNNING) {
            $resumeId = (int) $latest['id'];
        } elseif ($running >= $this->maxConcurrent) {
            return [
                'account_id' => $accountId,
                'status' => 'skipped',
                'reason' => 'max_concurrent',
                'to_node_id' => $toNodeId,
            ];
        }

        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s.v');
        if ($resumeId !== null) {
            $migrationId = $resumeId;
            $this->migrations->update($migrationId, [
                'to_node_id' => $toNodeId,
                'from_node_id' => $fromNodeId,
                'error' => null,
            ]);
        } else {
            $migrationId = $this->migrations->create([
                'account_id' => $accountId,
                'from_node_id' => $fromNodeId,
                'to_node_id' => $toNodeId,
                'status' => ProfileMigrationRepository::STATUS_RUNNING,
                'started_at' => $now,
            ]);
        }

        $token = $this->locks->tryAccount($accountId, $this->accountLockTtl);
        if ($token === null) {
            $this->migrations->update($migrationId, [
                'status' => ProfileMigrationRepository::STATUS_FAILED,
                'error' => 'account_locked',
                'finished_at' => $now,
            ]);

            return [
                'account_id' => $accountId,
                'status' => 'failed',
                'error' => 'account_locked',
                'migration_id' => $migrationId,
            ];
        }

        $archivePath = $this->stagingDir . DIRECTORY_SEPARATOR . 'acc-' . $accountId . '-' . $migrationId . '.tar.gz';
        $remoteName = 'acc-' . $accountId . '-' . $migrationId . '.tar.gz';

        try {
            if (!$this->transport->profileExists($fromNode, $accountId)) {
                throw new RuntimeException('source_profile_missing');
            }

            $this->transport->snapshot($fromNode, $accountId, $archivePath);
            $checksum = hash_file('sha256', $archivePath) ?: null;

            $this->transport->ensureProfilesRoot($toNode);
            $this->transport->pushArchive($toNode, $archivePath, $remoteName);
            $this->transport->extractArchive($toNode, $remoteName, $accountId);
            $removed = $this->transport->removeSingletonFiles($toNode, $accountId, self::SINGLETON_FILES);

            $profilePath = 'storage/profiles/' . $accountId;
            $this->accounts->update($accountId, [
                'assigned_node_id' => $toNodeId,
                'profile_path' => $profilePath,
                'status' => $this->rebindStatus((string) ($account['status'] ?? 'ready')),
            ]);

            $finished = (new \DateTimeImmutable())->format('Y-m-d H:i:s.v');
            $this->migrations->update($migrationId, [
                'status' => ProfileMigrationRepository::STATUS_DONE,
                'archive_checksum' => $checksum,
                'error' => null,
                'finished_at' => $finished,
            ]);

            return [
                'account_id' => $accountId,
                'status' => 'done',
                'migration_id' => $migrationId,
                'from_node_id' => $fromNodeId,
                'to_node_id' => $toNodeId,
                'checksum' => $checksum,
                'singletons_removed' => $removed,
            ];
        } catch (Throwable $e) {
            $finished = (new \DateTimeImmutable())->format('Y-m-d H:i:s.v');
            $this->migrations->update($migrationId, [
                'status' => ProfileMigrationRepository::STATUS_FAILED,
                'error' => mb_substr($e->getMessage(), 0, 2000),
                'finished_at' => $finished,
            ]);

            return [
                'account_id' => $accountId,
                'status' => 'failed',
                'migration_id' => $migrationId,
                'from_node_id' => $fromNodeId,
                'to_node_id' => $toNodeId,
                'error' => $e->getMessage(),
            ];
        } finally {
            $this->locks->releaseAccount($accountId, $token);
            if (is_file($archivePath)) {
                @unlink($archivePath);
            }
        }
    }

    private function rebindStatus(string $current): string
    {
        if ($current === 'error' || $current === '') {
            return 'ready';
        }

        return $current;
    }
}
