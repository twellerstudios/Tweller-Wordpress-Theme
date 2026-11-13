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
        // "Book this scene": note the scene on the booking, then go to packages.
        $('[data-viewer-book]', viewer).addEventListener('click', function () {
            var notes = document.getElementById('tf2-client-notes');
            var line = 'Scene: ' + scenes[cur].name;
            if (notes) {
                var v = notes.value.replace(/^Scene: .*(\n|$)/, '');
                notes.value = line + (v ? '\n' + v : '');
                try { if (window.tfBooking) window.tfBooking.saveState(); } catch (x) {}
            }
            viewer.close();
            goToPackages();
        });
    }
})();
