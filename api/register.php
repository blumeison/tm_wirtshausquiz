<?php
/**
 * POST api/register.php — public team signup. No login by design.
 */

require_once __DIR__ . '/lib.php';
require_once __DIR__ . '/mail_templates.php';

require_method('POST');

$cfg = wq_config();
$in  = read_json_body();

if (empty($cfg['registration_open'])) {
    fail(403, 'Die Anmeldung ist derzeit geschlossen.');
}

// Honeypot: a real browser leaves this empty, most bots fill everything.
// Answer 200 so the bot thinks it worked and doesn't retry with variations.
if (clean_str(isset($in['website']) ? $in['website'] : '', 100) !== '') {
    ok(['slot' => 'CONFIRMED', 'promoRank' => 0]);
}

// ---- Validation ------------------------------------------------------------
$errors = [];

$teamName = clean_str(isset($in['teamName']) ? $in['teamName'] : '', 60);
if (mb_strlen($teamName) < 2) {
    $errors['teamName'] = 'Bitte gebt eurem Team einen Namen (mindestens 2 Zeichen).';
}

$captainName = clean_str(isset($in['captainName']) ? $in['captainName'] : '', 60);
if (mb_strlen($captainName) < 2) {
    $errors['captainName'] = 'Bitte trag deinen Namen ein.';
}

$email = clean_email(isset($in['email']) ? $in['email'] : '');
if ($email === '') {
    $errors['email'] = 'Bitte eine gültige E-Mail-Adresse angeben — dorthin geht die Bestätigung.';
}

$phoneRaw = clean_str(isset($in['phone']) ? $in['phone'] : '', 40);
$phone    = clean_phone($phoneRaw);
if ($phoneRaw !== '' && $phone === '') {
    $errors['phone'] = 'Diese Telefonnummer sieht nicht richtig aus.';
}

$size = (int)(isset($in['size']) ? $in['size'] : 0);
$min  = (int)$cfg['team_min'];
$max  = (int)$cfg['team_max'];
if ($size < $min || $size > $max) {
    $errors['size'] = 'Ein Team besteht aus ' . $min . ' bis ' . $max . ' Personen.';
}

$note = clean_str(isset($in['note']) ? $in['note'] : '', 500);
$looking = !empty($in['lookingForPlayers']);

if (empty($in['consent'])) {
    $errors['consent'] = 'Ohne Zustimmung zur Datenschutzerklärung können wir die Anmeldung nicht speichern.';
}

if (count($errors) > 0) {
    fail(422, 'Bitte prüft die markierten Felder.', ['fields' => $errors]);
}

if (!rate_limit_ok(client_ip())) {
    fail(429, 'Von diesem Anschluss kamen gerade sehr viele Anmeldungen. Bitte versucht es in einer Stunde nochmal — oder ruft uns an.');
}

// ---- Write -----------------------------------------------------------------
$sessionId = $cfg['session_id'];
$result    = null;

with_registrations($sessionId, function ($list, &$result) use (
    $cfg, $teamName, $captainName, $email, $phone, $size, $note, $looking
) {
    // Duplicate check inside the lock, so two simultaneous submits can't both pass.
    $emailKey = norm_key($email);
    $teamKey  = norm_key($teamName);
    foreach ($list as $r) {
        if ((isset($r['status']) ? $r['status'] : 'ACTIVE') === 'CANCELLED') {
            continue;
        }
        if (norm_key(isset($r['email']) ? $r['email'] : '') === $emailKey) {
            $result = ['error' => 'Mit dieser E-Mail-Adresse ist schon ein Team angemeldet. Schau in dein Postfach — dort liegt die Bestätigung.'];
            return null;
        }
        if (norm_key(isset($r['teamName']) ? $r['teamName'] : '') === $teamKey) {
            $result = ['error' => 'Diesen Teamnamen gibt es schon. Sucht euch bitte einen anderen — am Quizabend muss man euch auseinanderhalten können.'];
            return null;
        }
    }

    // Hard stop well beyond the waitlist, so the evening stays organisable.
    $activeCount = 0;
    foreach ($list as $r) {
        if ((isset($r['status']) ? $r['status'] : 'ACTIVE') !== 'CANCELLED') {
            $activeCount++;
        }
    }
    if ($activeCount >= (int)$cfg['waitlist_from']) {
        $result = ['error' => 'Der Quizabend ist ausgebucht, auch die Warteliste ist voll. Melde dich bei uns, dann setzen wir dich beim nächsten Termin ganz vorne auf die Liste.'];
        return null;
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
        'status'            => 'ACTIVE',
        'cancelToken'       => gen_token(),
        'createdAt'         => now_iso(),
        'ip'                => client_ip(),
    ];

    $list[] = $reg;

    // Derive the new standing while still holding the lock.
    $st = standings($list, $cfg);
    $mine = null;
    foreach ($st['entries'] as $e) {
        if ($e['id'] === $reg['id']) {
            $mine = $e;
            break;
        }
    }

    $result = [
        'reg'       => $reg,
        'slot'      => $mine ? $mine['slot'] : 'CONFIRMED',
        'promoRank' => $mine ? (int)$mine['promoRank'] : 0,
        'counters'  => [
            'spotsLeft' => $st['spotsLeft'],
            'promoLeft' => $st['promoLeft'],
            'teamsTotal' => $st['teamsTotal'],
        ],
    ];
    return $list;
}, $result);

if (isset($result['error'])) {
    fail(409, $result['error']);
}
if (!isset($result['reg'])) {
    fail(500, 'Die Anmeldung konnte nicht gespeichert werden. Bitte nochmal versuchen.');
}

// ---- Confirmation mail (never block the response on a mail failure) --------
$cancelUrl = rtrim($cfg['site_url'], '/') . '/absage.html?t=' . $result['reg']['cancelToken'];
$mail = wq_confirmation_mail($result['reg'], $result['slot'], $result['promoRank'], $cfg, $cancelUrl);
$mailSent = send_mail($result['reg']['email'], $mail['subject'], $mail['html'], $mail['text']);

if (!empty($cfg['notify_to'])) {
    $n = $result['reg'];
    $summary = $n['teamName'] . ' (' . (int)$n['size'] . ' Pers.) · ' . $n['captainName']
        . ' · ' . $n['email'] . ($n['phone'] !== '' ? ' · ' . $n['phone'] : '')
        . ' · ' . $result['slot']
        . ($result['promoRank'] > 0 ? ' · Freirunde #' . $result['promoRank'] : '')
        . ($n['note'] !== '' ? "\r\nAnmerkung: " . $n['note'] : '');
    send_mail(
        $cfg['notify_to'],
        'Neue Quiz-Anmeldung: ' . $n['teamName'],
        '<pre style="font:14px/1.6 monospace">' . htmlspecialchars($summary, ENT_QUOTES, 'UTF-8') . '</pre>',
        $summary
    );
}

ok([
    'slot'      => $result['slot'],
    'promoRank' => $result['promoRank'],
    'teamName'  => $result['reg']['teamName'],
    'mailSent'  => (bool)$mailSent,
    'counters'  => $result['counters'],
]);
