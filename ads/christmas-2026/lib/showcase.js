/* "Website showcase" — the landing page's before/after slider and backdrop
 * slider, animated, for sending to clients privately (not an ad: no Meta
 * safe-zone fuss, but text still stays inside the Reels safe area so one
 * file works everywhere). */
(function () {
  'use strict';
  const root = document.getElementById('root');
  const W = +root.dataset.width, H = +root.dataset.height, TALL = W / H < 0.7;
  const TOTAL = 21;
  const el = (t, c, p, h) => { const e = document.createElement(t); if (c) e.className = c; if (h != null) e.innerHTML = h; (p || root).appendChild(e); return e; };
  const S = (n) => 'assets/scenes/' + n + '.jpg';
  const SETS = [
    { n: 6, name: 'Snowy Window', bg: 'snowy-window' },
    { n: 3, name: 'Fireside Library', bg: 'fireside-library' },
    { n: 5, name: 'Champagne Gold', bg: 'champagne-gold' }
  ];
  const SCENES = [['Fireside Library', 'fireside-library'], ['Classic Red Hearth', 'classic-red-hearth'], ['Snowy Window', 'snowy-window'], ['Champagne Gold', 'champagne-gold']];

  // layout
  const safeT = H * 0.14, safeB = H * (TALL ? 0.65 : 0.88);
  const cardW = Math.round(W * 0.88), cardH = Math.round(cardW * 847 / 1186);
  const cardL = (W - cardW) / 2;
  const midY = TALL ? H * 0.46 : H * 0.5;
  const cardT = Math.round(midY - cardH / 2);
  const headT = TALL ? safeT : H * 0.05;

  // blurred backgrounds that crossfade with each scene
  const bgs = SCENES.map(([, slug], i) => { const b = el('div', 'bgblur'); b.style.backgroundImage = 'url(' + S(slug) + ')'; b.style.opacity = i === 0 ? 1 : 0; return b; });
  el('div', 'vig');

  const head = el('div', 'sc-head'); head.style.top = headT + 'px';
  el('div', 'sc-kick', head, 'The Christmas Photos page');
  const title = el('div', 'sc-title', head, 'Your photo. <em>Any</em> scene.');

  // ── before / after cards ──
  const cards = SETS.map((s) => {
    const c = el('div', 'card'); Object.assign(c.style, { left: cardL + 'px', top: cardT + 'px', width: cardW + 'px', height: cardH + 'px', opacity: 0 });
    const b = el('img', '', c); b.src = 'assets/before-after/ba' + s.n + '-before.webp';
    const aw = el('div', 'after', c); const a = el('img', '', aw); a.src = 'assets/before-after/ba' + s.n + '-after.webp';
    const h = el('div', 'handle', c, '<i></i>'); h.style.left = '50%';
    el('span', 'pill l', c, 'Original'); el('span', 'pill r', c, 'After');
    const cap = el('div', 'cap', root); cap.style.top = (cardT + cardH + 44) + 'px';
    el('div', 'cap__name', cap, s.name); el('div', 'cap__sub', cap, 'Only the background changes');
    return { c, aw, h, cap };
  });

  // ── backdrop slider ──
  const sl = el('div', 'card'); Object.assign(sl.style, { left: cardL + 'px', top: cardT + 'px', width: cardW + 'px', height: cardH + 'px', opacity: 0 });
  const track = el('div', 'track', sl);
  SCENES.forEach(([, slug]) => { const i = el('img', '', track); i.src = S(slug); });
  el('div', 'arrow l', sl); el('div', 'arrow r', sl);
  const scap = el('div', 'cap', root); scap.style.top = (cardT + cardH + 44) + 'px';
  const sname = el('div', 'cap__name', scap, SCENES[0][0]);
  el('div', 'cap__sub', scap, 'Pick yours at booking');
  const dots = el('div', 'dots', root); dots.style.top = (cardT + cardH + 225) + 'px';
  const dotEls = SCENES.map((_, i) => el('b', i === 0 ? 'on' : '', dots));

  // ── end card ──
  const end = el('div', 'sc-end', root); end.style.top = (TALL ? H * 0.2 : H * 0.14) + 'px'; end.style.opacity = 0;
  const lw = Math.round(W * (TALL ? 0.5 : 0.4)); const lh = Math.round(lw / window.TW_LOGO.aspect);
  const logo = el('div', 'sc-end__logo', end); logo.style.width = lw + 'px'; logo.style.height = lh + 'px';
  Object.keys(window.TW_LOGO.parts).forEach((k) => { const p = window.TW_LOGO.parts[k]; const i = el('img', '', logo); i.src = 'assets/logo-parts/' + k + '.png'; Object.assign(i.style, { left: p.x * lw + 'px', top: p.y * lh + 'px', width: p.w * lw + 'px', height: p.h * lh + 'px' }); });
  el('div', 'sc-end__line', end, 'Christmas Minis<br>from <em>TTD 550</em>');
  el('div', 'sc-end__url', end, 'twellerstudios.com/christmas-photos');
  el('div', 'sc-end__sm', end, '4 digital scenes · Studio or at home');

  // ── timeline ──
  const tl = gsap.timeline({ paused: true });
  const ease = 'power2.inOut';
  tl.from(head, { opacity: 0, y: 24, duration: 0.7, ease: 'power3.out' }, 0.2);

  const D = 3.2, T0 = 1.6;
  cards.forEach((k, i) => {
    const t = T0 + i * D;
    tl.to(title, { opacity: 0, duration: 0.3 }, T0 - 0.1); // headline gives way to the card
    tl.fromTo(k.c, { opacity: 0, y: 40, scale: 0.96 }, { opacity: 1, y: 0, scale: 1, duration: 0.5, ease: 'power3.out' }, t);
    tl.fromTo(k.cap, { opacity: 0, y: 18 }, { opacity: 1, y: 0, duration: 0.45, ease: 'power3.out' }, t + 0.25);
    // the slider sweep: centre → left → right → centre
    [[8, 0.7], [92, 1.1], [50, 0.7]].forEach(([p, d], j, a) => {
      const st = t + 0.55 + a.slice(0, j).reduce((s, x) => s + x[1], 0);
      tl.to(k.aw, { clipPath: 'inset(0 0 0 ' + p + '%)', duration: d, ease }, st);
      tl.to(k.h, { left: p + '%', duration: d, ease }, st);
    });
    tl.to([k.c, k.cap], { opacity: 0, duration: 0.35 }, t + D - 0.32);
  });
  // background colour follows the set's scene
  const order = [2, 1, 3]; // snowy, fireside, champagne
  order.forEach((bi, i) => {
    const t = T0 + i * D;
    bgs.forEach((b, j) => tl.to(b, { opacity: j === bi ? 1 : 0, duration: 0.6 }, t - 0.1));
  });

  // backdrop slider
  const ST = T0 + 3 * D;
  tl.to(bgs, { opacity: (i) => (i === 0 ? 1 : 0), duration: 0.5 }, ST - 0.2);
  tl.to(title, { opacity: 1, duration: 0.01 }, ST - 0.1); title.innerHTML = 'Your photo. <em>Any</em> scene.';
  tl.fromTo(sl, { opacity: 0, y: 40, scale: 0.96 }, { opacity: 1, y: 0, scale: 1, duration: 0.5, ease: 'power3.out' }, ST);
  tl.fromTo([scap, dots], { opacity: 0, y: 18 }, { opacity: 1, y: 0, duration: 0.45 }, ST + 0.2);
  SCENES.forEach(([name], i) => {
    if (i === 0) return;
    const t = ST + 0.6 + (i - 1) * 1.15;
    tl.to(track, { x: -i * cardW, duration: 0.7, ease: 'power3.inOut' }, t);
    tl.to(bgs.map((b, j) => b), { opacity: (j) => (j === i ? 1 : 0), duration: 0.6 }, t);
    tl.set(sname, { textContent: name }, t + 0.35);
    dotEls.forEach((d, j) => tl.set(d, { className: j === i ? 'on' : '' }, t + 0.35));
  });
  tl.to([sl, scap, dots, title], { opacity: 0, duration: 0.4 }, ST + 4.4);

  // end card
  const ET = ST + 4.8;
  tl.to(head, { opacity: 0, duration: 0.2 }, ET - 0.3);
  tl.fromTo(end, { opacity: 0, y: 30 }, { opacity: 1, y: 0, duration: 0.7, ease: 'power3.out' }, ET);
  tl.set({}, {}, TOTAL);
  window.__timelines = window.__timelines || {};
  window.__timelines.root = tl;
})();
