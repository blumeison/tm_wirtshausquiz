<?php
/**
 * GET api/counters.php — public event facts and live counts.
 * Returns no personal data: only numbers the pages need.
 */

require_once __DIR__ . '/lib.php';

require_method('GET');

$cfg  = wq_config();
$list = read_registrations($cfg['session_id']);
$st   = standings($list, $cfg);

$full = $st['teamsTotal'] >= (int)$cfg['waitlist_from'];

ok([
    'event' => [
        'title'      => $cfg['event_title'],
        'date'       => $cfg['event_date'],
        'dateLong'   => fmt_date_de($cfg['event_date']),
        'doorsTime'  => $cfg['doors_time'],
        'startTime'  => $cfg['start_time'],
        'endTime'    => $cfg['end_time'],
        'venue'      => $cfg['venue'],
        'venueShort' => $cfg['venue_short'],
        'address'    => $cfg['address'],
        'phone'      => $cfg['venue_phone'],
        'mapsUrl'    => $cfg['maps_url'],
    ],
    'rules' => [
        'teamMin' => (int)$cfg['team_min'],
        'teamMax' => (int)$cfg['team_max'],
    ],
    'promo' => [
        'enabled' => !empty($cfg['promo_enabled']),
        'teams'   => (int)$cfg['promo_teams'],
        'left'    => $st['promoLeft'],
        'label'   => $cfg['promo_label'],
    ],
    'counters' => [
        'open'           => !empty($cfg['registration_open']) && !$full,
        'full'           => $full,
        'capacity'       => (int)$cfg['capacity_teams'],
        'teamsTotal'     => $st['teamsTotal'],
        'teamsConfirmed' => $st['teamsConfirmed'],
        'teamsWaitlist'  => $st['teamsWaitlist'],
        'spotsLeft'      => $st['spotsLeft'],
    ],
]);
