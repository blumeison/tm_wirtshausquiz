<?php
/**
 * Wirtshausquiz — backoffice auth (PHP 7.4 compatible).
 *
 * Google Sign-In -> ID token verified here against Google's JWKS -> PHP session.
 * Roles live in data/users.json (ADMIN | EDITOR | PENDING). Emails listed in
 * config 'admins' are always ADMIN and cannot be changed from the backoffice,
 * which also means there is always at least one admin.
 *
 * Deliberately no "first login becomes admin": as long as the fixed list is
 * in place, a stranger can never win that race.
 */

require_once __DIR__ . '/lib.php';

// ---- Session ---------------------------------------------------------------
function app_session_start()
{
    if (session_status() === PHP_SESSION_NONE) {
        session_name('wqsess');
        session_set_cookie_params([
            'lifetime' => 0,
            'path'     => '/',
            'secure'   => true,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_start();
    }
}

function current_user()
{
    app_session_start();
    if (empty($_SESSION['sub'])) {
        return null;
    }
    return [
        'sub'     => $_SESSION['sub'],
        'email'   => isset($_SESSION['email']) ? $_SESSION['email'] : '',
        'name'    => isset($_SESSION['name']) ? $_SESSION['name'] : '',
        'picture' => isset($_SESSION['picture']) ? $_SESSION['picture'] : '',
    ];
}

function login_user($sub, $email, $name, $picture)
{
    app_session_start();
    session_regenerate_id(true);
    $_SESSION['sub']     = 'google:' . $sub;
    $_SESSION['email']   = $email;
    $_SESSION['name']    = $name;
    $_SESSION['picture'] = $picture;
    return current_user();
}

function logout_user()
{
    app_session_start();
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
}

// ---- Roles -----------------------------------------------------------------
function role_rank($role)
{
    $m = ['PENDING' => 0, 'EDITOR' => 1, 'ADMIN' => 2];
    return isset($m[$role]) ? $m[$role] : -1;
}

function valid_role($role)
{
    return role_rank($role) >= 0;
}

/** Lowercased fixed admin list from config (+ data/config.json). */
function config_admins()
{
    $cfg = wq_config();
    $out = [];
    foreach ((isset($cfg['admins']) ? (array)$cfg['admins'] : []) as $a) {
        $a = strtolower(trim((string)$a));
        if ($a !== '') {
            $out[] = $a;
        }
    }
    return $out;
}

function is_fixed_admin($email)
{
    return in_array(strtolower(trim((string)$email)), config_admins(), true);
}

// ---- User store (data/users.json, flock) ------------------------------------
function users_file()
{
    wq_ensure_dir(WQ_DATA_DIR);
    return WQ_DATA_DIR . '/users.json';
}

function read_users()
{
    $f = users_file();
    if (!is_file($f)) {
        return [];
    }
    $list = json_decode((string)file_get_contents($f), true);
    return is_array($list) ? $list : [];
}

/** Same read-modify-write pattern as with_registrations(). */
function with_users(callable $fn, &$result = null)
{
    $fh = fopen(users_file(), 'c+');
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

function find_user_index($list, $email)
{
    $email = strtolower(trim((string)$email));
    foreach ($list as $i => $u) {
        if (strtolower(isset($u['email']) ? $u['email'] : '') === $email) {
            return $i;
        }
    }
    return -1;
}

/** Role of an email: fixed admins win, then users.json, else null. */
function role_of($email)
{
    if ($email === '') {
        return null;
    }
    if (is_fixed_admin($email)) {
        return 'ADMIN';
    }
    $list = read_users();
    $i = find_user_index($list, $email);
    if ($i < 0) {
        return null;
    }
    $r = isset($list[$i]['role']) ? $list[$i]['role'] : 'PENDING';
    return valid_role($r) ? $r : 'PENDING';
}

/** Logged-in user plus role, or null. */
function current_staff()
{
    $u = current_user();
    if (!$u) {
        return null;
    }
    $role = role_of($u['email']);
    $u['role'] = $role ? $role : 'PENDING';
    return $u;
}

/**
 * Gate for every backoffice endpoint. Role comes from the session and the
 * user store, never from the request.
 */
function require_role($min)
{
    $u = current_staff();
    if (!$u) {
        fail(401, 'login_required');
    }
    if (role_rank($u['role']) < role_rank($min)) {
        fail(403, 'forbidden');
    }
    require_same_origin();
    return $u;
}

/**
 * Writes must come from our own pages. The session cookie is SameSite=Lax,
 * this is the second belt: browsers send Origin on every cross-site POST.
 */
function require_same_origin()
{
    $method = strtoupper(isset($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : 'GET');
    if ($method === 'GET' || $method === 'HEAD') {
        return;
    }
    $origin = isset($_SERVER['HTTP_ORIGIN']) ? $_SERVER['HTTP_ORIGIN'] : '';
    if ($origin === '') {
        return;
    }
    $site = rtrim((string)wq_config()['site_url'], '/');
    if (strcasecmp(rtrim($origin, '/'), $site) !== 0) {
        fail(403, 'bad_origin');
    }
}

// ---- Google ID token verification (from tm_go, dependency-free) -------------
function b64url_decode($s)
{
    $s = strtr($s, '-_', '+/');
    $pad = strlen($s) % 4;
    if ($pad) {
        $s .= str_repeat('=', 4 - $pad);
    }
    return base64_decode($s);
}

function der_len($len)
{
    if ($len < 0x80) {
        return chr($len);
    }
    $bytes = '';
    while ($len > 0) {
        $bytes = chr($len & 0xff) . $bytes;
        $len >>= 8;
    }
    return chr(0x80 | strlen($bytes)) . $bytes;
}

function der_uint($bytes)
{
    if ($bytes === '') {
        $bytes = "\x00";
    }
    if (ord($bytes[0]) & 0x80) {
        $bytes = "\x00" . $bytes;
    }
    return "\x02" . der_len(strlen($bytes)) . $bytes;
}

/** Build a PEM public key from a JWK modulus (n) and exponent (e). */
function jwk_rsa_to_pem($n_b64, $e_b64)
{
    $n = b64url_decode($n_b64);
    $e = b64url_decode($e_b64);
    $rsa = "\x30" . der_len(strlen(der_uint($n) . der_uint($e))) . der_uint($n) . der_uint($e);
    $bit = "\x03" . der_len(strlen($rsa) + 1) . "\x00" . $rsa;
    $algo = "\x30\x0d\x06\x09\x2a\x86\x48\x86\xf7\x0d\x01\x01\x01\x05\x00";
    $spki = "\x30" . der_len(strlen($algo . $bit)) . $algo . $bit;
    return "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($spki), 64, "\n") . "-----END PUBLIC KEY-----\n";
}

function google_jwks($force = false)
{
    $dir = WQ_DATA_DIR . '/cache';
    wq_ensure_dir($dir);
    $f = $dir . '/google_jwks.json';
    if (!$force && is_file($f) && (time() - filemtime($f) < 3600)) {
        $j = json_decode((string)file_get_contents($f), true);
        if (!empty($j['keys'])) {
            return $j;
        }
    }
    $ch = curl_init('https://www.googleapis.com/oauth2/v3/certs');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $res = curl_exec($ch);
    curl_close($ch);
    $j = json_decode((string)$res, true);
    if (!empty($j['keys'])) {
        @file_put_contents($f, $res, LOCK_EX);
        return $j;
    }
    if (is_file($f)) { // stale cache beats no login at all
        $j = json_decode((string)file_get_contents($f), true);
        if (!empty($j['keys'])) {
            return $j;
        }
    }
    return ['keys' => []];
}

/** Returns the token payload, or null. Checks RS256 signature, iss, aud, exp. */
function verify_google_idtoken($jwt, $clientId)
{
    if (!is_string($jwt) || substr_count($jwt, '.') !== 2 || $clientId === '') {
        return null;
    }
    list($h64, $p64, $s64) = explode('.', $jwt);
    $header  = json_decode((string)b64url_decode($h64), true);
    $payload = json_decode((string)b64url_decode($p64), true);
    if (!is_array($header) || !is_array($payload)) {
        return null;
    }
    if ((isset($header['alg']) ? $header['alg'] : '') !== 'RS256' || empty($header['kid'])) {
        return null;
    }

    $findKey = function ($jwks, $kid) {
        foreach ((isset($jwks['keys']) ? $jwks['keys'] : []) as $k) {
            if ((isset($k['kid']) ? $k['kid'] : '') === $kid) {
                return $k;
            }
        }
        return null;
    };
    $key = $findKey(google_jwks(false), $header['kid']);
    if (!$key) {
        $key = $findKey(google_jwks(true), $header['kid']); // key rotation
    }
    if (!$key || empty($key['n']) || empty($key['e'])) {
        return null;
    }
    $pem = jwk_rsa_to_pem($key['n'], $key['e']);
    if (openssl_verify($h64 . '.' . $p64, b64url_decode($s64), $pem, OPENSSL_ALGO_SHA256) !== 1) {
        return null;
    }

    $iss = isset($payload['iss']) ? $payload['iss'] : '';
    if (!in_array($iss, ['https://accounts.google.com', 'accounts.google.com'], true)) {
        return null;
    }
    if ((isset($payload['aud']) ? $payload['aud'] : '') !== $clientId) {
        return null;
    }
    if ((int)(isset($payload['exp']) ? $payload['exp'] : 0) < time() - 60) {
        return null;
    }
    if (empty($payload['sub'])) {
        return null;
    }
    return $payload;
}
