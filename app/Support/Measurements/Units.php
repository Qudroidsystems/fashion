<?php

namespace App\Support\Measurements;

use InvalidArgumentException;

/**
 * Length units (SRS FR-MEA-002). Canonical storage is MILLIMETRES.
 * Conversion factors are exact (1 in = 25.4 mm by definition); rounding
 * happens only when displaying (FR-MEA-002, acceptance scenario 32).
 */
class Units
{
    /** unit code => millimetres per unit */
    public const TO_MM = [
        'mm' => 1.0,
        'cm' => 10.0,
        'm'  => 1000.0,
        'in' => 25.4,
        'ft' => 304.8,
    ];

    public const LABELS = [
        'in' => 'Inches',
        'cm' => 'Centimetres',
        'mm' => 'Millimetres',
        'm'  => 'Metres',
        'ft' => 'Feet',
    ];

    /** Decimal places shown per unit. */
    public const DISPLAY_DECIMALS = ['mm' => 0, 'cm' => 1, 'm' => 3, 'in' => 2, 'ft' => 3];

    public static function isValid(?string $unit): bool
    {
        return isset(self::TO_MM[$unit]);
    }

    /** Value in $unit → canonical millimetres, rounded to 0.01 mm for storage. */
    public static function toMm(float|int|string $value, string $unit): float
    {
        self::assert($unit);

        return round((float) $value * self::TO_MM[$unit], 2);
    }

    /** Canonical millimetres → value in $unit (unrounded). */
    public static function fromMm(float|int|string $mm, string $unit): float
    {
        self::assert($unit);

        return (float) $mm / self::TO_MM[$unit];
    }

    /** Display string, e.g. 38.25 (in) or 97.2 (cm); trailing zeros trimmed. */
    public static function format(float|int|string|null $mm, string $unit, bool $withUnit = false): string
    {
        if ($mm === null || $mm === '') {
            return '';
        }
        $v = number_format(self::fromMm($mm, $unit), self::DISPLAY_DECIMALS[$unit] ?? 2, '.', '');
        $v = str_contains($v, '.') ? rtrim(rtrim($v, '0'), '.') : $v;

        return $withUnit ? $v . ' ' . $unit : $v;
    }

    protected static function assert(string $unit): void
    {
        if (! self::isValid($unit)) {
            throw new InvalidArgumentException("Unknown unit [$unit].");
        }
    }
}
