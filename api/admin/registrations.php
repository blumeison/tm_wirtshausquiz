<?php
/**
 * Backoffice: registrations of the current quiz night.
 *
 *   GET                  -> list with derived position/slot/free round + counters
 *   GET ?format=csv      -> the same list as CSV (Excel-friendly: ; and BOM)
 *   POST {action:"cancel", id} -> cancel a team (waitlist moves up by itself)
 *   POST {action:"create", …}  -> enter a team by hand (phone signups)
 */
require_once __DIR__ . '/../auth.php';

$me  = require_role('EDITOR');
$cfg = wq_config();
$sessionId = $cfg['session_id'];

/**
 * A team entered by hand, typically after a phone call to the number on the flyer.
 * Unlike the public form: e-mail is optional (phone or e-mail is enough), no consent
 * checkbox (the caller agreed on the phone), no rate limit and no hard stop at the
 * waitlist limit — whoever types it in knows what they are doing. The confirmation
 * mail only goes out when asked for and an address exists.
 */
function create_manual($in, $cfg, $me)
{
    require_once __DIR__ . '/../mail_templates.php';

    $errors = [];
    $teamName = clean_str(isset($in['teamName']) ? $in['teamName'] : '', 60);
    if (mb_strlen($teamName) < 2) {
        $errors['teamName'] = 'Teamname fehlt (mindestens 2 Zeichen).';
    }
    $captainName = clean_str(isset($in['captainName']) ? $in['captainName'] : '', 60);
    if (mb_strlen($captainName) < 2) {
        $errors['captainName'] = 'Name der Kontaktperson fehlt.';
    }
    $emailRaw = clean_str(isset($in['email']) ? $in['email'] : '', 120);
    $email = $emailRaw === '' ? '' : clean_email($emailRaw);
    if ($emailRaw !== '' && $email === '') {
        $errors['email'] = 'Diese E-Mail-Adresse sieht nicht richtig aus.';
    }
    $phoneRaw = clean_str(isset($in['phone']) ? $in['phone'] : '', 40);
    $phone = $phoneRaw === '' ? '' : clean_phone($phoneRaw);
    if ($phoneRaw !== '' && $phone === '') {
        $errors['phone'] = 'Diese Telefonnummer sieht nicht richtig aus.';
    }
    if ($email === '' && $phone === '' && !isset($errors['email']) && !isset($errors['phone'])) {
        $errors['phone'] = 'Telefonnummer oder E-Mail — sonst erreicht ihr das Team nicht.';
    }
    $size = (int)(isset($in['size']) ? $in['size'] : 0);
    if ($size < (int)$cfg['team_min'] || $size > (int)$cfg['team_max']) {
        $errors['size'] = 'Ein Team hat ' . (int)$cfg['team_min'] . ' bis ' . (int)$cfg['team_max'] . ' Personen.';
    }
    $heardAllowed = ['telefon', 'freunde', 'facebook', 'instagram', 'schwarzesbrett', 'plakat',
                     'flyer', 'zeitung', 'wirt', 'sonstiges'];
    $heardFrom = clean_str(isset($in['heardFrom']) ? $in['heardFrom'] : '', 20);
    if (!in_array($heardFrom, $heardAllowed, true)) {
        $heardFrom = 'telefon';
    }
    $note = clean_str(isset($in['note']) ? $in['note'] : '', 500);
    $looking = !empty($in['lookingForPlayers']);
    $sendMail = !empty($in['sendMail']) && $email !== '';

    if (count($errors) > 0) {
        fail(422, 'Bitte die markierten Felder prüfen.', ['fields' => $errors]);
    }

    $res = null;
    with_registrations($cfg['session_id'], function ($list, &$res) use (
        $cfg, $me, $teamName, $captainName, $email, $phone, $size, $note, $looking, $heardFrom
    ) {
        foreach ($list as $r) {
            if ((isset($r['status']) ? $r['status'] : 'ACTIVE') === 'CANCELLED') {
                continue;
            }
            if ($email !== '' && norm_key(isset($r['email']) ? $r['email'] : '') === norm_key($email)) {
                $res = ['error' => 'Mit dieser E-Mail-Adresse ist schon „' . $r['teamName'] . '" angemeldet.'];
                return null;
            }
            if (norm_key(isset($r['teamName']) ? $r['teamName'] : '') === norm_key($teamName)) {
                $res = ['error' => 'Den Teamnamen „' . $teamName . '" gibt es schon.'];
                return null;
            }
        }
        $reg = [
            'id'                => gen_id('reg_'),
            'sessionId'         => $cfg['session_id'],
            'teamName'          => $teamName,
            'captainName'       => $captainName,
            'email'             => $email,
            'phone'             => $phone,
            'size'              => $size,
            'lookingForPlayers' => $looking,
            'note'              => $note,
            'heardFrom'         => $heardFrom,
            'tmSrc'             => '',
            'tmCh'              => '',
            'referrer'          => '',
            'status'            => 'ACTIVE',
            'cancelToken'       => gen_token(),
            'createdAt'         => now_iso(),
            'createdBy'         => $me['email'],
        ];
        $list[] = $reg;
        $st = standings($list, $cfg);
        $slot = 'CONFIRMED';
        foreach ($st['entries'] as $e) {
            if ($e['id'] === $reg['id']) {
                $slot = $e['slot'];
            }
        }
        $res = ['reg' => $reg, 'slot' => $slot];
        return $list;
    }, $res);

    if (isset($res['error'])) {
        fail(409, $res['error']);
    }
    if (!isset($res['reg'])) {
        fail(500, 'Konnte nicht gespeichert werden.');
    }

    $mailSent = false;
    if ($sendMail) {
        $cancelUrl = rtrim($cfg['site_url'], '/') . '/absage.html?t=' . $res['reg']['cancelToken'];
        // Manual entries never get the (frozen) free round, so promoRank is 0.
        $mail = wq_confirmation_mail($res['reg'], $res['slot'], 0, $cfg, $cancelUrl);
        $mailSent = (bool)send_mail($email, $mail['subject'], $mail['html'], $mail['text']);
    }
    ok(['teamName' => $teamName, 'slot' => $res['slot'], 'mailSent' => $mailSent]);
}

$method = strtoupper(isset($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : 'GET');

// ---- Actions ---------------------------------------------------------------
if ($method === 'POST') {
    $in = read_json_body();
    $action = isset($in['action']) ? $in['action'] : '';
    $id = clean_str(isset($in['id']) ? $in['id'] : '', 40);

    if ($action === 'create') {
        create_manual($in, $cfg, $me);
    }
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
         'status', 'createdAt', 'createdBy', 'cancelledAt', 'cancelledBy', 'heardFrom', 'tmSrc', 'tmCh', 'referrer', 'ip'];
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
              'flyer' => 'Flyer im Postkasten', 'telefon' => 'Telefonisch angemeldet', 'wirt' => 'Im Gasthaus', 'sonstiges' => 'Anders'];
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
