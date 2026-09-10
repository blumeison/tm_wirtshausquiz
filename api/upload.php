<?php
/**
 * POST multipart "file" -> stored under httpdocs/uploads/, returns {url}.
 *
 * The type is taken from the file content (finfo), not from the name the
 * browser sends. SVG is deliberately not allowed: served from our own domain
 * it could carry script. Photos are scaled to 1920px — phone pictures are
 * 4000px+ and would make the beamer view slow.
 * The deploy mirror excludes ^uploads/, so --delete never touches these files.
 */
require_once __DIR__ . '/questions_lib.php';

require_role('EDITOR');
require_method('POST');

$max = upload_max_bytes();
if (empty($_FILES['file']) || !is_array($_FILES['file'])) {
    // A body over post_max_size reaches PHP as an empty request.
    fail(413, 'Keine Datei angekommen — vermutlich größer als erlaubt (max. ' . fmt_mb($max) . ').');
}
$f = $_FILES['file'];
if ($f['error'] === UPLOAD_ERR_INI_SIZE || $f['error'] === UPLOAD_ERR_FORM_SIZE || $f['size'] > $max) {
    fail(413, 'Die Datei ist größer als erlaubt (max. ' . fmt_mb($max) . ').');
}
if ($f['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($f['tmp_name'])) {
    fail(400, 'Upload fehlgeschlagen, bitte nochmal versuchen.');
}

$allowed = [
    'image/jpeg'      => 'jpg',
    'image/png'       => 'png',
    'image/gif'       => 'gif',
    'image/webp'      => 'webp',
    'audio/mpeg'      => 'mp3',
    'audio/mp4'       => 'm4a',
    'audio/x-m4a'     => 'm4a',
    'audio/ogg'       => 'ogg',
    'application/ogg' => 'ogg',
    'audio/wav'       => 'wav',
    'audio/x-wav'     => 'wav',
    'video/mp4'       => 'mp4',
    'video/webm'      => 'webm',
    'video/quicktime' => 'mov',
];

$mime = '';
if (function_exists('finfo_open')) {
    $fi = finfo_open(FILEINFO_MIME_TYPE);
    $mime = (string)finfo_file($fi, $f['tmp_name']);
    finfo_close($fi);
}
if ($mime === '' || $mime === 'application/octet-stream') {
    $mime = (string)$f['type'];
}
if (!isset($allowed[$mime])) {
    fail(415, 'Dateityp ' . ($mime !== '' ? $mime : 'unbekannt') . ' ist nicht erlaubt. '
        . 'Bilder: JPG, PNG, GIF, WebP · Audio: MP3, M4A, OGG, WAV · Video: MP4, WebM, MOV.');
}
$ext = $allowed[$mime];

$dir = realpath(__DIR__ . '/..') . '/uploads';
if (!wq_ensure_dir($dir) || !is_writable($dir)) {
    fail(500, 'Der Upload-Ordner ist am Server nicht beschreibbar.');
}
$name = bin2hex(random_bytes(10)) . '.' . $ext;
$dest = $dir . '/' . $name;

$done = in_array($ext, ['jpg', 'png', 'webp'], true) && shrink_image($f['tmp_name'], $dest, $ext, 1920);
if (!$done && !move_uploaded_file($f['tmp_name'], $dest)) {
    fail(500, 'Die Datei konnte nicht gespeichert werden.');
}
@chmod($dest, 0644);

ok(['url' => '/uploads/' . $name, 'mime' => $mime, 'bytes' => (int)filesize($dest)]);
