<?php
/**
 * TEMPORARY host probe for the AI generator — delete after use.
 *   ?mode=ini            PHP limits relevant for a long AI request
 *   ?mode=stream&s=100   keeps a streamed response open for s seconds
 */
require_once __DIR__ . '/lib.php';

$mode = isset($_GET['mode']) ? $_GET['mode'] : 'ini';

if ($mode === 'stream') {
    $raised = @set_time_limit(0);
    header('Content-Type: text/plain; charset=utf-8');
    header('X-Accel-Buffering: no');
    header('Cache-Control: no-store');
    while (ob_get_level() > 0) {
        ob_end_flush();
    }
    $secs = min(300, max(5, (int)(isset($_GET['s']) ? $_GET['s'] : 100)));
    $t0 = time();
    echo 'set_time_limit(0): ' . ($raised ? 'ok' : 'refused') . "\n";
    flush();
    while (time() - $t0 < $secs) {
        echo 'tick ' . (time() - $t0) . "\n";
        flush();
        sleep(5);
    }
    echo 'done ' . (time() - $t0) . "\n";
    exit;
}

$disabled = array_map('trim', explode(',', (string)ini_get('disable_functions')));
$before = ini_get('max_execution_time');
$raised = function_exists('set_time_limit') && !in_array('set_time_limit', $disabled, true) ? @set_time_limit(300) : false;
ok([
    'max_execution_time'      => $before,
    'after_set_time_limit'    => ini_get('max_execution_time'),
    'set_time_limit_ok'       => (bool)$raised,
    'memory_limit'            => ini_get('memory_limit'),
    'sapi'                    => PHP_SAPI,
    'fastcgi_finish_request'  => function_exists('fastcgi_finish_request'),
    'curl'                    => function_exists('curl_version') ? curl_version()['version'] : null,
    'ssl'                     => function_exists('curl_version') ? curl_version()['ssl_version'] : null,
]);
