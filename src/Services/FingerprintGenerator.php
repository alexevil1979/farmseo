<?php

declare(strict_types=1);

namespace Farm\Services;

/**
 * Generates a coherent browser fingerprint per account.
 *
 * Distribution: ~60% desktop (Win/macOS), ~40% mobile (Android/iOS emulation).
 * Timezone + language are derived from proxy geo via GeoMapper.
 */
final class FingerprintGenerator
{
    private const DESKTOP_RATIO = 0.60;

    /** @var list<array{w: int, h: int, dpr: float}> */
    private const DESKTOP_SCREENS = [
        ['w' => 1920, 'h' => 1080, 'dpr' => 1.0],
        ['w' => 2560, 'h' => 1440, 'dpr' => 1.0],
        ['w' => 1366, 'h' => 768, 'dpr' => 1.0],
        ['w' => 1536, 'h' => 864, 'dpr' => 1.25],
        ['w' => 1440, 'h' => 900, 'dpr' => 2.0],
        ['w' => 1680, 'h' => 1050, 'dpr' => 1.0],
        ['w' => 1280, 'h' => 800, 'dpr' => 2.0],
        ['w' => 1920, 'h' => 1200, 'dpr' => 1.0],
    ];

    /** @var list<array{w: int, h: int, dpr: float, model: string}> */
    private const ANDROID_SCREENS = [
        ['w' => 412, 'h' => 915, 'dpr' => 2.625, 'model' => 'Pixel 7'],
        ['w' => 360, 'h' => 800, 'dpr' => 3.0, 'model' => 'SM-S911B'],
        ['w' => 393, 'h' => 873, 'dpr' => 2.75, 'model' => 'Pixel 6a'],
        ['w' => 412, 'h' => 892, 'dpr' => 3.5, 'model' => 'SM-G991B'],
        ['w' => 384, 'h' => 854, 'dpr' => 2.8125, 'model' => 'M2102J20SG'],
    ];

    /** @var list<array{w: int, h: int, dpr: float, model: string}> */
    private const IOS_SCREENS = [
        ['w' => 390, 'h' => 844, 'dpr' => 3.0, 'model' => 'iPhone 13'],
        ['w' => 393, 'h' => 852, 'dpr' => 3.0, 'model' => 'iPhone 14'],
        ['w' => 430, 'h' => 932, 'dpr' => 3.0, 'model' => 'iPhone 14 Pro Max'],
        ['w' => 375, 'h' => 812, 'dpr' => 3.0, 'model' => 'iPhone 12 mini'],
        ['w' => 414, 'h' => 896, 'dpr' => 3.0, 'model' => 'iPhone 11'],
    ];

    /** @var list<string> */
    private const WIN_FONTS = [
        'Arial', 'Arial Black', 'Calibri', 'Cambria', 'Candara', 'Comic Sans MS',
        'Consolas', 'Courier New', 'Georgia', 'Impact', 'Lucida Console',
        'Lucida Sans Unicode', 'Microsoft Sans Serif', 'Palatino Linotype',
        'Segoe UI', 'Tahoma', 'Times New Roman', 'Trebuchet MS', 'Verdana',
        'Wingdings',
    ];

    /** @var list<string> */
    private const MAC_FONTS = [
        'Arial', 'Arial Black', 'Courier New', 'Georgia', 'Helvetica',
        'Helvetica Neue', 'Impact', 'Lucida Grande', 'Menlo', 'Monaco',
        'Optima', 'Palatino', 'San Francisco', 'Times', 'Times New Roman',
        'Trebuchet MS', 'Verdana', 'Apple Color Emoji',
    ];

    /** @var list<string> */
    private const ANDROID_FONTS = [
        'Roboto', 'Noto Sans', 'Droid Sans', 'Droid Sans Mono', 'Cutive Mono',
        'Coming Soon', 'Dancing Script', 'Carrois Gothic SC', 'Noto Color Emoji',
    ];

    /** @var list<string> */
    private const IOS_FONTS = [
        'San Francisco', 'Helvetica', 'Helvetica Neue', 'Arial', 'Times New Roman',
        'Courier New', 'Georgia', 'Verdana', 'Apple Color Emoji',
    ];

