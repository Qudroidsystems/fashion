<?php

namespace App\Models\Concerns;

use App\Models\FashionStyle;
use App\Models\ProductVariant;
use App\Models\StyleVersion;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Shared by quotation and order lines. */
trait HasPricedLines
{
    public const KINDS = [
        'bespoke'    => 'Made to measure',
        'ready_made' => 'Ready-made',
        'service'    => 'Service / alteration',
        'charge'     => 'Other charge',
    ];

    public function initializeHasPricedLines(): void
    {
        $this->mergeFillable([
            'tenant_id', 'kind', 'style_id', 'style_version_id', 'product_variant_id', 'description', 'options',
            'quantity', 'unit_price', 'list_price', 'discount', 'line_total', 'instructions', 'sort_order',
        ]);
        $this->mergeCasts([
            'options' => 'array', 'quantity' => 'float', 'unit_price' => 'float', 'list_price' => 'float',
            'discount' => 'float', 'line_total' => 'float',
        ]);
    }

    public function style(): BelongsTo
    {
        return $this->belongsTo(FashionStyle::class, 'style_id');
    }

    public function styleVersion(): BelongsTo
    {
        return $this->belongsTo(StyleVersion::class, 'style_version_id');
    }

    public function productVariant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class);
    }

    public function getKindLabelAttribute(): string
    {
        return self::KINDS[$this->kind] ?? $this->kind;
    }

    /** Quantity without trailing ".00". */
    public function getQtyAttribute(): string
    {
        return rtrim(rtrim(number_format($this->quantity, 2, '.', ''), '0'), '.');
    }

    /** Values to copy this line into another document. */
    public function copyable(): array
    {
        return $this->only([
            'kind', 'style_id', 'style_version_id', 'product_variant_id', 'description', 'options', 'quantity',
            'unit_price', 'list_price', 'discount', 'line_total', 'instructions', 'sort_order',
        ]);
    }
}
