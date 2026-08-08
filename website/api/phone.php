<?php
// ApneScan Connect — Phone <-> PC relay. Lets the desktop app and a phone
// exchange ANY file over the internet (no same network needed), in BOTH
// directions, inside a single QR-paired session.
//
//   POST ?action=up&s=CODE&dir=toPC|toPhone|toPCScan   (multipart 'file')      -> {ok,id,name,size}
//   GET  ?action=list&s=CODE&dir=...&since=TS                                   -> {ok, items:[{id,name,ts,size}]}
//   GET  ?action=dl&s=CODE&dir=...&id=ID                                        -> file download
//   GET/POST ?action=hello&s=CODE&name=DEVICE                                   -> {ok}   (phone heartbeat)
//   GET  ?action=status&s=CODE                                                  -> {ok,connected,device,ago}
//   GET  ?action=end&s=CODE                                                     -> {ok}   (invalidate session)
//
// Files auto-expire after 2 hours. Codes are random, per pairing session.
// A session can be explicitly ended: a tombstone then blocks all further use
// of that code until the 2-hour sweep removes it.
declare(strict_types=1);
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

const MAX_BYTES     = 100 * 1024 * 1024; // hard cap per file (shared-host limit)
const PRESENCE_WIN  = 15;                 // phone counts as "connected" for N s after last heartbeat
const SESSION_TTL   = 7200;               // 2 h — files + tombstones expire

$code   = preg_replace('/[^a-zA-Z0-9]/', '', (string)($_GET['s'] ?? ''));
$dirRaw = (string)($_GET['dir'] ?? 'toPC');
$dir    = in_array($dirRaw, ['toPC', 'toPhone', 'toPCScan'], true) ? $dirRaw : 'toPC';
$action = (string)($_GET['action'] ?? '');
if (strlen($code) < 4) { http_response_code(400); jout(['ok' => false, 'error' => 'bad code']); }

$base = __DIR__ . '/data/phone';
@is_dir($base) || @mkdir($base, 0755, true);
@file_exists($base . '/.htaccess') || @file_put_contents($base . '/.htaccess', "Require all denied\nDeny from all\n");
$cdir = $base . '/' . $code;      // per-session directory
$sdir = $cdir . '/' . $dir;       // per-direction directory

// Opportunistic cleanup: drop files/markers older than the session TTL.
foreach (glob($base . '/*/*/*') ?: [] as $f) { if (is_file($f) && filemtime($f) < time() - SESSION_TTL) @unlink($f); }
foreach (glob($base . '/*/_ended') ?: [] as $f) { if (is_file($f) && filemtime($f) < time() - SESSION_TTL) @unlink($f); }

function jout($x) { header('Content-Type: application/json'); echo json_encode($x); exit; }
function safe_name($n) {
    $n = str_replace(['/', '\\'], '_', (string)$n);           // no path separators
    $n = preg_replace('/[^A-Za-z0-9._\- ()]/', '_', $n);      // keep it simple + safe
    $n = ltrim($n, '.');                                       // no leading dots
    $n = substr($n, 0, 120);
    return $n !== '' ? $n : 'file';
}
function session_ended($cdir) { return is_file($cdir . '/_ended'); }

// A session that has been ended is dead until the sweep removes the tombstone.
if (session_ended($cdir) && $action !== 'end') { jout(['ok' => false, 'ended' => true]); }

// --- Phone heartbeat: marks the session as connected & records the device. ---
if ($action === 'hello') {
    @is_dir($cdir) || @mkdir($cdir, 0755, true);
    $name = substr(preg_replace('/[^A-Za-z0-9 .\-]/', '', (string)($_GET['name'] ?? 'Phone')), 0, 40) ?: 'Phone';
    @file_put_contents($cdir . '/_presence', json_encode(['name' => $name, 'ts' => time()]));
    jout(['ok' => true]);
}

// --- PC polls connection state. ---
if ($action === 'status') {
    $connected = false; $device = ''; $ago = -1;
    $p = $cdir . '/_presence';
    if (is_file($p)) {
        $j = json_decode((string)@file_get_contents($p), true);
        if (is_array($j)) {
            $ago = time() - (int)($j['ts'] ?? 0);
            $connected = $ago >= 0 && $ago <= PRESENCE_WIN;
            $device = (string)($j['name'] ?? '');
        }
    }
    jout(['ok' => true, 'connected' => $connected, 'device' => $device, 'ago' => $ago]);
}

