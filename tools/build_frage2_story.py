"""
Frage der Woche #2 als Story: Dennis' Video mit Untertiteln, Marke und Feld
für den Instagram-Quiz-Sticker.

    python tools/build_frage2_story.py [pfad/zum/video.mp4]

Was passiert:
  - Bild: Pixel-HDR (HLG, 10 bit) wird auf SDR/BT.709 umgerechnet — sonst
    wirkt es auf Instagram/Facebook grell oder ausgewaschen.
  - Ton: Der „I wer' narrisch!“-Schrei der Gruppe liegt gut 20 dB über
    Dennis. Er wird gezielt abgesenkt, danach alles auf -16 LUFS normiert
    (üblicher Social-Media-Pegel).
  - Overlay: frage2-story.html, je Untertitel ein transparenter Screenshot.

Ergebnis in tools/out/:
  wirtshausquiz-frage2-story-video.mp4       zum Posten (Sticker drüberlegen)
  wirtshausquiz-frage2-story-video.jpg       Standbild / Vorschau
  wirtshausquiz-frage2-story-video-vorschau.jpg   mit nachgebautem Sticker, nur zum Prüfen
"""

import json
import shutil
import subprocess
import sys
import tempfile
from pathlib import Path

import imageio_ffmpeg
from playwright.sync_api import sync_playwright

from build_motive import FRAGE2, OUT, ROOT, find_browser

SRC = Path(sys.argv[1]) if len(sys.argv) > 1 else Path.home() / "Downloads" / "PXL_20260925_194443619~2.mp4"
NAME = "wirtshausquiz-frage2-story-video"

# Untertitel mit Zeitfenster in Sekunden (bis None = Videoende). Zeiten nach
# Markus' Angabe, am Pegelverlauf nachgeschärft: Dennis „Edi Finger's“
# 3,1–3,8 s, Pause, Schrei 4,1–5,8 s, Dennis wieder ab 6,3 s.
CAPS = [
    {"text": "Der berühmteste Tonfall der österreichischen Fernsehgeschichte …", "from": 0.0, "to": 2.9},
    {"text": "Edi Finger:", "from": 2.9, "to": 4.0},
    {"text": "„I wer’ narrisch!“", "from": 4.0, "to": 6.2, "shout": True},
    {"text": "Welcher Spielstand steht nach diesem Tor auf der Anzeigetafel?",
     "from": 6.2, "to": None, "hint": True},
]

# Schrei absenken: Faktor 0.12 (= -18 dB) zwischen 4,05 und 6,15 s, 0,1 s
# Rampe. Bis 6,15 s, weil das „sch“ von narrisch bis ~6,1 s ausläuft; Dennis
# setzt erst bei 6,3 s wieder ein.
DUCK = (4.05, 6.15, 0.12, 0.1)
POSTER_T = 13.0   # im Countdown: Frage, Hinweis und Ring sichtbar

# Nach der Frage: letztes Bild stehen lassen, langsam heranzoomen, Countdown.
# Sonst springt Instagram nach 11 s weiter, bevor jemand getippt hat.
COUNT = 10          # Sekunden
END_HOLD = 0        # nach 0 nichts mehr stehen lassen (kein „Zeit!“)
ZOOM = 0.07         # +7 % über den Countdown
ZOOM_CENTER = (500, 760)   # Richtung Gesicht (px im 1080x1920-Bild)
FPS = 30
COUNT_BOX = (740, 570, 260, 260)   # muss zu .count in frage2-story.html passen

FF = imageio_ffmpeg.get_ffmpeg_exe()

# HLG -> linear -> BT.709 mit Hable-Tonemapping.
TONEMAP = ("zscale=t=linear:npl=203,format=gbrpf32le,zscale=p=bt709,"
           "tonemap=hable:desat=0,zscale=t=bt709:m=bt709:r=tv,format=yuv420p")


