"""
Rendert alle Werbemotive fürs Wirtshausquiz.

    python tools/build_motive.py            # alles
    python tools/build_motive.py plakat     # nur ein Motiv

Erzeugt Papierfaser-Textur und QR-Code, legt beide als data-URI in die
Vorlagen und schießt dann mit Chrome headless PNGs in exakter Pixelgröße —
beim Plakat zusätzlich ein A3-PDF für die Druckerei.

Der QR zeigt bewusst NICHT direkt auf quiz.team-michelhausen.at, sondern auf
den tm_go-Kurzlink: nur so ist messbar, wie viele Leute über das Plakat
gekommen sind, und das Ziel lässt sich später ändern, ohne neu zu drucken.
Der Slug muss vorher in tm_go angelegt sein, sonst führt der Code ins Leere.
"""

import base64
import io
import os
import random
import subprocess
import sys
from pathlib import Path

import qrcode
from PIL import Image, ImageFilter

ROOT = Path(__file__).resolve().parent
OUT = ROOT / "out"

# tm_go-Kurzlink. Slug muss im tm_go-Backoffice existieren und auf
# https://quiz.team-michelhausen.at zeigen.
GO_SLUG = "quiz"
GO_URL = "https://go.team-michelhausen.at/" + GO_SLUG + "?s=plakat"

MOTIVE = {
    "savethedate": {
        "template": "sujet.html",
        "renders": [
            ("wirtshausquiz-save-the-date-feed", 1080, 1350, "Feed 4:5"),
            ("wirtshausquiz-save-the-date-quadrat", 1080, 1080, "Feed 1:1"),
            ("wirtshausquiz-save-the-date-story", 1080, 1920, "Story 9:16"),
        ],
    },
    "anmeldung": {
        "template": "anmeldung.html",
        "renders": [
            ("wirtshausquiz-anmeldung-feed", 1080, 1350, "Feed 4:5"),
            ("wirtshausquiz-anmeldung-quadrat", 1080, 1080, "Feed 1:1"),
            ("wirtshausquiz-anmeldung-story", 1080, 1920, "Story 9:16"),
        ],
    },
    "plakat": {
        "template": "plakat.html",
        # A3 = 297x420mm. CSS rechnet 96dpi, also 1122x1587 CSS-Pixel.
        # Scale 3 ergibt rund 288 dpi — mehr als jede Druckerei braucht.
        "renders": [("wirtshausquiz-plakat-a3", 1122, 1587, "A3 Vorschau")],
        "scale": 3,
        "pdf": "wirtshausquiz-plakat-a3",
    },
}

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
    sys.exit("Kein Chrome/Edge gefunden.")


def paper_noise(size=160, strength=26, seed=7):
    """Feine Papierfaser: fast weiß, mit unregelmäßig dunkleren Fasern."""
    random.seed(seed)
    img = Image.new("L", (size, size), 255)
    px = img.load()
    for y in range(size):
        for x in range(size):
            px[x, y] = 255 - random.randint(0, strength)
    img = img.filter(ImageFilter.GaussianBlur(0.4))
    buf = io.BytesIO()
    Image.merge("RGB", (img, img, img)).save(buf, format="PNG", optimize=True)
    return base64.b64encode(buf.getvalue()).decode("ascii")


def qr_datauri(url):
    """Fehlerkorrektur Q: der Code bleibt lesbar, auch wenn das Plakat leidet."""
    q = qrcode.QRCode(
        error_correction=qrcode.constants.ERROR_CORRECT_Q, box_size=16, border=1
    )
    q.add_data(url)
    q.make(fit=True)
    img = q.make_image(fill_color="#3f2c20", back_color="white").convert("RGB")
    buf = io.BytesIO()
    img.save(buf, format="PNG", optimize=True)
    return "data:image/png;base64," + base64.b64encode(buf.getvalue()).decode("ascii")


def build(name, spec, browser, noise, qr):
    tpl = (ROOT / spec["template"]).read_text(encoding="utf-8")
    # Bewusst Verkettung statt .format(): die CSS-Klammer in ":root {" wäre
    # sonst eine Formatangabe.
    injected = tpl.replace(
        "  :root {",
        '  :root {\n    --noise: url("data:image/png;base64,' + noise + '");'
        '\n    --qr: url("' + qr + '");',
        1,
    )
    built = ROOT / ("_" + name + ".built.html")
    built.write_text(injected, encoding="utf-8")
    scale = spec.get("scale", 1)

    try:
        for fname, w, h, label in spec["renders"]:
            target = OUT / (fname + ".png")
            url = built.as_uri() + "?w={}&h={}".format(w, h)
            subprocess.run(
                [
                    browser,
                    "--headless=new",
                    "--disable-gpu",
                    "--hide-scrollbars",
                    "--force-device-scale-factor=" + str(scale),
                    "--virtual-time-budget=7000",
                    "--window-size={},{}".format(w, h),
                    "--screenshot=" + str(target),
                    url,
                ],
                capture_output=True,
                timeout=180,
            )
            if target.is_file():
                got = Image.open(target).size
                kb = target.stat().st_size / 1024
                print("  {:<44} {}x{}  {:>6.0f} KB  {}".format(
                    target.name, got[0], got[1], kb, label))
            else:
                print("  FEHLGESCHLAGEN:", target.name)

        if spec.get("pdf"):
            pdf = OUT / (spec["pdf"] + ".pdf")
            subprocess.run(
                [
                    browser,
                    "--headless=new",
                    "--disable-gpu",
                    "--no-pdf-header-footer",
                    "--virtual-time-budget=7000",
                    "--print-to-pdf=" + str(pdf),
                    built.as_uri(),
                ],
                capture_output=True,
                timeout=180,
            )
            if pdf.is_file():
                print("  {:<44} {:>27.0f} KB  A3-PDF für die Druckerei".format(
                    pdf.name, pdf.stat().st_size / 1024))
            else:
                print("  FEHLGESCHLAGEN:", pdf.name)
    finally:
        built.unlink(missing_ok=True)


def main():
    OUT.mkdir(exist_ok=True)
    wanted = sys.argv[1:] or list(MOTIVE)
    unknown = [w for w in wanted if w not in MOTIVE]
    if unknown:
        sys.exit("Unbekanntes Motiv: {}. Bekannt: {}".format(
            ", ".join(unknown), ", ".join(MOTIVE)))

    browser = find_browser()
    noise = paper_noise()
    qr = qr_datauri(GO_URL)
    print("Renderer:", browser)
    print("QR zeigt auf:", GO_URL)

    for name in wanted:
        print("\n" + name)
        build(name, MOTIVE[name], browser, noise, qr)


if __name__ == "__main__":
    main()
