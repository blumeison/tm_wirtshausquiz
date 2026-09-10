# Wirtshausquiz — SPEC

Ein Repo, ein Deploy, eine Datenbasis. Öffentliche Anmeldung und Backoffice
liegen zusammen auf `quiz.team-michelhausen.at`, gebaut nach dem Blueprint von
`tm_humantablesoccer`: statisches HTML + PHP-API + JSON-Store + Google-SSO,
push-to-deploy per FTPS auf netcup/Plesk.

Ersetzt: `blumeison/tm_wirtshausquiz_backoffice` (Next.js 16 + Prisma/SQLite).
Das Repo bleibt bis Phase 9 als Referenz für Domänenmodell und UI stehen und
wird danach archiviert.

---

## 1. Warum portiert wird

Der Blueprint deployt gebaute **statische Dateien plus PHP**. Node läuft
ausschließlich im GitHub-Runner, am Server nie. Das Next.js-Backoffice braucht
aber einen dauerhaft laufenden Node-Prozess (Server Actions, NextAuth, Prisma)
— dafür gibt es auf netcup keine Laufzeit, und `next export` scheidet aus,
sobald Server Actions und Login im Spiel sind.

Entscheidung: Das Backoffice wird auf denselben Stack portiert wie die
Anmeldung. Danach gilt für alle tm-Projekte ein Deployment-Muster, ein
Auth-Muster, ein Host.

---

## 2. Host-Fakten (bindend)

Verifiziert auf `soccer.team-michelhausen.at` — gleicher Account, gleiche
Maschine. Vor Phase 1 mit einem temporären `api/health.php` für diese
Subdomain gegenprüfen.

- **PHP 7.4.33** (cgi-fcgi). Der Code muss 7.4-kompatibel sein:
  **kein** `match()`, `str_contains` / `str_starts_with` / `str_ends_with`,
  Constructor Promotion, Union Types, benannte Argumente, Nullsafe `?->`, Enums.
  Erlaubt: `??`, `??=`, Arrow-Funktionen `fn()`, typisierte Properties.
- **GD** mit JPEG, PNG, WebP → Bild-Resize serverseitig möglich.
- **openssl, curl, json, mbstring** vorhanden → Google-ID-Token lässt sich
  serverseitig prüfen (JWKS via curl, Signatur via `openssl_verify`).
- **Datenpfad:** `data/` liegt als Geschwister von `httpdocs`, also
  `/var/www/vhosts/<account>/quiz.team-michelhausen.at/data`.
  Aus `api/` erreichbar über `__DIR__ . '/../../data'` — zwei Ebenen hoch,
  nicht eine. Nichts unter `data/` ist über HTTP erreichbar.
- **Mail** über PHPMailer/SMTP (aus HTS übernehmen), nicht `mail()`.

### Absender für die Bestätigungsmails

Eigener Absender, nicht der HTS-Zugang: **`quiz@team-michelhausen.at`**, als
Mailbox in Plesk auf der Apex-Domain angelegt (nicht auf der Subdomain — der
Absender wird gelesen, und `@team-michelhausen.at` ist die vertrauenswürdigere
Adresse). SMTP-Zugangsdaten in `data/config.json`.

DNS-Stand am 07.09.2026, geprüft:

- **SPF** ist gesetzt und deckt den netcup-Mailserver bereits ab
  (`v=spf1 mx a include:_spf.webhosting.systems ~all`) → eine Plesk-Mailbox auf
  dieser Domain ist ohne weitere Änderung SPF-aligned.
- **DMARC** vorhanden, aber `p=none` — reiner Beobachtungsmodus.
- **DKIM fehlt** (kein Eintrag unter den üblichen Selektoren). Vor dem ersten
  Massenversand in Plesk unter Mail-Einstellungen der Domain aktivieren.
  Bestätigungsmails gehen überwiegend an Gmail und GMX; ohne DKIM landet ein
  Teil davon im Spam, und das kostet direkt Anmeldungen.

---

## 3. Verzeichnisse

