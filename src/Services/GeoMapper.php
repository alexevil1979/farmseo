<?php

declare(strict_types=1);

namespace Farm\Services;

/**
 * Maps proxy geo (country/city/tz) → account locale settings.
 */
final class GeoMapper
{
    /**
     * country ISO2 => [timezone, locale, languages[], default_city_tz fallback]
     *
     * @var array<string, array{timezone: string, locale: string, languages: list<string>}>
     */
    private const COUNTRY_MAP = [
        'RU' => ['timezone' => 'Europe/Moscow', 'locale' => 'ru-RU', 'languages' => ['ru-RU', 'ru', 'en-US']],
        'UA' => ['timezone' => 'Europe/Kyiv', 'locale' => 'uk-UA', 'languages' => ['uk-UA', 'uk', 'ru', 'en-US']],
        'BY' => ['timezone' => 'Europe/Minsk', 'locale' => 'be-BY', 'languages' => ['be-BY', 'ru', 'en-US']],
        'KZ' => ['timezone' => 'Asia/Almaty', 'locale' => 'kk-KZ', 'languages' => ['kk-KZ', 'ru', 'en-US']],
        'DE' => ['timezone' => 'Europe/Berlin', 'locale' => 'de-DE', 'languages' => ['de-DE', 'de', 'en-US']],
        'NL' => ['timezone' => 'Europe/Amsterdam', 'locale' => 'nl-NL', 'languages' => ['nl-NL', 'nl', 'en-US']],
        'PL' => ['timezone' => 'Europe/Warsaw', 'locale' => 'pl-PL', 'languages' => ['pl-PL', 'pl', 'en-US']],
        'TR' => ['timezone' => 'Europe/Istanbul', 'locale' => 'tr-TR', 'languages' => ['tr-TR', 'tr', 'en-US']],
        'US' => ['timezone' => 'America/New_York', 'locale' => 'en-US', 'languages' => ['en-US', 'en']],
        'GB' => ['timezone' => 'Europe/London', 'locale' => 'en-GB', 'languages' => ['en-GB', 'en']],
        'FR' => ['timezone' => 'Europe/Paris', 'locale' => 'fr-FR', 'languages' => ['fr-FR', 'fr', 'en-US']],
        'ES' => ['timezone' => 'Europe/Madrid', 'locale' => 'es-ES', 'languages' => ['es-ES', 'es', 'en-US']],
        'IT' => ['timezone' => 'Europe/Rome', 'locale' => 'it-IT', 'languages' => ['it-IT', 'it', 'en-US']],
        'CZ' => ['timezone' => 'Europe/Prague', 'locale' => 'cs-CZ', 'languages' => ['cs-CZ', 'cs', 'en-US']],
        'RO' => ['timezone' => 'Europe/Bucharest', 'locale' => 'ro-RO', 'languages' => ['ro-RO', 'ro', 'en-US']],
        'AE' => ['timezone' => 'Asia/Dubai', 'locale' => 'ar-AE', 'languages' => ['ar-AE', 'ar', 'en-US']],
        'CN' => ['timezone' => 'Asia/Shanghai', 'locale' => 'zh-CN', 'languages' => ['zh-CN', 'zh', 'en-US']],
        'JP' => ['timezone' => 'Asia/Tokyo', 'locale' => 'ja-JP', 'languages' => ['ja-JP', 'ja', 'en-US']],
        'BR' => ['timezone' => 'America/Sao_Paulo', 'locale' => 'pt-BR', 'languages' => ['pt-BR', 'pt', 'en-US']],
        'IN' => ['timezone' => 'Asia/Kolkata', 'locale' => 'en-IN', 'languages' => ['en-IN', 'hi', 'en-US']],
    ];

    /**
     * @param array{
     *     country?: string|null,
     *     city?: string|null,
     *     timezone?: string|null,
     *     region?: string|null
     * } $geo
     * @return array{
     *     country: string|null,
     *     timezone: string,
     *     locale: string,
     *     languages: list<string>,
     *     accept_language: string,
     *     city: string|null
     * }
     */
    public function map(array $geo): array
    {
        $country = isset($geo['country']) && $geo['country'] !== ''
            ? strtoupper(substr((string) $geo['country'], 0, 2))
            : null;

        $mapped = $country !== null && isset(self::COUNTRY_MAP[$country])
            ? self::COUNTRY_MAP[$country]
            : ['timezone' => 'UTC', 'locale' => 'en-US', 'languages' => ['en-US', 'en']];

        $timezone = !empty($geo['timezone']) && $this->isValidTimezone((string) $geo['timezone'])
            ? (string) $geo['timezone']
            : $mapped['timezone'];

        $languages = $mapped['languages'];
        $locale = $mapped['locale'];

        return [
            'country' => $country,
            'timezone' => $timezone,
            'locale' => $locale,
            'languages' => $languages,
            'accept_language' => implode(',', array_map(
                static function (string $lang, int $i) use ($languages): string {
                    if ($i === 0) {
                        return $lang;
                    }
                    $q = max(0.1, 1.0 - ($i * 0.1));

                    return sprintf('%s;q=%.1f', $lang, $q);
                },
                $languages,
                array_keys($languages)
            )),
            'city' => isset($geo['city']) ? (string) $geo['city'] : null,
        ];
    }

    /**
     * Build fingerprint fields for an account from proxy geo meta.
     *
     * @param array<string, mixed> $proxyRow proxies row (country + meta JSON/array)
     * @return array{timezone: string, locale: string, extra: array<string, mixed>}
     */
    public function forAccount(array $proxyRow): array
    {
        $meta = $proxyRow['meta'] ?? null;
        if (is_string($meta)) {
            $meta = json_decode($meta, true) ?: [];
        }
        if (!is_array($meta)) {
            $meta = [];
        }

        $mapped = $this->map([
            'country' => $proxyRow['country'] ?? ($meta['country'] ?? null),
            'city' => $meta['city'] ?? null,
            'timezone' => $meta['timezone'] ?? ($meta['tz'] ?? null),
            'region' => $meta['region'] ?? null,
        ]);

        return [
            'timezone' => $mapped['timezone'],
            'locale' => $mapped['locale'],
            'extra' => [
                'languages' => $mapped['languages'],
                'accept_language' => $mapped['accept_language'],
                'geo_country' => $mapped['country'],
                'geo_city' => $mapped['city'],
            ],
        ];
    }

    public function timezoneForCountry(?string $country): string
    {
        if ($country === null || $country === '') {
            return 'UTC';
        }
        $code = strtoupper(substr($country, 0, 2));

        return self::COUNTRY_MAP[$code]['timezone'] ?? 'UTC';
    }

    private function isValidTimezone(string $tz): bool
    {
        try {
            new \DateTimeZone($tz);

            return true;
        } catch (\Exception) {
            return false;
        }
    }
}
