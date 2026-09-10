<?php
/**
 * Wirtshausquiz — KI-Fragengenerator (P6).
 *
 * Claude Opus 5 over plain HTTP (the host has no Composer), streamed, with a
 * JSON schema as structured output. The prompts are German on purpose: the
 * questions are German and the house style lives in the wording.
 *
 * Two modes:
 *   round  — one round on a topic, optionally with a 👑 clue for the finale
 *   master — the finale's master question + one clue question per round
 *
 * `fallbacks: "default"` is on: if Opus 5 declines a request, the API re-runs
 * it on the recommended fallback model instead of returning nothing.
 */

require_once __DIR__ . '/sessions_lib.php';

define('WQ_AI_MODEL', 'claude-opus-5');
// USD per million tokens (Opus 5) — only for the cost hint in the UI.
define('WQ_AI_PRICE_IN', 5.0);
define('WQ_AI_PRICE_OUT', 25.0);

function ai_types()
{
    return ['MULTIPLE_CHOICE', 'OPEN_TEXT', 'ESTIMATE', 'MAP', 'AUDIO', 'IMAGE'];
}

// ---- Structured output schemas ------------------------------------------------
function ai_nullable($schema)
{
    return ['anyOf' => [$schema, ['type' => 'null']]];
}

function ai_obj($props)
{
    return ['type' => 'object', 'additionalProperties' => false, 'required' => array_keys($props), 'properties' => $props];
}

function ai_question_schema()
{
    $str = ['type' => 'string'];
    return ai_obj([
        'type'            => ['type' => 'string', 'enum' => ai_types()],
        'prompt'          => $str,
        'answer'          => $str,
        'background'      => $str,
        'factCheck'       => $str,
        'category'        => $str,
        'difficulty'      => ['type' => 'integer'],
        'options'         => ai_nullable(['type' => 'array', 'items' => $str]),
        'correctIndex'    => ai_nullable(['type' => 'integer']),
        'acceptedAnswers' => ai_nullable(['type' => 'array', 'items' => $str]),
        'estimateValue'   => ai_nullable(['type' => 'number']),
        'estimateUnit'    => ai_nullable($str),
        'lat'             => ai_nullable(['type' => 'number']),
        'lng'             => ai_nullable(['type' => 'number']),
        'radiusKm'        => ai_nullable(['type' => 'number']),
        'mediaSearch'     => ai_nullable($str),
        'startSeconds'    => ai_nullable(['type' => 'number']),
        'masterClue'      => ['type' => 'boolean'],
        'clueNote'        => ai_nullable($str),
    ]);
}

function ai_round_schema()
{
    return ai_obj([
        'roundTitle' => ['type' => 'string'],
        'roundIntro' => ['type' => 'string'],
        'questions'  => ['type' => 'array', 'items' => ai_question_schema()],
    ]);
}

function ai_master_schema()
{
    $str = ['type' => 'string'];
    return ai_obj([
        'master' => ai_obj([
            'prompt'          => $str,
            'answer'          => $str,
            'hints'           => ['type' => 'array', 'items' => $str],
            'clueExplanation' => $str,
            'background'      => $str,
        ]),
        'clueQuestions' => ['type' => 'array', 'items' => ai_obj([
            'roundIndex' => ['type' => 'integer'],
            'question'   => ai_question_schema(),
        ])],
    ]);
}

