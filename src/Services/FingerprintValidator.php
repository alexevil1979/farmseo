<?php

declare(strict_types=1);

namespace Farm\Services;

/**
 * Validates fingerprint internal consistency and alignment with proxy geo.
 *
 * Checks: ua ↔ platform ↔ screen ↔ profile/touch ↔ timezone ↔ proxy country.
 */
final class FingerprintValidator
{
    /** @var array<string, list<string>> country => acceptable IANA timezones */
    private const COUNTRY_TIMEZONES = [
        'RU' => ['Europe/Moscow', 'Europe/Samara', 'Asia/Yekaterinburg', 'Asia/Novosibirsk', 'Asia/Krasnoyarsk', 'Asia/Irkutsk', 'Asia/Yakutsk', 'Asia/Vladivostok', 'Europe/Kaliningrad'],
        'UA' => ['Europe/Kyiv', 'Europe/Uzhgorod', 'Europe/Zaporozhye'],
        'BY' => ['Europe/Minsk'],
        'KZ' => ['Asia/Almaty', 'Asia/Aqtobe', 'Asia/Aqtau', 'Asia/Oral'],
        'DE' => ['Europe/Berlin', 'Europe/Busingen'],
        'NL' => ['Europe/Amsterdam'],
        'PL' => ['Europe/Warsaw'],
        'TR' => ['Europe/Istanbul'],
        'US' => ['America/New_York', 'America/Chicago', 'America/Denver', 'America/Los_Angeles', 'America/Phoenix', 'America/Anchorage', 'Pacific/Honolulu'],
        'GB' => ['Europe/London'],
        'FR' => ['Europe/Paris'],
        'ES' => ['Europe/Madrid', 'Atlantic/Canary'],
        'IT' => ['Europe/Rome'],
        'CZ' => ['Europe/Prague'],
        'RO' => ['Europe/Bucharest'],
        'AE' => ['Asia/Dubai'],
        'CN' => ['Asia/Shanghai', 'Asia/Urumqi'],
        'JP' => ['Asia/Tokyo'],
        'BR' => ['America/Sao_Paulo', 'America/Manaus', 'America/Fortaleza', 'America/Recife'],
        'IN' => ['Asia/Kolkata'],
    ];

    /** @var array<string, list<string>> country => acceptable BCP47 locale prefixes */
    private const COUNTRY_LANG_PREFIXES = [
        'RU' => ['ru'],
        'UA' => ['uk', 'ru'],
        'BY' => ['be', 'ru'],
        'KZ' => ['kk', 'ru'],
        'DE' => ['de'],
        'NL' => ['nl'],
        'PL' => ['pl'],
        'TR' => ['tr'],
        'US' => ['en'],
        'GB' => ['en'],
        'FR' => ['fr'],
        'ES' => ['es'],
        'IT' => ['it'],
        'CZ' => ['cs'],
        'RO' => ['ro'],
        'AE' => ['ar', 'en'],
        'CN' => ['zh'],
        'JP' => ['ja'],
        'BR' => ['pt'],
        'IN' => ['en', 'hi'],
    ];

    public function __construct(
        private readonly GeoMapper $geoMapper = new GeoMapper(),
    ) {
    }

    /**
     * @param array<string, mixed> $fp
     * @param array<string, mixed>|null $proxyRow proxies row (country + meta) when checking geo alignment
     * @return array{ok: bool, errors: list<string>}
     */
    public function validate(array $fp, ?array $proxyRow = null): array
    {
        $errors = [];

        $errors = array_merge($errors, $this->checkRequired($fp));
        $errors = array_merge($errors, $this->checkUaPlatform($fp));
        $errors = array_merge($errors, $this->checkScreenProfile($fp));
        $errors = array_merge($errors, $this->checkTouchPlugins($fp));
        $errors = array_merge($errors, $this->checkHardware($fp));

        $proxyCountry = $this->resolveProxyCountry($proxyRow, $fp);
        if ($proxyCountry !== null) {
            $errors = array_merge($errors, $this->checkTimezoneCountry($fp, $proxyCountry));
            $errors = array_merge($errors, $this->checkLangCountry($fp, $proxyCountry));
        } elseif (!empty($fp['geo_country'])) {
            $errors = array_merge($errors, $this->checkTimezoneCountry($fp, strtoupper((string) $fp['geo_country'])));
            $errors = array_merge($errors, $this->checkLangCountry($fp, strtoupper((string) $fp['geo_country'])));
        }

        $errors = array_merge($errors, $this->checkAsn($fp, $proxyRow));

        return [
            'ok' => $errors === [],
            'errors' => $errors,
        ];
    }

