<?php

declare(strict_types=1);

namespace Farm\Tests\Services;

use Farm\Repositories\AccountRepository;
use Farm\Repositories\PhoneRepository;
use Farm\Services\SmsProvider;
use Farm\Tests\TestCase;

final class SmsProviderTest extends TestCase
{
    private PhoneRepository $phones;
    private AccountRepository $accounts;

    /** @var list<array{method:string,url:string,query:array}> */
    private array $httpLog = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->phones = new PhoneRepository($this->db);
        $this->accounts = new AccountRepository($this->db);
        $this->httpLog = [];
    }

    public function testRentCodeBindReleaseViaSmsActivate(): void
    {
        $accountId = $this->accounts->create([
            'login' => 'sms@test',
            'password_enc' => 'x',
            'status' => 'new',
            'trust_score' => '0.00',
        ]);

        $calls = 0;
        $http = function (string $method, string $url, array $query = [], ?array $body = null) use (&$calls): array {
            $this->httpLog[] = ['method' => $method, 'url' => $url, 'query' => $query];
            $action = (string) ($query['action'] ?? '');
            if ($action === 'getNumber') {
                return ['ok' => true, 'status' => 200, 'body' => 'ACCESS_NUMBER:777:79001234567', 'json' => null];
            }
            if ($action === 'getStatus') {
                $calls++;
                if ($calls < 2) {
                    return ['ok' => true, 'status' => 200, 'body' => 'STATUS_WAIT_CODE', 'json' => null];
                }

                return ['ok' => true, 'status' => 200, 'body' => 'STATUS_OK:123456', 'json' => null];
            }
            if ($action === 'setStatus') {
                return ['ok' => true, 'status' => 200, 'body' => 'ACCESS_ACTIVATION', 'json' => null];
            }

            return ['ok' => false, 'status' => 500, 'body' => 'BAD_ACTION', 'json' => null];
        };

        $sms = new SmsProvider(
            $this->phones,
            $this->accounts,
            [
                'provider' => 'sms-activate',
                'api_key' => 'test-key',
                'service' => 'ya',
                'country' => '0',
                'rent_minutes' => 15,
                'poll_interval_sec' => 0,
                'wait_timeout_sec' => 30,
            ],
            $http,
            static function (int $seconds): void {
                // no-op sleeper for tests
            },
        );

        $rented = $sms->getNumber(['account_id' => $accountId]);
        self::assertSame('79001234567', $rented['number']);
        self::assertSame('777', $rented['external_id']);
        self::assertSame($accountId, $rented['account_id']);
        self::assertSame('sms-activate', $rented['provider']);

        $row = $this->phones->findById($rented['phone_id']);
        self::assertNotNull($row);
        self::assertSame('rented', $row['status']);
        self::assertSame($accountId, (int) $row['account_id']);
        self::assertNotNull($row['expires_at']);

        $acc = $this->accounts->findById($accountId);
        self::assertSame('79001234567', $acc['phone']);

        $code = $sms->waitCode($rented['phone_id'], 30, 0);
        self::assertSame('123456', $code['code']);
        self::assertSame($accountId, $code['account_id']);

        $afterCode = $this->phones->findById($rented['phone_id']);
        self::assertSame('used', $afterCode['status']);
        self::assertSame('123456', $afterCode['last_code']);

        $released = $sms->release($rented['phone_id']);
        self::assertTrue($released['ok']);
        self::assertSame('released', $released['status']);
        self::assertSame('released', $this->phones->findById($rented['phone_id'])['status']);

        $actions = array_map(
            static fn (array $c): string => (string) ($c['query']['action'] ?? ''),
            $this->httpLog
        );
        self::assertContains('getNumber', $actions);
        self::assertContains('getStatus', $actions);
        self::assertContains('setStatus', $actions);
    }

    public function testProviderSwitchToSimBankWithoutCodeChange(): void
    {
        $http = function (string $method, string $url, array $query = [], ?array $body = null): array {
            $this->httpLog[] = ['method' => $method, 'url' => $url, 'query' => $query];
            self::assertStringContainsString('127.0.0.1:7920', $url);
            if (($query['action'] ?? '') === 'getNumber') {
                return ['ok' => true, 'status' => 200, 'body' => 'ACCESS_NUMBER:42:79998887766', 'json' => null];
            }
            if (($query['action'] ?? '') === 'setStatus') {
                return ['ok' => true, 'status' => 200, 'body' => 'ACCESS_CANCEL', 'json' => null];
            }

            return ['ok' => true, 'status' => 200, 'body' => 'STATUS_WAIT_CODE', 'json' => null];
        };

        $sms = new SmsProvider(
            $this->phones,
            $this->accounts,
            [
                'provider' => 'simbank',
                'api_key' => 'sim-key',
                'api_url' => 'http://127.0.0.1:7920/',
                'rent_minutes' => 10,
            ],
            $http,
            static function (int $seconds): void {},
        );

        self::assertSame('simbank', $sms->providerName());
        $rented = $sms->getNumber();
        self::assertSame('79998887766', $rented['number']);
        self::assertSame('simbank', $rented['provider']);

        $cancelled = $sms->cancel($rented['phone_id']);
        self::assertSame('cancelled', $cancelled['status']);
    }

    public function testCancelUnusedNumber(): void
    {
        $http = function (string $method, string $url, array $query = [], ?array $body = null): array {
            if (($query['action'] ?? '') === 'getNumber') {
                return ['ok' => true, 'status' => 200, 'body' => 'ACCESS_NUMBER:1:71112223344', 'json' => null];
            }

            return ['ok' => true, 'status' => 200, 'body' => 'ACCESS_CANCEL', 'json' => null];
        };

        $sms = new SmsProvider(
            $this->phones,
            $this->accounts,
            ['provider' => 'sms-activate', 'api_key' => 'k'],
            $http,
            static function (int $seconds): void {},
        );

        $rented = $sms->getNumber();
        $sms->cancel($rented['phone_id']);
        self::assertSame('cancelled', $this->phones->findById($rented['phone_id'])['status']);
    }
}
