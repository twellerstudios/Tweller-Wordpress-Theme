#!/usr/bin/env python3
"""
Build the Christmas page's before/after (original/after) pairs.

    python3 tools/build-before-after.py <before-folder> <after-folder> 2 3 5 6

For each set N it reads before<N>.* and after<N>.*, finds the scale and offset
that line the people up (edge matching on the middle of the frame), crops both
to the area they share, and writes
tweller-bookings-wp/public/landing/christmas/before-after/ba<N>-before.webp and
ba<N>-after.webp (1200 px wide, under ~170 KB). Needs Pillow and numpy.
"""
import io
import sys
from pathlib import Path

import numpy as np
from PIL import Image

ROOT = Path(__file__).resolve().parent.parent
OUT = ROOT / "tweller-bookings-wp" / "public" / "landing" / "christmas" / "before-after"
W = 600      # search resolution
TW = 1200    # output width


def find(folder, stem):
    for p in sorted(Path(folder).iterdir()):
        if p.stem.lower() == stem and p.suffix.lower() in (".jpg", ".jpeg", ".png", ".webp", ".tif", ".tiff"):
            return p
    raise SystemExit(f"missing {stem} in {folder}")


def edges(im, w):
    im = im.convert("L").resize((w, int(round(im.height * w / im.width))), Image.LANCZOS)
    a = np.asarray(im, float)
    return np.abs(np.diff(a, axis=1))[:-1] + np.abs(np.diff(a, axis=0))[:, :-1]


def align(before, after):
    a = edges(after, W)
    h = a.shape[0]
    y0, y1, x0, x1 = int(h * .15), int(h * .95), int(W * .25), int(W * .75)
    best = None
    for sc in np.arange(0.90, 1.10, 0.004):
        b = edges(before, int(round(W * sc)))
        for dx in range(-16, 17):
            for dy in range(-16, 17):
                ox = (b.shape[1] - a.shape[1]) // 2 + dx
                oy = (b.shape[0] - h) // 2 + dy
                if oy + y0 < 0 or oy + y1 > b.shape[0] or ox + x0 < 0 or ox + x1 > b.shape[1]:
                    continue
                d = np.mean(np.abs(a[y0:y1, x0:x1] - b[oy + y0:oy + y1, ox + x0:ox + x1]))
                if best is None or d < best[0]:
                    best = (d, sc, dx, dy)
    return best


def save(im, path):
    for q in (80, 74, 68, 62):
        buf = io.BytesIO()
        im.save(buf, "WEBP", quality=q, method=6)
        if buf.tell() < 170 * 1024:
            break
    path.write_bytes(buf.getvalue())


def main():
    if len(sys.argv) < 4:
        raise SystemExit(__doc__)
    bdir, adir, sets = sys.argv[1], sys.argv[2], sys.argv[3:]
    OUT.mkdir(parents=True, exist_ok=True)
    for n in sets:
        B = Image.open(find(bdir, f"before{n}")).convert("RGB")
        A = Image.open(find(adir, f"after{n}")).convert("RGB")
        d, sc, dx, dy = align(B, A)
        k = TW / W
        a = A.resize((TW, round(A.height * TW / A.width)), Image.LANCZOS)
        bw = round(TW * sc)
        b = B.resize((bw, round(B.height * bw / B.width)), Image.LANCZOS)
        ox = (b.width - a.width) // 2 + round(dx * k)
        oy = (b.height - a.height) // 2 + round(dy * k)
        x0, y0 = max(0, -ox), max(0, -oy)
        x1, y1 = min(a.width, b.width - ox), min(a.height, b.height - oy)
        ac = a.crop((x0, y0, x1, y1))
        bc = b.crop((x0 + ox, y0 + oy, x1 + ox, y1 + oy))
        save(ac, OUT / f"ba{n}-after.webp")
        save(bc, OUT / f"ba{n}-before.webp")
        print(f"set {n}: scale {sc:.3f} dx {dx} dy {dy} (match {d:.2f}) -> {ac.size}")


if __name__ == "__main__":
    main()
