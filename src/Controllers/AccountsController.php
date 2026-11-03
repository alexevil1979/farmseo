<?php

declare(strict_types=1);

namespace Farm\Controllers;

use Farm\Http\HttpException;
use Farm\Http\Request;
use Farm\Http\Response;
use Farm\Repositories\AbstractRepository;
use Farm\Repositories\AccountSessionRepository;
use Throwable;

final class AccountsController extends BaseController
{
    private const STATUSES = ['new', 'warming', 'ready', 'active', 'paused', 'banned', 'error'];

    protected function repository(): AbstractRepository
    {
        return $this->factory->accountRepository();
    }

    protected function resourceName(): string
    {
        return 'accounts';
    }

    protected function writable(): array
    {
        return [
            'login', 'password', 'password_enc', 'status', 'trust_score', 'phone',
            'group_id', 'tags', 'notes', 'proxy_id', 'profile_path', 'last_activity',
        ];
    }

    protected function listFilters(Request $request): array
    {
        $f = [];
        if ($request->query('status') !== null) {
            $f['status'] = (string) $request->query('status');
        }
        if ($request->query('group_id') !== null) {
            $f['group_id'] = (int) $request->query('group_id');
        }

        return $f;
    }

    protected function beforeCreate(array $data, Request $request): array
    {
        if (!isset($data['login']) || trim((string) $data['login']) === '') {
            throw new HttpException('validation_error', 'login is required', 422);
        }
        $password = (string) ($data['password'] ?? '');
        unset($data['password']);
        if ($password === '' && empty($data['password_enc'])) {
            throw new HttpException('validation_error', 'password is required', 422);
        }
        if ($password !== '') {
            $data['password_enc'] = $this->factory->crypto()->encrypt($password);
        }
        $data['status'] = (string) ($data['status'] ?? 'new');
        if (!in_array($data['status'], self::STATUSES, true)) {
            throw new HttpException('validation_error', 'Invalid account status', 422);
        }
        $data['trust_score'] = $data['trust_score'] ?? '0.00';

        return $data;
    }

    protected function beforeUpdate(array $data, array $existing, Request $request): array
    {
        if (isset($data['password'])) {
            $data['password_enc'] = $this->factory->crypto()->encrypt((string) $data['password']);
            unset($data['password']);
        }
        if (isset($data['status']) && !in_array((string) $data['status'], self::STATUSES, true)) {
            throw new HttpException('validation_error', 'Invalid account status', 422);
        }

        return $data;
    }

    protected function present(array $row): array
    {
        unset($row['password_enc']);

        return $row;
    }

    public function startSession(Request $request, string $id): Response
    {
        $accountId = (int) $id;
        $body = $request->json();
        if (is_array($body) && isset($body['action']) && $body['action'] === 'stop') {
            return $this->stopSession($request, $id);
        }
        if (is_array($body) && isset($body['action']) && $body['action'] === 'reset') {
            return $this->resetSession($request, $id);
        }

        try {
            $data = $this->factory->liveView()->start($accountId);
        } catch (Throwable $e) {
            throw new HttpException('session_error', $e->getMessage(), 502);
        }

        $this->audit($request, 'session_start', $accountId, [
            'session_id' => $data['session_id'],
            'vnc_port' => $data['vnc_port'],
        ]);

        return Response::json(['data' => $data], 201);
    }

    public function stopSession(Request $request, string $id): Response
    {
        $accountId = (int) $id;
        $account = $this->factory->accountRepository()->findById($accountId);
        if ($account === null) {
            throw new HttpException('not_found', 'Account not found', 404);
        }

        try {
            $this->factory->liveView()->stop($accountId);
        } catch (Throwable $e) {
            throw new HttpException('session_error', $e->getMessage(), 502);
        }

        $this->audit($request, 'session_stop', $accountId, []);

        return Response::json(['ok' => true, 'data' => ['account_id' => $accountId, 'status' => 'stopped']]);
    }

    public function resetSession(Request $request, string $id): Response
    {
        $accountId = (int) $id;
        try {
            $this->factory->liveView()->stop($accountId);
        } catch (Throwable) {
            // ignore stop errors on reset
        }
        $sessions = new AccountSessionRepository($this->factory->db());
        foreach ($sessions->findActiveByAccount($accountId) as $session) {
            $sessions->endSession((int) $session['id']);
        }

        try {
            $data = $this->factory->liveView()->start($accountId);
        } catch (Throwable $e) {
            throw new HttpException('session_error', $e->getMessage(), 502);
        }

        $this->audit($request, 'session_reset', $accountId, ['session_id' => $data['session_id']]);

        return Response::json(['data' => $data]);
    }

