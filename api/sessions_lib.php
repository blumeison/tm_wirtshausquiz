<?php
/**
 * Wirtshausquiz — quiz nights (P5).
 *
 * One quiz night = one JSON document in data/sessions/<id>.json. Rounds and
 * the finale are embedded — they never exist outside a night (SPEC §4).
 * The id is the same as the registration session id (config session_id), so
 * a night and its signups share one key.
 *
 * Saves carry `rev`; a save against an outdated rev is refused, so two
 * editors can't silently overwrite each other.
 */

require_once __DIR__ . '/questions_lib.php';

function round_kinds()
{
    // HANDOUT: questions are on a printed sheet that lies on the tables from
    // doors open and is collected at the break; the beamer only shows answers.
    return ['NORMAL', 'HANDOUT'];
}

function sessions_dir()
{
    $d = WQ_DATA_DIR . '/sessions';
    wq_ensure_dir($d);
    return $d;
}

function session_file($id)
{
    if (!valid_session_id($id)) {
        fail(400, 'ungültige Session');
    }
    return sessions_dir() . '/' . $id . '.json';
}

/** A fresh night laid out like the plan agreed for the premiere. */
function default_session($id)
{
    $cfg = wq_config();
    $now = now_iso();
    $round = function ($title, $kind) {
        return ['id' => gen_id('r_'), 'title' => $title, 'kind' => $kind, 'multiplier' => 1, 'questions' => []];
    };
    return [
        'id'           => $id,
        'title'        => isset($cfg['event_title']) ? $cfg['event_title'] : 'Wirtshausquiz',
        'notes'        => '',
        'jokerEnabled' => true,
        'rounds'       => [
            $round('Aufwärmen', 'NORMAL'),
            $round('Bilderrunde', 'HANDOUT'),
            $round('Musik', 'NORMAL'),
            $round('Runde 4', 'NORMAL'),
        ],
        'finale'       => ['masterId' => null, 'tiebreakId' => null],
        'rev'          => 0,
        'createdAt'    => $now,
        'updatedAt'    => $now,
    ];
}

function read_session($id)
{
    $f = session_file($id);
    if (is_file($f)) {
        $doc = json_decode((string)file_get_contents($f), true);
        if (is_array($doc)) {
            return $doc;
        }
    }
    return default_session($id);
}