    /** @var list<array{vendor: string, renderer: string}> */
    private const WIN_WEBGL = [
        ['vendor' => 'Google Inc. (NVIDIA)', 'renderer' => 'ANGLE (NVIDIA, NVIDIA GeForce GTX 1660 Direct3D11 vs_5_0 ps_5_0, D3D11)'],
        ['vendor' => 'Google Inc. (NVIDIA)', 'renderer' => 'ANGLE (NVIDIA, NVIDIA GeForce RTX 3060 Direct3D11 vs_5_0 ps_5_0, D3D11)'],
        ['vendor' => 'Google Inc. (AMD)', 'renderer' => 'ANGLE (AMD, AMD Radeon RX 580 Series Direct3D11 vs_5_0 ps_5_0, D3D11)'],
        ['vendor' => 'Google Inc. (Intel)', 'renderer' => 'ANGLE (Intel, Intel(R) UHD Graphics 630 Direct3D11 vs_5_0 ps_5_0, D3D11)'],
        ['vendor' => 'Google Inc. (Intel)', 'renderer' => 'ANGLE (Intel, Intel(R) Iris(R) Xe Graphics Direct3D11 vs_5_0 ps_5_0, D3D11)'],
    ];

    /** @var list<array{vendor: string, renderer: string}> */
    private const MAC_WEBGL = [
        ['vendor' => 'Google Inc. (Apple)', 'renderer' => 'ANGLE (Apple, ANGLE Metal Renderer: Apple M1, Unspecified Version)'],
        ['vendor' => 'Google Inc. (Apple)', 'renderer' => 'ANGLE (Apple, ANGLE Metal Renderer: Apple M2, Unspecified Version)'],
        ['vendor' => 'Google Inc. (Apple)', 'renderer' => 'ANGLE (Apple, ANGLE Metal Renderer: AMD Radeon Pro 5500M, Unspecified Version)'],
        ['vendor' => 'Apple Inc.', 'renderer' => 'Apple GPU'],
    ];

    /** @var list<array{vendor: string, renderer: string}> */
    private const ANDROID_WEBGL = [
        ['vendor' => 'Qualcomm', 'renderer' => 'Adreno (TM) 730'],
        ['vendor' => 'Qualcomm', 'renderer' => 'Adreno (TM) 640'],
        ['vendor' => 'ARM', 'renderer' => 'Mali-G78'],
        ['vendor' => 'ARM', 'renderer' => 'Mali-G710'],
        ['vendor' => 'Google Inc. (Qualcomm)', 'renderer' => 'ANGLE (Qualcomm, Adreno (TM) 740, OpenGL ES 3.2)'],
    ];

    /** @var list<array{vendor: string, renderer: string}> */
    private const IOS_WEBGL = [
        ['vendor' => 'Apple Inc.', 'renderer' => 'Apple GPU'],
        ['vendor' => 'Apple Inc.', 'renderer' => 'Apple A15 GPU'],
        ['vendor' => 'Apple Inc.', 'renderer' => 'Apple A16 GPU'],
    ];

    public function __construct(
        private readonly GeoMapper $geoMapper = new GeoMapper(),
    ) {
    }

    /**
     * @param array{
     *     country?: string|null,
     *     city?: string|null,
     *     timezone?: string|null,
     *     latitude?: float|null,
     *     longitude?: float|null,
     *     device?: 'desktop'|'mobile'|null,
     *     seed?: string|null
     * } $opts
     * @return array<string, mixed>
     */
    public function generate(array $opts = []): array
    {
        $geo = $this->geoMapper->map([
            'country' => $opts['country'] ?? null,
            'city' => $opts['city'] ?? null,
            'timezone' => $opts['timezone'] ?? null,
        ]);

        $seed = $opts['seed'] ?? bin2hex(random_bytes(16));
        $rng = $this->rng($seed);

        $deviceHint = $opts['device'] ?? null;
        $isDesktop = $deviceHint === 'desktop'
            || ($deviceHint !== 'mobile' && $rng() < self::DESKTOP_RATIO);

        $fp = $isDesktop
            ? $this->generateDesktop($rng, $geo, $seed)
            : $this->generateMobile($rng, $geo, $seed);

        if (isset($opts['latitude'], $opts['longitude'])) {
            $fp['geolocation'] = [
                'latitude' => (float) $opts['latitude'],
                'longitude' => (float) $opts['longitude'],
                'accuracy' => 25 + (int) floor($rng() * 50),
            ];
        } else {
            $fp['geolocation'] = $this->geolocationForCountry($geo['country'], $rng);
        }

        $fp['hash'] = $this->hash($fp);

        return $fp;
    }

