<?php
/** GET ?id= -> one quiz night (default: the current one) + event facts for the builder. */
require_once __DIR__ . '/../sessions_lib.php';

require_role('EDITOR');

$cfg = wq_config();
$id = isset($_GET['id']) && is_string($_GET['id']) && $_GET['id'] !== '' ? $_GET['id'] : $cfg['session_id'];
if (!valid_session_id($id)) {
    fail(400, 'ungültige Session');
}

$s = read_session($id);
if (!isset($s['finale']) || !is_array($s['finale'])) {
    $s['finale'] = ['masterId' => null, 'tiebreakId' => null];
}

ok([
    'session' => $s,
    'event'   => [
        'title'      => $cfg['event_title'],
        'dateLong'   => fmt_date_de($cfg['event_date']),
        'doorsTime'  => $cfg['doors_time'],
        'startTime'  => $cfg['start_time'],
        'endTime'    => $cfg['end_time'],
        'venueShort' => $cfg['venue_short'],
    ],
]);
