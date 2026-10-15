/*
 * Garment line editor for quotations and orders.
 * window.FHMP_CATALOG = { styles: [{id, name, code, price, options: [{label, amount}]}],
 *                         products: [{id, label, price}] }
 * Prices shown here are a preview only — the server recalculates every total.
 */
(function () {
    const money = n => Number(n || 0).toLocaleString(undefined, { minimumFractionDigits: 0, maximumFractionDigits: 2 });

    function init(box) {
        const cat = window.FHMP_CATALOG || { styles: [], products: [] };
        const styles = Object.fromEntries(cat.styles.map(s => [String(s.id), s]));
        const products = Object.fromEntries(cat.products.map(p => [String(p.id), p]));
        const tbody = box.querySelector('tbody');
        const tpl = box.querySelector('template');
        let counter = 1000;

        function show(row) {
            const kind = row.querySelector('.ol-kind').value;
            row.querySelectorAll('[data-for]').forEach(el => el.style.display = el.dataset.for.split(' ').includes(kind) ? '' : 'none');
        }

        function renderOptions(row, keepChecked) {
            const holder = row.querySelector('.ol-options');
            const style = styles[row.querySelector('.ol-style').value];
            const name = holder.dataset.name;
            const checked = keepChecked ? JSON.parse(holder.dataset.checked || '[]') : [];
            holder.innerHTML = '';
            (style ? style.options : []).forEach(o => {
                const id = name + '-' + Math.random().toString(36).slice(2, 8);
                holder.insertAdjacentHTML('beforeend',
                    '<label class="small d-inline-flex align-items-center gap-1 me-2" for="' + id + '"><input type="checkbox" class="ol-opt" id="' + id + '" name="' + name +
                    '" value="' + o.label.replace(/"/g, '&quot;') + '" data-amount="' + o.amount + '"' + (checked.includes(o.label) ? ' checked' : '') + '>' +
                    o.label + ' <span class="text-muted">+' + money(o.amount) + '</span></label>');
            });
            holder.querySelectorAll('.ol-opt').forEach(c => c.addEventListener('change', () => { listPrice(row, true); }));
        }

        function listPrice(row, force) {
            const kind = row.querySelector('.ol-kind').value;
            const price = row.querySelector('.ol-price');
            let list = null;
            if (kind === 'bespoke') {
                const s = styles[row.querySelector('.ol-style').value];
                if (s) list = Number(s.price) + [...row.querySelectorAll('.ol-opt:checked')].reduce((a, c) => a + Number(c.dataset.amount), 0);
            } else if (kind === 'ready_made') {
                const p = products[row.querySelector('.ol-product').value];
                if (p) list = Number(p.price);
            }
            row.querySelector('.ol-list').textContent = list !== null ? 'Catalogue: ' + money(list) : '';
            if (list !== null && (force || !price.value)) price.value = list;
            calc();
        }

        function calc() {
            let sub = 0, disc = 0;
            tbody.querySelectorAll('tr.ol-row').forEach(r => {
                const q = parseFloat(r.querySelector('.ol-qty').value) || 0;
                const p = parseFloat(r.querySelector('.ol-price').value) || 0;
                const d = Math.min(parseFloat(r.querySelector('.ol-disc').value) || 0, q * p);
                r.querySelector('.ol-total').textContent = q && p ? money(q * p - d) : '';
                sub += q * p; disc += d;
            });
            const od = document.querySelector('[name=order_discount]');
            const orderDisc = Math.min(parseFloat(od && od.value) || 0, sub - disc);
            const set = (sel, v) => { const el = document.querySelector(sel); if (el) el.textContent = money(v); };
            set('.ol-subtotal', sub);
            set('.ol-discounts', disc + orderDisc);
            set('.ol-grand', sub - disc - orderDisc);
            const dep = document.querySelector('.ol-deposit-hint');
            if (dep) dep.textContent = money((sub - disc - orderDisc) * (parseFloat(dep.dataset.percent) || 0) / 100);
        }

        function wire(row) {
            row.querySelector('.ol-kind').addEventListener('change', () => { show(row); listPrice(row, true); });
            row.querySelector('.ol-style').addEventListener('change', () => { renderOptions(row, false); listPrice(row, true); });
            row.querySelector('.ol-product').addEventListener('change', () => listPrice(row, true));
            row.querySelectorAll('.ol-qty, .ol-price, .ol-disc').forEach(i => i.addEventListener('input', calc));
            row.querySelector('.ol-remove').addEventListener('click', () => {
                if (tbody.querySelectorAll('tr.ol-row').length > 1) row.remove(); calc();
            });
            show(row);
            renderOptions(row, true);
            listPrice(row, false);
        }

        tbody.querySelectorAll('tr.ol-row').forEach(wire);
        box.querySelector('.ol-add').addEventListener('click', () => {
            const t = document.createElement('tbody');
            t.innerHTML = tpl.innerHTML.replace(/__i__/g, String(counter++)).trim();
            const row = t.firstElementChild;
            tbody.appendChild(row);
            wire(row);
        });
        const od = document.querySelector('[name=order_discount]');
        if (od) od.addEventListener('input', calc);
        calc();
    }

    document.addEventListener('DOMContentLoaded', () => document.querySelectorAll('.order-lines').forEach(init));
})();
