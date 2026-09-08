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
GO_BASE = "https://go.team-michelhausen.at/" + GO_SLUG

# Ein Kurzlink, mehrere Tracking-Varianten — so ist tm_go gebaut. Jede Variante
# bekommt ihren eigenen QR-Code, das Ziel bleibt fuer alle dasselbe und laesst
# sich spaeter aendern, ohne neu zu drucken.
# Die Schluessel muessen den Labels im tm_go-Backoffice entsprechen: dort wird
# aus dem Label per slugify der Schluessel, "Nahversorger" -> nahversorger.
PLAKAT_ORTE = [
    ("wirtshaus", "Wirtshaus"),
    ("nahversorger", "Nahversorger"),
    ("bahnhof", "Bahnhof"),
]

# Die drei Antworten der Screen-Frage brauchen DREI EIGENE Kurzlinks, keine
# Varianten: jede Antwort führt auf ein anderes Ziel (quizfrage.html?a=1|2|3).
# Beim Anmelde-Plakat war es umgekehrt — dort ein Link mit drei Varianten.
# Diese Slugs müssen im tm_go-Backoffice angelegt sein.
SCREEN_SLUGS = [
    ("quiz-a", "https://quiz.team-michelhausen.at/quizfrage.html?a=1"),
    ("quiz-b", "https://quiz.team-michelhausen.at/quizfrage.html?a=2"),
    ("quiz-c", "https://quiz.team-michelhausen.at/quizfrage.html?a=3"),
]

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
    "frage": {
        "template": "frage.html",
        "renders": [
            ("wirtshausquiz-frage1-feed", 1080, 1350, "Feed 4:5"),
            ("wirtshausquiz-frage1-quadrat", 1080, 1080, "Feed 1:1"),
            ("wirtshausquiz-frage1-story", 1080, 1920, "Story 9:16"),
        ],
    },
    # Die Agentur nimmt ausschliesslich Hochformat 1080x1920, PNG/JPG unter 3 MB.
    # Zwei Motive fuer zwei Publikumsgruppen am Kreisverkehr: Fussgaenger
    # koennen scannen, Autofahrer nicht.
    "screen-fuss": {
        "template": "screen-fuss.html",
        "renders": [("wirtshausquiz-screen-fussgeher", 1080, 1920, "9:16, mit QR")],
        "screen_qr": True,
        "jpg": True,
    },
    "screen-fahrer": {
        "template": "screen-fahrer.html",
        "renders": [("wirtshausquiz-screen-fahrer", 1080, 1920, "9:16, ohne QR")],
        "jpg": True,
    },
    "plakat": {
        "template": "plakat.html",
        # A3 = 297x420mm. CSS rechnet 96dpi, also 1122x1587 CSS-Pixel.
        # Scale 3 ergibt rund 288 dpi — mehr als jede Druckerei braucht.
        "renders": [("wirtshausquiz-plakat-a3", 1122, 1587, "A3")],
        "scale": 3,
        "pdf": "wirtshausquiz-plakat-a3",
        "orte": PLAKAT_ORTE,
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


def build(name, spec, browser, noise, qr, ort=None, suffix="", extra_vars=None):
    tpl = (ROOT / spec["template"]).read_text(encoding="utf-8")
    # Verrät am Plakatfuß, welche Variante das ist — damit beim Aufhängen
    # nicht der Bahnhof-Code im Wirtshaus landet. Auf Lesedistanz unsichtbar.
    tpl = tpl.replace("<!--ORT-->", (" · " + ort) if ort else "")
    # Bewusst Verkettung statt .format(): die CSS-Klammer in ":root {" wäre
    # sonst eine Formatangabe.
    block = '  :root {\n    --noise: url("data:image/png;base64,' + noise + '");'
    if qr:
        block += '\n    --qr: url("' + qr + '");'
    # Das Screen-Motiv braucht drei QR-Codes statt einem.
    for key, uri in (extra_vars or {}).items():
        block += '\n    ' + key + ': url("' + uri + '");'
    injected = tpl.replace("  :root {", block, 1)
    built = ROOT / ("_" + name + suffix + ".built.html")
    built.write_text(injected, encoding="utf-8")
    scale = spec.get("scale", 1)

    try:
        for fname, w, h, label in spec["renders"]:
            target = OUT / (fname + suffix + ".png")
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
                # Das Holzfoto macht PNG unnoetig schwer. Die Agentur nimmt
                # auch JPG, und darunter bleibt reichlich Luft zum 3-MB-Limit.
                if spec.get("jpg"):
                    jpg = target.with_suffix(".jpg")
                    Image.open(target).convert("RGB").save(
                        jpg, "JPEG", quality=90, optimize=True, progressive=True)
                    print("  {:<44} {}x{}  {:>6.0f} KB  {}".format(
                        jpg.name, got[0], got[1], jpg.stat().st_size / 1024,
                        label + ", fuer die Agentur"))
            else:
                print("  FEHLGESCHLAGEN:", target.name)

        if spec.get("pdf"):
            pdf = OUT / (spec["pdf"] + suffix + ".pdf")
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
                print("  {:<44} {:>27.1f} MB  A3-PDF, Text vektoriell".format(
                    pdf.name, pdf.stat().st_size / 1048576))
            else:
                print("  FEHLGESCHLAGEN:", pdf.name)

            # Chrome bettet den Holzhintergrund unkomprimiert ein — rund 22 MB
            # pro Seite, zu viel für Mail oder Upload. Diese Fassung ist aus dem
            # PNG gebaut und JPEG-komprimiert: gleiche 288 dpi, ein Fünfzehntel
            # der Größe. Für ein Plakat, das aus einem halben Meter Entfernung
            # gelesen wird, ist der Unterschied nicht sichtbar.
            png = OUT / (spec["renders"][0][0] + suffix + ".png")
            if png.is_file():
                slim = OUT / (spec["pdf"] + suffix + "-kompakt.pdf")
                im = Image.open(png).convert("RGB")
                im.save(slim, "PDF", resolution=round(im.width / 11.69), quality=88)
                print("  {:<44} {:>27.1f} MB  A3-PDF zum Verschicken".format(
                    slim.name, slim.stat().st_size / 1048576))
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
    print("Renderer:", browser)

    for name in wanted:
        print("\n" + name)
        spec = MOTIVE[name]
        orte = spec.get("orte")
        if spec.get("screen_qr"):
            # Ein QR je Antwort, jeder auf seinen eigenen Kurzlink.
            qrs = {}
            for i, (slug, target) in enumerate(SCREEN_SLUGS):
                url = "https://go.team-michelhausen.at/" + slug + "?s=screen"
                print("  {} -> {}   (Ziel: {})".format(
                    "ABC"[i], url, target))
                qrs["--qr-" + "abc"[i]] = qr_datauri(url)
            build(name, spec, browser, noise, None, extra_vars=qrs)
            continue

        if not orte:
            build(name, spec, browser, noise, qr_datauri(GO_BASE))
            continue
        # Eine Fassung je Aushangort — gleicher Kurzlink, eigener QR, eigene
        # Zählung in tm_go.
        for key, label in orte:
            url = GO_BASE + "?s=" + key
            print("  [{}] QR -> {}".format(label, url))
            build(name, spec, browser, noise, qr_datauri(url),
                  ort=label, suffix="-" + key)


if __name__ == "__main__":
    main()
