<?php

declare(strict_types=1);

namespace Farm\Services;

use RuntimeException;

/**
 * Routes session/start and screenshot HTTP to the account's sticky node address.
 *
 * Never falls back to another node when HB is dead (use ProfileMigrationJob / 14b).
 */
final class SessionRouter
{
    public function __construct(
        private readonly NodeRegistry $registry,
        private readonly string $fallbackWorkerUrl = 'http://127.0.0.1:3000',
    ) {
    }

    /**
     * Resolve worker base URL for /internal/session/start and /internal/account/screenshot.
     *
     * Pins account on first use; dead HB → account error + alert + exception.
     */
    public function resolveWorkerUrl(int $accountId): string
    {
        try {
            $url = $this->registry->resolveAddress($accountId, true);
            if ($url !== '') {
                return $url;
            }
        } catch (RuntimeException $e) {
            if ($e->getMessage() === 'No nodes registered') {
                return rtrim($this->fallbackWorkerUrl, '/');
            }
            throw $e;
        }

        return rtrim($this->fallbackWorkerUrl, '/');
    }

    public function registry(): NodeRegistry
    {
        return $this->registry;
    }
}
