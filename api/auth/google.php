<?php
/**
 * POST { credential: "<google id_token>" } -> verify -> PHP session.
 *
 * Unknown accounts are not rejected: they are recorded as PENDING and see a
 * waiting page, so an admin can approve them later without asking for the
 * exact spelling of their Google address.
 */
require_once __DIR__ . '/../auth.php';

require_method('POST');
require_same_origin();

$cfg = wq_config();
$clientId = isset($cfg['google_client_id']) ? (string)$cfg['google_client_id'] : '';
if ($clientId === '') {
    fail(503, 'google_not_configured');
}

$body = read_json_body();
$claims = verify_google_idtoken(isset($body['credential']) ? $body['credential'] : '', $clientId);
if (!$claims) {
    fail(401, 'invalid_token');
}
if (isset($claims['email_verified']) && $claims['email_verified'] === false) {
    fail(403, 'email_not_verified');
}

$email   = strtolower(trim((string)(isset($claims['email']) ? $claims['email'] : '')));
$name    = clean_str(isset($claims['name']) ? (string)$claims['name'] : '', 80);
$picture = clean_str(isset($claims['picture']) ? (string)$claims['picture'] : '', 300);
if ($email === '') {
    fail(403, 'email_missing');
}

$role = null;
with_users(function ($list, &$role) use ($email, $name, $picture, $claims) {
    $i = find_user_index($list, $email);
    if ($i < 0) {
        $list[] = [
            'sub'       => 'google:' . $claims['sub'],
            'email'     => $email,
            'name'      => $name,
            'role'      => is_fixed_admin($email) ? 'ADMIN' : 'PENDING',
            'createdAt' => now_iso(),
        ];
        $i = count($list) - 1;
    }
    if (is_fixed_admin($email)) {
        $list[$i]['role'] = 'ADMIN';
    }
    $list[$i]['sub']       = 'google:' . $claims['sub'];
    $list[$i]['name']      = $name !== '' ? $name : (isset($list[$i]['name']) ? $list[$i]['name'] : '');
    $list[$i]['picture']   = $picture;
    $list[$i]['lastLogin'] = now_iso();
    $role = $list[$i]['role'];
    return $list;
}, $role);

$user = login_user((string)$claims['sub'], $email, $name, $picture);

ok(['user' => $user, 'role' => $role]);
