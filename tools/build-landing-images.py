#!/usr/bin/env python3
"""
Build the Christmas landing page's web images from the 4 scene originals.

    python3 tools/build-landing-images.py "C:/Users/Tweller/Pictures/CHRISTMAS BACKGROUNDS"

Writes into tweller-bookings-wp/public/landing/christmas/:
    <slug>-1600.webp           1600x1067  hero / viewer   (target <= 200 KB)
    <slug>-800.webp             800x533   hero on small screens, card srcset
    <slug>-600.webp             600x400   scene card thumbnail
    <slug>-portrait-900.webp    900x1350  phone hero, cropped on the focal point
    og-1200x630.jpg                       link preview (Classic Red Hearth)

Needs Pillow (pip install pillow). Source files are matched by the IDs in
their names, so the long "remove the person and give me th …" names work
as they are; a file already named after its slug works too.
"""
import io
import sys
from pathlib import Path

from PIL import Image, ImageOps

ROOT = Path(__file__).resolve().parent.parent
OUT = ROOT / "tweller-bookings-wp" / "public" / "landing" / "christmas"

# slug, id in the original file name, focal point (x, y as 0..1)
SCENES = [
    ("classic-red-hearth", "341721539", (0.50, 0.55)),
    ("fireside-library",   "1660309657", (0.52, 0.58)),
    ("snowy-window",       "1112374189", (0.48, 0.52)),
    ("champagne-gold",     "678360445",  (0.42, 0.55)),
]


def find_source(src_dir: Path, slug: str, file_id: str) -> Path:
    for p in sorted(src_dir.iterdir()):
        if p.suffix.lower() not in (".png", ".jpg", ".jpeg", ".webp"):
            continue
        name = p.stem.lower()
        if file_id in name or slug in name:
            return p
    raise SystemExit(f"No source image for {slug} (looked for '{file_id}' or '{slug}' in {src_dir})")


def crop_to(img: Image.Image, ratio: float, focus) -> Image.Image:
    """Largest crop of the given width/height ratio, centred on focus."""
    w, h = img.size
    if w / h > ratio:
        cw, ch = round(h * ratio), h
    else:
        cw, ch = w, round(w / ratio)
    cx = min(max(focus[0] * w - cw / 2, 0), w - cw)
    cy = min(max(focus[1] * h - ch / 2, 0), h - ch)
    return img.crop((round(cx), round(cy), round(cx) + cw, round(cy) + ch))


def save_webp(img: Image.Image, path: Path, max_kb: int):
    """Highest quality that fits under max_kb."""
    for q in (82, 78, 74, 70, 66, 62, 58, 54, 50):
        buf = io.BytesIO()
        img.save(buf, "WEBP", quality=q, method=6)
        if buf.tell() <= max_kb * 1024 or q == 50:
            path.write_bytes(buf.getvalue())
            return q, buf.tell()


def save_jpg(img: Image.Image, path: Path, max_kb: int):
    for q in (86, 82, 78, 74, 70, 66):
        buf = io.BytesIO()
        img.save(buf, "JPEG", quality=q, optimize=True, progressive=True)
        if buf.tell() <= max_kb * 1024 or q == 66:
            path.write_bytes(buf.getvalue())
            return q, buf.tell()


def main():
    if len(sys.argv) < 2:
        raise SystemExit(__doc__)
    src_dir = Path(sys.argv[1])
    OUT.mkdir(parents=True, exist_ok=True)

    for slug, file_id, focus in SCENES:
        src = find_source(src_dir, slug, file_id)
        img = ImageOps.exif_transpose(Image.open(src)).convert("RGB")
        land = crop_to(img, 3 / 2, focus)
        jobs = [
            (land.resize((1600, 1067), Image.LANCZOS), f"{slug}-1600.webp", 200),
            (land.resize((800, 533), Image.LANCZOS), f"{slug}-800.webp", 90),
            (land.resize((600, 400), Image.LANCZOS), f"{slug}-600.webp", 60),
            (crop_to(img, 2 / 3, focus).resize((900, 1350), Image.LANCZOS), f"{slug}-portrait-900.webp", 160),
        ]
        for im, name, kb in jobs:
            q, size = save_webp(im, OUT / name, kb)
            print(f"{name:34s} {im.size[0]}x{im.size[1]}  q{q}  {size / 1024:.0f} KB")
        if slug == "classic-red-hearth":
            og = crop_to(img, 1200 / 630, focus).resize((1200, 630), Image.LANCZOS)
            q, size = save_jpg(og, OUT / "og-1200x630.jpg", 250)
            print(f"{'og-1200x630.jpg':34s} 1200x630  q{q}  {size / 1024:.0f} KB")


if __name__ == "__main__":
    main()
