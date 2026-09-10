<?php
/**
 * Backoffice: registrations of the current quiz night.
 *
 *   GET                  -> list with derived position/slot/free round + counters
 *   GET ?format=csv      -> the same list as CSV (Excel-friendly: ; and BOM)
 *   POST {action:"cancel", id} -> cancel a team (waitlist moves up by itself)
 */
require_once __DIR__ . '/../auth.php';

$me  = require_role('EDITOR');
$cfg = wq_config();
$sessionId = $cfg['session_id'];

$method = strtoupper(isset($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : 'GET');

// ---- Actions ---------------------------------------------------------------
if ($method === 'POST') {
    $in = read_json_body();
    $action = isset($in['action']) ? $in['action'] : '';
    $id = clean_str(isset($in['id']) ? $in['id'] : '', 40);

    if ($action !== 'cancel' || $id === '') {
        fail(400, 'Unbekannte Aktion');
    }
    $res = null;
    with_registrations($sessionId, function ($list, &$res) use ($id, $me) {
        foreach ($list as $i => $r) {
            if ((isset($r['id']) ? $r['id'] : '') !== $id) {
                continue;
            }
            if ((isset($r['status']) ? $r['status'] : 'ACTIVE') === 'CANCELLED') {
                $res = ['already' => true, 'teamName' => $r['teamName']];
                return null;
            }
            $list[$i]['status']      = 'CANCELLED';
            $list[$i]['cancelledAt'] = now_iso();
            $list[$i]['cancelledBy'] = $me['email'];
            $res = ['already' => false, 'teamName' => $r['teamName']];
            return $list;
        }
        $res = ['error' => 'Anmeldung nicht gefunden'];
        return null;
    }, $res);
    if (isset($res['error'])) {
        fail(404, $res['error']);
    }
    ok($res);
}

// ---- List ------------------------------------------------------------------
$list = read_registrations($sessionId);
$st = standings($list, $cfg);

$derived = [];
foreach ($st['entries'] as $e) {
    $derived[$e['id']] = $e;
}

// cancelToken is a capability (whoever has it can cancel) — it never leaves the server.
$pick = ['id', 'teamName', 'captainName', 'email', 'phone', 'size', 'lookingForPlayers', 'note',
         'status', 'createdAt', 'cancelledAt', 'cancelledBy', 'heardFrom', 'tmSrc', 'tmCh', 'referrer', 'ip'];
$rows = [];
foreach ($list as $r) {
    $row = [];
    foreach ($pick as $k) {
        $row[$k] = isset($r[$k]) ? $r[$k] : null;
    }
    if (!$row['status']) {
        $row['status'] = 'ACTIVE';
    }
    $d = isset($derived[$r['id']]) ? $derived[$r['id']] : null;
    $row['position']  = $d ? $d['position'] : null;
    $row['slot']      = $d ? $d['slot'] : null;
    $row['promoRank'] = $d ? (int)$d['promoRank'] : 0;
    $rows[] = $row;
}
usort($rows, function ($a, $b) {
    // active in queue order first, then cancelled (newest first)
    $ac = $a['status'] === 'CANCELLED';
    $bc = $b['status'] === 'CANCELLED';
    if ($ac !== $bc) {
        return $ac ? 1 : -1;
    }
    if (!$ac) {
        return $a['position'] - $b['position'];
    }
    return strcmp((string)$b['cancelledAt'], (string)$a['cancelledAt']);
});

if (isset($_GET['format']) && $_GET['format'] === 'csv') {
    $heard = ['freunde' => 'Freunde/Bekannte', 'facebook' => 'Facebook', 'instagram' => 'Instagram',
              'schwarzesbrett' => 'Schwarzes Brett (WhatsApp)', 'plakat' => 'Plakat', 'zeitung' => 'Zeitung',
              'screen' => 'Werbescreen', 'wirt' => 'Im Gasthaus', 'sonstiges' => 'Anders'];
    // A cell starting with = + - @ would run as a formula in Excel.
    $cell = function ($v) {
        $v = str_replace(["\r", "\n"], ' ', (string)$v);
        if ($v !== '' && strpos('=+-@', $v[0]) !== false) {
            $v = "'" . $v;
        }
        return '"' . str_replace('"', '""', $v) . '"';
    };
    header('Content-Type: text/csv; charset=utf-8');
    header('Cache-Control: no-store');
    header('Content-Disposition: attachment; filename="wirtshausquiz-anmeldungen-' . date('Y-m-d') . '.csv"');
    echo "\xEF\xBB\xBF";
    $head = ['Pos', 'Status', 'Team', 'Kapitän', 'E-Mail', 'Telefon', 'Personen', 'Sucht Mitspieler',
             'Freirunde', 'Erfahren über', 'QR/Kurzlink', 'Kam von', 'Anmerkung', 'Angemeldet', 'Storniert'];
    echo implode(';', array_map($cell, $head)) . "\r\n";
    foreach ($rows as $r) {
        $status = $r['status'] === 'CANCELLED' ? 'storniert' : ($r['slot'] === 'WAITLIST' ? 'Warteliste' : 'bestätigt');
        $line = [
            $r['position'], $status, $r['teamName'], $r['captainName'], $r['email'], $r['phone'], $r['size'],
            $r['lookingForPlayers'] ? 'ja' : '', $r['promoRank'] > 0 ? ('#' . $r['promoRank']) : '',
            $r['heardFrom'] ? (isset($heard[$r['heardFrom']]) ? $heard[$r['heardFrom']] : $r['heardFrom']) : '',
            trim($r['tmSrc'] . ($r['tmCh'] ? '/' . $r['tmCh'] : ''), '/'),
            $r['referrer'], $r['note'], $r['createdAt'], $r['cancelledAt'],
        ];
        echo implode(';', array_map($cell, $line)) . "\r\n";
    }
    exit;
}

$people = 0;
foreach ($rows as $r) {
    if ($r['status'] !== 'CANCELLED' && $r['slot'] === 'CONFIRMED') {
        $people += (int)$r['size'];
    }
}

ok([
    'sessionId' => $sessionId,
    'event'     => ['date' => $cfg['event_date'] ?? null],
    'capacity'  => (int)$cfg['capacity_teams'],
    'counters'  => [
        'teamsTotal'     => $st['teamsTotal'],
        'teamsConfirmed' => $st['teamsConfirmed'],
        'teamsWaitlist'  => $st['teamsWaitlist'],
        'peopleConfirmed' => $people,
        'spotsLeft'      => $st['spotsLeft'],
        'cancelled'      => count($rows) - $st['teamsTotal'],
    ],
    'rows'      => $rows,
    'me'        => ['email' => $me['email'], 'role' => $me['role']],
]);
