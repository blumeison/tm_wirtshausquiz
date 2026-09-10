<?php
/**
 * Backoffice: whitelist and roles. ADMIN only.
 *
 *   GET                                   -> all users (fixed admins included)
 *   POST {action:"add", email, role}      -> pre-approve an address before first login
 *   POST {action:"setRole", email, role}
 *   POST {action:"delete", email}
 *
 * Fixed admins from config cannot be changed here — that is what guarantees
 * the backoffice can never lock itself out.
 */
require_once __DIR__ . '/../auth.php';

$me = require_role('ADMIN');
$method = strtoupper(isset($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : 'GET');

if ($method === 'POST') {
    $in = read_json_body();
    $action = isset($in['action']) ? $in['action'] : '';
    $email = clean_email(isset($in['email']) ? $in['email'] : '');
    $role = isset($in['role']) ? strtoupper((string)$in['role']) : '';

    if ($email === '') {
        fail(422, 'Bitte eine gültige E-Mail-Adresse angeben.');
    }
    if (is_fixed_admin($email)) {
        fail(409, 'Diese Adresse ist fix als Admin eingetragen und lässt sich hier nicht ändern.');
    }
    if (($action === 'add' || $action === 'setRole') && !valid_role($role)) {
        fail(422, 'Unbekannte Rolle');
    }

    $res = null;
    with_users(function ($list, &$res) use ($action, $email, $role, $me) {
        $i = find_user_index($list, $email);
        if ($action === 'add') {
            if ($i >= 0) {
                $list[$i]['role'] = $role;
            } else {
                $list[] = ['sub' => '', 'email' => $email, 'name' => '', 'role' => $role,
                           'createdAt' => now_iso(), 'addedBy' => $me['email']];
            }
            $res = ['ok' => true];
            return $list;
        }
        if ($i < 0) {
            $res = ['error' => 'Benutzer nicht gefunden'];
            return null;
        }
        if ($action === 'setRole') {
            $list[$i]['role'] = $role;
            $res = ['ok' => true];
            return $list;
        }
        if ($action === 'delete') {
            array_splice($list, $i, 1);
            $res = ['ok' => true];
            return $list;
        }
        $res = ['error' => 'Unbekannte Aktion'];
        return null;
    }, $res);
    if (isset($res['error'])) {
        fail(400, $res['error']);
    }
    ok();
}

$users = read_users();
$seen = [];
$out = [];
foreach ($users as $u) {
    $email = strtolower(isset($u['email']) ? $u['email'] : '');
    $seen[$email] = true;
    $fixed = is_fixed_admin($email);
    $out[] = [
        'email'     => $email,
        'name'      => isset($u['name']) ? $u['name'] : '',
        'role'      => $fixed ? 'ADMIN' : (isset($u['role']) ? $u['role'] : 'PENDING'),
        'fixed'     => $fixed,
        'lastLogin' => isset($u['lastLogin']) ? $u['lastLogin'] : null,
        'createdAt' => isset($u['createdAt']) ? $u['createdAt'] : null,
    ];
}
foreach (config_admins() as $a) {
    if (!isset($seen[$a])) {
        $out[] = ['email' => $a, 'name' => '', 'role' => 'ADMIN', 'fixed' => true, 'lastLogin' => null, 'createdAt' => null];
    }
}
usort($out, function ($a, $b) {
    $d = role_rank($b['role']) - role_rank($a['role']);
    return $d !== 0 ? $d : strcmp($a['email'], $b['email']);
});

ok(['users' => $out, 'me' => $me['email']]);
