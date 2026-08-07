<?php
// ApneScan — user feedback ingest. The app posts free-text feedback here;
// it shows up in the admin dashboard inbox. No personal data required.
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

$install = substr(preg_replace('/[^a-zA-Z0-9\-]/', '', (string)($d['install'] ?? '')), 0, 40);
$version = substr(preg_replace('/[^0-9\.]/', '', (string)($d['version'] ?? '')), 0, 20);
$contact = substr(preg_replace('/[^a-zA-Z0-9@._+\- ]/', '', (string)($d['contact'] ?? '')), 0, 120);
$msg     = trim((string)($d['message'] ?? ''));
$msg     = mb_substr($msg, 0, 2000);
if ($msg === '') { http_response_code(400); echo 'empty'; exit; }

try {
    $db = new PDO("mysql:host=$DB_HOST;dbname=$DB_NAME;charset=utf8mb4", $DB_USER, $DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $db->exec('CREATE TABLE IF NOT EXISTS feedback (
        id BIGINT AUTO_INCREMENT PRIMARY KEY,
        install VARCHAR(40), version VARCHAR(20), contact VARCHAR(120),
        message TEXT, ts INT, day CHAR(10), seen TINYINT DEFAULT 0,
        INDEX idx_ts (ts)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    $s = $db->prepare('INSERT INTO feedback (install,version,contact,message,ts,day) VALUES (?,?,?,?,?,?)');
    $s->execute([$install, $version, $contact, $msg, time(), gmdate('Y-m-d')]);
    http_response_code(204);
} catch (Throwable $e) {
    http_response_code(500); echo 'err';
}
