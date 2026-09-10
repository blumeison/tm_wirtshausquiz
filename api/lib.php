<?php
/**
 * Wirtshausquiz — shared backend library (PHP 7.4 compatible).
 *
 * JSON filestore with flock, validation, mail, rate limiting.
 * No match(), str_contains(), constructor promotion, union types or enums —
 * the host runs PHP 7.4.33.
 */

error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);
ini_set('display_errors', '0');

// ---- Paths -----------------------------------------------------------------
// api/ -> httpdocs/ -> (sibling) data/   == __DIR__ . /../../data
define('WQ_DATA_DIR', realpath(__DIR__ . '/..') . '/../data');

function wq_config()
{
    static $cfg = null;
    if ($cfg === null) {
        $cfg = require __DIR__ . '/config.php';
        // Server-side overrides (secrets, date changes) without a redeploy.
        $f = WQ_DATA_DIR . '/config.json';
        if (is_file($f)) {
            $over = json_decode((string)file_get_contents($f), true);
            if (is_array($over)) {
                $cfg = array_merge($cfg, $over);
            }
        }
    }
    return $cfg;
}

function wq_ensure_dir($dir)
{
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    return is_dir($dir);
}

// ---- JSON responses --------------------------------------------------------
function json_out($data, $code = 200)
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function fail($code, $msg, $extra = [])
{
    json_out(array_merge(['ok' => false, 'error' => $msg], $extra), $code);
}

function ok($data = [])
{
    json_out(array_merge(['ok' => true], $data), 200);
}

