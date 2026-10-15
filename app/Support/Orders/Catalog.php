<?php

namespace App\Support\Orders;

use App\Models\FashionStyle;
use App\Models\ProductVariant;

/** Styles and ready-made items for the quotation / order line editor. */
class Catalog
{
    public static function data(): array
    {
        return [
            'styles' => FashionStyle::where('status', 'active')->whereNotNull('current_version_id')->with('currentVersion')->orderBy('name')->get()
                ->map(fn ($s) => [
                    'id' => $s->id, 'name' => $s->name, 'code' => $s->code,
                    'price' => (float) $s->currentVersion->base_price,
                    'options' => collect($s->currentVersion->price_options ?? [])->map(fn ($o) => ['label' => $o['label'], 'amount' => (float) $o['amount']])->values(),
                ])->values(),
            'products' => ProductVariant::where('is_active', true)->whereHas('product', fn ($q) => $q->where('status', 'active'))
                ->with('product:id,name')->get()->sortBy(fn ($v) => $v->product->name . $v->sort_order)
                ->map(fn ($v) => ['id' => $v->id, 'label' => $v->product->name . ($v->label ? ' — ' . $v->label : '') . ' (' . $v->sku . ')', 'price' => (float) $v->price])->values(),
        ];
    }
}
