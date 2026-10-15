{{--
    Garment lines editor shared by quotations and orders.
    Expects $rows (array of line arrays), $catalog (['styles' => …, 'products' => …]).
--}}
@php
    use App\Models\OrderLine;
    $rows = array_values($rows ?: [['kind' => 'bespoke']]);
    $sym = \App\Support\Money::symbol();
    $row = function ($i, $r) use ($catalog) {
        $kind = $r['kind'] ?? 'bespoke';
        $opts = collect($r['options'] ?? [])->map(fn ($o) => is_array($o) ? $o['label'] : $o)->values()->all();
        ob_start(); ?>
        <tr class="ol-row">
            <td style="min-width:150px">
                <input type="hidden" name="lines[<?= $i ?>][id]" value="<?= e($r['id'] ?? '') ?>">
                <select name="lines[<?= $i ?>][kind]" class="form-select form-select-sm ol-kind">
                    <?php foreach (OrderLine::KINDS as $k => $l): ?><option value="<?= $k ?>" <?= $kind === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?>
                </select>
            </td>
            <td style="min-width:280px">
                <div data-for="bespoke">
                    <select name="lines[<?= $i ?>][style_id]" class="form-select form-select-sm ol-style">
                        <option value="">Choose style…</option>
                        <?php foreach ($catalog['styles'] as $s): ?><option value="<?= $s['id'] ?>" <?= (string) ($r['style_id'] ?? '') === (string) $s['id'] ? 'selected' : '' ?>><?= e($s['name']) ?> (<?= e($s['code']) ?>)</option><?php endforeach; ?>
                    </select>
                    <div class="ol-options mt-1" data-name="lines[<?= $i ?>][options][]" data-checked="<?= e(json_encode($opts)) ?>"></div>
                </div>
                <div data-for="ready_made">
                    <select name="lines[<?= $i ?>][product_variant_id]" class="form-select form-select-sm ol-product">
                        <option value="">Choose item…</option>
                        <?php foreach ($catalog['products'] as $p): ?><option value="<?= $p['id'] ?>" <?= (string) ($r['product_variant_id'] ?? '') === (string) $p['id'] ? 'selected' : '' ?>><?= e($p['label']) ?></option><?php endforeach; ?>
                    </select>
                </div>
                <input name="lines[<?= $i ?>][description]" class="form-control form-control-sm mt-1" maxlength="255" value="<?= e($r['description'] ?? '') ?>" placeholder="Description (optional for styles)">
                <input name="lines[<?= $i ?>][instructions]" class="form-control form-control-sm mt-1" maxlength="2000" value="<?= e($r['instructions'] ?? '') ?>" placeholder="Instructions, e.g. sweetheart neckline, no sleeves">
                <div class="small text-muted ol-list mt-1"></div>
            </td>
            <td style="width:90px"><input type="number" min="0.01" step="any" name="lines[<?= $i ?>][quantity]" class="form-control form-control-sm ol-qty" value="<?= e($r['quantity'] ?? 1) ?>"></td>
            <td style="min-width:120px"><input type="number" min="0" step="0.01" name="lines[<?= $i ?>][unit_price]" class="form-control form-control-sm ol-price" value="<?= e($r['unit_price'] ?? '') ?>"></td>
            <td style="min-width:110px"><input type="number" min="0" step="0.01" name="lines[<?= $i ?>][discount]" class="form-control form-control-sm ol-disc" value="<?= e(($r['discount'] ?? 0) ?: '') ?>" placeholder="0"></td>
            <td class="text-end fw-semibold ol-total" style="min-width:100px"></td>
            <td class="text-end"><button type="button" class="btn btn-sm btn-light text-danger ol-remove" title="Remove"><i class="ri-close-line"></i></button></td>
        </tr>
        <?php return ob_get_clean();
    };
@endphp
<div class="order-lines">
    <div class="table-responsive">
        <table class="table align-top mb-0">
            <thead class="table-light"><tr><th>Type</th><th>What</th><th>Qty</th><th>Price ({{ $sym }})</th><th>Discount</th><th class="text-end">Total</th><th></th></tr></thead>
            <tbody>@foreach($rows as $i => $r){!! $row($i, $r) !!}@endforeach</tbody>
        </table>
    </div>
    <template>{!! $row('__i__', ['kind' => 'bespoke']) !!}</template>
    <div class="p-3"><button type="button" class="action-btn ol-add"><i class="ri-add-line"></i>Add garment or item</button></div>
</div>
@once
    <script>window.FHMP_CATALOG = @json($catalog);</script>
    <script src="{{ asset('js/order-lines.js') }}?v=1"></script>
@endonce
