<?php

declare(strict_types=1);

namespace Farm\Services\ProfileMigration;

use RuntimeException;

/**
 * Production transport: snapshot via SSH tar stream / local pack after rsync pull,
 * push archive with rsync+SSH key from .env (PROFILE_SSH_KEY).
 */
final class SshRsyncTransport implements ProfileTransport
{
    public function __construct(
        private readonly string $sshKeyPath,
        private readonly string $sshUser = 'farm',
        private readonly string $defaultProfilesRoot = '/opt/farm/storage/profiles',
        private readonly int $sshPort = 22,
    ) {
        if ($this->sshKeyPath === '' || !is_readable($this->sshKeyPath)) {
            throw new RuntimeException('PROFILE_SSH_KEY missing or unreadable: ' . $this->sshKeyPath);
        }
    }

    public function profilesRoot(array $node): string
    {
        $meta = $this->meta($node);
        if (isset($meta['profiles_path']) && is_string($meta['profiles_path']) && $meta['profiles_path'] !== '') {
            return rtrim($meta['profiles_path'], '/');
        }

        return rtrim($this->defaultProfilesRoot, '/');
    }

    public function ensureProfilesRoot(array $node): void
    {
        $root = $this->profilesRoot($node);
        $this->ssh($node, 'mkdir -p ' . escapeshellarg($root . '/.migrate'));
    }

    public function profileExists(array $node, int $accountId): bool
    {
        $dir = $this->profilesRoot($node) . '/' . $accountId;
        $out = $this->ssh($node, 'test -d ' . escapeshellarg($dir) . ' && echo YES || echo NO');

        return str_contains($out, 'YES');
    }

    public function snapshot(array $node, int $accountId, string $localArchivePath): string
    {
        $parent = dirname($localArchivePath);
        if (!is_dir($parent) && !@mkdir($parent, 0775, true) && !is_dir($parent)) {
            throw new RuntimeException('Cannot create archive parent: ' . $parent);
        }
        if (is_file($localArchivePath)) {
            @unlink($localArchivePath);
        }

        $remoteDir = $this->profilesRoot($node) . '/' . $accountId;
        // Stream remote tar to local file (no NFS).
        $remoteCmd = 'tar -czf - -C ' . escapeshellarg($remoteDir) . ' .';
        $full = sprintf(
            'ssh -i %s -o IdentitiesOnly=yes -o StrictHostKeyChecking=accept-new -p %d %s %s > %s',
            escapeshellarg($this->sshKeyPath),
            $this->sshPortFor($node),
            escapeshellarg($this->sshTarget($node)),
            escapeshellarg($remoteCmd),
            escapeshellarg($localArchivePath)
        );
        $this->exec($full, 'ssh snapshot failed');
        if (!is_file($localArchivePath) || filesize($localArchivePath) === 0) {
            throw new RuntimeException('Empty snapshot from node ' . (int) ($node['id'] ?? 0));
        }

        return $localArchivePath;
    }

    public function pushArchive(array $node, string $localArchivePath, string $remoteFileName): void
    {
        if (!is_file($localArchivePath)) {
            throw new RuntimeException('Archive missing: ' . $localArchivePath);
        }
        $this->ensureProfilesRoot($node);
        $remote = $this->sshTarget($node) . ':' . $this->profilesRoot($node) . '/.migrate/' . $remoteFileName;
        $cmd = sprintf(
            'rsync -az -e %s %s %s',
            escapeshellarg($this->rsyncSsh()),
            escapeshellarg($localArchivePath),
            escapeshellarg($remote)
        );
        $this->exec($cmd, 'rsync push failed');
    }

    public function extractArchive(array $node, string $remoteFileName, int $accountId): void
    {
        $root = $this->profilesRoot($node);
        $archive = $root . '/.migrate/' . $remoteFileName;
        $target = $root . '/' . $accountId;
        $script = sprintf(
            'mkdir -p %s && tar -xzf %s -C %s && rm -f %s',
            escapeshellarg($target),
            escapeshellarg($archive),
            escapeshellarg($target),
            escapeshellarg($archive)
        );
        $this->ssh($node, $script);
    }

    public function removeSingletonFiles(array $node, int $accountId, array $names): int
    {
        if ($names === []) {
            return 0;
        }
        $dir = $this->profilesRoot($node) . '/' . $accountId;
        $parts = [];
        foreach ($names as $name) {
            $parts[] = escapeshellarg($dir . '/' . $name);
        }
        $out = $this->ssh(
            $node,
            'removed=0; for f in ' . implode(' ', $parts)
            . '; do if [ -e "$f" ] || [ -L "$f" ]; then rm -f "$f" && removed=$((removed+1)); fi; done; echo $removed'
        );

        return (int) trim($out);
    }

    /**
     * @param array<string, mixed> $node
     */
    private function ssh(array $node, string $remoteCommand): string
    {
        $cmd = sprintf(
            'ssh -i %s -o IdentitiesOnly=yes -o StrictHostKeyChecking=accept-new -p %d %s %s',
            escapeshellarg($this->sshKeyPath),
            $this->sshPortFor($node),
            escapeshellarg($this->sshTarget($node)),
            escapeshellarg($remoteCommand)
        );
        $out = [];
        $code = 0;
        @exec($cmd . ' 2>&1', $out, $code);
        if ($code !== 0) {
            throw new RuntimeException('ssh failed: ' . implode("\n", $out));
        }

        return implode("\n", $out);
    }

    private function rsyncSsh(): string
    {
        return sprintf(
            'ssh -i %s -o IdentitiesOnly=yes -o StrictHostKeyChecking=accept-new -p %d',
            $this->sshKeyPath,
            $this->sshPort
        );
    }

    /** @param array<string, mixed> $node */
    private function sshTarget(array $node): string
    {
        $meta = $this->meta($node);
        $user = isset($meta['ssh_user']) && is_string($meta['ssh_user']) && $meta['ssh_user'] !== ''
            ? $meta['ssh_user']
            : $this->sshUser;
        $host = isset($meta['ssh_host']) && is_string($meta['ssh_host']) && $meta['ssh_host'] !== ''
            ? $meta['ssh_host']
            : $this->hostFromAddress((string) ($node['address'] ?? ''));
        if ($host === '') {
            throw new RuntimeException('Cannot resolve SSH host for node ' . (int) ($node['id'] ?? 0));
        }

        return $user . '@' . $host;
    }

    /** @param array<string, mixed> $node */
    private function sshPortFor(array $node): int
    {
        $meta = $this->meta($node);
        if (isset($meta['ssh_port']) && (int) $meta['ssh_port'] > 0) {
            return (int) $meta['ssh_port'];
        }

        return $this->sshPort;
    }

    private function hostFromAddress(string $address): string
    {
        $parts = parse_url($address);
        if (is_array($parts) && isset($parts['host']) && is_string($parts['host'])) {
            return $parts['host'];
        }

        return '';
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

    private function exec(string $cmd, string $errorPrefix): void
    {
        $out = [];
        $code = 0;
        @exec($cmd . ' 2>&1', $out, $code);
        if ($code !== 0) {
            throw new RuntimeException($errorPrefix . ': ' . implode("\n", $out));
        }
    }
}