```
tm_wirtshausquiz/
  index.html              Event-Landing MIT Anmeldeformular unter #anmelden
  absage.html             Abmeldung per Token aus der Erinnerungsmail
  quizfrage.html          Landeseite der Werbescreen- und Plakat-QR-Codes
  tokens.css              TM-Design-System (Tokens + Komponenten)
  logo.png logo-white.png Logo, hell/dunkel automatisch
  assets/                 auth.js, Logo, og-image, Sujets
  admin/                  Backoffice (SSO-geschützt, clientseitig gerendert)
    index.html            Dashboard
    fragen.html           Fragenpool und Editor
    abende.html           Quizabende
    abend.html            ein Abend: Runden, Fragen, Masterfrage
    teams.html            Anmeldungen, Teams, Warteliste
    generator.html        KI-Fragengenerator
    benutzer.html         Whitelist und Rollen
    beamer.html           Präsentationsansicht für den Quizabend
  api/
    lib.php               JSON-Store, flock, Session, JWT-Verify, Upload, Mail
    config.php            liest data/config.json
    auth/google.php  session.php  logout.php
    register.php  cancel.php  counters.php        öffentlich
    questions/*.php  sessions/*.php  teams/*.php  admin/*.php
    ai/generate.php
    phpmailer/            aus HTS übernommen
  uploads/                Bild, Audio, Video der Fragen (öffentlich lesbar)
  docs/SPEC.md
  .github/workflows/deploy.yml
```

Am Server außerhalb von `httpdocs`:

```
data/
  config.json             Google-Client-ID, Anthropic-Key, SMTP, Event-Defaults
  users.json              SSO-Whitelist mit Rollen
  questions.json          der Fragenpool
  sessions/<id>.json      ein Quizabend inkl. Runden und Rundenfragen
  teams.json              Stammdaten wiederkehrender Teams
  registrations/<sessionId>.json   Anmeldungen zu einem Abend
```

**Warum Fragen in einer Datei, Abende und Anmeldungen je eigene:** Der
Fragenpool wird fast immer als Ganzes gelesen — filtern, suchen, Fragen in eine
Runde ziehen — und nur von ein, zwei Redakteuren geschrieben; eine Datei ist
einfacher und schnell genug bis in den vierstelligen Bereich. Abende und
Anmeldungen werden dagegen unabhängig voneinander geschrieben und bekommen je
eine eigene Datei, damit sich Schreibvorgänge nicht gegenseitig sperren.

---

## 4. Datenmodell

Übernommen aus dem Prisma-Schema, JSON statt Tabellen. IDs über `gen_id()`
wie in HTS.

### Frage

```json
{
  "id": "q_a1b2c3",
  "type": "MULTIPLE_CHOICE",
  "prompt": "…",
  "answer": "…",
  "payload": {},
  "points": 10,
  "difficulty": 2,
  "category": "Geschichte",
  "tags": ["michelhausen", "lokal"],
  "status": "READY",
  "source": "MANUAL",
  "createdBy": "google:1098…",
  "createdAt": "2026-09-07T18:30:00+02:00",
  "updatedAt": "2026-09-07T18:30:00+02:00"
}
```

`type` ∈ `MULTIPLE_CHOICE | OPEN_TEXT | ESTIMATE | IMAGE | AUDIO | VIDEO | MAP | MASTER`,
`status` ∈ `DRAFT | READY` (KI-Fragen landen als `DRAFT`),
`source` ∈ `MANUAL | AI`, `difficulty` 1–3.

`payload` ist typabhängig und wird serverseitig validiert. Die maßgebliche
Definition steht heute als zod-Schema in
`tm_wirtshausquiz_backoffice/src/lib/quiz.ts` und wird 1:1 nach PHP übersetzt:

