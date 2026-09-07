<?php
/**
 * GET api/quizfrage.php — die Frage, auf die Werbescreen und Fragenplakat zeigen.
 *
 * Liefert bewusst auch die richtige Antwort mit: Die Landeseite muss sofort
 * „Richtig" oder „Knapp daneben" anzeigen können, und geheim ist hier nichts —
 * die Auflösung steht ohnehin auf jeder der drei Zielseiten.
 */

require_once __DIR__ . '/lib.php';

require_method('GET');

$cfg = wq_config();
$q   = isset($cfg['screen_question']) ? $cfg['screen_question'] : null;

if (!is_array($q) || empty($q['prompt']) || empty($q['options'])) {
    fail(404, 'Derzeit ist keine Frage hinterlegt.');
}

$options = array_values((array)$q['options']);
$correct = (int)(isset($q['correct']) ? $q['correct'] : 0);
if ($correct < 1 || $correct > count($options)) {
    fail(500, 'Die hinterlegte Frage ist nicht schlüssig konfiguriert.');
}

$list = read_registrations($cfg['session_id']);
$st   = standings($list, $cfg);

ok([
    'question' => [
        'prompt'  => $q['prompt'],
        'options' => $options,
        'correct' => $correct,
        'reveal'  => isset($q['reveal']) ? $q['reveal'] : '',
    ],
    'event' => [
        'dateLong'  => fmt_date_de($cfg['event_date']),
        'doorsTime' => $cfg['doors_time'],
        'startTime' => $cfg['start_time'],
        'venue'     => $cfg['venue_short'],
        'address'   => $cfg['address'],
    ],
    'rules' => [
        'teamMin' => (int)$cfg['team_min'],
        'teamMax' => (int)$cfg['team_max'],
    ],
    'promo' => [
        'enabled' => !empty($cfg['promo_enabled']),
        'left'    => $st['promoLeft'],
        'label'   => $cfg['promo_label'],
    ],
    'open' => !empty($cfg['registration_open'])
              && $st['teamsTotal'] < (int)$cfg['waitlist_from'],
]);
