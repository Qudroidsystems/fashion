<?php

namespace App\Services\Catalogue;

use App\Models\Product;
use App\Models\ProductVariant;
use App\Services\Audit\AuditLogger;
use App\Services\Numbering\DocumentNumber;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/** Ready-made products and their size/colour variants (SRS FR-INV-002, FR-POS-002/007). */
class ProductService
{
    /**
     * Create or update a product with its variants.
     * $variants: [['id' => ?, 'size' =>, 'colour' =>, 'sku' =>, 'barcode' =>, 'generate_barcode' => bool, 'price' =>, 'cost_price' =>, 'reorder_level' =>, 'is_active' => bool], …]
     * Variants missing from the list are removed (deactivated once stock exists — Inventory module).
     */
    public function save(?Product $product, array $data, array $variants, ?UploadedFile $image = null, bool $removeImage = false): Product
    {
        return DB::transaction(function () use ($product, $data, $variants, $image, $removeImage) {
            $before = $product?->only(['name', 'status', 'show_in_catalogue']);
            $creating = ! $product;

            $product ??= new Product(['created_by' => auth()->id()]);
            $product->fill(collect($data)->except("code")->all());
            if ($creating) {
                $product->code = ($data['code'] ?? null) ?: DocumentNumber::next('product');
            }

            if (($removeImage || $image) && $product->image_path) {
                // Keep the file when it is also in the shop gallery.
                if (! \App\Models\ProductImage::where('path', $product->image_path)->exists()) {
                    Storage::disk('public')->delete($product->image_path);
                }
                $product->image_path = null;
            }
            $product->save();

            if ($image) {
                $product->update(['image_path' => $image->storeAs(
                    'tenants/' . CurrentTenant::require()->public_id . '/products',
                    $product->public_id . '-' . Str::lower(Str::random(6)) . '.' . $image->extension(), 'public')]);
            }

            $keep = [];
            foreach (array_values($variants) as $i => $row) {
                $variant = ! empty($row['id']) ? $product->variants()->whereKey($row['id'])->first() : null;
                $variant ??= new ProductVariant(['product_id' => $product->id]);

                $variant->fill([
                    'size'          => $this->clean($row['size'] ?? null),
                    'colour'        => $this->clean($row['colour'] ?? null),
                    'price'         => round((float) ($row['price'] ?? 0), 2),
                    'cost_price'    => isset($row['cost_price']) && $row['cost_price'] !== '' ? round((float) $row['cost_price'], 2) : $variant->cost_price,
                    'reorder_level' => isset($row['reorder_level']) && $row['reorder_level'] !== '' ? (float) $row['reorder_level'] : null,
                    'is_active'     => (bool) ($row['is_active'] ?? true),
                    'sort_order'    => $i,
                ]);
                $variant->sku = $this->clean($row['sku'] ?? null) ?: $this->makeSku($product, $variant);
                $barcode = $this->clean($row['barcode'] ?? null);
                $variant->barcode = $barcode ?: (! empty($row['generate_barcode']) ? $this->makeBarcode() : null);
                $variant->save();
                $keep[] = $variant->id;
            }
            $product->variants()->whereNotIn('id', $keep)->get()->each->delete();
            app(\App\Services\Inventory\ItemService::class)->syncProduct($product->fresh('variants', 'category'));

            $creating
                ? AuditLogger::log('product.created', $product, after: $product->only(['code', 'name']) + ['variants' => count($keep)])
                : AuditLogger::log('product.updated', $product, $before, $product->only(['name', 'status', 'show_in_catalogue']));

            return $product->fresh('variants');
        });
    }

    /** PRD-00001-M-RED; made unique within the house. */
    public function makeSku(Product $product, ProductVariant $variant): string
    {
        $base = strtoupper(implode('-', array_filter([
            $product->code,
            $variant->size ? Str::slug($variant->size, '') : null,
            $variant->colour ? Str::upper(Str::substr(Str::slug($variant->colour, ''), 0, 4)) : null,
        ])));
        $sku = $base;
        $n = 2;
        while (ProductVariant::where('sku', $sku)->when($variant->exists, fn ($q) => $q->whereKeyNot($variant->id))->exists()) {
            $sku = $base . '-' . $n++;
        }

        return $sku;
    }

    /** Internal EAN-13 in the in-store range (prefix 2), unique within the house. */
    public function makeBarcode(): string
    {
        do {
            $digits = '2' . str_pad((string) random_int(0, 99999999999), 11, '0', STR_PAD_LEFT);
            $code = $digits . self::ean13CheckDigit($digits);
        } while (ProductVariant::where('barcode', $code)->exists());

        return $code;
    }

    public static function ean13CheckDigit(string $twelve): int
    {
        $sum = 0;
        foreach (str_split($twelve) as $i => $d) {
            $sum += (int) $d * ($i % 2 ? 3 : 1);
        }

        return (10 - $sum % 10) % 10;
    }

    protected function clean(?string $v): ?string
    {
        $v = trim((string) $v);

        return $v === '' ? null : $v;
    }
}