    /**
     * Convenience: returns true only when fully consistent.
     *
     * @param array<string, mixed> $fp
     * @param array<string, mixed>|null $proxyRow
     */
    public function isValid(array $fp, ?array $proxyRow = null): bool
    {
        return $this->validate($fp, $proxyRow)['ok'];
    }

    /**
     * @param array<string, mixed> $fp
     * @return list<string>
     */
    private function checkRequired(array $fp): array
    {
        $errors = [];
        $required = [
            'ua', 'platform', 'screen', 'dpr', 'timezone', 'lang', 'fonts',
            'webgl_vendor', 'webgl_renderer', 'canvas_hash_seed', 'audio_seed',
            'hardwareConcurrency', 'deviceMemory', 'plugins', 'clientRects',
            'touch', 'mediaDevices', 'profile',
        ];
        foreach ($required as $key) {
            if (!array_key_exists($key, $fp) || $fp[$key] === null || $fp[$key] === '') {
                $errors[] = "missing_field:{$key}";
            }
        }

        if (isset($fp['screen']) && (!is_array($fp['screen']) || !isset($fp['screen']['width'], $fp['screen']['height']))) {
            $errors[] = 'invalid_screen';
        }

        return $errors;
    }

    /**
     * @param array<string, mixed> $fp
     * @return list<string>
     */
    private function checkUaPlatform(array $fp): array
    {
        $errors = [];
        $ua = (string) ($fp['ua'] ?? '');
        $platform = (string) ($fp['platform'] ?? '');
        $profile = (string) ($fp['profile'] ?? '');
        $os = (string) ($fp['os'] ?? '');

        $uaLower = strtolower($ua);

        // Order matters: iOS UAs contain "like Mac OS X" — check mobile tokens first.
        if (str_contains($uaLower, 'iphone') || str_contains($uaLower, 'ipad')) {
            if ($platform !== 'iPhone' && $platform !== 'iPad') {
                $errors[] = 'ua_platform_mismatch:ios_expects_iPhone_or_iPad';
            }
            if ($profile !== '' && $profile !== 'mobile') {
                $errors[] = 'ua_profile_mismatch:ios_expects_mobile';
            }
        } elseif (str_contains($uaLower, 'android')) {
            if (!str_starts_with($platform, 'Linux')) {
                $errors[] = 'ua_platform_mismatch:android_expects_Linux';
            }
            if ($profile !== '' && $profile !== 'mobile') {
                $errors[] = 'ua_profile_mismatch:android_expects_mobile';
            }
            if (!str_contains($uaLower, 'mobile') && $profile === 'mobile') {
                $errors[] = 'ua_missing_mobile_token';
            }
        } elseif (str_contains($uaLower, 'windows') || str_contains($ua, 'Win64') || str_contains($ua, 'WOW64')) {
            if ($platform !== 'Win32') {
                $errors[] = 'ua_platform_mismatch:windows_expects_Win32';
            }
            if ($profile !== '' && $profile !== 'desktop') {
                $errors[] = 'ua_profile_mismatch:windows_expects_desktop';
            }
            if ($os !== '' && $os !== 'Windows') {
                $errors[] = 'ua_os_mismatch:windows';
            }
        } elseif (str_contains($uaLower, 'macintosh') || str_contains($uaLower, 'mac os x')) {
            if ($platform !== 'MacIntel') {
                $errors[] = 'ua_platform_mismatch:macos_expects_MacIntel';
            }
            if ($profile !== '' && $profile !== 'desktop') {
                $errors[] = 'ua_profile_mismatch:macos_expects_desktop';
            }
        }

        return $errors;
    }

