<?php
// ApneScan — device-profile ingest. The app reports coarse, non-identifying
// hardware/environment facts (CPU cores, RAM, screen, language, timezone,
// scanner driver). No document content or personal data. One row per install.
header('Content-Type: text/plain; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST')    { http_response_code(405); echo 'POST only'; exit; }

$cfg = __DIR__ . '/config.php';
if (!file_exists($cfg)) { http_response_code(503); echo 'not configured'; exit; }
require $cfg;

$d = json_decode(file_get_contents('php://input'), true);
if (!is_array($d) || ($d['key'] ?? '') !== 'apnescan-telemetry-v1') { http_response_code(401); echo 'no'; exit; }

$clean = fn($s, $re, $len) => substr(preg_replace($re, '', (string)$s), 0, $len);
$install = $clean($d['install'] ?? '', '/[^a-zA-Z0-9\-]/', 40);
if ($install === '') { http_response_code(400); echo 'missing'; exit; }

$os       = $clean($d['os'] ?? '',       '/[^a-zA-Z0-9_. ]/', 40);
$arch     = $clean($d['arch'] ?? '',     '/[^a-zA-Z0-9_]/', 16);
$cpu      = (int)($d['cpu_cores'] ?? 0);
$ram      = (int)($d['ram_mb'] ?? 0);
$screen   = $clean($d['screen'] ?? '',   '/[^0-9x ]/', 24);
$monitors = (int)($d['monitors'] ?? 0);
$lang     = $clean($d['lang'] ?? '',     '/[^a-zA-Z\-]/', 16);
$tz       = $clean($d['tz'] ?? '',       '/[^a-zA-Z0-9_\/+\-]/', 40);
$scanner  = $clean($d['scanner'] ?? '',  '/[^a-zA-Z0-9_.\- ]/', 80);

try {
    $db = new PDO("mysql:host=$DB_HOST;dbname=$DB_NAME;charset=utf8mb4", $DB_USER, $DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $db->exec('CREATE TABLE IF NOT EXISTS devices (install VARCHAR(40) PRIMARY KEY,os VARCHAR(40),arch VARCHAR(16),cpu_cores INT,ram_mb INT,screen VARCHAR(24),monitors INT,lang VARCHAR(16),tz VARCHAR(40),scanner VARCHAR(80),ts INT) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    $s = $db->prepare('INSERT INTO devices (install,os,arch,cpu_cores,ram_mb,screen,monitors,lang,tz,scanner,ts)
        VALUES (?,?,?,?,?,?,?,?,?,?,?)
        ON DUPLICATE KEY UPDATE os=VALUES(os),arch=VALUES(arch),cpu_cores=VALUES(cpu_cores),ram_mb=VALUES(ram_mb),
        screen=VALUES(screen),monitors=VALUES(monitors),lang=VALUES(lang),tz=VALUES(tz),
        scanner=IF(VALUES(scanner)<>"",VALUES(scanner),scanner),ts=VALUES(ts)');
    $s->execute([$install, $os, $arch, $cpu, $ram, $screen, $monitors, $lang, $tz, $scanner, time()]);
    http_response_code(204);
} catch (Throwable $e) {
    http_response_code(500); echo 'err';
}
