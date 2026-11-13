// Gift cards — the purchase flow, on /gift-cards/ and inside Book Us.
//
//   Gift → For → You → Pay
//
// Every step is a history entry, so the browser's Back button walks the form
// backwards instead of leaving it; the payment step is shown in place. What
// has been typed is kept in sessionStorage (so it survives Back from the
// order page or from WiPay), and an order that is still unpaid is updated
// rather than duplicated when the buyer changes something.
(function () {
    'use strict';

    var cfg = window.tweller_gc || {};
    var $ = function (id) { return document.getElementById(id); };
    var STORE = 'tgc_form_v1';
    var TESS = 'https://cdn.jsdelivr.net/npm/tesseract.js@5/dist/tesseract.min.js';

    function money(n) {
        n = Number(n) || 0;
        return 'TT$' + n.toLocaleString(undefined, { minimumFractionDigits: n % 1 ? 2 : 0, maximumFractionDigits: 2 });
    }

    function esc(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }

    function emit(name, detail) {
        try { document.dispatchEvent(new CustomEvent(name, { detail: detail || {} })); } catch (e) {}
    }

    // "Alex Gomez" → "AG" — the same rule the server uses for the code.
    function initials(name) {
        var w = String(name || '');
        if (w.normalize) w = w.normalize('NFD').replace(/[̀-ͯ]/g, '');
        w = w.trim().split(/\s+/).filter(Boolean);
        var out = '';
        [w[0], w.length > 1 ? w[w.length - 1] : ''].forEach(function (x) {
            var m = x && x.match(/[A-Za-z]/);
            if (m) out += m[0].toUpperCase();
        });
        return out;
    }

    var store = {
        read: function () {
            try { var d = JSON.parse(sessionStorage.getItem(STORE) || 'null'); return d && d.v === 1 ? d : null; } catch (e) { return null; }
        },
        write: function (d) { try { d.v = 1; sessionStorage.setItem(STORE, JSON.stringify(d)); } catch (e) {} },
        clear: function () { try { sessionStorage.removeItem(STORE); } catch (e) {} }
    };

    var app = {
        booted: false,
        step: 1,
        view: 'form',        // 'form' | 'pay'
        editing: null,       // { ref, token } of the unpaid order this form is changing
        payHtml: '',
        payUrl: '',
        fields: null         // the unpaid order's fields, for "Change gift" on a fresh page
    };
    var ui = {};             // set by initPurchase()

    // ═════════════════════ Steps and history ═════════════════════

    function root() { return $('tgc-app'); }

    function setProgress(n) {
        if (root()) root().setAttribute('data-step', n);
        document.querySelectorAll('#tgc-progress li').forEach(function (li) {
            var p = parseInt(li.getAttribute('data-p'), 10);
            li.classList.toggle('is-done', p < n);
            li.classList.toggle('is-now', p === n);
            if (p === n) li.setAttribute('aria-current', 'step'); else li.removeAttribute('aria-current');
        });
        emit('tgc:step', { step: n });
    }

    function toTop() {
        var el = root();
        if (!el || !el.getBoundingClientRect) return;
        var top = el.getBoundingClientRect().top;
        if (top < 0 || top > 160) {
            try { window.scrollTo({ top: window.pageYOffset + top - 16, behavior: 'smooth' }); } catch (e) { window.scrollTo(0, window.pageYOffset + top - 16); }
        }
    }

    function stampHistory(mode, state, url) {
        if (!window.history || !history.pushState) return;
        try {
            if (mode === 'push') history.pushState(state, '', url || location.href);
            else if (mode === 'replace') history.replaceState(state, '', url || location.href);
        } catch (e) {}
    }

    function cameFrom() {
        var st = history.state;
        return st && st.tgc != null ? st.tgc : 'out';
    }

    // Replacing an entry keeps where it came from.
    function keptFrom() {
        var st = history.state;
        return st && st.from != null ? st.from : 'out';
    }

    function showStep(n, mode, quiet) {
        var form = $('tgc-form'), stage = $('tgc-stage');
        if (!form) return;
        n = Math.max(1, Math.min(3, n));
        form.hidden = false;
        if (stage) stage.hidden = true;
        form.querySelectorAll('[data-gstep]').forEach(function (p) {
            p.hidden = parseInt(p.getAttribute('data-gstep'), 10) !== n;
        });
        app.step = n;
        app.view = 'form';
        stampHistory(mode, { tgc: n, from: mode === 'replace' ? keptFrom() : cameFrom() }, null);
        setProgress(n);
        persist();
        if (!quiet) toTop();
    }

    function showPay(html, ref, token, mode, url) {
        var form = $('tgc-form'), stage = $('tgc-stage');
        if (!stage) return;
        stage.innerHTML = html;
        stage.hidden = false;
        if (form) form.hidden = true;
        app.view = 'pay';
        app.payHtml = html;
        if (url) app.payUrl = url;
        stampHistory(mode, { tgc: 'pay', from: mode === 'replace' ? keptFrom() : cameFrom() }, url);
        setProgress(4);
        wirePay();
        toTop();
        if (/data-status="(active|used)"/.test(html)) store.clear();
    }

    // The Back / Forward buttons.
    window.addEventListener('popstate', function (e) {
        var st = e.state && e.state.tgc;
        if (st == null) {
            // Past the first entry we made: inside Book Us that is the session list.
            if (cfg.embed) emit('tgc:exit');
            return;
        }
        if (!app.booted) return;
        if (cfg.embed) emit('tgc:enter');
        if (st === 'pay') {
            if (app.payHtml) showPay(app.payHtml, null, null, 'none');
            return;
        }
        showStep(parseInt(st, 10) || 1, 'none');
    });

    // ═════════════════════ Keeping the form ═════════════════════

    var TEXT_IDS = ['tgc-rname', 'tgc-remail', 'tgc-message', 'tgc-pname', 'tgc-pemail', 'tgc-pphone', 'tgc-date', 'tgc-custom'];

    function checked(name) {
        var el = document.querySelector('#tgc-form input[name="' + name + '"]:checked');
        return el ? el.value : '';
    }

    function persist() {
        if (!ui.form) return;
        var f = {};
        TEXT_IDS.forEach(function (id) { var el = $(id); if (el) f[id] = el.value; });
        store.write({
            step: app.step,
            kind: ui.state.kind,
            pkg: ui.state.pkg ? ui.state.pkg.key : '',
            type: ui.typeSel ? ui.typeSel.value : '',
            amount: ui.state.amount,
            sendto: checked('tgc-sendto'),
            when: checked('tgc-when'),
            pay: checked('tgc-pay'),
            f: f,
            order: app.editing
        });
    }

    // Put values back (from storage, or from an unpaid order) and redraw.
    function apply(d) {
        if (!d || !ui.form) return;
        var f = d.f || {};
        TEXT_IDS.forEach(function (id) { var el = $(id); if (el && f[id] != null) el.value = f[id]; });
        setKind(d.kind === 'amount' || !(cfg.packages || []).length ? 'amount' : 'package');
        if (d.kind === 'package') {
            var idx = (cfg.packages || []).findIndex(function (p) { return p.key === d.pkg; });
            if (idx >= 0) pickPackage(cfg.packages[idx], d.type);
        } else if (d.amount) {
            ui.state.amount = Number(d.amount) || 0;
            var hit = false;
            ui.chips.querySelectorAll('.tgc-chip').forEach(function (c) {
                var on = Number(c.getAttribute('data-v')) === ui.state.amount;
                c.classList.toggle('is-on', on);
                c.setAttribute('aria-pressed', on ? 'true' : 'false');
                hit = hit || on;
            });
            ui.custom.value = hit ? '' : (ui.state.amount || '');
        }
        ['sendto', 'when', 'pay'].forEach(function (k) {
            if (!d[k]) return;
            var r = ui.form.querySelector('input[name="tgc-' + k + '"][value="' + d[k] + '"]');
            if (r) r.checked = true;
        });
        syncDelivery();
        update();
    }

    // An unpaid order's fields (as the server returns them) → what apply() takes.
    function fromOrder(o) {
        return {
            kind: o.kind, pkg: o.package, type: o.session_type, amount: o.amount,
            sendto: o.send_to, when: o.deliver_on ? 'date' : 'now', pay: o.pay,
            f: {
                'tgc-rname': o.recipient_name, 'tgc-remail': o.recipient_email, 'tgc-message': o.message,
                'tgc-pname': o.purchaser_name, 'tgc-pemail': o.purchaser_email, 'tgc-pphone': o.purchaser_phone,
                'tgc-date': o.deliver_on || ''
            }
        };
    }

    // ═════════════════════ Purchase form ═════════════════════

    var setKind, pickPackage, update, syncDelivery;

    function initPurchase() {
        var form = $('tgc-form');
        if (!form) return;

        var state = { kind: (cfg.packages && cfg.packages.length) ? 'package' : 'amount', pkg: null, amount: 0 };
        ui = {
            form: form, state: state,
            typeSel: $('tgc-type'), typeWrap: $('tgc-type-wrap'),
            pkgHost: $('tgc-pkgs'), chips: $('tgc-chips'), custom: $('tgc-custom'),
            dateEl: $('tgc-date')
        };

        // A session or an amount
        setKind = function (kind) {
            state.kind = kind;
            form.querySelectorAll('.tgc-tab').forEach(function (t) {
                var on = t.getAttribute('data-kind') === kind;
                t.classList.toggle('is-on', on);
                t.setAttribute('aria-selected', on ? 'true' : 'false');
            });
            form.querySelectorAll('.tgc-kind').forEach(function (k) { k.hidden = k.getAttribute('data-kind') !== kind; });
        };
        form.querySelectorAll('.tgc-tab').forEach(function (tab) {
            tab.addEventListener('click', function () { setKind(tab.getAttribute('data-kind')); update(); persist(); });
        });

        // Package cards
        pickPackage = function (p, type) {
            state.pkg = p;
            // Switching package keeps the chosen shoot when the new one offers it too.
            if (type == null && ui.typeSel && ui.typeSel.value && p.types && p.types[ui.typeSel.value]) type = ui.typeSel.value;
            ui.pkgHost.querySelectorAll('.tgc-pkg').forEach(function (x) {
                var on = x.getAttribute('data-key') === p.key;
                x.classList.toggle('is-on', on);
                x.setAttribute('aria-pressed', on ? 'true' : 'false');
            });
            ui.typeSel.innerHTML = '<option value="">Let them choose</option>';
            Object.keys(p.types || {}).forEach(function (k) {
                var o = document.createElement('option');
                o.value = k; o.textContent = p.types[k];
                ui.typeSel.appendChild(o);
            });
            if (type && p.types && p.types[type]) ui.typeSel.value = type;
            ui.typeWrap.hidden = !Object.keys(p.types || {}).length;
            update();
        };
        (cfg.packages || []).forEach(function (p) {
            var b = document.createElement('button');
            b.type = 'button';
            b.className = 'tgc-pkg';
            b.setAttribute('data-key', p.key);
            b.setAttribute('aria-pressed', 'false');
            b.innerHTML = '<span class="tgc-pkg__name">' + esc(p.name) + '</span>' +
                '<span class="tgc-pkg__price">' + money(p.price) + '</span>' +
                (p.features && p.features.length ? '<span class="tgc-pkg__feat">' + p.features.map(esc).join(' · ') + '</span>' : '');
            b.addEventListener('click', function () { pickPackage(p); persist(); });
            ui.pkgHost.appendChild(b);
        });
        if (ui.typeSel) ui.typeSel.addEventListener('change', function () { update(); persist(); });

        // Amount chips + custom
        (cfg.presets || []).forEach(function (v) {
            var c = document.createElement('button');
            c.type = 'button';
            c.className = 'tgc-chip';
            c.setAttribute('data-v', v);
            c.setAttribute('aria-pressed', 'false');
            c.textContent = money(v);
            c.addEventListener('click', function () {
                state.amount = v;
                ui.custom.value = '';
                ui.chips.querySelectorAll('.tgc-chip').forEach(function (x) {
                    x.classList.toggle('is-on', x === c);
                    x.setAttribute('aria-pressed', x === c ? 'true' : 'false');
                });
                update(); persist();
            });
            ui.chips.appendChild(c);
        });
        if (ui.custom) ui.custom.addEventListener('input', function () {
            state.amount = parseFloat(ui.custom.value) || 0;
            ui.chips.querySelectorAll('.tgc-chip').forEach(function (x) { x.classList.remove('is-on'); x.setAttribute('aria-pressed', 'false'); });
            update(); persist();
        });

        // Delivery: email them (right away / on a date), or send it to me
        syncDelivery = function () {
            var toThem = checked('tgc-sendto') !== 'me';
            var onDate = checked('tgc-when') === 'date';
            $('tgc-when').hidden = !toThem;
            $('tgc-date-wrap').hidden = !(toThem && onDate);
            $('tgc-remail').required = toThem;
            if (!onDate) ui.dateEl.value = '';
        };
        form.querySelectorAll('input[name="tgc-sendto"], input[name="tgc-when"]').forEach(function (r) {
            r.addEventListener('change', function () {
                syncDelivery();
                if (r.name === 'tgc-when' && r.value === 'date') ui.dateEl.focus();
                persist();
            });
        });
        $('tgc-date-clear').addEventListener('click', function () {
            ui.dateEl.value = '';
            form.querySelector('input[name="tgc-when"][value="now"]').checked = true;
            syncDelivery();
            persist();
        });
        syncDelivery();

        function total() {
            return state.kind === 'package' ? (state.pkg ? state.pkg.price : 0) : state.amount;
        }

        // Live preview: what, who, and the start of the code it will get.
        update = function () {
            var t = total();
            $('tgc-total').textContent = t ? money(t) : '—';
            $('tgc-total').classList.toggle('is-empty', !t);
            var what = 'Choose a gift';
            var word = 'GIFT';
            if (state.kind === 'package' && state.pkg) {
                var typed = ui.typeSel && ui.typeSel.value;
                what = (typed ? ui.typeSel.options[ui.typeSel.selectedIndex].text + ' — ' : '') + state.pkg.name;
                var w = cfg.words || {};
                word = (typed && w.types && w.types[typed]) || (w.packages && w.packages[state.pkg.key]) || 'GIFT';
            } else if (state.kind === 'amount' && state.amount) {
                what = money(state.amount) + ' gift card';
            }
            $('tgc-pv-what').textContent = what;
            $('tgc-sum-what').textContent = t ? what : 'No gift chosen yet';
            $('tgc-sum-total').textContent = t ? money(t) : '—';
            var to = $('tgc-rname').value.trim(), from = $('tgc-pname').value.trim();
            $('tgc-pv-to').textContent = [to ? 'For ' + to : '', from ? 'From ' + from : ''].filter(Boolean).join(' · ');
            var msg = $('tgc-message').value.trim();
            $('tgc-pv-msg').textContent = msg ? '“' + msg + '”' : '';
            $('tgc-pv-code').textContent = word + '-' + (initials(to) || initials(from) || 'TW') + '•••';
            var buy = $('tgc-buy');
            if (buy && !buy.disabled) buy.textContent = app.editing ? 'Update my order' : 'Buy gift card';
        };

        TEXT_IDS.concat([]).forEach(function (id) {
            var el = $(id);
            if (el) el.addEventListener('input', function () { update(); persist(); });
        });
        form.querySelectorAll('input[name="tgc-pay"]').forEach(function (r) { r.addEventListener('change', persist); });

        // Steps
        function fail(n, msg, el) {
            var box = $('tgc-error-' + n);
            box.textContent = msg;
            box.hidden = false;
            if (el) { el.setAttribute('aria-invalid', 'true'); el.focus(); }
        }
        function clearFail() {
            form.querySelectorAll('.tgc-error').forEach(function (b) { b.hidden = true; });
            form.querySelectorAll('[aria-invalid]').forEach(function (e) { e.removeAttribute('aria-invalid'); });
        }
        var mailRe = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;

        function valid(n) {
            clearFail();
            if (n === 1) {
                var amount = total();
                if (!amount) { fail(1, 'Please choose a session or an amount.'); return false; }
                if (state.kind === 'amount' && (amount < cfg.min || amount > cfg.max)) {
                    fail(1, 'Please choose an amount between ' + money(cfg.min) + ' and ' + money(cfg.max) + '.', ui.custom);
                    return false;
                }
            } else if (n === 2) {
                if (!$('tgc-rname').value.trim()) { fail(2, 'Please enter who the gift is for.', $('tgc-rname')); return false; }
                if (checked('tgc-sendto') !== 'me') {
                    if (!mailRe.test($('tgc-remail').value.trim())) { fail(2, 'Please enter their email, or choose to have it sent to you.', $('tgc-remail')); return false; }
                    if (checked('tgc-when') === 'date' && !ui.dateEl.value) { fail(2, 'Pick the day to send it, or choose “Right away”.', ui.dateEl); return false; }
                }
            } else if (n === 3) {
                if (!$('tgc-pname').value.trim()) { fail(3, 'Please enter your name.', $('tgc-pname')); return false; }
                if (!mailRe.test($('tgc-pemail').value.trim())) { fail(3, 'Please enter your email.', $('tgc-pemail')); return false; }
            }
            return true;
        }

        form.querySelectorAll('[data-tgc-next]').forEach(function (b) {
            b.addEventListener('click', function () { if (valid(app.step)) showStep(app.step + 1, 'push'); });
        });
        form.querySelectorAll('[data-tgc-prev]').forEach(function (b) {
            b.addEventListener('click', function () {
                var st = history.state;
                // The entry before this one is the previous step: use the real Back.
                if (st && st.tgc === app.step && st.from === app.step - 1) history.back();
                else showStep(app.step - 1, 'push');
            });
        });
        form.querySelectorAll('[data-tgc-exit]').forEach(function (b) {
            b.addEventListener('click', function () {
                var st = history.state;
                if (st && st.tgc === 1 && st.from === 'out') history.back();
                else emit('tgc:exit');
            });
        });

        form.addEventListener('submit', function (e) {
            e.preventDefault();
            if (app.step < 3) { if (valid(app.step)) showStep(app.step + 1, 'push'); return; }
            buy();
        });

        async function buy() {
            clearFail();
            if (!valid(1)) { showStep(1, 'push'); valid(1); return; }
            if (!valid(2)) { showStep(2, 'push'); valid(2); return; }
            if (!valid(3)) return;

            var amount = total();
            var payEl = form.querySelector('input[name="tgc-pay"]:checked') || form.querySelector('input[name="tgc-pay"]');
            var sendTo = checked('tgc-sendto') || 'recipient';
            var btn = $('tgc-buy');
            btn.disabled = true;
            btn.textContent = 'One moment…';

            var body = {
                kind: state.kind,
                package: state.pkg ? state.pkg.key : '',
                session_type: ui.typeSel ? ui.typeSel.value : '',
                amount: state.kind === 'amount' ? amount : 0,
                recipient_name: $('tgc-rname').value.trim(),
                recipient_email: $('tgc-remail').value.trim(),
                message: $('tgc-message').value.trim(),
                send_to: sendTo,
                deliver_on: (sendTo === 'recipient' && checked('tgc-when') === 'date') ? ui.dateEl.value : '',
                purchaser_name: $('tgc-pname').value.trim(),
                purchaser_email: $('tgc-pemail').value.trim(),
                purchaser_phone: $('tgc-pphone').value.trim(),
                pay: payEl ? payEl.value : 'bank'
            };

            try {
                var data = null;
                if (app.editing) {
                    var up = await post('update', Object.assign({ ref: app.editing.ref, token: app.editing.token }, body));
                    // Paid, or gone, in the meantime: this becomes a new order.
                    if (up.res.status === 404 || up.res.status === 409) { app.editing = null; }
                    else if (!up.res.ok || !up.data.ok) throw new Error(up.data.message || 'Something went wrong — please try again.');
                    else data = up.data;
                }
                if (!data) {
                    var cr = await post('order', body);
                    if (!cr.res.ok || !cr.data.ok) throw new Error(cr.data.message || 'Something went wrong — please try again.');
                    data = cr.data;
                    try { if (typeof window.fbq === 'function') window.fbq('track', 'InitiateCheckout', { content_name: 'Gift card' }); } catch (x) {}
                }
                app.editing = { ref: data.order_ref, token: data.token };
                app.fields = data.fields;
                persist();
                showPay(data.html, data.order_ref, data.token, 'push', payUrlFor(data));
                // Paying by card: on to WiPay. Back from there lands on this
                // payment step, with the form behind it intact.
                if (data.pay_url) window.location.href = data.pay_url;
            } catch (ex) {
                fail(3, ex.message);
            }
            btn.disabled = false;
            update();
        }

        update();
    }

    async function post(path, body) {
        var res = await fetch(cfg.rest + path, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body) });
        var data = {};
        try { data = await res.json(); } catch (e) {}
        return { res: res, data: data };
    }

    // Where the payment step lives, so a reload (or the email link) lands on it.
    function payUrlFor(data) {
        if (!cfg.embed) return data.view_url;
        var u = new URL(location.href);
        u.searchParams.set('gc', data.order_ref);
        u.searchParams.set('t', data.token);
        return u.pathname + u.search + u.hash;
    }

    // ═════════════════════ Payment step ═════════════════════

    function currentOrder() {
        var o = document.querySelector('#tgc-stage .tgc-order');
        return o ? { ref: o.getAttribute('data-ref'), token: o.getAttribute('data-token'), status: o.getAttribute('data-status') } : null;
    }

    function wirePay() {
        initReceipt();
        var edit = document.querySelector('#tgc-stage [data-tgc-edit]');
        if (edit && !edit.getAttribute('data-wired')) {
            edit.setAttribute('data-wired', '1');
            edit.addEventListener('click', function (e) {
                if (!$('tgc-form')) return;      // no form on this page: follow the link
                e.preventDefault();
                var st = history.state;
                // We pushed this step from the form: Back is the form, as typed.
                if (st && st.tgc === 'pay' && st.from === 3) { history.back(); return; }
                var o = currentOrder();
                if (o) app.editing = { ref: o.ref, token: o.token };
                if (app.fields) apply(fromOrder(app.fields));
                showStep(3, 'push');
            });
        }
    }

    function readAmount(text) {
        var labeled = text.match(/(?:transfer\s+amount|amount)[\s:]*(?:TTD|\$)?\s?(\d{1,3}(?:,\d{3})*(?:\.\d{2})?)/i);
        if (labeled && labeled[1]) return parseFloat(labeled[1].replace(/[^\d.]/g, ''));
        var cur = text.match(/(?:TTD|\$)\s?(\d{1,3}(?:,\d{3})*(?:\.\d{2})?)/gi);
        var plain = text.match(/\b\d{1,3}(?:,\d{3})*\.\d{2}\b/g);
        var list = (cur || plain || []).map(function (m) { return parseFloat(m.replace(/[^\d.]/g, '')); })
            .filter(function (a) { return !isNaN(a) && a > 0; });
        return list.length ? Math.max.apply(null, list) : null;
    }

    function readRef(text) {
        var m = text.match(/(?:ref(?:erence)?\s*(?:no\.?|#|number)?|transaction\s*(?:id|no\.?|#)?|trx\s*(?:id|no\.?|#)?)\s*[:\-#]?\s*([a-z0-9]{6,15})/i);
        return m && m[1] ? m[1].toUpperCase() : '';
    }

    function ensureTesseract() {
        if (window.Tesseract) return Promise.resolve();
        return new Promise(function (resolve) {
            var s = document.createElement('script');
            s.src = TESS;
            s.onload = resolve;
            s.onerror = resolve;
            document.head.appendChild(s);
        });
    }

    // Same two-step flow as the booking tracker: read the receipt in the
    // browser (free OCR), let the buyer confirm or correct the amount, then
    // upload — so the studio sees the amount next to the receipt.
    function initReceipt() {
        var form = $('tgc-receipt');
        if (!form || form.getAttribute('data-wired')) return;
        form.setAttribute('data-wired', '1');

        var bank = $('tgc-receipt-bank'), other = $('tgc-receipt-bank-other');
        bank.addEventListener('change', function () {
            other.hidden = bank.value !== 'Other';
            other.required = bank.value === 'Other';
        });

        var stage = 'scan', pendingRef = '';
        var fileEl = $('tgc-receipt-file'), btn = $('tgc-receipt-btn');
        var confirmBox = $('tgc-receipt-confirm'), amountEl = $('tgc-receipt-amount');
        var status = $('tgc-receipt-status'), err = $('tgc-receipt-error');
        var expected = parseFloat(form.getAttribute('data-expected')) || 0;

        fileEl.addEventListener('change', function () {
            stage = 'scan';
            confirmBox.hidden = true;
            btn.textContent = 'Verify receipt upload';
        });

        form.addEventListener('submit', async function (e) {
            e.preventDefault();
            err.hidden = true;
            if (!form.reportValidity() || !fileEl.files.length) return;
            var file = fileEl.files[0];

            if (stage === 'confirm') return upload(file);

            btn.disabled = true;
            btn.textContent = 'Reading receipt…';
            status.hidden = false;
            status.textContent = 'Reading your receipt…';

            var amount = null;
            pendingRef = '';
            await ensureTesseract();
            if (window.Tesseract) {
                try {
                    var worker = await Tesseract.createWorker('eng');
                    var ret = await worker.recognize(file);
                    await worker.terminate();
                    amount = readAmount(ret.data.text || '');
                    pendingRef = readRef(ret.data.text || '');
                } catch (x) { console.error('OCR failed', x); }
            }

            stage = 'confirm';
            status.hidden = true;
            amountEl.value = amount ? amount.toFixed(2) : '';
            var note = $('tgc-receipt-note');
            if (amount && Math.abs(amount - expected) < 0.01) {
                note.textContent = 'We read ' + money(amount) + ' from your receipt — that matches your gift card. Submit to finish.';
            } else if (amount) {
                note.textContent = 'We read ' + money(amount) + ' from your receipt, but the gift card is ' + money(expected) + '. Please check it, correct it if needed, then submit.';
            } else {
                note.textContent = "We couldn't read the amount automatically. Please type the amount you transferred, then submit.";
            }
            confirmBox.hidden = false;
            btn.disabled = false;
            btn.textContent = 'Confirm & submit receipt';
            amountEl.focus();
        });

        async function upload(file) {
            var o = currentOrder();
            btn.disabled = true;
            btn.textContent = 'Uploading…';
            var fd = new FormData();
            fd.append('receipt', file);
            fd.append('ref', o ? o.ref : '');
            fd.append('token', o ? o.token : '');
            var amt = parseFloat(amountEl.value);
            if (amt > 0) fd.append('ocr_amounts', 'TTD ' + amt.toFixed(2));
            fd.append('amount_confirmed', '1');
            if (pendingRef) fd.append('ocr_reference', pendingRef);
            fd.append('bank_name', bank.value === 'Other' ? other.value : bank.value);
            try {
                var res = await fetch(cfg.rest + 'receipt', { method: 'POST', body: fd });
                var data = await res.json();
                if (!res.ok || !data.ok) throw new Error(data.message || 'Upload failed — please try again.');
                // The receipt is with the studio: the order can't be edited any more.
                app.editing = null;
                store.clear();
                showPay(data.html, data.order_ref, data.token, 'replace', app.payUrl || undefined);
            } catch (x) {
                btn.disabled = false;
                btn.textContent = 'Confirm & submit receipt';
                err.textContent = x.message;
                err.hidden = false;
            }
        }
    }

    // ═════════════════════ Start-up ═════════════════════

    async function loadOrder(ref, token) {
        var res = await fetch(cfg.rest + 'view?ref=' + encodeURIComponent(ref) + '&token=' + encodeURIComponent(token));
        var data = await res.json();
        if (!res.ok || !data.ok) throw new Error(data.message || 'Order not found.');
        return data;
    }

    function boot() {
        if (app.booted) return;
        app.booted = true;
        initPurchase();

        var q = new URLSearchParams(location.search);

        // Server-drawn order page (?gc=…&t=…) on /gift-cards/.
        if (cfg.order) {
            stampHistory('replace', { tgc: 'pay', from: 'out' }, null);
            app.view = 'pay';
            app.payHtml = $('tgc-stage').innerHTML;
            setProgress(4);
            wirePay();
            if (cfg.order.status === 'pending' && cfg.order.fields) {
                app.editing = { ref: cfg.order.ref, token: cfg.order.token };
                app.fields = cfg.order.fields;
            } else {
                app.editing = null;
            }
            if (cfg.order.status === 'active' || cfg.order.status === 'used') store.clear();
            return;
        }

        // Inside Book Us, a reload on the payment step: fetch it back.
        if (cfg.embed && q.get('gc') && q.get('t')) {
            loadOrder(q.get('gc'), q.get('t')).then(function (data) {
                emit('tgc:enter');
                stampHistory('replace', { tgc: 'pay', from: 'out' }, null);
                if (data.status === 'pending') { app.editing = { ref: data.order_ref, token: data.token }; app.fields = data.fields; }
                showPay(data.html, data.order_ref, data.token, 'none', location.pathname + location.search);
            }).catch(function () {});
            return;
        }

        if (!cfg.embed) {
            var saved = store.read();
            if (saved) { app.editing = saved.order || null; apply(saved); }
            showStep(saved ? saved.step : 1, 'replace', true);
        }
    }

    // Book Us: the "Gift card" tile opens the flow (a new history entry).
    window.tweller_gc_app = {
        open: function () {
            boot();
            var saved = store.read();
            if (saved && !app.editing) { app.editing = saved.order || null; apply(saved); }
            if (app.view === 'pay' && app.payHtml) { showPay(app.payHtml, null, null, 'push'); return; }
            showStep(saved ? saved.step : 1, 'push', true);
        },
        show: function () { boot(); }
    };

    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot);
    else boot();
})();
