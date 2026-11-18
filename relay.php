<?php
// Aura Icons (duo): relays the short messages between the two iPhones through the server,
// alongside Bluetooth (whichever arrives first wins), and picks the Bluetooth roles.
//   GET  ?room=R&me=ID&since=N  long poll: messages from the other iPhone after N (waits up to ~8 s).
//                               since=-1: just registers, returns the current position.
//   POST {room, me, t, d}       sends a message.   POST {room, me, leave: true}: leaving.
// Every answer lists the live iPhones of the room, the oldest first (it hosts over Bluetooth).
// State: data/relay-<room>.json.

header('Content-Type: application/json');
header('Cache-Control: no-store');
set_time_limit(25);

function fail($code, $msg) {
  http_response_code($code);
  echo json_encode(['ok' => false, 'error' => $msg]);
  exit;
}

const PEER_TTL = 20;  // s without polling → gone
const MSG_TTL = 60;   // s
const MSG_MAX = 60;
const WAIT = 8;       // s of long poll

$post = $_SERVER['REQUEST_METHOD'] === 'POST';
if ($post) {
  $raw = file_get_contents('php://input', false, null, 0, 400001);
  if ($raw === false || strlen($raw) > 400000) fail(413, 'too large');
  $in = json_decode($raw, true);
  if (!is_array($in)) fail(400, 'bad json');
} else {
  $in = $_GET;
}
$room = isset($in['room']) ? $in['room'] : '';
$me = isset($in['me']) ? $in['me'] : '';
if (!is_string($room) || !preg_match('/^[a-z0-9-]{1,40}$/', $room)) fail(400, 'bad room');
if (!is_string($me) || !preg_match('/^[a-z0-9]{6,40}$/', $me)) fail(400, 'bad id');

$dir = __DIR__ . '/data';
if (!is_dir($dir) && !mkdir($dir, 0755, true)) fail(500, 'cannot create data/');
$file = "$dir/relay-$room.json";

// Reads, changes and writes the room under an exclusive lock.
function update($file, $fn) {
  $h = fopen($file, 'c+');
  if (!$h) fail(500, 'cannot open');
  flock($h, LOCK_EX);
  $raw = stream_get_contents($h);
  $s = $raw ? json_decode($raw, true) : null;
  if (!is_array($s)) $s = ['seq' => 0, 'msgs' => [], 'peers' => []];
  $now = microtime(true);
  foreach ($s['peers'] as $id => $p) if ($p['last'] < $now - PEER_TTL) unset($s['peers'][$id]);
  $s['msgs'] = array_values(array_filter($s['msgs'], function ($m) use ($now) { return $m['at'] > $now - MSG_TTL; }));
  $fn($s, $now);
  if (count($s['msgs']) > MSG_MAX) $s['msgs'] = array_slice($s['msgs'], -MSG_MAX);
  ftruncate($h, 0);
  rewind($h);
  fwrite($h, json_encode($s));
  fflush($h);
  flock($h, LOCK_UN);
  fclose($h);
  return $s;
}
function read_room($file) {
  $h = @fopen($file, 'r');
  if (!$h) return null;
  flock($h, LOCK_SH);
  $raw = stream_get_contents($h);
  flock($h, LOCK_UN);
  fclose($h);
  $s = json_decode($raw, true);
  return is_array($s) ? $s : null;
}
function peers($s) {
  $p = $s['peers'];
  uasort($p, function ($a, $b) { return $a['first'] <=> $b['first']; });
  return array_keys($p);
}
function touch_peer(&$s, $me, $now) {
  $first = isset($s['peers'][$me]) ? $s['peers'][$me]['first'] : $now;
  $s['peers'][$me] = ['first' => $first, 'last' => $now];
}

if ($post) {
  if (!empty($in['leave'])) {
    update($file, function (&$s) use ($me) { unset($s['peers'][$me]); });
    echo json_encode(['ok' => true]);
    exit;
  }
  $t = isset($in['t']) ? $in['t'] : '';
  if (!is_string($t) || !preg_match('/^[a-z-]{1,20}$/', $t)) fail(400, 'bad type');
  $d = isset($in['d']) ? $in['d'] : null;
  $s = update($file, function (&$s, $now) use ($me, $t, $d) {
    touch_peer($s, $me, $now);
    $s['seq']++;
    $s['msgs'][] = ['seq' => $s['seq'], 'from' => $me, 't' => $t, 'd' => $d, 'at' => $now];
  });
  echo json_encode(['ok' => true, 'seq' => $s['seq'], 'peers' => peers($s)]);
  exit;
}

$since = isset($in['since']) ? (int) $in['since'] : -1;
$s = update($file, function (&$s, $now) use ($me) { touch_peer($s, $me, $now); });
if ($since < 0) {
  echo json_encode(['ok' => true, 'seq' => $s['seq'], 'msgs' => [], 'peers' => peers($s)]);
  exit;
}
// Long poll: answer as soon as the other iPhone sent something, or arrived / left.
$known = peers($s);
$end = microtime(true) + WAIT;
for (;;) {
  $out = [];
  foreach ($s['msgs'] as $m) {
    if ($m['seq'] > $since && $m['from'] !== $me) $out[] = ['t' => $m['t'], 'd' => $m['d']];
  }
  if ($out || peers($s) !== $known || microtime(true) >= $end || connection_aborted()) break;
  usleep(80000);
  $r = read_room($file);
  if ($r) $s = $r;
}
echo json_encode(['ok' => true, 'seq' => $s['seq'], 'msgs' => $out, 'peers' => peers($s)]);
