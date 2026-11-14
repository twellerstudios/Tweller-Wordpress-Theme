/* Christmas Memory Giveaway reel (9:16 and 4:5), 20 s.
 *
 *   0.0  Hook        "What's the Christmas you'll never forget?" over a real family photo
 *   3.2  Memories    three "maybe it was…" memory cards over real client photos
 *   9.6  Prizes      ribbon + Full Session / Mini Session cards + platforms
 *  13.6  How         three steps tick in one after another
 *  16.8  End         logo builds, "Comment yours below", closing date
 *
 * Text stays inside the Reels safe area (9:16: top 14%, bottom 35%).
 * The memory lines are prompts ("Maybe it was…"), not quotes from people.
 */
(function () {
  'use strict';
  const root = document.getElementById('root');
  const W = +root.dataset.width, H = +root.dataset.height;
  const TALL = W / H < 0.7;
  const SAFE_T = H * +(root.dataset.safeTop || 0.14);
  const SAFE_B = H * (1 - +(root.dataset.safeBottom || 0.35));
  const SPAN = SAFE_B - SAFE_T;
  const u = TALL ? 1 : 0.9;
  root.style.setProperty('--u', u);
  const T = { mem: 3.2, prize: 9.6, how: 13.6, end: 16.8, total: 20 };

  const el = (tag, cls, parent, html) => { const e = document.createElement(tag); if (cls) e.className = cls; if (html != null) e.innerHTML = html; (parent || root).appendChild(e); return e; };
  const px = (n) => Math.round(n) + 'px';
  let seed = 20261206;
  const rnd = () => ((seed = (seed * 1664525 + 1013904223) >>> 0) / 4294967296);
  const words = (text) => text.split(' ').map((w) => '<span class="w">' + w + '</span>').join(' ');
  const HOLLY = '<svg class="ribbon__holly" viewBox="0 0 120 64" aria-hidden="true"><path d="M58 40 C40 18 18 14 4 24 C14 26 16 34 10 40 C22 36 28 44 26 52 C38 42 50 46 58 40Z" fill="#1f5a3a" stroke="#0f3322" stroke-width="2"/><path d="M62 40 C80 18 102 14 116 24 C106 26 104 34 110 40 C98 36 92 44 94 52 C82 42 70 46 62 40Z" fill="#256b45" stroke="#0f3322" stroke-width="2"/><circle cx="52" cy="40" r="9" fill="#c8202b"/><circle cx="68" cy="40" r="9" fill="#d4262f"/><circle cx="60" cy="30" r="9" fill="#e0353d"/><circle cx="57" cy="27" r="2.5" fill="#fff" opacity=".6"/></svg>';
  const ICON = {
    fb: '<svg viewBox="0 0 24 24"><path d="M13.5 21v-7.5h2.6l.4-3h-3V8.6c0-.9.3-1.5 1.5-1.5h1.6V4.4c-.3 0-1.2-.1-2.3-.1-2.3 0-3.8 1.4-3.8 3.9v2.3H8v3h2.5V21z"/></svg>',
    ig: '<svg viewBox="0 0 24 24"><path d="M12 7.3a4.7 4.7 0 1 0 0 9.4 4.7 4.7 0 0 0 0-9.4zm0 7.7a3 3 0 1 1 0-6 3 3 0 0 1 0 6zm4.9-7.9a1.1 1.1 0 1 1-2.2 0 1.1 1.1 0 0 1 2.2 0zM12 4.6c2.4 0 2.7 0 3.6.1 2.4.1 3.6 1.3 3.7 3.7.1.9.1 1.2.1 3.6s0 2.7-.1 3.6c-.1 2.4-1.3 3.6-3.7 3.7-.9.1-1.2.1-3.6.1s-2.7 0-3.6-.1c-2.4-.1-3.6-1.3-3.7-3.7-.1-.9-.1-1.2-.1-3.6s0-2.7.1-3.6C4.8 6 6 4.8 8.4 4.7c.9-.1 1.2-.1 3.6-.1zM12 3c-2.4 0-2.8 0-3.7.1C5 3.2 3.2 5 3.1 8.3 3 9.2 3 9.6 3 12s0 2.8.1 3.7c.1 3.3 1.9 5.1 5.2 5.2.9.1 1.3.1 3.7.1s2.8 0 3.7-.1c3.3-.1 5.1-1.9 5.2-5.2.1-.9.1-1.3.1-3.7s0-2.8-.1-3.7C20.8 5 19 3.2 15.7 3.1 14.8 3 14.4 3 12 3z"/></svg>',
    tt: '<svg viewBox="0 0 24 24"><path d="M16.6 5.8A4.3 4.3 0 0 1 15.5 3h-3.1v12.4a2.6 2.6 0 1 1-1.8-2.5V9.7a5.7 5.7 0 1 0 4.9 5.7V9.1a7.3 7.3 0 0 0 4.3 1.4V7.4a4.3 4.3 0 0 1-3.2-1.6z"/></svg>'
  };
  const tick = '<svg viewBox="0 0 24 24"><path d="M5 12.5l4.5 4.5L19 7.5"/></svg>';

  // ── Background: real client photos (after the edit), slow push-ins ──
  const PHOTOS = ['ba3', 'ba6', 'ba5', 'ba2'];
  const photos = PHOTOS.map((n) => {
    const p = el('div', 'photo layer');
    const im = el('img', '', p); im.src = 'assets/before-after/' + n + '-after.webp'; im.alt = '';
    el('div', 'warm', p);
    return { p, im };
  });
  const green = el('div', 'layer green');
  const gbg = el('img', 'gw-bg', green); gbg.src = 'assets/scenes/fireside-library.jpg'; gbg.alt = '';
  el('div', 'gw-shade', green);

  // Snow, deterministic
  const snow = el('div', 'layer snow');
  const flakes = [];
  for (let i = 0; i < 40; i++) {
    const f = el('i', '', snow);
    const s = 3 + rnd() * 8;
    Object.assign(f.style, { width: px(s), height: px(s), left: px(rnd() * W), top: '0px', opacity: (0.3 + rnd() * 0.5).toFixed(2), filter: s > 7 ? 'blur(2px)' : 'blur(.6px)' });
    flakes.push({ f, y0: rnd() * H - H * 0.1, speed: 0.3 + rnd() * 0.45, drift: (rnd() - 0.5) * 80 });
  }
  el('div', 'layer vignette');
  const flash = el('div', 'layer flash');

  // ── 1. Hook ──
  const hook = el('div', 'block', root,
    '<p class="kick">The Christmas Memory Giveaway</p>' +
    '<p class="hook" style="margin:' + px(28 * u) + ' 0 0;">' + words('What&rsquo;s the Christmas') + '<b>' + words('you&rsquo;ll never forget?') + '</b></p><span class="underline"></span>');
  hook.style.top = px(SAFE_T + SPAN * (TALL ? 0.05 : 0.08));
  const hookWords = hook.querySelectorAll('.w');

  // ── 2. Memories (prompts) ──
  const MEM = [
    'the year the power went out and the whole street sang carols by candlelight',
    'the morning a puppy was hiding under the tree',
    'Granny&rsquo;s pastelles, flour everywhere, parang on the radio'
  ];
  const maybe = el('div', 'block', root, '<p class="maybe">Maybe it was&hellip;</p>');
  maybe.style.top = px(SAFE_T + SPAN * 0.02);
  maybe.style.opacity = 0;
  const mems = MEM.map((t) => {
    const m = el('div', 'mem', root, '<p>' + words(t) + '</p><small>Your memory could win</small>');
    m.style.top = px(SAFE_T + SPAN * (TALL ? 0.14 : 0.16));
    return m;
  });

  // ── 3. Prizes ──
  const prize = el('div', 'block', root,
    '<div class="ribbon rib">' + HOLLY + '<span class="foil">Win a Christmas photo session</span><span class="shine"><i></i></span></div>' +
    '<div class="prizes" style="margin-top:' + px(56 * u) + ';' + (TALL ? 'grid-template-columns:1fr;gap:' + px(18) + ';width:86%;margin-left:auto;margin-right:auto;' : '') + '">' +
      '<div class="prize"><span class="prize__tag">Most-loved memory</span><p class="prize__name">Full Christmas Session</p><p class="prize__how">The memory with the most likes</p></div>' +
      '<div class="prize prize--mini"><span class="prize__tag">Random draw</span><p class="prize__name">Mini Session</p><p class="prize__how">One lucky memory, picked at random</p></div>' +
    '</div>' +
    '<div class="plats" style="margin-top:' + px(40 * u) + ';"><span>' + ICON.fb + 'Facebook</span><span>' + ICON.ig + 'Instagram</span><span>' + ICON.tt + 'TikTok</span></div>');
  prize.style.top = px(SAFE_T + SPAN * (TALL ? 0.06 : 0.1));
  const rib = prize.querySelector('.ribbon'), shine = prize.querySelector('.shine');
  const prizeCards = prize.querySelectorAll('.prize'), plats = prize.querySelectorAll('.plats span');

  // ── 4. How to enter ──
  const how = el('div', 'block', root,
    '<p class="kick" style="opacity:1;">How to enter</p>' +
    '<div class="steps2" style="margin-top:' + px(34 * u) + ';">' +
      [['Comment your memory', 'The real one, the one that still makes you smile'], ['Tag who was there', 'Bonus entry on Instagram &amp; TikTok'], ['Enter on our website too', 'twellerstudios.com/giveaway']]
        .map((s, i) => '<div class="st"><span class="n"><span class="fill"></span><span class="num">' + (i + 1) + '</span>' + tick + '</span><span><b>' + s[0] + '</b><span class="t">' + s[1] + '</span></span></div>').join('') +
    '</div>');
  how.style.top = px(SAFE_T + SPAN * (TALL ? 0.08 : 0.12));
  const steps = how.querySelectorAll('.st');

  // ── 5. End card ──
  const end = el('div', 'block', root,
    '<div class="anlogo" aria-label="Tweller Studios"></div>' +
    '<div style="height:' + px(46 * u) + '"></div>' +
    '<span class="cta">Comment yours below</span>' +
    '<div style="height:' + px(30 * u) + '"></div>' +
    '<p class="endline">Ends <b>Sun Dec 6 · 8 pm</b> · 6 winners<br>Prizes valid 6 months · T&amp;T 18+</p>');
  end.style.top = px(SAFE_T + SPAN * (TALL ? 0.12 : 0.18));
  const logoBox = end.querySelector('.anlogo');
  const LG = window.TW_LOGO;
  root.style.setProperty('--logo-aspect', LG.aspect);
  const P = {};
  Object.keys(LG.parts).forEach((name) => {
    const p = LG.parts[name];
    const im = el('img', 'anlogo__part anlogo__' + name.replace(/-\d+$/, ''), logoBox);
    im.src = 'assets/logo-parts/' + name + '.png'; im.alt = '';
    Object.assign(im.style, { left: (p.x * 100) + '%', top: (p.y * 100) + '%', width: (p.w * 100) + '%', height: (p.h * 100) + '%', opacity: 0 });
    P[name] = im;
  });
  const cta = end.querySelector('.cta'), endline = end.querySelector('.endline');

  // ── Timeline ──
  const tl = gsap.timeline({ paused: true });
  const ease = 'power3.out';

  // Snow drifts the whole time
  flakes.forEach((k) => tl.fromTo(k.f, { y: k.y0, x: 0 }, { y: k.y0 + H * k.speed, x: k.drift, duration: T.total, ease: 'none' }, 0));

  // Photo sequence: hook photo, then one per memory
  const showPhoto = (i, t0, t1) => {
    const { p, im } = photos[i];
    tl.fromTo(p, { opacity: 0 }, { opacity: 1, duration: 0.7, ease: 'sine.inOut' }, t0);
    tl.fromTo(im, { scale: 1.02, xPercent: i % 2 ? 1.5 : -1.5 }, { scale: 1.14, xPercent: i % 2 ? -1.5 : 1.5, duration: t1 - t0 + 0.8, ease: 'none' }, t0);
    tl.to(p, { opacity: 0, duration: 0.7, ease: 'sine.inOut' }, t1);
  };
  tl.set(photos[0].p, { opacity: 1 }, 0);
  tl.fromTo(photos[0].im, { scale: 1.16 }, { scale: 1.04, duration: T.mem + 0.6, ease: 'power1.out' }, 0);
  tl.to(photos[0].p, { opacity: 0, duration: 0.7, ease: 'sine.inOut' }, T.mem);
  showPhoto(1, T.mem, T.mem + 2.1);
  showPhoto(2, T.mem + 2.1, T.mem + 4.2);
  showPhoto(3, T.mem + 4.2, T.prize - 0.2);

  // 1. Hook: words rise one by one, gold line draws
  tl.to(hook.querySelector('.kick'), { opacity: 1, duration: 0.6, ease }, 0.15);
  tl.fromTo(hookWords, { opacity: 0, y: 34 * u, filter: 'blur(6px)' }, { opacity: 1, y: 0, filter: 'blur(0px)', duration: 0.6, ease, stagger: 0.09 }, 0.3);
  tl.to(hook.querySelector('.underline'), { scaleX: 1, duration: 0.7, ease: 'power2.inOut' }, 1.25);
  tl.to(hook, { opacity: 0, y: -30 * u, duration: 0.45, ease: 'power2.in' }, T.mem - 0.35);

  // 2. Memories: card slides up, words appear like they're being written
  tl.to(maybe, { opacity: 1, duration: 0.4 }, T.mem + 0.1);
  mems.forEach((m, i) => {
    const t = T.mem + 0.15 + i * 2.1;
    tl.fromTo(m, { opacity: 0, y: 60 * u, rotation: i % 2 ? 1.5 : -1.5 }, { opacity: 1, y: 0, rotation: i % 2 ? -0.6 : 0.6, duration: 0.6, ease: 'back.out(1.4)' }, t);
    tl.fromTo(m.querySelectorAll('.w'), { opacity: 0, y: 10 }, { opacity: 1, y: 0, duration: 0.25, ease, stagger: 0.055 }, t + 0.25);
    tl.to(m, { opacity: 0, y: -40 * u, duration: 0.35, ease: 'power2.in' }, t + 1.85);
  });
  tl.to(maybe, { opacity: 0, duration: 0.3 }, T.prize - 0.4);

  // 3. Prizes: green room fades in, ribbon pops, gold shine sweeps, cards slide in
  tl.to(green, { opacity: 1, duration: 0.6, ease: 'sine.inOut' }, T.prize - 0.4);
  tl.fromTo(flash, { opacity: 0 }, { opacity: 0.3, duration: 0.08 }, T.prize);
  tl.to(flash, { opacity: 0, duration: 0.5, ease: 'power2.out' }, T.prize + 0.08);
  tl.fromTo(rib, { scale: 0.6, opacity: 0, rotation: -3 }, { scale: 1, opacity: 1, rotation: 0, duration: 0.7, ease: 'back.out(1.7)' }, T.prize);
  tl.fromTo(shine.querySelector('i'), { left: '-40%' }, { left: '130%', duration: 0.9, ease: 'power2.inOut' }, T.prize + 0.5);
  tl.fromTo(shine.querySelector('i'), { left: '-40%' }, { left: '130%', duration: 0.9, ease: 'power2.inOut', immediateRender: false }, T.prize + 2.6);
  tl.fromTo(prizeCards[0], { opacity: 0, x: -120 * u }, { opacity: 1, x: 0, duration: 0.65, ease }, T.prize + 0.55);
  tl.fromTo(prizeCards[1], { opacity: 0, x: 120 * u }, { opacity: 1, x: 0, duration: 0.65, ease }, T.prize + 0.8);
  tl.fromTo(plats, { opacity: 0, y: 20, scale: 0.9 }, { opacity: 1, y: 0, scale: 1, duration: 0.45, ease: 'back.out(2)', stagger: 0.12 }, T.prize + 1.35);
  tl.to(prize, { opacity: 0, y: -30 * u, duration: 0.4, ease: 'power2.in' }, T.how - 0.4);

  // 4. How: each step slides in, its number fills gold and turns into a tick
  tl.fromTo(how, { opacity: 0 }, { opacity: 1, duration: 0.3 }, T.how);
  steps.forEach((s, i) => {
    const t = T.how + 0.2 + i * 0.8;
    tl.fromTo(s, { opacity: 0, x: 80 * u }, { opacity: 1, x: 0, duration: 0.55, ease }, t);
    tl.to(s.querySelector('.fill'), { scale: 1, duration: 0.35, ease: 'back.out(2)' }, t + 0.45);
    tl.to(s.querySelector('.num'), { opacity: 0, duration: 0.15 }, t + 0.5);
    tl.fromTo(s.querySelector('svg'), { opacity: 0, scale: 0.4 }, { opacity: 1, scale: 1, duration: 0.3, ease: 'back.out(2.5)' }, t + 0.55);
  });
  tl.to(how, { opacity: 0, y: -30 * u, duration: 0.4, ease: 'power2.in' }, T.end - 0.4);

  // 5. End: logo assembles, CTA pops and breathes
  const L0 = T.end;
  tl.fromTo(P.camera, { opacity: 0, y: -40, scale: 0.7 }, { opacity: 1, y: 0, scale: 1, duration: 0.45, ease: 'back.out(1.8)' }, L0);
  tl.fromTo(flash, { opacity: 0 }, { opacity: 0.3, duration: 0.06 }, L0 + 0.4);
  tl.to(flash, { opacity: 0, duration: 0.4, ease: 'power2.out' }, L0 + 0.46);
  tl.fromTo(P.bowtie, { opacity: 0, scale: 0, rotation: -25 }, { opacity: 1, scale: 1, rotation: 0, duration: 0.45, ease: 'back.out(2.4)' }, L0 + 0.45);
  tl.fromTo([P['rule-left'], P['rule-right']], { opacity: 1, scaleX: 0 }, { scaleX: 1, duration: 0.5, ease }, L0 + 0.55);
  for (let i = 1; i <= 7; i++) tl.fromTo(P['letter-' + i], { opacity: 0, y: 30 }, { opacity: 1, y: 0, duration: 0.35, ease }, L0 + 0.6 + (i - 1) * 0.05);
  tl.fromTo(P.studios, { opacity: 0, scaleX: 1.25 }, { opacity: 1, scaleX: 1, duration: 0.5, ease }, L0 + 0.95);
  tl.fromTo(P['rule-bottom'], { opacity: 1, scaleX: 0 }, { scaleX: 1, duration: 0.4, ease }, L0 + 1.1);
  tl.fromTo(cta, { opacity: 0, y: 24, scale: 0.92 }, { opacity: 1, y: 0, scale: 1, duration: 0.55, ease: 'back.out(1.6)' }, L0 + 1.25);
  tl.fromTo(endline, { opacity: 0, y: 16 }, { opacity: 1, y: 0, duration: 0.5, ease }, L0 + 1.55);
  for (let t = L0 + 2.0; t < T.total - 0.3; t += 0.9) {
    tl.to(cta, { scale: 1.05, duration: 0.45, ease: 'sine.inOut' }, t);
    tl.to(cta, { scale: 1, duration: 0.45, ease: 'sine.inOut' }, t + 0.45);
  }

  tl.set({}, {}, T.total);
  window.__timelines = window.__timelines || {};
  window.__timelines.root = tl;
})();
