<?php

declare(strict_types=1);

namespace Farm\Services\ProfileMigration;

/**
 * Moves profile archives between node-local disks (no NFS primary store).
 */
interface ProfileTransport
{
    /**
     * Absolute profiles root as seen for this node (local path or SSH remote root).
     *
     * @param array<string, mixed> $node
     */
    public function profilesRoot(array $node): string;

    /**
     * Ensure profiles root exists on the node.
     *
     * @param array<string, mixed> $node
     */
    public function ensureProfilesRoot(array $node): void;

    /**
     * Whether account profile directory exists and is readable on the node.
     *
     * @param array<string, mixed> $node
     */
    public function profileExists(array $node, int $accountId): bool;

    /**
     * Pack profile dir into a local .tar.gz snapshot. Returns absolute archive path.
     *
     * @param array<string, mixed> $node
     */
    public function snapshot(array $node, int $accountId, string $localArchivePath): string;

    /**
     * Push local archive to node staging (rsync / local copy).
     *
     * @param array<string, mixed> $node
     */
    public function pushArchive(array $node, string $localArchivePath, string $remoteFileName): void;

    /**
     * Extract archive into profiles/{accountId} on the node (overwrite merge).
     *
     * @param array<string, mixed> $node
     */
    public function extractArchive(array $node, string $remoteFileName, int $accountId): void;

    /**
     * Remove Chrome singleton / lock leftovers under the profile on the node.
     *
     * @param array<string, mixed> $node
     * @param list<string> $names
     */
    public function removeSingletonFiles(array $node, int $accountId, array $names): int;
}
