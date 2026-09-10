<?php
/** GET -> who am I + client config for the backoffice bootstrap. */
require_once __DIR__ . '/auth.php';

$cfg = wq_config();
$u = current_staff();

ok([
    'loggedIn'       => (bool)$u,
    'role'           => $u ? $u['role'] : null,
    'user'           => $u ? [
        'email'   => $u['email'],
        'name'    => $u['name'],
        'picture' => $u['picture'],
    ] : null,
    'googleClientId' => isset($cfg['google_client_id']) ? (string)$cfg['google_client_id'] : '',
    'sessionId'      => $cfg['session_id'],
    'eventTitle'     => isset($cfg['event_title']) ? $cfg['event_title'] : 'Wirtshausquiz',
]);