    public function liveView(Request $request, string $id): Response
    {
        $accountId = (int) $id;
        $account = $this->factory->accountRepository()->findById($accountId);
        if ($account === null) {
            throw new HttpException('not_found', 'Account not found', 404);
        }

        $live = $this->factory->liveView();
        $live->touch($accountId);

        return Response::json([
            'data' => [
                'account_id' => $accountId,
                'novnc_url' => $live->streamUrl($accountId),
                'busy_ports' => $live->busyPorts(),
            ],
        ]);
    }

    public function screenshot(Request $request, string $id): Response
    {
        $accountId = (int) $id;
        $account = $this->factory->accountRepository()->findById($accountId);
        if ($account === null) {
            throw new HttpException('not_found', 'Account not found', 404);
        }

        $live = $this->factory->liveView();
        $root = (string) $this->factory->configValue('root', dirname(__DIR__, 2));
        $fresh = $request->query('fresh');
        $wantFresh = $request->method() === 'POST'
            || $fresh === '1'
            || $fresh === 'true'
            || $request->query('now') === '1';

        if ($wantFresh) {
            try {
                $data = $live->screenshotNow($accountId);

                return Response::json(['data' => $data]);
            } catch (Throwable $e) {
                // Fall through to latest on disk if worker unavailable
                $latest = $live->latestScreenshot($accountId, $root);
                if ($latest !== null) {
                    return Response::json(['data' => $latest, 'warning' => $e->getMessage()]);
                }
                throw new HttpException('screenshot_error', $e->getMessage(), 502);
            }
        }

        $latest = $live->latestScreenshot($accountId, $root);
        if ($latest === null) {
            // Try one-shot capture so GET remains useful as "актуальный"
            try {
                $data = $live->screenshotNow($accountId);

                return Response::json(['data' => $data]);
            } catch (Throwable $e) {
                throw new HttpException('not_found', 'Screenshot not found: ' . $e->getMessage(), 404);
            }
        }

        return Response::json(['data' => $latest]);
    }

    public function sessions(Request $request, string $id): Response
    {
        $accountId = (int) $id;
        $account = $this->factory->accountRepository()->findById($accountId);
        if ($account === null) {
            throw new HttpException('not_found', 'Account not found', 404);
        }
        $repo = new AccountSessionRepository($this->factory->db());
        $page = max(1, (int) ($request->query('page') ?? 1));
        $perPage = min(100, max(1, (int) ($request->query('per_page') ?? 50)));
        $all = $repo->getAll(['account_id' => $accountId]);
        usort($all, static fn (array $a, array $b): int => strcmp((string) ($b['started_at'] ?? ''), (string) ($a['started_at'] ?? '')));
        $total = count($all);
        $slice = array_slice($all, ($page - 1) * $perPage, $perPage);

        return Response::json([
            'data' => $slice,
            'meta' => [
                'page' => $page,
                'per_page' => $perPage,
                'total' => $total,
            ],
        ]);
    }

    public function fingerprint(Request $request, string $id): Response
    {
        $accountId = (int) $id;
        $account = $this->factory->accountRepository()->findById($accountId);
        if ($account === null) {
            throw new HttpException('not_found', 'Account not found', 404);
        }
        $row = $this->factory->accountFingerprintRepository()->findByAccountId($accountId);
        if ($row === null) {
            throw new HttpException('not_found', 'Fingerprint not found', 404);
        }
        $fp = $this->factory->fingerprintGenerator()->fromRow($row);

        return Response::json([
            'data' => [
                'row' => $row,
                'fingerprint' => $fp,
            ],
        ]);
    }

    public function generateFingerprint(Request $request, string $id): Response
    {
        $accountId = (int) $id;
        $account = $this->factory->accountRepository()->findById($accountId);
        if ($account === null) {
            throw new HttpException('not_found', 'Account not found', 404);
        }

        $force = false;
        $body = $request->json();
        if (is_array($body) && !empty($body['force'])) {
            $force = true;
        }

        $existing = $this->factory->accountFingerprintRepository()->findByAccountId($accountId);
        if ($existing !== null && !$force) {
            return Response::json([
                'data' => [
                    'row' => $existing,
                    'fingerprint' => $this->factory->fingerprintGenerator()->fromRow($existing),
                    'created' => false,
                ],
            ]);
        }

        $proxy = null;
        if (!empty($account['proxy_id'])) {
            $proxy = $this->factory->proxyRepository()->findById((int) $account['proxy_id']);
        }
        $geo = is_array($proxy) ? ($proxy['country'] ?? null) : null;
        $fp = $this->factory->fingerprintGenerator()->generate([
            'country' => $geo,
            'seed' => 'acc-' . $accountId,
        ]);
        $rowData = $this->factory->fingerprintGenerator()->toRow($accountId, $fp);
        $fpId = $this->factory->accountFingerprintRepository()->upsertForAccount($accountId, $rowData);
        $row = $this->factory->accountFingerprintRepository()->findById($fpId)
            ?? $this->factory->accountFingerprintRepository()->findByAccountId($accountId);

        $this->audit($request, 'fingerprint_generate', $accountId, ['fingerprint_id' => $fpId]);

        return Response::json([
            'data' => [
                'row' => $row,
                'fingerprint' => $fp,
                'created' => true,
            ],
        ], 201);
    }

