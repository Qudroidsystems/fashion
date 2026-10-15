/*
 * Line editor for stock documents (purchase orders, receipts, transfers,
 * adjustments, counts, material issues).
 *
 * Markup:
 *   <div class="inv-lines" data-items-var="FHMP_ITEMS" data-branch="#branch_id">
 *     <table><tbody>…rows…</tbody></table>
 *     <template class="inv-row-template"><tr class="inv-row">…name="lines[__i__][item_id]"…</tr></template>
 *     <button type="button" class="inv-add">Add item</button>
 *   </div>
 * In a row: select.inv-item, select.inv-lot (optional), .inv-unit, .inv-hint,
 * input.inv-cost (optional, pre-filled from last/average cost), .inv-remove.
 */
(function () {
    function fmt(n, d) {
        var s = Number(n || 0).toFixed(d || 0);
        return d ? s.replace(/\.?0+$/, '') : s;
    }

    function init(box) {
        var items = window[box.dataset.itemsVar || 'FHMP_ITEMS'] || [];
        var byId = {};
        items.forEach(function (i) { byId[i.id] = i; });
        var branchSel = box.dataset.branch ? document.querySelector(box.dataset.branch) : null;
        var tbody = box.querySelector('tbody');
        var tpl = box.querySelector('template.inv-row-template');
        var counter = tbody.querySelectorAll('tr.inv-row').length + 1000;

        function branch() { return branchSel ? branchSel.value : null; }

        function refresh(row) {
            var sel = row.querySelector('select.inv-item');
            var it = sel ? byId[sel.value] : null;
            row.querySelectorAll('.inv-unit').forEach(function (u) { u.textContent = it ? it.unit : ''; });

            var lotSel = row.querySelector('select.inv-lot');
            var b = branch();
            if (lotSel) {
                var current = lotSel.dataset.value || lotSel.value;
                lotSel.innerHTML = '<option value="">' + (it && it.lots ? 'Choose roll / lot…' : '—') + '</option>';
                if (it) {
                    it.lot_list.forEach(function (l) {
                        var qty = b && it.stock[b] ? it.stock[b][l.id] : undefined;
                        if (box.dataset.lotsInStock && !(qty > 0) && String(l.id) !== String(current)) return;
                        var o = document.createElement('option');
                        o.value = l.id;
                        o.textContent = l.label + (qty !== undefined ? ' — ' + fmt(qty, it.decimals) + ' ' + it.unit : '');
                        if (String(l.id) === String(current)) o.selected = true;
                        lotSel.appendChild(o);
                    });
                }
                lotSel.disabled = !it || (!it.lots && it.lot_list.length === 0);
                lotSel.required = !!(it && it.lots);
            }

            var hint = row.querySelector('.inv-hint');
            if (hint) {
                if (!it) { hint.textContent = ''; }
                else if (b) {
                    var lots = it.stock[b] || {};
                    var onHand = 0;
                    Object.keys(lots).forEach(function (k) { onHand += lots[k]; });
                    var free = onHand - (it.reserved[b] || 0);
                    hint.textContent = fmt(Math.max(0, free), it.decimals) + ' ' + it.unit + ' free here' + ((it.reserved[b] || 0) > 0 ? ' (' + fmt(it.reserved[b], it.decimals) + ' reserved)' : '');
                } else {
                    hint.textContent = it.code;
                }
            }

            var cost = row.querySelector('input.inv-cost');
            if (cost && it && !cost.value && !cost.dataset.touched) {
                var c = it.last_cost !== null && it.last_cost !== undefined ? it.last_cost : it.cost;
                if (c) cost.value = c;
            }
            var qty = row.querySelector('input.inv-qty');
            if (qty && it) qty.step = it.decimals ? (1 / Math.pow(10, it.decimals)).toString() : '1';

            recalc();
        }

        function recalc() {
            var totalBox = box.querySelector('.inv-total');
            if (!totalBox) return;
            var total = 0;
            tbody.querySelectorAll('tr.inv-row').forEach(function (r) {
                var q = parseFloat((r.querySelector('input.inv-qty') || {}).value) || 0;
                var c = parseFloat((r.querySelector('input.inv-cost') || {}).value) || 0;
                var line = r.querySelector('.inv-line-total');
                if (line) line.textContent = q && c ? (q * c).toLocaleString(undefined, { maximumFractionDigits: 2 }) : '';
                total += q * c;
            });
            totalBox.textContent = total.toLocaleString(undefined, { maximumFractionDigits: 2 });
        }

        function wire(row) {
            var sel = row.querySelector('select.inv-item');
            if (sel) sel.addEventListener('change', function () {
                var lot = row.querySelector('select.inv-lot');
                if (lot) lot.dataset.value = '';
                var cost = row.querySelector('input.inv-cost');
                if (cost && !cost.dataset.touched) cost.value = '';
                refresh(row);
            });
            row.querySelectorAll('input.inv-qty, input.inv-cost').forEach(function (i) {
                i.addEventListener('input', function () { if (i.classList.contains('inv-cost')) i.dataset.touched = 1; recalc(); });
            });
            var rm = row.querySelector('.inv-remove');
            if (rm) rm.addEventListener('click', function () {
                if (tbody.querySelectorAll('tr.inv-row').length > 1) { row.remove(); recalc(); }
                else { row.querySelectorAll('input,select').forEach(function (i) { if (i.type !== 'hidden') i.value = ''; }); refresh(row); }
            });
            refresh(row);
        }

        tbody.querySelectorAll('tr.inv-row').forEach(wire);

        var add = box.querySelector('.inv-add');
        if (add && tpl) add.addEventListener('click', function () {
            var html = tpl.innerHTML.replace(/__i__/g, String(counter++));
            var t = document.createElement('tbody');
            t.innerHTML = html.trim();
            var row = t.firstElementChild;
            tbody.appendChild(row);
            wire(row);
            var s = row.querySelector('select.inv-item');
            if (s) s.focus();
        });

        if (branchSel) branchSel.addEventListener('change', function () { tbody.querySelectorAll('tr.inv-row').forEach(refresh); });
    }

    document.addEventListener('DOMContentLoaded', function () {
        document.querySelectorAll('.inv-lines').forEach(init);
    });
})();
