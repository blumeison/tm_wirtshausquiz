<?php
/**
 * Wirtshausquiz — public app config (PHP 7.4).
 *
 * Everything here is non-secret and may ship to the browser. Secrets (SMTP
 * password, Anthropic key) live in data/config.json, a sibling of httpdocs,
 * which wq_config() merges over these defaults. That file is never in the repo
 * and survives a deploy.
 */

return [
    'site_url' => 'https://quiz.team-michelhausen.at',

    // Google OAuth Client ID for the backoffice (P3). Not a secret.
    // Authorized JavaScript origin = https://quiz.team-michelhausen.at
    'google_client_id' => '',

    // ---- The event ---------------------------------------------------------
    // Change these in data/config.json to move the date without a deploy.
    'session_id'   => 's_premiere',
    'event_title'  => 'Wirtshausquiz #1',
    'event_date'   => '2026-10-16',            // Friday
    'doors_time'   => '17:30',
    'start_time'   => '18:30',
    'end_time'     => '21:45',
    'venue'        => 'Gasthaus Burchhart „Zur Veste Liechtenstein“',
    'venue_short'  => 'Gasthaus Burchhart',
    'address'      => 'Liechtensteingasse 2, 3451 Atzelsdorf',
    'venue_phone'  => '+43 2275 6802',
    'maps_url'     => 'https://www.google.com/maps/search/?api=1&query=Gasthaus+Burchhart+Liechtensteingasse+2+3451+Atzelsdorf',

    // ---- Registration rules -----------------------------------------------
    'registration_open' => true,
    'team_min'          => 3,
    'team_max'          => 5,
    'capacity_teams'    => 12,   // teams that get a confirmed spot
    'waitlist_from'     => 14,   // hard stop: no signups at all beyond this

    // ---- Free-round promo --------------------------------------------------
    // ON: agreed 2026-09-07. Team Michelhausen covers it if the Wirt doesn't.
    'promo_enabled' => true,
    'promo_teams'   => 2,
    'promo_label'   => 'Die ersten 2 Teams bekommen die erste Runde aufs Haus.',

    // ---- Quizfrage auf Werbescreen und Plakat ------------------------------
    // Ziel der drei QR-Codes. Frage und Auflösung stehen hier, damit sie ohne
    // Deploy wechseln können: {"screen_question": {...}} in data/config.json.
    // `correct` ist 1-basiert und passt damit direkt zum ?a= aus dem QR-Code.
    'screen_question' => [
        'prompt'  => 'Wie lang braucht der Zug von Tullnerfeld nach Wien Hbf?',
        'options' => ['20 Minuten', '35 Minuten', '50 Minuten'],
        'correct' => 1,
        'reveal'  => 'Zwanzig Minuten. Genau deshalb ist Michelhausen die am stärksten wachsende Gemeinde Österreichs — und deshalb kennen sich hier so viele noch nicht.',
    ],

    // ---- Anti-abuse --------------------------------------------------------
    'rate_limit_max'    => 5,     // signups per IP …
    'rate_limit_window' => 3600,  // … per this many seconds

    // ---- Mail --------------------------------------------------------------
    'mail_from'      => 'quiz@team-michelhausen.at',
    'mail_from_name' => 'Wirtshausquiz · Team Michelhausen',
    'mail_reply_to'  => 'quiz@team-michelhausen.at',

    'smtp_host'   => 'mx2e51.netcup.net',
    'smtp_port'   => 587,
    'smtp_secure' => 'tls',
    'smtp_user'   => 'quiz@team-michelhausen.at',
    // SECRET — set on the server in data/config.json: {"smtp_pass":"…"}
    'smtp_pass'   => '',

    // Where signup notifications go (empty = no notification).
    'notify_to'   => 'markus.blumei@gmail.com',
];