/** Read-modify-write of one night under an exclusive lock. */
function with_session_doc($id, callable $fn, &$result = null)
{
    $fh = fopen(session_file($id), 'c+');
    if ($fh === false) {
        fail(500, 'Speicher nicht verfügbar');
    }
    if (!flock($fh, LOCK_EX)) {
        fclose($fh);
        fail(500, 'Speicher belegt, bitte nochmal versuchen');
    }
    $doc = json_decode((string)stream_get_contents($fh), true);
    if (!is_array($doc)) {
        $doc = default_session($id);
    }
    $out = $fn($doc, $result);
    if (is_array($out)) {
        ftruncate($fh, 0);
        rewind($fh);
        fwrite($fh, json_encode($out, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        fflush($fh);
    }
    flock($fh, LOCK_UN);
    fclose($fh);
    return $result;
}

/**
 * Validate a night coming from the builder. $qById is the question pool.
 * References to questions that no longer exist are dropped quietly.
 * Returns [clean fields, null] or [null, error message].
 */
function validate_session_input($in, $qById)
{
    $title = q_str(isset($in['title']) ? $in['title'] : '', 120);
    if ($title === '') {
        return [null, 'Der Abend braucht einen Titel.'];
    }
    $rin = isset($in['rounds']) && is_array($in['rounds']) ? array_values($in['rounds']) : [];
    if (count($rin) > 8) {
        return [null, 'Höchstens 8 Runden.'];
    }

    $used = [];
    $rounds = [];
    foreach ($rin as $n => $r) {
        if (!is_array($r)) {
            continue;
        }
        $rid = isset($r['id']) && is_string($r['id']) && preg_match('/^r_[A-Za-z0-9]{1,20}$/', $r['id']) ? $r['id'] : gen_id('r_');
        $rt = q_str(isset($r['title']) ? $r['title'] : '', 80);
        if ($rt === '') {
            $rt = 'Runde ' . ($n + 1);
        }
        $kind = isset($r['kind']) && in_array($r['kind'], round_kinds(), true) ? $r['kind'] : 'NORMAL';
        $mult = q_num(isset($r['multiplier']) ? $r['multiplier'] : 1);
        if ($mult === null || $mult < 0.5 || $mult > 5) {
            $mult = 1;
        }

        $qs = [];
        foreach ((isset($r['questions']) && is_array($r['questions']) ? $r['questions'] : []) as $rq) {
            $qid = is_array($rq) && isset($rq['questionId']) && is_string($rq['questionId']) ? $rq['questionId'] : '';
            if ($qid === '' || !isset($qById[$qid])) {
                continue;
            }
            $q = $qById[$qid];
            if (isset($used[$qid])) {
                return [null, 'Die Frage „' . clean_str($q['prompt'], 40) . '…“ steckt zweimal im Abend.'];
            }
            if ($q['type'] === 'MASTER') {
                return [null, 'Masterfragen gehören ins Finale, nicht in eine Runde.'];
            }
            $used[$qid] = true;
            $po = isset($rq['pointsOverride']) ? q_num($rq['pointsOverride']) : null;
            if ($po !== null && ($po < 1 || $po > 100)) {
                $po = null;
            }
            $qs[] = ['questionId' => $qid, 'pointsOverride' => $po === null ? null : (int)round($po)];
        }
        if (count($qs) > 20) {
            return [null, 'Höchstens 20 Fragen pro Runde.'];
        }
        $rounds[] = ['id' => $rid, 'title' => $rt, 'kind' => $kind, 'multiplier' => $mult, 'questions' => $qs];
    }

    $fin = isset($in['finale']) && is_array($in['finale']) ? $in['finale'] : [];
    $mid = isset($fin['masterId']) && is_string($fin['masterId']) && $fin['masterId'] !== '' ? $fin['masterId'] : null;
    if ($mid !== null && (!isset($qById[$mid]) || $qById[$mid]['type'] !== 'MASTER')) {
        return [null, 'Ins Finale gehört eine Frage vom Typ Masterfrage.'];
    }
    $tid = isset($fin['tiebreakId']) && is_string($fin['tiebreakId']) && $fin['tiebreakId'] !== '' ? $fin['tiebreakId'] : null;
    if ($tid !== null) {
        if (!isset($qById[$tid]) || $qById[$tid]['type'] !== 'ESTIMATE') {
            return [null, 'Die Stechfrage muss eine Schätzfrage sein.'];
        }
        if (isset($used[$tid])) {
            return [null, 'Die Stechfrage steckt schon in einer Runde — nimm eine andere Schätzfrage.'];
        }
    }

    return [[
        'title'        => $title,
        'notes'        => q_str(isset($in['notes']) ? $in['notes'] : '', 4000),
        'jokerEnabled' => !empty($in['jokerEnabled']),
        'rounds'       => $rounds,
        'finale'       => ['masterId' => $mid, 'tiebreakId' => $tid],
    ], null];
}

/** questionId => ['R2', 'Finale', …] across all nights. */
function question_usage()
{
    $out = [];
    $files = glob(sessions_dir() . '/*.json');
    foreach ($files ? $files : [] as $f) {
        $s = json_decode((string)file_get_contents($f), true);
        if (!is_array($s)) {
            continue;
        }
        foreach ((isset($s['rounds']) && is_array($s['rounds']) ? $s['rounds'] : []) as $i => $r) {
            foreach ((isset($r['questions']) && is_array($r['questions']) ? $r['questions'] : []) as $rq) {
                if (!empty($rq['questionId'])) {
                    $out[$rq['questionId']][] = 'R' . ($i + 1);
                }
            }
        }
        $fin = isset($s['finale']) && is_array($s['finale']) ? $s['finale'] : [];
        if (!empty($fin['masterId'])) {
            $out[$fin['masterId']][] = 'Finale';
        }
        if (!empty($fin['tiebreakId'])) {
            $out[$fin['tiebreakId']][] = 'Stechfrage';
        }
    }
    return $out;
}
