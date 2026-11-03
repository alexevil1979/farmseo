<?php

declare(strict_types=1);

namespace Farm\Tests\Services;

use Farm\Services\Auth;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class AuthTotpTest extends TestCase
{
    public function testTotpRoundTrip(): void
    {
        $auth = $this->makeAuth();
        $secret = $auth->generateTotpSecret();
        $code = $auth->totpCode($secret);
        self::assertMatchesRegularExpression('/^\d{6}$/', $code);
        self::assertSame($code, $auth->totpCode($secret, time()));
    }

    public function testPasswordHash(): void
    {
        $auth = $this->makeAuth();
        $hash = $auth->hashPassword('admin123!');
        self::assertTrue($auth->verifyPassword('admin123!', $hash));
        self::assertFalse($auth->verifyPassword('wrong', $hash));
    }

    public function testJwtRoundTrip(): void
    {
        $auth = $this->makeAuth();
        $jwt = $auth->issueJwt(42, 1, 60);
        $payload = $auth->parseJwt($jwt);
        self::assertNotNull($payload);
        self::assertSame(42, (int) $payload['sub']);
        self::assertSame(1, (int) $payload['role_id']);
    }

    private function makeAuth(): Auth
    {
        $ref = new ReflectionClass(Auth::class);
        /** @var Auth $auth */
        $auth = $ref->newInstanceWithoutConstructor();
        $prop = $ref->getProperty('appKey');
        $prop->setAccessible(true);
        $prop->setValue($auth, 'test-app-key');

        return $auth;
    }
}