    /**
     * Sticky proxy assign (+ optional fingerprint) for one account.
     */
    public function assignProxy(Request $request, string $id): Response
    {
        $accountId = (int) $id;
        $account = $this->factory->accountRepository()->findById($accountId);
        if ($account === null) {
            throw new HttpException('not_found', 'Account not found', 404);
        }
        $body = $request->jsonObject();
        try {
            $result = $this->factory->proxyPool()->assign($accountId, [
                'country' => $body['country'] ?? null,
                'force' => (bool) ($body['force'] ?? false),
            ]);
        } catch (Throwable $e) {
            throw new HttpException('assign_error', $e->getMessage(), 422);
        }

        $fp = null;
        if (!empty($body['with_fingerprint'])) {
            $fpRes = $this->generateFingerprint($request, $id);
            $payload = $fpRes->body();
            $fp = is_array($payload) ? ($payload['data'] ?? null) : null;
        }

        $this->audit($request, 'proxy_assign', $accountId, [
            'proxy_id' => $result['proxy_id'],
            'sticky' => $result['sticky'],
        ]);

        return Response::json([
            'data' => [
                'assignment' => $result,
                'fingerprint' => $fp,
            ],
        ]);
    }

    /**
     * Bulk provision: assign sticky proxies (+ fingerprints) to many accounts.
     * Body: { account_ids?: int[], group_id?: int, with_fingerprint?: bool, force?: bool, country?: string }
     */
    public function bulkProvision(Request $request): Response
    {
        $body = $request->jsonObject();
        $ids = [];
        if (isset($body['account_ids']) && is_array($body['account_ids'])) {
            $ids = array_values(array_unique(array_map('intval', $body['account_ids'])));
        } elseif (isset($body['group_id'])) {
            foreach ($this->factory->accountRepository()->findByGroupId((int) $body['group_id']) as $row) {
                $ids[] = (int) $row['id'];
            }
        } else {
            throw new HttpException('validation_error', 'account_ids or group_id required', 422);
        }
        if ($ids === []) {
            throw new HttpException('validation_error', 'No accounts to provision', 422);
        }
        if (count($ids) > 500) {
            throw new HttpException('validation_error', 'Max 500 accounts per provision call', 422);
        }

        $withFp = !empty($body['with_fingerprint']);
        $force = (bool) ($body['force'] ?? false);
        $country = $body['country'] ?? null;
        $ok = [];
        $errors = [];

        foreach ($ids as $accountId) {
            try {
                $assign = $this->factory->proxyPool()->assign($accountId, [
                    'country' => $country,
                    'force' => $force,
                ]);
                $fpId = null;
                if ($withFp) {
                    $account = $this->factory->accountRepository()->findById($accountId);
                    $existing = $this->factory->accountFingerprintRepository()->findByAccountId($accountId);
                    if ($existing === null || $force) {
                        $proxy = $this->factory->proxyRepository()->findById((int) $assign['proxy_id']);
                        $fp = $this->factory->fingerprintGenerator()->generate([
                            'country' => $proxy['country'] ?? $country,
                            'seed' => 'acc-' . $accountId,
                        ]);
                        $rowData = $this->factory->fingerprintGenerator()->toRow($accountId, $fp);
                        $fpId = $this->factory->accountFingerprintRepository()->upsertForAccount($accountId, $rowData);
                    } else {
                        $fpId = (int) $existing['id'];
                    }
                }
                $ok[] = [
                    'account_id' => $accountId,
                    'proxy_id' => $assign['proxy_id'],
                    'sticky' => $assign['sticky'],
                    'fingerprint_id' => $fpId,
                ];
            } catch (Throwable $e) {
                $errors[] = ['account_id' => $accountId, 'error' => $e->getMessage()];
            }
        }

        $this->audit($request, 'bulk_provision', null, [
            'ok' => count($ok),
            'errors' => count($errors),
        ]);

        return Response::json([
            'data' => $ok,
            'meta' => [
                'ok' => count($ok),
                'errors' => $errors,
                'total' => count($ids),
            ],
        ], $ok !== [] ? 200 : 422);
    }
}
