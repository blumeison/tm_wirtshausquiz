<?php
/**
 * POST — generate a round or the master question with Claude.
 *   {mode:"round", topic, roundIndex?, count, difficulty, types[], masterClue, notes}
 *   {mode:"master", idea, difficulty}
 *
 * Answers as NDJSON, one event per line, so the browser sees progress and the
 * proxy in front of PHP never sees an idle connection while Claude thinks:
 *   {"t":"start"} · {"t":"beat","phase":…,"chars":…} · {"t":"result",…} | {"t":"error","message":…}
 * Nothing is saved here — the editor picks what to keep, then import.php stores it.
 */
require_once __DIR__ . '/../ai_lib.php';

require_role('EDITOR');
require_method('POST');

$in = read_json_body();
$cfg = wq_config();
$session = read_session($cfg['session_id']);
$qById = [];
foreach (read_questions() as $q) {
    $qById[$q['id']] = $q;
}

$mode = isset($in['mode']) && $in['mode'] === 'master' ? 'master' : 'round';
if ($mode === 'round') {
    list($user, $meta, $err) = ai_build_round($in, $session, $qById);
    $schema = ai_round_schema();
} else {
    list($user, $meta, $err) = ai_build_master($in, $session, $qById);
    $schema = ai_master_schema();
}
if ($err !== null) {
    fail(422, $err);
}

// ---- From here on the answer is a stream ------------------------------------------
@set_time_limit(330);
@ini_set('zlib.output_compression', '0');
header('Content-Type: application/x-ndjson; charset=utf-8');
header('Cache-Control: no-store');
header('X-Accel-Buffering: no');
while (ob_get_level() > 0) {
    ob_end_flush();
}
$emit = function ($obj) {
    echo json_encode($obj, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
    flush();
};

$emit(['t' => 'start', 'mode' => $mode]);
$r = ai_call(ai_system_prompt(), $user, $schema, function ($phase, $chars) use ($emit) {
    $emit(['t' => 'beat', 'phase' => $phase, 'chars' => $chars]);
});
if (!$r['ok']) {
    $emit(['t' => 'error', 'message' => $r['error']]);
    exit;
}

$d = $r['data'];
$usage = ai_usage($r['usage']);
$roundCount = count(isset($session['rounds']) ? $session['rounds'] : []);

if ($mode === 'round') {
    $title = q_str((string)ai_get($d, 'roundTitle', ''), 80);
    $items = [];
    foreach ((array)ai_get($d, 'questions', []) as $q) {
        $items[] = ai_item($q, $meta['roundIndex'], $title);
    }
    $emit([
        't' => 'result', 'mode' => 'round',
        'roundTitle' => $title,
        'roundIntro' => q_str((string)ai_get($d, 'roundIntro', ''), 500),
        'roundIndex' => $meta['roundIndex'],
        'items' => $items, 'usage' => $usage, 'model' => $r['model'], 'fellBack' => $r['fellBack'],
    ]);
    exit;
}

$items = [];
foreach ((array)ai_get($d, 'clueQuestions', []) as $cq) {
    $ri = (int)ai_get($cq, 'roundIndex', -1);
    $q = ai_get($cq, 'question', []);
    $q['masterClue'] = true;
    $items[] = ai_item($q, ($ri >= 0 && $ri < $roundCount) ? $ri : null, 'masterhinweis');
}
$emit([
    't' => 'result', 'mode' => 'master',
    'master' => ai_master_item(ai_get($d, 'master', [])),
    'items' => $items, 'usage' => $usage, 'model' => $r['model'], 'fellBack' => $r['fellBack'],
]);
