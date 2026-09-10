<?php
/**
 * POST {id?, type, prompt, answer, points, difficulty, category, tags, status, payload}
 * Creates a question (source MANUAL) or updates one. The type is fixed once a
 * question exists — its payload would no longer fit.
 */
require_once __DIR__ . '/../questions_lib.php';

$me = require_role('EDITOR');
require_method('POST');

$in = read_json_body();
list($data, $err) = validate_question($in);
if ($err !== null) {
    fail(422, $err);
}
$id = isset($in['id']) && is_string($in['id']) ? clean_str($in['id'], 40) : '';

$res = null;
with_questions(function ($list, &$res) use ($data, $id, $me) {
    $now = now_iso();
    if ($id !== '') {
        $i = find_question_index($list, $id);
        if ($i < 0) {
            $res = ['error' => 'Diese Frage gibt es nicht mehr — wurde sie inzwischen gelöscht?', 'code' => 404];
            return null;
        }
        if ($list[$i]['type'] !== $data['type']) {
            $res = ['error' => 'Der Fragetyp lässt sich nachträglich nicht ändern.', 'code' => 409];
            return null;
        }
        $list[$i] = array_merge($list[$i], $data, ['updatedAt' => $now, 'updatedBy' => $me['email']]);
        $res = ['question' => $list[$i]];
        return $list;
    }
    $q = array_merge(['id' => gen_id('q_')], $data, [
        'source'    => 'MANUAL',
        'createdBy' => $me['email'],
        'createdAt' => $now,
        'updatedAt' => $now,
        'updatedBy' => $me['email'],
    ]);
    $list[] = $q;
    $res = ['question' => $q];
    return $list;
}, $res);

if (isset($res['error'])) {
    fail($res['code'], $res['error']);
}
ok($res);