    /**
     * Stable uniqueness hash over fingerprint identity fields.
     *
     * @param array<string, mixed> $fp
     */
    public function hash(array $fp): string
    {
        $payload = [
            'ua' => $fp['ua'] ?? null,
            'platform' => $fp['platform'] ?? null,
            'screen' => $fp['screen'] ?? null,
            'dpr' => $fp['dpr'] ?? null,
            'timezone' => $fp['timezone'] ?? null,
            'lang' => $fp['lang'] ?? null,
            'fonts' => $fp['fonts'] ?? null,
            'webgl_vendor' => $fp['webgl_vendor'] ?? null,
            'webgl_renderer' => $fp['webgl_renderer'] ?? null,
            'canvas_hash_seed' => $fp['canvas_hash_seed'] ?? null,
            'audio_seed' => $fp['audio_seed'] ?? null,
            'hardwareConcurrency' => $fp['hardwareConcurrency'] ?? null,
            'deviceMemory' => $fp['deviceMemory'] ?? null,
            'plugins' => $fp['plugins'] ?? null,
            'clientRects' => $fp['clientRects'] ?? null,
            'touch' => $fp['touch'] ?? null,
            'mediaDevices' => $fp['mediaDevices'] ?? null,
            'profile' => $fp['profile'] ?? null,
            'os' => $fp['os'] ?? null,
        ];

        return hash('sha256', (string) json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    /**
     * Map fingerprint → account_fingerprints row (CONTRACT columns + extra.fp).
     *
     * @param array<string, mixed> $fp
     * @param array<string, mixed> $extraMerge merged into extra alongside fp
     * @return array<string, mixed>
     */
    public function toRow(int $accountId, array $fp, array $extraMerge = []): array
    {
        $screen = is_array($fp['screen'] ?? null) ? $fp['screen'] : [];
        $fonts = is_array($fp['fonts'] ?? null) ? $fp['fonts'] : [];
        $webglKey = ($fp['webgl_vendor'] ?? '') . '|' . ($fp['webgl_renderer'] ?? '');

        $extra = array_merge($extraMerge, [
            'fp' => $fp,
        ]);

        return [
            'account_id' => $accountId,
            'user_agent' => (string) ($fp['ua'] ?? 'Mozilla/5.0'),
            'platform' => isset($fp['platform']) ? (string) $fp['platform'] : null,
            'viewport_w' => isset($screen['width']) ? (int) $screen['width'] : null,
            'viewport_h' => isset($screen['height']) ? (int) $screen['height'] : null,
            'timezone' => isset($fp['timezone']) ? (string) $fp['timezone'] : null,
            'locale' => isset($fp['lang']) ? (string) $fp['lang'] : null,
            'webgl_hash' => hash('sha256', $webglKey),
            'canvas_hash' => isset($fp['canvas_hash_seed'])
                ? hash('sha256', (string) $fp['canvas_hash_seed'])
                : null,
            'fonts_hash' => hash('sha256', (string) json_encode($fonts)),
            'extra' => $extra,
        ];
    }

    /**
     * Extract fp object from account_fingerprints row.
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>|null
     */
    public function fromRow(array $row): ?array
    {
        $extra = $row['extra'] ?? null;
        if (is_string($extra)) {
            $extra = json_decode($extra, true);
        }
        if (!is_array($extra)) {
            return null;
        }
        if (isset($extra['fp']) && is_array($extra['fp'])) {
            return $extra['fp'];
        }

        // Legacy / partial rows without nested fp
        if (isset($extra['ua']) || isset($extra['platform'])) {
            return $extra;
        }

        return null;
    }

    /**
     * @param callable(): float $rng
     * @param array{country: string|null, timezone: string, locale: string, languages: list<string>, accept_language: string, city: string|null} $geo
     * @return array<string, mixed>
     */
    private function generateDesktop(callable $rng, array $geo, string $seed): array
    {
        $isMac = $rng() < 0.35;
        $chromeMajor = 120 + (int) floor($rng() * 12);
        $chromeBuild = 6000 + (int) floor($rng() * 800);
        $chromePatch = (int) floor($rng() * 200);

        if ($isMac) {
            $osVer = $this->pick($rng, ['10_15_7', '13_6_1', '14_2_1', '14_4_1', '14_5']);
            $ua = sprintf(
                'Mozilla/5.0 (Macintosh; Intel Mac OS X %s) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/%d.0.%d.%d Safari/537.36',
                $osVer,
                $chromeMajor,
                $chromeBuild,
                $chromePatch
            );
            $platform = 'MacIntel';
            $os = 'macOS';
            $fonts = $this->subsetFonts(self::MAC_FONTS, $rng, 12, 18);
            $webgl = $this->pick($rng, self::MAC_WEBGL);
            $plugins = $this->chromePlugins($rng);
            $hw = $this->pick($rng, [4, 8, 10, 12]);
            $mem = $this->pick($rng, [8, 16, 32]);
        } else {
            $winToken = $this->pick($rng, ['Windows NT 10.0; Win64; x64', 'Windows NT 10.0; WOW64']);
            $ua = sprintf(
                'Mozilla/5.0 (%s) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/%d.0.%d.%d Safari/537.36',
                $winToken,
                $chromeMajor,
                $chromeBuild,
                $chromePatch
            );
            $platform = 'Win32';
            $os = 'Windows';
            $fonts = $this->subsetFonts(self::WIN_FONTS, $rng, 14, 20);
            $webgl = $this->pick($rng, self::WIN_WEBGL);
            $plugins = $this->chromePlugins($rng);
            $hw = $this->pick($rng, [4, 6, 8, 12, 16]);
            $mem = $this->pick($rng, [4, 8, 16]);
        }

        $screen = $this->pick($rng, self::DESKTOP_SCREENS);

        return $this->assemble(
            profile: 'desktop',
            os: $os,
            ua: $ua,
            platform: $platform,
            screen: ['width' => $screen['w'], 'height' => $screen['h'], 'availWidth' => $screen['w'], 'availHeight' => $screen['h'] - 40],
            dpr: $screen['dpr'],
            geo: $geo,
            fonts: $fonts,
            webgl: $webgl,
            hw: $hw,
            mem: $mem,
            plugins: $plugins,
            touch: ['maxTouchPoints' => 0, 'touchEvent' => false, 'touchStart' => false],
            rng: $rng,
            seed: $seed,
            model: null,
        );
    }

    /**
     * @param callable(): float $rng
     * @param array{country: string|null, timezone: string, locale: string, languages: list<string>, accept_language: string, city: string|null} $geo
     * @return array<string, mixed>
     */
    private function generateMobile(callable $rng, array $geo, string $seed): array
    {
        $isIos = $rng() < 0.45;
        $chromeMajor = 120 + (int) floor($rng() * 12);

        if ($isIos) {
            $screen = $this->pick($rng, self::IOS_SCREENS);
            $iosMajor = $this->pick($rng, [16, 17, 18]);
            $iosMinor = (int) floor($rng() * 5);
            $ua = sprintf(
                'Mozilla/5.0 (iPhone; CPU iPhone OS %d_%d like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) CriOS/%d.0.0.0 Mobile/15E148 Safari/604.1',
                $iosMajor,
                $iosMinor,
                $chromeMajor
            );
            $platform = 'iPhone';
            $os = 'iOS';
            $fonts = $this->subsetFonts(self::IOS_FONTS, $rng, 7, 9);
            $webgl = $this->pick($rng, self::IOS_WEBGL);
            $hw = $this->pick($rng, [4, 6]);
            $mem = $this->pick($rng, [4, 6, 8]);
            $touchPts = $this->pick($rng, [5, 5]);
        } else {
            $screen = $this->pick($rng, self::ANDROID_SCREENS);
            $androidVer = $this->pick($rng, [12, 13, 14]);
            $ua = sprintf(
                'Mozilla/5.0 (Linux; Android %d; %s) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/%d.0.0.0 Mobile Safari/537.36',
                $androidVer,
                $screen['model'],
                $chromeMajor
            );
            $platform = 'Linux armv8l';
            $os = 'Android';
            $fonts = $this->subsetFonts(self::ANDROID_FONTS, $rng, 6, 9);
            $webgl = $this->pick($rng, self::ANDROID_WEBGL);
            $hw = $this->pick($rng, [4, 6, 8]);
            $mem = $this->pick($rng, [2, 4, 6, 8]);
            $touchPts = $this->pick($rng, [5, 10]);
        }

        return $this->assemble(
            profile: 'mobile',
            os: $os,
            ua: $ua,
            platform: $platform,
            screen: [
                'width' => $screen['w'],
                'height' => $screen['h'],
                'availWidth' => $screen['w'],
                'availHeight' => $screen['h'],
            ],
            dpr: $screen['dpr'],
            geo: $geo,
            fonts: $fonts,
            webgl: $webgl,
            hw: $hw,
            mem: $mem,
            plugins: [],
            touch: ['maxTouchPoints' => $touchPts, 'touchEvent' => true, 'touchStart' => true],
            rng: $rng,
            seed: $seed,
            model: $screen['model'],
        );
    }

    /**
     * @param array{country: string|null, timezone: string, locale: string, languages: list<string>, accept_language: string, city: string|null} $geo
     * @param list<string> $fonts
     * @param array{vendor: string, renderer: string} $webgl
     * @param list<array<string, mixed>> $plugins
     * @param array{maxTouchPoints: int, touchEvent: bool, touchStart: bool} $touch
     * @param callable(): float $rng
     * @return array<string, mixed>
     */
    private function assemble(
        string $profile,
        string $os,
        string $ua,
        string $platform,
        array $screen,
        float $dpr,
        array $geo,
        array $fonts,
        array $webgl,
        int $hw,
        int $mem,
        array $plugins,
        array $touch,
        callable $rng,
        string $seed,
        ?string $model,
    ): array {
        $canvasSeed = substr(hash('sha256', $seed . '|canvas|' . (string) $rng()), 0, 32);
        $audioSeed = substr(hash('sha256', $seed . '|audio|' . (string) $rng()), 0, 32);
        $rectsNoise = round(($rng() - 0.5) * 0.0002, 8);

        $mediaCount = $profile === 'desktop'
            ? $this->pick($rng, [1, 2, 3])
            : $this->pick($rng, [1, 2]);

        $mediaDevices = [];
        for ($i = 0; $i < $mediaCount; $i++) {
            $mediaDevices[] = [
                'kind' => $i === 0 ? 'audioinput' : ($i === 1 ? 'videoinput' : 'audiooutput'),
                'label' => '',
                'deviceId' => substr(hash('sha256', $seed . '|md|' . $i . '|' . (string) $rng()), 0, 64),
                'groupId' => substr(hash('sha256', $seed . '|mg|' . (string) $rng()), 0, 64),
            ];
        }

        return [
            'profile' => $profile,
            'os' => $os,
            'model' => $model,
            'ua' => $ua,
            'platform' => $platform,
            'screen' => $screen,
            'dpr' => $dpr,
            'timezone' => $geo['timezone'],
            'lang' => $geo['locale'],
            'languages' => $geo['languages'],
            'accept_language' => $geo['accept_language'],
            'fonts' => $fonts,
            'webgl_vendor' => $webgl['vendor'],
            'webgl_renderer' => $webgl['renderer'],
            'canvas_hash_seed' => $canvasSeed,
            'audio_seed' => $audioSeed,
            'hardwareConcurrency' => $hw,
            'deviceMemory' => $mem,
            'plugins' => $plugins,
            'clientRects' => [
                'noise' => $rectsNoise,
                'seed' => substr(hash('sha256', $seed . '|rects'), 0, 16),
            ],
            'touch' => $touch,
            'mediaDevices' => $mediaDevices,
            'geo_country' => $geo['country'],
            'geo_city' => $geo['city'],
            'seed' => $seed,
        ];
    }

    /**
     * @param callable(): float $rng
     * @return list<array{name: string, filename: string, description: string}>
     */
    private function chromePlugins(callable $rng): array
    {
        $base = [
            [
                'name' => 'PDF Viewer',
                'filename' => 'internal-pdf-viewer',
                'description' => 'Portable Document Format',
            ],
            [
                'name' => 'Chrome PDF Viewer',
                'filename' => 'internal-pdf-viewer',
                'description' => 'Portable Document Format',
            ],
            [
                'name' => 'Chromium PDF Viewer',
                'filename' => 'internal-pdf-viewer',
                'description' => 'Portable Document Format',
            ],
            [
                'name' => 'Microsoft Edge PDF Viewer',
                'filename' => 'internal-pdf-viewer',
                'description' => 'Portable Document Format',
            ],
            [
                'name' => 'WebKit built-in PDF',
                'filename' => 'internal-pdf-viewer',
                'description' => 'Portable Document Format',
            ],
        ];

        // Keep all five (real Chrome) or drop last randomly for slight entropy
        if ($rng() < 0.15) {
            return array_slice($base, 0, 4);
        }

        return $base;
    }

    /**
     * @param list<string> $pool
     * @param callable(): float $rng
     * @return list<string>
     */
    private function subsetFonts(array $pool, callable $rng, int $min, int $max): array
    {
        $count = min(count($pool), max($min, (int) floor($min + $rng() * ($max - $min + 1))));
        $shuffled = $pool;
        // Fisher–Yates with our rng
        for ($i = count($shuffled) - 1; $i > 0; $i--) {
            $j = (int) floor($rng() * ($i + 1));
            [$shuffled[$i], $shuffled[$j]] = [$shuffled[$j], $shuffled[$i]];
        }
        $subset = array_slice($shuffled, 0, $count);
        sort($subset, SORT_STRING);

        return array_values($subset);
    }

    /**
     * @param callable(): float $rng
     * @template T
     * @param list<T> $items
     * @return T
     */
    private function pick(callable $rng, array $items): mixed
    {
        return $items[(int) floor($rng() * count($items))];
    }

    /**
     * Deterministic [0,1) PRNG from seed (xorshift32).
     *
     * @return callable(): float
     */
    private function rng(string $seed): callable
    {
        $state = unpack('N', hash('sha256', $seed, true))[1] ?: 1;

        return static function () use (&$state): float {
            $state ^= ($state << 13) & 0xFFFFFFFF;
            $state ^= ($state >> 17) & 0xFFFFFFFF;
            $state ^= ($state << 5) & 0xFFFFFFFF;
            $state &= 0xFFFFFFFF;

            return ($state % 1000000) / 1000000;
        };
    }

    /**
     * Approximate capital/city coords for Emulation.setGeolocationOverride.
     *
     * @param callable(): float $rng
     * @return array{latitude: float, longitude: float, accuracy: int}
     */
    private function geolocationForCountry(?string $country, callable $rng): array
    {
        $coords = [
            'RU' => [55.7558, 37.6173],
            'UA' => [50.4501, 30.5234],
            'BY' => [53.9006, 27.5590],
            'KZ' => [43.2220, 76.8512],
            'DE' => [52.5200, 13.4050],
            'NL' => [52.3676, 4.9041],
            'PL' => [52.2297, 21.0122],
            'TR' => [41.0082, 28.9784],
            'US' => [40.7128, -74.0060],
            'GB' => [51.5074, -0.1278],
            'FR' => [48.8566, 2.3522],
            'ES' => [40.4168, -3.7038],
            'IT' => [41.9028, 12.4964],
            'CZ' => [50.0755, 14.4378],
            'RO' => [44.4268, 26.1025],
            'AE' => [25.2048, 55.2708],
            'CN' => [31.2304, 121.4737],
            'JP' => [35.6762, 139.6503],
            'BR' => [-23.5505, -46.6333],
            'IN' => [28.6139, 77.2090],
        ];

        $base = ($country !== null && isset($coords[$country]))
            ? $coords[$country]
            : [0.0, 0.0];

        return [
            'latitude' => round($base[0] + ($rng() - 0.5) * 0.08, 6),
            'longitude' => round($base[1] + ($rng() - 0.5) * 0.08, 6),
            'accuracy' => 20 + (int) floor($rng() * 60),
        ];
    }
}