function require_method($method)
{
    if (strtoupper(isset($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : 'GET') !== strtoupper($method)) {
        fail(405, 'Methode nicht erlaubt');
    }
}

function read_json_body()
{
    $raw = file_get_contents('php://input');
    if ($raw === '' || $raw === false) {
        return [];
    }
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

function client_ip()
{
    $ip = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '';
    return substr((string)$ip, 0, 45);
}

// ---- IDs / tokens ----------------------------------------------------------
function gen_id($prefix)
{
    return $prefix . bin2hex(random_bytes(4));
}

function gen_token()
{
    return bin2hex(random_bytes(16)); // 32 hex chars — this one is a capability
}

function now_iso()
{
    $d = new DateTime('now', new DateTimeZone('Europe/Vienna'));
    return $d->format('c');
}

// ---- Validation ------------------------------------------------------------
function clean_str($v, $max = 200)
{
    if (!is_string($v)) {
        return '';
    }
    $v = trim($v);
    $v = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $v);
    if (function_exists('mb_substr')) {
        $v = mb_substr($v, 0, $max);
    } else {
        $v = substr($v, 0, $max);
    }
    return $v;
}

function clean_email($v)
{
    $v = strtolower(clean_str($v, 190));
    if ($v === '' || !filter_var($v, FILTER_VALIDATE_EMAIL)) {
        return '';
    }
    return $v;
}

function clean_phone($v)
{
    $v = clean_str($v, 40);
    if ($v === '') {
        return '';
    }
    // digits, spaces, +, /, -, ( )
    if (!preg_match('#^[0-9 +/()\-]{6,40}$#', $v)) {
        return '';
    }
    return $v;
}

/** Normalized key for duplicate detection: lowercase, no spaces/punctuation. */
function norm_key($v)
{
    $v = function_exists('mb_strtolower') ? mb_strtolower($v, 'UTF-8') : strtolower($v);
    $v = preg_replace('/[^\p{L}\p{N}]+/u', '', $v);
    return (string)$v;
}

// ---- Registration store ----------------------------------------------------
function registrations_dir()
{
    $dir = WQ_DATA_DIR . '/registrations';
    wq_ensure_dir($dir);
    return $dir;
}

function valid_session_id($id)
{
    return is_string($id) && preg_match('/^[A-Za-z0-9_-]{1,40}$/', $id) === 1;
}

function registrations_file($sessionId)
{
    if (!valid_session_id($sessionId)) {
        fail(400, 'ungültige Session');
    }
    return registrations_dir() . '/' . $sessionId . '.json';
}

/**
 * Read-modify-write under an exclusive lock.
 *
 * $fn receives the current list (array) and returns the list to store, or null
 * to leave the file untouched. The return value of $fn is handed back through
 * $result so callers can report what happened.
 *
 * Every write path goes through here. The free-round promo means several
 * signups can land in the same second, and an unlocked read-modify-write would
 * silently drop one of them.
 */
function with_registrations($sessionId, callable $fn, &$result = null)
{
    $file = registrations_file($sessionId);
    $fh = fopen($file, 'c+');
    if ($fh === false) {
        fail(500, 'Speicher nicht verfügbar');
    }
    if (!flock($fh, LOCK_EX)) {
        fclose($fh);
        fail(500, 'Speicher belegt, bitte nochmal versuchen');
    }

    $raw = stream_get_contents($fh);
    $list = json_decode((string)$raw, true);
    if (!is_array($list)) {
        $list = [];
    }

    $out = $fn($list, $result);

    if (is_array($out)) {
        $json = json_encode(array_values($out), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        ftruncate($fh, 0);
        rewind($fh);
        fwrite($fh, $json);
        fflush($fh);
    }

    flock($fh, LOCK_UN);
    fclose($fh);
    return $result;
}

/** Read-only access, no lock needed. */
function read_registrations($sessionId)
{
    $file = registrations_file($sessionId);
    if (!is_file($file)) {
        return [];
    }
    $list = json_decode((string)file_get_contents($file), true);
    return is_array($list) ? $list : [];
}

/**
 * Derive standing from the stored list.
 *
 * Slot (CONFIRMED / WAITLIST) and the free-round rank are NOT stored — they are
 * computed from signup order among the non-cancelled entries. That way a
 * cancellation automatically promotes the next team on the waitlist and frees
 * up a free round, with no reassignment bookkeeping and no chance of handing
 * out the same rank twice.
 *
 * A team can only ever move up: nothing can be inserted before it, and a
 * cancelled signup is never revived.
 */
function standings($list, $cfg)
{
    $active = [];
    foreach ($list as $r) {
        $status = isset($r['status']) ? $r['status'] : 'ACTIVE';
        if ($status !== 'CANCELLED') {
            $active[] = $r;
        }
    }
    usort($active, function ($a, $b) {
        $x = isset($a['createdAt']) ? $a['createdAt'] : '';
        $y = isset($b['createdAt']) ? $b['createdAt'] : '';
        if ($x === $y) {
            return strcmp(isset($a['id']) ? $a['id'] : '', isset($b['id']) ? $b['id'] : '');
        }
        return strcmp($x, $y);
    });

    $capacity   = (int)$cfg['capacity_teams'];
    $promoTeams = !empty($cfg['promo_enabled']) ? (int)$cfg['promo_teams'] : 0;
    // After promo_until nobody new can get a free round — not even by moving up
    // when an earlier team cancels. Teams who already have one keep it.
    $promoUntil = !empty($cfg['promo_until']) ? strtotime($cfg['promo_until']) : false;
    $promoOpen  = ($promoUntil === false) || (time() <= $promoUntil);
    $promoGiven = 0;

    $out = [];
    $people = 0;
    $i = 0;
    foreach ($active as $r) {
        $i++;
        $r['position']  = $i;
        $r['slot']      = ($i <= $capacity) ? 'CONFIRMED' : 'WAITLIST';
        $inTime = ($promoUntil === false)
            || (isset($r['createdAt']) && strtotime($r['createdAt']) <= $promoUntil);
        if ($promoGiven < $promoTeams && $inTime) {
            $promoGiven++;
            $r['promoRank'] = $promoGiven;
        } else {
            $r['promoRank'] = 0;
        }
        if ($r['slot'] === 'CONFIRMED') {
            $people += (int)$r['size'];
        }
        $out[] = $r;
    }

    return [
        'entries'       => $out,
        'teamsTotal'    => count($out),
        'teamsConfirmed' => min(count($out), $capacity),
        'teamsWaitlist' => max(0, count($out) - $capacity),
        'peopleConfirmed' => $people,
        'spotsLeft'     => max(0, $capacity - count($out)),
        'promoLeft'     => $promoOpen ? max(0, $promoTeams - $promoGiven) : 0,
    ];
}

function find_by_token($list, $token)
{
    foreach ($list as $i => $r) {
        if (isset($r['cancelToken']) && hash_equals((string)$r['cancelToken'], (string)$token)) {
            return $i;
        }
    }
    return -1;
}

// ---- Rate limiting ---------------------------------------------------------
/**
 * Returns true when this IP may sign up again. Prunes as it goes, so the file
 * stays small without a cron job.
 */
function rate_limit_ok($ip)
{
    $cfg = wq_config();
    $max    = (int)$cfg['rate_limit_max'];
    $window = (int)$cfg['rate_limit_window'];
    if ($max <= 0 || $ip === '') {
        return true;
    }

    wq_ensure_dir(WQ_DATA_DIR);
    $file = WQ_DATA_DIR . '/ratelimit.json';
    $allowed = true;

    $fh = fopen($file, 'c+');
    if ($fh === false) {
        return true; // never block a signup because of the rate-limit file
    }
    if (flock($fh, LOCK_EX)) {
        $raw = stream_get_contents($fh);
        $map = json_decode((string)$raw, true);
        if (!is_array($map)) {
            $map = [];
        }
        $now = time();
        $key = hash('sha256', $ip); // don't store raw IPs longer than needed

        foreach ($map as $k => $stamps) {
            $keep = [];
            foreach ((array)$stamps as $t) {
                if ($now - (int)$t < $window) {
                    $keep[] = (int)$t;
                }
            }
            if (count($keep) > 0) {
                $map[$k] = $keep;
            } else {
                unset($map[$k]);
            }
        }

        $mine = isset($map[$key]) ? $map[$key] : [];
        if (count($mine) >= $max) {
            $allowed = false;
        } else {
            $mine[] = $now;
            $map[$key] = $mine;
        }

        $json = json_encode($map, JSON_UNESCAPED_SLASHES);
        ftruncate($fh, 0);
        rewind($fh);
        fwrite($fh, $json);
        fflush($fh);
        flock($fh, LOCK_UN);
    }
    fclose($fh);

    return $allowed;
}

// ---- Formatting ------------------------------------------------------------
function fmt_date_de($iso)
{
    $days = ['Sonntag', 'Montag', 'Dienstag', 'Mittwoch', 'Donnerstag', 'Freitag', 'Samstag'];
    $months = ['', 'Jänner', 'Februar', 'März', 'April', 'Mai', 'Juni', 'Juli',
               'August', 'September', 'Oktober', 'November', 'Dezember'];
    $ts = strtotime($iso);
    if ($ts === false) {
        return $iso;
    }
    return $days[(int)date('w', $ts)] . ', ' . (int)date('j', $ts) . '. '
         . $months[(int)date('n', $ts)] . ' ' . date('Y', $ts);
}

// ---- Mail ------------------------------------------------------------------
function send_mail($to, $subject, $html, $text)
{
    $cfg = wq_config();
    $fromAddr = $cfg['mail_from'];
    $fromName = $cfg['mail_from_name'];

    if (!empty($cfg['smtp_host']) && !empty($cfg['smtp_pass'])) {
        require_once __DIR__ . '/phpmailer/Exception.php';
        require_once __DIR__ . '/phpmailer/PHPMailer.php';
        require_once __DIR__ . '/phpmailer/SMTP.php';
        $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
        try {
            $mail->isSMTP();
            $mail->Host     = $cfg['smtp_host'];
            $mail->Port     = (int)$cfg['smtp_port'];
            $mail->SMTPAuth = true;
            $mail->Username = $cfg['smtp_user'];
            $mail->Password = $cfg['smtp_pass'];
            if ($cfg['smtp_secure'] === 'tls') {
                $mail->SMTPSecure = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
            } elseif ($cfg['smtp_secure'] === 'ssl') {
                $mail->SMTPSecure = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS;
            }
            $mail->Timeout = 15;
            $mail->CharSet = 'UTF-8';
            $mail->setFrom($fromAddr, $fromName);
            if (!empty($cfg['mail_reply_to'])) {
                $mail->addReplyTo($cfg['mail_reply_to']);
            }
            $mail->addAddress($to);
            $mail->isHTML(true);
            $mail->Subject = $subject;
            $mail->Body    = $html;
            $mail->AltBody = $text;
            $mail->send();
            return true;
        } catch (\Throwable $e) {
            // fall through to mail()
        }
    }

    $encodedName = '=?UTF-8?B?' . base64_encode($fromName) . '?=';
    $headers = [
        'From: ' . $encodedName . ' <' . $fromAddr . '>',
        'Reply-To: ' . $fromAddr,
        'Content-Type: text/html; charset=UTF-8',
        'MIME-Version: 1.0',
    ];
    $encodedSubject = '=?UTF-8?B?' . base64_encode($subject) . '?=';
    return @mail($to, $encodedSubject, $html, implode("\r\n", $headers), '-f' . $fromAddr);
}
