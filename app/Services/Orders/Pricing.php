<?php

namespace App\Services\Orders;

use App\Models\FashionStyle;
use App\Models\ProductVariant;
use App\Models\User;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Validation\ValidationException;

/**
 * Server-side line and total calculation (SRS FR-ORD-005, FR-ORD-010).
 *
 * Arithmetic is done in whole minor units (kobo) so totals never drift.
 * Bespoke lines pin the style's live version (scenario 31); prices below the
 * catalogue price and discounts above the house limit need "Override price".
 */
class Pricing
{
    /**
     * Normalise posted lines.
     * In: [['kind', 'style_id'|'product_variant_id', 'description', 'options' => [labels], 'quantity', 'unit_price', 'discount', 'instructions', …]]
     * Out: line rows ready to save, plus extra keys passed through (measurement_session_id, wearer, assigned_to, id).
     */
    public function lines(array $input, ?User $user = null, array $keepPinned = []): array
    {
        $user ??= auth()->user();
        $out = [];
        $styles = FashionStyle::with('currentVersion')->whereIn('id', collect($input)->pluck('style_id')->filter())->get()->keyBy('id');
        $variants = ProductVariant::with('product')->whereIn('id', collect($input)->pluck('product_variant_id')->filter())->get()->keyBy('id');

        foreach (array_values($input) as $i => $l) {
            $kind = $l['kind'] ?? 'bespoke';
            $qty = round((float) ($l['quantity'] ?? 1), 2);
            if ($qty <= 0) {
                continue;
            }
            $row = [
                'kind' => $kind, 'style_id' => null, 'style_version_id' => null, 'product_variant_id' => null,
                'options' => null, 'list_price' => null, 'quantity' => $qty,
                'instructions' => $l['instructions'] ?? null, 'sort_order' => $i,
            ];

            if ($kind === 'bespoke' && ! empty($l['style_id'])) {
                $style = $styles->get((int) $l['style_id']);
                // Keep the version an existing line was priced on (revisions must not silently re-price).
                $pinnedId = $keepPinned[(int) ($l['id'] ?? 0)] ?? null;
                $version = $pinnedId ? \App\Models\StyleVersion::find($pinnedId) : $style?->currentVersion;
                if (! $style || ! $version || $version->status === 'draft') {
                    throw ValidationException::withMessages(['lines' => 'Choose a published style for line ' . ($i + 1) . '.']);
                }
                $chosen = collect($version->price_options ?? [])->whereIn('label', (array) ($l['options'] ?? []))->values()->all();
                $row['style_id'] = $style->id;
                $row['style_version_id'] = $version->id;
                $row['options'] = $chosen ?: null;
                $row['list_price'] = $this->money((float) $version->base_price + collect($chosen)->sum(fn ($o) => (float) $o['amount']));
                $row['description'] = trim((string) ($l['description'] ?? '')) ?: $style->name;
            } elseif ($kind === 'ready_made' && ! empty($l['product_variant_id'])) {
                $v = $variants->get((int) $l['product_variant_id']);
                if (! $v) {
                    throw ValidationException::withMessages(['lines' => 'Choose a product for line ' . ($i + 1) . '.']);
                }
                $row['product_variant_id'] = $v->id;
                $row['list_price'] = $this->money((float) $v->price);
                $row['description'] = trim((string) ($l['description'] ?? '')) ?: $v->product->name . ($v->label ? ' — ' . $v->label : '');
            } else {
                $row['kind'] = in_array($kind, ['service', 'charge'], true) ? $kind : 'service';
                $row['description'] = trim((string) ($l['description'] ?? ''));
                if ($row['description'] === '') {
                    continue;
                }
            }

            $unit = isset($l['unit_price']) && $l['unit_price'] !== '' && $l['unit_price'] !== null ? $this->money((float) $l['unit_price']) : ($row['list_price'] ?? 0.0);
            if ($unit < 0) {
                throw ValidationException::withMessages(['lines' => 'Prices cannot be negative.']);
            }
            if ($row['list_price'] !== null && $unit < $row['list_price'] && ! $user?->can('Override price')) {
                throw ValidationException::withMessages(['lines' => sprintf('“%s” is priced below the catalogue price. Only staff allowed to override prices can do that.', $row['description'])]);
            }
            $gross = $this->minor($unit) * $qty;
            $discount = min($this->minor((float) ($l['discount'] ?? 0)), (int) round($gross));

            $row['unit_price'] = $unit;
            $row['discount'] = max(0, $discount) / 100;
            $row['line_total'] = (int) round($gross - max(0, $discount)) / 100;

            foreach (['id', 'measurement_session_id', 'wearer', 'assigned_to'] as $extra) {
                if (array_key_exists($extra, $l)) {
                    $row[$extra] = $l[$extra] === '' ? null : $l[$extra];
                }
            }
            $out[] = $row;
        }

        if (! $out) {
            throw ValidationException::withMessages(['lines' => 'Add at least one garment or item.']);
        }

        return $out;
    }

    /** subtotal, discount_total, order_discount, total — enforcing the house discount limit. */
    public function totals(array $lines, float $orderDiscount = 0, ?User $user = null): array
    {
        $user ??= auth()->user();
        $sub = 0;
        $lineDisc = 0;
        foreach ($lines as $l) {
            $sub += (int) round($this->minor($l['unit_price']) * $l['quantity']);
            $lineDisc += $this->minor($l['discount']);
        }
        $orderDisc = min(max(0, $this->minor($orderDiscount)), $sub - $lineDisc);
        $discount = $lineDisc + $orderDisc;

        $limit = (float) (CurrentTenant::get()?->setting('discount_limit_percent', 10) ?? 10);
        if ($sub > 0 && $discount / $sub * 100 > $limit + 0.0001 && ! $user?->can('Override price')) {
            throw ValidationException::withMessages(['discount' => sprintf(
                'Discounts add up to %.1f%%. Above %s%% needs someone allowed to override prices.', $discount / $sub * 100, rtrim(rtrim(number_format($limit, 2), '0'), '.')
            )]);
        }

        return [
            'subtotal'       => $sub / 100,
            'discount_total' => $discount / 100,
            'order_discount' => $orderDisc / 100,
            'total'          => ($sub - $discount) / 100,
        ];
    }

    public function money(float $v): float
    {
        return round($v, 2);
    }

    public function minor(float|int|string|null $v): int
    {
        return (int) round(((float) $v) * 100);
    }
}