| Typ | Payload |
| --- | --- |
| `MULTIPLE_CHOICE` | `options` (2–8 Strings), `correctIndex` (0-basiert) |
| `OPEN_TEXT` | `acceptedAnswers` (Schreibvarianten) |
| `ESTIMATE` | `value`, `unit`, `scoring` = `CLOSEST` \| `TOLERANCE`, `tolerancePercent` |
| `IMAGE` | `imageUrl` |
| `AUDIO` / `VIDEO` | `mediaUrl`, `youtubeUrl`, `startSeconds`, `clipSeconds` |
| `MAP` | `lat`, `lng`, `radiusKm`, `scoring` = `RADIUS` \| `CLOSEST` |
| `MASTER` | `clueMode` = `FIRST_LETTERS` \| `ANSWERS_ARE_CLUES` \| `CUSTOM`, `clueExplanation` |

Standardpunkte: 10, Kartenfrage 15, Masterfrage 25.

### Quizabend

Runden und Rundenfragen werden in die Abend-Datei eingebettet — sie existieren
nie außerhalb eines Abends.

```json
{
  "id": "s_x1y2",
  "title": "Wirtshausquiz #1 – Premiere",
  "location": "…",
  "scheduledAt": "2026-…",
  "status": "DRAFT",
  "notes": "",
  "capacityTeams": 12,
  "waitlistFrom": 14,
  "promoTeams": 5,
  "rounds": [
    {
      "id": "r_1", "title": "Rund ums Bier", "order": 1,
      "multiplier": 1, "jokerAllowed": true,
      "questions": [
        { "questionId": "q_a1b2c3", "order": 1, "pointsOverride": null,
          "clueFragment": "Anfangsbuchstabe B" }
      ]
    }
  ]
}
```

`status` ∈ `DRAFT | READY | LIVE | DONE`.
Effektive Punkte einer Frage in einer Runde:
`round((pointsOverride ?? points) × multiplier)`.

`clueFragment` hält fest, was eine normale Frage zur Masterfrage der Runde
beiträgt — das ist der Kern des Formats und darf beim Port nicht verlorengehen.

### Anmeldung

```json
{
  "id": "reg_7f3a",
  "sessionId": "s_x1y2",
  "teamName": "Die Quizverstörten",
  "captainName": "…", "email": "…", "phone": "",
  "size": 5,
  "lookingForPlayers": false,
  "note": "",
  "status": "ACTIVE",
  "cancelToken": "…",
  "createdAt": "…", "ip": "…"
}
```

Gespeichert wird nur `status` ∈ `ACTIVE | CANCELLED`.

**Platz und Freirunden-Rang werden abgeleitet, nicht gespeichert.** Beide
ergeben sich aus der Anmeldereihenfolge unter den nicht stornierten Einträgen:
Position ≤ `capacityTeams` heißt bestätigt, darüber Warteliste; Position ≤
`promoTeams` heißt Freirunde. Das hat drei Vorteile: Eine Absage lässt die
Warteliste automatisch nachrücken, ohne Umbuchungslogik; derselbe Freirunden-Rang
kann nicht zweimal vergeben werden; und ein Team kann sich nur nach vorne
bewegen, nie nach hinten — vor einen bestehenden Eintrag lässt sich nichts
einfügen, und eine Stornierung wird nie zurückgenommen.

Der Preis: Rückt ein Team von der Warteliste nach, erfährt es das nicht von
selbst. Diese Benachrichtigung ist bis P3 Handarbeit.

### Benutzer und Whitelist

```json
{ "sub": "google:1098…", "email": "…", "name": "…",
  "role": "ADMIN", "createdAt": "…" }
```

`role` ∈ `ADMIN | EDITOR | PENDING`. Ist `users.json` leer, wird der erste
Login automatisch `ADMIN` — genau wie bisher. Alle weiteren landen als
`PENDING` und sehen nur eine Warteseite, bis ein Admin sie freischaltet. Der
letzte Admin lässt sich weder löschen noch herabstufen.

---

## 5. Auth

Aus HTS übernommen, ohne Apple — für ein Backoffice reicht Google.

- Client zeigt „Mit Google anmelden“ → ID-Token (JWT) → `POST api/auth/google.php`.
- PHP verifiziert die Signatur gegen die Google-JWKS, prüft `aud` gegen die
  eigene Client-ID und `exp`, legt dann eine PHP-Session an (HttpOnly-Cookie)
  mit `sub`, `provider`, `email`.
