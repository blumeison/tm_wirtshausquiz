"""
Rendert animierte Story-Videos (1080x1920, MP4/H.264) fürs Wirtshausquiz.

    python tools/build_video.py            # alle
    python tools/build_video.py frage1     # nur eines
    python tools/build_video.py frage1 --nur-standbild   # schnell prüfen

Die Vorlage stellt eine Funktion render(t) bereit, die jeden Zustand aus der
Zeit t setzt. Playwright steuert das vorhandene Chrome, ruft render() für
jedes Einzelbild auf und macht einen Screenshot; ffmpeg (aus imageio-ffmpeg)
baut daraus das Video. So läuft die Animation immer exakt gleich, egal wie
schnell der Rechner ist.

Einmalig: pip install playwright imageio-ffmpeg   (kein Browser-Download nötig)
"""

import json
import shutil
import subprocess
import sys
import tempfile
from pathlib import Path

import imageio_ffmpeg
from playwright.sync_api import sync_playwright

from build_motive import OUT, ROOT, find_browser, paper_noise

FPS = 30

# Bewegte Auflösungen der „Frage der Woche“. Für eine neue Auflösung einen
# Eintrag kopieren und anpassen — die Vorlage bleibt unverändert.
#   data:  Messpunkte (x = Jahr als Dezimalzahl); axis=False = Jahr unten nicht
#          beschriften, wenn zwei Punkte zu eng liegen
#   x, y:  sichtbarer Bereich der Achsen; grid: gestrichelte Linien
#   next:  Text der Weiter-Pille (Pfeil zur nächsten Story mit dem Link-Sticker);
#          leer lassen, wenn keine Story folgt
VIDEOS = {
    # Frage #1 (Di 22.09.), Auflösung Mi 23.09. als Story.
    # Zahlen: Statistik Austria, Gemeinde Michelhausen. Volkszählungen Ende
    # Oktober, sonst Stand 1. Jänner. Nicht mit den Gemeinde-Zahlen
    # (Melderegister) mischen.
    "frage1": {
        "template": "aufloesung-kurve.html",
        "out": "wirtshausquiz-frage1-aufloesung-story",
        "cfg": {
            "kicker": "Auflösung · Frage der Woche",
            "headline": "So schnell wächst die Gemeinde Michelhausen",
            "unit": "Einwohner",
            "lastLabel": "Anfang 2026",
            "data": [
                {"x": 2011.83, "y": 2609, "label": "2011"},
                {"x": 2015.0, "y": 2736, "label": "2015"},
                {"x": 2021.83, "y": 3845, "label": "2021"},
                {"x": 2025.0, "y": 4373, "label": "2025", "axis": False},
                {"x": 2026.0, "y": 4560, "label": "2026"},
            ],
            "x": [2011.5, 2026.3],
            "y": [2000, 5000],
            "grid": [3000, 4000, 5000],
            "badge": "+75 %",
            "answer": {"letter": "B", "text": "Richtig ist: ca. 4.500"},
            "fact": "Keine Gemeinde in Österreich ist von 2015 bis 2025 so stark gewachsen.",
            "cta": "Neu hier? Lern deine Nachbarn beim Wirtshausquiz kennen: Fr 16. Oktober.",
            "next": "Zur Anmeldung",
            "source": "Quelle: Statistik Austria",
        },
    },
}


def render(name, spec, browser, noise):
    tpl = (ROOT / spec["template"]).read_text(encoding="utf-8")
    block = '  :root {\n    --noise: url("data:image/png;base64,' + noise + '");'
    built = ROOT / ("_" + name + ".built.html")
    html = tpl.replace("  :root {", block, 1)
    cfg = "<script>window.CFG = " + json.dumps(spec["cfg"], ensure_ascii=False) + ";</script>\n</head>"
    built.write_text(html.replace("</head>", cfg, 1), encoding="utf-8")
    mp4 = OUT / (spec["out"] + ".mp4")
    poster = OUT / (spec["out"] + ".jpg")
    frames = Path(tempfile.mkdtemp(prefix="wq_frames_"))
    try:
        with sync_playwright() as pw:
            b = pw.chromium.launch(executable_path=browser)
            page = b.new_page(viewport={"width": 1080, "height": 1920})
            page.goto(built.as_uri())
            page.wait_for_load_state("networkidle")
            page.evaluate("document.fonts.ready")
            duration = page.evaluate("window.DURATION")
            over = page.evaluate("window.overflow ? window.overflow() : 0")
            if over > 0:
                sys.exit("  Inhalt ragt {} px über den Deckel — Vorlage kürzen.".format(over))
            # Erst ein Standbild zum Prüfen, bevor 360 Bilder gerendert werden.
            page.screenshot(path=str(OUT / (spec["out"] + "-check.png")))
            if "--nur-standbild" in sys.argv:
                b.close()
                return
            n = int(round(duration * FPS))
            for i in range(n + 1):
                page.evaluate("t => window.render(t)", i / FPS)
                page.screenshot(path=str(frames / "f{:04d}.png".format(i)))
                if i % FPS == 0:
                    print("  {:>4.0f} s".format(i / FPS), end="\r", flush=True)
            b.close()

        subprocess.run([
            imageio_ffmpeg.get_ffmpeg_exe(), "-y", "-loglevel", "error",
            "-framerate", str(FPS), "-i", str(frames / "f%04d.png"),
            # Instagram und Facebook wollen H.264, yuv420p, Moov-Atom vorne.
            "-c:v", "libx264", "-preset", "slow", "-crf", "18",
            "-pix_fmt", "yuv420p", "-movflags", "+faststart",
            str(mp4),
        ], check=True)
        # Letztes Bild als Standbild — Vorschau auf der Seite und Notfall-Fassung.
        from PIL import Image
        last = sorted(frames.glob("f*.png"))[-1]
        Image.open(last).convert("RGB").save(poster, "JPEG", quality=90, optimize=True, progressive=True)
        print("  {:<44} {:>5.1f} s  {:>6.1f} MB".format(
            mp4.name, duration, mp4.stat().st_size / 1048576))
        print("  {:<44} Standbild".format(poster.name))
    finally:
        shutil.rmtree(frames, ignore_errors=True)
        built.unlink(missing_ok=True)


def main():
    OUT.mkdir(exist_ok=True)
    wanted = [a for a in sys.argv[1:] if not a.startswith("--")] or list(VIDEOS)
    unknown = [w for w in wanted if w not in VIDEOS]
    if unknown:
        sys.exit("Unbekanntes Video: {}. Bekannt: {}".format(", ".join(unknown), ", ".join(VIDEOS)))
    browser = find_browser()
    noise = paper_noise()
    for name in wanted:
        print(name)
        render(name, VIDEOS[name], browser, noise)


if __name__ == "__main__":
    main()