// ---- Prompts --------------------------------------------------------------------
function ai_system_prompt()
{
    return <<<'TXT'
Du schreibst Fragen für das Wirtshausquiz von „SPÖ & Unabhängige – Team Michelhausen“: ein Pub-Quiz im Gasthaus Burchhart in Atzelsdorf (Gemeinde Michelhausen, Tullnerfeld, Niederösterreich). An den Tischen sitzen Teams zu 3 bis 5 Leuten, gemischtes Alter, darunter viele Zugezogene aus Pixendorf (Bahnhof Tullnerfeld), die die Gegend noch kaum kennen. Ein Quizmaster liest vor, ein Beamer zeigt die Fragen. Nach jeder Runde tauschen die Tische die Antwortzettel und korrigieren gegenseitig, während der Quizmaster auflöst.

Worum es geht: Fragen, über die am Tisch diskutiert wird, und bei deren Auflösung der Saal „Ah!“ oder „Echt jetzt?“ sagt. Überraschende Blickwinkel statt Schulbuchwissen. Eine Frage darf verblüffen, aber ihre Antwort muss eindeutig und kurz sein: ein Name, ein Wort, eine Zahl. Lokales darf vorkommen, aber so, dass auch Zugezogene eine Chance haben, etwa als Multiple Choice oder Schätzfrage. Runden- und Fragetitel dürfen Schmäh haben.

Jede Frage bringt einen Hintergrund mit (background): zwei bis vier Sätze, die der Quizmaster bei der Auflösung erzählt – die Geschichte hinter der Antwort, eine Pointe, ein Zusammenhang. Locker und gesprochen, freundlich, nicht belehrend. Dazu factCheck: woran man die Antwort nachprüfen kann (Quelle oder Nachschlagestelle). Ist ein Detail unsicher, schreib das dort offen hin, statt es zu glätten.

Fragetypen und was sie brauchen:
- MULTIPLE_CHOICE: genau 4 Optionen, alle plausibel (gleiche Kategorie, ähnliche Größenordnung), correctIndex 0-basiert.
- OPEN_TEXT: acceptedAnswers mit gängigen Schreibvarianten.
- ESTIMATE: estimateValue ist der belegbare richtige Wert, estimateUnit die Einheit. Nur Werte, die sich nicht laufend ändern, oder mit Stichjahr im Fragetext („Stand 2023“).
- MAP: Die Teams sehen eine stumme Karte ohne Beschriftung und markieren den Ort. lat und lng exakt, radiusKm nach Schwierigkeit (Ort in der Region 3–15, Stadt in Europa 30–80, weltweit 150–400). Gut sind Orte, die man sich aus Umrissen, Flüssen und Küsten erschließen kann.
- AUDIO: ein Song, den das Publikum erkennen kann. mediaSearch ist ein YouTube-Suchbegriff (Interpret und Titel), startSeconds die Sekunde, ab der der Song sofort erkennbar ist (Refrain, Riff), falls du sie weißt, sonst null. Erfinde nie eine URL. Die Frage darf mehr sein als „Wer singt das?“ – etwa ein Detail zum Song, das zum Rundenthema passt.
- IMAGE: für Bilderrunden, die als Handout auf dem Tisch liegen. mediaSearch beschreibt, welches Bild gezeigt werden soll und wie man es findet. Die Frage muss aus dem Bild beantwortbar sein.
Nicht verwendete typspezifische Felder sind null.

Schwierigkeit: 1 = am Tisch weiß es jemand nach kurzem Überlegen, 2 = braucht Diskussion, 3 = nur mit Spezialwissen oder Glück. Eine gute Runde steigt leicht an und endet nicht mit ihrer schwersten Frage. Mische die Fragetypen, sodass möglichst keine zwei gleichen direkt aufeinander folgen.

Lass weg: Fragen, deren Antwort sich seit 2024 geändert haben kann (Amtsinhaber, Rekorde, „aktuell“), Fangfragen, Partei- und Tagespolitik, Fragen, die in jedem Pub-Quiz vorkommen.

Die Masterfrage ist das Finale des Abends. Über den Abend verteilt sind einzelne Antworten als Masterhinweise markiert (masterClue). Der Quizmaster sagt sie bei der Auflösung offen an, am Beamer sammeln sie sich auf einer Tafel. Jeder Hinweis für sich ist unauffällig und könnte in viele Richtungen zeigen – erst zusammen ergeben sie einen roten Faden. Im Finale erscheinen dann Countdown-Hinweise vom schwersten zum leichtesten, die Punkte fallen dabei von 50 auf 10, und jedes Team hat einen einzigen Tipp. Nach den Rundenhinweisen allein darf die Lösung noch nicht offensichtlich sein; nach dem letzten Countdown-Hinweis muss sie jeder Tisch kennen können. clueNote erklärt intern (nur für das Quiz-Team), was ein Hinweis zur Lösung beiträgt.
TXT;
}

