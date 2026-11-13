#!/usr/bin/env node
// Builds every Christmas ad deliverable into ./out (or OUT_DIR):
//   xmas_scene_tour_9x16.mp4 / _4x5.mp4          silent (pick music in Ads Manager)
//   xmas_scene_tour_9x16_vo.mp4 / _4x5_vo.mp4    with voiceover
//   xmas_scene_tour_thumb.jpg                    1080x1920 hook frame
//   xmas_carousel_01..06.jpg, xmas_pick_scene_4x5.jpg
//
//   node render.mjs            everything
//   node render.mjs --stills   carousel + fallback only
//   node render.mjs --vo       rebuild the voiceover track only
// Needs: Node 22+, FFmpeg (with libflite for the voiceover), `npm i` here.
import { execFileSync } from 'node:child_process';
import { mkdirSync, writeFileSync, readFileSync, statSync, existsSync } from 'node:fs';
import { join, resolve, dirname } from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';
import { createRequire } from 'node:module';

const HERE = dirname(fileURLToPath(import.meta.url));
const OUT = resolve(process.env.OUT_DIR || join(HERE, 'out'));
const TMP = join(OUT, '.work');
mkdirSync(TMP, { recursive: true });
const args = new Set(process.argv.slice(2));
const all = !args.has('--stills') && !args.has('--vo');
const run = (cmd, a, opts = {}) => execFileSync(cmd, a, { stdio: 'inherit', cwd: HERE, ...opts });
const ff = (a) => run('ffmpeg', ['-nostdin', '-v', 'error', '-y', ...a]);
const hfBin = process.env.HYPERFRAMES_BIN || 'hyperframes';

function buildVoiceover() {
  const lines = readFileSync(join(HERE, 'vo-script.txt'), 'utf8').split('\n')
    .filter((l) => l.trim() && !l.startsWith('#')).map((l) => { const [t, ...x] = l.split('|'); return { t: +t, text: x.join('|').trim() }; });
  const inputs = [], chains = [];
  lines.forEach((l, i) => {
    const txt = join(TMP, `vo${i}.txt`), wav = join(TMP, `vo${i}.wav`);
    writeFileSync(txt, l.text);
    ff(['-f', 'lavfi', '-i', `flite=textfile=${txt}:voice=slt`, '-af',
      'silenceremove=start_periods=1:start_threshold=-50dB,areverse,silenceremove=start_periods=1:start_threshold=-50dB,areverse,atempo=1.12',
      '-ar', '48000', '-ac', '1', wav]);
    inputs.push('-i', wav);
    const ms = Math.round(l.t * 1000);
    chains.push(`[${i}:a]adelay=${ms}|${ms}[a${i}]`);
  });
  const mix = lines.map((_, i) => `[a${i}]`).join('') + `amix=inputs=${lines.length}:normalize=0,` +
    'asetrate=48000*0.96,aresample=48000,atempo=1.0417,highpass=f=85,lowpass=f=8500,' +
    'equalizer=f=220:t=q:w=1:g=2.5,equalizer=f=3200:t=q:w=1.2:g=2,' +
    'acompressor=threshold=-20dB:ratio=3:attack=5:release=120,aecho=0.85:0.6:38:0.10,' +
    'apad=whole_dur=15,atrim=0:15,loudnorm=I=-16:TP=-1.5:LRA=7,aresample=48000';
  const out = join(OUT, 'xmas_scene_tour_voiceover.wav');
  ff([...inputs, '-filter_complex', chains.join(';') + ';' + mix, '-ac', '2', '-c:a', 'pcm_s16le', out]);
  return out;
}

function renderReel(fmt) {
  const silent = join(OUT, `xmas_scene_tour_${fmt}.mp4`);
  const raw = join(TMP, `reel_${fmt}.mp4`);
  run(hfBin, ['render', '-c', `reel-${fmt}.html`, '-q', 'high', '-f', '30', '-o', raw], { shell: process.platform === 'win32' });
  // Meta-friendly H.264: yuv420p, faststart, a silent AAC track so every placement accepts it.
  ff(['-i', raw, '-f', 'lavfi', '-i', 'anullsrc=r=48000:cl=stereo', '-map', '0:v', '-map', '1:a', '-shortest',
    '-c:v', 'libx264', '-preset', 'slow', '-crf', '18', '-profile:v', 'high', '-pix_fmt', 'yuv420p', '-r', '30',
    '-c:a', 'aac', '-b:a', '128k', '-movflags', '+faststart', silent]);
  return silent;
}

function addVo(silent, vo) {
  const out = silent.replace(/\.mp4$/, '_vo.mp4');
  ff(['-i', silent, '-i', vo, '-map', '0:v', '-map', '1:a', '-c:v', 'copy', '-c:a', 'aac', '-b:a', '192k', '-shortest', '-movflags', '+faststart', out]);
  return out;
}

async function stills() {
  // Local install first; a global one (NODE_PATH) otherwise.
  let pw;
  try { pw = await import('playwright'); } catch { pw = createRequire(import.meta.url)('playwright'); }
  const { chromium } = pw;
  const b = await chromium.launch();
  const page = await b.newPage({ deviceScaleFactor: 1 });
  const url = pathToFileURL(join(HERE, 'carousel.html')).href;
  const jobs = [1, 2, 3, 4, 5, 6].map((n) => ({ q: `card=${n}`, w: 1080, h: 1080, file: `xmas_carousel_0${n}.jpg` }));
  jobs.push({ q: 'card=1&fmt=4x5', w: 1080, h: 1350, file: 'xmas_pick_scene_4x5.jpg' });
  for (const j of jobs) {
    await page.setViewportSize({ width: j.w, height: j.h });
    await page.goto(`${url}?${j.q}`, { waitUntil: 'load' });
    await page.evaluate(() => document.fonts.ready);
    const png = join(TMP, j.file.replace('.jpg', '.png'));
    await page.screenshot({ path: png, clip: { x: 0, y: 0, width: j.w, height: j.h } });
    // Highest JPEG quality that stays under 1 MB.
    for (const q of [2, 3, 4, 5, 6]) {
      ff(['-i', png, '-pix_fmt', 'yuvj420p', '-q:v', String(q), join(OUT, j.file)]);
      if (statSync(join(OUT, j.file)).size < 1000 * 1024) break;
    }
  }
  await b.close();
}

if (all || args.has('--vo') || args.has('--reels')) {
  const vo = buildVoiceover();
  if (all || args.has('--reels')) {
    for (const fmt of ['9x16', '4x5']) addVo(renderReel(fmt), vo);
    ff(['-ss', '1.0', '-i', join(OUT, 'xmas_scene_tour_9x16.mp4'), '-frames:v', '1', '-q:v', '2', join(OUT, 'xmas_scene_tour_thumb.jpg')]);
  } else {
    for (const fmt of ['9x16', '4x5']) { const s = join(OUT, `xmas_scene_tour_${fmt}.mp4`); if (existsSync(s)) addVo(s, vo); }
  }
}
if (all || args.has('--stills')) await stills();
console.log('Done →', OUT);
