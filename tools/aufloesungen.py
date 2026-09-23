"""
Inhalte der „Auflösung der Frage der Woche“ — eine Quelle für beide Ausgaben:

    build_video.py    bewegte Story (MP4 1080x1920)
    build_motive.py   statisches Sujet für Feed und Facebook (4:5 und 1:1)

Damit können Zahl im Video und Zahl im Bild nicht auseinanderlaufen. Für eine
neue Auflösung hier einen Eintrag anlegen und in beiden Skripten eintragen.

Zahlen immer aus EINER Quelle. Für Michelhausen ist das Statistik Austria
(Volkszählungen Ende Oktober, sonst Stand 1. Jänner). Die Gemeinde-Website
zählt aus dem Melderegister und kommt auf leicht andere Werte — nicht mischen.
"""

FRAGE1 = {
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
    "source": "Quelle: Statistik Austria",
}