- Jeder Admin-Endpunkt liest Rolle und `sub` **aus der Session**, nie aus dem
  Request-Body.
- Die Client-ID ist Konfiguration, kein Geheimnis, und darf im Frontend stehen.
  Anthropic-Key und SMTP-Zugang liegen in `data/config.json`, außerhalb von
  `httpdocs`.

Die **öffentliche Anmeldung hat bewusst keinen Login.** Jede Hürde vor dem
Formular kostet Anmeldungen; gegen Missbrauch reichen Rate-Limit pro IP,
Honeypot-Feld und die Bestätigungsmail.

---

## 6. API

Alle Antworten JSON, Fehler über `fail($code, $msg)` wie in HTS.

**Öffentlich**

| Endpunkt | Zweck |
| --- | --- |
| `GET  api/counters.php` | freie Plätze, verbleibende Freirunden, Status des Abends |
| `POST api/register.php` | Anmeldung anlegen, Bestätigungsmail, Warteliste |
| `GET  api/cancel.php?t=` | Abmeldung per Token, rückt die Warteliste nach |

**Auth:** `POST api/auth/google.php`, `GET api/session.php`, `POST api/logout.php`

**Backoffice** (Rolle `EDITOR` aufwärts, sofern nicht anders vermerkt)

| Endpunkt | Zweck |
| --- | --- |
| `GET/POST api/questions/list.php · save.php · delete.php` | Fragenpool |
| `GET/POST api/sessions/list.php · get.php · save.php · delete.php` | Abende |
| `POST api/sessions/rounds.php` | Runden und Rundenfragen setzen |
| `GET/POST api/teams/…` | Teamstammdaten |
| `GET/POST api/admin/registrations.php` | Anmeldungen, Warteliste, CSV-Export |
| `POST api/upload.php` | Bild, Audio, Video; GD-Resize für Bilder |
| `POST api/ai/generate.php` | KI-Generator |
| `GET/POST api/admin/users.php` | Whitelist und Rollen (**nur `ADMIN`**) |

### Schreibvorgänge und Nebenläufigkeit

Jeder Schreibvorgang läuft als read-modify-write unter `flock(LOCK_EX)` — das
gilt besonders für `api/register.php`. Die Aktion „erste 5 Teams“ erzeugt genau
die Situation, in der mehrere Anmeldungen in derselben Sekunde eintreffen und
`promoRank` sonst doppelt vergeben wird.

---

## 7. KI-Generator in PHP

Bisher `@anthropic-ai/sdk` mit `messages.parse` und zod-Structured-Outputs.
In PHP: `curl` gegen die Messages-API mit demselben Ausgabeschema, danach
serverseitige Validierung wie bei jeder anderen Eingabe.

System-Prompt, Rundenmodus-Anweisungen und Ausgabeschema werden **wörtlich**
aus `tm_wirtshausquiz_backoffice/src/lib/ai.ts` übernommen — sie sind erprobt,
und die Konstruktion der Masterfrage hängt an ihrem genauen Wortlaut.
KI-Fragen landen als `DRAFT` und müssen vor dem Einsatz freigegeben werden.

Generierbare Typen bleiben `MULTIPLE_CHOICE`, `OPEN_TEXT`, `ESTIMATE`, `MAP`
plus die konstruierte Masterfrage.

> Vor der Implementierung: Modell-ID und Request-Format gegen die aktuelle
> Anthropic-API prüfen (Skill `claude-api`). Der alte Default `claude-opus-4-8`
> ist nicht ungeprüft zu übernehmen.

---

## 8. Beamer-Ansicht

`admin/beamer.html?id=<sessionId>` rendert den Abend als Foliensatz:
Titelfolie → je Runde eine Rundenfolie → Fragefolien → Auflösung → Masterfrage.
Steuerung mit Pfeiltasten und Leertaste, Vollbild, große Type, dunkler Grund.

