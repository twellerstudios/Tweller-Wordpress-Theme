#!/usr/bin/env python3
"""
Prepare the 4 scene originals for the ad compositions.

    python3 ads/christmas-2026/prepare-assets.py "C:/Users/Tweller/Pictures/CHRISTMAS BACKGROUNDS"

Writes assets/scenes/<slug>.jpg (2700x1800, high quality) next to this
script. The 9:16 frame crops a third of the width, so the originals'
resolution is kept high enough to stay sharp at 1080 wide.
"""
import sys
from pathlib import Path
from PIL import Image, ImageOps

HERE = Path(__file__).resolve().parent
SCENES = {
    "classic-red-hearth": "341721539",
    "fireside-library": "1660309657",
    "snowy-window": "1112374189",
    "champagne-gold": "678360445",
}

def main():
    if len(sys.argv) < 2:
        raise SystemExit(__doc__)
    src = Path(sys.argv[1])
    out = HERE / "assets" / "scenes"
    out.mkdir(parents=True, exist_ok=True)
    files = [p for p in src.iterdir() if p.suffix.lower() in (".png", ".jpg", ".jpeg", ".webp")]
    for slug, fid in SCENES.items():
        match = [p for p in files if fid in p.stem or slug in p.stem.lower()]
        if not match:
            raise SystemExit(f"missing source for {slug}")
        im = ImageOps.exif_transpose(Image.open(match[0])).convert("RGB")
        w, h = im.size
        if abs(w / h - 1.5) > 0.01:  # centre-crop to 3:2
            if w / h > 1.5:
                nw = round(h * 1.5); im = im.crop(((w - nw) // 2, 0, (w - nw) // 2 + nw, h))
            else:
                nh = round(w / 1.5); im = im.crop((0, (h - nh) // 2, w, (h - nh) // 2 + nh))
        im = im.resize((2700, 1800), Image.LANCZOS) if im.size[0] > 2700 else im
        im.save(out / f"{slug}.jpg", "JPEG", quality=90, optimize=True, progressive=True)
        print(slug, im.size, match[0].name)

if __name__ == "__main__":
    main()
