/**
 * Tweller Flow — Client Culling Portal
 * Clients view proof images and select which to retouch.
 */
(function() {
    'use strict';

    var portal = document.querySelector('.tc-portal[data-code]');
    if (!portal || typeof twellerCulling === 'undefined') return;

    var code = portal.getAttribute('data-code');
    var apiUrl = twellerCulling.apiUrl;
    var nonce = twellerCulling.nonce;

    var token = sessionStorage.getItem('tc_token_' + code) || '';
    var proofs = [];
    var selectedIds = new Set();
    var maxFree = 0;
    var includedImages = 0;
    var freebies = 0;
    var upsellPricePerPhoto = 30;
    var submitted = false;
    var bd = null;          // backdrops this client booked (see TwellerFlow2_Backdrop_Edits::portal_data)
    var bdAssign = {};      // proof id -> backdrop key

    // Elements
    var loading     = document.getElementById('tc-loading');
    var pwSection   = document.getElementById('tc-password');
    var pwForm      = document.getElementById('tc-pw-form');
    var pwInput     = document.getElementById('tc-pw-input');
    var pwError     = document.getElementById('tc-pw-error');
    var notReady    = document.getElementById('tc-not-ready');
    var submittedEl = document.getElementById('tc-submitted');
    var counter     = document.getElementById('tc-counter');
    var countEl     = document.getElementById('tc-count');
    var maxEl       = document.getElementById('tc-max');
    var grid        = document.getElementById('tc-grid');
    var submitBtn   = document.getElementById('tc-submit-btn');

    // Modals
    var confirmModal  = document.getElementById('tc-confirm-modal');
    var confirmCount  = document.getElementById('tc-confirm-count');
    var confirmUpsell = document.getElementById('tc-confirm-upsell');
    var confirmCancel = document.getElementById('tc-confirm-cancel');
    var confirmYes    = document.getElementById('tc-confirm-yes');
    // Extra cost banner (shown inline when client exceeds package)
    var extraBanner   = null;

    // Load data
    loadProofs();

    function loadProofs() {
        var url = apiUrl + code;
        if (token) url += '?token=' + encodeURIComponent(token);

        fetch(url, { headers: { 'X-WP-Nonce': nonce } })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            loading.style.display = 'none';

            if (!data.ok) {
                showMessage('Unable to load proofs. Please try again.');
                return;
            }

            if (!data.culling_enabled) {
                showMessage('Photo selection is not available for this session.');
                return;
            }

            if (data.has_password && !data.unlocked) {
                pwSection.style.display = 'block';
                return;
            }

            if (!data.ready) {
                notReady.style.display = 'block';
                return;
            }

            if (data.submitted) {
                submittedEl.style.display = 'block';
                return;
            }

            // Store data
            proofs = data.proofs || [];
            includedImages = data.included_images || 15;
            freebies = data.freebies || 0;
            maxFree = data.max_free || (includedImages + freebies);
            upsellPricePerPhoto = data.upsell_price_per_photo || 30;

            // Restore selections
            if (data.selected_ids) {
                data.selected_ids.forEach(function(id) { selectedIds.add(id); });
            }

            if (proofs.length === 0) {
                showMessage('No proofs available yet. Please check back soon.');
                return;
            }

            bd = data.backdrops || null;
            if (bd && bd.assign) Object.keys(bd.assign).forEach(function(k) { bdAssign[k] = bd.assign[k]; });

            // Render
            renderBackdropPanel();
            buildGrid();
            counter.style.display = '';
            grid.style.display = '';
            updateCounter();
        })
        .catch(function(err) {
            loading.style.display = 'none';
            showMessage('Unable to load proofs. Please refresh the page.');
            console.error('[Tweller Culling]', err);
        });
    }

    function showMessage(msg) {
        var el = document.createElement('div');
        el.className = 'tc-portal__message';
        el.innerHTML = '<p>' + msg + '</p>';
        portal.appendChild(el);
    }

    // ── Password ───────────────────────────────────────
    if (pwForm) {
        pwForm.addEventListener('submit', function(e) {
            e.preventDefault();
            var pw = pwInput.value.trim();
            if (!pw) return;
            pwError.style.display = 'none';

            fetch(apiUrl + code + '/verify', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': nonce },
                body: JSON.stringify({ password: pw })
            })
            .then(function(r) {
                if (!r.ok) throw new Error('wrong');
                return r.json();
            })
            .then(function(data) {
                if (data.ok && data.token) {
                    token = data.token;
                    sessionStorage.setItem('tc_token_' + code, data.token);
                    pwSection.style.display = 'none';
                    loading.style.display = 'block';
                    loadProofs();
                }
            })
            .catch(function() {
                pwError.style.display = 'block';
                pwInput.value = '';
                pwInput.focus();
            });
        });
    }

    // ── Grid ───────────────────────────────────────────
    function buildGrid() {
        grid.innerHTML = '';

        proofs.forEach(function(proof) {
            var item = document.createElement('div');
            item.className = 'tc-grid__item' + (selectedIds.has(proof.id) ? ' tc-grid__item--selected' : '');
            item.setAttribute('data-id', proof.id);

            var img = document.createElement('img');
            img.src = proof.thumb_url;
            img.alt = proof.filename;
            img.loading = 'lazy';
            img.draggable = false;
            img.oncontextmenu = function(e) { e.preventDefault(); return false; };

            var check = document.createElement('div');
            check.className = 'tc-grid__check';
            check.innerHTML = '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><polyline points="20 6 9 17 4 12"/></svg>';

            var number = document.createElement('div');
            number.className = 'tc-grid__number';

            var zoom = document.createElement('button');
            zoom.type = 'button';
            zoom.className = 'tc-grid__zoom';
            zoom.title = 'View larger';
            zoom.innerHTML = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/><line x1="11" y1="8" x2="11" y2="14"/><line x1="8" y1="11" x2="14" y2="11"/></svg>';
            zoom.addEventListener('click', function(e) {
                e.stopPropagation();
                openLightbox(proofs.indexOf(proof));
            });

            item.appendChild(img);
            item.appendChild(check);
            item.appendChild(number);
            item.appendChild(zoom);
            var pick = backdropPicker(proof.id);
            if (pick) item.appendChild(pick);

            item.addEventListener('click', function() {
                if (submitted) return;
                toggleSelection(proof.id, item);
            });

            grid.appendChild(item);
        });

        updateSelectionNumbers();
    }

    // ── Lightbox (bigger view with select) ─────────────
    var lightboxIndex = -1;

    function findItemEl(id) {
        return grid.querySelector('.tc-grid__item[data-id="' + id + '"]');
    }

    function openLightbox(index) {
        if (index < 0 || index >= proofs.length) return;
        lightboxIndex = index;

        var lb = document.getElementById('tc-lightbox');
        if (!lb) {
            var hasPreset = typeof twellerCulling !== 'undefined' && twellerCulling.presetFilter;
            lb = document.createElement('div');
            lb.id = 'tc-lightbox';
            lb.className = 'tc-lightbox';
            lb.innerHTML =
                '<button type="button" class="tc-lightbox__close" title="Close">&times;</button>' +
                '<button type="button" class="tc-lightbox__nav tc-lightbox__nav--prev" title="Previous">&#8249;</button>' +
                '<div class="tc-lb-stage">' +
                    '<img class="tc-lb-img tc-lb-img--before" alt="" draggable="false">' +
                    ( hasPreset ?
                        '<img class="tc-lb-img tc-lb-img--after" alt="" draggable="false" style="filter:' + twellerCulling.presetFilter + ';">' +
                        '<div class="tc-lb-divider"><div class="tc-lb-handle">&#8596;</div></div>' +
                        '<span class="tc-lb-label tc-lb-label--before">UNEDITED</span>' +
                        '<span class="tc-lb-label tc-lb-label--after">&#10024; EDITED PREVIEW</span>'
                    : '' ) +
                '</div>' +
                '<button type="button" class="tc-lightbox__nav tc-lightbox__nav--next" title="Next">&#8250;</button>' +
                '<div class="tc-lightbox__footer">' +
                    '<span class="tc-lightbox__counter"></span>' +
                    '<button type="button" class="tc-lightbox__select"></button>' +
                '</div>' +
                ( hasPreset ? '<p class="tc-lb-hint">Drag the slider — see how your photo transforms with our editing. Only selected photos get the full treatment.</p>' : '' );
            portal.appendChild(lb);

            // ── Before/after compare slider ──
            if (hasPreset) {
                var stage = lb.querySelector('.tc-lb-stage');
                var afterImg = lb.querySelector('.tc-lb-img--after');
                var divider = lb.querySelector('.tc-lb-divider');

                function setSplit(pct) {
                    pct = Math.max(2, Math.min(98, pct));
                    afterImg.style.clipPath = 'inset(0 0 0 ' + pct + '%)';
                    divider.style.left = pct + '%';
                }
                setSplit(50);

                var dragging = false;
                function posFromEvent(e) {
                    var rect = stage.getBoundingClientRect();
                    var x = (e.touches ? e.touches[0].clientX : e.clientX) - rect.left;
                    return (x / rect.width) * 100;
                }
                stage.addEventListener('pointerdown', function(e) {
                    dragging = true;
                    setSplit(posFromEvent(e));
                    e.preventDefault();
                });
                window.addEventListener('pointermove', function(e) {
                    if (dragging) setSplit(posFromEvent(e));
                });
                window.addEventListener('pointerup', function() { dragging = false; });
                stage.addEventListener('touchmove', function(e) {
                    setSplit(posFromEvent(e));
                    e.preventDefault();
                }, { passive: false });

                lb._setSplit = setSplit;
            }

            lb.querySelector('.tc-lightbox__close').addEventListener('click', closeLightbox);
            lb.querySelector('.tc-lightbox__nav--prev').addEventListener('click', function() { openLightbox(lightboxIndex - 1); });
            lb.querySelector('.tc-lightbox__nav--next').addEventListener('click', function() { openLightbox(lightboxIndex + 1); });
            lb.querySelector('.tc-lightbox__select').addEventListener('click', function() {
                var proof = proofs[lightboxIndex];
                if (!proof || submitted) return;
                var itemEl = findItemEl(proof.id);
                if (itemEl) toggleSelection(proof.id, itemEl);
                updateLightbox();
            });
            lb.addEventListener('click', function(e) {
                if (e.target === lb) closeLightbox();
            });
            lb.oncontextmenu = function(e) { e.preventDefault(); return false; };

            document.addEventListener('keydown', function(e) {
                var box = document.getElementById('tc-lightbox');
                if (!box || box.style.display === 'none') return;
                if (e.key === 'Escape') closeLightbox();
                if (e.key === 'ArrowLeft') openLightbox(lightboxIndex - 1);
                if (e.key === 'ArrowRight') openLightbox(lightboxIndex + 1);
            });
        }

        lb.style.display = 'flex';
        updateLightbox();
    }

    function updateLightbox() {
        var lb = document.getElementById('tc-lightbox');
        if (!lb) return;
        var proof = proofs[lightboxIndex];
        if (!proof) return;

        var src = proof.url || proof.thumb_url;
        lb.querySelectorAll('.tc-lb-img').forEach(function(im) { im.src = src; });
        if (lb._setSplit) lb._setSplit(50);
        lb.querySelector('.tc-lightbox__counter').textContent = (lightboxIndex + 1) + ' / ' + proofs.length;
        lb.querySelector('.tc-lightbox__nav--prev').style.visibility = lightboxIndex > 0 ? 'visible' : 'hidden';
        lb.querySelector('.tc-lightbox__nav--next').style.visibility = lightboxIndex < proofs.length - 1 ? 'visible' : 'hidden';

        var selBtn = lb.querySelector('.tc-lightbox__select');
        if (submitted) {
            selBtn.style.display = 'none';
        } else {
            selBtn.style.display = '';
            var isSel = selectedIds.has(proof.id);
            selBtn.textContent = isSel ? '✓ Selected — tap to remove' : 'Select this photo';
            selBtn.className = 'tc-lightbox__select' + (isSel ? ' tc-lightbox__select--on' : '');
        }
    }

    function closeLightbox() {
        var lb = document.getElementById('tc-lightbox');
        if (lb) lb.style.display = 'none';
    }

    // ── Backdrops: which one goes on which photo ─────────
    function esc(t) { return String(t == null ? '' : t).replace(/[&<>"]/g, function(c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]; }); }

    function bdMine() { return (bd && bd.mine) ? bd.mine : []; }

    // A menu on each chosen photo when there is a real choice to make.
    function bdHasChoice() {
        var n = bdMine().length;
        return n > 1 || (n === 1 && bd && !bd.primary_digital);
    }

    function bdDefault() {
        var mine = bdMine();
        if (bd && bd.primary_digital && mine.some(function(m) { return m.key === bd.primary; })) return bd.primary;
        if (mine.length === 1 && bd && bd.primary_digital) return mine[0].key;
        return bd ? bd.keep : '';
    }

    function bdKeyFor(id) {
        var k = bdAssign[id];
        var ok = k === (bd && bd.keep) || bdMine().some(function(m) { return m.key === k; });
        return ok ? k : bdDefault();
    }

    function bdThumb(key) {
        var m = bdMine().filter(function(x) { return x.key === key; })[0];
        return m ? m.thumb : '';
    }

    function bdName(key) {
        if (bd && key === bd.keep) return 'Keep as shot';
        var m = bdMine().filter(function(x) { return x.key === key; })[0];
        return m ? m.name : '';
    }

    function backdropPicker(id) {
        if (!bd || !bdMine().length) return null;
        var wrap = document.createElement('div');
        wrap.className = 'tc-bdpick';
        // Picking a backdrop must never toggle the photo's selection.
        ['click', 'mousedown', 'touchstart', 'pointerdown'].forEach(function(ev) {
            wrap.addEventListener(ev, function(e) { e.stopPropagation(); }, { passive: true });
        });
        paintPicker(wrap, id);
        return wrap;
    }

    function paintPicker(wrap, id) {
        var key = bdKeyFor(id);
        var th = bdThumb(key);
        if (!bdHasChoice()) {
            wrap.innerHTML = (th ? '<img src="' + esc(th) + '" alt="">' : '') + '<span class="tc-bdpick__fixed">' + esc(bdName(key)) + '</span>';
            return;
        }
        var opts = bdMine().map(function(m) { return '<option value="' + esc(m.key) + '"' + (m.key === key ? ' selected' : '') + '>' + esc(m.name) + '</option>'; }).join('') +
            '<option value="' + esc(bd.keep) + '"' + (key === bd.keep ? ' selected' : '') + '>Keep as shot</option>';
        wrap.innerHTML = (th ? '<img src="' + esc(th) + '" alt="">' : '<span class="tc-bdpick__dot" aria-hidden="true"></span>') +
            '<select aria-label="Backdrop for this photo">' + opts + '</select>';
        var sel = wrap.querySelector('select');
        sel.addEventListener('change', function() {
            bdAssign[id] = sel.value;
            paintPicker(wrap, id);
            renderBackdropPanel();
        });
    }

    function repaintPickers() {
        grid.querySelectorAll('.tc-grid__item').forEach(function(item) {
            var id = parseInt(item.getAttribute('data-id'), 10);
            var old = item.querySelector('.tc-bdpick');
            var neu = backdropPicker(id);
            if (old && neu) item.replaceChild(neu, old); else if (neu) item.appendChild(neu); else if (old) old.remove();
        });
    }

    // The panel above the grid: their backdrops, how many photos each, and
    // the early-bird free backdrop when it has not been chosen yet.
    function renderBackdropPanel() {
        var box = document.getElementById('tc-bd');
        if (!box || !bd) return;
        var mine = bdMine();
        if (!mine.length && !bd.free_available) { box.hidden = true; return; }
        var counts = {};
        selectedIds.forEach(function(id) { var k = bdKeyFor(id); counts[k] = (counts[k] || 0) + 1; });
        var chips = mine.map(function(m) {
            return '<li class="tc-bd__chip">' + (m.thumb ? '<img src="' + esc(m.thumb) + '" alt="">' : '') + '<span><b>' + esc(m.name) + '</b><small>' + (counts[m.key] || 0) + ' photo' + ((counts[m.key] || 0) === 1 ? '' : 's') + '</small></span></li>';
        }).join('');
        var html = '<div class="tc-bd__head"><h3 class="tc-bd__title">Your backdrops</h3>' +
            (bdHasChoice() ? '<p class="tc-bd__hint">Select a photo, then choose its backdrop from the menu at the bottom of the photo.</p>'
                : (mine.length ? '<p class="tc-bd__hint">Every photo you choose gets this backdrop.</p>' : '')) + '</div>';
        if (mine.length) html += '<ul class="tc-bd__chips">' + chips + (counts[bd.keep] ? '<li class="tc-bd__chip tc-bd__chip--keep"><span><b>Keep as shot</b><small>' + counts[bd.keep] + ' photo' + (counts[bd.keep] === 1 ? '' : 's') + '</small></span></li>' : '') + '</ul>';
        if (bd.free_available && bd.free_options && bd.free_options.length) {
            html += '<div class="tc-bd__free"><p class="tc-bd__freetitle"><span aria-hidden="true">🎁</span> Your early-bird gift: <b>one extra backdrop, free</b>. Choose it now and use it on any of your photos.</p>' +
                '<div class="tc-bd__freegrid">' + bd.free_options.map(function(o) {
                    return '<button type="button" class="tc-bd__freeopt" data-key="' + esc(o.key) + '">' + (o.thumb ? '<img src="' + esc(o.thumb) + '" alt="">' : '') + '<span>' + esc(o.name) + '</span></button>';
                }).join('') + '</div><p class="tc-bd__freemsg" role="status" aria-live="polite"></p></div>';
        }
        box.innerHTML = html;
        box.hidden = false;
        box.querySelectorAll('.tc-bd__freeopt').forEach(function(b) {
            b.addEventListener('click', function() { redeemFree(b.getAttribute('data-key'), b); });
        });
    }

    function redeemFree(key, btn) {
        var msg = document.querySelector('.tc-bd__freemsg');
        if (msg) msg.textContent = 'Adding your free backdrop…';
        btn.disabled = true;
        var body = { key: key };
        if (token) body.token = token;
        fetch(apiUrl + code + '/redeem-backdrop', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': nonce },
            body: JSON.stringify(body)
        })
        .then(function(r) { return r.json().then(function(j) { return { ok: r.ok, j: j }; }); })
        .then(function(r) {
            if (!r.ok || !r.j.ok) throw new Error((r.j && r.j.message) || 'Could not add it. Please try again.');
            bd = r.j.backdrops;
            renderBackdropPanel();
            repaintPickers();
        })
        .catch(function(e) { if (msg) msg.textContent = e.message; btn.disabled = false; });
    }

    function backdropSummary() {
        if (!bd || !bdMine().length) return '';
        var counts = {};
        selectedIds.forEach(function(id) { var k = bdKeyFor(id); counts[k] = (counts[k] || 0) + 1; });
        return Object.keys(counts).map(function(k) { return counts[k] + ' × ' + bdName(k); }).join(', ');
    }

    function toggleSelection(id, item) {
        if (selectedIds.has(id)) {
            selectedIds.delete(id);
            item.classList.remove('tc-grid__item--selected');
        } else {
            selectedIds.add(id);
            item.classList.add('tc-grid__item--selected');
        }
        updateCounter();
        updateSelectionNumbers();
        updateExtraBanner();
        renderBackdropPanel();
    }

    function updateSelectionNumbers() {
        var items = grid.querySelectorAll('.tc-grid__item');
        var idx = 0;
        items.forEach(function(item) {
            var numEl = item.querySelector('.tc-grid__number');
            var id = parseInt(item.getAttribute('data-id'));
            if (selectedIds.has(id)) {
                idx++;
                numEl.textContent = idx;
                numEl.style.display = '';
            } else {
                numEl.style.display = 'none';
            }
        });
    }

    function updateCounter() {
        var count = selectedIds.size;
        var extra = Math.max(0, count - maxFree);

        countEl.textContent = count;
        maxEl.textContent = maxFree;

        submitBtn.disabled = count === 0;

        if (extra > 0) {
            countEl.style.color = '#D97706';
        } else {
            countEl.style.color = '';
        }
    }

    function updateExtraBanner() {
        var extra = Math.max(0, selectedIds.size - maxFree);
        var cost  = extra * upsellPricePerPhoto;

        if (!extraBanner) {
            extraBanner = document.createElement('div');
            extraBanner.id = 'tc-extra-banner';
            extraBanner.style.cssText = 'background:#FEF9EC; border:1px solid #FCD34D; border-radius:10px; padding:12px 18px; margin:12px 0; display:flex; align-items:center; gap:12px; font-size:14px;';
            if (counter) counter.after(extraBanner);
        }

        if (extra > 0) {
            extraBanner.style.display = 'flex';
            extraBanner.innerHTML =
                '<span style="font-size:20px;">💡</span>' +
                '<span>' +
                  '<strong style="color:#92400E;">' + extra + ' extra photo' + (extra > 1 ? 's' : '') + ' selected</strong>' +
                  ' &mdash; ' +
                  extra + ' × $' + upsellPricePerPhoto + ' TTD = ' +
                  '<strong style="color:#92400E;">$' + cost + ' TTD additional charge</strong>.' +
                  ' <span style="color:#6B7280;">Payment details will be emailed to you after submitting.</span>' +
                '</span>';
        } else {
            extraBanner.style.display = 'none';
        }
    }

    // ── Submit ──────────────────────────────────────────
    if (submitBtn) {
        submitBtn.addEventListener('click', function() {
            var count = selectedIds.size;
            var extra = Math.max(0, count - maxFree);
            var cost  = extra * upsellPricePerPhoto;

            confirmCount.textContent = count;
            if (extra > 0) {
                confirmUpsell.innerHTML =
                    '<strong>' + extra + ' extra photo' + (extra > 1 ? 's' : '') + '</strong> &mdash; an additional charge of <strong>$' + cost + ' TTD</strong> will apply. Payment details will be emailed to you.';
                confirmUpsell.style.display = 'block';
            } else {
                confirmUpsell.style.display = 'none';
            }
            var bdLine = document.getElementById('tc-confirm-bd');
            if (!bdLine && confirmUpsell) {
                bdLine = document.createElement('p');
                bdLine.id = 'tc-confirm-bd';
                bdLine.className = 'tc-confirm-bd';
                confirmUpsell.parentNode.insertBefore(bdLine, confirmUpsell);
            }
            var bdSum = backdropSummary();
            if (bdLine) { bdLine.textContent = bdSum ? 'Backdrops: ' + bdSum : ''; bdLine.style.display = bdSum ? '' : 'none'; }
            confirmModal.style.display = 'flex';
        });
    }

    if (confirmCancel) {
        confirmCancel.addEventListener('click', function() {
            confirmModal.style.display = 'none';
        });
    }

    if (confirmYes) {
        confirmYes.addEventListener('click', function() {
            confirmYes.disabled = true;
            confirmYes.textContent = 'Submitting...';

            var body = {
                proof_ids: Array.from(selectedIds),
            };
            if (bd && bdMine().length) {
                body.backdrops = {};
                selectedIds.forEach(function(id) { body.backdrops[id] = bdKeyFor(id); });
            }
            if (token) body.token = token;

            fetch(apiUrl + code + '/select', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': nonce },
                body: JSON.stringify(body)
            })
            .then(function(r) { return r.json(); })
            .then(function(data) {
                confirmModal.style.display = 'none';
                if (data.ok) {
                    submitted = true;
                    grid.style.display = 'none';
                    counter.style.display = 'none';
                    submittedEl.style.display = 'block';
                    submittedEl.scrollIntoView({ behavior: 'smooth' });
                } else {
                    alert('Error: ' + (data.message || 'Could not submit. Please try again.'));
                    confirmYes.disabled = false;
                    confirmYes.textContent = 'Confirm & Submit';
                }
            })
            .catch(function(err) {
                alert('Network error. Please try again.');
                confirmYes.disabled = false;
                confirmYes.textContent = 'Confirm & Submit';
            });
        });
    }

    // Block right-click on images (basic protection)
    portal.addEventListener('contextmenu', function(e) {
        if (e.target.tagName === 'IMG') {
            e.preventDefault();
            return false;
        }
    });

    // Block drag on images
    portal.addEventListener('dragstart', function(e) {
        if (e.target.tagName === 'IMG') {
            e.preventDefault();
            return false;
        }
    });
})();