Das Slide-Modell liegt bereits halbfertig in
`tm_wirtshausquiz_backoffice/src/lib/presentation.ts` (uncommitted) und wird
nach JavaScript übersetzt. Kartenfragen zeigen die Leaflet-Karte, Musikfragen
einen Player, Videofragen den eingebetteten Clip mit `startSeconds` und
`clipSeconds`.

---

## 9. Öffentliche Seiten

### Startseite

Datum, Uhrzeit, Lokal, Ablauf in vier Zeilen, Teamgröße 3–6, Anmelde-CTA.
Dazu zwei Live-Zahlen aus `api/counters.php`: freie Plätze und verbleibende
Freirunden. Der Aktionsblock ist über `config.json` abschaltbar — solange mit
dem Wirt nichts vereinbart ist, wird er nicht angezeigt.

### Anmeldeformular

Teamname · Kapitän · E-Mail · Handy (optional) · Anzahl 3–6 ·
„suche noch Mitspieler“ · Anmerkung (optional) · Datenschutz-Häkchen.
Honeypot-Feld gegen Bots. Nach dem Absenden Bestätigungsmail mit Absage-Link.

Ist der Abend voll, wird die Anmeldung als `WAITLIST` angenommen und das auch
so kommuniziert — nicht abgewiesen.

### Quizfrage-Landeseite

`quizfrage.html?q=<kuerzel>&a=<1|2|3>` — Ziel der drei QR-Codes auf Werbescreen
und Plakat. Zeigt „Richtig!“ oder „Knapp daneben“, die Auflösung in einem Satz,
und direkt darunter ohne Scrollen den Anmeldeblock. Eine falsche Antwort führt
nie in eine Sackgasse: die Frage ist der Aufhänger, die Anmeldung das Ziel.

Die QR-Codes zeigen nicht direkt hierher, sondern auf Kurzlinks in **tm_go** —
damit ist messbar, welche Antwort wie oft gescannt wurde, und das Ziel lässt
sich ändern, ohne den Screen neu zu bespielen.

---

## 10. Deployment

`.github/workflows/deploy.yml` nach dem Muster aus HTS und tm_website:
`lftp mirror -R --delete` über FTPS, ein Staging-Schritt kopiert nur die
Deploy-Dateien nach `_site/`, sodass `.git`, `.github`, `.claude` und `docs`
den Server nie erreichen.

```
SERVER_DIR: ./quiz.team-michelhausen.at/httpdocs/
Secrets:    FTP_SERVER, FTP_USERNAME, FTP_PASSWORD
```

**Nicht spiegeln:** `uploads/` und alles unter `data/` werden am Server
geschrieben und dürfen von `--delete` nicht erfasst werden — entsprechend
ausnehmen, sonst löscht der nächste Deploy die hochgeladenen Medien.

Secrets pro Repo setzen (`assets/set-secrets.ps1` aus dem `tm-deploy`-Skill);
`blumeison` ist ein persönlicher Account ohne organisationsweite Secrets.

---

## 11. Migration der bestehenden Daten

Einmaliges Node-Skript `tools/migrate.mjs`, bleibt lokal und wird nicht
deployt: liest `tm_wirtshausquiz_backoffice/prisma/dev.db` und schreibt
`questions.json`, `sessions/*.json`, `teams.json`, `users.json`.

Aktueller Inhalt: 8 Fragen, 1 Abend-Entwurf ohne Datum, 3 Demo-Teams, 1 Konto —
alles Seed-Daten. Die Demo-Teams werden **nicht** übernommen, sie würden die
echten Anmeldungen verfälschen. Die 8 Fragen sind es wert.

---

## 12. Phasen

Phase 2 kommt bewusst früh: Sie ist das Einzige, was die Werbung blockiert.

