"""
Rendert das Save-the-Date-Sujet in allen Zielformaten.

Erzeugt zuerst eine Papierfaser-Textur (Pillow), legt sie als data-URI in die
HTML-Vorlage und schießt dann mit Chrome headless PNGs in exakter Pixelgröße.

    python tools/build_sujet.py

Ergebnis liegt in tools/out/.
"""

import base64
import io
import os
import random
import subprocess
import sys
from pathlib import Path

from PIL import Image, ImageFilter

ROOT = Path(__file__).resolve().parent
OUT = ROOT / "out"

FORMATS = [
    # Name,                       Breite, Höhe,  wofür
    ("wirtshausquiz-save-the-date-feed", 1080, 1350, "Instagram/Facebook Feed (4:5)"),
    ("wirtshausquiz-save-the-date-quadrat", 1080, 1080, "Feed quadratisch (1:1)"),
    ("wirtshausquiz-save-the-date-story", 1080, 1920, "Story / Reel-Cover (9:16)"),
]

CHROME_CANDIDATES = [
    r"C:\Program Files\Google\Chrome\Application\chrome.exe",
    r"C:\Program Files (x86)\Google\Chrome\Application\chrome.exe",
    r"C:\Program Files\Microsoft\Edge\Application\msedge.exe",
    r"C:\Program Files (x86)\Microsoft\Edge\Application\msedge.exe",
]


def find_browser():
    for p in CHROME_CANDIDATES:
        if os.path.isfile(p):
            return p
    sys.exit("Kein Chrome/Edge gefunden — bitte Pfad in CHROME_CANDIDATES ergänzen.")


def paper_noise(size=160, strength=26, seed=7):
    """Feine Papierfaser: fast weiß, mit unregelmäßig dunkleren Fasern."""
    random.seed(seed)
    img = Image.new("L", (size, size), 255)
    px = img.load()
    for y in range(size):
        for x in range(size):
            px[x, y] = 255 - random.randint(0, strength)
    # Ein Hauch Unschärfe macht aus Rauschen Fasern.
    img = img.filter(ImageFilter.GaussianBlur(0.4))
    rgb = Image.merge("RGB", (img, img, img))
    buf = io.BytesIO()
    rgb.save(buf, format="PNG", optimize=True)
    return base64.b64encode(buf.getvalue()).decode("ascii")


def main():
    OUT.mkdir(exist_ok=True)

    noise = paper_noise()
    tpl = (ROOT / "sujet.html").read_text(encoding="utf-8")
    marker = "  :root {"
    injected = tpl.replace(
        marker,
        marker + '\n    --noise: url("data:image/png;base64,' + noise + '");',
        1,
    )
    built = ROOT / "_sujet.built.html"
    built.write_text(injected, encoding="utf-8")

    browser = find_browser()
    print("Renderer:", browser)

    for name, w, h, label in FORMATS:
        target = OUT / (name + ".png")
        url = built.as_uri() + "?w={}&h={}".format(w, h)
        cmd = [
            browser,
            "--headless=new",
            "--disable-gpu",
            "--hide-scrollbars",
            "--force-device-scale-factor=1",
            "--default-background-color=00000000",
            "--virtual-time-budget=6000",
            "--window-size={},{}".format(w, h),
            "--screenshot=" + str(target),
            url,
        ]
        subprocess.run(cmd, capture_output=True, timeout=120)
        if target.is_file():
            got = Image.open(target).size
            print("  {:<40} {}x{}  {}".format(target.name, got[0], got[1], label))
        else:
            print("  FEHLGESCHLAGEN:", target.name)

    built.unlink(missing_ok=True)


if __name__ == "__main__":
    main()
