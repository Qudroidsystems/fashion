/* Online store behaviour: basket, mini cart, wishlist, product options, gallery, checkout totals. */
(function () {
    'use strict';
    var S = window.STORE || { base: '', open: false };
    var csrf = (document.querySelector('meta[name="csrf-token"]') || {}).content || S.csrf;
    var base = S.base || '';

    function post(url, data) {
        var body = new FormData();
        Object.keys(data || {}).forEach(function (k) { body.append(k, data[k]); });
        return fetch(base + url, { method: 'POST', body: body, credentials: 'same-origin',
            headers: { 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' } })
            .then(function (r) { return r.json().catch(function () { return { ok: false, message: 'Something went wrong. Please try again.' }; }); });
    }
    function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
    var toastTimer;
    function toast(msg, bad) {
        var t = document.getElementById('storeToast');
        if (!t || !msg) return;
        t.textContent = msg; t.classList.toggle('is-error', !!bad); t.classList.add('show');
        clearTimeout(toastTimer); toastTimer = setTimeout(function () { t.classList.remove('show'); }, 3200);
    }
    window.storeToast = toast;

    /* ---------------- mini cart ---------------- */
    function setCount(n) { document.querySelectorAll('.js-cart-count').forEach(function (el) { el.textContent = n; }); }
    function renderMini(d) {
        if (!d || !d.items) return;
        setCount(d.count);
        var box = document.querySelector('.js-mini-items');
        if (!box) return;
        if (!d.items.length) {
            box.innerHTML = '<div class="p-4 text-center"><p class="text-secondary">Your basket is empty.</p><a class="tf-btn btn-fill mt-2" href="' + base + '/shop"><span class="text">Start shopping</span></a></div>';
        } else {
            box.innerHTML = d.items.map(function (i) {
                return '<div class="tf-mini-cart-item">' +
                    '<div class="tf-mini-cart-image">' + (i.image ? '<img src="' + esc(i.image) + '" alt="">' : '') + '</div>' +
                    '<div class="tf-mini-cart-info flex-grow-1">' +
                    '<div class="mb_12 d-flex align-items-center justify-content-between flex-wrap gap-12"><div class="text-title"><a href="' + esc(i.url || '#') + '" class="link text-line-clamp-1">' + esc(i.name) + '</a></div>' +
                    '<button type="button" class="text-button tf-btn-remove btn p-0 js-mini-remove" data-item="' + i.id + '">Remove</button></div>' +
                    '<div class="d-flex align-items-center justify-content-between flex-wrap gap-12">' +
                    '<div class="text-secondary-2">' + esc(i.label || '') + '</div>' +
                    '<div class="store-mini-qty"><button type="button" class="js-mini-qty" data-item="' + i.id + '" data-q="' + (i.qty - 1) + '" aria-label="Less">−</button><span>' + i.qty + '</span>' +
                    '<button type="button" class="js-mini-qty" data-item="' + i.id + '" data-q="' + (i.qty + 1) + '" aria-label="More"' + (i.max !== null && i.qty >= i.max ? ' disabled' : '') + '>+</button></div>' +
                    '<div class="text-button">' + esc(i.total) + '</div></div>' +
                    (i.problem ? '<div class="store-err">' + esc(i.problem) + '</div>' : '') +
                    '</div></div>';
            }).join('');
        }
        var t = document.querySelector('.js-mini-total'); if (t) t.textContent = d.total;
        var dr = document.querySelector('.js-mini-discount-row');
        if (dr) { dr.classList.toggle('d-none', !d.discount); var dd = document.querySelector('.js-mini-discount'); if (dd) dd.textContent = d.discount ? '-' + d.discount : ''; }
        var th = document.querySelector('.js-mini-threshold');
        if (th) {
            th.classList.toggle('d-none', !d.threshold || !d.items.length);
            if (d.threshold) {
                document.querySelector('.js-mini-progress').style.width = d.threshold.percent + '%';
                document.querySelector('.js-mini-threshold-text').textContent = d.threshold.percent >= 100
                    ? 'You qualify for free delivery on eligible addresses!' : 'Spend ' + d.threshold.left + ' more for free delivery.';
            }
        }
        var co = document.querySelector('.js-mini-checkout'); if (co) co.classList.toggle('disabled', !d.items.length);
    }
    function loadMini() {
        if (!S.open) return;
        fetch(base + '/cart/mini', { credentials: 'same-origin', headers: { 'Accept': 'application/json' } }).then(function (r) { return r.json(); }).then(renderMini).catch(function () {});
    }
    function openMini() {
        var m = document.getElementById('shoppingCart');
        if (m && window.bootstrap) bootstrap.Modal.getOrCreateInstance(m).show();
    }
    var miniModal = document.getElementById('shoppingCart');
    if (miniModal) miniModal.addEventListener('show.bs.modal', loadMini);

    document.addEventListener('click', function (e) {
        var rm = e.target.closest('.js-mini-remove');
        if (rm) { post('/cart/remove', { item: rm.dataset.item }).then(function (d) { renderMini(d); }); return; }
        var q = e.target.closest('.js-mini-qty');
        if (q) { post('/cart/update', { item: q.dataset.item, quantity: q.dataset.q }).then(function (d) { renderMini(d); if (d.ok === false) toast(d.message, true); }); return; }

        var add = e.target.closest('.js-add');
        if (add) {
            e.preventDefault();
            add.classList.add('loading');
            post('/cart/add', { variant_id: add.dataset.variant, quantity: 1 }).then(function (d) {
                add.classList.remove('loading');
                renderMini(d);
                if (d.ok) openMini(); else toast(d.message, true);
            });
            return;
        }
        var w = e.target.closest('.js-wish');
        if (w) {
            e.preventDefault();
            post('/wishlist', { product: w.dataset.product }).then(function (d) {
                if (d.saved === undefined) { toast(d.message || 'Please try again.', true); return; }
                document.querySelectorAll('.js-wish[data-product="' + w.dataset.product + '"]').forEach(function (el) { el.classList.toggle('active', d.saved); });
                document.querySelectorAll('.js-wish-count').forEach(function (el) { el.textContent = d.count; el.classList.toggle('d-none', !d.count); });
                toast(d.message);
            });
            return;
        }
        var cp = e.target.closest('.js-copy');
        if (cp && navigator.clipboard) { navigator.clipboard.writeText(cp.dataset.copy).then(function () { toast('Copied ' + cp.dataset.copy); }); }
    });

    /* ---------------- product page ---------------- */
    var prod = document.querySelector('.js-product');
    if (prod) {
        var variants = JSON.parse(prod.dataset.variants || '[]');
        var sym = prod.dataset.currency || '';
        var low = parseInt(prod.dataset.low || '3', 10);
        var form = prod.querySelector('.js-add-form');
        var idInput = prod.querySelector('.js-variant-id');
        var current = variants.find(function (v) { return String(v.id) === idInput.value; }) || variants[0];
        var colour = current ? current.colour : null, size = current ? current.size : null;
        var fmt = function (n) { return sym + Number(n).toLocaleString(undefined, { minimumFractionDigits: n % 1 ? 2 : 0, maximumFractionDigits: 2 }); };
        var same = function (a, b) { return (a || '').toLowerCase() === (b || '').toLowerCase(); };

        function pick() {
            var v = variants.find(function (x) { return (!colour || same(x.colour, colour)) && (!size || x.size === size); })
                || variants.find(function (x) { return !colour || same(x.colour, colour); }) || variants[0];
            if (!v) return;
            current = v; idInput.value = v.id;
            if (v.size && v.size !== size) { size = v.size; var r = prod.querySelector('input[name="_size"][value="' + CSS.escape(v.size) + '"]'); if (r) r.checked = true; }
            var set = function (sel, txt) { var el = prod.querySelector(sel); if (el) el.textContent = txt; };
            set('.js-price', fmt(v.price));
            var cmp = prod.querySelector('.js-compare'), off = prod.querySelector('.js-off');
            if (cmp) { cmp.classList.toggle('d-none', !v.compare); cmp.textContent = v.compare ? fmt(v.compare) : ''; }
            if (off) { off.classList.toggle('d-none', !v.compare); off.textContent = v.compare ? '-' + Math.round((v.compare - v.price) / v.compare * 100) + '%' : ''; }
            set('.js-sku', v.sku || '');
            set('.js-colour-label', v.colour || '');
            set('.js-size-label', v.size || '');
            var st = prod.querySelector('.js-stock');
            var out = v.stock !== null && v.stock <= 0;
            if (st) st.innerHTML = v.stock === null ? 'In stock' : (out ? '<span class="text-critical">Sold out</span>' : (v.stock <= low ? '<span class="text-warning-2">Only ' + v.stock + ' left</span>' : 'In stock'));
            prod.querySelectorAll('.js-add-btn, .js-buy-now').forEach(function (b) { b.disabled = out; });
            var qty = prod.querySelector('.js-qty'); if (qty) { qty.max = v.stock === null ? 99 : Math.max(1, v.stock); if (+qty.value > +qty.max) qty.value = qty.max; }
            var wa = prod.querySelector('.js-wa-product'); if (wa && v.wa) wa.href = v.wa;
            // Sizes that exist in the chosen colour
            prod.querySelectorAll('.js-size').forEach(function (l) {
                var ok = variants.some(function (x) { return x.size === l.dataset.value && (!colour || same(x.colour, colour)) && (x.stock === null || x.stock > 0); });
                l.classList.toggle('is-off', !ok);
            });
            // Show the pictures of this colour
            if (window.storeGallery && colour) {
                var slides = document.querySelectorAll('.js-main .swiper-slide');
                for (var i = 0; i < slides.length; i++) { if (same(slides[i].dataset.colour, colour)) { window.storeGallery.slideTo(i); break; } }
            }
        }
        prod.querySelectorAll('.js-colour').forEach(function (l) { l.addEventListener('click', function () { colour = l.dataset.value; setTimeout(pick); }); });
        prod.querySelectorAll('.js-size').forEach(function (l) { l.addEventListener('click', function () { size = l.dataset.value; setTimeout(pick); }); });
        var q = prod.querySelector('.js-qty');
        prod.querySelectorAll('.js-qty-dec, .js-qty-inc').forEach(function (b) {
            b.addEventListener('click', function () {
                var n = parseInt(q.value || '1', 10) + (b.classList.contains('js-qty-inc') ? 1 : -1);
                q.value = Math.max(1, Math.min(parseInt(q.max || '99', 10), n));
            });
        });
        if (form && S.open) {
            form.addEventListener('submit', function (e) {
                var buyNow = e.submitter && e.submitter.name === 'buy_now';
                e.preventDefault();
                var btn = e.submitter; if (btn) btn.disabled = true;
                post('/cart/add', { variant_id: idInput.value, quantity: q ? q.value : 1, buy_now: buyNow ? 1 : 0 }).then(function (d) {
                    if (btn) btn.disabled = false;
                    renderMini(d);
                    if (!d.ok) { toast(d.message, true); return; }
                    if (buyNow && d.redirect) { window.location = d.redirect; return; }
                    openMini();
                });
            });
        }
        pick();
    }

    /* gallery (thumbnails + main picture) */
    if (window.Swiper && document.querySelector('.js-main')) {
        var thumbs = document.querySelector('.js-thumbs') ? new Swiper('.js-thumbs', {
            spaceBetween: 10, slidesPerView: 'auto', freeMode: true, watchSlidesProgress: true, direction: 'horizontal',
            breakpoints: { 1200: { direction: 'vertical' } },
        }) : null;
        window.storeGallery = new Swiper('.js-main', { spaceBetween: 0, thumbs: thumbs ? { swiper: thumbs } : undefined });
    }

    /* ---------------- checkout ---------------- */
    var co = document.querySelector('.js-checkout');
    if (co) {
        var timer;
        var labels = { whatsapp: 'Send order on WhatsApp', paystack: 'Continue to payment', stripe: 'Continue to payment' };
        function sync() {
            var ful = (co.querySelector('.js-ful:checked') || {}).value || 'delivery';
            var d = co.querySelector('.js-delivery'), p = co.querySelector('.js-pickup');
            if (d) d.classList.toggle('d-none', ful !== 'delivery');
            if (p) p.classList.toggle('d-none', ful !== 'pickup');
            var m = (co.querySelector('.js-method:checked') || {}).value;
            var lbl = co.querySelector('.js-place-label'); if (lbl) lbl.textContent = labels[m] || 'Place order';
            co.querySelectorAll('.store-pay').forEach(function (el) { el.classList.toggle('active', !!el.querySelector('input:checked')); });
            clearTimeout(timer);
            timer = setTimeout(function () {
                post('/checkout/quote', { fulfilment: ful, state: (co.querySelector('[name=state]') || {}).value || '',
                    email: (co.querySelector('[name=email]') || {}).value || '', phone: (co.querySelector('[name=phone]') || {}).value || '' })
                    .then(function (t) {
                        if (!t.total) return;
                        var set = function (sel, v) { var el = co.querySelector(sel); if (el) el.textContent = v || ''; };
                        set('.js-t-sub', t.subtotal); set('.js-t-ship', t.shipping); set('.js-t-total', t.total);
                        set('.js-t-ship-label', ful === 'delivery' && t.shipping_label && t.shipping_label !== 'Delivery' ? '(' + t.shipping_label + ')' : '');
                        set('.js-eta', ful === 'delivery' && t.eta ? 'Usually arrives in ' + t.eta + '.' : '');
                        var dr = co.querySelector('.js-t-disc-row'); if (dr) { dr.classList.toggle('d-none', !t.discount); set('.js-t-disc', t.discount); }
                        var place = co.querySelector('.js-place'); if (place) place.disabled = !t.deliverable;
                        if (!t.deliverable) toast('We do not deliver to that state yet — choose pickup or another address.', true);
                    });
            }, 250);
        }
        co.addEventListener('change', function (e) { if (e.target.matches('.js-q, .js-ful, .js-method, [name=state]')) sync(); });
        co.addEventListener('submit', function () { var b = co.querySelector('.js-place'); if (b) { setTimeout(function () { b.disabled = true; }, 0); } });
        sync();
    }

    /* order page: open WhatsApp with the order straight after a WhatsApp checkout */
    var wa = document.querySelector('.js-wa-order[data-autoopen]');
    if (wa) setTimeout(function () { window.open(wa.href, '_blank'); }, 600);

    if (S.open && document.querySelector('.js-cart-count')) { /* counts are rendered server-side; refresh after back/forward cache */
        window.addEventListener('pageshow', function (e) { if (e.persisted) loadMini(); });
    }
})();