- [x] **P0** Repos zusammenlegen, Deploy-Action, `api/health.php` gegen die Subdomain verifizieren, Google-OAuth-Client anlegen
- [x] **P1** `api/lib.php`: JSON-Store mit flock, Session, Google-JWT-Verify, Upload, Mail — größtenteils aus HTS portiert
- [x] **P2** Öffentliche Anmeldung: Startseite, Formular, `register.php`, Bestätigungsmail, Zähler, Warteliste, Absage → **ab hier kann geworben werden**
- [~] **P3** Admin-Shell mit SSO und Whitelist, Anmeldungsverwaltung, CSV-Export —
  gebaut 10.09. (`admin/`, `api/auth.php`, `api/auth/google.php`, `api/admin/*`), dazu
  Herkunfts-Auswertung pro Team und nach Kanal. Feste Admins in `config.php` → `admins`
  statt „erster Login wird ADMIN“. Google-Client-ID seit 10.09. gesetzt (bewusst kein
  Dev-Bypass — echte Personendaten).
- [x] **P4** Fragenpool und Editor für alle acht Typen — gebaut 10.09.
  (`api/questions_lib.php`, `api/questions/*`, `api/upload.php`, `admin/assets/fragen.js`).
  Strenger als das Original: keine leeren MC-Optionen, Kartenfrage braucht Zielort, kein SVG-Upload.
  `delete.php` muss in P5 Fragen verweigern, die in einer Runde stecken.
- [x] **P5** Quizabende: Runden, Fragen ziehen, Multiplikatoren, Masterfrage — gebaut 10.09.
  (`api/sessions_lib.php`, `api/sessions/get|save.php`, `admin/assets/abend.js`). Ein Abend =
  `data/sessions/<session_id>.json`, `rev` gegen Überschreiben. Rundenarten NORMAL/HANDOUT.
  **Masterfrage = Finale im Countdown-Modus** (Hinweise vom schwersten zum leichtesten,
  Punkteleiter 50/40/30/20/10, ein Tipp pro Team) — abweichend vom alten `clueFragment`-Modell,
  das als Modus bleibt. Stechfrage = eine Schätzfrage außerhalb der Runden. Joker pro Abend an/aus.
- [ ] **P6** KI-Generator
- [ ] **P7** Beamer-Ansicht
- [ ] **P8** Quizfrage-Landeseite und tm_go-Kurzlinks für die QR-Codes
- [ ] **P9** Migration, `tm_wirtshausquiz_backoffice` archivieren

---

## 13. Der erste Abend

```json
{
  "title": "Wirtshausquiz #1 – Premiere",
  "date": "2026-10-16",
  "weekday": "Freitag",
  "venue": "Gasthaus Burchhart „Zur Veste Liechtenstein“",
  "address": "Liechtensteingasse 2, 3451 Atzelsdorf",
  "phone": "+43 2275 6802",
  "capacityTeams": 12,
  "waitlistFrom": 14,
  "promoTeams": 5
}
```

Der Name ist gegen die Gemeindeseite und herold.at geprüft: **Burchhart** mit
zwei r, offizieller Zusatz „Zur Veste Liechtenstein“.

Uhrzeit: **Einlass ab 17:30 Uhr** (Küche offen, damit der Wirt nicht spät
kochen muss), **Quizstart 18:30 Uhr**, Ende gegen 21:45 Uhr.

**Die Anmeldung ist seit 07.09.2026 live** — deutlich vor dem Anmelde-Post am
17.09.

## 14. Offen

- Freirunden-Mechanik mit dem Wirt klären; bis dahin bleibt der Aktionsblock aus
  (`promo_enabled` in `data/config.json` auf `true` setzen, sonst nichts)
- Mailversand läuft derzeit über den `mail()`-Fallback, weil `smtp_pass` fehlt.
  Funktioniert, ist aber die schlechtere Zustellung — Mailbox anlegen und
  `{"smtp_pass":"…"}` in `data/config.json` legen
- `api/health.php` löschen, sobald die Anmeldung abgenommen ist
- Google-OAuth-Client für `quiz.team-michelhausen.at` — der User legt ihn an,
  sobald P3 ansteht. P0 bis P2 brauchen ihn nicht.
- Mailbox `quiz@team-michelhausen.at` in Plesk anlegen, DKIM aktivieren
