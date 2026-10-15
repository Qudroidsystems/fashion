<?php

namespace App\Support\Orders;

use App\Support\Tenancy\CurrentTenant;

/**
 * Order life cycle and transition rules (SRS FR-ORD-003, §6.2).
 * Houses can rename stages in settings (order_status_labels); the rules stay.
 */
class OrderStatus
{
    /** status => [default label, pill class, customer-visible by default] */
    public const STATUSES = [
        'draft'         => ['Draft', 'st-muted', false],
        'confirmed'     => ['Confirmed', 'st-info', true],
        'in_production' => ['In production', 'st-violet', true],
        'fitting'       => ['Fitting', 'st-warning', true],
        'ready'         => ['Ready', 'st-success', true],
        'delivered'     => ['Delivered', 'st-success', true],
        'closed'        => ['Closed', 'st-muted', false],
        'cancelled'     => ['Cancelled', 'st-danger', true],
    ];

    /** Forward moves anyone with "Update order" may make. */
    public const TRANSITIONS = [
        'draft'         => ['confirmed'],
        'confirmed'     => ['in_production'],
        'in_production' => ['fitting', 'ready'],
        'fitting'       => ['in_production', 'ready'],   // back to production = alteration
        'ready'         => ['fitting', 'delivered'],
        'delivered'     => ['closed'],
        'closed'        => [],
        'cancelled'     => [],
    ];

    /** Statuses an order can still be cancelled from (needs "Cancel order"). */
    public const CANCELLABLE = ['draft', 'confirmed', 'in_production', 'fitting', 'ready'];

    /** Reopening (needs "Cancel order"): cancelled → draft, closed → delivered. */
    public const REOPEN = ['cancelled' => 'draft', 'closed' => 'delivered'];

    /** Lines and prices may change (with a revision once confirmed) in these. */
    public const EDITABLE = ['draft', 'confirmed', 'in_production', 'fitting'];

    /** Orders that count as open work. */
    public const OPEN = ['confirmed', 'in_production', 'fitting', 'ready'];

    public static function label(string $status): string
    {
        $custom = CurrentTenant::get()?->setting('order_status_labels.' . $status);

        return $custom ?: (self::STATUSES[$status][0] ?? ucfirst(str_replace('_', ' ', $status)));
    }

    public static function pill(string $status): string
    {
        return self::STATUSES[$status][1] ?? 'st-muted';
    }

    public static function customerVisible(string $status): bool
    {
        return self::STATUSES[$status][2] ?? false;
    }

    public static function canMove(string $from, string $to): bool
    {
        return in_array($to, self::TRANSITIONS[$from] ?? [], true);
    }
}
