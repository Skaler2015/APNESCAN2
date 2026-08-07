<?php
// ApneScan — scheduled backup endpoint. Call daily from a Hostinger cron:
//   wget -q -O /dev/null "https://apnescan.subhashkaler.com/api/backup-cron.php?token=YOUR_TOKEN"
// Writes a gzipped SQL dump under api/data/backups and keeps the newest 20.
declare(strict_types=1);
header('Content-Type: text/plain; charset=utf-8');

$cfg = __DIR__ . '/config.php';
if (!file_exists($cfg)) { http_response_code(503); echo 'not configured'; exit; }
require $cfg;

try {
    $db = new PDO("mysql:host=$DB_HOST;dbname=$DB_NAME;charset=utf8mb4", $DB_USER, $DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $db->exec('CREATE TABLE IF NOT EXISTS settings (k VARCHAR(40) PRIMARY KEY, v TEXT) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    $st = $db->query('SELECT k,v FROM settings')->fetchAll(PDO::FETCH_KEY_PAIR);
    $expected = (string)($st['cron_token'] ?? '');
    $token = $_GET['token'] ?? ($argv[1] ?? '');
    if ($expected === '' || !hash_equals($expected, (string)$token)) { http_response_code(403); echo 'bad token'; exit; }

    $dir = __DIR__ . '/data/backups';
    if (!is_dir($dir)) { @mkdir($dir, 0755, true); @file_put_contents(__DIR__ . '/data/.htaccess', "Require all denied\nDeny from all\n"); }

    $tables = ['events', 'settings', 'feedback', 'geo', 'devices', 'admin_users', 'audit_log'];
    $sql = "-- ApneScan backup " . gmdate('c') . "\nSET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\n\n";
    foreach ($tables as $t) {
        try {
            $create = $db->query('SHOW CREATE TABLE `' . $t . '`')->fetch(PDO::FETCH_NUM);
            $sql .= "DROP TABLE IF EXISTS `$t`;\n" . ($create[1] ?? '') . ";\n";
            foreach ($db->query('SELECT * FROM `' . $t . '`') as $row) {
                $cols = array_map(fn($c) => '`' . $c . '`', array_keys($row));
                $vals = array_map(fn($v) => $v === null ? 'NULL' : $db->quote((string)$v), array_values($row));
                $sql .= 'INSERT INTO `' . $t . '` (' . implode(',', $cols) . ') VALUES (' . implode(',', $vals) . ");\n";
            }
            $sql .= "\n";
        } catch (Throwable $e) { $sql .= "-- skip $t\n"; }
    }
    $sql .= "SET FOREIGN_KEY_CHECKS=1;\n";

    $name = 'apnescan-' . gmdate('Ymd-His') . '.sql.gz';
    @file_put_contents($dir . '/' . $name, function_exists('gzencode') ? gzencode($sql, 6) : $sql);

    // prune to newest 20
    $files = glob($dir . '/apnescan-*.sql*') ?: [];
    usort($files, fn($a, $b) => filemtime($b) <=> filemtime($a));
    foreach (array_slice($files, 20) as $f) @unlink($f);

    echo 'backup ok: ' . $name;
} catch (Throwable $e) {
    http_response_code(500); echo 'err';
}
