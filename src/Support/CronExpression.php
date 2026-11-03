<?php

declare(strict_types=1);

namespace Farm\Support;

use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

/**
 * Minimal 5-field cron matcher/next calculator (min hour dom mon dow).
 * Supports *, lists, ranges, and steps (e.g. every 5 minutes).
 */
final class CronExpression
{
    private string $minute;
    private string $hour;
    private string $dom;
    private string $month;
    private string $dow;

    public function __construct(string $expr)
    {
        $parts = preg_split('/\s+/', trim($expr)) ?: [];
        if (count($parts) !== 5) {
            throw new InvalidArgumentException("Invalid cron expression (need 5 fields): {$expr}");
        }
        $this->minute = $parts[0];
        $this->hour = $parts[1];
        $this->dom = $parts[2];
        $this->month = $parts[3];
        $this->dow = $parts[4];
    }

    public static function parse(string $expr): self
    {
        return new self($expr);
    }

    public function isDue(DateTimeImmutable $at): bool
    {
        // Cron matches whole minute; ignore seconds/ms
        $at = $at->setTime((int) $at->format('H'), (int) $at->format('i'), 0);

        return $this->fieldMatches($this->minute, (int) $at->format('i'), 0, 59)
            && $this->fieldMatches($this->hour, (int) $at->format('G'), 0, 23)
            && $this->fieldMatches($this->month, (int) $at->format('n'), 1, 12)
            && $this->domDowMatches($at);
    }

    /**
     * Next fire time strictly after $from (second resolution truncated to minute).
     */
    public function nextRun(DateTimeImmutable $from, ?DateTimeZone $tz = null): DateTimeImmutable
    {
        $cursor = $from;
        if ($tz !== null) {
            $cursor = $cursor->setTimezone($tz);
        }
        // Start from next minute
        $cursor = $cursor->setTime((int) $cursor->format('H'), (int) $cursor->format('i'), 0)
            ->add(new DateInterval('PT1M'));

        // Hard cap: 366 days * 24 * 60
        for ($i = 0; $i < 527040; $i++) {
            if ($this->isDue($cursor)) {
                return $cursor;
            }
            $cursor = $cursor->add(new DateInterval('PT1M'));
        }

        throw new InvalidArgumentException('Could not find next cron run within search window');
    }

    private function domDowMatches(DateTimeImmutable $at): bool
    {
        $domOk = $this->fieldMatches($this->dom, (int) $at->format('j'), 1, 31);
        // cron dow: 0/7 = Sunday … 6 = Saturday
        $dow = (int) $at->format('w');
        $dowOk = $this->fieldMatches($this->dow, $dow, 0, 7)
            || ($dow === 0 && $this->fieldMatches($this->dow, 7, 0, 7));

        $domStar = $this->dom === '*';
        $dowStar = $this->dow === '*';

        if ($domStar && $dowStar) {
            return true;
        }
        if (!$domStar && $dowStar) {
            return $domOk;
        }
        if ($domStar && !$dowStar) {
            return $dowOk;
        }

        // Both restricted → either may match (common cron behaviour)
        return $domOk || $dowOk;
    }

    private function fieldMatches(string $field, int $value, int $min, int $max): bool
    {
        foreach (explode(',', $field) as $part) {
            $part = trim($part);
            if ($part === '') {
                continue;
            }
            if ($this->partMatches($part, $value, $min, $max)) {
                return true;
            }
        }

        return false;
    }

    private function partMatches(string $part, int $value, int $min, int $max): bool
    {
        $step = 1;
        if (str_contains($part, '/')) {
            [$part, $stepStr] = explode('/', $part, 2);
            $step = max(1, (int) $stepStr);
        }

        if ($part === '*' || $part === '') {
            if ($step === 1) {
                return $value >= $min && $value <= $max;
            }

            return ($value - $min) % $step === 0;
        }

        if (str_contains($part, '-')) {
            [$a, $b] = explode('-', $part, 2);
            $start = $this->normalizeNumber($a, $min, $max);
            $end = $this->normalizeNumber($b, $min, $max);
            if ($value < $start || $value > $end) {
                return false;
            }

            return ($value - $start) % $step === 0;
        }

        $n = $this->normalizeNumber($part, $min, $max);
        if ($step === 1) {
            return $value === $n;
        }

        return $value >= $n && ($value - $n) % $step === 0;
    }

    private function normalizeNumber(string $raw, int $min, int $max): int
    {
        $n = (int) $raw;
        if ($n < $min) {
            $n = $min;
        }
        if ($n > $max) {
            $n = $max;
        }

        return $n;
    }
}
