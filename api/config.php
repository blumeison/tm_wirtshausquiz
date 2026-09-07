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
    'team_max'          => 6,
    'capacity_teams'    => 12,   // teams that get a confirmed spot
    'waitlist_from'     => 14,   // hard stop: no signups at all beyond this

    // ---- Free-round promo --------------------------------------------------
    // OFF until it is agreed with the Wirt who pays. Flip in data/config.json:
    //   {"promo_enabled": true}
    // Nothing about the promo is shown or promised while this is false.
    'promo_enabled' => false,
    'promo_teams'   => 5,
    'promo_label'   => 'Die ersten 5 Teams bekommen die erste Runde aufs Haus.',

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
