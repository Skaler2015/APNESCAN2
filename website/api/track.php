<?php
// ApneScan — anonymous usage-analytics ingest (MySQL).
// Stores which feature was used, app version and OS. NO document content,
// filenames or personal data. The IP is only kept as a short one-way hash
// for rough unique-visitor counting, plus a coarse country code.
//
// Special events:
//   "ping"  -> just refreshes a live-presence row (for the "online now" count),
//              never stored in the events history.

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Content-Type: text/plain; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST')    { http_response_code(405); echo 'POST only'; exit; }

$cfg = __DIR__ . '/config.php';
if (!file_exists($cfg)) { http_response_code(503); echo 'not configured'; exit; }
require $cfg; // $DB_HOST, $DB_NAME, $DB_USER, $DB_PASS

$raw  = file_get_contents('php://input');
$data = json_decode($raw, true);
if (!is_array($data)) { http_response_code(400); echo 'bad json'; exit; }

// Light shared key to discourage casual junk posts (not a real secret).
if (($data['key'] ?? '') !== 'apnescan-telemetry-v1') { http_response_code(401); echo 'no'; exit; }

$install = substr(preg_replace('/[^a-zA-Z0-9\-]/', '', (string)($data['install'] ?? '')), 0, 40);
$event   = substr(preg_replace('/[^a-zA-Z0-9_\-]/', '', (string)($data['event'] ?? '')), 0, 40);
$version = substr(preg_replace('/[^0-9\.]/',        '', (string)($data['version'] ?? '')), 0, 20);
$os      = substr(preg_replace('/[^a-zA-Z0-9_\. ]/','', (string)($data['os'] ?? '')), 0, 40);
$count   = (int)($data['count'] ?? 1);
if ($count < 1) $count = 1;
if ($count > 100000) $count = 100000;
if ($install === '' || $event === '') { http_response_code(400); echo 'missing'; exit; }

try {
    $db = new PDO("mysql:host=$DB_HOST;dbname=$DB_NAME;charset=utf8mb4", $DB_USER, $DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

    $now = time();

    // ---- Live presence heartbeat (for "online now"). Not part of history. ----
    if ($event === 'ping') {
        $db->exec('CREATE TABLE IF NOT EXISTS live (
            install VARCHAR(40) PRIMARY KEY, ts INT, version VARCHAR(20)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
        $s = $db->prepare('INSERT INTO live (install,ts,version) VALUES (?,?,?)
                           ON DUPLICATE KEY UPDATE ts=VALUES(ts), version=VALUES(version)');
        $s->execute([$install, $now, $version]);
        http_response_code(204); exit;
    }

    $db->exec('CREATE TABLE IF NOT EXISTS events (
        id BIGINT AUTO_INCREMENT PRIMARY KEY,
        install VARCHAR(40), event VARCHAR(40), version VARCHAR(20), os VARCHAR(40),
        cnt INT, iphash VARCHAR(16), ts INT, day CHAR(10),
        INDEX idx_ts (ts), INDEX idx_install (install), INDEX idx_event (event)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    $db->exec('CREATE TABLE IF NOT EXISTS geo (
        install VARCHAR(40) PRIMARY KEY, country CHAR(2), ts INT
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    // keep the live row fresh on any activity too
    $db->exec('CREATE TABLE IF NOT EXISTS live (
        install VARCHAR(40) PRIMARY KEY, ts INT, version VARCHAR(20)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');

    $ip     = $_SERVER['REMOTE_ADDR'] ?? '';
    $iphash = substr(hash('sha256', $ip . '|apnescan-salt'), 0, 16);
    $day    = gmdate('Y-m-d', $now);

    $stmt = $db->prepare('INSERT INTO events (install,event,version,os,cnt,iphash,ts,day)
                          VALUES (?,?,?,?,?,?,?,?)');
    $stmt->execute([$install, $event, $version, $os, $count, $iphash, $now, $day]);

    // refresh live presence on real activity
    $ls = $db->prepare('INSERT INTO live (install,ts,version) VALUES (?,?,?)
                        ON DUPLICATE KEY UPDATE ts=VALUES(ts), version=VALUES(version)');
    $ls->execute([$install, $now, $version]);

    // ---- Coarse country lookup, once per install (best-effort). ----
    $known = $db->prepare('SELECT 1 FROM geo WHERE install=?');
    $known->execute([$install]);
    if (!$known->fetchColumn() && $ip !== '' && filter_var($ip, FILTER_VALIDATE_IP)) {
        $cc = '';
        try {
            $ctx = stream_context_create(['http' => ['timeout' => 2]]);
            $r = @file_get_contents('http://ip-api.com/json/' . urlencode($ip) . '?fields=countryCode', false, $ctx);
            if ($r) { $j = json_decode($r, true); $cc = substr((string)($j['countryCode'] ?? ''), 0, 2); }
        } catch (Throwable $e) { $cc = ''; }
        // Store even on failure ('??') so we don't retry on every event.
        $gi = $db->prepare('INSERT IGNORE INTO geo (install,country,ts) VALUES (?,?,?)');
        $gi->execute([$install, $cc !== '' ? $cc : '??', $now]);
    }

    http_response_code(204);
} catch (Throwable $e) {
    http_response_code(500);
    echo 'err';
}
