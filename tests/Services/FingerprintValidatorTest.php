<?php

declare(strict_types=1);

namespace Farm\Tests\Services;

use Farm\Services\FingerprintGenerator;
use Farm\Services\FingerprintValidator;
use Farm\Services\GeoMapper;
use PHPUnit\Framework\TestCase;

final class FingerprintValidatorTest extends TestCase
{
    private FingerprintGenerator $generator;
    private FingerprintValidator $validator;

    protected function setUp(): void
    {
        parent::setUp();
        $geo = new GeoMapper();
        $this->generator = new FingerprintGenerator($geo);
        $this->validator = new FingerprintValidator($geo);
    }

    public function testCatchesTimezoneCountryMismatch(): void
    {
        $fp = $this->generator->generate([
            'country' => 'RU',
            'device' => 'desktop',
            'seed' => 'tz-mismatch-1',
        ]);

        // Corrupt: Russian UA geo but US timezone
        $fp['timezone'] = 'America/New_York';

        $result = $this->validator->validate($fp, ['country' => 'RU']);

        self::assertFalse($result['ok']);
        $joined = implode('|', $result['errors']);
        self::assertStringContainsString('tz_country_mismatch', $joined);
    }

    public function testCatchesLangCountryMismatch(): void
    {
        $fp = $this->generator->generate([
            'country' => 'DE',
            'device' => 'desktop',
            'seed' => 'lang-mismatch-1',
        ]);
        $fp['lang'] = 'ja-JP';

        $result = $this->validator->validate($fp, ['country' => 'DE']);
        self::assertFalse($result['ok']);
        self::assertTrue(
            (bool) array_filter($result['errors'], static fn (string $e): bool => str_starts_with($e, 'lang_country_mismatch')),
            implode(', ', $result['errors'])
        );
    }

    public function testCatchesUaPlatformMismatch(): void
    {
        $fp = $this->generator->generate([
            'country' => 'US',
            'device' => 'desktop',
            'seed' => 'ua-plat-1',
        ]);
        // Force Windows UA with Mac platform
        $fp['ua'] = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/122.0.0.0 Safari/537.36';
        $fp['platform'] = 'MacIntel';
        $fp['os'] = 'Windows';
        $fp['profile'] = 'desktop';

        $result = $this->validator->validate($fp, ['country' => 'US']);
        self::assertFalse($result['ok']);
        $joined = implode('|', $result['errors']);
        self::assertStringContainsString('ua_platform_mismatch', $joined);
    }

    public function testCatchesMobileDesktopScreenMismatch(): void
    {
        $fp = $this->generator->generate([
            'country' => 'US',
            'device' => 'mobile',
            'seed' => 'screen-1',
        ]);
        $fp['screen']['width'] = 1920;
        $fp['screen']['height'] = 1080;

        $result = $this->validator->validate($fp, ['country' => 'US']);
        self::assertFalse($result['ok']);
        self::assertContains('mobile_screen_too_wide', $result['errors']);
    }

    public function testValidFingerprintPassesWithProxy(): void
    {
        $fp = $this->generator->generate([
            'country' => 'PL',
            'device' => 'desktop',
            'seed' => 'ok-pl-1',
        ]);
        $result = $this->validator->validate($fp, [
            'country' => 'PL',
            'meta' => ['city' => 'Warsaw', 'timezone' => 'Europe/Warsaw'],
        ]);
        self::assertTrue($result['ok'], implode(', ', $result['errors']));
    }

    public function testGeoCountryMismatchWithProxy(): void
    {
        $fp = $this->generator->generate([
            'country' => 'FR',
            'seed' => 'geo-1',
        ]);
        // Proxy says DE while fp claims FR
        $result = $this->validator->validate($fp, ['country' => 'DE']);
        self::assertFalse($result['ok']);
        $joined = implode('|', $result['errors']);
        self::assertTrue(
            str_contains($joined, 'tz_country_mismatch') || str_contains($joined, 'geo_country_mismatch') || str_contains($joined, 'lang_country_mismatch'),
            $joined
        );
    }

    public function testStubModeDoesNotFailOnMissingAsn(): void
    {
        $prev = (string) (getenv('GEO_PROVIDER') ?: '');
        putenv('GEO_PROVIDER=stub');
        $_ENV['GEO_PROVIDER'] = 'stub';
        $_SERVER['GEO_PROVIDER'] = 'stub';

        try {
            $fp = $this->generator->generate([
                'country' => 'RU',
                'device' => 'desktop',
                'seed' => 'stub-asn-1',
            ]);
            // Probe-shaped meta from StubGeoProvider (asn intentionally null)
            $result = $this->validator->validate($fp, [
                'country' => 'RU',
                'meta' => [
                    'provider' => 'stub',
                    'asn' => null,
                    'timezone' => 'Europe/Moscow',
                    'city' => 'Moscow',
                ],
            ]);
            self::assertTrue($result['ok'], implode(', ', $result['errors']));
            self::assertNotContains('missing_asn', $result['errors']);
        } finally {
            if ($prev === '') {
                putenv('GEO_PROVIDER');
                unset($_ENV['GEO_PROVIDER'], $_SERVER['GEO_PROVIDER']);
            } else {
                putenv('GEO_PROVIDER=' . $prev);
                $_ENV['GEO_PROVIDER'] = $prev;
                $_SERVER['GEO_PROVIDER'] = $prev;
            }
        }
    }

    public function testLiveModeRequiresAsnAfterProbe(): void
    {
        $prev = (string) (getenv('GEO_PROVIDER') ?: '');
        putenv('GEO_PROVIDER=http');
        $_ENV['GEO_PROVIDER'] = 'http';
        $_SERVER['GEO_PROVIDER'] = 'http';

        try {
            $fp = $this->generator->generate([
                'country' => 'RU',
                'device' => 'desktop',
                'seed' => 'live-asn-1',
            ]);
            $result = $this->validator->validate($fp, [
                'country' => 'RU',
                'meta' => [
                    'provider' => 'ip-api',
                    'asn' => null,
                    'timezone' => 'Europe/Moscow',
                ],
            ]);
            self::assertFalse($result['ok']);
            self::assertContains('missing_asn', $result['errors']);
        } finally {
            if ($prev === '') {
                putenv('GEO_PROVIDER');
                unset($_ENV['GEO_PROVIDER'], $_SERVER['GEO_PROVIDER']);
            } else {
                putenv('GEO_PROVIDER=' . $prev);
                $_ENV['GEO_PROVIDER'] = $prev;
                $_SERVER['GEO_PROVIDER'] = $prev;
            }
        }
    }
}
