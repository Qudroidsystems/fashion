<?php

namespace App\Services\Numbering;

use App\Models\Tenant;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Support\Facades\DB;

/**
 * Tenant-configurable document numbers (SRS FR-ORD-004).
 *
 *   DocumentNumber::next('customer');   // CUS-00001
 *   DocumentNumber::next('order');      // ORD-2026-00042
 *
 * Formats come from the tenant's settings.numbering (House Settings screen).
 * Tokens: {YYYY} {YY} {MM} {SEQ} {SEQ:5}. A format with {YYYY}/{YY} restarts
 * every year, one with {MM} every month. Numbers are allocated under a row
 * lock, so concurrent requests never get the same number.
 */
class DocumentNumber
{
    public const DEFAULT_FORMATS = [
        'customer'  => 'CUS-{SEQ:5}',
        'quotation' => 'QTE-{YYYY}-{SEQ:5}',
        'order'     => 'ORD-{YYYY}-{SEQ:5}',
        'invoice'   => 'INV-{YYYY}-{SEQ:5}',
        'receipt'   => 'RCP-{YYYY}-{SEQ:5}',
    ];

    public static function next(string $key, ?Tenant $tenant = null): string
    {
        $tenant ??= CurrentTenant::require();
        $format = $tenant->setting('numbering.' . $key) ?: (self::DEFAULT_FORMATS[$key] ?? strtoupper($key) . '-{SEQ:5}');
        $now = now($tenant->timezone ?: config('app.timezone'));

        $period = str_contains($format, '{MM}') ? $now->format('Ym')
            : ((str_contains($format, '{YYYY}') || str_contains($format, '{YY}')) ? $now->format('Y') : '');

        $value = DB::transaction(function () use ($tenant, $key, $period) {
            DB::table('document_sequences')->insertOrIgnore([
                'tenant_id' => $tenant->id, 'key' => $key, 'period' => $period,
                'last_value' => 0, 'created_at' => now(), 'updated_at' => now(),
            ]);

            $row = DB::table('document_sequences')
                ->where(['tenant_id' => $tenant->id, 'key' => $key, 'period' => $period])
                ->lockForUpdate()->first();

            $next = $row->last_value + 1;
            DB::table('document_sequences')->where('id', $row->id)->update(['last_value' => $next, 'updated_at' => now()]);

            return $next;
        });

        return static::format($format, $value, $now);
    }

    public static function format(string $format, int $value, \DateTimeInterface $date): string
    {
        $out = strtr($format, [
            '{YYYY}' => $date->format('Y'),
            '{YY}'   => $date->format('y'),
            '{MM}'   => $date->format('m'),
        ]);

        return preg_replace_callback('/\{SEQ(?::(\d))?\}/', fn ($m) => str_pad((string) $value, (int) ($m[1] ?? 1), '0', STR_PAD_LEFT), $out);
    }
}
