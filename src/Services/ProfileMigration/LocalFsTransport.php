<?php

declare(strict_types=1);

namespace Farm\Services\ProfileMigration;

use RuntimeException;

/**
 * Local two-directory transport for tests / same-host node simulation.
 * Maps node id → absolute profiles root; no SSH/NFS.
 */
final class LocalFsTransport implements ProfileTransport
{
    /**
     * @param array<int, string> $rootsByNodeId
     */
    public function __construct(
        private array $rootsByNodeId,
        private readonly string $stagingDir,
    ) {
        if (!is_dir($this->stagingDir) && !@mkdir($this->stagingDir, 0775, true) && !is_dir($this->stagingDir)) {
            throw new RuntimeException('Cannot create staging dir: ' . $this->stagingDir);
        }
    }

    public function setRoot(int $nodeId, string $absoluteRoot): void
    {
        $this->rootsByNodeId[$nodeId] = $absoluteRoot;
    }

    public function profilesRoot(array $node): string
    {
        $id = (int) ($node['id'] ?? 0);
        if ($id > 0 && isset($this->rootsByNodeId[$id]) && $this->rootsByNodeId[$id] !== '') {
            return rtrim($this->rootsByNodeId[$id], '/\\');
        }

        $meta = $this->meta($node);
        if (isset($meta['profiles_path']) && is_string($meta['profiles_path']) && $meta['profiles_path'] !== '') {
            return rtrim($meta['profiles_path'], '/\\');
        }

        throw new RuntimeException('LocalFsTransport: no profiles root for node ' . $id);
    }

    public function ensureProfilesRoot(array $node): void
    {
        $root = $this->profilesRoot($node);
        if (!is_dir($root) && !@mkdir($root, 0775, true) && !is_dir($root)) {
            throw new RuntimeException('Cannot create profiles root: ' . $root);
        }
    }

    public function profileExists(array $node, int $accountId): bool
    {
        $dir = $this->profileDir($node, $accountId);

        return is_dir($dir);
    }

    public function snapshot(array $node, int $accountId, string $localArchivePath): string
    {
        $dir = $this->profileDir($node, $accountId);
        if (!is_dir($dir)) {
            throw new RuntimeException("Profile missing for account {$accountId}: {$dir}");
        }

        $parent = dirname($localArchivePath);
        if (!is_dir($parent) && !@mkdir($parent, 0775, true) && !is_dir($parent)) {
            throw new RuntimeException('Cannot create archive parent: ' . $parent);
        }
        if (is_file($localArchivePath)) {
            @unlink($localArchivePath);
        }

        Archive::packDirectory($dir, $localArchivePath);

        return $localArchivePath;
    }

    public function pushArchive(array $node, string $localArchivePath, string $remoteFileName): void
    {
        if (!is_file($localArchivePath)) {
            throw new RuntimeException('Archive missing: ' . $localArchivePath);
        }
        $this->ensureProfilesRoot($node);
        $destDir = $this->profilesRoot($node) . DIRECTORY_SEPARATOR . '.migrate';
        if (!is_dir($destDir) && !@mkdir($destDir, 0775, true) && !is_dir($destDir)) {
            throw new RuntimeException('Cannot create migrate staging: ' . $destDir);
        }
        $dest = $destDir . DIRECTORY_SEPARATOR . $remoteFileName;
        if (!@copy($localArchivePath, $dest)) {
            throw new RuntimeException("Failed to copy archive to {$dest}");
        }
    }

    public function extractArchive(array $node, string $remoteFileName, int $accountId): void
    {
        $archive = $this->profilesRoot($node) . DIRECTORY_SEPARATOR . '.migrate' . DIRECTORY_SEPARATOR . $remoteFileName;
        if (!is_file($archive)) {
            throw new RuntimeException('Remote archive missing: ' . $archive);
        }

        $this->ensureProfilesRoot($node);
        $target = $this->profileDir($node, $accountId);
        if (!is_dir($target) && !@mkdir($target, 0775, true) && !is_dir($target)) {
            throw new RuntimeException('Cannot create target profile dir: ' . $target);
        }

        Archive::extractInto($archive, $target);
        @unlink($archive);
    }

    public function removeSingletonFiles(array $node, int $accountId, array $names): int
    {
        $dir = $this->profileDir($node, $accountId);
        if (!is_dir($dir)) {
            return 0;
        }
        $removed = 0;
        foreach ($names as $name) {
            $path = $dir . DIRECTORY_SEPARATOR . $name;
            if ((is_file($path) || is_link($path)) && @unlink($path)) {
                $removed++;
            }
        }

        return $removed;
    }

    /**
     * @param array<string, mixed> $node
     */
    private function profileDir(array $node, int $accountId): string
    {
        return $this->profilesRoot($node) . DIRECTORY_SEPARATOR . $accountId;
    }

    /**
     * @param array<string, mixed> $node
     * @return array<string, mixed>
     */
    private function meta(array $node): array
    {
        $meta = $node['meta'] ?? null;
        if (is_string($meta) && $meta !== '') {
            $decoded = json_decode($meta, true);

            return is_array($decoded) ? $decoded : [];
        }

        return is_array($meta) ? $meta : [];
    }
}
