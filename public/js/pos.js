/*
 * Point-of-sale till. Prices shown here are a preview: the server re-prices
 * every line, checks stock and discounts, and records the sale atomically.
 * Each basket has one client_ref; if the network fails and the cashier
 * presses "Complete" again, the server returns the same sale instead of
 * selling twice (SRS scenario 35).
 */
(function () {
    const C = window.POS;
    const $ = id => document.getElementById(id);
    const fmt = n => C.currency + Number(n || 0).toLocaleString(undefined, { maximumFractionDigits: 2 });
    const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
    const uuid = () => (crypto.randomUUID ? crypto.randomUUID() : 'r' + Date.now() + Math.random().toString(36).slice(2));

    let lines = [], customer = null, clientRef = uuid(), lastResults = [], busy = false;

    async function getJSON(url) {
        const r = await fetch(url, { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
        if (!r.ok) throw new Error((await r.json().catch(() => ({}))).message || 'Request failed');
        return r.json();
    }

    /* ----------------------------- products ----------------------------- */
    let searchTimer;
    async function search(q, addExact) {
        const data = await getJSON(C.urls.products + '?q=' + encodeURIComponent(q));
        lastResults = data.items;
        if (addExact && data.exact) {
            add(data.items.find(i => i.id === data.exact));
            $('posScan').value = '';
            return search('', false);
        }
        renderProducts();
    }
    function renderProducts() {
        const box = $('posProducts');
        $('posEmpty').classList.toggle('d-none', lastResults.length > 0);
        box.innerHTML = lastResults.map(p => {
            const out = p.stock !== null && p.stock <= 0;
            return `<button type="button" class="pos-card" data-id="${p.id}" ${out ? 'disabled title="Out of stock here"' : ''}>
                <div class="pos-thumb" ${p.image ? `style="background-image:url('${esc(p.image)}')"` : ''}>${p.image ? '' : '<i class="ri-t-shirt-line"></i>'}</div>
                <div class="fw-semibold small text-truncate">${esc(p.name)}</div>
                <div class="small text-muted text-truncate">${esc(p.variant || p.sku)}</div>
                <div class="d-flex justify-content-between align-items-center mt-1"><span class="fw-bold">${fmt(p.price)}</span>
                <span class="small ${out ? 'text-danger' : 'text-muted'}">${p.stock === null ? '' : (out ? 'out' : p.stock + ' left')}</span></div></button>`;
        }).join('');
        box.querySelectorAll('.pos-card').forEach(b => b.addEventListener('click', () => { add(lastResults.find(p => p.id == b.dataset.id)); $('posScan').focus(); }));
    }

    /* ------------------------------- basket ------------------------------- */
    function add(p) {
        if (!p) return;
        const existing = lines.find(l => l.type === 'product' && l.variant_id === p.id);
        if (existing) existing.qty += 1;
        else lines.push({ type: 'product', variant_id: p.id, name: p.name, variant: p.variant, list: p.price, unit_price: p.price, qty: 1, discount: 0, stock: p.stock });
        render();
    }
    function addOrder(o, amount) {
        lines = lines.filter(l => !(l.type === 'order' && l.order_id === o.id));
        lines.push({ type: 'order', order_id: o.id, name: 'Order ' + o.number, variant: o.customer + (o.title ? ' · ' + o.title : ''), amount: amount, max: o.balance });
        if (!customer) setCustomer({ id: o.customer_id, name: o.customer });
        render();
    }
    const lineTotal = l => l.type === 'order' ? Number(l.amount || 0) : Math.max(0, l.unit_price * l.qty - (l.discount || 0));
    const total = () => lines.reduce((a, l) => a + lineTotal(l), 0);

    function render() {
        const box = $('posLines');
        $('posLinesEmpty')?.remove();
        if (!lines.length) box.innerHTML = '<div class="text-center text-muted small p-4" id="posLinesEmpty">Scan or tap an item to start.</div>';
        else box.innerHTML = lines.map((l, i) => l.type === 'order' ? `
            <div class="pos-line"><div><div class="fw-semibold"><i class="ri-file-list-3-line me-1"></i>${esc(l.name)}</div><div class="small text-muted">${esc(l.variant)} · owes ${fmt(l.max)}</div>
                <div class="input-group input-group-sm mt-1" style="max-width:200px"><span class="input-group-text">${esc(C.currency)}</span><input type="number" min="1" step="0.01" class="form-control" data-i="${i}" data-f="amount" value="${l.amount}"></div></div>
                <div class="text-end"><div class="fw-semibold">${fmt(lineTotal(l))}</div><button class="btn btn-sm btn-link text-danger p-0" data-rm="${i}">Remove</button></div></div>` : `
            <div class="pos-line"><div><div class="fw-semibold">${esc(l.name)}</div><div class="small text-muted">${esc(l.variant || '')}${l.stock !== null && l.qty > l.stock ? ' · <span class="text-danger">only ' + l.stock + ' here</span>' : ''}</div>
                <div class="d-flex gap-2 align-items-center mt-1">
                    <span class="pos-qty"><button type="button" data-step="-1" data-i="${i}">−</button><input type="number" min="1" step="1" data-i="${i}" data-f="qty" value="${l.qty}"><button type="button" data-step="1" data-i="${i}">+</button></span>
                    ${C.canOverride ? `<input type="number" min="0" step="0.01" class="form-control form-control-sm" style="width:100px" title="Unit price" data-i="${i}" data-f="unit_price" value="${l.unit_price}">` : `<span class="small text-muted">× ${fmt(l.unit_price)}</span>`}
                    <input type="number" min="0" step="0.01" class="form-control form-control-sm" style="width:90px" placeholder="Disc." title="Discount" data-i="${i}" data-f="discount" value="${l.discount || ''}">
                </div></div>
                <div class="text-end"><div class="fw-semibold">${fmt(lineTotal(l))}</div><button class="btn btn-sm btn-link text-danger p-0" data-rm="${i}">Remove</button></div></div>`).join('');

        box.querySelectorAll('[data-step]').forEach(b => b.addEventListener('click', () => { const l = lines[b.dataset.i]; l.qty = Math.max(1, l.qty + Number(b.dataset.step)); render(); }));
        box.querySelectorAll('input[data-f]').forEach(inp => inp.addEventListener('change', () => { lines[inp.dataset.i][inp.dataset.f] = Number(inp.value) || 0; if (inp.dataset.f === 'qty' && lines[inp.dataset.i].qty < 1) lines[inp.dataset.i].qty = 1; render(); }));
        box.querySelectorAll('[data-rm]').forEach(b => b.addEventListener('click', () => { lines.splice(b.dataset.rm, 1); render(); }));

        const sub = lines.reduce((a, l) => a + (l.type === 'order' ? Number(l.amount || 0) : l.unit_price * l.qty), 0);
        $('posSub').textContent = fmt(sub);
        $('posDisc').textContent = '−' + fmt(sub - total());
        $('posTotal').textContent = fmt(total());
        quickCash();
        tenderState();
    }

    /* ------------------------------- tenders ------------------------------ */
    const tenderInputs = () => [...document.querySelectorAll('.pos-tender input[data-method]')];
    function tenderState() {
        const t = total();
        const paid = tenderInputs().reduce((a, i) => a + (Number(i.value) || 0), 0);
        const diff = paid - t;
        $('posDueLabel').textContent = diff >= 0 ? (diff > 0 ? 'Change to give' : 'Paid in full') : 'Still to pay';
        $('posDue').textContent = fmt(Math.abs(diff));
        $('posDue').className = 'fw-semibold ' + (diff < 0 ? 'text-danger' : 'text-success');
        $('posPay').disabled = busy || !lines.length || t <= 0 && !lines.some(l => l.type === 'product') || diff < -0.001;
    }
    function quickCash() {
        const t = total();
        const notes = [t, Math.ceil(t / 1000) * 1000, Math.ceil(t / 5000) * 5000, Math.ceil(t / 10000) * 10000].filter((v, i, a) => v > 0 && a.indexOf(v) === i);
        $('posQuickCash').innerHTML = notes.map(v => `<button type="button" class="btn btn-sm btn-light" data-cash="${v}">${v === t ? 'Exact ' : ''}${fmt(v)}</button>`).join('');
        $('posQuickCash').querySelectorAll('[data-cash]').forEach(b => b.addEventListener('click', () => { tenderInputs().forEach(i => i.value = ''); $('tCash').value = b.dataset.cash; tenderState(); }));
    }
    tenderInputs().forEach(i => i.addEventListener('input', tenderState));

    /* ------------------------------ customer ------------------------------ */
    function setCustomer(c) {
        customer = c;
        $('posCustomerSearch').value = c ? c.name : '';
        $('posCustomerSearch').readOnly = !!c;
        $('posCustomerClear').classList.toggle('d-none', !c);
        $('posCustomerResults').innerHTML = '';
    }
    let custTimer;
    $('posCustomerSearch').addEventListener('input', e => {
        clearTimeout(custTimer);
        const q = e.target.value.trim();
        if (q.length < 2) { $('posCustomerResults').innerHTML = ''; return; }
        custTimer = setTimeout(async () => {
            const list = await getJSON(C.urls.customers + '?q=' + encodeURIComponent(q));
            $('posCustomerResults').innerHTML = list.map(c => `<button type="button" class="list-group-item list-group-item-action small" data-id="${c.id}" data-name="${esc(c.name)}">${esc(c.name)} <span class="text-muted">${esc(c.number)} · ${esc(c.phone || '')}</span></button>`).join('');
            $('posCustomerResults').querySelectorAll('button').forEach(b => b.addEventListener('click', () => setCustomer({ id: Number(b.dataset.id), name: b.dataset.name })));
        }, 250);
    });
    $('posCustomerClear').addEventListener('click', () => { if (lines.some(l => l.type === 'order')) return; setCustomer(null); });

    /* ------------------------------- orders ------------------------------- */
    const orderModal = new bootstrap.Modal($('orderModal'));
    $('posAddOrder').addEventListener('click', () => { orderModal.show(); setTimeout(() => $('orderSearch').focus(), 300); loadOrders(''); });
    let orderTimer;
    $('orderSearch').addEventListener('input', e => { clearTimeout(orderTimer); orderTimer = setTimeout(() => loadOrders(e.target.value.trim()), 250); });
    async function loadOrders(q) {
        const list = await getJSON(C.urls.orders + '?q=' + encodeURIComponent(q) + (customer ? '&customer=' + customer.id : ''));
        $('orderResults').innerHTML = list.length ? list.map(o => `<button type="button" class="list-group-item list-group-item-action" data-o='${esc(JSON.stringify(o))}'>
            <div class="d-flex justify-content-between"><span class="fw-semibold">${esc(o.number)}</span><span>owes ${fmt(o.balance)}</span></div>
            <div class="small text-muted">${esc(o.customer)}${o.title ? ' · ' + esc(o.title) : ''}${o.deposit_due > 0 ? ' · deposit due ' + fmt(o.deposit_due) : ''}</div></button>`).join('')
            : '<div class="text-muted small p-2">No open orders with money owing.</div>';
        $('orderResults').querySelectorAll('button').forEach(b => b.addEventListener('click', () => {
            const o = JSON.parse(b.dataset.o);
            addOrder(o, o.deposit_due > 0 ? Math.min(o.deposit_due, o.balance) : o.balance);
            orderModal.hide();
        }));
    }

    /* -------------------------------- pay --------------------------------- */
    function showError(msg) { $('posError').innerHTML = msg; $('posError').classList.toggle('d-none', !msg); }
    async function pay() {
        if ($('posPay').disabled) return;
        busy = true; tenderState(); showError('');
        $('posPay').innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Saving…';
        const ref = $('tRef').value.trim() || null;
        const body = {
            client_ref: clientRef,
            customer_id: customer ? customer.id : null,
            lines: lines.map(l => l.type === 'order' ? { type: 'order', order_id: l.order_id, amount: Number(l.amount) }
                : { type: 'product', variant_id: l.variant_id, quantity: l.qty, unit_price: l.unit_price, discount: l.discount || 0 }),
            tenders: tenderInputs().filter(i => Number(i.value) > 0).map(i => ({ method: i.dataset.method, amount: Number(i.value), reference: i.dataset.method === 'cash' ? null : ref })),
        };
        try {
            const r = await fetch(C.urls.sale, { method: 'POST', credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': C.csrf }, body: JSON.stringify(body) });
            const data = await r.json().catch(() => ({}));
            if (!r.ok) {
                const errs = data.errors ? Object.values(data.errors).flat() : [data.message || 'Could not save the sale.'];
                showError(errs.map(esc).join('<br>'));
                return;
            }
            done(data);
        } catch (e) {
            // Network problem: keep the same client_ref so pressing again can't sell twice.
            showError('No connection. Press “Complete sale” again — it will not be charged twice.');
        } finally {
            busy = false;
            $('posPay').innerHTML = '<i class="ri-check-double-line me-1"></i>Complete sale';
            tenderState();
        }
    }
    const doneModal = new bootstrap.Modal($('doneModal'));
    function done(data) {
        $('doneNumber').textContent = data.sale.number + ' · ' + fmt(data.sale.total);
        $('doneChange').innerHTML = data.sale.change > 0 ? 'Give change: <strong>' + fmt(data.sale.change) + '</strong>' : 'No change due';
        $('doneReceipt').href = data.sale.receipt_url;
        $('posSummary').textContent = data.summary.sales_count + ' sale(s), ' + fmt(data.summary.sales_total);
        doneModal.show();
        setTimeout(() => $('doneNext').focus(), 300);
    }
    function reset() {
        lines = []; clientRef = uuid(); setCustomer(null);
        tenderInputs().forEach(i => i.value = ''); $('tRef').value = '';
        showError(''); render(); $('posScan').value = ''; $('posScan').focus();
        search('', false);   // refresh what's left on the shelf
    }
    $('doneNext').addEventListener('click', () => { doneModal.hide(); reset(); });
    $('posPay').addEventListener('click', pay);
    $('posClear').addEventListener('click', () => { if (!lines.length || confirm('Clear the basket?')) reset(); });

    /* ------------------------------ keyboard ------------------------------ */
    $('posScan').addEventListener('input', e => { clearTimeout(searchTimer); searchTimer = setTimeout(() => search(e.target.value.trim(), false), 200); });
    $('posScan').addEventListener('keydown', e => {
        if (e.key === 'Enter') {
            e.preventDefault();
            const q = e.target.value.trim();
            if (!q) return;
            clearTimeout(searchTimer);
            search(q, true).then(() => { if (lastResults.length === 1) { add(lastResults[0]); e.target.value = ''; search('', false); } });
        }
    });
    document.addEventListener('keydown', e => {
        if (e.key === 'F9') { e.preventDefault(); pay(); }
        if (e.key === 'Enter' && $('doneModal').classList.contains('show')) { e.preventDefault(); $('doneNext').click(); }
    });

    search('', false);
    render();
})();
