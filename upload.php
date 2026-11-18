<?php
// Aura Icons: receives the icons cut out on the spectator's phone and stores them in
// data/<session>.json, which the performer's phone then downloads (one request).
// Sessions older than a day are deleted.

header('Content-Type: application/json');
header('Cache-Control: no-store');

function fail($code, $msg) {
  http_response_code($code);
  echo json_encode(['ok' => false, 'error' => $msg]);
  exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') fail(405, 'POST only');

$MAX = 4000000; // bytes: ~25 icons of 180 px in PNG take well under 2 MB
$raw = file_get_contents('php://input', false, null, 0, $MAX + 1);
if ($raw === false || strlen($raw) > $MAX) fail(413, 'too large');

$d = json_decode($raw, true);
$session = isset($d['session']) ? $d['session'] : '';
if (!is_string($session) || !preg_match('/^[a-z0-9]{6,40}$/', $session)) fail(400, 'bad session');
if (!isset($d['icons']) || !is_array($d['icons']) || count($d['icons']) > 120) fail(400, 'bad icons');

$icons = [];
foreach ($d['icons'] as $ic) {
  $id = isset($ic['id']) ? $ic['id'] : '';
  $src = isset($ic['src']) ? $ic['src'] : '';
  if (!is_string($id) || !preg_match('/^[a-z0-9-]{1,60}$/', $id)) fail(400, 'bad id');
  if (!is_string($src) || !preg_match('#^data:image/png;base64,[A-Za-z0-9+/=]+$#', $src)) fail(400, 'bad image');
  $icons[] = [
    'id' => $id,
    'src' => $src,
    'w' => (float) (isset($ic['w']) ? $ic['w'] : 0.15),
    'a' => (float) (isset($ic['a']) ? $ic['a'] : 1),
  ];
}

$dir = __DIR__ . '/data';
if (!is_dir($dir) && !mkdir($dir, 0755, true)) fail(500, 'cannot create data/');
foreach (glob($dir . '/*.json') ?: [] as $f) {
  if (filemtime($f) < time() - 86400) @unlink($f);
}
if (file_put_contents("$dir/$session.json", json_encode(['session' => $session, 'icons' => $icons]), LOCK_EX) === false) {
  fail(500, 'cannot write');
}

echo json_encode(['ok' => true, 'url' => "data/$session.json"]);
