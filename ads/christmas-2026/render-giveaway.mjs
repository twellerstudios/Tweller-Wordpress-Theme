#!/usr/bin/env node
// Christmas Memory Giveaway media → ./out/giveaway (or OUT_DIR)
//   node render-giveaway.mjs            stills + reels
//   node render-giveaway.mjs --stills   stills only (SCALE=1 for quick previews)
//   node render-giveaway.mjs --reels    reels only
import { execFileSync } from 'node:child_process';
import { mkdirSync, statSync, existsSync, readFileSync } from 'node:fs';
import { join, resolve, dirname } from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';
import { createRequire } from 'node:module';

const HERE = dirname(fileURLToPath(import.meta.url));
const OUT = resolve(process.env.OUT_DIR || join(HERE, 'out', 'giveaway'));
const TMP = join(OUT, '.work');
mkdirSync(TMP, { recursive: true });
const args = new Set(process.argv.slice(2));
const all = !args.has('--stills') && !args.has('--reels');
const SCALE = Number(process.env.SCALE || 2);
const ff = (a) => execFileSync('ffmpeg', ['-nostdin', '-v', 'error', '-y', ...a], { stdio: 'inherit' });

async function stills() {
  let pw;
  try { pw = await import('playwright'); } catch { pw = createRequire(import.meta.url)('playwright'); }
  const b = await pw.chromium.launch();
  const page = await b.newPage({ deviceScaleFactor: SCALE });
  const H = { '4x5': 1350, '1x1': 1080, '9x16': 1920 };
  const jobs = [
    ['launch', '4x5', ''], ['launch', '1x1', ''], ['launch', '9x16', ''], ['launch', '4x5', 'fb'], ['launch', '1x1', 'fb'],
    ['memory', '9x16', ''], ['leaderboard', '9x16', ''], ['last48', '9x16', ''], ['last48', '4x5', ''],
    ['livedraw', '9x16', ''], ['winners', '4x5', ''], ['winners', '9x16', ''],
  ];
  for (const [k, fmt, plat] of jobs) {
    await page.setViewportSize({ width: 1080, height: H[fmt] });
    await page.goto(pathToFileURL(join(HERE, 'giveaway.html')).href + `?k=${k}&fmt=${fmt}${plat ? '&plat=' + plat : ''}`, { waitUntil: 'load' });
    await page.evaluate(() => document.fonts.ready);
    const name = `giveaway_${k}_${fmt}${plat ? '_' + plat : ''}`;
    const png = join(TMP, name + '.png');
    await page.screenshot({ path: png, clip: { x: 0, y: 0, width: 1080, height: H[fmt] } });
    for (const q of [2, 3, 4, 5]) {
      ff(['-i', png, '-pix_fmt', 'yuvj420p', '-q:v', String(q), join(OUT, name + '.jpg')]);
      if (statSync(join(OUT, name + '.jpg')).size < 4000 * 1024) break;
    }
    console.log('still', name);
  }
  await b.close();
}

function reel(fmt) {
  const raw = join(TMP, `giveaway_reel_${fmt}.mp4`);
  execFileSync(process.env.HYPERFRAMES_BIN || 'npx', [...(process.env.HYPERFRAMES_BIN ? [] : ['--yes', 'hyperframes@0.9.41']), 'render', '-c', `giveaway-reel-${fmt}.html`, '-q', 'high', '-f', '30', '-o', raw], { cwd: HERE, stdio: 'inherit' });
  const silent = join(OUT, `giveaway_reel_${fmt}_silent.mp4`);
  ff(['-i', raw, '-f', 'lavfi', '-i', 'anullsrc=r=48000:cl=stereo', '-map', '0:v', '-map', '1:a', '-shortest',
    '-c:v', 'libx264', '-preset', 'slow', '-crf', '18', '-profile:v', 'high', '-pix_fmt', 'yuv420p', '-r', '30', '-c:a', 'aac', '-b:a', '128k', '-movflags', '+faststart', silent]);
  // Music: the owner's Jingle Bells track (not in the repo), ending on its real ending.
  let cfg = {}; try { cfg = JSON.parse(readFileSync(join(HERE, 'music.json'), 'utf8')); } catch {}
  const music = cfg.file ? join(HERE, cfg.file) : '';
  if (music && existsSync(music)) {
    const dur = Number(execFileSync('ffprobe', ['-v', 'error', '-show_entries', 'format=duration', '-of', 'csv=p=0', music]).toString());
    const LEN = 20;
    ff(['-i', silent, '-ss', String(Math.max(0, dur - LEN)), '-t', String(LEN), '-i', music, '-map', '0:v', '-map', '1:a', '-c:v', 'copy',
      '-af', `afade=t=in:d=0.4,afade=t=out:st=${LEN - 1.2}:d=1.2,volume=0.8,loudnorm=I=-16:TP=-1.5:LRA=9`, '-ar', '48000', '-c:a', 'aac', '-b:a', '192k', '-shortest', '-movflags', '+faststart',
      join(OUT, `giveaway_reel_${fmt}.mp4`)]);
  }
  console.log('reel', fmt);
}

if (all || args.has('--stills')) await stills();
if (all || args.has('--reels')) { for (const f of ['9x16', '4x5']) reel(f); ff(['-ss', '1.6', '-i', join(OUT, 'giveaway_reel_9x16_silent.mp4'), '-frames:v', '1', '-q:v', '2', join(OUT, 'giveaway_reel_cover.jpg')]); }
console.log('Done →', OUT);
