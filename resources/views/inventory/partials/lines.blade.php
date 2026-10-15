{{--
    Line editor for stock documents.
    @include('inventory.partials.lines', [
        'items'  => $pickerItems,          // ItemPicker::data()
        'rows'   => old('lines', $rows ?? []),
        'branch' => '#branch_id',          // selector of the location field (for availability), or null
        'lot'    => 'select'|'text'|null,  // choose an existing lot, type a new lot no., or no lot column
        'cost'   => true|false,            // unit cost column (+ line totals)
        'qty'    => 'quantity',            // quantity field name: quantity | quantity_change | counted_quantity
        'qtyLabel' => 'Quantity',
        'signed' => false,                 // allow negative quantities
        'note'   => false,
        'lotsInStock' => true,             // only offer lots with stock at the location
    ])
--}}
@php
    $lot ??= null; $cost ??= false; $qty ??= 'quantity'; $qtyLabel ??= 'Quantity'; $signed ??= false; $note ??= false;
    $branch ??= null; $lotsInStock ??= true;
    $rows = array_values($rows ?: [[]]);
    $groups = collect($items)->groupBy('type');
    $sym = \App\Support\Money::symbol();
    $renderRow = function ($i, $r) use ($groups, $lot, $cost, $qty, $signed, $note) {
        ob_start(); ?>
        <tr class="inv-row">
            <td style="min-width:240px">
                <select name="lines[<?= $i ?>][item_id]" class="form-select form-select-sm inv-item">
                    <option value="">Choose item…</option>
                    <?php foreach ($groups as $type => $list): ?>
                        <optgroup label="<?= e($type) ?>">
                            <?php foreach ($list as $it): ?>
                                <option value="<?= $it['id'] ?>" <?= (string) ($r['item_id'] ?? '') === (string) $it['id'] ? 'selected' : '' ?>><?= e($it['name']) ?> (<?= e($it['code']) ?>)</option>
                            <?php endforeach; ?>
                        </optgroup>
                    <?php endforeach; ?>
                </select>
                <div class="small text-muted inv-hint mt-1"></div>
            </td>
            <?php if ($lot === 'select'): ?>
                <td style="min-width:170px"><select name="lines[<?= $i ?>][lot_id]" class="form-select form-select-sm inv-lot" data-value="<?= e($r['lot_id'] ?? '') ?>"></select></td>
            <?php elseif ($lot === 'text'): ?>
                <td style="min-width:130px"><input name="lines[<?= $i ?>][lot_no]" class="form-control form-control-sm" maxlength="60" value="<?= e($r['lot_no'] ?? '') ?>" placeholder="e.g. R-104"></td>
            <?php endif; ?>
            <td style="min-width:130px">
                <div class="input-group input-group-sm">
                    <input type="number" <?= $signed ? '' : 'min="0"' ?> step="any" name="lines[<?= $i ?>][<?= $qty ?>]" class="form-control inv-qty" value="<?= e($r[$qty] ?? '') ?>">
                    <span class="input-group-text inv-unit"></span>
                </div>
            </td>
            <?php if ($cost): ?>
                <td style="min-width:120px"><input type="number" min="0" step="any" name="lines[<?= $i ?>][unit_cost]" class="form-control form-control-sm inv-cost" value="<?= e($r['unit_cost'] ?? '') ?>" <?= isset($r['unit_cost']) && $r['unit_cost'] !== '' ? 'data-touched="1"' : '' ?>></td>
                <td class="text-end small inv-line-total" style="min-width:90px"></td>
            <?php endif; ?>
            <?php if ($note): ?>
                <td style="min-width:150px"><input name="lines[<?= $i ?>][note]" class="form-control form-control-sm" maxlength="255" value="<?= e($r['note'] ?? '') ?>"></td>
            <?php endif; ?>
            <td class="text-end"><button type="button" class="btn btn-sm btn-light text-danger inv-remove" title="Remove"><i class="ri-close-line"></i></button></td>
        </tr>
        <?php return ob_get_clean();
    };
@endphp
<div class="inv-lines" data-items-var="FHMP_ITEMS" @if($branch) data-branch="{{ $branch }}" @endif @if($lotsInStock) data-lots-in-stock="1" @endif>
    <div class="table-responsive">
        <table class="table align-middle mb-0">
            <thead class="table-light">
                <tr>
                    <th>Item</th>
                    @if($lot === 'select')<th>Roll / lot</th>@elseif($lot === 'text')<th>Roll / lot no.</th>@endif
                    <th>{{ $qtyLabel }}</th>
                    @if($cost)<th>Unit cost ({{ $sym }})</th><th class="text-end">Line</th>@endif
                    @if($note)<th>Note</th>@endif
                    <th></th>
                </tr>
            </thead>
            <tbody>
                @foreach($rows as $i => $r){!! $renderRow($i, $r) !!}@endforeach
            </tbody>
            @if($cost)
                <tfoot><tr><td colspan="{{ 2 + ($lot ? 1 : 0) }}" class="text-end fw-semibold">Total</td><td colspan="2" class="text-end fw-semibold">{{ $sym }}<span class="inv-total">0</span></td><td colspan="{{ 1 + ($note ? 1 : 0) }}"></td></tr></tfoot>
            @endif
        </table>
    </div>
    <template class="inv-row-template">{!! $renderRow('__i__', []) !!}</template>
    <div class="p-3"><button type="button" class="action-btn inv-add"><i class="ri-add-line"></i>Add item</button></div>
</div>
@once
    <script>window.FHMP_ITEMS = @json($items);</script>
    <script src="{{ asset('js/inventory-lines.js') }}?v=1"></script>
@endonce