/** Rounds, answers already used, the finale's master and its clues — as context lines. */
function ai_evening_context($session, $qById)
{
    $rounds = [];
    $used = [];
    $clues = [];
    foreach ((isset($session['rounds']) ? $session['rounds'] : []) as $i => $r) {
        $answers = [];
        foreach ((isset($r['questions']) ? $r['questions'] : []) as $rq) {
            if (!isset($qById[$rq['questionId']])) {
                continue;
            }
            $a = $qById[$rq['questionId']]['answer'];
            $answers[] = $a;
            $used[] = $a;
            if (!empty($rq['masterClue'])) {
                $clues[] = 'R' . ($i + 1) . ': ' . $a . (!empty($rq['clueNote']) ? ' (' . $rq['clueNote'] . ')' : '');
            }
        }
        $rounds[] = ($i + 1) . '. „' . $r['title'] . '“' . ((isset($r['kind']) && $r['kind'] === 'HANDOUT') ? ' (Bilderrunde als Handout)' : '')
            . ($answers ? ' – bisher: ' . implode(', ', $answers) : ' – noch leer');
    }
    $master = null;
    $mid = isset($session['finale']['masterId']) ? $session['finale']['masterId'] : null;
    if ($mid && isset($qById[$mid])) {
        $master = $qById[$mid];
        $used[] = $master['answer'];
    }
    return ['rounds' => $rounds, 'used' => $used, 'clues' => $clues, 'master' => $master];
}

/** Returns [user prompt, meta, error]. */
function ai_build_round($in, $session, $qById)
{
    $topic = q_str(isset($in['topic']) ? $in['topic'] : '', 200);
    if (mb_strlen($topic) < 2) {
        return [null, null, 'Bitte ein Thema angeben.'];
    }
    $count = max(3, min(12, (int)(isset($in['count']) ? $in['count'] : 8)));
    $difficulty = max(1, min(3, (int)(isset($in['difficulty']) ? $in['difficulty'] : 2)));
    $types = array_values(array_intersect(ai_types(), isset($in['types']) ? (array)$in['types'] : []));
    if (!$types) {
        $types = ['MULTIPLE_CHOICE', 'OPEN_TEXT', 'ESTIMATE', 'MAP', 'AUDIO'];
    }
    $notes = q_str(isset($in['notes']) ? $in['notes'] : '', 500);

    $ri = isset($in['roundIndex']) && $in['roundIndex'] !== null && $in['roundIndex'] !== '' ? (int)$in['roundIndex'] : null;
    $round = ($ri !== null && isset($session['rounds'][$ri])) ? $session['rounds'][$ri] : null;
    if ($round === null) {
        $ri = null;
    }
    $handout = $round && isset($round['kind']) && $round['kind'] === 'HANDOUT';
    if ($handout) {
        $types = ['IMAGE'];
    }

    $ctx = ai_evening_context($session, $qById);
    $withClue = !empty($in['masterClue']) && $ctx['master'] !== null;

    $lines = [];
    $lines[] = 'Erstelle eine Runde für den Abend.';
    $lines[] = 'Thema: ' . $topic;
    if ($round) {
        $lines[] = 'Sie kommt als Runde ' . ($ri + 1) . ' („' . $round['title'] . '“) in den Abend.';
    }
    $lines[] = $handout
        ? 'Rundenart: Bilderrunde als Handout – nur IMAGE-Fragen.'
        : 'Erlaubte Fragetypen: ' . implode(', ', $types) . '.';
    $lines[] = 'Anzahl Fragen: ' . $count . '. Ziel-Schwierigkeit: ' . $difficulty . ' von 3.';
    if ($notes !== '') {
        $lines[] = 'Wünsche des Quiz-Teams: ' . $notes;
    }
    $lines[] = 'Die Runden des Abends:';
    foreach ($ctx['rounds'] as $l) {
        $lines[] = '  ' . $l;
    }
    if ($ctx['used']) {
        $lines[] = 'Diese Antworten gibt es im Abend schon – nicht wiederholen: ' . implode('; ', $ctx['used']) . '.';
    }
    if ($withClue) {
        $m = $ctx['master'];
        $hints = isset($m['payload']['hints']) ? $m['payload']['hints'] : [];
        $lines[] = 'Die Masterfrage im Finale lautet „' . $m['prompt'] . '“, Lösung: „' . $m['answer'] . '“.'
            . ($hints ? ' Ihre Countdown-Hinweise: ' . implode(' | ', $hints) . '.' : '');
        $lines[] = $ctx['clues'] ? 'Bisherige Masterhinweise: ' . implode('; ', $ctx['clues']) . '.' : 'Es gibt noch keine Masterhinweise.';
        $lines[] = 'Baue genau eine Frage ein, deren Antwort ein weiterer Masterhinweis ist (masterClue true, clueNote erklärt, was er beiträgt). '
            . 'Er muss sich natürlich ins Rundenthema fügen und darf die Lösung nicht verraten. Alle anderen Fragen: masterClue false, clueNote null.';
    } else {
        $lines[] = 'Alle Fragen: masterClue false, clueNote null.';
    }
    $lines[] = 'roundTitle: ein knackiger Rundentitel. roundIntro: ein, zwei Sätze, mit denen der Quizmaster die Runde ankündigt.';

    return [implode("\n", $lines), ['roundIndex' => $ri, 'handout' => $handout], null];
}

