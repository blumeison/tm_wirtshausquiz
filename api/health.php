<?php
/**
 * GET api/health.php — one-off host probe for this subdomain.
 *
 * TEMPORARY. Delete once the signup is accepted (SPEC P0); it reports paths
 * and extension state that nobody else needs to see.
 */

require_once __DIR__ . '/lib.php';

require_method('GET');

$dataDir = WQ_DATA_DIR;
$regDir  = $dataDir . '/registrations';

$writable = false;
$probe    = $dataDir . '/.write-probe';
if (wq_ensure_dir($dataDir) && @file_put_contents($probe, (string)time()) !== false) {
    $writable = true;
    @unlink($probe);
}

$cfg = wq_config();

ok([
    'php'        => PHP_VERSION,
    'sapi'       => PHP_SAPI,
    'extensions' => [
        'gd'       => extension_loaded('gd'),
        'openssl'  => extension_loaded('openssl'),
        'curl'     => extension_loaded('curl'),
        'mbstring' => extension_loaded('mbstring'),
        'json'     => extension_loaded('json'),
    ],
    'paths' => [
        'dataDir'          => $dataDir,
        'dataDirExists'    => is_dir($dataDir),
        'dataDirWritable'  => $writable,
        'registrationsDir' => is_dir($regDir),
        'docRoot'          => isset($_SERVER['DOCUMENT_ROOT']) ? $_SERVER['DOCUMENT_ROOT'] : '',
    ],
    'config' => [
        'overrideFileFound' => is_file($dataDir . '/config.json'),
        'smtpConfigured'    => !empty($cfg['smtp_pass']),
        'registrationOpen'  => !empty($cfg['registration_open']),
        'promoEnabled'      => !empty($cfg['promo_enabled']),
        'sessionId'         => $cfg['session_id'],
    ],
    'time' => now_iso(),
]);
