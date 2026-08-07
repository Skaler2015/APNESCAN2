<?php
// ApneScan — Phone <-> PC relay. Lets the desktop app and a phone exchange
// files over the internet (no same network needed), in BOTH directions.
//   POST ?action=up&s=CODE&dir=toPC|toPhone   (multipart 'file' or raw body)  -> {ok,id}
//   GET  ?action=list&s=CODE&dir=...&since=TS  -> {ok, items:[{id,name,ts,size}]}
//   GET  ?action=dl&s=CODE&dir=...&id=ID       -> file download
// Files auto-expire after 2 hours. Codes are random, per pairing session.
declare(strict_types=1);
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

$code   = preg_replace('/[^a-zA-Z0-9]/', '', (string)($_GET['s'] ?? ''));
$dir    = (($_GET['dir'] ?? 'toPC') === 'toPhone') ? 'toPhone' : 'toPC';
$action = $_GET['action'] ?? '';
if (strlen($code) < 4) { http_response_code(400); exit('bad code'); }

$base = __DIR__ . '/data/phone';
@is_dir($base) || @mkdir($base, 0755, true);
@file_exists($base . '/.htaccess') || @file_put_contents($base . '/.htaccess', "Require all denied\nDeny from all\n");
$sdir = $base . '/' . $code . '/' . $dir;

// Opportunistic cleanup: drop files older than 2 hours.
foreach (glob($base . '/*/*/*') ?: [] as $f) { if (is_file($f) && filemtime($f) < time() - 7200) @unlink($f); }

function jout($x) { header('Content-Type: application/json'); echo json_encode($x); exit; }
function safe_name($n) { $n = preg_replace('/[^A-Za-z0-9._\- ]/', '_', (string)$n); return substr($n, 0, 80) ?: 'file'; }

if ($action === 'up') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit('POST'); }
    @is_dir($sdir) || @mkdir($sdir, 0755, true);
    $name = 'file'; $data = null;
    if (!empty($_FILES['file']['tmp_name']) && is_uploaded_file($_FILES['file']['tmp_name'])) {
        if ((int)($_FILES['file']['size'] ?? 0) > 40 * 1024 * 1024) { http_response_code(413); exit('too big'); }
        $name = safe_name($_FILES['file']['name'] ?? 'file');
        $data = file_get_contents($_FILES['file']['tmp_name']);
    } else {
        $raw = file_get_contents('php://input');
        if ($raw !== false && strlen($raw) > 0 && strlen($raw) <= 40 * 1024 * 1024) { $data = $raw; $name = safe_name($_GET['name'] ?? 'upload.jpg'); }
    }
    if ($data === null) { http_response_code(400); exit('no file'); }
    $id = time() . '_' . bin2hex(random_bytes(4)) . '__' . $name;
    @file_put_contents($sdir . '/' . $id, $data);
    jout(['ok' => true, 'id' => $id]);
}

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

if ($action === 'dl') {
    $id = basename((string)($_GET['id'] ?? ''));
    $f = $sdir . '/' . $id;
    if (!is_file($f)) { http_response_code(404); exit('not found'); }
    $pos = strpos($id, '__'); $name = $pos !== false ? substr($id, $pos + 2) : $id;
    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    $ct = ['pdf' => 'application/pdf', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png'][$ext] ?? 'application/octet-stream';
    header('Content-Type: ' . $ct);
    header('Content-Disposition: attachment; filename="' . $name . '"');
    header('Content-Length: ' . filesize($f));
    readfile($f);
    // Phone→PC files are one-shot: remove once the PC has pulled them.
    if ($dir === 'toPC') @unlink($f);
    exit;
}

http_response_code(400); echo 'bad action';