// --- End the session: keep a tombstone, drop all data + presence. ---
if ($action === 'end') {
    if (is_dir($cdir)) {
        foreach (glob($cdir . '/*/*') ?: [] as $f) { if (is_file($f)) @unlink($f); }
        foreach (['toPC', 'toPhone', 'toPCScan'] as $d) { @rmdir($cdir . '/' . $d); }
        @unlink($cdir . '/_presence');
        @file_put_contents($cdir . '/_ended', (string)time());
    } else {
        @mkdir($cdir, 0755, true);
        @file_put_contents($cdir . '/_ended', (string)time());
    }
    jout(['ok' => true]);
}

// --- Upload a file into one direction. ---
if ($action === 'up') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); jout(['ok' => false, 'error' => 'POST']); }
    @is_dir($sdir) || @mkdir($sdir, 0755, true);
    $name = 'file'; $data = null;
    if (!empty($_FILES['file']['tmp_name']) && is_uploaded_file($_FILES['file']['tmp_name'])) {
        if ((int)($_FILES['file']['size'] ?? 0) > MAX_BYTES) { http_response_code(413); jout(['ok' => false, 'error' => 'too big', 'max' => MAX_BYTES]); }
        $name = safe_name($_FILES['file']['name'] ?? 'file');
        $data = file_get_contents($_FILES['file']['tmp_name']);
    } else {
        $raw = file_get_contents('php://input');
        if ($raw !== false && strlen($raw) > 0) {
            if (strlen($raw) > MAX_BYTES) { http_response_code(413); jout(['ok' => false, 'error' => 'too big', 'max' => MAX_BYTES]); }
            $data = $raw; $name = safe_name($_GET['name'] ?? 'upload.jpg');
        }
    }
    if ($data === null) { http_response_code(400); jout(['ok' => false, 'error' => 'no file']); }
    $id = time() . '_' . bin2hex(random_bytes(4)) . '__' . $name;
    @file_put_contents($sdir . '/' . $id, $data);
    jout(['ok' => true, 'id' => $id, 'name' => $name, 'size' => strlen($data)]);
}

// --- List queued files in one direction (newer than 'since'). ---
if ($action === 'list') {
    $since = (int)($_GET['since'] ?? 0);
    $items = [];
    foreach (glob($sdir . '/*') ?: [] as $f) {
        if (!is_file($f)) continue;
        $bn = basename($f);
        $ts = (int)strtok($bn, '_');
        if ($ts <= $since) continue;
        $pos = strpos($bn, '__');
        $name = $pos !== false ? substr($bn, $pos + 2) : $bn;
        $items[] = ['id' => $bn, 'name' => $name, 'ts' => $ts, 'size' => filesize($f)];
    }
    usort($items, fn($a, $b) => $a['ts'] <=> $b['ts']);
    jout(['ok' => true, 'items' => $items]);
}

// --- Download one queued file. Phone→PC files are one-shot (removed on pull). ---
if ($action === 'dl') {
    $id = basename((string)($_GET['id'] ?? ''));
    $f = $sdir . '/' . $id;
    if (!is_file($f)) { http_response_code(404); exit('not found'); }
    $pos = strpos($id, '__'); $name = $pos !== false ? substr($id, $pos + 2) : $id;
    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    $ct = [
        'pdf' => 'application/pdf', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png',
        'gif' => 'image/gif', 'webp' => 'image/webp', 'txt' => 'text/plain', 'csv' => 'text/csv',
        'zip' => 'application/zip', 'mp3' => 'audio/mpeg', 'mp4' => 'video/mp4',
    ][$ext] ?? 'application/octet-stream';
    header('Content-Type: ' . $ct);
    header('Content-Disposition: attachment; filename="' . $name . '"');
    header('Content-Length: ' . filesize($f));
    readfile($f);
    if ($dir === 'toPC' || $dir === 'toPCScan') @unlink($f);
    exit;
}

http_response_code(400); jout(['ok' => false, 'error' => 'bad action']);
