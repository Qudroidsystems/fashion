<?php

namespace App\Services\Reports;

use App\Support\Money;

/** One way to write a report value, shared by the screen, PDF and API. */
class ReportFormat
{
    public static function cell($value, string $format): string
    {
        if ($value === null || $value === '') {
            return $format === 'text' ? '' : '—';
        }

        return match ($format) {
            'money'   => Money::format((float) $value),
            'int'     => number_format((float) $value),
            'qty'     => rtrim(rtrim(number_format((float) $value, 3), '0'), '.'),
            'percent' => rtrim(rtrim(number_format((float) $value, 1), '0'), '.') . '%',
            'days'    => rtrim(rtrim(number_format((float) $value, 1), '0'), '.') . ' d',
            'date'    => $value instanceof \DateTimeInterface ? $value->format('d M Y')
                : (preg_match('/^\d{4}-\d{2}-\d{2}/', (string) $value) ? \Carbon\Carbon::parse($value)->format('d M Y') : (string) $value),
            default   => (string) $value,
        };
    }

    /** Plain value for CSV/XLSX: numbers stay numbers. */
    public static function raw($value, string $format)
    {
        if ($value === null) {
            return '';
        }
        if (in_array($format, ['money', 'int', 'qty', 'percent', 'days'], true)) {
            return is_numeric($value) ? round((float) $value, $format === 'qty' ? 3 : 2) : $value;
        }
        if ($format === 'date' && $value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d');
        }

        return (string) $value;
    }
}