    /**
     * @param array<string, mixed> $fp
     * @return list<string>
     */
    private function checkScreenProfile(array $fp): array
    {
        $errors = [];
        if (!is_array($fp['screen'] ?? null)) {
            return $errors;
        }

        $w = (int) ($fp['screen']['width'] ?? 0);
        $h = (int) ($fp['screen']['height'] ?? 0);
        $dpr = (float) ($fp['dpr'] ?? 0);
        $profile = (string) ($fp['profile'] ?? '');

        if ($w < 100 || $h < 100) {
            $errors[] = 'screen_too_small';
        }

        if ($dpr < 0.5 || $dpr > 4.0) {
            $errors[] = 'dpr_out_of_range';
        }

        if ($profile === 'desktop') {
            if ($w < 1024) {
                $errors[] = 'desktop_screen_too_narrow';
            }
            if ($dpr > 2.5) {
                $errors[] = 'desktop_dpr_too_high';
            }
        }

        if ($profile === 'mobile') {
            if ($w > 600) {
                $errors[] = 'mobile_screen_too_wide';
            }
            if ($dpr < 1.5) {
                $errors[] = 'mobile_dpr_too_low';
            }
        }

        return $errors;
    }

    /**
     * @param array<string, mixed> $fp
     * @return list<string>
     */
    private function checkTouchPlugins(array $fp): array
    {
        $errors = [];
        $profile = (string) ($fp['profile'] ?? '');
        $touch = is_array($fp['touch'] ?? null) ? $fp['touch'] : [];
        $plugins = is_array($fp['plugins'] ?? null) ? $fp['plugins'] : null;
        $maxTouch = (int) ($touch['maxTouchPoints'] ?? 0);

        if ($profile === 'desktop') {
            if ($maxTouch > 1) {
                $errors[] = 'desktop_touch_points_too_high';
            }
            if ($plugins === []) {
                $errors[] = 'desktop_plugins_empty';
            }
        }

        if ($profile === 'mobile') {
            if ($maxTouch < 1) {
                $errors[] = 'mobile_missing_touch';
            }
            if ($plugins !== null && $plugins !== []) {
                $errors[] = 'mobile_plugins_should_be_empty';
            }
            if (empty($touch['touchEvent']) || empty($touch['touchStart'])) {
                $errors[] = 'mobile_touch_events_missing';
            }
        }

        return $errors;
    }

    /**
     * @param array<string, mixed> $fp
     * @return list<string>
     */
    private function checkHardware(array $fp): array
    {
        $errors = [];
        $hw = (int) ($fp['hardwareConcurrency'] ?? 0);
        $mem = (int) ($fp['deviceMemory'] ?? 0);

        if ($hw < 1 || $hw > 32) {
            $errors[] = 'hardwareConcurrency_out_of_range';
        }
        if (!in_array($mem, [2, 4, 6, 8, 16, 32], true)) {
            $errors[] = 'deviceMemory_out_of_range';
        }

        if (!is_array($fp['fonts'] ?? null) || $fp['fonts'] === []) {
            $errors[] = 'fonts_empty';
        }

        return $errors;
    }

    /**
     * Critical acceptance rule: timezone must match proxy country.
     *
     * @param array<string, mixed> $fp
     * @return list<string>
     */
    private function checkTimezoneCountry(array $fp, string $country): array
    {
        $errors = [];
        $tz = (string) ($fp['timezone'] ?? '');
        $country = strtoupper(substr($country, 0, 2));

        if ($tz === '') {
            $errors[] = 'timezone_missing';

            return $errors;
        }

        try {
            new \DateTimeZone($tz);
        } catch (\Exception) {
            $errors[] = 'timezone_invalid:' . $tz;

            return $errors;
        }

        $allowed = self::COUNTRY_TIMEZONES[$country] ?? null;
        $expected = $this->geoMapper->timezoneForCountry($country);

        if ($allowed !== null) {
            if (!in_array($tz, $allowed, true)) {
                $errors[] = sprintf('tz_country_mismatch:tz=%s country=%s expected_one_of=%s', $tz, $country, implode(',', $allowed));
            }
        } elseif ($expected !== 'UTC' && $tz !== $expected) {
            $errors[] = sprintf('tz_country_mismatch:tz=%s country=%s expected=%s', $tz, $country, $expected);
        }

        // fp.geo_country must not contradict proxy country when both set
        if (!empty($fp['geo_country']) && strtoupper((string) $fp['geo_country']) !== $country) {
            $errors[] = sprintf(
                'geo_country_mismatch:fp=%s proxy=%s',
                strtoupper((string) $fp['geo_country']),
                $country
            );
        }

        return $errors;
    }

