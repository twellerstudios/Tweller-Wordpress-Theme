/* "Scene Tour" reel — one composition for 9:16 (Reels/Stories) and 4:5 (Feed).
 *
 * The root's data-width / data-height pick the layout; data-safe-top and
 * data-safe-bottom (fractions of the height) keep every word clear of the
 * Reels interface (9:16: top 14%, bottom 35%).
 *
 * Real client photos can be dropped in later: set the variable
 * family_<slug> (e.g. family_classic-red-hearth) to a transparent PNG
 * cut-out and it appears in front of that scene.
 *
 * Beats (s): 0 hook · 1.5 / 3.65 / 5.8 scene cuts · 8.0 caption 1 · 9.6 caption 2 · 11 end card · 15 out
 */
(function () {
  'use strict';
  const root = document.getElementById('root');
  const W = +root.dataset.width, H = +root.dataset.height;
  const SAFE_T = H * +(root.dataset.safeTop || 0.14);
  const SAFE_B = H * (1 - +(root.dataset.safeBottom || 0.35));
  const vars = Object.assign({
    price: '550',
    early_bird: 'Book by Nov 30 → extra scene FREE',
    cta: 'Tap Book Now to see packages'
  }, (window.__hyperframes && window.__hyperframes.getVariables && window.__hyperframes.getVariables()) || {});

  const SCENES = [
    { slug: 'fireside-library',   name: 'Fireside Library',   fx: 0.52, fy: 0.56, glow: [0.52, 0.66] },
    { slug: 'classic-red-hearth', name: 'Classic Red Hearth', fx: 0.50, fy: 0.55, glow: [0.50, 0.65] },
    { slug: 'snowy-window',       name: 'Snowy Window',       fx: 0.48, fy: 0.52, glow: null },
    { slug: 'champagne-gold',     name: 'Champagne Gold',     fx: 0.47, fy: 0.55, glow: null }
  ];
  const T = { s2: 1.5, s3: 3.65, s4: 5.8, cap1: 8.0, cap2: 9.6, end: 11.0, total: 15 };

  const el = (tag, cls, parent, html) => {
    const e = document.createElement(tag);
    if (cls) e.className = cls;
    if (html != null) e.innerHTML = html;
    (parent || root).appendChild(e);
    return e;
  };
  const px = (n) => Math.round(n) + 'px';

  // Deterministic randomness: every render is identical.
  let seed = 20261225;
  const rnd = () => ((seed = (seed * 1664525 + 1013904223) >>> 0) / 4294967296);

  // ── Scene layers ────────────────────────────────
  // A scene image covers the frame, positioned so its focal point (the
  // fireplace / window) sits at the centre of the crop.
  function sceneLayer(sc, cls) {
    const wrap = el('div', 'layer scene ' + (cls || ''));
    const cam = el('div', 'cam', wrap);
    const img = el('img', '', cam);
    img.src = 'assets/scenes/' + sc.slug + '.jpg';
    img.alt = '';
    const ih = H * 1.0, iw = ih * 1.5;          // 3:2 source
    let w = iw, h = ih;
    if (w < W) { w = W; h = W / 1.5; }
    const left = Math.min(0, Math.max(W - w, W / 2 - sc.fx * w));
    const top = Math.min(0, Math.max(H - h, H / 2 - sc.fy * h));
    Object.assign(img.style, { width: px(w), height: px(h), left: px(left), top: px(top) });
    if (sc.glow) {
      const g = el('div', 'glow', cam);
      Object.assign(g.style, { left: px(left + sc.glow[0] * w - W * 0.45), top: px(top + sc.glow[1] * h - W * 0.45), width: px(W * 0.9), height: px(W * 0.9) });
      sc.glowEl = g;
    }
    sc.wrap = wrap; sc.cam = cam;
    return wrap;
  }

  SCENES.forEach((sc) => sceneLayer(sc));

  // A real family cut-out, when one is provided.
  const markTop = SAFE_B - H * (W / H > 0.7 ? 0.27 : 0.2);
  const markH = SAFE_B - markTop - 10, markW = Math.min(W * 0.52, markH * 1.45);
  SCENES.forEach((sc) => {
    const photo = vars['family_' + sc.slug];
    if (photo) {
      const f = el('img', 'family', sc.cam);
      f.src = photo;
      Object.assign(f.style, { left: px(W / 2 - markW * 0.7), width: px(markW * 1.4), bottom: px(H - SAFE_B - H * 0.06) });
      return;
    }
    // No placeholder box: the empty scene speaks for itself.
  });

  // Falling snow / bokeh over everything but the end card text.
  const snow = el('div', 'layer snow');
  const flakes = [];
  for (let i = 0; i < 46; i++) {
    const f = el('i', '', snow);
    const s = 3 + rnd() * 9;
    const x = rnd() * W, y0 = rnd() * H - H * 0.1, speed = 0.35 + rnd() * 0.5, drift = (rnd() - 0.5) * 90;
    Object.assign(f.style, { width: px(s), height: px(s), left: px(x), top: '0px', opacity: (0.35 + rnd() * 0.5).toFixed(2), filter: s > 8 ? 'blur(2px)' : 'blur(0.6px)' });
    flakes.push({ f, y0, speed, drift });
  }

  const flash = el('div', 'layer flash');

  // ── Text ────────────────────────────────────────
  const hook = el('div', 'block hook', root,
    '<p class="hook__text">Imagine your family’s Christmas photo here… <span class="emoji">🎄</span></p>');
  hook.style.top = px(SAFE_T + 10);

  const labels = SCENES.slice(1).map((sc, i) => {
    const l = el('div', 'label', root, '<span class="label__no">Scene 0' + (i + 2) + '</span><span class="label__name">' + sc.name + '</span>');
    l.style.top = px(SAFE_T + 6);
    return l;
  });

  // Caption 1 + mosaic of all four scenes
  const mosaic = el('div', 'layer mosaic');
  const cap1 = el('div', 'block caption', mosaic, '<p class="caption__big">Pick your scene</p><p class="caption__small">— or use your own decor</p>');
  cap1.style.top = px(SAFE_T + 6);
  const grid = el('div', 'grid', mosaic);
  const gW = W * 0.86, tileW = (gW - 18) / 2, tileH = tileW / 1.5;
  const gridTop = Math.max(SAFE_T + H * 0.15, (SAFE_B - (tileH * 2 + 18)) - 10);
  Object.assign(grid.style, { left: px((W - gW) / 2), top: px(gridTop), width: px(gW) });
  const tiles = SCENES.map((sc) => {
    const t = el('figure', 'tile', grid, '<img src="assets/scenes/' + sc.slug + '.jpg" alt=""><figcaption>' + sc.name + '</figcaption>');
    Object.assign(t.style, { width: px(tileW), height: px(tileH) });
    return t;
  });

  // Caption 2 over Classic Red Hearth
  const hearth2 = sceneLayer(Object.assign({}, SCENES[1]), 'hearth2');
  const cap2 = el('div', 'block caption', root, '<p class="caption__big">Studio or at your home</p><p class="caption__small">Your scene, or your own decor</p>');
  cap2.style.top = px(SAFE_T + 6);

  // End card
  const end = el('div', 'layer end');
  const endBg = el('img', 'end__bg', end); endBg.src = 'assets/scenes/fireside-library.jpg'; endBg.alt = '';
  const endBody = el('div', 'end__body', end,
    '<img class="end__logo" src="assets/logo-cream.png" alt="Tweller Studios">' +
    '<p class="end__kicker">Christmas Minis</p>' +
    '<p class="end__price"><span>from</span> TTD ' + vars.price + '</p>' +
    '<p class="end__eb">' + vars.early_bird + '</p>' +
    '<p class="end__cta">' + vars.cta + '</p>');
  const endSpan = SAFE_B - SAFE_T;
  Object.assign(endBody.style, { top: px(SAFE_T), height: px(endSpan) });
  root.style.setProperty('--u', (Math.min(W, endSpan * 1.05) / 1080).toFixed(4));

  // ── Timeline ────────────────────────────────────
  const tl = gsap.timeline({ paused: true });
  const [lib, hea, sno, cha] = SCENES;

  // Snow runs the whole film.
  flakes.forEach(({ f, y0, speed, drift }) => {
    tl.fromTo(f, { y: y0, x: 0 }, { y: y0 + H * speed * (T.total / 6), x: drift, duration: T.total, ease: 'none' }, 0);
  });

  // Fire / candle flicker on the scenes that have a fire.
  [lib, hea].forEach((sc, k) => {
    if (!sc.glowEl) return;
    const from = k === 0 ? 0 : T.s2, to = k === 0 ? T.s2 + 0.3 : T.s3 + 0.3;
    for (let t = from; t < to; t += 0.11) tl.to(sc.glowEl, { opacity: 0.45 + rnd() * 0.5, duration: 0.11, ease: 'none' }, t);
  });

  // Scene visibility and camera moves
  const show = (sc, at, until) => {
    tl.set(sc.wrap, { opacity: 1 }, at);
    if (until != null) tl.set(sc.wrap, { opacity: 0 }, until);
  };
  SCENES.forEach((sc) => tl.set(sc.wrap, { opacity: 0 }, 0));
  show(lib, 0, T.s2);
  show(hea, T.s2, T.s3);
  show(sno, T.s3, T.s4);
  show(cha, T.s4, T.cap1);
  // hook: slow push-in toward the fire
  tl.fromTo(lib.cam, { scale: 1.0 }, { scale: 1.09, duration: T.s2 + 0.2, ease: 'none' }, 0);
  // hearth: push in, settling from a slight overshoot (the beat)
  tl.fromTo(hea.cam, { scale: 1.14 }, { scale: 1.02, duration: T.s3 - T.s2, ease: 'power2.out' }, T.s2);
  // snowy window: lateral pan
  tl.fromTo(sno.cam, { scale: 1.1, x: -W * 0.05 }, { scale: 1.1, x: W * 0.05, duration: T.s4 - T.s3, ease: 'sine.inOut' }, T.s3);
  // champagne: parallax pull-back
  tl.fromTo(cha.cam, { scale: 1.16, y: H * 0.02 }, { scale: 1.03, y: 0, duration: T.cap1 - T.s4, ease: 'power1.out' }, T.s4);

  // Crisp cut on each beat: a short warm flash.
  [T.s2, T.s3, T.s4, T.cap1, T.cap2, T.end].forEach((t) => {
    tl.fromTo(flash, { opacity: 0 }, { opacity: 0.55, duration: 0.06, ease: 'none' }, t - 0.06);
    tl.to(flash, { opacity: 0, duration: 0.32, ease: 'power2.out' }, t);
  });

  // Markers breathe gently while their scene is on.
  SCENES.forEach((sc, i) => {
    if (!sc.marker) return;
    const at = [0.35, T.s2 + 0.35, T.s3 + 0.35, T.s4 + 0.35][i];
    tl.fromTo(sc.marker, { opacity: 0, scale: 0.94 }, { opacity: 1, scale: 1, duration: 0.45, ease: 'power2.out' }, at);
  });

  // Hook text — on screen from the first frame, settles in.
  tl.fromTo(hook, { opacity: 1, y: 14, scale: 1.03 }, { y: 0, scale: 1, duration: 0.6, ease: 'power3.out' }, 0);
  tl.to(hook, { opacity: 0, y: -16, duration: 0.25, ease: 'power2.in' }, T.s2 - 0.22);

  // Scene name labels
  const spans = [[T.s2, T.s3], [T.s3, T.s4], [T.s4, T.cap1]];
  labels.forEach((l, i) => {
    const [a, b] = spans[i];
    tl.set(l, { opacity: 0 }, 0);
    tl.fromTo(l, { opacity: 0, y: 10 }, { opacity: 1, y: 0, duration: 0.35, ease: 'power2.out' }, a + 0.1);
    tl.to(l, { opacity: 0, duration: 0.2 }, b - 0.2);
  });

  // Caption 1 — mosaic of the four scenes
  tl.set(mosaic, { opacity: 0 }, 0);
  tl.set(mosaic, { opacity: 1 }, T.cap1);
  tl.fromTo(cap1, { opacity: 0, y: 16 }, { opacity: 1, y: 0, duration: 0.4, ease: 'power3.out' }, T.cap1 + 0.05);
  tiles.forEach((t, i) => tl.fromTo(t, { opacity: 0, scale: 0.9, y: 20 }, { opacity: 1, scale: 1, y: 0, duration: 0.4, ease: 'back.out(1.6)' }, T.cap1 + 0.15 + i * 0.12));
  tl.set(mosaic, { opacity: 0 }, T.cap2);

  // Caption 2 — Classic Red Hearth again
  tl.set(hearth2, { opacity: 0 }, 0);
  tl.set(hearth2, { opacity: 1 }, T.cap2);
  tl.set(hearth2, { opacity: 0 }, T.end);
  tl.fromTo(hearth2.firstChild, { scale: 1.02 }, { scale: 1.1, duration: T.end - T.cap2, ease: 'none' }, T.cap2);
  tl.set(cap2, { opacity: 0 }, 0);
  tl.fromTo(cap2, { opacity: 0, y: 16 }, { opacity: 1, y: 0, duration: 0.4, ease: 'power3.out' }, T.cap2 + 0.05);
  tl.to(cap2, { opacity: 0, duration: 0.2 }, T.end - 0.15);

  // End card
  tl.set(end, { opacity: 0 }, 0);
  tl.set(end, { opacity: 1 }, T.end);
  tl.fromTo(endBg, { scale: 1.15 }, { scale: 1.05, duration: T.total - T.end, ease: 'none' }, T.end);
  const eb = endBody.children;
  tl.fromTo(eb[0], { opacity: 0, y: 20 }, { opacity: 1, y: 0, duration: 0.5, ease: 'power3.out' }, T.end + 0.05);
  tl.fromTo(eb[1], { opacity: 0, y: 24 }, { opacity: 1, y: 0, duration: 0.5, ease: 'power3.out' }, T.end + 0.2);
  tl.fromTo(eb[2], { opacity: 0, y: 24, scale: 0.96 }, { opacity: 1, y: 0, scale: 1, duration: 0.55, ease: 'back.out(1.4)' }, T.end + 0.35);
  tl.fromTo(eb[3], { opacity: 0, y: 16 }, { opacity: 1, y: 0, duration: 0.45, ease: 'power3.out' }, T.end + 0.9);
  tl.fromTo(eb[4], { opacity: 0, y: 16 }, { opacity: 1, y: 0, duration: 0.45, ease: 'power3.out' }, T.end + 1.3);
  for (let t = T.end + 1.9; t < T.total - 0.3; t += 0.9) {
    tl.to(eb[4], { y: 8, duration: 0.45, ease: 'sine.inOut' }, t);
    tl.to(eb[4], { y: 0, duration: 0.45, ease: 'sine.inOut' }, t + 0.45);
  }
  // Snow fades behind the end card text
  tl.to(snow, { opacity: 0.5, duration: 0.4 }, T.end);

  tl.set({}, {}, T.total);
  window.__timelines = window.__timelines || {};
  window.__timelines.root = tl;
})();
