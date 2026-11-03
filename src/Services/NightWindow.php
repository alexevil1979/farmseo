<?php

declare(strict_types=1);

namespace Farm\Services;

use DateTimeImmutable;
use DateTimeZone;
use Farm\Repositories\SettingRepository;

/**
 * Per-account "night window" of low activity in the account's local timezone.
 * Default: 23:00–07:00. Configurable via settings.night_window JSON.
 */
final class NightWindow
{
    public const DEFAULT_START = '23:00';
    public const DEFAULT_END = '07:00';

    public function __construct(
        private readonly ?SettingRepository $settings = null,
        private readonly string $defaultStart = self::DEFAULT_START,
        private readonly string $defaultEnd = self::DEFAULT_END,
    ) {
    }

    /**
     * @return array{start: string, end: string}
     */
    public function window(): array
    {
        $start = $this->defaultStart;
        $end = $this->defaultEnd;

        if ($this->settings !== null) {
            $row = $this->settings->getByName('night_window');
            if ($row !== null) {
                $v = $row['value'];
                if (is_string($v)) {
                    $decoded = json_decode($v, true);
                    $v = is_array($decoded) ? $decoded : $v;
                }
                if (is_array($v)) {
                    if (!empty($v['start'])) {
                        $start = (string) $v['start'];
                    }
                    if (!empty($v['end'])) {
                        $end = (string) $v['end'];
                    }
                }
            }
        }

        $envStart = $_ENV['NIGHT_WINDOW_START'] ?? $_SERVER['NIGHT_WINDOW_START'] ?? getenv('NIGHT_WINDOW_START');
        $envEnd = $_ENV['NIGHT_WINDOW_END'] ?? $_SERVER['NIGHT_WINDOW_END'] ?? getenv('NIGHT_WINDOW_END');
        if (is_string($envStart) && $envStart !== '') {
            $start = $envStart;
        }
        if (is_string($envEnd) && $envEnd !== '') {
            $end = $envEnd;
        }

        return ['start' => $start, 'end' => $end];
    }

    public function isNight(?string $timezone, ?DateTimeImmutable $now = null): bool
    {
        $tzName = $timezone !== null && $timezone !== '' ? $timezone : 'UTC';
        try {
            $tz = new DateTimeZone($tzName);
        } catch (\Exception) {
            $tz = new DateTimeZone('UTC');
        }

        $now ??= new DateTimeImmutable('now');
        $local = $now->setTimezone($tz);
        $mins = ((int) $local->format('G')) * 60 + (int) $local->format('i');

        $w = $this->window();
        $start = $this->parseHm($w['start']);
        $end = $this->parseHm($w['end']);

        if ($start === $end) {
            return false; // disabled
        }

        // Window crosses midnight (typical night): e.g. 23:00–07:00
        if ($start > $end) {
            return $mins >= $start || $mins < $end;
        }

        return $mins >= $start && $mins < $end;
    }

    private function parseHm(string $hm): int
    {
        if (!preg_match('/^(\d{1,2}):(\d{2})$/', trim($hm), $m)) {
            return 0;
        }
        $h = max(0, min(23, (int) $m[1]));
        $i = max(0, min(59, (int) $m[2]));

        return $h * 60 + $i;
    }
}