/** Returns [user prompt, meta, error]. */
function ai_build_master($in, $session, $qById)
{
    $rounds = isset($session['rounds']) ? $session['rounds'] : [];
    if (!$rounds) {
        return [null, null, 'Der Abend hat noch keine Runden – leg sie zuerst unter „Abend“ an.'];
    }
    $idea = q_str(isset($in['idea']) ? $in['idea'] : '', 300);
    $difficulty = max(1, min(3, (int)(isset($in['difficulty']) ? $in['difficulty'] : 2)));
    $ctx = ai_evening_context($session, $qById);

    $lines = [];
    $lines[] = 'Erfinde die Masterfrage für das Finale und den roten Faden durch den Abend.';
    $lines[] = $idea !== '' ? 'Idee oder Richtung des Quiz-Teams: ' . $idea : 'Die Richtung ist frei – überrasch uns.';
    $lines[] = 'Ziel-Schwierigkeit der Hinweisfragen: ' . $difficulty . ' von 3.';
    $lines[] = 'Die Runden des Abends (roundIndex beginnt bei 0):';
    foreach ($ctx['rounds'] as $i => $l) {
        $lines[] = '  roundIndex ' . $i . ' = ' . $l;
    }
    if ($ctx['used']) {
        $lines[] = 'Diese Antworten gibt es im Abend schon – nicht wiederholen: ' . implode('; ', $ctx['used']) . '.';
    }
    $lines[] = 'clueQuestions: für jede Runde genau eine Frage, deren Antwort ein Masterhinweis ist (masterClue true, clueNote erklärt intern, was er beiträgt). '
        . 'Sie muss zum Rundentitel passen; in Handout-Runden ist sie eine IMAGE-Frage.';
    $lines[] = 'master.prompt: die Frage, wie sie im Finale am Beamer steht. master.hints: 5 Countdown-Hinweise vom schwersten zum leichtesten – '
        . 'der erste nur für Tische, die den roten Faden erkannt haben, der letzte fast geschenkt. '
        . 'master.clueExplanation: die Auflösung, die der Quizmaster vorliest, inklusive wie die Rundenhinweise zusammenhängen. master.background: die Geschichte dahinter.';
    $lines[] = 'Die Lösung soll allgemein bekannt genug sein, dass sie am Ende jeder Tisch kennen kann: eine Person, ein Ort, ein Ding oder ein Ereignis.';

    return [implode("\n", $lines), ['rounds' => count($rounds)], null];
}

// ---- The API call (streamed) ------------------------------------------------------
function ai_fail($msg)
{
    return ['ok' => false, 'error' => $msg];
}

function ai_sse_event(&$st, $ev)
{
    $type = isset($ev['type']) ? $ev['type'] : '';
    if ($type === 'message_start') {
        $st['model'] = isset($ev['message']['model']) ? $ev['message']['model'] : '';
        $st['in'] = isset($ev['message']['usage']['input_tokens']) ? (int)$ev['message']['usage']['input_tokens'] : 0;
    } elseif ($type === 'content_block_start') {
        $t = isset($ev['content_block']['type']) ? $ev['content_block']['type'] : '';
        if ($t === 'fallback') {
            // A fallback model takes over and starts its answer from scratch.
            $st['text'] = '';
            $st['fellBack'] = true;
        } elseif ($t === 'text') {
            $st['phase'] = 'writing';
        } elseif ($t === 'thinking') {
            $st['phase'] = 'thinking';
        }
    } elseif ($type === 'content_block_delta') {
        if (isset($ev['delta']['type']) && $ev['delta']['type'] === 'text_delta') {
            $st['text'] .= $ev['delta']['text'];
        }
    } elseif ($type === 'message_delta') {
        if (isset($ev['delta']['stop_reason'])) {
            $st['stop'] = $ev['delta']['stop_reason'];
        }
        if (isset($ev['usage']['output_tokens'])) {
            $st['out'] = (int)$ev['usage']['output_tokens'];
        }
    } elseif ($type === 'error') {
        $st['error'] = isset($ev['error']['message']) ? $ev['error']['message'] : 'unbekannter Fehler';
    }
}

