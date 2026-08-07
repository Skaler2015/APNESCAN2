<?php
// ApneScan — app remote-config endpoint.
// The app polls this on startup to get: a broadcast banner message, the
// minimum-required version (for force-update), a download URL and feature
// flags. All values are managed from the admin dashboard.
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Cache-Control: no-store');

$cfg = __DIR__ . '/config.php';
if (!file_exists($cfg)) { echo json_encode(['ok' => false]); exit; }
require $cfg;

try {
    $db = new PDO("mysql:host=$DB_HOST;dbname=$DB_NAME;charset=utf8mb4", $DB_USER, $DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $db->exec('CREATE TABLE IF NOT EXISTS settings (k VARCHAR(40) PRIMARY KEY, v TEXT)
               ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    $rows = $db->query('SELECT k,v FROM settings')->fetchAll(PDO::FETCH_KEY_PAIR);

    $flags = json_decode($rows['flags'] ?? '{}', true);
    echo json_encode([
        'ok'          => true,
        'messageId'   => (int)($rows['message_id'] ?? 0),
        'message'     => (string)($rows['message'] ?? ''),
        'messageType' => (string)($rows['message_type'] ?? 'info'),
        'minVersion'  => (string)($rows['min_version'] ?? ''),
        'forceUpdate' => (($rows['force_update'] ?? '0') === '1'),
        'downloadUrl' => (string)($rows['download_url']
            ?? 'https://github.com/Skaler2015/APNESCAN2/releases/download/apnescan-latest/ApneScan-Setup.exe'),
        'flags'       => is_array($flags) ? $flags : new stdClass(),
    ]);
} catch (Throwable $e) {
    echo json_encode(['ok' => false]);
}
