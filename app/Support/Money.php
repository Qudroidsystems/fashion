<?php

namespace App\Support;

use App\Support\Tenancy\CurrentTenant;

/** Money display in the house currency. Amounts are stored as decimal(14,2). */
class Money
{
    public const SYMBOLS = ['NGN' => '₦', 'GHS' => 'GH₵', 'KES' => 'KSh ', 'ZAR' => 'R', 'USD' => '$', 'GBP' => '£', 'EUR' => '€', 'XOF' => 'CFA ', 'UGX' => 'USh ', 'TZS' => 'TSh ', 'RWF' => 'RF '];

    public static function format(float|int|string|null $amount, ?string $currency = null, bool $decimals = false): string
    {
        if ($amount === null || $amount === '') {
            return '—';
        }
        $currency ??= CurrentTenant::get()?->currency_code ?: 'NGN';
        $symbol = self::SYMBOLS[$currency] ?? $currency . ' ';
        $value = (float) $amount;
        $places = $decimals || floor($value) != $value ? 2 : 0;

        return ($value < 0 ? '-' : '') . $symbol . number_format(abs($value), $places);
    }

    public static function symbol(?string $currency = null): string
    {
        $currency ??= CurrentTenant::get()?->currency_code ?: 'NGN';

        return trim(self::SYMBOLS[$currency] ?? $currency);
    }
}
