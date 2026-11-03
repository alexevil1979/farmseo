<?php

declare(strict_types=1);

namespace Farm\Services\ProfileMigration;

use RuntimeException;

/**
 * Cross-platform tar.gz helpers (Windows tar.exe / Unix tar).
 */
final class Archive
{
    public static function packDirectory(string $directory, string $archivePath): void
    {
        $directory = rtrim($directory, '/\\');
        if (!is_dir($directory)) {
            throw new RuntimeException('Not a directory: ' . $directory);
        }

        $parent = dirname($directory);
        $base = basename($directory);
        // Pack contents of profile dir as top-level entries (not nested account id folder).
        $cmd = sprintf(
            'tar -czf %s -C %s .',
            escapeshellarg($archivePath),
            escapeshellarg($directory)
        );
        self::run($cmd, "tar pack failed for {$base} under {$parent}");
        if (!is_file($archivePath) || filesize($archivePath) === 0) {
            throw new RuntimeException('Empty archive produced: ' . $archivePath);
        }
    }

    public static function extractInto(string $archivePath, string $targetDirectory): void
    {
        if (!is_file($archivePath)) {
            throw new RuntimeException('Archive missing: ' . $archivePath);
        }
        if (!is_dir($targetDirectory) && !@mkdir($targetDirectory, 0775, true) && !is_dir($targetDirectory)) {
            throw new RuntimeException('Cannot create extract target: ' . $targetDirectory);
        }

        $cmd = sprintf(
            'tar -xzf %s -C %s',
            escapeshellarg($archivePath),
            escapeshellarg($targetDirectory)
        );
        self::run($cmd, 'tar extract failed: ' . $archivePath);
    }

    private static function run(string $cmd, string $errorPrefix): void
    {
        $out = [];
        $code = 0;
        @exec($cmd . ' 2>&1', $out, $code);
        if ($code !== 0) {
            throw new RuntimeException($errorPrefix . ': ' . implode("\n", $out));
        }
    }
}
