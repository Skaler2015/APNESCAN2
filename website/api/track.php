<?php
// ApneScan — anonymous usage-analytics ingest (MySQL).
// Stores which feature was used, app version and OS. NO document content,
// filenames or personal data. The IP is only kept as a short one-way hash
// for rough unique-visitor counting.

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
if ($count > 1000) $count = 1000;
if ($install === '' || $event === '') { http_response_code(400); echo 'missing'; exit; }

try {
    $db = new PDO("mysql:host=$DB_HOST;dbname=$DB_NAME;charset=utf8mb4", $DB_USER, $DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $db->exec('CREATE TABLE IF NOT EXISTS events (
        id BIGINT AUTO_INCREMENT PRIMARY KEY,
        install VARCHAR(40), event VARCHAR(40), version VARCHAR(20), os VARCHAR(40),
        cnt INT, iphash VARCHAR(16), ts INT, day CHAR(10),
        INDEX idx_ts (ts), INDEX idx_install (install)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');

    $ip     = $_SERVER['REMOTE_ADDR'] ?? '';
    $iphash = substr(hash('sha256', $ip . '|apnescan-salt'), 0, 16);
    $now    = time();
    $day    = gmdate('Y-m-d', $now);

    $stmt = $db->prepare('INSERT INTO events (install,event,version,os,cnt,iphash,ts,day)
                          VALUES (?,?,?,?,?,?,?,?)');
    $stmt->execute([$install, $event, $version, $os, $count, $iphash, $now, $day]);
    http_response_code(204);
} catch (Throwable $e) {
    http_response_code(500);
    echo 'err';
}