function ai_sse_feed(&$st, $chunk)
{
    if (strlen($st['raw']) < 4000) {
        $st['raw'] .= $chunk;
    }
    $st['buf'] .= str_replace("\r\n", "\n", $chunk);
    while (($p = strpos($st['buf'], "\n\n")) !== false) {
        $block = substr($st['buf'], 0, $p);
        $st['buf'] = substr($st['buf'], $p + 2);
        $data = '';
        foreach (explode("\n", $block) as $line) {
            if (strpos($line, 'data:') === 0) {
                $data .= ltrim(substr($line, 5));
            }
        }
        if ($data !== '') {
            $ev = json_decode($data, true);
            if (is_array($ev)) {
                ai_sse_event($st, $ev);
            }
        }
    }
}

/**
 * One streamed Messages API call. $onBeat($phase, $chars) is called about
 * every 3 seconds so the caller can keep its own connection to the browser
 * alive while Claude thinks.
 * Returns ['ok'=>true, 'data'=>array, 'usage'=>[in,out], 'model'=>…] or ['ok'=>false, 'error'=>…].
 */
function ai_call($system, $user, $schema, callable $onBeat)
{
    $cfg = wq_config();
    $key = isset($cfg['anthropic_api_key']) ? trim((string)$cfg['anthropic_api_key']) : '';
    if ($key === '') {
        return ai_fail('Am Server ist kein Anthropic-API-Key hinterlegt (data/config.json → anthropic_api_key).');
    }
    if (!function_exists('curl_init')) {
        return ai_fail('PHP-cURL fehlt am Server.');
    }

    $body = json_encode([
        'model'         => WQ_AI_MODEL,
        'max_tokens'    => 32000,
        'stream'        => true,
        'thinking'      => ['type' => 'adaptive'],
        'output_config' => ['effort' => 'high', 'format' => ['type' => 'json_schema', 'schema' => $schema]],
        'fallbacks'     => 'default',
        'system'        => $system,
        'messages'      => [['role' => 'user', 'content' => $user]],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    $st = ['buf' => '', 'raw' => '', 'text' => '', 'stop' => null, 'error' => null, 'in' => 0, 'out' => 0,
           'model' => '', 'phase' => 'thinking', 'beat' => 0.0, 'fellBack' => false];

    $ch = curl_init('https://api.anthropic.com/v1/messages');
    curl_setopt_array($ch, [
        CURLOPT_POST             => true,
        CURLOPT_POSTFIELDS       => $body,
        CURLOPT_HTTPHEADER       => [
            'content-type: application/json',
            'accept: text/event-stream',
            'x-api-key: ' . $key,
            'anthropic-version: 2023-06-01',
            'anthropic-beta: server-side-fallback-2026-07-01',
        ],
        CURLOPT_CONNECTTIMEOUT   => 15,
        CURLOPT_TIMEOUT          => 300,
        CURLOPT_WRITEFUNCTION    => function ($c, $chunk) use (&$st) {
            ai_sse_feed($st, $chunk);
            return strlen($chunk);
        },
        CURLOPT_NOPROGRESS       => false,
        CURLOPT_PROGRESSFUNCTION => function () use (&$st, $onBeat) {
            $now = microtime(true);
            if ($now - $st['beat'] >= 3) {
                $st['beat'] = $now;
                $onBeat($st['phase'], strlen($st['text']));
            }
            return 0;
        },
    ]);
    $ok = curl_exec($ch);
    $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $cerr = curl_error($ch);
    curl_close($ch);

    if ($ok === false) {
        return ai_fail('Keine Verbindung zur KI: ' . $cerr);
    }
    if ($http !== 200) {
        $j = json_decode($st['raw'], true);
        $msg = isset($j['error']['message']) ? $j['error']['message'] : ('HTTP ' . $http);
        if ($http === 429 || $http === 529) {
            $msg = 'Die KI ist gerade überlastet – in einer Minute nochmal probieren. (' . $msg . ')';
        }
        return ai_fail('Die KI-Schnittstelle meldet: ' . $msg);
    }
    if ($st['error'] !== null) {
        return ai_fail('Die KI hat mittendrin abgebrochen: ' . $st['error']);
    }
    if ($st['stop'] === 'refusal') {
        return ai_fail('Die KI hat die Anfrage abgelehnt. Formulier das Thema etwas anders.');
    }
    if ($st['stop'] === 'max_tokens') {
        return ai_fail('Die Antwort wurde zu lang und abgeschnitten – weniger Fragen auf einmal versuchen.');
    }
    $data = json_decode($st['text'], true);
    if (!is_array($data)) {
        return ai_fail('Die KI-Antwort war kein gültiges JSON. Bitte nochmal erzeugen.');
    }
    return ['ok' => true, 'data' => $data, 'usage' => ['in' => $st['in'], 'out' => $st['out']], 'model' => $st['model'],
            'fellBack' => $st['fellBack']];
}

function ai_usage($usage)
{
    $usd = $usage['in'] / 1e6 * WQ_AI_PRICE_IN + $usage['out'] / 1e6 * WQ_AI_PRICE_OUT;
    return ['inputTokens' => $usage['in'], 'outputTokens' => $usage['out'], 'usd' => round($usd, 3)];
}

// ---- Mapping AI output -> our question format ----------------------------------------
function ai_get($a, $k, $d = null)
{
    return is_array($a) && array_key_exists($k, $a) && $a[$k] !== null ? $a[$k] : $d;
}

function ai_to_input($q, $tag = '')
{
    $type = in_array(ai_get($q, 'type'), ai_types(), true) ? $q['type'] : 'OPEN_TEXT';
    $p = [];
    if ($type === 'MULTIPLE_CHOICE') {
        $p = ['options' => ai_get($q, 'options', []), 'correctIndex' => ai_get($q, 'correctIndex', 0)];
    } elseif ($type === 'OPEN_TEXT') {
        $p = ['acceptedAnswers' => ai_get($q, 'acceptedAnswers', [])];
    } elseif ($type === 'ESTIMATE') {
        $p = ['value' => ai_get($q, 'estimateValue'), 'unit' => ai_get($q, 'estimateUnit', ''), 'scoring' => 'CLOSEST'];
    } elseif ($type === 'MAP') {
        $p = ['lat' => ai_get($q, 'lat'), 'lng' => ai_get($q, 'lng'), 'radiusKm' => ai_get($q, 'radiusKm', 50), 'scoring' => 'RADIUS'];
    } elseif ($type === 'AUDIO') {
        $p = ['mediaUrl' => '', 'youtubeUrl' => '', 'youtubeSearch' => ai_get($q, 'mediaSearch', ''), 'startSeconds' => ai_get($q, 'startSeconds')];
    } elseif ($type === 'IMAGE') {
        $p = ['imageUrl' => '', 'imageSearch' => ai_get($q, 'mediaSearch', '')];
    }
    $types = question_types();
    return [
        'type'       => $type,
        'prompt'     => (string)ai_get($q, 'prompt', ''),
        'answer'     => (string)ai_get($q, 'answer', ''),
        'background' => (string)ai_get($q, 'background', ''),
        'factCheck'  => (string)ai_get($q, 'factCheck', ''),
        'category'   => (string)ai_get($q, 'category', ''),
        'difficulty' => max(1, min(3, (int)ai_get($q, 'difficulty', 2))),
        'points'     => $types[$type],
        'tags'       => $tag !== '' ? ['ki', $tag] : ['ki'],
        'status'     => 'DRAFT',
        'payload'    => $p,
    ];
}

/** One previewable item: our question format, validated, plus its clue marking. */
function ai_item($q, $roundIndex, $tag = '')
{
    $input = ai_to_input($q, $tag);
    list($clean, $err) = validate_question($input);
    $clue = !empty($q['masterClue']);
    return [
        'input'      => $clean !== null ? $clean : $input,
        'valid'      => $err === null,
        'error'      => $err,
        'masterClue' => $clue,
        'clueNote'   => $clue ? q_str((string)ai_get($q, 'clueNote', ''), 200) : '',
        'roundIndex' => $roundIndex,
    ];
}

function ai_master_item($m)
{
    $input = [
        'type'       => 'MASTER',
        'prompt'     => (string)ai_get($m, 'prompt', ''),
        'answer'     => (string)ai_get($m, 'answer', ''),
        'background' => (string)ai_get($m, 'background', ''),
        'factCheck'  => '',
        'category'   => 'Masterfrage',
        'difficulty' => 3,
        'points'     => 50,
        'tags'       => ['ki'],
        'status'     => 'DRAFT',
        'payload'    => [
            'clueMode'        => 'COUNTDOWN',
            'hints'           => ai_get($m, 'hints', []),
            'clueExplanation' => (string)ai_get($m, 'clueExplanation', ''),
        ],
    ];
    list($clean, $err) = validate_question($input);
    return ['input' => $clean !== null ? $clean : $input, 'valid' => $err === null, 'error' => $err];
}