    /**
     * @param array<string, mixed> $fp
     * @return list<string>
     */
    private function checkLangCountry(array $fp, string $country): array
    {
        $errors = [];
        $lang = (string) ($fp['lang'] ?? '');
        $country = strtoupper(substr($country, 0, 2));
        $prefixes = self::COUNTRY_LANG_PREFIXES[$country] ?? null;

        if ($lang === '') {
            $errors[] = 'lang_missing';

            return $errors;
        }

        if ($prefixes !== null) {
            $primary = strtolower(explode('-', $lang)[0]);
            if (!in_array($primary, $prefixes, true)) {
                $errors[] = sprintf('lang_country_mismatch:lang=%s country=%s', $lang, $country);
            }
        }

        return $errors;
    }

    /**
     * ASN alignment. In GEO_PROVIDER=stub mode missing ASN is tolerated (fixtures often omit it).
     *
     * @param array<string, mixed> $fp
     * @param array<string, mixed>|null $proxyRow
     * @return list<string>
     */
    private function checkAsn(array $fp, ?array $proxyRow): array
    {
        if (GeoProviderFactory::isStub()) {
            return [];
        }

        $proxyAsn = $this->resolveProxyAsn($proxyRow);
        $fpAsn = isset($fp['asn']) ? HttpGeoProvider::normalizeAsn($fp['asn']) : null;

        // Live mode: if fingerprint declares ASN it must match proxy meta when both present.
        if ($fpAsn !== null && $proxyAsn !== null && strtoupper($fpAsn) !== strtoupper($proxyAsn)) {
            return [sprintf('asn_mismatch:fp=%s proxy=%s', $fpAsn, $proxyAsn)];
        }

        // Live mode after a completed geo probe (provider set): ASN should be present on proxy meta.
        $meta = $this->proxyMeta($proxyRow);
        $provider = isset($meta['provider']) ? (string) $meta['provider'] : '';
        if ($provider !== '' && $provider !== 'stub' && ($proxyAsn === null || $proxyAsn === '')) {
            return ['missing_asn'];
        }

        return [];
    }

    /**
     * @param array<string, mixed>|null $proxyRow
     */
    private function resolveProxyAsn(?array $proxyRow): ?string
    {
        if ($proxyRow === null) {
            return null;
        }
        if (isset($proxyRow['asn'])) {
            return HttpGeoProvider::normalizeAsn($proxyRow['asn']);
        }
        $meta = $this->proxyMeta($proxyRow);
        if (isset($meta['asn'])) {
            return HttpGeoProvider::normalizeAsn($meta['asn']);
        }

        return null;
    }

    /**
     * @param array<string, mixed>|null $proxyRow
     * @return array<string, mixed>
     */
    private function proxyMeta(?array $proxyRow): array
    {
        if ($proxyRow === null) {
            return [];
        }
        $meta = $proxyRow['meta'] ?? null;
        if (is_string($meta)) {
            $meta = json_decode($meta, true);
        }

        return is_array($meta) ? $meta : [];
    }

    /**
     * @param array<string, mixed>|null $proxyRow
     * @param array<string, mixed> $fp
     */
    private function resolveProxyCountry(?array $proxyRow, array $fp): ?string
    {
        if ($proxyRow === null) {
            return null;
        }

        if (!empty($proxyRow['country'])) {
            return strtoupper(substr((string) $proxyRow['country'], 0, 2));
        }

        $meta = $this->proxyMeta($proxyRow);
        if (!empty($meta['country'])) {
            return strtoupper(substr((string) $meta['country'], 0, 2));
        }

        return null;
    }
}
