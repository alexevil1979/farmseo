<?php

declare(strict_types=1);

namespace Farm\Controllers;

use Farm\Http\HttpException;
use Farm\Http\Request;
use Farm\Http\Response;
use Farm\Services\ServiceFactory;
use RuntimeException;

final class AuthController
{
    public function __construct(private readonly ServiceFactory $factory)
    {
    }

    public function login(Request $request): Response
    {
        $body = $request->jsonObject();
        $email = trim((string) ($body['email'] ?? ''));
        $password = (string) ($body['password'] ?? '');
        $totp = isset($body['totp_code']) ? (string) $body['totp_code'] : (isset($body['totp']) ? (string) $body['totp'] : null);

        if ($email === '' || $password === '') {
            throw new HttpException('validation_error', 'email and password are required', 422);
        }

        try {
            $result = $this->factory->auth()->login($email, $password, $totp);
        } catch (RuntimeException $e) {
            throw new HttpException('unauthorized', $e->getMessage(), 401);
        }

        if (!empty($result['totp_required'])) {
            return Response::json([
                'totp_required' => true,
                'message' => 'Two-factor authentication code required',
            ], 401);
        }

        $this->factory->auditLogRepository()->log(
            (int) $result['user']['id'],
            'login',
            'users',
            (int) $result['user']['id'],
            $request->ip(),
            ['via' => 'password']
        );

        return Response::json(['data' => $result]);
    }

    public function logout(Request $request): Response
    {
        $user = $request->getAttribute('user');
        $this->factory->auth()->logout();
        if (is_array($user)) {
            $this->factory->auditLogRepository()->log(
                (int) $user['id'],
                'logout',
                'users',
                (int) $user['id'],
                $request->ip(),
                null
            );
        }

        return Response::json(['ok' => true]);
    }

    public function me(Request $request): Response
    {
        $user = $request->getAttribute('user');
        if (!is_array($user)) {
            throw new HttpException('unauthorized', 'Authentication required', 401);
        }

        return Response::json([
            'data' => [
                'user' => $this->factory->auth()->publicUser($user),
                'role' => $request->getAttribute('role'),
                'permissions' => $this->factory->rbac()->matrixFor((string) $request->getAttribute('role')),
                'csrf' => $this->factory->auth()->issueCsrfToken(),
            ],
        ]);
    }

    public function enableTotp(Request $request): Response
    {
        $user = $request->getAttribute('user');
        if (!is_array($user)) {
            throw new HttpException('unauthorized', 'Authentication required', 401);
        }
        $out = $this->factory->auth()->enableTotp((int) $user['id']);
        $this->factory->auditLogRepository()->log(
            (int) $user['id'],
            'totp_enable_pending',
            'users',
            (int) $user['id'],
            $request->ip(),
            null
        );

        return Response::json(['data' => $out]);
    }

    public function confirmTotp(Request $request): Response
    {
        $user = $request->getAttribute('user');
        if (!is_array($user)) {
            throw new HttpException('unauthorized', 'Authentication required', 401);
        }
        $code = (string) ($request->jsonObject()['totp_code'] ?? $request->jsonObject()['code'] ?? '');
        if (!$this->factory->auth()->confirmTotp((int) $user['id'], $code)) {
            throw new HttpException('validation_error', 'Invalid TOTP code', 422);
        }
        $this->factory->auditLogRepository()->log(
            (int) $user['id'],
            'totp_enabled',
            'users',
            (int) $user['id'],
            $request->ip(),
            null
        );

        return Response::json(['ok' => true]);
    }
}
