#!/usr/bin/env python3
"""
Builds every icon file from design/app-icon-source.png (one picture holding a large and a small
version of the icon on white). Developer tool only: the app itself needs none of this. Needs Pillow
and numpy.

  python3 bin/make_icons.py

Writes into web/assets/ and web/favicon.ico:
  icon-512.png, icon-192.png     the large icon, rounded corners transparent  (web app manifest)
  icon-maskable-512.png          full-bleed background, artwork inside the safe zone (Android "maskable")
  apple-touch-icon.png           180x180, opaque square, iOS rounds it itself
  favicon-32.png, favicon-48.png the SMALL icon, for browser tabs
  ../favicon.ico                 16, 32 and 48 px of the small icon in one file

If the source picture changes, update the two boxes below (left, top, right, bottom in pixels).
"""
import os
import struct
import io
import numpy as np
from PIL import Image, ImageDraw, ImageFilter

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
SRC = os.path.join(ROOT, 'design', 'app-icon-source.png')
ASSETS = os.path.join(ROOT, 'web', 'assets')

BIG_BOX = (96, 216, 875, 992)       # the large icon, tight around its rounded square
SMALL_BOX = (947, 700, 1198, 954)   # the small icon


def with_transparent_corners(img):
    """Makes the white outside the rounded square transparent, with a clean edge."""
    rgb = img.convert('RGB')
    marked = rgb.copy()
    for corner in [(0, 0), (rgb.width - 1, 0), (0, rgb.height - 1), (rgb.width - 1, rgb.height - 1)]:
        ImageDraw.floodfill(marked, corner, (255, 0, 255), thresh=60)
    m = np.array(marked)
    outside = (m[:, :, 0] == 255) & (m[:, :, 1] == 0) & (m[:, :, 2] == 255)
    alpha = Image.fromarray(np.where(outside, 0, 255).astype('uint8'))
    alpha = alpha.filter(ImageFilter.MinFilter(3)).filter(ImageFilter.GaussianBlur(0.9))   # drop the white halo, soften
    out = rgb.copy()
    out.putalpha(alpha)
    return out


def square_inset(img, fraction):
    """The largest centred square that stays inside the rounded corners."""
    inset = int(img.width * fraction)
    return img.crop((inset, inset, img.width - inset, img.height - inset)).convert('RGB')


def clean_corners(core, k=72):
    """
    The source picture is a rounded square on white, so the very corners of a square cut from it carry
    light pixels. Repaint each k-pixel corner patch with the plain background strip beside it along the
    same edge (the artwork's ring stays well clear of those strips), with a soft join.
    """
    core = core.copy()
    w, h = core.size
    # (patch x, patch y, source x): the source block sits one patch-width further along the same edge
    for (x, y, sx) in [(0, 0, k), (w - k, 0, w - 2 * k), (0, h - k, k), (w - k, h - k, w - 2 * k)]:
        patch = core.crop((sx, y, sx + k, y + k))
        mask = Image.new('L', (k, k), 0)
        ImageDraw.Draw(mask).rectangle((6, 6, k - 1, k - 1), fill=255)          # soft join on the inner sides only
        core.paste(patch, (x, y), mask.filter(ImageFilter.GaussianBlur(4)))
    return core


def maskable_canvas(core, fraction, size):
    """
    The artwork centred on a full-bleed square. The margin is the picture's own edge colours carried
    outward (edge pixels repeated, then blurred), so there is no visible seam where the artwork ends.
    """
    pad = int(round(core.width * (1 / fraction - 1) / 2))
    padded = Image.fromarray(np.pad(np.array(core), ((pad, pad), (pad, pad), (0, 0)), mode='edge'))
    margin = padded.filter(ImageFilter.GaussianBlur(max(6, pad // 5)))
    feather = Image.new('L', core.size, 0)
    ImageDraw.Draw(feather).rectangle((4, 4, core.width - 5, core.height - 5), fill=255)
    feather = feather.filter(ImageFilter.GaussianBlur(2.5))
    margin.paste(core, (pad, pad), feather)
    return margin.resize((size, size), Image.LANCZOS)


def save_png(img, name):
    img.save(os.path.join(ASSETS, name), optimize=True)
    print('wrote', name, img.size)


def write_ico(path, images):
    """A .ico holding PNG-compressed images (all sizes < 256)."""
    blobs = []
    for im in images:
        buf = io.BytesIO()
        im.save(buf, 'PNG', optimize=True)
        blobs.append((im.width, im.height, buf.getvalue()))
    out = struct.pack('<HHH', 0, 1, len(blobs))
    offset = 6 + 16 * len(blobs)
    for w, h, data in blobs:
        out += struct.pack('<BBBBHHII', w, h, 0, 0, 1, 32, len(data), offset)
        offset += len(data)
    out += b''.join(d for _, _, d in blobs)
    open(path, 'wb').write(out)
    print('wrote', os.path.relpath(path, ROOT), [im.size for im in images])


def main():
    src = Image.open(SRC).convert('RGB')
    big = src.crop(BIG_BOX)
    small = src.crop(SMALL_BOX)

    # --- app icon ---
    big_t = with_transparent_corners(big)
    save_png(big_t.resize((512, 512), Image.LANCZOS), 'icon-512.png')
    save_png(big_t.resize((192, 192), Image.LANCZOS), 'icon-192.png')

    core = clean_corners(square_inset(big, 0.07))                    # no rounded corners in it
    save_png(core.resize((180, 180), Image.LANCZOS), 'apple-touch-icon.png')

    # maskable: Android may crop to a circle of 80% of the width. The ring of the artwork is ~47% of
    # the artwork's width in radius, so at 78% of the canvas it reaches ~37%, inside that 40% circle.
    canvas = maskable_canvas(core, 0.78, 512)
    save_png(canvas, 'icon-maskable-512.png')

    # --- favicon: the small icon ---
    small_t = with_transparent_corners(small)
    save_png(small_t.resize((32, 32), Image.LANCZOS), 'favicon-32.png')
    save_png(small_t.resize((48, 48), Image.LANCZOS), 'favicon-48.png')
    write_ico(os.path.join(ROOT, 'web', 'favicon.ico'), [small_t.resize((s, s), Image.LANCZOS) for s in (16, 32, 48)])


if __name__ == '__main__':
    main()
