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
import urllib.request
from pathlib import Path

import imageio_ffmpeg
from playwright.sync_api import sync_playwright

import aufloesungen
from build_motive import OUT, ROOT, find_browser, paper_noise

FPS = 30

COUNTERS = "https://quiz.team-michelhausen.at/api/counters.php"


def live_teams():
    """Angemeldete Teams von der Anmeldeseite — nie eine Zahl erfinden."""
    with urllib.request.urlopen(COUNTERS, timeout=15) as r:
        return int(json.load(r)["counters"]["teamsTotal"])


LIVE_TEAMS = live_teams()

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
        # Inhalt aus aufloesungen.py — dieselben Zahlen wie im statischen
        # Sujet. „next“ gibt es nur im Video: Pfeil auf die nächste Story.
        "cfg": dict(aufloesungen.FRAGE1, next="Zur Anmeldung"),
    },
    # Zweite Seite der Story: hier soll getippt werden. Unten bleibt ein Feld
    # für den Link-Sticker frei — Link immer über tm_go, sonst fehlt das
    # Tracking:  https://go.team-michelhausen.at/quiz?s=instagram-story
    # teams: kommt live von der Anmeldeseite (LIVE_TEAMS).
    "anmeldung": {
        "template": "anmeldung-bewegt.html",
        "out": "wirtshausquiz-anmeldung-story-bewegt",
        "cfg": {
            "kicker": "Ab sofort",
            "headline": "Anmeldung offen",
            "when": "Wirtshausquiz · Freitag, 16. Oktober",
            "where": "Gasthaus Burchhart · Atzelsdorf",
            "teams": LIVE_TEAMS,
            # {n} = Zahl der angemeldeten Teams. Bewusst KEINE Obergrenze: die
            # Saalkapazität ist mit dem Wirt noch nicht fixiert. Für den Post
            # „Letzte Plätze“ (13.10.) auf die Restplätze umstellen.
            "teamsText": "Schon {n} Teams sind dabei",
            "hint": "🍺 Kein Team? Wir setzen euch dazu",
            "tap": "Hier geht's zur Anmeldung",
            "small": "Teams mit 3 bis 5 Personen · Teilnahme kostenlos",
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
