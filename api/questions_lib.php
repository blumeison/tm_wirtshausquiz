<?php
/**
 * Wirtshausquiz — question pool (P4): storage, validation, uploads helpers.
 *
 * The whole pool lives in one file (SPEC §3): it is almost always read as a
 * whole and written by one or two editors at a time.
 *
 * Payload rules are a 1:1 port of the zod schemas in
 * tm_wirtshausquiz_backoffice/src/lib/quiz.ts, with two deliberate tightenings:
 * empty multiple-choice options are rejected (the old editor stored them), and
 * a map question needs a real target (the old default 0/0 is in the Atlantic).
 */

require_once __DIR__ . '/auth.php';

/** type => default points */
function question_types()
{
    return [
        'MULTIPLE_CHOICE' => 10,
        'OPEN_TEXT'       => 10,
        'ESTIMATE'        => 10,
        'IMAGE'           => 10,
        'AUDIO'           => 10,
        'VIDEO'           => 10,
        'MAP'             => 15,
        'MASTER'          => 25,
    ];
}

/**
 * Points per clue in the countdown finale: the first (hardest) clue is worth
 * the most, every further clue 10 less — 5 clues = 50 · 40 · 30 · 20 · 10.
 */
function master_ladder($hintCount)
{
    $out = [];
    for ($i = $hintCount; $i >= 1; $i--) {
        $out[] = $i * 10;
    }
    return $out;
}

// ---- Generic JSON list file (flock) ----------------------------------------
function read_json_list_file($file)
{
    if (!is_file($file)) {
        return [];
    }
    $list = json_decode((string)file_get_contents($file), true);
    return is_array($list) ? $list : [];
}

