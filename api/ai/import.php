<?php
/**
 * POST {items:[{input, roundIndex|null, masterClue, clueNote}], master: input|null, setFinale}
 * Stores the chosen AI questions (status DRAFT, source AI), puts them into
 * their rounds with the 👑 marking and, if asked, makes the master question
 * the finale. Everything is validated again here — the browser only suggests.
 */
require_once __DIR__ . '/../ai_lib.php';

$me = require_role('EDITOR');
require_method('POST');

$in = read_json_body();
$sid = wq_config()['session_id'];

$raw = isset($in['items']) && is_array($in['items']) ? $in['items'] : [];
if (count($raw) > 30) {
    fail(422, 'Zu viele Fragen auf einmal.');
}
$prepared = [];
foreach ($raw as $it) {
    if (!is_array($it) || !isset($it['input']) || !is_array($it['input'])) {
        continue;
    }
    $input = $it['input'];
    $input['status'] = 'DRAFT';
    list($clean, $err) = validate_question($input);
    if ($err !== null) {
        fail(422, 'Eine Frage ist ungültig: ' . $err);
    }
    if ($clean['type'] === 'MASTER') {
        fail(422, 'Masterfragen gehören ins Finale, nicht in eine Runde.');
    }
    $ri = isset($it['roundIndex']) && $it['roundIndex'] !== null && $it['roundIndex'] !== '' ? (int)$it['roundIndex'] : -1;
    $prepared[] = [
        'q'     => $clean,
        'round' => $ri,
        'clue'  => !empty($it['masterClue']),
        'note'  => q_str(isset($it['clueNote']) ? $it['clueNote'] : '', 200),
    ];
}

$master = null;
if (!empty($in['master']) && is_array($in['master'])) {
    $mi = $in['master'];
    $mi['type'] = 'MASTER';
    $mi['status'] = 'DRAFT';
    list($master, $err) = validate_question($mi);
    if ($err !== null) {
        fail(422, 'Die Masterfrage ist ungültig: ' . $err);
    }
}
if (!$prepared && !$master) {
    fail(422, 'Nichts ausgewählt.');
}

// 1) store the questions
$ids = null;
with_questions(function ($list, &$ids) use ($prepared, $master, $me) {
    $now = now_iso();
    $stamp = ['source' => 'AI', 'createdBy' => $me['email'], 'createdAt' => $now, 'updatedAt' => $now, 'updatedBy' => $me['email']];
    $ids = ['items' => [], 'master' => null];
    foreach ($prepared as $p) {
        $q = array_merge(['id' => gen_id('q_')], $p['q'], $stamp);
        $list[] = $q;
        $ids['items'][] = $q['id'];
    }
    if ($master) {
        $q = array_merge(['id' => gen_id('q_')], $master, $stamp);
        $list[] = $q;
        $ids['master'] = $q['id'];
    }
    return $list;
}, $ids);

// 2) place them in the evening
$setFinale = !empty($in['setFinale']);
$res = null;
with_session_doc($sid, function ($doc, &$res) use ($prepared, $ids, $setFinale, $me) {
    $placed = 0;
    $full = 0;
    foreach ($prepared as $k => $p) {
        $ri = $p['round'];
        if ($ri < 0 || !isset($doc['rounds'][$ri])) {
            continue;
        }
        if (count($doc['rounds'][$ri]['questions']) >= 20) {
            $full++;
            continue;
        }
        $doc['rounds'][$ri]['questions'][] = [
            'questionId'     => $ids['items'][$k],
            'pointsOverride' => null,
            'masterClue'     => $p['clue'],
            'clueNote'       => $p['note'],
        ];
        $placed++;
    }
    if ($ids['master'] && $setFinale) {
        if (!isset($doc['finale']) || !is_array($doc['finale'])) {
            $doc['finale'] = ['masterId' => null, 'tiebreakId' => null];
        }
        $doc['finale']['masterId'] = $ids['master'];
    }
    $doc['rev'] = (isset($doc['rev']) ? (int)$doc['rev'] : 0) + 1;
    $doc['updatedAt'] = now_iso();
    $doc['updatedBy'] = $me['email'];
    $res = ['placed' => $placed, 'full' => $full];
    return $doc;
}, $res);

ok([
    'created'  => count($ids['items']) + ($ids['master'] ? 1 : 0),
    'placed'   => $res['placed'],
    'skipped'  => $res['full'],
    'masterId' => $ids['master'],
    'finale'   => (bool)($ids['master'] && $setFinale),
]);
