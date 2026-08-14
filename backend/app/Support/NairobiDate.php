<?php

namespace App\Support;

use Carbon\Carbon;
use Carbon\CarbonInterface;
use DateTimeInterface;

/**
 * Graph captures timestamps in UTC. MySQL stores naive datetimes.
 * Always interpret stored values as UTC, then present Africa/Nairobi (UTC+3).
 */
final class NairobiDate
{
    public const TZ = 'Africa/Nairobi';

    public static function format(?DateTimeInterface $value, string $format = 'Y-m-d H:i:s'): ?string
    {
        if ($value === null) {
            return null;
        }

        return self::toNairobi($value)->format($format);
    }

    public static function iso(?DateTimeInterface $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return self::toNairobi($value)->toIso8601String();
    }

    public static function parseUtc(?string $value): ?CarbonInterface
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        return Carbon::parse($value)->utc();
    }

    /**
     * Persist Graph UTC as a naive UTC wall-clock string (avoids app-TZ rewrite on save).
     */
    public static function utcForStorage(?string $value): ?string
    {
        $parsed = self::parseUtc($value);

        return $parsed?->format('Y-m-d H:i:s');
    }

    private static function toNairobi(DateTimeInterface $value): CarbonInterface
    {
        // Treat Eloquent/datetime instances as UTC wall clock, then shift to EAT (+3).
        return Carbon::parse($value->format('Y-m-d H:i:s'), 'UTC')->timezone(self::TZ);
    }
}