function with_json_list_file($file, callable $fn, &$result = null)
{
    $fh = fopen($file, 'c+');
    if ($fh === false) {
        fail(500, 'Speicher nicht verfügbar');
    }
    if (!flock($fh, LOCK_EX)) {
        fclose($fh);
        fail(500, 'Speicher belegt, bitte nochmal versuchen');
    }
    $list = json_decode((string)stream_get_contents($fh), true);
    if (!is_array($list)) {
        $list = [];
    }
    $out = $fn($list, $result);
    if (is_array($out)) {
        ftruncate($fh, 0);
        rewind($fh);
        fwrite($fh, json_encode(array_values($out), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        fflush($fh);
    }
    flock($fh, LOCK_UN);
    fclose($fh);
    return $result;
}

// ---- Question store ----------------------------------------------------------
function questions_file()
{
    wq_ensure_dir(WQ_DATA_DIR);
    return WQ_DATA_DIR . '/questions.json';
}

function read_questions()
{
    return read_json_list_file(questions_file());
}

function with_questions(callable $fn, &$result = null)
{
    return with_json_list_file(questions_file(), $fn, $result);
}

function find_question_index($list, $id)
{
    foreach ($list as $i => $q) {
        if ((isset($q['id']) ? $q['id'] : '') === $id) {
            return $i;
        }
    }
    return -1;
}

// ---- Validation --------------------------------------------------------------
function q_str($v, $max)
{
    return is_scalar($v) ? clean_str((string)$v, $max) : '';
}

/** Number from int/float or a numeric string (German comma allowed), else null. */
function q_num($v)
{
    if (is_int($v) || is_float($v)) {
        return (float)$v;
    }
    if (is_string($v)) {
        $v = str_replace(',', '.', trim($v));
        if ($v !== '' && is_numeric($v)) {
            return (float)$v;
        }
    }
    return null;
}

/** '' | http(s) URL | /uploads/<file>. Anything else (javascript:, data:) -> null. */
function clean_media_url($v)
{
    $v = q_str($v, 500);
    if ($v === '') {
        return '';
    }
    if (preg_match('#^/uploads/[A-Za-z0-9._-]+$#', $v)) {
        return $v;
    }
    if (preg_match('#^https?://#i', $v) && filter_var($v, FILTER_VALIDATE_URL)) {
        return $v;
    }
    return null;
}

/** Returns [payload, null] or [null, error message]. */
function validate_payload($type, $p)
{
    if (!is_array($p)) {
        $p = [];
    }
    $g = function ($k, $d = null) use ($p) {
        return array_key_exists($k, $p) ? $p[$k] : $d;
    };

    switch ($type) {
        case 'MULTIPLE_CHOICE':
            $opts = [];
            foreach ((array)$g('options', []) as $o) {
                $opts[] = q_str($o, 300);
            }
            if (count($opts) < 2 || count($opts) > 8) {
                return [null, 'Multiple Choice braucht 2 bis 8 Antwortmöglichkeiten.'];
            }
            foreach ($opts as $o) {
                if ($o === '') {
                    return [null, 'Bitte alle Antwortmöglichkeiten ausfüllen oder leere entfernen.'];
                }
            }
            $ci = q_num($g('correctIndex', 0));
            if ($ci === null || $ci < 0 || $ci >= count($opts) || floor($ci) != $ci) {
                return [null, 'Bitte die richtige Antwort markieren.'];
            }
            return [['options' => $opts, 'correctIndex' => (int)$ci], null];

        case 'OPEN_TEXT':
            $acc = [];
            foreach ((array)$g('acceptedAnswers', []) as $a) {
                $a = q_str($a, 200);
                if ($a !== '') {
                    $acc[] = $a;
                }
            }
            return [['acceptedAnswers' => array_slice($acc, 0, 30)], null];

        case 'ESTIMATE':
            $v = q_num($g('value'));
            if ($v === null) {
                return [null, 'Schätzfrage: Bitte den richtigen Wert als Zahl angeben.'];
            }
            $scoring = $g('scoring') === 'TOLERANCE' ? 'TOLERANCE' : 'CLOSEST';
            $out = ['value' => $v, 'unit' => q_str($g('unit', ''), 40), 'scoring' => $scoring];
            if ($scoring === 'TOLERANCE') {
                $t = q_num($g('tolerancePercent', 10));
                if ($t === null || $t <= 0 || $t > 100) {
                    return [null, 'Die Toleranz muss zwischen 1 und 100 % liegen.'];
                }
                $out['tolerancePercent'] = $t;
            }
            return [$out, null];

        case 'IMAGE':
            $u = clean_media_url($g('imageUrl', ''));
            if ($u === null) {
                return [null, 'Bild: nur http(s)-Adressen oder hochgeladene Dateien.'];
            }
            return [['imageUrl' => $u], null];

        case 'AUDIO':
        case 'VIDEO':
            $m = clean_media_url($g('mediaUrl', ''));
            $y = clean_media_url($g('youtubeUrl', ''));
            if ($m === null || $y === null) {
                return [null, 'Nur http(s)-Adressen oder hochgeladene Dateien.'];
            }
            $out = ['mediaUrl' => $m, 'youtubeUrl' => $y];
            foreach (['startSeconds', 'clipSeconds'] as $k) {
                $n = q_num($g($k));
                if ($n !== null && $n > 0) {
                    $out[$k] = $n;
                }
            }
            return [$out, null];

        case 'MAP':
            $lat = q_num($g('lat'));
            $lng = q_num($g('lng'));
            if ($lat === null || $lng === null || $lat < -90 || $lat > 90 || $lng < -180 || $lng > 180
                || ($lat == 0 && $lng == 0)) {
                return [null, 'Kartenfrage: Bitte den Zielort auf der Karte setzen.'];
            }
            $r = q_num($g('radiusKm', 50));
            if ($r === null || $r <= 0) {
                return [null, 'Der Radius muss größer als 0 km sein.'];
            }
            return [[
                'lat'      => $lat,
                'lng'      => $lng,
                'radiusKm' => $r,
                'scoring'  => $g('scoring') === 'CLOSEST' ? 'CLOSEST' : 'RADIUS',
            ], null];

        case 'MASTER':
            // COUNTDOWN is the finale format: clues appear one by one, each
            // team gets one guess, an early right guess scores the most.
            $mode = $g('clueMode', 'COUNTDOWN');
            if (!in_array($mode, ['COUNTDOWN', 'FIRST_LETTERS', 'ANSWERS_ARE_CLUES', 'CUSTOM'], true)) {
                $mode = 'COUNTDOWN';
            }
            $out = ['clueMode' => $mode, 'clueExplanation' => q_str($g('clueExplanation', ''), 2000)];
            if ($mode === 'COUNTDOWN') {
                $hints = [];
                foreach ((array)$g('hints', []) as $h) {
                    $h = q_str($h, 300);
                    if ($h !== '') {
                        $hints[] = $h;
                    }
                }
                if (count($hints) < 2) {
                    return [null, 'Countdown: mindestens 2 Hinweise, vom schwersten zum leichtesten.'];
                }
                if (count($hints) > 8) {
                    return [null, 'Höchstens 8 Hinweise.'];
                }
                $out['hints'] = $hints;
                $out['ladder'] = master_ladder(count($hints));
            }
            return [$out, null];
    }
    return [null, 'Unbekannter Fragetyp'];
}

/** Base fields + payload. Returns [fields, null] or [null, error message]. */
function validate_question($in)
{
    $types = question_types();
    $type = isset($in['type']) && is_string($in['type']) ? $in['type'] : '';
    if (!isset($types[$type])) {
        return [null, 'Bitte einen Fragetyp wählen.'];
    }
    $prompt = q_str(isset($in['prompt']) ? $in['prompt'] : '', 2000);
    if ($prompt === '') {
        return [null, 'Die Frage fehlt.'];
    }
    $answer = q_str(isset($in['answer']) ? $in['answer'] : '', 2000);
    if ($answer === '') {
        return [null, 'Die Antwort fehlt.'];
    }
    $points = q_num(isset($in['points']) ? $in['points'] : $types[$type]);
    if ($points === null || $points < 1 || $points > 100 || floor($points) != $points) {
        return [null, 'Punkte: eine ganze Zahl von 1 bis 100.'];
    }
    $difficulty = (int)(isset($in['difficulty']) ? $in['difficulty'] : 2);
    if ($difficulty < 1 || $difficulty > 3) {
        return [null, 'Schwierigkeit: leicht, mittel oder schwer.'];
    }

    $rawTags = isset($in['tags']) ? $in['tags'] : [];
    if (is_string($rawTags)) {
        $rawTags = explode(',', $rawTags);
    }
    $tags = [];
    foreach ((array)$rawTags as $t) {
        $t = q_str($t, 40);
        if ($t !== '' && !in_array($t, $tags, true)) {
            $tags[] = $t;
        }
    }

    list($payload, $err) = validate_payload($type, isset($in['payload']) ? $in['payload'] : []);
    if ($err !== null) {
        return [null, $err];
    }
    if ($type === 'MASTER' && isset($payload['ladder'][0])) {
        $points = (float)$payload['ladder'][0]; // the countdown starts at the top of the ladder
    }

    return [[
        'type'       => $type,
        'prompt'     => $prompt,
        'answer'     => $answer,
        'payload'    => $payload,
        'points'     => (int)$points,
        'difficulty' => $difficulty,
        'category'   => q_str(isset($in['category']) ? $in['category'] : '', 80),
        'tags'       => array_slice($tags, 0, 15),
        'status'     => (isset($in['status']) && $in['status'] === 'DRAFT') ? 'DRAFT' : 'READY',
    ], null];
}

// ---- Uploads -----------------------------------------------------------------
function ini_bytes($v)
{
    $v = trim((string)$v);
    if ($v === '') {
        return 0;
    }
    $n = (float)$v;
    $u = strtolower(substr($v, -1));
    if ($u === 'g') {
        $n *= 1073741824;
    } elseif ($u === 'm') {
        $n *= 1048576;
    } elseif ($u === 'k') {
        $n *= 1024;
    }
    return (int)$n;
}

/** What PHP will actually accept, capped at 50 MB like the old backoffice. */
function upload_max_bytes()
{
    $max = 50 * 1048576;
    foreach ([ini_bytes(ini_get('upload_max_filesize')), ini_bytes(ini_get('post_max_size'))] as $x) {
        if ($x > 0) {
            $max = min($max, $x);
        }
    }
    return $max;
}

function fmt_mb($bytes)
{
    return str_replace('.', ',', (string)round($bytes / 1048576, 1)) . ' MB';
}

/**
 * Scale an image down to $maxSide on its longest edge (EXIF rotation applied
 * first — phone photos are stored sideways) and write it to $dest.
 * Returns false when nothing needed doing or GD can't handle it; the caller
 * then keeps the original file.
 */
function shrink_image($src, $dest, $ext, $maxSide)
{
    if (!function_exists('imagecreatetruecolor')) {
        return false;
    }
    $info = @getimagesize($src);
    if (!$info) {
        return false;
    }
    $w = (int)$info[0];
    $h = (int)$info[1];
    $orient = 1;
    if ($ext === 'jpg' && function_exists('exif_read_data')) {
        $exif = @exif_read_data($src);
        if (!empty($exif['Orientation'])) {
            $orient = (int)$exif['Orientation'];
        }
    }
    if (max($w, $h) <= $maxSide && !in_array($orient, [3, 6, 8], true)) {
        return false;
    }
    if ($w * $h > 40000000) { // ~40 MP would blow the memory limit
        return false;
    }

    $im = false;
    if ($ext === 'jpg') {
        $im = @imagecreatefromjpeg($src);
    } elseif ($ext === 'png') {
        $im = @imagecreatefrompng($src);
    } elseif ($ext === 'webp' && function_exists('imagecreatefromwebp')) {
        $im = @imagecreatefromwebp($src);
    }
    if (!$im) {
        return false;
    }
    if ($orient === 3) {
        $im = imagerotate($im, 180, 0);
    } elseif ($orient === 6) {
        $im = imagerotate($im, -90, 0);
    } elseif ($orient === 8) {
        $im = imagerotate($im, 90, 0);
    }

    $w = imagesx($im);
    $h = imagesy($im);
    $scale = min(1, $maxSide / max($w, $h));
    $nw = max(1, (int)round($w * $scale));
    $nh = max(1, (int)round($h * $scale));
    $out = imagecreatetruecolor($nw, $nh);
    if ($ext !== 'jpg') {
        imagealphablending($out, false);
        imagesavealpha($out, true);
    }
    imagecopyresampled($out, $im, 0, 0, 0, 0, $nw, $nh, $w, $h);

    $ok = false;
    if ($ext === 'jpg') {
        $ok = imagejpeg($out, $dest, 85);
    } elseif ($ext === 'png') {
        $ok = imagepng($out, $dest, 6);
    } elseif ($ext === 'webp' && function_exists('imagewebp')) {
        $ok = imagewebp($out, $dest, 85);
    }
    imagedestroy($im);
    imagedestroy($out);
    return (bool)$ok;
}