def overlays(browser, tmp):
    cfg = {"kicker": FRAGE2["kicker"], "caps": CAPS, "opts": FRAGE2["opts"]}
    tpl = (ROOT / "frage2-story.html").read_text(encoding="utf-8")
    built = ROOT / "_frage2-story.built.html"
    built.write_text(tpl.replace(
        "</head>", "<script>window.CFG = " + json.dumps(cfg, ensure_ascii=False) + ";</script>\n</head>", 1),
        encoding="utf-8")
    pngs = []
    try:
        with sync_playwright() as pw:
            b = pw.chromium.launch(executable_path=browser)
            page = b.new_page(viewport={"width": 1080, "height": 1920})
            page.goto(built.as_uri())
            page.wait_for_load_state("networkidle")
            page.evaluate("document.fonts.ready")
            # Untertitel ohne „Tippt an“-Hinweis; der kommt als eigene Ebene
            # erst mit dem Countdown (dann, wenn der Sticker da ist).
            for i in range(len(CAPS)):
                page.evaluate("i => window.show(i, false, true)", i)
                p = tmp / "cap{}.png".format(i)
                page.screenshot(path=str(p), omit_background=True)
                pngs.append(p)
            page.evaluate("i => window.show(i, false, false)", len(CAPS) - 1)
            page.evaluate("document.querySelectorAll('.kicker,.logo,#caps').forEach(e => e.style.visibility='hidden')")
            hint = tmp / "hint.png"
            page.screenshot(path=str(hint), omit_background=True)
            pngs.append(hint)
            page.evaluate("document.querySelectorAll('.kicker,.logo,#caps').forEach(e => e.style.visibility='')")
            page.evaluate("i => window.show(i, true)", len(CAPS) - 1)
            mock = tmp / "mock.png"
            page.screenshot(path=str(mock), omit_background=True)
            x, y, w, h = COUNT_BOX
            for i in range(int(round((COUNT + END_HOLD) * FPS))):
                page.evaluate("a => window.countdown(a[0], a[1])", [i / FPS, COUNT])
                page.screenshot(path=str(tmp / "cd{:04d}.png".format(i)), omit_background=True,
                                clip={"x": x, "y": y, "width": w, "height": h})
            b.close()
    finally:
        built.unlink(missing_ok=True)
    return pngs, mock


def duration(path):
    out = subprocess.run([FF, "-hide_banner", "-i", str(path)], capture_output=True, text=True).stderr
    hms = out.split("Duration: ", 1)[1].split(",", 1)[0].split(":")
    return int(hms[0]) * 3600 + int(hms[1]) * 60 + float(hms[2])


def ticks():
    """Leises Ticken je Sekunde, die letzten drei heller."""
    f = "if(gte(t,{}),2100,1400)".format(COUNT - 3)
    return ("aevalsrc='0.22*sin(2*PI*{f}*t)*exp(-70*mod(t,1))*lt(t,{n})':s=48000:d={d},"
            "aformat=channel_layouts=stereo".format(f=f, n=COUNT, d=COUNT + END_HOLD))


def zoom_frames(tmp):
    """
    Langsamer Zoom aufs letzte Bild, mit Subpixel-Genauigkeit.

    ffmpegs zoompan kann den Ausschnitt nur um ganze Pixel verschieben — das
    ruckelt sichtbar. Pillow rechnet jede Stufe per affiner Transformation
    (bikubisch) aus dem Originalbild, dazu weicher An- und Auslauf.
    """
    from PIL import Image
    last = tmp / "last.png"
    subprocess.run([FF, "-y", "-loglevel", "error", "-display_rotation:v:0", "0",
                    "-sseof", "-0.3", "-i", str(SRC), "-vf",
                    "transpose=clock," + TONEMAP + ",scale=in_color_matrix=bt709:in_range=tv,format=rgb24",
                    "-update", "1", str(last)], check=True)
    im = Image.open(last).convert("RGB")
    cx, cy = ZOOM_CENTER
    n = int(round((COUNT + END_HOLD) * FPS))
    for i in range(n):
        p = i / max(1, n - 1)
        z = 1 + ZOOM * (p * p * (3 - 2 * p))          # smoothstep
        frame = im.transform(im.size, Image.AFFINE,
                             (1 / z, 0, cx - cx / z, 0, 1 / z, cy - cy / z),
                             resample=Image.BICUBIC)
        frame.save(tmp / "z{:04d}.png".format(i), compress_level=1)


