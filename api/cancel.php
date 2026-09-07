<?php
/**
 * api/cancel.php — self-service cancellation via the token from the
 * confirmation mail.
 *
 * GET  → what this token belongs to (so the page can name the team).
 * POST → actually cancel.
 *
 * The split matters: mail clients and link scanners prefetch GET links. A
 * cancellation behind a plain GET would fire the moment the confirmation mail
 * is opened.
 */

require_once __DIR__ . '/lib.php';

$cfg    = wq_config();
$token  = clean_str(isset($_GET['t']) ? $_GET['t'] : '', 64);
$method = strtoupper(isset($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : 'GET');

if (!preg_match('/^[a-f0-9]{32}$/', $token)) {
    fail(400, 'Dieser Link ist unvollständig. Bitte kopiere ihn ganz aus der E-Mail.');
}

$sessionId = $cfg['session_id'];

if ($method === 'GET') {
    $list = read_registrations($sessionId);
    $i = find_by_token($list, $token);
    if ($i < 0) {
        fail(404, 'Zu diesem Link finden wir keine Anmeldung. Vielleicht wurde sie schon gelöscht.');
    }
    $r  = $list[$i];
    $st = standings($list, $cfg);
    $slot = 'CONFIRMED';
    foreach ($st['entries'] as $e) {
        if ($e['id'] === $r['id']) {
            $slot = $e['slot'];
            break;
        }
    }
    ok([
        'teamName'  => $r['teamName'],
        'size'      => (int)$r['size'],
        'cancelled' => (isset($r['status']) && $r['status'] === 'CANCELLED'),
        'slot'      => $slot,
        'event'     => [
            'dateLong'  => fmt_date_de($cfg['event_date']),
            'startTime' => $cfg['start_time'],
            'venue'     => $cfg['venue'],
        ],
    ]);
}

require_method('POST');

$result = null;
with_registrations($sessionId, function ($list, &$result) use ($token) {
    $i = find_by_token($list, $token);
    if ($i < 0) {
        $result = ['error' => 'Zu diesem Link finden wir keine Anmeldung.'];
        return null;
    }
    if (isset($list[$i]['status']) && $list[$i]['status'] === 'CANCELLED') {
        $result = ['already' => true, 'teamName' => $list[$i]['teamName']];
        return null; // nothing to write
    }
    $list[$i]['status']      = 'CANCELLED';
    $list[$i]['cancelledAt'] = now_iso();
    $result = ['teamName' => $list[$i]['teamName']];
    return $list;
}, $result);

if (isset($result['error'])) {
    fail(404, $result['error']);
}

ok([
    'teamName' => $result['teamName'],
    'already'  => !empty($result['already']),
]);
