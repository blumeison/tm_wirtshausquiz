<?php
/**
 * POST {session: {id?, rev, title, notes, jokerEnabled, rounds, finale}}
 * Saves the whole night. A stale `rev` gets 409 — the builder then reloads.
 */
require_once __DIR__ . '/../sessions_lib.php';

$me = require_role('EDITOR');
require_method('POST');

$in = read_json_body();
$s = isset($in['session']) && is_array($in['session']) ? $in['session'] : [];
$id = isset($s['id']) && is_string($s['id']) && $s['id'] !== '' ? $s['id'] : wq_config()['session_id'];
if (!valid_session_id($id)) {
    fail(400, 'ungültige Session');
}

$qById = [];
foreach (read_questions() as $q) {
    $qById[$q['id']] = $q;
}
list($clean, $err) = validate_session_input($s, $qById);
if ($err !== null) {
    fail(422, $err);
}
$rev = isset($s['rev']) ? (int)$s['rev'] : -1;

$res = null;
with_session_doc($id, function ($doc, &$res) use ($clean, $rev, $me, $id) {
    $cur = isset($doc['rev']) ? (int)$doc['rev'] : 0;
    if ($rev !== $cur) {
        $res = ['conflict' => true];
        return null;
    }
    $doc = array_merge($doc, $clean, [
        'id'        => $id,
        'rev'       => $cur + 1,
        'updatedAt' => now_iso(),
        'updatedBy' => $me['email'],
    ]);
    $res = ['session' => $doc];
    return $doc;
}, $res);

if (!empty($res['conflict'])) {
    fail(409, 'conflict');
}
ok($res);