def encode(pngs, dst, tmp):
    a, b, g, r = DUCK
    duck = ("volume='1-{k}*clip((t-{a})/{r},0,1)*clip(({b}-t)/{r},0,1)':eval=frame"
            .format(k=1 - g, a=a - r, b=b + r, r=r))
    t0 = duration(SRC)
    extra = COUNT + END_HOLD
    total = t0 + extra
    # Standbild-Teil kommt als fertige Bildfolge (zoom_frames) und wird
    # hinten angehängt.
    chain = ["[0:v]transpose=clock," + TONEMAP + ",fps={fps},setsar=1[main]".format(fps=FPS),
             "[{z}:v]scale=out_color_matrix=bt709:out_range=tv,format=yuv420p,setsar=1[zm]".format(
                 z=len(pngs) + 2),
             "[main][zm]concat=n=2:v=1:a=0[v0]"]
    last = "v0"
    chain_caps = CAPS + [{"from": t0, "to": None}]   # letzte Ebene: Hinweis
    for i, c in enumerate(chain_caps):
        enable = "gte(t,{})".format(c["from"]) if c["to"] is None else \
                 "between(t,{},{})".format(c["from"], c["to"] - 0.001)
        chain.append("[{last}][{n}:v]overlay=0:0:enable='{en}'[v{m}]".format(
            last=last, n=i + 1, en=enable, m=i + 1))
        last = "v{}".format(i + 1)
    n = len(chain_caps) + 1
    chain.append("[{n}:v]setpts=PTS+{t0}/TB[cd];[{last}][cd]overlay={x}:{y}:eof_action=pass[vout]".format(
        n=n, t0=t0, last=last, x=COUNT_BOX[0], y=COUNT_BOX[1]))
    last = "vout"
    chain.append("[0:a]" + duck + ",highpass=f=80,"
                 # Kompressor gleicht Dennis' Sprache aus, loudnorm bringt alles
                 # auf -16 LUFS, der Limiter kappt Spitzen bei -1,5 dBFS.
                 "acompressor=threshold=-30dB:ratio=4:attack=5:release=150:knee=6,"
                 "loudnorm=I=-16:TP=-1.5:LRA=5,alimiter=limit=0.84:level=false,aresample=48000[sp]")
    chain.append(ticks() + ",adelay={ms}|{ms}[tk]".format(ms=int(t0 * 1000)))
    chain.append("[sp][tk]amix=inputs=2:duration=longest:normalize=0,atrim=0:{}[a]".format(total))
    # Das Handy speichert quer + Drehvermerk (-90°). Vermerk auf 0 setzen und
    # selbst drehen, sonst dreht der Player das fertige Video noch einmal.
    cmd = [FF, "-y", "-loglevel", "error", "-display_rotation:v:0", "0", "-i", str(SRC)]
    for p in pngs:
        cmd += ["-i", str(p)]
    cmd += ["-framerate", str(FPS), "-i", str(tmp / "cd%04d.png")]
    cmd += ["-framerate", str(FPS), "-i", str(tmp / "z%04d.png")]
    cmd += ["-filter_complex", ";".join(chain), "-map", "[" + last + "]", "-map", "[a]",
            # Instagram/Facebook: H.264, yuv420p, AAC 48 kHz, Moov-Atom vorne.
            "-c:v", "libx264", "-preset", "slow", "-crf", "18", "-pix_fmt", "yuv420p",
            "-colorspace", "bt709", "-color_primaries", "bt709", "-color_trc", "bt709",
            "-c:a", "aac", "-b:a", "192k", "-ar", "48000",
            "-movflags", "+faststart", "-t", "{:.3f}".format(total), str(dst)]
    subprocess.run(cmd, check=True)


def split(video, t0):
    """Zwei Story-Teile: 1 = Dennis (ohne Sticker), 2 = Countdown (mit Sticker)."""
    for part, args in ((1, ["-t", "{:.3f}".format(t0)]), (2, ["-ss", "{:.3f}".format(t0)])):
        dst = OUT / "{}-teil{}.mp4".format(NAME, part)
        subprocess.run([FF, "-y", "-loglevel", "error", "-i", str(video)] + args + [
            "-c:v", "libx264", "-preset", "slow", "-crf", "18", "-pix_fmt", "yuv420p",
            "-colorspace", "bt709", "-color_primaries", "bt709", "-color_trc", "bt709",
            "-c:a", "aac", "-b:a", "192k", "-movflags", "+faststart", str(dst)], check=True)
        print("  {:<44} {:>6.1f} MB".format(dst.name, dst.stat().st_size / 1048576))


def still(video, t, dst, extra=None):
    cmd = [FF, "-y", "-loglevel", "error", "-ss", str(t), "-i", str(video)]
    if extra:
        cmd += ["-i", str(extra), "-filter_complex", "[0:v][1:v]overlay"]
    cmd += ["-frames:v", "1", "-q:v", "3", str(dst)]
    subprocess.run(cmd, check=True)


def main():
    if not SRC.is_file():
        sys.exit("Video nicht gefunden: {}".format(SRC))
    OUT.mkdir(exist_ok=True)
    tmp = Path(tempfile.mkdtemp(prefix="wq_f2_"))
    try:
        pngs, mock = overlays(find_browser(), tmp)
        mp4 = OUT / (NAME + ".mp4")
        zoom_frames(tmp)
        encode(pngs, mp4, tmp)
        still(mp4, POSTER_T, OUT / (NAME + ".jpg"))
        still(mp4, POSTER_T, OUT / (NAME + "-vorschau.jpg"), extra=mock)
        print("  {:<44} {:>6.1f} MB".format(mp4.name, mp4.stat().st_size / 1048576))
        split(mp4, duration(SRC))
        still(OUT / (NAME + "-teil2.mp4"), 0.5, OUT / (NAME + "-teil2.jpg"))
    finally:
        shutil.rmtree(tmp, ignore_errors=True)


if __name__ == "__main__":
    main()
