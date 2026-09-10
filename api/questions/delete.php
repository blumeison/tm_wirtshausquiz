<?php
/**
 * POST {id} -> remove a question from the pool.
 * P5 (quiz nights) must refuse this for questions that sit in a round.
 */
require_once __DIR__ . '/../questions_lib.php';

require_role('EDITOR');
require_method('POST');

$in = read_json_body();
$id = isset($in['id']) && is_string($in['id']) ? clean_str($in['id'], 40) : '';
if ($id === '') {
    fail(400, 'Keine Frage angegeben');
}

$res = null;
with_questions(function ($list, &$res) use ($id) {
    $i = find_question_index($list, $id);
    if ($i < 0) {
        $res = ['error' => 'Diese Frage gibt es nicht mehr.'];
        return null;
    }
    array_splice($list, $i, 1);
    $res = ['deleted' => $id];
    return $list;
}, $res);

if (isset($res['error'])) {
    fail(404, $res['error']);
}
ok($res);
