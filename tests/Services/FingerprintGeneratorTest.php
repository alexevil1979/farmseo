<?php

declare(strict_types=1);

namespace Farm\Tests\Services;

use Farm\Services\FingerprintGenerator;
use Farm\Services\FingerprintValidator;
use Farm\Services\GeoMapper;
use PHPUnit\Framework\TestCase;

final class FingerprintGeneratorTest extends TestCase
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

    public function testGenerate1000NoHashCollisions(): void
    {
        $hashes = [];
        $desktop = 0;
        $mobile = 0;
        $countries = ['RU', 'DE', 'US', 'TR', 'JP', 'BR', 'GB', 'FR'];

        for ($i = 0; $i < 1000; $i++) {
            $fp = $this->generator->generate([
                'country' => $countries[$i % count($countries)],
                'seed' => 'collision-test-' . $i,
            ]);

            self::assertArrayHasKey('hash', $fp);
            self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $fp['hash']);
            self::assertArrayNotHasKey($fp['hash'], $hashes, "hash collision at i={$i}");
            $hashes[$fp['hash']] = $i;

            $check = $this->validator->validate($fp, ['country' => $countries[$i % count($countries)]]);
            self::assertTrue($check['ok'], 'fp #' . $i . ': ' . implode(', ', $check['errors']));

            if ($fp['profile'] === 'desktop') {
                $desktop++;
            } else {
                $mobile++;
            }
        }

        self::assertCount(1000, $hashes);
        // ~60/40 with seed variance — allow ±12%
        self::assertGreaterThan(480, $desktop, "desktop={$desktop}");
        self::assertLessThan(720, $desktop, "desktop={$desktop}");
        self::assertGreaterThan(280, $mobile, "mobile={$mobile}");
        self::assertLessThan(520, $mobile, "mobile={$mobile}");
    }

    public function testTimezoneAndLangFollowProxyCountry(): void
    {
        $fp = $this->generator->generate(['country' => 'DE', 'device' => 'desktop', 'seed' => 'de-1']);
        self::assertSame('Europe/Berlin', $fp['timezone']);
        self::assertSame('de-DE', $fp['lang']);
        self::assertSame('DE', $fp['geo_country']);
        self::assertTrue($this->validator->isValid($fp, ['country' => 'DE']));
    }

    public function testToRowAndFromRowRoundTrip(): void
    {
        $fp = $this->generator->generate(['country' => 'RU', 'seed' => 'row-1']);
        $row = $this->generator->toRow(42, $fp, ['geo_country' => 'RU']);

        self::assertSame(42, $row['account_id']);
        self::assertSame($fp['ua'], $row['user_agent']);
        self::assertSame($fp['platform'], $row['platform']);
        self::assertSame($fp['screen']['width'], $row['viewport_w']);
        self::assertSame($fp['timezone'], $row['timezone']);
        self::assertSame($fp['lang'], $row['locale']);
        self::assertIsArray($row['extra']);
        self::assertArrayHasKey('fp', $row['extra']);

        $restored = $this->generator->fromRow(['extra' => $row['extra']]);
        self::assertNotNull($restored);
        self::assertSame($fp['hash'], $restored['hash']);
        self::assertSame($fp['ua'], $restored['ua']);
    }

    public function testMobileForcedProfile(): void
    {
        $fp = $this->generator->generate(['country' => 'US', 'device' => 'mobile', 'seed' => 'm-1']);
        self::assertSame('mobile', $fp['profile']);
        self::assertGreaterThan(0, $fp['touch']['maxTouchPoints']);
        self::assertSame([], $fp['plugins']);
        self::assertLessThanOrEqual(600, $fp['screen']['width']);
    }

    public function testDesktopForcedProfile(): void
    {
        $fp = $this->generator->generate(['country' => 'US', 'device' => 'desktop', 'seed' => 'd-1']);
        self::assertSame('desktop', $fp['profile']);
        self::assertSame(0, $fp['touch']['maxTouchPoints']);
        self::assertNotSame([], $fp['plugins']);
        self::assertGreaterThanOrEqual(1024, $fp['screen']['width']);
    }
}
