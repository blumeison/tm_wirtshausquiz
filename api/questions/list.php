<?php
/** GET -> the whole question pool, newest change first. EDITOR and up. */
require_once __DIR__ . '/../sessions_lib.php';

require_role('EDITOR');

$list = read_questions();
usort($list, function ($a, $b) {
    return strcmp((string)(isset($b['updatedAt']) ? $b['updatedAt'] : ''), (string)(isset($a['updatedAt']) ? $a['updatedAt'] : ''));
});

ok([
    'questions' => $list,
    'types'     => question_types(),
    'uploadMax' => upload_max_bytes(),
    'usage'     => (object)question_usage(),
]);
