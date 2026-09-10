<?php
/**
 * GET ?id=<jobId> -> the parked outcome of a generation whose connection was cut.
 *   {pending:true} while Claude is still working, else {event: {t:"result"|"error", …}}
 */
require_once __DIR__ . '/../ai_lib.php';

$me = require_role('EDITOR');
require_method('GET');

$id = isset($_GET['id']) && is_string($_GET['id']) && preg_match('/^[a-f0-9]{16,40}$/', $_GET['id']) ? $_GET['id'] : '';
if ($id === '') {
    fail(400, 'ungültige Auftragsnummer');
}
$job = ai_job_load($id);
if ($job === null) {
    fail(404, 'Diesen Auftrag gibt es nicht (mehr).');
}
if ($job['owner'] !== $me['email']) {
    fail(403, 'forbidden');
}
$ev = isset($job['event']) ? $job['event'] : ['t' => 'pending'];
if (!isset($ev['t']) || $ev['t'] === 'pending') {
    // A job that stays pending far longer than any generation has died with its PHP process.
    if (time() - (int)$job['at'] > 420) {
        ok(['event' => ['t' => 'error', 'message' => 'Die Generierung ist am Server abgebrochen. Bitte nochmal erzeugen.']]);
    }
    ok(['pending' => true]);
}
ok(['event' => $ev]);
