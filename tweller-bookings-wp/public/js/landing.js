/* Christmas landing page — hero crossfade, scene viewer, countdown,
   sticky bar, and WhatsApp / Call tracking. No dependencies. */
(function () {
    'use strict';

    var reduce = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    var $ = function (sel, root) { return (root || document).querySelector(sel); };
    var $$ = function (sel, root) { return Array.prototype.slice.call((root || document).querySelectorAll(sel)); };

    // ── Smooth scroll to the packages ─────────────────
    function goToPackages(e) {
        var target = document.getElementById('packages');
        if (!target) return;
        if (e) e.preventDefault();
        target.scrollIntoView({ behavior: reduce ? 'auto' : 'smooth', block: 'start' });
        try { target.focus({ preventScroll: true }); } catch (x) {}
        if (history.replaceState) history.replaceState(history.state, '', location.pathname + location.search + '#packages');
    }
    $$('[data-scroll]').forEach(function (a) { a.addEventListener('click', goToPackages); });

    // ── WhatsApp / Call: Lead + Contact, once per visit per method ─────
    $$('[data-contact]').forEach(function (a) {
        a.addEventListener('click', function () {
            var method = a.getAttribute('data-contact');
            if (typeof window.tfTrackContact === 'function') window.tfTrackContact(method);
        });
    });

    // ── Hero: load the other scenes after the page, then crossfade ─────
    var hero = $('[data-hero]');
    if (hero) {
        var slides = $$('.tw-hero__slide', hero);
        var nameEl = $('[data-hero-name]');
        var loadRest = function () {
            slides.slice(1).forEach(function (sl) {
                $$('[data-srcset]', sl).forEach(function (el) { el.srcset = el.getAttribute('data-srcset'); el.removeAttribute('data-srcset'); });
                $$('[data-src]', sl).forEach(function (el) { el.src = el.getAttribute('data-src'); el.removeAttribute('data-src'); });
            });
        };
        if (slides.length > 1 && !reduce) {
            var idx = 0, timer = null;
            var show = function (n) {
                var next = slides[n];
                var img = $('img', next);
                // Only fade to a slide whose image has arrived.
                if (img && !(img.complete && img.naturalWidth)) return false;
                slides[idx].classList.remove('is-active');
                next.classList.add('is-active');
                idx = n;
                if (nameEl) nameEl.textContent = next.getAttribute('data-name') || '';
                return true;
            };
            var tick = function () {
                if (document.hidden) return;
                var n = (idx + 1) % slides.length;
                for (var tries = 0; tries < slides.length - 1; tries++) {
                    if (show(n)) return;
                    n = (n + 1) % slides.length;
                }
            };
            var start = function () {
                loadRest();
                timer = setInterval(tick, 5500);
            };
            if (document.readyState === 'complete') setTimeout(start, 1200);
            else window.addEventListener('load', function () { setTimeout(start, 1200); });
        }
    }

    // Lazy images elsewhere (final call-to-action background).
    var lazy = $$('.tw-final [data-src], .tw-final [data-srcset]');
    if (lazy.length) {
        var swap = function () {
            lazy.forEach(function (el) {
                if (el.hasAttribute('data-srcset')) { el.srcset = el.getAttribute('data-srcset'); el.removeAttribute('data-srcset'); }
                if (el.hasAttribute('data-src')) { el.src = el.getAttribute('data-src'); el.removeAttribute('data-src'); }
            });
        };
        if ('IntersectionObserver' in window) {
            var lio = new IntersectionObserver(function (ents) {
                if (ents.some(function (e) { return e.isIntersecting; })) { swap(); lio.disconnect(); }
            }, { rootMargin: '600px 0px' });
            lio.observe($('.tw-final'));
        } else { swap(); }
    }

    // ── Sticky bar: after the hero, hidden while the booking form is on screen ──
    var bar = $('[data-sticky]');
    var heroSec = $('.tw-hero');
    var pk = document.getElementById('packages');
    if (bar && heroSec && 'IntersectionObserver' in window) {
        var pastHero = false, inPk = false;
        var paint = function () {
            var on = pastHero && !inPk;
            bar.classList.toggle('is-on', on);
            if (on) bar.removeAttribute('hidden');
        };
        new IntersectionObserver(function (e) { pastHero = !e[0].isIntersecting; paint(); }, { threshold: 0 }).observe(heroSec);
        // "In the packages" = the booking section crosses the middle of the screen.
        if (pk) new IntersectionObserver(function (e) { inPk = e[0].isIntersecting; paint(); }, { rootMargin: '-45% 0px -45% 0px' }).observe(pk);
    }

    // ── Early-bird countdown ─────────────────────────
    var cd = $('[data-countdown]');
    if (cd) {
        var end = Date.parse(cd.getAttribute('data-countdown'));
        var parts = { d: $('[data-cd="d"]', cd), h: $('[data-cd="h"]', cd), m: $('[data-cd="m"]', cd), s: $('[data-cd="s"]', cd) };
        var pad = function (n) { return (n < 10 ? '0' : '') + n; };
        var run = function () {
            var left = Math.max(0, Math.floor((end - Date.now()) / 1000));
            if (!left) { cd.hidden = true; return false; }
            parts.d.textContent = Math.floor(left / 86400);
            parts.h.textContent = pad(Math.floor(left % 86400 / 3600));
            parts.m.textContent = pad(Math.floor(left % 3600 / 60));
            parts.s.textContent = pad(left % 60);
            return true;
        };
        if (!isNaN(end) && run()) {
            var cdTimer = setInterval(function () { if (!run()) clearInterval(cdTimer); }, 1000);
        }
    }


    // ── Backdrop slider: swipe (native scroll-snap), arrows, thumbnails, autoplay ──
    var slider = $('[data-slider]');
    if (slider) {
        var track = $('[data-track]', slider);
        var slides = $$('.tw-slide', slider);
        var thumbs = $$('[data-go]', slider);
        var cur = 0, auto = null, userTouched = false;
        var setW = function () { slider.style.setProperty('--sw', slider.clientWidth + 'px'); };
        setW(); window.addEventListener('resize', setW);
        var go = function (i, smooth) {
            cur = (i + slides.length) % slides.length;
            track.scrollTo({ left: slides[cur].offsetLeft - track.offsetLeft, behavior: smooth === false || reduce ? 'auto' : 'smooth' });
        };
        var paint = function () {
            var i = Math.round(track.scrollLeft / Math.max(1, track.clientWidth));
            cur = Math.max(0, Math.min(slides.length - 1, i));
            thumbs.forEach(function (t, k) { t.classList.toggle('is-active', k === cur); t.setAttribute('aria-selected', k === cur ? 'true' : 'false'); });
        };
        var stop = function () { userTouched = true; if (auto) { clearInterval(auto); auto = null; } };
        track.addEventListener('scroll', function () { window.requestAnimationFrame(paint); }, { passive: true });
        ['pointerdown', 'wheel', 'touchstart', 'keydown'].forEach(function (ev) { track.addEventListener(ev, stop, { passive: true }); });
        var prev = $('[data-prev]', slider), next = $('[data-next]', slider);
        if (prev) prev.addEventListener('click', function () { stop(); go(cur - 1); });
        if (next) next.addEventListener('click', function () { stop(); go(cur + 1); });
        thumbs.forEach(function (t) { t.addEventListener('click', function () { stop(); go(parseInt(t.getAttribute('data-go'), 10) || 0); }); });
        track.addEventListener('keydown', function (e) {
            if (e.key === 'ArrowRight') { e.preventDefault(); go(cur + 1); }
            if (e.key === 'ArrowLeft') { e.preventDefault(); go(cur - 1); }
        });
        // Gentle autoplay while the slider is on screen, until someone touches it.
        if (!reduce && 'IntersectionObserver' in window) {
            new IntersectionObserver(function (ents) {
                var vis = ents[0].isIntersecting;
                if (vis && !auto && !userTouched) auto = setInterval(function () { if (!document.hidden) go(cur + 1); }, 4500);
                if (!vis && auto) { clearInterval(auto); auto = null; }
            }, { threshold: 0.5 }).observe(slider);
        }
        // "Choose this backdrop": pre-select it in the booking form, then go to packages.
        $$('[data-pick]', slider).forEach(function (b) {
            b.addEventListener('click', function () {
                try { if (window.tfBooking) window.tfBooking.chooseBackdrop(b.getAttribute('data-pick')); } catch (x) {}
                goToPackages();
            });
        });
    }

    // ── Before & after: drag to compare, tabs between examples ──
    $$('[data-compare]').forEach(function (box) {
        var range = $('input[type="range"]', box);
        var dragging = false;
        var set = function (pct) {
            pct = Math.max(0, Math.min(100, pct));
            box.style.setProperty('--pos', pct + '%');
            if (range) range.value = Math.round(pct);
        };
        var fromEvent = function (e) {
            var r = box.getBoundingClientRect();
            set(((e.clientX - r.left) / r.width) * 100);
        };
        box.addEventListener('pointerdown', function (e) {
            dragging = true; box.hinted = true;
            try { box.setPointerCapture(e.pointerId); } catch (x) {}
            fromEvent(e);
        });
        box.addEventListener('pointermove', function (e) { if (dragging) fromEvent(e); });
        ['pointerup', 'pointercancel', 'lostpointercapture'].forEach(function (ev) { box.addEventListener(ev, function () { dragging = false; }); });
        box.addEventListener('click', function () { if (range) range.focus({ preventScroll: true }); });
        if (range) range.addEventListener('input', function () { box.hinted = true; set(parseFloat(range.value)); });
        // A small sweep the first time it scrolls into view, so people see it moves.
        if (!reduce && 'IntersectionObserver' in window) {
            var io = new IntersectionObserver(function (ents) {
                if (!ents[0].isIntersecting || box.hinted) return;
                box.hinted = true; io.disconnect();
                var t0 = null;
                var step = function (t) {
                    if (!t0) t0 = t;
                    var k = Math.min(1, (t - t0) / 1600);
                    set(50 + Math.sin(k * Math.PI * 2) * 18 * (1 - k * 0.2));
                    if (k < 1 && !dragging) requestAnimationFrame(step); else if (!dragging) set(50);
                };
                requestAnimationFrame(step);
            }, { threshold: 0.6 });
            io.observe(box);
        }
    });
    var tabs = $$('[data-ba-tab]');
    tabs.forEach(function (t) {
        t.addEventListener('click', function () {
            var n = t.getAttribute('data-ba-tab');
            tabs.forEach(function (o) { var on = o === t; o.classList.toggle('is-active', on); o.setAttribute('aria-selected', on ? 'true' : 'false'); });
            $$('[data-ba-panel]').forEach(function (p) { p.hidden = p.getAttribute('data-ba-panel') !== n; });
        });
    });

    // ── Scene viewer ─────────────────────────────────
    var viewer = $('[data-viewer]');
    var dataEl = document.getElementById('tw-scenes-data');
    var scenes = [];
    try { scenes = JSON.parse(dataEl ? dataEl.textContent : '[]'); } catch (x) {}
    if (viewer && scenes.length && typeof viewer.showModal === 'function') {
        var vImg = $('[data-viewer-img]', viewer), vName = $('[data-viewer-name]', viewer), cur = 0, opener = null;
        var paintV = function () {
            vImg.src = scenes[cur].src;
            vImg.alt = scenes[cur].name + ' Christmas scene';
            vName.textContent = scenes[cur].name;
        };
        var openV = function (i, btn) { cur = i; opener = btn; paintV(); viewer.showModal(); };
        var step = function (d) { cur = (cur + d + scenes.length) % scenes.length; paintV(); };

        $$('[data-scene]').forEach(function (btn) {
            btn.addEventListener('click', function () { openV(parseInt(btn.getAttribute('data-scene'), 10) || 0, btn); });
        });
        $('[data-viewer-prev]', viewer).addEventListener('click', function () { step(-1); });
        $('[data-viewer-next]', viewer).addEventListener('click', function () { step(1); });
        $('[data-viewer-close]', viewer).addEventListener('click', function () { viewer.close(); });
        viewer.addEventListener('close', function () { if (opener) opener.focus(); });
        viewer.addEventListener('keydown', function (e) {
            if (e.key === 'ArrowLeft') step(-1);
            if (e.key === 'ArrowRight') step(1);
        });
        // Swipe between scenes.
        var x0 = null;
        viewer.addEventListener('touchstart', function (e) { x0 = e.touches[0].clientX; }, { passive: true });
        viewer.addEventListener('touchend', function (e) {
            if (x0 === null) return;
            var dx = e.changedTouches[0].clientX - x0;
            if (Math.abs(dx) > 50) step(dx < 0 ? 1 : -1);
            x0 = null;
        });
        // "Book this scene": pre-select it in the booking form's backdrop picker, then go to packages.
        $('[data-viewer-book]', viewer).addEventListener('click', function () {
            try { if (window.tfBooking) window.tfBooking.chooseBackdrop(scenes[cur].key); } catch (x) {}
            viewer.close();
            goToPackages();
        });
    }
})();
