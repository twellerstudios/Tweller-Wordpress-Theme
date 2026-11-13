// tweller-flow-2/public/js/booking.js

const tfBooking = {
    step: 1,
    selectedSessionTypeKey: null,
    selectedSessionData: null,
    selectedPackageKey: null,
    selectedPackageData: null,
    selectedDate: null,
    selectedTimeStr: null,
    selectedTimeDisplay: null,
    selectedBackdrop: '',
    selectedBackdropExtra: '',

    init() {
        if (!window.twellerBooking) return;
        const app = document.getElementById('tweller-booking-app');
        // A landing page that sells one kind of shoot hides the session list.
        this.locked = !!(app && app.dataset.lock === '1' && app.dataset.session);
        if (this.locked) app.classList.add('tf2-locked');
        this.renderSessionTypes();

        document.getElementById('tf2-booking-date').addEventListener('change', (e) => {
            this.selectedDate = e.target.value;
            this.selectedTimeStr = null;
            this.selectedTimeDisplay = null;
            this.fetchAvailability();
            this.saveState();
        });

        this.wireWhyModal();
        this.wirePersistence();
        this.syncConsentSelection();
        this.captureAttribution();
        this.restoreState();
        this.applyDeepLink();
        // A locked landing page counts as one ViewContent per visit, even when
        // a saved draft (not the deep link) is what opened it.
        if (this.locked && !this.viewSent) {
            const t = (window.twellerBooking.sessionTypes || {})[app.dataset.session];
            if (t) this.trackView(t);
        }
        this.wireGiftCard();
        if (new URLSearchParams(location.search).get('buy') === 'gift') this.openGift();
    },

    // ── Gift cards as a session-list option ────────────
    // Same app, same look: the "Gift card" tile swaps the session steps for
    // the gift steps (Gift / For / You / Pay), whose form and history live in
    // giftcards.js. The browser Back button returns here.

    giftMode: false,
    giftHeader: null,

    openGift() {
        if (!window.tweller_gc_app || !document.getElementById('tf2-step-gift')) return;
        this.enterGift();
        window.tweller_gc_app.open();
    },

    enterGift() {
        if (this.giftMode || !document.getElementById('tf2-step-gift')) return;
        this.giftMode = true;
        document.querySelectorAll('.tweller-booking__step').forEach((el) => { el.style.display = 'none'; });
        document.getElementById('tf2-step-gift').style.display = 'block';
        const resume = document.getElementById('tf2-resume-notice');
        if (resume) resume.style.display = 'none';

        const h = document.querySelector('.tweller-booking__header h2');
        const p = document.querySelector('.tweller-booking__header p');
        this.giftHeader = { h: h && h.textContent, p: p && p.textContent };
        if (h) h.textContent = 'Give a Gift';
        if (p) p.textContent = 'A session or an amount, for someone you love.';
        this.paintProgress(1);
    },

    exitGift() {
        if (!this.giftMode) return;
        this.giftMode = false;
        document.getElementById('tf2-step-gift').style.display = 'none';
        document.getElementById(`tf2-step-${this.step}`).style.display = 'block';
        if (this.map) setTimeout(() => this.map.invalidateSize(), 50);
        const h = document.querySelector('.tweller-booking__header h2');
        const p = document.querySelector('.tweller-booking__header p');
        if (this.giftHeader) {
            if (h) h.textContent = this.giftHeader.h;
            if (p) p.textContent = this.giftHeader.p;
        }
        this.paintProgress(this.step);
        const top = document.getElementById('tweller-booking-app');
        if (top && top.getBoundingClientRect().top < 0) top.scrollIntoView({ block: 'start' });
    },

    // Progress dots: everything before `step` done, `step` current.
    paintProgress(step) {
        document.querySelectorAll('.tf2-step').forEach((el) => {
            const n = parseInt(el.dataset.step, 10);
            el.classList.toggle('completed', n < step);
            el.classList.toggle('active', n === step);
        });
    },

    // ── Gift card ──────────────────────────────────────
    // Checked against the server as soon as it's entered so the summary can
    // show what's left to pay; the server re-checks and takes it at booking.

    gift: null,   // { code, balance, label }

    wireGiftCard() {
        const toggle = document.getElementById('tf2-gift-toggle');
        const body = document.getElementById('tf2-gift-body');
        const input = document.getElementById('tf2-gift-code');
        const apply = document.getElementById('tf2-gift-apply');
        if (!toggle || !body || !input || !apply) return;

        const open = () => { body.hidden = false; toggle.setAttribute('aria-expanded', 'true'); };
        toggle.addEventListener('click', () => {
            if (body.hidden) { open(); input.focus(); }
            else { body.hidden = true; toggle.setAttribute('aria-expanded', 'false'); }
        });
        apply.addEventListener('click', () => this.applyGiftCard(input.value));
        input.addEventListener('keydown', (e) => {
            if (e.key === 'Enter') { e.preventDefault(); this.applyGiftCard(input.value); }
        });

        // From the recipient's gift email (?gift=CODE), or a saved draft.
        const fromUrl = new URLSearchParams(location.search).get('gift');
        const saved = (this.readState() || {}).giftCode;
        const code = fromUrl || saved;
        if (code) { open(); input.value = code; this.applyGiftCard(code, true); }
    },

    async applyGiftCard(raw, quiet) {
        const msg = document.getElementById('tf2-gift-msg');
        const code = String(raw || '').trim();
        const say = (text, ok) => {
            msg.textContent = text;
            msg.hidden = !text;
            msg.className = 'tf2-gift__msg' + (ok ? ' tf2-gift__msg--ok' : ' tf2-gift__msg--bad');
        };
        if (!code) { this.gift = null; say(''); this.refreshSummary(); return; }

        say('Checking…', true);
        try {
            const res = await fetch(`${window.twellerBooking.restUrl}giftcards/check?code=${encodeURIComponent(code)}`);
            const data = await res.json();
            if (!res.ok || !data.ok) throw new Error(data.message || 'That code wasn\'t recognised.');
            this.gift = { code: data.code, balance: Number(data.balance) || 0, label: data.label };
            document.getElementById('tf2-gift-code').value = data.code;
            say(`✓ ${data.label} — TT$${this.gift.balance.toLocaleString()} available`, true);
        } catch (e) {
            this.gift = null;
            say(quiet ? '' : e.message, false);
        }
        this.saveState();
        this.refreshSummary();
    },

    refreshSummary() {
        if (this.step === 4 && this.selectedPackageData && this.selectedDate) this.renderSummary();
    },

    // ── Per-session links (/book-us/grads/, /book-us/grads/full/) ──
    // A link that names a session opens on it — and on its package when it
    // names one too — instead of the full menu.

    applyDeepLink() {
        const tb   = window.twellerBooking;
        const app  = document.getElementById('tweller-booking-app');
        const key  = (app && app.dataset.session) || tb.preselect || '';
        const pkgK = (app && app.dataset.package) || tb.preselectPackage || '';
        const type = key ? (tb.sessionTypes || {})[key] : null;
        if (!type || key === 'weddings') { this.syncUrl(); return; }

        const pkg = pkgK ? (tb.packages || {})[pkgK] : null;
        const allowed = type.allowed_packages || [];
        const pkgOk = pkg && (!allowed.length || allowed.includes(pkgK));

        // A saved draft for this same session (and package) is further along
        // than the link — keep the visitor's progress.
        if (this.selectedSessionTypeKey === key && (!pkgOk || this.selectedPackageKey === pkgK)) {
            this.syncUrl();
            return;
        }

        const notice = document.getElementById('tf2-resume-notice');
        if (notice) notice.style.display = 'none';
        this.selectSessionType(key, type);
        if (pkgOk) this.selectPackage(pkgK, pkg);
    },

    // Mirror the current pick in the address bar, so whatever is on screen
    // can be copied and sent as-is. replaceState, not pushState: the browser
    // Back button keeps leaving the page exactly as it always has.
    syncUrl() {
        const tb = window.twellerBooking;
        // A landing page keeps its own clean address (/christmas-photos/).
        if (this.locked) return;
        if (!tb.bookingUrl || !window.history || !history.replaceState) return;
        try {
            const base = new URL(tb.bookingUrl, location.href);
            // Only on the booking page itself — a landing page that embeds the
            // form keeps its own address.
            const basePath = base.pathname.replace(/\/+$/, '');
            if (location.pathname.replace(/\/+$/, '').indexOf(basePath) !== 0) return;

            const key = this.step >= 2 ? this.selectedSessionTypeKey : null;
            const pkg = this.step >= 3 ? this.selectedPackageKey : null;
            let target = base.href;
            if (key && pkg && tb.packageLinks && tb.packageLinks[key] && tb.packageLinks[key][pkg]) {
                target = tb.packageLinks[key][pkg];
            } else if (key && tb.links && tb.links[key]) {
                target = tb.links[key];
            }

            const next = new URL(target, location.href);
            // Carry ad parameters along; drop our own, which the target sets.
            new URLSearchParams(location.search).forEach((v, k) => {
                if (k === 'session' || k === 'package') return;
                if (!next.searchParams.has(k)) next.searchParams.set(k, v);
            });
            if (next.href !== location.href) history.replaceState(history.state, '', next.href);
        } catch (e) { /* never let the address bar break the booking */ }
    },

    // ── Ad / link attribution ──────────────────────────
    // Remember where the visitor came from (utm_*, fbclid…) so the booking
    // records which ad or shared link produced it — even if they browse away
    // and come back days later to finish. The latest ad click wins.

    attrKey() { return 'tf_booking_attr_v1'; },

    captureAttribution() {
        try {
            const q = new URLSearchParams(location.search);
            const keys = ['utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term', 'fbclid', 'gclid'];
            const fromAd = keys.some((k) => q.get(k));
            const saved = this.readAttribution();
            if (!fromAd && saved) return;

            const rec = { at: Date.now(), landing: location.href.split('#')[0] };
            keys.forEach((k) => { if (q.get(k)) rec[k] = q.get(k).slice(0, 200); });
            if (document.referrer) rec.referrer = document.referrer;
            const tb = window.twellerBooking;
            if (tb.preselect) rec.link = tb.preselect;
            localStorage.setItem(this.attrKey(), JSON.stringify(rec));
        } catch (e) {}
    },

    readAttribution() {
        try {
            const rec = JSON.parse(localStorage.getItem(this.attrKey()) || 'null');
            if (!rec || Date.now() - (rec.at || 0) > 30 * 24 * 60 * 60 * 1000) return null;
            return rec;
        } catch (e) { return null; }
    },

    // Meta Pixel / Google tag, only if the site has them installed.
    //
    // Every Meta event carries an eventID. With opts.relay the same ID goes
    // to /pixel, which sends the server copy (Conversions API) — Meta keeps
    // one. opts.eventID reuses an ID the server already knows (booking Lead).
    // Returns the ID used.
    trackEvent(name, params, opts) {
        opts = opts || {};
        const eventID = opts.eventID || this.newEventId(name);
        try { if (typeof window.fbq === 'function') window.fbq('track', name, params || {}, { eventID }); } catch (e) {}
        try { if (typeof window.gtag === 'function') window.gtag('event', name, params || {}); } catch (e) {}
        if (opts.relay) this.relayEvent(name, eventID, opts.relay);
        return eventID;
    },

    newEventId(name) {
        let rnd = '';
        try { rnd = crypto.randomUUID(); } catch (e) { rnd = Date.now().toString(36) + Math.random().toString(36).slice(2, 10); }
        return String(name).toLowerCase() + '-' + rnd;
    },

    relayEvent(name, eventID, extra) {
        try {
            const tb = window.twellerBooking;
            const attr = this.readAttribution() || {};
            const body = JSON.stringify(Object.assign({
                event: name,
                event_id: eventID,
                source_url: location.href.split('#')[0],
                fbclid: attr.fbclid || ''
            }, extra || {}));
            const url = tb.restUrl + 'pixel';
            // keepalive: a WhatsApp / Call tap navigates away immediately.
            fetch(url, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body, keepalive: true, credentials: 'same-origin' }).catch(() => {});
        } catch (e) {}
    },

    // At most once per visit (browser tab session) for each key.
    onceThisVisit(key) {
        try {
            const k = 'tf_px_once_' + key;
            if (sessionStorage.getItem(k)) return false;
            sessionStorage.setItem(k, '1');
        } catch (e) {}
        return true;
    },

    trackView(type) {
        this.viewSent = true;
        const params = { content_name: type.name, content_category: 'Session' };
        this.trackEvent('ViewContent', params, { relay: { content_name: type.name } });
    },

    // WhatsApp / Call taps — anywhere on the page. Lead (what the ads
    // optimise for) at most once per visit per method, plus Contact for
    // reporting. Exposed as window.tfTrackContact for the landing page.
    trackContact(method) {
        if (!this.onceThisVisit('lead_' + method)) return;
        const type = this.selectedSessionData ? this.selectedSessionData.name : '';
        const extra = { method, content_name: type };
        this.trackEvent('Lead', { content_name: type, method }, { relay: extra });
        this.trackEvent('Contact', { method }, { relay: extra });
    },

    // ── "Why is there a privacy fee?" ──────────────────
    // Read on the page. Sending someone to another tab mid-booking is how
    // you lose the booking.

    wireWhyModal() {
        const modal = document.getElementById('tf2-why-modal');
        const open  = document.getElementById('tf2-why-open');
        if (!modal || !open) return;

        /*
         * Move the dialog to <body>. position:fixed is resolved against the
         * nearest ancestor carrying a transform/filter/perspective, not the
         * viewport — and .tweller-booking__step keeps a transform after its
         * fadeIn animation settles (animation-fill-mode: forwards). That is
         * what pushed this dialog off-centre. Reparenting makes it immune to
         * that and to whatever the surrounding theme does.
         */
        if (modal.parentNode !== document.body) {
            document.body.appendChild(modal);
        }

        const show = () => {
            modal.style.display = 'flex';
            document.body.style.overflow = 'hidden';
            const x = document.getElementById('tf2-why-close');
            if (x) x.focus();
        };
        const hide = () => {
            modal.style.display = 'none';
            document.body.style.overflow = '';
            open.focus();
        };

        open.addEventListener('click', show);
        ['tf2-why-close', 'tf2-why-done', 'tf2-why-close-bg'].forEach((id) => {
            const el = document.getElementById(id);
            if (el) el.addEventListener('click', hide);
        });
        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape' && modal.style.display !== 'none') hide();
        });
    },

    /*
     * Mirror the checked radio onto a class. The card styling uses :has(),
     * which older Safari and Firefox ESR do not support — without this the
     * chosen option would give no visual feedback at all on those browsers.
     */
    syncConsentSelection() {
        const opts = document.querySelectorAll('.tf2-consent-opt');
        opts.forEach((opt) => {
            const input = opt.querySelector('input[type="radio"]');
            opt.classList.toggle('is-selected', !!(input && input.checked));
        });
    },

    // ── Resume an interrupted booking ──────────────────
    // Everything except the moment of submission is recoverable, so a phone
    // call, a closed tab or a stray back-button no longer costs the visitor
    // their whole booking.

    storeKey() {
        return 'tf_booking_draft_v1';
    },

    saveState() {
        try {
            const el = (id) => document.getElementById(id);
            const consent = document.querySelector('input[name="tf2_image_consent"]:checked');
            localStorage.setItem(this.storeKey(), JSON.stringify({
                v: 1,
                savedAt: Date.now(),
                step: this.step,
                sessionTypeKey: this.selectedSessionTypeKey,
                packageKey: this.selectedPackageKey,
                date: this.selectedDate,
                timeStr: this.selectedTimeStr,
                timeDisplay: this.selectedTimeDisplay,
                consent: consent ? consent.value : 'limited',
                giftCode: this.gift ? this.gift.code : '',
                name:  el('tf2-client-name')  ? el('tf2-client-name').value  : '',
                email: el('tf2-client-email') ? el('tf2-client-email').value : '',
                phone: el('tf2-client-phone') ? el('tf2-client-phone').value : '',
                notes: el('tf2-client-notes') ? el('tf2-client-notes').value : '',
                backdrop: this.selectedBackdrop,
                backdropExtra: this.selectedBackdropExtra,
                shootLocation: this.shootType(),
                shootPoint: this.shootPoint
            }));
        } catch (e) { /* private mode / quota — never block the booking */ }
    },

    clearState() {
        try { localStorage.removeItem(this.storeKey()); } catch (e) {}
    },

    readState() {
        try {
            const raw = localStorage.getItem(this.storeKey());
            if (!raw) return null;
            const s = JSON.parse(raw);
            if (!s || s.v !== 1) return null;
            // A fortnight-old draft is a stale price, not a convenience.
            if (Date.now() - (s.savedAt || 0) > 14 * 24 * 60 * 60 * 1000) return null;
            return s;
        } catch (e) { return null; }
    },

    // Save on every input, not just on step changes, so a half-typed form
    // survives too.
    wirePersistence() {
        ['tf2-client-name', 'tf2-client-email', 'tf2-client-phone', 'tf2-client-notes'].forEach((id) => {
            const el = document.getElementById(id);
            if (el) el.addEventListener('input', () => this.saveState());
        });
        document.querySelectorAll('input[name="tf2_image_consent"]').forEach((el) => {
            el.addEventListener('change', () => { this.syncConsentSelection(); this.saveState(); });
        });
        document.querySelectorAll('input[name="tf2_shoot_location"]').forEach((el) => {
            el.addEventListener('change', () => {
                this.showMapPicker(this.shootType() === 'location');
                if (this.step === 4 && this.selectedPackageData && this.selectedDate) this.renderSummary();
                this.saveState();
            });
        });
        this.wireMapPicker();
    },

    restoreState() {
        const s = this.readState();
        if (!s || !s.sessionTypeKey) return;

        const types = window.twellerBooking.sessionTypes || {};
        const pkgs  = window.twellerBooking.packages || {};
        const type  = types[s.sessionTypeKey];
        if (!type) { this.clearState(); return; }
        // A draft for a session with its own page resumes there, not here:
        // Book Us keeps showing its menu (no surprise redirect).
        if (this.landingFor(s.sessionTypeKey)) return;

        // Rebuild the chain step by step, stopping wherever the saved data no
        // longer holds up — a package that was withdrawn, a date now in the
        // past. Better to land them on the last valid step than to restore a
        // booking that cannot be completed.
        this.selectedSessionTypeKey = s.sessionTypeKey;
        this.selectedSessionData = type;
        this.selectedBackdrop = s.backdrop || '';
        this.selectedBackdropExtra = s.backdropExtra || '';
        this.renderPackages();
        this.renderBackdrops();
        let target = 2;

        const pkg = s.packageKey ? pkgs[s.packageKey] : null;
        if (pkg) {
            this.selectedPackageKey = s.packageKey;
            this.selectedPackageData = pkg;
            target = 3;
        }

        const today = new Date();
        today.setHours(0, 0, 0, 0);
        const stillFuture = s.date && new Date(s.date + 'T00:00:00') >= today;

        if (pkg && stillFuture) {
            this.selectedDate = s.date;
            const dateEl = document.getElementById('tf2-booking-date');
            if (dateEl) dateEl.value = s.date;

            if (s.timeStr && s.timeDisplay) {
                this.selectedTimeStr = s.timeStr;
                this.selectedTimeDisplay = s.timeDisplay;
                this.renderSummary();
                target = 4;
            }
            // Re-check the slot is genuinely still free; the grid re-renders
            // from the server's answer, not from what we saved.
            this.fetchAvailability();
        }

        // Restore the details form regardless of which step we land on.
        const set = (id, val) => { const el = document.getElementById(id); if (el && val) el.value = val; };
        set('tf2-client-name',  s.name);
        set('tf2-client-email', s.email);
        set('tf2-client-phone', s.phone);
        set('tf2-client-notes', s.notes);

        if (s.shootLocation) {
            const loc = document.querySelector('input[name="tf2_shoot_location"][value="' + s.shootLocation + '"]');
            if (loc) loc.checked = true;
            if (s.shootPoint && typeof s.shootPoint.lat === 'number') this.shootPoint = s.shootPoint;
            if (s.shootLocation === 'location') this.showMapPicker(true);
        }

        if (s.consent) {
            const radio = document.querySelector('input[name="tf2_image_consent"][value="' + s.consent + '"]');
            if (radio) radio.checked = true;
            this.syncConsentSelection();
        }

        this.goToStep(target);
        if (target === 4) this.renderSummary();
        this.showResumeNotice(target < 4 && s.step === 4);
    },

    showResumeNotice(downgraded) {
        const host = document.getElementById('tf2-resume-notice');
        if (!host) return;
        host.querySelector('.tf2-resume__text').textContent = downgraded
            ? 'We saved your booking, but that date or time is no longer available — please pick another.'
            : 'We picked up where you left off.';
        host.style.display = 'flex';

        const startOver = document.getElementById('tf2-resume-reset');
        if (startOver && !startOver.dataset.wired) {
            startOver.dataset.wired = '1';
            startOver.addEventListener('click', () => {
                this.clearState();
                window.location.reload();
            });
        }
    },

    // ── Shoot location: studio, or a pin on the map ─────
    // Home and location shoots are pinned on an OpenStreetMap map (search an
    // address, tap the map, or use the phone's location). A pin outside the
    // shaded central area adds the transport fee; the server re-checks it.

    shootPoint: null,   // { lat, lng, address, outside }
    map: null,
    marker: null,

    shootType() {
        const el = document.querySelector('input[name="tf2_shoot_location"]:checked');
        return el ? el.value : '';
    },

    centralArea() {
        try { return JSON.parse(document.getElementById('tf2-where').dataset.area || '[]'); } catch (e) { return []; }
    },

    inCentral(lat, lng) {
        const poly = this.centralArea();
        if (poly.length < 3) return true;
        let inside = false;
        for (let i = 0, j = poly.length - 1; i < poly.length; j = i++) {
            const [yi, xi] = poly[i], [yj, xj] = poly[j];
            if ((yi > lat) !== (yj > lat) && lng < (xj - xi) * (lat - yi) / ((yj - yi) || 1e-12) + xi) inside = !inside;
        }
        return inside;
    },

    mapStatus(text, kind) {
        const el = document.getElementById('tf2-map-status');
        if (!el) return;
        el.textContent = text || '';
        el.className = 'tf2-map__status' + (kind ? ' tf2-map__status--' + kind : '');
    },

    feeText() {
        const fee = parseFloat((document.getElementById('tf2-where') || {}).dataset.fee) || 0;
        return 'TT$' + fee.toLocaleString('en-US', { maximumFractionDigits: 2 });
    },

    paintPointStatus() {
        const p = this.shootPoint;
        if (!p) { this.mapStatus(''); return; }
        const where = p.address || 'Pinned location';
        this.mapStatus(p.outside
            ? `📍 ${where} — outside the central area: +${this.feeText()} transport.`
            : `📍 ${where} — in the central area, no transport fee.`, p.outside ? 'fee' : 'ok');
    },

    showMapPicker(show) {
        const wrap = document.getElementById('tf2-map-wrap');
        if (!wrap) return;
        wrap.hidden = !show;
        if (show) this.loadMap();
    },

    loadMap() {
        if (this.map || this.mapLoading) return;
        const box = document.getElementById('tf2-where');
        const start = () => this.initMap();
        if (window.L) { start(); return; }
        this.mapLoading = true;
        const css = document.createElement('link');
        css.rel = 'stylesheet'; css.href = box.dataset.leafletCss;
        document.head.appendChild(css);
        const js = document.createElement('script');
        js.src = box.dataset.leafletJs; js.async = true;
        js.onload = start;
        js.onerror = () => this.mapStatus('The map could not load. Please type the full address in the notes below.', 'error');
        document.head.appendChild(js);
    },

    initMap() {
        const el = document.getElementById('tf2-map');
        if (!el || this.map || !window.L) return;
        const L = window.L;
        const icons = (document.getElementById('tf2-where').dataset.leafletJs || '').replace(/leaflet\.js.*$/, 'images/');
        L.Icon.Default.imagePath = icons;
        this.map = L.map(el, { scrollWheelZoom: false, tap: true }).setView([10.52, -61.41], 10);
        L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', {
            maxZoom: 19,
            attribution: '&copy; <a href="https://www.openstreetmap.org/copyright" target="_blank" rel="noopener">OpenStreetMap</a>'
        }).addTo(this.map);
        const area = this.centralArea();
        if (area.length >= 3) {
            L.polygon(area, { color: '#173a2e', weight: 2, dashArray: '6 6', fillColor: '#2f7a55', fillOpacity: 0.12, interactive: false }).addTo(this.map);
        }
        this.map.on('click', (e) => this.setPoint(e.latlng.lat, e.latlng.lng, '', true));
        if (this.shootPoint) {
            this.setPoint(this.shootPoint.lat, this.shootPoint.lng, this.shootPoint.address, false);
            this.map.setView([this.shootPoint.lat, this.shootPoint.lng], 14);
        }
        setTimeout(() => this.map.invalidateSize(), 60);
    },

    setPoint(lat, lng, address, lookup) {
        const L = window.L;
        lat = Math.round(lat * 1e6) / 1e6; lng = Math.round(lng * 1e6) / 1e6;
        this.shootPoint = { lat, lng, address: address || '', outside: !this.inCentral(lat, lng) };
        if (this.map && L) {
            if (!this.marker) {
                this.marker = L.marker([lat, lng], { draggable: true, keyboard: true, title: 'Shoot location' }).addTo(this.map);
                this.marker.on('dragend', () => { const ll = this.marker.getLatLng(); this.setPoint(ll.lat, ll.lng, '', true); });
            } else {
                this.marker.setLatLng([lat, lng]);
            }
        }
        const wrap = document.getElementById('tf2-map-wrap');
        if (wrap) wrap.classList.remove('tf2-map--missing');
        this.paintPointStatus();
        if (this.step === 4 && this.selectedPackageData && this.selectedDate) this.renderSummary();
        this.saveState();
        if (lookup) this.reverseGeocode(lat, lng);
    },

    // OpenStreetMap Nominatim: search and reverse lookups happen only on a
    // deliberate action (Search / a tap / a drag), well inside its usage policy.
    async reverseGeocode(lat, lng) {
        const seq = (this.geoSeq = (this.geoSeq || 0) + 1);
        try {
            const r = await fetch(`https://nominatim.openstreetmap.org/reverse?format=jsonv2&zoom=17&addressdetails=0&lat=${lat}&lon=${lng}`, { headers: { 'Accept-Language': 'en' } });
            const d = await r.json();
            if (seq !== this.geoSeq || !this.shootPoint || this.shootPoint.lat !== lat) return;
            const name = (d.display_name || '').split(',').slice(0, 3).join(',').trim();
            if (name) { this.shootPoint.address = name; this.paintPointStatus(); this.saveState(); }
        } catch (e) { /* the pin is enough; the address is a nicety */ }
    },

    async searchAddress() {
        const q = (document.getElementById('tf2-map-q').value || '').trim();
        const list = document.getElementById('tf2-map-results');
        if (q.length < 3) return;
        this.loadMap();
        list.hidden = false;
        list.innerHTML = '<li class="tf2-map__hint">Searching…</li>';
        try {
            const r = await fetch(`https://nominatim.openstreetmap.org/search?format=jsonv2&countrycodes=tt&limit=5&q=${encodeURIComponent(q)}`, { headers: { 'Accept-Language': 'en' } });
            const rows = await r.json();
            if (!rows.length) { list.innerHTML = '<li class="tf2-map__hint">No match. Try a nearby landmark or area, or tap the map.</li>'; return; }
            list.innerHTML = '';
            rows.forEach((row) => {
                const li = document.createElement('li');
                const b = document.createElement('button');
                b.type = 'button';
                b.textContent = row.display_name.split(',').slice(0, 4).join(',');
                b.addEventListener('click', () => {
                    list.hidden = true;
                    const lat = parseFloat(row.lat), lng = parseFloat(row.lon);
                    if (this.map) this.map.setView([lat, lng], 15);
                    this.setPoint(lat, lng, row.display_name.split(',').slice(0, 3).join(',').trim(), false);
                });
                li.appendChild(b);
                list.appendChild(li);
            });
        } catch (e) {
            list.innerHTML = '<li class="tf2-map__hint">Search is unavailable right now. Tap the map instead.</li>';
        }
    },

    wireMapPicker() {
        const go = document.getElementById('tf2-map-go');
        const q = document.getElementById('tf2-map-q');
        const me = document.getElementById('tf2-map-me');
        if (go) go.addEventListener('click', () => this.searchAddress());
        if (q) q.addEventListener('keydown', (e) => { if (e.key === 'Enter') { e.preventDefault(); this.searchAddress(); } });
        if (me) me.addEventListener('click', () => {
            if (!navigator.geolocation) { this.mapStatus('Your browser can’t share its location. Search or tap the map instead.', 'error'); return; }
            this.mapStatus('Finding you…');
            navigator.geolocation.getCurrentPosition((pos) => {
                this.loadMap();
                const { latitude, longitude } = pos.coords;
                if (this.map) this.map.setView([latitude, longitude], 16);
                this.setPoint(latitude, longitude, '', true);
            }, () => this.mapStatus('Couldn’t get your location. Search or tap the map instead.', 'error'), { enableHighAccuracy: true, timeout: 10000 });
        });
    },

    // ── Backdrop picker ────────────────────────────────
    // Sessions with backdrops (Christmas scenes) ask "which one?" at the top
    // of the details step, as pictures. The choice goes on the booking so the
    // studio knows which scene to edit in.

    backdropConfig() {
        const all = window.twellerBooking.backdrops || {};
        const cfg = this.selectedSessionTypeKey ? all[this.selectedSessionTypeKey] : null;
        return cfg && cfg.choices && cfg.choices.length ? cfg : null;
    },

    renderBackdrops() {
        const box = document.getElementById('tf2-backdrop');
        if (!box) return;
        const cfg = this.backdropConfig();
        if (!cfg) { box.hidden = true; box.innerHTML = ''; this.selectedBackdrop = ''; this.selectedBackdropExtra = ''; return; }
        const keys = cfg.choices.map((c) => c.key);
        if (!keys.includes(this.selectedBackdrop)) this.selectedBackdrop = '';
        const esc = (t) => String(t).replace(/[&<>"]/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]));

        const tiles = cfg.choices.map((c) => {
            const art = c.thumb
                ? `<img src="${esc(c.thumb)}" alt="" loading="lazy" decoding="async">`
                : `<span class="tf2-bd__icon" aria-hidden="true">${c.kind === 'location'
                    ? '<svg viewBox="0 0 24 24"><path d="M12 22s7-6.2 7-12a7 7 0 1 0-14 0c0 5.8 7 12 7 12Z"/><circle cx="12" cy="10" r="2.5"/></svg>'
                    : '<svg viewBox="0 0 24 24"><path d="M3 11 12 4l9 7"/><path d="M5 10v10h14V10"/><path d="M12 13l-2.2 4h4.4L12 13Z"/></svg>'}</span>`;
            return `<label class="tf2-bd__opt${c.thumb ? '' : ' tf2-bd__opt--plain'}">
                <input type="radio" name="tf2_backdrop" value="${esc(c.key)}"${c.key === this.selectedBackdrop ? ' checked' : ''}>
                <span class="tf2-bd__art">${art}${c.kind === 'scene' ? '<span class="tf2-bd__badge">Digital</span>' : ''}<span class="tf2-bd__tick" aria-hidden="true"></span></span>
                <span class="tf2-bd__name">${esc(c.name)}</span>
                ${c.note ? `<span class="tf2-bd__note">${esc(c.note)}</span>` : ''}
            </label>`;
        }).join('');

        let extra = '';
        if (cfg.extra) {
            extra = `<div class="tf2-bd__extra"><span class="tf2-bd__extralabel" id="tf2-dd-label">${esc(cfg.extra_label || 'Your free extra scene')}</span>
                <details class="tf2-dd" id="tf2-dd"><summary aria-labelledby="tf2-dd-label" id="tf2-dd-sum"></summary>
                <ul class="tf2-dd__list" id="tf2-dd-list"></ul></details></div>`;
        }

        box.innerHTML = `<fieldset class="tf2-bd">
            <legend class="tf2-bd__title">${esc(cfg.title || 'Choose your backdrop')}</legend>
            ${cfg.intro ? `<p class="tf2-bd__intro">${esc(cfg.intro)}</p>` : ''}
            <div class="tf2-bd__grid">${tiles}</div>
            ${extra}
            <p class="tf2-bd__error" role="alert">Please choose a backdrop to continue.</p>
        </fieldset>`;
        box.hidden = false;

        box.querySelectorAll('input[name="tf2_backdrop"]').forEach((r) => r.addEventListener('change', () => {
            this.selectedBackdrop = r.value;
            if (this.selectedBackdropExtra === r.value) this.selectedBackdropExtra = '';
            box.classList.remove('tf2-backdrop--missing');
            this.paintExtra();
            this.renderChosen();
            if (this.step === 4 && this.selectedPackageData && this.selectedDate) this.renderSummary();
            this.saveState();
        }));
        this.paintExtra();
        this.renderChosen();
    },

    // The free extra scene: a thumbnail dropdown (never the scene already chosen).
    paintExtra() {
        const cfg = this.backdropConfig();
        const sum = document.getElementById('tf2-dd-sum'), list = document.getElementById('tf2-dd-list'), dd = document.getElementById('tf2-dd');
        if (!cfg || !sum || !list) return;
        const esc = (t) => String(t).replace(/[&<>"]/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]));
        const scenes = cfg.choices.filter((c) => c.thumb && c.key !== this.selectedBackdrop);
        if (!scenes.some((c) => c.key === this.selectedBackdropExtra)) this.selectedBackdropExtra = '';
        const cur = scenes.find((c) => c.key === this.selectedBackdropExtra);
        sum.innerHTML = cur
            ? `<img src="${esc(cur.thumb)}" alt=""><span>${esc(cur.name)}</span>`
            : `<span class="tf2-dd__ph" aria-hidden="true">+</span><span>Choose your free scene (or decide later)</span>`;
        list.innerHTML = `<li><button type="button" data-extra="" class="${cur ? '' : 'is-on'}"><span class="tf2-dd__ph" aria-hidden="true">–</span><span>Decide later</span></button></li>` +
            scenes.map((c) => `<li><button type="button" data-extra="${esc(c.key)}" class="${c.key === this.selectedBackdropExtra ? 'is-on' : ''}"><img src="${esc(c.thumb)}" alt="" loading="lazy"><span>${esc(c.name)}</span></button></li>`).join('');
        list.querySelectorAll('button').forEach((b) => b.addEventListener('click', () => {
            this.selectedBackdropExtra = b.getAttribute('data-extra') || '';
            this.paintExtra();
            this.renderChosen();
            if (this.step === 4 && this.selectedPackageData && this.selectedDate) this.renderSummary();
            if (dd) dd.open = false;
            this.saveState();
        }));
        if (dd && !dd.dataset.wired) {
            dd.dataset.wired = '1';
            document.addEventListener('click', (e) => { if (dd.open && !dd.contains(e.target)) dd.open = false; });
        }
    },

    // "Your backdrop" chip: shown next to the booking so the choice is always visible.
    renderChosen() {
        const el = document.getElementById('tf2-chosen');
        if (!el) return;
        const cfg = this.backdropConfig();
        const c = cfg ? cfg.choices.find((x) => x.key === this.selectedBackdrop) : null;
        if (!cfg) { el.hidden = true; el.innerHTML = ''; return; }
        const esc = (t) => String(t).replace(/[&<>"]/g, (ch) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[ch]));
        el.hidden = false;
        el.classList.toggle('is-empty', !c);
        if (!c) {
            el.innerHTML = `<span class="tf2-chosen__ph" aria-hidden="true">?</span><span class="tf2-chosen__txt"><span class="tf2-chosen__k">Your backdrop</span><b>None chosen yet</b></span><button type="button" class="tf2-chosen__chg" data-chg>Choose</button>`;
        } else {
            const x = cfg.choices.find((y) => y.key === this.selectedBackdropExtra);
            el.innerHTML = (c.thumb ? `<img src="${esc(c.thumb)}" alt="">` : `<span class="tf2-chosen__ph" aria-hidden="true">✓</span>`) +
                `<span class="tf2-chosen__txt"><span class="tf2-chosen__k">Your backdrop</span><b>${esc(c.name)}</b>${x ? `<span class="tf2-chosen__x">+ free scene: ${esc(x.name)}</span>` : ''}</span>` +
                `<button type="button" class="tf2-chosen__chg" data-chg>Change</button>`;
        }
        const btn = el.querySelector('[data-chg]');
        if (btn) btn.addEventListener('click', () => {
            // On the Christmas page the backdrop slider is the nicest place to pick; otherwise the picker in the form.
            const target = document.querySelector('.tw-scenes') || document.getElementById('tf2-backdrop');
            if (target) target.scrollIntoView({ behavior: 'smooth', block: 'start' });
        });
    },

    /** Pre-select a backdrop from outside the wizard (the landing page's scene viewer). */
    chooseBackdrop(key) {
        this.selectedBackdrop = key;
        if (this.selectedBackdropExtra === key) this.selectedBackdropExtra = '';
        const r = document.querySelector('input[name="tf2_backdrop"][value="' + key + '"]');
        if (r) { r.checked = true; document.getElementById('tf2-backdrop').classList.remove('tf2-backdrop--missing'); }
        this.paintExtra();
        this.renderChosen();
        this.saveState();
    },

    renderSessionTypes() {
        const container = document.getElementById('tf2-sessions-container');
        if (!container) return;
        
        container.innerHTML = '';
        const types = window.twellerBooking.sessionTypes;

        // Tiles are plain divs; make them reachable and operable by keyboard.
        const activatable = (el, fn) => {
            el.setAttribute('role', 'button');
            el.tabIndex = 0;
            el.onclick = fn;
            el.addEventListener('keydown', (e) => {
                if (e.target === el && (e.key === 'Enter' || e.key === ' ')) { e.preventDefault(); fn(); }
            });
        };

        for (const [key, type] of Object.entries(types)) {
            const el = document.createElement('div');
            el.className = 'tf2-package-card tf2-session-card';
            if (key === 'weddings') {
                el.innerHTML = `
                    <div class="tf2-pkg-title">${type.name}</div>
                    <div class="tf2-pkg-detail" style="margin-bottom:15px; color:var(--tf2-text);">${type.description}</div>
                    <a href="mailto:hello@twellerstudios.com?subject=Wedding Inquiry" class="tf2-btn-inquiry" onclick="event.stopPropagation()">Inquire Now</a>
                `;
                el.onclick = () => { window.location.href = 'mailto:hello@twellerstudios.com?subject=Wedding Inquiry'; };
            } else {
                el.innerHTML = `
                    <div class="tf2-pkg-title">${type.name}</div>
                    <div class="tf2-pkg-detail" style="color:var(--tf2-text);">${type.description}</div>
                `;
                activatable(el, () => this.selectSessionType(key, type));
            }
            container.appendChild(el);
        }

        const tb = window.twellerBooking;
        if (tb.gift && tb.gift.enabled && window.tweller_gc_app) {
            const el = document.createElement('div');
            el.className = 'tf2-package-card tf2-session-card tf2-session-card--gift';
            el.innerHTML = `
                <div class="tf2-pkg-title">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="8" width="18" height="4" rx="1"/><path d="M12 8v13M5 12v8a1 1 0 0 0 1 1h12a1 1 0 0 0 1-1v-8M7.5 8a2.5 2.5 0 1 1 0-5C10 3 12 8 12 8s2-5 4.5-5a2.5 2.5 0 1 1 0 5"/></svg>
                    <span>Gift Card</span>
                </div>
                <div class="tf2-pkg-detail">Give a session or an amount, emailed straight to them.</div>`;
            activatable(el, () => this.openGift());
            container.appendChild(el);
        }
    },

    // A session with its own landing page (Christmas) opens that page,
    // carrying the ad parameters along.
    landingFor(key) {
        if (this.locked) return '';
        const l = (window.twellerBooking.landings || {})[key];
        if (!l) return '';
        try {
            const u = new URL(l, location.href);
            if (u.pathname.replace(/\/+$/, '') === location.pathname.replace(/\/+$/, '')) return '';
            new URLSearchParams(location.search).forEach((v, k) => { if (k !== 'session' && k !== 'package' && !u.searchParams.has(k)) u.searchParams.set(k, v); });
            return u.href;
        } catch (e) { return l; }
    },

    selectSessionType(key, type) {
        if (key === 'weddings') return;
        const landing = this.landingFor(key);
        if (landing) { window.location.href = landing; return; }
        this.selectedSessionTypeKey = key;
        this.selectedSessionData = type;
        this.selectedPackageKey = null;
        this.selectedPackageData = null;
        this.renderPackages();
        this.renderBackdrops();
        this.goToStep(2);
        this.saveState();
        if (!this.locked || !this.viewSent) this.trackView(type);
    },

    renderPackages() {
        const container = document.getElementById('tf2-packages-container');
        if (!container) return;
        
        container.innerHTML = '';
        const pkgs = window.twellerBooking.packages;
        const allowed = this.selectedSessionData.allowed_packages || [];

        for (const [key, pkg] of Object.entries(pkgs)) {
            if (!allowed.includes(key) && allowed.length > 0) continue; // Show all if allowed empty/not set (fallback), else filter

            const el = document.createElement('div');
            el.className = 'tf2-package-card';
            
            // Badge from Offerings ("Top Booked", "Best Value"…); Mini keeps
            // its long-standing "Top Booked" when none is set.
            const badgeText = pkg.badge || (key === 'mini' ? 'Top Booked' : '');
            const esc = (t) => String(t).replace(/[&<>"]/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]));
            let topBooked = badgeText ? `<div class="tf2-top-booked">${esc(badgeText)}</div>` : '';
            if (badgeText) el.classList.add('tf2-package-card--badged');
            
            let html = `
                ${topBooked}
                <div class="tf2-pkg-title">${pkg.name}</div>
            `;
            
            const money = (n) => Number(n).toLocaleString('en-US', { maximumFractionDigits: 2 });
            if (Number(pkg.old_price)) {
                html += `<div class="tf2-pkg-price" style="display:flex; align-items:center; gap:8px;"><span style="text-decoration:line-through; font-size:16px; color:#A3A3A3; font-weight:500;">TTD ${money(pkg.old_price)}</span> <span>TTD ${money(pkg.price)}</span></div>`;
            } else {
                html += `<div class="tf2-pkg-price">TTD ${money(pkg.price)}</div>`;
            }
            
            if (pkg.features && Array.isArray(pkg.features)) {
                html += `<ul class="tf2-pkg-features">`;
                pkg.features.forEach(f => {
                    html += `<li><span class="tf2-checkmark"></span> ${f}</li>`;
                });
                html += `</ul>`;
            } else {
                html += `
                    <div class="tf2-pkg-detail">🕒 ${pkg.duration} Minutes</div>
                    <div class="tf2-pkg-detail">📸 ${pkg.images} Edited Images</div>
                    <div class="tf2-pkg-detail">👥 Up to ${pkg.members} People</div>
                `;
            }
            
            const cta = (document.getElementById('tweller-booking-app') || {}).dataset || {};
            if (cta.cardCta) html += `<span class="tf2-pkg-cta">${esc(cta.cardCta)}</span>`;

            el.innerHTML = html;
            el.setAttribute('role', 'button');
            el.tabIndex = 0;
            el.onclick = () => this.selectPackage(key, pkg);
            el.addEventListener('keydown', (e) => {
                if (e.target === el && (e.key === 'Enter' || e.key === ' ')) { e.preventDefault(); this.selectPackage(key, pkg); }
            });
            container.appendChild(el);
        }
    },

    selectPackage(key, pkg) {
        const changed = this.selectedPackageKey !== key;
        this.selectedPackageKey = key;
        this.selectedPackageData = pkg;
        this.goToStep(3);
        this.saveState();
        if (changed) {
            this.trackEvent('AddToCart', {
                content_name: pkg.name,
                content_ids: [key],
                content_type: 'product',
                value: Number(pkg.price) || 0,
                currency: 'TTD'
            }, { relay: { package: key } });
        }

        // if date already selected, refetch to apply correct duration
        if (this.selectedDate) {
            this.fetchAvailability();
        }
    },

    async fetchAvailability() {
        if (!this.selectedDate || !this.selectedPackageData) return;

        const slotsContainer = document.getElementById('tf2-slots-container');
        slotsContainer.innerHTML = '<p class="tf2-slots-empty">Checking availability...</p>';

        try {
            const res = await fetch(`${window.twellerBooking.restUrl}availability?date=${this.selectedDate}&duration=${this.selectedPackageData.duration}`);
            const data = await res.json();

            if (data.available_slots && data.available_slots.length > 0) {
                slotsContainer.innerHTML = '';
                data.available_slots.forEach(slot => {
                    const btn = document.createElement('div');
                    btn.className = 'tf2-slot-btn';
                    btn.innerText = slot.display;
                    btn.onclick = () => this.selectTime(slot.time, slot.display);
                    slotsContainer.appendChild(btn);
                });
            } else {
                slotsContainer.innerHTML = '<p class="tf2-slots-empty">No slots available for this date. Please try another date.</p>';
            }
        } catch (err) {
            slotsContainer.innerHTML = '<p class="tf2-slots-empty" style="color:red">Failed to load availability.</p>';
        }
    },

    selectTime(timeStr, displayStr) {
        this.selectedTimeStr = timeStr;
        this.selectedTimeDisplay = displayStr;
        this.renderSummary();
        this.goToStep(4);
        this.saveState();
    },

    renderSummary() {
        const summary = document.getElementById('tf2-summary-card');
        const d = new Date(this.selectedDate + 'T' + this.selectedTimeStr);
        const options = { weekday:'long', year:'numeric', month:'long', day:'numeric' };

        const base = parseFloat(String(this.selectedPackageData.price).replace(/[^0-9.]/g, '')) || 0;
        const cfg = document.getElementById('tf2-consent-cfg');
        const fee = cfg ? (parseFloat(cfg.dataset.fee) || 0) : 0;
        const choiceEl = document.querySelector('input[name="tf2_image_consent"]:checked');
        const choice = choiceEl ? choiceEl.value : 'limited';

        const fmt = (n) => n.toLocaleString(undefined, { maximumFractionDigits: 2 });
        let lines = '';
        let total = base;
        if (choice === 'declined' && fee > 0) {
            total = base + fee;
            lines = `
                <div style="display:flex; justify-content:space-between; opacity:0.9; font-size:14px; margin-top:8px;"><span>Session</span><span>TTD ${fmt(base)}</span></div>
                <div style="display:flex; justify-content:space-between; opacity:0.9; font-size:14px;"><span>Privacy fee</span><span>TTD ${fmt(fee)}</span></div>`;
        } else if (choice === 'unlimited') {
            lines = `<div style="font-size:12.5px; color:#C9A227; margin-top:8px;">✓ You'll earn a discount on your next session.</div>`;
        }

        // Transport fee: shoots away from the studio, outside the central area.
        const where = document.getElementById('tf2-where');
        const transport = where && this.shootType() === 'location' && this.shootPoint && this.shootPoint.outside
            ? (parseFloat(where.dataset.fee) || 0) : 0;
        if (transport > 0) {
            if (!lines.includes('Session')) {
                lines = `<div style="display:flex; justify-content:space-between; opacity:0.9; font-size:14px; margin-top:8px;"><span>Session</span><span>TTD ${fmt(total)}</span></div>` + lines;
            }
            lines += `<div style="display:flex; justify-content:space-between; opacity:0.9; font-size:14px;"><span>Transport (outside central area)</span><span>TTD ${fmt(transport)}</span></div>`;
            total += transport;
        }

        let giftLines = '';
        if (this.gift && this.gift.balance > 0) {
            const use = Math.min(this.gift.balance, total);
            giftLines = `
                <div style="display:flex; justify-content:space-between; font-size:14px; margin-top:8px; color:#C9A227;"><span>Gift card ${this.gift.code}</span><span>− TTD ${fmt(use)}</span></div>
                <div style="margin-top: 6px; font-weight:700;">To pay: TTD ${fmt(total - use)}</div>`;
        }

        const bcfg = this.backdropConfig();
        const bch = bcfg ? bcfg.choices.find((x) => x.key === this.selectedBackdrop) : null;
        const bex = bcfg ? bcfg.choices.find((x) => x.key === this.selectedBackdropExtra) : null;
        const bline = bch ? `<div class="tf2-summary-bd">${bch.thumb ? `<img src="${bch.thumb}" alt="">` : ''}<span>Backdrop: <b>${bch.name}</b>${bex ? ` · free scene: <b>${bex.name}</b>` : ''}</span></div>` : '';
        summary.innerHTML = `
            <h4>${this.selectedPackageData.name}</h4>
            <div class="tf2-summary-detail">${d.toLocaleDateString(undefined, options)} at ${this.selectedTimeDisplay}</div>
            ${bline}
            ${lines}
            <div style="margin-top: 10px; opacity: 0.95; font-weight:600;">Total: TTD ${fmt(total)}</div>
            ${giftLines}
        `;
    },

    goToStep(num) {
        document.getElementById(`tf2-step-${this.step}`).style.display = 'none';
        document.querySelector(`.tf2-step[data-step="${this.step}"]`).classList.remove('active');
        document.querySelector(`.tf2-step[data-step="${this.step}"]`).classList.add('completed');
        
        this.step = num;
        this.renderStepContext();
        this.syncUrl();
        
        document.getElementById(`tf2-step-${this.step}`).style.display = 'block';
        document.querySelector(`.tf2-step[data-step="${this.step}"]`).classList.add('active');
        document.querySelector(`.tf2-step[data-step="${this.step}"]`).classList.remove('completed');

        // Reset later steps
        for(let i = this.step + 1; i <= 4; i++) {
            let el = document.querySelector(`.tf2-step[data-step="${i}"]`);
            if(el) {
                el.classList.remove('active');
                el.classList.remove('completed');
            }
        }
    },

    // Which session the visitor is booking, above the package and date
    // steps. The package cards (Mini / Full / Extended) are shared by every
    // session type, so without this nothing on screen says whether this is
    // a Grad shoot, 1-year photos, etc.
    renderStepContext() {
        const session = this.selectedSessionData ? this.selectedSessionData.name : '';
        const pkg = this.selectedPackageData ? this.selectedPackageData.name.split('—')[0].trim() : '';
        const labels = { 2: session, 3: [session, pkg].filter(Boolean).join(' · ') };
        for (const n of [2, 3]) {
            const el = document.getElementById(`tf2-step-${n}-context`);
            if (!el) continue;
            el.textContent = labels[n];
            el.style.display = labels[n] ? '' : 'none';
        }
    },

    prevStep() {
        if (this.locked && this.step <= 2) return;
        if (this.step > 1) {
            this.goToStep(this.step - 1);
        }
    },

    buildInquiryMessage() {
        const type = this.selectedSessionData ? this.selectedSessionData.name : '';
        const pkg = this.selectedPackageData ? this.selectedPackageData.name.split('—')[0].trim() : '';
        const loc = document.getElementById('tf2-client-notes').value || 'Not specified';
        
        let dateStr = this.selectedDate;
        if (this.selectedDate) {
            const d = new Date(this.selectedDate + 'T' + this.selectedTimeStr);
            const options = { weekday:'long', year:'numeric', month:'long', day:'numeric' };
            dateStr = d.toLocaleDateString(undefined, options);
        }

        return `Hi good day, can you confirm availability for an upcoming session?\n\nSession Type: ${type}\nPackage: ${pkg}\nDate: ${dateStr}\nTime: ${this.selectedTimeDisplay || ''}\nLocation: ${loc}`;
    },

    sendWhatsApp() {
        this.trackContact('whatsapp');
        const msg = encodeURIComponent(this.buildInquiryMessage());
        // User requested number: 18683422948
        window.open(`https://wa.me/18683422948?text=${msg}`, '_blank');
    },

    sendEmail() {
        const msg = encodeURIComponent(this.buildInquiryMessage());
        window.open(`mailto:hello@twellerstudios.com?subject=Availability Inquiry&body=${msg}`, '_blank');
    },

    // The reassuring "booking you in" screen between the button and the payment page.
    startLoader() {
        const el = document.getElementById('tf2-booking-loading');
        const t0 = Date.now();
        const steps = el ? Array.from(el.querySelectorAll('[data-ld-step]')) : [];
        const bar = el ? el.querySelector('.tf2-ld__bar i') : null;
        let i = 0, timer = null;
        const show = (n) => {
            steps.forEach((s2, k) => { s2.classList.toggle('is-on', k === n); s2.classList.toggle('is-done', k < n); });
            if (bar) bar.style.width = (Math.min(n, steps.length - 1) / (steps.length - 1) * 88 + 8) + '%';
        };
        if (el) {
            el.classList.remove('is-ok');
            el.style.display = 'flex';
            document.documentElement.classList.add('tf2-ld-lock');
            requestAnimationFrame(() => el.classList.add('is-in'));
            show(0);
            timer = setInterval(() => { if (i < steps.length - 2) { i++; show(i); } }, 1300);
        }
        const done = () => { clearInterval(timer); document.documentElement.classList.remove('tf2-ld-lock'); };
        return {
            // Never flash: at least ~2.2 s on screen so each message can be read.
            minimum: () => new Promise((r) => setTimeout(r, Math.max(0, 2200 - (Date.now() - t0)))),
            success: () => { clearInterval(timer); if (el) { el.classList.add('is-ok'); show(steps.length - 1); if (bar) bar.style.width = '100%'; } },
            stop: () => { done(); if (el) { el.classList.remove('is-in', 'is-ok'); el.style.display = 'none'; } }
        };
    },

    async submitBooking() {
        const name = document.getElementById('tf2-client-name').value;
        const email = document.getElementById('tf2-client-email').value;
        const phone = document.getElementById('tf2-client-phone').value;
        const notes = document.getElementById('tf2-client-notes').value;
        
        const payload = {
            client_name: name,
            client_email: email,
            client_phone: phone,
            location: notes,
            notes: notes,
            session_type: this.selectedSessionTypeKey,
            package_type: this.selectedPackageKey,
            session_date: this.selectedDate,
            session_time: this.selectedTimeStr,
            image_consent: (document.querySelector('input[name="tf2_image_consent"]:checked') || {}).value || 'limited',
            attribution: this.readAttribution() || {},
            gift_code: this.gift ? this.gift.code : '',
            backdrop: this.selectedBackdrop,
            backdrop_extra: this.selectedBackdropExtra,
            shoot_location: this.shootType(),
            shoot_lat: this.shootPoint ? this.shootPoint.lat : '',
            shoot_lng: this.shootPoint ? this.shootPoint.lng : '',
            shoot_address: this.shootPoint ? this.shootPoint.address : ''
        };
        // A home / location shoot needs a pin on the map.
        if (this.shootType() === 'location' && !this.shootPoint) {
            const wrap = document.getElementById('tf2-map-wrap');
            if (wrap) {
                wrap.classList.add('tf2-map--missing');
                this.mapStatus('Please search for the address or tap the map to drop a pin.', 'error');
                wrap.scrollIntoView({ behavior: 'smooth', block: 'center' });
            }
            return;
        }
        // A session with a backdrop picker needs a choice before booking.
        if (this.backdropConfig() && !this.selectedBackdrop) {
            const box = document.getElementById('tf2-backdrop');
            if (box) {
                box.classList.add('tf2-backdrop--missing');
                box.scrollIntoView({ behavior: 'smooth', block: 'start' });
                const first = box.querySelector('input[name="tf2_backdrop"]');
                if (first) first.focus({ preventScroll: true });
            }
            return;
        }
        // One Lead per visit for the form: the server sends its copy with
        // this ID (POST /book), the browser fires with it on success.
        let leadId = '';
        try { if (!sessionStorage.getItem('tf_px_once_lead_form')) leadId = this.newEventId('lead'); } catch (e) { leadId = this.newEventId('lead'); }
        if (leadId) payload.event_id = leadId;

        const loading = document.getElementById('tf2-booking-loading');
        const errorMsg = document.getElementById('tf2-booking-error');
        const submitBtn = document.getElementById('tf2-submit-btn');

        // Read the label off the DOM rather than hard-coding it, so the
        // button always restores to whatever the shortcode rendered —
        // "Reserve Booking · Continue to Payment" today, anything later.
        const submitLabel = submitBtn ? submitBtn.textContent : '';
        const restoreSubmit = () => {
            if (!submitBtn) return;
            submitBtn.disabled = false;
            submitBtn.textContent = submitLabel;
        };
        if (submitBtn) {
            submitBtn.disabled = true;
            submitBtn.textContent = 'Reserving your session…';
        }

        const seq = this.startLoader();
        errorMsg.style.display = 'none';

        try {
            const res = await fetch(`${window.twellerBooking.restUrl}book`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload)
            });

            const data = await res.json();
            await seq.minimum();
            
            if (data.success) {
                this.clearState();
                try { localStorage.removeItem(this.attrKey()); } catch (e) {}
                if (leadId && this.onceThisVisit('lead_form')) {
                    this.trackEvent('Lead', {
                        content_name: (this.selectedSessionData ? this.selectedSessionData.name : '') +
                            (this.selectedPackageData ? ' — ' + this.selectedPackageData.name : ''),
                        value: this.selectedPackageData ? Number(this.selectedPackageData.price) || 0 : 0,
                        currency: 'TTD',
                        method: 'booking_form'
                    }, { eventID: leadId });
                }
                // Redirect to tracker page!
                let tUrl = window.twellerBooking.trackerUrl;
                let sep = tUrl.includes('?') ? '&' : '?';
                // Show "taking you to payment" for a beat (the pixel's Lead request leaves meanwhile).
                seq.success();
                setTimeout(() => { window.location.href = tUrl + sep + 'code=' + data.tracking_code; }, 1500);
            } else {
                seq.stop();
                restoreSubmit();
                errorMsg.innerText = data.message || 'An error occurred. Please try again.';
                errorMsg.style.display = 'block';
            }
        } catch (err) {
            seq.stop();
            restoreSubmit();
            errorMsg.innerText = 'Network error. Please try again.';
            errorMsg.style.display = 'block';
        }
    }
};

// From giftcards.js: the gift flow asks the page to follow it in and out
// (browser Back/Forward, a reload on the payment step), and reports its step.
document.addEventListener('tgc:enter', () => tfBooking.enterGift());
document.addEventListener('tgc:exit', () => tfBooking.exitGift());
document.addEventListener('tgc:step', (e) => { if (tfBooking.giftMode) tfBooking.paintProgress(e.detail.step); });

document.addEventListener('DOMContentLoaded', () => {
    tfBooking.init();
});

// For pages around the wizard (the Christmas landing page's WhatsApp / Call
// buttons): same once-per-visit Lead + Contact as the wizard's own button.
window.tfTrackContact = (method) => tfBooking.trackContact(method);
window.tfBooking = tfBooking;
