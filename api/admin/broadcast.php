<?php
/**
 * Backoffice: eine Rundmail an alle angemeldeten Teams (z. B. „ihr dürft schon ab 17 Uhr kommen“).
 *
 *   GET                               -> Empfänger + bisher verschickte Rundmails
 *   POST {subject, body, test:true}   -> nur an mich selbst (Vorschau im eigenen Postfach)
 *   POST {subject, body}              -> an alle Teams, jede Adresse bekommt eine eigene Mail
 *
 * Absender ist quiz@team-michelhausen.at (wie die Anmeldebestätigung). Text ist reiner Text:
 * Leerzeile = Absatz, **so** = fett. Dieselbe Mail (gleicher Betreff) geht nur einmal raus.
 */
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../mail_templates.php';

$me  = require_role('ADMIN');
$cfg = wq_config();
$sid = $cfg['session_id'];
$dir = WQ_DATA_DIR . '/mails';
wq_ensure_dir($dir);
$logFile = $dir . '/' . $sid . '.json';
$log = is_file($logFile) ? json_decode((string)file_get_contents($logFile), true) : [];
if (!is_array($log)) {
    $log = [];
}

/** Aktive Teams; Zusatzadressen (z. B. „Zweite E-Mail: …“ bei zusammengelegten Teams) aus der Anmerkung. */
function broadcast_recipients($sid)
{
    $out = [];
    foreach (read_registrations($sid) as $r) {
        if ((isset($r['status']) ? $r['status'] : '') === 'CANCELLED') {
            continue;
        }
        $mails = [];
        if (!empty($r['email'])) {
            $mails[] = $r['email'];
        }
        if (!empty($r['note']) && preg_match_all('/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/i', $r['note'], $m)) {
            $mails = array_merge($mails, $m[0]);
        }
        foreach ($mails as $e) {
            $e = clean_email($e);
            if ($e !== '' && !isset($out[strtolower($e)])) {
                $out[strtolower($e)] = ['email' => $e, 'team' => $r['teamName']];
            }
        }
    }
    return array_values($out);
}

function broadcast_html($body)
{
    $e = htmlspecialchars(str_replace("\r\n", "\n", trim($body)), ENT_QUOTES, 'UTF-8');
    $e = preg_replace('/\*\*(.+?)\*\*/s', '<strong>$1</strong>', $e);
    $paras = preg_split('/\n\s*\n/', $e);
    return implode('', array_map(function ($p) {
        return '<p style="margin:0 0 14px 0;">' . nl2br(trim($p), false) . '</p>';
    }, $paras));
}

if (strtoupper(isset($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : 'GET') !== 'POST') {
    ok(['recipients' => broadcast_recipients($sid), 'sent' => $log, 'from' => $cfg['mail_from'], 'me' => $me['email']]);
}

$in = read_json_body();
$subject = clean_str(isset($in['subject']) ? $in['subject'] : '', 150);
$body = trim((string)(isset($in['body']) ? $in['body'] : ''));
if (mb_strlen($subject) < 3 || mb_strlen($body) < 10) {
    fail(422, 'Betreff und Text fehlen.');
}
if (mb_strlen($body) > 8000) {
    fail(422, 'Der Text ist zu lang.');
}
$test = !empty($in['test']);
$html = wq_mail_shell(htmlspecialchars($subject, ENT_QUOTES, 'UTF-8'), broadcast_html($body));
$text = str_replace('**', '', $body) . "\r\n";

if ($test) {
    $okSent = (bool)send_mail($me['email'], '[Test] ' . $subject, $html, $text);
    if (!$okSent) {
        fail(502, 'Testmail konnte nicht verschickt werden.');
    }
    ok(['test' => true, 'to' => $me['email']]);
}

foreach ($log as $l) {
    if (isset($l['subject']) && $l['subject'] === $subject && empty($in['again'])) {
        fail(409, 'Eine Rundmail mit diesem Betreff wurde schon am ' . $l['at'] . ' verschickt.');
    }
}

@set_time_limit(300);
$results = [];
foreach (broadcast_recipients($sid) as $r) {
    $results[] = ['email' => $r['email'], 'team' => $r['team'], 'ok' => (bool)send_mail($r['email'], $subject, $html, $text)];
}
$log[] = ['at' => now_iso(), 'by' => $me['email'], 'subject' => $subject, 'results' => $results];
file_put_contents($logFile, json_encode($log, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), LOCK_EX);
ok(['results' => $results]);
