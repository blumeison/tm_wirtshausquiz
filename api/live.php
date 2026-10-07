<?php
/**
 * The evening itself: who showed up, and the round scores.
 * One document per quiz night in data/live/<session_id>.json:
 *   {"checkin": {"<regId>": true}, "scores": {"<regId>": {"<roundIndex>": 8.5}}}
 *
 *   GET -> teams (active registrations, signup order) + checkin + scores
 *   POST {action:"checkin", id, on}
 *   POST {action:"score", id, round, pts}   pts null/"" clears the cell
 *
 * Every write touches exactly one cell under an exclusive lock, so a helper on
 * the phone and the laptop at the beamer can type at the same time.
 * Kept apart from the registrations on purpose: the evening is not the signup.
 */
require_once __DIR__ . '/auth.php';

require_role('EDITOR');
$cfg = wq_config();
$sid = $cfg['session_id'];
$dir = WQ_DATA_DIR . '/live';
wq_ensure_dir($dir);
$file = $dir . '/' . $sid . '.json';

$teams = [];
foreach (standings(read_registrations($sid), $cfg)['entries'] as $e) {
    $teams[] = ['id' => $e['id'], 'name' => $e['teamName'], 'slot' => $e['slot']];
}

function live_read_doc($raw)
{
    $doc = json_decode((string)$raw, true);
    if (!is_array($doc)) {
        $doc = [];
    }
    foreach (['checkin', 'scores'] as $k) {
        if (!isset($doc[$k]) || !is_array($doc[$k])) {
            $doc[$k] = [];
        }
    }
    return $doc;
}

function live_out($doc, $teams)
{
    ok(['teams' => $teams, 'checkin' => (object)$doc['checkin'], 'scores' => (object)array_map(function ($s) {
        return (object)$s;
    }, $doc['scores'])]);
}

if (strtoupper(isset($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : 'GET') !== 'POST') {
    live_out(live_read_doc(is_file($file) ? file_get_contents($file) : ''), $teams);
}

$in = read_json_body();
$id = clean_str(isset($in['id']) ? $in['id'] : '', 40);
if (!in_array($id, array_column($teams, 'id'), true)) {
    fail(404, 'Team nicht gefunden (abgesagt?)');
}
$action = isset($in['action']) ? $in['action'] : '';
if ($action === 'score') {
    $round = isset($in['round']) ? $in['round'] : null;
    if (!is_int($round) || $round < 0 || $round > 19) {
        fail(400, 'ungültige Runde');
    }
    $pts = isset($in['pts']) ? $in['pts'] : null;
    if ($pts !== null && $pts !== '' && (!is_numeric($pts) || $pts < 0 || $pts > 1000)) {
        fail(422, 'Punkte bitte als Zahl zwischen 0 und 1000.');
    }
} elseif ($action !== 'checkin') {
    fail(400, 'Unbekannte Aktion');
}

$fh = fopen($file, 'c+');
if ($fh === false || !flock($fh, LOCK_EX)) {
    fail(500, 'Speicher belegt, bitte nochmal');
}
$doc = live_read_doc(stream_get_contents($fh));
if ($action === 'checkin') {
    if (!empty($in['on'])) {
        $doc['checkin'][$id] = true;
    } else {
        unset($doc['checkin'][$id]);
    }
} else {
    $key = (string)$round;
    if ($pts === null || $pts === '') {
        unset($doc['scores'][$id][$key]);
    } else {
        $doc['scores'][$id][$key] = round((float)$pts, 1);
    }
}
$doc['updatedAt'] = now_iso();
// FORCE_OBJECT keeps round keys as keys: {"0": 8} must not turn into a list.
$json = json_encode($doc, JSON_UNESCAPED_UNICODE | JSON_FORCE_OBJECT | JSON_PRETTY_PRINT);
ftruncate($fh, 0);
rewind($fh);
fwrite($fh, $json);
fflush($fh);
flock($fh, LOCK_UN);
fclose($fh);

live_out($doc, $teams);
