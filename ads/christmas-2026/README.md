# Christmas 2026 ads

Two Meta ads (Facebook + Instagram) for **Christmas Minis from TTD 550**. Both send people to
https://www.twellerstudios.com/book-us/christmas-photos/ with the **Book Now** button.

| File | What | Size |
|---|---|---|
| `xmas_scene_tour_9x16.mp4` | Reel / Stories, silent (add music in Ads Manager) | 1080×1920, 15 s |
| `xmas_scene_tour_9x16_vo.mp4` | Same, with voiceover | 1080×1920, 15 s |
| `xmas_scene_tour_4x5.mp4` | Feed, silent | 1080×1350, 15 s |
| `xmas_scene_tour_4x5_vo.mp4` | Feed, with voiceover | 1080×1350, 15 s |
| `xmas_scene_tour_thumb.jpg` | Reel cover (hook frame) | 1080×1920 |
| `xmas_scene_tour_voiceover.wav` | The voice track alone (−16 LUFS) | 15 s |
| `xmas_carousel_01…06.jpg` | "Pick Your Scene" carousel | 1080×1080, each < 1 MB |
| `xmas_pick_scene_4x5.jpg` | Single-image fallback | 1080×1350 |

## Build

```bash
npm install
python3 prepare-assets.py "C:/Users/Tweller/Pictures/CHRISTMAS BACKGROUNDS"
OUT_DIR="C:/Users/Tweller/Pictures/CHRISTMAS BACKGROUNDS/ads_final" node render.mjs
```

`node render.mjs --stills` rebuilds only the carousel; `--vo` only the voiceover (edit `vo-script.txt`).

## What's where

- `reel-9x16.html`, `reel-4x5.html`: HyperFrames compositions; the motion lives in `lib/reel.js`, styles in `lib/reel.css`.
  Text stays out of the top 14 % and bottom 35 % of the 9:16 frame (Reels interface).
- `carousel.html`: the 6 cards and the 4:5 fallback (`?card=1..6`, `&fmt=4x5`).
- Client photos later: set the composition variable `family_<scene-slug>` to a transparent PNG cut-out, and it
  appears in front of that scene. For the carousel, add `&photo=<png>` to a scene card.
- Voiceover: ffmpeg's built-in `flite` voice (`slt`), pitched slightly down, EQ'd and compressed. For music, choose a
  track from Meta's Sound Collection in Ads Manager (no copyrighted songs).
